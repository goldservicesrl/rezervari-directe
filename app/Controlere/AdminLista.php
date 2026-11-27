<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\I18n\Format;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;
use Cazo\Rezervare\MasinaStari;
use Cazo\Securitate\SesiuneAdmin;

defined('CAZO') or exit;

/**
 * Listele panoului:
 *
 *   GET  /admin/rezervari                     rezervările, cu filtrele: starea (pe grupuri), perioada (sosirea), plata
 *                                             (cardul sau la sosire), codul rezervării; 50 pe pagină; `format=csv`
 *                                             (proprietarul)
 *   POST /admin/rezervari                     căutarea după oaspete: adresa de e-mail întreagă (indexul orb) sau o
 *                                             parte din nume (numele se descifrează în memorie, pe ultimele
 *                                             CAUTARE_NUME rezervări); ce a scris gazda rămâne în afara adresei
 *   POST /admin/rezervari/{ref}/retrimite     confirmarea trimisă din nou oaspetelui (14: „retrimiterea confirmării”)
 *   GET  /admin/plati                         toate plățile online (proprietarul și angajații cu dreptul de
 *                                             rambursare), cu filtrele starea și perioada
 *   jurnalul alertelor trimise gazdei: blocul „Jurnalul” de pe Setări › Întreținere (AdminCompus)
 *
 * Filtrele stau în adresă: codul rezervării, stările, datele; nicio dată personală. Căutarea după oaspete
 * merge prin POST, iar răspunsul ei e chiar pagina cu rezultatele (fără redirecționare, deci fără nume în adresă).
 */
final class AdminLista
{
    public const PE_PAGINA = 50;
    public const MESAJE = ['retrimis' => 'admin.rezervari.retrimis', 'reluata' => 'admin.rezervari.reluata'];
    public const ERORI = ['retrimis_limita' => 'admin.rezervari.retrimis_limita', 'retrimis_stare' => 'admin.rezervari.retrimis_stare', 'reia_stare' => 'admin.rezervari.reia_stare',
        // confirmarea se retrimite din EasyCheck.
        'retrimis_ec' => 'admin.rezervari.retrimis_ec'];

    /** Căutarea după nume: câte rezervări (cele mai noi) se descifrează și câte rezultate se arată. */
    public const CAUTARE_NUME = 2000;
    public const CAUTARE_REZULTATE = 50;

    /**
     * Stările, pe grupurile din filtru, în ordinea de pe ecran. Fiecare grup: stările arătate ca alegeri și
     * stările de trecere (câteva secunde sau minute), care intră doar în alegerea „tot grupul”.
     */
    public const GRUPURI_STARI = [
        'asteptare' => [['cerere_in_asteptare', 'transfer_in_asteptare', 'anulare_ceruta', 'plata_in_procesare'], ['noua', 'trimitere_ec', 'plata_in_curs', 'platita', 'de_capturat']],
        'confirmate' => [['confirmata'], []],
        'anulate' => [['anulata_oaspete', 'anulata_gazda', 'rambursata', 'inchisa_gazda'], []],
        'refuzate' => [['cerere_refuzata', 'cerere_expirata', 'transfer_neprimit', 'transfer_expirat', 'indisponibila', 'plata_esuata', 'abandonata'], ['autorizare_anulata']],
        'probleme' => [['anulare_de_lamurit', 'de_rambursat', 'respinsa_ec'], []],
    ];

    /** Clasa etichetei colorate a unei stări (`pa-stare-…`). */
    public static function clasaStare(string $stare): string
    {
        return match (true) {
            $stare === 'confirmata' => 'pa-stare-confirmata',
            in_array($stare, ['anulata_oaspete', 'anulata_gazda', 'rambursata', 'inchisa_gazda', 'abandonata', 'autorizare_anulata'], true) => 'pa-stare-anulata',
            in_array($stare, ['cerere_refuzata', 'cerere_expirata', 'transfer_neprimit', 'transfer_expirat', 'indisponibila', 'plata_esuata'], true) => 'pa-stare-refuzata',
            in_array($stare, self::GRUPURI_STARI['probleme'][0], true) => 'pa-stare-problema',
            default => 'pa-stare-asteapta',
        };
    }

    /**
     * Starea pe înțelesul gazdei; codul respingerii din EasyCheck rămâne la „Detalii pentru suport”. Cu rândul comenzii,
     * stările transferului bancar își spun suma și termenul („Așteaptă transferul”, „Plătită prin transfer”…).
     */
    public static function textStare(\Cazo\I18n\Traducator $t, string $stare, ?array $cm = null): string
    {
        if ($cm !== null && ($text = \Cazo\Rezervare\Transfer::stareAdmin($t, $cm)) !== null) {
            return $text;
        }
        return $t->t($stare === 'respinsa_ec' ? 'admin.stare.respinsa_ec_simplu' : 'admin.stare.' . $stare);
    }

    /** @return array<string,string> numele camerelor din oglinda EasyCheck (`titlu`), după id */
    public static function titluriCamere(Aplicatie $app): array
    {
        $r = [];
        foreach ((array) ($app->ecOglinda()->corp('camere')['camere'] ?? []) as $cam) {
            if (is_array($cam) && is_string($cam['id'] ?? null) && trim((string) ($cam['titlu'] ?? '')) !== '') {
                $r[$cam['id']] = (string) $cam['titlu'];
            }
        }
        return $r;
    }

    /** Camerele unei rezervări, cu numele lor; o cameră scoasă între timp din EasyCheck apare cu un text, nu cu codul ei. */
    public static function camere(Aplicatie $app, \Cazo\I18n\Traducator $t, array $cm, array $titluri): string
    {
        $r = [];
        foreach ($app->comenzi()->camere($cm) as $x) {
            $r[] = $titluri[(string) ($x['camera'] ?? '')] ?? $t->t('admin.rez.camera_scoasa');
        }
        return implode(', ', $r);
    }

    /**
     * „Reia finalizarea” (14 Rezervări, M6b): trimiterea spre EasyCheck, acum, fără așteptarea reluării automate. Pe o
     * plată cu cardul încasată și netrimisă (`platita`) și pe o cerere cash rămasă la trimitere (`trimitere_ec`, cu
     * lacătul expirat). Aceeași funcție ca reluarea automată (Finalizare, CerereCash), cu aceleași chei.
     */
    public static function poateRelua(array $cm, int $acum): bool
    {
        $liber = $cm['blocat_pana'] === null || \Cazo\Nucleu\Ceas::dinMoment((string) $cm['blocat_pana']) <= $acum;
        return ($cm['fel'] === 'card' && $cm['stare'] === 'platita') || (in_array($cm['fel'], ['cash', 'transfer'], true) && $cm['stare'] === 'trimitere_ec' && $liber);
    }

    public static function reia(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'rambursari');
        $cm = $app->comenzi()->dupaRef((string) $p['ref']);
        if ($s === null || $cm === null) {
            return AdminPanou::inapoi($app);
        }
        $inapoi = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/rezervari/' . $cm['ref'], $q);
        if (!self::poateRelua($cm, $app->ceas()->acum())) {
            return $inapoi(['eroare' => 'reia_stare']);
        }
        $actor = 'admin:' . (int) $s[0]['id'];
        $stare = $cm['fel'] === 'card' ? $app->finalizare()->ruleaza((int) $cm['id'], $actor)
            : ($cm['fel'] === 'transfer' ? $app->transfer()->trimite((int) $cm['id'], $actor, false) : $app->cerereCash()->trimite((int) $cm['id'], $actor, false));
        $app->auditDetalii(['stare' => $stare]);
        $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
        return $inapoi(['mesaj' => 'reluata']);
    }

    /** @return list<string> toate stările, din mașina de stări */
    public static function stari(): array
    {
        $r = [];
        foreach (MasinaStari::TRANZITII as $fel) {
            foreach ($fel as $din => $in) {
                $r[$din] = true;
                foreach ($in as $x) {
                    $r[$x] = true;
                }
            }
        }
        $r = array_keys($r);
        sort($r);
        return $r;
    }

    public static function rezervari(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        // plățile online și avansurile sunt vederi ale paginii Rezervări; fiecare își păstrează dreptul.
        $vedere = $c->param('vedere');
        if ($vedere === 'plati') {
            return self::plati($app, $c, $p, $limba);
        }
        if ($vedere === 'avansuri') {
            return AdminRezervari::avansuri($app, $c, $p, $limba);
        }
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $f = self::filtre($c);
        [$where, $param] = self::where($f);
        $csv = $c->param('format') === 'csv' && $u['rol'] === 'proprietar';
        $pagina = max(1, (int) ($c->param('pagina') ?? 1));
        $sql = 'SELECT * FROM {comenzi}' . $where . ' ORDER BY sosire DESC, id DESC';
        $randuri = $app->baza()->toate($sql . ($csv ? ' LIMIT 5000' : ' LIMIT ' . self::PE_PAGINA . ' OFFSET ' . (($pagina - 1) * self::PE_PAGINA)), $param);
        $total = (int) $app->baza()->valoare('SELECT COUNT(*) FROM {comenzi}' . $where, $param);
        if ($csv) {
            $titluri = self::titluriCamere($app);
            $date = [[$t->t('admin.csv.ref'), $t->t('admin.rezervari.col.stare'), $t->t('admin.rezervari.col.plata'), $t->t('admin.csv.sosire'), $t->t('admin.csv.plecare'),
                $t->t('admin.rezervari.col.camera'), $t->t('admin.rezervari.col.total'), $t->t('admin.csv.oaspete'), $t->t('admin.rezervari.col.creata')]];
            foreach ($randuri as $cm) {
                $o = $app->comenzi()->oaspete($cm);
                // Momentul creării: în fusul site-ului și în formatul limbii, ca în panou (nu UTC-ul brut din bază).
                $date[] = [$cm['ref'], self::textStare($t, (string) $cm['stare'], $cm), $t->t('admin.rezervari.fel.' . $cm['fel']), $cm['sosire'], $cm['plecare'],
                    self::camere($app, $t, $cm, $titluri), self::lei((int) $cm['total']), (string) ($o['nume'] ?? ''), Format::moment((string) $cm['creat_la'], $l)];
            }
            $app->audit()->scrie('admin-rezervari-csv', null, ['randuri' => count($randuri)], (int) $u['id']);
            return self::csv($date, 'rezervari-' . $app->azi() . '.csv');
        }
        $q = array_filter($f, static fn ($v): bool => $v !== '');
        $url = ['csv' => $u['rol'] === 'proprietar' ? $app->url()->cale('/admin/rezervari', null, $q + ['format' => 'csv']) : null,
            'inainte' => $pagina > 1 ? $app->url()->cale('/admin/rezervari', null, $q + ['pagina' => (string) ($pagina - 1)]) : null,
            'dupa' => $pagina * self::PE_PAGINA < $total ? $app->url()->cale('/admin/rezervari', null, $q + ['pagina' => (string) ($pagina + 1)]) : null];
        return self::paginaRezervari($app, $c, $u, $jeton, $randuri, $total, $f, null, $url);
    }

    /**
     * POST /admin/rezervari: căutarea după oaspete. Cu „@”, adresa de e-mail întreagă, prin indexul orb (fără
     * nicio descifrare); altfel o parte din nume, căutată în memorie pe cele mai noi CAUTARE_NUME rezervări cu date
     * (numele stau criptate, deci baza nu le poate căuta). Răspunsul e pagina cu rezultatele, fără redirecționare: ce a
     * scris gazda nu ajunge în adresă, iar în jurnalul de audit intră doar felul căutării și numărul rezultatelor.
     */
    public static function cauta(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $text = mb_substr(trim((string) ($c->camp('oaspete') ?? '')), 0, 100);
        if ($text === '') {
            return AdminPanou::inapoi($app, '/admin/rezervari');
        }
        $b = $app->baza();
        $gasite = [];
        $scurt = false;
        if (str_contains($text, '@')) {
            $gasite = $b->toate('SELECT * FROM {comenzi} WHERE email_io = ? AND anonimizat_la IS NULL ORDER BY sosire DESC, id DESC LIMIT ' . self::CAUTARE_REZULTATE, [$app->comenzi()->emailIo($text)]);
        } elseif (mb_strlen($text) < 2) {
            $scurt = true;
        } else {
            $cautat = self::simplu($text);
            foreach ($b->toate('SELECT * FROM {comenzi} WHERE anonimizat_la IS NULL ORDER BY id DESC LIMIT ' . self::CAUTARE_NUME) as $cm) {
                if (str_contains(self::simplu((string) ($app->comenzi()->oaspete($cm)['nume'] ?? '')), $cautat)) {
                    $gasite[] = $cm;
                    if (count($gasite) >= self::CAUTARE_REZULTATE) {
                        break;
                    }
                }
            }
            usort($gasite, static fn (array $x, array $y): int => [$y['sosire'], (int) $y['id']] <=> [$x['sosire'], (int) $x['id']]);
        }
        $app->auditDetalii(['cautare' => str_contains($text, '@') ? 'email' : 'nume', 'gasite' => count($gasite)]);
        return self::paginaRezervari($app, $c, $u, $jeton, $gasite, count($gasite), self::filtre($c, true),
            ['text' => $text, 'scurt' => $scurt, 'plin' => count($gasite) >= self::CAUTARE_REZULTATE], []);
    }

    /** Numele pentru comparare: litere mici, fără diacritice, cu spațiile strânse. */
    public static function simplu(string $s): string
    {
        $s = strtr(mb_strtolower($s, 'UTF-8'), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'á' => 'a', 'à' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e',
            'ë' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss']);
        return trim((string) preg_replace('~\s+~u', ' ', $s));
    }

    /** Pagina listei: rândurile găsite de filtre sau de căutarea după oaspete ($cautare), cu aceleași coloane. */
    private static function paginaRezervari(Aplicatie $app, Cerere $c, array $u, string $jeton, array $randuri, int $total, array $f, ?array $cautare, array $url): Raspuns
    {
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $titluri = self::titluriCamere($app);
        // pe fiecare rând, cât s-a plătit până acum (online sau prin transfer, după rambursări) și ce rămâne la sosire.
        $platite = [];
        $ids = array_map(static fn (array $cm): int => (int) $cm['id'], $randuri);
        if ($ids !== []) {
            foreach ($app->baza()->toate('SELECT comanda_id, SUM(suma_incasata) AS incasat, SUM(suma_rambursata) AS rambursat FROM {plati} WHERE comanda_id IN ('
                . implode(', ', array_fill(0, count($ids), '?')) . ') GROUP BY comanda_id', $ids) as $x) {
                $platite[(int) $x['comanda_id']] = [(int) $x['incasat'], (int) $x['rambursat']];
            }
        }
        $lista = [];
        // în modurile E și P, „Deschideți în EasyCheck” pe fiecare rezervare ajunsă acolo.
        $inEc = $app->modEc()->deciziiInEasyCheck();
        foreach ($randuri as $cm) {
            $o = $app->comenzi()->oaspete($cm);
            [$incasat, $rambursat] = $cm['fel'] === 'transfer' ? [$cm['platita_la'] !== null ? (int) $cm['suma_acum'] : 0, 0] : ($platite[(int) $cm['id']] ?? [0, 0]);
            $lista[] = ['ref' => (string) $cm['ref'], 'creata' => Format::moment((string) $cm['creat_la'], $l),
                'platit' => Format::bani($incasat - $rambursat, $l), 'returnat' => $rambursat > 0 ? $t->t('admin.rezervari.returnat', ['suma' => Format::bani($rambursat, $l)]) : '',
                'la_sosire' => Format::bani((int) $cm['rest_la_sosire'], $l),
                'sejur' => Format::zi((string) $cm['sosire'], $l) . ' - ' . Format::zi((string) $cm['plecare'], $l), 'camera' => self::camere($app, $t, $cm, $titluri),
                'oaspete' => $cm['anonimizat_la'] !== null ? $t->t('admin.rezervari.anonimizata') : (string) ($o['nume'] ?? ''), 'total' => Format::bani((int) $cm['total'], $l),
                'fel' => $t->t('admin.rezervari.fel.' . $cm['fel']), 'stare' => self::textStare($t, (string) $cm['stare'], $cm), 'stare_clasa' => self::clasaStare((string) $cm['stare']),
                'suprapunere' => (int) $cm['suprapunere'] === 1,
                'ec_url' => $inEc && (string) ($cm['ec_rezervare'] ?? '') !== '' ? $app->modEc()->urlRezervare($cm) : null];
        }
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'rezervari', self::MESAJE, self::ERORI);
        // Filtrul stărilor, pe grupuri: „tot grupul” și stările lui pe care gazda le poate întâlni mai mult de câteva secunde.
        $grupuri = [];
        foreach (self::GRUPURI_STARI as $g => [$aratate]) {
            $nume = $t->t('admin.rezervari.grup.' . $g);
            $optiuni = count($aratate) > 1 ? [['valoare' => 'grup-' . $g, 'text' => $t->t('admin.rezervari.grup_tot', ['grup' => $nume])]] : [];
            foreach ($aratate as $x) {
                $optiuni[] = ['valoare' => $x, 'text' => self::textStare($t, $x)];
            }
            $grupuri[] = ['nume' => $nume, 'optiuni' => $optiuni];
        }
        // O stare de trecere cerută prin adresă (o legătură veche) rămâne aleasă, ca filtrul să spună ce arată lista.
        $inFiltru = str_starts_with($f['stare'], 'grup-') || $f['stare'] === '';
        foreach (self::GRUPURI_STARI as [$aratate]) {
            $inFiltru = $inFiltru || in_array($f['stare'], $aratate, true);
        }
        $d += ['lista' => $lista, 'filtre' => $f, 'grupuri' => $grupuri, 'stare_in_plus' => $inFiltru ? null : ['valoare' => $f['stare'], 'text' => self::textStare($t, $f['stare'])],
            'total' => $total, 'cautare' => $cautare, 'cu_filtre' => array_filter($f, static fn ($v): bool => $v !== '') !== [],
            // Raportul lunar al plăților (pentru contabilitate) rămâne pe pagina Rezervări, al proprietarului.
            'raport' => self::raport($app, $u),
            'url' => $url + ['baza' => $app->url()->cale('/admin/rezervari'), 'csv' => null, 'inainte' => null, 'dupa' => null] + self::urlVederi($app, $u)];
        return AdminPanou::pagina($app, $u, 'rezervari', $d, $r);
    }

    /**
     * Vederile paginii Rezervări: rezervările, plățile online și avansurile. Ultimele două, ca până acum, doar
     * pentru proprietar și angajatul cu dreptul de rambursare; pentru ceilalți, nicio legătură.
     * @return array<string,string>
     */
    public static function urlVederi(Aplicatie $app, array $u): array
    {
        if (!SesiuneAdmin::poateRambursa($u)) {
            return [];
        }
        return ['vedere_rezervari' => $app->url()->cale('/admin/rezervari'), 'vedere_plati' => $app->url()->cale('/admin/rezervari', null, ['vedere' => 'plati']),
            'vedere_avansuri' => $app->url()->cale('/admin/rezervari', null, ['vedere' => 'avansuri'])]
            // în modurile E și P, plățile online se numesc „Jurnalul plăților cu cardul” (numai de citit).
            + ($app->modEc()->deciziiInEasyCheck() ? ['jurnal_card' => '1'] : []);
    }

    /** Raportul lunar al plăților: luna de acum și adresa fișierului, pentru proprietar; null pentru ceilalți. @return ?array{luna:string, url:string} */
    public static function raport(Aplicatie $app, array $u): ?array
    {
        return ($u['rol'] ?? null) === 'proprietar' ? ['luna' => substr($app->azi(), 0, 7), 'url' => $app->url()->cale('/admin/plati/export.csv')] : null;
    }

    /**
     * @return array{stare:string, de:string, pana:string, plata:string, cod:string} filtrele valide (cele greșite devin
     * goale); `stare` e o stare sau un grup (`grup-<cheie>`). Cu $goale, toate goale (pagina căutării după oaspete).
     */
    public static function filtre(Cerere $c, bool $goale = false): array
    {
        if ($goale) {
            return ['stare' => '', 'de' => '', 'pana' => '', 'plata' => '', 'cod' => ''];
        }
        $zi = '~^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$~';
        $stare = (string) ($c->param('stare') ?? '');
        $de = (string) ($c->param('de') ?? '');
        $pana = (string) ($c->param('pana') ?? '');
        $plata = (string) ($c->param('plata') ?? '');
        $cod = strtoupper(preg_replace('~[^A-Za-z0-9-]~', '', (string) ($c->param('cod') ?? '')) ?? '');
        $grup = str_starts_with($stare, 'grup-') && isset(self::GRUPURI_STARI[substr($stare, 5)]);
        return [
            'stare' => $grup || in_array($stare, self::stari(), true) ? $stare : '',
            'de' => preg_match($zi, $de) ? $de : '',
            'pana' => preg_match($zi, $pana) ? $pana : '',
            'plata' => in_array($plata, ['card', 'cash', 'transfer'], true) ? $plata : '',
            'cod' => mb_substr($cod, 0, 9),
        ];
    }

    /** @return array{0:string, 1:list<string>} */
    private static function where(array $f): array
    {
        $w = [];
        $p = [];
        if (str_starts_with($f['stare'], 'grup-')) {
            $stari = array_merge(...self::GRUPURI_STARI[substr($f['stare'], 5)]);
            $w[] = 'stare IN (' . implode(', ', array_fill(0, count($stari), '?')) . ')';
            array_push($p, ...$stari);
        } elseif ($f['stare'] !== '') {
            $w[] = 'stare = ?';
            $p[] = $f['stare'];
        }
        if ($f['de'] !== '') {
            $w[] = 'sosire >= ?';
            $p[] = $f['de'];
        }
        if ($f['pana'] !== '') {
            $w[] = 'sosire <= ?';
            $p[] = $f['pana'];
        }
        if ($f['plata'] !== '') {
            $w[] = 'fel = ?';
            $p[] = $f['plata'];
        }
        if ($f['cod'] !== '') {
            $w[] = 'ref LIKE ?';
            $p[] = '%' . $f['cod'] . '%';
        }
        return [$w !== [] ? ' WHERE ' . implode(' AND ', $w) : '', $p];
    }

    /** POST /admin/rezervari/{ref}/retrimite: confirmarea, din nou, pe adresa oaspetelui (cel mult 3 pe oră pe comandă). */
    public static function retrimite(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        $cm = $app->comenzi()->dupaRef((string) $p['ref']);
        if ($s === null || $cm === null) {
            return AdminPanou::inapoi($app);
        }
        $inapoi = static fn (array $q): Raspuns => AdminPanou::inapoi($app, '/admin/rezervari/' . $cm['ref'], $q);
        // cu mesajele cerute din EasyCheck (E și P cu `functii.mesaje`), serverul refuză, nu doar ascunde butonul.
        if ($app->modEc()->mesajeInEasyCheck()) {
            $app->auditDetalii(['eroare' => 'retrimis_ec']);
            return $inapoi(['eroare' => 'retrimis_ec']);
        }
        if (!in_array($cm['stare'], ['confirmata', 'anulare_ceruta'], true) || $cm['anonimizat_la'] !== null) {
            return $inapoi(['eroare' => 'retrimis_stare']);
        }
        if (!$app->limitator()->incearca('admin:retrimite:' . $cm['ref'], 3, 3600)) {
            return $inapoi(['eroare' => 'retrimis_limita']);
        }
        $app->cerereCash()->anunta((int) $cm['id'], $cm['fel'] !== 'cash' ? 'plata_confirmata' : 'cerere_acceptata');
        $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
        return $inapoi(['mesaj' => 'retrimis']);
    }

    /** Stările unei plăți, în ordinea din filtru. */
    public const STARI_PLATA = ['incasata', 'rambursata_partial', 'rambursata', 'in_procesare', 'autorizata', 'de_eliberat', 'anulata', 'esuata', 'sesiune', 'expirata'];

    /** GET /admin/plati: lista completă a plăților online, cu plățile de rezolvat în Stripe și raportul pe lună. */
    public static function plati(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'rambursari');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $zi = '~^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$~';
        // Fără alegere, lista arată doar plățile cu bani încasați; „toate” le arată și pe cele rămase la pagina de plată.
        $f = ['stare' => in_array($c->param('stare'), [...self::STARI_PLATA, 'toate'], true) ? (string) $c->param('stare') : '', 'de' => preg_match($zi, (string) $c->param('de')) ? (string) $c->param('de') : '',
            'pana' => preg_match($zi, (string) $c->param('pana')) ? (string) $c->param('pana') : ''];
        $w = [];
        $q = [];
        if ($f['stare'] === '') {
            $w[] = "p.stare NOT IN ('sesiune', 'expirata')";
        } elseif ($f['stare'] !== 'toate') {
            $w[] = 'p.stare = ?';
            $q[] = $f['stare'];
        }
        if ($f['de'] !== '') {
            $w[] = 'p.creat_la >= ?';
            $q[] = $f['de'] . ' 00:00:00';
        }
        if ($f['pana'] !== '') {
            $w[] = 'p.creat_la <= ?';
            $q[] = $f['pana'] . ' 23:59:59';
        }
        $where = $w !== [] ? ' WHERE ' . implode(' AND ', $w) : '';
        $pagina = max(1, (int) ($c->param('pagina') ?? 1));
        $lista = [];
        $sumaIncasata = 0;
        $sumaRambursata = 0;
        foreach ($app->baza()->toate('SELECT p.*, c.ref, c.stare AS stare_comanda, c.sosire, c.plecare FROM {plati} p JOIN {comenzi} c ON c.id = p.comanda_id' . $where
            . ' ORDER BY p.id DESC LIMIT ' . self::PE_PAGINA . ' OFFSET ' . (($pagina - 1) * self::PE_PAGINA), $q) as $x) {
            // Rambursarea fiecărei plăți (o comandă cu diferența unei schimbări are două plăți): suma ei, o singură dată.
            $rambursat = (int) $x['suma_rambursata'];
            $lista[] = ['ref' => (string) $x['ref'], 'la' => Format::moment((string) $x['creat_la'], $l), 'sejur' => Format::zi((string) $x['sosire'], $l) . ' - ' . Format::zi((string) $x['plecare'], $l),
                'incasat' => Format::bani((int) $x['suma_incasata'], $l), 'rambursat' => Format::bani($rambursat, $l),
                'metoda' => trim((string) ($x['metoda'] ?? '') . ' ' . (string) ($x['metoda_scurt'] ?? '')),
                'stare' => $t->t('admin.plati.stare_plata.' . $x['stare']), 'stare_clasa' => self::clasaPlata((string) $x['stare']),
                'comanda' => self::textStare($t, (string) $x['stare_comanda']), 'comanda_clasa' => self::clasaStare((string) $x['stare_comanda'])];
            $sumaIncasata += (int) $x['suma_incasata'];
            $sumaRambursata += $rambursat;
        }
        $total = (int) $app->baza()->valoare('SELECT COUNT(*) FROM {plati} p' . $where, $q);
        // Plățile fără ieșire automată: gazda le rezolvă în Stripe, apoi le închide de pe pagina rezervării.
        $deRezolvat = [];
        foreach ($app->baza()->toate("SELECT * FROM {comenzi} WHERE fel = 'card' AND stare IN ('platita', 'de_rambursat') ORDER BY id LIMIT 200") as $cm) {
            if (\Cazo\Rezervare\PlataOnline::poateFiInchisa($cm, $app)) {
                $deRezolvat[] = ['ref' => (string) $cm['ref'], 'stare' => self::textStare($t, (string) $cm['stare'])];
            }
        }
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'plati');
        $qq = array_filter($f, static fn ($v): bool => $v !== '');
        $optiuni = [];
        foreach (self::STARI_PLATA as $x) {
            $optiuni[] = ['valoare' => $x, 'text' => $t->t('admin.plati.stare_plata.' . $x)];
        }
        $proprietar = $u['rol'] === 'proprietar';
        $d += ['lista' => $lista, 'filtre' => $f, 'stari' => $optiuni, 'total' => $total, 'proprietar' => $proprietar, 'raport' => self::raport($app, $u),
            // în modurile E și P, „Jurnalul plăților cu cardul”, numai de citit, cu exportul cu comisioane (Î5).
            'jurnal_card' => $app->modEc()->deciziiInEasyCheck(),
            'cu_filtre' => $qq !== [], 'stripe_legat' => $app->stripe()->configurat(), 'de_rezolvat' => $deRezolvat,
            'suma_incasata' => Format::bani($sumaIncasata, $l), 'suma_rambursata' => Format::bani($sumaRambursata, $l),
            // plățile sunt o vedere a paginii Rezervări (`vedere=plati`).
            'url' => ['baza' => $app->url()->cale('/admin/rezervari'), 'export' => $app->url()->cale('/admin/plati/export.csv'),
                'metode' => $proprietar ? $app->url()->cale('/admin/metode-plata') : null,
                'inainte' => $pagina > 1 ? $app->url()->cale('/admin/rezervari', null, ['vedere' => 'plati'] + $qq + ['pagina' => (string) ($pagina - 1)]) : null,
                'dupa' => $pagina * self::PE_PAGINA < $total ? $app->url()->cale('/admin/rezervari', null, ['vedere' => 'plati'] + $qq + ['pagina' => (string) ($pagina + 1)]) : null]
                + self::urlVederi($app, $u)];
        return AdminPanou::pagina($app, $u, 'plati', $d, $r);
    }

    /** Clasa etichetei colorate a stării unei plăți. */
    public static function clasaPlata(string $stare): string
    {
        return match ($stare) {
            'incasata' => 'pa-stare-confirmata',
            'rambursata', 'rambursata_partial', 'anulata', 'expirata' => 'pa-stare-anulata',
            'esuata' => 'pa-stare-refuzata',
            default => 'pa-stare-asteapta',
        };
    }

    /**
     * Jurnalul alertelor trimise gazdei, blocul „Jurnalul” de pe Setări › Întreținere (până atunci pe pagina
     * Alerte, ale cărei alerte de acum stau pe Acasă). Îl văd proprietarul și angajatul cu dreptul de rambursare (acesta,
     * doar alertele despre bani); filtrul perioadei: 30 sau 90 de zile, ori tot ce păstrează jurnalul.
     */
    public static function alerte(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'alerte', self::MESAJE_ALERTE, self::ERORI_ALERTE);
        // Filtrul paginii: 30 sau 90 de zile, ori tot ce păstrează jurnalul.
        $zile = in_array($c->param('zile'), ['90', 'toate'], true) ? (string) $c->param('zile') : '30';
        $proprietar = $u['rol'] === 'proprietar';
        $jurnal = [];
        $sql = 'SELECT * FROM {alerte}' . ($zile === 'toate' ? '' : ' WHERE la > ?') . ' ORDER BY id DESC LIMIT 500';
        foreach (SesiuneAdmin::poateRambursa($u) ? $app->baza()->toate($sql, $zile === 'toate' ? [] : [$app->ceas()->moment(-(int) $zile * 86400)]) : [] as $x) {
            if (!$proprietar && !AdminPanou::sablonBani((string) $x['sablon'])) {
                continue;
            }
            $date = json_decode((string) ($x['date'] ?? ''), true) ?: [];
            $param = array_filter($date, 'is_scalar');
            if (is_int($date['suma_bani'] ?? null)) {
                $param['suma'] = Format::bani($date['suma_bani'], $l);
            }
            $cheie = 'email.' . $x['sablon'] . '.subiect';
            $titlu = $t->t($cheie, $param);
            // Un șablon fără subiect în catalog: un text pentru gazdă, nu numele intern al șablonului.
            $jurnal[] = ['la' => Format::moment((string) $x['la'], $l), 'titlu' => $titlu === $cheie ? $t->t('admin.alerte.alta') : $titlu, 'ref' => is_string($date['ref'] ?? null) && $date['ref'] !== '-' ? $date['ref'] : null,
                'trimis' => $t->t((int) $x['trimis'] === 1 ? 'admin.alerte.trimis' : 'admin.alerte.doar_jurnal')];
        }
        $spre = static fn (array $q): string => $app->url()->cale('/admin/intretinere', null, $q) . '#jurnal';
        $d += ['jurnal' => $jurnal, 'vede_jurnal' => SesiuneAdmin::poateRambursa($u), 'zile' => $zile, 'proprietar' => $proprietar, 'jurnal_fel' => 'alerte',
            // cât păstrează jurnalul se alege pe pagina „Legal și datele oaspeților”, lângă celelalte termene.
            'pastrare' => $app->gdpr()->alerteZile(),
            'url' => ['gdpr' => $app->url()->cale('/admin/gdpr') . '#oaspeti', 'zile' => ['30' => $spre([]), '90' => $spre(['zile' => '90']), 'toate' => $spre(['zile' => 'toate'])],
                'jurnal_alerte' => $spre(['jurnal' => 'alerte']), 'jurnal_audit' => $proprietar ? $spre(['jurnal' => 'audit']) : null]];
        return AdminPanou::pagina($app, $u, 'jurnal-alerte', $d, $r);
    }

    public const MESAJE_ALERTE = ['salvat' => 'admin.salvat', 'nota_ascunsa' => 'admin.alerte.nota_ascunsa'];
    public const ERORI_ALERTE = [];

    private static function lei(int $bani): string
    {
        return ($bani < 0 ? '-' : '') . intdiv(abs($bani), 100) . ',' . str_pad((string) (abs($bani) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function csv(array $randuri, string $nume): Raspuns
    {
        $f = fopen('php://memory', 'r+');
        foreach ($randuri as $x) {
            fputcsv($f, array_map([AdminRezervari::class, 'celula'], $x), ';', '"', '');
        }
        rewind($f);
        $r = new Raspuns(200, "\u{FEFF}" . stream_get_contents($f));
        $r->antet('Content-Type', 'text/csv; charset=utf-8');
        $r->antet('Content-Disposition', 'attachment; filename="' . $nume . '"');
        return $r;
    }
}
