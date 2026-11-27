<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\Continut\Poze;
use Cazo\Continut\TextSimplu;
use Cazo\I18n\Format;
use Cazo\Legal\Editabile;
use Cazo\Legal\PaginiLegale;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cai;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;

defined('CAZO') or exit;

/**
 * Conținutul și setările avansate (M6b):
 *
 *   GET  /admin/poze                          pozele: din EasyCheck și încărcate, cu categoria, indicele, principala
 *   POST /admin/poze/incarca                  încărcarea (multipart), cu validarea și derivatele
 *   POST /admin/poze/{sha}/{actiune}          seteaza | sterge
 *   GET  /admin/pagini                        paginile, întrebările frecvente și meniul (ordinea, publicarea), RO și EN
 *   GET  /admin/pagini/{id|noua}              editarea unei pagini
 *   POST /admin/pagini/{id|noua}              salvarea; POST /admin/pagini/{id}/sterge
 *   GET  /admin/seo                           titlurile și descrierile, redirectările, completitudinea traducerilor
 *   POST /admin/seo/{actiune}                 titluri | redirectare | sterge-redirectare
 *   GET  /admin/emailuri/sabloane             zona editabilă a e-mailurilor către oaspeți, cu previzualizarea
 *   POST /admin/emailuri/sabloane             salvarea zonei
 *   GET  /admin/legal/texte                   paginile legale și subsolul, editabile integral, cu diferențele față de model
 *   POST /admin/legal/texte                   o versiune nouă sau revenirea la model (cele vechi rămân; acordurile țin versiunea acceptată)
 *
 * Pozele și paginile le editează și personalul (14: Conținut); SEO, șabloanele e-mailurilor și textele legale sunt ale
 * proprietarului. Textele legale cer re-autentificarea.
 */
final class AdminContinut
{
    public const MESAJE = ['salvat' => 'admin.salvat', 'incarcata' => 'admin.poze.incarcata', 'stearsa' => 'admin.poze.stearsa', 'pagina_stearsa' => 'admin.pagini.stearsa',
        'versiune' => 'admin.texte_legale.versiune_noua', 'model' => 'admin.texte_legale.revenit'];
    public const ERORI = ['marime' => 'admin.poze.eroare_marime', 'tip' => 'admin.poze.eroare_tip', 'limita' => 'admin.poze.eroare_limita', 'exista' => 'admin.poze.eroare_exista',
        'fisier' => 'admin.poze.eroare_fisier', 'poza' => 'admin.poze.eroare_lipsa', 'titlu' => 'admin.pagini.eroare_titlu', 'slug' => 'admin.pagini.eroare_slug',
        'redirectare' => 'admin.seo.eroare_redirectare', 'reauth' => 'admin.reauth.eroare', 'sablon' => 'admin.sabloane.eroare', 'sablon_ec' => 'admin.sabloane.in_easycheck'];

    /**
     * Șabloanele către oaspeți cu zona editabilă a gazdei: toate cele 32 de mesaje ale catalogului
     * (`SabloaneGazda::CATALOG`, fără codul de 6 cifre), nu doar confirmările, refuzurile și anulările.
     */
    public const SABLOANE = ['cerere_primita', 'cerere_acceptata', 'cerere_refuzata', 'cerere_expirata', 'cerere_netrimisa', 'cerere_indisponibila', 'cerere_respinsa', 'cerere_intarziata',
        'plata_confirmata', 'plata_in_procesare', 'plata_esuata', 'plata_rambursata', 'plata_verificare', 'plata_inchisa',
        'transfer_asteptat', 'transfer_reamintire', 'transfer_prelungit', 'transfer_neprimit', 'transfer_expirat', 'transfer_anulat', 'transfer_anulat_gazda',
        'anulare_oaspete', 'anulare_gazda', 'anulare_ceruta', 'anulare_pastrata', 'rambursare_gazda', 'restituire_transfer', 'anulare_renuntata', 'anulare_renuntata_gratuita',
        'rezervare_anulata', 'schimbare_facuta', 'schimbare_esuata'];

    public const NOTA_MAXIM = 600;

    // ---- Pozele ----

    /** Mesajele și erorile paginilor Poze, Pagini și „Google și limbi”, pe lângă cele comune de mai sus. */
    public const MESAJE_SITE = ['poza_salvata' => 'admin.poze.salvata', 'pagina_salvata' => 'admin.pagini.salvata', 'titluri' => 'admin.seo.titluri_salvate',
        'redirectare' => 'admin.seo.redirectare_adaugata', 'redirectare_stearsa' => 'admin.seo.redirectare_stearsa'];

    /** Erorile încărcării unei poze, arătate lângă câmpul fișierului. */
    public const ERORI_FISIER = ['marime', 'tip', 'limita', 'exista', 'fisier'];

    public static function poze(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $t = $app->traducator((string) $u['limba_panou'], 'admin');
        $tsite = $app->traducator((string) $u['limba_panou']);
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'poze', self::MESAJE + self::MESAJE_SITE, self::ERORI);
        $ec = (array) ($app->ecOglinda()->corp('proprietate')['poze'] ?? []);
        // categoria pozelor din EasyCheck vine din EasyCheck; a celor încărcate, din panou.
        $categorieEc = [];
        foreach ($ec as $x) {
            if (is_array($x) && is_string($x['sha256'] ?? null)) {
                $k = $x['categorie']['cheie'] ?? $x['categorie'] ?? null;
                $categorieEc[$x['sha256']] = is_string($k) && in_array($k, Poze::CATEGORII, true) ? $k : null;
            }
        }
        $lista = [];
        foreach (self::continutPoze($app)->pentruPanou($ec) as $i => $x) {
            $tema = $app->ecPoze()->pentruTema($x['sha256']);
            $cat = $x['incarcata'] ? (is_string($x['categorie']) && $x['categorie'] !== '' ? $x['categorie'] : null) : ($categorieEc[$x['sha256']] ?? null);
            // Titlul și textul alternativ: categoria („Baie”, „Dormitorul 2”), ca legenda de pe site; fără categorie, numărul pozei.
            $titlu = match (true) {
                $cat === null => $t->t('admin.poze.poza_n', ['n' => (string) ($i + 1)]),
                $x['incarcata'] && $x['indice'] !== null && in_array($cat, Poze::CU_INDICE, true) => $tsite->t('poza.categorie_n.' . $cat, ['n' => (string) $x['indice']]),
                default => $tsite->t('poza.categorie.' . $cat),
            };
            $lista[] = $x + ['n' => $i + 1, 'titlu' => $titlu,
                'miniatura' => $tema !== null && !$x['fisiere_lipsa'] ? ($tema['surse']['jpg'][480] ?? reset($tema['surse'])[480] ?? null) : null];
        }
        $categorii = [];
        foreach (Poze::CATEGORII as $k) {
            $categorii[] = ['cheie' => $k, 'text' => $tsite->t('poza.categorie.' . $k)];
        }
        $limita = self::limitaIncarcare();
        $e = (string) ($c->param('eroare') ?? '');
        if ($e === 'marime') {
            $d['eroare'] = $t->t('admin.poze.eroare_marime', ['mb' => self::mb($limita)]);
        }
        $d += ['poze' => $lista, 'in_lucru' => $app->ecPoze()->deFacutAcum(), 'categorii' => $categorii, 'maxim_mb' => self::mb($limita), 'limita_gazduire' => $limita < Poze::MARIME_MAXIMA,
            // Eroarea încărcării apare și lângă câmpul fișierului.
            'eroare_fisier' => in_array($e, self::ERORI_FISIER, true) ? $d['eroare'] : null,
            'url' => ['baza' => $app->url()->cale('/admin/poze')]];
        return AdminPanou::pagina($app, $u, 'poze', $d, $r);
    }

    public static function pozaIncarca(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        $inapoi = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/poze', $q);
        $f = $c->fisiere['poza'] ?? null;
        // Doar un fișier venit prin încărcarea HTTP (sau dat de teste prin Cerere, fără PHP-ul de server).
        if ($f === null || $f['eroare'] !== UPLOAD_ERR_OK || ($f['incarcat'] === false && PHP_SAPI !== 'cli')) {
            return $inapoi(['eroare' => $f !== null && in_array($f['eroare'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'marime' : 'fisier']);
        }
        if ($f['marime'] > self::limitaIncarcare()) {
            return $inapoi(['eroare' => 'marime']);
        }
        $octeti = (string) @file_get_contents($f['cale'], false, null, 0, Poze::MARIME_MAXIMA + 1);
        try {
            $sha = self::continutPoze($app)->incarca($octeti, (string) ($c->camp('categorie') ?? ''));
        } catch (\UnexpectedValueException $e) {
            $app->auditDetalii(['eroare_poza' => $e->getMessage()]);
            return $inapoi(['eroare' => isset(self::ERORI[$e->getMessage()]) ? $e->getMessage() : 'tip']);
        }
        $app->auditDetalii(['poza' => substr($sha, 0, 16)]);
        $app->ecOglinda()->schimbaContinutul();
        return $inapoi(['mesaj' => 'incarcata']);
    }

    public static function pozaActiune(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        $inapoi = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/poze', $q);
        $sha = (string) $p['sha'];
        $poze = self::continutPoze($app);
        if ($p['actiune'] === 'sterge') {
            if (!$poze->sterge($sha)) {
                return $inapoi(['eroare' => 'poza']);
            }
            $app->auditDetalii(['poza_stearsa' => substr($sha, 0, 16)]);
            $app->ecOglinda()->schimbaContinutul();
            return $inapoi(['mesaj' => 'stearsa']);
        }
        $indice = trim((string) ($c->camp('indice') ?? ''));
        // O singură poză principală: Poze::seteaza o scoate pe cea veche când gazda alege alta.
        if (!$poze->seteaza($sha, (string) ($c->camp('categorie') ?? ''), $indice !== '' ? (int) $indice : null, $c->camp('principala') === '1', (int) ($c->camp('ordine') ?? 0),
            $c->camp('ascunsa') === '1')) {
            return $inapoi(['eroare' => 'poza']);
        }
        $app->ecOglinda()->schimbaContinutul();
        return $inapoi(['mesaj' => 'poza_salvata']);
    }

    /**
     * Limita reală a unei încărcări: cea mai mică dintre limita pozelor (15 MB) și limitele PHP ale găzduirii
     * (`upload_max_filesize`, `post_max_size`; pe cPanel, des 2-8 MB).
     */
    public static function limitaIncarcare(): int
    {
        $l = [Poze::MARIME_MAXIMA];
        foreach (['upload_max_filesize', 'post_max_size'] as $k) {
            $v = self::octeti((string) ini_get($k));
            if ($v > 0) {
                $l[] = $v;
            }
        }
        return min($l);
    }

    /** O valoare din php.ini („8M”, „2G”, „512K”, „0”), în octeți; 0 = fără limită. */
    public static function octeti(string $v): int
    {
        $v = trim($v);
        if (!preg_match('~^(\d+)\s*([kmg]?)~i', $v, $m)) {
            return 0;
        }
        return (int) $m[1] * match (strtolower($m[2])) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
    }

    private static function mb(int $octeti): string
    {
        return rtrim(rtrim(number_format($octeti / 1048576, 1, ',', ''), '0'), ',');
    }

    private static function continutPoze(Aplicatie $app): Poze
    {
        return new Poze($app->baza(), $app->ceas(), $app->config()->calePublic());
    }

    // ---- Paginile, FAQ și meniul ----

    public static function pagini(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'pagini', self::MESAJE + self::MESAJE_SITE, self::ERORI);
        $lista = [];
        foreach ($app->baza()->toate('SELECT * FROM {pagini} ORDER BY ordine, id') as $x) {
            $titlu = json_decode((string) $x['titlu'], true) ?: [];
            $slug = json_decode((string) $x['slug'], true) ?: [];
            $areSlug = is_string($slug['ro'] ?? null) && $slug['ro'] !== '';
            $lista[] = ['id' => (int) $x['id'], 'tip' => (string) $x['tip'], 'titlu' => (string) ($titlu['ro'] ?? ''), 'titlu_en' => (string) ($titlu['en'] ?? ''),
                'publicata' => (int) $x['publicata'] === 1, 'ordine' => (int) $x['ordine'],
                // Adresa întreagă a paginii; linkul spre ea, doar cât e publicată.
                'adresa' => $areSlug ? self::adresaPagina($app, 'ro', $slug['ro']) : null,
                'url' => (int) $x['publicata'] === 1 && $areSlug ? $app->url()->cale(Cai::cale('pagina', 'ro', ['slug' => $slug['ro']]), 'ro') : null];
        }
        $d += ['pagini' => $lista, 'url' => ['baza' => $app->url()->cale('/admin/pagini')]];
        return AdminPanou::pagina($app, $u, 'pagini', $d, $r);
    }

    /** Adresa întreagă a unei pagini a gazdei, pe limbă (cu domeniul site-ului). */
    private static function adresaPagina(Aplicatie $app, string $l, string $slug): string
    {
        return $app->url()->absolut(Cai::cale('pagina', $l, ['slug' => $slug]), $l);
    }

    public static function pagina(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $id = $p['id'] === 'noua' ? null : (int) $p['id'];
        $x = $id !== null ? $app->baza()->rand('SELECT * FROM {pagini} WHERE id = ?', [$id]) : null;
        if ($id !== null && $x === null) {
            return AdminPanou::inapoi($app, '/admin/pagini');
        }
        if ($c->metoda === 'POST') {
            return self::paginaSalveaza($app, $c, $id, $x, $u, $jeton);
        }
        $j = static fn (string $k): array => $x !== null ? (json_decode((string) ($x[$k] ?? ''), true) ?: []) : [];
        $seo = $j('seo');
        return self::paginaFormular($app, $c, $u, $jeton, $id, $x, ['tip' => (string) ($x['tip'] ?? 'libera'), 'titlu' => $j('titlu'), 'slug' => $j('slug'), 'continut' => $j('continut'),
            'seo_titlu' => (array) ($seo['titlu'] ?? []), 'seo_descriere' => (array) ($seo['descriere'] ?? []), 'publicata' => (int) ($x['publicata'] ?? 0) === 1,
            'ordine' => (string) (int) ($x['ordine'] ?? 0)], []);
    }

    /**
     * Formularul unei pagini: cu valorile din bază sau, după o greșeală, cu cele scrise de gazdă și cu eroarea lângă câmp (422).
     * @param array<string,mixed> $v valorile câmpurilor
     * @param array<string,string> $erori numele câmpului => cheia textului erorii
     */
    private static function paginaFormular(Aplicatie $app, Cerere $c, array $u, string $jeton, ?int $id, ?array $x, array $v, array $erori): Raspuns
    {
        $t = $app->traducator((string) $u['limba_panou'], 'admin');
        $r = Raspuns::html('', $erori === [] ? 200 : 422);
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'pagini', self::MESAJE + self::MESAJE_SITE, self::ERORI);
        $salvate = $x !== null ? (json_decode((string) ($x['slug'] ?? ''), true) ?: []) : [];
        $adrese = [];
        foreach (['ro', 'en'] as $l) {
            // Adresa întreagă: cea salvată sau, cât pagina e nouă, un exemplu.
            $are = is_string($salvate[$l] ?? null) && $salvate[$l] !== '';
            $adrese[$l] = $t->t($are ? 'admin.pagini.adresa_intreaga' : 'admin.pagini.adresa_exemplu',
                ['adresa' => self::adresaPagina($app, $l, $are ? $salvate[$l] : ($l === 'ro' ? 'despre-noi' : 'about-us'))]);
        }
        $texte = [];
        foreach ($erori as $camp => $cheie) {
            $texte[$camp] = $t->t($cheie);
        }
        if ($texte !== []) {
            $d['eroare'] = $t->t('admin.pagini.eroare_formular');
        }
        $d += $v + ['pagina_id' => $id, 'adrese' => $adrese, 'erori' => $texte, 'titlu_salvat' => (string) (($x !== null ? (json_decode((string) $x['titlu'], true) ?: []) : [])['ro'] ?? ''),
            'url' => ['baza' => $app->url()->cale('/admin/pagini/' . ($id ?? 'noua')), 'lista' => $app->url()->cale('/admin/pagini')]];
        return AdminPanou::pagina($app, $u, 'pagina-editare', $d, $r);
    }

    private static function paginaSalveaza(Aplicatie $app, Cerere $c, ?int $id, ?array $x, array $u, string $jeton): Raspuns
    {
        $text = static fn (string $k, int $max): string => trim(mb_substr(str_replace("\r", '', (string) ($c->camp($k) ?? '')), 0, $max));
        $tip = in_array($c->camp('tip'), ['libera', 'faq'], true) ? (string) $c->camp('tip') : 'libera';
        // Ce a scris gazda, pentru formularul întors după o greșeală.
        $scris = ['tip' => $tip, 'titlu' => ['ro' => $text('titlu_ro', 120), 'en' => $text('titlu_en', 120)], 'slug' => ['ro' => $text('slug_ro', 80), 'en' => $text('slug_en', 80)],
            'continut' => ['ro' => $text('continut_ro', TextSimplu::MAXIM), 'en' => $text('continut_en', TextSimplu::MAXIM)],
            'seo_titlu' => ['ro' => $text('seo_titlu_ro', 70), 'en' => $text('seo_titlu_en', 70)],
            'seo_descriere' => ['ro' => $text('seo_descriere_ro', 160), 'en' => $text('seo_descriere_en', 160)],
            'publicata' => $c->camp('publicata') === '1', 'ordine' => trim((string) ($c->camp('ordine') ?? '0'))];
        $titlu = array_filter($scris['titlu'], 'strlen');
        $erori = [];
        if (!isset($titlu['ro'])) {
            $erori['titlu_ro'] = 'admin.pagini.eroare_titlu';
        }
        $slug = [];
        foreach (['ro', 'en'] as $l) {
            $s = $scris['slug'][$l] !== '' ? $scris['slug'][$l] : (isset($titlu[$l]) ? Cai::slug($titlu[$l]) : '');
            if ($s === '') {
                continue;
            }
            if (!preg_match('~^' . Cai::SLUG . '$~', $s)) {
                $erori['slug_' . $l] = 'admin.pagini.eroare_slug';
                continue;
            }
            // Slugul e unic pe limbă printre paginile gazdei.
            foreach ($app->baza()->toate('SELECT id, slug FROM {pagini}') as $alta) {
                if ((int) $alta['id'] !== (int) $id && ((json_decode((string) $alta['slug'], true) ?: [])[$l] ?? null) === $s) {
                    $erori['slug_' . $l] = 'admin.pagini.eroare_slug_folosit';
                    continue 2;
                }
            }
            $slug[$l] = $s;
        }
        if ($erori !== []) {
            return self::paginaFormular($app, $c, $u, $jeton, $id, $x, $scris, $erori);
        }
        $continut = array_filter($scris['continut'], 'strlen');
        $seo = ['titlu' => array_filter($scris['seo_titlu'], 'strlen'), 'descriere' => array_filter($scris['seo_descriere'], 'strlen')];
        $date = ['tip' => $tip, 'slug' => json_encode($slug, JSON_UNESCAPED_UNICODE), 'titlu' => json_encode($titlu, JSON_UNESCAPED_UNICODE),
            'continut' => json_encode($continut, JSON_UNESCAPED_UNICODE), 'seo' => json_encode($seo, JSON_UNESCAPED_UNICODE), 'publicata' => $scris['publicata'] ? 1 : 0,
            'ordine' => max(-999, min(999, (int) $scris['ordine']))];
        if ($id === null) {
            $id = $app->baza()->insereaza('pagini', $date);
        } else {
            // Adresa schimbată: redirectarea 301 de la cea veche, pe fiecare limbă (alegerea 161).
            $vechi = json_decode((string) ($x['slug'] ?? ''), true) ?: [];
            $red = new \Cazo\Continut\Redirectari($app->baza(), $app->ceas());
            foreach (['ro', 'en'] as $l) {
                if (is_string($vechi[$l] ?? null) && isset($slug[$l]) && $vechi[$l] !== $slug[$l] && preg_match('~^' . Cai::SLUG . '$~', $vechi[$l])) {
                    $prefix = $l === $app->config()->limbaImplicita() ? '' : '/' . $l;
                    $red->mutare($prefix . Cai::cale('pagina', $l, ['slug' => $vechi[$l]]), $prefix . Cai::cale('pagina', $l, ['slug' => $slug[$l]]));
                }
            }
            $set = implode(', ', array_map(static fn (string $k): string => "$k = ?", array_keys($date)));
            $app->baza()->executa("UPDATE {pagini} SET $set WHERE id = ?", [...array_values($date), $id]);
        }
        $app->auditDetalii(['pagina' => $id, 'publicata' => $date['publicata'] === 1]);
        $app->ecOglinda()->schimbaContinutul();
        return AdminPanou::inapoi($app, '/admin/pagini/' . $id, ['mesaj' => 'pagina_salvata']);
    }

    public static function paginaSterge(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        $app->baza()->executa('DELETE FROM {pagini} WHERE id = ?', [(int) $p['id']]);
        $app->auditDetalii(['pagina_stearsa' => (int) $p['id']]);
        $app->ecOglinda()->schimbaContinutul();
        return AdminPanou::inapoi($app, '/admin/pagini', ['mesaj' => 'pagina_stearsa']);
    }

    // ---- Google și limbi (SEO) ----

    public static function seo(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        return self::seoPagina($app, $c, $s[0], $s[1], ['de' => '', 'la' => '', 'cod' => 301], null);
    }

    /**
     * Pagina „Cum apare site-ul în Google”: titlurile și descrierile, traducerile, adresele site-ului vechi.
     * @param array{de:string, la:string, cod:int} $formular ce a scris gazda la „Adresele site-ului vechi”
     * @param ?string $campGresit `de` sau `la`: câmpul cu eroarea (422), sau null
     */
    private static function seoPagina(Aplicatie $app, Cerere $c, array $u, string $jeton, array $formular, ?string $campGresit): Raspuns
    {
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('', $campGresit === null ? 200 : 422);
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'seo', self::MESAJE + self::MESAJE_SITE, self::ERORI);
        $redirectari = [];
        foreach ($app->baza()->toate('SELECT * FROM {redirectari} ORDER BY de') as $x) {
            $redirectari[] = ['de' => (string) $x['de'], 'la' => (string) $x['la'], 'cod' => (int) $x['cod']];
        }
        if ($campGresit !== null) {
            $d['eroare'] = $t->t('admin.seo.eroare_redirectare');
        }
        $seo = (array) ($app->setari()->get('seo.pagini') ?? []);
        // Ce vede Google acum pe fiecare pagină și limbă: textul scris de gazdă sau, în lipsa lui, cel calculat de site.
        $acum = [];
        foreach (['acasa', 'camere'] as $pg) {
            foreach ($app->config()->limbi() as $lb) {
                $calc = Prezentare::seoCalculat($app, $pg, $lb);
                foreach (['titlu', 'descriere'] as $k) {
                    $scris = trim((string) ($seo[$pg][$k][$lb] ?? ''));
                    $acum[$pg][$lb][$k] = ['text' => $scris !== '' ? $scris : $calc[$k], 'scris' => $scris !== ''];
                }
            }
        }
        $d += ['seo' => $seo, 'seo_acum' => $acum, 'limbi' => $app->config()->limbi(), 'redirectari' => $redirectari, 'traduceri' => self::traduceri($app, $t),
            'formular' => $formular, 'camp_gresit' => $campGresit, 'url' => ['baza' => $app->url()->cale('/admin/seo/')]];
        return AdminPanou::pagina($app, $u, 'seo', $d, $r);
    }

    /**
     * Completitudinea traducerilor în engleză: camerele (titlul, descrierea), paginile (titlul, textul), titlurile SEO.
     * @return list<array{zona:string, total:int, traduse:int, lipsa:list<string>}>
     */
    public static function traduceri(Aplicatie $app, \Cazo\I18n\Traducator $t): array
    {
        $camere = ['zona' => $t->t('admin.seo.zona.camere'), 'total' => 0, 'traduse' => 0, 'lipsa' => []];
        $local = array_column($app->baza()->toate('SELECT camera, titlu, descriere FROM {camere_local}'), null, 'camera');
        foreach ((array) ($app->ecOglinda()->corp('camere')['camere'] ?? []) as $cam) {
            if (!is_array($cam) || !is_string($cam['id'] ?? null)) {
                continue;
            }
            $camere['total']++;
            $titlu = json_decode((string) ($local[$cam['id']]['titlu'] ?? ''), true) ?: [];
            if (($titlu['en'] ?? '') !== '') {
                $camere['traduse']++;
            } else {
                $camere['lipsa'][] = (string) ($titlu['ro'] ?? $cam['titlu'] ?? $t->t('admin.seo.camera_fara_nume'));
            }
        }
        $pagini = ['zona' => $t->t('admin.seo.zona.pagini'), 'total' => 0, 'traduse' => 0, 'lipsa' => []];
        foreach ($app->baza()->toate('SELECT titlu, continut FROM {pagini} WHERE publicata = 1') as $x) {
            $pagini['total']++;
            $titlu = json_decode((string) $x['titlu'], true) ?: [];
            $cont = json_decode((string) $x['continut'], true) ?: [];
            if (($titlu['en'] ?? '') !== '' && (($cont['ro'] ?? '') === '' || ($cont['en'] ?? '') !== '')) {
                $pagini['traduse']++;
            } else {
                $pagini['lipsa'][] = (string) ($titlu['ro'] ?? '');
            }
        }
        return [$camere, $pagini];
    }

    public static function seoActiune(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        $inapoi = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/seo', $q);
        $a = (string) $p['actiune'];
        if ($a === 'titluri') {
            $v = [];
            foreach (['acasa', 'camere'] as $pg) {
                foreach (['titlu' => 70, 'descriere' => 160] as $k => $max) {
                    foreach (['ro', 'en'] as $l) {
                        $x = trim(mb_substr((string) ($c->camp("{$pg}_{$k}_{$l}") ?? ''), 0, $max));
                        if ($x !== '') {
                            $v[$pg][$k][$l] = $x;
                        }
                    }
                }
            }
            $app->setari()->pune('seo.pagini', $v, (int) $s[0]['id']);
            $app->ecOglinda()->schimbaContinutul();
            return $inapoi(['mesaj' => 'titluri']);
        }
        if ($a === 'redirectare') {
            $scrisDe = trim((string) ($c->camp('de') ?? ''));
            $de = \Cazo\Continut\Redirectari::normalizeaza($scrisDe);
            $la = trim((string) ($c->camp('la') ?? ''));
            $cod = $c->camp('cod') === '302' ? 302 : 301;
            if (!(new \Cazo\Continut\Redirectari($app->baza(), $app->ceas()))->adauga($de, $la, $cod)) {
                // Formularul se întoarce cu ce a scris gazda și cu eroarea lângă câmpul greșit: adresa veche, când forma ei
                // e greșită; altfel, cea nouă (forma, aceeași adresă sau un lanț de adrese).
                $deBun = \Cazo\Continut\Redirectari::formaValida($de, '/');
                return self::seoPagina($app, $c, $s[0], $s[1], ['de' => $scrisDe, 'la' => $la, 'cod' => $cod], $deBun ? 'la' : 'de');
            }
            $app->auditDetalii(['de' => $de, 'cod' => $cod]);
            return $inapoi(['mesaj' => 'redirectare']);
        }
        if ($a === 'sterge-redirectare') {
            $app->baza()->executa('DELETE FROM {redirectari} WHERE de = ?', [(string) ($c->camp('de') ?? '')]);
            return $inapoi(['mesaj' => 'redirectare_stearsa']);
        }
        return $inapoi([]);
    }

    // ---- Mesajele către oaspeți: zona editabilă a e-mailurilor ----

    /** Mesajele paginii „Mesajele către oaspeți”: confirmarea spune ce s-a salvat. */
    public const MESAJE_SABLOANE = ['salvat' => 'admin.sabloane.salvat'] + self::MESAJE;

    /** Codul rezervării din previzualizarea cu date de exemplu: „L” lipsește din alfabetul codurilor adevărate (Comenzi::ALFABET). */
    public const REF_EXEMPLU = 'PG-EXEMPLU';

    public static function sabloane(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        // cu mesajele scrise în EasyCheck, pagina e doar previzualizare; paragraful nu se mai scrie aici.
        $ec = $app->mesajeEc()->activa();
        if ($c->metoda === 'POST' && $ec) {
            return AdminPanou::inapoi($app, '/admin/emailuri/sabloane', ['eroare' => 'sablon_ec']);
        }
        if ($c->metoda === 'POST') {
            $sablon = (string) ($c->camp('sablon') ?? '');
            $l = (string) ($c->camp('limba') ?? '');
            if (!in_array($sablon, self::SABLOANE, true) || !in_array($l, ['ro', 'en'], true)) {
                return AdminPanou::inapoi($app, '/admin/emailuri/sabloane', ['eroare' => 'sablon']);
            }
            $nota = trim(mb_substr(str_replace("\r", '', (string) ($c->camp('nota') ?? '')), 0, self::NOTA_MAXIM));
            $app->baza()->upsert('sabloane_email', ['cheie' => $sablon, 'limba' => $l, 'nota' => $nota === '' ? null : $nota, 'actualizat_la' => $app->ceas()->moment(),
                'actualizat_de' => (int) $u['id']], ['cheie', 'limba'], ['nota', 'actualizat_la', 'actualizat_de']);
            $app->auditDetalii(['sablon' => $sablon, 'limba' => $l]);
            return AdminPanou::inapoi($app, '/admin/emailuri/sabloane', ['mesaj' => 'salvat', 'sablon' => $sablon, 'limba' => $l]);
        }
        $t = $app->traducator((string) $u['limba_panou'], 'admin');
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'mesaje', self::MESAJE_SABLOANE, self::ERORI);
        $ales = in_array($c->param('sablon'), self::SABLOANE, true) ? (string) $c->param('sablon') : self::SABLOANE[0];
        $l = $c->param('limba') === 'en' ? 'en' : 'ro';
        $lista = [];
        foreach (self::SABLOANE as $x) {
            $lista[] = ['cheie' => $x, 'text' => $t->t('admin.sabloane.sablon.' . $x)];
        }
        $previzualizare = null;
        $eroarePrev = null;
        $exemplu = false;
        // Previzualizarea folosește cea mai nouă comandă potrivită șablonului: felul plății și, la anulări, starea; la suma
        // returnată, ultima rambursare reușită din panou.
        [$fel, $stari] = self::potrivire($ales);
        $sql = 'SELECT id FROM {comenzi} WHERE anonimizat_la IS NULL';
        $par = [];
        if ($fel !== null) {
            $sql .= ' AND fel = ?';
            $par[] = $fel;
        }
        if ($stari !== null) {
            $sql .= ' AND stare IN (' . implode(', ', array_fill(0, count($stari), '?')) . ')';
            array_push($par, ...$stari);
        }
        $cm = $app->baza()->rand($sql . ' ORDER BY id DESC LIMIT 1', $par);
        try {
            if ($cm !== null) {
                $suma = (int) ($app->baza()->valoare("SELECT suma FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND stare = 'reusit' ORDER BY id DESC LIMIT 1", [(int) $cm['id']]) ?? 0);
                $msg = $app->sabloaneEmail()->mesaj($ales, $l, ['comanda_id' => (int) $cm['id'], 'suma_bani' => $suma, 'previzualizare' => true], 'previzualizare@exemplu.invalid');
                $previzualizare = ['subiect' => $msg->subiect, 'text' => $msg->text];
            } else {
                // fără o rezervare potrivită, previzualizarea se face cu date de exemplu.
                $previzualizare = self::exempluMesaj($app, $ales, $l, $fel ?? 'cash', $stari[0] ?? 'cerere_in_asteptare');
                $exemplu = $previzualizare !== null;
            }
        } catch (\Throwable $e) {
            $eroarePrev = $t->t('admin.sabloane.previzualizare_eroare', ['eroare' => mb_substr(\Cazo\Nucleu\Jurnal::mascheaza(get_class($e) . ': ' . $e->getMessage()), 0, 200)]);
        }
        // sursa textului previzualizat (șablonul din EasyCheck sau textul standard) și garda unui șablon respins.
        if ($ec) {
            $pr = $app->mesajeEc()->proiectie($ales, $l);
            $d['ec'] = ['sursa' => $app->mesajeEc()->sablon($ales, $l) !== null ? 'ec' : 'standard',
                'respins' => $pr !== null && (int) $pr['valid'] !== 1 ? $t->t('admin.sabloane.respins', ['garda' => implode(', ', array_map(static fn (string $g): string => $t->t('admin.sabloane.garda.' . $g),
                    array_values(array_intersect(explode(',', (string) $pr['garda']), \Cazo\Email\SabloaneGazda::GARZI)) ?: ['text_simplu']))]) : null,
                'url' => self::adresaEc($app, $ales, $l)];
        }
        $d += ['sabloane' => $lista, 'ales' => $ales, 'nume_ales' => $t->t('admin.sabloane.sablon.' . $ales), 'cand' => $t->t('admin.sabloane.cand.' . $ales), 'limba_sablon' => $l,
            'nota' => \Cazo\Email\Sabloane::notaGazdei($app, $ales, $l), 'previzualizare' => $previzualizare, 'exemplu' => $exemplu, 'ref_exemplu' => self::REF_EXEMPLU,
            'eroare_previzualizare' => $eroarePrev, 'maxim' => self::NOTA_MAXIM, 'url' => ['baza' => $app->url()->cale('/admin/emailuri/sabloane')]];
        return AdminPanou::pagina($app, $u, 'sabloane', $d, $r);
    }

    /** Comanda potrivită previzualizării unui mesaj: felul plății și stările în care pleacă (null = oricare). @return array{0:?string, 1:?list<string>} */
    public static function potrivire(string $sablon): array
    {
        return match ($sablon) {
            'plata_confirmata' => ['card', ['confirmata', 'anulare_ceruta']],
            'rambursare_gazda' => ['card', ['confirmata', 'anulata_oaspete', 'anulata_gazda']],
            'cerere_primita' => ['cash', null],
            'cerere_acceptata' => ['cash', ['confirmata']],
            'cerere_refuzata' => ['cash', ['cerere_refuzata']],
            'anulare_oaspete' => [null, ['anulata_oaspete']],
            'anulare_gazda' => [null, ['anulata_gazda']],
            // celelalte mesaje ale catalogului.
            'cerere_expirata' => ['cash', ['cerere_expirata']],
            'cerere_netrimisa' => ['cash', ['abandonata', 'trimitere_ec']],
            'cerere_indisponibila' => [null, ['indisponibila']],
            'cerere_respinsa' => [null, ['respinsa_ec']],
            'cerere_intarziata' => ['cash', ['cerere_in_asteptare']],
            'plata_in_procesare' => ['card', ['plata_in_procesare']],
            'plata_esuata' => ['card', ['plata_esuata']],
            'plata_rambursata', 'rezervare_anulata' => ['card', ['de_rambursat', 'rambursata', 'autorizare_anulata']],
            'plata_verificare' => ['card', ['platita', 'trimitere_ec', 'de_capturat']],
            'plata_inchisa' => ['card', ['inchisa_gazda']],
            'transfer_asteptat', 'transfer_reamintire', 'transfer_prelungit' => ['transfer', ['transfer_in_asteptare']],
            'transfer_neprimit' => ['transfer', ['transfer_neprimit']],
            'transfer_expirat' => ['transfer', ['transfer_expirat']],
            'transfer_anulat' => ['transfer', ['anulata_oaspete']],
            'transfer_anulat_gazda' => ['transfer', ['anulata_gazda']],
            'restituire_transfer' => ['transfer', ['confirmata', 'anulata_oaspete', 'anulata_gazda']],
            'anulare_ceruta' => ['card', ['anulare_ceruta']],
            'anulare_pastrata', 'anulare_renuntata', 'anulare_renuntata_gratuita', 'schimbare_facuta', 'schimbare_esuata' => ['card', ['confirmata']],
            default => [null, null],
        };
    }

    /** Adresa mesajului în EasyCheck („Mesajele site-ului”), pe domeniul API-ului legat. */
    public static function adresaEc(Aplicatie $app, string $sablon, string $limba): string
    {
        $u = parse_url($app->ecClient()->baza());
        $origine = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? 'easycheck.ro') . (isset($u['port']) ? ':' . $u['port'] : '');
        return $origine . '/host/site.php?' . http_build_query(['fila' => 'mesaje', 'mesaj' => $sablon, 'limba' => $limba]);
    }

    /**
     * Previzualizarea cu date de exemplu: o rezervare inventată (oaspetele „Maria Exemplu”, codul REF_EXEMPLU, prima
     * cameră din oglindă, două nopți peste o lună), scrisă într-o tranzacție care se anulează imediat după ce șablonul
     * adevărat a făcut mesajul din ea. În bază nu rămâne nimic, iar mesajul trece prin același cod ca e-mailul trimis
     * oaspeților. Null cât oglinda nu are nicio cameră.
     * @return array{subiect:string, text:string}|null
     */
    private static function exempluMesaj(Aplicatie $app, string $sablon, string $l, string $fel, string $stare): ?array
    {
        $camera = null;
        foreach ((array) ($app->ecOglinda()->corp('camere')['camere'] ?? []) as $x) {
            if (is_array($x) && is_string($x['id'] ?? null)) {
                $camera = $x['id'];
                break;
            }
        }
        if ($camera === null) {
            return null;
        }
        $zi = static fn (int $peste): string => (new \DateTimeImmutable($app->azi()))->modify('+' . $peste . ' days')->format('Y-m-d');
        $total = 50000;
        $card = $fel === 'card';
        $rez = null;
        try {
            $app->baza()->tranzactie(static function () use ($app, $sablon, $l, $fel, $stare, $camera, $zi, $total, $card, &$rez): void {
                $r = $app->comenzi()->creeaza([
                    'fel' => $fel, 'limba' => $l, 'proba' => true, 'sosire' => $zi(30), 'plecare' => $zi(32),
                    'camere' => [['camera' => $camera, 'adulti' => 2, 'copii' => 0]], 'plan' => null, 'sursa_pret' => 'easycheck',
                    'total' => $total, 'componente' => ['nopti' => $total], 'suma_acum' => $card ? $total : 0, 'rest_la_sosire' => $card ? 0 : $total,
                    'oaspete' => ['nume' => 'Maria Exemplu', 'email' => 'maria@exemplu.invalid', 'telefon' => null, 'tara' => 'RO'], 'nota' => '', 'marketing' => false, 'ip24' => '192.0.2.0/24',
                    'acord' => ['termeni_versiune' => 'exemplu', 'termeni_hash' => 'exemplu', 'confidentialitate_versiune' => 'exemplu', 'text_buton' => 'exemplu',
                        'text_precontractual_hash' => 'exemplu', 'text_marketing_hash' => null, 'ip' => '192.0.2.1', 'agent' => 'exemplu'],
                ]);
                $app->baza()->executa('UPDATE {comenzi} SET ref = ?, stare = ?, ec_expira_la = ? WHERE id = ?', [self::REF_EXEMPLU, $stare, $app->ceas()->moment(86400), $r['id']]);
                $msg = $app->sabloaneEmail()->mesaj($sablon, $l, ['comanda_id' => $r['id'], 'suma_bani' => 20000, 'previzualizare' => true], 'maria@exemplu.invalid');
                // Linkul „Rezervarea mea” ar duce la o rezervare care nu există: jetonul lui devine „exemplu”.
                $token = $app->comenzi()->token((array) $app->comenzi()->dupaId($r['id']));
                $rez = ['subiect' => $msg->subiect, 'text' => str_replace($token, 'exemplu', $msg->text)];
                throw new \LogicException('Exemplul se anulează: tranzacția se întoarce.');
            });
        } catch (\Throwable $e) {
            if ($rez === null) {
                throw $e;
            }
        }
        return $rez;
    }

    // ---- Paginile legale și subsolul, editabile integral ----

    public static function texteLegale(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $cerut = (string) ($c->param('fel') ?? $c->camp('fel') ?? '');
        $fel = in_array($cerut, Editabile::FELURI, true) ? $cerut : 'termeni';
        $l = ($c->param('limba') ?? $c->camp('limba')) === 'en' ? 'en' : 'ro';
        $ed = new Editabile($app);
        $trimis = null;
        $gresite = [];
        // cu parola greșită sau lipsă, pagina revine cu textul scris de gazdă (422), nu cu textul de dinainte.
        $faraParola = false;
        if ($c->metoda === 'POST') {
            $model = $c->camp('actiune') === 'model';
            if (!$model) {
                // Textul rămâne exact cum l-a scris gazda („<12 ani” e text): fără „\r”, tăiat la lungimea maximă, escapat la afișare.
                $curat = static fn (string $x, int $max): string => trim(mb_substr(str_replace("\r", '', $x), 0, $max));
                if ($fel === 'subsol') {
                    $g = [];
                    foreach (Editabile::GRUPURI as $k) {
                        $g[$k] = $curat((string) ($c->camp('grup_' . $k) ?? ''), Editabile::MAXIM_GRUP);
                    }
                    $trimis = Editabile::json($g);
                    $gresite = Editabile::adreseGresite($g);
                } else {
                    $trimis = $curat((string) ($c->camp('corp') ?? ''), Editabile::MAXIM);
                }
            }
            $faraParola = !AdminPanou::reautentificat($app, $c, $u, $jeton);
            if (!$faraParola && $model) {
                $v = $ed->salveaza($fel, $l, 'model', '', (int) $u['id']);
                $app->auditDetalii(['fel' => $fel, 'limba' => $l, 'versiune' => $v, 'model' => true]);
                $app->ecOglinda()->schimbaContinutul();
                return AdminPanou::inapoi($app, '/admin/legal/texte', ['mesaj' => 'model', 'fel' => $fel, 'limba' => $l]);
            }
            if (!$faraParola && $gresite === []) {
                $v = $ed->salveaza($fel, $l, 'integral', (string) $trimis, (int) $u['id']);
                $app->auditDetalii(['fel' => $fel, 'limba' => $l, 'versiune' => $v]);
                $app->ecOglinda()->schimbaContinutul();
                return AdminPanou::inapoi($app, '/admin/legal/texte', ['mesaj' => 'versiune', 'fel' => $fel, 'limba' => $l]);
            }
        }
        $ul = (string) $u['limba_panou'];
        $tp = $app->traducator($ul, 'admin');
        $r = Raspuns::html('');
        if ($gresite !== [] || $faraParola) {
            $r->status = 422;
        }
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'texte', self::MESAJE, self::ERORI);
        if ($faraParola) {
            $d['mesaj'] = null;
            $d['eroare'] = $tp->t('admin.reauth.eroare');
        } elseif ($gresite !== []) {
            $d['eroare'] = $tp->t('admin.texte_legale.eroare');
        }
        $numeGrup = static fn (string $g): string => $tp->t('admin.texte_legale.grup.' . $g);
        $numeFel = static fn (string $f): string => $f === 'subsol' ? $tp->t('admin.texte_legale.subsol') : $app->traducator($ul)->t('legal.link.' . $f);
        $istoric = [];
        $ultima = null;
        foreach ($app->baza()->toate('SELECT * FROM {texte_legale} WHERE fel = ? AND limba = ? ORDER BY versiune DESC LIMIT 50', [$fel, $l]) as $x) {
            $format = (string) ($x['format'] ?? 'adaugat');
            $la = $x['aprobat_la'] !== null ? Format::moment((string) $x['aprobat_la'], $ul) : '';
            $istoric[] = ['versiune' => (int) $x['versiune'], 'activ' => (int) $x['activ'] === 1, 'la' => $la,
                'fel' => $tp->t('admin.texte_legale.format.' . $format), 'corp' => $format === 'integral' ? Editabile::deComparat($fel, (string) $x['corp'], $numeGrup) : (string) $x['corp']];
            // „Ultima schimbare” e data versiunii de acum (`texte_legale.aprobat_la`), în fusul site-ului.
            if ((int) $x['activ'] === 1 && $ultima === null && $la !== '') {
                $ultima = $la;
            }
        }
        $text = $trimis ?? $ed->deEditat($fel, $l);
        $model = $ed->model($fel, $l);
        $vechi = $ed->modelVechi($fel, $l);
        $comparat = Editabile::deComparat($fel, $text, $numeGrup);
        $modelComparat = Editabile::deComparat($fel, $model, $numeGrup);
        $felurile = [];
        foreach (Editabile::FELURI as $f) {
            $felurile[] = ['cheie' => $f, 'text' => $numeFel($f)];
        }
        $grupuri = [];
        if ($fel === 'subsol') {
            foreach (Editabile::grupuri($text) as $k => $x) {
                $grupuri[] = ['cheie' => $k, 'eticheta' => $numeGrup($k), 'text' => $x, 'randuri' => max(2, min(12, substr_count($x, "\n") + 2))];
            }
        }
        // previzualizarea paginii, cu câmpurile ({denumire}, {adresa}...) completate de același cod care face pagina
        // de pe site (PaginiLegale), deci textul salvat, nu cel abia scris. Subsolul se vede pe site, pe orice pagină.
        $previzualizare = null;
        if ($fel !== 'subsol') {
            try {
                $pg = (new PaginiLegale($app, $app->traducator($l), $l))->pagina($fel);
                $previzualizare = ['titlu' => (string) $pg['titlu'], 'intro' => (array) $pg['intro'], 'sectiuni' => (array) $pg['sectiuni']];
            } catch (\Throwable) {
                $previzualizare = null;
            }
        }
        $integral = $ed->integral($fel, $l) !== null;
        $d += ['fel' => $fel, 'limba_text' => $l, 'felurile' => $felurile, 'nume_fel' => $numeFel($fel), 'istoric' => $istoric, 'corp' => $fel === 'subsol' ? '' : $text, 'grupuri' => $grupuri,
            'integral' => $integral, 'diferente' => $comparat === $modelComparat ? [] : Editabile::diferente($modelComparat, $comparat),
            'model_schimbat' => $vechi !== null ? Editabile::diferente(Editabile::deComparat($fel, $vechi, $numeGrup), $modelComparat) : [],
            'gresite' => array_map(static fn (array $x): string => $tp->t('admin.texte_legale.rand', ['grup' => $numeGrup($x['grup']), 'n' => $x['rand']]), $gresite), 'campuri' => implode(', ', array_map(static fn (string $x): string => '{' . $x . '}', $fel === 'subsol' ? self::CAMPURI_SUBSOL : self::CAMPURI_PAGINA)),
            'ultima_schimbare' => $ultima, 'previzualizare' => $previzualizare, 'fara_parola' => $faraParola, 'nesalvat' => $trimis !== null,
            // editorul stă pe „Legal și datele oaspeților”; alegerea paginii de editat se deschide tot acolo.
            'url' => ['baza' => $app->url()->cale('/admin/legal/texte'), 'deschide' => $app->url()->cale('/admin/gdpr') . '#texte',
                'site' => $fel === 'subsol' ? $app->url()->cale('/', $l) : $app->url()->cale(Cai::cale($fel, $l), $l)]];
        return AdminPanou::pagina($app, $u, 'texte-legale', $d, $r);
    }

    /** Câmpurile pe care nucleul le completează în textul paginilor și al subsolului (ajutorul de lângă editor). */
    public const CAMPURI_PAGINA = ['denumire', 'nume', 'adresa', 'email', 'telefon', 'checkin', 'plecare', 'site', 'destinatari', 'harta',
        // Câmpurile paginilor publice refăcute: fraze întregi, făcute din setările site-ului.
        'contact_fraza', 'total_cuprinde', 'plata_metode', 'plata_avans', 'date_card', 'pastrare'];

    public const CAMPURI_SUBSOL = ['nume', 'denumire', 'cui', 'registrul_comertului', 'sediu', 'sediu_eticheta', 'capital_social', 'certificat_clasificare', 'clasificare', 'telefon', 'telefon_tel', 'email', 'adresa', 'site', 'an'];
}
