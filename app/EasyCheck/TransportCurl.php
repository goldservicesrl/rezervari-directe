<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

defined('CAZO') or exit;

/**
 * Transportul prin cURL: TLS verificat, fără redirecționări, doar HTTPS (HTTP doar în mediul de test,
 * spre serverele false), corpul răspunsului plafonat. Citirile paralele (totalurile căutării) merg prin
 * curl_multi, cel mult 8 deodată.
 */
final class TransportCurl implements Transport, TransportMultiplu
{
    public const PARALEL_MAXIM = 8;

    public function __construct(private bool $permiteHttp = false, private string $agent = 'Cazo')
    {
    }

    public function trimite(string $metoda, string $url, array $antete, ?string $corp, array $limite): RaspunsEc
    {
        [$h, $st] = $this->pregateste($metoda, $url, $antete, $corp, $limite);
        $ok = function_exists('curl_exec') ? curl_exec($h) : false;
        return $this->rezultat($h, $st, $ok !== false, curl_errno($h), curl_error($h));
    }

    public function trimiteMulte(array $cereri, array $limite): array
    {
        $rez = [];
        // Fără cererile paralele (curl_multi sau cURL oprite de găzduire), cererile pleacă pe rând, fiecare cu
        // eroarea ei.
        if (!function_exists('curl_multi_exec') || !function_exists('curl_multi_init') || !function_exists('curl_exec') || !function_exists('curl_init')) {
            foreach ($cereri as $k => $c) {
                try {
                    $rez[$k] = $this->trimite('GET', $c['url'], $c['antete'], null, $limite);
                } catch (EroareTransport $e) {
                    $rez[$k] = $e;
                }
            }
            return $rez;
        }
        foreach (array_chunk($cereri, self::PARALEL_MAXIM, true) as $pachet) {
            $m = curl_multi_init();
            $h = [];
            foreach ($pachet as $k => $c) {
                $h[$k] = $this->pregateste('GET', $c['url'], $c['antete'], null, $limite);
                curl_multi_add_handle($m, $h[$k][0]);
            }
            do {
                $stare = curl_multi_exec($m, $active);
                if ($active) {
                    curl_multi_select($m, 0.2);
                }
            } while ($active && $stare === CURLM_OK);
            $erori = [];
            while (($info = curl_multi_info_read($m)) !== false) {
                foreach ($h as $k => [$handle]) {
                    if ($handle === $info['handle']) {
                        $erori[$k] = (int) $info['result'];
                    }
                }
            }
            foreach ($h as $k => [$handle, $st]) {
                $errno = $erori[$k] ?? CURLE_OPERATION_TIMEDOUT;
                try {
                    $rez[$k] = $this->rezultat($handle, $st, $errno === CURLE_OK, $errno, curl_error($handle));
                } catch (EroareTransport $e) {
                    $rez[$k] = $e;
                }
                curl_multi_remove_handle($m, $handle);
            }
            curl_multi_close($m);
        }
        return $rez;
    }

    /** @return array{0:\CurlHandle, 1:\stdClass} */
    private function pregateste(string $metoda, string $url, array $antete, ?string $corp, array $limite): array
    {
        // Fără curl_init sau curl_exec (oprite de găzduire): aceeași eroare ca o rețea căzută.
        if (!function_exists('curl_exec') || !function_exists('curl_init')) {
            throw new EroareTransport('cURL e oprit pe server.');
        }
        $h = curl_init($url);
        if ($h === false) {
            throw new EroareTransport('cURL nu a pornit.');
        }
        $linii = [];
        foreach ($antete as $k => $v) {
            $linii[] = $k . ': ' . $v;
        }
        // Fără „Expect: 100-continue”: o așteptare în plus la fiecare scriere.
        $linii[] = 'Expect:';
        $st = new \stdClass();
        $st->antete = [];
        $st->corp = '';
        $st->prea = false;
        $max = (int) $limite['max_octeti'];
        $optiuni = [
            CURLOPT_CUSTOMREQUEST => $metoda,
            CURLOPT_HTTPHEADER => $linii,
            CURLOPT_USERAGENT => $this->agent,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $this->permiteHttp ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($limite['conectare'] * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($limite['total'] * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use ($st): int {
                if (preg_match('~^HTTP/\S+\s+\d{3}~', $linie)) {
                    $st->antete = [];
                }
                $p = explode(':', $linie, 2);
                if (count($p) === 2) {
                    $st->antete[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($linie);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $bucata) use ($st, $max): int {
                if (strlen($st->corp) + strlen($bucata) > $max) {
                    $st->prea = true;
                    return 0;
                }
                $st->corp .= $bucata;
                return strlen($bucata);
            },
        ];
        if ($metoda === 'HEAD') {
            $optiuni[CURLOPT_NOBODY] = true;
        }
        if ($corp !== null) {
            $optiuni[CURLOPT_POSTFIELDS] = $corp;
        }
        curl_setopt_array($h, $optiuni);
        return [$h, $st];
    }

    private function rezultat(\CurlHandle $h, \stdClass $st, bool $ok, int $errno, string $mesaj): RaspunsEc
    {
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_close($h);
        if ($st->prea) {
            throw new EroareTransport('Răspuns prea mare.', 'marime');
        }
        if (!$ok || $status === 0) {
            $fel = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'retea';
            throw new EroareTransport('EasyCheck nu a răspuns (' . $fel . ', cURL ' . $errno . '): ' . $mesaj, $fel);
        }
        return new RaspunsEc($status, $st->antete, $st->corp);
    }
}
