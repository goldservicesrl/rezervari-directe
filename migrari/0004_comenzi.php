<?php

declare(strict_types=1);

// Migrarea 0004: comenzile și cererea cash.
// Comenzile cu jurnalul tranzițiilor, acordurile (dovada contractului și a acordului pentru noutăți), codurile
// de 6 cifre trimise pe e-mail, cutia de e-mailuri, utilizatorii și sesiunile panoului minim.
// Plățile online (plati, operatii_plata, stripe_evenimente) vine într-o versiune următoare.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Comenzile, jurnalul lor, acordurile, codurile pe e-mail, cutia de e-mailuri, panoul minim',
    'ruleaza' => static function (Schema $s): void {
        $s->tabel('comenzi', [
            'id' => 'id',
            'ref' => 'text(16)',
            'fel' => 'text(8)',
            'stare' => 'text(32)',
            'limba' => 'text(8)',
            'proba' => 'bool implicit 0',
            'sosire' => 'zi',
            'plecare' => 'zi',
            'camere' => 'json',
            'plan' => 'text(64) null',
            'sursa_pret' => 'text(16)',
            'total' => 'bigint',
            'componente' => 'json',
            'camere_parti' => 'json null',
            'reduceri' => 'json null',
            'avans_procent' => 'int null',
            'suma_acum' => 'bigint implicit 0',
            'rest_la_sosire' => 'bigint implicit 0',
            'anulare_zile' => 'int null',
            'anulare_limita' => 'zi null',
            // Datele oaspetelui criptate (cheia „pii”); căutarea după e-mail prin indexul orb.
            'oaspete' => 'textlung',
            'email_io' => 'text(64)',
            'tara' => 'text(2) null',
            'nota' => 'textlung null',
            'marketing_acord' => 'bool implicit 0',
            'marketing_retras_la' => 'moment null',
            'idem_ec' => 'text(36)',
            'corp_ec' => 'textlung',
            'ec_rezervare' => 'text(64) null',
            'ec_cerere' => 'text(64) null',
            'ec_expira_la' => 'moment null',
            'suprapunere' => 'bool implicit 0',
            'incercari' => 'int implicit 0',
            'urmatoarea_incercare' => 'moment null',
            'blocat_pana' => 'moment null',
            'eroare_cod' => 'text(64) null',
            'eroare_detalii' => 'json null',
            'request_id' => 'text(80) null',
            'token_oaspete_hash' => 'text(64)',
            'anulare_ceruta_la' => 'moment null',
            // Cererea cash: codul de 6 cifre confirmat și ultima citire GET /cereri/{id}.
            'cod_confirmat_la' => 'moment null',
            'verificat_la' => 'moment null',
            'ip24' => 'text(64)',
            'creat_la' => 'moment',
            'actualizat_la' => 'moment',
            'de_sters_la' => 'moment null',
            'anonimizat_la' => 'moment null',
        ]);
        $s->unic('comenzi', 'ux_comenzi_ref', ['ref']);
        $s->unic('comenzi', 'ux_comenzi_token', ['token_oaspete_hash']);
        $s->index('comenzi', 'ix_comenzi_stare', ['stare', 'urmatoarea_incercare']);
        $s->index('comenzi', 'ix_comenzi_email', ['email_io']);
        $s->index('comenzi', 'ix_comenzi_cerere', ['ec_cerere']);
        $s->index('comenzi', 'ix_comenzi_plecare', ['plecare']);

        // Jurnalul tranzițiilor: prima unealtă de suport (fără date personale).
        $s->tabel('comenzi_jurnal', [
            'id' => 'id',
            'comanda_id' => 'bigint',
            'la' => 'moment',
            'din_stare' => 'text(32) null',
            'in_stare' => 'text(32)',
            'actor' => 'text(32)',
            'detalii' => 'json null',
        ]);
        $s->index('comenzi_jurnal', 'ix_comenzi_jurnal_comanda', ['comanda_id']);

        // Dovada contractului și a acordului pentru noutăți: versiunile și amprentele textelor afișate.
        $s->tabel('acorduri', [
            'id' => 'id',
            'comanda_id' => 'bigint',
            'termeni_versiune' => 'text(32)',
            'termeni_hash' => 'text(64)',
            'confidentialitate_versiune' => 'text(32)',
            'text_buton' => 'text(191)',
            'text_precontractual_hash' => 'text(64)',
            'marketing' => 'bool implicit 0',
            'text_marketing_hash' => 'text(64) null',
            'la' => 'moment',
            'ip' => 'textlung',
            'agent' => 'textlung',
        ]);
        $s->index('acorduri', 'ix_acorduri_comanda', ['comanda_id']);

        // Codul de 6 cifre la cererea cash, când e pornit: doar amprenta lui, 10 minute, 5 încercări.
        $s->tabel('coduri_email', [
            'id' => 'id',
            'comanda_id' => 'bigint',
            'email_io' => 'text(64)',
            'cod_hash' => 'text(64)',
            'incercari' => 'int implicit 0',
            'expira_la' => 'moment',
            'confirmat_la' => 'moment null',
            'creat_la' => 'moment',
        ]);
        $s->index('coduri_email', 'ix_coduri_email_comanda', ['comanda_id']);

        // Cutia de e-mailuri: corpul se randează la trimitere, din șablonul nucleului și din `date`.
        $s->tabel('emailuri', [
            'id' => 'id',
            'comanda_id' => 'bigint null',
            'sablon' => 'text(64)',
            'limba' => 'text(8)',
            'catre' => 'textlung',
            'date' => 'json',
            'stare' => 'text(16)',
            'incercari' => 'int implicit 0',
            'urmatoarea_incercare' => 'moment',
            'trimis_la' => 'moment null',
            'eroare' => 'text(500) null',
            'creat_la' => 'moment',
        ]);
        $s->index('emailuri', 'ix_emailuri_stare', ['stare', 'urmatoarea_incercare']);

        // Panoul minim: utilizatorii și sesiunile; TOTP, rolurile complete și auditul vine într-o versiune următoare.
        $s->tabel('utilizatori', [
            'id' => 'id',
            'email' => 'text(191)',
            'nume' => 'text(191)',
            'parola_hash' => 'text(255)',
            'rol' => 'text(16)',
            'limba_panou' => 'text(8)',
            'totp_secret' => 'textlung null',
            'totp_ultimul_pas' => 'bigint null',
            'blocat_pana' => 'moment null',
            'ultima_logare_la' => 'moment null',
            'activ' => 'bool implicit 1',
            'creat_la' => 'moment',
        ]);
        $s->unic('utilizatori', 'ux_utilizatori_email', ['email']);

        $s->tabel('sesiuni_admin', [
            'id_hash' => 'cheie(64)',
            'utilizator_id' => 'bigint',
            'creat_la' => 'moment',
            'vazut_la' => 'moment',
            'reautentificat_la' => 'moment null',
            'ip24' => 'text(64)',
            'agent_scurt' => 'text(120)',
        ], ['id_hash']);
    },
];
