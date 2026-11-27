<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Twig\Environment;
use Twig\Node\CaptureNode;
use Twig\Node\Expression\Binary\ConcatBinary;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\ForNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Node\SetNode;
use Twig\NodeVisitor\NodeVisitorInterface;

defined('CAZO') or exit;

/**
 * Pune verificările gărzii (Garda) în șabloanele temelor, după escapare și sandbox (prioritatea
 * 100), deci analiza de siguranță a escapării vede încă nodurile originale:
 *  - începutul și sfârșitul fiecărui șablon: Garda::intra() și Garda::iese() (adâncimea include/extends);
 *  - corpul fiecărei bucle: Garda::pas();
 *  - fiecare filtru și fiecare `~`: rezultatul prin Garda::valoare();
 *  - după fiecare `set`: variabilele atribuite prin Garda::valoare();
 *  - fiecare captură (corpul lui `{% set x %}...{% endset %}` și al lui `{% apply %}`): GardaCaptura, numărată cât
 *    se adună, în locul lui implode(iterator_to_array()) din Twig.
 */
final class VizitatorGarda implements NodeVisitorInterface
{
    public function enterNode(Node $node, Environment $env): Node
    {
        return $node;
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        if ($node instanceof ModuleNode) {
            $node->setNode('display_start', new Nodes([new GardaApel('intra'), $node->getNode('display_start')]));
            $node->setNode('display_end', new Nodes([new GardaApel('iese'), $node->getNode('display_end')]));
            return $node;
        }
        if ($node instanceof ForNode) {
            $node->setNode('body', new Nodes([new GardaApel('pas', [], $node->getTemplateLine()), $node->getNode('body')]));
            return $node;
        }
        if ($node instanceof CaptureNode && !$node instanceof GardaCaptura) {
            return GardaCaptura::din($node);
        }
        if ($node instanceof FilterExpression || $node instanceof ConcatBinary) {
            return new GardaValoare($node);
        }
        if ($node instanceof SetNode) {
            $nume = [];
            $n = $node->getNode('names');
            foreach ($n->hasAttribute('name') ? [$n] : $n as $x) {
                if ($x->hasAttribute('name')) {
                    $nume[] = (string) $x->getAttribute('name');
                }
            }
            return new Nodes([$node, new GardaApel('valoare', $nume, $node->getTemplateLine())]);
        }
        return $node;
    }

    public function getPriority(): int
    {
        return 100;
    }
}
