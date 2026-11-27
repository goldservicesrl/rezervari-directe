<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\EasyCheck\Client;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;
use Cazo\Pret\RegulaPlata;

defined('CAZO') or exit;

/**
 * După rezervare: anularea și rambursările unei rezervări confirmate.
 *
 *   deOaspete        „Rezervarea mea” (/r/{token}/anuleaza): în perioada de anulare gratuită (anulare_limita înghețată
 *                    în comandă) și la plata la sosire, anularea automată: POST /rezervari/{id}/anuleaza, apoi
 *                    rambursarea integrală a sumei încasate; după perioadă, cererea către gazdă
 *                    (`anulare_ceruta`)
 *   deciziaGazdei    panoul: cererea acceptată, cu suma aleasă de gazdă (de la zero la tot, cu reținerea după regula
 *                    EasyCheck ca propunere), sau păstrată (`anulare_ceruta` → `confirmata`)
 *   dinPanou         panoul: anularea gazdei (`confirmata` → `anulata_gazda`), cu suma aleasă
 *   rambursare       panoul: rambursarea totală sau parțială fără anulare (gestul gazdei), cu cheia formularului
 *   dinEasyCheck     anularea văzută în EasyCheck (anunțul rezervare.schimbata sau GET /rezervari): o anulare pornită
 *                    din Cazo (marcajul) se încheie cu suma ei; una a gazdei, cu bani încasați, duce la
 *                    `anulare_de_lamurit`, cu banii neatinși și alerta gazdei; fără bani (plata la sosire, netul
 *                    zero), direct la `anulata_gazda`
 *   lamureste        panoul: suma aleasă de gazdă pentru anularea din EasyCheck, de la zero la net
 *                    (`anulare_de_lamurit` → `anulata_gazda`), apoi rambursarea și e-mailul oaspetelui
 *   ruleaza          cronul: anulările de trimis din nou, rambursările anulărilor, e-mailurile rămase, rambursările
 *                    din panou rămase fără răspuns, reamintirea anulărilor de lămurit
 *
 * Marcajul (`anulare_actor`, `anulare_spre`, `anulare_suma`, `anulare_idem`) se scrie înaintea apelului spre
 * EasyCheck, sub zăvorul `comanda:<id>` (același cu anunțurile Stripe), doar pe o comandă fără marcaj. Rambursarea
 * unei anulări are o singură cheie pe comandă (`ref-<ref>-anulare`, apoi `-r1`.. `-r3`), oricâte căi ajung la ea.
 * Starea se schimbă abia când EasyCheck confirmă anularea (200, sau 409 stare_invalida cu rezervarea citită
 * `anulata`), prin mașina de stări (UPDATE ... WHERE stare = :veche, cu jurnal).
 */
final class Anulare
{
    /** Reluările unei anulări fără răspuns de la EasyCheck: 30 s, 2, 10, 30 de minute, apoi la 30 de minute. */
    public const REINCERCARI = [30, 120, 600, 1800];
    /** Cât ține „lacătul” unei trimiteri în curs: un proces mort lasă anularea cronului după atâtea secunde. */
    public const LACAT = 120;
    /** După atâtea secunde de la pornire fără confirmarea EasyCheck, gazda primește alerta (o dată). */
    public const ALERTA = 2 * 3600;
    /** Paginile GET /rezervari citite la o rulare a sincronizării; restul, de la cursor, la rularea următoare. */
    public const PAGINI = 20;
    public const JOB = 'rezervari:schimbate';
    /** Cursoarele listei incrementale GET /rezervari, în `ec_cursoare`. */
    public const CURSOR_DE_LA = 'rezervari_actualizate_de_la';
    public const CURSOR_DUPA = 'rezervari_dupa';
    /** Cea mai veche anulare ținută sub cursor, păstrată cât lista se citește pe mai multe rulări (cu `dupa`). */
    public const CURSOR_TINE = 'rezervari_tine';
    /**
     * Cât se citește înapoi față de cursor la lista incrementală: EasyCheck filtrează `actualizat_la` în ora României,
     * deci la sfârșitul orei de vară (ora 03:00-04:00 trăită de două ori) o schimbare făcută în a doua trecere are ora
     * locală mai mică decât cursorul. Procesarea e idempotentă: o rezervare citită de două ori nu face nimic a doua oară.
     */
    public const SUPRAPUNERE = 2 * 3600;
    /** Stările în care rezervarea din EasyCheck poate exista înainte ca site-ul să afle (comanda devine apoi `confirmata`). */
    public const INAINTE_DE_CONFIRMARE = ['platita', 'trimitere_ec', 'cerere_in_asteptare', 'de_capturat'];
    /** Marcajul `nerambursabil` = 2: comanda dintr-o versiune mai veche, fără regula de reținere cunoscută (migrarea 0013). */
    public const REGULA_NECUNOSCUTA = 2;
    /** O rambursare în procesare mai veche de atât aduce alerta gazdei (de obicei soldul Stripe). */
    public const PROCESARE_ALERTA = 48 * 3600;
    /** după prima alertă, câte o amintire la atâtea secunde, cât rambursarea rămâne în procesare. */
    public const PROCESARE_AMINTIRE = 86400;
    /** După reluările epuizate, rambursarea anulării se reverifică la atâtea secunde (lista din Stripe). */
    public const EPUIZATA_REIA = 6 * 3600;
    /** Motivele rambursării din panou (fără text liber în jurnal; nota opțională stă criptată pe operație). */
    public const MOTIVE_PANOU = ['cererea_oaspetelui', 'problema_cazare', 'gest_comercial', 'alt_motiv'];
    /** după o mutare pe alte date, la anularea după termen se păstrează avansul recalculat pe noul total (implicit) sau cel plătit la rezervare. */
    public const SETARE_MUTARE = 'anulare.avans_mutare';
    public const MUTARE_RECALCULAT = 'recalculat';
    public const MUTARE_INITIAL = 'initial';
    /**
     * ce primește oaspetele când gazda anulează rezervarea, alegerea gazdei (Setări › Metodele de plată sau
     * instalatorul), fără variantă implicită: până la alegere, site-ul nu primește rezervări (`configurareLipsa`). Se
     * îngheață pe comandă și se spune în informarea precontractuală și în contract: tot ce a plătit (`tot`); în plus, o
     * sumă egală cu avansul, cu excepția forței majore (`despagubire`); despăgubirea hotărâtă de la caz la caz, la
     * anulare (`caz`). Despăgubirea o plătește gazda din bancă.
     */
    public const SETARE_GAZDA = 'anulare.gazda';
    public const GAZDA_TOT = 'tot';
    public const GAZDA_DESPAGUBIRE = 'despagubire';
    public const GAZDA_CAZ = 'caz';
    public const GAZDA = [self::GAZDA_TOT, self::GAZDA_DESPAGUBIRE, self::GAZDA_CAZ];
    /** după atâtea secunde fără decizie, gazda primește o reamintire (o singură dată) pentru anularea de lămurit. */
    public const REAMINTIRE = 24 * 3600;
    /** Starea comenzii anulate de gazdă în EasyCheck, cu banii neatinși până la decizia ei. */
    public const DE_LAMURIT = 'anulare_de_lamurit';

    public function __construct(private Aplicatie $app)
    {
    }

    // ---- condițiile ----

    /**
     * Ce poate face oaspetele acum cu rezervarea lui, după regula înghețată la comandă:
     *   gratuita   anularea automată, cu rambursarea integrală (`suma`)
     *   cash       anularea automată, fără bani de mutat
     *   cerere     cererea către gazdă; `suma` = propunerea după regula EasyCheck, `retine` = ce rămâne la gazdă
     *   in_lucru   anularea a pornit (marcajul) și așteaptă confirmarea EasyCheck
     *   ceruta     cererea de anulare așteaptă decizia gazdei
     *   schimbare  o schimbare de date e în lucru (118-118d): anularea se oferă după încheierea ei
     *   null       nimic de făcut (altă stare, sau sejurul a început)
     * Anularea și cererea se oferă până în ziua sosirii inclusiv.
     * @return array{fel:?string, limita:?string, suma:int, retine:int, net:int, motiv:string}
     */
    public function conditii(array $c): array
    {
        $net = $this->net($c);
        $r = ['fel' => null, 'limita' => $c['anulare_limita'] ?? null, 'suma' => 0, 'retine' => 0, 'net' => $net, 'motiv' => ''];
        if ($c['anonimizat_la'] !== null) {
            return $r;
        }
        if ($c['anulare_actor'] !== null && $c['anulata_ec_la'] === null && in_array($c['stare'], ['confirmata', 'anulare_ceruta'], true)) {
            // După un refuz definitiv al EasyCheck, anularea o încheie gazda (sau renunță la ea, din panou).
            return ['fel' => self::refuzataDefinitiv($c) ? 'in_lucru_gazda' : 'in_lucru', 'suma' => (int) $c['anulare_suma']] + $r;
        }
        if ($c['stare'] === 'anulare_ceruta') {
            return ['fel' => 'ceruta'] + $this->propunere($c) + $r;
        }
        // O schimbare în lucru blochează anularea; cererea de mutare a unei rezervări cu plata la sosire, nu (varianta A).
        $sch = $c['stare'] === 'confirmata' ? $this->app->schimbareDate()->inLucru((int) $c['id']) : null;
        if ($sch !== null && $sch['stare'] !== 'cerere') {
            return ['fel' => 'schimbare'] + $r;
        }
        if ($c['stare'] !== 'confirmata' || $this->app->azi() > (string) $c['sosire']) {
            return $r;
        }
        if ($c['fel'] === 'cash') {
            return ['fel' => 'cash'] + $r;
        }
        // rezervarea plătită prin transfer bancar se anulează pe loc, fără rambursare automată: banii îi întoarce
        // gazda, din bancă, după politica de anulare a rezervării.
        if ($c['fel'] === 'transfer') {
            return ['fel' => 'transfer'] + $r;
        }
        $p = $this->propunere($c);
        // Plata deja returnată integral (net 0): nimic de reținut, deci anularea e gratuită și automată.
        // tariful nerambursabil se anulează direct, nu prin cererea către gazdă: nopțile se eliberează, banii
        // cazării rămân la gazdă, iar taxa locală plătită online se returnează.
        $fel = in_array($p['motiv'], ['gratuit', 'fara_politica', 'fara_plata'], true) ? 'gratuita' : ($p['motiv'] === 'nerambursabil' ? 'nerambursabil' : 'cerere');
        return ['fel' => $fel] + $p + $r;
    }

    /**
     * Propunerea de rambursare după regula EasyCheck, cu procentul, termenul și felul planului din ziua rezervării:
     * planul nerambursabil reține tot ce s-a plătit pentru cazare; până la termen (inclusiv ziua lui) se restituie tot;
     * după el se reține avansul (cel mult cât s-a plătit), iar restul se restituie; fără politică de anulare, se restituie
     * tot. după termen se reține avansul plătit, ca avans al cazării; tot ce s-a plătit peste el se returnează, cu
     * taxa locală (la avansul 100%, se reține prețul cazării).
     * Sumele sunt nete: încasat minus rambursările deja făcute sau în procesare.
     * @return array{suma:int, retine:int, net:int, motiv:string}
     */
    public function propunere(array $c): array
    {
        $net = $this->net($c);
        if ($c['fel'] !== 'card' || $net <= 0) {
            return ['suma' => 0, 'retine' => 0, 'net' => max(0, $net), 'motiv' => 'fara_plata'];
        }
        if ((int) ($c['nerambursabil'] ?? 0) === 1) {
            // sejurul care nu are loc nu datorează taxa locală: partea ei plătită online se returnează, iar partea
            // cazării rămâne la gazdă (rambursările de dinainte se socotesc întâi din taxă).
            $platit = (int) ($this->plata((int) $c['id'])['suma_incasata'] ?? 0);
            $retine = min($net, max(0, $platit - self::taxaOnline($c, $platit)));
            return ['suma' => $net - $retine, 'retine' => $retine, 'net' => $net, 'motiv' => 'nerambursabil'];
        }
        if ((int) ($c['nerambursabil'] ?? 0) === self::REGULA_NECUNOSCUTA) {
            // Comanda dintr-o versiune mai veche, fără regula din ziua rezervării (migrarea 0013): anularea vine drept cerere, iar suma
            // o hotărăște gazda; propunerea pornește de la zero.
            return ['suma' => 0, 'retine' => $net, 'net' => $net, 'motiv' => 'necunoscuta'];
        }
        if ($c['anulare_limita'] === null) {
            return ['suma' => $net, 'retine' => 0, 'net' => $net, 'motiv' => 'fara_politica'];
        }
        if ($this->app->azi() <= (string) $c['anulare_limita']) {
            return ['suma' => $net, 'retine' => 0, 'net' => $net, 'motiv' => 'gratuit'];
        }
        $retine = min($net, max(0, self::retinereDupaTermen($c, $this->avansMutare($c))));
        return ['suma' => $net - $retine, 'retine' => $retine, 'net' => $net, 'motiv' => 'dupa_termen'];
    }

    /**
     * Partea de taxă locală plătită online pe comandă, în bani: taxa locală din componentele totalului (de acum, deci
     * și după o schimbare de date), cel mult $platit, doar când comanda și-a ținut încasarea „online” (`taxa_incasare`).
     * La „la_sosire” și la comenzile de dinaintea alegerii (NULL, înainte de 17.11.2026): 0, regula de până atunci.
     */
    public static function taxaOnline(array $c, int $platit): int
    {
        if (($c['taxa_incasare'] ?? null) !== RegulaPlata::TAXA_ONLINE) {
            return 0;
        }
        $comp = json_decode((string) ($c['componente'] ?? ''), true);
        return max(0, min(RegulaPlata::taxa(is_array($comp) ? $comp : []), (int) $c['total'], $platit));
    }

    /**
     * Cât se reține după termenul de anulare gratuită, înainte de plafonul sumei plătite (revizuită 23.11.2026):
     * avansul ținut pe comandă (cel plătit la rezervare, cu avansul minim cât taxa locală; după o schimbare de date, cel
     * refăcut după același procent pe noul total, cel puțin avansul minim), ca avans al cazării. Cu taxa locală
     * plătită online, cel mult prețul cazării (totalul fără taxă): la avansul 100%, taxa se returnează.
     */
    public static function retinereDupaTermen(array $c, string $mutare = self::MUTARE_RECALCULAT): int
    {
        $total = (int) $c['total'];
        $avans = $c['avans'] !== null ? (int) $c['avans'] : RegulaPlata::avans($total, (int) ($c['avans_procent'] ?? 100));
        $taxa = self::taxaOnline($c, $total);
        if ($mutare === self::MUTARE_INITIAL && ($c['avans_initial'] ?? null) !== null) {
            // alegerea gazdei „avansul plătit la rezervare”: avansul din ziua rezervării, dar cel puțin avansul minim
            // al noului total (taxa locală plătită online; la leu, ca avansul ținut, când acesta e rotund).
            $minim = $taxa > 0 ? ($avans % 100 === 0 ? min($total, intdiv($taxa + 99, 100) * 100) : $taxa) : 0;
            $avans = max((int) $c['avans_initial'], $minim);
        }
        return $taxa > 0 ? min($avans, $total - $taxa) : min($avans, $total);
    }

    /**
     * ce avans se păstrează la o anulare după termen, după o mutare pe alte date: alegerea gazdei din ziua comenzii
     * (`comenzi.avans_mutare`, în contract), iar la comenzile de dinainte, alegerea de acum a site-ului (implicit, avansul
     * recalculat pe noul total).
     */
    public function avansMutare(array $c): string
    {
        $v = $c['avans_mutare'] ?? null;
        return in_array($v, [self::MUTARE_RECALCULAT, self::MUTARE_INITIAL], true) ? $v : self::mutareSite($this->app);
    }

    /** alegerea site-ului pentru anularea făcută de gazdă (Setări › Metodele de plată); null până la alegere. */
    public static function gazdaSite(Aplicatie $app): ?string
    {
        $v = $app->setari()->get(self::SETARE_GAZDA);
        return in_array($v, self::GAZDA, true) ? $v : null;
    }

    /**
     * la anularea gazdei, suma returnată e sub tot ce s-a plătit (netul), pe o comandă cu clauza anulării gazdei
     * în contract („vă returnăm tot ce ați plătit”): gazda poate returna mai puțin, dar doar după avertizare și confirmare
     * („Am înțeles și returnez mai puțin”). Comenzile fără clauză ținută (de dinainte) nu primesc avertizarea.
     */
    public static function returneazaMaiPutin(array $c, int $suma, int $net): bool
    {
        return ($c['anulare_gazda'] ?? null) !== null && $suma < $net;
    }

    /**
     * la anularea gazdei, ea hotărăște despăgubirea (contractul spune „de la caz la caz”): comanda plătită, cu
     * această clauză, fără decizia încă scrisă.
     */
    public static function despagubireDeHotarat(array $c): bool
    {
        return ($c['anulare_gazda'] ?? null) === self::GAZDA_CAZ && ($c['platita_la'] ?? null) !== null && ($c['despagubire_aleasa'] ?? null) === null;
    }

    /**
     * despăgubirea din contractul comenzii, la anularea gazdei: o sumă egală cu avansul spus în contract (cel din
     * ziua rezervării, ținut la o mutare în `avans_initial`). 0 la comenzile fără clauza despăgubirii.
     */
    public static function despagubireContract(array $c): int
    {
        if (($c['anulare_gazda'] ?? null) !== self::GAZDA_DESPAGUBIRE) {
            return 0;
        }
        return max(0, (int) ($c['avans_initial'] ?? $c['avans'] ?? 0));
    }

    /** Alegerea site-ului pentru reținerea după o mutare (Setări › Metodele de plată; implicit, avansul recalculat). */
    public static function mutareSite(Aplicatie $app): string
    {
        return $app->setari()->get(self::SETARE_MUTARE) === self::MUTARE_INITIAL ? self::MUTARE_INITIAL : self::MUTARE_RECALCULAT;
    }

    /**
     * Anularea marcată a fost pornită de oaspete în dreptul lui gratuit: la plata la sosire, sau cu cardul
     * într-o zi de până la termenul gratuit (ori fără termen). „Renunțați” îi păstrează atunci dreptul.
     * Tariful nerambursabil (și regula necunoscută, 0013) n-are perioadă gratuită: anularea lui a fost gratuită doar
     * fiindcă netul era zero, deci „Renunțați” nu îi dă oaspetelui un drept pe care tariful nu-l are.
     */
    public static function pastreazaGratuita(array $c): bool
    {
        if ($c['anulare_actor'] !== 'oaspete') {
            return false;
        }
        if ($c['fel'] === 'card' && (int) ($c['nerambursabil'] ?? 0) !== 0) {
            return false;
        }
        if ($c['fel'] !== 'card' || $c['anulare_limita'] === null) {
            return true;
        }
        $zi = (new \DateTimeImmutable((string) $c['anulare_pornita_la'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('Y-m-d');
        return $zi <= (string) ($c['anulare_limita_initiala'] ?? $c['anulare_limita']);
    }

    /** Anularea a primit un refuz definitiv de la EasyCheck: fără reluări automate, ieșirea e la gazdă. */
    public static function refuzataDefinitiv(array $c): bool
    {
        return $c['anulare_actor'] !== null && $c['anulata_ec_la'] === null && $c['anulare_eroare'] !== null && $c['anulare_urmatoarea'] === null;
    }

    /** Suma încasată și încă nerambursată (rambursările reușite, în procesare sau `cerut` scăzute), în bani. */
    public function net(array $c): int
    {
        if (($c['fel'] ?? 'cash') !== 'card') {
            return 0;
        }
        $p = $this->plata((int) $c['id']);
        return $p === null ? 0 : max(0, (int) $p['suma_incasata'] - $this->app->rambursari()->rambursatSauInCurs((int) $c['id']));
    }

    /**
     * banii comenzii de lămurit, pentru textele gazdei (alerta, reamintirea, panoul): încasat, rambursările deja
     * făcute (reușite sau în procesare), rambursarea încă nelămurită (`cerut`, răspunsul Stripe pierdut) și netul.
     * @return array{incasat:int, rambursat:int, cerut:int, net:int}
     */
    public function baniLamurire(array $c): array
    {
        $id = (int) $c['id'];
        $p = $this->plata($id);
        $r = $this->app->rambursari();
        return ['incasat' => $p === null ? 0 : (int) $p['suma_incasata'], 'rambursat' => $r->sumaPrefix($id, '', ['reusit', 'in_procesare']),
            'cerut' => $r->cerutDeschis($id) ? $r->sumaPrefix($id, '', ['cerut']) : 0, 'net' => $this->net($c)];
    }

    /**
     * Fraza cu banii comenzii de lămurit (baniLamurire): cu o rambursare nelămurită, doar plata și rambursarea în
     * așteptare (netul încă necunoscut); după rambursări anterioare, ce s-a returnat și restul; altfel suma plătită.
     * @param callable(int):string $bani
     */
    public static function textBaniLamurire(\Cazo\I18n\Traducator $t, array $b, callable $bani): string
    {
        if ($b['cerut'] > 0) {
            return $t->t('admin.rez.lamurire_bani_in_curs', ['incasat' => $bani($b['incasat']), 'cerut' => $bani($b['cerut'])]);
        }
        if ($b['rambursat'] > 0) {
            return $b['net'] > 0
                ? $t->t('admin.rez.lamurire_bani_rest', ['incasat' => $bani($b['incasat']), 'rambursat' => $bani($b['rambursat']), 'net' => $bani($b['net'])])
                : $t->t('admin.rez.lamurire_bani_returnata', ['incasat' => $bani($b['incasat'])]);
        }
        return $t->t('admin.rez.lamurire_bani', ['net' => $bani($b['net'])]);
    }

    /** Datele alertelor „Anulare de lămurit” (intrarea și reamintirea): banii comenzii, ca sume în bani. */
    private function dateAlertaLamurire(array $c): array
    {
        $b = $this->baniLamurire($c);
        return ['ref' => (string) $c['ref'], 'suma_bani' => $b['net'], 'incasat_bani' => $b['incasat'], 'rambursat_bani' => $b['rambursat'], 'cerut_bani' => $b['cerut'],
            'sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare']];
    }

    // ---- oaspetele ----

    /**
     * POST /r/{token}/anuleaza. $vazut = felul arătat oaspetelui pe pagină (gratuita, cerere, cash): dacă între timp
     * condițiile s-au schimbat (de exemplu termenul gratuit a trecut), nimic nu se face și pagina le arată din nou.
     * @return string anulata, in_lucru, ceruta, schimbat, stare, ocupat
     */
    public function deOaspete(int $id, string $vazut): string
    {
        return $this->subZavor($id, function () use ($id, $vazut): string {
            $c = $this->app->comenzi()->dupaId($id);
            if ($c === null) {
                return 'stare';
            }
            if (in_array($c['stare'], ['anulata_oaspete', 'anulata_gazda', self::DE_LAMURIT], true)) {
                // Anulată între timp (de gazdă în EasyCheck, sau de o apăsare de dinainte): pagina arată anularea.
                return 'anulata';
            }
            $k = $this->conditii($c);
            if (in_array($k['fel'], ['in_lucru', 'in_lucru_gazda', 'ceruta'], true)) {
                return $k['fel'] === 'ceruta' ? 'ceruta' : 'in_lucru';
            }
            if ($k['fel'] === 'schimbare') {
                return 'schimbare';
            }
            if ($k['fel'] === null) {
                return 'stare';
            }
            if ($k['fel'] !== $vazut) {
                return 'schimbat';
            }
            if ($k['fel'] === 'cerere') {
                if (!$this->app->masinaStari()->trece($id, 'card', 'confirmata', 'anulare_ceruta', 'oaspete', ['anulare_ceruta_la' => $this->app->ceas()->moment()],
                    ['anulare' => 'ceruta', 'propunere' => $k['suma'], 'retine' => $k['retine']])) {
                    return 'stare';
                }
                $this->anuntaOaspete($id, 'anulare_ceruta');
                // cu funcția etapei 3 aprinsă, cererea pleacă la EasyCheck, iar gazda o
                // hotărăște acolo (EasyCheck îi trimite e-mailul); altfel, ca până acum, e-mailul site-ului și panoul.
                if (!$this->app->modEc()->comenziAprinse() || !$this->app->hotarariEc()->trimiteCerere($id)) {
                    $this->anuntaGazda($id, 'gazda_anulare_ceruta', ['suma_bani' => $k['suma']]);
                }
                return 'ceruta';
            }
            if ($this->app->rambursari()->cerutDeschis($id)) {
                // O rambursare așteaptă răspunsul Stripe: suma anulării se știe abia după lămurirea ei.
                return 'in_curs';
            }
            // la tariful nerambursabil, suma propusă e partea de taxă locală plătită online (se returnează).
            if (!$this->marcheaza($c, 'oaspete', 'anulata_oaspete', in_array($k['fel'], ['gratuita', 'nerambursabil'], true) ? $k['suma'] : 0)) {
                return 'stare';
            }
            return $this->trimiteEc($id, 'oaspete');
        });
    }

    // ---- panoul ----

    /**
     * Decizia gazdei pe o cerere de anulare: `accepta` (anularea în EasyCheck și rambursarea sumei alese, de la zero
     * la tot ce s-a plătit) sau `pastreaza` (rezervarea rămâne confirmată, oaspetele primește e-mailul).
     * @return string anulata, in_lucru, pastrata, suma, stare, ocupat
     */
    public function deciziaGazdei(int $id, int $utilizator, string $decizie, int $suma): string
    {
        return $this->subZavor($id, function () use ($id, $utilizator, $decizie, $suma): string {
            $c = $this->app->comenzi()->dupaId($id);
            if ($c === null || $c['stare'] !== 'anulare_ceruta' || $c['anulare_actor'] !== null) {
                return 'stare';
            }
            if ($decizie === 'pastreaza') {
                if (!$this->app->masinaStari()->trece($id, 'card', 'anulare_ceruta', 'confirmata', 'admin:' . $utilizator, [], ['anulare' => 'pastrata'])) {
                    return 'stare';
                }
                $this->app->jurnal()->avertisment('Cerere de anulare păstrată de gazdă', ['ref' => $c['ref'], 'utilizator' => $utilizator]);
                $this->anuntaOaspete($id, 'anulare_pastrata');
                return 'pastrata';
            }
            if ($this->app->rambursari()->cerutDeschis($id)) {
                return 'in_curs';
            }
            if ($suma < 0 || $suma > $this->net($c)) {
                return 'suma';
            }
            if (!$this->marcheaza($c, 'admin:' . $utilizator, 'anulata_oaspete', $suma)) {
                return 'stare';
            }
            $this->app->jurnal()->avertisment('Cerere de anulare acceptată de gazdă', ['ref' => $c['ref'], 'utilizator' => $utilizator, 'suma' => $suma] + $this->propunere($c));
            return $this->trimiteEc($id, 'admin:' . $utilizator);
        });
    }

    /**
     * Anularea gazdei din panou (`confirmata` → `anulata_gazda`), cu suma aleasă în dialog (la plata la sosire, zero).
     * @return string anulata, in_lucru, suma, stare, ocupat, in_curs
     */
    public function dinPanou(int $id, int $utilizator, int $suma, bool $faraBani = false, bool $fortaMajora = false, ?int $despagubire = null, bool $maiPutin = false): string
    {
        return $this->subZavor($id, function () use ($id, $utilizator, $suma, $faraBani, $fortaMajora, $despagubire, $maiPutin): string {
            $c = $this->app->comenzi()->dupaId($id);
            if ($c === null || $c['stare'] !== 'confirmata' || $c['anulare_actor'] !== null) {
                return 'stare';
            }
            // „Anulați rezervarea” fără bani: doar la plata la sosire sau cu plata deja returnată integral.
            if ($faraBani && ($c['fel'] === 'card' && $this->net($c) > 0)) {
                return 'stare';
            }
            if ($this->app->rambursari()->cerutDeschis($id)) {
                return 'in_curs';
            }
            if ($suma < 0 || $suma > $this->net($c)) {
                return 'suma';
            }
            // contractul cu clauza anulării gazdei promite tot ce s-a plătit; mai puțin doar cu confirmarea gazdei.
            if (self::returneazaMaiPutin($c, $suma, $this->net($c)) && !$maiPutin) {
                return 'mai_putin';
            }
            // cu despăgubirea „de la caz la caz”, gazda o hotărăște la anulare (0 e o decizie).
            if (self::despagubireDeHotarat($c) && ($despagubire === null || $despagubire < 0)) {
                return 'despagubire';
            }
            if (!$this->marcheaza($c, 'admin:' . $utilizator, 'anulata_gazda', $suma)) {
                return 'stare';
            }
            // „Anulare din forță majoră”, bifată la anulare: fără despăgubire (scrisă înainte de încheierea anulării).
            if ($fortaMajora) {
                $this->notaFortaMajora($c, $utilizator);
            }
            if (self::despagubireDeHotarat($c)) {
                $this->notaDespagubireAleasa($c, $utilizator, (int) $despagubire);
            }
            $this->app->jurnal()->avertisment('Rezervare anulată de gazdă din panou', ['ref' => $c['ref'], 'utilizator' => $utilizator, 'suma' => $suma]);
            return $this->trimiteEc($id, 'admin:' . $utilizator);
        });
    }

    /**
     * Rambursarea din panou, fără anulare: totală sau parțială, cu motivul din listă și nota opțională
     * (criptată pe operație), cu cheia de idempotență a formularului (`ref-<ref>-panou-<jeton>`): o dublă apăsare
     * sau o reluare a aceluiași formular găsesc aceeași operație, deci o singură rambursare.
     * @return string reusit, in_procesare, cerut, esuat, suma, stare, ocupat
     */
    public function rambursare(int $id, int $utilizator, int $suma, string $motiv, string $nota, string $jeton): string
    {
        if (!preg_match('~^[A-Za-z0-9_-]{16,43}$~', $jeton) || !in_array($motiv, self::MOTIVE_PANOU, true)) {
            return 'stare';
        }
        return $this->subZavor($id, function () use ($id, $utilizator, $suma, $motiv, $nota, $jeton): string {
            $c = $this->app->comenzi()->dupaId($id);
            $p = $this->plata($id);
            if ($c === null || $c['fel'] !== 'card' || $p === null || !in_array($c['stare'], ['confirmata', 'anulata_oaspete', 'anulata_gazda'], true)
                || ($c['anulare_actor'] !== null && $c['anulata_ec_la'] === null)) {
                // O anulare în lucru își are suma ei: rambursarea din panou așteaptă încheierea ei.
                return 'stare';
            }
            $idem = 'ref-' . $c['ref'] . '-panou-' . $jeton;
            $op = $this->app->baza()->rand('SELECT * FROM {operatii_plata} WHERE idem = ?', [$idem]);
            if ($op === null && $this->app->rambursari()->cerutDeschis($id)) {
                // O rambursare cu răspunsul pierdut: întâi se lămurește (cronul o reia cu aceeași cheie).
                return 'in_curs';
            }
            if ($op === null && ($suma <= 0 || $suma > $this->net($c))) {
                return 'suma';
            }
            $nou = $op === null;
            $ramb = $this->app->rambursari();
            // Cu două plăți încasate (diferența unei schimbări de date), suma se poate împărți pe două operații (`-p2`).
            if ($nou) {
                $stare = $ramb->ramburseazaImpartit($id, (string) $p['stripe_pi'], $suma, 'gest_gazda', $idem, $utilizator);
            } else {
                $stare = $ramb->reia($op, (string) $p['stripe_pi']);
                $p2 = $this->app->baza()->rand('SELECT * FROM {operatii_plata} WHERE idem = ?', [$idem . '-p2']);
                if ($p2 !== null) {
                    $s2 = $ramb->reia($p2, (string) $p['stripe_pi']);
                    $stare = in_array($s2, ['cerut', 'esuat', 'in_procesare'], true) && $stare === 'reusit' ? $s2 : $stare;
                }
            }
            if ($nou) {
                if (trim($nota) !== '') {
                    $this->app->baza()->executa('UPDATE {operatii_plata} SET nota = ? WHERE idem = ?', [$this->app->comenzi()->cripteaza(mb_substr(trim($nota), 0, 500)), $idem]);
                }
                $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], 'admin:' . $utilizator, ['rambursare' => 'panou', 'suma' => $suma, 'motiv' => $motiv, 'stare' => $stare]);
                $this->app->jurnal()->avertisment('Rambursare din panou', ['ref' => $c['ref'], 'utilizator' => $utilizator, 'suma' => $suma, 'motiv' => $motiv, 'stare' => $stare]);
            }
            $this->anuntaRambursarile($id);
            // O rambursare din panou poate încheia o anulare (și una cu reluările epuizate): reverificarea pe loc.
            if (in_array($stare, ['reusit', 'in_procesare'], true)) {
                $this->app->baza()->executa('UPDATE {comenzi} SET anulare_rambursare_reia = NULL WHERE id = ? AND anulare_rambursata_la IS NULL', [$id]);
                $this->reverificaRambursarea($id);
            }
            return $stare;
        });
    }

    /**
     * Decizia gazdei pe o anulare făcută de ea în EasyCheck (`anulare_de_lamurit` → `anulata_gazda`): suma
     * returnată oaspetelui, de la zero la net (încasat minus rambursările reușite sau în curs). Rambursarea pleacă pe
     * calea anulărilor (cheia `ref-<ref>-anulare`, reluările, alertele, redeschiderea la eșec), apoi oaspetele primește
     * e-mailul anulării, cu suma.
     * @return string anulata, suma, stare, ocupat, in_curs
     */
    public function lamureste(int $id, int $utilizator, int $suma, bool $fortaMajora = false, ?int $despagubire = null, bool $maiPutin = false): string
    {
        return $this->subZavor($id, function () use ($id, $utilizator, $suma, $fortaMajora, $despagubire, $maiPutin): string {
            $c = $this->app->comenzi()->dupaId($id);
            if ($c === null || $c['stare'] !== self::DE_LAMURIT) {
                return 'stare';
            }
            if ($this->app->rambursari()->cerutDeschis($id)) {
                return 'in_curs';
            }
            $net = $this->net($c);
            if ($suma < 0 || $suma > $net) {
                return 'suma';
            }
            if (self::returneazaMaiPutin($c, $suma, $net) && !$maiPutin) {
                return 'mai_putin';
            }
            if (self::despagubireDeHotarat($c) && ($despagubire === null || $despagubire < 0)) {
                return 'despagubire';
            }
            $propunere = $this->propunere($c);
            if (!$this->app->masinaStari()->trece($id, 'card', self::DE_LAMURIT, 'anulata_gazda', 'admin:' . $utilizator,
                ['anulare_spre' => 'anulata_gazda', 'anulare_suma' => $suma, 'anulare_net' => $net, 'lamurire_reamintire_la' => null],
                ['anulare' => 'lamurita', 'suma' => $suma, 'net' => $net, 'propunere' => $propunere['suma'], 'motiv_propunere' => $propunere['motiv']])) {
                return 'stare';
            }
            $this->app->jurnal()->avertisment('Anularea din EasyCheck lămurită de gazdă', ['ref' => $c['ref'], 'utilizator' => $utilizator, 'suma' => $suma, 'net' => $net]);
            if ($fortaMajora) {
                $this->notaFortaMajora($this->app->comenzi()->dupaId($id) ?? $c, $utilizator);
            }
            if (self::despagubireDeHotarat($c)) {
                $this->notaDespagubireAleasa($this->app->comenzi()->dupaId($id) ?? $c, $utilizator, (int) $despagubire);
            }
            if ($suma > 0) {
                $this->ramburseaza($id);
            }
            $this->despagubire($id);
            $this->anunta($id);
            return 'anulata';
        });
    }

    /** Gazda cere din panou o nouă încercare a anulării în EasyCheck (după o eroare definitivă). */
    public function reincearca(int $id, int $utilizator): string
    {
        return $this->subZavor($id, function () use ($id, $utilizator): string {
            $c = $this->app->comenzi()->dupaId($id);
            if ($c === null || $c['anulare_actor'] === null || $c['anulata_ec_la'] !== null || !in_array($c['stare'], ['confirmata', 'anulare_ceruta'], true)) {
                return 'stare';
            }
            return $this->trimiteEc($id, 'admin:' . $utilizator);
        });
    }

    /**
     * Gazda renunță din panou la o anulare refuzată definitiv de EasyCheck: marcajul se șterge, rezervarea
     * rămâne confirmată (o cerere acceptată revine din `anulare_ceruta` în `confirmata`), banii rămân neatinși, iar
     * oaspetele primește e-mailul că rezervarea rămâne valabilă, cu contactul gazdei.
     * @return string renuntata, stare, ocupat
     */
    public function renunta(int $id, int $utilizator): string
    {
        return $this->subZavor($id, function () use ($id, $utilizator): string {
            $c = $this->app->comenzi()->dupaId($id);
            if ($c === null || !in_array($c['stare'], ['confirmata', 'anulare_ceruta'], true) || !self::refuzataDefinitiv($c)) {
                return 'stare';
            }
            $n = $this->app->baza()->executa('UPDATE {comenzi} SET anulare_actor = NULL, anulare_spre = NULL, anulare_suma = NULL, anulare_net = NULL, anulare_idem = NULL,
                anulare_pornita_la = NULL, anulare_incercari = 0, anulare_urmatoarea = NULL, anulare_eroare = NULL, actualizat_la = ? WHERE id = ? AND stare = ? AND anulata_ec_la IS NULL AND anulare_actor IS NOT NULL',
                [$this->app->ceas()->moment(), $id, $c['stare']])->rowCount();
            if ($n !== 1) {
                return 'stare';
            }
            // Anularea pornită de oaspete (doar în perioada gratuită sau la plata la sosire): dreptul lui
            // rămâne. Termenul gratuit se mută la ziua sosirii, ca oaspetele să poată anula din nou, gratuit și automat.
            // Doar o anulare pornită în termen (nu una gratuită fiindcă netul era zero după termen): altfel, o rambursare în
            // procesare care eșuează mai târziu i-ar da oaspetelui o anulare gratuită pe care n-o avea. Regula din ziua
            // rezervării rămâne în `anulare_limita_initiala`.
            $gratuita = self::pastreazaGratuita($c);
            if ($gratuita && $c['fel'] === 'card' && $c['anulare_limita'] !== null) {
                $this->app->baza()->executa('UPDATE {comenzi} SET anulare_limita_initiala = COALESCE(anulare_limita_initiala, anulare_limita), anulare_limita = ? WHERE id = ?',
                    [max((string) $c['anulare_limita'], (string) $c['sosire']), $id]);
            }
            $detalii = ['anulare' => 'renuntata', 'cod' => $c['anulare_eroare'], 'actor_anulare' => $c['anulare_actor'], 'gratuita_pastrata' => $gratuita];
            if ($c['stare'] === 'anulare_ceruta') {
                $this->app->masinaStari()->trece($id, 'card', 'anulare_ceruta', 'confirmata', 'admin:' . $utilizator, [], $detalii);
            } else {
                $this->app->masinaStari()->jurnal($id, 'confirmata', 'confirmata', 'admin:' . $utilizator, $detalii);
            }
            $this->app->jurnal()->avertisment('Anulare refuzată de EasyCheck, la care gazda a renunțat', ['ref' => $c['ref'], 'utilizator' => $utilizator, 'cod' => $c['anulare_eroare']]);
            $this->anuntaOaspete($id, $gratuita ? 'anulare_renuntata_gratuita' : 'anulare_renuntata');
            return 'renuntata';
        });
    }

    // ---- EasyCheck ----

    /**
     * Rezervarea comenzii apare `anulata` în EasyCheck. Cu marcajul (anularea pornită din Cazo), anularea se încheie
     * cu starea și suma ei; fără marcaj, a anulat gazda în EasyCheck: cu bani încasați (netul peste zero
     * sau o rambursare încă nelămurită), comanda trece în `anulare_de_lamurit`, banii rămân neatinși și gazda primește
     * alerta, oricând (și după sosire, și la tariful nerambursabil, și cu cererea oaspetelui în așteptare); fără bani
     * (plata la sosire, plata returnată integral), direct `anulata_gazda`, cu e-mailul oaspetelui.
     * Întoarce false când zăvorul comenzii e ocupat (rămâne pentru rularea următoare).
     */
    public function dinEasyCheck(int $id, string $actor): bool
    {
        $r = $this->app->zavor()->cuZavor('comanda:' . $id, 60, function () use ($id, $actor): bool {
            $c = $this->app->comenzi()->dupaId($id);
            // rezervarea anulată cât suma e doar rezervată pe card: comanda se refuză și suma se eliberează, fără
            // captură; cât o captură e în drum, anularea se reia.
            if ($c !== null && $c['stare'] === 'de_capturat') {
                return $this->app->retinere()->anulataInEc($id, $actor);
            }
            if ($c === null || !in_array($c['stare'], ['confirmata', 'anulare_ceruta'], true)) {
                return true;
            }
            if ($c['anulare_actor'] !== null) {
                $this->incheie($id, $actor, null);
                return true;
            }
            // Anularea rezervării vechi dintr-o schimbare de date (marcajul schimbării, 118d): pașii schimbării o încheie.
            if ($this->app->schimbareDate()->anulareProprie($c)) {
                $this->app->coada()->pune(SchimbareDate::JOB, [], SchimbareDate::JOB);
                return true;
            }
            $net = $this->net($c);
            if ($c['fel'] === 'card' && ($net > 0 || $this->app->rambursari()->cerutDeschis($id))) {
                $this->deLamurit($c, $actor, $net);
                return true;
            }
            $this->incheie($id, $actor, null, 'anulata_gazda', 0);
            return true;
        });
        return $r[0] === true && $r[1] === true;
    }

    /**
     * hotărârea gazdei luată în EasyCheck, citită de pe rezervare (`anulare` cu
     * `restituire`): comanda trece în `anulata_oaspete` (o cerere a oaspetelui acceptată) sau `anulata_gazda`, cu suma
     * hotărâtă, fără „Anulare de lămurit” și fără suma cerută în panou. Banii: la `cale = comanda` îi returnează executorul
     * comenzii (EasyCheck\Comenzi), cu cheia ei; la `in_afara_site` îi returnează gazda, iar la `fara` nu e nimic de
     * returnat, deci calea anulărilor nu ramburseazăm nimic. Despăgubirea și forța majoră se notează pe comandă, fără
     * alertele site-ului (le arată EasyCheck). Oaspetele primește e-mailul anulării cu suma, o singură dată. Sub zăvorul
     * comenzii. @return bool|null null = nu e o hotărâre de aplicat aici (rămâne calea de azi); false = zăvorul ocupat
     */
    public function hotarareEc(int $id, array $anulare): ?bool
    {
        $r = $this->app->zavor()->cuZavor('comanda:' . $id, 60, function () use ($id, $anulare): ?bool {
            $c = $this->app->comenzi()->dupaId($id);
            $rest = $anulare['restituire'] ?? null;
            if ($c === null || !is_array($rest) || !is_int($rest['suma'] ?? null) || !in_array($rest['cale'] ?? null, ['comanda', 'in_afara_site', 'fara'], true)) {
                return null;
            }
            $cmd = is_string($rest['comanda'] ?? null) && preg_match('~^cmd_[0-9A-Za-z]{24}$~D', $rest['comanda']) ? $rest['comanda'] : null;
            if (in_array($c['stare'], ['anulata_oaspete', 'anulata_gazda'], true)) {
                // „Încercați din nou” în EasyCheck: comanda cea nouă ține acum restituirea anulării.
                if (($c['ec_restituire_cale'] ?? null) === 'comanda' && $cmd !== null && $cmd !== $c['ec_restituire_comanda']) {
                    $this->app->baza()->executa('UPDATE {comenzi} SET ec_restituire_comanda = ?, actualizat_la = ? WHERE id = ?', [$cmd, $this->app->ceas()->moment(), $id]);
                }
                return true;
            }
            if (!in_array($c['stare'], ['confirmata', 'anulare_ceruta', self::DE_LAMURIT], true) || $c['anulare_actor'] !== null) {
                return null;
            }
            $spre = ($anulare['motiv'] ?? null) === 'cerere_oaspete' && $c['stare'] !== self::DE_LAMURIT ? 'anulata_oaspete' : 'anulata_gazda';
            if (!MasinaStari::permisa((string) $c['fel'], (string) $c['stare'], $spre)) {
                return null;
            }
            $net = $this->net($c);
            $suma = max(0, $c['fel'] === 'card' ? min((int) $rest['suma'], $net) : (int) $rest['suma']);
            $acum = $this->app->ceas()->moment();
            $desp = is_array($anulare['despagubire'] ?? null) && is_int($anulare['despagubire']['suma'] ?? null) && $anulare['despagubire']['suma'] >= 0 ? (int) $anulare['despagubire']['suma'] : null;
            $campuri = ['anulata_ec_la' => $c['anulata_ec_la'] ?? $acum, 'anulare_spre' => $spre, 'anulare_suma' => $suma, 'anulare_net' => $net, 'anulare_urmatoarea' => null,
                'anulare_eroare' => null, 'lamurire_reamintire_la' => null, 'ec_restituire_cale' => (string) $rest['cale'], 'ec_restituire_comanda' => $rest['cale'] === 'comanda' ? $cmd : null,
                'forta_majora' => ($anulare['forta_majora'] ?? false) === true ? 1 : 0];
            if ($desp !== null) {
                // Despăgubirea o plătește gazda din bancă și o notează în EasyCheck: pe site, doar ținută.
                $campuri['despagubire'] = $desp;
                $campuri['despagubire_aleasa'] = $desp;
            }
            if ($rest['cale'] !== 'comanda' || $suma === 0) {
                // Nimic de returnat de pe site: gazda returnează ea (în afara site-ului) sau suma e zero.
                $campuri['anulare_rambursata_la'] = $acum;
            }
            if (!$this->app->masinaStari()->trece($id, (string) $c['fel'], (string) $c['stare'], $spre, 'webhook_ec', $campuri,
                ['anulare' => 'hotarata_ec', 'motiv' => is_string($anulare['motiv'] ?? null) ? $anulare['motiv'] : null, 'suma' => $suma, 'cale' => $rest['cale'], 'comanda_ec' => $cmd])) {
                return null;
            }
            $this->app->jurnal()->avertisment('Anulare hotărâtă de gazdă în EasyCheck', ['ref' => $c['ref'], 'suma' => $suma, 'cale' => $rest['cale']]);
            $this->app->schimbareDate()->opresteCererea($this->app->comenzi()->dupaId($id), 'webhook_ec');
            $this->anunta($id);
            return true;
        });
        return $r[0] === true ? $r[1] : false;
    }

    /**
     * gazda a păstrat rezervarea în EasyCheck (`cerere_anulare.stare = respinsa`):
     * `anulare_ceruta` → `confirmata`, cu e-mailul oaspetelui. Sub zăvorul comenzii. @return bool|null ca hotarareEc
     */
    public function pastrataInEc(int $id): ?bool
    {
        $r = $this->app->zavor()->cuZavor('comanda:' . $id, 60, function () use ($id): ?bool {
            $c = $this->app->comenzi()->dupaId($id);
            if ($c === null || $c['stare'] !== 'anulare_ceruta' || $c['anulare_actor'] !== null) {
                return null;
            }
            if (!$this->app->masinaStari()->trece($id, (string) $c['fel'], 'anulare_ceruta', 'confirmata', 'webhook_ec', ['ec_cerere_stare' => 'respinsa'], ['anulare' => 'pastrata_ec'])) {
                return null;
            }
            $this->anuntaOaspete($id, 'anulare_pastrata');
            return true;
        });
        return $r[0] === true ? $r[1] : false;
    }

    /** anularea gazdei din EasyCheck așteaptă suma aleasă de ea; alerta (e-mail și panou) pleacă o singură dată. */
    private function deLamurit(array $c, string $actor, int $net): void
    {
        $id = (int) $c['id'];
        $acum = $this->app->ceas()->moment();
        if (!$this->app->masinaStari()->trece($id, 'card', (string) $c['stare'], self::DE_LAMURIT, $actor,
            ['anulata_ec_la' => $acum, 'lamurire_reamintire_la' => $this->app->ceas()->moment(self::REAMINTIRE)],
            ['anulare' => 'easycheck', 'lamurire' => 'asteapta_gazda', 'net' => $net])) {
            return;
        }
        $this->app->jurnal()->avertisment('Rezervare anulată de gazdă în EasyCheck, suma de ales în panou', ['ref' => $c['ref'], 'net' => $net]);
        // cu funcția etapei 3 aprinsă, gazda hotărăște suma în EasyCheck („Hotărâți ce
        // returnați”), deci site-ul nu-i mai trimite alerta lui; comanda rămâne „Anulare de lămurit”, fără butoane în panou.
        if (!$this->app->modEc()->deciziiInEasyCheck()) {
            $this->app->alertaGazda('alerta_anulare_gazda', $this->dateAlertaLamurire($c), 'anulare_de_lamurit:' . $c['ref'], 30 * 86400);
        }
    }

    /**
     * Lista incrementală GET /rezervari?actualizate_de_la=<cursor>, urmată cu `dupa` (la 15 minute și la anunțul
     * rezervare.schimbata). Fiecare rezervare `anulata` a unei comenzi încă vii trece prin dinEasyCheck. Cursorul
     * înaintează doar când toate comenzile găsite s-au putut procesa (un zăvor ocupat lasă lista pentru data viitoare),
     * cel mult până la o anulare a unei comenzi încă neconfirmate pe site (INAINTE_DE_CONFIRMARE), iar lista se cere cu
     * SUPRAPUNERE înapoi (ora de vară).
     * @return array{citite:int, anulate:int, ocupate:int}
     */
    public function sincronizeaza(string $actor = 'cron'): array
    {
        $rez = ['citite' => 0, 'anulate' => 0, 'ocupate' => 0];
        $b = $this->app->baza();
        // cu funcția etapei 3 aprinsă, și anulările fără hotărâre („Hotărâți ce returnați” în EasyCheck).
        $vii = $this->app->modEc()->comenziAprinse() ? "'confirmata', 'anulare_ceruta', 'de_capturat', 'anulare_de_lamurit'" : "'confirmata', 'anulare_ceruta', 'de_capturat'";
        $prima = $b->valoare("SELECT MIN(creat_la) FROM {comenzi} WHERE stare IN ($vii) AND ec_rezervare IS NOT NULL");
        if ($prima === null) {
            return $rez;
        }
        $deLa = $this->cursor(self::CURSOR_DE_LA);
        if (!is_string($deLa) || !preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$~', $deLa)) {
            $deLa = gmdate('Y-m-d\TH:i:s\Z', Ceas::dinMoment((string) $prima) - 60);
        }
        // Lista se cere cu SUPRAPUNERE înapoi (ora de vară); cursorul păstrat rămâne cea mai nouă schimbare citită.
        $q = ['actualizate_de_la' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($deLa) - self::SUPRAPUNERE)];
        $dupa = $this->cursor(self::CURSOR_DUPA);
        if (is_string($dupa) && $dupa !== '') {
            $q['dupa'] = $dupa;
        }
        $max = $deLa;
        // O rezervare anulată a unei comenzi care încă n-a aflat că e confirmată (plătită, în trimitere, cererea cash
        // acceptată în EasyCheck): cursorul rămâne sub ea, ca anularea să fie găsită după confirmare.
        $tine = isset($q['dupa']) ? $this->cursor(self::CURSOR_TINE) : null;
        for ($i = 0; $i < self::PAGINI; $i++) {
            try {
                $j = $this->app->ecClient()->citeste('/rezervari', $q)->json() ?? [];
            } catch (EroareApi $e) {
                // Un cursor respins (4xx) se șterge; lista se reia de la momentul păstrat.
                if (isset($q['dupa']) && $e->raspuns->status >= 400 && $e->raspuns->status < 500 && !in_array($e->raspuns->status, [408, 429], true)) {
                    $this->puneCursor(self::CURSOR_DUPA, null);
                }
                return $rez;
            } catch (EroareTransport) {
                return $rez;
            }
            foreach ((array) ($j['rezervari'] ?? []) as $r) {
                if (!is_array($r) || !is_string($r['id'] ?? null)) {
                    continue;
                }
                $rez['citite']++;
                if (is_string($r['actualizat_la'] ?? null) && preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$~', $r['actualizat_la']) && $r['actualizat_la'] > $max) {
                    $max = $r['actualizat_la'];
                }
                // sumele știute de EasyCheck (`plati`), comparate cu registrul site-ului.
                if (isset($r['plati'])) {
                    $this->app->raportPlati()->dinLista($r);
                }
                // hotărârile gazdei din EasyCheck (`anulare.restituire`, `cerere_anulare.stare`), cu funcția aprinsă.
                if (array_key_exists('anulare', $r) || array_key_exists('cerere_anulare', $r)) {
                    $h = $this->app->hotarariEc()->aplica($r);
                    if ($h !== null) {
                        $h ? $rez['anulate']++ : $rez['ocupate']++;
                        continue;
                    }
                }
                if (($r['stare'] ?? null) !== 'anulata') {
                    continue;
                }
                $c = $b->rand("SELECT id, ref FROM {comenzi} WHERE ec_rezervare = ? AND stare IN ('confirmata', 'anulare_ceruta', 'de_capturat')", [$r['id']]);
                if ($c === null && is_string($r['ref_site'] ?? null) && is_string($r['actualizat_la'] ?? null)
                    && $b->valoare('SELECT 1 FROM {comenzi} WHERE ref = ? AND stare IN (' . implode(', ', array_fill(0, count(self::INAINTE_DE_CONFIRMARE), '?')) . ')',
                        array_merge([$r['ref_site']], self::INAINTE_DE_CONFIRMARE)) !== null) {
                    $tine = $tine === null ? $r['actualizat_la'] : min($tine, $r['actualizat_la']);
                }
                if ($c === null || (($r['ref_site'] ?? null) !== null && $r['ref_site'] !== $c['ref'])) {
                    continue;
                }
                if ($this->dinEasyCheck((int) $c['id'], $actor)) {
                    $rez['anulate']++;
                } else {
                    $rez['ocupate']++;
                }
            }
            if (!is_string($j['dupa'] ?? null) || $j['dupa'] === '') {
                $this->puneCursor(self::CURSOR_DUPA, null);
                $this->puneCursor(self::CURSOR_TINE, null);
                if ($rez['ocupate'] === 0) {
                    $this->puneCursor(self::CURSOR_DE_LA, $tine !== null && preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$~', $tine) ? min($max, max($deLa, $tine)) : $max);
                }
                return $rez;
            }
            $q['dupa'] = $j['dupa'];
            if ($rez['ocupate'] === 0) {
                $this->puneCursor(self::CURSOR_DUPA, $j['dupa']);
                $this->puneCursor(self::CURSOR_TINE, $tine);
            }
        }
        return $rez;
    }

    /**
     * Comanda tocmai a devenit `confirmata` (plata trimisă, recuperarea, cererea cash acceptată): rezervarea se citește
     * o dată din EasyCheck, fiindcă gazda poate să o fi anulat înainte ca site-ul să afle de ea, iar lista incrementală
     * poate fi trecut deja de anulare. Anulată sau necitită: cursorul (doar unul existent) se întoarce înaintea comenzii, iar sincronizarea
     * pornește din coadă (`anulata`) sau la rularea următoare (necitită). Fără zăvor: doar citește și programează.
     */
    public function dupaConfirmare(int $id): void
    {
        $c = $this->app->comenzi()->dupaId($id);
        $rezEc = (string) ($c['ec_rezervare'] ?? '');
        if ($c === null || $c['stare'] !== 'confirmata' || !preg_match('~^rez_[0-9A-Za-z]{1,64}$~', $rezEc)) {
            return;
        }
        try {
            $stare = $this->app->ecClient()->citeste('/rezervari/' . $rezEc)->json()['stare'] ?? null;
        } catch (EroareApi|EroareTransport) {
            $stare = null;
        }
        if (is_string($stare) && $stare !== 'anulata') {
            return;
        }
        $inapoi = gmdate('Y-m-d\TH:i:s\Z', Ceas::dinMoment((string) ($c['trimisa_la'] ?? $c['creat_la'])) - 60);
        // Fără cursor (nicio sincronizare încă, de exemplu imediat după actualizarea din această versiune), prima sincronizare citește
        // deja de la cea mai veche rezervare vie: cursorul rămâne gol, ca anulările rezervărilor mai vechi să fie găsite.
        $cursor = $this->cursor(self::CURSOR_DE_LA);
        if (is_string($cursor) && preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$~', $cursor) && $cursor > $inapoi) {
            $this->puneCursor(self::CURSOR_DE_LA, $inapoi);
            $this->puneCursor(self::CURSOR_DUPA, null);
        }
        $this->app->masinaStari()->jurnal($id, 'confirmata', 'confirmata', 'sistem', ['anulare' => $stare === 'anulata' ? 'anulata_in_ec_la_confirmare' : 'verificare_amanata']);
        if ($stare === 'anulata') {
            $this->app->coada()->pune(self::JOB, [], self::JOB);
        }
    }

    // ---- cronul ----

    /**
     * Cronul: anulările de trimis din nou (reluările și procesele moarte), rambursările anulărilor, e-mailurile rămase
     * netrimise, rambursările din panou rămase `cerut` sau `in_procesare`, reamintirile anulărilor de lămurit.
     * @return array{trimise:int, rambursate:int, panou:int, reamintiri:int}
     */
    public function ruleaza(int $cate = 20): array
    {
        $b = $this->app->baza();
        $acum = $this->app->ceas()->moment();
        $rez = ['trimise' => 0, 'rambursate' => 0, 'panou' => 0];
        // Rotația: fiecare buclă ia întâi rândurile cu momentul cel mai vechi, iar fiecare rând lucrat își mută momentul
        // înainte, ca o comandă blocată să lase loc celor noi.
        foreach ($b->toate("SELECT id FROM {comenzi} WHERE stare IN ('confirmata', 'anulare_ceruta') AND anulare_actor IS NOT NULL AND anulata_ec_la IS NULL
            AND anulare_urmatoarea IS NOT NULL AND anulare_urmatoarea <= ? ORDER BY anulare_urmatoarea, id LIMIT " . $cate, [$acum]) as $r) {
            $this->subZavor((int) $r['id'], fn (): string => $this->trimiteEc((int) $r['id'], 'cron'));
            $rez['trimise']++;
        }
        foreach ($b->toate("SELECT id FROM {comenzi} WHERE fel = 'card' AND stare IN ('anulata_oaspete', 'anulata_gazda') AND anulare_suma IS NOT NULL AND anulare_rambursata_la IS NULL
            AND ec_restituire_cale IS NULL AND (anulare_rambursare_reia IS NULL OR anulare_rambursare_reia <= ?) ORDER BY anulare_rambursare_reia, id LIMIT " . $cate, [$acum]) as $r) {
            $this->subZavor((int) $r['id'], fn (): string => $this->ramburseaza((int) $r['id']));
            $rez['rambursate']++;
        }
        foreach ($b->toate("SELECT id FROM {comenzi} WHERE stare IN ('anulata_oaspete', 'anulata_gazda') AND anulata_ec_la IS NOT NULL AND email_anulare_la IS NULL LIMIT " . $cate) as $r) {
            $this->anunta((int) $r['id']);
        }
        foreach ($b->toate("SELECT comanda_id, MIN(COALESCE(urmatoarea_incercare, '')) AS m FROM {operatii_plata} WHERE tip = 'rambursare' AND idem LIKE 'ref-%-panou-%'
            AND ((stare IN ('cerut', 'in_procesare') AND (urmatoarea_incercare IS NULL OR urmatoarea_incercare <= ?)) OR (stare = 'reusit' AND anuntat_la IS NULL))
            GROUP BY comanda_id ORDER BY m, comanda_id LIMIT " . $cate, [$acum]) as $r) {
            $id = (int) $r['comanda_id'];
            $this->subZavor($id, function () use ($id): string {
                $p = $this->plata($id);
                foreach ($this->app->baza()->toate("SELECT * FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND idem LIKE 'ref-%-panou-%' AND stare IN ('cerut', 'in_procesare')", [$id]) as $op) {
                    if ($p !== null) {
                        $this->app->rambursari()->reia($op, (string) $p['stripe_pi']);
                    }
                }
                $this->anuntaRambursarile($id);
                return 'ok';
            });
            $rez['panou']++;
        }
        $rez['reamintiri'] = $this->reamintiri($cate);
        $this->lamuresteCerute();
        $this->alerteProcesare();
        return $rez;
    }

    /**
     * anulările de lămurit fără decizie după REAMINTIRE primesc o reamintire, o singură dată pe comandă
     * (`lamurire_reamintire_la` se golește înaintea alertei, deci două cronuri nu o trimit de două ori).
     */
    private function reamintiri(int $cate): int
    {
        $n = 0;
        foreach ($this->app->baza()->toate("SELECT id FROM {comenzi} WHERE stare = ? AND lamurire_reamintire_la IS NOT NULL AND lamurire_reamintire_la <= ?
            ORDER BY lamurire_reamintire_la, id LIMIT " . $cate, [self::DE_LAMURIT, $this->app->ceas()->moment()]) as $r) {
            $id = (int) $r['id'];
            if ($this->app->baza()->executa('UPDATE {comenzi} SET lamurire_reamintire_la = NULL WHERE id = ? AND stare = ? AND lamurire_reamintire_la IS NOT NULL',
                [$id, self::DE_LAMURIT])->rowCount() !== 1) {
                continue;
            }
            $c = $this->app->comenzi()->dupaId($id);
            $this->app->masinaStari()->jurnal($id, self::DE_LAMURIT, self::DE_LAMURIT, 'cron', ['lamurire' => 'reamintire']);
            $this->app->alertaGazda('alerta_anulare_gazda_reamintire', $this->dateAlertaLamurire($c), 'anulare_de_lamurit_reamintire:' . $c['ref'], 30 * 86400);
            $n++;
        }
        return $n;
    }

    /**
     * Operațiile de rambursare rămase `cerut` (răspunsul Stripe pierdut), pe orice comandă și în orice stare a ei
     * (Plati\Rambursari::lamureste): lista Stripe, apoi cheia veche doar în fereastra ei. O operație nelămurită după
     * fereastră devine `esuat`, iar gazda primește alerta, o dată pe operație. Cu rotația după momentul reluării.
     */
    public function lamuresteCerute(int $cate = 20): int
    {
        $n = 0;
        foreach ($this->app->baza()->toate("SELECT o.id, o.comanda_id FROM {operatii_plata} o WHERE o.tip = 'rambursare' AND o.stare = 'cerut'
            AND (o.urmatoarea_incercare IS NULL OR o.urmatoarea_incercare <= ?) ORDER BY COALESCE(o.urmatoarea_incercare, ''), o.id LIMIT " . $cate, [$this->app->ceas()->moment()]) as $r) {
            $id = (int) $r['comanda_id'];
            $this->subZavor($id, function () use ($id, $r): string {
                $op = $this->app->baza()->rand('SELECT * FROM {operatii_plata} WHERE id = ?', [$r['id']]);
                $p = $this->app->baza()->rand('SELECT stripe_pi FROM {plati} WHERE comanda_id = ? AND stripe_pi IS NOT NULL ORDER BY id DESC LIMIT 1', [$id]);
                if ($op === null || $p === null) {
                    return 'nimic';
                }
                $stare = $this->app->rambursari()->lamureste($op, (string) $p['stripe_pi']);
                $this->anuntaRambursarile($id);
                return $stare;
            });
            $n++;
        }
        // Operațiile rămase nelămurite după fereastra cheii (pe orice cale au ajuns acolo): alerta gazdei, o dată pe operație.
        foreach ($this->app->baza()->toate("SELECT o.id, o.suma, o.idem, c.ref FROM {operatii_plata} o JOIN {comenzi} c ON c.id = o.comanda_id
            WHERE o.tip = 'rambursare' AND o.stare = 'esuat' AND o.eroare = 'nelamurita' AND o.actualizat_la >= ? ORDER BY o.id DESC LIMIT 50", [$this->app->ceas()->moment(-7 * 86400)]) as $op) {
            if ($this->app->limitator()->numar('alerta:rambursare_nelamurita:' . $op['id'], 30 * 86400) === 0) {
                // Alerta proprie: rambursarea poate să fi ajuns la Stripe, deci gazda verifică întâi acolo (o rambursare repetată
                // ar returna de două ori).
                // La -auto, comanda e `de_rambursat`: panoul are acolo doar „Am rezolvat plata în contul Stripe”.
                $sablon = preg_match('~-auto(-[rs]\d+)?$~', (string) $op['idem']) ? 'alerta_rambursare_nelamurita_auto' : 'alerta_rambursare_nelamurita';
                $this->app->alertaGazda($sablon, ['ref' => (string) $op['ref'], 'suma_bani' => (int) $op['suma']], 'rambursare_nelamurita:' . $op['id'], 30 * 86400);
            }
        }
        return $n;
    }

    /**
     * După o rambursare aflată din Stripe (anunțul charge.refunded): rambursarea anulării se reverifică pe loc, ca o
     * rambursare din Dashboard să o încheie fără să aștepte cronul. Chemată sub zăvorul comenzii (anunțul îl ține).
     */
    public function reverificaRambursarea(int $id): void
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || $c['fel'] !== 'card' || !in_array($c['stare'], ['anulata_oaspete', 'anulata_gazda'], true) || $c['anulare_suma'] === null
            || ($c['ec_restituire_cale'] ?? null) !== null) {
            return;
        }
        if ($c['anulare_rambursata_la'] !== null && !$this->redeschide($c)) {
            return;
        }
        $this->ramburseaza($id);
    }

    /**
     * O rambursare a anulării încheiate, confirmată de Stripe, a eșuat după aceea (charge.refund.updated, succeeded →
     * failed): anularea se redeschide (`anulare_rambursata_la` golit), ca rambursarea să se reia cu regulile ei, iar
     * gazda primește alerta o singură dată. @return bool true când anularea s-a redeschis acum
     */
    private function redeschide(array $c): bool
    {
        $id = (int) $c['id'];
        $p = $this->plata($id);
        if ($p === null) {
            return false;
        }
        $ramb = $this->app->rambursari();
        $incasat = (int) $p['suma_incasata'];
        $ramane = $c['anulare_net'] !== null ? max(0, (int) $c['anulare_net'] - (int) $c['anulare_suma']) : $incasat - (int) $c['anulare_suma'];
        $lipsa = $incasat - $ramb->sumaPrefix($id, '', ['reusit']) - $ramane;
        if ($lipsa <= 0) {
            return false;
        }
        $n = $this->app->baza()->executa('UPDATE {comenzi} SET anulare_rambursata_la = NULL, anulare_rambursare_reia = NULL WHERE id = ? AND anulare_rambursata_la IS NOT NULL', [$id])->rowCount();
        if ($n !== 1) {
            return false;
        }
        $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], 'sistem', ['rambursare' => 'redeschisa', 'lipsa' => $lipsa]);
        $this->app->jurnal()->avertisment('Rambursarea unei anulări a eșuat după confirmare', ['ref' => $c['ref'], 'lipsa' => $lipsa]);
        if ($this->app->limitator()->numar('alerta:rambursare_intoarsa:' . $c['ref'], 30 * 86400) === 0) {
            $this->app->alertaGazda('alerta_rambursare_intoarsa', ['ref' => (string) $c['ref'], 'suma_bani' => $lipsa], 'rambursare_intoarsa:' . $c['ref'], 30 * 86400);
        }
        return true;
    }

    /** Ceva de făcut pentru cron (o interogare ieftină, ca sarcina să iasă repede pe un site liniștit). */
    public function areLucru(): bool
    {
        $b = $this->app->baza();
        $acum = $this->app->ceas()->moment();
        return $b->valoare("SELECT 1 FROM {comenzi} WHERE (stare IN ('confirmata', 'anulare_ceruta') AND anulare_actor IS NOT NULL AND anulata_ec_la IS NULL AND anulare_urmatoarea IS NOT NULL)
            OR (stare IN ('anulata_oaspete', 'anulata_gazda') AND fel = 'card' AND anulare_suma IS NOT NULL AND anulare_rambursata_la IS NULL AND ec_restituire_cale IS NULL
                AND (anulare_rambursare_reia IS NULL OR anulare_rambursare_reia <= ?))
            OR (stare IN ('anulata_oaspete', 'anulata_gazda') AND anulata_ec_la IS NOT NULL AND email_anulare_la IS NULL)
            OR (stare = 'anulare_de_lamurit' AND lamurire_reamintire_la IS NOT NULL AND lamurire_reamintire_la <= ?) LIMIT 1", [$acum, $acum]) !== null
            || $b->valoare("SELECT 1 FROM {operatii_plata} WHERE tip = 'rambursare' AND stare = 'cerut' AND (urmatoarea_incercare IS NULL OR urmatoarea_incercare <= ?) LIMIT 1", [$acum]) !== null
            || $b->valoare("SELECT 1 FROM {operatii_plata} WHERE tip = 'rambursare' AND idem LIKE 'ref-%-panou-%' AND ((stare IN ('cerut', 'in_procesare') AND (urmatoarea_incercare IS NULL OR urmatoarea_incercare <= ?))
                OR (stare = 'reusit' AND anuntat_la IS NULL)) LIMIT 1", [$acum]) !== null;
    }

    /**
     * Rambursările rămase în procesare peste PROCESARE_ALERTA (de obicei soldul insuficient în contul Stripe: refundul
     * stă `pending`): alerta gazdei la 48 de ore, apoi câte o amintire pe zi cât operația rămâne în procesare;
     * panoul le arată în lista de pe prima pagină (rambursariInProcesare). Pentru toate rambursările site-ului, pe orice
     * cale. Ritmul vine din `operatii_plata.alerta_procesare_la` (migrarea 0016): următoarea alertă pleacă la 24 de ore
     * după cea de dinainte, oricât de des rulează cronul, iar între alerte nimic nu se scrie în jurnal.
     */
    public function alerteProcesare(): void
    {
        // Rotația: cursorul `alerte_procesare.dupa` (ultimul id văzut), reluat de la început la capătul listei.
        $dupa = (int) ($this->app->setari()->get('alerte_procesare.dupa') ?? 0);
        $lista = $this->app->baza()->toate("SELECT o.id, o.suma, c.ref FROM {operatii_plata} o JOIN {comenzi} c ON c.id = o.comanda_id
            WHERE o.tip = 'rambursare' AND o.stare = 'in_procesare' AND o.la <= ? AND o.id > ? ORDER BY o.id LIMIT 20", [$this->app->ceas()->moment(-self::PROCESARE_ALERTA), $dupa]);
        $this->app->setari()->pune('alerte_procesare.dupa', count($lista) < 20 ? 0 : (int) $lista[count($lista) - 1]['id']);
        foreach ($lista as $op) {
            // Ritmul: 24 de ore de la alerta de dinainte a operației (`alerta_procesare_la`, scris atomic, deci o singură
            // trecere a cronului câștigă), apoi limitatorul ca a doua plasă; fără nicio scriere în jurnal între alerte.
            $acum = $this->app->ceas()->moment();
            $n = $this->app->baza()->executa("UPDATE {operatii_plata} SET alerta_procesare_la = ? WHERE id = ? AND stare = 'in_procesare'
                AND (alerta_procesare_la IS NULL OR alerta_procesare_la <= ?)", [$acum, $op['id'], $this->app->ceas()->moment(-self::PROCESARE_AMINTIRE)])->rowCount();
            if ($n === 1 && $this->app->limitator()->numar('alerta:procesare:' . $op['id'], self::PROCESARE_AMINTIRE) === 0) {
                $this->app->alertaGazda('alerta_rambursare_procesare', ['ref' => (string) $op['ref'], 'suma_bani' => (int) $op['suma']], 'procesare:' . $op['id'], self::PROCESARE_AMINTIRE);
            }
        }
    }

    /** comenzile cu o rambursare în procesare de peste 48 de ore, pentru lista din panou. @return list<string> */
    public function rambursariInProcesare(int $cate = 50): array
    {
        return array_column($this->app->baza()->toate("SELECT c.ref, MIN(o.la) AS m FROM {operatii_plata} o JOIN {comenzi} c ON c.id = o.comanda_id
            WHERE o.tip = 'rambursare' AND o.stare = 'in_procesare' AND o.la <= ? GROUP BY c.ref ORDER BY m, c.ref LIMIT " . $cate, [$this->app->ceas()->moment(-self::PROCESARE_ALERTA)]), 'ref');
    }

    /** Un cursor al listei incrementale, din `ec_cursoare`. */
    private function cursor(string $nume): ?string
    {
        $v = $this->app->baza()->valoare('SELECT valoare FROM {ec_cursoare} WHERE nume = ?', [$nume]);
        return is_string($v) && $v !== '' ? $v : null;
    }

    private function puneCursor(string $nume, ?string $valoare): void
    {
        if ($valoare === null) {
            $this->app->baza()->executa('DELETE FROM {ec_cursoare} WHERE nume = ?', [$nume]);
            return;
        }
        $this->app->baza()->upsert('ec_cursoare', ['nume' => $nume, 'valoare' => mb_substr($valoare, 0, 191)], ['nume'], ['valoare']);
    }

    // ---- miezul ----

    /** Marcajul anulării, înaintea apelului spre EasyCheck, doar pe o comandă fără marcaj și în starea citită. */
    private function marcheaza(array $c, string $actor, string $spre, int $suma): bool
    {
        if (!MasinaStari::permisa((string) $c['fel'], (string) $c['stare'], $spre)) {
            return false;
        }
        // O schimbare de date în lucru își are pașii ei în EasyCheck (118d): anularea așteaptă încheierea ei. Cererea de
        // mutare a unei rezervări cu plata la sosire rămâne până când anularea se încheie, apoi se oprește (varianta A).
        $sch = $this->app->schimbareDate()->inLucru((int) $c['id']);
        if ($sch !== null && $sch['stare'] !== 'cerere') {
            return false;
        }
        $acum = $this->app->ceas()->moment();
        $n = $this->app->baza()->executa('UPDATE {comenzi} SET anulare_actor = ?, anulare_spre = ?, anulare_suma = ?, anulare_net = ?, anulare_idem = ?, anulare_pornita_la = ?, anulare_incercari = 0,
            anulare_urmatoarea = ?, anulare_eroare = NULL, actualizat_la = ? WHERE id = ? AND stare = ? AND anulare_actor IS NULL',
            [$actor, $spre, $suma, $this->net($c), Client::uuid(), $acum, $this->app->ceas()->moment(self::LACAT), $acum, $c['id'], $c['stare']])->rowCount();
        if ($n !== 1) {
            return false;
        }
        $this->app->masinaStari()->jurnal((int) $c['id'], (string) $c['stare'], (string) $c['stare'], $actor, ['anulare' => 'pornita', 'spre' => $spre, 'suma' => $suma]);
        return true;
    }

    /**
     * POST /rezervari/{id}/anuleaza cu cheia salvată în marcaj. 200, sau 409 stare_invalida cu rezervarea citită
     * `anulata`: anularea se încheie. 429, 5xx, rețeaua, 409 în lucru: reluarea din cron, cu aceeași cheie. Celelalte
     * refuzuri: gazda primește alerta și anulează din EasyCheck (sincronizarea încheie apoi anularea).
     * Chemată sub zăvorul comenzii. @return string anulata, in_lucru
     */
    private function trimiteEc(int $id, string $actor): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || $c['anulare_actor'] === null || $c['anulata_ec_la'] !== null || !in_array($c['stare'], ['confirmata', 'anulare_ceruta'], true)) {
            return in_array($c['stare'] ?? '', ['anulata_oaspete', 'anulata_gazda'], true) ? 'anulata' : 'in_lucru';
        }
        $rezEc = (string) ($c['ec_rezervare'] ?? '');
        if (!preg_match('~^rez_[0-9A-Za-z]{1,64}$~', $rezEc)) {
            return $this->esec($c, 'fara_rezervare', null, $actor);
        }
        $this->app->baza()->executa('UPDATE {comenzi} SET anulare_incercari = anulare_incercari + 1, anulare_urmatoarea = ? WHERE id = ?', [$this->app->ceas()->moment(self::LACAT), $id]);
        try {
            $r = $this->app->ecClient()->scrie('POST', '/rezervari/' . $rezEc . '/anuleaza', null, (string) $c['anulare_idem']);
            return $this->incheie($id, $actor, $r->requestId());
        } catch (EroareApi $e) {
            $cod = $e->cod ?? 'http_' . $e->raspuns->status;
            if ($e->raspuns->status === 409 && $cod === 'stare_invalida') {
                // Deja anulată în EasyCheck: de gazdă, chiar acum, sau de o încercare a noastră cu răspunsul pierdut și
                // cheia expirată. Starea se citește înainte de încheiere.
                return $this->anulataInEc($rezEc) ? $this->incheie($id, $actor, $e->requestId) : $this->amana($id, $cod, $e->requestId, $actor);
            }
            $inLucru = $cod === 'idempotency_conflict' && (($e->detalii()['in_lucru'] ?? false) === true);
            if ($inLucru || in_array($e->comportament, ['reincearca', 'pauza'], true) || $e->raspuns->status >= 500 || $e->raspuns->status === 429) {
                return $this->amana($id, $cod, $e->requestId, $actor);
            }
            return $this->esec($c, $cod, $e->requestId, $actor);
        } catch (EroareTransport $e) {
            return $this->amana($id, $e->felul, null, $actor);
        }
    }

    private function anulataInEc(string $rezEc): bool
    {
        try {
            return ($this->app->ecClient()->citeste('/rezervari/' . $rezEc)->json()['stare'] ?? null) === 'anulata';
        } catch (EroareApi|EroareTransport) {
            return false;
        }
    }

    private function amana(int $id, string $motiv, ?string $requestId, string $actor): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        $n = max(1, (int) $c['anulare_incercari']);
        $pauza = self::REINCERCARI[min($n - 1, count(self::REINCERCARI) - 1)];
        $this->app->baza()->executa('UPDATE {comenzi} SET anulare_urmatoarea = ?, anulare_eroare = ? WHERE id = ?', [$this->app->ceas()->moment($pauza), mb_substr($motiv, 0, 64), $id]);
        $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], $actor, ['anulare' => 'amanata', 'motiv' => $motiv, 'reincercare_in' => $pauza, 'request_id' => $requestId]);
        if ($this->app->ceas()->acum() - Ceas::dinMoment((string) $c['anulare_pornita_la']) >= self::ALERTA) {
            $this->app->alertaGazda('alerta_anulare', ['ref' => (string) $c['ref'], 'cod' => $motiv, 'sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare']],
                'anulare_fara_raspuns:' . $c['ref'], 30 * 86400);
        }
        return 'in_lucru';
    }

    private function esec(array $c, string $cod, ?string $requestId, string $actor): string
    {
        $id = (int) $c['id'];
        $this->app->baza()->executa('UPDATE {comenzi} SET anulare_urmatoarea = NULL, anulare_eroare = ? WHERE id = ?', [mb_substr($cod, 0, 64), $id]);
        $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], $actor, ['anulare' => 'refuzata_ec', 'cod' => $cod, 'request_id' => $requestId]);
        $this->app->jurnal()->eroare('Anularea în EasyCheck a fost refuzată', ['ref' => $c['ref'], 'cod' => $cod, 'request_id' => $requestId]);
        $this->app->alertaGazda('alerta_anulare', ['ref' => (string) $c['ref'], 'cod' => $cod, 'sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare']],
            'anulare_refuz:' . $c['ref'] . ':' . $cod, 30 * 86400);
        return 'in_lucru';
    }

    /**
     * EasyCheck a confirmat anularea: tranziția spre starea marcajului (sau, la anularea gazdei din EasyCheck, spre
     * $spre cu $suma), apoi rambursarea și e-mailurile. Chemată sub zăvorul comenzii. @return string anulata, in_lucru
     */
    private function incheie(int $id, string $actor, ?string $requestId, ?string $spre = null, ?int $suma = null): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        $din = (string) $c['stare'];
        $spre ??= (string) $c['anulare_spre'];
        if (!in_array($din, ['confirmata', 'anulare_ceruta'], true) || !MasinaStari::permisa((string) $c['fel'], $din, $spre)) {
            return in_array($din, ['anulata_oaspete', 'anulata_gazda'], true) ? 'anulata' : 'in_lucru';
        }
        $campuri = ['anulata_ec_la' => $this->app->ceas()->moment(), 'anulare_urmatoarea' => null, 'anulare_eroare' => null];
        if ($suma !== null) {
            $campuri += ['anulare_spre' => $spre, 'anulare_suma' => $suma, 'anulare_net' => $this->net($c)];
        }
        $detalii = ['anulare' => $suma !== null ? 'easycheck' : 'confirmata_ec', 'actor_anulare' => $c['anulare_actor'], 'suma' => $suma ?? (int) $c['anulare_suma'], 'request_id' => $requestId];
        if (!$this->app->masinaStari()->trece($id, (string) $c['fel'], $din, $spre, $actor, $campuri, $detalii)) {
            return 'in_lucru';
        }
        if ($c['fel'] === 'card' && ($suma ?? (int) $c['anulare_suma']) > 0) {
            $this->ramburseaza($id);
        }
        // la plata prin transfer, taxa locală plătită online o returnează gazda, din bancă: alerta și e-mailul ei.
        if ($c['fel'] === 'transfer') {
            $this->taxaDeReturnat($id);
        }
        // anularea gazdei, cu clauza despăgubirii în contract: alerta și e-mailul gazdei.
        if ($spre === 'anulata_gazda') {
            $this->despagubire($id);
        }
        // Cererea de mutare care aștepta gazda se oprește abia acum, când anularea e încheiată (varianta A).
        $this->app->schimbareDate()->opresteCererea($this->app->comenzi()->dupaId($id), $actor);
        $this->anunta($id);
        return 'anulata';
    }

    /**
     * rezervarea plătită prin transfer bancar, anulată cu taxa locală plătită online în transfer: gazda o returnează
     * oaspetelui, din bancă (la orice anulare: sejurul anulat nu datorează taxa). Suma se scrie o singură dată pe comandă
     * (`taxa_de_returnat`), cu o alertă în panou (până la „Am returnat taxa locală”, `taxaReturnata`) și un e-mail către
     * proprietari, o singură dată pe comandă.
     */
    public function taxaDeReturnat(int $id): void
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || $c['fel'] !== 'transfer' || $c['platita_la'] === null || $c['taxa_de_returnat'] !== null
            || !in_array($c['stare'], ['anulata_oaspete', 'anulata_gazda'], true)) {
            return;
        }
        $taxa = self::taxaOnline($c, (int) $c['suma_acum']);
        if ($taxa <= 0) {
            return;
        }
        if ($this->app->baza()->executa('UPDATE {comenzi} SET taxa_de_returnat = ? WHERE id = ? AND taxa_de_returnat IS NULL', [$taxa, $id])->rowCount() !== 1) {
            return;
        }
        $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], 'sistem', ['taxa' => 'de_returnat', 'suma' => $taxa]);
        $this->app->alertaGazda('alerta_taxa_transfer', ['ref' => (string) $c['ref'], 'taxa_bani' => $taxa, 'sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare']],
            'taxa_transfer:' . $c['ref'], 31 * 86400);
    }

    /** gazda a apăsat „Am returnat taxa locală”: alerta se închide. @return string taxa_returnata sau stare */
    public function taxaReturnata(int $id, int $utilizator): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || (int) ($c['taxa_de_returnat'] ?? 0) <= 0 || $c['taxa_returnata_la'] !== null) {
            return 'stare';
        }
        if ($this->app->baza()->executa('UPDATE {comenzi} SET taxa_returnata_la = ? WHERE id = ? AND taxa_returnata_la IS NULL', [$this->app->ceas()->moment(), $id])->rowCount() !== 1) {
            return 'stare';
        }
        $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], 'admin:' . $utilizator, ['taxa' => 'returnata', 'suma' => (int) $c['taxa_de_returnat']]);
        return 'taxa_returnata';
    }

    /**
     * rezervarea plătită (cu cardul sau prin transfer), anulată de gazdă, cu clauza despăgubirii în contract
     * (`anulare_gazda` = `despagubire`): gazda datorează oaspetelui, în plus față de tot ce a plătit, o sumă egală cu
     * avansul (Anulare::despagubireContract), afară de forța majoră notată la anulare. Suma se scrie o singură dată pe
     * comandă (`despagubire`, 0 la forța majoră), cu o alertă în panou (până la „Am plătit despăgubirea”) și un e-mail
     * către proprietari. Despăgubirea o plătește gazda din bancă: site-ul nu o trimite prin Stripe. Comenzile fără clauză
     * (cele de dinainte, cu NULL, și cele cu „tot”) nu primesc nimic.
     */
    public function despagubire(int $id): void
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || $c['stare'] !== 'anulata_gazda' || $c['platita_la'] === null || $c['despagubire'] !== null) {
            return;
        }
        if (($c['anulare_gazda'] ?? null) === self::GAZDA_CAZ) {
            // „De la caz la caz”: suma hotărâtă de gazdă la anulare; fără decizie (o anulare fără dialog, din EasyCheck),
            // pagina rezervării o cere, iar despăgubirea se scrie după aceea (`despagubireAleasa`).
            if ($c['despagubire_aleasa'] === null) {
                return;
            }
            $suma = max(0, (int) $c['despagubire_aleasa']);
        } elseif (self::despagubireContract($c) > 0) {
            $suma = (int) $c['forta_majora'] === 1 ? 0 : self::despagubireContract($c);
        } else {
            return;
        }
        if ($this->app->baza()->executa('UPDATE {comenzi} SET despagubire = ? WHERE id = ? AND despagubire IS NULL', [$suma, $id])->rowCount() !== 1) {
            return;
        }
        $this->app->masinaStari()->jurnal($id, 'anulata_gazda', 'anulata_gazda', 'sistem', ['despagubire' => $suma > 0 ? 'datorata' : 'forta_majora', 'suma' => $suma]);
        if ($suma > 0) {
            $this->app->alertaGazda('alerta_despagubire', ['ref' => (string) $c['ref'], 'avans_bani' => $suma, 'sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare'],
                'clauza' => (string) $c['anulare_gazda']],
                'despagubire:' . $c['ref'], 31 * 86400);
        }
    }

    /** gazda a apăsat „Am plătit despăgubirea”: alerta se închide, o singură dată. @return string despagubire_platita sau stare */
    public function despagubirePlatita(int $id, int $utilizator): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || (int) ($c['despagubire'] ?? 0) <= 0 || $c['despagubire_platita_la'] !== null || (int) $c['forta_majora'] === 1) {
            return 'stare';
        }
        if ($this->app->baza()->executa('UPDATE {comenzi} SET despagubire_platita_la = ? WHERE id = ? AND despagubire_platita_la IS NULL AND forta_majora = 0',
            [$this->app->ceas()->moment(), $id])->rowCount() !== 1) {
            return 'stare';
        }
        $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], 'admin:' . $utilizator, ['despagubire' => 'platita', 'suma' => (int) $c['despagubire']]);
        return 'despagubire_platita';
    }

    /**
     * „Anulare din forță majoră” pe pagina rezervării, după o anulare fără dialog în panou (de exemplu, făcută
     * în EasyCheck): despăgubirea nu se mai datorează, iar alerta se închide. @return string forta_majora sau stare
     */
    public function fortaMajora(int $id, int $utilizator): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || (int) ($c['despagubire'] ?? 0) <= 0 || $c['despagubire_platita_la'] !== null || (int) $c['forta_majora'] === 1) {
            return 'stare';
        }
        if ($this->app->baza()->executa('UPDATE {comenzi} SET forta_majora = 1 WHERE id = ? AND forta_majora = 0 AND despagubire_platita_la IS NULL', [$id])->rowCount() !== 1) {
            return 'stare';
        }
        $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], 'admin:' . $utilizator, ['despagubire' => 'forta_majora', 'suma' => (int) $c['despagubire']]);
        return 'forta_majora';
    }

    /**
     * decizia gazdei despre despăgubire, la o anulare cu clauza „de la caz la caz” făcută fără dialog în panou
     * (de exemplu, în EasyCheck): suma (0 e o decizie), apoi alerta, ca la anulare. @return string despagubire_aleasa,
     * despagubire sau stare
     */
    public function despagubireAleasa(int $id, int $utilizator, ?int $suma): string
    {
        return $this->subZavor($id, function () use ($id, $utilizator, $suma): string {
            $c = $this->app->comenzi()->dupaId($id);
            if ($c === null || $c['stare'] !== 'anulata_gazda' || !self::despagubireDeHotarat($c)) {
                return 'stare';
            }
            if ($suma === null || $suma < 0) {
                return 'despagubire';
            }
            $this->notaDespagubireAleasa($c, $utilizator, $suma);
            $this->despagubire($id);
            return 'despagubire_aleasa';
        });
    }

    /** despăgubirea hotărâtă de gazdă („de la caz la caz”), scrisă o singură dată pe comandă. */
    private function notaDespagubireAleasa(array $c, int $utilizator, int $suma): void
    {
        if ($this->app->baza()->executa('UPDATE {comenzi} SET despagubire_aleasa = ? WHERE id = ? AND despagubire_aleasa IS NULL', [$suma, $c['id']])->rowCount() === 1) {
            $this->app->masinaStari()->jurnal((int) $c['id'], (string) $c['stare'], (string) $c['stare'], 'admin:' . $utilizator, ['despagubire' => 'aleasa', 'suma' => $suma]);
        }
    }

    /** forța majoră bifată la anularea gazdei (panoul, lămurirea), doar pe o comandă cu clauza despăgubirii. */
    private function notaFortaMajora(array $c, int $utilizator): void
    {
        if (self::despagubireContract($c) <= 0) {
            return;
        }
        if ($this->app->baza()->executa('UPDATE {comenzi} SET forta_majora = 1 WHERE id = ? AND forta_majora = 0', [$c['id']])->rowCount() === 1) {
            $this->app->masinaStari()->jurnal((int) $c['id'], (string) $c['stare'], (string) $c['stare'], 'admin:' . $utilizator, ['despagubire' => 'forta_majora']);
        }
    }

    /**
     * Rambursarea anulării, cu cheia comenzii `ref-<ref>-anulare` și reluările ei (Plati\Rambursari). La gazdă rămâne
     * exact ce s-a hotărât la pornire (`anulare_net` - `anulare_suma`); restul se returnează, pe orice cale: o rambursare
     * din Dashboard sau din panou făcută între timp se scade din ce se mai cere, iar o rambursare în procesare care
     * eșuează după pornire mărește din nou ce se cere. Încheiată (`anulare_rambursata_la`) doar când rambursările reușite
     * ating ținta. După un eșec, lista din Stripe se recitește rar (o oră; 6 ore după epuizare). Reluările epuizate:
     * alerta gazdei și o reverificare la 6 ore (`anulare_rambursare_reia`), ca o comandă blocată să lase cronul liber.
     * Chemată sub zăvorul comenzii.
     */
    private function ramburseaza(int $id): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        $p = $this->plata($id);
        if ($c === null || $p === null || $c['anulare_rambursata_la'] !== null) {
            return 'reusit';
        }
        // restituirea hotărâtă în EasyCheck pleacă prin comanda ei (EasyCheck\Comenzi) sau o face gazda, în afara site-ului.
        if (($c['ec_restituire_cale'] ?? null) !== null) {
            return 'in_lucru';
        }
        $ramb = $this->app->rambursari();
        $prefix = 'ref-' . $c['ref'] . '-anulare';
        $incasat = (int) $p['suma_incasata'];
        $ramane = $c['anulare_net'] !== null ? max(0, (int) $c['anulare_net'] - (int) $c['anulare_suma']) : $incasat - (int) $c['anulare_suma'];
        $gata = static fn (): bool => $incasat - $ramb->sumaPrefix($id, '', ['reusit']) <= $ramane;
        $esuate = (int) $this->app->baza()->valoare("SELECT COUNT(*) FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND idem LIKE ? AND stare = 'esuat'", [$id, $prefix . '%']);
        if ($esuate > 0 && $this->app->limitator()->incearca('sync_anulare:' . $id, 1, $esuate > count(\Cazo\Plati\Rambursari::REINCERCARI) ? self::EPUIZATA_REIA : 3600)) {
            $ramb->sincronizeaza($id, (string) $p['stripe_pi']);
        }
        $reia = $this->app->ceas()->moment(60);
        if (!$gata()) {
            $rest = $incasat - $ramb->rambursatSauInCurs($id) - $ramane;
            $r = $ramb->automata($id, (string) $c['ref'], (string) $p['stripe_pi'], max(0, $rest), (string) $c['anulare_spre'] === 'anulata_gazda' ? 'anulare_gazda' : 'anulare_oaspete', 'anulare');
            // Cu două plăți încasate, o parte reușită lasă restul pe cealaltă plată (`-s<n>`): se cere pe loc, ca e-mailul
            // anulării să plece după ce ambele părți sunt cerute.
            for ($n = 0; $n < 2 && $r['stare'] === 'reusit' && !$gata() && count($ramb->pisIncasate($id)) > 1; $n++) {
                $rest = $incasat - $ramb->rambursatSauInCurs($id) - $ramane;
                if ($rest <= 0) {
                    break;
                }
                $r = $ramb->automata($id, (string) $c['ref'], (string) $p['stripe_pi'], $rest, (string) $c['anulare_spre'] === 'anulata_gazda' ? 'anulare_gazda' : 'anulare_oaspete', 'anulare');
            }
            // Epuizarea printr-o operație nelămurită are alerta ei (lamuresteCerute), fără textul general.
            if ($r['epuizata']) {
                if (!$ramb->areNelamurita($id)) {
                    $this->app->alertaGazda('alerta_rambursare', ['ref' => (string) $c['ref'], 'suma_bani' => max(0, $incasat - $ramb->sumaPrefix($id, '', ['reusit']) - $ramane)],
                        'rambursare_anulare:' . $c['ref'], 30 * 86400);
                }
                // Ritmul de 6 ore și la epuizarea printr-o operație nelămurită.
                $reia = $this->app->ceas()->moment(self::EPUIZATA_REIA);
            }
        }
        if ($gata()) {
            $this->app->baza()->executa('UPDATE {comenzi} SET anulare_rambursata_la = ? WHERE id = ? AND anulare_rambursata_la IS NULL', [$this->app->ceas()->moment(), $id]);
            $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], 'sistem', ['rambursare' => 'anulare', 'suma' => (int) $c['anulare_suma']]);
            return 'reusit';
        }
        $this->app->baza()->executa('UPDATE {comenzi} SET anulare_rambursare_reia = ? WHERE id = ?', [$reia, $id]);
        return 'in_lucru';
    }

    /** E-mailurile anulării încheiate, o singură dată: oaspetele și, la anularea oaspetelui, gazda. */
    private function anunta(int $id): void
    {
        $n = $this->app->baza()->executa('UPDATE {comenzi} SET email_anulare_la = ? WHERE id = ? AND email_anulare_la IS NULL', [$this->app->ceas()->moment(), $id])->rowCount();
        if ($n !== 1) {
            return;
        }
        $c = $this->app->comenzi()->dupaId($id);
        $sablon = $c['anulare_spre'] === 'anulata_gazda' ? 'anulare_gazda' : 'anulare_oaspete';
        $pleaca = $this->sumaCarePleaca($c);
        if ($pleaca !== null) {
            // O parte a rambursării, pe a doua plată, a eșuat: e-mailul spune doar suma care pleacă (alerta gazdei acoperă restul).
            $o = $this->app->comenzi()->oaspete($c);
            if (isset($o['email'])) {
                $this->app->emailuri()->pune($id, $sablon, (string) $c['limba'], (string) $o['email'], ['comanda_id' => $id, 'suma_bani' => $pleaca]);
                $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], 'sistem', ['email' => $sablon, 'suma' => $pleaca]);
            }
        } else {
            $this->anuntaOaspete($id, $sablon);
        }
        if ($c['anulare_actor'] === 'oaspete') {
            $this->anuntaGazda($id, 'gazda_anulare', ['suma_bani' => (int) $c['anulare_suma']]);
        }
    }

    /**
     * Rambursarea anulării împărțită pe două plăți, cu o parte eșuată: suma care pleacă de fapt
     * (operațiile anulării reușite, în procesare sau încă cerute), când e sub suma hotărâtă. Null altfel (o singură
     * plată, nicio parte eșuată sau nimic plecat încă): e-mailul spune suma hotărâtă, ca până acum.
     */
    private function sumaCarePleaca(array $c): ?int
    {
        $id = (int) $c['id'];
        $ramb = $this->app->rambursari();
        if ($c['fel'] !== 'card' || (int) $c['anulare_suma'] <= 0 || count($ramb->pisIncasate($id)) < 2) {
            return null;
        }
        $prefix = 'ref-' . $c['ref'] . '-anulare';
        if ($ramb->sumaPrefix($id, $prefix, ['esuat']) <= 0) {
            return null;
        }
        $pleaca = $ramb->sumaPrefix($id, $prefix, ['reusit', 'in_procesare', 'cerut']);
        return $pleaca > 0 && $pleaca < (int) $c['anulare_suma'] ? $pleaca : null;
    }

    /**
     * E-mailul rambursării din panou, o singură dată pe rambursare (varianta B): o rambursare împărțită
     * pe două plăți (`<cheie>` și `<cheie>-p2`) aduce un singur e-mail, cu suma totală, după ce ambele părți s-au încheiat
     * (reușite, ca până acum). O parte eșuată rămâne în afara sumei: e-mailul spune doar ce pleacă, iar restul îl
     * acoperă alerta gazdei.
     */
    private function anuntaRambursarile(int $id): void
    {
        $b = $this->app->baza();
        foreach ($b->toate("SELECT idem FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND idem LIKE 'ref-%-panou-%' AND stare = 'reusit' AND anuntat_la IS NULL ORDER BY id", [$id]) as $r) {
            $cheie = (string) preg_replace('~-p2$~', '', (string) $r['idem']);
            $parti = $b->toate("SELECT id, suma, stare FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND idem IN (?, ?) AND anuntat_la IS NULL ORDER BY id", [$id, $cheie, $cheie . '-p2']);
            if ($parti === [] || array_filter($parti, static fn (array $o): bool => in_array($o['stare'], ['cerut', 'in_procesare'], true)) !== []) {
                // O parte e încă în drum (sau a fost deja anunțată odată cu cealaltă): e-mailul așteaptă.
                continue;
            }
            $ids = array_map(static fn (array $o): int => (int) $o['id'], $parti);
            $semne = implode(', ', array_fill(0, count($ids), '?'));
            if ($b->executa("UPDATE {operatii_plata} SET anuntat_la = ? WHERE id IN ($semne) AND anuntat_la IS NULL", array_merge([$this->app->ceas()->moment()], $ids))->rowCount() !== count($ids)) {
                continue;
            }
            $suma = array_sum(array_map(static fn (array $o): int => $o['stare'] === 'reusit' ? (int) $o['suma'] : 0, $parti));
            $c = $this->app->comenzi()->dupaId($id);
            $o = $this->app->comenzi()->oaspete($c);
            if ($suma > 0 && isset($o['email'])) {
                $this->app->emailuri()->pune($id, 'rambursare_gazda', (string) $c['limba'], (string) $o['email'], ['comanda_id' => $id, 'operatie' => $ids[0], 'suma_bani' => $suma]);
                $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], 'sistem', ['email' => 'rambursare_gazda', 'suma' => $suma]);
            }
        }
    }

    private function anuntaOaspete(int $id, string $sablon): void
    {
        $this->app->plataOnline()->anunta($id, $sablon);
    }

    /** E-mailul către gazdă (fiecare proprietar activ, în limba panoului lui). */
    private function anuntaGazda(int $id, string $sablon, array $date = []): void
    {
        foreach ($this->app->baza()->toate("SELECT email, limba_panou FROM {utilizatori} WHERE rol = 'proprietar' AND activ = 1 ORDER BY id") as $u) {
            $this->app->emailuri()->pune($id, $sablon, (string) $u['limba_panou'], (string) $u['email'], ['comanda_id' => $id] + $date);
        }
    }

    /**
     * Plata încasată a comenzii, sau null: rândul plății inițiale (PaymentIntent-ul ei), cu `suma_incasata` adunată pe
     * toate plățile încasate ale comenzii (plata inițială și diferența unei schimbări de date, 118b). Rambursările aleg
     * singure plata (Plati\Rambursari::tinta).
     */
    public function plata(int $id): ?array
    {
        $p = $this->app->baza()->rand("SELECT * FROM {plati} WHERE comanda_id = ? AND stripe_pi IS NOT NULL AND suma_incasata > 0 ORDER BY id LIMIT 1", [$id]);
        if ($p !== null) {
            $p['suma_incasata'] = (int) $this->app->baza()->valoare('SELECT COALESCE(SUM(suma_incasata), 0) FROM {plati} WHERE comanda_id = ? AND stripe_pi IS NOT NULL AND suma_incasata > 0', [$id]);
        }
        return $p;
    }

    /** $f sub zăvorul comenzii; zăvorul ocupat: „ocupat”. */
    private function subZavor(int $id, callable $f): string
    {
        $r = $this->app->zavor()->cuZavor('comanda:' . $id, 60, $f);
        return $r[0] === true ? (string) $r[1] : 'ocupat';
    }
}
