<?php

declare(strict_types=1);

// Rulatorul de teste, fără Composer:
//
//   php teste/run.php [grupa] [filtru]
//
// Descoperă teste/<grupa>/**/*_test.php; fiecare fișier întoarce ['nume' => function (Mediu $m): void {...}].
// Fiecare test rulează pe SQLite; testele care folosesc baza rulează încă o dată pe MySQL/MariaDB când
// CAZO_TEST_MYSQL=mysql://utilizator:parola@gazda:port/baza e setat. Codul de ieșire: 0 = toate au trecut.

define('CAZO', true);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$radacina = dirname(__DIR__);
require $radacina . '/app/incarca.php';
require __DIR__ . '/lib/aserti.php';
require __DIR__ . '/lib/Mediu.php';
require __DIR__ . '/lib/ServerTest.php';
require __DIR__ . '/lib/SchemaJson.php';
require __DIR__ . '/lib/TransportTest.php';
require __DIR__ . '/lib/FalsSmtp.php';
// Dublurile EasyCheck și Stripe, cu testele care le folosesc, stau doar în depozitul de dezvoltare.
foreach (['FalsEasyCheck', 'FalsSite', 'FalsPlati', 'FalsComenzi', 'DemoPrezentare', 'DemoComanda', 'FalsStripe'] as $dublura) {
    if (is_file(__DIR__ . '/lib/' . $dublura . '.php')) {
        require __DIR__ . '/lib/' . $dublura . '.php';
    }
}
require __DIR__ . '/lib/BrowserTest.php';
require __DIR__ . '/lib/InstalareTest.php';

set_error_handler(static function (int $nivel, string $mesaj, string $fisier = '', int $linie = 0): bool {
    if (!(error_reporting() & $nivel)) {
        return false;
    }
    throw new \ErrorException($mesaj, 0, $nivel, $fisier, $linie);
});

// Plasa pentru SQLite-ul vechi: în teste, o instrucțiune SQL cu peste 900 de parametri aruncă o excepție, și în
// serverele pornite de teste (moștenesc mediul), ca limita de 999 a SQLite-ului dinainte de 3.32 să fie prinsă aici.
if (getenv(\Cazo\Baza\Baza::MEDIU_LIMITA) === false) {
    putenv(\Cazo\Baza\Baza::MEDIU_LIMITA . '=' . \Cazo\Baza\Baza::PARAMETRI_MAX);
}

$grupaCeruta = $argv[1] ?? null;
$filtru = $argv[2] ?? null;
if ($grupaCeruta === 'toate') {
    $grupaCeruta = null;
}

$excluse = ['lib', 'fixturi', 'fals', 'corpus', 'e2e'];
$grupe = [];
foreach (glob(__DIR__ . '/*', GLOB_ONLYDIR) ?: [] as $d) {
    $g = basename($d);
    if (!in_array($g, $excluse, true) && ($grupaCeruta === null || $g === $grupaCeruta)) {
        $grupe[] = $g;
    }
}
sort($grupe);
if ($grupe === []) {
    fwrite(STDERR, "Grupă necunoscută: $grupaCeruta\n");
    exit(2);
}

$mysql = null;
$urlMysql = getenv('CAZO_TEST_MYSQL');
if (is_string($urlMysql) && $urlMysql !== '') {
    $mysql = \Cazo\Baza\Baza::descriereDinUrl($urlMysql);
    try {
        \Cazo\Baza\Baza::conecteaza($mysql);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'MySQL indisponibil (CAZO_TEST_MYSQL): ' . $e->getMessage() . "\n");
        exit(2);
    }
}

$rezultate = ['trecute' => 0, 'esuate' => 0];
$esecuri = [];
$start = microtime(true);
echo 'Cazo ' . (require $radacina . '/app/versiune.php') . ', PHP ' . PHP_VERSION . ', baze: sqlite' . ($mysql !== null ? ' + mysql' : '') . "\n";

foreach ($grupe as $g) {
    $fisiere = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/' . $g, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (str_ends_with($f->getFilename(), '_test.php')) {
            $fisiere[] = $f->getPathname();
        }
    }
    sort($fisiere);
    foreach ($fisiere as $fisier) {
        $rel = substr($fisier, strlen(__DIR__) + 1);
        $teste = require $fisier;
        if (!is_array($teste)) {
            fwrite(STDERR, "$rel nu întoarce un tablou de teste\n");
            exit(2);
        }
        foreach ($teste as $nume => $test) {
            if ($filtru !== null && !str_contains($rel . ' :: ' . $nume, $filtru)) {
                continue;
            }
            $dialecte = ['sqlite'];
            for ($i = 0; $i < count($dialecte); $i++) {
                $dialect = $dialecte[$i];
                $m = new Mediu($dialect, $mysql);
                $eticheta = $rel . ' :: ' . $nume . ' [' . $dialect . ']';
                $t0 = microtime(true);
                try {
                    $test($m);
                    $rezultate['trecute']++;
                    printf("  ok    %s (%d ms)\n", $eticheta, (int) ((microtime(true) - $t0) * 1000));
                } catch (\Throwable $e) {
                    $rezultate['esuate']++;
                    $detaliu = $e instanceof EsecTest ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
                    $loc = '';
                    foreach ($e->getTrace() as $cadru) {
                        if (isset($cadru['file']) && str_ends_with($cadru['file'], '_test.php')) {
                            $loc = ' (' . basename($cadru['file']) . ':' . $cadru['line'] . ')';
                            break;
                        }
                    }
                    if ($loc === '' && str_ends_with($e->getFile(), '_test.php')) {
                        $loc = ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
                    }
                    $esecuri[] = $eticheta . $loc . "\n        " . $detaliu;
                    printf("  EȘEC  %s%s\n        %s\n", $eticheta, $loc, $detaliu);
                } finally {
                    try {
                        $m->curata();
                    } catch (\Throwable $e) {
                        printf("        (curățenie eșuată: %s)\n", $e->getMessage());
                    }
                }
                if ($dialect === 'sqlite' && $m->aFolositBaza && $mysql !== null) {
                    $dialecte[] = 'mysql';
                }
            }
        }
    }
}

printf("\n%d trecute, %d eșuate, %.1f s\n", $rezultate['trecute'], $rezultate['esuate'], microtime(true) - $start);
if ($esecuri !== []) {
    echo "\nEșecuri:\n";
    foreach ($esecuri as $e) {
        echo '  ' . $e . "\n";
    }
}
exit($rezultate['esuate'] === 0 ? 0 : 1);
