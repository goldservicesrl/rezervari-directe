<?php

declare(strict_types=1);

// Migrarea 0003: prezentarea.
// Totalurile ținute minte, planurile vizibile pe site, completările camerelor, paginile și conținutul gazdei,
// textele și datele legale, temele și setările lor. Reducerile locale (reduceri_local) vine într-o versiune următoare,
// datele externe (vremea, cursul BNR) tot într-o versiune următoare.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Prezentarea: totalurile, planurile și camerele locale, paginile, conținutul, legalul, temele',
    'ruleaza' => static function (Schema $s): void {
        // Totalurile calculate (GET /total sau MotorLocal), 10 minute; cheia: SHA-256 pe sursă, parametri,
        // versiunea prețurilor, bifele „include” și versiunea reducerilor locale. Golit la prețuri noi.
        $s->tabel('totaluri', [
            'cheie' => 'cheie(64)',
            'corp' => 'json',
            'la' => 'moment',
        ], ['cheie']);
        $s->index('totaluri', 'ix_totaluri_la', ['la']);

        // Ce alege gazda în Cazo despre planurile EasyCheck (fără planuri „doar cu cod”).
        $s->tabel('planuri_local', [
            'plan' => 'cheie(32)',
            'vizibilitate' => 'text(16)',
            'titlu_local' => 'json null',
            'ordine' => 'int implicit 0',
        ], ['plan']);

        // Completările camerelor: API-ul v1 dă titlul într-o singură limbă și pozele proprietății.
        $s->tabel('camere_local', [
            'camera' => 'cheie(32)',
            'slug' => 'json null',
            'titlu' => 'json null',
            'descriere' => 'json null',
            'fisa' => 'json null',
            'dotari' => 'json null',
            'ordine' => 'int implicit 0',
            'ascunsa' => 'bool implicit 0',
        ], ['camera']);

        // Paginile gazdei (libere, ghid, întrebări frecvente); conținutul e HTML sanitizat, pe limbi.
        $s->tabel('pagini', [
            'id' => 'id',
            'tip' => 'text(16)',
            'slug' => 'json',
            'titlu' => 'json',
            'continut' => 'json',
            'seo' => 'json null',
            'publicata' => 'bool implicit 0',
            'ordine' => 'int implicit 0',
        ]);

        // Blocurile de conținut cerute de secțiunile temelor (cum_ajungeti, starea_muntelui ...), pe limbă.
        $s->tabel('continut', [
            'cheie' => 'cheie(64)',
            'limba' => 'cheie(8)',
            'date' => 'json',
        ], ['cheie', 'limba']);

        // Paragrafele adăugate de gazdă la modelele legale, versionate; acordul indică versiunea exactă.
        $s->tabel('texte_legale', [
            'id' => 'id',
            'fel' => 'text(32)',
            'limba' => 'text(8)',
            'versiune' => 'int',
            'corp' => 'textlung',
            'aprobat_la' => 'moment null',
            'aprobat_de' => 'bigint null',
            'activ' => 'bool implicit 0',
        ]);
        $s->index('texte_legale', 'ix_texte_legale_fel', ['fel', 'limba', 'activ']);

        // Completările datelor operatorului din GET /proprietate (API-ul are prioritate), câmp cu câmp.
        $s->tabel('date_legale', [
            'camp' => 'cheie(64)',
            'valoare' => 'text(500) null',
            'actualizat_la' => 'moment',
        ], ['camp']);

        // Temele instalate: oficiale (din lansare) și terțe (fără JS).
        $s->tabel('teme', [
            'slug' => 'cheie(64)',
            'versiune' => 'text(32)',
            'sha' => 'text(64)',
            'sursa' => 'text(16)',
            'stare' => 'text(16)',
            'manifest' => 'json',
            'raport' => 'json null',
            'instalata_la' => 'moment',
        ], ['slug']);

        // Alegerile gazdei pe temă: valorile setărilor și compoziția secțiunilor pe șablon.
        $s->tabel('teme_setari', [
            'tema' => 'cheie(64)',
            'valori' => 'json',
            'compozitie' => 'json',
            'versiune' => 'int implicit 0',
        ], ['tema']);
    },
];
