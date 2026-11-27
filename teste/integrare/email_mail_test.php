<?php

declare(strict_types=1);

// E-mailul trimis prin funcția mail() a găzduirii (fără SMTP în panou), așa cum îl primește sendmail-ul: un singur
// fel de sfârșit de rând în tot mesajul, cel pe care PHP îl pune singur după To și Subject (CRLF implicit, LF cu
// `mail.mixed_lf_and_crlf`). Pe site-ul de test, amestecul (CRLF de la PHP, LF în antetele noastre) a ajuns în Gmail cu
// Date, From, MIME-Version și Content-Type lipite într-un singur antet, deci cu limitele MIME afișate în clar. Mesajul
// capturat se citește aici strict, despărțit doar la sfârșitul de rând așteptat: multipart/alternative (și
// multipart/mixed cu .ics), textul și HTML-ul decodate, diacriticele întregi în subiect (pliat), expeditor și corp.

use Cazo\Email\Mesaj;

/** Trimite mesajul printr-un PHP separat, cu sendmail_path spre capturator (teste/fals/mail.php); întoarce ce a primit sendmail-ul. */
$trimite = static function (Mediu $m, Mesaj $mesaj, bool $lf): array {
    $dir = $m->dosarTemporar();
    file_put_contents("$dir/mesaj.ser", serialize($mesaj));
    $fals = Mediu::radacina() . '/teste/fals/mail.php';
    $sendmail = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fals) . ' ' . escapeshellarg("--captura=$dir/captura.eml");
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . trim((string) getenv('CAZO_TEST_PHP_ARGS'))
        . ' -d ' . escapeshellarg('sendmail_path=' . $sendmail) . ' -d ' . escapeshellarg('mail.mixed_lf_and_crlf=' . ($lf ? '1' : '0'))
        . ' ' . escapeshellarg($fals) . ' ' . escapeshellarg("$dir/mesaj.ser") . ' 2>&1';
    exec($cmd, $iesire, $cod);
    egal(0, $cod, implode("\n", $iesire));
    return ['brut' => (string) file_get_contents("$dir/captura.eml"), 'args' => (string) file_get_contents("$dir/captura.eml.args")];
};

/**
 * Citirea strictă a unei părți MIME: rândurile se despart doar la $sep, antetele se despliază, valorile codate se
 * decodează; o parte multipart se împarte la limita ei, iar corpul celorlalte se decodează (quoted-printable, base64).
 */
$citeste = static function (string $text, string $sep) use (&$citeste): array {
    $i = strpos($text, $sep . $sep);
    adevarat($i !== false, 'lipsește rândul gol de după antete');
    [$cap, $corp] = [substr($text, 0, (int) $i), substr($text, (int) $i + 2 * strlen($sep))];
    $antete = [];
    foreach (preg_split('~' . preg_quote($sep, '~') . '(?![ \t])~', $cap) ?: [] as $rand) {
        adevarat(preg_match('~^([A-Za-z0-9\-]+):(.*)$~s', $rand, $p) === 1, 'antet stricat: ' . json_encode($rand));
        $v = preg_replace('~' . preg_quote($sep, '~') . '[ \t]+~', ' ', $p[2]) ?? '';
        adevarat(!str_contains($v, "\r") && !str_contains($v, "\n"), $p[1] . ': rând nou în valoare ' . json_encode($v));
        $antete[strtolower($p[1])][] = trim((string) iconv_mime_decode(trim($v), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8'));
    }
    $tip = $antete['content-type'][0] ?? 'text/plain';
    if (preg_match('~^multipart/([a-z]+);\s*boundary="([^"]+)"~', $tip, $mp) === 1) {
        $bucati = explode('--' . $mp[2], $corp);
        adevarat(count($bucati) >= 3 && $bucati[0] === '' && str_starts_with((string) end($bucati), '--'), 'limitele părții ' . $mp[1]);
        $parti = [];
        foreach (array_slice($bucati, 1, -1) as $b) {
            adevarat(str_starts_with($b, $sep) && str_ends_with($b, $sep), 'limită fără rând propriu');
            $parti[] = $citeste(substr($b, strlen($sep), -strlen($sep)), $sep);
        }
        return ['antete' => $antete, 'tip' => 'multipart/' . $mp[1], 'parti' => $parti];
    }
    $cod = $antete['content-transfer-encoding'][0] ?? '7bit';
    $continut = $cod === 'base64' ? (string) base64_decode($corp, true) : ($cod === 'quoted-printable' ? quoted_printable_decode($corp) : $corp);
    return ['antete' => $antete, 'tip' => strtolower(trim(explode(';', $tip)[0])), 'continut' => $continut];
};

/** Mesajul capturat: un singur fel de sfârșit de rând, antetele de sus o singură dată, apoi structura citită strict. */
$verifica = static function (array $captura, bool $lf) use ($citeste): array {
    $brut = $captura['brut'];
    $sep = $lf ? "\n" : "\r\n";
    if ($lf) {
        adevarat(!str_contains($brut, "\r"), 'CR în mesajul cu rânduri LF');
    } else {
        adevarat(preg_match('~\r(?!\n)|(?<!\r)\n~', $brut) === 0, 'rânduri LF amestecate cu CRLF în mesaj');
    }
    foreach (explode($sep, $brut) as $l) {
        adevarat(strlen($l) <= 998, 'rând mai lung de 998 de octeți');
    }
    $r = $citeste($brut, $sep);
    foreach (['to', 'subject', 'date', 'from', 'message-id', 'mime-version', 'content-type'] as $a) {
        egal(1, count($r['antete'][$a] ?? []), "antetul $a o singură dată");
    }
    egal('1.0', $r['antete']['mime-version'][0]);
    egal('-frezervari@pensiunea-exemplu.ro', $captura['args']);
    return $r;
};

/** Textul unei părți, cu rândurile aduse la LF (quoted-printable le are în CRLF sau LF, după mesaj). */
$text = static fn (array $parte): string => str_replace("\r\n", "\n", (string) $parte['continut']);

$fiecare = static function (callable $f): void {
    if (PHP_OS_FAMILY === 'Windows') {
        return; // Pe Windows, mail() vorbește SMTP direct, fără sendmail_path.
    }
    foreach ([false, true] as $lf) {
        $f($lf);
    }
};

return [
    'alerta de temă prin mail(): multipart/alternative, subiectul și expeditorul cu diacritice, textul și HTML-ul decodate' => function (Mediu $m) use ($trimite, $verifica, $text, $fiecare): void {
        $app = $m->aplicatie();
        $app->setari()->pune('email.expeditor', ['adresa' => 'rezervari@pensiunea-exemplu.ro', 'nume' => 'Pensiunea „Țărm” de la munte']);
        $mesaj = $app->sabloaneEmail()->mesaj('alerta_tema', 'ro', ['tema' => 'aurora', 'motiv' => 'memorie'], 'gazda@exemplu.ro');
        egal('Site-ul a afișat o pagină cu tema de rezervă', $mesaj->subiect);
        $fiecare(function (bool $lf) use ($m, $mesaj, $trimite, $verifica, $text): void {
            $r = $verifica($trimite($m, $mesaj, $lf), $lf);
            egal('gazda@exemplu.ro', $r['antete']['to'][0]);
            egal('Site-ul a afișat o pagină cu tema de rezervă', $r['antete']['subject'][0]);
            egal('Pensiunea „Țărm” de la munte <rezervari@pensiunea-exemplu.ro>', $r['antete']['from'][0]);
            egal('multipart/alternative', $r['tip']);
            egal(['text/plain', 'text/html'], array_column($r['parti'], 'tip'));
            egal(str_replace("\r\n", "\n", $mesaj->text), $text($r['parti'][0]));
            egal(str_replace("\r\n", "\n", $mesaj->html), $text($r['parti'][1]));
            contine($text($r['parti'][0]), 'aurora');
            contine($text($r['parti'][0]), 'ă');
        });
    },

    'cu atașament .ics prin mail(): multipart/mixed, subiectul lung pliat, calendarul întreg' => function (Mediu $m) use ($trimite, $verifica, $text, $fiecare): void {
        $subiect = 'Confirmarea rezervării CZ-12: Brașov, 3 nopți, cameră dublă cu vedere spre munte și mic dejun';
        $ics = "BEGIN:VCALENDAR\r\nSUMMARY:Sejur la Pensiunea Țărm\r\nEND:VCALENDAR\r\n";
        $mesaj = new Mesaj('gazda@exemplu.ro', 'rezervari@pensiunea-exemplu.ro', 'Pensiunea „Țărm” & Co', 'oaspete@exemplu.ro', $subiect,
            '<p>Bună ziua, <b>Ștefan</b>!</p>', "Bună ziua, Ștefan!\n\n" . str_repeat('lung ', 60), [['nume' => 'cz-12.ics', 'continut' => $ics, 'tip' => 'text/calendar; charset=utf-8; method=PUBLISH']]);
        $fiecare(function (bool $lf) use ($m, $mesaj, $subiect, $ics, $trimite, $verifica, $text): void {
            $c = $trimite($m, $mesaj, $lf);
            // Subiectul are mai multe cuvinte codate: cu CRLF, pliate pe rânduri; cu LF, PHP pune spații în locul plierii (lasă
            // în subiect doar plierea CRLF), deci cuvintele rămân pe un rând.
            contine($c['brut'], $lf ? '?=  =?UTF-8?B?' : "?=\r\n =?UTF-8?B?");
            $r = $verifica($c, $lf);
            egal($subiect, $r['antete']['subject'][0]);
            egal('Pensiunea „Țărm” & Co <rezervari@pensiunea-exemplu.ro>', $r['antete']['from'][0]);
            egal('<oaspete@exemplu.ro>', $r['antete']['reply-to'][0]);
            egal('multipart/mixed', $r['tip']);
            egal(['multipart/alternative', 'text/calendar'], array_column($r['parti'], 'tip'));
            [$alt, $cal] = $r['parti'];
            egal(['text/plain', 'text/html'], array_column($alt['parti'], 'tip'));
            egal("Bună ziua, Ștefan!\n\n" . str_repeat('lung ', 60), $text($alt['parti'][0]));
            egal('<p>Bună ziua, <b>Ștefan</b>!</p>', $text($alt['parti'][1]));
            egal($ics, $cal['continut']);
            egal('attachment; filename="cz-12.ics"', $cal['antete']['content-disposition'][0]);
        });
    },
];
