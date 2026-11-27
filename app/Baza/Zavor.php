<?php

declare(strict_types=1);

namespace Cazo\Baza;

use Cazo\Nucleu\Ceas;
use Cazo\Securitate\Codare;

defined('CAZO') or exit;

/**
 * Zăvoare cu expirare, în tabelul `zavoare` (cron, sincronizare, comanda:<id>, migrari).
 * Exclusivitatea vine din cheia primară: două INSERT-uri pe același nume nu pot reuși amândouă.
 * Un zăvor expirat (proces mort) se poate lua din nou după `expira_la`.
 */
final class Zavor
{
    public function __construct(private Baza $baza, private Ceas $ceas)
    {
    }

    /** Întoarce jetonul deținătorului sau null când zăvorul e ținut de altcineva. */
    public function ia(string $nume, int $secunde): ?string
    {
        $this->valideaza($nume, $secunde);
        $detinator = Codare::jeton(12);
        $acum = $this->ceas->moment();
        // Curăță doar zăvorul expirat cu acest nume; un zăvor valid rămâne neatins.
        $this->baza->executa('DELETE FROM {zavoare} WHERE nume = ? AND expira_la <= ?', [$nume, $acum]);
        try {
            $this->baza->insereaza('zavoare', ['nume' => $nume, 'detinator' => $detinator, 'expira_la' => $this->ceas->moment($secunde)]);
        } catch (\PDOException $e) {
            if ($this->baza->esteDuplicat($e)) {
                return null;
            }
            throw $e;
        }
        return $detinator;
    }

    /** Prelungește zăvorul doar dacă îl deține încă $detinator. */
    public function prelungeste(string $nume, string $detinator, int $secunde): bool
    {
        $this->valideaza($nume, $secunde);
        return $this->baza->executa(
            'UPDATE {zavoare} SET expira_la = ? WHERE nume = ? AND detinator = ? AND expira_la > ?',
            [$this->ceas->moment($secunde), $nume, $detinator, $this->ceas->moment()]
        )->rowCount() === 1;
    }

    public function elibereaza(string $nume, string $detinator): void
    {
        $this->baza->executa('DELETE FROM {zavoare} WHERE nume = ? AND detinator = ?', [$nume, $detinator]);
    }

    public function liber(string $nume): bool
    {
        return $this->baza->valoare('SELECT 1 FROM {zavoare} WHERE nume = ? AND expira_la > ?', [$nume, $this->ceas->moment()]) === null;
    }

    /**
     * Rulează $f sub zăvor; întoarce [true, rezultat] sau [false, null] când zăvorul e ocupat.
     */
    public function cuZavor(string $nume, int $secunde, callable $f): array
    {
        $d = $this->ia($nume, $secunde);
        if ($d === null) {
            return [false, null];
        }
        try {
            return [true, $f($d)];
        } finally {
            $this->elibereaza($nume, $d);
        }
    }

    public function curataExpirate(): int
    {
        return $this->baza->executa('DELETE FROM {zavoare} WHERE expira_la <= ?', [$this->ceas->moment()])->rowCount();
    }

    private function valideaza(string $nume, int $secunde): void
    {
        if ($nume === '' || strlen($nume) > 191 || $secunde < 1 || $secunde > 86400) {
            throw new \InvalidArgumentException('Zăvor invalid.');
        }
    }
}
