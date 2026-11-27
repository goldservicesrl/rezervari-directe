<?php

declare(strict_types=1);

// Transportul EasyCheck al testelor unitare: răspunsuri puse în ordine (sau o funcție) și cererile trimise, ținute minte.

use Cazo\EasyCheck\EroareTransport;
use Cazo\EasyCheck\RaspunsEc;
use Cazo\EasyCheck\Transport;

defined('CAZO') or exit;

final class TransportTest implements Transport
{
    /** @var list<array{metoda:string, url:string, antete:array, corp:?string, limite:array}> */
    public array $cereri = [];

    /** @var list<RaspunsEc|\Throwable|\Closure> */
    private array $coada = [];

    public ?\Closure $implicit = null;

    public static function raspuns(int $status, mixed $corp = null, array $antete = []): RaspunsEc
    {
        $a = [];
        foreach ($antete as $k => $v) {
            $a[strtolower($k)] = (string) $v;
        }
        $a += ['x-request-id' => 'req_' . str_repeat('A', 24)];
        return new RaspunsEc($status, $a, is_string($corp) ? $corp : ($corp === null ? '' : json_encode($corp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    public static function eroare(int $status, string $cod, array $antete = [], ?array $detalii = null): RaspunsEc
    {
        $e = ['cod' => $cod, 'mesaj' => 'probă', 'request_id' => 'req_' . str_repeat('E', 24)];
        if ($detalii !== null) {
            $e['detalii'] = $detalii;
        }
        return self::raspuns($status, ['eroare' => $e], $antete);
    }

    public function pune(RaspunsEc|\Throwable|\Closure ...$r): self
    {
        array_push($this->coada, ...$r);
        return $this;
    }

    public function trimite(string $metoda, string $url, array $antete, ?string $corp, array $limite): RaspunsEc
    {
        $this->cereri[] = ['metoda' => $metoda, 'url' => $url, 'antete' => $antete, 'corp' => $corp, 'limite' => $limite];
        $r = array_shift($this->coada) ?? $this->implicit ?? throw new EroareTransport('Nicio cerere așteptată: ' . $metoda . ' ' . $url, 'retea');
        if ($r instanceof \Closure) {
            $r = $r($metoda, $url, $antete, $corp);
        }
        if ($r instanceof \Throwable) {
            throw $r;
        }
        return $r;
    }

    public function ultima(): array
    {
        return $this->cereri[count($this->cereri) - 1] ?? [];
    }
}
