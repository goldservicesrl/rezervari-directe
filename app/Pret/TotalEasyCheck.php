<?php

declare(strict_types=1);

namespace Cazo\Pret;

use Cazo\EasyCheck\Client;
use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;

defined('CAZO') or exit;

/**
 * Totalul la „Prețul din EasyCheck”: doar GET /total, fără al doilea algoritm local.
 * Cererile pleacă în paralel (curl_multi, cel mult 8 deodată); ținerea minte și bugetul le face fațada Total.
 *
 * fiecare cerere declară ce intră în total pe lângă nopți (`include_taxe`, `include_taxa_locala`, alegerea gazdei
 * din panoul site-ului); rezultatul ține alegerea trimisă (`include`), pe care comanda o repetă la POST /cereri și
 * POST /rezervari, ca totalul verificat de EasyCheck să fie cel afișat.
 */
final class TotalEasyCheck
{
    /**
     * @param array{taxe:bool, taxa_locala:bool}|null $include alegerea gazdei; null lasă valoarea de pe cheie
     * @param string|null $incasare când se încasează taxa locală (online sau la_sosire); null lasă valoarea proprietății
     */
    public function __construct(private Client $client, private ?array $include = null, private ?string $incasare = null)
    {
    }

    /** Parametrii GET /total ai alegerii. @return array<string,string> */
    public static function queryInclude(?array $include): array
    {
        return $include === null ? [] : ['include_taxe' => ($include['taxe'] ?? true) ? '1' : '0', 'include_taxa_locala' => ($include['taxa_locala'] ?? true) ? '1' : '0'];
    }

    /**
     * @param array<int|string, Sejur> $sejururi
     * @return array<int|string, array> fiecare: {stare: ok, total, moneda, componente, camere, avans, include?} | {stare: restrictie,
     *   restrictie, index} | {stare: indisponibil, motiv}
     */
    public function cere(array $sejururi): array
    {
        $cereri = [];
        foreach ($sejururi as $k => $s) {
            $cereri[$k] = ['/total', $s->query() + self::queryInclude($this->include) + ($this->incasare !== null ? ['taxa_locala_incasare' => RegulaPlata::incasare($this->incasare)] : [])];
        }
        $rez = [];
        foreach ($this->client->citesteMulte($cereri) as $k => $r) {
            $rez[$k] = self::interpreteaza($r);
            if ($rez[$k]['stare'] === 'ok' && $this->incasare !== null) {
                // regula de plată urmează alegerea declarată de site, oricare ar fi valoarea proprietății din EasyCheck.
                $rez[$k]['taxa_incasare'] = RegulaPlata::incasare($this->incasare);
                $rez[$k]['taxa_incasare_declarata'] = true;
            }
            if ($rez[$k]['stare'] === 'ok' && $this->include !== null) {
                $rez[$k]['include'] = ['taxe' => (bool) ($this->include['taxe'] ?? true), 'taxa_locala' => (bool) ($this->include['taxa_locala'] ?? true)];
            }
        }
        return $rez;
    }

    private static function interpreteaza(mixed $r): array
    {
        if ($r instanceof EroareTransport) {
            return ['stare' => 'indisponibil', 'motiv' => $r->felul];
        }
        if ($r instanceof EroareApi) {
            if ($r->cod === 'restrictie') {
                $d = $r->detalii();
                $index = is_int($d['index'] ?? null) ? $d['index'] : 0;
                unset($d['index']);
                return ['stare' => 'restrictie', 'restrictie' => $d + ['motiv' => 'restrictie'], 'index' => $index];
            }
            return ['stare' => 'indisponibil', 'motiv' => $r->cod ?? 'http_' . $r->raspuns->status];
        }
        $j = $r->json();
        if (!is_array($j) || !is_int($j['total'] ?? null) || !is_array($j['componente'] ?? null)) {
            return ['stare' => 'indisponibil', 'motiv' => 'forma'];
        }
        return [
            'stare' => 'ok',
            'total' => $j['total'],
            'moneda' => (string) ($j['moneda'] ?? 'RON'),
            'componente' => [
                'nopti' => (int) ($j['componente']['nopti'] ?? 0),
                'taxe' => is_int($j['componente']['taxe'] ?? null) ? $j['componente']['taxe'] : null,
                'taxa_locala' => is_int($j['componente']['taxa_locala'] ?? null) ? $j['componente']['taxa_locala'] : null,
            ],
            'camere' => is_array($j['camere'] ?? null) ? $j['camere'] : [],
            'avans' => is_array($j['avans'] ?? null) ? $j['avans'] : null,
            // Din EasyCheck 7.550.119: cât se poate plăti cel mult la rezervare, rotund la leu; lipsă la un EasyCheck mai vechi.
            'platibil_online' => is_int($j['platibil_online'] ?? null) ? $j['platibil_online'] : null,
            // Din EasyCheck 7.550.91: cum încasează gazda taxa locală; lipsă la un EasyCheck mai vechi (online, ca până acum).
            'taxa_incasare' => RegulaPlata::incasare($j['taxa_locala']['incasare'] ?? null),
        ];
    }
}
