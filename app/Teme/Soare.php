<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/**
 * Răsăritul și apusul (`rasarit_apus`): calculate local, din coordonatele
 * proprietății și dată, fără niciun serviciu extern. Orele sunt în ora României (Europe/Bucharest, cu ora de vară),
 * rotunjite la minut, ca text „HH:MM”. Ziua polară sau noaptea polară (fără răsărit sau fără apus) dă null.
 */
final class Soare
{
    public const FUS = 'Europe/Bucharest';

    /** @return array{zi:string, rasarit:?string, apus:?string}|null null pentru coordonate sau zi invalide */
    public static function zi(float $lat, float $lng, string $zi): ?array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $zi)) {
            return null;
        }
        $fus = new \DateTimeZone(self::FUS);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $zi . ' 12:00', $fus);
        if ($d === false || $d->format('Y-m-d') !== $zi) {
            return null;
        }
        // Algoritmul NOAA complet (calculatorul NOAA, după Meeus): poziția Soarelui (declinația și ecuația timpului) se
        // calculează la momentul evenimentului, nu la amiază, prin iterații pornite de la amiază; zenitul 90,833° ține
        // seama de refracție și de raza discului. Calculat aici, nu cu date_sun_info(), care dă rezultate diferite de la
        // o versiune PHP la alta.
        $miezulNoptiiUtc = (new \DateTimeImmutable($zi . ' 00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $info = ['sunrise' => null, 'sunset' => null];
        foreach (['sunrise' => 1, 'sunset' => -1] as $ev => $semn) {
            $minute = 720.0 - 4 * $lng;
            for ($i = 0; $i < 3 && $minute !== null; $i++) {
                $minute = self::minuteEveniment($lat, $lng, $miezulNoptiiUtc / 86400 + 2440587.5 + $minute / 1440, $semn);
            }
            if ($minute !== null) {
                $info[$ev] = $miezulNoptiiUtc + (int) round($minute * 60);
            }
        }
        $ora = static function (?int $t) use ($fus): ?string {
            // Rotunjit la cel mai apropiat minut.
            return $t === null ? null : (new \DateTimeImmutable('@' . ($t + 30)))->setTimezone($fus)->format('H:i');
        };
        return ['zi' => $zi, 'rasarit' => $ora($info['sunrise'] ?? null), 'apus' => $ora($info['sunset'] ?? null)];
    }

    /**
     * Minutele UTC de la miezul nopții ale răsăritului ($semn 1) sau apusului ($semn -1), cu poziția Soarelui la ziua
     * iuliană $jd (formulele calculatorului NOAA); null fără răsărit sau fără apus (zi sau noapte polară).
     */
    private static function minuteEveniment(float $lat, float $lng, float $jd, int $semn): ?float
    {
        $t = ($jd - 2451545.0) / 36525;
        $l0 = fmod(280.46646 + $t * (36000.76983 + $t * 0.0003032), 360);
        $m = 357.52911 + $t * (35999.05029 - 0.0001537 * $t);
        $e = 0.016708634 - $t * (0.000042037 + 0.0000001267 * $t);
        $c = sin(deg2rad($m)) * (1.914602 - $t * (0.004817 + 0.000014 * $t)) + sin(deg2rad(2 * $m)) * (0.019993 - 0.000101 * $t)
            + sin(deg2rad(3 * $m)) * 0.000289;
        $omega = deg2rad(125.04 - 1934.136 * $t);
        $lambda = $l0 + $c - 0.00569 - 0.00478 * sin($omega);
        $eps0 = 23 + (26 + (21.448 - $t * (46.815 + $t * (0.00059 - $t * 0.001813))) / 60) / 60;
        $eps = deg2rad($eps0 + 0.00256 * cos($omega));
        $declinatia = asin(sin($eps) * sin(deg2rad($lambda)));
        $y = tan($eps / 2) ** 2;
        $l0r = deg2rad($l0);
        $mr = deg2rad($m);
        $ecuatiaTimpului = 4 * rad2deg($y * sin(2 * $l0r) - 2 * $e * sin($mr) + 4 * $e * $y * sin($mr) * cos(2 * $l0r)
            - 0.5 * $y * $y * sin(4 * $l0r) - 1.25 * $e * $e * sin(2 * $mr));
        $phi = deg2rad($lat);
        $cosH = cos(deg2rad(90.833)) / (cos($phi) * cos($declinatia)) - tan($phi) * tan($declinatia);
        if ($cosH < -1 || $cosH > 1) {
            return null;
        }
        return 720 - 4 * ($lng + $semn * rad2deg(acos($cosH))) - $ecuatiaTimpului;
    }

    /**
     * Datele pentru teme: azi, ziua sosirii (din cerere, altfel azi) și dimineața de după ea.
     * @return array{azi:array, sosire:array, dimineata:array, din_cerere:bool}|null null fără coordonate
     */
    public static function pentruTema(?array $coordonate, string $azi, ?string $sosire): ?array
    {
        if (!is_array($coordonate) || !is_numeric($coordonate['lat'] ?? null) || !is_numeric($coordonate['lng'] ?? null)) {
            return null;
        }
        $lat = (float) $coordonate['lat'];
        $lng = (float) $coordonate['lng'];
        $dinCerere = is_string($sosire) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $sosire) === 1;
        $ziSosire = $dinCerere ? $sosire : $azi;
        $a = self::zi($lat, $lng, $azi);
        $s = self::zi($lat, $lng, $ziSosire);
        if ($a === null || $s === null) {
            return null;
        }
        $urmatoarea = (new \DateTimeImmutable($ziSosire . ' 12:00', new \DateTimeZone(self::FUS)))->modify('+1 day')->format('Y-m-d');
        return ['azi' => $a, 'sosire' => $s, 'dimineata' => self::zi($lat, $lng, $urmatoarea) ?? ['zi' => $urmatoarea, 'rasarit' => null, 'apus' => null], 'din_cerere' => $dinCerere];
    }
}
