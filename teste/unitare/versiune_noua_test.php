<?php

declare(strict_types=1);

// Anunțul versiunii noi: fișierul public https://cazo.ro/versiune.json, citit zilnic, ținut în setări, tolerant
// la eșec, cu e-mailul către proprietari o singură dată pe versiune. Răspunsurile cazo.ro sunt false, fără rețea.

use Cazo\Actualizari\VersiuneNoua;
use Cazo\DateExterne\EroareExterna;
use Cazo\DateExterne\Http;
use Cazo\Dezvoltare\Lansare;
use Cazo\Nucleu\Setari;

$versiuneNoua = static function (Mediu $m, \Closure $raspuns, array &$cereri = [], array &$anunturi = [], ?string $sursa = VersiuneNoua::SURSA): VersiuneNoua {
    $http = new class ($raspuns, $cereri) implements Http {
        public function __construct(private \Closure $raspuns, private array &$cereri)
        {
        }

        public function get(string $url, array $antete, float $timp): array
        {
            $this->cereri[] = [$url, $antete];
            return ($this->raspuns)($url) + ['antete' => []];
        }
    };
    return new VersiuneNoua(new Setari($m->baza(), $m->ceas()), $m->ceas(), static fn (): Http => $http, 'https://github.com/goldservicesrl/rezervari-directe', $sursa,
        static function (array $v) use (&$anunturi): void {
            $anunturi[] = $v;
        });
};

$json = static fn (array $x): array => ['status' => 200, 'corp' => (string) json_encode($x)];

return [
    'versiunea mai nouă de pe cazo.ro apare cu data, notele și legătura; e-mailul pleacă o singură dată pe versiune' => function (Mediu $m) use ($versiuneNoua, $json): void {
        $cereri = [];
        $anunturi = [];
        $corp = ['versiune' => '0.2.0', 'data' => '2026-12-10', 'note' => 'Reparații la calendar.', 'descarcare' => 'https://github.com/goldservicesrl/rezervari-directe/releases/tag/v0.2.0', 'securitate' => false];
        $v = $versiuneNoua($m, static function (string $u) use (&$corp, $json): array {
            return $json($corp);
        }, $cereri, $anunturi);
        egal('versiune nouă: 0.2.0 (anunțată)', $v->verifica('0.1.0'));
        egal([[VersiuneNoua::SURSA, ['Accept' => 'application/json']]], $cereri, 'o cerere GET simplă, fără date despre site');
        egal(['versiune' => '0.2.0', 'data' => '2026-12-10', 'note' => ['ro' => 'Reparații la calendar.', 'en' => 'Reparații la calendar.'],
            'url' => 'https://github.com/goldservicesrl/rezervari-directe/releases/tag/v0.2.0', 'securitate' => false], $v->noua('0.1.0'));
        egal(1, count($anunturi));
        egal('0.2.0', $anunturi[0]['versiune']);
        // A doua zi, aceeași versiune: nimic de anunțat din nou.
        $m->ceas()->avanseaza(86400);
        egal('ultima versiune: 0.2.0', $v->verifica('0.1.0'));
        egal(1, count($anunturi));
        // O versiune și mai nouă: un e-mail nou, cu notele pe limbi și semnul de securitate.
        $corp = ['versiune' => '0.2.1', 'data' => '2026-12-12', 'note' => ['ro' => 'Securitate.', 'en' => 'Security.'], 'descarcare' => 'https://cazo.ro/descarcare/', 'securitate' => true];
        $v->verifica('0.1.0');
        egal(2, count($anunturi));
        egal(['ro' => 'Securitate.', 'en' => 'Security.'], $anunturi[1]['note']);
        adevarat($anunturi[1]['securitate']);
        // Site-ul la zi: nici anunț, nici e-mail.
        egal(null, $v->noua('0.2.1'));
        egal(null, $v->noua('0.10.0'));
        egal(null, $v->noua('dev'));
        $m->ceas()->avanseaza(86400);
        $v->verifica('0.2.1');
        egal(2, count($anunturi));
    },

    'eșecurile păstrează ultima versiune văzută: rețeaua, coduri de eroare, corp greșit, legătură străină' => function (Mediu $m) use ($versiuneNoua, $json): void {
        $anunturi = [];
        $cereri = [];
        $raspuns = $json(['versiune' => '0.3.1', 'data' => '2026-12-20', 'descarcare' => 'https://github.com/goldservicesrl/rezervari-directe/releases/tag/v0.3.1']);
        $v = $versiuneNoua($m, static function (string $u) use (&$raspuns): array {
            if ($raspuns === null) {
                throw new EroareExterna('fără rețea');
            }
            return $raspuns;
        }, $cereri, $anunturi);
        $v->verifica('0.1.0');
        egal('0.3.1', $v->noua('0.1.0')['versiune'] ?? null);
        $prima = $v->stare()['verificat_la'];
        foreach ([null, ['status' => 503, 'corp' => ''], ['status' => 404, 'corp' => 'Not Found'], ['status' => 200, 'corp' => '<html>'],
            $json(['versiune' => '9.0.0-beta', 'data' => '2026-12-21', 'descarcare' => 'https://github.com/x/y']),
            $json(['versiune' => '9.0.0', 'data' => '21.12.2026', 'descarcare' => 'https://github.com/x/y']),
            $json(['versiune' => '9.0.0', 'data' => '2026-12-21', 'descarcare' => 'https://evil.example/cazo.zip']),
            $json(['versiune' => '9.0.0', 'data' => '2026-12-21', 'descarcare' => 'http://github.com/x/y']),
            $json(['versiune' => '9.0.0', 'data' => '2026-12-21']),
            $json(['versiune' => '9.0.0', 'data' => '2026-12-21', 'descarcare' => 'https://github.com/atacator/rezervari-directe/releases/tag/v9.0.0']),
            $json(['versiune' => '9.0.0', 'data' => '2026-12-21', 'descarcare' => 'https://github.com/goldservicesrl/rezervari-directe-fals/releases/tag/v9.0.0']),
            $json(['versiune' => '9.0.0', 'data' => '2026-12-21', 'descarcare' => 'https://github.com/goldservicesrl/rezervari-directe/releases/../../../x/y']),
            $json(['versiune' => '9.0.0', 'data' => '2026-12-21', 'descarcare' => 'https://www.cazo.ro/descarcare/']),
            $json(['versiune' => '9.0.0', 'data' => '2026-12-21', 'descarcare' => 'https://cazo.ro/alt/fisier.zip'])] as $i => $r) {
            $m->ceas()->avanseaza(86400);
            $raspuns = $r;
            $v->verifica('0.1.0');
            egal('0.3.1', $v->noua('0.1.0')['versiune'] ?? null, "cazul $i");
            egal($prima, $v->stare()['verificat_la'], "cazul $i");
            egal($m->ceas()->moment(), $v->stare()['eroare_la'], "cazul $i");
        }
        egal(1, count($anunturi));
    },

    'fără sursă (în mediul de test fără actualizari.sursa), nicio cerere' => function (Mediu $m) use ($versiuneNoua): void {
        $cereri = [];
        $anunturi = [];
        $v = $versiuneNoua($m, static fn (string $u): array => ['status' => 200, 'corp' => '{}'], $cereri, $anunturi, null);
        egal('fără sursă', $v->verifica('0.1.0'));
        egal([], $cereri);
        egal(null, $v->noua('0.1.0'));
    },

    'versiune.json scris de lansare se citește înapoi la fel' => function (Mediu $m): void {
        $j = Lansare::versiuneJson('0.1.1', '2026-12-10', ['ro' => 'Note.', 'en' => 'Notes.'], true);
        $c = VersiuneNoua::citeste($j);
        egal(['versiune' => '0.1.1', 'data' => '2026-12-10', 'note' => ['ro' => 'Note.', 'en' => 'Notes.'],
            'descarcare' => \Cazo\Nucleu\Aplicatie::ADRESA_PROIECT . '/releases/tag/v0.1.1', 'securitate' => true], $c);
        egal(null, VersiuneNoua::citeste('[]'));
    },
];
