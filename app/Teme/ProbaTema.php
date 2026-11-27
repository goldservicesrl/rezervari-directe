<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cai;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Config;
use Cazo\Securitate\Chei;

defined('CAZO') or exit;

/**
 * Randarea de probă a validatorului: tema rulează pe un site izolat, într-un dosar
 * temporar, cu baza SQLite proprie și cu fixturile normale și extreme (FixturiTeme), în română și în engleză, pe
 * fiecare pagină: acasă, camerele, camera, pagina gazdei, căutarea, o pagină legală și comanda.
 *
 * Pe fiecare pagină: `lang` pe `<html>`, `header`/`main`/`footer`, un singur `<h1>`, `alt` și dimensiuni pe imagini,
 * imaginea LCP prin `img(..., prioritate=true)` acolo unde pagina are poze, marcajele `data-pg` o singură dată (legal,
 * contact și componentele cerute de pagină), timpul ≤ 150 ms (a doua randare, cu șabloanele compilate) și HTML-ul
 * ≤ 512 KB. Eroarea temei vine direct în raport (Randare::$faraRezerva), fără pagina de rezervă _schelet.
 */
final class ProbaTema
{
    public const TIMP_MS = 150;
    public const HTML_MAX = 512 * 1024;

    /** @param list<string> $variante fixturile folosite (implicit toate; testele pot cere una) */
    public function __construct(private Tema $tema, private string $lucru, private array $variante = FixturiTeme::VARIANTE)
    {
    }

    public function ruleaza(Raport $r): void
    {
        $timpi = [];
        foreach ($this->variante as $v) {
            $dir = rtrim($this->lucru, '/') . '/proba-' . bin2hex(random_bytes(6));
            $app = null;
            try {
                $app = self::site($dir, $v, $this->tema);
                foreach ($this->pagini($app, $v) as [$cale, $limba, $pagina]) {
                    $this->pagina($app, $r, $v, $cale, $limba, $pagina, $timpi);
                }
            } catch (\Throwable $e) {
                $r->eroare('proba', 'Site-ul de probă nu a pornit (' . $v . '): ' . $e->getMessage());
            } finally {
                // Conexiunea la baza de probă se închide înainte de ștergere (pe Windows, un SQLite deschis nu se șterge).
                $app = null;
                gc_collect_cycles();
                Despachetare::sterge($dir);
            }
        }
        if ($timpi !== []) {
            $r->masuratori['proba_ms_max'] = max($timpi);
            $r->masuratori['proba_pagini'] = count($timpi);
        }
    }

    /**
     * Site-ul izolat: configurarea, cheia, migrările, oglinda din fixturi, plata la sosire, o pagină a gazdei.
     * Îl folosește și previzualizarea locală din cazo-tema.
     */
    public static function site(string $dir, string $varianta, Tema $tema): Aplicatie
    {
        foreach (['privat/cache', 'privat/jurnale', 'privat/sesiuni', 'public'] as $d) {
            if (!is_dir($dir . '/' . $d)) {
                mkdir($dir . '/' . $d, 0700, true);
            }
        }
        $cfg = new Config([
            'id_instalare' => 'proba-tema',
            'site_url' => 'https://proba.cazo.test',
            'limba_implicita' => 'ro',
            'limbi' => ['ro', 'en'],
            'rescriere' => true,
            'cale_public' => $dir . '/public',
            'baza' => ['driver' => 'sqlite', 'cale' => $dir . '/privat/proba.sqlite', 'prefix' => 'pg_'],
        ], $dir . '/privat');
        $app = new Aplicatie($cfg, Chei::genereaza($dir . '/privat/cheie.bin'));
        $app->migrator()->migreaza();
        $app->transportEc = new TransportFixturi($varianta);
        $app->secrete()->pune('ec_cheie', 'ec_live_proba000_' . str_repeat('0', 32));
        $app->ecLicenta()->dinEu(FixturiTeme::eu($varianta));
        foreach (['proprietate', 'camere', 'disponibilitate', 'preturi'] as $res) {
            $app->ecOglinda()->citeste($res);
        }
        $app->setari()->pune('plati.metode', ['cash']);
        // configurarea completă cere și alegerea pentru anularea făcută de gazdă.
        $app->setari()->pune(\Cazo\Rezervare\Anulare::SETARE_GAZDA, \Cazo\Rezervare\Anulare::GAZDA_TOT);
        $app->baza()->insereaza('pagini', ['tip' => 'libera', 'slug' => json_encode(['ro' => 'ghid-local', 'en' => 'local-guide']),
            'titlu' => json_encode($varianta === 'extrem' ? ['ro' => str_repeat('Ghidul local al zonei ', 4)] : ['ro' => 'Ghid local', 'en' => 'Local guide']),
            'continut' => json_encode(['ro' => "<p>Brutăria e la colț.</p>\n\n<p>Piața are <strong>legume</strong> proaspete.</p>", 'en' => '<p>The bakery is around the corner.</p>']),
            'seo' => null, 'publicata' => 1, 'ordine' => 0]);
        $app->temaFixa = $tema;
        $app->randare()->faraRezerva = true;
        return $app;
    }

    /** @return list<array{0:string,1:string,2:string}> calea, limba, tipul paginii */
    private function pagini(Aplicatie $app, string $varianta): array
    {
        $sosire = gmdate('Y-m-d', time() + 30 * 86400);
        $plecare = gmdate('Y-m-d', time() + 32 * 86400);
        $camera = FixturiTeme::idCamere($varianta)[0];
        $r = [];
        foreach (['ro', 'en'] as $l) {
            $pre = $l === 'en' ? '/en' : '';
            $slug = $app->modelVedere($l)->camere()[0]['slug'];
            $r[] = [$pre . '/', $l, 'acasa'];
            $r[] = [$pre . Cai::cale('camere', $l), $l, 'camere'];
            $r[] = [$pre . Cai::cale('camera', $l, ['slug' => $slug]), $l, 'camera'];
            $r[] = [$pre . Cai::cale('pagina', $l, ['slug' => $l === 'en' ? 'local-guide' : 'ghid-local']), $l, 'pagina'];
            $r[] = [$pre . Cai::cale('disponibil', $l) . '?' . http_build_query(['sosire' => $sosire, 'plecare' => $plecare, 'adulti' => 2]), $l, 'cautare'];
            $r[] = [$pre . Cai::cale('termeni', $l), $l, 'legal'];
            $r[] = [$pre . '/rezerva?' . http_build_query(['sosire' => $sosire, 'plecare' => $plecare, 'plan' => 'plan_std',
                'camere' => [['camera' => $camera, 'adulti' => 2, 'copii' => 0]]]), $l, 'comanda'];
        }
        return $r;
    }

    /** @param list<float> $timpi */
    private function pagina(Aplicatie $app, Raport $r, string $varianta, string $cale, string $limba, string $tip, array &$timpi): void
    {
        $et = $varianta . ' ' . $cale;
        $raspuns = $app->trateaza(Cerere::creeaza('GET', $cale));
        if ($raspuns->status !== 200) {
            $r->eroare('proba', 'Pagina a răspuns ' . $raspuns->status . ': ' . self::ultimaEroare($app), $et);
            return;
        }
        $t0 = microtime(true);
        $raspuns = $app->trateaza(Cerere::creeaza('GET', $cale));
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $timpi[] = $ms;
        $html = $raspuns->corp;
        if ($ms > self::TIMP_MS) {
            $r->eroare('proba', 'Randarea durează ' . $ms . ' ms; limita e ' . self::TIMP_MS . ' ms.', $et);
        }
        if (strlen($html) > self::HTML_MAX) {
            $r->eroare('proba', 'HTML-ul are ' . round(strlen($html) / 1024) . ' KB; limita e 512 KB.', $et);
        }
        // JavaScript-ul unei teme oficiale (contoarele de oaspeți ale temei Hotel) vine doar ca modul cu
        // src, din activele ei publicate (/_t/<sha>/assets/js/); restul scripturilor rămân ale nucleului (/_c/).
        if ($this->tema->oficiala() && $this->tema->js() !== []) {
            $html = (string) preg_replace('~<script type="module" src="/_t/[0-9a-f]{32}/assets/js/[a-z0-9\-]+\.js"></script>~', '', $html);
        }
        foreach (self::probleme($html, $limba, $tip, $varianta === 'normal') as $p) {
            $r->eroare('proba', $p, $et);
        }
    }

    /**
     * Verificările pe documentul randat. $cuPoze: fixturile au poze, deci pagina de cameră are imaginea LCP.
     * @return list<string>
     */
    public static function probleme(string $html, string $limba, string $tip, bool $cuPoze = true): array
    {
        $p = [];
        if (!preg_match('~<html\b[^>]*\slang="' . $limba . '"~', $html)) {
            $p[] = 'Lipsește lang="' . $limba . '" pe <html>.';
        }
        foreach (['header', 'main', 'footer'] as $tag) {
            if (!preg_match('~<' . $tag . '[\s>]~', $html)) {
                $p[] = 'Lipsește <' . $tag . '>.';
            }
        }
        $h1 = preg_match_all('~<h1[\s>]~', $html);
        if ($h1 !== 1) {
            $p[] = 'Pagina are ' . $h1 . ' titluri <h1>; trebuie exact unul.';
        }
        preg_match_all('~<img\b[^>]*>~', $html, $imgs);
        foreach ($imgs[0] as $img) {
            if (!preg_match('~\salt="[^"]*"~', $img)) {
                $p[] = 'Imagine fără alt: ' . mb_substr($img, 0, 120);
            }
            if (!preg_match('~\swidth="\d+"~', $img) || !preg_match('~\sheight="\d+"~', $img)) {
                $p[] = 'Imagine fără width și height: ' . mb_substr($img, 0, 120);
            }
        }
        if ($cuPoze && $tip === 'camera' && str_contains($html, 'class="pg-poza"') && !str_contains($html, 'fetchpriority="high"')) {
            $p[] = 'Imaginea principală (LCP) se pune cu img(poza, latimi, true).';
        }
        foreach (Randare::MARCAJE_UNICE as $m) {
            if (substr_count($html, 'data-pg="' . $m . '"') > 1) {
                $p[] = 'Marcajul data-pg="' . $m . '" apare de mai multe ori.';
            }
        }
        $cerute = ['legal', 'contact'];
        $cerute = array_merge($cerute, match ($tip) {
            'acasa' => ['bara', 'harta'],
            // pe pagina camerei, datele se aleg într-un singur loc, în rezumatul camerei; bara rămâne la alegerea temei.
            'camera' => ['calendar', 'rezumat'],
            'comanda' => ['comanda'],
            'cautare' => ['cautare'],
            'pagina' => ['continut-pagina'],
            default => [],
        });
        foreach ($cerute as $m) {
            if (substr_count($html, 'data-pg="' . $m . '"') !== 1) {
                $p[] = 'Lipsește marcajul data-pg="' . $m . '"' . self::cine($m) . '.';
            }
        }
        foreach (self::verificaHtml($html) as $x) {
            $p[] = $x;
        }
        return $p;
    }

    /**
     * Plasa de siguranță pe HTML-ul randat, citit ca HTML5 (HtmlTaguri): ce ar construi o temă din expresii și ar
     * scăpa analizei statice. Doar nucleul pune scripturi (din /_c/), formulare (cu clasa pg-) și refresh-ul din
     * <noscript> al paginilor de stare.
     * @return list<string>
     */
    public static function verificaHtml(string $html): array
    {
        return array_values(array_unique(array_column(HtmlTaguri::probleme($html, true), 0)));
    }

    private static function cine(string $m): string
    {
        return match ($m) {
            'legal' => ' (cazo.subsol_legal() sau cazo.foot())',
            'contact' => ' (cazo.contact(), în antet sau în subsol)',
            'bara' => ' (cazo.bara_rezervare())',
            'harta' => ' (cazo.harta())',
            'calendar' => ' (cazo.calendar(camera))',
            'rezumat' => ' (cazo.rezumat(camera))',
            'cautare', 'comanda', 'continut-pagina' => ' (cazo.continut() în {% block continut %})',
            default => '',
        };
    }

    public static function ultimaEroare(Aplicatie $app): string
    {
        $f = glob($app->privat() . '/jurnale/*.log') ?: [];
        $linii = $f !== [] ? file((string) end($f), FILE_IGNORE_NEW_LINES) : [];
        foreach (array_reverse($linii ?: []) as $l) {
            $d = json_decode($l, true);
            if (is_array($d) && isset($d['context']['mesaj'])) {
                return mb_substr((string) $d['context']['mesaj'], 0, 300);
            }
            if (is_array($d) && isset($d['context']['eroare'])) {
                return mb_substr((string) $d['context']['eroare'], 0, 300);
            }
        }
        return 'eroare necunoscută';
    }
}
