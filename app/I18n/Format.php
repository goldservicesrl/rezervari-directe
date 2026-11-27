<?php

declare(strict_types=1);

namespace Cazo\I18n;

defined('CAZO') or exit;

/**
 * Formatarea banilor, a numerelor și a datelor:
 *   RO „1.450,00 lei”, „20 dec. 2026”; EN „RON 1,450.00”, „20 Dec 2026”; fusul Europe/Bucharest.
 * Tabelele sunt proprii, ca rezultatul să fie același cu și fără extensia intl.
 * Sumele sunt întregi, în bani.
 */
final class Format
{
    public const FUS = 'Europe/Bucharest';

    private const LUNI = [
        'ro' => ['ian.', 'feb.', 'mar.', 'apr.', 'mai', 'iun.', 'iul.', 'aug.', 'sept.', 'oct.', 'nov.', 'dec.'],
        'en' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    ];

    private const LUNI_LUNGI = [
        'ro' => ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ];

    /**
     * Literele vechi cu sedilă din datele venite din EasyCheck (Ş, ş, Ţ, ţ) devin, la afișare, literele românești cu
     * virguliță (Ș, ș, Ț, ț). Singurul loc al corecturii: forma textului rămâne cea primită, iar valoarea
     * păstrată în oglindă rămâne neatinsă.
     */
    public static function diacritice(string $text): string
    {
        return strtr($text, ["\u{015E}" => "\u{0218}", "\u{015F}" => "\u{0219}", "\u{0162}" => "\u{021A}", "\u{0163}" => "\u{021B}"]);
    }

    /** Numele lunii (1-12), pentru titlul calendarului. */
    public static function luna(int $luna, string $limba): string
    {
        return (self::LUNI_LUNGI[$limba] ?? self::LUNI_LUNGI['en'])[max(1, min(12, $luna)) - 1];
    }

    /** O zi cu luna întreagă: „20 decembrie 2026”, „20 December 2026” (etichetele calendarului, 19). */
    public static function ziLunga(string $zi, string $limba): string
    {
        self::zi($zi, $limba);
        [$a, $l, $z] = array_map('intval', explode('-', $zi));
        return $z . ' ' . self::luna($l, $limba) . ' ' . $a;
    }

    /**
     * Intervalul unui sejur, cu luna întreagă, în forma limbii: „20 - 23 decembrie 2026”,
     * „28 noiembrie - 2 decembrie 2026”, „29 decembrie 2026 - 2 ianuarie 2027”; în engleză, „20 - 23 December 2026”.
     */
    public static function interval(string $de, string $pana, string $limba): string
    {
        self::zi($de, $limba);
        self::zi($pana, $limba);
        [$a1, $l1, $z1] = array_map('intval', explode('-', $de));
        [$a2, $l2, $z2] = array_map('intval', explode('-', $pana));
        if ($a1 !== $a2) {
            return self::ziLunga($de, $limba) . ' - ' . self::ziLunga($pana, $limba);
        }
        return ($l1 === $l2 ? (string) $z1 : $z1 . ' ' . self::luna($l1, $limba)) . ' - ' . self::ziLunga($pana, $limba);
    }

    public static function numar(int $n, string $limba): string
    {
        return number_format($n, 0, '', $limba === 'ro' ? '.' : ',');
    }

    public static function bani(int $bani, string $limba): string
    {
        $semn = $bani < 0 ? '-' : '';
        $abs = abs($bani);
        if ($limba === 'ro') {
            return $semn . number_format(intdiv($abs, 100), 0, '', '.') . ',' . sprintf('%02d', $abs % 100) . ' lei';
        }
        return $semn . 'RON ' . number_format(intdiv($abs, 100), 0, '', ',') . '.' . sprintf('%02d', $abs % 100);
    }

    /**
     * Suma arătată oaspetelui: fără zecimale când e rotundă („627 lei”, „RON 627”), cu ele altfel
     * („620,79 lei”). Pe paginile site-ului și în e-mailurile oaspetelui; panoul, e-mailurile gazdei, rapoartele și
     * exporturile rămân pe `bani()`, cu două zecimale mereu.
     */
    public static function baniOaspete(int $bani, string $limba): string
    {
        if ($bani % 100 !== 0) {
            return self::bani($bani, $limba);
        }
        $n = self::numar(intdiv(abs($bani), 100), $limba);
        return ($bani < 0 ? '-' : '') . ($limba === 'ro' ? $n . ' lei' : 'RON ' . $n);
    }

    /** O zi „AAAA-LL-ZZ” (fără fus: zilele sunt calendaristice). */
    public static function zi(string $zi, string $limba): string
    {
        if (!preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $zi, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException('Zi invalidă: ' . $zi);
        }
        $luni = self::LUNI[$limba] ?? self::LUNI['en'];
        return (int) $m[3] . ' ' . $luni[(int) $m[2] - 1] . ' ' . $m[1];
    }

    /** Un moment UTC din bază („AAAA-LL-ZZ HH:MM:SS”), arătat în fusul României. */
    public static function moment(string $momentUtc, string $limba): string
    {
        $d = new \DateTimeImmutable($momentUtc, new \DateTimeZone('UTC'));
        $local = $d->setTimezone(new \DateTimeZone(self::FUS));
        return self::zi($local->format('Y-m-d'), $limba) . ', ' . $local->format('H:i');
    }
}
