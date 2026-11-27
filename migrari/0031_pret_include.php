<?php

declare(strict_types=1);

// Migrarea 0031: ce plătește oaspetele pe site pe lângă nopți, la „Prețul din EasyCheck”, se alege de acum în
// panoul site-ului (setarea `pret.include`) și se declară lui EasyCheck la fiecare cerere cu total. O instalare existentă
// pornește cu ce spunea cheia la ultima sincronizare (`pret.include` din GET /eu, păstrat în rezumatul `ec.cheie`), ca
// totalurile să rămână aceleași după actualizare; o instalare nouă (fără cheie încă) rămâne pe valoarea implicită, amândouă.
// La fel momentul încasării taxei locale (setarea `pret.taxa_incasare`): din `taxa_locala.incasare` al oglinzii prețurilor.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Ce plătește oaspetele pe site: alegerea de pe cheia EasyCheck trece în setările site-ului',
    'ruleaza' => static function (Schema $s): void {
        $b = $s->baza();
        if ($b->valoare("SELECT 1 FROM {setari} WHERE cheie = 'pret.include'") !== null) {
            return;
        }
        $v = $b->valoare("SELECT valoare FROM {setari} WHERE cheie = 'ec.cheie'");
        $cheie = is_string($v) ? json_decode($v, true) : null;
        $include = is_array($cheie) ? ($cheie['include'] ?? null) : null;
        if (!is_array($include) || ($cheie['sursa'] ?? null) !== 'easycheck') {
            return;
        }
        $b->executa('INSERT INTO {setari} (cheie, valoare, actualizat_la, actualizat_de) VALUES (?, ?, ?, NULL)', ['pret.include',
            json_encode(['taxe' => ($include['taxe'] ?? true) !== false, 'taxa_locala' => ($include['taxa_locala'] ?? true) !== false], JSON_THROW_ON_ERROR),
            gmdate('Y-m-d H:i:s')]);
        // momentul încasării taxei locale, cum îl spunea proprietatea la ultima sincronizare (GET /preturi, `taxa_locala.incasare`).
        $p = $b->valoare("SELECT corp FROM {ec_resurse} WHERE resursa = 'preturi'");
        $preturi = is_string($p) ? json_decode($p, true) : null;
        $incasare = is_array($preturi) ? ($preturi['taxa_locala']['incasare'] ?? null) : null;
        if (in_array($incasare, ['online', 'la_sosire'], true) && $b->valoare("SELECT 1 FROM {setari} WHERE cheie = 'pret.taxa_incasare'") === null) {
            $b->executa('INSERT INTO {setari} (cheie, valoare, actualizat_la, actualizat_de) VALUES (?, ?, ?, NULL)', ['pret.taxa_incasare', json_encode($incasare, JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s')]);
        }    },
];
