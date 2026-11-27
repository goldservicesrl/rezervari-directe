<?php

declare(strict_types=1);

// Plasa pentru SQLite-ul vechi. PHP-ul din unele găzduiri cPanel are un SQLite mai vechi de 3.32, care primește
// cel mult 999 de parametri pe instrucțiune („too many SQL variables”); pe mașinile de dezvoltare SQLite-ul e nou și
// greșeala trecea neobservată. Trei rânduri de apărare:
//   1. rulatorul de teste pune CAZO_LIMITA_PARAMETRI=900, iar `Baza` aruncă o excepție la orice instrucțiune cu mai
//      mulți parametri (și în serverele pornite de teste), deci orice test care trece limita pică oriunde;
//   2. sursele din app/ sunt citite: fiecare listă de `?` construită din date e ori împărțită cu `Baza::pachete()`, ori
//      trecută pe lista de mai jos, cu motivul pentru care rămâne mică;
//   3. locurile cu liste mari (oglinda EasyCheck, restaurarea unei copii) sunt probate aici cu peste 900 de valori.

use Cazo\Baza\Baza;

return [
    'în teste, o instrucțiune SQL cu peste 900 de parametri aruncă o excepție, pe orice bază' => function (Mediu $m): void {
        egal(Baza::PARAMETRI_MAX, Baza::limitaParametri(), 'rulatorul de teste pune limita din mediu');
        egal((string) Baza::PARAMETRI_MAX, getenv(Baza::MEDIU_LIMITA), 'serverele pornite de teste moștenesc limita');
        adevarat(Baza::PARAMETRI_MAX < 999, 'limita rămâne sub cei 999 de parametri ai SQLite-ului vechi');
        $b = $m->baza();
        $semne = static fn (int $n): string => implode(', ', array_fill(0, $n, '?'));
        // La limită trece; cu un parametru în plus, excepția numește numărul și drumul de urmat.
        egal(0, (int) $b->valoare('SELECT COUNT(*) FROM {setari} WHERE cheie IN (' . $semne(900) . ')', array_map('strval', range(1, 900))));
        $e = arunca(static fn () => $b->toate('SELECT cheie FROM {setari} WHERE cheie IN (' . $semne(901) . ')', array_map('strval', range(1, 901))), \LogicException::class, '901 parametri');
        contine($e->getMessage(), 'Baza::pachete()');
        // Limita se poate opri și reciti (un site instalat rulează fără ea).
        Baza::puneLimitaParametri(0);
        try {
            egal(0, Baza::limitaParametri());
        } finally {
            Baza::puneLimitaParametri(null);
        }
        egal(Baza::PARAMETRI_MAX, Baza::limitaParametri());
    },

    'Baza::pachete împarte listele sub limită, și pe rânduri cu mai multe coloane' => function (Mediu $m): void {
        egal([], Baza::pachete([]));
        egal([900, 900, 200], array_map('count', Baza::pachete(range(1, 2000))));
        egal([[1, 2, 3]], Baza::pachete([1, 2, 3]));
        foreach ([1, 2, 9, 10, 11, 50, 400, 900, 901, 5000] as $coloane) {
            $n = Baza::marimePachet($coloane);
            adevarat($n >= 1, "pachet gol la $coloane coloane");
            if ($coloane <= Baza::PARAMETRI_MAX) {
                adevarat($n * $coloane <= Baza::PARAMETRI_MAX, "$n rânduri x $coloane coloane trec de limită");
            }
        }
        egal(100, Baza::marimePachet(9, 100));
        egal(81, Baza::marimePachet(11, 100));
        egal([81, 81, 38], array_map('count', Baza::pachete(range(1, 200), 11, 100)));
    },

    'sursele: fiecare listă de „?” construită din date e împărțită în pachete sau e pe lista celor mărginite' => function (Mediu $m): void {
        // Fișierul => expresiile numărate care pot rămâne nepachetate, fiecare cu marginea ei. O listă nouă se împarte cu
        // Baza::pachete() (și atunci numără `$pachet`) sau se adaugă aici, cu motivul.
        $marginite = [
            'app/EasyCheck/Comenzi.php' => ['$v'],                    // stările în lucru (constanta IN_LUCRU) și comenzile sărite într-o rulare (cel mult 20)
            'app/Baza/Baza.php' => ['$valori'],                       // coloanele unui rând (cât are tabelul)
            'app/Baza/DialectSqlite.php' => ['$coloane'],             // la fel, la upsert
            'app/Baza/DialectMysql.php' => ['$coloane'],
            'app/Gdpr/Gdpr.php' => ['$x'],                            // listele de stări din constante (Gdpr::lista)
            'app/Controlere/AdminContinut.php' => ['$stari'],         // stările unui șablon de e-mail, din cod
            'app/Controlere/AdminLista.php' => ['$stari', '$ids'],    // stările unui grup din filtru, din constante; $ids: rândurile unei pagini a listei (cel mult PE_PAGINA)
            'app/Rezervare/Anulare.php' => ['self::INAINTE_DE_CONFIRMARE', '$ids'],   // $ids: cele două părți ale unei rambursări
            'app/Plati/Rambursari.php' => ['$stari'],                 // stările unei operații, din cod
            'app/Rezervare/SchimbareDate.php' => ['$stari'],
            'app/EasyCheck/Oglinda.php' => ['$coloane', '$pachet'],   // rândurile vin din Baza::marimePachet
            'app/Sistem/Copii.php' => ['$pachet'],                    // cheile vin din Baza::pachete
            'app/EasyCheck/RaportPlati.php' => ['$sarite'],           // rezervările amânate într-o rulare (cel mult $cate, 20)
        ];
        $radacina = Mediu::radacina();
        $gasite = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radacina . '/app', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!str_ends_with($f->getFilename(), '.php')) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($radacina) + 1));
            $s = (string) file_get_contents($f->getPathname());
            // O listă de semne de întrebare repetate altfel decât prin array_fill(0, count(...), ...): de scris în forma cunoscută.
            adevarat(!preg_match('~str_repeat\(\s*[\'"][^\'"]*\?~', $s), "$rel: lista de „?” se construiește cu array_fill(0, count(...), '?'), ca să poată fi verificată aici");
            if (!preg_match_all('~array_fill\(\s*0\s*,\s*count\(([^()]+)\)\s*,\s*(?:\'\?\'|"\?"|\$unul)\s*\)~', $s, $x)) {
                continue;
            }
            foreach ($x[1] as $numarat) {
                $gasite[$rel][] = trim($numarat);
                adevarat(in_array(trim($numarat), $marginite[$rel] ?? [], true),
                    "$rel: lista de „?” numărată din $numarat poate trece de " . Baza::PARAMETRI_MAX . ' de parametri; împărțiți-o cu Baza::pachete() sau treceți-o pe lista celor mărginite, cu motivul');
            }
            // Unde rândurile se împart în pachete, împărțirea vine din Baza (aceeași limită peste tot).
            if (in_array('$pachet', $gasite[$rel] ?? [], true)) {
                adevarat((bool) preg_match('~Baza::(pachete|marimePachet)\(|self::marimePachet\(~', $s), "$rel: pachetele se fac cu Baza::pachete()");
            }
        }
        // Lista de mai sus descrie sursele de acum: o intrare rămasă fără loc în cod se scoate.
        foreach ($marginite as $rel => $expresii) {
            foreach ($expresii as $e) {
                adevarat(in_array($e, $gasite[$rel] ?? [], true), "$rel: $e e pe lista celor mărginite, dar lipsește din sursă");
            }
        }
    },

    'restaurarea unei copii cu peste 900 de setări de șters și de pus la loc rămâne sub limită' => function (Mediu $m): void {
        $app = $m->aplicatie();
        $s = $app->setari();
        for ($i = 0; $i < 950; $i++) {
            $s->pune('proba.veche.' . $i, $i);
        }
        $s->pune('ec.proba_pe_loc', 'rămâne');
        $nume = $app->copii()->creeaza();
        for ($i = 0; $i < 950; $i++) {
            $s->pune('proba.noua.' . $i, $i);
        }
        $s->pune('ec.proba_pe_loc', 'schimbată după copie');
        $app->copii()->restaureaza($nume);
        $b = $app->baza();
        egal(950, (int) $b->valoare("SELECT COUNT(*) FROM {setari} WHERE cheie LIKE 'proba.veche.%'"));
        egal(0, (int) $b->valoare("SELECT COUNT(*) FROM {setari} WHERE cheie LIKE 'proba.noua.%'"));
        // Setările care rămân pe loc (legătura cu EasyCheck) sunt cele de acum, nu cele din copie.
        egal('schimbată după copie', $app->setari()->proaspat('ec.proba_pe_loc'));
    },
];
