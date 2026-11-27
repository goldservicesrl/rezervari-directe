<?php

declare(strict_types=1);

// Migrarea 0007: plata online.
// `plati`: sesiunea Checkout a comenzii și starea încasării; `operatii_plata`: fiecare încasare și rambursare, cu
// cheia de idempotență salvată înainte de apel; `stripe_evenimente`: deduplicarea anunțurilor Stripe.
// Pe comenzi: alegerea oaspetelui între avans și plata întreagă, momentul încasării confirmate (de la care se
// numără cele 6 ore ale recuperării) și motivul rambursării automate.
// Pe utilizatori: dreptul „Poate face rambursări” dat de proprietar unui angajat.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Plata online: plățile, operațiile de plată, anunțurile Stripe, dreptul de rambursare',
    'ruleaza' => static function (Schema $s): void {
        $s->tabel('plati', [
            'id' => 'id',
            'comanda_id' => 'bigint',
            'incercare' => 'int implicit 1',
            'stripe_sesiune' => 'text(255) null',
            'stripe_pi' => 'text(255) null',
            'stare' => 'text(32)',
            'suma' => 'bigint',
            'suma_incasata' => 'bigint implicit 0',
            'suma_rambursata' => 'bigint implicit 0',
            'comision' => 'bigint null',
            'metoda' => 'text(64) null',
            'metoda_scurt' => 'text(64) null',
            'idem_sesiune' => 'text(128)',
            'url' => 'textlung null',
            'expira_la' => 'moment null',
            'creat_la' => 'moment',
            'actualizat_la' => 'moment',
        ]);
        $s->index('plati', 'ix_plati_comanda', ['comanda_id']);
        $s->index('plati', 'ix_plati_sesiune', ['stripe_sesiune']);
        $s->index('plati', 'ix_plati_pi', ['stripe_pi']);

        $s->tabel('operatii_plata', [
            'id' => 'id',
            'comanda_id' => 'bigint',
            'tip' => 'text(16)',
            'suma' => 'bigint',
            'stripe_id' => 'text(255) null',
            'idem' => 'text(128)',
            'stare' => 'text(16)',
            'motiv' => 'text(32)',
            'eroare' => 'text(500) null',
            'incercari' => 'int implicit 0',
            'urmatoarea_incercare' => 'moment null',
            'utilizator_id' => 'bigint null',
            'la' => 'moment',
            'actualizat_la' => 'moment',
        ]);
        $s->index('operatii_plata', 'ix_operatii_comanda', ['comanda_id']);
        $s->unic('operatii_plata', 'ux_operatii_idem', ['idem']);

        $s->tabel('stripe_evenimente', [
            'id' => 'cheie(255)',
            'tip' => 'text(64)',
            'obiect' => 'text(255) null',
            'primit_la' => 'moment',
            'procesat_la' => 'moment null',
        ], ['id']);

        $s->coloana('comenzi', 'plata_aleasa', 'text(16) null');
        $s->coloana('comenzi', 'platita_la', 'moment null');
        $s->coloana('comenzi', 'motiv_rambursare', 'text(32) null');
        $s->coloana('utilizatori', 'poate_rambursa', 'bool implicit 0');
    },
];
