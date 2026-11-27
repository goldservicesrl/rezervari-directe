<?php

declare(strict_types=1);

namespace Cazo\Plati;

defined('CAZO') or exit;

/**
 * Transportul prin cURL spre api.stripe.com: TLS verificat, fără redirecționări, doar HTTPS (HTTP doar în mediul
 * de test), corpul răspunsului plafonat la 2 MB.
 */
final class HttpCurl implements Http
{
    public const MAXIM = 2 * 1024 * 1024;

    public function __construct(private bool $permiteHttp = false)
    {
    }

    public function trimite(string $metoda, string $url, array $antete, ?string $corp, array $limite): RaspunsStripe
    {
        // Fără curl_init sau curl_exec (oprite de găzduire): aceeași eroare ca o rețea căzută.
        if (!function_exists('curl_exec') || !function_exists('curl_init')) {
            throw new EroareStripe('cURL e oprit pe server.', 'retea');
        }
        $h = curl_init($url);
        if ($h === false) {
            throw new EroareStripe('cURL nu a pornit.', 'retea');
        }
        $linii = ['Expect:'];
        foreach ($antete as $k => $v) {
            $linii[] = $k . ': ' . $v;
        }
        $st = new \stdClass();
        $st->antete = [];
        $st->corp = '';
        $st->prea = false;
        curl_setopt_array($h, [
            CURLOPT_CUSTOMREQUEST => $metoda,
            CURLOPT_HTTPHEADER => $linii,
            CURLOPT_USERAGENT => 'Cazo',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $this->permiteHttp ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($limite['conectare'] * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($limite['total'] * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use ($st): int {
                $p = explode(':', $linie, 2);
                if (count($p) === 2) {
                    $st->antete[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($linie);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $b) use ($st): int {
                if (strlen($st->corp) + strlen($b) > self::MAXIM) {
                    $st->prea = true;
                    return 0;
                }
                $st->corp .= $b;
                return strlen($b);
            },
        ] + ($corp !== null ? [CURLOPT_POSTFIELDS => $corp] : []));
        $ok = function_exists('curl_exec') ? curl_exec($h) : false;
        $errno = curl_errno($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_close($h);
        if ($st->prea) {
            throw new EroareStripe('Răspuns Stripe prea mare.', 'retea');
        }
        if ($ok === false || $status === 0) {
            throw new EroareStripe('Stripe nu a răspuns (cURL ' . $errno . ').', $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'retea');
        }
        return new RaspunsStripe($status, $st->antete, $st->corp);
    }
}
