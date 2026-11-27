<?php

declare(strict_types=1);

namespace Cazo\Plati;

defined('CAZO') or exit;

/** Un răspuns Stripe: codul, antetele (numele în litere mici) și corpul JSON. */
final class RaspunsStripe
{
    /** @param array<string,string> $antete */
    public function __construct(public readonly int $status, public readonly array $antete, public readonly string $corp)
    {
    }

    public function json(): array
    {
        $d = $this->corp === '' ? null : json_decode($this->corp, true, 64);
        return is_array($d) ? $d : [];
    }

    public function reusit(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function requestId(): ?string
    {
        $id = $this->antete['request-id'] ?? null;
        return is_string($id) && preg_match('~^req_[0-9A-Za-z]{1,64}$~', $id) ? $id : null;
    }
}
