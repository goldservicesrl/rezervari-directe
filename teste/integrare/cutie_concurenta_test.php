<?php

declare(strict_types=1);

// Cutia de e-mailuri sub două rulări simultane (cererea și cronul, ori două cereri): fiecare e-mail pleacă o singură
// dată. O rulare care a ales un e-mail din coadă îl preia doar dacă e tot în coadă sau rămas în lucru de peste 10 minute.

use Cazo\Email\Cutie;
use Cazo\Email\Mesaj;
use Cazo\Email\Transport;

return [
    'două rulări simultane: e-mailul preluat de cealaltă rulare chiar acum nu pleacă a doua oară; cel rămas în lucru de 10 minute se reia' => function (Mediu $m): void {
        $app = $m->aplicatie();
        $b = $app->baza();
        $acum = $app->ceas()->moment();
        $trimitere = $b->insereaza('oferte_trimiteri', ['subiect' => 'Oferta', 'text' => 'Text', 'limba' => 'ro', 'destinatari' => 2, 'creat_la' => $acum]);
        $unu = $app->emailuri()->pune(null, Cutie::SABLON_OFERTA, 'ro', 'ana@exemplu.ro', ['trimitere' => $trimitere, 'nume' => '']);
        $doi = $app->emailuri()->pune(null, Cutie::SABLON_OFERTA, 'ro', 'bob@exemplu.ro', ['trimitere' => $trimitere, 'nume' => '']);
        // Rularea aceasta a ales amândouă e-mailurile; cât îl trimite pe primul, o altă rulare îl preia pe al doilea.
        $posta = new class ($b, $doi, $acum) implements Transport {
            /** @var list<string> */
            public array $catre = [];

            public function __construct(private \Cazo\Baza\Baza $b, private int $doi, private string $acum)
            {
            }

            public function trimite(Mesaj $m): void
            {
                $this->catre[] = $m->catre;
                $this->b->executa("UPDATE {emailuri} SET stare = 'in_lucru', urmatoarea_incercare = ? WHERE id = ? AND stare = 'in_coada'", [$this->acum, $this->doi]);
            }

            public function nume(): string
            {
                return 'memorie';
            }
        };
        $app->transportEmail = $posta;
        egal(['trimise' => 1, 'esuate' => 0], $app->emailuri()->trimite());
        egal(['ana@exemplu.ro'], $posta->catre);
        egal(['trimis', 'in_lucru'], [$b->valoare('SELECT stare FROM {emailuri} WHERE id = ?', [$unu]), $b->valoare('SELECT stare FROM {emailuri} WHERE id = ?', [$doi])]);
        // Rularea care l-a preluat s-a oprit: după 10 minute, e-mailul se reia și pleacă o dată.
        $m->ceas()->avanseaza(601);
        egal(['trimise' => 1, 'esuate' => 0], $app->emailuri()->trimite());
        egal(['ana@exemplu.ro', 'bob@exemplu.ro'], $posta->catre);
    },
];
