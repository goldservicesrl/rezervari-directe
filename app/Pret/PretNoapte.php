<?php

declare(strict_types=1);

namespace Cazo\Pret;

use Cazo\Baza\Baza;

defined('CAZO') or exit;

/**
 * Prețul pe noapte, calculat local din oglindă, la ambele surse de preț: calendarul,
 * „de la X lei pe noapte” și restricțiile sejurului. Grila din GET /preturi are deja prețul nopții pe fiecare
 * plan (planurile calculate, weekendul și prețul pus pe zi le face EasyCheck); aici se aplică regula pe persoană
 * (ocupare_baza, minus_persoana, plus_persoana), ca în EasyCheck, și restricțiile, cu aceleași
 * motive și în aceeași ordine ca API-ul, ca mesajele să coincidă.
 *
 * Totalul sejurului nu se calculează aici: la „Prețul din EasyCheck” vine din GET /total, la „Prețul de pe
 * site-ul meu” din MotorLocal (care folosește prețurile nopților de aici).
 */
final class PretNoapte
{
    /** @var array<string, array> planurile, după id */
    private ?array $planuri = null;

    /** @var array<string, array<string, array>> cache pe [camera|plan] => [zi => rând] */
    private array $grile = [];

    /**
     * Reducerile locale aplicate în calendar și în „de la”, doar la „Prețul de pe site-ul meu”: cele fără
     * condiții care depind de sejur. Null la „Prețul din EasyCheck” și când gazda n-are reduceri active.
     */
    public ?ReduceriLocale $reduceri = null;

    /**
     * Paritatea nocturnă a găsit o diferență la „Prețul din EasyCheck” (Paritate): calendarul arată zilele
     * libere fără suma pe zi, iar cardurile rămân pe „de la”, până la o verificare fără diferențe.
     */
    public bool $faraSumePeZi = false;

    public function __construct(private Baza $baza)
    {
    }

    /** @return array<string, array> planurile din oglindă, cu `pe_persoana` decodat, în ordinea din EasyCheck */
    public function planuri(): array
    {
        if ($this->planuri === null) {
            $this->planuri = [];
            $ascunse = [];
            foreach ($this->baza->toate("SELECT plan FROM {planuri_local} WHERE vizibilitate = 'ascuns'") as $r) {
                $ascunse[$r['plan']] = true;
            }
            foreach ($this->baza->toate('SELECT * FROM {planuri} ORDER BY ordine, id') as $p) {
                $p['pe_persoana'] = $p['pe_persoana'] !== null ? (json_decode((string) $p['pe_persoana'], true) ?: []) : [];
                $p['regula'] = $p['regula'] !== null ? json_decode((string) $p['regula'], true) : null;
                $p['ascuns'] = isset($ascunse[$p['id']]);
                // Titlul scris de gazdă în EasyCheck, cu literele cu sedilă corectate la afișare.
                $p['titlu'] = \Cazo\I18n\Format::diacritice((string) $p['titlu']);
                $this->planuri[$p['id']] = $p;
            }
        }
        return $this->planuri;
    }

    /** @return list<string> planurile vizibile pe site care au prețuri pe camera dată */
    public function planuriCamera(string $camera): array
    {
        $cu = [];
        foreach ($this->baza->toate('SELECT DISTINCT plan FROM {nopti_pret} WHERE camera = ?', [$camera]) as $r) {
            $cu[$r['plan']] = true;
        }
        $rez = [];
        foreach ($this->planuri() as $id => $p) {
            if (isset($cu[$id]) && !$p['ascuns']) {
                $rez[] = $id;
            }
        }
        return $rez;
    }

    /** Planul implicit al unei camere: Standard când îl are, altfel primul plan vizibil. */
    public function planImplicit(string $camera): ?string
    {
        $p = $this->planuriCamera($camera);
        return in_array('plan_std', $p, true) ? 'plan_std' : ($p[0] ?? null);
    }

    /** Prețurile din oglindă s-au schimbat (Oglinda::laSchimbare('preturi')): planurile și grilele se citesc din nou. */
    public function golesteCache(): void
    {
        $this->planuri = null;
        $this->grile = [];
    }

    /** @return array<string, array> rândurile grilei pe [de, pana] inclusiv, după zi */
    public function grila(string $camera, string $plan, string $de, string $pana): array
    {
        $k = "$camera|$plan|$de|$pana";
        if (!isset($this->grile[$k])) {
            $r = [];
            foreach ($this->baza->toate('SELECT * FROM {nopti_pret} WHERE camera = ? AND plan = ? AND zi >= ? AND zi <= ? ORDER BY zi', [$camera, $plan, $de, $pana]) as $z) {
                $r[$z['zi']] = $z;
            }
            $this->grile[$k] = $r;
        }
        return $this->grile[$k];
    }

    /** Regula pe persoană a planului pe cameră, sau null. */
    public function regulaPersoana(string $camera, string $plan): ?array
    {
        $pp = $this->planuri()[$plan]['pe_persoana'][$camera] ?? null;
        return is_array($pp) ? $pp : null;
    }

    /**
     * Prețul unei nopți pentru $oaspeti, din prețul de bază al zilei, ca în EasyCheck:
     * sub ocuparea de bază scade cu minus_persoana pe persoană, peste ea crește cu plus_persoana; cel puțin 0.
     */
    public static function cuPersoane(int $pretBaza, ?array $regula, int $oaspeti): int
    {
        if ($regula === null) {
            return max(0, $pretBaza);
        }
        $b = (int) $regula['ocupare_baza'];
        $p = $oaspeti < $b ? $pretBaza - ($b - $oaspeti) * (int) $regula['minus_persoana'] : $pretBaza + ($oaspeti - $b) * (int) $regula['plus_persoana'];
        return max(0, $p);
    }

    /**
     * Prețurile nopților sejurului pe o cameră și un plan, pentru $oaspeti: [zi => bani], sau restricția `fara_pret`.
     * @return array{preturi: array<string,int>, restrictie: ?array}
     */
    public function nopti(string $camera, string $plan, string $sosire, string $plecare, int $oaspeti): array
    {
        $zile = Sejur::zile($sosire, $plecare);
        $grila = $this->grila($camera, $plan, $sosire, $plecare);
        $regula = $this->regulaPersoana($camera, $plan);
        $preturi = [];
        $fara = [];
        foreach ($zile as $z) {
            $p = $grila[$z]['pret'] ?? null;
            if ($p === null) {
                $fara[] = $z;
                continue;
            }
            $preturi[$z] = self::cuPersoane((int) $p, $regula, $oaspeti);
        }
        return ['preturi' => $preturi, 'restrictie' => $fara !== [] ? ['motiv' => 'fara_pret', 'zile' => $fara] : null];
    }

    /**
     * Restricțiile unei camere din listă, în ordinea din GET /total: oaspeții peste capacitate,
     * sejurul minim din ziua sosirii, sejurul maxim, sosirea închisă, sejurul minim prin fiecare noapte, plecarea
     * închisă, zilele închise (la vânzare sau pe plan), apoi nopțile fără preț. Null = sejurul trece.
     * @return array{motiv:string, valoare?:int, zile?:list<string>}|null
     */
    public function restrictie(string $camera, ?string $plan, string $sosire, string $plecare, int $oaspeti, ?int $oaspetiMax): ?array
    {
        $n = Sejur::nopti($sosire, $plecare);
        $regula = $plan !== null ? $this->regulaPersoana($camera, $plan) : null;
        $max = $oaspetiMax ?? (isset($regula['oaspeti_max']) ? (int) $regula['oaspeti_max'] : null);
        if ($max !== null && $max > 0 && $oaspeti > $max) {
            return ['motiv' => 'oaspeti_max', 'valoare' => $max];
        }
        $inchisePlan = [];
        if ($plan !== null) {
            $g = $this->grila($camera, $plan, $sosire, $plecare);
            $s0 = $g[$sosire] ?? null;
            if ($s0 !== null) {
                if ((int) $s0['sejur_minim'] > $n) {
                    return ['motiv' => 'sejur_minim', 'valoare' => (int) $s0['sejur_minim']];
                }
                if ((int) $s0['sejur_maxim'] > 0 && $n > (int) $s0['sejur_maxim']) {
                    return ['motiv' => 'sejur_maxim', 'valoare' => (int) $s0['sejur_maxim']];
                }
                if ((int) $s0['sosire_inchisa'] === 1) {
                    return ['motiv' => 'sosire_inchisa', 'zile' => [$sosire]];
                }
            }
            foreach (Sejur::zile($sosire, $plecare) as $z) {
                if (isset($g[$z]) && (int) $g[$z]['sejur_minim_prin'] > $n) {
                    return ['motiv' => 'sejur_minim_prin', 'valoare' => (int) $g[$z]['sejur_minim_prin'], 'zile' => [$z]];
                }
                if (isset($g[$z]) && (int) $g[$z]['inchis'] === 1) {
                    $inchisePlan[$z] = true;
                }
            }
            if (isset($g[$plecare]) && (int) $g[$plecare]['plecare_inchisa'] === 1) {
                return ['motiv' => 'plecare_inchisa', 'zile' => [$plecare]];
            }
        }
        foreach ($this->baza->toate('SELECT zi FROM {nopti} WHERE camera = ? AND zi >= ? AND zi < ? AND inchis = 1', [$camera, $sosire, $plecare]) as $r) {
            $inchisePlan[$r['zi']] = true;
        }
        if ($inchisePlan !== []) {
            $zile = array_keys($inchisePlan);
            sort($zile);
            return ['motiv' => 'inchis', 'zile' => $zile];
        }
        if ($plan !== null) {
            $f = $this->nopti($camera, $plan, $sosire, $plecare, $oaspeti)['restrictie'];
            if ($f !== null) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Nopțile fără loc pentru lista de camere (aceeași cameră se poate repeta: unitățile se adună pe cameră).
     * O noapte absentă din oglindă se socotește ocupată (prudent). @return list<string>
     */
    public function noptiPline(array $camere, string $sosire, string $plecare): array
    {
        $nevoie = [];
        foreach ($camere as $c) {
            $nevoie[$c] = ($nevoie[$c] ?? 0) + 1;
        }
        $zile = Sejur::zile($sosire, $plecare);
        $pline = [];
        foreach ($nevoie as $cam => $k) {
            $libere = [];
            foreach ($this->baza->toate('SELECT zi, libere, inchis FROM {nopti} WHERE camera = ? AND zi >= ? AND zi < ?', [$cam, $sosire, $plecare]) as $r) {
                $libere[$r['zi']] = (int) $r['inchis'] === 1 ? 0 : (int) $r['libere'];
            }
            foreach ($zile as $z) {
                if (($libere[$z] ?? 0) < $k) {
                    $pline[$z] = true;
                }
            }
        }
        $r = array_keys($pline);
        sort($r);
        return $r;
    }

    /**
     * „De la X lei pe noapte”: cel mai mic preț de bază al unei nopți libere pe tariful afișat al camerei
     * (Standard când îl are, altfel primul plan vizibil; planImplicit), în următoarele $zile zile de la $de. Tarifele
     * cu sejur minim lung (sejur lung, nerambursabil) rămân în afara acestui preț: oaspetele vede prețul unei nopți
     * obișnuite. Null când camera n-are nicio noapte cu preț în interval.
     */
    public function deLa(string $camera, string $de, int $zile = 60): ?int
    {
        $plan = $this->planImplicit($camera);
        if ($plan === null) {
            return null;
        }
        $pana = gmdate('Y-m-d', strtotime($de . ' 00:00:00 UTC') + ($zile - 1) * 86400);
        if ($this->reduceri !== null && $this->reduceri->active() !== []) {
            // Cu reduceri locale, minimul se caută după reducerea fiecărei nopți (perioadele lor diferă de la zi la zi).
            $min = null;
            foreach ($this->baza->toate(
                'SELECT p.plan, p.zi, p.pret FROM {nopti_pret} p JOIN {nopti} n ON n.camera = p.camera AND n.zi = p.zi
                 WHERE p.camera = ? AND p.zi >= ? AND p.zi <= ? AND p.pret IS NOT NULL AND p.inchis = 0 AND n.inchis = 0 AND n.libere > 0 AND p.plan = ?',
                [$camera, $de, $pana, $plan]
            ) as $r) {
                $x = $this->reduceri->pentruNoapte($camera, (string) $r['plan'], substr((string) $r['zi'], 0, 10), (int) $r['pret']);
                $min = $min === null ? $x : min($min, $x);
            }
            return $min;
        }
        $v = $this->baza->valoare(
            'SELECT MIN(p.pret) FROM {nopti_pret} p JOIN {nopti} n ON n.camera = p.camera AND n.zi = p.zi
             WHERE p.camera = ? AND p.zi >= ? AND p.zi <= ? AND p.pret IS NOT NULL AND p.inchis = 0 AND n.inchis = 0 AND n.libere > 0 AND p.plan = ?',
            [$camera, $de, $pana, $plan]
        );
        return $v === null ? null : (int) $v;
    }

    /**
     * Zilele calendarului pe o cameră, între $de și $pana (inclusiv), pentru $oaspeti: starea (libera, ocupata,
     * inchisa, fara_pret, trecuta), prețul nopții, sejurul minim, sosirea și plecarea închise. Prețul și sejurul minim
     * sunt ale tarifului afișat (Standard când camera îl are, planImplicit), nu minimul peste toate planurile:
     * un tarif de sejur lung are preț mic și sejur minim mare, iar ziua ar spune „minimum 28 de nopți” când se pot
     * rezerva 1-3. Doar într-o zi în care tariful afișat n-are preț sau e închis se arată cel mai mic preț al celorlalte.
     * @return list<array{zi:string, stare:string, pret:?int, sejur_minim:int, sosire_inchisa:bool, plecare_inchisa:bool}>
     */
    public function calendar(string $camera, string $de, string $pana, int $oaspeti, string $azi): array
    {
        $planuri = $this->planuriCamera($camera);
        $afisat = $this->planImplicit($camera);
        $grile = [];
        foreach ($planuri as $p) {
            $grile[$p] = $this->grila($camera, $p, $de, $pana);
        }
        $nopti = [];
        foreach ($this->baza->toate('SELECT zi, libere, inchis FROM {nopti} WHERE camera = ? AND zi >= ? AND zi <= ?', [$camera, $de, $pana]) as $r) {
            $nopti[$r['zi']] = $r;
        }
        $rez = [];
        for ($t = strtotime($de . ' 00:00:00 UTC'), $f = strtotime($pana . ' 00:00:00 UTC'); $t <= $f; $t += 86400) {
            $z = gmdate('Y-m-d', $t);
            $pret = null;
            $min = 0;
            $sosireInchisa = true;
            $plecareInchisa = true;
            $inchisPlan = true;
            $cuRand = false;
            // Tariful afișat are preț în ziua asta: celelalte planuri contează doar pentru plecarea închisă.
            $ra = $afisat !== null ? ($grile[$afisat][$z] ?? null) : null;
            $doarAfisat = $ra !== null && $ra['pret'] !== null && (int) $ra['inchis'] !== 1;
            foreach ($grile as $p => $g) {
                $r = $g[$z] ?? null;
                if ($r === null) {
                    continue;
                }
                $cuRand = true;
                $plecareInchisa = $plecareInchisa && (int) $r['plecare_inchisa'] === 1;
                if ($r['pret'] === null || (int) $r['inchis'] === 1 || ($doarAfisat && $p !== $afisat)) {
                    continue;
                }
                $inchisPlan = false;
                $x = self::cuPersoane((int) $r['pret'], $this->regulaPersoana($camera, $p), $oaspeti);
                if ($this->reduceri !== null) {
                    $x = $this->reduceri->pentruNoapte($camera, $p, $z, $x);
                }
                if ($pret === null || $x < $pret) {
                    $pret = $x;
                    $min = (int) $r['sejur_minim'];
                }
                $sosireInchisa = $sosireInchisa && (int) $r['sosire_inchisa'] === 1;
            }
            $n = $nopti[$z] ?? null;
            $stare = match (true) {
                $z < $azi => 'trecuta',
                $n === null => 'necunoscuta',
                (int) $n['inchis'] === 1 || ($inchisPlan && $grile !== [] && $pret === null && $this->areRand($grile, $z)) => 'inchisa',
                (int) $n['libere'] <= 0 => 'ocupata',
                $pret === null => 'fara_pret',
                default => 'libera',
            };
            $rez[] = [
                'zi' => $z,
                'stare' => $stare,
                'pret' => $stare === 'libera' && !$this->faraSumePeZi ? $pret : null,
                'sejur_minim' => max(1, $min),
                'sosire_inchisa' => $stare === 'libera' && $sosireInchisa,
                'plecare_inchisa' => $cuRand && $plecareInchisa,
            ];
        }
        return $rez;
    }

    private function areRand(array $grile, string $z): bool
    {
        foreach ($grile as $g) {
            if (isset($g[$z]) && (int) $g[$z]['inchis'] === 1) {
                return true;
            }
        }
        return false;
    }
}
