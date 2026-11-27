<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\IncludeNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\TextNode;
use Twig\Node\WithNode;
use Twig\NodeVisitor\NodeVisitorInterface;

defined('CAZO') or exit;

/**
 * Vizitatorul validatorului: adună, pe șablon, textul literal (pentru verificarea HTML),
 * variabilele citite și cele definite (pentru „nicio variabilă în afara modelului de vizualizare”), includerile,
 * funcțiile nucleului folosite și cheile t() literale. Nu schimbă nimic; rulează înaintea vizitatorului sandboxului.
 */
final class VizitatorRaport implements NodeVisitorInterface
{
    /** @var array<string, array{text:list<array{0:string,1:int}>, citite:list<array{0:string,1:int}>, definite:array<string,true>, include:list<array{0:string,1:int}>, cazo:array<string,true>, chei:list<array{0:string,1:int}>, folosite:list<array{0:string,1:string,2:int}>}> */
    public array $date = [];

    private function &pentru(Node $n): array
    {
        $nume = $n->getSourceContext()?->getName() ?? '?';
        $this->date[$nume] ??= ['text' => [], 'citite' => [], 'definite' => [], 'include' => [], 'cazo' => [], 'chei' => [], 'folosite' => []];
        return $this->date[$nume];
    }

    public function enterNode(Node $node, Environment $env): Node
    {
        $d = &$this->pentru($node);
        $linie = $node->getTemplateLine();
        if ($node instanceof TextNode) {
            $d['text'][] = [(string) $node->getAttribute('data'), $linie];
        } elseif ($node instanceof NameExpression) {
            $nume = (string) $node->getAttribute('name');
            if (str_contains(get_class($node), 'Assign')) {
                $d['definite'][$nume] = true;
            } else {
                $d['citite'][] = [$nume, $linie];
            }
        } elseif ($node instanceof IncludeNode) {
            $e = $node->getNode('expr');
            if ($e instanceof ConstantExpression && is_string($e->getAttribute('value'))) {
                $d['include'][] = [$e->getAttribute('value'), $linie];
            }
            if ($node->hasNode('variables')) {
                $this->chei($node->getNode('variables'), $d);
            }
        } elseif ($node instanceof WithNode && $node->hasNode('variables')) {
            $this->chei($node->getNode('variables'), $d);
        } elseif ($node instanceof ModuleNode && $node->hasNode('parent')) {
            $p = $node->getNode('parent');
            if ($p instanceof ConstantExpression && is_string($p->getAttribute('value'))) {
                $d['include'][] = [$p->getAttribute('value'), $linie];
            }
        } elseif ($node instanceof FunctionExpression && $node->getAttribute('name') === 't') {
            foreach ($node->getNode('arguments') as $a) {
                if ($a instanceof ConstantExpression && is_string($a->getAttribute('value'))) {
                    $d['chei'][] = [$a->getAttribute('value'), $linie];
                }
                break;
            }
        }
        // Filtrele, funcțiile, testele și tagurile, pentru verificarea față de politica sandboxului (Politica), cu linia.
        if ($node instanceof \Twig\Node\Expression\FilterExpression) {
            $d['folosite'][] = ['filtru', (string) $node->getAttribute('name'), $linie];
        } elseif ($node instanceof FunctionExpression) {
            $d['folosite'][] = ['functie', (string) $node->getAttribute('name'), $linie];
        } elseif ($node instanceof \Twig\Node\Expression\TestExpression) {
            $d['folosite'][] = ['test', (string) $node->getAttribute('name'), $linie];
        }
        if ($node->getNodeTag() !== null) {
            $d['folosite'][] = ['tag', (string) $node->getNodeTag(), $linie];
        }
        // cazo.head() ... (înaintea rescrierii de către VizitatorSandbox, ca acces la atributul lui `cazo`).
        if ($node instanceof \Twig\Node\Expression\GetAttrExpression) {
            $tinta = $node->getNode('node');
            $attr = $node->getNode('attribute');
            if ($tinta instanceof NameExpression && $tinta->getAttribute('name') === 'cazo' && $attr instanceof ConstantExpression) {
                $d['cazo'][(string) $attr->getAttribute('value')] = true;
            }
        }
        return $node;
    }

    private function chei(Node $v, array &$d): void
    {
        if ($v instanceof ArrayExpression) {
            foreach ($v->getKeyValuePairs() as $p) {
                if ($p['key'] instanceof ConstantExpression) {
                    $d['definite'][(string) $p['key']->getAttribute('value')] = true;
                }
            }
        }
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        return $node;
    }

    public function getPriority(): int
    {
        return -20;
    }
}
