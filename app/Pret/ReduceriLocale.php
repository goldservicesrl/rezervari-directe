<?php

declare(strict_types=1);

namespace Cazo\Pret;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Setari;

defined('CAZO') or exit;

/**
 * Reducerile locale (tabelul `reduceri_local`), doar la „Prețul de pe site-ul meu”.
 *
 * O reducere are felul (procent în sutimi sau sumă pe noapte, în bani), valoarea și condițiile: perioada nopților
 * (sejur_de, sejur_pana, inclusiv), zilele dinaintea sosirii (zile_inainte_min = rezervarea timpurie,
 * zile_inainte_max = ultimul moment), numărul minim de nopți, planurile și camerele (null = toate).
 *
 * Suprapunerea o hotărăște gazda, în setarea `reduceri.mod`:
 *   cea_mai_mare  pe fiecare cameră se aplică reducerea care scade cel mai mult prețul sejurului camerei;
 *   cumulate      pe fiecare noapte se adună procentele (cel mult 100%), apoi sumele pe noapte;
 * Setarea pornește goală: nicio scriere nu lasă două reduceri active fără alegerea gazdei (EroareReducere `mod_lipsa`),
 * deci motorul primește alegerea ori de câte ori două reduceri se pot suprapune. Dacă totuși se întâlnesc două fără
 * alegere (o setare pierdută), motorul aplică reducerea cea mai mare și cheamă $faraMod (jurnalul și alerta gazdei),
 * o dată pe instanță: alegerea nu rămâne tăcută.
 *
 * Prețul unei nopți scade cel mult până la zero. Reducerile se calculează în bani cu zecimale; rotunjirea rămâne
 * una pe componentă, în MotorLocal. Taxa locală procentuală se ia din prețul după reducere.
 * Fiecare scriere crește `reduceri.versiune` (cheia totalurilor ținute minte) și golește `totaluri`.
 */
final class ReduceriLocale
{
    public const FELURI = ['procent_sutimi', 'suma_noapte'];
    public const MODURI = ['cea_mai_mare', 'cumulate'];
    /** Peste atâtea reduceri, panoul cere ștergerea uneia (lista rămâne scurtă și ușor de citit). */
    public const MAXIM = 20;
    private const PLAN = '~^plan_(std|[0-9a-f]{16})$~';
    private const CAMERA = '~^cam_[0-9a-f]{16}$~';

    /** @var list<array>|null */
    private ?array $active = null;

    private bool $semnalat = false;

    /** @param (\Closure(int): void)|null $faraMod primește numărul reducerilor active când lipsește alegerea gazdei */
    public function __construct(private Baza $baza, private Setari $setari, private Ceas $ceas, private ?\Closure $faraMod = null)
    {
    }

    /** Două reduceri active fără alegerea gazdei: numai o setare pierdută ajunge aici. */
    public function lipsaMod(): bool
    {
        return $this->mod() === null && count($this->active()) >= 2;
    }

    public function mod(): ?string
    {
        $m = $this->setari->get('reduceri.mod');
        return in_array($m, self::MODURI, true) ? $m : null;
    }

    public function versiune(): int
    {
        return (int) $this->setari->get('reduceri.versiune', 0);
    }

    /** @return list<array> toate reducerile, decodate, în ordinea gazdei */
    public function toate(): array
    {
        return array_map(self::decodeaza(...), $this->baza->toate('SELECT * FROM {reduceri_local} ORDER BY ordine, id'));
    }

    /** @return list<array> reducerile active */
    public function active(): array
    {
        return $this->active ??= array_values(array_filter($this->toate(), static fn (array $r): bool => $r['activa']));
    }

    public function una(int $id): ?array
    {
        $r = $this->baza->rand('SELECT * FROM {reduceri_local} WHERE id = ?', [$id]);
        return $r === null ? null : self::decodeaza($r);
    }

    /**
     * Valori din formularul panoului → rândul validat. Procentul se scrie în procente („10” sau „12,5”), suma în lei.
     * @throws EroareReducere
     */
    public static function valideaza(array $f): array
    {
        $titlu = trim((string) ($f['titlu'] ?? ''));
        if ($titlu === '' || mb_strlen($titlu) > 80) {
            throw new EroareReducere('titlu');
        }
        $fel = (string) ($f['fel'] ?? '');
        if (!in_array($fel, self::FELURI, true)) {
            throw new EroareReducere('fel');
        }
        $v = str_replace(',', '.', trim((string) ($f['valoare'] ?? '')));
        if (!preg_match('~^\d{1,6}(\.\d{1,2})?$~', $v)) {
            throw new EroareReducere('valoare');
        }
        $valoare = (int) round((float) $v * 100);
        if ($valoare < 1 || ($fel === 'procent_sutimi' && $valoare > 10000)) {
            throw new EroareReducere('valoare');
        }
        $zi = static function (mixed $x): ?string {
            $x = trim((string) $x);
            if ($x === '') {
                return null;
            }
            if (!Sejur::zi($x)) {
                throw new EroareReducere('perioada');
            }
            return $x;
        };
        $de = $zi($f['sejur_de'] ?? '');
        $pana = $zi($f['sejur_pana'] ?? '');
        if ($de !== null && $pana !== null && $pana < $de) {
            throw new EroareReducere('perioada');
        }
        $nr = static function (mixed $x, string $cod, int $min, int $max): ?int {
            $x = trim((string) $x);
            if ($x === '') {
                return null;
            }
            if (!preg_match('~^\d{1,3}$~', $x) || (int) $x < $min || (int) $x > $max) {
                throw new EroareReducere($cod);
            }
            return (int) $x;
        };
        $zMin = $nr($f['zile_inainte_min'] ?? '', 'zile_inainte', 0, 730);
        $zMax = $nr($f['zile_inainte_max'] ?? '', 'zile_inainte', 0, 730);
        if ($zMin !== null && $zMax !== null && $zMax < $zMin) {
            throw new EroareReducere('zile_inainte');
        }
        $lista = static function (mixed $x, string $model, string $cod): ?array {
            if ($x === null || $x === '' || $x === []) {
                return null;
            }
            if (!is_array($x) || count($x) > 50) {
                throw new EroareReducere($cod);
            }
            $r = [];
            foreach ($x as $e) {
                if (!is_string($e) || !preg_match($model, $e)) {
                    throw new EroareReducere($cod);
                }
                $r[$e] = true;
            }
            $r = array_keys($r);
            sort($r);
            return $r;
        };
        return [
            'titlu' => $titlu,
            'fel' => $fel,
            'valoare' => $valoare,
            'planuri' => $lista($f['planuri'] ?? null, self::PLAN, 'planuri'),
            'camere' => $lista($f['camere'] ?? null, self::CAMERA, 'camere'),
            'sejur_de' => $de,
            'sejur_pana' => $pana,
            'zile_inainte_min' => $zMin,
            'zile_inainte_max' => $zMax,
            'nopti_min' => $nr($f['nopti_min'] ?? '', 'nopti_min', 1, 365),
            'activa' => !isset($f['activa']) || (bool) $f['activa'],
        ];
    }

    /**
     * Alegerea gazdei se cere când, după scriere, ar rămâne cel puțin două reduceri active și setarea e goală.
     * $id = reducerea scrisă (null = una nouă), $activa = starea ei după scriere.
     */
    public function cereMod(?int $id, bool $activa): bool
    {
        if ($this->mod() !== null) {
            return false;
        }
        $alte = count(array_filter($this->toate(), static fn (array $r): bool => $r['activa'] && $r['id'] !== $id));
        return $alte + ($activa ? 1 : 0) >= 2;
    }

    /**
     * Adaugă ($id null) sau schimbă o reducere; $mod = alegerea gazdei trimisă odată cu formularul.
     * @throws EroareReducere
     */
    public function salveaza(array $formular, ?int $id = null, ?string $mod = null, ?int $utilizator = null): int
    {
        $d = self::valideaza($formular);
        if ($id !== null && $this->una($id) === null) {
            throw new EroareReducere('lipsa');
        }
        if ($id === null && count($this->toate()) >= self::MAXIM) {
            throw new EroareReducere('maxim');
        }
        $this->aplicaMod($mod, $id, $d['activa'], $utilizator);
        $rand = [
            'titlu' => json_encode(['nume' => $d['titlu']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'fel' => $d['fel'],
            'valoare' => $d['valoare'],
            'planuri' => $d['planuri'] !== null ? json_encode($d['planuri'], JSON_THROW_ON_ERROR) : null,
            'camere' => $d['camere'] !== null ? json_encode($d['camere'], JSON_THROW_ON_ERROR) : null,
            'sejur_de' => $d['sejur_de'],
            'sejur_pana' => $d['sejur_pana'],
            'zile_inainte_min' => $d['zile_inainte_min'],
            'zile_inainte_max' => $d['zile_inainte_max'],
            'nopti_min' => $d['nopti_min'],
            'activa' => (int) $d['activa'],
            'actualizat_la' => $this->ceas->moment(),
        ];
        if ($id === null) {
            $ordine = (int) ($this->baza->valoare('SELECT MAX(ordine) FROM {reduceri_local}') ?? 0) + 1;
            $id = $this->baza->insereaza('reduceri_local', $rand + ['ordine' => $ordine, 'creat_la' => $this->ceas->moment()]);
        } else {
            $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($rand)));
            $this->baza->executa("UPDATE {reduceri_local} SET $set WHERE id = ?", [...array_values($rand), $id]);
        }
        $this->schimbat($utilizator);
        return $id;
    }

    /** Pornește sau oprește o reducere; pornirea celei de-a doua cere alegerea gazdei. @throws EroareReducere */
    public function comuta(int $id, bool $activa, ?string $mod = null, ?int $utilizator = null): void
    {
        if ($this->una($id) === null) {
            throw new EroareReducere('lipsa');
        }
        $this->aplicaMod($mod, $id, $activa, $utilizator);
        $this->baza->executa('UPDATE {reduceri_local} SET activa = ?, actualizat_la = ? WHERE id = ?', [(int) $activa, $this->ceas->moment(), $id]);
        $this->schimbat($utilizator);
    }

    public function sterge(int $id, ?int $utilizator = null): void
    {
        $this->baza->executa('DELETE FROM {reduceri_local} WHERE id = ?', [$id]);
        $this->schimbat($utilizator);
    }

    /** Alegerea gazdei pentru reducerile care se suprapun, schimbată oricând din panou. @throws EroareReducere */
    public function seteazaMod(string $mod, ?int $utilizator = null): void
    {
        if (!in_array($mod, self::MODURI, true)) {
            throw new EroareReducere('mod');
        }
        if ($this->mod() !== $mod) {
            $this->setari->pune('reduceri.mod', $mod, $utilizator);
            $this->schimbat($utilizator);
        }
    }

    private function aplicaMod(?string $mod, ?int $id, bool $activa, ?int $utilizator): void
    {
        if ($mod !== null && $mod !== '') {
            if (!in_array($mod, self::MODURI, true)) {
                throw new EroareReducere('mod');
            }
            if ($this->mod() !== $mod) {
                $this->setari->pune('reduceri.mod', $mod, $utilizator);
            }
            return;
        }
        if ($this->cereMod($id, $activa)) {
            throw new EroareReducere('mod_lipsa');
        }
    }

    private function schimbat(?int $utilizator): void
    {
        $this->active = null;
        $this->setari->pune('reduceri.versiune', $this->versiune() + 1, $utilizator);
        if ($this->baza->tabelExista('totaluri')) {
            $this->baza->executa('DELETE FROM {totaluri}');
        }
    }

    // ---- aplicarea ----

    /** Reducerile care se potrivesc unei camere pe un sejur: planul, camera, nopțile minime, zilele dinaintea sosirii. */
    private function potrivite(string $camera, ?string $plan, string $sosire, int $nopti, string $azi, bool $doarNoapte = false): array
    {
        $inainte = (int) floor((strtotime($sosire . ' 00:00:00 UTC') - strtotime($azi . ' 00:00:00 UTC')) / 86400);
        $rez = [];
        foreach ($this->active() as $r) {
            if ($r['planuri'] !== null && ($plan === null || !in_array($plan, $r['planuri'], true))) {
                continue;
            }
            if ($r['camere'] !== null && !in_array($camera, $r['camere'], true)) {
                continue;
            }
            if ($doarNoapte) {
                // Calendarul și „de la”: doar reducerile fără condiții care depind de sejur.
                if ($r['nopti_min'] !== null || $r['zile_inainte_min'] !== null || $r['zile_inainte_max'] !== null) {
                    continue;
                }
            } else {
                if ($r['nopti_min'] !== null && $nopti < $r['nopti_min']) {
                    continue;
                }
                if ($r['zile_inainte_min'] !== null && $inainte < $r['zile_inainte_min']) {
                    continue;
                }
                if ($r['zile_inainte_max'] !== null && $inainte > $r['zile_inainte_max']) {
                    continue;
                }
            }
            $rez[] = $r;
        }
        return $rez;
    }

    /** Reducerea unei reduceri pe o noapte, în bani (cu zecimale), cel mult prețul nopții. */
    private static function peNoapte(array $r, string $zi, int $pret): float
    {
        if (($r['sejur_de'] !== null && $zi < $r['sejur_de']) || ($r['sejur_pana'] !== null && $zi > $r['sejur_pana'])) {
            return 0.0;
        }
        $x = $r['fel'] === 'procent_sutimi' ? $pret * $r['valoare'] / 10000 : (float) $r['valoare'];
        return min((float) $pret, $x);
    }

    /**
     * Aplică reducerile pe nopțile unei camere.
     * @param array<string,int> $preturi [zi => bani], prețurile nopților fără reducere
     * @return array{preturi: array<string,float>, reduceri: list<array{id:int, suma:float}>}
     */
    public function aplica(string $camera, ?string $plan, array $preturi, string $sosire, string $azi): array
    {
        $cand = $this->potrivite($camera, $plan, $sosire, count($preturi), $azi);
        return $this->calculeaza($cand, $preturi);
    }

    /** Prețul unei nopți în calendar și în „de la”, cu reducerile care nu depind de sejur; rotunjit la ban. */
    public function pentruNoapte(string $camera, string $plan, string $zi, int $pret): int
    {
        if ($this->active() === []) {
            return $pret;
        }
        $r = $this->calculeaza($this->potrivite($camera, $plan, $zi, 1, $zi, true), [$zi => $pret]);
        return (int) round(Bani::rot2($r['preturi'][$zi] / 100) * 100);
    }

    private function calculeaza(array $cand, array $preturi): array
    {
        $rez = array_map(static fn (int $p): float => (float) $p, $preturi);
        if ($cand === []) {
            return ['preturi' => $rez, 'reduceri' => []];
        }
        $sume = [];
        foreach ($cand as $r) {
            $s = 0.0;
            foreach ($preturi as $z => $p) {
                $s += self::peNoapte($r, (string) $z, $p);
            }
            if ($s > 0) {
                $sume[$r['id']] = ['r' => $r, 'suma' => $s];
            }
        }
        if ($sume === []) {
            return ['preturi' => $rez, 'reduceri' => []];
        }
        if (count($sume) > 1 && $this->mod() === null && !$this->semnalat) {
            $this->semnalat = true;
            if ($this->faraMod !== null) {
                ($this->faraMod)(count($this->active()));
            }
        }
        if (count($sume) === 1 || $this->mod() !== 'cumulate') {
            // O singură reducere sau „Se aplică doar reducerea cea mai mare”: cea cu suma cea mai mare pe camera
            // întreagă; la egalitate, prima din lista gazdei.
            $ales = null;
            foreach ($sume as $x) {
                if ($ales === null || $x['suma'] > $ales['suma'] + 1e-9) {
                    $ales = $x;
                }
            }
            foreach ($preturi as $z => $p) {
                $rez[$z] = $p - self::peNoapte($ales['r'], (string) $z, $p);
            }
            return ['preturi' => $rez, 'reduceri' => [['id' => $ales['r']['id'], 'suma' => $ales['suma']]]];
        }
        // „Reducerile se cumulează”: pe fiecare noapte, procentele adunate (cel mult 100%), apoi sumele pe noapte.
        $pe = [];
        foreach ($preturi as $z => $p) {
            $procent = 0;
            $suma = 0.0;
            $parti = [];
            foreach ($sume as $id => $x) {
                if (self::peNoapte($x['r'], (string) $z, $p) <= 0) {
                    continue;
                }
                if ($x['r']['fel'] === 'procent_sutimi') {
                    $procent += $x['r']['valoare'];
                    $parti[$id] = ['procent', $x['r']['valoare']];
                } else {
                    $suma += $x['r']['valoare'];
                    $parti[$id] = ['suma', $x['r']['valoare']];
                }
            }
            $brut = $procent;
            $procent = min(10000, $procent);
            $dinProcent = $p * $procent / 10000;
            $total = min((float) $p, $dinProcent + $suma);
            $rez[$z] = $p - $total;
            // Partea fiecărei reduceri (informativ, pentru panou): procentele proporțional, apoi sumele până la preț.
            $ramas = $total;
            foreach ($parti as $id => [$fel, $v]) {
                $x = $fel === 'procent' && $brut > 0 ? $dinProcent * $v / $brut : 0.0;
                $x = min($x, $ramas);
                $ramas -= $x;
                $pe[$id] = ($pe[$id] ?? 0.0) + $x;
            }
            foreach ($parti as $id => [$fel, $v]) {
                if ($fel === 'suma') {
                    $x = min((float) $v, $ramas);
                    $ramas -= $x;
                    $pe[$id] = ($pe[$id] ?? 0.0) + $x;
                }
            }
        }
        $lista = [];
        foreach ($pe as $id => $s) {
            if ($s > 0) {
                $lista[] = ['id' => (int) $id, 'suma' => $s];
            }
        }
        return ['preturi' => $rez, 'reduceri' => $lista];
    }

    private static function decodeaza(array $r): array
    {
        $t = json_decode((string) $r['titlu'], true);
        return [
            'id' => (int) $r['id'],
            'titlu' => is_array($t) ? (string) ($t['nume'] ?? '') : '',
            'fel' => (string) $r['fel'],
            'valoare' => (int) $r['valoare'],
            'planuri' => $r['planuri'] !== null ? (json_decode((string) $r['planuri'], true) ?: null) : null,
            'camere' => $r['camere'] !== null ? (json_decode((string) $r['camere'], true) ?: null) : null,
            'sejur_de' => $r['sejur_de'] !== null ? substr((string) $r['sejur_de'], 0, 10) : null,
            'sejur_pana' => $r['sejur_pana'] !== null ? substr((string) $r['sejur_pana'], 0, 10) : null,
            'zile_inainte_min' => $r['zile_inainte_min'] !== null ? (int) $r['zile_inainte_min'] : null,
            'zile_inainte_max' => $r['zile_inainte_max'] !== null ? (int) $r['zile_inainte_max'] : null,
            'nopti_min' => $r['nopti_min'] !== null ? (int) $r['nopti_min'] : null,
            'activa' => (int) $r['activa'] === 1,
            'ordine' => (int) $r['ordine'],
        ];
    }
}
