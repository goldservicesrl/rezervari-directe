<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

use Cazo\Joburi\Coada;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Executorul comenzilor primite de la EasyCheck (etapa 3 din „EasyCheck întâi”).
 * Gazda hotărăște în EasyCheck o rambursare pe cardul oaspetelui; site-ul o citește (`GET /comenzi`), o preia
 * (`POST /comenzi/{id}/preia`), o verifică pe registrul lui (nu pe ce spune EasyCheck), o execută prin
 * `Plati/Rambursari.php` și raportează (`POST /comenzi/{id}/rezultat`). Site-ul rămâne singurul care atinge banii.
 *
 *  - **Când se citesc**: la anunțul `comanda.noua` și la `comenzi_in_asteptare` peste 0 în răspunsul stării (semnul
 *    `de_citit`), la prima rulare și o dată pe oră (`stare=vii`: comenzile proprii rămase preluate sau în procesare).
 *    Citirea și execuția stau în cron (sarcina `ec:comenzi`, la minut), sub zăvorul `ec:comenzi`.
 *  - **O singură execuție**: `INSERT` în `ec_comenzi` cu id-ul comenzii cheie primară, sub zăvorul comenzii site-ului
 *    (`comanda:<id>`); un rând existent = comanda e în lucru aici și continuă de la pasul ei. Cheia de idempotență Stripe e
 *    derivată din comandă (`ref-<ref>-ec-<cmd>`, partea a doua `-p2`, reluările `-r1`..`-r3`), salvată în `operatii_plata`
 *    înaintea apelului, deci o reluare după o cădere nu dublează nimic.
 *  - **Verificările**, în ordinea contractului: comutatorul (`rambursari_oprite`), tipul (`tip_necunoscut`),
 *    rezervarea e a unei comenzi proprii, făcută cu cheia de acum sau cu una legată de ea (`rezervare_necunoscuta`), plata
 *    cu cardul încasată (`fara_plata_card`), suma cel mult netul încasat (`peste_incasat`, `detalii.maxim`), moneda RON,
 *    cheia EasyCheck și cheia Stripe de același fel (`mod_amestecat`), plafonul zilnic (`peste_plafon_zilnic`, ziua în ora
 *    României).
 *  - **Raportul** pleacă din coada `ec_comenzi_rezultate`, cu corpul și `Idempotency-Key` (`rezultat-<cmd>-<stare>`)
 *    fixate la punere; reluări la 1, 5, 30 de minute, 2 ore, apoi la 6 ore, cel mult 7 zile. `preia` și `rezultat` sunt
 *    scrieri critice (banii au plecat sau pleacă): nu trec prin rezerva scrierilor necritice.
 *  - **După reușită**: rambursarea anulării se socotește încheiată (`anulare_rambursata_la`), oaspetele primește mesajul
 *    cerut de comandă (`rambursare_gazda`), iar proprietarii primesc e-mailul de siguranță al site-ului la fiecare
 *    rambursare executată (`gazda_rambursare_ec`). Jurnalul comenzii (`comenzi_jurnal`) și auditul înlănțuit primesc
 *    fiecare pas.
 */
final class Comenzi
{
    public const SETARE = 'ec.comenzi';
    public const ZAVOR = 'ec:comenzi';
    /**
     * Lista tipurilor e închisă în cod: `rambursare` (etapa 3) și `mesaj` (etapa 4, executat de `MesajeEc::comandaMesaj`,
     * fără comutator, fără plafon și fără `in_procesare`).
     */
    public const TIPURI = ['rambursare', 'mesaj'];
    public const SETARE_PERMISE = 'ec.rambursari.permise';
    public const SETARE_PLAFON_NUMAR = 'ec.rambursari.plafon_numar';
    public const SETARE_PLAFON_SUMA = 'ec.rambursari.plafon_suma';
    /** Plafonul zilnic precompletat: 5 rambursări și 5.000 lei pe zi. */
    public const PLAFON_NUMAR = 5;
    public const PLAFON_SUMA = 500000;
    public const PLAFON_NUMAR_MAX = 1000;
    public const PLAFON_SUMA_MIN = 100;
    public const PLAFON_SUMA_MAX = 100000000;
    /**
     * Comutatorul la instalare și la actualizare: pornit, cu plafonul precompletat
     * 5 rambursări / 5.000 lei pe zi și e-mailul de siguranță la fiecare rambursare. Gazda îl oprește din Metodele de
     * plată, cu parola.
     */
    public const PERMISE_IMPLICIT = true;
    /** Comenzile proprii rămase preluate sau în procesare se recitesc o dată pe oră. */
    public const VII = 3600;
    /** Paginile `GET /comenzi` citite la o rulare. */
    public const PAGINI = 10;
    /** Cât se reia un raport fără răspuns. */
    public const VIATA = 7 * 86400;
    public const REIA_RAR = 6 * 3600;
    /** Comanda fără rezultat ajuns după atât intră în alerta `comenzi_blocate`. */
    public const BLOCATA = 3600;
    /** Stările locale în lucru (de continuat după o cădere). */
    public const IN_LUCRU = ['primita', 'preluata', 'executare', 'in_procesare'];
    /** Motivul comenzii => motivul operației de rambursare (vocabularul etapei 2; `restituire_ulterioara` intră ca anularea gazdei). */
    private const MOTIVE_OPERATIE = ['anulare_gazda' => 'anulare_gazda', 'anulare_oaspete' => 'anulare_oaspete', 'gest_gazda' => 'gest_gazda', 'restituire_ulterioara' => 'anulare_gazda'];
    private const ID = '~^cmd_[0-9A-Za-z]{24}$~D';

    public function __construct(private Aplicatie $app)
    {
    }

    // ---- comutatorul și plafonul (Metodele de plată) ----

    public function permise(): bool
    {
        $v = $this->app->setari()->get(self::SETARE_PERMISE);
        return is_bool($v) ? $v : self::PERMISE_IMPLICIT;
    }

    /** Plafonul zilnic ales de gazdă, sau null (fără plafon). @return array{numar:int, suma:int}|null */
    public function plafon(): ?array
    {
        $n = $this->app->setari()->get(self::SETARE_PLAFON_NUMAR);
        $s = $this->app->setari()->get(self::SETARE_PLAFON_SUMA);
        $n = is_int($n) ? $n : self::PLAFON_NUMAR;
        $s = is_int($s) ? $s : self::PLAFON_SUMA;
        // „Fără plafon” (Î3: gazda îl poate opri) se ține ca 0.
        if ($n <= 0 || $s <= 0) {
            return null;
        }
        return ['numar' => min($n, self::PLAFON_NUMAR_MAX), 'suma' => max(self::PLAFON_SUMA_MIN, min($s, self::PLAFON_SUMA_MAX))];
    }

    /**
     * Salvarea din Metodele de plată (proprietarul, cu parola confirmată; controlerul o verifică), apoi `PUT /site` pe loc.
     * $plafon null = fără plafon.
     * @param array{numar:int, suma:int}|null $plafon
     */
    public function salveaza(bool $permise, ?array $plafon): void
    {
        $this->app->setari()->pune(self::SETARE_PERMISE, $permise);
        $this->app->setari()->pune(self::SETARE_PLAFON_NUMAR, $plafon === null ? 0 : $plafon['numar']);
        $this->app->setari()->pune(self::SETARE_PLAFON_SUMA, $plafon === null ? 0 : $plafon['suma']);
        $this->app->stareSite()->declara();
    }

    // ---- citirea ----

    /** Anunțul `comanda.noua` sau `comenzi_in_asteptare` peste 0: cronul citește comenzile la rularea următoare. */
    public function deCitit(): void
    {
        $s = (array) ($this->app->setari()->get(self::SETARE) ?? []);
        if (($s['de_citit'] ?? false) !== true) {
            $this->app->setari()->pune(self::SETARE, ['de_citit' => true] + $s);
        }
    }

    /** Ceva de făcut aici: comenzi locale nelămurite sau rapoarte de trimis (și după stingerea funcției). */
    public function areLucru(): bool
    {
        $b = $this->app->baza();
        return $b->valoare('SELECT 1 FROM {ec_comenzi} WHERE stare IN (' . self::semne(self::IN_LUCRU) . ') LIMIT 1', self::IN_LUCRU) !== null
            || $b->valoare("SELECT 1 FROM {ec_comenzi_rezultate} WHERE livrare = 'de_trimis' LIMIT 1") !== null;
    }

    /** Sarcina cronului `ec:comenzi` (la minut). Întoarce rezumatul pentru panou. */
    public function sarcina(): string
    {
        if ($this->app->modLocal()) {
            return 'fără cheie EasyCheck';
        }
        $aprinse = $this->app->modEc()->comenziAprinse();
        if (!$aprinse && !$this->areLucru()) {
            return 'oprită: EasyCheck fără comenzi';
        }
        $r = null;
        $this->app->zavor()->cuZavor(self::ZAVOR, 300, function () use ($aprinse, &$r): void {
            $r = ['citite' => 0, 'continuate' => 0, 'rapoarte' => 0];
            if ($aprinse) {
                $this->app->hotarariEc()->pornire();
                $r['citite'] = $this->citeste();
            }
            $r['continuate'] = $this->continua();
            $r['rapoarte'] = $this->trimiteRezultate()['trimise'];
        });
        if ($r === null) {
            return 'ocupată';
        }
        return "citite: {$r['citite']}, continuate: {$r['continuate']}, rapoarte trimise: {$r['rapoarte']}";
    }

    /** Citirea `GET /comenzi` (în așteptare, la semn; ale noastre vii, o dată pe oră) și preluarea celor noi. */
    public function citeste(): int
    {
        $s = (array) ($this->app->setari()->get(self::SETARE) ?? []);
        $acum = $this->app->ceas()->acum();
        $n = 0;
        // Prima rulare (repornirea) citește tot: semnul lipsește încă.
        if (($s['de_citit'] ?? true) === true) {
            $lista = $this->lista('in_asteptare');
            if ($lista !== null) {
                $s['de_citit'] = false;
                $this->app->setari()->pune(self::SETARE, $s);
                foreach ($lista as $c) {
                    if ($this->proceseaza($c) === 'amanata') {
                        // O situație trecătoare (zăvorul, o rambursare nelămurită, rețeaua): comanda se recitește la rularea următoare.
                        $this->deCitit();
                    }
                    $n++;
                }
            }
        }
        if (!is_int($s['vii_la'] ?? null) || $acum - $s['vii_la'] >= self::VII) {
            $lista = $this->lista('vii');
            if ($lista !== null) {
                $s = (array) ($this->app->setari()->get(self::SETARE) ?? []);
                $s['vii_la'] = $acum;
                $this->app->setari()->pune(self::SETARE, $s);
                foreach ($lista as $c) {
                    $n++;
                    if (($c['stare'] ?? null) === 'in_asteptare') {
                        $this->proceseaza($c);
                    } elseif (in_array($c['stare'] ?? null, ['preluata', 'in_procesare'], true)) {
                        $this->reluata($c);
                    }
                }
            }
        }
        return $n;
    }

    /** @return list<array>|null comenzile de pe toate paginile (cel mult PAGINI), null la o eroare */
    private function lista(string $stare): ?array
    {
        $q = ['stare' => $stare];
        $toate = [];
        for ($i = 0; $i < self::PAGINI; $i++) {
            try {
                $j = $this->app->ecClient()->citeste('/comenzi', $q)->json() ?? [];
            } catch (EroareApi | EroareTransport | \LengthException $e) {
                $this->app->jurnal()->avertisment('Comenzile EasyCheck nu s-au putut citi', ['eroare' => $e instanceof EroareApi ? $e->raspuns->status . ' ' . ($e->cod ?? '') : get_class($e)]);
                return null;
            }
            foreach ((array) ($j['comenzi'] ?? []) as $c) {
                if (is_array($c) && is_string($c['id'] ?? null) && preg_match(self::ID, $c['id'])) {
                    $toate[] = $c;
                }
            }
            if (!is_string($j['urmatoarea'] ?? null) || $j['urmatoarea'] === '') {
                break;
            }
            $q['dupa'] = $j['urmatoarea'];
        }
        return $toate;
    }

    /**
     * O comandă preluată de noi, fără rând local (de exemplu o bază restaurată dintr-o copie): rândul se refacă din ce
     * spune EasyCheck, iar execuția continuă cu aceeași cheie Stripe (derivată din comandă), deci nimic nu se dublează.
     */
    private function reluata(array $cmd): void
    {
        if ($this->rand((string) $cmd['id']) !== null) {
            return;
        }
        $c = $this->comandaSite($cmd);
        $this->insereaza($cmd, $c, 'preluata');
        $this->pas($cmd['id'], $c, 'reluata', []);
    }

    /**
     * Preluarea unei comenzi `in_asteptare`, sub zăvorul comenzii site-ului: verificările trecătoare (zăvorul liber, nicio
     * rambursare a comenzii nelămurită), rândul local (cheie primară), `preia`, apoi verificările și execuția.
     * @return string preluata, in_lucru, amanata, oprita, refuzata, executata
     */
    public function proceseaza(array $cmd): string
    {
        $id = (string) ($cmd['id'] ?? '');
        if (!preg_match(self::ID, $id)) {
            return 'oprita';
        }
        if ($this->rand($id) !== null) {
            return 'in_lucru';
        }
        $c = $this->comandaSite($cmd);
        if (($cmd['tip'] ?? null) !== 'mesaj' && $c !== null && $this->app->rambursari()->cerutDeschis((int) $c['id'])) {
            // Situație trecătoare: o rambursare a comenzii așteaptă răspunsul Stripe; comanda rămâne în așteptare.
            return 'amanata';
        }
        $zavor = $c !== null ? 'comanda:' . $c['id'] : 'ec-comanda:' . $id;
        $r = $this->app->zavor()->cuZavor($zavor, 120, function () use ($cmd, $c): string {
            if (!$this->insereaza($cmd, $c, 'primita')) {
                return 'in_lucru';
            }
            if (($cmd['tip'] ?? null) === 'mesaj') {
                $this->pas($cmd['id'], $c, 'mesaj_primit', ['mesaj' => is_string($cmd['mesaj'] ?? null) ? mb_substr($cmd['mesaj'], 0, 32) : '']);
            } else {
                $this->pas($cmd['id'], $c, 'primita', ['suma' => (int) ($cmd['suma'] ?? 0)]);
            }
            return $this->preia($this->rand((string) $cmd['id']));
        });
        return $r[0] === true ? (string) $r[1] : 'amanata';
    }

    /** Rândul local, cu id-ul comenzii ca cheie primară. Întoarce false când există deja (altă rulare l-a pus). */
    private function insereaza(array $cmd, ?array $c, string $stare): bool
    {
        $acum = $this->app->ceas()->moment();
        try {
            $this->app->baza()->insereaza('ec_comenzi', ['id' => (string) $cmd['id'], 'tip' => mb_substr((string) ($cmd['tip'] ?? ''), 0, 16),
                'rezervare' => is_string($cmd['rezervare'] ?? null) ? mb_substr($cmd['rezervare'], 0, 64) : null, 'comanda_id' => $c !== null ? (int) $c['id'] : null,
                'suma' => is_int($cmd['suma'] ?? null) ? $cmd['suma'] : 0, 'motiv' => is_string($cmd['motiv'] ?? null) ? mb_substr($cmd['motiv'], 0, 32) : null,
                'mesaj' => is_string($cmd['mesaj'] ?? null) ? mb_substr($cmd['mesaj'], 0, 32) : null, 'proba' => ($cmd['proba'] ?? false) === true ? 1 : 0,
                'stare' => $stare, 'stare_ec' => is_string($cmd['stare'] ?? null) ? mb_substr($cmd['stare'], 0, 16) : null, 'incercari' => 0,
                'primita_la' => $acum, 'actualizat_la' => $acum, 'detalii' => json_encode(['moneda' => is_string($cmd['moneda'] ?? null) ? mb_substr($cmd['moneda'], 0, 8) : null]
                    // Etapa 4: parametrii comenzii `mesaj` (de exemplu suma restituită prin transfer), păstrați pentru execuție.
                    + (is_array($cmd['parametri'] ?? null) ? ['parametri' => array_slice($cmd['parametri'], 0, 8, true)] : [])) ?: null]);
            return true;
        } catch (\PDOException $e) {
            if (!$this->app->baza()->esteDuplicat($e)) {
                throw $e;
            }
            return false;
        }
    }

    /** `POST /comenzi/{id}/preia`; la 200 urmează verificările și execuția. Chemată sub zăvorul comenzii. */
    private function preia(array $x): string
    {
        $id = (string) $x['id'];
        try {
            $r = $this->app->ecClient()->scrie('POST', '/comenzi/' . $id . '/preia', '{}');
        } catch (EroareApi $e) {
            if ($e->raspuns->status === 409 || $e->raspuns->status === 404) {
                // Retrasă, expirată sau încheiată (409), ori necunoscută EasyCheck-ului de acum (404): nimic nu se execută.
                $stare = $e->detalii()['stare'] ?? ($e->raspuns->status === 404 ? 'negasita' : null);
                $this->actualizeaza($id, ['stare' => 'oprita', 'stare_ec' => is_string($stare) ? mb_substr($stare, 0, 16) : null, 'incheiata_la' => $this->app->ceas()->moment()]);
                $this->pas($id, $this->comandaDin($x), 'oprita', ['stare_ec' => is_string($stare) ? $stare : '']);
                return 'oprita';
            }
            $this->actualizeaza($id, ['urmatoarea' => $this->app->ceas()->moment(60), 'eroare' => mb_substr('preia: ' . $e->raspuns->status . ' ' . ($e->cod ?? ''), 0, 200)]);
            return 'amanata';
        } catch (EroareTransport $e) {
            $this->actualizeaza($id, ['urmatoarea' => $this->app->ceas()->moment(60), 'eroare' => 'preia: ' . $e->felul]);
            return 'amanata';
        }
        $j = $r->json() ?? [];
        $this->actualizeaza($id, ['stare' => 'preluata', 'stare_ec' => is_string($j['stare'] ?? null) ? mb_substr($j['stare'], 0, 16) : 'preluata', 'urmatoarea' => null, 'eroare' => null]);
        return $this->dupaPreluare($this->rand($id));
    }

    /**
     * Verificările definitive față de registrul site-ului; ce nu trece primește `refuzata` cu codul. Ce
     * trece intră în execuție, cu ziua plafonului și cheia Stripe fixate. Chemată sub zăvorul comenzii.
     */
    private function dupaPreluare(array $x): string
    {
        $id = (string) $x['id'];
        $c = $this->comandaDin($x);
        if ($x['tip'] === 'mesaj') {
            return $this->mesaj($x, $c);
        }
        $v = $this->verifica($x, $c);
        if ($v !== null) {
            [$cod, $detalii] = $v;
            $this->actualizeaza($id, ['stare' => 'refuzata', 'cod' => $cod, 'detalii' => json_encode($detalii === [] ? null : $detalii), 'incheiata_la' => $this->app->ceas()->moment()]);
            $corp = ['stare' => 'refuzata', 'la' => self::moment($this->app->ceas()->acum()), 'cod' => $cod];
            if ($detalii !== []) {
                $corp['detalii'] = $detalii;
            }
            $this->puneRezultat($id, 'refuzata', $corp);
            $this->pas($id, $c, 'refuzata', ['cod' => $cod]);
            return 'refuzata';
        }
        $this->actualizeaza($id, ['stare' => 'executare', 'zi' => $this->app->azi(), 'idem_stripe' => 'ref-' . $c['ref'] . '-ec-' . $id, 'executata_la' => $this->app->ceas()->moment()]);
        $this->pas($id, $c, 'verificata', ['suma' => (int) $x['suma']]);
        return $this->executa($this->rand($id));
    }

    /**
     * Comanda `mesaj` (etapa 4): fără comutatorul și plafonul rambursărilor, fără
     * `in_procesare`. `reusita` când mesajul a intrat în coada e-mailurilor; `refuzata` cu codul. Chemată sub zăvorul comenzii.
     */
    private function mesaj(array $x, ?array $c): string
    {
        $id = (string) $x['id'];
        $param = (json_decode((string) ($x['detalii'] ?? ''), true) ?: [])['parametri'] ?? null;
        try {
            $r = $this->app->mesajeEc()->comandaMesaj(['id' => $id, 'mesaj' => $x['mesaj'], 'parametri' => is_array($param) ? $param : null], $c);
        } catch (\Throwable $e) {
            $this->app->jurnal()->eroare('Comanda mesaj din EasyCheck nu s-a putut executa', ['comanda' => $id, 'eroare' => $e->getMessage()]);
            $r = ['stare' => 'refuzata', 'cod' => 'tip_necunoscut'];
        }
        $stare = $r['stare'] === 'reusita' ? 'reusita' : 'refuzata';
        $cod = $stare === 'refuzata' ? (string) ($r['cod'] ?? 'tip_necunoscut') : null;
        $acum = $this->app->ceas()->acum();
        $this->actualizeaza($id, ['stare' => $stare, 'cod' => $cod, 'urmatoarea' => null, 'incheiata_la' => Ceas::formateaza($acum)]);
        $corp = ['stare' => $stare, 'la' => self::moment($acum)];
        if ($cod !== null) {
            $corp['cod'] = $cod;
        }
        $this->puneRezultat($id, $stare, $corp);
        $this->pas($id, $c, $stare === 'reusita' ? 'mesaj_trimis' : 'refuzata', array_filter(['cod' => $cod, 'mesaj' => (string) $x['mesaj']], static fn (?string $v): bool => $v !== null));
        return $stare;
    }

    /** @return array{0:string, 1:array}|null codul refuzului și detaliile lui; null = comanda trece */
    private function verifica(array $x, ?array $c): ?array
    {
        if (!$this->permise()) {
            return ['rambursari_oprite', []];
        }
        if (!in_array($x['tip'], self::TIPURI, true)) {
            return ['tip_necunoscut', []];
        }
        if ($c === null || \Cazo\Local\Mod::comandaLocala($c) || $this->app->ecConectare()->comandaStraina($c, $this->app->secrete()->citeste('ec_cheie'))) {
            return ['rezervare_necunoscuta', []];
        }
        $plata = $this->app->anulare()->plata((int) $c['id']);
        if ($c['fel'] !== 'card' || $plata === null || (int) $plata['suma_incasata'] <= 0) {
            return ['fara_plata_card', []];
        }
        $net = $this->app->anulare()->net($c);
        if ((int) $x['suma'] < 1 || (int) $x['suma'] > $net) {
            return ['peste_incasat', ['maxim' => $net]];
        }
        $moneda = (json_decode((string) ($x['detalii'] ?? ''), true) ?: [])['moneda'] ?? 'RON';
        if ($moneda !== 'RON') {
            // Contractul nu are un cod al monedei: programul nu știe să ramburseze altă monedă.
            return ['tip_necunoscut', []];
        }
        $stripe = (string) ($this->app->secrete()->citeste('stripe_cheie') ?? '');
        $modEc = (string) ($this->app->ecLicenta()->stare()['mod'] ?? '');
        if ($stripe !== '' && $modEc !== '' && !Licenta::compatibilCuStripe($modEc, $stripe)) {
            return ['mod_amestecat', []];
        }
        $plafon = $this->plafon();
        if ($plafon !== null) {
            $azi = $this->executateAzi();
            if ($azi['numar'] + 1 > $plafon['numar'] || $azi['suma'] + (int) $x['suma'] > $plafon['suma']) {
                return ['peste_plafon_zilnic', ['plafon' => $plafon, 'ramas' => ['numar' => max(0, $plafon['numar'] - $azi['numar']), 'suma' => max(0, $plafon['suma'] - $azi['suma'])]]];
            }
        }
        return null;
    }

    /** Rambursările executate azi (ora României): în execuție, în procesare și reușite. @return array{numar:int, suma:int} */
    public function executateAzi(): array
    {
        $r = $this->app->baza()->rand("SELECT COUNT(*) AS n, COALESCE(SUM(COALESCE(suma_executata, suma)), 0) AS s FROM {ec_comenzi} WHERE zi = ? AND stare IN ('executare', 'in_procesare', 'reusita')",
            [$this->app->azi()]);
        return ['numar' => (int) ($r['n'] ?? 0), 'suma' => (int) ($r['s'] ?? 0)];
    }

    // ---- execuția ----

    /**
     * Rambursarea pe drumul de azi (`Rambursari::ramburseazaImpartit`), cu cheia derivată din comandă; o reluare după o
     * cădere găsește operațiile salvate (aceeași cheie) și le lămurește, fără o rambursare nouă. Chemată sub zăvorul
     * comenzii. @return string executare, in_procesare, reusita, esuata
     */
    private function executa(array $x): string
    {
        $id = (string) $x['id'];
        $c = $this->comandaDin($x);
        $plata = $c !== null ? $this->app->anulare()->plata((int) $c['id']) : null;
        if ($c === null || $plata === null || !is_string($x['idem_stripe'] ?? null)) {
            return $this->incheie($x, 'esuata', 'stripe_refuz', []);
        }
        $cid = (int) $c['id'];
        $pi = (string) $plata['stripe_pi'];
        $ramb = $this->app->rambursari();
        $baza = (string) $x['idem_stripe'];
        foreach ($this->operatii($cid, $baza) as $op) {
            if (in_array($op['stare'], ['cerut', 'in_procesare'], true)) {
                $ramb->reia($op, $pi);
            }
        }
        $s = $this->sume($cid, $baza);
        if ($s['cerut'] > 0) {
            // Stripe n-a răspuns: aceeași cheie, la rularea următoare (situație trecătoare, nu un refuz).
            $this->actualizeaza($id, ['urmatoarea' => $this->app->ceas()->moment(60)]);
            return 'executare';
        }
        $suma = (int) $x['suma'];
        $rest = $suma - $s['reusit'] - $s['in_procesare'];
        $incercari = (int) $x['incercari'];
        $motiv = self::MOTIVE_OPERATIE[(string) $x['motiv']] ?? 'gest_gazda';
        if ($rest > 0 && $s['toate'] === 0) {
            $this->actualizeaza($id, ['incercari' => 1]);
            $ramb->ramburseazaImpartit($cid, $pi, $rest, $motiv, $baza);
            $s = $this->sume($cid, $baza);
            $rest = $suma - $s['reusit'] - $s['in_procesare'];
            $incercari = 1;
        } elseif ($rest > 0 && $s['cheie_refuzata'] === 0 && $incercari <= count(\Cazo\Plati\Rambursari::REINCERCARI)) {
            // O parte a eșuat (de exemplu soldul contului Stripe): reluările, fiecare cu cheia ei (`-r1`..`-r3`), la pauzele
            // rambursărilor (sub 24 de ore în total); suma e restul de acum, deci nimic nu se dublează.
            $cand = Ceas::dinMoment((string) $s['ultima_la']) + \Cazo\Plati\Rambursari::REINCERCARI[$incercari - 1];
            if ($incercari < count(\Cazo\Plati\Rambursari::REINCERCARI) + 1 && $cand <= $this->app->ceas()->acum()) {
                $ramb->ramburseazaImpartit($cid, $pi, $rest, $motiv, $baza . '-r' . $incercari);
                $incercari++;
                $this->actualizeaza($id, ['incercari' => $incercari]);
                $s = $this->sume($cid, $baza);
                $rest = $suma - $s['reusit'] - $s['in_procesare'];
            } elseif ($incercari < count(\Cazo\Plati\Rambursari::REINCERCARI) + 1) {
                $this->actualizeaza($id, ['urmatoarea' => Ceas::formateaza($cand)]);
                if ($s['reusit'] + $s['in_procesare'] > 0) {
                    $this->inProcesare($x, $c, $s);
                }
                return 'executare';
            }
        }
        if ($s['cerut'] > 0) {
            $this->actualizeaza($id, ['urmatoarea' => $this->app->ceas()->moment(60)]);
            return 'executare';
        }
        if ($s['in_procesare'] > 0) {
            $this->inProcesare($x, $c, $s);
            $this->actualizeaza($id, ['stare' => 'in_procesare', 'suma_executata' => $s['reusit'] + $s['in_procesare'], 'urmatoarea' => $this->app->ceas()->moment(\Cazo\Plati\Rambursari::RECITIRE)]);
            return 'in_procesare';
        }
        if ($rest > 0 && $s['cheie_refuzata'] === 0 && $incercari <= count(\Cazo\Plati\Rambursari::REINCERCARI)) {
            // Așteaptă reluarea următoare.
            $this->actualizeaza($id, ['urmatoarea' => Ceas::formateaza(Ceas::dinMoment((string) $s['ultima_la']) + \Cazo\Plati\Rambursari::REINCERCARI[$incercari - 1])]);
            return 'executare';
        }
        if ($s['reusit'] > 0) {
            return $this->incheie($x, 'reusita', null, [], $s);
        }
        return $this->incheie($x, 'esuata', $s['cod'], $s['motiv_procesator'] !== null ? ['motiv_procesator' => $s['motiv_procesator']] : []);
    }

    /** `in_procesare` (o dată pe comandă) și e-mailul de siguranță: banii au plecat spre Stripe. */
    private function inProcesare(array $x, ?array $c, array $s): void
    {
        $id = (string) $x['id'];
        $corp = ['stare' => 'in_procesare', 'la' => self::moment($this->app->ceas()->acum()), 'suma_executata' => $s['reusit'] + $s['in_procesare'], 'parti' => $s['parti']];
        $this->puneRezultat($id, 'in_procesare', $corp);
        $this->emailGazda($x, $c, $s['reusit'] + $s['in_procesare']);
    }

    /** Starea finală, raportul și urmările ei (anularea încheiată, e-mailurile, jurnalul). */
    private function incheie(array $x, string $stare, ?string $cod, array $detalii, ?array $s = null): string
    {
        $id = (string) $x['id'];
        $c = $this->comandaDin($x);
        $acum = $this->app->ceas()->acum();
        $corp = ['stare' => $stare, 'la' => self::moment($acum)];
        if ($stare === 'reusita' && $s !== null) {
            $corp['suma_executata'] = $s['reusit'];
            $corp['parti'] = $s['parti'];
            $this->actualizeaza($id, ['stare' => 'reusita', 'suma_executata' => $s['reusit'], 'urmatoarea' => null, 'incheiata_la' => Ceas::formateaza($acum)]);
        } else {
            $corp['cod'] = $cod;
            if ($s !== null && $s['parti'] !== []) {
                $corp['parti'] = $s['parti'];
            }
            if ($detalii !== []) {
                $corp['detalii'] = $detalii;
            }
            $this->actualizeaza($id, ['stare' => $stare, 'cod' => $cod, 'urmatoarea' => null, 'incheiata_la' => Ceas::formateaza($acum)]);
        }
        $this->puneRezultat($id, $stare, $corp);
        $this->pas($id, $c, $stare === 'reusita' ? 'executata' : 'esuata', array_filter(['suma' => $s['reusit'] ?? null, 'cod' => $cod], static fn (mixed $v): bool => $v !== null));
        if ($stare === 'reusita' && $c !== null) {
            $this->dupaReusita($x, $c, (int) $s['reusit']);
        }
        return $stare;
    }

    /** Ce face site-ul cu comanda lui după reușită. */
    private function dupaReusita(array $x, array $c, int $executat): void
    {
        $id = (string) $x['id'];
        $cid = (int) $c['id'];
        if (($c['ec_restituire_comanda'] ?? null) === $id) {
            $this->app->baza()->executa('UPDATE {comenzi} SET anulare_rambursata_la = ? WHERE id = ? AND anulare_rambursata_la IS NULL', [$this->app->ceas()->moment(), $cid]);
        }
        $this->emailGazda($x, $c, $executat);
        if ($x['mesaj'] === 'rambursare_gazda' && $executat > 0
            && $this->app->baza()->executa('UPDATE {ec_comenzi} SET email_oaspete_la = ? WHERE id = ? AND email_oaspete_la IS NULL', [$this->app->ceas()->moment(), $id])->rowCount() === 1) {
            $o = $this->app->comenzi()->oaspete($c);
            if (isset($o['email']) && $c['anonimizat_la'] === null) {
                $this->app->emailuri()->pune($cid, 'rambursare_gazda', (string) $c['limba'], (string) $o['email'], ['comanda_id' => $cid, 'suma_bani' => $executat]);
                $this->app->masinaStari()->jurnal($cid, (string) $c['stare'], (string) $c['stare'], 'webhook_ec', ['email' => 'rambursare_gazda', 'suma' => $executat, 'comanda_ec' => $id]);
            }
        }
    }

    /**
     * E-mailul de siguranță către proprietari, prin e-mailul site-ului (nu depinde de EasyCheck), o dată pe comandă, la
     * prima execuție (`in_procesare` sau `reusita`), în limba panoului fiecăruia.
     */
    private function emailGazda(array $x, ?array $c, int $suma): void
    {
        if ($c === null || $suma <= 0 || $this->app->baza()->executa('UPDATE {ec_comenzi} SET email_gazda_la = ? WHERE id = ? AND email_gazda_la IS NULL', [$this->app->ceas()->moment(), (string) $x['id']])->rowCount() !== 1) {
            return;
        }
        foreach ($this->app->baza()->toate("SELECT email, limba_panou FROM {utilizatori} WHERE rol = 'proprietar' AND activ = 1 ORDER BY id") as $u) {
            $this->app->emailuri()->pune((int) $c['id'], 'gazda_rambursare_ec', (string) $u['limba_panou'], (string) $u['email'], ['comanda_id' => (int) $c['id'], 'ref' => (string) $c['ref'], 'suma_bani' => $suma]);
        }
    }

    /** @return list<array> operațiile de rambursare ale comenzii (toate încercările ei) */
    private function operatii(int $cid, string $baza): array
    {
        return $this->app->baza()->toate("SELECT * FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND (idem = ? OR idem LIKE ?) ORDER BY id", [$cid, $baza, $baza . '-%']);
    }

    /**
     * Sumele operațiilor comenzii, pe stări, părțile raportului (cu `re_`), codul eșecului și momentul ultimei operații.
     * @return array{reusit:int, in_procesare:int, cerut:int, esuat:int, toate:int, parti:list<array>, cod:string, motiv_procesator:?string, cheie_refuzata:int, ultima_la:string}
     */
    private function sume(int $cid, string $baza): array
    {
        $s = ['reusit' => 0, 'in_procesare' => 0, 'cerut' => 0, 'esuat' => 0, 'toate' => 0, 'parti' => [], 'cod' => 'stripe_refuz', 'motiv_procesator' => null, 'cheie_refuzata' => 0,
            'ultima_la' => $this->app->ceas()->moment()];
        foreach ($this->operatii($cid, $baza) as $op) {
            $s['toate']++;
            $s[$op['stare'] === 'reusit' ? 'reusit' : (string) $op['stare']] = ($s[$op['stare'] === 'reusit' ? 'reusit' : (string) $op['stare']] ?? 0) + (int) $op['suma'];
            $s['ultima_la'] = (string) $op['actualizat_la'];
            if ($op['stare'] === 'esuat') {
                $e = (string) ($op['eroare'] ?? '');
                if (preg_match('~: (401|403) ~', $e)) {
                    $s['cod'] = 'stripe_cheie';
                    $s['cheie_refuzata']++;
                } elseif (str_contains($e, 'insufficient')) {
                    $s['cod'] = 'stripe_sold';
                } elseif (preg_match('~^[a-z0-9_]{1,40}$~D', $e) && $e !== 'nelamurita') {
                    $s['motiv_procesator'] = $e;
                }
            }
            if (is_string($op['stripe_id'] ?? null) && str_starts_with($op['stripe_id'], 're_') && in_array($op['stare'], ['reusit', 'in_procesare', 'esuat'], true)) {
                $p = ['ref' => (string) $op['stripe_id']];
                if (is_string($op['stripe_pi'] ?? null) && str_starts_with($op['stripe_pi'], 'pi_')) {
                    $p['plata_ref'] = $op['stripe_pi'];
                }
                $p['suma'] = (int) $op['suma'];
                $p['stare'] = ['reusit' => 'reusita', 'in_procesare' => 'in_procesare', 'esuat' => 'esuata'][$op['stare']];
                $s['parti'][] = $p;
            }
        }
        // Cel mult 5 părți (contractul): cele mai noi; cele eșuate ies primele.
        if (count($s['parti']) > 5) {
            $s['parti'] = array_slice(array_merge(array_filter($s['parti'], static fn (array $p): bool => $p['stare'] === 'esuata'),
                array_filter($s['parti'], static fn (array $p): bool => $p['stare'] !== 'esuata')), -5);
        }
        $s['parti'] = array_values($s['parti']);
        return $s;
    }

    /**
     * Comenzile locale rămase în lucru (o cădere între pași, Stripe fără răspuns, o rambursare în procesare, preluarea
     * fără răspuns): continuă de la pasul lor, sub zăvorul comenzii site-ului. Întoarce câte s-au atins.
     */
    public function continua(int $cate = 20): int
    {
        $n = 0;
        foreach ($this->app->baza()->toate('SELECT * FROM {ec_comenzi} WHERE stare IN (' . self::semne(self::IN_LUCRU) . ') AND (urmatoarea IS NULL OR urmatoarea <= ?) ORDER BY primita_la LIMIT ' . $cate,
            array_merge(self::IN_LUCRU, [$this->app->ceas()->moment()])) as $x) {
            $zavor = $x['comanda_id'] !== null ? 'comanda:' . $x['comanda_id'] : 'ec-comanda:' . $x['id'];
            $this->app->zavor()->cuZavor($zavor, 120, function () use ($x): void {
                $x = $this->rand((string) $x['id']);
                match ($x['stare']) {
                    'primita' => $this->preia($x),
                    'preluata' => $this->dupaPreluare($x),
                    'executare', 'in_procesare' => $this->executa($x),
                    default => null,
                };
            });
            $n++;
        }
        return $n;
    }

    // ---- rapoartele ----

    /** Raportul în coadă, o dată pe (comandă, stare), cu corpul și cheia de idempotență fixate acum. */
    private function puneRezultat(string $id, string $stare, array $corp): void
    {
        $acum = $this->app->ceas()->moment();
        try {
            $this->app->baza()->insereaza('ec_comenzi_rezultate', ['comanda' => $id, 'stare' => $stare, 'corp' => Client::json($corp), 'idem' => 'rezultat-' . $id . '-' . $stare,
                'livrare' => 'de_trimis', 'incercari' => 0, 'urmatoarea' => $acum, 'creat_la' => $acum, 'actualizat_la' => $acum]);
        } catch (\PDOException $e) {
            if (!$this->app->baza()->esteDuplicat($e)) {
                throw $e;
            }
        }
    }

    /**
     * Rapoartele scadente, în ordine pe comandă (cel mai vechi nelivrat întâi). 200: trimis. 429, 5xx, rețeaua, 409 în
     * lucru, 404 (funcția stinsă, un EasyCheck întors): reluare, cel mult 7 zile; 409 `stare_invalida`, 422: oprit, cu o
     * linie în jurnal. @return array{trimise:int, amanate:int, oprite:int}
     */
    public function trimiteRezultate(int $cate = 20): array
    {
        $rez = ['trimise' => 0, 'amanate' => 0, 'oprite' => 0];
        $b = $this->app->baza();
        $sarite = [];
        for ($i = 0; $i < $cate; $i++) {
            $param = [$this->app->ceas()->moment()];
            $filtru = '';
            if ($sarite !== []) {
                $filtru = ' AND p.comanda NOT IN (' . self::semne(array_keys($sarite)) . ')';
                array_push($param, ...array_keys($sarite));
            }
            $x = $b->rand("SELECT p.* FROM {ec_comenzi_rezultate} p WHERE p.livrare = 'de_trimis' AND p.urmatoarea <= ?" . $filtru . "
                AND NOT EXISTS (SELECT 1 FROM {ec_comenzi_rezultate} q WHERE q.comanda = p.comanda AND q.livrare = 'de_trimis' AND q.id < p.id) ORDER BY p.urmatoarea, p.id LIMIT 1", $param);
            if ($x === null) {
                break;
            }
            $r = $this->trimiteRezultat($x);
            $rez[$r]++;
            if ($r === 'amanate') {
                $sarite[(string) $x['comanda']] = true;
            }
        }
        return $rez;
    }

    private function trimiteRezultat(array $x): string
    {
        $b = $this->app->baza();
        $n = (int) $x['incercari'] + 1;
        $b->executa('UPDATE {ec_comenzi_rezultate} SET incercari = ?, urmatoarea = ?, actualizat_la = ? WHERE id = ?', [$n, $this->app->ceas()->moment(120), $this->app->ceas()->moment(), (int) $x['id']]);
        try {
            $this->app->ecClient()->scrie('POST', '/comenzi/' . $x['comanda'] . '/rezultat', (string) $x['corp'], (string) $x['idem']);
        } catch (EroareApi $e) {
            $cod = $e->cod ?? 'http_' . $e->raspuns->status;
            $inLucru = $cod === 'idempotency_conflict' && (($e->detalii()['in_lucru'] ?? false) === true);
            if ($inLucru || $e->raspuns->status === 404 || $e->raspuns->status >= 500 || $e->raspuns->status === 429 || $e->comportament === 'reincearca') {
                return $this->amanaRezultat($x, $n, $cod, $e->raspuns->retryAfter());
            }
            return $this->opresteRezultat($x, $cod);
        } catch (EroareTransport $e) {
            if ($e->felul === 'pauza') {
                $b->executa('UPDATE {ec_comenzi_rezultate} SET incercari = ?, urmatoarea = ? WHERE id = ?', [$n - 1, $this->app->ceas()->moment(max(60, (int) $e->reiaDupa)), (int) $x['id']]);
                return 'amanate';
            }
            return $this->amanaRezultat($x, $n, $e->felul, null);
        } catch (\LengthException) {
            return $this->opresteRezultat($x, 'corp_prea_mare');
        }
        $b->executa("UPDATE {ec_comenzi_rezultate} SET livrare = 'trimis', cod = NULL, urmatoarea = NULL, actualizat_la = ? WHERE id = ?", [$this->app->ceas()->moment(), (int) $x['id']]);
        return 'trimise';
    }

    private function amanaRezultat(array $x, int $n, string $cod, ?int $retryAfter): string
    {
        if ($this->app->ceas()->acum() - Ceas::dinMoment((string) $x['creat_la']) >= self::VIATA) {
            return $this->opresteRezultat($x, $cod);
        }
        $pauza = max(Coada::REINCERCARI[$n - 1] ?? self::REIA_RAR, (int) $retryAfter);
        $this->app->baza()->executa('UPDATE {ec_comenzi_rezultate} SET urmatoarea = ?, cod = ?, actualizat_la = ? WHERE id = ?',
            [$this->app->ceas()->moment($pauza), mb_substr($cod, 0, 64), $this->app->ceas()->moment(), (int) $x['id']]);
        return 'amanate';
    }

    private function opresteRezultat(array $x, string $cod): string
    {
        $this->app->baza()->executa("UPDATE {ec_comenzi_rezultate} SET livrare = 'oprit', cod = ?, urmatoarea = NULL, actualizat_la = ? WHERE id = ?",
            [mb_substr($cod, 0, 64), $this->app->ceas()->moment(), (int) $x['id']]);
        $this->app->jurnal()->avertisment('Raportul unei comenzi n-a ajuns la EasyCheck', ['comanda' => $x['comanda'], 'stare' => $x['stare'], 'cod' => $cod]);
        return 'oprite';
    }

    /**
     * Comenzile blocate, pentru alerta `comenzi_blocate`: execuția sau raportul așteaptă de peste o oră, ori raportul unei
     * comenzi cu bani mișcați s-a oprit. @return list<string> codurile comenzilor site-ului
     */
    public function blocate(): array
    {
        $b = $this->app->baza();
        $prag = $this->app->ceas()->moment(-self::BLOCATA);
        $refs = [];
        foreach ($b->toate("SELECT DISTINCT c.ref FROM {ec_comenzi} e JOIN {comenzi} c ON c.id = e.comanda_id
            WHERE (e.stare IN ('executare', 'in_procesare') AND e.executata_la < ?)
            OR EXISTS (SELECT 1 FROM {ec_comenzi_rezultate} r WHERE r.comanda = e.id AND ((r.livrare = 'de_trimis' AND r.creat_la < ?) OR (r.livrare = 'oprit' AND r.stare IN ('in_procesare', 'reusita'))))
            ORDER BY c.ref LIMIT 50", [$prag, $prag]) as $r) {
            $refs[] = (string) $r['ref'];
        }
        return $refs;
    }

    // ---- ajutoare ----

    /** Comanda site-ului a rezervării din comandă (`comenzi.ec_rezervare`), sau null. */
    private function comandaSite(array $cmd): ?array
    {
        $rez = $cmd['rezervare'] ?? null;
        if (!is_string($rez) || !preg_match('~^rez_[0-9A-Za-z]{24}$~D', $rez)) {
            return null;
        }
        $id = $this->app->baza()->valoare('SELECT id FROM {comenzi} WHERE ec_rezervare = ? ORDER BY id DESC LIMIT 1', [$rez]);
        return $id !== null ? $this->app->comenzi()->dupaId((int) $id) : null;
    }

    private function comandaDin(array $x): ?array
    {
        return $x['comanda_id'] !== null ? $this->app->comenzi()->dupaId((int) $x['comanda_id']) : null;
    }

    public function rand(string $id): ?array
    {
        return $this->app->baza()->rand('SELECT * FROM {ec_comenzi} WHERE id = ?', [$id]);
    }

    private function actualizeaza(string $id, array $campuri): void
    {
        $campuri['actualizat_la'] = $this->app->ceas()->moment();
        $set = implode(', ', array_map(static fn (string $k): string => $k . ' = ?', array_keys($campuri)));
        $this->app->baza()->executa('UPDATE {ec_comenzi} SET ' . $set . ' WHERE id = ?', array_merge(array_values($campuri), [$id]));
    }

    /** Jurnalul comenzii site-ului și auditul înlănțuit, la fiecare pas (fără date ale oaspetelui). */
    private function pas(string $id, ?array $c, string $pas, array $detalii): void
    {
        if ($c !== null) {
            $this->app->masinaStari()->jurnal((int) $c['id'], (string) $c['stare'], (string) $c['stare'], 'webhook_ec', ['comanda_ec' => $id, 'pas' => $pas] + $detalii);
        }
        $this->app->audit()->scrie('ec-comanda-' . $pas, $id, ['ref' => $c['ref'] ?? null] + $detalii);
    }

    private static function semne(array $v): string
    {
        return implode(', ', array_fill(0, count($v), '?'));
    }

    public static function moment(int $t): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $t);
    }
}
