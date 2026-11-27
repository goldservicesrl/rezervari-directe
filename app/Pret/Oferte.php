<?php

declare(strict_types=1);

namespace Cazo\Pret;

defined('CAZO') or exit;

/**
 * Tarifele arătate oaspetelui pentru datele alese: doar cel mai bun tarif aplicabil pe fiecare
 * combinație de politică de anulare și masă inclusă, nu lista tuturor planurilor din EasyCheck.
 *
 * Cum se deduce „cel mai bun” din datele API-ului (GET /preturi, ținut în oglindă):
 *  1. candidații sunt planurile vizibile pe site pe care le au toate camerele rezervării (un singur plan pe
 *     rezervare);
 *  2. un plan se aplică datelor când trece restricțiile din grilă, aceleași ca în API (PretNoapte::restrictie):
 *     sejurul minim și maxim din ziua sosirii, sejurul minim prin fiecare noapte, sosirea și plecarea închise,
 *     zilele închise, nopțile fără preț. Un tarif de sejur lung („de la N nopți”) are `sejur_minim` = N în grilă,
 *     deci iese singur la un sejur mai scurt;
 *  3. planurile aplicabile se grupează după politica de anulare și după masa inclusă (23.11.2026):
 *     `tip` = `nerambursabil` e o politică, celelalte se grupează după `anulare_zile` (lipsă: „la
 *     cerere”); masa e codul din EasyCheck (`mic_dejun`, `demipensiune`, `pensiune_completa`, `all_inclusive`),
 *     iar „fără masă”, lipsa câmpului și un cod necunoscut sunt același grup, „doar cazare”;
 *  4. în fiecare grup câștigă planul cu suma cea mai mică a nopților (prețul fiecărei nopți din grilă, cu regula
 *     pe persoană a planului și, la „Prețul de pe site-ul meu”, cu reducerile locale pe noapte); taxele sunt
 *     aceleași pentru aceleași date, camere și oaspeți, deci ordinea sumelor nopților e ordinea totalurilor. La
 *     egalitate câștigă Standard, apoi ordinea din EasyCheck;
 *  5. varianta nerambursabilă rămâne doar când e mai ieftină decât cea mai ieftină variantă cu anulare cu aceeași
 *     masă;
 *  6. tariful nerambursabil fără nicio metodă plătită la rezervare oferită acum (ModPlata::ascuns) iese
 *     dintre candidați: se oferă doar cu cardul sau prin transfer bancar, niciodată cu plata la sosire.
 *
 * Ordinea: după masă (doar cazare, mic dejun, demipensiune, pensiune completă, all inclusive), apoi, la
 * aceeași masă, după politica de anulare, ca până acum (de la termenul cel mai apropiat de sosire, apoi „la cerere”,
 * la sfârșit nerambursabilul). Primul tarif rămâne astfel cel fără masă cu anulare, ca înainte de mese.
 *
 * Totalul exact se cere apoi doar pentru planurile câștigătoare (câte un GET /total pe tarif arătat, nu pe plan),
 * iar la EasyCheck pleacă identificatorul real al planului.
 */
final class Oferte
{
    public const STANDARD = 'plan_std';

    /**
     * @param \Closure(): array<string,?int> $oaspetiMax capacitățile camerelor, ca la Total
     * @param (\Closure(array, string): bool)|null $ascuns planul lipsește de pe site pentru ziua sosirii
     */
    public function __construct(private PretNoapte $pret, private \Closure $oaspetiMax, private ?\Closure $ascuns = null)
    {
    }

    /** Planul lipsește de pe site pentru ziua sosirii: tariful nerambursabil fără nicio metodă plătită la rezervare. */
    public function ascuns(?string $plan, string $sosire): bool
    {
        $p = $plan !== null ? ($this->pret->planuri()[$plan] ?? null) : null;
        return $p !== null && $this->ascuns !== null && ($this->ascuns)($p, $sosire);
    }

    /** Ordinea meselor în lista tarifelor; null = doar cazare. */
    public const ORDINE_MESE = [null, 'mic_dejun', 'demipensiune', 'pensiune_completa', 'all_inclusive'];

    /** Masa inclusă a planului, pentru grupare: codul cunoscut sau null (doar cazare). */
    public static function masa(array $plan): ?string
    {
        $m = $plan['masa'] ?? null;
        return in_array($m, \Cazo\EasyCheck\Proiectii::MESE, true) ? $m : null;
    }

    /** Grupul politicii de anulare a unui plan. */
    public static function grup(array $plan): string
    {
        if (($plan['tip'] ?? null) === 'nerambursabil') {
            return 'nerambursabil';
        }
        $z = $plan['anulare_zile'] ?? null;
        return $z === null || $z === '' ? 'cerere' : 'zile:' . (int) $z;
    }

    /**
     * Planurile câștigătoare pentru sejur, câte unul pe politică de anulare și masă: după masă, apoi întâi
     * cele cu anulare (de la termenul cel mai apropiat de sosire), la sfârșit varianta nerambursabilă.
     * @return list<array{plan:string, grup:string, masa:?string, nopti:int}>
     */
    public function alese(Sejur $s): array
    {
        $comune = null;
        foreach ($s->camere as $x) {
            $p = $this->pret->planuriCamera($x['camera']);
            $comune = $comune === null ? $p : array_values(array_intersect($comune, $p));
        }
        $toate = $this->pret->planuri();
        $max = ($this->oaspetiMax)();
        $grupuri = [];
        foreach ((array) $comune as $ordine => $id) {
            if ($this->ascuns((string) $id, $s->sosire)) {
                continue;
            }
            $suma = 0;
            foreach ($s->camere as $i => $c) {
                if ($this->pret->restrictie($c['camera'], $id, $s->sosire, $s->plecare, $s->oaspeti($i), $max[$c['camera']] ?? null) !== null) {
                    continue 2;
                }
                foreach ($this->pret->nopti($c['camera'], $id, $s->sosire, $s->plecare, $s->oaspeti($i))['preturi'] as $zi => $bani) {
                    $suma += $this->pret->reduceri !== null ? $this->pret->reduceri->pentruNoapte($c['camera'], $id, (string) $zi, $bani) : $bani;
                }
            }
            $g = self::grup($toate[$id]);
            $masa = self::masa($toate[$id]);
            $k = $g . '|' . ($masa ?? '');
            $cheie = [$suma, $id === self::STANDARD ? 0 : 1, $ordine];
            if (!isset($grupuri[$k]) || $cheie < $grupuri[$k]['cheie']) {
                $grupuri[$k] = ['plan' => (string) $id, 'grup' => $g, 'masa' => $masa, 'nopti' => $suma, 'cheie' => $cheie];
            }
        }
        foreach ($grupuri as $k => $x) {
            if ($x['grup'] !== 'nerambursabil') {
                continue;
            }
            $cuAnulare = array_filter($grupuri, static fn (array $y): bool => $y['grup'] !== 'nerambursabil' && $y['masa'] === $x['masa']);
            if ($cuAnulare !== [] && $x['nopti'] >= min(array_column($cuAnulare, 'nopti'))) {
                unset($grupuri[$k]);
            }
        }
        $rang = static fn (string $g): array => match (true) {
            $g === 'nerambursabil' => [2, 0],
            $g === 'cerere' => [1, 0],
            default => [0, (int) substr($g, 5)],
        };
        $rangMasa = static fn (?string $m): int => (int) array_search($m, self::ORDINE_MESE, true);
        uasort($grupuri, static fn (array $a, array $b): int => [$rangMasa($a['masa']), ...$rang($a['grup'])] <=> [$rangMasa($b['masa']), ...$rang($b['grup'])]);
        return array_values(array_map(static fn (array $x): array => ['plan' => $x['plan'], 'grup' => $x['grup'], 'masa' => $x['masa'], 'nopti' => $x['nopti']], $grupuri));
    }

    /**
     * Planul potrivit unui sejur: cel cerut, când e printre cele câștigătoare; altfel câștigătorul cu aceeași politică
     * de anulare și aceeași masă (același regim, preț mai bun), apoi cu aceeași masă (oaspetele nu pierde masa
     * aleasă), apoi cu aceeași politică, altfel primul câștigător. Null când niciun plan nu se aplică datelor.
     */
    public function potrivit(Sejur $s, ?string $cerut): ?string
    {
        $alese = $this->alese($s);
        if ($alese === []) {
            return null;
        }
        $toate = $this->pret->planuri();
        foreach ($alese as $a) {
            if ($a['plan'] === $cerut) {
                return $cerut;
            }
        }
        if ($cerut !== null && isset($toate[$cerut])) {
            $grup = self::grup($toate[$cerut]);
            $masa = self::masa($toate[$cerut]);
            foreach ([static fn (array $a): bool => $a['grup'] === $grup && $a['masa'] === $masa, static fn (array $a): bool => $a['masa'] === $masa,
                static fn (array $a): bool => $a['grup'] === $grup] as $la) {
                foreach ($alese as $a) {
                    if ($la($a)) {
                        return $a['plan'];
                    }
                }
            }
        }
        return $alese[0]['plan'];
    }
}
