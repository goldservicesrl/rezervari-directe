<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\CaptureNode;

defined('CAZO') or exit;

/**
 * Captura numărată a temelor, pusă de VizitatorGarda în locul fiecărui CaptureNode din șablon:
 * corpul lui `{% set x %}...{% endset %}` și al lui `{% apply f %}...{% endapply %}`.
 *
 * Twig compilează captura ca implode('', iterator_to_array(corp)): bucățile se adună întâi într-un tablou, fără
 * nicio verificare, iar o bucată Markup costă în tablou doar o referință, oricât de lungă ar fi. Un corp care
 * repetă de 900 de ori un Markup de 160 KB ajunge astfel la un implode de 144 MB, cu o eroare fatală de memorie.
 * Aici bucățile trec prin Garda::captura(), care le numără mărimea reală (inclusiv a Markup-ului) cât se adună și
 * oprește captura la Garda::VALOARE octeți, înainte de lipire.
 *
 * Compilarea e cea a lui CaptureNode din Twig 3.30 (cu use_yield), cu Garda::captura() în locul lui
 * implode(iterator_to_array()).
 */
#[YieldReady]
final class GardaCaptura extends CaptureNode
{
    public static function din(CaptureNode $c): self
    {
        $n = new self($c->getNode('body'), $c->getTemplateLine());
        $n->setAttribute('raw', $c->getAttribute('raw'));
        $n->setSourceContext($c->getSourceContext());
        return $n;
    }

    public function compile(Compiler $compiler): void
    {
        if (!$compiler->getEnvironment()->useYield()) {
            // Mediul temelor randează doar cu yield (MediuSandbox); fără el, compilarea rămâne cea a lui Twig.
            parent::compile($compiler);
            return;
        }
        if (!$this->getAttribute('raw')) {
            $compiler->raw("('' === \$tmp = ");
        }
        $compiler
            ->raw('\\' . Garda::class . '::captura(')
            ->raw("(function () use (&\$context, \$macros, \$blocks) {\n")
            ->indent()
            ->subcompile($this->getNode('body'))
            ->write("return; yield;\n")
            ->outdent()
            ->write('})())');
        if (!$this->getAttribute('raw')) {
            $compiler->raw(") ? '' : new Markup(\$tmp, \$this->env->getCharset());");
        } else {
            $compiler->raw(';');
        }
    }
}
