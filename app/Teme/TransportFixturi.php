<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Cazo\EasyCheck\RaspunsEc;
use Cazo\EasyCheck\Transport;

defined('CAZO') or exit;

/** Transportul randării de probă: răspunde din FixturiTeme, fără rețea; orice scriere primește 403. */
final class TransportFixturi implements Transport
{
    public function __construct(private string $varianta)
    {
    }

    public function trimite(string $metoda, string $url, array $antete, ?string $corp, array $limite): RaspunsEc
    {
        $u = parse_url($url);
        parse_str($u['query'] ?? '', $q);
        $cale = (string) ($u['path'] ?? '');
        $res = substr($cale, (int) strrpos($cale, '/') + 1);
        $de = is_string($q['de'] ?? null) ? $q['de'] : gmdate('Y-m-d');
        $pana = is_string($q['pana'] ?? null) ? $q['pana'] : gmdate('Y-m-d', time() + 30 * 86400);
        $v = $this->varianta;
        $d = $metoda !== 'GET' ? null : match ($res) {
            'eu' => FixturiTeme::eu($v),
            'proprietate' => FixturiTeme::proprietate($v),
            'camere' => FixturiTeme::camere($v),
            'disponibilitate' => FixturiTeme::disponibilitate($v, $de, $pana),
            'preturi' => FixturiTeme::preturi($v, $de, $pana),
            default => null,
        };
        $a = ['x-request-id' => 'req_' . str_repeat('P', 24), 'content-type' => 'application/json'];
        if ($d === null) {
            return new RaspunsEc(403, $a, (string) json_encode(['eroare' => ['cod' => 'proba', 'mesaj' => 'Randarea de probă nu scrie nimic.', 'request_id' => $a['x-request-id']]]));
        }
        return new RaspunsEc(200, $a, (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
