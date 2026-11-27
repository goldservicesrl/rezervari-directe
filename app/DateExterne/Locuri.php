<?php

declare(strict_types=1);

namespace Cazo\DateExterne;

use Cazo\Securitate\Limitator;

defined('CAZO') or exit;

/**
 * Locurile din OpenStreetMap pentru punctele de reper (modelul de conținut), cerute doar din panou,
 * la apăsarea gazdei, niciodată de vizitatori și niciodată în timp ce gazda scrie.
 *
 *   cauta()   Nominatim, la butonul „Caută”: 1-3 rezultate în jurul proprietății. Politica Nominatim: cel mult o cerere
 *             pe secundă (limitatorul `osm:nominatim`, comun site-ului), User-Agent cu numele programului și al
 *             site-ului, fără completare automată, răspunsurile ținute 30 de zile în `date_externe`.
 *
 * Datele OpenStreetMap sunt sub licența ODbL: reperele luate de aici poartă sursa `osm`, iar site-ul afișează
 * atribuirea „© OpenStreetMap” lângă ele (Repere::ATRIBUIRE).
 */
final class Locuri
{
    public const NOMINATIM = 'https://nominatim.openstreetmap.org/search';
    private const CACHE_CAUTARE = 30 * 86400;

    public function __construct(private Depozit $depozit, private Limitator $limitator, private \Closure $http)
    {
    }

    /**
     * @return array{stare:string, rezultate?:list<array{nume:string, lat:float, lng:float, osm:string, categorie:?string}>}
     *   stare: ok, limita (o secundă între cereri), eroare
     */
    public function cauta(string $text, ?float $lat, ?float $lng, string $limba): array
    {
        $text = trim(preg_replace('~\s+~u', ' ', $text) ?? '');
        if (mb_strlen($text) < 2 || mb_strlen($text) > 120) {
            return ['stare' => 'eroare'];
        }
        $q = ['q' => $text, 'format' => 'jsonv2', 'limit' => 3, 'accept-language' => $limba === 'en' ? 'en' : 'ro', 'addressdetails' => 0];
        if ($lat !== null && $lng !== null) {
            // Rezultatele din jurul proprietății au întâietate (cutia de vizualizare, fără tăiere).
            $q['viewbox'] = sprintf('%.4f,%.4f,%.4f,%.4f', $lng - 0.3, $lat + 0.3, $lng + 0.3, $lat - 0.3);
        }
        $nume = 'osm:cauta:' . sha1(json_encode($q, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $tinut = $this->tinut($nume, self::CACHE_CAUTARE);
        if ($tinut !== null) {
            return ['stare' => 'ok', 'rezultate' => $tinut['rezultate'] ?? []];
        }
        if (!$this->limitator->incearca('osm:nominatim', 1, 1) || !$this->limitator->incearca('osm:nominatim:zi', 200, 86400)) {
            return ['stare' => 'limita'];
        }
        try {
            $r = ($this->http)()->get(self::NOMINATIM . '?' . http_build_query($q), ['Accept' => 'application/json'], 10.0);
            if ($r['status'] !== 200) {
                throw new EroareExterna('Nominatim a răspuns ' . $r['status'] . '.');
            }
            $j = json_decode($r['corp'], true);
            if (!is_array($j) || !array_is_list($j)) {
                throw new EroareExterna('Forma răspunsului Nominatim e alta.');
            }
        } catch (EroareExterna $e) {
            $this->depozit->eroare('osm:nominatim', $e->getMessage());
            return ['stare' => 'eroare'];
        }
        $rez = [];
        foreach (array_slice($j, 0, 3) as $x) {
            if (!is_array($x) || !is_numeric($x['lat'] ?? null) || !is_numeric($x['lon'] ?? null)) {
                continue;
            }
            $n = is_string($x['name'] ?? null) && $x['name'] !== '' ? $x['name'] : explode(',', (string) ($x['display_name'] ?? ''))[0];
            $osm = is_string($x['osm_type'] ?? null) && is_numeric($x['osm_id'] ?? null) ? $x['osm_type'] . '/' . $x['osm_id'] : '';
            $rez[] = ['nume' => mb_substr(trim($n), 0, 120), 'lat' => (float) $x['lat'], 'lng' => (float) $x['lon'], 'osm' => $osm,
                'categorie' => self::categorie([(string) ($x['category'] ?? '') => (string) ($x['type'] ?? '')]), 'eticheta' => mb_substr((string) ($x['display_name'] ?? ''), 0, 200)];
        }
        $this->depozit->scrie($nume, ['rezultate' => $rez]);
        return ['stare' => 'ok', 'rezultate' => $rez];
    }

    /** Categoria reperului (modelul de conținut 6.1) din etichetele OSM, sau null. */
    public static function categorie(array $tag): ?string
    {
        $v = static fn (string $k): string => (string) ($tag[$k] ?? '');
        return match (true) {
            in_array($v('amenity'), ['restaurant', 'cafe', 'bar', 'pub'], true) => 'restaurant',
            in_array($v('railway'), ['station'], true), $v('amenity') === 'bus_station', $v('aeroway') === 'aerodrome' => 'transport',
            $v('shop') !== '' => 'magazin',
            in_array($v('leisure'), ['park', 'beach_resort'], true), $v('natural') === 'beach' => 'parc',
            $v('leisure') === 'nature_reserve', in_array($v('natural'), ['peak', 'water'], true), $v('waterway') === 'waterfall' => 'natura',
            $v('tourism') !== '', $v('historic') !== '' => 'atractie',
            default => null,
        };
    }

    private function tinut(string $nume, int $durata): ?array
    {
        $d = $this->depozit->citeste($nume);
        $v = $this->depozit->varsta($nume);
        return $d !== null && $v !== null && $v < $durata ? $d['corp'] : null;
    }
}
