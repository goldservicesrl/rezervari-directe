<?php

declare(strict_types=1);

namespace Cazo\Instalare;

defined('CAZO') or exit;

/**
 * Cererile HTTP ale instalatorului (verificările și sonda din pasul verificărilor), cu cURL: TLS verificat, fără
 * urmarea redirecționărilor (o redirecționare e un răspuns în afară de 403/404 și oprește sonda), corp limitat.
 * Testele dau în locul ei o funcție cu aceeași formă.
 */
final class Http
{
    public const CORP_MAXIM = 65536;

    /** @return \Closure(string): array{status:?int, corp:string, eroare:?string} */
    public static function curl(int $timeout = 10): \Closure
    {
        return static function (string $url) use ($timeout): array {
            if (!function_exists('curl_exec') || !function_exists('curl_init')) {
                return ['status' => null, 'corp' => '', 'eroare' => 'curl'];
            }
            $h = curl_init($url);
            $corp = '';
            curl_setopt_array($h, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'Cazo',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($c, string $bucata) use (&$corp): int {
                    if (strlen($corp) < self::CORP_MAXIM) {
                        $corp .= substr($bucata, 0, self::CORP_MAXIM - strlen($corp));
                    }
                    return strlen($bucata);
                },
            ]);
            $ok = function_exists('curl_exec') ? curl_exec($h) : false;
            $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            $eroare = $ok === false ? (curl_errno($h) === CURLE_SSL_CACERT || curl_errno($h) === CURLE_SSL_CONNECT_ERROR || curl_errno($h) === 60 ? 'tls' : 'retea') : null;
            curl_close($h);
            return ['status' => $ok === false || $status === 0 ? null : $status, 'corp' => $corp, 'eroare' => $eroare];
        };
    }
}
