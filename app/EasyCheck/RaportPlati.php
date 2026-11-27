<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

use Cazo\Joburi\Coada;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;
use Cazo\Plati\Contestari;

defined('CAZO') or exit;

/**
 * Rapoartele plăților către EasyCheck (etapa 2 din „EasyCheck întâi”, contract-plati.md): după ce o rezervare plătită
 * cu cardul a ajuns în EasyCheck (`POST /rezervari` cu `plata`), site-ul spune ce se întâmplă cu banii, prin
 * `POST /rezervari/{id}/plati`: captura (`incasare_confirmata`), suma eliberată de bancă (`incasare_anulata`), rambursările
 * (`rambursare`: `in_procesare`, `reusita`, `esuata`; automate, din panou sau din Dashboardul Stripe) și contestările
 * (`contestare`: `deschisa`, `castigata`, `pierduta`).
 *
 *  - **Când**: doar cu `functii.plati` = true (StareSite::functie, din `PUT /site` sau din `GET /eu`); un EasyCheck mai vechi
 *    (fără funcție, 404 `negasit` la rută) lasă site-ul pe purtarea de azi: nimic nu se scanează, nimic nu pleacă. La
 *    aprinderea funcției, prima scanare ia și rezervările încă vii (plecarea în ultimele 30 de zile sau mai târziu), cu `la`
 *    original: cheia naturală (`rezervare`, `tip`, `ref`) le face sigure la EasyCheck.
 *  - **Evenimentele se deduc din registrul site-ului** (`plati`, `operatii_plata`, `contestari`), nu din fiecare cale de cod:
 *    scanarea compară starea de acum a fiecărui eveniment cu ultimul rând din coadă și pune un rând nou doar la o schimbare
 *    (stare, termen, taxă, tranzacție). Așa nicio cale (cronul, anunțul Stripe, panoul, Dashboardul) nu poate uita un raport.
 *  - **Coada** (`ec_plati`, migrarea 0065): fiecare rând are corpul trimis octet cu octet și cheia lui de idempotență, fixate
 *    la punere. Pe fiecare rezervare pleacă doar cel mai vechi rând nelivrat, deci rapoartele ajung în ordine; o reluare
 *    amânată ține pe loc rândurile de după ea. Reluările: 1, 5, 30 de minute, 2 ore, apoi la 6 ore, cel mult 7 zile
 *    (EasyCheck căzut o zi nu pierde nimic); apoi rândul se oprește, cu o linie în jurnal.
 *  - **Cheia**: rapoartele pleacă doar cu cheia care a creat rezervarea sau cu una care a înlocuit-o (
 *    Conectare::comandaStraina); cu altă cheie, rândul se oprește fără cerere (`alta_cheie`).
 *  - **Sumele**: după ultimul rând al unei rezervări, sumele din răspuns (`plati.platit`, `plati.rambursat`) se compară cu
 *    registrul site-ului; la fel, sumele din lista rezervărilor (`GET /rezervari?actualizate_de_la=`). EasyCheck știe și
 *    plățile notate de gazdă (numerar, POS, restul la sosire), deci alerta pleacă doar când EasyCheck știe mai puțini bani
 *    mișcați pe card decât site-ul: încasarea brută (`platit + rambursat`) sub cea a site-ului sau returnările sub cele ale
 *    site-ului. Alerta gazdei `alerta_plati_diferite` (cod `plati_diferite` în starea site-ului), o dată la 7 zile pe rezervare.
 *  - Nimic nu blochează oaspetele: rapoartele pleacă din cron (sarcina `ec:plati`, la minut); singura trimitere pe loc e
 *    eliberarea dinaintea anulării automate a rezervării (Retinere::orfana), ca EasyCheck să știe întâi că plata e 0.
 */
final class RaportPlati
{
    public const SETARE = 'ec.plati';
    public const ZAVOR = 'ec:plati';
    /** Rezervările luate la prima scanare: plecarea cel mult cu atât în urmă. */
    public const INAPOI = 30 * 86400;
    /** Scanarea incrementală ia schimbările de la cursor, cu atâta suprapunere (scrieri în timpul scanării). */
    public const SUPRAPUNERE = 300;
    /** Cât se reia un raport fără răspuns. */
    public const VIATA = 7 * 86400;
    /** Pauza după ultima treaptă din Coada::REINCERCARI. */
    public const REIA_RAR = 6 * 3600;
    /** Cât ține un rând preluat, ca o rulare paralelă să-l lase în pace. */
    private const LACAT = 120;
    /** Cel mult o alertă de sume diferite pe rezervare în acest interval. */
    public const ALERTA = 7 * 86400;

    /** Rambursările operațiilor (operatii_plata.motiv) => `motiv` din contract. Restul sunt refuzuri automate. */
    private const MOTIVE = ['anulare_oaspete' => 'anulare_oaspete', 'anulare_gazda' => 'anulare_gazda', 'gest_gazda' => 'gest_gazda', 'schimbare_date' => 'schimbare_date'];

    public function __construct(private Aplicatie $app)
    {
    }

    /** Funcția e pornită: comutatorul stării site-ului, o cheie EasyCheck valabilă și `functii.plati` = true în EasyCheck. */
    public function activa(): bool
    {
        $s = $this->app->stareSite();
        return $s->pornit() && $this->app->secrete()->citeste('ec_cheie') !== null && $this->app->ecLicenta()->stare()['valida'] && $s->functie('plati');
    }

    /** Sarcina cronului (`ec:plati`): scanarea registrului, apoi rapoartele scadente. Întoarce rezumatul pentru panou. */
    public function sarcina(): string
    {
        if (!$this->activa()) {
            return 'oprită: EasyCheck fără rapoartele plăților';
        }
        $r = null;
        $this->app->zavor()->cuZavor(self::ZAVOR, 300, function () use (&$r): void {
            $puse = $this->scaneaza();
            $r = ['puse' => $puse] + $this->trimite();
        });
        if ($r === null) {
            return 'ocupată';
        }
        return "puse: {$r['puse']}, trimise: {$r['trimise']}, amânate: {$r['amanate']}, oprite: {$r['oprite']}";
    }

    /**
     * Trimiterea pe loc a rapoartelor unei comenzi (eliberarea dinaintea anulării automate, Retinere::orfana): scanarea ei
     * și rândurile ei scadente. Erorile rămân aici; ce nu pleacă acum pleacă din cron.
     */
    public function trimiteAcum(int $comandaId): void
    {
        try {
            if (!$this->activa()) {
                return;
            }
            $this->app->zavor()->cuZavor(self::ZAVOR, 60, function () use ($comandaId): void {
                $this->scaneaza([$comandaId]);
                $this->trimite(5, $comandaId);
            });
        } catch (\Throwable $e) {
            $this->app->jurnal()->avertisment('Raportul plății către EasyCheck s-a amânat', ['eroare' => get_class($e)]);
        }
    }

    // ---- scanarea ----

    /**
     * Compară evenimentele deduse din registru cu ultimul rând din coadă și pune rândurile noi. Fără $ids: comenzile
     * schimbate de la ultima scanare (la prima, cele încă vii). Întoarce câte rânduri au intrat în coadă.
     * @param list<int>|null $ids
     */
    public function scaneaza(?array $ids = null): int
    {
        $b = $this->app->baza();
        $acum = $this->app->ceas()->acum();
        $tinut = (array) ($this->app->setari()->get(self::SETARE) ?? []);
        if ($ids === null) {
            $cursor = is_string($tinut['scanat_la'] ?? null) ? $tinut['scanat_la'] : null;
            if ($cursor === null) {
                $ids = array_map('intval', array_column($b->toate("SELECT id FROM {comenzi} WHERE fel = 'card' AND ec_rezervare IS NOT NULL AND plecare >= ? ORDER BY id",
                    [gmdate('Y-m-d', $acum - self::INAPOI)]), 'id'));
            } else {
                $de = Ceas::formateaza(Ceas::dinMoment($cursor) - self::SUPRAPUNERE);
                $ids = [];
                foreach (['SELECT DISTINCT comanda_id AS id FROM {plati} WHERE actualizat_la >= ?', 'SELECT DISTINCT comanda_id AS id FROM {operatii_plata} WHERE actualizat_la >= ?',
                    'SELECT DISTINCT comanda_id AS id FROM {contestari} WHERE actualizat_la >= ? AND comanda_id IS NOT NULL', 'SELECT id FROM {comenzi} WHERE actualizat_la >= ? AND ec_rezervare IS NOT NULL'] as $sql) {
                    foreach ($b->toate($sql, [$de]) as $r) {
                        $ids[(int) $r['id']] = (int) $r['id'];
                    }
                }
                $ids = array_values($ids);
                sort($ids);
            }
            $this->app->setari()->pune(self::SETARE, ['scanat_la' => Ceas::formateaza($acum)] + $tinut);
        }
        $puse = 0;
        foreach ($ids as $id) {
            $c = $this->app->comenzi()->dupaId($id);
            if ($c === null) {
                continue;
            }
            foreach ($this->evenimente($c) as $e) {
                $puse += $this->pune($c, $e) ? 1 : 0;
            }
        }
        return $puse;
    }

    /**
     * Evenimentele de acum ale comenzii, în forma contractului (fără `la` ajustat): captura sau eliberarea plății inițiale,
     * rambursările cunoscute de Stripe și contestările care rețin bani. Doar pentru comenzile cu cardul cu rezervare în
     * EasyCheck; după o mutare, doar ce s-a întâmplat de la mutare (rezervarea veche le are pe ale ei).
     * @return list<array{tip:string, ref:string, stare:?string, suma:?int, la:int, corp:array}>
     */
    public function evenimente(array $c): array
    {
        $rez = (string) ($c['ec_rezervare'] ?? '');
        if ($c['fel'] !== 'card' || !preg_match('~^rez_[0-9A-Za-z]{24}$~D', $rez) || \Cazo\Local\Mod::comandaLocala($c)) {
            return [];
        }
        $b = $this->app->baza();
        $id = (int) $c['id'];
        $mutare = $b->valoare("SELECT MAX(aplicata_la) FROM {schimbari_date} WHERE comanda_id = ? AND stare = 'aplicata'", [$id]);
        $dela = is_string($mutare) ? Ceas::dinMoment($mutare) : 0;
        $ev = [];
        $ref = static fn (string $x): ?string => preg_match('~^[A-Za-z0-9_.\-]{1,100}$~D', $x) ? $x : null;
        // Plata inițială: captura (încasată) sau eliberarea băncii (anulată fără încasare).
        $p = $b->rand('SELECT * FROM {plati} WHERE comanda_id = ? AND schimbare_id IS NULL AND stripe_pi IS NOT NULL ORDER BY id DESC LIMIT 1', [$id]);
        $pi = $p !== null ? $ref((string) $p['stripe_pi']) : null;
        if ($p !== null && $pi !== null) {
            if ((int) $p['suma_incasata'] > 0 && in_array($p['stare'], ['incasata', 'rambursata_partial', 'rambursata'], true)) {
                $la = Ceas::dinMoment((string) ($p['capturata_la'] ?? $p['incasata_la'] ?? $c['platita_la'] ?? $p['actualizat_la']));
                $corp = ['tip' => 'incasare_confirmata', 'ref' => $pi];
                if ($p['comision'] !== null && (int) $p['comision'] >= 0) {
                    $corp['comision'] = (int) $p['comision'];
                }
                $ev[] = ['tip' => 'incasare_confirmata', 'ref' => $pi, 'stare' => null, 'suma' => null, 'la' => $la, 'corp' => $corp];
            } elseif ($p['stare'] === 'anulata' && (int) $p['suma_incasata'] === 0) {
                $ev[] = ['tip' => 'incasare_anulata', 'ref' => $pi, 'stare' => null, 'suma' => null, 'la' => Ceas::dinMoment((string) $p['actualizat_la']),
                    'corp' => ['tip' => 'incasare_anulata', 'ref' => $pi]];
            }
        }
        // Rambursările cunoscute de Stripe (cu id `re_`): cele `cerut` așteaptă răspunsul, iar un refuz pe loc n-a existat la Stripe.
        foreach ($b->toate("SELECT * FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND stripe_id IS NOT NULL AND stare IN ('in_procesare', 'reusit', 'esuat') ORDER BY id", [$id]) as $o) {
            $re = $ref((string) $o['stripe_id']);
            if ($re === null || !str_starts_with($re, 're_')) {
                continue;
            }
            $stare = ['in_procesare' => 'in_procesare', 'reusit' => 'reusita', 'esuat' => 'esuata'][$o['stare']];
            $la = Ceas::dinMoment((string) ($o['stare'] === 'in_procesare' ? $o['la'] : $o['actualizat_la']));
            $corp = ['tip' => 'rambursare', 'suma' => (int) $o['suma'], 'stare' => $stare, 'ref' => $re];
            $plataRef = $ref((string) ($o['stripe_pi'] ?? $p['stripe_pi'] ?? ''));
            if ($plataRef !== null) {
                $corp['plata_ref'] = $plataRef;
            }
            $corp['motiv'] = str_starts_with((string) $o['idem'], 'dash-') ? 'din_cont_stripe' : (self::MOTIVE[(string) $o['motiv']] ?? 'refuz_automat');
            // rambursarea făcută la o comandă din EasyCheck poartă comanda (cheia Stripe
            // `ref-<ref>-ec-<cmd>`), ca EasyCheck să lege raportul de evenimentul scris la rezultatul comenzii.
            if (preg_match('~^ref-.+-ec-(cmd_[0-9A-Za-z]{24})(-|$)~', (string) $o['idem'], $mc)) {
                $corp['comanda'] = $mc[1];
            }
            if ((int) $o['suma'] < 1 || Ceas::dinMoment((string) $o['la']) < $dela) {
                continue;
            }
            $ev[] = ['tip' => 'rambursare', 'ref' => $re, 'stare' => $stare, 'suma' => (int) $o['suma'], 'la' => $la, 'corp' => $corp];
        }
        // Contestările care rețin bani (întrebările băncii, `warning_*`, nu).
        foreach ($this->app->contestari()->aleComenzii($id) as $d) {
            $stare = Contestari::stareEc((string) $d['stare']);
            $dp = $ref((string) $d['stripe_id']);
            if ($stare === null || $dp === null || (int) $d['suma'] < 1 || Ceas::dinMoment((string) $d['deschisa_la']) < $dela) {
                continue;
            }
            $la = Ceas::dinMoment((string) ($stare === 'deschisa' ? $d['deschisa_la'] : ($d['inchisa_la'] ?? $d['actualizat_la'])));
            $corp = ['tip' => 'contestare', 'suma' => (int) $d['suma'], 'stare' => $stare, 'ref' => $dp];
            if (($x = $ref((string) ($d['stripe_pi'] ?? ''))) !== null) {
                $corp['plata_ref'] = $x;
            }
            if (is_string($d['txn'] ?? null) && ($x = $ref($d['txn'])) !== null) {
                $corp['txn'] = $x;
            }
            if (is_string($d['motiv'] ?? null) && preg_match('~^[a-z_]{1,40}$~D', $d['motiv'])) {
                $corp['motiv'] = $d['motiv'];
            }
            if ($d['termen'] !== null) {
                $corp['termen'] = self::moment(Ceas::dinMoment((string) $d['termen']));
            }
            if ($d['debitat_la'] !== null) {
                $corp['debitat_la'] = self::moment(Ceas::dinMoment((string) $d['debitat_la']));
            }
            if ((int) $d['taxa'] > 0) {
                $corp['taxa'] = (int) $d['taxa'];
            }
            if ((int) $d['taxa_returnata'] > 0) {
                $corp['taxa_returnata'] = (int) $d['taxa_returnata'];
            }
            $ev[] = ['tip' => 'contestare', 'ref' => $dp, 'stare' => $stare, 'suma' => (int) $d['suma'], 'la' => $la, 'corp' => $corp];
        }
        // Captura sau eliberarea dinaintea unei mutări a plecat spre rezervarea veche.
        return array_values(array_filter($ev, static fn (array $e): bool => !in_array($e['tip'], ['incasare_confirmata', 'incasare_anulata'], true) || $e['la'] >= $dela));
    }

    /**
     * Pune în coadă evenimentul, când diferă de ultimul rând al aceleiași chei naturale (starea sau câmpurile ținute de
     * amprentă). Suma rămâne cea din primul raport (contractul: alt `suma` pe aceeași cheie dă 409), iar `la` nu coboară
     * sub ultimul raport (un `la` mai vechi n-ar schimba nimic la EasyCheck). O rambursare eșuată fără un raport anterior
     * nu pleacă: EasyCheck n-a știut de ea.
     */
    private function pune(array $c, array $e): bool
    {
        $b = $this->app->baza();
        $rez = (string) $c['ec_rezervare'];
        $ultim = $b->rand('SELECT * FROM {ec_plati} WHERE rezervare = ? AND tip = ? AND ref = ? ORDER BY id DESC LIMIT 1', [$rez, $e['tip'], $e['ref']]);
        if ($ultim === null && $e['tip'] === 'rambursare' && $e['stare'] === 'esuata') {
            return false;
        }
        $amprenta = self::amprenta($e['corp']);
        if ($ultim !== null && $ultim['amprenta'] === $amprenta) {
            return false;
        }
        $la = $e['la'];
        $corp = $e['corp'];
        if ($ultim !== null) {
            $la = max($la, Ceas::dinMoment((string) $ultim['la']));
            if ($ultim['suma'] !== null && array_key_exists('suma', $corp)) {
                $corp['suma'] = (int) $ultim['suma'];
            }
        }
        // „Cel mult 5 minute în viitor”: un ceas al serverului înaintea celui EasyCheck nu trimite momente din viitor.
        $la = min($la, $this->app->ceas()->acum());
        $corp['la'] = self::moment($la);
        $acum = $this->app->ceas()->moment();
        $b->insereaza('ec_plati', ['comanda_id' => (int) $c['id'], 'rezervare' => $rez, 'tip' => $e['tip'], 'ref' => $e['ref'], 'stare' => $e['stare'],
            'suma' => $corp['suma'] ?? null, 'la' => Ceas::formateaza($la), 'amprenta' => $amprenta, 'corp' => Client::json(self::ordonat($corp)),
            'idem' => 'plati-' . Client::uuid(), 'livrare' => 'de_trimis', 'incercari' => 0, 'urmatoarea' => $acum, 'creat_la' => $acum, 'actualizat_la' => $acum]);
        return true;
    }

    /** Ce deosebește două rapoarte ale aceluiași eveniment: starea și câmpurile care se pot schimba (fără `la`, `comision`). */
    private static function amprenta(array $corp): string
    {
        $a = array_intersect_key($corp, array_flip(['stare', 'termen', 'taxa', 'taxa_returnata', 'txn', 'debitat_la', 'motiv', 'plata_ref']));
        ksort($a);
        return substr(hash('sha256', $corp['tip'] . '|' . json_encode($a)), 0, 32);
    }

    /** Corpul în ordinea din contract (ușor de citit în jurnale). */
    private static function ordonat(array $corp): array
    {
        $ordine = ['tip', 'suma', 'stare', 'ref', 'plata_ref', 'la', 'motiv', 'comanda', 'comision', 'termen', 'taxa', 'taxa_returnata', 'txn', 'debitat_la'];
        return array_replace(array_intersect_key(array_flip($ordine), $corp), $corp);
    }

    // ---- trimiterea ----

    /**
     * Rândurile scadente, câte unul pe rezervare (cel mai vechi nelivrat), în ordine; după o livrare, rândul următor al
     * aceleiași rezervări poate pleca în aceeași rulare. @return array{trimise:int, amanate:int, oprite:int}
     */
    public function trimite(int $cate = 20, ?int $comandaId = null): array
    {
        $rez = ['trimise' => 0, 'amanate' => 0, 'oprite' => 0];
        $b = $this->app->baza();
        $sarite = [];
        for ($i = 0; $i < $cate; $i++) {
            if (!$this->app->ecBuget()->permiteScriereNecritica()) {
                break;
            }
            $param = [$this->app->ceas()->moment()];
            $filtru = '';
            if ($comandaId !== null) {
                $filtru .= ' AND p.comanda_id = ?';
                $param[] = $comandaId;
            }
            if ($sarite !== []) {
                $filtru .= ' AND p.rezervare NOT IN (' . implode(', ', array_fill(0, count($sarite), '?')) . ')';
                array_push($param, ...array_keys($sarite));
            }
            $x = $b->rand("SELECT p.* FROM {ec_plati} p WHERE p.livrare = 'de_trimis' AND p.urmatoarea <= ?" . $filtru . "
                AND NOT EXISTS (SELECT 1 FROM {ec_plati} q WHERE q.rezervare = p.rezervare AND q.livrare = 'de_trimis' AND q.id < p.id) ORDER BY p.urmatoarea, p.id LIMIT 1", $param);
            if ($x === null) {
                break;
            }
            $r = $this->trimiteRand($x);
            $rez[$r]++;
            if ($r === 'amanate') {
                $sarite[(string) $x['rezervare']] = true;
            }
        }
        return $rez;
    }

    /** @return string trimise, amanate, oprite */
    private function trimiteRand(array $x): string
    {
        $b = $this->app->baza();
        $id = (int) $x['id'];
        $c = $this->app->comenzi()->dupaId((int) $x['comanda_id']);
        if ($c === null) {
            return $this->opreste($x, 'comanda_lipsa', null);
        }
        if ($this->app->ecConectare()->comandaStraina($c, $this->app->secrete()->citeste('ec_cheie'))) {
            // rezervarea e a altei chei, nelegate de cea de acum; EasyCheck ar răspunde 403 rezervare_straina.
            return $this->opreste($x, 'alta_cheie', null);
        }
        $n = (int) $x['incercari'] + 1;
        $b->executa('UPDATE {ec_plati} SET incercari = ?, urmatoarea = ?, actualizat_la = ? WHERE id = ?', [$n, $this->app->ceas()->moment(self::LACAT), $this->app->ceas()->moment(), $id]);
        try {
            $r = $this->app->ecClient()->scrie('POST', '/rezervari/' . $x['rezervare'] . '/plati', (string) $x['corp'], (string) $x['idem']);
        } catch (EroareApi $e) {
            $cod = $e->cod ?? 'http_' . $e->raspuns->status;
            $inLucru = $cod === 'idempotency_conflict' && (($e->detalii()['in_lucru'] ?? false) === true);
            if ($inLucru || $e->comportament === 'reincearca' || $e->comportament === 'licenta' && $cod !== 'rezervare_straina' || $e->raspuns->status >= 500 || $e->raspuns->status === 429) {
                return $this->amana($x, $n, $cod, $e->raspuns->retryAfter());
            }
            $motiv = $e->detalii()['motiv'] ?? null;
            return $this->opreste($x, $cod . (is_string($motiv) && preg_match('~^[a-z_]{1,40}$~', $motiv) ? ':' . $motiv : ''), $e->requestId);
        } catch (EroareTransport $e) {
            if ($e->felul === 'pauza') {
                // Pauza cerută de EasyCheck (Retry-After): cererea n-a plecat, deci încercarea nu se socotește.
                $b->executa('UPDATE {ec_plati} SET incercari = ?, urmatoarea = ? WHERE id = ?', [$n - 1, $this->app->ceas()->moment(max(60, (int) $e->reiaDupa)), $id]);
                return 'amanate';
            }
            return $this->amana($x, $n, $e->felul, null);
        } catch (\LengthException $e) {
            return $this->opreste($x, 'corp_prea_mare', null);
        }
        $j = $r->json() ?? [];
        $plati = is_array($j['plati'] ?? null) && is_int($j['plati']['platit'] ?? null) && is_int($j['plati']['rambursat'] ?? null)
            ? ['platit' => $j['plati']['platit'], 'rambursat' => $j['plati']['rambursat']] : null;
        $raspuns = ['inregistrat' => ($j['inregistrat'] ?? null) === true, 'plati' => $plati, 'avertizare' => is_string($j['avertizare'] ?? null) ? mb_substr($j['avertizare'], 0, 40) : null];
        $b->executa("UPDATE {ec_plati} SET livrare = 'trimis', cod = NULL, urmatoarea = NULL, raspuns = ?, actualizat_la = ? WHERE id = ?",
            [json_encode($raspuns, JSON_UNESCAPED_SLASHES), $this->app->ceas()->moment(), $id]);
        if ($raspuns['avertizare'] !== null) {
            $this->app->jurnal()->avertisment('EasyCheck: returnările depășesc plățile rezervării', ['ref' => $c['ref'], 'rezervare' => $x['rezervare'], 'avertizare' => $raspuns['avertizare']]);
        }
        // Sumele se compară după ultimul raport al rezervării (cele de dinainte arată o stare de mijloc).
        if ($plati !== null && $b->valoare("SELECT 1 FROM {ec_plati} WHERE rezervare = ? AND livrare = 'de_trimis' LIMIT 1", [$x['rezervare']]) === null) {
            $this->compara($c, (string) $x['rezervare'], $plati);
        }
        return 'trimise';
    }

    private function amana(array $x, int $n, string $cod, ?int $retryAfter): string
    {
        $creat = Ceas::dinMoment((string) $x['creat_la']);
        if ($this->app->ceas()->acum() - $creat >= self::VIATA) {
            return $this->opreste($x, $cod, null);
        }
        $pauza = Coada::REINCERCARI[$n - 1] ?? self::REIA_RAR;
        $pauza = max($pauza, (int) $retryAfter);
        $this->app->baza()->executa('UPDATE {ec_plati} SET urmatoarea = ?, cod = ?, actualizat_la = ? WHERE id = ?',
            [$this->app->ceas()->moment($pauza), mb_substr($cod, 0, 64), $this->app->ceas()->moment(), (int) $x['id']]);
        return 'amanate';
    }

    private function opreste(array $x, string $cod, ?string $requestId): string
    {
        $this->app->baza()->executa("UPDATE {ec_plati} SET livrare = 'oprit', cod = ?, urmatoarea = NULL, actualizat_la = ? WHERE id = ?",
            [mb_substr($cod, 0, 64), $this->app->ceas()->moment(), (int) $x['id']]);
        $this->app->jurnal()->avertisment('Raportul plății n-a ajuns la EasyCheck', ['rezervare' => $x['rezervare'], 'tip' => $x['tip'], 'stare' => $x['stare'], 'cod' => $cod, 'request_id' => $requestId]);
        return 'oprite';
    }

    // ---- sumele ----

    /**
     * Registrul site-ului pentru comandă: încasat pe card (toate plățile), returnat (rambursările reușite și contestările
     * care țin banii la bancă). @return array{incasat:int, rambursat:int}
     */
    public function registru(int $comandaId): array
    {
        $b = $this->app->baza();
        $incasat = (int) $b->valoare('SELECT COALESCE(SUM(suma_incasata), 0) FROM {plati} WHERE comanda_id = ?', [$comandaId]);
        $rambursat = (int) $b->valoare("SELECT COALESCE(SUM(suma), 0) FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND stare = 'reusit'", [$comandaId]);
        return ['incasat' => $incasat, 'rambursat' => $rambursat + $this->app->contestari()->retinut($comandaId)];
    }

    /**
     * Sumele din lista rezervărilor (`GET /rezervari?actualizate_de_la=`, Anulare::sincronizeaza): pentru rezervările cu
     * rapoarte livrate și fără rapoarte în lucru, aceeași comparație ca după un raport.
     */
    public function dinLista(array $r): void
    {
        try {
            $plati = $r['plati'] ?? null;
            if (!is_string($r['id'] ?? null) || !is_array($plati) || !is_int($plati['platit'] ?? null) || !is_int($plati['rambursat'] ?? null) || !$this->activa()) {
                return;
            }
            $b = $this->app->baza();
            $cid = $b->valoare("SELECT comanda_id FROM {ec_plati} WHERE rezervare = ? AND livrare = 'trimis' ORDER BY id DESC LIMIT 1", [$r['id']]);
            if ($cid === null || $b->valoare("SELECT 1 FROM {ec_plati} WHERE rezervare = ? AND livrare = 'de_trimis' LIMIT 1", [$r['id']]) !== null) {
                return;
            }
            $c = $this->app->comenzi()->dupaId((int) $cid);
            if ($c !== null && $c['ec_rezervare'] === $r['id']) {
                $this->compara($c, $r['id'], ['platit' => $plati['platit'], 'rambursat' => $plati['rambursat']]);
            }
        } catch (\Throwable $e) {
            $this->app->jurnal()->avertisment('Sumele EasyCheck nu s-au putut compara', ['eroare' => get_class($e)]);
        }
    }

    /**
     * Comparația: EasyCheck știe și plățile notate de gazdă, deci doar mai puțini bani mișcați pe card decât știe site-ul
     * înseamnă un raport lipsă: încasarea brută (`platit + rambursat`) sub cea a site-ului, sau returnările sub ale lui.
     * @param array{platit:int, rambursat:int} $ec
     */
    public function compara(array $c, string $rezervare, array $ec): bool
    {
        $reg = $this->registru((int) $c['id']);
        $diferenta = max($reg['incasat'] - ($ec['platit'] + $ec['rambursat']), $reg['rambursat'] - $ec['rambursat']);
        if ($diferenta <= 0) {
            return false;
        }
        $this->app->jurnal()->avertisment('Sumele din EasyCheck diferă de plățile site-ului', ['ref' => $c['ref'], 'rezervare' => $rezervare,
            'site' => $reg, 'easycheck' => $ec]);
        $this->app->alertaGazda('alerta_plati_diferite', ['ref' => (string) $c['ref'], 'incasat_bani' => $reg['incasat'], 'rambursat_bani' => $reg['rambursat'],
            'ec_platit_bani' => $ec['platit'], 'ec_rambursat_bani' => $ec['rambursat'], 'suma_bani' => $diferenta], 'plati_diferite:' . $c['ref'], self::ALERTA);
        return true;
    }

    public static function moment(int $t): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $t);
    }
}
