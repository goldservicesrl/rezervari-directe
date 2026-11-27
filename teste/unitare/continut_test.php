<?php

declare(strict_types=1);

// Textul simplu al paginilor gazdei (M6b): paragrafele, subtitlurile, listele, întrebările frecvente, totul escapat.

use Cazo\Continut\TextSimplu;

return [
    'textul simplu: paragrafe, subtitluri, liste și întrebări frecvente; orice marcaj ajunge escapat' => function (Mediu $m): void {
        egal('<p>Unu<br>doi</p><p>Trei</p>', TextSimplu::html("Unu\ndoi\n\nTrei"));
        egal('<h2>Titlu</h2><p>Text</p>', TextSimplu::html("## Titlu\nText"));
        egal('<ul><li>a</li><li>b</li></ul>', TextSimplu::html("- a\n- b"));
        egal('<h2>Întrebare?</h2><p>Răspuns</p>', TextSimplu::html("Întrebare?\nRăspuns", 'faq'));
        $x = TextSimplu::html("<img src=x onerror=alert(1)>\n\n## <b>t</b>\n\n- <a href=\"javascript:1\">l</a>");
        lipseste($x, '<img');
        lipseste($x, '<b>');
        lipseste($x, '<a ');
        contine($x, '&lt;img src=x onerror=alert(1)&gt;');
        egal('', TextSimplu::html("\n\n  \n"));
    },
];
