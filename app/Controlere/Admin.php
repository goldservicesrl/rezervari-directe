<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\I18n\Format;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;
use Cazo\Securitate\Ip;
use Cazo\Securitate\SesiuneAdmin;
use Cazo\Teme\MediuNucleu;

defined('CAZO') or exit;

/**
 * Panoul: autentificarea, Acasă (tabloul de bord), „Metodele de plată” și „Protecția rezervărilor” (grupul
 * „Prețuri și plăți”), butoanele „Acceptați (probă)” și „Refuzați (probă)” la cheile de probă și
 * punctele POST ale plăților și ale verificării în doi pași.
 * Profilul de antete „admin”: no-store, COOP, Trusted Types, fără scripturi.
 */
final class Admin
{
    /** Acasă: răspunsul la cererile de probă. */
    public const MESAJE = ['acceptata' => 'admin.cereri.acceptata_proba', 'refuzata' => 'admin.cereri.refuzata_proba',
        // Transferul bancar: deciziile de probă ale plății.
        'plata_confirmata_proba' => 'admin.transfer.proba_confirmata', 'plata_refuzata_proba' => 'admin.transfer.proba_refuzata',
        // termenul transferului prelungit din panoul site-ului.
        'termen_prelungit' => 'admin.transfer.termen_prelungit',
        // nota de informare ascunsă de gazdă cu „Am înțeles”.
        'nota_ascunsa' => 'admin.alerte.nota_ascunsa'];
    /** Metodele de plată și contul Stripe (/admin/metode-plata). */
    public const MESAJE_METODE = ['metode_card' => 'admin.plati.metode_salvate.card', 'metode_cash' => 'admin.plati.metode_salvate.cash', 'metode_ambele' => 'admin.plati.metode_salvate.ambele',
        // combinațiile cu transferul bancar și contul lui (AdminTransfer).
        'metode_transfer' => 'admin.plati.metode_salvate.transfer', 'metode_card_transfer' => 'admin.plati.metode_salvate.card_transfer',
        'metode_cash_transfer' => 'admin.plati.metode_salvate.cash_transfer', 'metode_toate' => 'admin.plati.metode_salvate.toate', 'cont_transfer' => 'admin.transfer.salvat', 'cont_majuscule' => 'admin.transfer.majuscule_salvat',
        'drept' => 'admin.plati.drept_salvat', 'cheie_salvata' => 'admin.plati.cheie_salvata', 'cheie_permisiuni' => 'admin.plati.cheie_permisiuni', 'cheie_stearsa' => 'admin.plati.cheie_stearsa',
        'verificat' => 'admin.plati.verificat', 'anunt_salvat' => 'admin.plati.anunt_salvat', 'avans_mutare' => 'admin.plati.avans_mutare_salvat',
        'anulare_gazda' => 'admin.plati.anulare_gazda_salvat', 'rambursari_ec' => 'admin.plati.ec_salvat'];
    public const ERORI_METODE = ['reauth' => 'admin.reauth.eroare', 'ec_plafon' => 'admin.plati.eroare_ec_plafon', 'ec_drept' => 'admin.plati.ec_drept', 'parola' => 'admin.eroare.parola', 'secreta' => 'admin.plati.eroare_secreta', 'forma' => 'admin.plati.eroare_forma', 'invalida' => 'admin.plati.eroare_invalida',
        'mod_mixt' => 'admin.plati.eroare_mod_mixt', 'https' => 'admin.plati.eroare_https', 'secret_anunt' => 'admin.plati.eroare_secret_anunt',
        'metode_fara_stripe' => 'admin.plati.eroare_metode_fara_stripe', 'metode_lipsa' => 'admin.plati.eroare_metode_lipsa', 'metode_fara_cont' => 'admin.plati.eroare_metode_fara_cont'];
    /** Protecția rezervărilor (/admin/protectie). */
    public const MESAJE_PROTECTIE = ['cod_pornit' => 'admin.cod.salvat_pornit', 'cod_oprit' => 'admin.cod.salvat_oprit', 'cod_metode' => 'admin.cod.metode_salvate', 'repornit' => 'admin.intrerupator.repornit'];
    /** Închiderea unei plăți rezolvate în Stripe: mesajele ajung pe pagina rezervării (AdminRezervari le adaugă la ale ei). */
    public const MESAJE_INCHIDERE = ['inchisa' => 'admin.plati.inchisa', 'inchisa_rambursata' => 'admin.plati.inchisa_rambursata'];
    public const ERORI_INCHIDERE = ['reauth' => 'admin.reauth.eroare', 'inchidere' => 'admin.plati.eroare_inchidere', 'inchidere_confirmare' => 'admin.plati.eroare_inchidere_confirmare',
        'inchidere_sincronizare' => 'admin.plati.eroare_inchidere_sincronizare'];

    /** GET /admin/: formularul de intrare sau tabloul de bord: alertele, sosirile, cererile care așteaptă, pașii până la publicare. */
    public static function acasa(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return self::intrareFormular($app, $c, null);
        }
        [$u, $jeton] = $s;
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'acasa', self::MESAJE);
        // fără cheie EasyCheck, gazda răspunde la cereri și confirmă transferurile chiar de aici, cu
        // butoanele care, pe o cheie de probă, sunt „de probă”.
        $local = $app->modLocal();
        $proba = $app->ecLicenta()->stare()['proba'] || $local;
        $azi = $app->azi();
        $maine = (new \DateTimeImmutable($azi . ' 00:00:00', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        // Doar cererile care așteaptă răspuns; restul sunt în Rezervări.
        $cereri = [];
        // Tot aici, rezervările care așteaptă transferul bancar: și pe ele gazda le hotărăște în EasyCheck.
        foreach ($app->baza()->toate("SELECT * FROM {comenzi} WHERE (fel = 'cash' AND stare = 'cerere_in_asteptare') OR (fel = 'transfer' AND stare = 'transfer_in_asteptare') ORDER BY id LIMIT 100") as $cm) {
            $o = $app->comenzi()->oaspete($cm);
            if ($cm['fel'] === 'transfer') {
                $cereri[] = [
                    'ref' => $cm['ref'],
                    'sejur' => Format::zi((string) $cm['sosire'], $l) . ' - ' . Format::zi((string) $cm['plecare'], $l),
                    'curand' => $cm['sosire'] === $azi ? $t->t('admin.cereri.sosire_azi') : ($cm['sosire'] === $maine ? $t->t('admin.cereri.sosire_maine') : ''),
                    'oaspete' => (string) ($o['nume'] ?? ''),
                    'total' => Format::bani((int) $cm['total'], $l),
                    'termen' => $cm['transfer_termen'] !== null ? Format::moment((string) $cm['transfer_termen'], $l) : '',
                    'transfer' => $t->t('admin.transfer.stare.scurt', ['suma' => Format::bani((int) $cm['suma_acum'], $l)]),
                    'proba' => false,
                    'proba_transfer' => self::poateDecide($u) && AdminTransfer::poateProba($app, $cm),
                    // „Prelungiți termenul”, doar fără cheie EasyCheck și cât termenul curge.
                    'prelungire' => self::poateDecide($u) && AdminTransfer::poatePrelungi($app, $cm),
                ];
                continue;
            }
            $cereri[] = [
                'ref' => $cm['ref'],
                'sejur' => Format::zi((string) $cm['sosire'], $l) . ' - ' . Format::zi((string) $cm['plecare'], $l),
                'curand' => $cm['sosire'] === $azi ? $t->t('admin.cereri.sosire_azi') : ($cm['sosire'] === $maine ? $t->t('admin.cereri.sosire_maine') : ''),
                'oaspete' => (string) ($o['nume'] ?? ''),
                'total' => Format::bani((int) $cm['total'], $l),
                'termen' => $cm['ec_expira_la'] !== null ? Format::moment((string) $cm['ec_expira_la'], $l) : '',
                'proba' => $proba && self::poateDecide($u) && ((int) $cm['proba'] === 1 || $local),
            ];
        }
        $e = $c->param('eroare');
        if ($e === 'rol.-') {
            $d['eroare'] = $t->t('admin.cereri.proba_proprietar');
        } elseif ($e === 'termen_trecut.-') {
            $d['eroare'] = $t->t('admin.transfer.termen_trecut');
        } elseif (is_string($e) && preg_match('~^([a-z_0-9]{1,40})\.(req_[0-9A-Za-z]{1,64}|-)$~', $e, $m)) {
            $d['eroare'] = $t->t('admin.cereri.eroare_proba', ['cod' => $m[1], 'request_id' => $m[2]]);
        }
        $acasa = AdminPanou::acasa($app, $t, $u);
        $proprietar = $u['rol'] === 'proprietar';
        // Publicarea are blocul ei pe Acasă: alerta „nepublicat” ar spune același lucru de două ori.
        $usoare = array_values(array_filter($acasa['alerte'], static fn (array $a): bool => !$a['grav'] && !($proprietar && $a['cheie'] === 'nepublicat')));
        // recomandarea verificării în doi pași rămâne la vedere în panou până e pornită, pentru proprietarul cu
        // plăți reale și pentru angajatul cu dreptul de rambursare; pornirea e doar pe Contul meu.
        if ((int) ($u['are_2fa'] ?? 0) !== 1 && ($proprietar ? $app->stripe()->mod() === 'live' : SesiuneAdmin::poateRambursa($u))) {
            $usoare[] = ['cheie' => 'doi_pasi', 'text' => $t->t($proprietar ? 'admin.2fa.recomandare_proprietar' : 'admin.2fa.recomandare_rambursari_acasa'),
                'url' => $app->url()->cale('/admin/cont') . '#doi-pasi', 'refs' => [], 'grav' => false];
        }
        // Cât site-ul e în pregătire, alertele care doar repetă un pas până la publicare (datele firmei cerute la
        // publicare, termenele de păstrare) stau o singură dată pe pagină, în blocul publicării. Cele care opresc
        // rezervările (metodele de plată, datele cerute înaintea contractului) rămân și la „De rezolvat”, cu urmarea lor.
        // Bulina de lângă „Acasă” le numără la fel (AdminPanou::inPublicare).
        // pașii până la publicare sunt blocul „Publicarea site-ului” de pe Acasă (Publicare::paginaSite), cu lista a
        // ce lipsește; alertele pe care le spune el lipsesc de la „De rezolvat”.
        $inPasi = AdminPanou::inPublicare($app, $u);
        $d += [
            'cereri' => $cereri,
            'proba' => $proba,
            'local' => $local,
            'proba_rol' => self::poateDecide($u),
            'prelungiri' => AdminTransfer::variantePrelungire($t),
            // pe cheia de probă, cu rezervări care așteaptă transferul în listă, textul de deasupra o spune și despre ele.
            'proba_transferuri' => $proba && array_filter($cereri, static fn (array $x): bool => isset($x['transfer'])) !== [],
            'sosiri' => $acasa['sosiri'],
            'alerte_grave' => array_values(array_filter($acasa['alerte'], static fn (array $a): bool => $a['grav'] && !in_array($a['cheie'], $inPasi, true))),
            'alerte_usoare' => $usoare,
            // cât starea site-ului ajunge la EasyCheck, gazda află că problemele importante se văd și acolo.
            'in_easycheck' => $app->stareSite()->inPanou(),
            // versiunea nouă anunțată de cazo.ro, cu legătura de descărcare și pașii de instalare.
            'versiune_noua' => Actualizari::noua($app, (string) $u['limba_panou'], true),
            'url' => ['proba' => $app->url()->cale('/admin/cereri/'), 'proba_transfer' => $app->url()->cale('/admin/transfer/'), 'alerte' => $app->url()->cale('/admin/') . '#alerte', 'toate' => $app->url()->cale('/admin/rezervari'),
                'publicare' => $app->url()->cale('/admin/') . '#publicare', 'ascunde' => $app->url()->cale('/admin/alerte/ascunde')],
            'proprietar' => $proprietar,
            // în modurile E și P, Acasă e mai mică: rezervările, banii și mesajele se hotărăsc în
            // EasyCheck, cu „Deschideți EasyCheck”; sosirile pleacă, iar cererile rămân doar în P, pentru butoanele de probă.
            'ec_acasa' => $app->modEc()->deciziiInEasyCheck() ? ['url' => $app->modEc()->urlAcasa(), 'proba' => $app->modEc()->mod() === \Cazo\EasyCheck\ModEc::PROBA] : null,
        ];
        return AdminPanou::pagina($app, $u, 'acasa', $d, $r);
    }

    public static function intrare(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $rez = $app->sesiuneAdmin()->intra((string) ($c->camp('email') ?? ''), (string) ($c->camp('parola') ?? ''), Ip::trunchiaza($c->ip), (string) ($c->antet('user-agent') ?? ''),
            $c->camp('cod'));
        if ($rez['jeton'] === null) {
            return self::intrareFormular($app, $c, $rez['limita'] ? 'admin.intrare.limita' : 'admin.intrare.gresit', $rez['limita'] ? 429 : 403);
        }
        // Intrarea cu un cod de rezervă: panoul spune câte coduri au rămas.
        $cuRezerva = $app->sesiuneAdmin()->intrareCuCodRezerva;
        $r = Raspuns::redirectare($app->url()->cale($cuRezerva ? '/admin/cont' : '/admin/', null, $cuRezerva ? ['mesaj' => 'cod_rezerva'] : []));
        $r->cookie(SesiuneAdmin::cookie($rez['jeton']));
        // Punctul 22: pagina de intrare ține minte ultima limbă a panoului.
        $intrat = $app->sesiuneAdmin()->utilizator($rez['jeton']);
        if ($intrat !== null) {
            $r->cookie(AdminPanou::cookieLimba((string) $intrat['limba_panou']));
        }
        if ($cuRezerva) {
            $app->auditDetalii(['cod_rezerva' => true]);
        }
        return $r;
    }

    public static function iesire(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $app->sesiuneAdmin()->iese($c->cookie(SesiuneAdmin::COOKIE));
        $r = Raspuns::redirectare($app->url()->cale('/admin/'));
        $r->cookie(SesiuneAdmin::cookieSters());
        return $r;
    }

    /** POST /admin/cereri/{ref}/proba-accepta | proba-refuza: doar la cheile de probă, pe o cerere în așteptare. */
    public static function proba(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $u = $app->sesiuneAdmin()->utilizator($c->cookie(SesiuneAdmin::COOKIE));
        if ($u === null) {
            return Raspuns::redirectare($app->url()->cale('/admin/'));
        }
        // Răspunsul la o cerere (chiar de probă) e o decizie asupra rezervării: doar proprietarul.
        if (!self::poateDecide($u)) {
            return Raspuns::redirectare($app->url()->cale('/admin/', null, ['eroare' => 'rol.-']));
        }
        $cm = $app->comenzi()->dupaRef((string) $p['ref']);
        if ($cm === null || !($app->ecLicenta()->stare()['proba'] || $app->modLocal()) || $cm['stare'] !== 'cerere_in_asteptare' || $cm['ec_cerere'] === null) {
            return Raspuns::redirectare($app->url()->cale('/admin/', null, ['eroare' => 'stare_invalida.-']));
        }
        try {
            $app->ecClient()->scrie('POST', '/cereri/' . $cm['ec_cerere'] . '/' . $p['decizie'], null);
        } catch (EroareApi $e) {
            return Raspuns::redirectare($app->url()->cale('/admin/', null, ['eroare' => ($e->cod ?? 'http_' . $e->raspuns->status) . '.' . ($e->requestId ?? '-')]));
        } catch (EroareTransport $e) {
            return Raspuns::redirectare($app->url()->cale('/admin/', null, ['eroare' => 'retea.-']));
        }
        // Răspunsul gazdei se citește pe loc (cheia demo are anunțurile oprite); anunțul `cerere.*` vine oricum, după.
        $app->cerereCash()->urmareste(false, (int) $cm['id'], 'admin:' . (int) $u['id']);
        $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
        return Raspuns::redirectare($app->url()->cale('/admin/', null, ['mesaj' => $p['decizie'] === 'proba-accepta' ? 'acceptata' : 'refuzata']));
    }

    /**
     * Rolurile panoului (14): `proprietar` decide; `personal` vede cererile, fără butoanele de probă. Modelul din
     * specificație are doar aceste două roluri (administratorul e proprietarul).
     */
    public static function poateDecide(array $u): bool
    {
        return ($u['rol'] ?? null) === 'proprietar';
    }

    /** POST /admin/rezervare: confirmarea adresei oaspetelui pornită sau oprită, rezervările repornite. */
    public static function setari(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $u = $app->sesiuneAdmin()->utilizator($c->cookie(SesiuneAdmin::COOKIE));
        if ($u === null || $u['rol'] !== 'proprietar') {
            return Raspuns::redirectare($app->url()->cale('/admin/'));
        }
        $a = $c->camp('actiune');
        $mesaj = null;
        if ($a === 'cod_porneste' || $a === 'cod_opreste') {
            $app->intrerupator()->comutaCodul($a === 'cod_porneste');
            $mesaj = $a === 'cod_porneste' ? 'cod_pornit' : 'cod_oprit';
        } elseif ($a === 'cod_metode') {
            // la ce metode de plată se cere codul (bifele gazdei).
            $alese = [];
            foreach (\Cazo\Securitate\Intrerupator::METODE_COD as $m) {
                if ($c->camp('metoda_' . $m) === '1') {
                    $alese[] = $m;
                }
            }
            $app->intrerupator()->salveazaMetodeCod($alese);
            $mesaj = 'cod_metode';
        } elseif ($a === 'reporneste') {
            $app->intrerupator()->seteaza(false);
            $mesaj = 'repornit';
        }
        return Raspuns::redirectare($app->url()->cale('/admin/protectie', null, $mesaj !== null ? ['mesaj' => $mesaj] : []));
    }

    /** GET /admin/protectie: confirmarea adresei de e-mail a oaspetelui și protecția automată, doar proprietarul. */
    public static function protectie(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $t = $app->traducator((string) $u['limba_panou'], 'admin');
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'protectie', self::MESAJE_PROTECTIE);
        $d += ['cod' => $app->intrerupator()->cod(), 'intrerupator' => $app->intrerupator()->stare(),
            'intrerupator_text' => $app->intrerupator()->pornit() ? $t->cuContact('admin.intrerupator.pornit', Rezerva::contactText($app)) : $t->t('admin.intrerupator.oprit'),
            'url' => ['actiune' => $app->url()->cale('/admin/rezervare')]];
        return AdminPanou::pagina($app, $u, 'protectie', $d, $r);
    }

    /** GET /admin/metode-plata: metodele de plată, contul Stripe și drepturile de rambursare, doar proprietarul. */
    public static function metode(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $t = $app->traducator((string) $u['limba_panou'], 'admin');
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'metode', self::MESAJE_METODE, self::ERORI_METODE);
        if ($c->param('eroare') === 'cont_deschise') {
            $refs = array_filter(explode(',', (string) ($c->param('refs') ?? '')), static fn (string $x): bool => (bool) preg_match('~^PG-[0-9A-Z]{6}$~', $x));
            $d['eroare'] = $t->t('admin.plati.eroare_cont_deschise', ['comenzi' => implode(', ', $refs)]);
        }
        $d['plati'] = self::datePlati($app, $t, $u);
        // Transferul bancar: contul în care plătesc oaspeții, sub bifa lui, cu erorile lângă câmpuri după o
        // trimitere greșită; formularul întors păstrează bifele trimise, ca blocul contului să rămână la vedere.
        if (AdminTransfer::trimis($c)) {
            foreach ($d['plati']['metode_lista'] as $k => $m) {
                $d['plati']['metode_lista'][$k]['bifat'] = $m['disponibil'] && $c->camp('metoda_' . $m['cheie']) === '1';
            }
        }
        $d['transfer_setari'] = AdminTransfer::date($app, $c, $t, 'pa-tr', AdminTransfer::trimis($c) ? $c->camp('metoda_transfer') === '1' : null);
        return AdminPanou::pagina($app, $u, 'metode-plata', $d, $r);
    }

    /**
     * Secțiunea „Rezervare și plăți”: metodele de plată (alegerea gazdei, fără valoare impusă),
     * cheia Stripe cu permisiunile, punctul de anunț (automat sau manual), starea plății online, drepturile de
     * rambursare ale angajaților și 2FA (recomandare, alegerea fiecăruia).
     */
    private static function datePlati(Aplicatie $app, \Cazo\I18n\Traducator $t, array $u): array
    {
        $stripe = $app->stripe()->stare();
        $mod = $app->modPlata();
        $online = (array) ($app->setari()->get('plati.online') ?? []);
        $motive = [];
        foreach ((array) ($online['motive'] ?? []) as $m) {
            $motive[] = $t->t('plata.motiv.' . $m);
        }
        $permisiuni = [];
        // Câte un rând pe resursa din Stripe (Write cuprinde Read): verde doar când toate permisiunile ei trec.
        $verificate = (array) $stripe['permisiuni'];
        foreach (\Cazo\Plati\VerificareCheie::SETARI as $rand => $acopera) {
            $permisiuni[] = ['nume' => $t->t('admin.plati.perm.' . $rand),
                'ok' => $verificate !== [] && array_reduce($acopera, static fn (bool $ok, string $p): bool => $ok && ($verificate[$p] ?? null) === 'ok', true)];
        }
        $personal = [];
        // în modurile E și P, „Poate face rambursări” se ascunde: rambursările le
        // hotărăsc în EasyCheck cei cu permisiunea „Rambursări pe card”; comutatorul și plafonul apar doar aici.
        $inEc = $app->modEc()->deciziiInEasyCheck();
        if ($u['rol'] === 'proprietar' && !$inEc) {
            foreach ($app->baza()->toate("SELECT id, email, poate_rambursa FROM {utilizatori} WHERE rol = 'personal' AND activ = 1 ORDER BY id") as $x) {
                $personal[] = ['id' => (int) $x['id'], 'email' => (string) $x['email'], 'poate' => (int) $x['poate_rambursa'] === 1];
            }
        }
        $doiF = (int) ($u['are_2fa'] ?? 0) === 1;
        // Metodele de plată, ca listă: fiecare cu bifa ei, cu „se poate bifa” și cu explicația când e oprită.
        // O metodă nouă se adaugă aici și în ModPlata, fără alt loc care să presupună exact două.
        $bife = \Cazo\Rezervare\ModPlata::bife($mod->metode());
        $lista = [];
        foreach ($bife as $cheie => $bifat) {
            // Cardul cere contul Stripe legat. Transferul bancar se bifează oricând: contul lui (titularul și IBAN-ul)
            // se completează sub bifă și se salvează odată cu ea.
            $disponibil = $cheie !== 'card' || $stripe['configurata'];
            $lista[] = ['cheie' => $cheie, 'bifat' => $bifat && $disponibil, 'disponibil' => $disponibil, 'ajutor' => $disponibil ? null : $t->t('admin.plati.metoda.' . $cheie . '_oprita')];
        }
        $metodeStripe = (array) ($app->setari()->get('stripe.metode') ?? []);
        return [
            'metode' => $mod->metode(),
            'bife' => $bife,
            'metode_lista' => $lista,
            'metode_alege' => $mod->metode() === null,
            'stripe' => $stripe,
            'permisiuni' => $permisiuni,
            'oprita' => ($online['oprita'] ?? false) === true,
            'motive' => $motive,
            'fara_politica' => $mod->planuriFaraPolitica(),
            // cu cardul sau transferul bifat, tarifele cu avans 0% numite, cu ce are gazda de schimbat în EasyCheck.
            'nota_avans_zero' => $mod->notaFaraAvans($t),
            // tariful nerambursabil se oferă doar cu o metodă plătită la rezervare; gazda află când lipsește de pe site.
            'nota_nerambursabil' => $mod->notaNerambursabil($t),
            // tarifele cu avans, la care plata la sosire lipsește (sau, doar cu plata la sosire, se rezervă fără avans).
            'nota_cu_avans' => $mod->notaCuAvans($t),
            'evenimente' => implode(', ', \Cazo\Plati\ContStripe::EVENIMENTE),
            'personal' => $personal,
            'proprietar' => $u['rol'] === 'proprietar',
            'metode_stripe' => implode(', ', array_filter($metodeStripe, 'is_string')),
            'doi_factori' => $doiF,
            // recomandarea 2FA pentru proprietar la plățile live și pentru angajatul cu drept de rambursare.
            'recomanda_2fa' => !$doiF && ($u['rol'] === 'proprietar' ? $stripe['mod'] === 'live' : \Cazo\Securitate\SesiuneAdmin::poateRambursa($u)),
            'url' => $app->url()->cale('/admin/plati'),
            'url_cont' => $app->url()->cale('/admin/cont'),
            // reținerea după o mutare pe alte date (avansul recalculat pe noul total sau cel plătit la rezervare).
            'avans_mutare' => \Cazo\Rezervare\Anulare::mutareSite($app),
            // ce primește oaspetele când gazda anulează rezervarea.
            'anulare_gazda' => \Cazo\Rezervare\Anulare::gazdaSite($app),
            'drept_ec' => $inEc && $u['rol'] === 'proprietar',
            // „Primesc rambursări comandate din EasyCheck” și plafonul zilnic (Î3), doar în E și P, doar pentru proprietar.
            'rambursari_ec' => $inEc && $u['rol'] === 'proprietar' ? self::dateRambursariEc($app, $t) : null,
            // la Prețuri și plăți, legătura spre „Jurnalul plăților cu cardul” (vederea Rezervărilor, de citit).
            'jurnal_card' => $inEc && SesiuneAdmin::poateRambursa($u) ? $app->url()->cale('/admin/rezervari', null, ['vedere' => 'plati']) : null,
        ];
    }

    /** comutatorul și plafonul zilnic al rambursărilor comandate din EasyCheck, pentru formular. */
    private static function dateRambursariEc(Aplicatie $app, \Cazo\I18n\Traducator $t): array
    {
        $cmd = $app->ecComenzi();
        $pl = $cmd->plafon();
        $l = $t->limba();
        return ['permise' => $cmd->permise(), 'cu_plafon' => $pl !== null,
            'numar' => (string) ($pl['numar'] ?? \Cazo\EasyCheck\Comenzi::PLAFON_NUMAR),
            'suma' => (string) intdiv($pl['suma'] ?? \Cazo\EasyCheck\Comenzi::PLAFON_SUMA, 100),
            'text' => $pl !== null ? $t->t('admin.plati.ec_plafon', ['n' => (string) $pl['numar'], 'suma' => \Cazo\I18n\Format::bani($pl['suma'], $l)]) : $t->t('admin.plati.ec_plafon_oprit')];
    }

    /** POST /admin/plati: doar proprietarul; schimbarea cheii și a secretului cer reconfirmarea parolei. */
    public static function plati(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return self::platiPost($app, $c);
    }

    private static function platiPost(Aplicatie $app, Cerere $c): Raspuns
    {
        $u = $app->sesiuneAdmin()->utilizator($c->cookie(SesiuneAdmin::COOKIE));
        if ($u === null || $u['rol'] !== 'proprietar') {
            return Raspuns::redirectare($app->url()->cale('/admin/'));
        }
        $inapoi = static fn (array $q): Raspuns => Raspuns::redirectare($app->url()->cale('/admin/metode-plata', null, $q));
        $a = (string) ($c->camp('actiune') ?? '');
        // forma cu majuscule a titularului și a băncii, propusă după salvarea contului și aleasă de gazdă.
        if ($a === 'majuscule') {
            $app->contTransfer()->aplicaMajuscule((int) $u['id']);
            $app->auditDetalii(['cont' => 'majuscule']);
            return Raspuns::redirectare($app->url()->cale('/admin/metode-plata', null, ['mesaj' => 'cont_majuscule']) . '#transfer');
        }
        // formularul metodelor cuprinde și contul transferului bancar; bifa și contul se salvează împreună.
        if ($a === 'metode' && AdminTransfer::trimis($c)) {
            return AdminTransfer::salveazaCuMetode($app, $c);
        }
        if ($a === 'metode') {
            // trei bife independente, cel puțin una; cu mai multe, oaspetele alege la rezervare.
            $m = \Cazo\Rezervare\ModPlata::dinBife($c->camp('metoda_card') === '1', $c->camp('metoda_cash') === '1', $c->camp('metoda_transfer') === '1');
            if ($m === null) {
                return $inapoi(['eroare' => 'metode_lipsa']);
            }
            if ($c->camp('metoda_card') === '1' && !$app->stripe()->configurat()) {
                return $inapoi(['eroare' => 'metode_fara_stripe']);
            }
            // transferul bancar cere contul complet (titularul și IBAN-ul), salvat dinainte sau trimis în aceeași salvare.
            if ($c->camp('metoda_transfer') === '1' && !$app->contTransfer()->complet()) {
                return $inapoi(['eroare' => 'metode_fara_cont']);
            }
            $app->modPlata()->alegeMetode($m);
            return $inapoi(['mesaj' => 'metode_' . $m]);
        }
        // după o mutare pe alte date, ce avans se păstrează la anularea după termen (pentru comenzile de acum înainte,
        // în contractul lor; comenzile de dinainte urmează alegerea din ziua anulării).
        if ($a === 'avans_mutare') {
            $v = (string) ($c->camp('avans_mutare') ?? '');
            if (!in_array($v, [\Cazo\Rezervare\Anulare::MUTARE_RECALCULAT, \Cazo\Rezervare\Anulare::MUTARE_INITIAL], true)) {
                // O valoare din afara formularului: nimic salvat.
                return $inapoi([]);
            }
            $app->setari()->pune(\Cazo\Rezervare\Anulare::SETARE_MUTARE, $v);
            $app->auditDetalii(['avans_mutare' => $v]);
            return Raspuns::redirectare($app->url()->cale('/admin/metode-plata', null, ['mesaj' => 'avans_mutare']) . '#avans-mutare');
        }
        // ce primește oaspetele când gazda anulează: tot ce a plătit; în plus, o sumă egală cu avansul; sau
        // despăgubirea hotărâtă de la caz la caz (pentru comenzile de acum înainte, în informarea precontractuală și în
        // contractul lor; cele de dinainte rămân cum au fost). Fără alegere, site-ul nu primește rezervări.
        if ($a === 'anulare_gazda') {
            $v = (string) ($c->camp('anulare_gazda') ?? '');
            if (!in_array($v, \Cazo\Rezervare\Anulare::GAZDA, true)) {
                return $inapoi([]);
            }
            $app->setari()->pune(\Cazo\Rezervare\Anulare::SETARE_GAZDA, $v);
            $app->auditDetalii(['anulare_gazda' => $v]);
            return Raspuns::redirectare($app->url()->cale('/admin/metode-plata', null, ['mesaj' => 'anulare_gazda']) . '#anulare-gazda');
        }
        // comutatorul și plafonul rambursărilor comandate din EasyCheck: doar proprietarul (verificat mai sus), cu parola
        // confirmată în ultimele 10 minute, apoi `PUT /site` pe loc (Comenzi::salveaza). Doar în modurile E și P.
        if ($a === 'rambursari_ec') {
            if (!$app->modEc()->deciziiInEasyCheck()) {
                return $inapoi([]);
            }
            $jeton = (string) $c->cookie(SesiuneAdmin::COOKIE);
            if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
                return Raspuns::redirectare($app->url()->cale('/admin/metode-plata', null, ['eroare' => 'reauth']) . '#rambursari-ec');
            }
            $plafon = null;
            if ($c->camp('plafon') !== 'fara') {
                $n = filter_var($c->camp('plafon_numar'), FILTER_VALIDATE_INT);
                $suma = AdminRezervari::bani((string) ($c->camp('plafon_suma') ?? ''));
                if (!is_int($n) || $n < 1 || $n > \Cazo\EasyCheck\Comenzi::PLAFON_NUMAR_MAX || $suma === null || $suma < \Cazo\EasyCheck\Comenzi::PLAFON_SUMA_MIN
                    || $suma > \Cazo\EasyCheck\Comenzi::PLAFON_SUMA_MAX) {
                    return Raspuns::redirectare($app->url()->cale('/admin/metode-plata', null, ['eroare' => 'ec_plafon']) . '#rambursari-ec');
                }
                $plafon = ['numar' => $n, 'suma' => $suma];
            }
            $permise = $c->camp('permise') === '1';
            $app->ecComenzi()->salveaza($permise, $plafon);
            $app->auditDetalii(['rambursari_ec' => $permise ? 'pornite' : 'oprite', 'plafon_numar' => $plafon['numar'] ?? 0, 'plafon_suma' => $plafon['suma'] ?? 0]);
            return Raspuns::redirectare($app->url()->cale('/admin/metode-plata', null, ['mesaj' => 'rambursari_ec']) . '#rambursari-ec');
        }
        if ($a === 'drept' && $app->modEc()->deciziiInEasyCheck()) {
            // în E și P dreptul „Poate face rambursări” e ascuns, deci nici nu se schimbă de pe server.
            return $inapoi(['eroare' => 'ec_drept']);
        }
        if ($a === 'drept') {
            $nou = $app->sesiuneAdmin()->dreptRambursare((int) ($c->camp('utilizator') ?? 0), $c->camp('da') === '1');
            // la primirea dreptului, angajatul fără 2FA primește atenționarea și recomandarea pe e-mail
            // (în panou o vede până o pornește).
            if ($nou !== null && (int) $nou['are_2fa'] === 0) {
                $app->emailuri()->pune(null, 'drept_rambursare', (string) $nou['limba_panou'], (string) $nou['email'], []);
                $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
            }
            return $inapoi(['mesaj' => 'drept']);
        }
        if (in_array($a, ['cheie', 'secret_anunt', 'sterge_cheie'], true) && !$app->sesiuneAdmin()->reconfirma((int) $u['id'], (string) ($c->camp('parola') ?? ''))) {
            return $inapoi(['eroare' => 'parola']);
        }
        if ($a === 'cheie') {
            $cheie = trim((string) ($c->camp('cheie') ?? ''));
            $modEc = $app->ecLicenta()->stare()['mod'];
            if (\Cazo\Plati\VerificareCheie::forma($cheie) === null && $modEc !== null && !$app->modLocal() && !\Cazo\EasyCheck\Licenta::compatibilCuStripe((string) $modEc, $cheie)) {
                return $inapoi(['eroare' => 'mod_mixt']);
            }
            if (\Cazo\Plati\StripeClient::mod($cheie) === 'live' && !$app->config()->https()) {
                return $inapoi(['eroare' => 'https']);
            }
            // O cheie a altui cont Stripe, cu plăți încă în lucru pe contul de acum: schimbarea așteaptă încheierea lor.
            $deschise = $app->plataOnline()->deschise();
            if (\Cazo\Plati\VerificareCheie::forma($cheie) === null && !$app->stripe()->acelasiCont($cheie, array_column($deschise, 'obiect'))) {
                return $inapoi(['eroare' => 'cont_deschise', 'refs' => implode(',', array_slice(array_column($deschise, 'ref'), 0, 20))]);
            }
            $r = $app->stripe()->salveazaCheie($cheie);
            // fără cheie EasyCheck, cheia Stripe de test face site-ul „de probă”: rezumatul se recitește acum.
            $app->localDupaScriere(['eu']);
            $app->modPlata()->evalueaza();
            if ($r['motiv'] !== null && $r['motiv'] !== 'permisiuni') {
                return $inapoi(['eroare' => $r['motiv']]);
            }
            return $inapoi(['mesaj' => $r['ok'] ? 'cheie_salvata' : 'cheie_permisiuni']);
        }
        if ($a === 'secret_anunt') {
            return $app->stripe()->secretManual((string) ($c->camp('secret') ?? '')) ? $inapoi(['mesaj' => 'anunt_salvat']) : $inapoi(['eroare' => 'secret_anunt']);
        }
        if ($a === 'sterge_cheie') {
            $deschise = $app->plataOnline()->deschise();
            if ($deschise !== []) {
                return $inapoi(['eroare' => 'cont_deschise', 'refs' => implode(',', array_slice(array_column($deschise, 'ref'), 0, 20))]);
            }
            $app->stripe()->stergeCheie();
            $app->localDupaScriere(['eu']);
            $app->modPlata()->evalueaza();
            return $inapoi(['mesaj' => 'cheie_stearsa']);
        }
        if ($a === 'verifica') {
            $app->stripe()->verificaZilnic();
            $app->modPlata()->evalueaza();
            return $inapoi(['mesaj' => 'verificat']);
        }
        return $inapoi([]);
    }

    /**
     * POST /admin/plati/{ref}/inchide: „Am rezolvat plata în contul Stripe” (doar proprietarul sau angajatul cu dreptul
     * de rambursare, cu parola confirmată în ultimele 10 minute); comanda fără ieșire automată devine
     * `inchisa_gazda`, fără bani mutați. Răspunsul se întoarce pe pagina rezervării.
     */
    public static function inchide(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'rambursari');
        if ($s === null) {
            return Raspuns::redirectare($app->url()->cale('/admin/'));
        }
        [$u, $jeton] = $s;
        $inapoi = static fn (array $q): Raspuns => Raspuns::redirectare($app->url()->cale('/admin/rezervari/' . $p['ref'], null, $q));
        if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
            return $inapoi(['eroare' => 'reauth']);
        }
        $cm = $app->comenzi()->dupaRef((string) $p['ref']);
        if ($cm === null) {
            return Raspuns::redirectare($app->url()->cale('/admin/plati'));
        }
        $r = $app->plataOnline()->inchide((int) $cm['id'], (int) $u['id'], $c->camp('confirm_rest') === '1');
        return match ($r) {
            'ok' => $inapoi(['mesaj' => 'inchisa']),
            'rambursata' => $inapoi(['mesaj' => 'inchisa_rambursata']),
            'confirmare' => $inapoi(['eroare' => 'inchidere_confirmare']),
            'sincronizare' => $inapoi(['eroare' => 'inchidere_sincronizare']),
            default => $inapoi(['eroare' => 'inchidere']),
        };
    }

    /**
     * Datele formularului „Am rezolvat plata în contul Stripe” pentru pagina unei rezervări, sau null când comanda nu
     * se poate închide așa ori utilizatorul nu are dreptul.
     * @return array{sume:string, rest:?string, url:string}|null
     */
    public static function dateInchidere(Aplicatie $app, \Cazo\I18n\Traducator $t, array $u, array $cm): ?array
    {
        if (!SesiuneAdmin::poateRambursa($u) || !\Cazo\Rezervare\PlataOnline::poateFiInchisa($cm, $app)) {
            return null;
        }
        // Sumele din bază (recitite din Stripe la apăsarea butonului): încasat, rambursat, restul de confirmat.
        $sum = $app->plataOnline()->sume((int) $cm['id']);
        $rest = $sum['incasat'] - $sum['rambursat'];
        return ['sume' => $t->t('admin.plati.sume', ['incasat' => Format::bani($sum['incasat'], $t->limba()), 'rambursat' => Format::bani($sum['rambursat'], $t->limba())]),
            'rest' => $rest > 0 ? $t->t('admin.plati.confirm_rest', ['suma' => Format::bani($rest, $t->limba())]) : null,
            'url' => $app->url()->cale('/admin/plati/' . $cm['ref'] . '/inchide')];
    }

    private static function intrareFormular(Aplicatie $app, Cerere $c, ?string $eroare, int $status = 200): Raspuns
    {
        // Punctul 22: limba vine din ultima limbă a panoului sau din cea a proprietarului, nu din browser.
        [$l, $cookie] = AdminPanou::limbaPublica($app, $c);
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('', $status);
        if ($cookie !== null) {
            $r->cookie($cookie);
        }
        return self::pagina($app, $l, 'intrare', ['eroare' => $eroare !== null ? $t->t($eroare) : null, 'csrf' => $app->jetonCsrf($c, $r),
            'actiune' => $app->url()->cale('/admin/intrare'), 'url_uitata' => $app->url()->cale('/admin/parola-uitata'), 'url_site' => $app->url()->cale('/'),
            // Câmpul codului stă închis până îl deschide un cont cu verificarea în doi pași; după o greșeală rămâne deschis.
            'cod_deschis' => $eroare !== null || trim((string) ($c->camp('cod') ?? '')) !== '',
            // După o greșeală, adresa scrisă rămâne în câmp (parola și codul, nu).
            'email' => $eroare !== null ? mb_substr(trim((string) ($c->camp('email') ?? '')), 0, 254) : '',
            'alta_limba' => AdminPanou::altaLimba($app, $l, '/admin/'),
            'mesaj' => isset(AdminCont::MESAJE_PUBLICE[$c->param('mesaj') ?? '']) ? $t->t(AdminCont::MESAJE_PUBLICE[$c->param('mesaj')]) : null], $r);
    }

    public static function pagina(Aplicatie $app, string $limba, string $vedere, array $d, Raspuns $r): Raspuns
    {
        // Paginile compuse: cât se strâng blocurile, datele rămân la AdminCompus; o vedere care e bloc al unei
        // pagini compuse se randează în pagina ei, cu celelalte blocuri în jur.
        if (AdminCompus::capteaza($vedere, $d, $r)) {
            return $r;
        }
        if (!isset($d['meniu']) && ($compusa = AdminCompus::paginaPentru($app, $vedere, $d)) !== null) {
            return AdminCompus::randeaza($app, $compusa, ['sursa' => $vedere, 'd' => $d, 'r' => $r]);
        }
        $t = $app->traducator($limba, 'admin');
        $env = MediuNucleu::creeaza(Aplicatie::radacina() . '/vederi', null, $t);
        $active = $app->active()->nucleu();
        // Meniul panoului: grupurile pe care le vede rolul utilizatorului autentificat și antetul cu alertele.
        if (isset($d['utilizator']) && is_array($d['utilizator']) && !isset($d['meniu'])) {
            $d['meniu'] = AdminPanou::meniu($app, $d['utilizator'], (string) ($d['sectiune'] ?? ''));
            $d['antet'] = AdminPanou::antet($app, $t, $d['utilizator'], (string) ($d['sectiune'] ?? ''));
            // Codurile de rezervă abia generate: pe prima pagină randată, o singură dată.
            $d['coduri_noi'] = AdminPanou::coduriDeAfisat($app, (int) $d['utilizator']['id']);
        }
        $html = $env->render('admin/' . $vedere . '.twig', $d + ['limba' => $limba, 'css' => $active['admin.css'] ?? '', 'js_ramane' => $active['admin/ramane.js'] ?? '', 'site' => $app->modelVedere('ro')->nume()]);
        $p = Raspuns::html($html, $r->status);
        foreach ($r->cookies() as $k) {
            $p->cookie($k);
        }
        return $p;
    }
}
