<?php

declare(strict_types=1);

namespace Cazo\Baza;

use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Migrările din migrari/NNNN_nume.php. Fiecare fișier întoarce
 * ['descriere' => '...', 'ruleaza' => function (Schema $s): void { ... }].
 * Toate operațiile sunt aditive și idempotente (DDL-ul MySQL nu e tranzacțional),
 * deci o migrare întreruptă se reia în siguranță.
 */
final class Migrator
{
    public function __construct(private Baza $baza, private Ceas $ceas, private string $director, private string $lansare)
    {
    }

    /** Tabelele de care depinde chiar migratorul; aceleași definiții apar și în 0001. */
    public static function tabeleInfrastructura(Schema $s): void
    {
        $s->tabel('migrari', [
            'versiune' => 'cheie(32)',
            'aplicat_la' => 'moment',
            'lansare' => 'text(32)',
        ], ['versiune']);
        $s->tabel('zavoare', [
            'nume' => 'cheie',
            'detinator' => 'text(64)',
            'expira_la' => 'moment',
        ], ['nume']);
    }

    /** @return array<string,string> versiune => cale, în ordine */
    public function disponibile(): array
    {
        $rez = [];
        foreach (glob($this->director . '/[0-9][0-9][0-9][0-9]_*.php') ?: [] as $f) {
            $rez[substr(basename($f), 0, 4)] = $f;
        }
        ksort($rez, SORT_STRING);
        return $rez;
    }

    /** @return string[] */
    public function aplicate(): array
    {
        if (!$this->baza->tabelExista('migrari')) {
            return [];
        }
        return array_map('strval', $this->baza->executa('SELECT versiune FROM {migrari} ORDER BY versiune')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return string[] */
    public function inAsteptare(): array
    {
        return array_values(array_diff(array_keys($this->disponibile()), $this->aplicate()));
    }

    /**
     * Aplică migrările lipsă, sub zăvorul `migrari`.
     * @return string[] versiunile aplicate acum
     */
    public function migreaza(): array
    {
        $schema = new Schema($this->baza);
        self::tabeleInfrastructura($schema);
        $zavor = new Zavor($this->baza, $this->ceas);
        [$luat, $aplicate] = $zavor->cuZavor('migrari', 600, function () use ($schema): array {
            $facute = [];
            $existente = $this->aplicate();
            foreach ($this->disponibile() as $versiune => $cale) {
                if (in_array((string) $versiune, $existente, true)) {
                    continue;
                }
                $m = require $cale;
                if (!is_array($m) || !isset($m['ruleaza']) || !is_callable($m['ruleaza'])) {
                    throw new \RuntimeException('Migrare invalidă: ' . basename($cale));
                }
                ($m['ruleaza'])($schema);
                $this->baza->insereaza('migrari', ['versiune' => (string) $versiune, 'aplicat_la' => $this->ceas->moment(), 'lansare' => $this->lansare]);
                $facute[] = (string) $versiune;
            }
            return $facute;
        });
        if (!$luat) {
            throw new \RuntimeException('Alt proces aplică migrările acum.');
        }
        return $aplicate;
    }
}
