<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Cazo\Baza\Baza;
use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Pret\PretNoapte;

defined('CAZO') or exit;

/**
 * Harta termică a prețurilor (componenta-semnătură `harta_termica_preturi`, tema Faleză): prețul median pe noapte în fiecare din următoarele 12 luni, cu lunile fără preț marcate închise,
 * calendarul pe zile al unei luni (prețul, ocupat, închis, sărbătorile legale) și planurile camerei cu „de la” și
 * anularea. Totul din oglinda EasyCheck (`nopti_pret`, `nopti`, `planuri`), prin PretNoapte, fără cereri noi.
 *
 * Camera de referință e cea cu prețul „de la” cel mai mic (prima, la egalitate), pentru 2 oaspeți (sau capacitatea
 * ei, când e mai mică), în planul ei implicit (Standard, altfel primul plan vizibil). Sumele sunt în bani.
 * Medianele împart lunile în cel mult 5 trepte egale, între cea mai mică și cea mai mare mediană.
 */
final class PreturiSezon
{
    public const LUNI = 12;
    public const TREPTE = 5;
    public const OASPETI = 2;

    public function __construct(
        private Baza $baza,
        private PretNoapte $pret,
        private Traducator $t,
    ) {
    }

    /**
     * @param list<array> $camere camerele modelului de vizualizare (cu pret_de_la)
     * @param string|null $ziLuna o zi (AAAA-LL-ZZ) din luna arătată pe zile; null sau o zi în afara celor 12 luni:
     *                            prima lună cu prețuri
     * @return array|null null fără o cameră cu prețuri
     */
    public function date(array $camere, string $azi, ?string $ziLuna): ?array
    {
        $ref = null;
        foreach ($camere as $c) {
            $s = $c['pret_de_la']['suma'] ?? null;
            if (is_int($s) && ($ref === null || $s < $ref['pret_de_la']['suma'])) {
                $ref = $c;
            }
        }
        if ($ref === null) {
            return null;
        }
        $plan = $this->pret->planImplicit($ref['id']);
        if ($plan === null) {
            return null;
        }
        $l = $this->t->limba();
        $oaspeti = min(self::OASPETI, max(1, (int) ($ref['oaspeti_max'] ?? self::OASPETI)));
        $t0 = strtotime(substr($azi, 0, 7) . '-01 00:00:00 UTC');
        $de = $azi;
        $pana = gmdate('Y-m-t', strtotime('+' . (self::LUNI - 1) . ' month', $t0));
        $nopti = [];
        foreach ($this->baza->toate('SELECT zi, libere, inchis FROM {nopti} WHERE camera = ? AND zi >= ? AND zi <= ?', [$ref['id'], $de, $pana]) as $r) {
            $nopti[$r['zi']] = $r;
        }
        $regula = $this->pret->regulaPersoana($ref['id'], $plan);
        $zile = [];
        foreach ($this->pret->grila($ref['id'], $plan, $de, $pana) as $zi => $r) {
            $n = $nopti[$zi] ?? null;
            if ($r['pret'] !== null && (int) $r['inchis'] === 0 && ($n === null || (int) $n['inchis'] === 0)) {
                $zile[$zi] = PretNoapte::cuPersoane((int) $r['pret'], $regula, $oaspeti);
            }
        }

        // Medianele lunilor, apoi treptele.
        $luni = [];
        for ($k = 0; $k < self::LUNI; $k++) {
            $tl = strtotime('+' . $k . ' month', $t0);
            $id = gmdate('Y-m', $tl);
            $valori = [];
            foreach ($zile as $zi => $p) {
                if (str_starts_with($zi, $id)) {
                    $valori[] = $p;
                }
            }
            $luni[] = ['id' => $id, 'an' => (int) gmdate('Y', $tl), 'luna' => (int) gmdate('n', $tl), 'nume' => Format::luna((int) gmdate('n', $tl), $l),
                'mediana' => $valori === [] ? null : self::mediana($valori)];
        }
        $mediane = array_values(array_filter(array_column($luni, 'mediana'), 'is_int'));
        if ($mediane === []) {
            return null;
        }
        $min = min($mediane);
        $max = max($mediane);
        $treapta = static function (int $p) use ($min, $max): int {
            if ($max === $min) {
                return 1;
            }
            return max(1, min(self::TREPTE, 1 + intdiv(($p - $min) * self::TREPTE, $max - $min + 1)));
        };
        foreach ($luni as &$x) {
            $x['treapta'] = $x['mediana'] === null ? 0 : $treapta($x['mediana']);
            $x['mediana_lei'] = $x['mediana'] === null ? null : intdiv($x['mediana'] + 50, 100);
            $x['mediana_text'] = $x['mediana'] === null ? '' : Componente::leiScurt(intdiv($x['mediana'] + 50, 100) * 100, $l);
        }
        unset($x);

        // Luna pe zile: luna zilei date, altfel prima lună cu prețuri.
        $aleasa = null;
        foreach ($luni as $x) {
            if (is_string($ziLuna) && str_starts_with($ziLuna, $x['id'])) {
                $aleasa = $x['id'];
            }
        }
        if ($aleasa === null) {
            foreach ($luni as $x) {
                if ($x['mediana'] !== null) {
                    $aleasa = $x['id'];
                    break;
                }
            }
        }
        foreach ($luni as &$x) {
            $x['afisata'] = $x['id'] === $aleasa;
        }
        unset($x);
        $lunaZile = $aleasa === null ? null : $this->luna($aleasa, $azi, $nopti, $ref['id'], $plan, $regula, $oaspeti, $treapta);

        // Legenda: treptele folosite de luni și de zilele lunii afișate (o zi poate cădea pe o treaptă fără nicio
        // lună), cu valorile reale din fiecare treaptă: medianele lunilor și prețurile zilelor libere.
        $valoriTreapta = [];
        foreach ($luni as $x) {
            if ($x['treapta'] > 0) {
                $valoriTreapta[$x['treapta']][] = $x['mediana'];
            }
        }
        foreach ($lunaZile['zile'] ?? [] as $z) {
            if ($z['treapta'] > 0) {
                $valoriTreapta[$z['treapta']][] = $z['pret'];
            }
        }
        ksort($valoriTreapta);
        $trepte = [];
        foreach ($valoriTreapta as $i => $vals) {
            $trepte[] = ['treapta' => $i, 'de_lei' => intdiv(min($vals) + 50, 100), 'pana_lei' => intdiv(max($vals) + 50, 100)];
        }

        $planuri = [];
        foreach ($this->pret->planuriCamera($ref['id']) as $pid) {
            $p = $this->pret->planuri()[$pid];
            $minPlan = null;
            $rp = $this->pret->regulaPersoana($ref['id'], $pid);
            foreach ($this->pret->grila($ref['id'], $pid, $de, $pana) as $zi => $r) {
                $n = $nopti[$zi] ?? null;
                if ($r['pret'] !== null && (int) $r['inchis'] === 0 && $n !== null && (int) $n['inchis'] === 0 && (int) $n['libere'] > 0) {
                    $v = PretNoapte::cuPersoane((int) $r['pret'], $rp, $oaspeti);
                    $minPlan = $minPlan === null ? $v : min($minPlan, $v);
                }
            }
            $planuri[] = ['id' => $pid, 'titlu' => $this->titluPlan($p), 'de_la' => $minPlan, 'de_la_text' => $minPlan === null ? '' : Format::baniOaspete($minPlan, $l),
                'prag_nopti' => ($p['tip'] ?? null) === 'sejur_lung' && (int) ($p['prag_nopti'] ?? 0) > 1 ? (int) $p['prag_nopti'] : null,
                'anulare' => $this->anulare($p), 'masa' => Componente::masaPlan($p, $l, $this->t)];
        }
        $planuri = Componente::cuDoarCazare($planuri, 'masa', $this->t);
        $titluPlan = $this->titluPlan($this->pret->planuri()[$plan]);

        return [
            'camera' => ['id' => $ref['id'], 'titlu' => $ref['titlu'], 'titlu_limba' => $ref['titlu_limba'] ?? $l, 'url' => $ref['url']],
            'plan' => ['id' => $plan, 'titlu' => $titluPlan],
            'oaspeti' => $oaspeti,
            'oaspeti_text' => $this->t->plural('unitate.oaspeti', $oaspeti),
            'luni' => $luni,
            'trepte' => $trepte,
            'luna' => $lunaZile,
            'planuri' => $planuri,
            'zile_saptamana' => array_map(fn (int $i): string => $this->t->t('calendar.zi_scurt.' . $i), range(1, 7)),
        ];
    }

    /** O lună pe zile, cu celulele goale de la început (luni = prima coloană). */
    private function luna(string $id, string $azi, array $nopti, string $camera, string $plan, ?array $regula, int $oaspeti, \Closure $treapta): array
    {
        $l = $this->t->limba();
        $t0 = strtotime($id . '-01 00:00:00 UTC');
        $nr = (int) gmdate('t', $t0);
        $grila = $this->pret->grila($camera, $plan, $id . '-01', gmdate('Y-m-t', $t0));
        $sarbatori = self::sarbatori((int) gmdate('Y', $t0));
        $zile = [];
        for ($d = 1; $d <= $nr; $d++) {
            $zi = sprintf('%s-%02d', $id, $d);
            $r = $grila[$zi] ?? null;
            $n = $nopti[$zi] ?? null;
            $pret = $r !== null && $r['pret'] !== null && (int) $r['inchis'] === 0 ? PretNoapte::cuPersoane((int) $r['pret'], $regula, $oaspeti) : null;
            $stare = match (true) {
                $zi < $azi => 'trecuta',
                $n === null => 'necunoscuta',
                (int) $n['inchis'] === 1 || ($r !== null && (int) $r['inchis'] === 1) => 'inchisa',
                (int) $n['libere'] <= 0 => 'ocupata',
                $pret === null => 'fara_pret',
                default => 'libera',
            };
            $pret = $stare === 'libera' ? $pret : null;
            $parti = [Format::ziLunga($zi, $l), $this->t->t('calendar.stare.' . $stare)];
            if ($pret !== null) {
                $parti[] = Componente::leiScurt($pret, $l);
            }
            $zile[] = ['zi' => $zi, 'numar' => $d, 'stare' => $stare, 'pret' => $pret, 'pret_lei' => $pret === null ? null : intdiv($pret + 50, 100),
                'treapta' => $pret === null ? 0 : $treapta($pret), 'sarbatoare' => isset($sarbatori[$zi]), 'eticheta' => implode(', ', $parti)];
        }
        return ['id' => $id, 'an' => (int) gmdate('Y', $t0), 'nume' => Format::luna((int) gmdate('n', $t0), $l), 'gol' => (int) gmdate('N', $t0) - 1, 'zile' => $zile];
    }

    /** Titlul planului: cel scris de gazdă în site, pe limbă, altfel cel din EasyCheck. */
    private function titluPlan(array $p): string
    {
        $local = $this->baza->valoare('SELECT titlu_local FROM {planuri_local} WHERE plan = ?', [$p['id']]);
        $t = is_string($local) ? (json_decode($local, true)[$this->t->limba()] ?? null) : null;
        return is_string($t) && $t !== '' ? $t : (string) $p['titlu'];
    }

    /** Politica de anulare a planului, cu textele nucleului (aceleași ca lângă preț). */
    private function anulare(array $p): string
    {
        if (($p['tip'] ?? null) === 'nerambursabil') {
            return $this->t->t('anulare.nerambursabil');
        }
        $z = $p['anulare_zile'] ?? null;
        if ($z === null || $z === '') {
            return $this->t->t('anulare.la_cerere');
        }
        $z = (int) $z;
        return $z === 0 ? $this->t->t('anulare.pana_la_sosire') : $this->t->t('anulare.gratuita', ['n' => $this->t->plural('unitate.zile', $z)]);
    }

    /** @param non-empty-list<int> $v */
    public static function mediana(array $v): int
    {
        sort($v);
        $n = count($v);
        return $n % 2 === 1 ? $v[intdiv($n, 2)] : intdiv($v[$n / 2 - 1] + $v[$n / 2] + 1, 2);
    }

    /**
     * Zilele de sărbătoare legală din România (Codul muncii, art. 139 alin. (1), cu 6 și 7 ianuarie din 2024):
     * 1 și 2 ianuarie, 6 și 7 ianuarie, 24 ianuarie, Vinerea Mare, Paștele și a doua zi, 1 mai, 1 iunie, Rusaliile
     * și a doua zi, 15 august, 30 noiembrie, 1 decembrie, 25 și 26 decembrie. Paștele ortodox: formula lui Meeus în
     * calendarul iulian, mutată în gregorian (+13 zile, valabil 1900-2099).
     * @return array<string, true>
     */
    public static function sarbatori(int $an): array
    {
        $fixe = ['01-01', '01-02', '01-24', '05-01', '06-01', '08-15', '11-30', '12-01', '12-25', '12-26'];
        if ($an >= 2024) {
            array_push($fixe, '01-06', '01-07');
        }
        $a = $an % 4;
        $b = $an % 7;
        $c = $an % 19;
        $d = (19 * $c + 15) % 30;
        $e = (2 * $a + 4 * $b - $d + 34) % 7;
        $luna = intdiv($d + $e + 114, 31);
        $zi = ($d + $e + 114) % 31 + 1;
        $paste = strtotime(sprintf('%04d-%02d-%02d 00:00:00 UTC', $an, $luna, $zi)) + 13 * 86400;
        $rez = [];
        foreach ($fixe as $f) {
            $rez[$an . '-' . $f] = true;
        }
        foreach ([-2, 0, 1, 49, 50] as $dif) {
            $rez[gmdate('Y-m-d', $paste + $dif * 86400)] = true;
        }
        return $rez;
    }
}
