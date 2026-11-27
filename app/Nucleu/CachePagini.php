<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

use Cazo\Joburi\PseudoCron;

defined('CAZO') or exit;

/**
 * Cache-ul de pagini întregi, servit înainte de aplicație pentru vizitatorii fără cookie de
 * comandă sau de panou. Încărcătorul (public_html/index.php) include app/cache-pagini.php din lansarea curentă,
 * deci regulile de aici se schimbă odată cu versiunea, iar încărcătorul rămâne același.
 *
 * Cheia: versiunea aplicației, generația conținutului, limba și adresa cerută (REQUEST_URI).
 * Generația ține versiunea din oglinda EasyCheck și versiunea setărilor temei; modulele care le schimbă
 * cheamă schimbaGeneratia(), iar paginile vechi nu se mai potrivesc. După o actualizare,
 * versiunea nouă a aplicației dă alte chei, deci paginile versiunii vechi rămân nefolosite.
 *
 * Formatul fișierului: prima linie, antetele în JSON; restul, corpul.
 */
final class CachePagini
{
    /** Cookie-urile care ocolesc cache-ul (comanda în curs, panoul). */
    public const COOKIE_OCOLIRE = ['__Host-pg_comanda', '__Host-pg_admin'];

    public const FISIER_GENERATIE = 'cache/pagini/generatie';

    public const FISIER_PSEUDO_CRON = 'cache/pseudo-cron';

    public static function versiune(): string
    {
        return (string) require dirname(__DIR__) . '/versiune.php';
    }

    /** Generația curentă a conținutului; „0” cât nimeni nu a scris-o. */
    public static function generatie(string $privat): string
    {
        $g = @file_get_contents($privat . '/' . self::FISIER_GENERATIE);
        $g = is_string($g) ? trim($g) : '';
        return preg_match('~^[0-9A-Za-z.\-]{1,64}$~', $g) ? $g : '0';
    }

    /** Schimbă generația (versiunea din oglindă și versiunea setărilor temei), atomic. */
    public static function schimbaGeneratia(string $privat, string $versiuneOglinda, string $versiuneTema): void
    {
        $g = $versiuneOglinda . '.' . $versiuneTema;
        if (!preg_match('~^[0-9A-Za-z.\-]{1,64}$~', $g)) {
            throw new \InvalidArgumentException('Generație de cache invalidă.');
        }
        $f = $privat . '/' . self::FISIER_GENERATIE;
        if (!is_dir(dirname($f))) {
            mkdir(dirname($f), 0700, true);
        }
        $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $g, LOCK_EX);
        \Cazo\Nucleu\Functii::chmod($tmp, 0600);
        rename($tmp, $f);
    }

    public static function cheie(string $versiune, string $generatie, string $limba, string $uri): string
    {
        return hash('sha256', $versiune . "\n" . $generatie . "\n" . $limba . "\n" . $uri);
    }

    /** Fișierul paginii pentru o adresă, cu versiunea și generația curente. */
    public static function fisier(string $privat, string $limba, string $uri): string
    {
        return $privat . '/cache/pagini/' . $limba . '/' . self::cheie(self::versiune(), self::generatie($privat), $limba, $uri) . '.html';
    }

    /** Limba după calea logică (aceleași trei forme ca în Cerere: /en/..., /?r=/en/..., /index.php/en/...). */
    public static function limba(string $uri, array $get): string
    {
        $cale = (string) parse_url('http://x' . (str_starts_with($uri, '/') ? $uri : '/' . $uri), PHP_URL_PATH);
        if (($cale === '/' || $cale === '/index.php') && is_string($get['r'] ?? null)) {
            $cale = $get['r'];
        } elseif (str_starts_with($cale, '/index.php/')) {
            $cale = substr($cale, strlen('/index.php'));
        }
        return str_starts_with($cale . '/', '/en/') ? 'en' : 'ro';
    }

    /**
     * Servește pagina din cache, dacă există. Întoarce true când răspunsul a plecat.
     * După o pagină servită din cache rulează și pseudo-cronul, când e rândul lui.
     */
    public static function serveste(string $privat, array $server, array $get, array $cookies): bool
    {
        $metoda = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($metoda !== 'GET' && $metoda !== 'HEAD') {
            return false;
        }
        foreach (self::COOKIE_OCOLIRE as $c) {
            if (isset($cookies[$c])) {
                return false;
            }
        }
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $fisier = self::fisier($privat, self::limba($uri, $get), $uri);
        if (!is_file($fisier) || ($h = @fopen($fisier, 'rb')) === false) {
            return false;
        }
        $antete = json_decode((string) fgets($h), true);
        if (!is_array($antete)) {
            fclose($h);
            return false;
        }
        $acum = time();
        $pseudo = PseudoCron::scadent($privat . '/' . self::FISIER_PSEUDO_CRON, $acum) && !Intretinere::activa($privat, $acum);
        foreach ($antete as $nume => $valoare) {
            if (is_string($nume) && is_scalar($valoare)) {
                header($nume . ': ' . $valoare);
            }
        }
        header('X-Cazo-Cache: hit');
        if ($pseudo) {
            // Lungimea exactă, ca vizitatorul să primească pagina întreagă înainte de lucrul de după.
            $marime = @filesize($fisier);
            if ($marime !== false) {
                header('Content-Length: ' . ($marime - ftell($h)));
            }
            if (!function_exists('fastcgi_finish_request') && !function_exists('litespeed_finish_request')) {
                header('Connection: close');
            }
        }
        if ($metoda === 'GET') {
            fpassthru($h);
        }
        fclose($h);
        if ($pseudo) {
            Inchidere::inchide();
            self::pseudoCron($privat);
        }
        return true;
    }

    /**
     * Scrie pagina în cache (după randare), cu antetele ei pe prima linie. Doar paginile marcate `deTinut`, la GET,
     * 200, fără cookie-uri de ocolire în cerere și fără Set-Cookie în răspuns; adresa fără parametri (doar ?r=).
     */
    public static function scrie(string $privat, array $server, array $get, array $cookies, Raspuns $r): bool
    {
        if (!$r->deTinut || $r->status !== 200 || $r->cookies() !== [] || strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return false;
        }
        foreach (self::COOKIE_OCOLIRE as $c) {
            if (isset($cookies[$c])) {
                return false;
            }
        }
        if (array_diff(array_keys($get), ['r']) !== []) {
            return false;
        }
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $fisier = self::fisier($privat, self::limba($uri, $get), $uri);
        $antete = [];
        foreach ($r->antete() as $n => $v) {
            if (!in_array(strtolower($n), ['content-length', 'set-cookie', 'x-cazo-cache'], true)) {
                $antete[$n] = $v;
            }
        }
        $dir = dirname($fisier);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $fisier . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($antete, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . $r->corp, LOCK_EX) === false) {
            return false;
        }
        \Cazo\Nucleu\Functii::chmod($tmp, 0600);
        return @rename($tmp, $fisier);
    }

    /** Curățenia: paginile altor generații și versiuni (fișierele mai vechi de o zi, care nu mai sunt cele curente). */
    public static function curata(string $privat, int $acum): int
    {
        $n = 0;
        foreach (glob($privat . '/cache/pagini/*/*.html') ?: [] as $f) {
            if ((int) @filemtime($f) < $acum - 86400) {
                $n += @unlink($f) ? 1 : 0;
            }
        }
        return $n;
    }

    /** Pseudo-cronul după o pagină din cache: aceeași cale ca după o pagină a aplicației (porneste.php). */
    private static function pseudoCron(string $privat): void
    {
        $app = null;
        try {
            $app = Aplicatie::dinDosar($privat);
            if ($app->pseudoCron()->eRandul()) {
                $app->rulator()->ruleaza('pseudo');
            }
        } catch (\Throwable $e) {
            try {
                $app?->jurnal()->eroare('Pseudo-cron eșuat', ['exceptie' => get_class($e), 'mesaj' => $e->getMessage()]);
            } catch (\Throwable) {
            }
        }
    }
}
