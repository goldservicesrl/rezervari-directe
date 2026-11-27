<?php

declare(strict_types=1);

// Migrarea 0020: prețul site-ului și datele externe.
// `reduceri_local`: reducerile gazdei la „Prețul de pe site-ul meu”, aplicate de MotorLocal.
// `date_externe`: vremea (Open-Meteo, o oră), cursul BNR (zilnic) și răspunsurile ținute minte ale căutărilor de
// locuri (Nominatim, Overpass), citite doar din joburi și din panou, niciodată la cererea unui vizitator.
// `repere`: punctele de reper ale gazdei (0-5), cu coordonatele în milionimi de grad.
// Numărul 0020 lasă loc migrărilor scrise în paralel.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Reducerile locale, datele externe și punctele de reper',
    'ruleaza' => static function (Schema $s): void {
        $s->tabel('reduceri_local', [
            'id' => 'id',
            'titlu' => 'json',
            'fel' => 'text(16)',
            'valoare' => 'int',
            'planuri' => 'json null',
            'camere' => 'json null',
            'sejur_de' => 'zi null',
            'sejur_pana' => 'zi null',
            'zile_inainte_min' => 'int null',
            'zile_inainte_max' => 'int null',
            'nopti_min' => 'int null',
            'activa' => 'bool implicit 1',
            'ordine' => 'int implicit 0',
            'creat_la' => 'moment',
            'actualizat_la' => 'moment',
        ]);

        $s->tabel('date_externe', [
            'nume' => 'cheie(64)',
            'corp' => 'json null',
            'citit_la' => 'moment null',
            'versiune' => 'int implicit 0',
            'ultima_eroare' => 'text(500) null',
            'eroare_la' => 'moment null',
        ], ['nume']);

        $s->tabel('repere', [
            'id' => 'id',
            'nume' => 'text(120)',
            'categorie' => 'text(32)',
            'lat_e6' => 'int null',
            'lng_e6' => 'int null',
            'distanta_m' => 'int null',
            'minute' => 'int null',
            'sursa' => 'text(8)',
            'osm' => 'text(32) null',
            'principal' => 'bool implicit 0',
            'ordine' => 'int implicit 0',
            'creat_la' => 'moment',
        ]);
    },
];
