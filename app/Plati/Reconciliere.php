<?php

declare(strict_types=1);

namespace Cazo\Plati;

use Cazo\Nucleu\Aplicatie;

defined('CAZO') or exit;

/**
 * Reconcilierea și dovezile: rândurile exportului CSV (o plată încasată pe rând, cu
 * rambursările ei până azi) și lista „Avansuri încasate pentru sejururi viitoare” (Ord. 948/2026, la radierea
 * certificatului). Comisionul Stripe se citește din balance_transaction (GET /v1/payment_intents/{pi} cu
 * expand[]=latest_charge.balance_transaction, permisiunea Balance Transactions Read), o singură dată pe plată, și se
 * păstrează în `plati.comision`; fără răspuns sau fără permisiune, celula rămâne goală.
 */
final class Reconciliere
{
    /** Câte comisioane se citesc din Stripe la un export (restul, la exportul următor). */
    public const COMISIOANE = 50;

    public function __construct(private Aplicatie $app)
    {
    }

    /**
     * Plățile încasate între $de și $pana (zile incluse, în ora României, după momentul încasării).
     * Restul la sosire apare doar la rezervările încă vii (confirmate sau cu cererea de anulare în așteptare).
     * @return list<array{ref:string, rezervare:string, pi:string, data:string, brut:int, comision:?int, rambursari:int, rest:int}>
     */
    public function randuri(string $de, string $pana): array
    {
        $fus = new \DateTimeZone('Europe/Bucharest');
        $utc = new \DateTimeZone('UTC');
        $inceput = (new \DateTimeImmutable($de . ' 00:00:00', $fus))->setTimezone($utc)->format('Y-m-d H:i:s');
        $sfarsit = (new \DateTimeImmutable($pana . ' 00:00:00', $fus))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $rez = [];
        $citite = 0;
        // Diferența plătită la o schimbare de date (118b) e un rând propriu, cu ziua încasării ei; restul la sosire stă
        // pe rândul plății inițiale.
        // ziua încasării e cea a capturii (`capturata_la`), nu a autorizării; plățile de dinainte au doar
        // `incasata_la` (diferențele) sau momentul plății comenzii.
        $la = 'COALESCE(p.capturata_la, p.incasata_la, c.platita_la)';
        foreach ($this->app->baza()->toate("SELECT c.ref, c.ec_rezervare, $la AS platita_la, c.stare, c.rest_la_sosire, p.id AS plata_id, p.stripe_pi, p.suma_incasata,
            p.suma_rambursata, p.comision, p.schimbare_id
            FROM {comenzi} c JOIN {plati} p ON p.comanda_id = c.id
            WHERE c.fel = 'card' AND c.platita_la IS NOT NULL AND $la >= ? AND $la < ? AND p.stripe_pi IS NOT NULL AND p.suma_incasata > 0
            ORDER BY $la, c.id, p.id", [$inceput, $sfarsit]) as $x) {
            $comision = $x['comision'] !== null ? (int) $x['comision'] : null;
            if ($comision === null && $citite < self::COMISIOANE) {
                $citite++;
                $comision = $this->comision((int) $x['plata_id'], (string) $x['stripe_pi']);
            }
            $rez[] = [
                'ref' => (string) $x['ref'],
                'rezervare' => (string) ($x['ec_rezervare'] ?? ''),
                'pi' => (string) $x['stripe_pi'],
                'data' => (new \DateTimeImmutable((string) $x['platita_la'], $utc))->setTimezone($fus)->format('Y-m-d'),
                'brut' => (int) $x['suma_incasata'],
                'comision' => $comision,
                'rambursari' => (int) $x['suma_rambursata'],
                'rest' => $x['schimbare_id'] === null && in_array($x['stare'], ['confirmata', 'anulare_ceruta'], true) ? (int) $x['rest_la_sosire'] : 0,
            ];
        }
        return $rez;
    }

    /** Comisionul plății din balance_transaction, păstrat pe rândul plății; null fără răspuns. */
    public function comision(int $plataId, string $pi): ?int
    {
        try {
            $j = $this->app->stripe()->client()->get('/v1/payment_intents/' . $pi, ['expand' => ['latest_charge.balance_transaction']]);
        } catch (EroareStripe) {
            return null;
        }
        $bt = $j['latest_charge']['balance_transaction'] ?? null;
        if (!is_array($bt) || !is_int($bt['fee'] ?? null)) {
            return null;
        }
        $this->app->baza()->executa('UPDATE {plati} SET comision = ?, actualizat_la = ? WHERE id = ?', [$bt['fee'], $this->app->ceas()->moment(), $plataId]);
        return $bt['fee'];
    }

    /**
     * Avansurile încasate pentru sejururi viitoare: rezervările plătite online, încă vii (și cele anulate în EasyCheck cu
     * suma încă de ales de gazdă), cu sosirea după azi și cu o sumă încasată nerambursată.
     * @return array{randuri: list<array{ref:string, sosire:string, plecare:string, oaspete:string, incasat:int, rambursat:int, net:int}>, total:int}
     */
    public function avansuri(): array
    {
        $randuri = [];
        $total = 0;
        // anularea din EasyCheck încă de lămurit ține banii la gazdă până la decizie, deci avansul rămâne în listă.
        foreach ($this->app->baza()->toate("SELECT c.* FROM {comenzi} c WHERE c.fel = 'card' AND c.stare IN ('confirmata', 'anulare_ceruta', 'anulare_de_lamurit') AND c.sosire > ? ORDER BY c.sosire, c.id",
            [$this->app->azi()]) as $c) {
            $p = $this->app->anulare()->plata((int) $c['id']);
            if ($p === null) {
                continue;
            }
            $rambursat = $this->app->rambursari()->rambursatSauInCurs((int) $c['id']);
            $net = (int) $p['suma_incasata'] - $rambursat;
            if ($net <= 0) {
                continue;
            }
            $o = $this->app->comenzi()->oaspete($c);
            $randuri[] = ['ref' => (string) $c['ref'], 'sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare'], 'oaspete' => (string) ($o['nume'] ?? ''),
                'incasat' => (int) $p['suma_incasata'], 'rambursat' => $rambursat, 'net' => $net];
            $total += $net;
        }
        return ['randuri' => $randuri, 'total' => $total];
    }
}
