<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

defined('CAZO') or exit;

/**
 * EasyCheck n-a răspuns: timeout, legătură eșuată, TLS, răspuns prea mare, sau pauza cerută prin Retry-After
 * încă în curs ($felul = 'pauza'). Pentru scrieri: se reia cu aceeași Idempotency-Key și același corp.
 */
final class EroareTransport extends \RuntimeException
{
    public function __construct(string $mesaj, public readonly string $felul = 'retea', public readonly ?int $reiaDupa = null)
    {
        parent::__construct($mesaj);
    }
}
