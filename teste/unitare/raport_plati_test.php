<?php

declare(strict_types=1);

// piesele fără rețea ale rapoartelor plăților: starea contestării pentru EasyCheck, câmpurile contestării păstrate din
// anunțul Stripe (fără date personale), regula cheii comenzii și regulile EasyCheck-ului fals (cheia naturală,
// eliberările parțiale, `la` mai vechi, plata eliberată, câmpurile primite de fiecare tip).

use Cazo\Plati\ContStripe;
use Cazo\Plati\Contestari;

return [
    'starea contestării pentru EasyCheck: deschisă cât banca ține banii; întrebările băncii nu pleacă' => function (Mediu $m): void {
        egal(['deschisa', 'deschisa', 'castigata', 'pierduta', null, null, null], array_map([Contestari::class, 'stareEc'],
            ['needs_response', 'under_review', 'won', 'lost', 'warning_needs_response', 'warning_closed', 'altceva']));
    },

    'anunțul unei contestări păstrează suma, starea, motivul, termenul și tranzacțiile, fără datele oaspetelui' => function (Mediu $m): void {
        $d = ContStripe::dateMinime(['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_1', 'amount' => 43500, 'status' => 'needs_response', 'reason' => 'fraudulent',
            'created' => 1_800_000_000, 'evidence_details' => ['due_by' => 1_800_900_000, 'customer_name' => 'Ion Popescu'], 'evidence' => ['customer_email_address' => 'ion@exemplu.ro'],
            'balance_transactions' => [['id' => 'txn_1', 'fee' => 1500, 'created' => 1_800_000_005, 'description' => 'x']]]);
        egal(['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_1', 'amount' => 43500, 'status' => 'needs_response', 'reason' => 'fraudulent', 'created' => 1_800_000_000,
            'evidence_details' => ['due_by' => 1_800_900_000], 'balance_transactions' => [['id' => 'txn_1', 'fee' => 1500, 'created' => 1_800_000_005]]], $d);
        lipseste(json_encode($d), 'Ion');
        // Celelalte obiecte rămân ca înainte.
        egal(['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1'], ContStripe::dateMinime(['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'amount' => 5]));
    },

    'cheia comenzii: aceeași cheie sau una legată lucrează pe rezervare; alta, nu; comenzile fără prefix urmează rotirea cheii' => function (Mediu $m): void {
        $app = $m->aplicatie();
        $cheie = 'ec_test_Acum0001_abcdefghijklmnopqrstuvwxyzABCDEF';
        $app->secrete()->pune('ec_cheie', $cheie);
        $con = $app->ecConectare();
        fals($con->comandaStraina(['cheie_prefix' => 'Acum0001'], $cheie));
        adevarat($con->comandaStraina(['cheie_prefix' => 'Veche001'], $cheie));
        $app->setari()->pune(\Cazo\EasyCheck\Conectare::LEGATURI, ['Acum0001' => ['inlocuieste' => 'Veche001']]);
        fals($con->comandaStraina(['cheie_prefix' => 'Veche001'], $cheie));
        $pusa = (string) $app->secrete()->schimbatLa('ec_cheie');
        fals($con->comandaStraina(['cheie_prefix' => null, 'platita_la' => $pusa], $cheie));
        adevarat($con->comandaStraina(['cheie_prefix' => null, 'platita_la' => '2020-01-01 00:00:00'], $cheie));
    },

    'EasyCheck-ul fals: eliberări parțiale succesive, plata eliberată, `la` mai vechi, câmpuri la tipul greșit, returnările peste plăți' => function (Mediu $m): void {
        $acum = 1_800_000_000;
        $la = static fn (int $d = 0): string => gmdate('Y-m-d\TH:i:s\Z', $acum + $d);
        $r = ['id' => 'rez_' . str_repeat('A', 24)];
        egal([200, ['platit' => 30000, 'rambursat' => 0]], (static fn (array $x): array => [$x[0], $x[1]['plati']])(FalsPlati::aplica($r, 43500, ['tip' => 'incasare_anulata', 'ref' => 'pi_a', 'suma' => 13500, 'la' => $la()], $acum)));
        [$st, $j] = FalsPlati::aplica($r, 43500, ['tip' => 'incasare_anulata', 'ref' => 'pi_b', 'suma' => 40000, 'la' => $la()], $acum);
        egal([422, 30000], [$st, $j['eroare']['detalii']['maxim']]);
        egal(200, FalsPlati::aplica($r, 43500, ['tip' => 'incasare_anulata', 'ref' => 'pi_b', 'la' => $la()], $acum)[0]);
        egal([409, 'fara_plata_card'], (static fn (array $x): array => [$x[0], $x[1]['eroare']['detalii']['motiv']])(FalsPlati::aplica($r, 43500, ['tip' => 'incasare_anulata', 'ref' => 'pi_c', 'la' => $la()], $acum)));
        egal([409, 'plata_eliberata'], (static fn (array $x): array => [$x[0], $x[1]['eroare']['detalii']['motiv']])(FalsPlati::aplica($r, 43500, ['tip' => 'incasare_confirmata', 'ref' => 'pi_d', 'la' => $la()], $acum)));
        // O rambursare se primește la orice rezervare a cheii; peste plăți: `avertizare`.
        [$st, $j] = FalsPlati::aplica($r, 43500, ['tip' => 'rambursare', 'suma' => 1000, 'stare' => 'reusita', 'ref' => 're_1', 'la' => $la(10)], $acum + 10);
        egal([200, ['platit' => -1000, 'rambursat' => 1000], 'peste_platit'], [$st, $j['plati'], $j['avertizare']]);
        [$st, $j] = FalsPlati::aplica($r, 43500, ['tip' => 'rambursare', 'suma' => 1000, 'stare' => 'esuata', 'ref' => 're_1', 'la' => $la(5)], $acum + 10);
        egal([200, false, -1000], [$st, $j['inregistrat'], $j['plati']['platit']]);
        [$st, $j] = FalsPlati::aplica($r, 43500, ['tip' => 'contestare', 'suma' => 1000, 'stare' => 'deschisa', 'ref' => 'dp_1', 'la' => $la(), 'comision' => 3, 'termen' => 'mâine'], $acum);
        egal([422, ['comision', 'termen']], [$st, $j['eroare']['detalii']['campuri']]);
        egal(422, FalsPlati::aplica($r, 43500, ['tip' => 'rambursare', 'suma' => 1000, 'stare' => 'reusita', 'ref' => 're_2', 'la' => $la(3600)], $acum)[0], '`la` prea departe în viitor');
    },
];
