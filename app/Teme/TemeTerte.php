<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Cazo\Nucleu\Aplicatie;

defined('CAZO') or exit;

/**
 * Temele încărcate de gazdă: despachetarea, validatorul complet, re-encodarea imaginilor și
 * curățarea SVG-urilor, apoi mutarea în cazo-privat/teme/<slug>/<sha>/ (imuabil: o versiune nouă are alt sha, deci
 * activele ei nu suprascriu versiunea activă, iar revenirea e instantanee) și rândul din `teme`, cu raportul.
 * Tema trece în `instalata` doar fără erori; gazda o activează după previzualizare, iar activarea rulează validatorul
 * din nou. Raportul ultimei încărcări (și al unei teme respinse) stă în setarea `teme.ultima_incarcare`.
 */
final class TemeTerte
{
    /**
     * Versiunile unei teme care rămân pe disc: cea din rândul `teme` (activă sau instalată), versiunea nouă care
     * așteaptă activarea gazdei și cea dinainte, la care duce „Reveniți” (tema.anterioara = „slug@sha”). Restul se șterg.
     */
    public const VERSIUNI_PASTRATE = 3;

    /** Setarea cu versiunile noi încărcate peste o temă activă, pe slug: {sha, versiune, manifest, raport, instalata_la}. */
    public const SETARE_NOI = 'teme.versiuni_noi';

    public function __construct(private Aplicatie $app)
    {
    }

    private function lucru(): string
    {
        return $this->app->privat() . '/lucru';
    }

    /**
     * @return array{slug:?string, nume:string, versiune:string, instalata:bool, raport:array}
     */
    public function incarca(string $zip): array
    {
        $r = new Raport();
        $rez = ['slug' => null, 'nume' => '', 'versiune' => '', 'instalata' => false, 'raport' => []];
        $d = null;
        $this->curataLucru();
        try {
            $d = Despachetare::din($zip, $this->lucru());
        } catch (EroareTema $e) {
            $r->eroare('arhiva', $e->getMessage());
        }
        if ($d !== null) {
            try {
                $m = json_decode((string) file_get_contents($d->dir . '/theme.json'), true, 32);
                $m = is_array($m) ? $m : [];
                $slug = $this->slugPentru($m);
                $rez['slug'] = $slug;
                $rez['nume'] = is_array($m['nume'] ?? null) ? (string) ($m['nume']['ro'] ?? $slug) : $slug;
                $rez['versiune'] = (string) ($m['versiune'] ?? '');
                $r = Validator::verifica($d->dir, false, ['slug' => $slug, 'lucru' => $this->lucru()]);
                if ($r->trece()) {
                    $this->reencodeaza($d->dir, $r);
                }
                if ($r->trece()) {
                    $rez['in_asteptare'] = $this->instaleaza($d->dir, $slug, $m, $r);
                    $rez['instalata'] = true;
                }
            } finally {
                Despachetare::sterge($d->dir);
            }
        }
        $rez['raport'] = $r->tablou();
        $this->app->setari()->pune('teme.ultima_incarcare', $rez + ['la' => $this->app->ceas()->acum()]);
        $this->app->jurnal()->info('Temă încărcată', ['slug' => $rez['slug'], 'instalata' => $rez['instalata'], 'erori' => count($r->erori)]);
        return $rez;
    }

    /**
     * Slugul arhivei încărcate: o versiune nouă a unei teme terțe deja instalate (același slug propus și același autor)
     * își păstrează slugul; orice altă temă primește unul nou, fără să suprascrie o temă oficială sau terță.
     */
    private function slugPentru(array $m): string
    {
        $propus = is_string($m['slug'] ?? null) ? $m['slug'] : 'tema';
        $autor = static fn (array $x): string => is_array($x['autor'] ?? null) ? trim((string) ($x['autor']['nume'] ?? '')) : '';
        foreach ($this->app->catalog()->terte() as $x) {
            $vechi = $x['manifest'];
            if (Validator::slugDin(is_string($vechi['slug'] ?? null) ? $vechi['slug'] : '') === Validator::slugDin($propus) && $autor($vechi) === $autor($m)) {
                return $x['slug'];
            }
        }
        return $this->app->catalog()->slugNou($propus);
    }

    /** Versiunea nouă care așteaptă activarea gazdei, pentru tema dată, sau null. */
    public function versiuneNoua(string $slug): ?array
    {
        $noi = $this->app->setari()->get(self::SETARE_NOI, []);
        $v = is_array($noi) ? ($noi[$slug] ?? null) : null;
        return is_array($v) && is_string($v['sha'] ?? null) && is_dir($this->app->catalog()->dirTerte() . '/' . $slug . '/' . $v['sha']) ? $v : null;
    }

    private function puneVersiuneNoua(string $slug, ?array $v): void
    {
        $noi = $this->app->setari()->get(self::SETARE_NOI, []);
        $noi = is_array($noi) ? $noi : [];
        if ($v === null) {
            unset($noi[$slug]);
        } else {
            $noi[$slug] = $v;
        }
        $this->app->setari()->pune(self::SETARE_NOI, $noi);
    }

    /**
     * Activarea versiunii noi a unei teme (gazda o activează după previzualizare): validatorul rulează din nou,
     * iar rândul temei trece pe versiunea nouă. Întoarce sha-ul versiunii de dinainte (pentru revenire) sau null.
     */
    public function activeazaVersiuneaNoua(string $slug, string $sha): ?string
    {
        $v = $this->versiuneNoua($slug);
        $rand = $this->app->baza()->rand("SELECT sha FROM {teme} WHERE slug = ? AND sursa = 'terta'", [$slug]);
        if ($v === null || $v['sha'] !== $sha || $rand === null) {
            return null;
        }
        $dir = $this->app->catalog()->dirTerte() . '/' . $slug . '/' . $sha;
        $r = Validator::verifica($dir, false, ['slug' => $slug, 'lucru' => $this->lucru()]);
        if (!$r->trece()) {
            $v['raport'] = $r->tablou();
            $this->puneVersiuneNoua($slug, $v);
            return null;
        }
        $this->scrieRand($slug, ['versiune' => (string) $v['versiune'], 'sha' => $sha, 'sursa' => 'terta', 'stare' => 'instalata',
            'manifest' => json_encode($v['manifest'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'raport' => json_encode($r->tablou(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'instalata_la' => $this->app->ceas()->moment()]);
        $this->puneVersiuneNoua($slug, null);
        return (string) $rand['sha'];
    }

    /** Revenirea la o versiune anume a temei (cea dinainte, păstrată pe disc): rândul trece înapoi pe sha-ul ei. */
    public function revinoLaVersiune(string $slug, string $sha): bool
    {
        $t = $this->app->catalog()->temaVersiune($slug, $sha);
        if ($t === null || $this->app->baza()->rand("SELECT slug FROM {teme} WHERE slug = ? AND sursa = 'terta'", [$slug]) === null) {
            return false;
        }
        $r = Validator::verifica($t->dir(), false, ['slug' => $slug, 'lucru' => $this->lucru()]);
        if (!$r->trece()) {
            return false;
        }
        $this->scrieRand($slug, ['versiune' => $t->versiune(), 'sha' => $sha, 'sursa' => 'terta', 'stare' => 'instalata',
            'manifest' => json_encode(Tema::json($t->dir() . '/theme.json'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'raport' => json_encode($r->tablou(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'instalata_la' => $this->app->ceas()->moment()]);
        return true;
    }

    private function scrieRand(string $slug, array $rand): void
    {
        $b = $this->app->baza();
        $b->tranzactie(static function () use ($b, $slug, $rand): void {
            if ($b->rand('SELECT slug FROM {teme} WHERE slug = ?', [$slug]) !== null) {
                $b->executa('UPDATE {teme} SET versiune = ?, sha = ?, sursa = ?, stare = ?, manifest = ?, raport = ?, instalata_la = ? WHERE slug = ?', [...array_values($rand), $slug]);
            } else {
                $b->insereaza('teme', ['slug' => $slug] + $rand);
            }
        });
    }

    /**
     * Dosarele de lucru rămase de la o încărcare oprită brusc (o eroare fatală nu mai ajunge la finally): cele mai vechi
     * de o oră se șterg la următoarea încărcare.
     */
    private function curataLucru(): void
    {
        foreach (glob($this->lucru() . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if ((int) @filemtime($d) < time() - 3600) {
                Despachetare::sterge($d);
            }
        }
    }

    /** Imaginile se re-encodează (fără metadate, fără conținut ascuns), SVG-urile se curăță. */
    private function reencodeaza(string $dir, Raport $r): void
    {
        foreach (Active::lista($dir) as $f) {
            // Imaginile temei și captura ei din rădăcină (theme.json, cheia `captura`).
            if (!preg_match('~^(?:assets/img|previews)/.+\.(png|jpe?g|webp|avif|svg)$~i', $f, $m) && !preg_match('~^captura\.(webp|png|jpe?g)$~', $f, $m)) {
                continue;
            }
            $cale = $dir . '/' . $f;
            $ext = strtolower($m[1]);
            try {
                if ($ext === 'svg') {
                    file_put_contents($cale, Svg::curata((string) file_get_contents($cale)));
                    continue;
                }
                if (!function_exists('imagecreatefromstring')) {
                    $r->eroare('imagini', 'Serverul nu are extensia PHP GD: imaginile temei nu se pot re-encoda.', $f);
                    return;
                }
                // Mărimea din antet, înainte de decodare: o imagine prea mare ar umple memoria lui GD (eroare fatală).
                $i = @getimagesize($cale);
                if ($i === false || !Validator::imagineIncape((int) $i[0], (int) $i[1])) {
                    $r->eroare('imagini', 'Imaginea nu se poate citi sau depășește 16 megapixeli (de exemplu 4000 x 4000) sau 6000 px pe o latură.', $f);
                    continue;
                }
                $img = @imagecreatefromstring((string) file_get_contents($cale));
                if ($img === false) {
                    $r->eroare('imagini', 'Imaginea nu se poate citi.', $f);
                    continue;
                }
                imagesavealpha($img, true);
                $ok = match ($ext) {
                    'png' => imagepng($img, $cale, 9),
                    'jpg', 'jpeg' => imagejpeg($img, $cale, 85),
                    'webp' => function_exists('imagewebp') && imagewebp($img, $cale, 85),
                    'avif' => function_exists('imageavif') && imageavif($img, $cale, 60),
                    default => false,
                };
                imagedestroy($img);
                if (!$ok) {
                    $r->eroare('imagini', 'Serverul nu poate re-encoda imaginile ' . $ext . '; folosiți PNG, JPEG sau WebP.', $f);
                }
            } catch (EroareTema $e) {
                $r->eroare('imagini', $e->getMessage(), $f);
            }
        }
    }

    /**
     * Instalarea în cazo-privat/teme/<slug>/<sha>/. O versiune nouă a temei active nu intră pe site: rămâne alături,
     * în așteptare, până când gazda o previzualizează și o activează; o temă inactivă trece direct pe versiunea
     * nouă. Întoarce true când versiunea nouă așteaptă activarea.
     */
    private function instaleaza(string $dir, string $slug, array $m, Raport $r): bool
    {
        $tema = Tema::din($dir, Aplicatie::radacina() . '/teme/_schelet', false, $slug);
        $sha = $tema->sha();
        $baza = $this->app->catalog()->dirTerte() . '/' . $slug;
        if (!is_dir($baza)) {
            mkdir($baza, 0700, true);
        }
        $tinta = $baza . '/' . $sha;
        if (!is_dir($tinta)) {
            $tmp = $baza . '/.' . $sha . '-' . bin2hex(random_bytes(4));
            self::copiaza($dir, $tmp);
            rename($tmp, $tinta);
        }
        $acum = $this->app->ceas()->moment();
        $existent = $this->app->baza()->rand("SELECT sha FROM {teme} WHERE slug = ? AND sursa = 'terta'", [$slug]);
        $activa = $this->app->setari()->get('tema.activa', Tema::IMPLICITA) === $slug;
        $asteapta = $existent !== null && $activa && (string) $existent['sha'] !== $sha;
        if ($asteapta) {
            $this->puneVersiuneNoua($slug, ['sha' => $sha, 'versiune' => (string) ($m['versiune'] ?? ''), 'manifest' => $m, 'raport' => $r->tablou(), 'instalata_la' => $acum]);
        } else {
            $this->scrieRand($slug, ['versiune' => (string) ($m['versiune'] ?? ''), 'sha' => $sha, 'sursa' => 'terta', 'stare' => 'instalata',
                'manifest' => json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'raport' => json_encode($r->tablou(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'instalata_la' => $acum]);
            $this->puneVersiuneNoua($slug, null);
        }
        $this->curataVersiuni($slug);
        return $asteapta;
    }

    /**
     * Păstrează pe disc versiunile folosite: cea din rândul temei, versiunea nouă în așteptare și cea la care duce
     * „Reveniți”; restul se șterg.
     */
    public function curataVersiuni(string $slug): void
    {
        $baza = $this->app->catalog()->dirTerte() . '/' . $slug;
        $pastrate = [];
        $rand = $this->app->baza()->rand("SELECT sha FROM {teme} WHERE slug = ? AND sursa = 'terta'", [$slug]);
        if ($rand !== null) {
            $pastrate[(string) $rand['sha']] = true;
        }
        $noua = $this->versiuneNoua($slug);
        if ($noua !== null) {
            $pastrate[(string) $noua['sha']] = true;
        }
        $ant = (string) $this->app->setari()->get('tema.anterioara', '');
        if (str_starts_with($ant, $slug . '@')) {
            $pastrate[substr($ant, strlen($slug) + 1)] = true;
        }
        foreach (glob($baza . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (!isset($pastrate[basename($d)])) {
                Despachetare::sterge($d);
            }
        }
    }

    /** Ștergerea unei teme terțe (doar când nu e activă). */
    public function sterge(string $slug): bool
    {
        if ($this->app->setari()->get('tema.activa', Tema::IMPLICITA) === $slug) {
            return false;
        }
        $n = $this->app->baza()->executa("DELETE FROM {teme} WHERE slug = ? AND sursa = 'terta'", [$slug])->rowCount();
        $this->puneVersiuneNoua($slug, null);
        if (preg_match('~^[a-z0-9][a-z0-9\-]{0,63}$~', $slug)) {
            Despachetare::sterge($this->app->catalog()->dirTerte() . '/' . $slug);
        }
        $this->app->baza()->executa('DELETE FROM {teme_setari} WHERE tema = ?', [$slug]);
        return $n > 0;
    }

    /**
     * Validatorul pe o temă instalată (la activare și după o actualizare a nucleului): raportul nou se păstrează;
     * o temă care nu mai trece iese din `instalata`.
     */
    public function reverifica(string $slug): Raport
    {
        $t = $this->app->catalog()->tema($slug);
        if ($t === null || $t->oficiala()) {
            $r = new Raport();
            $t === null ? $r->eroare('manifest', 'Tema lipsește.') : null;
            return $r;
        }
        $r = Validator::verifica($t->dir(), false, ['slug' => $slug, 'lucru' => $this->lucru()]);
        $this->app->baza()->executa('UPDATE {teme} SET raport = ?, stare = ? WHERE slug = ?', [json_encode($r->tablou(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $r->trece() ? 'instalata' : 'respinsa', $slug]);
        return $r;
    }

    private static function copiaza(string $din, string $in): void
    {
        mkdir($in, 0700, true);
        foreach (Active::lista($din) as $rel) {
            if (!is_dir(dirname($in . '/' . $rel))) {
                mkdir(dirname($in . '/' . $rel), 0700, true);
            }
            copy($din . '/' . $rel, $in . '/' . $rel);
            \Cazo\Nucleu\Functii::chmod($in . '/' . $rel, 0600);
        }
    }
}
