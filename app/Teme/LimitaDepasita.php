<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/** O limită de resurse a randării temei (Garda), depășită; $limita: adancime, iteratii, valoare, elemente, forma, iesire, timp, memorie. */
final class LimitaDepasita extends \RuntimeException
{
    public function __construct(public readonly string $limita, string $mesaj)
    {
        parent::__construct($mesaj);
    }

    /** Limita depășită din lanțul unei excepții (Twig o învelește în RuntimeError), sau null. */
    public static function din(\Throwable $e): ?self
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof self) {
                return $x;
            }
        }
        return null;
    }
}
