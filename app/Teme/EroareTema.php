<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/** Refuzul unei teme încărcate, cu un motiv scurt (`nume`, `marime`, `symlink` ...) pentru jurnal și pentru teste. */
final class EroareTema extends \RuntimeException
{
    public function __construct(public readonly string $motiv, string $mesaj)
    {
        parent::__construct($mesaj);
    }
}
