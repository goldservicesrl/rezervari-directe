<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\Legal\PaginiLegale;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cai;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Csp;
use Cazo\Nucleu\Erori;
use Cazo\Nucleu\Raspuns;
use Cazo\Pret\Sejur;
use Cazo\Securitate\Ip;
use Cazo\Teme\Componente;

defined('CAZO') or exit;

/**
 * Paginile publice randate de temă: acasă, camerele, camera, paginile gazdei, paginile
 * legale (cu conținutul nucleului) și căutarea pe toate camerele. Paginile fără parametri sunt aceleași
 * pentru toți vizitatorii și intră în cache-ul de pagini; căutarea și paginile cu sejur în adresă se calculează
 * la fiecare cerere și poartă noindex.
 */
final class Prezentare
{
    public static function acasa(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return self::randeaza($app, $c, $limba, self::seo($app, 'acasa', $limba, ['pagina' => 'acasa', 'sablon' => 'acasa'] + self::seoCalculat($app, 'acasa', $limba)), 'acasa');
    }

    /**
     * Titlul și descrierea calculate de site (fără ce a scris gazda la „Google și limbi”), pentru pagina de început
     * și lista camerelor. Panoul le arată ca valori de acum.
     * @return array{titlu:string, descriere:string}
     */
    public static function seoCalculat(Aplicatie $app, string $pagina, string $limba): array
    {
        $prop = $app->modelVedere($limba)->proprietate();
        if ($pagina === 'camere') {
            $t = $app->traducator($limba);
            return ['titlu' => $t->t('pagina.camere.titlu', ['nume' => $prop['nume']]), 'descriere' => $t->t('pagina.camere.descriere', ['nume' => $prop['nume']])];
        }
        return [
            'titlu' => $prop['nume'] !== '' ? $prop['nume'] : $app->traducator($limba)->t('meniu.acasa'),
            // În meta, titlul descrierii e despărțit de text: „Titlu. Textul…”, tăiat la 160 de caractere.
            'descriere' => \Cazo\Teme\Functii::scurtat(($prop['descriere_titlu'] !== '' ? $prop['descriere_titlu'] . '. ' : '') . implode(' ', $prop['descriere_paragrafe']), 160),
        ];
    }

    public static function camere(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return self::randeaza($app, $c, $limba, self::seo($app, 'camere', $limba, ['pagina' => 'camere', 'sablon' => 'camere'] + self::seoCalculat($app, 'camere', $limba)), 'camere');
    }

    public static function camera(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $m = $app->modelVedere($limba);
        $camera = $m->cameraDupaSlug((string) $p['slug']);
        if ($camera === null) {
            return Erori::pagina($app->traducator($limba), 404, $app->url()->cale('/', $limba));
        }
        $t = $app->traducator($limba);
        $sluguri = $m->sluguri($camera['id']);
        $alternative = [];
        foreach ($app->config()->limbi() as $l) {
            $alternative[$l] = $app->url()->absolut(Cai::cale('camera', $l, ['slug' => $sluguri[$l]]), $l);
        }
        $cerere = $c->query !== [] ? Sejur::dinQuery($c->query, $camera['id']) : null;
        return self::randeaza($app, $c, $limba, [
            'pagina' => 'camera',
            'sablon' => 'camera',
            // Fără repetiție când camera poartă numele proprietății („Casa Mare | Casa Mare”).
            'titlu' => self::titluCu($camera['titlu'], $m->proprietate()['nume']),
            'descriere' => $camera['descriere'] !== '' ? \Cazo\Teme\Functii::scurtat($camera['descriere'], 160) : $t->t('pagina.camera.descriere', ['camera' => $camera['titlu'], 'nume' => $m->proprietate()['nume']]),
            'model' => ['camera' => $camera],
            'poza_og' => $camera['poza_principala'],
            'cerere' => $cerere,
            // Întoarcerea de la o cerere cu nopți ocupate chiar acum (409): zilele marcate în calendar.
            'ocupate' => Componente::ocupateDin($c->query['ocupate'] ?? null),
        ], 'camera', [], $alternative);
    }

    /** „Titlu | Numele proprietății”, sau doar titlul când e chiar numele proprietății. */
    public static function titluCu(string $titlu, string $nume): string
    {
        return $nume === '' || mb_strtolower(trim($titlu)) === mb_strtolower(trim($nume)) ? $titlu : $titlu . ' | ' . $nume;
    }

    /**
     * Adresa cerută e a unei pagini pentru vizitatori: fără zonele tehnice (/_..., /admin, /instalare,
     * /.well-known) și fără fișiere (o adresă cu extensie, de obicei un robot care caută fișiere), care primesc
     * pagina 404 simplă, ieftină.
     */
    public static function paginaDeVizitator(string $cale): bool
    {
        return !preg_match('~^/(?:en/)?(?:_|admin(?:/|$)|instalare(?:/|$)|\.)|\.[A-Za-z0-9]{1,6}$~', $cale);
    }

    /** Pagina 404 în layoutul temei, cu antetul și subsolul site-ului; în afara cache-ului, cu noindex. */
    public static function negasit(Aplicatie $app, Cerere $c, string $limba): Raspuns
    {
        $t = $app->traducator($limba);
        $html = self::nucleu($app, $limba, 'eroare', [
            'titlu' => $t->t('eroare.404.titlu'), 'text' => $t->t('eroare.404.text'),
            'acasa' => $app->url()->cale('/', $limba), 'acasa_text' => $t->t('eroare.link_acasa'),
        ]);
        $alternative = [];
        foreach ($app->config()->limbi() as $l) {
            $alternative[$l] = $app->url()->absolut('/', $l);
        }
        $alternative['x-default'] = $alternative[$app->config()->limbaImplicita()] ?? reset($alternative);
        $raspuns = static function (array $r) use ($app): Raspuns {
            $raspuns = Raspuns::html($r['html'], 404);
            $raspuns->antet('Content-Security-Policy', Csp::pentru('public', $app->config()->origine(), ['tema_cu_js' => $app->temaActiva()->areJs()]));
            $raspuns->deTinut = false;
            return $raspuns;
        };
        return $raspuns($app->randare()->pagina([
            'pagina' => 'eroare',
            'sablon' => null,
            'limba' => $limba,
            'titlu' => self::titluCu($t->t('eroare.404.titlu'), $app->modelVedere($limba)->proprietate()['nume']),
            'canonic' => $alternative[$limba] ?? $app->url()->absolut('/', $limba),
            'alternative' => $alternative,
            'noindex' => true,
            'activ' => 'eroare',
            'continut' => $html,
            'ip24' => Ip::trunchiaza($c->ip),
            'raspuns' => $raspuns,
        ]));
    }

    /** Paginile gazdei (libere, ghid, întrebări frecvente), publicate din panou. */
    public static function pagina(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        foreach ($app->baza()->toate('SELECT * FROM {pagini} WHERE publicata = 1') as $r) {
            $slug = json_decode((string) $r['slug'], true) ?: [];
            if (($slug[$limba] ?? null) !== $p['slug']) {
                continue;
            }
            $titlu = (string) ((json_decode((string) $r['titlu'], true) ?: [])[$limba] ?? '');
            $alternative = [];
            foreach ($app->config()->limbi() as $l) {
                if (is_string($slug[$l] ?? null)) {
                    $alternative[$l] = $app->url()->absolut(Cai::cale('pagina', $l, ['slug' => $slug[$l]]), $l);
                }
            }
            $continut = (string) ((json_decode((string) $r['continut'], true) ?: [])[$limba] ?? '');
            // M6b: textul gazdei (text simplu, escapat) în conținutul nucleului, cu titlul și descrierea SEO ale paginii;
            // corpul ajunge și la cazo.continut() și la pagina.corp (Randare), ca pe main.
            $seo = json_decode((string) ($r['seo'] ?? ''), true) ?: [];
            $html = self::nucleu($app, $limba, 'pagina-gazda', ['titlu' => $titlu, 'corp' => \Cazo\Continut\TextSimplu::html($continut, (string) $r['tip'])]);
            $titluSeo = (string) ($seo['titlu'][$limba] ?? '');
            $descriere = (string) ($seo['descriere'][$limba] ?? '');
            return self::randeaza($app, $c, $limba, [
                'pagina' => 'pagina',
                'sablon' => 'pagina',
                'titlu' => $titluSeo !== '' ? $titluSeo : $titlu . ' | ' . $app->modelVedere($limba)->proprietate()['nume'],
                'descriere' => $descriere !== '' ? $descriere : \Cazo\Teme\Functii::scurtat(trim((string) preg_replace('~\s+~', ' ', $continut)), 160),
                'model' => ['corp' => $continut],
                'continut' => $html,
            ], 'pagina', ['slug' => $p['slug']], $alternative, 'pagina:' . $p['slug']);
        }
        return Erori::pagina($app->traducator($limba), 404, $app->url()->cale('/', $limba));
    }

    public static function dateLegale(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return self::legal($app, $c, $limba, 'date_legale');
    }

    public static function termeni(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return self::legal($app, $c, $limba, 'termeni');
    }

    public static function confidentialitate(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return self::legal($app, $c, $limba, 'confidentialitate');
    }

    /** Adresa veche a paginii de cookie-uri: redirecționare permanentă spre secțiunea din confidențialitate. */
    public static function cookie(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return Raspuns::redirectare(Cai::legala($app->url(), 'cookie', $limba), 301);
    }

    public static function accesibilitate(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        // pagina e ascunsă implicit; o pornește gazda (sau instalatorul, pentru firmele peste pragul microîntreprinderii).
        if (!\Cazo\Legal\BlocLegal::accesibilitate($app)) {
            return Erori::pagina($app->traducator($limba), 404, $app->url()->cale('/', $limba));
        }
        return self::legal($app, $c, $limba, 'accesibilitate');
    }

    private static function legal(Aplicatie $app, Cerere $c, string $limba, string $pagina): Raspuns
    {
        $t = $app->traducator($limba);
        $d = (new PaginiLegale($app, $t, $limba))->pagina($pagina);
        $html = self::nucleu($app, $limba, 'legal-pagina', $d);
        return self::randeaza($app, $c, $limba, [
            'pagina' => 'legal',
            'sablon' => null,
            'titlu' => self::titluCu($d['titlu'], $app->modelVedere($limba)->proprietate()['nume']),
            'descriere' => $d['intro'] !== [] ? \Cazo\Teme\Functii::scurtatText(implode(' ', $d['intro']), 160) : '',
            'continut' => $html,
        ], $pagina, [], null, $pagina);
    }

    /**
     * Căutarea pe toate camerele: camerele libere pe toate nopțile pentru oaspeții ceruți, cu totalul pe sejur
     * pe fiecare plan vizibil și politica de anulare. Fără date în adresă, doar formularul.
     */
    public static function disponibil(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $t = $app->traducator($limba);
        $q = Sejur::cautare($c->query);
        $m = $app->modelVedere($limba);
        $componente = new Componente($app, $m, $t, $limba, null, Ip::trunchiaza($c->ip));
        $date = ['formular' => $componente->formular(Cai::cale('disponibil', $limba)), 'cerere' => $q, 'rezultate' => [], 'ocupate' => [],
            'adulti_optiuni' => $componente->optiuniOaspeti()['adulti'], 'copii_optiuni' => $componente->optiuniOaspeti()['copii'], 'cautat' => $c->query !== [], 'vecine' => [],
            'activa' => $app->ecLicenta()->stare()['valida'], 'oprite' => $app->rezervariOprite(), 'contact' => $m->proprietate()['contact']];
        // Peste durata maximă de pe site: mesajul cu contactul, fără căutare.
        $date['prea_lung'] = $q !== null ? $componente->mesajPreaLung($q['sosire'], $q['plecare']) : null;
        if ($q !== null && $date['activa'] && $date['prea_lung'] === null) {
            $date = array_replace($date, self::cauta($app, $componente, $m->camere(), $q, $limba, Ip::trunchiaza($c->ip)));
        }
        $html = self::nucleu($app, $limba, 'cautare', $date);
        return self::randeaza($app, $c, $limba, [
            'pagina' => 'cautare',
            'sablon' => null,
            'titlu' => $t->t('cautare.titlu') . ' | ' . $m->proprietate()['nume'],
            'continut' => $html,
            'noindex' => true,
        ], 'disponibil', [], null, 'disponibil');
    }

    /** @return array{rezultate:list<array>, ocupate:list<array>, vecine:list<array>, rezumat:string} */
    private static function cauta(Aplicatie $app, Componente $comp, array $camere, array $q, string $limba, string $ip24): array
    {
        $t = $app->traducator($limba);
        $pn = $app->pretNoapte();
        $oaspeti = $q['adulti'] + $q['copii'];
        $sejururi = [];
        $carduri = [];
        $ocupate = [];
        foreach ($camere as $cam) {
            if ($cam['oaspeti_max'] !== null && $oaspeti > $cam['oaspeti_max']) {
                continue;
            }
            if ($pn->noptiPline([$cam['id']], $q['sosire'], $q['plecare']) !== []) {
                $ocupate[] = ['titlu' => $cam['titlu'], 'url' => $cam['url']];
                continue;
            }
            $carduri[$cam['id']] = ['camera' => $cam, 'planuri' => [], 'poza' => self::pozaMica($app, $cam['poza_principala'] ?? null)];
            // Pe fiecare cameră, doar cel mai bun tarif aplicabil pe fiecare politică de anulare și masă; când
            // niciun tarif nu se aplică datelor, tariful afișat al camerei, cu mesajul restricției lui.
            $faraPlan = Sejur::din($q['sosire'], $q['plecare'], [['camera' => $cam['id'], 'adulti' => $q['adulti'], 'copii' => $q['copii'], 'plan' => null]]);
            $planuri = array_column($app->oferte()->alese($faraPlan), 'plan');
            if ($planuri === [] && ($implicit = $pn->planImplicit($cam['id'])) !== null) {
                $planuri = [$implicit];
            }
            foreach ($planuri as $plan) {
                if (count($sejururi) >= 16) {
                    break;
                }
                $sejururi[$cam['id'] . '|' . $plan] = $faraPlan->cuPlan($plan);
            }
        }
        // tariful afișat al camerei, rămas singur și nerambursabil fără nicio metodă plătită la rezervare, apare
        // fără preț, cu contactul gazdei.
        $ascunse = array_filter($sejururi, static fn (Sejur $x): bool => $app->oferte()->ascuns($x->planCamera(0), $x->sosire));
        $deCalculat = array_diff_key($sejururi, $ascunse);
        $totaluri = $deCalculat === [] ? [] : $app->total()->calculeaza($deCalculat, $ip24);
        $toatePlanurile = $pn->planuri();
        foreach ($sejururi as $k => $s) {
            [$cam, $plan] = explode('|', $k);
            $r = $totaluri[$k] ?? ['stare' => 'ascuns'];
            $nume = Componente::numePlan($toatePlanurile[$plan], $limba, $app);
            $linie = [
                'plan' => $plan,
                'titlu' => $nume['titlu'],
                'nota' => $nume['nota'],
                'masa' => Componente::masaPlan($toatePlanurile[$plan], $limba, $app->traducator($limba)),
                // Regula care i se aplică oaspetelui, aceeași ca pe pagina de rezervare.
                'politica' => $comp->politicaAnulare($toatePlanurile[$plan], $comp->metodePlan($toatePlanurile[$plan], $s->sosire)),
                'url' => self::cuQuery($carduri[$cam]['camera']['url'], $s->query()),
                'stare' => $r['stare'],
            ];
            if ($r['stare'] === 'ok') {
                $linie['total_text'] = \Cazo\I18n\Format::baniOaspete($r['total'], $limba);
                $linie['rezerva'] = $app->rezervariActive() ? $app->url()->cale('/rezerva', $limba, $s->query()) : null;
            } elseif ($r['stare'] === 'restrictie') {
                $linie['mesaj'] = $comp->mesajRestrictie($r['restrictie']);
            } elseif ($r['stare'] === 'peste_buget') {
                $linie['mesaj'] = $t->t('rezumat.vedeti_totalul');
            } elseif ($r['stare'] === 'ascuns') {
                $linie['mesaj'] = $t->t('rezumat.contact_gazda');
            } else {
                $linie['mesaj'] = $t->t('rezumat.indisponibil');
            }
            $carduri[$cam]['planuri'][] = $linie;
        }
        // „Doar cazare” lângă tarifele fără masă, când alt tarif din rezultate are masa inclusă.
        $linii = Componente::cuDoarCazare(array_merge(...array_values(array_map(static fn (array $c): array => $c['planuri'], $carduri))), 'masa', $t);
        foreach ($carduri as $cam => $card) {
            $carduri[$cam]['planuri'] = array_splice($linii, 0, count($card['planuri']));
        }
        // Un card rămâne doar cu cel puțin un plan vânzabil (o restricție pe toate planurile îl trece la sfârșit).
        $rezultate = [];
        $cuRestrictii = [];
        foreach ($carduri as $card) {
            $vandabil = array_filter($card['planuri'], static fn (array $x): bool => $x['stare'] !== 'restrictie');
            if ($vandabil !== [] || $card['planuri'] === []) {
                $rezultate[] = $card;
            } else {
                $cuRestrictii[] = $card;
            }
        }
        $n = Sejur::nopti($q['sosire'], $q['plecare']);
        $vecine = [];
        if ($rezultate === []) {
            foreach ([-7, -1, 1, 7] as $d) {
                $s = gmdate('Y-m-d', strtotime($q['sosire'] . ' 00:00:00 UTC') + $d * 86400);
                if ($s < $app->azi()) {
                    continue;
                }
                $pl = gmdate('Y-m-d', strtotime($s . ' 00:00:00 UTC') + $n * 86400);
                $vecine[] = ['text' => \Cazo\I18n\Format::zi($s, $limba) . ' - ' . \Cazo\I18n\Format::zi($pl, $limba),
                    'url' => $app->url()->cale(Cai::cale('disponibil', $limba), $limba, ['sosire' => $s, 'plecare' => $pl, 'adulti' => (string) $q['adulti'], 'copii' => (string) $q['copii']])];
            }
        }
        // Datele în forma limbii paginii: „20 - 23 decembrie 2026 · 3 nopți · 2 oaspeți”.
        $rezumat = $t->t('cautare.rezumat', [
            'interval' => \Cazo\I18n\Format::interval($q['sosire'], $q['plecare'], $limba),
            'nopti' => $t->plural('unitate.nopti', $n),
            'oaspeti' => $t->plural('unitate.oaspeti', $oaspeti),
        ]);
        $toate = array_merge($rezultate, $cuRestrictii);
        return ['rezultate' => $toate, 'ocupate' => $ocupate, 'vecine' => $vecine, 'rezumat' => $rezumat,
            // Lângă total: „3 nopți, 2 oaspeți”. Butonul spune „Alegeți” doar când sunt mai multe tarife sau camere de ales.
            'sejur_text' => $t->t('cautare.sejur', ['nopti' => $t->plural('unitate.nopti', $n), 'oaspeti' => $t->plural('unitate.oaspeti', $oaspeti)]),
            'de_ales' => array_sum(array_map(static fn (array $x): int => count($x['planuri']), $toate)) > 1];
    }

    /**
     * Poza mică a unității pe cardul căutării: varianta JPEG cea mai îngustă de cel puțin 320 px (sau cea
     * mai lată care există), dintre derivatele ținute de site; null cât poza încă se prelucrează.
     * @return array{src:string, latime:int, inaltime:int}|null
     */
    private static function pozaMica(Aplicatie $app, ?array $poza): ?array
    {
        $d = is_array($poza) && is_string($poza['sha'] ?? null) ? $app->ecPoze()->pentruTema($poza['sha']) : null;
        $jpg = is_array($d) ? ($d['surse']['jpg'] ?? reset($d['surse'])) : null;
        if (!is_array($jpg) || $jpg === []) {
            return null;
        }
        ksort($jpg);
        $src = end($jpg);
        foreach ($jpg as $l => $u) {
            if ($l >= 320) {
                $src = $u;
                break;
            }
        }
        return ['src' => $src, 'latime' => 4, 'inaltime' => 3];
    }

    private static function cuQuery(string $url, array $q): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * O pagină de rezervare (comanda, starea, „Rezervarea mea”), în layoutul temei: conținutul nucleului în
     * {% block continut %}, fără JS de temă, cu profilul de antete „rezervare” al rutei (no-store, Trusted Types,
     * doar scripturile nucleului), noindex, în afara cache-ului de pagini. $refresh: secundele reîncărcării fără JS,
     * sau {secunde, url} pentru reîncărcarea spre altă adresă (starea, cu numărătoarea reîncărcărilor).
     * $cookies și $antete intră în răspuns tot aici, ca și răspunsul de după o eroare fatală a temei să le aibă.
     * @param array<string,string> $alternative
     * @param list<string> $cookies valorile Set-Cookie
     * @param array<string,string> $antete
     */
    public static function paginaRezervare(Aplicatie $app, Cerere $c, string $limba, string $titlu, string $continut, array $alternative, int|array|null $refresh = null, int $status = 200,
        array $cookies = [], array $antete = []): Raspuns
    {
        $alternative['x-default'] = $alternative[$app->config()->limbaImplicita()] ?? reset($alternative);
        // Răspunsul se construiește aici și în Plasa (după o eroare fatală a temei): aceeași stare, CSP, cookie-uri, antete.
        $raspuns = static function (array $r) use ($app, $status, $cookies, $antete): Raspuns {
            $raspuns = Raspuns::html($r['html'], $status);
            $raspuns->deTinut = false;
            if ($r['harta']) {
                // „Cum ajungeți”: plăcile hărții, după clic, din serverul ales în panou.
                $raspuns->antet('Content-Security-Policy', Csp::pentru('rezervare', $app->config()->origine(),
                    ['placi_harta' => (string) $app->setari()->get('harta.placi', 'https://tile.openstreetmap.org')]));
            }
            foreach ($antete as $n => $v) {
                $raspuns->antet($n, $v);
            }
            foreach ($cookies as $k) {
                $raspuns->cookie($k);
            }
            return $raspuns;
        };
        return $raspuns($app->randare()->pagina([
            'pagina' => 'rezervare',
            'sablon' => null,
            'limba' => $limba,
            'titlu' => $titlu,
            'canonic' => $alternative[$limba] ?? $app->url()->absolut('/', $limba),
            'alternative' => $alternative,
            'noindex' => true,
            'activ' => 'rezervare',
            'continut' => $continut,
            'rezervare' => true,
            'refresh' => $refresh,
            'ip24' => Ip::trunchiaza($c->ip),
            'raspuns' => $raspuns,
        ]));
    }

    /**
     * Titlul și descrierea alese de gazdă în panou (SEO și limbi, M6b) pentru pagina de început și lista camerelor;
     * gol = cele calculate.
     */
    private static function seo(Aplicatie $app, string $pagina, string $limba, array $p): array
    {
        $s = (array) (((array) ($app->setari()->get('seo.pagini') ?? []))[$pagina] ?? []);
        foreach (['titlu', 'descriere'] as $k) {
            $v = $s[$k][$limba] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $p[$k] = trim($v);
            }
        }
        return $p;
    }

    /** O componentă de conținut a nucleului (vederi/componente/<nume>.twig), în limba paginii. */
    public static function nucleu(Aplicatie $app, string $limba, string $nume, array $date): string
    {
        $dir = $app->privat() . '/cache/twig/nucleu-' . preg_replace('~[^A-Za-z0-9.\-]~', '_', Aplicatie::versiune());
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $env = \Cazo\Teme\MediuNucleu::creeaza(Aplicatie::radacina() . '/vederi', is_dir($dir) ? $dir : null, $app->traducator($limba));
        return $env->render('componente/' . $nume . '.twig', $date);
    }

    /**
     * Randează pagina și construiește răspunsul: canonical, alternativele hreflang, noindex, CSP-ul cu serverul
     * de plăci pe paginile cu harta, marcajul pentru cache-ul de pagini.
     * @param array<string,string>|null $alternative
     */
    private static function randeaza(Aplicatie $app, Cerere $c, string $limba, array $p, string $ruta, array $param = [], ?array $alternative = null, ?string $activ = null): Raspuns
    {
        if ($alternative === null) {
            $alternative = [];
            foreach ($app->config()->limbi() as $l) {
                $alternative[$l] = $app->url()->absolut(Cai::cale($ruta, $l, $param), $l);
            }
        }
        $alternative['x-default'] = $alternative[$app->config()->limbaImplicita()] ?? reset($alternative);
        $noindex = ($p['noindex'] ?? false) || $app->noindex() || $c->query !== [];
        $pregatire = Publicare::bandaPentru($app, $c);
        $dateLipsa = $pregatire === null ? Publicare::alertaPentru($app, $c) : null;
        // Răspunsul se construiește aici și în Plasa (după o eroare fatală a temei), cu aceleași antete.
        $raspuns = static function (array $r) use ($app, $c, $p, $pregatire, $dateLipsa): Raspuns {
            $raspuns = Raspuns::html($r['html']);
            $optiuni = ['tema_cu_js' => $app->temaActiva()->areJs()];
            if ($r['harta']) {
                $optiuni['placi_harta'] = (string) $app->setari()->get('harta.placi', 'https://tile.openstreetmap.org');
            }
            $raspuns->antet('Content-Security-Policy', Csp::pentru('public', $app->config()->origine(), $optiuni));
            // O pagină care arată încă imaginea-substitut a unei poze în lucru rămâne în afara cache-ului.
            $raspuns->deTinut = $c->query === [] && ($p['pagina'] !== 'cautare') && $pregatire === null && $dateLipsa === null
                && !isset($r['folosite']['substitut']);
            // Căutarea și paginile cu un sejur în adresă arată prețul, tariful și politica de anulare de acum:
            // rămân în afara oricărui cache (browser, LiteSpeed, proxy). Cu antetul public al profilului (60 s, apoi 5 minute
            // „stale-while-revalidate”), o pagină păstrată arăta tariful de dinaintea unei schimbări făcute în EasyCheck,
            // iar pagina de rezervare (mereu no-store), pe cel nou.
            if ($p['pagina'] === 'cautare' || isset($c->query['sosire'])) {
                $raspuns->antet('Cache-Control', 'no-store');
                $raspuns->antet('X-LiteSpeed-Cache-Control', 'no-cache');
            }
            return $raspuns;
        };
        return $raspuns($app->randare()->pagina($p + [
            'limba' => $limba,
            'canonic' => $alternative[$limba] ?? $app->url()->absolut('/', $limba),
            'alternative' => $alternative,
            'noindex' => $noindex,
            'activ' => $activ ?? $p['pagina'],
            'ip24' => Ip::trunchiaza($c->ip),
            'pregatire' => $pregatire,
            'date_lipsa' => $dateLipsa,
            'raspuns' => $raspuns,
        ]));
    }
}
