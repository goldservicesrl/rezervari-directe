<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;
use Cazo\Securitate\Codare;
use Cazo\Securitate\Csrf;
use Cazo\Teme\MediuNucleu;

defined('CAZO') or exit;

/**
 * Paginile compuse ale panoului (reorganizarea din 20.11.2026): de la 30 de pagini în 6 meniuri la 13 pagini în
 * 4 meniuri. O pagină compusă pune pe același ecran blocurile mai multor pagini de dinainte, fiecare cu controlerul și
 * șablonul ei, deci cu aceleași drepturi și aceleași formulare (aceleași adrese POST):
 *
 *  - controlerul GET al fiecărui bloc rulează ca până acum; `Admin::pagina` îi păstrează datele (`capteaza`) în locul
 *    randării. Un bloc al cărui controler întoarce altceva (utilizatorul fără drept, de exemplu angajatul la un bloc al
 *    proprietarului) lipsește de pe pagină;
 *  - o pagină randată de un controler (GET-ul paginii, ori POST-ul care întoarce formularul cu o greșeală, 422) intră
 *    singură în pagina compusă a vederii ei: celelalte blocuri se adaugă în jurul ei (`paginaPentru`, `randeaza`);
 *  - mesajul și eroarea din adresă (`?mesaj=`, `?eroare=`) ajung doar la blocul lor: cel numit de `bloc=` (pus de
 *    adresele vechi, la redirecționare) sau, fără el, blocul paginii cerute; celelalte blocuri primesc adresa fără ele;
 *  - adresele vechi ale paginilor scoase din meniu (`VECHI`) duc, cu 302 și cu interogarea păstrată, la pagina nouă și
 *    la ancora blocului.
 */
final class AdminCompus
{
    /**
     * Adresele vechi (GET) => [calea nouă, blocul, interogarea adăugată]. POST-urile lor rămân pe loc și se întorc tot
     * aici, deci redirecționarea le duce la blocul lor.
     */
    public const VECHI = [
        '/admin/poze' => ['/admin/continut', 'poze', []],
        '/admin/imprejurimi' => ['/admin/pagini', 'imprejurimi', []],
        '/admin/preturi' => ['/admin/metode-plata', 'preturi', []],
        '/admin/protectie' => ['/admin/metode-plata', 'protectie', []],
        '/admin/legal' => ['/admin/conexiuni', 'date-firma', []],
        '/admin/legal/texte' => ['/admin/gdpr', 'texte', []],
        '/admin/site' => ['/admin/', 'publicare', []],
        '/admin/sistem' => ['/admin/intretinere', 'diagnostic', []],
        '/admin/actualizari' => ['/admin/intretinere', 'actualizari', []],
        '/admin/copii' => ['/admin/intretinere', 'copii', []],
        '/admin/audit' => ['/admin/intretinere', 'jurnal', ['jurnal' => 'audit']],
        '/admin/alerte' => ['/admin/', 'alerte', []],
        '/admin/plati' => ['/admin/rezervari', null, ['vedere' => 'plati']],
        '/admin/plati/avansuri' => ['/admin/rezervari', null, ['vedere' => 'avansuri']],
    ];

    /** Secțiunile de dinainte (`sectiune` din datele paginilor) => [pagina nouă, blocul]: meniul și întoarcerea după parolă. */
    public const SECTIUNI = [
        'poze' => ['continut', 'poze'], 'imprejurimi' => ['pagini', 'imprejurimi'], 'preturi' => ['metode', 'preturi'], 'protectie' => ['metode', 'protectie'],
        'legal' => ['conexiuni', 'date-firma'], 'texte' => ['gdpr', 'texte'], 'site' => ['acasa', 'publicare'], 'sistem' => ['intretinere', 'diagnostic'],
        'actualizari' => ['intretinere', 'actualizari'], 'copii' => ['intretinere', 'copii'], 'audit' => ['intretinere', 'jurnal'], 'alerte' => ['intretinere', 'jurnal'],
        'plati' => ['rezervari', null], 'avansuri' => ['rezervari', null],
    ];

    /**
     * Blocurile fiecărei pagini compuse, în ordinea de pe ecran. Un bloc:
     *  - id: ancora lui (`#poze`); sursa: vederea pe care o randează controlerul; h: controlerul GET;
     *  - vedere: șablonul blocului (implicit sursa), sau [sursa => șablon] când controlerul randează vederi diferite;
     *  - titlu: cheia titlului; null păstrează titlul vechi al paginii, coborât un nivel; false îl scoate (nivelurile
     *    titlurilor din bloc rămân, pentru blocurile care continuă direct titlul paginii);
     *  - avansat: cheia rezumatului „Avansat: …”; blocul stă închis sub el (deschis când e blocul cerut sau `deschis`);
     *  - cand: fn(Aplicatie, date): bool, blocul apare doar atunci (blocul cerut prin `bloc=` apare oricum);
     *  - p: fn(Aplicatie): parametrii rutei.
     * @return array<string, list<array<string,mixed>>>
     */
    public static function pagini(): array
    {
        $activa = static fn (Aplicatie $app): string => (string) $app->setari()->get('tema.activa', \Cazo\Teme\Tema::IMPLICITA);
        return [
            'acasa' => [
                ['id' => 'alerte', 'sursa' => 'acasa', 'h' => [Admin::class, 'acasa'], 'vedere' => 'acasa-alerte', 'titlu' => false],
                ['id' => 'publicare', 'sursa' => 'site', 'h' => [AdminGdpr::class, 'site'], 'titlu' => null,
                    'cand' => static fn (Aplicatie $app, array $d): bool => !$d['publicat']],
                // în modul E (cheie live, funcția etapei 3 aprinsă) blocul lipsește; în P rămân cererile de probă.
                ['id' => 'sosiri', 'sursa' => 'acasa', 'h' => [Admin::class, 'acasa'], 'vedere' => 'acasa-sosiri', 'titlu' => false,
                    'cand' => static fn (Aplicatie $app, array $d): bool => ($d['ec_acasa'] ?? null) === null || $d['ec_acasa']['proba']],
                ['id' => 'stare', 'sursa' => 'sistem', 'h' => [AdminSistem::class, 'sistem'], 'vedere' => 'sistem-stare', 'titlu' => null],
            ],
            'continut' => [
                ['id' => 'descriere', 'sursa' => 'continut', 'h' => [AdminSistem::class, 'continut'], 'titlu' => 'admin.continut.bloc_titlu'],
                ['id' => 'poze', 'sursa' => 'poze', 'h' => [AdminContinut::class, 'poze'], 'titlu' => null],
            ],
            'pagini' => [
                ['id' => 'pagini', 'sursa' => 'pagini', 'h' => [AdminContinut::class, 'pagini'], 'titlu' => null],
                ['id' => 'imprejurimi', 'sursa' => 'imprejurimi', 'h' => [AdminPret::class, 'imprejurimi'], 'titlu' => null],
            ],
            'aspect' => [
                ['id' => 'teme', 'sursa' => 'aspect', 'h' => [AdminTeme::class, 'lista'], 'titlu' => 'admin.teme.bloc_titlu'],
                ['id' => 'sigla', 'sursa' => 'aspect-sigla', 'h' => [AdminTeme::class, 'siglaBloc'], 'titlu' => null],
                ['id' => 'setari-tema', 'sursa' => 'aspect-setari', 'h' => [AdminTeme::class, 'setari'], 'titlu' => null,
                    'p' => static fn (Aplicatie $app): array => ['slug' => $activa($app)],
                    'cand' => static fn (Aplicatie $app, array $d): bool => ($d['tema']['slug'] ?? null) === $activa($app)],
            ],
            'metode' => [
                ['id' => 'metode', 'sursa' => 'metode-plata', 'h' => [Admin::class, 'metode'], 'titlu' => false],
                ['id' => 'preturi', 'sursa' => 'pret', 'h' => [AdminPret::class, 'preturi'], 'titlu' => null],
                ['id' => 'protectie', 'sursa' => 'protectie', 'h' => [Admin::class, 'protectie'], 'titlu' => null, 'avansat' => 'admin.protectie.avansat',
                    'deschis' => static fn (array $d): bool => (bool) ($d['intrerupator']['pornit'] ?? false)],
            ],
            'conexiuni' => [
                ['id' => 'legatura', 'sursa' => 'conexiuni', 'h' => [AdminSistem::class, 'conexiuni'], 'titlu' => false],
                ['id' => 'date-firma', 'sursa' => 'legal', 'h' => [AdminGdpr::class, 'legal'], 'vedere' => 'legal-firma', 'titlu' => null],
                ['id' => 'adresa', 'sursa' => 'adresa', 'h' => [AdminAdresa::class, 'bloc'], 'titlu' => null],
            ],
            'gdpr' => [
                ['id' => 'texte', 'sursa' => 'texte-legale', 'h' => [AdminContinut::class, 'texteLegale'], 'titlu' => null],
                ['id' => 'pe-site', 'sursa' => 'legal', 'h' => [AdminGdpr::class, 'legal'], 'vedere' => 'legal-site', 'titlu' => null],
                ['id' => 'oaspeti', 'sursa' => 'gdpr', 'h' => [AdminGdpr::class, 'gdpr'], 'titlu' => null],
            ],
            'intretinere' => [
                ['id' => 'actualizari', 'sursa' => 'actualizari', 'h' => [Actualizari::class, 'pagina'], 'titlu' => null],
                ['id' => 'copii', 'sursa' => 'copii', 'h' => [AdminSistem::class, 'copiiPagina'], 'titlu' => null,
                    'cand' => static fn (Aplicatie $app, array $d): bool => ($d['de_restaurat'] ?? null) === null],
                ['id' => 'jurnal', 'sursa' => 'jurnal', 'h' => [self::class, 'jurnal'], 'titlu' => 'admin.jurnal.titlu',
                    'cand' => static fn (Aplicatie $app, array $d): bool => (bool) ($d['vede_jurnal'] ?? true)],
                ['id' => 'publicare', 'sursa' => 'site', 'h' => [AdminGdpr::class, 'site'], 'titlu' => null,
                    'cand' => static fn (Aplicatie $app, array $d): bool => (bool) $d['publicat']],
                ['id' => 'diagnostic', 'sursa' => 'sistem', 'h' => [AdminSistem::class, 'sistem'], 'titlu' => null, 'avansat' => 'admin.sistem.avansat_diagnostic'],
                ['id' => 'sarcini', 'sursa' => 'sistem', 'h' => [AdminSistem::class, 'sistem'], 'vedere' => 'sistem-sarcini', 'titlu' => null, 'avansat' => 'admin.sistem.avansat_sarcini'],
            ],
        ];
    }

    // ---- Strângerea datelor unui bloc ----

    /** @var list<?array{sursa:string, d:array, r:Raspuns}> */
    private static array $captura = [];

    /** Admin::pagina: cât se strânge un bloc, datele paginii rămân aici în locul randării. */
    public static function capteaza(string $vedere, array $d, Raspuns $r): bool
    {
        if (self::$captura === []) {
            return false;
        }
        self::$captura[array_key_last(self::$captura)] = ['sursa' => $vedere, 'd' => $d, 'r' => $r];
        return true;
    }

    /** @return ?array{sursa:string, d:array, r:Raspuns} datele blocului, sau null când controlerul a întors altceva */
    private static function strange(Aplicatie $app, array $h, Cerere $c, array $p): ?array
    {
        self::$captura[] = null;
        try {
            [$h[0], $h[1]]($app, $c, $p, 'ro');
        } finally {
            $x = array_pop(self::$captura);
        }
        return $x;
    }

    /**
     * Pagina compusă în care intră vederea randată acum de un controler, sau null (o vedere de sine stătătoare: pasul de
     * restaurare a unei copii, setările unei teme care nu e pe site, pagina unei rezervări).
     */
    public static function paginaPentru(Aplicatie $app, string $vedere, array $d): ?string
    {
        if (self::$captura !== [] || !isset($d['utilizator']) || $app->cerereInCurs() === null) {
            return null;
        }
        foreach (self::pagini() as $pagina => $blocuri) {
            foreach ($blocuri as $b) {
                if ($b['sursa'] === $vedere && (!isset($b['cand']) || ($b['cand'])($app, $d))) {
                    return $pagina;
                }
            }
        }
        return null;
    }

    /** GET /admin/intretinere: Setări › Întreținere (actualizările, copiile, jurnalul, diagnosticul). */
    public static function intretinere(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return self::randeaza($app, 'intretinere', null);
    }

    /**
     * Blocul „Jurnalul” de pe Întreținere: alertele trimise gazdei (AdminLista::alerte, proprietarul și angajatul cu
     * dreptul de rambursare) sau, cu `jurnal=audit`, ce s-a făcut în panou (AdminCont::audit, doar proprietarul).
     */
    public static function jurnal(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return $c->param('jurnal') === 'audit' ? AdminCont::audit($app, $c, $p, $limba) : AdminLista::alerte($app, $c, $p, $limba);
    }

    /**
     * Pagina compusă: blocurile ei, fiecare cu datele controlerului lui. $preset: datele vederii randate deja de
     * controlerul cererii (cu statusul și cookie-urile lui).
     * @param ?array{sursa:string, d:array, r:Raspuns} $preset
     */
    public static function randeaza(Aplicatie $app, string $pagina, ?array $preset): Raspuns
    {
        $c = $app->cerereInCurs();
        $s = $c !== null ? AdminPanou::sesiune($app, $c, AdminPanou::SECTIUNI[$pagina][1]) : null;
        if ($c === null || $s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u] = $s;
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $blocuri = self::pagini()[$pagina];
        $ids = array_column($blocuri, 'sursa', 'id');
        $bloc = (string) ($c->param('bloc') ?? '');
        $bloc = isset($ids[$bloc]) ? $bloc : '';
        // Blocul numit în adresă e altul decât cel randat de controler: controlerul se reia fără mesajul adresei.
        if ($preset !== null && $bloc !== '' && $c->esteCitire() && $ids[$bloc] !== $preset['sursa']) {
            $preset = null;
        }
        $activ = $bloc !== '' ? [$bloc] : ($preset !== null ? array_keys(array_filter($ids, static fn (string $x): bool => $x === $preset['sursa'])) : []);
        $final = Raspuns::html('', $preset !== null ? $preset['r']->status : 200);
        $cookies = $preset !== null ? $preset['r']->cookies() : [];
        // Toate blocurile scriu jetonul CSRF legat de același cookie: cel al cererii, al controlerului sau unul nou.
        $legatura = null;
        foreach ($cookies as $k) {
            if (str_starts_with($k, Csrf::COOKIE . '=')) {
                $legatura = substr(explode(';', $k, 2)[0], strlen(Csrf::COOKIE) + 1);
            }
        }
        $legatura ??= $c->cookie(Csrf::COOKIE);
        if ($legatura === null || !preg_match('~^[A-Za-z0-9_-]{43}$~', $legatura)) {
            $legatura = Codare::jeton(32);
            $cookies[] = Csrf::COOKIE . '=' . $legatura . '; Path=/; Secure; HttpOnly; SameSite=Lax';
        }
        $jar = [Csrf::COOKIE => $legatura] + $c->cookies;
        $faraMesaj = array_diff_key($c->query, ['mesaj' => true, 'eroare' => true]);
        $env = MediuNucleu::creeaza(Aplicatie::radacina() . '/vederi', null, $t);
        $comun = ['limba' => $l, 'css' => '', 'site' => $app->modelVedere('ro')->nume(), 'incorporat' => true];
        $strans = [];
        $parti = [];
        foreach ($blocuri as $b) {
            $eActiv = in_array($b['id'], $activ, true);
            if ($preset !== null && $b['sursa'] === $preset['sursa'] && ($bloc === '' || $eActiv)) {
                $x = $preset;
            } else {
                $p = isset($b['p']) ? ($b['p'])($app) : [];
                $cheie = $b['h'][0] . '::' . $b['h'][1] . '|' . json_encode($p) . '|' . ($eActiv ? 'a' : '');
                if (!array_key_exists($cheie, $strans)) {
                    $strans[$cheie] = self::strange($app, $b['h'], $c->caCitire($eActiv ? $c->query : $faraMesaj, $jar), $p);
                }
                $x = $strans[$cheie];
            }
            if ($x === null || (isset($b['cand']) && !$eActiv && !($b['cand'])($app, $x['d']))) {
                continue;
            }
            foreach ($x !== $preset ? $x['r']->cookies() : [] as $k) {
                $cookies[] = $k;
            }
            $vedere = is_array($b['vedere'] ?? null) ? ($b['vedere'][$x['sursa']] ?? $x['sursa']) : ($b['vedere'] ?? $x['sursa']);
            $html = $env->load('admin/' . $vedere . '.twig')->renderBlock('continut', $x['d'] + $comun);
            $parti[] = self::bloc($b, $html, $t, $eActiv || (isset($b['deschis']) && ($b['deschis'])($x['d'])));
        }
        // Un cookie o singură dată (ultimul scris câștigă), ca la răspunsul unei pagini simple.
        $unice = [];
        foreach ($cookies as $k) {
            $unice[explode('=', $k, 2)[0]] = $k;
        }
        foreach ($unice as $k) {
            $final->cookie($k);
        }
        $d = ['utilizator' => $u, 'sectiune' => $pagina, 'csrf' => $app->csrf()->emite($legatura), 'titlu' => $t->t('admin.nav.' . $pagina), 'blocuri' => $parti];
        return Admin::pagina($app, $l, 'compus', $d, $final);
    }

    /**
     * Un bloc al paginii: titlul lui (cel vechi coborât un nivel, unul nou, sau niciunul), învelit în secțiunea cu ancora
     * lui ori, la blocurile avansate, în zona „Avansat: …”.
     */
    private static function bloc(array $b, string $html, \Cazo\I18n\Traducator $t, bool $deschis): string
    {
        if ($b['titlu'] === false) {
            $html = (string) preg_replace('~<h1\b[^>]*>.*?</h1>\s*~s', '', $html, 1);
        } else {
            // Titlurile de pe pagina veche coboară un nivel: titlul ei devine titlul blocului, sub titlul paginii compuse.
            $html = (string) preg_replace_callback('~<(/?)h([1-5])\b~', static fn (array $m): string => '<' . $m[1] . 'h' . ((int) $m[2] + 1), $html);
            if (is_string($b['titlu'])) {
                $text = htmlspecialchars($t->t($b['titlu']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $html = (string) preg_replace_callback('~(<h2\b[^>]*>).*?(</h2>)~s', static fn (array $m): string => $m[1] . $text . $m[2], $html, 1);
            }
        }
        $id = htmlspecialchars((string) $b['id'], ENT_QUOTES);
        if (isset($b['avansat'])) {
            return '<details class="pa-avansat pa-bloc" id="' . $id . '"' . ($deschis ? ' open' : '') . '><summary>' . htmlspecialchars($t->t($b['avansat']), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . '</summary>' . "\n" . trim($html) . "\n</details>\n";
        }
        return '<section class="pa-bloc" id="' . $id . '">' . "\n" . trim($html) . "\n</section>\n";
    }

    // ---- Adresele vechi ----

    /** GET pe o adresă veche (VECHI): spre pagina nouă și blocul ei, cu interogarea păstrată (302). */
    public static function vechi(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $cale = $c->cale;
        // Fișierul avansurilor rămâne la adresa lui (legăturile din pagină și cele salvate de gazdă).
        if ($cale === '/admin/plati/avansuri' && $c->param('format') === 'csv') {
            return AdminRezervari::avansuri($app, $c, $p, $limba);
        }
        [$tinta, $bloc, $q] = self::VECHI[$cale] ?? ['/admin/', null, []];
        // Publicarea: cât site-ul e în pregătire, pe Acasă; după publicare, la Întreținere (retragerea).
        if ($cale === '/admin/site' && $app->setari()->get('site.publicat', false) === true) {
            $tinta = '/admin/intretinere';
        }
        // Jurnalul alertelor (filtrul perioadei) e acum pe Întreținere; alertele de acum, pe Acasă.
        if ($cale === '/admin/alerte' && $c->param('zile') !== null) {
            [$tinta, $bloc] = ['/admin/intretinere', 'jurnal'];
        }
        return self::spre($app, $tinta, $bloc, $q + $c->query);
    }

    /** GET /admin/aspect/{slug}/setari: setările temei de pe site stau pe pagina Aspect; ale altei teme, pe pagina lor. */
    public static function setariTema(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        if ((string) ($p['slug'] ?? '') === (string) $app->setari()->get('tema.activa', \Cazo\Teme\Tema::IMPLICITA)) {
            return self::spre($app, '/admin/aspect', 'setari-tema', $c->query);
        }
        return AdminTeme::setari($app, $c, $p, $limba);
    }

    /** Redirecționarea 302 spre o pagină și un bloc al ei; `bloc=` duce mesajul adresei la blocul lui. */
    public static function spre(Aplicatie $app, string $cale, ?string $bloc, array $q, int $status = 302): Raspuns
    {
        unset($q['r'], $q['bloc']);
        if ($bloc !== null) {
            $q['bloc'] = $bloc;
        }
        return Raspuns::redirectare($app->url()->cale($cale, null, $q) . ($bloc !== null ? '#' . $bloc : ''), $status);
    }

    /** Adresa unei secțiuni (pagina din meniu, sau pagina nouă și blocul unei secțiuni de dinainte), cu ancora. */
    public static function adresa(Aplicatie $app, string $sectiune, array $q = []): string
    {
        [$pagina, $bloc] = self::SECTIUNI[$sectiune] ?? [$sectiune, null];
        $cale = AdminPanou::SECTIUNI[$pagina][0] ?? '/admin/';
        return $app->url()->cale($cale, null, $q) . ($bloc !== null ? '#' . $bloc : '');
    }
}
