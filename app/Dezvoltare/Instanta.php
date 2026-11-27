<?php

declare(strict_types=1);

namespace Cazo\Dezvoltare;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Config;
use Cazo\Securitate\Chei;
use Cazo\Securitate\Codare;
use Cazo\Securitate\Cutie;

defined('CAZO') or exit;

/**
 * Configurarea unei instanțe locale (bin/cazo configureaza și testele), cu aceeași structură ca pe server
 *: <dir>/cazo-privat/ și <dir>/public_html/.
 *
 * Lansarea: în dezvoltare, lansari/dev e o legătură simbolică spre depozit (codul se vede imediat); unde symlink()
 * e refuzat (găzduiri partajate, Windows fără drepturi), lansarea se copiază (legaLansarea). Pentru o arhivă
 * construită de bin/lansare.php, lansarea există deja și se păstrează.
 * Instalatorul (Cazo\Instalare) face aceiași pași pe server, cu sondele și cu ecranele lui.
 */
final class Instanta
{
    public const DOSARE = ['cache', 'cache/pagini', 'media', 'media/original', 'teme', 'lucru', 'sesiuni', 'jurnale', 'copii', 'lansari'];

    /**
     * @param array{site_url?:string, baza?:array, prefix?:string, rescriere?:bool, lansare_dev?:?string, limba?:string, easycheck?:array} $optiuni
     *   baza: ['driver' => 'sqlite'] (implicit, cazo.sqlite) sau descrierea MySQL cu parola în clar (se criptează aici)
     *   easycheck: legătura spre un server EasyCheck de test (['baza' => 'http://...']); pune config-ul în mediul de test
     * @return array{privat:string, public:string, cron_token:string}
     */
    public static function configureaza(string $dir, array $optiuni = []): array
    {
        $privat = $dir . '/cazo-privat';
        $public = $dir . '/public_html';
        foreach ([$privat, $public] as $d) {
            if (!is_dir($d) && !mkdir($d, 0700, true) && !is_dir($d)) {
                throw new \RuntimeException('Dosarul nu s-a putut crea: ' . $d);
            }
        }
        \Cazo\Nucleu\Functii::chmod($privat, 0700);
        foreach (self::DOSARE as $d) {
            if (!is_dir("$privat/$d")) {
                mkdir("$privat/$d", 0700, true);
            }
        }

        // Lansarea de dezvoltare: legătură spre depozit și curent.php = 'dev'.
        if (($optiuni['lansare_dev'] ?? null) !== null) {
            $link = "$privat/lansari/dev";
            if (!file_exists($link)) {
                self::legaLansarea($optiuni['lansare_dev'], $link);
            }
            file_put_contents("$privat/curent.php", "<?php\n\ndefined('CAZO') or exit;\n\nreturn 'dev';\n");
            \Cazo\Nucleu\Functii::chmod("$privat/curent.php", 0600);
            copy($optiuni['lansare_dev'] . '/bin/cron-privat.php', "$privat/cron.php");
            \Cazo\Nucleu\Functii::chmod("$privat/cron.php", 0600);
            $sursaPublic = $optiuni['lansare_dev'] . '/public';
            foreach (['index.php', '.htaccess'] as $f) {
                copy("$sursaPublic/$f", "$public/$f");
            }
        }

        $chei = is_file("$privat/cheie.bin") ? Chei::dinFisier("$privat/cheie.bin") : Chei::genereaza("$privat/cheie.bin");

        $baza = $optiuni['baza'] ?? ['driver' => 'sqlite'];
        if ($baza['driver'] === 'sqlite') {
            $baza = ['driver' => 'sqlite', 'cale' => $baza['cale'] ?? 'cazo.sqlite'];
        } else {
            $baza['parola_c'] = (new Cutie($chei->subcheie('config')))->cripteaza((string) ($baza['parola'] ?? ''));
            unset($baza['parola']);
        }
        $baza['prefix'] = $optiuni['prefix'] ?? 'pg_';

        $existent = is_file("$privat/config.php") ? (static fn (): array => require "$privat/config.php")() : [];
        $config = [
            'id_instalare' => $existent['id_instalare'] ?? bin2hex(random_bytes(16)),
            'site_url' => $optiuni['site_url'] ?? 'http://localhost:8080',
            'limba_implicita' => $optiuni['limba'] ?? 'ro',
            'limbi' => ['ro', 'en'],
            'rescriere' => $optiuni['rescriere'] ?? true,
            'cale_public' => $public,
            'baza' => $baza,
            'instalat_la' => $existent['instalat_la'] ?? gmdate('Y-m-d H:i:s'),
        ];
        $easycheck = $optiuni['easycheck'] ?? $existent['easycheck'] ?? null;
        if (is_array($easycheck)) {
            // Serverul EasyCheck de test (un server de test sau serverul fals): doar în mediul de test.
            $config['mediu'] = 'test';
            $config['easycheck'] = $easycheck;
        }
        Config::scrie($privat, $config);
        // Instanța e instalată: instalatorul rămâne închis, chiar dacă config.php lipsește mai târziu (15 pasul 12).
        if (!is_file("$privat/" . \Cazo\Instalare\Loc::MARCAJ)) {
            \Cazo\Instalare\Loc::scrieMarcaj($privat, $config['instalat_la']);
        }

        file_put_contents("$public/.cazo-cale.php", "<?php\n\nreturn " . var_export($privat, true) . ";\n");

        $app = Aplicatie::dinDosar($privat);
        $app->migrator()->migreaza();
        $token = $app->secrete()->citeste('cron_token');
        if ($token === null) {
            $token = Codare::jeton(32);
            $app->secrete()->pune('cron_token', $token);
        }
        return ['privat' => $privat, 'public' => $public, 'cron_token' => $token];
    }

    /**
     * lansari/dev: legătura simbolică spre depozit sau, unde symlink() e refuzat ori lipsește, o copie a dosarelor
     * lansării (Lansare::DOSARE și front/). Întoarce „legatura” sau „copie”.
     * @param ?\Closure(string, string): bool $symlink pentru teste (o găzduire care refuză legăturile)
     */
    public static function legaLansarea(string $sursa, string $link, ?\Closure $symlink = null): string
    {
        $symlink ??= static fn (string $tinta, string $nume): bool => function_exists('symlink') && @symlink($tinta, $nume);
        if ($symlink($sursa, $link)) {
            return 'legatura';
        }
        if (!is_dir($link) && !mkdir($link, 0700, true) && !is_dir($link)) {
            throw new \RuntimeException('Lansarea de dezvoltare nu s-a putut crea: ' . $link);
        }
        foreach (array_merge(Lansare::DOSARE, ['front']) as $d) {
            if (is_dir("$sursa/$d")) {
                self::copiaza("$sursa/$d", "$link/$d");
            }
        }
        return 'copie';
    }

    /** Copierea unui dosar, fără legături simbolice (o legătură din depozit ar ieși din lansare). */
    public static function copiaza(string $din, string $in): void
    {
        if (!is_dir($in) && !mkdir($in, 0700, true) && !is_dir($in)) {
            throw new \RuntimeException('Dosarul nu s-a putut crea: ' . $in);
        }
        $lista = @scandir($din);
        if ($lista === false) {
            throw new \RuntimeException('Dosarul nu s-a putut citi: ' . $din);
        }
        foreach ($lista as $f) {
            if ($f === '.' || $f === '..' || is_link("$din/$f")) {
                continue;
            }
            if (is_dir("$din/$f")) {
                self::copiaza("$din/$f", "$in/$f");
            } elseif (!@copy("$din/$f", "$in/$f")) {
                throw new \RuntimeException('Fișierul nu s-a putut copia: ' . "$din/$f");
            }
        }
    }
}
