<?php

declare(strict_types=1);

namespace Cazo\I18n;

defined('CAZO') or exit;

/**
 * Regulile de plural (CLDR), pentru numere întregi.
 *   ro: unu (1), cateva (0 și 2-19 la final de sută: 2 nopți, 101 nopți), multe (20 de nopți, 120 de nopți)
 *   en: unu (1), multe (rest)
 * de și it (după v1) folosesc regula engleză.
 */
final class Plural
{
    public static function forma(string $limba, int $n): string
    {
        $n = abs($n);
        if ($limba === 'ro') {
            if ($n === 1) {
                return 'unu';
            }
            $r = $n % 100;
            return ($n === 0 || ($r >= 1 && $r <= 19)) ? 'cateva' : 'multe';
        }
        return $n === 1 ? 'unu' : 'multe';
    }

    /** Formele cerute de fiecare limbă în cataloage. */
    public static function forme(string $limba): array
    {
        return $limba === 'ro' ? ['unu', 'cateva', 'multe'] : ['unu', 'multe'];
    }
}
