<?php

declare(strict_types=1);

// Piesele plății prin transfer bancar (`unitare`): IBAN-ul (forma, lungimea țării, cifrele de control),
// codul SWIFT/BIC, validarea contului și a regulilor de timp, modelul metodelor de plată cu trei metode independente
// (bifele, numele combinațiilor, valorile de dinaintea migrării) și migrarea 0038.

use Cazo\Rezervare\ContTransfer;
use Cazo\Rezervare\Iban;
use Cazo\Rezervare\ModPlata;
use Cazo\Rezervare\Transfer;

/** Un IBAN cu cifrele de control calculate (ISO 7064, mod 97-10), pentru o țară și un BBAN date. */
$iban = static function (string $tara, string $bban): string {
    $rest = static function (string $s): int {
        $r = 0;
        foreach (str_split($s) as $c) {
            foreach (str_split(ctype_digit($c) ? $c : (string) (ord($c) - 55)) as $cifra) {
                $r = ($r * 10 + (int) $cifra) % 97;
            }
        }
        return $r;
    };
    return $tara . str_pad((string) (98 - $rest($bban . $tara . '00')), 2, '0', STR_PAD_LEFT) . $bban;
};

return [
    'IBAN: forma, lungimea țării și cifrele de control; românesc și din alte țări' => function (Mediu $m) use ($iban): void {
        adevarat(Iban::valid('RO49AAAA1B31007593840000'), 'IBAN-ul românesc de probă');
        adevarat(Iban::valid('ro49 aaaa 1b31 0075 9384 0000'), 'cu spații și litere mici');
        adevarat(Iban::valid("RO49\u{00A0}AAAA-1B31.0075 9384 0000"), 'cu spații neseparabile, liniuțe și puncte');
        egal('RO49AAAA1B31007593840000', Iban::curata(' ro49 aaaa 1b31 0075 9384 0000 '));
        egal('RO49 AAAA 1B31 0075 9384 0000', Iban::grupat('RO49AAAA1B31007593840000'));
        // Alte țări, cu lungimea lor.
        adevarat(Iban::valid('GB82WEST12345698765432'));
        adevarat(Iban::valid('DE89370400440532013000'));
        adevarat(Iban::valid('MD24AG000225100013104168'));
        adevarat(Iban::valid($iban('RO', 'BTRL0000000000000000')), 'cifrele de control calculate');
        // O țară din afara tabelului trece cu forma generală și cifrele de control bune.
        adevarat(!isset(Iban::LUNGIMI['ZZ']));
        adevarat(Iban::valid($iban('ZZ', '00123456789012345')));
        // Greșelile.
        fals(Iban::valid('RO50AAAA1B31007593840000'), 'cifrele de control greșite');
        fals(Iban::valid('RO49AAAA1B3100759384000'), 'un caracter lipsă');
        fals(Iban::valid('RO49AAAA1B310075938400000'), 'un caracter în plus');
        fals(Iban::valid(substr($iban('RO', 'BTRL0000000000000000'), 0, 4) . '1TRL0000000000000000'), 'codul băncii românești are patru litere');
        fals(Iban::valid('GB82WEST1234569876543'), 'lungimea țării');
        fals(Iban::valid('GB82WEST1234569876543!'), 'semne străine');
        fals(Iban::valid(''));
        fals(Iban::valid('4900AAAA1B31007593840000'), 'fără codul țării');
        fals(Iban::valid($iban('ZZ', '0012345678')), 'prea scurt');
        fals(Iban::valid($iban('ZZ', str_repeat('1', 31))), 'peste 34 de caractere');
        // SWIFT/BIC: 8 sau 11 caractere.
        adevarat(Iban::swiftValid('BTRLRO22'));
        adevarat(Iban::swiftValid('btrlro22xxx'));
        fals(Iban::swiftValid('BTRLRO2'));
        fals(Iban::swiftValid('BTRLRO22XX'));
        fals(Iban::swiftValid('1TRLRO22'));
    },

    'contul transferului: titularul și IBAN-ul obligatorii cu bifa; termenul 12-120 de ore (implicit 48), zilele 0-30 (implicit 3)' => function (Mediu $m): void {
        $bun = ['titular' => ' Demo Turism SRL ', 'iban' => 'ro49 aaaa 1b31 0075 9384 0000', 'banca' => 'Banca Demo', 'swift' => 'btrlro22', 'termen_ore' => '72', 'zile_inainte' => '5'];
        $r = ContTransfer::valideaza($bun, true);
        egal([], $r['erori']);
        egal(['titular' => 'Demo Turism SRL', 'iban' => 'RO49AAAA1B31007593840000', 'banca' => 'Banca Demo', 'swift' => 'BTRLRO22', 'termen_ore' => 72, 'zile_inainte' => 5], $r['valori']);
        // Valorile implicite, când câmpurile rămân goale.
        $r = ContTransfer::valideaza(['titular' => 'A', 'iban' => 'RO49AAAA1B31007593840000'], true);
        egal([], $r['erori']);
        egal([ContTransfer::TERMEN_IMPLICIT, ContTransfer::ZILE_IMPLICIT, '', ''], [$r['valori']['termen_ore'], $r['valori']['zile_inainte'], $r['valori']['banca'], $r['valori']['swift']]);
        egal([48, 3], [ContTransfer::TERMEN_IMPLICIT, ContTransfer::ZILE_IMPLICIT]);
        // Bifa cere titularul și IBAN-ul.
        egal(['titular' => 'admin.transfer.eroare.titular', 'iban' => 'admin.transfer.eroare.iban_lipsa'], ContTransfer::valideaza([], true)['erori']);
        egal(['iban' => 'admin.transfer.eroare.iban'], ContTransfer::valideaza(['iban' => 'RO50AAAA1B31007593840000'] + $bun, true)['erori']);
        // Fără bifă, contul se poate salva și gol, dar un IBAN scris trebuie să fie corect.
        egal([], ContTransfer::valideaza([], false)['erori']);
        egal(['iban' => 'admin.transfer.eroare.iban'], ContTransfer::valideaza(['iban' => 'RO50AAAA1B31007593840000'], false)['erori']);
        egal(['swift' => 'admin.transfer.eroare.swift'], ContTransfer::valideaza(['swift' => 'ABC'] + $bun, true)['erori']);
        // Limitele: 12-120 de ore, 0-30 de zile.
        foreach (['12' => true, '120' => true, '11' => false, '121' => false, '0' => false, '-5' => false, 'abc' => false, '48.5' => false] as $v => $ok) {
            egal($ok ? [] : ['termen_ore' => 'admin.transfer.eroare.termen_ore'], ContTransfer::valideaza(['termen_ore' => (string) $v] + $bun, true)['erori'], "termenul $v");
        }
        foreach (['0' => true, '30' => true, '31' => false, '-1' => false, 'x' => false] as $v => $ok) {
            egal($ok ? [] : ['zile_inainte' => 'admin.transfer.eroare.zile_inainte'], ContTransfer::valideaza(['zile_inainte' => (string) $v] + $bun, true)['erori'], "zilele $v");
        }
        // Caracterele de control ies, iar lungimile se taie.
        $r = ContTransfer::valideaza(['titular' => "Ana\x00Pop\r\n", 'banca' => str_repeat('b', 300)] + $bun, true);
        egal('Ana Pop', $r['valori']['titular']);
        egal(120, mb_strlen($r['valori']['banca']));
    },

    'modelul metodelor: trei bife independente, numele combinațiilor, valorile de dinaintea migrării' => function (Mediu $m): void {
        egal(['card', 'cash', 'transfer'], ModPlata::METODE);
        // Bifele: fiecare combinație are numele ei; fără nicio bifă, null.
        $combinatii = [[true, false, false, 'card'], [false, true, false, 'cash'], [true, true, false, 'ambele'], [false, false, true, 'transfer'],
            [true, false, true, 'card_transfer'], [false, true, true, 'cash_transfer'], [true, true, true, 'toate'], [false, false, false, null]];
        foreach ($combinatii as [$card, $cash, $transfer, $nume]) {
            egal($nume, ModPlata::dinBife($card, $cash, $transfer));
            egal(['card' => $card, 'cash' => $cash, 'transfer' => $transfer], ModPlata::bife($nume));
        }
        // Apelurile de dinaintea transferului rămân valabile.
        egal('ambele', ModPlata::dinBife(true, true));
        egal(null, ModPlata::dinBife(false, false));
        // Lista curată: ordinea fixă, valorile străine ies, numele vechi se citesc.
        egal(['card', 'transfer'], ModPlata::normalizeaza(['transfer', 'card', 'bitcoin', 'card']));
        egal(['card', 'cash'], ModPlata::normalizeaza('ambele'));
        egal(['cash'], ModPlata::normalizeaza('cash'));
        egal([], ModPlata::normalizeaza('altceva'));
        egal([], ModPlata::normalizeaza(null));
        egal(['card' => true, 'cash' => false, 'transfer' => true], ModPlata::bife(['transfer', 'card']));
        egal('cash_transfer', ModPlata::cheie(['transfer', 'cash']));
        egal(null, ModPlata::cheie([]));

        $app = $m->aplicatie();
        $mod = $app->modPlata();
        egal([], $mod->lista());
        egal(null, $mod->metode());
        // O valoare de dinaintea migrării 0038 (card / cash / ambele) se citește la fel.
        $app->setari()->pune('plati.metode', 'ambele');
        egal(['card', 'cash'], $mod->lista());
        egal('ambele', $mod->metode());
        adevarat($mod->are('card') && $mod->are('cash') && !$mod->are('transfer'));
        adevarat($mod->vreaCard() && $mod->incaseazaInainte());
        $app->setari()->pune('plati.metode', ['cash']);
        fals($mod->incaseazaInainte());
        // Transferul cere contul complet, verificat și pe server.
        arunca(fn () => $mod->alegeMetode(['cash', 'transfer']), \DomainException::class);
        arunca(fn () => $mod->alegeMetode('cash_transfer'), \DomainException::class);
        egal(['cash'], $mod->lista());
        $app->contTransfer()->salveaza(ContTransfer::valideaza(['titular' => 'Demo Turism SRL', 'iban' => 'RO49AAAA1B31007593840000'], true)['valori']);
        adevarat($app->contTransfer()->complet());
        $mod->alegeMetode(['transfer', 'cash']);
        egal(['cash', 'transfer'], $mod->lista());
        egal(['cash', 'transfer'], $app->setari()->get('plati.metode'), 'setarea ține lista metodelor');
        egal('cash_transfer', $mod->metode());
        adevarat($mod->incaseazaInainte() && !$mod->vreaCard());
        // Bifa unei singure metode, celelalte rămânând cum sunt; fără nicio metodă, nimic salvat.
        adevarat($mod->comuta('cash', false));
        egal(['transfer'], $mod->lista());
        fals($mod->comuta('transfer', false));
        egal(['transfer'], $mod->lista());
        adevarat($mod->comuta('cash', true));
        egal(['cash', 'transfer'], $mod->lista());
        // Valorile necunoscute.
        arunca(fn () => $mod->alegeMetode('bitcoin'), \InvalidArgumentException::class);
        arunca(fn () => $mod->alegeMetode(['cash', 'bitcoin']), \InvalidArgumentException::class);
        arunca(fn () => $mod->alegeMetode([]), \InvalidArgumentException::class);
        // Cardul cere Stripe configurat, ca până acum.
        arunca(fn () => $mod->alegeMetode(['card', 'transfer']), \DomainException::class);
    },

    'migrarea 0038: card, cash și ambele devin lista metodelor; lista și lipsa alegerii rămân neatinse' => function (Mediu $m): void {
        $b = $m->baza();
        $migrare = require Mediu::radacina() . '/migrari/0038_metode_plata.php';
        $dupa = static function (mixed $vechi) use ($b, $migrare): mixed {
            $b->executa("DELETE FROM {setari} WHERE cheie = 'plati.metode'");
            if ($vechi !== '-') {
                $b->executa('INSERT INTO {setari} (cheie, valoare, actualizat_la) VALUES (?, ?, ?)', ['plati.metode', json_encode($vechi), '2026-11-20 10:00:00']);
            }
            ($migrare['ruleaza'])(new \Cazo\Baza\Schema($b));
            // A doua rulare lasă valoarea cum e.
            ($migrare['ruleaza'])(new \Cazo\Baza\Schema($b));
            $v = $b->valoare("SELECT valoare FROM {setari} WHERE cheie = 'plati.metode'");
            return $v === null ? '-' : json_decode((string) $v, true);
        };
        egal(['card'], $dupa('card'));
        egal(['cash'], $dupa('cash'));
        egal(['card', 'cash'], $dupa('ambele'));
        egal(['cash', 'transfer'], $dupa(['cash', 'transfer']), 'o listă rămâne neatinsă');
        egal(null, $dupa(null), 'alegerea ștearsă rămâne ștearsă');
        egal('-', $dupa('-'), 'fără setare, nimic scris');
        // Aplicația citește valoarea migrată.
        $dupa('ambele');
        $app = $m->aplicatie();
        egal(['card', 'cash'], $app->modPlata()->lista());
        egal('ambele', $app->modPlata()->metode());
        // Coloanele migrării 0039 există pe comenzi.
        foreach (['transfer_termen', 'transfer_reamintire_la', 'transfer_cont'] as $c) {
            adevarat($b->coloanaExista('comenzi', $c), $c);
        }
    },

    'corpul rezervării cu transfer: plata_asteptata în loc de plata, cu termenul în UTC și codul comenzii ca referință' => function (Mediu $m): void {
        $termen = gmmktime(16, 0, 0, 11, 23, 2026);
        egal(['metoda' => 'transfer', 'suma' => 43500, 'termen' => '2026-11-23T16:00:00Z', 'referinta' => 'PG-7K3M9Q'], Transfer::plataAsteptata(43500, $termen, 'PG-7K3M9Q'));
        $d = ['camere' => [['camera' => 'cam_1', 'adulti' => 2, 'copii' => 0]], 'sosire' => '2026-12-20', 'plecare' => '2026-12-23', 'plan' => 'plan_std', 'total' => 145000,
            'oaspete' => ['nume' => 'Ana Pop', 'email' => 'ana@example.com', 'telefon' => '+40722000000', 'tara' => 'RO'], 'include' => ['taxe' => true, 'taxa_locala' => true],
            'taxa_locala_incasare' => 'la_sosire', 'transfer' => ['suma' => 43500, 'termen' => $termen]];
        $corp = json_decode(\Cazo\Rezervare\Comenzi::corpEc($d, 'PG-7K3M9Q'), true);
        egal(['metoda' => 'transfer', 'suma' => 43500, 'termen' => '2026-11-23T16:00:00Z', 'referinta' => 'PG-7K3M9Q'], $corp['plata_asteptata']);
        fals(array_key_exists('plata', $corp), 'plata și plata_asteptata nu merg împreună');
        egal(['PG-7K3M9Q', 145000, 'la_sosire'], [$corp['ref_site'], $corp['total'], $corp['taxa_locala_incasare']]);
        // Fără transfer, corpul rămâne cel de până acum.
        unset($d['transfer']);
        fals(array_key_exists('plata_asteptata', json_decode(\Cazo\Rezervare\Comenzi::corpEc($d, 'PG-7K3M9Q'), true)));
        // Mașina de stări a ramurii.
        adevarat(\Cazo\Rezervare\MasinaStari::permisa('transfer', 'trimitere_ec', 'transfer_in_asteptare'));
        foreach (['confirmata', 'transfer_neprimit', 'transfer_expirat', 'anulata_oaspete', 'anulata_gazda'] as $in) {
            adevarat(\Cazo\Rezervare\MasinaStari::permisa('transfer', 'transfer_in_asteptare', $in), $in);
        }
        fals(\Cazo\Rezervare\MasinaStari::permisa('transfer', 'transfer_expirat', 'confirmata'), 'o comandă închisă rămâne închisă');
        fals(\Cazo\Rezervare\MasinaStari::permisa('cash', 'cerere_in_asteptare', 'transfer_in_asteptare'));
    },
];
