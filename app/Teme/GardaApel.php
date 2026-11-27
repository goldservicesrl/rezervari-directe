<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Node;

defined('CAZO') or exit;

/**
 * Un apel al gărzii pus de VizitatorGarda în șablonul compilat: `pas` (fiecare pas de buclă), `intra` și `iese`
 * (începutul și sfârșitul unui șablon), sau `valoare` pe variabilele tocmai atribuite cu `set` ($variabile).
 */
#[YieldReady]
final class GardaApel extends Node
{
    /** @param list<string> $variabile */
    public function __construct(string $metoda, array $variabile = [], int $linie = 0)
    {
        parent::__construct([], ['metoda' => $metoda, 'variabile' => $variabile], $linie);
    }

    public function compile(Compiler $compiler): void
    {
        $m = $this->getAttribute('metoda');
        if ($m === 'valoare') {
            foreach ($this->getAttribute('variabile') as $v) {
                $compiler->write('\\' . Garda::class . '::valoare($context[')->repr($v)->raw("] ?? null);\n");
            }
            return;
        }
        $compiler->write('\\' . Garda::class . '::' . $m . "();\n");
    }
}
