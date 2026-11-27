<?php

declare(strict_types=1);

namespace Cazo\DateExterne;

defined('CAZO') or exit;

/**
 * Transportul spre serviciile de date externe (MET Norway, BNR, Nominatim, Overpass): doar GET, doar HTTPS, cu un
 * User-Agent care numește programul și site-ul gazdei, fără e-mail (cerut de MET Norway, Nominatim și Overpass). Implementarea reală e
 * HttpCurl; testele dau o dublură. Aruncă EroareExterna când răspunsul lipsește.
 */
interface Http
{
    /**
     * @param array<string,string> $antete
     * @return array{status:int, antete:array<string,string>, corp:string}
     */
    public function get(string $url, array $antete, float $timp): array;
}
