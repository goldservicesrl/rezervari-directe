<?php

declare(strict_types=1);

namespace Cazo\Baza;

defined('CAZO') or exit;

/** MySQL 8 și MariaDB 10.6+. */
final class DialectMysql implements Dialect
{
    public function nume(): string
    {
        return 'mysql';
    }

    public function citeaza(string $identificator): string
    {
        return '`' . str_replace('`', '``', $identificator) . '`';
    }

    public function tip(string $tipAbstract, ?int $lungime): string
    {
        return match ($tipAbstract) {
            'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            // 191 de caractere utf8mb4 încap în limita indexurilor InnoDB vechi (767 de octeți).
            'cheie' => 'VARCHAR(' . ($lungime ?? 191) . ')',
            'text' => 'VARCHAR(' . ($lungime ?? 255) . ')',
            'textlung', 'json' => 'LONGTEXT',
            'int' => 'INT',
            'bigint' => 'BIGINT',
            'bool' => 'TINYINT(1)',
            'moment' => 'DATETIME',
            'zi' => 'DATE',
            'blob' => 'LONGBLOB',
            default => throw new \InvalidArgumentException('Tip necunoscut: ' . $tipAbstract),
        };
    }

    public function optiuniTabel(): string
    {
        return ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    public function upsert(string $tabelCitat, array $coloane, array $cheiConflict, array $actualizari): string
    {
        $col = implode(', ', array_map($this->citeaza(...), $coloane));
        $loc = implode(', ', array_fill(0, count($coloane), '?'));
        $set = [];
        foreach ($actualizari as $c => $v) {
            // VALUES(col) merge pe MariaDB și pe MySQL 8 (acolo e doar marcat ca învechit).
            $set[] = $this->citeaza($c) . ' = ' . (is_array($v) ? $v['expr'] : 'VALUES(' . $this->citeaza($c) . ')');
        }
        if ($set === []) {
            return "INSERT IGNORE INTO $tabelCitat ($col) VALUES ($loc)";
        }
        return "INSERT INTO $tabelCitat ($col) VALUES ($loc) ON DUPLICATE KEY UPDATE " . implode(', ', $set);
    }

    public function esteDuplicat(\PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    public function tabelExista(\PDO $pdo, string $tabel): bool
    {
        $s = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $s->execute([$tabel]);
        return (bool) $s->fetchColumn();
    }

    public function coloanaExista(\PDO $pdo, string $tabel, string $coloana): bool
    {
        $s = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $s->execute([$tabel, $coloana]);
        return (bool) $s->fetchColumn();
    }

    public function indexExista(\PDO $pdo, string $tabel, string $index): bool
    {
        $s = $pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
        $s->execute([$tabel, $index]);
        return (bool) $s->fetchColumn();
    }

    public function tabeleCuPrefix(\PDO $pdo, string $prefix): array
    {
        $s = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND LEFT(table_name, ?) = ? ORDER BY table_name');
        $s->execute([strlen($prefix), $prefix]);
        return $s->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function pregateste(\PDO $pdo): void
    {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }
}
