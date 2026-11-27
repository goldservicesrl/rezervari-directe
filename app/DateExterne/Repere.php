<?php

declare(strict_types=1);

namespace Cazo\DateExterne;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Punctele de reper ale gazdei (modelul de conținut 6.1): între 0 și 5, opționale; secțiunea
 * „Împrejurimi” a temelor apare de la primul reper.
 *
 * Un reper are numele, categoria, coordonatele (din căutare sau din sugestii, sursa `osm`) ori doar valoarea scrisă
 * de gazdă (sursa `gazda`), distanța și minutele pe jos. Minutele se propun din distanța în linie dreaptă × 1,3,
 * la 75 m pe minut [modelul 6.1], rotunjite la minut până la 10 și la 5 minute peste; peste 25 de minute
 * pe jos, reperul arată doar distanța în km („Aeroport, 18 km”), fără minute cu mașina. Gazda poate corecta
 * minutele reperelor apropiate.
 *
 * Temele primesc lista `repere` (nume, categorie, minute sau km, principal, sursa) și `repere_atribuire`
 * („Date despre locuri: © OpenStreetMap”), cerută de licența ODbL cât lista are un reper din OpenStreetMap.
 */
final class Repere
{
    public const MAXIM = 5;
    public const CATEGORII = ['atractie', 'restaurant', 'magazin', 'servicii', 'transport', 'parc', 'natura'];
    public const MINUTE_MAXIM = 25;
    /** Atribuirea cerută de ODbL; un nume propriu, la fel în toate limbile. */
    public const ATRIBUIRE = '© OpenStreetMap';
    public const ATRIBUIRE_URL = 'https://www.openstreetmap.org/copyright';

    public function __construct(private Baza $baza, private Ceas $ceas)
    {
    }

    /** Distanța în linie dreaptă, în metri (formula haversine). */
    public static function distanta(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return (int) round(2 * $r * asin(min(1.0, sqrt($a))));
    }

    /** Minutele pe jos propuse pentru o distanță în linie dreaptă, sau null peste 25 de minute (atunci km). */
    public static function minute(int $metri): ?int
    {
        $m = $metri * 1.3 / 75;
        if ($m > self::MINUTE_MAXIM) {
            return null;
        }
        $m = max(1, (int) round($m));
        return $m <= 10 ? $m : (int) (round($m / 5) * 5);
    }

    /** Kilometrii afișați: întregi de la 10 km, cu o zecimală sub 10 km. */
    public static function km(int $metri, string $limba): string
    {
        $km = $metri / 1000;
        return $km >= 10 ? (string) (int) round($km) : str_replace('.', $limba === 'ro' ? ',' : '.', (string) round(max(0.1, $km), 1));
    }

    /** @return list<array> reperele, în ordinea gazdei */
    public function toate(): array
    {
        $rez = [];
        foreach ($this->baza->toate('SELECT * FROM {repere} ORDER BY ordine, id') as $r) {
            $rez[] = [
                'id' => (int) $r['id'], 'nume' => (string) $r['nume'], 'categorie' => (string) $r['categorie'],
                'lat' => $r['lat_e6'] !== null ? (int) $r['lat_e6'] / 1e6 : null, 'lng' => $r['lng_e6'] !== null ? (int) $r['lng_e6'] / 1e6 : null,
                'distanta_m' => $r['distanta_m'] !== null ? (int) $r['distanta_m'] : null, 'minute' => $r['minute'] !== null ? (int) $r['minute'] : null,
                'sursa' => (string) $r['sursa'], 'osm' => $r['osm'] !== null ? (string) $r['osm'] : null, 'principal' => (int) $r['principal'] === 1,
            ];
        }
        return $rez;
    }

    /**
     * Adaugă un reper. Cu coordonate: distanța și minutele se calculează față de proprietate. Fără coordonate (gazda
     * scrie reperul): minutele pe jos sau kilometrii, scriși de gazdă.
     * @param array{nume:string, categorie:string, lat?:?float, lng?:?float, osm?:?string, minute?:?int, km?:?float} $d
     * @throws \InvalidArgumentException cu mesajul `maxim`, `nume`, `categorie`, `distanta`, `duplicat`
     */
    public function adauga(array $d, ?array $proprietate): int
    {
        if (count($this->toate()) >= self::MAXIM) {
            throw new \InvalidArgumentException('maxim');
        }
        $nume = trim(preg_replace('~\s+~u', ' ', (string) ($d['nume'] ?? '')) ?? '');
        if ($nume === '' || mb_strlen($nume) > 120) {
            throw new \InvalidArgumentException('nume');
        }
        if (!in_array($d['categorie'] ?? null, self::CATEGORII, true)) {
            throw new \InvalidArgumentException('categorie');
        }
        $osm = isset($d['osm']) && is_string($d['osm']) && preg_match('~^(node|way|relation|n|w|r)/\d{1,15}$~', $d['osm']) ? $d['osm'] : null;
        if ($osm !== null && $this->baza->valoare('SELECT 1 FROM {repere} WHERE osm = ?', [$osm]) !== null) {
            throw new \InvalidArgumentException('duplicat');
        }
        $lat = isset($d['lat']) && is_numeric($d['lat']) && abs((float) $d['lat']) <= 90 ? (float) $d['lat'] : null;
        $lng = isset($d['lng']) && is_numeric($d['lng']) && abs((float) $d['lng']) <= 180 ? (float) $d['lng'] : null;
        $distanta = null;
        $minute = null;
        if ($lat !== null && $lng !== null && $proprietate !== null) {
            $distanta = self::distanta((float) $proprietate['lat'], (float) $proprietate['lng'], $lat, $lng);
            $minute = self::minute($distanta);
        } elseif (isset($d['minute']) && is_int($d['minute']) && $d['minute'] >= 1 && $d['minute'] <= self::MINUTE_MAXIM) {
            $minute = $d['minute'];
        } elseif (isset($d['km']) && is_numeric($d['km']) && (float) $d['km'] > 0 && (float) $d['km'] <= 500) {
            $distanta = (int) round((float) $d['km'] * 1000);
        } else {
            throw new \InvalidArgumentException('distanta');
        }
        $ordine = (int) ($this->baza->valoare('SELECT MAX(ordine) FROM {repere}') ?? 0) + 1;
        return $this->baza->insereaza('repere', [
            'nume' => $nume, 'categorie' => $d['categorie'],
            'lat_e6' => $lat !== null && $lng !== null ? (int) round($lat * 1e6) : null, 'lng_e6' => $lat !== null && $lng !== null ? (int) round($lng * 1e6) : null,
            'distanta_m' => $distanta, 'minute' => $minute, 'sursa' => $osm !== null ? 'osm' : 'gazda', 'osm' => $osm,
            'principal' => 0, 'ordine' => $ordine, 'creat_la' => $this->ceas->moment(),
        ]);
    }

    /** Gazda corectează minutele pe jos ale unui reper apropiat. */
    public function minuteGazda(int $id, int $minute): void
    {
        if ($minute < 1 || $minute > self::MINUTE_MAXIM) {
            throw new \InvalidArgumentException('distanta');
        }
        $this->baza->executa('UPDATE {repere} SET minute = ? WHERE id = ?', [$minute, $id]);
    }

    /** Reperul principal: unul singur; același id a doua oară îl scoate. */
    public function principal(int $id): void
    {
        $era = (int) ($this->baza->valoare('SELECT principal FROM {repere} WHERE id = ?', [$id]) ?? 0) === 1;
        $this->baza->executa('UPDATE {repere} SET principal = 0');
        if (!$era) {
            $this->baza->executa('UPDATE {repere} SET principal = 1 WHERE id = ?', [$id]);
        }
    }

    public function sterge(int $id): void
    {
        $this->baza->executa('DELETE FROM {repere} WHERE id = ?', [$id]);
    }

    /**
     * Lista pentru teme (modelul de vizualizare): nume, categorie, minute (pe jos, apropiate) sau km (îndepărtate),
     * principal, sursa. Reperul principal ales de gazdă vine primul (observația operatorului.2026), restul în ordinea
     * gazdei. @return list<array>
     */
    public function pentruTema(string $limba): array
    {
        $toate = array_slice($this->toate(), 0, self::MAXIM);
        usort($toate, static fn (array $a, array $b): int => (int) $b['principal'] <=> (int) $a['principal']);
        $rez = [];
        foreach ($toate as $r) {
            $km = $r['minute'] === null && $r['distanta_m'] !== null ? self::km($r['distanta_m'], $limba) : null;
            $rez[] = ['nume' => $r['nume'], 'categorie' => $r['categorie'], 'minute' => $r['minute'], 'km' => $km, 'mod' => $r['minute'] !== null ? 'pe_jos' : null,
                'principal' => $r['principal'], 'sursa' => $r['sursa']];
        }
        return $rez;
    }

    public function auOsm(): bool
    {
        return $this->baza->valoare("SELECT 1 FROM {repere} WHERE sursa = 'osm' LIMIT 1") !== null;
    }
}
