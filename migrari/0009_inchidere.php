<?php

declare(strict_types=1);

// Migrarea 0009: e-mailul către oaspete la închiderea comenzii de către gazdă (inchisa_gazda), trimis o singură dată.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'E-mailul oaspetelui la închiderea comenzii de către gazdă',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('comenzi', 'email_inchidere_la', 'moment null');
    },
];
