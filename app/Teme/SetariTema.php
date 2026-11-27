<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/**
 * Setările temei din schemă: câmpurile formularului din panou, construite din settings.schema.json,
 * și verificarea valorilor la salvare, pe tip, cu verificarea contrastului: 4,5:1 pentru text, 3:1 pentru elementele
 * de interfață. Sub prag, salvarea se refuză, cu cea mai apropiată nuanță care trece.
 *
 * Tipurile: color, color_scheme (fundal și text), font (fonturile temei și ale nucleului), image (încărcată prin
 * nucleu, re-encodată, fără SVG), text, textarea, richtext (listă închisă de etichete, reconstruit cu DOMDocument),
 * select, radio, checkbox, range, url (http, https, relativ), continut (cheia unui bloc din panou).
 */
final class SetariTema
{
    public const TEXT_MAX = 200;
    public const TEXTAREA_MAX = 2000;
    public const RICHTEXT_ETICHETE = ['p', 'strong', 'em', 'a', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'br'];

    /** Setările globale ale temei, din schemă (doar intrările bine formate). @return list<array> */
    public static function schema(Tema $t): array
    {
        $f = $t->dir() . '/settings.schema.json';
        $s = is_file($f) ? Tema::json($f) : [];
        return array_values(array_filter((array) ($s['setari'] ?? []), static fn ($x): bool => is_array($x) && is_string($x['id'] ?? null) && is_string($x['tip'] ?? null)));
    }

    /**
     * Câmpurile formularului: id, tip, eticheta (din schemă sau din catalogul panoului), valoarea curentă, opțiunile.
     * @param callable(string): ?string $eticheta eticheta panoului pentru un id cunoscut (culoare_fundal ...), sau null
     * @return list<array<string,mixed>>
     */
    public static function campuri(Tema $t, string $limba, array $valori, callable $eticheta): array
    {
        $fonturi = array_values(array_unique(array_merge(array_column($t->fonturi(), 'familie'), array_keys(Jetoane::FONTURI_SISTEM))));
        $r = [];
        foreach (self::schema($t) as $x) {
            $id = $x['id'];
            $et = is_array($x['eticheta'] ?? null) ? (string) ($x['eticheta'][$limba] ?? $x['eticheta']['ro'] ?? $id) : ($eticheta($id) ?? $id);
            $v = array_key_exists($id, $valori) ? $valori[$id] : ($x['implicit'] ?? null);
            $optiuni = [];
            if ($x['tip'] === 'font') {
                foreach ($fonturi as $f) {
                    $optiuni[] = ['valoare' => $f, 'eticheta' => $eticheta('font.' . $f) ?? $f];
                }
            } elseif (in_array($x['tip'], ['select', 'radio'], true)) {
                foreach ((array) ($x['optiuni'] ?? []) as $o) {
                    $val = is_array($o) ? (string) ($o['valoare'] ?? '') : (string) $o;
                    $optiuni[] = ['valoare' => $val, 'eticheta' => is_array($o) && is_array($o['eticheta'] ?? null) ? (string) ($o['eticheta'][$limba] ?? $val) : ($eticheta('optiune.' . $val) ?? $val)];
                }
            }
            $r[] = [
                'id' => $id, 'tip' => $x['tip'], 'eticheta' => $et,
                'ajutor' => is_array($x['ajutor'] ?? null) ? (string) ($x['ajutor'][$limba] ?? '') : '',
                'valoare' => $x['tip'] === 'color_scheme' ? (is_array($v) ? $v : ['fundal' => '#ffffff', 'text' => '#1a1a1a']) : (is_scalar($v) ? $v : ''),
                'optiuni' => $optiuni, 'min' => $x['min'] ?? null, 'max' => $x['max'] ?? null, 'pas' => $x['pas'] ?? 1, 'unitate' => $x['unitate'] ?? '',
            ];
        }
        return $r;
    }

    /**
     * Valorile trimise, verificate pe tip. Imaginile vin separat (deja re-encodate, ca adresă publică în $imagini).
     * @param array<string,mixed> $post câmpurile formularului (setari[id] => valoare)
     * @param array<string,string> $imagini id => adresa imaginii încărcate acum
     * @param list<string> $continut cheile blocurilor de conținut din panou
     * @return array{valori:array<string,mixed>, erori:array<string,array{cheie:string, parametri:array}>}
     */
    public static function valideaza(Tema $t, array $post, array $imagini = [], array $continut = [], array $vechi = []): array
    {
        $fonturi = array_merge(array_column($t->fonturi(), 'familie'), array_keys(Jetoane::FONTURI_SISTEM));
        $valori = [];
        $erori = [];
        foreach (self::schema($t) as $x) {
            $id = $x['id'];
            $v = $post[$id] ?? null;
            $gresit = static function (string $cheie, array $p = []) use (&$erori, $id): void {
                $erori[$id] = ['cheie' => $cheie, 'parametri' => $p];
            };
            switch ($x['tip']) {
                case 'color':
                    is_string($v) && preg_match('~^#[0-9a-fA-F]{6}$~', $v) ? $valori[$id] = strtolower($v) : $gresit('admin.teme.eroare.culoare');
                    break;
                case 'color_scheme':
                    $f = is_array($v) ? ($v['fundal'] ?? null) : null;
                    $tx = is_array($v) ? ($v['text'] ?? null) : null;
                    if (!is_string($f) || !is_string($tx) || !preg_match('~^#[0-9a-fA-F]{6}$~', $f) || !preg_match('~^#[0-9a-fA-F]{6}$~', $tx)) {
                        $gresit('admin.teme.eroare.culoare');
                    } else {
                        $valori[$id] = ['fundal' => strtolower($f), 'text' => strtolower($tx)];
                    }
                    break;
                case 'font':
                    is_string($v) && in_array($v, $fonturi, true) ? $valori[$id] = $v : $gresit('admin.teme.eroare.optiune');
                    break;
                case 'select':
                case 'radio':
                    is_string($v) && in_array($v, self::valoriOptiuni($x), true) ? $valori[$id] = $v : $gresit('admin.teme.eroare.optiune');
                    break;
                case 'checkbox':
                    $valori[$id] = $v === '1' || $v === 'on' || $v === true;
                    break;
                case 'range':
                    $min = (int) ($x['min'] ?? 0);
                    $max = (int) ($x['max'] ?? 100);
                    $pas = max(1, (int) ($x['pas'] ?? 1));
                    if (is_string($v) && preg_match('~^-?\d{1,6}$~', $v) && (int) $v >= $min && (int) $v <= $max && ((int) $v - $min) % $pas === 0) {
                        $valori[$id] = (int) $v;
                    } else {
                        $gresit('admin.teme.eroare.interval', ['min' => (string) $min, 'max' => (string) $max]);
                    }
                    break;
                case 'text':
                case 'textarea':
                    $lim = (int) ($x['lungime_max'] ?? ($x['tip'] === 'text' ? self::TEXT_MAX : self::TEXTAREA_MAX));
                    $s = is_string($v) ? trim((string) preg_replace($x['tip'] === 'text' ? '~[\x00-\x1F\x7F]~u' : '~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', $v)) : '';
                    mb_strlen($s) <= $lim ? $valori[$id] = $s : $gresit('admin.teme.eroare.lungime', ['n' => (string) $lim]);
                    break;
                case 'richtext':
                    $s = is_string($v) ? $v : '';
                    mb_strlen($s) <= self::TEXTAREA_MAX * 2 ? $valori[$id] = self::richtext($s) : $gresit('admin.teme.eroare.lungime', ['n' => (string) (self::TEXTAREA_MAX * 2)]);
                    break;
                case 'url':
                    $s = is_string($v) ? trim($v) : '';
                    $s === '' || self::urlPermis($s) ? $valori[$id] = $s : $gresit('admin.teme.eroare.adresa');
                    break;
                case 'continut':
                    $s = is_string($v) ? $v : '';
                    $s === '' || in_array($s, $continut, true) ? $valori[$id] = $s : $gresit('admin.teme.eroare.optiune');
                    break;
                case 'image':
                    if (isset($imagini[$id])) {
                        $valori[$id] = $imagini[$id];
                    } elseif ($v === '') {
                        $valori[$id] = '';
                    } elseif (is_string($vechi[$id] ?? null)) {
                        $valori[$id] = $vechi[$id];
                    }
                    break;
            }
        }
        if ($erori === []) {
            foreach (self::contrast(array_replace($t->setariImplicite(), $valori), self::schema($t)) as $id => $e) {
                $erori[$id] = $e;
            }
        }
        return ['valori' => $valori, 'erori' => $erori];
    }

    /**
     * Perechile de culori sub prag: jetoanele nucleului (Jetoane::PERECHI) și fiecare color_scheme.
     * @return array<string, array{cheie:string, parametri:array}> după id-ul culorii de text
     */
    public static function contrast(array $valori, array $schema = []): array
    {
        $r = [];
        $v = Jetoane::curate($valori);
        foreach (Jetoane::contrastInsuficient($valori) as $x) {
            if (isset($r[$x['text']])) {
                continue;
            }
            $e = self::eroareContrast($v[$x['text']], $v[$x['fundal']], $x['prag']);
            // Aceeași culoare de text stă pe mai multe fundaluri (pagina, cardurile): nuanța sugerată le trece pe toate.
            $sug = $e['parametri']['sugestie'];
            foreach (Jetoane::PERECHI as [$a, $b, $prag]) {
                if ($a === $x['text'] && Jetoane::contrast($sug, $v[$b]) + 1e-9 < $prag) {
                    $sug = self::sugestie($sug, $v[$b], $prag);
                }
            }
            $e['parametri']['sugestie'] = $sug;
            $r[$x['text']] = $e;
        }
        foreach ($schema as $s) {
            $p = $valori[$s['id']] ?? null;
            if ($s['tip'] === 'color_scheme' && is_array($p) && is_string($p['text'] ?? null) && is_string($p['fundal'] ?? null)
                && preg_match('~^#[0-9a-f]{6}$~', $p['text']) && preg_match('~^#[0-9a-f]{6}$~', $p['fundal']) && Jetoane::contrast($p['text'], $p['fundal']) + 1e-9 < 4.5) {
                $r[$s['id']] = self::eroareContrast($p['text'], $p['fundal'], 4.5);
            }
        }
        return $r;
    }

    /** @return array{cheie:string, parametri:array} */
    private static function eroareContrast(string $text, string $fundal, float $prag): array
    {
        $deschis = self::luminanta($fundal) > 0.18;
        return ['cheie' => $deschis ? 'admin.teme.contrast.fundal_deschis' : 'admin.teme.contrast.fundal_inchis', 'parametri' => [
            'prag' => $prag === 4.5 ? '4,5:1' : rtrim(rtrim(number_format($prag, 1, ',', ''), '0'), ',') . ':1',
            'raport' => number_format(Jetoane::contrast($text, $fundal), 2, ',', '') . ':1',
            'sugestie' => self::sugestie($text, $fundal, $prag),
        ]];
    }

    /** Cea mai apropiată nuanță a culorii de text care trece pragul pe fundalul dat: amestecul cu negru sau cu alb. */
    public static function sugestie(string $text, string $fundal, float $prag): string
    {
        $tinta = self::luminanta($fundal) > 0.18 ? [0, 0, 0] : [255, 255, 255];
        $c = [hexdec(substr($text, 1, 2)), hexdec(substr($text, 3, 2)), hexdec(substr($text, 5, 2))];
        for ($i = 1; $i <= 100; $i++) {
            $k = $i / 100;
            $hex = '#';
            foreach ([0, 1, 2] as $j) {
                $hex .= str_pad(dechex((int) round($c[$j] + ($tinta[$j] - $c[$j]) * $k)), 2, '0', STR_PAD_LEFT);
            }
            if (Jetoane::contrast($hex, $fundal) + 1e-9 >= $prag) {
                return $hex;
            }
        }
        return $tinta === [0, 0, 0] ? '#000000' : '#ffffff';
    }

    private static function luminanta(string $hex): float
    {
        return (Jetoane::contrast($hex, '#000000') - 1) * 0.05;
    }

    /** Valorile permise ale unui select sau radio (opțiunile pot fi texte sau obiecte {valoare, eticheta}). @return list<string> */
    public static function valoriOptiuni(array $setare): array
    {
        $r = [];
        foreach ((array) ($setare['optiuni'] ?? []) as $o) {
            $v = is_array($o) ? ($o['valoare'] ?? null) : $o;
            if (is_string($v)) {
                $r[] = $v;
            }
        }
        return $r;
    }

    public static function urlPermis(string $u): bool
    {
        if (strlen($u) > 500 || preg_match('~[\x00-\x20"\'<>\\\\]~', $u)) {
            return false;
        }
        return (bool) preg_match('~^(https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[^\s]*)?|/(?!/)[^\s]*|[a-z0-9\-]+(/[^\s]*)?)$~i', $u);
    }

    /** richtext: listă închisă de etichete, linkuri doar http, https, mailto, tel sau relative; restul devine text. */
    public static function richtext(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $d = new \DOMDocument();
        $vechi = libxml_use_internal_errors(true);
        $d->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($vechi);
        $nou = new \DOMDocument('1.0', 'UTF-8');
        $rad = $nou->createElement('div');
        $nou->appendChild($rad);
        $sursa = $d->getElementsByTagName('div')->item(0);
        if ($sursa !== null) {
            self::copiazaRich($sursa, $rad, $nou);
        }
        $r = '';
        foreach ($rad->childNodes as $c) {
            $r .= $nou->saveHTML($c);
        }
        return trim($r);
    }

    private static function copiazaRich(\DOMNode $din, \DOMNode $in, \DOMDocument $nou): void
    {
        foreach ($din->childNodes as $c) {
            if ($c instanceof \DOMText) {
                $in->appendChild($nou->createTextNode($c->data));
            } elseif ($c instanceof \DOMElement) {
                $tag = strtolower($c->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'template'], true)) {
                    continue;
                }
                if (!in_array($tag, self::RICHTEXT_ETICHETE, true)) {
                    self::copiazaRich($c, $in, $nou);
                    continue;
                }
                $e = $nou->createElement($tag);
                if ($tag === 'a') {
                    $href = trim($c->getAttribute('href'));
                    if (preg_match('~^(https?://|mailto:|tel:|/(?!/)|#)~i', $href) && !preg_match('~[\x00-\x20"\'<>]~', $href)) {
                        $e->setAttribute('href', $href);
                    }
                }
                $in->appendChild($e);
                self::copiazaRich($c, $e, $nou);
            }
        }
    }
}
