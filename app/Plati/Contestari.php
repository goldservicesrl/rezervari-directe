<?php

declare(strict_types=1);

namespace Cazo\Plati;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Contestările plăților la bancă (Stripe „disputes”), ținute în tabelul `contestari` (migrarea 0065), fără date
 * personale: suma, motivul băncii, starea Stripe, termenul de răspuns, taxa și momentele.
 *
 *  - anunțurile `charge.dispute.*` (created, updated, closed, funds_withdrawn, funds_reinstated) sunt doar un semnal: starea
 *    se citește din `GET /v1/disputes/{id}`; fără permisiunea „Disputes: Read” (opțională), se ia obiectul semnat
 *    din anunț; fără răspuns de la Stripe, anunțul rămâne de reluat;
 *  - o dată pe zi, lista contestărilor din ultimele 120 de zile (`GET /v1/disputes?created[gte]=`) prinde ce a scăpat
 *    (un site fără anunțuri, un anunț pierdut);
 *  - prima apariție a unei contestări aduce alerta gazdei (`alerta_disputa`, ca până acum), o dată pe contestare;
 *  - rapoartele spre EasyCheck (`contestare`, cu `deschisa`, `castigata`, `pierduta`) le face EasyCheck\RaportPlati, din
 *    rândurile de aici. Întrebările băncii (stările `warning_*`) nu rețin bani, deci nu pleacă la EasyCheck.
 */
final class Contestari
{
    /** Cât de departe în urmă citește lista zilnică. */
    public const FEREASTRA_LISTA = 120 * 86400;
    /** Câte pagini de câte 100 citește lista zilnică. */
    public const PAGINI = 5;

    public function __construct(private Aplicatie $app)
    {
    }

    /**
     * Un anunț `charge.dispute.*` al comenzii $comandaId. Întoarce false când Stripe n-a răspuns (anunțul se reia din cron).
     */
    public function dinAnunt(int $comandaId, array $o): bool
    {
        $id = is_string($o['id'] ?? null) && preg_match('~^(dp|du)_[A-Za-z0-9_]{1,60}$~', $o['id']) ? $o['id'] : null;
        if ($id === null) {
            return true;
        }
        try {
            $d = $this->app->stripe()->client()->get('/v1/disputes/' . $id);
        } catch (EroareStripe $e) {
            if ($e->tranzitorie()) {
                return false;
            }
            // Fără permisiunea de citire (sau alt refuz definitiv): obiectul semnat al anunțului, cu câmpurile păstrate de
            // ContStripe::dateMinime.
            $d = $o;
        }
        return $this->tine($comandaId, $d);
    }

    /**
     * Lista zilnică a contestărilor (sarcina `plati:contestari`): contestările plăților site-ului, noi sau schimbate.
     * @return array{citite:int, schimbate:int}|null null când Stripe n-a dat lista
     */
    public function sincronizeaza(): ?array
    {
        $rez = ['citite' => 0, 'schimbate' => 0];
        $q = ['limit' => 100, 'created' => ['gte' => $this->app->ceas()->acum() - self::FEREASTRA_LISTA]];
        for ($i = 0; $i < self::PAGINI; $i++) {
            try {
                $l = $this->app->stripe()->client()->get('/v1/disputes', $q);
            } catch (EroareStripe) {
                return null;
            }
            $pagina = array_values(array_filter((array) ($l['data'] ?? []), 'is_array'));
            foreach ($pagina as $d) {
                $rez['citite']++;
                $pi = self::pi($d);
                $comanda = $pi !== null ? $this->app->baza()->valoare('SELECT comanda_id FROM {plati} WHERE stripe_pi = ? ORDER BY id DESC LIMIT 1', [$pi]) : null;
                if ($comanda !== null && $this->tine((int) $comanda, $d, true)) {
                    $rez['schimbate']++;
                }
            }
            if (($l['has_more'] ?? false) !== true || $pagina === []) {
                break;
            }
            $q['starting_after'] = (string) ($pagina[count($pagina) - 1]['id'] ?? '');
        }
        return $rez;
    }

    /** Contestările comenzii, cele mai vechi întâi. @return list<array> */
    public function aleComenzii(int $comandaId): array
    {
        return $this->app->baza()->toate('SELECT * FROM {contestari} WHERE comanda_id = ? ORDER BY id', [$comandaId]);
    }

    /** Suma reținută de bancă acum (contestările deschise sau pierdute) pe comanda dată. */
    public function retinut(int $comandaId): int
    {
        $s = 0;
        foreach ($this->aleComenzii($comandaId) as $c) {
            if (in_array(self::stareEc((string) $c['stare']), ['deschisa', 'pierduta'], true)) {
                $s += (int) $c['suma'];
            }
        }
        return $s;
    }

    /**
     * Starea pentru EasyCheck (contract-plati.md, `contestare`): `deschisa` cât banca ține banii, `castigata`, `pierduta`;
     * null la întrebările băncii (`warning_*`), care nu rețin nimic.
     */
    public static function stareEc(string $stare): ?string
    {
        return match ($stare) {
            'needs_response', 'under_review' => 'deschisa',
            'won' => 'castigata',
            'lost' => 'pierduta',
            default => null,
        };
    }

    /**
     * Scrie (sau aduce la zi) rândul unei contestări citite din Stripe. Întoarce true la o schimbare; prima apariție aduce
     * alerta gazdei. Cu $faraAlertaVeche (lista zilnică), o contestare deja încheiată la prima apariție n-o mai aduce.
     */
    private function tine(int $comandaId, array $d, bool $faraAlertaVeche = false): bool
    {
        $id = is_string($d['id'] ?? null) && preg_match('~^(dp|du)_[A-Za-z0-9_]{1,60}$~', $d['id']) ? $d['id'] : null;
        $stare = is_string($d['status'] ?? null) && preg_match('~^[a-z_]{1,32}$~', $d['status']) ? $d['status'] : null;
        if ($id === null || $stare === null || !is_int($d['amount'] ?? null) || $d['amount'] < 0) {
            return true;
        }
        $acum = $this->app->ceas()->moment();
        $creat = is_int($d['created'] ?? null) ? Ceas::formateaza($d['created']) : $acum;
        $termen = $d['evidence_details']['due_by'] ?? null;
        $motiv = is_string($d['reason'] ?? null) && preg_match('~^[a-z_]{1,40}$~', $d['reason']) ? $d['reason'] : null;
        // Taxa contestației: comisionul tranzacțiilor de sold ale contestării (pozitiv la retragere, negativ la returnare).
        $taxa = 0;
        $returnata = 0;
        $txn = null;
        $debitat = null;
        foreach ((array) ($d['balance_transactions'] ?? []) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $fee = is_int($t['fee'] ?? null) ? $t['fee'] : 0;
            $fee > 0 ? $taxa += $fee : $returnata += -$fee;
            if ($txn === null && is_string($t['id'] ?? null) && preg_match('~^txn_[A-Za-z0-9_]{1,60}$~', $t['id'])) {
                $txn = $t['id'];
                $debitat = is_int($t['created'] ?? null) ? Ceas::formateaza($t['created']) : null;
            }
        }
        $rand = ['comanda_id' => $comandaId, 'stripe_pi' => self::pi($d), 'suma' => $d['amount'], 'motiv' => $motiv, 'stare' => $stare,
            'termen' => is_int($termen) ? Ceas::formateaza($termen) : null, 'taxa' => $taxa, 'taxa_returnata' => $returnata, 'txn' => $txn, 'debitat_la' => $debitat];
        $b = $this->app->baza();
        $vechi = $b->rand('SELECT * FROM {contestari} WHERE stripe_id = ?', [$id]);
        $inchisa = in_array($stare, ['won', 'lost', 'warning_closed'], true);
        if ($vechi === null) {
            try {
                $b->insereaza('contestari', $rand + ['stripe_id' => $id, 'deschisa_la' => $creat, 'inchisa_la' => $inchisa ? $acum : null, 'actualizat_la' => $acum]);
            } catch (\PDOException $e) {
                if (!$b->esteDuplicat($e)) {
                    throw $e;
                }
                return $this->tine($comandaId, $d, $faraAlertaVeche);
            }
            $this->app->masinaStari()->jurnal($comandaId, $this->stareComenzii($comandaId), $this->stareComenzii($comandaId), 'webhook_stripe', ['contestare' => $id, 'stare' => $stare]);
            if (!($faraAlertaVeche && $inchisa)) {
                $c = $this->app->comenzi()->dupaId($comandaId);
                $this->app->alertaGazda('alerta_disputa', ['ref' => (string) ($c['ref'] ?? '-'), 'suma_bani' => (int) $d['amount']], 'disputa:' . $id, 30 * 86400);
            }
            return true;
        }
        $schimbat = false;
        foreach ($rand as $k => $v) {
            if ((string) $vechi[$k] !== (string) $v) {
                $schimbat = true;
            }
        }
        if (!$schimbat) {
            return false;
        }
        $b->executa('UPDATE {contestari} SET comanda_id = ?, stripe_pi = ?, suma = ?, motiv = ?, stare = ?, termen = ?, taxa = ?, taxa_returnata = ?, txn = ?, debitat_la = ?,
            inchisa_la = ?, actualizat_la = ? WHERE id = ?', [...array_values($rand), $inchisa ? ($vechi['inchisa_la'] ?? $acum) : null, $acum, $vechi['id']]);
        if ($vechi['stare'] !== $stare) {
            $this->app->masinaStari()->jurnal($comandaId, $this->stareComenzii($comandaId), $this->stareComenzii($comandaId), 'webhook_stripe', ['contestare' => $id, 'stare' => $stare]);
        }
        return true;
    }

    private function stareComenzii(int $id): string
    {
        return (string) ($this->app->comenzi()->dupaId($id)['stare'] ?? '');
    }

    private static function pi(array $d): ?string
    {
        $pi = $d['payment_intent'] ?? null;
        $pi = is_array($pi) ? ($pi['id'] ?? null) : $pi;
        return is_string($pi) && preg_match('~^pi_[A-Za-z0-9_]{1,200}$~', $pi) ? $pi : null;
    }
}
