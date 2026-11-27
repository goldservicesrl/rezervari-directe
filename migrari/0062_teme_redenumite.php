<?php

declare(strict_types=1);

// Migrarea 0062: temele Atelier și Etaj își schimbă numele și slugul, Atelier → Studio (`atelier` → `studio`) și
// Etaj → Hotel (`etaj` → `hotel`). Un site care folosea una dintre ele rămâne pe aceeași temă, sub slugul nou, cu
// setările ei: rândul din `teme_setari` trece pe slugul nou (dacă slugul nou are deja un rând, cel vechi rămâne
// neatins). „Reveniți” spre tema veche duce la slugul nou. Generația paginilor ținute crește când tema activă se
// redenumește: paginile vechi cer CSS-ul din dosarul vechi. O temă terță instalată cu slugul vechi rămâne neatinsă, cu
// setările ei. Rulată a doua oară, nu mai schimbă nimic. Numărul lasă loc migrărilor ramurilor care se unesc mai târziu.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Temele redenumite: Atelier devine Studio, Etaj devine Hotel, cu setările lor',
    'ruleaza' => static function (Schema $s): void {
        $b = $s->baza();
        $harta = ['atelier' => 'studio', 'etaj' => 'hotel'];
        $terta = static fn (string $slug): bool => $b->valoare("SELECT slug FROM {teme} WHERE slug = ? AND sursa = 'terta'", [$slug]) !== null;
        $setare = static function (string $cheie) use ($b): mixed {
            $v = $b->valoare('SELECT valoare FROM {setari} WHERE cheie = ?', [$cheie]);
            return is_string($v) ? json_decode($v, true) : null;
        };
        $scrie = static function (string $cheie, mixed $valoare) use ($b): void {
            $json = json_encode($valoare, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $acum = gmdate('Y-m-d H:i:s');
            if ($b->valoare('SELECT cheie FROM {setari} WHERE cheie = ?', [$cheie]) !== null) {
                $b->executa('UPDATE {setari} SET valoare = ?, actualizat_la = ?, actualizat_de = NULL WHERE cheie = ?', [$json, $acum, $cheie]);
            } else {
                $b->insereaza('setari', ['cheie' => $cheie, 'valoare' => $json, 'actualizat_la' => $acum, 'actualizat_de' => null]);
            }
        };

        // Tema activă (instalatorul o scrie la pasul temei; fără ea, site-ul ia oricum tema implicită, acum Studio).
        $activa = $setare('tema.activa');
        if (is_string($activa) && isset($harta[$activa]) && !$terta($activa)) {
            $scrie('tema.activa', $harta[$activa]);
            $scrie('tema.versiune', (int) $setare('tema.versiune') + 1);
        }

        // „Reveniți”: o temă oficială se ține minte fără sha; „slug@sha” e a unei teme terțe și rămâne.
        $ant = $setare('tema.anterioara');
        if (is_string($ant) && isset($harta[$ant]) && !$terta($ant)) {
            $scrie('tema.anterioara', $harta[$ant]);
        }

        // Setările temei trec pe slugul nou.
        foreach ($harta as $vechi => $nou) {
            if ($terta($vechi) || $b->valoare('SELECT tema FROM {teme_setari} WHERE tema = ?', [$nou]) !== null) {
                continue;
            }
            $b->executa('UPDATE {teme_setari} SET tema = ? WHERE tema = ?', [$nou, $vechi]);
        }
    },
];
