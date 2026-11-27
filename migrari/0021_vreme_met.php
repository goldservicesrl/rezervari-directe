<?php

declare(strict_types=1);

// Migrarea 0021: vremea vine de la MET Norway,
// gratuit și pentru folosire comercială, fără cheie. Cheia Open-Meteo salvată de gazdă (secretul `open_meteo_cheie`)
// nu mai folosește la nimic și se șterge; datele vechi din `date_externe.vreme` se înlocuiesc la citirea următoare.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Vremea de la MET Norway: cheia Open-Meteo se șterge',
    'ruleaza' => static function (Schema $s): void {
        $s->baza()->executa("DELETE FROM {secrete} WHERE nume = 'open_meteo_cheie'");
    },
];
