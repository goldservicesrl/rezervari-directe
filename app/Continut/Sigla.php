<?php

declare(strict_types=1);

namespace Cazo\Continut;

use Cazo\EasyCheck\Imagini;
use Cazo\Nucleu\Setari;

defined('CAZO') or exit;

/**
 * Sigla site-ului: o imagine a gazdei în antetul fiecărei teme și pictograma site-ului (favicon). E a site-ului,
 * nu vine din EasyCheck: rămâne aceeași cu cheia EasyCheck, fără cheie (site.mod local) și la trecerea între ele.
 *
 * Încărcarea e sigură, ca la pozele site-ului (Poze, Imagini): doar PNG, JPEG și WebP, după conținut (SVG și orice alt
 * tip se refuză, deci fără risc de XSS), cel mult 2 MB și 6000 px pe latură; din fișier nu se păstrează nimic neprelucrat.
 * GD desenează din pixeli, fără metadate, cu transparența păstrată: sigla, cel mult 192 px înălțime (de două ori cea mai
 * mare înălțime din antet, pentru ecranele dense) și 768 px lățime, plus pictograma de 64 x 64 px, pe fond transparent.
 * Fișierele stau în _s/img/, cu numele derivat din conținut (`sigla-<amprenta>.webp`, `favicon-<amprenta>.png`).
 *
 * Reglajele (`site.sigla_reglaje`): înălțimea pe calculator și pe telefon (24-96 px) și ce se arată în antet: doar
 * sigla, sau sigla cu numele proprietății. Rămân și după ștergerea siglei, pentru următoarea încărcare.
 */
final class Sigla
{
    public const SETARE = 'site.sigla';
    public const REGLAJE = 'site.sigla_reglaje';
    public const MARIME_MAXIMA = 2 * 1024 * 1024;
    public const LATURA_MAXIMA = 6000;
    public const INALTIME_MIN = 24;
    public const INALTIME_MAX = 96;
    /** Valorile implicite: 48 px pe calculator, 36 px pe telefon, sigla cu numele (antetul arată și numele, ca până acum). */
    public const IMPLICITE = ['inaltime' => 48, 'inaltime_telefon' => 36, 'mod' => 'sigla_nume'];
    public const MODURI = ['sigla', 'sigla_nume'];
    /** Imaginea păstrată: cel mult 192 px înălțime și 768 px lățime. */
    public const DESEN_INALTIME = 192;
    public const DESEN_LATIME = 768;
    public const FAVICON = 64;
    /** Telefonul, pentru înălțimea a doua: ecranele mai înguste de 40em (640 px). */
    public const TELEFON_EM = 40;

    public function __construct(private Setari $setari, private string $dirPublic)
    {
    }

    /**
     * Încarcă sigla: o prelucrează, o publică și o face sigla site-ului (cea veche se șterge). Aruncă o excepție cu un cod:
     * `marime` (gol sau peste 2 MB), `tip` (alt tip decât PNG, JPEG, WebP, ori un fișier care nu se poate citi),
     * `dimensiuni` (peste 6000 px pe o latură).
     * @return array{fisier:string, latime:int, inaltime:int, favicon:string}
     */
    public function incarca(string $octeti, ?int $utilizator = null): array
    {
        if ($octeti === '' || strlen($octeti) > self::MARIME_MAXIMA) {
            throw new \UnexpectedValueException('marime');
        }
        $info = @getimagesizefromstring($octeti);
        if ($info === false || !isset(Imagini::TIPURI[$info[2]])) {
            throw new \UnexpectedValueException('tip');
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w < 1 || $h < 1) {
            throw new \UnexpectedValueException('tip');
        }
        if ($w > self::LATURA_MAXIMA || $h > self::LATURA_MAXIMA) {
            throw new \UnexpectedValueException('dimensiuni');
        }
        $img = function_exists('imagecreatefromstring') ? @imagecreatefromstring($octeti) : false;
        if (!$img instanceof \GdImage) {
            throw new \UnexpectedValueException('tip');
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $img = self::orienteaza($img, Imagini::orientareExif($octeti));
            [$w, $h] = [imagesx($img), imagesy($img)];
        }
        $f = min(1.0, self::DESEN_INALTIME / $h, self::DESEN_LATIME / $w);
        $l2 = max(1, (int) round($w * $f));
        $h2 = max(1, (int) round($h * $f));
        $desen = self::panza($l2, $h2);
        imagecopyresampled($desen, $img, 0, 0, 0, 0, $l2, $h2, $w, $h);
        // Pictograma: sigla încăpută în pătrat, centrată, pe fond transparent.
        $fav = self::panza(self::FAVICON, self::FAVICON);
        $ff = min(self::FAVICON / $w, self::FAVICON / $h);
        $fl = max(1, (int) round($w * $ff));
        $fh = max(1, (int) round($h * $ff));
        imagecopyresampled($fav, $img, intdiv(self::FAVICON - $fl, 2), intdiv(self::FAVICON - $fh, 2), 0, 0, $fl, $fh, $w, $h);
        imagedestroy($img);
        $webp = function_exists('imagewebp');
        $bin = self::scrie($desen, $webp ? 'webp' : 'png');
        $binFav = self::scrie($fav, 'png');
        imagedestroy($desen);
        imagedestroy($fav);
        $dir = $this->dirPublic . '/_s/img';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Dosarul imaginilor nu se poate crea.');
        }
        $nume = 'sigla-' . substr(hash('sha256', $bin), 0, 24) . '.' . ($webp ? 'webp' : 'png');
        $numeFav = 'favicon-' . substr(hash('sha256', $binFav), 0, 24) . '.png';
        foreach ([$nume => $bin, $numeFav => $binFav] as $n => $b) {
            if (!is_file($dir . '/' . $n)) {
                $tmp = $dir . '/' . $n . '.' . bin2hex(random_bytes(4)) . '.tmp';
                file_put_contents($tmp, $b);
                \Cazo\Nucleu\Functii::chmod($tmp, 0644);
                rename($tmp, $dir . '/' . $n);
            }
        }
        $vechi = $this->citeste();
        $nou = ['fisier' => '/_s/img/' . $nume, 'latime' => $l2, 'inaltime' => $h2, 'favicon' => '/_s/img/' . $numeFav];
        $this->setari->pune(self::SETARE, $nou, $utilizator);
        if ($vechi !== null) {
            $this->stergeFisiere($vechi, $nou);
        }
        return $nou;
    }

    /** Șterge sigla (antetul revine la numele proprietății, pictograma la litera lui). Reglajele rămân. */
    public function sterge(?int $utilizator = null): bool
    {
        $vechi = $this->citeste();
        $this->setari->pune(self::SETARE, null, $utilizator);
        if ($vechi === null) {
            return false;
        }
        $this->stergeFisiere($vechi, []);
        return true;
    }

    /** Reglajele, verificate: înălțimile în 24-96 px, modul din listă; valorile greșite primesc implicitele. */
    public function regleaza(mixed $inaltime, mixed $telefon, mixed $mod, ?int $utilizator = null): array
    {
        $r = self::reglajeCurate(['inaltime' => $inaltime, 'inaltime_telefon' => $telefon, 'mod' => $mod]);
        $this->setari->pune(self::REGLAJE, $r, $utilizator);
        return $r;
    }

    /** @return array{inaltime:int, inaltime_telefon:int, mod:string} */
    public function reglaje(): array
    {
        $r = $this->setari->get(self::REGLAJE);
        return self::reglajeCurate(is_array($r) ? $r : []);
    }

    /** Valoarea unei înălțimi e validă (un număr întreg între 24 și 96). */
    public static function inaltimeValida(mixed $v): bool
    {
        $s = is_int($v) ? (string) $v : (is_string($v) ? trim($v) : '');
        return preg_match('~^\d{1,3}$~', $s) === 1 && (int) $s >= self::INALTIME_MIN && (int) $s <= self::INALTIME_MAX;
    }

    private static function reglajeCurate(array $r): array
    {
        $i = $r['inaltime'] ?? null;
        $t = $r['inaltime_telefon'] ?? null;
        return [
            'inaltime' => self::inaltimeValida($i) ? (int) $i : self::IMPLICITE['inaltime'],
            'inaltime_telefon' => self::inaltimeValida($t) ? (int) $t : self::IMPLICITE['inaltime_telefon'],
            'mod' => in_array($r['mod'] ?? null, self::MODURI, true) ? (string) $r['mod'] : self::IMPLICITE['mod'],
        ];
    }

    /** Sigla salvată, cu fișierele pe disc; altfel null (fără siglă, ori fișierele lipsesc după o mutare). */
    public function citeste(): ?array
    {
        $s = $this->setari->get(self::SETARE);
        if (!is_array($s) || !is_string($s['fisier'] ?? null) || !preg_match('~^/_s/img/sigla-[0-9a-f]{24}\.(webp|png)$~', $s['fisier'])
            || !is_file($this->dirPublic . $s['fisier'])) {
            return null;
        }
        $fav = is_string($s['favicon'] ?? null) && preg_match('~^/_s/img/favicon-[0-9a-f]{24}\.png$~', $s['favicon']) && is_file($this->dirPublic . $s['favicon']) ? $s['favicon'] : null;
        return ['fisier' => $s['fisier'], 'latime' => max(1, (int) ($s['latime'] ?? 1)), 'inaltime' => max(1, (int) ($s['inaltime'] ?? 1)), 'favicon' => $fav];
    }

    /**
     * Sigla pentru teme (modelul de vizualizare, `site.sigla`): adresa, lățimea și înălțimea la înălțimea de pe calculator
     * (atributele <img>, ca pagina să nu sară la încărcare) și dacă numele proprietății rămâne lângă ea; null fără siglă.
     * @return ?array{url:string, latime:int, inaltime:int, cu_nume:bool}
     */
    public function pentruTema(): ?array
    {
        $s = $this->citeste();
        if ($s === null) {
            return null;
        }
        $r = $this->reglaje();
        return ['url' => $s['fisier'], 'latime' => max(1, (int) round($s['latime'] * $r['inaltime'] / $s['inaltime'])), 'inaltime' => $r['inaltime'],
            'cu_nume' => $r['mod'] === 'sigla_nume'];
    }

    /** Pictograma site-ului din siglă (adresa PNG-ului de 64 px), sau null. */
    public function favicon(): ?string
    {
        return $this->citeste()['favicon'] ?? null;
    }

    /**
     * Înălțimea siglei pe calculator și pe telefon, în foaia jetoanelor (fără stil inline, CSP 13.2). Foaia aceasta stă în
     * afara stratului temei, deci înălțimea aleasă de gazdă trece peste stilul temei. Fără siglă, nimic.
     */
    public function css(): string
    {
        if ($this->citeste() === null) {
            return '';
        }
        $r = $this->reglaje();
        return '.pg-sigla-img{display:block;flex:none;width:auto;height:' . $r['inaltime'] . 'px;max-width:min(100%,20rem);object-fit:contain;object-position:left center}' . "\n"
            . '@media (max-width:' . (self::TELEFON_EM - 0.01) . 'em){.pg-sigla-img{height:' . $r['inaltime_telefon'] . 'px;max-width:min(100%,12rem)}}' . "\n";
    }

    /** Fișierele siglei vechi, în afară de cele folosite de cea nouă (aceeași imagine încărcată din nou). */
    private function stergeFisiere(array $vechi, array $nou): void
    {
        foreach (['fisier', 'favicon'] as $k) {
            $f = $vechi[$k] ?? null;
            if (is_string($f) && $f !== ($nou[$k] ?? null) && preg_match('~^/_s/img/(sigla|favicon)-[0-9a-f]{24}\.(webp|png)$~', $f)) {
                @unlink($this->dirPublic . $f);
            }
        }
    }

    /** O pânză goală, transparentă, cu canalul alfa păstrat la scriere. */
    private static function panza(int $l, int $h): \GdImage
    {
        $i = imagecreatetruecolor($l, $h);
        imagealphablending($i, false);
        imagesavealpha($i, true);
        imagefill($i, 0, 0, (int) imagecolorallocatealpha($i, 0, 0, 0, 127));
        return $i;
    }

    private static function scrie(\GdImage $i, string $format): string
    {
        ob_start();
        $ok = $format === 'webp' ? imagewebp($i, null, 90) : imagepng($i, null, 9);
        $bin = (string) ob_get_clean();
        if (!$ok || $bin === '') {
            throw new \RuntimeException('Sigla nu s-a putut scrie.');
        }
        return $bin;
    }

    /** Orientarea EXIF a unui JPEG (1-8), ca la pozele site-ului. */
    private static function orienteaza(\GdImage $img, int $o): \GdImage
    {
        $r = match ($o) {
            3, 4 => imagerotate($img, 180, 0),
            5, 6 => imagerotate($img, -90, 0),
            7, 8 => imagerotate($img, 90, 0),
            default => $img,
        };
        if ($r instanceof \GdImage && in_array($o, [2, 4, 5, 7], true)) {
            imageflip($r, IMG_FLIP_HORIZONTAL);
        }
        return $r instanceof \GdImage ? $r : $img;
    }
}
