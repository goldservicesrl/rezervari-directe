<?php

declare(strict_types=1);

// Migrarea 0027: prefixul cheii EasyCheck cu care s-a făcut comanda (EasyCheck 7.550.88, `cheie.inlocuieste` din GET /eu):
// schimbarea datelor știe atunci dacă cheia de acum e legată de ea (aceeași cheie sau o înlocuire notată). Comenzile
// de dinainte rămân cu NULL și urmează regula de până acum (cheia pusă după plată contează ca schimbată).

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Prefixul cheii EasyCheck a comenzii',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('comenzi', 'cheie_prefix', 'text(16) null');
    },
];
