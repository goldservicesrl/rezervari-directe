<?php

declare(strict_types=1);

// Coada de joburi, planificatorul, rulatorul și pseudo-cronul.

use Cazo\Baza\Zavor;
use Cazo\Joburi\Coada;
use Cazo\Joburi\Planificator;
use Cazo\Joburi\PseudoCron;
use Cazo\Joburi\Rulator;
use Cazo\Nucleu\Setari;

return [
    'jobul pus rulează o dată, cu datele lui, și dispare' => function (Mediu $m): void {
        $c = new Coada($m->baza(), $m->ceas());
        $primite = [];
        $c->inregistreaza('email:trimite', function (array $d) use (&$primite): void {
            $primite[] = $d;
        });
        $id = $c->pune('email:trimite', ['catre' => 7]);
        adevarat(is_int($id) && $id > 0);
        adevarat($c->ruleazaUnul());
        egal([['catre' => 7]], $primite);
        egal(null, $c->ruleazaUnul(), 'coada nu s-a golit');
        egal(0, $c->numar());
    },

    'întârzierea: jobul așteaptă termenul' => function (Mediu $m): void {
        $c = new Coada($m->baza(), $m->ceas());
        $c->inregistreaza('x', fn () => null);
        $c->pune('x', [], null, 120);
        egal(null, $c->ruleazaUnul());
        $m->ceas()->avanseaza(120);
        adevarat($c->ruleazaUnul());
    },

    'coalescența: zece anunțuri fac un singur job, cu datele cele mai noi' => function (Mediu $m): void {
        $c = new Coada($m->baza(), $m->ceas());
        $rulari = [];
        $c->inregistreaza('sincronizare:preturi', function (array $d) use (&$rulari): void {
            $rulari[] = $d['n'];
        });
        $prim = $c->pune('sincronizare:preturi', ['n' => 0], 'sinc:preturi');
        adevarat(is_int($prim));
        for ($i = 1; $i < 10; $i++) {
            egal(null, $c->pune('sincronizare:preturi', ['n' => $i], 'sinc:preturi'));
        }
        egal(1, $c->numar());
        adevarat($c->ruleazaUnul());
        egal([9], $rulari);
        egal(null, $c->ruleazaUnul());
        // După rulare, cheia unică e din nou liberă.
        adevarat(is_int($c->pune('sincronizare:preturi', ['n' => 10], 'sinc:preturi')));
    },

    'un anunț venit în timpul rulării face jobul să mai ruleze o dată' => function (Mediu $m): void {
        $c = new Coada($m->baza(), $m->ceas());
        $rulari = 0;
        $c->inregistreaza('sincronizare:disp', function () use (&$rulari, $c): void {
            $rulari++;
            if ($rulari === 1) {
                // Anunțul nou sosește cât jobul rulează.
                $c->pune('sincronizare:disp', ['nou' => true], 'sinc:disp');
            }
        });
        $c->pune('sincronizare:disp', [], 'sinc:disp');
        adevarat($c->ruleazaUnul());
        egal(1, $c->numar(), 'evenimentul din timpul rulării s-a pierdut');
        adevarat($c->ruleazaUnul());
        egal(2, $rulari);
        egal(0, $c->numar());
    },

    'eșecul se reia la 1, 5, 30 de minute, 2 h, 6 h, apoi jobul se oprește' => function (Mediu $m): void {
        $c = new Coada($m->baza(), $m->ceas());
        $c->inregistreaza('poze:descarca', function (): void {
            throw new \RuntimeException('cheia sk_live_ABCDEFGH1234 a eșuat');
        });
        // Implicit: prima rulare plus o reluare pentru fiecare pauză.
        $c->pune('poze:descarca');
        egal(Coada::MAX_INCERCARI, count(Coada::REINCERCARI) + 1);
        $pauze = [60, 300, 1800, 7200, 21600];
        foreach ($pauze as $p) {
            fals($c->ruleazaUnul());
            $m->ceas()->avanseaza($p - 1);
            egal(null, $c->ruleazaUnul(), "jobul a rulat înainte de pauza de $p s");
            $m->ceas()->avanseaza(1);
        }
        fals($c->ruleazaUnul());
        $m->ceas()->avanseaza(86400);
        egal(null, $c->ruleazaUnul(), 'jobul oprit a rulat din nou');
        $oprite = $c->oprite();
        egal(1, count($oprite));
        egal(6, (int) $oprite[0]['incercari']);
        contine((string) $oprite[0]['eroare'], 'sk_live_***');
        lipseste((string) $oprite[0]['eroare'], 'ABCDEFGH1234');
    },

    'tipul fără executant eșuează curat' => function (Mediu $m): void {
        $c = new Coada($m->baza(), $m->ceas());
        $c->pune('necunoscut:tip');
        fals($c->ruleazaUnul());
        contine((string) $m->baza()->valoare('SELECT eroare FROM {joburi}'), 'fără executant');
        arunca(fn () => $c->pune('Tip Invalid'), \InvalidArgumentException::class);
    },

    'preluarea e exclusivă între două conexiuni' => function (Mediu $m): void {
        $a = new Coada($m->conexiune(), $m->ceas());
        $b = new Coada($m->conexiune(), $m->ceas());
        $id = $a->pune('x');
        $ja = $a->preia($id);
        adevarat(is_array($ja));
        egal(null, $b->preia($id), 'jobul a fost preluat de două ori');
        egal([], $b->scadente());
        // Un proces mort: după BLOCARE_SECUNDE, jobul se poate prelua din nou.
        $m->ceas()->avanseaza(Coada::BLOCARE_SECUNDE);
        adevarat(is_array($b->preia($id)));
    },

    'planificatorul rulează sarcinile scadente și le fixează următorul termen' => function (Mediu $m): void {
        $p = new Planificator($m->baza(), $m->ceas());
        $rulari = 0;
        $p->inregistreaza('curatenie', 3600, 7, function () use (&$rulari): string {
            $rulari++;
            return 'gata';
        });
        $p->inregistreaza('cade', 600, 3, function (): void {
            throw new \RuntimeException('eroare de probă');
        });
        egal(2, count($p->scadente()));
        adevarat($p->ruleaza('curatenie', 'cpanel'));
        fals($p->ruleaza('cade', 'pseudo'));
        egal([], $p->scadente());
        $r = $m->baza()->rand('SELECT * FROM {sarcini} WHERE nume = ?', ['curatenie']);
        egal('2026-11-14 11:00:00', $r['urmatoarea_la']);
        egal('gata', $r['rezultat']);
        egal('cpanel', $r['sursa']);
        contine((string) $m->baza()->valoare('SELECT rezultat FROM {sarcini} WHERE nume = ?', ['cade']), 'eroare de probă');
        $m->ceas()->avanseaza(600);
        egal(['cade'], array_column($p->scadente(), 'nume'));
        arunca(fn () => $p->inregistreaza('x', 10, 1, fn () => null), \InvalidArgumentException::class);
    },

    'rulatorul urmează prioritățile lucrărilor de fundal și notează ultima rulare' => function (Mediu $m): void {
        $b = $m->baza();
        $ordine = [];
        $c = new Coada($b, $m->ceas());
        foreach (['email:trimite', 'comanda:finalizeaza', 'poze:descarca', 'cerere:urmareste', 'ec:sincronizeaza'] as $tip) {
            $c->inregistreaza($tip, function () use (&$ordine, $tip): void {
                $ordine[] = $tip;
            });
            $c->pune($tip);
        }
        $p = new Planificator($b, $m->ceas());
        $p->inregistreaza('curatenie', 3600, 7, function () use (&$ordine): string {
            $ordine[] = 'curatenie';
            return 'ok';
        });
        $r = new Rulator($c, $p, new Zavor($b, $m->ceas()), new Setari($b, $m->ceas()), $m->ceas());
        $raport = $r->ruleaza('cpanel', 10.0);
        egal(['comanda:finalizeaza', 'cerere:urmareste', 'ec:sincronizeaza', 'email:trimite', 'poze:descarca', 'curatenie'], $ordine);
        potriveste_tablou($raport, ['rulat' => true, 'sarcini' => 1, 'joburi' => 5, 'esecuri' => 0, 'ramase' => 0]);
        $ultima = (new Setari($b, $m->ceas()))->get('cron.ultima');
        egal('cpanel', $ultima['sursa']);
        egal('2026-11-14 10:00:00', $ultima['la']);
    },

    'rulatorul respectă bugetul de timp' => function (Mediu $m): void {
        $b = $m->baza();
        $c = new Coada($b, $m->ceas());
        $c->inregistreaza('email:lent', function (): void {
            usleep(60_000);
        });
        for ($i = 0; $i < 10; $i++) {
            $c->pune('email:lent');
        }
        $r = new Rulator($c, new Planificator($b, $m->ceas()), new Zavor($b, $m->ceas()), new Setari($b, $m->ceas()), $m->ceas());
        $raport = $r->ruleaza('pseudo', 0.15);
        adevarat($raport['joburi'] >= 1 && $raport['joburi'] <= 4, 'bugetul nu a oprit rularea: ' . $raport['joburi']);
        egal(10 - $raport['joburi'], $raport['ramase']);
    },

    'rulatorul se oprește când zăvorul cron e ocupat' => function (Mediu $m): void {
        $b = $m->baza();
        $z = new Zavor($b, $m->ceas());
        $z->ia('cron', 60);
        $c = new Coada($b, $m->ceas());
        $c->inregistreaza('x', fn () => null);
        $c->pune('x');
        $r = new Rulator($c, new Planificator($b, $m->ceas()), $z, new Setari($b, $m->ceas()), $m->ceas());
        egal(false, $r->ruleaza('pseudo')['rulat']);
        egal(1, $c->numar());
        arunca(fn () => $r->ruleaza('altceva'), \InvalidArgumentException::class);
    },

    'bugetele se adaptează la max_execution_time' => function (Mediu $m): void {
        egal(20, Rulator::buget('cpanel', 0));
        egal(20, Rulator::buget('cpanel', 30));
        egal(10, Rulator::buget('cpanel', 15));
        egal(8, Rulator::buget('pseudo', 0));
        egal(1, Rulator::buget('anunt', 3));
        egal(1, Rulator::prioritateJob('comanda:x'));
        egal(5, Rulator::prioritateJob('necunoscut'));
    },

    'pseudo-cronul: cel mult o dată la 60 de secunde' => function (Mediu $m): void {
        $f = $m->dosarTemporar() . '/cache/pseudo-cron';
        $p = new PseudoCron($f, $m->ceas());
        adevarat($p->eRandul());
        fals($p->eRandul());
        $m->ceas()->avanseaza(59);
        fals($p->eRandul());
        $m->ceas()->avanseaza(1);
        adevarat($p->eRandul());
    },

    'în modul de întreținere, cronul aplicației nu rulează nimic (fără încheierea actualizărilor)' => function (Mediu $m): void {
        $app = $m->aplicatie();
        $p = $app->privat();
        $ruleaza = [];
        $app->coada()->inregistreaza('email:proba', function () use (&$ruleaza): void {
            $ruleaza[] = 'email:proba';
        });
        $app->coada()->pune('email:proba');
        \Cazo\Nucleu\Intretinere::porneste($p, Mediu::ACUM + 900, str_repeat('j', 43));
        $raport = $app->rulator()->ruleaza('cpanel', 20.0);
        potriveste_tablou($raport, ['rulat' => true, 'sarcini' => 0, 'joburi' => 0]);
        egal([], array_column($app->baza()->toate('SELECT nume FROM {sarcini}'), 'nume'));
        egal([], $ruleaza, 'joburile așteaptă sfârșitul întreținerii');
        // După întreținere (oprită sau expirată), rulează din nou toate.
        $m->ceas()->avanseaza(901);
        $raport = $app->rulator()->ruleaza('cpanel', 20.0);
        adevarat($raport['sarcini'] > 1, 'celelalte sarcini');
        egal(['email:proba'], $ruleaza);
    },
];
