<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/**
 * O temă în formatul public: manifestul theme.json, setările din settings.schema.json, șabloanele
 * JSON, secțiunile, localele și activele. Temele oficiale stau în teme/ al lansării și sunt revizuite și semnate cu
 * ea; cele terțe trec întâi prin despachetare și validator.
 */
final class Tema
{
    /**
     * Tema implicită (Studio, fostă Atelier): pe un site fără `tema.activa`, cu tema aleasă lipsă sau
     * scoasă din program, și propunerea instalatorului pentru tipurile de proprietate fără o temă anume.
     */
    public const IMPLICITA = 'studio';

    /** Identificatorii SPDX acceptați pentru temele oficiale. */
    public const LICENTE_OFICIALE = ['LicenseRef-Cazo'];

    private ?string $sha = null;
    private ?array $locale = null;

    private function __construct(private string $dir, private string $dirSchelet, private array $manifest, private bool $oficiala)
    {
    }

    /**
     * @param ?string $slug slugul generat de server pentru o temă terță, care stă în teme/<slug>/<sha>/;
     *                     înlocuiește slugul din manifest. Fără el, slugul din manifest trebuie să fie numele dosarului.
     */
    public static function din(string $dir, string $dirSchelet, bool $oficiala = true, ?string $slug = null): self
    {
        $m = self::json($dir . '/theme.json');
        if ($slug !== null && !$oficiala && preg_match('~^[a-z0-9][a-z0-9\-]{0,63}$~', $slug)) {
            $m['slug_autor'] = $m['slug'] ?? null;
            $m['slug'] = $slug;
        } elseif (!is_string($m['slug'] ?? null) || !preg_match($oficiala ? '~^_?[a-z0-9][a-z0-9\-]{0,63}$~' : '~^[a-z0-9][a-z0-9\-]{0,63}$~', $m['slug']) || basename($dir) !== $m['slug']) {
            throw new \UnexpectedValueException('theme.json: slug lipsește sau diferă de dosar.');
        }
        foreach (['nume', 'versiune', 'limbi'] as $c) {
            if (!isset($m[$c])) {
                throw new \UnexpectedValueException("theme.json: $c lipsește.");
            }
        }
        self::verificaLicenta($m['licenta'] ?? null, $oficiala);
        if (!$oficiala && isset($m['js'])) {
            throw new \UnexpectedValueException('theme.json: temele terțe sunt fără JavaScript.');
        }
        if (array_key_exists('privata', $m) && !is_bool($m['privata'])) {
            throw new \UnexpectedValueException('theme.json: privata este true sau false.');
        }
        return new self($dir, $dirSchelet, $m, $oficiala);
    }

    /**
     * Tema privată (`"privata": true` în theme.json): o temă făcută pentru un singur site. Lipsește din
     * catalogul temelor, se activează doar pe instalarea care o trece în config.php (`teme_private`) și rămâne în
     * afara lansării publice (Lansare::fisiere).
     */
    public function privata(): bool
    {
        return ($this->manifest['privata'] ?? false) === true;
    }

    /** Citește doar indicatorul `privata` din theme.json al unui dosar (fără validarea completă a temei). */
    public static function estePrivata(string $dir): bool
    {
        $c = @file_get_contents($dir . '/theme.json');
        $m = is_string($c) ? json_decode($c, true, 32) : null;
        return is_array($m) && ($m['privata'] ?? false) === true;
    }

    /**
     * Catalogul temelor oficiale din $dirTeme (teme/ al lansării): slugurile sortate, fără biblioteca _schelet și fără
     * temele private, în afara celor permise pe această instalare.
     * @param list<string> $permise slugurile temelor private permise (config.php, `teme_private`)
     * @return list<string>
     */
    public static function catalog(string $dirTeme, array $permise = []): array
    {
        $rez = [];
        foreach (glob($dirTeme . '/*/theme.json') ?: [] as $f) {
            $slug = basename(dirname($f));
            if (!preg_match('~^[a-z0-9][a-z0-9\-]{0,63}$~', $slug)) {
                continue;
            }
            if (self::estePrivata(dirname($f)) && !in_array($slug, $permise, true)) {
                continue;
            }
            $rez[] = $slug;
        }
        sort($rez, SORT_STRING);
        return $rez;
    }

    /**
     * Licența temei: temele oficiale sunt sub Licența Cazo, cu identificatorul SPDX LicenseRef-Cazo
     * (fișierul LICENSE al programului); cele terțe au licența aleasă de autor, un identificator SPDX sau orice text (până la 200 de
     * caractere), inclusiv una plătită, și o pot lăsa nedeclarată.
     */
    private static function verificaLicenta(mixed $l, bool $oficiala): void
    {
        if ($oficiala) {
            if (!in_array($l, self::LICENTE_OFICIALE, true)) {
                throw new \UnexpectedValueException('theme.json: temele oficiale au licenta ' . implode(' sau ', self::LICENTE_OFICIALE) . '.');
            }
            return;
        }
        if ($l !== null && (!is_string($l) || trim($l) === '' || mb_strlen($l) > 200)) {
            throw new \UnexpectedValueException('theme.json: licenta e un text de cel mult 200 de caractere (de exemplu un identificator SPDX).');
        }
    }

    public function nume(string $limba): string
    {
        $n = $this->manifest['nume'] ?? null;
        return is_array($n) ? (string) ($n[$limba] ?? $n['ro'] ?? $this->slug()) : (is_string($n) ? $n : $this->slug());
    }

    public function versiune(): string
    {
        return (string) ($this->manifest['versiune'] ?? '');
    }

    public function licenta(): ?string
    {
        $l = $this->manifest['licenta'] ?? null;
        return is_string($l) ? $l : null;
    }

    /**
     * Lățimea coloanei de conținut a temei (theme.json, `latime_rem`, între 40 și 90), în rem; null fără ea. Zona
     * nucleului o primește ca jetonul --pg-latime.
     */
    public function latime(): ?float
    {
        $l = $this->manifest['latime_rem'] ?? null;
        return (is_int($l) || is_float($l)) && $l >= 40 && $l <= 90 ? (float) $l : null;
    }

    /** Numele permise pentru captura temei (theme.json, cheia `captura`): un fișier din rădăcina temei. */
    public const CAPTURA = '~^captura\.(webp|png|jpg|jpeg)$~';

    /**
     * Captura primei pagini (cheia `captura` din theme.json): imaginea mică din cardul temei, în panou și în
     * instalator. Numele fișierului din rădăcina temei, sau null când tema vine fără captură ori fișierul lipsește.
     */
    public function captura(): ?string
    {
        $c = $this->manifest['captura'] ?? null;
        return is_string($c) && preg_match(self::CAPTURA, $c) && is_file($this->dir . '/' . $c) && !is_link($this->dir . '/' . $c) ? $c : null;
    }

    public static function json(string $f): array
    {
        $c = @file_get_contents($f);
        $d = is_string($c) ? json_decode($c, true, 32) : null;
        if (!is_array($d)) {
            throw new \UnexpectedValueException(basename($f) . ' lipsește sau nu e JSON valid.');
        }
        return $d;
    }

    public function slug(): string
    {
        return $this->manifest['slug'];
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function dirSchelet(): string
    {
        return $this->dirSchelet;
    }

    public function manifest(): array
    {
        return $this->manifest;
    }

    public function oficiala(): bool
    {
        return $this->oficiala;
    }

    public function areJs(): bool
    {
        return $this->oficiala && $this->js() !== [];
    }

    /** Amprenta conținutului temei (și a lui _schelet), care numește activele publicate și cache-ul Twig. */
    public function sha(): string
    {
        if ($this->sha === null) {
            $h = hash_init('sha256');
            foreach ([$this->dir, $this->dirSchelet] as $d) {
                foreach (Active::lista($d) as $rel) {
                    hash_update($h, basename($d) . '/' . $rel . "\0" . hash_file('sha256', $d . '/' . $rel) . "\n");
                }
            }
            $this->sha = substr(hash_final($h), 0, 32);
        }
        return $this->sha;
    }

    /** @return list<string> foile temei (assets/css/...), în ordinea din manifest sau alfabetic */
    public function css(): array
    {
        $l = $this->manifest['css'] ?? null;
        if (is_array($l)) {
            return array_values(array_filter($l, static fn ($x): bool => is_string($x) && (bool) preg_match('~^assets/css/[a-z0-9_\-]+\.css$~', $x)));
        }
        $r = [];
        foreach (Active::lista($this->dir . '/assets/css') as $f) {
            if (str_ends_with($f, '.css') && !str_contains($f, '/')) {
                $r[] = 'assets/css/' . $f;
            }
        }
        return $r;
    }

    /** @return list<string> */
    public function js(): array
    {
        $l = $this->manifest['js'] ?? [];
        return is_array($l) ? array_values(array_filter($l, static fn ($x): bool => is_string($x) && (bool) preg_match('~^assets/js/[a-z0-9_\-]+\.js$~', $x))) : [];
    }

    /** @return list<array{familie:string, fisier:string, licenta:string, preincarcat?:bool}> */
    public function fonturi(): array
    {
        $r = [];
        foreach ((array) ($this->manifest['fonturi'] ?? []) as $f) {
            if (is_array($f) && is_string($f['fisier'] ?? null) && preg_match('~^assets/fonts/[A-Za-z0-9_\-.]+\.woff2$~', $f['fisier'])) {
                $r[] = $f;
            }
        }
        return $r;
    }

    /** Valorile implicite ale setărilor globale, din settings.schema.json. */
    public function setariImplicite(): array
    {
        $s = is_file($this->dir . '/settings.schema.json') ? self::json($this->dir . '/settings.schema.json') : [];
        $r = [];
        foreach ((array) ($s['setari'] ?? []) as $x) {
            if (is_array($x) && is_string($x['id'] ?? null)) {
                $r[$x['id']] = $x['implicit'] ?? null;
            }
        }
        return $r;
    }

    /**
     * Culorile fixe ale temei, desenate peste jetoanele pe care le alege gazda (settings.schema.json, cheia `contrast`):
     * fiecare culoare trebuie să treacă pragul pe fiecare jeton din `pe`. Intrările greșite se ignoră.
     * @return list<array{culori:list<string>, pe:list<string>, prag:float}>
     */
    public function contrastTema(): array
    {
        $s = is_file($this->dir . '/settings.schema.json') ? self::json($this->dir . '/settings.schema.json') : [];
        $r = [];
        foreach ((array) ($s['contrast'] ?? []) as $x) {
            if (!is_array($x)) {
                continue;
            }
            $culori = array_values(array_filter((array) ($x['culori'] ?? []), static fn ($c): bool => is_string($c) && (bool) preg_match('~^#[0-9a-fA-F]{6}$~', $c)));
            $pe = array_values(array_filter((array) ($x['pe'] ?? []), static fn ($k): bool => is_string($k) && str_starts_with($k, 'culoare_') && isset(Jetoane::IMPLICITE[$k])));
            $prag = $x['prag'] ?? null;
            if ($culori !== [] && $pe !== [] && (is_int($prag) || is_float($prag)) && $prag >= 3 && $prag <= 7) {
                $r[] = ['culori' => array_map('strtolower', $culori), 'pe' => $pe, 'prag' => (float) $prag];
            }
        }
        return $r;
    }

    /** Șablonul JSON al unei pagini (templates/<nume>.json), sau null (nucleul are implicite pentru cele opționale). */
    public function sablon(string $nume): ?array
    {
        if (!preg_match('~^[a-z0-9_\-]+$~', $nume)) {
            return null;
        }
        $f = $this->dir . '/templates/' . $nume . '.json';
        return is_file($f) ? self::json($f) : null;
    }

    /** Secțiunea: a temei (sections/<tip>.twig) sau din biblioteca _schelet; întoarce numele șablonului Twig. */
    public function sectiune(string $tip): ?string
    {
        if (!preg_match('~^[a-z0-9_\-]{1,64}$~', $tip)) {
            return null;
        }
        if (is_file($this->dir . '/sections/' . $tip . '.twig')) {
            return 'sections/' . $tip . '.twig';
        }
        if (is_file($this->dirSchelet . '/sections/' . $tip . '.twig')) {
            return '@schelet/sections/' . $tip . '.twig';
        }
        return null;
    }

    /** Schema secțiunii (sections/<tip>.json), cu setările și valorile lor implicite. */
    public function schemaSectiune(string $tip): array
    {
        foreach ([$this->dir, $this->dirSchelet] as $d) {
            $f = $d . '/sections/' . $tip . '.json';
            if (is_file($f)) {
                return self::json($f);
            }
        }
        return [];
    }

    /** Textele temei pe limbă: locales/<limba>.json peste cele din _schelet. */
    public function locale(string $limba): array
    {
        $this->locale ??= [];
        if (!isset($this->locale[$limba])) {
            $r = [];
            foreach ([$this->dirSchelet, $this->dir] as $d) {
                $f = $d . '/locales/' . $limba . '.json';
                if (is_file($f)) {
                    $r = array_replace($r, self::json($f));
                }
            }
            $this->locale[$limba] = $r;
        }
        return $this->locale[$limba];
    }
}
