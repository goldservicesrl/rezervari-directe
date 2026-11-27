<?php

declare(strict_types=1);

// Încărcătorul de clase al nucleului: Cazo\X\Y se află în app/X/Y.php.
// Comun pentru web (porneste.php), cron (bin/cron.php), CLI (bin/cazo) și teste.

defined('CAZO') or exit;

// Fișierele scrise în dosarul privat de versiunile de dinainte de redenumire (config.php, curent.php, harta activelor)
// au garda veche, `defined('PRAGUL') or exit;`: constanta veche rămâne definită, ca ele să se citească mai departe.
defined('PRAGUL') or define('PRAGUL', true);

spl_autoload_register(static function (string $clasa): void {
    if (!str_starts_with($clasa, 'Cazo\\')) {
        return;
    }
    $relativ = str_replace('\\', '/', substr($clasa, 5));
    // Numele de clasă intră în cale doar cu caractere sigure.
    if (!preg_match('~^[A-Za-z0-9_/]+$~', $relativ)) {
        return;
    }
    $cale = __DIR__ . '/' . $relativ . '.php';
    if (is_file($cale)) {
        require $cale;
    }
});

// Funcțiile păzite pentru găzduirile care opresc chmod, umask și flock se încarcă de la început: instalatorul
// redenumește dosarul privat (cu lansarea în el) în mijlocul cererii, după care o clasă cerută prima oară n-ar mai
// fi găsită la calea veche. Instalatorul include din nou încărcătorul, de la calea nouă: clasa se declară o singură dată.
if (!class_exists(\Cazo\Nucleu\Functii::class, false) && is_file(__DIR__ . '/Nucleu/Functii.php')) {
    require __DIR__ . '/Nucleu/Functii.php';
}

// Fără extensia sodium (unele găzduiri n-o au), funcțiile sodium_* și constantele SODIUM_* vin din biblioteca vendorizată
// paragonie/sodium_compat, cu același format al datelor criptate și al semnăturilor. Se încarcă aici, înaintea
// oricărei clase: constantele se citesc la prima cheie, deci nu pot aștepta încărcătorul de clase. Cu extensia prezentă,
// biblioteca rămâne neîncărcată. Fără extensie, se include și când funcțiile există deja (încărcate de o intrare stabilă
// din lansarea activă, sau de încărcătorul de la calea veche, înainte ca instalatorul să redenumească dosarul privat):
// biblioteca își înregistrează atunci încărcătorul de clase și la calea de acum, iar fișierele ei își păzesc singure
// definițiile (function_exists, defined, class_exists), deci a doua includere nu redeclară nimic.
if (!extension_loaded('sodium') && is_file(dirname(__DIR__) . '/vendor/paragonie/sodium_compat/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/paragonie/sodium_compat/autoload.php';
}

// Bibliotecile vendorizate (vendor/VENDOR.lock): Twig, cu funcția trigger_deprecation() și, doar fără extensia
// ctype, polyfill-ul ei. Se încarcă la prima clasă Twig cerută, deci paginile din cache și cronul rămân fără ele.
spl_autoload_register(static function (string $clasa): void {
    static $pregatit = false;
    $vendor = dirname(__DIR__) . '/vendor';
    if (str_starts_with($clasa, 'Twig\\')) {
        if (!$pregatit) {
            $pregatit = true;
            require_once $vendor . '/symfony/deprecation-contracts/function.php';
            if (!function_exists('ctype_alpha')) {
                require_once $vendor . '/symfony/polyfill-ctype/bootstrap.php';
            }
        }
        [$dosar, $relativ] = ['twig/twig/src/', str_replace('\\', '/', substr($clasa, 5))];
    } elseif (str_starts_with($clasa, 'Symfony\\Polyfill\\Ctype\\')) {
        [$dosar, $relativ] = ['symfony/polyfill-ctype/', str_replace('\\', '/', substr($clasa, 23))];
    } else {
        return;
    }
    // Doar partea venită din numele clasei se verifică (ca la clasele nucleului): calea instalării poate avea
    // spații sau alte caractere (de exemplu „/home/cont/site nou/cazo-privat”), iar ea e aleasă de gazdă, nu de cerere.
    if (preg_match('~^[A-Za-z0-9_/]+$~', $relativ) && is_file($cale = $vendor . '/' . $dosar . $relativ . '.php')) {
        require $cale;
    }
});
