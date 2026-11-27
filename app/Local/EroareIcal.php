<?php

declare(strict_types=1);

namespace Cazo\Local;

defined('CAZO') or exit;

/**
 * Citirea unui calendar iCal a eșuat; `cod` spune de ce (adresa, schema, gazda, dns, retea, http, marime, forma,
 * evenimente, redirectari), iar panoul arată textul potrivit (`admin.local.ical.eroare.<cod>`).
 */
final class EroareIcal extends \RuntimeException
{
    public const CODURI = ['adresa', 'schema', 'gazda', 'dns', 'retea', 'http', 'marime', 'forma', 'evenimente', 'redirectari'];

    public function __construct(public readonly string $cod, ?string $mesaj = null)
    {
        parent::__construct($mesaj ?? $cod);
    }
}
