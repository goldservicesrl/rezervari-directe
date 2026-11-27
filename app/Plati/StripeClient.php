<?php

declare(strict_types=1);

namespace Cazo\Plati;

defined('CAZO') or exit;

/**
 * Clientul Stripe propriu, fără SDK: cURL, corp form-urlencoded, `Stripe-Version` fixat în cod
 * (schimbarea lui vine într-o lansare, cu teste), cheia doar în `Authorization: Bearer`. Fiecare POST are un
 * `Idempotency-Key` dat de apelant, derivat determinist din comandă și operație și salvat pe rând înainte de apel;
 * la timeout sau 5xx, apelantul reia cu aceeași cheie și aceiași parametri.
 *
 * Operațiile: sesiunea Checkout (creare, citire cu payment_intent, expirare), PaymentIntent-ul, rambursarea și
 * citirea ei, punctul de anunț (creare, ștergere), listările de verificare a cheii.
 */
final class StripeClient
{
    public const BAZA = 'https://api.stripe.com';
    /** Versiunea API fixată la lansare; aceeași intră în punctul de anunț. */
    public const VERSIUNE = '2025-10-29.clover';
    public const TIMEOUTS = ['conectare' => 5.0, 'total' => 20.0];

    /** @param \Closure(): ?string $cheie */
    public function __construct(private Http $http, private \Closure $cheie, private string $baza = self::BAZA)
    {
        $this->baza = rtrim($baza, '/');
    }

    public function get(string $cale, array $query = []): array
    {
        return $this->cere('GET', $cale, $query, null);
    }

    public function post(string $cale, array $param, ?string $idem): array
    {
        return $this->cere('POST', $cale, $param, $idem);
    }

    public function delete(string $cale): array
    {
        return $this->cere('DELETE', $cale, [], null);
    }

    /** Parametrii în forma Stripe: a[b][0]=c, cu adevărat/fals ca „true”/„false”. */
    public static function codifica(array $param): string
    {
        $norm = static function (mixed $v) use (&$norm): mixed {
            if (is_bool($v)) {
                return $v ? 'true' : 'false';
            }
            return is_array($v) ? array_map($norm, $v) : $v;
        };
        return http_build_query($norm($param), '', '&', PHP_QUERY_RFC1738);
    }

    private function cere(string $metoda, string $cale, array $param, ?string $idem): array
    {
        if (!preg_match('~^/v1/[A-Za-z0-9_/\-]{1,200}$~', $cale)) {
            throw new \InvalidArgumentException('Cale Stripe invalidă.');
        }
        $cheie = ($this->cheie)();
        if ($cheie === null || $cheie === '' || preg_match('~[\r\n\0\s]~', $cheie)) {
            throw new EroareStripe('Cheia Stripe lipsește.', 'fara_cheie');
        }
        $antete = ['Authorization' => 'Bearer ' . $cheie, 'Stripe-Version' => self::VERSIUNE, 'Accept' => 'application/json'];
        $url = $this->baza . $cale;
        $corp = null;
        if ($metoda === 'GET') {
            $url .= $param === [] ? '' : '?' . self::codifica($param);
        } else {
            $corp = self::codifica($param);
            $antete['Content-Type'] = 'application/x-www-form-urlencoded';
            if ($idem !== null) {
                if (!preg_match('~^[A-Za-z0-9\-_.:]{8,255}$~', $idem)) {
                    throw new \InvalidArgumentException('Idempotency-Key invalidă.');
                }
                $antete['Idempotency-Key'] = $idem;
            }
        }
        $r = $this->http->trimite($metoda, $url, $antete, $corp, self::TIMEOUTS);
        if (!$r->reusit()) {
            throw EroareStripe::din($r, $metoda . ' ' . preg_replace('~/(cs|pi|re|we|ch|evt)_[A-Za-z0-9_]+~', '/$1_…', $cale));
        }
        return $r->json();
    }

    /** Modul cheii: „test”, „live” sau null (cheie necunoscută). */
    public static function mod(string $cheie): ?string
    {
        return preg_match('~^(rk|sk)_(test|live)_~', $cheie, $m) ? $m[2] : null;
    }
}
