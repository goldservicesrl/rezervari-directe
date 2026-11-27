<?php

declare(strict_types=1);

// Temele terțe (`securitate`): corpusul zip ostil e respins integral la despachetare
// (traversarea, căile absolute, „\”, „:”, NUL, caracterele de control, Unicode înșelător, fișierele ascunse,
// .htaccess, .user.ini, x.php.css, .phtml, symlinkul, intrarea criptată, bomba zip, 10 000 de fișiere, mărimile
// declarate mincinos), iar corpusul de teme ostile e respins de validator (orice JavaScript, HTML literal periculos,
// CSS care ar putea ascunde blocul legal sau comanda, șabloane interzise, variabile din afara modelului, SVG cu script).

use Cazo\Teme\Despachetare;
use Cazo\Teme\EroareTema;
use Cazo\Teme\Raport;
use Cazo\Teme\Tema;
use Cazo\Teme\Validator;

/**
 * O arhivă zip scrisă octet cu octet (metoda „stored”), ca numele și atributele să fie exact cele din corpus:
 * ZipArchive ar normaliza unele nume. Intrări: [nume, conținut, atribute externe (opțional), marime declarată (opțional)].
 */
function zip_brut(string $cale, array $intrari): void
{
    $local = '';
    $central = '';
    foreach ($intrari as $x) {
        [$nume, $date] = $x;
        $attr = $x[2] ?? (0100644 << 16);
        $marime = $x[3] ?? strlen($date);
        $crc = crc32($date);
        $off = strlen($local);
        $local .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0x21, $crc, strlen($date), $marime, strlen($nume), 0) . $nume . $date;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x031E, 20, 0, 0, 0, 0x21, $crc, strlen($date), $marime, strlen($nume), 0, 0, 0, 0, $attr, $off) . $nume;
    }
    file_put_contents($cale, $local . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($intrari), count($intrari), strlen($central), strlen($local), 0));
}

/** Fișierele temei de pornire, ca listă [cale relativă => conținut]. */
function pornire_fisiere(): array
{
    $d = Mediu::radacina() . '/teme/_pornire';
    $r = [];
    foreach (\Cazo\Teme\Active::lista($d) as $rel) {
        $r[$rel] = (string) file_get_contents($d . '/' . $rel);
    }
    return $r;
}

/** O temă terță dintr-o listă de fișiere, într-un dosar temporar. */
function tema_din(Mediu $m, array $fisiere): string
{
    $d = $m->dosarTemporar() . '/tema';
    foreach ($fisiere as $rel => $c) {
        if ($c === null) {
            continue;
        }
        if (!is_dir(dirname($d . '/' . $rel))) {
            mkdir(dirname($d . '/' . $rel), 0700, true);
        }
        file_put_contents($d . '/' . $rel, $c);
    }
    return $d;
}

return [
    'corpusul zip ostil e respins integral la despachetare, fără să scrie ceva în afara dosarului de lucru' => function (Mediu $m): void {
        $baza = [['theme.json', '{"slug":"x"}']];
        $cazuri = [
            'zip-slip' => [['../../evil.css', 'x']],
            'zip-slip în dosar' => [['assets/css/../../../evil.css', 'x']],
            'cale absolută' => [['/etc/evil.css', 'x']],
            'backslash' => [['assets\\..\\..\\evil.css', 'x']],
            'două puncte' => [['C:/evil.css', 'x']],
            'NUL în nume' => [["assets/css/a.css\0.php", 'x']],
            'caracter de control' => [["assets/css/a\x07.css", 'x']],
            'Unicode înșelător' => [["s\u{0435}ctions/a.twig", 'x']],
            'fișier ascuns' => [['assets/css/.ascuns.css', 'x']],
            '.htaccess' => [['.htaccess', 'Options +ExecCGI']],
            '.htaccess în dosar' => [['assets/img/.htaccess', 'AddType application/x-httpd-php .png']],
            '.user.ini' => [['.user.ini', 'auto_prepend_file=x']],
            'user.ini' => [['assets/css/user.ini', 'x']],
            'x.php.css' => [['assets/css/x.php.css', '<?php system($_GET[1]);']],
            '.phtml' => [['partials/x.phtml', '<?php ?>']],
            '.phar' => [['assets/img/x.phar', 'x']],
            '.php5' => [['assets/img/x.php5.png', 'x']],
            '.sh' => [['sections/x.sh', 'x']],
            '.py' => [['x.py', 'x']],
            'extensie străină în dosar' => [['assets/css/x.twig', 'x']],
            'dosar necunoscut' => [['cgi-bin/x.json', '{}']],
            'adâncime peste 6' => [['sections/a/b/c/d/e/f/x.twig', 'x']],
            'symlink' => [['assets/img/x.png', '/etc/passwd', 0120777 << 16]],
            'mărime declarată mincinoasă' => [['assets/css/a.css', str_repeat('a', 100), 0100644 << 16, 10]],
            'cale repetată' => [['locales/ro.json', '{}'], ['LOCALES/ro.json', '{}']],
        ];
        $lucru = $m->dosarTemporar() . '/lucru';
        $respinse = [];
        foreach ($cazuri as $caz => $intrari) {
            $zip = $m->dosarTemporar() . '/caz.zip';
            zip_brut($zip, array_merge($baza, $intrari));
            try {
                Despachetare::din($zip, $lucru);
            } catch (EroareTema $e) {
                $respinse[$caz] = $e->motiv;
            }
            egal([], glob($lucru . '/*') ?: [], "$caz: dosarul de lucru a rămas cu fișiere");
        }
        egal(array_keys($cazuri), array_keys($respinse), 'cazuri acceptate: ' . implode(', ', array_diff(array_keys($cazuri), array_keys($respinse))));
        egal('symlink', $respinse['symlink']);
        egal('extensie', $respinse['x.php.css']);
        egal('nume', $respinse['zip-slip']);
        egal('arhiva', $respinse['mărime declarată mincinoasă']);
        // Adâncimea se numără în dosare, fără fișier: 6 dosare trec (și sub dosarul temei din arhivă), 7 nu („adâncime peste 6”).
        foreach (['assets/img/a/b/c/d/x.png', 'lumina/assets/img/a/b/c/d/x.png'] as $i => $cale) {
            $zip = $m->dosarTemporar() . "/adancime$i.zip";
            zip_brut($zip, [[$i === 0 ? 'theme.json' : 'lumina/theme.json', '{"slug":"x"}'], [$cale, 'x']]);
            $d = Despachetare::din($zip, $lucru);
            adevarat(is_file($d->dir . '/assets/img/a/b/c/d/x.png'), $cale);
            Despachetare::sterge($d->dir);
        }
        // Nimic scris în afara dosarului de lucru.
        adevarat(!file_exists(dirname($m->dosarTemporar()) . '/evil.css'), 'zip-slip a scris în afara dosarului');
    },

    'limitele: bomba zip (raportul de compresie), fișierul peste 5 MB, peste 60 MB despachetat, 10 000 de fișiere, intrarea criptată, arhiva peste 15 MB' => function (Mediu $m): void {
        $lucru = $m->dosarTemporar() . '/lucru';
        $proba = static function (callable $f) use ($m, $lucru): string {
            $zip = $m->dosarTemporar() . '/' . bin2hex(random_bytes(4)) . '.zip';
            $z = new ZipArchive();
            $z->open($zip, ZipArchive::CREATE);
            $z->addFromString('theme.json', '{"slug":"x"}');
            $f($z);
            $z->close();
            try {
                Despachetare::din($zip, $lucru);
                return 'acceptat';
            } catch (EroareTema $e) {
                return $e->motiv;
            } finally {
                @unlink($zip);
            }
        };
        // 4 MB de zerouri se comprimă la câțiva KB: raportul trece mult de 50:1.
        egal('compresie', $proba(static fn (ZipArchive $z) => $z->addFromString('assets/css/a.css', str_repeat("\0", 4 * 1024 * 1024))));
        egal('marime', $proba(static fn (ZipArchive $z) => $z->addFromString('assets/css/a.css', str_repeat("\0", 6 * 1024 * 1024))));
        egal('fisiere', $proba(static function (ZipArchive $z): void {
            for ($i = 0; $i < 10000; $i++) {
                $z->addFromString('sections/s' . $i . '.json', '{}');
            }
        }));
        // Peste 60 MB despachetat: 13 fișiere declarate de 4,9 MB fiecare (sub limita de 5 MB pe fișier), deci 63,7 MB în
        // total; arhiva e refuzată din directorul central, înainte să scrie ceva în dosarul de lucru.
        $z60 = $m->dosarTemporar() . '/t60.zip';
        $intrari = [['theme.json', '{"slug":"x"}']];
        for ($i = 0; $i < 13; $i++) {
            $intrari[] = ["assets/css/mare$i.css", 'a{}', 0100644 << 16, (int) (4.9 * 1024 * 1024)];
        }
        zip_brut($z60, $intrari);
        $mesaj = '';
        try {
            Despachetare::din($z60, $lucru);
        } catch (EroareTema $e) {
            egal('marime', $e->motiv);
            $mesaj = $e->getMessage();
        }
        contine($mesaj, '60 MB');
        egal([], glob($lucru . '/*') ?: [], 'dosarul de lucru a rămas cu fișiere');
        egal('criptat', $proba(static function (ZipArchive $z): void {
            $z->addFromString('assets/css/a.css', 'body{}');
            $z->setEncryptionName('assets/css/a.css', ZipArchive::EM_AES_256, 'parola');
        }));
        $mare = $m->dosarTemporar() . '/mare.zip';
        file_put_contents($mare, random_bytes(Despachetare::ARHIVA_MAX + 1));
        arunca(fn () => Despachetare::din($mare, $lucru), EroareTema::class, '15 MB');
        egal([], glob($lucru . '/*') ?: []);
        // O arhivă obișnuită, cu dosarul temei la rădăcină (cum o face un „Comprimă dosarul”), se despachetează.
        $bun = $m->dosarTemporar() . '/bun.zip';
        $z = new ZipArchive();
        $z->open($bun, ZipArchive::CREATE);
        foreach (pornire_fisiere() as $rel => $c) {
            $z->addFromString('tema-mea/' . $rel, $c);
        }
        $z->close();
        $d = Despachetare::din($bun, $lucru);
        adevarat(is_file($d->dir . '/theme.json') && is_file($d->dir . '/layout/baza.twig'), 'dosarul de sus nu s-a scos');
        egal(array_keys(pornire_fisiere()), $d->fisiere);
        Despachetare::sterge($d->dir);
    },

    'corpusul de teme ostile e respins de validator, fiecare cu pasul lui' => function (Mediu $m): void {
        $p = pornire_fisiere();
        $layout = $p['layout/baza.twig'];
        $cazuri = [
            'fișier JavaScript' => ['js', ['assets/js/x.js' => 'alert(1)'] + $p],
            'fișier .mjs' => ['js', ['assets/js/x.mjs' => 'export default 1'] + $p],
            'cheia js în manifest' => ['js', ['theme.json' => str_replace('"css":', '"js": ["assets/js/x.js"], "css":', $p['theme.json'])] + $p],
            '<script> inline' => ['html', ['layout/baza.twig' => str_replace('</body>', '<script>alert(1)</script></body>', $layout)] + $p],
            '<script src> extern' => ['html', ['partials/antet.twig' => '<script src="https://rau.example/x.js"></script>' . $p['partials/antet.twig']] + $p],
            'atribut on*' => ['html', ['partials/antet.twig' => str_replace('<header class="po-antet">', '<header class="po-antet" onmouseover="x()">', $p['partials/antet.twig'])] + $p],
            'atribut style' => ['html', ['partials/antet.twig' => str_replace('<header class="po-antet">', '<header class="po-antet" style="display:none">', $p['partials/antet.twig'])] + $p],
            'iframe' => ['html', ['sections/po_bun_venit.twig' => '<iframe src="/x"></iframe>' . $p['sections/po_bun_venit.twig']] + $p],
            'form' => ['html', ['sections/po_bun_venit.twig' => '<form action="/x"></form>' . $p['sections/po_bun_venit.twig']] + $p],
            'base' => ['html', ['layout/baza.twig' => str_replace('<head>', '<head><base href="https://rau.example/">', $layout)] + $p],
            'meta http-equiv' => ['html', ['layout/baza.twig' => str_replace('<head>', '<head><meta http-equiv="refresh" content="0;url=https://rau.example">', $layout)] + $p],
            'javascript:' => ['html', ['partials/antet.twig' => '<a href="javascript:alert(1)">x</a>' . $p['partials/antet.twig']] + $p],
            'imagine de pe alt domeniu' => ['html', ['sections/po_bun_venit.twig' => '<img src="//rau.example/p.gif" alt="" width="1" height="1">' . $p['sections/po_bun_venit.twig']] + $p],
            'filtrul raw' => ['sabloane', ['sections/po_bun_venit.twig' => '{{ proprietate.descriere|raw }}' . $p['sections/po_bun_venit.twig']] + $p],
            'include dinamic' => ['sabloane', ['sections/po_bun_venit.twig' => '{% include sectiune.tip %}' . $p['sections/po_bun_venit.twig']] + $p],
            'variabilă din afara modelului' => ['sabloane', ['sections/po_bun_venit.twig' => '{{ _context|length }}{{ operator.cui|default("") }}' . $p['sections/po_bun_venit.twig']] + $p],
            'macro' => ['sabloane', ['partials/antet.twig' => '{% macro x() %}{% endmacro %}' . $p['partials/antet.twig']] + $p],
            'fără cazo.head()' => ['compozitie', ['layout/baza.twig' => str_replace('{{ cazo.head() }}', '', $layout)] + $p],
            'fără cazo.foot()' => ['compozitie', ['layout/baza.twig' => str_replace('{{ cazo.foot() }}', '', $layout)] + $p],
            'șablon obligatoriu lipsă' => ['compozitie', ['templates/camera.json' => null] + $p],
            'secțiune inexistentă' => ['compozitie', ['templates/acasa.json' => '{"sectiuni": [{"tip": "nu_exista"}]}'] + $p],
            'CSS !important (ar ascunde totalul)' => ['css', ['assets/css/pornire.css' => '[data-pg="comanda"] .pg-total { display: none !important; }'] + $p],
            'CSS @layer propriu' => ['css', ['assets/css/pornire.css' => '@layer peste { [data-pg="legal"] { display: none; } }'] + $p],
            'CSS @import' => ['css', ['assets/css/pornire.css' => '@import url("https://rau.example/x.css");'] + $p],
            'CSS url() extern' => ['css', ['assets/css/pornire.css' => 'body { background: url(https://rau.example/urmarire.gif); }'] + $p],
            'CSS cu acoladă închisă în plus' => ['css', ['assets/css/pornire.css' => 'a { color: red; } } [data-pg="legal"] { display: none; }'] + $p],
            'CSS expression' => ['css', ['assets/css/pornire.css' => 'a { width: expression(alert(1)); }'] + $p],
            'font ttf' => ['css', ['assets/css/pornire.css' => '@font-face { font-family: X; src: url(../fonts/x.ttf); }'] + $p],
            'SVG cu DOCTYPE (entități)' => ['imagini', ['assets/img/sigla.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><!DOCTYPE x><script>alert(1)</script></svg>'] + $p],
            'locale fără engleză' => ['limbi', ['locales/en.json' => null] + $p],
            'chei diferite RO/EN' => ['limbi', ['locales/en.json' => '{"salt_continut": "Skip"}'] + $p],
            'linie lungă în locale' => ['limbi', ['locales/ro.json' => str_replace('Mergeți la conținut', "Mergeți \u{2014} la conținut", $p['locales/ro.json'])] + $p],
            'Ș cu sedilă' => ['limbi', ['locales/ro.json' => str_replace('Mergeți la conținut', "Mergeți la \u{015F}tiri", $p['locales/ro.json'])] + $p],
            'manifest fără limba engleză' => ['manifest', ['theme.json' => str_replace('"limbi": ["ro", "en"]', '"limbi": ["ro"]', $p['theme.json'])] + $p],
            'versiune care nu e semver' => ['manifest', ['theme.json' => str_replace('"versiune": "1.0.0"', '"versiune": "unu"', $p['theme.json'])] + $p],
            'font declarat lipsă' => ['manifest', ['theme.json' => str_replace('"fonturi": []', '"fonturi": [{"familie": "X", "fisier": "assets/fonts/x.woff2", "licenta": "OFL-1.1"}]', $p['theme.json'])] + $p],
            'font care nu e WOFF2' => ['fonturi', ['theme.json' => str_replace('"fonturi": []', '"fonturi": [{"familie": "X", "fisier": "assets/fonts/x.woff2", "licenta": "OFL-1.1"}]', $p['theme.json']),
                'assets/fonts/x.woff2' => 'nu e un font'] + $p],
            'CSS peste buget' => ['bugete', ['theme.json' => str_replace('"css": ["assets/css/pornire.css"]', '"css": ["assets/css/pornire.css", "assets/css/mare.css"]', $p['theme.json']), 'assets/css/mare.css' => implode("\n", array_map(static fn (int $i): string => '.c' . $i . '-' . bin2hex(random_bytes(8)) . ' { color: #' . substr(md5((string) $i), 0, 6) . '; }', range(1, 9000)))] + $p],
            // Escape-urile CSS: browserul le decodează („!\important” e „!important”), deci „\” e refuzat în afara șirurilor.
            'CSS !\important cu escape' => ['css', ['assets/css/pornire.css' => '[data-pg="legal"] { display: none !\important; }'] + $p],
            'CSS @\import cu escape' => ['css', ['assets/css/pornire.css' => '@\import "https://rau.example/x.css";'] + $p],
            'CSS u\72 l() cu escape' => ['css', ['assets/css/pornire.css' => 'body { background: u\72 l(https://rau.example/urmarire.gif); }'] + $p],
            'CSS url() cu escape în șir' => ['css', ['assets/css/pornire.css' => 'body { background: url("\68ttps://rau.example/urmarire.gif"); }'] + $p],
            // Un comentariu Twig taie textul literal în două noduri; regulile se aplică pe sursa întreagă.
            'meta http-equiv tăiat de un comentariu Twig' => ['html', ['layout/baza.twig' => str_replace('<head>', '<head><meta {# #}http-equiv="refresh" content="0;url=https://rau.example">', $layout)] + $p],
            'script tăiat de un comentariu Twig' => ['html', ['layout/baza.twig' => str_replace('</body>', '<scr{# x #}ipt>alert(1)</script></body>', $layout)] + $p],
            'http-equiv pe un tag cu numele din expresie' => ['html', ['layout/baza.twig' => str_replace('<head>', "<head><{{ 'meta' }} http-equiv=\"refresh\" content=\"0;url=https://rau.example\">", $layout)] + $p],
            // A doua verificare: un „/*” într-un șir ascundea regulile de după el (comentariile se scoteau înaintea șirurilor).
            'CSS: comentariu deschis într-un șir' => ['css', ['assets/css/pornire.css' => '.a { content: "/*"; } [data-pg="legal"] { display: none !important; } .b { content: "*/"; }'] + $p],
            'CSS: jetoanele nucleului redefinite (text alb pe alb)' => ['css', ['assets/css/pornire.css' => '[data-pg="legal"] { --pg-culoare-text: #fff; --pg-culoare-fundal: #fff; }'] + $p],
            // HTML citit ca HTML5: „>” într-o valoare între ghilimele, „/” în loc de spațiu.
            'meta cu „>” într-o valoare înaintea lui http-equiv' => ['html', ['layout/baza.twig' => str_replace('<head>', '<head><meta content="0;url=https://rau.example/?a=>" http-equiv="refresh">', $layout)] + $p],
            'meta cu „/” în loc de spațiu' => ['html', ['layout/baza.twig' => str_replace('<head>', '<head><meta/http-equiv="refresh" content="0;url=https://rau.example">', $layout)] + $p],
            'atribut on* după „/”' => ['html', ['partials/antet.twig' => str_replace('<header class="po-antet">', '<header/onmouseover="x()" class="po-antet">', $p['partials/antet.twig'])] + $p],
            // Construcția Twig în locul numelui unui atribut, sub o condiție pe fixturi (randarea de probă n-ar vedea-o).
            'http-equiv dintr-o expresie, sub o condiție pe fixturi' => ['html', ['layout/baza.twig' => str_replace('<head>', "<head>{% if proprietate.nume != 'Pensiunea Probă' %}<meta {{ 'http-equiv' }}=\"refresh\" content=\"0;url=https://rau.example\">{% endif %}", $layout)] + $p],
            // Un șir Twig cu „%}{#” ascundea textul literal de expresiile regulate; lexerul Twig îl citește corect.
            'șir Twig cu „%}{#” care ascunde textul' => ['html', ['layout/baza.twig' => str_replace('<head>', "<head>{% set z = '%}{#' %}<meta http-equiv=\"refresh\" content=\"0;url=https://rau.example\">{{ '#}' }}", $layout)] + $p],
            // Strămoșii blocurilor nucleului: ce nu poate impune CSS-ul nucleului fără să strice grilele.
            'CSS: subsolul ascuns' => ['css', ['assets/css/pornire.css' => '.po-subsol { display: none; }'] + $p],
            'CSS: footer mutat în afara ecranului' => ['css', ['assets/css/pornire.css' => 'footer { position: absolute; left: -9999px; }'] + $p],
            'CSS: body cu text-indent negativ' => ['css', ['assets/css/pornire.css' => 'body { text-indent: -9999px; }'] + $p],
            'CSS: main strâns sub 1em' => ['css', ['assets/css/pornire.css' => 'main { max-width: 2px; }'] + $p],
            'CSS: strămoș prins prin :where(:has([data-pg]))' => ['css', ['assets/css/pornire.css' => ':where(div:has([data-pg])) { display: none; }'] + $p],
            'CSS: blocul legal cu margin negativ' => ['css', ['assets/css/pornire.css' => '[data-pg="legal"] { margin-left: -99999px; }'] + $p],
            'CSS: blocul legal fără lățime' => ['css', ['assets/css/pornire.css' => '[data-pg] { width: 0; }'] + $p],
            // A doua verificare de securitate a temelor.
            'CSS: @property golește jetoanele' => ['css', ['assets/css/pornire.css' => "@property --pg-culoare-text { syntax: '<color>'; inherits: false; initial-value: transparent; }"] + $p],
            'CSS: footer relativ mutat la stânga' => ['css', ['assets/css/pornire.css' => 'footer { position: relative; left: -9999px; }'] + $p],
            'CSS: footer mutat cu translate' => ['css', ['assets/css/pornire.css' => 'footer { translate: -9999px 0; }'] + $p],
            'CSS: body cu zoom' => ['css', ['assets/css/pornire.css' => 'body { zoom: .01; }'] + $p],
            'CSS: z-index peste blocurile nucleului' => ['css', ['assets/css/pornire.css' => '.po-antet::after { content: ""; position: fixed; inset: 0; background: #fff; z-index: 2147483647; }'] + $p],
            'CSS: z-index din calc()' => ['css', ['assets/css/pornire.css' => '.po-antet { position: relative; z-index: calc(1e9); }'] + $p],
            'HTML: comentariul <!--> închis pe loc' => ['html', ['partials/antet.twig' => '<!--><meta http-equiv="refresh" content="0;url=/x">-->' . $p['partials/antet.twig']] + $p],
            'HTML: comentariul <!---> închis pe loc' => ['html', ['partials/antet.twig' => '<!---><meta http-equiv="refresh" content="0;url=/x">-->' . $p['partials/antet.twig']] + $p],
            'HTML: comentariul închis cu --!>' => ['html', ['partials/antet.twig' => '<!-- x --!><meta http-equiv="refresh" content="0;url=/x"><!-- -->' . $p['partials/antet.twig']] + $p],
            'HTML: textarea în svg nu e text brut' => ['html', ['partials/antet.twig' => '<svg><textarea><meta http-equiv="refresh" content="0;url=/x"></textarea></svg>' . $p['partials/antet.twig']] + $p],
            'HTML: title în svg nu e text brut' => ['html', ['partials/antet.twig' => '<svg><title><p></p><form action="/x"></form></title></svg>' . $p['partials/antet.twig']] + $p],
            'HTML: tag de control lipit în numele atributului' => ['html', ['partials/antet.twig' => '<meta {% if site.an < 2027 %}data-{% endif %}http-equiv="refresh" content="0;url=/x">' . $p['partials/antet.twig']] + $p],
            'HTML: tag de control lipit în numele tagului' => ['html', ['partials/antet.twig' => '<{% if site.an < 2027 %}x{% endif %}iframe src="/x"></iframe>' . $p['partials/antet.twig']] + $p],
            'HTML: captura set care se termină într-un tag deschis' => ['html', ['partials/antet.twig' => '{% set a %}<meta http-equ{% endset %}{{ a }}iv="refresh" content="0;url=/x">' . $p['partials/antet.twig']] + $p],
            'HTML: apply cu replace face un tag nou' => ['html', ['partials/antet.twig' => "{% apply replace({'[': '<'}) %}[meta http-equiv=\"refresh\" content=\"0;url=/x\">{% endapply %}" . $p['partials/antet.twig']] + $p],
            // A patra verificare de securitate a temelor.
            'CSS: strămoș transparent' => ['css', ['assets/css/pornire.css' => 'main { opacity: 0; }'] + $p],
            'CSS: strămoș micșorat cu transform' => ['css', ['assets/css/pornire.css' => 'footer { transform: scale(0); }'] + $p],
            'CSS: strămoș cu content-visibility' => ['css', ['assets/css/pornire.css' => 'main { content-visibility: hidden; }'] + $p],
            'CSS: strămoș cu padding uriaș' => ['css', ['assets/css/pornire.css' => 'footer { padding-left: 400vw; }'] + $p],
            'CSS: rânduri suprapuse' => ['css', ['assets/css/pornire.css' => 'main { line-height: 0; }'] + $p],
            'CSS: litere strânse în bloc' => ['css', ['assets/css/pornire.css' => '[data-pg] * { letter-spacing: -20px; }'] + $p],
            'CSS: padding uriaș pe bloc' => ['css', ['assets/css/pornire.css' => '[data-pg] { padding-left: 400vw; }'] + $p],
            'CSS: pseudo-element absolut peste bloc' => ['css', ['assets/css/pornire.css' => '[data-pg]::after { content: ""; position: absolute; inset: 0; background: #fff; }'] + $p],
            'CSS: font micșorat cu size-adjust' => ['css', ['assets/css/pornire.css' => '@font-face { font-family: "x"; src: url("../fonts/x.woff2") format("woff2"); size-adjust: 1%; }'] + $p],
            'HTML: atributul hidden' => ['html', ['partials/antet.twig' => '<div hidden>x</div>' . $p['partials/antet.twig']] + $p],
            'HTML: atributul inert' => ['html', ['partials/antet.twig' => '<div inert>x</div>' . $p['partials/antet.twig']] + $p],
            'HTML: aria-hidden în jurul contactului' => ['html', ['partials/antet.twig' => '<div aria-hidden="true">{{ cazo.contact() }}</div>' . $p['partials/antet.twig']] + $p],
            'HTML: details închis în jurul contactului' => ['html', ['partials/antet.twig' => '<details><summary>x</summary>{{ cazo.contact() }}</details>' . $p['partials/antet.twig']] + $p],
            'HTML: dialog în top layer' => ['html', ['partials/antet.twig' => '<dialog closedby="none">x</dialog>' . $p['partials/antet.twig']] + $p],
            'HTML: popover' => ['html', ['partials/antet.twig' => '<button popovertarget="p">x</button><div id="p" popover="manual">x</div>' . $p['partials/antet.twig']] + $p],
            'HTML: commandfor' => ['html', ['partials/antet.twig' => '<button commandfor="d" command="show-modal">x</button>' . $p['partials/antet.twig']] + $p],
            'HTML: interestfor' => ['html', ['partials/antet.twig' => '<a href="/" interestfor="p">x</a>' . $p['partials/antet.twig']] + $p],
            'HTML: referință numerică fără punct și virgulă' => ['html', ['partials/antet.twig' => '<a href="&#106avascript:alert(1)">x</a>' . $p['partials/antet.twig']] + $p],
            'HTML: rădăcina shadow fără slot ascunde blocul legal' => ['html', ['partials/antet.twig' => '<div><template shadowrootmode="open"></template></div>' . $p['partials/antet.twig']] + $p],
            'HTML: o expresie în comentariu îl poate închide' => ['html', ['partials/antet.twig' => '<!-- {{ site.nume }} <meta http-equiv="refresh" content="0;url=/x"> -->' . $p['partials/antet.twig']] + $p],
            // Izolarea: tema stilează doar containerul ei; html, body, :root, .pg-tema și zonele nucleului îi sunt închise.
            'CSS: html ascuns' => ['css', ['assets/css/pornire.css' => 'html { visibility: hidden; }'] + $p],
            'CSS: body cu clasă' => ['css', ['assets/css/pornire.css' => 'body.po-corp { height: 0; overflow: hidden; }'] + $p],
            'CSS: :root cu jetoanele nucleului' => ['css', ['assets/css/pornire.css' => ':root { --pg-culoare-text: transparent; }'] + $p],
            'CSS: body într-un selector compus' => ['css', ['assets/css/pornire.css' => '.a, body > footer { display: none; }'] + $p],
            'CSS: :is(body) într-o listă' => ['css', ['assets/css/pornire.css' => ':is(body) footer { display: none; }'] + $p],
            'CSS: containerul temei ascuns' => ['css', ['assets/css/pornire.css' => '.pg-tema { display: none; }'] + $p],
            'CSS: zona nucleului ascunsă' => ['css', ['assets/css/pornire.css' => '.pg-nucleu { display: none; }'] + $p],
            'CSS: zona nucleului prinsă pe atribut' => ['css', ['assets/css/pornire.css' => '[data-pg-nucleu="jos"] { opacity: 0; }'] + $p],
            'CSS: corpul nucleului' => ['css', ['assets/css/pornire.css' => '.pg-corp > * { display: none; }'] + $p],
            'CSS: selector care începe cu ~' => ['css', ['assets/css/pornire.css' => '~ footer { display: none; }'] + $p],
            'CSS: regulă imbricată spre frații containerului' => ['css', ['assets/css/pornire.css' => '.po-antet { & ~ footer { display: none; } }'] + $p],
            'CSS: html în @media' => ['css', ['assets/css/pornire.css' => '@media screen { html { zoom: .01; } }'] + $p],
            // A cincea verificare (izolarea): comentariile care ascund </body> sau <body>, containerul și locul comenzii
            // scrise de temă, marginea, padding-ul și float-ul pe clasa de pe <body>.
            'HTML: comentariu cu </body> după </body>' => ['html', ['layout/baza.twig' => str_replace('</body>', '</body><!-- </body> -->', $layout)] + $p],
            'HTML: comentariu cu <body> în <head>' => ['html', ['layout/baza.twig' => str_replace('</head>', '<!-- <body> --></head>', $layout)] + $p],
            'HTML: containerul temei scris de temă, peste tot' => ['html', ['partials/antet.twig' => '</div><section><div class="pg-tema po-voal"><a href="/x">Rezervați</a></div></section>' . $p['partials/antet.twig']] + $p],
            'HTML: zona nucleului imitată' => ['html', ['partials/antet.twig' => '<div data-pg-nucleu="jos">x</div>' . $p['partials/antet.twig']] + $p],
            'HTML: locul comenzii scris de temă' => ['html', ['partials/antet.twig' => '<!--pg-zona-continut-->' . $p['partials/antet.twig']] + $p],
            'CSS: margin negativ pe clasa de pe <body>' => ['css', ['assets/css/pornire.css' => '.po-corp { margin-bottom: -100000px; }'] + $p],
            'CSS: float pe clasa de pe <body>' => ['css', ['assets/css/pornire.css' => '.po-corp { float: left; }'] + $p],
            'CSS: padding uriaș pe clasa de pe <body>' => ['css', ['assets/css/pornire.css' => '@media screen { .po-corp { padding-top: 400vh; } }'] + $p],
            // A șasea verificare: HTML-ul citit ca de browser („</” + non-literă, elementele cu text brut, spațiile HTML5).
            'HTML: „</ ” e un comentariu fals, nu text' => ['html', ['partials/antet.twig' => '<div class="po-x"><div>.</ </div></ </div>' . $p['partials/antet.twig']] + $p],
            'HTML: „</>” gol' => ['html', ['partials/antet.twig' => '<p>a</>b</p>' . $p['partials/antet.twig']] + $p],
            'HTML: <plaintext> face text restul paginii' => ['html', ['partials/antet.twig' => '<plaintext>' . $p['partials/antet.twig']] + $p],
            'HTML: <xmp>' => ['html', ['partials/antet.twig' => '<xmp></div></xmp>' . $p['partials/antet.twig']] + $p],
            'HTML: <noembed>' => ['html', ['partials/antet.twig' => '<noembed></div></noembed>' . $p['partials/antet.twig']] + $p],
            'HTML: <noframes>' => ['html', ['partials/antet.twig' => '<noframes></div></noframes>' . $p['partials/antet.twig']] + $p],
            'HTML: data-pg scris de temă (un bloc fals al nucleului)' => ['html', ['partials/antet.twig' => '<div data-pg="harta"><a href="https://rau.example">Confirmă</a></div>' . $p['partials/antet.twig']] + $p],
            'HTML: <listing>' => ['html', ['partials/antet.twig' => '<listing>x</listing>' . $p['partials/antet.twig']] + $p],
            'HTML: <noscript> în temă' => ['html', ['partials/antet.twig' => '<noscript></div></noscript>' . $p['partials/antet.twig']] + $p],
            'HTML: tab vertical (\\x0B) în tag' => ['html', ['partials/antet.twig' => "<div\x0Bclass=\"x\">x</div>" . $p['partials/antet.twig']] + $p],
            // 5000 x 5000 = 25 de megapixeli: refuzată din antet, înainte ca GD s-o decodeze (ar umple memoria).
            'imagine peste 16 megapixeli' => ['imagini', ['assets/img/mare.png' => "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 5000, 5000) . "\x08\x06\x00\x00\x00" . pack('N', crc32('IHDR' . pack('NN', 5000, 5000) . "\x08\x06\x00\x00\x00"))] + $p],
            'fișier executabil în temă' => ['fisiere', ['assets/img/x.php.png' => 'x'] + $p],
        ];
        $neprinse = [];
        foreach ($cazuri as $caz => [$pas, $fisiere]) {
            $d = tema_din($m, $fisiere);
            $r = Validator::verifica($d, false, ['proba' => false]);
            // Respinsă, și la pasul care răspunde de caz (nu din alt motiv, întâmplător).
            if ($r->trece() || !$r->areEroareLa($pas)) {
                $neprinse[] = $caz . ' (așteptat la „' . $pas . '”, erori la: ' . implode(', ', array_unique(array_column($r->erori, 'pas'))) . ')';
            }
            Mediu::stergeDosar($d);
        }
        egal([], $neprinse, 'teme ostile acceptate sau respinse la alt pas');
    },

    'randarea de probă prinde ce analiza statică nu vede: două <h1>, bara lipsă, imaginea fără dimensiuni, tagurile construite din expresii' => function (Mediu $m): void {
        $p = pornire_fisiere();
        $cazuri = [
            'două <h1>' => ['partials/antet.twig' => '<h1>{{ site.nume }}</h1>' . $p['partials/antet.twig']],
            'fără bara pe pagina de început' => ['sections/po_bun_venit.twig' => str_replace('{{ cazo.bara_rezervare() }}', '', $p['sections/po_bun_venit.twig'])],
            'fără <main>' => ['layout/baza.twig' => str_replace(['<main id="continut" class="po-principal">', '</main>'], ['<div id="continut">', '</div>'], $p['layout/baza.twig'])],
            'imagine fără dimensiuni' => ['sections/po_bun_venit.twig' => '<img src="/x.png" alt="">' . $p['sections/po_bun_venit.twig']],
            'variabilă necunoscută la randare' => ['sections/po_bun_venit.twig' => '{{ proprietate.inexistent }}' . $p['sections/po_bun_venit.twig']],
            // Numele tagului dintr-o expresie: textul literal are doar „<x”, dar HTML-ul randat are elementul.
            'iframe construit dintr-o expresie' => ['sections/po_bun_venit.twig' => "<{{ 'iframe' }} src=\"/x\"></{{ 'iframe' }}>" . $p['sections/po_bun_venit.twig']],
            'formular construit dintr-o expresie' => ['sections/po_bun_venit.twig' => "<{{ 'form' }} method=\"post\" action=\"/x\"></{{ 'form' }}>" . $p['sections/po_bun_venit.twig']],
            // A cincea verificare: un </div> în plus scoate conținutul din container; tagurile neînchise cuprind zona nucleului.
            'un </div> în plus' => ['sections/po_bun_venit.twig' => '</div><section><div class="po-voal">x</div></section>' . $p['sections/po_bun_venit.twig']],
            'taguri neînchise' => ['layout/baza.twig' => str_replace('{{ cazo.foot() }}', '<div class="po-x"><div>{{ cazo.foot() }}', $p['layout/baza.twig'])],
        ];
        foreach ($cazuri as $caz => $schimbari) {
            $d = tema_din($m, $schimbari + $p);
            $r = Validator::verifica($d, false, ['lucru' => $m->dosarTemporar(), 'variante' => ['normal']]);
            adevarat($r->areEroareLa('proba'), "$caz: randarea de probă a trecut\n" . $r->text());
            Mediu::stergeDosar($d);
        }
    },

    'HTML-ul randat: doar nucleul pune formulare (clasa pg-) și refresh-ul din <noscript>; restul e respins' => function (Mediu $m): void {
        egal([], \Cazo\Teme\ProbaTema::verificaHtml('<head><noscript><meta http-equiv="refresh" content="5;url=/stare?x=1&amp;y=2"></noscript></head><form class="pg-bara-formular" method="get" action="/x"></form>'));
        foreach (['<meta http-equiv="refresh" content="0;url=https://rau.example">', '<noscript><meta http-equiv="refresh" content="0;url=//rau.example"></noscript>', '<base href="https://rau.example/">', '<iframe src="/x"></iframe>', '<form action="/x"></form>', '<form class="tema-pg" action="/x"></form>'] as $html) {
            adevarat(\Cazo\Teme\ProbaTema::verificaHtml($html) !== [], $html);
        }
        // HTML5: „/” desparte atributele, „>” dintr-o valoare între ghilimele nu închide tagul.
        adevarat(\Cazo\Teme\ProbaTema::verificaHtml('<meta/http-equiv="refresh" content="0;url=https://rau.example">') !== []);
        adevarat(\Cazo\Teme\ProbaTema::verificaHtml('<meta content="0;url=https://rau.example/?a=>" http-equiv="refresh">') !== []);
        adevarat(\Cazo\Teme\ProbaTema::verificaHtml('<a/onclick="x()">y</a>') !== []);
        // Textul literal, din lexerul Twig: comentariile dispar, expresiile devin marcajul, tagurile de control nu lasă
        // nimic, {% verbatim %} rămâne text, un șir „%}{#” dintr-un tag nu ascunde nimic, rândurile se păstrează.
        $env = \Cazo\Teme\MediuSandbox::creeaza(Mediu::radacina() . '/teme/_pornire', Mediu::radacina() . '/teme/_schelet', null, null);
        $x = \Cazo\Teme\HtmlTaguri::MARCAJ;
        egal("<meta http-equiv>\n{$x}\n<b>{{ y }}</b>", \Cazo\Teme\HtmlTaguri::textLiteral($env, "<meta {# a\nb #}http-equiv>\n{{ x }}\n{% verbatim %}<b>{{ y }}</b>{% endverbatim %}", 't'));
        // Un comentariu pe două rânduri în numele unui tag: textul literal are „<script>”, ca browserul, și linia rămâne cea din fișier.
        $t = \Cazo\Teme\HtmlTaguri::textLiteral($env, "<p>\n</p>\n<scr{# a\nb #}ipt>x()</script>", 't', $linii);
        egal([['Fără <script> în șabloane: interactivitatea vine din componentele nucleului și din CSS.', 3]], \Cazo\Teme\HtmlTaguri::probleme($t, false, $linii));
        $k = \Cazo\Teme\HtmlTaguri::MARCAJ_CONTROL;
        egal("<li {$k}class=\"a\"{$k}>", \Cazo\Teme\HtmlTaguri::textLiteral($env, '<li {% if x %}class="a"{% endif %}>', 't'));
        // Între atribute (după spațiu, după o valoare între ghilimele) și în valorile între ghilimele, marcajul de control e permis;
        // lipit de numele tagului sau al atributului, nu: o ramură absentă ar lipi două bucăți de nume („<if{% if 0 %} {% endif %}rame>”).
        egal([], \Cazo\Teme\HtmlTaguri::citeste("<li {$k}class=\"a\"{$k}>")['marcaje']);
        egal([], \Cazo\Teme\HtmlTaguri::citeste("<a href=\"x\"{$k} lang=\"en\"{$k} title=\"a{$k}b\">")['marcaje']);
        adevarat(\Cazo\Teme\HtmlTaguri::citeste("<li{$k} class=\"a\"{$k}>")['marcaje'] !== []);
        adevarat(\Cazo\Teme\HtmlTaguri::citeste("<if{$k} {$k}rame src=\"/x\">")['marcaje'] !== []);
        adevarat(\Cazo\Teme\HtmlTaguri::citeste("<meta {$k}data-{$k}http-equiv=\"refresh\">")['marcaje'] !== []);
        adevarat(\Cazo\Teme\HtmlTaguri::citeste("<a href=x{$k}y>")['marcaje'] !== []);
        // Captura {% set %} se citește separat; tagul deschis la sfârșitul ei se vede.
        $t = \Cazo\Teme\HtmlTaguri::textLiteral($env, '{% set a %}<meta http-equ{% endset %}{{ a }}iv="refresh">', 't', $linii, $extra);
        egal("{$k}{$x}iv=\"refresh\">", $t);
        egal('<meta http-equ', $extra['capturi'][0][0]);
        adevarat(\Cazo\Teme\HtmlTaguri::citeste($extra['capturi'][0][0])['deschis']);
        fals(\Cazo\Teme\HtmlTaguri::citeste('<p class="a">x</p>')['deschis']);
        \Cazo\Teme\HtmlTaguri::textLiteral($env, "{% apply upper|e %}<p>x</p>{% endapply %}{% apply replace({'a': 'b'}) %}x{% endapply %}", 't', $linii, $extra);
        egal(1, count($extra['probleme']));
        // Comentariile după HTML5: „<!-->”, „<!--->” și „--!>” le închid; tagul de după se vede.
        foreach (['<!--><b x=1>-->', '<!---><b x=1>-->', '<!-- a --!><b x=1><!-- -->'] as $h) {
            egal(['b'], array_column(\Cazo\Teme\HtmlTaguri::citeste($h)['taguri'], 'nume'), $h);
        }
        // Conținut străin: în svg și math, title și textarea sunt taguri obișnuite; în afara lor, text brut.
        egal(['svg', 'textarea', 'meta'], array_column(\Cazo\Teme\HtmlTaguri::citeste('<svg><textarea><meta http-equiv="x"></textarea></svg>')['taguri'], 'nume'));
        egal(['textarea'], array_column(\Cazo\Teme\HtmlTaguri::citeste('<textarea><meta http-equiv="x"></textarea>')['taguri'], 'nume'));
        egal(['svg', 'path', 'title'], array_column(\Cazo\Teme\HtmlTaguri::citeste('<svg><path/></svg><title><meta x></title>')['taguri'], 'nume'));
        // Un comentariu sau un text brut cu o expresie în el: valoarea l-ar putea închide, deci conținutul se citește ca HTML.
        egal(['meta'], array_column(\Cazo\Teme\HtmlTaguri::citeste("<!-- {$x} <meta http-equiv=\"x\"> -->")['taguri'], 'nume'));
        egal("{$k}<meta http-equiv=\"refresh\">{$x}", \Cazo\Teme\HtmlTaguri::textLiteral($env, "{% set z = '%}{#' %}<meta http-equiv=\"refresh\">{{ '#}' }}", 't'));
        // Construcțiile Twig cu ieșire în locul numelui unui tag sau al unui atribut sunt refuzate; în valori între ghilimele, nu.
        adevarat(\Cazo\Teme\HtmlTaguri::citeste("<{$x} src=\"/x\">")['marcaje'] !== []);
        adevarat(\Cazo\Teme\HtmlTaguri::citeste("<meta {$x}=\"refresh\">")['marcaje'] !== []);
        adevarat(\Cazo\Teme\HtmlTaguri::citeste("<a href={$x}>")['marcaje'] !== []);
        egal([], \Cazo\Teme\HtmlTaguri::citeste("<a href=\"{$x}\" class=\"c {$x}\">{$x}</a>")['marcaje']);
    },

    'protecțiile nucleului pe blocuri și pe strămoși: înălțimea, depășirea, text-indent, pseudo-elementele, transformările, măștile și suprapunerea, în afara straturilor' => function (Mediu $m): void {
        $css = (string) file_get_contents(Mediu::radacina() . '/front/css/cazo.css');
        // „height: 0; overflow: hidden” pe un strămoș și un ::after alb peste blocul legal nu mai ascund nimic.
        contine($css, 'perspective: none !important; height: auto !important; max-height: none !important; overflow: visible !important; text-indent: 0 !important; translate: none !important; scale: none !important; rotate: none !important; zoom: 1 !important; mask: none !important; -webkit-mask: none !important; z-index: auto !important; isolation: auto !important; contain: none !important; will-change: auto !important; mix-blend-mode: normal !important; }');
        contine($css, ':where(*):has([data-pg])::before, :where(*):has([data-pg])::after { content: none !important; }');
        // Blocul e poziționat și stă deasupra temei (z-index 2147483646, bara 2147483647; tema are cel mult 10000).
        adevarat((bool) preg_match('~^\[data-pg\] \{[^}]*position: relative !important;[^}]*max-height: none !important; text-indent: 0 !important; inset: auto !important; z-index: 2147483646 !important; \}~m', $css));
        contine($css, '[data-pg="bara"] { z-index: 2147483647 !important; }');
        contine($css, ':is([data-pg], [data-pg] *) { translate: none !important; scale: none !important; rotate: none !important; zoom: 1 !important; mask: none !important; -webkit-mask: none !important; -webkit-text-fill-color: currentColor !important; font-size-adjust: none !important; background-image: none !important;');
        adevarat((bool) preg_match('~^\[data-pg\] \* \{[^}]*text-indent: 0 !important; \}~m', $css));
        // v8: toți strămoșii (opacitatea, transform, filter, clip-path, content-visibility, backdrop-filter, perspective), hidden și
        // <details> închis, textul blocului (contur, mascare, direcție, bidi, sublinierea, clicurile), pseudo-elementele blocului.
        contine($css, ':where(*):has([data-pg]) { opacity: 1 !important; transform: none !important; filter: none !important; clip-path: none !important; content-visibility: visible !important; backdrop-filter: none !important; perspective: none !important;');
        contine($css, ':where([hidden]):has([data-pg]) { display: block !important; }');
        contine($css, ':where(details):has([data-pg])::details-content { content-visibility: visible !important; display: block !important; }');
        contine($css, '-webkit-text-stroke: 0 !important; -webkit-text-security: none !important; direction: ltr !important; unicode-bidi: normal !important; text-decoration-thickness: auto !important; pointer-events: auto !important;');
        contine($css, ':is([data-pg], [data-pg] *)::before, :is([data-pg], [data-pg] *)::after { position: static !important; background: none !important; }');
        contine($css, ':is([data-pg], [data-pg] *)::first-line, :is([data-pg], [data-pg] *)::first-letter { color: inherit !important; -webkit-text-fill-color: currentColor !important; }');
        contine($css, ':is([data-pg="legal"], [data-pg="contact"], [data-pg="comanda"], :is([data-pg="legal"], [data-pg="contact"], [data-pg="comanda"]) *) { font-family: var(--pg-font-text, system-ui, sans-serif) !important; }');
        // size-adjust și *-override în @font-face: între 50% și 200% (un font micșorat la 1% face textul de 0×0).
        $fp = \Cazo\Teme\ValidatorCss::probleme('@font-face { font-family: "x"; src: url("x.woff2"); size-adjust: 1%; ascent-override: 90%; }');
        egal(['size-adjust într-un @font-face rămâne între 50% și 200%: 1%'], $fp);
        // Pe pagina randată: un bloc într-un element care îl ascunde se vede; referințele fără „;” se citesc ca în browser.
        adevarat(\Cazo\Teme\ProbaTema::verificaHtml('<div aria-hidden="true"><div data-pg="legal">x</div></div>') !== []);
        adevarat(\Cazo\Teme\ProbaTema::verificaHtml('<details><div data-pg="legal">x</div></details>') !== []);
        egal([], \Cazo\Teme\ProbaTema::verificaHtml('<details open><div data-pg="legal">x</div></details><span aria-hidden="true">*</span><div data-pg="contact">x</div>'));
        adevarat(\Cazo\Teme\ProbaTema::verificaHtml('<a href="&#106avascript:x()">y</a>') !== []);
        adevarat(\Cazo\Teme\ProbaTema::verificaHtml('<a href="javascript&#x3Ax()">y</a>') !== []);
        // Fără display, position și margin impuse pe strămoși: grilele și centrarea temelor rămân (le ține validatorul).
        lipseste($css, ':where(*):has([data-pg]) { display:');
        $p = \Cazo\Teme\ValidatorCss::probleme('.ch-hero li::before { width: 5px; } summary::-webkit-details-marker { display: none; } .x input { width: 1px; } .y i { width: 14px; }');
        egal([], $p, 'pseudo-elementele, elementele goale și pictogramele de text nu sunt strămoși');
    },
    'izolarea: selectorii temei prefixați cu :where(.pg-tema), html, body și :root refuzați, zona nucleului deasupra containerului temei' => function (Mediu $m): void {
        $V = \Cazo\Teme\ValidatorCss::class;
        // Fiecare selector: descendent al containerului sau pe container (clasele de pe <body> ajung pe .pg-tema); specificitatea e aceeași.
        egal(':where(.pg-tema) .a, .a:where(.pg-tema), :where(.pg-tema) .b > c::before, .b:where(.pg-tema) > c::before {color: red;}', $V::prefixeaza('.a, .b > c::before {color: red;}'));
        egal(':where(.pg-tema) a::after, a:where(.pg-tema)::after {x: 1;}', $V::prefixeaza('a::after {x: 1;}'));
        egal(':where(.pg-tema) .x:hover::first-line, .x:hover:where(.pg-tema)::first-line {x: 1;}', $V::prefixeaza('.x:hover::first-line {x: 1;}'));
        // În @media și @supports se prefixează; @keyframes și @font-face rămân.
        egal('@media (min-width: 48rem) {:where(.pg-tema) .a, .a:where(.pg-tema) { x: 1; } }', $V::prefixeaza('@media (min-width: 48rem) { .a { x: 1; } }'));
        egal('@supports (display: grid) {@media print {:where(.pg-tema) .a, .a:where(.pg-tema) { x: 1; } } }', $V::prefixeaza('@supports (display: grid) { @media print { .a { x: 1; } } }'));
        egal('@keyframes k {from { opacity: 0; }50% { opacity: 1; } }', $V::prefixeaza('@keyframes k { from { opacity: 0; } 50% { opacity: 1; } }'));
        egal('@font-face { font-family: "a, b {"; src: url("x.woff2"); }', $V::prefixeaza('@font-face { font-family: "a, b {"; src: url("x.woff2"); }'));
        // Frații containerului sunt zonele nucleului: după primul compus, „+” și „~” rămân doar în varianta descendentă.
        egal(':where(.pg-tema) div ~ footer, :where(.pg-tema) a > b + c, a:where(.pg-tema) > b + c {x: 1;}', $V::prefixeaza('div ~ footer, a > b + c {x: 1;}'));
        egal(':where(.pg-tema) :where(*) ~ footer {x: 1;}', $V::prefixeaza('~ footer {x: 1;}'));
        // Virgulele și acoladele din șiruri, paranteze și atribute nu despart selectorii; comentariile ies.
        egal(':where(.pg-tema) [title="a, b"], [title="a, b"]:where(.pg-tema), :where(.pg-tema) :is(.c, .d) e, :is(.c, .d):where(.pg-tema) e {x: 1;}', $V::prefixeaza('/* x, { */ [title="a, b"], :is(.c, .d) e {x: 1;}'));
        // Selectorii refuzați: html, body, :root, .pg-tema, .pg-nucleu, .pg-corp, [data-pg-nucleu]; tbody, .body, [data-x="body"] rămân.
        foreach (['html { x: 1; }', 'body { x: 1; }', 'BODY.a { x: 1; }', ':root { x: 1; }', '.a, body > b { x: 1; }', ':is(body) a { x: 1; }', '.a ~ html { x: 1; }',
            '.pg-tema { x: 1; }', '.a .pg-nucleu { x: 1; }', '.pg-corp > * { x: 1; }', '[data-pg-nucleu] { x: 1; }', '@media print { html { x: 1; } }',
            '~ footer { x: 1; }', '.a, + b { x: 1; }', '.a { & ~ footer { x: 1; } }', '.a { .b { x: 1; } }', '@media print { .a { ~ b { x: 1; } } }'] as $css) {
            adevarat($V::probleme($css) !== [], "acceptat: $css");
        }
        foreach (['tbody td { x: 1; }', '.body { x: 1; }', '.html-x { x: 1; }', '[data-x="body"] { x: 1; }', '.pg-temaxyz { x: 1; }', 'a:has(> b, + c) { x: 1; }', '@keyframes k { from { x: 1; } }', '@media print { .a { x: 1; } }'] as $css) {
            egal([], $V::probleme($css), $css);
        }
        // Temele oficiale: niciun selector din CSS-ul publicat nu ajunge în afara containerului temei.
        foreach (glob(Mediu::radacina() . '/teme/*/assets/css/*.css') as $fisier) {
            $iesire = $V::inStrat((string) file_get_contents($fisier));
            preg_match_all('~(?:^|[{};])\s*([^{};@\s][^{};]*)\{~', $iesire, $sel);
            foreach ($sel[1] as $lista) {
                if (preg_match('~^\s*(from|to|\d+(\.\d+)?%)(\s*,\s*(from|to|\d+(\.\d+)?%))*\s*$~', $lista)) {
                    continue;
                }
                adevarat(str_contains($lista, ':where(.pg-tema)'), basename($fisier) . ': selector neprefixat: ' . trim($lista));
            }
        }
        // Nucleul: containerul temei e un context de suprapunere la z-index 0, zona nucleului e soră cu el, deasupra, cu jetoanele temei.
        $css = (string) file_get_contents(Mediu::radacina() . '/front/css/cazo.css');
        adevarat((bool) preg_match('~^body > \.pg-tema \{ display: flow-root !important; position: relative !important; inset: auto !important; float: none !important; margin: 0 !important; padding: 0 !important; z-index: 0 !important; isolation: isolate !important; height: auto !important; max-height: none !important; min-height: auto !important;~m', $css));
        // Temele terțe: zona nucleului are fontul de sistem al nucleului.
        contine($css, 'body.pg-corp--terta > .pg-nucleu, body.pg-corp--terta > .pg-nucleu * { font-family: system-ui, sans-serif !important; }');
        // Margin, padding și float pe clasa de pe <body>: refuzate, afară de valorile neutre; doar pe containerul însuși.
        egal(1, count($V::peCorp('.a-corp { margin-bottom: -1000px; }', ['a-corp'])));
        egal(1, count($V::peCorp('.a-corp--acasa { padding-top: 40vw; }', ['a-corp'], ['a-corp--'])));
        egal(2, count($V::peCorp('.x { color: red; } .a-corp, .y { float: left; } .z { x: 1; } .a-corp:hover { margin: 5px; }', ['a-corp'])));
        egal([], $V::peCorp('.a-corp { margin: 0; padding: 0 auto; float: none; } .a-corp a { margin: 9px; } .a-corp > .b { padding: 2rem; }', ['a-corp']));
        adevarat((bool) preg_match('~^body > \.pg-nucleu \{ display: block !important; position: relative !important; z-index: 1 !important; isolation: isolate !important;[^}]*background-color: var\(--pg-culoare-fundal\) !important; color: var\(--pg-culoare-text\) !important;~m', $css));
    },

    'raportul: fișierul și linia erorii din șablon' => function (Mediu $m): void {
        $p = pornire_fisiere();
        $d = tema_din($m, ['sections/po_bun_venit.twig' => "<section>\n\n{{ x|raw }}\n</section>"] + $p);
        $r = Validator::verifica($d, false, ['proba' => false]);
        $gasit = array_values(array_filter($r->erori, static fn (array $e): bool => $e['fisier'] === 'sections/po_bun_venit.twig' && $e['pas'] === 'sabloane'));
        adevarat($gasit !== [], $r->text());
        egal(3, $gasit[0]['linie']);
        contine(Raport::dinTablou($r->tablou())->text(), 'sections/po_bun_venit.twig:3');
    },

    'captura temei (theme.json, cheia `captura`): doar ea e imagine în rădăcină; validatorul cere o imagine adevărată, de tipul din nume, de cel mult 200 KB și 1600 px' => function (Mediu $m): void {
        $p = pornire_fisiere();
        $imagine = static function (int $l, int $h, string $tip = 'webp', bool $zgomot = false): string {
            $img = imagecreatetruecolor($l, $h);
            imagefill($img, 0, 0, (int) imagecolorallocate($img, 40, 90, 160));
            if ($zgomot) {
                for ($k = 0; $k < $l * $h; $k++) {
                    imagesetpixel($img, $k % $l, intdiv($k, $l), random_int(0, 0xFFFFFF));
                }
            }
            ob_start();
            $tip === 'webp' ? imagewebp($img, null, 80) : ($tip === 'png' ? imagepng($img) : imagejpeg($img, null, 85));
            return (string) ob_get_clean();
        };
        $cu = static fn (string $nume = 'captura.webp'): string => str_replace('"pentru":', '"captura": "' . $nume . '", "pentru":', $p['theme.json']);
        adevarat(str_contains($cu(), '"captura": "captura.webp"'), 'manifestul de pornire s-a schimbat');

        // Despachetarea: captura trece, altă imagine în rădăcină sau o captură cu extensie dublă executabilă, nu.
        $lucru = $m->dosarTemporar();
        $zip = static function (array $fisiere) use ($m): string {
            $f = $m->dosarTemporar() . '/t.zip';
            $z = new ZipArchive();
            $z->open($f, ZipArchive::CREATE);
            foreach ($fisiere as $rel => $c) {
                $z->addFromString($rel, $c);
            }
            $z->close();
            return $f;
        };
        $d = Despachetare::din($zip(['theme.json' => $cu(), 'captura.webp' => $imagine(640, 480)] + $p), $lucru);
        adevarat(is_file($d->dir . '/captura.webp'));
        Despachetare::sterge($d->dir);
        foreach (['poza.webp', 'captura.gif', 'captura.svg', 'captura.php.webp', 'captura.webp.php', 'Captura.webp', 'layout/captura.webp'] as $nume) {
            arunca(fn () => Despachetare::din($zip([$nume => $imagine(64, 48)] + $p), $lucru), EroareTema::class, null, $nume);
        }
        egal([], glob($lucru . '/*') ?: []);

        // Validatorul, pe temă: (pasul așteptat sau null când trece, fișierele).
        $cazuri = [
            'captură WebP bună' => [null, ['theme.json' => $cu(), 'captura.webp' => $imagine(640, 480)] + $p],
            'captură PNG bună' => [null, ['theme.json' => $cu('captura.png'), 'captura.png' => $imagine(640, 480, 'png')] + $p],
            'captură JPEG bună' => [null, ['theme.json' => $cu('captura.jpg'), 'captura.jpg' => $imagine(640, 480, 'jpg')] + $p],
            'temă fără captură' => [null, $p],
            'captură declarată, fișier lipsă' => ['manifest', ['theme.json' => $cu()] + $p],
            'captură prea mare (peste 200 KB)' => ['imagini', ['theme.json' => $cu('captura.png'), 'captura.png' => $imagine(700, 700, 'png', true)] + $p],
            'captură falsă: text cu nume de imagine' => ['imagini', ['theme.json' => $cu(), 'captura.webp' => '<?php echo 1; ?>'] + $p],
            'captură falsă: HTML cu antet GIF' => ['imagini', ['theme.json' => $cu(), 'captura.webp' => 'GIF89a<script>alert(1)</script>'] + $p],
            'captură cu altă extensie decât conținutul' => ['imagini', ['theme.json' => $cu(), 'captura.webp' => $imagine(640, 480, 'png')] + $p],
            'captură peste 1600 px' => ['imagini', ['theme.json' => $cu(), 'captura.webp' => $imagine(1700, 400)] + $p],
            'captură cu alt nume în manifest' => ['manifest', ['theme.json' => $cu('assets/img/captura.webp')] + $p],
            'captură cu cale în sus' => ['manifest', ['theme.json' => $cu('../captura.webp')] + $p],
        ];
        adevarat(strlen($cazuri['captură prea mare (peste 200 KB)'][1]['captura.png']) > Validator::CAPTURA_OCTETI, 'imaginea de probă e sub limită');
        foreach ($cazuri as $nume => [$pas, $fisiere]) {
            $r = Validator::verifica(tema_din($m, $fisiere), false, ['proba' => false]);
            if ($pas === null) {
                egal([], $r->erori, "$nume:\n" . $r->text());
            } else {
                adevarat($r->areEroareLa($pas), "$nume: aștepta o eroare la „{$pas}”:\n" . $r->text());
            }
        }
        // O captură nedeclarată în manifest: doar o observație (cardul arată numele temei).
        $r = Validator::verifica(tema_din($m, ['captura.webp' => $imagine(640, 480)] + $p), false, ['proba' => false]);
        egal([], $r->erori, $r->text());
        contine($r->text(), 'declarați-o în theme.json');
        // Tema citește captura doar când fișierul există și numele e cel permis.
        $t = Tema::din(tema_din($m, ['theme.json' => $cu(), 'captura.webp' => $imagine(640, 480)] + $p), Mediu::radacina() . '/teme/_schelet', false, 'lumina');
        egal('captura.webp', $t->captura());
        $t = Tema::din(tema_din($m, ['theme.json' => $cu()] + $p), Mediu::radacina() . '/teme/_schelet', false, 'lumina');
        egal(null, $t->captura());
    },

    'temele oficiale au captura declarată, WebP de 600 x 750, sub 60 KB; biblioteca și scheletul autorilor, fără' => function (Mediu $m): void {
        $n = 0;
        foreach (glob(Mediu::radacina() . '/teme/*/theme.json') ?: [] as $f) {
            $dir = dirname($f);
            $slug = basename($dir);
            $manifest = Tema::json($f);
            if ($slug[0] === '_') {
                fals(isset($manifest['captura']), $slug);
                continue;
            }
            egal('captura.webp', $manifest['captura'] ?? null, $slug);
            $i = getimagesize($dir . '/captura.webp');
            adevarat($i !== false && $i[2] === IMAGETYPE_WEBP, "$slug: captura nu e WebP");
            egal([600, 750], [$i[0], $i[1]], $slug);
            adevarat(filesize($dir . '/captura.webp') <= 60 * 1024, "$slug: captura are peste 60 KB");
            $n++;
        }
        adevarat($n > 0 && $n >= count(\Cazo\Instalare\Instalator::teme(Mediu::radacina())), 'lipsesc temele oficiale');
    },
];
