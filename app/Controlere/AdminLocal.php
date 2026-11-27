<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Local\AdresaSigura;
use Cazo\Local\Calendare;
use Cazo\Local\Depozit;
use Cazo\Local\EroareIcal;
use Cazo\Local\Mod;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;

defined('CAZO') or exit;

/**
 * Panoul site-ului fără cheie EasyCheck:
 *
 *  - „Proprietatea” (/admin/proprietate): numele, felul, descrierea în română și în engleză, adresa cu punctul de pe
 *    hartă (căutat după adresă, OpenStreetMap), orele de check-in și check-out, regulile casei, contactul și regula de
 *    rezervare: sejurul minim, avansul, anularea gratuită și timpul pentru răspuns la o cerere (o singură setare
 *    pe proprietate);
 *  - „Camerele și prețurile” (/admin/camere-site): numele, unitățile, capacitatea, descrierea, „Preț pe noapte” și
 *    „Preț pe noapte în weekend (vineri și sâmbătă)”; pozele rămân la „Camerele” (Poze), ca până acum; dedesubt, cele
 *    două reduceri ale proprietății (POST /admin/reduceri-site): sejurul lung și rezervarea din timp;
 *  - „Calendarul” (/admin/calendar): calendarele iCal citite (Booking, Airbnb, altele), adresa calendarului
 *    site-ului pentru fiecare cameră, zilele închise de mână;
 *  - trecerea dintr-un mod în altul, de pe Setări › Legătura cu EasyCheck (POST /admin/legatura/...), cu parola.
 *
 * Toate paginile sunt ale proprietarului; în modul EasyCheck, paginile de date întorc spre Legătura cu EasyCheck.
 */
final class AdminLocal
{
    public const MESAJE = [
        'proprietate' => 'admin.local.prop.salvata', 'harta' => 'admin.local.prop.harta_gasita', 'harta_negasita' => 'admin.local.prop.harta_negasita',
        'camera' => 'admin.local.cam.salvata', 'camera_noua' => 'admin.local.cam.adaugata', 'retrasa' => 'admin.local.cam.retrasa',
        'ical_adaugat' => 'admin.local.ical.adaugat', 'ical_citit' => 'admin.local.ical.citit', 'ical_sters' => 'admin.local.ical.sters',
        'inchise' => 'admin.local.inchise.salvate', 'deschise' => 'admin.local.inchise.deschise', 'jeton' => 'admin.local.export.jeton_nou', 'interval' => 'admin.local.ical.interval_salvat',
        'spre_local' => 'admin.local.trecere.gata_local', 'spre_ec' => 'admin.local.trecere.gata_ec', 'camere_mapate' => 'admin.local.trecere.mapare_salvata',
        'retrimise' => 'admin.local.trecere.retrimise', 'reduceri' => 'admin.local.red.salvate',
    ];
    public const ERORI = [
        'reauth' => 'admin.local.eroare.reauth', 'confirmare' => 'admin.local.eroare.confirmare', 'mod' => 'admin.local.eroare.mod',
        'rezervari' => 'admin.local.cam.eroare_rezervari', 'limita' => 'admin.local.cam.eroare_limita', 'camera' => 'admin.local.eroare.camera',
        'cheie' => 'admin.local.trecere.eroare_cheie', 'cheie_forma' => 'admin.conexiuni.eroare_forma', 'citire' => 'admin.local.trecere.eroare_citire',
        'retea' => 'admin.local.trecere.eroare_retea', 'perioada' => 'admin.local.inchise.eroare_perioada', 'ical' => 'admin.local.ical.eroare_adaugare',
    ];

    /** Centrul hărții din panou când proprietatea n-are încă un punct: mijlocul României, la zoomul cel mai larg. */
    public const CENTRU_HARTA = [45.94, 24.97];

    /** Platformele propuse la adăugarea unui calendar; numele rămâne scris de gazdă la „alta”. */
    public const PLATFORME = ['booking' => 'Booking.com', 'airbnb' => 'Airbnb', 'easycheck' => 'EasyCheck', 'alta' => null];

    private static function sesiune(Aplicatie $app, Cerere $c): ?array
    {
        return AdminPanou::sesiune($app, $c, 'proprietar');
    }

    // ---- Proprietatea ----

    public static function proprietate(Aplicatie $app, Cerere $c, array $p, string $limba, array $valori = [], array $erori = []): Raspuns
    {
        $s = self::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        if (!$app->modLocal()) {
            return AdminPanou::inapoi($app, '/admin/conexiuni');
        }
        [$u, $jeton] = $s;
        $t = $app->traducator((string) $u['limba_panou'], 'admin');
        $r = Raspuns::html('', $erori !== [] ? 422 : 200);
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'proprietate', self::MESAJE, self::ERORI);
        $pr = $app->localDepozit()->proprietate();
        $reg = $app->localDepozit()->reguli();
        $v = $valori + self::valoriProprietate($pr, $reg);
        if ($erori !== []) {
            $d['eroare'] = $t->t('admin.local.eroare.campuri');
        }
        $d += ['v' => $v, 'erori' => array_map(static fn (string $k): string => $t->t($k), $erori), 'tipuri' => Depozit::TIPURI, 'reguli_casa' => Depozit::REGULI_CASA,
            'harta' => $pr['coordonate'] !== null ? 'https://www.openstreetmap.org/?mlat=' . $pr['coordonate']['lat'] . '&mlon=' . $pr['coordonate']['lng'] . '#map=17/'
                . $pr['coordonate']['lat'] . '/' . $pr['coordonate']['lng'] : null,
            'url' => ['salveaza' => $app->url()->cale('/admin/proprietate'), 'legal' => $app->url()->cale('/admin/conexiuni') . '#date-firma'],
            // harta care se trage pornește de la coordonatele salvate; fără ele, din CENTRU_HARTA, cu îndrumarea spre căutare.
            'harta_panou' => ['dale' => $app->url()->cale('/admin/harta/'), 'js' => $app->active()->nucleu()['admin/harta.js'] ?? '',
                'centru' => implode(',', self::CENTRU_HARTA), 'indrumare' => $pr['coordonate'] !== null ? 'admin.local.prop.harta_indrumare' : 'admin.local.prop.harta_indrumare_fara']];
        return AdminPanou::pagina($app, $u, 'local-proprietate', $d, $r);
    }

    /** Valorile formularului din datele salvate (orele, regulile casei ca „da” / „nu” / gol). */
    public static function valoriProprietate(array $pr, array $reg): array
    {
        $v = ['nume' => $pr['nume'], 'tip' => $pr['tip'], 'descriere_ro' => $pr['descriere']['ro'], 'descriere_en' => $pr['descriere']['en'],
            'strada' => $pr['adresa']['strada'], 'localitate' => $pr['adresa']['localitate'], 'judet' => $pr['adresa']['judet'],
            'lat' => $pr['coordonate'] !== null ? (string) $pr['coordonate']['lat'] : '', 'lng' => $pr['coordonate'] !== null ? (string) $pr['coordonate']['lng'] : '',
            'checkin_de_la' => (string) $pr['ore']['checkin_de_la'], 'checkin_pana_la' => (string) ($pr['ore']['checkin_pana_la'] ?? ''), 'plecare_pana_la' => (string) $pr['ore']['plecare_pana_la'],
            'email' => $pr['contact']['email'], 'telefon' => $pr['contact']['telefon'], 'animale_max' => $pr['reguli_casa']['animale_max'] !== null ? (string) $pr['reguli_casa']['animale_max'] : '',
            'sejur_minim' => (string) $reg['sejur_minim'], 'avans_procent' => (string) $reg['avans_procent'], 'anulare_zile' => (string) $reg['anulare_zile'],
            'termen_cerere_ore' => (string) $reg['termen_cerere_ore']];
        foreach (Depozit::REGULI_CASA as $x) {
            $v['regula_' . $x] = $pr['reguli_casa'][$x] === null ? '' : ($pr['reguli_casa'][$x] ? 'da' : 'nu');
        }
        return $v;
    }

    /**
     * Verifică formularul proprietății (panoul și instalatorul).
     * @return array{proprietate:array, reguli:array, valori:array, erori:array<string,string>} erorile: câmp => cheia textului
     */
    public static function citesteProprietate(Cerere $c, array $existent): array
    {
        $text = static fn (string $k, int $max = 191): string => mb_substr(trim(str_replace("\r", '', (string) ($c->camp($k) ?? ''))), 0, $max);
        $v = [];
        foreach (['nume', 'tip', 'strada', 'localitate', 'judet', 'lat', 'lng', 'checkin_de_la', 'checkin_pana_la', 'plecare_pana_la', 'email', 'telefon', 'animale_max', 'sejur_minim',
            'avans_procent', 'anulare_zile'] as $k) {
            $v[$k] = $text($k);
        }
        // „Timpul pentru răspuns la o cerere”; un formular fără câmp (instalatorul) lasă termenul salvat neatins.
        $cuTermen = $c->camp('termen_cerere_ore') !== null;
        $v['termen_cerere_ore'] = $text('termen_cerere_ore');
        $v['descriere_ro'] = $text('descriere_ro', 4000);
        $v['descriere_en'] = $text('descriere_en', 4000);
        foreach (Depozit::REGULI_CASA as $x) {
            $v['regula_' . $x] = in_array($c->camp('regula_' . $x), ['da', 'nu'], true) ? (string) $c->camp('regula_' . $x) : '';
        }
        $e = [];
        if ($v['nume'] === '') {
            $e['nume'] = 'admin.local.prop.eroare_nume';
        }
        if (!in_array($v['tip'], Depozit::TIPURI, true)) {
            $e['tip'] = 'admin.local.prop.eroare_tip';
        }
        if ($v['localitate'] === '') {
            $e['localitate'] = 'admin.local.prop.eroare_localitate';
        }
        $coord = null;
        $lat = str_replace(',', '.', $v['lat']);
        $lng = str_replace(',', '.', $v['lng']);
        if ($lat !== '' || $lng !== '') {
            if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
                $e['lat'] = 'admin.local.prop.eroare_coordonate';
            } else {
                $coord = ['lat' => (float) $lat, 'lng' => (float) $lng];
            }
        }
        $ora = '~^([01]\d|2[0-3]):[0-5]\d$~';
        foreach (['checkin_de_la' => true, 'plecare_pana_la' => true, 'checkin_pana_la' => false] as $k => $cerut) {
            if (($cerut || $v[$k] !== '') && !preg_match($ora, $v[$k])) {
                $e[$k] = 'admin.local.prop.eroare_ora';
            }
        }
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            $e['email'] = 'admin.local.prop.eroare_email';
        }
        $am = null;
        if ($v['animale_max'] !== '') {
            $am = preg_match('~^\d{1,2}$~', $v['animale_max']) && (int) $v['animale_max'] >= 1 && (int) $v['animale_max'] <= 20 ? (int) $v['animale_max'] : null;
            if ($am === null) {
                $e['animale_max'] = 'admin.local.prop.eroare_animale';
            }
        }
        $int = static fn (string $x, int $min, int $max): ?int => preg_match('~^\d{1,3}$~', $x) && (int) $x >= $min && (int) $x <= $max ? (int) $x : null;
        $sejur = $int($v['sejur_minim'], 1, Depozit::SEJUR_MINIM_MAX);
        $avans = $int($v['avans_procent'], 0, 100);
        $anulare = $int($v['anulare_zile'], 0, Depozit::ANULARE_MAX);
        foreach (['sejur_minim' => [$sejur, 'admin.local.reg.eroare_sejur'], 'avans_procent' => [$avans, 'admin.local.reg.eroare_avans'], 'anulare_zile' => [$anulare, 'admin.local.reg.eroare_anulare']] as $k => [$x, $cheie]) {
            if ($x === null) {
                $e[$k] = $cheie;
            }
        }
        $termen = $cuTermen ? $int($v['termen_cerere_ore'], Depozit::TERMEN_CERERE_MIN, Depozit::TERMEN_CERERE_MAX) : null;
        if ($cuTermen && $termen === null) {
            $e['termen_cerere_ore'] = 'admin.local.reg.eroare_termen';
        }
        $reguli = [];
        foreach (Depozit::REGULI_CASA as $x) {
            $reguli[$x] = $v['regula_' . $x] === '' ? null : $v['regula_' . $x] === 'da';
        }
        $reguli['animale_max'] = $am;
        $p = ['nume' => $v['nume'], 'tip' => $v['tip'], 'descriere' => ['ro' => $v['descriere_ro'], 'en' => $v['descriere_en']],
            'adresa' => ['strada' => $v['strada'], 'localitate' => $v['localitate'], 'judet' => $v['judet']], 'coordonate' => $coord,
            'ore' => ['checkin_de_la' => $v['checkin_de_la'], 'checkin_pana_la' => $v['checkin_pana_la'] !== '' ? $v['checkin_pana_la'] : null, 'plecare_pana_la' => $v['plecare_pana_la']],
            'reguli_casa' => $reguli, 'contact' => ['email' => $v['email'], 'telefon' => $v['telefon']]];
        // Ce a venit din EasyCheck la trecere (pozele, clasificările, notele de pe platforme) rămâne neatins.
        foreach (['clasificari', 'poze', 'linkuri_platforme', 'note_platforme', 'spatii_comune'] as $k) {
            $p[$k] = $existent[$k] ?? null;
        }
        return ['proprietate' => $p, 'reguli' => ['sejur_minim' => $sejur ?? 1, 'avans_procent' => $avans ?? 0, 'anulare_zile' => $anulare ?? 7]
            + ($termen !== null ? ['termen_cerere_ore' => $termen] : []), 'valori' => $v, 'erori' => $e];
    }

    /**
     * Punctul de pe hartă după adresa scrisă (Nominatim, prin DateExterne\Locuri, cu limitele lui): coordonatele primului
     * rezultat, sau null.
     */
    public static function cautaPeHarta(Aplicatie $app, array $adresa): ?array
    {
        $text = implode(', ', array_filter([trim((string) ($adresa['strada'] ?? '')), trim((string) ($adresa['localitate'] ?? '')), trim((string) ($adresa['judet'] ?? '')), 'România'], static fn (string $x): bool => $x !== ''));
        $r = $app->locuri()->cauta($text, null, null, 'ro');
        $x = $r['rezultate'][0] ?? null;
        return $r['stare'] === 'ok' && is_array($x) ? ['lat' => round((float) $x['lat'], 6), 'lng' => round((float) $x['lng'], 6)] : null;
    }

    /**
     * GET /admin/harta/{z}/{x}/{y}.png: o dală a hărții de pe „Proprietatea”, doar cu sesiunea panoului (oricare
     * utilizator al panoului; pagina rămâne a proprietarului). Din cache, sau descărcată prin server, cu frânele din
     * DateExterne\Dale; fără sesiune 403, în afara grilei 404, o frână plină sau pauza OSM 429 cu Retry-After.
     */
    public static function dala(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return Raspuns::text('', 403);
        }
        $r = $app->dale()->dala((int) $p['z'], (int) $p['x'], (int) $p['y'], (int) $s[0]['id'], \Cazo\Securitate\Ip::trunchiaza($c->ip));
        if (isset($r['png'])) {
            return (new Raspuns(200, $r['png']))->antet('Content-Type', 'image/png')->antet('Cache-Control', 'private, max-age=86400');
        }
        if ($r['stare'] === 'limita' || $r['stare'] === 'pauza') {
            return Raspuns::text('', 429)->antet('Retry-After', (string) ($r['dupa'] ?? 60));
        }
        return Raspuns::text('', $r['stare'] === 'invalida' ? 404 : 502);
    }

    public static function proprietateSalveaza(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = self::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        if (!$app->modLocal()) {
            return AdminPanou::inapoi($app, '/admin/conexiuni', ['eroare' => 'mod']);
        }
        [$u] = $s;
        $d = $app->localDepozit();
        $f = self::citesteProprietate($c, $d->proprietate());
        $mesaj = 'proprietate';
        if ($c->camp('actiune') === 'harta' && !isset($f['erori']['localitate'])) {
            $gasit = self::cautaPeHarta($app, $f['proprietate']['adresa']);
            if ($gasit !== null) {
                $f['proprietate']['coordonate'] = $gasit;
                unset($f['erori']['lat']);
                $mesaj = 'harta';
            } else {
                $mesaj = 'harta_negasita';
            }
        }
        if ($f['erori'] !== []) {
            $app->auditDetalii(['eroare' => implode(',', array_keys($f['erori']))]);
            return self::proprietate($app, $c, $p, $limba, $f['valori'], $f['erori']);
        }
        $d->salveazaProprietate($f['proprietate'], (int) $u['id']);
        $d->salveazaReguli($f['reguli'], (int) $u['id']);
        $app->localDupaScriere(['eu', 'proprietate', 'preturi']);
        $app->ecOglinda()->schimbaContinutul();
        $app->modPlata()->evalueaza();
        return AdminPanou::inapoi($app, '/admin/proprietate', ['mesaj' => $mesaj]);
    }

    // ---- Camerele și prețurile ----

    /**
     * @param ?array{valori:array<string,string>, erori:array<string,string>} $reduceri formularul reducerilor trimis cu
     *        greșeli, reafișat cu valorile scrise și erorile lângă câmpuri
     */
    public static function camere(Aplicatie $app, Cerere $c, array $p, string $limba, ?string $gresita = null, array $valori = [], array $erori = [], ?array $reduceri = null): Raspuns
    {
        $s = self::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        // în asistentul trecerii, camerele pregătite din EasyCheck se verifică și se schimbă aici, înainte de trecere.
        if (!$app->modLocal() && !$app->localTrecere()->asistentDeschis()) {
            return AdminPanou::inapoi($app, '/admin/conexiuni');
        }
        [$u, $jeton] = $s;
        $t = $app->traducator((string) $u['limba_panou'], 'admin');
        $r = Raspuns::html('', $gresita !== null || $reduceri !== null ? 422 : 200);
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'camere_site', self::MESAJE, self::ERORI);
        $local = [];
        foreach ($app->baza()->toate('SELECT * FROM {camere_local}') as $x) {
            $local[(string) $x['camera']] = $x;
        }
        $lista = [];
        foreach ($app->localDepozit()->camere() as $cam) {
            $lista[] = self::valoriCamera($cam, $local[$cam['id']] ?? []) + ['id' => $cam['id'], 'de_verificat' => $cam['de_verificat'], 'erori' => []];
        }
        $nou = self::valoriCamera(null, []) + ['id' => 'noua', 'de_verificat' => false, 'erori' => []];
        $err = array_map(static fn (string $k): string => $t->t($k), $erori);
        foreach ($lista as $i => $x) {
            if ($x['id'] === $gresita) {
                $lista[$i] = array_replace($x, $valori, ['erori' => $err]);
            }
        }
        if ($gresita === 'noua') {
            $nou = array_replace($nou, $valori, ['erori' => $err]);
        }
        if ($gresita !== null || $reduceri !== null) {
            $d['eroare'] = $t->t('admin.local.eroare.campuri');
        }
        $red = $app->localDepozit()->reduceri();
        $d['reduceri'] = ['v' => $reduceri['valori'] ?? ['lung_procent' => (string) ($red['lung']['procent'] ?? ''), 'lung_nopti' => (string) ($red['lung']['nopti'] ?? ''),
            'timp_procent' => (string) ($red['timp']['procent'] ?? ''), 'timp_zile' => (string) ($red['timp']['zile'] ?? '')],
            'erori' => array_map(static fn (string $k): string => $t->t($k), $reduceri['erori'] ?? []), 'url' => $app->url()->cale('/admin/reduceri-site')];
        $d += ['camere' => $lista, 'noua' => $nou, 'de_verificat' => $app->localDepozit()->deVerificat() !== [], 'poate_adauga' => count($lista) < Depozit::CAMERE_MAX,
            'asistent' => $app->localTrecere()->asistentDeschis(),
            'url' => ['baza' => $app->url()->cale('/admin/camere-site/'), 'poze' => $app->url()->cale('/admin/continut') . '#poze', 'proprietate' => $app->url()->cale('/admin/proprietate'),
                'asistent' => $app->url()->cale('/admin/trecere', null, ['pas' => 1])]];
        return AdminPanou::pagina($app, $u, 'local-camere', $d, $r);
    }

    private static function valoriCamera(?array $cam, array $local): array
    {
        $titlu = json_decode((string) ($local['titlu'] ?? ''), true) ?: [];
        $desc = json_decode((string) ($local['descriere'] ?? ''), true) ?: [];
        return ['titlu' => (string) ($cam['titlu'] ?? ''), 'titlu_en' => (string) ($titlu['en'] ?? ''), 'unitati' => (string) ($cam['unitati'] ?? 1),
            'oaspeti_max' => $cam !== null ? (string) $cam['oaspeti_max'] : '', 'pret_noapte' => $cam !== null && $cam['pret_noapte'] > 0 ? (string) intdiv($cam['pret_noapte'], 100) : '',
            'pret_weekend' => $cam !== null && $cam['pret_weekend'] > 0 ? (string) intdiv($cam['pret_weekend'], 100) : '',
            'descriere_ro' => (string) ($desc['ro'] ?? ''), 'descriere_en' => (string) ($desc['en'] ?? '')];
    }

    /**
     * Salvează o cameră din formular (panoul și instalatorul): datele ei în `local_camere`, titlul în engleză și
     * descrierile în `camere_local`, ca la camerele din EasyCheck (temele le citesc de acolo).
     * @return array{id:?string, valori:array, erori:array<string,string>}
     */
    public static function salveazaCameraDin(Aplicatie $app, Cerere $c, ?string $id): array
    {
        $text = static fn (string $k, int $max): string => mb_substr(trim(str_replace("\r", '', (string) ($c->camp($k) ?? ''))), 0, $max);
        $f = Depozit::valideazaCamera(['titlu' => $c->camp('titlu'), 'unitati' => $c->camp('unitati') ?? '1', 'oaspeti_max' => $c->camp('oaspeti_max'),
            'pret_noapte' => $c->camp('pret_noapte'), 'pret_weekend' => $c->camp('pret_weekend')]);
        $valori = ['titlu' => $text('titlu', 120), 'titlu_en' => $text('titlu_en', 120), 'unitati' => $text('unitati', 6), 'oaspeti_max' => $text('oaspeti_max', 6),
            'pret_noapte' => $text('pret_noapte', 12), 'pret_weekend' => $text('pret_weekend', 12), 'descriere_ro' => $text('descriere_ro', 2000), 'descriere_en' => $text('descriere_en', 2000)];
        $coduri = ['titlu' => 'admin.local.cam.eroare_titlu', 'lungime' => 'admin.local.cam.eroare_lungime', 'unitati' => 'admin.local.cam.eroare_unitati',
            'oaspeti' => 'admin.local.cam.eroare_oaspeti', 'pret' => 'admin.local.cam.eroare_pret'];
        $erori = array_map(static fn (string $x): string => $coduri[$x], $f['erori']);
        if ($erori !== []) {
            return ['id' => null, 'valori' => $valori, 'erori' => $erori];
        }
        $id = $app->localDepozit()->salveazaCamera($id, $f['valori']);
        $titlu = array_filter(['en' => $valori['titlu_en']], static fn (string $x): bool => $x !== '');
        $desc = array_filter(['ro' => $valori['descriere_ro'], 'en' => $valori['descriere_en']], static fn (string $x): bool => $x !== '');
        $app->baza()->upsert('camere_local', ['camera' => $id, 'titlu' => $titlu !== [] ? json_encode($titlu, JSON_UNESCAPED_UNICODE) : null,
            'descriere' => $desc !== [] ? json_encode($desc, JSON_UNESCAPED_UNICODE) : null], ['camera'], ['titlu', 'descriere']);
        $app->localDupaScriere(['camere', 'disponibilitate', 'preturi']);
        $app->ecOglinda()->schimbaContinutul();
        return ['id' => $id, 'valori' => $valori, 'erori' => []];
    }

    public static function cameraSalveaza(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = self::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        if (!$app->modLocal() && !$app->localTrecere()->asistentDeschis()) {
            return AdminPanou::inapoi($app, '/admin/conexiuni', ['eroare' => 'mod']);
        }
        $id = (string) $p['camera'];
        if ($id === 'noua' && count($app->localDepozit()->camere()) >= Depozit::CAMERE_MAX) {
            return AdminPanou::inapoi($app, '/admin/camere-site', ['eroare' => 'limita']);
        }
        if ($id !== 'noua' && $app->localDepozit()->camera($id) === null) {
            return AdminPanou::inapoi($app, '/admin/camere-site', ['eroare' => 'camera']);
        }
        $r = self::salveazaCameraDin($app, $c, $id === 'noua' ? null : $id);
        if ($r['erori'] !== []) {
            $app->auditDetalii(['eroare' => implode(',', array_keys($r['erori']))]);
            return self::camere($app, $c, $p, $limba, $id, $r['valori'], $r['erori']);
        }
        $app->auditDetalii(['camera' => (string) $r['id']]);
        return AdminPanou::inapoi($app, '/admin/camere-site', ['mesaj' => $id === 'noua' ? 'camera_noua' : 'camera']);
    }

    /**
     * Verifică formularul reducerilor: procentul gol oprește reducerea; scris, cere și pragul ei.
     * @return array{reduceri:array, valori:array<string,string>, erori:array<string,string>} erorile: câmp => cheia textului
     */
    public static function citesteReduceri(Cerere $c): array
    {
        $v = [];
        foreach (['lung_procent', 'lung_nopti', 'timp_procent', 'timp_zile'] as $k) {
            $v[$k] = mb_substr(trim(str_replace('%', '', (string) ($c->camp($k) ?? ''))), 0, 6);
        }
        $int = static fn (string $x, int $min, int $max): ?int => preg_match('~^\d{1,3}$~', $x) && (int) $x >= $min && (int) $x <= $max ? (int) $x : null;
        $e = [];
        $rez = ['lung' => null, 'timp' => null];
        foreach (['lung' => ['nopti', Depozit::REDUCERE_NOPTI_MIN, Depozit::REDUCERE_NOPTI_MAX], 'timp' => ['zile', 1, Depozit::REDUCERE_ZILE_MAX]] as $fel => [$prag, $min, $max]) {
            if ($v[$fel . '_procent'] === '') {
                continue;
            }
            $procent = $int($v[$fel . '_procent'], 1, Depozit::REDUCERE_PROCENT_MAX);
            $n = $int($v[$fel . '_' . $prag], $min, $max);
            if ($procent === null) {
                $e[$fel . '_procent'] = 'admin.local.red.eroare_procent';
            }
            if ($n === null) {
                $e[$fel . '_' . $prag] = 'admin.local.red.eroare_' . $prag;
            }
            if ($procent !== null && $n !== null) {
                $rez[$fel] = ['procent' => $procent, $prag => $n];
            }
        }
        return ['reduceri' => $rez, 'valori' => $v, 'erori' => $e];
    }

    /** POST /admin/reduceri-site: cele două reduceri ale proprietății, pe pagina Camerele și prețurile. */
    public static function reduceriSalveaza(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = self::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        if (!$app->modLocal()) {
            return AdminPanou::inapoi($app, '/admin/conexiuni', ['eroare' => 'mod']);
        }
        [$u] = $s;
        $f = self::citesteReduceri($c);
        if ($f['erori'] !== []) {
            $app->auditDetalii(['eroare' => implode(',', array_keys($f['erori']))]);
            return self::camere($app, $c, $p, $limba, null, [], [], ['valori' => $f['valori'], 'erori' => $f['erori']]);
        }
        $app->localDepozit()->salveazaReduceri($f['reduceri'], (int) $u['id']);
        $app->auditDetalii(['lung' => $f['reduceri']['lung'], 'timp' => $f['reduceri']['timp']]);
        $app->localDupaScriere(['preturi']);
        return AdminPanou::inapoi($app, '/admin/camere-site', ['mesaj' => 'reduceri']);
    }

    public static function cameraRetrage(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = self::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        if (!$app->modLocal() || $app->localDepozit()->camera((string) $p['camera']) === null) {
            return AdminPanou::inapoi($app, '/admin/camere-site', ['eroare' => 'camera']);
        }
        if (!$app->localDepozit()->retrageCamera((string) $p['camera'], $app->azi())) {
            return AdminPanou::inapoi($app, '/admin/camere-site', ['eroare' => 'rezervari']);
        }
        $app->localDupaScriere(['camere', 'disponibilitate', 'preturi']);
        $app->ecOglinda()->schimbaContinutul();
        return AdminPanou::inapoi($app, '/admin/camere-site', ['mesaj' => 'retrasa']);
    }

    // ---- Calendarul ----

    public static function calendar(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = self::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        if (!$app->modLocal()) {
            return AdminPanou::inapoi($app, '/admin/conexiuni');
        }
        [$u, $jeton] = $s;
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'calendar', self::MESAJE, self::ERORI);
        $e = (string) ($c->param('ical') ?? '');
        if (in_array($e, EroareIcal::CODURI, true)) {
            $d['eroare'] = $t->t('admin.local.ical.eroare.' . $e);
        }
        $cal = $app->localIcal();
        $surse = [];
        foreach ($cal->surse() as $x) {
            $x['citita'] = Calendare::cand($x['reusita_la'], $l);
            $x['eroare_text'] = $x['eroare'] !== null && in_array($x['eroare'], EroareIcal::CODURI, true) ? $t->t('admin.local.ical.eroare.' . $x['eroare']) : null;
            $surse[$x['camera']][] = $x;
        }
        $camere = [];
        foreach ($app->localDepozit()->camere() as $cam) {
            $camere[] = ['id' => $cam['id'], 'titlu' => $cam['titlu'], 'export' => $cal->adresa($cam['id']), 'surse' => $surse[$cam['id']] ?? []];
        }
        $exporturi = self::adreseExport($app, $t);
        $titluri = array_column($camere, 'titlu', 'id');
        $inchise = array_map(static fn (array $x): array => $x + ['camera_titlu' => $x['camera'] === '*' ? $t->t('admin.local.inchise.toate') : ($titluri[$x['camera']] ?? $x['camera']),
            'perioada' => $x['de'] === $x['pana'] ? Format::zi($x['de'], $l) : Format::zi($x['de'], $l) . ' - ' . Format::zi($x['pana'], $l)], $app->localDepozit()->perioadeInchise($app->azi()));
        $d += ['camere' => $camere, 'exporturi' => $exporturi, 'are_unitati' => in_array(true, array_map(static fn (array $x): bool => $x['unitate'] !== null, $exporturi), true), 'inchise' => $inchise, 'platforme' => self::PLATFORME, 'interval' => Calendare::interval($app->setari()), 'intervale' => [5, 10, 15, 30, 60],
            'azi' => $app->azi(), 'adresa_ec_exemplu' => $camere !== [] ? $camere[0]['export'] : '',
            'url' => ['baza' => $app->url()->cale('/admin/calendar/'), 'camere' => $app->url()->cale('/admin/camere-site')]];
        return AdminPanou::pagina($app, $u, 'local-calendar', $d, $r);
    }

    public static function calendarActiune(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = self::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        if (!$app->modLocal()) {
            return AdminPanou::inapoi($app, '/admin/conexiuni', ['eroare' => 'mod']);
        }
        [$u] = $s;
        $inapoi = static fn (array $q, string $ancora = ''): Raspuns => Raspuns::redirectare($app->url()->cale('/admin/calendar', null, $q) . $ancora);
        $a = (string) $p['actiune'];
        $cal = $app->localIcal();
        if ($a === 'adauga') {
            $plat = (string) ($c->camp('platforma') ?? '');
            $nume = array_key_exists($plat, self::PLATFORME) ? (self::PLATFORME[$plat] ?? trim((string) ($c->camp('nume') ?? ''))) : '';
            try {
                $r = $cal->adauga($nume !== '' ? $nume : 'iCal', (string) ($c->camp('url') ?? ''), (string) ($c->camp('camera') ?? ''));
            } catch (EroareIcal $e) {
                $app->auditDetalii(['eroare' => $e->cod]);
                return $inapoi(['ical' => $e->cod], '#calendare');
            }
            $app->auditDetalii(['platforma' => $plat, 'stare' => $r['citire']['stare']]);
            return $r['citire']['stare'] === 'eroare' ? $inapoi(['mesaj' => 'ical_adaugat', 'ical' => (string) ($r['citire']['eroare'] ?? 'forma')], '#calendare')
                : $inapoi(['mesaj' => 'ical_adaugat'], '#calendare');
        }
        if ($a === 'citeste' || $a === 'sterge') {
            $id = (int) ($c->camp('sursa') ?? 0);
            if ($a === 'sterge') {
                $cal->sterge($id);
                return $inapoi(['mesaj' => 'ical_sters'], '#calendare');
            }
            $r = $cal->citesteUna($id);
            return $r['stare'] === 'eroare' ? $inapoi(['ical' => (string) ($r['eroare'] ?? 'forma')], '#calendare') : $inapoi(['mesaj' => 'ical_citit'], '#calendare');
        }
        if ($a === 'inchide' || $a === 'deschide') {
            $camera = (string) ($c->camp('camera') ?? '');
            $de = (string) ($c->camp('de') ?? '');
            $pana = (string) ($c->camp('pana') ?? '') ?: $de;
            $ok = ($camera === '*' || $app->localDepozit()->camera($camera) !== null) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $de) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $pana)
                && $de <= $pana && $de >= gmdate('Y-m-d', strtotime($app->azi() . ' UTC') - 86400);
            if (!$ok || $app->localDepozit()->inchide($camera, $de, $pana, $a === 'inchide') === 0) {
                return $inapoi(['eroare' => 'perioada'], '#inchise');
            }
            $app->localDupaScriere(['disponibilitate', 'preturi']);
            return $inapoi(['mesaj' => $a === 'inchide' ? 'inchise' : 'deschise'], '#inchise');
        }
        if ($a === 'jeton') {
            if ($c->camp('confirm') !== '1') {
                return $inapoi(['eroare' => 'confirmare'], '#export');
            }
            $cal->schimbaJetonul((int) $u['id']);
            return $inapoi(['mesaj' => 'jeton'], '#export');
        }
        if ($a === 'interval') {
            $m = (int) ($c->camp('minute') ?? 15);
            $app->setari()->pune(Calendare::INTERVAL, in_array($m, [5, 10, 15, 30, 60], true) ? $m : 15, (int) $u['id']);
            return $inapoi(['mesaj' => 'interval'], '#calendare');
        }
        return $inapoi([]);
    }

    /** GET /calendar/{jeton}/{camera}.ics: calendarul site-ului pentru o cameră (adresa secretă pusă pe platforme). */
    public static function ics(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        // adresa merge și cât gazda o pune pe platforme, în asistentul trecerii (site-ul încă pe EasyCheck).
        $text = self::exportPermis($app) ? $app->localIcal()->exporta((string) $p['jeton'], (string) $p['camera']) : null;
        return self::raspunsIcs($text);
    }

    /** Calendarul unei unități: `/calendar/<jeton>/<camera>-<unitate>.ics`. */
    public static function icsUnitate(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $text = self::exportPermis($app) ? $app->localIcal()->exportaUnitate((string) $p['jeton'], (string) $p['camera'], (int) $p['unitate']) : null;
        return self::raspunsIcs($text);
    }

    private static function exportPermis(Aplicatie $app): bool
    {
        return $app->modLocal() || $app->localTrecere()->asistentDeschis();
    }

    /**
     * Adresele calendarului site-ului pentru panou (pagina Calendarul și pașii 2-3 ai asistentului), cu eticheta
     * „Camera, unitatea N” la camerele cu mai multe unități.
     * @return list<array{camera:string, titlu:string, unitate:?int, eticheta:string, url:string}>
     */
    public static function adreseExport(Aplicatie $app, Traducator $t): array
    {
        return array_map(static fn (array $x): array => $x + ['eticheta' => $x['unitate'] === null ? $x['titlu'] : $t->t('admin.local.export.unitate', ['camera' => $x['titlu'], 'n' => (string) $x['unitate']])],
            $app->localIcal()->adrese());
    }

    private static function raspunsIcs(?string $text): Raspuns
    {
        if ($text === null) {
            return Raspuns::text('Not Found', 404);
        }
        $r = new Raspuns(200, $text);
        $r->antet('Content-Type', 'text/calendar; charset=utf-8');
        $r->antet('Cache-Control', 'no-store');
        $r->antet('X-Robots-Tag', 'noindex');
        return $r;
    }

    // ---- Legătura cu EasyCheck: trecerea dintr-un mod în altul ----

    /** Datele blocului de pe „Legătura cu EasyCheck”. */
    public static function dateLegatura(Aplicatie $app, Traducator $t, string $l): array
    {
        $trecere = [];
        $ec = [];
        if (!$app->modLocal()) {
            foreach ((array) ($app->ecOglinda()->corp('camere')['camere'] ?? []) as $x) {
                if (is_array($x) && is_string($x['id'] ?? null)) {
                    $ec[] = ['id' => $x['id'], 'titlu' => (string) ($x['titlu'] ?? $x['id'])];
                }
            }
            foreach ($app->localTrecere()->stari() as $x) {
                $cod = (string) ($x['cod'] ?? '');
                $trecere[] = ['ref' => $x['ref'], 'stare' => $t->t('admin.local.trecere.stare.' . $x['stare']), 'clasa' => match ($x['stare']) {
                    'trimisa', 'renuntata' => 'pa-stare-ok', 'esuata' => 'pa-stare-problema', default => 'pa-stare-asteapta' },
                    'motiv' => $cod === '' ? '' : ($t->are('admin.local.trecere.cod.' . $cod) ? $t->t('admin.local.trecere.cod.' . $cod) : $t->t('admin.local.trecere.cod.altul', ['cod' => $cod]))];
            }
        }
        $mapare = (array) $app->setari()->get(\Cazo\Local\Trecere::MAPARE, []);
        $camereLocale = [];
        if ($trecere !== []) {
            foreach ($app->localDepozit()->camere(true) as $c) {
                $camereLocale[] = ['id' => $c['id'], 'titlu' => $c['titlu'], 'ales' => $mapare[$c['id']] ?? null];
            }
        }
        return ['local' => $app->modLocal(), 'are_cheie_salvata' => $app->secrete()->citeste('ec_cheie') !== null, 'trecere' => $trecere, 'camere_ec' => $ec, 'camere_locale' => $camereLocale,
            'are_esuate' => in_array('pa-stare-problema', array_column($trecere, 'clasa'), true),
            'url_legatura' => $app->url()->cale('/admin/legatura/'), 'url_proprietate' => $app->url()->cale('/admin/proprietate'), 'url_camere' => $app->url()->cale('/admin/camere-site'),
            'url_calendar' => $app->url()->cale('/admin/calendar'),
            'asistent' => !$app->modLocal() && $app->localTrecere()->asistentDeschis() ? ['pas' => $app->localTrecere()->pasCurent()] : null,
            'anunt_esuat' => $app->localTrecere()->anuntEsuat(),
            'url_trecere' => $app->url()->cale('/admin/trecere'), 'url_trecere_incepe' => $app->url()->cale('/admin/trecere/incepe')];
    }

    public static function legatura(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = self::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $inapoi = static fn (array $q, string $cale = '/admin/conexiuni'): Raspuns => AdminPanou::inapoi($app, $cale, $q);
        $a = (string) $p['actiune'];
        $tr = $app->localTrecere();
        if ($a === 'camere') {
            $tr->salveazaMapare((array) ($c->camp('camera') ?? []), (int) $u['id']);
            $tr->trimite(20);
            return $inapoi(['mesaj' => 'camere_mapate']);
        }
        if ($a === 'retrimite') {
            $app->baza()->executa("UPDATE {local_trecere} SET stare = 'de_trimis', cod = NULL, incercari = 0 WHERE stare = 'esuata'");
            $tr->trimite(20);
            return $inapoi(['mesaj' => 'retrimise']);
        }
        // Trecerea spre EasyCheck schimbă de unde vin datele site-ului: cere bifa de confirmare și parola. Trecerea în
        // sens invers trece prin asistent (AdminTrecere).
        if ($c->camp('confirm') !== '1') {
            return $inapoi(['eroare' => 'confirmare']);
        }
        if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
            return $inapoi(['eroare' => 'reauth']);
        }
        if ($a === 'spre-easycheck') {
            if (!$app->modLocal()) {
                return $inapoi(['eroare' => 'mod']);
            }
            $cheie = trim((string) ($c->camp('cheie') ?? ''));
            if ($cheie !== '' && !\Cazo\EasyCheck\Conectare::formaCheii($cheie)) {
                return $inapoi(['eroare' => 'cheie_forma']);
            }
            try {
                $rez = $tr->spreEasyCheck($cheie !== '' ? $cheie : null, (int) $u['id']);
            } catch (EroareApi $e) {
                $app->auditDetalii(['cod' => (string) ($e->cod ?? 'http_' . $e->raspuns->status)]);
                return $inapoi(['eroare' => 'cheie']);
            } catch (EroareTransport) {
                return $inapoi(['eroare' => 'retea']);
            } catch (\InvalidArgumentException) {
                return $inapoi(['eroare' => 'cheie_forma']);
            } catch (\UnexpectedValueException) {
                return $inapoi(['eroare' => 'citire']);
            }
            $app->auditDetalii(['mod' => (string) ($rez['mod'] ?? ''), 'trimise' => (int) ($rez['trimise']['trimise'] ?? 0), 'esuate' => (int) ($rez['trimise']['esuate'] ?? 0)]);
            $app->modPlata()->evalueaza();
            return $inapoi(['mesaj' => 'spre_ec']);
        }
        return $inapoi([]);
    }

    /** Adresa scurtă a unui calendar, pentru panou (doar gazda și începutul căii). */
    public static function adresaScurta(string $url): string
    {
        return AdresaSigura::scurta($url);
    }
}
