<?php

declare(strict_types=1);

namespace Cazo\Securitate;

defined('CAZO') or exit;

/** Trunchierea IP-urilor pentru limite și jurnale: IPv4 la /24, IPv6 la /48. */
final class Ip
{
    public static function trunchiaza(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return 'necunoscut';
        }
        if (strlen($bin) === 4) {
            return inet_ntop(substr($bin, 0, 3) . "\0") . '/24';
        }
        // IPv4 mapat în IPv6 (::ffff:a.b.c.d) se tratează ca IPv4.
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return inet_ntop(substr($bin, 12, 3) . "\0") . '/24';
        }
        return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) . '/48';
    }

    /** Adresa face parte din rețea (CIDR, de exemplu 173.245.48.0/20 sau 2400:cb00::/32, ori o adresă simplă). */
    public static function inRetea(string $ip, string $retea): bool
    {
        [$baza, $biti] = array_pad(explode('/', trim($retea), 2), 2, null);
        $a = @inet_pton($ip);
        $b = @inet_pton((string) $baza);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $max = strlen($a) * 8;
        $biti = $biti === null ? $max : (ctype_digit($biti) ? (int) $biti : -1);
        if ($biti < 0 || $biti > $max) {
            return false;
        }
        $octeti = intdiv($biti, 8);
        if (substr($a, 0, $octeti) !== substr($b, 0, $octeti)) {
            return false;
        }
        $rest = $biti % 8;
        if ($rest === 0) {
            return true;
        }
        $masca = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$octeti]) & $masca) === (ord($b[$octeti]) & $masca);
    }

    /**
     * Adresa vizitatorului. Fără proxy-uri de încredere în config.php (`proxy_increzut`), REMOTE_ADDR, neschimbat.
     * Când REMOTE_ADDR e un proxy de încredere, X-Forwarded-For se citește de la dreapta spre stânga, peste
     * proxy-urile de încredere; prima adresă validă din afara lor e a vizitatorului. Restul antetului, scris
     * de vizitator, rămâne ignorat.
     *
     * @param string[] $proxyuri rețele CIDR
     */
    public static function client(string $remote, ?string $forwardedFor, array $proxyuri): string
    {
        $increzut = static function (string $ip) use ($proxyuri): bool {
            foreach ($proxyuri as $r) {
                if (is_string($r) && self::inRetea($ip, $r)) {
                    return true;
                }
            }
            return false;
        };
        if ($proxyuri === [] || $forwardedFor === null || !$increzut($remote)) {
            return $remote;
        }
        foreach (array_reverse(explode(',', $forwardedFor)) as $parte) {
            $ip = trim($parte);
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return $remote;
            }
            if (!$increzut($ip)) {
                return $ip;
            }
        }
        return $remote;
    }
}
