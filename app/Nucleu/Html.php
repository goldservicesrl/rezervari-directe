<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

use Cazo\I18n\Traducator;

defined('CAZO') or exit;

/**
 * HTML-ul simplu al nucleului, până la temele din pagina „Salut” și paginile de eroare.
 * Fără stil inline și fără script inline (CSP-ul static nu le permite).
 */
final class Html
{
    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param string[] $paragrafe texte simple (se escapează aici)
     * @param array{noindex?:bool, alternative?:array<string,string>, canonic?:?string, link?:?array{0:string,1:string}} $optiuni
     */
    public static function pagina(Traducator $t, string $titlu, array $paragrafe, array $optiuni = []): string
    {
        $limba = $t->limba();
        $cap = '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e($titlu) . '</title>';
        if ($optiuni['noindex'] ?? false) {
            $cap .= '<meta name="robots" content="noindex">';
        }
        if (!empty($optiuni['canonic'])) {
            $cap .= '<link rel="canonical" href="' . self::e($optiuni['canonic']) . '">';
        }
        foreach ($optiuni['alternative'] ?? [] as $l => $url) {
            $cap .= '<link rel="alternate" hreflang="' . self::e($l) . '" href="' . self::e($url) . '">';
        }
        $corp = '<main id="continut"><h1>' . self::e($titlu) . '</h1>';
        foreach ($paragrafe as $p) {
            $corp .= '<p>' . self::e($p) . '</p>';
        }
        if (!empty($optiuni['link'])) {
            $corp .= '<p><a href="' . self::e($optiuni['link'][0]) . '">' . self::e($optiuni['link'][1]) . '</a></p>';
        }
        $corp .= '</main>';
        return "<!doctype html>\n<html lang=\"" . self::e($limba) . '"><head>' . $cap . '</head><body>' . $corp . "</body></html>\n";
    }
}
