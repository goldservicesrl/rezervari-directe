<?php

declare(strict_types=1);

namespace Cazo\Joburi;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Sarcinile periodice (tabelul `sarcini`): fiecare are un interval și o prioritate (ordinea lucrărilor de fundal)
 * (1 bani în mișcare ... 8 zilnicele). Modulele își înregistrează sarcinile la pornire.
 */
final class Planificator
{
    /** @var array<string,array{interval:int,prioritate:int,executant:callable}> */
    private array $sarcini = [];

    public function __construct(private Baza $baza, private Ceas $ceas)
    {
    }

    /** @param callable():?string $executant întoarce un rezumat scurt, pentru panou */
    public function inregistreaza(string $nume, int $intervalSecunde, int $prioritate, callable $executant): void
    {
        if (!preg_match('~^[a-z0-9_:.\-]{1,64}$~', $nume) || $intervalSecunde < 60 || $prioritate < 1 || $prioritate > 9) {
            throw new \InvalidArgumentException('Sarcină invalidă: ' . $nume);
        }
        $this->sarcini[$nume] = ['interval' => $intervalSecunde, 'prioritate' => $prioritate, 'executant' => $executant];
    }

    /** @return array<string,array{interval:int,prioritate:int}> */
    public function inregistrate(): array
    {
        return array_map(fn (array $s): array => ['interval' => $s['interval'], 'prioritate' => $s['prioritate']], $this->sarcini);
    }

    /**
     * Sarcinile scadente: cele fără rând în tabel (niciodată rulate) și cele cu urmatoarea_la trecută.
     * @return array<int,array{nume:string,prioritate:int,termen:string}>
     */
    public function scadente(): array
    {
        if ($this->sarcini === []) {
            return [];
        }
        $acum = $this->ceas->moment();
        $termene = [];
        foreach ($this->baza->toate('SELECT nume, urmatoarea_la FROM {sarcini}') as $r) {
            $termene[$r['nume']] = (string) $r['urmatoarea_la'];
        }
        $rez = [];
        foreach ($this->sarcini as $nume => $s) {
            $termen = $termene[$nume] ?? '0000-00-00 00:00:00';
            if ($termen <= $acum) {
                $rez[] = ['nume' => $nume, 'prioritate' => $s['prioritate'], 'termen' => $termen];
            }
        }
        return $rez;
    }

    /** Aduce termenul sarcinii la acum (de exemplu pozele, când oglinda primește amprente noi). */
    public function grabeste(string $nume): void
    {
        $this->baza->executa('UPDATE {sarcini} SET urmatoarea_la = ? WHERE nume = ? AND urmatoarea_la > ?', [$this->ceas->moment(), $nume, $this->ceas->moment()]);
    }

    /** @var array<string,true> sarcinile care au cerut, în rularea curentă, să fie reluate fără așteptarea intervalului */
    private array $imediat = [];

    /**
     * O sarcină cu lucru rămas (pozele de la prima sincronizare) cere reluarea fără așteptarea intervalului:
     * următorul termen rămâne acum, deci rulatorul o reia în aceeași rulare, cât are buget, și la rularea următoare.
     */
    public function reiaImediat(string $nume): void
    {
        $this->imediat[$nume] = true;
    }

    /**
     * Rulează sarcina și îi fixează următorul termen, chiar și la eșec (eșecul ajunge în `rezultat`).
     * $ramas: secundele rămase din bugetul rulării; executantul le primește ca prim argument (îl poate ignora).
     */
    public function ruleaza(string $nume, string $sursa, ?float $ramas = null): bool
    {
        $s = $this->sarcini[$nume] ?? throw new \InvalidArgumentException('Sarcină necunoscută: ' . $nume);
        $start = hrtime(true);
        $ok = true;
        unset($this->imediat[$nume]);
        try {
            $rezultat = (string) (($s['executant'])($ramas) ?? 'ok');
        } catch (\Throwable $e) {
            $ok = false;
            $rezultat = 'eroare: ' . get_class($e) . ': ' . $e->getMessage();
        }
        $durata = (int) ((hrtime(true) - $start) / 1_000_000);
        $this->baza->upsert('sarcini', [
            'nume' => $nume,
            'urmatoarea_la' => $this->ceas->moment(isset($this->imediat[$nume]) && $ok ? 0 : $s['interval']),
            'ultima_la' => $this->ceas->moment(),
            'durata_ms' => $durata,
            'rezultat' => mb_substr(\Cazo\Nucleu\Jurnal::mascheaza($rezultat), 0, 480),
            'sursa' => $sursa,
        ], ['nume'], ['urmatoarea_la', 'ultima_la', 'durata_ms', 'rezultat', 'sursa']);
        return $ok;
    }
}
