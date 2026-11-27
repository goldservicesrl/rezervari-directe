<?php

declare(strict_types=1);

// Migrarea 0012: `comenzi.anulare_limita_initiala`, termenul de anulare gratuită din ziua
// rezervării, păstrat când „Renunțați la anulare” mută termenul la ziua sosirii. Adăugată doar
// când lipsește, ca pe orice bază cu 0010 și 0011 deja aplicate.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Termenul gratuit din ziua rezervării, păstrat la renunțarea la anulare',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('comenzi', 'anulare_limita_initiala', 'zi null');
    },
];
