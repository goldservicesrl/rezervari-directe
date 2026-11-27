<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

defined('CAZO') or exit;

/**
 * Cererea HTTP, independentă de variabilele globale (testele o construiesc direct).
 *
 * Calea se citește în trei forme, ca site-ul să meargă cu și fără rescriere:
 *   /camere                 (cu rescriere: .htaccess trimite totul spre index.php)
 *   /?r=/camere             (fără mod_rewrite)
 *   /index.php/camere       (PATH_INFO, unde serverul îl permite)
 */
final class Cerere
{
    public const CORP_MAXIM = 65536;

    private ?string $corp;

    /**
     * Fișierele încărcate (M6b, pozele gazdei, arhiva și setările temei), doar câmpurile simple: numele câmpului =>
     * ['cale' => fișierul temporar, 'nume' => numele trimis de browser, 'marime' => octeți, 'eroare' => codul
     * UPLOAD_ERR_*, 'incarcat' => true doar pentru fișierele venite prin HTTP (is_uploaded_file)].
     * @var array<string, array{cale:string, nume?:string, marime:int, eroare:int, incarcat?:bool}>
     */
    public array $fisiere = [];

    /**
     * @param array<string,string> $antete numele în litere mici
     */
    public function __construct(
        public readonly string $metoda,
        public readonly string $cale,
        public readonly array $query,
        private array $antete,
        public readonly array $cookies,
        public readonly string $ip,
        ?string $corp = null,
        public readonly array $post = [],
        private ?\Closure $cititorCorp = null,
    ) {
        $this->corp = $corp;
    }

    /**
     * @param string[] $proxyuri rețelele proxy-urilor de încredere (config.php, `proxy_increzut`); vezi Ip::client()
     */
    public static function dinGlobale(array $proxyuri = []): self
    {
        $antete = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $antete[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $antete['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $antete['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $query = $_GET;
        $cale = self::caleDinUri($uri, $query, isset($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : null);
        unset($query['r']);
        $c = new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $cale,
            $query,
            $antete,
            $_COOKIE,
            \Cazo\Securitate\Ip::client((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $antete['x-forwarded-for'] ?? null, $proxyuri),
            null,
            $_POST,
            static fn (): string => (string) file_get_contents('php://input', false, null, 0, self::CORP_MAXIM + 1),
        );
        foreach ($_FILES as $nume => $f) {
            if (is_string($nume) && is_array($f) && is_string($f['tmp_name'] ?? null)) {
                $c->fisiere[$nume] = ['cale' => $f['tmp_name'], 'nume' => is_string($f['name'] ?? null) ? $f['name'] : '', 'marime' => (int) ($f['size'] ?? 0), 'eroare' => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE),
                    'incarcat' => $f['tmp_name'] !== '' && is_uploaded_file($f['tmp_name'])];
            }
        }
        return $c;
    }

    /** Un fișier încărcat, sau null. @return array{cale:string, nume:string, marime:int, eroare:int}|null */
    public function fisier(string $nume): ?array
    {
        return $this->fisiere[$nume] ?? null;
    }

    /** Cerere construită din părți (teste, cereri interne). */
    public static function creeaza(string $metoda, string $uri, array $antete = [], string $corp = '', array $cookies = [], string $ip = '127.0.0.1', array $post = [], array $fisiere = []): self
    {
        $q = [];
        parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $q);
        $cale = self::caleDinUri($uri, $q, null);
        unset($q['r']);
        $a = [];
        foreach ($antete as $k => $v) {
            $a[strtolower($k)] = (string) $v;
        }
        $c = new self(strtoupper($metoda), $cale, $q, $a, $cookies, $ip, $corp, $post);
        $c->fisiere = $fisiere;
        return $c;
    }

    /**
     * Calea logică a cererii. Întoarce '' când calea e invalidă (router-ul răspunde atunci 400).
     */
    public static function caleDinUri(string $uri, array $query, ?string $pathInfo): string
    {
        $cale = (string) (parse_url('http://x' . (str_starts_with($uri, '/') ? $uri : '/' . $uri), PHP_URL_PATH) ?? '/');
        $cale = rawurldecode($cale);
        if (isset($query['r']) && is_string($query['r']) && ($cale === '/' || $cale === '/index.php')) {
            $cale = $query['r'];
        } elseif ($pathInfo !== null && $pathInfo !== '' && str_starts_with($cale, '/index.php/')) {
            $cale = $pathInfo;
        } elseif (str_starts_with($cale, '/index.php/')) {
            $cale = substr($cale, strlen('/index.php'));
        } elseif ($cale === '/index.php') {
            $cale = '/';
        }
        return self::valideazaCale($cale);
    }

    private static function valideazaCale(string $cale): string
    {
        if ($cale === '' || $cale[0] !== '/' || strlen($cale) > 2000 || !mb_check_encoding($cale, 'UTF-8')
            || preg_match('~[\x00-\x1F\x7F\\\\]~', $cale) || preg_match('~(^|/)\.\.?(/|$)~', $cale)) {
            return '';
        }
        return $cale;
    }

    /**
     * Aceeași cerere, ca citire (GET), cu altă interogare și alte cookie-uri: blocurile paginilor compuse ale panoului
     * își rulează controlerul GET, fără formularul trimis.
     */
    public function caCitire(array $query, array $cookies): self
    {
        return new self('GET', $this->cale, $query, $this->antete, $cookies, $this->ip);
    }

    public function antet(string $nume): ?string
    {
        return $this->antete[strtolower($nume)] ?? null;
    }

    public function cookie(string $nume): ?string
    {
        $v = $this->cookies[$nume] ?? null;
        return is_string($v) ? $v : null;
    }

    public function camp(string $nume): ?string
    {
        $v = $this->post[$nume] ?? null;
        return is_string($v) ? $v : null;
    }

    public function param(string $nume): ?string
    {
        $v = $this->query[$nume] ?? null;
        return is_string($v) ? $v : null;
    }

    /** Corpul brut, cel mult CORP_MAXIM + 1 octeți (cine primește mai mult refuză cererea). */
    public function corp(): string
    {
        if ($this->corp === null) {
            $this->corp = $this->cititorCorp !== null ? ($this->cititorCorp)() : '';
        }
        return $this->corp;
    }

    public function esteCitire(): bool
    {
        return $this->metoda === 'GET' || $this->metoda === 'HEAD';
    }
}
