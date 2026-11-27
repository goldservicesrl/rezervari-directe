<?php

declare(strict_types=1);

// bin/lansare.php: arhiva reproductibilă, conținutul ei și pagina de început servită din lansarea dezarhivată.

use Cazo\Dezvoltare\Instanta;
use Cazo\Dezvoltare\Lansare;
use Cazo\Nucleu\Csp;

return [
    'arhiva e reproductibilă și are structura așteptată' => function (Mediu $m): void {
        $r = Mediu::radacina();
        // Data fișierelor e data construirii; SOURCE_DATE_EPOCH o fixează, iar arhiva e atunci reproductibilă.
        $epoca = getenv('SOURCE_DATE_EPOCH');
        putenv('SOURCE_DATE_EPOCH=1763600000');
        try {
            $a = Lansare::construieste($r, $m->dosarTemporar(), false);
            $b = Lansare::construieste($r, $m->dosarTemporar(), false);
        } finally {
            putenv($epoca === false ? 'SOURCE_DATE_EPOCH' : 'SOURCE_DATE_EPOCH=' . $epoca);
        }
        egal($a['sha256'], $b['sha256'], 'două construcții dau arhive diferite');
        $ver = $a['versiune'];
        egal('cazo-' . $ver . '.zip', basename($a['cale']));
        $z = new \ZipArchive();
        adevarat($z->open($a['cale']) === true);
        $nume = [];
        for ($k = 0; $k < $z->numFiles; $k++) {
            $nume[] = $z->getNameIndex($k);
        }
        $radacini = array_values(array_unique(array_map(fn (string $n) => explode('/', $n)[0], $nume)));
        sort($radacini);
        egal(['cazo-privat', 'public_html'], $radacini);
        foreach (['public_html/index.php', 'public_html/.htaccess', 'public_html/_c/.htaccess', 'public_html/_t/.htaccess',
            'public_html/_s/.htaccess', 'public_html/media/.htaccess', 'cazo-privat/curent.php', 'cazo-privat/cron.php',
            "cazo-privat/lansari/$ver/app/porneste.php", "cazo-privat/lansari/$ver/MANIFEST.json",
            "cazo-privat/lansari/$ver/migrari/0001_baza.php", "cazo-privat/lansari/$ver/limbi/ro.php",
            "cazo-privat/lansari/$ver/vendor/VENDOR.lock", "cazo-privat/lansari/$ver/bin/cron.php"] as $f) {
            adevarat(in_array($f, $nume, true), "lipsește $f");
        }
        foreach ($nume as $n) {
            adevarat(!preg_match('~/(teste|docs|front|local|dist|\.git)/~', $n), "în arhivă: $n");
            adevarat(!str_ends_with($n, 'semneaza.php'), 'cheia de semnare ajunge în arhivă');
            if (str_starts_with($n, 'public_html/') && preg_match('~\.php$~', $n)) {
                egal('public_html/index.php', $n, 'alt PHP public');
            }
        }
        egal("<?php\n\ndefined('CAZO') or exit;\n\nreturn '$ver';\n", $z->getFromName('cazo-privat/curent.php'));
        egal((string) file_get_contents(Mediu::radacina() . '/bin/cron-privat.php'), $z->getFromName('cazo-privat/cron.php'));
        foreach (Lansare::DOSARE_ACTIVE as $d) {
            egal((string) file_get_contents(Mediu::radacina() . '/public/.htaccess-active'), $z->getFromName("public_html/$d/.htaccess"), $d);
        }
        $manifest = json_decode((string) $z->getFromName("cazo-privat/lansari/$ver/MANIFEST.json"), true);
        egal($ver, $manifest['versiune']);
        egal(count($nume) - count(array_filter($nume, fn ($n) => str_ends_with($n, '/'))) - 9, count($manifest['fisiere']));
        foreach ($manifest['fisiere'] as $rel => $sha) {
            egal($sha, hash('sha256', (string) $z->getFromName("cazo-privat/lansari/$ver/$rel")), "hash greșit: $rel");
        }
        $z->close();
    },

    'lansarea dezarhivată servește pagina de început cu tema și CSP-ul final' => function (Mediu $m): void {
        $a = Lansare::construieste(Mediu::radacina(), $m->dosarTemporar(), false);
        $dir = $m->dosarTemporar();
        $z = new \ZipArchive();
        $z->open($a['cale']);
        $z->extractTo($dir);
        $z->close();
        $r = Instanta::configureaza($dir, ['site_url' => 'https://cazo.test', 'baza' => $m->descriereBaza(), 'prefix' => $m->prefix()]);
        adevarat(!is_link($r['privat'] . '/lansari/dev') && !file_exists($r['privat'] . '/lansari/dev'), 'instanța din arhivă a primit lansarea de dezvoltare');
        $s = ServerTest::porneste($r['public'], $r['public'] . '/index.php');
        try {
            $rasp = $s->cerere('GET', '/');
            egal(200, $rasp['status'], $rasp['corp']);
            contine($rasp['corp'], 'data-pg="legal"');
            egal(Csp::pentru('public', 'https://cazo.test', ['placi_harta' => 'https://tile.openstreetmap.org']), $rasp['antete']['content-security-policy'] ?? null);
            // Activele nucleului vin din active/ al lansării (front/ rămâne în depozit) și se servesc din _c/.
            adevarat((bool) preg_match('~href="(/_c/[^"]+/css/cazo\.[0-9a-f]{10}\.css)"~', $rasp['corp'], $css), 'foaia nucleului lipsește');
            adevarat(is_file($r['public'] . $css[1]), 'foaia nucleului nepublicată');
            // Codul servit e cel din lansări, nu cel din depozit.
            file_put_contents($r['privat'] . '/curent.php', "<?php\n\nreturn 'alta';\n");
            egal(503, $s->cerere('GET', '/')['status']);
        } finally {
            $s->opreste();
        }
    },

    'bin/lansare.php rulează din linia de comandă' => function (Mediu $m): void {
        $out = $m->dosarTemporar();
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(Mediu::radacina() . '/bin/lansare.php') . ' --fara-teste ' . escapeshellarg('--iesire=' . $out) . ' 2>&1', $iesire, $cod);
        egal(0, $cod, implode("\n", $iesire));
        contine(implode("\n", $iesire), 'sha256 ');
        egal(1, count(glob("$out/cazo-*.zip")));
    },
];
