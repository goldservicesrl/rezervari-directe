<?php

declare(strict_types=1);

// Temelia panoului, fără bază de date: foaia de stil a panoului (regulile ei și clasele
// folosite în șabloane), șabloanele fără scripturi și fără stiluri în linie (CSP-ul panoului), numele browserului unei
// sesiuni și cookie-ul limbii paginilor dinaintea autentificării.

use Cazo\Controlere\AdminCont;
use Cazo\Controlere\AdminPanou;
use Cazo\I18n\Traducator;

$css = static fn (): string => (string) file_get_contents(Mediu::radacina() . '/front/css/admin.css');

/** Șabloanele panoului și ale instalatorului: nume => conținut. */
$sabloane = static function (): array {
    $r = [];
    foreach (['admin', 'instalare'] as $dosar) {
        foreach (glob(Mediu::radacina() . '/vederi/' . $dosar . '/*.twig') ?: [] as $f) {
            $r[$dosar . '/' . basename($f)] = (string) file_get_contents($f);
        }
    }
    return $r;
};

return [
    'foaia de stil a panoului: regulile cerute de audit (formulare fără clasă, butonul de pericol, stările, telefonul)' => function (Mediu $m) use ($css): void {
        $c = $css();
        // Formularele: regulile stau pe .pa-principal, deci niciun <form> nu mai are nevoie de o clasă (auditul).
        contine($c, '.pa-principal label, .pa-eticheta { display: block;');
        contine($c, '.pa-principal input:not([type="checkbox"]):not([type="radio"]):not([type="color"]):not([type="file"]):not([type="hidden"]), .pa-principal select, .pa-principal textarea {');
        contine($c, '.pa-principal form p { margin: 0 0 1rem; }');
        adevarat((bool) preg_match('~min-height: 44px; width: var\(--pa-camp, 100%\); max-width: var\(--pa-latime, 24rem\);~', $c), 'câmpurile au ținta de 44 px și lățimea din --pa-latime');
        adevarat(!preg_match('~^\.pa-formular (label|input)~m', $c), 'stilul câmpurilor nu mai depinde de .pa-formular');
        // Lățimile scurte câștigă oricare ar fi greutatea selectorului de bază: vin prin --pa-latime.
        adevarat((bool) preg_match('~\.pa-principal input\[type="number"\][^{]*\.pa-principal input\.pa-scurt \{ --pa-latime: 11rem; \}~', $c), 'numerele și codurile sunt scurte');
        contine($c, '.pa-principal input[type="color"] { width: 4rem;');
        contine($c, '.pa-principal textarea { --pa-latime: 44rem;');
        // Butoanele: primar, secundar, pericol; legăturile cu aspect de buton fără subliniere.
        foreach (['.pa-buton {', '.pa-buton-secundar {', '.pa-buton-pericol {'] as $x) {
            contine($c, $x);
        }
        adevarat((bool) preg_match('~\.pa-buton, \.pa-buton-secundar, \.pa-buton-pericol \{[^}]*min-height: 44px;[^}]*text-decoration: none;~', $c), 'butoanele au 44 px și nu sunt subliniate');
        // Stările: reușită, informație, avertizare, eroare.
        foreach (['.pa-mesaj {', '.pa-info {', '.pa-avertizare {', '.pa-eroare {', '.pa-stare-asteapta {', '.pa-stare-confirmata {', '.pa-stare-problema {'] as $x) {
            contine($c, $x);
        }
        // Telefonul (obs. 15): grupurile în bara fixă de jos, submeniul pe un rând derulabil, tabelele ca niște carduri.
        adevarat((bool) preg_match('~@media \(max-width: 40rem\) \{(.*?)\n\}~s', $c, $tel), 'regulile pentru telefon');
        contine($tel[1], '.pa-meniu { position: fixed; z-index: 20; right: 0; bottom: 0; left: 0;');
        contine($tel[1], '.pa-meniu a { position: relative; width: 100%; min-height: 3.5rem;');
        contine($tel[1], '.pa-submeniu ul { flex-wrap: nowrap; overflow-x: auto;');
        contine($tel[1], '.pa-tabel td[data-eticheta]::before, .pa-tabel tbody th[data-eticheta]::before { content: attr(data-eticheta) ": ";');
        contine($tel[1], '.pa-detalii { grid-template-columns: 1fr;');
        // Clasele care lipseau (auditul).
        foreach (['pa-legaturi', 'pa-camp', 'pa-reauth', 'pa-gdpr-stergere', 'pa-cere', 'pa-dif-semn', 'pa-tema-noua'] as $clasa) {
            adevarat((bool) preg_match('~\.' . $clasa . '(?![a-z0-9-])[^{}]*\{~', $c), '.' . $clasa . ' e definită');
        }
        // Focusul vizibil și mișcarea redusă.
        contine($c, ':focus-visible { outline: 3px solid #0b5cad; outline-offset: 2px; }');
        contine($c, '@media (prefers-reduced-motion: reduce)');
        // CSP-ul panoului: nimic adus din afară.
        adevarat(!preg_match('~@import|url\(~i', $c), 'foaia de stil nu aduce nimic din afară');
        // Acoladele se închid toate (o regulă ruptă ar strica tot ce urmează).
        egal(substr_count($c, '{'), substr_count($c, '}'));
    },

    'fiecare clasă pa-* și pi-* folosită în șabloanele panoului și ale instalatorului e definită în foaia de stil' => function (Mediu $m) use ($css, $sabloane): void {
        preg_match_all('~\.(p[ai]-[a-z0-9]+(?:-[a-z0-9]+)*)~', $css(), $x);
        $definite = array_fill_keys($x[1], true);
        // Clase folosite doar ca reper (fără stil propriu): zona „Ștergeți tema” ia stilul din `.pa-tema details`, iar
        // marcajul „cerut” al instalatorului e țintit prin `.pi-cerut-firma`.
        $exceptii = ['pa-tema-sterge', 'pi-cerut'];
        $lipsa = [];
        foreach ($sabloane() as $nume => $t) {
            preg_match_all('~class="([^"]*)"~', $t, $cls);
            foreach ($cls[1] as $valoare) {
                preg_match_all('~p[ai]-[a-z0-9]+(?:-[a-z0-9]+)*-?~', $valoare, $k);
                foreach ($k[0] as $clasa) {
                    if (in_array($clasa, $exceptii, true) || isset($definite[$clasa])) {
                        continue;
                    }
                    // O clasă compusă în șablon (`pa-stare-{{ x }}`): ajunge un început definit.
                    if (str_ends_with($clasa, '-') && array_filter(array_keys($definite), static fn (string $d): bool => str_starts_with($d, $clasa)) !== []) {
                        continue;
                    }
                    $lipsa[$clasa][] = $nume;
                }
            }
        }
        egal([], array_map(static fn (array $f): string => implode(', ', array_unique($f)), $lipsa), 'clase fără stil');
    },

    'șabloanele panoului: fără scripturi, fără stiluri în linie și fără atribute on...= (CSP); meniurile au nume diferite' => function (Mediu $m) use ($sabloane): void {
        foreach ($sabloane() as $nume => $t) {
            // singurul script al panoului, harta de pe „Proprietatea”, ca fișier din /_c/admin/ (fără cod în pagină).
            if ($nume === 'admin/local-proprietate.twig') {
                $t = str_replace('<script src="{{ harta_panou.js }}" defer></script>', '', $t);
            }
            // butoanele „Copiați” ale asistentului trecerii, la fel, dintr-un fișier sub /_c/admin/.
            if ($nume === 'admin/local-trecere.twig') {
                $t = str_replace('<script src="{{ url.copiaza }}" defer></script>', '', $t);
            }
            // Scriptul comun al panoului (pagina rămâne pe loc după o acțiune), tot dintr-un fișier sub /_c/admin/.
            if ($nume === 'admin/baza.twig') {
                $t = str_replace('<script src="{{ js_ramane }}" defer></script>', '', $t);
            }
            adevarat(!preg_match('~<script|\sstyle\s*=|\son[a-z]+\s*=|javascript:~i', $t), $nume);
        }
        $baza = (string) file_get_contents(Mediu::radacina() . '/vederi/admin/baza.twig');
        // Antetul, meniul și submeniul vin din baza.twig; fiecare <nav> are numele lui.
        preg_match_all('~<nav class="(pa-[a-z]+)" aria-label="\{\{ t\(\'([a-z.]+)\'~', $baza, $nav);
        egal(['pa-meniu', 'pa-submeniu'], $nav[1]);
        egal(['admin.meniu', 'admin.submeniu'], $nav[2]);
        contine($baza, '<a class="pa-salt" href="#continut">');
        contine($baza, '<main id="continut" class="pa-principal" tabindex="-1">');
        contine($baza, '<meta name="robots" content="noindex">');
        // Grupul aprins e pagina curentă doar când nu are submeniu; altfel pagina curentă e cea din submeniu.
        contine($baza, "aria-current=\"{{ meniu.pagini ? 'true' : 'page' }}\"");
    },

    'browserul unei sesiuni, pe înțeles: „Chrome pe Windows”; un șir necunoscut rămâne cum e' => function (Mediu $m): void {
        $ro = Traducator::pentru(Mediu::radacina() . '/limbi', 'ro', 'admin');
        $en = Traducator::pentru(Mediu::radacina() . '/limbi', 'en', 'admin');
        $cazuri = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36' => 'Chrome pe Windows',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0' => 'Edge pe Windows',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 OPR/114.0.0.0' => 'Opera pe Windows',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0' => 'Firefox pe Windows',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15' => 'Safari pe macOS',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1' => 'Safari pe iOS',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0.6723.90 Mobile/15E148 Safari/604.1' => 'Chrome pe iOS',
            'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36' => 'Chrome pe Android',
            'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0' => 'Firefox pe Linux',
            'Mozilla/5.0 (Unknown) Firefox/132.0' => 'Firefox',
            'curl/8.5.0' => 'curl/8.5.0',
            '' => 'Browser necunoscut',
        ];
        foreach ($cazuri as $agent => $asteptat) {
            egal($asteptat, AdminCont::browser((string) $agent, $ro), (string) $agent);
        }
        egal('Chrome on Windows', AdminCont::browser((string) array_key_first($cazuri), $en));
        egal('Unknown browser', AdminCont::browser('', $en));
        // Un șir necunoscut, lung: scurtat la 60 de caractere.
        egal(60, mb_strlen(AdminCont::browser(str_repeat('x', 200), $ro)));
        // Sesiunea ține primele 120 de caractere din User-Agent: Chrome și Edge pe Windows rămân recunoscute.
        $edge = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0';
        egal('Edge pe Windows', AdminCont::browser(mb_substr($edge, 0, 120), $ro));
    },

    'cookie-ul limbii paginilor dinaintea autentificării: __Host-, fără date personale, doar limbile panoului' => function (Mediu $m): void {
        egal('__Host-pg_admin_limba', AdminPanou::COOKIE_LIMBA);
        egal('__Host-pg_admin_limba=en; Path=/; Secure; HttpOnly; SameSite=Lax; Max-Age=31536000', AdminPanou::cookieLimba('en'));
        egal('__Host-pg_admin_limba=ro; Path=/; Secure; HttpOnly; SameSite=Lax; Max-Age=31536000', AdminPanou::cookieLimba('ro'));
        // O valoare necunoscută (sau una cu caractere de antet) devine „ro”.
        egal(AdminPanou::cookieLimba('ro'), AdminPanou::cookieLimba('de'));
        egal(AdminPanou::cookieLimba('ro'), AdminPanou::cookieLimba("en\r\nSet-Cookie: x=1"));
    },
];
