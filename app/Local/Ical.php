<?php

declare(strict_types=1);

namespace Cazo\Local;

defined('CAZO') or exit;

/**
 * Calendarele iCal (RFC 5545) ale site-ului fără cheie EasyCheck, ca funcții pure.
 *
 * Citirea e prudentă: textul primit vine de pe alt server, deci are limite (mărimea, numărul de linii și de
 * evenimente, lungimea liniei, intervalul de ani) și ia din el doar perioadele ocupate ale evenimentelor (DTSTART,
 * DTEND sau DURATION, STATUS, UID); restul se ignoră. Un fișier fără VCALENDAR se refuză (UnexpectedValueException),
 * ca o pagină de eroare a platformei să nu golească nopțile ocupate.
 *
 * Scrierea dă calendarul site-ului: nopțile fără nicio unitate liberă după rezervările site-ului și zilele închise de
 * gazdă, câte un eveniment pe fiecare șir de nopți, cu datele ca zile (VALUE=DATE), cum le citesc Booking, Airbnb și
 * EasyCheck.
 */
final class Ical
{
    public const MARIME_MAXIMA = 2 * 1024 * 1024;
    public const LINII_MAXIME = 100_000;
    public const EVENIMENTE_MAXIME = 5_000;
    public const LINIE_MAXIMA = 8_000;
    /** Un eveniment ocupă cel mult un an (o greșeală a sursei nu închide calendarul pe ani întregi). */
    public const NOPTI_MAXIME = 366;

    /**
     * Perioadele ocupate din calendar, [de, pana) ca zile (AAAA-LL-ZZ), doar cele care ating fereastra [$de, $pana).
     * @return list<array{de:string, pana:string, uid:string}>
     */
    public static function citeste(string $text, string $de, string $pana): array
    {
        if (strlen($text) > self::MARIME_MAXIMA) {
            throw new \UnexpectedValueException('marime');
        }
        // UTF-8 cu BOM, sfârșituri de rând de orice fel; liniile continuate (încep cu spațiu sau tab) se lipesc.
        $text = (string) preg_replace('~^\xEF\xBB\xBF~', '', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace("~\n[ \t]~", '', $text);
        $linii = explode("\n", $text);
        if (count($linii) > self::LINII_MAXIME) {
            throw new \UnexpectedValueException('marime');
        }
        $inceput = false;
        $sfarsit = false;
        $rez = [];
        $ev = null;
        $evenimente = 0;
        foreach ($linii as $linie) {
            if (strlen($linie) > self::LINIE_MAXIMA) {
                $linie = substr($linie, 0, self::LINIE_MAXIMA);
            }
            $linie = rtrim($linie);
            if ($linie === '') {
                continue;
            }
            $sus = strtoupper($linie);
            if ($sus === 'BEGIN:VCALENDAR') {
                $inceput = true;
                continue;
            }
            if ($sus === 'END:VCALENDAR') {
                $sfarsit = true;
                continue;
            }
            if (!$inceput) {
                continue;
            }
            if ($sus === 'BEGIN:VEVENT') {
                if (++$evenimente > self::EVENIMENTE_MAXIME) {
                    throw new \UnexpectedValueException('evenimente');
                }
                $ev = [];
                continue;
            }
            if ($sus === 'END:VEVENT') {
                if (is_array($ev)) {
                    $p = self::perioada($ev);
                    if ($p !== null && $p['pana'] > $de && $p['de'] < $pana) {
                        $rez[] = $p;
                    }
                }
                $ev = null;
                continue;
            }
            if (!is_array($ev)) {
                continue;
            }
            $doua = strpos($linie, ':');
            if ($doua === false) {
                continue;
            }
            $cap = substr($linie, 0, $doua);
            $valoare = substr($linie, $doua + 1);
            $parti = explode(';', $cap);
            $nume = strtoupper(trim($parti[0]));
            if (!in_array($nume, ['DTSTART', 'DTEND', 'DURATION', 'STATUS', 'UID', 'TRANSP'], true) || isset($ev[$nume])) {
                continue;
            }
            $param = [];
            foreach (array_slice($parti, 1) as $x) {
                $kv = explode('=', $x, 2);
                if (count($kv) === 2) {
                    $param[strtoupper(trim($kv[0]))] = trim($kv[1], " \"");
                }
            }
            $ev[$nume] = ['v' => trim($valoare), 'p' => $param];
        }
        if (!$inceput || !$sfarsit) {
            throw new \UnexpectedValueException('forma');
        }
        return $rez;
    }

    /** Perioada unui eveniment, sau null (anulat, transparent, fără început valid, invers). */
    private static function perioada(array $ev): ?array
    {
        if (strtoupper($ev['STATUS']['v'] ?? '') === 'CANCELLED' || strtoupper($ev['TRANSP']['v'] ?? '') === 'TRANSPARENT') {
            return null;
        }
        $start = isset($ev['DTSTART']) ? self::zi($ev['DTSTART']['v'], $ev['DTSTART']['p']) : null;
        if ($start === null) {
            return null;
        }
        $sfarsit = isset($ev['DTEND']) ? self::zi($ev['DTEND']['v'], $ev['DTEND']['p']) : null;
        if ($sfarsit === null && isset($ev['DURATION']) && preg_match('~^P(?:(\d{1,4})W)?(?:(\d{1,4})D)?~', strtoupper($ev['DURATION']['v']), $m)) {
            $zile = (int) ($m[1] ?? 0) * 7 + (int) ($m[2] ?? 0);
            $sfarsit = gmdate('Y-m-d', strtotime($start . ' 00:00:00 UTC') + max(1, $zile) * 86400);
        }
        // Fără sfârșit: o zi (RFC 5545.1, la datele fără oră).
        $sfarsit ??= Depozit::ziUrmatoare($start);
        if ($sfarsit <= $start) {
            // Un eveniment cu ore în aceeași zi (de exemplu 14:00-23:00) ocupă noaptea acelei zile.
            $sfarsit = Depozit::ziUrmatoare($start);
        }
        $maxim = gmdate('Y-m-d', strtotime($start . ' 00:00:00 UTC') + self::NOPTI_MAXIME * 86400);
        if ($sfarsit > $maxim) {
            $sfarsit = $maxim;
        }
        $uid = mb_substr((string) ($ev['UID']['v'] ?? ''), 0, 255);
        return ['de' => $start, 'pana' => $sfarsit, 'uid' => $uid];
    }

    /**
     * Ziua unei valori DATE sau DATE-TIME: „20261201”, „20261201T140000”, „20261201T140000Z”, cu TZID ignorat (ziua din
     * text e ziua locală a platformei). Anii în afara 2000-2100 se refuză.
     */
    private static function zi(string $v, array $p): ?string
    {
        if (!preg_match('~^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})(Z)?)?$~', strtoupper(trim($v)), $m)) {
            return null;
        }
        [$an, $luna, $zi] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($an < 2000 || $an > 2100 || !checkdate($luna, $zi, $an)) {
            return null;
        }
        $d = sprintf('%04d-%02d-%02d', $an, $luna, $zi);
        // O oră UTC se trece în ora României: 22:30Z din 31 ianuarie e deja 1 februarie la București.
        if (($m[7] ?? '') === 'Z') {
            $t = (new \DateTimeImmutable(sprintf('%s %s:%s:%s', $d, $m[4], $m[5], $m[6]), new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Bucharest'));
            $d = $t->format('Y-m-d');
        }
        return $d;
    }

    /**
     * Nopțile ocupate, pe fiecare zi din [de, pana): câte evenimente se suprapun în acea noapte.
     * @param list<array{de:string, pana:string}> $perioade
     * @return array<string,int>
     */
    public static function nopti(array $perioade, string $de, string $pana): array
    {
        $rez = [];
        foreach ($perioade as $p) {
            foreach (Depozit::zile(max($de, $p['de']), min($pana, $p['pana'])) as $zi) {
                $rez[$zi] = ($rez[$zi] ?? 0) + 1;
            }
        }
        ksort($rez);
        return $rez;
    }

    /**
     * Calendarul site-ului pentru o cameră: câte un eveniment pe fiecare șir de nopți ocupate.
     * @param list<string> $nopti nopțile ocupate, sortate
     */
    public static function scrie(array $nopti, string $camera, string $domeniu, string $numeCalendar, string $sumar, int $acum): string
    {
        $linii = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Cazo//Cazo//RO', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . self::text($numeCalendar)];
        $stamp = gmdate('Ymd\THis\Z', $acum);
        foreach (self::siruri($nopti) as [$de, $pana]) {
            $linii[] = 'BEGIN:VEVENT';
            $linii[] = 'UID:' . substr(hash('sha256', $camera . '|' . $de), 0, 32) . '@' . $domeniu;
            $linii[] = 'DTSTAMP:' . $stamp;
            $linii[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $de);
            $linii[] = 'DTEND;VALUE=DATE:' . str_replace('-', '', $pana);
            $linii[] = 'SUMMARY:' . self::text($sumar);
            $linii[] = 'TRANSP:OPAQUE';
            $linii[] = 'END:VEVENT';
        }
        $linii[] = 'END:VCALENDAR';
        return implode("\r\n", array_map(self::pliaza(...), $linii)) . "\r\n";
    }

    /** Nopțile sortate, în șiruri [prima noapte, ziua de după ultima]. @return list<array{0:string, 1:string}> */
    public static function siruri(array $nopti): array
    {
        sort($nopti);
        $rez = [];
        $de = null;
        $ultima = null;
        foreach (array_values(array_unique($nopti)) as $zi) {
            if ($de !== null && Depozit::ziUrmatoare($ultima) === $zi) {
                $ultima = $zi;
                continue;
            }
            if ($de !== null) {
                $rez[] = [$de, Depozit::ziUrmatoare($ultima)];
            }
            $de = $zi;
            $ultima = $zi;
        }
        if ($de !== null) {
            $rez[] = [$de, Depozit::ziUrmatoare($ultima)];
        }
        return $rez;
    }

    public static function text(string $t): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $t);
    }

    /** Liniile de peste 75 de octeți se pliază (RFC 5545), fără să rupă un caracter UTF-8. */
    private static function pliaza(string $linie): string
    {
        $rez = '';
        $curent = '';
        foreach (mb_str_split($linie) as $ch) {
            if (strlen($curent) + strlen($ch) > ($rez === '' ? 75 : 74)) {
                $rez .= ($rez === '' ? '' : "\r\n ") . $curent;
                $curent = '';
            }
            $curent .= $ch;
        }
        return $rez . ($rez === '' ? '' : "\r\n ") . $curent;
    }
}
