<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Nucleu\Cai;
use Cazo\Nucleu\Url;
use Twig\Environment;

defined('CAZO') or exit;

/**
 * Funcțiile nucleului pentru o randare: `cazo.head()`, `cazo.foot()`, `cazo.subsol_legal()`,
 * `cazo.contact()`, `cazo.bara_rezervare()`, `cazo.calendar(camera)`, `cazo.rezumat(camera)`, `cazo.harta()`,
 * `cazo.vreme()`, `cazo.componenta()`, `cazo.continut()`, plus `img()`, `t()`, `url()`, `asset()` și filtrele.
 *
 * Fiecare funcție produce HTML din șabloanele nucleului (vederi/componente/), în afara sandboxului, cu marcajul
 * `data-pg`. Datele componentelor le pregătește Randare (Componente); aici se țin minte și componentele folosite,
 * ca foot() să încarce doar scripturile lor și blocul legal când tema l-a omis, iar head() să preîncarce imaginea LCP.
 */
final class Functii
{
    /** @var array<string,int> de câte ori a fost folosită fiecare componentă */
    public array $folosite = [];

    /** @var list<array{href:string, srcset:string, sizes:string}> imaginile marcate prioritare (LCP) */
    public array $preincarcari = [];

    public readonly string $marcaj;

    /** Paginile de rezervare: JS-ul temei rămâne afară. */
    public bool $faraJsTema = false;

    /**
     * @param \Closure(string $componenta, array $argumente): array $date datele unei componente (Componente)
     * @param array<string,string> $activeNucleu
     * @param array{baza:string, css:list<string>, js:list<string>, fonturi:list<string>} $activeTema
     */
    public function __construct(
        private Environment $nucleu,
        private Traducator $t,
        private Url $url,
        private Tema $tema,
        private array $activeNucleu,
        private array $activeTema,
        private string $jetoane,
        private array $meta,
        private \Closure $date,
        private string $continut = '',
    ) {
        $this->marcaj = '<!--pg-preincarcare-' . bin2hex(random_bytes(8)) . '-->';
        $this->locZona = '<!--pg-zona-' . bin2hex(random_bytes(12)) . '-->';
    }

    // ---- funcțiile din teme ----

    public function head(): string
    {
        $this->folosit('head');
        $fonturi = [];
        foreach ($this->tema->fonturi() as $i => $f) {
            if (($f['preincarcat'] ?? $i === 0) && count($fonturi) < 2) {
                $fonturi[] = $this->activeTema['baza'] . $f['fisier'];
            }
        }
        $css = array_merge([$this->activeNucleu['cazo.css']], [$this->jetoane], $this->activeTema['css']);
        // Pictograma: PNG-ul din sigla gazdei, altfel litera numelui, ca SVG.
        $png = is_string($this->meta['favicon'] ?? null) ? $this->meta['favicon'] : null;
        return $this->randeaza('head', ['meta' => $this->meta, 'fonturi' => $fonturi, 'css' => $css, 'favicon' => $png ?? self::favicon((string) ($this->meta['site'] ?? '')),
            'favicon_tip' => $png !== null ? 'image/png' : 'image/svg+xml']) . $this->marcaj;
    }

    /**
     * Pictograma implicită a site-ului: litera siglei (prima literă a numelui) pe un pătrat rotunjit, ca
     * SVG scris direct în adresă (data:), deci fără nicio cerere în plus și fără /favicon.ico lipsă.
     */
    public static function favicon(string $nume): string
    {
        $litera = preg_match('~[\p{L}\p{N}]~u', $nume, $m) ? mb_strtoupper($m[0]) : '•';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#1f2937"/>'
            . '<text x="32" y="45" font-family="system-ui,-apple-system,Segoe UI,Roboto,sans-serif" font-size="38" font-weight="700" text-anchor="middle" fill="#ffffff">'
            . htmlspecialchars($litera, ENT_QUOTES | ENT_XML1) . '</text></svg>';
        return 'data:image/svg+xml,' . rawurlencode($svg);
    }

    public function foot(): string
    {
        $this->folosit('foot');
        $html = '';
        $module = [];
        foreach (['bara_rezervare' => 'bara.js', 'calendar' => 'calendar-start.js', 'rezumat' => 'rezumat.js', 'harta' => 'harta.js', 'cautare' => 'bara.js', 'comanda' => 'comanda.js'] as $c => $js) {
            if (isset($this->folosite[$c]) && isset($this->activeNucleu[$js])) {
                $module[$this->activeNucleu[$js]] = true;
            }
        }
        // galeria pozelor, doar pornirea; fereastra și foaia ei vin la primul clic (adresele, pe eticheta scriptului).
        if (isset($this->folosite['galerie'], $this->activeNucleu['galerie.js'], $this->activeNucleu['galerie-dialog.js'], $this->activeNucleu['galerie.css'])) {
            $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5);
            $html .= '<script type="module" src="' . $e($this->activeNucleu['galerie.js']) . '" data-galerie-modul="'
                . $e($this->activeNucleu['galerie-dialog.js']) . '" data-galerie-css="' . $e($this->activeNucleu['galerie.css']) . '"></script>' . "\n";
        }
        foreach ($this->faraJsTema ? [] : $this->activeTema['js'] as $j) {
            $module[$j] = true;
        }
        return $html . $this->randeaza('foot', ['module' => array_keys($module)]);
    }

    /**
     * Izolarea: blocul legal și contactul stau în zona nucleului, în afara containerului temei (Randare::zone);
     * în temă, funcțiile rămân (temele scrise înainte merg mai departe) și nu mai scriu nimic.
     */
    public function subsol_legal(): string
    {
        $this->folosit('subsol_legal');
        return '';
    }

    public function contact(): string
    {
        $this->folosit('contact');
        return '';
    }

    /**
     * Locul conținutului nucleului pe pagina comenzii, înlocuit de Randare::zone cu zona nucleului. Aleator la fiecare
     * randare, ca tema să nu-l poată scrie singură mai devreme.
     */
    public readonly string $locZona;

    /** Conținutul comenzii (totalul, acordul, butonul final), mutat în zona nucleului; altfel null. */
    public ?string $continutZona = null;

    /** Blocurile zonei nucleului: „sus”, banda „Site în probă”; „jos”, contactul și blocul legal. */
    public function zona(string $parte): string
    {
        if ($parte === 'sus') {
            // „Site în probă” (pentru oricine), „În pregătire” și alerta setărilor lipsă, ultimele două doar pentru
            // administratorul autentificat (Randare le pune în meta doar pentru el; pagina lui ocolește cache-ul).
            $pregatire = is_string($this->meta['pregatire'] ?? null) ? $this->meta['pregatire'] : null;
            $lipsa = is_string($this->meta['date_lipsa'] ?? null) ? $this->meta['date_lipsa'] : null;
            if (empty($this->meta['proba']) && $pregatire === null && $lipsa === null) {
                return '';
            }
            return $this->randeaza('banda', ['proba' => !empty($this->meta['proba']), 'pregatire' => $pregatire, 'date_lipsa' => $lipsa]);
        }
        return $this->randeaza('contact', ($this->date)('contact', [])) . $this->randeaza('legal', ($this->date)('legal', []));
    }

    public function bara_rezervare(): string
    {
        $this->folosit('bara_rezervare');
        return $this->randeaza('bara', ($this->date)('bara', []));
    }

    public function calendar(mixed $camera = null): string
    {
        $this->folosit('calendar');
        // calendar.js se importă la primul focus sau clic (18), din calendar-start.js, după adresa din data-modul.
        return $this->randeaza('calendar', ($this->date)('calendar', ['camera' => is_array($camera) ? $camera : null]) + ['modul' => $this->activeNucleu['calendar.js'] ?? '']);
    }

    public function rezumat(mixed $camera = null): string
    {
        $this->folosit('rezumat');
        return $this->randeaza('rezumat', ($this->date)('rezumat', ['camera' => is_array($camera) ? $camera : null]));
    }

    /** $nivel: nivelul titlului „Unde ne găsiți” (2-4), ca harta să intre corect în capitolul temei care o cuprinde. */
    public function harta(mixed $nivel = 2): string
    {
        $this->folosit('harta');
        $nivel = is_int($nivel) && $nivel >= 2 && $nivel <= 4 ? $nivel : 2;
        return $this->randeaza('harta', ($this->date)('harta', []) + ['nivel' => $nivel, 'leaflet_js' => $this->activeNucleu['leaflet/leaflet.js'] ?? '', 'leaflet_css' => $this->activeNucleu['leaflet/leaflet.css'] ?? '']);
    }

    /**
     * Vremea: temperatura, cerul și 3 zile de la MET Norway, citite de job, cu sursa cerută de licență
     * (CC BY 4.0); fără date proaspete (cheia lipsă, vremea oprită de gazdă, peste 3 ore), componenta rămâne goală.
     */
    public function vreme(): string
    {
        $this->folosit('vreme');
        $d = ($this->date)('vreme', []);
        return is_array($d) && $d !== [] ? $this->randeaza('vreme', $d) : '';
    }

    /** Componentele-semnătură ale temelor vin cu temele lor; una necunoscută rămâne goală. */
    public function componenta(mixed $nume = null, mixed $optiuni = null): string
    {
        $this->folosit('componenta');
        return '';
    }

    /** Conținutul nucleului pe paginile lui (căutarea, paginile legale), pus în {% block continut %}. */
    public function continut(): string
    {
        $this->folosit('continut');
        if (str_contains($this->continut, 'data-pg="cautare"')) {
            $this->folosit('cautare');
        }
        if (preg_match('~data-pg="(comanda|stare|rezervarea-mea)"~', $this->continut)) {
            $this->folosit('comanda');
        }
        // Pe pagina comenzii, totalul și acordul stau în zona nucleului: tema primește doar locul lor.
        if (str_contains($this->continut, 'data-pg="comanda"')) {
            $this->continutZona = $this->continut;
            return $this->locZona;
        }
        if (str_contains($this->continut, 'data-pg="harta"')) {
            // Harta din „Cum ajungeți” (starea confirmată): harta.js și plăcile în CSP, ca pe paginile publice.
            $this->folosit('harta');
        }
        return $this->continut;
    }

    /** Banda „Site în probă”: stă în zona nucleului, sus (Functii::zona); în temă nu mai scrie nimic. */
    public function banda(): string
    {
        $this->folosit('banda');
        return '';
    }

    /**
     * <picture> cu AVIF/WebP/JPEG, srcset, sizes, width/height, loading și fetchpriority (18). Până la prima
     * derivare, poza-substitut neutră a nucleului.
     */
    /**
     * $deschide: poza devine o legătură spre varianta ei cea mai mare, ca oaspetele s-o vadă întreagă când
     * o deschide (galeriile decupează pozele la același raport).
     */
    public function img(mixed $poza = null, mixed $latimi = null, mixed $prioritate = false, mixed $sizes = '100vw', mixed $deschide = false): string
    {
        $this->folosit('img');
        $p = is_array($poza) ? $poza : [];
        $latimi = is_array($latimi) && $latimi !== [] ? array_values(array_filter($latimi, static fn ($x): bool => is_int($x) && in_array($x, [480, 960, 1440, 2048], true))) : [480, 960, 1440];
        $sizes = is_string($sizes) && preg_match('~^[a-z0-9 (),.:%\-]{1,200}$~i', $sizes) ? $sizes : '100vw';
        $sha = is_string($p['sha'] ?? null) && preg_match('~^[0-9a-f]{64}$~', $p['sha']) ? $p['sha'] : null;
        $d = $sha !== null ? ($this->date)('poza', ['sha' => $sha]) : null;
        $w = (int) ($p['latime'] ?? 1200);
        $h = (int) ($p['inaltime'] ?? 800);
        $surse = [];
        $fallback = $this->activeNucleu['substitut.svg'] ?? '';
        $srcsetJpg = '';
        $mare = '';
        // Poza încă se prelucrează: pagina cu substitutul rămâne în afara cache-ului de pagini.
        if ($sha !== null && !(is_array($d) && $d !== []) && (($this->date)('poza_in_lucru', ['sha' => $sha])['da'] ?? false) === true) {
            $this->folosit('substitut');
        }
        if (is_array($d) && $d !== []) {
            $w = $d['latime'];
            $h = $d['inaltime'];
            foreach (['avif' => 'image/avif', 'webp' => 'image/webp', 'jpg' => 'image/jpeg'] as $f => $mime) {
                $lista = [];
                foreach ($latimi as $l) {
                    if (isset($d['surse'][$f][$l])) {
                        $lista[] = $d['surse'][$f][$l] . ' ' . $l . 'w';
                    }
                }
                if ($lista === [] && isset($d['surse'][$f])) {
                    $max = max(array_keys($d['surse'][$f]));
                    $lista[] = $d['surse'][$f][$max] . ' ' . $max . 'w';
                }
                if ($lista !== []) {
                    $surse[] = ['tip' => $mime, 'srcset' => implode(', ', $lista), 'format' => $f];
                }
            }
            foreach (['jpg', 'webp', 'avif'] as $f) {
                if ($mare === '' && !empty($d['surse'][$f]) && is_array($d['surse'][$f])) {
                    $mare = (string) $d['surse'][$f][max(array_keys($d['surse'][$f]))];
                }
            }
            foreach ($surse as $s) {
                if ($s['format'] === 'jpg') {
                    $srcsetJpg = $s['srcset'];
                    $fallback = explode(' ', explode(', ', $s['srcset'])[0])[0];
                }
            }
        }
        $prio = $prioritate === true;
        if ($prio && count($this->preincarcari) < 1) {
            $prima = $surse[0] ?? null;
            $this->preincarcari[] = ['href' => $fallback, 'srcset' => $prima['srcset'] ?? '', 'sizes' => $sizes, 'tip' => $prima['tip'] ?? ''];
        }
        if ($deschide === true && $mare !== '') {
            $this->folosit('galerie');   // galeria pozelor (front/js/galerie.js) pe paginile cu o poză deschizibilă
        }
        return $this->randeaza('img', [
            'surse' => array_values(array_filter($surse, static fn (array $s): bool => $s['format'] !== 'jpg')),
            'src' => $fallback,
            'srcset' => $srcsetJpg,
            'sizes' => $sizes,
            'alt' => (string) ($p['alt'] ?? ''),
            'latime' => $w,
            'inaltime' => $h,
            'prioritate' => $prio,
            'deschide' => $deschide === true && $mare !== '' ? $mare : '',
        ]);
    }

    /** Textele temei (locales/<limba>.json), cu {parametri}; lipsa cade pe română, apoi pe cheie. */
    public function t(mixed $cheie = '', mixed $parametri = []): string
    {
        $cheie = (string) $cheie;
        // Variantele după felul proprietății: `<cheie>_apartament`, `<cheie>_unica`, când tema le are.
        foreach ($this->t->variante() as $v) {
            if (isset($this->tema->locale($this->t->limba())[$cheie . $v]) || isset($this->tema->locale('ro')[$cheie . $v])) {
                $cheie .= $v;
                break;
            }
        }
        $text = $this->tema->locale($this->t->limba())[$cheie] ?? $this->tema->locale('ro')[$cheie] ?? $cheie;
        if (!is_string($text)) {
            return $cheie;
        }
        $inloc = [];
        foreach (is_array($parametri) ? $parametri : [] as $k => $v) {
            if (is_scalar($v)) {
                $inloc['{' . $k . '}'] = (string) $v;
            }
        }
        // Un text din localele temei poate repeta un parametru de mii de ori: mărimea se calculează înainte (Garda).
        Garda::inlocuire($text, $inloc);
        return strtr($text, $inloc);
    }

    /** Adresele site-ului după numele rutei (Cai), în limba paginii. */
    public function url(mixed $ruta = 'acasa', mixed $parametri = []): string
    {
        $l = $this->t->limba();
        try {
            return $this->url->cale(Cai::cale((string) $ruta, $l, is_array($parametri) ? $parametri : []), $l);
        } catch (\InvalidArgumentException) {
            return $this->url->cale('/', $l);
        }
    }

    /** Un activ al temei (assets/...), din _t/<sha>/. */
    public function asset(mixed $cale = ''): string
    {
        $cale = (string) $cale;
        if (!preg_match('~^(img|fonts|css|js)/[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*\.[a-z0-9]{2,5}$~', $cale)) {
            return '';
        }
        return $this->activeTema['baza'] . 'assets/' . $cale;
    }

    public function lei(mixed $bani): string
    {
        return is_int($bani) ? Format::baniOaspete($bani, $this->t->limba()) : '';
    }

    public function data(mixed $zi): string
    {
        try {
            return is_string($zi) ? Format::zi($zi, $this->t->limba()) : '';
        } catch (\InvalidArgumentException) {
            return '';
        }
    }

    public function nopti(mixed $n): string
    {
        return is_int($n) ? $this->t->plural('unitate.nopti', $n) : '';
    }

    public static function scurtat(mixed $text, mixed $lungime = 160): string
    {
        return self::scurtatText(strip_tags((string) $text), is_int($lungime) ? $lungime : 160);
    }

    /** Ca scurtat(), pentru un text simplu (de exemplu paginile legale ale gazdei): „<” și „>” rămân text. */
    public static function scurtatText(string $text, int $lungime = 160): string
    {
        $text = trim((string) preg_replace('~\s+~u', ' ', $text));
        $n = max(10, min(2000, $lungime));
        if (mb_strlen($text) <= $n) {
            return $text;
        }
        $t = mb_substr($text, 0, $n);
        $p = mb_strrpos($t, ' ');
        return rtrim($p !== false && $p > $n * 0.6 ? mb_substr($t, 0, $p) : $t, ' ,.;:') . '…';
    }

    // ---- pentru Randare ----

    /** Preîncărcarea imaginii LCP, pusă în locul marcajului din head(). */
    public function linkuriPreincarcare(): string
    {
        $r = '';
        foreach ($this->preincarcari as $p) {
            if ($p['href'] === '') {
                continue;
            }
            $r .= $this->randeaza('preincarcare', $p);
        }
        return $r;
    }

    private function folosit(string $c): void
    {
        $this->folosite[$c] = ($this->folosite[$c] ?? 0) + 1;
    }

    private function randeaza(string $componenta, array $date): string
    {
        return $this->nucleu->render('componente/' . $componenta . '.twig', $date);
    }
}
