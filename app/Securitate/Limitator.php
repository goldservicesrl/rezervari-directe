<?php

declare(strict_types=1);

namespace Cazo\Securitate;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Limitatorul cu ferestre fixe, în tabelul `limite`.
 * Cheia descrie acțiunea și subiectul, de exemplu „csp:ip:203.0.113.0/24”; IP-urile intră doar trunchiate.
 * Contorul crește atomic (upsert), apoi se citește: două cereri simultane pot doar să fie numărate amândouă,
 * niciodată să treacă amândouă peste limită.
 */
final class Limitator
{
    public function __construct(private Baza $baza, private Ceas $ceas)
    {
    }

    /** Numără o încercare și spune dacă se încadrează în limită. */
    public function incearca(string $cheie, int $limita, int $fereastraSecunde): bool
    {
        return $this->inregistreaza($cheie, $fereastraSecunde) <= $limita;
    }

    /** Numără o încercare și întoarce numărul din fereastra curentă. */
    public function inregistreaza(string $cheie, int $fereastraSecunde): int
    {
        $this->valideaza($cheie, $fereastraSecunde);
        $sfarsit = $this->sfarsitFereastra($fereastraSecunde);
        $this->baza->upsert('limite', ['cheie' => $cheie, 'fereastra' => $sfarsit, 'numar' => 1], ['cheie', 'fereastra'], ['numar' => ['expr' => $this->baza->dialect()->citeaza('numar') . ' + 1']]);
        return (int) $this->baza->valoare('SELECT numar FROM {limite} WHERE cheie = ? AND fereastra = ?', [$cheie, $sfarsit]);
    }

    /**
     * Dă înapoi o încercare numărată cu inregistreaza (rezervarea unei încercări înainte de verificare, întoarsă la succes):
     * limitele de intrare, de reconfirmare și de 2FA rezervă încercarea atomic, ca cererile simultane să nu treacă toate de
     * limită (auditul de securitate.2026).
     */
    public function anuleaza(string $cheie, int $fereastraSecunde): void
    {
        $this->valideaza($cheie, $fereastraSecunde);
        $this->baza->executa('UPDATE {limite} SET numar = numar - 1 WHERE cheie = ? AND fereastra = ? AND numar > 0', [$cheie, $this->sfarsitFereastra($fereastraSecunde)]);
    }

    /** Numărul din fereastra curentă, fără să numere. */
    public function numar(string $cheie, int $fereastraSecunde): int
    {
        $this->valideaza($cheie, $fereastraSecunde);
        return (int) ($this->baza->valoare('SELECT numar FROM {limite} WHERE cheie = ? AND fereastra = ?', [$cheie, $this->sfarsitFereastra($fereastraSecunde)]) ?? 0);
    }

    /**
     * Suma contoarelor unei chei pe ferestrele care se încheie în intervalul (de, pana] (secunde Unix): istoricul
     * întrerupătorului (cheile „istoric:...”, păstrate 8 zile).
     */
    public function suma(string $cheie, int $de, int $pana): int
    {
        return (int) ($this->baza->valoare('SELECT SUM(numar) FROM {limite} WHERE cheie = ? AND fereastra > ? AND fereastra <= ?', [$cheie, $de, $pana]) ?? 0);
    }

    /** Șterge ferestrele încheiate; istoricul („istoric:...”) rămâne 8 zile, pentru media pe 7 zile a întrerupătorului. */
    public function curata(): int
    {
        $acum = $this->ceas->acum();
        return $this->baza->executa("DELETE FROM {limite} WHERE (fereastra <= ? AND cheie NOT LIKE 'istoric:%') OR fereastra <= ?", [$acum, $acum - 8 * 86400])->rowCount();
    }

    private function sfarsitFereastra(int $fereastraSecunde): int
    {
        return (intdiv($this->ceas->acum(), $fereastraSecunde) + 1) * $fereastraSecunde;
    }

    private function valideaza(string $cheie, int $fereastraSecunde): void
    {
        if ($cheie === '' || strlen($cheie) > 191 || $fereastraSecunde < 1 || $fereastraSecunde > 31 * 86400) {
            throw new \InvalidArgumentException('Limită invalidă.');
        }
    }
}
