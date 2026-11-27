<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

defined('CAZO') or exit;

/**
 * Căile publice pe limbi: RO la rădăcină, EN sub /en/, cu sluguri traduse.
 * Același tabel construiește rutele (Rute), adresele din teme (url()) și alternativele hreflang.
 */
final class Cai
{
    public const CAI = [
        'acasa' => ['ro' => '/', 'en' => '/'],
        'camere' => ['ro' => '/camere', 'en' => '/rooms'],
        'camera' => ['ro' => '/camere/{slug}', 'en' => '/rooms/{slug}'],
        'pagina' => ['ro' => '/pagina/{slug}', 'en' => '/page/{slug}'],
        'disponibil' => ['ro' => '/disponibil', 'en' => '/available'],
        'date_legale' => ['ro' => '/date-legale', 'en' => '/legal-information'],
        'termeni' => ['ro' => '/termeni', 'en' => '/terms'],
        'confidentialitate' => ['ro' => '/confidentialitate', 'en' => '/privacy'],
        'accesibilitate' => ['ro' => '/accesibilitate', 'en' => '/accessibility'],
    ];

    /** Paginile legale ale nucleului, în ordinea linkurilor din blocul legal. */
    public const LEGALE = ['date_legale', 'termeni', 'confidentialitate', 'cookie', 'accesibilitate'];

    /**
     * Linkurile legale care duc într-o secțiune a altei pagini: cookie-urile sunt o secțiune a politicii de
     * confidențialitate, cu ancora pe limbi.
     */
    public const ANCORE = [
        'cookie' => ['pagina' => 'confidentialitate', 'ro' => 'cookie-uri', 'en' => 'cookies'],
    ];

    /** Căile vechi, păstrate ca redirecționări permanente (301) spre noua lor adresă. */
    public const VECHI = [
        'cookie' => ['ro' => '/cookie-uri', 'en' => '/cookies'],
    ];

    public const SLUG = '[a-z0-9]+(?:-[a-z0-9]+){0,15}';

    /** Calea logică (fără prefixul limbii) a unei rute, cu parametrii înlocuiți. */
    public static function cale(string $nume, string $limba, array $parametri = []): string
    {
        $model = self::CAI[$nume][$limba] ?? self::CAI[$nume]['ro'] ?? null;
        if ($model === null) {
            throw new \InvalidArgumentException('Rută necunoscută: ' . $nume);
        }
        return (string) preg_replace_callback('~\{([a-z_]+)\}~', static function (array $m) use ($parametri, $nume): string {
            $v = $parametri[$m[1]] ?? null;
            if (!is_string($v) || !preg_match('~^' . self::SLUG . '$~', $v)) {
                throw new \InvalidArgumentException("Parametrul {$m[1]} lipsește sau e greșit pentru $nume.");
            }
            return $v;
        }, $model);
    }

    /** Adresa unui link legal (Cai::LEGALE), cu ancora secțiunii când linkul duce într-o altă pagină. */
    public static function legala(Url $url, string $nume, string $limba): string
    {
        $a = self::ANCORE[$nume] ?? null;
        if ($a === null) {
            return $url->cale(self::cale($nume, $limba), $limba);
        }
        return $url->cale(self::cale($a['pagina'], $limba), $limba) . '#' . ($a[$limba] ?? $a['ro']);
    }

    /** Un slug din text: litere latine fără diacritice, cifre și cratime. */
    public static function slug(string $text): string
    {
        $t = strtr(mb_strtolower($text, 'UTF-8'), [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'é' => 'e', 'è' => 'e', 'ë' => 'e',
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'ö' => 'o', 'ó' => 'o', 'ü' => 'u', 'ú' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss',
        ]);
        $t = (string) preg_replace('~[^a-z0-9]+~', '-', $t);
        $t = trim($t, '-');
        $parti = array_slice(array_filter(explode('-', $t), 'strlen'), 0, 16);
        $t = implode('-', $parti);
        $t = rtrim(substr($t, 0, 80), '-');
        return $t !== '' ? $t : 'camera';
    }
}
