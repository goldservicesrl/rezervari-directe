<?php

declare(strict_types=1);

// Router-ul (cu și fără rescriere), cererea, adresele generate, constructorul CSP, jurnalul.

use Cazo\Nucleu\Antete;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Config;
use Cazo\Nucleu\Csp;
use Cazo\Nucleu\Jurnal;
use Cazo\Nucleu\Raspuns;
use Cazo\Nucleu\Router;
use Cazo\Nucleu\Ruta;
use Cazo\Nucleu\Rute;
use Cazo\Nucleu\Url;

$router = static function (): Router {
    $f = fn () => Raspuns::text('');
    return new Router([
        new Ruta('GET', '/', $f, 'public', limbi: true, nume: 'acasa'),
        new Ruta('GET', '/camere/{slug}', $f, 'public', limbi: true, nume: 'camera'),
        new Ruta('GET', '/_cron/{token:[A-Za-z0-9_-]{43}}', $f, 'tehnic', nume: 'cron'),
        new Ruta('POST', '/_csp', $f, 'tehnic', faraCsrf: 'test', nume: 'csp'),
    ]);
};

return [
    'calea se citește la fel cu rescriere, cu ?r= și cu PATH_INFO' => function (Mediu $m): void {
        foreach (['/camere/dubla', '/?r=/camere/dubla', '/index.php?r=/camere/dubla', '/index.php/camere/dubla', '/?r=%2Fcamere%2Fdubla'] as $uri) {
            egal('/camere/dubla', Cerere::creeaza('GET', $uri)->cale, $uri);
        }
        egal('/', Cerere::creeaza('GET', '/index.php')->cale);
        egal('/', Cerere::creeaza('GET', '/')->cale);
        egal('/camere/dublă', Cerere::creeaza('GET', '/camere/dubl%C4%83')->cale);
        egal([], Cerere::creeaza('GET', '/?r=/x')->query, 'r rămâne în parametri');
        egal(['a' => '1'], Cerere::creeaza('GET', '/?r=/x&a=1')->query);
        // ?r= contează doar pe rădăcină: altfel e un parametru obișnuit al paginii.
        egal('/camere', Cerere::creeaza('GET', '/camere?r=/altceva')->cale);
    },

    'căile invalide devin cereri greșite' => function (Mediu $m): void {
        foreach (['/../config.php', '/a/../b', '/a/./b', '/%00', "/a\\b", '/?r=camere', '/?r=/a/../../etc', '/%FF'] as $uri) {
            egal('', Cerere::creeaza('GET', $uri)->cale, $uri);
        }
    },

    'router-ul: limba din prefix, parametrii, 404 și 405' => function (Mediu $m) use ($router): void {
        $r = $router();
        $x = $r->rezolva(Cerere::creeaza('GET', '/'));
        egal('acasa', $x['ruta']->nume);
        egal('ro', $x['limba']);
        foreach (['/en', '/en/', '/?r=/en/', '/index.php/en'] as $uri) {
            $x = $r->rezolva(Cerere::creeaza('GET', $uri));
            egal('acasa', $x['ruta']->nume ?? null, $uri);
            egal('en', $x['limba'], $uri);
        }
        $x = $r->rezolva(Cerere::creeaza('GET', '/en/camere/double-room'));
        egal(['slug' => 'double-room'], $x['parametri']);
        egal('en', $x['limba']);
        egal(404, $r->rezolva(Cerere::creeaza('GET', '/en/_cron/' . str_repeat('a', 43)))['status'], 'rutele tehnice nu au prefix de limbă');
        egal('cron', $r->rezolva(Cerere::creeaza('GET', '/_cron/' . str_repeat('a', 43)))['ruta']->nume);
        egal(404, $r->rezolva(Cerere::creeaza('GET', '/_cron/' . str_repeat('a', 42)))['status']);
        egal(404, $r->rezolva(Cerere::creeaza('GET', '/_cron/' . str_repeat('a', 44)))['status']);
        egal(404, $r->rezolva(Cerere::creeaza('GET', '/english'))['status']);
        $x = $r->rezolva(Cerere::creeaza('GET', '/_csp'));
        egal(405, $x['status']);
        egal(['POST'], $x['permise']);
        egal('acasa', $r->rezolva(Cerere::creeaza('HEAD', '/'))['ruta']->nume, 'HEAD urmează GET');
        egal(400, $r->rezolva(Cerere::creeaza('GET', '/a/../b'))['status']);
    },

    'rutele: metode și profiluri valide; POST fără CSRF doar cu motiv' => function (Mediu $m): void {
        arunca(fn () => new Ruta('PUT', '/', fn () => null), \InvalidArgumentException::class);
        arunca(fn () => new Ruta('GET', '/', fn () => null, 'inventat'), \InvalidArgumentException::class);
        adevarat((new Ruta('POST', '/x', fn () => null))->cereCsrf());
        fals((new Ruta('POST', '/x', fn () => null, faraCsrf: 'motiv'))->cereCsrf());
        fals((new Ruta('GET', '/x', fn () => null))->cereCsrf());
        adevarat(count(Rute::toate()) >= 3);
    },

    'adresele generate, cu și fără rescriere' => function (Mediu $m): void {
        $baza = ['site_url' => 'https://cazo.test', 'limbi' => ['ro', 'en'], 'limba_implicita' => 'ro'];
        $cu = new Url(new Config($baza + ['rescriere' => true], '/tmp'));
        $fara = new Url(new Config($baza + ['rescriere' => false], '/tmp'));
        egal('/', $cu->cale('/'));
        egal('/en/', $cu->cale('/', 'en'));
        egal('/camere/dubl%C4%83', $cu->cale('/camere/dublă'));
        egal('/en/rooms?a=1', $cu->cale('/rooms', 'en', ['a' => 1]));
        egal('https://cazo.test/en/', $cu->absolut('/', 'en'));
        egal('/', $fara->cale('/'));
        egal('/?r=%2Fen%2F', $fara->cale('/', 'en'));
        egal('/?r=%2Fcamere&a=1', $fara->cale('/camere', null, ['a' => 1]));
        // Adresa generată fără rescriere se citește înapoi în aceeași cale.
        egal('/en/', Cerere::creeaza('GET', $fara->cale('/', 'en'))->cale);
        arunca(fn () => $cu->cale('camere'), \InvalidArgumentException::class);
    },

    'config: site_url doar ca origine' => function (Mediu $m): void {
        arunca(fn () => new Config(['site_url' => 'https://cazo.test/cale'], '/tmp'), \InvalidArgumentException::class);
        arunca(fn () => new Config(['site_url' => 'javascript:alert(1)'], '/tmp'), \InvalidArgumentException::class);
        adevarat((new Config(['site_url' => 'https://cazo.test'], '/tmp'))->https());
        fals((new Config(['site_url' => 'http://localhost:8080'], '/tmp'))->https());
    },

    'CSP-ul final al paginilor publice' => function (Mediu $m): void {
        egal(
            "default-src 'none'; script-src https://cazo.test/_c/; style-src 'self'; img-src 'self' data:; font-src 'self'; "
            . "connect-src 'self'; manifest-src 'self'; form-action 'self' https://checkout.stripe.com; frame-ancestors 'none'; "
            . "base-uri 'none'; object-src 'none'; upgrade-insecure-requests; report-uri /_csp; report-to csp",
            Csp::pentru('public', 'https://cazo.test')
        );
        contine(Csp::pentru('public', 'https://cazo.test', ['tema_cu_js' => true]), 'script-src https://cazo.test/_c/ https://cazo.test/_t/;');
        contine(Csp::pentru('public', 'https://cazo.test', ['placi_harta' => 'https://tile.openstreetmap.org']), "img-src 'self' data: https://tile.openstreetmap.org;");
        arunca(fn () => Csp::pentru('public', 'https://cazo.test', ['placi_harta' => "https://x.ro; script-src *"]), \InvalidArgumentException::class);
        lipseste(Csp::pentru('public', 'http://localhost:8080'), 'upgrade-insecure-requests');
    },

    'CSP-ul paginilor de rezervare, al panoului, al punctelor tehnice și al SVG-urilor' => function (Mediu $m): void {
        $rez = Csp::pentru('rezervare', 'https://cazo.test', ['tema_cu_js' => true, 'placi_harta' => 'https://tile.openstreetmap.org']);
        contine($rez, 'script-src https://cazo.test/_c/;');
        lipseste($rez, '/_t/', 'JS-ul temei pe paginile de rezervare');
        // Plăcile hărții intră doar când pagina are harta („Cum ajungeți” pe rezervarea confirmată).
        contine($rez, 'img-src \'self\' data: https://tile.openstreetmap.org;');
        lipseste(Csp::pentru('rezervare', 'https://cazo.test'), 'openstreetmap');
        contine($rez, "require-trusted-types-for 'script'");
        contine($rez, "form-action 'self' https://checkout.stripe.com");
        $admin = Csp::pentru('admin', 'https://cazo.test');
        contine($admin, 'script-src https://cazo.test/_c/admin/;');
        contine($admin, "form-action 'self';");
        contine($admin, "require-trusted-types-for 'script'");
        egal("default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'", Csp::pentru('tehnic', 'https://cazo.test'));
        egal("sandbox; default-src 'none'", Csp::pentru('svg', 'https://cazo.test'));
        foreach (['public', 'rezervare', 'admin'] as $p) {
            $csp = Csp::pentru($p, 'https://cazo.test');
            foreach (["'unsafe-inline'", "'unsafe-eval'", 'nonce-', '*'] as $interzis) {
                lipseste($csp, $interzis, "$p conține $interzis");
            }
        }
    },

    'antetele pe profil' => function (Mediu $m): void {
        $r = Antete::aplica(Raspuns::html('x'), 'public', 'https://cazo.test', ['hsts' => true]);
        egal(Antete::CACHE_PUBLIC, $r->valoareAntet('Cache-Control'));
        egal('max-age=31536000', $r->valoareAntet('Strict-Transport-Security'));
        egal('DENY', $r->valoareAntet('X-Frame-Options'));
        egal('csp="https://cazo.test/_csp"', $r->valoareAntet('Reporting-Endpoints'));
        $r = Antete::aplica(Raspuns::html('x', 404), 'public', 'https://cazo.test');
        egal('no-store', $r->valoareAntet('Cache-Control'), 'eroarea a intrat în cache');
        egal(null, $r->valoareAntet('Strict-Transport-Security'), 'HSTS fără verificarea HTTPS');
        $r = Antete::aplica(Raspuns::html('x'), 'rezervare', 'https://cazo.test');
        egal('no-store', $r->valoareAntet('Cache-Control'));
        egal('no-cache', $r->valoareAntet('X-LiteSpeed-Cache-Control'));
        $r = Antete::aplica(Raspuns::html('x'), 'admin', 'https://cazo.test');
        egal('same-origin', $r->valoareAntet('Cross-Origin-Opener-Policy'));
        $r = Antete::aplica(Raspuns::html('x'), 'public', 'http://localhost', ['hsts' => true, 'noindex' => true]);
        egal(null, $r->valoareAntet('Strict-Transport-Security'), 'HSTS pe http');
        egal('noindex', $r->valoareAntet('X-Robots-Tag'));
        arunca(fn () => Raspuns::text('')->antet('X', "a\r\nSet-Cookie: x"), \InvalidArgumentException::class);
    },

    'jurnalul maschează cheile, e-mailurile, IP-urile și numerele lungi' => function (Mediu $m): void {
        $t = Jurnal::mascheaza('chei ec_live_abcdEFGH1234 rk_test_51Habc sk_live_XYZ987 whsec_abc123def pk_test_qwerty1 Bearer abc.def; ana.pop@example.com de pe 203.0.113.77, card 4242 4242 4242 4242, tel +40 722 123 456, comanda PG-7K3M9Q');
        foreach (['abcdEFGH1234', '51Habc', 'XYZ987', 'abc123def', 'qwerty1', 'abc.def', 'ana.pop', '113.77', '4242 4242', '722 123'] as $secret) {
            lipseste($t, $secret);
        }
        foreach (['ec_live_***', 'rk_test_***', 'whsec_***', '***@***', '203.0.113.x', 'PG-7K3M9Q'] as $vizibil) {
            contine($t, $vizibil);
        }
    },

    'jurnalul maschează secretul cheilor EasyCheck în formatul real și secretele cu „_” în ele' => function (Mediu $m): void {
        // Formatul cheilor EasyCheck: ec_live_<prefix 8>_<secret 32>; prefixul e public, secretul dispare.
        $t = Jurnal::mascheaza('cheia ec_live_AbCd1234_Zx9QwErTyUiOp-_kLmN0pQrStUvWxYz012345 și ec_test_QwEr5678_0123456789abcdefABCDEF0123456789.');
        egal('cheia ec_live_AbCd1234_*** și ec_test_QwEr5678_***.', $t);
        foreach (['whsec_abcDEF123_xyz' => 'whsec_***', 'stripe_cheie=sk_live_51H_abc_def' => 'stripe_cheie=sk_live_***', '{"ec":"rk_test_a-b_c"}' => '{"ec":"rk_test_***"}'] as $intrare => $iesire) {
            egal($iesire, Jurnal::mascheaza($intrare), $intrare);
        }
    },

    'jurnalul maschează IPv6 la /48 și lasă neatinse orele și numele de metode' => function (Mediu $m): void {
        egal('de pe 2a02:2f0e:1234::/48', Jurnal::mascheaza('de pe 2a02:2f0e:1234:5678::1'));
        egal('[2001:db8::/48]:443', Jurnal::mascheaza('[2001:db8:0:1:2:3:4:5]:443'));
        egal('::ffff:203.0.113.x', Jurnal::mascheaza('::ffff:203.0.113.77'));
        $neatins = 'Coada::pune la 2026-11-14T10:00:00Z, ora 10:00:00';
        egal($neatins, Jurnal::mascheaza($neatins));
        // Și în contextul scris de Jurnal (JSON).
        $d = $m->dosarTemporar();
        (new Jurnal($d, $m->ceas()))->avertisment('Cerere', ['ip' => '2a02:2f0e:1234:5678:9abc:def0:1:2', 'cheie' => 'ec_live_AbCd1234_Zx9QwErTyUiOpkLmN0pQrStUvWxYz012345']);
        $continut = (string) file_get_contents("$d/cazo-2026-11-14.log");
        lipseste($continut, '5678');
        lipseste($continut, 'Zx9Qw');
        contine($continut, '2a02:2f0e:1234::/48');
    },

    'jurnalul scrie o linie pe eveniment și păstrează 14 zile' => function (Mediu $m): void {
        $d = $m->dosarTemporar();
        $j = new Jurnal($d, $m->ceas());
        $j->eroare("linie\ncu rând nou", ['cheie' => 'sk_live_ABCDEFG']);
        $continut = (string) file_get_contents("$d/cazo-2026-11-14.log");
        egal(1, substr_count($continut, "\n"));
        contine($continut, '2026-11-14T10:00:00Z EROARE linie\\ncu rând nou');
        contine($continut, 'sk_live_***');
        touch("$d/cazo-2026-10-30.log");
        touch("$d/cazo-2026-10-31.log");
        touch("$d/altceva.log");
        egal(1, $j->curata());
        adevarat(is_file("$d/cazo-2026-10-31.log"));
        adevarat(is_file("$d/altceva.log"));
    },

    'Cerere::dinGlobale ia IP-ul din X-Forwarded-For doar de la proxy-urile de încredere' => function (Mediu $m): void {
        $server = $_SERVER;
        try {
            $_SERVER['REQUEST_URI'] = '/';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REMOTE_ADDR'] = '173.245.50.1';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
            egal('173.245.50.1', Cerere::dinGlobale()->ip, 'fără listă');
            egal('203.0.113.9', Cerere::dinGlobale(['173.245.48.0/20'])->ip, 'prin proxy de încredere');
            $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
            egal('198.51.100.7', Cerere::dinGlobale(['173.245.48.0/20'])->ip, 'antet trimis direct de vizitator');
        } finally {
            $_SERVER = $server;
        }
    },
];
