<?php

declare(strict_types=1);

// Migrarea 0045: site-ul care lucrează singur, fără cheie EasyCheck. Numărul lasă loc
// migrărilor ramurii etapa2 (0041 ...), care se unește mai târziu.
//
//   - local_camere: camerele completate de gazdă în panou (numele, unitățile, capacitatea, cele două prețuri pe noapte,
//     în bani); `extra` ține spațiile și pozele primite de la EasyCheck la trecerea din modul EasyCheck;
//     `de_verificat` = prețurile precompletate din tariful de bază, de confirmat de gazdă;
//   - local_rezervari, local_cereri, local_idem: rezervările și cererile site-ului, cu idempotența scrierilor (aceeași
//     formă ca în API-ul EasyCheck, deci restul softului nu vede diferența);
//   - local_inchise: zilele închise de gazdă din panou (camera „*” = toate camerele);
//   - local_ical_surse, local_ical_nopti: calendarele iCal citite (Booking, Airbnb, altele) și nopțile ocupate
//     găsite în ele; adresa calendarului e criptată (are în ea un jeton al platformei);
//   - local_trecere: trimiterea, o singură dată, a rezervărilor viitoare făcute local spre EasyCheck.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Site-ul fără cheie EasyCheck: camerele, rezervările, cererile, zilele închise, calendarele iCal, trecerea',
    'ruleaza' => static function (Schema $s): void {
        $s->tabel('local_camere', [
            'camera' => 'text(32)',
            'titlu' => 'text(191)',
            'unitati' => 'int implicit 1',
            'oaspeti_max' => 'int implicit 2',
            'pret_noapte' => 'bigint implicit 0',
            'pret_weekend' => 'bigint implicit 0',
            'de_verificat' => 'bool implicit 0',
            'extra' => 'json null',
            'ordine' => 'int implicit 0',
            'activa' => 'bool implicit 1',
            'creat_la' => 'moment',
            'actualizat_la' => 'moment',
        ], ['camera']);

        $s->tabel('local_rezervari', [
            'id' => 'text(40)',
            'ref_site' => 'text(32) null',
            'stare' => 'text(32)',
            'sosire' => 'zi',
            'plecare' => 'zi',
            'camere' => 'json',
            'total' => 'bigint implicit 0',
            'plata' => 'json null',
            'plata_asteptata' => 'json null',
            'sursa' => 'text(16) implicit \'site\'',
            'proba' => 'bool implicit 0',
            'creat_la' => 'moment',
            'actualizat_la' => 'moment',
        ], ['id']);
        $s->index('local_rezervari', 'ix_local_rezervari_perioada', ['plecare', 'sosire']);
        $s->index('local_rezervari', 'ix_local_rezervari_actualizat', ['actualizat_la']);
        $s->index('local_rezervari', 'ix_local_rezervari_ref', ['ref_site']);

        $s->tabel('local_cereri', [
            'id' => 'text(40)',
            'ref_site' => 'text(32) null',
            'stare' => 'text(16)',
            'sosire' => 'zi',
            'plecare' => 'zi',
            'camere' => 'json',
            'total' => 'bigint implicit 0',
            'expira_la' => 'moment',
            'rezervare' => 'text(40) null',
            'decisa_la' => 'moment null',
            'proba' => 'bool implicit 0',
            'creat_la' => 'moment',
        ], ['id']);

        $s->tabel('local_idem', [
            'cheie' => 'text(64)',
            'hash_corp' => 'text(64)',
            'status' => 'int',
            'corp' => 'json null',
            'la' => 'moment',
        ], ['cheie']);

        $s->tabel('local_inchise', [
            'camera' => 'text(32)',
            'zi' => 'zi',
        ], ['camera', 'zi']);

        $s->tabel('local_ical_surse', [
            'id' => 'id',
            'nume' => 'text(80)',
            'fel' => 'text(16) implicit \'ical\'',
            'url' => 'textlung null',
            'gazda' => 'text(191) null',
            'camera' => 'text(32)',
            'activa' => 'bool implicit 1',
            'citita_la' => 'moment null',
            'reusita_la' => 'moment null',
            'eroare' => 'text(64) null',
            'etag' => 'text(191) null',
            'modificat' => 'text(64) null',
            'evenimente' => 'int implicit 0',
            'creat_la' => 'moment',
        ]);

        $s->tabel('local_ical_nopti', [
            'sursa_id' => 'bigint',
            'camera' => 'text(32)',
            'zi' => 'zi',
            'numar' => 'int implicit 1',
        ], ['sursa_id', 'camera', 'zi']);
        $s->index('local_ical_nopti', 'ix_local_ical_nopti_zi', ['camera', 'zi']);

        $s->tabel('local_trecere', [
            'comanda_id' => 'bigint',
            'ref' => 'text(16)',
            'fel' => 'text(16)',
            'stare' => 'text(16)',
            'ec_id' => 'text(40) null',
            'cod' => 'text(64) null',
            'incercari' => 'int implicit 0',
            'la' => 'moment',
            'actualizat_la' => 'moment',
        ], ['comanda_id']);
    },
];
