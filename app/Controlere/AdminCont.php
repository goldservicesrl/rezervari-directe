<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\I18n\Format;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;
use Cazo\Securitate\Ip;
use Cazo\Securitate\SesiuneAdmin;
use Cazo\Securitate\Totp;
use Cazo\Securitate\Utilizatori;

defined('CAZO') or exit;

/**
 * Securitatea panoului (14 Securitate):
 *
 *   GET  /admin/cont                         contul meu: numele, limba panoului, parola, 2FA, codurile de rezervă,
 *                                            sesiunile active
 *   POST /admin/cont/{actiune}               limba | nume | parola | sesiuni | coduri (regenerarea cere re-autentificarea)
 *   GET  /admin/utilizatori                  utilizatorii și rolurile (proprietarul)
 *   POST /admin/utilizatori/invita           invitația pe e-mail (re-autentificare: „utilizatori noi”)
 *   POST /admin/utilizatori/{id}/{actiune}   dezactiveaza | reactiveaza | rol | drept | reinvita | reseteaza-2fa
 *   GET  /admin/audit                        jurnalul de audit, cu verificarea lanțului
 *   GET|POST /admin/parola-uitata            cererea de resetare a parolei, pe e-mail
 *   GET|POST /admin/parola/{jeton}           parola nouă, cu jetonul din e-mail (2FA rămâne cerută la intrare)
 *   GET|POST /admin/invitatie/{jeton}        invitația acceptată: parola și limba panoului
 *
 * Căile de recuperare, fără acces la fișiere: parola uitată se resetează prin e-mail; telefonul pierdut se
 * acoperă cu un cod de rezervă (intrarea, oprirea 2FA, trecerea pe alt telefon); un angajat sau un proprietar care a
 * pierdut și codurile primește resetarea 2FA de la un proprietar. Singurul proprietar fără telefon și fără coduri
 * rămâne la accesul la găzduire (comanda `bin/cazo admin`), ca până acum.
 */
final class AdminCont
{
    public const MESAJE = ['salvat' => 'admin.salvat', 'parola' => 'admin.cont.parola_schimbata', 'sesiuni' => 'admin.cont.sesiuni_inchise',
        'coduri_noi' => 'admin.cont.coduri_noi', '2fa_pornita' => 'admin.2fa.pornita', '2fa_oprita' => 'admin.2fa.oprita', '2fa_renuntat' => 'admin.2fa.renuntat',
        'date' => 'admin.cont.date_salvate'];
    public const ERORI = ['parola' => 'admin.eroare.parola', 'scurta' => 'admin.cont.eroare_scurta', 'compromisa' => 'admin.cont.eroare_compromisa', 'reauth' => 'admin.reauth.eroare', 'cod' => 'admin.2fa.cod_gresit',
        'cod_vechi' => 'admin.2fa.cod_vechi_gresit', 'cod_limita' => 'admin.2fa.cod_limita', 'fara_2fa' => 'admin.cont.eroare_fara_2fa', 'expirat' => 'admin.2fa.expirat'];
    public const MESAJE_UTILIZATORI = ['invitat' => 'admin.utilizatori.invitat', 'salvat' => 'admin.salvat', 'dezactivat' => 'admin.utilizatori.dezactivat',
        'reactivat' => 'admin.utilizatori.reactivat', 'resetat' => 'admin.utilizatori.2fa_resetata',
        'rol_proprietar' => 'admin.utilizatori.rol_proprietar', 'rol_personal' => 'admin.utilizatori.rol_personal', 'drept_dat' => 'admin.utilizatori.drept_dat', 'drept_luat' => 'admin.utilizatori.drept_luat'];
    public const ERORI_UTILIZATORI = ['reauth' => 'admin.reauth.eroare', 'email' => 'admin.utilizatori.eroare_email', 'exista' => 'admin.utilizatori.eroare_exista',
        'ultimul' => 'admin.utilizatori.eroare_ultimul', 'lipsa' => 'admin.utilizatori.eroare_lipsa', 'rol' => 'admin.utilizatori.eroare_lipsa', 'tu' => 'admin.utilizatori.eroare_propriu',
        'ocupat' => 'admin.utilizatori.eroare_ocupat', 'confirmare_rol' => 'admin.utilizatori.eroare_confirmare_rol', 'parola_rol' => 'admin.eroare.parola'];
    /** Mesajele formularului de intrare, după pașii fără sesiune. */
    public const MESAJE_PUBLICE = ['parola_salvata' => 'admin.cont.parola_salvata_intrati', 'invitatie' => 'admin.invitatie.gata'];

    // ---- Contul meu ----

    public static function cont(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'cont', self::MESAJE, self::ERORI);
        $uid = (int) $u['id'];
        $ramase = $app->sesiuneAdmin()->coduriRamase($uid);
        if ($c->param('mesaj') === 'cod_rezerva') {
            $d['mesaj'] = $t->t('admin.cont.intrat_cu_cod', ['n' => (string) $ramase]);
        }
        $doiF = (int) $u['are_2fa'] === 1;
        $sesiuni = [];
        foreach ($app->sesiuneAdmin()->sesiuni($uid, $jeton) as $x) {
            $sesiuni[] = ['curenta' => $x['curenta'], 'creat_la' => Format::moment($x['creat_la'], $l), 'vazut_la' => Format::moment($x['vazut_la'], $l), 'ip24' => $x['ip24'],
                'agent' => self::browser((string) $x['agent_scurt'], $t)];
        }
        $d += [
            'nume' => (string) $u['nume'],
            // în modurile E și P, dreptul de rambursare al panoului nu mai are efect (rambursările se hotărăsc în EasyCheck).
            'rol' => $t->t('admin.rol.' . $u['rol']) . (SesiuneAdmin::poateRambursa($u) && $u['rol'] === 'personal' && !$app->modEc()->deciziiInEasyCheck() ? ', ' . $t->t('admin.utilizatori.cu_rambursari') : ''),
            'doi_factori' => $doiF,
            'coduri_ramase' => $ramase,
            'coduri_putine' => $doiF && $ramase <= 3,
            'recomanda_2fa' => !$doiF && ($u['rol'] === 'proprietar' || SesiuneAdmin::poateRambursa($u)),
            // cheia verificării în doi pași apare doar pe pagina pornirii, după ce gazda o cere cu parola confirmată.
            'in_lucru' => self::secretInLucru($app, $uid, $jeton) !== null,
            'sesiuni' => $sesiuni,
            'alte_sesiuni' => count($sesiuni) > 1,
            'url' => ['cont' => $app->url()->cale('/admin/cont'), '2fa' => $app->url()->cale('/admin/2fa'), '2fa_pagina' => $app->url()->cale('/admin/cont/2fa')],
        ];
        return AdminPanou::pagina($app, $u, 'cont', $d, $r);
    }

    public static function contActiune(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $uid = (int) $u['id'];
        $inapoi = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/cont', $q);
        switch ((string) $p['actiune']) {
            case 'limba':
                $limbaNoua = (string) ($c->camp('limba') ?? '');
                if (!in_array($limbaNoua, Utilizatori::LIMBI, true)) {
                    return $inapoi([]);
                }
                $app->utilizatori()->schimbaLimba($uid, $limbaNoua);
                $app->auditDetalii(['limba' => $limbaNoua]);
                return $inapoi(['mesaj' => 'date'])->cookie(AdminPanou::cookieLimba($limbaNoua));
            case 'nume':
                $app->utilizatori()->schimbaNumele($uid, (string) ($c->camp('nume') ?? ''));
                return $inapoi(['mesaj' => 'date']);
            case 'date':
                // „Datele mele”: numele și limba panoului, într-un singur formular.
                $app->utilizatori()->schimbaNumele($uid, (string) ($c->camp('nume') ?? ''));
                $limbaNoua = (string) ($c->camp('limba') ?? '');
                $rasp = $inapoi(['mesaj' => 'date']);
                if (in_array($limbaNoua, Utilizatori::LIMBI, true) && $limbaNoua !== (string) $u['limba_panou']) {
                    $app->utilizatori()->schimbaLimba($uid, $limbaNoua);
                    $app->auditDetalii(['limba' => $limbaNoua]);
                    $rasp->cookie(AdminPanou::cookieLimba($limbaNoua));
                }
                return $rasp;
            case 'parola':
                $rez = $app->utilizatori()->schimbaParola($uid, (string) ($c->camp('parola') ?? ''), (string) ($c->camp('parola_noua') ?? ''), $jeton);
                return $rez === 'ok' ? $inapoi(['mesaj' => 'parola']) : $inapoi(['eroare' => $rez]);
            case 'sesiuni':
                $app->auditDetalii(['inchise' => $app->sesiuneAdmin()->inchideCelelalte($uid, $jeton)]);
                return $inapoi(['mesaj' => 'sesiuni']);
            case 'coduri':
                if ((int) $u['are_2fa'] !== 1) {
                    return $inapoi(['eroare' => 'fara_2fa']);
                }
                if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
                    return $inapoi(['eroare' => 'reauth']);
                }
                AdminPanou::pastreazaCoduri($app, $uid, $app->sesiuneAdmin()->coduriRezervaNoi($uid));
                return $inapoi(['mesaj' => 'coduri_noi']);
        }
        return $inapoi([]);
    }

    /**
     * POST /admin/reautentificare: parola confirmată pentru 10 minute, apoi înapoi în secțiunea de unde a plecat
     * (doar numele unei secțiuni din meniu, deci fără redirecționări în afara panoului).
     */
    public static function reautentificare(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $sectiune = (string) ($c->camp('inapoi') ?? '');
        // o secțiune de dinainte duce la pagina nouă și la blocul ei.
        $q = AdminPanou::reautentificat($app, $c, $u, $jeton) ? [] : ['eroare' => 'reauth'];
        // Paginile cu adresă proprie: o rezervare (`ref`) și pornirea verificării în doi pași.
        if (preg_match('~^PG-[0-9A-Z]{6}$~', (string) ($c->camp('ref') ?? ''))) {
            return AdminPanou::inapoi($app, '/admin/rezervari/' . $c->camp('ref'), $q);
        }
        if (isset(AdminCompus::SECTIUNI[$sectiune])) {
            return Raspuns::redirectare(AdminCompus::adresa($app, $sectiune, $q + (AdminCompus::SECTIUNI[$sectiune][1] !== null ? ['bloc' => AdminCompus::SECTIUNI[$sectiune][1]] : [])));
        }
        return AdminPanou::inapoi($app, AdminPanou::SECTIUNI[$sectiune][0] ?? '/admin/', $q);
    }

    // ---- Verificarea în doi pași: doar aici, cu cheia arătată după cererea pornirii ----

    /** Cât rămâne valabilă cheia unei porniri începute: 15 minute. */
    public const TOTP_IN_LUCRU = 900;

    /**
     * Cheia unei porniri începute și neîncheiate (criptată în setări, ca și codurile de rezervă abia generate), sau null.
     * Cheia ține de sesiunea care a cerut pornirea cu parola confirmată: cu `$jeton` dat, altă sesiune a aceluiași cont
     * (de pe alt dispozitiv sau una furată) primește null, deci nu o vede și nu o poate confirma.
     */
    public static function secretInLucru(Aplicatie $app, int $uid, ?string $jeton = null): ?string
    {
        try {
            $v = $app->setari()->proaspat('totp_noua.' . $uid);
            if (!is_array($v) || (int) ($v['pana'] ?? 0) < $app->ceas()->acum()) {
                return null;
            }
            if ($jeton !== null && !hash_equals((string) ($v['s'] ?? ''), hash('sha256', $jeton))) {
                return null;
            }
            $secret = (new \Cazo\Securitate\Cutie($app->chei()->subcheie('totp')))->decripteaza((string) $v['c']);
            return preg_match('~^[A-Z2-7]{32}$~', $secret) ? $secret : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function stergeSecretInLucru(Aplicatie $app, int $uid): void
    {
        $app->baza()->executa('DELETE FROM {setari} WHERE cheie = ?', ['totp_noua.' . $uid]);
    }

    /**
     * GET /admin/cont/2fa: pasul pornirii, cu codul QR (generat local, SVG), cheia ca text sub „Nu puteți scana?” și
     * câmpul codului. Cheia e cea ținută de la „Porniți”: aceeași până la confirmare, renunțare sau expirare.
     */
    public static function doiFactoriPagina(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $uid = (int) $u['id'];
        $secret = self::secretInLucru($app, $uid, $jeton);
        if ($secret === null) {
            return AdminPanou::inapoi($app, '/admin/cont', $c->param('eroare') !== null ? ['eroare' => 'expirat'] : []);
        }
        $t = $app->traducator((string) $u['limba_panou'], 'admin');
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'cont', [], self::ERORI);
        $uri = Totp::uri($secret, (string) $u['email'], $app->modelVedere('ro')->nume() ?: 'Site');
        $d += ['qr' => \Cazo\Securitate\CodQr::svg($uri, 'M', 4, $t->t('admin.2fa.qr_titlu')), 'grupe' => trim(chunk_split($secret, 4, ' ')), 'cont_2fa' => (string) $u['email'],
            'inlocuire' => (int) $u['are_2fa'] === 1, 'url' => ['2fa' => $app->url()->cale('/admin/2fa'), 'cont' => $app->url()->cale('/admin/cont')]];
        return AdminPanou::pagina($app, $u, 'cont-2fa', $d, $r);
    }

    /**
     * POST /admin/2fa, pentru oricine:
     *   incepe    cererea pornirii (sau a trecerii pe alt telefon), cu parola confirmată în ultimele 10 minute; creează
     *             cheia și o ține criptată până la confirmare, legată de sesiunea care a cerut-o;
     *   porneste  codul din aplicație (și codul vechi, la înlocuire) pentru cheia ținută; cheia nu vine din formular;
     *   renunta   șterge cheia ținută;
     *   opreste   cu parola scrisă acum și un cod valid.
     */
    public static function doiFactori(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $uid = (int) $u['id'];
        $cont = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/cont', $q);
        $pas = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/cont/2fa', $q);
        $a = (string) ($c->camp('actiune') ?? '');
        if ($a === 'opreste') {
            if (!$app->sesiuneAdmin()->reconfirma($uid, (string) ($c->camp('parola') ?? ''))) {
                return $cont(['eroare' => 'parola']);
            }
            // Oprirea cere și un cod valid din aplicație: o sesiune furată cu parola aflată nu scoate 2FA.
            if (!$app->sesiuneAdmin()->codValid($uid, (string) ($c->camp('cod') ?? ''))) {
                return $cont(['eroare' => $app->sesiuneAdmin()->coduriEpuizate($uid) ? 'cod_limita' : 'cod']);
            }
            $app->sesiuneAdmin()->dezactiveazaTotp($uid);
            self::stergeSecretInLucru($app, $uid);
            return $cont(['mesaj' => '2fa_oprita']);
        }
        if ($a === 'renunta') {
            self::stergeSecretInLucru($app, $uid);
            return $cont(['mesaj' => '2fa_renuntat']);
        }
        if ($a === 'incepe') {
            if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
                return $cont(['eroare' => 'reauth']);
            }
            // O pornire deja începută își păstrează cheia: gazda care a scanat-o și revine găsește aceeași cheie.
            if (self::secretInLucru($app, $uid, $jeton) === null) {
                $cutie = new \Cazo\Securitate\Cutie($app->chei()->subcheie('totp'));
                $app->setari()->pune('totp_noua.' . $uid, ['c' => $cutie->cripteaza(Totp::secretNou()), 'pana' => $app->ceas()->acum() + self::TOTP_IN_LUCRU,
                    's' => hash('sha256', $jeton)]);
            }
            return $pas([]);
        }
        if ($a !== 'porneste') {
            return $cont([]);
        }
        $secret = self::secretInLucru($app, $uid, $jeton);
        if ($secret === null) {
            return $cont(['eroare' => 'expirat']);
        }
        $r = $app->sesiuneAdmin()->activeazaTotp($uid, $secret, (string) ($c->camp('cod') ?? ''), $c->camp('cod_vechi'));
        if ($r !== 'ok') {
            return $pas(['eroare' => match ($r) {
                'cod_vechi' => $app->sesiuneAdmin()->coduriEpuizate($uid) ? 'cod_limita' : 'cod_vechi',
                'limita' => 'cod_limita',
                default => 'cod',
            }]);
        }
        self::stergeSecretInLucru($app, $uid);
        if ((int) ($u['are_2fa'] ?? 0) === 0) {
            // La prima pornire, cele 10 coduri de rezervă, arătate o singură dată pe pagina următoare.
            AdminPanou::pastreazaCoduri($app, $uid, $app->sesiuneAdmin()->coduriRezervaNoi($uid));
        }
        return $cont(['mesaj' => '2fa_pornita']);
    }

    /** Numele browserului și al sistemului dintr-un User-Agent („Chrome pe Windows”); necunoscut: textul scurtat, ca până acum. */
    public static function browser(string $agent, \Cazo\I18n\Traducator $t): string
    {
        $b = match (true) {
            (bool) preg_match('~Edg(e|A|iOS)?/~', $agent) => 'Edge',
            (bool) preg_match('~OPR/|Opera~', $agent) => 'Opera',
            (bool) preg_match('~Firefox/|FxiOS/~', $agent) => 'Firefox',
            (bool) preg_match('~Chrome/|CriOS/|Chromium/~', $agent) => 'Chrome',
            (bool) preg_match('~Safari/~', $agent) => 'Safari',
            default => null,
        };
        $s = match (true) {
            (bool) preg_match('~iPhone|iPad|iPod~', $agent) => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            (bool) preg_match('~Mac OS X|Macintosh~', $agent) => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };
        if ($b === null) {
            return $agent !== '' ? mb_substr($agent, 0, 60) : $t->t('admin.cont.browser_necunoscut');
        }
        return $s !== null ? $t->t('admin.cont.browser_pe', ['browser' => $b, 'sistem' => $s]) : $b;
    }

    // ---- Utilizatorii (proprietarul) ----

    public static function utilizatori(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        return self::paginaUtilizatori($app, $c, $u, $jeton);
    }

    /**
     * Pagina „Utilizatori”. La o greșeală a invitației se întoarce cu 422, cu valorile scrise ($valori) și cu eroarea
     * lângă câmp ($erori: numele câmpului => textul).
     */
    private static function paginaUtilizatori(Aplicatie $app, Cerere $c, array $u, string $jeton, array $valori = [], array $erori = [], ?string $eroare = null): Raspuns
    {
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('', $eroare !== null ? 422 : 200);
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'utilizatori', self::MESAJE_UTILIZATORI, self::ERORI_UTILIZATORI);
        if ($eroare !== null) {
            $d['eroare'] = $eroare;
            $d['mesaj'] = null;
        }
        $lista = [];
        $colegi = false;
        foreach ($app->utilizatori()->toti() as $x) {
            $stare = (int) $x['activ'] !== 1 ? 'dezactivat' : ((int) $x['invitat'] === 1 ? 'invitat' : 'activ');
            $eu = (int) $x['id'] === (int) $u['id'];
            $colegi = $colegi || !$eu;
            $limbaLui = in_array($x['limba_panou'], Utilizatori::LIMBI, true) ? (string) $x['limba_panou'] : 'ro';
            $lista[] = ['id' => (int) $x['id'], 'email' => (string) $x['email'], 'nume' => (string) $x['nume'], 'rol' => (string) $x['rol'],
                'rol_text' => $t->t('admin.rol.' . $x['rol']), 'poate' => (int) $x['poate_rambursa'] === 1, 'doi_f' => (int) $x['are_2fa'] === 1, 'stare' => $stare,
                'stare_text' => $t->t('admin.utilizatori.stare.' . $stare), 'eu' => $eu,
                'ultima' => $x['ultima_logare_la'] !== null ? Format::moment((string) $x['ultima_logare_la'], $l) : $t->t('admin.utilizatori.niciodata'),
                'limba' => $t->t('admin.utilizatori.limba.' . $limbaLui)];
        }
        $d += ['lista' => $lista, 'colegi' => $colegi, 'invitatie' => $valori + ['email' => '', 'nume' => '', 'rol' => 'personal', 'poate_rambursa' => false, 'limba' => 'ro'],
            // în modurile E și P, fără „Poate face rambursări”: rambursările se hotărăsc în EasyCheck.
            'drepturi_rambursare' => !$app->modEc()->deciziiInEasyCheck(),
            'erori_camp' => $erori, 'url' => ['baza' => $app->url()->cale('/admin/utilizatori')]];
        return AdminPanou::pagina($app, $u, 'utilizatori', $d, $r);
    }

    public static function invita(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $t = $app->traducator((string) $u['limba_panou'], 'admin');
        $inapoi = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/utilizatori', $q);
        $rol = (string) ($c->camp('rol') ?? '');
        // Valorile scrise, pentru pagina întoarsă la o greșeală.
        $scrise = ['email' => trim((string) ($c->camp('email') ?? '')), 'nume' => trim(mb_substr((string) ($c->camp('nume') ?? ''), 0, 120)), 'rol' => $rol === 'proprietar' ? 'proprietar' : 'personal',
            'poate_rambursa' => $c->camp('poate_rambursa') === '1', 'limba' => $c->camp('limba') === 'en' ? 'en' : 'ro'];
        if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
            $app->auditDetalii(['eroare' => 'reauth']);
            return self::paginaUtilizatori($app, $c, $u, $jeton, $scrise, [], $t->t('admin.reauth.eroare'));
        }
        // Dreptul „Poate face rambursări” e al rolului Personal: la Proprietar bifa e ignorată (și aici, și în Utilizatori::invita).
        // în modurile E și P bifa e ascunsă și serverul o ignoră.
        $cuRambursari = $rol === 'personal' && $c->camp('poate_rambursa') === '1' && !$app->modEc()->deciziiInEasyCheck();
        $rez = $app->utilizatori()->invita((string) ($c->camp('email') ?? ''), (string) ($c->camp('nume') ?? ''), $rol, (string) ($c->camp('limba') ?? 'ro'),
            $cuRambursari, (int) $u['id']);
        if (isset($rez['eroare'])) {
            if (in_array($rez['eroare'], ['email', 'exista'], true)) {
                $app->auditDetalii(['eroare' => (string) $rez['eroare']]);
                return self::paginaUtilizatori($app, $c, $u, $jeton, $scrise, ['email' => $t->t(self::ERORI_UTILIZATORI[$rez['eroare']])], $t->t('admin.utilizatori.eroare_invitatie'));
            }
            return $inapoi(['eroare' => $rez['eroare']]);
        }
        self::trimiteInvitatia($app, (int) $rez['id'], (string) $rez['jeton']);
        // angajatul invitat cu dreptul de rambursare primește și atenționarea cu recomandarea 2FA.
        $x = $app->utilizatori()->dupaId((int) $rez['id']);
        if ($x !== null && $x['rol'] === 'personal' && (int) $x['poate_rambursa'] === 1) {
            $app->emailuri()->pune(null, 'drept_rambursare', (string) $x['limba_panou'], (string) $x['email'], []);
        }
        $app->auditDetalii(['utilizator' => (int) $rez['id'], 'rol' => $rol, 'rambursari' => $cuRambursari]);
        return $inapoi(['mesaj' => 'invitat']);
    }

    public static function utilizatorActiune(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $inapoi = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/utilizatori', $q);
        $id = (int) $p['id'];
        $a = (string) $p['actiune'];
        // Propriul cont se schimbă din „Contul meu”: aici, doar ceilalți (rolul propriu și dezactivarea proprie ar putea
        // lăsa site-ul fără proprietar dintr-un clic).
        if ($id === (int) $u['id'] && $a !== 'reinvita') {
            return $inapoi(['eroare' => 'tu']);
        }
        if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
            return $inapoi(['eroare' => 'reauth']);
        }
        $ut = $app->utilizatori();
        switch ($a) {
            case 'dezactiveaza':
                $rez = $ut->dezactiveaza($id);
                return $rez === 'ok' ? $inapoi(['mesaj' => 'dezactivat']) : $inapoi(['eroare' => $rez]);
            case 'reactiveaza':
                $rez = $ut->reactiveaza($id);
                return $rez === 'ok' ? $inapoi(['mesaj' => 'reactivat']) : $inapoi(['eroare' => $rez]);
            case 'rol':
                $rol = (string) ($c->camp('rol') ?? '');
                $app->auditDetalii(['rol' => $rol]);
                // trecerea pe rolul de proprietar dă acces la chei și la datele oaspeților. Cere bifa care spune asta
                // și parola scrisă acum, la fiecare trecere, pe lângă parola confirmată a celorlalte schimbări.
                if ($rol === 'proprietar') {
                    if ($c->camp('confirm') !== '1') {
                        return $inapoi(['eroare' => 'confirmare_rol']);
                    }
                    if (!$app->sesiuneAdmin()->reconfirma((int) $u['id'], (string) ($c->camp('parola') ?? ''))) {
                        return $inapoi(['eroare' => 'parola_rol']);
                    }
                }
                $rez = $ut->schimbaRol($id, $rol);
                return $rez === 'ok' ? $inapoi(['mesaj' => $rol === 'proprietar' ? 'rol_proprietar' : 'rol_personal']) : $inapoi(['eroare' => $rez]);
            case 'drept':
                $da = $c->camp('da') === '1';
                $nou = $app->sesiuneAdmin()->dreptRambursare($id, $da);
                // atenționarea și recomandarea 2FA pe e-mail, la primirea dreptului.
                if ($nou !== null && (int) $nou['are_2fa'] === 0) {
                    $app->emailuri()->pune(null, 'drept_rambursare', (string) $nou['limba_panou'], (string) $nou['email'], []);
                    $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
                }
                $app->auditDetalii(['rambursari' => $da]);
                return $inapoi(['mesaj' => $da ? 'drept_dat' : 'drept_luat']);
            case 'reinvita':
                $j = $ut->reinvita($id, (int) $u['id']);
                if ($j === null) {
                    return $inapoi(['eroare' => 'lipsa']);
                }
                self::trimiteInvitatia($app, $id, $j);
                return $inapoi(['mesaj' => 'invitat']);
            case 'reseteaza-2fa':
                $rez = $ut->reseteaza2fa($id);
                return $rez === 'ok' ? $inapoi(['mesaj' => 'resetat']) : $inapoi(['eroare' => $rez]);
        }
        return $inapoi([]);
    }

    private static function trimiteInvitatia(Aplicatie $app, int $id, string $jeton): void
    {
        $x = $app->utilizatori()->dupaId($id);
        if ($x === null) {
            return;
        }
        $app->emailuri()->pune(null, 'invitatie', (string) $x['limba_panou'], (string) $x['email'],
            ['url' => $app->emailuri()->secret($app->url()->absolut('/admin/invitatie/' . $jeton)), 'rol' => (string) $x['rol']]);
        $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
    }

    // ---- Jurnalul de audit (proprietarul) ----

    public static function audit(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'audit');
        if ($c->param('verifica') === '1') {
            $v = $app->audit()->verifica();
            $app->setari()->pune('audit.verificare', $v + ['la' => $app->ceas()->acum()], (int) $u['id']);
        }
        $v = $app->setari()->get('audit.verificare');
        $d['verificare'] = null;
        if (is_array($v)) {
            $cand = Format::moment(gmdate('Y-m-d H:i:s', (int) $v['la']), $l);
            $d['verificare'] = $v['ok'] ? $t->t('admin.audit.lant_ok', ['cand' => $cand]) : $t->t('admin.audit.lant_rupt', ['id' => (string) $v['rupt_la'], 'cand' => $cand]);
            $d['verificare_ok'] = (bool) $v['ok'];
        }
        $actiune = (string) ($c->param('actiune') ?? '');
        $actiuni = [];
        foreach ($app->audit()->actiuni() as $x) {
            $actiuni[] = ['cod' => $x, 'nume' => self::numeActiune($t, $x, '')];
        }
        usort($actiuni, static fn (array $a, array $b): int => strcmp($a['nume'], $b['nume']));
        if (!in_array($actiune, array_column($actiuni, 'cod'), true)) {
            $actiune = '';
        }
        $pagina = max(1, (int) ($c->param('pagina') ?? 1));
        $emailuri = array_column($app->baza()->toate('SELECT id, email FROM {utilizatori}'), 'email', 'id');
        $randuri = [];
        foreach ($app->audit()->pagina($pagina, 50, $actiune) as $x) {
            $obiect = (string) ($x['obiect'] ?? '');
            $detalii = (string) ($x['detalii'] ?? '');
            $det = json_decode($detalii, true);
            // Obiectul pe înțeles: codul rezervării (cu link) sau contul; restul rămâne sub „Detalii”.
            $ref = preg_match('~\bPG-[0-9A-Z]{6}\b~', $obiect, $m) ? $m[0] : '';
            $cont = str_starts_with((string) $x['actiune'], 'admin-utilizator') && preg_match('~\bid=(\d+)\b~', $obiect, $m) ? (string) ($emailuri[(int) $m[1]] ?? '') : '';
            $randuri[] = ['id' => (int) $x['id'], 'la' => Format::moment((string) $x['la'], $l), 'cine' => $x['utilizator_id'] !== null ? (string) ($emailuri[$x['utilizator_id']] ?? '#' . $x['utilizator_id']) : $t->t('admin.audit.sistem'),
                'actiune' => self::numeActiune($t, (string) $x['actiune'], $obiect), 'cod' => (string) $x['actiune'], 'ref' => $ref, 'cont' => $cont,
                'refuzata' => is_array($det) && (isset($det['eroare']) || (int) ($det['status'] ?? 0) >= 400),
                'obiect' => $obiect, 'detalii' => $detalii, 'ip24' => (string) ($x['ip24'] ?? '')];
        }
        $total = $app->audit()->numar($actiune);
        // jurnalul de audit e un filtru al jurnalului de pe Setări › Întreținere.
        $q = ['jurnal' => 'audit'] + ($actiune !== '' ? ['actiune' => $actiune] : []);
        $spre = static fn (array $x): string => $app->url()->cale('/admin/intretinere', null, $x) . '#jurnal';
        $d += ['randuri' => $randuri, 'actiuni' => $actiuni, 'actiune' => $actiune, 'pagina' => $pagina, 'total' => $total, 'jurnal_fel' => 'audit', 'proprietar' => true,
            'url' => ['baza' => $app->url()->cale('/admin/intretinere') . '#jurnal', 'verifica' => $spre(['jurnal' => 'audit', 'verifica' => '1']),
                'jurnal_alerte' => $spre(['jurnal' => 'alerte']), 'jurnal_audit' => $spre(['jurnal' => 'audit']),
                'inainte' => $pagina > 1 ? $spre($q + ['pagina' => (string) ($pagina - 1)]) : null,
                'dupa' => $pagina * 50 < $total ? $spre($q + ['pagina' => (string) ($pagina + 1)]) : null]];
        return AdminPanou::pagina($app, $u, 'audit', $d, $r);
    }

    /**
     * Numele pe înțeles al unei acțiuni din audit: catalogul `admin.audit.actiune.<numele rutei>`, cu varianta mai
     * exactă `admin.audit.actiune.<numele rutei>.<acțiunea sau decizia din adresă>` unde există. Numele rutei rămâne
     * sub „Detalii”.
     */
    public static function numeActiune(\Cazo\I18n\Traducator $t, string $actiune, string $obiect): string
    {
        if (preg_match('~(?:^| )(?:actiune|decizie)=([a-z0-9\-]+)~', $obiect, $m) && $t->are('admin.audit.actiune.' . $actiune . '.' . $m[1])) {
            return $t->t('admin.audit.actiune.' . $actiune . '.' . $m[1]);
        }
        return $t->are('admin.audit.actiune.' . $actiune) ? $t->t('admin.audit.actiune.' . $actiune) : $t->t('admin.audit.actiune_alta');
    }

    // ---- Fără sesiune: parola uitată, parola nouă, invitația ----

    public static function parolaUitata(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        [$l, $cookieLimba] = AdminPanou::limbaPublica($app, $c);
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('');
        if ($cookieLimba !== null) {
            $r->cookie($cookieLimba);
        }
        $trimis = false;
        if ($c->metoda === 'POST') {
            $email = mb_strtolower(trim((string) ($c->camp('email') ?? '')));
            // 3 cereri pe oră pe adresă, 10 pe IP/24; răspunsul e același oricare ar fi adresa.
            if ($app->limitator()->incearca('admin:resetare:ip:' . Ip::trunchiaza($c->ip), 10, 3600)
                && $app->limitator()->incearca('admin:resetare:' . hash('sha256', $email), 3, 3600)) {
                $x = $app->utilizatori()->cereResetare($email);
                if ($x !== null) {
                    $app->emailuri()->pune(null, 'resetare_parola', $x['limba_panou'], $x['email'], ['url' => $app->emailuri()->secret($app->url()->absolut('/admin/parola/' . $x['jeton']))]);
                    $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
                    $app->auditDetalii(['utilizator' => $x['id']]);
                }
            }
            $trimis = true;
        }
        return Admin::pagina($app, $l, 'parola-uitata', ['csrf' => $app->jetonCsrf($c, $r), 'trimis' => $trimis, 'actiune' => $app->url()->cale('/admin/parola-uitata'),
            'url_intrare' => $app->url()->cale('/admin/'), 'alta_limba' => AdminPanou::altaLimba($app, $l, '/admin/parola-uitata')], $r);
    }

    /** /admin/parola/{jeton} și /admin/invitatie/{jeton}: formularul parolei noi și salvarea ei. */
    public static function parolaNoua(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $invitatie = str_starts_with($c->cale, '/admin/invitatie/');
        $scop = $invitatie ? 'invitatie' : 'resetare';
        $jeton = (string) $p['jeton'];
        $x = $app->utilizatori()->dupaJeton($jeton, $scop);
        $l = $x !== null ? (string) $x['limba_panou'] : AdminPanou::limbaPublica($app, $c)[0];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('', $x === null ? 404 : 200);
        $eroare = null;
        if ($x !== null && $c->metoda === 'POST') {
            $parola = (string) ($c->camp('parola') ?? '');
            if ($parola !== (string) ($c->camp('parola_repetata') ?? '')) {
                $eroare = $t->t('admin.cont.eroare_repetata');
            } else {
                $rez = $app->utilizatori()->folosesteJeton($jeton, $scop, $parola, $invitatie ? (string) ($c->camp('limba') ?? '') : null);
                if (is_int($rez)) {
                    $app->auditDetalii(['utilizator' => $rez, 'scop' => $scop]);
                    return Raspuns::redirectare($app->url()->cale('/admin/', null, ['mesaj' => $invitatie ? 'invitatie' : 'parola_salvata']));
                }
                $eroare = $t->t(match ($rez) {
                    'parola' => 'admin.cont.eroare_scurta',
                    'compromisa' => 'admin.cont.eroare_compromisa',
                    default => 'admin.cont.jeton_expirat',
                });
            }
        }
        return Admin::pagina($app, $l, 'parola-noua', ['csrf' => $app->jetonCsrf($c, $r), 'valid' => $x !== null, 'invitatie' => $invitatie, 'email' => $x['email'] ?? '',
            'limba_aleasa' => $l, 'eroare' => $eroare, 'actiune' => $app->url()->cale($c->cale), 'url_intrare' => $app->url()->cale('/admin/'),
            'url_uitata' => $app->url()->cale('/admin/parola-uitata')], $r);
    }

}
