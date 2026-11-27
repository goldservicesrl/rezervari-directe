<?php

declare(strict_types=1);

// Golirea memoriei OPcache la prima pornire a unei instalări noi. Fără clasele aplicației: rulează
// înaintea încărcătorului de clase, din app/instalare.php.

defined('CAZO') or exit;

if (!function_exists('cazo_instalare_proaspata')) {
    /**
     * Cât starea instalării (instalare/stare.php) lipsește, fiecare fișier PHP al lansării e scos din OPcache
     * (opcache_invalidate cu forțare: are efect chiar în cererea aceasta), apoi se cere golirea întreagă
     * (opcache_reset, care se face după cererile în curs). Găzduirile care opresc funcțiile (disable_functions,
     * opcache.restrict_api) rămân cu data fișierelor din arhivă, care e data construirii.
     * @param ?callable(string): mixed $invalideaza dublura testelor; null = opcache_invalidate și opcache_reset
     * @return int numărul fișierelor scoase din memorie (0 = instalarea e începută ori OPcache lipsește)
     */
    function cazo_instalare_proaspata(string $privat, string $lansare, ?callable $invalideaza = null): int
    {
        if (is_file($privat . '/instalare/stare.php') || is_file($privat . '/config.php')) {
            return 0;
        }
        $real = $invalideaza === null;
        if ($real && !function_exists('opcache_invalidate')) {
            return 0;
        }
        $n = 0;
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lansare, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                    $real ? @opcache_invalidate($f->getPathname(), true) : $invalideaza($f->getPathname());
                    $n++;
                }
            }
        } catch (\Throwable) {
        }
        if ($real && function_exists('opcache_reset')) {
            @opcache_reset();
        }
        return $n;
    }
}
