<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;
use Cazo\Securitate\Limitator;

defined('CAZO') or exit;

/**
 * Codul de 6 cifre pe e-mail înainte de trimiterea comenzii: o oră,
 * 5 încercări, cel mult 3 trimiteri pe oră pe e-mail. În bază stă doar amprenta codului (SHA-256 peste id-ul
 * comenzii și cod, cu sare aleatoare pe rând); codul în clar pleacă doar în e-mail.
 * Codul vine pe e-mail; pe pagina comenzii, câmpul are autocomplete="one-time-code" și acceptă lipirea.
 */
final class CodEmail
{
    public const DURATA = 3600;
    public const INCERCARI = 5;
    public const TRIMITERI_ORA = 3;

    public function __construct(private Baza $baza, private Ceas $ceas, private Limitator $limitator)
    {
    }

    /**
     * Un cod nou pentru comandă, sau null peste limita de trimiteri (3 pe oră pe e-mail).
     * Codurile vechi ale comenzii se închid (doar ultimul cod e valabil).
     */
    public function nou(int $comandaId, string $emailIo): ?string
    {
        if (!$this->limitator->incearca('cod_email:' . $emailIo, self::TRIMITERI_ORA, 3600)) {
            return null;
        }
        $cod = sprintf('%06d', random_int(0, 999_999));
        $sare = bin2hex(random_bytes(8));
        $this->baza->tranzactie(function (Baza $b) use ($comandaId, $emailIo, $cod, $sare): void {
            $b->executa('UPDATE {coduri_email} SET expira_la = ? WHERE comanda_id = ? AND confirmat_la IS NULL', [$this->ceas->moment(), $comandaId]);
            $b->insereaza('coduri_email', ['comanda_id' => $comandaId, 'email_io' => $emailIo, 'cod_hash' => $sare . '.' . hash('sha256', $sare . ':' . $comandaId . ':' . $cod),
                'incercari' => 0, 'expira_la' => $this->ceas->moment(self::DURATA), 'creat_la' => $this->ceas->moment()]);
        });
        return $cod;
    }

    /** @return string „ok”, „gresit”, „expirat” (termen trecut, încercări epuizate sau niciun cod) */
    public function verifica(int $comandaId, string $cod): string
    {
        $cod = preg_replace('~\D~', '', $cod) ?? '';
        $r = $this->baza->rand('SELECT * FROM {coduri_email} WHERE comanda_id = ? AND confirmat_la IS NULL ORDER BY id DESC LIMIT 1', [$comandaId]);
        if ($r === null || (string) $r['expira_la'] <= $this->ceas->moment() || (int) $r['incercari'] >= self::INCERCARI) {
            return 'expirat';
        }
        // Auditul de securitate (24.11.2026): încercarea se ia atomic, cu condiția în UPDATE. Altfel cereri simultane citeau toate
        // același contor sub limită și treceau peste cele 5 încercări.
        if ($this->baza->executa('UPDATE {coduri_email} SET incercari = incercari + 1 WHERE id = ? AND incercari < ?', [$r['id'], self::INCERCARI])->rowCount() !== 1) {
            return 'expirat';
        }
        [$sare, $h] = explode('.', (string) $r['cod_hash'], 2) + [1 => ''];
        if (strlen($cod) !== 6 || !hash_equals($h, hash('sha256', $sare . ':' . $comandaId . ':' . $cod))) {
            return (int) $r['incercari'] + 1 >= self::INCERCARI ? 'expirat' : 'gresit';
        }
        $n = $this->baza->executa('UPDATE {coduri_email} SET confirmat_la = ? WHERE id = ? AND confirmat_la IS NULL', [$this->ceas->moment(), $r['id']])->rowCount();
        return $n === 1 ? 'ok' : 'expirat';
    }

    /** Codurile mai vechi de 24 de ore (termenul tehnic fix din 20). */
    public function curata(): int
    {
        return $this->baza->executa('DELETE FROM {coduri_email} WHERE creat_la < ?', [$this->ceas->moment(-86400)])->rowCount();
    }
}
