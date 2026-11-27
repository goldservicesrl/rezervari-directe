<?php

declare(strict_types=1);

// Migrarea 0006: momentul primei trimiteri a cererii cash.
// `trimisa_la`: trecerea `noua` → `trimitere_ec`, deci primul POST /cereri cu cheia de idempotență. De aici se
// numără cele 6 ore ale e-mailului „cererea întârzie” și cele 23 de ore ale renunțării: pe calea cu cod,
// comanda poate sta `noua` până la 24 de ore înainte de prima trimitere. Comenzile vechi, fără valoare, se numără
// de la `creat_la`.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Momentul primei trimiteri a cererii cash',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('comenzi', 'trimisa_la', 'moment null');
    },
];
