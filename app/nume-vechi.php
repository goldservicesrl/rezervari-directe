<?php

declare(strict_types=1);

// Instalările de dinainte de redenumire (Pragul). Încărcătorul lor (public_html/index.php) și intrarea lor
// stabilă a cronului (cron.php din dosarul privat) rămân pe disc la actualizarea de mână (doar lansari/<versiune>/ și
// curent.php se schimbă) și definesc PRAGUL, PRAGUL_PRIVAT, PRAGUL_PUBLIC, PRAGUL_LANSARE, PRAGUL_CRON_*, plus funcția
// pragul_indisponibil(). Fișierul acesta le dă numele noi, ca lansarea Cazo să pornească la fel sub încărcătorul vechi.
// Inclus de intrările lansării (porneste.php, cache-pagini.php, instalare.php) când găsesc doar constanta veche.

defined('PRAGUL') && !defined('CAZO') && define('CAZO', true);
defined('CAZO') or exit;

foreach (['PRIVAT', 'PUBLIC', 'LANSARE', 'CRON_PRIVAT', 'CRON_LANSARE'] as $nume) {
    if (defined('PRAGUL_' . $nume) && !defined('CAZO_' . $nume)) {
        define('CAZO_' . $nume, constant('PRAGUL_' . $nume));
    }
}
unset($nume);

if (!function_exists('cazo_indisponibil') && function_exists('pragul_indisponibil')) {
    function cazo_indisponibil(string $motiv = 'actualizare'): void
    {
        pragul_indisponibil($motiv);
    }
}
