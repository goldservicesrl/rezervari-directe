<?php

declare(strict_types=1);

// Paginile legale și subsolul editabile: reparațiile din verificarea din 17.11.2026.

use Cazo\Legal\Editabile;

return [
    'adresa spre Accesibilitate se recunoaște cu și fără rescriere, în ambele limbi, și ca adresă absolută' => function (Mediu $m): void {
        foreach (['/accesibilitate', '/accesibilitate/', '/accesibilitate#x', '/en/accessibility', '/?r=%2Faccesibilitate', '/?r=/accesibilitate', '/?r=%2Fen%2Faccessibility',
            '/?x=1&r=%2Faccesibilitate', 'https://pensiune.ro/accesibilitate', 'https://pensiune.ro/?r=%2Faccesibilitate', 'https://pensiune.ro/?r=%2Fen%2Faccessibility#sus'] as $da) {
            adevarat(Editabile::spreAccesibilitate($da), $da);
        }
        foreach ([null, '/', '/termeni', '/?r=%2Ftermeni', '/?r=%2Fen%2Fterms', 'mailto:a@b.ro', 'tel:+40700000000', 'https://pensiune.ro/', '/?accesibilitate=1'] as $nu) {
            adevarat(!Editabile::spreAccesibilitate($nu), (string) $nu);
        }
    },

    'introducerea are câte un paragraf pentru fiecare rând gol; atașamentul citește și contractele vechi, cu introducerea text' => function (Mediu $m): void {
        $d = Editabile::desfacePagina("Primul {x}.\n\nAl doilea.\n\n## Titlu\n\nUnu.", ['x' => 'paragraf']);
        egal(['Primul paragraf.', 'Al doilea.'], $d['intro']);
        egal([], Editabile::desfacePagina("## Titlu\n\nUnu.", [])['intro']);
        $sectiuni = [['titlu' => 'Titlu', 'paragrafe' => ['Unu.']]];
        egal("Termeni\n\nPrimul paragraf.\n\nAl doilea.\n\nTitlu\nUnu.\n\nv1\n",
            \Cazo\Email\Sabloane::textTermeni(['titlu' => 'Termeni', 'intro' => ['Primul paragraf.', 'Al doilea.'], 'sectiuni' => $sectiuni, 'eticheta_versiune' => 'v1']));
        // Un contract înghețat înainte, cu introducerea ca text.
        egal("Termeni\n\nIntroducerea veche.\n\nTitlu\nUnu.\n\nv1\n",
            \Cazo\Email\Sabloane::textTermeni(['titlu' => 'Termeni', 'intro' => 'Introducerea veche.', 'sectiuni' => $sectiuni, 'eticheta_versiune' => 'v1']));
        egal("Termeni\n\nTitlu\nUnu.\n\nv1\n", \Cazo\Email\Sabloane::textTermeni(['titlu' => 'Termeni', 'intro' => [], 'sectiuni' => $sectiuni, 'eticheta_versiune' => 'v1']));
    },
];
