<?php

declare(strict_types=1);

namespace Cazo\Local;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Setari;

defined('CAZO') or exit;

/**
 * Datele site-ului fără cheie EasyCheck: proprietatea, regula de rezervare (sejurul
 * minim, avansul, anularea gratuită, o singură setare pe proprietate), camerele cu cele două prețuri, zilele închise de
 * gazdă și ocuparea nopților (rezervările site-ului și calendarele iCal citite).
 *
 * Sumele sunt în bani, ca în restul softului; în panou gazda scrie lei întregi.
 */
final class Depozit
{
    public const PROPRIETATE = 'local.proprietate';
    public const REGULI = 'local.reguli';
    /** Cele două reduceri ale proprietății: sejurul lung și rezervarea din timp. */
    public const REDUCERI = 'local.reduceri';
    public const ID = 'local.proprietate_id';

    /** Felurile proprietății (cheia `tip` din GET /proprietate, aceleași chei ca în EasyCheck). */
    public const TIPURI = ['pensiune', 'apartament', 'vila', 'cabana', 'casa', 'camere', 'hotel', 'hostel'];

    public const SEJUR_MINIM_MAX = 30;
    public const ANULARE_MAX = 90;
    public const UNITATI_MAX = 50;
    public const OASPETI_MAX = 30;
    /** Cel mult 100.000 de lei pe noapte. */
    public const PRET_MAX = 10_000_000;
    public const CAMERE_MAX = 50;
    /** Timpul gazdei pentru răspuns la o cerere cu plata la sosire, în ore. */
    public const TERMEN_CERERE_MIN = 2;
    public const TERMEN_CERERE_MAX = 72;
    public const TERMEN_CERERE_IMPLICIT = 24;
    /** Limitele reducerilor: procentul, nopțile sejurului lung, zilele dinaintea check-in-ului. */
    public const REDUCERE_PROCENT_MAX = 90;
    public const REDUCERE_NOPTI_MIN = 2;
    public const REDUCERE_NOPTI_MAX = 90;
    public const REDUCERE_ZILE_MAX = 365;

    public const REGULI_CASA = ['copii', 'bebelusi', 'animale', 'evenimente', 'fumat'];

    public function __construct(private Baza $baza, private Setari $setari, private Ceas $ceas)
    {
    }

    /** Id-ul proprietății (prop_ + 16 hex), creat o singură dată. */
    public function idProprietate(): string
    {
        $id = $this->setari->get(self::ID);
        if (is_string($id) && preg_match('~^prop_[0-9a-f]{16}$~', $id)) {
            return $id;
        }
        $id = 'prop_' . bin2hex(random_bytes(8));
        $this->setari->pune(self::ID, $id);
        return $id;
    }

    /** Proprietatea completată de gazdă, cu valorile implicite pentru ce lipsește. */
    public function proprietate(): array
    {
        $p = $this->setari->get(self::PROPRIETATE);
        return self::completeaza(is_array($p) ? $p : []);
    }

    public static function completeaza(array $p): array
    {
        $text = static fn (mixed $v, int $max = 191): string => is_string($v) ? mb_substr(trim($v), 0, $max) : '';
        $ora = static fn (mixed $v, ?string $implicit): ?string => is_string($v) && preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $v) ? $v : $implicit;
        $reguli = [];
        foreach (self::REGULI_CASA as $r) {
            $v = $p['reguli_casa'][$r] ?? null;
            $reguli[$r] = is_bool($v) ? $v : null;
        }
        $am = $p['reguli_casa']['animale_max'] ?? null;
        $reguli['animale_max'] = is_int($am) && $am >= 1 && $am <= 20 ? $am : null;
        $c = $p['coordonate'] ?? null;
        $coord = is_array($c) && is_numeric($c['lat'] ?? null) && is_numeric($c['lng'] ?? null)
            && abs((float) $c['lat']) <= 90 && abs((float) $c['lng']) <= 180 ? ['lat' => round((float) $c['lat'], 6), 'lng' => round((float) $c['lng'], 6)] : null;
        return [
            'nume' => $text($p['nume'] ?? ''),
            'tip' => in_array($p['tip'] ?? null, self::TIPURI, true) ? $p['tip'] : 'pensiune',
            'descriere' => ['ro' => $text($p['descriere']['ro'] ?? '', 4000), 'en' => $text($p['descriere']['en'] ?? '', 4000)],
            'adresa' => ['strada' => $text($p['adresa']['strada'] ?? ''), 'localitate' => $text($p['adresa']['localitate'] ?? ''), 'judet' => $text($p['adresa']['judet'] ?? '')],
            'coordonate' => $coord,
            'ore' => ['checkin_de_la' => $ora($p['ore']['checkin_de_la'] ?? null, '14:00'), 'checkin_pana_la' => $ora($p['ore']['checkin_pana_la'] ?? null, null),
                'plecare_pana_la' => $ora($p['ore']['plecare_pana_la'] ?? null, '11:00')],
            'reguli_casa' => $reguli,
            'contact' => ['email' => $text($p['contact']['email'] ?? ''), 'telefon' => $text($p['contact']['telefon'] ?? '', 40)],
            // Păstrate de la trecerea din modul EasyCheck: documentele de clasificare, pozele și restul câmpurilor de
            // prezentare, în forma API-ului.
            'clasificari' => array_values(array_filter((array) ($p['clasificari'] ?? []), 'is_array')),
            'poze' => array_values(array_filter((array) ($p['poze'] ?? []), static fn ($x): bool => is_array($x) && is_string($x['sha256'] ?? null))),
            'linkuri_platforme' => array_values(array_filter((array) ($p['linkuri_platforme'] ?? []), 'is_array')),
            'note_platforme' => is_array($p['note_platforme'] ?? null) ? $p['note_platforme'] : null,
            'spatii_comune' => is_array($p['spatii_comune'] ?? null) ? $p['spatii_comune'] : null,
        ];
    }

    public function salveazaProprietate(array $p, ?int $utilizator = null): void
    {
        $this->setari->pune(self::PROPRIETATE, self::completeaza($p), $utilizator);
        $this->idProprietate();
    }

    /**
     * Regula de rezervare a proprietății: sejurul minim (implicit 1), avansul în procente, zilele de
     * anulare gratuită dinaintea sosirii, aceleași pentru toate camerele, și timpul gazdei pentru răspuns la o cerere cu
     * plata la sosire (2-72 de ore, implicit 24).
     * @return array{sejur_minim:int, avans_procent:int, anulare_zile:int, termen_cerere_ore:int}
     */
    public function reguli(): array
    {
        $r = $this->setari->get(self::REGULI);
        return self::reguliValide(is_array($r) ? $r : []);
    }

    public static function reguliValide(array $r): array
    {
        $int = static fn (mixed $v, int $min, int $max, int $implicit): int => is_int($v) && $v >= $min && $v <= $max ? $v : $implicit;
        return [
            'sejur_minim' => $int($r['sejur_minim'] ?? null, 1, self::SEJUR_MINIM_MAX, 1),
            'avans_procent' => $int($r['avans_procent'] ?? null, 0, 100, 0),
            'anulare_zile' => $int($r['anulare_zile'] ?? null, 0, self::ANULARE_MAX, 7),
            'termen_cerere_ore' => $int($r['termen_cerere_ore'] ?? null, self::TERMEN_CERERE_MIN, self::TERMEN_CERERE_MAX, self::TERMEN_CERERE_IMPLICIT),
        ];
    }

    /** Salvează regula; câmpurile lipsă din $r (de exemplu termenul, la o salvare care nu-l cuprinde) rămân cele salvate. */
    public function salveazaReguli(array $r, ?int $utilizator = null): void
    {
        $this->setari->pune(self::REGULI, self::reguliValide(array_replace($this->reguli(), $r)), $utilizator);
    }

    /**
     * Reducerile proprietății, aceleași pentru toate camerele: „Reducere pentru sejur lung” (procentul de la
     * un număr de nopți) și „Reducere pentru rezervare din timp” (procentul când check-in-ul e la cel puțin atâtea zile).
     * Fiecare e null când e oprită.
     * @return array{lung: ?array{procent:int, nopti:int}, timp: ?array{procent:int, zile:int}}
     */
    public function reduceri(): array
    {
        $r = $this->setari->get(self::REDUCERI);
        return self::reduceriValide(is_array($r) ? $r : []);
    }

    public static function reduceriValide(array $r): array
    {
        $ok = static fn (mixed $v, int $min, int $max): bool => is_int($v) && $v >= $min && $v <= $max;
        $l = $r['lung'] ?? null;
        $t = $r['timp'] ?? null;
        return [
            'lung' => is_array($l) && $ok($l['procent'] ?? null, 1, self::REDUCERE_PROCENT_MAX) && $ok($l['nopti'] ?? null, self::REDUCERE_NOPTI_MIN, self::REDUCERE_NOPTI_MAX)
                ? ['procent' => $l['procent'], 'nopti' => $l['nopti']] : null,
            'timp' => is_array($t) && $ok($t['procent'] ?? null, 1, self::REDUCERE_PROCENT_MAX) && $ok($t['zile'] ?? null, 1, self::REDUCERE_ZILE_MAX)
                ? ['procent' => $t['procent'], 'zile' => $t['zile']] : null,
        ];
    }

    public function salveazaReduceri(array $r, ?int $utilizator = null): void
    {
        $this->setari->pune(self::REDUCERI, self::reduceriValide($r), $utilizator);
    }

    /** Prețul unei nopți după reducerea de $procent, în bani, rotunjit la ban (jumătatea în sus). */
    public static function pretRedus(int $pret, int $procent): int
    {
        return intdiv($pret * (100 - $procent) + 50, 100);
    }

    /** Regula a fost aleasă de gazdă (instalatorul o cere o dată, chiar cu valorile propuse). */
    public function areReguli(): bool
    {
        return is_array($this->setari->get(self::REGULI));
    }

    // ---- camerele ----

    /** @return list<array> camerele, în ordinea gazdei (doar cele active, fără $toate) */
    public function camere(bool $toate = false): array
    {
        $rez = [];
        foreach ($this->baza->toate('SELECT * FROM {local_camere}' . ($toate ? '' : ' WHERE activa = 1') . ' ORDER BY ordine, creat_la, camera') as $r) {
            $rez[] = self::rand($r);
        }
        return $rez;
    }

    public function camera(string $id): ?array
    {
        $r = $this->baza->rand('SELECT * FROM {local_camere} WHERE camera = ?', [$id]);
        return $r === null ? null : self::rand($r);
    }

    private static function rand(array $r): array
    {
        $x = json_decode((string) ($r['extra'] ?? ''), true);
        return ['id' => (string) $r['camera'], 'titlu' => (string) $r['titlu'], 'unitati' => (int) $r['unitati'], 'oaspeti_max' => (int) $r['oaspeti_max'],
            'pret_noapte' => (int) $r['pret_noapte'], 'pret_weekend' => (int) $r['pret_weekend'], 'de_verificat' => (int) $r['de_verificat'] === 1,
            'extra' => is_array($x) ? $x : [], 'ordine' => (int) $r['ordine'], 'activa' => (int) $r['activa'] === 1];
    }

    /**
     * Verifică datele unei camere scrise în panou: numele, unitățile, capacitatea și prețurile în lei întregi.
     * @return array{valori:array, erori:array<string,string>} erorile: câmp => codul erorii
     */
    public static function valideazaCamera(array $v): array
    {
        $erori = [];
        $titlu = trim((string) ($v['titlu'] ?? ''));
        if ($titlu === '') {
            $erori['titlu'] = 'titlu';
        } elseif (mb_strlen($titlu) > 120) {
            $erori['titlu'] = 'lungime';
        }
        $intreg = static function (mixed $x, int $min, int $max): ?int {
            $s = trim(str_replace([' ', '.'], '', (string) $x));
            return preg_match('~^\d{1,7}$~', $s) && (int) $s >= $min && (int) $s <= $max ? (int) $s : null;
        };
        $unitati = $intreg($v['unitati'] ?? '1', 1, self::UNITATI_MAX);
        if ($unitati === null) {
            $erori['unitati'] = 'unitati';
        }
        $oaspeti = $intreg($v['oaspeti_max'] ?? '', 1, self::OASPETI_MAX);
        if ($oaspeti === null) {
            $erori['oaspeti_max'] = 'oaspeti';
        }
        $noapte = $intreg($v['pret_noapte'] ?? '', 1, intdiv(self::PRET_MAX, 100));
        if ($noapte === null) {
            $erori['pret_noapte'] = 'pret';
        }
        // Prețul de weekend gol = același cu prețul pe noapte.
        $w = trim((string) ($v['pret_weekend'] ?? ''));
        $weekend = $w === '' ? $noapte : $intreg($w, 1, intdiv(self::PRET_MAX, 100));
        if ($weekend === null && $w !== '') {
            $erori['pret_weekend'] = 'pret';
        }
        return ['valori' => ['titlu' => mb_substr($titlu, 0, 120), 'unitati' => $unitati ?? 1, 'oaspeti_max' => $oaspeti ?? 2,
            'pret_noapte' => ($noapte ?? 0) * 100, 'pret_weekend' => ($weekend ?? 0) * 100], 'erori' => $erori];
    }

    /** Salvează o cameră (nouă cu $id null) din valorile verificate; întoarce id-ul. Salvarea confirmă și prețurile. */
    public function salveazaCamera(?string $id, array $v, ?array $extra = null): string
    {
        $acum = $this->ceas->moment();
        $date = ['titlu' => (string) $v['titlu'], 'unitati' => (int) $v['unitati'], 'oaspeti_max' => (int) $v['oaspeti_max'],
            'pret_noapte' => (int) $v['pret_noapte'], 'pret_weekend' => (int) $v['pret_weekend'], 'de_verificat' => (int) ($v['de_verificat'] ?? 0), 'actualizat_la' => $acum];
        if ($id !== null && $this->baza->valoare('SELECT 1 FROM {local_camere} WHERE camera = ?', [$id]) !== null) {
            $set = implode(', ', array_map(static fn (string $k): string => "$k = ?", array_keys($date)));
            $this->baza->executa("UPDATE {local_camere} SET $set, activa = 1 WHERE camera = ?", [...array_values($date), $id]);
            if ($extra !== null) {
                $this->baza->executa('UPDATE {local_camere} SET extra = ? WHERE camera = ?', [json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
            }
            return $id;
        }
        if ($id === null || !preg_match('~^cam_[0-9a-f]{16}$~', $id)) {
            $id = 'cam_' . bin2hex(random_bytes(8));
        }
        $ordine = (int) $this->baza->valoare('SELECT COALESCE(MAX(ordine), 0) FROM {local_camere}') + 1;
        $this->baza->insereaza('local_camere', $date + ['camera' => $id, 'extra' => $extra !== null ? json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ordine' => $ordine, 'activa' => 1, 'creat_la' => $acum]);
        return $id;
    }

    /**
     * Retrage o cameră de pe site (rămâne în bază pentru rezervările ei). Refuză când camera are rezervări viitoare
     * (false), ca o rezervare plătită să nu rămână fără cameră.
     */
    public function retrageCamera(string $id, string $azi): bool
    {
        foreach ($this->baza->toate("SELECT camere FROM {local_rezervari} WHERE plecare > ? AND stare <> 'anulata'", [$azi]) as $r) {
            foreach ((array) json_decode((string) $r['camere'], true) as $c) {
                if (is_array($c) && ($c['camera'] ?? null) === $id) {
                    return false;
                }
            }
        }
        $this->baza->executa('UPDATE {local_camere} SET activa = 0, actualizat_la = ? WHERE camera = ?', [$this->ceas->moment(), $id]);
        return true;
    }

    /** Camerele cu prețurile precompletate la trecerea din modul EasyCheck, încă neconfirmate de gazdă. @return list<string> */
    public function deVerificat(): array
    {
        return array_map('strval', array_column($this->baza->toate('SELECT camera FROM {local_camere} WHERE activa = 1 AND de_verificat = 1 ORDER BY ordine'), 'camera'));
    }

    // ---- zilele închise ----

    /** Închide sau deschide zilele [de, pana] (inclusiv) pentru o cameră sau pentru toate („*”). Întoarce câte zile. */
    public function inchide(string $camera, string $de, string $pana, bool $inchis): int
    {
        $zile = self::zile($de, $pana, true);
        if ($zile === [] || count($zile) > 366) {
            return 0;
        }
        $this->baza->tranzactie(function () use ($camera, $zile, $inchis): void {
            foreach ($zile as $zi) {
                $this->baza->executa('DELETE FROM {local_inchise} WHERE camera = ? AND zi = ?', [$camera, $zi]);
                if ($inchis) {
                    $this->baza->insereaza('local_inchise', ['camera' => $camera, 'zi' => $zi]);
                }
            }
        });
        return count($zile);
    }

    /** Zilele închise de acum încolo, grupate în perioade, pentru panou. @return list<array{camera:string, de:string, pana:string}> */
    public function perioadeInchise(string $deLa): array
    {
        $rez = [];
        $curent = null;
        foreach ($this->baza->toate('SELECT camera, zi FROM {local_inchise} WHERE zi >= ? ORDER BY camera, zi', [$deLa]) as $r) {
            $zi = (string) $r['zi'];
            $cam = (string) $r['camera'];
            if ($curent !== null && $curent['camera'] === $cam && self::ziUrmatoare($curent['pana']) === $zi) {
                $curent['pana'] = $zi;
                continue;
            }
            if ($curent !== null) {
                $rez[] = $curent;
            }
            $curent = ['camera' => $cam, 'de' => $zi, 'pana' => $zi];
        }
        if ($curent !== null) {
            $rez[] = $curent;
        }
        return $rez;
    }

    /** @return array<string, array<string,bool>> camera („*” inclus) => [zi => true], în [de, pana) */
    public function inchise(string $de, string $pana): array
    {
        $r = [];
        foreach ($this->baza->toate('SELECT camera, zi FROM {local_inchise} WHERE zi >= ? AND zi < ?', [$de, $pana]) as $x) {
            $r[(string) $x['camera']][(string) $x['zi']] = true;
        }
        return $r;
    }

    // ---- ocuparea ----

    /**
     * Câte unități ocupă, pe fiecare noapte din [de, pana), rezervările site-ului (confirmate și cele care așteaptă
     * transferul, cu termenul încă în curs) și calendarele iCal (fiecare eveniment ocupă o unitate a camerei lui).
     * @return array{site: array<string, array<string,int>>, ical: array<string, array<string,int>>}
     */
    public function ocupare(string $de, string $pana, ?string $exceptand = null): array
    {
        $site = [];
        $acum = gmdate('Y-m-d\TH:i:s\Z', $this->ceas->acum());
        foreach ($this->baza->toate("SELECT id, sosire, plecare, camere, stare, plata_asteptata FROM {local_rezervari} WHERE stare <> 'anulata' AND plecare > ? AND sosire < ?", [$de, $pana]) as $r) {
            if ($exceptand !== null && $r['id'] === $exceptand) {
                continue;
            }
            if (!self::tineNoptile($r, $acum)) {
                continue;
            }
            foreach (self::zile(max($de, (string) $r['sosire']), min($pana, (string) $r['plecare'])) as $zi) {
                foreach ((array) json_decode((string) $r['camere'], true) as $c) {
                    if (is_array($c) && is_string($c['camera'] ?? null)) {
                        $site[$c['camera']][$zi] = ($site[$c['camera']][$zi] ?? 0) + max(1, (int) ($c['unitati'] ?? 1));
                    }
                }
            }
        }
        $ical = [];
        foreach ($this->baza->toate('SELECT n.camera, n.zi, n.numar FROM {local_ical_nopti} n JOIN {local_ical_surse} s ON s.id = n.sursa_id WHERE s.activa = 1 AND n.zi >= ? AND n.zi < ?', [$de, $pana]) as $r) {
            $ical[(string) $r['camera']][(string) $r['zi']] = ($ical[(string) $r['camera']][(string) $r['zi']] ?? 0) + (int) $r['numar'];
        }
        return ['site' => $site, 'ical' => $ical];
    }

    /**
     * O rezervare locală ține nopțile: orice stare în afară de anulată; cea care așteaptă transferul, doar cât plata e
     * așteptată și termenul e încă în curs.
     */
    public static function tineNoptile(array $r, string $acumIso): bool
    {
        if (($r['stare'] ?? '') === 'anulata') {
            return false;
        }
        if (($r['stare'] ?? '') === 'in_asteptarea_platii') {
            $pa = json_decode((string) ($r['plata_asteptata'] ?? ''), true);
            $termen = is_array($pa) ? ($pa['termen'] ?? null) : null;
            if (!is_array($pa) || ($pa['stare'] ?? null) !== 'in_asteptare' || (is_string($termen) && $termen <= $acumIso)) {
                return false;
            }
        }
        return true;
    }

    // ---- unitățile ----

    /**
     * Repartizează rezervările locale încă în picioare pe unitățile camerelor (calendarul iCal pe unitate). Alocarea e
     * stabilă: o rezervare care are deja unitatea ei o păstrează; o rezervare nouă primește prima unitate liberă pe toată
     * perioada (cea cu numărul cel mai mic); fără una liberă pe toată perioada (o plată cu cardul primită peste nopți
     * pline, o suprapunere), unitatea cu cele mai puține nopți în comun, apoi cea cu numărul cel mai mic. Rândurile
     * rezervărilor anulate, expirate, mutate pe alte date sau în altă cameră se scot (cele mutate primesc altă unitate),
     * iar o unitate peste numărul de acum al camerei se alocă din nou.
     */
    public function repartizeaza(string $azi): void
    {
        $acum = gmdate('Y-m-d\TH:i:s\Z', $this->ceas->acum());
        $unitati = [];
        foreach ($this->baza->toate('SELECT camera, unitati FROM {local_camere}') as $c) {
            $unitati[(string) $c['camera']] = max(1, (int) $c['unitati']);
        }
        $this->baza->tranzactie(function () use ($azi, $acum, $unitati): void {
            $this->baza->executa('DELETE FROM {local_unitati} WHERE plecare <= ?', [$azi]);
            $rez = [];
            foreach ($this->baza->toate('SELECT id, sosire, plecare, camere, stare, plata_asteptata FROM {local_rezervari} WHERE plecare > ? ORDER BY creat_la, id', [$azi]) as $r) {
                if (self::tineNoptile($r, $acum)) {
                    $rez[(string) $r['id']] = $r;
                }
            }
            // Rândurile de acum, păstrate doar când se potrivesc încă rezervării (camera, datele, numărul unităților).
            $ocupat = [];
            $alocate = [];
            foreach ($this->baza->toate('SELECT * FROM {local_unitati} ORDER BY rezervare, poz, unitate') as $x) {
                $r = $rez[(string) $x['rezervare']] ?? null;
                $intrari = $r === null ? [] : array_values(array_filter((array) json_decode((string) $r['camere'], true), 'is_array'));
                $intrare = $intrari[(int) $x['poz']] ?? null;
                $cam = (string) $x['camera'];
                $valid = $intrare !== null && ($intrare['camera'] ?? null) === $cam && $x['sosire'] === $r['sosire'] && $x['plecare'] === $r['plecare']
                    && (int) $x['unitate'] >= 1 && (int) $x['unitate'] <= ($unitati[$cam] ?? 1)
                    && count($alocate[$x['rezervare']][(int) $x['poz']] ?? []) < max(1, (int) ($intrare['unitati'] ?? 1))
                    && !isset($alocate[$x['rezervare']][(int) $x['poz']][(int) $x['unitate']]);
                if (!$valid) {
                    $this->baza->executa('DELETE FROM {local_unitati} WHERE rezervare = ? AND poz = ? AND unitate = ?', [$x['rezervare'], $x['poz'], $x['unitate']]);
                    continue;
                }
                $alocate[$x['rezervare']][(int) $x['poz']][(int) $x['unitate']] = true;
                foreach (self::zile((string) $x['sosire'], (string) $x['plecare']) as $zi) {
                    $ocupat[$cam][(int) $x['unitate']][$zi] = true;
                }
            }
            foreach ($rez as $id => $r) {
                $zile = self::zile((string) $r['sosire'], (string) $r['plecare']);
                foreach (array_values(array_filter((array) json_decode((string) $r['camere'], true), 'is_array')) as $poz => $intrare) {
                    $cam = (string) ($intrare['camera'] ?? '');
                    if ($cam === '') {
                        continue;
                    }
                    $total = $unitati[$cam] ?? 1;
                    $lipsa = max(1, (int) ($intrare['unitati'] ?? 1)) - count($alocate[$id][$poz] ?? []);
                    for (; $lipsa > 0; $lipsa--) {
                        $ales = null;
                        $minim = PHP_INT_MAX;
                        for ($u = 1; $u <= $total; $u++) {
                            if (isset($alocate[$id][$poz][$u])) {
                                continue;
                            }
                            $comune = 0;
                            foreach ($zile as $zi) {
                                $comune += isset($ocupat[$cam][$u][$zi]) ? 1 : 0;
                            }
                            if ($comune < $minim) {
                                [$ales, $minim] = [$u, $comune];
                            }
                            if ($comune === 0) {
                                break;
                            }
                        }
                        if ($ales === null) {
                            break;
                        }
                        $this->baza->insereaza('local_unitati', ['rezervare' => $id, 'poz' => $poz, 'unitate' => $ales, 'camera' => $cam, 'sosire' => $r['sosire'],
                            'plecare' => $r['plecare'], 'creat_la' => $this->ceas->moment()]);
                        $alocate[$id][$poz][$ales] = true;
                        foreach ($zile as $zi) {
                            $ocupat[$cam][$ales][$zi] = true;
                        }
                    }
                }
            }
        });
    }

    /** Unitățile ocupate ale fiecărei rezervări, după repartizare: rezervarea => [poziția => [unitățile]]. @return array<string, array<int, list<int>>> */
    public function unitatiRezervari(): array
    {
        $rez = [];
        foreach ($this->baza->toate('SELECT rezervare, poz, unitate FROM {local_unitati} ORDER BY rezervare, poz, unitate') as $x) {
            $rez[(string) $x['rezervare']][(int) $x['poz']][] = (int) $x['unitate'];
        }
        return $rez;
    }

    /** Nopțile din [de, pana) ocupate de rezervările repartizate pe o unitate. @return array<string,true> */
    public function noptiUnitate(string $camera, int $unitate, string $de, string $pana): array
    {
        $nopti = [];
        $acum = gmdate('Y-m-d\TH:i:s\Z', $this->ceas->acum());
        $sql = 'SELECT u.sosire, u.plecare, r.stare, r.plata_asteptata FROM {local_unitati} u JOIN {local_rezervari} r ON r.id = u.rezervare'
            . ' WHERE u.camera = ? AND u.unitate = ? AND u.plecare > ? AND u.sosire < ?';
        foreach ($this->baza->toate($sql, [$camera, $unitate, $de, $pana]) as $x) {
            // Un transfer expirat de la ultima scriere nu mai ține nopțile, chiar înainte de repartizarea următoare.
            if (!self::tineNoptile($x, $acum)) {
                continue;
            }
            foreach (self::zile(max($de, (string) $x['sosire']), min($pana, (string) $x['plecare'])) as $zi) {
                $nopti[$zi] = true;
            }
        }
        return $nopti;
    }

    /**
     * Unitățile libere ale camerelor pe fiecare noapte din [de, pana) și zilele închise.
     * @return array<string, array<string, array{libere:int, inchis:bool}>>
     */
    public function disponibilitate(string $de, string $pana, ?string $exceptand = null): array
    {
        $o = $this->ocupare($de, $pana, $exceptand);
        $inchise = $this->inchise($de, $pana);
        $rez = [];
        foreach ($this->camere() as $c) {
            foreach (self::zile($de, $pana) as $zi) {
                $ocupat = ($o['site'][$c['id']][$zi] ?? 0) + ($o['ical'][$c['id']][$zi] ?? 0);
                $rez[$c['id']][$zi] = ['libere' => max(0, $c['unitati'] - $ocupat), 'inchis' => isset($inchise['*'][$zi]) || isset($inchise[$c['id']][$zi])];
            }
        }
        return $rez;
    }

    /**
     * Nopțile ocupate (fără unitate liberă) sau închise ale unui sejur cu camerele date: lista lor, goală când sejurul
     * încape. Mai multe unități din aceeași cameră se numără împreună.
     * @param list<array{camera:string}> $camere
     * @return list<string>
     */
    public function noptiPline(string $sosire, string $plecare, array $camere, ?string $exceptand = null): array
    {
        $d = $this->disponibilitate($sosire, $plecare, $exceptand);
        $cerute = [];
        foreach ($camere as $c) {
            $cerute[(string) ($c['camera'] ?? '')] = ($cerute[(string) ($c['camera'] ?? '')] ?? 0) + 1;
        }
        $pline = [];
        foreach (self::zile($sosire, $plecare) as $zi) {
            foreach ($cerute as $cam => $n) {
                $x = $d[$cam][$zi] ?? null;
                if ($x === null || $x['inchis'] || $x['libere'] < $n) {
                    $pline[$zi] = true;
                }
            }
        }
        return array_keys($pline);
    }

    // ---- zilele ----

    /** Zilele din [de, pana) sau, cu $inclusiv, [de, pana]. @return list<string> */
    public static function zile(string $de, string $pana, bool $inclusiv = false): array
    {
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $de) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $pana)) {
            return [];
        }
        $rez = [];
        $t = strtotime($de . ' 00:00:00 UTC');
        $sf = strtotime($pana . ' 00:00:00 UTC');
        if ($t === false || $sf === false) {
            return [];
        }
        for ($i = 0; ($inclusiv ? $t <= $sf : $t < $sf) && $i < 800; $t += 86400, $i++) {
            $rez[] = gmdate('Y-m-d', $t);
        }
        return $rez;
    }

    public static function ziUrmatoare(string $zi): string
    {
        return gmdate('Y-m-d', strtotime($zi . ' 00:00:00 UTC') + 86400);
    }

    /** Noaptea de vineri sau de sâmbătă: noaptea care începe în acea zi. */
    public static function weekend(string $zi): bool
    {
        return in_array((int) gmdate('N', strtotime($zi . ' 00:00:00 UTC')), [5, 6], true);
    }

    /** Prețul unei nopți în bani: prețul de weekend vinerea și sâmbăta, altfel prețul pe noapte; null fără preț. */
    public static function pretNoapte(array $camera, string $zi): ?int
    {
        $p = self::weekend($zi) ? ($camera['pret_weekend'] > 0 ? $camera['pret_weekend'] : $camera['pret_noapte']) : $camera['pret_noapte'];
        return $p > 0 ? $p : null;
    }
}
