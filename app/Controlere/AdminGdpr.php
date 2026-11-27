<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\Gdpr\Gdpr;
use Cazo\I18n\Format;
use Cazo\Legal\BlocLegal;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;

defined('CAZO') or exit;

/**
 * Datele oaspeților, Datele firmei și publicarea site-ului, doar pentru proprietar:
 *
 *   GET  /admin/gdpr                termenele de păstrare (instalatorul le cere, aici se schimbă) și căutarea
 *   POST /admin/gdpr/termene        cele patru termene, între praguri, cu re-autentificarea; la o greșeală, pagina
 *                                   revine cu valorile scrise și cu eroarea lângă câmp (422)
 *   POST /admin/gdpr/alerte         cât păstrează jurnalul alertelor (mutat aici de pe pagina Alerte)
 *   POST /admin/gdpr/cauta          căutarea după e-mail (prin POST: adresa rămâne în afara adreselor paginilor)
 *   POST /admin/gdpr/export         exportul JSON al datelor oaspetelui, cu re-autentificarea
 *   POST /admin/gdpr/sterge         ștergerea (anonimizarea comenzilor încheiate), cu re-autentificarea și confirmarea;
 *                                   rezervările EasyCheck ale comenzilor șterse pleacă după răspuns
 *   GET  /admin/legal               datele operatorului din EasyCheck (citire) și completările locale, alerta datelor lipsă
 *   POST /admin/legal               completările locale (API-ul are prioritate)
 *   POST /admin/legal/noutati       exportul acordurilor pentru noutăți, cu re-autentificarea
 *   GET  /admin/site                publicarea: pagina unică, în Publicare
 *   POST /admin/site/{actiune}      publica | retrage (Publicare::actiune)
 */
final class AdminGdpr
{
    public const MESAJE = ['salvat' => 'admin.salvat', 'sters' => 'admin.gdpr.sters', 'publicat' => 'admin.site.publicat_mesaj', 'retras' => 'admin.site.retras_mesaj'];
    public const ERORI = ['reauth' => 'admin.reauth.eroare', 'confirmare' => 'admin.gdpr.eroare_confirmare', 'email' => 'admin.gdpr.eroare_email',
        'confirmare_publicare' => 'admin.site.eroare_confirmare', 'alerte' => 'admin.alerte.eroare_pastrare'];

    /** Mesajele paginilor „Datele oaspeților” și „Datele firmei”: fiecare spune ce s-a salvat. */
    public const MESAJE_GDPR = ['salvat' => 'admin.gdpr.termene_salvate'] + self::MESAJE;
    public const MESAJE_LEGAL = ['salvat' => 'admin.legal.salvat', 'acces_publicata' => 'admin.legal.accesibilitate_publicata', 'acces_ascunsa' => 'admin.legal.accesibilitate_retrasa',
        'telefon_aratat' => 'admin.legal.telefon_aratat', 'telefon_ascuns' => 'admin.legal.telefon_ascuns'] + self::MESAJE;

    public static function gdpr(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'gdpr', self::MESAJE_GDPR, self::ERORI);
        return self::paginaGdpr($app, $u, $d, $r, null, null);
    }

    /**
     * @param ?array<string,string> $scrise termenele scrise în formularul întors după o greșeală
     * @param list<string> $gresite cheile câmpurilor greșite
     * @param array<string,mixed> $extra `email_gresit` (căutarea) și `eroare_la` (formularul cu greșeala: `export`, `sterge`, `confirmare`, `noutati`)
     */
    private static function paginaGdpr(Aplicatie $app, array $u, array $d, Raspuns $r, ?string $email, ?array $gasite, ?array $scrise = null, array $gresite = [], array $extra = []): Raspuns
    {
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $termene = $app->gdpr()->termene();
        $afara = $app->gdpr()->termeneInAfara();
        // câmpurile numerice vin completate: cu ce a scris gazda (după o greșeală), cu termenele în vigoare sau
        // cu valorile propuse; explicația spune pragurile și valoarea recomandată, cu durata ei pe înțeles (Gdpr::durata).
        $public = $app->traducator($l);
        $campuri = [];
        foreach (Gdpr::LIMITE as $k => [$min, $max]) {
            $campuri[] = ['cheie' => $k, 'min' => $min, 'max' => $max, 'propus' => Gdpr::PROPUSE[$k], 'unitate' => Gdpr::UNITATI[$k],
                'durata_propus' => Gdpr::durata($public, $k, Gdpr::PROPUSE[$k]),
                'valoare' => (string) ($scrise[$k] ?? $termene[$k] ?? Gdpr::PROPUSE[$k]),
                'eroare' => in_array($k, $gresite, true),
                // O valoare salvată înaintea pragurilor: rămâne în vigoare până o schimbă gazda.
                'in_afara' => $scrise === null && isset($afara[$k]) ? $public->plural('unitate.' . Gdpr::UNITATI[$k], $afara[$k]) : null];
        }
        // ștergerile trimise la EasyCheck, cu rezultatul și textul pentru oaspete; codurile interne, doar pentru suport.
        $stergeri = $app->gdprEasyCheck()->recente($l);
        foreach ($stergeri as $i => $x) {
            $stari = array_column((array) $x['randuri'], 'stare');
            $stergeri[$i]['stare'] = in_array('de_facut', $stari, true) ? 'de_facut' : (in_array('in_lucru', $stari, true) ? 'in_lucru' : 'gata');
            $stergeri[$i]['suport'] = $app->baza()->toate('SELECT ref, ec_rezervare, cod FROM {gdpr_easycheck} WHERE stergere_id = ? ORDER BY id', [(int) $x['id']]);
        }
        $d += $extra + [
            'termene' => $termene,
            'campuri' => $campuri,
            'in_afara' => $afara !== [],
            'alerte_zile' => ['valoare' => $app->gdpr()->alerteZile(), 'min' => Gdpr::ALERTE_LIMITE[0], 'max' => Gdpr::ALERTE_LIMITE[1]],
            'email' => $email,
            'email_gresit' => false,
            'eroare_la' => null,
            'gasite' => $gasite,
            'stergeri' => $stergeri,
            // acordurile pentru noutăți, mutate aici de pe „Datele firmei”.
            'noutati' => (int) $app->baza()->valoare('SELECT COUNT(*) FROM {comenzi} WHERE marketing_acord = 1 AND marketing_retras_la IS NULL AND anonimizat_la IS NULL'),
            'url' => ['termene' => $app->url()->cale('/admin/gdpr/termene'), 'alerte' => $app->url()->cale('/admin/gdpr/alerte'), 'cauta' => $app->url()->cale('/admin/gdpr/cauta'),
                'export' => $app->url()->cale('/admin/gdpr/export'), 'sterge' => $app->url()->cale('/admin/gdpr/sterge'), 'noutati' => $app->url()->cale('/admin/legal/noutati')],
        ];
        return AdminPanou::pagina($app, $u, 'gdpr', $d, $r);
    }

    /** Rezervările găsite după adresa de e-mail, pentru lista de sub căutare. @return list<array> */
    private static function gasite(Aplicatie $app, string $email, string $l): array
    {
        $t = $app->traducator($l, 'admin');
        $gasite = [];
        foreach ($app->gdpr()->comenziDupaEmail($email) as $cm) {
            $gasite[] = ['ref' => (string) $cm['ref'], 'sejur' => Format::zi((string) $cm['sosire'], $l) . ' - ' . Format::zi((string) $cm['plecare'], $l),
                'stare' => $t->t('admin.stare.' . $cm['stare'], ['cod' => (string) ($cm['eroare_cod'] ?? '')]), 'poate' => $app->gdpr()->poateFiAnonimizata($cm)];
        }
        return $gasite;
    }

    /** Pagina întoarsă după o greșeală (422), cu mesajul general sus și cu ce a scris gazda păstrat. */
    private static function gdprCuEroare(Aplicatie $app, Cerere $c, array $u, string $jeton, string $eroare, ?string $email, ?array $gasite, array $extra = [], ?array $scrise = null, array $gresite = []): Raspuns
    {
        $r = Raspuns::html('', 422);
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'gdpr', self::MESAJE_GDPR, self::ERORI);
        $d['mesaj'] = null;
        $d['eroare'] = $app->traducator((string) $u['limba_panou'], 'admin')->t($eroare);
        return self::paginaGdpr($app, $u, $d, $r, $email, $gasite, $scrise, $gresite, $extra);
    }

    public static function actiune(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $inapoi = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/gdpr', $q);
        $a = (string) $p['actiune'];
        if ($a === 'termene') {
            $scrise = [];
            foreach (array_keys(Gdpr::LIMITE) as $k) {
                $scrise[$k] = mb_substr(trim((string) ($c->camp($k) ?? '')), 0, 10);
            }
            // Formularul întors după o greșeală păstrează ce a scris gazda, cu eroarea lângă câmp.
            $inapoiCuValori = static fn (string $eroare, array $gresite): Raspuns => self::gdprCuEroare($app, $c, $u, $jeton, $eroare, null, null, [], $scrise, $gresite);
            if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
                return $inapoiCuValori('admin.reauth.eroare', []);
            }
            [, $gresite] = Gdpr::verificaTermene($scrise);
            if ($gresite !== [] || $app->gdpr()->alegeTermene($scrise, (int) $u['id']) !== null) {
                return $inapoiCuValori('admin.gdpr.eroare_termene', $gresite);
            }
            $app->auditDetalii((array) $app->gdpr()->termene());
            return $inapoi(['mesaj' => 'salvat']);
        }
        if ($a === 'alerte') {
            // jurnalul alertelor, lângă celelalte termene (fără date ale oaspeților; fără re-autentificare, ca până acum).
            if (!$app->gdpr()->alegeAlerteZile((string) ($c->camp('zile') ?? ''), (int) $u['id'])) {
                return $inapoi(['eroare' => 'alerte']);
            }
            $app->auditDetalii(['zile' => $app->gdpr()->alerteZile()]);
            return $inapoi(['mesaj' => 'salvat']);
        }
        $scris = mb_substr(trim((string) ($c->camp('email') ?? '')), 0, 254);
        $email = mb_strtolower($scris);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            // Căutarea cu o adresă greșită se întoarce cu adresa scrisă și cu eroarea lângă câmp.
            return $a === 'cauta' ? self::gdprCuEroare($app, $c, $u, $jeton, 'admin.gdpr.eroare_email', $scris, null, ['email_gresit' => true]) : $inapoi(['eroare' => 'email']);
        }
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        if ($a === 'cauta') {
            $r = Raspuns::html('');
            $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'gdpr', self::MESAJE_GDPR, self::ERORI);
            $gasite = self::gasite($app, $email, $l);
            $app->auditDetalii(['gasite' => count($gasite)]);
            return self::paginaGdpr($app, $u, $d, $r, $email, $gasite);
        }
        if ($a !== 'export' && $a !== 'sterge') {
            return $inapoi([]);
        }
        // O parolă greșită sau bifa lipsă: pagina revine cu oaspetele căutat și cu rezervările lui.
        if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
            return self::gdprCuEroare($app, $c, $u, $jeton, 'admin.reauth.eroare', $email, self::gasite($app, $email, $l), ['eroare_la' => $a]);
        }
        if ($a === 'export') {
            $date = $app->gdpr()->exporta($email);
            $app->auditDetalii(['comenzi' => count($date['comenzi']), 'refs' => implode(',', array_column($date['comenzi'], 'ref'))]);
            $r = new Raspuns(200, (string) json_encode($date, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $r->antet('Content-Type', 'application/json; charset=utf-8');
            $r->antet('Content-Disposition', 'attachment; filename="date-oaspete-' . $app->azi() . '.json"');
            return $r;
        }
        if ($c->camp('confirm') !== '1') {
            return self::gdprCuEroare($app, $c, $u, $jeton, 'admin.gdpr.eroare_confirmare', $email, self::gasite($app, $email, $l), ['eroare_la' => 'confirmare']);
        }
        $rez = $app->gdpr()->sterge($email, (int) $u['id']);
        $app->auditDetalii(['anonimizate' => implode(',', $rez['anonimizate']), 'pastrate' => implode(',', $rez['pastrate'])]);
        // ștergerea din site e gata; trimiterea la EasyCheck pleacă după răspuns (reluările, din cron).
        $trimise = $rez['stergere'] !== null ? (int) $app->baza()->valoare('SELECT COUNT(*) FROM {gdpr_easycheck} WHERE stergere_id = ?', [$rez['stergere']]) : 0;
        if ($trimise > 0) {
            $app->dupaRaspuns(static fn () => $app->gdprEasyCheck()->ruleaza());
        }
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'gdpr', self::MESAJE_GDPR, self::ERORI);
        $d['mesaj'] = $t->t('admin.gdpr.rezultat', ['anonimizate' => $rez['anonimizate'] !== [] ? implode(', ', $rez['anonimizate']) : '0'])
            . ($rez['pastrate'] !== [] ? ' ' . $t->t('admin.gdpr.pastrate', ['comenzi' => implode(', ', $rez['pastrate'])]) : '')
            . ($trimise > 0 ? ' ' . $t->plural('admin.gdpr.ec.trimisa', $trimise) : '');
        return self::paginaGdpr($app, $u, $d, $r, null, null);
    }

    // ---- Datele firmei ----

    /** Lățimea câmpurilor din „Datele firmei” (clasele din admin.css); restul au lățimea obișnuită. */
    private const LEGAL_LATIME = ['cui' => 'pa-scurt', 'sediu' => 'pa-lat', 'capital_social' => 'pa-scurt', 'certificat_clasificare' => 'pa-scurt'];

    public static function legal(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        return self::paginaLegal($app, $c, $u, $jeton, null, []);
    }

    /**
     * @param ?array<string,string> $scrise valorile scrise în formularul întors după o greșeală (și `forma`)
     * @param array<string,string> $erori câmpul greșit => cheia textului erorii
     */
    private static function paginaLegal(Aplicatie $app, Cerere $c, array $u, string $jeton, ?array $scrise, array $erori): Raspuns
    {
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('', $erori === [] ? 200 : 422);
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'legal', self::MESAJE_LEGAL, self::ERORI);
        if ($erori !== []) {
            $d['mesaj'] = null;
            $d['eroare'] = $t->t('admin.legal.eroare');
        }
        $api = (array) ($app->ecOglinda()->corp('proprietate')['operator'] ?? []);
        $local = array_column($app->baza()->toate('SELECT camp, valoare FROM {date_legale}'), 'valoare', 'camp');
        $bloc = new BlocLegal($app, $app->traducator($l), $l);
        // La persoana fizică, CUI-ul și sediul nu sunt cerute, ca în lipsa() și lipsaVanzare().
        $obligatorii = BlocLegal::obligatorii($app);
        $eticheta = static fn (string $k): string => $t->t('admin.legal.camp.' . $k);
        // Ce vine din EasyCheck se arată doar de citit; formularul ține doar ce lipsește acolo (API-ul are întâietate).
        $dinEc = [];
        $campuri = [];
        foreach (BlocLegal::CAMPURI as $k) {
            $dinApi = is_string($api[$k] ?? null) && trim($api[$k]) !== '' ? trim($api[$k]) : null;
            if ($dinApi !== null) {
                // ce vine din EasyCheck se arată ca pe site: literele cu sedilă corectate, capitalul social cu „lei”.
                $afisat = \Cazo\I18n\Format::diacritice($dinApi);
                $dinEc[] = ['eticheta' => $eticheta($k), 'valoare' => $k === 'sediu_tip' && in_array($dinApi, ['social', 'profesional'], true) ? $t->t('admin.legal.sediu_' . $dinApi)
                    : ($k === 'capital_social' ? BlocLegal::capital($afisat, $app->traducator($l)) : $afisat)];
                continue;
            }
            $cerut = in_array($k, $obligatorii, true);
            $ajutor = $t->are('admin.legal.ajutor.' . $k) ? $t->t('admin.legal.ajutor.' . $k) : '';
            // Denumirea e cerută la salvare; celelalte date cerute de lege se pot completa și mai târziu.
            $laSalvare = $k === 'denumire';
            if ($cerut && !$laSalvare) {
                $ajutor = trim($t->t(in_array($k, BlocLegal::DOAR_FIRMA, true) ? 'admin.legal.cerut_firme' : 'admin.legal.cerut') . ' ' . $ajutor);
            }
            $campuri[] = ['camp' => $k, 'eticheta' => $eticheta($k), 'valoare' => (string) ($scrise[$k] ?? $local[$k] ?? ''), 'required' => $cerut && $laSalvare, 'ajutor' => $ajutor,
                'latime' => self::LEGAL_LATIME[$k] ?? '', 'eroare' => isset($erori[$k]) ? $t->t($erori[$k]) : null];
        }
        // O singură casetă cu ce lipsește: întâi datele fără de care site-ul nu primește rezervări, apoi restul celor
        // cerute de lege; contactul, care se completează în EasyCheck, e spus separat.
        $vanzare = $bloc->lipsaVanzare();
        $toate = $bloc->lipsa();
        $aici = static fn (array $x): array => array_map($eticheta, array_values(array_diff($x, ['contact'])));
        // forma de organizare (persoana fizică ascunde adresa); un cod fiscal din EasyCheck înseamnă firmă.
        $cuiEc = is_string($api['cui'] ?? null) && trim($api['cui']) !== '';
        // forma trimisă de EasyCheck se arată doar de citit, lângă celelalte date de acolo; fără ea, lista vine
        // cu forma aleasă sau, cât gazda n-a ales, cu cea dedusă din denumire și din numărul de la registru.
        $cunoscuta = BlocLegal::formaCunoscuta($app);
        $salvata = (string) $app->setari()->get('legal.forma', '');
        if (($cunoscuta['sursa'] ?? null) === 'easycheck') {
            $dinEc[] = ['eticheta' => $t->t('admin.legal.forma'), 'valoare' => $t->t('admin.legal.forma.' . $cunoscuta['forma'])];
        }
        $d += ['forma' => (string) ($scrise['forma'] ?? ($salvata !== '' ? $salvata : ($cunoscuta['forma'] ?? ''))), 'forme' => BlocLegal::FORME,
            'forma_ec' => $cuiEc || ($cunoscuta['sursa'] ?? null) === 'easycheck', 'cui_ec' => $cuiEc,
            'forma_dedusa' => !isset($scrise['forma']) && $salvata === '' && ($cunoscuta['sursa'] ?? null) === 'dedus',
            'persoana_fizica' => BlocLegal::persoanaFizica($app), 'accesibilitate' => BlocLegal::accesibilitate($app),
            'din_ec' => $dinEc, 'campuri' => $campuri, 'lipsa_vanzare' => $aici($vanzare), 'lipsa' => $aici(array_diff($toate, $vanzare)),
            'lipsa_contact' => in_array('contact', $toate, true),
            // telefonul apare pe site doar la alegerea gazdei.
            'telefon_pe_site' => $app->setari()->get(\Cazo\EasyCheck\Oglinda::TELEFON_PE_SITE, false) === true,
            'telefon_ec' => trim((string) ($app->ecOglinda()->corp('proprietate')['contact']['telefon'] ?? '')),
            'email_ec' => trim((string) ($app->ecOglinda()->corp('proprietate')['contact']['email'] ?? '')),
            'url' => ['baza' => $app->url()->cale('/admin/legal'), 'gdpr' => $app->url()->cale('/admin/gdpr'), 'pagina' => $app->url()->cale('/date-legale')]];
        return AdminPanou::pagina($app, $u, 'legal', $d, $r);
    }

    public static function legalSalveaza(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        // pagina Accesibilitate, pornită sau ascunsă de gazdă.
        if ($c->camp('sectiune') === 'accesibilitate') {
            $activa = $c->camp('accesibilitate') === '1';
            $app->setari()->pune(BlocLegal::ACCESIBILITATE, $activa, (int) $u['id']);
            $app->ecOglinda()->schimbaContinutul();
            $app->auditDetalii(['accesibilitate' => $activa]);
            // bifa stă pe „Legal și datele oaspeților”, în blocul contactului și al paginii Accesibilitate.
            return AdminCompus::spre($app, '/admin/gdpr', 'pe-site', ['mesaj' => $activa ? 'acces_publicata' : 'acces_ascunsa'], 303);
        }
        // bifa „Arătați telefonul pe site”; paginile ținute în cache se refac.
        if ($c->camp('sectiune') === 'telefon') {
            $aratat = $c->camp('telefon') === '1';
            $app->setari()->pune(\Cazo\EasyCheck\Oglinda::TELEFON_PE_SITE, $aratat, (int) $u['id']);
            $app->ecOglinda()->schimbaContinutul();
            $app->auditDetalii(['telefon_pe_site' => $aratat]);
            return AdminCompus::spre($app, '/admin/gdpr', 'pe-site', ['mesaj' => $aratat ? 'telefon_aratat' : 'telefon_ascuns'], 303);
        }
        // linkul Cazo din subsol face parte din licență; bifa care îl ascundea s-a scos, iar un formular vechi
        // trimis încă nu schimbă nimic.
        if ($c->camp('sectiune') === 'mentiune_ec') {
            return AdminCompus::spre($app, '/admin/gdpr', 'pe-site', [], 303);
        }
        // Datele și forma de organizare vin din același formular. Întâi verificarea, apoi scrierea: la o greșeală,
        // pagina revine cu tot ce a scris gazda și cu eroarea lângă câmp.
        $api = (array) ($app->ecOglinda()->corp('proprietate')['operator'] ?? []);
        $scrise = [];
        $erori = [];
        foreach (BlocLegal::CAMPURI as $k) {
            $v = $c->camp('camp_' . $k);
            if ($v === null) {
                continue;
            }
            $v = trim(mb_substr($v, 0, 500));
            if ($k === 'sediu_tip' && !in_array($v, ['', 'social', 'profesional'], true)) {
                continue;
            }
            if ($k === 'cui' && $v !== '') {
                // Verificarea blândă a formei: cifrele codului, cu „RO” în față la plătitorii de TVA; spațiile, punctele și liniuțele ies.
                $cui = mb_strtoupper((string) preg_replace('~[\s.\-]+~u', '', $v));
                if (preg_match('~^(RO)?[0-9]{2,10}$~', $cui)) {
                    $v = $cui;
                } else {
                    $erori[$k] = 'admin.legal.eroare_cui';
                }
            }
            if ($k === 'denumire' && $v === '' && !(is_string($api[$k] ?? null) && trim($api[$k]) !== '')) {
                $erori[$k] = 'admin.legal.eroare_denumire';
            }
            $scrise[$k] = $v;
        }
        $forma = $c->camp('forma');
        $forma = is_string($forma) && in_array($forma, BlocLegal::FORME, true) ? $forma : null;
        if ($erori !== []) {
            return self::paginaLegal($app, $c, $u, $jeton, $scrise + ($forma !== null ? ['forma' => $forma] : []), $erori);
        }
        $acum = $app->ceas()->moment();
        foreach ($scrise as $k => $v) {
            $app->baza()->upsert('date_legale', ['camp' => $k, 'valoare' => $v === '' ? null : $v, 'actualizat_la' => $acum], ['camp'], ['valoare', 'actualizat_la']);
        }
        // forma de organizare, ca la instalare.
        if ($forma !== null) {
            $app->setari()->pune('legal.forma', $forma, (int) $u['id']);
        }
        // Blocul legal e pe fiecare pagină: paginile din cache se refac.
        $app->ecOglinda()->schimbaContinutul();
        $app->auditDetalii(['campuri' => implode(',', array_keys($scrise))] + ($forma !== null ? ['forma' => $forma] : []));
        return AdminPanou::inapoi($app, '/admin/legal', ['mesaj' => 'salvat']);
    }

    /** Acordurile pentru noutăți, ca CSV: adresa, numele, limba, data acordului. Formularul stă pe „Datele oaspeților”. */
    public static function noutati(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
            return self::gdprCuEroare($app, $c, $u, $jeton, 'admin.reauth.eroare', null, null, ['eroare_la' => 'noutati']);
        }
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $date = [[$t->t('admin.legal.csv.email'), $t->t('admin.csv.oaspete'), $t->t('admin.legal.csv.limba'), $t->t('admin.legal.csv.la'), $t->t('admin.legal.csv.reconfirmat')]];
        // un acord mai vechi de 2 ani, când nici site-ul n-a mai trimis oaspetelui (aceeași adresă) vreun e-mail în
        // acești 2 ani, după datele pe care le ține softul, apare „de reconfirmat”.
        $prag = $app->ceas()->moment(-730 * 86400);
        $n = 0;
        foreach ($app->baza()->toate('SELECT * FROM {comenzi} WHERE marketing_acord = 1 AND marketing_retras_la IS NULL AND anonimizat_la IS NULL ORDER BY id') as $cm) {
            $o = $app->comenzi()->oaspete($cm);
            if (!isset($o['email'])) {
                continue;
            }
            $ultim = $app->baza()->valoare("SELECT MAX(e.trimis_la) FROM {emailuri} e JOIN {comenzi} c ON c.id = e.comanda_id WHERE c.email_io = ? AND e.stare = 'trimis'", [$cm['email_io']]);
            $vechi = (string) $cm['creat_la'] < $prag && ($ultim === null || (string) $ultim < $prag);
            // Data acordului, în fusul site-ului și în formatul limbii panoului.
            $date[] = [(string) $o['email'], (string) ($o['nume'] ?? ''), (string) $cm['limba'], Format::moment((string) $cm['creat_la'], $l), $vechi ? $t->t('admin.legal.csv.da') : ''];
            $n++;
        }
        $app->auditDetalii(['randuri' => $n]);
        return AdminLista::csv($date, 'noutati-' . $app->azi() . '.csv');
    }

    // ---- Publicarea ----

    /** Publicarea site-ului: pagina unică și regulile ei stau în Publicare. */
    public static function site(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return Publicare::paginaSite($app, $c);
    }

    public static function siteActiune(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return Publicare::actiune($app, $c, (string) $p['actiune']);
    }
}
