<?php

declare(strict_types=1);

// Migrarea 0005: contractul înghețat la comandă și întârzierea cererii cash.
// `contract`: textele acceptate de oaspete la comandă (identitatea gazdei, adresa, regulile casei, componentele
// totalului, informarea precontractuală, termenii în versiunea acceptată), din care se scrie e-mailul de
// confirmare, contractul pe suport durabil. Fără date personale ale oaspetelui.
// `intarziata_la`: momentul e-mailului „cererea întârzie”, trimis o singură dată după 6 ore fără răspuns.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Contractul înghețat la comandă și întârzierea cererii cash',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('comenzi', 'contract', 'textlung null');
        $s->coloana('comenzi', 'intarziata_la', 'moment null');
    },
];
