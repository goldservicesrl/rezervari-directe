<?php

declare(strict_types=1);

namespace Cazo\Instalare;

defined('CAZO') or exit;

/**
 * Sonda folderului privat (varianta B): cereri HTTP reale, din server spre propriul site,
 * la fișierele din dosarul privat aflat în webroot. Doar 403 sau 404 trec; orice alt răspuns (200, o redirecționare,
 * 500) sau lipsa unui răspuns oprește instalarea.
 *
 * La trecerea de verificări se întreabă fișiere-capcană cu extensiile care contează: .php (ca config.php), .bin (ca cheie.bin),
 * .sqlite și .txt, plus curent.php; la încheiere se repetă pe fișierele reale (cheie.bin, configurarea, baza SQLite).
 * La varianta A, dosarul privat stă deasupra webroot, deci serverul web nu are o adresă pentru el.
 */
final class Sonda
{
    public const CAPCANE = ['php', 'bin', 'sqlite', 'txt'];

    /**
     * @param \Closure(string): array{status:?int, corp:string, eroare:?string} $http
     * @param list<string> $fisiere fișierele reale de întrebat (căi relative la dosarul privat), pe lângă capcane
     * @return array{necesara:bool, ok:bool, rezultate:list<array{fisier:string, status:?int, ok:bool}>}
     */
    public static function verifica(string $origine, string $public, string $privat, \Closure $http, array $fisiere = [], bool $capcane = true, ?string $documentRoot = null): array
    {
        // Webroot-ul care cuprinde dosarul: DOCUMENT_ROOT-ul (adresele reale), altfel dosarul lui index.php.
        $radacina = Loc::radacinaWeb($public, $documentRoot, $privat);
        if ($radacina === null) {
            return ['necesara' => false, 'ok' => true, 'rezultate' => []];
        }
        $rel = substr(rtrim($privat, '/'), strlen($radacina));
        $cale = implode('/', array_map('rawurlencode', explode('/', $rel)));
        $scrise = [];
        $intrebate = [];
        if ($capcane) {
            $marcaj = 'cazo-sonda-' . bin2hex(random_bytes(8));
            foreach (self::CAPCANE as $ext) {
                $f = "sonda-$marcaj.$ext";
                // Capcana PHP: dacă serverul o execută, iese doar „exit”, cu 200 (oprește instalarea la fel).
                file_put_contents("$privat/$f", $ext === 'php' ? "<?php exit; ?>\n$marcaj\n" : "$marcaj\n");
                $scrise[] = "$privat/$f";
                $intrebate[] = $f;
            }
            if (is_file("$privat/curent.php")) {
                $intrebate[] = 'curent.php';
            }
        }
        foreach ($fisiere as $f) {
            if (is_file("$privat/$f")) {
                $intrebate[] = $f;
            }
        }
        $rezultate = [];
        try {
            foreach (array_values(array_unique($intrebate)) as $f) {
                $r = $http(rtrim($origine, '/') . $cale . '/' . implode('/', array_map('rawurlencode', explode('/', $f))));
                $ok = in_array($r['status'], [403, 404], true);
                $rezultate[] = ['fisier' => $f, 'status' => $r['status'], 'ok' => $ok];
            }
        } finally {
            foreach ($scrise as $f) {
                @unlink($f);
            }
        }
        return ['necesara' => true, 'ok' => $rezultate !== [] && !in_array(false, array_column($rezultate, 'ok'), true), 'rezultate' => $rezultate];
    }
}
