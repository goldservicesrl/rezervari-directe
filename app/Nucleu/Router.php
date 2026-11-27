<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

defined('CAZO') or exit;

/**
 * Router-ul cu tabel static de rute. Limba se citește din prefix: RO la rădăcină, EN sub /en/.
 * Rezultatul: ['ruta' => Ruta, 'parametri' => [...], 'limba' => 'ro'] sau ['status' => 400|404|405, 'limba' => ...].
 */
final class Router
{
    /** @param Ruta[] $rute */
    public function __construct(private array $rute, private array $limbi = ['ro', 'en'], private string $limbaImplicita = 'ro')
    {
    }

    /** @return Ruta[] */
    public function rute(): array
    {
        return $this->rute;
    }

    public function rezolva(Cerere $c): array
    {
        $cale = $c->cale;
        if ($cale === '') {
            return ['status' => 400, 'limba' => $this->limbaImplicita];
        }
        [$limba, $rest, $prefixat] = $this->separaLimba($cale);
        $metoda = $c->metoda === 'HEAD' ? 'GET' : $c->metoda;
        $metodePermise = [];
        foreach ($this->rute as $r) {
            if (($prefixat && !$r->limbi) || ($r->limba !== null && $r->limba !== $limba)) {
                continue;
            }
            $p = $r->potriveste($rest);
            if ($p === null) {
                continue;
            }
            if ($r->metoda !== $metoda) {
                $metodePermise[] = $r->metoda;
                continue;
            }
            return ['ruta' => $r, 'parametri' => $p, 'limba' => $limba];
        }
        if ($metodePermise !== []) {
            return ['status' => 405, 'limba' => $limba, 'permise' => array_values(array_unique($metodePermise))];
        }
        return ['status' => 404, 'limba' => $limba];
    }

    /** @return array{0:string,1:string,2:bool} [limba, calea fără prefix, avea prefix] */
    public function separaLimba(string $cale): array
    {
        foreach ($this->limbi as $l) {
            if ($l === $this->limbaImplicita) {
                continue;
            }
            if ($cale === '/' . $l || $cale === '/' . $l . '/') {
                return [$l, '/', true];
            }
            if (str_starts_with($cale, '/' . $l . '/')) {
                return [$l, substr($cale, strlen($l) + 1), true];
            }
        }
        return [$this->limbaImplicita, $cale, false];
    }
}
