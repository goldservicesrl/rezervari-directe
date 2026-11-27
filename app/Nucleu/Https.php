<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

defined('CAZO') or exit;

/**
 * HTTPS verificat: redirecționarea 301 de pe http pe https și antetul HSTS pornesc abia
 * după ce site-ul a servit el însuși o cerere venită pe HTTPS, pe adresa lui din config.php.
 *
 * Verificarea: prima cerere pe care aplicația o tratează și care a sosit pe HTTPS (direct sau printr-un proxy care
 * anunță schema: X-Forwarded-Proto, X-Forwarded-Ssl, CF-Visitor), pe gazda din `site_url`, cu `site_url` pe https,
 * scrie setarea `https.verificat` (HSTS, Antete) și fișierul-martor `cache/https-verificat`, cu originea site-ului.
 * Martorul e un fișier fiindcă redirecționarea se hotărăște înaintea aplicației și a bazei, în app/cache-pagini.php,
 * ca și paginile servite din cache să fie redirecționate.
 *
 * Redirecționarea: doar GET și HEAD, doar când cererea a sosit fără HTTPS, spre originea din martor, cu aceeași cale.
 * Bucla e oprită în două feluri: (1) cererea e socotită sigură cu aceeași funcție care a făcut verificarea, deci un
 * proxy care anunța schema la verificare o anunță și după redirecționare; (2) redirecționarea pune un cookie scurt
 * (60 s, fără Secure), iar o cerere „fără HTTPS” care îl aduce înapoi înseamnă că browserul a urmat deja
 * redirecționarea și a ajuns tot aici (un proxy care a încetat să anunțe schema): pagina se servește atunci fără o
 * nouă redirecționare.
 */
final class Https
{
    public const FISIER = 'cache/https-verificat';
    public const SETARE = 'https.verificat';
    public const COOKIE = 'pg_https';

    /** Cererea a sosit pe HTTPS, direct sau printr-un proxy care anunță schema. */
    public static function cerereSigura(array $server): bool
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        if (($https !== '' && $https !== 'off' && $https !== '0') || (int) ($server['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        if (strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https'
            || strtolower((string) ($server['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on'
            || strtolower((string) ($server['REQUEST_SCHEME'] ?? '')) === 'https') {
            return true;
        }
        return str_contains(strtolower((string) ($server['HTTP_CF_VISITOR'] ?? '')), '"scheme":"https"');
    }

    /** Originea verificată (https://domeniu), din fișierul-martor, sau null. */
    public static function origineVerificata(string $privat): ?string
    {
        $o = @file_get_contents($privat . '/' . self::FISIER);
        $o = is_string($o) ? trim($o) : '';
        return preg_match('~^https://[A-Za-z0-9.\-]+(:\d{1,5})?$~', $o) ? $o : null;
    }

    /**
     * După o cerere tratată de aplicație: notează verificarea, o singură dată. Întoarce true când tocmai a notat-o
     * (apelantul reface atunci paginile din cache, ca să primească antetul HSTS).
     */
    public static function verifica(string $privat, string $origine, Setari $setari, array $server): bool
    {
        if (!str_starts_with($origine, 'https://') || !self::cerereSigura($server)) {
            return false;
        }
        $gazda = strtolower((string) preg_replace('~:\d+$~', '', (string) ($server['HTTP_HOST'] ?? '')));
        if ($gazda === '' || $gazda !== strtolower((string) parse_url($origine, PHP_URL_HOST))) {
            return false;
        }
        $nou = $setari->get(self::SETARE, false) !== true;
        if ($nou) {
            $setari->pune(self::SETARE, true);
        }
        if (self::origineVerificata($privat) !== rtrim($origine, '/')) {
            $f = $privat . '/' . self::FISIER;
            if (!is_dir(dirname($f))) {
                @mkdir(dirname($f), 0700, true);
            }
            $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, rtrim($origine, '/'), LOCK_EX) !== false) {
                Functii::chmod($tmp, 0600);
                @rename($tmp, $f);
            }
        }
        return $nou;
    }

    /**
     * Adresa spre care se redirecționează cererea, sau null (HTTPS neverificat, cererea e deja sigură, altă metodă
     * decât GET și HEAD, ori browserul s-a întors de la o redirecționare abia făcută).
     */
    public static function tinta(string $privat, array $server, array $cookies): ?string
    {
        $metoda = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if (($metoda !== 'GET' && $metoda !== 'HEAD') || self::cerereSigura($server) || isset($cookies[self::COOKIE])) {
            return null;
        }
        $origine = self::origineVerificata($privat);
        if ($origine === null) {
            return null;
        }
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        // Doar o cale locală, fără caractere de control: adresa nu poate fi dusă spre alt domeniu.
        if ($uri === '' || $uri[0] !== '/' || str_starts_with($uri, '//') || preg_match('~[\x00-\x20\x7F\\\\]~', $uri)) {
            $uri = '/';
        }
        return $origine . $uri;
    }

    /** Trimite redirecționarea 301, dacă e cazul. Întoarce true când răspunsul a plecat. */
    public static function redirectioneaza(string $privat, array $server, array $cookies): bool
    {
        $tinta = self::tinta($privat, $server, $cookies);
        if ($tinta === null || headers_sent()) {
            return false;
        }
        http_response_code(301);
        header('Location: ' . $tinta);
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=utf-8');
        header('Set-Cookie: ' . self::COOKIE . '=1; Path=/; Max-Age=60; HttpOnly; SameSite=Lax');
        return true;
    }
}
