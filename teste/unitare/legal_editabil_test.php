<?php

declare(strict_types=1);

// Paginile legale și subsolul editabile: textul desfăcut în introducere și secțiuni, câmpurile completate, rândurile
// subsolului cu legăturile verificate, diferențele pe rânduri.

use Cazo\Legal\Editabile;

return [
    'pagina: introducerea, secțiunile „## ”, paragrafele, câmpurile cunoscute completate, celelalte acolade rămân text' => function (Mediu $m): void {
        $d = Editabile::desfacePagina("Termenii de la {denumire}.\r\n\n## Plata\nPlătiți la sosire.\n\nAvansul e {avans}.\n\n## Anularea\n\n\nGratuită.", ['denumire' => 'Demo SRL']);
        egal(['Termenii de la Demo SRL.'], $d['intro']);
        egal([['titlu' => 'Plata', 'paragrafe' => ['Plătiți la sosire.', 'Avansul e {avans}.']], ['titlu' => 'Anularea', 'paragrafe' => ['Gratuită.']]], $d['sectiuni']);
        egal(['intro' => [], 'sectiuni' => []], Editabile::desfacePagina('', []));
    },

    'subsolul: rândurile cu un câmp gol se ascund, adresele se completează fără spații, adresele nepermise rămân text' => function (Mediu $m): void {
        $p = ['telefon' => '+40 700 000 000', 'telefon_tel' => '+40700000000', 'email' => 'a@b.ro', 'cui' => '', 'denumire' => 'Demo SRL'];
        egal([
            ['text' => 'Telefon: +40 700 000 000', 'url' => 'tel:+40700000000'],
            ['text' => 'Scrieți-ne', 'url' => 'mailto:a@b.ro'],
            ['text' => 'Denumirea: Demo SRL', 'url' => null],
            ['text' => 'Blog', 'url' => 'https://exemplu.ro/blog'],
            ['text' => 'Termeni', 'url' => '/termeni'],
            ['text' => 'Rău', 'url' => null],
            ['text' => 'Altă cale', 'url' => null],
        ], Editabile::randuri("Telefon: {telefon} | tel:{telefon}\nScrieți-ne | mailto:{email}\nCUI: {cui}\nDenumirea: {denumire}\n\nBlog | https://exemplu.ro/blog\nTermeni | /termeni\nRău | javascript:alert(1)\nAltă cale | //alt.ro/x", $p));
        foreach (['/termeni', '/en/terms#x', 'https://a.ro', 'http://a.ro/b?c=1', 'mailto:a@b.ro', 'tel:+40700000000'] as $bun) {
            adevarat(Editabile::adresaPermisa($bun), $bun);
        }
        foreach (['', '//a.ro', 'javascript:alert(1)', 'data:text/html,x', 'https://', 'ftp://a.ro', 'termeni', 'mailto:a', 'tel:abc', '/a b', "/a\"onclick=x", 'https://a.ro/<x>'] as $rau) {
            adevarat(!Editabile::adresaPermisa($rau), $rau);
        }
        egal([['grup' => 'legaturi', 'rand' => 2]], Editabile::adreseGresite(['contact' => 'E-mail | mailto:{email}', 'legaturi' => "Bun | /termeni\nRău | javascript:x"]));
    },

    'subsolul se păstrează ca JSON cu grupurile cunoscute' => function (Mediu $m): void {
        egal(['contact' => 'a', 'date' => '', 'legaturi' => 'b', 'sal' => '', 'an' => ''], Editabile::grupuri(Editabile::json(['contact' => 'a', 'legaturi' => 'b', 'strain' => 'x'])));
        egal(['contact' => '', 'date' => '', 'legaturi' => '', 'sal' => '', 'an' => ''], Editabile::grupuri('nu e json'));
    },

    'diferențele pe rânduri: comune, scoase, adăugate, în ordine' => function (Mediu $m): void {
        egal([
            ['fel' => 'egal', 'text' => 'a'],
            ['fel' => 'scos', 'text' => 'b'],
            ['fel' => 'adaugat', 'text' => 'x'],
            ['fel' => 'egal', 'text' => 'c'],
            ['fel' => 'adaugat', 'text' => 'd'],
        ], Editabile::diferente("a\nb\nc", "a\nx\nc\nd"));
        egal([['fel' => 'egal', 'text' => 'a']], Editabile::diferente('a', 'a'));
    },
];
