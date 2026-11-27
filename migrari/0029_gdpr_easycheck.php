<?php

declare(strict_types=1);

// Migrarea 0029: ștergerea datelor unui oaspete, transmisă la EasyCheck (`POST /rezervari/{id}/anonimizeaza`,
// EasyCheck 7.550.92). `gdpr_stergeri`: fiecare ștergere cerută din panou, cu limba oaspetelui, codurile comenzilor
// anonimizate și ale celor păstrate până se încheie (fără date personale). `gdpr_easycheck`: câte un rând pentru fiecare
// rezervare EasyCheck a comenzilor șterse (și cele legate prin mutare), cu cheia de idempotență a cererii, reluările,
// codul erorii și răspunsul (ce s-a șters și ce rămâne, cu temeiul), pentru panou și pentru textul către oaspete.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Ștergerea datelor oaspetelui, transmisă la EasyCheck',
    'ruleaza' => static function (Schema $s): void {
        $s->tabel('gdpr_stergeri', [
            'id' => 'id',
            'utilizator_id' => 'bigint null',
            'limba' => 'text(8)',
            'anonimizate' => 'json',
            'ramase' => 'json',
            'creat_la' => 'moment',
        ]);
        $s->tabel('gdpr_easycheck', [
            'id' => 'id',
            'stergere_id' => 'bigint',
            'ref' => 'text(16)',
            'ec_rezervare' => 'text(64)',
            'idem' => 'text(64)',
            'stare' => 'text(16)',
            'incercari' => 'int implicit 0',
            'urmatoarea' => 'moment null',
            'cod' => 'text(64) null',
            'raspuns' => 'json null',
            'creat_la' => 'moment',
            'actualizat_la' => 'moment',
        ]);
        $s->index('gdpr_easycheck', 'ix_gdpr_ec_stergere', ['stergere_id']);
        $s->index('gdpr_easycheck', 'ix_gdpr_ec_stare', ['stare', 'urmatoarea']);
    },
];
