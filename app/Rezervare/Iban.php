<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

defined('CAZO') or exit;

/**
 * IBAN-ul contului pentru transferul bancar: forma (ISO 13616: două litere de țară, două cifre de control, apoi
 * litere și cifre, cel mult 34 de caractere în total), lungimea țării, unde o știm, și cifrele de control (mod 97,
 * ISO 7064). IBAN-ul românesc are 24 de caractere; celelalte țări se acceptă cu lungimea lor din registrul SWIFT, iar o
 * țară din afara tabelului trece doar cu forma generală și cifrele de control bune.
 */
final class Iban
{
    /** Lungimea IBAN-ului pe țară (registrul IBAN al SWIFT). */
    public const LUNGIMI = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20, 'BE' => 16, 'BG' => 22, 'BH' => 22, 'BR' => 29, 'BY' => 28, 'CH' => 21,
        'CR' => 22, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18, 'DO' => 28, 'EE' => 20, 'EG' => 29, 'ES' => 24, 'FI' => 18, 'FO' => 18, 'FR' => 27,
        'GB' => 22, 'GE' => 22, 'GI' => 23, 'GL' => 18, 'GR' => 27, 'GT' => 28, 'HR' => 21, 'HU' => 28, 'IE' => 22, 'IL' => 23, 'IQ' => 23, 'IS' => 26,
        'IT' => 27, 'JO' => 30, 'KW' => 30, 'KZ' => 20, 'LB' => 28, 'LC' => 32, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21, 'LY' => 25, 'MC' => 27,
        'MD' => 24, 'ME' => 22, 'MK' => 19, 'MR' => 27, 'MT' => 31, 'MU' => 30, 'NL' => 18, 'NO' => 15, 'PK' => 24, 'PL' => 28, 'PS' => 29, 'PT' => 25,
        'QA' => 29, 'RO' => 24, 'RS' => 22, 'SA' => 24, 'SC' => 31, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27, 'ST' => 25, 'SV' => 28, 'TL' => 23,
        'TN' => 24, 'TR' => 26, 'UA' => 29, 'VA' => 22, 'VG' => 24, 'XK' => 20,
    ];

    /** IBAN-ul fără spații, cu litere mari (cum se păstrează și cum se copiază). */
    public static function curata(string $iban): string
    {
        return strtoupper((string) preg_replace('~[\s\x{00A0}.\-]+~u', '', $iban));
    }

    /** Forma, lungimea țării și cifrele de control. */
    public static function valid(string $iban): bool
    {
        $i = self::curata($iban);
        if (!preg_match('~^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$~', $i)) {
            return false;
        }
        $tara = substr($i, 0, 2);
        if (isset(self::LUNGIMI[$tara]) && strlen($i) !== self::LUNGIMI[$tara]) {
            return false;
        }
        // România: codul băncii are patru litere (BBAN = 4 litere + 16 litere sau cifre).
        if ($tara === 'RO' && !preg_match('~^RO\d{2}[A-Z]{4}[A-Z0-9]{16}$~', $i)) {
            return false;
        }
        return self::rest97(substr($i, 4) . substr($i, 0, 4)) === 1;
    }

    /** Restul la 97 al șirului cu literele scrise ca numere (A = 10 … Z = 35), calculat pe bucăți. */
    private static function rest97(string $s): int
    {
        $r = 0;
        foreach (str_split($s) as $c) {
            $n = ctype_digit($c) ? $c : (string) (ord($c) - 55);
            foreach (str_split($n) as $cifra) {
                $r = ($r * 10 + (int) $cifra) % 97;
            }
        }
        return $r;
    }

    /** IBAN-ul în grupe de câte patru, pentru citit. */
    public static function grupat(string $iban): string
    {
        return trim(chunk_split(self::curata($iban), 4, ' '));
    }

    /** Codul SWIFT/BIC: 8 sau 11 caractere (banca, țara, localitatea și, opțional, sucursala). */
    public static function swiftValid(string $swift): bool
    {
        return (bool) preg_match('~^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$~', strtoupper(trim($swift)));
    }
}
