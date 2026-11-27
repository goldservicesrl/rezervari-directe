<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\EasyCheck\Client;
use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;
use Cazo\Plati\Checkout;
use Cazo\Plati\EroareStripe;
use Cazo\Pret\RegulaPlata;
use Cazo\Pret\Sejur;

defined('CAZO') or exit;

/**
 * Schimbarea datelor de către oaspete, din „Rezervarea mea”.
 *
 *   conditii      butonul „Schimbați datele”: rezervarea confirmată, până la termenul de anulare gratuită inclusiv
 *                 (118a; fără termen, până în ziua sosirii; la plata la sosire, până în ziua sosirii), o singură
 *                 schimbare pe rezervare (118c), fără o anulare sau o altă schimbare în lucru; tariful nerambursabil
 *                 (fără perioadă gratuită) rămâne la gazdă
 *   oferta        noile date, cu aceleași camere, oaspeți și plan: totalul din aceeași sursă a prețului ca la comandă,
 *                 disponibilitatea live (nopțile proprii ale rezervării de acum se socotesc libere), regula din ziua
 *                 rezervării mutată odată cu datele (termenul gratuit din `anulare_zile`, avansul cu același procent,
 *                 nerambursabilul neschimbat, 118c) și diferența sumei de acum (118b)
 *   porneste      POST-ul oaspetelui, cu totalul văzut: mai scump = sesiunea Checkout pentru diferență (schimbarea
 *                 așteaptă plata, `plata_in_curs`); altfel (și la plata la sosire) schimbarea pornește pe loc
 *   confirmaPlata sesiunea diferenței citită din Stripe (întoarcerea, anunțul, cronul): plătită → `de_aplicat`;
 *                 expirată, refuzată sau abandonată → `abandonata`, fără nimic încasat; deschisă după 15 minute
 *                 (PLATA) → închisă de site
 *   pas           pașii EasyCheck (118d): la date care nu se suprapun, întâi rezervarea nouă, apoi anularea celei
 *                 vechi; la date care se suprapun, întâi anularea, imediat rezervarea nouă (în fereastra de 15 minute a
 *                 EasyCheck, FEREASTRA), iar la un refuz rezervarea veche se reface și oaspetele e anunțat; cu cardul,
 *                 rezervarea nouă poartă `inlocuieste` (rezervarea veche), deci EasyCheck primește avansul deja plătit
 *                ; la plata la sosire, cererea către gazdă (POST /cereri, singura cale fără plată),
 *                 apoi anularea celei vechi după acceptare
 *   ruleaza       cronul: reluările, sesiunile diferenței, cererile, rambursările, e-mailurile rămase
 *
 * Comanda rămâne aceeași (același link „Rezervarea mea”); la aplicare primește noile date, totalul, sumele, regula
 * mutată și legătura spre noua rezervare EasyCheck, iar istoria (ambele id-uri, plus o rezervare refăcută) rămâne în
 * `schimbari_date` și în jurnalul comenzii. Marcajul `anulare_pornita_la` se scrie înaintea anulării celei vechi, sub
 * zăvorul `comanda:<id>`, ca sincronizarea anulărilor să o recunoască drept parte a schimbării (anulareProprie), nu
 * ca o anulare a gazdei. Banii: diferența plătită fără schimbare aplicată se returnează integral, cea mai
 * mică se returnează după aplicare, fiecare pe cheia ei (`ref-<ref>-schimbare<id>`), o singură dată.
 */
final class SchimbareDate
{
    public const JOB = 'rezervari:schimbari';
    /** Stările unei schimbări încă în lucru (cel mult una pe comandă). */
    public const IN_LUCRU = ['plata_in_curs', 'de_aplicat', 'cerere'];
    /** Reluările pașilor EasyCheck fără răspuns: 30 s, 2, 10, 30 de minute, apoi la 30 de minute. */
    public const REINCERCARI = [30, 120, 600, 1800];
    /** Cât ține un pas în curs: un proces mort lasă schimbarea cronului după atâtea secunde. */
    public const LACAT = 120;
    /** După atâtea secunde fără răspuns, cu rezervarea veche deja anulată, gazda primește alerta (o dată). */
    public const ALERTA = 2 * 3600;
    /** Cheile de idempotență trăiesc 24 de ore la EasyCheck: după atât, reluarea s-ar transforma într-o cerere nouă. */
    public const RENUNTARE = 23 * 3600;
    /**
     * cât se reia captura diferenței după ce rezervarea nouă există; apoi suma reținută se eliberează, iar diferența
     * trece în restul de la sosire, cu e-mail și alertă.
     */
    public const CAPTURA_LIMITA = 600;
    /** Pauzele eliberării diferenței, după ultima captură nereușită (reluate rar, ca orice eliberare). */
    public const ELIBERARI = [60, 300, 1800, 3600, 6 * 3600];
    /** După atât, o eliberare tot nelămurită aduce alerta gazdei (o dată). */
    public const ELIBERARE_ALERTA = 86400;
    /** Sesiunea diferenței se recitește din Stripe după atâtea secunde fără anunț (ca PlataOnline::RECONCILIERE). */
    public const RECONCILIERE = 120;
    /** Cererea către gazdă (plata la sosire) se recitește la atâtea secunde, pe lângă anunțurile `cerere.*`. */
    public const CERERE = 900;
    /**
     * Cât rămâne deschisă plata diferenței (varianta B): 15 minute. Stripe Checkout cere cel puțin
     * 30 de minute pentru `expires_at`, deci sesiunea se creează ca la comandă, iar site-ul o închide singur
     * (`POST /v1/checkout/sessions/{id}/expire`) din confirmaPlata, la prima citire după termen.
     */
    public const PLATA = 900;
    /**
     * Cât primește EasyCheck `inlocuieste` cu o rezervare veche anulată de aceeași cheie (ordinea `anulare_intai`): 15
     * minute de la anulare. Reluările rezervării noi se strâng în fereastră (amana); după ea, EasyCheck răspunde 422
     * `inlocuieste_invalid` (`anulata`), iar rezervarea veche se reface, ca la orice refuz.
     */
    public const FEREASTRA = 900;
    /** Marja față de fereastră: ultima reluare pleacă cu atâtea secunde înaintea închiderii ei. */
    public const FEREASTRA_MARJA = 60;

    public function __construct(private Aplicatie $app)
    {
    }

    // ---- condițiile și oferta ----

    /**
     * Ce poate face oaspetele acum cu datele rezervării.
     *   ok             butonul „Schimbați datele” (`limita` = termenul, null = până în ziua sosirii)
     *   in_lucru       o schimbare e în lucru (`schimbare`)
     *   facuta         rezervarea și-a folosit schimbarea (118c)
     *   dupa_termen    după termenul gratuit sau după sosire (118a): gazda, direct
     *   nerambursabil  tariful fără perioadă gratuită (și regula necunoscută, 0013): gazda, direct
     *   anulare        o anulare e în lucru
     *   vanzare_oprita vânzarea e oprită (rezervariActive false: datele legale lipsă sau orele
     *                 neconfirmate): gazda, direct
     *   stare          altă stare decât `confirmata`
     * @return array{poate:bool, motiv:string, limita:?string, schimbare:?array}
     */
    public function conditii(array $c): array
    {
        $s = $this->inLucru((int) $c['id']);
        $r = ['poate' => false, 'motiv' => 'stare', 'limita' => null, 'schimbare' => $s];
        if ($s !== null) {
            return ['motiv' => 'in_lucru'] + $r;
        }
        if ($c['anonimizat_la'] !== null || $c['stare'] !== 'confirmata' || !preg_match('~^rez_[0-9A-Za-z]{1,64}$~', (string) ($c['ec_rezervare'] ?? ''))) {
            return $r;
        }
        if ($c['anulare_actor'] !== null) {
            return ['motiv' => 'anulare'] + $r;
        }
        // rezervarea plătită prin transfer bancar se mută direct cu gazda (EasyCheck nu primește `inlocuieste`
        // împreună cu `plata_asteptata`, iar banii sunt în contul gazdei).
        if ($c['fel'] === 'transfer') {
            return ['motiv' => 'transfer'] + $r;
        }
        if ((int) ($c['schimbari'] ?? 0) >= 1) {
            return ['motiv' => 'facuta'] + $r;
        }
        // cât vânzarea e oprită, nici schimbarea datelor nu pornește (o rezervare nouă pe noile date).
        if (!$this->app->rezervariActive()) {
            return ['motiv' => 'vanzare_oprita'] + $r;
        }
        $azi = $this->app->azi();
        if ($azi > (string) $c['sosire']) {
            return ['motiv' => 'dupa_termen'] + $r;
        }
        if ($c['fel'] !== 'card') {
            return ['poate' => true, 'motiv' => 'ok'] + $r;
        }
        if ((int) ($c['nerambursabil'] ?? 0) !== 0) {
            return ['motiv' => 'nerambursabil'] + $r;
        }
        if ($c['anulare_limita'] !== null && $azi > (string) $c['anulare_limita']) {
            return ['motiv' => 'dupa_termen', 'limita' => (string) $c['anulare_limita']] + $r;
        }
        return ['poate' => true, 'motiv' => 'ok', 'limita' => $c['anulare_limita'] !== null ? (string) $c['anulare_limita'] : null] + $r;
    }

    /** Schimbarea în lucru a comenzii, sau null. */
    public function inLucru(int $comandaId): ?array
    {
        return $this->app->baza()->rand("SELECT * FROM {schimbari_date} WHERE comanda_id = ? AND stare IN ('plata_in_curs', 'de_aplicat', 'cerere') ORDER BY id DESC LIMIT 1", [$comandaId]);
    }

    /** @return list<array> schimbările comenzii, în ordine (istoria din panou) */
    public function istoric(int $comandaId): array
    {
        return $this->app->baza()->toate('SELECT * FROM {schimbari_date} WHERE comanda_id = ? ORDER BY id', [$comandaId]);
    }

    public function dupaId(int $id): ?array
    {
        return $this->app->baza()->rand('SELECT * FROM {schimbari_date} WHERE id = ?', [$id]);
    }

    /**
     * Schimbarea unui e-mail: cea din `date` (a comenzii), altfel ultima a comenzii, altfel una neutră din datele
     * comenzii (randarea de probă a șabloanelor).
     */
    public function pentruEmail(array $c, int $sid): array
    {
        $s = $sid > 0 ? $this->dupaId($sid) : null;
        if ($s !== null && (int) $s['comanda_id'] === (int) $c['id']) {
            return $s;
        }
        return $this->app->baza()->rand('SELECT * FROM {schimbari_date} WHERE comanda_id = ? ORDER BY id DESC LIMIT 1', [$c['id']])
            ?? ['id' => 0, 'comanda_id' => (int) $c['id'], 'fel' => (string) $c['fel'], 'stare' => 'esuata', 'sosire_veche' => (string) $c['sosire'], 'plecare_veche' => (string) $c['plecare'],
                'sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare'], 'total' => (int) $c['total'], 'diferenta' => 0, 'rambursare_suma' => 0, 'eroare' => '',
                'suma_acum' => (int) $c['suma_acum'], 'rest_la_sosire' => (int) $c['rest_la_sosire'],
                'ec_veche' => (string) ($c['ec_rezervare'] ?? ''), 'ec_noua' => null, 'ec_refacuta' => null];
    }

    /**
     * Oferta pentru noile date, fără scrieri: stare `ok` (cu sumele), `date` (date greșite sau din trecut), `aceleasi`,
     * `restrictie` (motivul, ca la comandă), `ocupat` (nopțile) sau `indisponibil` (prețul lipsă chiar acum).
     */
    public function oferta(array $c, string $sosire, string $plecare): array
    {
        try {
            $s = Sejur::din($sosire, $plecare, $this->app->comenzi()->camere($c), $c['plan'] !== null ? (string) $c['plan'] : null);
        } catch (\InvalidArgumentException) {
            return ['stare' => 'date'];
        }
        if ($sosire < $this->app->azi()) {
            return ['stare' => 'date'];
        }
        if ($sosire === (string) $c['sosire'] && $plecare === (string) $c['plecare']) {
            return ['stare' => 'aceleasi'];
        }
        $total = $this->app->total()->live($s);
        if ($total['stare'] === 'restrictie') {
            return ['stare' => 'restrictie', 'restrictie' => (array) $total['restrictie']];
        }
        if ($total['stare'] !== 'ok' || !is_int($total['total'] ?? null)) {
            return ['stare' => 'indisponibil'];
        }
        $ocupate = $this->noptiOcupate($c, $sosire, $plecare);
        if ($ocupate === null) {
            return ['stare' => 'indisponibil'];
        }
        if ($ocupate !== []) {
            return ['stare' => 'ocupat', 'zile' => $ocupate];
        }
        return ['stare' => 'ok'] + $this->sume($c, $sosire, $plecare, $total);
    }

    /** Sumele noilor date, după regula din ziua rezervării (118b, 118c). */
    private function sume(array $c, string $sosire, string $plecare, array $total): array
    {
        $t = (int) $total['total'];
        $limita = $c['anulare_zile'] !== null ? gmdate('Y-m-d', (int) strtotime($sosire . ' 00:00:00 UTC') - (int) $c['anulare_zile'] * 86400) : null;
        $r = ['sosire' => $sosire, 'plecare' => $plecare, 'total' => $t, 'componente' => (array) ($total['componente'] ?? []), 'camere_parti' => $total['camere'] ?? null,
            'include' => $total['include'] ?? null, 'taxa_incasare_declarata' => ($total['taxa_incasare_declarata'] ?? false) === true ? RegulaPlata::incasare($total['taxa_incasare'] ?? null) : null,
            'anulare_limita' => $limita, 'suprapuse' => $sosire < (string) $c['plecare'] && (string) $c['sosire'] < $plecare];
        if ($c['fel'] !== 'card') {
            return $r + ['suma_acum' => 0, 'rest' => $t, 'diferenta' => 0, 'avans' => null];
        }
        // Avansul cu procentul din ziua rezervării, pe noul total (regula de anulare mutată, 118c); plata aleasă atunci
        // (avans sau tot) rămâne. La plata întreagă, suma de acum e noul total (diferența se plătește sau se
        // returnează). La avans (varianta B): avansul plătit rămâne, iar toată diferența de preț trece în restul de
        // la sosire; doar un total nou sub avansul plătit aduce înapoi surplusul, cu restul la sosire zero.
        // taxa locală a noilor date urmează alegerea din ziua comenzii: la „la_sosire”, plata întreagă și avansul o
        // lasă afară (diferența ei trece în restul de la sosire); la „online”, plata întreagă o cuprinde.
        // Comenzile de dinaintea alegerii (fără `taxa_incasare`) rămân pe regula de până acum.
        $cuAlegere = is_string($c['taxa_incasare'] ?? null);
        // sumele noilor date sunt rotunde la leu când totalul vine de la un EasyCheck cu sume rotunde sau de la motorul
        // local. Rotunjirea se face aici, cu regula EasyCheck, pe alegerea taxei din ziua comenzii (`platibil_online` din
        // GET /total urmează alegerea declarată acum, care poate fi alta).
        [$platibilEc, $local] = RegulaPlata::rotunjire($total);
        $regula = RegulaPlata::calculeaza(null, $t, ['procent' => (int) ($c['avans_procent'] ?? 100)], $cuAlegere ? RegulaPlata::taxa($r['componente']) : 0,
            RegulaPlata::incasare($c['taxa_incasare'] ?? null), null, $platibilEc !== null || $local);
        $avans = $regula['avans'];
        $platit = (int) $c['suma_acum'];
        $laAvans = self::laAvans($c);
        $acum = $laAvans ? min($platit, $regula['integral']) : $regula['integral'];
        // Cu `inlocuieste`, EasyCheck primește ca plată cel puțin min(avansul noului total, suma plătită pe rezervarea
        // veche): avansul plătit ține și noile date mai scumpe.
        return $r + ['suma_acum' => $acum, 'rest' => $t - $acum, 'diferenta' => $acum - $platit, 'avans' => $avans, 'la_avans' => $laAvans];
    }

    /** Rezervarea e plătită cu avans: plata aleasă la comandă e avansul, iar suma plătită e sub total. */
    public static function laAvans(array $c): bool
    {
        return $c['fel'] === 'card' && ($c['plata_aleasa'] ?? 'integral') === 'avans' && (int) $c['suma_acum'] < (int) $c['total'];
    }

    /**
     * Nopțile fără loc pentru camerele comenzii, din GET /disponibilitate pe noile date; unitățile rezervării de acum
     * se adună înapoi pe nopțile comune (sunt ale oaspetelui). @return list<string>|null null când EasyCheck tace
     */
    private function noptiOcupate(array $c, string $sosire, string $plecare): ?array
    {
        try {
            $r = $this->app->ecClient()->citeste('/disponibilitate', ['de' => $sosire, 'pana' => $plecare]);
        } catch (EroareApi|EroareTransport) {
            return null;
        }
        $cerute = array_count_values(array_column($this->app->comenzi()->camere($c), 'camera'));
        $proprii = array_flip(Sejur::zile((string) $c['sosire'], (string) $c['plecare']));
        $pline = [];
        foreach ((array) ($r->json()['camere'] ?? []) as $cam) {
            $n = $cerute[$cam['id'] ?? ''] ?? 0;
            foreach ($n > 0 ? (array) ($cam['nopti'] ?? []) : [] as $z) {
                if (!is_array($z) || !is_string($z['zi'] ?? null) || $z['zi'] < $sosire || $z['zi'] >= $plecare) {
                    continue;
                }
                $libere = (int) ($z['libere'] ?? 0) + (isset($proprii[$z['zi']]) ? $n : 0);
                if (!empty($z['inchis']) || $libere < $n) {
                    $pline[$z['zi']] = true;
                }
            }
        }
        $z = array_keys($pline);
        sort($z);
        return $z;
    }

    // ---- oaspetele ----

    /**
     * POST /r/{token}/schimba: pornește schimbarea cu totalul văzut de oaspete, sub zăvorul comenzii. O a doua apăsare
     * (sau un al doilea tab) găsește schimbarea în lucru și duce la aceeași plată, fără alta.
     * @return array{rez:string, url?:?string, oferta?:array, schimbare?:int, motiv?:string}
     *   rez: plata (url Checkout), aplicata, cerere, in_lucru, esuata (motiv), schimbat (alt total), refuz (oferta),
     *   stare (condițiile), ocupat (zăvorul), eroare_plata
     */
    public function porneste(int $id, string $sosire, string $plecare, int $vazut): array
    {
        $r = $this->app->zavor()->cuZavor('comanda:' . $id, 60, function () use ($id, $sosire, $plecare, $vazut): array {
            $c = $this->app->comenzi()->dupaId($id);
            if ($c === null) {
                return ['rez' => 'stare'];
            }
            $k = $this->conditii($c);
            if ($k['motiv'] === 'in_lucru') {
                return ['rez' => 'in_lucru', 'url' => $this->urlPlata($k['schimbare']), 'schimbare' => (int) $k['schimbare']['id']];
            }
            if (!$k['poate']) {
                return ['rez' => 'stare'];
            }
            $o = $this->oferta($c, $sosire, $plecare);
            if ($o['stare'] !== 'ok') {
                return ['rez' => 'refuz', 'oferta' => $o];
            }
            if ($o['total'] !== $vazut) {
                return ['rez' => 'schimbat', 'oferta' => $o];
            }
            $sid = $this->creeaza($c, $o);
            if ($c['fel'] === 'card' && $o['diferenta'] > 0) {
                $url = $this->sesiune($c, $sid);
                return $url === null ? ['rez' => 'eroare_plata', 'schimbare' => $sid] : ['rez' => 'plata', 'url' => $url, 'schimbare' => $sid];
            }
            $stare = $this->pas($sid, 'oaspete');
            $s = $this->dupaId($sid);
            return ['rez' => $stare, 'schimbare' => $sid, 'motiv' => (string) ($s['eroare'] ?? '')];
        });
        return $r[0] === true ? $r[1] : ['rez' => 'ocupat'];
    }

    /** Rândul schimbării, cu toate cheile scrise înaintea oricărui apel spre EasyCheck sau Stripe. */
    private function creeaza(array $c, array $o): int
    {
        $acum = $this->app->ceas()->moment();
        $corp = json_decode($this->app->comenzi()->corpEcDin($c), true, 32, JSON_THROW_ON_ERROR);
        $corp['sosire'] = $o['sosire'];
        $corp['plecare'] = $o['plecare'];
        $corp['total'] = $o['total'];
        // noul total s-a calculat cu alegerea de acum a gazdei; corpul o declară pe aceea (sau nimic, la prețul site-ului).
        unset($corp['include']);
        if (is_array($o['include'] ?? null)) {
            $corp['include'] = ['taxe' => (bool) ($o['include']['taxe'] ?? true), 'taxa_locala' => (bool) ($o['include']['taxa_locala'] ?? true)];
        }
        // momentul încasării taxei locale: la plata cu cardul, cel din ziua comenzii (regula sumelor de mai sus);
        // altfel, cel declarat acum la GET /total.
        unset($corp['taxa_locala_incasare']);
        if (is_array($o['include'] ?? null)) {
            $incasare = is_string($c['taxa_incasare'] ?? null) ? RegulaPlata::incasare($c['taxa_incasare']) : ($o['taxa_incasare_declarata'] ?? null);
            if ($incasare !== null) {
                $corp['taxa_locala_incasare'] = $incasare;
            }
        }
        unset($corp['plata']);
        if ($c['fel'] === 'card') {
            // Plata de pe noua rezervare: tot ce s-a plătit pentru ea (suma de acum, după diferență; la avans, avansul deja
            // plătit, cel mult noul total), cu plata inițială ca referință. `inlocuieste` leagă rezervarea nouă de cea veche:
            // EasyCheck primește atunci o plată de cel puțin min(avansul noului total, suma plătită pe cea veche).
            $p = $this->app->anulare()->plata((int) $c['id']);
            $corp['plata'] = ['suma' => max(1, $o['suma_acum']), 'ref' => mb_substr((string) ($p['stripe_pi'] ?? $c['ref']), 0, 100), 'metoda' => 'card'];
            // partea de taxă locală din plata noii rezervări, după alegerea din ziua comenzii.
            $taxa = RegulaPlata::taxaOnline($c['taxa_incasare'] ?? null, RegulaPlata::taxa($o['componente']), max(1, $o['suma_acum']), (int) $o['total']);
            if ($taxa !== null) {
                $corp['plata']['taxa_locala'] = $taxa;
            }
            $corp['inlocuieste'] = (string) $c['ec_rezervare'];
        }
        $ordine = $c['fel'] !== 'card' ? 'cerere' : ($o['suprapuse'] ? 'anulare_intai' : 'noua_intai');
        $sid = $this->app->baza()->insereaza('schimbari_date', [
            'comanda_id' => (int) $c['id'], 'fel' => (string) $c['fel'], 'stare' => $c['fel'] === 'card' && $o['diferenta'] > 0 ? 'plata_in_curs' : 'de_aplicat', 'ordine' => $ordine,
            'limba' => (string) $c['limba'], 'sosire_veche' => (string) $c['sosire'], 'plecare_veche' => (string) $c['plecare'], 'sosire' => $o['sosire'], 'plecare' => $o['plecare'],
            'total_vechi' => (int) $c['total'], 'total' => $o['total'], 'componente' => Comenzi::json($o['componente']), 'camere_parti' => $o['camere_parti'] !== null ? Comenzi::json($o['camere_parti']) : null,
            'suma_acum_veche' => (int) $c['suma_acum'], 'suma_acum' => $o['suma_acum'], 'rest_la_sosire' => $o['rest'], 'avans' => $o['avans'], 'anulare_limita' => $o['anulare_limita'],
            'diferenta' => $o['diferenta'], 'corp_ec' => $this->app->comenzi()->cripteaza(Comenzi::json($corp)), 'idem_ec' => Client::uuid(), 'idem_anulare' => Client::uuid(),
            'idem_refacere' => Client::uuid(), 'ec_veche' => (string) $c['ec_rezervare'], 'jeton' => \Cazo\Securitate\Codare::jeton(16), 'incercari' => 0,
            'creat_la' => $acum, 'actualizat_la' => $acum,
        ]);
        $this->app->masinaStari()->jurnal((int) $c['id'], 'confirmata', 'confirmata', 'oaspete', ['schimbare' => 'pornita', 'id' => $sid, 'sosire' => $o['sosire'], 'plecare' => $o['plecare'],
            'total' => $o['total'], 'diferenta' => $o['diferenta'], 'ordine' => $ordine, 'ec_veche' => (string) $c['ec_rezervare']]);
        return $sid;
    }

    /** Sesiunea Checkout pentru diferență (aceeași cale ca plata comenzii, cu metadata `schimbare`). Null la o eroare Stripe. */
    private function sesiune(array $c, int $sid): ?string
    {
        $s = $this->dupaId($sid);
        $limba = (string) $c['limba'];
        $t = $this->app->traducator($limba);
        $o = $this->app->comenzi()->oaspete($c);
        $url = $this->app->url();
        $sejur = \Cazo\I18n\Format::zi((string) $s['sosire'], $limba) . ' - ' . \Cazo\I18n\Format::zi((string) $s['plecare'], $limba);
        try {
            $ses = $this->app->checkout()->creeaza([
                'comanda_id' => (int) $c['id'], 'ref' => (string) $c['ref'], 'suma' => (int) $s['diferenta'], 'produs' => $t->t('schimbare.produs', ['sejur' => $sejur, 'ref' => (string) $c['ref']]),
                'email' => (string) ($o['email'] ?? ''), 'limba' => $limba, 'proba' => (int) $c['proba'] === 1, 'schimbare_id' => $sid,
                'succes' => $url->absolut('/schimbare/intoarcere', $limba, ['c' => (string) $c['ref']]) . '&s={CHECKOUT_SESSION_ID}',
                'renuntare' => $url->absolut('/schimbare/renuntare', $limba, ['c' => (string) $c['ref'], 'k' => (string) $s['jeton']]),
            ]);
        } catch (EroareStripe $e) {
            $this->incheie($sid, 'abandonata', 'stripe_' . ($e->cod ?? $e->felul), 'oaspete', false);
            $this->app->jurnal()->eroare('Sesiunea Checkout a diferenței n-a pornit', ['ref' => $c['ref'], 'eroare' => $e->getMessage()]);
            return null;
        }
        $this->app->baza()->executa('UPDATE {schimbari_date} SET plata_id = ?, actualizat_la = ? WHERE id = ?', [$ses['plata_id'], $this->app->ceas()->moment(), $sid]);
        // Termenul site-ului (PLATA), sub cel din Stripe: „Continuați plata” dispare odată cu el.
        $this->app->baza()->executa('UPDATE {plati} SET expira_la = ? WHERE id = ?', [$this->app->ceas()->moment(self::PLATA), $ses['plata_id']]);
        return $ses['url'];
    }

    /** Adresa sesiunii diferenței încă deschise, pentru „Continuați plata”. */
    public function urlPlata(?array $s): ?string
    {
        if ($s === null || $s['stare'] !== 'plata_in_curs' || $s['plata_id'] === null) {
            return null;
        }
        $p = $this->app->baza()->rand("SELECT url, stare, expira_la FROM {plati} WHERE id = ?", [$s['plata_id']]);
        return $p !== null && $p['stare'] === 'sesiune' && is_string($p['url']) && str_starts_with($p['url'], 'https://')
            && ($p['expira_la'] === null || (string) $p['expira_la'] > $this->app->ceas()->moment()) ? $p['url'] : null;
    }

    /**
     * Întoarcerea din Checkout (`s` = sesiunea adusă de Stripe): sesiunea salvată pe schimbare (hash_equals) se citește
     * de pe server, apoi schimbarea merge mai departe. @return ?array schimbarea găsită
     */
    public function intoarcere(int $comandaId, string $sesiune): ?array
    {
        if (!preg_match('~^cs_[A-Za-z0-9_]{1,250}$~', $sesiune)) {
            return null;
        }
        $s = $this->app->baza()->rand('SELECT s.* FROM {schimbari_date} s JOIN {plati} p ON p.id = s.plata_id WHERE s.comanda_id = ? AND p.stripe_sesiune = ?', [$comandaId, $sesiune]);
        if ($s === null || !hash_equals((string) $this->app->baza()->valoare('SELECT stripe_sesiune FROM {plati} WHERE id = ?', [$s['plata_id']]), $sesiune)) {
            return null;
        }
        $this->app->zavor()->cuZavor('comanda:' . $comandaId, 60, fn (): string => $this->confirmaPlata((int) $s['id'], 'intoarcere'));
        return $this->dupaId((int) $s['id']);
    }

    /** Oaspetele a renunțat la plata diferenței (`cancel_url`, cu jetonul schimbării): sesiunea expiră, schimbarea se oprește. */
    public function renunta(int $comandaId, string $jeton): ?array
    {
        $s = $this->app->baza()->rand("SELECT * FROM {schimbari_date} WHERE comanda_id = ? AND stare = 'plata_in_curs' ORDER BY id DESC LIMIT 1", [$comandaId]);
        if ($s === null || !hash_equals((string) $s['jeton'], $jeton)) {
            return null;
        }
        $this->app->zavor()->cuZavor('comanda:' . $comandaId, 60, function () use ($s): string {
            $p = $this->app->baza()->rand('SELECT * FROM {plati} WHERE id = ?', [$s['plata_id']]);
            if ($p !== null && is_string($p['stripe_sesiune']) && $p['stare'] === 'sesiune') {
                try {
                    $this->app->checkout()->expira($p['stripe_sesiune']);
                } catch (EroareStripe) {
                    // Sesiunea s-a încheiat între timp (plătită sau în procesare): o tratează confirmarea.
                    return $this->confirmaPlata((int) $s['id'], 'intoarcere');
                }
                $this->app->baza()->executa("UPDATE {plati} SET stare = 'expirata', actualizat_la = ? WHERE id = ? AND stare = 'sesiune'", [$this->app->ceas()->moment(), $p['id']]);
            }
            $this->incheie((int) $s['id'], 'abandonata', 'renuntare', 'oaspete', false);
            return 'abandonata';
        });
        return $this->dupaId((int) $s['id']);
    }

    // ---- plata diferenței ----

    /** Anunțul Stripe al unei sesiuni a diferenței (metadata `schimbare`), sub zăvorul comenzii ținut de PlataOnline. */
    public function dinAnunt(int $comandaId, array $obiect): void
    {
        $sid = (int) ($obiect['metadata']['schimbare'] ?? 0);
        $s = $sid > 0 ? $this->dupaId($sid) : null;
        if ($s !== null && (int) $s['comanda_id'] === $comandaId) {
            $this->confirmaPlata($sid, 'webhook_stripe');
        }
    }

    /**
     * Sesiunea diferenței, citită mereu din Stripe (întoarcerea, anunțul și cronul cheamă aceeași funcție). Plătită cu
     * suma diferenței: `de_aplicat`, apoi pașii EasyCheck; plătită cu altă sumă: rambursarea integrală și oprirea;
     * în procesare (o metodă cu confirmare întârziată): așteaptă; refuzată sau expirată: oprită, fără nimic încasat.
     * Chemată sub zăvorul comenzii.
     */
    public function confirmaPlata(int $sid, string $actor): string
    {
        $s = $this->dupaId($sid);
        if ($s === null || $s['stare'] !== 'plata_in_curs' || $s['plata_id'] === null) {
            return (string) ($s['stare'] ?? '');
        }
        $p = $this->app->baza()->rand('SELECT * FROM {plati} WHERE id = ?', [$s['plata_id']]);
        if ($p === null || !is_string($p['stripe_sesiune'])) {
            return 'plata_in_curs';
        }
        try {
            $ses = $this->app->checkout()->citeste($p['stripe_sesiune']);
        } catch (EroareStripe) {
            $this->app->baza()->executa('UPDATE {schimbari_date} SET actualizat_la = ? WHERE id = ?', [$this->app->ceas()->moment(), $sid]);
            return 'plata_in_curs';
        }
        $i = Checkout::interpreteaza($ses, (int) $s['diferenta']);
        if ($i['stare'] === 'deschisa' && Ceas::dinMoment((string) $p['creat_la']) + self::PLATA <= $this->app->ceas()->acum()) {
            // Termenul de 15 minute a trecut, iar oaspetele n-a terminat plata: site-ul închide sesiunea. Dacă Stripe
            // răspunde că sesiunea s-a încheiat între timp (plătită în ultimele secunde), se citește plata, ca până acum.
            try {
                $i = Checkout::interpreteaza($this->app->checkout()->expira($p['stripe_sesiune']), (int) $s['diferenta']);
            } catch (EroareStripe) {
                try {
                    $i = Checkout::interpreteaza($this->app->checkout()->citeste($p['stripe_sesiune']), (int) $s['diferenta']);
                } catch (EroareStripe) {
                    // Stripe tace: încă o încercare la rularea următoare a cronului.
                }
            }
            if ($i['stare'] === 'expirata') {
                $this->app->masinaStari()->jurnal((int) $s['comanda_id'], 'confirmata', 'confirmata', $actor, ['schimbare' => 'plata_inchisa', 'id' => $sid, 'dupa' => self::PLATA]);
            }
        }
        $acum = $this->app->ceas()->moment();
        $cid = (int) $s['comanda_id'];
        switch ($i['stare']) {
            case 'platita':
            case 'autorizata':
            case 'nepotrivita':
                if ($i['autorizata']) {
                    // diferența doar reținută pe card; se încasează după ce rezervarea nouă există (aplica).
                    $this->app->baza()->executa("UPDATE {plati} SET stare = 'autorizata', stripe_pi = ?, suma_autorizata = ?, capturare_inainte = ?, metoda = ?, metoda_scurt = ?, actualizat_la = ?
                        WHERE id = ? AND suma_incasata = 0", [$i['pi'], $i['suma'], $i['capturare_inainte'] !== null ? Ceas::formateaza($i['capturare_inainte']) : null, $i['metoda'], $i['metoda_scurt'], $acum, $p['id']]);
                } else {
                    $this->app->baza()->executa("UPDATE {plati} SET stare = 'incasata', stripe_pi = ?, suma_incasata = ?, metoda = ?, metoda_scurt = ?, incasata_la = COALESCE(incasata_la, ?), capturata_la = ?, actualizat_la = ?
                        WHERE id = ? AND suma_incasata = 0", [$i['pi'], $i['suma'], $i['metoda'], $i['metoda_scurt'], $acum, $acum, $acum, $p['id']]);
                    $this->app->rambursari()->recalculeaza($cid);
                }
                if ($i['stare'] === 'nepotrivita') {
                    $this->app->jurnal()->eroare('Diferența încasată cu altă sumă decât schimbarea', ['schimbare' => $sid, 'suma' => $i['suma'], 'asteptat' => (int) $s['diferenta']]);
                    $this->incheie($sid, 'esuata', 'suma_diferita', $actor);
                    return 'esuata';
                }
                if ($this->app->baza()->executa("UPDATE {schimbari_date} SET stare = 'de_aplicat', urmatoarea = NULL, actualizat_la = ? WHERE id = ? AND stare = 'plata_in_curs'", [$acum, $sid])->rowCount() === 1) {
                    $this->app->masinaStari()->jurnal($cid, 'confirmata', 'confirmata', $actor, ['schimbare' => 'diferenta_platita', 'id' => $sid, 'suma' => $i['suma'], 'pi' => $i['pi']]);
                }
                return $this->pas($sid, $actor);
            case 'in_procesare':
                $this->app->baza()->executa("UPDATE {plati} SET stare = 'in_procesare', stripe_pi = ?, metoda = ?, actualizat_la = ? WHERE id = ? AND stare = 'sesiune'", [$i['pi'], $i['metoda'], $acum, $p['id']]);
                $this->app->baza()->executa('UPDATE {schimbari_date} SET actualizat_la = ? WHERE id = ?', [$acum, $sid]);
                return 'plata_in_curs';
            case 'esuata':
                $this->app->baza()->executa("UPDATE {plati} SET stare = 'esuata', stripe_pi = ?, actualizat_la = ? WHERE id = ?", [$i['pi'], $acum, $p['id']]);
                $this->incheie($sid, 'abandonata', 'plata_esuata', $actor);
                return 'abandonata';
            case 'expirata':
                $this->app->baza()->executa("UPDATE {plati} SET stare = 'expirata', actualizat_la = ? WHERE id = ?", [$acum, $p['id']]);
                $this->incheie($sid, 'abandonata', 'expirata', $actor, false);
                return 'abandonata';
        }
        $this->app->baza()->executa('UPDATE {schimbari_date} SET actualizat_la = ? WHERE id = ?', [$acum, $sid]);
        return 'plata_in_curs';
    }

    // ---- pașii EasyCheck ----

    /**
     * Pasul următor al unei schimbări `de_aplicat` (sau `cerere`), sub zăvorul comenzii.
     * @return string aplicata, in_lucru, cerere, esuata
     */
    private function pas(int $sid, string $actor): string
    {
        $s = $this->dupaId($sid);
        if ($s === null || !in_array($s['stare'], ['de_aplicat', 'cerere'], true)) {
            return (string) ($s['stare'] ?? '');
        }
        $c = $this->app->comenzi()->dupaId((int) $s['comanda_id']);
        if ($s['stare'] === 'cerere') {
            // O anulare pornită (încă neîncheiată sau refuzată de EasyCheck) lasă cererea neatinsă: la încheierea anulării
            // o oprește Anulare::incheie (opresteCererea), iar dacă gazda renunță la anulare, cererea își urmează drumul.
            if ($c !== null && $c['anulare_actor'] !== null && in_array($c['stare'], ['confirmata', 'anulare_ceruta'], true)) {
                $this->app->baza()->executa('UPDATE {schimbari_date} SET urmatoarea = ? WHERE id = ?', [$this->app->ceas()->moment(self::CERERE), $sid]);
                return 'cerere';
            }
            return $this->verificaCererea($s, $c, $actor);
        }
        // Comanda trebuie să fie încă cea de la pornire: confirmată, pe rezervarea veche, fără o anulare. Altfel (de
        // exemplu gazda a anulat în EasyCheck înaintea primului pas), schimbarea se oprește și diferența se returnează.
        if ($c === null || $c['stare'] !== 'confirmata' || $c['anulare_actor'] !== null || (string) $c['ec_rezervare'] !== (string) $s['ec_veche']) {
            if ($s['ec_noua'] === null && $s['anulare_pornita_la'] === null && $s['fel'] === 'card' && $s['ordine'] === 'noua_intai' && (int) $s['incercari'] > 0) {
                // POST /rezervari pentru noile date a putut ajunge la EasyCheck fără răspuns: reluarea cu aceeași cheie
                // aduce id-ul rezervării create, ca să fie anulată (altfel nopțile rămân blocate fără nicio comandă).
                $r = $this->aflaNoua($s, $actor);
                if ($r !== 'ok') {
                    return $r;
                }
                $s = $this->dupaId($sid);
            }
            if ($s['ec_noua'] !== null) {
                $this->anuleazaNoua($s);
            }
            if ($s['anulare_pornita_la'] === null) {
                return $this->incheie($sid, 'esuata', 'comanda', $actor);
            }
        }
        if ($this->nepornita($s) && !$this->app->rezervariActive()) {
            // vânzarea s-a oprit înaintea primului pas, fără nimic trimis spre EasyCheck și fără bani încasați pentru
            // schimbare: schimbarea se oprește. Cele pornite (plata diferenței făcută, pașii în lucru) merg la capăt.
            return $this->incheie($sid, 'esuata', 'vanzare_oprita', $actor);
        }
        $this->app->baza()->executa('UPDATE {schimbari_date} SET incercari = incercari + 1, urmatoarea = ?, pornita_la = COALESCE(pornita_la, ?), actualizat_la = ? WHERE id = ?',
            [$this->app->ceas()->moment(self::LACAT), $this->app->ceas()->moment(), $this->app->ceas()->moment(), $sid]);
        $s = $this->dupaId($sid);
        if ($s['fel'] !== 'card' && $s['ec_noua'] === null) {
            return $this->trimiteCererea($s, $actor);
        }
        // A doua verificare proaspătă, doar înaintea primului apel care schimbă ceva în EasyCheck (ca Finalizare).
        if ((int) $s['incercari'] === 1 && $s['fel'] === 'card') {
            $ocupate = $this->noptiOcupate($c, (string) $s['sosire'], (string) $s['plecare']);
            if ($ocupate !== null && $ocupate !== []) {
                $this->app->baza()->executa('UPDATE {schimbari_date} SET eroare_detalii = ? WHERE id = ?', [json_encode(['zile' => $ocupate]), $sid]);
                return $this->incheie($sid, 'esuata', 'noapte_ocupata', $actor);
            }
        }
        if ($s['ordine'] === 'anulare_intai' && $s['anulare_pornita_la'] === null && $s['ec_noua'] === null && $this->cheieSchimbata($c)) {
            // Cheia EasyCheck s-a schimbat după rezervarea veche: EasyCheck poate refuza `inlocuieste` cu `alta_cheie`
            // (rezervarea veche e a altei chei; site-ul nu vede dacă cheia nouă e o înlocuire legată de cea veche). Ca un
            // asemenea refuz să vină înaintea oricărei anulări, rezervarea nouă pleacă întâi, ca la datele separate:
            // refuzată, rezervarea veche rămâne neatinsă și diferența se returnează; primită (o noapte se poate vedea
            // suprapusă câteva clipe), rezervarea veche se anulează imediat după.
            $this->app->baza()->executa("UPDATE {schimbari_date} SET ordine = 'noua_intai', actualizat_la = ? WHERE id = ? AND ordine = 'anulare_intai' AND anulare_pornita_la IS NULL",
                [$this->app->ceas()->moment(), $sid]);
            $this->app->masinaStari()->jurnal((int) $s['comanda_id'], 'confirmata', 'confirmata', $actor, ['schimbare' => 'ordine_noua_intai', 'id' => $sid, 'motiv' => 'cheie_schimbata']);
            $s = $this->dupaId($sid);
        }
        if ($s['ordine'] === 'anulare_intai') {
            if ($s['anulare_veche_la'] === null) {
                $r = $this->anuleazaVeche($s, $actor);
                if ($r !== 'ok') {
                    return $r;
                }
                $s = $this->dupaId($sid);
            }
            if ($s['ec_noua'] === null) {
                // Noua rezervare refuzată definitiv: se continuă refacerea celei vechi (reluările ei).
                $r = (int) $s['refacere'] === 1 ? $this->reface($s, $actor) : $this->creeazaNoua($s, $actor);
                if ($r !== 'ok') {
                    return $r;
                }
            }
            return $this->aplica($sid, $actor);
        }
        if ($s['ec_noua'] === null) {
            $r = $this->creeazaNoua($s, $actor);
            if ($r !== 'ok') {
                return $r;
            }
            $s = $this->dupaId($sid);
        }
        if ($s['anulare_veche_la'] === null) {
            $r = $this->anuleazaVeche($s, $actor);
            if ($r !== 'ok') {
                return $r;
            }
        }
        return $this->aplica($sid, $actor);
    }

    /**
     * Cheia EasyCheck de acum poate fi refuzată la `inlocuieste` (EasyCheck îl primește doar de la cheia rezervării vechi
     * sau de la o înlocuire legată a ei). comanda ține prefixul cheii ei; cheia de acum, aceeași sau legată de ea
     * (direct sau prin lanțul înlocuirilor notate la salvare, Conectare::legata), dă „nu”, iar ordinea rămâne. La
     * comenzile de dinaintea prefixului, regula de până acum: o cheie pusă după plata comenzii (chiar cu aceeași
     * valoare) dă „da”, iar rezervarea nouă pleacă întâi, fără niciun refuz în plus.
     */
    private function cheieSchimbata(array $c): bool
    {
        return $this->app->ecConectare()->comandaStraina($c, $this->app->cheieEc());
    }

    /**
     * Schimbarea n-a trimis încă nimic spre EasyCheck (niciun pas, nicio cerere, nicio rezervare, nicio anulare) și n-are
     * bani încasați pentru ea (diferența plătită): poarta vânzării o mai poate opri.
     */
    private function nepornita(array $s): bool
    {
        $platit = $s['plata_id'] !== null && (int) $this->app->baza()->valoare('SELECT suma_incasata FROM {plati} WHERE id = ?', [$s['plata_id']]) > 0;
        return (int) $s['incercari'] === 0 && $s['pornita_la'] === null && $s['ec_cerere'] === null && $s['ec_noua'] === null && $s['anulare_pornita_la'] === null && !$platit;
    }

    /** POST /rezervari pentru noile date, cu cheia schimbării. @return string ok sau rezultatul pasului */
    private function creeazaNoua(array $s, string $actor): string
    {
        $sid = (int) $s['id'];
        // rezervarea nouă e a cheii care trimite cererea acum; aplica() o trece pe comandă.
        $prefix = \Cazo\EasyCheck\Conectare::prefix($this->app->cheieEc());
        try {
            $r = $this->app->ecClient()->scrie('POST', '/rezervari', $this->app->comenzi()->decripteaza((string) $s['corp_ec']), (string) $s['idem_ec']);
            $j = $r->json() ?? [];
            if (!is_string($j['id'] ?? null) || !preg_match('~^rez_[0-9A-Za-z]{1,64}$~', $j['id'])) {
                return $this->amana($s, 'forma', $actor);
            }
            $this->app->baza()->executa('UPDATE {schimbari_date} SET ec_noua = ?, suprapunere = ?, cheie_prefix = ?, eroare = NULL, actualizat_la = ? WHERE id = ?',
                [$j['id'], ($j['suprapunere'] ?? false) === true ? 1 : 0, $prefix, $this->app->ceas()->moment(), $sid]);
            $this->app->masinaStari()->jurnal((int) $s['comanda_id'], 'confirmata', 'confirmata', $actor, ['schimbare' => 'rezervare_noua', 'id' => $sid, 'ec_noua' => $j['id'], 'request_id' => $r->requestId()]);
            return 'ok';
        } catch (EroareApi $e) {
            if ($this->trecatoare($e)) {
                return $this->amana($s, $e->cod ?? 'http_' . $e->raspuns->status, $actor);
            }
            $cod = $e->cod ?? 'http_' . $e->raspuns->status;
            $motiv = $cod === 'inlocuieste_invalid' && is_string($e->detalii()['motiv'] ?? null) ? $e->detalii()['motiv'] : null;
            if ($motiv === 'deja_mutata' && empty($s['cautata'])) {
                // Rezervarea veche e deja înlocuită: rezervarea nouă poate fi chiar a acestei schimbări (o cerere anterioară
                // cu aceeași cheie). Se caută o dată cu aceeași cheie și același corp, ca în aflaNoua: găsită, schimbarea merge
                // mai departe; un refuz la fel înseamnă că rezervarea nouă nu e a schimbării, iar refuzul rămâne.
                return $this->creeazaNoua(['cautata' => true] + $s, $actor);
            }
            if ($motiv !== null) {
                $cod = mb_substr('inlocuieste_' . $motiv, 0, 64);
            }
            $this->app->baza()->executa('UPDATE {schimbari_date} SET eroare = ?, eroare_detalii = ?, refacere = ? WHERE id = ?', [mb_substr($cod, 0, 64),
                json_encode(['cod' => $cod, 'detalii' => $e->detalii()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $s['ordine'] === 'anulare_intai' ? 1 : 0, $sid]);
            $this->app->jurnal()->avertisment('Rezervarea noilor date refuzată de EasyCheck', ['schimbare' => $sid, 'cod' => $cod, 'request_id' => $e->requestId]);
            if ($s['ordine'] === 'anulare_intai') {
                // Rezervarea veche e deja anulată (datele se suprapun): se reface, iar oaspetele e anunțat.
                return $this->reface($this->dupaId($sid), $actor);
            }
            return $this->incheie($sid, 'esuata', $cod, $actor);
        } catch (EroareTransport $e) {
            return $this->amana($s, $e->felul, $actor);
        }
    }

    /**
     * Comanda s-a anulat după ce POST /rezervari pentru noile date a putut pleca: aceeași cerere, cu aceeași cheie
     * (`idem_ec`) și același corp, întoarce rezervarea deja creată (sau o creează acum, iar ea se anulează imediat
     * după). Un refuz definitiv înseamnă că EasyCheck n-a creat nimic pe cheie. Trecătoare: reluarea, ca la ceilalți
     * pași. @return string ok sau rezultatul pasului
     */
    private function aflaNoua(array $s, string $actor): string
    {
        $sid = (int) $s['id'];
        $this->app->baza()->executa('UPDATE {schimbari_date} SET incercari = incercari + 1, urmatoarea = ?, actualizat_la = ? WHERE id = ?',
            [$this->app->ceas()->moment(self::LACAT), $this->app->ceas()->moment(), $sid]);
        try {
            $r = $this->app->ecClient()->scrie('POST', '/rezervari', $this->app->comenzi()->decripteaza((string) $s['corp_ec']), (string) $s['idem_ec']);
            $j = $r->json() ?? [];
            if (!is_string($j['id'] ?? null) || !preg_match('~^rez_[0-9A-Za-z]{1,64}$~', $j['id'])) {
                return $this->amana($s, 'forma', $actor);
            }
            $this->app->baza()->executa('UPDATE {schimbari_date} SET ec_noua = ?, actualizat_la = ? WHERE id = ?', [$j['id'], $this->app->ceas()->moment(), $sid]);
            $st = (string) ($this->app->comenzi()->dupaId((int) $s['comanda_id'])['stare'] ?? 'confirmata');
            $this->app->masinaStari()->jurnal((int) $s['comanda_id'], $st, $st, $actor, ['schimbare' => 'rezervare_noua_fara_comanda', 'id' => $sid, 'ec_noua' => $j['id'], 'request_id' => $r->requestId()]);
            return 'ok';
        } catch (EroareApi $e) {
            if ($this->trecatoare($e)) {
                return $this->amana($s, $e->cod ?? 'http_' . $e->raspuns->status, $actor);
            }
            return 'ok';
        } catch (EroareTransport $e) {
            return $this->amana($s, $e->felul, $actor);
        }
    }

    /**
     * POST /rezervari/{veche}/anuleaza, cu marcajul scris înaintea apelului. 200, sau 409 stare_invalida cu rezervarea
     * citită `anulata`: ok. Trecătoare: reluarea. Refuz definitiv: la datele care nu se suprapun, noua rezervare
     * rămâne (oaspetele are ce a cerut) și gazda primește alerta să anuleze rezervarea veche; la cele care se suprapun,
     * nimic nu s-a schimbat încă, deci schimbarea se oprește.
     */
    private function anuleazaVeche(array $s, string $actor): string
    {
        $sid = (int) $s['id'];
        $acum = $this->app->ceas()->moment();
        $this->app->baza()->executa('UPDATE {schimbari_date} SET anulare_pornita_la = COALESCE(anulare_pornita_la, ?) WHERE id = ?', [$acum, $sid]);
        $veche = (string) $s['ec_veche'];
        try {
            $r = $this->app->ecClient()->scrie('POST', '/rezervari/' . $veche . '/anuleaza', null, (string) $s['idem_anulare']);
            return $this->vecheAnulata($s, $r->requestId(), $actor);
        } catch (EroareApi $e) {
            $cod = $e->cod ?? 'http_' . $e->raspuns->status;
            if ($e->raspuns->status === 409 && $cod === 'stare_invalida') {
                return $this->anulataInEc($veche) ? $this->vecheAnulata($s, $e->requestId, $actor) : $this->amana($s, $cod, $actor);
            }
            if ($this->trecatoare($e)) {
                return $this->amana($s, $cod, $actor);
            }
            $this->app->jurnal()->eroare('Anularea rezervării vechi la schimbarea datelor a fost refuzată', ['schimbare' => $sid, 'cod' => $cod, 'request_id' => $e->requestId]);
            if ($s['ordine'] === 'anulare_intai') {
                // Nimic schimbat în EasyCheck: oaspetele rămâne pe datele vechi (marcajul se șterge, ca o anulare a gazdei
                // de mai târziu să fie văzută ca atare).
                $this->app->baza()->executa('UPDATE {schimbari_date} SET anulare_pornita_la = NULL WHERE id = ?', [$sid]);
                return $this->incheie($sid, 'esuata', $cod, $actor);
            }
            $c = $this->app->comenzi()->dupaId((int) $s['comanda_id']);
            $this->app->alertaGazda('alerta_schimbare', ['ref' => (string) $c['ref'], 'cod' => $cod, 'ec' => $veche, 'ec_noua' => (string) $s['ec_noua'], 'fel' => 'veche',
                'sosire' => (string) $s['sosire_veche'], 'plecare' => (string) $s['plecare_veche']], 'schimbare_veche:' . $sid, 30 * 86400);
            $this->app->baza()->executa('UPDATE {schimbari_date} SET eroare = ? WHERE id = ?', [mb_substr('veche_' . $cod, 0, 64), $sid]);
            return 'ok';
        } catch (EroareTransport $e) {
            return $this->amana($s, $e->felul, $actor);
        }
    }

    private function vecheAnulata(array $s, ?string $requestId, string $actor): string
    {
        $this->app->baza()->executa('UPDATE {schimbari_date} SET anulare_veche_la = COALESCE(anulare_veche_la, ?), eroare = NULL, actualizat_la = ? WHERE id = ?',
            [$this->app->ceas()->moment(), $this->app->ceas()->moment(), $s['id']]);
        $this->app->masinaStari()->jurnal((int) $s['comanda_id'], 'confirmata', 'confirmata', $actor, ['schimbare' => 'rezervare_veche_anulata', 'id' => (int) $s['id'],
            'ec_veche' => (string) $s['ec_veche'], 'request_id' => $requestId]);
        return 'ok';
    }

    private function anulataInEc(string $rezEc): bool
    {
        try {
            return ($this->app->ecClient()->citeste('/rezervari/' . $rezEc)->json()['stare'] ?? null) === 'anulata';
        } catch (EroareApi|EroareTransport) {
            return false;
        }
    }

    /**
     * La datele care se suprapun, după un refuz al noii rezervări: rezervarea veche se reface (POST /rezervari cu
     * corpul comenzii, cheia `idem_refacere`), comanda trece pe rezervarea refăcută, iar schimbarea se încheie cu
     * motivul refuzului (oaspetele e anunțat, diferența plătită se returnează). La 422 `pret_diferit`, încă o dată cu
     * totalul din răspuns și o cheie nouă (prețul vechilor date s-a schimbat de la comandă). Un refuz definitiv aduce
     * alerta gazdei, care adaugă rezervarea de mână în EasyCheck.
     */
    private function reface(array $s, string $actor): string
    {
        $sid = (int) $s['id'];
        $c = $this->app->comenzi()->dupaId((int) $s['comanda_id']);
        $det = json_decode((string) ($s['eroare_detalii'] ?? ''), true) ?: [];
        $corp = is_string($s['corp_refacere'] ?? null) && $s['corp_refacere'] !== '' ? $this->app->comenzi()->decripteaza((string) $s['corp_refacere']) : $this->app->comenzi()->corpEcDin($c);
        try {
            $r = $this->app->ecClient()->scrie('POST', '/rezervari', $corp, (string) $s['idem_refacere']);
            $j = $r->json() ?? [];
            if (!is_string($j['id'] ?? null) || !preg_match('~^rez_[0-9A-Za-z]{1,64}$~', $j['id'])) {
                return $this->amana($s, 'forma', $actor);
            }
            $this->app->baza()->tranzactie(function () use ($s, $j, $sid): void {
                $this->app->baza()->executa('UPDATE {schimbari_date} SET ec_refacuta = ?, actualizat_la = ? WHERE id = ?', [$j['id'], $this->app->ceas()->moment(), $sid]);
                $this->app->baza()->executa('UPDATE {comenzi} SET ec_rezervare = ?, cheie_prefix = COALESCE(?, cheie_prefix), actualizat_la = ? WHERE id = ? AND ec_rezervare = ?',
                    [$j['id'], \Cazo\EasyCheck\Conectare::prefix($this->app->cheieEc()), $this->app->ceas()->moment(), $s['comanda_id'], $s['ec_veche']]);
            });
            $this->app->masinaStari()->jurnal((int) $s['comanda_id'], 'confirmata', 'confirmata', $actor, ['schimbare' => 'rezervare_veche_refacuta', 'id' => $sid,
                'ec_veche' => (string) $s['ec_veche'], 'ec_refacuta' => $j['id'], 'request_id' => $r->requestId()]);
            return $this->incheie($sid, 'esuata', (string) ($det['cod'] ?? $s['eroare'] ?? 'refuz'), $actor);
        } catch (EroareApi $e) {
            $cod = $e->cod ?? 'http_' . $e->raspuns->status;
            if ($this->trecatoare($e)) {
                return $this->amana($s, $cod, $actor);
            }
            $total = $e->detalii()['total'] ?? null;
            if ($cod === 'pret_diferit' && is_int($total) && empty($det['refacere_pret'])) {
                $x = json_decode($corp, true);
                $x['total'] = $total;
                $this->app->baza()->executa('UPDATE {schimbari_date} SET corp_refacere = ?, idem_refacere = ?, eroare_detalii = ? WHERE id = ?', [
                    $this->app->comenzi()->cripteaza(Comenzi::json($x)), Client::uuid(), json_encode($det + ['refacere_pret' => $total], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sid]);
                return $this->reface($this->dupaId($sid), $actor);
            }
            $this->app->jurnal()->eroare('Refacerea rezervării vechi a fost refuzată de EasyCheck', ['schimbare' => $sid, 'cod' => $cod, 'request_id' => $e->requestId]);
            $this->app->alertaGazda('alerta_schimbare', ['ref' => (string) $c['ref'], 'cod' => $cod, 'ec' => (string) $s['ec_veche'], 'fel' => 'refacere',
                'sosire' => (string) $s['sosire_veche'], 'plecare' => (string) $s['plecare_veche']], 'schimbare_refacere:' . $sid, 30 * 86400);
            return $this->incheie($sid, 'esuata', 'refacere_' . $cod, $actor);
        } catch (EroareTransport $e) {
            return $this->amana($s, $e->felul, $actor);
        }
    }

    /** O rezervare nouă rămasă fără comandă (comanda s-a anulat între pași): se anulează, cu alerta la un refuz. */
    private function anuleazaNoua(array $s): void
    {
        try {
            $this->app->ecClient()->scrie('POST', '/rezervari/' . $s['ec_noua'] . '/anuleaza', null, (string) $s['idem_refacere']);
        } catch (EroareApi|EroareTransport $e) {
            $c = $this->app->comenzi()->dupaId((int) $s['comanda_id']);
            $this->app->alertaGazda('alerta_schimbare', ['ref' => (string) ($c['ref'] ?? ''), 'cod' => $e instanceof EroareApi ? ($e->cod ?? 'http_' . $e->raspuns->status) : $e->felul,
                'ec' => (string) $s['ec_noua'], 'fel' => 'noua', 'sosire' => (string) $s['sosire'], 'plecare' => (string) $s['plecare']], 'schimbare_noua:' . $s['id'], 30 * 86400);
        }
    }

    /** Plata la sosire: cererea pentru noile date (POST /cereri, fără plată), cu rezervarea veche neatinsă. */
    private function trimiteCererea(array $s, string $actor): string
    {
        $sid = (int) $s['id'];
        if ($s['ec_cerere'] !== null) {
            return $this->verificaCererea($s, $this->app->comenzi()->dupaId((int) $s['comanda_id']), $actor);
        }
        $prefix = \Cazo\EasyCheck\Conectare::prefix($this->app->cheieEc());
        try {
            $r = $this->app->ecClient()->scrie('POST', '/cereri', $this->app->comenzi()->decripteaza((string) $s['corp_ec']), (string) $s['idem_ec']);
            $j = $r->json() ?? [];
            if (!is_string($j['id'] ?? null) || !preg_match('~^cer_[0-9A-Za-z]{1,64}$~', $j['id'])) {
                return $this->amana($s, 'forma', $actor);
            }
            $expira = is_string($j['expira_la'] ?? null) && strtotime($j['expira_la']) !== false ? Ceas::formateaza((int) strtotime($j['expira_la'])) : null;
            // Rezervarea din cererea acceptată e a cheii care a trimis cererea.
            $this->app->baza()->executa("UPDATE {schimbari_date} SET stare = 'cerere', ec_cerere = ?, ec_expira_la = ?, cheie_prefix = ?, eroare = NULL, urmatoarea = ?, actualizat_la = ?
                WHERE id = ? AND stare = 'de_aplicat'", [$j['id'], $expira, $prefix, $this->app->ceas()->moment(self::CERERE), $this->app->ceas()->moment(), $sid]);
            $this->app->masinaStari()->jurnal((int) $s['comanda_id'], 'confirmata', 'confirmata', $actor, ['schimbare' => 'cerere_trimisa', 'id' => $sid, 'ec_cerere' => $j['id']]);
            $this->anuntaGazda($this->dupaId($sid), 'gazda_schimbare_cerere');
            return 'cerere';
        } catch (EroareApi $e) {
            if ($this->trecatoare($e)) {
                return $this->amana($s, $e->cod ?? 'http_' . $e->raspuns->status, $actor);
            }
            $cod = $e->cod ?? 'http_' . $e->raspuns->status;
            if ($cod === 'indisponibil') {
                $this->app->baza()->executa('UPDATE {schimbari_date} SET eroare_detalii = ? WHERE id = ?', [json_encode(['zile' => array_values(array_filter((array) ($e->detalii()['zile'] ?? []), 'is_string'))]), $sid]);
                $cod = 'noapte_ocupata';
            }
            return $this->incheie($sid, 'esuata', $cod, $actor);
        } catch (EroareTransport $e) {
            return $this->amana($s, $e->felul, $actor);
        }
    }

    /** GET /cereri/{id}: acceptată → anularea celei vechi și aplicarea; refuzată sau expirată → oprită, rezervarea rămâne. */
    private function verificaCererea(array $s, ?array $c, string $actor): string
    {
        $sid = (int) $s['id'];
        try {
            $j = $this->app->ecClient()->citeste('/cereri/' . $s['ec_cerere'])->json() ?? [];
        } catch (EroareApi|EroareTransport $e) {
            // Pe o comandă anulată cererea nu se mai încheie singură: la o zi după termenul ei, citirile eșuate se opresc.
            // Un 404 după termen e o cerere expirată (ca în CerereCash); altfel gazda verifică cererea în EasyCheck.
            $termen = $s['ec_expira_la'] !== null ? Ceas::dinMoment((string) $s['ec_expira_la']) + 86400 : null;
            if ($c !== null && !in_array($c['stare'], ['confirmata', 'anulare_ceruta'], true) && $termen !== null && $this->app->ceas()->acum() >= $termen) {
                if ($e instanceof EroareApi && $e->raspuns->status === 404) {
                    return $this->incheie($sid, 'esuata', 'cerere_expirata', $actor, false);
                }
                if ($this->incheie($sid, 'esuata', 'comanda', $actor, false) === 'esuata') {
                    $this->app->alertaGazda('alerta_schimbare', ['ref' => (string) $c['ref'], 'cod' => 'comanda', 'ec' => (string) $s['ec_cerere'], 'fel' => 'cerere_necitita',
                        'sosire' => (string) $s['sosire'], 'plecare' => (string) $s['plecare']], 'schimbare_cerere:' . $sid, 30 * 86400);
                }
                return 'esuata';
            }
            $this->app->baza()->executa('UPDATE {schimbari_date} SET urmatoarea = ? WHERE id = ?', [$this->app->ceas()->moment(self::CERERE), $sid]);
            return 'cerere';
        }
        $stare = $j['stare'] ?? null;
        if ($stare === 'acceptata' && is_string($j['rezervare'] ?? null) && preg_match('~^rez_[0-9A-Za-z]{1,64}$~', $j['rezervare'])) {
            // Pașii de după acceptare au cheile lor noi: termenul reluărilor (RENUNTARE, ALERTA) se numără de acum, nu de la
            // trimiterea cererii (o cerere poate aștepta zile, de exemplu cât o anulare pornită e pe comandă).
            if ($this->app->baza()->executa("UPDATE {schimbari_date} SET stare = 'de_aplicat', ec_noua = ?, urmatoarea = NULL, pornita_la = ?, actualizat_la = ? WHERE id = ? AND stare = 'cerere'",
                [$j['rezervare'], $this->app->ceas()->moment(), $this->app->ceas()->moment(), $sid])->rowCount() === 1) {
                $this->app->masinaStari()->jurnal((int) $s['comanda_id'], 'confirmata', 'confirmata', $actor, ['schimbare' => 'cerere_acceptata', 'id' => $sid, 'ec_noua' => $j['rezervare']]);
            }
            return $this->pas($sid, $actor);
        }
        if ($stare === 'refuzata' || $stare === 'expirata') {
            return $this->incheie($sid, 'esuata', 'cerere_' . $stare, $actor);
        }
        if ($c !== null && !in_array($c['stare'], ['confirmata', 'anulare_ceruta'], true) && $stare !== null) {
            // Comanda s-a anulat, iar cererea, citită, încă așteaptă gazda (varianta A): schimbarea se oprește, iar
            // gazda primește alerta s-o refuze în EasyCheck (API-ul public nu are anularea unei cereri).
            if ($this->incheie($sid, 'esuata', 'comanda', $actor, false) === 'esuata') {
                $this->app->alertaGazda('alerta_schimbare', ['ref' => (string) $c['ref'], 'cod' => 'comanda', 'ec' => (string) $s['ec_cerere'], 'fel' => 'cerere',
                    'sosire' => (string) $s['sosire'], 'plecare' => (string) $s['plecare']], 'schimbare_cerere:' . $sid, 30 * 86400);
            }
            return 'esuata';
        }
        $this->app->baza()->executa('UPDATE {schimbari_date} SET urmatoarea = ? WHERE id = ?', [$this->app->ceas()->moment(self::CERERE), $sid]);
        return 'cerere';
    }

    /**
     * Comanda primește noile date, într-o tranzacție cu încheierea schimbării: datele, totalul cu componentele, suma
     * de acum și restul, regula mutată (avansul, termenul gratuit), legătura spre noua rezervare și corpul ei. Apoi
     * rambursarea diferenței (mai ieftin), e-mailurile, rezervarea nouă citită o dată din EasyCheck (o anulare a gazdei
     * făcută între timp), iar `.ics`-ul urmează singur datele noi (SEQUENCE = numărul schimbărilor).
     */
    private function aplica(int $sid, string $actor): string
    {
        $s = $this->dupaId($sid);
        $cid = (int) $s['comanda_id'];
        // rezervarea nouă există, deci diferența reținută pe card se încasează acum; o captură imposibilă (banca a
        // eliberat suma) lasă mutarea, iar diferența trece în restul de la sosire, cu e-mail și alertă.
        $aut = $s['plata_id'] !== null ? $this->app->baza()->rand("SELECT * FROM {plati} WHERE id = ? AND stare IN ('autorizata', 'de_eliberat') AND stripe_pi IS NOT NULL", [$s['plata_id']]) : null;
        if ($aut !== null) {
            // Mutarea rămâne (fără RENUNTARE). Captura se reia CAPTURA_LIMITA de la prima încercare, cu o ultimă încercare la
            // limită; după ea, plata devine `de_eliberat` și se reia doar eliberarea. Trecerea la sosire vine abia după ce
            // Stripe confirmă eliberarea (`canceled`); un status necunoscut se reverifică.
            $limita = $s['captura_de_la'] !== null && $this->app->ceas()->acum() - Ceas::dinMoment((string) $s['captura_de_la']) >= self::CAPTURA_LIMITA;
            if ($aut['stare'] === 'de_eliberat') {
                $cap = $this->renuntaLaCaptura($s, $aut);
            } else {
                $cap = $this->captureazaDiferenta($s, $aut);
                if ($cap === 'amana' && $limita) {
                    $this->app->baza()->executa("UPDATE {plati} SET stare = 'de_eliberat', actualizat_la = ? WHERE id = ? AND stare = 'autorizata'", [$this->app->ceas()->moment(), $aut['id']]);
                    $aut['stare'] = 'de_eliberat';
                    $cap = $this->renuntaLaCaptura($s, $aut);
                }
            }
            if ($cap === 'amana') {
                return $aut['stare'] === 'de_eliberat' ? $this->amanaEliberare($s, $actor) : $this->amanaCaptura($s, $actor);
            }
            $s = $this->dupaId($sid);
        }
        $acum = $this->app->ceas()->moment();
        $corp = $this->app->comenzi()->decripteaza((string) $s['corp_ec']);
        // Taxa locală plătită online era cea a datelor vechi: cu diferența la sosire, creșterea ei vine tot la sosire. Se scrie
        // odată cu mutarea (în tranzacție), deci o aplicare refuzată lasă comanda neatinsă.
        $taxaSosire = (int) ($s['diferenta_la_sosire'] ?? 0) > 0 ? self::taxaLaSosire($s, $this->app->comenzi()->dupaId($cid) ?? []) : null;
        $ok = $this->app->baza()->tranzactie(function () use ($s, $sid, $cid, $acum, $corp, $taxaSosire): bool {
            // avansul din ziua rezervării se ține la prima mutare (`avans_initial`, înaintea lui `avans` în listă: MySQL
            // atribuie de la stânga la dreapta), pentru alegerea gazdei „avansul plătit la rezervare”.
            $n = $this->app->baza()->executa("UPDATE {comenzi} SET avans_initial = COALESCE(avans_initial, avans), sosire = ?, plecare = ?, total = ?, componente = ?, camere_parti = ?, suma_acum = ?, rest_la_sosire = ?, avans = ?,
                anulare_limita = ?, anulare_limita_initiala = NULL, ec_rezervare = ?, corp_ec = ?, idem_ec = ?, suprapunere = ?, schimbari = schimbari + 1, actualizat_la = ?,
                cheie_prefix = COALESCE(?, cheie_prefix), taxa_la_sosire = ?
                WHERE id = ? AND stare = 'confirmata' AND ec_rezervare = ? AND anulare_actor IS NULL",
                [$s['sosire'], $s['plecare'], (int) $s['total'], $s['componente'], $s['camere_parti'], (int) $s['suma_acum'], (int) $s['rest_la_sosire'],
                $s['fel'] === 'card' ? $s['avans'] : null, $s['anulare_limita'], $s['ec_noua'], $this->app->comenzi()->cripteaza($corp), $s['idem_ec'], (int) $s['suprapunere'], $acum,
                // Rezervarea nouă e a cheii cu care s-a creat (notată la creeazaNoua sau la trimiterea cererii); la
                // schimbările de dinaintea notării, cheia de acum.
                is_string($s['cheie_prefix'] ?? null) && $s['cheie_prefix'] !== '' ? $s['cheie_prefix'] : \Cazo\EasyCheck\Conectare::prefix($this->app->cheieEc()),
                // Taxa trecută la sosire rămâne doar când diferența acestei mutări a trecut la sosire.
                $taxaSosire,
                $cid, $s['ec_veche']])->rowCount();
            if ($n !== 1) {
                return false;
            }
            return $this->app->baza()->executa("UPDATE {schimbari_date} SET stare = 'aplicata', aplicata_la = ?, incheiata_la = ?, urmatoarea = NULL, eroare = NULL, actualizat_la = ? WHERE id = ? AND stare = 'de_aplicat'",
                [$acum, $acum, $acum, $sid])->rowCount() === 1;
        });
        if (!$ok) {
            // Comanda s-a mutat între timp (o stare pe care pașii n-o lasă): gazda primește alerta, cu noua rezervare.
            $this->app->jurnal()->eroare('Schimbarea datelor n-a putut fi aplicată comenzii', ['schimbare' => $sid]);
            $this->app->alertaGazda('alerta_schimbare', ['ref' => (string) ($this->app->comenzi()->dupaId($cid)['ref'] ?? ''), 'cod' => 'comanda', 'ec' => (string) $s['ec_noua'], 'fel' => 'noua',
                'sosire' => (string) $s['sosire'], 'plecare' => (string) $s['plecare']], 'schimbare_noua:' . $sid, 30 * 86400);
            return $this->incheie($sid, 'esuata', 'comanda', $actor);
        }
        $this->app->masinaStari()->jurnal($cid, 'confirmata', 'confirmata', $actor, ['schimbare' => 'aplicata', 'id' => $sid, 'sosire_veche' => (string) $s['sosire_veche'],
            'plecare_veche' => (string) $s['plecare_veche'], 'sosire' => (string) $s['sosire'], 'plecare' => (string) $s['plecare'], 'total_vechi' => (int) $s['total_vechi'],
            'total' => (int) $s['total'], 'diferenta' => (int) $s['diferenta'], 'ec_veche' => (string) $s['ec_veche'], 'ec_noua' => (string) $s['ec_noua']]);
        if ($s['fel'] === 'card' && (int) $s['diferenta'] < 0) {
            $c = $this->app->comenzi()->dupaId($cid);
            $suma = min(-(int) $s['diferenta'], $this->app->anulare()->net($c));
            $this->app->baza()->executa('UPDATE {schimbari_date} SET rambursare_suma = ? WHERE id = ?', [max(0, $suma), $sid]);
            $this->ramburseaza($sid);
        }
        if ((int) ($s['diferenta_la_sosire'] ?? 0) > 0) {
            $c = $this->app->comenzi()->dupaId($cid);
            $this->app->alertaGazda('alerta_diferenta', ['ref' => (string) $c['ref'], 'sosire' => (string) $s['sosire'], 'plecare' => (string) $s['plecare'],
                'suma_bani' => (int) $s['diferenta_la_sosire']], 'diferenta:' . $sid, 30 * 86400);
        }
        $this->anunta($sid);
        $this->app->anulare()->dupaConfirmare($cid);
        return 'aplicata';
    }

    /**
     * Captura diferenței autorizate: 'ok' (încasată), 'la_sosire' (banca a eliberat suma: diferența trece în restul
     * de la sosire) sau 'amana' (eroare trecătoare Stripe; pasul se reia).
     */
    private function captureazaDiferenta(array $s, array $p): string
    {
        $c = $this->app->comenzi()->dupaId((int) $s['comanda_id']);
        $pi = (string) $p['stripe_pi'];
        try {
            // O cheie nouă la fiecare reluare: Stripe păstrează pe cheie și un răspuns 500.
            $o = $this->app->checkout()->captureaza($pi, 'cap-' . $c['ref'] . '-schimbare' . $s['id'] . ((int) $s['capturi'] > 0 ? '-' . (int) $s['capturi'] : ''));
        } catch (EroareStripe) {
            // Orice eroare (și 5xx sau timeout, cu captura poate făcută): starea se citește din Stripe.
            try {
                $o = $this->app->checkout()->citestePi($pi);
            } catch (EroareStripe) {
                return 'amana';
            }
        }
        $acum = $this->app->ceas()->moment();
        if (($o['status'] ?? '') === 'succeeded') {
            $this->app->baza()->executa("UPDATE {plati} SET stare = 'incasata', suma_incasata = ?, incasata_la = COALESCE(incasata_la, ?), capturata_la = ?, actualizat_la = ? WHERE id = ?",
                [(int) ($o['amount_received'] ?? $p['suma_autorizata']), $acum, $acum, $acum, $p['id']]);
            $this->app->rambursari()->recalculeaza((int) $c['id']);
            return 'ok';
        }
        // Doar eliberarea confirmată de Stripe (`canceled`) trece diferența la sosire; orice altă stare se reia.
        return ($o['status'] ?? '') === 'canceled' ? $this->laSosire($s, $p, 'canceled') : 'amana';
    }

    /**
     * Captura diferenței tot nereușită după CAPTURA_LIMITA: suma reținută se eliberează, iar diferența trece în restul de
     * la sosire; o captură găsită făcută între timp rămâne încasată ('ok').
     */
    private function renuntaLaCaptura(array $s, array $p): string
    {
        $c = $this->app->comenzi()->dupaId((int) $s['comanda_id']);
        $pi = (string) $p['stripe_pi'];
        try {
            $o = $this->app->checkout()->anuleaza($pi, 'elib-' . $c['ref'] . '-schimbare' . $s['id']);
        } catch (EroareStripe) {
            try {
                $o = $this->app->checkout()->citestePi($pi);
            } catch (EroareStripe) {
                // Stripe tace: starea rămâne de lămurit, iar cronul o reverifică.
                return 'amana';
            }
        }
        if (($o['status'] ?? '') === 'succeeded') {
            // Captura a reușit totuși (răspunsul ei s-a pierdut): diferența e încasată, restul de la sosire rămâne.
            $acum = $this->app->ceas()->moment();
            $this->app->baza()->executa("UPDATE {plati} SET stare = 'incasata', suma_incasata = ?, incasata_la = COALESCE(incasata_la, ?), capturata_la = ?, actualizat_la = ? WHERE id = ?",
                [(int) ($o['amount_received'] ?? $p['suma_autorizata']), $acum, $acum, $acum, $p['id']]);
            $this->app->rambursari()->recalculeaza((int) $c['id']);
            return 'ok';
        }
        return ($o['status'] ?? '') === 'canceled' ? $this->laSosire($s, $p, 'canceled') : 'amana';
    }

    /**
     * Eliberarea diferenței se reia rar (ELIBERARI), cât Stripe tace sau PaymentIntent-ul e tot `requires_capture`; după
     * ELIBERARE_ALERTA, gazda primește alerta (o dată). Autorizarea expiră oricum la bancă (7 zile), iar atunci Stripe o
     * arată `canceled`.
     */
    private function amanaEliberare(array $s, string $actor): string
    {
        $sid = (int) $s['id'];
        $s = $this->dupaId($sid);
        $acum = $this->app->ceas()->acum();
        $n = (int) $s['eliberari'] + 1;
        $pauza = self::ELIBERARI[min($n - 1, count(self::ELIBERARI) - 1)];
        $this->app->baza()->executa('UPDATE {schimbari_date} SET eliberari = ?, urmatoarea = ?, eroare = ?, actualizat_la = ? WHERE id = ?',
            [$n, Ceas::formateaza($acum + $pauza), 'eliberare', $this->app->ceas()->moment(), $sid]);
        $this->app->masinaStari()->jurnal((int) $s['comanda_id'], 'confirmata', 'confirmata', $actor, ['schimbare' => 'eliberare_amanata', 'id' => $sid, 'reincercare_in' => $pauza]);
        $de = $s['captura_de_la'] !== null ? Ceas::dinMoment((string) $s['captura_de_la']) : $acum;
        if ($acum - $de >= self::ELIBERARE_ALERTA && $this->app->limitator()->incearca('eliberare_dif_alerta:' . $sid, 1, 30 * 86400)) {
            $c = $this->app->comenzi()->dupaId((int) $s['comanda_id']);
            $p = $this->app->baza()->rand('SELECT suma_autorizata FROM {plati} WHERE id = ?', [$s['plata_id']]);
            $this->app->alertaGazda('alerta_eliberare_diferenta', ['ref' => (string) $c['ref'], 'sosire' => (string) $s['sosire'], 'plecare' => (string) $s['plecare'],
                'suma_bani' => (int) ($p['suma_autorizata'] ?? $s['diferenta'])], 'eliberare_diferenta:' . $sid, 30 * 86400);
        }
        return 'in_lucru';
    }

    /** Diferența reținută rămâne neîncasată: plata se închide, iar diferența trece în restul de la sosire. */
    private function laSosire(array $s, array $p, ?string $piStare): string
    {
        $c = $this->app->comenzi()->dupaId((int) $s['comanda_id']);
        $acum = $this->app->ceas()->moment();
        $dif = (int) $s['diferenta'];
        $this->app->baza()->executa("UPDATE {plati} SET stare = 'anulata', actualizat_la = ? WHERE id = ?", [$acum, $p['id']]);
        $this->app->baza()->executa('UPDATE {schimbari_date} SET suma_acum = suma_acum - ?, rest_la_sosire = rest_la_sosire + ?, diferenta_la_sosire = ?, actualizat_la = ? WHERE id = ?',
            [$dif, $dif, $dif, $acum, $s['id']]);
        $this->app->masinaStari()->jurnal((int) $c['id'], 'confirmata', 'confirmata', 'sistem', ['schimbare' => 'diferenta_la_sosire', 'id' => (int) $s['id'], 'suma' => $dif, 'pi_stare' => $piStare]);
        return 'la_sosire';
    }

    /** Partea din taxa locală trecută la sosire odată cu diferența: creșterea taxei față de datele vechi, cel mult diferența. */
    private static function taxaLaSosire(array $s, array $c): ?int
    {
        $noua = (int) ((json_decode((string) $s['componente'], true) ?: [])['taxa_locala'] ?? 0);
        $veche = (int) ((json_decode((string) ($c['componente'] ?? ''), true) ?: [])['taxa_locala'] ?? 0);
        return min((int) $s['diferenta_la_sosire'], max(0, $noua - $veche)) ?: null;
    }

    /** Captura diferenței se reia (cu o cheie nouă), cel mult până la CAPTURA_LIMITA de la prima încercare nereușită. */
    private function amanaCaptura(array $s, string $actor): string
    {
        $sid = (int) $s['id'];
        $s = $this->dupaId($sid);
        $acum = $this->app->ceas()->acum();
        $n = (int) $s['capturi'] + 1;
        $de = $s['captura_de_la'] !== null ? Ceas::dinMoment((string) $s['captura_de_la']) : $acum;
        $pauza = self::REINCERCARI[min($n - 1, count(self::REINCERCARI) - 1)];
        $urm = max($acum + 5, min($acum + $pauza, $de + self::CAPTURA_LIMITA));
        $this->app->baza()->executa('UPDATE {schimbari_date} SET capturi = ?, captura_de_la = ?, urmatoarea = ?, eroare = ?, actualizat_la = ? WHERE id = ?',
            [$n, Ceas::formateaza($de), Ceas::formateaza($urm), 'captura', $this->app->ceas()->moment(), $sid]);
        $this->app->masinaStari()->jurnal((int) $s['comanda_id'], 'confirmata', 'confirmata', $actor, ['schimbare' => 'captura_amanata', 'id' => $sid, 'reincercare_in' => $urm - $acum]);
        return 'in_lucru';
    }

    /**
     * Comanda cu plata la sosire s-a anulat (pe orice cale) cât cererea de mutare aștepta gazda.
     * Cererea se citește întâi (verificaCererea): acceptată, rezervarea nouă se
     * anulează prin pașii obișnuiți (comanda nu mai e confirmată); refuzată sau expirată, schimbarea se încheie cu motivul
     * ei; încă în așteptare, schimbarea se oprește, iar gazda primește alerta s-o refuze în EasyCheck; necitită, se
     * reia din cron. Chemată sub zăvorul comenzii, după încheierea anulării.
     */
    public function opresteCererea(array $c, string $actor): void
    {
        $s = $this->inLucru((int) $c['id']);
        if ($s !== null && $s['stare'] === 'cerere') {
            // Aceeași citire ca în cron: o citire eșuată lasă cererea pentru reluarea următoare (comanda e deja anulată).
            $this->verificaCererea($s, $c, $actor);
        }
    }

    /**
     * Încheierea unei schimbări fără aplicare: `esuata` (gazda, plata cu altă sumă, comanda mutată) sau
     * `abandonata` (plata diferenței neterminată). O diferență plătită se returnează integral; oaspetele primește
     * e-mailul (doar când a aflat altfel, $email). @return string starea finală
     */
    private function incheie(int $sid, string $stare, string $motiv, string $actor, bool $email = true): string
    {
        $acum = $this->app->ceas()->moment();
        $n = $this->app->baza()->executa("UPDATE {schimbari_date} SET stare = ?, eroare = ?, urmatoarea = NULL, incheiata_la = ?, actualizat_la = ?, email_la = " . ($email ? 'NULL' : '?') . "
            WHERE id = ? AND stare IN ('plata_in_curs', 'de_aplicat', 'cerere')", array_merge([$stare, mb_substr($motiv, 0, 64), $acum, $acum], $email ? [] : [$acum], [$sid]))->rowCount();
        if ($n !== 1) {
            return (string) ($this->dupaId($sid)['stare'] ?? $stare);
        }
        $s = $this->dupaId($sid);
        $this->app->masinaStari()->jurnal((int) $s['comanda_id'], 'confirmata', 'confirmata', $actor, ['schimbare' => $stare, 'id' => $sid, 'motiv' => $motiv,
            'ec_veche' => (string) $s['ec_veche'], 'ec_noua' => $s['ec_noua'], 'ec_refacuta' => $s['ec_refacuta']]);
        // o diferență doar reținută pe card se eliberează (PaymentIntent anulat), fără rambursare.
        $aut = $s['plata_id'] !== null ? $this->app->baza()->rand("SELECT * FROM {plati} WHERE id = ? AND stare = 'autorizata' AND stripe_pi IS NOT NULL", [$s['plata_id']]) : null;
        if ($aut !== null) {
            $this->elibereazaDiferenta($s, $aut);
        }
        $platit = $s['plata_id'] !== null ? (int) $this->app->baza()->valoare('SELECT suma_incasata FROM {plati} WHERE id = ?', [$s['plata_id']]) : 0;
        if ($platit > 0) {
            $this->app->baza()->executa('UPDATE {schimbari_date} SET rambursare_suma = ? WHERE id = ?', [$platit, $sid]);
            $this->ramburseaza($sid);
        }
        if ($email) {
            $this->anunta($sid);
        }
        return $stare;
    }

    /** Eliberarea diferenței reținute; o eliberare nereușită o lasă să expire la bancă, fără încasare. */
    private function elibereazaDiferenta(array $s, array $p): void
    {
        $c = $this->app->comenzi()->dupaId((int) $s['comanda_id']);
        try {
            $o = $this->app->checkout()->anuleaza((string) $p['stripe_pi'], 'elib-' . $c['ref'] . '-schimbare' . $s['id']);
        } catch (EroareStripe $e) {
            try {
                $o = $this->app->checkout()->citestePi((string) $p['stripe_pi']);
            } catch (EroareStripe) {
                $o = ['status' => null];
            }
        }
        $acum = $this->app->ceas()->moment();
        if (($o['status'] ?? null) === 'succeeded') {
            // Capturată între timp: încasată, deci rambursarea obișnuită urmează în incheie().
            $this->app->baza()->executa("UPDATE {plati} SET stare = 'incasata', suma_incasata = ?, incasata_la = COALESCE(incasata_la, ?), capturata_la = ?, actualizat_la = ? WHERE id = ?",
                [(int) ($o['amount_received'] ?? $p['suma_autorizata']), $acum, $acum, $acum, $p['id']]);
            return;
        }
        if (($o['status'] ?? null) === 'canceled') {
            $this->app->baza()->executa("UPDATE {plati} SET stare = 'anulata', actualizat_la = ? WHERE id = ?", [$acum, $p['id']]);
        }
        $this->app->masinaStari()->jurnal((int) $c['id'], (string) $c['stare'], (string) $c['stare'], 'sistem', ['schimbare' => 'diferenta_eliberata', 'id' => (int) $s['id'], 'pi_stare' => $o['status'] ?? null]);
    }

    /**
     * Rambursarea schimbării (diferența mai mică după aplicare, sau diferența plătită fără aplicare): cheia
     * `ref-<ref>-schimbare<id>`, cu reluările ei (Plati\Rambursari::automata), o singură dată pe oricâte căi. Încheiată
     * (`rambursata_la`) doar când rambursările reușite pe cheie ating suma. Cât o anulare a comenzii e în curs (marcajul
     * pornit, EasyCheck încă n-a confirmat), rambursarea așteaptă: anularea se poate încheia (atunci suma se potrivește
     * cu partea gazdei) sau se poate retrage (atunci diferența se returnează integral). Chemată sub zăvorul comenzii.
     */
    private function ramburseaza(int $sid): void
    {
        $s = $this->dupaId($sid);
        if ($s === null || (int) $s['rambursare_suma'] <= 0 || $s['rambursata_la'] !== null) {
            return;
        }
        $c = $this->app->comenzi()->dupaId((int) $s['comanda_id']);
        $cid = (int) $c['id'];
        $anulata = $c['anulata_ec_la'] !== null || in_array($c['stare'], ['anulata_oaspete', 'anulata_gazda'], true);
        if ($c['anulare_actor'] !== null && !$anulata) {
            // Anularea e doar pornită (marcajul de pe site): rambursarea schimbării rămâne în așteptare, neatinsă, până
            // la încheierea anulării sau până când gazda renunță la ea.
            $this->app->baza()->executa('UPDATE {schimbari_date} SET rambursare_reia = ? WHERE id = ?', [$this->app->ceas()->moment(60), $sid]);
            return;
        }
        $ramb = $this->app->rambursari();
        $fel = 'schimbare' . $sid;
        $baza = 'ref-' . $c['ref'] . '-' . $fel;
        $pi = $s['plata_id'] !== null ? $this->app->baza()->valoare('SELECT stripe_pi FROM {plati} WHERE id = ? AND suma_incasata > 0', [$s['plata_id']]) : null;
        $pi ??= $this->app->anulare()->plata($cid)['stripe_pi'] ?? null;
        if (!is_string($pi)) {
            return;
        }
        $fac = function (array $stari) use ($cid, $baza): int {
            $semne = implode(', ', array_fill(0, count($stari), '?'));
            return (int) $this->app->baza()->valoare("SELECT COALESCE(SUM(suma), 0) FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND (idem = ? OR idem LIKE ?) AND stare IN ($semne)",
                array_merge([$cid, $baza, $baza . '-%'], $stari));
        };
        $rest = (int) $s['rambursare_suma'] - $fac(['reusit', 'in_procesare', 'cerut']);
        if ($anulata && $c['anulare_net'] !== null && $c['anulare_suma'] !== null) {
            // Comanda e anulată, cu suma hotărâtă (gazda în panou, sau anularea pornită de pe site și confirmată de
            // EasyCheck): la gazdă rămâne `anulare_net` - `anulare_suma`, iar netul de atunci cuprindea și diferența
            // încă nereturnată.
            // Rambursarea schimbării ia doar ce depășește partea gazdei, ca împreună cu rambursarea anulării să nu
            // returneze mai mult decât s-a hotărât.
            $peste = max(0, $this->app->anulare()->net($c) - max(0, (int) $c['anulare_net'] - (int) $c['anulare_suma']));
            if ($peste < $rest) {
                $suma = (int) $s['rambursare_suma'] - $rest + $peste;
                $this->app->baza()->executa('UPDATE {schimbari_date} SET rambursare_suma = ? WHERE id = ?', [$suma, $sid]);
                $this->app->masinaStari()->jurnal($cid, (string) $c['stare'], (string) $c['stare'], 'sistem', ['schimbare' => 'rambursare_in_anulare', 'id' => $sid,
                    'suma' => $suma, 'suma_initiala' => (int) $s['rambursare_suma']]);
                $s['rambursare_suma'] = $suma;
                $rest = $peste;
            }
        }
        $r = $ramb->automata($cid, (string) $c['ref'], $pi, max(0, $rest), 'schimbare_date', $fel, true);
        if ($r['epuizata'] && !$ramb->areNelamurita($cid)) {
            $this->app->alertaGazda('alerta_rambursare', ['ref' => (string) $c['ref'], 'suma_bani' => max(0, (int) $s['rambursare_suma'] - $fac(['reusit']))], 'rambursare_schimbare:' . $sid, 30 * 86400);
        }
        if ($fac(['reusit']) >= (int) $s['rambursare_suma']) {
            $this->app->baza()->executa('UPDATE {schimbari_date} SET rambursata_la = ? WHERE id = ? AND rambursata_la IS NULL', [$this->app->ceas()->moment(), $sid]);
            $this->app->masinaStari()->jurnal($cid, (string) $c['stare'], (string) $c['stare'], 'sistem', ['schimbare' => 'rambursata', 'id' => $sid, 'suma' => (int) $s['rambursare_suma']]);
            return;
        }
        $this->app->baza()->executa('UPDATE {schimbari_date} SET rambursare_reia = ? WHERE id = ?', [$this->app->ceas()->moment($r['epuizata'] ? Anulare::EPUIZATA_REIA : 60), $sid]);
    }

    private function amana(array $s, string $motiv, string $actor): string
    {
        $sid = (int) $s['id'];
        $s = $this->dupaId($sid);
        $n = max(1, (int) $s['incercari']);
        $pauza = self::REINCERCARI[min($n - 1, count(self::REINCERCARI) - 1)];
        if ($s['ordine'] === 'anulare_intai' && $s['anulare_veche_la'] !== null && $s['ec_noua'] === null && (int) $s['refacere'] === 0) {
            // Rezervarea veche e anulată, iar cea nouă (cu `inlocuieste`) trebuie să plece în fereastra EasyCheck: reluarea
            // următoare se strânge până înaintea închiderii ei. După fereastră, EasyCheck decide (o cerere anterioară cu
            // aceeași cheie întoarce rezervarea creată; altfel 422 `anulata`, iar rezervarea veche se reface).
            $ramas = self::FEREASTRA - self::FEREASTRA_MARJA - ($this->app->ceas()->acum() - Ceas::dinMoment((string) $s['anulare_veche_la']));
            if ($ramas > 0) {
                $pauza = min($pauza, max(5, intdiv($ramas, 2)));
            }
        }
        $this->app->baza()->executa('UPDATE {schimbari_date} SET urmatoarea = ?, eroare = ?, actualizat_la = ? WHERE id = ?',
            [$this->app->ceas()->moment($pauza), mb_substr($motiv, 0, 64), $this->app->ceas()->moment(), $sid]);
        $this->app->masinaStari()->jurnal((int) $s['comanda_id'], 'confirmata', 'confirmata', $actor, ['schimbare' => 'amanata', 'id' => $sid, 'motiv' => $motiv, 'reincercare_in' => $pauza]);
        $varsta = $this->app->ceas()->acum() - Ceas::dinMoment((string) ($s['pornita_la'] ?? $s['creat_la']));
        $c = $this->app->comenzi()->dupaId((int) $s['comanda_id']);
        if ($s['anulare_veche_la'] !== null && $s['ec_noua'] === null && $varsta >= self::ALERTA) {
            // Rezervarea veche e anulată, iar cea nouă (sau refacerea) așteaptă EasyCheck: gazda află, o dată pentru
            // fiecare, cu datele pe care site-ul încearcă să le adauge (la refacere, datele vechi).
            $refacere = (int) $s['refacere'] === 1;
            $this->app->alertaGazda('alerta_schimbare', ['ref' => (string) $c['ref'], 'cod' => $motiv, 'ec' => (string) $s['ec_veche'], 'fel' => $refacere ? 'intarziere_refacere' : 'intarziere',
                'sosire' => (string) ($refacere ? $s['sosire_veche'] : $s['sosire']), 'plecare' => (string) ($refacere ? $s['plecare_veche'] : $s['plecare'])],
                ($refacere ? 'schimbare_intarziere_refacere:' : 'schimbare_intarziere:') . $sid, 30 * 86400);
        }
        if ($varsta >= self::RENUNTARE && !($s['ec_noua'] !== null && $s['anulare_veche_la'] !== null)) {
            // Cheile de idempotență expiră la EasyCheck: reluările se opresc. Cu rezervarea nouă creată și cea veche anulată,
            // mutarea rămâne: pasul care lipsește e doar aplicarea pe site. Cu rezervarea veche anulată, gazda o adaugă
            // de mână (alerta de refacere); altfel oaspetele rămâne pe datele vechi.
            if ($s['anulare_veche_la'] !== null && $s['ec_noua'] === null) {
                $this->app->alertaGazda('alerta_schimbare', ['ref' => (string) $c['ref'], 'cod' => $motiv, 'ec' => (string) $s['ec_veche'], 'fel' => 'refacere',
                    'sosire' => (string) $s['sosire_veche'], 'plecare' => (string) $s['plecare_veche']], 'schimbare_refacere:' . $sid, 30 * 86400);
            }
            return $this->incheie($sid, 'esuata', 'fara_raspuns', $actor);
        }
        return 'in_lucru';
    }

    private function trecatoare(EroareApi $e): bool
    {
        $inLucru = $e->cod === 'idempotency_conflict' && (($e->detalii()['in_lucru'] ?? false) === true);
        return $inLucru || in_array($e->comportament, ['reincearca', 'pauza'], true) || $e->raspuns->status >= 500 || $e->raspuns->status === 429;
    }

    // ---- sincronizarea anulărilor ----

    /**
     * Anularea rezervării de acum a comenzii, văzută în EasyCheck, face parte dintr-o schimbare de date (marcajul
     * `anulare_pornita_la` pe rezervarea veche, cât schimbarea e în lucru sau după o refacere nereușită): nu e o anulare
     * a gazdei.
     */
    public function anulareProprie(array $c): bool
    {
        $rez = (string) ($c['ec_rezervare'] ?? '');
        return $rez !== '' && $this->app->baza()->valoare("SELECT 1 FROM {schimbari_date} WHERE comanda_id = ? AND ec_veche = ? AND anulare_pornita_la IS NOT NULL
            AND (stare IN ('de_aplicat', 'cerere') OR (stare = 'esuata' AND anulare_veche_la IS NOT NULL AND ec_refacuta IS NULL)) LIMIT 1", [$c['id'], $rez]) !== null;
    }

    // ---- e-mailurile ----

    /** E-mailurile schimbării încheiate, o singură dată: oaspetele și, la aplicare, gazda. */
    private function anunta(int $sid): void
    {
        if ($this->app->baza()->executa('UPDATE {schimbari_date} SET email_la = ? WHERE id = ? AND email_la IS NULL', [$this->app->ceas()->moment(), $sid])->rowCount() !== 1) {
            return;
        }
        $s = $this->dupaId($sid);
        $cid = (int) $s['comanda_id'];
        $c = $this->app->comenzi()->dupaId($cid);
        $o = $this->app->comenzi()->oaspete($c);
        $sablon = $s['stare'] === 'aplicata' ? 'schimbare_facuta' : 'schimbare_esuata';
        if (isset($o['email'])) {
            $this->app->emailuri()->pune($cid, $sablon, (string) $c['limba'], (string) $o['email'], ['comanda_id' => $cid, 'schimbare' => $sid]);
            $this->app->masinaStari()->jurnal($cid, (string) $c['stare'], (string) $c['stare'], 'sistem', ['email' => $sablon, 'schimbare' => $sid]);
        }
        if ($s['stare'] === 'aplicata') {
            $this->anuntaGazda($s, 'gazda_schimbare');
        }
    }

    /** E-mailul către gazdă (fiecare proprietar activ, în limba panoului lui). */
    private function anuntaGazda(array $s, string $sablon): void
    {
        foreach ($this->app->baza()->toate("SELECT email, limba_panou FROM {utilizatori} WHERE rol = 'proprietar' AND activ = 1 ORDER BY id") as $u) {
            $this->app->emailuri()->pune((int) $s['comanda_id'], $sablon, (string) $u['limba_panou'], (string) $u['email'], ['comanda_id' => (int) $s['comanda_id'], 'schimbare' => (int) $s['id']]);
        }
    }

    // ---- cronul ----

    /** Ceva de făcut pentru cron (o interogare ieftină). */
    public function areLucru(): bool
    {
        $acum = $this->app->ceas()->moment();
        return $this->app->baza()->valoare("SELECT 1 FROM {schimbari_date} WHERE (stare = 'plata_in_curs' AND (actualizat_la <= ? OR plata_id IN (SELECT id FROM {plati} WHERE creat_la <= ? AND stare = 'sesiune')))
            OR (stare IN ('de_aplicat', 'cerere') AND (urmatoarea IS NULL OR urmatoarea <= ?))
            OR (rambursare_suma > 0 AND rambursata_la IS NULL AND (rambursare_reia IS NULL OR rambursare_reia <= ?))
            OR (stare IN ('aplicata', 'esuata') AND email_la IS NULL) LIMIT 1", [$this->app->ceas()->moment(-self::RECONCILIERE), $this->app->ceas()->moment(-self::PLATA), $acum, $acum]) !== null;
    }

    /**
     * Cronul: sesiunile diferenței fără anunț (reconcilierea), pașii EasyCheck scadenți (și procesele moarte, după
     * LACAT), cererile către gazdă, rambursările schimbărilor, e-mailurile rămase.
     * @return array{plati:int, pasi:int, rambursari:int}
     */
    public function ruleaza(int $cate = 20): array
    {
        $b = $this->app->baza();
        $acum = $this->app->ceas()->moment();
        $rez = ['plati' => 0, 'pasi' => 0, 'rambursari' => 0];
        // Sesiunile fără anunț de peste RECONCILIERE și cele trecute de termenul plății (PLATA), pe care site-ul le închide.
        foreach ($b->toate("SELECT id, comanda_id FROM {schimbari_date} WHERE stare = 'plata_in_curs' AND (actualizat_la <= ? OR plata_id IN (SELECT id FROM {plati} WHERE creat_la <= ? AND stare = 'sesiune'))
            ORDER BY actualizat_la, id LIMIT " . $cate, [$this->app->ceas()->moment(-self::RECONCILIERE), $this->app->ceas()->moment(-self::PLATA)]) as $r) {
            $this->subZavor((int) $r['comanda_id'], fn (): string => $this->confirmaPlata((int) $r['id'], 'cron'));
            $rez['plati']++;
        }
        foreach ($b->toate("SELECT id, comanda_id FROM {schimbari_date} WHERE stare IN ('de_aplicat', 'cerere') AND (urmatoarea IS NULL OR urmatoarea <= ?) ORDER BY urmatoarea, id LIMIT " . $cate, [$acum]) as $r) {
            $this->subZavor((int) $r['comanda_id'], fn (): string => $this->pas((int) $r['id'], 'cron'));
            $rez['pasi']++;
        }
        foreach ($b->toate("SELECT id, comanda_id FROM {schimbari_date} WHERE rambursare_suma > 0 AND rambursata_la IS NULL AND (rambursare_reia IS NULL OR rambursare_reia <= ?)
            ORDER BY rambursare_reia, id LIMIT " . $cate, [$acum]) as $r) {
            $this->subZavor((int) $r['comanda_id'], function () use ($r): string {
                $this->ramburseaza((int) $r['id']);
                return 'ok';
            });
            $rez['rambursari']++;
        }
        foreach ($b->toate("SELECT id FROM {schimbari_date} WHERE stare IN ('aplicata', 'esuata') AND email_la IS NULL LIMIT " . $cate) as $r) {
            $this->anunta((int) $r['id']);
        }
        return $rez;
    }

    /** Anunțul `cerere.*` (plata la sosire): cererile de schimbare în așteptare se citesc acum. */
    public function dupaAnuntCerere(): void
    {
        $this->app->baza()->executa("UPDATE {schimbari_date} SET urmatoarea = NULL WHERE stare = 'cerere'");
        $this->ruleaza();
    }

    private function subZavor(int $comandaId, callable $f): string
    {
        $r = $this->app->zavor()->cuZavor('comanda:' . $comandaId, 60, $f);
        return $r[0] === true ? (string) $r[1] : 'ocupat';
    }
}
