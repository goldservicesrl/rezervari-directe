<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;
use Cazo\Rezervare\Anulare;
use Cazo\Securitate\Codare;
use Cazo\Securitate\SesiuneAdmin;

defined('CAZO') or exit;

/**
 * Panoul după rezervare:
 *
 *   GET  /admin/rezervari/{ref}                  pagina rezervării: camera, oaspeții, tariful, mesajul oaspetelui, sumele,
 *                                                jurnalul în cuvinte, rambursările, dialogurile (cererea de anulare,
 *                                                rambursarea, anularea, plata rezolvată în Stripe)
 *   POST /admin/rezervari/{ref}/decizie          cererea de anulare: acceptată cu suma aleasă, sau rezervarea păstrată
 *   POST /admin/rezervari/{ref}/rambursare       rambursarea totală sau parțială, cu motiv; opțional „Anulați și
 *                                                rezervarea în EasyCheck” (confirmata → anulata_gazda, cu suma aleasă)
 *   POST /admin/rezervari/{ref}/anulare          anularea unei rezervări cu plata la sosire
 *   POST /admin/rezervari/{ref}/reincearca       o nouă încercare a anulării în EasyCheck
 *   POST /admin/rezervari/{ref}/lamurire         anularea făcută de gazdă în EasyCheck: suma returnată, de la zero
 *                                                la net, cu alegerile rapide „toată suma” (`alegere=tot`) și „nimic”
 *                                                (`alegere=nimic`); anulare_de_lamurit → anulata_gazda
 *   GET  /admin/plati/export.csv                 reconcilierea pe lună sau pe perioadă
 *   GET  /admin/plati/avansuri                   avansurile încasate pentru sejururi viitoare (Ord. 948/2026), și .csv
 *
 * Deciziile cu bani (rambursarea, cererea de anulare, anularea) le iau proprietarul și angajatul cu dreptul „Poate face
 * rambursări”, cu parola confirmată în ultimele 10 minute (câmpul `parola_reauth`, cerut doar când
 * confirmarea a expirat); pagina o vede oricine din panou. Raportul lunar e al proprietarului; avansurile le văd
 * proprietarul și angajatul cu dreptul de rambursare (14).
 */
final class AdminRezervari
{
    public const MESAJE = ['anulata' => 'admin.rez.mesaj.anulata', 'in_lucru' => 'admin.rez.mesaj.in_lucru', 'pastrata' => 'admin.rez.mesaj.pastrata',
        'reusit' => 'admin.rez.mesaj.rambursata', 'in_procesare' => 'admin.rez.mesaj.in_procesare', 'cerut' => 'admin.rez.mesaj.cerut', 'renuntata' => 'admin.rez.mesaj.renuntata',
        // deciziile de probă ale plății prin transfer, luate de pe pagina rezervării.
        'plata_confirmata_proba' => 'admin.transfer.proba_confirmata', 'plata_refuzata_proba' => 'admin.transfer.proba_refuzata',
        // „Prelungiți termenul”, de pe pagina rezervării.
        'termen_prelungit' => 'admin.transfer.termen_prelungit',
        // „Am returnat taxa locală”, la rezervarea plătită prin transfer și anulată.
        'taxa_returnata' => 'admin.rez.mesaj.taxa_returnata',
        // „Am plătit despăgubirea” și „Anulare din forță majoră”, după anularea gazdei cu clauza despăgubirii.
        'despagubire_platita' => 'admin.rez.mesaj.despagubire_platita', 'forta_majora' => 'admin.rez.mesaj.forta_majora', 'despagubire_aleasa' => 'admin.rez.mesaj.despagubire_aleasa',
        // proba trimisă la EasyCheck (modul P).
        'proba_ec' => 'admin.rez.mesaj.proba_ec']
        + AdminLista::MESAJE + Admin::MESAJE_INCHIDERE;
    public const ERORI = ['parola' => 'admin.reauth.eroare', 'reauth' => 'admin.reauth.eroare', 'suma' => 'admin.rez.eroare.suma', 'stare' => 'admin.rez.eroare.stare', 'ocupat' => 'admin.rez.eroare.ocupat',
        'esuat' => 'admin.rez.eroare.esuat', 'drept' => 'admin.rez.eroare.drept', 'in_curs' => 'admin.rez.eroare.in_curs', 'termen_trecut' => 'admin.transfer.termen_trecut',
        // despăgubirea „de la caz la caz”, necompletată la anularea gazdei.
        'despagubire' => 'admin.rez.eroare.despagubire', 'mai_putin' => 'admin.rez.eroare.mai_putin',
        // decizia se ia în EasyCheck (modurile E și P); proba n-a ajuns la EasyCheck.
        'ec' => 'admin.rez.eroare.ec', 'proba_ec' => 'admin.rez.eroare.proba_ec'] + AdminLista::ERORI + Admin::ERORI_INCHIDERE;
    /**
     * acțiunile de decizie ascunse în modurile E și P (cheie cu funcția etapei 3 aprinsă) și
     * refuzate de server; în L și V rămân ca până acum. Reparațiile site-ului (reîncercarea, renunțarea, reluarea, închiderea
     * plății rezolvate în Stripe) rămân în toate modurile.
     */
    public const ASCUNSE_EC = ['decizie', 'rambursare', 'anulare', 'lamurire', 'despagubire_caz', 'forta_majora', 'despagubire', 'taxa'];
    /** Butoanele de probă ale etapei 3 (doar în modul P) => ruta EasyCheck de probă. */
    public const PROBE_EC = ['proba-anuleaza' => 'proba-anuleaza-gazda', 'proba-accepta-anularea' => 'proba-anulare-decide', 'proba-pastreaza' => 'proba-anulare-decide',
        'proba-rambursare' => 'proba-rambursare',
        // comanda `mesaj` pe cheia de probă, cu `functii.mesaje`.
        'proba-confirmarea' => 'proba-mesaj', 'proba-transfer' => 'proba-mesaj'];

    public static function detaliu(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        $cm = $app->comenzi()->dupaRef((string) $p['ref']);
        if ($cm === null) {
            return AdminPanou::inapoi($app, '/admin/rezervari');
        }
        return self::pagina($app, $c, $s[0], $s[1], $cm);
    }

    /**
     * Pagina rezervării. $gresit: formularul întors după o sumă greșită (`actiune`, `suma` și restul câmpurilor scrise
     * de gazdă), ca eroarea să stea lângă câmp, cu valorile păstrate (422).
     * @param array{actiune:string, suma:string, motiv?:string, nota?:string, anuleaza?:bool}|null $gresit
     */
    private static function pagina(Aplicatie $app, Cerere $c, array $u, string $jeton, array $cm, ?array $gresit = null): Raspuns
    {
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('', $gresit !== null ? 422 : 200);
        $an = $app->anulare();
        $id = (int) $cm['id'];
        $o = $app->comenzi()->oaspete($cm);
        $plata = $an->plata($id);
        $incasat = $plata !== null ? (int) $plata['suma_incasata'] : 0;
        $rambursat = $app->rambursari()->rambursatSauInCurs($id);
        $net = $an->net($cm);
        $poate = SesiuneAdmin::poateRambursa($u);
        $bani = static fn (int $s): string => Format::bani($s, $l);
        $propunere = $an->propunere($cm);
        $suma = static fn (string $actiune, string $implicita): array => ['suma' => $gresit !== null && $gresit['actiune'] === $actiune ? $gresit['suma'] : $implicita,
            'eroare' => $gresit !== null && $gresit['actiune'] === $actiune];
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'rezervari', self::MESAJE, self::ERORI);
        if ($gresit !== null) {
            $d['eroare'] = $t->t(($gresit['mai_putin'] ?? null) !== null ? 'admin.rez.eroare.mai_putin' : 'admin.rez.eroare.suma');
        }
        // avertizarea returnării sub tot ce s-a plătit, lângă câmpul formularului trimis; bifa de confirmare stă în
        // formularele anulării gazdei doar la comenzile cu clauza anulării gazdei în contract.
        $maiPutin = static fn (string $actiune): array => ['mai_putin' => ($cm['anulare_gazda'] ?? null) !== null,
            'avertizare' => $gresit !== null && $gresit['actiune'] === $actiune && ($gresit['mai_putin'] ?? null) !== null
                ? $t->t('admin.rez.avertizare_mai_putin', ['suma_totala' => Format::bani($an->net($cm), $l), 'suma' => Format::bani((int) $gresit['mai_putin'], $l),
                    'diferenta' => Format::bani($an->net($cm) - (int) $gresit['mai_putin'], $l)]) : null];
        $jurnal = self::jurnal($app, $t, $cm, $l);
        // Transferul care așteaptă plata: pe cheia reală, primirea banilor se confirmă doar în EasyCheck, iar
        // panoul o spune, cu legătura; pe cheia de probă, la o rezervare de probă, plata se hotărăște de aici, cu butoanele
        // de probă (ale proprietarului, ca pe Acasă), iar textul spune asta.
        $asteapta = $cm['fel'] === 'transfer' && $cm['stare'] === \Cazo\Rezervare\Transfer::ASTEPTARE;
        $deProba = $asteapta && (($app->ecLicenta()->stare()['proba'] && (int) $cm['proba'] === 1) || $app->modLocal());
        $d += [
            'transfer_proba' => $deProba ? ['poate' => Admin::poateDecide($u) && AdminTransfer::poateProba($app, $cm), 'url' => $app->url()->cale('/admin/transfer/' . $cm['ref']),
                'prelungire' => Admin::poateDecide($u) && AdminTransfer::poatePrelungi($app, $cm), 'variante' => AdminTransfer::variantePrelungire($t)] : null,
            'ref' => (string) $cm['ref'],
            'stare' => AdminLista::textStare($t, (string) $cm['stare'], $cm),
            'stare_clasa' => AdminLista::clasaStare((string) $cm['stare']),
            'transfer_ec' => $asteapta && !$deProba ? AdminTransfer::urlEasyCheck($app) : null,
            'fel' => $cm['fel'],
            'plata_fel' => $t->t('admin.rezervari.fel.' . $cm['fel']),
            'sejur' => Format::zi((string) $cm['sosire'], $l) . ' - ' . Format::zi((string) $cm['plecare'], $l),
            'camere' => self::camere($app, $t, $cm),
            'tarif' => self::tarif($app, $cm, $l),
            'creata' => Format::moment((string) $cm['creat_la'], $l),
            'anonimizata' => $cm['anonimizat_la'] !== null,
            'oaspete' => ['nume' => (string) ($o['nume'] ?? ''), 'email' => (string) ($o['email'] ?? ''), 'telefon' => (string) ($o['telefon'] ?? ''),
                // Numărul pentru `tel:`: doar cifrele și plusul de la început.
                'tel' => (string) preg_replace('~(?!^\+)[^0-9]~', '', trim((string) ($o['telefon'] ?? ''))),
                'tara' => self::tara((string) ($o['tara'] ?? $cm['tara'] ?? ''), $l),
                'limba' => $t->are('admin.rez.limba.' . $cm['limba']) ? $t->t('admin.rez.limba.' . $cm['limba']) : ''],
            'nota' => $cm['anonimizat_la'] === null ? $app->comenzi()->nota($cm) : '',
            'sume' => self::sume($t, $cm, $incasat, $rambursat, $bani),
            'regula' => self::regula($t, $l, $cm, $app->anulare()->avansMutare($cm))
                // clauza despăgubirii din contract, când gazda anulează.
                . (Anulare::despagubireContract($cm) > 0 ? ' ' . $t->t('admin.rez.regula_despagubire', ['avans' => Format::bani(Anulare::despagubireContract($cm), $l)])
                    : (($cm['anulare_gazda'] ?? null) === Anulare::GAZDA_CAZ ? ' ' . $t->t('admin.rez.regula_despagubire_caz') : '')),
            // Codurile pentru suport (rezervarea și cererea din EasyCheck, codul refuzului, identificatorul ultimei cereri).
            'suport' => array_filter(['ec_rezervare' => (string) ($cm['ec_rezervare'] ?? ''), 'ec_cerere' => (string) ($cm['ec_cerere'] ?? ''), 'eroare_cod' => (string) ($cm['eroare_cod'] ?? ''),
                'request_id' => (string) ($cm['request_id'] ?? '')], static fn (string $v): bool => $v !== ''),
            'jurnal' => $jurnal['intamplari'],
            'jurnal_brut' => $jurnal['brut'],
            'schimbari' => self::schimbari($app, $t, $id, $l),
            'operatii' => self::operatii($app, $t, $id, $l),
            'poate' => $poate,
            'url' => ['baza' => $app->url()->cale('/admin/rezervari/' . $cm['ref']), 'lista' => $app->url()->cale('/admin/rezervari')],
            // confirmarea trimisă din nou (14 Rezervări), la rezervările vii, înainte de anonimizare.
            // cu mesajele cerute din EasyCheck (E și P cu `functii.mesaje`), retrimiterea se cere de acolo.
            'retrimite' => in_array($cm['stare'], ['confirmata', 'anulare_ceruta'], true) && $cm['anonimizat_la'] === null && !$app->modEc()->mesajeInEasyCheck(),
            'reia' => $poate && AdminLista::poateRelua($cm, $app->ceas()->acum()),
            // „Am rezolvat plata în contul Stripe”: null când nu se aplică sau utilizatorul nu are dreptul.
            'inchidere' => Admin::dateInchidere($app, $t, $u, $cm),
            // taxa locală de returnat de gazdă (plata prin transfer, rezervarea anulată), cu „Am returnat taxa locală”.
            'taxa_de_returnat' => (int) ($cm['taxa_de_returnat'] ?? 0) > 0 ? ['text' => $t->t($cm['taxa_returnata_la'] === null ? 'admin.rez.taxa_de_returnat' : 'admin.rez.taxa_returnata',
                ['taxa' => Format::bani((int) $cm['taxa_de_returnat'], $l), 'data' => $cm['taxa_returnata_la'] !== null ? Format::moment((string) $cm['taxa_returnata_la'], $l) : '']),
                'buton' => $cm['taxa_returnata_la'] === null && $poate] : null,
            // despăgubirea datorată oaspetelui după anularea gazdei, cu „Am plătit despăgubirea” și „Anulare din forță majoră”.
            'despagubire' => self::despagubire($t, $l, $cm, $poate),
            // la anularea gazdei, bifa „Anulare din forță majoră” (doar cu clauza despăgubirii în contract).
            'forta_majora' => Anulare::despagubireContract($cm) > 0 && $cm['platita_la'] !== null,
            // cu despăgubirea „de la caz la caz”, câmpul sumei în formularele anulării gazdei; după o anulare fără
            // dialog (din EasyCheck), formularul deciziei pe pagina rezervării.
            'despagubire_caz' => Anulare::despagubireDeHotarat($cm) && in_array($cm['stare'], ['confirmata', Anulare::DE_LAMURIT], true),
            'despagubire_decizie' => Anulare::despagubireDeHotarat($cm) && $cm['stare'] === 'anulata_gazda' && $poate,
        ];
        if ($app->rambursari()->cerutDeschis($id)) {
            $d['rambursare_in_curs'] = $t->t('admin.rez.rambursare_in_curs');
        }
        // în modurile E și P deciziile despre bani se iau în EasyCheck; pagina arată starea
        // hotărârii și „Deschideți în EasyCheck”, iar în P butoanele de probă. Rolul `personal` nu vede nimic despre bani.
        $modEc = $app->modEc()->mod();
        $inEc = in_array($modEc, [\Cazo\EasyCheck\ModEc::EASYCHECK, \Cazo\EasyCheck\ModEc::PROBA], true);
        if ($inEc && $poate) {
            $d['ec_hotarare'] = ['texte' => self::texteEc($app, $t, $cm, $bani), 'url' => $app->modEc()->urlRezervare($cm)];
            if ($modEc === \Cazo\EasyCheck\ModEc::PROBA && Admin::poateDecide($u) && (int) ($cm['proba'] ?? 0) === 1) {
                $d['probe_ec'] = self::probeEc($cm, $net, $propunere, $app->modEc()->mesajeInEasyCheck());
            }
        }
        // „Deschideți în EasyCheck” pe fiecare rezervare, pentru toți cei din panou (cei fără drept la bani nu văd
        // cardul hotărârilor de mai sus, dar rezervarea o deschid și ei acolo).
        if ($inEc && !isset($d['ec_hotarare'])) {
            $d['ec_deschide'] = $app->modEc()->urlRezervare($cm);
        }
        if ($inEc) {
            $d['despagubire'] = null;
            $d['despagubire_caz'] = false;
            $d['despagubire_decizie'] = false;
            $d['forta_majora'] = false;
            if (is_array($d['taxa_de_returnat'])) {
                $d['taxa_de_returnat']['buton'] = false;
            }
        }
        if ($cm['anulare_actor'] !== null && $cm['anulata_ec_la'] === null && in_array($cm['stare'], ['confirmata', 'anulare_ceruta'], true)) {
            $refuzata = $cm['anulare_eroare'] !== null && $cm['anulare_urmatoarea'] === null;
            $d['anulare_in_lucru'] = $refuzata ? $t->t('admin.rez.anulare_refuzata') : $t->t('admin.rez.anulare_in_lucru', ['suma' => $bani((int) $cm['anulare_suma'])]);
            if ($refuzata) {
                $d['suport']['anulare_eroare'] = (string) $cm['anulare_eroare'];
            }
            $d['reincearca'] = $poate && $cm['anulare_urmatoarea'] === null;
            // Ieșirea gazdei după refuzul definitiv: renunță la anulare, rezervarea rămâne confirmată.
            $d['renunta'] = $poate && Anulare::refuzataDefinitiv($cm);
            $d['renunta_ajutor'] = $t->t(Anulare::pastreazaGratuita($cm) ? 'admin.rez.renunta_ajutor_oaspete' : 'admin.rez.renunta_ajutor');
        } elseif ($poate && !$inEc) {
            if ($cm['stare'] === Anulare::DE_LAMURIT) {
                // propunerea după regula rezervării (ca la cererea după termen), precompletată; suma o alege gazda.
                // Banii spuși după rambursările anterioare; cu o rambursare nelămurită, formularul așteaptă lămurirea ei.
                $bl = $an->baniLamurire($cm);
                $text = $t->t('admin.rez.lamurire_text', ['data' => Format::moment((string) $cm['anulata_ec_la'], $l)]) . ' ' . Anulare::textBaniLamurire($t, $bl, $bani);
                if ($bl['cerut'] === 0) {
                    $text .= ' ' . $t->t('admin.rez.lamurire_alegeti', ['propunere' => $bani($propunere['suma']), 'retine' => $bani($propunere['retine'])]);
                }
                $d['lamurire'] = ['text' => $text, 'drept' => $t->t('admin.rez.lamurire_drept'), 'net' => $bani($net), 'in_curs' => $bl['cerut'] > 0] + $suma('lamurire', self::lei($propunere['suma']))
                    + $maiPutin('lamurire');
            }
            if ($cm['stare'] === 'anulare_ceruta') {
                $d['decizie'] = ['text' => $t->t('admin.rez.cerere_text', ['data' => Format::moment((string) $cm['anulare_ceruta_la'], $l), 'propunere' => $bani($propunere['suma']),
                    'retine' => $bani($propunere['retine']), 'net' => $bani($net)]), 'net' => $bani($net)] + $suma('decizie', self::lei($propunere['suma']));
            }
            if ($cm['fel'] === 'card' && $net > 0 && in_array($cm['stare'], ['confirmata', 'anulata_oaspete', 'anulata_gazda'], true)) {
                $d['rambursare'] = ['net' => $bani($net), 'jeton' => Codare::jeton(16), 'motive' => Anulare::MOTIVE_PANOU,
                    'motiv' => (string) ($gresit['motiv'] ?? ''), 'nota' => (string) ($gresit['nota'] ?? ''), 'anuleaza_bifat' => (bool) ($gresit['anuleaza'] ?? false),
                    'anuleaza' => $cm['stare'] === 'confirmata', 'propunere' => $cm['stare'] === 'confirmata' ? $t->t('admin.rez.propunere', ['suma' => $bani($propunere['suma'])]) : null]
                    + $suma('rambursare', self::lei($net)) + $maiPutin('rambursare');
            }
            if ($cm['stare'] === 'confirmata' && ($cm['fel'] !== 'card' || $net === 0)) {
                $d['anulare'] = true;
            }
        }
        return AdminPanou::pagina($app, $u, 'rezervare', $d, $r);
    }

    /**
     * „Sumele” de pe pagina rezervării. La plata cu cardul și la cea de la sosire: totalul, încasat online, rambursat,
     * de plătit la sosire. La transferul bancar banii intră în contul gazdei, în afara site-ului, deci „Încasat
     * online” și „Rambursat” lipsesc, iar în locul lor stă suma transferului: „De primit prin transfer bancar” cât plata
     * e așteptată, „Primit prin transfer bancar” după confirmarea ei (și la o anulare de după confirmare), nimic la
     * transferul rămas neprimit. Restul de la sosire rămâne cel al comenzii.
     * @return list<array{0:string, 1:string}>
     */
    private static function sume(Traducator $t, array $cm, int $incasat, int $rambursat, \Closure $bani): array
    {
        $sume = [[$t->t('admin.rez.total'), $bani((int) $cm['total'])]];
        if ($cm['fel'] === 'transfer') {
            if ($cm['platita_la'] !== null) {
                $sume[] = [$t->t('admin.rez.transfer_primit'), $bani((int) $cm['suma_acum'])];
            } elseif (in_array($cm['stare'], ['noua', 'trimitere_ec', \Cazo\Rezervare\Transfer::ASTEPTARE], true)) {
                $sume[] = [$t->t('admin.rez.transfer_de_primit'), $bani((int) $cm['suma_acum'])];
            }
        } else {
            $sume[] = [$t->t('admin.rez.incasat'), $bani($incasat)];
            $sume[] = [$t->t('admin.rez.rambursat'), $bani($rambursat)];
        }
        $sume[] = [$t->t('admin.rez.rest_sosire'), $bani((int) $cm['rest_la_sosire'])];
        return $sume;
    }

    /** @return list<array{titlu:string, oaspeti:string}> camerele rezervării, cu numele lor din EasyCheck și oaspeții fiecăreia */
    private static function camere(Aplicatie $app, Traducator $t, array $cm): array
    {
        $titluri = AdminLista::titluriCamere($app);
        $r = [];
        foreach ($app->comenzi()->camere($cm) as $x) {
            $copii = (int) ($x['copii'] ?? 0);
            $r[] = ['titlu' => $titluri[(string) ($x['camera'] ?? '')] ?? $t->t('admin.rez.camera_scoasa'),
                'oaspeti' => $t->t($copii > 0 ? 'admin.rez.oaspeti_copii' : 'admin.rez.oaspeti', ['adulti' => (string) (int) ($x['adulti'] ?? 0), 'copii' => (string) $copii])];
        }
        return $r;
    }

    /** Tariful ales: numele planului de acum (cu numele dat de gazdă pe site), altfel cel înghețat în contractul comenzii. */
    private static function tarif(Aplicatie $app, array $cm, string $l): string
    {
        $plan = $cm['plan'] !== null ? ($app->pretNoapte()->planuri()[(string) $cm['plan']] ?? null) : null;
        if ($plan !== null) {
            return \Cazo\Teme\Componente::titluPlan($plan, $l, $app);
        }
        $contract = json_decode((string) ($cm['contract'] ?? ''), true);
        return is_array($contract) && is_string($contract['tarif'] ?? null) ? $contract['tarif'] : '';
    }

    /** Numele țării după codul ei (lista din comandă); un cod necunoscut rămâne gol. */
    private static function tara(string $cod, string $l): string
    {
        if ($cod === '') {
            return '';
        }
        $tari = (array) require Aplicatie::radacina() . '/limbi/tari.php';
        return isset($tari[$cod]) ? (string) $tari[$cod][$l === 'ro' ? 0 : 1] : '';
    }

    /**
     * Istoria schimbărilor de date (118-118d): fiecare încercare, cu datele vechi și noi și starea. Codurile
     * rezervărilor din EasyCheck (cea veche, cea nouă și, la o refacere, cea refăcută) rămân în jurnalul pentru suport.
     * @return list<array{text:string}>
     */
    private static function schimbari(Aplicatie $app, Traducator $t, int $id, string $l): array
    {
        $r = [];
        foreach ($app->schimbareDate()->istoric($id) as $s) {
            $text = $t->t('admin.rez.schimbare_rand', ['la' => Format::moment((string) $s['creat_la'], $l),
                'perioada_veche' => Format::zi((string) $s['sosire_veche'], $l) . ' - ' . Format::zi((string) $s['plecare_veche'], $l),
                'perioada' => Format::zi((string) $s['sosire'], $l) . ' - ' . Format::zi((string) $s['plecare'], $l),
                'ec_veche' => (string) $s['ec_veche'], 'ec_noua' => (string) ($s['ec_noua'] ?? '-'), 'stare' => $t->t('admin.schimbare.stare.' . $s['stare'])]);
            if ($s['ec_refacuta'] !== null) {
                $text .= ' ' . $t->t('admin.rez.schimbare_refacuta', ['ec' => (string) $s['ec_refacuta']]);
            }
            $r[] = ['text' => $text];
        }
        return $r;
    }

    /**
     * POST /admin/rezervari/{ref}/{actiune}: deciziile cu bani, cu dreptul de rambursare și parola confirmată în
     * ultimele 10 minute. O sumă greșită întoarce pagina cu 422, cu eroarea lângă câmp și valorile păstrate.
     */
    public static function actiune(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        $cm = $app->comenzi()->dupaRef((string) $p['ref']);
        if ($s === null || $cm === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $inapoi = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/rezervari/' . $cm['ref'], $q);
        if (!SesiuneAdmin::poateRambursa($u)) {
            return $inapoi(['eroare' => 'drept']);
        }
        $a = (string) $p['actiune'];
        // în modurile E și P, deciziile despre bani se iau în EasyCheck; serverul le refuză, nu doar le ascunde.
        if (in_array($a, self::ASCUNSE_EC, true) && $app->modEc()->deciziiInEasyCheck()) {
            $app->auditDetalii(['eroare' => 'ec']);
            return $inapoi(['eroare' => 'ec']);
        }
        // „Păstrați rezervarea” nu mută bani: doar dreptul. Restul (și „Renunțați la anulare”, care hotărăște soarta unei
        // anulări cerute) cere parola confirmată de curând.
        $pastreaza = $a === 'decizie' && $c->camp('decizie') === 'pastreaza';
        // „Am returnat taxa locală” și „Am plătit despăgubirea” doar notează un transfer făcut de gazdă din bancă: fără parolă.
        if (!$pastreaza && !in_array($a, ['taxa', 'despagubire'], true) && !AdminPanou::reautentificat($app, $c, $u, $jeton)) {
            return $inapoi(['eroare' => 'reauth']);
        }
        $an = $app->anulare();
        $uid = (int) $u['id'];
        $id = (int) $cm['id'];
        $scris = (string) ($c->camp('suma') ?? '');
        $suma = self::bani($scris);
        // „Anulare din forță majoră”, bifată la anularea gazdei: fără despăgubire.
        $fm = $c->camp('forta_majora') === '1';
        // despăgubirea hotărâtă de gazdă la anulare („de la caz la caz”); null când lipsește sau e greșită.
        $desp = self::bani((string) ($c->camp('despagubire_suma') ?? ''));
        // „Am înțeles și returnez mai puțin”, bifată după avertizare.
        $mp = $c->camp('mai_putin') === '1';
        $r = match ($a) {
            'decizie' => $pastreaza ? $an->deciziaGazdei($id, $uid, 'pastreaza', 0) : ($suma === null ? 'suma' : $an->deciziaGazdei($id, $uid, 'accepta', $suma)),
            'rambursare' => $suma === null ? 'suma' : ($c->camp('anuleaza') === '1' && $cm['stare'] === 'confirmata'
                ? $an->dinPanou($id, $uid, $suma, false, $fm, $desp, $mp)
                : $an->rambursare($id, $uid, $suma, (string) ($c->camp('motiv') ?? ''), (string) ($c->camp('nota') ?? ''), (string) ($c->camp('jeton') ?? ''))),
            'anulare' => $an->dinPanou($id, $uid, 0, true, $fm, $desp),
            'reincearca' => $an->reincearca($id, $uid),
            'renunta' => $an->renunta($id, $uid),
            'taxa' => $an->taxaReturnata($id, $uid),
            'despagubire' => $an->despagubirePlatita($id, $uid),
            'forta_majora' => $an->fortaMajora($id, $uid),
            'despagubire_caz' => $an->despagubireAleasa($id, $uid, $desp),
            'lamurire' => match ((string) ($c->camp('alegere') ?? '')) {
                'tot' => $an->lamureste($id, $uid, $an->net($cm), $fm, $desp, $mp),
                'nimic' => $an->lamureste($id, $uid, 0, $fm, $desp, $mp),
                default => $suma === null ? 'suma' : $an->lamureste($id, $uid, $suma, $fm, $desp, $mp),
            },
            default => 'stare',
        };
        $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
        if ($r === 'suma' || $r === 'mai_putin') {
            // Eroarea lângă câmp, cu ce a scris gazda (comanda recitită: starea ei s-a putut schimba între timp). sub
            // tot ce s-a plătit, avertizarea cu sumele, lângă câmp, și bifa de confirmare.
            $app->auditDetalii(['eroare' => $r]);
            $alegere = (string) ($c->camp('alegere') ?? '');
            $returnat = $a === 'lamurire' ? match ($alegere) { 'tot' => $an->net($cm), 'nimic' => 0, default => (int) $suma } : (int) $suma;
            return self::pagina($app, $c, $u, $jeton, $app->comenzi()->dupaRef((string) $cm['ref']) ?? $cm, ['actiune' => $a,
                'suma' => $r === 'mai_putin' ? self::lei($returnat) : mb_substr($scris, 0, 20),
                'motiv' => (string) ($c->camp('motiv') ?? ''), 'nota' => mb_substr((string) ($c->camp('nota') ?? ''), 0, 500), 'anuleaza' => $c->camp('anuleaza') === '1',
                'mai_putin' => $r === 'mai_putin' ? $returnat : null]);
        }
        return isset(self::MESAJE[$r]) ? $inapoi(['mesaj' => $r]) : $inapoi(['eroare' => isset(self::ERORI[$r]) ? $r : 'stare']);
    }

    /**
     * rândurile de stare ale hotărârii din EasyCheck, pe pagina rezervării (modurile E și P).
     * @return list<string>
     */
    private static function texteEc(Aplicatie $app, Traducator $t, array $cm, \Closure $bani): array
    {
        $r = [];
        if (in_array($cm['stare'], ['anulata_gazda', 'anulata_oaspete'], true) && ($cm['ec_restituire_cale'] ?? null) !== null) {
            $suma = $bani((int) $cm['anulare_suma']);
            // `anulata_oaspete` cu o hotărâre din EasyCheck: oaspetele a cerut anularea, iar gazda a acceptat-o acolo
            // (Anulare::hotarareEc, motivul `cerere_oaspete`); `anulata_gazda`: gazda a anulat-o ea.
            $cheie = $cm['stare'] === 'anulata_oaspete'
                ? match ($cm['ec_restituire_cale']) { 'comanda' => 'admin.rez.ec.acceptata_card', 'in_afara_site' => 'admin.rez.ec.acceptata_gazda_returneaza', default => 'admin.rez.ec.acceptata_fara' }
                : match ($cm['ec_restituire_cale']) { 'comanda' => 'admin.rez.ec.anulata_card', 'in_afara_site' => 'admin.rez.ec.anulata_gazda_returneaza', default => 'admin.rez.ec.anulata_fara' };
            $r[] = $t->t($cheie, ['suma' => $suma]);
        } elseif ($cm['stare'] === 'anulare_ceruta' && ($cm['ec_cerere_stare'] ?? null) === 'trimisa') {
            $r[] = $t->t('admin.rez.ec.cerere');
        } elseif ($cm['stare'] === Anulare::DE_LAMURIT) {
            $r[] = $t->t('admin.rez.ec.de_hotarat');
        }
        foreach ($app->baza()->toate('SELECT stare, suma, suma_executata FROM {ec_comenzi} WHERE comanda_id = ? AND tip = ? ORDER BY primita_la DESC LIMIT 3', [(int) $cm['id'], 'rambursare']) as $x) {
            $r[] = $t->t('admin.rez.ec.comanda', ['stare' => $t->t('admin.rez.ec.stare.' . $x['stare']), 'suma' => $bani((int) ($x['suma_executata'] ?? $x['suma']))]);
        }
        if ($r === []) {
            $r[] = $t->t('admin.rez.ec.general');
        }
        return $r;
    }

    /** butoanele de probă ale etapei 3 potrivite stării rezervării (modul P, doar pe rezervările de probă). @return list<array{cale:string, suma:?string}> */
    private static function probeEc(array $cm, int $net, array $propunere, bool $mesaje = false): array
    {
        $r = [];
        // comanda `mesaj` de probă: confirmarea retrimisă și anunțul restituirii prin transfer.
        if ($mesaje && $cm['anonimizat_la'] === null) {
            if (in_array($cm['stare'], ['confirmata', 'anulare_ceruta'], true)) {
                $r[] = ['cale' => 'proba-confirmarea', 'suma' => null];
            }
            if (in_array($cm['stare'], ['confirmata', 'anulare_ceruta', 'anulata_oaspete', 'anulata_gazda'], true)) {
                $r[] = ['cale' => 'proba-transfer', 'suma' => self::lei(max(100, $cm['fel'] === 'transfer' ? (int) ($cm['suma_acum'] ?? 0) : $net))];
            }
        }
        if ($cm['stare'] === 'confirmata') {
            $r[] = ['cale' => 'proba-anuleaza', 'suma' => $cm['fel'] === 'card' ? self::lei($net) : null];
        }
        if ($cm['stare'] === 'anulare_ceruta' && ($cm['ec_cerere_stare'] ?? null) === 'trimisa') {
            $r[] = ['cale' => 'proba-accepta-anularea', 'suma' => self::lei((int) $propunere['suma'])];
            $r[] = ['cale' => 'proba-pastreaza', 'suma' => null];
        }
        if ($cm['fel'] === 'card' && $net > 0 && in_array($cm['stare'], ['confirmata', 'anulata_oaspete', 'anulata_gazda'], true)) {
            $r[] = ['cale' => 'proba-rambursare', 'suma' => self::lei($net)];
        }
        return $r;
    }

    /**
     * POST /admin/rezervari/{ref}/{proba}: butoanele de probă ale etapei 3, doar în modul P, pe o
     * rezervare de probă, pentru proprietar. Rutele EasyCheck de probă joacă rolul gazdei; site-ul urmează apoi hotărârea pe
     * drumul real (lista rezervărilor, comanda, rambursarea cu cardurile de probă Stripe).
     */
    public static function probaEc(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        $cm = $app->comenzi()->dupaRef((string) $p['ref']);
        if ($s === null || $cm === null) {
            return AdminPanou::inapoi($app);
        }
        [$u] = $s;
        $inapoi = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/rezervari/' . $cm['ref'], $q);
        $a = (string) $p['proba'];
        if (!Admin::poateDecide($u) || $app->modEc()->mod() !== \Cazo\EasyCheck\ModEc::PROBA || (int) ($cm['proba'] ?? 0) !== 1 || !isset(self::PROBE_EC[$a])
            || !preg_match('~^rez_[0-9A-Za-z]{24}$~D', (string) ($cm['ec_rezervare'] ?? ''))
            || (self::PROBE_EC[$a] === 'proba-mesaj' && !$app->modEc()->mesajeInEasyCheck())) {
            return $inapoi(['eroare' => 'stare']);
        }
        $suma = self::bani((string) ($c->camp('suma') ?? '')) ?? 0;
        $corp = match ($a) {
            'proba-anuleaza' => ['restituie' => max(0, $suma)],
            'proba-accepta-anularea' => ['decizie' => 'accepta', 'restituie' => max(0, $suma)],
            'proba-pastreaza' => ['decizie' => 'pastreaza'],
            'proba-confirmarea' => ['mesaj' => 'retrimite_confirmarea'],
            'proba-transfer' => ['mesaj' => 'restituire_transfer', 'parametri' => ['suma' => max(1, $suma)]],
            default => ['suma' => max(1, $suma)],
        };
        try {
            $app->ecClient()->scrie('POST', '/rezervari/' . $cm['ec_rezervare'] . '/' . self::PROBE_EC[$a], \Cazo\EasyCheck\Client::json($corp));
        } catch (\Cazo\EasyCheck\EroareApi | \Cazo\EasyCheck\EroareTransport $e) {
            $app->auditDetalii(['eroare' => $e instanceof \Cazo\EasyCheck\EroareApi ? $e->raspuns->status . ' ' . ($e->cod ?? '') : $e->felul]);
            return $inapoi(['eroare' => 'proba_ec']);
        }
        $app->auditDetalii(['proba' => $a]);
        $app->ecComenzi()->deCitit();
        $app->coada()->pune(Anulare::JOB, [], Anulare::JOB);
        $app->dupaRaspuns(static function () use ($app): void {
            $app->anulare()->sincronizeaza('cron');
            $app->ecComenzi()->sarcina();
            $app->emailuri()->trimite(5);
        });
        return $inapoi(['mesaj' => 'proba_ec']);
    }

    /**
     * Exportul de reconciliere, CSV: comanda, rezervarea EasyCheck, plata (pi_), data încasării, brut, comision,
     * net, rambursări, restul la sosire. Comisionul vine din balance_transaction (citit o dată și păstrat în
     * `plati.comision`). Perioada: `luna=AAAA-LL` sau `de` și `pana` (AAAA-LL-ZZ, ziua încasării în ora României).
     * Separatorul „;” și virgula zecimală (Excel în română), cu BOM UTF-8.
     */
    public static function export(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $u = $app->sesiuneAdmin()->utilizator($c->cookie(SesiuneAdmin::COOKIE));
        if ($u === null || $u['rol'] !== 'proprietar') {
            return Raspuns::redirectare($app->url()->cale('/admin/'));
        }
        $t = $app->traducator((string) $u['limba_panou'], 'admin');
        [$de, $pana] = self::perioada($app, $c);
        $randuri = $app->reconciliere()->randuri($de, $pana);
        $csv = [[$t->t('admin.csv.ref'), $t->t('admin.csv.rezervare'), $t->t('admin.csv.plata'), $t->t('admin.csv.data'), $t->t('admin.csv.brut'), $t->t('admin.csv.comision'),
            $t->t('admin.csv.net'), $t->t('admin.csv.rambursari'), $t->t('admin.csv.rest')]];
        foreach ($randuri as $x) {
            $csv[] = [$x['ref'], $x['rezervare'], $x['pi'], $x['data'], self::lei($x['brut']), $x['comision'] === null ? '' : self::lei($x['comision']),
                $x['comision'] === null ? '' : self::lei($x['brut'] - $x['comision']), self::lei($x['rambursari']), self::lei($x['rest'])];
        }
        return self::fisierCsv($csv, 'reconciliere-' . $de . '-' . $pana . '.csv');
    }

    /**
     * Avansurile încasate pentru sejururi viitoare (Ord. 948/2026): pagina și, cu `format=csv`, fișierul. Le văd
     * proprietarul și angajatul cu dreptul „Poate face rambursări”, ca lista plăților.
     */
    public static function avansuri(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'rambursari');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $lista = $app->reconciliere()->avansuri();
        if ($c->param('format') === 'csv') {
            $csv = [[$t->t('admin.csv.ref'), $t->t('admin.csv.sosire'), $t->t('admin.csv.plecare'), $t->t('admin.csv.oaspete'), $t->t('admin.csv.brut'), $t->t('admin.csv.rambursari'),
                $t->t('admin.csv.avans_net')]];
            foreach ($lista['randuri'] as $x) {
                $csv[] = [$x['ref'], $x['sosire'], $x['plecare'], $x['oaspete'], self::lei($x['incasat']), self::lei($x['rambursat']), self::lei($x['net'])];
            }
            return self::fisierCsv($csv, 'avansuri-' . $app->azi() . '.csv');
        }
        $randuri = [];
        foreach ($lista['randuri'] as $x) {
            $randuri[] = ['ref' => $x['ref'], 'sejur' => Format::zi($x['sosire'], $l) . ' - ' . Format::zi($x['plecare'], $l), 'oaspete' => $x['oaspete'],
                'incasat' => Format::bani($x['incasat'], $l), 'rambursat' => Format::bani($x['rambursat'], $l), 'net' => Format::bani($x['net'], $l),
                'url' => $app->url()->cale('/admin/rezervari/' . $x['ref'])];
        }
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'avansuri');
        $d += ['randuri' => $randuri, 'total' => Format::bani($lista['total'], $l), 'url' => ['csv' => $app->url()->cale('/admin/plati/avansuri', null, ['format' => 'csv'])] + AdminLista::urlVederi($app, $u), 'raport' => AdminLista::raport($app, $u)];
        return AdminPanou::pagina($app, $u, 'avansuri', $d, $r);
    }

    /**
     * Suma scrisă de gazdă în lei („1.234,56”, „1234,56”, „1234.56”, „1234”), în bani; null la o formă necunoscută.
     */
    public static function bani(string $s): ?int
    {
        $s = str_replace([' ', "\u{00A0}", 'lei', 'RON'], '', trim($s));
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } elseif (preg_match('~^\d{1,3}(\.\d{3})+$~', $s)) {
            $s = str_replace('.', '', $s);
        }
        if (!preg_match('~^(\d{1,9})(?:\.(\d{1,2}))?$~', $s, $m)) {
            return null;
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /** Suma în lei pentru CSV: virgula zecimală, fără separatorul miilor. */
    private static function lei(int $bani): string
    {
        return ($bani < 0 ? '-' : '') . intdiv(abs($bani), 100) . ',' . str_pad((string) (abs($bani) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return array{0:string,1:string} ziua de început și de sfârșit, incluse; implicit luna curentă */
    public static function perioada(Aplicatie $app, Cerere $c): array
    {
        $luna = (string) ($c->param('luna') ?? '');
        $de = (string) ($c->param('de') ?? '');
        $pana = (string) ($c->param('pana') ?? '');
        $zi = '~^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$~';
        if (preg_match($zi, $de) && preg_match($zi, $pana) && $de <= $pana) {
            return [$de, $pana];
        }
        if (!preg_match('~^\d{4}-(0[1-9]|1[0-2])$~', $luna)) {
            $luna = substr($app->azi(), 0, 7);
        }
        return [$luna . '-01', gmdate('Y-m-t', (int) strtotime($luna . '-01 00:00:00 UTC'))];
    }

    /**
     * O celulă CSV fără formule (OWASP, CSV Injection): o valoare care începe, și după spațiile de început, cu =, +, -,
     * @, tab sau CR primește un apostrof în față, ca foaia de calcul să o arate ca text.
     */
    public static function celula(mixed $v): string
    {
        $v = (string) $v;
        return preg_match('~^[\s]*[=+\-@\t\r]~', $v) ? "'" . $v : $v;
    }

    private static function fisierCsv(array $randuri, string $nume): Raspuns
    {
        $f = fopen('php://memory', 'r+');
        foreach ($randuri as $r) {
            fputcsv($f, array_map([self::class, 'celula'], $r), ';', '"', '');
        }
        rewind($f);
        $r = new Raspuns(200, "\u{FEFF}" . stream_get_contents($f));
        $r->antet('Content-Type', 'text/csv; charset=utf-8');
        $r->antet('Content-Disposition', 'attachment; filename="' . $nume . '"');
        return $r;
    }

    /**
     * cartea despăgubirii pe pagina rezervării, după anularea gazdei cu clauza din contract: de plătit (cu
     * butoanele, pentru cine are dreptul de rambursare), plătită sau lăsată deoparte pentru forța majoră; null altfel.
     */
    private static function despagubire(Traducator $t, string $l, array $cm, bool $poate): ?array
    {
        $suma = (int) ($cm['despagubire'] ?? 0);
        if ($suma <= 0) {
            return null;
        }
        $p = ['avans' => Format::bani($suma, $l), 'ref' => (string) $cm['ref']];
        if ((int) $cm['forta_majora'] === 1) {
            return ['text' => $t->t('admin.rez.despagubire_forta_majora', $p), 'buton' => false];
        }
        if ($cm['despagubire_platita_la'] !== null) {
            return ['text' => $t->t('admin.rez.despagubire_platita', $p + ['data' => Format::moment((string) $cm['despagubire_platita_la'], $l)]), 'buton' => false];
        }
        return ['text' => $t->t('admin.rez.despagubire_de_platit', $p), 'buton' => $poate, 'forta_majora' => ($cm['anulare_gazda'] ?? null) === Anulare::GAZDA_DESPAGUBIRE];
    }

    /** Regula de anulare înghețată la comandă, pentru gazdă. */
    private static function regula(Traducator $t, string $l, array $cm, string $mutare = Anulare::MUTARE_RECALCULAT): string
    {
        if ($cm['fel'] === 'transfer') {
            // Cât plata e așteptată (sau a rămas neprimită), textul spune că banii încă nu au intrat.
            $r = $t->t($cm['platita_la'] !== null ? 'admin.rez.regula_transfer' : 'admin.rez.regula_transfer_asteptat');
            // taxa locală din suma transferului se returnează la orice anulare (sejurul anulat nu o datorează).
            $taxa = Anulare::taxaOnline($cm, (int) $cm['suma_acum']);
            return $taxa > 0 ? $r . ' ' . $t->t('admin.rez.regula_transfer_taxa', ['taxa' => Format::bani($taxa, $l)]) : $r;
        }
        if ($cm['fel'] !== 'card') {
            return $t->t('admin.rez.regula_cash');
        }
        if ((int) ($cm['nerambursabil'] ?? 0) === 1) {
            // taxa locală plătită online se returnează și la tariful nerambursabil.
            return $t->t(Anulare::taxaOnline($cm, (int) $cm['suma_acum']) > 0 ? 'admin.rez.regula_nerambursabil_taxa' : 'admin.rez.regula_nerambursabil');
        }
        if ((int) ($cm['nerambursabil'] ?? 0) === Anulare::REGULA_NECUNOSCUTA) {
            return $t->t('admin.rez.regula_necunoscuta');
        }
        // Regula din ziua rezervării (termenul inițial, chiar dacă „Renunțați” l-a mutat la ziua sosirii).
        $limita = $cm['anulare_limita_initiala'] ?? $cm['anulare_limita'];
        if ($limita === null) {
            return $t->t('admin.rez.regula_fara_politica');
        }
        // după termen se reține avansul plătit; cu taxa locală plătită online, restul se returnează cu taxa, iar avansul
        // nu trece de prețul cazării (la avansul 100%, taxa se returnează).
        $avans = Anulare::retinereDupaTermen($cm, $mutare);
        // când oaspetele a plătit cel mult cât se reține (de exemplu doar avansul), nu e un „avans”.
        $platit = (int) ($cm['suma_acum'] ?? 0);
        $cazare = (int) $cm['total'] - Anulare::taxaOnline($cm, (int) $cm['total']);
        $r = Anulare::taxaOnline($cm, $platit) > 0 && $avans >= $cazare
            ? $t->t('admin.rez.regula_tot_taxa', ['data' => Format::zi((string) $limita, $l)])
            : (Anulare::taxaOnline($cm, $platit) > 0 && $avans < $platit
            ? $t->t('admin.rez.regula_taxa', ['data' => Format::zi((string) $limita, $l), 'avans' => Format::bani($avans, $l)])
            : ($platit > 0 && $avans >= $platit
            ? $t->t('admin.rez.regula_platit', ['data' => Format::zi((string) $limita, $l), 'suma' => Format::bani($platit, $l)])
            : $t->t('admin.rez.regula', ['data' => Format::zi((string) $limita, $l), 'avans' => Format::bani($avans, $l)])));
        return $cm['anulare_limita_initiala'] !== null ? $r . ' ' . $t->t('admin.rez.regula_prelungita', ['data' => Format::zi((string) $cm['anulare_limita'], $l)]) : $r;
    }

    /** Trecerile făcute de un utilizator al panoului, spuse cu numele lui: „din>în” => cheia textului. */
    private const JURNAL_ADMIN = ['cerere_in_asteptare>confirmata' => 'acceptat', 'cerere_in_asteptare>cerere_refuzata' => 'refuzat', 'anulare_ceruta>anulata_oaspete' => 'anulare_acceptata',
        'anulare_ceruta>anulata_gazda' => 'anulat', 'confirmata>anulata_gazda' => 'anulat', 'anulare_de_lamurit>anulata_gazda' => 'lamurit', 'platita>inchisa_gazda' => 'inchis',
        'de_rambursat>inchisa_gazda' => 'inchis'];

    /**
     * Jurnalul rezervării, în două forme: întâmplările pe înțelesul gazdei (stările traduse, autorii numiți,
     * e-mailurile numite după mesajul lor) și rândurile brute, cu stările interne și JSON-ul, pentru „Detalii pentru
     * suport”. Pașii pur tehnici (reîncercările unei schimbări de date, sesiunile de plată) apar doar în forma brută.
     * @return array{intamplari: list<array{la:string, text:string}>, brut: list<array{la:string, din:string, in:string, actor:string, detalii:string}>}
     */
    private static function jurnal(Aplicatie $app, Traducator $t, array $cm, string $l): array
    {
        $id = (int) $cm['id'];
        // Suma transferului bancar, pentru întâmplările lui; la celelalte comenzi rămâne goală.
        $transfer = $cm['fel'] === 'transfer' ? Format::bani((int) $cm['suma_acum'], $l) : '';
        $adrese = [];
        foreach ($app->baza()->toate('SELECT id, email FROM {utilizatori}') as $x) {
            $adrese[(int) $x['id']] = (string) $x['email'];
        }
        $intamplari = [];
        $brut = [];
        $istoric = $app->masinaStari()->istoric($id);
        // suma doar rezervată pe card se spune altfel decât plata încasată pe loc.
        $retinere = $cm['fel'] === 'card' && ($app->plataOnline()->cuRetinere($id)
            || array_filter($istoric, static fn (array $x): bool => in_array((string) $x['in_stare'], ['de_capturat', 'autorizare_anulata'], true)) !== []);
        foreach ($istoric as $x) {
            $la = Format::moment((string) $x['la'], $l);
            $brut[] = ['la' => $la, 'din' => (string) ($x['din_stare'] ?? ''), 'in' => (string) $x['in_stare'], 'actor' => (string) $x['actor'], 'detalii' => (string) ($x['detalii'] ?? '')];
            $actor = (string) $x['actor'];
            $admin = (bool) preg_match('~^admin:(\d+)$~', $actor, $m);
            $cine = $admin ? ($adrese[(int) $m[1]] ?? $t->t('admin.rez.jurnal.cine.sters')) : '';
            $text = self::intamplare($t, $x['din_stare'] !== null ? (string) $x['din_stare'] : null, (string) $x['in_stare'], $actor, $admin, $cine,
                (array) (json_decode((string) ($x['detalii'] ?? ''), true) ?: []), $l, $transfer, (int) $cm['proba'] === 1, $retinere);
            if ($text !== null) {
                $intamplari[] = ['la' => $la, 'text' => $text];
            }
        }
        return ['intamplari' => $intamplari, 'brut' => $brut];
    }

    /**
     * O întâmplare din jurnal, în cuvinte; null pentru un pas pur tehnic. $transfer: suma transferului bancar, la
     * comenzile cu transfer (întâmplările lui sunt propoziții întregi, cu suma), altfel ''. $proba: comanda e de
     * probă, deci plata ei s-a hotărât cu butoanele de probă, nu în EasyCheck (decizia ajunge uneori întâi prin anunțul
     * EasyCheck, fără numele celui care a apăsat butonul). $retinere: plata cu cardul a fost doar rezervată pe card, apoi
     * încasată la confirmare, deci „platita” înseamnă „banca a rezervat suma”.
     */
    private static function intamplare(Traducator $t, ?string $din, string $in, string $actor, bool $admin, string $cine, array $det, string $l, string $transfer = '', bool $proba = false, bool $retinere = false): ?string
    {
        $j = static fn (string $cheie, array $param = []): string => $t->t('admin.rez.jurnal.' . $cheie, $param);
        $suma = is_int($det['suma'] ?? null) ? Format::bani($det['suma'], $l) : '';
        if ($din === null) {
            return $in === 'noua' ? $j('inceput') : null;
        }
        // Gazda a renunțat la o anulare refuzată de EasyCheck sau a păstrat rezervarea la cererea de anulare a oaspetelui.
        if (($det['anulare'] ?? null) === 'renuntata') {
            return $j('admin.renuntat', ['cine' => $cine]);
        }
        if (($det['anulare'] ?? null) === 'pastrata') {
            return $j('admin.pastrat', ['cine' => $cine]);
        }
        if ($din !== $in) {
            // Transferul bancar: rezervarea așteaptă transferul, plata confirmată (de probă: de cine), neprimită, termenul trecut.
            if ($transfer !== '' && ($in === \Cazo\Rezervare\Transfer::ASTEPTARE || $din === \Cazo\Rezervare\Transfer::ASTEPTARE)) {
                $cheie = match (true) {
                    $in === \Cazo\Rezervare\Transfer::ASTEPTARE => 'transfer.asteapta',
                    $in === 'confirmata' => $admin ? 'transfer.confirmat_proba' : ($proba ? 'transfer.confirmat_proba_anunt' : 'transfer.confirmat'),
                    $in === \Cazo\Rezervare\Transfer::NEPRIMIT => $admin ? 'transfer.neprimit_proba' : ($proba ? 'transfer.neprimit_proba_anunt' : 'transfer.neprimit'),
                    $in === \Cazo\Rezervare\Transfer::EXPIRAT => 'transfer.expirat',
                    default => null,
                };
                if ($cheie !== null) {
                    return $j($cheie, ['suma' => $transfer, 'cine' => $cine]);
                }
            }
            if ($admin && isset(self::JURNAL_ADMIN[$din . '>' . $in])) {
                return $j('admin.' . self::JURNAL_ADMIN[$din . '>' . $in], ['cine' => $cine]);
            }
            // autorizarea, apoi încasarea la confirmare, spuse în ordinea în care s-au întâmplat.
            if ($in === 'platita' && $retinere) {
                return $j('in.platita_retinere');
            }
            if ($din === 'de_capturat' && $in === 'confirmata') {
                return $j('in.confirmata_capturata');
            }
            return $t->are('admin.rez.jurnal.in.' . $in) ? $j('in.' . $in) : AdminLista::textStare($t, $in);
        }
        // E-mailurile către oaspete: fiecare mesaj își are propoziția lui („I-am trimis oaspetelui e-mailul de
        // confirmare a rezervării.”), nu numele intern al șablonului; un mesaj fără propoziție rămâne „un e-mail despre rezervare”.
        if (is_string($det['email'] ?? null)) {
            return $t->are('admin.rez.jurnal.email.' . $det['email']) ? $j('email.' . $det['email']) : $j('email_alt');
        }
        // Termenul de plată al transferului, mutat de gazdă în EasyCheck.
        if ($transfer !== '' && $in === \Cazo\Rezervare\Transfer::ASTEPTARE && is_string($det['termen'] ?? null)) {
            // prelungit din panoul site-ului (modul fără cheie), cu numele celui care a apăsat.
            return $j($admin ? 'transfer.termen_admin' : 'transfer.termen', ['termen' => Format::moment($det['termen'], $l), 'cine' => $cine]);
        }
        // „Am plătit” de la oaspete și drumul anunțului (EasyCheck sau site-ul, a doua notificare).
        if ($transfer !== '' && is_string($det['transfer'] ?? null)) {
            return match ($det['transfer']) {
                'anuntat' => $j('transfer.anuntat', ['suma' => $transfer]),
                'anunt_easycheck' => $j('transfer.anunt_easycheck'),
                'anunt_site' => $j('transfer.anunt_site'),
                'anunt_reamintit' => $j('transfer.anunt_reamintit'),
                default => null,
            };
        }
        // pașii comenzilor primite din EasyCheck și cererea de anulare trimisă acolo.
        if (is_string($det['pas'] ?? null) && isset($det['comanda_ec'])) {
            $k = 'ec.' . $det['pas'];
            return $t->are('admin.rez.jurnal.' . $k) ? $j($k, ['suma' => $suma, 'cod' => is_string($det['cod'] ?? null) ? $det['cod'] : '']) : null;
        }
        if (($det['cerere_anulare'] ?? null) === 'trimisa_ec') {
            return $j('ec.cerere_trimisa');
        }
        if (isset($det['schimbare'])) {
            return match ($det['schimbare']) {
                'pornita' => $j('schimbare_pornita'),
                'aplicata' => $j('schimbare_aplicata'),
                default => null,
            };
        }
        return match (true) {
            ($det['cod'] ?? null) === 'confirmat' => $j('cod_confirmat'),
            ($det['noutati'] ?? null) === 'retras' => $j('noutati_retras'),
            ($det['noutati'] ?? null) === 'retras_gazda' => $j('noutati_retras_gazda'),
            ($det['rambursare'] ?? null) === 'panou' => $j('rambursare_panou', ['cine' => $cine, 'suma' => $suma]),
            ($det['rambursare'] ?? null) === 'anulare' => $j('rambursare_anulare', ['suma' => $suma]),
            ($det['rambursare'] ?? null) === 'redeschisa' => $j('rambursare_redeschisa'),
            ($det['anulare'] ?? null) === 'pornita' => $admin ? $j('admin.anulare_pornita', ['cine' => $cine]) : $j($actor === 'oaspete' ? 'anulare_pornita_oaspete' : 'anulare_pornita'),
            ($det['anulare'] ?? null) === 'refuzata_ec' => $j('anulare_refuzata'),
            ($det['lamurire'] ?? null) === 'reamintire' => $j('lamurire_reamintire'),
            isset($det['reincercare_in']) => $j('reincercare'),
            default => null,
        };
    }

    private static function operatii(Aplicatie $app, Traducator $t, int $id, string $l): array
    {
        $r = [];
        foreach ($app->baza()->toate("SELECT * FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' ORDER BY id", [$id]) as $op) {
            // O operație nelămurită: textul cu îndrumarea (verificați în Stripe înainte de a returna), fără codul brut.
            $nelamurita = $op['stare'] === 'esuat' && $op['eroare'] === 'nelamurita';
            // Nota internă scrisă de gazdă la rambursare (criptată pe operație).
            $nota = '';
            if (($op['nota'] ?? null) !== null && $op['nota'] !== '') {
                try {
                    $nota = $app->comenzi()->decripteaza((string) $op['nota']);
                } catch (\Throwable) {
                    $nota = '';
                }
            }
            $r[] = ['la' => Format::moment((string) $op['la'], $l), 'suma' => Format::bani((int) $op['suma'], $l), 'stare' => $t->t($nelamurita ? 'admin.rez.op.nelamurita' : 'admin.rez.op.' . $op['stare']),
                'stare_clasa' => match ((string) $op['stare']) { 'reusit' => 'pa-stare-confirmata', 'esuat' => 'pa-stare-problema', default => 'pa-stare-asteapta' },
                'motiv' => $t->t('admin.rez.motiv.' . $op['motiv']), 'nota' => $nota, 'eroare' => $nelamurita ? '' : (string) ($op['eroare'] ?? '')];
        }
        return $r;
    }
}
