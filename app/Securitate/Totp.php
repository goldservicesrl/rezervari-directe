<?php

declare(strict_types=1);

namespace Cazo\Securitate;

defined('CAZO') or exit;

/**
 * TOTP (RFC 6238), propriu: HMAC-SHA1, pași de 30 de secunde, 6 cifre, o fereastră de un pas în
 * fiecare parte; un pas folosit o dată nu mai trece (`totp_ultimul_pas`). Secretul: 20 de octeți, în Base32 pentru
 * aplicația de autentificare, păstrat criptat (subcheia „secrete”).
 */
final class Totp
{
    public const PAS = 30;
    public const CIFRE = 6;
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secretNou(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function cod(string $secretB32, int $pas): string
    {
        $k = self::dinBase32($secretB32);
        $h = hash_hmac('sha1', pack('J', $pas), $k, true);
        $o = ord($h[19]) & 0x0f;
        $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string) ($n % 10 ** self::CIFRE), self::CIFRE, '0', STR_PAD_LEFT);
    }

    /** Pasul potrivit codului ($ultimul = ultimul pas folosit, refuzat ca reluare), sau null. */
    public static function verifica(string $secretB32, string $cod, int $acum, ?int $ultimul = null): ?int
    {
        $cod = (string) preg_replace('~\s+~', '', $cod);
        if (!preg_match('~^\d{6}$~', $cod)) {
            return null;
        }
        $pas = intdiv($acum, self::PAS);
        foreach ([$pas - 1, $pas, $pas + 1] as $p) {
            if (($ultimul === null || $p > $ultimul) && hash_equals(self::cod($secretB32, $p), $cod)) {
                return $p;
            }
        }
        return null;
    }

    /** Adresa otpauth:// pentru aplicația de autentificare (introdusă manual sau ca text). */
    public static function uri(string $secretB32, string $cont, string $emitent): string
    {
        return 'otpauth://totp/' . rawurlencode($emitent . ':' . $cont) . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($emitent) . '&digits=6&period=30';
    }

    public static function base32(string $b): string
    {
        $biti = '';
        foreach (str_split($b) as $c) {
            $biti .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $r = '';
        foreach (str_split($biti, 5) as $g) {
            $r .= self::B32[bindec(str_pad($g, 5, '0'))];
        }
        return $r;
    }

    public static function dinBase32(string $s): string
    {
        $s = strtoupper((string) preg_replace('~[\s=]~', '', $s));
        $biti = '';
        foreach (str_split($s) as $c) {
            $p = strpos(self::B32, $c);
            if ($p === false) {
                throw new \InvalidArgumentException('Secret TOTP invalid.');
            }
            $biti .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $r = '';
        foreach (str_split($biti, 8) as $o) {
            if (strlen($o) === 8) {
                $r .= chr(bindec($o));
            }
        }
        return $r;
    }
}
