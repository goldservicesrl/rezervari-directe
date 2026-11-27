<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

defined('CAZO') or exit;

/**
 * Un răspuns al API-ului EasyCheck: codul, antetele (numele în litere mici) și corpul brut.
 * Corpul brut se păstrează neatins (în `ec_resurse`), ca un câmp necunoscut să rămână disponibil.
 */
final class RaspunsEc
{
    private mixed $json = false;

    /** @param array<string,string> $antete */
    public function __construct(public readonly int $status, public readonly array $antete, public readonly string $corp)
    {
    }

    public function antet(string $nume): ?string
    {
        return $this->antete[strtolower($nume)] ?? null;
    }

    public function etag(): ?string
    {
        $e = $this->antet('etag');
        return $e !== null && $e !== '' && strlen($e) <= 191 ? $e : null;
    }

    /** Id-ul cererii, din corpul erorii sau din antetul X-Request-Id. */
    public function requestId(): ?string
    {
        $id = $this->eroare()['request_id'] ?? $this->antet('x-request-id');
        return is_string($id) && preg_match('~^req_[0-9A-Za-z]{1,64}$~', $id) ? $id : null;
    }

    /** Corpul decodat, sau null când nu e un obiect JSON. */
    public function json(): ?array
    {
        if ($this->json === false) {
            $d = $this->corp === '' ? null : json_decode($this->corp, true, 64);
            $this->json = is_array($d) ? $d : null;
        }
        return $this->json;
    }

    public function reusit(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Obiectul `eroare` ({cod, mesaj, detalii, request_id}) sau null. */
    public function eroare(): ?array
    {
        if ($this->reusit() || $this->status === 304) {
            return null;
        }
        $e = $this->json()['eroare'] ?? null;
        return is_array($e) ? $e : null;
    }

    public function codEroare(): ?string
    {
        $c = $this->eroare()['cod'] ?? null;
        return is_string($c) && preg_match('~^[a-z_]{1,64}$~', $c) ? $c : null;
    }

    /** Secundele din Retry-After (doar forma numerică, plafonată la o oră), sau null. */
    public function retryAfter(): ?int
    {
        $v = $this->antet('retry-after');
        if ($v === null || !preg_match('~^\d{1,6}$~', trim($v))) {
            return null;
        }
        return min(3600, (int) trim($v));
    }

    /** @return array{limita:?int, ramase:?int, reset:?int} */
    public function rateLimit(): array
    {
        $n = function (string $a): ?int {
            $v = $this->antet($a);
            return $v !== null && preg_match('~^\d{1,9}$~', trim($v)) ? (int) trim($v) : null;
        };
        return ['limita' => $n('ratelimit-limit'), 'ramase' => $n('ratelimit-remaining'), 'reset' => $n('ratelimit-reset')];
    }
}
