<?php

declare(strict_types=1);

// Intrarea stabilă a cronului cPanel, copiată ca cazo-privat/cron.php (lângă curent.php) la instalare:
//
//   */5 * * * * php -q /home/<cont>/cazo-privat/cron.php
//
// Linia rămâne aceeași la fiecare actualizare: fișierul citește curent.php și pornește bin/cron.php din
// lansarea curentă, oricare ar fi ea.

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

define('CAZO_CRON_PRIVAT', __DIR__);

$versiune = preg_match("~^\\s*return\\s+'([^']*)';~m", (string) @file_get_contents(__DIR__ . '/curent.php'), $potrivire) ? $potrivire[1] : null;
if (!is_string($versiune) || !preg_match('~^[0-9A-Za-z.\-]{1,32}$~', $versiune) || !is_file(__DIR__ . '/lansari/' . $versiune . '/bin/cron.php')) {
    fwrite(STDERR, 'Cazo: lansarea curentă lipsește în ' . __DIR__ . "\n");
    exit(2);
}

define('CAZO_CRON_LANSARE', __DIR__ . '/lansari/' . $versiune);

require __DIR__ . '/lansari/' . $versiune . '/bin/cron.php';
