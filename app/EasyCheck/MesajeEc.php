<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

use Cazo\Baza\Baza;
use Cazo\Email\SabloaneGazda;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Mesajele către oaspeți scrise în EasyCheck (etapa 4 din „EasyCheck întâi”, contract-mesaje.md): oglinda lui
 * `GET /mesaje`, proiecția ei în `ec_mesaje` cu gărzile site-ului, ordinea surselor la trimitere și importul paragrafelor.
 *
 *  - **Când**: doar cu `functii.mesaje` = true (StareSite::functie), capabilitatea `mesaje` declarată, cheia valabilă și
 *    modul cu cheie. Fără ele (un EasyCheck vechi, funcția oprită, modul local, `stare_easycheck => false`), site-ul rămâne
 *    pe drumul de azi: textul programului, cu paragraful gazdei scris în panou.
 *  - **Oglinda**: corpul brut stă în `ec_resurse` (rândul `mesaje`, cu ETag și 304), ca celelalte resurse, dar se citește
 *    doar de aici (sarcina `ec:mesaje`), nu prin `Oglinda::RESURSE`: sincronizarea generală, instalatorul și transportul local
 *    nu trebuie să ceară `/mesaje` unui EasyCheck fără funcție. Citirea: la anunțul `mesaje.schimbate`, când `PUT /site/stare`
 *    întoarce alt `mesaje_versiune` decât cel din oglindă și o dată pe zi. EasyCheck căzut: rămâne ultima copie.
 *  - **Proiecția**: un rând pe mesaj și limbă, cu verdictul gărzilor (`SabloaneGazda::valideaza`, domeniul site-ului); un
 *    șablon respins nu pleacă (textul standard ia locul lui), iar starea site-ului poartă alerta `mesaj_invalid`.
 *  - **Importul**: la prima aprindere, paragrafele nevide din `sabloane_email` urcă o dată, în loturi de cel mult 10,
 *    ultimul cu `incheiat: true`; un lot are corpul și cheia de idempotență fixate până trece. Pe cheia de probă EasyCheck nu
 *    scrie nimic, deci paragrafele rămân în e-mailurile site-ului.
 */
final class MesajeEc
{
    public const RESURSA = 'mesaje';
    public const CALE = '/mesaje';
    public const JOB = 'ec:mesaje';
    public const SETARE_IMPORT = 'ec.mesaje.import';
    /** Plasa de siguranță: o citire pe zi. */
    public const ZILNIC = 86400;
    public const LOT = 10;
    public const PARAGRAF_MAXIM = 600;
    /** După o citire sau un import nereușit. */
    public const RELUARE = 300;

    public function __construct(private Aplicatie $app)
    {
    }

    private function baza(): Baza
    {
        return $this->app->baza();
    }

    /** Funcția e aprinsă pentru acest site: modul cu cheie, cheia valabilă, `functii.mesaje` = true în EasyCheck. */
    public function activa(): bool
    {
        try {
            $s = $this->app->stareSite();
            return !$this->app->modLocal() && $s->pornit() && in_array('mesaje', StareSite::CAPABILITATI, true) && $this->app->secrete()->citeste('ec_cheie') !== null
                && $this->app->ecLicenta()->stare()['valida'] && $s->functie('mesaje');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Domeniul site-ului (gazda adresei publice, fără `www.`): singurul permis în textul gazdei (garda `link`). @return list<string> */
    public function domenii(): array
    {
        $h = strtolower((string) parse_url($this->app->config()->origine(), PHP_URL_HOST));
        $h = (string) preg_replace('~^www\.~', '', $h);
        return $h !== '' && $h !== 'localhost' && !filter_var($h, FILTER_VALIDATE_IP) ? [$h] : [];
    }

    /** Rândul oglinzii (`ec_resurse`), sau null înainte de prima citire. */
    public function rand(): ?array
    {
        return $this->baza()->rand('SELECT * FROM {ec_resurse} WHERE resursa = ?', [self::RESURSA]);
    }

    /** Oglinda a fost citită cu succes cel puțin o dată. */
    public function citita(): bool
    {
        return ($this->rand()['corp'] ?? null) !== null;
    }

    /** Versiunea șabloanelor din oglindă (0 înainte de prima citire). */
    public function versiune(): int
    {
        $c = json_decode((string) ($this->rand()['corp'] ?? ''), true);
        return is_array($c) && is_int($c['versiune'] ?? null) ? $c['versiune'] : 0;
    }

    /**
     * Șablonul gazdei pentru (mesaj, limbă), valid acum (gărzile se reiau la trimitere, cu domeniul de acum), sau null: fără
     * funcție, fără șablon sau cu șablonul respins, mesajul pleacă cu textul standard.
     * @return array{subiect:string, corp:string, versiune:int, limba:string}|null
     */
    public function sablon(string $cheie, string $limba): ?array
    {
        if (!isset(SabloaneGazda::CATALOG[$cheie]) || !$this->activa() || !$this->citita()) {
            return null;
        }
        try {
            $r = $this->baza()->rand('SELECT * FROM {ec_mesaje} WHERE cheie = ? AND limba = ?', [$cheie, $limba]);
        } catch (\Throwable) {
            return null;
        }
        if ($r === null || (int) $r['valid'] !== 1 || SabloaneGazda::valideaza($cheie, (string) $r['subiect'], (string) $r['corp'], $this->domenii()) !== []) {
            return null;
        }
        return ['subiect' => (string) $r['subiect'], 'corp' => (string) $r['corp'], 'versiune' => (int) $r['versiune'], 'limba' => $limba];
    }

    /** Gazda a rescris în EasyCheck cel puțin un mesaj (trecerea pe datele proprii o avertizează). */
    public function rescrise(): bool
    {
        try {
            return $this->activa() && (int) $this->baza()->valoare('SELECT COUNT(*) FROM {ec_mesaje}') > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Rândul proiecției (și respins), pentru previzualizarea din panou. */
    public function proiectie(string $cheie, string $limba): ?array
    {
        try {
            return $this->baza()->rand('SELECT * FROM {ec_mesaje} WHERE cheie = ? AND limba = ?', [$cheie, $limba]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Paragraful gazdei scris pe site, cât încă pleacă (ordinea surselor din contract): fără funcție, ca azi; cu funcția, până
     * la încheierea importului pe cheia live (pe cheia de probă EasyCheck nu păstrează paragrafele) și, după, doar
     * paragrafele pe care EasyCheck nu le-a primit (respinse de gărzi, păstrate de site).
     */
    public function notaInca(string $cheie, string $limba): bool
    {
        if (!$this->activa()) {
            return true;
        }
        $i = $this->stareImport();
        if (!is_string($i['incheiat_la'] ?? null) || ($i['proba'] ?? false) === true) {
            return true;
        }
        return in_array($cheie . '|' . $limba, (array) ($i['pastrate'] ?? []), true);
    }

    /** Gărzile șabloanelor respinse acum (alerta `mesaj_invalid`, `detalii.motive`), cu paragrafele respinse la import. @return list<string> */
    public function motiveInvalide(): array
    {
        if (!$this->activa()) {
            return [];
        }
        $m = [];
        try {
            foreach ($this->baza()->toate('SELECT garda FROM {ec_mesaje} WHERE valid = 0') as $r) {
                array_push($m, ...explode(',', (string) ($r['garda'] ?? 'text_simplu')));
            }
        } catch (\Throwable) {
        }
        foreach ((array) ($this->stareImport()['respinse'] ?? []) as $g) {
            $m[] = (string) $g;
        }
        return array_values(array_unique(array_filter($m, static fn (string $x): bool => in_array($x, SabloaneGazda::GARZI, true))));
    }

    /** Răspunsul lui `PUT /site/stare`: un `mesaje_versiune` diferit de oglindă cere o citire (plasa de siguranță). */
    public function versiuneStare(mixed $v): void
    {
        if (is_int($v) && $v !== $this->versiune() && $this->activa()) {
            $this->marcheazaInvechit();
        }
    }

    /** Anunțul `mesaje.schimbate` (sau plasa): oglinda se citește la rularea următoare a sarcinii. */
    public function marcheazaInvechit(): void
    {
        $this->baza()->upsert('ec_resurse', ['resursa' => self::RESURSA, 'invechit' => 1, 'versiune' => 0], ['resursa'], ['invechit']);
        try {
            $this->app->coada()->pune(self::JOB, [], self::JOB);
        } catch (\Throwable) {
        }
    }

    /** Sarcina cronului și a cozii (`ec:mesaje`): citirea scadentă, apoi importul. Întoarce rezumatul pentru panou. */
    public function sarcina(): string
    {
        if (!$this->activa()) {
            return 'oprită: EasyCheck fără mesajele site-ului';
        }
        $r = [];
        if ($this->deCitit()) {
            $r[] = 'citire: ' . $this->citeste();
        }
        $i = $this->importa();
        if ($i !== '') {
            $r[] = 'import: ' . $i;
        }
        return $r === [] ? 'la zi' : implode(', ', $r);
    }

    /** Citirea e scadentă: niciodată citită, învechită (anunț, plasă) sau de o zi; după o eroare, după pauză. */
    public function deCitit(): bool
    {
        $r = $this->rand();
        if ($r === null) {
            return true;
        }
        if ($r['reincercare_la'] !== null && (string) $r['reincercare_la'] > $this->app->ceas()->moment()) {
            return false;
        }
        return $r['corp'] === null || (int) $r['invechit'] === 1 || $r['verificat_la'] === null
            || Ceas::dinMoment((string) $r['verificat_la']) + self::ZILNIC <= $this->app->ceas()->acum();
    }

    /**
     * `GET /mesaje` cu If-None-Match; la un corp nou, proiecția se reface într-o tranzacție. Întoarce „neschimbat”,
     * „schimbat” sau „eroare” (ultima copie rămâne).
     */
    public function citeste(): string
    {
        $b = $this->baza();
        $b->upsert('ec_resurse', ['resursa' => self::RESURSA, 'invechit' => 0, 'versiune' => 0], ['resursa'], []);
        $rand = (array) $this->rand();
        $acum = $this->app->ceas()->moment();
        if (!$this->app->ecBuget()->permiteSincronizare()) {
            return 'amânată';
        }
        try {
            $r = $this->app->ecClient()->citeste(self::CALE, [], $rand['corp'] !== null ? $rand['etag'] : null);
            if ($r->status === 304) {
                $b->executa('UPDATE {ec_resurse} SET verificat_la = ?, invechit = 0, ultima_eroare = NULL, esecuri = 0, reincercare_la = NULL WHERE resursa = ?', [$acum, self::RESURSA]);
                return 'neschimbat';
            }
            $corp = $r->json();
            if ($corp === null || !is_int($corp['versiune'] ?? null) || !is_array($corp['mesaje'] ?? null) || !array_is_list($corp['mesaje'])) {
                throw new \UnexpectedValueException('Corpul /mesaje nu are forma contractului.');
            }
        } catch (\Throwable $e) {
            $esecuri = (int) ($rand['esecuri'] ?? 0) + 1;
            $b->executa('UPDATE {ec_resurse} SET ultima_eroare = ?, esecuri = ?, reincercare_la = ? WHERE resursa = ?',
                [mb_substr(\Cazo\Nucleu\Jurnal::mascheaza(Oglinda::descrie($e)), 0, 480), $esecuri, $this->app->ceas()->moment(min(Oglinda::PAUZA_MAXIMA, 60 * (2 ** min(10, $esecuri - 1)))), self::RESURSA]);
            return 'eroare';
        }
        $hash = Oglinda::amprenta($corp);
        if ($hash === ($rand['hash'] ?? null)) {
            $b->executa('UPDATE {ec_resurse} SET etag = ?, corp = ?, citit_la = ?, verificat_la = ?, invechit = 0, ultima_eroare = NULL, esecuri = 0, reincercare_la = NULL WHERE resursa = ?',
                [$r->etag(), $r->corp, $acum, $acum, self::RESURSA]);
            return 'neschimbat';
        }
        $randuri = $this->proiecteaza($corp);
        $b->tranzactie(function (Baza $b) use ($randuri, $r, $hash, $acum): void {
            $b->executa('DELETE FROM {ec_mesaje}');
            foreach ($randuri as $x) {
                $b->insereaza('ec_mesaje', $x);
            }
            $b->executa('UPDATE {ec_resurse} SET etag = ?, corp = ?, hash = ?, citit_la = ?, verificat_la = ?, schimbat_la = ?, invechit = 0, versiune = versiune + 1,
                ultima_eroare = NULL, esecuri = 0, reincercare_la = NULL WHERE resursa = ?', [$r->etag(), $r->corp, $hash, $acum, $acum, $acum, self::RESURSA]);
        });
        $respinse = array_filter($randuri, static fn (array $x): bool => (int) $x['valid'] === 0);
        if ($respinse !== []) {
            $this->app->jurnal()->avertisment('Mesajele din EasyCheck: șabloane respinse de site, pleacă textul standard', ['garzi' => array_values(array_unique(explode(',', implode(',', array_column($respinse, 'garda')))))]);
        }
        return 'schimbat';
    }

    /**
     * Rândurile proiecției din corpul `GET /mesaje`: doar cheile catalogului și limbile site-ului; fiecare șablon trece
     * gărzile cu domeniul site-ului. @return list<array>
     */
    public function proiecteaza(array $corp): array
    {
        $v = (int) $corp['versiune'];
        $rez = [];
        foreach ($corp['mesaje'] as $m) {
            if (!is_array($m) || !is_string($m['cheie'] ?? null) || !isset(SabloaneGazda::CATALOG[$m['cheie']])) {
                continue;
            }
            foreach (SabloaneGazda::LIMBI as $l) {
                $x = $m[$l] ?? null;
                if (!is_array($x)) {
                    continue;
                }
                $s = is_string($x['subiect'] ?? null) ? $x['subiect'] : '';
                $c = is_string($x['corp'] ?? null) ? $x['corp'] : '';
                $g = SabloaneGazda::valideaza($m['cheie'], $s, $c, $this->domenii());
                $rez[] = ['cheie' => $m['cheie'], 'limba' => $l, 'subiect' => mb_substr($s, 0, 1000), 'corp' => mb_substr($c, 0, 20000), 'versiune' => $v,
                    'valid' => $g === [] ? 1 : 0, 'garda' => $g === [] ? null : implode(',', $g), 'actualizat_la' => is_string($x['actualizat_la'] ?? null) ? mb_substr($x['actualizat_la'], 0, 32) : null];
            }
        }
        return $rez;
    }

    // ---- importul paragrafelor ----

    /** Starea importului pe cheia de acum; o cheie nouă (alt prefix) îl reia (EasyCheck răspunde 409 dacă e deja făcut). */
    public function stareImport(): array
    {
        $s = $this->app->setari()->get(self::SETARE_IMPORT);
        $prefix = Conectare::prefix($this->app->secrete()->citeste('ec_cheie'));
        return is_array($s) && ($s['cheie'] ?? null) === $prefix ? $s : ['cheie' => $prefix];
    }

    /** La trecerea pe datele proprii: importul se reia la revenirea pe EasyCheck (fereastra se redeschide). */
    public function uitaImportul(): void
    {
        $this->app->setari()->pune(self::SETARE_IMPORT, null);
    }

    /** Paragrafele de urcat: notele nevide, pe mesajele catalogului, în ordine. @return list<array{cheie:string, limba:string, text:string}> */
    public function paragrafe(): array
    {
        $r = [];
        foreach ($this->baza()->toate('SELECT cheie, limba, nota FROM {sabloane_email} WHERE nota IS NOT NULL ORDER BY cheie, limba') as $x) {
            $t = trim((string) $x['nota']);
            if ($t !== '' && isset(SabloaneGazda::CATALOG[(string) $x['cheie']]) && in_array($x['limba'], SabloaneGazda::LIMBI, true)) {
                $r[] = ['cheie' => (string) $x['cheie'], 'limba' => (string) $x['limba'], 'text' => mb_substr($t, 0, self::PARAGRAF_MAXIM)];
            }
        }
        return $r;
    }

    /** Un pas al importului (un lot pe rulare). Întoarce rezumatul, sau '' când nu e nimic de făcut. */
    public function importa(): string
    {
        $s = $this->stareImport();
        if (is_string($s['incheiat_la'] ?? null) || !$this->citita()) {
            return '';
        }
        $acum = $this->app->ceas()->acum();
        if (is_int($s['reia_la'] ?? null) && $s['reia_la'] > $acum) {
            return 'așteaptă';
        }
        if (!is_array($s['lot'] ?? null)) {
            $trimise = (array) ($s['trimise'] ?? []);
            $rest = array_values(array_filter($this->paragrafe(), static fn (array $p): bool => !in_array($p['cheie'] . '|' . $p['limba'], $trimise, true)));
            if ($rest === []) {
                // Fără paragrafe (sau toate urcate): importul se încheie fără cerere.
                $s['incheiat_la'] = gmdate('Y-m-d\TH:i:s\Z', $acum);
                $this->tineImport($s);
                return 'nimic de urcat';
            }
            $lot = array_slice($rest, 0, self::LOT);
            $corp = ['paragrafe' => $lot, 'incheiat' => count($rest) <= self::LOT];
            while (strlen(\Cazo\EasyCheck\Client::json($corp)) > Client::CORP_MAXIM && count($corp['paragrafe']) > 1) {
                array_pop($corp['paragrafe']);
                $corp['incheiat'] = false;
            }
            $s['lot'] = ['idem' => 'mesaje-import-' . Client::uuid(), 'corp' => Client::json($corp),
                'chei' => array_map(static fn (array $p): string => $p['cheie'] . '|' . $p['limba'], $corp['paragrafe']), 'incheiat' => $corp['incheiat']];
            $this->tineImport($s);
        }
        $lot = $s['lot'];
        try {
            $r = $this->app->ecClient()->scrie('POST', '/mesaje/import', (string) $lot['corp'], (string) $lot['idem']);
        } catch (EroareApi $e) {
            $det = $e->raspuns->eroare()['detalii'] ?? [];
            if ($e->raspuns->status === 409 && $e->cod === 'stare_invalida' && is_array($det) && ($det['motiv'] ?? null) === 'import_incheiat') {
                // Importul s-a făcut deja (o instalare de dinainte, altă cheie a proprietății): se încheie liniștit.
                unset($s['lot']);
                $s['incheiat_la'] = gmdate('Y-m-d\TH:i:s\Z', $acum);
                $this->tineImport($s);
                return 'încheiat deja în EasyCheck';
            }
            if (in_array($e->raspuns->status, [400, 403, 404, 422], true) || ($e->raspuns->status === 409 && $e->cod !== 'idempotency_conflict')) {
                // O eroare care nu trece cu o reluare: lotul se lasă, iar importul se reia după o zi, de la capăt.
                unset($s['lot']);
                $s['reia_la'] = $acum + self::ZILNIC;
                $this->tineImport($s);
                $this->app->jurnal()->avertisment('Importul paragrafelor în EasyCheck s-a oprit', ['status' => $e->raspuns->status, 'cod' => $e->cod]);
                return 'oprit: ' . $e->raspuns->status;
            }
            $s['reia_la'] = $acum + self::RELUARE;
            $this->tineImport($s);
            return 'de reluat';
        } catch (\Throwable) {
            $s['reia_la'] = $acum + self::RELUARE;
            $this->tineImport($s);
            return 'de reluat';
        }
        $j = $r->json() ?? [];
        $s['trimise'] = array_values(array_unique([...(array) ($s['trimise'] ?? []), ...(array) $lot['chei']]));
        foreach ((array) ($j['ignorate'] ?? []) as $ig) {
            if (is_array($ig) && ($ig['motiv'] ?? null) === 'invalid' && is_string($ig['cheie'] ?? null) && is_string($ig['limba'] ?? null)) {
                // Paragraful respins de EasyCheck rămâne al site-ului: pleacă mai departe cu textul standard, iar starea poartă
                // alerta `mesaj_invalid`, cu garda primită.
                $s['pastrate'][] = $ig['cheie'] . '|' . $ig['limba'];
                if (is_string($ig['garda'] ?? null) && in_array($ig['garda'], SabloaneGazda::GARZI, true)) {
                    $s['respinse'][] = $ig['garda'];
                }
            }
        }
        $s['proba'] = ($j['proba'] ?? false) === true || ($s['proba'] ?? false) === true;
        unset($s['lot'], $s['reia_la']);
        if (($lot['incheiat'] ?? false) === true) {
            $s['incheiat_la'] = gmdate('Y-m-d\TH:i:s\Z', $acum);
        }
        $this->tineImport($s);
        if (is_int($j['versiune'] ?? null) && $j['versiune'] !== $this->versiune()) {
            $this->marcheazaInvechit();
        }
        return 'lot de ' . count((array) $lot['chei']) . (($lot['incheiat'] ?? false) === true ? ', încheiat' : '');
    }

    private function tineImport(array $s): void
    {
        $this->app->setari()->pune(self::SETARE_IMPORT, $s);
    }

    // ---- comanda de tip `mesaj`: ce face site-ul după preluare; executorul comenzilor (etapa 3) o cheamă ----

    /** Mesajele pe care le poate cere EasyCheck printr-o comandă `mesaj`. */
    public const COMANDA_MESAJE = ['retrimite_confirmarea', 'restituire_transfer'];

    /**
     * Comanda `mesaj` preluată de executorul comenzilor (`EasyCheck\Comenzi`, etapa 3): verificările față de registrul site-ului,
     * apoi e-mailul pus în coada site-ului. `reusita` când mesajul a intrat în coadă; `refuzata` cu codul contractului
     * (`tip_necunoscut`, `rezervare_necunoscuta`, `date_sterse`, `fara_confirmare`). Fără comutator și fără plafon.
     * Executorul ține o singură execuție pe comandă (rândul din `ec_comenzi`), deci aici nu se deduplică.
     * @param array $cmd comanda din `GET /comenzi` (`mesaj`, `parametri`)
     * @param array|null $c comanda site-ului găsită după `rez_…` (null: nu există)
     * @return array{stare:string, cod:?string}
     */
    public function comandaMesaj(array $cmd, ?array $c): array
    {
        $mesaj = $cmd['mesaj'] ?? null;
        if (!in_array($mesaj, self::COMANDA_MESAJE, true)) {
            return ['stare' => 'refuzata', 'cod' => 'tip_necunoscut'];
        }
        if ($c === null || \Cazo\Local\Mod::comandaLocala($c) || $this->app->ecConectare()->comandaStraina($c, $this->app->secrete()->citeste('ec_cheie'))) {
            return ['stare' => 'refuzata', 'cod' => 'rezervare_necunoscuta'];
        }
        if ($c['anonimizat_la'] !== null || ($this->app->comenzi()->oaspete($c)['email'] ?? null) === null) {
            return ['stare' => 'refuzata', 'cod' => 'date_sterse'];
        }
        if ($mesaj === 'retrimite_confirmarea') {
            // Ca „Retrimiteți confirmarea” din panou (AdminLista::retrimite): confirmarea înghețată la comandă, deci identică.
            if (!in_array($c['stare'], ['confirmata', 'anulare_ceruta'], true)) {
                return ['stare' => 'refuzata', 'cod' => 'fara_confirmare'];
            }
            $this->app->cerereCash()->anunta((int) $c['id'], $c['fel'] !== 'cash' ? 'plata_confirmata' : 'cerere_acceptata');
            return ['stare' => 'reusita', 'cod' => null];
        }
        $suma = is_array($cmd['parametri'] ?? null) ? ($cmd['parametri']['suma'] ?? null) : null;
        if (!is_int($suma) || $suma < 1 || $suma > 100_000_000) {
            // Parametrii greșiți nu au un cod în contract: programul nu știe să facă cererea.
            return ['stare' => 'refuzata', 'cod' => 'tip_necunoscut'];
        }
        $this->app->cerereCash()->anunta((int) $c['id'], 'restituire_transfer', ['suma_bani' => $suma, 'ec_comanda' => is_string($cmd['id'] ?? null) ? $cmd['id'] : null]);
        return ['stare' => 'reusita', 'cod' => null];
    }
}
