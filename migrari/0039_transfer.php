<?php

declare(strict_types=1);

// Migrarea 0039: plata prin transfer bancar. Comanda cu transfer (`fel` = `transfer`) își ține termenul de
// plată în vigoare (`transfer_termen`, UTC; se schimbă când gazda îl prelungește în EasyCheck), momentul planificat al
// reamintirii (`transfer_reamintire_la`, golit când reamintirea a plecat sau când comanda s-a încheiat) și contul bancar
// arătat oaspetelui la rezervare (`transfer_cont`, JSON: titularul, IBAN-ul, banca, SWIFT-ul), înghețat la comandă.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Transferul bancar: termenul de plată, reamintirea și contul înghețat pe comandă',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('comenzi', 'transfer_termen', 'moment null');
        $s->coloana('comenzi', 'transfer_reamintire_la', 'moment null');
        $s->coloana('comenzi', 'transfer_cont', 'text(600) null');
    },
];
