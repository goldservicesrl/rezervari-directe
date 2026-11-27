<?php

declare(strict_types=1);

namespace Cazo\Securitate;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Secretele instalării (cheia EasyCheck, cheia Stripe, secretele de anunț, parola SMTP, tokenul cronului...),
 * criptate în tabelul `secrete`. Numele permise sunt fixe.
 *
 * Fiecare secret are cheia lui, derivată din subcheia „secrete” și din nume (BLAKE2b cu cheie): un text criptat
 * mutat în bază de la un nume la altul (de exemplu stripe_cheie peste ec_cheie) nu se mai decriptează.
 */
final class Secrete
{
    public const NUME = ['ec_cheie', 'ec_cheie_anterioara', 'ec_whsec', 'ec_whsec_anterior', 'stripe_cheie', 'stripe_whsec', 'stripe_whsec_anterior', 'smtp_parola', 'altcha_hmac', 'cron_token'];

    private string $subcheie;

    /** @var array<string,Cutie> */
    private array $cutii = [];

    public function __construct(private Baza $baza, Chei $chei, private Ceas $ceas)
    {
        $this->subcheie = $chei->subcheie('secrete');
    }

    /** Cutia secretului: cheia derivată din subcheia „secrete” și din numele lui. */
    private function cutie(string $nume): Cutie
    {
        return $this->cutii[$nume] ??= new Cutie(sodium_crypto_generichash('pragul-secret-v1:' . $nume, $this->subcheie, SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function pune(string $nume, string $valoare): void
    {
        $this->valideaza($nume);
        $acum = $this->ceas->moment();
        $exista = $this->baza->valoare('SELECT 1 FROM {secrete} WHERE nume = ?', [$nume]) !== null;
        if ($exista) {
            $this->baza->executa('UPDATE {secrete} SET valoare = ?, ultimele4 = ?, rotit_la = ? WHERE nume = ?', [$this->cutie($nume)->cripteaza($valoare), self::ultimele4($valoare), $acum, $nume]);
            return;
        }
        $this->baza->insereaza('secrete', [
            'nume' => $nume,
            'valoare' => $this->cutie($nume)->cripteaza($valoare),
            'ultimele4' => self::ultimele4($valoare),
            'creat_la' => $acum,
        ]);
    }

    /** Când a primit secretul valoarea de acum (ultima schimbare, altfel crearea), sau null dacă lipsește. */
    public function schimbatLa(string $nume): ?string
    {
        $this->valideaza($nume);
        $v = $this->baza->valoare('SELECT COALESCE(rotit_la, creat_la) FROM {secrete} WHERE nume = ?', [$nume]);
        return $v === null ? null : (string) $v;
    }

    public function citeste(string $nume): ?string
    {
        $this->valideaza($nume);
        $v = $this->baza->valoare('SELECT valoare FROM {secrete} WHERE nume = ?', [$nume]);
        return $v === null ? null : $this->cutie($nume)->decripteaza((string) $v);
    }

    public function ultimele4Din(string $nume): ?string
    {
        $v = $this->baza->valoare('SELECT ultimele4 FROM {secrete} WHERE nume = ?', [$nume]);
        return $v === null ? null : (string) $v;
    }

    public function sterge(string $nume): void
    {
        $this->valideaza($nume);
        $this->baza->executa('DELETE FROM {secrete} WHERE nume = ?', [$nume]);
    }

    public static function ultimele4(string $valoare): string
    {
        return strlen($valoare) <= 8 ? '' : substr($valoare, -4);
    }

    private function valideaza(string $nume): void
    {
        if (!in_array($nume, self::NUME, true)) {
            throw new \InvalidArgumentException('Nume de secret necunoscut: ' . $nume);
        }
    }
}
