<?php

declare(strict_types=1);

// Migrarea 0010: după rezervare, anularea și rambursările din panou.
// Pe comenzi, regula de reținere înghețată în ziua rezervării (ca regula avansului din EasyCheck): `avans` (suma
// avansului planului, în bani) și `nerambursabil`. Marcajul anulării pornite din Cazo (oaspetele sau panoul), scris
// înaintea apelului POST /rezervari/{id}/anuleaza, ca sincronizarea să o recunoască și să ramburseze o singură dată:
// `anulare_actor`, `anulare_spre` (starea de sosire), `anulare_suma` (de rambursat), `anulare_net` (netul încasat la pornire), `anulare_idem` (cheia de
// idempotență), `anulare_pornita_la`, reluările (`anulare_incercari`, `anulare_urmatoarea`, `anulare_eroare`),
// confirmarea EasyCheck (`anulata_ec_la`), rambursarea încheiată (`anulare_rambursata_la`) și e-mailul trimis o
// singură dată (`email_anulare_la`).
// Pe operatii_plata: nota rambursării din panou (criptată) și momentul e-mailului către oaspete (`anuntat_la`).

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Anularea rezervărilor și rambursările din panou',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('comenzi', 'avans', 'bigint null');
        $s->coloana('comenzi', 'nerambursabil', 'int implicit 0');
        $s->coloana('comenzi', 'anulare_actor', 'text(32) null');
        $s->coloana('comenzi', 'anulare_spre', 'text(32) null');
        $s->coloana('comenzi', 'anulare_suma', 'bigint null');
        $s->coloana('comenzi', 'anulare_net', 'bigint null');
        $s->coloana('comenzi', 'anulare_idem', 'text(64) null');
        $s->coloana('comenzi', 'anulare_pornita_la', 'moment null');
        $s->coloana('comenzi', 'anulare_incercari', 'int implicit 0');
        $s->coloana('comenzi', 'anulare_urmatoarea', 'moment null');
        $s->coloana('comenzi', 'anulare_eroare', 'text(64) null');
        $s->coloana('comenzi', 'anulata_ec_la', 'moment null');
        $s->coloana('comenzi', 'anulare_rambursata_la', 'moment null');
        $s->coloana('comenzi', 'email_anulare_la', 'moment null');
        $s->coloana('operatii_plata', 'nota', 'textlung null');
        $s->coloana('operatii_plata', 'anuntat_la', 'moment null');
        $s->index('comenzi', 'ix_comenzi_ec_rezervare', ['ec_rezervare']);
    },
];
