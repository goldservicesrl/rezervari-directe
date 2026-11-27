<?php

declare(strict_types=1);

// Migrarea 0014: anularea gazdei din EasyCheck nu mai returnează banii automat. Comanda cu bani încasați
// trece în `anulare_de_lamurit` până când gazda alege suma în panou; momentul intrării rămâne în `anulata_ec_la`
// (0010). `lamurire_reamintire_la`: când primește gazda reamintirea, dacă decizia încă lipsește (golit după trimitere).

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Anularea din EasyCheck lămurită de gazdă',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('comenzi', 'lamurire_reamintire_la', 'moment null');
    },
];
