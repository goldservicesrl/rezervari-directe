<?php

declare(strict_types=1);

// Migrarea 0013: regula de reținere a comenzilor cu cardul făcute înainte de migrarea 0010. Aceasta a
// adăugat `avans` și `nerambursabil` fără valori pentru comenzile vechi, deci o comandă veche pe tariful nerambursabil ar fi
// apărut cu anulare gratuită și rambursare integrală. Regula se reface din ce a păstrat comanda veche:
//   - tariful nerambursabil: politica din contractul înghețat la comandă (`contract.politica`, textul acceptat de
//     oaspete: „Tarif nerambursabil.” / „Non-refundable rate.”); versiunea veche punea atunci `anulare_zile` = 0;
//   - avansul: `avans_procent` din ziua rezervării, aplicat pe total cu rotunjirea EasyCheck (ca RegulaPlata::avans).
// Comanda veche cu `anulare_zile` = 0 (singura valoare comună cu tariful nerambursabil) și fără o politică recunoscută în
// contract primește `nerambursabil` = 2 (Anulare::REGULA_NECUNOSCUTA): anularea vine drept cerere către gazdă, fără
// anulare gratuită, iar suma o hotărăște gazda. Comenzile noi (cu `avans` scris la comandă) și cele cash rămân neatinse.
// Idempotentă: se uită doar la comenzile cu cardul cu `avans` lipsă și `nerambursabil` = 0.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Regula de reținere a comenzilor cu cardul de dinaintea anulărilor',
    'ruleaza' => static function (Schema $s): void {
        $b = $s->baza();
        // Textele politicii din această versiune (limbi/ro.php, limbi/en.php, cheile anulare.*), aprobate pe 15.11.2026.
        $nerambursabil = ['Tarif nerambursabil.', 'Non-refundable rate.'];
        $panaLaSosire = ['Anulare gratuită până în ziua sosirii.', 'Free cancellation until the day of arrival.'];
        foreach ($b->toate("SELECT id, total, avans_procent, anulare_zile, contract FROM {comenzi} WHERE fel = 'card' AND avans IS NULL AND nerambursabil = 0") as $c) {
            $contract = is_string($c['contract']) ? json_decode($c['contract'], true) : null;
            $politica = is_array($contract) && is_string($contract['politica'] ?? null) ? $contract['politica'] : null;
            $are = static function (?string $text, array $cautate): bool {
                foreach ($cautate as $x) {
                    if ($text !== null && str_contains($text, $x)) {
                        return true;
                    }
                }
                return false;
            };
            $zile = $c['anulare_zile'] === null ? null : (int) $c['anulare_zile'];
            if ($are($politica, $nerambursabil)) {
                $marcaj = 1;
            } elseif ($zile === 0 && !$are($politica, $panaLaSosire)) {
                $marcaj = 2;
            } else {
                $marcaj = 0;
            }
            $avans = $c['avans_procent'] !== null ? (int) round((int) $c['total'] * max(0, min(100, (int) $c['avans_procent'])) / 100) : null;
            $b->executa('UPDATE {comenzi} SET nerambursabil = ?, avans = COALESCE(avans, ?) WHERE id = ? AND avans IS NULL AND nerambursabil = 0', [$marcaj, $avans, $c['id']]);
        }
    },
];
