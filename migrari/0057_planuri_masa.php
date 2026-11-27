<?php

declare(strict_types=1);

// Migrarea 0057: masa inclusă în prețul planului (EasyCheck 7.550.142, `masa`, `masa_eticheta`,
// `masa_eticheta_en` pe fiecare plan din GET /preturi). Trei coloane noi în oglinda planurilor, null la un EasyCheck mai
// vechi. Când corpul brut al prețurilor e deja în `ec_resurse` (un ETag neschimbat nu mai aduce corpul a doua oară),
// masa se completează de acolo, ca site-ul s-o arate fără să aștepte o schimbare de preț.

use Cazo\Baza\Schema;
use Cazo\EasyCheck\Proiectii;

defined('CAZO') or exit;

return [
    'descriere' => 'Masa inclusă pe planurile EasyCheck: codul și etichetele în română și în engleză',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('planuri', 'masa', 'text(32) null');
        $s->coloana('planuri', 'masa_eticheta', 'text(191) null');
        $s->coloana('planuri', 'masa_eticheta_en', 'text(191) null');
        $b = $s->baza();
        $corp = $b->valoare("SELECT corp FROM {ec_resurse} WHERE resursa = 'preturi'");
        $d = is_string($corp) ? json_decode($corp, true) : null;
        foreach (is_array($d['planuri'] ?? null) ? $d['planuri'] : [] as $i => $p) {
            if (!is_array($p) || !is_string($p['id'] ?? null)) {
                continue;
            }
            try {
                $m = Proiectii::masa($p, "planuri[$i]");
            } catch (\UnexpectedValueException) {
                continue;
            }
            if ($m['masa'] !== null) {
                $b->executa('UPDATE {planuri} SET masa = ?, masa_eticheta = ?, masa_eticheta_en = ? WHERE id = ?', [$m['masa'], $m['masa_eticheta'], $m['masa_eticheta_en'], $p['id']]);
            }
        }
    },
];
