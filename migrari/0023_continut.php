<?php

declare(strict_types=1);

// Migrarea 0023 (0017 pe ramură, înainte de aducerea la zi cu main): conținutul și setările avansate (M6b).
//   - redirectari: adresele vechi trimise spre cele noi (301 sau 302), pentru mutarea de pe un site vechi;
//   - sabloane_email: zona editabilă a gazdei în e-mailurile către oaspeți (un paragraf pe șablon și limbă);
//   - poze_local: pozele încărcate de gazdă și alegerile ei pe toate pozele: categoria (în `rol`, cheile din
//     lista categoriilor), indicele („Dormitorul 2”), poza principală, ascunderea.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Conținutul: redirectările, zona editabilă a e-mailurilor, pozele încărcate de gazdă',
    'ruleaza' => static function (Schema $s): void {
        $s->tabel('redirectari', [
            'de' => 'cheie(191)',
            'la' => 'text(500)',
            'cod' => 'int implicit 301',
            'creat_la' => 'moment',
        ], ['de']);

        $s->tabel('sabloane_email', [
            'cheie' => 'cheie(64)',
            'limba' => 'cheie(8)',
            'nota' => 'textlung null',
            'actualizat_la' => 'moment',
            'actualizat_de' => 'bigint null',
        ], ['cheie', 'limba']);

        $s->coloana('poze_local', 'incarcata', 'bool implicit 0');
        $s->coloana('poze_local', 'principala', 'bool implicit 0');
        $s->coloana('poze_local', 'indice', 'int null');
        $s->coloana('poze_local', 'ascunsa', 'bool implicit 0');
        $s->coloana('poze_local', 'creat_la', 'moment null');
    },
];
