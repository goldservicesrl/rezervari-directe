<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Cazo\Nucleu\Aplicatie;

defined('CAZO') or exit;

/**
 * Temele pe care le poate folosi site-ul:
 *
 *  - oficiale: dosarele din teme/ ale lansării, fără cele care încep cu „_” (_schelet e biblioteca nucleului, _pornire e
 *    scheletul pentru autori); o temă privată (`"privata": true` în theme.json) lipsește din catalog și se
 *    activează doar pe instalarea care o trece în config.php (`teme_private`); ea poate sta și în cazo-privat/teme/<slug>
 *    (direct, fără dosarul versiunii), ca să rămână neatinsă de actualizări;
 *  - terțe: încărcate din panou, validate, în cazo-privat/teme/<slug>/<sha>/, cu rândul lor în `teme` (stare
 *    `instalata`). Slugul îl generează serverul și nu se suprapune cu al unei teme oficiale.
 */
final class Catalog
{
    /** @param ?string $dirOficiale dosarul temelor oficiale (implicit teme/ al lansării; probele dau altul) */
    public function __construct(private Aplicatie $app, private ?string $dirOficiale = null)
    {
    }

    public function dirOficiale(): string
    {
        return $this->dirOficiale ?? Aplicatie::radacina() . '/teme';
    }

    public function dirTerte(): string
    {
        return $this->app->privat() . '/teme';
    }

    /** @return list<Tema> temele oficiale vizibile în catalogul acestui site, în ordinea numelui */
    public function oficiale(): array
    {
        $r = [];
        foreach (glob($this->dirOficiale() . '/*/theme.json') ?: [] as $f) {
            $slug = basename(dirname($f));
            if ($slug[0] === '_') {
                continue;
            }
            try {
                $t = Tema::din(dirname($f), Aplicatie::radacina() . '/teme/_schelet');
            } catch (\Throwable) {
                continue;
            }
            // Catalogul arată doar temele publice și pe cele private permise pe această instalare.
            if ($t->privata() && !$this->permisa($slug)) {
                continue;
            }
            $r[] = $t;
        }
        // O temă privată permisă poate sta și în cazo-privat/teme/<slug>, în afara lansării.
        foreach ($this->app->config()->temePrivate() as $slug) {
            if (!is_file($this->dirOficiale() . '/' . $slug . '/theme.json') && ($t = $this->privata($slug)) !== null) {
                $r[] = $t;
            }
        }
        usort($r, static fn (Tema $a, Tema $b): int => strcmp($a->slug(), $b->slug()));
        return $r;
    }

    /** @return list<array{slug:string, versiune:string, sha:string, stare:string, manifest:array, raport:array, instalata_la:string}> */
    public function terte(): array
    {
        $r = [];
        foreach ($this->app->baza()->toate("SELECT * FROM {teme} WHERE sursa = 'terta' ORDER BY slug") as $x) {
            $r[] = [
                'slug' => (string) $x['slug'], 'versiune' => (string) $x['versiune'], 'sha' => (string) $x['sha'], 'stare' => (string) $x['stare'],
                'manifest' => json_decode((string) $x['manifest'], true) ?: [], 'raport' => json_decode((string) ($x['raport'] ?? ''), true) ?: [],
                'instalata_la' => (string) $x['instalata_la'],
            ];
        }
        return $r;
    }

    /** Slugul unei teme private trecut în config.php (`teme_private`). */
    private function permisa(string $slug): bool
    {
        return in_array($slug, $this->app->config()->temePrivate(), true);
    }

    /** Tema privată permisă din cazo-privat/teme/<slug> (fără dosarul versiunii, deci nu o temă terță), sau null. */
    private function privata(string $slug): ?Tema
    {
        $dir = $this->dirTerte() . '/' . $slug;
        if (!$this->permisa($slug) || !is_file($dir . '/theme.json')) {
            return null;
        }
        try {
            $t = Tema::din($dir, Aplicatie::radacina() . '/teme/_schelet');
        } catch (\Throwable) {
            return null;
        }
        return $t->privata() ? $t : null;
    }

    /** Tema cu slugul dat, dacă site-ul o poate folosi: oficială (cea privată, doar permisă) sau terță instalată. */
    public function tema(string $slug): ?Tema
    {
        if (!preg_match('~^[a-z0-9][a-z0-9\-]{0,63}$~', $slug)) {
            return null;
        }
        $dir = $this->dirOficiale() . '/' . $slug;
        if (is_file($dir . '/theme.json')) {
            try {
                $t = Tema::din($dir, Aplicatie::radacina() . '/teme/_schelet');
            } catch (\Throwable) {
                return null;
            }
            if ($t->privata() && !$this->permisa($slug)) {
                $this->app->jurnal()->avertisment('Tema privată nu e permisă pe acest site', ['tema' => $slug]);
                return null;
            }
            return $t;
        }
        if (($t = $this->privata($slug)) !== null) {
            return $t;
        }
        $rand = $this->app->baza()->rand("SELECT sha, stare FROM {teme} WHERE slug = ? AND sursa = 'terta'", [$slug]);
        if ($rand === null || $rand['stare'] !== 'instalata' || !preg_match('~^[0-9a-f]{32,64}$~', (string) $rand['sha'])) {
            return null;
        }
        $dir = $this->dirTerte() . '/' . $slug . '/' . $rand['sha'];
        if (!is_file($dir . '/theme.json')) {
            return null;
        }
        try {
            return Tema::din($dir, Aplicatie::radacina() . '/teme/_schelet', false, $slug);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Sha-ul versiunii din rândul unei teme terțe (numele dosarului ei de pe disc), sau null pentru o temă oficială. */
    public function shaInstalat(string $slug): ?string
    {
        $sha = $this->app->baza()->valoare("SELECT sha FROM {teme} WHERE slug = ? AND sursa = 'terta'", [$slug]);
        return is_string($sha) && $sha !== '' ? $sha : null;
    }

    /**
     * O versiune anume (sha) a unei teme terțe instalate, de exemplu versiunea nouă încărcată peste tema activă, ca
     * gazda s-o previzualizeze înainte s-o activeze; null dacă versiunea lipsește de pe disc.
     */
    public function temaVersiune(string $slug, string $sha): ?Tema
    {
        if (!preg_match('~^[a-z0-9][a-z0-9\-]{0,63}$~', $slug) || !preg_match('~^[0-9a-f]{32,64}$~', $sha)) {
            return null;
        }
        $dir = $this->dirTerte() . '/' . $slug . '/' . $sha;
        if (!is_file($dir . '/theme.json')) {
            return null;
        }
        try {
            return Tema::din($dir, Aplicatie::radacina() . '/teme/_schelet', false, $slug);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Slugul unei teme terțe noi: din numele propus de autor, fără suprapunere cu temele oficiale și nici cu temele
     * terțe instalate (o altă temă cu același nume propus primește „-2”, „-3” ..., nu o suprascrie pe cea instalată).
     */
    public function slugNou(string $propus): string
    {
        $baza = Validator::slugDin($propus);
        if ($baza[0] === '_') {
            $baza = ltrim($baza, '_') ?: 'tema';
        }
        $folosite = array_fill_keys(array_column($this->app->baza()->toate('SELECT slug FROM {teme}'), 'slug'), true);
        $ocupat = fn (string $s): bool => is_dir($this->dirOficiale() . '/' . $s) || isset($folosite[$s]) || is_dir($this->dirTerte() . '/' . $s);
        $slug = $baza;
        for ($i = 2; $ocupat($slug) && $i < 1000; $i++) {
            $slug = $baza . '-' . $i;
        }
        return $slug;
    }
}
