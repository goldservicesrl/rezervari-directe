<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

defined('CAZO') or exit;

/**
 * Mediul Twig al nucleului (mediu de încredere): șabloanele din vederi/, scrise și revizuite odată
 * cu nucleul. Autoescape HTML și variabile stricte, ca la teme; fără sandbox, fiindcă nu primește cod din afară.
 * Textele vin din cataloagele nucleului (limbi/ro.php, limbi/en.php).
 */
final class MediuNucleu
{
    public static function creeaza(string $dirVederi, ?string $dirCache, Traducator $t): Environment
    {
        $env = new Environment(new FilesystemLoader([$dirVederi]), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => $dirCache ?? false,
            'auto_reload' => true,
        ]);
        $limba = $t->limba();
        $env->addFunction(new TwigFunction('t', static fn (string $c, array $p = []): string => $t->t($c, $p)));
        $env->addFunction(new TwigFunction('tn', static fn (string $c, int $n, array $p = []): string => $t->plural($c, $n, $p)));
        $env->addFilter(new TwigFilter('lei', static fn (?int $b): string => $b === null ? '' : Format::bani($b, $limba)));
        $env->addFilter(new TwigFilter('data', static fn (string $z): string => Format::zi($z, $limba)));
        $env->addFilter(new TwigFilter('data_lunga', static fn (string $z): string => Format::ziLunga($z, $limba)));
        return $env;
    }
}
