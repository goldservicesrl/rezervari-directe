<?php

declare(strict_types=1);

// Migrarea 0061: ce primește oaspetele când gazda anulează rezervarea. `anulare_gazda`: alegerea gazdei din
// ziua comenzii, spusă în contract (`tot`; `despagubire`: tot ce s-a plătit și, în plus, o sumă egală cu avansul, cu
// excepția forței majore; `caz`: despăgubirea hotărâtă la anulare); `despagubire_aleasa`: suma hotărâtă de gazdă la
// anulare, la `caz` (0 e o decizie); `despagubire`: suma datorată (bani), scrisă o singură dată la anularea gazdei (0 la
// forța majoră sau când gazda hotărăște 0); `despagubire_platita_la`: când a apăsat gazda „Am plătit despăgubirea”; `forta_majora`: anularea gazdei
// notată ca forță majoră. Comenzile de dinainte rămân cu NULL, deci fără clauză și fără alertă.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Anularea gazdei: clauza din contract și despăgubirea oaspetelui',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('comenzi', 'anulare_gazda', 'text(16) null');
        $s->coloana('comenzi', 'despagubire_aleasa', 'bigint null');
        $s->coloana('comenzi', 'despagubire', 'bigint null');
        $s->coloana('comenzi', 'despagubire_platita_la', 'moment null');
        $s->coloana('comenzi', 'forta_majora', 'int implicit 0');
    },
];
