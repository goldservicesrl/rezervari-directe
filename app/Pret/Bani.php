<?php

declare(strict_types=1);

namespace Cazo\Pret;

defined('CAZO') or exit;

/**
 * Rotunjirea sumelor, aceeași ca în EasyCheck (o singură dată pe componentă, la cel mai apropiat ban,
 * jumătatea în sus, fără zgomotul virgulei mobile), ca totalul local să coincidă cu GET /total.
 */
final class Bani
{
    /** Lei cu zecimale → lei rotunjiți la ban, ca în EasyCheck. */
    public static function rot2(float $lei): float
    {
        $b = floor(round(abs($lei) * 100, 6) + 0.5);
        return ($lei < 0 ? -$b : $b) / 100;
    }

    /** Bani cu fracțiuni → bani întregi, cu aceeași rotunjire ca GET /total. */
    public static function intregi(float $bani): int
    {
        return (int) round(self::rot2($bani / 100) * 100);
    }
}
