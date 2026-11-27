<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

defined('CAZO') or exit;

/**
 * Transportul HTTP al clientului. Implementarea reală e TransportCurl; testele unitare dau o funcție.
 * Aruncă EroareTransport la timeout, la o legătură eșuată sau la un răspuns prea mare.
 */
interface Transport
{
    /**
     * @param array<string,string> $antete
     * @param array{conectare:float, total:float, max_octeti:int} $limite
     */
    public function trimite(string $metoda, string $url, array $antete, ?string $corp, array $limite): RaspunsEc;
}
