<?php

declare(strict_types=1);

namespace Cazo\DateExterne;

defined('CAZO') or exit;

/**
 * GET prin cURL spre serviciile de date externe: TLS verificat, cel mult 3 redirecționări, tot pe HTTPS (MET Norway le
 * cere), corpul plafonat la 2 MB, conectarea în 5 secunde, gzip. Rulează doar din joburi și din panou, niciodată la cererea unui vizitator.
 */
final class HttpCurl implements Http
{
    public const MAXIM = 2 * 1024 * 1024;

    public function __construct(private string $agent)
    {
    }

    public function get(string $url, array $antete, float $timp): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new EroareExterna('Doar adrese HTTPS.');
        }
        if (!function_exists('curl_exec') || !function_exists('curl_init')) {
            throw new EroareExterna('cURL e oprit de găzduire.');
        }
        $h = curl_init($url);
        if ($h === false) {
            throw new EroareExterna('cURL nu a pornit.');
        }
        $linii = [];
        foreach ($antete as $k => $v) {
            $linii[] = $k . ': ' . $v;
        }
        $st = new \stdClass();
        $st->antete = [];
        $st->corp = '';
        $st->prea = false;
        curl_setopt_array($h, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $linii,
            CURLOPT_USERAGENT => $this->agent,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_TIMEOUT_MS => (int) ($timp * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
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
        ]);
        $ok = function_exists('curl_exec') ? curl_exec($h) : false;
        $errno = curl_errno($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_close($h);
        if ($st->prea) {
            throw new EroareExterna('Răspuns prea mare.');
        }
        if ($ok === false || $status === 0) {
            throw new EroareExterna('Serviciul n-a răspuns (cURL ' . $errno . ').');
        }
        return ['status' => $status, 'antete' => $st->antete, 'corp' => $st->corp];
    }
}
