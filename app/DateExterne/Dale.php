<?php

declare(strict_types=1);

namespace Cazo\DateExterne;

use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Setari;
use Cazo\Securitate\Limitator;

defined('CAZO') or exit;

/**
 * Dalele hărții din panou (aceleași reguli ca harta din panoul EasyCheck):
 * harta pe care gazda o trage sub acul fix, pe „Proprietatea”. Dalele vin tot prin serverul site-ului, din cache, deci
 * CSP-ul panoului rămâne „self”, iar tile.openstreetmap.org nu primește trafic direct din browser.
 *
 *   - zoomul 6-18, în grila lumii (fără caseta României din EasyCheck: un site Cazo poate fi oriunde);
 *   - o dală din cache se dă fără nicio frână; una nouă trece de frânele pe utilizator (oră, zi), pe IP (oră) și pe site
 *     (oră, zi), cu valori pentru un singur site, unde hărțile le cere doar gazda din panou; frânele numără doar
 *     descărcările reușite (în EasyCheck: restituite la eșec);
 *   - pauza după refuzul OSM (403, 418, 429: 15 minute) și după 3 erori la rând (5xx, fără răspuns: un minut);
 *   - răspunsul primit e o dală doar la 200, cu semnătura PNG și sub 256 KB; scrierea în cache e atomică;
 *   - curățenia zilnică (din sarcina `curatenie`): dalele mai vechi de 90 de zile, temporarele uitate, apoi cele mai vechi,
 *     până sub 90% din plafonul de fișiere.
 *
 * User-Agent-ul numește aplicația (Cazo/<versiune> (+https://cazo.ro)), iar Referer-ul e adresa site-ului, cum cere
 * politica serverelor de dale OSM. Atribuirea „© OpenStreetMap” stă pe hartă.
 */
final class Dale
{
    public const SURSA = 'https://tile.openstreetmap.org/';
    public const Z_MIN = 6;
    public const Z_MAX = 18;
    /** Frânele descărcărilor noi: [cheia, maximul, fereastra în secunde]. {u} = utilizatorul, {ip} = IP-ul trunchiat. */
    public const FRANE = [
        ['harta:dale:u:{u}', 300, 3600],
        ['harta:dale:uz:{u}', 1000, 86400],
        ['harta:dale:ip:{ip}', 300, 3600],
        ['harta:dale:site', 500, 3600],
        ['harta:dale:site:zi', 2000, 86400],
    ];
    public const PAUZA_SEC = 900;
    public const PAUZA_SCURTA_SEC = 60;
    public const ESECURI_PAUZA = 3;
    public const VARSTA_ZILE = 90;
    public const PLAFON_FISIERE = 5000;
    public const MAX_OCTETI = 262144;
    public const TIMP = 4.0;

    /** @param \Closure(): Http $http */
    public function __construct(private string $dir, private Limitator $limitator, private Setari $setari, private Ceas $ceas, private \Closure $http,
        private string $referer = '')
    {
    }

    /** O dală din grila lumii, la zoomul permis. */
    public static function permisa(int $z, int $x, int $y): bool
    {
        if ($z < self::Z_MIN || $z > self::Z_MAX) {
            return false;
        }
        $max = 2 ** $z;
        return $x >= 0 && $y >= 0 && $x < $max && $y < $max;
    }

    /** Un punct pe care harta îl poate centra: numere finite, latitudinea în proiecția hărții, longitudinea în [-180, 180]. */
    public static function coordValida(float $lat, float $lng): bool
    {
        return is_finite($lat) && is_finite($lng) && abs($lat) <= 85.05112878 && abs($lng) <= 180.0;
    }

    public function fisier(int $z, int $x, int $y): string
    {
        return $this->dir . '/' . $z . '/' . $x . '/' . $y . '.png';
    }

    /**
     * Dala cerută din panou.
     * @return array{stare:string, png?:string, dupa?:int}
     *   stare: cache, noua, invalida (în afara grilei sau a zoomului), limita (o frână plină; `dupa` = secundele ferestrei),
     *   pauza (OSM ne-a refuzat sau a dat erori la rând; `dupa` = cât mai ține), eroare
     */
    public function dala(int $z, int $x, int $y, int $utilizator, string $ip): array
    {
        if (!self::permisa($z, $x, $y)) {
            return ['stare' => 'invalida'];
        }
        $fis = $this->fisier($z, $x, $y);
        if (is_file($fis)) {
            $c = @file_get_contents($fis);
            if (is_string($c) && $c !== '') {
                return ['stare' => 'cache', 'png' => $c];
            }
        }
        $pauza = (int) $this->setari->proaspat('harta.dale_pauza_pana', 0) - $this->ceas->acum();
        if ($pauza > 0) {
            return ['stare' => 'pauza', 'dupa' => $pauza];
        }
        $frane = [];
        foreach (self::FRANE as [$cheie, $max, $fereastra]) {
            $cheie = str_replace(['{u}', '{ip}'], [(string) $utilizator, $ip], $cheie);
            if ($this->limitator->numar($cheie, $fereastra) >= $max) {
                $acum = $this->ceas->acum();
                return ['stare' => 'limita', 'dupa' => max(1, (intdiv($acum, $fereastra) + 1) * $fereastra - $acum)];
            }
            $frane[] = [$cheie, $fereastra];
        }
        $png = $this->descarca($z, $x, $y);
        if ($png === null) {
            return ['stare' => 'eroare'];
        }
        foreach ($frane as [$cheie, $fereastra]) {
            $this->limitator->inregistreaza($cheie, $fereastra);
        }
        if (!is_dir(dirname($fis))) {
            @mkdir(dirname($fis), 0700, true);
        }
        $tmp = $fis . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $png) === strlen($png)) {
            if (!@rename($tmp, $fis)) {
                @unlink($tmp);
            }
        } else {
            @unlink($tmp);
        }
        return ['stare' => 'noua', 'png' => $png];
    }

    private function descarca(int $z, int $x, int $y): ?string
    {
        $antete = ['Accept' => 'image/png'];
        if ($this->referer !== '') {
            $antete['Referer'] = $this->referer;
        }
        try {
            $r = ($this->http)()->get(self::SURSA . $z . '/' . $x . '/' . $y . '.png', $antete, self::TIMP);
        } catch (EroareExterna $e) {
            return $this->raspuns(0, '');
        }
        return $this->raspuns($r['status'], $r['corp']);
    }

    /**
     * Soarta unui răspuns al serverului de dale: PNG-ul doar la 200, cu semnătura PNG și sub MAX_OCTETI. La 403, 418 și 429
     * pune pauza lungă și numără refuzul (`harta.dale_refuzuri`); la 5xx sau fără răspuns (0), după ESECURI_PAUZA la rând,
     * pauza scurtă; restul e doar o eroare.
     */
    public function raspuns(int $cod, string $corp): ?string
    {
        if ($cod === 200 && $corp !== '' && strlen($corp) <= self::MAX_OCTETI && str_starts_with($corp, "\x89PNG\r\n\x1a\n")) {
            if ((int) $this->setari->get('harta.dale_esecuri', 0) !== 0) {
                $this->setari->pune('harta.dale_esecuri', 0);
            }
            return $corp;
        }
        if (in_array($cod, [403, 418, 429], true)) {
            $this->setari->pune('harta.dale_pauza_pana', $this->ceas->acum() + self::PAUZA_SEC);
            $this->setari->pune('harta.dale_refuzuri', (int) $this->setari->get('harta.dale_refuzuri', 0) + 1);
            return null;
        }
        if ($cod === 0 || $cod >= 500) {
            $n = (int) $this->setari->get('harta.dale_esecuri', 0) + 1;
            if ($n >= self::ESECURI_PAUZA) {
                $n = 0;
                if ((int) $this->setari->proaspat('harta.dale_pauza_pana', 0) <= $this->ceas->acum()) {
                    $this->setari->pune('harta.dale_pauza_pana', $this->ceas->acum() + self::PAUZA_SCURTA_SEC);
                }
            }
            $this->setari->pune('harta.dale_esecuri', $n);
        }
        return null;
    }

    /**
     * Curățenia cache-ului, o dată pe zi (garda `harta.dale_curatate_zi`): temporarele mai vechi de o oră, dalele mai vechi
     * de VARSTA_ZILE, apoi, peste plafon, cele mai vechi, până la 90% din plafon; folderele goale pleacă.
     * @return array{rulat:bool, sterse:int, fisiere:int}
     */
    public function curata(?int $plafon = null, bool $fortat = false): array
    {
        $plafon ??= self::PLAFON_FISIERE;
        $acum = $this->ceas->acum();
        $zi = gmdate('Y-m-d', $acum);
        if (!$fortat && $this->setari->get('harta.dale_curatate_zi') === $zi) {
            return ['rulat' => false, 'sterse' => 0, 'fisiere' => 0];
        }
        $sterse = 0;
        $ramase = [];
        $prag = $acum - self::VARSTA_ZILE * 86400;
        foreach (self::subdosare($this->dir) as $z) {
            foreach (self::subdosare($this->dir . '/' . $z) as $x) {
                $d = $this->dir . '/' . $z . '/' . $x;
                foreach (scandir($d) ?: [] as $f) {
                    $cale = $d . '/' . $f;
                    if ($f === '.' || $f === '..' || !is_file($cale)) {
                        continue;
                    }
                    $mt = (int) @filemtime($cale);
                    if (str_ends_with($f, '.tmp')) {
                        if ($mt < $acum - 3600 && @unlink($cale)) {
                            $sterse++;
                        }
                        continue;
                    }
                    if (!preg_match('~^\d{1,7}\.png$~', $f)) {
                        continue;
                    }
                    if ($mt < $prag && @unlink($cale)) {
                        $sterse++;
                        continue;
                    }
                    $ramase[$cale] = $mt;
                }
            }
        }
        if (count($ramase) > $plafon) {
            asort($ramase, SORT_NUMERIC);
            $deSters = count($ramase) - (int) floor($plafon * 0.9);
            foreach (array_keys($ramase) as $cale) {
                if ($deSters <= 0) {
                    break;
                }
                if (@unlink($cale)) {
                    unset($ramase[$cale]);
                    $sterse++;
                }
                $deSters--;
            }
        }
        foreach (self::subdosare($this->dir) as $z) {
            foreach (self::subdosare($this->dir . '/' . $z) as $x) {
                @rmdir($this->dir . '/' . $z . '/' . $x);   // reușește doar pe un folder gol
            }
            @rmdir($this->dir . '/' . $z);
        }
        $this->setari->pune('harta.dale_curatate_zi', $zi);
        return ['rulat' => true, 'sterse' => $sterse, 'fisiere' => count($ramase)];
    }

    /** @return list<int> subfolderele numerice (zoomurile sau coloanele), crescător */
    private static function subdosare(string $dir): array
    {
        $r = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $n) {
            if (preg_match('~^\d{1,7}$~', $n) && is_dir($dir . '/' . $n)) {
                $r[] = (int) $n;
            }
        }
        sort($r, SORT_NUMERIC);
        return $r;
    }
}
