<?php

declare(strict_types=1);

// după termenul de anulare
// gratuită, gazda păstrează avansul plătit la rezervare, ca avans al cazării (nu ca taxă), cel mult cât s-a plătit; tot ce
// s-a plătit peste el se returnează, cu taxa locală. Avansul minim: procentul planului din total, dar cel puțin taxa locală
// plătită online. Exemplele hotărârii: total 1.000 lei = 950 lei cazarea + 50 lei taxa locală, plătită online.

use Cazo\Controlere\Rezerva;
use Cazo\Pret\RegulaPlata;
use Cazo\Rezervare\Anulare;

/** Comanda cu cardul, cum o ține baza: totalul, taxa în componente, avansul ținut și încasarea taxei. */
$comanda = static fn (int $procent, int $avans, ?string $incasare = 'online', int $total = 100000, int $taxa = 5000): array => ['total' => $total,
    'componente' => json_encode(['nopti' => $total - $taxa, 'taxa_locala' => $taxa]), 'avans' => $avans, 'avans_procent' => $procent, 'taxa_incasare' => $incasare];
$plan = static fn (int $procent, ?string $tip = null): array => ['avans_procent' => $procent, 'anulare_zile' => $tip === 'nerambursabil' ? 0 : 7, 'tip' => $tip];
$ro = static fn (): \Cazo\I18n\Traducator => \Cazo\I18n\Traducator::pentru(Mediu::radacina() . '/limbi', 'ro');
$en = static fn (): \Cazo\I18n\Traducator => \Cazo\I18n\Traducator::pentru(Mediu::radacina() . '/limbi', 'en');
/** Ce se păstrează și ce se returnează după termen, din suma plătită: [păstrat, returnat]. */
$dupa = static fn (array $c, int $platit): array => [min($platit, Anulare::retinereDupaTermen($c)), $platit - min($platit, Anulare::retinereDupaTermen($c))];

return [
    'avansul 1%, ridicat la avansul minim de 50 lei: după termen se păstrează 50 (plătit doar avansul: 50/0; plătit tot: 50/950, cu taxa)' => function (Mediu $m) use ($comanda, $plan, $ro, $en, $dupa): void {
        $r = RegulaPlata::calculeaza($plan(1), 100000, null, 5000, 'online', null, true);
        egal([5000, true, 5000, 5000], [$r['avans'], $r['avans_din_taxa'], $r['avans_minim'], $r['retinere']]);
        egal([5000, 0], $dupa($comanda(1, 5000), 5000));
        egal([5000, 95000], $dupa($comanda(1, 5000), 100000));
        // avansul minim în propoziția plății, fără pomenirea taxei locale, în fiecare variantă.
        egal('Acum plătiți un avans de 50 lei: 1% din total, dar cel puțin 50 lei. Restul, 950 lei, îl plătiți la sosire.', Rezerva::avansMinim($ro(), 'ro', $r));
        egal('You now pay a deposit of RON 50: 1% of the total, but at least RON 50. You pay the rest, RON 950, on arrival.', Rezerva::avansMinim($en(), 'en', $r));
        egal('Acum plătiți toată suma, 1.000 lei, din care avansul este de 50 lei: 1% din total, dar cel puțin 50 lei.', Rezerva::avansMinim($ro(), 'ro', $r, 'integral'));
        egal('You now pay the full amount, RON 1,000, of which the deposit is RON 50: 1% of the total, but at least RON 50.', Rezerva::avansMinim($en(), 'en', $r, 'integral'));
        egal('Avansul este de 50 lei: 1% din total, dar cel puțin 50 lei.', Rezerva::avansMinim($ro(), 'ro', $r, 'optiuni'));
        egal('The deposit is RON 50: 1% of the total, but at least RON 50.', Rezerva::avansMinim($en(), 'en', $r, 'optiuni'));
        egal('Plătiți prin transfer bancar, în cel mult 2 zile, un avans de 50 lei: 1% din total, dar cel puțin 50 lei. Restul, 950 lei, îl plătiți la sosire.',
            Rezerva::avansMinim($ro(), 'ro', $r, 'transfer', ['termen' => 'în cel mult 2 zile']));
        foreach (['avans', 'integral', 'optiuni', 'transfer'] as $fel) {
            lipseste((string) Rezerva::avansMinim($ro(), 'ro', $r, $fel, ['termen' => '-']), 'taxa locală');
            lipseste((string) Rezerva::avansMinim($en(), 'en', $r, $fel, ['termen' => '-']), 'local tax');
        }
        egal('Dacă anulați după termenul de anulare gratuită, păstrăm avansul de 50 lei, iar restul sumei plătite, inclusiv taxa locală, vi-l returnăm.',
            Rezerva::retinere($ro(), 'ro', $plan(1), $r, 100000));
        egal('If you cancel after the free cancellation period, we keep the RON 50 deposit and refund the rest of what you paid, including the local tax.',
            Rezerva::retinere($en(), 'en', $plan(1), $r, 100000));
    },

    'avansul 30%: după termen se păstrează avansul de 300 (plătit doar avansul: 300/0; plătit tot: 300/700, cu taxa)' => function (Mediu $m) use ($comanda, $plan, $ro, $dupa): void {
        $r = RegulaPlata::calculeaza($plan(30), 100000, null, 5000, 'online', null, true);
        egal([30000, false, 5000, 30000], [$r['avans'], $r['avans_din_taxa'], $r['avans_minim'], $r['retinere']]);
        egal([30000, 0], $dupa($comanda(30, 30000), 30000));
        egal([30000, 70000], $dupa($comanda(30, 30000), 100000));
        egal('Acum plătiți un avans de 300 lei: 30% din total, dar cel puțin 50 lei. Restul, 700 lei, îl plătiți la sosire.', Rezerva::avansMinim($ro(), 'ro', $r));
        egal('Dacă anulați după termenul de anulare gratuită, păstrăm avansul de 300 lei, iar restul sumei plătite, inclusiv taxa locală, vi-l returnăm.',
            Rezerva::retinere($ro(), 'ro', $plan(30), $r, 100000));
    },

    'avansul 100% și tariful nerambursabil: se păstrează prețul cazării (950), taxa locală (50) se returnează; textele o spun' => function (Mediu $m) use ($comanda, $plan, $ro, $en, $dupa): void {
        $r = RegulaPlata::calculeaza($plan(100), 100000, null, 5000, 'online', null, true);
        egal([100000, 95000], [$r['avans'], $r['retinere']]);
        egal(null, Rezerva::avansMinim($ro(), 'ro', $r), 'fără avans de ales, fără avans minim');
        egal([95000, 5000], $dupa($comanda(100, 100000), 100000));
        egal('Dacă anulați după termenul de anulare gratuită, păstrăm suma plătită pentru cazare, iar taxa locală v-o returnăm.', Rezerva::retinere($ro(), 'ro', $plan(100), $r, 100000));
        $n = RegulaPlata::calculeaza($plan(100, 'nerambursabil'), 100000, null, 5000, 'online', null, true);
        egal('Dacă anulați, păstrăm suma plătită pentru cazare, iar taxa locală v-o returnăm.', Rezerva::retinere($ro(), 'ro', $plan(100, 'nerambursabil'), $n, 100000));
        egal('If you cancel, we keep the amount paid for the accommodation and refund the local tax.', Rezerva::retinere($en(), 'en', $plan(100, 'nerambursabil'), $n, 100000));
        // Partea de taxă plătită online, cel mult suma plătită.
        egal([5000, 3000, 0, 0], [Anulare::taxaOnline($comanda(100, 100000), 100000), Anulare::taxaOnline($comanda(100, 100000), 3000),
            Anulare::taxaOnline($comanda(100, 100000, 'la_sosire'), 100000), Anulare::taxaOnline($comanda(100, 100000, null), 100000)]);
    },

    'taxa „la_sosire” și comenzile de dinaintea alegerii: nimic schimbat (se reține avansul ținut, textele de până acum, fără avans minim)' => function (Mediu $m) use ($comanda, $plan, $ro): void {
        egal(28500, Anulare::retinereDupaTermen($comanda(30, 28500, 'la_sosire')));
        egal(100000, Anulare::retinereDupaTermen($comanda(100, 100000, null)), 'comanda de dinainte de 0026: regula veche');
        $r = RegulaPlata::calculeaza($plan(30), 100000, null, 5000, 'la_sosire', null, true);
        egal([$r['avans'], 0], [$r['retinere'], $r['avans_minim']]);
        egal(null, Rezerva::avansMinim($ro(), 'ro', $r));
        egal('Dacă anulați după termenul de anulare gratuită, păstrăm avansul de 285 lei (30% din prețul cazării), iar restul sumei plătite vi-l returnăm.',
            Rezerva::retinere($ro(), 'ro', $plan(30), $r, 100000));
        $n = RegulaPlata::calculeaza($plan(100, 'nerambursabil'), 100000, null, 5000, 'la_sosire', null, true);
        egal('Dacă anulați, păstrăm toată suma plătită.', Rezerva::retinere($ro(), 'ro', $plan(100, 'nerambursabil'), $n, 100000));
        // Fără taxă locală: ca până acum.
        $f = RegulaPlata::calculeaza($plan(30), 100000, null, 0, 'online', null, true);
        egal([30000, 30000, 0], [$f['avans'], $f['retinere'], $f['avans_minim']]);
    },

    'avansul minim la ban (un EasyCheck de dinaintea sumelor rotunde): exact taxa; la sumele rotunde, leul întreg de deasupra ei' => function (Mediu $m): void {
        $p = ['avans_procent' => 1, 'anulare_zile' => 7, 'tip' => null];
        egal([4999, 4999], array_values(array_intersect_key(RegulaPlata::calculeaza($p, 100050, null, 4999), ['avans' => 1, 'avans_minim' => 1])));
        egal([5000, 5000], array_values(array_intersect_key(RegulaPlata::calculeaza($p, 100050, null, 4999, 'online', null, true), ['avans' => 1, 'avans_minim' => 1])));
    },
    'după o mutare, alegerea gazdei: total 1.000 → 1.200, avans 30%: recalculat 360, plătit la rezervare 300; cel mult cât s-a plătit, cel puțin avansul minim' => function (Mediu $m) use ($dupa): void {
        $mutata = static fn (int $avansInitial): array => ['total' => 120000, 'componente' => json_encode(['nopti' => 115000, 'taxa_locala' => 5000]), 'avans' => 36000,
            'avans_procent' => 30, 'taxa_incasare' => 'online', 'avans_initial' => $avansInitial];
        egal(36000, Anulare::retinereDupaTermen($mutata(30000)));
        egal(36000, Anulare::retinereDupaTermen($mutata(30000), Anulare::MUTARE_RECALCULAT));
        egal(30000, Anulare::retinereDupaTermen($mutata(30000), Anulare::MUTARE_INITIAL));
        // Plătit tot (1.200): 360/840, respectiv 300/900; plătit doar avansul de 300 (avansul rămâne): 300/0 în ambele.
        $c = $mutata(30000);
        egal([[36000, 84000], [30000, 0]], [$dupa($c, 120000), $dupa($c, 30000)]);
        egal([30000, 90000], [min(120000, Anulare::retinereDupaTermen($c, Anulare::MUTARE_INITIAL)), 120000 - min(120000, Anulare::retinereDupaTermen($c, Anulare::MUTARE_INITIAL))]);
        // Avansul inițial sub avansul minim al noului total (taxa de 50 lei): cel puțin avansul minim.
        egal(5000, Anulare::retinereDupaTermen($mutata(3000), Anulare::MUTARE_INITIAL));
        // Fără mutare (`avans_initial` lipsă), alegerea nu schimbă nimic.
        $nemutata = ['avans_initial' => null] + $mutata(0);
        egal(36000, Anulare::retinereDupaTermen($nemutata, Anulare::MUTARE_INITIAL));
    },
];
