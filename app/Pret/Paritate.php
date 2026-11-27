<?php

declare(strict_types=1);

namespace Cazo\Pret;

use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Setari;

defined('CAZO') or exit;

/**
 * Verificarea nocturnă de paritate, doar la „Prețul din EasyCheck”: la „Prețul de pe
 * site-ul meu” prețul îl hotărăște site-ul, iar EasyCheck păstrează ce primește, deci acolo verificarea lipsește.
 *
 * Până la 20 de sejururi aleatorii (camera, planul vizibil, sosirea în următoarele 300 de zile, 1-7 nopți, 1-2 adulți
 * în limita camerei), trecute de restricțiile locale; prețul nopților calculat local (PretNoapte, fără reduceri) se
 * compară cu componenta `nopti` din GET /total. Bugetul: cel mult 20 de citiri pe noapte.
 *
 * La o diferență: calendarul trece pe zilele libere fără sumă pe zi (PretNoapte::$faraSumePeZi), cardurile rămân
 * pe „de la”, panoul arată alerta cu sejurul și cele două sume, iar proprietarii primesc e-mailul `alerta_paritate`
 * (cel mult unul pe zi). O verificare următoare fără diferențe readuce sumele pe zi.
 */
final class Paritate
{
    public const SEJURURI = 20;
    public const INCERCARI = 80;
    /** Diferența îngăduită la nopți, în bani: rotunjirea totalului la leu (ghidul API: „pot diferi cu până la 50 de bani”). */
    public const TOLERANTA = 50;

    /**
     * @param \Closure(list<Sejur>): array $totalEc GET /total pentru o listă de sejururi (TotalEasyCheck::cere)
     * @param \Closure(int, int): int $aleator
     */
    public function __construct(
        private PretNoapte $pret,
        private Setari $setari,
        private Ceas $ceas,
        private \Closure $totalEc,
        private ?\Closure $aleator = null,
    ) {
    }

    /** Ultimul rezultat: la, verificate, diferente (lista), sau null înainte de prima verificare. */
    public function stare(): ?array
    {
        $s = $this->setari->get('paritate.stare');
        return is_array($s) ? $s : null;
    }

    public function diferenta(): bool
    {
        return self::diferentaDin($this->setari);
    }

    /** Ultima verificare a găsit o diferență (citit din setări, fără să construiască verificarea). */
    public static function diferentaDin(Setari $setari): bool
    {
        $s = $setari->get('paritate.stare');
        return is_array($s) && ($s['diferente'] ?? []) !== [];
    }

    /**
     * @param array<string,?int> $capacitati oaspeții maximi pe cameră (GET /camere)
     * @return array{verificate:int, diferente:list<array>, schimbat:bool}
     */
    public function verifica(array $capacitati, string $azi): array
    {
        $sejururi = $this->sejururi($capacitati, $azi);
        $diferente = [];
        $verificate = 0;
        if ($sejururi !== []) {
            $raspunsuri = ($this->totalEc)(array_column($sejururi, 'sejur'));
            foreach ($sejururi as $k => $x) {
                $r = $raspunsuri[$k] ?? null;
                if (!is_array($r) || ($r['stare'] ?? '') !== 'ok') {
                    continue;
                }
                $verificate++;
                $ec = (int) ($r['componente']['nopti'] ?? 0);
                // totalul EasyCheck e rotund la leu, iar diferența de rotunjire intră în `nopti`: până la 50 de bani
                // între nopțile din grilă și cele din GET /total e rotunjirea, nu o diferență de preț.
                if (abs($ec - $x['local']) > self::TOLERANTA) {
                    $s = $x['sejur'];
                    $diferente[] = ['sosire' => $s->sosire, 'plecare' => $s->plecare, 'camera' => $s->camere[0]['camera'], 'plan' => $s->plan,
                        'oaspeti' => $s->oaspeti(0), 'local' => $x['local'], 'easycheck' => $ec];
                }
            }
        }
        $inainte = $this->diferenta();
        if ($verificate > 0) {
            $this->setari->pune('paritate.stare', ['la' => $this->ceas->moment(), 'verificate' => $verificate, 'diferente' => array_slice($diferente, 0, 5)]);
        }
        return ['verificate' => $verificate, 'diferente' => $diferente, 'schimbat' => $verificate > 0 && $inainte !== ($diferente !== [])];
    }

    /** @return list<array{sejur:Sejur, local:int}> */
    private function sejururi(array $capacitati, string $azi): array
    {
        $aleator = $this->aleator ?? static fn (int $a, int $b): int => random_int($a, $b);
        $camere = [];
        foreach (array_keys($capacitati) as $c) {
            $planuri = $this->pret->planuriCamera((string) $c);
            if ($planuri !== []) {
                $camere[(string) $c] = $planuri;
            }
        }
        if ($camere === []) {
            return [];
        }
        $ids = array_keys($camere);
        $rez = [];
        for ($i = 0; $i < self::INCERCARI && count($rez) < self::SEJURURI; $i++) {
            $cam = $ids[$aleator(0, count($ids) - 1)];
            $plan = $camere[$cam][$aleator(0, count($camere[$cam]) - 1)];
            $sosire = gmdate('Y-m-d', strtotime($azi . ' 00:00:00 UTC') + $aleator(1, 300) * 86400);
            $plecare = gmdate('Y-m-d', strtotime($sosire . ' 00:00:00 UTC') + $aleator(1, 7) * 86400);
            $max = $capacitati[$cam] ?? null;
            $adulti = max(1, min($max ?? 2, $aleator(1, 2)));
            if ($this->pret->restrictie($cam, $plan, $sosire, $plecare, $adulti, $max) !== null) {
                continue;
            }
            $nopti = $this->pret->nopti($cam, $plan, $sosire, $plecare, $adulti)['preturi'];
            $rez[] = ['sejur' => Sejur::din($sosire, $plecare, [['camera' => $cam, 'adulti' => $adulti, 'copii' => 0]], $plan), 'local' => (int) array_sum($nopti)];
        }
        return $rez;
    }
}
