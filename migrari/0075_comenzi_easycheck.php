<?php

declare(strict_types=1);

// Migrarea 0075 (etapa 3 din „EasyCheck întâi”, contract-comenzi.md): anulările și rambursările hotărâte în EasyCheck.
// `ec_comenzi`: comenzile primite de la EasyCheck (`GET /comenzi`), cu id-ul comenzii ca cheie primară: aceeași comandă
// citită de două procese (cronul și anunțul) intră o singură dată, iar un rând existent înseamnă „în lucru aici”; starea
// locală, verificările, cheia Stripe derivată din comandă, suma executată, ziua execuției (plafonul zilnic, în ora României)
// și e-mailurile trimise. `ec_comenzi_rezultate`: coada rapoartelor `POST /comenzi/{id}/rezultat`, cu corpul și cheia de
// idempotență fixate la punere, ca `ec_plati`. Pe `comenzi`: cererea de anulare trimisă la EasyCheck (momentul cererii,
// cheia, reluările), hotărârea gazdei din EasyCheck (calea restituirii și comanda ei) și clauza trimisă după aprindere.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Anulările și rambursările hotărâte în EasyCheck: comenzile, rapoartele, cererile de anulare',
    'ruleaza' => static function (Schema $s): void {
        $s->tabel('ec_comenzi', [
            'id' => 'text(40)',
            'tip' => 'text(16)',
            'rezervare' => 'text(64) null',
            'comanda_id' => 'bigint null',
            'suma' => 'bigint implicit 0',
            'motiv' => 'text(32) null',
            'mesaj' => 'text(32) null',
            'proba' => 'int implicit 0',
            'stare' => 'text(16)',
            'stare_ec' => 'text(16) null',
            'cod' => 'text(40) null',
            'detalii' => 'json null',
            'idem_stripe' => 'text(120) null',
            'suma_executata' => 'bigint null',
            'zi' => 'text(10) null',
            'incercari' => 'int implicit 0',
            'urmatoarea' => 'moment null',
            'eroare' => 'text(200) null',
            'email_gazda_la' => 'moment null',
            'email_oaspete_la' => 'moment null',
            'primita_la' => 'moment',
            'executata_la' => 'moment null',
            'incheiata_la' => 'moment null',
            'actualizat_la' => 'moment',
        ], ['id']);
        $s->index('ec_comenzi', 'ix_ec_comenzi_stare', ['stare']);
        $s->index('ec_comenzi', 'ix_ec_comenzi_zi', ['zi']);
        $s->index('ec_comenzi', 'ix_ec_comenzi_comanda', ['comanda_id']);
        $s->tabel('ec_comenzi_rezultate', [
            'id' => 'id',
            'comanda' => 'text(40)',
            'stare' => 'text(16)',
            'corp' => 'textlung',
            'idem' => 'text(100)',
            'livrare' => 'text(16)',
            'incercari' => 'int implicit 0',
            'urmatoarea' => 'moment null',
            'cod' => 'text(64) null',
            'creat_la' => 'moment',
            'actualizat_la' => 'moment',
        ]);
        $s->unic('ec_comenzi_rezultate', 'ux_ec_comenzi_rezultate', ['comanda', 'stare']);
        $s->index('ec_comenzi_rezultate', 'ix_ec_comenzi_rezultate_livrare', ['livrare', 'urmatoarea']);
        $s->coloana('comenzi', 'ec_cerere_la', 'moment null');
        $s->coloana('comenzi', 'ec_cerere_idem', 'text(100) null');
        $s->coloana('comenzi', 'ec_cerere_stare', 'text(16) null');
        $s->coloana('comenzi', 'ec_cerere_incercari', 'int implicit 0');
        $s->coloana('comenzi', 'ec_cerere_urmatoarea', 'moment null');
        $s->coloana('comenzi', 'ec_restituire_cale', 'text(16) null');
        $s->coloana('comenzi', 'ec_restituire_comanda', 'text(40) null');
        $s->coloana('comenzi', 'ec_contract_trimis_la', 'moment null');
    },
];
