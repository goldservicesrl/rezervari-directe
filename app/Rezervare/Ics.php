<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

defined('CAZO') or exit;

/**
 * Fișierul .ics al sejurului (RFC 5545): un eveniment de la ora cazării din ziua sosirii până la ora plecării,
 * în fusul Europe/Bucharest (cu VTIMEZONE pentru ora de vară și de iarnă), cu adresa și codul rezervării.
 */
final class Ics
{
    /**
     * @param array{ref:string, sosire:string, plecare:string, checkin:string, plecare_ora:string, titlu:string, descriere:string,
     *   locatie:string, url:string, domeniu:string, acum:int} $d
     */
    public static function eveniment(array $d): string
    {
        $ora = static fn (string $o, string $implicit): string => preg_match('~^([01]\d|2[0-3]):([0-5]\d)$~', $o, $m) ? $m[1] . $m[2] . '00' : $implicit;
        $zi = static fn (string $z): string => str_replace('-', '', $z);
        $linii = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Cazo//Rezervari//RO',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VTIMEZONE',
            'TZID:Europe/Bucharest',
            'BEGIN:STANDARD',
            'DTSTART:19701025T040000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'TZOFFSETFROM:+0300',
            'TZOFFSETTO:+0200',
            'TZNAME:EET',
            'END:STANDARD',
            'BEGIN:DAYLIGHT',
            'DTSTART:19700329T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0300',
            'TZNAME:EEST',
            'END:DAYLIGHT',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:' . strtolower($d['ref']) . '@' . self::text($d['domeniu']),
            'DTSTAMP:' . gmdate('Ymd\THis\Z', $d['acum']),
            ...((int) ($d['secventa'] ?? 0) > 0 ? ['SEQUENCE:' . (int) $d['secventa']] : []),
            'DTSTART;TZID=Europe/Bucharest:' . $zi($d['sosire']) . 'T' . $ora($d['checkin'], '140000'),
            'DTEND;TZID=Europe/Bucharest:' . $zi($d['plecare']) . 'T' . $ora($d['plecare_ora'], '110000'),
            'SUMMARY:' . self::text($d['titlu']),
            'DESCRIPTION:' . self::text($d['descriere']),
            'LOCATION:' . self::text($d['locatie']),
            'URL:' . self::text($d['url']),
            'STATUS:CONFIRMED',
            'TRANSP:OPAQUE',
            'END:VEVENT',
            'END:VCALENDAR',
        ];
        return implode("\r\n", array_map(self::pliaza(...), $linii)) . "\r\n";
    }

    /** Escaparea textului (RFC 5545.11). */
    public static function text(string $t): string
    {
        $t = str_replace(["\r\n", "\r"], "\n", $t);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $t);
    }

    /** Liniile peste 75 de octeți se pliază (fără să rupă un caracter UTF-8). */
    private static function pliaza(string $linie): string
    {
        $rez = '';
        $curent = '';
        foreach (mb_str_split($linie) as $c) {
            if (strlen($curent) + strlen($c) > ($rez === '' ? 75 : 74)) {
                $rez .= ($rez === '' ? '' : "\r\n ") . $curent;
                $curent = '';
            }
            $curent .= $c;
        }
        return $rez . ($rez === '' ? '' : "\r\n ") . $curent;
    }
}
