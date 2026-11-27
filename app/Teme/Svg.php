<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/**
 * Curățarea SVG-urilor din teme: reconstruite cu DOMDocument dintr-o listă închisă de elemente și
 * atribute; fără scripturi, fără `foreignObject`, fără evenimente, fără trimiteri externe (`href` doar spre `#id`),
 * fără `style` (culorile prin atributele de prezentare), fără DOCTYPE și entități. Rezultatul se servește doar ca
 * imagine (`<img>`), unde browserul oricum nu rulează scripturi.
 */
final class Svg
{
    public const MARIME_MAX = 512 * 1024;

    private const ELEMENTE = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'defs', 'title', 'desc',
        'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'pattern', 'symbol', 'use'];

    private const ATRIBUTE = ['id', 'class', 'viewbox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
        'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'transform', 'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform',
        'patternunits', 'patterntransform', 'clip-path', 'clip-rule', 'mask', 'preserveaspectratio', 'xmlns', 'version', 'font-size', 'font-weight',
        'font-family', 'text-anchor', 'dominant-baseline', 'letter-spacing', 'dx', 'dy', 'role', 'aria-hidden', 'aria-label', 'focusable', 'href', 'xlink:href',
        'xmlns:xlink', 'fx', 'fy', 'spreadmethod', 'maskunits', 'clippathunits', 'vector-effect', 'shape-rendering'];

    /** SVG-ul curățat; aruncă EroareTema când fișierul nu e un SVG citibil. */
    public static function curata(string $svg): string
    {
        if (strlen($svg) > self::MARIME_MAX) {
            throw new EroareTema('svg', 'SVG peste 512 KB.');
        }
        // Auditul de securitate (24.11.2026): doar UTF-8 (fără octeți nuli și fără declarație cu altă codare). Un SVG în UTF-16
        // trecea de căutarea textului de mai jos; în plus, documentul încărcat nu poate avea DOCTYPE.
        if (!mb_check_encoding($svg, 'UTF-8') || str_contains($svg, "\0")
            || preg_match('~^\s*<\?xml[^>]*encoding\s*=\s*["\'](?!utf-?8["\'])~i', $svg)) {
            throw new EroareTema('svg', 'SVG-ul trebuie să fie în UTF-8.');
        }
        if (preg_match('~<!DOCTYPE|<!ENTITY~i', $svg)) {
            throw new EroareTema('svg', 'SVG cu DOCTYPE sau entități.');
        }
        $d = new \DOMDocument();
        $vechi = libxml_use_internal_errors(true);
        $ok = $d->loadXML($svg, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($vechi);
        if ($ok && $d->doctype !== null) {
            throw new EroareTema('svg', 'SVG cu DOCTYPE sau entități.');
        }
        if (!$ok || $d->documentElement === null || strtolower($d->documentElement->localName) !== 'svg') {
            throw new EroareTema('svg', 'Fișierul nu e un SVG valid.');
        }
        $nou = new \DOMDocument('1.0', 'UTF-8');
        $radacina = self::copiaza($d->documentElement, $nou);
        if ($radacina === null) {
            throw new EroareTema('svg', 'SVG gol după curățare.');
        }
        $radacina->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        $nou->appendChild($radacina);
        return (string) $nou->saveXML($nou->documentElement) . "\n";
    }

    private static function copiaza(\DOMElement $e, \DOMDocument $nou): ?\DOMElement
    {
        $nume = strtolower($e->localName);
        if (!in_array($nume, self::ELEMENTE, true)) {
            return null;
        }
        $x = $nou->createElement($e->localName);
        foreach ($e->attributes ?? [] as $a) {
            /** @var \DOMAttr $a */
            $n = strtolower($a->nodeName);
            $v = trim($a->value);
            if (!in_array($n, self::ATRIBUTE, true) || str_starts_with($n, 'on') || $n === 'xmlns' || $n === 'xmlns:xlink') {
                continue;
            }
            if (($n === 'href' || $n === 'xlink:href') && !preg_match('~^#[A-Za-z][A-Za-z0-9_\-]{0,63}$~', $v)) {
                continue;
            }
            if (preg_match('~url\(\s*[\'"]?\s*(?!#)~i', $v) || preg_match('~javascript:|data:|expression|&#~i', $v)) {
                continue;
            }
            $x->setAttribute($n === 'xlink:href' ? 'href' : $a->nodeName, $v);
        }
        foreach ($e->childNodes as $c) {
            if ($c instanceof \DOMElement) {
                $copie = self::copiaza($c, $nou);
                if ($copie !== null) {
                    $x->appendChild($copie);
                }
            } elseif ($c instanceof \DOMText && in_array($nume, ['text', 'tspan', 'title', 'desc'], true)) {
                $x->appendChild($nou->createTextNode($c->data));
            }
        }
        return $x;
    }
}
