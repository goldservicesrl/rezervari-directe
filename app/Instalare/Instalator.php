<?php

declare(strict_types=1);

namespace Cazo\Instalare;

use Cazo\Baza\Baza;
use Cazo\Controlere\Prezentare;
use Cazo\Dezvoltare\TexteDeAprobat;
use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\EasyCheck\Licenta;
use Cazo\EasyCheck\Oglinda;
use Cazo\Local\Depozit;
use Cazo\Local\Mod;
use Cazo\Gdpr\Gdpr;
use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Legal\BlocLegal;
use Cazo\Nucleu\Antete;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Config;
use Cazo\Nucleu\Raspuns;
use Cazo\Plati\StripeClient;
use Cazo\Plati\VerificareCheie;
use Cazo\Rezervare\ModPlata;
use Cazo\Securitate\Chei;
use Cazo\Securitate\Codare;
use Cazo\Securitate\Cutie;
use Cazo\Securitate\Parole;
use Cazo\Securitate\ParoleCompromise;
use Cazo\Sistem\Adresa;
use Cazo\Teme\Active;
use Cazo\Teme\MediuNucleu;

defined('CAZO') or exit;

/**
 * Instalatorul: asistentul în 11 pași, în română și engleză, servit de încărcător
 * (public_html/index.php) înaintea aplicației, doar cât lipsește config.php și cel mult 72 de ore de la prima
 * vizită. După ultimul pas, config.php există, deci încărcătorul pornește aplicația, iar /instalare/ primește 404 de
 * acolo; marcajul INSTALARE-INCHEIATA.txt ține instalatorul închis și dacă config.php dispare.
 *
 * Accesul: codul de 8 caractere din COD-INSTALARE.txt (doar cine are acces la fișierele contului îl citește), apoi
 * un cookie de sesiune cu amprenta în starea instalării; CSRF prin cookie dublat în formular (SameSite=Strict).
 * De la pasul bazei de date, pașii folosesc aplicația construită din config-instalare.php (cheia EasyCheck, plățile, tema,
 * administratorul), iar anunțurile EasyCheck și Stripe și cronul extern ajung la ea tot prin instalator.
 */
final class Instalator
{
    /**
     * Pașii asistentului: locul datelor și cheia de criptare țin de pasul verificărilor, fără pași proprii.
     * Numerele vin doar de aici; codul și șabloanele cer numărul unui pas cu nr().
     */
    public const PASI = [1 => 'cod', 2 => 'verificari', 3 => 'baza', 4 => 'easycheck', 5 => 'adresa', 6 => 'legal', 7 => 'plati',
        8 => 'tema', 9 => 'administrator', 10 => 'cron', 11 => 'gata'];
    /** Primul pas care se poate revedea; cei de dinainte au lăsat fișiere și se fac o singură dată. */
    public const PRIMUL_REVAZUT = 'easycheck';
    /** Sursele unei rulări a sarcinilor (Rulator::SURSE) care au text propriu în pasul cronului. */
    public const SURSE_CRON = ['cpanel', 'pseudo', 'anunt', 'panou', 'lant'];

    /** Instalatorul rămâne deschis 72 de ore de la prima vizită (15). */
    public const DURATA = 72 * 3600;
    public const COOKIE = 'pg_instalare';
    public const COOKIE_CSRF = 'pg_instalare_csrf';
    public const COOKIE_LIMBA = 'pg_instalare_limba';
    /** Încercările greșite de cod îngăduite unei conexiuni înaintea unei pauze. */
    public const INCERCARI_COD = 10;
    /** Pauza conexiunii după INCERCARI_COD greșeli; codul rămâne același. */
    public const BLOCARE_COD = 900;
    /** Câte conexiuni cu încercări greșite ține starea (cele mai noi). */
    public const CLIENTI_COD = 500;
    public const ALFABET_COD = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * Termenele de păstrare (20): câmpul formularului => cheia termenului din Gdpr::CHEIE. Pragurile și valorile
     * propuse sunt cele din panou (Gdpr::LIMITE, Gdpr::PROPUSE). Câmpul `comenzi_zile` e termenul `confirmate_zile`.
     */
    public const PASTRARE_GDPR = ['comenzi_zile' => 'confirmate_zile', 'abandonate_ore' => 'abandonate_ore', 'rambursate_zile' => 'rambursate_zile', 'ip_luni' => 'ip_luni'];
    public const FORME = BlocLegal::FORME;
    /** Resursele fără de care site-ul nu are ce arăta: sincronizarea inițială le cere (6). */
    public const RESURSE_OBLIGATORII = ['eu', 'proprietate', 'camere'];
    /** Selectorul DKIM pus de cPanel la „Email Deliverability”: `default._domainkey.<domeniu>`. */
    public const DKIM_SELECTOR = 'default';
    /** Cât timp e proaspătă o rulare a cronului pentru butonul „Verificați” (11). */
    public const CRON_PROASPAT = 900;

    /** găzduirea recomandată în instalator și în ghiduri, cu legătura de afiliere. */
    public const GAZDUIRE_RECOMANDATA = 'https://client.sitebunker.net/aff.php?aff=1302';

    private string $privat;
    private ?Stare $stare = null;
    private ?Aplicatie $app = null;
    /** DOCUMENT_ROOT-ul serverului web (Loc::documentRoot), când cuprinde dosarul lui index.php. */
    private ?string $documentRoot = null;
    private string $limba = 'ro';
    private string $origine = '';
    private bool $https = false;
    /** HTTPS văzut de serverul web, fără antetele proxy-urilor (schema()); de el țin cookie-urile. */
    private bool $httpsDirect = false;
    private ?string $csrfNou = null;
    private string $csrfPagina = '';
    /** @var list<string> */
    private array $cookies = [];
    /** @var list<\Closure(): void> */
    private array $dupa = [];

    /**
     * @param string $gasit dosarul privat găsit de încărcător (CAZO_PRIVAT)
     * @param ?\Closure(string): array{status:?int, corp:string, eroare:?string} $http cererile HTTP (verificări, sondă)
     * @param ?\Closure(Config, Chei): Aplicatie $fabrica aplicația pe configurarea în curs (testele pun dublurile)
     * @param array $mediu valorile verificărilor (Verificari::ruleaza), 'https' (schema cererii) și, în teste, 'drivere' și 'dns'
     *   (\Closure(string): ?list<string>: înregistrările TXT ale unui nume, null = fără răspuns; vezi livrare())
     */
    public function __construct(
        private string $gasit,
        private string $public,
        private ?\Closure $http = null,
        private ?\Closure $fabrica = null,
        private ?\Closure $ceas = null,
        private array $mediu = [],
    ) {
        $this->public = rtrim($public, '/');
        $this->privat = rtrim($gasit, '/');
        $this->documentRoot = Loc::documentRoot((string) ($mediu['document_root'] ?? ''), $this->public);
    }

    /** Intrarea din app/instalare.php: cererea reală, răspunsul trimis, apoi lucrul de după (anunțurile). */
    public static function web(string $privat, string $public): void
    {
        $i = new self($privat, $public, null, null, null, self::schema($_SERVER) + ['document_root' => (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')]);
        $c = Cerere::dinGlobale();
        $r = $i->trateaza($c);
        $dupa = $i->lucruDupa();
        if ($dupa !== []) {
            \Cazo\Nucleu\Inchidere::pregateste($r);
        }
        $r->trimite($c->metoda === 'HEAD');
        if ($dupa !== []) {
            \Cazo\Nucleu\Inchidere::inchide();
            foreach ($dupa as $f) {
                try {
                    $f();
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * Schema cererii, pentru mediul instalatorului (auditul de securitate.2026). `https_direct`: HTTPS-ul văzut
     * de serverul web însuși (HTTPS, SERVER_PORT 443), singurul pe care se sprijină cookie-urile (prefixul `__Host-`,
     * atributul Secure). Înainte de config.php nu există lista proxy-urilor de încredere (`proxy_increzut`), deci un
     * antet X-Forwarded-Proto scris de oricine nu mai schimbă cookie-urile; pe găzduirea cPanel HTTPS-ul vine direct
     * de la Apache. `https`: schema adresei site-ului și a verificării „https”, ca în restul aplicației
     * (Https::cerereSigura, și printr-un proxy care anunță schema), ca un site din spatele unui proxy cu HTTPS
     * să se poată instala în continuare.
     *
     * @return array{https:bool, https_direct:bool}
     */
    public static function schema(array $server): array
    {
        $h = strtolower((string) ($server['HTTPS'] ?? ''));
        $direct = ($h !== '' && $h !== 'off' && $h !== '0') || (int) ($server['SERVER_PORT'] ?? 0) === 443;
        return ['https' => $direct || \Cazo\Nucleu\Https::cerereSigura($server), 'https_direct' => $direct];
    }

    /** @return list<\Closure(): void> */
    public function lucruDupa(): array
    {
        return $this->dupa;
    }

    /** Numărul unui pas, după numele lui din PASI. */
    public static function nr(string $nume): int
    {
        $n = array_search($nume, self::PASI, true);
        if ($n === false) {
            throw new \InvalidArgumentException('Pas necunoscut: ' . $nume);
        }
        return (int) $n;
    }

    /** Dosarul privat de lucru (după mutarea de la varianta B). */
    public function privat(): string
    {
        return $this->privat;
    }

    public function trateaza(Cerere $c): Raspuns
    {
        $this->https = (bool) ($this->mediu['https'] ?? false);
        $this->httpsDirect = (bool) ($this->mediu['https_direct'] ?? $this->https);
        $gazda = (string) ($c->antet('host') ?? '');
        if (!preg_match('~^[A-Za-z0-9.\-]{1,253}(:\d{1,5})?$~', $gazda)) {
            return $this->tehnic(Raspuns::text('Bad Request', 400));
        }
        $this->origine = ($this->https ? 'https://' : 'http://') . strtolower($gazda);
        $this->csrfPagina = $this->csrf($c);
        if (is_file($this->privat . '/' . Loc::MARCAJ)) {
            return $this->tehnic($this->indisponibil());
        }
        // Proba rescrierii: răspunsul vine doar când serverul trimite /__cazo_test spre index.php.
        if ($c->cale === '/__cazo_test') {
            return $this->tehnic(Raspuns::text(Verificari::MARCAJ_RESCRIERE));
        }
        // Anunțurile și cronul extern ajung la aplicația instalării în curs (de la pasul bazei de date).
        if (in_array($c->cale, ['/easycheck-anunt', '/stripe-anunt'], true) || str_starts_with($c->cale, '/_cron/')) {
            if (!is_file($this->privat . '/' . Loc::CONFIG_LUCRU) || !is_file($this->privat . '/cheie.bin')) {
                return $this->tehnic(Raspuns::text('Not Found', 404));
            }
            $app = $this->aplicatie();
            $r = $app->trateaza($c);
            $this->dupa = $app->lucruDupaRaspuns();
            return $r;
        }

        try {
            $vechi = $this->privat;
            $this->pregatesteLocul();
            if (!is_dir($vechi)) {
                // Dosarul privat tocmai s-a redenumit, cu lansarea în el: cererea următoare îl găsește în noul loc
                // (cu limba aleasă pe prima pagină, ca alegerea să rămână și după redirecționare).
                $l = $c->param('limba');
                return $this->finalizeaza(Raspuns::redirectare($this->url(in_array($l, ['ro', 'en'], true) ? ['limba' => $l] : [])));
            }
        } catch (\Throwable) {
            $this->limba = $this->limbaCeruta($c);
            return $this->finalizeaza($this->randeaza('eroare', ['mesaj' => 'instalare.eroare.scriere', 'parametri' => ['dosar' => basename($this->public)]], 500));
        }
        $autentificat = $this->sesiuneValida($c);
        $this->limba = $this->limbaCeruta($c, $autentificat);
        if ($this->acum() - (int) $this->stare->get('inceput_la', $this->acum()) > self::DURATA) {
            return $this->finalizeaza($this->randeaza('eroare', ['mesaj' => 'instalare.eroare.expirat', 'parametri' => ['dosar' => Loc::afisat($this->public, $this->privat, 'instalare', $this->documentRoot)]], 403));
        }

        $cale = $c->cale;
        if ($cale === '/' && $c->esteCitire()) {
            return $this->finalizeaza(Raspuns::redirectare($this->url()));
        }
        if ($cale !== '/instalare/' && $cale !== '/instalare' && $cale !== '/instalare/tema') {
            return $this->finalizeaza($this->randeaza('eroare', ['mesaj' => 'instalare.eroare.negasit', 'parametri' => []], 404));
        }
        if ($c->metoda === 'POST' && !$this->csrfValid($c)) {
            return $this->finalizeaza($this->randeaza('eroare', ['mesaj' => 'instalare.eroare.csrf', 'parametri' => []], 403));
        }
        if (!in_array($c->metoda, ['GET', 'HEAD', 'POST'], true)) {
            return $this->finalizeaza(Raspuns::text('Method Not Allowed', 405)->antet('Allow', 'GET, HEAD, POST'));
        }

        if (!$autentificat) {
            return $this->finalizeaza($c->metoda === 'POST' ? $this->codPost($c) : $this->pagina(1, $c));
        }
        if ($cale === '/instalare/tema') {
            return $this->finalizeaza($this->previzualizare($c));
        }
        $curent = min(count(self::PASI), max(2, (int) $this->stare->get('pas', 2)));
        $cerut = (int) ($c->param('pas') ?? $c->camp('pas') ?? $curent);
        // Pașii de la legătura cu EasyCheck încolo se pot revedea; cei de dinainte au lăsat fișiere și se fac o singură dată.
        $pas = ($cerut >= self::nr(self::PRIMUL_REVAZUT) && $cerut <= $curent) ? $cerut : $curent;
        if ($c->metoda === 'POST') {
            // Un formular vechi (al unui pas încheiat, retrimis din istoricul browserului) duce doar la pasul curent.
            if ((int) ($c->camp('pas') ?? 0) !== $pas) {
                return $this->finalizeaza(Raspuns::redirectare($this->url(['pas' => $pas])));
            }
            $metoda = 'post' . ucfirst(self::PASI[$pas]);
            return $this->finalizeaza($this->$metoda($c, $pas));
        }
        return $this->finalizeaza($this->pagina($pas, $c));
    }

    // ---- locul, starea, sesiunea ----

    private function pregatesteLocul(): void
    {
        $s = Stare::pentru($this->privat);
        if (!$s->exista()) {
            $loc = Loc::pregateste($this->public, $this->privat, $this->documentRoot);
            $this->dupaRedenumire($loc['privat']);
            Loc::structura($this->privat);
            $s = Stare::pentru($this->privat);
            $s->pune('inceput_la', $this->acum())->pune('varianta', $loc['varianta'])->pune('mutat', $loc['mutat'])->pune('pas', 1)
                ->pune('loc_hash', $this->amprentaLoc());
            $this->codNou($s);
        } else {
            $s = $this->reverificaLocul($s);
        }
        $this->stare = $s;
    }

    /**
     * La fiecare cerere: dosarul privat ajuns în webroot fără numele aleator al variantei B (de exemplu după mutarea
     * lui index.php) se redenumește și se protejează pe loc, ca la prima vizită; unul cu numele B, dar fără .htaccess,
     * își primește din nou protecția. Când locul s-a schimbat (alt dosar privat sau alt dosar al lui index.php) ori
     * a stat expus, codul vechi se poate să fi fost citit de oricine: se scrie un cod nou, sesiunea veche se
     * închide, iar pasul verificărilor se reia (sonda și .cazo-cale.php), cu întoarcerea la pasul atins după el.
     */
    private function reverificaLocul(Stare $s): Stare
    {
        $expus = false;
        if (Loc::radacinaWeb($this->public, $this->documentRoot, $this->privat) !== null) {
            if (!str_starts_with(basename($this->privat), Loc::PREFIX_B)) {
                $this->dupaRedenumire(Loc::pregateste($this->public, $this->privat, $this->documentRoot)['privat']);
                $s = Stare::pentru($this->privat);
                $expus = true;
            } elseif (!is_file($this->privat . '/.htaccess')) {
                Loc::protejeaza($this->privat);
                $expus = true;
            }
        }
        $varianta = Loc::radacinaWeb($this->public, $this->documentRoot, $this->privat) !== null ? 'B' : 'A';
        $mutat = !hash_equals((string) $s->get('loc_hash', ''), $this->amprentaLoc());
        if (!$expus && !$mutat && $s->get('varianta') === $varianta) {
            return $s;
        }
        if ($expus) {
            $s->pune('mutat', 'redenumit');
        }
        $s->pune('varianta', $varianta)->pune('loc_hash', $this->amprentaLoc())->pune('sesiune_hash', null)->pune('sonda', null);
        $pas = (int) $s->get('pas', 1);
        if ($pas > 2) {
            $s->pune('pas', 2)->pune('pas_reluat', max($pas, (int) $s->get('pas_reluat', 0)));
        }
        // .cazo-cale.php arată spre locul vechi: pasul verificărilor îl scrie din nou, după sondă.
        if (is_file($this->public . '/.cazo-cale.php')) {
            @unlink($this->public . '/.cazo-cale.php');
        }
        $cfg = $this->privat . '/' . Loc::CONFIG_LUCRU;
        if (is_file($cfg)) {
            $v = (array) (static fn (string $f): mixed => require $f)($cfg);
            if (($v['cale_public'] ?? null) !== $this->public) {
                $v['cale_public'] = $this->public;
                Config::scrie($this->privat, $v, Loc::CONFIG_LUCRU);
            }
        }
        $this->codNou($s);
        return $s;
    }

    /**
     * Lansarea care rulează stă în dosarul privat: după redenumirea lui, clasele încă neîncărcate se caută în noul loc
     * (încărcătorul de clase al lansării, înregistrat din nou de acolo).
     */
    private function dupaRedenumire(string $nou): void
    {
        $vechi = $this->privat;
        $this->privat = $nou;
        if ($nou === $vechi || !defined('CAZO_LANSARE') || !str_starts_with((string) CAZO_LANSARE, $vechi . '/')) {
            return;
        }
        $incarca = $nou . substr((string) CAZO_LANSARE, strlen($vechi)) . '/app/incarca.php';
        if (is_file($incarca)) {
            require $incarca;
        }
    }

    /** Amprenta locului: dosarul privat și dosarul lui index.php (căile întregi rămân în afara stării). */
    private function amprentaLoc(): string
    {
        return hash('sha256', $this->privat . "\0" . $this->public);
    }

    private function codNou(Stare $s): void
    {
        $cod = '';
        for ($i = 0; $i < 8; $i++) {
            $cod .= self::ALFABET_COD[random_int(0, strlen(self::ALFABET_COD) - 1)];
        }
        $f = $this->privat . '/' . Loc::COD;
        file_put_contents($f, "Codul de instalare / Installation code: $cod\n", LOCK_EX);
        \Cazo\Nucleu\Functii::chmod($f, 0600);
        $s->pune('cod_hash', hash('sha256', $cod))->salveaza();
    }

    private function sesiuneValida(Cerere $c): bool
    {
        $j = $c->cookie($this->numeCookie(self::COOKIE));
        $h = $this->stare->get('sesiune_hash');
        return is_string($j) && is_string($h) && hash_equals($h, hash('sha256', $j));
    }

    private function csrfValid(Cerere $c): bool
    {
        $a = $c->cookie($this->numeCookie(self::COOKIE_CSRF));
        $b = $c->camp('_csrf');
        return is_string($a) && is_string($b) && strlen($a) === 43 && hash_equals($a, $b);
    }

    private function csrf(Cerere $c): string
    {
        $a = $c->cookie($this->numeCookie(self::COOKIE_CSRF));
        if (is_string($a) && preg_match('~^[A-Za-z0-9_-]{43}$~', $a)) {
            return $a;
        }
        $this->csrfNou ??= Codare::jeton(32);
        return $this->csrfNou;
    }

    /**
     * Pe HTTPS, prefixul __Host- (Secure, fără Domain, Path=/); pe http://localhost, numele simplu. HTTPS-ul e cel văzut
     * de serverul web, nu cel anunțat de un antet (auditul de securitate.2026; schema()).
     */
    private function numeCookie(string $n): string
    {
        return $this->httpsDirect ? '__Host-' . $n : $n;
    }

    private function cookie(string $nume, string $valoare, int $durata = 0): void
    {
        $this->cookies[] = $this->numeCookie($nume) . '=' . $valoare . '; Path=/; HttpOnly; SameSite=Strict' . ($this->httpsDirect ? '; Secure' : '')
            . ($durata > 0 ? '; Max-Age=' . $durata : '');
    }

    /**
     * Limba paginii: ?limba= (sau câmpul limba), apoi limba aleasă. Starea instalării o scrie doar sesiunea
     * proprietarului; un vizitator fără sesiune își schimbă limba doar în cookie-ul lui.
     */
    private function limbaCeruta(Cerere $c, bool $autentificat = false): string
    {
        $l = $c->param('limba') ?? $c->camp('limba');
        if (in_array($l, ['ro', 'en'], true)) {
            $this->cookie(self::COOKIE_LIMBA, $l, self::DURATA);
            if ($autentificat && $this->stare !== null && $this->stare->get('limba') !== $l) {
                $this->stare->pune('limba', $l)->salveaza();
            }
            return $l;
        }
        $cookie = $c->cookie($this->numeCookie(self::COOKIE_LIMBA));
        $l = $autentificat ? ($this->stare?->get('limba') ?? $cookie) : $cookie;
        return in_array($l, ['ro', 'en'], true) ? $l : 'ro';
    }

    private function acum(): int
    {
        return $this->ceas !== null ? (int) ($this->ceas)() : time();
    }

    private function aplicatie(): Aplicatie
    {
        if ($this->app === null) {
            $cfg = Config::dinDosar($this->privat, true);
            $chei = Chei::dinFisier($this->privat . '/cheie.bin');
            $this->app = $this->fabrica !== null ? ($this->fabrica)($cfg, $chei) : new Aplicatie($cfg, $chei);
        }
        return $this->app;
    }

    private function http(): \Closure
    {
        return $this->http ??= Http::curl();
    }

    private function avanseaza(int $pas): Raspuns
    {
        if ((int) $this->stare->get('pas', 1) < $pas) {
            $this->stare->pune('pas', $pas)->salveaza();
        }
        return Raspuns::redirectare($this->url(['pas' => $pas]));
    }

    /**
     * Comanda cronului, fără partea de timp (cPanel o cere separat, în câmpul „Command”): calea între ghilimele, ca o
     * cale cu spații să rămână un singur argument, iar „%” scris „\%”, fiindcă în crontab „%” simplu începe o linie nouă.
     */
    public static function comandaCron(string $privat): string
    {
        return 'php -q ' . str_replace('%', '\\%', escapeshellarg(rtrim($privat, '/') . '/cron.php'));
    }

    /** Linia întreagă de crontab (pasul cronului, bin/cazo configureaza): la fiecare 5 minute, apoi comanda. */
    public static function linieCron(string $privat): string
    {
        return '*/5 * * * * ' . self::comandaCron($privat);
    }

    /** Adresa instalatorului: /instalare/ cu rescriere, altfel /?r=/instalare/ (merge pe orice server). */
    public function url(array $q = [], string $cale = '/instalare/'): string
    {
        if ($this->stare?->get('rescriere') === true) {
            return $cale . ($q === [] ? '' : '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986));
        }
        return '/?' . http_build_query(['r' => $cale] + $q, '', '&', PHP_QUERY_RFC3986);
    }

    // ---- codul ----

    /**
     * Codul rămâne același pe toată instalarea; încercările greșite se numără pe conexiune (adresa IP, la IPv6
     * rețeaua /64). După INCERCARI_COD greșeli, conexiunea aceea așteaptă BLOCARE_COD secunde, iar celelalte merg
     * mai departe, deci un vizitator străin nu poate ține proprietarul pe dinafară. Cererile de cod trec pe rând
     * (flock), ca încercările trimise în paralel să se numere toate.
     */
    private function codPost(Cerere $c): Raspuns
    {
        $lacat = @fopen($this->privat . '/instalare/cod.lock', 'c');
        if ($lacat !== false) {
            \Cazo\Nucleu\Functii::flock($lacat, LOCK_EX);
        }
        try {
            $this->stare = Stare::pentru($this->privat);
            return $this->verificaCod($c);
        } finally {
            if ($lacat !== false) {
                \Cazo\Nucleu\Functii::flock($lacat, LOCK_UN);
                fclose($lacat);
            }
        }
    }

    private function verificaCod(Cerere $c): Raspuns
    {
        $acum = $this->acum();
        $client = self::cheieClient($c->ip);
        $clienti = self::clientiActivi((array) $this->stare->get('cod_clienti', []), $acum);
        $al = $clienti[$client] ?? ['n' => 0, 'pana' => 0, 'la' => $acum];
        if ((int) $al['pana'] > $acum) {
            return $this->asteptareCod((int) $al['pana'] - $acum, $c);
        }
        $cod = strtoupper(preg_replace('~[\s\-]~', '', (string) ($c->camp('cod') ?? '')));
        if ($cod !== '' && hash_equals((string) $this->stare->get('cod_hash'), hash('sha256', $cod))) {
            // cine instalează acceptă că programul e oferit „ca atare”, fără garanție și fără răspunderea titularului
            // (articolul 6 al licenței). Fără bifă, codul corect nu se numără ca încercare greșită.
            if ((string) ($c->camp('licenta') ?? '') !== '1') {
                return $this->pagina(1, $c, ['eroare' => 'instalare.cod.licenta_lipsa'], 422);
            }
            unset($clienti[$client]);
            $jeton = Codare::jeton(32);
            $this->stare->pune('cod_clienti', $clienti)->pune('sesiune_hash', hash('sha256', $jeton))->pune('limba', $this->limba)
                ->pune('licenta_acceptata_la', gmdate('Y-m-d\\TH:i:s\\Z', $acum))
                ->pune('pas', max(2, (int) $this->stare->get('pas', 1)))->salveaza();
            $this->cookie(self::COOKIE, $jeton);
            return Raspuns::redirectare($this->url());
        }
        $n = (int) $al['n'] + 1;
        $blocat = $n >= self::INCERCARI_COD;
        $clienti[$client] = ['n' => $blocat ? 0 : $n, 'pana' => $blocat ? $acum + self::BLOCARE_COD : 0, 'la' => $acum];
        $this->stare->pune('cod_clienti', self::clientiActivi($clienti, $acum))->salveaza();
        return $blocat ? $this->asteptareCod(self::BLOCARE_COD, $c) : $this->pagina(1, $c, ['eroare' => 'instalare.cod.gresit'], 403);
    }

    private function asteptareCod(int $secunde, Cerere $c): Raspuns
    {
        $minute = max(1, (int) ceil($secunde / 60));
        return $this->pagina(1, $c, ['eroare' => 'instalare.cod.asteptare', 'eroare_p' => ['minute' => $minute]], 429)
            ->antet('Retry-After', (string) $secunde);
    }

    /** Amprenta conexiunii: adresa IPv4 sau rețeaua IPv6 /64 (o singură conexiune primește de obicei o /64 întreagă). */
    public static function cheieClient(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            $bin = $ip;
        } elseif (strlen($bin) === 16) {
            $bin = substr($bin, 0, 8);
        }
        return substr(hash('sha256', 'cazo-instalare|' . $bin), 0, 24);
    }

    /**
     * Conexiunile cu încercări recente sau cu așteptarea în curs; cel mult CLIENTI_COD, cele mai noi.
     * @param array<string, array{n:int, pana:int, la:int}> $clienti
     */
    private static function clientiActivi(array $clienti, int $acum): array
    {
        $clienti = array_filter($clienti, static fn ($a): bool => is_array($a)
            && ((int) ($a['pana'] ?? 0) > $acum || $acum - (int) ($a['la'] ?? 0) < self::BLOCARE_COD));
        if (count($clienti) > self::CLIENTI_COD) {
            uasort($clienti, static fn ($a, $b): int => (int) $b['la'] <=> (int) $a['la']);
            $clienti = array_slice($clienti, 0, self::CLIENTI_COD, true);
        }
        return $clienti;
    }

    // ---- verificările, locul datelor și cheia de criptare ----

    private function verificari(): array
    {
        $scriere = ['cazo-privat' => Loc::scriibil($this->privat), 'public_html' => Loc::scriibil($this->public)];
        $v = Verificari::ruleaza($this->origine, $this->mediu + ['scriere' => $scriere], $this->http());
        $this->stare->pune('verificari', ['ok' => $v['ok'], 'la' => $this->acum()])->pune('site_url', $this->origine)->pune('rescriere', $v['rescriere'])->salveaza();
        return $v;
    }

    /**
     * Trecerea de verificări: cu verificările bune, tot aici se confirmă locul datelor (sonda), se scrie
     * .cazo-cale.php și se creează cheia de criptare, fără pași și fără butoane proprii. La varianta A, gazda merge
     * direct la baza de date. La varianta B (datele în public_html), gazda vede întâi ecranul locului datelor, unde
     * află ce s-a întâmplat și pornește sonda; o sondă picată o ține acolo, cu pașii de urmat.
     */
    private function postVerificari(Cerere $c, int $pas): Raspuns
    {
        $v = $this->verificari();
        if (!$v['ok']) {
            return $this->pagina($pas, $c, ['verificari' => $v], 200);
        }
        if ($this->stare->get('varianta') === 'B' && $c->camp('actiune') !== 'loc') {
            return Raspuns::redirectare($this->url(['pas' => $pas, 'ecran' => 'loc']));
        }
        $s = $this->sonda();
        $this->stare->pune('sonda', ['ok' => $s['ok'], 'la' => $this->acum()])->salveaza();
        if (!$s['ok']) {
            return $this->pagina($pas, $c, ['ecran' => 'loc', 'sonda' => $s], 200);
        }
        file_put_contents($this->public . '/.cazo-cale.php', "<?php\n\nreturn " . var_export($this->privat, true) . ";\n", LOCK_EX);
        \Cazo\Nucleu\Functii::chmod($this->public . '/.cazo-cale.php', 0644);
        if (!$this->creeazaCheia()) {
            return $this->pagina($pas, $c, ['verificari' => $v, 'cheie_esuata' => true], 200);
        }
        // După o mutare a locului (reverificaLocul), instalarea se întoarce la pasul atins înainte.
        $inapoi = max($pas + 1, (int) $this->stare->get('pas_reluat', 0));
        $this->stare->pune('pas_reluat', null);
        return $this->avanseaza($inapoi);
    }

    /** Cheia-mamă (cheie.bin, 32 de octeți, 0600), creată o singură dată; false când scrierea sau citirea ei a eșuat. */
    private function creeazaCheia(): bool
    {
        $f = $this->privat . '/cheie.bin';
        try {
            if (!is_file($f)) {
                Chei::genereaza($f);
            }
            Chei::dinFisier($f);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Rândurile locului datelor din lista verificărilor, în forma elementelor din Verificari::ruleaza:
     * locul, protecția fișierelor private (la A, dosarul stă în afara webroot-ului; la B, rezultatul ultimei sonde) și
     * cheia de criptare (de când există sau când crearea ei a eșuat).
     * @return list<array{id:string, ok:bool, blocant:bool, valoare:string, gazduire:bool, nota?:string}>
     */
    private function randuriLoc(bool $cheieEsuata): array
    {
        $b = $this->stare->get('varianta') === 'B';
        $dosar = Loc::afisat($this->public, $this->privat, '', $this->documentRoot);
        $r = [['id' => 'loc', 'ok' => true, 'blocant' => false, 'valoare' => $dosar, 'gazduire' => false] + ($b ? ['nota' => 'instalare.verificari.loc.nota_b'] : [])];
        $sonda = $this->stare->get('sonda');
        if (!$b || is_array($sonda)) {
            $r[] = ['id' => 'sonda', 'ok' => !$b || ($sonda['ok'] ?? false) === true, 'blocant' => true, 'valoare' => $dosar, 'gazduire' => false];
        }
        if ($cheieEsuata || is_file($this->privat . '/cheie.bin')) {
            $r[] = ['id' => 'cheie', 'ok' => !$cheieEsuata, 'blocant' => true, 'valoare' => $dosar, 'gazduire' => false];
        }
        return $r;
    }

    private function sonda(array $fisiere = [], bool $capcane = true): array
    {
        return Sonda::verifica((string) $this->stare->get('site_url', $this->origine), $this->public, $this->privat, $this->http(), $fisiere, $capcane, $this->documentRoot);
    }

    // ---- baza ----

    private function postBaza(Cerere $c, int $pas): Raspuns
    {
        $driver = (string) ($c->camp('driver') ?? '');
        $prefix = strtolower(trim((string) ($c->camp('prefix') ?? 'pg_')));
        $date = ['driver' => $driver, 'gazda' => trim((string) ($c->camp('gazda') ?? 'localhost')), 'port' => (int) ($c->camp('port') ?? 3306),
            'nume' => trim((string) ($c->camp('nume') ?? '')), 'utilizator' => trim((string) ($c->camp('utilizator') ?? '')), 'prefix' => $prefix];
        $eroare = null;
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            $eroare = 'instalare.baza.eroare_driver';
        } elseif (!preg_match('~^[a-z][a-z0-9_]{0,15}$~', $prefix)) {
            $eroare = 'instalare.baza.eroare_prefix';
        } elseif ($driver === 'mysql' && ($date['nume'] === '' || $date['utilizator'] === '' || !preg_match('~^[A-Za-z0-9.\-]{1,253}$~', $date['gazda']) || $date['port'] < 1 || $date['port'] > 65535)) {
            $eroare = 'instalare.baza.eroare_campuri';
        }
        if ($eroare !== null) {
            return $this->pagina($pas, $c, ['eroare' => $eroare, 'formular' => $date], 422);
        }
        $descriere = $driver === 'sqlite' ? ['driver' => 'sqlite', 'cale' => $this->privat . '/cazo.sqlite']
            : ['driver' => 'mysql', 'gazda' => $date['gazda'], 'port' => $date['port'], 'nume' => $date['nume'], 'utilizator' => $date['utilizator'], 'parola' => (string) ($c->camp('parola') ?? '')];
        $proba = self::probaBaza($descriere, $prefix, $this->propriaInstalare($descriere, $prefix));
        if ($proba !== null) {
            // o probă SQLite picată (scrierea sau blocarea fișierului) trece alegerea pe MySQL, când serverul îl are.
            if ($driver === 'sqlite' && $proba['cod'] !== 'ocupata') {
                $areMysql = $this->drivere()['mysql'];
                return $this->pagina($pas, $c, ['eroare' => $areMysql ? 'instalare.baza.eroare_sqlite' : 'instalare.baza.eroare_sqlite_singur',
                    'detaliu' => $proba['detaliu'], 'formular' => ['driver' => $areMysql ? 'mysql' : 'sqlite'] + $date], 422);
            }
            return $this->pagina($pas, $c, ['eroare' => 'instalare.baza.eroare_' . $proba['cod'], 'eroare_p' => ['detaliu' => $proba['detaliu']],
                'detaliu' => $proba['cod'] === 'ocupata' ? '' : $proba['detaliu'], 'formular' => $date], 422);
        }
        $chei = Chei::dinFisier($this->privat . '/cheie.bin');
        $baza = $driver === 'sqlite' ? ['driver' => 'sqlite', 'cale' => 'cazo.sqlite']
            : ['driver' => 'mysql', 'gazda' => $date['gazda'], 'port' => $date['port'], 'nume' => $date['nume'], 'utilizator' => $date['utilizator'],
                'parola_c' => (new Cutie($chei->subcheie('config')))->cripteaza($descriere['parola'])];
        $baza['prefix'] = $prefix;
        $vechi = is_file($this->privat . '/' . Loc::CONFIG_LUCRU) ? (array) (static fn (string $f): mixed => require $f)($this->privat . '/' . Loc::CONFIG_LUCRU) : [];
        Config::scrie($this->privat, [
            'id_instalare' => $vechi['id_instalare'] ?? bin2hex(random_bytes(16)),
            'site_url' => (string) $this->stare->get('site_url', $this->origine),
            'limba_implicita' => 'ro',
            'limbi' => ['ro', 'en'],
            'rescriere' => (bool) $this->stare->get('rescriere', false),
            'cale_public' => $this->public,
            'baza' => $baza,
        ], Loc::CONFIG_LUCRU);
        $this->app = null;
        $app = $this->aplicatie();
        $app->migrator()->migreaza();
        if ($app->secrete()->citeste('cron_token') === null) {
            $app->secrete()->pune('cron_token', Codare::jeton(32));
        }
        $this->stare->pune('baza', ['driver' => $driver])->salveaza();
        return $this->avanseaza($pas + 1);
    }

    /** Bazele de date pe care le are PHP-ul serverului (testele le dau prin `$mediu['drivere']`). @return array{mysql:bool, sqlite:bool} */
    private function drivere(): array
    {
        return ($this->mediu['drivere'] ?? []) + ['mysql' => extension_loaded('pdo_mysql'), 'sqlite' => extension_loaded('pdo_sqlite')];
    }

    /** Configurarea în curs indică deja aceeași bază și același prefix (o reluare a pasului bazei de date). */
    private function propriaInstalare(array $d, string $prefix): bool
    {
        if (!is_file($this->privat . '/' . Loc::CONFIG_LUCRU)) {
            return false;
        }
        $v = (array) ((static fn (string $f): mixed => require $f)($this->privat . '/' . Loc::CONFIG_LUCRU)['baza'] ?? []);
        if (($v['prefix'] ?? null) !== $prefix || ($v['driver'] ?? null) !== $d['driver']) {
            return false;
        }
        return $d['driver'] === 'sqlite' || (($v['gazda'] ?? null) === $d['gazda'] && ($v['nume'] ?? null) === $d['nume']);
    }

    /**
     * Proba bazei (15, pasul bazei de date): conexiunea și crearea unui tabel; la SQLite, scrierea și blocarea între două
     * conexiuni (3: SQLite doar după testul de scriere și blocare). Tabelele unei alte instalări cu același prefix
     * opresc pasul, ca instalarea să nu scrie peste datele altui site.
     * @return ?array{cod:string, detaliu:string} null = baza e bună
     */
    public static function probaBaza(array $d, string $prefix, bool $propriaInstalare = false): ?array
    {
        try {
            $b = Baza::conecteaza($d, $prefix);
        } catch (\Throwable $e) {
            return ['cod' => 'conexiune', 'detaliu' => self::scurt($e->getMessage())];
        }
        if (!$propriaInstalare && $b->tabelExista('migrari')) {
            return ['cod' => 'ocupata', 'detaliu' => $prefix];
        }
        $t = $prefix . 'proba_' . bin2hex(random_bytes(3));
        $q = $b->dialect()->citeaza($t);
        try {
            $b->pdo()->exec("CREATE TABLE $q (id INTEGER NOT NULL)");
            $b->pdo()->exec("INSERT INTO $q (id) VALUES (1)");
            $b->pdo()->exec("DROP TABLE $q");
        } catch (\Throwable $e) {
            return ['cod' => 'drepturi', 'detaliu' => self::scurt($e->getMessage())];
        }
        if ($d['driver'] === 'sqlite') {
            try {
                $a = new \PDO('sqlite:' . $d['cale']);
                $a->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $a->exec('PRAGMA busy_timeout = 100');
                $a->exec('BEGIN IMMEDIATE');
                $blocat = false;
                $b2 = new \PDO('sqlite:' . $d['cale']);
                $b2->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $b2->exec('PRAGMA busy_timeout = 100');
                try {
                    $b2->exec('BEGIN IMMEDIATE');
                    $b2->exec('ROLLBACK');
                } catch (\PDOException) {
                    $blocat = true;
                }
                $a->exec('ROLLBACK');
                if (!$blocat) {
                    return ['cod' => 'blocare', 'detaliu' => ''];
                }
            } catch (\Throwable $e) {
                return ['cod' => 'blocare', 'detaliu' => self::scurt($e->getMessage())];
            }
        }
        return null;
    }

    // ---- EasyCheck ----

    private function postEasycheck(Cerere $c, int $pas): Raspuns
    {
        $app = $this->aplicatie();
        $a = (string) ($c->camp('actiune') ?? '');
        // prima întrebare a pasului, „Legați site-ul de EasyCheck?”. Răspunsul pune modul site-ului: cu cheia
        // EasyCheck sau de sine stătător, cu datele completate chiar aici.
        if ($a === 'alege') {
            $mod = $c->camp('mod') === Mod::LOCAL ? Mod::LOCAL : Mod::EASYCHECK;
            if ($mod !== ($this->stare->get('mod_site') ?? Mod::EASYCHECK)) {
                // Răspunsul schimbat: nimic citit în celălalt mod nu rămâne (oglinda și rezumatul pasului).
                $app->ecOglinda()->goleste();
                $this->stare->pune('ec', []);
            }
            Mod::alege($app->setari(), $mod);
            $app->uitaLegatura();
            $this->stare->pune('mod_site', $mod)->salveaza();
            return Raspuns::redirectare($this->url(['pas' => $pas]));
        }
        if ($a === 'local') {
            return $this->postLocal($c, $pas);
        }
        if ($a === 'cheie' && $app->modLocal()) {
            Mod::alege($app->setari(), Mod::EASYCHECK);
            $app->uitaLegatura();
            $this->stare->pune('mod_site', Mod::EASYCHECK)->salveaza();
        }
        if ($a === 'cheie') {
            $vechi = $app->ecLicenta()->cheie();
            try {
                $rez = $app->ecConectare()->legaCheia((string) ($c->camp('cheie') ?? ''));
            } catch (\InvalidArgumentException) {
                return $this->pagina($pas, $c, ['eroare' => 'instalare.ec.eroare_forma'], 422);
            } catch (EroareApi $e) {
                return $this->pagina($pas, $c, ['eroare' => 'instalare.ec.eroare_api', 'eroare_p' => ['cod' => (string) ($e->cod ?? 'http_' . $e->raspuns->status)]], 422);
            } catch (EroareTransport | \UnexpectedValueException) {
                return $this->pagina($pas, $c, ['eroare' => 'instalare.ec.eroare_retea'], 422);
            }
            // „Folosiți altă cheie” cu o cheie de alt fel (de probă în loc de live) sau a altei proprietăți golește
            // tot ce s-a citit cu cheia veche; sincronizarea de mai jos citește totul din nou, cu cheia nouă.
            if ($vechi !== null && (($vechi['mod'] ?? null) !== ($rez['mod'] ?? null) || ($vechi['proprietate'] ?? null) !== ($rez['proprietate'] ?? null))) {
                $app->ecOglinda()->goleste();
            }
            $ec = ['rezumat' => $rez, 'anunturi' => null, 'citite' => [], 'erori' => [], 'incercari' => 0];
            // site-ul se declară la EasyCheck (programul, versiunea, panoul); cu un EasyCheck mai vechi, nimic nu pleacă.
            $app->stareSite()->declara();
            if ($rez['scop'] === 'rezervari') {
                try {
                    $ec['anunturi'] = $app->ecConectare()->inregistreazaAnunturile();
                } catch (EroareApi $e) {
                    $ec['anunturi'] = ['inregistrat' => false, 'primit' => false, 'motiv' => (string) ($e->cod ?? 'http_' . $e->raspuns->status)];
                } catch (EroareTransport | \UnexpectedValueException) {
                    $ec['anunturi'] = ['inregistrat' => false, 'primit' => false, 'motiv' => 'retea'];
                }
            }
            $this->stare->pune('ec', $ec)->salveaza();
            return Raspuns::redirectare($this->url(['pas' => $pas, 'sincronizare' => 1]));
        }
        if ($a === 'reia') {
            $ec = (array) $this->stare->get('ec', []);
            $this->stare->pune('ec', ['erori' => [], 'incercari' => 0] + $ec)->salveaza();
            return Raspuns::redirectare($this->url(['pas' => $pas, 'sincronizare' => 1]));
        }
        $ec = (array) $this->stare->get('ec', []);
        if (($ec['rezumat']['scop'] ?? null) !== 'rezervari' || !$this->sincronizareGata($ec)) {
            return $this->pagina($pas, $c, ['eroare' => 'instalare.ec.eroare_incomplet'], 409);
        }
        return $this->avanseaza($pas + 1);
    }

    /**
     * Pasul 4 fără cheie EasyCheck: proprietatea (numele, felul, adresa, orele, regulile casei, contactul),
     * regula de rezervare (sejurul minim, avansul, anularea gratuită) și prima cameră, cu cele două prețuri. Celelalte
     * camere, descrierile, pozele și calendarul le adaugă gazda din panou. Datele firmei urmează la pasul următor.
     */
    private function postLocal(Cerere $c, int $pas): Raspuns
    {
        $app = $this->aplicatie();
        if (!$app->modLocal()) {
            Mod::alege($app->setari(), Mod::LOCAL);
            $app->uitaLegatura();
        }
        $d = $app->localDepozit();
        $f = \Cazo\Controlere\AdminLocal::citesteProprietate($c, $d->proprietate());
        $cam = Depozit::valideazaCamera(['titlu' => $c->camp('camera_titlu'), 'unitati' => $c->camp('camera_unitati') ?? '1', 'oaspeti_max' => $c->camp('camera_oaspeti_max'),
            'pret_noapte' => $c->camp('camera_pret_noapte'), 'pret_weekend' => $c->camp('camera_pret_weekend')]);
        $coduri = ['titlu' => 'admin.local.cam.eroare_titlu', 'lungime' => 'admin.local.cam.eroare_lungime', 'unitati' => 'admin.local.cam.eroare_unitati',
            'oaspeti' => 'admin.local.cam.eroare_oaspeti', 'pret' => 'admin.local.cam.eroare_pret'];
        $erori = $f['erori'];
        foreach ($cam['erori'] as $k => $cod) {
            $erori['camera_' . $k] = $coduri[$cod];
        }
        $valori = $f['valori'];
        foreach (['titlu', 'unitati', 'oaspeti_max', 'pret_noapte', 'pret_weekend'] as $k) {
            $valori['camera_' . $k] = mb_substr(trim((string) ($c->camp('camera_' . $k) ?? '')), 0, 120);
        }
        if ($erori !== []) {
            return $this->pagina($pas, $c, ['local_valori' => $valori, 'local_erori' => $erori, 'eroare' => 'admin.local.eroare.campuri'], 422);
        }
        $d->salveazaProprietate($f['proprietate']);
        $d->salveazaReguli($f['reguli']);
        $prima = $d->camere()[0]['id'] ?? null;
        $d->salveazaCamera($prima, $cam['valori']);
        $app->localDupaScriere(array_keys(Oglinda::RESURSE));
        // Restul instalatorului citește rezumatul „cheii” (proprietatea, modul, sursa prețului) ca la EasyCheck.
        $rezumat = $app->ecLicenta()->cheie();
        $this->stare->pune('mod_site', Mod::LOCAL)->pune('ec', ['rezumat' => $rezumat, 'anunturi' => null, 'citite' => array_fill_keys(array_keys(Oglinda::RESURSE), 'local'),
            'erori' => [], 'incercari' => 0])->salveaza();
        $app->ecOglinda()->schimbaContinutul();
        return $this->avanseaza($pas + 1);
    }

    private function sincronizareGata(array $ec): bool
    {
        return array_diff(self::RESURSE_OBLIGATORII, array_keys((array) ($ec['citite'] ?? []))) === [];
    }

    /** O tură a sincronizării inițiale (pagina cu bara de progres se reîncarcă până la capăt). */
    private function sincronizeaza(): array
    {
        $ec = (array) $this->stare->get('ec', []);
        $toate = array_keys(Oglinda::RESURSE);
        $ramase = array_values(array_diff($toate, array_keys((array) ($ec['citite'] ?? [])), array_keys((array) ($ec['erori'] ?? []))));
        if ($ramase !== []) {
            $r = $this->aplicatie()->ecConectare()->sincronizeazaTot($this->aplicatie()->ecSincronizare(), 8.0);
            foreach ((array) ($r['citite'] ?? []) as $res => $x) {
                $ec['citite'][$res] = (string) $x;
            }
            $ec['incercari'] = (int) ($ec['incercari'] ?? 0) + 1;
            // După trei ture, o resursă rămasă cu eroare se arată gazdei (cu „Reîncercați”), în loc de o așteptare fără capăt.
            foreach ((array) ($r['erori'] ?? []) as $res => $x) {
                if (!isset($ec['citite'][$res]) && $ec['incercari'] >= 3) {
                    $ec['erori'][$res] = (string) $x;
                }
            }
            $this->stare->pune('ec', $ec)->salveaza();
        }
        return $ec;
    }

    // ---- adresa site-ului ----

    /**
     * Pasul „Adresa site-ului”, după „Legați site-ul de EasyCheck?”: fără cheie, doar domeniul gazdei; cu cheie, domeniul
     * gazdei sau subdomeniul gratuit cazo.ro. Pe un `*.cazo.ro`, instalatorul întreabă EasyCheck dacă subdomeniul e rezervat
     * pentru cheie și îl leagă; pe domeniul propriu verifică doar că adresa din config se potrivește cu cea la care rulează.
     * Subdomeniul se rezervă doar în EasyCheck › Site web: aici, pașii și legătura spre acolo.
     */
    private function postAdresa(Cerere $c, int $pas): Raspuns
    {
        $app = $this->aplicatie();
        $a = (string) ($c->camp('actiune') ?? '');
        if ($a === 'alege') {
            $fel = $c->camp('fel') === 'cazo' && !$app->modLocal() ? 'cazo' : 'domeniu';
            $this->stare->pune('adresa', ['fel' => $fel])->salveaza();
            return Raspuns::redirectare($this->url(['pas' => $pas]));
        }
        if ($a === 'potriveste') {
            $this->potrivesteAdresa($app);
            return Raspuns::redirectare($this->url(['pas' => $pas]));
        }
        if ($a === 'verifica') {
            return Raspuns::redirectare($this->url(['pas' => $pas]));
        }
        $d = $this->dateAdresa($app);
        if (!$d['potrivire']) {
            return $this->pagina($pas, $c, ['eroare' => 'instalare.adresa.eroare_potrivire', 'eroare_p' => ['config' => $d['config'], 'curenta' => $this->origine]], 409);
        }
        if ($d['fel'] === 'cazo') {
            $rez = $d['rezervare'];
            if (($rez['stare'] ?? null) !== 'rezervat' || ($d['pe_cazo'] !== null && $rez['domeniu'] !== Adresa::gazda($this->origine))) {
                return $this->pagina($pas, $c, ['eroare' => 'instalare.adresa.eroare_nerezervat'], 409);
            }
        }
        $app->adresa()->alege($d['pe_cazo'] !== null ? 'cazo' : 'domeniu');
        $this->stare->pune('adresa', ['fel' => $d['fel'], 'legat' => $d['pe_cazo'] !== null, 'rezervat' => $d['rezervare']['domeniu'] ?? null])->salveaza();
        return $this->avanseaza($pas + 1);
    }

    /** „Folosiți adresa de acum”: config-ul instalării primește adresa la care rulează asistentul. */
    private function potrivesteAdresa(Aplicatie $app): void
    {
        $cfg = $this->privat . '/' . Loc::CONFIG_LUCRU;
        $v = (array) (static fn (string $f): mixed => require $f)($cfg);
        $v['site_url'] = $this->origine;
        Config::scrie($this->privat, $v, Loc::CONFIG_LUCRU);
        $this->stare->pune('site_url', $this->origine)->salveaza();
        $this->app = null;
    }

    /**
     * Datele pasului adresei: modul, adresa din config și cea la care rulează asistentul, subdomeniul cazo.ro al gazdei
     * (când rulează pe unul) și, cu cheie, ce spune EasyCheck despre subdomeniul rezervat.
     */
    private function dateAdresa(Aplicatie $app): array
    {
        $local = $app->modLocal();
        $config = $app->config()->origine();
        $peCazo = $local ? null : Adresa::numeCazo(Adresa::gazda($this->origine));
        $ales = (string) ($this->stare->get('adresa')['fel'] ?? '');
        $fel = $local ? 'domeniu' : ($peCazo !== null ? 'cazo' : ($ales === 'cazo' ? 'cazo' : 'domeniu'));
        $rez = $fel === 'cazo' ? $app->adresa()->rezervat() : null;
        return ['local' => $local, 'config' => $config, 'curenta' => $this->origine, 'potrivire' => Adresa::gazda($config) === Adresa::gazda($this->origine),
            'pe_cazo' => $peCazo, 'fel' => $fel, 'rezervare' => $rez, 'url_ec' => Adresa::EASYCHECK_SITE_WEB,
            'legat' => $peCazo !== null && ($rez['stare'] ?? null) === 'rezervat' && $rez['domeniu'] === Adresa::gazda($this->origine)];
    }

    // ---- datele legale și termenele de păstrare ----

    private function postLegal(Cerere $c, int $pas): Raspuns
    {
        $app = $this->aplicatie();
        $api = (array) ($app->ecOglinda()->corp('proprietate')['operator'] ?? []);
        $erori = [];
        foreach (BlocLegal::CAMPURI as $camp) {
            if (is_string($api[$camp] ?? null) && trim($api[$camp]) !== '') {
                continue;
            }
            $v = trim((string) ($c->camp('legal_' . $camp) ?? ''));
            if ($camp === 'sediu_tip' && $v !== '' && !in_array($v, ['social', 'profesional'], true)) {
                $v = '';
            }
            $v = mb_substr($v, 0, 500);
            $app->baza()->executa('DELETE FROM {date_legale} WHERE camp = ?', [$camp]);
            if ($v !== '') {
                $app->baza()->insereaza('date_legale', ['camp' => $camp, 'valoare' => $v, 'actualizat_la' => $app->ceas()->moment()]);
            }
        }
        // forma trimisă de EasyCheck are întâietate; altfel cea din formular (dedusă și lăsată așa, ori aleasă).
        $forma = BlocLegal::formaEasyCheck($app) ?? (string) ($c->camp('forma') ?? '');
        if (!in_array($forma, self::FORME, true)) {
            $erori[] = 'forma';
        }
        // microîntreprinderile sunt scutite de Legea 232/2022; celelalte publică pagina Accesibilitate.
        $micro = (string) ($c->camp('micro') ?? '');
        if (!in_array($micro, ['da', 'nu'], true)) {
            $erori[] = 'micro';
        }
        // cele patru termene, între pragurile din Gdpr::LIMITE.
        $scrise = [];
        foreach (self::PASTRARE_GDPR as $camp => $cheie) {
            $scrise[$cheie] = $c->camp($camp);
        }
        [$termene, $gresite] = Gdpr::verificaTermene($scrise);
        foreach ($gresite as $cheie) {
            $erori[] = (string) array_search($cheie, self::PASTRARE_GDPR, true);
        }
        if ($erori !== []) {
            return $this->pagina($pas, $c, ['erori' => $erori], 422);
        }
        $app->setari()->pune('legal.forma', $forma);
        $app->setari()->pune(BlocLegal::ACCESIBILITATE, $micro === 'nu');
        // Termenele alese de gazdă, în aceeași setare ca în panou (GDPR); anonimizarea le folosește de acum.
        if (($gresit = $app->gdpr()->alegeTermene($termene, null)) !== null) {
            return $this->pagina($pas, $c, ['erori' => [(string) array_search($gresit, self::PASTRARE_GDPR, true)]], 422);
        }
        $app->ecOglinda()->schimbaContinutul();
        return $this->avanseaza($pas + 1);
    }

    // ---- plățile și expeditorul e-mailurilor ----

    private function postPlati(Cerere $c, int $pas): Raspuns
    {
        $app = $this->aplicatie();
        // trei bife independente, cel puțin una; cu mai multe, oaspetele alege la rezervare.
        $transfer = \Cazo\Controlere\AdminTransfer::dinCerere($c);
        $cuCard = $c->camp('metoda_card') === '1';
        $metode = ModPlata::dinBife($cuCard, $c->camp('metoda_cash') === '1', $transfer['bifat']);
        if ($metode === null) {
            return $this->pagina($pas, $c, ['eroare' => 'admin.plati.eroare_metode_lipsa'], 422);
        }
        // contul transferului bancar se salvează odată cu bifa lui, din același formular; bifa cere
        // titularul și IBAN-ul (erorile apar lângă câmpuri). Fără bifă, câmpurile contului stau ascunse și rămân neatinse.
        $cont = \Cazo\Rezervare\ContTransfer::valideaza($transfer['valori'], $transfer['bifat']);
        if ($transfer['bifat'] && $cont['erori'] !== []) {
            return $this->pagina($pas, $c, ['eroare' => 'instalare.plati.eroare_transfer'], 422);
        }
        $cheie = trim((string) ($c->camp('cheie') ?? ''));
        if ($cuCard && $cheie !== '') {
            $modEc = $app->ecLicenta()->stare()['mod'];
            if (VerificareCheie::forma($cheie) === null && $modEc !== null && !$app->modLocal() && !Licenta::compatibilCuStripe((string) $modEc, $cheie)) {
                return $this->pagina($pas, $c, ['eroare' => 'admin.plati.eroare_mod_mixt'], 422);
            }
            if (StripeClient::mod($cheie) === 'live' && !$app->config()->https()) {
                return $this->pagina($pas, $c, ['eroare' => 'admin.plati.eroare_https'], 422);
            }
            $r = $app->stripe()->salveazaCheie($cheie);
            $app->localDupaScriere(['eu']);
            if ($r['motiv'] !== null && $r['motiv'] !== 'permisiuni') {
                return $this->pagina($pas, $c, ['eroare' => 'admin.plati.eroare_' . $r['motiv']], 422);
            }
        }
        if ($cuCard && !$app->stripe()->configurat()) {
            return $this->pagina($pas, $c, ['eroare' => 'admin.plati.eroare_metode_fara_stripe'], 422);
        }
        // ce primește oaspetele când gazda anulează, alegere obligatorie, fără variantă implicită (ca termenele GDPR).
        $anulareGazda = (string) ($c->camp('anulare_gazda') ?? '');
        if (!in_array($anulareGazda, \Cazo\Rezervare\Anulare::GAZDA, true)) {
            return $this->pagina($pas, $c, ['eroare' => 'admin.plati.eroare_anulare_gazda'], 422);
        }
        if ($transfer['bifat']) {
            $app->contTransfer()->salveaza($cont['valori']);
        }
        $app->modPlata()->alegeMetode($metode);
        $app->setari()->pune(\Cazo\Rezervare\Anulare::SETARE_GAZDA, $anulareGazda);
        // la „Prețul din EasyCheck”, ce plătește oaspetele pe site pe lângă nopți (aceeași setare ca în panou).
        if (($app->ecLicenta()->cheie()['sursa'] ?? null) === 'easycheck' && $c->camp('include_trimis') === '1') {
            // încasarea online a taxei cere o plată făcută la rezervare, cu cardul sau prin transfer bancar (la
            // transfer, taxa face parte din suma transferului); fără ele (varianta e ascunsă în formular), taxa rămâne
            // arătată în preț și încasată la sosire.
            $taxa = (string) ($c->camp('taxa_locala') ?? '');
            $app->alegeCePlateste($c->camp('include_taxe') === '1', $taxa === 'online' && !$cuCard && !$transfer['bifat'] ? 'la_sosire' : $taxa);
        }
        // expeditorul e mereu adresa site-ului (rezervari@domeniu), cu răspunsurile spre adresa gazdei din
        // EasyCheck; schimbarea lui rămâne în panou. Pe o adresă de probă fără domeniu (localhost, un IP), setarea rămâne
        // goală, iar e-mailurile își iau expeditorul ca în Sabloane.
        $expeditor = self::expeditorSite($app);
        if ($expeditor !== null) {
            $app->setari()->pune('email.expeditor', $expeditor);
        }
        $this->stare->pune('stripe_live', $app->stripe()->mod() === 'live')->salveaza();
        return $this->avanseaza($pas + 1);
    }

    /** Expeditorul e-mailurilor: rezervari@<domeniul site-ului>, cu numele proprietății; null fără domeniu. */
    public static function expeditorSite(Aplicatie $app): ?array
    {
        $domeniu = self::domeniu($app->config()->origine());
        $adresa = 'rezervari@' . $domeniu;
        if (filter_var($domeniu, FILTER_VALIDATE_IP) !== false || !str_contains($domeniu, '.') || filter_var($adresa, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return ['fel' => 'site', 'adresa' => mb_strtolower($adresa), 'nume' => mb_substr($app->modelVedere('ro')->nume(), 0, 100)];
    }

    public static function domeniu(string $origine): string
    {
        $g = strtolower((string) parse_url($origine, PHP_URL_HOST));
        return str_starts_with($g, 'www.') ? substr($g, 4) : $g;
    }

    /**
     * Livrarea e-mailurilor de pe domeniul site-ului: înregistrarea SPF (un TXT „v=spf1” pe domeniu) și
     * semnătura DKIM pusă de cPanel (un TXT pe `default._domainkey.<domeniu>`). Fiecare e `gasit`, `lipsa` sau
     * `necunoscut` (fără DNS, fără răspuns, un IP ori localhost). $dns: \Closure(string): ?list<string>, textele TXT ale
     * unui nume sau null când serverul DNS a lăsat cererea fără răspuns; implicit dns_get_record.
     * @return array{spf:string, dkim:string}
     */
    public static function livrare(string $domeniu, ?\Closure $dns = null): array
    {
        if ($domeniu === '' || !str_contains($domeniu, '.') || filter_var($domeniu, FILTER_VALIDATE_IP) !== false || str_ends_with($domeniu, '.localdomain')) {
            return ['spf' => 'necunoscut', 'dkim' => 'necunoscut'];
        }
        $dns ??= static function (string $nume): ?array {
            if (!function_exists('dns_get_record')) {
                return null;
            }
            $r = @dns_get_record($nume, DNS_TXT);
            return is_array($r) ? array_map(static fn (array $x): string => (string) ($x['txt'] ?? ''), $r) : null;
        };
        $are = static function (?array $txt, \Closure $potrivit): string {
            if ($txt === null) {
                return 'necunoscut';
            }
            foreach ($txt as $x) {
                if ($potrivit(strtolower(trim((string) $x)))) {
                    return 'gasit';
                }
            }
            return 'lipsa';
        };
        return ['spf' => $are($dns($domeniu), static fn (string $x): bool => str_starts_with($x, 'v=spf1')),
            'dkim' => $are($dns(self::DKIM_SELECTOR . '._domainkey.' . $domeniu), static fn (string $x): bool => str_contains($x, 'v=dkim1') || str_contains($x, 'p='))];
    }

    /**
     * O valoare venită din EasyCheck, gata de arătat în asistent, la fel ca în subsolul site-ului: literele cu
     * sedilă corectate (Format::diacritice), capitalul social cu „lei” (BlocLegal::capital), felul sediului cu numele lui.
     * $public e catalogul site-ului, $t al instalatorului.
     */
    public static function dinEasyCheck(string $camp, string $valoare, Traducator $public, Traducator $t): string
    {
        $v = Format::diacritice(trim($valoare));
        return match (true) {
            $camp === 'capital_social' => BlocLegal::capital($v, $public),
            $camp === 'sediu_tip' && in_array($v, ['social', 'profesional'], true) => $t->t('instalare.legal.sediu.' . $v),
            default => $v,
        };
    }

    // ---- tema ----

    /**
     * Temele oficiale ale lansării, fără biblioteca și scheletul autorilor (`_*`) și fără temele private, care
     * se activează doar pe site-ul lor, din config.php.
     * @return list<array{slug:string, nume:array, pentru:string}>
     */
    public static function teme(string $radacina): array
    {
        $r = [];
        foreach (glob($radacina . '/teme/*/theme.json') ?: [] as $f) {
            $slug = basename(dirname($f));
            if ($slug[0] === '_' || \Cazo\Teme\Tema::estePrivata(dirname($f))) {
                continue;
            }
            $j = json_decode((string) file_get_contents($f), true);
            if (is_array($j)) {
                $r[] = ['slug' => $slug, 'nume' => $j['nume'] ?? ['ro' => $slug, 'en' => $slug], 'pentru' => (string) ($j['pentru'] ?? '')];
            }
        }
        usort($r, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        return $r;
    }

    /**
     * Propunerea după tipul proprietății (15, pasul temei): tema al cărei slug e chiar tipul („hotel” →
     * Hotel, deși și „pentru” al Falezei pomenește hotelul), altfel prima temă, în ordinea slugurilor, al cărei „pentru”
     * pomenește tipul ca un cuvânt întreg, fără diacritice și fără deosebire între litere mari și mici („vila” se
     * potrivește cu „vilă”, „cabana” cu „cabană”, dar „apartament” nu se potrivește cu „apartamente în regim hotelier”,
     * nici „hotel” cu „hotelier”). Fără potrivire, tema implicită (Studio). Cu textele de azi:
     * hotel și motel → Hotel, pensiune → Rustic, vilă → Faleză, apartament și garsonieră → Bulevard, casă și cabană →
     * Montan, hostel, camere și restul → Studio.
     */
    public static function propunere(array $teme, string $tip): ?string
    {
        $tip = self::faraDiacritice($tip);
        if ($tip !== '') {
            if (in_array($tip, array_column($teme, 'slug'), true)) {
                return $tip;
            }
            $cuvant = '~(?<![\p{L}\p{N}])' . preg_quote($tip, '~') . '(?![\p{L}\p{N}])~u';
            foreach ($teme as $t) {
                foreach (array_map('trim', explode(',', self::faraDiacritice((string) $t['pentru']))) as $p) {
                    if ($p !== '' && preg_match($cuvant, $p) === 1) {
                        return $t['slug'];
                    }
                }
            }
        }
        return in_array(\Cazo\Teme\Tema::IMPLICITA, array_column($teme, 'slug'), true) ? \Cazo\Teme\Tema::IMPLICITA : ($teme[0]['slug'] ?? null);
    }

    /** Textul cu litere mici și fără diacritice (ă, â, î, ș, ț, cu formele vechi cu sedilă), pentru propunerea temei. */
    private static function faraDiacritice(string $s): string
    {
        return strtr(mb_strtolower(trim($s)), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    }

    private function postTema(Cerere $c, int $pas): Raspuns
    {
        $slug = (string) ($c->camp('tema') ?? '');
        if (!in_array($slug, array_column(self::teme(Aplicatie::radacina()), 'slug'), true)) {
            return $this->pagina($pas, $c, ['eroare' => 'instalare.tema.eroare'], 422);
        }
        $app = $this->aplicatie();
        $app->setari()->pune('tema.activa', $slug);
        $app->ecOglinda()->schimbaContinutul();
        return $this->avanseaza($pas + 1);
    }

    /** Previzualizarea unei teme cu datele gazdei: pagina de început, cu noindex, deschisă într-o filă nouă. */
    private function previzualizare(Cerere $c): Raspuns
    {
        $slug = (string) ($c->param('tema') ?? '');
        if ((int) $this->stare->get('pas', 1) < self::nr('tema') || !in_array($slug, array_column(self::teme(Aplicatie::radacina()), 'slug'), true)) {
            return $this->randeaza('eroare', ['mesaj' => 'instalare.eroare.negasit', 'parametri' => []], 404);
        }
        $app = $this->aplicatie();
        $app->temaPrevizualizata = $slug;
        $limba = $this->limba;
        $r = Prezentare::acasa($app, Cerere::creeaza('GET', $limba === 'ro' ? '/' : '/en/'), [], $limba);
        $r->deTinut = false;
        return Antete::aplica($r, 'public', $this->origine, ['noindex' => true, 'metoda' => 'GET', 'tema_cu_js' => $app->temaActiva()->areJs()])
            ->antet('Cache-Control', 'no-store');
    }

    // ---- administratorul ----

    private function postAdministrator(Cerere $c, int $pas): Raspuns
    {
        $app = $this->aplicatie();
        $email = mb_strtolower(trim((string) ($c->camp('email') ?? '')));
        $nume = mb_substr(trim((string) ($c->camp('nume') ?? '')), 0, 100);
        $parola = (string) ($c->camp('parola') ?? '');
        $limba = (string) ($c->camp('limba_panou') ?? $this->limba);
        $date = ['email' => $email, 'nume' => $nume, 'limba_panou' => $limba];
        $eroare = null;
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $eroare = 'instalare.admin.eroare_email';
        } elseif (!in_array($limba, ['ro', 'en'], true)) {
            $eroare = 'instalare.admin.eroare_limba';
        } elseif (mb_strlen($parola) < Parole::MINIM || strlen($parola) > 4096) {
            $eroare = 'instalare.admin.eroare_scurta';
        } elseif (!hash_equals($parola, (string) ($c->camp('parola2') ?? ''))) {
            $eroare = 'instalare.admin.eroare_diferite';
        } elseif (ParoleCompromise::compromisa($parola, [(string) strstr($email, '@', true), self::domeniu($app->config()->origine()),
            explode('.', self::domeniu($app->config()->origine()))[0], $app->modelVedere('ro')->nume()])) {
            $eroare = 'instalare.admin.eroare_compromisa';
        }
        if ($eroare !== null) {
            return $this->pagina($pas, $c, ['eroare' => $eroare, 'formular' => $date], 422);
        }
        $id = $app->sesiuneAdmin()->creeazaUtilizator($email, $nume, $parola, 'proprietar', $limba);
        // Pasul reluat cu altă adresă (legătura „Schimbați” din rezumat): contul creat înainte de instalator dispare.
        $vechi = (int) ($this->stare->get('administrator')['id'] ?? 0);
        if ($vechi > 0 && $vechi !== $id) {
            $app->baza()->executa('DELETE FROM {utilizatori} WHERE id = ?', [$vechi]);
        }
        $this->stare->pune('administrator', ['id' => $id, 'email' => $email])->salveaza();
        return $this->avanseaza($pas + 1);
    }

    // ---- cronul ----

    private function postCron(Cerere $c, int $pas): Raspuns
    {
        if (($c->camp('actiune') ?? '') === 'verifica') {
            return Raspuns::redirectare($this->url(['pas' => $pas, 'verificat' => 1]));
        }
        return $this->avanseaza($pas + 1);
    }

    /**
     * Ultima rulare a sarcinilor: `la` e ora arătată gazdei, în fusul site-ului și în formatul limbii
     * (Format::moment: „20 nov. 2026, 12:04”), iar `sursa` e una dintre SURSE_CRON sau „alta” (textul ei vine din
     * instalare.cron.ultima.<sursa>). Rulatorul notează cu „cpanel” și cronul extern, și linia de comandă.
     * @return array{ok:bool, la:?string, sursa:?string}
     */
    private function stareCron(): array
    {
        $u = $this->aplicatie()->setari()->proaspat('cron.ultima');
        if (!is_array($u) || !is_string($u['la'] ?? null)) {
            return ['ok' => false, 'la' => null, 'sursa' => null];
        }
        $la = \Cazo\Nucleu\Ceas::dinMoment($u['la']);
        $sursa = in_array($u['sursa'] ?? null, self::SURSE_CRON, true) ? (string) $u['sursa'] : 'alta';
        return ['ok' => $sursa === 'cpanel' && $this->aplicatie()->ceas()->acum() - $la <= self::CRON_PROASPAT,
            'la' => Format::moment($u['la'], $this->limba), 'sursa' => $sursa];
    }

    // ---- încheierea ----

    private function postGata(Cerere $c, int $pas): Raspuns
    {
        $app = $this->aplicatie();
        // gazda a ales forma cu majuscule a titularului și a băncii, propusă în rezumat.
        if ($c->camp('actiune') === 'majuscule') {
            $app->contTransfer()->aplicaMajuscule();
            return Raspuns::redirectare($this->url(['pas' => $pas]));
        }
        // Sonda se repetă pe fișierele reale, înainte ca instalarea să se încheie (varianta B).
        $s = $this->sonda(['cheie.bin', Loc::CONFIG_LUCRU, 'cazo.sqlite', Loc::COD, Loc::STARE], false);
        if (!$s['ok']) {
            return $this->pagina($pas, $c, ['sonda' => $s], 200);
        }
        $lipsa = $this->pasiLipsa();
        if ($lipsa !== []) {
            return $this->pagina($pas, $c, ['lipsa' => $lipsa], 409);
        }
        $cfg = $app->config()->toate();
        $cfg['instalat_la'] = $app->ceas()->moment();
        Config::scrie($this->privat, $cfg);
        @unlink($this->privat . '/' . Loc::CONFIG_LUCRU);
        @unlink($this->privat . '/' . Loc::COD);
        Loc::scrieMarcaj($this->privat, $cfg['instalat_la']);
        $this->stare->pune('incheiata_la', $cfg['instalat_la'])->pune('sesiune_hash', null)->pune('cod_hash', null)->salveaza();
        // căutarea actualizărilor a rulat în timpul instalării fără să caute nimic („instalarea e în curs”); rândul ei
        // se șterge, ca sarcina să ruleze din nou la următorul cron, nu abia peste o zi.
        try {
            $app->baza()->executa("DELETE FROM {sarcini} WHERE nume = 'actualizari'");
        } catch (\Throwable) {
        }
        // Semnalul „instalare încheiată”: pleacă acum și, până la confirmare, la fiecare
        // rulare a cronului; cu cheie de probă și cu cheie live, fără cheie nimic.
        $app->stareSite()->instalareIncheiata();
        $app->jurnal()->info('Instalarea s-a încheiat', ['varianta' => $this->stare->get('varianta'), 'baza' => $this->stare->get('baza')['driver'] ?? null]);
        $app->ecOglinda()->schimbaContinutul();
        $this->cookies[] = $this->numeCookie(self::COOKIE) . '=; Path=/; Max-Age=0; HttpOnly; SameSite=Strict' . ($this->httpsDirect ? '; Secure' : '');
        return $this->randeaza('urmeaza', [
            'url_site' => $app->url()->cale('/'),
            'url_panou' => $app->url()->cale('/admin/'),
            'live' => $app->ecLicenta()->stare()['mod'] === 'live',
            'cheie_fisier' => Loc::afisat($this->public, $this->privat, 'cheie.bin', $this->documentRoot),
        ]);
    }

    /** Pașii fără rezultat salvat (o instalare încheiată le are pe toate). @return list<int> */
    private function pasiLipsa(): array
    {
        $app = $this->aplicatie();
        $l = [];
        if ($app->modLocal() ? ($app->localDepozit()->proprietate()['nume'] === '' || $app->localDepozit()->camere() === [])
            : ($this->stare->get('ec')['rezumat']['scop'] ?? null) !== 'rezervari') {
            $l[] = self::nr('easycheck');
        }
        if ($app->gdpr()->termene() === null) {
            $l[] = self::nr('legal');
        }
        if ($app->modPlata()->metode() === null || \Cazo\Rezervare\Anulare::gazdaSite($app) === null) {
            $l[] = self::nr('plati');
        }
        if ($app->baza()->valoare("SELECT 1 FROM {utilizatori} WHERE rol = 'proprietar' AND activ = 1 LIMIT 1") === null) {
            $l[] = self::nr('administrator');
        }
        return $l;
    }

    /**
     * Rezumatul alegerilor de pe pagina de încheiere, citit din setările salvate, cu pasul la care se schimbă
     * fiecare. Valorile care au texte în catalogul panoului (metodele de plată, taxa locală) ajung în șablon drept
     * valorile modelului (ModPlata::bife, Aplicatie::taxaLocalaAleasa), iar șablonul le ia textele de acolo.
     * @return list<array<string, mixed>>
     */
    private function rezumat(): array
    {
        $app = $this->aplicatie();
        $rand = fn (string $id, string $pas, array $d): array => ['id' => $id, 'url' => $this->url(['pas' => self::nr($pas)])] + $d;
        $r = [];
        $r[] = $rand('proprietate', 'easycheck', ['nume' => Format::diacritice($app->modelVedere($this->limba)->nume()), 'live' => $app->ecLicenta()->stare()['mod'] === 'live']);
        // datele firmei, cum apar pe site: literele corectate, capitalul social cu „lei”, forma de organizare.
        $public = $app->traducator($this->limba);
        $o = (new BlocLegal($app, $public, $this->limba))->operator();
        $r[] = $rand('legal', 'legal', ['denumire' => Format::diacritice((string) ($o['denumire'] ?? '')), 'cui' => (string) ($o['cui'] ?? ''), 'forma' => BlocLegal::forma($app),
            'capital' => $o['capital_social'] !== null ? BlocLegal::capital(Format::diacritice($o['capital_social']), $public) : '']);
        // cu transferul bancar între metode, rezumatul arată și contul în care plătesc oaspeții.
        $r[] = $rand('plati', 'plati', ['metode' => array_keys(array_filter(ModPlata::bife($app->modPlata()->metode()))),
            'cont' => $app->modPlata()->are('transfer') ? \Cazo\Rezervare\Iban::grupat($app->contTransfer()->citeste()['iban']) : '',
            'majuscule' => $app->modPlata()->are('transfer') ? $app->contTransfer()->propunere() : null]);
        if (($app->ecLicenta()->cheie()['sursa'] ?? null) === 'easycheck') {
            $r[] = $rand('include', 'plati', ['taxe' => $app->includeTotal()['taxe'], 'taxa_locala' => $app->taxaLocalaAleasa()]);
        }
        $slug = (string) $app->setari()->get('tema.activa', '');
        foreach (self::teme(Aplicatie::radacina()) as $t) {
            if ($t['slug'] === $slug) {
                $r[] = $rand('tema', 'tema', ['nume' => (string) ($t['nume'][$this->limba] ?? $t['nume']['ro'] ?? $slug)]);
            }
        }
        $email = $app->baza()->valoare("SELECT email FROM {utilizatori} WHERE rol = 'proprietar' AND activ = 1 ORDER BY id DESC LIMIT 1");
        if ($email !== null) {
            $r[] = $rand('administrator', 'administrator', ['email' => (string) $email]);
        }
        return $r;
    }

    // ---- paginile ----

    /** Datele fiecărui pas, pentru șablonul lui. */
    private function pagina(int $pas, Cerere $c, array $extra = [], int $status = 200): Raspuns
    {
        $d = ['pas' => $pas] + $extra;
        $nume = self::PASI[$pas];
        switch ($nume) {
            case 'cod':
                $d['fisier'] = Loc::afisat($this->public, $this->privat, Loc::COD, $this->documentRoot);
                break;
            case 'verificari':
                $d['gazduire_recomandata'] = self::GAZDUIRE_RECOMANDATA;
                // Ecranul locului datelor: doar la varianta B, după verificările trecute, la cerere (?ecran=loc).
                $ecran = $d['ecran'] ?? $c->param('ecran');
                if ($ecran === 'loc' && $this->stare->get('varianta') === 'B' && ($this->stare->get('verificari')['ok'] ?? false) === true) {
                    $d['mutat'] = $this->stare->get('mutat');
                    $d['dosar'] = Loc::afisat($this->public, $this->privat, '', $this->documentRoot);
                    $d['sonda'] ??= null;
                    return $this->randeaza('loc', $d, $status);
                }
                $d['verificari'] ??= $this->verificari();
                $d['verificari']['elemente'] = array_merge($d['verificari']['elemente'], $this->randuriLoc((bool) ($d['cheie_esuata'] ?? false)));
                break;
            case 'baza':
                $d['drivere'] = $this->drivere();
                // SQLite vine aleasă din start (fără nimic de completat); MySQL doar când SQLite lipsește de pe server.
                $d['formular'] ??= ['driver' => $d['drivere']['sqlite'] ? 'sqlite' : 'mysql', 'gazda' => 'localhost', 'port' => 3306, 'nume' => '', 'utilizator' => '', 'prefix' => 'pg_'];
                $d['detaliu'] ??= '';
                break;
            case 'easycheck':
                // întrebarea de la început și, fără cheie, datele completate aici.
                $app = is_file($this->privat . '/' . Loc::CONFIG_LUCRU) ? $this->aplicatie() : null;
                $d['mod_site'] = $this->stare->get('mod_site') ?? ($app?->modLocal() ? Mod::LOCAL : null);
                if ($d['mod_site'] === Mod::LOCAL && $app !== null) {
                    $dep = $app->localDepozit();
                    $prima = $dep->camere()[0] ?? null;
                    $v = \Cazo\Controlere\AdminLocal::valoriProprietate($dep->proprietate(), $dep->reguli());
                    if (!$dep->areReguli()) {
                        // Propunerea de la prima completare: avans 30%, anulare gratuită cu 7 zile înainte, o noapte minimum.
                        $v = array_replace($v, ['avans_procent' => '30', 'anulare_zile' => '7', 'sejur_minim' => '1']);
                    }
                    $v += ['camera_titlu' => (string) ($prima['titlu'] ?? ''), 'camera_unitati' => (string) ($prima['unitati'] ?? 1), 'camera_oaspeti_max' => (string) ($prima['oaspeti_max'] ?? 2),
                        'camera_pret_noapte' => $prima !== null && $prima['pret_noapte'] > 0 ? (string) intdiv($prima['pret_noapte'], 100) : '',
                        'camera_pret_weekend' => $prima !== null && $prima['pret_weekend'] > 0 ? (string) intdiv($prima['pret_weekend'], 100) : ''];
                    $t = $this->traducator();
                    $d['local'] = ['v' => array_replace($v, (array) ($d['local_valori'] ?? [])), 'erori' => array_map(static fn (string $k): string => $t->t($k), (array) ($d['local_erori'] ?? [])),
                        'tipuri' => Depozit::TIPURI, 'reguli_casa' => Depozit::REGULI_CASA];
                    break;
                }
                $ec = (array) $this->stare->get('ec', []);
                if ($c->param('sincronizare') === '1' && ($ec['rezumat']['scop'] ?? null) !== null) {
                    $ec = $this->sincronizeaza();
                }
                $total = count(Oglinda::RESURSE);
                $citite = count((array) ($ec['citite'] ?? []));
                $facute = $citite + count((array) ($ec['erori'] ?? []));
                // Numele proprietății vine din EasyCheck: literele cu sedilă se corectează la afișare.
                if (is_string($ec['rezumat']['nume'] ?? null)) {
                    $ec['rezumat']['nume'] = Format::diacritice($ec['rezumat']['nume']);
                }
                $d['ec'] = $ec;
                // numărătoarea arată doar datele citite („5 din 6”), iar cele rămase apar cu numele pe înțelesul
                // gazdei („prețurile”), din instalare.ec.resursa.<nume>.
                $d['progres'] = ['facute' => min($citite, $total), 'total' => $total];
                $t = $this->traducator();
                $d['necitite'] = array_map(static fn (string $r): string => $t->are('instalare.ec.resursa.' . $r) ? $t->t('instalare.ec.resursa.' . $r) : $r, array_keys((array) ($ec['erori'] ?? [])));
                $d['gata'] = $this->sincronizareGata($ec);
                $d['in_lucru'] = ($ec['rezumat'] ?? null) !== null && $facute < $total;
                $d['refresh'] = $c->param('sincronizare') === '1' && $d['in_lucru'] ? $this->url(['pas' => $pas, 'sincronizare' => 1]) : null;
                $d['adresa_anunt'] = is_file($this->privat . '/' . Loc::CONFIG_LUCRU) ? $this->aplicatie()->url()->absolut('/easycheck-anunt') : '';
                break;
            case 'adresa':
                $d += $this->dateAdresa($this->aplicatie());
                break;
            case 'legal':
                $app = $this->aplicatie();
                $api = (array) ($app->ecOglinda()->corp('proprietate')['operator'] ?? []);
                $local = [];
                foreach ($app->baza()->toate('SELECT camp, valoare FROM {date_legale}') as $r) {
                    $local[$r['camp']] = (string) $r['valoare'];
                }
                // câmpurile cerute urmează forma aleasă pe același pas; la persoana fizică, datele de firmă rămân
                // marcate, dar ascunse, iar foaia de stil le arată când gazda alege o firmă înainte să trimită pasul.
                // forma vine din EasyCheck sau se deduce din denumire și din numărul de la registru; gazda e
                // întrebată doar când nu reiese. O formă deja aleasă pe acest pas (și alta decât cea dedusă) rămâne a gazdei.
                $cunoscuta = BlocLegal::formaCunoscuta($app);
                $salvata = (string) $app->setari()->get('legal.forma', '');
                $forma = (string) ($c->camp('forma') ?? ($salvata !== '' ? $salvata : ($cunoscuta['forma'] ?? '')));
                if (($cunoscuta['sursa'] ?? null) === 'easycheck') {
                    $forma = $cunoscuta['forma'];
                }
                $d['forma_sursa'] = $cunoscuta !== null && $cunoscuta['forma'] === $forma ? $cunoscuta['sursa'] : null;
                $obligatorii = BlocLegal::obligatorii($app, $forma);
                $firmaDinApi = !BlocLegal::persoanaFizica($app, 'persoana_fizica');
                $public = $app->traducator($this->limba);
                $ti = $this->traducator();
                $campuri = [];
                foreach (BlocLegal::CAMPURI as $camp) {
                    $din = is_string($api[$camp] ?? null) && trim($api[$camp]) !== '' ? trim($api[$camp]) : null;
                    $doarFirma = !$firmaDinApi && in_array($camp, BlocLegal::DOAR_FIRMA, true) && in_array($camp, BlocLegal::OBLIGATORII, true);
                    $campuri[] = ['camp' => $camp, 'api' => $din === null ? null : self::dinEasyCheck($camp, $din, $public, $ti), 'valoare' => $c->camp('legal_' . $camp) ?? $local[$camp] ?? '',
                        'obligatoriu' => in_array($camp, $obligatorii, true) || $doarFirma, 'doar_firma' => $doarFirma,
                        'ascuns' => $doarFirma && !in_array($camp, $obligatorii, true)];
                }
                $alese = $app->gdpr()->termene() ?? Gdpr::PROPUSE;
                $d['campuri'] = $campuri;
                $d['forma'] = $forma;
                $d['forme'] = self::FORME;
                // „Da” vine bifat din start (EasyCheck nu știe angajații și cifra de afaceri); gazda schimbă doar dacă e cazul.
                $acc = $app->setari()->get(BlocLegal::ACCESIBILITATE);
                $d['micro'] = $c->camp('micro') ?? (is_bool($acc) && $acc ? 'nu' : 'da');
                // câmpurile numerice, cu pragurile și cu valoarea recomandată completată din start (sau cea scrisă,
                // după o greșeală); valoarea rămâne în unitatea termenului (zile, ore, luni).
                $d['pastrare'] = [];
                foreach (self::PASTRARE_GDPR as $k => $cheie) {
                    $scris = $c->camp($k);
                    $d['pastrare'][] = ['camp' => $k, 'cheie' => $cheie, 'min' => Gdpr::LIMITE[$cheie][0], 'max' => Gdpr::LIMITE[$cheie][1],
                        'propus' => Gdpr::PROPUSE[$cheie], 'unitate' => Gdpr::UNITATI[$cheie], 'durata_propus' => Gdpr::durata($public, $cheie, Gdpr::PROPUSE[$cheie]),
                        'valoare' => is_string($scris) ? mb_substr(trim($scris), 0, 10) : (string) $alese[$cheie]];
                }
                $d['erori'] ??= [];
                break;
            case 'plati':
                $app = $this->aplicatie();
                $email = (string) ($app->ecOglinda()->corp('proprietate')['contact']['email'] ?? '');
                // Formularul retrimis după o eroare păstrează bifele gazdei; altfel, alegerea salvată.
                $trimis = $c->metoda === 'POST' && $c->camp('pas') === (string) $pas;
                // Punctul 14, fără o alegere salvată, e bifat doar ce se poate salva: plata la sosire mereu, cardul doar cu
                // cheia Stripe deja pusă.
                $d['bife'] = $trimis ? ['card' => $c->camp('metoda_card') === '1', 'cash' => $c->camp('metoda_cash') === '1', 'transfer' => $c->camp('metoda_transfer') === '1']
                    : ($app->modPlata()->metode() !== null ? ModPlata::bife($app->modPlata()->metode()) : ['card' => $app->stripe()->configurat(), 'cash' => true, 'transfer' => false]);
                // contul transferului bancar, cu aceleași câmpuri ca în panou și cu erorile lângă ele.
                // alegerea pentru anularea făcută de gazdă: cea trimisă, altfel cea salvată; niciuna bifată la început.
                $d['anulare_gazda'] = $trimis ? (string) ($c->camp('anulare_gazda') ?? '') : (\Cazo\Rezervare\Anulare::gazdaSite($app) ?? '');
                $d['transfer_setari'] = \Cazo\Controlere\AdminTransfer::date($app, $c, $app->traducator($this->limba, 'admin'), 'pi-tr', $d['bife']['transfer']);
                // tarifele cu avans 0% din oglindă, numite încă de aici, cu ce are gazda de schimbat în EasyCheck.
                $d['nota_avans_zero'] = $app->modPlata()->notaFaraAvans($app->traducator($this->limba, 'admin'), true);
                // tariful nerambursabil din oglindă apare pe site doar cu o metodă plătită la rezervare.
                $d['nota_nerambursabil'] = $app->modPlata()->notaNerambursabil($app->traducator($this->limba, 'admin'), true);
                $d['sursa'] = $app->ecLicenta()->cheie()['sursa'] ?? null;
                $d['inclus'] = $trimis && $c->camp('include_trimis') === '1'
                    ? ['taxe' => $c->camp('include_taxe') === '1', 'taxa' => (string) ($c->camp('taxa_locala') ?? '')]
                    // La o instalare nouă, fără metode alese încă: încasată online când bifa cardului vine pusă, altfel la sosire
                    // (varianta online apare doar cu bifa cardului).
                    : ['taxe' => $app->includeTotal()['taxe'], 'taxa' => !$app->includeTotal()['taxa_locala'] ? 'afara'
                        : ($app->modPlata()->metode() === null && $app->setari()->get('pret.taxa_incasare') === null ? ($d['bife']['card'] || $d['bife']['transfer'] ? 'online' : 'la_sosire') : $app->taxaIncasare())];
                $d['stripe'] = $app->stripe()->stare() + ['ultimele4' => $app->secrete()->ultimele4Din('stripe_cheie')];
                $d['mod_ec'] = $app->ecLicenta()->stare()['mod'];
                // permisiunile cheii restricționate, cu numele lor din Stripe, ca în panou.
                $ta = $app->traducator($this->limba, 'admin');
                $d['permisiuni'] = array_map(static fn (string $p): string => $ta->t('admin.plati.perm.' . $p), array_keys(VerificareCheie::SETARI));
                $d['persoana_fizica'] = BlocLegal::persoanaFizica($app);
                // expeditorul e adresa site-ului, fără alegere; asistentul verifică singur SPF și DKIM pe domeniu.
                $domeniu = self::domeniu($app->config()->origine());
                $d['domeniu'] = $domeniu;
                $d['adresa_site'] = self::expeditorSite($app)['adresa'] ?? null;
                $d['adresa_gazda'] = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
                $d['livrare'] = $d['adresa_site'] !== null ? self::livrare($domeniu, $this->mediu['dns'] ?? null) : null;
                $d['https'] = $app->config()->https();
                break;
            case 'tema':
                $app = $this->aplicatie();
                $teme = self::teme(Aplicatie::radacina());
                $tip = (string) ($app->ecOglinda()->corp('proprietate')['tip'] ?? '');
                // Cardurile: captura fiecărei teme (theme.json, cheia `captura`), publicată ca fișier static al temei.
                foreach ($teme as &$tm) {
                    try {
                        $tema = $app->catalog()->tema($tm['slug']);
                        $tm['captura'] = $tema !== null ? $app->active()->captura($tema) : null;
                    } catch (\Throwable) {
                        $tm['captura'] = null;
                    }
                }
                unset($tm);
                $d['teme'] = $teme;
                $d['propusa'] = self::propunere($teme, $tip);
                $d['activa'] = $app->setari()->get('tema.activa', $d['propusa']);
                $d['url_previzualizare'] = $this->url(['tema' => ''], '/instalare/tema');
                break;
            case 'administrator':
                $app = $this->aplicatie();
                $d['formular'] ??= ['email' => (string) ($this->stare->get('administrator')['email'] ?? $app->ecOglinda()->corp('proprietate')['contact']['email'] ?? ''), 'nume' => '', 'limba_panou' => $this->limba];
                $d['live'] = (bool) $this->stare->get('stripe_live', false);
                $d['minim'] = Parole::MINIM;
                break;
            case 'cron':
                $app = $this->aplicatie();
                $d['comanda'] = self::comandaCron($this->privat);
                $d['linie'] = self::linieCron($this->privat);
                $d['extern'] = $app->url()->absolut('/_cron/' . (string) $app->secrete()->citeste('cron_token'));
                $d['cron'] = $this->stareCron();
                $d['verificat'] = $c->param('verificat') === '1';
                break;
            case 'gata':
                $d['lipsa'] ??= $this->pasiLipsa();
                $d['lipsa'] = array_map(fn (int $n): array => ['titlu' => 'instalare.pas.' . self::PASI[$n], 'url' => $this->url(['pas' => $n])], $d['lipsa']);
                $d['sonda'] ??= null;
                $d['rezumat'] = $d['lipsa'] === [] ? $this->rezumat() : [];
                break;
        }
        return $this->randeaza($nume, $d, $status);
    }

    private function traducator(): Traducator
    {
        $dir = Aplicatie::radacina() . '/limbi';
        $catalog = Traducator::incarca($dir, $this->limba, 'instalare') + Traducator::incarca($dir, $this->limba, 'admin');
        $rezerva = $this->limba === 'ro' ? [] : Traducator::incarca($dir, 'ro', 'instalare') + Traducator::incarca($dir, 'ro', 'admin');
        $t = new Traducator($this->limba, $catalog, $rezerva);
        // fără cheie EasyCheck, textele cu pași în EasyCheck își iau varianta `_local`.
        if ($this->stare?->get('mod_site') === Mod::LOCAL) {
            return $t->cuVariante(['_local']);
        }
        return $t;
    }

    private function randeaza(string $vedere, array $d, int $status = 200): Raspuns
    {
        $t = $this->traducator();
        $env = MediuNucleu::creeaza(Aplicatie::radacina() . '/vederi', null, $t);
        $css = '';
        try {
            $css = (new Active(Aplicatie::radacina(), $this->public, $this->privat, Aplicatie::versiune()))->nucleu()['admin.css'] ?? '';
        } catch (\Throwable) {
            // Fără foaia de stil publicată, pagina rămâne lizibilă: lista pașilor e o listă numerotată obișnuită.
        }
        $pasi = [];
        $curent = (int) ($this->stare?->get('pas', 1) ?? 1);
        foreach (self::PASI as $n => $p) {
            $pasi[] = ['nr' => $n, 'nume' => $p, 'titlu' => 'instalare.pas.' . $p, 'facut' => $n < $curent, 'curent' => $n === ($d['pas'] ?? 0),
                'url' => $n >= self::nr(self::PRIMUL_REVAZUT) && $n <= $curent ? $this->url(['pas' => $n]) : null];
        }
        $html = $env->render('instalare/' . $vedere . '.twig', $d + [
            'limba' => $this->limba,
            'css' => $css,
            'pasi' => $pasi,
            'total_pasi' => count(self::PASI),
            'titlu_pas' => isset($d['pas'], self::PASI[$d['pas']]) ? 'instalare.pas.' . self::PASI[$d['pas']] : null,
            'csrf' => $this->csrfPagina,
            'actiune' => $this->url(),
            'url_limba' => ['ro' => $this->url(['limba' => 'ro'] + (isset($d['pas']) && $d['pas'] > 1 ? ['pas' => $d['pas']] : [])), 'en' => $this->url(['limba' => 'en'] + (isset($d['pas']) && $d['pas'] > 1 ? ['pas' => $d['pas']] : []))],
            'eroare' => $d['eroare'] ?? null,
            'eroare_p' => $d['eroare_p'] ?? [],
            'refresh' => $d['refresh'] ?? null,
            // „Înapoi” duce la pasul de dinainte, la pașii care se pot revedea (de la legătura cu EasyCheck încolo;
            // cei de dinainte au lăsat fișiere și se fac o singură dată).
            'url_inapoi' => isset($d['pas'], self::PASI[$d['pas']]) && $d['pas'] > self::nr(self::PRIMUL_REVAZUT) && $d['pas'] - 1 <= $curent
                ? $this->url(['pas' => $d['pas'] - 1]) : null,
        ]);
        return Raspuns::html($html, $status);
    }

    /** Antetele profilului „admin” (no-store, COOP, CSP fără scripturi), noindex, cookie-urile. */
    private function finalizeaza(Raspuns $r): Raspuns
    {
        if ($this->csrfNou !== null) {
            $this->cookie(self::COOKIE_CSRF, $this->csrfNou);
        }
        foreach ($this->cookies as $k) {
            $r->cookie($k);
        }
        return Antete::aplica($r, 'admin', $this->origine, ['noindex' => true, 'metoda' => 'GET']);
    }

    private function tehnic(Raspuns $r): Raspuns
    {
        return Antete::aplica($r, 'tehnic', $this->origine !== '' ? $this->origine : 'http://localhost', ['noindex' => true]);
    }

    /** Aceeași pagină ca încărcătorul când configurarea lipsește (textele din TexteDeAprobat::INCARCATOR). */
    private function indisponibil(): Raspuns
    {
        [$titluRo, $titluEn] = TexteDeAprobat::INCARCATOR['incarcator.titlu'];
        [$ro, $en] = TexteDeAprobat::INCARCATOR['incarcator.text_configurare'];
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $r = Raspuns::html('<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $e($titluRo . ' / ' . $titluEn) . '</title></head><body><main><h1>' . $e($titluRo) . '</h1><p>' . $e($ro) . '</p>'
            . '<h2 lang="en">' . $e($titluEn) . '</h2><p lang="en">' . $e($en) . '</p></main></body></html>', 503);
        return $r->antet('Retry-After', '300');
    }

    private static function scurt(string $m): string
    {
        return mb_substr(preg_replace('~\s+~', ' ', $m), 0, 300);
    }
}
