<?php

declare(strict_types=1);

namespace Cazo\Dezvoltare;

defined('CAZO') or exit;

/**
 * Lista textelor de aprobat (docs/texte-de-aprobat.md), generată din cataloage.
 * Fiecare text intră „provizoriu”; aprobarea se scrie ca „aprobat AAAA-LL-ZZ” în coloana Stare.
 * O aprobare se păstrează la regenerare doar cât textul rămâne identic; un text schimbat redevine provizoriu.
 */
final class TexteDeAprobat
{
    public const FISIER = 'docs/texte-de-aprobat.md';

    /**
     * Textele încărcătorului (public/index.php), scrise direct în el: rulează fără cataloage.
     * `text` apare când lansarea curentă lipsește (o actualizare în curs), `text_configurare` când
     * dosarul privat sau config.php lipsesc ori sunt deteriorate.
     */
    public const INCARCATOR = [
        'incarcator.titlu' => ['Revenim în câteva minute', 'We’ll be back in a few minutes'],
        'incarcator.text' => ['Facem câteva îmbunătățiri pe site. Vă mulțumim pentru răbdare.', 'We’re making a few improvements to the site. Thanks for your patience.'],
        'incarcator.text_configurare' => ['Facem o verificare tehnică pe site. Vă mulțumim pentru răbdare.', 'We’re running a technical check on the site. Thanks for your patience.'],
    ];

    /** Secțiunile listei: titlu => [catalog RO, catalog EN] (căi relative la depozit) sau sursa specială. */
    public static function sectiuni(string $radacina): array
    {
        $s = [];
        $s['Site (limbi/ro.php, limbi/en.php)'] = self::dinCataloage("$radacina/limbi/ro.php", "$radacina/limbi/en.php");
        $s['Panoul gazdei (limbi/admin-ro.php, limbi/admin-en.php)'] = self::dinCataloage("$radacina/limbi/admin-ro.php", "$radacina/limbi/admin-en.php");
        if (is_file("$radacina/limbi/instalare-ro.php")) {
            $s['Instalatorul (limbi/instalare-ro.php, limbi/instalare-en.php)'] = self::dinCataloage("$radacina/limbi/instalare-ro.php", "$radacina/limbi/instalare-en.php");
        }
        if (is_file("$radacina/limbi/actualizari-ro.php")) {
            $s['Actualizările (limbi/actualizari-ro.php, limbi/actualizari-en.php)'] = self::dinCataloage("$radacina/limbi/actualizari-ro.php", "$radacina/limbi/actualizari-en.php");
        }
        $s['Țările (limbi/tari.php)'] = self::tari($radacina);
        $s['Încărcătorul (public/index.php)'] = array_map(fn (array $p): array => ['ro' => $p[0], 'en' => $p[1] ?? ''], self::INCARCATOR);
        $s['Formatele (app/I18n/Format.php)'] = [
            'format.luni_scurt' => [
                'ro' => 'ian., feb., mar., apr., mai, iun., iul., aug., sept., oct., nov., dec.',
                'en' => 'Jan, Feb, Mar, Apr, May, Jun, Jul, Aug, Sep, Oct, Nov, Dec',
            ],
            'format.luni' => [
                'ro' => 'ianuarie, februarie, martie, aprilie, mai, iunie, iulie, august, septembrie, octombrie, noiembrie, decembrie',
                'en' => 'January, February, March, April, May, June, July, August, September, October, November, December',
            ],
            'format.bani' => ['ro' => '1.450,00 lei', 'en' => 'RON 1,450.00'],
            'format.zi' => ['ro' => '20 dec. 2026', 'en' => '20 Dec 2026'],
            'format.zi_lunga' => ['ro' => '20 decembrie 2026', 'en' => '20 December 2026'],
        ];
        foreach (self::teme($radacina) as $slug => $texte) {
            $s["Tema $slug (teme/$slug/locales/ro.json, en.json)"] = $texte;
        }
        $s[self::PENTRU] = self::pentru($radacina);
        return $s;
    }

    /** Secțiunea textelor „pentru cine e tema”, în română (instalatorul și panoul le arată în ambele limbi). */
    public const PENTRU = 'Pentru cine e tema (teme/<slug>/theme.json, „pentru”, doar în română)';

    /**
     * „Pentru cine e tema” din manifestul fiecărei teme (instalatorul și panoul, „Potrivită pentru …”; propunerea
     * instalatorului), ca tema.<slug>.pentru; fără temele interne (_pornire, _schelet).
     * @return array<string,array{ro:string,en:string}>
     */
    public static function pentru(string $radacina): array
    {
        $rez = [];
        foreach (glob($radacina . '/teme/*/theme.json') ?: [] as $f) {
            $slug = basename(dirname($f));
            $j = json_decode((string) file_get_contents($f), true);
            if (!str_starts_with($slug, '_') && is_string($j['pentru'] ?? null) && $j['pentru'] !== '') {
                $rez['tema.' . $slug . '.pentru'] = ['ro' => $j['pentru'], 'en' => ''];
            }
        }
        ksort($rez, SORT_STRING);
        return $rez;
    }

    /**
     * Textele temelor oficiale (locales/ro.json și en.json ale fiecărei teme, inclusiv biblioteca _schelet).
     * @return array<string, array<string,array{ro:string,en:string}>>
     */
    public static function teme(string $radacina): array
    {
        $rez = [];
        foreach (glob($radacina . '/teme/*/locales/ro.json') ?: [] as $f) {
            $dir = dirname($f, 2);
            $ro = json_decode((string) file_get_contents($f), true) ?: [];
            $en = json_decode((string) @file_get_contents($dir . '/locales/en.json'), true) ?: [];
            $t = [];
            foreach (array_unique(array_merge(array_keys($ro), array_keys($en))) as $k) {
                $t[(string) $k] = ['ro' => self::text($ro[$k] ?? ''), 'en' => self::text($en[$k] ?? '')];
            }
            ksort($t, SORT_STRING);
            $rez[basename($dir)] = $t;
        }
        ksort($rez, SORT_STRING);
        return $rez;
    }

    /** Numele țărilor din câmpul „Țara de reședință” (comanda), ca tara.<cod>. @return array<string,array{ro:string,en:string}> */
    public static function tari(string $radacina): array
    {
        $rez = [];
        foreach (is_file($radacina . '/limbi/tari.php') ? (array) require $radacina . '/limbi/tari.php' : [] as $cod => [$ro, $en]) {
            $rez['tara.' . $cod] = ['ro' => $ro, 'en' => $en];
        }
        ksort($rez, SORT_STRING);
        return $rez;
    }

    /** @return array<string,array{ro:string,en:string}> */
    private static function dinCataloage(string $ro, string $en): array
    {
        $cro = require $ro;
        $cen = require $en;
        $rez = [];
        foreach (array_unique(array_merge(array_keys($cro), array_keys($cen))) as $k) {
            $rez[$k] = ['ro' => self::text($cro[$k] ?? ''), 'en' => self::text($cen[$k] ?? '')];
        }
        ksort($rez, SORT_STRING);
        return $rez;
    }

    public static function text(string|array $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        $p = [];
        foreach ($v as $forma => $t) {
            $p[] = $forma . ': ' . $t;
        }
        return implode('; ', $p);
    }

    /**
     * Rândurile existente: [secțiune][cheie] => ['ro', 'en', 'stare'].
     */
    public static function citeste(string $fisier): array
    {
        $rez = [];
        if (!is_file($fisier)) {
            return $rez;
        }
        $sectiune = '';
        foreach (file($fisier, FILE_IGNORE_NEW_LINES) ?: [] as $linie) {
            if (str_starts_with($linie, '## ')) {
                $sectiune = substr($linie, 3);
                continue;
            }
            if (preg_match('~^\| `([^`]+)` \| (.*) \| (.*) \| ([^|]*) \|$~u', $linie, $m)) {
                $rez[$sectiune][$m[1]] = ['ro' => self::dinCelula($m[2]), 'en' => self::dinCelula($m[3]), 'stare' => trim($m[4])];
            }
        }
        return $rez;
    }

    public static function scrie(string $radacina): int
    {
        $fisier = $radacina . '/' . self::FISIER;
        $vechi = self::citeste($fisier);
        $md = "# Textele de aprobat\n\n"
            . "Fiecare text pentru oaspeți și pentru gazdă intră în produs doar după aprobare, unul câte unul.\n"
            . "Regulile: formulare afirmativă, fără „—”, cu „dumneavoastră”,\n"
            . "fără numele furnizorilor tehnici în textele pentru oaspeți.\n\n"
            . "Coloana Stare: `provizoriu` până la aprobare; aprobarea se scrie `aprobat AAAA-LL-ZZ`. Lista se regenerează cu\n"
            . "`bin/cazo texte`; o aprobare rămâne cât textul e identic, iar un text schimbat redevine provizoriu.\n"
            . "Grupa de teste `texte` verifică că fiecare cheie din cataloage are aici un rând cu textul curent.\n";
        $n = 0;
        foreach (self::sectiuni($radacina) as $titlu => $texte) {
            $md .= "\n## $titlu\n\n| Cheie | RO | EN | Stare |\n|---|---|---|---|\n";
            foreach ($texte as $cheie => $t) {
                $v = $vechi[$titlu][$cheie] ?? null;
                $stare = ($v !== null && $v['ro'] === $t['ro'] && $v['en'] === $t['en'] && $v['stare'] !== '') ? $v['stare'] : 'provizoriu';
                $md .= '| `' . $cheie . '` | ' . self::celula($t['ro']) . ' | ' . self::celula($t['en']) . ' | ' . $stare . " |\n";
                $n++;
            }
        }
        file_put_contents($fisier, $md);
        return $n;
    }

    public static function celula(string $t): string
    {
        return $t === '' ? '' : str_replace(['\\', '|', "\n"], ['\\\\', '\\|', ' '], $t);
    }

    private static function dinCelula(string $c): string
    {
        return str_replace(['\\|', '\\\\'], ['|', '\\'], trim($c));
    }
}
