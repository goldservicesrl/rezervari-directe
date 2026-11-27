<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

use Cazo\Baza\Baza;
use Cazo\Baza\Migrator;
use Cazo\Baza\Zavor;
use Cazo\EasyCheck\Anunturi;
use Cazo\EasyCheck\Buget;
use Cazo\EasyCheck\Client;
use Cazo\EasyCheck\Conectare;
use Cazo\EasyCheck\Licenta;
use Cazo\EasyCheck\Oglinda;
use Cazo\EasyCheck\Poze;
use Cazo\EasyCheck\Sincronizare;
use Cazo\EasyCheck\Transport;
use Cazo\EasyCheck\TransportCurl;
use Cazo\I18n\Traducator;
use Cazo\Joburi\Coada;
use Cazo\Joburi\Planificator;
use Cazo\Joburi\PseudoCron;
use Cazo\Joburi\Rulator;
use Cazo\Pret\MotorLocal;
use Cazo\Pret\PretNoapte;
use Cazo\Pret\Total;
use Cazo\Pret\RegulaPlata;
use Cazo\Pret\TotalEasyCheck;
use Cazo\Email\Cutie as CutieEmail;
use Cazo\Email\Sabloane;
use Cazo\Email\Transport as TransportEmail;
use Cazo\Email\TransportSmtp;
use Cazo\Plati\Checkout;
use Cazo\Plati\ContStripe;
use Cazo\Plati\Http as HttpStripe;
use Cazo\Plati\HttpCurl;
use Cazo\Plati\Rambursari;
use Cazo\Rezervare\Anulare;
use Cazo\Rezervare\CerereCash;
use Cazo\Rezervare\Finalizare;
use Cazo\Rezervare\ModPlata;
use Cazo\Rezervare\PlataOnline;
use Cazo\Rezervare\Recuperare;
use Cazo\Rezervare\CodEmail;
use Cazo\Rezervare\Comenzi;
use Cazo\Rezervare\LinkOaspete;
use Cazo\Rezervare\MasinaStari;
use Cazo\Securitate\Altcha;
use Cazo\Securitate\Cutie;
use Cazo\Securitate\Intrerupator;
use Cazo\Securitate\SesiuneAdmin;
use Cazo\Teme\Active;
use Cazo\Teme\ModelVedere;
use Cazo\Teme\Randare;
use Cazo\Teme\Catalog;
use Cazo\Teme\Tema;
use Cazo\Teme\TemeTerte;
use Cazo\Securitate\Chei;
use Cazo\Securitate\Codare;
use Cazo\Securitate\Csrf;
use Cazo\Securitate\IndexOrb;
use Cazo\Securitate\Limitator;
use Cazo\Securitate\Secrete;

defined('CAZO') or exit;

/**
 * Containerul aplicației, cu fabrici explicite (fără reflecție, fără configurare magică), și nucleul
 * care transformă o Cerere într-un Raspuns: router, întreținere, CSRF, controler, erori, antete.
 */
final class Aplicatie
{
    private array $instante = [];

    /** @var list<callable(): void> lucrul de după răspuns (anunțurile), rulat după închiderea conexiunii */
    private array $dupaRaspuns = [];

    private bool $caleaAnuntului = false;

    /** @var array{cerere:Cerere, profil:string}|null cererea tratată acum (antetele răspunsului de după o eroare fatală) */
    private ?array $inCurs = null;

    /** Răspunsul trimis din funcția de oprire după o eroare fatală a temei (Plasa); îl citesc probele. */
    public ?Raspuns $raspunsDupaFatala = null;

    /** Transportul EasyCheck al testelor unitare (null = cURL). */
    public ?Transport $transportEc = null;

    /** Transportul Stripe al testelor (null = cURL spre api.stripe.com). */
    public ?HttpStripe $httpStripe = null;

    /** Transportul e-mailurilor al testelor (null = SMTP din setări, altfel mail()). */
    public ?TransportEmail $transportEmail = null;

    /** Tema arătată în locul celei active (previzualizarea din instalator), doar pentru cererea aceasta. */
    public ?string $temaPrevizualizata = null;
    /** Transportul datelor externe al testelor (null = cURL spre MET Norway, BNR, Nominatim, Overpass). */
    public ?\Cazo\DateExterne\Http $httpExtern = null;

    /** Transportul dalelor hărții din panou al testelor (null = cURL spre tile.openstreetmap.org). */
    public ?\Cazo\DateExterne\Http $httpDale = null;

    /** Tema folosită de randarea de probă a validatorului (ProbaTema), în locul celei din setări. */
    public ?Tema $temaFixa = null;

    /** @param Ruta[]|null $rute */
    public function __construct(
        private Config $config,
        private Chei $chei,
        private ?Baza $baza = null,
        private ?Ceas $ceas = null,
        private ?array $rute = null,
    ) {
    }

    /** @param bool $cuInstalare și configurarea instalării în curs (cronul, Config::dinDosar) */
    public static function dinDosar(string $privat, bool $cuInstalare = false): self
    {
        $config = Config::dinDosar($privat, $cuInstalare);
        return new self($config, Chei::dinFisier($privat . '/cheie.bin'));
    }

    /** Rădăcina lansării (app/, limbi/, migrari/ ...). */
    public static function radacina(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function versiune(): string
    {
        return (string) require self::radacina() . '/app/versiune.php';
    }

    // ---- fabricile ----

    public function config(): Config
    {
        return $this->config;
    }

    public function chei(): Chei
    {
        return $this->chei;
    }

    public function privat(): string
    {
        return $this->config->privat();
    }

    public function ceas(): Ceas
    {
        return $this->ceas ??= new Ceas();
    }

    public function baza(): Baza
    {
        return $this->baza ??= Baza::conecteaza($this->config->descriereBaza($this->chei), $this->config->prefixBaza());
    }

    public function migrator(): Migrator
    {
        return $this->instante[__FUNCTION__] ??= new Migrator($this->baza(), $this->ceas(), self::radacina() . '/migrari', self::versiune());
    }

    /** Semnul ultimei migrări aplicate pe această instalare (lucru/migrari.txt), ca verificarea să nu atingă baza la fiecare cerere. */
    public const SEMN_MIGRARI = 'lucru/migrari.txt';

    /**
     * Migrările unei versiuni urcate de gazdă: actualizarea e de mână (arhiva de pe GitHub dezarhivată peste
     * instalare), deci prima cerere sau primul cron al codului nou aplică migrările lui, aditive și idempotente, sub
     * zăvorul `migrari`. Cât semnul din lucru/ arată ultima migrare din lansare, costul e un glob și o citire de fișier.
     * Aruncă excepția migratorului când altă cerere migrează chiar acum sau o migrare cade.
     */
    public function migreazaLaNevoie(): void
    {
        $fisiere = glob(self::radacina() . '/migrari/[0-9][0-9][0-9][0-9]_*.php') ?: [];
        $ultima = $fisiere === [] ? '' : substr(basename(max($fisiere)), 0, 4);
        $semn = $this->privat() . '/' . self::SEMN_MIGRARI;
        if ($ultima === '' || trim((string) @file_get_contents($semn)) === $ultima) {
            return;
        }
        $facute = $this->migrator()->migreaza();
        if ($facute !== []) {
            $this->jurnal()->info('Migrările versiunii noi s-au aplicat', ['versiune' => self::versiune(), 'migrari' => $facute]);
            $this->ecOglinda()->schimbaContinutul();
        }
        if (!is_dir(dirname($semn))) {
            @mkdir(dirname($semn), 0700, true);
        }
        $tmp = $semn . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $ultima . "\n") === false || !@rename($tmp, $semn)) {
            @unlink($tmp);
        }
    }

    public function jurnal(): Jurnal
    {
        return $this->instante[__FUNCTION__] ??= new Jurnal($this->privat() . '/jurnale', $this->ceas());
    }

    public function setari(): Setari
    {
        return $this->instante[__FUNCTION__] ??= new Setari($this->baza(), $this->ceas());
    }

    public function secrete(): Secrete
    {
        return $this->instante[__FUNCTION__] ??= new Secrete($this->baza(), $this->chei, $this->ceas());
    }

    public function indexOrb(): IndexOrb
    {
        return $this->instante[__FUNCTION__] ??= new IndexOrb($this->chei);
    }

    public function csrf(): Csrf
    {
        return $this->instante[__FUNCTION__] ??= new Csrf($this->chei);
    }

    public function limitator(): Limitator
    {
        return $this->instante[__FUNCTION__] ??= new Limitator($this->baza(), $this->ceas());
    }

    public function zavor(): Zavor
    {
        return $this->instante[__FUNCTION__] ??= new Zavor($this->baza(), $this->ceas());
    }

    public function coada(): Coada
    {
        if (!isset($this->instante[__FUNCTION__])) {
            $this->instante[__FUNCTION__] = new Coada($this->baza(), $this->ceas(), $this->jurnal());
            $this->inregistreazaJoburi($this->instante[__FUNCTION__]);
        }
        return $this->instante[__FUNCTION__];
    }

    public function planificator(): Planificator
    {
        if (!isset($this->instante[__FUNCTION__])) {
            $this->instante[__FUNCTION__] = new Planificator($this->baza(), $this->ceas());
            $this->inregistreazaSarcini($this->instante[__FUNCTION__]);
        }
        return $this->instante[__FUNCTION__];
    }

    public function rulator(): Rulator
    {
        return $this->instante[__FUNCTION__] ??= new Rulator($this->coada(), $this->planificator(), $this->zavor(), $this->setari(), $this->ceas(),
            fn (): bool => Intretinere::activa($this->privat(), $this->ceas()->acum()),
            function (): void {
                if ($this->lantCerut) {
                    $this->lantCerut = false;
                    $this->lantCron();
                }
            });
    }

    public function pseudoCron(): PseudoCron
    {
        return $this->instante[__FUNCTION__] ??= new PseudoCron($this->privat() . '/' . CachePagini::FISIER_PSEUDO_CRON, $this->ceas());
    }

    // ---- EasyCheck ----

    public function ecBuget(): Buget
    {
        return $this->instante[__FUNCTION__] ??= new Buget($this->baza(), $this->ceas(), $this->limitator());
    }

    public function ecLicenta(): Licenta
    {
        return $this->instante[__FUNCTION__] ??= new Licenta($this->setari(), $this->ceas(), $this->ecBuget());
    }

    /**
     * Clientul pe cheia salvată (secretul `ec_cheie`) sau pe o cheie dată (verificarea unei chei noi).
     * Clientul unei chei de verificat rămâne separat de cheia salvată: fără bugetul comun (pauza lui) și fără
     * observatorul licenței, ca o cheie greșită tastată în Conexiuni să lase cheia bună să lucreze mai departe.
     */
    public function ecClient(?string $cheie = null): Client
    {
        if ($cheie === null && isset($this->instante[__FUNCTION__])) {
            return $this->instante[__FUNCTION__];
        }
        $e = $this->config->easycheck();
        $transport = $this->transportEc ?? new TransportCurl($e['permite_http']);
        if ($cheie !== null) {
            return new Client($transport, static fn (): string => $cheie, null, $e['baza'], $e['timeouts']);
        }
        // fără cheie EasyCheck, clientul vorbește cu API-ul răspuns de site, din baza lui (fără rețea).
        if ($this->modLocal()) {
            $c = new Client($this->transportLocal ?? new \Cazo\Local\TransportLocal($this), static fn (): string => \Cazo\Local\Mod::CHEIE, null, \Cazo\Local\Mod::BAZA, $e['timeouts']);
            $c->observa($this->ecLicenta()->observa(...));
            return $this->instante[__FUNCTION__] = $c;
        }
        $c = new Client($transport, fn (): ?string => $this->secrete()->citeste('ec_cheie'), $this->ecBuget(), $e['baza'], $e['timeouts']);
        $c->observa($this->ecLicenta()->observa(...));
        return $this->instante[__FUNCTION__] = $c;
    }

    // ---- site-ul fără cheie EasyCheck ----

    /** Transportul local al testelor (null = cel din baza site-ului). */
    public ?Transport $transportLocal = null;

    /** Transportul iCal al testelor (null = cURL cu adresa verificată, Local\IcalCurl). */
    public ?\Cazo\Local\IcalHttp $httpIcal = null;

    /** Site-ul lucrează fără cheie EasyCheck: datele vin din panou, rezervările le ține site-ul. */
    public function modLocal(): bool
    {
        try {
            return \Cazo\Local\Mod::local($this->setari());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Cheia folosită acum de client: cea EasyCheck salvată sau, în modul local, cheia fixă a site-ului (prefixul ei,
     * `cazoloc0`, ajunge în `comenzi.cheie_prefix`). Null: nicio cheie.
     */
    public function cheieEc(): ?string
    {
        return $this->modLocal() ? \Cazo\Local\Mod::CHEIE : $this->secrete()->citeste('ec_cheie');
    }

    public function localDepozit(): \Cazo\Local\Depozit
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Local\Depozit($this->baza(), $this->setari(), $this->ceas());
    }

    public function localIcal(): \Cazo\Local\Calendare
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Local\Calendare($this);
    }

    public function localTrecere(): \Cazo\Local\Trecere
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Local\Trecere($this);
    }

    /**
     * Datele locale s-au schimbat (o rezervare, o cerere acceptată, camerele, zilele închise, un calendar iCal): oglinda
     * le recitește pe loc din transportul local (fără rețea), ca site-ul să arate imediat nopțile și prețurile noi; într-o
     * tranzacție deschisă, după răspuns.
     * @param list<string> $resurse
     */
    public function localDupaScriere(array $resurse = ['disponibilitate']): void
    {
        if (!$this->modLocal()) {
            return;
        }
        // rezervările se repartizează pe unități la scriere, ca citirea calendarului pe unitate doar să citească.
        try {
            $this->localDepozit()->repartizeaza($this->azi());
        } catch (\Throwable $e) {
            $this->jurnal()->avertisment('Repartizarea pe unități a eșuat', ['eroare' => $e->getMessage()]);
        }
        $this->ecOglinda()->marcheazaInvechit($resurse);
        $citeste = function () use ($resurse): void {
            foreach ($resurse as $r) {
                try {
                    $this->ecOglinda()->citeste($r);
                } catch (\Throwable $e) {
                    $this->jurnal()->avertisment('Recitirea datelor locale a eșuat', ['resursa' => $r, 'eroare' => $e->getMessage()]);
                }
            }
        };
        if ($this->baza()->inTranzactie()) {
            $this->dupaRaspuns($citeste);
            return;
        }
        $citeste();
    }

    /**
     * Schimbarea modului: clientul, oglinda și tot ce le ține se refac la prima folosire, pe modul nou.
     */
    public function uitaLegatura(): void
    {
        foreach (['ecClient', 'ecOglinda', 'ecSincronizare', 'ecConectare', 'ecAnunturi', 'total', 'paritate', 'pretNoapte', 'motorLocal', 'varianteText', 'cerereCash', 'modPlata'] as $k) {
            unset($this->instante[$k]);
        }
    }

    public function ecOglinda(): Oglinda
    {
        if (!isset($this->instante[__FUNCTION__])) {
            $o = new Oglinda($this->baza(), $this->ceas(), $this->ecClient(), $this->setari(), $this->privat(), $this->jurnal());
            $o->laSchimbare('eu', function (?array $corp): void {
                if ($corp !== null) {
                    $vechi = $this->ecLicenta()->cheie();
                    $this->ecLicenta()->dinEu($corp);
                    $this->ecLicenta()->dupaRezumat($vechi);
                    // Modul cheii intră în antete (noindex la proba), deci și în paginile ținute în cache.
                    if (Licenta::schimbaPaginile($vechi, $this->ecLicenta()->cheie())) {
                        $this->ecOglinda()->schimbaContinutul();
                    }
                }
            });
            $poze = function (): void {
                $o = $this->ecOglinda();
                if ($this->ecPoze()->dinOglinda($o->corp('proprietate'), $o->corp('camere')) > 0) {
                    $this->planificator()->grabeste('poze:prelucreaza');
                }
            };
            // Prețurile noi din oglindă: grilele ținute minte de PretNoapte în aceeași cerere nu mai sunt valabile.
            $o->laSchimbare('preturi', function (): void {
                if (isset($this->instante['pretNoapte'])) {
                    $this->instante['pretNoapte']->golesteCache();
                }
            });
            $o->laSchimbare('proprietate', $poze);
            $o->laSchimbare('camere', $poze);
            $this->instante[__FUNCTION__] = $o;
        }
        return $this->instante[__FUNCTION__];
    }

    public function ecSincronizare(): Sincronizare
    {
        return $this->instante[__FUNCTION__] ??= new Sincronizare($this->ecOglinda(), $this->ecBuget(), $this->ecLicenta(), $this->zavor(), $this->ceas(), $this->jurnal());
    }

    public function ecAnunturi(): Anunturi
    {
        if (!isset($this->instante[__FUNCTION__])) {
            $a = new Anunturi($this->baza(), $this->ceas(), $this->secrete(), $this->ecOglinda(), $this->coada(), $this->setari());
            // Răspunsul gazdei la o cerere cash: anunțul nu poartă id-ul, deci jobul citește toate cererile în așteptare.
            $a->observa(function (string $tip): void {
                if (str_starts_with($tip, 'cerere.')) {
                    $this->coada()->pune(CerereCash::JOB, [], CerereCash::JOB);
                    // Cererile de schimbare a datelor (plata la sosire, 118d) primesc răspunsul pe aceeași cale.
                    $this->coada()->pune(\Cazo\Rezervare\SchimbareDate::JOB, ['cerere' => true], \Cazo\Rezervare\SchimbareDate::JOB . ':cerere');
                }
                // O rezervare a site-ului schimbată în EasyCheck (de exemplu anulată de gazdă): lista incrementală.
                if ($tip === 'rezervare.schimbata' || $tip === 'rezervare.anulare_decisa') {
                    $this->coada()->pune(Anulare::JOB, [], Anulare::JOB);
                }
                // o comandă nouă de la EasyCheck; citirea și execuția stau în cron (`ec:comenzi`), nu în cererea anunțului.
                if ($tip === 'comanda.noua') {
                    $this->ecComenzi()->deCitit();
                }
                // Transferul bancar: decizia gazdei, expirarea sau prelungirea termenului; anunțul nu poartă id-ul
                // rezervării, deci jobul citește toate rezervările care așteaptă plata. `rezervare.schimbata` pleacă la
                // aceleași treceri și la anularea din calendar, deci pornește același job (o singură intrare în coadă).
                // șabloanele mesajelor s-au schimbat în EasyCheck; sarcina `ec:mesaje` le recitește.
                if ($tip === 'mesaje.schimbate') {
                    $this->mesajeEc()->marcheazaInvechit();
                }
                if (in_array($tip, \Cazo\Rezervare\Transfer::ANUNTURI, true) || $tip === 'rezervare.schimbata') {
                    $this->coada()->pune(\Cazo\Rezervare\Transfer::JOB, [], \Cazo\Rezervare\Transfer::JOB);
                }
            });
            $this->instante[__FUNCTION__] = $a;
        }
        return $this->instante[__FUNCTION__];
    }

    public function ecPoze(): Poze
    {
        $e = $this->config->easycheck();
        return $this->instante[__FUNCTION__] ??= new Poze($this->baza(), $this->ceas(), $this->privat(), $this->config->calePublic(), $e['gazde_poze'], $e['permite_http'], null, $this->jurnal());
    }

    /** „Starea site-ului” trimisă la EasyCheck: declarația și semnul de viață cu alertele de acum. */
    public function stareSite(): \Cazo\EasyCheck\StareSite
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\EasyCheck\StareSite($this);
    }

    /** „Adresa site-ului”: domeniul gazdei sau subdomeniul gratuit cazo.ro, cu trecerea pe el. */
    public function adresa(): \Cazo\Sistem\Adresa
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Sistem\Adresa($this);
    }

    public function ecConectare(): Conectare
    {
        return $this->instante[__FUNCTION__] ??= new Conectare(
            fn (string $cheie): Client => $this->ecClient($cheie),
            $this->ecClient(),
            $this->secrete(),
            $this->setari(),
            $this->ecOglinda(),
            $this->ecLicenta(),
            $this->ecAnunturi(),
            $this->ecBuget(),
            $this->ceas(),
            $this->url()->absolut('/easycheck-anunt'),
        );
    }

    // ---- comenzile, cererea cash, e-mailurile, panoul minim ----

    public function linkOaspete(): LinkOaspete
    {
        return $this->instante[__FUNCTION__] ??= new LinkOaspete($this->chei);
    }

    public function comenzi(): Comenzi
    {
        return $this->instante[__FUNCTION__] ??= new Comenzi($this->baza(), $this->ceas(), $this->chei, $this->indexOrb(), $this->linkOaspete());
    }

    public function masinaStari(): MasinaStari
    {
        return $this->instante[__FUNCTION__] ??= new MasinaStari($this->baza(), $this->ceas());
    }

    public function cerereCash(): CerereCash
    {
        return $this->instante[__FUNCTION__] ??= new CerereCash($this->baza(), $this->ceas(), $this->comenzi(), $this->masinaStari(),
            fn (): Client => $this->ecClient(), $this->emailuri(), $this->setari(), $this->jurnal(),
            // alerta_cheie: cel mult una pe zi pentru același cod.
            fn (string $sablon, array $date, string $cheie) => $this->alertaGazda($sablon, $date, $cheie, 86400),
            fn (int $id) => $this->anulare()->dupaConfirmare($id),
            // fără cheie EasyCheck, gazda află de cerere de la site (în modul EasyCheck, o anunță EasyCheck).
            function (int $id): void {
                if (!$this->modLocal()) {
                    return;
                }
                foreach ($this->baza()->toate("SELECT email, limba_panou FROM {utilizatori} WHERE rol = 'proprietar' AND activ = 1 ORDER BY id") as $u) {
                    $this->emailuri()->pune($id, 'gazda_cerere', (string) $u['limba_panou'], (string) $u['email'], ['comanda_id' => $id]);
                }
            });
    }

    /** Contul bancar și regulile plății prin transfer. */
    public function contTransfer(): \Cazo\Rezervare\ContTransfer
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Rezervare\ContTransfer($this);
    }

    /** Plata prin transfer bancar. */
    public function transfer(): \Cazo\Rezervare\Transfer
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Rezervare\Transfer($this);
    }

    public function codEmail(): CodEmail
    {
        return $this->instante[__FUNCTION__] ??= new CodEmail($this->baza(), $this->ceas(), $this->limitator());
    }

    public function altcha(): Altcha
    {
        return $this->instante[__FUNCTION__] ??= new Altcha($this->chei, $this->ceas(), $this->limitator(), $this->zavor());
    }

    public function intrerupator(): Intrerupator
    {
        if (!isset($this->instante[__FUNCTION__])) {
            // Pragul minim pe oră: maximul dintre 30 și numărul de camere (unitățile din oglindă).
            $i = new Intrerupator($this->setari(), $this->ceas(), $this->limitator(), function (): int {
                $n = 0;
                foreach ((array) ($this->ecOglinda()->corp('camere')['camere'] ?? []) as $c) {
                    $n += is_array($c) ? (int) ($c['unitati'] ?? 1) : 0;
                }
                return $n;
            });
            // Codul pornit de întrerupător s-a oprit singur după 24 de ore liniștite.
            $i->laOprireCod(function (): void {
                foreach ($this->baza()->toate("SELECT email, limba_panou FROM {utilizatori} WHERE rol = 'proprietar' AND activ = 1 ORDER BY id") as $u) {
                    $this->emailuri()->pune(null, 'alerta_protectie_oprita', (string) $u['limba_panou'], (string) $u['email'], []);
                }
                // întâmplarea intră și în jurnalul alertelor (fără alt e-mail), de unde ajunge în starea trimisă la EasyCheck.
                try {
                    $this->baza()->insereaza('alerte', ['la' => $this->ceas()->moment(), 'sablon' => 'alerta_protectie_oprita', 'cheie' => 'protectie_oprita', 'date' => '{}', 'trimis' => 1]);
                } catch (\Throwable) {
                }
            });
            // Gazda primește e-mail la declanșare, la fiecare proprietar activ, în limba panoului lui.
            $i->laDeclansare(function (string $motiv): void {
                foreach ($this->baza()->toate("SELECT email, limba_panou FROM {utilizatori} WHERE rol = 'proprietar' AND activ = 1 ORDER BY id") as $u) {
                    $this->emailuri()->pune(null, 'alerta_intrerupator', (string) $u['limba_panou'], (string) $u['email'], ['motiv' => $motiv]);
                }
                $this->jurnal()->avertisment('Întrerupătorul a oprit rezervările online', ['motiv' => $motiv]);
            });
            $this->instante[__FUNCTION__] = $i;
        }
        return $this->instante[__FUNCTION__];
    }

    /**
     * O alertă către gazdă: e-mailul $sablon (Sabloane::GAZDA) la fiecare proprietar activ, în limba panoului lui,
     * cel mult o dată la $interval secunde pentru aceeași $cheie; linia din jurnal rămâne la fiecare apel.
     * Panoul complet arată alertele și în pagina de început.
     */
    public function alertaGazda(string $sablon, array $date, string $cheie, int $interval = 86400): void
    {
        $this->jurnal()->avertisment('Alertă pentru gazdă', ['sablon' => $sablon] + $date);
        try {
            $trimite = $this->limitator()->incearca('alerta:' . $cheie, 1, $interval);
            // fiecare caz rămâne în jurnalul alertelor din panou, chiar când e-mailul a plecat deja azi.
            try {
                $this->baza()->insereaza('alerte', ['la' => $this->ceas()->moment(), 'sablon' => mb_substr($sablon, 0, 64), 'cheie' => mb_substr($cheie, 0, 191),
                    'date' => json_encode(\Cazo\Securitate\Audit::curata($date), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'trimis' => (int) $trimite]);
            } catch (\Throwable $e) {
                // Jurnalul alertelor din panou nu oprește e-mailul.
                $this->jurnal()->eroare('Alerta nu a intrat în jurnalul panoului', ['sablon' => $sablon, 'eroare' => $e->getMessage()]);
            }
            if (!$trimite) {
                return;
            }
            foreach ($this->baza()->toate("SELECT email, limba_panou FROM {utilizatori} WHERE rol = 'proprietar' AND activ = 1 ORDER BY id") as $u) {
                $this->emailuri()->pune(null, $sablon, (string) $u['limba_panou'], (string) $u['email'], $date);
            }
        } catch (\Throwable $e) {
            // Alerta nu oprește pagina: rămâne linia din jurnal.
            $this->jurnal()->eroare('Alerta pentru gazdă nu a intrat în coadă', ['sablon' => $sablon, 'eroare' => $e->getMessage()]);
        }
    }

    /**
     * Anunțul versiunii noi: fișierul public https://cazo.ro/versiune.json, citit o dată pe zi, mereu pornit; la o
     * versiune nouă, e-mailul `versiune_noua` către proprietari, o singură dată pe versiune. În mediul de test, cererea
     * pleacă doar spre `actualizari.sursa` din config.php.
     */
    public function versiuneNoua(): \Cazo\Actualizari\VersiuneNoua
    {
        $sursa = $this->config->mediuTest() ? (is_string($a = $this->config->get('actualizari.sursa')) && str_starts_with($a, 'https://') ? $a : null)
            : \Cazo\Actualizari\VersiuneNoua::SURSA;
        return $this->instante[__FUNCTION__] ??= new \Cazo\Actualizari\VersiuneNoua($this->setari(), $this->ceas(),
            fn (): \Cazo\DateExterne\Http => $this->httpExtern(), self::ADRESA_PROIECT, $sursa,
            function (array $v): void {
                $this->alertaGazda('versiune_noua', ['versiune' => $v['versiune'], 'data' => $v['data'], 'url' => $v['url'], 'securitate' => $v['securitate'],
                    'note_ro' => $v['note']['ro'], 'note_en' => $v['note']['en']], 'versiune_noua:' . $v['versiune'], 31 * 86400);
            });
    }

    public function sesiuneAdmin(): SesiuneAdmin
    {
        return $this->instante[__FUNCTION__] ??= new SesiuneAdmin($this->baza(), $this->ceas(), $this->limitator(), new Cutie($this->chei->subcheie('totp')),
            $this->chei->subcheie('coduri_rezerva'));
    }

    // ---- panoul complet: utilizatorii, auditul, GDPR, copiile ----

    public function utilizatori(): \Cazo\Securitate\Utilizatori
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Securitate\Utilizatori($this->baza(), $this->ceas(), $this->sesiuneAdmin(), $this->zavor());
    }

    /** Auditul înlănțuit, cu HMAC-ul pe subcheia „audit”. */
    public function audit(): \Cazo\Securitate\Audit
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Securitate\Audit($this->baza(), $this->ceas(), $this->chei->subcheie('audit'));
    }

    public function gdpr(): \Cazo\Gdpr\Gdpr
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Gdpr\Gdpr($this);
    }

    /** Ștergerea datelor oaspetelui, transmisă la EasyCheck. */
    public function gdprEasyCheck(): \Cazo\Gdpr\StergereEasyCheck
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Gdpr\StergereEasyCheck($this);
    }

    public function copii(): \Cazo\Sistem\Copii
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Sistem\Copii($this->baza(), $this->ceas(), $this->privat() . '/copii', self::versiune(), $this->config->calePublic());
    }

    /** Detaliile pe care controlerul le adaugă rândului de audit al cererii panoului în curs (fără date personale). */
    private array $auditDetalii = [];

    public function auditDetalii(array $d): void
    {
        $this->auditDetalii = $d + $this->auditDetalii;
    }

    public function emailuri(): CutieEmail
    {
        return $this->instante[__FUNCTION__] ??= new CutieEmail($this->baza(), $this->ceas(), new Cutie($this->chei->subcheie('pii')),
            fn (): TransportEmail => $this->transportEmail ?? $this->transportEmailDinSetari(),
            function (string $sablon, string $limba, array $date, string $catre): ?\Cazo\Email\Mesaj {
                // ofertele către abonați și proba lor, scrise de gazdă, cu linkul de dezabonare.
                if (\Cazo\Oferte\Trimiteri::alSau($sablon)) {
                    return $this->oferteTrimiteri()->mesaj($sablon, $limba, $date, $catre);
                }
                $s = new Sabloane($this);
                return $s->depasit($sablon, $date) ? null : $s->mesaj($sablon, $limba, $date, $catre);
            },
            $this->jurnal(), max(1, min(1000, (int) $this->config->get('oferte_pe_ora', CutieEmail::OFERTE_PE_ORA))));
    }

    /** abonații la oferte (acordurile de pe site și, cu `functii.abonati`, lista din EasyCheck). */
    public function abonati(): \Cazo\Oferte\Abonati
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Oferte\Abonati($this);
    }

    /** trimiterile ofertelor către abonați, prin cutia de e-mailuri. */
    public function oferteTrimiteri(): \Cazo\Oferte\Trimiteri
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Oferte\Trimiteri($this);
    }

    public function sabloaneEmail(): Sabloane
    {
        return $this->instante[__FUNCTION__] ??= new Sabloane($this);
    }

    /** SMTP-ul din setări (`email.smtp`, parola în secretul `smtp_parola`), altfel mail() al găzduirii. */
    private function transportEmailDinSetari(): TransportEmail
    {
        $smtp = $this->setari()->get('email.smtp');
        if (is_array($smtp) && is_string($smtp['gazda'] ?? null) && $smtp['gazda'] !== '') {
            return new TransportSmtp(['gazda' => $smtp['gazda'], 'port' => (int) ($smtp['port'] ?? 587), 'securitate' => (string) ($smtp['securitate'] ?? 'tls'),
                'utilizator' => (string) ($smtp['utilizator'] ?? '')], $this->secrete()->citeste('smtp_parola'));
        }
        return new TransportSmtp(null, null);
    }

    // ---- plata online ----

    public function stripe(): ContStripe
    {
        $s = $this->config->stripe();
        return $this->instante[__FUNCTION__] ??= new ContStripe($this->baza(), $this->ceas(), $this->secrete(), $this->setari(),
            $this->httpStripe ?? new HttpCurl($s['permite_http']), $this->url()->absolut('/stripe-anunt'), $s['baza']);
    }

    public function checkout(): Checkout
    {
        return $this->instante[__FUNCTION__] ??= new Checkout($this->baza(), $this->ceas(), $this->stripe());
    }

    /** Contestările plăților la bancă. */
    public function contestari(): \Cazo\Plati\Contestari
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Plati\Contestari($this);
    }

    /** Comenzile primite de la EasyCheck: rambursările hotărâte de gazdă acolo (contract-comenzi.md). */
    public function ecComenzi(): \Cazo\EasyCheck\Comenzi
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\EasyCheck\Comenzi($this);
    }

    /** Modurile panoului față de etapa 3 (L, V, E, P): unde se iau deciziile despre bani. */
    public function modEc(): \Cazo\EasyCheck\ModEc
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\EasyCheck\ModEc($this);
    }

    /** Hotărârile gazdei luate în EasyCheck și cererile de anulare trimise acolo. */
    public function hotarariEc(): \Cazo\EasyCheck\HotarariEc
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\EasyCheck\HotarariEc($this);
    }

    /** Mesajele către oaspeți scrise în EasyCheck (`GET /mesaje`, `POST /mesaje/import`). */
    public function mesajeEc(): \Cazo\EasyCheck\MesajeEc
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\EasyCheck\MesajeEc($this);
    }

    /** Rapoartele plăților către EasyCheck (`POST /rezervari/{id}/plati`). */
    public function raportPlati(): \Cazo\EasyCheck\RaportPlati
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\EasyCheck\RaportPlati($this);
    }

    public function rambursari(): Rambursari
    {
        return $this->instante[__FUNCTION__] ??= new Rambursari($this->baza(), $this->ceas(), $this->stripe());
    }

    public function reconciliere(): \Cazo\Plati\Reconciliere
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Plati\Reconciliere($this);
    }

    public function retinere(): \Cazo\Rezervare\Retinere
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Rezervare\Retinere($this);
    }

    public function plataOnline(): PlataOnline
    {
        return $this->instante[__FUNCTION__] ??= new PlataOnline($this);
    }

    public function finalizare(): Finalizare
    {
        return $this->instante[__FUNCTION__] ??= new Finalizare($this);
    }

    public function recuperare(): Recuperare
    {
        return $this->instante[__FUNCTION__] ??= new Recuperare($this);
    }

    /** După rezervare: anularea și rambursările unei rezervări confirmate. */
    public function anulare(): Anulare
    {
        return $this->instante[__FUNCTION__] ??= new Anulare($this);
    }

    /** Schimbarea datelor de către oaspete. */
    public function schimbareDate(): \Cazo\Rezervare\SchimbareDate
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Rezervare\SchimbareDate($this);
    }

    public function modPlata(): ModPlata
    {
        return $this->instante[__FUNCTION__] ??= new ModPlata($this);
    }

    /**
     * Rezervările se pot face acum: cheia permite rezervări, configurarea site-ului e completă, iar
     * întrerupătorul e oprit.
     */
    /**
     * Rezervările s-au oprit fiindcă EasyCheck refuză cheia site-ului (401 confirmat), de exemplu după ce gazda a adus
     * rezervările directe înapoi pe pagina EasyCheck și cheile live s-au revocat: bara,
     * căutarea, rezumatul și comanda spun „Rezervările sunt oprite momentan.”, apoi contactul gazdei.
     */
    public function rezervariOprite(): bool
    {
        return !$this->modLocal() && $this->ecLicenta()->invalidaConfirmata();
    }

    public function rezervariActive(): bool
    {
        return $this->ecLicenta()->stare()['rezervari'] && $this->configurareLipsa() === [] && !$this->intrerupator()->pornit();
    }

    /**
     * Setările obligatorii încă lipsă: până la completarea lor, oaspetele vede doar prezentarea, fără plată
     * online și fără cerere cash (modul de pregătire). Azi: metodele de plată (`metode`) și datele legale
     * cerute înaintea contractului la distanță (`date_legale`: denumirea, CUI, sediul, contactul,
     * BlocLegal::VANZARE) și orele de check-in și check-out confirmate de gazdă în EasyCheck (`ore_neconfirmate`,
     * `GET /proprietate` → `ore.setate_de_gazda` false înseamnă orele implicite 13:00 / 11:00, neconfirmate; fără
     * câmp, de la un EasyCheck mai vechi, orele contează ca fiind confirmate) și ce primește oaspetele când gazda anulează
     * rezervarea (`anulare_gazda`, fără variantă implicită). Asumarea publicării cu date lipsă deschide doar
     * publicarea prezentării. Cheia EasyCheck cu dreptul de rezervare se verifică separat, în licență.
     * @return list<string>
     */
    public function configurareLipsa(): array
    {
        $r = $this->modPlata()->metode() === null ? ['metode'] : [];
        if ((new \Cazo\Legal\BlocLegal($this, $this->traducator('ro'), 'ro'))->lipsaVanzare() !== []) {
            $r[] = 'date_legale';
        }
        $ore = $this->ecOglinda()->corp('proprietate')['ore'] ?? null;
        if (is_array($ore) && array_key_exists('setate_de_gazda', $ore) && $ore['setate_de_gazda'] === false) {
            $r[] = 'ore_neconfirmate';
        }
        // ce primește oaspetele când gazda anulează, fără variantă implicită: întâi alegerea gazdei.
        if (\Cazo\Rezervare\Anulare::gazdaSite($this) === null) {
            $r[] = 'anulare_gazda';
        }
        return $r;
    }

    // ---- prețul și prezentarea ----

    /** Ziua curentă în ora României (AAAA-LL-ZZ). */
    public function azi(): string
    {
        return (new \DateTimeImmutable('@' . $this->ceas()->acum()))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('Y-m-d');
    }

    public function pretNoapte(): PretNoapte
    {
        if (!isset($this->instante[__FUNCTION__])) {
            $p = new PretNoapte($this->baza());
            // reducerile locale în calendar și în „de la” la „Prețul de pe site-ul meu”; la „Prețul din
            // EasyCheck”, o diferență găsită de paritate ascunde sumele pe zi.
            try {
                $sursa = $this->ecLicenta()->cheie()['sursa'] ?? null;
                // fără cheie EasyCheck, prețul e doar cel pe noapte și cel de weekend, fără reduceri.
                if ($sursa === 'site' && !$this->modLocal() && $this->reduceriLocale()->active() !== []) {
                    $p->reduceri = $this->reduceriLocale();
                } elseif ($sursa === 'easycheck') {
                    $p->faraSumePeZi = \Cazo\Pret\Paritate::diferentaDin($this->setari());
                }
            } catch (\Throwable) {
                // Înaintea migrării 0020 sau fără cheie: prețul pe noapte rămâne cel din oglindă.
            }
            $this->instante[__FUNCTION__] = $p;
        }
        return $this->instante[__FUNCTION__];
    }

    public function motorLocal(): MotorLocal
    {
        return $this->instante[__FUNCTION__] ??= new MotorLocal($this->baza(), $this->pretNoapte(), $this->modLocal() ? null : $this->reduceriLocale(), fn (): string => $this->azi());
    }

    // ---- prețul site-ului și datele externe ----

    public function reduceriLocale(): \Cazo\Pret\ReduceriLocale
    {
        // Două reduceri active fără alegerea gazdei: linia din jurnal și alerta, cel mult un e-mail pe zi.
        return $this->instante[__FUNCTION__] ??= new \Cazo\Pret\ReduceriLocale($this->baza(), $this->setari(), $this->ceas(),
            fn (int $n) => $this->alertaGazda('alerta_reduceri_mod', ['reduceri' => $n], 'reduceri_mod', 86400));
    }

    public function paritate(): \Cazo\Pret\Paritate
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Pret\Paritate($this->pretNoapte(), $this->setari(), $this->ceas(),
            fn (array $sejururi): array => (new TotalEasyCheck($this->ecClient(), $this->includeTotal(), $this->taxaIncasare()))->cere($sejururi));
    }

    public function dateExterne(): \Cazo\DateExterne\Depozit
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\DateExterne\Depozit($this->baza(), $this->ceas());
    }

    /**
     * Adresa publică a proiectului (depozitul GitHub), singurul loc unde e scrisă: User-Agent-ul când site-ul gazdei
     * n-are încă o adresă, anunțul versiunii noi, ghidurile generate (bin/site-docs.php) și exportul public o citesc de aici.
     */
    public const ADRESA_PROIECT = 'https://github.com/goldservicesrl/rezervari-directe';

    /**
     * Transportul spre serviciile de date externe (MET Norway, Nominatim, Overpass, BNR). User-Agent-ul numește doar
     * programul, versiunea și adresa site-ului gazdei (politicile OSM și MET), fără e-mail; fără adresa
     * site-ului, adresa publică a proiectului.
     */
    public function httpExtern(): \Cazo\DateExterne\Http
    {
        return $this->httpExtern ??= new \Cazo\DateExterne\HttpCurl(self::agentExtern($this->config->get('site_url', '')));
    }

    /** „cazo/<versiune> (+<adresa site-ului>)”, cu adresa proiectului când site-ul n-are o adresă http(s). */
    public static function agentExtern(mixed $site): string
    {
        $site = rtrim(is_string($site) ? trim($site) : '', '/');
        $bun = preg_match('~^https?://[^\s/?#;()]+(/[^\s?#;()]*)?$~i', $site) === 1;
        return 'cazo/' . self::versiune() . ' (+' . ($bun ? $site : self::ADRESA_PROIECT) . ')';
    }

    public function vreme(): \Cazo\DateExterne\Vreme
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\DateExterne\Vreme($this->dateExterne(), $this->setari(), $this->ceas(),
            fn (): \Cazo\DateExterne\Http => $this->httpExtern(), $this->config->origine());
    }

    public function cursBnr(): \Cazo\DateExterne\CursBnr
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\DateExterne\CursBnr($this->dateExterne(), $this->setari(), $this->ceas(), fn (): \Cazo\DateExterne\Http => $this->httpExtern());
    }

    public function echivalentEuro(): \Cazo\Pret\EchivalentEuro
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Pret\EchivalentEuro($this->cursBnr(), fn (): string => $this->azi());
    }

    public function locuri(): \Cazo\DateExterne\Locuri
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\DateExterne\Locuri($this->dateExterne(), $this->limitator(), fn (): \Cazo\DateExterne\Http => $this->httpExtern());
    }

    /**
     * Dalele hărții din panou, din cache-ul `cazo-privat/cache/dale/`, cu User-Agent-ul care numește aplicația:
     * „Cazo/<versiune> (+https://cazo.ro)”, cum cere politica serverelor de dale OSM, și Referer-ul adresei site-ului.
     */
    public function dale(): \Cazo\DateExterne\Dale
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\DateExterne\Dale($this->privat() . '/cache/dale', $this->limitator(), $this->setari(), $this->ceas(),
            fn (): \Cazo\DateExterne\Http => $this->httpDale ??= new \Cazo\DateExterne\HttpCurl(self::agentDale()), $this->config->origine() . '/');
    }

    /** User-Agent-ul descărcării dalelor. */
    public static function agentDale(): string
    {
        return 'Cazo/' . self::versiune() . ' (+https://cazo.ro)';
    }

    public function repere(): \Cazo\DateExterne\Repere
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\DateExterne\Repere($this->baza(), $this->ceas());
    }

    /** Coordonatele proprietății din oglindă (GET /proprietate), sau null. @return array{lat:float, lng:float}|null */
    public function coordonate(): ?array
    {
        $c = $this->ecOglinda()->corp('proprietate')['coordonate'] ?? null;
        return is_array($c) && is_numeric($c['lat'] ?? null) && is_numeric($c['lng'] ?? null) ? ['lat' => (float) $c['lat'], 'lng' => (float) $c['lng']] : null;
    }

    /** Tema activă cheamă în șabloanele ei funcția sau variabila dată (de exemplu `cazo.vreme(`), citit din fișiere. */
    /**
     * Tema activă arată vremea: cu componenta nucleului (cazo.vreme()) sau cu datele ei, desenate de temă (variabila
     * `vreme`, ca panorama din Montan); de aici atârnă citirea vremii și cardul „Vremea pe site” din panou.
     */
    public function temaFolosesteVremea(): bool
    {
        return $this->temaFoloseste('cazo.vreme(') || $this->temaFoloseste('vreme.zile');
    }

    public function temaFoloseste(string $text): bool
    {
        $k = 'tema_foloseste:' . $text;
        if (!isset($this->instante[$k])) {
            $gasit = false;
            $dir = $this->temaActiva()->dir();
            foreach (['layout', 'sections', 'templates', 'partials'] as $d) {
                foreach (glob($dir . '/' . $d . '/*.twig') ?: [] as $f) {
                    if (str_contains((string) file_get_contents($f), $text)) {
                        $gasit = true;
                        break 2;
                    }
                }
            }
            $this->instante[$k] = $gasit;
        }
        return $this->instante[$k];
    }

    public function total(): Total
    {
        return $this->instante[__FUNCTION__] ??= new Total(
            $this->baza(),
            $this->ceas(),
            $this->pretNoapte(),
            $this->motorLocal(),
            fn (): TotalEasyCheck => new TotalEasyCheck($this->ecClient(), $this->includeTotal(), $this->taxaIncasare()),
            $this->ecLicenta(),
            $this->ecBuget(),
            fn (): array => $this->capacitatiCamere(),
            $this->limitator(),
            fn (): string => $this->reduceriLocale()->versiune() . ':' . $this->azi(),
            fn (): int => $this->noptiMaxSite(),
            fn (): array => $this->includeTotal() + ['incasare' => $this->taxaIncasare()],
        );
    }

    /** Tarifele arătate oaspetelui pentru datele alese: cel mai bun pe fiecare politică de anulare și masă. */
    public function oferte(): \Cazo\Pret\Oferte
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Pret\Oferte($this->pretNoapte(), fn (): array => $this->capacitatiCamere(),
            fn (array $plan, string $sosire): bool => $this->modPlata()->ascuns($plan, $sosire));
    }

    /**
     * Durata maximă a unui sejur rezervat pe site: cât alege gazda, de la o noapte în sus, fără plafon; 0 (câmpul
     * lăsat gol) = fără limită, adică NOPTI_FARA_LIMITA. Implicit 30.
     */
    public const NOPTI_MAX_SITE = 30;
    public const NOPTI_FARA_LIMITA = 3650;

    public function noptiMaxSite(): int
    {
        $v = $this->setari()->get('sejur.nopti_max', self::NOPTI_MAX_SITE);
        if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
            return self::NOPTI_MAX_SITE;
        }
        $v = (int) $v;
        return $v <= 0 ? self::NOPTI_FARA_LIMITA : min(self::NOPTI_FARA_LIMITA, $v);
    }

    /** Gazda a ales „fără limită” (câmpul gol). */
    public function noptiMaxFaraLimita(): bool
    {
        return $this->noptiMaxSite() >= self::NOPTI_FARA_LIMITA;
    }

    /**
     * Ce plătește oaspetele pe site, pe lângă nopți, la „Prețul din EasyCheck”: alegerea gazdei din panou
     * (`pret.include`), declarată lui EasyCheck la fiecare cerere cu total (GET /total, POST /cereri, POST /rezervari).
     * Fără alegere salvată (o instalare nouă): amândouă. @return array{taxe:bool, taxa_locala:bool}
     */
    public function includeTotal(): array
    {
        $v = $this->setari()->get('pret.include');
        return ['taxe' => !is_array($v) || ($v['taxe'] ?? true) !== false, 'taxa_locala' => !is_array($v) || ($v['taxa_locala'] ?? true) !== false];
    }

    /**
     * Când se încasează taxa locală cuprinsă în total, la „Prețul din EasyCheck”: alegerea gazdei din panoul
     * site-ului (`pret.taxa_incasare`), declarată lui EasyCheck la fiecare cerere cu total (`taxa_locala_incasare`).
     * „online” e disponibilă doar cu o plată făcută înaintea sosirii bifată, cu cardul sau prin transfer bancar (la
     * transfer, „online” înseamnă că taxa intră în suma transferului); fără alegere salvată: online cu una dintre ele,
     * altfel la sosire.
     */
    public function taxaIncasare(): string
    {
        if (!$this->modPlata()->incaseazaInainte()) {
            return RegulaPlata::TAXA_LA_SOSIRE;
        }
        return $this->setari()->get('pret.taxa_incasare') === RegulaPlata::TAXA_LA_SOSIRE ? RegulaPlata::TAXA_LA_SOSIRE : RegulaPlata::TAXA_ONLINE;
    }

    /**
     * Ce plătește oaspetele separat, la sosire, în afara totalului de pe site: la „Prețul din EasyCheck”,
     * componentele pe care gazda le-a lăsat în afara totalului, cu suma lor când se știe. Suma vine din motorul
     * local, pe aceeași grilă și aceleași taxe din oglindă (GET /preturi); când motorul n-o poate calcula, null (rândul
     * apare fără sumă); o componentă de 0 lei lipsește. @return array<string,?int> taxe și/sau taxa_locala
     */
    public function inAfaraTotalului(\Cazo\Pret\Sejur $s): array
    {
        if (($this->ecLicenta()->cheie()['sursa'] ?? null) !== 'easycheck') {
            return [];
        }
        $inc = $this->includeTotal();
        if ($inc['taxe'] && $inc['taxa_locala']) {
            return [];
        }
        $comp = null;
        try {
            // Componentele exacte, la ban (fără totalul rotund la leu): sumele acestea stau în afara totalului.
            $r = $this->motorLocal()->total($s, $this->capacitatiCamere(), false);
            $comp = ($r['stare'] ?? null) === 'ok' ? (array) $r['componente'] : null;
        } catch (\Throwable) {
        }
        $rez = [];
        foreach (['taxe', 'taxa_locala'] as $k) {
            if ($inc[$k]) {
                continue;
            }
            $v = $comp[$k] ?? null;
            if ($v === 0) {
                continue;
            }
            $rez[$k] = is_int($v) && $v > 0 ? $v : null;
        }
        return $rez;
    }

    /** Varianta aleasă la „Taxa locală” în „Ce plătește oaspetele pe site”: afara, online sau la_sosire. */
    public function taxaLocalaAleasa(): string
    {
        return $this->includeTotal()['taxa_locala'] ? $this->taxaIncasare() : 'afara';
    }

    /**
     * „Ce plătește oaspetele pe site”: bifa taxelor suplimentare și varianta taxei locale (`afara`: în afara
     * totalului; `online`: în total, plătită odată cu rezervarea; `la_sosire`: în total, plătită la sosire). „online”
     * fără plata cu cardul sau prin transfer bifată lasă momentul nesalvat (la sosire acum, online din clipa în care gazda
     * bifează una dintre ele).
     * O variantă necunoscută: nimic salvat, false.
     */
    public function alegeCePlateste(bool $taxe, string $taxaLocala, ?int $utilizator = null): bool
    {
        if (!in_array($taxaLocala, ['afara', RegulaPlata::TAXA_ONLINE, RegulaPlata::TAXA_LA_SOSIRE], true)) {
            return false;
        }
        $inainte = [$this->includeTotal(), $this->taxaIncasare()];
        if ($taxaLocala !== 'afara') {
            $this->setari()->pune('pret.taxa_incasare', $taxaLocala === RegulaPlata::TAXA_LA_SOSIRE ? RegulaPlata::TAXA_LA_SOSIRE
                : ($this->modPlata()->incaseazaInainte() ? RegulaPlata::TAXA_ONLINE : null), $utilizator);
        }
        $this->alegeIncludeTotal($taxe, $taxaLocala !== 'afara', $utilizator);
        if ($inainte !== [$this->includeTotal(), $this->taxaIncasare()]) {
            $this->ecOglinda()->schimbaContinutul();
        }
        return true;
    }

    /** Alegerea gazdei; totalurile afișate și paginile ținute minte se refac. */
    public function alegeIncludeTotal(bool $taxe, bool $taxaLocala, ?int $utilizator = null): void
    {
        $nou = ['taxe' => $taxe, 'taxa_locala' => $taxaLocala];
        $schimbat = $nou !== $this->includeTotal();
        $this->setari()->pune('pret.include', $nou, $utilizator);
        if ($schimbat) {
            $this->ecOglinda()->schimbaContinutul();
        }
    }

    /** Câți oaspeți încap în fiecare cameră (GET /camere, `oaspeti_max`), după id. @return array<string,?int> */
    public function capacitatiCamere(): array
    {
        $r = [];
        foreach ((array) ($this->ecOglinda()->corp('camere')['camere'] ?? []) as $c) {
            if (is_array($c) && is_string($c['id'] ?? null)) {
                $r[$c['id']] = is_int($c['oaspeti_max'] ?? null) ? $c['oaspeti_max'] : null;
            }
        }
        return $r;
    }

    public function active(): Active
    {
        return $this->instante[__FUNCTION__] ??= new Active(self::radacina(), $this->config->calePublic(), $this->privat(), self::versiune());
    }

    /**
     * Tema activă: setarea `tema.activa` (implicit Studio, Tema::IMPLICITA), o temă oficială a lansării, o temă
     * privată permisă de config.php (`teme_private`; poate sta și în cazo-privat/teme/<slug>, neatinsă de
     * actualizări) sau o temă terță instalată (Catalog); altfel Studio, apoi _schelet.
     */
    public function temaActiva(): Tema
    {
        if ($this->temaFixa !== null) {
            return $this->temaFixa;
        }
        if (!isset($this->instante[__FUNCTION__])) {
            $slug = $this->temaPrevizualizata ?? (string) $this->setari()->get('tema.activa', Tema::IMPLICITA);
            $this->instante[__FUNCTION__] = $this->catalog()->tema($slug) ?? $this->catalog()->tema(Tema::IMPLICITA) ?? $this->temaSchelet();
        }
        return $this->instante[__FUNCTION__];
    }

    /** Temele pe care site-ul le poate folosi: oficiale (fără cele private nepermise) și terțe instalate. */
    public function catalog(): Catalog
    {
        return $this->instante[__FUNCTION__] ??= new Catalog($this);
    }

    public function temeTerte(): TemeTerte
    {
        return $this->instante[__FUNCTION__] ??= new TemeTerte($this);
    }

    /** Uită tema activă calculată (după activare sau la previzualizare, în aceeași cerere). */
    public function uitaTemaActiva(): void
    {
        unset($this->instante['temaActiva']);
    }

    /** Catalogul temelor pe care gazda le poate alege: oficiale, fără cele private nepermise (Catalog::oficiale). @return list<string> */
    public function catalogTeme(): array
    {
        return array_map(static fn (Tema $t): string => $t->slug(), $this->catalog()->oficiale());
    }

    /** Biblioteca _schelet, folosită și ca temă de rezervă când tema activă eșuează. */
    public function temaSchelet(): Tema
    {
        return $this->instante[__FUNCTION__] ??= Tema::din(self::radacina() . '/teme/_schelet', self::radacina() . '/teme/_schelet');
    }

    /** Alegerile gazdei pe temă (teme_setari): valorile setărilor și compoziția. */
    public function temaSetari(string $slug): array
    {
        $r = $this->baza()->rand('SELECT valori, compozitie, versiune FROM {teme_setari} WHERE tema = ?', [$slug]);
        if ($r === null) {
            return ['valori' => [], 'compozitie' => [], 'versiune' => 0];
        }
        return ['valori' => json_decode((string) $r['valori'], true) ?: [], 'compozitie' => json_decode((string) $r['compozitie'], true) ?: [], 'versiune' => (int) $r['versiune']];
    }

    public function modelVedere(string $limba): ModelVedere
    {
        return $this->instante[__FUNCTION__ . $limba] ??= new ModelVedere($this->baza(), $this->ecOglinda(), $this->ecPoze(), $this->pretNoapte(), $this->url(),
            $this->traducator($limba), $this->azi(), $this->ecLicenta()->stare()['valida'], \Cazo\Legal\BlocLegal::accesibilitate($this), fn (): ?array => $this->sigla()->pentruTema());
    }

    /** Sigla site-ului: a site-ului, în ambele moduri (cu EasyCheck și fără cheie). */
    public function sigla(): \Cazo\Continut\Sigla
    {
        return $this->instante[__FUNCTION__] ??= new \Cazo\Continut\Sigla($this->setari(), $this->config->calePublic());
    }

    public function randare(): Randare
    {
        return $this->instante[__FUNCTION__] ??= new Randare($this);
    }

    /** Lucrul de după răspuns (porneste.php îl rulează după închiderea conexiunii). */
    public function dupaRaspuns(callable $f): void
    {
        $this->dupaRaspuns[] = $f;
    }

    /** @return list<callable(): void> */
    public function lucruDupaRaspuns(): array
    {
        return $this->dupaRaspuns;
    }

    /** Rulează și golește lucrul de după răspuns (testele, care rămân în același proces). */
    public function ruleazaDupaRaspuns(): void
    {
        while (($f = array_shift($this->dupaRaspuns)) !== null) {
            $f();
        }
    }

    /** Joburile pornite de un anunț: rulatorul cu sursa „anunt” (15 s), citirile EasyCheck cu timeout-ul de 2 s. */
    public function ruleazaInCaleaAnuntului(): array
    {
        $this->caleaAnuntului = true;
        try {
            $r = $this->rulator()->ruleaza('anunt');
            // E-mailurile puse în cutie de anunț (confirmarea după `cerere.acceptata`) pleacă pe loc: sarcina
            // `email:trimite` poate să nu fie scadentă în rularea asta (a rulat în ultimul minut), iar fără cron
            // următoarea rulare vine abia la o vizită. Era și cauza unei picări rare a testului „gata când: cerere →
            // proba-accepta → e-mail”: când anunțul făcea tranziția după ce panoul își trimisese deja cutia.
            try {
                if ($this->emailuri()->inAsteptare() > 0) {
                    $this->emailuri()->trimite(5);
                }
            } catch (\Throwable $e) {
                $this->jurnal()->avertisment('Cutia de e-mailuri după anunț', ['eroare' => $e->getMessage()]);
            }
            return $r;
        } finally {
            $this->caleaAnuntului = false;
        }
    }

    /** noindex: până la „Publică site-ul” și pe o cheie EasyCheck de probă. */
    public function noindex(): bool
    {
        try {
            $s = $this->setari();
            return $s->get('site.publicat', false) !== true || (($s->get('ec.cheie')['mod'] ?? null) === 'proba');
        } catch (\Throwable) {
            return true;
        }
    }

    public function url(): Url
    {
        return $this->instante[__FUNCTION__] ??= new Url($this->config);
    }

    public function router(): Router
    {
        return $this->instante[__FUNCTION__] ??= new Router($this->rute ?? Rute::toate(), $this->config->limbi(), $this->config->limbaImplicita());
    }

    public function traducator(string $limba, string $domeniu = ''): Traducator
    {
        if (!in_array($limba, $this->config->limbi(), true)) {
            $limba = $this->config->limbaImplicita();
        }
        $t = Traducator::pentru(self::radacina() . '/limbi', $limba, $domeniu, $this->config->limbaImplicita());
        // și textele panoului (cu e-mailurile către gazdă) spun „apartamentul” la o proprietate cu un singur apartament.
        // fără cheie EasyCheck, textele care trimit gazda în EasyCheck au varianta `_local`, cu pasul făcut în site.
        $v = in_array($domeniu, ['', 'admin'], true) ? $this->varianteText() : [];
        if ($domeniu === 'admin' && $this->modLocal()) {
            array_unshift($v, '_local');
        }
        return $v !== [] ? $t->cuVariante($v) : $t;
    }

    /**
     * Variantele de text ale site-ului după felul proprietății: „_apartament” când proprietatea are o
     * singură unitate și e de tip apartament în EasyCheck (`GET /proprietate` › `tip`), „_unica” când are o singură
     * unitate, de orice fel (`GET /camere`: o cameră cu `unitati` 1). Textele care au aceste forme spun atunci
     * „apartamentul” în loc de „camera” și nu trimit oaspetele spre „încă o cameră”.
     * @return list<string>
     */
    public function varianteText(): array
    {
        if (isset($this->instante[__FUNCTION__])) {
            return $this->instante[__FUNCTION__];
        }
        $v = [];
        try {
            $camere = array_values(array_filter((array) ($this->ecOglinda()->corp('camere')['camere'] ?? []), 'is_array'));
            if (count($camere) === 1 && (int) ($camere[0]['unitati'] ?? 1) === 1) {
                if (($this->ecOglinda()->corp('proprietate')['tip'] ?? null) === 'apartament') {
                    $v[] = '_apartament';
                }
                $v[] = '_unica';
            }
        } catch (\Throwable) {
            // Înaintea instalării sau cu baza indisponibilă: textele obișnuite.
            return [];
        }
        return $this->instante[__FUNCTION__] = $v;
    }

    /** Joburile modulelor; fiecare etapă își adaugă aici executanții. */
    private function inregistreazaJoburi(Coada $coada): void
    {
        // Anunțurile EasyCheck: zece anunțuri = un job, care citește resursele învechite.
        // Răspunsul gazdei la cererile cash, după un anunț `cerere.*`, în calea anunțului.
        $coada->inregistreaza(CerereCash::JOB, function (): void {
            $this->cerereCash()->urmareste(true, null, 'webhook_ec');
        });
        // Rezervările care așteaptă transferul, după un anunț `rezervare.plata_*` sau `rezervare.schimbata`.
        $coada->inregistreaza(\Cazo\Rezervare\Transfer::JOB, function (): void {
            $this->transfer()->urmareste(true, null, 'webhook_ec');
        });
        $coada->inregistreaza(Anulare::JOB, function (): void {
            $r = $this->anulare()->sincronizeaza('webhook_ec');
            if ($r['ocupate'] > 0) {
                throw new \RuntimeException('O comandă era ocupată; lista rezervărilor se reia.');
            }
        });
        // Schimbările de date: pașii scadenți, după un anunț `cerere.*` sau după anularea rezervării vechi văzută în
        // sincronizare (118d).
        $coada->inregistreaza(\Cazo\Rezervare\SchimbareDate::JOB, function (array $date = []): void {
            if (($date['cerere'] ?? false) === true) {
                $this->schimbareDate()->dupaAnuntCerere();
                return;
            }
            $this->schimbareDate()->ruleaza();
        });
        // șabloanele mesajelor, după anunțul `mesaje.schimbate` (citirea și importul, ca sarcina `ec:mesaje`).
        $coada->inregistreaza(\Cazo\EasyCheck\MesajeEc::JOB, function (): void {
            $this->mesajeEc()->sarcina();
        });
        $coada->inregistreaza(Anunturi::JOB, function (): void {
            $r = $this->ecSincronizare()->ruleaza($this->ecOglinda()->invechite(), 15.0, $this->caleaAnuntului);
            if (!empty($r['ocupat'])) {
                throw new \RuntimeException('Sincronizarea rulează deja; anunțul se reia.');
            }
        });
    }

    /** Rularea curentă a lăsat poze de prelucrat: după eliberarea zăvorului pornește lanțul (lantCron). */
    private bool $lantCerut = false;

    /**
     * Prelucrează pozele care așteaptă, cel mult $buget secunde. După o rulare cu poze derivate, paginile din
     * cache se refac (generația nouă a cache-ului); cât mai rămân poze, sarcina se reia fără așteptarea intervalului,
     * următoarea vizită pornește pseudo-cronul imediat, iar pe o găzduire fără cron site-ul își cere singur rularea
     * următoare (lantCron). Folosită de sarcina `poze:prelucreaza` și de prima sincronizare din instalator.
     * @return array{derivate:int, erori:int, ramase:int} ramase: pozele care se mai pot prelucra acum
     */
    public function prelucreazaPoze(float $buget = 6.0): array
    {
        $r = $this->ecPoze()->prelucreaza(60, $buget);
        if ($r['derivate'] > 0) {
            $this->ecOglinda()->schimbaContinutul();
        }
        $r['ramase'] = $this->ecPoze()->deFacutAcum();
        if ($r['ramase'] > 0) {
            $this->planificator()->reiaImediat('poze:prelucreaza');
            $this->pseudoCron()->grabeste();
            $this->lantCerut = true;
        }
        return $r;
    }

    /** Cel mult atâtea verigi ale lanțului într-o fereastră de 10 minute (o poză care tot eșuează oprește lanțul). */
    public const LANT_MAXIM = 40;

    /**
     * Lanțul cronului: site-ul își cere singur, fără să aștepte răspunsul, o rulare a cronului extern
     * (`/_cron/<token>`), ca lucrul rămas să continue și fără vizitatori și fără cron. Doar pe un server web obișnuit
     * (în linia de comandă rulează cronul adevărat, iar serverul de dezvoltare al PHP are un singur proces), cu cURL,
     * cu tokenul cronului și cu `lant_cron` lăsat pornit în config.php. Întoarce true când cererea a plecat.
     */
    public function lantCron(?\Closure $trimite = null): bool
    {
        if ($this->config->get('lant_cron', true) === false || ($trimite === null && (in_array(PHP_SAPI, ['cli', 'cli-server', 'phpdbg'], true) || !function_exists('curl_init') || !function_exists('curl_exec')))) {
            return false;
        }
        $token = $this->secrete()->citeste('cron_token');
        if ($token === null) {
            return false;
        }
        $acum = $this->ceas()->acum();
        $l = $this->setari()->proaspat('cron.lant');
        $l = is_array($l) && is_int($l['de_la'] ?? null) && $acum - $l['de_la'] < 600 ? $l : ['de_la' => $acum, 'n' => 0];
        if ((int) $l['n'] >= self::LANT_MAXIM) {
            return false;
        }
        $this->setari()->pune('cron.lant', ['de_la' => $l['de_la'], 'n' => (int) $l['n'] + 1]);
        // veriga lanțului se anunță (`?lant=1`), ca rularea ei să nu fie socotită a cronului din cPanel.
        $adresa = $this->url()->absolut('/_cron/' . $token) . '?lant=1';
        if ($trimite !== null) {
            $trimite($adresa);
            return true;
        }
        // Răspunsul vine abia la sfârșitul rulării; cererea doar o pornește, deci se renunță repede la așteptare.
        $optiuni = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOSIGNAL => true, CURLOPT_CONNECTTIMEOUT_MS => 1500,
            CURLOPT_TIMEOUT_MS => 1500, CURLOPT_USERAGENT => 'Cazo'];
        $h = function_exists('curl_init') ? curl_init($adresa) : false;
        if ($h === false) {
            return false;
        }
        curl_setopt_array($h, $optiuni);
        if (function_exists('curl_exec')) {
            curl_exec($h);
        }
        curl_close($h);
        return true;
    }

    /** Sarcinile periodice, cu prioritățile lucrărilor de fundal. */
    private function inregistreazaSarcini(Planificator $p): void
    {
        // Plata online: doar pe site-urile care o folosesc (Stripe configurat, metodele alese sau comenzi cu cardul),
        // ca un site doar cu cereri cash să nu facă scrieri în plus la fiecare rulare.
        if ($this->plataOnlineFolosita()) {
            $this->inregistreazaSarciniPlati($p);
        }
        // Oglinda EasyCheck: resursele învechite și cele scadente după intervalul lor de citire (prioritatea 3).
        $p->inregistreaza('ec:sincronizare', 60, 3, function (): string {
            if ($this->cheieEc() === null) {
                return 'fără cheie EasyCheck';
            }
            // Anunțurile rămase neprocesate marchează întâi resursele lor ca învechite.
            $recuperate = $this->ecAnunturi()->recupereaza();
            $r = $this->ecSincronizare()->ruleaza(null, 10.0);
            return ($recuperate > 0 ? "anunțuri recuperate: $recuperate, " : '') . 'citite: ' . json_encode($r['citite'], JSON_UNESCAPED_UNICODE) . ($r['erori'] !== [] ? ', erori: ' . implode(', ', array_keys($r['erori'])) : '')
                . ($r['amanate'] !== [] ? ', amânate: ' . implode(', ', $r['amanate']) : '');
        });
        // fără cheie EasyCheck, calendarele iCal puse de gazdă se citesc la fiecare N minute (15 implicit), cu
        // cronul din cPanel sau cu vizitele; nopțile găsite se închid pe site.
        if ($this->modLocal()) {
            $p->inregistreaza('local:ical', 60 * \Cazo\Local\Calendare::interval($this->setari()), 3, fn (): string => $this->localIcal()->sarcina());
        }
        // după trecerea pe EasyCheck, rezervările viitoare făcute local pleacă o singură dată spre EasyCheck;
        // cele refuzate de rețea se reiau, iar cererile trimise se urmăresc până la răspunsul gazdei.
        if (!$this->modLocal() && $this->localTrecere()->areLucru()) {
            $p->inregistreaza('local:trecere', 300, 3, fn (): string => $this->localTrecere()->sarcina());
        }
        // Cererile cash (prioritatea 2): retrimiterile scadente, răspunsurile gazdei, comenzile cu codul neconfirmat.
        // Starea site-ului spre EasyCheck: declarația când e de trimis și starea la schimbare ori la 15 minute. Cheamă
        // EasyCheck doar atunci; în rest, o citire a alertelor de acum.
        $p->inregistreaza('ec:stare', 60, 3, fn (): string => $this->stareSite()->sarcina());
        // Rapoartele plăților către EasyCheck (prioritatea 2): doar cu `functii.plati` pornit în EasyCheck și pe
        // site-urile cu rezervări plătite cu cardul în EasyCheck.
        if ($this->platiCuCard()) {
            $p->inregistreaza('ec:plati', 60, 2, fn (): string => $this->raportPlati()->sarcina());
            // comenzile EasyCheck (rambursările hotărâte de gazdă acolo), cu funcția aprinsă și cât rămâne ceva de încheiat.
            $p->inregistreaza('ec:comenzi', 60, 1, fn (): string => $this->ecComenzi()->sarcina());
            // cererile de anulare ale oaspeților rămase netrimise la EasyCheck (rețeaua, 5xx); doar plățile cu cardul le au.
            $p->inregistreaza('ec:cereri_anulare', 60, 2, function (): string {
                if ($this->modLocal() || $this->baza()->valoare("SELECT 1 FROM {comenzi} WHERE stare = 'anulare_ceruta' AND ec_cerere_stare = 'de_trimis' LIMIT 1") === null) {
                    return 'nicio cerere de trimis';
                }
                return 'încercate: ' . $this->hotarariEc()->reiaCereri();
            });
        }
        // Mesajele către oaspeți scrise în EasyCheck (prioritatea 3): doar cu cheie; citirea o dată pe zi, la anunț
        // sau la plasa stării, apoi importul paragrafelor. Fără `functii.mesaje`, nicio cerere.
        if (!$this->modLocal() && $this->cheieEc() !== null) {
            $p->inregistreaza(\Cazo\EasyCheck\MesajeEc::JOB, 60, 3, fn (): string => $this->mesajeEc()->sarcina());
        }
        // Trecerea pe subdomeniul rezervat (Setări › Adresa site-ului): adresa nouă verificată la fiecare rulare, până răspunde.
        $p->inregistreaza('site:adresa', 60, 4, fn (): string => $this->adresa()->sarcina());
        $p->inregistreaza('cereri:cash', 60, 2, function (): string {
            if ($this->baza()->valoare("SELECT 1 FROM {comenzi} WHERE fel = 'cash' AND stare IN ('noua', 'trimitere_ec', 'cerere_in_asteptare') LIMIT 1") === null) {
                return 'nicio cerere în lucru';
            }
            $trimise = [];
            foreach ($this->cerereCash()->deRetrimis() as $id) {
                $trimise[] = $this->cerereCash()->trimite($id, 'cron', false);
            }
            $u = $this->cerereCash()->urmareste();
            $ab = $this->cerereCash()->abandoneaza();
            return 'retrimise: ' . count($trimise) . ', citite: ' . $u['citite'] . ', schimbate: ' . $u['schimbate'] . ', abandonate: ' . $ab;
        });
        // Transferul bancar (prioritatea 2): retrimiterile, citirile scadente și cea de după termen (plasa de
        // siguranță), reamintirea, anulările oaspeților rămase fără răspuns, comenzile cu codul neconfirmat. Doar pe
        // site-urile care îl folosesc, ca celelalte să rămână fără scrieri în plus la fiecare rulare.
        if ($this->transferFolosit()) {
            $p->inregistreaza('rezervari:transfer', 60, 2, function (): string {
                if (!$this->transfer()->areLucru()) {
                    return 'niciun transfer în lucru';
                }
                $r = $this->transfer()->ruleaza();
                return "retrimise: {$r['retrimise']}, citite: {$r['citite']}, schimbate: {$r['schimbate']}, reamintiri: {$r['reamintiri']}, anulări: {$r['anulari']}, abandonate: {$r['abandonate']}";
            });
        }
        // După rezervare: doar pe site-urile cu rezervări în EasyCheck, ca un site nou să rămână fără scrieri în plus.
        if ($this->rezervariFacute()) {
            $this->inregistreazaSarciniAnulari($p);
        }
        // dezabonările de trimis la EasyCheck (`POST /abonati/dezabonare`), reluate până trec (prioritatea 4).
        if ($this->abonati()->areDeTrimis()) {
            $p->inregistreaza('oferte:dezabonari', 300, 4, fn (): string => $this->abonati()->sarcina());
        }
        // Cutia de e-mailuri: cel mult 20 pe rulare (prioritatea 4).
        $p->inregistreaza('email:trimite', 60, 4, function (): string {
            if ($this->emailuri()->inAsteptare() === 0) {
                return 'cutia e goală';
            }
            $r = $this->emailuri()->trimite();
            return "trimise: {$r['trimise']}, eșuate: {$r['esuate']}";
        });
        // Pozele (prioritatea 5): câte încap în timpul rămas al rulării, cu reluare imediată cât mai sunt.
        $p->inregistreaza('poze:prelucreaza', 60, 5, function (?float $ramas = null): string {
            if ($this->ecPoze()->inAsteptare() === 0) {
                return 'nimic de prelucrat';
            }
            $r = $this->prelucreazaPoze(max(1.0, ($ramas ?? 6.0) - 1.0));
            return "derivate: {$r['derivate']}, erori: {$r['erori']}, rămase: {$r['ramase']}";
        });
        $this->inregistreazaSarciniPretDate($p);
        // GDPR (20): anonimizarea la termen, doar după alegerea termenelor; orară, pentru termenul în ore.
        if ($this->gdprActiv()) {
            $p->inregistreaza('gdpr:anonimizare', 3600, 7, function (): string {
                $r = $this->gdpr()->anonimizeazaLaTermen();
                return $r === null ? 'termenele nu sunt alese' : "comenzi: {$r['comenzi']}, IP-uri: {$r['ip']}";
            });
        }
        // Ștergerile cerute de oaspeți, transmise la EasyCheck: reluările scadente (prioritatea 2), doar cât
        // există trimiteri în lucru, ca un site fără ștergeri să rămână fără sarcina asta.
        if ($this->stergeriDeTrimis()) {
            $this->inregistreazaSarcinaGdprEc($p);
        }
        // Copia zilnică a bazei (14 Sistem), pe site-urile cu panoul pornit.
        if ($this->arePanou()) {
            $p->inregistreaza('copii:zilnic', 86400, 9, function (): string {
                return 'copia: ' . $this->copii()->creeaza('zilnica');
            });
            // Verificarea zilnică a lanțului de audit; un lanț rupt apare în alertele panoului.
            $p->inregistreaza('audit:verificare', 86400, 9, function (): string {
                $v = $this->audit()->verifica();
                $this->setari()->pune('audit.verificare', $v + ['la' => $this->ceas()->acum()]);
                return $v['ok'] ? 'lanțul e întreg: ' . $v['verificate'] : 'lanțul e rupt la ' . $v['rupt_la'];
            });
        }
        // Actualizările: doar citirea zilnică a versiunii noi de pe cazo.ro, pentru anunțul din panou și e-mail.
        $p->inregistreaza('actualizari', 86400, 9, function (): string {
            return $this->versiuneNoua()->verifica(self::versiune());
        });
        $p->inregistreaza('curatenie', 3600, 7, function (): string {
            $limite = $this->limitator()->curata();
            $zavoare = $this->zavor()->curataExpirate();
            $jurnale = $this->jurnal()->curata();
            $anunturi = $this->ecAnunturi()->curata();
            $coduri = $this->codEmail()->curata();
            $sesiuni = $this->sesiuneAdmin()->curata();
            $stripe = $this->stripe()->curata();
            $this->utilizatori()->curata();
            // Cache-ul dalelor hărții din panou, o dată pe zi.
            $this->dale()->curata();
            // Copiile mai vechi de 7 zile, și când nu se mai face nicio copie nouă.
            $this->copii()->curataVechi();
            // Depozitul media al copiilor se completează în pași mici, cu bugetul de timp al curățeniei (M6b).
            $this->copii()->copiazaMedia(5.0);
            // Pozele EasyCheck cu fișierele lipsă se descarcă din nou, și fără o schimbare în EasyCheck (M6b).
            if ($this->ecPoze()->verificaFisiere(5.0) > 0) {
                $this->planificator()->grabeste('poze:prelucreaza');
            }
            // Cheia EasyCheck anterioară dispare după cele 24 de ore ale rotirii (M6b).
            $rot = $this->setari()->get('ec.rotire');
            if (is_array($rot) && is_int($rot['la'] ?? null) && $this->ceas()->acum() - $rot['la'] > 86400 && $this->secrete()->citeste('ec_cheie_anterioara') !== null) {
                $this->secrete()->sterge('ec_cheie_anterioara');
            }
            // Jurnalul alertelor din panou: cât a ales gazda, de la început 365 de zile.
            $this->baza()->executa('DELETE FROM {alerte} WHERE la < ?', [$this->ceas()->moment(-$this->gdpr()->alerteZile() * 86400)]);
            // Codul pornit de întrerupător se oprește după 24 de ore liniștite, verificat la fiecare oră.
            $cod = $this->intrerupator()->verificaCodul() ? ', codul de 6 cifre oprit' : '';
            return "limite: $limite, zăvoare: $zavoare, jurnale: $jurnale, anunțuri: $anunturi, coduri: $coduri, sesiuni: $sesiuni, anunțuri Stripe: $stripe$cod";
        });
    }

    private function gdprActiv(): bool
    {
        try {
            return $this->setari()->get(\Cazo\Gdpr\Gdpr::CHEIE) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function arePanou(): bool
    {
        try {
            return $this->baza()->valoare("SELECT 1 FROM {utilizatori} WHERE parola_hash <> '' LIMIT 1") !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Site-ul folosește transferul bancar: metoda e bifată sau există comenzi cu transfer în bază. */
    private function transferFolosit(): bool
    {
        try {
            return $this->modPlata()->are('transfer') || $this->baza()->valoare("SELECT 1 FROM {comenzi} WHERE fel = 'transfer' LIMIT 1") !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Site-ul are rezervări trimise în EasyCheck (confirmate, anulate sau cu o anulare în lucru): sarcinile de după rezervare au rost. */
    private function rezervariFacute(): bool
    {
        try {
            return $this->baza()->valoare('SELECT 1 FROM {comenzi} WHERE ec_rezervare IS NOT NULL LIMIT 1') !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Site-ul are rezervări plătite cu cardul ajunse în EasyCheck: rapoartele plăților au rost. */
    private function platiCuCard(): bool
    {
        try {
            return $this->baza()->valoare("SELECT 1 FROM {comenzi} WHERE fel = 'card' AND ec_rezervare IS NOT NULL LIMIT 1") !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function stergeriDeTrimis(): bool
    {
        try {
            return $this->baza()->valoare("SELECT 1 FROM {gdpr_easycheck} WHERE stare = 'in_lucru' LIMIT 1") !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Trimiterea ștergerilor la EasyCheck. */
    private function inregistreazaSarcinaGdprEc(Planificator $p): void
    {
        $p->inregistreaza('gdpr:easycheck', 60, 2, function (): string {
            if (!$this->gdprEasyCheck()->areLucru()) {
                return 'nicio ștergere de trimis';
            }
            $r = $this->gdprEasyCheck()->ruleaza();
            return "șterse: {$r['facute']}, rămase gazdei: {$r['de_facut']}, amânate: {$r['amanate']}";
        });
    }

    /** Sarcinile de după rezervare. */
    private function inregistreazaSarciniAnulari(Planificator $p): void
    {
        // După rezervare (prioritatea 1, banii în mișcare): anulările de trimis, rambursările lor, cele din panou.
        $p->inregistreaza('rezervari:anulari', 60, 1, function (): string {
            if (!$this->anulare()->areLucru()) {
                return 'nicio anulare în lucru';
            }
            $r = $this->anulare()->ruleaza();
            return "trimise: {$r['trimise']}, rambursate: {$r['rambursate']}, din panou: {$r['panou']}, reamintiri: {$r['reamintiri']}";
        });
        // Schimbările de date (118-118d, prioritatea 1, banii și rezervările în mișcare).
        $p->inregistreaza('rezervari:schimbari', 60, 1, function (): string {
            if (!$this->schimbareDate()->areLucru()) {
                return 'nicio schimbare în lucru';
            }
            $r = $this->schimbareDate()->ruleaza();
            return "plăți: {$r['plati']}, pași: {$r['pasi']}, rambursări: {$r['rambursari']}";
        });
        // Rezervările site-ului schimbate în EasyCheck (la 15 minute), pentru anulările gazdei.
        $p->inregistreaza('rezervari:sincronizare', 900, 3, function (): string {
            if ($this->cheieEc() === null) {
                return 'fără cheie EasyCheck';
            }
            $r = $this->anulare()->sincronizeaza();
            return "citite: {$r['citite']}, anulate: {$r['anulate']}, ocupate: {$r['ocupate']}";
        });
    }

    /** Site-ul folosește plata online: Stripe configurat, metodele alese de gazdă sau comenzi cu cardul în bază. */
    private function plataOnlineFolosita(): bool
    {
        try {
            return $this->secrete()->citeste('stripe_cheie') !== null || $this->setari()->get('plati.metode') !== null
                || $this->baza()->valoare("SELECT 1 FROM {comenzi} WHERE fel = 'card' LIMIT 1") !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Sarcinile datele externe ale temelor (vremea de la MET Norway, cel mult o dată pe oră și
     * după antetul Expires, doar când tema activă o arată; cursul BNR zilnic, doar cu echivalentul în euro pornit), apoi paritatea nocturnă
     * la „Prețul din EasyCheck”. O schimbare vizibilă (date noi sau ascunse pentru vechime) schimbă generația
     * cache-ului de pagini.
     */
    private function inregistreazaSarciniPretDate(Planificator $p): void
    {
        $p->inregistreaza('date_externe', 900, 6, function (): string {
            $rez = [];
            $schimbat = false;
            $coord = $this->coordonate();
            $cuVreme = $this->temaFolosesteVremea();
            if ($cuVreme && $coord !== null && $this->vreme()->pornita() && $this->vreme()->scadenta()) {
                $schimbat = $this->vreme()->citeste($coord['lat'], $coord['lng']) || $schimbat;
                $rez[] = 'vremea citită';
            }
            if ($this->cursBnr()->pornit()) {
                $ora = (new \DateTimeImmutable('@' . $this->ceas()->acum()))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('H:i');
                if ($this->cursBnr()->scadenta($this->azi(), $ora)) {
                    $schimbat = $this->cursBnr()->citeste() || $schimbat;
                    $rez[] = 'cursul BNR citit';
                }
            }
            // Vizibilitatea (datele vechi se ascund) și ziua (răsăritul și apusul) intră în paginile din cache.
            $vizibil = [$this->vreme()->date() !== null, $this->cursBnr()->pornit() ? ($this->cursBnr()->curs($this->azi())['data'] ?? null) : null,
                $cuVreme || $this->temaFoloseste('soare') ? $this->azi() : null];
            if ($this->setari()->get('date_externe.vizibil', [false, null, null]) !== $vizibil) {
                $this->setari()->pune('date_externe.vizibil', $vizibil);
                $schimbat = true;
            }
            if ($schimbat) {
                $this->ecOglinda()->schimbaContinutul();
            }
            $osm = $this->dateExterne()->curata('osm:', 30 * 86400);
            return ($rez === [] ? 'nimic de citit' : implode(', ', $rez)) . ($schimbat ? ', paginile se reîmprospătează' : '') . ($osm > 0 ? ", căutări vechi șterse: $osm" : '');
        });
        $p->inregistreaza('paritate', 86400, 8, function (): string {
            if (($this->ecLicenta()->cheie()['sursa'] ?? null) !== 'easycheck' || !$this->ecLicenta()->stare()['valida']) {
                return 'doar la „Prețul din EasyCheck”';
            }
            $r = $this->paritate()->verifica($this->capacitatiCamere(), $this->azi());
            if ($r['schimbat']) {
                $this->ecOglinda()->schimbaContinutul();
            }
            if ($r['diferente'] !== []) {
                $d = $r['diferente'][0];
                $this->alertaGazda('alerta_paritate', ['sosire' => $d['sosire'], 'plecare' => $d['plecare'], 'local_bani' => $d['local'], 'easycheck_bani' => $d['easycheck']], 'paritate', 86400);
            }
            return "verificate: {$r['verificate']}, diferențe: " . count($r['diferente']);
        });
    }

    /** Sarcinile plății online. */
    private function inregistreazaSarciniPlati(Planificator $p): void
    {
        // Banii în mișcare (prioritatea 1): reconcilierea, finalizarea, recuperarea, rambursările.
        $p->inregistreaza('plati:online', 60, 1, function (): string {
            if ($this->baza()->valoare("SELECT 1 FROM {comenzi} WHERE fel = 'card' AND (stare IN ('noua', 'plata_in_curs', 'plata_in_procesare', 'platita', 'trimitere_ec', 'de_rambursat', 'de_capturat')
                OR (stare = 'rambursata' AND email_refuz_la IS NULL AND motiv_rambursare IS NOT NULL) OR ec_orfana IN ('de_cautat', 'de_anulat')) LIMIT 1") === null
                && $this->baza()->valoare("SELECT 1 FROM {operatii_plata} WHERE tip = 'rambursare' AND stare IN ('cerut', 'in_procesare') LIMIT 1") === null) {
                return 'nicio plată în lucru';
            }
            $r = $this->plataOnline()->ruleaza();
            $this->recuperare()->curataCursoarele();
            return "reconciliate: {$r['reconciliate']}, finalizate: {$r['finalizate']}, rambursate: {$r['rambursate']}";
        });
        // Anunțurile Stripe primite, dar rămase neprocesate, reluate după 5 minute.
        $p->inregistreaza('stripe:anunturi', 300, 1, function (): string {
            return 'reluate: ' . $this->plataOnline()->reiaAnunturile();
        });
        // Starea plății online.
        $p->inregistreaza('plati:stare', 300, 1, function (): string {
            $s = $this->modPlata()->evalueaza();
            return $s['oprita'] ? 'plata online oprită: ' . implode(', ', $s['motive']) : 'plata online merge';
        });
        // Contestările plăților: lista zilnică din Stripe prinde ce n-a venit prin anunțuri.
        $p->inregistreaza('plati:contestari', 86400, 8, function (): string {
            if (!$this->stripe()->configurat()) {
                return 'fără cheie Stripe';
            }
            $r = $this->contestari()->sincronizeaza();
            return $r === null ? 'lista contestărilor n-a venit' : "citite: {$r['citite']}, schimbate: {$r['schimbate']}";
        });
        // Verificarea zilnică a cheii Stripe.
        $p->inregistreaza('stripe:verificare', 86400, 8, function (): string {
            if (!$this->stripe()->configurat()) {
                return 'fără cheie Stripe';
            }
            $v = $this->stripe()->verificaZilnic();
            $this->modPlata()->evalueaza();
            return $v['ok'] ? 'cheia are toate permisiunile' : 'permisiuni lipsă: ' . implode(', ', array_keys(array_filter($v['permisiuni'], static fn ($x) => $x !== 'ok')));
        });
    }

    // ---- tratarea cererii ----

    public function trateaza(Cerere $c): Raspuns
    {
        $rez = $this->router()->rezolva($c);
        $limba = $rez['limba'];
        $ruta = $rez['ruta'] ?? null;
        $profil = $ruta?->profil ?? 'public';
        $this->inCurs = ['cerere' => $c, 'profil' => $profil];
        $t = $this->traducator($limba);
        try {
            if (Intretinere::activa($this->privat(), $this->ceas()->acum()) && $profil !== 'tehnic'
                && !Intretinere::ocolire($this->privat(), $c->antet(Intretinere::ANTET_OCOLIRE))) {
                $r = Erori::pagina($t, 503, $this->url()->cale('/', $limba));
                $r->antet('Retry-After', '120');
            } elseif ($ruta === null && (int) $rez['status'] === 404 && $c->esteCitire() && $c->cale === '/admin') {
                // /admin, fără bara de la capăt, duce la panou (/admin/), nu la „Pagina nu există”.
                $r = Raspuns::redirectare($this->url()->cale('/admin/'), 302);
            } elseif ($ruta === null && (int) $rez['status'] === 404 && $c->esteCitire() && ($red = $this->redirectare($c->cale)) !== null) {
                // Redirectările gazdei (M6b, SEO): o adresă veche, fără rută, trimite spre cea nouă.
                $r = Raspuns::redirectare($red['la'], $red['cod']);
            } elseif ($ruta === null) {
                $r = Erori::pagina($t, (int) $rez['status'], $this->url()->cale('/', $limba));
                if ($rez['status'] === 405) {
                    $r->antet('Allow', implode(', ', array_merge($rez['permise'], in_array('GET', $rez['permise'], true) ? ['HEAD'] : [])));
                }
            } elseif ($ruta->nume === 'admin-poze-incarca' && $c->post === [] && (int) ($c->antet('content-length') ?? 0) > \Cazo\Controlere\AdminContinut::octeti((string) ini_get('post_max_size'))
                && \Cazo\Controlere\AdminContinut::octeti((string) ini_get('post_max_size')) > 0) {
                // O încărcare peste post_max_size: PHP a golit formularul (și jetonul CSRF). Răspunsul e doar trimiterea înapoi
                // la Poze, cu mesajul limitei; nicio acțiune nu se face, deci CSRF-ul rămâne neatins (M6b).
                $r = Raspuns::redirectare($this->url()->cale('/admin/poze', null, ['eroare' => 'marime']));
            } elseif ($ruta->cereCsrf() && !$this->csrfValid($c)) {
                $r = Erori::pagina($t, 403, $this->url()->cale('/', $limba));
            } else {
                $uid = $profil === 'admin' && $c->metoda === 'POST' ? $this->utilizatorAudit($c) : null;
                $r = $this->apeleaza($ruta, $c, $rez['parametri'], $limba);
                // O rută publică cu parametri care n-a găsit nimic (o pagină redenumită, o cameră mutată): redirectarea gazdei,
                // dacă există, înaintea răspunsului 404 (M6b).
                if ($r->status === 404 && $profil === 'public' && $c->esteCitire() && ($red = $this->redirectare($c->cale)) !== null) {
                    $r = Raspuns::redirectare($red['la'], $red['cod']);
                }
                if ($profil === 'admin' && $c->metoda === 'POST') {
                    $this->auditeazaCererea($ruta, $c, $rez['parametri'], $r, $uid);
                }
            }
        } catch (\Throwable $e) {
            $id = Erori::id();
            $this->jurnal()->eroare('Eroare neprinsă ' . $id, [
                'id' => $id,
                'ruta' => $ruta?->nume,
                'exceptie' => get_class($e),
                'mesaj' => $e->getMessage(),
                'loc' => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
            $r = Erori::pagina($t, 500, $this->url()->cale('/', $limba), $id);
        }
        // Pagina 404 a vizitatorului, în layoutul temei: o adresă de pagină fără rută sau o pagină negăsită.
        if ($r->status === 404 && $profil === 'public' && $c->esteCitire() && \Cazo\Controlere\Prezentare::paginaDeVizitator($c->cale)) {
            try {
                $r = \Cazo\Controlere\Prezentare::negasit($this, $c, $limba);
            } catch (\Throwable) {
                // Tema sau baza indisponibile: rămâne pagina simplă.
            }
        }
        return Antete::aplica($r, $profil, $this->config->origine(), $this->optiuniAntete($c, $profil));
    }

    /**
     * Trimiterea din funcția de oprire, după o eroare fatală în randarea unei teme terțe (Plasa):
     * răspunsul construit de controler cu pagina _schelet primește antetele profilului cererii în curs, ca pe calea
     * obișnuită, plus no-store (o pagină de rezervă rămâne în afara cache-urilor), și pleacă direct.
     */
    public function trimiteDupaFatala(Raspuns $r): void
    {
        $r->deTinut = false;
        $r->antet('Cache-Control', 'no-store');
        $c = $this->inCurs['cerere'] ?? null;
        if ($c instanceof Cerere) {
            $profil = (string) $this->inCurs['profil'];
            $r = Antete::aplica($r, $profil, $this->config->origine(), $this->optiuniAntete($c, $profil));
        }
        $this->raspunsDupaFatala = $r;
        $r->trimite($c instanceof Cerere && $c->metoda === 'HEAD');
    }

    /** Cererea tratată acum (paginile compuse ale panoului, își iau de aici adresa și cookie-urile). */
    public function cerereInCurs(): ?Cerere
    {
        $c = $this->inCurs['cerere'] ?? null;
        return $c instanceof Cerere ? $c : null;
    }

    private function apeleaza(Ruta $ruta, Cerere $c, array $parametri, string $limba): Raspuns
    {
        $a = $ruta->actiune;
        $r = $a instanceof \Closure ? $a($this, $c, $parametri, $limba) : [$a[0], $a[1]]($this, $c, $parametri, $limba);
        if (!$r instanceof Raspuns) {
            throw new \LogicException('Controlerul nu a întors un Raspuns.');
        }
        return $r;
    }

    /** Cele mai multe rânduri de audit pe oră pentru aceeași rută și același status de refuz. */
    public const PLAFON_AUDIT = 20;

    /**
     * Redirectarea gazdei pentru calea cerută (M6b), cu destinația trecută prin Url::cale (merge și fără rescriere);
     * o adresă https rămâne cum e.
     * @return array{la:string, cod:int}|null
     */
    private function redirectare(string $cale): ?array
    {
        try {
            $r = (new \Cazo\Continut\Redirectari($this->baza(), $this->ceas()))->pentru($cale);
        } catch (\Throwable) {
            return null;
        }
        if ($r === null) {
            return null;
        }
        if (str_starts_with($r['la'], '/')) {
            // Aceeași regulă și la aplicare: o destinație care, decodată, ar duce pe alt domeniu (//, /\) se ignoră.
            if (!\Cazo\Continut\Redirectari::caleSigura($r['la'])) {
                return null;
            }
            $p = parse_url('http://x' . $r['la']);
            parse_str((string) ($p['query'] ?? ''), $q);
            try {
                $r['la'] = $this->url()->cale(rawurldecode((string) ($p['path'] ?? '/')), $this->config->limbaImplicita(), $q);
            } catch (\Throwable) {
                return null;
            }
        }
        return $r;
    }

    private function utilizatorAudit(Cerere $c): ?int
    {
        try {
            return $this->sesiuneAdmin()->idDinJeton($c->cookie(SesiuneAdmin::COOKIE));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fiecare POST al panoului intră în auditul înlănțuit: ruta, parametrii din adresă (codul comenzii, id-ul
     * utilizatorului), rezultatul (mesajul sau eroarea din redirecționare) și detaliile adăugate de controler; niciodată
     * câmpurile formularului (parole, sume scrise, note). Autentificarea reușită își află utilizatorul din sesiunea nouă.
     */
    private function auditeazaCererea(Ruta $ruta, Cerere $c, array $parametri, Raspuns $r, ?int $uid): void
    {
        try {
            if ($uid === null) {
                foreach ($r->cookies() as $k) {
                    if (preg_match('~^' . preg_quote(SesiuneAdmin::COOKIE, '~') . '=([A-Za-z0-9_-]{43});~', $k, $x)) {
                        $uid = $this->sesiuneAdmin()->idDinJeton($x[1]);
                    }
                }
            }
            $d = [];
            $loc = (string) ($r->valoareAntet('Location') ?? '');
            parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
            foreach (['mesaj', 'eroare'] as $k) {
                if (is_string($q[$k] ?? null)) {
                    $d[$k] = mb_substr($q[$k], 0, 60);
                }
            }
            if ($r->status >= 400) {
                $d['status'] = $r->status;
            }
            $obiect = [];
            foreach ($parametri as $k => $v) {
                // Jetoanele din adresă (invitația, resetarea) rămân în afara auditului.
                if (is_string($k) && is_scalar($v) && !in_array($k, ['jeton', 'token'], true)) {
                    $obiect[] = $k . '=' . $v;
                }
            }
            // Cererile refuzate (intrarea greșită, limita atinsă) și cererile de resetare a parolei intră cel mult o
            // dată pe oră pentru aceeași rută și același status, ca o încercare în buclă să nu umfle auditul; jurnalul
            // aplicației le ține pe toate. Auditul nu ține IP-ul: rândurile lui nu se mai pot schimba după scriere (lanțul),
            // deci n-ar putea urma termenul de păstrare al IP-urilor (20).
            $refuz = $r->status >= 400 || $ruta->nume === 'admin-parola-uitata-trimite';
            if ($refuz) {
                // Eșantionul e pe cont doar pentru conturile existente (utilizatorul sesiunii sau cel cu adresa scrisă), ca
                // fiecare cont real să aibă cel puțin un rând pe oră; adresele fără cont intră sub o singură cheie; peste tot,
                // cel mult PLAFON_AUDIT rânduri pe oră pe rută și status (protecția la abuz a jurnalului).
                $contId = $uid;
                $email = mb_strtolower(trim((string) ($c->camp('email') ?? '')));
                if ($contId === null && $email !== '') {
                    $v = $this->baza()->valoare('SELECT id FROM {utilizatori} WHERE email = ?', [$email]);
                    $contId = $v !== null ? (int) $v : null;
                }
                $baza = 'audit:refuz:' . $ruta->nume . ':' . $r->status;
                if (!$this->limitator()->incearca($baza . ':' . ($contId !== null ? 'u' . $contId : 'necunoscut'), 1, 3600)
                    || !$this->limitator()->incearca($baza . ':plafon', self::PLAFON_AUDIT, 3600)) {
                    return;
                }
            }
            if ($refuz) {
                $d['esantion'] = 'o_ora';
            }
            $this->audit()->scrie((string) ($ruta->nume ?? 'admin'), $obiect !== [] ? implode(' ', $obiect) : null, $this->auditDetalii + $d, $uid);
        } catch (\Throwable $e) {
            $this->jurnal()->eroare('Auditul nu s-a putut scrie', ['ruta' => $ruta->nume, 'eroare' => $e->getMessage()]);
        } finally {
            $this->auditDetalii = [];
        }
    }

    /** CSRF: jetonul din câmpul _csrf sau din antetul X-CSRF, legat de cookie-ul __Host-pg_csrf. */
    private function csrfValid(Cerere $c): bool
    {
        $legatura = $c->cookie(Csrf::COOKIE) ?? '';
        $jeton = $c->camp(Csrf::CAMP) ?? $c->antet(Csrf::ANTET);
        return $this->csrf()->verifica($legatura, $jeton);
    }

    /** Emite un jeton CSRF pentru un formular; pune cookie-ul de legătură când lipsește. */
    public function jetonCsrf(Cerere $c, Raspuns $r): string
    {
        $legatura = $c->cookie(Csrf::COOKIE);
        if ($legatura === null || !preg_match('~^[A-Za-z0-9_-]{43}$~', $legatura)) {
            $legatura = Codare::jeton(32);
            $r->cookie(Csrf::COOKIE . '=' . $legatura . '; Path=/; Secure; HttpOnly; SameSite=Lax');
        }
        return $this->csrf()->emite($legatura);
    }

    private function optiuniAntete(Cerere $c, string $profil): array
    {
        $o = ['metoda' => $c->metoda, 'tema_cu_js' => false, 'hsts' => false, 'noindex' => true];
        try {
            $s = $this->setari();
            $o['tema_cu_js'] = $this->temaActiva()->areJs();
            $o['hsts'] = (bool) $s->get('https.verificat', false);
            // Până la „Publică site-ul”: vizibil oricui, cu noindex; cu o cheie EasyCheck de probă, la fel.
            $o['noindex'] = $this->noindex() || $profil !== 'public';
        } catch (\Throwable) {
            // Baza indisponibilă: antetele rămân pe variantele prudente.
        }
        return $o;
    }

    /** Pseudo-cronul rulează după răspunsurile publice reușite, cel mult o dată la 60 s. */
    public function vreaPseudoCron(Cerere $c, Raspuns $r, ?Ruta $ruta = null): bool
    {
        if ($r->status >= 500 || !$c->esteCitire()) {
            return false;
        }
        $rez = $this->router()->rezolva($c);
        if (($rez['ruta'] ?? null)?->profil !== 'public') {
            return false;
        }
        return $this->pseudoCron()->eRandul();
    }
}
