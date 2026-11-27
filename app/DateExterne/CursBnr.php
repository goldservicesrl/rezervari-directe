<?php

declare(strict_types=1);

namespace Cazo\DateExterne;

use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Setari;

defined('CAZO') or exit;

/**
 * Cursul BNR pentru echivalentul orientativ în euro. Fișierul public
 * nbrfxrates.xml al Băncii Naționale, citit de jobul `date_externe` doar cât gazda are echivalentul pornit: o dată
 * pe zi lucrătoare, după ora 13:30 (ora publicării), cu reîncercare la o oră după o eroare. Se păstrează data
 * cursului și valoarea EUR, așa cum apar în fișier.
 *
 * Echivalentul dispare când cursul are peste 4 zile lucrătoare și revine singur cu cursul nou. Zilele
 * lucrătoare sunt zilele de luni până vineri, fără sărbătorile legale (Codul muncii, art. 139), inclusiv Paștele
 * și Rusaliile ortodoxe.
 */
final class CursBnr
{
    public const NUME = 'curs_bnr';
    public const ADRESA = 'https://www.bnr.ro/nbrfxrates.xml';
    public const ZILE_MAXIM = 4;
    /** Sărbătorile legale cu dată fixă (LL-ZZ). */
    private const FIXE = ['01-01', '01-02', '01-06', '01-07', '01-24', '05-01', '06-01', '08-15', '11-30', '12-01', '12-25', '12-26'];

    public function __construct(private Depozit $depozit, private Setari $setari, private Ceas $ceas, private \Closure $http)
    {
    }

    public function pornit(): bool
    {
        return (bool) $this->setari->get('euro.pornit', false);
    }

    /**
     * Citirea e scadentă: fără curs; sau cursul e mai vechi decât ziua de azi, azi e zi lucrătoare și a trecut ora 13:30
     * (ora României); după o eroare, cel mult o încercare pe oră.
     */
    public function scadenta(string $azi, string $ora): bool
    {
        $d = $this->depozit->citeste(self::NUME);
        if (($d['eroare_la'] ?? null) !== null && $this->ceas->acum() - Ceas::dinMoment((string) $d['eroare_la']) < 3600) {
            return false;
        }
        $data = $d['corp']['data'] ?? null;
        if (!is_string($data)) {
            return true;
        }
        if ($data >= $azi) {
            return false;
        }
        // Cursul de azi apare după 13:30; înainte, doar un curs mai vechi de ziua lucrătoare trecută cere o citire.
        $deCitit = (self::lucratoare($azi) && $ora >= '13:30') || self::zileLucratoare($data, $azi) > (self::lucratoare($azi) ? 1 : 0);
        if (!$deCitit) {
            return false;
        }
        // O citire reușită cu același curs (BNR încă n-a publicat): următoarea încercare peste o oră.
        $citit = $d['citit_la'] ?? null;
        return $citit === null || $this->ceas->acum() - Ceas::dinMoment($citit) >= 3600;
    }

    /** Citește fișierul BNR; întoarce true când cursul s-a schimbat. */
    public function citeste(): bool
    {
        try {
            $r = ($this->http)()->get(self::ADRESA, ['Accept' => 'application/xml'], 10.0);
            if ($r['status'] !== 200) {
                throw new EroareExterna('BNR a răspuns ' . $r['status'] . '.');
            }
            $corp = self::interpreteaza($r['corp']);
        } catch (EroareExterna $e) {
            $this->depozit->eroare(self::NUME, $e->getMessage());
            return false;
        }
        return $this->depozit->scrie(self::NUME, $corp);
    }

    /**
     * Data cursului și valoarea EUR din nbrfxrates.xml (`<Cube date="...">`, `<Rate currency="EUR">`), fără
     * încărcarea unui parser XML (fișierul are o formă fixă și publică).
     * @return array{data:string, eur:string}
     * @throws EroareExterna
     */
    public static function interpreteaza(string $xml): array
    {
        if (!preg_match('~<Cube\s+date="(\d{4}-\d{2}-\d{2})"\s*>~', $xml, $d) || !preg_match('~<Rate\s+currency="EUR"\s*>\s*(\d{1,2}\.\d{1,6})\s*</Rate>~', $xml, $e)) {
            throw new EroareExterna('Forma fișierului BNR e alta.');
        }
        if ((float) $e[1] <= 0) {
            throw new EroareExterna('Cursul EUR din fișierul BNR e greșit.');
        }
        return ['data' => $d[1], 'eur' => $e[1]];
    }

    /** Cursul afișabil (data și valoarea), sau null când lipsește ori are peste 4 zile lucrătoare. */
    public function curs(string $azi): ?array
    {
        $c = $this->depozit->citeste(self::NUME)['corp'] ?? null;
        if (!is_array($c) || !is_string($c['data'] ?? null) || !is_string($c['eur'] ?? null)) {
            return null;
        }
        return self::zileLucratoare($c['data'], $azi) > self::ZILE_MAXIM ? null : $c;
    }

    /** Zilele lucrătoare de după data cursului, până azi inclusiv. */
    public static function zileLucratoare(string $de, string $azi): int
    {
        $n = 0;
        for ($t = strtotime($de . ' 00:00:00 UTC') + 86400, $f = strtotime($azi . ' 00:00:00 UTC'); $t <= $f && $n <= 60; $t += 86400) {
            if (self::lucratoare(gmdate('Y-m-d', $t))) {
                $n++;
            }
        }
        return $n;
    }

    public static function lucratoare(string $zi): bool
    {
        $t = strtotime($zi . ' 00:00:00 UTC');
        if ((int) gmdate('N', $t) >= 6 || in_array(substr($zi, 5), self::FIXE, true)) {
            return false;
        }
        $pasti = self::pastiOrtodox((int) substr($zi, 0, 4));
        foreach ([-2, 0, 1, 49, 50] as $d) {
            if (gmdate('Y-m-d', $pasti + $d * 86400) === $zi) {
                return false;
            }
        }
        return true;
    }

    /** Paștele ortodox (algoritmul lui Meeus, calendarul iulian, plus 13 zile), ca moment UTC la miezul nopții. */
    public static function pastiOrtodox(int $an): int
    {
        $a = $an % 4;
        $b = $an % 7;
        $c = $an % 19;
        $d = (19 * $c + 15) % 30;
        $e = (2 * $a + 4 * $b - $d + 34) % 7;
        $luna = intdiv($d + $e + 114, 31);
        $zi = (($d + $e + 114) % 31) + 1;
        return strtotime(sprintf('%04d-%02d-%02d 00:00:00 UTC', $an, $luna, $zi)) + 13 * 86400;
    }
}
