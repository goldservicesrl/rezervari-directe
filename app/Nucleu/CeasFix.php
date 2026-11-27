<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

defined('CAZO') or exit;

/** Ceas controlat de teste: timpul avansează doar când îl mută testul. */
final class CeasFix extends Ceas
{
    public function __construct(private int $unix)
    {
    }

    public function acum(): int
    {
        return $this->unix;
    }

    public function avanseaza(int $secunde): void
    {
        $this->unix += $secunde;
    }
}
