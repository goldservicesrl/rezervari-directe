<?php

declare(strict_types=1);

// Pachetele de INSERT ale oglinzii EasyCheck rămân sub limita de parametri a SQLite-ului vechi (999 înainte de 3.32).
// Proba pe server (20.11.2026, PHP 8.4 din cPanel): 100 de rânduri de prețuri dădeau „too many SQL variables”.

use Cazo\EasyCheck\Oglinda;

return [
    'pachetul de INSERT ține parametrii sub limita SQLite-ului vechi, la orice număr de coloane' => function (Mediu $m): void {
        foreach ([1, 5, 9, 10, 11, 12, 20, 50, 400, 2000] as $coloane) {
            $randuri = Oglinda::marimePachet($coloane);
            adevarat($randuri >= 1, 'pachet gol la ' . $coloane . ' coloane');
            adevarat($randuri <= 100, 'pachet prea mare la ' . $coloane . ' coloane');
            if ($coloane <= Oglinda::PARAMETRI_MAX) {
                adevarat($randuri * $coloane <= Oglinda::PARAMETRI_MAX, $randuri . ' rânduri x ' . $coloane . ' coloane trec de limită');
            }
        }
        adevarat(Oglinda::PARAMETRI_MAX < 999, 'limita trebuie să rămână sub 999');
        egal(100, Oglinda::marimePachet(9));
        egal(81, Oglinda::marimePachet(11));
    },
];
