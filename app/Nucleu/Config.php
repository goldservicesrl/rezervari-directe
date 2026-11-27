<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

use Cazo\Securitate\Chei;
use Cazo\Securitate\Cutie;

defined('CAZO') or exit;

/**
 * config.php din dosarul privat: DSN, căi, id-ul instalării, limba implicită. Fără secrete în clar:
 * parola MySQL stă criptată cu subcheia „config” a cheii-mamă (cheie.bin).
 *
 * Forma:
 * [
 *   'id_instalare' => '...', 'site_url' => 'https://exemplu.ro', 'limba_implicita' => 'ro', 'limbi' => ['ro', 'en'],
 *   'rescriere' => true, 'instalat_la' => '2026-11-14 10:00:00',
 *   'proxy_increzut' => ['173.245.48.0/20', ...]   (opțional: proxy-urile din fața site-ului, vezi Ip::client),
 *   'cale_public' => '/home/cont/public_html'      (webroot-ul: derivatele pozelor, activele; implicit ../public_html),
 *   'teme_private' => ['slug']                     (opțional: temele private permise pe acest site, vezi Tema::privata),
 *   'mediu' => 'test'                               (doar în dezvoltare și în teste: permite 'easycheck' de mai jos),
 *   'easycheck' => ['baza' => 'http://127.0.0.1:8090/api/v1', 'gazde_poze' => ['localhost'], 'timeouts' => [...]],
 *   'baza' => ['driver' => 'sqlite', 'cale' => 'cazo.sqlite', 'prefix' => 'pg_']
 *          sau ['driver' => 'mysql', 'gazda' => ..., 'port' => ..., 'nume' => ..., 'utilizator' => ..., 'parola_c' => 'v1....', 'prefix' => 'pg_'],
 * ]
 */
final class Config
{
    public function __construct(private array $date, private string $privat)
    {
        $url = (string) ($date['site_url'] ?? '');
        if (!preg_match('~^https?://[A-Za-z0-9.\-]+(:\d{1,5})?$~', $url)) {
            throw new \InvalidArgumentException('site_url trebuie să fie o origine (schemă, domeniu, port opțional), fără cale.');
        }
    }

    /**
     * @param bool $cuInstalare și configurarea instalării în curs (config-instalare.php, scrisă de instalator de la
     *   pasul 5 până la pasul 12), când config.php lipsește: cronul rulează așa și în timpul instalării
     *   (sincronizarea, pozele în fundal, verificarea cronului din pasul 11)
     */
    public static function dinDosar(string $privat, bool $cuInstalare = false): self
    {
        $cale = $privat . '/config.php';
        if ($cuInstalare && !is_file($cale) && is_file($privat . '/' . \Cazo\Instalare\Loc::CONFIG_LUCRU)) {
            $cale = $privat . '/' . \Cazo\Instalare\Loc::CONFIG_LUCRU;
        }
        if (!is_file($cale)) {
            throw new \RuntimeException('config.php lipsește.');
        }
        $date = require $cale;
        if (!is_array($date)) {
            throw new \RuntimeException('config.php are o formă greșită.');
        }
        return new self($date, $privat);
    }

    public function privat(): string
    {
        return $this->privat;
    }

    public function get(string $cheie, mixed $implicit = null): mixed
    {
        $v = $this->date;
        foreach (explode('.', $cheie) as $parte) {
            if (!is_array($v) || !array_key_exists($parte, $v)) {
                return $implicit;
            }
            $v = $v[$parte];
        }
        return $v;
    }

    public function toate(): array
    {
        return $this->date;
    }

    /** Originea site-ului (schemă + domeniu + port), folosită în CSP și în linkurile absolute. */
    public function origine(): string
    {
        return rtrim((string) $this->date['site_url'], '/');
    }

    public function https(): bool
    {
        return str_starts_with($this->origine(), 'https://');
    }

    public function limbi(): array
    {
        return $this->date['limbi'] ?? ['ro', 'en'];
    }

    public function limbaImplicita(): string
    {
        return $this->date['limba_implicita'] ?? 'ro';
    }

    public function rescriere(): bool
    {
        return (bool) ($this->date['rescriere'] ?? true);
    }

    /** Webroot-ul instalării (public_html). */
    public function calePublic(): string
    {
        $c = (string) ($this->date['cale_public'] ?? '');
        return $c !== '' ? rtrim($c, '/') : dirname($this->privat) . '/public_html';
    }

    /**
     * Temele private permise pe această instalare (`teme_private`, lista slugurilor; implicit niciuna). O temă cu
     * `"privata": true` în theme.json se activează doar când slugul ei e aici.
     * @return list<string>
     */
    public function temePrivate(): array
    {
        $l = $this->date['teme_private'] ?? [];
        return is_array($l) ? array_values(array_filter($l, static fn ($s): bool => is_string($s) && (bool) preg_match('~^[a-z0-9][a-z0-9\-]{0,63}$~', $s))) : [];
    }

    /** Mediul de test (servere false sau un server EasyCheck de test); pe o instalare reală lipsește. */
    public function mediuTest(): bool
    {
        return ($this->date['mediu'] ?? null) === 'test';
    }

    /**
     * Legătura cu EasyCheck: baza fixă https://easycheck.ro/api/v1 și domeniul pozelor.
     * Adresa de bază, gazdele pozelor și timeout-urile se schimbă doar în mediul de test.
     * @return array{baza:string, gazde_poze:string[], permite_http:bool, timeouts:array<string,float>}
     */
    public function easycheck(): array
    {
        $r = ['baza' => \Cazo\EasyCheck\Client::BAZA, 'gazde_poze' => [\Cazo\EasyCheck\Poze::DOMENIU], 'permite_http' => false, 'timeouts' => \Cazo\EasyCheck\Client::TIMEOUTS];
        $e = $this->date['easycheck'] ?? null;
        if (!$this->mediuTest() || !is_array($e)) {
            return $r;
        }
        if (is_string($e['baza'] ?? null) && preg_match('~^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[A-Za-z0-9._/\-]*)?$~', $e['baza'])) {
            $r['baza'] = rtrim($e['baza'], '/');
            $r['permite_http'] = str_starts_with($r['baza'], 'http://');
            $r['gazde_poze'][] = (string) parse_url($r['baza'], PHP_URL_HOST);
        }
        foreach ((array) ($e['gazde_poze'] ?? []) as $g) {
            if (is_string($g) && preg_match('~^[A-Za-z0-9.\-]{1,253}$~', $g)) {
                $r['gazde_poze'][] = strtolower($g);
            }
        }
        foreach ((array) ($e['timeouts'] ?? []) as $k => $v) {
            if (isset($r['timeouts'][$k]) && is_numeric($v) && $v > 0) {
                $r['timeouts'][$k] = (float) $v;
            }
        }
        $r['gazde_poze'] = array_values(array_unique($r['gazde_poze']));
        return $r;
    }

    /**
     * Legătura cu Stripe: baza fixă https://api.stripe.com; alta doar în mediul de test (dublura Stripe).
     * @return array{baza:string, permite_http:bool}
     */
    public function stripe(): array
    {
        $r = ['baza' => \Cazo\Plati\StripeClient::BAZA, 'permite_http' => false];
        $b = $this->date['stripe']['baza'] ?? null;
        if ($this->mediuTest() && is_string($b) && preg_match('~^https?://[A-Za-z0-9.\-]+(:\d{1,5})?$~', $b)) {
            $r = ['baza' => $b, 'permite_http' => str_starts_with($b, 'http://')];
        }
        return $r;
    }

    public function prefixBaza(): string
    {
        return (string) ($this->date['baza']['prefix'] ?? 'pg_');
    }

    /** Descrierea conexiunii, cu parola decriptată și calea SQLite absolută. */
    public function descriereBaza(Chei $chei): array
    {
        $b = $this->date['baza'] ?? [];
        if (($b['driver'] ?? '') === 'sqlite') {
            // Fără cale în config.php: cazo.sqlite, sau pragul.sqlite pe o instalare de dinainte de redenumire.
            $cale = (string) ($b['cale'] ?? (is_file($this->privat . '/pragul.sqlite') && !is_file($this->privat . '/cazo.sqlite') ? 'pragul.sqlite' : 'cazo.sqlite'));
            if ($cale !== ':memory:' && !str_starts_with($cale, '/')) {
                $cale = $this->privat . '/' . $cale;
            }
            return ['driver' => 'sqlite', 'cale' => $cale];
        }
        if (isset($b['parola_c'])) {
            $b['parola'] = (new Cutie($chei->subcheie('config')))->decripteaza((string) $b['parola_c']);
            unset($b['parola_c']);
        }
        return $b;
    }

    /** Scrie config.php atomic (folosit de instalator și de bin/cazo); instalatorul scrie întâi config-instalare.php. */
    public static function scrie(string $privat, array $date, string $fisier = 'config.php'): void
    {
        if (!in_array($fisier, ['config.php', \Cazo\Instalare\Loc::CONFIG_LUCRU], true)) {
            throw new \InvalidArgumentException('Fișier de configurare necunoscut.');
        }
        $continut = "<?php\n\n// Configurarea instalării Cazo. Fără secrete în clar.\n\ndefined('CAZO') or exit;\n\nreturn " . var_export($date, true) . ";\n";
        $tmp = $privat . '/' . $fisier . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $continut, LOCK_EX);
        \Cazo\Nucleu\Functii::chmod($tmp, 0600);
        rename($tmp, $privat . '/' . $fisier);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($privat . '/' . $fisier, true);
        }
    }

    public function __debugInfo(): array
    {
        $d = $this->date;
        if (isset($d['baza']['parola_c'])) {
            $d['baza']['parola_c'] = '***';
        }
        return ['date' => $d];
    }
}
