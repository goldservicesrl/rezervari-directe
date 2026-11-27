<?php

declare(strict_types=1);

// Pluralul, formatarea banilor și a datelor, traducătorul.

use Cazo\I18n\Format;
use Cazo\I18n\Plural;
use Cazo\I18n\Traducator;

return [
    'pluralul românesc: 1 noapte, 2 nopți, 20 de nopți, 101 nopți' => function (Mediu $m): void {
        $t = Traducator::pentru(Mediu::radacina() . '/limbi', 'ro');
        $asteptat = [0 => '0 nopți', 1 => '1 noapte', 2 => '2 nopți', 19 => '19 nopți', 20 => '20 de nopți', 21 => '21 de nopți',
            100 => '100 de nopți', 101 => '101 nopți', 102 => '102 nopți', 119 => '119 nopți', 120 => '120 de nopți', 1000 => '1.000 de nopți', 1001 => '1.001 nopți'];
        foreach ($asteptat as $n => $text) {
            egal($text, $t->plural('unitate.nopti', $n), (string) $n);
        }
        egal('1 adult', $t->plural('unitate.adulti', 1));
        egal('3 adulți', $t->plural('unitate.adulti', 3));
        egal('20 de copii', $t->plural('unitate.copii', 20));
    },

    'pluralul englez' => function (Mediu $m): void {
        $t = Traducator::pentru(Mediu::radacina() . '/limbi', 'en');
        egal('1 night', $t->plural('unitate.nopti', 1));
        egal('0 nights', $t->plural('unitate.nopti', 0));
        egal('1,450 nights', $t->plural('unitate.nopti', 1450));
        egal('2 children', $t->plural('unitate.copii', 2));
        egal(['unu', 'multe'], Plural::forme('en'));
        egal(['unu', 'cateva', 'multe'], Plural::forme('ro'));
    },

    'literele cu sedilă devin litere cu virguliță, restul textului rămâne' => function (Mediu $m): void {
        egal('BRĂILA, Șos. Buzăului, Țiglina, județul Galați', Format::diacritice("BRĂILA, \u{015E}os. Buzăului, \u{0162}iglina, jude\u{0163}ul Gala\u{0163}i"));
        egal('Ștefan și Țuțu', Format::diacritice("\u{015E}tefan \u{015F}i \u{0162}u\u{0163}u"));
        egal('Șoseaua', Format::diacritice('Șoseaua'));
    },

    'banii: RO „1.450,00 lei”, EN „RON 1,450.00”' => function (Mediu $m): void {
        egal('1.450,00 lei', Format::bani(145000, 'ro'));
        egal('RON 1,450.00', Format::bani(145000, 'en'));
        egal('0,05 lei', Format::bani(5, 'ro'));
        egal('1.234.567,89 lei', Format::bani(123456789, 'ro'));
        egal('-12,30 lei', Format::bani(-1230, 'ro'));
        egal('RON 0.00', Format::bani(0, 'en'));
    },

    'datele: RO „20 dec. 2026”, EN „20 Dec 2026”, fusul României' => function (Mediu $m): void {
        egal('20 dec. 2026', Format::zi('2026-12-20', 'ro'));
        egal('20 Dec 2026', Format::zi('2026-12-20', 'en'));
        egal('1 sept. 2026', Format::zi('2026-09-01', 'ro'));
        egal('5 mai 2026', Format::zi('2026-05-05', 'ro'));
        arunca(fn () => Format::zi('2026-02-30', 'ro'), \InvalidArgumentException::class);
        // 22:30 UTC iarna = 00:30 a doua zi în România; vara diferența e de 3 ore.
        egal('1 ian. 2027, 00:30', Format::moment('2026-12-31 22:30:00', 'ro'));
        egal('1 Jul 2026, 03:00', Format::moment('2026-07-01 00:00:00', 'en'));
    },

    'traducătorul: parametri, rezerva în română, cheile lipsă vizibile' => function (Mediu $m): void {
        $en = Traducator::pentru(Mediu::radacina() . '/limbi', 'en');
        egal('Home', $en->t('meniu.acasa'));
        contine($en->t('eroare.500.text', ['id' => 'E-1234ABCD']), 'E-1234ABCD');
        egal('cheie.inexistenta', $en->t('cheie.inexistenta'));
        $t = new Traducator('en', ['a' => 'A {x}'], ['b' => 'B ro', 'p' => ['unu' => '{n} noapte', 'cateva' => '{n} nopți', 'multe' => '{n} de nopți']]);
        egal('A 1', $t->t('a', ['x' => 1]));
        egal('B ro', $t->t('b'), 'rezerva în română');
        egal('20 de nopți', $t->plural('p', 20), 'pluralul din rezervă urmează regula limbii rezervei');
        arunca(fn () => $t->t('p'), \LogicException::class);
        arunca(fn () => Traducator::pentru(Mediu::radacina() . '/limbi', '../x'), \InvalidArgumentException::class);
        egal('Last run: acum, via cron', Traducator::pentru(Mediu::radacina() . '/limbi', 'en', 'admin')->t('cron.ultima_rulare', ['cand' => 'acum', 'sursa' => 'cron']));
    },
];
