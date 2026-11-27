<?php

declare(strict_types=1);

// Browserul minimal al probelor HTTP: cookie-urile, fără urmarea redirecționărilor.

defined('CAZO') or exit;

/** Un browser minimal: cookie-urile primite (toate antetele Set-Cookie), fără urmarea redirecționărilor. */
final class BrowserTest
{
    public array $cookies = [];

    public function __construct(private string $baza)
    {
    }

    /** @return array{status:int, antete:array<string,list<string>>, corp:string} */
    public function cere(string $metoda, string $cale, array $campuri = []): array
    {
        $h = curl_init($this->baza . $cale);
        $antete = [];
        $cookie = implode('; ', array_map(static fn (string $k, string $v): string => "$k=$v", array_keys($this->cookies), $this->cookies));
        curl_setopt_array($h, [
            CURLOPT_CUSTOMREQUEST => $metoda,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array_filter(['User-Agent: BrowserTest/1.0', 'Accept-Language: ro-RO', $cookie !== '' ? 'Cookie: ' . $cookie : null]),
            CURLOPT_HEADERFUNCTION => static function ($c, string $l) use (&$antete): int {
                $p = explode(':', $l, 2);
                if (count($p) === 2) {
                    $antete[strtolower(trim($p[0]))][] = trim($p[1]);
                }
                return strlen($l);
            },
        ]);
        if ($metoda === 'POST') {
            curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($campuri));
        }
        $corp = (string) curl_exec($h);
        $st = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_close($h);
        foreach ($antete['set-cookie'] ?? [] as $c) {
            [$nv] = explode(';', $c, 2);
            [$n, $v] = explode('=', $nv, 2);
            if ($v === '') {
                unset($this->cookies[$n]);
            } else {
                $this->cookies[$n] = $v;
            }
        }
        return ['status' => $st, 'antete' => $antete, 'corp' => $corp];
    }
}
