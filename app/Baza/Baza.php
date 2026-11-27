<?php

declare(strict_types=1);

namespace Cazo\Baza;

defined('CAZO') or exit;

/**
 * Legătura cu baza: PDO, prefixul tabelelor și dialectul.
 * În SQL, tabelele se scriu ca {nume}; Baza le înlocuiește cu numele prefixat și citat.
 */
final class Baza
{
    /**
     * Cei mai mulți parametri (`?`) puși într-o singură instrucțiune. SQLite mai vechi de 3.32, cum are PHP-ul din
     * unele găzduiri cPanel, primește cel mult 999 de parametri pe instrucțiune și răspunde peste ei cu „too many SQL
     * variables”; PHP nu poate coborî limita unui SQLite nou, așa că regula se ține în cod: orice listă de `?` construită
     * din date (IN (...), VALUES pe mai multe rânduri) se împarte cu `pachete()` sau se plafonează sub această limită.
     */
    public const PARAMETRI_MAX = 900;

    /** Variabila de mediu care pornește plasa de mai jos: rulatorul de teste o pune pe PARAMETRI_MAX. */
    public const MEDIU_LIMITA = 'CAZO_LIMITA_PARAMETRI';

    /** Limita verificată la fiecare instrucțiune (0 = fără verificare); null până la prima citire a mediului. */
    private static ?int $limitaParametri = null;

    private int $adancimeTranzactie = 0;

    public function __construct(private \PDO $pdo, private Dialect $dialect, private string $prefix = 'pg_')
    {
        if (!preg_match('~^[a-z][a-z0-9_]{0,23}$~', $prefix)) {
            throw new \InvalidArgumentException('Prefix de tabel invalid.');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        $dialect->pregateste($pdo);
    }

    /**
     * Conectarea din descrierea din config.php.
     * SQLite: ['driver' => 'sqlite', 'cale' => '...']; MySQL: ['driver' => 'mysql', 'gazda', 'port', 'nume', 'utilizator', 'parola'].
     */
    public static function conecteaza(array $c, string $prefix = 'pg_'): self
    {
        $driver = $c['driver'] ?? '';
        if ($driver === 'sqlite') {
            self::creeazaFisierPrivat((string) $c['cale']);
            $pdo = new \PDO('sqlite:' . $c['cale']);
            return new self($pdo, new DialectSqlite(), $prefix);
        }
        if ($driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['gazda'] ?? 'localhost', (int) ($c['port'] ?? 3306), $c['nume'] ?? '');
            $pdo = new \PDO($dsn, $c['utilizator'] ?? '', $c['parola'] ?? '', [\PDO::ATTR_TIMEOUT => 5]);
            return new self($pdo, new DialectMysql(), $prefix);
        }
        throw new \InvalidArgumentException('Driver de bază necunoscut.');
    }

    /**
     * Fișierul SQLite nou se creează gol cu 0600, înaintea conexiunii; SQLite îl tratează
     * ca bază nouă, iar fișierele -wal și -shm primesc aceleași permisiuni.
     */
    private static function creeazaFisierPrivat(string $cale): void
    {
        if ($cale === '' || $cale === ':memory:' || str_starts_with($cale, 'file:') || file_exists($cale)) {
            return;
        }
        $vechi = \Cazo\Nucleu\Functii::umask(0077);
        try {
            @touch($cale);
        } finally {
            \Cazo\Nucleu\Functii::restabilesteUmask($vechi);
        }
        \Cazo\Nucleu\Functii::chmod($cale, 0600);
    }

    /** Descrierea MySQL dintr-un URL mysql://utilizator:parola@gazda:port/nume (teste și CLI). */
    public static function descriereDinUrl(string $url): array
    {
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'mysql' || !isset($p['host'], $p['path'])) {
            throw new \InvalidArgumentException('URL MySQL invalid; forma: mysql://utilizator:parola@gazda:port/baza');
        }
        return [
            'driver' => 'mysql',
            'gazda' => $p['host'],
            'port' => (int) ($p['port'] ?? 3306),
            'nume' => ltrim($p['path'], '/'),
            'utilizator' => rawurldecode($p['user'] ?? ''),
            'parola' => rawurldecode($p['pass'] ?? ''),
        ];
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** Numele complet (prefixat), necitat. */
    public function numeTabel(string $tabel): string
    {
        return $this->prefix . $tabel;
    }

    /** Numele complet, citat pentru SQL. */
    public function t(string $tabel): string
    {
        return $this->dialect->citeaza($this->prefix . $tabel);
    }

    public function sql(string $sql): string
    {
        return preg_replace_callback('~\{([a-z_][a-z0-9_]*)\}~', fn (array $m): string => $this->t($m[1]), $sql);
    }

    /**
     * Plasa pentru SQLite-ul vechi: cu variabila de mediu CAZO_LIMITA_PARAMETRI pusă (rulatorul de teste o pune
     * pe 900, și pentru serverele pornite de el), o instrucțiune cu mai mulți parametri aruncă o excepție, pe orice
     * bază. Așa, un test care trece limita pică și pe mașinile cu SQLite nou, nu abia pe serverul gazdei. Fără
     * variabilă (site-urile instalate), nimic verificat aici.
     */
    public static function limitaParametri(): int
    {
        if (self::$limitaParametri === null) {
            $v = getenv(self::MEDIU_LIMITA);
            self::$limitaParametri = is_string($v) && ctype_digit($v) ? (int) $v : 0;
        }
        return self::$limitaParametri;
    }

    /** Doar pentru teste: pune (sau, cu null, recitește din mediu) limita verificată. */
    public static function puneLimitaParametri(?int $limita): void
    {
        self::$limitaParametri = $limita;
    }

    private static function verificaParametrii(int $cati, string $sql): void
    {
        $limita = self::limitaParametri();
        if ($limita > 0 && $cati > $limita) {
            throw new \LogicException('Instrucțiune SQL cu ' . $cati . ' parametri, peste limita de ' . $limita . ' (SQLite mai vechi de 3.32 primește cel mult 999): împărțiți-o cu Baza::pachete(). '
                . mb_substr($sql, 0, 120));
        }
    }

    /**
     * Împarte o listă în pachete care încap într-o instrucțiune: câte PARAMETRI_MAX valori, sau, la rândurile cu mai
     * multe coloane (VALUES (...), (...)), câte rânduri încap sub limită (cel puțin unul, cel mult $celMult).
     * @template T
     * @param list<T> $lista
     * @return list<list<T>>
     */
    public static function pachete(array $lista, int $parametriPeElement = 1, int $celMult = self::PARAMETRI_MAX): array
    {
        return $lista === [] ? [] : array_chunk($lista, self::marimePachet($parametriPeElement, $celMult));
    }

    /** Câte elemente, fiecare cu $parametriPeElement parametri, intră într-o instrucțiune (între 1 și $celMult). */
    public static function marimePachet(int $parametriPeElement, int $celMult = self::PARAMETRI_MAX): int
    {
        return max(1, min($celMult, intdiv(self::PARAMETRI_MAX, max(1, $parametriPeElement))));
    }

    public function executa(string $sql, array $parametri = []): \PDOStatement
    {
        self::verificaParametrii(count($parametri), $sql);
        $s = $this->pdo->prepare($this->sql($sql));
        $s->execute($parametri);
        return $s;
    }

    public function rand(string $sql, array $parametri = []): ?array
    {
        $r = $this->executa($sql, $parametri)->fetch();
        return $r === false ? null : $r;
    }

    public function toate(string $sql, array $parametri = []): array
    {
        return $this->executa($sql, $parametri)->fetchAll();
    }

    public function valoare(string $sql, array $parametri = []): mixed
    {
        $v = $this->executa($sql, $parametri)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** INSERT simplu; întoarce id-ul generat (sau 0 la tabelele fără autoincrement). */
    public function insereaza(string $tabel, array $valori): int
    {
        $col = implode(', ', array_map($this->dialect->citeaza(...), array_keys($valori)));
        $loc = implode(', ', array_fill(0, count($valori), '?'));
        $this->executa('INSERT INTO ' . $this->t($tabel) . " ($col) VALUES ($loc)", array_values($valori));
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * INSERT cu actualizare la conflict.
     * $actualizari: lista coloanelor care primesc valoarea nouă, sau coloana => ['expr' => 'SQL'].
     */
    public function upsert(string $tabel, array $valori, array $cheiConflict, array $actualizari): void
    {
        $norm = [];
        foreach ($actualizari as $k => $v) {
            if (is_int($k)) {
                $norm[$v] = 'nou';
            } else {
                $norm[$k] = $v;
            }
        }
        $sql = $this->dialect->upsert($this->t($tabel), array_keys($valori), $cheiConflict, $norm);
        self::verificaParametrii(count($valori), $sql);
        $s = $this->pdo->prepare($sql);
        $s->execute(array_values($valori));
    }

    public function esteDuplicat(\PDOException $e): bool
    {
        return $this->dialect->esteDuplicat($e);
    }

    /** O tranzacție e deschisă acum (prin `tranzactie` sau direct pe PDO): fără cereri în rețea până la încheierea ei. */
    public function inTranzactie(): bool
    {
        return $this->adancimeTranzactie > 0 || $this->pdo->inTransaction();
    }

    /**
     * Rulează $f într-o tranzacție; tranzacțiile imbricate se contopesc în cea exterioară.
     * @template T
     * @param callable(self): T $f
     * @return T
     */
    public function tranzactie(callable $f): mixed
    {
        if ($this->adancimeTranzactie > 0) {
            $this->adancimeTranzactie++;
            try {
                return $f($this);
            } finally {
                $this->adancimeTranzactie--;
            }
        }
        $this->pdo->beginTransaction();
        $this->adancimeTranzactie = 1;
        try {
            $rezultat = $f($this);
            $this->pdo->commit();
            return $rezultat;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->adancimeTranzactie = 0;
        }
    }

    public function tabelExista(string $tabel): bool
    {
        return $this->dialect->tabelExista($this->pdo, $this->prefix . $tabel);
    }

    public function coloanaExista(string $tabel, string $coloana): bool
    {
        return $this->dialect->coloanaExista($this->pdo, $this->prefix . $tabel, $coloana);
    }

    /** @return string[] numele tabelelor acestei instalări (fără prefix) */
    public function tabele(): array
    {
        $l = strlen($this->prefix);
        return array_map(fn (string $t): string => substr($t, $l), $this->dialect->tabeleCuPrefix($this->pdo, $this->prefix));
    }
}
