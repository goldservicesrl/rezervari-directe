<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Twig\Compiler;
use Twig\Node\Expression\AbstractExpression;

defined('CAZO') or exit;

/** O expresie a temei (filtru, `~`) trecută prin Garda::valoare(), care o întoarce neschimbată sau oprește randarea. */
final class GardaValoare extends AbstractExpression
{
    public function __construct(AbstractExpression $expr)
    {
        parent::__construct(['expr' => $expr], [], $expr->getTemplateLine());
    }

    public function compile(Compiler $compiler): void
    {
        $compiler->raw('\\' . Garda::class . '::valoare(')->subcompile($this->getNode('expr'))->raw(')');
    }
}
