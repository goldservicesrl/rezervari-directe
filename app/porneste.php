<?php

declare(strict_types=1);

// Pornirea web a lansării: inclus de public_html/index.php după ce încărcătorul a definit
// CAZO, CAZO_PRIVAT și CAZO_PUBLIC și a verificat că lansarea există.

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Inchidere;

// O instalare de dinainte de redenumire: încărcătorul ei definește doar PRAGUL_*; nume-vechi.php dă numele noi.
if (!defined('CAZO') && defined('PRAGUL')) {
    require __DIR__ . '/nume-vechi.php';
}
defined('CAZO') or exit;

// Găzduirile pot opri ini_set (disable_functions); atunci rămâne valoarea din php.ini.
if (function_exists('ini_set')) {
    @ini_set('display_errors', '0');
}
error_reporting(E_ALL);

require_once __DIR__ . '/incarca.php';

// Avertismentele devin excepții (prinse de Aplicatie, cu pagina de eroare și id); @ rămâne respectat.
set_error_handler(static function (int $nivel, string $mesaj, string $fisier = '', int $linie = 0): bool {
    if (!(error_reporting() & $nivel)) {
        return false;
    }
    throw new \ErrorException($mesaj, 0, $nivel, $fisier, $linie);
});

try {
    $app = Aplicatie::dinDosar(CAZO_PRIVAT);
} catch (\Throwable $e) {
    // Configurarea lipsește sau e deteriorată: același răspuns ca încărcătorul când lansarea lipsește.
    @file_put_contents(CAZO_PRIVAT . '/jurnale/cazo-' . gmdate('Y-m-d') . '.log',
        gmdate('Y-m-d\TH:i:s\Z') . ' EROARE Pornire eșuată: ' . get_class($e) . "\n", FILE_APPEND | LOCK_EX);
    cazo_indisponibil('configurare');
    return;
}

// O versiune nouă urcată de gazdă: migrările ei, la prima cerere. Cât altă cerere migrează sau o migrare cade,
// vizitatorii văd pagina de așteptare (paginile din cache se servesc înainte, din încărcător).
try {
    $app->migreazaLaNevoie();
} catch (\Throwable $e) {
    $app->jurnal()->eroare('Migrările versiunii noi nu s-au aplicat', ['exceptie' => get_class($e), 'mesaj' => $e->getMessage()]);
    cazo_indisponibil();
    return;
}

$cerere = Cerere::dinGlobale(array_values(array_filter((array) $app->config()->get('proxy_increzut', []), 'is_string')));
$raspuns = $app->trateaza($cerere);
// HTTPS verificat: prima cerere sosită pe HTTPS pornește redirecționarea de pe http și antetul HSTS;
// paginile ținute până atunci în cache se refac, ca să primească antetul.
try {
    if (\Cazo\Nucleu\Https::verifica(CAZO_PRIVAT, $app->config()->origine(), $app->setari(), $_SERVER)) {
        $app->ecOglinda()->schimbaContinutul();
    }
} catch (\Throwable) {
}
// Paginile publice, aceleași pentru toți vizitatorii, intră în cache-ul de pagini.
try {
    \Cazo\Nucleu\CachePagini::scrie(CAZO_PRIVAT, $_SERVER, $_GET, $_COOKIE, $raspuns);
} catch (\Throwable) {
}

$pseudo = false;
$dupa = $app->lucruDupaRaspuns();
try {
    $pseudo = $dupa === [] && $app->vreaPseudoCron($cerere, $raspuns);
} catch (\Throwable) {
}
if ($pseudo || $dupa !== []) {
    Inchidere::pregateste($raspuns);
}
$raspuns->trimite($cerere->metoda === 'HEAD');

// Lucrul de după răspuns (anunțurile EasyCheck, apoi pseudo-cronul), după închiderea conexiunii.
if ($pseudo || $dupa !== []) {
    Inchidere::inchide();
    foreach ($dupa as $f) {
        try {
            $f();
        } catch (\Throwable $e) {
            $app->jurnal()->eroare('Lucrul de după răspuns a eșuat', ['exceptie' => get_class($e), 'mesaj' => $e->getMessage()]);
        }
    }
    if ($pseudo) {
        try {
            $app->rulator()->ruleaza('pseudo');
        } catch (\Throwable $e) {
            $app->jurnal()->eroare('Pseudo-cron eșuat', ['exceptie' => get_class($e), 'mesaj' => $e->getMessage()]);
        }
    }
}
