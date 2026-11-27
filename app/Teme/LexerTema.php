<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Twig\Error\SyntaxError;
use Twig\Lexer;
use Twig\Source;
use Twig\Token;
use Twig\TokenStream;

defined('CAZO') or exit;

/**
 * Lexerul temelor: marginește compilarea unui șablon înaintea parserului.
 *
 * Compilarea (parserul Twig, vizitatorii, eval-ul codului PHP generat) rulează înaintea primului punct de verificare
 * al gărzii din codul compilat, iar costul ei crește mai repede decât liniar cu adâncimea imbricării: Twig reface
 * contextul sursei pe tot subarborele la fiecare nod înlocuit, iar compilatorul PHP are propriul plafon de imbricare.
 * Un șablon de 30 KB cu 1200 de `{% apply upper %}` imbricate, ori cu un lanț de mii de `|upper` sau de `~`,
 * compila zeci de secunde, peste regula celor 3 s. Lexerul Twig e liniar; după el, aici, înaintea parserului:
 *
 *  - mărimea sursei: cel mult SURSA octeți și cel mult JETOANE jetoane (textul dintre taguri, numele, operatorii,
 *    literalii); costul compilării crește cu numărul lor, nu cu octeții;
 *  - operatorul `...`: refuzat (ca în VizitatorSandbox), aici înaintea parserului;
 *  - tagurile: doar cele din Politica::TAGURI (cu `else`, `elseif` și `end...`), deci un tag interzis se oprește
 *    înaintea parserului;
 *  - imbricarea tagurilor cu corp (`if`, `for`, `apply`, `with`, `block` fără expresie, `set` fără `=`): cel mult
 *    IMBRICARE niveluri;
 *  - parantezele `(`, `[`, `{` și interpolările `#{` deschise una în alta: cel mult PARANTEZE niveluri;
 *  - legăturile dintr-un singur tag (operatorii, filtrele `|`, accesele `.` și `?.`, apelurile și indicii): cel mult
 *    LANT, deci un lanț de filtre sau de `~` rămâne scurt.
 *
 * Cu acestea, adâncimea arborelui unui șablon rămâne mică, iar compilarea lui e aproape liniară în mărime. Timpul
 * total al compilării (mai multe șabloane în aceeași cerere) îl măsoară garda: Garda::verifica() înainte și după fiecare
 * șablon citit aici și la fiecare nod vizitat (VizitatorSandbox).
 *
 * Valorile numerice sunt alese pentru această versiune.
 */
final class LexerTema extends Lexer
{
    public const SURSA = 64 * 1024;
    public const IMBRICARE = 32;
    public const PARANTEZE = 32;
    public const JETOANE = 4000;
    public const LANT = 100;

    /** Tagurile cu corp, închise de `end<nume>`. */
    private const CU_CORP = ['if', 'for', 'apply', 'with', 'block', 'set'];

    public function tokenize(Source $source): TokenStream
    {
        Garda::verifica();
        $n = strlen($source->getCode());
        if ($n > self::SURSA) {
            throw new SyntaxError('șablonul are ' . $n . ' octeți (cel mult ' . self::SURSA . ')', 1, $source);
        }
        $flux = parent::tokenize($source);
        $jetoane = [];
        while (!$flux->isEOF()) {
            $jetoane[] = $flux->next();
        }
        $jetoane[] = $flux->getCurrent();
        self::verifica($jetoane, $source);
        if (count($jetoane) > self::JETOANE) {
            throw new SyntaxError('șablonul are ' . count($jetoane) . ' de jetoane (cel mult ' . self::JETOANE . ')', 1, $source);
        }
        Garda::verifica();
        return new TokenStream($jetoane, $source);
    }

    /** @param list<Token> $jetoane */
    private static function verifica(array $jetoane, Source $source): void
    {
        $imbricare = 0;
        $paranteze = 0;
        $lant = 0;
        $total = count($jetoane);
        for ($i = 0; $i < $total; $i++) {
            $j = $jetoane[$i];
            $tip = $j->getType();
            if ($tip === Token::BLOCK_START_TYPE || $tip === Token::VAR_START_TYPE) {
                $lant = 0;
            }
            if ($tip === Token::BLOCK_START_TYPE) {
                $nume = $jetoane[$i + 1] ?? null;
                if ($nume === null || $nume->getType() !== Token::NAME_TYPE) {
                    continue;
                }
                $tag = (string) $nume->getValue();
                if (str_starts_with($tag, 'end')) {
                    $imbricare = max(0, $imbricare - 1);
                } elseif (in_array($tag, self::CU_CORP, true) && self::areCorp($tag, $jetoane, $i + 2)) {
                    if (++$imbricare > self::IMBRICARE) {
                        self::refuza('tagurile se imbrică pe mai mult de ' . self::IMBRICARE . ' de niveluri', $j, $source);
                    }
                } elseif (!in_array($tag, Politica::TAGURI, true) && $tag !== 'else' && $tag !== 'elseif') {
                    self::refuza('tagul ' . $tag . ' e interzis', $j, $source);
                }
                continue;
            }
            $v = $j->getValue();
            if ($tip === Token::SPREAD_TYPE || ($tip === Token::OPERATOR_TYPE && $v === '...')) {
                // Și în VizitatorSandbox; aici, înaintea parserului.
                self::refuza('operatorul ... e interzis', $j, $source);
            }
            // Twig 3.30 dă `(`, `[`, `.`, `|` ca operatori (infix), iar `{`, `)`, `]`, `}` ca punctuație.
            $semn = $tip === Token::OPERATOR_TYPE || $tip === Token::PUNCTUATION_TYPE;
            if ($tip === Token::INTERPOLATION_START_TYPE || ($semn && ($v === '(' || $v === '[' || $v === '{'))) {
                if (++$paranteze > self::PARANTEZE) {
                    self::refuza('parantezele se deschid una în alta pe mai mult de ' . self::PARANTEZE . ' de niveluri', $j, $source);
                }
            } elseif ($tip === Token::INTERPOLATION_END_TYPE || ($semn && ($v === ')' || $v === ']' || $v === '}'))) {
                $paranteze = max(0, $paranteze - 1);
            }
            $legatura = $tip === Token::OPERATOR_TYPE
                || ($tip === Token::PUNCTUATION_TYPE && in_array($v, ['|', '.', '?.', '(', '['], true));
            if ($legatura && ++$lant > self::LANT) {
                self::refuza('un tag are peste ' . self::LANT . ' de operatori, filtre și accese', $j, $source);
            }
        }
    }

    /**
     * Dacă tagul de la poziția $i (jetonul de după nume) are corp: `block` doar fără expresie după nume
     * (`{% block x %}`, nu `{% block x "text" %}`), `set` doar fără `=` (captura); celelalte, mereu.
     * @param list<Token> $jetoane
     */
    private static function areCorp(string $tag, array $jetoane, int $i): bool
    {
        if ($tag === 'block') {
            return ($jetoane[$i + 1] ?? null)?->getType() === Token::BLOCK_END_TYPE;
        }
        if ($tag === 'set') {
            for ($k = $i, $n = count($jetoane); $k < $n && $jetoane[$k]->getType() !== Token::BLOCK_END_TYPE; $k++) {
                if ($jetoane[$k]->test(Token::OPERATOR_TYPE, '=') || $jetoane[$k]->test(Token::PUNCTUATION_TYPE, '=')) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function refuza(string $motiv, Token $j, Source $source): never
    {
        throw new SyntaxError($motiv, $j->getLine(), $source);
    }
}
