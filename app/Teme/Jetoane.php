<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/**
 * Jetoanele CSS: variabilele --pg-* generate de nucleu din setările temei, în _s/<hash>.css,
 * fără stil inline. Valorile se verifică strict (culori hex, fonturile declarate de temă sau din biblioteca
 * nucleului, numere în interval), iar perechile de culori trebuie să treacă pragurile WCAG: 4,5:1 pentru text,
 * 3:1 pentru elementele de interfață.
 */
final class Jetoane
{
    /** Fonturile de sistem ale nucleului (fără fișiere). */
    public const FONTURI_SISTEM = [
        'sistem' => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
        'serif' => 'Georgia, "Times New Roman", serif',
        'mono' => 'ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace',
    ];

    public const IMPLICITE = [
        'culoare_fundal' => '#ffffff',
        'culoare_text' => '#1a1a1a',
        'culoare_accent' => '#0b5cad',
        'culoare_accent_contrast' => '#ffffff',
        'culoare_linie' => '#767676',
        'culoare_suprafata' => '#f4f4f2',
        'font_titlu' => 'sistem',
        'font_text' => 'sistem',
        'raza' => 6,
        'spatiu' => 16,
    ];

    /** Perechile verificate: [text, fundal, pragul]. */
    public const PERECHI = [
        ['culoare_text', 'culoare_fundal', 4.5],
        ['culoare_text', 'culoare_suprafata', 4.5],
        ['culoare_accent_contrast', 'culoare_accent', 4.5],
        ['culoare_accent', 'culoare_fundal', 4.5],
        ['culoare_linie', 'culoare_fundal', 3.0],
    ];

    /**
     * @param array<string,mixed> $valori setările temei (implicite + alegerile gazdei)
     * @param list<array{familie:string}> $fonturiTema
     * @param ?float $latime lățimea coloanei de conținut a temei, în rem (Tema::latime), pentru zona nucleului
     */
    public static function css(array $valori, array $fonturiTema = [], ?float $latime = null): string
    {
        $v = self::curate($valori, $fonturiTema);
        $s = (int) $v['spatiu'];
        $linii = [
            '--pg-culoare-fundal:' . $v['culoare_fundal'],
            '--pg-culoare-text:' . $v['culoare_text'],
            '--pg-culoare-accent:' . $v['culoare_accent'],
            '--pg-culoare-accent-contrast:' . $v['culoare_accent_contrast'],
            '--pg-culoare-linie:' . $v['culoare_linie'],
            '--pg-culoare-suprafata:' . $v['culoare_suprafata'],
            '--pg-culoare-text-sters:' . self::textSters($v['culoare_text'], $v['culoare_fundal'], $v['culoare_suprafata']),
            // Linia fină dintre rândurile unui card (decorativă; marginile cardurilor rămân pe --pg-culoare-linie).
            '--pg-culoare-linie-fina:' . self::amestec($v['culoare_linie'], $v['culoare_fundal'], 40),
            '--pg-font-titlu:' . self::font($v['font_titlu'], $fonturiTema),
            '--pg-font-text:' . self::font($v['font_text'], $fonturiTema),
            '--pg-raza:' . (int) $v['raza'] . 'px',
            '--pg-umbra:0 1px 2px rgb(0 0 0 / 12%), 0 4px 12px rgb(0 0 0 / 8%)',
        ];
        foreach ([1 => 0.25, 2 => 0.5, 3 => 0.75, 4 => 1, 5 => 1.5, 6 => 2, 7 => 3, 8 => 4] as $i => $f) {
            $linii[] = '--pg-spatiu-' . $i . ':' . rtrim(rtrim(number_format($s * $f / 16, 4, '.', ''), '0'), '.') . 'rem';
        }
        if ($latime !== null) {
            $linii[] = '--pg-latime:' . rtrim(rtrim(number_format($latime, 3, '.', ''), '0'), '.') . 'rem';
        }
        return ':root{' . implode(';', $linii) . "}\n";
    }

    /**
     * Culoarea textului secundar (subsolul, notele de lângă preț): culoarea textului amestecată cu fundalul,
     * cât de ștearsă se poate cu un contrast de cel puțin 4,5:1 (WCAG AA) și pe fundal, și pe suprafață. Pornește de
     * la 62% text și urcă spre culoarea textului până trece pragul; în cel mai rău caz rămâne chiar culoarea textului.
     */
    public static function textSters(string $text, string $fundal, string $suprafata): string
    {
        for ($p = 62; $p < 100; $p += 2) {
            $c = self::amestec($text, $fundal, $p);
            if (self::contrast($c, $fundal) >= 4.5 && self::contrast($c, $suprafata) >= 4.5) {
                return $c;
            }
        }
        return $text;
    }

    /** Amestecul a două culori hex: $procent% din prima, restul din a doua. */
    private static function amestec(string $a, string $b, int $procent): string
    {
        $c = '#';
        foreach ([1, 3, 5] as $i) {
            $c .= sprintf('%02x', (int) round((hexdec(substr($a, $i, 2)) * $procent + hexdec(substr($b, $i, 2)) * (100 - $procent)) / 100));
        }
        return $c;
    }

    /** Valorile, curățate: necunoscutele cad pe implicit. */
    public static function curate(array $valori, array $fonturiTema = []): array
    {
        $r = self::IMPLICITE;
        foreach (self::IMPLICITE as $k => $impl) {
            $x = $valori[$k] ?? null;
            if (str_starts_with($k, 'culoare_')) {
                if (is_string($x) && preg_match('~^#[0-9a-fA-F]{6}$~', $x)) {
                    $r[$k] = strtolower($x);
                }
            } elseif (str_starts_with($k, 'font_')) {
                if (is_string($x) && (isset(self::FONTURI_SISTEM[$x]) || in_array($x, array_column($fonturiTema, 'familie'), true))) {
                    $r[$k] = $x;
                }
            } elseif ($k === 'raza' && is_int($x) && $x >= 0 && $x <= 32) {
                $r[$k] = $x;
            } elseif ($k === 'spatiu' && is_int($x) && $x >= 12 && $x <= 24) {
                $r[$k] = $x;
            }
        }
        return $r;
    }

    /**
     * Perechile sub prag: cele ale nucleului, apoi culorile fixe ale temei (Tema::contrastTema) pe jetoanele gazdei.
     * La o culoare a temei, `text` e chiar culoarea hex.
     * @param list<array{culori:list<string>, pe:list<string>, prag:float}> $culoriTema
     * @return list<array{text:string, fundal:string, raport:float, prag:float}>
     */
    public static function contrastInsuficient(array $valori, array $culoriTema = []): array
    {
        $v = self::curate($valori);
        $r = [];
        foreach (self::PERECHI as [$a, $b, $prag]) {
            $raport = self::contrast($v[$a], $v[$b]);
            if ($raport + 1e-9 < $prag) {
                $r[] = ['text' => $a, 'fundal' => $b, 'raport' => round($raport, 2), 'prag' => $prag];
            }
        }
        foreach ($culoriTema as $g) {
            foreach ($g['culori'] as $c) {
                foreach ($g['pe'] as $b) {
                    $raport = self::contrast($c, $v[$b]);
                    if ($raport + 1e-9 < $g['prag']) {
                        $r[] = ['text' => $c, 'fundal' => $b, 'raport' => round($raport, 2), 'prag' => $g['prag']];
                    }
                }
            }
        }
        return $r;
    }

    /**
     * Valorile cu care se randează: dacă alegerile gazdei nu trec contrastul (nucleul și culorile temei), culorile
     * revin la implicitele temei; restul setărilor rămân. Salvarea le refuză oricum; aici e plasa pentru o valoare veche.
     * @param array<string,mixed> $implicite valorile implicite ale temei
     */
    public static function sigure(array $valori, array $implicite, array $culoriTema = []): array
    {
        if (self::contrastInsuficient($valori, $culoriTema) === []) {
            return $valori;
        }
        foreach (array_keys(self::IMPLICITE) as $k) {
            if (str_starts_with($k, 'culoare_')) {
                if (array_key_exists($k, $implicite)) {
                    $valori[$k] = $implicite[$k];
                } else {
                    unset($valori[$k]);
                }
            }
        }
        return $valori;
    }

    /** Raportul de contrast WCAG 2.2 între două culori hex. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminanta($a);
        $lb = self::luminanta($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminanta(string $hex): float
    {
        $c = [];
        foreach ([1, 3, 5] as $i) {
            $x = hexdec(substr($hex, $i, 2)) / 255;
            $c[] = $x <= 0.04045 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private static function font(string $nume, array $fonturiTema): string
    {
        if (isset(self::FONTURI_SISTEM[$nume])) {
            return self::FONTURI_SISTEM[$nume];
        }
        return '"' . str_replace(['"', '\\', ';', '}', '{'], '', $nume) . '", ' . self::FONTURI_SISTEM['sistem'];
    }
}
