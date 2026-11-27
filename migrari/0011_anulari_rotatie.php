<?php

declare(strict_types=1);

// Migrarea 0011: `comenzi.anulare_rambursare_reia`, momentul următoarei reverificări a rambursării
// unei anulări (rotația cronului și pauza de 6 ore după reluările epuizate). Separat de 0010, care a fost publicată pe
// ramură fără ea: o bază cu 0010 deja aplicată primește coloana aici; Schema::coloana o adaugă doar când lipsește.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Rotația rambursărilor anulărilor',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('comenzi', 'anulare_rambursare_reia', 'moment null');
    },
];
