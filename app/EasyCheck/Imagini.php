<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

defined('CAZO') or exit;

/**
 * Prelucrarea pozelor: verificarea (tipul permis, cel mult 8000 px pe latură, memoria disponibilă),
 * orientarea după EXIF, re-encodarea fără metadate și derivatele 480/960/1440/2048 în AVIF (unde GD îl are), WebP și JPEG.
 * Nimic din fișierul primit nu ajunge neprelucrat pe site: fiecare derivat e desenat din pixeli de GD.
 */
final class Imagini
{
    public const LATIMI = [480, 960, 1440, 2048];
    public const LATURA_MAXIMA = 8000;
    public const TIPURI = [IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    public const CALITATE = ['avif' => 52, 'webp' => 80, 'jpg' => 82];

    /** Formatele produse pe acest server, în ordinea preferinței pentru <picture>. */
    public static function formate(): array
    {
        $f = [];
        if (function_exists('imageavif') && (gd_info()['AVIF Support'] ?? false)) {
            $f[] = 'avif';
        }
        if (function_exists('imagewebp')) {
            $f[] = 'webp';
        }
        $f[] = 'jpg';
        return $f;
    }

    /**
     * Verifică poza și scrie derivatele în $dir (<latime>.<ext>), atomic.
     * @return array{latime:int, inaltime:int, latimi:int[], formate:string[]}
     * @throws \UnexpectedValueException poza refuzată (tipul, mărimea, conținutul)
     */
    public static function deriva(string $octeti, string $dir, ?array $formate = null): array
    {
        $info = @getimagesizefromstring($octeti);
        if ($info === false || !isset(self::TIPURI[$info[2]])) {
            throw new \UnexpectedValueException('Tip de imagine nepermis.');
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w < 1 || $h < 1 || $w > self::LATURA_MAXIMA || $h > self::LATURA_MAXIMA) {
            throw new \UnexpectedValueException('Imagine în afara dimensiunilor permise.');
        }
        self::verificaMemoria($w, $h);
        $img = @imagecreatefromstring($octeti);
        if (!$img instanceof \GdImage) {
            throw new \UnexpectedValueException('Imaginea nu se poate citi.');
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $img = self::orienteaza($img, self::orientareExif($octeti));
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        $formate ??= self::formate();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Dosarul pozelor nu se poate crea.');
        }
        $latimi = array_values(array_filter(self::LATIMI, fn (int $l): bool => $l <= $w));
        if ($latimi === []) {
            $latimi = [$w];
        }
        foreach ($latimi as $l) {
            $inalt = max(1, (int) round($h * $l / $w));
            $mic = $l === $w ? $img : imagescale($img, $l, $inalt, IMG_BICUBIC);
            if (!$mic instanceof \GdImage) {
                throw new \RuntimeException('Scalarea a eșuat.');
            }
            foreach ($formate as $f) {
                self::scrie($mic, $f, $dir . '/' . $l . '.' . $f);
            }
            if ($mic !== $img) {
                imagedestroy($mic);
            }
        }
        imagedestroy($img);
        return ['latime' => $w, 'inaltime' => $h, 'latimi' => $latimi, 'formate' => $formate];
    }

    private static function scrie(\GdImage $img, string $format, string $cale): void
    {
        $tmp = $cale . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = match ($format) {
            'avif' => imageavif($img, $tmp, self::CALITATE['avif'], 8),
            'webp' => imagewebp($img, $tmp, self::CALITATE['webp']),
            'jpg' => self::jpeg($img, $tmp),
            default => throw new \InvalidArgumentException('Format necunoscut: ' . $format),
        };
        if (!$ok || !is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            throw new \RuntimeException('Derivatul ' . $format . ' nu s-a putut scrie.');
        }
        \Cazo\Nucleu\Functii::chmod($tmp, 0644);
        rename($tmp, $cale);
    }

    /** JPEG progresiv; transparența (PNG, WebP) se așază pe alb. */
    private static function jpeg(\GdImage $img, string $cale): bool
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $fond = imagecreatetruecolor($w, $h);
        imagefill($fond, 0, 0, (int) imagecolorallocate($fond, 255, 255, 255));
        imagecopy($fond, $img, 0, 0, 0, 0, $w, $h);
        imageinterlace($fond, true);
        $ok = imagejpeg($fond, $cale, self::CALITATE['jpg']);
        imagedestroy($fond);
        return $ok;
    }

    /** Memoria cerută de GD (cam 5 octeți pe pixel, plus derivatul cel mare) față de memory_limit. */
    private static function verificaMemoria(int $w, int $h): void
    {
        $limita = self::octeti((string) ini_get('memory_limit'));
        if ($limita <= 0) {
            return;
        }
        $nevoie = (int) ($w * $h * 5 * 1.6) + 2048 * 2048 * 5;
        if (memory_get_usage(true) + $nevoie > $limita) {
            throw new \UnexpectedValueException('Imaginea cere mai multă memorie decât are serverul.');
        }
    }

    private static function octeti(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** Orientarea EXIF (eticheta 0x0112 din IFD0) a unui JPEG, fără extensia exif; 1 când lipsește. */
    public static function orientareExif(string $jpeg): int
    {
        $n = strlen($jpeg);
        if ($n < 4 || substr($jpeg, 0, 2) !== "\xFF\xD8") {
            return 1;
        }
        $p = 2;
        while ($p + 4 <= $n) {
            if ($jpeg[$p] !== "\xFF") {
                return 1;
            }
            $marker = ord($jpeg[$p + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                return 1;
            }
            $len = unpack('n', substr($jpeg, $p + 2, 2))[1];
            if ($len < 2 || $p + 2 + $len > $n) {
                return 1;
            }
            if ($marker === 0xE1 && substr($jpeg, $p + 4, 6) === "Exif\0\0") {
                return self::orientareTiff(substr($jpeg, $p + 10, $len - 8));
            }
            $p += 2 + $len;
        }
        return 1;
    }

    private static function orientareTiff(string $t): int
    {
        if (strlen($t) < 8) {
            return 1;
        }
        $le = substr($t, 0, 2) === 'II';
        if (!$le && substr($t, 0, 2) !== 'MM') {
            return 1;
        }
        $u16 = static fn (int $o): int => unpack($le ? 'v' : 'n', substr($t, $o, 2))[1];
        $u32 = static fn (int $o): int => unpack($le ? 'V' : 'N', substr($t, $o, 4))[1];
        $ifd = $u32(4);
        if ($ifd + 2 > strlen($t)) {
            return 1;
        }
        $intrari = $u16($ifd);
        for ($i = 0; $i < min($intrari, 200); $i++) {
            $o = $ifd + 2 + $i * 12;
            if ($o + 12 > strlen($t)) {
                return 1;
            }
            if ($u16($o) === 0x0112) {
                $v = $u16($o + 8);
                return $v >= 1 && $v <= 8 ? $v : 1;
            }
        }
        return 1;
    }

    /** Rotirea și oglindirea după orientarea EXIF (1-8). */
    public static function orienteaza(\GdImage $img, int $o): \GdImage
    {
        if (in_array($o, [2, 4, 5, 7], true)) {
            imageflip($img, IMG_FLIP_HORIZONTAL);
        }
        $unghi = match ($o) {
            3, 4 => 180,
            6, 7 => 270,
            5, 8 => 90,
            default => 0,
        };
        if ($unghi !== 0) {
            $rotit = imagerotate($img, $unghi, 0);
            if ($rotit instanceof \GdImage) {
                imagedestroy($img);
                return $rotit;
            }
        }
        return $img;
    }
}
