<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/**
 * Publicarea activelor: doar fișiere scrise de Cazo, cu nume generate și extensii
 * dintr-o listă închisă, în webroot.
 *
 *   _c/<versiune>/   activele nucleului (front/ în depozit, active/ în lansare), cu hash în nume; Leaflet în
 *                    leaflet-<versiune>/, cu numele lui (CSS-ul lui cere images/...)
 *   _t/<sha>/        activele temei, cu aceleași căi relative; CSS-ul temei înfășurat de nucleu în @layer tema
 *   _s/<hash>.css    variabilele CSS generate din setările temei
 *
 * Harta numelor logice spre adrese se ține în cazo-privat/cache/ (o singură publicare pe versiune; în
 * dezvoltare, lansarea „dev”, se reface la orice fișier-sursă mai nou).
 */
final class Active
{
    public const EXTENSII = ['css', 'js', 'woff2', 'png', 'jpg', 'jpeg', 'webp', 'avif', 'svg', 'json'];

    public function __construct(private string $radacina, private string $public, private string $privat, private string $versiune)
    {
    }

    /** @return array<string,string> numele logic (bara.js, cazo.css, leaflet/leaflet.js ...) => adresa publică */
    public function nucleu(): array
    {
        $sursa = is_dir($this->radacina . '/front') ? $this->radacina . '/front' : $this->radacina . '/active';
        $harta = $this->privat . '/cache/active-' . $this->versiune . '.php';
        $fisiere = self::lista($sursa);
        if (is_file($harta) && !$this->deRefacut($harta, $sursa, $fisiere)) {
            $h = @include $harta;
            if (is_array($h)) {
                return $h;
            }
        }
        $dest = $this->dosarActive('_c') . '/' . $this->versiune;
        $rez = [];
        foreach ($fisiere as $rel) {
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (!in_array($ext, self::EXTENSII, true) || str_ends_with($rel, 'VENDOR.lock')) {
                continue;
            }
            $continut = (string) file_get_contents($sursa . '/' . $rel);
            if (preg_match('~^vendor/leaflet/(.+)$~', $rel, $m)) {
                $public = 'leaflet-1.9.4/' . $m[1];
                $logic = 'leaflet/' . $m[1];
            } elseif (preg_match('~^js/admin/([a-z0-9-]+)\.js$~', $rel, $m)) {
                // Scripturile panoului, sub _c/admin/<versiune>/: singura cale din script-src-ul panoului.
                $public = $m[1] . '.' . substr(hash('sha256', $continut), 0, 10) . '.js';
                self::scrie($this->dosarActive('_c') . '/admin/' . $this->versiune . '/' . $public, $continut);
                $rez['admin/' . $m[1] . '.js'] = '/_c/admin/' . $this->versiune . '/' . $public;
                continue;
            } else {
                $logic = preg_replace('~^(css|js|img)/~', '', $rel);
                $public = preg_replace('~\.([a-z0-9]+)$~', '.' . substr(hash('sha256', $continut), 0, 10) . '.$1', $rel);
            }
            self::scrie($dest . '/' . $public, $continut);
            $rez[$logic] = '/_c/' . $this->versiune . '/' . $public;
        }
        ksort($rez);
        self::scrie($harta, "<?php\n\ndefined('CAZO') or exit;\n\nreturn " . var_export($rez, true) . ";\n", 0600);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($harta, true);
        }
        return $rez;
    }

    /**
     * Publică tema (dacă lipsește din _t/<sha>/) și întoarce adresele ei.
     * @return array{baza:string, css:list<string>, js:list<string>, fonturi:list<string>}
     */
    public function tema(Tema $t): array
    {
        $baza = '/_t/' . $t->sha() . '/';
        $dest = $this->dosarActive('_t') . '/' . $t->sha();
        $gata = $dest . '/.gata';
        if (!is_file($gata)) {
            foreach (self::lista($t->dir() . '/assets') as $rel) {
                $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                if (!in_array($ext, self::EXTENSII, true) || ($ext === 'js' && !$t->oficiala())) {
                    continue;
                }
                $continut = (string) file_get_contents($t->dir() . '/assets/' . $rel);
                if ($ext === 'css') {
                    $continut = ValidatorCss::inStrat($continut);
                }
                self::scrie($dest . '/assets/' . $rel, $continut);
            }
            self::scrie($gata, $t->sha());
        }
        $css = [];
        foreach ($t->css() as $c) {
            $css[] = $baza . $c;
        }
        $js = [];
        foreach ($t->js() as $j) {
            $js[] = $baza . $j;
        }
        $fonturi = [];
        foreach ($t->fonturi() as $f) {
            $fonturi[] = $baza . $f['fisier'];
        }
        return ['baza' => $baza, 'css' => $css, 'js' => $js, 'fonturi' => $fonturi];
    }

    /**
     * Publică doar captura temei (theme.json, cheia `captura`) în _t/<sha>/, pe același drum ca activele ei, și
     * întoarce adresa, sau null când tema vine fără captură. Lista temelor arată capturile tuturor temelor, deci
     * publicarea celorlalte active rămâne pentru tema activă sau previzualizată (tema()).
     */
    public function captura(Tema $t): ?string
    {
        $c = $t->captura();
        if ($c === null || !in_array(strtolower(pathinfo($c, PATHINFO_EXTENSION)), self::EXTENSII, true)) {
            return null;
        }
        $dest = $this->dosarActive('_t') . '/' . $t->sha() . '/' . $c;
        if (!is_file($dest)) {
            self::scrie($dest, (string) file_get_contents($t->dir() . '/' . $c));
        }
        return '/_t/' . $t->sha() . '/' . $c;
    }

    /** Scrie _s/<hash>.css cu variabilele CSS (o dată pe conținut) și întoarce adresa. */
    public function jetoane(string $css): string
    {
        $nume = substr(hash('sha256', $css), 0, 16) . '.css';
        $f = $this->dosarActive('_s') . '/' . $nume;
        if (!is_file($f)) {
            self::scrie($f, $css);
        }
        return '/_s/' . $nume;
    }

    /** @return list<string> căile relative ale fișierelor, sortate (fără fișiere ascunse) */
    public static function lista(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $rez = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $rel = substr($f->getPathname(), strlen($dir) + 1);
            if ($f->isFile() && !$f->isLink() && !preg_match('~(^|/)\.~', $rel)) {
                $rez[] = $rel;
            }
        }
        sort($rez, SORT_STRING);
        return $rez;
    }

    private function deRefacut(string $harta, string $sursa, array $fisiere): bool
    {
        if ($this->versiune !== 'dev') {
            return false;
        }
        $t = (int) filemtime($harta);
        foreach ($fisiere as $rel) {
            if ((int) @filemtime($sursa . '/' . $rel) > $t) {
                return true;
            }
        }
        $h = @include $harta;
        return !is_array($h) || count($h) === 0 || !is_file($this->public . (string) reset($h));
    }

    private function dosarActive(string $nume): string
    {
        $d = $this->public . '/' . $nume;
        if (!is_dir($d)) {
            mkdir($d, 0755, true);
        }
        if (!is_file($d . '/.htaccess')) {
            $s = $this->radacina . '/public/.htaccess-active';
            if (is_file($s)) {
                @copy($s, $d . '/.htaccess');
            }
        }
        return $d;
    }

    private static function scrie(string $cale, string $continut, int $mod = 0644): void
    {
        if (!is_dir(dirname($cale))) {
            mkdir(dirname($cale), 0755, true);
        }
        $tmp = $cale . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $continut, LOCK_EX);
        \Cazo\Nucleu\Functii::chmod($tmp, $mod);
        rename($tmp, $cale);
    }
}
