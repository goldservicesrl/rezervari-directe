<?php

declare(strict_types=1);

// Antetele și CSP-ul pe fiecare rută, CSRF pe fiecare POST, paginile de eroare fără detalii (`securitate`).

use Cazo\Nucleu\Antete;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Csp;
use Cazo\Nucleu\Intretinere;
use Cazo\Nucleu\Raspuns;
use Cazo\Nucleu\Ruta;
use Cazo\Nucleu\Rute;
use Cazo\Securitate\Csrf;

/** Antetele obligatorii pe orice răspuns al aplicației. */
$verificaAntete = static function (Raspuns $r, string $profil, string $eticheta): void {
    $a = $r->antete();
    // Paginile cu harta adaugă în img-src doar serverul de plăci; restul CSP-ului rămâne al profilului.
    $harta = $profil === 'public' && str_contains($r->corp, 'data-pg="harta"');
    egal(Csp::pentru($profil, 'https://cazo.test', $harta ? ['placi_harta' => 'https://tile.openstreetmap.org'] : []), $a['Content-Security-Policy'] ?? null, "$eticheta: CSP");
    egal('nosniff', $a['X-Content-Type-Options'] ?? null, "$eticheta: nosniff");
    egal('strict-origin-when-cross-origin', $a['Referrer-Policy'] ?? null, "$eticheta: Referrer-Policy");
    egal(Antete::PERMISSIONS, $a['Permissions-Policy'] ?? null, "$eticheta: Permissions-Policy");
    egal('DENY', $a['X-Frame-Options'] ?? null, "$eticheta: X-Frame-Options");
    adevarat(isset($a['Cache-Control']), "$eticheta: Cache-Control");
    // Punctele tehnice publice (sitemap, robots, calendarul din oglindă) au o durată scurtă în cache; restul: no-store.
    $publicTehnic = $profil === 'tehnic' && in_array(explode(' ', $eticheta)[0], ['sitemap', 'robots', 'api-calendar'], true);
    if ($publicTehnic && $r->status === 200) {
        adevarat(str_starts_with($a['Cache-Control'], 'public, max-age='), "$eticheta: Cache-Control public");
    } elseif ($r->status !== 200 || $profil !== 'public') {
        egal('no-store', $a['Cache-Control'], "$eticheta: răspunsul privat sau de eroare intră în cache");
    }
};

/** O cerere potrivită fiecărei rute din tabel, cu parametri de probă. */
$cerereProba = static function (Ruta $r): Cerere {
    $cale = preg_replace_callback('~\{([a-z_]+)(?::((?:[^{}]|\{\d+(?:,\d*)?\})+))?\}~', fn (array $m) => match (true) {
        // O alternanță de cuvinte (acțiunile panoului): prima variantă, ca ruta să se potrivească.
        isset($m[2]) && preg_match('~^[a-z0-9\-]+(\|[a-z0-9\-]+)+$~', $m[2]) === 1 => explode('|', $m[2])[0],
        default => match ($m[1]) {
            'token', 'jeton' => str_repeat('A', 43), 'ref' => 'PG-ABCDEF', 'decizie' => 'proba-accepta', 'actiune' => 'decizie', 'id' => '1', 'unitate' => '2', 'z' => '6', 'x', 'y' => '1', 'sha' => str_repeat('a', 64), default => 'proba'
        },
    }, $r->model);
    // Rutele cu cale doar în engleză (/rooms) există sub prefixul /en.
    $cale = $r->limba !== null && $r->limba !== 'ro' ? '/' . $r->limba . $cale : $cale;
    return Cerere::creeaza($r->metoda, $cale, [], $r->metoda === 'POST' ? '{}' : '');
};

return [
    'fiecare rută din tabel răspunde cu antetele și CSP-ul profilului ei' => function (Mediu $m) use ($verificaAntete, $cerereProba): void {
        $app = $m->aplicatie();
        $app->secrete()->pune('cron_token', str_repeat('B', 43));
        foreach (Rute::toate() as $ruta) {
            $c = $cerereProba($ruta);
            $r = $app->trateaza($c);
            adevarat($r->status < 500, "ruta {$ruta->nume} a dat {$r->status}");
            $verificaAntete($r, $ruta->profil, $ruta->nume);
            if ($ruta->limbi && $ruta->limba === null) {
                $verificaAntete($app->trateaza(Cerere::creeaza($ruta->metoda, '/en' . $c->cale)), $ruta->profil, $ruta->nume . ' (en)');
            }
        }
    },

    'paginile de eroare au antetele complete și niciun detaliu tehnic' => function (Mediu $m) use ($verificaAntete): void {
        $rute = array_merge(Rute::toate(), [new Ruta('GET', '/cade', function (): never {
            throw new \RuntimeException('secret intern: /home/cont/cazo-privat sk_live_ABCDEF123');
        }, 'public', nume: 'cade')]);
        $app = $m->aplicatie([], $rute);
        $cazuri = [
            ['GET', '/nu-exista', 404], ['GET', '/en/nu-exista', 404], ['GET', '/_csp', 405],
            ['GET', '/a/../b', 400], ['GET', '/cade', 500], ['DELETE', '/', 405],
        ];
        foreach ($cazuri as [$met, $cale, $status]) {
            $r = $app->trateaza(Cerere::creeaza($met, $cale));
            egal($status, $r->status, "$met $cale");
            $verificaAntete($r, 'public', "$met $cale");
            egal('noindex', $r->valoareAntet('X-Robots-Tag'));
            contine($r->corp, '<meta name="robots" content="noindex">');
        }
        $r = $app->trateaza(Cerere::creeaza('GET', '/cade'));
        lipseste($r->corp, 'secret intern');
        lipseste($r->corp, 'RuntimeException');
        adevarat((bool) preg_match('~E-[0-9A-F]{8}~', $r->corp, $id), 'pagina 500 fără id');
        $jurnal = (string) file_get_contents($app->privat() . '/jurnale/cazo-2026-11-14.log');
        contine($jurnal, $id[0], 'id-ul erorii lipsește din jurnal');
        contine($jurnal, 'sk_live_***');
        lipseste($jurnal, 'ABCDEF123');
        egal('GET, HEAD', $app->trateaza(Cerere::creeaza('POST', '/'))->valoareAntet('Allow'));
    },

    'fiecare POST cere CSRF sau are scris motivul excepției' => function (Mediu $m): void {
        foreach (Rute::toate() as $r) {
            if ($r->metoda !== 'POST') {
                continue;
            }
            adevarat($r->cereCsrf() || mb_strlen((string) $r->faraCsrf) >= 30, "POST {$r->model}: fără CSRF și fără motiv");
        }
    },

    'CSRF: POST-ul fără jeton, cu jeton străin sau cu alt cookie primește 403' => function (Mediu $m): void {
        $rute = [new Ruta('POST', '/formular', fn () => Raspuns::text('primit'), 'rezervare', nume: 'formular')];
        $app = $m->aplicatie([], $rute);
        $legatura = str_repeat('L', 43);
        $jeton = $app->csrf()->emite($legatura);
        $cookie = [Csrf::COOKIE => $legatura];
        egal(403, $app->trateaza(Cerere::creeaza('POST', '/formular'))->status, 'fără nimic');
        egal(403, $app->trateaza(Cerere::creeaza('POST', '/formular', [], '', $cookie))->status, 'fără jeton');
        egal(403, $app->trateaza(Cerere::creeaza('POST', '/formular', [], '', [], '127.0.0.1', [Csrf::CAMP => $jeton]))->status, 'fără cookie');
        egal(403, $app->trateaza(Cerere::creeaza('POST', '/formular', [], '', [Csrf::COOKIE => str_repeat('X', 43)], '127.0.0.1', [Csrf::CAMP => $jeton]))->status, 'alt cookie');
        $r = $app->trateaza(Cerere::creeaza('POST', '/formular', [], '', $cookie, '127.0.0.1', [Csrf::CAMP => $jeton]));
        egal(200, $r->status);
        egal('primit', $r->corp);
        egal(200, $app->trateaza(Cerere::creeaza('POST', '/formular', [Csrf::ANTET => $jeton], '', $cookie))->status, 'jetonul din antet');
        $r403 = $app->trateaza(Cerere::creeaza('POST', '/formular'));
        egal('no-store', $r403->valoareAntet('Cache-Control'));
    },

    'jetonul CSRF emis pune cookie-ul __Host- cu atributele corecte' => function (Mediu $m): void {
        $app = $m->aplicatie();
        $r = Raspuns::html('');
        $j = $app->jetonCsrf(Cerere::creeaza('GET', '/'), $r);
        egal(1, count($r->cookies()));
        adevarat((bool) preg_match('~^__Host-pg_csrf=([A-Za-z0-9_-]{43}); Path=/; Secure; HttpOnly; SameSite=Lax$~', $r->cookies()[0], $x));
        adevarat($app->csrf()->verifica($x[1], $j));
        $r2 = Raspuns::html('');
        $app->jetonCsrf(Cerere::creeaza('GET', '/', [], '', [Csrf::COOKIE => $x[1]]), $r2);
        egal([], $r2->cookies(), 'cookie-ul existent s-a rescris');
    },

    'modul de întreținere: 503 cu Retry-After, punctele tehnice merg' => function (Mediu $m): void {
        $app = $m->aplicatie();
        Intretinere::porneste($app->privat(), Mediu::ACUM + 600);
        $r = $app->trateaza(Cerere::creeaza('GET', '/'));
        egal(503, $r->status);
        egal('120', $r->valoareAntet('Retry-After'));
        contine($r->corp, 'Revenim în câteva minute');
        egal(204, $app->trateaza(Cerere::creeaza('POST', '/_csp', [], '{}'))->status);
        $m->ceas()->avanseaza(601);
        egal(200, $app->trateaza(Cerere::creeaza('GET', '/'))->status, 'întreținerea expirată ține site-ul oprit');
        Intretinere::opreste($app->privat());
    },

    'noindex până la „Publică site-ul”, indexare după' => function (Mediu $m): void {
        $app = $m->aplicatie();
        $r = $app->trateaza(Cerere::creeaza('GET', '/'));
        egal('noindex', $r->valoareAntet('X-Robots-Tag'));
        contine($r->corp, '<meta name="robots" content="noindex">');
        $app->setari()->pune('site.publicat', true);
        $app2 = $m->aplicatie();
        $r = $app2->trateaza(Cerere::creeaza('GET', '/'));
        egal(null, $r->valoareAntet('X-Robots-Tag'));
        lipseste($r->corp, 'noindex');
    },

    'HSTS doar după verificarea HTTPS' => function (Mediu $m): void {
        $app = $m->aplicatie();
        egal(null, $app->trateaza(Cerere::creeaza('GET', '/'))->valoareAntet('Strict-Transport-Security'));
        $app->setari()->pune('https.verificat', true);
        egal('max-age=31536000', $m->aplicatie()->trateaza(Cerere::creeaza('GET', '/'))->valoareAntet('Strict-Transport-Security'));
    },

    'cronul extern: 404 la orice alt token, rulează la tokenul corect' => function (Mediu $m) use ($verificaAntete): void {
        $app = $m->aplicatie();
        $token = str_repeat('k', 43);
        egal(404, $app->trateaza(Cerere::creeaza('GET', '/_cron/' . $token))->status, 'fără token configurat');
        $app->secrete()->pune('cron_token', $token);
        egal(404, $app->trateaza(Cerere::creeaza('GET', '/_cron/' . str_repeat('j', 43)))->status);
        egal(404, $app->trateaza(Cerere::creeaza('GET', '/_cron/' . substr($token, 1)))->status);
        $r = $app->trateaza(Cerere::creeaza('GET', '/_cron/' . $token));
        egal(200, $r->status);
        potriveste_json($r->corp, ['ok' => true, 'rulat' => true]);
        $verificaAntete($r, 'tehnic', 'cron');
        egal('cpanel', $app->setari()->get('cron.ultima')['sursa']);
        contine((string) $app->baza()->valoare('SELECT rezultat FROM {sarcini} WHERE nume = ?', ['curatenie']), 'limite: ');
    },

    'rapoartele CSP: agregate, fără parametri și fără IP, limitate' => function (Mediu $m): void {
        $app = $m->aplicatie();
        $vechi = json_encode(['csp-report' => [
            'document-uri' => 'https://cazo.test/r/TOKENSECRET123?email=ana@example.com',
            'violated-directive' => 'script-src-elem',
            'effective-directive' => 'script-src-elem',
            'blocked-uri' => 'https://rau.example.com/x.js?id=1',
        ]]);
        $nou = json_encode([['type' => 'csp-violation', 'body' => [
            'documentURL' => 'https://cazo.test/r/ALTTOKEN',
            'effectiveDirective' => 'script-src-elem',
            'blockedURL' => 'https://rau.example.com/y.js',
        ]]]);
        egal(204, $app->trateaza(Cerere::creeaza('POST', '/_csp', ['Content-Type' => 'application/csp-report'], $vechi, [], '192.0.2.5'))->status);
        egal(204, $app->trateaza(Cerere::creeaza('POST', '/_csp', ['Content-Type' => 'application/reports+json'], $nou, [], '198.51.100.6'))->status);
        $randuri = $app->baza()->toate('SELECT * FROM {csp_rapoarte}');
        egal(1, count($randuri));
        egal('script-src-elem', $randuri[0]['directiva']);
        egal('https://rau.example.com', $randuri[0]['sursa']);
        egal('/r/*', $randuri[0]['pagina']);
        egal(2, (int) $randuri[0]['numar']);
        $tot = json_encode($randuri);
        foreach (['TOKENSECRET', 'ana@', '203.0.113', 'id=1'] as $x) {
            lipseste($tot, $x);
        }
        // 10 pe minut pe IP/24: al 11-lea raport de pe aceeași rețea se ignoră.
        for ($i = 0; $i < 10; $i++) {
            $app->trateaza(Cerere::creeaza('POST', '/_csp', [], $vechi, [], '203.0.113.9'));
        }
        egal(12, (int) $app->baza()->valoare('SELECT numar FROM {csp_rapoarte}'));
        egal(204, $app->trateaza(Cerere::creeaza('POST', '/_csp', [], $vechi, [], '203.0.113.10'))->status);
        egal(12, (int) $app->baza()->valoare('SELECT numar FROM {csp_rapoarte}'), 'limita pe IP/24 ignorată');
        egal(413, $app->trateaza(Cerere::creeaza('POST', '/_csp', [], str_repeat('x', 16385), [], '198.51.100.1'))->status);
        egal(204, $app->trateaza(Cerere::creeaza('POST', '/_csp', [], 'nu e json', [], '198.51.100.1'))->status);
    },
];
