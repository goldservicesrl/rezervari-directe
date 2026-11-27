<?php

declare(strict_types=1);

namespace Cazo\Securitate;

defined('CAZO') or exit;

/**
 * Jetoanele CSRF, legate de o valoare a vizitatorului (id-ul sesiunii de panou sau cookie-ul __Host-pg_csrf).
 * Jeton = base64url(aleator16) . "." . base64url(HMAC-SHA256(cheia csrf, scop \0 legătură \0 aleator)).
 * Verificarea e în timp constant; un jeton emis pentru o legătură sau un scop nu trece pentru altele.
 */
final class Csrf
{
    public const CAMP = '_csrf';
    public const ANTET = 'X-CSRF';
    public const COOKIE = '__Host-pg_csrf';

    private string $cheie;

    public function __construct(Chei $chei)
    {
        $this->cheie = $chei->subcheie('csrf');
    }

    public function emite(string $legatura, string $scop = 'general'): string
    {
        $aleator = random_bytes(16);
        return Codare::b64u($aleator) . '.' . Codare::b64u($this->mac($legatura, $scop, $aleator));
    }

    public function verifica(string $legatura, ?string $jeton, string $scop = 'general'): bool
    {
        if ($legatura === '' || $jeton === null || strlen($jeton) > 200 || substr_count($jeton, '.') !== 1) {
            return false;
        }
        [$a, $m] = explode('.', $jeton, 2);
        $aleator = Codare::dinB64u($a);
        $mac = Codare::dinB64u($m);
        if ($aleator === null || $mac === null || strlen($aleator) !== 16) {
            return false;
        }
        return hash_equals($this->mac($legatura, $scop, $aleator), $mac);
    }

    private function mac(string $legatura, string $scop, string $aleator): string
    {
        return hash_hmac('sha256', $scop . "\0" . $legatura . "\0" . $aleator, $this->cheie, true);
    }
}
