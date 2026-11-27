<?php

declare(strict_types=1);

// Încărcătorul Cazo: singurul fișier PHP accesibil din web, stabil între versiuni.
// Citește calea dosarului privat (.cazo-cale.php) și lansarea curentă (curent.php), lasă lansarea să
// servească pagina din cache (app/cache-pagini.php, fără aplicație), altfel pornește aplicația lansării.

define('CAZO', true);
define('CAZO_PUBLIC', __DIR__);

/**
 * Răspunsul când lansarea lipsește („actualizare”) sau dosarul privat și configurarea lipsesc ori sunt
 * deteriorate („configurare”). Textele: incarcator.* în docs/texte-de-aprobat.md.
 */
function cazo_indisponibil(string $motiv = 'actualizare'): void
{
    http_response_code(503);
    header('Retry-After: 300');
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
    [$ro, $en] = $motiv === 'configurare'
        ? ['Facem o verificare tehnică pe site. Vă mulțumim pentru răbdare.', 'We’re running a technical check on the site. Thanks for your patience.']
        : ['Facem câteva îmbunătățiri pe site. Vă mulțumim pentru răbdare.', 'We’re making a few improvements to the site. Thanks for your patience.'];
    echo '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Revenim în câteva minute / We’ll be back in a few minutes</title></head><body><main>'
        . '<h1>Revenim în câteva minute</h1><p>' . $ro . '</p>'
        . '<h2 lang="en">We’ll be back in a few minutes</h2><p lang="en">' . $en . '</p></main></body></html>';
}

$caleCerere = (string) parse_url('http://x' . ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// Serverul de dezvoltare (php -S cu acest fișier ca router): activele existente se servesc direct.
if (PHP_SAPI === 'cli-server' && preg_match('~^/(_c|_t|_s|media|\.well-known)/[A-Za-z0-9._/\-]+$~', $caleCerere)
    && !str_contains($caleCerere, '..') && is_file(__DIR__ . $caleCerere)) {
    return false;
}

// O instalare de dinainte de redenumire are .pragul-cale.php și dosarul privat pragul-privat: se folosesc așa.
$privat = @include __DIR__ . '/.cazo-cale.php';
if (!is_string($privat) && is_file(__DIR__ . '/.pragul-cale.php')) {
    $privat = @include __DIR__ . '/.pragul-cale.php';
}
$instalare = false;
if (!is_string($privat) || !@is_dir($privat)) {
    // Înainte de pasul 3 al instalatorului, .cazo-cale.php lipsește: dosarul privat din arhivă se caută în
    // public_html (varianta B, după mutare), deasupra lui (varianta A) sau dezarhivat chiar în public_html.
    $privat = null;
    foreach (array_merge(glob(__DIR__ . '/cazo-privat-*', GLOB_ONLYDIR) ?: [], [dirname(__DIR__) . '/cazo-privat', __DIR__ . '/cazo-privat'],
        glob(__DIR__ . '/pragul-privat-*', GLOB_ONLYDIR) ?: [], [dirname(__DIR__) . '/pragul-privat', __DIR__ . '/pragul-privat']) as $c) {
        if (@is_file($c . '/curent.php')) {
            $privat = $c;
            break;
        }
    }
    // Un site instalat merge doar prin .cazo-cale.php; dosarul găsit servește doar instalatorului.
    if ($privat === null || @is_file($privat . '/config.php')) {
        cazo_indisponibil('configurare');
        return;
    }
    $instalare = true;
}
// curent.php se citește ca text, nu prin include: comutarea versiunii (rename atomic) se vede imediat,
// chiar dacă OPcache-ul serverului web păstrează încă vechiul fișier compilat.
$versiune = preg_match("~^\\s*return\\s+'([^']*)';~m", (string) @file_get_contents($privat . '/curent.php'), $potrivire) ? $potrivire[1] : null;
if (!is_string($versiune) || !preg_match('~^[0-9A-Za-z.\-]{1,32}$~', $versiune)
    || !is_file($privat . '/lansari/' . $versiune . '/app/porneste.php')) {
    cazo_indisponibil();
    return;
}
define('CAZO_PRIVAT', $privat);
define('CAZO_LANSARE', $privat . '/lansari/' . $versiune);

// Instalatorul, înaintea aplicației: doar cât lipsește config.php; după pasul 12 rămâne închis.
if ($instalare || !is_file($privat . '/config.php')) {
    if (is_file(CAZO_LANSARE . '/app/instalare.php')) {
        require CAZO_LANSARE . '/app/instalare.php';
        return;
    }
    cazo_indisponibil('configurare');
    return;
}

// Cache-ul de pagini: regulile și cheia stau în lansare, ca să se schimbe odată cu versiunea.
if (is_file(CAZO_LANSARE . '/app/cache-pagini.php') && (require CAZO_LANSARE . '/app/cache-pagini.php') === true) {
    return;
}

require CAZO_LANSARE . '/app/porneste.php';
