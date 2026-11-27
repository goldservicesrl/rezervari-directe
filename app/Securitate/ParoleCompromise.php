<?php

declare(strict_types=1);

namespace Cazo\Securitate;

defined('CAZO') or exit;

/**
 * Parolele compromise: lista locală parole-compromise.txt, fără nicio cerere în afară,
 * plus tiparele evidente (un singur caracter repetat, o secvență de tastatură sau de cifre, adresa de e-mail sau
 * numele site-ului repetate în parolă).
 */
final class ParoleCompromise
{
    public const FISIER = __DIR__ . '/parole-compromise.txt';

    private const SECVENTE = ['0123456789012345678901234567890', '9876543210987654321098765432109', 'abcdefghijklmnopqrstuvwxyz', 'qwertyuiopasdfghjklzxcvbnm', '1q2w3e4r5t6y7u8i9o0p'];

    /** @param list<string> $context cuvinte legate de cont (partea locală a e-mailului, domeniul, numele proprietății) */
    public static function compromisa(string $parola, array $context = []): bool
    {
        $p = mb_strtolower($parola);
        if (count(array_unique(mb_str_split($p))) <= 2) {
            return true;
        }
        foreach (self::SECVENTE as $s) {
            if (str_contains($s, $p)) {
                return true;
            }
        }
        foreach ($context as $c) {
            $c = mb_strtolower(trim($c));
            // Parola e cuvântul contului, cu cel mult 8 cifre sau semne adăugate (un an, „123”, „!”).
            if (mb_strlen($c) >= 4 && str_contains($p, $c) && mb_strlen($p) - mb_strlen($c) <= 8) {
                return true;
            }
        }
        static $lista = null;
        if ($lista === null) {
            $lista = [];
            foreach (file(self::FISIER, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
                if ($l !== '' && $l[0] !== '#') {
                    $lista[$l] = true;
                }
            }
        }
        return isset($lista[$p]);
    }
}
