<?php

declare(strict_types=1);

namespace Cazo\Continut;

defined('CAZO') or exit;

/**
 * Textele paginilor gazdei (M6b), scrise ca text simplu, fără HTML: un rând gol desparte paragrafele,
 * „## ” la început face un subtitlu, „- ” face un element de listă. La întrebările frecvente, fiecare bloc are pe primul
 * rând întrebarea și pe următoarele răspunsul. Totul se escapează, deci textul gazdei nu poate aduce marcaj, scripturi
 * sau stiluri (fără sanitizor de HTML).
 */
final class TextSimplu
{
    public const MAXIM = 20000;

    public static function html(string $text, string $tip = 'libera'): string
    {
        $text = mb_substr(str_replace("\r", '', $text), 0, self::MAXIM);
        $blocuri = array_values(array_filter(array_map('trim', preg_split('~\n\s*\n~', $text) ?: []), 'strlen'));
        $e = static fn (string $x): string => htmlspecialchars($x, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $r = '';
        foreach ($blocuri as $b) {
            $randuri = array_values(array_filter(array_map('trim', explode("\n", $b)), 'strlen'));
            if ($tip === 'faq') {
                $r .= '<h2>' . $e((string) array_shift($randuri)) . '</h2>';
                if ($randuri !== []) {
                    $r .= '<p>' . implode('<br>', array_map($e, $randuri)) . '</p>';
                }
                continue;
            }
            if (str_starts_with($randuri[0], '## ')) {
                $r .= '<h2>' . $e(trim(substr(array_shift($randuri), 3))) . '</h2>';
                if ($randuri === []) {
                    continue;
                }
            }
            if (array_filter($randuri, static fn (string $x): bool => !str_starts_with($x, '- ')) === []) {
                $r .= '<ul>' . implode('', array_map(static fn (string $x): string => '<li>' . $e(trim(substr($x, 2))) . '</li>', $randuri)) . '</ul>';
                continue;
            }
            $r .= '<p>' . implode('<br>', array_map($e, $randuri)) . '</p>';
        }
        return $r;
    }
}
