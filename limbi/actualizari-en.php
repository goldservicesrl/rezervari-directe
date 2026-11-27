<?php

declare(strict_types=1);

// Textele actualizărilor, în engleză: blocul Actualizări din panou (Setări › Întreținere), anunțul de pe Acasă și
// e-mailul versiunii noi. Softul nu se actualizează singur: anunță versiunea nouă de pe cazo.ro, iar gazda o urcă după
// ghidul gazdei.
// Textele panoului pentru actualizări, în engleză.

defined('CAZO') or exit;

return [
    'actualizari.titlu' => 'Updates',
    'actualizari.versiune_instalata' => 'Installed version: {versiune}',
    'actualizari.la_zi' => 'Your site has the latest version.',
    'actualizari.verificat_la' => 'Last check: {cand}.',
    'actualizari.noua' => 'There is a new version: {versiune}.',
    'actualizari.noua_data' => 'Published on {data}.',
    'actualizari.noua_securitate' => 'This version brings a security fix: install it as soon as you can.',
    'actualizari.note' => 'What the new version brings',
    'actualizari.descarca' => 'Download the version',
    'actualizari.cum' => 'You install updates yourself: download the archive and follow the steps in the host guide, under “Updates”.',
    'actualizari.pasii' => 'Installation steps',
    'actualizari.pagina_versiunilor' => 'All versions, on GitHub',

    'email.versiune_noua.subiect' => 'There is a new version of your site: {versiune}',
    'email.versiune_noua.p1' => 'Version {versiune} of your site’s software was published on {data}.',
    'email.versiune_noua.note' => 'What it brings: {note}',
];
