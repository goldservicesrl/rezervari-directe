<?php

declare(strict_types=1);

// Migrarea 0046: repartizarea rezervărilor locale pe unitățile camerelor, pentru calendarul iCal pe unitate.
//
//   - local_unitati: câte un rând pe unitatea ocupată de o rezervare (rezervarea, poziția camerei în rezervare, numărul
//     unității, de la 1); perioada și camera se țin pe rând, ca o rezervare mutată pe alte date sau în altă cameră să
//     primească din nou o unitate. Rândurile rămân cât rezervarea rămâne în picioare: alocarea nu se mută la recalculare.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Rezervările locale repartizate pe unitățile camerelor (calendarul iCal pe unitate)',
    'ruleaza' => static function (Schema $s): void {
        $s->tabel('local_unitati', [
            'rezervare' => 'text(40)',
            'poz' => 'int',
            'unitate' => 'int',
            'camera' => 'text(32)',
            'sosire' => 'zi',
            'plecare' => 'zi',
            'creat_la' => 'moment',
        ], ['rezervare', 'poz', 'unitate']);
        $s->index('local_unitati', 'ix_local_unitati_camera', ['camera', 'plecare']);
    },
];
