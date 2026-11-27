<?php

declare(strict_types=1);

namespace Cazo\Securitate;

defined('CAZO') or exit;

/**
 * Cheia-mamă (cheie.bin, 32 de octeți, 0600) și subcheile pe scop.
 * Subcheile se derivă cu sodium_crypto_kdf_derive_from_key, contextul fix „PragulV1”
 * și un număr fix pe scop; un scop nou primește un număr nou, cele vechi rămân neschimbate.
 */
final class Chei
{
    public const CONTEXT = 'PragulV1';

    /** Numerele subcheilor; nu se renumerotează niciodată. */
    public const SCOPURI = [
        'secrete' => 1,
        'pii' => 2,
        'index_orb' => 3,
        'altcha' => 4,
        'csrf' => 5,
        'config' => 6,
        // tokenul „Rezervarea mea” (derivat din comandă) și cookie-ul comenzii în curs.
        'oaspete' => 7,
        'comanda' => 8,
        // secretele TOTP ale utilizatorilor panoului.
        'totp' => 9,
        // lanțul auditului (HMAC, cu cheia în afara bazei) și codurile de rezervă ale 2FA.
        'audit' => 10,
        'coduri_rezerva' => 11,
        // site-ul fără cheie EasyCheck: adresele calendarelor iCal citite (criptate) și adresa secretă a
        // calendarului site-ului. Numărul lasă loc scopurilor altor ramuri (12-19).
        'ical' => 20,
        // jetonul linkului de dezabonare de la oferte (adresa criptată și autentificată).
        'oferte_dezabonare' => 21,
    ];

    private array $cache = [];

    public function __construct(private string $cheieMama)
    {
        if (strlen($cheieMama) !== SODIUM_CRYPTO_KDF_KEYBYTES) {
            throw new \InvalidArgumentException('Cheia-mamă are o lungime greșită.');
        }
    }

    public static function dinFisier(string $cale): self
    {
        $continut = @file_get_contents($cale);
        if ($continut === false) {
            throw new \RuntimeException('Cheia-mamă lipsește: ' . basename($cale));
        }
        return new self($continut);
    }

    /** Scrie o cheie-mamă nouă, atomic, cu permisiunile 0600. Refuză suprascrierea. */
    public static function genereaza(string $cale): self
    {
        if (file_exists($cale)) {
            throw new \RuntimeException('Cheia-mamă există deja; o cheie nouă ar face ilizibile secretele.');
        }
        $cheie = random_bytes(SODIUM_CRYPTO_KDF_KEYBYTES);
        $tmp = $cale . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $vechi = \Cazo\Nucleu\Functii::umask(0077);
        try {
            if (file_put_contents($tmp, $cheie, LOCK_EX) !== SODIUM_CRYPTO_KDF_KEYBYTES) {
                throw new \RuntimeException('Cheia-mamă nu s-a putut scrie.');
            }
            \Cazo\Nucleu\Functii::chmod($tmp, 0600);
            rename($tmp, $cale);
        } finally {
            \Cazo\Nucleu\Functii::restabilesteUmask($vechi);
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        return new self($cheie);
    }

    public function subcheie(string $scop, int $lungime = 32): string
    {
        if (!isset(self::SCOPURI[$scop])) {
            throw new \InvalidArgumentException('Scop de cheie necunoscut: ' . $scop);
        }
        $k = $scop . ':' . $lungime;
        return $this->cache[$k] ??= sodium_crypto_kdf_derive_from_key($lungime, self::SCOPURI[$scop], self::CONTEXT, $this->cheieMama);
    }

    public function __debugInfo(): array
    {
        return ['cheieMama' => '***'];
    }
}
