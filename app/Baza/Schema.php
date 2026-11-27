<?php

declare(strict_types=1);

namespace Cazo\Baza;

defined('CAZO') or exit;

/**
 * Constructorul de schemă al migrărilor. Doar operații aditive și idempotente:
 * tabele, coloane, indecși. Nu există metode de ștergere sau de modificare a coloanelor.
 *
 * Coloanele se descriu ca text scurt: „tip[(lungime)] [null] [implicit VALOARE]”, de exemplu
 * 'cheie', 'text(64) null', 'int implicit 0', 'moment null', 'json'. Implicit, coloanele sunt NOT NULL.
 */
final class Schema
{
    public function __construct(private Baza $baza)
    {
    }

    public function baza(): Baza
    {
        return $this->baza;
    }

    /**
     * @param array<string,string> $coloane
     * @param string[] $cheiePrimara coloanele cheii primare (gol când există o coloană 'id')
     */
    public function tabel(string $nume, array $coloane, array $cheiePrimara = []): void
    {
        if ($this->baza->tabelExista($nume)) {
            // Tabelul există: se adaugă doar coloanele noi (migrare repetată sau completată).
            foreach ($coloane as $col => $def) {
                $this->coloana($nume, $col, $def);
            }
            return;
        }
        $d = $this->baza->dialect();
        $linii = [];
        foreach ($coloane as $col => $def) {
            $linii[] = $d->citeaza($col) . ' ' . $this->definitie($def);
        }
        if ($cheiePrimara !== []) {
            $linii[] = 'PRIMARY KEY (' . implode(', ', array_map($d->citeaza(...), $cheiePrimara)) . ')';
        }
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $this->baza->t($nume) . " (\n  " . implode(",\n  ", $linii) . "\n)" . $d->optiuniTabel();
        $this->baza->pdo()->exec($sql);
    }

    public function coloana(string $tabel, string $coloana, string $def): void
    {
        if ($this->baza->coloanaExista($tabel, $coloana)) {
            return;
        }
        $p = $this->analizeaza($def);
        if ($p['tip'] === 'id') {
            throw new \LogicException('O coloană id se adaugă doar la crearea tabelului.');
        }
        if (!$p['null'] && $p['implicit'] === null) {
            // O coloană NOT NULL fără valoare implicită ar rupe rândurile existente și codul versiunii anterioare.
            throw new \LogicException("Coloana nouă $tabel.$coloana are nevoie de null sau de o valoare implicită.");
        }
        $this->baza->pdo()->exec('ALTER TABLE ' . $this->baza->t($tabel) . ' ADD COLUMN '
            . $this->baza->dialect()->citeaza($coloana) . ' ' . $this->definitie($def));
    }

    /** @param string[] $coloane */
    public function index(string $tabel, string $nume, array $coloane, bool $unic = false): void
    {
        $numeComplet = $this->baza->prefix() . $nume;
        $d = $this->baza->dialect();
        if ($d->indexExista($this->baza->pdo(), $this->baza->numeTabel($tabel), $numeComplet)) {
            return;
        }
        $this->baza->pdo()->exec(($unic ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ') . $d->citeaza($numeComplet)
            . ' ON ' . $this->baza->t($tabel) . ' (' . implode(', ', array_map($d->citeaza(...), $coloane)) . ')');
    }

    public function unic(string $tabel, string $nume, array $coloane): void
    {
        $this->index($tabel, $nume, $coloane, true);
    }

    private function analizeaza(string $def): array
    {
        if (!preg_match('~^([a-z]+)(?:\((\d+)\))?((?:\s+null)?)(?:\s+implicit\s+(.+))?$~', trim($def), $m)) {
            throw new \InvalidArgumentException('Definiție de coloană invalidă: ' . $def);
        }
        return ['tip' => $m[1], 'lungime' => ($m[2] ?? '') !== '' ? (int) $m[2] : null, 'null' => trim($m[3] ?? '') === 'null', 'implicit' => $m[4] ?? null];
    }

    private function definitie(string $def): string
    {
        $p = $this->analizeaza($def);
        $sql = $this->baza->dialect()->tip($p['tip'], $p['lungime']);
        if ($p['tip'] === 'id') {
            return $sql;
        }
        $sql .= $p['null'] ? ' NULL' : ' NOT NULL';
        if ($p['implicit'] !== null) {
            $v = $p['implicit'];
            if (!preg_match("~^(-?\d+|'[^']*'|NULL)$~", $v)) {
                throw new \InvalidArgumentException('Valoare implicită invalidă: ' . $v);
            }
            $sql .= ' DEFAULT ' . $v;
        }
        return $sql;
    }
}
