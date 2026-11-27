<?php

declare(strict_types=1);

// Încărcătorul pe lansări, prin HTTP real (php -S): pagina de început servită cu CSP-ul final, cu și fără rescriere,
// cache-ul de pagini, lansarea lipsă, întreținerea, cronul extern și pseudo-cronul. Pe SQLite și pe MySQL.

use Cazo\Dezvoltare\Instanta;
use Cazo\Nucleu\Antete;
use Cazo\Nucleu\CachePagini;
use Cazo\Nucleu\Csp;
use Cazo\Nucleu\Intretinere;

/** Instanță locală configurată ca de bin/cazo, pe baza testului. */
$instanta = static function (Mediu $m, string $siteUrl, bool $rescriere = true): array {
    $dir = $m->dosarTemporar();
    $r = Instanta::configureaza($dir, [
        'site_url' => $siteUrl,
        'lansare_dev' => Mediu::radacina(),
        'rescriere' => $rescriere,
        'baza' => $m->descriereBaza(),
        'prefix' => $m->prefix(),
    ]);
    return $r + ['dir' => $dir];
};

/** Scrie o pagină în cache, cu cheia curentă (versiune, generație, limbă, adresă). */
$inCache = static function (string $privat, string $limba, string $uri, string $corp, array $antete = ['Content-Type' => 'text/html; charset=utf-8']): string {
    $f = CachePagini::fisier($privat, $limba, $uri);
    @mkdir(dirname($f), 0700, true);
    file_put_contents($f, json_encode($antete) . "\n" . $corp);
    return $f;
};

return [
    'pagina de început e servită cu CSP-ul final și cu toate antetele' => function (Mediu $m) use ($instanta): void {
        $i = $instanta($m, 'https://cazo.test');
        $s = ServerTest::porneste($i['public'], $i['public'] . '/index.php');
        try {
            $r = $s->cerere('GET', '/');
            egal(200, $r['status'], 'GET /: ' . $r['corp']);
            contine($r['corp'], 'data-pg="legal"');
            contine($r['corp'], '<h1');
            contine($r['corp'], '<html lang="ro">');
            // Pagina de început are harta: img-src primește serverul de plăci, cerut când harta se apropie de ecran.
            egal(Csp::pentru('public', 'https://cazo.test', ['placi_harta' => 'https://tile.openstreetmap.org']), $r['antete']['content-security-policy'] ?? null);
            egal(
                "default-src 'none'; script-src https://cazo.test/_c/; style-src 'self'; img-src 'self' data: https://tile.openstreetmap.org; font-src 'self'; "
                . "connect-src 'self'; manifest-src 'self'; form-action 'self' https://checkout.stripe.com; frame-ancestors 'none'; "
                . "base-uri 'none'; object-src 'none'; upgrade-insecure-requests; report-uri /_csp; report-to csp",
                $r['antete']['content-security-policy']
            );
            egal('csp="https://cazo.test/_csp"', $r['antete']['reporting-endpoints'] ?? null);
            egal('nosniff', $r['antete']['x-content-type-options'] ?? null);
            egal('strict-origin-when-cross-origin', $r['antete']['referrer-policy'] ?? null);
            egal(Antete::PERMISSIONS, $r['antete']['permissions-policy'] ?? null);
            egal('DENY', $r['antete']['x-frame-options'] ?? null);
            egal(Antete::CACHE_PUBLIC, $r['antete']['cache-control'] ?? null);
            egal('noindex', $r['antete']['x-robots-tag'] ?? null, 'site nepublicat fără noindex');
            egal('text/html; charset=utf-8', $r['antete']['content-type'] ?? null);
            adevarat(!isset($r['antete']['x-powered-by']), 'X-Powered-By trimis');
            contine($r['corp'], '<link rel="alternate" hreflang="en" href="https://cazo.test/en/">');
            contine($r['corp'], '<link rel="canonical" href="https://cazo.test/">');

            $en = $s->cerere('GET', '/en/');
            egal(200, $en['status']);
            contine($en['corp'], 'data-pg="legal"');
            contine($en['corp'], '<html lang="en">');
            egal($r['antete']['content-security-policy'], $en['antete']['content-security-policy']);

            $head = $s->cerere('HEAD', '/');
            egal(200, $head['status']);
            egal('', $head['corp']);
            egal($r['antete']['content-security-policy'], $head['antete']['content-security-policy']);
        } finally {
            $s->opreste();
        }
    },

    'fără rescriere: ?r= și /index.php/ duc la aceleași pagini' => function (Mediu $m) use ($instanta): void {
        $i = $instanta($m, 'http://127.0.0.1', false);
        // Fără script router: php -S servește index.php doar pentru / și /index.php, ca un server fără rescriere.
        $s = ServerTest::porneste($i['public'], null);
        try {
            foreach (['/?r=/en/', '/index.php?r=/en/', '/index.php/en/', '/?r=%2Fen%2F'] as $uri) {
                $r = $s->cerere('GET', $uri);
                egal(200, $r['status'], $uri);
                contine($r['corp'], '<html lang="en">');
                contine($r['corp'], 'data-pg="legal"');
                egal(Csp::pentru('public', 'http://127.0.0.1', ['placi_harta' => 'https://tile.openstreetmap.org']), $r['antete']['content-security-policy'] ?? null, $uri);
            }
            $r = $s->cerere('GET', '/?r=/pagina-inexistenta');
            egal(404, $r['status']);
            egal('no-store', $r['antete']['cache-control'] ?? null);
            // Adresele generate de site folosesc forma ?r= când rescrierea lipsește.
            contine($r['corp'], 'href="/"');
            $en404 = $s->cerere('GET', '/?r=/en/x');
            contine($en404['corp'], 'href="/?r=%2Fen%2F"');
            // Fișierele ascunse din public_html nu se execută ca pagini: .cazo-cale.php întoarce doar o cale, fără ieșire.
            egal('', $s->cerere('GET', '/.cazo-cale.php')['corp']);
        } finally {
            $s->opreste();
        }
    },

    'cache-ul de pagini se servește fără aplicație; cookie-urile de comandă și de panou îl ocolesc' => function (Mediu $m) use ($instanta, $inCache): void {
        $i = $instanta($m, 'http://127.0.0.1');
        $inCache($i['privat'], 'ro', '/', '<p>din cache</p>', ['Content-Type' => 'text/html; charset=utf-8', 'Content-Security-Policy' => "default-src 'none'"]);
        $inCache($i['privat'], 'en', '/en/', '<p>cache en</p>');
        // Pseudo-cronul tocmai a rulat: testul acesta privește doar cache-ul.
        touch($i['privat'] . '/' . CachePagini::FISIER_PSEUDO_CRON);
        $s = ServerTest::porneste($i['public'], $i['public'] . '/index.php');
        try {
            $r = $s->cerere('GET', '/');
            egal('<p>din cache</p>', $r['corp']);
            egal('hit', $r['antete']['x-cazo-cache'] ?? null);
            egal("default-src 'none'", $r['antete']['content-security-policy'] ?? null);
            egal('<p>cache en</p>', $s->cerere('GET', '/en/')['corp']);
            foreach (['__Host-pg_comanda', '__Host-pg_admin'] as $cookie) {
                $r = $s->cerere('GET', '/', ['Cookie' => $cookie . '=x']);
                contine($r['corp'], 'data-pg="legal"');
                adevarat(!isset($r['antete']['x-cazo-cache']), "$cookie a primit pagina din cache");
            }
            adevarat(!isset($s->cerere('POST', '/')['antete']['x-cazo-cache']), 'POST servit din cache');
            egal('', $s->cerere('HEAD', '/')['corp']);
        } finally {
            $s->opreste();
        }
    },

    'cheia de cache cuprinde versiunea aplicației și generația conținutului (oglinda, tema)' => function (Mediu $m) use ($instanta, $inCache): void {
        $c = CachePagini::cheie('1.0.0', '0', 'ro', '/');
        adevarat($c !== CachePagini::cheie('1.0.1', '0', 'ro', '/'), 'versiunea aplicației lipsește din cheie');
        adevarat($c !== CachePagini::cheie('1.0.0', '7.3', 'ro', '/'), 'generația lipsește din cheie');
        adevarat($c !== CachePagini::cheie('1.0.0', '0', 'en', '/'), 'limba lipsește din cheie');
        adevarat($c !== CachePagini::cheie('1.0.0', '0', 'ro', '/?a=1'), 'adresa lipsește din cheie');
        $i = $instanta($m, 'http://127.0.0.1');
        egal('0', CachePagini::generatie($i['privat']));
        $inCache($i['privat'], 'ro', '/', '<p>generația veche</p>');
        touch($i['privat'] . '/' . CachePagini::FISIER_PSEUDO_CRON);
        $s = ServerTest::porneste($i['public'], $i['public'] . '/index.php');
        try {
            egal('<p>generația veche</p>', $s->cerere('GET', '/')['corp']);
            // Oglinda sau tema se schimbă: pagina veche nu se mai potrivește, răspunde aplicația.
            CachePagini::schimbaGeneratia($i['privat'], '42', '3');
            egal('42.3', CachePagini::generatie($i['privat']));
            $r = $s->cerere('GET', '/', ['Cookie' => '__Host-pg_admin=x']);
            contine($r['corp'], 'data-pg="legal"');
            adevarat(!isset($r['antete']['x-cazo-cache']), 'pagina generației vechi servită');
            @unlink(CachePagini::fisier($i['privat'], 'ro', '/'));
            $r = $s->cerere('GET', '/');
            contine($r['corp'], 'data-pg="legal"');
            adevarat(!isset($r['antete']['x-cazo-cache']), 'pagina generației vechi servită');
            $inCache($i['privat'], 'ro', '/', '<p>generația nouă</p>');
            egal('<p>generația nouă</p>', $s->cerere('GET', '/')['corp']);
        } finally {
            $s->opreste();
        }
        arunca(fn () => CachePagini::schimbaGeneratia($i['privat'], '../x', '1'), \InvalidArgumentException::class);
    },

    'pseudo-cronul rulează și după o pagină servită din cache' => function (Mediu $m) use ($instanta, $inCache): void {
        $i = $instanta($m, 'http://127.0.0.1');
        $inCache($i['privat'], 'ro', '/', '<p>din cache</p>');
        $marcaj = $i['privat'] . '/' . CachePagini::FISIER_PSEUDO_CRON;
        @unlink($marcaj);
        $s = ServerTest::porneste($i['public'], $i['public'] . '/index.php');
        try {
            $r = $s->cerere('GET', '/');
            egal('<p>din cache</p>', $r['corp']);
            egal('hit', $r['antete']['x-cazo-cache'] ?? null);
            egal((string) strlen('<p>din cache</p>'), $r['antete']['content-length'] ?? null);
            $app = \Cazo\Nucleu\Aplicatie::dinDosar($i['privat']);
            $ultima = null;
            for ($k = 0; $k < 40 && $ultima === null; $k++) {
                usleep(50_000);
                $ultima = $app->baza()->valoare("SELECT valoare FROM {setari} WHERE cheie = 'cron.ultima'");
            }
            adevarat($ultima !== null, 'pseudo-cronul nu a rulat după pagina din cache');
            egal('pseudo', json_decode((string) $ultima, true)['sursa']);
            // În intervalul de 60 s, pagina din cache pleacă fără lucru după ea.
            $acum = time() - 30;
            touch($marcaj, $acum);
            egal('<p>din cache</p>', $s->cerere('GET', '/')['corp']);
            usleep(300_000);
            clearstatcache();
            egal($acum, filemtime($marcaj), 'pseudo-cronul a rulat de două ori într-un minut');
        } finally {
            $s->opreste();
        }
    },

    'lansarea lipsă sau configurarea deteriorată: 503 cu textul încărcătorului' => function (Mediu $m) use ($instanta): void {
        $i = $instanta($m, 'http://127.0.0.1');
        $s = ServerTest::porneste($i['public'], $i['public'] . '/index.php');
        try {
            file_put_contents($i['privat'] . '/curent.php', "<?php\n\ndefined('CAZO') or exit;\n\nreturn '9.9.9';\n");
            $r = $s->cerere('GET', '/');
            egal(503, $r['status']);
            contine($r['corp'], 'Revenim în câteva minute');
            contine($r['corp'], '<title>Revenim în câteva minute / We’ll be back in a few minutes</title>');
            contine($r['corp'], 'Facem câteva îmbunătățiri pe site.');
            egal('300', $r['antete']['retry-after'] ?? null);
            egal('no-store', $r['antete']['cache-control'] ?? null);
            file_put_contents($i['privat'] . '/curent.php', "<?php\n\nreturn '../../etc';\n");
            egal(503, $s->cerere('GET', '/')['status'], 'versiune cu traversare acceptată');
            file_put_contents($i['privat'] . '/curent.php', "<?php\n\ndefined('CAZO') or exit;\n\nreturn 'dev';\n");
            egal(200, $s->cerere('GET', '/')['status']);
            rename($i['privat'] . '/config.php', $i['privat'] . '/config.php.bak');
            // O adresă cu parametri ocolește cache-ul de pagini (pagina de început tocmai a intrat în el).
            $r = $s->cerere('GET', '/?proba=1');
            egal(503, $r['status']);
            contine($r['corp'], 'Revenim în câteva minute');
            contine($r['corp'], 'We’ll be back in a few minutes');
            contine($r['corp'], 'verificare tehnică');
            rename($i['privat'] . '/config.php.bak', $i['privat'] . '/config.php');
            unlink($i['public'] . '/.cazo-cale.php');
            $r = $s->cerere('GET', '/?proba=1');
            egal(503, $r['status']);
            contine($r['corp'], 'We’re running a technical check on the site.');
        } finally {
            $s->opreste();
        }
    },

    'întreținerea: 503 pentru pagini, cache-ul rămâne servit' => function (Mediu $m) use ($instanta, $inCache): void {
        $i = $instanta($m, 'http://127.0.0.1');
        $inCache($i['privat'], 'en', '/en/', '<p>cache en</p>');
        Intretinere::porneste($i['privat'], time() + 300);
        $s = ServerTest::porneste($i['public'], $i['public'] . '/index.php');
        try {
            $r = $s->cerere('GET', '/');
            egal(503, $r['status']);
            egal('120', $r['antete']['retry-after'] ?? null);
            egal('<p>cache en</p>', $s->cerere('GET', '/en/')['corp']);
            Intretinere::opreste($i['privat']);
            egal(200, $s->cerere('GET', '/')['status']);
        } finally {
            $s->opreste();
        }
    },

    'cronul extern prin URL și cronul din linia de comandă rulează sarcinile' => function (Mediu $m) use ($instanta): void {
        $i = $instanta($m, 'http://127.0.0.1');
        $s = ServerTest::porneste($i['public'], $i['public'] . '/index.php');
        try {
            egal(404, $s->cerere('GET', '/_cron/' . str_repeat('x', 43))['status']);
            $r = $s->cerere('GET', '/_cron/' . $i['cron_token']);
            egal(200, $r['status'], $r['corp']);
            // Curățenia, sincronizarea EasyCheck (fără cheie încă), pozele, cererile cash, cutia de e-mailuri, datele externe
            // și paritatea, căutarea versiunii noi (în mediul de test, fără nicio cerere spre GitHub); sarcinile
            // plății online se adaugă doar pe un site care o folosește. A noua: starea site-ului spre EasyCheck (
            // fără cheie, tace). A zecea: trecerea pe adresa nouă (fără trecere în curs, nimic).
            potriveste_json($r['corp'], ['ok' => true, 'rulat' => true, 'sarcini' => 10]);
            egal("default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'", $r['antete']['content-security-policy'] ?? null);
        } finally {
            $s->opreste();
        }
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . trim((string) getenv('CAZO_TEST_PHP_ARGS')) . ' '
            . escapeshellarg(Mediu::radacina() . '/bin/cron.php') . ' ' . escapeshellarg('--privat=' . $i['privat']) . ' 2>&1';
        exec($cmd, $iesire, $cod);
        egal(0, $cod, implode("\n", $iesire));
        potriveste_json(implode('', $iesire), ['rulat' => true]);
        $app = \Cazo\Nucleu\Aplicatie::dinDosar($i['privat']);
        egal('cpanel', $app->setari()->get('cron.ultima')['sursa']);

        // Linia din cPanel indică intrarea stabilă cazo-privat/cron.php, care pornește lansarea curentă.
        $app->baza()->executa("DELETE FROM {setari} WHERE cheie = 'cron.ultima'");
        $stabil = escapeshellarg(PHP_BINARY) . ' ' . trim((string) getenv('CAZO_TEST_PHP_ARGS')) . ' -q ' . escapeshellarg($i['privat'] . '/cron.php') . ' 2>&1';
        $iesire = [];
        exec($stabil, $iesire, $cod);
        egal(0, $cod, implode("\n", $iesire));
        potriveste_json(implode('', $iesire), ['rulat' => true]);
        egal('cpanel', \Cazo\Nucleu\Aplicatie::dinDosar($i['privat'])->setari()->get('cron.ultima')['sursa']);
        file_put_contents($i['privat'] . '/curent.php', "<?php\n\ndefined('CAZO') or exit;\n\nreturn '9.9.9';\n");
        $iesire = [];
        exec($stabil, $iesire, $cod);
        egal(2, $cod, 'lansarea lipsă: ' . implode("\n", $iesire));
    },

    'pseudo-cronul rulează după o pagină publică, cel mult o dată la 60 de secunde' => function (Mediu $m) use ($instanta): void {
        $i = $instanta($m, 'http://127.0.0.1');
        $s = ServerTest::porneste($i['public'], $i['public'] . '/index.php');
        try {
            $r = $s->cerere('GET', '/');
            egal(200, $r['status']);
            $app = \Cazo\Nucleu\Aplicatie::dinDosar($i['privat']);
            $ultima = null;
            for ($k = 0; $k < 40 && $ultima === null; $k++) {
                usleep(50_000);
                $ultima = $app->baza()->valoare("SELECT valoare FROM {setari} WHERE cheie = 'cron.ultima'");
            }
            adevarat($ultima !== null, 'pseudo-cronul nu a rulat');
            egal('pseudo', json_decode((string) $ultima, true)['sursa']);
            $marcaj = time() - 30;
            touch($i['privat'] . '/cache/pseudo-cron', $marcaj);
            $r2 = $s->cerere('GET', '/');
            egal(200, $r2['status']);
            usleep(300_000);
            clearstatcache();
            egal($marcaj, filemtime($i['privat'] . '/cache/pseudo-cron'), 'pseudo-cronul a rulat de două ori într-un minut');
            // Răspunsurile tehnice și erorile nu pornesc pseudo-cronul.
            $marcaj = time() - 120;
            touch($i['privat'] . '/cache/pseudo-cron', $marcaj);
            $s->cerere('GET', '/nu-exista');
            usleep(300_000);
            clearstatcache();
            egal($marcaj, filemtime($i['privat'] . '/cache/pseudo-cron'), 'o pagină 404 a pornit pseudo-cronul');
        } finally {
            $s->opreste();
        }
    },

    'validările din /.well-known/ se servesc direct; restul adreselor de acolo ajung la Cazo' => function (Mediu $m) use ($instanta): void {
        $i = $instanta($m, 'http://127.0.0.1');
        mkdir($i['public'] . '/.well-known/acme-challenge', 0755, true);
        file_put_contents($i['public'] . '/.well-known/acme-challenge/jeton-AbC_123', 'jeton-AbC_123.amprenta');
        $s = ServerTest::porneste($i['public'], $i['public'] . '/index.php');
        try {
            $r = $s->cerere('GET', '/.well-known/acme-challenge/jeton-AbC_123');
            egal(200, $r['status']);
            egal('jeton-AbC_123.amprenta', $r['corp']);
            egal(404, $s->cerere('GET', '/.well-known/acme-challenge/altul')['status']);
        } finally {
            $s->opreste();
        }
    },

    '400 și 405 au textele lor, diferite de pagina negăsită' => function (Mediu $m) use ($instanta): void {
        $i = $instanta($m, 'http://127.0.0.1');
        $s = ServerTest::porneste($i['public'], $i['public'] . '/index.php');
        try {
            $r = $s->cerere('GET', '/?r=../../etc');
            egal(400, $r['status']);
            contine($r['corp'], 'Verificați adresa paginii');
            lipseste($r['corp'], 's-a mutat');
            $r = $s->cerere('GET', '/_csp');
            egal(405, $r['status']);
            contine($r['corp'], 'Deschideți pagina din meniul site-ului');
        } finally {
            $s->opreste();
        }
    },

    'fișierele private se creează cu 0600 (baza SQLite, curent.php, cron.php, jurnalul)' => function (Mediu $m) use ($instanta): void {
        if ($m->dialect !== 'sqlite') {
            return;
        }
        $dir = $m->dosarTemporar();
        $vechi = umask(0022);
        try {
            $r = Instanta::configureaza($dir, ['site_url' => 'http://127.0.0.1', 'lansare_dev' => Mediu::radacina()]);
            $app = \Cazo\Nucleu\Aplicatie::dinDosar($r['privat']);
            $app->jurnal()->info('proba');
        } finally {
            umask($vechi);
        }
        clearstatcache();
        foreach (['cazo.sqlite', 'curent.php', 'cron.php', 'config.php', 'cheie.bin', 'jurnale/cazo-' . gmdate('Y-m-d') . '.log'] as $f) {
            egal('0600', sprintf('%04o', fileperms($r['privat'] . '/' . $f) & 0777), $f);
        }
        egal('0700', sprintf('%04o', fileperms($r['privat']) & 0777), 'cazo-privat');
    },
];
