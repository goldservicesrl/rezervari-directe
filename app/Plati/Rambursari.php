<?php

declare(strict_types=1);

namespace Cazo\Plati;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Rambursările: `POST /v1/refunds` pe PaymentIntent-ul comenzii, cu `Idempotency-Key` salvat
 * în `operatii_plata` înainte de apel (rambursarea automată: `ref-<comanda>-auto`). Stările operației: cerut →
 * in_procesare (refund pending, metodele asincrone) → reusit | esuat. O rambursare eșuată (de exemplu sold
 * insuficient în contul Stripe) se reia de 3 ori în 24 de ore, fiecare cu o cheie nouă (`-r1`, `-r2`, `-r3`), fiindcă
 * Stripe păstrează rezultatul cheii vechi; după a treia, apelantul alertează gazda.
 *
 * O comandă poate avea două plăți încasate (plata inițială și diferența plătită la schimbarea
 * datelor). Fiecare operație ține plata pe care o rambursează (`stripe_pi`, migrarea 0015): când suma cerută trece de
 * ce mai e de rambursat pe plata dată, operația ia plata cu cel mai mult rămas și cere cel mult cât are aceasta
 * (Stripe refuză o sumă peste soldul rambursabil al unei plăți); restul îl cere operația următoare (`-s<n>` la
 * anulări, partea a doua `-p2` la rambursarea din panou). Lista din Stripe se citește pentru fiecare plată.
 */
final class Rambursari
{
    /** Pauzele dintre reluările unei rambursări eșuate (în total, sub 24 de ore). */
    public const REINCERCARI = [3600, 6 * 3600, 16 * 3600];
    /** O rambursare în procesare se recitește la cel mult o oră. */
    public const RECITIRE = 600;
    /** Cât se reia o operație `cerut` cu cheia ei: sub cele 24 de ore cât Stripe păstrează cheile de idempotență. */
    public const FEREASTRA_IDEM = 23 * 3600;
    /** După atât, o operație `cerut` cu lista Stripe necitibilă devine `esuat` (cu alerta gazdei). */
    public const PLAFON_CERUT = 48 * 3600;
    /** Câte pagini de câte 100 se citesc din lista rambursărilor unei plăți. */
    public const PAGINI_LISTA = 20;

    public function __construct(private Baza $baza, private Ceas $ceas, private ContStripe $cont)
    {
    }

    /**
     * Cere rambursarea (o singură dată pe cheie): întoarce starea operației (`reusit`, `in_procesare`, `esuat`, `cerut`).
     * `cerut` = Stripe n-a răspuns; reluarea, cu aceeași cheie, o face cronul.
     */
    public function ramburseaza(int $comandaId, string $pi, int $suma, string $motiv, string $idem, ?int $utilizator = null): string
    {
        $op = $this->baza->rand('SELECT * FROM {operatii_plata} WHERE idem = ?', [$idem]);
        $nou = $op === null;
        if ($op === null) {
            $acum = $this->ceas->moment();
            [$pi, $suma] = $this->tinta($comandaId, $pi, $suma);
            try {
                $this->baza->insereaza('operatii_plata', ['comanda_id' => $comandaId, 'tip' => 'rambursare', 'suma' => $suma, 'idem' => $idem, 'stare' => 'cerut',
                    'motiv' => $motiv, 'incercari' => 0, 'utilizator_id' => $utilizator, 'la' => $acum, 'actualizat_la' => $acum, 'stripe_pi' => $pi]);
            } catch (\PDOException $e) {
                if (!$this->baza->esteDuplicat($e)) {
                    throw $e;
                }
            }
            $op = $this->baza->rand('SELECT * FROM {operatii_plata} WHERE idem = ?', [$idem]);
        }
        if (in_array($op['stare'], ['reusit', 'in_procesare', 'esuat'], true)) {
            return (string) $op['stare'];
        }
        // O operație nouă pleacă acum; una rămasă `cerut` dinainte se lămurește (lista din Stripe, apoi cheia veche doar
        // în fereastra ei de 24 de ore).
        return $nou && (int) $op['incercari'] === 0 ? $this->trimite($op, $pi) : $this->lamureste($op, $pi);
    }

    /**
     * Plata pe care o rambursează o operație nouă și suma ei. Cu o singură plată încasată, exact ce cere apelantul
     * (ca înainte). Cu mai multe: plata dată, cât are destul; altfel plata cu cel mai mult rămas, cu suma tăiată la cât
     * are aceasta. @return array{0:string, 1:int}
     */
    private function tinta(int $comandaId, string $pi, int $suma): array
    {
        $plati = $this->pisIncasate($comandaId);
        if (count($plati) < 2) {
            return [$pi, $suma];
        }
        $ramas = [];
        foreach ($plati as $p) {
            $ramas[$p['pi']] = $this->ramasPe($comandaId, $p['pi']);
        }
        if (($ramas[$pi] ?? 0) >= $suma) {
            return [$pi, $suma];
        }
        arsort($ramas);
        $alt = (string) array_key_first($ramas);
        return [$alt, max(0, min($suma, $ramas[$alt]))];
    }

    /** Plățile încasate ale comenzii, în ordinea încasării (prima e plata inițială). @return list<array{id:int, pi:string, incasat:int}> */
    public function pisIncasate(int $comandaId): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'pi' => (string) $r['stripe_pi'], 'incasat' => (int) $r['suma_incasata']],
            $this->baza->toate('SELECT id, stripe_pi, suma_incasata FROM {plati} WHERE comanda_id = ? AND stripe_pi IS NOT NULL AND suma_incasata > 0 ORDER BY id', [$comandaId]));
    }

    /** Cât mai e de rambursat pe o plată: încasat minus operațiile ei reușite, în procesare sau încă `cerut`. */
    public function ramasPe(int $comandaId, string $pi): int
    {
        $plati = $this->pisIncasate($comandaId);
        $incasat = 0;
        foreach ($plati as $p) {
            if ($p['pi'] === $pi) {
                $incasat = $p['incasat'];
            }
        }
        $prima = $plati[0]['pi'] ?? $pi;
        $folosit = (int) $this->baza->valoare("SELECT COALESCE(SUM(suma), 0) FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND stare IN ('reusit', 'in_procesare', 'cerut')
            AND (stripe_pi = ?" . ($pi === $prima ? ' OR stripe_pi IS NULL' : '') . ')', [$comandaId, $pi]);
        return max(0, $incasat - $folosit);
    }

    /**
     * Rambursarea din panou (o sumă aleasă de gazdă, cu cheia formularului): cu mai multe plăți încasate, suma se poate
     * împărți pe două operații (`<cheie>` și `<cheie>-p2`), fiecare pe plata ei. Întoarce starea cea mai puțin avansată
     * (cerut, esuat, in_procesare, reusit), ca apelantul să vadă dacă mai e ceva de lămurit.
     */
    public function ramburseazaImpartit(int $comandaId, string $pi, int $suma, string $motiv, string $idem, ?int $utilizator = null): string
    {
        $stari = [$this->ramburseaza($comandaId, $pi, $suma, $motiv, $idem, $utilizator)];
        $prima = (int) $this->baza->valoare('SELECT suma FROM {operatii_plata} WHERE idem = ?', [$idem]);
        if ($prima < $suma && $prima > 0) {
            $stari[] = $this->ramburseaza($comandaId, $pi, $suma - $prima, $motiv, $idem . '-p2', $utilizator);
        }
        foreach (['cerut', 'esuat', 'in_procesare'] as $s) {
            if (in_array($s, $stari, true)) {
                return $s;
            }
        }
        return 'reusit';
    }

    private function trimite(array $op, string $pi): string
    {
        $pi = is_string($op['stripe_pi'] ?? null) && $op['stripe_pi'] !== '' ? $op['stripe_pi'] : $pi;
        $this->baza->executa('UPDATE {operatii_plata} SET incercari = incercari + 1, actualizat_la = ? WHERE id = ?', [$this->ceas->moment(), $op['id']]);
        try {
            $r = $this->cont->client()->post('/v1/refunds', ['payment_intent' => $pi, 'amount' => (int) $op['suma'],
                'metadata' => ['comanda_id' => (string) $op['comanda_id'], 'motiv' => (string) $op['motiv']]], (string) $op['idem']);
        } catch (EroareStripe $e) {
            if ($e->tranzitorie()) {
                $this->baza->executa('UPDATE {operatii_plata} SET eroare = ?, urmatoarea_incercare = ?, actualizat_la = ? WHERE id = ?',
                    [mb_substr($e->getMessage(), 0, 480), $this->ceas->moment(60), $this->ceas->moment(), $op['id']]);
                return 'cerut';
            }
            $this->baza->executa("UPDATE {operatii_plata} SET stare = 'esuat', eroare = ?, actualizat_la = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 480), $this->ceas->moment(), $op['id']]);
            return 'esuat';
        }
        return $this->aplica((int) $op['id'], $r);
    }

    /** Starea unei rambursări citite din Stripe (răspunsul la creare, citirea periodică sau anunțul). */
    private function aplica(int $opId, array $r): string
    {
        $stare = match ((string) ($r['status'] ?? '')) {
            'succeeded' => 'reusit',
            'failed', 'canceled' => 'esuat',
            default => 'in_procesare',
        };
        // `actualizat_la` se mută doar la o schimbare de stare: pe el se numără pauzele reluărilor (o recitire a
        // aceleiași rambursări eșuate nu amână reluarea).
        $vechi = (string) $this->baza->valoare('SELECT stare FROM {operatii_plata} WHERE id = ?', [$opId]);
        $this->baza->executa('UPDATE {operatii_plata} SET stare = ?, stripe_id = COALESCE(?, stripe_id), eroare = ?, urmatoarea_incercare = ?, actualizat_la = ? WHERE id = ?', [
            $stare, is_string($r['id'] ?? null) ? $r['id'] : null, $stare === 'esuat' ? mb_substr((string) ($r['failure_reason'] ?? 'failed'), 0, 480) : null,
            $stare === 'in_procesare' ? $this->ceas->moment(self::RECITIRE) : null,
            $vechi !== $stare ? $this->ceas->moment() : (string) $this->baza->valoare('SELECT actualizat_la FROM {operatii_plata} WHERE id = ?', [$opId]), $opId]);
        $op = $this->baza->rand('SELECT comanda_id FROM {operatii_plata} WHERE id = ?', [$opId]);
        $this->recalculeaza((int) $op['comanda_id']);
        return $stare;
    }

    /**
     * Rambursarea automată a unei comenzi, cu reluările ei: prima cheie `ref-<ref>-auto`, apoi `-r1`.. `-r3` după
     * eșecuri. $suma e restul nerambursat de acum: fiecare operație nouă cere suma de la momentul ei (o rambursare
     * parțială a gazdei din Dashboard între două încercări micșorează reluarea). Întoarce starea ultimei operații și
     * dacă reluările s-au epuizat. $restCalculat: apelantul a calculat $suma din operațiile cunoscute (ce mai e de
     * returnat acum), deci după o operație reușită o sumă pozitivă cere o operație nouă (`-s<n>`), ca la anulări.
     * @return array{stare:string, epuizata:bool, operatie:?array}
     */
    public function automata(int $comandaId, string $ref, string $pi, int $suma, string $motiv, string $fel = 'auto', bool $restCalculat = false): array
    {
        // $fel: `auto` (refuzurile) sau `anulare` (anulările, oaspetele, gazda în EasyCheck, panoul); fiecare
        // fel are cheile lui, deci o comandă anulată după o rambursare automată parțială își păstrează ambele șiruri.
        // `schimbare<id>`: rambursările unei schimbări de date (118b), una pe încercare; cheile derivate au mereu `-r<n>`
        // sau `-s<n>` după bază, deci căutarea ia baza exactă și ce urmează după `-`.
        if (!preg_match('~^[a-z][a-z0-9]{0,23}$~', $fel)) {
            throw new \InvalidArgumentException('Fel de rambursare invalid.');
        }
        $baza = 'ref-' . $ref . '-' . $fel;
        $ops = $this->baza->toate("SELECT * FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND (idem = ? OR idem LIKE ?) ORDER BY id", [$comandaId, $baza, $baza . '-%']);
        $ultima = $ops === [] ? null : $ops[count($ops) - 1];
        if ($suma <= 0 && ($ultima === null || $ultima['stare'] === 'esuat')) {
            // Restul e deja rambursat sau în procesare (de exemplu o rambursare a gazdei din Dashboard, încă pending):
            // nicio cerere nouă; anunțul sau citirea următoare a listei aduce rezultatul.
            return ['stare' => 'in_procesare', 'epuizata' => false, 'operatie' => $ultima];
        }
        if ($ultima === null) {
            $stare = $this->ramburseaza($comandaId, $pi, $suma, $motiv, $baza);
            return ['stare' => $stare, 'epuizata' => false, 'operatie' => $this->baza->rand('SELECT * FROM {operatii_plata} WHERE idem = ?', [$baza])];
        }
        if ($ultima['stare'] === 'cerut') {
            return ['stare' => $this->lamureste($ultima, $pi, false), 'epuizata' => false, 'operatie' => $ultima];
        }
        if ($ultima['stare'] === 'in_procesare' && $ultima['stripe_id'] !== null) {
            return ['stare' => $this->reciteste($ultima), 'epuizata' => false, 'operatie' => $ultima];
        }
        // Doar când apelantul cere exact ce a mai rămas de returnat (anulările, și rambursarea automată din
        // PlataOnline, cu $restCalculat); altfel o operație reușită încheie felul ei, iar o a doua chemare întoarce
        // aceeași operație, fără altă rambursare.
        if ($ultima['stare'] === 'reusit' && $suma > 0 && ($fel === 'anulare' || $restCalculat)) {
            // Mai e de returnat după o rambursare reușită (de exemplu o altă rambursare, în procesare la pornire, a eșuat
            // între timp): o operație nouă, cu cheia ei.
            $idem = $baza . '-s' . count($ops);
            return ['stare' => $this->ramburseaza($comandaId, $pi, $suma, $motiv, $idem), 'epuizata' => false, 'operatie' => $this->baza->rand('SELECT * FROM {operatii_plata} WHERE idem = ?', [$idem])];
        }
        if ($ultima['stare'] === 'esuat' && $ultima['eroare'] === 'nelamurita') {
            // O operație nelămurită poate să fi rambursat totuși: fără reluări cu chei noi (s-ar dubla banii); gazda
            // primește alerta și decide din panou.
            return ['stare' => 'esuat', 'epuizata' => true, 'operatie' => $ultima];
        }
        if ($ultima['stare'] === 'esuat') {
            $reluari = count(array_filter($ops, static fn (array $o): bool => !preg_match('~-s\d+$~', (string) $o['idem']))) - 1;
            if ($reluari >= count(self::REINCERCARI)) {
                return ['stare' => 'esuat', 'epuizata' => true, 'operatie' => $ultima];
            }
            $cand = Ceas::dinMoment((string) $ultima['actualizat_la']) + self::REINCERCARI[$reluari];
            if ($cand > $this->ceas->acum()) {
                return ['stare' => 'esuat', 'epuizata' => false, 'operatie' => $ultima];
            }
            $idem = $baza . '-r' . ($reluari + 1);
            return ['stare' => $this->ramburseaza($comandaId, $pi, $suma, $motiv, $idem), 'epuizata' => false, 'operatie' => $this->baza->rand('SELECT * FROM {operatii_plata} WHERE idem = ?', [$idem])];
        }
        return ['stare' => (string) $ultima['stare'], 'epuizata' => false, 'operatie' => $ultima];
    }

    /**
     * O operație rămasă `cerut` (Stripe n-a răspuns) se trimite din nou cu aceeași cheie; una `in_procesare` se
     * recitește (cel mult la RECITIRE). Pentru rambursările din panou, reluate din cron.
     */
    public function reia(array $op, string $pi): string
    {
        $pi = is_string($op['stripe_pi'] ?? null) && $op['stripe_pi'] !== '' ? $op['stripe_pi'] : $pi;
        if ($op['stare'] === 'cerut') {
            return $this->lamureste($op, $pi);
        }
        if ($op['stare'] === 'in_procesare' && $op['stripe_id'] !== null) {
            return $this->reciteste($op);
        }
        return (string) $op['stare'];
    }

    /**
     * Lămurirea unei operații rămase `cerut` (Stripe n-a răspuns), pe o cale garantată, oricare ar fi starea comenzii:
     * întâi lista rambursărilor plății (potrivirea după id sau după metadata, Plati\Rambursari::sincronizeaza); apoi, cât
     * cheia mai trăiește la Stripe (FEREASTRA_IDEM, sub cele 24 de ore ale lui), reluarea cu aceeași cheie (Stripe
     * întoarce rambursarea făcută sau o face acum; o sumă peste sold e refuzată, deci operația devine `esuat`). După
     * fereastră, cheia veche ar putea face o rambursare nouă, deci se citește doar lista; o operație negăsită acolo
     * devine `esuat` (`nelamurita`), iar apelantul alertează gazda. Fără listă (Stripe tace), încă o încercare peste 10
     * minute; după PLAFON_CERUT, `esuat` cu aceeași alertă.
     */
    public function lamureste(array $op, string $pi, bool $respectaPauza = true): string
    {
        if ($op['stare'] !== 'cerut') {
            return (string) $op['stare'];
        }
        // Rambursarea automată reia la fiecare rulare, ca până acum; cronul general respectă pauza operației.
        if ($respectaPauza && $op['urmatoarea_incercare'] !== null && (string) $op['urmatoarea_incercare'] > $this->ceas->moment()) {
            return 'cerut';
        }
        $pi = is_string($op['stripe_pi'] ?? null) && $op['stripe_pi'] !== '' ? $op['stripe_pi'] : $pi;
        $varsta = $this->ceas->acum() - \Cazo\Nucleu\Ceas::dinMoment((string) $op['la']);
        $citita = $this->sincronizeaza((int) $op['comanda_id'], $pi);
        $op = $this->baza->rand('SELECT * FROM {operatii_plata} WHERE id = ?', [$op['id']]) ?? $op;
        if ($op['stare'] !== 'cerut') {
            return (string) $op['stare'];
        }
        // Operația unui drum încheiat (comanda rambursată pe altă cale, anularea încheiată, comanda în stare finală) nu se
        // mai retrimite: cheia veche ar putea face acum o rambursare în plus. Lista citită și fără ea: `esuat`.
        $deschis = $this->baza->valoare("SELECT 1 FROM {operatii_plata} o JOIN {comenzi} c ON c.id = o.comanda_id WHERE o.id = ? AND " . self::CERUT_VIU, [$op['id']]) !== null;
        if ($deschis && $varsta < self::FEREASTRA_IDEM) {
            return $this->trimite($op, $pi);
        }
        if ($citita || $varsta >= self::PLAFON_CERUT || (!$deschis && $varsta >= self::FEREASTRA_IDEM)) {
            $this->baza->executa("UPDATE {operatii_plata} SET stare = 'esuat', eroare = 'nelamurita', urmatoarea_incercare = NULL, actualizat_la = ? WHERE id = ? AND stare = 'cerut'",
                [$this->ceas->moment(), $op['id']]);
            $this->recalculeaza((int) $op['comanda_id']);
            return 'esuat';
        }
        $this->baza->executa('UPDATE {operatii_plata} SET urmatoarea_incercare = ? WHERE id = ?', [$this->ceas->moment(600), $op['id']]);
        return 'cerut';
    }

    /** Suma operațiilor de rambursare ale comenzii cu cheia care începe cu $prefix, în stările date. */
    public function sumaPrefix(int $comandaId, string $prefix, array $stari): int
    {
        $semne = implode(', ', array_fill(0, count($stari), '?'));
        return (int) $this->baza->valoare("SELECT COALESCE(SUM(suma), 0) FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND idem LIKE ? AND stare IN ($semne)",
            array_merge([$comandaId, $prefix . '%'], $stari));
    }

    private function reciteste(array $op): string
    {
        if ($op['urmatoarea_incercare'] !== null && (string) $op['urmatoarea_incercare'] > $this->ceas->moment()) {
            return 'in_procesare';
        }
        try {
            return $this->aplica((int) $op['id'], $this->cont->client()->get('/v1/refunds/' . $op['stripe_id']));
        } catch (EroareStripe) {
            $this->baza->executa('UPDATE {operatii_plata} SET urmatoarea_incercare = ? WHERE id = ?', [$this->ceas->moment(self::RECITIRE), $op['id']]);
            return 'in_procesare';
        }
    }

    /**
     * Eșecul unei rambursări cunoscute, din obiectul semnat al anunțului `charge.refund.updated` (status `failed` sau
     * `canceled`). Ambele stări sunt finale la Stripe, deci un anunț întârziat nu le poate contrazice; se aplică și
     * când lista din Stripe nu se poate citi chiar atunci. O stare reușită sau în procesare din anunț se ia doar din
     * listă (un anunț vechi ar putea veni după eșec). @return bool true când operația a devenit acum `esuat`
     */
    public function esecDinAnunt(array $r): bool
    {
        if (!is_string($r['id'] ?? null) || !str_starts_with($r['id'], 're_') || !in_array($r['status'] ?? null, ['failed', 'canceled'], true)) {
            return false;
        }
        $op = $this->baza->rand("SELECT id, stare FROM {operatii_plata} WHERE stripe_id = ? AND tip = 'rambursare'", [$r['id']]);
        if ($op === null || $op['stare'] === 'esuat') {
            return false;
        }
        $motiv = is_string($r['failure_reason'] ?? null) && preg_match('~^[a-z_]{1,64}$~', $r['failure_reason']) ? $r['failure_reason'] : 'failed';
        $this->aplica((int) $op['id'], ['id' => $r['id'], 'status' => 'failed', 'failure_reason' => $motiv]);
        return true;
    }

    /**
     * Rambursările unei plăți, citite din Stripe (după `charge.refunded` sau `charge.refund.updated`): fiecare
     * rambursare se potrivește după id cu o operație cunoscută; o operație proprie al cărei răspuns s-a pierdut se
     * recunoaște după metadata (comanda și motivul) și suma; restul sunt rambursări făcute de gazdă în Dashboard și
     * intră în `operatii_plata` cu motivul `gest_gazda`. Apoi suma rambursată a plății se recalculează.
     */
    public function sincronizeaza(int $comandaId, string $pi): bool
    {
        // Fiecare plată încasată a comenzii (cea inițială și diferența unei schimbări de date), plus plata dată.
        $pis = array_values(array_unique(array_merge([$pi], array_column($this->pisIncasate($comandaId), 'pi'))));
        $ok = true;
        foreach ($pis as $p) {
            $ok = $this->sincronizeazaPlata($comandaId, $p) && $ok;
        }
        $this->recalculeaza($comandaId);
        return $ok;
    }

    private function sincronizeazaPlata(int $comandaId, string $pi): bool
    {
        // Toate paginile listei (has_more, starting_after), cel mult PAGINI_LISTA; o listă citită doar în parte nu
        // contează ca citită (o operație negăsită acolo poate fi pe pagina următoare).
        $date = [];
        $q = ['payment_intent' => $pi, 'limit' => 100];
        for ($i = 0; ; $i++) {
            try {
                $lista = $this->cont->client()->get('/v1/refunds', $q);
            } catch (EroareStripe) {
                return false;
            }
            $pagina = array_values(array_filter((array) ($lista['data'] ?? []), 'is_array'));
            array_push($date, ...$pagina);
            if (($lista['has_more'] ?? false) !== true || $pagina === []) {
                break;
            }
            if ($i + 1 >= self::PAGINI_LISTA) {
                return false;
            }
            $q['starting_after'] = (string) ($pagina[count($pagina) - 1]['id'] ?? '');
        }
        foreach ($date as $r) {
            if (!is_array($r) || !is_string($r['id'] ?? null)) {
                continue;
            }
            $op = $this->baza->rand('SELECT * FROM {operatii_plata} WHERE stripe_id = ?', [$r['id']]);
            if ($op === null && (string) ($r['metadata']['comanda_id'] ?? '') === (string) $comandaId) {
                // Operația noastră cu răspunsul pierdut (încă `cerut`, fără id): aceeași sumă și același motiv.
                // Doar o operație `cerut` (răspunsul pierdut); una `esuat` cu aceeași sumă e alt refund, deja refuzat.
                $op = $this->baza->rand("SELECT * FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND stripe_id IS NULL AND stare = 'cerut' AND suma = ? AND motiv = ?
                    AND (stripe_pi IS NULL OR stripe_pi = ?) ORDER BY id LIMIT 1", [$comandaId, (int) ($r['amount'] ?? 0), (string) ($r['metadata']['motiv'] ?? ''), $pi]);
            }
            if ($op !== null) {
                $this->aplica((int) $op['id'], $r);
                continue;
            }
            $acum = $this->ceas->moment();
            $stare = match ((string) ($r['status'] ?? '')) {
                'succeeded' => 'reusit',
                'failed', 'canceled' => 'esuat',
                default => 'in_procesare',
            };
            try {
                $this->baza->insereaza('operatii_plata', ['comanda_id' => $comandaId, 'tip' => 'rambursare', 'suma' => (int) ($r['amount'] ?? 0), 'stripe_id' => $r['id'],
                    'idem' => 'dash-' . $r['id'], 'stare' => $stare, 'motiv' => 'gest_gazda', 'incercari' => 0, 'la' => $acum, 'actualizat_la' => $acum, 'stripe_pi' => $pi]);
            } catch (\PDOException $e) {
                if (!$this->baza->esteDuplicat($e)) {
                    throw $e;
                }
            }
        }
        return true;
    }

    /**
     * Suma rambursată a fiecărei plăți și starea ei, din operațiile reușite pe plata ei (fără adunări repetate); o
     * operație fără plată (de dinaintea migrării 0015) ține de prima plată încasată.
     */
    public function recalculeaza(int $comandaId): void
    {
        $prima = $this->pisIncasate($comandaId)[0]['pi'] ?? null;
        // Starea se alege în PHP: SQL portabil pe MySQL, MariaDB și SQLite (fără CAST, iar pe SQLite parametrii sunt text).
        foreach ($this->baza->toate("SELECT id, stripe_pi, suma_incasata FROM {plati} WHERE comanda_id = ? AND stare IN ('incasata', 'rambursata_partial', 'rambursata')", [$comandaId]) as $p) {
            $suma = (int) $this->baza->valoare("SELECT COALESCE(SUM(suma), 0) FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND stare = 'reusit'
                AND (stripe_pi = ?" . ($p['stripe_pi'] !== null && $p['stripe_pi'] === $prima ? ' OR stripe_pi IS NULL' : '') . ')', [$comandaId, (string) $p['stripe_pi']]);
            $stare = $suma <= 0 ? 'incasata' : ($suma >= (int) $p['suma_incasata'] ? 'rambursata' : 'rambursata_partial');
            $this->baza->executa('UPDATE {plati} SET suma_rambursata = ?, stare = ?, actualizat_la = ? WHERE id = ?', [$suma, $stare, $this->ceas->moment(), $p['id']]);
        }
    }

    /**
     * Suma rambursărilor reușite, în procesare sau încă `cerut` (Stripe n-a răspuns: poate a făcut-o) ale comenzii, pe
     * orice cale, în bani. O operație `cerut` contează ca trimisă până se lămurește (reluarea cu aceeași cheie sau lista
     * din Stripe), ca o rambursare nouă să nu o dubleze.
     */
    public function rambursatSauInCurs(int $comandaId): int
    {
        return (int) $this->baza->valoare("SELECT COALESCE(SUM(suma), 0) FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND stare IN ('reusit', 'in_procesare', 'cerut')", [$comandaId]);
    }

    /** Comanda are o operație de rambursare eșuată ca „nelămurită” (poate să fi ajuns totuși la Stripe). */
    public function areNelamurita(int $comandaId): bool
    {
        return $this->baza->valoare("SELECT 1 FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND stare = 'esuat' AND eroare = 'nelamurita' LIMIT 1", [$comandaId]) !== null;
    }

    /** O rambursare a comenzii așteaptă răspunsul Stripe (`cerut`): rambursările noi și anularea așteaptă lămurirea ei. */
    public function cerutDeschis(int $comandaId): bool
    {
        return $this->baza->valoare("SELECT 1 FROM {operatii_plata} o JOIN {comenzi} c ON c.id = o.comanda_id WHERE o.comanda_id = ? AND o.tip = 'rambursare' AND o.stare = 'cerut'
            AND " . self::CERUT_VIU . ' LIMIT 1', [$comandaId]) !== null;
    }

    /**
     * Condiția SQL (pe aliasurile `o` și `c`): operația `cerut` ține încă de un drum deschis. Una a rambursării
     * automate (`-auto`) după ce comanda a ieșit din `de_rambursat`, sau a anulării (`-anulare`) după ce anularea e
     * rambursată, nu mai blochează nimic; se lămurește oricum din cron (lamureste).
     */
    public const CERUT_VIU = "NOT ((o.idem LIKE 'ref-%-auto%' AND c.stare <> 'de_rambursat') OR (o.idem LIKE 'ref-%-anulare%' AND c.anulare_rambursata_la IS NOT NULL)
        OR c.stare IN ('rambursata', 'inchisa_gazda', 'abandonata', 'plata_esuata', 'indisponibila', 'respinsa_ec', 'cerere_refuzata', 'cerere_expirata'))";

    /** Plățile încasate ale comenzii sunt rambursate integral (pe orice cale: automat sau din Dashboard). */
    public function integralRambursata(int $comandaId): bool
    {
        $r = $this->baza->rand('SELECT COALESCE(SUM(suma_incasata), 0) AS i, COALESCE(SUM(suma_rambursata), 0) AS r FROM {plati} WHERE comanda_id = ? AND suma_incasata > 0', [$comandaId]);
        return (int) $r['i'] > 0 && (int) $r['r'] >= (int) $r['i'];
    }
}
