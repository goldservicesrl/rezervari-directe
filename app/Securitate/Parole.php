<?php

declare(strict_types=1);

namespace Cazo\Securitate;

defined('CAZO') or exit;

/** Parolele panoului: Argon2id, cu bcrypt de rezervă unde PHP-ul îl are lipsă. */
final class Parole
{
    public const MINIM = 12;

    public static function algoritm(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function hash(string $parola): string
    {
        if (mb_strlen($parola) < self::MINIM || strlen($parola) > 4096) {
            throw new \InvalidArgumentException('Parola are cel puțin ' . self::MINIM . ' caractere.');
        }
        return password_hash($parola, self::algoritm());
    }

    public static function verifica(string $parola, string $hash): bool
    {
        return strlen($parola) <= 4096 && password_verify($parola, $hash);
    }

    public static function deRefacut(string $hash): bool
    {
        return password_needs_rehash($hash, self::algoritm());
    }
}
