<?php

declare(strict_types=1);

// Migrarea 0070: abonații la oferte. `oferte_trimiteri`: fiecare trimitere a gazdei (subiectul, textul, câți
// destinatari), cu e-mailurile ei în `oferte_destinatari` (starea lor e cea din `emailuri`). `oferte_dezabonari`: adresele
// dezabonate (indexul orb), ca o ofertă rămasă în coadă și lista din EasyCheck să le lase deoparte; cu cheia EasyCheck,
// adresa criptată stă aici până pleacă `POST /abonati/dezabonare`, apoi se șterge.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Abonații la oferte: trimiterile, destinatarii lor și dezabonările',
    'ruleaza' => static function (Schema $s): void {
        $s->tabel('oferte_trimiteri', [
            'id' => 'id',
            'subiect' => 'text(191)',
            'text' => 'textlung',
            'limba' => 'text(8)',
            'destinatari' => 'int implicit 0',
            'creat_de' => 'bigint null',
            'creat_la' => 'moment',
        ]);
        $s->tabel('oferte_destinatari', [
            'id' => 'id',
            'trimitere_id' => 'bigint',
            'email_id' => 'bigint',
        ]);
        $s->index('oferte_destinatari', 'ix_oferte_destinatari_trimitere', ['trimitere_id']);
        $s->tabel('oferte_dezabonari', [
            'id' => 'id',
            'email_io' => 'text(64)',
            'la' => 'moment',
            'sursa' => 'text(16)',
            'email' => 'textlung null',
            'ec_trimis_la' => 'moment null',
            'incercari' => 'int implicit 0',
            'urmatoarea' => 'moment null',
            'eroare' => 'text(200) null',
        ]);
        $s->unic('oferte_dezabonari', 'ux_oferte_dezabonari_email', ['email_io']);
    },
];
