<?php

declare(strict_types=1);

// Migrarea 0035: termenele de păstrare a datelor oaspeților au de acum patru valori. Comenzile rambursate sau
// respinse, ținute până acum pe termenul celor abandonate (în ore), primesc termenul lor, în zile. O instalare cu termenele
// deja alese le păstrează neschimbate, și pe cele aflate în afara pragurilor noi (rămân în vigoare, cu avertizarea din
// panou, până le schimbă gazda), iar termenul nou pornește de la 180 de zile. O instalare fără termene alese rămâne fără
// (instalatorul și panoul le propun). Doar setarea se completează; schema rămâne aceeași, deci versiunea anterioară a
// softului citește mai departe cele trei termene ale ei.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Termenele de păstrare: comenzile rambursate sau respinse primesc termenul lor, de 180 de zile',
    'ruleaza' => static function (Schema $s): void {
        $b = $s->baza();
        $v = $b->valoare("SELECT valoare FROM {setari} WHERE cheie = 'gdpr.termene'");
        $t = is_string($v) ? json_decode($v, true) : null;
        if (!is_array($t) || isset($t['rambursate_zile'])) {
            return;
        }
        $t['rambursate_zile'] = 180;
        $b->executa("UPDATE {setari} SET valoare = ? WHERE cheie = 'gdpr.termene'", [json_encode($t, JSON_THROW_ON_ERROR)]);
    },
];
