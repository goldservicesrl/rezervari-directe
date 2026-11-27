<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Legal\BlocLegal;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;
use Cazo\Securitate\SesiuneAdmin;

defined('CAZO') or exit;

/**
 * Piesele comune ale panoului complet: meniul pe roluri, sesiunea cu jetonul ei, re-autentificarea
 *, paginile cu mesajele lor și alertele strânse într-un singur loc (Acasă și pagina Alerte).
 *
 * Rolurile (14): `personal` vede Acasă, Rezervările, Conținutul și Contul lui; angajatul cu dreptul „Poate face
 * rambursări” vede în plus Plățile și Alertele; restul secțiunilor sunt ale proprietarului.
 */
final class AdminPanou
{
    /**
     * Paginile panoului: cheia => [calea, cine o vede, grupul din meniu]. Din 20.11.2026, 13 pagini în patru (14 din 24.11.2026, cu Abonații la oferte)
     * grupuri; paginile vechi sunt blocuri ale paginilor compuse (AdminCompus), iar adresele lor duc acolo. Grupul
     * `antet` ține Contul meu, care nu are intrare în meniu.
     */
    public const SECTIUNI = [
        'acasa' => ['/admin/', 'toti', 'acasa'],
        'continut' => ['/admin/continut', 'toti', 'site'],
        'pagini' => ['/admin/pagini', 'toti', 'site'],
        'aspect' => ['/admin/aspect', 'proprietar', 'site'],
        'seo' => ['/admin/seo', 'proprietar', 'site'],
        'rezervari' => ['/admin/rezervari', 'toti', 'rezervari'],
        'metode' => ['/admin/metode-plata', 'proprietar', 'rezervari'],
        'mesaje' => ['/admin/emailuri/sabloane', 'proprietar', 'rezervari'],
        // abonații la oferte (lista are datele oaspeților, trimiterea pleacă în numele site-ului).
        'abonati' => ['/admin/abonati', 'proprietar', 'rezervari'],
        'conexiuni' => ['/admin/conexiuni', 'proprietar', 'setari'],
        'gdpr' => ['/admin/gdpr', 'proprietar', 'setari'],
        'emailuri' => ['/admin/emailuri', 'proprietar', 'setari'],
        'utilizatori' => ['/admin/utilizatori', 'proprietar', 'setari'],
        // Actualizările le putea deschide oricine din panou (doar de citit pentru angajat), jurnalul alertelor angajatul
        // cu dreptul de rambursare: pagina îi arată fiecăruia blocurile lui.
        'intretinere' => ['/admin/intretinere', 'toti', 'setari'],
        'cont' => ['/admin/cont', 'toti', 'antet'],
    ];

    /** Grupurile meniului, în ordinea de pe ecran. */
    public const GRUPURI = ['acasa', 'site', 'rezervari', 'setari'];

    /**
     * Paginile site-ului fără cheie EasyCheck, doar ale proprietarului, puse în meniu doar în modul local:
     * cheia => [calea, cine o vede, grupul, pagina înaintea căreia stă].
     */
    public const SECTIUNI_LOCALE = [
        'proprietate' => ['/admin/proprietate', 'proprietar', 'site', 'continut'],
        'camere_site' => ['/admin/camere-site', 'proprietar', 'site', 'continut'],
        'calendar' => ['/admin/calendar', 'proprietar', 'rezervari', 'metode'],
    ];

    /** Paginile meniului pentru modul de acum (în modul local, cu SECTIUNI_LOCALE la locul lor). @return array<string, array{0:string, 1:string, 2:string}> */
    public static function sectiuni(Aplicatie $app): array
    {
        if (!$app->modLocal()) {
            return self::SECTIUNI;
        }
        $r = [];
        foreach (self::SECTIUNI as $cheie => $s) {
            foreach (self::SECTIUNI_LOCALE as $k => [$cale, $cine, $grup, $inainte]) {
                if ($inainte === $cheie) {
                    $r[$k] = [$cale, $cine, $grup];
                }
            }
            $r[$cheie] = $s;
        }
        return $r;
    }

    /**
     * Meniul: grupurile pe care le vede utilizatorul (fiecare duce la prima lui pagină vizibilă) și paginile
     * grupului activ, ca navigare secundară (doar când grupul are mai multe pagini vizibile).
     * @return array{grupuri:list<array{cheie:string, url:string, activ:bool}>, pagini:list<array{cheie:string, url:string, activ:bool}>, grup:string}
     */
    public static function meniu(Aplicatie $app, array $u, string $activa): array
    {
        // O secțiune de dinainte (pagina unei plăți, pasul restaurării) ține de pagina nouă care o cuprinde.
        $activa = AdminCompus::SECTIUNI[$activa][0] ?? $activa;
        $sectiuni = self::sectiuni($app);
        $grupActiv = $sectiuni[$activa][2] ?? '';
        $grupuri = [];
        $pagini = [];
        foreach (self::GRUPURI as $g) {
            $prima = null;
            foreach ($sectiuni as $cheie => [$cale, $cine, $grup]) {
                if ($grup !== $g || !self::vede($u, $cine)) {
                    continue;
                }
                $prima ??= $cale;
                if ($g === $grupActiv) {
                    $pagini[] = ['cheie' => $cheie, 'url' => $app->url()->cale($cale), 'activ' => $cheie === $activa];
                }
            }
            if ($prima !== null) {
                $grupuri[] = ['cheie' => $g, 'url' => $app->url()->cale($prima), 'activ' => $g === $grupActiv];
            }
        }
        return ['grupuri' => $grupuri, 'pagini' => count($pagini) > 1 ? $pagini : [], 'grup' => $grupActiv];
    }

    public static function vede(array $u, string $cine): bool
    {
        return match ($cine) {
            'proprietar' => ($u['rol'] ?? null) === 'proprietar',
            'rambursari' => SesiuneAdmin::poateRambursa($u),
            default => true,
        };
    }

    /**
     * Utilizatorul autentificat și jetonul sesiunii, sau null. $cine: 'toti', 'rambursari', 'proprietar'.
     * @return array{0:array, 1:string}|null
     */
    public static function sesiune(Aplicatie $app, Cerere $c, string $cine = 'toti'): ?array
    {
        $jeton = $c->cookie(SesiuneAdmin::COOKIE);
        $u = $app->sesiuneAdmin()->utilizator($jeton);
        if ($u === null || !self::vede($u, $cine)) {
            return null;
        }
        return [$u, (string) $jeton];
    }

    /** Fără sesiune sau fără drept: înapoi la Acasă (formularul de intrare, când lipsește sesiunea). */
    public static function inapoi(Aplicatie $app, string $cale = '/admin/', array $q = []): Raspuns
    {
        return Raspuns::redirectare($app->url()->cale($cale, null, $q));
    }

    /**
     * Re-autentificarea: operația trece cu parola confirmată în ultimele 10 minute sau cu parola scrisă acum în
     * câmpul `parola_reauth` al formularului.
     */
    public static function reautentificat(Aplicatie $app, Cerere $c, array $u, string $jeton): bool
    {
        return $app->sesiuneAdmin()->cereReautentificare($jeton, (int) $u['id'], (string) ($c->camp('parola_reauth') ?? ''));
    }

    /** Datele comune ale unei pagini: utilizatorul, CSRF, ieșirea, mesajul și eroarea din adresă, re-autentificarea. */
    public static function date(Aplicatie $app, Cerere $c, Raspuns $r, array $u, string $jeton, string $sectiune, array $mesaje = [], array $erori = []): array
    {
        $t = $app->traducator((string) $u['limba_panou'], 'admin');
        $m = (string) ($c->param('mesaj') ?? '');
        $e = (string) ($c->param('eroare') ?? '');
        return [
            'utilizator' => $u,
            'sectiune' => $sectiune,
            'csrf' => $app->jetonCsrf($c, $r),
            'reautentificat' => $app->sesiuneAdmin()->reautentificat($jeton),
            'mesaj' => isset($mesaje[$m]) ? $t->t($mesaje[$m]) : null,
            'eroare' => isset($erori[$e]) ? $t->t($erori[$e]) : null,
        ];
    }

    public static function pagina(Aplicatie $app, array $u, string $vedere, array $d, Raspuns $r): Raspuns
    {
        $d['url'] = (array) ($d['url'] ?? []) + ['iesire' => $app->url()->cale('/admin/iesire'), 'rezervari' => $app->url()->cale('/admin/rezervari/'),
            'reauth' => $app->url()->cale('/admin/reautentificare')];
        return Admin::pagina($app, (string) $u['limba_panou'], $vedere, $d, $r);
    }

    /**
     * Antetul panoului: contorul alertelor de rezolvat de acum (cu legătura spre pagina lor; fără notele „De știut”),
     * Contul meu și ieșirea.
     * @return array{alerte:int, grave:int, url_alerte:string, url_cont:string, url_iesire:string, url_site:string, alerte_activ:bool, cont_activ:bool}
     */
    public static function antet(Aplicatie $app, Traducator $t, array $u, string $activa): array
    {
        try {
            $alerte = self::alerte($app, $t, $u);
        } catch (\Throwable) {
            $alerte = [];
        }
        // bulina numără doar alertele de rezolvat; notele „De știut” (modul de probă, site-ul în pregătire,
        // tarifele cu avans 0%) rămân pe Acasă, fără să fie numărate. bulina stă lângă „Acasă” și numără ce arată
        // Acasă la „De rezolvat” (cele spuse de blocul publicării lipsesc de acolo, deci și de aici).
        $inPublicare = self::inPublicare($app, $u);
        $grave = count(array_filter($alerte, static fn (array $a): bool => $a['grav'] && !in_array($a['cheie'], $inPublicare, true)));
        return ['alerte' => $grave, 'grave' => $grave,
            'url_alerte' => $app->url()->cale('/admin/') . '#alerte', 'url_cont' => $app->url()->cale('/admin/cont'), 'url_iesire' => $app->url()->cale('/admin/iesire'),
            'url_site' => $app->url()->cale('/'), 'alerte_activ' => $activa === 'alerte', 'cont_activ' => $activa === 'cont'];
    }

    /**
     * Alertele pe care, cât site-ul e în pregătire, le spune deja blocul „Publicarea site-ului” de pe Acasă (datele firmei
     * cerute la publicare, termenele de păstrare): pe Acasă stau o singură dată, în acel bloc.
     * @return list<string>
     */
    public static function inPublicare(Aplicatie $app, array $u): array
    {
        return ($u['rol'] ?? null) === 'proprietar' && $app->setari()->get('site.publicat', false) !== true ? ['legal', 'termene'] : [];
    }

    // ---- Limba paginilor fără sesiune ----

    public const COOKIE_LIMBA = '__Host-pg_admin_limba';

    /** Cookie-ul cu ultima limbă a panoului (fără date personale), un an. */
    public static function cookieLimba(string $limba): string
    {
        return self::COOKIE_LIMBA . '=' . (in_array($limba, \Cazo\Securitate\Utilizatori::LIMBI, true) ? $limba : 'ro') . '; Path=/; Secure; HttpOnly; SameSite=Lax; Max-Age=31536000';
    }

    /**
     * Limba paginilor dinaintea autentificării (intrarea, parola uitată): alegerea din adresă (`?limba=`), apoi ultima
     * limbă a panoului (cookie-ul pus la intrare), apoi limba panoului aleasă de proprietar (la instalare), apoi
     * româna. Limba browserului nu contează.
     * @return array{0:string, 1:?string} limba și, când s-a ales din adresă, cookie-ul de pus
     */
    public static function limbaPublica(Aplicatie $app, Cerere $c): array
    {
        $limbi = \Cazo\Securitate\Utilizatori::LIMBI;
        $ceruta = (string) ($c->param('limba') ?? '');
        if (in_array($ceruta, $limbi, true)) {
            return [$ceruta, self::cookieLimba($ceruta)];
        }
        $tinuta = (string) ($c->cookie(self::COOKIE_LIMBA) ?? '');
        if (in_array($tinuta, $limbi, true)) {
            return [$tinuta, null];
        }
        try {
            $l = $app->baza()->valoare("SELECT limba_panou FROM {utilizatori} WHERE rol = 'proprietar' AND activ = 1 ORDER BY id LIMIT 1");
        } catch (\Throwable) {
            $l = null;
        }
        return [is_string($l) && in_array($l, $limbi, true) ? $l : 'ro', null];
    }

    /** Legătura mică spre cealaltă limbă, sub formularele fără sesiune: @return array{limba:string, url:string} */
    public static function altaLimba(Aplicatie $app, string $limba, string $cale): array
    {
        $alta = $limba === 'en' ? 'ro' : 'en';
        return ['limba' => $alta, 'url' => $app->url()->cale($cale, null, ['limba' => $alta])];
    }

    /** Acasă (14): sosirile de azi și de mâine de pe site și alertele de acum, pe scurt. */
    public static function acasa(Aplicatie $app, Traducator $t, array $u): array
    {
        $l = $t->limba();
        $azi = $app->azi();
        $maine = (new \DateTimeImmutable($azi . ' 00:00:00', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        $sosiri = [];
        foreach ($app->baza()->toate("SELECT * FROM {comenzi} WHERE stare IN ('confirmata', 'anulare_ceruta') AND sosire IN (?, ?) ORDER BY sosire, id LIMIT 50", [$azi, $maine]) as $cm) {
            $o = $app->comenzi()->oaspete($cm);
            $sosiri[] = ['ref' => (string) $cm['ref'], 'zi' => $cm['sosire'] === $azi ? $t->t('admin.acasa.azi') : $t->t('admin.acasa.maine'),
                'sejur' => Format::zi((string) $cm['sosire'], $l) . ' - ' . Format::zi((string) $cm['plecare'], $l), 'oaspete' => (string) ($o['nume'] ?? ''),
                'suprapunere' => (int) $cm['suprapunere'] === 1];
        }
        return ['sosiri' => $sosiri, 'alerte' => self::alerte($app, $t, $u), 'url_alerte' => $app->url()->cale('/admin/') . '#alerte'];
    }

    /**
     * Alertele de acum (pagina Acasă: configurarea, datele legale, termenele, plata, contul și rezervările), calculate din starea site-ului,
     * nu din e-mailuri: fiecare rămâne până se rezolvă. Fiecare are textul, legătura spre locul unde se rezolvă și,
     * unde e cazul, codurile comenzilor. Fără date personale.
     *
     * Vizibilitatea: alertele despre bani (BANI) le văd proprietarul și
     * angajații cu dreptul de rambursare; cele despre rezervări (TOTI), oricine din panou; restul, proprietarul.
     * Fără utilizator (apelurile interne), toate.
     *
     * Notele pe care gazda le poate ascunde (`ASCUNSE_POSIBILE`) au `ascunde` = true: pagina le pune butonul
     * „Am înțeles”. O notă ascunsă lipsește de aici (deci și din contorul din antet) până i se schimbă conținutul.
     * @return list<array{cheie:string, text:string, url:?string, refs:list<string>, grav:bool, ascunde:bool}>
     */
    public static function alerte(Aplicatie $app, Traducator $t, ?array $u = null): array
    {
        $b = $app->baza();
        $a = [];
        // legăturile alertelor rămân pe adresele de dinainte, care duc cu 302 la pagina și la blocul lor: aceleași căi
        // pleacă la EasyCheck în starea site-ului (`detalii.pagina`, fără parametri), deci starea trimisă rămâne neschimbată.
        $adauga = static function (string $cheie, string $text, ?string $cale, array $refs = [], bool $grav = true, array $query = []) use (&$a, $app): void {
            $a[] = ['cheie' => $cheie, 'text' => $text, 'url' => $cale !== null ? $app->url()->cale($cale, null, $query) : null, 'refs' => array_values($refs), 'grav' => $grav,
                'ascunde' => in_array($cheie, self::ASCUNSE_POSIBILE, true)];
        };
        $refs = static fn (string $sql, array $p = []): array => array_map('strval', array_column($b->toate($sql . ' LIMIT 50', $p), 'ref'));

        // Configurarea și vânzarea.
        if (in_array('metode', $app->configurareLipsa(), true)) {
            $adauga('configurare', $t->t('admin.alerta.configurare'), '/admin/metode-plata');
        }
        $online = (array) ($app->setari()->get('plati.online') ?? []);
        if (($online['oprita'] ?? false) === true) {
            $motive = [];
            foreach ((array) ($online['motive'] ?? []) as $m) {
                $motive[] = $t->t('plata.motiv.' . $m);
            }
            $adauga('plata_oprita', $t->t('admin.plati.oprita') . ' ' . implode(' ', $motive), '/admin/metode-plata');
        }
        $fara = $app->modPlata()->planuriFaraPolitica();
        if ($fara !== []) {
            $adauga('politica', $t->t('admin.plati.fara_politica', ['planuri' => implode(', ', $fara)]), null);
        }
        // cu cardul sau transferul bifat, tarifele cu avans 0% rămân doar cu plata la sosire; gazda află (informare).
        // gazda o poate ascunde de aici („Am înțeles”); nota rămâne pe pagina „Metodele de plată” și revine
        // aici când lista tarifelor cu avans 0% se schimbă.
        $zero = $app->modPlata()->notaFaraAvans($t);
        if ($zero !== null && !self::ascunsa($app, 'avans_zero')) {
            $adauga('avans_zero', $zero, '/admin/metode-plata', [], false);
        }
        // tema site-ului a ieșit din program, iar migrarea 0055 a trecut site-ul pe tema rămasă cea mai apropiată.
        // Nota stă cât site-ul folosește tema aleasă de migrare; gazda o poate ascunde („Am înțeles”).
        $scoasa = $app->setari()->get('tema.scoasa');
        if (is_array($scoasa) && is_string($scoasa['veche'] ?? null) && is_string($scoasa['noua'] ?? null)
            && (string) $app->setari()->get('tema.activa', \Cazo\Teme\Tema::IMPLICITA) === $scoasa['noua'] && !self::ascunsa($app, 'tema_scoasa')) {
            $adauga('tema_scoasa', $t->t('admin.alerta.tema_scoasa', ['veche' => $t->t('admin.teme.scoasa.' . $scoasa['veche']),
                'noua' => $app->catalog()->tema($scoasa['noua'])?->nume($t->limba()) ?? $scoasa['noua']]), '/admin/aspect', [], false);
        }
        if ($app->intrerupator()->pornit()) {
            $adauga('intrerupator', $t->t('admin.alerta.intrerupator'), '/admin/protectie');
        }
        foreach ($app->ecLicenta()->stare()['alerte'] as $k) {
            $adauga('cheie', $t->t($k, ['adresa' => $app->url()->absolut('/easycheck-anunt')]), '/admin/conexiuni', [], $k !== 'conexiuni.ec.mod_proba');
        }
        // API-ul EasyCheck: cu cheia live și `site_web.rezervari_pe` = pagina, rezervările directe
        // le primește pagina EasyCheck. Doar informare: site-ul primește în continuare rezervări, nimic nu se oprește.
        $ck = $app->ecLicenta()->cheie();
        if (($ck['mod'] ?? null) === 'live' && ($ck['rezervari_pe'] ?? null) === 'pagina') {
            $adauga('rezervari_pagina', $t->t('admin.alerta.rezervari_pagina'), null, [], false);
        }
        // Legal și GDPR. Fără datele cerute înaintea contractului, site-ul arată doar
        // prezentarea: alerta lor numește exact câmpurile lipsă; restul datelor obligatorii, separat.
        $bloc = new BlocLegal($app, $app->traducator($t->limba()), $t->limba());
        $campuri = static fn (array $l): string => implode(', ', array_map(static fn (string $c): string => $t->t('admin.legal.camp.' . $c), $l));
        $vanzare = $bloc->lipsaVanzare();
        if ($vanzare !== []) {
            $adauga('legal_vanzare', $t->t('admin.alerta.legal_vanzare', ['campuri' => $campuri($vanzare)]), '/admin/legal');
        }
        // Orele de check-in și check-out încă neconfirmate în EasyCheck: tot fără rezervări, până la confirmare.
        if (in_array('ore_neconfirmate', $app->configurareLipsa(), true)) {
            $adauga('ore_neconfirmate', $t->t('admin.alerta.ore_neconfirmate'), null);
        }
        // ce primește oaspetele când gazda anulează, încă nealeasă: tot fără rezervări, până la alegere.
        if (in_array('anulare_gazda', $app->configurareLipsa(), true)) {
            $adauga('anulare_gazda', $t->t('admin.alerta.anulare_gazda'), '/admin/metode-plata');
        }
        $rest = array_values(array_diff($bloc->lipsa(), $vanzare));
        if ($rest !== []) {
            $adauga('legal', $t->t('admin.alerta.legal', ['campuri' => $campuri($rest)]), '/admin/legal');
        }
        // Modelul unei pagini legale sau al subsolului s-a schimbat la o actualizare, după ce gazda și-a salvat textul:
        // textul ei rămâne, iar panoul arată diferențele.
        foreach ((new \Cazo\Legal\Editabile($app))->modeleSchimbate() as $x) {
            $text = $x['fel'] === 'subsol' ? $t->t('admin.texte_legale.model_schimbat_subsol') : $t->t('admin.texte_legale.model_schimbat', ['pagina' => $app->traducator($t->limba())->t('legal.link.' . $x['fel'])]);
            $adauga('model_legal', $text . ($x['limba'] === 'en' ? ' (English)' : ''), '/admin/legal/texte', [], false, ['fel' => $x['fel'], 'limba' => $x['limba']]);
        }
        if ($app->gdpr()->termene() === null) {
            $adauga('termene', $t->t('admin.alerta.termene'), '/admin/gdpr');
        }
        // --- Un termen de păstrare salvat înaintea pragurilor rămâne în vigoare până îl schimbă gazda. ---
        if ($app->gdpr()->termeneInAfara() !== []) {
            $adauga('termene_praguri', $t->t('admin.alerta.termene_praguri'), '/admin/gdpr', [], false);
        }
        // --- sfârșitul termenelor salvate înaintea pragurilor ---
        // cu funcția etapei 3 aprinsă (modurile E și P), cererile trimise la EasyCheck și anulările fără hotărâre stau în
        // EasyCheck (Azi, pagina plăților); site-ul nu le mai arată ca alerte ale lui. În L și V, ca până acum.
        $inEc = $app->modEc()->deciziiInEasyCheck();
        // Comenzile care așteaptă gazda.
        $x = $refs("SELECT ref FROM {comenzi} WHERE stare = 'anulare_ceruta'" . ($inEc ? " AND (ec_cerere_stare IS NULL OR ec_cerere_stare <> 'trimisa')" : '') . ' ORDER BY anulare_ceruta_la');
        if ($x !== []) {
            $adauga('anulare_ceruta', $t->t('admin.rez.alerta_cereri'), null, $x);
        }
        $x = $refs("SELECT ref FROM {comenzi} WHERE anulare_actor IS NOT NULL AND anulata_ec_la IS NULL AND anulare_eroare IS NOT NULL AND anulare_urmatoarea IS NULL AND stare IN ('confirmata', 'anulare_ceruta') ORDER BY id");
        if ($x !== []) {
            $adauga('anulare_refuzata', $t->t('admin.alerta.anulare_refuzata'), null, $x);
        }
        // Transferul bancar: rezervările care așteaptă plata; gazda confirmă primirea banilor în EasyCheck (o informație, nu o problemă).
        // pe cheia de probă, plata se hotărăște din panou, cu butoanele de probă, iar alerta spune asta.
        $x = $refs("SELECT ref FROM {comenzi} WHERE fel = 'transfer' AND stare = 'transfer_in_asteptare' ORDER BY transfer_termen, id");
        if ($x !== []) {
            $adauga('transfer_asteptat', $t->t($app->ecLicenta()->stare()['proba'] ? 'admin.alerta.transfer_asteptat_proba' : 'admin.alerta.transfer_asteptat'), null, $x, false);
        }
        $x = $inEc ? [] : $refs("SELECT ref FROM {comenzi} WHERE stare = 'anulare_de_lamurit' ORDER BY id");
        if ($x !== []) {
            $adauga('anulare_de_lamurit', $t->t('admin.rez.alerta_lamurire'), null, $x);
        }
        // taxa locală de returnat de gazdă la rezervările plătite prin transfer și anulate, până la „Am returnat taxa locală”.
        $x = $refs('SELECT ref FROM {comenzi} WHERE taxa_de_returnat > 0 AND taxa_returnata_la IS NULL AND ec_restituire_cale IS NULL ORDER BY id');
        if ($x !== []) {
            $adauga('taxa_de_returnat', $t->t('admin.alerta.taxa_de_returnat'), null, $x);
        }
        // despăgubirea datorată oaspetelui după anularea gazdei, până la „Am plătit despăgubirea” (sau forța majoră).
        // despăgubirea hotărâtă în EasyCheck („Am plătit despăgubirea” stă acolo) nu mai e o alertă a site-ului.
        $x = $refs('SELECT ref FROM {comenzi} WHERE despagubire > 0 AND despagubire_platita_la IS NULL AND forta_majora = 0 AND ec_restituire_cale IS NULL ORDER BY id');
        if ($x !== []) {
            $adauga('despagubire', $t->t('admin.alerta.despagubire'), null, $x);
        }
        // o rambursare comandată din EasyCheck care nu se poate încheia (execuția sau raportul blocate).
        $x = $app->modLocal() ? [] : $app->ecComenzi()->blocate();
        if ($x !== []) {
            $adauga('comenzi_blocate', $t->t('admin.alerta.comenzi_blocate'), null, $x);
        }
        $x = $refs("SELECT ref FROM {comenzi} WHERE stare = 'de_rambursat' ORDER BY id");
        if ($x !== []) {
            $adauga('de_rambursat', $t->t('admin.alerta.de_rambursat'), '/admin/plati', $x);
        }
        $x = $refs("SELECT DISTINCT c.ref FROM {operatii_plata} o JOIN {comenzi} c ON c.id = o.comanda_id WHERE o.tip = 'rambursare' AND o.stare = 'esuat' AND c.stare NOT IN ('rambursata', 'inchisa_gazda')
            AND NOT EXISTS (SELECT 1 FROM {operatii_plata} o2 WHERE o2.comanda_id = o.comanda_id AND o2.tip = 'rambursare' AND o2.id > o.id AND o2.stare = 'reusit') ORDER BY c.ref");
        if ($x !== []) {
            $adauga('rambursare_esuata', $t->t('admin.alerta.rambursare_esuata'), '/admin/plati', $x);
        }
        $x = $refs("SELECT DISTINCT c.ref FROM {operatii_plata} o JOIN {comenzi} c ON c.id = o.comanda_id WHERE o.tip = 'rambursare' AND o.stare = 'in_procesare' AND o.la < ? ORDER BY c.ref",
            [$app->ceas()->moment(-48 * 3600)]);
        if ($x !== []) {
            $adauga('rambursare_procesare', $t->t('admin.alerta.rambursare_procesare'), '/admin/plati', $x);
        }
        $x = $refs("SELECT ref FROM {comenzi} WHERE stare IN ('platita', 'trimitere_ec') AND fel = 'card' AND platita_la < ? ORDER BY id", [$app->ceas()->moment(-24 * 3600)]);
        if ($x !== []) {
            $adauga('blocata', $t->t('admin.alerta.blocata'), '/admin/plati', $x);
        }
        $x = $refs("SELECT ref FROM {comenzi} WHERE suprapunere = 1 AND stare IN ('confirmata', 'anulare_ceruta') AND plecare >= ? ORDER BY sosire", [$app->azi()]);
        if ($x !== []) {
            $adauga('suprapunere', $t->t('admin.alerta.suprapunere'), null, $x);
        }
        // verificarea prețului pe noapte nu mai are bloc propriu; o diferență găsită apare aici, cu nopțile ei.
        if (($par = $app->paritate()->stare()['diferente'] ?? []) !== []) {
            $lp = $t->limba();
            $titluri = AdminLista::titluriCamere($app);
            $randuri = [];
            foreach (array_filter((array) $par, static fn (mixed $x): bool => is_array($x) && isset($x['sosire'], $x['plecare'])) as $dif) {
                $randuri[] = $t->t('admin.pret.paritate_rand', ['perioada' => Format::zi((string) $dif['sosire'], $lp) . ' - ' . Format::zi((string) $dif['plecare'], $lp),
                    'camera' => (string) ($titluri[(string) ($dif['camera'] ?? '')] ?? $t->t('admin.pret.camera_fara_nume')), 'local' => Format::bani((int) ($dif['local'] ?? 0), $lp), 'easycheck' => Format::bani((int) ($dif['easycheck'] ?? 0), $lp)]);
            }
            $adauga('paritate', $t->t('admin.pret.paritate_diferenta') . ($randuri !== [] ? ' ' . implode('; ', $randuri) . '.' : '') . ' ' . $t->t('admin.pret.paritate_pas'), null);
        }
        // Sistemul: cronul, e-mailurile, auditul.
        $ultima = $b->valoare('SELECT MAX(ultima_la) FROM {sarcini}');
        if (is_string($ultima) && $ultima < $app->ceas()->moment(-3600)) {
            $adauga('cron', $t->t('admin.alerta.cron', ['cand' => Format::moment($ultima, $t->limba())]), '/admin/sistem');
        } elseif (($pauza = self::pauzaCron($app)) !== null) {
            // sarcinile au stat cel puțin o oră fiindcă site-ul n-are cron; alerta de 24 de ore cere cronul.
            $adauga('cron_pauza', $t->t('admin.alerta.cron_pauza', ['de_la' => Format::moment($pauza['de_la'], $t->limba()),
                'pana_la' => Format::moment($pauza['pana_la'], $t->limba())]), '/admin/sistem');
        }
        // E-mailurile care nu pleacă: alerta apare de la a doua încercare nereușită a unui mesaj (cam la un minut
        // după prima), nu abia după ce mesajul e abandonat (șase încercări, peste opt ore).
        $em = self::emailuriCuProbleme($app);
        if ($em['reincercate'] > 0) {
            $adauga('emailuri', $t->t('admin.alerta.emailuri_blocate', ['n' => (string) $em['reincercate']]), '/admin/emailuri');
        } elseif ($em['esuate'] > 0) {
            $adauga('emailuri', $t->t('admin.alerta.emailuri', ['n' => (string) $em['esuate']]), '/admin/emailuri');
        }
        if (($app->setari()->get('audit.verificare')['ok'] ?? true) === false) {
            $adauga('audit', $t->t('admin.alerta.audit'), '/admin/audit');
        }
        if ($app->setari()->get('site.publicat', false) !== true) {
            $adauga('nepublicat', $t->t('admin.alerta.nepublicat'), '/admin/site', [], false);
        }
        if ($u === null || ($u['rol'] ?? null) === 'proprietar') {
            return $a;
        }
        $vede = SesiuneAdmin::poateRambursa($u) ? [...self::ALERTE_BANI, ...self::ALERTE_TOTI] : self::ALERTE_TOTI;
        return array_values(array_filter($a, static fn (array $x): bool => in_array($x['cheie'], $vede, true)));
    }

    /** Cât rămâne alerta pauzei sarcinilor după ce sarcinile au pornit din nou. */
    public const PAUZA_CRON_ALERTA = 86400;

    /**
     * Ultima pauză a sarcinilor (Rulator::noteazaPauza), cât e mai nouă de 24 de ore și cronul din cPanel n-a rulat de
     * atunci (un cron adăugat între timp o închide). @return ?array{de_la:string, pana_la:string}
     */
    public static function pauzaCron(Aplicatie $app): ?array
    {
        $p = $app->setari()->get('cron.pauza');
        if (!is_array($p) || !is_string($p['de_la'] ?? null) || !is_string($p['pana_la'] ?? null) || $p['pana_la'] < $app->ceas()->moment(-self::PAUZA_CRON_ALERTA)) {
            return null;
        }
        $cpanel = $app->baza()->valoare("SELECT MAX(ultima_la) FROM {sarcini} WHERE sursa = 'cpanel'");
        return is_string($cpanel) && $cpanel >= $p['pana_la'] ? null : ['de_la' => $p['de_la'], 'pana_la' => $p['pana_la']];
    }

    // ---- Notele ascunse de gazdă ----

    /** Notele de informare pe care gazda le poate ascunde din Acasă și din Alerte, cu „Am înțeles”. */
    public const ASCUNSE_POSIBILE = ['avans_zero', 'tema_scoasa'];

    /**
     * Amprenta conținutului unei note care se poate ascunde: nota ascunsă revine când amprenta se schimbă. La
     * `avans_zero`: lista tarifelor vizibile cu avans 0% (un tarif nou în listă aduce nota înapoi).
     */
    public static function amprentaNotei(Aplicatie $app, string $cheie): string
    {
        return match ($cheie) {
            'avans_zero' => hash('sha256', implode("
", $app->modPlata()->tarifeFaraAvans('ro'))),
            'tema_scoasa' => hash('sha256', json_encode($app->setari()->get('tema.scoasa'), JSON_UNESCAPED_UNICODE) ?: ''),
            default => '',
        };
    }

    /** Nota e ascunsă de gazdă, iar conținutul ei e același ca atunci. */
    public static function ascunsa(Aplicatie $app, string $cheie): bool
    {
        $v = $app->setari()->get('alerte.ascunse');
        return is_array($v) && is_string($v[$cheie] ?? null) && hash_equals($v[$cheie], self::amprentaNotei($app, $cheie));
    }

    /**
     * POST /admin/alerte/ascunde (câmpul `cheie`): gazda ascunde o notă de informare din Acasă și din Alerte.
     * Doar proprietarul (notele de aici sunt ale lui) și doar notele din `ASCUNSE_POSIBILE`; alertele de rezolvat rămân.
     */
    public static function ascunde(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = self::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return self::inapoi($app);
        }
        $cheie = (string) ($c->camp('cheie') ?? '');
        // Notele stau pe Acasă (pagina Alerte s-a unit cu Acasă), deci întoarcerea e acolo.
        $inapoi = '/admin/';
        if (!in_array($cheie, self::ASCUNSE_POSIBILE, true)) {
            return self::inapoi($app, $inapoi);
        }
        $v = $app->setari()->proaspat('alerte.ascunse');
        $v = is_array($v) ? $v : [];
        $v[$cheie] = self::amprentaNotei($app, $cheie);
        $app->setari()->pune('alerte.ascunse', $v);
        $app->auditDetalii(['nota' => $cheie]);
        return self::inapoi($app, $inapoi, ['mesaj' => 'nota_ascunsa']);
    }

    /**
     * E-mailurile cu probleme, pentru alerte, pagina E-mailuri și Starea site-ului: câte mesaje din coadă au deja
     * cel puțin două încercări nereușite, câte au fost abandonate în ultima săptămână, ultima eroare (fără adrese) și
     * de când durează.
     * @return array{reincercate:int, esuate:int, eroare:?string, de_la:?string}
     */
    public static function emailuriCuProbleme(Aplicatie $app): array
    {
        $b = $app->baza();
        $re = $b->rand("SELECT COUNT(*) AS n, MIN(creat_la) AS de_la FROM {emailuri} WHERE stare = 'in_coada' AND incercari >= 2 AND eroare IS NOT NULL");
        $esuate = (int) $b->valoare("SELECT COUNT(*) FROM {emailuri} WHERE stare = 'esuat' AND creat_la > ?", [$app->ceas()->moment(-7 * 86400)]);
        $eroare = $b->valoare("SELECT eroare FROM {emailuri} WHERE eroare IS NOT NULL AND stare IN ('in_coada', 'esuat') ORDER BY id DESC LIMIT 1");
        return ['reincercate' => (int) ($re['n'] ?? 0), 'esuate' => $esuate, 'eroare' => is_string($eroare) && $eroare !== '' ? AdminSistem::faraAdrese($eroare) : null,
            'de_la' => isset($re['de_la']) && is_string($re['de_la']) ? $re['de_la'] : null];
    }

    /** Alertele despre bani: plăți, rambursări, anulări (inclusiv cele de lămurit). */
    public const ALERTE_BANI = ['plata_oprita', 'anulare_ceruta', 'anulare_refuzata', 'anulare_de_lamurit', 'de_rambursat', 'rambursare_esuata', 'rambursare_procesare', 'blocata', 'taxa_de_returnat', 'despagubire', 'comenzi_blocate'];

    /** Alertele despre rezervări, pentru oricine din panou. */
    public const ALERTE_TOTI = ['suprapunere', 'transfer_asteptat'];

    /** Șabloanele alertelor pe e-mail despre bani (jurnalul alertelor, după vizibilitatea de mai sus). */
    public static function sablonBani(string $sablon): bool
    {
        return (bool) preg_match('~rambursare|recuperare|disputa|plata_lipsa|anulare|alerta_comanda~', $sablon);
    }

    /**
     * Codurile de rezervă noi se arată o singură dată, pe pagina de după pornirea 2FA sau regenerare. Până atunci stau
     * criptate (subcheia TOTP) în setări, cel mult 10 minute.
     * @param list<string> $coduri
     */
    public static function pastreazaCoduri(Aplicatie $app, int $uid, array $coduri): void
    {
        $cutie = new \Cazo\Securitate\Cutie($app->chei()->subcheie('totp'));
        $app->setari()->pune('coduri_noi.' . $uid, ['c' => $cutie->cripteaza(implode(',', $coduri)), 'pana' => $app->ceas()->acum() + 600]);
    }

    /** @return list<string> codurile de arătat acum (și șterse din setări), sau [] */
    public static function coduriDeAfisat(Aplicatie $app, int $uid): array
    {
        try {
            $v = $app->setari()->proaspat('coduri_noi.' . $uid);
            if (!is_array($v)) {
                return [];
            }
            $app->baza()->executa('DELETE FROM {setari} WHERE cheie = ?', ['coduri_noi.' . $uid]);
            if ((int) ($v['pana'] ?? 0) < $app->ceas()->acum()) {
                return [];
            }
            $cutie = new \Cazo\Securitate\Cutie($app->chei()->subcheie('totp'));
            return explode(',', $cutie->decripteaza((string) $v['c']));
        } catch (\Throwable) {
            return [];
        }
    }

    /** Suma scrisă în lei pentru câmpurile panoului; delegată regulii din această versiune. */
    public static function bani(string $s): ?int
    {
        return AdminRezervari::bani($s);
    }
}
