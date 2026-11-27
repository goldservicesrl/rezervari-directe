<?php

declare(strict_types=1);

namespace Cazo\DateExterne;

defined('CAZO') or exit;

/**
 * Răsăritul și apusul, calculate local din coordonate și dată, fără niciun serviciu extern: algoritmul din
 * „Almanac for Computers” (U.S. Naval Observatory, 1990), zenitul oficial 90°50′, precizie de un minut sau două la
 * latitudinile României. Orele ies în ora României (Europe/Bucharest, cu ora de vară).
 */
final class Soare
{
    private const ZENIT = 90.833;

    /** @return array{rasarit:?string, apus:?string} „HH:MM”, sau null în zilele fără răsărit ori apus (cercul polar) */
    public static function ore(float $lat, float $lng, string $zi, string $fus = 'Europe/Bucharest'): array
    {
        return ['rasarit' => self::moment($lat, $lng, $zi, true, $fus), 'apus' => self::moment($lat, $lng, $zi, false, $fus)];
    }

    private static function moment(float $lat, float $lng, string $zi, bool $rasarit, string $fus): ?string
    {
        $t0 = strtotime($zi . ' 00:00:00 UTC');
        $n = (int) gmdate('z', $t0) + 1;
        $lngOra = $lng / 15;
        $t = $n + ((($rasarit ? 6 : 18) - $lngOra) / 24);
        $m = 0.9856 * $t - 3.289;
        $l = fmod($m + 1.916 * sin(deg2rad($m)) + 0.020 * sin(deg2rad(2 * $m)) + 282.634 + 720, 360);
        $ra = fmod(rad2deg(atan(0.91764 * tan(deg2rad($l)))) + 720, 360);
        $ra += floor($l / 90) * 90 - floor($ra / 90) * 90;
        $ra /= 15;
        $sinDec = 0.39782 * sin(deg2rad($l));
        $cosDec = cos(asin($sinDec));
        $cosH = (cos(deg2rad(self::ZENIT)) - $sinDec * sin(deg2rad($lat))) / ($cosDec * cos(deg2rad($lat)));
        if ($cosH > 1 || $cosH < -1) {
            return null;
        }
        $h = $rasarit ? 360 - rad2deg(acos($cosH)) : rad2deg(acos($cosH));
        $h /= 15;
        $ut = fmod($h + $ra - 0.06571 * $t - 6.622 - $lngOra + 48, 24);
        $s = $t0 + (int) round($ut * 3600);
        return (new \DateTimeImmutable('@' . $s))->setTimezone(new \DateTimeZone($fus))->format('H:i');
    }
}
