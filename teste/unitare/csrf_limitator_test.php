<?php

declare(strict_types=1);

// CSRF, limitatorul și trunchierea IP-urilor.

use Cazo\Securitate\Chei;
use Cazo\Securitate\Csrf;
use Cazo\Securitate\Ip;
use Cazo\Securitate\Limitator;

return [
    'jetonul CSRF trece doar pentru legătura și scopul lui' => function (Mediu $m): void {
        $c = new Csrf($m->chei());
        $j = $c->emite('sesiune-a');
        adevarat($c->verifica('sesiune-a', $j));
        fals($c->verifica('sesiune-b', $j), 'jeton acceptat pentru altă legătură');
        fals($c->verifica('sesiune-a', $j, 'rambursare'), 'jeton acceptat pentru alt scop');
        fals($c->verifica('', $j), 'legătură goală acceptată');
        fals($c->verifica('sesiune-a', null));
        fals($c->verifica('sesiune-a', ''));
        fals($c->verifica('sesiune-a', 'a.b.c'));
        fals($c->verifica('sesiune-a', str_repeat('A', 300)));
        [$a, $mac] = explode('.', $j);
        fals($c->verifica('sesiune-a', $a . '.' . strrev($mac)), 'MAC alterat acceptat');
        fals((new Csrf(new Chei(str_repeat("\x07", 32))))->verifica('sesiune-a', $j), 'jeton acceptat cu altă cheie');
        adevarat($c->emite('sesiune-a') !== $j, 'jetoanele se repetă');
    },

    'limitatorul numără în fereastră și se resetează la fereastra următoare' => function (Mediu $m): void {
        $l = new Limitator($m->baza(), $m->ceas());
        for ($i = 1; $i <= 5; $i++) {
            adevarat($l->incearca('rezerva:ip:203.0.113.0/24', 5, 3600), "încercarea $i");
        }
        fals($l->incearca('rezerva:ip:203.0.113.0/24', 5, 3600), 'a șasea încercare trece');
        egal(6, $l->numar('rezerva:ip:203.0.113.0/24', 3600));
        adevarat($l->incearca('rezerva:ip:198.51.100.0/24', 5, 3600), 'alt IP e afectat');
        $m->ceas()->avanseaza(3600);
        adevarat($l->incearca('rezerva:ip:203.0.113.0/24', 5, 3600), 'fereastra nouă nu s-a resetat');
        egal(1, $l->numar('rezerva:ip:203.0.113.0/24', 3600));
    },

    'limitatorul curăță ferestrele încheiate' => function (Mediu $m): void {
        $l = new Limitator($m->baza(), $m->ceas());
        $l->incearca('a', 10, 60);
        $l->incearca('b', 10, 86400);
        $m->ceas()->avanseaza(120);
        egal(1, $l->curata());
        egal(1, (int) $m->baza()->valoare('SELECT COUNT(*) FROM {limite}'));
        arunca(fn () => $l->incearca('', 1, 60), \InvalidArgumentException::class);
        arunca(fn () => $l->incearca('x', 1, 0), \InvalidArgumentException::class);
    },

    'două conexiuni numără împreună' => function (Mediu $m): void {
        $a = new Limitator($m->conexiune(), $m->ceas());
        $b = new Limitator($m->conexiune(), $m->ceas());
        $trecute = 0;
        for ($i = 0; $i < 6; $i++) {
            $trecute += ($i % 2 === 0 ? $a : $b)->incearca('comun', 4, 60) ? 1 : 0;
        }
        egal(4, $trecute);
    },

    'IP-urile se trunchiază la /24 și /48' => function (Mediu $m): void {
        egal('203.0.113.0/24', Ip::trunchiaza('203.0.113.77'));
        egal('203.0.113.0/24', Ip::trunchiaza('::ffff:203.0.113.77'));
        egal('2001:db8:abcd::/48', Ip::trunchiaza('2001:db8:abcd:12:1:2:3:4'));
        egal('necunoscut', Ip::trunchiaza('nu-e-ip'));
        egal('necunoscut', Ip::trunchiaza(''));
    },

    'IP-ul vizitatorului: REMOTE_ADDR, iar în spatele unui proxy de încredere, X-Forwarded-For' => function (Mediu $m): void {
        adevarat(Ip::inRetea('173.245.50.1', '173.245.48.0/20'));
        fals(Ip::inRetea('173.245.64.1', '173.245.48.0/20'));
        adevarat(Ip::inRetea('2400:cb00:12::1', '2400:cb00::/32'));
        fals(Ip::inRetea('2400:cb01::1', '2400:cb00::/32'));
        adevarat(Ip::inRetea('10.0.0.5', '10.0.0.5'));
        fals(Ip::inRetea('10.0.0.5', '10.0.0.0/33'));
        fals(Ip::inRetea('10.0.0.5', '::/0'), 'IPv4 față de rețea IPv6');
        $proxy = ['173.245.48.0/20', '10.0.0.0/8'];
        // Fără listă: antetul se ignoră, oricine l-ar trimite.
        egal('198.51.100.7', Ip::client('198.51.100.7', '203.0.113.9', []));
        // Cerere directă, cu antet falsificat: rămâne REMOTE_ADDR.
        egal('198.51.100.7', Ip::client('198.51.100.7', '203.0.113.9', $proxy));
        // Prin proxy: prima adresă din dreapta care nu e proxy; ce a scris vizitatorul în stânga se ignoră.
        egal('203.0.113.9', Ip::client('173.245.50.1', '1.2.3.4, 203.0.113.9', $proxy));
        egal('203.0.113.9', Ip::client('10.0.0.2', '1.2.3.4, 203.0.113.9, 173.245.50.1', $proxy));
        egal('2a02:2f0e::1', Ip::client('10.0.0.2', '2a02:2f0e::1', $proxy));
        egal('173.245.50.1', Ip::client('173.245.50.1', 'gunoi', $proxy));
        egal('173.245.50.1', Ip::client('173.245.50.1', null, $proxy));
    },
];
