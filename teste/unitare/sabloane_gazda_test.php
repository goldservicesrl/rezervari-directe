<?php

declare(strict_types=1);

// catalogul mesajelor, gărzile pe cazurile comune cu EasyCheck
// (teste/fixturi/mesaje-validare-cazuri.json) și completarea unui șablon: câmpurile, paragraful cu un câmp gol lăsat
// deoparte, blocurile (lipite de frază, între paragrafe, goale), textul simplu.

use Cazo\Email\SabloaneGazda;

$cazuri = static fn (): array => json_decode((string) file_get_contents(Mediu::radacina() . '/teste/fixturi/mesaje-validare-cazuri.json'), true, 512, JSON_THROW_ON_ERROR);

return [
    'catalogul: 32 de mesaje (cele 31 de azi fără `cod`, plus restituire_transfer), aceleași cu Sabloane::OASPETE' => function (): void {
        egal(32, count(SabloaneGazda::CATALOG));
        $oaspete = array_values(array_diff(\Cazo\Email\Sabloane::OASPETE, ['cod']));
        sort($oaspete);
        $chei = SabloaneGazda::chei();
        sort($chei);
        egal($oaspete, $chei);
        egal(\Cazo\Controlere\AdminContinut::SABLOANE, SabloaneGazda::chei(), 'paragraful din panou la toate mesajele, în ordinea catalogului');
        foreach (SabloaneGazda::CATALOG as $k => $c) {
            foreach ([...$c['o'], ...$c['p']] as $x) {
                adevarat($x === 'suma' || in_array($x, SabloaneGazda::BLOCURI, true), "$k: $x");
            }
            foreach ($c['c'] as $x) {
                adevarat(in_array($x, SabloaneGazda::CAMPURI_MESAJ, true), "$k: $x");
            }
            if (in_array('suma', $c['o'], true)) {
                adevarat(in_array('suma', $c['c'], true), "$k: {suma} obligatoriu dar nepermis");
            }
        }
    },

    'gărzile: fiecare caz din fișierul comun cu EasyCheck (copiat întocmai din scripts/fixturi/ al EasyCheck)' => function () use ($cazuri): void {
        $f = $cazuri();
        adevarat(count($f['cazuri']) >= 100);
        $vazute = [];
        foreach ($f['cazuri'] as $c) {
            $corp = isset($c['corp_repeta']) ? str_repeat($c['corp_repeta']['text'], $c['corp_repeta']['ori']) : $c['corp'];
            $g = SabloaneGazda::valideaza($c['mesaj'], $c['subiect'], $corp, $c['domenii']);
            egal([$c['asteptat']['valid'], $c['asteptat']['garzi']], [$g === [], $g], $c['nume']);
            foreach ($g ?: ['valid'] as $x) {
                $vazute[$x] = true;
            }
        }
        // Fiecare gardă are cel puțin un caz.
        foreach ([...SabloaneGazda::GARZI, 'valid'] as $x) {
            adevarat(isset($vazute[$x]), "garda $x fără caz");
        }
    },

    'gărzile: fiecare mesaj cu blocuri obligatorii le cere, iar cu ele trece' => function (): void {
        foreach (SabloaneGazda::CATALOG as $k => $c) {
            $obl = array_values(array_filter($c['o'], static fn (string $x): bool => $x !== 'suma'));
            $corp = implode("\n\n", array_map(static fn (string $b): string => '{' . $b . '}', $obl)) . (in_array('suma', $c['o'], true) ? "\n\nSuma: {suma}." : '');
            $corp = trim($corp) === '' ? 'Bună ziua, {oaspete}.' : $corp;
            egal([], SabloaneGazda::valideaza($k, 'Rezervarea {ref}', $corp, ['exemplu.ro']), $k);
            if ($c['o'] !== []) {
                egal(['bloc_lipsa'], SabloaneGazda::valideaza($k, 'Rezervarea {ref}', 'Bună ziua, {oaspete}.', ['exemplu.ro']), $k);
            }
        }
    },

    'completarea: câmpurile, paragraful cu un câmp gol lăsat deoparte, rândurile din paragraf' => function (): void {
        $campuri = ['oaspete' => 'Ana', 'proprietate' => 'Casa', 'rest' => '', 'contact_gazda' => ''];
        $s = SabloaneGazda::corp("Bună ziua, {oaspete},\n\nRestul de {rest} îl plătiți la sosire.\n\nNe găsiți la {contact_gazda}.\n\nCu drag,\n{proprietate}", $campuri, []);
        egal([['tip' => 'p', 'linii' => ['Bună ziua, Ana,']], ['tip' => 'p', 'linii' => ['Cu drag,', 'Casa']]], $s);
        egal("Bună ziua, Ana,\n\nCu drag,\nCasa", SabloaneGazda::text($s));
        // Un câmp gol în subiect rămâne gol (subiectul nu se lasă deoparte); spațiile duble se strâng.
        egal('Rezervarea la Casa', SabloaneGazda::subiect('Rezervarea {ref} la {proprietate}', ['ref' => '', 'proprietate' => 'Casa']));
        // Valorile rămân text: o acoladă în valoare nu devine câmp (completarea e o singură trecere).
        egal('Bună {bloc_transfer} <script>', SabloaneGazda::text(SabloaneGazda::corp('Bună {oaspete}', ['oaspete' => '{bloc_transfer} <script>'], ['bloc_transfer' => [['p', 'IBAN RO49']]])));
    },

    'blocurile: lipite de frază continuă propoziția, între paragrafe își scriu bucățile, goale dispar, nu se pierd din cauza unui câmp gol' => function (): void {
        $b = ['bloc_plata' => [['p', 'Ați plătit 300 lei.']], 'bloc_contract' => [['p', 'Acest e-mail confirmă contractul.'], ['detalii', [['Cod', 'PG-1']]], ['sectiune', 'Informațiile', ['Unu.', 'Doi.']]],
            'bloc_restituire' => []];
        $s = SabloaneGazda::corp("Mulțumim. Rezervarea e confirmată.\n{bloc_plata}\n\n{bloc_contract}\n\n{bloc_restituire}\n\nPe curând", [], $b);
        egal([
            ['tip' => 'p', 'linii' => ['Mulțumim. Rezervarea e confirmată. Ați plătit 300 lei.']],
            ['tip' => 'p', 'linii' => ['Acest e-mail confirmă contractul.']],
            ['tip' => 'detalii', 'randuri' => [['Cod', 'PG-1']]],
            ['tip' => 'titlu', 'text' => 'Informațiile'],
            ['tip' => 'p', 'linii' => ['Unu.'], 'mic' => true],
            ['tip' => 'p', 'linii' => ['Doi.'], 'mic' => true],
            ['tip' => 'p', 'linii' => ['Pe curând']],
        ], $s);
        // Paragraful cu un câmp gol pierde doar textul; blocul obligatoriu din el rămâne.
        $s = SabloaneGazda::corp("Restul: {rest}.\n{bloc_plata}", ['rest' => ''], $b);
        egal([['tip' => 'p', 'linii' => ['Ați plătit 300 lei.']]], $s);
        // Un bloc singur în paragraf, doar din fraze: fiecare frază e un paragraf.
        egal([['tip' => 'p', 'linii' => ['A.']], ['tip' => 'p', 'linii' => ['B.']]], SabloaneGazda::corp('{bloc_motiv}', [], ['bloc_motiv' => [['p', 'A.'], ['p', 'B.']]]));
    },

    'garda IBAN: forma, nu orice șir lung; garda link: domeniul și subdomeniile lui' => function (): void {
        adevarat(SabloaneGazda::areIban('IBAN: RO49 AAAA 1B31 0075 9384 0000'));
        adevarat(SabloaneGazda::areIban('GB82WEST12345698765432'));
        adevarat(SabloaneGazda::areIban("RO49\u{200B}AAAA1B31007593840000"), 'un caracter invizibil nu ascunde contul');
        fals(SabloaneGazda::areIban('Camera 12 are vedere spre lac.'));
        fals(SabloaneGazda::areIban('CUI RO12345678 din Brașov'));
        fals(SabloaneGazda::areLinkStrain('Scrieți la ana@yahoo.com sau vedeți https://casa.ro/x și sub.casa.ro.', ['casa.ro']));
        adevarat(SabloaneGazda::areLinkStrain('https://casa.ro.rau.com/', ['casa.ro']), 'un domeniu care doar începe cu al site-ului');
        adevarat(SabloaneGazda::areLinkStrain('http://alt.ro', ['casa.ro']));
        adevarat(SabloaneGazda::areLinkStrain('CASA-RAU.COM', ['casa.ro']));
    },
];
