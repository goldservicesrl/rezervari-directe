<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

defined('CAZO') or exit;

/** Transportul care trimite mai multe citiri deodată (totalurile căutării). */
interface TransportMultiplu
{
    /**
     * @param array<int|string, array{url:string, antete:array<string,string>}> $cereri
     * @param array{conectare:float, total:float, max_octeti:int} $limite
     * @return array<int|string, RaspunsEc|EroareTransport> în aceeași ordine de chei
     */
    public function trimiteMulte(array $cereri, array $limite): array;
}
