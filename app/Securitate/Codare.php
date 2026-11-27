<?php

declare(strict_types=1);

namespace Cazo\Securitate;

defined('CAZO') or exit;

/** Base64 sigur pentru URL, fără completare. */
final class Codare
{
    public static function b64u(string $brut): string
    {
        return rtrim(strtr(base64_encode($brut), '+/', '-_'), '=');
    }

    public static function dinB64u(string $text): ?string
    {
        if (!preg_match('~^[A-Za-z0-9_-]*$~', $text)) {
            return null;
        }
        $brut = base64_decode(strtr($text, '-_', '+/'), true);
        return $brut === false ? null : $brut;
    }

    /** Un jeton aleator de $octeti octeți, în base64url (32 de octeți = 43 de caractere). */
    public static function jeton(int $octeti = 32): string
    {
        return self::b64u(random_bytes($octeti));
    }
}
