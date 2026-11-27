<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cai;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;

defined('CAZO') or exit;

/**
 * SEO: sitemap.xml cu alternativele de limbă și imaginile, robots.txt generat.
 * Căutarea, comanda, „Rezervarea mea” și API-ul intern rămân în afara indexului.
 */
final class Seo
{
    public static function sitemap(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $url = $app->url();
        $limbi = $app->config()->limbi();
        $intrari = [];
        $pagini = ['acasa', 'camere', 'date_legale', 'termeni', 'confidentialitate'];
        if (\Cazo\Legal\BlocLegal::accesibilitate($app)) {
            $pagini[] = 'accesibilitate';
        }
        foreach ($pagini as $n) {
            $alt = [];
            foreach ($limbi as $l) {
                $alt[$l] = $url->absolut(Cai::cale($n, $l), $l);
            }
            $intrari[] = ['alt' => $alt, 'poze' => []];
        }
        $m = $app->modelVedere('ro');
        foreach ($m->camere() as $cam) {
            $s = $m->sluguri($cam['id']);
            $alt = [];
            foreach ($limbi as $l) {
                $alt[$l] = $url->absolut(Cai::cale('camera', $l, ['slug' => $s[$l]]), $l);
            }
            $poze = [];
            foreach (array_slice($cam['poze'], 0, 10) as $poza) {
                $d = $app->ecPoze()->pentruTema($poza['sha']);
                if (isset($d['surse']['jpg'])) {
                    $poze[] = $app->config()->origine() . end($d['surse']['jpg']);
                }
            }
            $intrari[] = ['alt' => $alt, 'poze' => $poze];
        }
        // Paginile publicate ale gazdei (M6b), cu limbile în care au adresă.
        foreach ($app->baza()->toate('SELECT slug FROM {pagini} WHERE publicata = 1 ORDER BY ordine, id') as $pg) {
            $sl = json_decode((string) $pg['slug'], true) ?: [];
            $alt = [];
            foreach ($limbi as $l) {
                if (is_string($sl[$l] ?? null) && preg_match('~^' . Cai::SLUG . '$~', $sl[$l])) {
                    $alt[$l] = $url->absolut(Cai::cale('pagina', $l, ['slug' => $sl[$l]]), $l);
                }
            }
            if ($alt !== []) {
                $intrari[] = ['alt' => $alt, 'poze' => []];
            }
        }
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        foreach ($intrari as $i) {
            foreach ($i['alt'] as $l => $u) {
                $x .= '<url><loc>' . self::e($u) . '</loc>';
                foreach ($i['alt'] as $l2 => $u2) {
                    $x .= '<xhtml:link rel="alternate" hreflang="' . self::e($l2) . '" href="' . self::e($u2) . '"/>';
                }
                $x .= '<xhtml:link rel="alternate" hreflang="x-default" href="' . self::e($i['alt'][$app->config()->limbaImplicita()] ?? $u) . '"/>';
                foreach ($i['poze'] as $poza) {
                    $x .= '<image:image><image:loc>' . self::e($poza) . '</image:loc></image:image>';
                }
                $x .= "</url>\n";
            }
        }
        $x .= "</urlset>\n";
        $r = (new Raspuns(200, $x))->antet('Content-Type', 'application/xml; charset=utf-8');
        $r->antet('Cache-Control', 'public, max-age=3600');
        return $r;
    }

    public static function robots(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $u = $app->url();
        $linii = ['User-agent: *'];
        // Paginile rezervării au aceeași cale în ambele limbi, cu prefixul limbii: /rezerva și /en/rezerva.
        $cai = ['/disponibil', '/rezerva', '/comanda/', '/r/', '/plata/', '/schimbare/'];
        foreach ($app->config()->limbi() as $l) {
            if ($l === $app->config()->limbaImplicita() || $l === 'ro') {
                continue;
            }
            $cai[] = '/' . $l . (Cai::CAI['disponibil'][$l] ?? '/disponibil');
            foreach (['/rezerva', '/comanda/', '/r/', '/plata/', '/schimbare/'] as $x) {
                $cai[] = '/' . $l . $x;
            }
        }
        foreach (array_merge($cai, ['/_api/', '/admin/', '/instalare/']) as $cale) {
            $linii[] = 'Disallow: ' . $cale;
        }
        $linii[] = '';
        $linii[] = 'Sitemap: ' . $u->absolut('/sitemap.xml');
        $r = Raspuns::text(implode("\n", $linii) . "\n");
        $r->antet('Cache-Control', 'public, max-age=3600');
        return $r;
    }

    /**
     * /.well-known/security.txt (RFC 9116): unde se anunță o problemă de securitate a site-ului, generat din
     * contactul gazdei (e-mailul, altfel telefonul); fără contact publicat, 404. `Expires` e mereu la 180 de zile.
     */
    public static function securitate(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        // Contactul public: e-mailul; telefonul doar la alegerea gazdei sau când e singurul ei contact.
        $contact = (array) ($app->ecOglinda()->proprietatePublica()['contact'] ?? []);
        $email = trim((string) ($contact['email'] ?? ''));
        $telefon = (string) preg_replace('~[^0-9+]~', '', (string) ($contact['telefon'] ?? ''));
        $linii = [];
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $linii[] = 'Contact: mailto:' . $email;
        }
        if ($telefon !== '') {
            $linii[] = 'Contact: tel:' . $telefon;
        }
        if ($linii === []) {
            return \Cazo\Nucleu\Erori::pagina($app->traducator($limba), 404, $app->url()->cale('/', $limba));
        }
        $linii[] = 'Expires: ' . gmdate('Y-m-d\\TH:i:s\\Z', $app->ceas()->acum() + 180 * 86400);
        $linii[] = 'Preferred-Languages: ' . implode(', ', $app->config()->limbi());
        $linii[] = 'Canonical: ' . $app->url()->absolut('/.well-known/security.txt');
        $r = Raspuns::text(implode("\n", $linii) . "\n");
        $r->antet('Cache-Control', 'public, max-age=86400');
        return $r;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
