<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

defined('CAZO') or exit;

/**
 * Funcțiile pe care găzduirea le poate opri (disable_functions): în PHP 8, apelul unei funcții oprite
 * e o eroare fatală, chiar cu @. Fără chmod și umask, fișierele păstrează drepturile date de server (dosarul privat
 * rămâne apărat de locul lui și de .htaccess); fără flock, pașii pe care îi ordona rulează fără lacăt.
 */
final class Functii
{
    public static function chmod(string $cale, int $mod): bool
    {
        return function_exists('chmod') && @chmod($cale, $mod);
    }

    /** Masca nouă; întoarce masca veche, sau null când umask lipsește. */
    public static function umask(int $masca): ?int
    {
        return function_exists('umask') ? umask($masca) : null;
    }

    public static function restabilesteUmask(?int $vechi): void
    {
        if ($vechi !== null && function_exists('umask')) {
            umask($vechi);
        }
    }

    /** @param resource $f */
    public static function flock($f, int $operatie): bool
    {
        return function_exists('flock') && flock($f, $operatie);
    }
}
