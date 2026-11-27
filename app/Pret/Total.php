<?php

declare(strict_types=1);

namespace Cazo\Pret;

use Cazo\Baza\Baza;
use Cazo\EasyCheck\Buget;
use Cazo\EasyCheck\Licenta;
use Cazo\Nucleu\Ceas;
use Cazo\Securitate\Limitator;

defined('CAZO') or exit;

/**
 * Fațada totalului: sursa vine din cheia EasyCheck (`pret.sursa` din GET /eu).
 *
 *   easycheck  GET /total (TotalEasyCheck), după verificarea locală a restricțiilor (aceleași motive ca API-ul,
 *              fără cereri inutile); cererile vizitatorilor trec prin plafonul bugetului (40 pe minut pe site,
 *              6 pe IP/24): peste el, rezultatul e „peste_buget” și cardul arată „Vedeți totalul”;
 *   site       MotorLocal, fără cereri spre EasyCheck.
 *
 * Rezultatele se țin 10 minute în `totaluri`, cu cheia pe sursă, sejur, versiunea prețurilor, alegerea gazdei „include” și,
 * la `site`, versiunea reducerilor locale cu ziua de azi;
 * oglinda golește tabelul la prețuri noi. Un total servit din `totaluri` nu consumă bugetul.
 */
final class Total
{
    public const DURATA = 600;
    /** Versiunea calculului, parte din cheia totalurilor ținute minte: 2 = sumele rotunde la leu. */
    public const CALCUL = 2;
    public const PE_IP = 6;

    /** @param \Closure(): TotalEasyCheck $totalEc */
    public function __construct(
        private Baza $baza,
        private Ceas $ceas,
        private PretNoapte $pret,
        private MotorLocal $motor,
        private \Closure $totalEc,
        private Licenta $licenta,
        private ?Buget $buget,
        private \Closure $oaspetiMax,
        private ?Limitator $limitator = null,
        private ?\Closure $cheieReduceri = null,
        private ?\Closure $noptiMax = null,
        private ?\Closure $include = null,
    ) {
    }

    /**
     * Durata maximă a sejurului pe site: un sejur mai lung primește o restricție proprie, cu contactul gazdei
     * în mesaj, înainte de orice cerere de preț. @return array{stare:string, restrictie:array, index:int}|null
     */
    private function pesteDurata(Sejur $s): ?array
    {
        $max = $this->noptiMax !== null ? (int) ($this->noptiMax)() : null;
        if ($max === null || Sejur::nopti($s->sosire, $s->plecare) <= $max) {
            return null;
        }
        return ['stare' => 'restrictie', 'restrictie' => ['motiv' => 'sejur_maxim_site', 'valoare' => $max], 'index' => 0];
    }

    /**
     * Partea reducerilor locale din cheia totalurilor ținute minte: la `site`, versiunea reducerilor și ziua de azi
     * (condițiile pe zilele dinaintea sosirii se schimbă la miezul nopții); la `easycheck`, nimic.
     */
    private function reduceriCheie(?string $sursa): string
    {
        return $sursa === 'site' && $this->cheieReduceri !== null ? (string) ($this->cheieReduceri)() : '0';
    }

    /**
     * Partea alegerii „Ce plătește oaspetele pe site” din cheia totalurilor ținute minte: la `easycheck`, bifele
     * gazdei din panou, trimise cu GET /total; la `site`, nimic (motorul local calculează tot).
     */
    private function includeCheie(?string $sursa): ?array
    {
        return $sursa === 'easycheck' && $this->include !== null ? ($this->include)() : null;
    }

    /** Sursa prețului cheii salvate: „easycheck”, „site” sau null (fără cheie). */
    public function sursa(): ?string
    {
        $s = $this->licenta->cheie()['sursa'] ?? null;
        return in_array($s, ['easycheck', 'site'], true) ? $s : null;
    }

    public function unul(Sejur $s, string $ip24, bool $vizitator = true): array
    {
        return $this->calculeaza([$s], $ip24, $vizitator)[0];
    }

    /**
     * @param array<int|string, Sejur> $sejururi
     * @return array<int|string, array> {stare: ok|restrictie|peste_buget|indisponibil, sursa, ...}
     */
    public function calculeaza(array $sejururi, string $ip24, bool $vizitator = true): array
    {
        $sursa = $this->sursa();
        $rez = [];
        if ($sursa === null || !$this->licenta->stare()['valida']) {
            // Cheia lipsă sau respinsă: prezentarea rămâne, fără prețuri.
            foreach (array_keys($sejururi) as $k) {
                $rez[$k] = ['stare' => 'indisponibil', 'motiv' => 'licenta', 'sursa' => $sursa];
            }
            return $rez;
        }
        $include = $this->includeCheie($sursa);
        $versiune = (int) ($this->baza->valoare("SELECT versiune FROM {ec_resurse} WHERE resursa = 'preturi'") ?? 0);
        $max = ($this->oaspetiMax)();
        $deCerut = [];
        $permisSite = null;
        foreach ($sejururi as $k => $s) {
            $lung = $this->pesteDurata($s);
            if ($lung !== null) {
                $rez[$k] = $lung + ['sursa' => $sursa];
                continue;
            }
            $cheie = hash('sha256', json_encode([$sursa, $s->canonic(), $versiune, $include, $this->reduceriCheie($sursa), self::CALCUL], JSON_THROW_ON_ERROR));
            $tinut = $this->citesteTinut($cheie);
            if ($tinut !== null) {
                $rez[$k] = $tinut + ['sursa' => $sursa];
                continue;
            }
            if ($sursa === 'site') {
                // /_api/pret: 6 pe minut pe IP/24; totalurile servite din `totaluri` rămân libere.
                // O căutare cu mai multe carduri numără o singură cerere.
                $permisSite ??= !$vizitator || $this->limitator === null || $this->limitator->incearca('api_pret:ip:' . $ip24, self::PE_IP, 60);
                if (!$permisSite) {
                    $rez[$k] = ['stare' => 'peste_buget', 'sursa' => $sursa];
                    continue;
                }
                $r = $this->motor->total($s, $max);
            } else {
                $r = $this->restrictieLocala($s, $max);
                if ($r === null) {
                    if ($vizitator && ($this->buget === null || !$this->buget->permiteTotalVizitator($ip24))) {
                        $rez[$k] = ['stare' => 'peste_buget', 'sursa' => $sursa];
                        continue;
                    }
                    $deCerut[$k] = [$s, $cheie];
                    continue;
                }
            }
            $this->tine($cheie, $r);
            $rez[$k] = $r + ['sursa' => $sursa];
        }
        if ($deCerut !== []) {
            $raspunsuri = ($this->totalEc)()->cere(array_map(static fn (array $x): Sejur => $x[0], $deCerut));
            foreach ($deCerut as $k => [, $cheie]) {
                $r = $raspunsuri[$k];
                if ($r['stare'] === 'ok' || $r['stare'] === 'restrictie') {
                    $this->tine($cheie, $r);
                }
                $rez[$k] = $r + ['sursa' => $sursa];
            }
        }
        $ordonat = [];
        foreach (array_keys($sejururi) as $k) {
            $ordonat[$k] = $rez[$k];
        }
        return $ordonat;
    }

    /**
     * Totalul live la trimiterea comenzii: la `easycheck`, GET /total direct, ocolind `totaluri`
     * și plafonul vizitatorilor (scrierea are limitele ei); la `site`, MotorLocal (oglinda prețurilor o
     * reîmprospătează apelantul, cu o citire condiționată). Rezultatul nou se ține și în `totaluri`.
     */
    public function live(Sejur $s): array
    {
        $sursa = $this->sursa();
        if ($sursa === null || !$this->licenta->stare()['valida']) {
            return ['stare' => 'indisponibil', 'motiv' => 'licenta', 'sursa' => $sursa];
        }
        $lung = $this->pesteDurata($s);
        if ($lung !== null) {
            return $lung + ['sursa' => $sursa];
        }
        $max = ($this->oaspetiMax)();
        if ($sursa === 'site') {
            $r = $this->motor->total($s, $max);
        } else {
            $r = $this->restrictieLocala($s, $max) ?? ($this->totalEc)()->cere([$s])[0];
        }
        if ($r['stare'] === 'ok' || $r['stare'] === 'restrictie') {
            $include = $this->includeCheie($sursa);
            $versiune = (int) ($this->baza->valoare("SELECT versiune FROM {ec_resurse} WHERE resursa = 'preturi'") ?? 0);
            $this->tine(hash('sha256', json_encode([$sursa, $s->canonic(), $versiune, $include, $this->reduceriCheie($sursa), self::CALCUL], JSON_THROW_ON_ERROR)), $r);
        }
        return $r + ['sursa' => $sursa];
    }

    /** Restricțiile verificate local la „Prețul din EasyCheck”, cu planul obligatoriu pe fiecare cameră. */
    private function restrictieLocala(Sejur $s, array $max): ?array
    {
        foreach ($s->camere as $i => $c) {
            $plan = $s->planCamera($i);
            if ($plan === null) {
                return ['stare' => 'indisponibil', 'motiv' => 'plan_lipsa'];
            }
            $r = $this->pret->restrictie($c['camera'], $plan, $s->sosire, $s->plecare, $s->oaspeti($i), $max[$c['camera']] ?? null);
            if ($r !== null) {
                return ['stare' => 'restrictie', 'restrictie' => $r, 'index' => $i];
            }
        }
        return null;
    }

    private function citesteTinut(string $cheie): ?array
    {
        $r = $this->baza->rand('SELECT corp, la FROM {totaluri} WHERE cheie = ?', [$cheie]);
        if ($r === null || Ceas::dinMoment((string) $r['la']) + self::DURATA <= $this->ceas->acum()) {
            return null;
        }
        $d = json_decode((string) $r['corp'], true);
        return is_array($d) ? $d : null;
    }

    private function tine(string $cheie, array $r): void
    {
        $this->baza->upsert('totaluri', ['cheie' => $cheie, 'corp' => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'la' => $this->ceas->moment()],
            ['cheie'], ['corp', 'la']);
    }

    /** Curățenia: totalurile mai vechi de 10 minute. */
    public function curata(): int
    {
        return $this->baza->executa('DELETE FROM {totaluri} WHERE la < ?', [$this->ceas->moment(-self::DURATA)])->rowCount();
    }
}
