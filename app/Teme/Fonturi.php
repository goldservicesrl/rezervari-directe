<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/**
 * Fonturile temelor: fișiere WOFF2, cu glifele românești Ș ș Ț ț (U+0218-U+021B).
 *
 * Tabelele WOFF2 sunt comprimate cu Brotli. Cu extensia PHP `brotli` (brotli_uncompress), validatorul citește tabelul
 * `cmap` și verifică glifele direct. Fără ea, verificarea trece pe intervalul declarat în CSS: fiecare familie are un
 * `@font-face` al cărui `unicode-range` acoperă U+0218-U+021B (sau unul fără `unicode-range`), iar raportul primește
 * un avertisment.
 */
final class Fonturi
{
    public const GLIFE = [0x0218, 0x0219, 0x021A, 0x021B];

    /** @return array{ok:bool, motiv:string} forma fișierului: semnătura și antetul WOFF2 */
    public static function forma(string $date): array
    {
        if (strlen($date) < 48 || substr($date, 0, 4) !== 'wOF2') {
            return ['ok' => false, 'motiv' => 'fișierul nu e WOFF2'];
        }
        $h = unpack('Nsemn/Nflavor/Nlungime/nnumTabele/nrez/NtotalSfnt/NtotalComprimat', substr($date, 0, 28));
        if ($h === false || $h['lungime'] !== strlen($date) || $h['numTabele'] === 0 || $h['numTabele'] > 100) {
            return ['ok' => false, 'motiv' => 'antetul WOFF2 e stricat'];
        }
        return ['ok' => true, 'motiv' => ''];
    }

    /**
     * Glifele românești din `cmap`: true/false, sau null când nu se pot citi (fără brotli, ori un tabel neobișnuit).
     */
    public static function areGlifeRomanesti(string $date): ?bool
    {
        if (!function_exists('brotli_uncompress') || !self::forma($date)['ok']) {
            return null;
        }
        try {
            $t = self::tabele($date);
        } catch (\Throwable) {
            return null;
        }
        if (!isset($t['cmap'])) {
            return false;
        }
        $coduri = self::coduriCmap($t['cmap']);
        foreach (self::GLIFE as $g) {
            if (!isset($coduri[$g])) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,string> tabelele decomprimate, după etichetă (doar cele fără transformare: cmap) */
    private static function tabele(string $date): array
    {
        $numTabele = unpack('n', substr($date, 12, 2))[1];
        $lungimeComprimat = unpack('N', substr($date, 20, 4))[1];
        $poz = 48;
        $etichete = ['cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'post', 'cvt ', 'fpgm', 'glyf', 'loca', 'prep', 'CFF ', 'VORG', 'EBDT', 'EBLC', 'gasp',
            'hdmx', 'kern', 'LTSH', 'PCLT', 'VDMX', 'vhea', 'vmtx', 'BASE', 'GDEF', 'GPOS', 'GSUB', 'EBSC', 'JSTF', 'MATH', 'CBDT', 'CBLC', 'COLR', 'CPAL', 'SVG ',
            'sbix', 'acnt', 'avar', 'bdat', 'bloc', 'bsln', 'cvar', 'fdsc', 'feat', 'fmtx', 'fvar', 'gvar', 'hsty', 'just', 'lcar', 'mort', 'morx', 'opbd', 'prop',
            'trak', 'Zapf', 'Silf', 'Glat', 'Gloc', 'Feat', 'Sill'];
        $intrari = [];
        for ($i = 0; $i < $numTabele; $i++) {
            $flags = ord($date[$poz++]);
            $idx = $flags & 0x3F;
            if ($idx === 63) {
                $tag = substr($date, $poz, 4);
                $poz += 4;
            } else {
                $tag = $etichete[$idx] ?? '????';
            }
            $orig = self::uintBase128($date, $poz);
            $transf = ($flags >> 6) & 3;
            $lung = $orig;
            if (($tag === 'glyf' || $tag === 'loca') ? $transf === 0 : $transf !== 0) {
                $lung = self::uintBase128($date, $poz);
            }
            $intrari[] = [$tag, $lung];
        }
        $flux = brotli_uncompress(substr($date, $poz, $lungimeComprimat));
        if (!is_string($flux)) {
            throw new \UnexpectedValueException('brotli');
        }
        $rez = [];
        $o = 0;
        foreach ($intrari as [$tag, $lung]) {
            $rez[$tag] = substr($flux, $o, $lung);
            $o += $lung;
        }
        return $rez;
    }

    private static function uintBase128(string $d, int &$p): int
    {
        $v = 0;
        for ($i = 0; $i < 5; $i++) {
            $b = ord($d[$p++]);
            $v = ($v << 7) | ($b & 0x7F);
            if (($b & 0x80) === 0) {
                return $v;
            }
        }
        throw new \UnexpectedValueException('UIntBase128');
    }

    /** @return array<int,true> codurile Unicode din subtabelele de format 4 și 12 */
    private static function coduriCmap(string $c): array
    {
        $r = [];
        $n = unpack('n', substr($c, 2, 2))[1];
        for ($i = 0; $i < $n; $i++) {
            $off = unpack('N', substr($c, 4 + $i * 8 + 4, 4))[1];
            $format = unpack('n', substr($c, $off, 2))[1];
            if ($format === 4) {
                $seg = intdiv(unpack('n', substr($c, $off + 6, 2))[1], 2);
                $sf = $off + 14;
                for ($s = 0; $s < $seg; $s++) {
                    $end = unpack('n', substr($c, $sf + $s * 2, 2))[1];
                    $start = unpack('n', substr($c, $sf + $seg * 2 + 2 + $s * 2, 2))[1];
                    foreach (self::GLIFE as $g) {
                        if ($g >= $start && $g <= $end) {
                            $r[$g] = true;
                        }
                    }
                }
            } elseif ($format === 12) {
                $grupuri = unpack('N', substr($c, $off + 12, 4))[1];
                for ($g2 = 0; $g2 < $grupuri; $g2++) {
                    $x = unpack('Nstart/Nend', substr($c, $off + 16 + $g2 * 12, 8));
                    foreach (self::GLIFE as $g) {
                        if ($g >= $x['start'] && $g <= $x['end']) {
                            $r[$g] = true;
                        }
                    }
                }
            }
        }
        return $r;
    }

    /**
     * Intervalele declarate în CSS pentru fiecare fișier de font (`@font-face` cu `src: url(...)` și, opțional,
     * `unicode-range`). @return array<string, list<array{0:int,1:int}>|null> cale relativă la temă => intervale (null = tot Unicode)
     */
    public static function intervaleCss(string $css, string $dirCss): array
    {
        $r = [];
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        if (!preg_match_all('~@font-face\s*\{([^}]*)\}~i', $css, $m)) {
            return [];
        }
        foreach ($m[1] as $corp) {
            if (!preg_match_all('~url\(\s*([\'"]?)([^\'")]+)\1\s*\)~i', $corp, $u)) {
                continue;
            }
            $intervale = null;
            if (preg_match('~unicode-range\s*:\s*([^;]+)~i', $corp, $ur)) {
                $intervale = [];
                foreach (explode(',', $ur[1]) as $x) {
                    $x = strtoupper(trim($x));
                    if (preg_match('~^U\+([0-9A-F?]{1,6})(?:-([0-9A-F]{1,6}))?$~', $x, $p)) {
                        $a = hexdec(str_replace('?', '0', $p[1]));
                        $b = isset($p[2]) ? hexdec($p[2]) : hexdec(str_replace('?', 'F', $p[1]));
                        $intervale[] = [(int) $a, (int) $b];
                    }
                }
            }
            foreach ($u[2] as $cale) {
                $rel = self::normalizeaza($dirCss . '/' . trim($cale));
                if ($rel !== null) {
                    $r[$rel] = $intervale;
                }
            }
        }
        return $r;
    }

    /** @param list<array{0:int,1:int}>|null $intervale */
    public static function acopera(?array $intervale): bool
    {
        if ($intervale === null) {
            return true;
        }
        foreach (self::GLIFE as $g) {
            $ok = false;
            foreach ($intervale as [$a, $b]) {
                $ok = $ok || ($g >= $a && $g <= $b);
            }
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /** O cale relativă (assets/css/../fonts/x.woff2 => assets/fonts/x.woff2), sau null dacă iese din temă. */
    public static function normalizeaza(string $cale): ?string
    {
        $r = [];
        foreach (explode('/', $cale) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                if ($r === []) {
                    return null;
                }
                array_pop($r);
                continue;
            }
            $r[] = $p;
        }
        return implode('/', $r);
    }
}
