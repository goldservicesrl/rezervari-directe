<?php

declare(strict_types=1);

// Migrarea 0030: plata cu reținere: suma se autorizează pe card la plată și se încasează după confirmarea EasyCheck.
// `plati` ține suma autorizată, termenul autorizării (`capture_before` din Stripe) și momentul capturii; o plată
// autorizată are `stare = 'autorizata'` și `suma_incasata = 0` până la captură. Plățile de dinainte rămân încasate.
// Fereastra de 10 minute: o rezervare EasyCheck creată fără plată se caută și se anulează (`comenzi.ec_orfana`).

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Plata cu reținere: suma autorizată, termenul ei și momentul capturii',
    'ruleaza' => static function (Schema $s): void {
        $s->coloana('plati', 'suma_autorizata', 'bigint implicit 0');
        $s->coloana('plati', 'capturare_inainte', 'moment null');
        $s->coloana('plati', 'capturata_la', 'moment null');
        // Rezervarea EasyCheck rămasă fără plată (creată după fereastra de 10 minute sau cu captura imposibilă): de căutat,
        // de anulat, anulată sau negăsită, cu termenul căutării.
        $s->coloana('comenzi', 'ec_orfana', 'text(16) null');
        $s->coloana('comenzi', 'ec_orfana_pana', 'moment null');
        // Diferența unei schimbări de date care n-a mai putut fi încasată de pe card: trece în restul de la sosire.
        $s->coloana('schimbari_date', 'diferenta_la_sosire', 'bigint null');
        // Capturile nereușite ale diferenței (numărul din cheia reluării) și prima dintre ele (termenul după care diferența
        // trece la sosire).
        $s->coloana('schimbari_date', 'capturi', 'int implicit 0');
        $s->coloana('schimbari_date', 'captura_de_la', 'moment null');
        // Eliberările nereușite ale diferenței (după ultima captură), reluate rar din cron.
        $s->coloana('schimbari_date', 'eliberari', 'int implicit 0');
        // Partea din taxa locală trecută la sosire odată cu diferența unei mutări (restul a intrat în plata online).
        $s->coloana('comenzi', 'taxa_la_sosire', 'bigint null');
    },
];
