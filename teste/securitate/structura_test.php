<?php

declare(strict_types=1);

// Structura care ține securitatea: un singur PHP public, garda CAZO în fiecare fișier al lansării
// (varianta B), HTML-ul nucleului fără script sau stil inline, .htaccess-urile.

use Cazo\Dezvoltare\Lansare;
use Cazo\Nucleu\Cerere;

return [
    'public/ are un singur fișier PHP: index.php' => function (Mediu $m): void {
        $php = [];
        foreach (scandir(Mediu::radacina() . '/public') ?: [] as $f) {
            if (preg_match('~\.(php\d?|phtml|phar)$~i', $f)) {
                $php[] = $f;
            }
        }
        egal(['index.php'], $php);
    },

    'fiecare fișier PHP al aplicației începe cu garda CAZO' => function (Mediu $m): void {
        $r = Mediu::radacina();
        foreach (Lansare::fisiere($r) as $rel) {
            if (!str_ends_with($rel, '.php') || str_starts_with($rel, 'public/') || str_starts_with($rel, 'bin/')) {
                continue;
            }
            // Bibliotecile vendorizate rămân neschimbate (hash-urile din VENDOR.lock): fișierele lor doar declară
            // clase și funcții, fără efecte la o rulare directă; garda lor e verificată mai jos.
            if (str_starts_with($rel, 'vendor/')) {
                $cod = (string) file_get_contents("$r/$rel");
                adevarat(!preg_match('~^(echo|print|exit|die|include|require|eval|header|setcookie|file_put_contents|unlink)\b~m', $cod) || str_contains($rel, 'polyfill-ctype/bootstrap'), "$rel are cod executat la încărcare");
                continue;
            }
            $cod = (string) file_get_contents("$r/$rel");
            adevarat(str_contains($cod, "defined('CAZO') or exit;"), "$rel fără gardă");
        }
        // Scripturile din bin/ refuză să ruleze din web.
        foreach (['bin/cron.php', 'bin/lansare.php', 'bin/cazo'] as $rel) {
            contine((string) file_get_contents("$r/$rel"), "PHP_SAPI !== 'cli'", $rel);
        }
        // Cronul primește și php-cgi din linia de cron (unele găzduiri cPanel), dar nu o cerere web.
        foreach (['bin/cron.php', 'bin/cron-privat.php'] as $rel) {
            contine((string) file_get_contents("$r/$rel"), 'str_starts_with(PHP_SAPI, \'cgi\') && !isset($_SERVER[\'REQUEST_METHOD\']) && !isset($_SERVER[\'HTTP_HOST\'])', $rel);
        }
    },

    'codul nu folosește GLOB_BRACE: constanta lipsește pe musl (Alpine) și pe unele găzduiri, deci glob() cu acolade ar opri softul' => function (Mediu $m): void {
        $r = Mediu::radacina();
        $gasite = [];
        $verificate = 0;
        foreach (['app', 'bin', 'public', 'teste'] as $dosar) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($r . '/' . $dosar, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $fisier) {
                $cale = str_replace(chr(92), '/', $fisier->getPathname());
                $rel = substr($cale, strlen($r) + 1);
                // Corpusul ostil al testelor nu e cod al softului; bin/ are și scripturi fără extensie (bin/cazo).
                if (str_starts_with($rel, 'teste/corpus/') || (!str_ends_with($rel, '.php') && !(str_starts_with($rel, 'bin/') && !str_contains(basename($rel), '.')))) {
                    continue;
                }
                $verificate++;
                // Doar constanta folosită în cod: numele ei în comentarii sau în șiruri (ca aici) nu contează.
                foreach (token_get_all((string) file_get_contents($cale)) as $t) {
                    if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && ltrim($t[1], chr(92)) === 'GLOB_BRACE') {
                        $gasite[] = $rel . ':' . $t[2];
                    }
                }
            }
        }
        adevarat($verificate > 100, "prea puține fișiere verificate: $verificate");
        egal([], $gasite, 'GLOB_BRACE în cod; folosiți mai multe glob() reunite sau scandir()');
    },

    'HTML-ul nucleului: fără script inline, fără stil inline, fără resurse externe' => function (Mediu $m): void {
        $app = $m->aplicatie();
        foreach (['/', '/en/', '/camere', '/en/rooms', '/termeni', '/disponibil', '/nu-exista'] as $cale) {
            $html = $app->trateaza(Cerere::creeaza('GET', $cale))->corp;
            // Scripturile au doar src (module ale nucleului din /_c/), fără cod între etichete.
            adevarat(!preg_match('~<script(?![^>]*\ssrc="/_c/)[^>]*>|<script[^>]*>[^<]+</script>|<style|\sstyle=|\son[a-z]+=|javascript:~i', $html), "$cale are cod inline");
            // Resursele încărcate de pagină (src, <link href>) vin doar de pe site; linkurile de navigare spre alte site-uri
            // (harta online, SAL) se deschid doar la clicul oaspetelui.
            adevarat(!preg_match('~\s(src|srcset|imagesrcset)="(https?:)?//(?!cazo.test)|<link[^>]*\shref="(https?:)?//(?!cazo.test)~', $html), "$cale cere resurse externe");
            contine($html, '<!doctype html>');
        }
    },

    'escaparea textelor în HTML' => function (Mediu $m): void {
        $t = new \Cazo\I18n\Traducator('ro', []);
        $html = \Cazo\Nucleu\Html::pagina($t, '<script>alert(1)</script>', ['"x" & \'y\''], ['link' => ['/?a=1&b="2"', '<b>']]);
        lipseste($html, '<script>');
        contine($html, '&lt;script&gt;');
        contine($html, '&quot;x&quot; &amp; &apos;y&apos;');
        contine($html, 'href="/?a=1&amp;b=&quot;2&quot;"');
    },

    '.htaccess: totul spre index.php, alte PHP-uri și fișierele ascunse refuzate, fără listare' => function (Mediu $m): void {
        $h = (string) file_get_contents(Mediu::radacina() . '/public/.htaccess');
        contine($h, 'Options -Indexes');
        contine($h, 'RewriteRule ^ index.php [L,QSA]');
        contine($h, '<FilesMatch "\.(php\d?|phtml|phar|pht|inc)$">');
        contine($h, '<FilesMatch "^\.">');
        // Validările de certificat (AutoSSL, Let's Encrypt) se servesc direct, înaintea rescrierii spre index.php.
        $wk = strpos($h, 'RewriteRule ^\.well-known/ - [L]');
        adevarat($wk !== false && $wk < strpos($h, 'RewriteRule ^ index.php [L,QSA]'), '/.well-known/ trece prin index.php');
        $a = (string) file_get_contents(Mediu::radacina() . '/public/.htaccess-active');
        contine($a, 'RemoveHandler .php');
        contine($a, 'php_flag engine off');
        contine($a, 'X-Content-Type-Options "nosniff"');
        contine($a, 'Header set Cross-Origin-Resource-Policy "same-origin"');
    },

    '.htaccess-active: CSP restrictiv pentru activele deschise direct, fără să încurce SVG-urile și fonturile (auditul de securitate.2026)' => function (Mediu $m): void {
        $a = (string) file_get_contents(Mediu::radacina() . '/public/.htaccess-active');
        adevarat((bool) preg_match('~^\s*Header set Content-Security-Policy "([^"]+)"$~m', $a, $x), 'antetul CSP lipsește');
        $csp = [];
        foreach (explode(';', $x[1]) as $d) {
            $p = preg_split('~\s+~', trim($d));
            $csp[array_shift($p)] = $p;
        }
        egal(["'none'"], $csp['default-src']);
        egal(["'self'"], $csp['style-src']);
        egal(["'self'", 'data:'], $csp['img-src']);
        egal(["'self'"], $csp['font-src']);
        egal([], $csp['sandbox'], 'sandbox fără excepții: niciun script');
        fals(isset($csp['script-src']));
        // În interiorul blocului mod_headers, lângă nosniff.
        $bloc = substr($a, (int) strpos($a, '<IfModule mod_headers.c>'));
        $bloc = substr($bloc, 0, (int) strpos($bloc, '</IfModule>'));
        contine($bloc, 'Header set Content-Security-Policy');
        contine($bloc, 'Header set X-Content-Type-Options "nosniff"');
        // SVG-urile livrate (deschise direct sau, în unele browsere, și ca imagine, sub politica lor): fără stiluri
        // în linie și fără trimiteri externe, deci desenate la fel sub `style-src 'self'` și `img-src 'self' data:`.
        $svg = array_merge(glob(Mediu::radacina() . '/teme/*/assets/img/*.svg') ?: [], glob(Mediu::radacina() . '/front/img/*.svg') ?: []);
        adevarat($svg !== []);
        foreach ($svg as $f) {
            $t = (string) file_get_contents($f);
            fals((bool) preg_match('~<style|\sstyle\s*=~i', $t), 'stil în linie: ' . $f);
            fals((bool) preg_match('~(?:href|src)\s*=\s*["\'](?!#|data:)~i', $t), 'trimitere externă: ' . $f);
        }
        // Curățarea SVG-urilor temelor terțe scoate stilurile.
        $curat = \Cazo\Teme\Svg::curata('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><style>*{fill:red}</style><rect style="fill:red" width="1" height="1"/></svg>');
        lipseste($curat, 'style');
    },

    'lansarea refuză fișierele cu extensii neașteptate' => function (Mediu $m): void {
        $d = $m->dosarTemporar();
        mkdir("$d/app");
        file_put_contents("$d/app/a.php", '<?php');
        egal(['app/a.php'], Lansare::fisiere($d));
        file_put_contents("$d/app/shell.phtml", '<?php');
        arunca(fn () => Lansare::fisiere($d), \RuntimeException::class, 'extensie nepermisă');
        unlink("$d/app/shell.phtml");
        symlink('/etc/passwd', "$d/app/legatura.txt");
        arunca(fn () => Lansare::fisiere($d), \RuntimeException::class, 'Legătură simbolică');
    },

    'VENDOR.lock: orice fișier nedeclarat sau modificat oprește construcția' => function (Mediu $m): void {
        Lansare::verificaVendor(Mediu::radacina());
        $d = $m->dosarTemporar();
        mkdir("$d/vendor/lib", 0700, true);
        file_put_contents("$d/vendor/lib/a.php", 'A');
        $lock = ['biblioteci' => [['nume' => 'lib', 'versiune' => '1.0.0', 'licenta' => 'MIT', 'sursa' => 'https://example.com', 'fisiere' => ['lib/a.php' => hash('sha256', 'A')]]]];
        file_put_contents("$d/vendor/VENDOR.lock", json_encode($lock));
        Lansare::verificaVendor($d);
        file_put_contents("$d/vendor/lib/a.php", 'B');
        arunca(fn () => Lansare::verificaVendor($d), \RuntimeException::class, 'modificat');
        file_put_contents("$d/vendor/lib/a.php", 'A');
        file_put_contents("$d/vendor/lib/b.php", 'B');
        arunca(fn () => Lansare::verificaVendor($d), \RuntimeException::class, 'absent din VENDOR.lock');
        unlink("$d/vendor/lib/b.php");
        unset($lock['biblioteci'][0]['licenta']);
        file_put_contents("$d/vendor/VENDOR.lock", json_encode($lock));
        arunca(fn () => Lansare::verificaVendor($d), \RuntimeException::class, 'licenta');
    },

    'funcțiile pe care găzduirea le poate opri se cheamă doar după function_exists' => function (Mediu $m): void {
        $r = Mediu::radacina();
        $functii = ['ini_set', 'set_time_limit', 'ignore_user_abort', 'disk_free_space', 'curl_init', 'curl_exec', 'chmod', 'umask', 'flock'];
        $gasite = 0;
        foreach (Lansare::fisiere($r) as $rel) {
            if (!str_ends_with($rel, '.php') || str_starts_with($rel, 'vendor/')) {
                continue;
            }
            $cod = (string) file_get_contents("$r/$rel");
            // Apelurile reale: numele urmat de paranteză, fără să fie metodă, declarație, șir sau parte a altui nume.
            preg_match_all('~(?<!function )(?<![\\w\'>:$])(' . implode('|', $functii) . ')\(~', $cod, $x, PREG_OFFSET_CAPTURE);
            foreach ($x[1] as [$f, $poz]) {
                $gasite++;
                $inainte = substr($cod, max(0, $poz - 200), min(200, $poz));
                $linie = substr_count(substr($cod, 0, $poz), "\n") + 1;
                adevarat(str_contains($inainte, "function_exists('$f')"), "$rel:$linie cheamă $f fără function_exists");
            }
        }
        adevarat($gasite >= 15, 'apelurile găsite');
        // Cele trei fișiere încărcate de public/index.php înaintea aplicației.
        foreach (['app/porneste.php', 'app/instalare.php', 'app/cache-pagini.php'] as $rel) {
            contine((string) file_get_contents("$r/$rel"), "if (function_exists('ini_set')) {", $rel);
        }
    },
];
