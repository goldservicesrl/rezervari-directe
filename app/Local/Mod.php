<?php

declare(strict_types=1);

namespace Cazo\Local;

use Cazo\Nucleu\Setari;

defined('CAZO') or exit;

/**
 * Modul site-ului: legat de EasyCheck (cheia API, datele vin din EasyCheck) sau „local”, adică fără
 * cheie: gazda completează în panou proprietatea, camerele, prețurile și calendarul, iar site-ul ține singur rezervările.
 *
 * Modul local nu e o a doua implementare a fluxurilor: clientul EasyCheck primește atunci transportul local
 * (`TransportLocal`), care răspunde din baza site-ului cu aceeași formă ca API-ul EasyCheck v1. Oglinda, prețul (la
 * „Prețul de pe site-ul meu”), comanda, plata cu cardul, cererile, transferul, anularea și temele rămân deci aceleași.
 * Cheia clientului e una fixă, a site-ului (`CHEIE`, cu prefixul `cazoloc0`), ca prefixul pus pe comenzi să spună
 * că rezervarea s-a făcut pe site, fără EasyCheck.
 */
final class Mod
{
    public const SETARE = 'site.mod';
    public const EASYCHECK = 'easycheck';
    public const LOCAL = 'local';

    /** Prefixul cheii locale: comenzile făcute fără EasyCheck îl poartă în `cheie_prefix`. */
    public const PREFIX = 'cazoloc0';
    public const CHEIE = 'ec_live_' . self::PREFIX . '_site000000000000000000000000000000';

    /** Baza adreselor clientului în modul local (nicio cerere nu pleacă în rețea). */
    public const BAZA = 'https://site.local/api/v1';

    public static function local(Setari $s): bool
    {
        return $s->get(self::SETARE) === self::LOCAL;
    }

    public static function alege(Setari $s, string $mod, ?int $utilizator = null): void
    {
        if (!in_array($mod, [self::EASYCHECK, self::LOCAL], true)) {
            throw new \InvalidArgumentException('Mod necunoscut: ' . $mod);
        }
        $s->pune(self::SETARE, $mod, $utilizator);
    }

    /** Comanda a fost făcută pe site în modul local (prefixul cheii locale). */
    public static function comandaLocala(array $c): bool
    {
        return ($c['cheie_prefix'] ?? null) === self::PREFIX;
    }
}
