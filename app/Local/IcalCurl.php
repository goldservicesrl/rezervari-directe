<?php

declare(strict_types=1);

namespace Cazo\Local;

defined('CAZO') or exit;

/**
 * GET-ul real al calendarelor iCal: doar HTTPS, TLS verificat, IP-ul fixat cu CURLOPT_RESOLVE pe cel verificat,
 * fără redirecționări automate (Calendare le urmează, verificate, cel mult 3), conectarea în 5 secunde, toată cererea în
 * 10, corpul plafonat la 2 MB (oprit pe loc la depășire), gzip acceptat.
 */
final class IcalCurl implements IcalHttp
{
    public const TIMP = 10.0;

    public function __construct(private string $agent)
    {
    }

    public function get(array $adresa, array $antete): array
    {
        if (!function_exists('curl_init') || !function_exists('curl_exec')) {
            throw new EroareIcal('retea', 'cURL e oprit de găzduire.');
        }
        $h = curl_init($adresa['url']);
        if ($h === false) {
            throw new EroareIcal('retea');
        }
        $linii = [];
        foreach ($antete as $k => $v) {
            $linii[] = $k . ': ' . $v;
        }
        $ip = str_contains($adresa['ip'], ':') ? '[' . $adresa['ip'] . ']' : $adresa['ip'];
        $st = new \stdClass();
        $st->antete = [];
        $st->corp = '';
        $st->prea = false;
        curl_setopt_array($h, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $linii,
            CURLOPT_USERAGENT => $this->agent,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$adresa['gazda'] . ':' . $adresa['port'] . ':' . $ip],
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_TIMEOUT_MS => (int) (self::TIMP * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use ($st): int {
                $p = explode(':', $linie, 2);
                if (count($p) === 2) {
                    $st->antete[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($linie);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $bucata) use ($st): int {
                if (strlen($st->corp) + strlen($bucata) > Ical::MARIME_MAXIMA) {
                    $st->prea = true;
                    return 0;
                }
                $st->corp .= $bucata;
                return strlen($bucata);
            },
        ]);
        // Verificat la începutul funcției; repetat aici, lângă apel.
        $ok = function_exists('curl_exec') ? curl_exec($h) : false;
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_close($h);
        if ($st->prea) {
            throw new EroareIcal('marime');
        }
        if ($ok === false) {
            throw new EroareIcal('retea');
        }
        return ['status' => $status, 'antete' => $st->antete, 'corp' => $st->corp];
    }
}
