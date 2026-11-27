<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/**
 * Despachetarea unei teme încărcate: `ZipArchive`, intrare cu intrare, toate verificările
 * înaintea oricărei scrieri, apoi extragerea în `lucru/<aleator>/`, cu fiecare cale verificată după scriere cu
 * `realpath`. Slugul nu se ia din arhivă: îl generează serverul (TemeTerte).
 *
 * Refuzurile: căile absolute, `..`, `\`, `:`, NUL, caracterele de control, componentele care încep cu punct, orice
 * caracter în afara listei (litere latine, cifre, `-`, `_`, `.`), symlinkurile (atributele externe, `S_IFLNK`),
 * intrările criptate, extensiile în afara listei folderului, extensiile executabile, inclusiv ca extensie dublă.
 * Limitele: arhiva ≤ 15 MB, ≤ 60 MB decomprimat, ≤ 800 de fișiere, ≤ 5 MB pe fișier, adâncime ≤ 6, raport de
 * compresie ≤ 50:1, numărate în timpul citirii (mărimile declarate în arhivă pot minți).
 */
final class Despachetare
{
    public const ARHIVA_MAX = 15 * 1024 * 1024;
    public const TOTAL_MAX = 60 * 1024 * 1024;
    public const FISIERE_MAX = 800;
    public const FISIER_MAX = 5 * 1024 * 1024;
    public const ADANCIME_MAX = 6;
    public const RAPORT_MAX = 50;
    /** Sub atâția octeți citiți, raportul pe o intrare nu se aplică (un fișier mic și repetitiv se comprimă mult). */
    public const RAPORT_PRAG = 1024 * 1024;

    /** Extensiile executabile sau de configurare a serverului, refuzate oriunde în numele fișierului. */
    public const INTERZISE = '~^(php[0-9]*|phtml|phar|pht|phps|shtml|cgi|pl|py|sh|htaccess|htpasswd|ini|asp|aspx|jsp|exe)$~i';

    /** Extensiile permise pe folder (calea relativă la rădăcina temei). */
    public const PERMISE = [
        '' => ['json', 'txt', 'md'],
        'layout' => ['twig'],
        'templates' => ['json'],
        'sections' => ['twig', 'json'],
        'partials' => ['twig'],
        'locales' => ['json'],
        'assets/css' => ['css'],
        'assets/js' => ['js', 'mjs'],
        'assets/img' => ['png', 'jpg', 'jpeg', 'webp', 'avif', 'svg'],
        'assets/fonts' => ['woff2', 'txt'],
        'previews' => ['webp', 'png', 'jpg', 'jpeg'],
    ];

    /** @var list<string> */
    public array $fisiere = [];

    private function __construct(public readonly string $dir)
    {
    }

    /**
     * Verifică arhiva și o extrage în $lucru/<aleator>/. Aruncă EroareTema cu motivul la prima problemă; dosarul
     * parțial se șterge.
     */
    public static function din(string $zip, string $lucru): self
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new EroareTema('arhiva', 'Serverul nu are extensia PHP zip; tema nu se poate încărca.');
        }
        $marime = @filesize($zip);
        if ($marime === false || $marime === 0) {
            throw new EroareTema('arhiva', 'Arhiva lipsește sau e goală.');
        }
        if ($marime > self::ARHIVA_MAX) {
            throw new EroareTema('marime', 'Arhiva depășește 15 MB.');
        }
        $z = new \ZipArchive();
        if ($z->open($zip, \ZipArchive::RDONLY) !== true) {
            throw new EroareTema('arhiva', 'Fișierul nu e o arhivă zip validă.');
        }
        try {
            $intrari = self::verificaIntrari($z);
            if (!is_dir($lucru) && !@mkdir($lucru, 0700, true) && !is_dir($lucru)) {
                throw new EroareTema('server', 'Dosarul de lucru nu se poate crea.');
            }
            $dest = $lucru . '/' . bin2hex(random_bytes(12));
            mkdir($dest, 0700);
            $d = new self($dest);
            try {
                $d->extrage($z, $intrari, $marime);
            } catch (\Throwable $e) {
                self::sterge($dest);
                throw $e;
            }
            return $d;
        } finally {
            $z->close();
        }
    }

    /**
     * Toate verificările care se pot face fără să citești conținutul: numărul, numele, tipul, criptarea, mărimile
     * declarate. Întoarce lista [index => cale relativă la rădăcina temei] (fără dosare).
     * @return array<int,string>
     */
    private static function verificaIntrari(\ZipArchive $z): array
    {
        $n = $z->numFiles;
        if ($n > self::FISIERE_MAX * 2) {
            throw new EroareTema('fisiere', 'Arhiva are prea multe intrări (cel mult ' . self::FISIERE_MAX . ' de fișiere).');
        }
        $nume = [];
        $declarat = 0;
        for ($i = 0; $i < $n; $i++) {
            $s = $z->statIndex($i, \ZipArchive::FL_UNCHANGED);
            if ($s === false) {
                throw new EroareTema('arhiva', 'O intrare a arhivei nu se poate citi.');
            }
            $cale = (string) $s['name'];
            self::verificaNume($cale);
            if (($s['encryption_method'] ?? 0) !== 0) {
                throw new EroareTema('criptat', 'Intrare criptată: ' . $cale);
            }
            $op = 0;
            $attr = 0;
            if ($z->getExternalAttributesIndex($i, $op, $attr, \ZipArchive::FL_UNCHANGED) && $op === \ZipArchive::OPSYS_UNIX) {
                $tip = ($attr >> 16) & 0170000;
                if ($tip === 0120000) {
                    throw new EroareTema('symlink', 'Legătură simbolică: ' . $cale);
                }
                if ($tip !== 0 && $tip !== 0100000 && $tip !== 0040000) {
                    throw new EroareTema('tip', 'Intrare de tip special: ' . $cale);
                }
            }
            if (str_ends_with($cale, '/')) {
                continue;
            }
            if ((int) $s['size'] > self::FISIER_MAX) {
                throw new EroareTema('marime', 'Fișierul depășește 5 MB: ' . $cale);
            }
            $declarat += (int) $s['size'];
            if ($declarat > self::TOTAL_MAX) {
                throw new EroareTema('marime', 'Arhiva depășește 60 MB decomprimată.');
            }
            if (isset($nume[strtolower($cale)])) {
                throw new EroareTema('nume', 'Cale repetată: ' . $cale);
            }
            $nume[strtolower($cale)] = $i;
        }
        if (count($nume) > self::FISIERE_MAX) {
            throw new EroareTema('fisiere', 'Arhiva are peste ' . self::FISIERE_MAX . ' de fișiere.');
        }
        // O arhivă făcută dintr-un dosar are un singur dosar de sus; rădăcina temei e atunci în el.
        $prefix = '';
        if (!isset($nume['theme.json'])) {
            $sus = [];
            foreach (array_keys($nume) as $c) {
                $sus[explode('/', $c)[0]] = true;
            }
            if (count($sus) === 1 && isset($nume[array_key_first($sus) . '/theme.json'])) {
                $prefix = array_key_first($sus) . '/';
            }
        }
        $rez = [];
        foreach ($nume as $i) {
            $cale = (string) $z->getNameIndex($i, \ZipArchive::FL_UNCHANGED);
            if ($prefix !== '') {
                if (!str_starts_with(strtolower($cale), $prefix)) {
                    throw new EroareTema('nume', 'Fișier în afara dosarului temei: ' . $cale);
                }
                $cale = substr($cale, strlen($prefix));
            }
            self::verificaLoc($cale);
            $rez[$i] = $cale;
        }
        if (!in_array('theme.json', $rez, true)) {
            throw new EroareTema('manifest', 'Arhiva nu are theme.json la rădăcina temei.');
        }
        return $rez;
    }

    /** Numele unei intrări: forma, caracterele, componentele, adâncimea. */
    public static function verificaNume(string $cale): void
    {
        $afisat = mb_substr((string) preg_replace('~[^\x20-\x7E]~', '?', $cale), 0, 120);
        if ($cale === '' || strlen($cale) > 255) {
            throw new EroareTema('nume', 'Nume de fișier gol sau prea lung.');
        }
        if (str_contains($cale, "\0") || preg_match('~[\x00-\x1F\x7F]~', $cale)) {
            throw new EroareTema('nume', 'Caractere de control în numele fișierului: ' . $afisat);
        }
        if (str_contains($cale, '\\') || str_contains($cale, ':')) {
            throw new EroareTema('nume', 'Cale cu „\\” sau „:”: ' . $afisat);
        }
        if ($cale[0] === '/') {
            throw new EroareTema('nume', 'Cale absolută: ' . $afisat);
        }
        $parti = explode('/', rtrim($cale, '/'));
        foreach ($parti as $p) {
            if ($p === '' || $p === '.' || $p === '..') {
                throw new EroareTema('nume', 'Cale cu traversare: ' . $afisat);
            }
            if ($p[0] === '.') {
                throw new EroareTema('nume', 'Fișier sau dosar ascuns: ' . $afisat);
            }
            if (!preg_match('~^[A-Za-z0-9._\-]+$~', $p)) {
                throw new EroareTema('nume', 'Caractere nepermise în nume (doar litere latine, cifre, -, _ și .): ' . $afisat);
            }
        }
        // Numele din arhivă: dosarul temei din față (opțional), cel mult ADANCIME_MAX dosare, apoi fișierul.
        if (count($parti) > self::ADANCIME_MAX + 2) {
            throw new EroareTema('adancime', 'Prea multe niveluri de dosare: ' . $afisat);
        }
    }

    /** Locul fișierului în temă: extensia permisă în dosarul lui, nicio extensie executabilă, nici ca extensie dublă. */
    public static function verificaLoc(string $cale): void
    {
        // Adâncimea se numără în dosare, fără fișier (ghidul: „cel mult 6 foldere”).
        $parti = explode('/', $cale);
        if (count($parti) - 1 > self::ADANCIME_MAX) {
            throw new EroareTema('adancime', 'Prea multe niveluri de dosare: ' . $cale);
        }
        $fisier = (string) array_pop($parti);
        $segmente = explode('.', strtolower($fisier));
        array_shift($segmente);
        foreach ($segmente as $s) {
            if (preg_match(self::INTERZISE, $s)) {
                throw new EroareTema('extensie', 'Fișier executabil sau de configurare a serverului: ' . $cale);
            }
        }
        $ext = $segmente === [] ? '' : (string) end($segmente);
        $dosar = implode('/', $parti);
        // Captura temei (theme.json, cheia `captura`): singura imagine permisă în rădăcină.
        if ($dosar === '' && preg_match(Tema::CAPTURA, $fisier)) {
            return;
        }
        $permise = null;
        foreach (self::PERMISE as $d => $l) {
            if ($dosar === $d || ($d !== '' && str_starts_with($dosar . '/', $d . '/') && in_array($d, ['sections', 'partials', 'assets/img'], true))) {
                $permise = $l;
                break;
            }
        }
        if ($permise === null) {
            throw new EroareTema('loc', 'Dosar necunoscut în formatul temei: ' . ($dosar === '' ? '/' : $dosar) . ' (' . $cale . ')');
        }
        if (!in_array($ext, $permise, true)) {
            throw new EroareTema('extensie', 'Extensie nepermisă în ' . ($dosar === '' ? 'rădăcina temei' : $dosar . '/') . ': ' . $cale);
        }
    }

    /** @param array<int,string> $intrari */
    private function extrage(\ZipArchive $z, array $intrari, int $marimeArhiva): void
    {
        $radacina = realpath($this->dir);
        if ($radacina === false) {
            throw new EroareTema('server', 'Dosarul de lucru lipsește.');
        }
        $total = 0;
        foreach ($intrari as $i => $cale) {
            $s = $z->statIndex($i, \ZipArchive::FL_UNCHANGED);
            $comprimat = max(1, (int) ($s['comp_size'] ?? 0));
            $flux = $z->getStream((string) $z->getNameIndex($i, \ZipArchive::FL_UNCHANGED));
            if ($flux === false) {
                throw new EroareTema('arhiva', 'Intrarea nu se poate citi: ' . $cale);
            }
            $tinta = $this->dir . '/' . $cale;
            if (!is_dir(dirname($tinta))) {
                mkdir(dirname($tinta), 0700, true);
            }
            $dosar = realpath(dirname($tinta));
            if ($dosar === false || !str_starts_with($dosar . '/', $radacina . '/')) {
                fclose($flux);
                throw new EroareTema('nume', 'Cale în afara dosarului de lucru: ' . $cale);
            }
            $out = fopen($tinta, 'xb');
            if ($out === false) {
                fclose($flux);
                throw new EroareTema('nume', 'Fișierul nu se poate scrie: ' . $cale);
            }
            $citit = 0;
            try {
                while (!feof($flux)) {
                    $b = fread($flux, 65536);
                    if ($b === false) {
                        throw new EroareTema('arhiva', 'Intrare stricată: ' . $cale);
                    }
                    $citit += strlen($b);
                    $total += strlen($b);
                    if ($citit > self::FISIER_MAX) {
                        throw new EroareTema('marime', 'Fișierul depășește 5 MB: ' . $cale);
                    }
                    if ($total > self::TOTAL_MAX) {
                        throw new EroareTema('marime', 'Arhiva depășește 60 MB decomprimată.');
                    }
                    if ($total > self::RAPORT_MAX * $marimeArhiva || ($citit > self::RAPORT_PRAG && $citit > self::RAPORT_MAX * $comprimat)) {
                        throw new EroareTema('compresie', 'Raportul de compresie depășește 50:1 (' . $cale . ').');
                    }
                    fwrite($out, $b);
                }
            } finally {
                fclose($out);
                fclose($flux);
            }
            if ($citit !== (int) $s['size']) {
                throw new EroareTema('arhiva', 'Mărimea declarată în arhivă diferă de conținut: ' . $cale);
            }
            $real = realpath($tinta);
            if ($real === false || !str_starts_with($real, $radacina . '/') || is_link($tinta)) {
                throw new EroareTema('nume', 'Cale în afara dosarului de lucru: ' . $cale);
            }
            \Cazo\Nucleu\Functii::chmod($tinta, 0600);
            $this->fisiere[] = $cale;
        }
        sort($this->fisiere, SORT_STRING);
    }

    public static function sterge(string $d): void
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
                self::sterge($d . '/' . $f);
            }
        }
        @rmdir($d);
    }
}
