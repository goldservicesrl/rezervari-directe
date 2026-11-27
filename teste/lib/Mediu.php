<?php

declare(strict_types=1);

// Mediul unui test: baza proaspătă (SQLite în memorie sau MySQL cu prefix unic), ceasul fix,
// dosare temporare, aplicația gata construită. Totul se curăță după test.

use Cazo\Baza\Baza;
use Cazo\Baza\Migrator;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\CeasFix;
use Cazo\Nucleu\Config;
use Cazo\Nucleu\Ruta;
use Cazo\Securitate\Chei;

defined('CAZO') or exit;

final class Mediu
{
    /** 14.11.2026 10:00:00 UTC */
    public const ACUM = 1794650400;

    public bool $aFolositBaza = false;

    private ?Baza $baza = null;
    private ?CeasFix $ceas = null;
    private array $dosare = [];
    private ?string $prefixMysql = null;
    private ?string $fisierSqlite = null;

    public function __construct(public readonly string $dialect, private ?array $mysql)
    {
    }

    public static function radacina(): string
    {
        return dirname(__DIR__, 2);
    }

    public function ceas(): CeasFix
    {
        return $this->ceas ??= new CeasFix(self::ACUM);
    }

    public function dosarTemporar(): string
    {
        $d = sys_get_temp_dir() . '/cazo-test-' . bin2hex(random_bytes(6));
        mkdir($d, 0700, true);
        $this->dosare[] = $d;
        return $d;
    }

    /** Prefixul tabelelor pentru acest test (unic pe MySQL, ca testele să nu se calce). */
    public function prefix(): string
    {
        if ($this->dialect === 'mysql') {
            return $this->prefixMysql ??= 't' . bin2hex(random_bytes(4)) . '_';
        }
        return 'pg_';
    }

    /** Descrierea conexiunii (fără prefix), ca pentru config.php. */
    public function descriereBaza(): array
    {
        $this->aFolositBaza = true;
        if ($this->dialect === 'mysql') {
            return $this->mysql;
        }
        if ($this->fisierSqlite === null) {
            $this->fisierSqlite = $this->dosarTemporar() . '/test.sqlite';
        }
        return ['driver' => 'sqlite', 'cale' => $this->fisierSqlite];
    }

    /** Baza testului, cu migrările aplicate. SQLite: în memorie, dacă testul nu cere o bază partajată. */
    public function baza(bool $migrata = true): Baza
    {
        $this->aFolositBaza = true;
        if ($this->baza === null) {
            $this->baza = $this->dialect === 'mysql'
                ? Baza::conecteaza($this->mysql, $this->prefix())
                : new Baza(new \PDO('sqlite::memory:'), new \Cazo\Baza\DialectSqlite(), 'pg_');
            if ($migrata) {
                $this->migrator($this->baza)->migreaza();
            }
        }
        return $this->baza;
    }

    /** O bază partajabilă între conexiuni (fișier SQLite sau același MySQL); întoarce o conexiune nouă la fiecare apel. */
    public function conexiune(): Baza
    {
        $d = $this->descriereBaza();
        $b = Baza::conecteaza($d, $this->prefix());
        if (!$b->tabelExista('migrari')) {
            $this->migrator($b)->migreaza();
        }
        return $b;
    }

    public function migrator(Baza $b): Migrator
    {
        return new Migrator($b, $this->ceas(), self::radacina() . '/migrari', 'test');
    }

    public function chei(): Chei
    {
        return new Chei(str_repeat("\x42", 32));
    }

    /**
     * Aplicația, cu dosar privat temporar și baza testului.
     * @param Ruta[]|null $rute
     */
    public function aplicatie(array $config = [], ?array $rute = null): Aplicatie
    {
        $privat = $this->dosarTemporar();
        foreach (['cache', 'jurnale', 'sesiuni'] as $d) {
            mkdir("$privat/$d", 0700);
        }
        $cfg = new Config($config + [
            'id_instalare' => 'test',
            'site_url' => 'https://cazo.test',
            'limba_implicita' => 'ro',
            'limbi' => ['ro', 'en'],
            'rescriere' => true,
            'baza' => ['driver' => $this->dialect, 'prefix' => $this->prefix()],
        ], $privat);
        return new Aplicatie($cfg, $this->chei(), $this->baza(), $this->ceas(), $rute);
    }

    /**
     * Aplicația legată de un EasyCheck din transportul dat (fără rețea), cu cheia salvată și rezumatul ei din GET /eu.
     */
    public function aplicatieEc(TransportTest $t, array $config = [], ?array $eu = null): Aplicatie
    {
        $app = $this->aplicatie($config + ['cale_public' => $this->dosarTemporar()]);
        $app->transportEc = $t;
        $app->secrete()->pune('ec_cheie', FalsEasyCheck::CHEIE);
        // Fără $eu, cheia e a unui EasyCheck dinaintea „stării site-ului” (fără `functii` în GET /eu): softul tace, iar
        // cererile testului rămân cele pe care le numără el. Testele stării dau $eu cu `functii`.
        $app->ecLicenta()->dinEu($eu ?? array_diff_key(FalsEasyCheck::fixtura('eu-easycheck'), ['functii' => true]));
        return $app;
    }

    public function curata(): void
    {
        if ($this->dialect === 'mysql' && $this->prefixMysql !== null) {
            $b = Baza::conecteaza($this->mysql, $this->prefixMysql);
            foreach ($b->dialect()->tabeleCuPrefix($b->pdo(), $this->prefixMysql) as $t) {
                $b->pdo()->exec('DROP TABLE ' . $b->dialect()->citeaza($t));
            }
        }
        $this->baza = null;
        foreach ($this->dosare as $d) {
            self::stergeDosar($d);
        }
        $this->dosare = [];
    }

    public static function stergeDosar(string $d): void
    {
        if (is_link($d) || is_file($d)) {
            @unlink($d);
            return;
        }
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                self::stergeDosar("$d/$f");
            }
        }
        @rmdir($d);
    }
}
