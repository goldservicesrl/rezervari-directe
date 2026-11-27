<?php

declare(strict_types=1);

namespace Cazo\Dezvoltare;

use Cazo\Teme\Active;

defined('CAZO') or exit;

/**
 * Construcția lui cazo-tema.phar: nucleul de care are nevoie validatorul (app, vederi, limbi,
 * migrari, scheme, vendor, front), biblioteca _schelet, tema de pornire și unealta (bin/cazo-tema). La prima rulare,
 * arhiva se despachetează într-un dosar din cache-ul utilizatorului, după amprenta ei, și unealta rulează de acolo:
 * Twig, SQLite și php -S lucrează apoi cu fișiere obișnuite.
 */
final class TemaPhar
{
    public const DOSARE = ['app', 'vederi', 'limbi', 'migrari', 'scheme', 'vendor', 'front', 'teme/_schelet', 'teme/_pornire'];
    public const FISIERE = ['bin/cazo-tema'];

    /** @return array{cale:string, fisiere:int, sha256:string} */
    public static function construieste(string $radacina, string $iesire): array
    {
        if (!class_exists(\Phar::class)) {
            throw new \RuntimeException('Extensia PHP phar lipsește.');
        }
        if (ini_get('phar.readonly')) {
            throw new \RuntimeException('Rulați cu php -d phar.readonly=0.');
        }
        if (is_file($iesire)) {
            unlink($iesire);
        }
        $fisiere = self::FISIERE;
        foreach (self::DOSARE as $d) {
            foreach (Active::lista($radacina . '/' . $d) as $rel) {
                $fisiere[] = $d . '/' . $rel;
            }
        }
        sort($fisiere, SORT_STRING);
        $p = new \Phar($iesire, 0, 'cazo-tema.phar');
        $p->startBuffering();
        foreach ($fisiere as $rel) {
            $p->addFromString($rel, (string) file_get_contents($radacina . '/' . $rel));
        }
        $p->setStub(self::stub());
        $p->stopBuffering();
        unset($p);
        \Cazo\Nucleu\Functii::chmod($iesire, 0755);
        return ['cale' => $iesire, 'fisiere' => count($fisiere), 'sha256' => hash_file('sha256', $iesire)];
    }

    private static function stub(): string
    {
        return <<<'PHP'
#!/usr/bin/env php
<?php
// cazo-tema.phar: despachetează nucleul în cache (o dată pe versiune), apoi rulează unealta de acolo.
if (PHP_SAPI !== 'cli') { exit; }
$f = __FILE__;
$casa = getenv('XDG_CACHE_HOME') ?: ((getenv('HOME') ?: sys_get_temp_dir()) . '/.cache');
$d = $casa . '/cazo-tema/' . substr(hash_file('sha256', $f), 0, 16);
if (!is_file($d . '/.gata')) {
    @mkdir($d, 0700, true);
    (new Phar($f))->extractTo($d, null, true);
    touch($d . '/.gata');
}
$argv[0] = $d . '/bin/cazo-tema';
$_SERVER['argv'] = $argv;
require $d . '/bin/cazo-tema';
__HALT_COMPILER();
PHP;
    }
}
