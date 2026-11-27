<?php

declare(strict_types=1);

// Migrarea 0025: paginile legale și subsolul, editabile integral din panou.
// `texte_legale` ține acum, pe lângă paragrafele adăugate (format `adaugat`), textul integral al gazdei (`integral`)
// și revenirea la model (`model`), fiecare cu modelul softului de la salvare, ca panoul să arate când modelul s-a schimbat
// la o actualizare. Rândurile de dinainte rămân `adaugat` și se afișează ca până acum.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Paginile legale și subsolul, editabile integral, cu modelul de la salvare',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('texte_legale', 'format', "text(16) implicit 'adaugat'");
        $s->coloana('texte_legale', 'model', 'textlung null');
    },
];
