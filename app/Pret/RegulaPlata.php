<?php

declare(strict_types=1);

namespace Cazo\Pret;

defined('CAZO') or exit;

/**
 * Regula de plată a unei rezervări: avansul planului rezervării (unul singur), din
 * câmpurile EasyCheck. La „Prețul din EasyCheck”, obiectul `avans` din GET /total (`procent`, `suma`, `anulare_zile`,
 * `regula`); la „Prețul de pe site-ul meu”, `avans_procent` al planului din GET /preturi, aplicat pe totalul local și
 * rotunjit la cel mai apropiat ban, ca EasyCheck (`round(total × procent / 100)`).
 *
 * Suma plătită la rezervare e rotundă la leu (EasyCheck 7.550.119, decizia proprietarului „rotunjește și avansul”):
 * la „Prețul din EasyCheck”, softul arată și trimite exact sumele din GET /total: `platibil_online` (cât se poate plăti cel
 * mult la rezervare: totalul, iar cu taxa locală la sosire totalul fără ea, rotund la leu) și `avans.suma` (procentul din
 * `platibil_online`, rotund la leu); restul de la sosire e totalul minus ce s-a plătit. La „Prețul de pe site-ul meu”,
 * aceleași două rotunjiri se fac aici (`platibil`, `avansRotund`), pe totalul local, rotund și el (MotorLocal). Un
 * EasyCheck mai vechi, fără `platibil_online`, rămâne pe calculul de până atunci, la ban.
 *
 * Oaspetele alege între „Plătesc avansul” și „Plătesc tot acum” când avansul e între 1 și 99%. La 100%, doar
 * plata întreagă. La 0%, rezervarea pleacă drept cerere cu plata la sosire, fără plată online (ModPlata::pentru),
 * deci regula de aici nu se folosește. Fără plan și fără avans cunoscut, plata întreagă.
 *
 * Taxa locală: apare mereu în total. Gazda alege în EasyCheck cum o încasează la rezervările de pe site
 * (`taxa_locala.incasare`): „online” (plata de pe site o cuprinde întreagă: avansul e cel puțin taxa) sau „la_sosire”
 * (avansul și plata întreagă se calculează fără ea, iar taxa trece în restul de la sosire).
 *
 * Avansul minim: la „online”, avansul e procentul planului din total, dar cel puțin taxa locală
 * (la sumele rotunde, leul întreg de deasupra ei): `avans_minim`.
 *
 * Reținerea după termen (revizuită 23.11.2026): gazda păstrează avansul
 * plătit la rezervare, ca avans al cazării (nu ca taxă), cel mult cât s-a plătit; tot ce s-a plătit peste el se
 * returnează, cu taxa locală. Avansul cazării nu trece de prețul cazării: la avansul 100% (sau unul peste prețul
 * cazării) se păstrează prețul cazării, iar taxa locală plătită online se returnează (`retinere`).
 */
final class RegulaPlata
{
    /** Cum încasează gazda taxa locală la rezervările de pe site (GET /preturi și GET /total, `taxa_locala.incasare`). */
    public const TAXA_ONLINE = 'online';
    public const TAXA_LA_SOSIRE = 'la_sosire';

    /** Alegerea gazdei din API; lipsă (un EasyCheck mai vechi) sau necunoscută: online, ca până acum. */
    public static function incasare(mixed $v): string
    {
        return $v === self::TAXA_LA_SOSIRE ? self::TAXA_LA_SOSIRE : self::TAXA_ONLINE;
    }

    /** Taxa locală din componentele totalului, în bani (0 când lipsește). */
    public static function taxa(array $componente): int
    {
        return max(0, (int) ($componente['taxa_locala'] ?? 0));
    }

    /**
     * @param array|null $plan rândul planului (avans_procent, anulare_zile, tip)
     * @param array|null $avansEc obiectul `avans` din GET /total
     * @param int $taxa taxa locală din total, în bani
     * @param string $incasare cum o încasează gazda: online (în plata de pe site) sau la_sosire (în afara ei)
     * @param ?int $platibil `platibil_online` din GET /total; null la un EasyCheck mai vechi și la motorul local
     * @param bool $rotund fără `platibil_online`: sumele plătite la rezervare se rotunjesc aici la leu (motorul local)
     * @return array{avans_procent:int, avans:int, rest_avans:int, integral:int, taxa:int, incasare:string, taxa_online:int, avans_din_taxa:bool, retinere:int, avans_minim:int,
     *   anulare_zile:?int, regula:?string, optiuni:list<string>, implicit:string}
     */
    public static function calculeaza(?array $plan, int $total, ?array $avansEc = null, int $taxa = 0, string $incasare = self::TAXA_ONLINE, ?int $platibil = null, bool $rotund = false): array
    {
        $procent = null;
        $suma = null;
        if (is_array($avansEc) && is_int($avansEc['procent'] ?? null)) {
            $procent = $avansEc['procent'];
            $suma = is_int($avansEc['suma'] ?? null) ? $avansEc['suma'] : null;
        } elseif ($plan !== null && ($plan['avans_procent'] ?? null) !== null) {
            $procent = (int) $plan['avans_procent'];
        }
        $procent = $procent === null ? 100 : max(0, min(100, $procent));
        $taxa = max(0, min($total, $taxa));
        // taxa locală stă mereu în total. La „la_sosire”, plata de pe site o lasă afară: avansul și plata întreagă
        // se calculează fără ea. La „online”, plata o cuprinde întreagă, deci avansul e cel puțin taxa.
        $laSosire = $incasare === self::TAXA_LA_SOSIRE && $taxa > 0;
        // sumele rotunde la leu. Cu `platibil_online` (EasyCheck 7.550.119), cele din GET /total, întocmai; la motorul
        // local, aceeași regulă aplicată aici; altfel (un EasyCheck mai vechi), calculul la ban de până acum.
        $leu = $platibil !== null || $rotund;
        if ($platibil !== null) {
            $integral = max(0, min($total, $platibil));
            $suma ??= self::avansRotund($integral, $procent);
        } elseif ($rotund) {
            $integral = self::platibil($total, $taxa, $laSosire ? self::TAXA_LA_SOSIRE : self::TAXA_ONLINE);
            $suma = self::avansRotund($integral, $procent);
        } else {
            $integral = $laSosire ? $total - $taxa : $total;
            $suma = $laSosire ? self::avans($integral, $procent) : ($suma ?? self::avans($total, $procent));
        }
        if (!$laSosire) {
            // avansul planului sub taxă crește până la ea (la sumele rotunde, până la leul întreg de deasupra ei);
            // reținerea spune atunci că avansul cuprinde taxa locală.
            $dinTaxa = $procent > 0 && $suma < $taxa;
            if ($dinTaxa) {
                $suma = $leu ? min($integral, intdiv($taxa + 99, 100) * 100) : $taxa;
            }
        }
        $suma = max(0, min($integral, $suma));
        $anulare = is_array($avansEc) && array_key_exists('anulare_zile', $avansEc) ? $avansEc['anulare_zile']
            : ($plan !== null ? (($plan['tip'] ?? null) === 'nerambursabil' ? 0 : ($plan['anulare_zile'] !== null ? (int) $plan['anulare_zile'] : null)) : null);
        $optiuni = $procent > 0 && $procent < 100 && $suma > 0 && $suma < $integral ? ['avans', 'integral'] : ['integral'];
        return [
            'avans_procent' => $procent,
            'avans' => $suma,
            'rest_avans' => $total - $suma,
            'integral' => $integral,
            'taxa' => $taxa,
            // Felul în care se plătește taxa acestui total (la taxa 0: online). Alegerea gazdei, pe care comanda o păstrează
            // pentru schimbarea datelor, e RegulaPlata::incasare() pe `taxa_incasare` din total (Rezerva).
            'incasare' => $laSosire ? self::TAXA_LA_SOSIRE : self::TAXA_ONLINE,
            'taxa_online' => $laSosire ? 0 : $taxa,
            'avans_din_taxa' => $dinTaxa ?? false,
            // cât se reține după termenul de anulare gratuită (înainte de plafonul sumei plătite): avansul, iar cu taxa
            // locală în plata online, cel mult prețul cazării (taxa se returnează).
            'retinere' => !$laSosire && $taxa > 0 ? min($suma, $total - $taxa) : $suma,
            // avansul minim, cât taxa locală plătită online (la sumele rotunde, leul întreg de deasupra ei); 0 fără ea.
            'avans_minim' => !$laSosire && $taxa > 0 ? ($leu ? min($integral, intdiv($taxa + 99, 100) * 100) : $taxa) : 0,
            'anulare_zile' => is_int($anulare) ? $anulare : null,
            'regula' => is_array($avansEc) && is_string($avansEc['regula'] ?? null) ? $avansEc['regula'] : null,
            'optiuni' => $optiuni,
            'implicit' => $optiuni[0],
        ];
    }

    /** Suma de plătit acum după alegerea oaspetelui (o alegere necunoscută sau nepermisă: implicitul regulii). */
    public static function sumaAcum(array $regula, int $total, ?string $alegere): array
    {
        $a = in_array($alegere, $regula['optiuni'], true) ? $alegere : $regula['implicit'];
        $acum = $a === 'avans' ? $regula['avans'] : ($regula['integral'] ?? $total);
        return ['alegere' => $a, 'suma_acum' => $acum, 'rest_la_sosire' => $total - $acum];
    }

    /**
     * Partea de taxă locală din suma plătită online (`plata.taxa_locala` la POST /rezervari): la „online”, toată
     * taxa (cel mult suma plătită); la „la_sosire”, 0. Null la comenzile de dinaintea alegerii (câmpul rămâne afară).
     */
    public static function taxaOnline(?string $incasare, int $taxa, int $suma, ?int $total = null): ?int
    {
        if ($incasare === null) {
            return null;
        }
        if ($incasare === self::TAXA_LA_SOSIRE) {
            // suma rotundă la leu poate trece cu câțiva bani de partea de cazare (622,00 față de 621,78): ce trece e
            // taxă locală plătită online, declarată ca atare (ghidul API, `plata.taxa_locala`).
            return $total === null ? 0 : max(0, min($taxa, $suma - max(0, $total - $taxa)));
        }
        return max(0, min($taxa, $suma));
    }

    /** O sumă în bani la cel mai apropiat leu, jumătatea în sus (62721 → 62700, 62750 → 62800), ca EasyCheck. */
    public static function rotund(int $bani): int
    {
        return intdiv(max(0, $bani) + 50, 100) * 100;
    }

    /**
     * Cât se poate plăti cel mult la rezervare (`platibil_online` din GET /total): cu taxa locală încasată online,
     * totalul; cu „la_sosire”, totalul fără taxa locală, rotund la leu (628,00 − 6,22 = 621,78, deci 622,00), niciodată
     * peste total; o sumă care ar ieși 0 după rotunjire rămâne nerotunjită.
     */
    public static function platibil(int $total, int $taxa, string $incasare): int
    {
        if ($incasare !== self::TAXA_LA_SOSIRE) {
            return $total;
        }
        $exact = max(0, $total - max(0, $taxa));
        $r = self::rotund($exact);
        return $r > 0 ? min($r, max(0, $total)) : $exact;
    }

    /**
     * Avansul rotund la leu (ca EasyCheck): procentul din suma plătibilă, la cel mai apropiat leu, jumătatea în sus
     * (30% din 628,00 = 188,40, deci 188,00). La 100%, toată suma plătibilă; un avans care ar ieși 0 după rotunjire
     * rămâne nerotunjit; niciodată peste suma plătibilă.
     */
    public static function avansRotund(int $platibil, int $procent): int
    {
        $platibil = max(0, $platibil);
        if ($procent >= 100) {
            return $platibil;
        }
        $exact = self::avans($platibil, $procent);
        $r = intdiv($exact + 50, 100) * 100;
        return $r > 0 ? min($r, $platibil) : $exact;
    }

    /**
     * Felul rotunjirii pentru un total al fațadei (Total): [`platibil_online` din GET /total sau null, true la motorul
     * local]. Se desface în ultimele două argumente ale lui `calculeaza`.
     * @return array{0:?int, 1:bool}
     */
    public static function rotunjire(array $total): array
    {
        return [is_int($total['platibil_online'] ?? null) ? $total['platibil_online'] : null, ($total['sursa'] ?? null) === 'site'];
    }

    /** Avansul în bani, rotunjit la cel mai apropiat ban: calculul de dinaintea sumelor rotunde, păstrat pentru comenzile vechi și un EasyCheck mai vechi. */
    public static function avans(int $total, int $procent): int
    {
        return (int) round($total * max(0, min(100, $procent)) / 100);
    }
}
