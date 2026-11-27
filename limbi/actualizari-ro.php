<?php

declare(strict_types=1);

// Textele actualizărilor, în română: blocul Actualizări din panou (Setări › Întreținere), anunțul de pe Acasă și
// e-mailul versiunii noi. Softul nu se actualizează singur: anunță versiunea nouă de pe cazo.ro, iar gazda o urcă după
// ghidul gazdei.
// Textele panoului pentru actualizări, în română.

defined('CAZO') or exit;

return [
    'actualizari.titlu' => 'Actualizări',
    'actualizari.versiune_instalata' => 'Versiunea instalată: {versiune}',
    'actualizari.la_zi' => 'Site-ul are cea mai nouă versiune.',
    'actualizari.verificat_la' => 'Ultima căutare: {cand}.',
    'actualizari.noua' => 'Există o versiune nouă: {versiune}.',
    'actualizari.noua_data' => 'Publicată pe {data}.',
    'actualizari.noua_securitate' => 'Versiunea aduce o reparație de securitate: instalați-o cât mai repede.',
    'actualizari.note' => 'Ce aduce versiunea nouă',
    'actualizari.descarca' => 'Descărcați versiunea',
    'actualizari.cum' => 'Actualizarea o faceți dumneavoastră: descărcați arhiva și urmați pașii din ghidul gazdei, la „Actualizările”.',
    'actualizari.pasii' => 'Pașii de instalare',
    'actualizari.pagina_versiunilor' => 'Toate versiunile, pe GitHub',

    'email.versiune_noua.subiect' => 'Există o versiune nouă a site-ului: {versiune}',
    'email.versiune_noua.p1' => 'Versiunea {versiune} a programului site-ului dumneavoastră a apărut pe {data}.',
    'email.versiune_noua.note' => 'Ce aduce: {note}',
];
