<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Intretinere;
use Cazo\Nucleu\Jurnal;
use Cazo\Nucleu\Raspuns;
use Cazo\Sistem\Copii;

defined('CAZO') or exit;

/**
 * Grupul „Setări” și pagina „Camere”:
 *
 *   GET  /admin/sistem                         Starea site-ului: trei rânduri pe înțelesul gazdei (site-ul, sarcinile
 *                                              automate, e-mailurile); restul diagnosticului (PHP și extensiile, HTTPS,
 *                                              baza, sarcinile, bugetul API, ultimele erori) stă sub „Detalii pentru
 *                                              firma de găzduire”
 *   POST /admin/sistem/pachet                  diagnosticul descărcabil: JSON fără secrete și fără date personale
 *   GET  /admin/copii                          copiile de siguranță, pe pagina lor
 *   GET  /admin/copii/restaurare?copie=...     pasul de confirmare al restaurării (explicația, bifa, parola)
 *   POST /admin/sistem/copii/{actiune}         creeaza | descarca | restaureaza | sterge (toate, afară de creare, cu
 *                                              re-autentificarea; restaurarea și cu bifa verificată aici)
 *   GET  /admin/conexiuni                      Legătura cu EasyCheck: cheia (modul, scopul, sursa prețului), anunțurile,
 *                                              ce aducem din EasyCheck
 *   POST /admin/conexiuni/{actiune}            cheie (re-autentificarea) | revino | anunturi | sincronizeaza
 *   GET  /admin/emailuri                       Trimiterea e-mailurilor: expeditorul, SMTP, proba, mesajele cu probleme
 *   POST /admin/emailuri/{actiune}             smtp (cere re-autentificarea) | expeditor | proba | reia
 *   GET  /admin/continut                       Camere: titlurile și descrierile (RO și EN), ordinea, camerele ascunse
 *   POST /admin/continut/{camera}              salvarea unei camere
 *
 * Starea site-ului, copiile, legătura cu EasyCheck și e-mailurile sunt ale proprietarului; camerele le editează și
 * angajații (14).
 */
final class AdminSistem
{
    public const MESAJE = ['salvat' => 'admin.salvat', 'copie' => 'admin.sistem.copie_facuta', 'restaurata' => 'admin.sistem.copie_restaurata', 'stearsa' => 'admin.sistem.copie_stearsa',
        'cheie' => 'admin.conexiuni.cheie_salvata', 'cheie_legata' => 'admin.conexiuni.cheie_legata', 'cheie_nelegata' => 'admin.conexiuni.cheie_nelegata', 'revenit' => 'admin.conexiuni.revenit', 'anunturi' => 'admin.conexiuni.anunturi_ok', 'anunturi_neprimite' => 'admin.conexiuni.anunturi_neprimite',
        'sincronizat' => 'admin.conexiuni.sincronizat', 'proba' => 'admin.emailuri.proba_trimisa', 'reluate' => 'admin.emailuri.reluate',
        'smtp' => 'admin.emailuri.smtp_salvat', 'smtp_sters' => 'admin.emailuri.smtp_sters', 'expeditor' => 'admin.emailuri.expeditor_salvat'];
    public const ERORI = ['reauth' => 'admin.reauth.eroare', 'confirmare' => 'admin.sistem.eroare_confirmare', 'copia_lipsa' => 'admin.sistem.eroare_copie',
        'copia_noua' => 'admin.sistem.eroare_copie_noua', 'copia_forma' => 'admin.sistem.eroare_copie', 'copia_citire' => 'admin.sistem.eroare_copie',
        'cheie_forma' => 'admin.conexiuni.eroare_forma', 'cheie_refuzata' => 'admin.conexiuni.eroare_refuzata', 'retea' => 'admin.conexiuni.eroare_retea', 'rotire' => 'admin.conexiuni.eroare_rotire',
        'smtp' => 'admin.emailuri.eroare_smtp', 'dkim' => 'admin.emailuri.eroare_dkim', 'expeditor' => 'admin.emailuri.eroare_expeditor', 'camera' => 'admin.continut.eroare'];

    // ---- Starea site-ului ----

    public static function sistem(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'sistem', self::MESAJE, self::ERORI);
        $diag = self::diagnostic($app);
        $sarcini = [];
        foreach ($diag['sarcini'] as $x) {
            $nume = (string) $x['nume'];
            $sursa = (string) ($x['sursa'] ?? '');
            $sarcini[] = ['nume' => $t->are('admin.sarcina.' . $nume) ? $t->t('admin.sarcina.' . $nume) : $nume, 'cod' => $nume,
                'ultima' => $x['ultima_la'] !== null ? Format::moment((string) $x['ultima_la'], $l) : '',
                'sursa' => $t->are('cron.sursa.' . $sursa) ? $t->t('cron.sursa.' . $sursa) : $sursa] + self::rezultatSarcina($t, $x);
        }
        // Driverul celeilalte baze de date lipsește fără urmări: pe SQLite, „pdo_mysql: lipsește” ar speria degeaba.
        $extensii = $diag['extensii'];
        unset($extensii[$diag['baza'] === 'sqlite' ? 'pdo_mysql' : 'pdo_sqlite']);
        $d += ['stari' => self::stari($app, $t, $diag), 'diag' => $diag, 'extensii' => $extensii, 'sarcini' => $sarcini,
            'cron_cpanel' => is_string($diag['cron_ultima']) ? Format::moment($diag['cron_ultima'], $l) : null,
            'url' => ['pachet' => $app->url()->cale('/admin/sistem/pachet')]];
        return AdminPanou::pagina($app, $u, 'sistem', $d, $r);
    }

    /**
     * Rezultatul unei sarcini, pe înțelesul gazdei: „în regulă”, „a dat o eroare” sau „încă nu a rulat”.
     * Textul scris de sarcină (pentru cine ajută gazda cu site-ul) apare doar la o eroare; întreg, rămâne în
     * diagnosticul descărcabil.
     * @return array{rezultat:string, detaliu:string, eroare:bool}
     */
    public static function rezultatSarcina(Traducator $t, array $x): array
    {
        $brut = (string) ($x['rezultat'] ?? '');
        if (($x['ultima_la'] ?? null) === null) {
            return ['rezultat' => $t->t('admin.sistem.rezultat.asteapta'), 'detaliu' => '', 'eroare' => false];
        }
        $eroare = str_starts_with($brut, 'eroare') || (bool) preg_match('~refuzat|eșuat|esuat|modificat~u', $brut);
        return ['rezultat' => $t->t($eroare ? 'admin.sistem.rezultat.eroare' : 'admin.sistem.rezultat.ok'), 'detaliu' => $eroare ? $brut : '', 'eroare' => $eroare];
    }

    /**
     * Cele trei rânduri de sus ale paginii „Starea site-ului”: site-ul, sarcinile automate, e-mailurile. Fiecare
     * spune că merge sau ce e de făcut, cu linkul spre pagina unde se repară.
     * @return list<array{clasa:string, text:string, url:?string, link:?string}>
     */
    public static function stari(Aplicatie $app, Traducator $t, array $diag): array
    {
        $l = $t->limba();
        $rand = static fn (string $clasa, string $text, ?string $cale = null, ?string $link = null): array
            => ['clasa' => $clasa, 'text' => $text, 'url' => $cale !== null ? $app->url()->cale($cale) : null, 'link' => $link];
        $stari = [$diag['php_ok'] ? $rand('pa-ok', $t->t('admin.sistem.stare.site_ok', ['versiune' => (string) $diag['versiune']]))
            : $rand('pa-atentie', $t->t('admin.sistem.stare.php_vechi', ['php' => (string) $diag['php']]))];
        // Sarcinile automate: aceeași regulă ca alerta de pe Acasă (o oră fără nicio rulare).
        $ultima = $app->baza()->valoare('SELECT MAX(ultima_la) FROM {sarcini}');
        $veche = $app->ceas()->moment(-3600);
        if (!is_string($ultima)) {
            $stari[] = $rand('pa-atentie', $t->t('admin.sistem.stare.cron_asteapta'));
        } elseif ($ultima < $veche) {
            $stari[] = $rand('pa-atentie', $t->t('admin.sistem.stare.cron_oprit', ['cand' => Format::moment($ultima, $l)]));
        } elseif (!is_string($diag['cron_ultima']) || $diag['cron_ultima'] < $veche) {
            $stari[] = $rand('pa-de-facut', $t->t('admin.sistem.stare.cron_vizite', ['cand' => Format::moment($ultima, $l)]));
        } else {
            $stari[] = $rand('pa-ok', $t->t('admin.sistem.stare.cron_ok', ['cand' => Format::moment($ultima, $l)]));
        }
        // E-mailurile: de la a doua încercare nereușită a unui mesaj, rândul spune că au rămas pe loc.
        $em = AdminPanou::emailuriCuProbleme($app);
        if ($em['reincercate'] > 0) {
            $stari[] = $rand('pa-atentie', $t->t('admin.emailuri.problema.blocate', ['n' => (string) $em['reincercate']]), '/admin/emailuri', $t->t('admin.emailuri.problema.vedeti'));
        } elseif ($em['esuate'] > 0) {
            $stari[] = $rand('pa-atentie', $t->t('admin.emailuri.problema.esuate', ['n' => (string) $em['esuate']]), '/admin/emailuri', $t->t('admin.emailuri.problema.vedeti'));
        } elseif (($n = self::emailuriInReincercare($app)) > 0) {
            $stari[] = $rand('pa-de-facut', $t->t('admin.emailuri.problema.prima', ['n' => (string) $n]), '/admin/emailuri', $t->t('admin.emailuri.problema.vedeti'));
        } else {
            $stari[] = $rand('pa-ok', $t->t('admin.sistem.stare.emailuri_ok'));
        }
        return $stari;
    }

    /**
     * Mesajele din coadă cu cel puțin o încercare nereușită. De la a doua încercare nereușită apare alerta; până
     * atunci, paginile spun doar că site-ul încearcă din nou, ca „E-mailurile pleacă” să nu stea lângă o trimitere căzută.
     */
    public static function emailuriInReincercare(Aplicatie $app): int
    {
        return (int) $app->baza()->valoare("SELECT COUNT(*) FROM {emailuri} WHERE stare IN ('in_coada', 'in_lucru') AND incercari >= 1 AND eroare IS NOT NULL");
    }

    /**
     * Diagnosticul, fără secrete și fără date personale: îl arată panoul și îl duce fișierul descărcabil.
     * Sonda folderului privat vine cu instalatorul.
     */
    public static function diagnostic(Aplicatie $app): array
    {
        $ext = [];
        foreach (['sodium', 'pdo_mysql', 'pdo_sqlite', 'curl', 'openssl', 'mbstring', 'intl', 'zlib', 'json', 'fileinfo', 'gd'] as $e) {
            $ext[$e] = extension_loaded($e);
        }
        $b = $app->baza();
        $erori = [];
        foreach ([0, -86400] as $zi) {
            $f = $app->jurnal()->fisier($app->ceas()->acum() + $zi);
            if (!is_file($f)) {
                continue;
            }
            foreach (array_reverse(file($f, FILE_IGNORE_NEW_LINES) ?: []) as $linie) {
                if (str_contains($linie, ' eroare ') || str_contains($linie, ' avertisment ')) {
                    $erori[] = mb_substr(self::faraAdrese(Jurnal::mascheaza($linie)), 0, 300);
                }
                if (count($erori) >= 20) {
                    break 2;
                }
            }
        }
        $licenta = $app->ecLicenta()->stare();
        $em = AdminPanou::emailuriCuProbleme($app);
        return [
            'versiune' => Aplicatie::versiune(),
            'php' => PHP_VERSION,
            'php_ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'extensii' => $ext,
            'https' => $app->config()->https(),
            'baza' => $b->dialect()->nume(),
            'migrari' => (string) $b->valoare('SELECT MAX(versiune) FROM {migrari}'),
            'memorie' => (string) ini_get('memory_limit'),
            'timp' => (string) ini_get('max_execution_time'),
            'sarcini' => $b->toate('SELECT nume, ultima_la, sursa, rezultat, durata_ms FROM {sarcini} ORDER BY nume'),
            'cron_ultima' => $b->valoare("SELECT MAX(ultima_la) FROM {sarcini} WHERE sursa = 'cpanel'"),
            'buget' => $app->ecBuget()->stare(),
            'emailuri' => ['in_coada' => $app->emailuri()->inAsteptare(), 'esuate' => (int) $b->valoare("SELECT COUNT(*) FROM {emailuri} WHERE stare = 'esuat'"),
                'reincercate' => $em['reincercate'], 'ultima_eroare' => $em['eroare']],
            'cheie' => ['mod' => $licenta['mod'], 'scop' => $licenta['scop'], 'sursa' => $licenta['sursa'], 'valida' => $licenta['valida']],
            'stripe' => ['configurat' => $app->stripe()->configurat(), 'mod' => $app->stripe()->stare()['mod'] ?? null],
            'comenzi' => array_column($b->toate('SELECT stare, COUNT(*) AS n FROM {comenzi} GROUP BY stare ORDER BY stare'), 'n', 'stare'),
            'erori' => $erori,
        ];
    }

    /** Adresele de e-mail dintr-un mesaj de eroare, mascate (rămâne domeniul): erorile SMTP repetă des destinatarul. */
    public static function faraAdrese(string $text): string
    {
        return (string) preg_replace('~[A-Za-z0-9._%+\-]+@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})~', '***@$1', $text);
    }

    /** Diagnosticul descărcabil, pentru cine o ajută pe gazdă cu site-ul: JSON fără secrete și fără date personale. */
    public static function pachet(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        $r = new Raspuns(200, Jurnal::mascheaza((string) json_encode(self::diagnostic($app) + ['generat_la' => $app->ceas()->moment()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        $r->antet('Content-Type', 'application/json; charset=utf-8');
        $r->antet('Content-Disposition', 'attachment; filename="diagnostic-' . $app->azi() . '.json"');
        return $r;
    }

    // ---- Copiile de siguranță (pagina lor) ----

    public static function copiiPagina(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'copii', self::MESAJE, self::ERORI);
        $copii = [];
        $total = 0;
        foreach ($app->copii()->lista() as $x) {
            $total += $x['marime'];
            $copii[] = self::copie($t, $x) + ['url_restaurare' => $app->url()->cale('/admin/copii/restaurare', null, ['copie' => $x['nume']])];
        }
        $d += ['copii' => $copii, 'de_restaurat' => null,
            'marimi' => $t->t('admin.sistem.copii_marimi', ['copii' => Format::numar((int) ceil($total / 1048576), $l) . ' MB',
                'media' => Format::numar((int) ceil($app->copii()->marimeMedia() / 1048576), $l) . ' MB']),
            'url' => ['copii' => $app->url()->cale('/admin/sistem/copii/')]];
        return AdminPanou::pagina($app, $u, 'copii', $d, $r);
    }

    /** O copie, pentru pagină: data în fusul site-ului, felul și mărimea; numele fișierului rămâne un detaliu. */
    private static function copie(Traducator $t, array $x): array
    {
        $l = $t->limba();
        return ['nume' => $x['nume'], 'fel' => $t->t('admin.sistem.copie_fel.' . $x['fel']), 'marime' => Format::numar((int) ceil($x['marime'] / 1024), $l) . ' KB',
            'la' => Format::moment(gmdate('Y-m-d H:i:s', $x['la']), $l)];
    }

    /** GET /admin/copii/restaurare?copie=...: pasul de confirmare al restaurării. */
    public static function copiiRestaurare(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $r = self::paginaRestaurare($app, $c, $u, $jeton, (string) ($c->param('copie') ?? ''), false);
        return $r ?? AdminPanou::inapoi($app, '/admin/copii', ['eroare' => 'copia_lipsa']);
    }

    /** Pagina de confirmare a restaurării; null când copia lipsește. Cu $faraBifa, 422 și eroarea lângă bifă. */
    private static function paginaRestaurare(Aplicatie $app, Cerere $c, array $u, string $jeton, string $nume, bool $faraBifa): ?Raspuns
    {
        $copie = null;
        foreach ($app->copii()->lista() as $x) {
            if ($x['nume'] === $nume) {
                $copie = $x;
            }
        }
        if ($copie === null) {
            return null;
        }
        $t = $app->traducator((string) $u['limba_panou'], 'admin');
        $r = Raspuns::html('', $faraBifa ? 422 : 200);
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'copii', self::MESAJE, self::ERORI);
        if ($faraBifa) {
            $d['eroare'] = $t->t('admin.sistem.eroare_confirmare');
        }
        $d += ['copii' => [], 'de_restaurat' => self::copie($t, $copie), 'fara_bifa' => $faraBifa,
            'url' => ['copii' => $app->url()->cale('/admin/sistem/copii/'), 'lista' => $app->url()->cale('/admin/copii')]];
        return AdminPanou::pagina($app, $u, 'copii', $d, $r);
    }

    public static function copii(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $inapoi = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/copii', $q);
        $a = (string) $p['actiune'];
        if ($a === 'creeaza') {
            $app->auditDetalii(['copie' => $app->copii()->creeaza('copie')]);
            return $inapoi(['mesaj' => 'copie']);
        }
        $nume = (string) ($c->camp('copie') ?? '');
        // Restaurarea se întoarce, la orice refuz, pe pasul ei de confirmare (cu bifa și parola).
        $laRestaurare = static fn (string $eroare): Raspuns => Copii::numeValid($nume) ? AdminPanou::inapoi($app, '/admin/copii/restaurare', ['copie' => $nume, 'eroare' => $eroare]) : $inapoi(['eroare' => $eroare]);
        if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
            return $a === 'restaureaza' ? $laRestaurare('reauth') : $inapoi(['eroare' => 'reauth']);
        }
        $f = $app->copii()->cale($nume);
        if ($f === null) {
            return $inapoi(['eroare' => 'copia_lipsa']);
        }
        $app->auditDetalii(['copie' => $nume]);
        if ($a === 'descarca') {
            // În flux, pe bucăți de 1 MB: o copie mare nu trece prin memoria PHP.
            $r = new Raspuns(200, '');
            $r->fisier = $f;
            $r->antet('Content-Type', 'application/gzip');
            $r->antet('Content-Disposition', 'attachment; filename="' . $nume . '"');
            return $r;
        }
        if ($a === 'sterge') {
            $app->copii()->sterge($nume);
            return $inapoi(['mesaj' => 'stearsa']);
        }
        if ($a === 'restaureaza') {
            // Bifa „Înțeleg că rezervările și setările de după această copie se pierd.”, verificată și aici.
            if ($c->camp('confirm') !== '1') {
                $app->auditDetalii(['eroare' => 'confirmare']);
                return self::paginaRestaurare($app, $c, $u, $jeton, $nume, true) ?? $inapoi(['eroare' => 'copia_lipsa']);
            }
            // Comenzile anonimizate până acum (la termen sau la cererea oaspetelui) rămân anonimizate după restaurare.
            $anonimizate = array_map('intval', array_column($app->baza()->toate('SELECT id FROM {comenzi} WHERE anonimizat_la IS NOT NULL'), 'id'));
            // Cât se restaurează, vizitatorii văd pagina de întreținere (cel mult 5 minute).
            Intretinere::porneste($app->privat(), $app->ceas()->acum() + 300);
            try {
                // Reanonimizarea în aceeași tranzacție cu restaurarea: un proces oprit la jumătate nu lasă datele la vedere.
                $re = 0;
                $inainte = $app->copii()->restaureaza($nume, static function () use ($app, $anonimizate, &$re): void {
                    foreach ($anonimizate as $id) {
                        $re += (int) $app->gdpr()->anonimizeaza($id, true);
                    }
                });
                $termen = $app->gdpr()->anonimizeazaLaTermen() ?? ['comenzi' => 0];
            } catch (\RuntimeException $e) {
                $cod = isset(self::ERORI[$e->getMessage()]) ? $e->getMessage() : 'copia_forma';
                $app->auditDetalii(['eroare_restaurare' => $cod]);
                return $inapoi(['eroare' => $cod]);
            } finally {
                Intretinere::opreste($app->privat());
            }
            $app->audit()->scrie('copie.restaurata', $nume, ['inainte' => $inainte, 'reanonimizate' => $re, 'anonimizate_la_termen' => (int) $termen['comenzi']], (int) $u['id']);
            $app->auditDetalii(['inainte' => $inainte]);
            $app->ecOglinda()->schimbaContinutul();
            return $inapoi(['mesaj' => 'restaurata']);
        }
        return $inapoi([]);
    }

    // ---- Legătura cu EasyCheck ----

    public static function conexiuni(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('');
        // trecerea dintr-un mod în altul își are mesajele ei (AdminLocal::legatura).
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'conexiuni', self::MESAJE + array_intersect_key(AdminLocal::MESAJE, array_flip(['spre_ec', 'camere_mapate', 'retrimise'])),
            self::ERORI + array_intersect_key(AdminLocal::ERORI, array_flip(['mod', 'cheie', 'citire'])));
        $d += AdminLocal::dateLegatura($app, $t, $l);
        // mesajele legăturii numesc cheia (prefixul); fără prefix în adresă, mesajul obișnuit al cheii salvate.
        $mesaj = (string) ($c->param('mesaj') ?? '');
        if (in_array($mesaj, ['cheie_legata', 'cheie_nelegata'], true)) {
            $prefix = (string) ($c->param('prefix') ?? '');
            $d['mesaj'] = preg_match('~^[0-9A-Za-z]{8}$~', $prefix) ? $t->t('admin.conexiuni.' . $mesaj, ['prefix' => $prefix]) : $t->t(self::MESAJE['cheie']);
        }
        $cheie = $app->ecLicenta()->cheie();
        $stare = $app->ecLicenta()->stare();
        // Modul de probă e o informație, anunțurile care întârzie o avertizare, restul (cheia respinsă, contul suspendat) erori.
        $adresa = $app->url()->absolut('/easycheck-anunt');
        $alerte = [];
        $anunturiDeReparat = false;
        foreach ($stare['alerte'] as $k) {
            $anunturi = in_array($k, ['conexiuni.ec.anunturi_oprite', 'conexiuni.ec.anunturi_neprimite'], true);
            $anunturiDeReparat = $anunturiDeReparat || $anunturi;
            $alerte[] = ['text' => $t->t($k, ['adresa' => $adresa]), 'clasa' => $k === 'conexiuni.ec.mod_proba' ? 'pa-info' : ($anunturi ? 'pa-avertizare' : 'pa-eroare'),
                'tehnic' => $k === 'conexiuni.ec.anunturi_neprimite' ? $t->t('admin.conexiuni.anunturi_tehnic', ['adresa' => $adresa]) : null];
        }
        $resurse = [];
        $verificat = null;
        $cuProbleme = [];
        foreach ($app->baza()->toate('SELECT resursa, verificat_la, schimbat_la, invechit, ultima_eroare FROM {ec_resurse} ORDER BY resursa') as $x) {
            $cod = (string) $x['resursa'];
            $nume = $t->are('admin.conexiuni.resursa.' . $cod) ? $t->t('admin.conexiuni.resursa.' . $cod) : $cod;
            $eroare = (string) ($x['ultima_eroare'] ?? '');
            if ($eroare !== '') {
                $cuProbleme[] = $nume;
            }
            if (is_string($x['verificat_la']) && ($verificat === null || $x['verificat_la'] > $verificat)) {
                $verificat = $x['verificat_la'];
            }
            $resurse[] = ['nume' => $nume, 'verificat' => $x['verificat_la'] !== null ? Format::moment((string) $x['verificat_la'], $l) : '',
                'schimbat' => $x['schimbat_la'] !== null ? Format::moment((string) $x['schimbat_la'], $l) : '', 'eroare' => $eroare];
        }
        // Rândul de sus: totul e la zi, sau ce anume așteaptă.
        if ($cheie === null || $resurse === []) {
            $d['la_zi'] = null;
        } elseif ($cuProbleme !== []) {
            $d['la_zi'] = ['clasa' => 'pa-atentie', 'text' => $t->t('admin.conexiuni.cu_probleme', ['lista' => implode(', ', $cuProbleme)])];
        } elseif ($verificat === null) {
            $d['la_zi'] = ['clasa' => 'pa-de-facut', 'text' => $t->t('admin.conexiuni.prima_sincronizare')];
        } else {
            $d['la_zi'] = ['clasa' => 'pa-ok', 'text' => $t->t('admin.conexiuni.la_zi', ['cand' => Format::moment($verificat, $l)])];
        }
        $rot = $app->setari()->get('ec.rotire');
        $d['rotire'] = self::cheieAnterioara($app) !== null ? $t->t('admin.conexiuni.rotire', ['pana' => Format::moment(gmdate('Y-m-d H:i:s', (int) $rot['la'] + 86400), $l)]) : null;
        $d += ['cheie' => $cheie, 'stare' => $stare, 'alerte' => $alerte, 'resurse' => $resurse, 'anunturi_de_reparat' => $anunturiDeReparat,
            'ultimele4' => $app->secrete()->ultimele4Din('ec_cheie'),
            'url' => ['baza' => $app->url()->cale('/admin/conexiuni/'), 'metode' => $app->url()->cale('/admin/metode-plata')]];
        return AdminPanou::pagina($app, $u, 'conexiuni', $d, $r);
    }

    /** Cheia anterioară, cât ține suprapunerea rotirii (24 de ore); după, null (și secretul se șterge la curățenie). */
    public static function cheieAnterioara(Aplicatie $app): ?string
    {
        $rot = $app->setari()->get('ec.rotire');
        if (!is_array($rot) || !is_int($rot['la'] ?? null) || $app->ceas()->acum() - $rot['la'] > 86400) {
            return null;
        }
        return $app->secrete()->citeste('ec_cheie_anterioara');
    }

    public static function conexiuniActiune(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $inapoi = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/conexiuni', $q);
        $a = (string) $p['actiune'];
        // fără cheie EasyCheck, cheia se leagă doar prin trecerea pe EasyCheck (AdminLocal::legatura).
        if ($app->modLocal() && $a !== 'sincronizeaza') {
            return $inapoi(['eroare' => 'mod']);
        }
        try {
            if ($a === 'cheie') {
                if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
                    return $inapoi(['eroare' => 'reauth']);
                }
                $nou = trim((string) ($c->camp('cheie') ?? ''));
                if (!\Cazo\EasyCheck\Conectare::formaCheii($nou)) {
                    return $inapoi(['eroare' => 'cheie_forma']);
                }
                // Rotirea (M6b): cheia de acum rămâne 24 de ore ca cheie anterioară, ca gazda să poată reveni la ea, iar
                // anunțurile semnate cu secretul vechi se primesc tot 24 de ore (Anunturi::secreteAcceptate).
                $veche = $app->secrete()->citeste('ec_cheie');
                $rez = $app->ecConectare()->legaCheia($nou);
                if ($veche !== null && !hash_equals($veche, $nou)) {
                    $app->secrete()->pune('ec_cheie_anterioara', $veche);
                    $app->setari()->pune('ec.rotire', ['la' => $app->ceas()->acum()], (int) $u['id']);
                }
                $app->auditDetalii(['mod' => (string) ($rez['mod'] ?? ''), 'scop' => (string) ($rez['scop'] ?? ''), 'rotire' => $veche !== null, 'legatura' => (string) ($rez['legatura'] ?? '')]);
                // Anunțurile și sincronizarea după răspuns: pagina se întoarce imediat.
                $app->dupaRaspuns(static function () use ($app): void {
                    try {
                        $app->ecConectare()->inregistreazaAnunturile();
                    } catch (\Throwable) {
                    }
                    // declarația site-ului pe cheia nouă (după adresa anunțurilor, de care ține legătura spre panou).
                    $app->stareSite()->declara();
                    $app->ecConectare()->sincronizeazaTot($app->ecSincronizare(), 30.0);
                });
                $app->modPlata()->evalueaza();
                // cheia făcută cu „Înlocuiește” din cea de acum păstrează mutările rezervărilor existente; o cheie
                // oarecare se acceptă, iar gazda află cum o face legată.
                // mesajul numește cheia înlocuită sau cheia de înlocuit (prefixul ei).
                $mesaj = match ($rez['legatura'] ?? null) {
                    'legata' => 'cheie_legata',
                    'nelegata' => 'cheie_nelegata',
                    default => 'cheie',
                };
                return $inapoi(['mesaj' => $mesaj] + ($mesaj !== 'cheie' && is_string($rez['legatura_prefix'] ?? null) ? ['prefix' => $rez['legatura_prefix']] : []));
            }
            if ($a === 'revino') {
                // Înapoi la cheia anterioară, cât ține suprapunerea de 24 de ore (de exemplu când cheia nouă e greșită).
                if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
                    return $inapoi(['eroare' => 'reauth']);
                }
                $veche = self::cheieAnterioara($app);
                if ($veche === null) {
                    return $inapoi(['eroare' => 'rotire']);
                }
                $curenta = $app->secrete()->citeste('ec_cheie');
                $app->ecConectare()->legaCheia($veche);
                if ($curenta !== null) {
                    $app->secrete()->pune('ec_cheie_anterioara', $curenta);
                }
                // Fereastra de 24 de ore curge de la prima rotire: revenirea nu o prelungește.
                $app->setari()->pune('ec.rotire', ['la' => (int) $app->setari()->get('ec.rotire')['la'], 'revenire' => true], (int) $u['id']);
                $app->dupaRaspuns(static function () use ($app): void {
                    try {
                        $app->ecConectare()->inregistreazaAnunturile();
                    } catch (\Throwable) {
                    }
                    // declarația site-ului pe cheia nouă (după adresa anunțurilor, de care ține legătura spre panou).
                    $app->stareSite()->declara();
                    $app->ecConectare()->sincronizeazaTot($app->ecSincronizare(), 30.0);
                });
                $app->modPlata()->evalueaza();
                return $inapoi(['mesaj' => 'revenit']);
            }
            if ($a === 'anunturi') {
                $rez = $app->ecConectare()->inregistreazaAnunturile();
                return $inapoi(['mesaj' => $rez['primit'] ? 'anunturi' : 'anunturi_neprimite']);
            }
            if ($a === 'sincronizeaza') {
                $rez = $app->ecSincronizare()->ruleaza(array_keys(\Cazo\EasyCheck\Oglinda::RESURSE), 20.0);
                if ($app->ecPoze()->verificaFisiere(5.0) > 0) {
                    $app->planificator()->grabeste('poze:prelucreaza');
                }
                $app->auditDetalii(['erori' => implode(',', array_keys($rez['erori']))]);
                return $inapoi(['mesaj' => 'sincronizat']);
            }
        } catch (EroareApi $e) {
            $app->auditDetalii(['cod' => (string) ($e->cod ?? 'http_' . $e->raspuns->status)]);
            return $inapoi(['eroare' => 'cheie_refuzata']);
        } catch (EroareTransport | \UnexpectedValueException) {
            // Un corp de neînțeles (GET /eu fără JSON, PUT /webhook fără secret): ca o problemă de rețea, ca instalatorul.
            return $inapoi(['eroare' => 'retea']);
        }
        return $inapoi([]);
    }

    // ---- Trimiterea e-mailurilor ----

    public static function emailuri(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        return self::paginaEmailuri($app, $c, $u, $jeton);
    }

    /**
     * Pagina „Trimiterea e-mailurilor”. La o greșeală de formular se întoarce cu 422, cu valorile scrise de gazdă
     * ($valori: `smtp`, `expeditor`, `dkim_selector`) și cu erorile lângă câmpuri ($erori: numele câmpului => textul).
     */
    private static function paginaEmailuri(Aplicatie $app, Cerere $c, array $u, string $jeton, array $valori = [], array $erori = [], ?string $eroare = null, int $status = 200): Raspuns
    {
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('', $status);
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'emailuri', self::MESAJE, self::ERORI);
        if ($eroare !== null) {
            $d['eroare'] = $eroare;
            $d['mesaj'] = null;
        }
        $smtp = (array) ($valori['smtp'] ?? $app->setari()->get('email.smtp') ?? []);
        $exp = (array) ($valori['expeditor'] ?? $app->setari()->get('email.expeditor') ?? []);
        $salvat = (array) ($app->setari()->get('email.expeditor') ?? []);
        // Mesajele cu probleme: cele abandonate și cele încă în coadă cu cel puțin o încercare nereușită.
        $probleme = [];
        $site = $app->modelVedere('ro')->nume();
        foreach ($app->baza()->toate("SELECT e.id, e.sablon, e.stare, e.creat_la, e.incercari, e.urmatoarea_incercare, e.eroare, c.ref FROM {emailuri} e LEFT JOIN {comenzi} c ON c.id = e.comanda_id
            WHERE e.stare = 'esuat' OR (e.stare IN ('in_coada', 'in_lucru') AND e.incercari >= 1 AND e.eroare IS NOT NULL) ORDER BY e.id DESC LIMIT 50") as $x) {
            $abandonat = $x['stare'] === 'esuat';
            $probleme[] = ['id' => (int) $x['id'], 'mesaj' => self::numeMesaj($app, $t, (string) $x['sablon'], $site),
                'catre' => $t->t(in_array($x['sablon'], \Cazo\Email\Sabloane::GAZDA, true) ? 'admin.emailuri.catre.gazda' : 'admin.emailuri.catre.oaspete'),
                'la' => Format::moment((string) $x['creat_la'], $l), 'ref' => (string) ($x['ref'] ?? ''), 'abandonat' => $abandonat,
                'stare' => $abandonat ? $t->t('admin.emailuri.stare.abandonat', ['n' => (string) $x['incercari']])
                    : $t->t('admin.emailuri.stare.reincercare', ['n' => (string) ((int) $x['incercari'] + 1), 'din' => (string) (count(\Cazo\Email\Cutie::REINCERCARI) + 1),
                        'cand' => Format::moment((string) $x['urmatoarea_incercare'], $l)]),
                'eroare' => self::faraAdrese((string) ($x['eroare'] ?? ''))];
        }
        $em = AdminPanou::emailuriCuProbleme($app);
        $problema = null;
        if ($em['reincercate'] > 0 || $em['esuate'] > 0) {
            $problema = ['titlu' => $em['reincercate'] > 0 ? $t->t('admin.emailuri.problema.blocate', ['n' => (string) $em['reincercate']]) : $t->t('admin.emailuri.problema.esuate', ['n' => (string) $em['esuate']]),
                'eroare' => $em['eroare'], 'de_la' => $em['de_la'] !== null ? Format::moment($em['de_la'], $l) : null];
        }
        $d += ['dkim_selector' => (string) ($valori['dkim_selector'] ?? $app->setari()->get('email.dkim_selector') ?? ''), 'smtp' => $smtp, 'smtp_parola' => $app->secrete()->ultimele4Din('smtp_parola'), 'expeditor' => $exp,
            'implicit' => 'rezervari@' . \Cazo\Email\Sabloane::domeniu($app->config()->origine()), 'erori_camp' => $erori,
            'in_reincercare' => $problema === null ? self::emailuriInReincercare($app) : 0,
            'coada' => ['in_coada' => $app->emailuri()->inAsteptare(), 'trimise' => (int) $app->baza()->valoare("SELECT COUNT(*) FROM {emailuri} WHERE stare = 'trimis' AND trimis_la > ?",
                [$app->ceas()->moment(-7 * 86400)])], 'problema' => $problema, 'probleme' => $probleme, 'dns' => self::dns($app, (string) ($salvat['adresa'] ?? '')),
            'url' => ['baza' => $app->url()->cale('/admin/emailuri/'), 'sabloane' => $app->url()->cale('/admin/emailuri/sabloane')]];
        return AdminPanou::pagina($app, $u, 'emailuri', $d, $r);
    }

    /**
     * Numele pe înțeles al unui mesaj din coadă, în locul numelui de șablon: numele din „Mesajele către oaspeți”, altfel
     * subiectul e-mailului (fără codul rezervării, care are coloana lui).
     */
    public static function numeMesaj(Aplicatie $app, Traducator $t, string $sablon, string $site = ''): string
    {
        foreach (['admin.emailuri.mesaj.', 'admin.sabloane.sablon.'] as $prefix) {
            if ($t->are($prefix . $sablon)) {
                return $t->t($prefix . $sablon);
            }
        }
        $catalog = in_array($sablon, \Cazo\Email\Sabloane::GAZDA, true) ? $t : $app->traducator($t->limba());
        $cheie = 'email.' . $sablon . '.subiect';
        if (!$catalog->are($cheie)) {
            return $t->t('admin.emailuri.mesaj_altul');
        }
        $text = (string) preg_replace('~\s*\(\{[a-z_]+\}\)|\s*\{[a-z_]+\}~', '', $catalog->t($cheie, ['site' => $site]));
        return trim($text) !== '' ? trim($text) : $t->t('admin.emailuri.mesaj_altul');
    }

    /** Cititorul înregistrărilor TXT (testele îl înlocuiesc); null = dns_get_record. @var (\Closure(string): ?list<string>)|null */
    public static ?\Closure $dnsTxt = null;

    /**
     * SPF, DMARC și DKIM pe domeniul expeditorului, citite din DNS la deschiderea paginii. DKIM se caută pe
     * selectorul implicit `default._domainkey` și pe selectorul scris de gazdă în panou (`email.dkim_selector`);
     * fără DKIM găsit, panoul arată o avertizare, fără să blocheze nimic.
     * Fără răspuns DNS, starea rămâne „necunoscută”.
     * @return array{domeniu:string, spf:?bool, dmarc:?bool, dkim:?bool, selectori:list<string>}|null
     */
    private static function dns(Aplicatie $app, string $adresa): ?array
    {
        $dom = strtolower((string) substr((string) strrchr($adresa !== '' ? $adresa : 'x@' . \Cazo\Email\Sabloane::domeniu($app->config()->origine()), '@'), 1));
        if ($dom === '' || filter_var($dom, FILTER_VALIDATE_IP) || !str_contains($dom, '.') || in_array($dom, ['localhost', 'cazo.test'], true) || str_ends_with($dom, '.test')) {
            return null;
        }
        $txt = self::$dnsTxt ?? static function (string $nume): ?array {
            $r = @dns_get_record($nume, DNS_TXT);
            return is_array($r) ? array_map(static fn (array $x): string => (string) ($x['txt'] ?? ''), $r) : null;
        };
        $are = static function (?array $r, string $prefix): ?bool {
            return $r === null ? null : array_filter($r, static fn (string $x): bool => str_starts_with(ltrim($x), $prefix)) !== [];
        };
        $selectori = array_values(array_unique(array_filter(['default', (string) ($app->setari()->get('email.dkim_selector') ?? '')])));
        $dkim = null;
        foreach ($selectori as $sel) {
            $r = $txt($sel . '._domainkey.' . $dom);
            if ($r !== null) {
                $gasit = array_filter($r, static fn (string $x): bool => str_contains($x, 'v=DKIM1') || str_contains($x, 'p=')) !== [];
                $dkim = ($dkim ?? false) || $gasit;
            }
        }
        return ['domeniu' => $dom, 'spf' => $are($txt($dom), 'v=spf1'), 'dmarc' => $are($txt('_dmarc.' . $dom), 'v=DMARC1'), 'dkim' => $dkim, 'selectori' => $selectori];
    }

    public static function emailuriActiune(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $t = $app->traducator((string) $u['limba_panou'], 'admin');
        $inapoi = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/emailuri', $q);
        $a = (string) $p['actiune'];
        if ($a === 'smtp') {
            $gazda = trim((string) ($c->camp('gazda') ?? ''));
            $sec = (string) ($c->camp('securitate') ?? 'tls');
            $portScris = trim((string) ($c->camp('port') ?? ''));
            $port = (int) $portScris;
            $utilizator = trim(mb_substr((string) ($c->camp('utilizator') ?? ''), 0, 190));
            // Valorile scrise de gazdă, pentru pagina întoarsă la o greșeală (parola SMTP nu se întoarce în pagină).
            $scrise = ['smtp' => ['gazda' => $gazda, 'port' => $portScris, 'securitate' => $sec, 'utilizator' => $utilizator]];
            // Orice schimbare a SMTP-ului cere re-autentificarea: serverul, portul, criptarea și utilizatorul hotărăsc
            // unde pleacă parola salvată.
            if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
                $app->auditDetalii(['eroare' => 'reauth']);
                return self::paginaEmailuri($app, $c, $u, $jeton, $scrise, [], $t->t('admin.reauth.eroare'), 422);
            }
            $vechi = (array) ($app->setari()->get('email.smtp') ?? []);
            if ($gazda === '') {
                // SMTP gol: e-mailurile pleacă prin funcția mail() a găzduirii; parola salvată nu mai are server.
                $app->setari()->pune('email.smtp', null, (int) $u['id']);
                $app->secrete()->sterge('smtp_parola');
                return $inapoi(['mesaj' => 'smtp_sters']);
            }
            $erori = [];
            if (!preg_match('~^[A-Za-z0-9.\-]{1,190}$~', $gazda)) {
                $erori['gazda'] = $t->t('admin.emailuri.eroare_gazda');
            }
            if ($port < 1 || $port > 65535) {
                $erori['port'] = $t->t('admin.emailuri.eroare_port');
            }
            if (!in_array($sec, ['tls', 'ssl', 'niciuna'], true)) {
                $erori['securitate'] = $t->t('admin.emailuri.eroare_securitate');
            }
            if ($erori !== []) {
                $app->auditDetalii(['eroare' => 'smtp']);
                return self::paginaEmailuri($app, $c, $u, $jeton, $scrise, $erori, $t->t('admin.emailuri.eroare_campuri'), 422);
            }
            $parola = (string) ($c->camp('parola_smtp') ?? '');
            $gazdaNoua = strtolower($gazda) !== strtolower((string) ($vechi['gazda'] ?? ''));
            if ($parola !== '') {
                $app->secrete()->pune('smtp_parola', $parola);
            } elseif ($gazdaNoua) {
                // Alt server: parola veche nu pleacă spre el; gazda o scrie din nou.
                $app->secrete()->sterge('smtp_parola');
            }
            $app->setari()->pune('email.smtp', ['gazda' => $gazda, 'port' => $port, 'securitate' => $sec, 'utilizator' => $utilizator], (int) $u['id']);
            $app->auditDetalii(['gazda' => $gazda, 'parola_noua' => $parola !== '', 'parola_stearsa' => $parola === '' && $gazdaNoua]);
            return $inapoi(['mesaj' => 'smtp']);
        }
        if ($a === 'expeditor') {
            $sel = strtolower(trim((string) ($c->camp('dkim_selector') ?? '')));
            $adresa = mb_strtolower(trim((string) ($c->camp('adresa') ?? '')));
            $nume = trim(mb_substr((string) ($c->camp('nume') ?? ''), 0, 120));
            $erori = [];
            if ($sel !== '' && !preg_match('~^[a-z0-9][a-z0-9._\-]{0,62}$~', $sel)) {
                $erori['dkim_selector'] = $t->t('admin.emailuri.eroare_dkim');
            }
            if ($adresa !== '' && filter_var($adresa, FILTER_VALIDATE_EMAIL) === false) {
                $erori['adresa'] = $t->t('admin.emailuri.eroare_expeditor');
            }
            if ($erori !== []) {
                $app->auditDetalii(['eroare' => isset($erori['dkim_selector']) ? 'dkim' : 'expeditor']);
                return self::paginaEmailuri($app, $c, $u, $jeton, ['expeditor' => ['adresa' => $adresa, 'nume' => $nume], 'dkim_selector' => $sel], $erori, $t->t('admin.emailuri.eroare_campuri'), 422);
            }
            $app->setari()->pune('email.dkim_selector', $sel === '' ? null : $sel, (int) $u['id']);
            $app->setari()->pune('email.expeditor', $adresa === '' ? null : ['adresa' => $adresa, 'nume' => $nume], (int) $u['id']);
            return $inapoi(['mesaj' => 'expeditor']);
        }
        if ($a === 'proba') {
            $id = $app->emailuri()->pune(null, 'proba_email', (string) $u['limba_panou'], (string) $u['email'], []);
            $app->emailuri()->trimite(1, $id);
            $e = $app->baza()->rand('SELECT stare, eroare FROM {emailuri} WHERE id = ?', [$id]);
            if (($e['stare'] ?? null) === 'trimis') {
                return $inapoi(['mesaj' => 'proba']);
            }
            // Proba nereușită iese din coadă (gazda o repetă cu butonul), iar răspunsul serverului apare pe pagină.
            $app->baza()->executa("UPDATE {emailuri} SET stare = 'renuntat' WHERE id = ? AND stare <> 'trimis'", [$id]);
            $app->auditDetalii(['eroare' => 'smtp']);
            $raspuns = self::faraAdrese((string) ($e['eroare'] ?? ''));
            return self::paginaEmailuri($app, $c, $u, $jeton, [], [], $t->t('admin.emailuri.eroare_smtp') . ($raspuns !== '' ? ' ' . $t->t('admin.emailuri.eroare_raspuns', ['raspuns' => $raspuns]) : ''), 422);
        }
        if ($a === 'reia') {
            // Mesajele abandonate intră din nou în coadă, iar cele care așteaptă o nouă încercare pleacă acum.
            $acum = $app->ceas()->moment();
            $n = $app->baza()->executa("UPDATE {emailuri} SET stare = 'in_coada', incercari = 0, urmatoarea_incercare = ? WHERE stare = 'esuat' AND catre <> ''", [$acum])->rowCount();
            $n += $app->baza()->executa("UPDATE {emailuri} SET urmatoarea_incercare = ? WHERE stare = 'in_coada' AND incercari >= 1 AND eroare IS NOT NULL AND urmatoarea_incercare > ?", [$acum, $acum])->rowCount();
            $app->auditDetalii(['reluate' => $n]);
            $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(10));
            return $inapoi(['mesaj' => 'reluate']);
        }
        return $inapoi([]);
    }

    // ---- Camere ----

    public static function continut(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        return self::paginaCamere($app, $c, $u, $jeton);
    }

    /**
     * Camerele din EasyCheck, în ordinea de pe site, cu completările locale. Numele camerei e `titlu` din API-ul
     * EasyCheck. Ordinea fără completare locală e locul camerei în listă (1, 2, 3).
     * @return list<array{id:string, nume_ec:string, loc:array, ordine:int}>
     */
    private static function camere(Aplicatie $app, Traducator $t): array
    {
        $local = [];
        foreach ($app->baza()->toate('SELECT * FROM {camere_local}') as $x) {
            $local[$x['camera']] = $x;
        }
        $camere = [];
        foreach ((array) ($app->ecOglinda()->corp('camere')['camere'] ?? []) as $cam) {
            if (!is_array($cam) || !is_string($cam['id'] ?? null)) {
                continue;
            }
            $n = count($camere) + 1;
            $loc = $local[$cam['id']] ?? [];
            $nume = trim((string) ($cam['titlu'] ?? $cam['nume'] ?? ''));
            $camere[] = ['id' => $cam['id'], 'nume_ec' => $nume !== '' ? $nume : $t->t('admin.continut.camera_n', ['n' => (string) $n]), 'loc' => $loc,
                'ordine' => (int) ($loc['ordine'] ?? 0) >= 1 ? (int) $loc['ordine'] : $n, 'are_ordine' => (int) ($loc['ordine'] ?? 0) >= 1];
        }
        return $camere;
    }

    /** Pagina „Camere”. La o greșeală: 422, cu valorile scrise în cardul camerei și eroarea lângă câmp. */
    private static function paginaCamere(Aplicatie $app, Cerere $c, array $u, string $jeton, ?string $gresita = null, array $valori = [], array $erori = []): Raspuns
    {
        $t = $app->traducator((string) $u['limba_panou'], 'admin');
        $r = Raspuns::html('', $gresita !== null ? 422 : 200);
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'continut', self::MESAJE, self::ERORI);
        $camere = [];
        $salvata = (string) ($c->param('camera') ?? '');
        foreach (self::camere($app, $t) as $cam) {
            $loc = $cam['loc'];
            $titlu = json_decode((string) ($loc['titlu'] ?? ''), true) ?: [];
            $descriere = json_decode((string) ($loc['descriere'] ?? ''), true) ?: [];
            $x = ['id' => $cam['id'], 'loc' => $cam['ordine'], 'nume_ec' => $cam['nume_ec'], 'titlu_ro' => (string) ($titlu['ro'] ?? ''), 'titlu_en' => (string) ($titlu['en'] ?? ''),
                'descriere_ro' => (string) ($descriere['ro'] ?? ''), 'descriere_en' => (string) ($descriere['en'] ?? ''), 'ordine' => (string) $cam['ordine'],
                'ascunsa' => (int) ($loc['ascunsa'] ?? 0) === 1, 'de_tradus' => ($titlu['ro'] ?? '') !== '' && ($titlu['en'] ?? '') === '', 'erori' => []];
            if ($cam['id'] === $gresita) {
                $x = array_replace($x, $valori, ['erori' => $erori]);
            }
            // Textele proprii ale site-ului stau strânse sub „(opțional)” cât sunt goale; deschise când au ceva sau o greșeală.
            $x['texte_deschise'] = $x['erori'] !== [] || $x['titlu_ro'] !== '' || $x['titlu_en'] !== '' || $x['descriere_ro'] !== '' || $x['descriere_en'] !== '';
            if ($gresita === null && $c->param('mesaj') === 'camera' && $cam['id'] === $salvata) {
                $d['mesaj'] = $t->t('admin.continut.salvata', ['camera' => $x['titlu_ro'] !== '' ? $x['titlu_ro'] : $cam['nume_ec']]);
            }
            $camere[] = $x;
        }
        usort($camere, static fn (array $a, array $b): int => $a['loc'] <=> $b['loc']);
        if ($gresita !== null) {
            $d['eroare'] = $t->t('admin.continut.eroare_campuri');
            $d['mesaj'] = null;
        }
        // Cu cheia EasyCheck și o singură unitate (un apartament întreg), numele și descrierea de pe site sunt ale proprietății
        // din EasyCheck: pagina le arată, cu locul unde se schimbă; textele proprii ale site-ului rămân într-un bloc opțional.
        $dinEc = null;
        if (!$app->modLocal() && in_array('_unica', $app->varianteText(), true) && count($camere) === 1) {
            $prop = (array) ($app->ecOglinda()->corp('proprietate') ?? []);
            $desc = is_array($prop['descriere'] ?? null) ? $prop['descriere'] : [];
            $x = $camere[0];
            $dinEc = ['nume' => $x['nume_ec'], 'descriere_ro' => trim((string) ($desc['ro'] ?? '')), 'descriere_en' => trim((string) ($desc['en'] ?? '')),
                'link' => \Cazo\Sistem\Adresa::EASYCHECK_SITE_WEB,
                'deschis' => $gresita !== null || $x['titlu_ro'] !== '' || $x['titlu_en'] !== '' || $x['descriere_ro'] !== '' || $x['descriere_en'] !== ''];
        }
        $d += ['camere' => $camere, 'din_ec' => $dinEc, 'url' => ['baza' => $app->url()->cale('/admin/continut/')]];
        return AdminPanou::pagina($app, $u, 'continut', $d, $r);
    }

    public static function continutSalveaza(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $t = $app->traducator((string) $u['limba_panou'], 'admin');
        $id = (string) $p['camera'];
        $camere = self::camere($app, $t);
        $camera = null;
        foreach ($camere as $cam) {
            if ($cam['id'] === $id) {
                $camera = $cam;
            }
        }
        if ($camera === null) {
            return AdminPanou::inapoi($app, '/admin/continut', ['eroare' => 'camera']);
        }
        $text = static fn (string $k): string => trim(str_replace("\r", '', (string) ($c->camp($k) ?? '')));
        $valori = ['titlu_ro' => $text('titlu_ro'), 'titlu_en' => $text('titlu_en'), 'descriere_ro' => $text('descriere_ro'), 'descriere_en' => $text('descriere_en'),
            'ordine' => trim((string) ($c->camp('ordine') ?? '')), 'ascunsa' => $c->camp('ascunsa') === '1'];
        $erori = [];
        foreach (['titlu_ro' => 120, 'titlu_en' => 120, 'descriere_ro' => 2000, 'descriere_en' => 2000] as $k => $max) {
            if (mb_strlen($valori[$k]) > $max) {
                $erori[$k] = $t->t('admin.continut.eroare_lungime', ['max' => (string) $max]);
            }
        }
        // Locul gol păstrează locul de acum al camerei; altfel, un număr întreg de la 1 în sus.
        $ordine = $valori['ordine'] === '' ? $camera['ordine'] : (preg_match('~^\d{1,3}$~', $valori['ordine']) ? (int) $valori['ordine'] : null);
        if ($ordine === null || $ordine < 1) {
            $erori['ordine'] = $t->t('admin.continut.eroare_ordine');
        }
        if ($erori !== []) {
            $app->auditDetalii(['eroare' => 'campuri']);
            return self::paginaCamere($app, $c, $u, $jeton, $id, $valori, $erori);
        }
        $titlu = array_filter(['ro' => $valori['titlu_ro'], 'en' => $valori['titlu_en']], static fn (string $v): bool => $v !== '');
        $descriere = array_filter(['ro' => $valori['descriere_ro'], 'en' => $valori['descriere_en']], static fn (string $v): bool => $v !== '');
        // Camera se așază pe locul scris, iar celelalte se rearanjează în jurul ei: locurile rămân 1, 2, 3, cum le vede
        // gazda în pagină și cum apar camerele pe site.
        usort($camere, static fn (array $a, array $b): int => $a['ordine'] <=> $b['ordine']);
        $ids = array_values(array_filter(array_column($camere, 'id'), static fn (string $x): bool => $x !== $id));
        array_splice($ids, min($ordine, count($ids) + 1) - 1, 0, [$id]);
        $locuri = array_flip($ids);
        $b = $app->baza();
        $b->upsert('camere_local', ['camera' => $id, 'titlu' => $titlu !== [] ? json_encode($titlu, JSON_UNESCAPED_UNICODE) : null,
            'descriere' => $descriere !== [] ? json_encode($descriere, JSON_UNESCAPED_UNICODE) : null, 'ordine' => $locuri[$id] + 1,
            'ascunsa' => $valori['ascunsa'] ? 1 : 0], ['camera'], ['titlu', 'descriere', 'ordine', 'ascunsa']);
        foreach ($camere as $cam) {
            if ($cam['id'] !== $id && (!$cam['are_ordine'] || $cam['ordine'] !== $locuri[$cam['id']] + 1)) {
                $b->upsert('camere_local', ['camera' => $cam['id'], 'ordine' => $locuri[$cam['id']] + 1], ['camera'], ['ordine']);
            }
        }
        $app->ecOglinda()->schimbaContinutul();
        return AdminPanou::inapoi($app, '/admin/continut', ['mesaj' => 'camera', 'camera' => $id]);
    }
}
