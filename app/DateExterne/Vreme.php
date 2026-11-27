<?php

declare(strict_types=1);

namespace Cazo\DateExterne;

use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Setari;

defined('CAZO') or exit;

/**
 * Vremea: MET Norway Locationforecast 2.0, citită pe server
 * de jobul `date_externe` și doar când tema activă o afișează; browserul oaspetelui rămâne fără cereri externe.
 *
 * Condițiile MET Norway (api.met.no, citite pe 16.11.2026): gratuit și pentru folosire comercială, fără cheie; datele
 * sub NLOD 2.0 și CC BY 4.0, cu sursa „MET Norway” afișată lângă date și legătura spre licență. Cererile au un
 * User-Agent cu numele aplicației și adresa site-ului (Aplicatie::httpExtern), coordonatele cu cel mult 4 zecimale, nu se
 * repetă înainte de ora din antetul Expires, folosesc If-Modified-Since cu Last-Modified-ul anterior (304 păstrează
 * datele), gzip și redirecturile; cererile programate se împrăștie (o deplasare fixă pe site, nu minute fixe).
 * Peste toate, cel mult o citire pe oră.
 *
 * Din prognoză se păstrează: temperatura de acum, starea cerului (simbolul MET grupat în cele 8 stări afișate) și 3
 * zile, în ora României, cu minima și maxima (din temperaturile orare și din minimele și maximele pe 6 ore) și starea
 * cerului de la prânz. Datele mai vechi de 3 ore se ascund; o citire eșuată păstrează ultimele date bune.
 */
final class Vreme
{
    public const NUME = 'vreme';
    public const INTERVAL = 3600;
    public const VECHE = 3 * 3600;
    public const ADRESA = 'https://api.met.no/weatherapi/locationforecast/2.0/compact';
    /** Atribuirea cerută de licență (CC BY 4.0, NLOD); un nume propriu, la fel în toate limbile. */
    public const SURSA = 'MET Norway (CC BY 4.0)';
    public const SURSA_URL = 'https://creativecommons.org/licenses/by/4.0/';
    /** Împrăștierea cererilor programate: o deplasare fixă pe site, de până la 10 minute. */
    public const IMPRASTIERE = 600;
    public const FUS = 'Europe/Bucharest';

    /** Stările afișate (vreme.stare.* în cataloage). */
    public const STARI = ['senin', 'partial_noros', 'noros', 'ceata', 'burnita', 'ploaie', 'ninsoare', 'furtuna'];

    /** Simbolurile MET (fără sufixele _day, _night, _polartwilight), grupate în stările afișate. */
    public const SIMBOLURI = [
        'clearsky' => 'senin', 'fair' => 'partial_noros', 'partlycloudy' => 'partial_noros', 'cloudy' => 'noros', 'fog' => 'ceata',
        'lightrain' => 'burnita', 'lightrainshowers' => 'burnita',
        'rain' => 'ploaie', 'heavyrain' => 'ploaie', 'rainshowers' => 'ploaie', 'heavyrainshowers' => 'ploaie',
        'lightsleet' => 'ploaie', 'sleet' => 'ploaie', 'heavysleet' => 'ploaie', 'lightsleetshowers' => 'ploaie', 'sleetshowers' => 'ploaie', 'heavysleetshowers' => 'ploaie',
        'lightsnow' => 'ninsoare', 'snow' => 'ninsoare', 'heavysnow' => 'ninsoare', 'lightsnowshowers' => 'ninsoare', 'snowshowers' => 'ninsoare', 'heavysnowshowers' => 'ninsoare',
    ];

    /** @param string $site originea site-ului: de ea ține deplasarea fixă a cererilor (împrăștierea) */
    public function __construct(private Depozit $depozit, private Setari $setari, private Ceas $ceas, private \Closure $http, private string $site)
    {
    }

    /** Starea afișată pentru un simbol MET (cu sau fără sufix); orice simbol cu „thunder” e furtună; necunoscut, înnorat. */
    public static function stare(string $simbol): string
    {
        $s = strtolower((string) preg_replace('~_(day|night|polartwilight)$~', '', $simbol));
        if (str_contains($s, 'thunder')) {
            return 'furtuna';
        }
        return self::SIMBOLURI[$s] ?? 'noros';
    }

    /** Gazda afișează vremea (pasul 7 al configurării, implicit da). */
    public function pornita(): bool
    {
        return (bool) $this->setari->get('vreme.afisata', true);
    }

    /** Deplasarea fixă a site-ului, în secunde (0 … IMPRASTIERE-1). */
    public function deplasare(): int
    {
        return crc32($this->site) % self::IMPRASTIERE;
    }

    /**
     * Citirea e scadentă: a trecut ora de la ultima încercare (reușită sau nu), plus deplasarea site-ului, și ora din
     * antetul Expires al ultimului răspuns.
     */
    public function scadenta(): bool
    {
        $acum = $this->ceas->acum();
        $d = $this->depozit->citeste(self::NUME);
        $ultima = null;
        foreach ([$d['citit_la'] ?? null, $d['eroare_la'] ?? null] as $x) {
            if ($x !== null) {
                $ultima = max($ultima ?? 0, Ceas::dinMoment($x));
            }
        }
        if ($ultima !== null && $acum < $ultima + self::INTERVAL + $this->deplasare()) {
            return false;
        }
        $expira = $this->setari->get('vreme.http', [])['expira'] ?? null;
        return !is_int($expira) || $acum >= $expira;
    }

    /** Coordonata cu 4 zecimale (cerința MET; cu 5 sau mai multe, 403). */
    public static function coordonata(float $x): string
    {
        return sprintf('%.4F', $x);
    }

    /** Citește vremea pentru coordonate; întoarce true când datele afișate s-au schimbat. */
    public function citeste(float $lat, float $lng): bool
    {
        $coord = self::coordonata($lat) . ',' . self::coordonata($lng);
        $url = self::ADRESA . '?' . http_build_query(['lat' => self::coordonata($lat), 'lon' => self::coordonata($lng)]);
        $meta = $this->setari->get('vreme.http', []);
        $meta = is_array($meta) ? $meta : [];
        $inainte = $this->depozit->citeste(self::NUME)['corp'] ?? null;
        $antete = ['Accept' => 'application/json'];
        // If-Modified-Since doar pentru aceleași coordonate și cu datele de atunci încă în depozit.
        if ($inainte !== null && ($meta['coord'] ?? null) === $coord && is_string($meta['ultima_modificare'] ?? null)) {
            $antete['If-Modified-Since'] = $meta['ultima_modificare'];
        }
        try {
            $r = ($this->http)()->get($url, $antete, 10.0);
            $expira = self::moment($r['antete']['expires'] ?? null);
            if ($r['status'] === 304 && isset($antete['If-Modified-Since'])) {
                $this->setari->pune('vreme.http', ['expira' => $expira, 'ultima_modificare' => $meta['ultima_modificare'], 'coord' => $coord]);
                $this->depozit->scrie(self::NUME, $inainte);
                return false;
            }
            if ($r['status'] === 403) {
                throw new EroareExterna('MET Norway a refuzat cererea (403): identificarea aplicației (User-Agent) sau coordonatele.');
            }
            if ($r['status'] === 429) {
                throw new EroareExterna('MET Norway cere mai puține cereri (429).');
            }
            if ($r['status'] !== 200) {
                throw new EroareExterna('MET Norway a răspuns ' . $r['status'] . '.');
            }
            $corp = self::interpreteaza(json_decode($r['corp'], true), $this->ceas->acum());
        } catch (EroareExterna $e) {
            $this->depozit->eroare(self::NUME, $e->getMessage());
            return false;
        }
        $this->setari->pune('vreme.http', ['expira' => $expira, 'ultima_modificare' => is_string($r['antete']['last-modified'] ?? null) ? $r['antete']['last-modified'] : null, 'coord' => $coord]);
        $this->depozit->scrie(self::NUME, $corp);
        return $inainte === null || self::afisat($inainte) !== self::afisat($corp);
    }

    /** Un antet de dată HTTP (Expires) ca moment Unix, sau null. */
    private static function moment(mixed $antet): ?int
    {
        if (!is_string($antet) || $antet === '') {
            return null;
        }
        $t = strtotime($antet);
        return $t === false ? null : $t;
    }

    /** Ce se vede pe pagină (fără momentul citirii), ca să se schimbe cache-ul de pagini doar la o schimbare reală. */
    private static function afisat(array $c): array
    {
        return [$c['temperatura'] ?? null, $c['stare'] ?? null, $c['zile'] ?? null];
    }

    /**
     * Răspunsul compact Locationforecast: temperatura și cerul din pasul cel mai apropiat de acum, apoi azi și
     * următoarele 2 zile, în ora României.
     * @throws EroareExterna
     */
    public static function interpreteaza(mixed $j, int $acum): array
    {
        $ts = is_array($j) ? ($j['properties']['timeseries'] ?? null) : null;
        if (!is_array($ts) || $ts === []) {
            throw new EroareExterna('Forma răspunsului MET Norway e alta.');
        }
        $fus = new \DateTimeZone(self::FUS);
        $pasi = [];
        foreach ($ts as $p) {
            $t = is_array($p) && is_string($p['time'] ?? null) ? strtotime($p['time']) : false;
            $temp = is_array($p) ? ($p['data']['instant']['details']['air_temperature'] ?? null) : null;
            if ($t === false || !is_numeric($temp)) {
                throw new EroareExterna('Pașii din răspunsul MET Norway au altă formă.');
            }
            $ora6 = $p['data']['next_6_hours'] ?? null;
            $pasi[] = [
                't' => $t,
                'local' => (new \DateTimeImmutable('@' . $t))->setTimezone($fus),
                'temp' => (float) $temp,
                'simbol1' => is_string($p['data']['next_1_hours']['summary']['symbol_code'] ?? null) ? $p['data']['next_1_hours']['summary']['symbol_code'] : null,
                'simbol6' => is_string($ora6['summary']['symbol_code'] ?? null) ? $ora6['summary']['symbol_code'] : null,
                'min6' => is_numeric($ora6['details']['air_temperature_min'] ?? null) ? (float) $ora6['details']['air_temperature_min'] : null,
                'max6' => is_numeric($ora6['details']['air_temperature_max'] ?? null) ? (float) $ora6['details']['air_temperature_max'] : null,
            ];
        }
        // Acum: pasul cel mai apropiat, cu cerul din ora următoare (altfel din următoarele 6 ore).
        usort($pasi, static fn (array $a, array $b): int => $a['t'] <=> $b['t']);
        $curent = $pasi[0];
        foreach ($pasi as $p) {
            if (abs($p['t'] - $acum) < abs($curent['t'] - $acum)) {
                $curent = $p;
            }
        }
        $simbol = $curent['simbol1'] ?? $curent['simbol6'];
        if ($simbol === null) {
            throw new EroareExterna('Răspunsul MET Norway e fără starea cerului.');
        }
        // Zilele: azi și următoarele 2, în ora României.
        $azi = (new \DateTimeImmutable('@' . $acum))->setTimezone($fus);
        $zile = [];
        for ($i = 0; $i < 3; $i++) {
            $zi = $azi->modify('+' . $i . ' days')->format('Y-m-d');
            $valori = [];
            $prinz = null;
            foreach ($pasi as $p) {
                if ($p['local']->format('Y-m-d') !== $zi) {
                    continue;
                }
                $valori[] = $p['temp'];
                foreach (['min6', 'max6'] as $k) {
                    if ($p[$k] !== null) {
                        $valori[] = $p[$k];
                    }
                }
                // Cerul zilei: perioada de 6 ore care începe cel mai aproape de prânz.
                if ($p['simbol6'] !== null && ($prinz === null || abs((int) $p['local']->format('G') - 12) < abs((int) $prinz['local']->format('G') - 12))) {
                    $prinz = $p;
                }
            }
            if ($valori === []) {
                break;
            }
            $zile[] = ['zi' => $zi, 'stare' => self::stare(($prinz ?? $curent)['simbol6'] ?? $simbol), 'min' => (int) round(min($valori)), 'max' => (int) round(max($valori))];
        }
        if ($zile === []) {
            throw new EroareExterna('Răspunsul MET Norway e fără zilele cerute.');
        }
        return ['temperatura' => (int) round($curent['temp']), 'stare' => self::stare($simbol), 'zile' => $zile];
    }

    /** Datele afișabile: ultimul corp bun, cât are cel mult 3 ore; altfel null (componenta se ascunde). */
    public function date(): ?array
    {
        if (!$this->pornita()) {
            return null;
        }
        $v = $this->depozit->varsta(self::NUME);
        $corp = $this->depozit->citeste(self::NUME)['corp'] ?? null;
        // Datele vechi, de la Open-Meteo (cu codul WMO, fără stare), nu se mai afișează: citirea următoare le înlocuiește.
        if ($v === null || $v > self::VECHE || !is_array($corp) || !in_array($corp['stare'] ?? null, self::STARI, true)) {
            return null;
        }
        return $corp;
    }
}
