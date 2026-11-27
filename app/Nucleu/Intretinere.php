<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

defined('CAZO') or exit;

/**
 * Modul de întreținere (în timpul actualizărilor): fișierul `intretinere` din dosarul privat.
 * Paginile din cache rămân servite de încărcător; restul primește 503 cu Retry-After.
 * Fișierul conține momentul Unix până la care e valabil; după el, modul se stinge singur
 * (o actualizare întreruptă nu lasă site-ul oprit la nesfârșit). A doua linie, opțională, ține amprenta SHA-256 a
 * jetonului de ocolire: cererile cu antetul X-Cazo-Actualizare văd paginile versiunii
 * noi înaintea vizitatorilor.
 */
final class Intretinere
{
    public const FISIER = 'intretinere';

    public const ANTET_OCOLIRE = 'x-cazo-actualizare';

    public static function activa(string $privat, int $acum): bool
    {
        $f = $privat . '/' . self::FISIER;
        if (!is_file($f)) {
            return false;
        }
        $pana = (int) trim((string) @file_get_contents($f));
        return $pana > $acum;
    }

    public static function porneste(string $privat, int $panaLa, ?string $jetonOcolire = null): void
    {
        $f = $privat . '/' . self::FISIER;
        file_put_contents($f, (string) $panaLa . ($jetonOcolire !== null ? "\n" . hash('sha256', $jetonOcolire) : ''), LOCK_EX);
        \Cazo\Nucleu\Functii::chmod($f, 0600);
    }

    /** Jetonul de ocolire al actualizării în curs, comparat în timp constant. */
    public static function ocolire(string $privat, ?string $jeton): bool
    {
        if ($jeton === null || strlen($jeton) < 32 || strlen($jeton) > 128) {
            return false;
        }
        $linii = explode("\n", (string) @file_get_contents($privat . '/' . self::FISIER));
        $amprenta = trim($linii[1] ?? '');
        return strlen($amprenta) === 64 && hash_equals($amprenta, hash('sha256', $jeton));
    }

    public static function opreste(string $privat): void
    {
        @unlink($privat . '/' . self::FISIER);
    }
}
