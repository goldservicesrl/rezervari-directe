<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Twig\Sandbox\SecurityPolicy;

defined('CAZO') or exit;

/**
 * Politica sandboxului pentru temele: liste închise de taguri, filtre, funcții și teste,
 * nicio metodă și nicio proprietate (contextul are doar tablouri și scalari). Modul strict al Twig: `extends`,
 * `use`, `parent()`, `block()`, `attribute()` și testele se permit doar explicit.
 */
final class Politica
{
    public const TAGURI = ['if', 'for', 'set', 'block', 'extends', 'include', 'with', 'apply', 'verbatim'];

    public const FILTRE = [
        'escape', 'e', 'default', 'length', 'upper', 'lower', 'capitalize', 'title', 'trim', 'join', 'split', 'slice',
        'first', 'last', 'keys', 'sort', 'reverse', 'replace', 'nl2br', 'striptags', 'abs', 'round', 'batch',
        // ale nucleului
        'lei', 'data', 'nopti', 'scurtat',
    ];

    /** Funcțiile nucleului: în teme se scriu cazo.head(), cazo.harta() ...; VizitatorSandbox le face cazo_head ... */
    public const FUNCTII_CAZO = [
        'head', 'foot', 'subsol_legal', 'contact', 'bara_rezervare', 'calendar', 'rezumat', 'harta', 'vreme', 'componenta',
        'continut', 'banda',
    ];

    public const FUNCTII = ['img', 't', 'url', 'asset', 'cycle', 'min', 'max', 'range'];

    public const TESTE = ['defined', 'empty', 'null', 'none', 'odd', 'even', 'iterable', 'same as', 'divisible by', 'sequence', 'mapping'];

    /** Cel mult atâtea elemente într-un range() sau într-un `..`. */
    public const RANGE_MAXIM = 100;

    public static function functiiPermise(): array
    {
        return array_merge(self::FUNCTII, array_map(static fn (string $f): string => 'cazo_' . $f, self::FUNCTII_CAZO));
    }

    public static function securitate(): SecurityPolicy
    {
        $p = new SecurityPolicy(self::TAGURI, self::FILTRE, [], [], self::functiiPermise(), self::TESTE);
        $p->setStrict(true);
        return $p;
    }
}
