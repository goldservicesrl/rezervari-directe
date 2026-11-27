<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\Continut\Sigla;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;
use Cazo\Teme\Despachetare;
use Cazo\Teme\Raport;
use Cazo\Teme\SetariTema;
use Cazo\Teme\Tema;

defined('CAZO') or exit;

/**
 * Secțiunea „Aspect” a panoului: temele oficiale ale lansării (fără cele private altui domeniu),
 * ca niște carduri cu captura fiecăreia (theme.json, cheia `captura`), temele încărcate de gazdă cu raportul
 * validatorului, încărcarea unei teme (zip, fără JavaScript), previzualizarea cu datele site-ului, activarea (validatorul
 * rulează din nou pe o temă încărcată), revenirea la tema anterioară, ștergerea și setările din schemă, cu verificarea
 * contrastului. Doar proprietarul.
 *
 * Schimbarea temei (activarea, revenirea) trece fără parolă: se desface cu un clic, din „Reveniți la tema …”.
 * Încărcarea unei arhive și ștergerea unei teme încărcate cer parola confirmată în ultimele 10 minute (
 * AdminPanou::reautentificat, câmpul `parola_reauth`); ștergerea cere și bifa de confirmare.
 *
 *   GET  /admin/aspect                                  lista, încărcarea, raportul ultimei încărcări
 *   POST /admin/aspect/incarca                          arhiva zip (câmpul „tema”)
 *   GET  /admin/aspect/{slug}/previzualizare            pagina de început (sau ?pagina=camere|camera) în tema aleasă
 *   POST /admin/aspect/{slug}/activeaza, /admin/aspect/{slug}/sterge
 *   POST /admin/aspect/revino                           tema activă dinainte
 *   GET  /admin/aspect/{slug}/setari, POST la fel        setările din settings.schema.json
 *   GET  /admin/aspect/{slug}/setari.json                exportul setărilor ca JSON
 *   POST /admin/aspect/{slug}/setari/import              importul lor, cu aceleași verificări
 */
final class AdminTeme
{
    public const SECTIUNE = 'aspect';

    public const MESAJE = ['incarcata' => 'admin.teme.incarcata', 'incarcata_noua' => 'admin.teme.incarcata_noua', 'activata' => 'admin.teme.activata', 'stearsa' => 'admin.teme.stearsa', 'revenire' => 'admin.teme.revenire',
        'salvate' => 'admin.teme.setari_salvate'];
    public const ERORI = ['respinsa' => 'admin.teme.respinsa', 'fisier' => 'admin.teme.eroare.fisier', 'marime' => 'admin.teme.eroare.marime', 'activa' => 'admin.teme.eroare.sterge_activa',
        'necunoscuta' => 'admin.teme.eroare.necunoscuta', 'reauth' => 'admin.reauth.eroare', 'confirmare' => 'admin.teme.eroare.confirmare', 'nevalidata' => 'admin.teme.eroare.nevalidata',
        'fara_anterioara' => 'admin.teme.eroare.fara_anterioara'];
    /** Erorile care țin de un câmp din zona „Avansat”: mesajul de sus duce la câmp, iar zona se deschide. */
    public const ERORI_CAMP = ['fisier' => 'pa-tema-zip', 'marime' => 'pa-tema-zip', 'reauth' => 'pa-teme-avansat', 'confirmare' => 'pa-teme-avansat', 'respinsa' => 'pa-tema-raport',
        'activa' => 'pa-teme-avansat'];

    /** Captura din carduri: mărimea capturilor livrate (prima pagină pe un ecran de telefon), scrisă în atributele imaginii; raportul 4:5 îl ține CSS-ul. */
    public const CAPTURA = ['latime' => 600, 'inaltime' => 750];

    /** @return array{0:?array, 1:string, 2:?Raspuns} utilizatorul proprietar cu jetonul sesiunii, sau răspunsul de întoarcere */
    private static function proprietar(Aplicatie $app, Cerere $c): array
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return [null, '', AdminPanou::inapoi($app)];
        }
        return [$s[0], $s[1], null];
    }

    /** Înapoi la lista temelor, cu mesajul sau eroarea în adresă; $ancora duce la zona paginii unde stă câmpul. */
    private static function inapoi(Aplicatie $app, array $q, string $ancora = ''): Raspuns
    {
        $e = $q['eroare'] ?? null;
        $ancora = $ancora !== '' ? $ancora : (is_string($e) && isset(self::ERORI_CAMP[$e]) ? self::ERORI_CAMP[$e] : '');
        return Raspuns::redirectare($app->url()->cale('/admin/aspect', null, $q) . ($ancora !== '' ? '#' . $ancora : ''));
    }

    /**
     * Site-ul arată altfel (altă temă, altă versiune a ei, alte setări): versiunea temei crește, deci generația
     * cache-ului de pagini e alta și paginile ținute cu aspectul de dinainte se refac.
     */
    private static function aspectSchimbat(Aplicatie $app, ?int $utilizator = null): void
    {
        $app->setari()->pune('tema.versiune', (int) $app->setari()->get('tema.versiune', 0) + 1, $utilizator);
        $app->uitaTemaActiva();
        $app->ecOglinda()->schimbaGeneratiaCache();
    }

    public static function lista(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        [$u, $jeton, $r] = self::proprietar($app, $c);
        if ($u === null) {
            return $r;
        }
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $activa = (string) $app->setari()->get('tema.activa', Tema::IMPLICITA);
        $baza = $app->url()->cale('/admin/aspect/');
        $captura = static function (?Tema $tema) use ($app): ?string {
            try {
                return $tema !== null ? $app->active()->captura($tema) : null;
            } catch (\Throwable) {
                // Dosarul public care nu se poate scrie lasă cardul fără imagine; pagina rămâne întreagă.
                return null;
            }
        };
        $oficiale = [];
        foreach ($app->catalog()->oficiale() as $tema) {
            $oficiale[] = ['slug' => $tema->slug(), 'nume' => $tema->nume($l), 'pentru' => (string) ($tema->manifest()['pentru'] ?? ''),
                'activa' => $tema->slug() === $activa, 'captura' => $captura($tema)];
        }
        $terte = [];
        $noi = false;
        foreach ($app->catalog()->terte() as $x) {
            $m = $x['manifest'];
            $noua = $app->temeTerte()->versiuneNoua($x['slug']);
            $noi = $noi || $noua !== null;
            $terte[] = ['slug' => $x['slug'], 'nume' => is_array($m['nume'] ?? null) ? (string) ($m['nume'][$l] ?? $m['nume']['ro'] ?? $x['slug']) : $x['slug'], 'versiune' => $x['versiune'],
                'pentru' => is_string($m['pentru'] ?? null) ? $m['pentru'] : '',
                'autor' => (string) ($m['autor']['nume'] ?? ''), 'licenta' => is_string($m['licenta'] ?? null) ? $m['licenta'] : '', 'stare' => $x['stare'],
                'activa' => $x['slug'] === $activa, 'captura' => $x['stare'] === 'instalata' ? $captura($app->catalog()->tema($x['slug'])) : null,
                'raport' => self::raport($t, Raport::dinTablou($x['raport'])),
                'noua' => $noua !== null ? ['versiune' => (string) $noua['versiune'], 'sha' => (string) $noua['sha'], 'raport' => self::raport($t, Raport::dinTablou((array) $noua['raport']))] : null];
        }
        $ultima = $app->setari()->get('teme.ultima_incarcare');
        $raspuns = Raspuns::html('');
        $numeActiva = $app->catalog()->tema($activa)?->nume($l) ?? $activa;
        $d = AdminPanou::date($app, $c, $raspuns, $u, $jeton, self::SECTIUNE, self::MESAJE, self::ERORI);
        $m = (string) ($c->param('mesaj') ?? '');
        $e = (string) ($c->param('eroare') ?? '');
        // Mesajul spune ce s-a întâmplat, cu numele temei care e acum pe site.
        if ($m === 'activata' || $m === 'revenire') {
            $d['mesaj'] = $t->t(self::MESAJE[$m], ['tema' => $numeActiva]);
        }
        // Cardul „Vremea pe site”: datele și mesajul lui vin din AdminPret; bifa se salvează la /admin/preturi.
        if (isset(AdminPret::MESAJE_VREME[$m])) {
            $d['mesaj'] = $t->t(AdminPret::MESAJE_VREME[$m]);
        }
        $d += [
            'vreme' => AdminPret::vreme($app, $l),
            'activa' => $numeActiva,
            'activa_slug' => $activa,
            'anterioara' => self::anterioara($app, $l),
            'oficiale' => $oficiale,
            'terte' => $terte,
            'ultima' => is_array($ultima) && !($ultima['instalata'] ?? false) ? ['nume' => (string) ($ultima['nume'] ?? ''), 'raport' => self::raport($t, Raport::dinTablou((array) ($ultima['raport'] ?? [])))] : null,
            // Câmpul la care duce eroarea de sus și zona „Avansat” deschisă: după o încărcare sau o ștergere, cu o temă
            // încărcată activă ori cu o versiune nouă care așteaptă.
            'eroare_camp' => isset($d['eroare']) ? (self::ERORI_CAMP[$e] ?? null) : null,
            'eroare_cod' => isset($d['eroare']) ? $e : null,
            'avansat_deschis' => isset(self::ERORI_CAMP[$e]) || in_array($m, ['incarcata', 'incarcata_noua', 'stearsa'], true) || $noi
                || in_array($activa, array_column($terte, 'slug'), true),
            'captura' => self::CAPTURA,
            'url' => ['baza' => $baza, 'incarca' => $app->url()->cale('/admin/aspect/incarca'), 'revino' => $app->url()->cale('/admin/aspect/revino')],
            'limite' => ['mb' => (int) (Despachetare::ARHIVA_MAX / 1048576)],
        ];
        return AdminPanou::pagina($app, $u, 'aspect', $d, $raspuns);
    }

    /** Tema la care duce „Reveniți”: numele ei, cu versiunea când e o versiune anume a unei teme terțe. */
    private static function anterioara(Aplicatie $app, string $l): ?string
    {
        $ant = $app->setari()->get('tema.anterioara');
        [$slug, $sha] = array_pad(explode('@', is_string($ant) ? $ant : '', 2), 2, null);
        $t = $slug !== '' ? $app->catalog()->tema($slug) : null;
        if ($t === null) {
            return null;
        }
        $v = $sha !== null ? $app->catalog()->temaVersiune($slug, $sha) : null;
        return $v !== null && $sha !== $app->catalog()->shaInstalat($slug) ? $t->nume($l) . ' ' . $v->versiune() : $t->nume($l);
    }

    /** Raportul validatorului pentru panou: erorile și avertismentele, cu fișierul și linia. */
    private static function raport(\Cazo\I18n\Traducator $t, Raport $r): array
    {
        $f = static fn (array $x): string => ($x['fisier'] !== null ? $x['fisier'] . ($x['linie'] !== null ? ':' . $x['linie'] : '') . ': ' : '') . $x['mesaj'];
        return ['erori' => array_map($f, $r->erori), 'avertismente' => array_map($f, $r->avertismente), 'trece' => $r->trece()];
    }

    public static function incarca(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        [$u, $jeton, $r] = self::proprietar($app, $c);
        if ($u === null) {
            return $r;
        }
        // Încărcarea unei arhive: parola confirmată în ultimele 10 minute sau scrisă acum.
        if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
            return self::inapoi($app, ['eroare' => 'reauth']);
        }
        $f = $c->fisier('tema');
        if ($f === null || $f['eroare'] === UPLOAD_ERR_NO_FILE) {
            return self::inapoi($app, ['eroare' => 'fisier']);
        }
        if (in_array($f['eroare'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['marime'] > Despachetare::ARHIVA_MAX) {
            return self::inapoi($app, ['eroare' => 'marime']);
        }
        if ($f['eroare'] !== UPLOAD_ERR_OK || !is_file($f['cale']) || (!is_uploaded_file($f['cale']) && !$app->config()->mediuTest())) {
            return self::inapoi($app, ['eroare' => 'fisier']);
        }
        $rez = $app->temeTerte()->incarca($f['cale']);
        $app->jurnal()->info('Temă încărcată din panou', ['utilizator' => (int) $u['id'], 'slug' => $rez['slug'], 'instalata' => $rez['instalata']]);
        $app->auditDetalii(['tema' => (string) $rez['slug'], 'instalata' => (bool) $rez['instalata']]);
        return self::inapoi($app, $rez['instalata'] ? ['mesaj' => !empty($rez['in_asteptare']) ? 'incarcata_noua' : 'incarcata'] : ['eroare' => 'respinsa'],
            $rez['instalata'] ? 'pa-teme-avansat' : '');
    }

    /** Previzualizarea: pagina temei cu datele site-ului, doar pentru proprietar, fără cache și cu noindex. */
    public static function previzualizare(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        [$u, $jeton, $r] = self::proprietar($app, $c);
        if ($u === null) {
            return $r;
        }
        $slug = (string) $p['slug'];
        if ($app->catalog()->tema($slug) === null) {
            return self::inapoi($app, ['eroare' => 'necunoscuta']);
        }
        // ?versiune=<sha>: versiunea nouă, încărcată peste tema activă, înainte de activare.
        $sha = $c->param('versiune');
        $noua = $sha !== null && ($app->temeTerte()->versiuneNoua($slug)['sha'] ?? null) === $sha ? $app->catalog()->temaVersiune($slug, $sha) : null;
        if ($sha !== null && $noua === null) {
            return self::inapoi($app, ['eroare' => 'necunoscuta']);
        }
        $app->temaPrevizualizata = $slug;
        $app->temaFixa = $noua;
        $app->uitaTemaActiva();
        $pagina = $c->param('pagina');
        if ($pagina === 'camere') {
            $raspuns = Prezentare::camere($app, $c, [], $limba);
        } elseif ($pagina === 'camera' && ($cam = $app->modelVedere($limba)->camere()[0] ?? null) !== null) {
            $raspuns = Prezentare::camera($app, $c, ['slug' => $cam['slug']], $limba);
        } else {
            $raspuns = Prezentare::acasa($app, $c, [], $limba);
        }
        // Previzualizarea ține doar cât cererea aceasta.
        $app->temaPrevizualizata = null;
        $app->temaFixa = null;
        $app->uitaTemaActiva();
        $raspuns->deTinut = false;
        $raspuns->antet('Cache-Control', 'no-store');
        $raspuns->antet('X-Robots-Tag', 'noindex');
        return $raspuns;
    }

    public static function activeaza(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return self::actiune($app, $c, ['actiune' => 'activeaza'] + $p);
    }

    public static function sterge(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return self::actiune($app, $c, ['actiune' => 'sterge'] + $p);
    }

    private static function actiune(Aplicatie $app, Cerere $c, array $p): Raspuns
    {
        [$u, $jeton, $r] = self::proprietar($app, $c);
        if ($u === null) {
            return $r;
        }
        $slug = (string) $p['slug'];
        $tema = $app->catalog()->tema($slug);
        if ($tema === null) {
            return self::inapoi($app, ['eroare' => 'necunoscuta']);
        }
        $app->auditDetalii(['tema' => $slug]);
        if ($p['actiune'] === 'sterge') {
            if ($tema->oficiala()) {
                return self::inapoi($app, ['eroare' => 'necunoscuta']);
            }
            // Ștergerea: bifa de confirmare și parola confirmată în ultimele 10 minute sau scrisă acum.
            if ($c->camp('confirm') !== '1') {
                return self::inapoi($app, ['eroare' => 'confirmare']);
            }
            if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
                return self::inapoi($app, ['eroare' => 'reauth']);
            }
            if (!$app->temeTerte()->sterge($slug)) {
                return self::inapoi($app, ['eroare' => 'activa']);
            }
            $app->jurnal()->info('Temă ștearsă din panou', ['tema' => $slug, 'utilizator' => (int) $u['id']]);
            return self::inapoi($app, ['mesaj' => 'stearsa'], 'pa-teme-avansat');
        }
        // Activarea trece fără parolă: se desface cu „Reveniți la tema …”.
        // Versiunea nouă a unei teme terțe (câmpul „versiune” = sha-ul ei): validatorul rulează din nou, apoi rândul
        // temei trece pe ea; „Reveniți” duce la versiunea de dinainte.
        $sha = $c->camp('versiune');
        if ($sha !== null && !$tema->oficiala()) {
            $vechi = $app->temeTerte()->activeazaVersiuneaNoua($slug, $sha);
            if ($vechi === null) {
                return self::inapoi($app, ['eroare' => 'nevalidata']);
            }
            if ((string) $app->setari()->get('tema.activa', Tema::IMPLICITA) === $slug) {
                $app->setari()->pune('tema.anterioara', $slug . '@' . $vechi, (int) $u['id']);
                self::aspectSchimbat($app, (int) $u['id']);
                $app->jurnal()->info('Versiunea nouă a temei e activă', ['tema' => $slug, 'din' => $vechi, 'in' => $sha, 'utilizator' => (int) $u['id']]);
            } else {
                self::schimbaTema($app, $slug, (int) $u['id']);
            }
            $app->temeTerte()->curataVersiuni($slug);
            return self::inapoi($app, ['mesaj' => 'activata']);
        }
        // Activarea: o temă terță trece din nou prin validator (la încărcare, la activare).
        if (!$tema->oficiala() && !$app->temeTerte()->reverifica($slug)->trece()) {
            return self::inapoi($app, ['eroare' => 'nevalidata']);
        }
        self::schimbaTema($app, $slug, (int) $u['id']);
        return self::inapoi($app, ['mesaj' => 'activata']);
    }

    private static function schimbaTema(Aplicatie $app, string $slug, int $utilizator): void
    {
        $vechi = (string) $app->setari()->get('tema.activa', Tema::IMPLICITA);
        if ($vechi !== $slug) {
            // „Reveniți” duce la tema și la versiunea de acum: o temă terță se ține minte cu sha-ul ei („slug@sha”).
            $sha = $app->catalog()->shaInstalat($vechi);
            $app->setari()->pune('tema.anterioara', $sha !== null ? $vechi . '@' . $sha : $vechi, $utilizator);
        }
        $app->setari()->pune('tema.activa', $slug, $utilizator);
        // Paginile din cache poartă tema veche: generația nouă le reface.
        self::aspectSchimbat($app, $utilizator);
        $app->jurnal()->info('Tema activă s-a schimbat', ['din' => $vechi, 'in' => $slug, 'utilizator' => $utilizator]);
    }

    public static function revino(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        [$u, $jeton, $r] = self::proprietar($app, $c);
        if ($u === null) {
            return $r;
        }
        // Revenirea trece fără parolă, ca activarea.
        $ant = $app->setari()->get('tema.anterioara');
        [$slug, $sha] = array_pad(explode('@', is_string($ant) ? $ant : '', 2), 2, null);
        $acum = $slug !== '' ? $app->catalog()->tema($slug) : null;
        if ($acum === null) {
            return self::inapoi($app, ['eroare' => 'fara_anterioara']);
        }
        $app->auditDetalii(['tema' => $slug]);
        // O temă terță revine la versiunea ei de atunci (sha), nu la cea instalată între timp.
        $shaAcum = $app->catalog()->shaInstalat($slug);
        if ($sha !== null && $shaAcum !== null && $shaAcum !== $sha) {
            if (!$app->temeTerte()->revinoLaVersiune($slug, $sha)) {
                return self::inapoi($app, ['eroare' => 'fara_anterioara']);
            }
            if ((string) $app->setari()->get('tema.activa', Tema::IMPLICITA) === $slug) {
                $app->setari()->pune('tema.anterioara', $slug . '@' . $shaAcum, (int) $u['id']);
                self::aspectSchimbat($app, (int) $u['id']);
                $app->temeTerte()->curataVersiuni($slug);
                return self::inapoi($app, ['mesaj' => 'revenire']);
            }
        }
        self::schimbaTema($app, $slug, (int) $u['id']);
        return self::inapoi($app, ['mesaj' => 'revenire']);
    }

    /** Setările temei din schemă; la salvare, verificarea pe tip și contrastul. */
    public static function setari(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        [$u, $jeton, $r] = self::proprietar($app, $c);
        if ($u === null) {
            return $r;
        }
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $tema = $app->catalog()->tema((string) $p['slug']);
        if ($tema === null) {
            return self::inapoi($app, ['eroare' => 'necunoscuta']);
        }
        $salvate = $app->temaSetari($tema->slug());
        $valori = $salvate['valori'];
        $erori = [];
        $status = 200;
        if ($c->metoda === 'POST') {
            $post = $c->post['setari'] ?? [];
            $imagini = self::imagini($app, $c, $tema, $erori, $t);
            $continut = array_column($app->baza()->toate('SELECT DISTINCT cheie FROM {continut}'), 'cheie');
            $rez = SetariTema::valideaza($tema, is_array($post) ? $post : [], $imagini, $continut, $valori);
            foreach ($rez['erori'] as $id => $e) {
                $erori[$id] = $t->t($e['cheie'], $e['parametri']);
            }
            if ($erori === []) {
                self::salveazaValori($app, $tema->slug(), $rez['valori']);
                return Raspuns::redirectare($app->url()->cale('/admin/aspect/' . $tema->slug() . '/setari', null, ['mesaj' => 'salvate']));
            }
            $valori = array_replace($valori, array_intersect_key(is_array($post) ? $post : [], array_flip(array_column(SetariTema::schema($tema), 'id'))));
            $status = 422;
        }
        $raspuns = Raspuns::html('', $status);
        $eticheta = static function (string $id) use ($t): ?string {
            $cheie = 'admin.teme.setare.' . $id;
            $x = $t->t($cheie);
            return $x !== $cheie ? $x : null;
        };
        $campuri = SetariTema::campuri($tema, $l, $valori, $eticheta);
        foreach ($campuri as &$x) {
            // Rotunjirea și spațierea sunt numere în pixeli: unitatea și explicația panoului, când tema vine fără ele.
            if ($x['tip'] === 'range' && in_array($x['id'], self::IN_PIXELI, true)) {
                $x['unitate'] = $x['unitate'] !== '' ? $x['unitate'] : 'px';
                $x['ajutor'] = $x['ajutor'] !== '' ? $x['ajutor'] : $t->t('admin.teme.setare.' . $x['id'] . '.ajutor', ['min' => (string) $x['min'], 'max' => (string) $x['max']]);
            }
        }
        unset($x);
        $d = AdminPanou::date($app, $c, $raspuns, $u, $jeton, self::SECTIUNE, ['salvate' => 'admin.teme.setari_salvate'], ['import' => 'admin.teme.eroare.import']);
        $d += [
            'tema' => ['slug' => $tema->slug(), 'nume' => $tema->nume($l)],
            'campuri' => $campuri,
            'erori' => $erori,
            'import_esuat' => $c->param('eroare') === 'import',
            'url' => ['salveaza' => $app->url()->cale('/admin/aspect/' . $tema->slug() . '/setari'), 'inapoi' => $app->url()->cale('/admin/aspect'),
                'previzualizare' => $app->url()->cale('/admin/aspect/' . $tema->slug() . '/previzualizare'),
                'export' => $app->url()->cale('/admin/aspect/' . $tema->slug() . '/setari.json'), 'import' => $app->url()->cale('/admin/aspect/' . $tema->slug() . '/setari/import')],
            'praguri' => ['text' => '4,5:1', 'interfata' => '3:1'],
        ];
        return AdminPanou::pagina($app, $u, 'aspect-setari', $d, $raspuns);
    }

    // ---- Sigla site-ului ----

    public const MESAJE_SIGLA = ['sigla_salvata' => 'admin.sigla.salvata', 'sigla_reglaje' => 'admin.sigla.reglaje_salvate', 'sigla_stearsa' => 'admin.sigla.stearsa'];
    public const ERORI_SIGLA = ['sigla_tip' => 'admin.sigla.eroare.tip', 'sigla_marime' => 'admin.sigla.eroare.marime', 'sigla_dimensiuni' => 'admin.sigla.eroare.dimensiuni',
        'sigla_inaltime' => 'admin.sigla.eroare.inaltime', 'sigla_fisier' => 'admin.sigla.eroare.fisier'];

    /**
     * Blocul „Sigla site-ului” de pe pagina Aspect: sigla de acum (sau antetul fără ea), încărcarea, înălțimea pe
     * calculator și pe telefon, ce se arată în antet și ștergerea. Doar proprietarul.
     */
    public static function siglaBloc(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        [$u, $jeton, $r] = self::proprietar($app, $c);
        if ($u === null) {
            return $r;
        }
        $raspuns = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $raspuns, $u, $jeton, self::SECTIUNE, self::MESAJE_SIGLA, self::ERORI_SIGLA);
        $s = $app->sigla();
        $e = (string) ($c->param('eroare') ?? '');
        $d += [
            'sigla' => $s->citeste(),
            'reglaje' => $s->reglaje(),
            'limite' => ['mb' => (int) (Sigla::MARIME_MAXIMA / 1048576), 'min' => Sigla::INALTIME_MIN, 'max' => Sigla::INALTIME_MAX],
            'eroare_fisier' => isset(self::ERORI_SIGLA[$e]) && $e !== 'sigla_inaltime',
            'eroare_inaltime' => $e === 'sigla_inaltime',
            'nume_site' => $app->modelVedere('ro')->nume(),
            'url' => ['sigla' => $app->url()->cale('/admin/aspect/sigla')],
        ];
        return AdminPanou::pagina($app, $u, 'aspect-sigla', $d, $raspuns);
    }

    /** POST /admin/aspect/sigla: încărcarea și reglajele (`actiune=salveaza`), ori ștergerea (`actiune=sterge`). */
    public static function sigla(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        [$u, $jeton, $r] = self::proprietar($app, $c);
        if ($u === null) {
            return $r;
        }
        $s = $app->sigla();
        $inapoi = static fn (array $q): Raspuns => AdminCompus::spre($app, '/admin/aspect', 'sigla', $q, 303);
        if ($c->camp('actiune') === 'sterge') {
            $s->sterge((int) $u['id']);
            self::aspectSchimbat($app, (int) $u['id']);
            $app->jurnal()->info('Sigla site-ului ștearsă din panou', ['utilizator' => (int) $u['id']]);
            $app->auditDetalii(['sigla' => 'stearsa']);
            return $inapoi(['mesaj' => 'sigla_stearsa']);
        }
        if (!Sigla::inaltimeValida($c->camp('inaltime')) || !Sigla::inaltimeValida($c->camp('inaltime_telefon'))) {
            return $inapoi(['eroare' => 'sigla_inaltime']);
        }
        $f = $c->fisier('sigla');
        $incarcata = false;
        if ($f !== null && $f['eroare'] !== UPLOAD_ERR_NO_FILE) {
            if (in_array($f['eroare'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['marime'] > Sigla::MARIME_MAXIMA) {
                return $inapoi(['eroare' => 'sigla_marime']);
            }
            if ($f['eroare'] !== UPLOAD_ERR_OK || !is_file($f['cale']) || (!is_uploaded_file($f['cale']) && !$app->config()->mediuTest())) {
                return $inapoi(['eroare' => 'sigla_fisier']);
            }
            try {
                $s->incarca((string) file_get_contents($f['cale']), (int) $u['id']);
            } catch (\UnexpectedValueException $e) {
                return $inapoi(['eroare' => 'sigla_' . (in_array($e->getMessage(), ['marime', 'dimensiuni'], true) ? $e->getMessage() : 'tip')]);
            }
            $incarcata = true;
        }
        $s->regleaza($c->camp('inaltime'), $c->camp('inaltime_telefon'), $c->camp('mod'), (int) $u['id']);
        self::aspectSchimbat($app, (int) $u['id']);
        $app->jurnal()->info($incarcata ? 'Sigla site-ului încărcată din panou' : 'Reglajele siglei salvate din panou', ['utilizator' => (int) $u['id']]);
        $app->auditDetalii(['sigla' => $incarcata ? 'incarcata' : 'reglaje']);
        return $inapoi(['mesaj' => $incarcata ? 'sigla_salvata' : 'sigla_reglaje']);
    }

    /** Setările `range` pe care nucleul le scrie în pixeli (Jetoane): rotunjirea colțurilor și spațierea. */
    public const IN_PIXELI = ['raza', 'spatiu'];

    /** Exportul setărilor temei ca JSON (pentru o copie sau pentru alt site cu aceeași temă). */
    public static function exportSetari(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        [$u, $jeton, $r] = self::proprietar($app, $c);
        if ($u === null) {
            return $r;
        }
        $tema = $app->catalog()->tema((string) $p['slug']);
        if ($tema === null) {
            return self::inapoi($app, ['eroare' => 'necunoscuta']);
        }
        $json = json_encode(['tema' => $tema->slug(), 'versiune' => $tema->versiune(), 'valori' => (object) $app->temaSetari($tema->slug())['valori']],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $raspuns = new Raspuns(200, (string) $json);
        $raspuns->antet('Content-Type', 'application/json; charset=utf-8');
        $raspuns->antet('Content-Disposition', 'attachment; filename="setari-' . $tema->slug() . '.json"');
        return $raspuns;
    }

    /** Importul setărilor din JSON: aceleași verificări ca la salvarea din formular, inclusiv contrastul. */
    public static function importSetari(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        [$u, $jeton, $r] = self::proprietar($app, $c);
        if ($u === null) {
            return $r;
        }
        $tema = $app->catalog()->tema((string) $p['slug']);
        if ($tema === null) {
            return self::inapoi($app, ['eroare' => 'necunoscuta']);
        }
        $f = $c->fisier('setari');
        $d = $f !== null && $f['eroare'] === UPLOAD_ERR_OK && $f['marime'] <= 65536 && is_file($f['cale']) && (is_uploaded_file($f['cale']) || $app->config()->mediuTest())
            ? json_decode((string) file_get_contents($f['cale']), true, 16) : null;
        $valori = is_array($d) && is_array($d['valori'] ?? null) ? $d['valori'] : null;
        $inapoi = '/admin/aspect/' . $tema->slug() . '/setari';
        if ($valori === null) {
            return Raspuns::redirectare($app->url()->cale($inapoi, null, ['eroare' => 'import']) . '#pa-setari-avansat');
        }
        // Ca din formular: valorile din fișier peste cele salvate și peste implicitele temei.
        $vechi = $app->temaSetari($tema->slug())['valori'];
        $post = [];
        foreach (array_replace($tema->setariImplicite(), $vechi, $valori) as $k => $v) {
            $post[(string) $k] = is_bool($v) ? ($v ? '1' : '0') : (is_int($v) ? (string) $v : $v);
        }
        $continut = array_column($app->baza()->toate('SELECT DISTINCT cheie FROM {continut}'), 'cheie');
        $rez = SetariTema::valideaza($tema, $post, [], $continut, $vechi);
        if ($rez['erori'] !== []) {
            return Raspuns::redirectare($app->url()->cale($inapoi, null, ['eroare' => 'import']) . '#pa-setari-avansat');
        }
        self::salveazaValori($app, $tema->slug(), $rez['valori']);
        return Raspuns::redirectare($app->url()->cale($inapoi, null, ['mesaj' => 'salvate']));
    }

    private static function salveazaValori(Aplicatie $app, string $slug, array $valori): void
    {
        $json = json_encode((object) $valori, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $b = $app->baza();
        if ($b->rand('SELECT tema FROM {teme_setari} WHERE tema = ?', [$slug]) !== null) {
            $b->executa('UPDATE {teme_setari} SET valori = ?, versiune = versiune + 1 WHERE tema = ?', [$json, $slug]);
        } else {
            $b->insereaza('teme_setari', ['tema' => $slug, 'valori' => $json, 'compozitie' => '{}', 'versiune' => 1]);
        }
        // Culorile și fonturile noi: paginile ținute în cache se refac.
        self::aspectSchimbat($app);
    }

    /**
     * Imaginile din setări (tipul image): încărcate prin nucleu, re-encodate (JPEG, PNG sau WebP, fără SVG și fără
     * metadate), publicate în _s/ cu nume generat. @return array<string,string> id => adresa publică
     */
    private static function imagini(Aplicatie $app, Cerere $c, Tema $tema, array &$erori, \Cazo\I18n\Traducator $t): array
    {
        $r = [];
        foreach (SetariTema::schema($tema) as $x) {
            if ($x['tip'] !== 'image') {
                continue;
            }
            $f = $c->fisier('imagine_' . $x['id']);
            if ($f === null || $f['eroare'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $date = $f['eroare'] === UPLOAD_ERR_OK && $f['marime'] <= 8 * 1048576 && is_file($f['cale']) && (is_uploaded_file($f['cale']) || $app->config()->mediuTest())
                ? (string) file_get_contents($f['cale']) : '';
            // Aceeași limită ca la imaginile temelor (16 megapixeli), din antet, înainte ca GD să decodeze imaginea.
            $dim = $date !== '' ? @getimagesizefromstring($date) : false;
            $img = $dim !== false && \Cazo\Teme\Validator::imagineIncape((int) $dim[0], (int) $dim[1]) && function_exists('imagecreatefromstring') ? @imagecreatefromstring($date) : false;
            if ($img === false) {
                $erori[$x['id']] = $t->t('admin.teme.eroare.imagine');
                continue;
            }
            imagesavealpha($img, true);
            ob_start();
            $tip = function_exists('imagewebp') ? 'webp' : 'png';
            $tip === 'webp' ? imagewebp($img, null, 85) : imagepng($img, null, 9);
            $bin = (string) ob_get_clean();
            imagedestroy($img);
            $nume = substr(hash('sha256', $bin), 0, 24) . '.' . $tip;
            $dir = $app->config()->calePublic() . '/_s/img';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            if (!is_file($dir . '/' . $nume)) {
                file_put_contents($dir . '/' . $nume, $bin);
            }
            $r[$x['id']] = '/_s/img/' . $nume;
        }
        return $r;
    }
}
