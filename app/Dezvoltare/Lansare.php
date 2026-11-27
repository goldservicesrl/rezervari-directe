<?php

declare(strict_types=1);

namespace Cazo\Dezvoltare;

defined('CAZO') or exit;

/** Construcția arhivei livrate; folosită de bin/lansare.php și de teste. */
final class Lansare
{
    /** Dosarele lansării; cele absente se sar. scheme/: schemele JSON ale temelor, citite de validator. */
    public const DOSARE = ['app', 'vederi', 'limbi', 'migrari', 'teme', 'scheme', 'vendor', 'bin', 'public'];

    /** Dosarele publice în care Cazo scrie active (fiecare primește .htaccess-active ca .htaccess). */
    public const DOSARE_ACTIVE = ['_c', '_t', '_s', 'media'];

    /** Fișiere care rămân în afara arhivei (niciunul acum: semnarea și publicarea automată au ieșit). */
    public const EXCLUSE = [];

    /** Extensiile permise în lansare (închisă: orice alt fișier oprește construcția). */
    public const EXTENSII = ['php', 'json', 'twig', 'css', 'js', 'woff2', 'png', 'jpg', 'jpeg', 'webp', 'avif', 'svg', 'txt', 'md', 'lock', 'xml', 'ico'];

    /** Fișierele fără extensie permise. */
    public const FARA_EXTENSIE = ['bin/cazo', 'bin/cazo-tema', 'public/.htaccess', 'public/.htaccess-active'];

    /**
     * @param ?string $versiune altă versiune decât cea din app/versiune.php (probele actualizărilor: 1.0.0 și 1.0.1 din
     *   același cod); app/versiune.php din arhivă o primește și ea
     * @param list<string> $temePrivate temele private adăugate în arhivă (arhiva făcută site-ului căruia îi aparțin)
     * @param array{ro?:string, en?:string} $note notele versiunii, scrise în versiune.json
     * @return array{cale:string, sha256:string, fisiere:int, versiune:string}
     */
    public static function construieste(string $radacina, string $iesire, bool $cuTeste = true, ?string $versiune = null, array $temePrivate = [], array $note = [], bool $securitate = false): array
    {
        $versiune ??= (string) (static fn (): string => require $radacina . '/app/versiune.php')();
        if (!preg_match('~^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$~', $versiune)) {
            throw new \RuntimeException('Versiune invalidă în app/versiune.php: ' . $versiune);
        }
        self::verificaVendor($radacina);
        if ($cuTeste) {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/teste/run.php');
            passthru($cmd, $cod);
            if ($cod !== 0) {
                throw new \RuntimeException('Testele au eșuat; arhiva nu se construiește.');
            }
        }

        $fisiere = self::fisiere($radacina, $temePrivate);
        $prefix ='cazo-privat/lansari/' . $versiune . '/';
        $intrari = [];
        $manifest = [];
        // Activele nucleului: front/ (sursa) ajunge în active/, gata de publicat în _c/ (fără pas de construcție într-o versiune următoare).
        $surse = [];
        foreach ($fisiere as $rel) {
            $surse[$rel] = $rel;
        }
        foreach (self::active($radacina) as $rel) {
            $dest = 'active/' . substr($rel, strlen('front/'));
            $surse[str_ends_with($dest, '/LICENSE') ? $dest . '.txt' : $dest] = $rel;
        }
        foreach ($surse as $rel => $sursa) {
            $continut = (string) file_get_contents($radacina . '/' . $sursa);
            if ($rel === 'app/versiune.php') {
                $continut = (string) preg_replace("~^return '[^']*';~m", "return '" . $versiune . "';", $continut);
            }
            $intrari[$prefix . $rel] = $continut;
            $manifest[$rel] = hash('sha256', $continut);
        }
        ksort($manifest, SORT_STRING);
        // MANIFEST.json: versiunea și SHA-256 pe fiecare fișier, de comparat cu fișierele urcate (lansările nu se semnează).
        $intrari[$prefix . 'MANIFEST.json'] = json_encode(['versiune' => $versiune, 'fisiere' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $intrari['cazo-privat/curent.php'] = "<?php\n\ndefined('CAZO') or exit;\n\nreturn '" . $versiune . "';\n";
        $intrari['cazo-privat/cron.php'] = (string) file_get_contents($radacina . '/bin/cron-privat.php');
        $intrari['public_html/index.php'] = (string) file_get_contents($radacina . '/public/index.php');
        $intrari['public_html/.htaccess'] = (string) file_get_contents($radacina . '/public/.htaccess');
        // .htaccess-active în fiecare dosar de active.
        foreach (self::DOSARE_ACTIVE as $d) {
            $intrari['public_html/' . $d . '/.htaccess'] = (string) file_get_contents($radacina . '/public/.htaccess-active');
        }
        ksort($intrari, SORT_STRING);

        if (!is_dir($iesire) && !mkdir($iesire, 0755, true) && !is_dir($iesire)) {
            throw new \RuntimeException('Dosarul de ieșire nu s-a putut crea.');
        }
        $cale = $iesire . '/cazo-' . $versiune . '.zip';
        $tmp = $cale . '.tmp';
        @unlink($tmp);
        $z = new \ZipArchive();
        if ($z->open($tmp, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('Arhiva nu s-a putut crea.');
        }
        // Data fișierelor din arhivă e data construirii: cu data fixă 1980-01-01, o arhivă dezarhivată peste
        // alta lăsa fișiere cu aceeași dată, iar OPcache servea codul vechi. SOURCE_DATE_EPOCH o fixează (arhivă reproductibilă).
        $epoca = (int) (getenv('SOURCE_DATE_EPOCH') ?: time());
        // Dosarele explicite, ca File Manager-ul din cPanel să le creeze cu permisiunile potrivite.
        $dosare = [];
        foreach (array_keys($intrari) as $n) {
            $d = dirname($n);
            while ($d !== '.' && $d !== '') {
                $dosare[$d . '/'] = true;
                $d = dirname($d);
            }
        }
        $toate = array_fill_keys(array_keys($dosare), null) + $intrari;
        ksort($toate, SORT_STRING);
        foreach ($toate as $nume => $continut) {
            if ($continut === null) {
                $z->addEmptyDir(rtrim($nume, '/'));
                $z->setExternalAttributesName($nume, \ZipArchive::OPSYS_UNIX, (040755 & 0xFFFF) << 16);
                $z->setMtimeName($nume, $epoca);
                continue;
            }
            $z->addFromString($nume, $continut);
            $mod = str_ends_with($nume, '/bin/cazo') ? 0100755 : 0100644;
            $z->setExternalAttributesName($nume, \ZipArchive::OPSYS_UNIX, $mod << 16);
            $z->setCompressionName($nume, \ZipArchive::CM_DEFLATE, 9);
            $z->setMtimeName($nume, $epoca);
        }
        if (!$z->close()) {
            throw new \RuntimeException('Arhiva nu s-a putut închide.');
        }
        rename($tmp, $cale);
        // versiune.json lângă arhivă: fișierul public de pe https://cazo.ro/versiune.json, citit zilnic de site-uri.
        file_put_contents($iesire . '/versiune.json', self::versiuneJson($versiune, gmdate('Y-m-d', $epoca), $note, $securitate));
        return ['cale' => $cale, 'sha256' => hash_file('sha256', $cale), 'fisiere' => count($intrari), 'versiune' => $versiune];
    }

    /**
     * Conținutul lui versiune.json (forma citită de Actualizari\VersiuneNoua): versiunea, data publicării, notele
     * (texte scurte, în română și în engleză), legătura spre versiunea de pe GitHub (Aplicatie::ADRESA_PROIECT) și
     * dacă aduce o reparație de securitate.
     * @param array{ro?:string, en?:string} $note
     */
    public static function versiuneJson(string $versiune, string $data, array $note = [], bool $securitate = false): string
    {
        return json_encode([
            'versiune' => $versiune,
            'data' => $data,
            'note' => ['ro' => trim((string) ($note['ro'] ?? '')), 'en' => trim((string) ($note['en'] ?? ''))],
            'descarcare' => \Cazo\Nucleu\Aplicatie::ADRESA_PROIECT . '/releases/tag/v' . $versiune,
            'securitate' => $securitate,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /** @return string[] activele nucleului din front/ (fără evidențele VENDOR.lock), sortate */
    public static function active(string $radacina): array
    {
        $rez = [];
        if (!is_dir("$radacina/front")) {
            return $rez;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$radacina/front", \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $rel = substr($f->getPathname(), strlen($radacina) + 1);
            // Licența Leaflet (BSD-2-Clause) și evidența VENDOR.lock călătoresc cu activele.
            if ($f->isFile() && !$f->isLink() && (in_array(strtolower($f->getExtension()), ['css', 'js', 'svg', 'png', 'woff2', 'json', 'lock'], true) || $f->getFilename() === 'LICENSE')) {
                $rez[] = $rel;
            } elseif ($f->isFile()) {
                throw new \RuntimeException('Fișier cu extensie nepermisă în active: ' . $rel);
            }
        }
        sort($rez, SORT_STRING);
        return $rez;
    }

    /**
     * Căile relative ale fișierelor lansării, sortate. Temele private (`"privata": true` în theme.json) rămân afară,
     * în afara celor cerute explicit în $temePrivate (arhiva făcută pentru site-ul căruia îi aparține tema).
     * @param list<string> $temePrivate
     * @return string[]
     */
    public static function fisiere(string $radacina, array $temePrivate = []): array
    {
        $rez = [];
        $private = [];
        foreach (glob($radacina . '/teme/*/theme.json') ?: [] as $f) {
            $slug = basename(dirname($f));
            if (\Cazo\Teme\Tema::estePrivata(dirname($f)) && !in_array($slug, $temePrivate, true)) {
                $private[] = 'teme/' . $slug . '/';
            }
        }
        foreach (self::DOSARE as $d) {
            if (!is_dir("$radacina/$d")) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$radacina/$d", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                if ($f->isLink()) {
                    throw new \RuntimeException('Legătură simbolică în lansare: ' . $f->getPathname());
                }
                if (!$f->isFile()) {
                    continue;
                }
                $rel = substr($f->getPathname(), strlen($radacina) + 1);
                if (in_array($rel, self::EXCLUSE, true)) {
                    continue;
                }
                foreach ($private as $p) {
                    if (str_starts_with($rel, $p)) {
                        continue 2;
                    }
                }
                $ext = strtolower($f->getExtension());
                // Licențele bibliotecilor vendorizate se păstrează cu numele lor original (LICENSE).
                $licenta = $f->getFilename() === 'LICENSE' && str_starts_with($rel, 'vendor/');
                if (!$licenta && !in_array($rel, self::FARA_EXTENSIE, true) && !in_array($ext, self::EXTENSII, true)) {
                    throw new \RuntimeException('Fișier cu extensie nepermisă în lansare: ' . $rel);
                }
                $rez[] = $rel;
            }
        }
        sort($rez, SORT_STRING);
        return $rez;
    }

    /**
     * vendor/VENDOR.lock: pentru fiecare bibliotecă, sursa, versiunea, licența și SHA-256 pe fiecare fișier.
     * Orice fișier din vendor/ absent din VENDOR.lock sau cu alt hash oprește construcția.
     */
    public static function verificaVendor(string $radacina): void
    {
        $lock = json_decode((string) @file_get_contents("$radacina/vendor/VENDOR.lock"), true);
        if (!is_array($lock) || !isset($lock['biblioteci']) || !is_array($lock['biblioteci'])) {
            throw new \RuntimeException('vendor/VENDOR.lock lipsește sau e deteriorat.');
        }
        $asteptate = [];
        foreach ($lock['biblioteci'] as $b) {
            foreach (['nume', 'versiune', 'licenta', 'sursa', 'fisiere'] as $c) {
                if (!isset($b[$c])) {
                    throw new \RuntimeException("VENDOR.lock: câmpul $c lipsește.");
                }
            }
            foreach ($b['fisiere'] as $rel => $sha) {
                $asteptate['vendor/' . $rel] = $sha;
            }
        }
        foreach (self::fisiere($radacina) as $rel) {
            if (!str_starts_with($rel, 'vendor/') || $rel === 'vendor/VENDOR.lock') {
                continue;
            }
            if (!isset($asteptate[$rel])) {
                throw new \RuntimeException('Fișier din vendor absent din VENDOR.lock: ' . $rel);
            }
            if (!hash_equals($asteptate[$rel], hash_file('sha256', "$radacina/$rel"))) {
                throw new \RuntimeException('Fișier din vendor modificat: ' . $rel);
            }
            unset($asteptate[$rel]);
        }
        if ($asteptate !== []) {
            throw new \RuntimeException('Fișiere din VENDOR.lock lipsă: ' . implode(', ', array_keys($asteptate)));
        }
    }
}
