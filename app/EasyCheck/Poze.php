<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Jurnal;

defined('CAZO') or exit;

/**
 * Pozele EasyCheck: amprentele din GET /proprietate și GET /camere față de tabelul `poze`.
 *
 * Doar o amprentă nouă se descarcă: doar de pe https:// și doar de pe domeniul pozelor EasyCheck, fără
 * redirecționări, cu limită de mărime; conținutul trebuie să aibă exact amprenta anunțată. Originalul stă în
 * cazo-privat/media/original/, derivatele în public_html/media/<sha>/<latime>.<ext>; toate pozele se servesc
 * de pe domeniul site-ului (img-src 'self'). Până la prima derivare, tema primește poza-substitut neutră.
 */
final class Poze
{
    public const MARIME_MAXIMA = 20 * 1024 * 1024;
    public const INCERCARI = 3;
    public const DOMENIU = 'easycheck.ro';

    /** @var \Closure(string $url): string */
    private \Closure $descarca;

    /**
     * @param string[] $gazdePermise gazdele de pe care se descarcă (implicit easycheck.ro și subdomeniile lui)
     * @param ?\Closure(string): string $descarca descărcătorul (testele unitare dau unul propriu)
     */
    public function __construct(
        private Baza $baza,
        private Ceas $ceas,
        private string $dirPrivat,
        private string $dirPublic,
        private array $gazdePermise = [self::DOMENIU],
        private bool $permiteHttp = false,
        ?\Closure $descarca = null,
        private ?Jurnal $jurnal = null,
    ) {
        $this->descarca = $descarca ?? fn (string $url): string => $this->descarcaCurl($url);
    }

    /** Actualizează lista din oglindă: amprentele noi intră ca „noua”, cele folosite primesc folosit_la. */
    public function dinOglinda(?array $proprietate, ?array $camere): int
    {
        $noi = 0;
        $acum = $this->ceas->moment();
        foreach (Proiectii::poze($proprietate, $camere) as $p) {
            $exista = $this->baza->valoare('SELECT 1 FROM {poze} WHERE sha256 = ?', [$p['sha256']]) !== null;
            if ($exista) {
                $this->baza->executa('UPDATE {poze} SET url_sursa = ?, pozitie = ?, folosit_la = ? WHERE sha256 = ?',
                    [mb_substr($p['url'], 0, 1000), $p['pozitie'], $acum, $p['sha256']]);
                // O poză derivată cu fișierele lipsă de pe site (o mutare, o restaurare): se descarcă și se derivă din nou (M6b).
                if (preg_match('~^[0-9a-f]{64}$~', (string) $p['sha256']) && (glob($this->dirPublic . '/media/' . $p['sha256'] . '/*') ?: []) === []
                    && $this->baza->executa("UPDATE {poze} SET stare = 'noua', incercari = 0, eroare = NULL, urmatoarea_la = NULL WHERE sha256 = ? AND stare = 'derivata'",
                        [$p['sha256']])->rowCount() === 1) {
                    $noi++;
                }
                continue;
            }
            $this->baza->insereaza('poze', [
                'sha256' => $p['sha256'],
                'url_sursa' => mb_substr($p['url'], 0, 1000),
                'latime' => $p['latime'],
                'inaltime' => $p['inaltime'],
                'pozitie' => $p['pozitie'],
                'stare' => 'noua',
                'variante' => null,
                'folosit_la' => $acum,
                'incercari' => 0,
                'eroare' => null,
                'urmatoarea_la' => null,
            ]);
            $noi++;
        }
        return $noi;
    }

    /**
     * Pozele EasyCheck derivate ale căror fișiere lipsesc de pe site (o mutare, o restaurare) trec în „noua”, ca să se
     * descarce din nou; independent de schimbarea resurselor (o sincronizare fără schimbări, 304, nu cheamă dinOglinda).
     * Rulează la curățenia orară și la „Sincronizați acum”, cu bugetul de timp dat (M6b). Întoarce câte s-au reluat.
     */
    public function verificaFisiere(float $buget = 5.0): int
    {
        $start = microtime(true);
        $n = 0;
        foreach ($this->baza->toate("SELECT sha256 FROM {poze} WHERE stare = 'derivata' AND url_sursa <> 'local:incarcata' ORDER BY sha256") as $r) {
            if (microtime(true) - $start > $buget) {
                break;
            }
            $sha = (string) $r['sha256'];
            if (preg_match('~^[0-9a-f]{64}$~', $sha) && (glob($this->dirPublic . '/media/' . $sha . '/*') ?: []) === []) {
                $n += $this->baza->executa("UPDATE {poze} SET stare = 'noua', incercari = 0, eroare = NULL, urmatoarea_la = NULL WHERE sha256 = ? AND stare = 'derivata'", [$sha])->rowCount();
            }
        }
        return $n;
    }

    /** Câte poze așteaptă descărcarea sau derivarea. */
    public function inAsteptare(): int
    {
        return (int) $this->baza->valoare("SELECT COUNT(*) FROM {poze} WHERE stare IN ('noua', 'descarcata') OR (stare = 'eroare' AND incercari < ?)", [self::INCERCARI]);
    }

    /** Câte poze se pot prelucra chiar acum (fără cele cu o reîncercare programată mai târziu). */
    public function deFacutAcum(): int
    {
        return (int) $this->baza->valoare(
            "SELECT COUNT(*) FROM {poze} WHERE (stare IN ('noua', 'descarcata') OR (stare = 'eroare' AND incercari < ?)) AND (urmatoarea_la IS NULL OR urmatoarea_la <= ?)",
            [self::INCERCARI, $this->ceas->moment()]
        );
    }

    /** Poza încă se prelucrează (pagina care o arată cu substitutul rămâne în afara cache-ului de pagini). */
    public function inLucru(string $sha): bool
    {
        return $this->baza->valoare("SELECT 1 FROM {poze} WHERE sha256 = ? AND (stare IN ('noua', 'descarcata') OR (stare = 'eroare' AND incercari < ?))", [$sha, self::INCERCARI]) !== null;
    }

    /**
     * Prelucrează pozele care așteaptă: descărcarea, verificarea, derivatele. Cel mult $max poze și, cu $buget,
     * cel mult atâtea secunde (prima poză se prelucrează oricum; următoarea pornește doar cât bugetul mai are loc
     * pentru durata celei mai lente de până acum).
     * @return array{derivate:int, erori:int}
     */
    public function prelucreaza(int $max = 2, ?float $buget = null): array
    {
        $acum = $this->ceas->moment();
        $lista = $this->baza->toate(
            "SELECT * FROM {poze} WHERE (stare IN ('noua', 'descarcata') OR (stare = 'eroare' AND incercari < ?))
               AND (urmatoarea_la IS NULL OR urmatoarea_la <= ?) ORDER BY pozitie, sha256 LIMIT " . max(1, min(60, $max)),
            [self::INCERCARI, $acum]
        );
        $rez = ['derivate' => 0, 'erori' => 0];
        $start = microtime(true);
        $ceaMaiLenta = 0.0;
        foreach ($lista as $i => $p) {
            $t0 = microtime(true);
            if ($buget !== null && $i > 0 && ($t0 - $start) + $ceaMaiLenta > $buget) {
                break;
            }
            try {
                $this->una($p);
                $rez['derivate']++;
            } catch (\Throwable $e) {
                $rez['erori']++;
                $incercari = (int) $p['incercari'] + 1;
                $this->baza->executa("UPDATE {poze} SET stare = 'eroare', incercari = ?, eroare = ?, urmatoarea_la = ? WHERE sha256 = ?", [
                    $incercari, mb_substr(Jurnal::mascheaza(get_class($e) . ': ' . $e->getMessage()), 0, 480), $this->ceas->moment(600 * $incercari), $p['sha256'],
                ]);
                $this->jurnal?->avertisment('Poza EasyCheck refuzată', ['sha256' => $p['sha256'], 'eroare' => $e->getMessage()]);
            }
            $ceaMaiLenta = max($ceaMaiLenta, microtime(true) - $t0);
        }
        return $rez;
    }

    private function una(array $p): void
    {
        $sha = (string) $p['sha256'];
        if (!preg_match('~^[0-9a-f]{64}$~', $sha)) {
            throw new \UnexpectedValueException('Amprentă invalidă.');
        }
        $original = $this->dirPrivat . '/media/original/' . $sha;
        $octeti = is_file($original) ? (string) file_get_contents($original) : null;
        if ($octeti === null || !hash_equals($sha, hash('sha256', $octeti))) {
            $this->verificaAdresa((string) $p['url_sursa']);
            $octeti = ($this->descarca)((string) $p['url_sursa']);
            if (!hash_equals($sha, hash('sha256', $octeti))) {
                throw new \UnexpectedValueException('Conținutul pozei are altă amprentă decât cea anunțată.');
            }
            $this->scrieAtomic($original, $octeti, 0600);
            $this->baza->executa("UPDATE {poze} SET stare = 'descarcata' WHERE sha256 = ?", [$sha]);
        }
        $dir = $this->dirMedia() . '/' . $sha;
        $d = Imagini::deriva($octeti, $dir);
        $this->baza->executa("UPDATE {poze} SET stare = 'derivata', latime = ?, inaltime = ?, variante = ?, eroare = NULL, urmatoarea_la = NULL WHERE sha256 = ?", [
            $d['latime'], $d['inaltime'], json_encode(['latimi' => $d['latimi'], 'formate' => $d['formate']], JSON_THROW_ON_ERROR), $sha,
        ]);
    }

    /**
     * Derivatele unei poze pentru temă: adresele pe latimi și formate, sau null cât poza încă se prelucrează
     * (tema arată atunci poza-substitut neutră, niciodată adresa externă).
     * @return array{latime:int, inaltime:int, surse:array<string,array<int,string>>}|null
     */
    public function pentruTema(string $sha): ?array
    {
        $r = $this->baza->rand("SELECT latime, inaltime, variante FROM {poze} WHERE sha256 = ? AND stare = 'derivata'", [$sha]);
        $v = $r !== null ? json_decode((string) $r['variante'], true) : null;
        if (!is_array($v)) {
            return null;
        }
        $surse = [];
        foreach ($v['formate'] as $f) {
            foreach ($v['latimi'] as $l) {
                $surse[$f][(int) $l] = '/media/' . $sha . '/' . (int) $l . '.' . $f;
            }
        }
        return ['latime' => (int) $r['latime'], 'inaltime' => (int) $r['inaltime'], 'surse' => $surse];
    }

    /** Adresa trebuie să fie https (http doar în mediul de test) și pe o gazdă permisă, fără utilizator sau port străin. */
    public function verificaAdresa(string $url): void
    {
        $p = parse_url($url);
        $schema = strtolower((string) ($p['scheme'] ?? ''));
        $gazda = strtolower((string) ($p['host'] ?? ''));
        if (!is_array($p) || isset($p['user']) || isset($p['pass']) || !in_array($schema, $this->permiteHttp ? ['https', 'http'] : ['https'], true)
            || preg_match('~[\x00-\x20"<>\\\\]~', $url)) {
            throw new \UnexpectedValueException('Adresa pozei nu e permisă.');
        }
        if (isset($p['port']) && !$this->permiteHttp && (int) $p['port'] !== 443) {
            throw new \UnexpectedValueException('Adresa pozei nu e permisă.');
        }
        foreach ($this->gazdePermise as $g) {
            $g = strtolower($g);
            if ($gazda === $g || str_ends_with($gazda, '.' . $g)) {
                return;
            }
        }
        throw new \UnexpectedValueException('Poza vine de pe o gazdă nepermisă.');
    }

    private function descarcaCurl(string $url): string
    {
        // Fără curl_init sau curl_exec (oprite de găzduire): descărcarea eșuează ca la o rețea căzută.
        if (!function_exists('curl_exec') || !function_exists('curl_init')) {
            throw new \RuntimeException('Descărcarea pozei a eșuat: cURL e oprit pe server.');
        }
        $h = curl_init($url);
        $corp = '';
        $prea = false;
        curl_setopt_array($h, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $this->permiteHttp ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'Cazo',
            CURLOPT_WRITEFUNCTION => static function ($c, string $b) use (&$corp, &$prea): int {
                if (strlen($corp) + strlen($b) > self::MARIME_MAXIMA) {
                    $prea = true;
                    return 0;
                }
                $corp .= $b;
                return strlen($b);
            },
        ]);
        $ok = function_exists('curl_exec') ? curl_exec($h) : false;
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $err = curl_error($h);
        curl_close($h);
        if ($prea) {
            throw new \UnexpectedValueException('Poza depășește mărimea permisă.');
        }
        if ($ok === false || $status !== 200) {
            throw new \RuntimeException('Descărcarea pozei a eșuat: ' . ($status ?: $err));
        }
        return $corp;
    }

    /** public_html/media, cu .htaccess-active copiat ca .htaccess la prima folosire. */
    private function dirMedia(): string
    {
        $d = $this->dirPublic . '/media';
        if (!is_dir($d)) {
            mkdir($d, 0755, true);
        }
        if (!is_file($d . '/.htaccess')) {
            $sursa = dirname(__DIR__, 2) . '/public/.htaccess-active';
            if (is_file($sursa)) {
                @copy($sursa, $d . '/.htaccess');
            }
        }
        return $d;
    }

    private function scrieAtomic(string $cale, string $continut, int $mod): void
    {
        if (!is_dir(dirname($cale))) {
            mkdir(dirname($cale), 0700, true);
        }
        $tmp = $cale . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $continut, LOCK_EX);
        \Cazo\Nucleu\Functii::chmod($tmp, $mod);
        rename($tmp, $cale);
    }
}
