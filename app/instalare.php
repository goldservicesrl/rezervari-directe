<?php

declare(strict_types=1);

// Instalatorul: inclus de public_html/index.php cât lipsește config.php, cu CAZO, CAZO_PRIVAT
// (dosarul privat găsit), CAZO_PUBLIC și CAZO_LANSARE definite, înaintea aplicației (Cazo\Instalare\Instalator).

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

// la prima pornire a unei instalări noi (starea instalării încă nu există), codul lansării iese din
// memoria OPcache. O arhivă dezarhivată peste una mai veche poate lăsa fișiere cu aceeași dată, iar PHP ar rula
// atunci codul vechi. Înaintea încărcătorului de clase, ca nicio clasă să nu vină din memoria veche.
require_once __DIR__ . '/instalare-proaspata.php';
cazo_instalare_proaspata(CAZO_PRIVAT, CAZO_LANSARE);

require_once __DIR__ . '/incarca.php';

set_error_handler(static function (int $nivel, string $mesaj, string $fisier = '', int $linie = 0): bool {
    if (!(error_reporting() & $nivel)) {
        return false;
    }
    throw new \ErrorException($mesaj, 0, $nivel, $fisier, $linie);
});

try {
    \Cazo\Instalare\Instalator::web(CAZO_PRIVAT, CAZO_PUBLIC);
} catch (\Throwable $e) {
    // O eroare neprinsă: pagina încărcătorului, ca la configurarea lipsă; cauza rămâne în jurnalul serverului.
    error_log('Cazo, instalatorul: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    if (!headers_sent()) {
        cazo_indisponibil('configurare');
    }
}
