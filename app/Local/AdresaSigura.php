<?php

declare(strict_types=1);

namespace Cazo\Local;

defined('CAZO') or exit;

/**
 * Garda adreselor iCal scrise de gazdă: site-ul cere doar ce e public pe internet, ca o adresă scrisă greșit
 * (sau de altcineva, în panou) să nu-l facă să citească servicii din rețeaua găzduirii (SSRF).
 *
 *  - doar https://, fără utilizator și parolă în adresă, pe portul 443;
 *  - numele gazdei se rezolvă o dată, iar toate adresele IP găsite trebuie să fie publice: fără 127/8, 10/8,
 *    172.16/12, 192.168/16, 169.254/16 (metadatele cloud), 100.64/10, 0/8, multicast, rezervate, ::1, fc00::/7, fe80::/10,
 *    IPv4 mapat în IPv6 cu o adresă privată; un IP scris direct în adresă trece prin aceeași regulă;
 *  - cererea pleacă exact spre IP-ul verificat (CURLOPT_RESOLVE), deci un DNS schimbat între verificare și cerere
 *    (DNS rebinding) nu mută cererea; redirecționările se urmează de mână, fiecare verificată la fel.
 */
final class AdresaSigura
{
    /**
     * @param ?\Closure(string): list<string> $rezolva numele gazdei → adresele IP (testele dau o dublură)
     * @return array{url:string, gazda:string, port:int, ip:string}
     * @throws EroareIcal codul: adresa, schema, gazda, dns
     */
    public static function verifica(string $url, ?\Closure $rezolva = null): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2000 || preg_match('~[\s\x00-\x1F\x7F]~', $url)) {
            throw new EroareIcal('adresa');
        }
        // Adresele webcal:// (Apple, Google) sunt https.
        if (stripos($url, 'webcal://') === 0) {
            $url = 'https://' . substr($url, 9);
        }
        $p = parse_url($url);
        if (is_array($p) && isset($p['scheme']) && strtolower($p['scheme']) !== 'https') {
            throw new EroareIcal('schema');
        }
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
            throw new EroareIcal('adresa');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new EroareIcal('adresa');
        }
        $port = (int) ($p['port'] ?? 443);
        if ($port !== 443) {
            throw new EroareIcal('gazda');
        }
        $gazda = strtolower(trim((string) $p['host'], '[]'));
        if ($gazda === '' || $gazda === 'localhost' || str_ends_with($gazda, '.localhost') || str_ends_with($gazda, '.local') || str_ends_with($gazda, '.internal')) {
            throw new EroareIcal('gazda');
        }
        if (filter_var($gazda, FILTER_VALIDATE_IP) !== false) {
            $ipuri = [$gazda];
        } else {
            if (!preg_match('~^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$~', $gazda)) {
                throw new EroareIcal('adresa');
            }
            $ipuri = ($rezolva ?? self::rezolva(...))($gazda);
            if ($ipuri === []) {
                throw new EroareIcal('dns');
            }
        }
        foreach ($ipuri as $ip) {
            if (!self::public($ip)) {
                throw new EroareIcal('gazda');
            }
        }
        return ['url' => $url, 'gazda' => $gazda, 'port' => $port, 'ip' => $ipuri[0]];
    }

    /** IP-ul e o adresă publică (nici privată, nici rezervată, nici o adresă specială). */
    public static function public(string $ip): bool
    {
        $ip = trim($ip, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // IPv4 mapat în IPv6 (::ffff:10.0.0.1, și scris hexa: ::ffff:a00:1) sau compatibil (::10.0.0.1): regula IPv4.
        // Auditul de securitate (24.11.2026): citit pe octeți, după inet_pton, nu după forma textului.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $b = (string) inet_pton($ip);
            if (strlen($b) === 16 && str_starts_with($b, str_repeat("\0", 10)) && in_array(substr($b, 10, 2), ["\xff\xff", "\0\0"], true)
                && substr($b, 0, 15) !== str_repeat("\0", 15)) {
                $ip = (string) inet_ntop(substr($b, 12, 4));
            }
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $n = ip2long($ip);
            foreach ([['0.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['192.0.0.0', 24], ['192.0.2.0', 24], ['198.18.0.0', 15],
                ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$retea, $biti]) {
                $masca = -1 << (32 - $biti);
                if (($n & $masca) === (ip2long($retea) & $masca)) {
                    return false;
                }
            }
            return true;
        }
        $bin = inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return false;
        }
        // Auditul de securitate (24.11.2026): doar adresele globale unicast (2000::/3). Restul (::1, fc00::/7, fe80::/10, fec0::/10,
        // ff00::/8, NAT64 64:ff9b::/96, 100::/64) iese de aici. Din 2000::/3 ies adresele care duc spre un IPv4 oarecare, deci și
        // spre rețeaua internă: 6to4 (2002::/16) și Teredo (2001::/32), plus documentarea (2001:db8::/32) și ORCHID (2001:10::/28, 2001:20::/28).
        if ((ord($bin[0]) & 0xE0) !== 0x20) {
            return false;
        }
        $p4 = substr($bin, 0, 4);
        if (substr($bin, 0, 2) === "\x20\x02" || $p4 === "\x20\x01\x00\x00" || $p4 === "\x20\x01\x0d\xb8"
            || (substr($bin, 0, 3) === "\x20\x01\x00" && in_array(ord($bin[3]) & 0xF0, [0x10, 0x20], true))) {
            return false;
        }
        return true;
    }

    /** Adresele IPv4 și IPv6 ale unui nume (A și AAAA). @return list<string> */
    private static function rezolva(string $gazda): array
    {
        $ipuri = [];
        $r = @dns_get_record($gazda, DNS_A | DNS_AAAA);
        foreach (is_array($r) ? $r : [] as $x) {
            if (isset($x['ip'])) {
                $ipuri[] = (string) $x['ip'];
            } elseif (isset($x['ipv6'])) {
                $ipuri[] = (string) $x['ipv6'];
            }
        }
        if ($ipuri === []) {
            $v4 = @gethostbynamel($gazda);
            $ipuri = is_array($v4) ? $v4 : [];
        }
        return array_values(array_unique($ipuri));
    }

    /** Adresa arătată în panou: doar gazda și începutul căii (restul, cu jetonul platformei, rămâne ascuns). */
    public static function scurta(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['host'])) {
            return '';
        }
        $cale = (string) ($p['path'] ?? '');
        return strtolower((string) $p['host']) . (strlen($cale) > 12 ? substr($cale, 0, 12) . '…' : $cale);
    }
}
