<?php

declare(strict_types=1);

// Instalarea curată pe MySQL și SQLite, idempotența migrărilor, regulile schemei aditive.

use Cazo\Baza\Baza;
use Cazo\Baza\Schema;

return [
    'instalarea curată creează tabelele fundației, ale oglinzii EasyCheck, ale prezentării, ale comenzilor și ale plăților' => function (Mediu $m): void {
        $b = $m->baza(false);
        egal([], $b->tabele());
        $migrator = $m->migrator($b);
        egal(['0001', '0002', '0003', '0004', '0005', '0006', '0007', '0008', '0009', '0010', '0011', '0012', '0013', '0014', '0015', '0016', '0020', '0021', '0022', '0023', '0024', '0025', '0026', '0027', '0028', '0029', '0030', '0031', '0035', '0038', '0039', '0040', '0045', '0046', '0050', '0055', '0056', '0057', '0060', '0061', '0062', '0065', '0070', '0075', '0080'], $migrator->inAsteptare());
        egal(['0001', '0002', '0003', '0004', '0005', '0006', '0007', '0008', '0009', '0010', '0011', '0012', '0013', '0014', '0015', '0016', '0020', '0021', '0022', '0023', '0024', '0025', '0026', '0027', '0028', '0029', '0030', '0031', '0035', '0038', '0039', '0040', '0045', '0046', '0050', '0055', '0056', '0057', '0060', '0061', '0062', '0065', '0070', '0075', '0080'], $migrator->migreaza());
        egal([], $migrator->inAsteptare());
        $tabele = $b->tabele();
        sort($tabele);
        egal(['acorduri', 'alerte', 'audit', 'camere_local', 'coduri_email', 'coduri_rezerva', 'comenzi', 'comenzi_jurnal', 'contestari', 'continut', 'csp_rapoarte', 'date_externe', 'date_legale', 'ec_anunturi', 'ec_buget', 'ec_comenzi', 'ec_comenzi_rezultate', 'ec_cursoare', 'ec_mesaje', 'ec_plati', 'ec_resurse', 'emailuri', 'gdpr_easycheck', 'gdpr_stergeri', 'jetoane_utilizator', 'joburi', 'lansari', 'limite', 'local_camere', 'local_cereri', 'local_ical_nopti', 'local_ical_surse', 'local_idem', 'local_inchise', 'local_rezervari', 'local_trecere', 'local_unitati',
            'migrari', 'nopti', 'nopti_pret', 'oferte_destinatari', 'oferte_dezabonari', 'oferte_trimiteri', 'operatii_plata', 'pagini', 'planuri', 'planuri_local', 'plati', 'poze', 'poze_local', 'redirectari', 'reduceri_local', 'repere', 'sabloane_email', 'sarcini', 'schimbari_date', 'secrete', 'sesiuni_admin', 'setari', 'stripe_evenimente', 'taxe_ec', 'teme',
            'teme_setari', 'texte_legale', 'totaluri', 'utilizatori', 'zavoare'], $tabele);
        egal('test', $b->valoare('SELECT lansare FROM {migrari} WHERE versiune = ?', ['0001']));
        adevarat($m->baza()->dialect()->nume() === $m->dialect);
    },

    'o bază la nivelul main-ului (0021 aplicată) primește la actualizare doar migrările noi, în ordine' => function (Mediu $m): void {
        $b = $m->baza(false);
        // Instalarea de pe main: migrările până la 0021, fără cele ale ramurii.
        $vechi = sys_get_temp_dir() . '/cazo-migrari-' . bin2hex(random_bytes(4));
        mkdir($vechi);
        try {
            foreach (glob(Mediu::radacina() . '/migrari/*.php') ?: [] as $f) {
                if (substr(basename($f), 0, 4) <= '0021') {
                    copy($f, $vechi . '/' . basename($f));
                }
            }
            $main = new \Cazo\Baza\Migrator($b, $m->ceas(), $vechi, 'main');
            egal(['0001', '0002', '0003', '0004', '0005', '0006', '0007', '0008', '0009', '0010', '0011', '0012', '0013', '0014', '0015', '0016', '0020', '0021'], $main->migreaza());
            fals($b->tabelExista('audit'));
            adevarat($b->coloanaExista('operatii_plata', 'alerta_procesare_la'));
        } finally {
            array_map('unlink', glob($vechi . '/*.php') ?: []);
            rmdir($vechi);
        }
        // Actualizarea: aceeași bază, migrările de acum.
        $nou = $m->migrator($b);
        egal(array_values(array_filter(array_keys($nou->disponibile()), static fn (string $v): bool => $v > '0021')), $nou->inAsteptare());
        egal($nou->inAsteptare(), $nou->migreaza());
        egal([], $nou->inAsteptare());
        adevarat($b->tabelExista('audit'));
        egal('main', $b->valoare('SELECT lansare FROM {migrari} WHERE versiune = ?', ['0021']));
        egal('test', $b->valoare('SELECT lansare FROM {migrari} WHERE versiune = ?', ['0022']));
    },

    '0056: o bază care rulase varianta veche a lui 0030 primește coloanele adăugate târziu (eliberari, taxa_la_sosire)' => function (Mediu $m): void {
        $b = $m->baza(false);
        $vechi = sys_get_temp_dir() . '/cazo-migrari-0030-' . bin2hex(random_bytes(4));
        mkdir($vechi);
        try {
            foreach (glob(Mediu::radacina() . '/migrari/*.php') ?: [] as $f) {
                if (substr(basename($f), 0, 4) <= '0055') {
                    copy($f, $vechi . '/' . basename($f));
                }
            }
            // Varianta veche a lui 0030: fără ultimele două coloane.
            $f30 = $vechi . '/0030_plata_autorizare.php';
            $txt = (string) file_get_contents($f30);
            $txt = str_replace(["\$s->coloana('schimbari_date', 'eliberari', 'int implicit 0');", "\$s->coloana('comenzi', 'taxa_la_sosire', 'bigint null');"], '', $txt);
            file_put_contents($f30, $txt);
            (new \Cazo\Baza\Migrator($b, $m->ceas(), $vechi, 'veche'))->migreaza();
            fals($b->coloanaExista('schimbari_date', 'eliberari'));
            fals($b->coloanaExista('comenzi', 'taxa_la_sosire'));
        } finally {
            array_map('unlink', glob($vechi . '/*.php') ?: []);
            rmdir($vechi);
        }
        egal(['0056', '0057', '0060', '0061', '0062', '0065', '0070', '0075', '0080'], $m->migrator($b)->migreaza());
        adevarat($b->coloanaExista('schimbari_date', 'eliberari'));
        adevarat($b->coloanaExista('comenzi', 'taxa_la_sosire'));
        // Pe o bază la zi, 0056 nu schimbă nimic și nu dă eroare.
        $zi = $m->baza();
        egal([], $m->migrator($zi)->migreaza());
    },

    'a doua migrare nu mai face nimic, iar tabelele rămân' => function (Mediu $m): void {
        $b = $m->baza();
        $b->insereaza('setari', ['cheie' => 'x', 'valoare' => '1', 'actualizat_la' => $m->ceas()->moment()]);
        egal([], $m->migrator($b)->migreaza());
        // Chiar și rularea directă a migrării 0001 e idempotentă (o migrare întreruptă se reia).
        $mig = require Mediu::radacina() . '/migrari/0001_baza.php';
        ($mig['ruleaza'])(new Schema($b));
        egal('1', (string) $b->valoare('SELECT valoare FROM {setari} WHERE cheie = ?', ['x']));
    },

    'tabelele au cheile și coloanele așteptate' => function (Mediu $m): void {
        $b = $m->baza();
        $coloane = [
            'setari' => ['cheie', 'valoare', 'actualizat_la', 'actualizat_de'],
            'secrete' => ['nume', 'valoare', 'ultimele4', 'creat_la', 'rotit_la'],
            'limite' => ['cheie', 'fereastra', 'numar'],
            'zavoare' => ['nume', 'detinator', 'expira_la'],
            'migrari' => ['versiune', 'aplicat_la', 'lansare'],
            'joburi' => ['id', 'tip', 'cheie_unica', 'date', 'ruleaza_dupa', 'incercari', 'max_incercari', 'blocat_pana', 'eroare'],
            'sarcini' => ['nume', 'urmatoarea_la', 'ultima_la', 'durata_ms', 'rezultat', 'sursa'],
            'csp_rapoarte' => ['directiva', 'sursa', 'pagina', 'numar', 'prima_la', 'ultima_la'],
            'lansari' => ['versiune', 'instalat_la', 'rezultat', 'anterioara'],
            // Oglinda EasyCheck, cu avansul și anularea pe plan.
            'ec_resurse' => ['resursa', 'etag', 'corp', 'hash', 'citit_la', 'verificat_la', 'schimbat_la', 'invechit', 'versiune', 'ultima_eroare'],
            'nopti' => ['camera', 'zi', 'libere', 'inchis'],
            'nopti_pret' => ['camera', 'plan', 'zi', 'pret', 'sejur_minim', 'sejur_minim_prin', 'sejur_maxim', 'sosire_inchisa', 'plecare_inchisa', 'inchis'],
            'planuri' => ['id', 'titlu', 'fel', 'tip', 'prag_nopti', 'mod', 'din_plan', 'regula', 'rotunjire', 'pe_persoana', 'avans_procent', 'anulare_zile', 'masa', 'masa_eticheta', 'masa_eticheta_en'],
            'taxe_ec' => ['corp', 'versiune'],
            'poze' => ['sha256', 'url_sursa', 'latime', 'inaltime', 'pozitie', 'stare', 'variante', 'folosit_la'],
            'poze_local' => ['sha256', 'camera', 'rol', 'alt', 'ordine'],
            'ec_anunturi' => ['id', 'tip', 'creat_la', 'primit_la', 'procesat_la'],
            'ec_cursoare' => ['nume', 'valoare'],
            'ec_buget' => ['fereastra', 'citiri', 'scrieri', 'ramase', 'reset_la', 'pauza_pana'],
            // Comenzile și cererea cash.
            'comenzi' => ['plata_aleasa', 'platita_la', 'motiv_rambursare', 'id', 'ref', 'fel', 'stare', 'limba', 'proba', 'sosire', 'plecare', 'camere', 'plan', 'sursa_pret', 'total', 'componente', 'camere_parti',
                'reduceri', 'avans_procent', 'suma_acum', 'rest_la_sosire', 'anulare_zile', 'anulare_limita', 'oaspete', 'email_io', 'tara', 'nota',
                'marketing_acord', 'marketing_retras_la', 'idem_ec', 'corp_ec', 'ec_rezervare', 'ec_cerere', 'ec_expira_la', 'suprapunere', 'incercari',
                'urmatoarea_incercare', 'blocat_pana', 'eroare_cod', 'eroare_detalii', 'request_id', 'token_oaspete_hash', 'anulare_ceruta_la', 'ip24',
                'creat_la', 'actualizat_la', 'de_sters_la', 'anonimizat_la'],
            'comenzi_jurnal' => ['id', 'comanda_id', 'la', 'din_stare', 'in_stare', 'actor', 'detalii'],
            'acorduri' => ['id', 'comanda_id', 'termeni_versiune', 'termeni_hash', 'confidentialitate_versiune', 'text_buton', 'text_precontractual_hash',
                'marketing', 'text_marketing_hash', 'la', 'ip', 'agent'],
            'coduri_email' => ['id', 'email_io', 'cod_hash', 'incercari', 'expira_la', 'confirmat_la'],
            'emailuri' => ['id', 'comanda_id', 'sablon', 'limba', 'catre', 'date', 'stare', 'incercari', 'urmatoarea_incercare', 'trimis_la', 'eroare'],
            'utilizatori' => ['id', 'email', 'nume', 'parola_hash', 'rol', 'limba_panou', 'totp_secret', 'totp_ultimul_pas', 'blocat_pana', 'ultima_logare_la', 'activ', 'creat_la', 'poate_rambursa'],
            // plățile.
            'plati' => ['id', 'comanda_id', 'stripe_sesiune', 'stripe_pi', 'stare', 'suma', 'suma_incasata', 'suma_rambursata', 'comision', 'metoda', 'metoda_scurt', 'idem_sesiune', 'expira_la', 'actualizat_la'],
            'operatii_plata' => ['id', 'comanda_id', 'tip', 'suma', 'stripe_id', 'idem', 'stare', 'motiv', 'eroare', 'utilizator_id', 'la'],
            'stripe_evenimente' => ['id', 'tip', 'obiect', 'primit_la', 'procesat_la'],
            'sesiuni_admin' => ['id_hash', 'utilizator_id', 'creat_la', 'vazut_la', 'reautentificat_la', 'ip24', 'agent_scurt'],
            // panoul complet.
            'coduri_rezerva' => ['utilizator_id', 'cod_hash', 'folosit_la'],
            'audit' => ['id', 'la', 'utilizator_id', 'actiune', 'obiect', 'detalii', 'ip24', 'hash_anterior', 'hash'],
            'jetoane_utilizator' => ['utilizator_id', 'scop', 'jeton_hash', 'expira_la', 'folosit_la'],
            'alerte' => ['la', 'sablon', 'cheie', 'date', 'trimis'],
        ];
        foreach ($coloane as $t => $lista) {
            foreach ($lista as $c) {
                adevarat($b->coloanaExista($t, $c), "lipsește $t.$c");
            }
        }
        // Cheile primare și unicitatea: duplicatele se recunosc pe ambele dialecte.
        $b->insereaza('zavoare', ['nume' => 'a', 'detinator' => 'x', 'expira_la' => $m->ceas()->moment()]);
        $e = arunca(fn () => $b->insereaza('zavoare', ['nume' => 'a', 'detinator' => 'y', 'expira_la' => $m->ceas()->moment()]), \PDOException::class);
        adevarat($b->esteDuplicat($e));
        $j = ['tip' => 't', 'date' => '{}', 'ruleaza_dupa' => $m->ceas()->moment(), 'creat_la' => $m->ceas()->moment()];
        $b->insereaza('joburi', $j);
        $b->insereaza('joburi', $j);
        $b->insereaza('joburi', $j + ['cheie_unica' => 'u']);
        $e = arunca(fn () => $b->insereaza('joburi', $j + ['cheie_unica' => 'u']), \PDOException::class);
        adevarat($b->esteDuplicat($e), 'cheie_unica repetată');
        egal(3, (int) $b->valoare('SELECT COUNT(*) FROM {joburi}'));
        egal(6, (int) $b->valoare('SELECT max_incercari FROM {joburi} LIMIT 1'), 'valoarea implicită');
    },

    'schema e doar aditivă: coloanele noi cer null sau valoare implicită' => function (Mediu $m): void {
        $b = $m->baza();
        $s = new Schema($b);
        $s->coloana('setari', 'nota', 'text(50) null');
        adevarat($b->coloanaExista('setari', 'nota'));
        $s->coloana('setari', 'nota', 'text(50) null');
        $s->coloana('setari', 'contor', 'int implicit 0');
        arunca(fn () => $s->coloana('setari', 'obligatorie', 'text(10)'), \LogicException::class, 'null sau de o valoare implicită');
        arunca(fn () => $s->coloana('setari', 'id2', 'id'), \LogicException::class);
        arunca(fn () => $s->tabel('x', ['a' => 'tip_necunoscut']), \InvalidArgumentException::class);
        arunca(fn () => $s->tabel('x', ['a' => "int implicit 0; DROP TABLE y"]), \InvalidArgumentException::class);
        $metode = array_map(fn (\ReflectionMethod $r) => $r->getName(), (new \ReflectionClass(Schema::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        foreach ($metode as $met) {
            adevarat(!preg_match('~sterge|drop|redenum|modific|rename|alter~i', $met), "metodă distructivă în Schema: $met");
        }
        // Un tabel existent primește la o migrare nouă doar coloanele lipsă.
        $s->tabel('setari', ['cheie' => 'cheie', 'valoare' => 'json', 'extra' => 'json null'], ['cheie']);
        adevarat($b->coloanaExista('setari', 'extra'));
        $s->index('setari', 'ix_setari_la', ['actualizat_la']);
        $s->index('setari', 'ix_setari_la', ['actualizat_la']);
    },

    'prefixul separă instalările din aceeași bază' => function (Mediu $m): void {
        $b = $m->baza();
        arunca(fn () => new Baza($b->pdo(), $b->dialect(), 'Pg-'), \InvalidArgumentException::class);
        arunca(fn () => new Baza($b->pdo(), $b->dialect(), "pg_`; DROP"), \InvalidArgumentException::class);
        $alta = new Baza($b->pdo(), $b->dialect(), $m->prefix() . 'b_');
        egal([], $alta->tabele());
        $m->migrator($alta)->migreaza();
        egal(67, count($alta->tabele()));
        $alta->insereaza('setari', ['cheie' => 'izolat', 'valoare' => '1', 'actualizat_la' => $m->ceas()->moment()]);
        egal(null, $b->valoare('SELECT 1 FROM {setari} WHERE cheie = ?', ['izolat']));
        foreach ($alta->tabele() as $t) {
            $alta->pdo()->exec('DROP TABLE ' . $alta->t($t));
        }
    },

    'tranzacțiile: anularea la excepție, contopirea celor imbricate' => function (Mediu $m): void {
        $b = $m->baza();
        $ins = fn (string $k) => $b->insereaza('setari', ['cheie' => $k, 'valoare' => '1', 'actualizat_la' => $m->ceas()->moment()]);
        arunca(fn () => $b->tranzactie(function () use ($ins): void {
            $ins('a');
            throw new \RuntimeException('anulare');
        }), \RuntimeException::class);
        egal(null, $b->valoare('SELECT 1 FROM {setari} WHERE cheie = ?', ['a']));
        $b->tranzactie(function () use ($b, $ins): void {
            $ins('b');
            $b->tranzactie(fn () => $ins('c'));
        });
        egal(2, (int) $b->valoare("SELECT COUNT(*) FROM {setari} WHERE cheie IN ('b', 'c')"));
    },

    'upsert pe ambele dialecte' => function (Mediu $m): void {
        $b = $m->baza();
        $b->upsert('limite', ['cheie' => 'k', 'fereastra' => 10, 'numar' => 1], ['cheie', 'fereastra'], ['numar' => ['expr' => $b->dialect()->citeaza('numar') . ' + 1']]);
        $b->upsert('limite', ['cheie' => 'k', 'fereastra' => 10, 'numar' => 1], ['cheie', 'fereastra'], ['numar' => ['expr' => $b->dialect()->citeaza('numar') . ' + 1']]);
        egal(2, (int) $b->valoare('SELECT numar FROM {limite}'));
        $b->upsert('setari', ['cheie' => 'u', 'valoare' => '"a"', 'actualizat_la' => '2026-01-01 00:00:00'], ['cheie'], ['valoare']);
        $b->upsert('setari', ['cheie' => 'u', 'valoare' => '"b"', 'actualizat_la' => '2026-02-02 00:00:00'], ['cheie'], ['valoare']);
        $r = $b->rand('SELECT valoare, actualizat_la FROM {setari} WHERE cheie = ?', ['u']);
        egal('"b"', $r['valoare']);
        egal('2026-01-01 00:00:00', $r['actualizat_la'], 'coloana neactualizată s-a schimbat');
        $b->upsert('setari', ['cheie' => 'u', 'valoare' => '"c"', 'actualizat_la' => '2026-03-03 00:00:00'], ['cheie'], []);
        egal('"b"', $b->valoare('SELECT valoare FROM {setari} WHERE cheie = ?', ['u']), 'upsert fără actualizări a suprascris');
    },
];
