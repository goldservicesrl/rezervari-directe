<?php

declare(strict_types=1);

namespace Cazo\Baza;

defined('CAZO') or exit;

/**
 * Diferențele dintre MySQL/MariaDB și SQLite, într-un singur loc:
 * tipurile coloanelor, ghilimelele numelor, upsert, recunoașterea duplicatelor, introspecția.
 */
interface Dialect
{
    public function nume(): string;

    public function citeaza(string $identificator): string;

    /** Tipul SQL pentru un tip abstract al schemei (id, cheie, text, textlung, int, bigint, bool, moment, zi, json, blob). */
    public function tip(string $tipAbstract, ?int $lungime): string;

    /** Sufixul instrucțiunii CREATE TABLE (motorul și setul de caractere la MySQL). */
    public function optiuniTabel(): string;

    /**
     * INSERT ... cu actualizare la conflict pe cheia unică.
     * $actualizari: coloana => 'nou' (valoarea propusă) sau o expresie SQL brută ['expr' => '...'].
     */
    public function upsert(string $tabelCitat, array $coloane, array $cheiConflict, array $actualizari): string;

    public function esteDuplicat(\PDOException $e): bool;

    public function tabelExista(\PDO $pdo, string $tabel): bool;

    public function coloanaExista(\PDO $pdo, string $tabel, string $coloana): bool;

    public function indexExista(\PDO $pdo, string $tabel, string $index): bool;

    /** @return string[] tabelele care încep cu prefixul dat */
    public function tabeleCuPrefix(\PDO $pdo, string $prefix): array;

    /** Setările de sesiune după conectare. */
    public function pregateste(\PDO $pdo): void;
}
