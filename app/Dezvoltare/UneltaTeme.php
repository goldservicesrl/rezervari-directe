<?php

declare(strict_types=1);

namespace Cazo\Dezvoltare;

use Cazo\Teme\Active;
use Cazo\Teme\Despachetare;
use Cazo\Teme\Raport;
use Cazo\Teme\Validator;

defined('CAZO') or exit;

/**
 * Unealta autorilor de teme, `cazo-tema`, fără Node:
 *
 *   init <dosar> [--nume="Numele temei"]          o temă nouă, copiată din _pornire
 *   verifica <dosar> [--fara-proba] [--json]       validatorul complet, același ca pe server
 *   previzualizeaza <dosar> [--port=8090] [--varianta=normal|extrem]
 *                                                  un server local cu fixturile de probă; modificările se văd la reîncărcarea paginii
 *   impacheteaza <dosar> [--iesire=tema.zip]       verificarea, apoi arhiva zip deterministă și raportul (tema.raport.json)
 *
 * Sursa e bin/cazo-tema; `bin/cazo tema-phar` o împachetează cu nucleul în cazo-tema.phar.
 */
final class UneltaTeme
{
    /** Data fixă a intrărilor din arhivă (1 ianuarie 1980, cea mai veche dată din formatul zip): aceeași temă dă aceeași arhivă. */
    public const DATA_ZIP = 315532800;

    public function __construct(private string $radacina, private $iesire = null)
    {
        $this->iesire ??= STDOUT;
    }

    private function scrie(string $s): void
    {
        fwrite($this->iesire, $s);
    }

    /** @param list<string> $argv argumentele, fără numele programului */
    public function ruleaza(array $argv): int
    {
        $comanda = $argv[0] ?? 'ajutor';
        $poz = [];
        $opt = [];
        foreach (array_slice($argv, 1) as $a) {
            if (preg_match('~^--([a-z\-]+)(?:=(.*))?$~', $a, $m)) {
                $opt[$m[1]] = $m[2] ?? true;
            } else {
                $poz[] = $a;
            }
        }
        // O cale absolută rămâne așa: /… pe Linux și macOS, C:\… sau C:/… pe Windows (unealta rulează la autor).
        $dosar = isset($poz[0]) ? (preg_match('~^(/|[A-Za-z]:[/\\\\])~', $poz[0]) ? $poz[0] : getcwd() . '/' . $poz[0]) : null;
        try {
            return match ($comanda) {
                'init' => $this->init($dosar, $opt),
                'verifica' => $this->verifica($dosar, $opt),
                'previzualizeaza' => $this->previzualizeaza($dosar, $opt),
                'impacheteaza' => $this->impacheteaza($dosar, $opt),
                default => $this->ajutor(),
            };
        } catch (\Throwable $e) {
            $this->scrie('Eroare: ' . $e->getMessage() . "\n");
            return 2;
        }
    }

    private function ajutor(): int
    {
        $this->scrie("cazo-tema: unealta pentru teme\n\n"
            . "  init <dosar> --autor=\"Numele autorului\" [--nume=\"Numele temei\"] [--licenta=MIT]\n"
            . "                                              o temă nouă, din tema de pornire\n"
            . "  verifica <dosar> [--fara-proba] [--json]    validatorul complet, același ca pe server\n"
            . "  previzualizeaza <dosar> [--port=8090] [--varianta=normal|extrem]\n"
            . "                                              site-ul de probă pe http://127.0.0.1:<port>\n"
            . "  impacheteaza <dosar> [--iesire=tema.zip]    arhiva zip de încărcat în panou, cu raportul\n");
        return 0;
    }

    private function init(?string $dosar, array $opt): int
    {
        if ($dosar === null) {
            throw new \InvalidArgumentException('Spuneți dosarul temei noi: cazo-tema init tema-mea');
        }
        if (file_exists($dosar) && (glob($dosar . '/*') ?: []) !== []) {
            throw new \RuntimeException('Dosarul există și are fișiere: ' . $dosar);
        }
        $autor = is_string($opt['autor'] ?? null) ? trim($opt['autor']) : '';
        if ($autor === '') {
            throw new \InvalidArgumentException('Spuneți autorul temei: cazo-tema init tema-mea --autor="Numele dumneavoastră"');
        }
        $licenta = is_string($opt['licenta'] ?? null) && trim($opt['licenta']) !== '' ? trim($opt['licenta']) : 'MIT';
        $sursa = $this->radacina . '/teme/_pornire';
        foreach (Active::lista($sursa) as $rel) {
            if (!is_dir(dirname($dosar . '/' . $rel))) {
                mkdir(dirname($dosar . '/' . $rel), 0755, true);
            }
            copy($sursa . '/' . $rel, $dosar . '/' . $rel);
        }
        $slug = Validator::slugDin(basename($dosar));
        $m = json_decode((string) file_get_contents($dosar . '/theme.json'), true);
        $nume = is_string($opt['nume'] ?? null) && trim($opt['nume']) !== '' ? trim($opt['nume']) : ucfirst(str_replace('-', ' ', $slug));
        $m['slug'] = $slug;
        $m['nume'] = ['ro' => $nume, 'en' => $nume];
        $m['versiune'] = '0.1.0';
        $m['autor'] = ['nume' => $autor];
        $m['licenta'] = $licenta;
        // LICENTA.txt e al autorului: anul, numele lui și licența aleasă (nu copyrightul temei de pornire).
        file_put_contents($dosar . '/LICENTA.txt', $nume . "\n\nCopyright (C) " . gmdate('Y') . ' ' . $autor . "\n\nLicența: " . $licenta . "\n");
        file_put_contents($dosar . '/theme.json', json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        $this->scrie("Tema „{$nume}” e gata în {$dosar}.\nUrmătorii pași: cazo-tema previzualizeaza {$dosar}, apoi cazo-tema verifica {$dosar}.\n");
        return 0;
    }

    private function dosarTema(?string $dosar): string
    {
        if ($dosar === null || !is_file($dosar . '/theme.json')) {
            throw new \InvalidArgumentException('Dosarul nu are theme.json: ' . ($dosar ?? '(lipsă)'));
        }
        return rtrim((string) realpath($dosar), '/');
    }

    private function raport(string $dosar, array $opt): Raport
    {
        $lucru = sys_get_temp_dir() . '/cazo-tema-' . bin2hex(random_bytes(4));
        mkdir($lucru, 0700, true);
        try {
            return Validator::verifica($dosar, false, ['proba' => !isset($opt['fara-proba']), 'lucru' => $lucru, 'radacina' => $this->radacina]);
        } finally {
            Despachetare::sterge($lucru);
        }
    }

    private function verifica(?string $dosar, array $opt): int
    {
        $dosar = $this->dosarTema($dosar);
        $r = $this->raport($dosar, $opt);
        $this->scrie(isset($opt['json']) ? json_encode($r->tablou(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n" : $r->text());
        return $r->trece() ? 0 : 1;
    }

    private function impacheteaza(?string $dosar, array $opt): int
    {
        $dosar = $this->dosarTema($dosar);
        $r = $this->raport($dosar, $opt);
        $this->scrie($r->text());
        if (!$r->trece()) {
            return 1;
        }
        $m = json_decode((string) file_get_contents($dosar . '/theme.json'), true);
        $zip = is_string($opt['iesire'] ?? null) ? $opt['iesire'] : getcwd() . '/' . Validator::slugDin((string) ($m['slug'] ?? 'tema')) . '-' . ($m['versiune'] ?? '0') . '.zip';
        self::zip($dosar, $zip);
        file_put_contents(preg_replace('~\.zip$~', '', $zip) . '.raport.json', json_encode($r->tablou(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        $this->scrie('Arhiva: ' . $zip . ' (' . round((int) filesize($zip) / 1024) . " KB), sha256 " . hash_file('sha256', $zip) . "\n");
        return 0;
    }

    /** Arhiva deterministă: intrările sortate, data fixă, aceleași drepturi, compresia implicită. */
    public static function zip(string $dosar, string $zip): void
    {
        if (is_file($zip)) {
            unlink($zip);
        }
        $z = new \ZipArchive();
        if ($z->open($zip, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('Arhiva nu se poate crea: ' . $zip);
        }
        foreach (Active::lista($dosar) as $rel) {
            $z->addFromString($rel, (string) file_get_contents($dosar . '/' . $rel));
            $z->setCompressionName($rel, \ZipArchive::CM_DEFLATE, 9);
            $z->setMtimeName($rel, self::DATA_ZIP);
            $z->setExternalAttributesName($rel, \ZipArchive::OPSYS_UNIX, 0100644 << 16);
        }
        $z->close();
        self::oraFixa($zip);
    }

    /**
     * Ora din antetele zip (formatul DOS, fără fus orar): libzip o scrie în ora locală a mașinii, deci aceeași temă ar da
     * arhive diferite la autori din fusuri diferite. Toate intrările primesc 1980-01-01 00:00, direct în octeți (în
     * antetul local și în directorul central), oricare ar fi fusul orar.
     */
    public static function oraFixa(string $zip): void
    {
        $z = (string) file_get_contents($zip);
        $eocd = strrpos($z, pack('V', 0x06054b50));
        if ($eocd === false) {
            throw new \RuntimeException('Arhiva nu are directorul central: ' . $zip);
        }
        ['n' => $n, 'off' => $poz] = unpack('vn/x4/Voff', $z, $eocd + 10);
        $ora = pack('vv', 0, (0 << 9) | (1 << 5) | 1);
        for ($i = 0; $i < $n; $i++) {
            if (substr($z, $poz, 4) !== pack('V', 0x02014b50)) {
                throw new \RuntimeException('Directorul central e deteriorat: ' . $zip);
            }
            ['nume' => $ln, 'extra' => $le, 'com' => $lc] = unpack('vnume/vextra/vcom', $z, $poz + 28);
            $local = unpack('V', $z, $poz + 42)[1];
            $z = substr_replace($z, $ora, $poz + 12, 4);
            $z = substr_replace($z, $ora, $local + 10, 4);
            $poz += 46 + $ln + $le + $lc;
        }
        file_put_contents($zip, $z);
    }

    /** Serverul local: php -S cu un router care randează tema pe site-ul de probă (ProbaTema), la fiecare cerere. */
    private function previzualizeaza(?string $dosar, array $opt): int
    {
        $dosar = $this->dosarTema($dosar);
        $port = (int) ($opt['port'] ?? 8090);
        $varianta = in_array($opt['varianta'] ?? 'normal', ['normal', 'extrem'], true) ? (string) ($opt['varianta'] ?? 'normal') : 'normal';
        $lucru = sys_get_temp_dir() . '/cazo-tema-previzualizare-' . $port;
        Despachetare::sterge($lucru);
        mkdir($lucru . '/site/public', 0700, true);
        $router = $lucru . '/router.php';
        file_put_contents($router, "<?php\ndefine('CAZO', true);\nrequire " . var_export($this->radacina . '/app/incarca.php', true) . ";\n"
            . 'return \Cazo\Dezvoltare\SiteProba::serveste(' . var_export($dosar, true) . ', ' . var_export($lucru, true) . ', ' . var_export($varianta, true) . ");\n");
        $this->scrie("Previzualizarea temei pe http://127.0.0.1:{$port}/ (varianta {$varianta}). Opriți serverul cu Ctrl+C.\n");
        passthru(escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg('127.0.0.1:' . $port) . ' -t ' . escapeshellarg($lucru . '/site/public') . ' ' . escapeshellarg($router), $cod);
        return (int) $cod;
    }
}
