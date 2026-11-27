<?php

declare(strict_types=1);

// Migrarea 0008: completările plății online.
// `stripe_evenimente.date`: câmpurile anunțului de care are nevoie procesarea (tipul obiectului, id-ul, comanda din
// metadata, PaymentIntent-ul, suma rambursată), fără date personale, ca un anunț rămas neprocesat după o eroare să
// fie reluat din cron; `incercari`: reluările lui.
// `comenzi.email_refuz_la`: momentul e-mailului rambursării automate, trimis o singură dată și pe calea din cron.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Reluarea anunțurilor Stripe și e-mailul rambursării trimis o singură dată',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('stripe_evenimente', 'date', 'json null');
        $s->coloana('stripe_evenimente', 'incercari', 'int implicit 0');
        $s->coloana('comenzi', 'email_refuz_la', 'moment null');
    },
];
