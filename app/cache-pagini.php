<?php

declare(strict_types=1);

// Cache-ul de pagini al lansării: inclus de public_html/index.php înaintea aplicației, cu CAZO și
// CAZO_PRIVAT definite. Întoarce true când pagina a plecat din cache (Cazo\Nucleu\CachePagini).

// O instalare de dinainte de redenumire: încărcătorul ei definește doar PRAGUL_*; nume-vechi.php dă numele noi.
if (!defined('CAZO') && defined('PRAGUL')) {
    require __DIR__ . '/nume-vechi.php';
}
defined('CAZO') or exit;

// Găzduirile pot opri ini_set (disable_functions); atunci rămâne valoarea din php.ini.
if (function_exists('ini_set')) {
    @ini_set('display_errors', '0');
}

require_once __DIR__ . '/incarca.php';

// Versiunea PHP rămâne în afara răspunsurilor: antetul X-Powered-By, pus de PHP, se scoate la fiecare cerere.
if (function_exists('header_remove')) {
    @header_remove('X-Powered-By');
}

try {
    // După trecerea pe o adresă nouă (Setări › Adresa site-ului): adresa veche trimite cu 301 spre cea nouă, cu aceeași cale.
    if (\Cazo\Sistem\Adresa::redirectioneaza(CAZO_PRIVAT, $_SERVER)) {
        return true;
    }
    // După verificarea HTTPS: cererile sosite pe http pleacă spre https, înaintea cache-ului și a aplicației.
    if (\Cazo\Nucleu\Https::redirectioneaza(CAZO_PRIVAT, $_SERVER, $_COOKIE)) {
        return true;
    }
    return \Cazo\Nucleu\CachePagini::serveste(CAZO_PRIVAT, $_SERVER, $_GET, $_COOKIE);
} catch (\Throwable) {
    // Un fișier de cache deteriorat lasă cererea să treacă la aplicație, cât antetele n-au plecat încă.
    return headers_sent();
}
