<?php

declare(strict_types=1);

namespace Cazo\Pret;

use Cazo\Baza\Baza;

defined('CAZO') or exit;

/**
 * Motorul local de preț, la „Prețul de pe site-ul meu”: totalul calculat de Cazo e cel
 * trimis și păstrat de EasyCheck. Aceeași formulă ca GET /total din EasyCheck, din
 * datele GET /preturi:
 *
 *  1. nopțile: pe fiecare cameră din listă, prețul nopții pe planul camerei, cu regula pe persoană (PretNoapte);
 *  2. reducerile locale (ReduceriLocale): pe nopțile fiecărei camere, după alegerea gazdei pentru suprapuneri
 *    ; prețul nopții după reducere intră în componenta `nopti` și în baza taxei locale procentuale;
 *  3. taxele suplimentare, pe cameră: curățenia (sau curățenia la sejur scurt, în locul ei, până la pragul ei de
 *     nopți), lenjeria, oaspetele în plus peste cei incluși, pe sejur sau pe noapte, doar pe camerele rândului;
 *     animalele niciodată (ca EasyCheck);
 *  4. taxa locală, o dată pe rezervare, pe nopțile tuturor camerelor adunate și pe toți oaspeții, după regula
 *     declarației (taxeLocale): anul și versiunea după momentul ales de gazdă în EasyCheck (`taxa_locala.moment`,
 *     ori momentul fiecărei taxe), fără moment după sosire: procentul din valoarea nopților (fără TVA la baza
 *     „fără”, când gazda e plătitoare), suma fixă pe persoană și noapte sau pe persoană; pragul sejurului lung.
 *
 * Rotunjirea: o dată pe componentă, la ban (Bani); apoi totalul rotund la leu (ca GET /total din EasyCheck
 * 7.550.118): suma componentelor se rotunjește la cel mai apropiat leu, jumătatea în sus, și se împarte înapoi
 * (`rotunjeste`): taxele suplimentare rămân neatinse, taxa locală procentuală se recalculează din cazarea rămasă, cea
 * în sumă fixă rămâne, iar diferența intră în `nopti`; părțile camerelor se împart proporțional, cu suma exactă
 * (`imparte`). Componentele au forma din
 * GET /total (nopti, taxe, taxa_locala), cu părțile camerelor informativ; `reduceri` (informativ, pentru panou și
 * pentru comandă) are suma reducerilor și partea fiecărei reduceri pe cameră, sau null fără reduceri. Fără etichete
 * de reducere pe site: oaspetele vede prețul deja redus.
 */
final class MotorLocal
{
    /** @param ?\Closure(): string $azi ziua de azi în ora României (zilele dinaintea sosirii) */
    public function __construct(private Baza $baza, private PretNoapte $pret, private ?ReduceriLocale $reduceri = null, private ?\Closure $azi = null)
    {
    }

    /** Taxele din oglindă (taxe_ec), sau null înainte de prima citire a prețurilor. */
    public function taxe(): ?array
    {
        $c = $this->baza->valoare("SELECT corp FROM {taxe_ec} WHERE nume = 'taxe'");
        $d = is_string($c) ? json_decode($c, true) : null;
        return is_array($d) ? $d : null;
    }

    /**
     * @param array<string,?int> $oaspetiMax capacitatea fiecărei camere (din GET /camere), după id
     * @return array{stare:string, total?:int, moneda?:string, componente?:array, camere?:list<array>, restrictie?:array, index?:int, plan?:list<?string>}
     *   stare: „ok”, „restrictie” (cu motivul și indexul camerei), „indisponibil” (oglinda incompletă sau altă monedă)
     * @param bool $rotund totalul rotund la leu; false pentru componentele exacte, la ban (sumele din afara totalului)
     */
    public function total(Sejur $s, array $oaspetiMax = [], bool $rotund = true): array
    {
        $taxe = $this->taxe();
        if ($taxe === null || ($taxe['moneda'] ?? 'RON') !== 'RON') {
            // Grila în altă monedă cere cursul BNR al platformei; Cazo vinde atunci doar prin „Prețul din EasyCheck”.
            return ['stare' => 'indisponibil'];
        }
        $n = $s->numarNopti();
        $exacte = [];
        $planuri = [];
        $reduceri = [];
        foreach ($s->camere as $i => $c) {
            $plan = $s->planCamera($i) ?? $this->pret->planImplicit($c['camera']);
            $planuri[] = $plan;
            $o = $s->oaspeti($i);
            if ($plan === null) {
                return ['stare' => 'restrictie', 'restrictie' => ['motiv' => 'fara_pret', 'zile' => Sejur::zile($s->sosire, $s->plecare)], 'index' => $i];
            }
            $r = $this->pret->restrictie($c['camera'], $plan, $s->sosire, $s->plecare, $o, $oaspetiMax[$c['camera']] ?? null);
            if ($r !== null) {
                return ['stare' => 'restrictie', 'restrictie' => $r, 'index' => $i];
            }
            $nopti = $this->pret->nopti($c['camera'], $plan, $s->sosire, $s->plecare, $o)['preturi'];
            if ($this->reduceri !== null) {
                $red = $this->reduceri->aplica($c['camera'], $plan, $nopti, $s->sosire, $this->azi !== null ? ($this->azi)() : gmdate('Y-m-d'));
                $nopti = $red['preturi'];
                foreach ($red['reduceri'] as $x) {
                    $reduceri[] = ['camera' => $c['camera'], 'index' => $i, 'reducere' => $x['id'], 'suma' => $x['suma']];
                }
            }
            $exacte[] = [
                'camera' => $c['camera'],
                'nopti' => (float) array_sum($nopti),
                'taxe' => (float) self::taxeCamera((array) ($taxe['taxe_suplimentare'] ?? []), $c['camera'], $n, $o),
                'lei_pe_noapte' => array_map(static fn (int|float $x): float => $x / 100, array_values($nopti)),
                'oaspeti' => $o,
            ];
        }
        $locale = self::taxeLocale((array) ($taxe['taxa_locala'] ?? []), $s->sosire, $s->plecare);
        $cota = (int) ($taxe['taxa_locala']['cota_tva_cazare_sutimi'] ?? $taxe['cota_tva_cazare_sutimi'] ?? 0) / 100;
        foreach ($exacte as &$e) {
            $lei = 0.0;
            foreach ($locale as $par) {
                $lei += self::taxaPeSejur($par, $e['lei_pe_noapte'], $e['oaspeti'], $cota);
            }
            $e['taxa_locala'] = $lei * 100;
        }
        unset($e);
        // Taxa locală a rezervării: nopțile camerelor adunate pe aceeași noapte și toți oaspeții, o singură dată.
        $peNoapte = [];
        $oaspeti = 0;
        foreach ($exacte as $e) {
            foreach ($e['lei_pe_noapte'] as $k => $x) {
                $peNoapte[$k] = ($peNoapte[$k] ?? 0.0) + $x;
            }
            $oaspeti += $e['oaspeti'];
        }
        $leiRez = 0.0;
        foreach ($locale as $par) {
            $leiRez += self::taxaPeSejur($par, $peNoapte, $oaspeti, $cota);
        }
        $suma = ['nopti' => 0.0, 'taxe' => 0.0];
        $parti = [];
        foreach ($exacte as $e) {
            $suma['nopti'] += $e['nopti'];
            $suma['taxe'] += $e['taxe'];
            $parti[] = ['camera' => $e['camera'], 'nopti' => Bani::intregi($e['nopti']), 'taxe' => Bani::intregi($e['taxe']), 'taxa_locala' => Bani::intregi($e['taxa_locala'])];
        }
        $comp = ['nopti' => Bani::intregi($suma['nopti']), 'taxe' => Bani::intregi($suma['taxe']), 'taxa_locala' => Bani::intregi($leiRez * 100)];
        if ($rotund) {
            // totalul rotund la leu, împărțit înapoi în componente și pe camere, ca în GET /total.
            $r = self::rotunjeste($comp['nopti'], $comp['taxe'], $comp['taxa_locala'], array_values($peNoapte), $oaspeti, $locale, $cota);
            $comp['nopti'] = $r['nopti'];
            $comp['taxa_locala'] = $r['taxa_locala'];
            foreach (['nopti', 'taxe', 'taxa_locala'] as $k) {
                foreach (self::imparte($comp[$k], array_column($exacte, $k)) as $i => $parte) {
                    $parti[$i][$k] = $parte;
                }
            }
        }
        return [
            'stare' => 'ok',
            'total' => $comp['nopti'] + $comp['taxe'] + $comp['taxa_locala'],
            'moneda' => 'RON',
            'componente' => $comp,
            'camere' => $parti,
            'plan' => $planuri,
            // cum încasează gazda taxa locală la rezervările de pe site (GET /preturi, `taxa_locala.incasare`).
            'taxa_incasare' => RegulaPlata::incasare($taxe['taxa_locala']['incasare'] ?? null),
            'reduceri' => $reduceri === [] ? null : [
                'total' => Bani::intregi(array_sum(array_column($reduceri, 'suma'))),
                'detalii' => array_map(static fn (array $x): array => ['suma' => Bani::intregi($x['suma'])] + $x, $reduceri),
            ],
        ];
    }

    /**
     * Totalul rotund la leu (ca GET /total din EasyCheck). Componentele sunt cele rotunjite la ban. Suma lor
     * se rotunjește la cel mai apropiat leu, jumătatea în sus, apoi: taxele suplimentare rămân neatinse; fără reguli de
     * taxă locală, taxa rămâne, iar diferența intră în nopți; cu ele, cazarea ajustată N e cea mai mare cu
     * N + taxa(N) ≤ totalul rotund − taxe (căutare binară), taxa e taxa(N), iar nopțile sunt restul. Un total care ar
     * deveni zero sau nopți sub zero: nimic nu se rotunjește.
     * @param list<float> $leiPeNoapte nopțile sejurului în lei, toate camerele adunate pe noapte
     * @return array{nopti:int, taxa_locala:int, total:int}
     */
    public static function rotunjeste(int $nopti, int $taxe, int $taxaLocala, array $leiPeNoapte = [], int $oaspeti = 0, array $taxeLocale = [], float $cotaTva = 0.0): array
    {
        $brut = $nopti + $taxe + $taxaLocala;
        $rotund = RegulaPlata::rotund($brut);
        $tinta = $rotund - $taxe;
        $t = $taxaLocala;
        if ($taxeLocale !== [] && $leiPeNoapte !== [] && $tinta >= 0) {
            $f = static fn (int $n): int => self::taxaDinNopti($n, $leiPeNoapte, $oaspeti, $taxeLocale, $cotaTva);
            [$jos, $sus] = [-1, $tinta];
            while ($jos < $sus) {
                $m = intdiv($jos + $sus + 1, 2);
                if ($m + $f($m) <= $tinta) {
                    $jos = $m;
                } else {
                    $sus = $m - 1;
                }
            }
            $t = $jos >= 0 ? $f($jos) : $tinta + 1;
        }
        if ($tinta - $t < 0 || $rotund === 0) {
            return ['nopti' => $nopti, 'taxa_locala' => $taxaLocala, 'total' => $brut];
        }
        return ['nopti' => $tinta - $t, 'taxa_locala' => $t, 'total' => $rotund];
    }

    /**
     * Taxa locală (bani) a unei cazări de $noptiBani bani, ca în EasyCheck: nopțile sejurului se
     * scalează ca suma lor să fie cazarea dată, deci baza procentului, baza fără TVA și pragul sejurului lung rămân cele
     * de la calculul obișnuit. Taxa în sumă fixă e aceeași oricare ar fi cazarea.
     */
    private static function taxaDinNopti(int $noptiBani, array $leiPeNoapte, int $oaspeti, array $taxeLocale, float $cotaTva): int
    {
        $s = (float) array_sum($leiPeNoapte);
        $n = count($leiPeNoapte);
        $nopti = [];
        foreach ($leiPeNoapte as $x) {
            $nopti[] = $s > 0 ? (float) $x * ($noptiBani / 100) / $s : $noptiBani / 100 / $n;
        }
        $lei = 0.0;
        foreach ($taxeLocale as $par) {
            $lei += self::taxaPeSejur($par, $nopti, $oaspeti, $cotaTva);
        }
        return Bani::intregi($lei * 100);
    }

    /**
     * Împarte $total (bani) proporțional cu $ponderi, în întregi cu suma exact $total (metoda restului cel mai mare,
     * ca în EasyCheck). Ponderile toate zero: totul pe prima parte.
     * @return list<int>
     */
    public static function imparte(int $total, array $ponderi): array
    {
        $ponderi = array_map(static fn ($p): float => max(0.0, (float) $p), array_values($ponderi));
        $n = count($ponderi);
        if ($n === 0) {
            return [];
        }
        $s = array_sum($ponderi);
        $o = array_fill(0, $n, 0);
        if ($s <= 0 || $total <= 0) {
            $o[0] = $total;
            return $o;
        }
        $rest = [];
        foreach ($ponderi as $i => $p) {
            $x = $total * $p / $s;
            $o[$i] = (int) floor($x + 1e-7);
            $rest[$i] = $x - $o[$i];
        }
        $ordine = array_keys($rest);
        usort($ordine, static fn (int $a, int $b): int => [$rest[$b], $a] <=> [$rest[$a], $b]);
        $ramas = $total - array_sum($o);
        for ($k = 0; $ramas > 0; $k++, $ramas--) {
            $o[$ordine[$k % $n]]++;
        }
        return $o;
    }

    /** Taxele suplimentare ale unei camere, ca în GET /total, în bani. */
    public static function taxeCamera(array $taxe, string $camera, int $nopti, int $oaspeti): int
    {
        $pe = [];
        foreach ($taxe as $t) {
            $cam = $t['camere'] ?? null;
            if (is_array($cam) && !in_array($camera, $cam, true)) {
                continue;
            }
            $pe[(string) $t['fel']] = $t;
        }
        $scurt = $pe['curatenie_scurt'] ?? null;
        if ($scurt !== null && is_int($scurt['nopti_max'] ?? null) && $nopti <= (int) $scurt['nopti_max']) {
            $pe['curatenie'] = $scurt;
        }
        $suma = static fn (array $t, int $cate): int => match ((string) ($t['unitate'] ?? 'sejur')) {
            'noapte' => (int) $t['suma'] * $cate * $nopti,
            'gratis' => 0,
            default => (int) $t['suma'] * $cate,
        };
        $total = 0;
        foreach (['curatenie', 'lenjerie'] as $fel) {
            if (isset($pe[$fel])) {
                $total += $suma($pe[$fel], 1);
            }
        }
        if (isset($pe['persoana'])) {
            $total += $suma($pe['persoana'], max(0, $oaspeti - (int) ($pe['persoana']['oaspeti_inclusi'] ?? 0)));
        }
        return $total;
    }

    /**
     * Taxele locale ale sejurului, după regula declarației (ca GET /total din EasyCheck): fiecare taxă are data de
     * referință după momentul ei (`moment` al taxei, altfel `taxa_locala.moment`): sosirea la „sosire”, plecarea la
     * „plecare” și „factura” (fără factură în aplicație, declarația o pune pe plecare). Pentru anii sosirii și plecării,
     * o taxă intră doar dacă data ei de referință cade în anul ei, cu versiunea valabilă la acea dată (ultima `de_la` ≤
     * data), o dată pe denumire; o taxă rămasă pe dinafară (momente diferite între ani) ia regula anului sosirii, la
     * sosire. Fără niciun moment (un EasyCheck mai vechi), data de referință e sosirea, ca până acum. Forma întoarsă:
     * parametrii lui taxaPeSejur (valoare în lei sau procente).
     * @return list<array{mod:string, valoare:float, unitate:string, baza_tva:string, prag_sejur_lung:?int, prag_mod:string}>
     */
    public static function taxeLocale(array $taxaLocala, string $sosire, ?string $plecare = null): array
    {
        $global = in_array($taxaLocala['moment'] ?? null, ['sosire', 'plecare', 'factura'], true) ? $taxaLocala['moment'] : null;
        $pe = [];
        foreach ((array) ($taxaLocala['ani'] ?? []) as $a) {
            $pe[(int) ($a['an'] ?? 0)] = (array) ($a['taxe'] ?? []);
        }
        $anSosire = (int) substr($sosire, 0, 4);
        $ani = array_unique([$anSosire, $plecare !== null ? (int) substr($plecare, 0, 4) : $anSosire]);
        $rez = [];
        $vazute = [];
        foreach ($ani as $an) {
            foreach ($pe[$an] ?? [] as $t) {
                $moment = in_array($t['moment'] ?? null, ['sosire', 'plecare', 'factura'], true) ? $t['moment'] : $global;
                $ref = $moment === null || $moment === 'sosire' || $plecare === null ? $sosire : $plecare;
                $nume = (string) ($t['denumire'] ?? '');
                if ((int) substr($ref, 0, 4) !== $an || isset($vazute[$nume])) {
                    continue;
                }
                $vazute[$nume] = true;
                $rez[] = self::efectiva($t, $ref);
            }
        }
        foreach ($pe[$anSosire] ?? [] as $t) {
            $nume = (string) ($t['denumire'] ?? '');
            if (!isset($vazute[$nume])) {
                $vazute[$nume] = true;
                $rez[] = self::efectiva($t, $sosire);
            }
        }
        return $rez;
    }

    /** Parametrii unei taxe în versiunea valabilă la data $ref (ultima `de_la` ≤ $ref). */
    private static function efectiva(array $t, string $ref): array
    {
        $ef = $t;
        foreach ((array) ($t['versiuni'] ?? []) as $v) {
            if (is_string($v['de_la'] ?? null) && $v['de_la'] <= $ref) {
                $ef = $v + $t;
                $ef['mod'] = $v['mod'] ?? $t['mod'];
            }
        }
        $procent = ($ef['mod'] ?? 'fix') === 'procent';
        return [
            'mod' => $procent ? 'procent' : 'fix',
            'valoare' => $procent ? (int) ($ef['procent_sutimi'] ?? 0) / 100 : (int) ($ef['suma'] ?? 0) / 100,
            'unitate' => ($ef['unitate'] ?? 'noapte_pers') === 'sejur_pers' ? 'sejur_pers' : 'noapte_pers',
            'baza_tva' => ($t['baza_tva'] ?? 'fara') === 'cu' ? 'cu' : 'fara',
            'prag_sejur_lung' => is_int($t['prag_sejur_lung'] ?? null) ? $t['prag_sejur_lung'] : null,
            'prag_mod' => ($t['prag_mod'] ?? 'tot') === 'peste' ? 'peste' : 'tot',
        ];
    }

    /**
     * Taxa unui sejur, în lei rotunjiți la ban, ca în EasyCheck: $nopti = prețurile nopților în lei,
     * $oaspeti = persoanele, toate pe toate nopțile, fără scutiri (vârstele lipsesc la rezervare).
     */
    public static function taxaPeSejur(array $par, array $nopti, int $oaspeti, float $cotaTva = 0.0): float
    {
        $n = count($nopti);
        if ($n === 0 || $oaspeti <= 0) {
            return 0.0;
        }
        $v = (float) $par['valoare'];
        $prag = $par['prag_sejur_lung'];
        $nTax = $n;
        if ($prag !== null && $n > $prag) {
            if ($par['prag_mod'] === 'tot') {
                return 0.0;
            }
            $nTax = $prag;
        }
        if ($par['mod'] === 'procent') {
            $baza = 0.0;
            foreach ($nopti as $x) {
                $baza += (float) $x;
            }
            if ($par['baza_tva'] !== 'cu' && $cotaTva > 0) {
                $baza = $baza / (1 + $cotaTva / 100);
            }
            return Bani::rot2($baza * ($nTax / $n) * $v / 100);
        }
        $unitati = $par['unitate'] === 'noapte_pers' ? $oaspeti * $nTax : $oaspeti;
        return Bani::rot2($unitati * $v);
    }
}
