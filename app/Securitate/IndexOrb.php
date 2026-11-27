<?php

declare(strict_types=1);

namespace Cazo\Securitate;

defined('CAZO') or exit;

/**
 * Indexul orb: HMAC-SHA256 cu subcheia „index_orb” peste valoarea normalizată.
 * Permite căutarea după e-mail fără ca e-mailul să stea în clar în bază.
 */
final class IndexOrb
{
    private string $cheie;

    public function __construct(Chei $chei)
    {
        $this->cheie = $chei->subcheie('index_orb');
    }

    public function email(string $email): string
    {
        return $this->valoare(mb_strtolower(trim($email), 'UTF-8'));
    }

    public function valoare(string $text): string
    {
        return hash_hmac('sha256', $text, $this->cheie);
    }
}
