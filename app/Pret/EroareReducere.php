<?php

declare(strict_types=1);

namespace Cazo\Pret;

defined('CAZO') or exit;

/** O reducere locală greșită sau o scriere care cere alegerea gazdei; codul alege textul din panou. */
final class EroareReducere extends \InvalidArgumentException
{
    public function __construct(public readonly string $cod)
    {
        parent::__construct('Reducere: ' . $cod);
    }
}
