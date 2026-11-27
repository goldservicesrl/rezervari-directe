<?php

declare(strict_types=1);

namespace Cazo\Email;

defined('CAZO') or exit;

/**
 * Mesajul MIME al unui e-mail (RFC 5322, 2045-2047), scris de nucleu, fără bibliotecă: antetele în UTF-8 codate
 * „cuvânt cu cuvânt” (=?UTF-8?B?...?=) doar când au caractere în afara ASCII, corpul text și HTML în quoted-printable
 * (multipart/alternative), atașamentele în base64 (multipart/mixed). Rândurile se termină cu CRLF și au cel mult 76 de
 * caractere în corp; un CR sau LF într-un antet, o adresă sau un tip de atașament greșit opresc mesajul (fără injecții
 * de antete).
 */
final class Mime
{
    /** Adresa acceptată în antete și în dialogul SMTP: o singură adresă, fără spații, ghilimele, paranteze sau rânduri noi. */
    public static function adresa(string $a): string
    {
        $a = trim($a);
        if ($a === '' || strlen($a) > 254 || preg_match('~^[^\s<>()",;:\\\\@\x00-\x1f\x7f]+@[A-Za-z0-9.\-\[\]:]+$~', $a) !== 1) {
            throw new \RuntimeException('Adresa de e-mail nu e validă: ' . preg_replace('~[\x00-\x1f\x7f]~', '?', $a));
        }
        return $a;
    }

    /**
     * Antetele și corpul. `antete` e lista rândurilor de antet, fără To și Subject când `pentruMail` (mail() le primește
     * separat); `subiect` e subiectul codat.
     *
     * @return array{antete:list<string>, corp:string, subiect:string}
     */
    public static function compune(Mesaj $m, ?int $acum = null, bool $pentruMail = false): array
    {
        $deLa = self::adresa($m->deLa);
        $catre = self::adresa($m->catre);
        $domeniu = substr($deLa, (int) strrpos($deLa, '@') + 1);
        $subiect = self::antet($m->subiect);
        $antete = ['Date: ' . gmdate('D, d M Y H:i:s', $acum ?? time()) . ' +0000'];
        if (!$pentruMail) {
            $antete[] = 'To: <' . $catre . '>';
        }
        $antete[] = 'From: ' . self::cuNume($deLa, $m->numeDeLa);
        if ($m->raspunsLa !== null && trim($m->raspunsLa) !== '') {
            $antete[] = 'Reply-To: <' . self::adresa($m->raspunsLa) . '>';
        }
        if (!$pentruMail) {
            $antete[] = 'Subject: ' . $subiect;
        }
        $antete[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domeniu . '>';
        $antete[] = 'MIME-Version: 1.0';
        // Antetele în plus ale mesajului (dezabonarea cu un clic): doar nume obișnuite, niciunul dintre cele scrise aici.
        foreach ($m->antete as $nume => $valoare) {
            if (preg_match('~^[A-Za-z][A-Za-z0-9\-]{1,60}$~', (string) $nume) !== 1
                || in_array(strtolower((string) $nume), ['date', 'to', 'from', 'reply-to', 'subject', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding', 'cc', 'bcc'], true)) {
                throw new \RuntimeException('Antet de e-mail nepermis.');
            }
            $antete[] = $nume . ': ' . self::antet((string) $valoare);
        }

        $parti = [];
        if ($m->text !== '') {
            $parti[] = self::parteText('text/plain', $m->text);
        }
        if ($m->html !== '') {
            $parti[] = self::parteText('text/html', $m->html);
        }
        if ($parti === []) {
            $parti[] = self::parteText('text/plain', '');
        }
        $continut = count($parti) === 1 ? $parti[0] : self::multipart('alternative', $parti);
        if ($m->atasamente !== []) {
            $toate = [$continut];
            foreach ($m->atasamente as $a) {
                $toate[] = self::atasament((string) $a['nume'], (string) $a['continut'], (string) $a['tip']);
            }
            $continut = self::multipart('mixed', $toate);
        }
        [$cap, $corp] = $continut;
        return ['antete' => array_merge($antete, $cap), 'corp' => $corp, 'subiect' => $subiect];
    }

    /** Mesajul întreg, gata de trimis prin DATA (fără punctele de la începutul rândurilor, puse de clientul SMTP). */
    public static function brut(Mesaj $m, ?int $acum = null): string
    {
        $c = self::compune($m, $acum);
        return implode("\r\n", $c['antete']) . "\r\n\r\n" . $c['corp'];
    }

    /**
     * Valoarea unui antet: ASCII tipăribil rămâne cum e; altfel cuvinte codate base64 de cel mult 75 de caractere,
     * despărțite pe rânduri (folding), fără să rupă un caracter UTF-8.
     */
    public static function antet(string $v): string
    {
        if (preg_match('~[\r\n]~', $v) === 1) {
            throw new \RuntimeException('Antetul e-mailului are un rând nou.');
        }
        if (preg_match('~^[\x20-\x7e]*$~', $v) === 1 && strlen($v) <= 900) {
            return $v;
        }
        if (!mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
        }
        $bucati = [];
        $b = '';
        foreach (mb_str_split($v, 1, 'UTF-8') as $ch) {
            if (strlen($b . $ch) > 45) {
                $bucati[] = $b;
                $b = '';
            }
            $b .= $ch;
        }
        $bucati[] = $b;
        return implode("\r\n ", array_map(static fn (string $x): string => '=?UTF-8?B?' . base64_encode($x) . '?=', $bucati));
    }

    /** „Nume” <adresa>: numele ASCII între ghilimele (cu \ și " scăpate), altfel codat. */
    private static function cuNume(string $adresa, string $nume): string
    {
        $nume = trim(preg_replace('~[\r\n\t]+~', ' ', $nume) ?? '');
        if ($nume === '') {
            return '<' . $adresa . '>';
        }
        $n = preg_match('~^[\x20-\x7e]*$~', $nume) === 1 ? '"' . addcslashes($nume, '"\\') . '"' : self::antet($nume);
        return $n . ' <' . $adresa . '>';
    }

    /** @return array{0:list<string>, 1:string} */
    private static function parteText(string $tip, string $text): array
    {
        return [['Content-Type: ' . $tip . '; charset=UTF-8', 'Content-Transfer-Encoding: quoted-printable'], self::qp($text)];
    }

    /** Quoted-printable cu rânduri CRLF: întâi sfârșiturile de rând aduse la CRLF, apoi codarea PHP (rânduri de cel mult 76). */
    public static function qp(string $text): string
    {
        $text = preg_replace("~\r\n?|\n~", "\r\n", $text) ?? $text;
        return quoted_printable_encode($text);
    }

    /** @return array{0:list<string>, 1:string} */
    private static function atasament(string $nume, string $continut, string $tip): array
    {
        if (preg_match('~^[A-Za-z0-9!#$&^_.+\-]+/[A-Za-z0-9!#$&^_.+\-]+(\s*;\s*[A-Za-z0-9\-]+=[A-Za-z0-9\-._]+)*$~', $tip) !== 1) {
            throw new \RuntimeException('Tipul atașamentului nu e valid.');
        }
        $nume = trim(preg_replace('~[\x00-\x1f\x7f"\\\\/]+~', '', $nume) ?? '');
        $nume = $nume === '' ? 'atasament' : $nume;
        $n = preg_match('~^[\x20-\x7e]*$~', $nume) === 1 ? $nume : str_replace("\r\n ", '', self::antet($nume));
        return [['Content-Type: ' . $tip . '; name="' . $n . '"', 'Content-Transfer-Encoding: base64', 'Content-Disposition: attachment; filename="' . $n . '"'],
            rtrim(chunk_split(base64_encode($continut), 76, "\r\n"), "\r\n")];
    }

    /**
     * @param list<array{0:list<string>, 1:string}> $parti
     * @return array{0:list<string>, 1:string}
     */
    private static function multipart(string $fel, array $parti): array
    {
        $limita = '=_cazo_' . bin2hex(random_bytes(12));
        $corp = '';
        foreach ($parti as [$cap, $c]) {
            $corp .= '--' . $limita . "\r\n" . implode("\r\n", $cap) . "\r\n\r\n" . $c . "\r\n";
        }
        $corp .= '--' . $limita . "--\r\n";
        return [['Content-Type: multipart/' . $fel . '; boundary="' . $limita . '"'], $corp];
    }
}
