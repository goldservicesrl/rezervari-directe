<?php

declare(strict_types=1);

namespace Cazo\Plati;

defined('CAZO') or exit;

/**
 * Stripe a răspuns cu o eroare ($felul = „api”, cu tipul și codul din corp), sau răspunsul lipsește ($felul =
 * „retea” / „timeout”, $status = 0). Mesajul nu conține cheia; jurnalele îl primesc mascat oricum.
 */
final class EroareStripe extends \RuntimeException
{
    public function __construct(
        string $mesaj,
        public readonly string $felul = 'api',
        public readonly int $status = 0,
        public readonly ?string $tip = null,
        public readonly ?string $cod = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($mesaj);
    }

    public static function din(RaspunsStripe $r, string $operatie): self
    {
        $e = $r->json()['error'] ?? [];
        $tip = is_string($e['type'] ?? null) ? $e['type'] : null;
        $cod = is_string($e['code'] ?? null) ? $e['code'] : null;
        return new self($operatie . ': ' . $r->status . ' ' . ($tip ?? '-') . '/' . ($cod ?? '-'), 'api', $r->status, $tip, $cod, $r->requestId());
    }

    /**
     * Fără răspuns, 5xx, 429 sau 409 (o cerere concurentă pe aceeași cheie de idempotență, încă în lucru la Stripe):
     * cererea se reia cu aceeași cheie, deci se recitește rezultatul. Cheia lipsă, cheia respinsă (401), permisiunea
     * lipsă (403) și forma neașteptată sunt definitive: reluarea n-ar schimba nimic.
     */
    public function tranzitorie(): bool
    {
        return in_array($this->felul, ['retea', 'timeout'], true) || $this->status >= 500 || $this->status === 429 || $this->status === 409;
    }

    /** Cheia nu are permisiunea cerută (cheia restricționată). */
    public function permisiune(): bool
    {
        return $this->status === 403 || $this->tip === 'invalid_request_error' && $this->cod === 'secret_key_required';
    }
}
