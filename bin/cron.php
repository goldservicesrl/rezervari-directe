<?php

declare(strict_types=1);

// Cronul. Linia din cPanel indică intrarea stabilă din dosarul privat (bin/cron-privat.php,
// copiat ca cazo-privat/cron.php), care include acest fișier din lansarea curentă:
//   */5 * * * * php -q /home/<cont>/cazo-privat/cron.php
// Dosarul privat vine de la intrarea stabilă, prin --privat=<cale> sau din locul fișierului
// (lansari/<versiune>/bin/cron.php). Rulează codul lansării alese de intrarea stabilă (CAZO_CRON_LANSARE: cea
// curentă sau, după o aplicare întreruptă, cea de dinainte), altfel al lansării curente (curent.php).

// Cronul rulează din linia de comandă. Pe unele găzduiri cPanel, `php` din linia de cron e varianta CGI (cgi-fcgi, nu cli):
// o primim doar când nu e o cerere web (fără REQUEST_METHOD și HTTP_HOST); dintr-o cerere web ieșim, ca până acum.
if (PHP_SAPI !== 'cli' && !(str_starts_with(PHP_SAPI, 'cgi') && !isset($_SERVER['REQUEST_METHOD']) && !isset($_SERVER['HTTP_HOST']))) {
    exit;
}
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}
if (PHP_SAPI !== 'cli' && function_exists('set_time_limit')) {
    @set_time_limit(0);
}

define('CAZO', true);

$privat = null;
foreach (array_slice((array) ($argv ?? $_SERVER['argv'] ?? []), 1) as $a) {
    if (str_starts_with($a, '--privat=')) {
        $privat = substr($a, 9);
    }
}
// Intrarea stabilă a unei instalări de dinainte de redenumire definește PRAGUL_CRON_*.
foreach (['PRIVAT', 'LANSARE'] as $nume) {
    if (defined('PRAGUL_CRON_' . $nume) && !defined('CAZO_CRON_' . $nume)) {
        define('CAZO_CRON_' . $nume, constant('PRAGUL_CRON_' . $nume));
    }
}
$privat ??= defined('CAZO_CRON_PRIVAT') ? CAZO_CRON_PRIVAT : dirname(__DIR__, 3);
$privat = rtrim($privat, '/');

if (defined('CAZO_CRON_LANSARE')) {
    $lansare = CAZO_CRON_LANSARE;
    $versiune = basename($lansare);
} else {
    $versiune = preg_match("~^\\s*return\\s+'([^']*)';~m", (string) @file_get_contents($privat . '/curent.php'), $potrivire) ? $potrivire[1] : null;
    if (!is_string($versiune) || !preg_match('~^[0-9A-Za-z.\-]{1,32}$~', $versiune)) {
        fwrite(STDERR, "Cazo: curent.php lipsește în $privat\n");
        exit(2);
    }
    $lansare = $privat . '/lansari/' . $versiune;
}
if (!is_file($lansare . '/app/incarca.php')) {
    fwrite(STDERR, "Cazo: lansarea $versiune lipsește\n");
    exit(2);
}

define('CAZO_PRIVAT', $privat);
require_once $lansare . '/app/incarca.php';

// În timpul instalării (config.php se scrie la pasul 12), cronul rulează pe configurarea în curs.
$app = \Cazo\Nucleu\Aplicatie::dinDosar($privat, true);
try {
    // O versiune nouă urcată de gazdă: migrările ei înaintea sarcinilor, doar pe o instalare încheiată.
    if (is_file($privat . '/config.php')) {
        $app->migreazaLaNevoie();
    }
    $raport = $app->rulator()->ruleaza('cpanel');
} catch (\Throwable $e) {
    $app->jurnal()->eroare('Cron eșuat', ['exceptie' => get_class($e), 'mesaj' => $e->getMessage()]);
    fwrite(STDERR, 'Cazo: cron eșuat: ' . \Cazo\Nucleu\Jurnal::mascheaza($e->getMessage()) . "\n");
    exit(1);
}
echo json_encode($raport, JSON_UNESCAPED_UNICODE), "\n";
exit(0);
