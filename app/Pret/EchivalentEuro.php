<?php

declare(strict_types=1);

namespace Cazo\Pret;

use Cazo\DateExterne\CursBnr;
use Cazo\I18n\Format;
use Cazo\I18n\Traducator;

defined('CAZO') or exit;

/**
 * Echivalentul orientativ în euro lângă total: oprit implicit, pornit de gazdă în panou.
 * „aproximativ 291 EUR, orientativ, la cursul BNR din 13.11.2026”: totalul în lei împărțit la cursul EUR al BNR,
 * rotunjit la euro întreg (jumătatea în sus). Prețul, plata și documentele rămân în lei (OG 21/1992 art. 20 alin. (4),
 * Regulamentul BNR 4/2005). Fără curs afișabil (lipsă sau peste 4 zile lucrătoare), echivalentul lipsește.
 */
final class EchivalentEuro
{
    public function __construct(private CursBnr $curs, private \Closure $azi)
    {
    }

    /** Suma în euro întregi pentru un total în bani, cu cursul dat (de exemplu „4.9750”). */
    public static function euro(int $bani, string $curs): int
    {
        $c = (float) $curs;
        return $c > 0 ? (int) floor($bani / 100 / $c + 0.5) : 0;
    }

    /** @return array{suma:int, data:string, curs:string}|null */
    public function pentru(int $bani): ?array
    {
        if (!$this->curs->pornit() || $bani <= 0) {
            return null;
        }
        $c = $this->curs->curs(($this->azi)());
        return $c === null ? null : ['suma' => self::euro($bani, $c['eur']), 'data' => $c['data'], 'curs' => $c['eur']];
    }

    /** Textul de lângă total, în limba paginii, sau null. */
    public function text(int $bani, Traducator $t, string $limba): ?string
    {
        $e = $this->pentru($bani);
        return $e === null ? null : $t->t('euro.echivalent', ['suma' => Format::numar($e['suma'], $limba), 'data' => Format::zi($e['data'], $limba)]);
    }
}
