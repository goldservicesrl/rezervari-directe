<?php

declare(strict_types=1);

// Migrarea 0022: panoul complet.
//   - coduri_rezerva: cele 10 coduri de rezervă ale 2FA, doar amprenta HMAC, fiecare folosit o singură dată;
//   - audit: jurnalul înlănțuit (fiecare rând poartă amprenta celui dinainte; `hash_anterior` unic, deci două scrieri
//     simultane nu pot porni două ramuri din același rând);
//   - jetoane_utilizator: invitațiile și resetările de parolă trimise pe e-mail (doar SHA-256 al jetonului);
//   - alerte: jurnalul alertelor către gazdă (fiecare caz rămâne în panou, chiar când e-mailul pleacă o dată pe zi),
//     fără date personale (codul comenzii, sume, coduri de eroare);
//   - utilizatori.dezactivat_la: momentul dezactivării din panou.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Panoul complet: coduri de rezervă, auditul înlănțuit, invitațiile, jurnalul alertelor',
    'ruleaza' => static function (Schema $s): void {
        $s->tabel('coduri_rezerva', [
            'id' => 'id',
            'utilizator_id' => 'bigint',
            'cod_hash' => 'text(64)',
            'folosit_la' => 'moment null',
            'creat_la' => 'moment',
        ]);
        $s->index('coduri_rezerva', 'ix_coduri_rezerva_utilizator', ['utilizator_id']);

        $s->tabel('audit', [
            'id' => 'id',
            'la' => 'moment',
            'utilizator_id' => 'bigint null',
            'actiune' => 'text(64)',
            'obiect' => 'text(191) null',
            'detalii' => 'json null',
            'ip24' => 'text(64) null',
            'hash_anterior' => 'text(64)',
            'hash' => 'text(64)',
        ]);
        $s->unic('audit', 'ux_audit_anterior', ['hash_anterior']);
        $s->index('audit', 'ix_audit_actiune', ['actiune']);

        $s->tabel('jetoane_utilizator', [
            'id' => 'id',
            'utilizator_id' => 'bigint',
            'scop' => 'text(16)',
            'jeton_hash' => 'text(64)',
            'expira_la' => 'moment',
            'folosit_la' => 'moment null',
            'creat_de' => 'bigint null',
            'creat_la' => 'moment',
        ]);
        $s->unic('jetoane_utilizator', 'ux_jetoane_utilizator_hash', ['jeton_hash']);

        $s->tabel('alerte', [
            'id' => 'id',
            'la' => 'moment',
            'sablon' => 'text(64)',
            'cheie' => 'text(191)',
            'date' => 'json null',
            'trimis' => 'bool implicit 0',
        ]);
        $s->index('alerte', 'ix_alerte_la', ['la']);

        $s->coloana('utilizatori', 'dezactivat_la', 'moment null');
    },
];
