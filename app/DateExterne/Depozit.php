<?php

declare(strict_types=1);

namespace Cazo\DateExterne;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Tabelul `date_externe`: ultimul corp bun al fiecărei surse (`vreme`, `curs_bnr`, răspunsurile
 * ținute minte ale căutărilor de locuri), momentul citirii și ultima eroare. O eroare păstrează corpul bun de dinainte;
 * cine afișează datele hotărăște, după `citit_la`, când le ascunde.
 */
final class Depozit
{
    public function __construct(private Baza $baza, private Ceas $ceas)
    {
    }

    /** @return array{corp:?array, citit_la:?string, versiune:int, ultima_eroare:?string, eroare_la:?string}|null */
    public function citeste(string $nume): ?array
    {
        $r = $this->baza->rand('SELECT corp, citit_la, versiune, ultima_eroare, eroare_la FROM {date_externe} WHERE nume = ?', [$nume]);
        if ($r === null) {
            return null;
        }
        $c = $r['corp'] !== null ? json_decode((string) $r['corp'], true) : null;
        return ['corp' => is_array($c) ? $c : null, 'citit_la' => $r['citit_la'] !== null ? (string) $r['citit_la'] : null, 'versiune' => (int) $r['versiune'],
            'ultima_eroare' => $r['ultima_eroare'] !== null ? (string) $r['ultima_eroare'] : null, 'eroare_la' => $r['eroare_la'] !== null ? (string) $r['eroare_la'] : null];
    }

    /** Vârsta datelor, în secunde, sau null fără o citire bună. */
    public function varsta(string $nume): ?int
    {
        $d = $this->citeste($nume);
        return $d === null || $d['citit_la'] === null || $d['corp'] === null ? null : max(0, $this->ceas->acum() - Ceas::dinMoment($d['citit_la']));
    }

    /** Scrie corpul nou; întoarce true când diferă de cel de dinainte. */
    public function scrie(string $nume, array $corp): bool
    {
        $json = json_encode($corp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $vechi = $this->baza->valoare('SELECT corp FROM {date_externe} WHERE nume = ?', [$nume]);
        $schimbat = $vechi !== $json;
        $versiune = (int) ($this->baza->valoare('SELECT versiune FROM {date_externe} WHERE nume = ?', [$nume]) ?? 0) + ($schimbat ? 1 : 0);
        $this->baza->upsert('date_externe', ['nume' => $nume, 'corp' => $json, 'citit_la' => $this->ceas->moment(), 'versiune' => $versiune,
            'ultima_eroare' => null, 'eroare_la' => null], ['nume'], ['corp', 'citit_la', 'versiune', 'ultima_eroare', 'eroare_la']);
        return $schimbat;
    }

    public function eroare(string $nume, string $mesaj): void
    {
        $mesaj = mb_substr($mesaj, 0, 480);
        if ($this->baza->valoare('SELECT 1 FROM {date_externe} WHERE nume = ?', [$nume]) === null) {
            $this->baza->insereaza('date_externe', ['nume' => $nume, 'corp' => null, 'citit_la' => null, 'versiune' => 0, 'ultima_eroare' => $mesaj, 'eroare_la' => $this->ceas->moment()]);
            return;
        }
        $this->baza->executa('UPDATE {date_externe} SET ultima_eroare = ?, eroare_la = ? WHERE nume = ?', [$mesaj, $this->ceas->moment(), $nume]);
    }

    /** Curățenia: răspunsurile ținute minte ale căutărilor de locuri, după termenul lor. */
    public function curata(string $prefix, int $secunde): int
    {
        return $this->baza->executa('DELETE FROM {date_externe} WHERE nume LIKE ? AND citit_la < ?', [$prefix . '%', $this->ceas->moment(-$secunde)])->rowCount();
    }
}
