<?php

declare(strict_types=1);

namespace Cazo\Baza;

defined('CAZO') or exit;

final class DialectSqlite implements Dialect
{
    public function nume(): string
    {
        return 'sqlite';
    }

    public function citeaza(string $identificator): string
    {
        return '"' . str_replace('"', '""', $identificator) . '"';
    }

    public function tip(string $tipAbstract, ?int $lungime): string
    {
        return match ($tipAbstract) {
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'cheie', 'text', 'textlung', 'moment', 'zi', 'json' => 'TEXT',
            'int', 'bigint', 'bool' => 'INTEGER',
            'blob' => 'BLOB',
            default => throw new \InvalidArgumentException('Tip necunoscut: ' . $tipAbstract),
        };
    }

    public function optiuniTabel(): string
    {
        return '';
    }

    public function upsert(string $tabelCitat, array $coloane, array $cheiConflict, array $actualizari): string
    {
        $col = implode(', ', array_map($this->citeaza(...), $coloane));
        $loc = implode(', ', array_fill(0, count($coloane), '?'));
        $conflict = implode(', ', array_map($this->citeaza(...), $cheiConflict));
        $set = [];
        foreach ($actualizari as $c => $v) {
            $set[] = $this->citeaza($c) . ' = ' . (is_array($v) ? $v['expr'] : 'excluded.' . $this->citeaza($c));
        }
        $sql = "INSERT INTO $tabelCitat ($col) VALUES ($loc) ON CONFLICT ($conflict) DO ";
        return $sql . ($set === [] ? 'NOTHING' : 'UPDATE SET ' . implode(', ', $set));
    }

    public function esteDuplicat(\PDOException $e): bool
    {
        // SQLITE_CONSTRAINT (19) cu mesajul de unicitate sau de cheie primară.
        $info = $e->errorInfo ?? [];
        return ($info[0] ?? '') === '23000'
            && (str_contains((string) ($info[2] ?? $e->getMessage()), 'UNIQUE') || str_contains((string) ($info[2] ?? ''), 'PRIMARY KEY'));
    }

    public function tabelExista(\PDO $pdo, string $tabel): bool
    {
        $s = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $s->execute([$tabel]);
        return (bool) $s->fetchColumn();
    }

    public function coloanaExista(\PDO $pdo, string $tabel, string $coloana): bool
    {
        foreach ($pdo->query('PRAGMA table_info(' . $this->citeaza($tabel) . ')')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if ($r['name'] === $coloana) {
                return true;
            }
        }
        return false;
    }

    public function indexExista(\PDO $pdo, string $tabel, string $index): bool
    {
        $s = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = ?");
        $s->execute([$index]);
        return (bool) $s->fetchColumn();
    }

    public function tabeleCuPrefix(\PDO $pdo, string $prefix): array
    {
        $s = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND substr(name, 1, ?) = ? ORDER BY name");
        $s->execute([strlen($prefix), $prefix]);
        return $s->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function pregateste(\PDO $pdo): void
    {
        // journal_mode=DELETE (fără fișiere -wal pe găzduiri cu NFS), busy_timeout=5000.
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}
