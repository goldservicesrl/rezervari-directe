<?php

declare(strict_types=1);

// Migrarea 0060: taxa locală de returnat de gazdă la anularea unei rezervări plătite prin transfer bancar.
// `taxa_de_returnat`: taxa locală plătită online (bani), scrisă o singură dată, la anulare; `taxa_returnata_la`: când a
// apăsat gazda „Am returnat taxa locală” (alerta panoului rămâne până atunci). `avans_initial`: avansul din ziua
// rezervării, ținut la prima mutare pe alte date; `avans_mutare`: alegerea gazdei din ziua comenzii pentru reținerea după
// o mutare (`recalculat` sau `initial`). Comenzile de dinainte rămân cu NULL.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Taxa locală de returnat la anularea plății prin transfer; avansul de reținut după o mutare',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('comenzi', 'taxa_de_returnat', 'bigint null');
        $s->coloana('comenzi', 'taxa_returnata_la', 'moment null');
        $s->coloana('comenzi', 'avans_initial', 'bigint null');
        $s->coloana('comenzi', 'avans_mutare', 'text(16) null');
    },
];
