<?php

declare(strict_types=1);

namespace Cazo\Securitate;

defined('CAZO') or exit;

/**
 * Criptarea simetrică (secretbox: XSalsa20-Poly1305) cu o subcheie de scop.
 * Formatul textului criptat: „v1.” + base64url(nonce || ciphertext). Versiunea permite schimbarea algoritmului.
 */
final class Cutie
{
    public function __construct(private string $cheie)
    {
        if (strlen($cheie) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Cheie de criptare cu lungime greșită.');
        }
    }

    public function cripteaza(string $text): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1.' . Codare::b64u($nonce . sodium_crypto_secretbox($text, $nonce, $this->cheie));
    }

    public function decripteaza(string $criptat): string
    {
        if (!str_starts_with($criptat, 'v1.')) {
            throw new \RuntimeException('Format de date criptate necunoscut.');
        }
        $brut = Codare::dinB64u(substr($criptat, 3));
        if ($brut === null || strlen($brut) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES - 1) {
            throw new \RuntimeException('Date criptate deteriorate.');
        }
        $nonce = substr($brut, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $text = sodium_crypto_secretbox_open(substr($brut, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->cheie);
        if ($text === false) {
            throw new \RuntimeException('Date criptate deteriorate sau cheie greșită.');
        }
        return $text;
    }

    public function __debugInfo(): array
    {
        return ['cheie' => '***'];
    }
}
