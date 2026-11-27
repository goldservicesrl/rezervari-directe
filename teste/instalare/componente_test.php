<?php

declare(strict_types=1);

// Piesele instalatorului: sonda și verificările cu medii simulate (grupa `instalare`), locul datelor (A, B prin
// redenumire, B prin copiere), starea, parolele compromise, marcajul instalării, cataloagele.

use Cazo\Dezvoltare\Instanta;
use Cazo\Instalare\Instalator;
use Cazo\Instalare\Loc;
use Cazo\Instalare\Sonda;
use Cazo\Instalare\Stare;
use Cazo\Instalare\Verificari;
use Cazo\Securitate\ParoleCompromise;

/** HTTP simulat: același status la orice adresă, cu adresele ținute minte. */
$http = static function (?int $status, array &$cerute): \Closure {
    return static function (string $url) use ($status, &$cerute): array {
        $cerute[] = $url;
        return ['status' => $status, 'corp' => '', 'eroare' => $status === null ? 'retea' : null];
    };
};

return [
    'sonda: varianta A nu are adresă web; la B, doar 403 și 404 trec' => function (Mediu $m) use ($http): void {
        $d = $m->dosarTemporar();
        mkdir("$d/public_html/cazo-privat-abc", 0700, true);
        mkdir("$d/cazo-privat", 0700);
        $cerute = [];
        $a = Sonda::verifica('https://x.ro', "$d/public_html", "$d/cazo-privat", $http(200, $cerute));
        egal(['necesara' => false, 'ok' => true, 'rezultate' => []], $a);
        egal([], $cerute);
        foreach ([403 => true, 404 => true, 200 => false, 301 => false, 500 => false, null => false] as $status => $ok) {
            $cerute = [];
            $status = $status === '' ? null : $status;
            $r = Sonda::verifica('https://x.ro', "$d/public_html", "$d/public_html/cazo-privat-abc", $http($status, $cerute));
            egal($ok, $r['ok'], 'status ' . var_export($status, true));
            egal(4, count($cerute), 'capcanele: php, bin, sqlite, txt (curent.php lipsește aici)');
            adevarat(str_starts_with($cerute[0], 'https://x.ro/cazo-privat-abc/sonda-cazo-sonda-'), $cerute[0]);
        }
        egal([], glob("$d/public_html/cazo-privat-abc/sonda-*"), 'capcanele au rămas');
        // Fișierele reale se întreabă doar dacă există.
        file_put_contents("$d/public_html/cazo-privat-abc/cheie.bin", 'x');
        $cerute = [];
        Sonda::verifica('https://x.ro', "$d/public_html", "$d/public_html/cazo-privat-abc", $http(404, $cerute), ['cheie.bin', 'lipsa.php'], false);
        egal(['https://x.ro/cazo-privat-abc/cheie.bin'], $cerute);
    },

    'verificările cu medii simulate: blocantele, avertismentele, HTTPS pe localhost, rescrierea' => function (Mediu $m): void {
        $ok = static fn (string $u): array => ['status' => str_ends_with($u, '/__cazo_test') ? 200 : 401, 'corp' => str_ends_with($u, '/__cazo_test') ? Verificari::MARCAJ_RESCRIERE : '', 'eroare' => null];
        $mediu = ['php' => '8.2.0', 'extensii' => ['sodium', 'curl', 'openssl', 'mbstring', 'json', 'zip', 'gd', 'pdo_sqlite'],
            'ini' => ['memory_limit' => '128M', 'max_execution_time' => '0', 'upload_max_filesize' => '8M', 'post_max_size' => '8M'], 'scriere' => ['cazo-privat' => true, 'public_html' => true]];
        $r = Verificari::ruleaza('https://site.ro', $mediu, $ok);
        egal(true, $r['ok']);
        egal(true, $r['rescriere']);
        egal([], array_column(array_filter($r['elemente'], static fn ($e) => !$e['ok']), 'id'));

        $r = Verificari::ruleaza('http://site.ro', ['php' => '8.1.9', 'extensii' => ['curl'], 'ini' => ['memory_limit' => '64M', 'max_execution_time' => '10', 'upload_max_filesize' => '2M', 'post_max_size' => '8M'],
            'scriere' => ['cazo-privat' => false, 'public_html' => true]],
            static fn (string $u): array => ['status' => null, 'corp' => '', 'eroare' => str_contains($u, 'stripe') || str_contains($u, 'easycheck') ? 'tls' : 'retea']);
        egal(false, $r['ok']);
        $e = array_column($r['elemente'], null, 'id');
        foreach (['php', 'extensii', 'https', 'tls_easycheck', 'scriere'] as $id) {
            egal([false, true], [$e[$id]['ok'], $e[$id]['blocant']], $id);
        }
        foreach (['sodium', 'memorie', 'timp', 'incarcare', 'tls_stripe', 'rescriere'] as $id) {
            egal([false, false], [$e[$id]['ok'], $e[$id]['blocant']], $id);
        }
        // sodium e doar recomandată: lipsa ei rămâne în afara listei de extensii obligatorii.
        lipseste($e['extensii']['valoare'], 'sodium');
        contine($e['extensii']['valoare'], 'openssl');
        adevarat(!in_array('sodium', Verificari::EXTENSII, true), 'sodium a rămas între extensiile obligatorii');
        // Fără sodium și cu restul în regulă: verificările trec, cu un singur avertisment.
        $r = Verificari::ruleaza('https://site.ro', ['extensii' => array_values(array_diff($mediu['extensii'], ['sodium']))] + $mediu, $ok);
        egal(true, $r['ok']);
        egal(['sodium'], array_column(array_filter($r['elemente'], static fn ($x) => !$x['ok']), 'id'));
        contine($e['extensii']['valoare'], 'pdo_mysql / pdo_sqlite');
        egal('tls', $e['tls_easycheck']['valoare']);
        egal('cazo-privat', $e['scriere']['valoare']);
        // HTTP pe localhost (calculatorul de dezvoltare, matricea Docker): trece.
        $r = Verificari::ruleaza('http://localhost:8080', $mediu, $ok);
        egal(true, array_column($r['elemente'], null, 'id')['https']['ok']);
        // Încărcarea: limita reală e cea mai mică dintre upload_max_filesize și post_max_size.
        $incarcare = static fn (string $u, string $p): array => array_column(Verificari::ruleaza('https://site.ro', ['ini' => ['upload_max_filesize' => $u, 'post_max_size' => $p] + $mediu['ini']] + $mediu, $ok)['elemente'], null, 'id')['incarcare'];
        egal([false, '2M'], array_values(array_intersect_key($incarcare('32M', '2M'), ['ok' => 1, 'valoare' => 1])));
        egal([false, '2M'], array_values(array_intersect_key($incarcare('2M', '64M'), ['ok' => 1, 'valoare' => 1])));
        egal([true, '32M'], array_values(array_intersect_key($incarcare('32M', '0'), ['ok' => 1, 'valoare' => 1])), 'post_max_size 0 = fără limită');
        egal([true, '16M'], array_values(array_intersect_key($incarcare('-1', '16M'), ['ok' => 1, 'valoare' => 1])));
        egal(-1, Verificari::octeti('-1'));
        egal(1024 ** 3, Verificari::octeti('1G'));
    },

    'locul datelor: A când dosarul se poate scrie; B prin redenumire în public_html; B prin copiere când A se poate doar citi' => function (Mediu $m): void {
        $d = $m->dosarTemporar();
        mkdir("$d/public_html");
        mkdir("$d/cazo-privat/lansari/1.0.0/app", 0755, true);
        file_put_contents("$d/cazo-privat/curent.php", "<?php return '1.0.0';");
        file_put_contents("$d/cazo-privat/lansari/1.0.0/app/porneste.php", '<?php');
        egal(['varianta' => 'A', 'privat' => "$d/cazo-privat", 'mutat' => null], Loc::pregateste("$d/public_html", "$d/cazo-privat"));
        egal('cazo-privat/COD-INSTALARE.txt', Loc::afisat("$d/public_html", "$d/cazo-privat", 'COD-INSTALARE.txt'));

        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            chmod("$d/cazo-privat", 0555);
            try {
                $b = Loc::pregateste("$d/public_html", "$d/cazo-privat");
            } finally {
                chmod("$d/cazo-privat", 0755);
            }
            egal(['B', 'copiat'], [$b['varianta'], $b['mutat']]);
            adevarat(is_file($b['privat'] . '/lansari/1.0.0/app/porneste.php'), 'lansarea necopiată');
            adevarat(is_file($b['privat'] . '/curent.php'));
            contine((string) file_get_contents($b['privat'] . '/.htaccess'), 'Require all denied');
            egal('public_html/cazo-privat-…', Loc::afisat("$d/public_html", $b['privat']));
        }

        $e = $m->dosarTemporar();
        mkdir("$e/public_html/cazo-privat", 0755, true);
        file_put_contents("$e/public_html/cazo-privat/curent.php", "<?php return '1.0.0';");
        $b = Loc::pregateste("$e/public_html", "$e/public_html/cazo-privat");
        egal(['B', 'redenumit'], [$b['varianta'], $b['mutat']]);
        adevarat(!is_dir("$e/public_html/cazo-privat"));
        adevarat((bool) preg_match('~^cazo-privat-[a-z0-9]{16}$~', basename($b['privat'])));
        // A doua vizită găsește dosarul redenumit și îl păstrează.
        egal(['varianta' => 'B', 'privat' => $b['privat'], 'mutat' => null], Loc::pregateste("$e/public_html", $b['privat']));
        Loc::structura($b['privat']);
        foreach (array_merge(Instanta::DOSARE, ['instalare']) as $dir) {
            adevarat(is_dir($b['privat'] . '/' . $dir), $dir);
        }
    },

    'webroot-ul după DOCUMENT_ROOT: arhiva dezarhivată în public_html se vede în webroot și se redenumește lângă ea' => function (Mediu $m): void {
        $d = (string) realpath($m->dosarTemporar());
        mkdir("$d/public_html/public_html", 0755, true);
        mkdir("$d/public_html/cazo-privat", 0755);
        file_put_contents("$d/public_html/cazo-privat/curent.php", "<?php return '1.0.0';");
        $public = "$d/public_html/public_html";
        // DOCUMENT_ROOT luat în seamă doar când cuprinde dosarul lui index.php; căile se compară după realpath.
        egal("$d/public_html", Loc::documentRoot("$d/public_html/", $public));
        egal("$d/public_html", Loc::documentRoot("$d/public_html/public_html/..", $public));
        egal(null, Loc::documentRoot('', $public));
        egal(null, Loc::documentRoot("$d/lipsa", $public));
        egal(null, Loc::documentRoot($m->dosarTemporar(), $public));
        egal(null, Loc::radacinaWeb($public, null, "$d/public_html/cazo-privat"), 'fără DOCUMENT_ROOT, doar dosarul lui index.php');
        egal("$d/public_html", Loc::radacinaWeb($public, "$d/public_html", "$d/public_html/cazo-privat"));
        egal(null, Loc::radacinaWeb("$d/public_html", "$d/public_html", "$d/cazo-privat"), 'varianta A trecută drept webroot');
        $b = Loc::pregateste($public, "$d/public_html/cazo-privat", "$d/public_html");
        egal(['B', 'redenumit'], [$b['varianta'], $b['mutat']]);
        egal("$d/public_html", dirname($b['privat']), 'dosarul redenumit a plecat de lângă arhivă');
        adevarat(!is_dir("$d/public_html/cazo-privat"));
        contine((string) file_get_contents($b['privat'] . '/.htaccess'), 'Require all denied');
        egal('public_html/cazo-privat-…/COD-INSTALARE.txt', Loc::afisat($public, $b['privat'], 'COD-INSTALARE.txt', "$d/public_html"));
        // Sonda întreabă adresele reale, de la DOCUMENT_ROOT.
        $cerute = [];
        $r = Sonda::verifica('https://x.ro', $public, $b['privat'], static function (string $url) use (&$cerute): array {
            $cerute[] = $url;
            return ['status' => 404, 'corp' => '', 'eroare' => null];
        }, [], true, "$d/public_html");
        adevarat($r['necesara'] && $r['ok']);
        adevarat(str_starts_with($cerute[0], 'https://x.ro/' . basename($b['privat']) . '/sonda-'), $cerute[0]);
    },

    'varianta B prin copiere: copia se face sub un nume temporar și primește numele final întreagă' => function (Mediu $m): void {
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0) {
            return; // ca root, chmod nu oprește citirea
        }
        $d = $m->dosarTemporar();
        mkdir("$d/public_html");
        mkdir("$d/cazo-privat/lansari/1.0.0/app", 0755, true);
        file_put_contents("$d/cazo-privat/curent.php", "<?php return '1.0.0';");
        file_put_contents("$d/cazo-privat/cron.php", '<?php');
        file_put_contents("$d/cazo-privat/lansari/1.0.0/app/porneste.php", '<?php');
        file_put_contents("$d/cazo-privat/lansari/1.0.0/app/z-ultimul.php", '<?php');
        chmod("$d/cazo-privat/lansari/1.0.0/app/z-ultimul.php", 0000);
        chmod("$d/cazo-privat", 0555);
        try {
            // Un fișier necitibil oprește copia la jumătate: în public_html rămâne nimic din ea.
            arunca(fn () => Loc::pregateste("$d/public_html", "$d/cazo-privat"), \RuntimeException::class);
            egal([], glob("$d/public_html/cazo-privat-*") ?: [], 'încărcătorul ar găsi o copie parțială');
            egal([], glob("$d/public_html/" . Loc::PREFIX_COPIERE . '*') ?: [], 'copia temporară a rămas');
            // Copiile rămase de la o vizită oprită de server (limita de timp) se șterg după COPIERE_UITATA.
            mkdir("$d/public_html/" . Loc::PREFIX_COPIERE . 'veche/lansari', 0700, true);
            mkdir("$d/public_html/" . Loc::PREFIX_COPIERE . 'recenta', 0700);
            touch("$d/public_html/" . Loc::PREFIX_COPIERE . 'veche', time() - Loc::COPIERE_UITATA - 60);
            chmod("$d/cazo-privat/lansari/1.0.0/app/z-ultimul.php", 0644);
            $b = Loc::pregateste("$d/public_html", "$d/cazo-privat");
        } finally {
            chmod("$d/cazo-privat", 0755);
            @chmod("$d/cazo-privat/lansari/1.0.0/app/z-ultimul.php", 0644);
        }
        egal(['B', 'copiat'], [$b['varianta'], $b['mutat']]);
        foreach (['curent.php', 'cron.php', '.htaccess', 'lansari/1.0.0/app/porneste.php', 'lansari/1.0.0/app/z-ultimul.php'] as $f) {
            adevarat(is_file($b['privat'] . '/' . $f), $f);
        }
        adevarat(!is_dir("$d/public_html/" . Loc::PREFIX_COPIERE . 'veche'), 'copia uitată a rămas');
        adevarat(is_dir("$d/public_html/" . Loc::PREFIX_COPIERE . 'recenta'), 'o copie în lucru s-a șters');
        egal([$b['privat']], glob("$d/public_html/cazo-privat-*", GLOB_ONLYDIR));
        // Un subdosar necitibil oprește și el copia (scandir întoarce false).
        $e = $m->dosarTemporar();
        mkdir("$e/sursa/a", 0755, true);
        chmod("$e/sursa/a", 0000);
        try {
            arunca(fn () => Instanta::copiaza("$e/sursa", "$e/tinta"), \RuntimeException::class);
        } finally {
            chmod("$e/sursa/a", 0755);
        }
    },

    'linia de cron: calea între ghilimele (spații, apostrof) și „%” scris pentru crontab' => function (Mediu $m): void {
        egal("*/5 * * * * php -q '/home/u/cazo-privat/cron.php'", Instalator::linieCron('/home/u/cazo-privat/'));
        foreach (['/tmp/site nou/cazo-privat', "/tmp/o'brien/cazo-privat", '/tmp/100%/cazo-privat'] as $cale) {
            $l = Instalator::linieCron($cale);
            adevarat(str_starts_with($l, '*/5 * * * * php -q '), $l);
            // Ce face cronul: „\%” devine „%”, apoi shell-ul primește restul liniei.
            $rest = substr($l, strlen('*/5 * * * * php -q '));
            adevarat(!str_contains(str_replace('\\%', '', $rest), '%'), 'un „%” neprotejat: ' . $l);
            $comanda = str_replace('\\%', '%', $rest);
            egal('[' . $cale . '/cron.php]', (string) shell_exec("printf '[%s]' " . $comanda), $l);
        }
    },

    'starea instalării: JSON după „<?php exit; ?>”, 0600, fără include' => function (Mediu $m): void {
        $d = $m->dosarTemporar();
        $s = Stare::pentru($d);
        egal(false, $s->exista());
        $s->pune('pas', 3)->pune('cod_hash', 'abc')->salveaza();
        $t = (string) file_get_contents("$d/" . Loc::STARE);
        adevarat(str_starts_with($t, "<?php exit; ?>\n"));
        egal(0600, fileperms("$d/" . Loc::STARE) & 0777);
        egal(3, Stare::pentru($d)->get('pas'));
        // Executat de PHP (o cerere directă), fișierul nu întoarce nimic.
        egal('', (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$d/" . Loc::STARE)));
    },

    'parolele compromise: lista locală și tiparele evidente' => function (Mediu $m): void {
        foreach (['password1234', 'Qwerty123456', '123456789012', 'aaaaaaaaaaaa', 'abababababab', 'abcdefghijklmn', 'parolamea123', 'pensiunea demo1'] as $p) {
            adevarat(ParoleCompromise::compromisa($p, ['pensiunea demo']), $p);
        }
        foreach (['cal verde pe dealul cu mere', 'Zmeura-Albastra-42!', 'trei pisici pe acoperiș'] as $p) {
            fals(ParoleCompromise::compromisa($p, ['gazda', 'cazo.test']), $p);
        }
        adevarat(ParoleCompromise::compromisa('ionescu20262026', ['ionescu']));
    },

    'bin/cazo configureaza pune marcajul instalării: instalatorul rămâne închis pe o instanță configurată' => function (Mediu $m): void {
        $i = Instanta::configureaza($m->dosarTemporar(), ['lansare_dev' => Mediu::radacina(), 'baza' => $m->descriereBaza(), 'prefix' => $m->prefix()]);
        adevarat(is_file($i['privat'] . '/' . Loc::MARCAJ));
    },

    'cataloagele instalatorului: domeniul „instalare” peste cel al panoului' => function (Mediu $m): void {
        $ro = \Cazo\I18n\Traducator::incarca(Mediu::radacina() . '/limbi', 'ro', 'instalare');
        egal('Instalarea site-ului', $ro['instalare.titlu']);
        arunca(fn () => \Cazo\I18n\Traducator::incarca(Mediu::radacina() . '/limbi', 'ro', '../ro'), \InvalidArgumentException::class);
    },
];
