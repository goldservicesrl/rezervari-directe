<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ArrowFunctionExpression;
use Twig\Node\Expression\Binary\ObjectDestructuringSetBinary;
use Twig\Node\Expression\Binary\RangeBinary;
use Twig\Node\Expression\Binary\SequenceDestructuringSetBinary;
use Twig\Node\Expression\Binary\SetBinary;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Expression\Unary\NegUnary;
use Twig\Node\Expression\Unary\SpreadUnary;
use Twig\Node\IncludeNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\NodeVisitor\NodeVisitorInterface;
use Twig\Template;

defined('CAZO') or exit;

/**
 * Vizitatorul temelor, rulat înaintea sandboxului Twig (prioritatea -10), la compilarea fiecărui șablon:
 *
 *  - `cazo.head()`, `cazo.calendar(camera)` ... devin funcțiile nucleului `cazo_head`, `cazo_calendar` (politica
 *    le permite pe nume; `cazo` nu e o variabilă și nu are metode);
 *  - numele de șabloane rămân statice: `include` și `extends` doar cu un text constant, din `partials/`, `sections/`,
 *    `layout/` sau `@schelet/`;
 *  - fără `_self`, `_context`, `_charset`, fără atribuiri în expresii (`=`), fără funcții-săgeată, fără `sort` cu argument, `replace` doar cu un tablou
 *    literal, `range()` și `..` doar cu limite constante și cel mult 100 de elemente;
 *  - fără operatorul `...` (în liste, în mapări și în argumente): un literal cu multe `...a` și-ar construi tot tabloul
 *    înaintea oricărei verificări a gărzii, iar memoria s-ar epuiza cu o eroare fatală, pe care PHP nu o poate prinde.
 *
 * La fiecare nod verifică și ceasul gărzii (Garda::verifica()), deci compilarea intră în bugetul de 3 s.
 *
 * Fiecare încălcare e o SyntaxError cu fișierul și linia, pentru raportul validatorului.
 */
final class VizitatorSandbox implements NodeVisitorInterface
{
    private const CALE_INCLUDE = '~^(@schelet/)?(partials|sections)/[a-z0-9_\-]+(/[a-z0-9_\-]+)*\.twig$~';
    private const CALE_EXTENDS = '~^(@schelet/)?(layout|partials|sections)/[a-z0-9_\-]+(/[a-z0-9_\-]+)*\.twig$~';
    private const SPECIALE = ['_self', '_context', '_charset'];

    public function enterNode(Node $node, Environment $env): Node
    {
        // Ceasul gărzii și la compilare: primul vizitator al fiecărui nod, înaintea celor care înlocuiesc noduri.
        Garda::verifica();
        if ($node instanceof ModuleNode && $node->hasNode('parent')) {
            $p = $node->getNode('parent');
            if (!$p instanceof ConstantExpression || !is_string($p->getAttribute('value')) || !preg_match(self::CALE_EXTENDS, $p->getAttribute('value'))) {
                $this->refuza($node, 'extends cere un nume static din layout/, partials/, sections/ sau @schelet/');
            }
        }
        if ($node instanceof IncludeNode) {
            $e = $node->getNode('expr');
            if (!$e instanceof ConstantExpression || !is_string($e->getAttribute('value')) || !preg_match(self::CALE_INCLUDE, $e->getAttribute('value'))) {
                $this->refuza($node, 'include cere un nume static din partials/, sections/ sau @schelet/');
            }
        }
        if ($node instanceof NameExpression && in_array($node->getAttribute('name'), self::SPECIALE, true)) {
            $this->refuza($node, 'variabila ' . $node->getAttribute('name') . ' e interzisă');
        }
        if ($node instanceof SetBinary || $node instanceof SequenceDestructuringSetBinary || $node instanceof ObjectDestructuringSetBinary) {
            // Atribuirea din expresii ar ocoli verificarea gărzii de după `set`.
            $this->refuza($node, 'atribuirea în expresii e interzisă; folosiți {% set %}');
        }
        if ($node instanceof SpreadUnary) {
            $this->refuza($node, 'operatorul ... e interzis');
        }
        if ($node instanceof ArrowFunctionExpression) {
            $this->refuza($node, 'funcțiile-săgeată sunt interzise');
        }
        if ($node instanceof FilterExpression) {
            $nume = $node->getAttribute('name');
            $arg = $node->hasNode('arguments') ? $node->getNode('arguments') : null;
            $nrArg = $arg === null ? 0 : count($arg);
            if ($nume === 'sort' && $nrArg > 0) {
                $this->refuza($node, 'sort se folosește fără argument');
            }
            if ($nume === 'replace' && ($nrArg !== 1 || !self::primul($arg) instanceof ArrayExpression)) {
                $this->refuza($node, 'replace cere un tablou literal');
            }
        }
        if ($node instanceof RangeBinary) {
            $this->verificaRange($node, $node->getNode('left'), $node->getNode('right'));
        }
        if ($node instanceof FunctionExpression && $node->getAttribute('name') === 'range') {
            $a = iterator_to_array($node->getNode('arguments'));
            $a = array_values($a);
            if (count($a) < 2 || count($a) > 2) {
                $this->refuza($node, 'range cere exact două limite constante');
            }
            $this->verificaRange($node, $a[0], $a[1]);
        }
        if ($node instanceof GetAttrExpression) {
            $tinta = $node->getNode('node');
            if ($tinta instanceof NameExpression && $tinta->getAttribute('name') === 'cazo') {
                return $this->functiaNucleului($node, $env);
            }
        }
        if ($node instanceof NameExpression && $node->getAttribute('name') === 'cazo') {
            $this->refuza($node, 'cazo se folosește doar ca cazo.functie(...)');
        }
        return $node;
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        return $node;
    }

    public function getPriority(): int
    {
        return -10;
    }

    private function functiaNucleului(GetAttrExpression $node, Environment $env): Node
    {
        $attr = $node->getNode('attribute');
        $nume = $attr instanceof ConstantExpression ? $attr->getAttribute('value') : null;
        if ($node->getAttribute('type') !== Template::METHOD_CALL || !is_string($nume) || !in_array($nume, Politica::FUNCTII_CAZO, true)) {
            $this->refuza($node, 'cazo.' . (is_string($nume) ? $nume : '?') . ' nu e o funcție a nucleului');
        }
        $argumente = [];
        $arg = $node->hasNode('arguments') ? $node->getNode('arguments') : null;
        if ($arg instanceof ArrayExpression) {
            foreach ($arg->getKeyValuePairs() as $p) {
                $argumente[] = $p['value'];
            }
        }
        $f = $env->getFunction('cazo_' . $nume);
        if ($f === null) {
            $this->refuza($node, 'funcția cazo.' . $nume . ' lipsește din nucleu');
        }
        return new FunctionExpression($f, new Nodes($argumente), $node->getTemplateLine());
    }

    private function verificaRange(Node $node, Node $a, Node $b): void
    {
        $x = self::constant($a);
        $y = self::constant($b);
        if ($x === null || $y === null) {
            $this->refuza($node, 'range cere limite constante');
        }
        if (abs($y - $x) + 1 > Politica::RANGE_MAXIM) {
            $this->refuza($node, 'range are mai mult de ' . Politica::RANGE_MAXIM . ' de elemente');
        }
    }

    private static function constant(Node $n): ?int
    {
        if ($n instanceof ConstantExpression && is_int($n->getAttribute('value'))) {
            return $n->getAttribute('value');
        }
        if ($n instanceof NegUnary && $n->getNode('node') instanceof ConstantExpression && is_int($n->getNode('node')->getAttribute('value'))) {
            return -$n->getNode('node')->getAttribute('value');
        }
        return null;
    }

    private static function primul(Node $n): ?Node
    {
        foreach ($n as $x) {
            return $x;
        }
        return null;
    }

    private function refuza(Node $node, string $motiv): never
    {
        throw new SyntaxError($motiv, $node->getTemplateLine(), $node->getSourceContext());
    }
}
