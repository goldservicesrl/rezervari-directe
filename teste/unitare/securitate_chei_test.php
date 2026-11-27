<?php

declare(strict_types=1);

// Cheia-mamă, subcheile pe scop, criptarea, indexul orb, secretele din bază.

use Cazo\Securitate\Chei;
use Cazo\Securitate\Codare;
use Cazo\Securitate\Cutie;
use Cazo\Securitate\IndexOrb;
use Cazo\Securitate\Secrete;

return [
    'subcheile sunt deterministe și diferite pe scop' => function (Mediu $m): void {
        $a = $m->chei();
        $b = new Chei(str_repeat("\x42", 32));
        egal($a->subcheie('pii'), $b->subcheie('pii'));
        $vazute = [];
        foreach (array_keys(Chei::SCOPURI) as $scop) {
            $k = $a->subcheie($scop);
            egal(32, strlen($k));
            adevarat(!in_array($k, $vazute, true), "subcheia $scop se repetă");
            $vazute[] = $k;
        }
        $c = new Chei(str_repeat("\x43", 32));
        adevarat($a->subcheie('secrete') !== $c->subcheie('secrete'), 'altă cheie-mamă dă aceeași subcheie');
        arunca(fn () => $a->subcheie('necunoscut'), \InvalidArgumentException::class);
        arunca(fn () => new Chei('scurta'), \InvalidArgumentException::class);
    },

    'numerele scopurilor sunt fixe (o renumerotare ar face secretele ilizibile)' => function (Mediu $m): void {
        egal(['secrete' => 1, 'pii' => 2, 'index_orb' => 3, 'altcha' => 4, 'csrf' => 5, 'config' => 6, 'oaspete' => 7, 'comanda' => 8, 'totp' => 9, 'audit' => 10, 'coduri_rezerva' => 11, 'ical' => 20, 'oferte_dezabonare' => 21], Chei::SCOPURI);
        egal('PragulV1', Chei::CONTEXT);
    },

    'cheia-mamă generată are 0600 și nu se suprascrie' => function (Mediu $m): void {
        $d = $m->dosarTemporar();
        $c = Chei::genereaza("$d/cheie.bin");
        egal(32, filesize("$d/cheie.bin"));
        egal('0600', substr(sprintf('%o', fileperms("$d/cheie.bin")), -4));
        egal($c->subcheie('pii'), Chei::dinFisier("$d/cheie.bin")->subcheie('pii'));
        arunca(fn () => Chei::genereaza("$d/cheie.bin"), \RuntimeException::class, 'există deja');
        arunca(fn () => Chei::dinFisier("$d/lipsa.bin"), \RuntimeException::class);
        lipseste(print_r($c, true), str_repeat("\x42", 4));
    },

    'cutia criptează, decriptează și respinge datele alterate' => function (Mediu $m): void {
        $cutie = new Cutie($m->chei()->subcheie('pii'));
        $c1 = $cutie->cripteaza('ana@example.com');
        $c2 = $cutie->cripteaza('ana@example.com');
        adevarat(str_starts_with($c1, 'v1.'));
        adevarat($c1 !== $c2, 'nonce-ul se repetă');
        egal('ana@example.com', $cutie->decripteaza($c1));
        lipseste($c1, 'ana');
        $alterat = substr($c1, 0, -2) . (substr($c1, -2, 1) === 'A' ? 'B' : 'A') . substr($c1, -1);
        arunca(fn () => $cutie->decripteaza($alterat), \RuntimeException::class);
        arunca(fn () => (new Cutie($m->chei()->subcheie('secrete')))->decripteaza($c1), \RuntimeException::class);
        arunca(fn () => $cutie->decripteaza('v2.abc'), \RuntimeException::class);
        arunca(fn () => $cutie->decripteaza('v1.!!'), \RuntimeException::class);
        arunca(fn () => $cutie->decripteaza('v1.' . Codare::b64u('scurt')), \RuntimeException::class);
    },

    'codarea base64url și jetoanele' => function (Mediu $m): void {
        egal('-_8', Codare::b64u("\xfb\xff"));
        egal("\xfb\xff", Codare::dinB64u('-_8'));
        egal(null, Codare::dinB64u('a+b'));
        egal(43, strlen(Codare::jeton()));
        adevarat(Codare::jeton() !== Codare::jeton());
    },

    'indexul orb normalizează e-mailul și depinde de cheie' => function (Mediu $m): void {
        $io = new IndexOrb($m->chei());
        egal($io->email('Ana@Example.com '), $io->email('ana@example.com'));
        egal(64, strlen($io->email('ana@example.com')));
        adevarat($io->email('ana@example.com') !== (new IndexOrb(new Chei(str_repeat("\x01", 32))))->email('ana@example.com'));
        adevarat($io->email('ana@example.com') !== hash('sha256', 'ana@example.com'));
    },

    'secretele stau criptate în bază, cu ultimele 4 caractere' => function (Mediu $m): void {
        $s = new Secrete($m->baza(), $m->chei(), $m->ceas());
        egal(null, $s->citeste('ec_cheie'));
        $s->pune('ec_cheie', 'ec_proba_1234567890abcdWXYZ');
        egal('ec_proba_1234567890abcdWXYZ', $s->citeste('ec_cheie'));
        egal('WXYZ', $s->ultimele4Din('ec_cheie'));
        $brut = (string) $m->baza()->valoare('SELECT valoare FROM {secrete} WHERE nume = ?', ['ec_cheie']);
        lipseste($brut, 'ec_proba');
        egal(null, $m->baza()->valoare('SELECT rotit_la FROM {secrete} WHERE nume = ?', ['ec_cheie']));
        $m->ceas()->avanseaza(60);
        $s->pune('ec_cheie', 'ec_proba_nouaCheie0000AAAA');
        egal('ec_proba_nouaCheie0000AAAA', $s->citeste('ec_cheie'));
        egal('2026-11-14 10:01:00', $m->baza()->valoare('SELECT rotit_la FROM {secrete} WHERE nume = ?', ['ec_cheie']));
        $s->pune('cron_token', 'scurt');
        egal('', $s->ultimele4Din('cron_token'), 'secretele scurte nu își arată finalul');
        arunca(fn () => $s->pune('altceva', 'x'), \InvalidArgumentException::class);
        $s->sterge('ec_cheie');
        egal(null, $s->citeste('ec_cheie'));
    },

    'un secret mutat în bază la alt nume nu se mai decriptează' => function (Mediu $m): void {
        $s = new Secrete($m->baza(), $m->chei(), $m->ceas());
        $s->pune('stripe_cheie', 'sk_live_cheiaStripe0000');
        $s->pune('ec_cheie', 'ec_live_AbCd1234_secretEasyCheck0000000000000000');
        $brut = (string) $m->baza()->valoare('SELECT valoare FROM {secrete} WHERE nume = ?', ['stripe_cheie']);
        $m->baza()->executa('UPDATE {secrete} SET valoare = ? WHERE nume = ?', [$brut, 'ec_cheie']);
        arunca(fn () => $s->citeste('ec_cheie'), \RuntimeException::class, 'Date criptate deteriorate');
        egal('sk_live_cheiaStripe0000', $s->citeste('stripe_cheie'));
    },
];
