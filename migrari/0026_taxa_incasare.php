<?php

declare(strict_types=1);

// Migrarea 0026: cum încasează gazda taxa locală la rezervările de pe site (
// EasyCheck 7.550.91, `taxa_locala.incasare`): „online” (în plata de pe site) sau „la_sosire”, păstrată pe comanda plătită
// cu cardul din ziua comenzii. Comenzile de dinainte rămân cu NULL și merg ca până acum (fără `plata.taxa_locala`).

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Încasarea taxei locale pe comandă (online sau la sosire)',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('comenzi', 'taxa_incasare', 'text(16) null');
    },
];
