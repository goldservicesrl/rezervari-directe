<?php

declare(strict_types=1);

// Migrarea 0056: completarea migrării 0030 (plata cu reținere). Migrarea 0030 a fost lărgită după prima ei variantă
// cu `schimbari_date.eliberari` și `comenzi.taxa_la_sosire`; o bază care rulase deja varianta veche a lui 0030 rămânea
// fără ele, iar codul le folosește la eliberarea diferenței și la mutarea cu taxa la sosire. Aici se adaugă doar dacă
// lipsesc (`coloana` sare peste o coloană existentă), deci pe o bază la zi migrarea nu schimbă nimic.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Plata cu reținere: coloanele adăugate târziu în 0030, pe bazele care rulaseră varianta veche',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('schimbari_date', 'eliberari', 'int implicit 0');
        $s->coloana('comenzi', 'taxa_la_sosire', 'bigint null');
    },
];
