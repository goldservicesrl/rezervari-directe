<?php

declare(strict_types=1);

namespace Cazo\Plati;

defined('CAZO') or exit;

/**
 * Verificarea cheii restricționate Stripe: refuzul cheilor secrete `sk_`, apoi fiecare
 * permisiune verificată cu un apel inofensiv. Citirile: o listare cu `limit=1`. Scrierile: o operație pe un obiect
 * inexistent; „resource_missing” (404 sau 400) arată că permisiunea există, 403 că lipsește. Excepția: la rambursări Stripe caută
 * întâi plata, deci `POST /v1/refunds` pe o plată inexistentă dă 404 și fără drept (proba din 26.11.2026): dreptul pe Refunds se
 * verifică și cu o listare (`refunds_read`, 403 când cheia n-are nimic pe Refunds).
 * Rezultatul: [permisiune => ok | lipsa | eroare]; plata online merge doar cu toate permisiunile obligatorii.
 */
final class VerificareCheie
{
    /** permisiune => [metoda, calea, parametrii, obligatorie] */
    public const PERMISIUNI = [
        'checkout_write' => ['POST', '/v1/checkout/sessions/cs_test_cazoverificare/expire', [], true],
        'checkout_read' => ['GET', '/v1/checkout/sessions', ['limit' => 1], true],
        'payment_intents_read' => ['GET', '/v1/payment_intents', ['limit' => 1], true],
        // captura și eliberarea autorizării (plata cu reținere); obligatorie, ca celelalte.
        'payment_intents_write' => ['POST', '/v1/payment_intents/pi_cazoverificare/cancel', [], true],
        'refunds_write' => ['POST', '/v1/refunds', ['payment_intent' => 'pi_cazoverificare'], true],
        'refunds_read' => ['GET', '/v1/refunds', ['limit' => 1], true],
        'charges_read' => ['GET', '/v1/charges', ['limit' => 1], true],
        'events_read' => ['GET', '/v1/events', ['limit' => 1], true],
        'webhook_write' => ['POST', '/v1/webhook_endpoints/we_cazoverificare', ['description' => 'verificare'], false],
        'disputes_read' => ['GET', '/v1/disputes', ['limit' => 1], false],
        'balance_read' => ['GET', '/v1/balance_transactions', ['limit' => 1], false],
    ];

    /**
     * Ce alege gazda în Stripe, câte o resursă pe rând (în Stripe fiecare resursă are o singură alegere, None, Read sau Write,
     * iar Write o cuprinde pe Read): rândul de arătat => permisiunile verificate pe care le acoperă.
     */
    public const SETARI = [
        'checkout_write' => ['checkout_write', 'checkout_read'],
        'payment_intents_write' => ['payment_intents_write', 'payment_intents_read'],
        'refunds_write' => ['refunds_write', 'refunds_read'],
        'charges_read' => ['charges_read'],
        'events_read' => ['events_read'],
        'webhook_write' => ['webhook_write'],
        'disputes_read' => ['disputes_read'],
        'balance_read' => ['balance_read'],
    ];

    /** Forma cheii: null = acceptată; altfel motivul refuzului (`secreta`, `forma`). */
    public static function forma(string $cheie): ?string
    {
        if (preg_match('~^sk_(test|live)_~', $cheie)) {
            return 'secreta';
        }
        return preg_match('~^rk_(test|live)_[A-Za-z0-9]{10,240}$~', $cheie) ? null : 'forma';
    }

    /**
     * @return array{permisiuni: array<string,string>, ok: bool, cheie_invalida: bool}
     */
    public static function verifica(StripeClient $client): array
    {
        $rez = [];
        $invalida = false;
        foreach (self::PERMISIUNI as $p => [$metoda, $cale, $param]) {
            try {
                $metoda === 'GET' ? $client->get($cale, $param) : $client->post($cale, $param, 'verificare-' . $p . '-' . bin2hex(random_bytes(6)));
                $rez[$p] = 'ok';
            } catch (EroareStripe $e) {
                if ($e->status === 401) {
                    $invalida = true;
                    $rez[$p] = 'lipsa';
                } elseif ($e->permisiune()) {
                    $rez[$p] = 'lipsa';
                } elseif ($e->felul === 'api' && ($e->cod === 'resource_missing' || in_array($e->status, [400, 404], true))) {
                    $rez[$p] = 'ok';
                } else {
                    $rez[$p] = 'eroare';
                }
            }
        }
        $ok = !$invalida;
        foreach (self::PERMISIUNI as $p => [, , , $oblig]) {
            if ($oblig && $rez[$p] !== 'ok') {
                $ok = false;
            }
        }
        return ['permisiuni' => $rez, 'ok' => $ok, 'cheie_invalida' => $invalida];
    }
}
