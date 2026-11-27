<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

defined('CAZO') or exit;

/**
 * O rută din tabelul static.
 *
 * profil: setul de antete și CSP (vezi Antete): public, rezervare, admin, tehnic, svg.
 * csrf: obligatoriu pe POST; o excepție (anunțurile semnate, rapoartele CSP trimise de browser)
 *       cere motivul scris în $faraCsrf, verificat de testul de securitate.
 * limbi: ruta există și sub prefixul /en.
 * limba: ruta există doar în limba dată (căile traduse: /camere în română, /en/rooms în engleză).
 */
final class Ruta
{
    public readonly string $regex;

    /** @var string[] */
    public readonly array $parametri;

    /**
     * @param array{0:class-string,1:string}|\Closure $actiune
     */
    public function __construct(
        public readonly string $metoda,
        public readonly string $model,
        public readonly array|\Closure $actiune,
        public readonly string $profil = 'public',
        public readonly bool $limbi = false,
        public readonly ?string $faraCsrf = null,
        public readonly string $nume = '',
        public readonly ?string $limba = null,
    ) {
        if (!in_array($metoda, ['GET', 'POST'], true)) {
            throw new \InvalidArgumentException('Metodă de rută nepermisă: ' . $metoda);
        }
        if (!in_array($profil, Antete::PROFILURI, true)) {
            throw new \InvalidArgumentException('Profil de antete necunoscut: ' . $profil);
        }
        $parametri = [];
        $regex = preg_replace_callback('~\{([a-z_]+)(?::([^{}]+(?:\{\d+(?:,\d*)?\}[^{}]*)*))?\}~', function (array $m) use (&$parametri): string {
            $parametri[] = $m[1];
            return '(?P<' . $m[1] . '>' . (($m[2] ?? '') !== '' ? $m[2] : '[^/]+') . ')';
        }, $model);
        $this->regex = '~^' . $regex . '$~u';
        $this->parametri = $parametri;
    }

    public function cereCsrf(): bool
    {
        return $this->metoda === 'POST' && $this->faraCsrf === null;
    }

    /** @return array<string,string>|null */
    public function potriveste(string $cale): ?array
    {
        if (!preg_match($this->regex, $cale, $m)) {
            return null;
        }
        $p = [];
        foreach ($this->parametri as $n) {
            $p[$n] = $m[$n];
        }
        return $p;
    }
}
