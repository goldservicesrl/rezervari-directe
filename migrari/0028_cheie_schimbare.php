<?php

declare(strict_types=1);

// Migrarea 0028: prefixul cheii EasyCheck cu care schimbarea datelor a creat rezervarea nouă (POST /rezervari, sau cererea
// POST /cereri la plata la sosire). La aplicare, comanda primește prefixul acesta, nu pe al cheii de atunci, care se
// poate schimba între pași. Schimbările de dinainte rămân cu NULL și urmează regula de până acum (cheia de la aplicare).

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Prefixul cheii EasyCheck a rezervării noi dintr-o schimbare de date',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('schimbari_date', 'cheie_prefix', 'text(16) null');
    },
];
