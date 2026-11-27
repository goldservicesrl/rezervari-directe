<?php

declare(strict_types=1);

// Migrarea 0015: schimbarea datelor de către oaspete, din „Rezervarea mea”.
// `schimbari_date`: fiecare încercare de schimbare, cu datele vechi și noi, totalul și suma de acum recalculate după
// regula din ziua rezervării (avansul cu același procent, termenul gratuit mutat după noua sosire), diferența de preț,
// corpul noii rezervări EasyCheck (criptat, ca `comenzi.corp_ec`) și cheile de idempotență ale fiecărui pas: rezervarea
// nouă (`idem_ec`), anularea celei vechi (`idem_anulare`, cu marcajul `anulare_pornita_la` scris înaintea apelului, ca
// sincronizarea să recunoască anularea ca parte a schimbării) și refacerea celei vechi la datele care se suprapun
// (`idem_refacere`). Istoria rămâne întreagă: `ec_veche`, `ec_noua`, `ec_refacuta`, plus jurnalul comenzii.
// Pe `plati`: `schimbare_id`, plata diferenței (o sesiune Checkout proprie, același cont Stripe), și `incasata_la`,
// ziua ei în exportul de reconciliere (plata inițială rămâne cu `comenzi.platita_la`).
// Pe `operatii_plata`: `stripe_pi`, plata pe care o rambursează operația (o comandă poate avea acum două plăți: cea
// inițială și diferența). Operațiile de dinainte primesc plata comenzii lor (singura încasată).
// Pe `comenzi`: `schimbari`, câte schimbări de date s-au făcut (cel mult una, 118c).

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Schimbarea datelor de către oaspete',
    'ruleaza' => static function (Schema $s): void {
        $s->tabel('schimbari_date', [
            'id' => 'id',
            'comanda_id' => 'bigint',
            'fel' => 'text(8)',
            'stare' => 'text(32)',
            'ordine' => 'text(16)',
            'limba' => 'text(8)',
            'sosire_veche' => 'zi',
            'plecare_veche' => 'zi',
            'sosire' => 'zi',
            'plecare' => 'zi',
            'total_vechi' => 'bigint',
            'total' => 'bigint',
            'componente' => 'json',
            'camere_parti' => 'json null',
            'suma_acum_veche' => 'bigint implicit 0',
            'suma_acum' => 'bigint implicit 0',
            'rest_la_sosire' => 'bigint implicit 0',
            'avans' => 'bigint null',
            'anulare_limita' => 'zi null',
            'diferenta' => 'bigint implicit 0',
            'corp_ec' => 'textlung',
            'idem_ec' => 'text(36)',
            'idem_anulare' => 'text(36)',
            'idem_refacere' => 'text(36)',
            'ec_veche' => 'text(64)',
            'ec_noua' => 'text(64) null',
            'ec_cerere' => 'text(64) null',
            'ec_refacuta' => 'text(64) null',
            'ec_expira_la' => 'moment null',
            'refacere' => 'bool implicit 0',
            'corp_refacere' => 'textlung null',
            'jeton' => 'text(32)',
            'suprapunere' => 'bool implicit 0',
            'anulare_pornita_la' => 'moment null',
            'anulare_veche_la' => 'moment null',
            'plata_id' => 'bigint null',
            'rambursare_suma' => 'bigint implicit 0',
            'rambursata_la' => 'moment null',
            'rambursare_reia' => 'moment null',
            'incercari' => 'int implicit 0',
            'urmatoarea' => 'moment null',
            'eroare' => 'text(64) null',
            'eroare_detalii' => 'json null',
            'pornita_la' => 'moment null',
            'aplicata_la' => 'moment null',
            'incheiata_la' => 'moment null',
            'email_la' => 'moment null',
            'creat_la' => 'moment',
            'actualizat_la' => 'moment',
        ]);
        $s->index('schimbari_date', 'ix_schimbari_comanda', ['comanda_id']);
        $s->index('schimbari_date', 'ix_schimbari_stare', ['stare', 'urmatoarea']);
        $s->coloana('plati', 'schimbare_id', 'bigint null');
        $s->coloana('plati', 'incasata_la', 'moment null');
        $s->coloana('operatii_plata', 'stripe_pi', 'text(255) null');
        $s->coloana('comenzi', 'schimbari', 'int implicit 0');
        $b = $s->baza();
        foreach ($b->toate('SELECT DISTINCT comanda_id FROM {operatii_plata} WHERE stripe_pi IS NULL') as $r) {
            $pi = $b->valoare('SELECT stripe_pi FROM {plati} WHERE comanda_id = ? AND stripe_pi IS NOT NULL ORDER BY suma_incasata > 0 DESC, id DESC LIMIT 1', [$r['comanda_id']]);
            if (is_string($pi) && $pi !== '') {
                $b->executa('UPDATE {operatii_plata} SET stripe_pi = ? WHERE comanda_id = ? AND stripe_pi IS NULL', [$pi, $r['comanda_id']]);
            }
        }
    },
];
