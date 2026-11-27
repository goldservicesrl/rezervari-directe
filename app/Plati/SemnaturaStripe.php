<?php

declare(strict_types=1);

namespace Cazo\Plati;

defined('CAZO') or exit;

/**
 * Semnătura anunțurilor Stripe, fără SDK: antetul `Stripe-Signature: t=...,v1=...[,v1=...][,v0=...]`,
 * HMAC-SHA256 cu secretul `whsec_...` peste `t + "." + corpul brut`, comparat cu `hash_equals` cu fiecare `v1`;
 * celelalte scheme se ignoră; toleranța 300 de secunde, în ambele sensuri.
 */
final class SemnaturaStripe
{
    public const TOLERANTA = 300;

    /** @param list<string> $secrete */
    public static function verifica(string $corp, ?string $antet, array $secrete, int $acum, int $toleranta = self::TOLERANTA): bool
    {
        if ($antet === null || strlen($antet) > 2048 || $secrete === []) {
            return false;
        }
        $t = null;
        $v1 = [];
        foreach (explode(',', $antet) as $parte) {
            $kv = explode('=', trim($parte), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't' && preg_match('~^\d{1,12}$~', $kv[1])) {
                $t = (int) $kv[1];
            } elseif ($kv[0] === 'v1' && preg_match('~^[0-9a-f]{64}$~', $kv[1])) {
                $v1[] = $kv[1];
            }
        }
        if ($t === null || $v1 === [] || abs($acum - $t) > $toleranta) {
            return false;
        }
        foreach ($secrete as $s) {
            $asteptat = hash_hmac('sha256', $t . '.' . $corp, $s);
            foreach ($v1 as $v) {
                if (hash_equals($asteptat, $v)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Antetul pentru un corp (testele și dublura Stripe). */
    public static function antet(string $corp, string $secret, int $t): string
    {
        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $corp, $secret);
    }
}
