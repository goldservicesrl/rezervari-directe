<?php

declare(strict_types=1);

namespace Cazo\Plati;

defined('CAZO') or exit;

/**
 * Transportul HTTP al clientului Stripe. Implementarea reală e HttpCurl; testele dau dublura
 * Stripe din teste/lib/FalsStripe.php. Aruncă EroareStripe cu felul „retea” sau „timeout” când răspunsul lipsește.
 */
interface Http
{
    /**
     * @param array<string,string> $antete
     * @param array{conectare:float, total:float} $limite
     */
    public function trimite(string $metoda, string $url, array $antete, ?string $corp, array $limite): RaspunsStripe;
}
