<?php

declare(strict_types=1);

// Instalatorul în proces: un cont de găzduire simulat (public_html/ și cazo-privat/ cu curent.php), cererile
// trimise ca de un browser (cookie-urile și jetonul CSRF ținute între cereri), EasyCheck din fixturi, HTTP-ul
// verificărilor și al sondei dat de test.

use Cazo\Instalare\Instalator;
use Cazo\Instalare\Verificari;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Config;
use Cazo\Nucleu\Raspuns;
use Cazo\Securitate\Chei;

defined('CAZO') or exit;

final class InstalareTest
{
    public string $public;
    public string $gasit;
    /** @var array<string,string> */
    public array $cookies = [];
    public string $csrf = '';
    public ?Raspuns $ultim = null;
    public TransportTest $ec;
    /** @var list<string> adresele cerute de verificări și de sondă */
    public array $http = [];
    /** Răspunsul sondei (403/404 = protejat, 200 = expus). */
    public ?int $statusSonda = 404;
    public array $mediu;
    /** @var array<string, list<string>> înregistrările TXT văzute de pasul plăților (numele => textele) */
    public array $dns = [];
    /** Adresa IP a „browserului” (codul se numără pe conexiune). */
    public string $ip = '127.0.0.1';
    /**
     * Subdomeniul cazo.ro rezervat pentru cheie (`GET /site/subdomeniu`, contract-site-web.md, „Ce s-a construit”): null =
     * niciunul, un șir = rezervat (`dns` activ), false = un EasyCheck fără rută (404).
     */
    public string|false|null $subdomeniu = null;

    public function __construct(private Mediu $m, public string $gazda = 'cazo.test', bool $https = true, ?string $dir = null)
    {
        $dir ??= $m->dosarTemporar();
        $this->public = $dir . '/public_html';
        $this->gasit = $dir . '/cazo-privat';
        @mkdir($this->public, 0755, true);
        @mkdir($this->gasit . '/lansari', 0755, true);
        file_put_contents($this->gasit . '/curent.php', "<?php\n\ndefined('CAZO') or exit;\n\nreturn 'dev';\n");
        $this->ec = self::easycheck([], fn (): string|false|null => $this->subdomeniu);
        $this->mediu = ['https' => $https, 'php' => '8.3.0', 'ini' => ['memory_limit' => '256M', 'max_execution_time' => '30', 'upload_max_filesize' => '32M', 'post_max_size' => '32M']];
        // DNS-ul pasului plăților (SPF și DKIM): din $this->dns, fără cereri reale; null = fără răspuns.
        $this->mediu['dns'] = fn (string $nume): ?array => $this->dns[$nume] ?? null;
    }

    /** EasyCheck din fixturi: GET /eu (cheie de probă cu rezervări), PUT /webhook, proba nelivrată, resursele. */
    public static function easycheck(array $eu = [], ?\Closure $subdomeniu = null): TransportTest
    {
        $t = new TransportTest();
        $t->implicit = static function (string $metoda, string $url, array $antete = [], ?string $corp = null) use ($eu, $subdomeniu): \Cazo\EasyCheck\RaspunsEc {
            $resursa = substr((string) parse_url($url, PHP_URL_PATH), strlen('/api/v1/'));
            if ($metoda === 'GET' && $resursa === 'site/subdomeniu') {
                return FalsSite::subdomeniu($subdomeniu !== null ? $subdomeniu() : null);
            }
            // Starea site-ului (EasyCheck 7.550.120): declarația și starea, primite ca în EasyCheck.
            if ($metoda === 'PUT' && ($resursa === 'site' || $resursa === 'site/stare')) {
                $j = json_decode((string) $corp, true);
                $gresite = $resursa === 'site' ? FalsSite::declaratie($j) : FalsSite::stare($j);
                if ($gresite !== []) {
                    return TransportTest::eroare(422, 'validare', [], ['campuri' => $gresite]);
                }
                return TransportTest::raspuns(200, $resursa === 'site' ? FalsSite::declarat($j) : FalsSite::primita(time(), $j));
            }
            if ($metoda === 'PUT' && $resursa === 'webhook') {
                return TransportTest::raspuns(200, ['adresa' => 'https://cazo.test/easycheck-anunt', 'secret' => 'whsec_' . str_repeat('a1', 16), 'stare' => 'activ']);
            }
            if ($metoda === 'POST' && $resursa === 'webhook/proba') {
                return TransportTest::raspuns(200, ['id' => 'prb_1', 'livrat' => false, 'cod_http' => null]);
            }
            if ($resursa === 'webhook') {
                return TransportTest::eroare(404, 'negasit');
            }
            $corp = FalsEasyCheck::fixtura($resursa === 'eu' ? 'eu-easycheck' : $resursa);
            if ($resursa === 'eu') {
                $corp = array_replace_recursive($corp, $eu);
            }
            return TransportTest::raspuns(200, $corp, ['ETag' => '"' . substr(hash('sha256', json_encode($corp)), 0, 32) . '"']);
        };
        return $t;
    }

    public function http(): \Closure
    {
        return function (string $url): array {
            $this->http[] = $url;
            if (str_ends_with($url, '/__cazo_test')) {
                return ['status' => 200, 'corp' => Verificari::MARCAJ_RESCRIERE, 'eroare' => null];
            }
            if (str_contains($url, '/cazo-privat-')) {
                return ['status' => $this->statusSonda, 'corp' => '', 'eroare' => $this->statusSonda === null ? 'retea' : null];
            }
            return ['status' => 401, 'corp' => '{}', 'eroare' => null];
        };
    }

    public function instalator(): Instalator
    {
        $m = $this->m;
        $ec = $this->ec;
        return new Instalator($this->gasit, $this->public, $this->http(), static function (Config $c, Chei $k) use ($m, $ec): Aplicatie {
            $app = new Aplicatie($c, $k, null, $m->ceas());
            $app->transportEc = $ec;
            return $app;
        }, fn (): int => $this->m->ceas()->acum(), $this->mediu);
    }

    /** O cerere ca de la browser; urmează redirecționările locale când $urmeaza. */
    public function cere(string $metoda, string $uri, array $post = [], bool $urmeaza = true): Raspuns
    {
        // bifa licenței, ca în browser, la orice trimitere a codului; testul ei o lasă afară cu 'licenta' => null.
        if ($metoda === 'POST' && array_key_exists('cod', $post) && !array_key_exists('licenta', $post)) {
            $post['licenta'] = '1';
        }
        if (array_key_exists('licenta', $post) && $post['licenta'] === null) {
            unset($post['licenta']);
        }
        if ($metoda === 'POST' && !array_key_exists('_csrf', $post)) {
            $post['_csrf'] = $this->csrf;
        }
        $r = $this->instalator()->trateaza(Cerere::creeaza($metoda, $uri, ['Host' => $this->gazda], '', $this->cookies, $this->ip, $post));
        foreach ($r->cookies() as $k) {
            [$nume, $valoare] = explode('=', explode(';', $k, 2)[0], 2);
            if (str_contains($k, 'Max-Age=0')) {
                unset($this->cookies[$nume]);
            } else {
                $this->cookies[$nume] = $valoare;
            }
        }
        if (preg_match('~name="_csrf" value="([A-Za-z0-9_-]{43})"~', $r->corp, $x)) {
            $this->csrf = $x[1];
        }
        $this->ultim = $r;
        $loc = $r->valoareAntet('Location');
        if ($urmeaza && $loc !== null && $r->status >= 300 && $r->status < 400) {
            // Ca încărcătorul: un dosar privat redenumit se caută în public_html (varianta B), altfel cererea se oprește.
            if (!is_dir($this->gasit)) {
                $g = glob($this->public . '/cazo-privat-*', GLOB_ONLYDIR) ?: [];
                if ($g === []) {
                    return $r;
                }
                $this->gasit = $g[0];
            }
            return $this->cere('GET', $loc);
        }
        return $r;
    }

    public function privat(): string
    {
        $g = glob($this->public . '/cazo-privat-*', GLOB_ONLYDIR) ?: [];
        return $g[0] ?? $this->gasit;
    }

    public function cod(): string
    {
        $t = (string) file_get_contents($this->privat() . '/COD-INSTALARE.txt');
        return preg_match('~: ([A-Z0-9]{8})$~m', $t, $x) ? $x[1] : '';
    }

    /** Numărul unui pas, ca valoare a câmpului `pas` (Instalator::PASI: numerele vin doar de acolo). */
    public static function pas(string $nume): string
    {
        return (string) Instalator::nr($nume);
    }

    /**
     * Pașii cu valori bune, de la cod până la $pana: numele ultimului pas trimis (Instalator::PASI). „loc” e ecranul
     * locului datelor din pasul verificărilor (doar la varianta B), iar „easycheck” trimite doar cheia; „Continuați” de
     * după sincronizare pleacă odată cu pașii de după el. $suprascrieri: câmpurile schimbate, pe numele pasului.
     */
    public function instaleaza(string $limba = 'ro', string $pana = 'gata', array $suprascrieri = []): Raspuns
    {
        $this->cere('GET', '/instalare/?limba=' . $limba);
        $r = $this->cere('POST', '/instalare/', ['cod' => $this->cod()]);
        $pasi = [
            ['verificari', 'verificari', []],
            ['loc', 'verificari', ['actiune' => 'loc']],
            ['baza', 'baza', ['driver' => 'sqlite', 'prefix' => 'pg_']],
            ['easycheck', 'easycheck', ['actiune' => 'cheie', 'cheie' => FalsEasyCheck::CHEIE]],
            ['easycheck-continua', 'easycheck', ['actiune' => 'continua']],
            ['adresa', 'adresa', ['actiune' => 'continua']],
            ['legal', 'legal', ['forma' => 'srl', 'micro' => 'nu', 'legal_certificat_clasificare' => '8821', 'legal_sediu' => 'Str. Exemplului 1, Brașov', 'comenzi_zile' => '1095', 'abandonate_ore' => '72', 'rambursate_zile' => '180', 'ip_luni' => '6']],
            ['plati', 'plati', ['metoda_cash' => '1', 'anulare_gazda' => 'tot']],
            ['tema', 'tema', ['tema' => 'studio']],
            ['administrator', 'administrator', ['email' => 'gazda@cazo.test', 'nume' => 'Gazda', 'parola' => 'cal verde pe dealul cu mere', 'parola2' => 'cal verde pe dealul cu mere', 'limba_panou' => $limba]],
            ['cron', 'cron', ['actiune' => 'continua']],
            ['gata', 'gata', []],
        ];
        $ordine = array_column($pasi, 0);
        $ultim = array_search($pana, $ordine, true);
        if ($ultim === false) {
            throw new \InvalidArgumentException('Pas necunoscut: ' . $pana);
        }
        foreach ($pasi as $k => [$nume, $pas, $post]) {
            if ($k > $ultim) {
                break;
            }
            // Ecranul locului datelor apare doar la varianta B (formularul lui are acțiunea „loc”).
            if ($nume === 'loc' && !str_contains($r->corp, 'name="actiune" value="loc"')) {
                continue;
            }
            $r = $this->cere('POST', '/instalare/', ($suprascrieri[$nume] ?? []) + ['pas' => self::pas($pas)] + $post);
        }
        return $r;
    }
}
