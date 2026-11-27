<?php

declare(strict_types=1);

namespace Cazo\Sistem;

use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Config;
use Cazo\Nucleu\Functii;
use Cazo\Nucleu\Https;

defined('CAZO') or exit;

/**
 * „Adresa site-ului”: domeniul gazdei sau subdomeniul
 * gratuit `<nume>.cazo.ro`, doar cu cheie EasyCheck (Pro), unul pe cheie, rezervat în EasyCheck › Site web, nu aici.
 *
 *  - `rezervat()`: subdomeniul rezervat pentru cheia de acum, din `GET /site/subdomeniu` (`SiteSubdomeniu`: `nume`, `adresa`,
 *    `dns` creat | in_asteptare; contractul, „Ce s-a construit”); un 403 (planul fără Site Web / Acces API) sau 404: necunoscut;
 *  - instalatorul (pasul „Adresa site-ului”) recunoaște un site care rulează deja pe `*.cazo.ro` și îl leagă când e
 *    rezervat pentru cheie; pe domeniul propriu verifică doar că adresa din config se potrivește cu cea la care rulează;
 *  - trecerea din Setări pe subdomeniul rezervat: site-ul încearcă singur să adauge domeniul în cPanel (`Cpanel`), altfel
 *    gazda face pașii de mână; apoi verifică singur, la fiecare rulare a cronului și la „Verificați acum”, că adresa nouă
 *    răspunde pe HTTPS cu jetonul acestei instalări (`/.well-known/cazo-adresa`), și trece: config.php primește adresa
 *    nouă, iar adresa veche face 301 spre cea nouă (fișierul-martor `cache/adresa-veche`, citit înaintea aplicației).
 */
final class Adresa
{
    public const DOMENIU_CAZO = 'cazo.ro';
    /** EasyCheck › Site web: acolo se rezervă subdomeniul (la cheie) și se pune IP-ul serverului. */
    public const EASYCHECK_SITE_WEB = 'https://easycheck.ro/host/site.php';
    public const SETARE = 'site.adresa';
    public const MARTOR = 'cache/adresa-veche';
    public const SONDA = '/.well-known/cazo-adresa';
    /** Cât încearcă singur site-ul să găsească adresa nouă, după pornirea trecerii. */
    public const ASTEPTARE = 7 * 86400;

    /** @var ?\Closure(string): array{status:?int, corp:string, eroare:?string} cererile HTTP (testele dau răspunsurile) */
    public ?\Closure $http = null;
    public ?Cpanel $cpanel = null;
    /** Dosarul contului de găzduire (testele îl dau); null = HOME sau dosarul utilizatorului. */
    public ?string $acasa = null;

    public function __construct(private Aplicatie $app)
    {
    }

    /** Numele subdomeniului când gazda e `<nume>.cazo.ro` (3-40 de caractere: litere mici, cifre, cratimă), altfel null. */
    public static function numeCazo(string $gazda): ?string
    {
        $g = strtolower(rtrim(preg_replace('~:\d+$~', '', $gazda) ?? '', '.'));
        $sufix = '.' . self::DOMENIU_CAZO;
        if (!str_ends_with($g, $sufix)) {
            return null;
        }
        $n = substr($g, 0, -strlen($sufix));
        return preg_match('~^[a-z0-9](?:[a-z0-9-]{1,38})[a-z0-9]$~D', $n) && !str_contains($n, '--') ? $n : null;
    }

    public static function gazda(string $origine): string
    {
        return strtolower((string) parse_url($origine, PHP_URL_HOST));
    }

    /** Adresa site-ului din config.php. */
    public function curenta(): string
    {
        return $this->app->config()->origine();
    }

    /** Cu cheie EasyCheck (subdomeniul gratuit cere cheia). */
    public function cuCheie(): bool
    {
        return !$this->app->modLocal() && $this->app->secrete()->citeste('ec_cheie') !== null;
    }

    /**
     * Subdomeniul rezervat în EasyCheck pentru cheia de acum. `stare`: rezervat (cu `domeniu`, `nume`, `dns` activ |
     * in_asteptare), lipsa (cheia n-are subdomeniu), necunoscut (EasyCheck fără rută, fără rețea, fără cheie).
     * @return array{stare:string, domeniu?:string, nume?:string, dns?:string}
     */
    public function rezervat(): array
    {
        if (!$this->cuCheie()) {
            return ['stare' => 'necunoscut'];
        }
        try {
            $j = $this->app->ecClient()->citeste('/site/subdomeniu')->json() ?? [];
        } catch (EroareApi | EroareTransport | \UnexpectedValueException) {
            return ['stare' => 'necunoscut'];
        }
        if (!array_key_exists('subdomeniu', $j)) {
            return ['stare' => 'necunoscut'];
        }
        $s = $j['subdomeniu'];
        if (!is_array($s)) {
            return ['stare' => 'lipsa'];
        }
        $nume = is_string($s['nume'] ?? null) ? strtolower($s['nume']) : null;
        $domeniu = is_string($s['adresa'] ?? null) ? strtolower($s['adresa']) : ($nume !== null ? $nume . '.' . self::DOMENIU_CAZO : '');
        if (self::numeCazo($domeniu) === null || ($nume !== null && self::numeCazo($domeniu) !== $nume)) {
            return ['stare' => 'necunoscut'];
        }
        return ['stare' => 'rezervat', 'domeniu' => $domeniu, 'nume' => (string) self::numeCazo($domeniu), 'dns' => ($s['dns'] ?? null) === 'creat' ? 'activ' : 'in_asteptare'];
    }

    /** Alegerea ținută: `fel` domeniu | cazo; `trecere` cât o trecere e în curs; `vechi` adresele de dinainte. */
    public function tinuta(): array
    {
        $s = $this->app->setari()->get(self::SETARE);
        return is_array($s) ? $s : [];
    }

    private function tine(array $s): void
    {
        $this->app->setari()->pune(self::SETARE, $s);
    }

    public function alege(string $fel, ?string $domeniu = null): void
    {
        $s = $this->tinuta();
        $s['fel'] = $fel === 'cazo' ? 'cazo' : 'domeniu';
        $s['domeniu'] = $domeniu ?? self::gazda($this->curenta());
        $this->tine($s);
    }

    // ---- trecerea pe subdomeniul rezervat ----

    /** Trecerea în curs, sau null. @return ?array{tinta:string, domeniu:string, jeton:string, inceput_la:int, cpanel:string, verificat_la:?int, rezultat:?string} */
    public function trecere(): ?array
    {
        $t = $this->tinuta()['trecere'] ?? null;
        return is_array($t) && is_string($t['tinta'] ?? null) ? $t : null;
    }

    /** Dosarul web al site-ului, relativ la dosarul contului (pentru cPanel), sau null când stă în afara lui. */
    public function dosarRelativ(): ?string
    {
        $acasa = $this->acasa ?? (getenv('HOME') ?: (function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '') : ''));
        $acasa = rtrim(str_replace('\\', '/', (string) $acasa), '/');
        $public = rtrim(str_replace('\\', '/', $this->app->config()->calePublic()), '/');
        if ($acasa === '' || !str_starts_with($public, $acasa . '/')) {
            return null;
        }
        return substr($public, strlen($acasa) + 1);
    }

    public function cpanel(): Cpanel
    {
        return $this->cpanel ??= new Cpanel();
    }

    /**
     * Pornește trecerea pe $domeniu (subdomeniul rezervat pentru cheie): jetonul instalării, apoi încercarea din cPanel.
     * @return array{cpanel:string} cpanel: adaugat | exista | manual (fără cale automată) | refuzat | eroare
     */
    public function pornesteTrecerea(string $domeniu, ?int $utilizator = null): array
    {
        $nume = self::numeCazo($domeniu);
        if ($nume === null) {
            throw new \InvalidArgumentException('domeniu');
        }
        $cp = $this->cpanel();
        $dir = $this->dosarRelativ();
        $rez = 'manual';
        if ($dir !== null && $cp->disponibil()) {
            $r = $cp->adaugaDomeniul($domeniu, 'cazo' . str_replace('-', '', $nume), $dir);
            $rez = $r['ok'] ? $r['motiv'] : ($r['motiv'] === 'indisponibil' ? 'manual' : $r['motiv']);
            if ($r['ok']) {
                $cp->pornesteAutoSsl();
            }
        }
        $s = $this->tinuta();
        $s['trecere'] = ['tinta' => 'https://' . $domeniu, 'domeniu' => $domeniu, 'jeton' => bin2hex(random_bytes(16)), 'inceput_la' => $this->app->ceas()->acum(),
            'cpanel' => $rez, 'verificat_la' => null, 'rezultat' => null];
        $this->tine($s);
        $this->app->jurnal()->info('Trecerea site-ului pe subdomeniul rezervat a pornit', ['domeniu' => $domeniu, 'cpanel' => $rez, 'utilizator' => $utilizator]);
        return ['cpanel' => $rez];
    }

    public function anuleaza(): void
    {
        $s = $this->tinuta();
        unset($s['trecere']);
        $this->tine($s);
    }

    /** Răspunsul sondei de pe adresa nouă: jetonul trecerii în curs, altfel null (404). */
    public function jetonSonda(): ?string
    {
        return $this->trecere()['jeton'] ?? null;
    }

    /**
     * Verifică adresa nouă: răspunde pe HTTPS, cu certificat bun, chiar această instalare (jetonul). Când da, trece.
     * @return string trecut | asteapta (fără răspuns încă: DNS, domeniul în cPanel) | tls (certificatul lipsește încă) | alt_site | fara_trecere
     */
    public function verifica(): string
    {
        $t = $this->trecere();
        if ($t === null) {
            return 'fara_trecere';
        }
        $http = $this->http ?? \Cazo\Instalare\Http::curl(10);
        $r = $http($t['tinta'] . self::SONDA);
        $rezultat = match (true) {
            $r['status'] === 200 && hash_equals($t['jeton'], trim($r['corp'])) => 'trecut',
            $r['eroare'] === 'tls' => 'tls',
            $r['status'] !== null => 'alt_site',
            default => 'asteapta',
        };
        if ($rezultat === 'trecut') {
            $this->treci($t);
            return 'trecut';
        }
        $s = $this->tinuta();
        $s['trecere']['verificat_la'] = $this->app->ceas()->acum();
        $s['trecere']['rezultat'] = $rezultat;
        $this->tine($s);
        return $rezultat;
    }

    /** Sarcina cronului: cât o trecere e în curs (cel mult ASTEPTARE), verifică adresa nouă. */
    public function sarcina(): string
    {
        $s = $this->tinuta();
        if (($s['de_anuntat'] ?? false) === true && self::gazda($this->curenta()) === ($s['domeniu'] ?? null)) {
            // După trecere, cu config.php nou: adresa anunțurilor și a panoului ajunge la EasyCheck.
            unset($s['de_anuntat']);
            $this->tine($s);
            try {
                $this->app->ecConectare()->inregistreazaAnunturile();
            } catch (\Throwable) {
            }
            $this->app->stareSite()->declara();
            return 'adresa nouă anunțată la EasyCheck';
        }
        $t = $this->trecere();
        if ($t === null) {
            return 'nicio trecere în curs';
        }
        if ($this->app->ceas()->acum() - (int) $t['inceput_la'] > self::ASTEPTARE) {
            return 'trecerea așteaptă „Verificați acum”';
        }
        return 'adresa nouă: ' . $this->verifica();
    }

    /** Trecerea: config.php cu adresa nouă, martorul redirecționării 301 și HTTPS-ul verificat pe adresa nouă. */
    private function treci(array $t): void
    {
        $privat = $this->app->privat();
        $vechi = self::gazda($this->curenta());
        $cfg = $this->app->config()->toate();
        $cfg['site_url'] = $t['tinta'];
        Config::scrie($privat, $cfg);
        $s = $this->tinuta();
        $lista = array_values(array_unique(array_filter(array_merge((array) ($s['vechi'] ?? []), [$vechi, 'www.' . preg_replace('~^www\.~', '', $vechi)]),
            static fn (mixed $g): bool => is_string($g) && $g !== '' && $g !== $t['domeniu'])));
        self::scrieMartor($privat . '/' . self::MARTOR, json_encode(['vechi' => $lista, 'noua' => $t['tinta']], JSON_UNESCAPED_SLASHES));
        // Sonda a mers pe HTTPS cu certificat verificat, pe adresa nouă: redirecționarea http → https merge de acum spre ea.
        self::scrieMartor($privat . '/' . Https::FISIER, $t['tinta']);
        unset($s['trecere']);
        $s['fel'] = 'cazo';
        $s['domeniu'] = $t['domeniu'];
        $s['vechi'] = $lista;
        $s['trecut_la'] = $this->app->ceas()->acum();
        $s['de_la'] = 'https://' . $vechi;
        $s['de_anuntat'] = !$this->app->modLocal();
        $this->tine($s);
        $this->app->jurnal()->info('Site-ul a trecut pe adresa nouă', ['de_la' => $vechi, 'pe' => $t['domeniu']]);
        try {
            $this->app->ecOglinda()->schimbaContinutul();
        } catch (\Throwable) {
        }
    }

    private static function scrieMartor(string $f, string $continut): void
    {
        if (!is_dir(dirname($f))) {
            @mkdir(dirname($f), 0700, true);
        }
        $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $continut, LOCK_EX) !== false) {
            Functii::chmod($tmp, 0600);
            @rename($tmp, $f);
        }
    }

    /**
     * Ținta redirecționării 301 a unei adrese vechi (înaintea aplicației, din app/cache-pagini.php): doar GET și HEAD, doar
     * pe o gazdă din lista adreselor vechi, cu aceeași cale; anunțurile EasyCheck și Stripe, cronul extern și sonda rămân pe loc.
     */
    public static function tinta(string $privat, array $server): ?string
    {
        $m = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($m !== 'GET' && $m !== 'HEAD') {
            return null;
        }
        $j = @file_get_contents($privat . '/' . self::MARTOR);
        $j = is_string($j) ? json_decode($j, true) : null;
        if (!is_array($j) || !is_string($j['noua'] ?? null) || !preg_match('~^https://[a-z0-9.\-]+$~D', $j['noua'])) {
            return null;
        }
        $gazda = strtolower((string) preg_replace('~:\d+$~', '', (string) ($server['HTTP_HOST'] ?? '')));
        if ($gazda === '' || !in_array($gazda, (array) ($j['vechi'] ?? []), true) || $gazda === self::gazda($j['noua'])) {
            return null;
        }
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        if ($uri === '' || $uri[0] !== '/' || str_starts_with($uri, '//')) {
            $uri = '/';
        }
        $cale = (string) parse_url($uri, PHP_URL_PATH);
        if (in_array($cale, ['/easycheck-anunt', '/stripe-anunt', self::SONDA], true) || str_starts_with($cale, '/_cron/')) {
            return null;
        }
        return $j['noua'] . $uri;
    }

    public static function redirectioneaza(string $privat, array $server): bool
    {
        $t = self::tinta($privat, $server);
        if ($t === null || headers_sent()) {
            return false;
        }
        http_response_code(301);
        header('Location: ' . $t);
        header('Cache-Control: max-age=3600');
        header('Content-Type: text/plain; charset=utf-8');
        return true;
    }
}
