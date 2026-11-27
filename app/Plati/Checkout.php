<?php

declare(strict_types=1);

namespace Cazo\Plati;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Sesiunea Checkout: plata cu reținere (`capture_method=manual`): suma se autorizează la
 * plată, se capturează după confirmarea EasyCheck (captureaza) și se eliberează la un refuz (anuleaza); Stripe arată
 * doar metodele care suportă reținerea (cardurile, portofelele).
 * Fără `payment_method_types`, deci toate metodele active în contul gazdei care acceptă lei și suma; `Idempotency-Key: ses-<ref>-<incercare>`,
 * salvat pe rândul din `plati` înainte de apel; expirarea la 30 de minute (minimul Stripe).
 * Fără Stripe.js și fără cheie publică pe paginile site-ului (Checkout găzduit, SAQ A).
 */
final class Checkout
{
    public const EXPIRARE = 1800;

    public function __construct(private Baza $baza, private Ceas $ceas, private ContStripe $cont)
    {
    }

    /**
     * Creează sesiunea pentru suma de plătit acum. $d: comanda_id, ref, suma, produs, email, limba, proba,
     * succes (cu {CHECKOUT_SESSION_ID}), renuntare; opțional schimbare_id (plata diferenței la schimbarea datelor,
     * 118b: cheia `dif-<ref>-<n>` și metadata `schimbare`, după care anunțul Stripe găsește schimbarea).
     * @return array{id:string, url:string, plata_id:int}
     */
    public function creeaza(array $d): array
    {
        $incercare = 1 + (int) $this->baza->valoare('SELECT COUNT(*) FROM {plati} WHERE comanda_id = ?', [$d['comanda_id']]);
        $schimbare = isset($d['schimbare_id']) ? (int) $d['schimbare_id'] : null;
        $idem = ($schimbare !== null ? 'dif-' : 'ses-') . $d['ref'] . '-' . $incercare;
        $acum = $this->ceas->moment();
        $plata = $this->baza->insereaza('plati', ['comanda_id' => $d['comanda_id'], 'incercare' => $incercare, 'stare' => 'sesiune', 'suma' => (int) $d['suma'],
            'idem_sesiune' => $idem, 'creat_la' => $acum, 'actualizat_la' => $acum] + ($schimbare !== null ? ['schimbare_id' => $schimbare] : []));
        $meta = ['comanda' => $d['ref']] + ($schimbare !== null ? ['schimbare' => (string) $schimbare] : []);
        $expira = $this->ceas->acum() + self::EXPIRARE + 60;
        $param = [
            'mode' => 'payment',
            'currency' => 'ron',
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'ron', 'unit_amount' => (int) $d['suma'], 'product_data' => ['name' => mb_substr((string) $d['produs'], 0, 250)]]]],
            'payment_intent_data' => ['description' => mb_substr((string) $d['produs'], 0, 250), 'metadata' => $meta, 'capture_method' => 'manual'],
            'metadata' => $meta + ['proba' => $d['proba'] ? '1' : '0'],
            'client_reference_id' => $d['ref'],
            'customer_email' => $d['email'],
            'locale' => $d['limba'] === 'en' ? 'en' : 'ro',
            'submit_type' => $schimbare !== null ? 'pay' : 'book',
            'expires_at' => $expira,
            'success_url' => $d['succes'],
            'cancel_url' => $d['renuntare'],
        ];
        $s = null;
        for ($i = 0; $i < 2; $i++) {
            try {
                $s = $this->cont->client()->post('/v1/checkout/sessions', $param, $idem);
                break;
            } catch (EroareStripe $e) {
                if (!$e->tranzitorie() || $i === 1) {
                    $this->baza->executa("UPDATE {plati} SET stare = 'esuata', actualizat_la = ? WHERE id = ?", [$this->ceas->moment(), $plata]);
                    throw $e;
                }
            }
        }
        if (!is_string($s['id'] ?? null) || !is_string($s['url'] ?? null) || !str_starts_with($s['url'], 'https://')) {
            $this->baza->executa("UPDATE {plati} SET stare = 'esuata', actualizat_la = ? WHERE id = ?", [$this->ceas->moment(), $plata]);
            throw new EroareStripe('Sesiunea Checkout are o formă neașteptată.', 'forma');
        }
        // Metodele oferite oaspetelui de Stripe la ultima plată, arătate în panou.
        $this->cont->tineMetode((array) ($s['payment_method_types'] ?? []));
        $this->baza->executa('UPDATE {plati} SET stripe_sesiune = ?, url = ?, expira_la = ?, actualizat_la = ? WHERE id = ?',
            [$s['id'], $s['url'], Ceas::formateaza((int) ($s['expires_at'] ?? $expira)), $this->ceas->moment(), $plata]);
        return ['id' => $s['id'], 'url' => $s['url'], 'plata_id' => $plata];
    }

    /** Ultima plată a comenzii (sesiunea curentă). */
    public function ultima(int $comandaId): ?array
    {
        return $this->baza->rand('SELECT * FROM {plati} WHERE comanda_id = ? ORDER BY id DESC LIMIT 1', [$comandaId]);
    }

    public function citeste(string $sesiune): array
    {
        return $this->cont->client()->get('/v1/checkout/sessions/' . $sesiune, ['expand' => ['payment_intent', 'payment_intent.latest_charge']]);
    }

    /** Captura unei plăți autorizate, pentru toată suma autorizată; aceeași cheie la fiecare reluare. */
    public function captureaza(string $pi, string $idem): array
    {
        return $this->cont->client()->post('/v1/payment_intents/' . $pi . '/capture', [], $idem);
    }

    /** Eliberarea unei plăți autorizate: PaymentIntent-ul anulat, banii deblocați de bancă, fără comision. */
    public function anuleaza(string $pi, string $idem): array
    {
        return $this->cont->client()->post('/v1/payment_intents/' . $pi . '/cancel', ['cancellation_reason' => 'abandoned'], $idem);
    }

    public function citestePi(string $pi): array
    {
        return $this->cont->client()->get('/v1/payment_intents/' . $pi, ['expand' => ['latest_charge']]);
    }

    public function expira(string $sesiune): array
    {
        return $this->cont->client()->post('/v1/checkout/sessions/' . $sesiune . '/expire', [], 'exp-' . $sesiune);
    }

    /**
     * Starea sesiunii citite din API, raportată la suma așteptată:
     *   platita       payment_status = paid, PaymentIntent-ul succeeded, amount_received = suma
     *   autorizata    PaymentIntent-ul requires_capture, amount_capturable = suma; suma se încasează după confirmare
     *   nepotrivita   plătită, dar cu altă sumă decât cea a comenzii (se rambursează integral)
     *   in_procesare  sesiunea încheiată, plata cu confirmare întârziată (payment_status = unpaid)
     *   esuata        PaymentIntent-ul anulat sau refuzat după confirmarea întârziată
     *   expirata      sesiunea a expirat fără plată
     *   deschisa      oaspetele n-a terminat încă plata
     * @return array{stare:string, pi:?string, suma:int, metoda:?string, metoda_scurt:?string}
     */
    public static function interpreteaza(array $s, int $suma): array
    {
        $pi = $s['payment_intent'] ?? null;
        $piId = is_array($pi) ? ($pi['id'] ?? null) : (is_string($pi) ? $pi : null);
        $primit = is_array($pi) ? (int) ($pi['amount_received'] ?? 0) : 0;
        $ch = is_array($pi) && is_array($pi['latest_charge'] ?? null) ? $pi['latest_charge'] : [];
        $det = (array) ($ch['payment_method_details'] ?? []);
        $metoda = is_string($det['type'] ?? null) ? $det['type'] : (is_array($pi) && is_array($pi['payment_method_types'] ?? null) ? (string) ($pi['payment_method_types'][0] ?? '') : null);
        $scurt = null;
        if (is_array($det['card'] ?? null)) {
            $scurt = mb_substr(trim((string) ($det['card']['brand'] ?? '') . ' ' . (string) ($det['card']['last4'] ?? '')), 0, 64);
        }
        $r = ['stare' => 'deschisa', 'pi' => is_string($piId) ? $piId : null, 'suma' => $primit, 'metoda' => $metoda !== '' ? $metoda : null, 'metoda_scurt' => $scurt,
            'autorizata' => false, 'capturare_inainte' => null];
        $stare = (string) ($s['status'] ?? '');
        $platita = (string) ($s['payment_status'] ?? '');
        $piStare = is_array($pi) ? (string) ($pi['status'] ?? '') : '';
        if ($stare === 'complete' && $platita === 'paid' && $piStare === 'succeeded') {
            $r['stare'] = $primit === $suma ? 'platita' : 'nepotrivita';
        } elseif ($stare === 'complete' && $piStare === 'requires_capture') {
            // suma e doar autorizată (reținută pe card); `suma` e cea care se poate captura.
            $r['suma'] = (int) ($pi['amount_capturable'] ?? 0);
            $r['stare'] = $r['suma'] === $suma ? 'autorizata' : 'nepotrivita';
            $r['autorizata'] = true;
            $inainte = $ch['payment_method_details']['card']['capture_before'] ?? null;
            $r['capturare_inainte'] = is_int($inainte) ? $inainte : null;
        } elseif ($stare === 'complete' && in_array($piStare, ['canceled', 'requires_payment_method'], true)) {
            $r['stare'] = 'esuata';
        } elseif ($stare === 'complete') {
            $r['stare'] = 'in_procesare';
        } elseif ($stare === 'expired') {
            $r['stare'] = 'expirata';
        }
        return $r;
    }
}
