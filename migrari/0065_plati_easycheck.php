<?php

declare(strict_types=1);

// Migrarea 0065 (etapa 2 din „EasyCheck întâi”, contract-plati.md): ce se întâmplă cu banii unei rezervări plătite cu
// cardul ajunge la EasyCheck (`POST /rezervari/{id}/plati`). `ec_plati`: coada rapoartelor, câte un rând pe schimbare a unui
// eveniment (captura, eliberarea, o rambursare, o contestare), cu corpul trimis octet cu octet, cheia de idempotență a
// rândului, reluările și răspunsul (sumele știute de EasyCheck). `contestari`: contestările plăților la bancă, citite din
// Stripe (`charge.dispute.*` și citirea zilnică a listei), fără date personale.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Plățile raportate la EasyCheck și contestările plăților',
    'ruleaza' => static function (Schema $s): void {
        $s->tabel('ec_plati', [
            'id' => 'id',
            'comanda_id' => 'bigint',
            'rezervare' => 'text(64)',
            'tip' => 'text(24)',
            'ref' => 'text(100)',
            'stare' => 'text(16) null',
            'suma' => 'bigint null',
            'la' => 'moment',
            'amprenta' => 'text(64)',
            'corp' => 'textlung',
            'idem' => 'text(64)',
            'livrare' => 'text(16)',
            'incercari' => 'int implicit 0',
            'urmatoarea' => 'moment null',
            'cod' => 'text(64) null',
            'raspuns' => 'json null',
            'creat_la' => 'moment',
            'actualizat_la' => 'moment',
        ]);
        $s->index('ec_plati', 'ix_ec_plati_livrare', ['livrare', 'urmatoarea']);
        $s->index('ec_plati', 'ix_ec_plati_rezervare', ['rezervare', 'tip', 'ref']);
        $s->index('ec_plati', 'ix_ec_plati_comanda', ['comanda_id']);
        $s->tabel('contestari', [
            'id' => 'id',
            'comanda_id' => 'bigint null',
            'stripe_id' => 'text(64)',
            'stripe_pi' => 'text(64) null',
            'suma' => 'bigint',
            'motiv' => 'text(64) null',
            'stare' => 'text(32)',
            'termen' => 'moment null',
            'taxa' => 'bigint implicit 0',
            'taxa_returnata' => 'bigint implicit 0',
            'txn' => 'text(64) null',
            'debitat_la' => 'moment null',
            'deschisa_la' => 'moment',
            'inchisa_la' => 'moment null',
            'actualizat_la' => 'moment',
        ]);
        $s->unic('contestari', 'ux_contestari_stripe', ['stripe_id']);
        $s->index('contestari', 'ix_contestari_comanda', ['comanda_id']);
    },
];
