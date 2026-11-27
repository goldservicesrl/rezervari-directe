<?php

declare(strict_types=1);

// Zăvoarele cu expirare.

use Cazo\Baza\Zavor;

return [
    'un singur deținător; eliberarea îl face liber' => function (Mediu $m): void {
        $z = new Zavor($m->baza(), $m->ceas());
        $d = $z->ia('cron', 60);
        adevarat(is_string($d));
        egal(null, $z->ia('cron', 60), 'zăvorul s-a luat de două ori');
        fals($z->liber('cron'));
        adevarat(is_string($z->ia('sincronizare', 60)), 'zăvoarele cu nume diferite se blochează între ele');
        $z->elibereaza('cron', 'alt-detinator');
        fals($z->liber('cron'), 'un străin a eliberat zăvorul');
        $z->elibereaza('cron', $d);
        adevarat($z->liber('cron'));
        adevarat(is_string($z->ia('cron', 60)));
    },

    'zăvorul expirat se poate lua din nou; vechiul deținător îl pierde' => function (Mediu $m): void {
        $z = new Zavor($m->baza(), $m->ceas());
        $vechi = $z->ia('comanda:7', 30);
        $m->ceas()->avanseaza(29);
        egal(null, $z->ia('comanda:7', 30));
        adevarat($z->prelungeste('comanda:7', $vechi, 30));
        $m->ceas()->avanseaza(29);
        egal(null, $z->ia('comanda:7', 30), 'prelungirea nu a ținut');
        $m->ceas()->avanseaza(2);
        $nou = $z->ia('comanda:7', 30);
        adevarat(is_string($nou), 'zăvorul expirat a rămas blocat');
        fals($z->prelungeste('comanda:7', $vechi, 30), 'vechiul deținător a prelungit zăvorul altcuiva');
        $z->elibereaza('comanda:7', $vechi);
        fals($z->liber('comanda:7'), 'vechiul deținător a eliberat zăvorul altcuiva');
    },

    'două conexiuni: o singură reușită' => function (Mediu $m): void {
        $a = new Zavor($m->conexiune(), $m->ceas());
        $b = new Zavor($m->conexiune(), $m->ceas());
        $da = $a->ia('cron', 60);
        $db = $b->ia('cron', 60);
        adevarat(is_string($da));
        egal(null, $db);
        $a->elibereaza('cron', $da);
        adevarat(is_string($b->ia('cron', 60)));
    },

    'cuZavor rulează o dată și eliberează și la excepție' => function (Mediu $m): void {
        $z = new Zavor($m->baza(), $m->ceas());
        [$ok, $r] = $z->cuZavor('x', 60, fn () => 42);
        adevarat($ok);
        egal(42, $r);
        adevarat($z->liber('x'));
        arunca(fn () => $z->cuZavor('x', 60, function (): void {
            throw new \RuntimeException('boom');
        }), \RuntimeException::class, 'boom');
        adevarat($z->liber('x'), 'excepția a lăsat zăvorul blocat');
        $d = $z->ia('x', 60);
        [$ok2] = $z->cuZavor('x', 60, fn () => 1);
        fals($ok2);
        $z->elibereaza('x', $d);
        arunca(fn () => $z->ia('', 60), \InvalidArgumentException::class);
        arunca(fn () => $z->ia('x', 0), \InvalidArgumentException::class);
    },

    'curățarea zăvoarelor expirate' => function (Mediu $m): void {
        $z = new Zavor($m->baza(), $m->ceas());
        $z->ia('a', 10);
        $z->ia('b', 100);
        $m->ceas()->avanseaza(50);
        egal(1, $z->curataExpirate());
        fals($z->liber('b'));
    },
];
