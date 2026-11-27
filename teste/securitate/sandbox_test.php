<?php

declare(strict_types=1);

// Sandboxul temelor (`securitate`): corpusul ostil din teste/corpus/sandbox/ e respins integral
// (la compilare, la încărcare sau la randare), un șablon permis trece, iar CSS-ul temei care ar putea ascunde blocul
// legal (!important, @import, url() extern) e refuzat; CSS-ul acceptat ajunge înfășurat în @layer tema.
// Corpusul are și cazurile de resurse: includerea recursivă, dublarea unui text sau a unei liste într-o buclă,
// buclele imbricate, replace/join/batch care multiplică, ieșirea peste 2 MB; garda le oprește devreme, cu o excepție.

use Cazo\Teme\Garda;
use Cazo\Teme\LimitaDepasita;
use Cazo\Teme\MediuSandbox;
use Cazo\Teme\Politica;
use Cazo\Teme\ValidatorCss;

/** O temă minimă într-un dosar temporar, cu un singur șablon de probă (sections/proba.twig). */
$tema = static function (Mediu $m, string $sursa): array {
    $d = $m->dosarTemporar();
    foreach (['sections', 'partials', 'layout'] as $x) {
        mkdir("$d/tema/$x", 0700, true);
    }
    mkdir("$d/schelet/sections", 0700, true);
    file_put_contents("$d/tema/sections/proba.twig", $sursa);
    file_put_contents("$d/tema/partials/x.twig", '{% block y %}x{% endblock %}');
    // Două partiale care se includ reciproc (corpusul include_mutual).
    file_put_contents("$d/tema/partials/a.twig", 'a{% include "partials/b.twig" %}');
    file_put_contents("$d/tema/partials/b.twig", 'b{% include "partials/a.twig" %}');
    // Pentru capturile din corpus: un partial care afișează valoarea mare și un layout cu blocul în captură.
    file_put_contents("$d/tema/partials/m.twig", '{{ m }}');
    file_put_contents("$d/tema/partials/captura-baza.twig", '{% set t %}{% block y %}{% endblock %}{% endset %}{{ t|length }}');
    file_put_contents("$d/tema/layout/baza.twig", '<main>{% block continut %}{% endblock %}</main>');
    return ["$d/tema", "$d/schelet"];
};

/** Cazurile de captură din corpus: Markup repetat în {% set %}, {% apply %}, imbricate, prin include, with, bloc moștenit. */
const CAPTURI = ['captura_set_markup', 'captura_apply_markup', 'captura_imbricata', 'captura_apply_imbricat', 'captura_include', 'captura_with',
    'captura_interpolare', 'captura_filtru', 'captura_fara_afisare', 'captura_lista_markup', 'captura_bloc_mostenit'];

$context = [
    'site' => ['nume' => 'Pensiunea Demo', 'limba' => 'ro'],
    'proprietate' => ['nume' => 'Pensiunea Demo', 'descriere' => '<script>alert(1)</script>'],
    'camere' => [['titlu' => 'A', 'unitati' => 2], ['titlu' => 'B', 'unitati' => 1]],
];

return [
    'corpusul sandbox e respins integral' => function (Mediu $m) use ($tema, $context): void {
        $fisiere = glob(Mediu::radacina() . '/teste/corpus/sandbox/*.twig') ?: [];
        adevarat(count($fisiere) >= 50, 'corpusul are prea puține cazuri');
        $trecute = [];
        foreach ($fisiere as $f) {
            [$dt, $ds] = $tema($m, (string) file_get_contents($f));
            $env = MediuSandbox::creeaza($dt, $ds, null, null);
            try {
                MediuSandbox::randeaza($env, 'sections/proba.twig', $context);
                $trecute[] = basename($f);
            } catch (\Twig\Error\Error|LimitaDepasita) {
                // respins: la compilare (vizitatorul, parserul), la încărcare (politica), la randare (variabilele
                // stricte) sau de gardă (o limită de resurse)
            }
            egal(false, Garda::activa(), 'garda a rămas pornită după ' . basename($f));
        }
        egal([], $trecute, 'șabloane ostile acceptate');
    },

    'un șablon permis trece, cu escaparea automată și funcțiile nucleului rescrise' => function (Mediu $m) use ($tema, $context): void {
        [$dt, $ds] = $tema($m, '{% for c in camere|sort|slice(0, 2) %}{{ c.titlu|upper }}{% endfor %}|{{ proprietate.descriere }}|{{ cazo.head() }}|'
            . '{% for i in 1..3 %}{{ i }}{% endfor %}|{{ site.nume|replace({"Demo": "Probă"}) }}|{% include "partials/x.twig" %}|{{ t("x") }}');
        $html = MediuSandbox::creeaza($dt, $ds, null, null)->load('sections/proba.twig')->render($context);
        contine($html, 'AB|');
        contine($html, '&lt;script&gt;alert(1)&lt;/script&gt;');
        contine($html, '|123|Pensiunea Probă|x|x');
    },

    'garda oprește devreme fiecare limită, cu motivul ei, iar memoria rămâne sub plafon' => function (Mediu $m) use ($tema, $context): void {
        foreach ([
            'include_recursiv' => 'adancime', 'include_mutual' => 'adancime', 'extends_recursiv' => 'adancime', 'dublare_concat' => 'valoare',
            'dublare_capture' => 'valoare', 'dublare_join' => 'valoare', 'replace_expansiune' => 'valoare', 'replace_imbricat' => 'valoare',
            'lista_dublata' => 'elemente', 'bucle_imbricate' => 'iteratii', 'iesire_mare' => 'iesire', 'batch_mare' => 'elemente',
            'sort_tablouri' => 'forma', 'sort_tablouri_concat' => 'forma',
        ] + array_fill_keys(CAPTURI, 'valoare') as $caz => $limita) {
            [$dt, $ds] = $tema($m, (string) file_get_contents(Mediu::radacina() . "/teste/corpus/sandbox/$caz.twig"));
            memory_reset_peak_usage();
            $inainte = memory_get_usage();
            try {
                MediuSandbox::randeaza(MediuSandbox::creeaza($dt, $ds, null, null), 'sections/proba.twig', $context);
                throw new \RuntimeException("$caz a trecut");
            } catch (\Twig\Error\Error|LimitaDepasita $e) {
                egal($limita, LimitaDepasita::din($e)?->limita, "$caz: " . $e->getMessage());
            }
            adevarat(memory_get_peak_usage() - $inainte < 200 * 1024 * 1024, "$caz: memoria");
        }
        // Timpul: o randare peste plafon se oprește la primul pas după el.
        [$dt, $ds] = $tema($m, '{% for i in 1..100 %}{% for j in 1..100 %}{{ i }}{% endfor %}{% endfor %}');
        try {
            MediuSandbox::randeaza(MediuSandbox::creeaza($dt, $ds, null, null), 'sections/proba.twig', $context, 0.000001);
            throw new \RuntimeException('timpul a trecut');
        } catch (\Twig\Error\Error|LimitaDepasita $e) {
            egal('timp', LimitaDepasita::din($e)?->limita, $e->getMessage());
        }
        // Un șablon obișnuit trece sub gardă: escaparea și nl2br rămân corecte după învelirea filtrelor.
        [$dt, $ds] = $tema($m, '{% set s %}<b>{{ site.nume }}</b>{% endset %}{{ s }}|{{ "a\nb"|nl2br }}|{{ site.nume|e }}|{{ [1, 2, 3]|batch(2)|length }}|{{ ["a", "b"]|join(", ") }}|{{ "a-b"|replace({"-": "+"}) }}');
        egal('<b>Pensiunea Demo</b>|a<br />' . "\n" . 'b|Pensiunea Demo|2|a, b|a+b', MediuSandbox::randeaza(MediuSandbox::creeaza($dt, $ds, null, null), 'sections/proba.twig', $context));
    },

    'sort pe liste de tablouri: forma mărginită oprește hangul pătratic devreme, iar sortarea scalară rămâne corectă' => function (Mediu $m) use ($tema, $context): void {
        // O listă pur scalară se sortează corect (asort exact), chiar lungă: nicio regresie a semanticii obișnuite.
        [$dt, $ds] = $tema($m, '{{ ["mar", "arbore", "banana", "cal"]|sort|join(",") }}');
        egal('arbore,banana,cal,mar', MediuSandbox::randeaza(MediuSandbox::creeaza($dt, $ds, null, null), 'sections/proba.twig', $context));
        // Câteva tablouri (sub plafon) se sortează în continuare exact, cu semantica SORT_REGULAR a lui asort.
        [$dt, $ds] = $tema($m, '{% for c in camere|sort %}{{ c.titlu }}{% endfor %}');
        egal('AB', MediuSandbox::randeaza(MediuSandbox::creeaza($dt, $ds, null, null), 'sections/proba.twig', $context));
        // Reproducătorul DoS la scară reală, într-un proces cu memory_limit=128M: split("") umflă ieftin un șir în
        // zeci de mii de tablouri (prin batch(1)), iar asort pe tablouri egale era O(n^2) și ardea ~11 s și un nucleu
        // de procesor. Acum garda oprește la „forma” după SORT_TABLOURI elemente, în câteva milisecunde, cu vârf mic
        // de memorie — fără a mai pătrunde în operația nativă neîntreruptibilă.
        $scris = $m->dosarTemporar() . '/sort.php';
        file_put_contents($scris, '<?php define("CAZO", true); require ' . var_export(Mediu::radacina() . '/app/incarca.php', true) . ';'
            . '[$dt, $ds] = [$argv[1], $argv[2]]; $t = microtime(true);'
            . ' try { \Cazo\Teme\MediuSandbox::randeaza(\Cazo\Teme\MediuSandbox::creeaza($dt, $ds, null, null), "sections/proba.twig", []); echo "TRECUT"; }'
            . ' catch (\Throwable $e) { $l = \Cazo\Teme\LimitaDepasita::din($e); echo "PRINS:", (string) $l?->limita, " ", round(microtime(true) - $t, 2), " ", round(memory_get_peak_usage() / 1048576), "M"; }');
        [$dt, $ds] = $tema($m, '{% set s = "' . str_repeat('a', 40000) . '" %}{{ (s|split("")|batch(1)|sort)|length }}');
        $t0 = microtime(true);
        $iesire = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=128M ' . escapeshellarg($scris) . ' ' . escapeshellarg($dt) . ' ' . escapeshellarg($ds) . ' 2>&1'));
        contine($iesire, 'PRINS:forma ', $iesire);
        adevarat(microtime(true) - $t0 < 5.0, 'reproducătorul sort nu s-a oprit repede: ' . $iesire);
    },

    'operatorul ... e refuzat la compilare, deci un literal uriaș nu mai epuizează memoria' => function (Mediu $m) use ($tema, $context): void {
        foreach (['spread_lista', 'spread_afisat', 'spread_mapare', 'spread_argumente'] as $caz) {
            [$dt, $ds] = $tema($m, (string) file_get_contents(Mediu::radacina() . "/teste/corpus/sandbox/$caz.twig"));
            try {
                MediuSandbox::randeaza(MediuSandbox::creeaza($dt, $ds, null, null), 'sections/proba.twig', $context);
                throw new \RuntimeException("$caz a trecut");
            } catch (\Twig\Error\SyntaxError $e) {
                contine($e->getMessage(), 'operatorul ... e interzis', $caz);
            }
        }
        // Proba verificatorului, într-un proces separat cu memory_limit=128M: înainte se oprea cu o eroare fatală.
        $scris = $m->dosarTemporar() . '/spread.php';
        file_put_contents($scris, '<?php define("CAZO", true); require ' . var_export(Mediu::radacina() . '/app/incarca.php', true) . ';'
            . '[$dt, $ds] = [$argv[1], $argv[2]]; try { \Cazo\Teme\MediuSandbox::randeaza(\Cazo\Teme\MediuSandbox::creeaza($dt, $ds, null, null), "sections/proba.twig", []); echo "TRECUT"; }'
            . ' catch (\Throwable $e) { echo "PRINS:", $e->getMessage(); }');
        foreach (['spread_lista', 'spread_afisat'] as $caz) {
            [$dt, $ds] = $tema($m, (string) file_get_contents(Mediu::radacina() . "/teste/corpus/sandbox/$caz.twig"));
            $iesire = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=128M ' . escapeshellarg($scris) . ' ' . escapeshellarg($dt) . ' ' . escapeshellarg($ds) . ' 2>&1');
            contine($iesire, 'PRINS:operatorul ... e interzis', $caz);
        }
    },

    'capturile se numără cât se adună: fiecare caz din corpus se oprește la 256 KB, și într-un proces cu memory_limit=128M' => function (Mediu $m) use ($tema, $context): void {
        // În proces: motivul „valoare” vine din captură, iar memoria rămâne mică (fără reparație: 144 MB într-un implode).
        foreach (CAPTURI as $caz) {
            [$dt, $ds] = $tema($m, (string) file_get_contents(Mediu::radacina() . "/teste/corpus/sandbox/$caz.twig"));
            memory_reset_peak_usage();
            $inainte = memory_get_usage();
            try {
                MediuSandbox::randeaza(MediuSandbox::creeaza($dt, $ds, null, null), 'sections/proba.twig', $context);
                throw new \RuntimeException("$caz a trecut");
            } catch (\Twig\Error\Error|LimitaDepasita $e) {
                egal('valoare', LimitaDepasita::din($e)?->limita, "$caz: " . $e->getMessage());
                contine($e->getMessage(), 'O captură din temă', $caz);
            }
            adevarat(memory_get_peak_usage() - $inainte < 32 * 1024 * 1024, "$caz: memoria");
        }
        // Codul compilat al temelor are doar captura numărată: niciun implode(iterator_to_array()) din Twig.
        $surse = [];
        foreach (array_merge(CAPTURI, ['dublare_capture']) as $caz) {
            [$dt, $ds] = $tema($m, (string) file_get_contents(Mediu::radacina() . "/teste/corpus/sandbox/$caz.twig"));
            $env = MediuSandbox::creeaza($dt, $ds, null, null);
            foreach (['sections/proba.twig', 'partials/captura-baza.twig'] as $n) {
                $surse[] = $env->compileSource($env->getLoader()->getSourceContext($n));
            }
        }
        foreach (array_merge(...array_map(static fn (string $d): array => glob(Mediu::radacina() . '/teme/*/' . $d . '/*.twig') ?: [], ['layout', 'sections', 'partials'])) as $f) {
            $dt = dirname($f, 2);
            $env = MediuSandbox::creeaza($dt, Mediu::radacina() . '/teme/_schelet', null, null);
            $surse[] = $env->compileSource($env->getLoader()->getSourceContext(substr($f, strlen($dt) + 1)));
        }
        $cuCaptura = 0;
        foreach ($surse as $sursa) {
            lipseste($sursa, 'iterator_to_array(');
            lipseste($sursa, 'captureOutput(');
            $cuCaptura += substr_count($sursa, '\\Cazo\\Teme\\Garda::captura(');
        }
        adevarat($cuCaptura >= count(CAPTURI), 'capturile numărate lipsesc din codul compilat');
        // În proces separat, cu memory_limit=128M: înainte de captura numărată, opt dintre cazuri opreau PHP cu o eroare fatală.
        $scris = $m->dosarTemporar() . '/captura.php';
        file_put_contents($scris, '<?php define("CAZO", true); require ' . var_export(Mediu::radacina() . '/app/incarca.php', true) . ';'
            . '[$dt, $ds] = [$argv[1], $argv[2]]; try { \Cazo\Teme\MediuSandbox::randeaza(\Cazo\Teme\MediuSandbox::creeaza($dt, $ds, null, null), "sections/proba.twig", []); echo "TRECUT"; }'
            . ' catch (\Throwable $e) { echo "PRINS:", \Cazo\Teme\LimitaDepasita::din($e)?->limita; }');
        foreach (CAPTURI as $caz) {
            [$dt, $ds] = $tema($m, (string) file_get_contents(Mediu::radacina() . "/teste/corpus/sandbox/$caz.twig"));
            $iesire = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=128M ' . escapeshellarg($scris) . ' ' . escapeshellarg($dt) . ' ' . escapeshellarg($ds) . ' 2>&1');
            egal('PRINS:valoare', $iesire, $caz);
        }
    },

    'compilarea e mărginită înaintea parserului: imbricările și lanțurile adânci se opresc în câteva milisecunde, și cu memory_limit=128M' => function (Mediu $m) use ($tema, $context): void {
        // Înainte: 1200 de apply imbricate compilau 6,5 s, un lanț de 8000 de ~ 13,5 s, iar 30 de lanțuri de 700 de
        // |upper 8,4 s, toate înaintea primului punct de verificare al gărzii (regula celor 3 s).
        $cazuri = [
            'compilare_apply_imbricat' => 'tagurile se imbrică', 'compilare_if_imbricat' => 'tagurile se imbrică',
            'compilare_for_imbricat' => 'tagurile se imbrică', 'compilare_captura_imbricata' => 'tagurile se imbrică',
            'compilare_lant_filtre' => 'operatori, filtre și accese', 'compilare_lanturi_multiple' => 'operatori, filtre și accese',
            'compilare_lant_concat' => 'operatori, filtre și accese', 'compilare_lant_atribute' => 'operatori, filtre și accese',
            'compilare_lant_plus' => 'operatori, filtre și accese', 'compilare_unar' => 'operatori, filtre și accese',
            'compilare_paranteze' => 'parantezele se deschid', 'compilare_liste_imbricate' => 'parantezele se deschid',
            'compilare_interpolare' => 'parantezele se deschid', 'compilare_jetoane' => 'jetoane', 'compilare_lista_mare' => 'octeți',
        ];
        $scris = $m->dosarTemporar() . '/compilare.php';
        file_put_contents($scris, '<?php define("CAZO", true); require ' . var_export(Mediu::radacina() . '/app/incarca.php', true) . ';'
            . '[$dt, $ds] = [$argv[1], $argv[2]]; $t = microtime(true); try { \Cazo\Teme\MediuSandbox::randeaza(\Cazo\Teme\MediuSandbox::creeaza($dt, $ds, null, null), "sections/proba.twig", []); echo "TRECUT"; }'
            . ' catch (\Throwable $e) { printf("PRINS:%.3f:%s", microtime(true) - $t, $e->getMessage()); }');
        foreach ($cazuri as $caz => $motiv) {
            $sursa = (string) file_get_contents(Mediu::radacina() . "/teste/corpus/sandbox/$caz.twig");
            // În proces: o SyntaxError, înaintea parserului, și pe calea validatorului (fără gardă).
            [$dt, $ds] = $tema($m, $sursa);
            $t = microtime(true);
            try {
                MediuSandbox::creeaza($dt, $ds, null, null)->load('sections/proba.twig');
                throw new \RuntimeException("$caz a trecut");
            } catch (\Twig\Error\SyntaxError $e) {
                contine($e->getMessage(), $motiv, $caz);
            }
            adevarat(microtime(true) - $t < 0.5, "$caz: compilarea a durat " . round(microtime(true) - $t, 2) . ' s');
            $iesire = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=128M ' . escapeshellarg($scris) . ' ' . escapeshellarg($dt) . ' ' . escapeshellarg($ds) . ' 2>&1');
            adevarat((bool) preg_match('~^PRINS:([0-9.]+):~', $iesire, $x), "$caz: $iesire");
            adevarat((float) $x[1] < 0.5, "$caz: compilarea a durat {$x[1]} s");
            contine($iesire, $motiv, $caz);
        }
        // Un șablon permis, la limite: 32 de taguri imbricate, lanțuri de 100, blocuri scurte și set-uri cu = (fără corp).
        $permis = str_repeat('{% if 1 %}', 16) . str_repeat('{% for i in [1] %}', 15) . '{% set s %}{{ "x"' . str_repeat('|lower', 99) . ' }}{% endset %}{{ s }}'
            . str_repeat('{% endfor %}', 15) . str_repeat('{% endif %}', 16)
            . str_repeat('{% set a = 1 %}', 100) . implode('', array_map(static fn (int $i): string => '{% block b' . $i . ' "x" %}', range(1, 60)))
            . '{{ ' . str_repeat('[', 32) . '1' . str_repeat(']', 32) . '|length }}';
        [$dt, $ds] = $tema($m, $permis);
        egal('x' . str_repeat('x', 60) . '1', MediuSandbox::randeaza(MediuSandbox::creeaza($dt, $ds, null, null), 'sections/proba.twig', $context));
        // Temele oficiale (și _schelet) compilează sub lexerul temelor.
        foreach (array_merge(...array_map(static fn (string $d): array => glob(Mediu::radacina() . '/teme/*/' . $d . '/*.twig') ?: [], ['layout', 'sections', 'partials'])) as $f) {
            $dt = dirname($f, 2);
            MediuSandbox::creeaza($dt, Mediu::radacina() . '/teme/_schelet', null, null)->load(substr($f, strlen($dt) + 1));
        }
    },

    'compilarea intră în bugetul de timp: mai multe șabloane permise, dar grele, se opresc la plafon, cu motivul timp' => function (Mediu $m) use ($tema, $context): void {
        // Fiecare secțiune e permisă (31 de apply imbricate, lanțuri de 99 de filtre) și compilează în câteva zecimi
        // de secundă; douăzeci, pe aceeași pagină, ar trece de plafon. Ceasul gărzii rulează și la compilare
        // (LexerTema, VizitatorSandbox), deci pagina se oprește aproape de plafon, nu după toate compilările.
        $sectiune = str_repeat('{% apply upper %}', 31) . str_repeat('{{ "x"' . str_repeat('|upper', 99) . ' }}', 18) . str_repeat('{% endapply %}', 31);
        $pagina = '';
        for ($i = 0; $i < 20; $i++) {
            $pagina .= '{% include "sections/s' . $i . '.twig" %}';
        }
        [$dt, $ds] = $tema($m, $pagina);
        for ($i = 0; $i < 20; $i++) {
            file_put_contents("$dt/sections/s$i.twig", $sectiune . $i);
        }
        $plafon = 0.4;
        $t = microtime(true);
        try {
            MediuSandbox::randeaza(MediuSandbox::creeaza($dt, $ds, null, null), 'sections/proba.twig', $context, $plafon);
            throw new \RuntimeException('compilarea a trecut de plafon');
        } catch (\Twig\Error\Error|LimitaDepasita $e) {
            egal('timp', LimitaDepasita::din($e)?->limita, $e->getMessage());
        }
        $durata = microtime(true) - $t;
        adevarat($durata < $plafon + 0.5, 'oprirea a venit după ' . round($durata, 2) . ' s');
        egal(false, Garda::activa());
    },

    'politica e strictă: fără metode, fără proprietăți, listele închise' => function (Mediu $m): void {
        $p = Politica::securitate();
        adevarat($p->isStrict());
        foreach (['raw', 'map', 'filter', 'reduce', 'column', 'format', 'date', 'json_encode', 'url_encode', 'spaceless'] as $f) {
            adevarat(!in_array($f, Politica::FILTRE, true), "filtrul $f e permis");
        }
        foreach (['macro', 'import', 'from', 'use', 'embed', 'sandbox', 'deprecated', 'cache', 'do', 'autoescape'] as $t) {
            adevarat(!in_array($t, Politica::TAGURI, true), "tagul $t e permis");
        }
        foreach (['template_from_string', 'source', 'constant', 'attribute', 'include', 'dump', 'random', 'parent', 'block'] as $f) {
            adevarat(!in_array($f, Politica::functiiPermise(), true), "funcția $f e permisă");
        }
        arunca(fn () => $p->checkMethodAllowed(new \ArrayObject([]), 'count'), \Twig\Sandbox\SecurityError::class);
        arunca(fn () => $p->checkPropertyAllowed(new \stdClass(), 'x'), \Twig\Sandbox\SecurityError::class);
    },

    'CSS-ul temei: !important, @import, url() extern și @layer propriu refuzate; cel acceptat ajunge în @layer tema' => function (Mediu $m): void {
        foreach ([
            '[data-pg="legal"] { display: none !important; }',
            '@import url("https://rau.example/x.css");',
            '.a { background: url(https://rau.example/p.png); }',
            '.a { background: url(//rau.example/p.png); }',
            '.a { background: url(data:image/svg+xml;base64,AAAA); }',
            '@layer x { [data-pg] { display: none } }',
            '.a { behavior: url(x.htc); }',
            '.a { width: expression(alert(1)); }',
            '@font-face { src: url(../fonts/x.ttf); }',
            '.a{color:red}</style><script>',
        ] as $css) {
            adevarat(ValidatorCss::probleme($css) !== [], "acceptat: $css");
            arunca(fn () => ValidatorCss::inStrat($css), \UnexpectedValueException::class);
        }
        $ok = '@font-face { font-family: X; src: url(../fonts/x.woff2) format("woff2"); } [data-pg="legal"] { color: red; } /* !important în comentariu */';
        egal([], ValidatorCss::probleme($ok));
        $s = ValidatorCss::inStrat($ok);
        adevarat(str_starts_with($s, "@layer tema {\n"));
        adevarat(str_ends_with($s, "\n}\n"));
        // Temele oficiale trec și ele prin validator.
        foreach (glob(Mediu::radacina() . '/teme/*/assets/css/*.css') ?: [] as $f) {
            egal([], ValidatorCss::probleme((string) file_get_contents($f)), basename(dirname($f, 3)) . '/' . basename($f));
        }
    },

    'nucleul declară protecțiile în afara straturilor, cu !important, pe html, body și [data-pg]' => function (Mediu $m): void {
        $css = (string) file_get_contents(Mediu::radacina() . '/front/css/cazo.css');
        egal(0, strpos($css, '/*'));
        contine($css, '@layer nucleu, tema;');
        // Protecțiile stau după blocul @layer nucleu, la nivelul de sus.
        $dupa = substr($css, (int) strpos($css, '/* ---- protecțiile nucleului'));
        lipseste($dupa, '@layer');
        foreach (['display', 'visibility', 'opacity', 'position', 'transform', 'clip-path', 'filter', 'content-visibility', 'font-size', 'color', 'background-color', 'height', 'overflow'] as $p) {
            foreach (['html {', 'body {', '[data-pg] {'] as $sel) {
                $bloc = substr($dupa, (int) strpos($dupa, $sel));
                $bloc = substr($bloc, 0, (int) strpos($bloc, '}'));
                adevarat((bool) preg_match('~(^|[;{ ])' . preg_quote($p, '~') . ':[^;]*!important~', $bloc), "$sel fără $p !important");
            }
        }
    },
];
