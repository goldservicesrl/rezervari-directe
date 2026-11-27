<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

defined('CAZO') or exit;

/**
 * Semnătura anunțurilor EasyCheck (ghidul API): antetul EasyCheck-Semnatura „t=<moment Unix>,v1=<hex>”,
 * HMAC-SHA256 cu secretul whsec_ peste „t + "." + corpul brut”, toleranța 5 minute, comparare în timp constant.
 */
final class Semnatura
{
    public const TOLERANTA = 300;

    public static function calculeaza(string $secret, int $t, string $corp): string
    {
        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $corp, $secret);
    }

    /** @param string[] $secrete secretul curent și, în cele 24 h de după o rotire, cel anterior */
    public static function verifica(string $antet, string $corp, array $secrete, int $acum): bool
    {
        if (!preg_match('~^t=(\d{1,12}),v1=([0-9a-f]{64})$~', trim($antet), $m)) {
            return false;
        }
        if (abs($acum - (int) $m[1]) > self::TOLERANTA) {
            return false;
        }
        $bun = false;
        foreach ($secrete as $s) {
            if (is_string($s) && $s !== '' && hash_equals(hash_hmac('sha256', $m[1] . '.' . $corp, $s), $m[2])) {
                $bun = true;
            }
        }
        return $bun;
    }
}
