<?php

declare(strict_types=1);

// Migrarea 0001: tabelele fundației.
// Tabelele modulelor următoare (oglinda, comenzile, conținutul, temele) vin cu migrările lor.

use Cazo\Baza\Migrator;
use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Fundația: setări, secrete, limite, zăvoare, joburi, sarcini, rapoarte CSP, lansări',
    'ruleaza' => static function (Schema $s): void {
        Migrator::tabeleInfrastructura($s);

        // Setările nesecrete, ca JSON.
        $s->tabel('setari', [
            'cheie' => 'cheie',
            'valoare' => 'json',
            'actualizat_la' => 'moment',
            'actualizat_de' => 'bigint null',
        ], ['cheie']);

        // Secretele, criptate cu subcheia „secrete”; panoul arată doar ultimele 4 caractere.
        $s->tabel('secrete', [
            'nume' => 'cheie(64)',
            'valoare' => 'textlung',
            'ultimele4' => 'text(8)',
            'creat_la' => 'moment',
            'rotit_la' => 'moment null',
        ], ['nume']);

        // Contoarele limitatorului: cheia (cu IP-ul trunchiat) și sfârșitul ferestrei, în secunde Unix.
        $s->tabel('limite', [
            'cheie' => 'cheie',
            'fereastra' => 'bigint',
            'numar' => 'int implicit 0',
        ], ['cheie', 'fereastra']);
        $s->index('limite', 'ix_limite_fereastra', ['fereastra']);

        // Coada de joburi, cu coalescență prin cheie_unica (NULL se poate repeta).
        $s->tabel('joburi', [
            'id' => 'id',
            'tip' => 'text(64)',
            'cheie_unica' => 'cheie null',
            'date' => 'json',
            'ruleaza_dupa' => 'moment',
            'incercari' => 'int implicit 0',
            'max_incercari' => 'int implicit 6',
            'blocat_pana' => 'moment null',
            'eroare' => 'text(500) null',
            'creat_la' => 'moment',
        ]);
        $s->unic('joburi', 'ux_joburi_cheie_unica', ['cheie_unica']);
        $s->index('joburi', 'ix_joburi_scadente', ['ruleaza_dupa']);

        // Sarcinile periodice ale planificatorului.
        $s->tabel('sarcini', [
            'nume' => 'cheie(64)',
            'urmatoarea_la' => 'moment',
            'ultima_la' => 'moment null',
            'durata_ms' => 'int null',
            'rezultat' => 'text(500) null',
            'sursa' => 'text(16) null',
        ], ['nume']);

        // Rapoartele CSP, agregate, fără IP; cheie = SHA-256 pe (directivă, sursă, pagină).
        $s->tabel('csp_rapoarte', [
            'cheie' => 'cheie(64)',
            'directiva' => 'text(64)',
            'sursa' => 'text(191)',
            'pagina' => 'text(191)',
            'numar' => 'int implicit 0',
            'prima_la' => 'moment',
            'ultima_la' => 'moment',
        ], ['cheie']);

        // Istoricul lansărilor instalate.
        $s->tabel('lansari', [
            'versiune' => 'cheie(32)',
            'instalat_la' => 'moment',
            'rezultat' => 'text(32)',
            'anterioara' => 'text(32) null',
        ], ['versiune']);
    },
];
