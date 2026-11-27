<?php

declare(strict_types=1);

// Clientul SMTP al nucleului, care înlocuiește PHPMailer: prin serverul SMTP fals, fără TLS, cu STARTTLS și cu
// TLS de la conectare (certificatul de probă făcut aici); autentificarea PLAIN și LOGIN; certificatul neîncrezut și
// serverul fără STARTTLS opresc trimiterea; mesajul MIME (UTF-8, text și HTML, atașamentul, punctele de la început
// de rând, antetele fără injecții).

use Cazo\Email\Mesaj;
use Cazo\Email\Mime;
use Cazo\Email\TransportSmtp;

/** Certificatul de probă pentru „localhost”, autosemnat, cu cheia: un PEM pentru server, unul pentru client (cafile). */
$certificat = static function (string $dir): array {
    $cheie = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $csr = openssl_csr_new(['commonName' => 'localhost'], $cheie, ['digest_alg' => 'sha256']);
    $x = openssl_csr_sign($csr, null, $cheie, 2, ['digest_alg' => 'sha256']);
    openssl_x509_export($x, $pem);
    openssl_pkey_export($cheie, $pemCheie);
    file_put_contents("$dir/server.pem", $pem . $pemCheie);
    file_put_contents("$dir/ca.pem", $pem);
    return ['server' => "$dir/server.pem", 'ca' => "$dir/ca.pem"];
};

$mesaj = static fn (array $atasamente = []): Mesaj => new Mesaj('oaspete@exemplu.ro', 'rezervari@pensiunea-exemplu.ro', 'Pensiunea „Țărm” & Co',
    'gazda@exemplu.ro', 'Confirmarea rezervării CZ-12: Brașov, 3 nopți, cameră dublă cu vedere spre munte și mic dejun', '<p>Bună ziua, <b>Ștefan</b>!</p>',
    "Bună ziua, Ștefan!\n.\n.un rând care începe cu punct\n" . str_repeat('lung ', 60), $atasamente);

$smtp = static fn (FalsSmtp $s, string $sec, string $utilizator = 'proba'): array => ['gazda' => '127.0.0.1', 'port' => $s->port, 'securitate' => $sec, 'utilizator' => $utilizator];

return [
    'fără TLS: AUTH PLAIN, plicul, antetele UTF-8, text și HTML, atașamentul .ics, punctele de început de rând' => function (Mediu $m) use ($mesaj, $smtp): void {
        $s = FalsSmtp::porneste($m->dosarTemporar());
        $t = new TransportSmtp($smtp($s, 'niciuna'), 'parolă-secretă');
        egal('smtp', $t->nume());
        $t->trimite($mesaj([['nume' => 'cz-12.ics', 'continut' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", 'tip' => 'text/calendar; charset=utf-8; method=PUBLISH']]));
        $p = $s->mesaje();
        egal(1, count($p));
        $a = $p[0]['antete'];
        egal('<rezervari@pensiunea-exemplu.ro>', $a['x-fals-de']);
        egal('<oaspete@exemplu.ro>', $a['x-fals-catre']);
        egal('Confirmarea rezervării CZ-12: Brașov, 3 nopți, cameră dublă cu vedere spre munte și mic dejun', $a['subject']);
        egal('Pensiunea „Țărm” & Co <rezervari@pensiunea-exemplu.ro>', $a['from']);
        egal('<gazda@exemplu.ro>', $a['reply-to']);
        egal('1.0', $a['mime-version']);
        contine($a['content-type'], 'multipart/mixed');
        contine($p[0]['corp'], "Bună ziua, Ștefan!\r\n.\r\n.un rând care începe cu punct");
        contine($p[0]['corp'], '<p>Bună ziua, <b>Ștefan</b>!</p>');
        contine($p[0]['brut'], 'Content-Type: multipart/alternative');
        contine($p[0]['brut'], 'Content-Disposition: attachment; filename="cz-12.ics"');
        egal(["BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n"], $p[0]['ics']);
        // Rândurile mesajului: CRLF, cel mult 998 de octeți; antetele codate, fără caractere în afara ASCII.
        foreach (explode("\r\n", $p[0]['brut']) as $l) {
            adevarat(strlen($l) <= 998 && !str_contains($l, "\n") && !str_contains($l, "\r"), 'rând prea lung sau LF singur');
        }
        [$cap] = explode("\r\n\r\n", $p[0]['brut'], 2);
        adevarat(preg_match('~^[\x09\x0a\x0d\x20-\x7e]*$~', $cap) === 1, 'antetele sunt ASCII');
        egal([['PLAIN', 'proba', 'parolă-secretă', false]], array_map(static fn (string $l) => json_decode($l, true), file("{$s->dir}/auth.log", FILE_IGNORE_NEW_LINES)));
    },

    'STARTTLS cerut: legătura se criptează înainte de AUTH LOGIN și de mesaj; certificatul se verifică' => function (Mediu $m) use ($certificat, $mesaj, $smtp): void {
        $d = $m->dosarTemporar();
        $c = $certificat($d);
        $s = FalsSmtp::porneste("$d/posta", ['tls' => 'starttls', 'cert' => $c['server'], 'auth' => 'LOGIN']);
        (new TransportSmtp($smtp($s, 'tls', 'gazda@pensiunea-exemplu.ro'), 'p@rola:cu spații', ['cafile' => $c['ca'], 'peer_name' => 'localhost']))->trimite($mesaj());
        egal(1, count($s->mesaje()));
        egal([['LOGIN', 'gazda@pensiunea-exemplu.ro', 'p@rola:cu spații', true]], array_map(static fn (string $l) => json_decode($l, true), file("{$s->dir}/auth.log", FILE_IGNORE_NEW_LINES)));
        $dialog = file("{$s->dir}/dialog.log", FILE_IGNORE_NEW_LINES);
        egal('STARTTLS', $dialog[1], 'STARTTLS imediat după primul EHLO');
        // Fără certificatul de probă printre cele de încredere, trimiterea se oprește înainte de parolă.
        $s2 = FalsSmtp::porneste("$d/posta2", ['tls' => 'starttls', 'cert' => $c['server']]);
        arunca(fn () => (new TransportSmtp($smtp($s2, 'tls'), 'secret', ['peer_name' => 'localhost']))->trimite($mesaj()), \RuntimeException::class, 'negocierea TLS a eșuat');
        adevarat(!is_file("{$s2->dir}/auth.log"), 'parola nu a plecat');
        egal([], $s2->mesaje());
        // Numele din certificat trebuie să fie al serverului.
        $s3 = FalsSmtp::porneste("$d/posta3", ['tls' => 'starttls', 'cert' => $c['server']]);
        arunca(fn () => (new TransportSmtp($smtp($s3, 'tls'), 'secret', ['cafile' => $c['ca'], 'peer_name' => 'alt-server.ro']))->trimite($mesaj()), \RuntimeException::class, 'negocierea TLS a eșuat');
    },

    'TLS de la conectare (SSL, portul 465)' => function (Mediu $m) use ($certificat, $mesaj, $smtp): void {
        $d = $m->dosarTemporar();
        $c = $certificat($d);
        $s = FalsSmtp::porneste("$d/posta", ['tls' => 'ssl', 'cert' => $c['server']]);
        (new TransportSmtp($smtp($s, 'ssl'), 'secret', ['cafile' => $c['ca'], 'peer_name' => 'localhost']))->trimite($mesaj());
        egal(1, count($s->mesaje()));
        egal([['PLAIN', 'proba', 'secret', true]], array_map(static fn (string $l) => json_decode($l, true), file("{$s->dir}/auth.log", FILE_IGNORE_NEW_LINES)));
        arunca(fn () => (new TransportSmtp($smtp($s, 'ssl'), 'secret', ['peer_name' => 'localhost']))->trimite($mesaj()), \RuntimeException::class, 'conectarea la 127.0.0.1');
    },

    'securitatea „tls” la un server fără STARTTLS, un răspuns de refuz și un port închis opresc trimiterea, fără parola în mesaj' => function (Mediu $m) use ($mesaj, $smtp): void {
        $s = FalsSmtp::porneste($m->dosarTemporar());
        $e = arunca(fn () => (new TransportSmtp($smtp($s, 'tls'), 'secret'))->trimite($mesaj()), \RuntimeException::class, 'serverul nu oferă STARTTLS');
        contine($e->getMessage(), 'Trimiterea e-mailului a eșuat: ');
        egal([], $s->mesaje());
        // Fără utilizator: fără AUTH.
        (new TransportSmtp($smtp($s, 'niciuna', ''), null))->trimite($mesaj());
        adevarat(!is_file("{$s->dir}/auth.log"));
        egal(1, count($s->mesaje()));
        $port = ServerTest::portLiber();
        arunca(fn () => (new TransportSmtp(['gazda' => '127.0.0.1', 'port' => $port, 'securitate' => 'niciuna', 'utilizator' => ''], null))->trimite($mesaj()), \RuntimeException::class, 'conectarea la 127.0.0.1:' . $port);
        $e = arunca(fn () => (new TransportSmtp(['gazda' => "127.0.0.1\r\nRCPT", 'port' => 25, 'securitate' => 'niciuna', 'utilizator' => ''], null))->trimite($mesaj()), \RuntimeException::class, 'gazda SMTP nu e validă');
        lipseste($e->getMessage(), 'secret');
    },

    'MIME: antetele nu primesc rânduri noi, adresele și tipurile atașamentelor se verifică; textul fără HTML e o singură parte' => function (Mediu $m): void {
        $baza = ['oaspete@exemplu.ro', 'rezervari@exemplu.ro', 'Nume', null, 'Subiect', '', 'Doar text, cu „ghilimele”.'];
        $b = Mime::brut(new Mesaj(...$baza), 1_795_000_000);
        contine($b, "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable");
        lipseste($b, 'multipart');
        lipseste($b, 'Reply-To');
        contine($b, 'Date: ' . gmdate('D, d M Y H:i:s', 1_795_000_000) . ' +0000');
        contine($b, 'From: "Nume" <rezervari@exemplu.ro>');
        egal("Doar text, cu „ghilimele”.", quoted_printable_decode(explode("\r\n\r\n", $b, 2)[1]));
        $alt = $baza;
        $alt[4] = "Subiect\r\nBcc: altcineva@exemplu.ro";
        arunca(fn () => Mime::brut(new Mesaj(...$alt)), \RuntimeException::class, 'rând nou');
        foreach (["oaspete@exemplu.ro\r\nBcc: x@y.ro", 'doi@a.ro, trei@b.ro', '<x@y.ro>', 'fara-arond'] as $adresa) {
            $alt = $baza;
            $alt[0] = $adresa;
            arunca(fn () => Mime::brut(new Mesaj(...$alt)), \RuntimeException::class, 'Adresa de e-mail nu e validă');
        }
        $alt = $baza;
        $alt[1] = 'Nume "rău" <x@y.ro>';
        arunca(fn () => Mime::brut(new Mesaj(...$alt)), \RuntimeException::class, 'Adresa de e-mail nu e validă');
        arunca(fn () => Mime::brut(new Mesaj(...[...$baza, [['nume' => 'a.txt', 'continut' => 'x', 'tip' => "text/plain\r\nX-Rau: 1"]]])), \RuntimeException::class, 'Tipul atașamentului');
        // Numele expeditorului cu ghilimele sau backslash rămâne în ghilimele, scăpat.
        $alt = $baza;
        $alt[2] = 'Casa "Sus" \\ Jos';
        contine(Mime::brut(new Mesaj(...$alt)), 'From: "Casa \\"Sus\\" \\\\ Jos" <rezervari@exemplu.ro>');
        // Numele de atașament cu diacritice: codat, fără ghilimele sau căi.
        $x = Mime::brut(new Mesaj(...[...$baza, [['nume' => '../termeni "ă".txt', 'continut' => 'x', 'tip' => 'text/plain; charset=utf-8']]]));
        contine($x, 'filename="=?UTF-8?B?' . base64_encode('..termeni ă.txt') . '?="');
    },
];
