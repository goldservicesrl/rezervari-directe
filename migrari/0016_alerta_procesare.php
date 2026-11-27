<?php

declare(strict_types=1);

// Migrarea 0016: `operatii_plata.alerta_procesare_la`, momentul ultimei alerte pentru o rambursare rămasă
// în procesare. Amintirea zilnică pleacă la 24 de ore după alerta de dinainte (nu o dată pe zi calendaristică, ca
// fereastra fixă a limitatorului), iar cronul care rulează des nu mai scrie în jurnal la fiecare trecere.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Momentul ultimei alerte pentru rambursarea în procesare',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('operatii_plata', 'alerta_procesare_la', 'moment null');
    },
];
