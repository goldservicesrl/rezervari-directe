<?php

declare(strict_types=1);

namespace Cazo\Local;

defined('CAZO') or exit;

/**
 * Transportul calendarelor iCal: un GET spre o adresă deja verificată (AdresaSigura), cu IP-ul fixat. Implementarea
 * reală e IcalCurl; testele dau o dublură. Aruncă EroareIcal (`retea`, `marime`) când răspunsul lipsește sau e prea mare.
 */
interface IcalHttp
{
    /**
     * @param array{url:string, gazda:string, port:int, ip:string} $adresa
     * @param array<string,string> $antete
     * @return array{status:int, antete:array<string,string>, corp:string}
     */
    public function get(array $adresa, array $antete): array;
}
