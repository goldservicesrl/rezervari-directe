<?php

declare(strict_types=1);

// Migrarea 0038: metodele de plată devin o listă de metode independente (`card`, `cash`, `transfer`), odată cu
// plata prin transfer bancar. Setarea `plati.metode` ținea până acum un singur cuvânt: `card`, `cash` sau `ambele`
//; o instalare existentă își păstrează alegerea, scrisă ca listă: ["card"], ["cash"] sau ["card","cash"].
// O instalare fără alegere (setarea lipsă sau null) rămâne neschimbată, iar o valoare deja listă nu se atinge.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Metodele de plată: din card / cash / ambele în lista metodelor bifate',
    'ruleaza' => static function (Schema $s): void {
        $b = $s->baza();
        $v = $b->valoare("SELECT valoare FROM {setari} WHERE cheie = 'plati.metode'");
        $vechi = is_string($v) ? json_decode($v, true) : null;
        $lista = ['card' => ['card'], 'cash' => ['cash'], 'ambele' => ['card', 'cash']][is_string($vechi) ? $vechi : ''] ?? null;
        if ($lista === null) {
            return;
        }
        $b->executa("UPDATE {setari} SET valoare = ? WHERE cheie = 'plati.metode'", [json_encode($lista, JSON_THROW_ON_ERROR)]);
    },
];
