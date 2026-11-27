<?php

declare(strict_types=1);

// Instalatorul prin HTTP real (php -S), pe arhiva livrată dezarhivată ca în cPanel: încărcătorul găsește dosarul
// privat fără .cazo-cale.php și pornește instalatorul înaintea aplicației; codul, sesiunea, proba rescrierii;
// după instalare, /instalare/ primește 404 din aplicație. Sonda, pe un server real: varianta B neprotejată
// (fișierele servite direct) oprește instalarea, cea protejată trece.

use Cazo\Dezvoltare\Lansare;
use Cazo\Instalare\Http;
use Cazo\Instalare\Sonda;

/** Arhiva construită și dezarhivată într-un „cont” nou (public_html/ și cazo-privat/). */
$cont = static function (Mediu $m): string {
    $zip = Lansare::construieste(Mediu::radacina(), $m->dosarTemporar(), false)['cale'];
    $dir = $m->dosarTemporar();
    $z = new \ZipArchive();
    $z->open($zip);
    $z->extractTo($dir);
    $z->close();
    return $dir;
};

return [
    'arhiva livrată: încărcătorul pornește instalatorul, codul deschide pașii, rescrierea se verifică' => function (Mediu $m) use ($cont): void {
        $dir = $cont($m);
        adevarat(!is_file("$dir/public_html/.cazo-cale.php") && !is_file("$dir/cazo-privat/config.php"), 'arhiva are configurarea');
        $s = ServerTest::porneste("$dir/public_html", "$dir/public_html/index.php");
        try {
            $r = $s->cerere('GET', '/');
            egal(303, $r['status'], $r['corp']);
            egal('/?r=%2Finstalare%2F', $r['antete']['location'] ?? null);
            $r = $s->cerere('GET', '/instalare/');
            egal(200, $r['status'], $r['corp']);
            contine($r['corp'], 'Codul de instalare');
            contine($r['corp'], 'cazo-privat/COD-INSTALARE.txt');
            egal('noindex', $r['antete']['x-robots-tag'] ?? null);
            egal('no-store', $r['antete']['cache-control'] ?? null);
            contine($r['antete']['content-security-policy'] ?? '', "default-src 'none'");
            // Foaia de stil a panoului, publicată din lansare în _c/.
            adevarat((bool) preg_match('~href="(/_c/[^"]+admin\.[0-9a-f]{10}\.css)"~', $r['corp'], $css), 'foaia panoului lipsește');
            egal(200, $s->cerere('GET', $css[1])['status']);
            adevarat(is_file("$dir/cazo-privat/COD-INSTALARE.txt"));
            adevarat(is_file("$dir/cazo-privat/instalare/stare.php"));
            preg_match('~: ([A-Z0-9]{8})$~m', (string) file_get_contents("$dir/cazo-privat/COD-INSTALARE.txt"), $cod);
            preg_match('~pg_instalare_csrf=([A-Za-z0-9_-]{43})~', $r['antete']['set-cookie'] ?? '', $csrf);
            adevarat(isset($csrf[1]), 'cookie-ul CSRF lipsește');
            $r = $s->cerere('POST', '/instalare/', ['Cookie' => 'pg_instalare_csrf=' . $csrf[1], 'Content-Type' => 'application/x-www-form-urlencoded'],
                http_build_query(['_csrf' => $csrf[1], 'cod' => 'GRESIT12']));
            egal(403, $r['status']);
            $r = $s->cerere('POST', '/instalare/', ['Cookie' => 'pg_instalare_csrf=' . $csrf[1], 'Content-Type' => 'application/x-www-form-urlencoded'],
                http_build_query(['_csrf' => $csrf[1], 'cod' => $cod[1], 'licenta' => '1']));
            egal(303, $r['status'], $r['corp']);
            contine($r['antete']['set-cookie'] ?? '', 'pg_instalare=');
            contine($r['antete']['set-cookie'] ?? '', 'SameSite=Strict');
            // Proba rescrierii din pasul 2: cu rescriere, /__cazo_test ajunge la index.php.
            $r = $s->cerere('GET', '/__cazo_test');
            egal('cazo-rescriere-ok', $r['corp']);
            // Fișierele dosarului privat rămân în afara webroot (varianta A).
            egal(404, $s->cerere('GET', '/cazo-privat/COD-INSTALARE.txt')['status']);
        } finally {
            $s->opreste();
        }
        // Fără rescriere (php -S fără router): instalatorul merge prin /?r=.
        $s = ServerTest::porneste("$dir/public_html", null);
        try {
            $r = $s->cerere('GET', '/?r=/instalare/');
            egal(200, $r['status']);
            contine($r['corp'], 'Codul de instalare');
        } finally {
            $s->opreste();
        }
    },

    'după instalare: /instalare/ primește 404 din aplicație, oricare ar fi fișierele instalatorului' => function (Mediu $m) use ($cont): void {
        $dir = $cont($m);
        // Instalarea încheiată (aceiași pași de fișiere ca ultimul pas, prin bin/cazo configureaza).
        $i = \Cazo\Dezvoltare\Instanta::configureaza($dir, ['site_url' => 'http://127.0.0.1', 'baza' => $m->descriereBaza(), 'prefix' => $m->prefix()]);
        $s = ServerTest::porneste($i['public'], $i['public'] . '/index.php');
        try {
            egal(404, $s->cerere('GET', '/instalare/')['status']);
            egal(404, $s->cerere('GET', '/?r=/instalare/')['status']);
            egal(404, $s->cerere('GET', '/__cazo_test')['status']);
            egal(200, $s->cerere('GET', '/')['status']);
            // config.php dispare: pagina încărcătorului, iar instalatorul rămâne închis (marcajul).
            rename($i['privat'] . '/config.php', $i['privat'] . '/config.bak');
            $r = $s->cerere('GET', '/instalare/');
            egal(503, $r['status']);
            contine($r['corp'], 'verificare tehnică');
            lipseste($r['corp'], 'Codul de instalare');
            adevarat(!is_file($i['privat'] . '/COD-INSTALARE.txt'), 'un cod nou a fost scris pe un site instalat');
        } finally {
            $s->opreste();
        }
    },

    'ServerTest: după o cerere, testul vede fișierele schimbate de server, nu cache-ul stat al PHP' => function (Mediu $m): void {
        // PHP ține minte ultimul stat() (is_dir, is_file); înainte de 8.3.19, deschiderea unui fișier nu golea cache-ul,
        // deci un is_dir repetat după cerere întorcea starea dinaintea ei (dosarul redenumit de server „exista” încă).
        $d = (string) realpath($m->dosarTemporar());
        mkdir("$d/web");
        mkdir("$d/web/vechi");
        file_put_contents("$d/web/router.php", "<?php\nrename(__DIR__ . '/vechi', __DIR__ . '/nou');\necho 'ok';\n");
        $s = ServerTest::porneste("$d/web", "$d/web/router.php");
        try {
            adevarat(is_dir("$d/web/vechi"));
            egal('ok', $s->cerere('GET', '/')['corp']);
            adevarat(!is_dir("$d/web/vechi"), 'is_dir a citit cache-ul stat de dinaintea cererii');
            adevarat(is_dir("$d/web/nou"));
        } finally {
            $s->opreste();
        }
    },

    'arhiva dezarhivată chiar în public_html: dosarul privat primește numele aleator după DOCUMENT_ROOT, codul rămâne ascuns' => function (Mediu $m): void {
        $zip = Lansare::construieste(Mediu::radacina(), $m->dosarTemporar(), false)['cale'];
        $dir = (string) realpath($m->dosarTemporar());
        mkdir("$dir/public_html");
        $z = new \ZipArchive();
        $z->open($zip);
        $z->extractTo("$dir/public_html");
        $z->close();
        adevarat(is_file("$dir/public_html/public_html/index.php") && is_dir("$dir/public_html/cazo-privat"));
        // Webroot-ul serverului e public_html; index.php a ajuns în public_html/public_html.
        $s = ServerTest::porneste("$dir/public_html", null);
        try {
            $s->cerere('GET', '/public_html/?r=/instalare/');
            adevarat(!is_dir("$dir/public_html/cazo-privat"), 'dosarul privat a rămas în webroot cu numele din arhivă');
            egal(404, $s->cerere('GET', '/cazo-privat/COD-INSTALARE.txt')['status']);
            egal(404, $s->cerere('GET', '/cazo-privat/instalare/stare.php')['status']);
            $privat = glob("$dir/public_html/cazo-privat-*", GLOB_ONLYDIR)[0] ?? '';
            adevarat((bool) preg_match('~/cazo-privat-[a-z0-9]{16}$~', $privat), $privat);
            contine((string) file_get_contents("$privat/.htaccess"), 'Require all denied');
            $cod = (string) file_get_contents("$privat/COD-INSTALARE.txt");
        } finally {
            $s->opreste();
        }
        // Gazda mută fișierele publice în public_html: încărcătorul găsește dosarul redenumit; locul s-a schimbat, deci
        // codul se scrie din nou, tot în dosarul protejat.
        foreach (scandir("$dir/public_html/public_html") ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                rename("$dir/public_html/public_html/$f", "$dir/public_html/$f");
            }
        }
        $s = ServerTest::porneste("$dir/public_html", "$dir/public_html/index.php");
        try {
            $r = $s->cerere('GET', '/instalare/');
            egal(200, $r['status'], $r['corp']);
            contine($r['corp'], 'public_html/cazo-privat-…/COD-INSTALARE.txt');
            adevarat($cod !== (string) file_get_contents("$privat/COD-INSTALARE.txt"), 'codul a rămas același după mutare');
            egal('B', \Cazo\Instalare\Stare::pentru($privat)->get('varianta'));
        } finally {
            $s->opreste();
        }
    },

    'sonda pe un server real: varianta B neprotejată oprește instalarea; protejată trece' => function (Mediu $m): void {
        $d = $m->dosarTemporar();
        $privat = "$d/public_html/cazo-privat-" . str_repeat('a', 16);
        mkdir($privat, 0755, true);
        file_put_contents("$privat/curent.php", "<?php\n\ndefined('CAZO') or exit;\n\nreturn 'dev';\n");
        mkdir("$privat/lansari");
        \Cazo\Dezvoltare\Instanta::legaLansarea(Mediu::radacina(), "$privat/lansari/dev");
        file_put_contents("$privat/cheie.bin", random_bytes(32));
        // Serverul fără nicio protecție (php -S fără router servește orice fișier): fiecare capcană primește 200.
        $s = ServerTest::porneste("$d/public_html", null);
        try {
            $r = Sonda::verifica($s->url(''), "$d/public_html", $privat, Http::curl(5), ['cheie.bin']);
            egal(false, $r['ok']);
            egal(true, $r['necesara']);
            foreach ($r['rezultate'] as $x) {
                egal(200, $x['status'], $x['fisier']);
            }
        } finally {
            $s->opreste();
        }
        // Protejat: totul trece prin index.php (ca .htaccess-ul cu „Require all denied” și rescrierea): încărcătorul
        // găsește dosarul B, pornește instalatorul, iar acesta răspunde 404 la orice fișier din dosar.
        copy(Mediu::radacina() . '/public/index.php', "$d/public_html/index.php");
        $s = ServerTest::porneste("$d/public_html", "$d/public_html/index.php");
        try {
            $r = Sonda::verifica($s->url(''), "$d/public_html", $privat, Http::curl(5), ['cheie.bin']);
            egal(true, $r['ok'], json_encode($r));
        } finally {
            $s->opreste();
        }
        egal([], glob("$privat/sonda-*"));
    },
];
