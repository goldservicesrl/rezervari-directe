<?php

declare(strict_types=1);

// Migrarea 0080 (etapa 4 din „EasyCheck întâi”, contract-mesaje.md): mesajele către oaspeți rescrise de gazdă în
// EasyCheck. Corpul brut al lui `GET /mesaje` stă în `ec_resurse` (rândul `mesaje`, cu ETag); `ec_mesaje` e proiecția lui,
// refăcută la fiecare corp nou: un rând pe mesaj și limbă, cu subiectul, corpul, versiunea oglinzii și verdictul gărzilor
// site-ului (`valid`, `garda` = gărzile încălcate, sortate, despărțite prin virgulă). Doar aditivă.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Mesajele către oaspeți scrise în EasyCheck: proiecția oglinzii',
    'ruleaza' => static function (Schema $s): void {
        $s->tabel('ec_mesaje', [
            'cheie' => 'cheie(64)',
            'limba' => 'cheie(8)',
            'subiect' => 'textlung',
            'corp' => 'textlung',
            'versiune' => 'int implicit 0',
            'valid' => 'bool implicit 0',
            'garda' => 'text(191) null',
            'actualizat_la' => 'text(32) null',
        ], ['cheie', 'limba']);
    },
];
