<?php

declare(strict_types=1);

// Migrarea 0050: linkul Cazo din subsol face parte din licență și nu se mai poate ascunde din panou. Setarea
// veche a bifei (`subsol.mentiune_easycheck`) nu mai e citită și se șterge. Numărul lasă loc migrărilor
// ramurilor care se unesc mai târziu.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Linkul Cazo din subsol, obligatoriu: setarea care îl ascundea se șterge',
    'ruleaza' => static function (Schema $s): void {
        $s->baza()->executa("DELETE FROM {setari} WHERE cheie = 'subsol.mentiune_easycheck'");
    },
];
