<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

defined('CAZO') or exit;

/**
 * Mediul Twig al temelor: separat de cel al nucleului, `Sandbox` global cu Politica strictă,
 * `autoescape: html`, `strict_variables`, cache pe sha-ul temei, încărcătorul limitat la tema activă (spațiul
 * principal și `@tema`) și la `@schelet`, plus paginile compuse de nucleu (`@pagina/...`, construite din
 * compoziția JSON validată, cu nume statice).
 *
 * Limitele de resurse (Garda, VizitatorGarda): adâncimea șabloanelor, pașii buclelor, mărimea valorilor, ieșirea,
 * timpul și memoria, plus capturile numărate cât se adună (GardaCaptura); o depășire oprește randarea cu o excepție,
 * iar Randare servește pagina cu _schelet. Compilarea e mărginită de LexerTema (mărimea sursei, imbricarea, lanțurile)
 * și intră în bugetul de timp: garda pornește înaintea lui load(). O eroare fatală care ar scăpa gărzii o prinde Plasa (temele terțe).
 *
 * Funcțiile nucleului sunt înregistrate cu `is_safe: html` și produc HTML din șabloanele nucleului, în afara
 * sandboxului (Functii); în context ajung doar tablouri și scalari.
 */
final class MediuSandbox
{
    /**
     * @param array<string,string> $pagini șabloanele compuse de nucleu, după nume (fără prefixul @pagina/)
     */
    public static function creeaza(string $dirTema, string $dirSchelet, ?string $dirCache, ?Functii $f, array $pagini = []): Environment
    {
        // Rădăcina „/”: cheia de cache a șablonului (deci numele clasei compilate) e calea absolută, ca două teme cu
        // aceleași căi relative (tema activă și _schelet, în aceeași cerere) să rămână șabloane distincte.
        $fs = new FilesystemLoader([], '/');
        $fs->addPath($dirTema);
        $fs->addPath($dirTema, 'tema');
        $fs->addPath($dirSchelet, 'schelet');
        $compuse = [];
        foreach ($pagini as $n => $sursa) {
            $compuse['@pagina/' . $n] = $sursa;
        }
        $loader = new ChainLoader([new ArrayLoader($compuse), $fs]);
        $env = new Environment($loader, [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => $dirCache ?? false,
            'auto_reload' => true,
            'optimizations' => -1,
            // Doar yield: ieșirea curge pe bucăți spre Randare, care o numără pe parcurs (Garda).
            'use_yield' => true,
        ]);
        // Compilarea mărginită înaintea parserului (mărimea, tagurile, imbricarea, lanțurile), sub ceasul gărzii.
        $env->setLexer(new LexerTema($env));
        $env->addExtension(new SandboxExtension(Politica::securitate(), true));
        $env->addNodeVisitor(new VizitatorSandbox());
        $env->addNodeVisitor(new VizitatorGarda());
        self::inregistreaza($env, $f);
        return $env;
    }

    /**
     * Randarea sub gardă: compilarea (la un cache gol) și ieșirea intră în același buget de timp; ieșirea curge pe
     * bucăți și se numără cât curge, iar timpul se verifică și la sfârșit.
     * O limită depășită ajunge ca excepție (LimitaDepasita, de obicei învelită de Twig în RuntimeError).
     */
    public static function randeaza(Environment $env, string $nume, array $context, float $timp = Garda::TIMP): string
    {
        $html = '';
        Garda::porneste($timp);
        try {
            foreach ($env->load($nume)->stream($context) as $bucata) {
                $bucata = (string) $bucata;
                Garda::iesire(strlen($bucata));
                $html .= $bucata;
            }
            if (Garda::durata() > $timp) {
                throw new LimitaDepasita('timp', 'Randarea temei depășește ' . $timp . ' s.');
            }
        } finally {
            Garda::opreste();
        }
        return $html;
    }

    /** Funcțiile și filtrele permise temelor; fără Functii (validatorul static), funcțiile întorc șiruri goale. */
    public static function inregistreaza(Environment $env, ?Functii $f): void
    {
        $sigur = ['is_safe' => ['html']];
        $gol = static fn (): string => '';
        foreach (Politica::FUNCTII_CAZO as $n) {
            $env->addFunction(new TwigFunction('cazo_' . $n, $f !== null ? [$f, $n] : $gol, $sigur));
        }
        $env->addFunction(new TwigFunction('img', $f !== null ? [$f, 'img'] : $gol, $sigur));
        $env->addFunction(new TwigFunction('t', $f !== null ? [$f, 't'] : static fn (string $c): string => $c));
        $env->addFunction(new TwigFunction('url', $f !== null ? [$f, 'url'] : static fn (): string => '/'));
        $env->addFunction(new TwigFunction('asset', $f !== null ? [$f, 'asset'] : static fn (): string => '/'));
        $env->addFilter(new TwigFilter('lei', $f !== null ? [$f, 'lei'] : static fn (mixed $v): string => (string) $v));
        $env->addFilter(new TwigFilter('data', $f !== null ? [$f, 'data'] : static fn (mixed $v): string => (string) $v));
        $env->addFilter(new TwigFilter('nopti', $f !== null ? [$f, 'nopti'] : static fn (mixed $v): string => (string) $v));
        $env->addFilter(new TwigFilter('scurtat', [Functii::class, 'scurtat']));
        // Filtrele care pot multiplica dimensiunea, cu rezultatul măsurat înainte (Garda); aceleași nume ca în Twig.
        $env->addFilter(new TwigFilter('replace', [Garda::class, 'replace']));
        $env->addFilter(new TwigFilter('join', [Garda::class, 'join']));
        $env->addFilter(new TwigFilter('batch', [Garda::class, 'batch']));
        // sort: operație nativă neîntreruptibilă; forma se mărginește înainte (elemente scalare), altfel asort e O(n^2).
        $env->addFilter(new TwigFilter('sort', [Garda::class, 'sort']));
    }
}
