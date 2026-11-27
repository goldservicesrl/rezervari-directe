<?php

declare(strict_types=1);

namespace Cazo\Local;

use Cazo\EasyCheck\RaspunsEc;
use Cazo\EasyCheck\Transport;
use Cazo\Nucleu\Aplicatie;

defined('CAZO') or exit;

/**
 * API-ul EasyCheck v1 răspuns de site, din baza lui. În modul local, clientul EasyCheck primește acest
 * transport: oglinda citește proprietatea, camerele, disponibilitatea și prețurile completate în panou, iar comanda,
 * cererea cu plata la sosire, transferul și anularea scriu în tabelele `local_*`, cu aceleași răspunsuri, coduri de
 * eroare și idempotență ca EasyCheck. Nicio cerere nu pleacă în rețea.
 *
 *  - prețul: „Prețul de pe site-ul meu” (pret.sursa = site), un singur tarif („plan_std”) cu avansul și anularea din
 *    regula proprietății; prețul nopții e cel de weekend vinerea și sâmbăta, altfel cel pe noapte; fără taxe
 *    suplimentare și fără taxă locală;
 *  - sejurul minim: același pe toate zilele;
 *  - rezervarea plătită cu cardul intră mereu (`suprapunere` când o noapte s-a ocupat chiar atunci), cea cu transfer
 *    și cererea se refuză pe nopți ocupate (409 `indisponibil`), ca în EasyCheck;
 *  - deciziile gazdei (acceptarea cererii, „Am primit banii”) vin din panoul site-ului, pe rutele de probă ale API-ului
 *    (`/proba-accepta`, `/proba-plata-confirma` ...), deci panoul folosește aceleași butoane ca la cheia de probă;
 *    „Prelungiți termenul” are ruta ei, doar aici: `POST /rezervari/{id}/prelungeste-termen` cu `{zile}`;
 *  - termenul cererii e timpul ales de gazdă (2-72 de ore, implicit 24), fixat la crearea cererii;
 *  - reducerile: fiecare reducere pornită e un tarif calculat din „plan_std”, ca tarifele de sejur lung din
 *    EasyCheck: PLAN_LUNG (`tip` = `sejur_lung`, `prag_nopti` = N, sejurul minim N în grilă) și PLAN_TIMP
 *    (`tip` = `din_timp`, `prag_zile` = N, sosirea închisă în zilele mai apropiate de azi decât N). Prețul fiecărei
 *    nopți e cel al planului de bază (cu weekendul), minus procentul. Aceeași politică de anulare, deci `Pret\Oferte`
 *    alege dintre ele tariful cel mai ieftin: când se aplică amândouă, câștigă reducerea cea mai mare, ca în EasyCheck.
 */
final class TransportLocal implements Transport
{
    /** Termenul implicit de răspuns al gazdei la o cerere cu plata la sosire (ca în EasyCheck); setarea gazdei. */
    public const TERMEN_CERERE = Depozit::TERMEN_CERERE_IMPLICIT * 3600;
    public const PLAN = 'plan_std';
    /** Tarifele calculate ale reducerilor, cu identificatori în forma celor din EasyCheck (`plan_` + 16 hex). */
    public const PLAN_LUNG = 'plan_0000000000000001';
    public const PLAN_TIMP = 'plan_0000000000000002';
    /** Prelungirile termenului de transfer, în zile (ca în EasyCheck). */
    public const PRELUNGIRI_ZILE = [1, 2, 3, 7];

    private Depozit $depozit;

    public function __construct(private Aplicatie $app)
    {
        $this->depozit = $app->localDepozit();
    }

    public function trimite(string $metoda, string $url, array $antete, ?string $corp, array $limite): RaspunsEc
    {
        $u = parse_url($url);
        $cale = (string) ($u['path'] ?? '');
        $poz = strpos($cale, '/api/v1');
        $ruta = $poz === false ? $cale : substr($cale, $poz + 7);
        parse_str((string) ($u['query'] ?? ''), $q);
        $a = [];
        foreach ($antete as $k => $v) {
            $a[strtolower((string) $k)] = (string) $v;
        }
        try {
            return $this->ruta($metoda, $ruta, $q, $a, $corp);
        } catch (\JsonException) {
            return self::eroare(400, 'json_invalid');
        }
    }

    private function ruta(string $m, string $r, array $q, array $a, ?string $corp): RaspunsEc
    {
        // fără cheie nu există comenzi, cereri de anulare spre EasyCheck sau rutele lor de
        // probă; toate hotărârile rămân în panoul site-ului. Răspunsul e cel al unui EasyCheck fără etapa 3.
        if (preg_match('~^/comenzi(/|$)~', $r) || preg_match('~^/rezervari/[^/]+/(cerere-anulare|contract-anulare|proba-anuleaza-gazda|proba-anulare-decide|proba-rambursare|proba-mesaj)$~', $r)) {
            return self::eroare(404, 'negasit');
        }
        // mesajele scrise în EasyCheck nu există în modul local; gazda scrie paragraful în panou.
        if ($r === '/mesaje' || str_starts_with($r, '/mesaje/')) {
            return self::eroare(404, 'negasit');
        }
        if ($m === 'GET') {
            return match (true) {
                $r === '/eu' => self::ok($this->eu()),
                $r === '/proprietate' => $this->cuEtag($this->proprietate(), $a),
                $r === '/camere' => $this->cuEtag($this->camere(), $a),
                $r === '/disponibilitate' => $this->fereastra($q, fn (string $de, string $pana): array => $this->disponibilitate($de, $pana), $a),
                $r === '/preturi' => $this->fereastra($q, fn (string $de, string $pana): array => $this->preturi($de, $pana), $a),
                $r === '/webhook' => self::ok(['adresa' => null, 'stare' => 'activ', 'ultima_livrare_la' => null, 'oprit_la' => null, 'in_asteptare' => 0]),
                $r === '/rezervari' => $this->listaRezervari($q),
                (bool) preg_match('~^/rezervari/(rez_[0-9A-Za-z]{1,60})$~', $r, $x) => $this->rezervare($x[1]),
                (bool) preg_match('~^/cereri/(cer_[0-9A-Za-z]{1,60})$~', $r, $x) => $this->cerere($x[1]),
                default => self::eroare(404, 'negasit'),
            };
        }
        if ($m === 'PUT' && $r === '/webhook') {
            return self::ok(['adresa' => null, 'stare' => 'activ', 'secret' => null]);
        }
        if ($m === 'DELETE' && $r === '/webhook') {
            return new RaspunsEc(204, self::antete(), '');
        }
        if ($m === 'POST' && $r === '/webhook/proba') {
            return self::ok(['id' => 'evt_local', 'livrat' => true, 'cod_http' => null]);
        }
        if ($m !== 'POST') {
            return self::eroare(404, 'negasit');
        }
        if ($r === '/rezervari' || $r === '/cereri') {
            return $this->idempotent($a, $corp, fn (): RaspunsEc => $r === '/cereri' ? $this->cerereNoua($corp) : $this->rezervareNoua($corp));
        }
        if (preg_match('~^/cereri/(cer_[0-9A-Za-z]{1,60})/(proba-accepta|proba-refuza)$~', $r, $x)) {
            return $this->decideCererea($x[1], $x[2] === 'proba-accepta');
        }
        if (preg_match('~^/rezervari/(rez_[0-9A-Za-z]{1,60})/(anuleaza|proba-plata-confirma|proba-plata-refuza|transfer-anuntat|anonimizeaza|prelungeste-termen)$~', $r, $x)) {
            return match ($x[2]) {
                'anuleaza' => $this->idempotent($a, null, fn (): RaspunsEc => $this->anuleaza($x[1])),
                'transfer-anuntat' => $this->transferAnuntat($x[1]),
                'prelungeste-termen' => $this->prelungesteTermen($x[1], $corp),
                'anonimizeaza' => $this->anonimizeaza($x[1]),
                default => $this->decidePlata($x[1], $x[2] === 'proba-plata-confirma'),
            };
        }
        return self::eroare(404, 'negasit');
    }

    // ---- citirile ----

    private function eu(): array
    {
        $p = $this->depozit->proprietate();
        // Cheia Stripe de test face site-ul „de probă” (banda și noindex), ca o plată de test să nu pară o rezervare reală.
        $mod = $this->app->stripe()->mod() === 'test' ? 'proba' : 'live';
        return ['proprietate' => ['id' => $this->depozit->idProprietate(), 'nume' => $p['nume'] !== '' ? $p['nume'] : 'Cazo'], 'scop' => 'rezervari', 'mod' => $mod,
            'pret' => ['sursa' => 'site', 'include' => null], 'versiune_api' => 'v1', 'webhook' => ['adresa' => null, 'stare' => 'activ', 'ultima_livrare_la' => null],
            'cheie' => ['inlocuieste' => null]];
    }

    /** GET /proprietate în forma EasyCheck: datele firmei rămân în `date_legale` (operatorul are doar clasificările). */
    public function proprietate(): array
    {
        $p = $this->depozit->proprietate();
        $r = $this->depozit->reguli();
        return [
            'id' => $this->depozit->idProprietate(), 'nume' => $p['nume'], 'tip' => $p['tip'], 'descriere' => $p['descriere'],
            'adresa' => $p['adresa'], 'coordonate' => $p['coordonate'],
            'ore' => $p['ore'] + ['setate_de_gazda' => true],
            'reguli_casa' => $p['reguli_casa'], 'anulare_zile' => $r['anulare_zile'],
            'contact' => ['email' => $p['contact']['email'] !== '' ? $p['contact']['email'] : null, 'telefon' => $p['contact']['telefon'] !== '' ? $p['contact']['telefon'] : null],
            'operator' => ['clasificari' => $p['clasificari']],
            'linkuri_platforme' => $p['linkuri_platforme'], 'note_platforme' => $p['note_platforme'], 'spatii_comune' => $p['spatii_comune'],
            'poze' => $p['poze'],
        ];
    }

    public function camere(): array
    {
        $rez = [];
        foreach ($this->depozit->camere() as $c) {
            $rez[] = ['id' => $c['id'], 'titlu' => $c['titlu'], 'unitati' => $c['unitati'], 'oaspeti_max' => $c['oaspeti_max'],
                'spatii' => is_array($c['extra']['spatii'] ?? null) ? $c['extra']['spatii'] : null,
                'poze' => array_values(array_filter((array) ($c['extra']['poze'] ?? []), 'is_array'))];
        }
        return ['camere' => $rez];
    }

    public function disponibilitate(string $de, string $pana): array
    {
        $camere = [];
        $d = $this->depozit->disponibilitate($de, $pana);
        foreach ($this->depozit->camere() as $c) {
            $nopti = [];
            foreach (Depozit::zile($de, $pana) as $zi) {
                $x = $d[$c['id']][$zi] ?? ['libere' => $c['unitati'], 'inchis' => false];
                $nopti[] = ['zi' => $zi, 'libere' => $x['libere'], 'inchis' => $x['inchis']];
            }
            $camere[] = ['id' => $c['id'], 'unitati' => $c['unitati'], 'nopti' => $nopti];
        }
        return ['proprietate' => $this->depozit->idProprietate(), 'de' => $de, 'pana' => $pana, 'camere' => $camere];
    }

    /** Planul de bază al site-ului, cu avansul și anularea din regula proprietății. */
    public function plan(): array
    {
        $r = $this->depozit->reguli();
        return ['id' => self::PLAN, 'titlu' => 'Standard', 'fel' => 'propriu', 'tip' => null, 'prag_nopti' => null, 'mod' => 'camera', 'din_plan' => null,
            'regula' => null, 'rotunjire' => null, 'avans_procent' => $r['avans_procent'], 'rest_plata' => 'la_sosire', 'anulare_zile' => $r['anulare_zile']];
    }

    /**
     * Planurile de acum: Standard și, pentru fiecare reducere pornită, tariful ei calculat din Standard, cu
     * aceeași regulă de plată și de anulare. Cheia internă `procent` (reducerea) lipsește din răspunsul API.
     * @return list<array>
     */
    public function planuri(): array
    {
        $baza = $this->plan();
        $red = $this->depozit->reduceri();
        $rez = [$baza];
        $calculat = static fn (string $id, string $titlu, int $procent): array => ['id' => $id, 'titlu' => $titlu, 'fel' => 'calculat', 'din_plan' => self::PLAN,
            'regula' => ['tip' => 'procent', 'procent_sutimi' => -100 * $procent], 'procent' => $procent] + $baza;
        if ($red['lung'] !== null) {
            $rez[] = ['tip' => 'sejur_lung', 'prag_nopti' => $red['lung']['nopti']] + $calculat(self::PLAN_LUNG, 'Sejur lung', $red['lung']['procent']);
        }
        if ($red['timp'] !== null) {
            $rez[] = ['tip' => 'din_timp', 'prag_zile' => $red['timp']['zile']] + $calculat(self::PLAN_TIMP, 'Rezervare din timp', $red['timp']['procent']);
        }
        return $rez;
    }

    public function preturi(string $de, string $pana): array
    {
        $r = $this->depozit->reguli();
        $inchise = $this->depozit->inchise($de, Depozit::ziUrmatoare($pana));
        $planuri = $this->planuri();
        $camere = [];
        foreach ($this->depozit->camere() as $c) {
            $grile = [];
            foreach ($planuri as $p) {
                // Sejurul lung: sejurul minim e pragul lui. Rezervarea din timp: sosirea se deschide abia la azi + N zile
                // (ziua României), deci grila se schimbă zilnic (oglinda o recitește la 15 minute).
                $min = $p['tip'] === 'sejur_lung' ? max($r['sejur_minim'], (int) $p['prag_nopti']) : $r['sejur_minim'];
                $primaSosire = $p['tip'] === 'din_timp' ? gmdate('Y-m-d', strtotime($this->app->azi() . ' 00:00:00 UTC') + 86400 * (int) $p['prag_zile']) : null;
                $zile = [];
                foreach (Depozit::zile($de, $pana, true) as $zi) {
                    $pret = Depozit::pretNoapte($c, $zi);
                    $zile[] = ['zi' => $zi, 'pret' => $pret !== null && isset($p['procent']) ? Depozit::pretRedus($pret, $p['procent']) : $pret, 'sejur_minim' => $min,
                        'sejur_minim_prin' => $min, 'sejur_maxim' => 0, 'sosire_inchisa' => $primaSosire !== null && $zi < $primaSosire, 'plecare_inchisa' => false,
                        'inchis' => isset($inchise['*'][$zi]) || isset($inchise[$c['id']][$zi])];
                }
                $grile[] = ['plan' => $p['id'], 'pe_persoana' => null, 'zile' => $zile];
            }
            $camere[] = ['id' => $c['id'], 'titlu' => $c['titlu'], 'unitati' => $c['unitati'], 'moneda' => 'RON', 'planuri' => $grile];
        }
        $planuri = array_map(static fn (array $p): array => array_diff_key($p, ['procent' => true]), $planuri);
        return ['proprietate' => $this->depozit->idProprietate(), 'de' => $de, 'pana' => $pana, 'moneda' => 'RON', 'planuri' => $planuri, 'camere' => $camere,
            'taxe_suplimentare' => [], 'taxa_locala' => ['cota_tva_cazare_sutimi' => 0, 'incasare' => 'online', 'moment' => null, 'ani' => []],
            'anulare_zile' => $r['anulare_zile'], 'avans_implicit' => ['avans_procent' => $r['avans_procent'], 'rest_plata' => 'la_sosire', 'anulare_zile' => $r['anulare_zile']]];
    }

    private function fereastra(array $q, \Closure $f, array $a): RaspunsEc
    {
        $de = $q['de'] ?? null;
        $pana = $q['pana'] ?? null;
        $n = is_string($de) && is_string($pana) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $de) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $pana)
            ? (int) round((strtotime($pana . ' UTC') - strtotime($de . ' UTC')) / 86400) : 0;
        if ($n < 1 || $n > 366) {
            return self::eroare(422, 'validare', ['campuri' => ['de', 'pana']]);
        }
        return $this->cuEtag($f($de, $pana), $a);
    }

    /** 200 cu ETag sau 304 la un If-None-Match potrivit (oglinda își păstrează atunci corpul). */
    private function cuEtag(array $corp, array $a): RaspunsEc
    {
        $json = self::json($corp);
        $etag = '"' . substr(hash('sha256', $json), 0, 32) . '"';
        if (in_array($etag, array_map('trim', explode(',', $a['if-none-match'] ?? '')), true)) {
            return new RaspunsEc(304, self::antete() + ['etag' => $etag], '');
        }
        return new RaspunsEc(200, self::antete() + ['etag' => $etag], $json);
    }

    // ---- rezervările ----

    private function idempotent(array $a, ?string $corp, \Closure $f): RaspunsEc
    {
        $cheie = (string) ($a['idempotency-key'] ?? '');
        if ($cheie === '') {
            return self::eroare(400, 'idempotency_lipsa');
        }
        $k = hash('sha256', $cheie);
        $h = $corp === null ? '' : hash('sha256', $corp);
        $b = $this->app->baza();
        $vechi = $b->rand('SELECT * FROM {local_idem} WHERE cheie = ?', [$k]);
        if ($vechi !== null) {
            if ($corp !== null && !hash_equals((string) $vechi['hash_corp'], $h)) {
                return self::eroare(409, 'idempotency_conflict');
            }
            return new RaspunsEc((int) $vechi['status'], self::antete(), (string) ($vechi['corp'] ?? ''));
        }
        $r = $f();
        try {
            $b->insereaza('local_idem', ['cheie' => $k, 'hash_corp' => $h, 'status' => $r->status, 'corp' => $r->corp, 'la' => $this->app->ceas()->moment()]);
        } catch (\PDOException $e) {
            if (!$b->esteDuplicat($e)) {
                throw $e;
            }
        }
        return $r;
    }

    /**
     * Corpul comun al rezervării și al cererii: camerele (fiecare o unitate), sejurul, totalul în lei. Întoarce corpul
     * verificat sau un răspuns de eroare.
     */
    private function corpSejur(?string $corp): array|RaspunsEc
    {
        $j = json_decode((string) $corp, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($j) || array_is_list($j)) {
            return self::eroare(400, 'json_invalid');
        }
        $zi = '~^\d{4}-\d{2}-\d{2}$~';
        if (!is_array($j['camere'] ?? null) || $j['camere'] === [] || count($j['camere']) > 10 || !is_string($j['sosire'] ?? null) || !is_string($j['plecare'] ?? null)
            || !preg_match($zi, $j['sosire']) || !preg_match($zi, $j['plecare']) || $j['sosire'] >= $j['plecare'] || !is_int($j['total'] ?? null) || $j['total'] < 0
            || ($j['moneda'] ?? null) !== 'RON') {
            return self::eroare(422, 'validare', ['campuri' => ['corp']]);
        }
        // Tarifele reducerilor trec și după ce gazda le-a oprit: o comandă plătită între timp nu se mai refuză.
        if (isset($j['plan']) && !in_array($j['plan'], [self::PLAN, self::PLAN_LUNG, self::PLAN_TIMP], true)) {
            return self::eroare(422, 'validare', ['campuri' => ['plan']]);
        }
        $camere = [];
        foreach (array_values($j['camere']) as $i => $c) {
            $cam = is_array($c) ? $this->depozit->camera((string) ($c['camera'] ?? '')) : null;
            if ($cam === null || !$cam['activa'] || !is_int($c['adulti'] ?? null) || $c['adulti'] < 1) {
                return self::eroare(422, 'validare', ['campuri' => ["camere[$i]"]]);
            }
            $copii = is_int($c['copii'] ?? null) ? max(0, $c['copii']) : 0;
            if ($c['adulti'] + $copii > $cam['oaspeti_max']) {
                return self::eroare(422, 'restrictie', ['motiv' => 'oaspeti_max', 'valoare' => $cam['oaspeti_max'], 'index' => $i]);
            }
            $camere[] = ['camera' => $cam['id'], 'adulti' => $c['adulti'], 'copii' => $copii];
        }
        $j['camere'] = $camere;
        return $j;
    }

    private function rezervareNoua(?string $corp): RaspunsEc
    {
        $j = $this->corpSejur($corp);
        if ($j instanceof RaspunsEc) {
            return $j;
        }
        $acum = $this->app->ceas()->moment();
        $id = self::id('rez_');
        $pline = $this->depozit->noptiPline($j['sosire'], $j['plecare'], $j['camere'], is_string($j['inlocuieste'] ?? null) ? $j['inlocuieste'] : null);
        $proba = $this->app->stripe()->mod() === 'test';
        $rand = ['id' => $id, 'ref_site' => is_string($j['ref_site'] ?? null) ? mb_substr($j['ref_site'], 0, 32) : null, 'sosire' => $j['sosire'], 'plecare' => $j['plecare'],
            'camere' => self::json($j['camere']), 'total' => $j['total'], 'proba' => $proba ? 1 : 0, 'sursa' => 'site', 'creat_la' => $acum, 'actualizat_la' => $acum];
        if (array_key_exists('plata_asteptata', $j)) {
            $pa = $j['plata_asteptata'];
            $termen = is_array($pa) && is_string($pa['termen'] ?? null) && preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$~', $pa['termen']) ? strtotime($pa['termen']) : false;
            if (!is_array($pa) || array_key_exists('plata', $j) || ($pa['metoda'] ?? null) !== 'transfer' || !is_int($pa['suma'] ?? null) || $pa['suma'] < 1 || $pa['suma'] > $j['total']
                || $termen === false || $termen <= $this->app->ceas()->acum() || !is_string($pa['referinta'] ?? null) || $pa['referinta'] === '' || mb_strlen($pa['referinta']) > 100) {
                return self::eroare(422, 'validare', ['campuri' => ['plata_asteptata']]);
            }
            if ($pline !== []) {
                return self::eroare(409, 'indisponibil', ['zile' => $pline]);
            }
            $plata = ['metoda' => 'transfer', 'suma' => $pa['suma'], 'termen' => $pa['termen'], 'referinta' => $pa['referinta'], 'stare' => 'in_asteptare', 'decisa_la' => null,
                'anuntata_de_oaspete_la' => null];
            $this->app->baza()->insereaza('local_rezervari', $rand + ['stare' => 'in_asteptarea_platii', 'plata_asteptata' => self::json($plata)]);
            $this->dupaScriere();
            return self::ok(['id' => $id, 'stare' => 'in_asteptarea_platii', 'plata_asteptata' => $plata, 'proba' => $proba], 201);
        }
        if (!is_array($j['plata'] ?? null) || !is_int($j['plata']['suma'] ?? null) || $j['plata']['suma'] < 0 || $j['plata']['suma'] > $j['total']) {
            return self::eroare(422, 'validare', ['campuri' => ['plata']]);
        }
        $avans = (int) round($j['total'] * $this->depozit->reguli()['avans_procent'] / 100);
        if ($j['plata']['suma'] < min($avans, intdiv($avans + 50, 100) * 100)) {
            return self::eroare(422, 'avans_insuficient', ['avans' => $avans, 'procent' => $this->depozit->reguli()['avans_procent'], 'moneda' => 'RON']);
        }
        $this->app->baza()->tranzactie(function () use ($rand, $j): void {
            // Schimbarea datelor (118): rezervarea nouă o înlocuiește pe cea veche, care se anulează odată cu ea.
            if (is_string($j['inlocuieste'] ?? null)) {
                $this->app->baza()->executa("UPDATE {local_rezervari} SET stare = 'anulata', actualizat_la = ? WHERE id = ? AND stare <> 'anulata'", [$rand['actualizat_la'], $j['inlocuieste']]);
            }
            $this->app->baza()->insereaza('local_rezervari', $rand + ['stare' => 'confirmata', 'plata' => self::json(['suma' => $j['plata']['suma'], 'metoda' => (string) ($j['plata']['metoda'] ?? 'card')])]);
        });
        $this->dupaScriere();
        return self::ok(['id' => $id, 'stare' => 'confirmata', 'suprapunere' => $pline !== [], 'proba' => $proba], 201);
    }

    private function cerereNoua(?string $corp): RaspunsEc
    {
        $j = $this->corpSejur($corp);
        if ($j instanceof RaspunsEc) {
            return $j;
        }
        if (isset($j['plata'])) {
            return self::eroare(422, 'validare', ['campuri' => ['plata']]);
        }
        $pline = $this->depozit->noptiPline($j['sosire'], $j['plecare'], $j['camere']);
        if ($pline !== []) {
            return self::eroare(409, 'indisponibil', ['zile' => $pline]);
        }
        $id = self::id('cer_');
        // termenul ales de gazdă acum, fixat pe cerere; o schimbare a setării nu atinge cererile în curs.
        $expira = $this->app->ceas()->moment($this->depozit->reguli()['termen_cerere_ore'] * 3600);
        $proba = $this->app->stripe()->mod() === 'test';
        $this->app->baza()->insereaza('local_cereri', ['id' => $id, 'ref_site' => is_string($j['ref_site'] ?? null) ? mb_substr($j['ref_site'], 0, 32) : null,
            'stare' => 'in_asteptare', 'sosire' => $j['sosire'], 'plecare' => $j['plecare'], 'camere' => self::json($j['camere']), 'total' => $j['total'],
            'expira_la' => $expira, 'proba' => $proba ? 1 : 0, 'creat_la' => $this->app->ceas()->moment()]);
        return self::ok(['id' => $id, 'stare' => 'in_asteptare', 'expira_la' => self::iso($expira), 'proba' => $proba], 201);
    }

    private function cerere(string $id): RaspunsEc
    {
        $c = $this->app->baza()->rand('SELECT * FROM {local_cereri} WHERE id = ?', [$id]);
        if ($c === null) {
            return self::eroare(404, 'negasit');
        }
        $stare = (string) $c['stare'];
        if ($stare === 'in_asteptare' && (string) $c['expira_la'] <= $this->app->ceas()->moment()) {
            $stare = 'expirata';
        }
        return self::ok(['id' => $id, 'stare' => $stare, 'expira_la' => self::iso((string) $c['expira_la']), 'rezervare' => $c['rezervare'], 'sosire' => $c['sosire'], 'plecare' => $c['plecare'],
            'camere' => json_decode((string) $c['camere'], true), 'proba' => (int) $c['proba'] === 1]);
    }

    /** Răspunsul gazdei la o cerere, din panou: acceptarea creează rezervarea, cu nopțile verificate din nou. */
    private function decideCererea(string $id, bool $accepta): RaspunsEc
    {
        $b = $this->app->baza();
        $c = $b->rand('SELECT * FROM {local_cereri} WHERE id = ?', [$id]);
        if ($c === null) {
            return self::eroare(404, 'negasit');
        }
        if ($c['stare'] !== 'in_asteptare' || (string) $c['expira_la'] <= $this->app->ceas()->moment()) {
            return self::eroare(409, 'stare_invalida');
        }
        $camere = (array) json_decode((string) $c['camere'], true);
        if ($accepta && ($pline = $this->depozit->noptiPline((string) $c['sosire'], (string) $c['plecare'], $camere)) !== []) {
            return self::eroare(409, 'indisponibil', ['zile' => $pline]);
        }
        $acum = $this->app->ceas()->moment();
        $rez = $accepta ? self::id('rez_') : null;
        $b->tranzactie(function () use ($b, $c, $id, $accepta, $rez, $acum): void {
            if ($accepta) {
                $b->insereaza('local_rezervari', ['id' => $rez, 'ref_site' => $c['ref_site'], 'stare' => 'confirmata', 'sosire' => $c['sosire'], 'plecare' => $c['plecare'],
                    'camere' => $c['camere'], 'total' => (int) $c['total'], 'plata' => null, 'proba' => (int) $c['proba'], 'sursa' => 'site', 'creat_la' => $acum, 'actualizat_la' => $acum]);
            }
            $b->executa("UPDATE {local_cereri} SET stare = ?, rezervare = ?, decisa_la = ? WHERE id = ? AND stare = 'in_asteptare'", [$accepta ? 'acceptata' : 'refuzata', $rez, $acum, $id]);
        });
        $this->dupaScriere();
        return self::ok(['id' => $id, 'stare' => $accepta ? 'acceptata' : 'refuzata', 'rezervare' => $rez, 'proba' => (int) $c['proba'] === 1]);
    }

    /** O rezervare, cu termenul transferului trecut văzut pe loc (ca EasyCheck): anulată, plata expirată. */
    private function randRezervare(string $id): ?array
    {
        $r = $this->app->baza()->rand('SELECT * FROM {local_rezervari} WHERE id = ?', [$id]);
        if ($r === null) {
            return null;
        }
        $pa = json_decode((string) ($r['plata_asteptata'] ?? ''), true);
        if (is_array($pa) && ($pa['stare'] ?? null) === 'in_asteptare' && is_string($pa['termen'] ?? null) && $pa['termen'] <= $this->momentIso()) {
            $pa['stare'] = 'expirata';
            $pa['decisa_la'] = $this->momentIso();
            $this->app->baza()->executa("UPDATE {local_rezervari} SET stare = 'anulata', plata_asteptata = ?, actualizat_la = ? WHERE id = ?", [self::json($pa), $this->app->ceas()->moment(), $id]);
            $this->dupaScriere();
            return $this->randRezervare($id);
        }
        return $r;
    }

    private static function forma(array $r): array
    {
        $pa = json_decode((string) ($r['plata_asteptata'] ?? ''), true);
        return ['id' => $r['id'], 'stare' => $r['stare'], 'ref_site' => $r['ref_site'], 'sosire' => $r['sosire'], 'plecare' => $r['plecare'],
            'camere' => array_map(static fn (array $c): array => ['camera' => $c['camera'], 'unitati' => 1], array_values(array_filter((array) json_decode((string) $r['camere'], true), 'is_array'))),
            'actualizat_la' => self::iso((string) $r['actualizat_la']), 'proba' => (int) $r['proba'] === 1] + (is_array($pa) ? ['plata_asteptata' => $pa] : []);
    }

    private function rezervare(string $id): RaspunsEc
    {
        $r = $this->randRezervare($id);
        return $r === null ? self::eroare(404, 'negasit') : self::ok(self::forma($r));
    }

    /** GET /rezervari?actualizate_de_la=&dupa=: câte 100, în ordinea schimbărilor. */
    private function listaRezervari(array $q): RaspunsEc
    {
        $deLa = is_string($q['actualizate_de_la'] ?? null) && preg_match('~^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})Z$~', $q['actualizate_de_la'], $x) ? $x[1] . ' ' . $x[2] : '1970-01-01 00:00:00';
        $dupa = is_string($q['dupa'] ?? null) && preg_match('~^rez_[0-9A-Za-z]{1,60}$~', $q['dupa']) ? $q['dupa'] : '';
        $randuri = $this->app->baza()->toate('SELECT * FROM {local_rezervari} WHERE actualizat_la >= ? AND id > ? ORDER BY id LIMIT 101', [$deLa, $dupa]);
        $mai = count($randuri) > 100;
        $randuri = array_slice($randuri, 0, 100);
        return self::ok(['rezervari' => array_map(self::forma(...), $randuri), 'dupa' => $mai ? (string) end($randuri)['id'] : null]);
    }

    private function anuleaza(string $id): RaspunsEc
    {
        $r = $this->randRezervare($id);
        if ($r === null) {
            return self::eroare(404, 'negasit');
        }
        if ($r['stare'] === 'anulata') {
            return self::eroare(409, 'stare_invalida');
        }
        $pa = json_decode((string) ($r['plata_asteptata'] ?? ''), true);
        if (is_array($pa) && ($pa['stare'] ?? null) === 'in_asteptare') {
            $pa['stare'] = 'anulata';
            $pa['decisa_la'] = $this->momentIso();
        }
        $this->app->baza()->executa("UPDATE {local_rezervari} SET stare = 'anulata', plata_asteptata = ?, actualizat_la = ? WHERE id = ?",
            [is_array($pa) ? self::json($pa) : null, $this->app->ceas()->moment(), $id]);
        $this->dupaScriere();
        return self::ok(['id' => $id, 'stare' => 'anulata']);
    }

    /** „Am primit banii” sau „Banii nu au venit”, din panoul site-ului. */
    private function decidePlata(string $id, bool $confirma): RaspunsEc
    {
        $r = $this->randRezervare($id);
        $pa = $r === null ? null : json_decode((string) ($r['plata_asteptata'] ?? ''), true);
        if (!is_array($pa)) {
            return self::eroare(404, 'negasit');
        }
        if (($pa['stare'] ?? null) !== 'in_asteptare') {
            return self::eroare(409, 'stare_invalida');
        }
        $pa['stare'] = $confirma ? 'confirmata' : 'neprimita';
        $pa['decisa_la'] = $this->momentIso();
        $this->app->baza()->executa('UPDATE {local_rezervari} SET stare = ?, plata_asteptata = ?, actualizat_la = ? WHERE id = ?',
            [$confirma ? 'confirmata' : 'anulata', self::json($pa), $this->app->ceas()->moment(), $id]);
        $this->dupaScriere();
        return $this->rezervare($id);
    }

    private function transferAnuntat(string $id): RaspunsEc
    {
        $r = $this->randRezervare($id);
        $pa = $r === null ? null : json_decode((string) ($r['plata_asteptata'] ?? ''), true);
        if (!is_array($pa)) {
            return self::eroare(404, 'negasit');
        }
        if (($pa['stare'] ?? null) !== 'in_asteptare') {
            return self::eroare(409, 'stare_invalida');
        }
        $pa['anuntata_de_oaspete_la'] ??= $this->momentIso();
        $pa['termen'] = null;
        $this->app->baza()->executa('UPDATE {local_rezervari} SET plata_asteptata = ?, actualizat_la = ? WHERE id = ?', [self::json($pa), $this->app->ceas()->moment(), $id]);
        return self::ok(['id' => $id, 'stare' => 'in_asteptarea_platii', 'plata_asteptata' => $pa, 'proba' => (int) $r['proba'] === 1]);
    }

    /**
     * „Prelungiți termenul” din panoul site-ului (ca `apitr_prelungeste_gazda` din EasyCheck): termenul în
     * vigoare crește cu 1, 2, 3 sau 7 zile, deci o prelungire repetată pornește de la termenul curent. Refuzată (409
     * `stare_invalida`) după decizie, după termen (rezervarea expiră la citire) și după „Am plătit” (fără termen).
     */
    private function prelungesteTermen(string $id, ?string $corp): RaspunsEc
    {
        $j = json_decode((string) $corp, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($j) || !in_array($j['zile'] ?? null, self::PRELUNGIRI_ZILE, true)) {
            return self::eroare(422, 'validare', ['campuri' => ['zile']]);
        }
        $r = $this->randRezervare($id);
        $pa = $r === null ? null : json_decode((string) ($r['plata_asteptata'] ?? ''), true);
        if (!is_array($pa)) {
            return self::eroare(404, 'negasit');
        }
        $vechi = $pa['termen'] ?? null;
        if (($pa['stare'] ?? null) !== 'in_asteptare' || !is_string($vechi) || strtotime($vechi) === false) {
            return self::eroare(409, 'stare_invalida');
        }
        $pa['termen'] = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($vechi) + $j['zile'] * 86400);
        $n = $this->app->baza()->executa("UPDATE {local_rezervari} SET plata_asteptata = ?, actualizat_la = ? WHERE id = ? AND stare = 'in_asteptarea_platii'",
            [self::json($pa), $this->app->ceas()->moment(), $id])->rowCount();
        if ($n !== 1) {
            return self::eroare(409, 'stare_invalida');
        }
        return self::ok(['id' => $id, 'stare' => 'in_asteptarea_platii', 'plata_asteptata' => $pa, 'proba' => (int) $r['proba'] === 1]);
    }

    /** Ștergerea datelor oaspetelui: site-ul nu ține date personale în `local_rezervari`, deci nu păstrează nimic. */
    private function anonimizeaza(string $id): RaspunsEc
    {
        if ($this->app->baza()->valoare('SELECT 1 FROM {local_rezervari} WHERE id = ?', [$id]) === null) {
            return self::eroare(404, 'negasit');
        }
        return self::ok(['id' => $id, 'anonimizata_la' => $this->momentIso(), 'rezervari' => [$id], 'sterse' => [], 'pastrate' => []]);
    }

    /** O scriere a schimbat nopțile: oglinda disponibilității se recitește la prima ocazie (în proces, fără rețea). */
    private function dupaScriere(): void
    {
        $this->app->localDupaScriere();
    }

    // ---- unelte ----

    private function momentIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $this->app->ceas()->acum());
    }

    /** Momentul din bază (UTC, „AAAA-LL-ZZ HH:MM:SS”) în forma API-ului. */
    public static function iso(string $moment): string
    {
        return str_replace(' ', 'T', substr($moment, 0, 19)) . 'Z';
    }

    public static function id(string $prefix): string
    {
        $a = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $s = '';
        for ($i = 0; $i < 24; $i++) {
            $s .= $a[random_int(0, 61)];
        }
        return $prefix . $s;
    }

    private static function antete(): array
    {
        return ['x-request-id' => self::id('req_'), 'content-type' => 'application/json'];
    }

    private static function ok(array $corp, int $status = 200): RaspunsEc
    {
        return new RaspunsEc($status, self::antete(), self::json($corp));
    }

    private static function eroare(int $status, string $cod, ?array $detalii = null): RaspunsEc
    {
        $e = ['cod' => $cod, 'mesaj' => $cod];
        if ($detalii !== null) {
            $e['detalii'] = $detalii;
        }
        $a = self::antete();
        $e['request_id'] = $a['x-request-id'];
        return new RaspunsEc($status, $a, self::json(['eroare' => $e]));
    }

    public static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
