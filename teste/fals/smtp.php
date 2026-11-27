<?php

declare(strict_types=1);

// Serverul SMTP fals al testelor (în locul Mailpit): ascultă pe 127.0.0.1:<port>, primește mesajele
// prin dialogul SMTP obișnuit (EHLO, AUTH PLAIN/LOGIN acceptat, MAIL FROM, RCPT TO, DATA, QUIT) și scrie fiecare mesaj,
// brut, în <dir>/<n>.eml. Implicit fără TLS: Cazo îl folosește cu securitatea „niciuna”.
//
//   php teste/fals/smtp.php --port=2525 --dir=/tmp/posta [--tls=starttls|ssl --cert=server.pem] [--auth="PLAIN LOGIN"]
//
// Pentru testele clientului SMTP: --tls=starttls anunță STARTTLS și cere TLS înainte de AUTH și MAIL; --tls=ssl
// pornește TLS de la conectare; --cert e certificatul cu cheia, în PEM. --auth alege metodele anunțate („” = niciuna).
// Fiecare autentificare se scrie în <dir>/auth.log (metoda, utilizatorul, parola, dacă legătura era criptată), fiecare
// comandă în <dir>/dialog.log.

if (PHP_SAPI !== 'cli') {
    exit;
}
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('~^--([a-z]+)=(.*)$~s', $a, $m)) {
        $opt[$m[1]] = $m[2];
    }
}
$port = (int) ($opt['port'] ?? 2525);
$dir = (string) ($opt['dir'] ?? sys_get_temp_dir() . '/cazo-posta');
$tls = (string) ($opt['tls'] ?? '');
$metode = (string) ($opt['auth'] ?? 'PLAIN LOGIN');
@mkdir($dir, 0700, true);
$ctx = stream_context_create($tls !== '' ? ['ssl' => ['local_cert' => (string) ($opt['cert'] ?? ''), 'verify_peer' => false]] : []);
$srv = stream_socket_server(($tls === 'ssl' ? 'tls' : 'tcp') . '://127.0.0.1:' . $port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($srv === false) {
    fwrite(STDERR, "SMTP fals: $errstr\n");
    exit(1);
}
$n = 0;
while (true) {
    $c = @stream_socket_accept($srv, 3600);
    if ($c === false) {
        continue;
    }
    stream_set_timeout($c, 30);
    $scrie = static function (string $l) use ($c): void {
        fwrite($c, $l . "\r\n");
    };
    $scrie('220 cazo-fals ESMTP');
    $de = '';
    $catre = [];
    $auth = null;
    $criptat = $tls === 'ssl';
    $jurnal = static fn (string $f, string $l) => file_put_contents("$dir/$f", $l . "\n", FILE_APPEND);
    while (($linie = fgets($c)) !== false) {
        $cmd = strtoupper(substr(trim($linie), 0, 4));
        if ($auth !== null) {
            // AUTH LOGIN: întâi utilizatorul, apoi parola, fiecare în base64.
            $auth[] = base64_decode(trim($linie));
            if (count($auth) === 1) {
                $scrie('334 UGFzc3dvcmQ6');
            } else {
                $jurnal('auth.log', json_encode(['LOGIN', $auth[0], $auth[1], $criptat]));
                $auth = null;
                $scrie('235 2.7.0 Autentificat');
            }
            continue;
        }
        $jurnal('dialog.log', str_starts_with($cmd, 'AUTH') ? 'AUTH' : rtrim($linie, "\r\n"));
        $faraTls = $tls === 'starttls' && !$criptat;
        if ($cmd === 'EHLO' || $cmd === 'HELO') {
            $scrie('250-cazo-fals');
            if ($faraTls) {
                $scrie('250-STARTTLS');
            }
            if ($metode !== '') {
                $scrie('250-AUTH ' . $metode);
            }
            $scrie('250 8BITMIME');
        } elseif ($cmd === 'STAR' && $faraTls) {
            $scrie('220 2.0.0 Gata pentru TLS');
            $criptat = @stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER') ? STREAM_CRYPTO_METHOD_TLSv1_3_SERVER : 0)) === true;
            if (!$criptat) {
                break;
            }
        } elseif ($faraTls && in_array($cmd, ['AUTH', 'MAIL', 'RCPT', 'DATA'], true)) {
            $scrie('530 5.7.0 Cereți întâi STARTTLS');
        } elseif ($cmd === 'AUTH') {
            if (stripos($linie, 'LOGIN') !== false) {
                $auth = [];
                $scrie('334 VXNlcm5hbWU6');
            } else {
                $p = explode("\0", (string) base64_decode(trim((string) substr(trim($linie), 11))));
                $jurnal('auth.log', json_encode(['PLAIN', $p[1] ?? '', $p[2] ?? '', $criptat]));
                $scrie('235 2.7.0 Autentificat');
            }
        } elseif ($cmd === 'MAIL') {
            $de = trim(substr($linie, 10));
            $catre = [];
            $scrie('250 OK');
        } elseif ($cmd === 'RCPT') {
            $catre[] = trim(substr($linie, 8));
            $scrie('250 OK');
        } elseif ($cmd === 'DATA') {
            $scrie('354 Continuați');
            $corp = '';
            while (($l = fgets($c)) !== false && rtrim($l, "\r\n") !== '.') {
                $corp .= str_starts_with($l, '..') ? substr($l, 1) : $l;
            }
            $n++;
            file_put_contents(sprintf('%s/%04d-%s.eml', $dir, $n, bin2hex(random_bytes(3))), 'X-Fals-De: ' . $de . "\r\nX-Fals-Catre: " . implode(',', $catre) . "\r\n" . $corp);
            $scrie('250 OK primit');
        } elseif ($cmd === 'QUIT') {
            $scrie('221 La revedere');
            break;
        } elseif ($cmd === 'RSET' || $cmd === 'NOOP') {
            $scrie('250 OK');
        } else {
            $scrie('502 Comandă necunoscută');
        }
    }
    fclose($c);
}
