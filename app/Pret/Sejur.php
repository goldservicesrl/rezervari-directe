<?php

declare(strict_types=1);

namespace Cazo\Pret;

defined('CAZO') or exit;

/**
 * Sejurul cerut de oaspete (adresa paginii, /_api/pret, deeplinkul de rezervare), validat: sosirea, plecarea și lista
 * `camere` (1-10 elemente, fiecare o unitate cu oaspeții ei), ca în API-ul EasyCheck, și un singur plan pentru toată
 * rezervarea: un `camere[i][plan]` din adresă devine planul rezervării doar când lipsește `plan`.
 * Forma scurtă (camera, adulti, copii, plan) se transformă în lista cu un element.
 */
final class Sejur
{
    public const NOPTI_MAX = 90;
    public const CAMERE_MAX = 10;
    public const OASPETI_MAX = 16;
    private const CAMERA = '~^cam_[0-9a-f]{16}$~';
    private const PLAN = '~^plan_(std|[0-9a-f]{16})$~';

    /** @param list<array{camera:string, adulti:int, copii:int, plan:?string}> $camere */
    private function __construct(
        public readonly string $sosire,
        public readonly string $plecare,
        public readonly array $camere,
        public readonly ?string $plan,
    ) {
    }

    /** @param list<array{camera:string, adulti:int, copii:int, plan?:?string}> $camere */
    public static function din(string $sosire, string $plecare, array $camere, ?string $plan = null): self
    {
        if (!self::zi($sosire) || !self::zi($plecare) || $plecare <= $sosire) {
            throw new \InvalidArgumentException('Datele sejurului sunt greșite.');
        }
        if (self::nopti($sosire, $plecare) > self::NOPTI_MAX) {
            throw new \InvalidArgumentException('Sejurul e prea lung.');
        }
        if ($plan !== null && !preg_match(self::PLAN, $plan)) {
            throw new \InvalidArgumentException('Plan greșit.');
        }
        if ($camere === [] || count($camere) > self::CAMERE_MAX || !array_is_list($camere)) {
            throw new \InvalidArgumentException('Lista camerelor e greșită.');
        }
        $lista = [];
        foreach ($camere as $c) {
            $cam = $c['camera'] ?? null;
            $a = $c['adulti'] ?? null;
            $k = $c['copii'] ?? 0;
            $p = $c['plan'] ?? null;
            if (!is_string($cam) || !preg_match(self::CAMERA, $cam) || !is_int($a) || !is_int($k) || $a < 1 || $k < 0 || $a + $k > self::OASPETI_MAX
                || ($p !== null && (!is_string($p) || !preg_match(self::PLAN, $p)))) {
                throw new \InvalidArgumentException('O cameră din listă e greșită.');
            }
            // Un singur plan pe rezervare: planul cerut sau, în lipsa lui, planul primei camere care are unul;
            // planurile pe cameră rămân deoparte (EasyCheck răspunde 422 plan_diferit la planuri diferite).
            $plan ??= $p;
            $lista[] = ['camera' => $cam, 'adulti' => $a, 'copii' => $k, 'plan' => null];
        }
        return new self($sosire, $plecare, $lista, $plan);
    }

    /**
     * Din parametrii adresei: sosire, plecare, plan și camere[i][camera|adulti|copii|plan], sau camera, adulti, copii.
     * Întoarce null când datele lipsesc sau sunt greșite (pagina arată atunci formularul gol).
     */
    public static function dinQuery(array $q, ?string $cameraImplicita = null): ?self
    {
        $int = static fn (mixed $v, int $implicit): ?int => $v === null || $v === '' ? $implicit : (is_string($v) && preg_match('~^\d{1,2}$~', $v) ? (int) $v : null);
        $sir = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;
        $camere = [];
        if (isset($q['camere']) && is_array($q['camere'])) {
            foreach (array_slice($q['camere'], 0, self::CAMERE_MAX + 1, true) as $c) {
                if (!is_array($c)) {
                    return null;
                }
                $camere[] = ['camera' => $sir($c['camera'] ?? null) ?? $cameraImplicita, 'adulti' => $int($c['adulti'] ?? null, 2), 'copii' => $int($c['copii'] ?? null, 0), 'plan' => $sir($c['plan'] ?? null)];
            }
        } else {
            $camere[] = ['camera' => $sir($q['camera'] ?? null) ?? $cameraImplicita, 'adulti' => $int($q['adulti'] ?? null, 2), 'copii' => $int($q['copii'] ?? null, 0), 'plan' => null];
        }
        try {
            return self::din((string) ($q['sosire'] ?? ''), (string) ($q['plecare'] ?? ''), $camere, $sir($q['plan'] ?? null));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** Doar datele și oaspeții (căutarea pe toate camerele): sosire, plecare, adulti, copii. */
    public static function cautare(array $q): ?array
    {
        $sosire = (string) ($q['sosire'] ?? '');
        $plecare = (string) ($q['plecare'] ?? '');
        $a = $q['adulti'] ?? '2';
        $k = $q['copii'] ?? '0';
        if (!self::zi($sosire) || !self::zi($plecare) || $plecare <= $sosire || self::nopti($sosire, $plecare) > self::NOPTI_MAX
            || !is_string($a) || !is_string($k) || !preg_match('~^\d{1,2}$~', $a) || !preg_match('~^\d{1,2}$~', $k)) {
            return null;
        }
        $a = (int) $a;
        $k = (int) $k;
        if ($a < 1 || $a + $k > self::OASPETI_MAX) {
            return null;
        }
        return ['sosire' => $sosire, 'plecare' => $plecare, 'adulti' => $a, 'copii' => $k];
    }

    public static function zi(string $z): bool
    {
        return (bool) preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $z, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public static function nopti(string $sosire, string $plecare): int
    {
        return (int) ((strtotime($plecare . ' 00:00:00 UTC') - strtotime($sosire . ' 00:00:00 UTC')) / 86400);
    }

    /** @return list<string> nopțile sejurului: de la sosire, fără ziua plecării */
    public static function zile(string $sosire, string $plecare): array
    {
        $r = [];
        for ($t = strtotime($sosire . ' 00:00:00 UTC'), $f = strtotime($plecare . ' 00:00:00 UTC'); $t < $f; $t += 86400) {
            $r[] = gmdate('Y-m-d', $t);
        }
        return $r;
    }

    public function numarNopti(): int
    {
        return self::nopti($this->sosire, $this->plecare);
    }

    /** Planul unei camere din listă: al ei, apoi cel al sejurului. */
    public function planCamera(int $i): ?string
    {
        return $this->camere[$i]['plan'] ?? $this->plan;
    }

    public function oaspeti(int $i): int
    {
        return $this->camere[$i]['adulti'] + $this->camere[$i]['copii'];
    }

    /** Forma canonică (cheile de cache, parametrii GET /total). */
    public function canonic(): array
    {
        return ['sosire' => $this->sosire, 'plecare' => $this->plecare, 'plan' => $this->plan, 'camere' => $this->camere];
    }

    /** Parametrii pentru adresă (deeplinkul de rezervare) și pentru GET /total. */
    public function query(): array
    {
        $q = ['sosire' => $this->sosire, 'plecare' => $this->plecare];
        foreach ($this->camere as $i => $c) {
            $q['camere'][$i] = ['camera' => $c['camera'], 'adulti' => (string) $c['adulti'], 'copii' => (string) $c['copii']];
            if ($c['plan'] !== null) {
                $q['camere'][$i]['plan'] = $c['plan'];
            }
        }
        if ($this->plan !== null) {
            $q['plan'] = $this->plan;
        }
        return $q;
    }

    /** Același sejur cu alt plan pe toate camerele. */
    public function cuPlan(?string $plan): self
    {
        $c = array_map(static fn (array $x): array => array_replace($x, ['plan' => null]), $this->camere);
        return self::din($this->sosire, $this->plecare, $c, $plan);
    }
}
