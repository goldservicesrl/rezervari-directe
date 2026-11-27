<?php

declare(strict_types=1);

// Migrarea 0002: oglinda EasyCheck.
// Resursele brute cu ETag, proiecțiile (nopțile, prețurile, planurile cu avansul și anularea, taxele),
// pozele, anunțurile primite, cursoarele și bugetul API. Tabelele prețului local (planuri_local,
// reduceri_local, totaluri) și datele externe vin cu etapele lor.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Oglinda EasyCheck: resursele, proiecțiile, pozele, anunțurile, bugetul API',
    'ruleaza' => static function (Schema $s): void {
        // Corpul brut al fiecărei resurse (câmpurile necunoscute rămân aici), cu ETag-ul și versiunea lui.
        $s->tabel('ec_resurse', [
            'resursa' => 'cheie(32)',
            'etag' => 'text(191) null',
            'corp' => 'textlung null',
            'hash' => 'text(64) null',
            'citit_la' => 'moment null',
            'verificat_la' => 'moment null',
            'schimbat_la' => 'moment null',
            'invechit' => 'bool implicit 0',
            'versiune' => 'int implicit 0',
            'ultima_eroare' => 'text(500) null',
            // Fereastra cerută (disponibilitate, prețuri): o fereastră nouă ocolește ETag-ul vechi.
            'fereastra' => 'text(32) null',
            // După un eșec: reluarea cu pauză crescătoare (1, 2, 4 ... cel mult 15 minute).
            'esecuri' => 'int implicit 0',
            'reincercare_la' => 'moment null',
        ], ['resursa']);

        // Proiecția disponibilității: unitățile libere pe noapte.
        $s->tabel('nopti', [
            'camera' => 'cheie(32)',
            'zi' => 'zi',
            'libere' => 'int',
            'inchis' => 'bool implicit 0',
        ], ['camera', 'zi']);

        // Proiecția grilei: prețul și restricțiile pe cameră, plan și zi.
        $s->tabel('nopti_pret', [
            'camera' => 'cheie(32)',
            'plan' => 'cheie(32)',
            'zi' => 'zi',
            'pret' => 'bigint null',
            'sejur_minim' => 'int implicit 1',
            'sejur_minim_prin' => 'int implicit 1',
            'sejur_maxim' => 'int implicit 0',
            'sosire_inchisa' => 'bool implicit 0',
            'plecare_inchisa' => 'bool implicit 0',
            'inchis' => 'bool implicit 0',
        ], ['camera', 'plan', 'zi']);
        $s->index('nopti_pret', 'ix_nopti_pret_zi', ['zi']);

        // Planurile tarifare, cu avansul și anularea gratuită pe plan. Valorile enumerate au până la 32 de
        // caractere (Proiectii::ENUM), ca o valoare nouă să încapă și în MySQL strict.
        $s->tabel('planuri', [
            'id' => 'cheie(32)',
            'titlu' => 'text(191)',
            'fel' => 'text(32)',
            'tip' => 'text(32) null',
            'prag_nopti' => 'int null',
            'mod' => 'text(32)',
            'din_plan' => 'text(32) null',
            'regula' => 'json null',
            'rotunjire' => 'text(32) null',
            'pe_persoana' => 'json null',
            'avans_procent' => 'int null',
            'anulare_zile' => 'int null',
            'ordine' => 'int implicit 0',
        ], ['id']);

        // Taxele din grilă (taxele suplimentare, taxa locală pe ani, cota TVA), pentru MotorLocal.
        $s->tabel('taxe_ec', [
            'nume' => 'cheie(16)',
            'corp' => 'json',
            'versiune' => 'int implicit 0',
        ], ['nume']);

        // Pozele EasyCheck, după amprentă; derivatele stau în public_html/media/<sha>/.
        $s->tabel('poze', [
            'sha256' => 'cheie(64)',
            'url_sursa' => 'text(1000)',
            'latime' => 'int null',
            'inaltime' => 'int null',
            'pozitie' => 'int implicit 0',
            'stare' => 'text(16)',
            'variante' => 'json null',
            'folosit_la' => 'moment null',
            'incercari' => 'int implicit 0',
            'eroare' => 'text(500) null',
            'urmatoarea_la' => 'moment null',
        ], ['sha256']);
        $s->index('poze', 'ix_poze_stare', ['stare']);

        // Alocarea pozelor pe camere, rolurile și textele alternative (le alege gazda în panou).
        $s->tabel('poze_local', [
            'id' => 'id',
            'sha256' => 'text(64)',
            'camera' => 'text(32) null',
            'rol' => 'text(16) null',
            'alt' => 'json null',
            'ordine' => 'int implicit 0',
        ]);
        $s->index('poze_local', 'ix_poze_local_sha', ['sha256']);

        // Anunțurile primite, pentru deduplicare.
        $s->tabel('ec_anunturi', [
            'id' => 'cheie(64)',
            'tip' => 'text(64)',
            'creat_la' => 'moment null',
            'primit_la' => 'moment',
            'procesat_la' => 'moment null',
        ], ['id']);
        $s->index('ec_anunturi', 'ix_ec_anunturi_primit', ['primit_la']);

        // Cursoarele listelor incrementale (rezervari_actualizate_de_la).
        $s->tabel('ec_cursoare', [
            'nume' => 'cheie(64)',
            'valoare' => 'text(191) null',
        ], ['nume']);

        // Oglinda antetelor RateLimit-* și pauza cerută prin Retry-After.
        $s->tabel('ec_buget', [
            'fereastra' => 'cheie(16)',
            'citiri' => 'int implicit 0',
            'scrieri' => 'int implicit 0',
            'ramase' => 'int null',
            'reset_la' => 'moment null',
            'pauza_pana' => 'moment null',
        ], ['fereastra']);
    },
];
