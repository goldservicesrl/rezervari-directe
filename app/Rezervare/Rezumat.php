<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Nucleu\Aplicatie;
use Cazo\Pret\Sejur;
use Cazo\Teme\Componente;

defined('CAZO') or exit;

/**
 * Rezumatul unei rezervări: camerele cu oaspeții lor, sosirea și plecarea
 * cu orele sejurului, durata, tariful cu politica de anulare, totalul cu componentele, plata și regulile casei.
 * Același rezumat stă în comandă (din sejurul cerut și totalul live) și pe paginile de după (din comanda salvată).
 */
final class Rezumat
{
    /**
     * @param list<array{camera:string, adulti:int, copii:int}> $camere
     * @param array{nopti?:?int, taxe?:?int, taxa_locala?:?int} $componente
     * @param ?string $taxa cum se plătește taxa locală, spus pe rândul ei: `online` (inclusă în plata de acum),
     *   `platita` (inclusă în plata online, după plată), `la_sosire` (o plătește la sosire) sau null (doar suma)
     */
    public static function construieste(Aplicatie $app, Traducator $t, string $limba, string $sosire, string $plecare, array $camere, ?string $plan, int $total, array $componente, int $laSosire,
        ?string $taxa = null, int $taxaSosire = 0, ?array $metode = null): array
    {
        $p = (array) ($app->ecOglinda()->corp('proprietate') ?? []);
        $ore = (array) ($p['ore'] ?? []);
        $titluri = [];
        foreach ((array) ($app->ecOglinda()->corp('camere')['camere'] ?? []) as $x) {
            if (is_array($x) && is_string($x['id'] ?? null)) {
                $titluri[$x['id']] = $app->modelVedere($limba)->cameraDupaId($x['id'])['titlu'] ?? (string) ($x['titlu'] ?? '');
            }
        }
        $lista = [];
        foreach ($camere as $c) {
            $lista[] = $t->t('comanda.camera_oaspeti', ['camera' => $titluri[$c['camera']] ?? $c['camera'], 'oaspeti' => self::oaspeti($t, (int) $c['adulti'], (int) $c['copii'])]);
        }
        $n = Sejur::nopti($sosire, $plecare);
        $planRand = $plan !== null ? ($app->pretNoapte()->planuri()[$plan] ?? null) : null;
        $comp = [];
        foreach (['nopti', 'taxe', 'taxa_locala'] as $k) {
            if (($componente[$k] ?? null) !== null && ($k === 'nopti' || $componente[$k] > 0)) {
                $text = Format::baniOaspete((int) $componente[$k], $limba);
                if ($k === 'taxa_locala' && $taxa !== null) {
                    // după o mutare cu diferența la sosire, o parte din taxă a rămas la sosire: rândul spune ambele părți.
                    $text = $taxa === 'platita' && $taxaSosire > 0 && $taxaSosire < (int) $componente[$k]
                        ? $t->t('rezumat.taxa_locala.partial', ['suma' => $text, 'online' => Format::baniOaspete((int) $componente[$k] - $taxaSosire, $limba), 'sosire' => Format::baniOaspete($taxaSosire, $limba)])
                        : $t->t('rezumat.taxa_locala.' . ($taxa === 'platita' && $taxaSosire >= (int) $componente[$k] ? 'la_sosire' : $taxa), ['suma' => $text]);
                }
                $comp[] = ['eticheta' => $t->t('rezumat.comp.' . $k), 'text' => $text];
            }
        }
        $reguli = [];
        // copiii și bebelușii bineveniți stau pe un singur rând.
        $impreuna = ($p['reguli_casa']['copii'] ?? null) === true && ($p['reguli_casa']['bebelusi'] ?? null) === true;
        if ($impreuna) {
            $reguli[] = $t->t('reguli.copii_bebelusi.da');
        }
        foreach ($impreuna ? ['animale', 'evenimente', 'fumat'] : ['copii', 'bebelusi', 'animale', 'evenimente', 'fumat'] as $r) {
            $v = $p['reguli_casa'][$r] ?? null;
            if (is_bool($v)) {
                $reguli[] = $t->t('reguli.' . $r . ($v ? '.da' : '.nu'));
            }
        }
        $comp0 = new Componente($app, $app->modelVedere($limba), $t, $limba, null, '0.0.0.0/24');
        $termen = (int) $app->setari()->get('cereri.termen_ore', 24);
        return [
            'camere' => $lista,
            'sosire' => is_string($ore['checkin_de_la'] ?? null) ? $t->t('comanda.sosire_ora', ['data' => Format::ziLunga($sosire, $limba), 'ora' => $ore['checkin_de_la']]) : Format::ziLunga($sosire, $limba),
            'plecare' => is_string($ore['plecare_pana_la'] ?? null) ? $t->t('comanda.plecare_ora', ['data' => Format::ziLunga($plecare, $limba), 'ora' => $ore['plecare_pana_la']]) : Format::ziLunga($plecare, $limba),
            'durata' => $t->plural('unitate.nopti', $n),
            'tarif' => $planRand !== null ? Componente::titluCuMasa($planRand, $limba, $app) : null,
            // Regula care i se aplică oaspetelui: după metodele oferite (comanda) sau după cea aleasă (după rezervare).
            'politica' => $planRand !== null ? $comp0->politicaAnulare($planRand, $metode)
                : $t->t(!empty($metode['cash']) && empty($metode['card']) && empty($metode['transfer']) ? 'anulare.pana_la_sosire' : 'anulare.la_cerere'),
            'total' => Format::baniOaspete($total, $limba),
            'total_bani' => $total,
            'componente' => $comp,
            'plata' => $t->t('comanda.plata_sosire', ['termen' => $t->plural('unitate.ore', $termen >= 1 && $termen <= 72 ? $termen : 24)]),
            'la_sosire' => $t->t('comanda.la_sosire', ['suma' => Format::baniOaspete($laSosire, $limba)]),
            'reguli' => $reguli,
        ];
    }

    /** Rezumatul unei comenzi salvate. */
    public static function dinComanda(Aplicatie $app, Traducator $t, array $c): array
    {
        $r = self::construieste($app, $t, $t->limba(), (string) $c['sosire'], (string) $c['plecare'], $app->comenzi()->camere($c), $c['plan'] !== null ? (string) $c['plan'] : null,
            (int) $c['total'], $app->comenzi()->componente($c), (int) $c['rest_la_sosire'], self::taxaComanda($c), (int) ($c['taxa_la_sosire'] ?? 0), self::metodaComenzii($c));
        // Plata online: suma plătită (avansul sau tot), lângă restul de la sosire.
        if (($c['fel'] ?? 'cash') === 'card' && in_array($c['stare'], ['platita', 'trimitere_ec', 'confirmata', 'anulare_ceruta', 'anulare_de_lamurit'], true)) {
            $r['platit'] = $t->t('comanda.platit', ['suma' => Format::baniOaspete((int) $c['suma_acum'], $t->limba())]);
        }
        // Transferul bancar: suma de plătit cât plata e așteptată, suma primită după confirmare.
        if (($c['fel'] ?? 'cash') === 'transfer' && (int) $c['suma_acum'] > 0 && in_array($c['stare'], [Transfer::ASTEPTARE, 'confirmata'], true)) {
            $r['platit'] = $t->t($c['stare'] === 'confirmata' ? 'comanda.platit_transfer' : 'comanda.transfer_de_platit', ['suma' => Format::baniOaspete((int) $c['suma_acum'], $t->limba())]);
        }
        return $r;
    }

    /**
     * Rândul taxei locale al unei comenzi plătite cu cardul: `platita` la încasarea online, `la_sosire` când gazda
     * o încasează la sosire; null la plata la sosire și la comenzile de dinaintea alegerii.
     */
    public static function taxaComanda(array $c): ?string
    {
        if (!in_array($c['fel'] ?? 'cash', ['card', 'transfer'], true) || !is_string($c['taxa_incasare'] ?? null)) {
            return null;
        }
        // la transfer, taxa „online” intră în suma transferului.
        return $c['taxa_incasare'] === \Cazo\Pret\RegulaPlata::TAXA_LA_SOSIRE ? 'la_sosire' : ($c['fel'] === 'transfer' ? 'transfer' : 'platita');
    }

    /** Metoda de plată a comenzii, în forma cerută de politica de anulare (Componente::politicaAnulare). */
    public static function metodaComenzii(array $c): array
    {
        $fel = in_array($c['fel'] ?? 'cash', ['card', 'transfer'], true) ? (string) $c['fel'] : 'cash';
        return [$fel => true];
    }

    public static function oaspeti(Traducator $t, int $adulti, int $copii): string
    {
        $s = $t->plural('unitate.adulti', $adulti);
        return $copii > 0 ? $s . ', ' . $t->plural('unitate.copii', $copii) : $s;
    }
}
