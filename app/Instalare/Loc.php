<?php

declare(strict_types=1);

namespace Cazo\Instalare;

use Cazo\Dezvoltare\Instanta;

defined('CAZO') or exit;

/**
 * Locul datelor:
 *
 *   A  cazo-privat/ deasupra webroot (/home/<cont>/cazo-privat), varianta preferată;
 *   B  public_html/cazo-privat-<16 caractere aleatoare>/, cu .htaccess „Require all denied”, când găzduirea lasă
 *      scrierea doar în public_html sau când arhiva a fost dezarhivată chiar în public_html.
 *
 * Alegerea se face la prima vizită a instalatorului (codul de instalare se scrie deja în locul final), iar pasul verificărilor
 * o arată și, la B, o confirmă prin sondă. Instalatorul o reverifică la fiecare cerere: un dosar privat ajuns între
 * timp în webroot (de exemplu după mutarea lui index.php) primește și el numele aleator și protecția. Webroot-ul
 * înseamnă dosarul lui index.php și, când îl cuprinde, DOCUMENT_ROOT-ul serverului web (radacinaWeb). Numele
 * fișierelor instalatorului stau aici.
 */
final class Loc
{
    public const COD = 'COD-INSTALARE.txt';
    public const STARE = 'instalare/stare.php';
    public const CONFIG_LUCRU = 'config-instalare.php';
    /** Marcajul instalării încheiate: cu el, instalatorul rămâne închis chiar dacă config.php dispare (15, ultimul pas). */
    public const MARCAJ = 'INSTALARE-INCHEIATA.txt';
    public const PREFIX_B = 'cazo-privat-';
    /** Numele temporar al copiei de la varianta B (încărcătorul caută doar PREFIX_B). */
    public const PREFIX_COPIERE = '.cazo-copiere-';
    /** După atâtea secunde, o copie temporară rămasă se consideră abandonată. */
    public const COPIERE_UITATA = 900;

    public const HTACCESS_B = "# Cazo: dosarul privat (varianta B); serverul web refuză orice fișier de aici.\n"
        . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
        . "Options -Indexes\n";

    /**
     * Dosarul privat de lucru, pornind de la cel găsit de încărcător.
     * @return array{varianta:string, privat:string, mutat:?string} mutat: „redenumit” sau „copiat” la B, altfel null
     */
    public static function pregateste(string $public, string $gasit, ?string $documentRoot = null): array
    {
        $public = rtrim($public, '/');
        $gasit = rtrim($gasit, '/');
        if (self::radacinaWeb($public, $documentRoot, $gasit) !== null) {
            if (str_starts_with(basename($gasit), self::PREFIX_B)) {
                return ['varianta' => 'B', 'privat' => $gasit, 'mutat' => null];
            }
            // Arhiva dezarhivată chiar în webroot: dosarul primește, pe loc, un nume greu de ghicit, apoi protecția.
            $nou = dirname($gasit) . '/' . self::PREFIX_B . self::aleator(16);
            if (!@rename($gasit, $nou)) {
                throw new \RuntimeException('Dosarul privat nu s-a putut redenumi.');
            }
            self::protejeaza($nou);
            return ['varianta' => 'B', 'privat' => $nou, 'mutat' => 'redenumit'];
        }
        if (self::scriibil($gasit)) {
            return ['varianta' => 'A', 'privat' => $gasit, 'mutat' => null];
        }
        // Deasupra webroot se poate doar citi: lansarea se copiază în public_html, sub un nume aleator.
        if (!self::scriibil($public)) {
            throw new \RuntimeException('Nici dosarul privat, nici public_html nu se pot scrie.');
        }
        return ['varianta' => 'B', 'privat' => self::copiaza($public, $gasit), 'mutat' => 'copiat'];
    }

    /**
     * Varianta B prin copiere: lansarea se copiază sub un nume temporar, pe care încărcătorul nu îl caută
     * (.cazo-copiere-…, protejat de la început), iar la sfârșit dosarul primește numele final printr-un singur
     * rename. Astfel încărcătorul și instalatorul văd doar o copie întreagă. O copie oprită de o eroare se șterge pe
     * loc; una oprită de limita de timp sau de memorie se șterge la următoarea vizită, după COPIERE_UITATA secunde.
     */
    public static function copiaza(string $public, string $gasit): string
    {
        self::curataCopiiUitate($public);
        $tmp = $public . '/' . self::PREFIX_COPIERE . self::aleator(16);
        if (!@mkdir($tmp, 0700)) {
            throw new \RuntimeException('Dosarul de copiere nu s-a putut crea.');
        }
        try {
            self::protejeaza($tmp);
            Instanta::copiaza("$gasit/lansari", "$tmp/lansari");
            // curent.php ultimul: după el, încărcătorul consideră dosarul gata de pornit.
            foreach (['cron.php', 'curent.php'] as $f) {
                if (is_file("$gasit/$f") && !@copy("$gasit/$f", "$tmp/$f")) {
                    throw new \RuntimeException('Fișierul nu s-a putut copia: ' . $f);
                }
            }
            $nou = $public . '/' . self::PREFIX_B . self::aleator(16);
            if (!@rename($tmp, $nou)) {
                throw new \RuntimeException('Dosarul copiat nu s-a putut redenumi.');
            }
        } catch (\Throwable $e) {
            self::stergeDosar($tmp);
            throw $e;
        }
        return $nou;
    }

    /** Copiile temporare rămase de la o vizită oprită de server (limita de timp sau de memorie). */
    public static function curataCopiiUitate(string $public, ?int $acum = null): void
    {
        $acum ??= time();
        foreach (glob($public . '/' . self::PREFIX_COPIERE . '*', GLOB_ONLYDIR) ?: [] as $d) {
            $t = @filemtime($d);
            if ($t !== false && $acum - $t > self::COPIERE_UITATA) {
                self::stergeDosar($d);
            }
        }
    }

    public static function stergeDosar(string $d): void
    {
        if (is_link($d) || !is_dir($d)) {
            @unlink($d);
            return;
        }
        foreach (scandir($d) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                self::stergeDosar("$d/$f");
            }
        }
        @rmdir($d);
    }

    public static function inPublic(string $public, string $privat): bool
    {
        return str_starts_with(rtrim($privat, '/') . '/', rtrim($public, '/') . '/');
    }

    /**
     * DOCUMENT_ROOT-ul serverului web, cu căile reale (realpath), luat în seamă doar când cuprinde dosarul lui
     * index.php. Așa, o arhivă dezarhivată un nivel mai jos (public_html/public_html/index.php, cu dosarul privat în
     * public_html/cazo-privat) se vede tot în webroot, iar o valoare străină a serverului (alt cont, altă gazdă
     * virtuală) se lasă deoparte.
     */
    public static function documentRoot(string $documentRoot, string $public): ?string
    {
        if (trim($documentRoot) === '') {
            return null;
        }
        $r = @realpath($documentRoot);
        if ($r === false || !@is_dir($r)) {
            return null;
        }
        $r = rtrim($r, '/');
        return self::inPublic($r === '' ? '/' : $r, $public) ? ($r === '' ? '/' : $r) : null;
    }

    /**
     * Webroot-ul care cuprinde dosarul privat (null = dosarul stă în afara lui, varianta A). DOCUMENT_ROOT-ul are
     * întâietate, fiindcă de la el pornesc adresele web reale.
     */
    public static function radacinaWeb(string $public, ?string $documentRoot, string $privat): ?string
    {
        if ($documentRoot !== null && self::inPublic($documentRoot, $privat)) {
            return rtrim($documentRoot, '/');
        }
        return self::inPublic($public, $privat) ? rtrim($public, '/') : null;
    }

    /** Protecția variantei B: .htaccess cu „Require all denied” (sonda din pasul verificărilor o verifică prin HTTP). */
    public static function protejeaza(string $privat): void
    {
        file_put_contents($privat . '/.htaccess', self::HTACCESS_B);
        \Cazo\Nucleu\Functii::chmod($privat . '/.htaccess', 0644);
    }

    /** Dosarele de lucru, cu 0700. */
    public static function structura(string $privat): void
    {
        \Cazo\Nucleu\Functii::chmod($privat, 0700);
        foreach (array_merge(Instanta::DOSARE, ['instalare']) as $d) {
            if (!is_dir("$privat/$d") && !@mkdir("$privat/$d", 0700, true) && !is_dir("$privat/$d")) {
                throw new \RuntimeException('Dosarul nu s-a putut crea: ' . $d);
            }
        }
    }

    /** Scrierea verificată printr-un fișier real (is_writable greșește pe unele sisteme de fișiere). */
    public static function scriibil(string $dir): bool
    {
        if (!@is_dir($dir)) {
            return false;
        }
        $f = $dir . '/.cazo-scriere-' . bin2hex(random_bytes(4));
        if (@file_put_contents($f, 'x') !== 1) {
            return false;
        }
        @unlink($f);
        return true;
    }

    /** Calea afișată gazdei: relativă la dosarul contului, cu sufixul aleator al variantei B ascuns. */
    public static function afisat(string $public, string $privat, string $fisier = '', ?string $documentRoot = null): string
    {
        $radacina = self::radacinaWeb(rtrim($public, '/'), $documentRoot, $privat);
        if ($radacina !== null) {
            $rel = basename(dirname(rtrim($privat, '/'))) . '/' . (str_starts_with(basename($privat), self::PREFIX_B) ? self::PREFIX_B . '…' : basename($privat));
        } else {
            $rel = basename($privat);
        }
        return $rel . ($fisier !== '' ? '/' . $fisier : '');
    }

    /** Marcajul instalării încheiate, pus de instalator (ultimul pas) și de bin/cazo configureaza. */
    public static function scrieMarcaj(string $privat, string $moment): void
    {
        @file_put_contents($privat . '/' . self::MARCAJ, $moment . "\n", LOCK_EX);
        \Cazo\Nucleu\Functii::chmod($privat . '/' . self::MARCAJ, 0600);
    }

    public static function aleator(int $n): string
    {
        $alfabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $s = '';
        for ($i = 0; $i < $n; $i++) {
            $s .= $alfabet[random_int(0, strlen($alfabet) - 1)];
        }
        return $s;
    }
}
