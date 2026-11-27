<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

use Cazo\Baza\Baza;

defined('CAZO') or exit;

/** Setările nesecrete (tabelul `setari`), ca JSON, cu o memorie pe durata cererii. */
final class Setari
{
    private ?array $cache = null;

    public function __construct(private Baza $baza, private Ceas $ceas)
    {
    }

    public function get(string $cheie, mixed $implicit = null): mixed
    {
        $this->cache ??= $this->incarca();
        return array_key_exists($cheie, $this->cache) ? $this->cache[$cheie] : $implicit;
    }

    /** Valoarea citită acum din bază (o scrie alt proces, de exemplu anunțul primit în timpul unei așteptări). */
    public function proaspat(string $cheie, mixed $implicit = null): mixed
    {
        $v = $this->baza->valoare('SELECT valoare FROM {setari} WHERE cheie = ?', [$cheie]);
        $d = $v === null ? $implicit : json_decode((string) $v, true);
        if ($this->cache !== null && $v !== null) {
            $this->cache[$cheie] = $d;
        }
        return $d;
    }

    public function pune(string $cheie, mixed $valoare, ?int $utilizator = null): void
    {
        if ($cheie === '' || strlen($cheie) > 191) {
            throw new \InvalidArgumentException('Cheie de setare invalidă.');
        }
        $this->baza->upsert('setari', [
            'cheie' => $cheie,
            'valoare' => json_encode($valoare, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'actualizat_la' => $this->ceas->moment(),
            'actualizat_de' => $utilizator,
        ], ['cheie'], ['valoare', 'actualizat_la', 'actualizat_de']);
        if ($this->cache !== null) {
            $this->cache[$cheie] = $valoare;
        }
    }

    private function incarca(): array
    {
        $r = [];
        foreach ($this->baza->toate('SELECT cheie, valoare FROM {setari}') as $rand) {
            $r[$rand['cheie']] = json_decode((string) $rand['valoare'], true);
        }
        return $r;
    }
}
