<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

defined('CAZO') or exit;

/** Răspunsul HTTP, construit complet înainte de trimitere (antetele se aplică la final, pe profil). */
final class Raspuns
{
    /** @var array<string,array{0:string,1:string}> cheie în litere mici => [numele afișat, valoarea] */
    private array $antete = [];

    /** @var string[] valorile Set-Cookie */
    private array $cookies = [];

    /** Pagina se poate ține în cache-ul de pagini (o pagină publică, aceeași pentru toți vizitatorii). */
    public bool $deTinut = false;

    /** Un fișier trimis în flux (descărcările mari, copiile de siguranță), în locul corpului din memorie. */
    public ?string $fisier = null;

    public function __construct(public int $status = 200, public string $corp = '')
    {
    }

    public static function html(string $corp, int $status = 200): self
    {
        return (new self($status, $corp))->antet('Content-Type', 'text/html; charset=utf-8');
    }

    public static function text(string $corp, int $status = 200): self
    {
        return (new self($status, $corp))->antet('Content-Type', 'text/plain; charset=utf-8');
    }

    public static function json(mixed $date, int $status = 200): self
    {
        return (new self($status, json_encode($date, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))
            ->antet('Content-Type', 'application/json; charset=utf-8');
    }

    public static function gol(int $status = 204): self
    {
        return new self($status, '');
    }

    public static function redirectare(string $catre, int $status = 303): self
    {
        return (new self($status, ''))->antet('Location', $catre);
    }

    public function antet(string $nume, string $valoare): self
    {
        if (preg_match('~[\r\n\0]~', $nume . $valoare)) {
            throw new \InvalidArgumentException('Antet cu caractere interzise.');
        }
        $this->antete[strtolower($nume)] = [$nume, $valoare];
        return $this;
    }

    public function antetImplicit(string $nume, string $valoare): self
    {
        if (!isset($this->antete[strtolower($nume)])) {
            $this->antet($nume, $valoare);
        }
        return $this;
    }

    public function areAntet(string $nume): bool
    {
        return isset($this->antete[strtolower($nume)]);
    }

    public function valoareAntet(string $nume): ?string
    {
        return $this->antete[strtolower($nume)][1] ?? null;
    }

    public function scoateAntet(string $nume): self
    {
        unset($this->antete[strtolower($nume)]);
        return $this;
    }

    /** @return array<string,string> numele afișat => valoare */
    public function antete(): array
    {
        $r = [];
        foreach ($this->antete as [$n, $v]) {
            $r[$n] = $v;
        }
        return $r;
    }

    public function cookie(string $setCookie): self
    {
        if (preg_match('~[\r\n\0]~', $setCookie)) {
            throw new \InvalidArgumentException('Cookie cu caractere interzise.');
        }
        $this->cookies[] = $setCookie;
        return $this;
    }

    /** @return string[] */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function trimite(bool $faraCorp = false): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->antete as [$n, $v]) {
                header($n . ': ' . $v, true);
            }
            foreach ($this->cookies as $c) {
                header('Set-Cookie: ' . $c, false);
            }
            if (!$faraCorp && !$this->areAntet('Content-Length')) {
                header('Content-Length: ' . ($this->fisier !== null ? (int) @filesize($this->fisier) : strlen($this->corp)));
            }
        }
        if ($faraCorp) {
            return;
        }
        if ($this->fisier !== null) {
            $f = @fopen($this->fisier, 'rb');
            if ($f !== false) {
                while (!feof($f)) {
                    echo (string) fread($f, 1048576);
                    flush();
                }
                fclose($f);
            }
            return;
        }
        echo $this->corp;
    }
}
