<?php

declare(strict_types=1);

// Migrarea 0040: „Am plătit” de la oaspete, pe o rezervare care așteaptă transferul bancar. Comanda ține
// momentul apăsării (`transfer_anuntat_la`, UTC; primul rămâne), cheia de idempotență a anunțului către EasyCheck
// (`transfer_anunt_idem`), momentul în care EasyCheck l-a primit (`transfer_anunt_ec_la`), reluarea programată după o
// eroare de rețea (`transfer_anunt_urmatoarea`), felul în care a fost anunțată gazda (`transfer_anunt_fel`: `easycheck`
// sau `site`, când EasyCheck nu cunoaște încă ruta) și momentul celei de-a doua notificări trimise de site
// (`transfer_anunt_reamintit_la`, doar la felul `site`).

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Transferul bancar: „Am plătit” de la oaspete, anunțul către EasyCheck și a doua notificare a gazdei',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('comenzi', 'transfer_anuntat_la', 'moment null');
        $s->coloana('comenzi', 'transfer_anunt_idem', 'text(64) null');
        $s->coloana('comenzi', 'transfer_anunt_ec_la', 'moment null');
        $s->coloana('comenzi', 'transfer_anunt_urmatoarea', 'moment null');
        $s->coloana('comenzi', 'transfer_anunt_fel', 'text(16) null');
        $s->coloana('comenzi', 'transfer_anunt_reamintit_la', 'moment null');
    },
];
