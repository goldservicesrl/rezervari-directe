<?php

declare(strict_types=1);

// Site-ul fără cheie EasyCheck, pe bucăți: prețul nopții și weekendul, citirea calendarelor iCal
// (formele platformelor, fișierele stricate, limitele), calendarul dat de site, garda adreselor (SSRF) și API-ul răspuns
// de site (TransportLocal: idempotența, nopțile ocupate, avansul, termenele cererilor și ale transferului).

use Cazo\EasyCheck\Client;
use Cazo\Local\AdresaSigura;
use Cazo\Local\Calendare;
use Cazo\Local\Depozit;
use Cazo\Local\EroareIcal;
use Cazo\Local\Ical;
use Cazo\Local\Mod;
use Cazo\Teme\ModelVedere;

$ical = static fn (string ...$linii): string => implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', ...$linii, 'END:VCALENDAR']) . "\r\n";

/** Aplicația cu modul local și o cameră (2 unități, 3 oaspeți, 250 / 300 lei), cu avansul dat. */
$aplicatie = static function (Mediu $m, int $avans = 30): array {
    $app = $m->aplicatie(['cale_public' => $m->dosarTemporar()]);
    Mod::alege($app->setari(), Mod::LOCAL);
    $app->localDepozit()->salveazaProprietate(['nume' => 'Casa Probă', 'contact' => ['email' => 'a@b.test']]);
    $app->localDepozit()->salveazaReguli(['sejur_minim' => 1, 'avans_procent' => $avans, 'anulare_zile' => 7]);
    $cam = $app->localDepozit()->salveazaCamera(null, ['titlu' => 'Dublă', 'unitati' => 2, 'oaspeti_max' => 3, 'pret_noapte' => 25000, 'pret_weekend' => 30000]);
    return [$app, $cam];
};
$corp = static fn (string $cam, string $sosire, string $plecare, array $extra = []): string => Client::json(['camere' => [['camera' => $cam, 'adulti' => 2, 'copii' => 0]], 'sosire' => $sosire,
    'plecare' => $plecare, 'total' => 50000, 'moneda' => 'RON', 'ref_site' => 'PG-AAAAAA', 'oaspete' => ['nume' => 'Ion', 'email' => 'ion@exemplu.ro']] + $extra);

return [
    'weekendul: nopțile de vineri și sâmbătă; prețul de weekend lipsă = prețul pe noapte; fără preț = noapte fără preț' => function (Mediu $m): void {
        // 19 noiembrie 2026 e joi.
        egal([false, true, true, false], array_map(Depozit::weekend(...), ['2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22']));
        $c = ['pret_noapte' => 25000, 'pret_weekend' => 30000];
        egal([25000, 30000, 30000, 25000], array_map(static fn (string $z): ?int => Depozit::pretNoapte($c, $z), ['2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22']));
        egal(25000, Depozit::pretNoapte(['pret_noapte' => 25000, 'pret_weekend' => 0], '2026-11-20'));
        egal(null, Depozit::pretNoapte(['pret_noapte' => 0, 'pret_weekend' => 0], '2026-11-19'));
        // Trecerea dintre ani și ziua schimbării orei: zilele se numără în UTC, fără goluri.
        egal(['2026-12-31', '2027-01-01'], Depozit::zile('2026-12-31', '2027-01-02'));
        egal(3, count(Depozit::zile('2026-10-24', '2026-10-27')));
        egal(['2026-11-19', '2026-11-20'], Depozit::zile('2026-11-19', '2026-11-20', true));
        egal([], Depozit::zile('2026-13-01', '2026-13-05'));
    },

    'regula de rezervare: implicit o noapte, avans 0%, anulare 7 zile; valorile din afara limitelor se întorc la implicit' => function (Mediu $m): void {
        egal(['sejur_minim' => 1, 'avans_procent' => 0, 'anulare_zile' => 7, 'termen_cerere_ore' => 24], Depozit::reguliValide([]));
        egal(['sejur_minim' => 1, 'avans_procent' => 0, 'anulare_zile' => 7, 'termen_cerere_ore' => 24], Depozit::reguliValide(['sejur_minim' => 0, 'avans_procent' => 101, 'anulare_zile' => -1]));
        egal(['sejur_minim' => 30, 'avans_procent' => 100, 'anulare_zile' => 0, 'termen_cerere_ore' => 24], Depozit::reguliValide(['sejur_minim' => 30, 'avans_procent' => 100, 'anulare_zile' => 0]));
        $p = Depozit::completeaza(['tip' => 'necunoscut', 'ore' => ['checkin_de_la' => '25:00'], 'coordonate' => ['lat' => 95, 'lng' => 10]]);
        egal(['pensiune', '14:00', '11:00', null], [$p['tip'], $p['ore']['checkin_de_la'], $p['ore']['plecare_pana_la'], $p['coordonate']]);
    },

    'iCal: formele Booking și Airbnb, liniile continuate, BOM, CR, anulările, evenimentele transparente, orele UTC' => function (Mediu $m) use ($ical): void {
        $t = "\xEF\xBB\xBF" . $ical(
            'BEGIN:VEVENT', 'DTSTART;VALUE=DATE:20261201', 'DTEND;VALUE=DATE:20261204', 'UID:a@booking', 'SUMMARY:CLOSED - Not available', 'END:VEVENT',
            'BEGIN:VEVENT', 'DTSTAMP:20261101T000000Z', 'DTSTART;VALUE=DATE:20261210', 'DTEND;VALUE=DATE:20261212', 'SUMMARY:Reserved', 'UID:1418fb94e984-0f9a@air',
            ' bnb.com', 'END:VEVENT',
            'BEGIN:VEVENT', 'DTSTART:20261215', 'DTEND:20261216', 'STATUS:CANCELLED', 'END:VEVENT',
            'BEGIN:VEVENT', 'DTSTART:20261220', 'DTEND:20261221', 'TRANSP:TRANSPARENT', 'END:VEVENT',
            // 22:30 UTC în 31 decembrie e deja 1 ianuarie la București.
            'BEGIN:VEVENT', 'DTSTART:20261231T223000Z', 'DTEND:20270102T090000Z', 'END:VEVENT',
            // Fără DTEND: o zi; cu DURATION: zilele ei.
            'BEGIN:VEVENT', 'DTSTART;VALUE=DATE:20270110', 'END:VEVENT',
            'BEGIN:VEVENT', 'DTSTART;TZID=Europe/Bucharest:20270115T140000', 'DURATION:P2D', 'END:VEVENT',
        );
        $p = Ical::citeste(str_replace("\r\n", "\n", $t), '2026-11-13', '2027-11-14');
        egal([['2026-12-01', '2026-12-04'], ['2026-12-10', '2026-12-12'], ['2027-01-01', '2027-01-02'], ['2027-01-10', '2027-01-11'], ['2027-01-15', '2027-01-17']],
            array_map(static fn (array $x): array => [$x['de'], $x['pana']], $p));
        egal('1418fb94e984-0f9a@airbnb.com', $p[1]['uid']);
        // Fereastra: evenimentele din afara ei lipsesc.
        egal(1, count(Ical::citeste($t, '2026-12-01', '2026-12-05')));
        // Nopțile: două evenimente suprapuse dau două unități în noaptea comună.
        egal(['2026-12-01' => 1, '2026-12-02' => 2, '2026-12-03' => 1], Ical::nopti([['de' => '2026-12-01', 'pana' => '2026-12-03'], ['de' => '2026-12-02', 'pana' => '2026-12-04']], '2026-11-01', '2027-01-01'));
    },

    'iCal stricat: fără VCALENDAR, neînchis, prea mare, prea multe evenimente; datele greșite și anii absurzi se sar' => function (Mediu $m) use ($ical): void {
        $cod = static function (string $t): string {
            try {
                Ical::citeste($t, '2026-01-01', '2027-01-01');
            } catch (\UnexpectedValueException $e) {
                return $e->getMessage();
            }
            return 'trecut';
        };
        egal('forma', $cod('<html><body>Eroare 500</body></html>'));
        egal('forma', $cod("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART:20261201\r\n"));
        egal('forma', $cod(''));
        egal('marime', $cod(str_repeat('A', Ical::MARIME_MAXIMA + 1)));
        egal('evenimente', $cod($ical(...array_merge(...array_fill(0, Ical::EVENIMENTE_MAXIME + 1, ['BEGIN:VEVENT', 'DTSTART:20261201', 'END:VEVENT'])))));
        $p = Ical::citeste($ical('BEGIN:VEVENT', 'DTSTART:20261301', 'END:VEVENT', 'BEGIN:VEVENT', 'DTSTART:18001201', 'END:VEVENT', 'BEGIN:VEVENT', 'DTSTART:20261131', 'END:VEVENT',
            'BEGIN:VEVENT', 'END:VEVENT', 'BEGIN:VEVENT', 'DTSTART:20261201', 'DTEND:20261130', 'END:VEVENT'), '2026-01-01', '2027-01-01');
        // Doar ultimul are o zi bună; sfârșitul înaintea începutului ocupă o noapte.
        egal([['de' => '2026-12-01', 'pana' => '2026-12-02', 'uid' => '']], $p);
        // Un eveniment de peste un an se taie la 366 de nopți; o linie foarte lungă se scurtează, fără eroare.
        $lung = Ical::citeste($ical('BEGIN:VEVENT', 'DTSTART:20261201', 'DTEND:20301201', 'SUMMARY:' . str_repeat('x', 20000), 'END:VEVENT'), '2026-01-01', '2031-01-01');
        egal('2027-12-02', $lung[0]['pana']);
    },

    'calendarul dat de site: nopțile în șiruri, datele ca zile, liniile sub 75 de octeți, CRLF' => function (Mediu $m): void {
        egal([['2026-12-01', '2026-12-04'], ['2026-12-10', '2026-12-11']], Ical::siruri(['2026-12-03', '2026-12-01', '2026-12-02', '2026-12-10', '2026-12-02']));
        $t = Ical::scrie(['2026-12-01', '2026-12-02', '2026-12-10'], 'cam_0123456789abcdef', 'casa.ro', 'Casa Lungă cu un nume foarte, foarte lung: Camera dublă cu vedere spre lac', 'Ocupat', 1794650400);
        contine($t, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n");
        contine($t, "DTSTART;VALUE=DATE:20261201\r\nDTEND;VALUE=DATE:20261203\r\n");
        contine($t, "DTSTART;VALUE=DATE:20261210\r\nDTEND;VALUE=DATE:20261211\r\n");
        egal(2, substr_count($t, 'BEGIN:VEVENT'));
        contine($t, 'X-WR-CALNAME:Casa Lungă cu un nume foarte\\, foarte lung');
        foreach (explode("\r\n", $t) as $l) {
            adevarat(strlen($l) <= 75, 'linia are peste 75 de octeți: ' . $l);
        }
        // Ce scrie site-ul, site-ul și citește.
        egal([['de' => '2026-12-01', 'pana' => '2026-12-03'], ['de' => '2026-12-10', 'pana' => '2026-12-11']],
            array_map(static fn (array $x): array => ['de' => $x['de'], 'pana' => $x['pana']], Ical::citeste($t, '2026-11-01', '2027-01-01')));
    },

    'garda adreselor (SSRF): doar https public pe 443, fără parolă în adresă; IP-urile private, rezervate și cele mapate se refuză' => function (Mediu $m): void {
        $dns = static fn (array $ip): \Closure => static fn (string $g): array => $ip;
        $cod = static function (string $url, ?\Closure $r = null): string {
            try {
                AdresaSigura::verifica($url, $r);
                return 'ok';
            } catch (EroareIcal $e) {
                return $e->cod;
            }
        };
        egal('ok', $cod('https://admin.booking.com/ical.ics?t=1', $dns(['93.184.216.34'])));
        egal('ok', $cod('webcal://p01-calendars.icloud.com/x.ics', $dns(['17.250.1.1'])));
        egal('ok', $cod('https://[2606:4700:4700::1111]/a.ics'));
        foreach (['http://a.ro/x' => 'schema', 'ftp://a.ro/x' => 'schema', 'file:///etc/passwd' => 'schema', 'https://a.ro:8443/x' => 'gazda', 'https://u:p@a.ro/x' => 'adresa',
            'https://localhost/x' => 'gazda', 'https://intranet.local/x' => 'gazda', 'https://127.0.0.1/x' => 'gazda', 'https://10.1.2.3/x' => 'gazda', 'https://172.16.0.1/x' => 'gazda',
            'https://192.168.1.1/x' => 'gazda', 'https://169.254.169.254/latest/meta-data' => 'gazda', 'https://100.64.0.1/x' => 'gazda', 'https://0.0.0.0/x' => 'gazda',
            'https://[::1]/x' => 'gazda', 'https://[fd00::1]/x' => 'gazda', 'https://[fe80::1]/x' => 'gazda', 'https://[::ffff:127.0.0.1]/x' => 'gazda', 'https://224.0.0.1/x' => 'gazda',
            'https://a .ro/x' => 'adresa', 'nu e o adresă' => 'adresa', '' => 'adresa', "https://a.ro/\r\nX: y" => 'adresa'] as $url => $asteptat) {
            egal($asteptat, $cod($url, $dns(['93.184.216.34'])), $url);
        }
        // Numele care se rezolvă spre o rețea privată (oricare dintre adrese) și numele fără adrese.
        egal('gazda', $cod('https://ical.test/x', $dns(['93.184.216.34', '10.0.0.7'])));
        egal('gazda', $cod('https://ical.test/x', $dns(['::ffff:192.168.0.2'])));
        egal('dns', $cod('https://ical.test/x', $dns([])));
        // Auditul de securitate (24.11.2026): formele IPv6 care duc spre rețeaua internă, citite pe octeți.
        foreach (['::ffff:7f00:1', '::ffff:a00:7', '::7f00:1', '64:ff9b::7f00:1', '64:ff9b::a9fe:a9fe', '2002:7f00:1::1', '2002:c0a8:1::1',
            '2001:0:4136:e378:8000:63bf:3fff:fdd2', 'fec0::1', '100::1', '2001:db8::1', '2001:10::1', 'ff02::1'] as $ip) {
            fals(AdresaSigura::public($ip), $ip);
        }
        foreach (['2606:4700:4700::1111', '2a00:1450:4001:80b::200e', '::ffff:93.184.216.34', '::ffff:5db8:d822', '93.184.216.34'] as $ip) {
            adevarat(AdresaSigura::public($ip), $ip);
        }
        egal('93.184.216.34', AdresaSigura::verifica('https://ical.test/x', $dns(['93.184.216.34']))['ip']);
        // Redirecționările relative se socotesc față de adresa cerută.
        egal('https://a.ro/b/c.ics', Calendare::absoluta('https://a.ro/b/x.ics', 'c.ics'));
        egal('https://a.ro/c.ics', Calendare::absoluta('https://a.ro/b/x.ics', '/c.ics'));
        egal('https://alt.ro/c.ics', Calendare::absoluta('https://a.ro/b/x.ics', '//alt.ro/c.ics'));
        egal('http://alt.ro/c.ics', Calendare::absoluta('https://a.ro/b/x.ics', 'http://alt.ro/c.ics'));
        egal('admin.booking.com/hotel/hotel…', AdresaSigura::scurta('https://admin.booking.com/hotel/hoteladmin/ical.html?t=secret'));
    },

    'API-ul răspuns de site: idempotența, transferul refuzat pe nopți ocupate, cardul cu suprapunerea notată, avansul cerut' => function (Mediu $m) use ($aplicatie, $corp): void {
        [$app, $cam] = $aplicatie($m);
        $c = $app->ecClient();
        $pa = ['plata_asteptata' => ['metoda' => 'transfer', 'suma' => 15000, 'termen' => gmdate('Y-m-d\TH:i:s\Z', Mediu::ACUM + 2 * 86400), 'referinta' => 'PG-AAAAAA']];
        $r1 = $c->scrie('POST', '/rezervari', $corp($cam, '2026-12-01', '2026-12-03', $pa), 'cheie-proba-0001');
        egal('in_asteptarea_platii', $r1->json()['stare']);
        // Aceeași cheie, același corp: același răspuns; alt corp: 409.
        egal($r1->json()['id'], $c->scrie('POST', '/rezervari', $corp($cam, '2026-12-01', '2026-12-03', $pa), 'cheie-proba-0001')->json()['id']);
        $e = arunca(fn () => $c->scrie('POST', '/rezervari', $corp($cam, '2026-12-01', '2026-12-04', $pa), 'cheie-proba-0001'), \Cazo\EasyCheck\EroareApi::class);
        egal('idempotency_conflict', $e->cod);
        egal(1, (int) $app->baza()->valoare('SELECT COUNT(*) FROM {local_rezervari}'));
        // A doua unitate: încă un transfer intră; al treilea, pe aceleași nopți, primește 409 indisponibil.
        $c->scrie('POST', '/rezervari', $corp($cam, '2026-12-02', '2026-12-03', $pa), 'cheie-proba-0002');
        $e = arunca(fn () => $c->scrie('POST', '/rezervari', $corp($cam, '2026-12-02', '2026-12-04', $pa), 'cheie-proba-0003'), \Cazo\EasyCheck\EroareApi::class);
        egal(['indisponibil', ['2026-12-02']], [$e->cod, $e->detalii()['zile']]);
        // Cardul intră oricum, cu suprapunerea notată (ca în EasyCheck); sub avans, 422 avans_insuficient.
        $card = $c->scrie('POST', '/rezervari', $corp($cam, '2026-12-02', '2026-12-03', ['plata' => ['suma' => 15000, 'ref' => 'pi_1', 'metoda' => 'card']]), 'cheie-proba-0004')->json();
        egal([true, 'confirmata'], [$card['suprapunere'], $card['stare']]);
        $e = arunca(fn () => $c->scrie('POST', '/rezervari', $corp($cam, '2026-12-10', '2026-12-11', ['plata' => ['suma' => 100, 'ref' => 'pi_2', 'metoda' => 'card']]), 'cheie-proba-0005'), \Cazo\EasyCheck\EroareApi::class);
        egal('avans_insuficient', $e->cod);
        // O cameră necunoscută, prea mulți oaspeți, datele inversate: 422.
        $e = arunca(fn () => $c->scrie('POST', '/cereri', $corp('cam_ffffffffffffffff', '2026-12-10', '2026-12-11'), 'cheie-proba-0006'), \Cazo\EasyCheck\EroareApi::class);
        egal('validare', $e->cod);
        $e = arunca(fn () => $c->scrie('POST', '/cereri', str_replace('"adulti":2', '"adulti":5', $corp($cam, '2026-12-10', '2026-12-11')), 'cheie-proba-0007'), \Cazo\EasyCheck\EroareApi::class);
        egal(['restrictie', 'oaspeti_max'], [$e->cod, $e->detalii()['motiv']]);
        $e = arunca(fn () => $c->scrie('POST', '/cereri', $corp($cam, '2026-12-11', '2026-12-10'), 'cheie-proba-0008'), \Cazo\EasyCheck\EroareApi::class);
        egal('validare', $e->cod);
        // Fără Idempotency-Key: 400.
        $e = arunca(fn () => $c->scrie('POST', '/cereri', $corp($cam, '2026-12-10', '2026-12-11')), \Cazo\EasyCheck\EroareApi::class);
        egal('idempotency_lipsa', $e->cod);
    },

    'API-ul răspuns de site: cererea expiră după 24 de ore, transferul după termen; anularea a doua oară dă 409; lista rezervărilor pe pagini' => function (Mediu $m) use ($aplicatie, $corp): void {
        [$app, $cam] = $aplicatie($m);
        $c = $app->ecClient();
        $cer = $c->scrie('POST', '/cereri', $corp($cam, '2026-12-10', '2026-12-12'), 'c-proba-0001')->json();
        egal('in_asteptare', $cer['stare']);
        egal(gmdate('Y-m-d\TH:i:s\Z', Mediu::ACUM + 86400), $cer['expira_la']);
        $pa = ['plata_asteptata' => ['metoda' => 'transfer', 'suma' => 15000, 'termen' => gmdate('Y-m-d\TH:i:s\Z', Mediu::ACUM + 3600), 'referinta' => 'PG-AAAAAA']];
        $t = $c->scrie('POST', '/rezervari', $corp($cam, '2026-12-01', '2026-12-03', $pa), 't-proba-0001')->json();
        $m->ceas()->avanseaza(86400 + 1);
        egal('expirata', $c->citeste('/cereri/' . $cer['id'])->json()['stare']);
        $e = arunca(fn () => $c->scrie('POST', '/cereri/' . $cer['id'] . '/proba-accepta', null), \Cazo\EasyCheck\EroareApi::class);
        egal('stare_invalida', $e->cod);
        $j = $c->citeste('/rezervari/' . $t['id'])->json();
        egal(['anulata', 'expirata'], [$j['stare'], $j['plata_asteptata']['stare']]);
        // Nopțile transferului expirat s-au eliberat.
        egal(2, $app->localDepozit()->disponibilitate('2026-12-01', '2026-12-02')[$cam]['2026-12-01']['libere']);
        $card = $c->scrie('POST', '/rezervari', $corp($cam, '2026-12-05', '2026-12-06', ['plata' => ['suma' => 50000, 'ref' => 'pi', 'metoda' => 'card']]), 'k-proba-0001')->json();
        egal('anulata', $c->scrie('POST', '/rezervari/' . $card['id'] . '/anuleaza', null, 'an-proba-0001')->json()['stare']);
        egal('anulata', $c->scrie('POST', '/rezervari/' . $card['id'] . '/anuleaza', null, 'an-proba-0001')->json()['stare'], 'aceeași cheie: același răspuns');
        $e = arunca(fn () => $c->scrie('POST', '/rezervari/' . $card['id'] . '/anuleaza', null, 'an-proba-0002'), \Cazo\EasyCheck\EroareApi::class);
        egal('stare_invalida', $e->cod);
        $lista = $c->citeste('/rezervari', ['actualizate_de_la' => '2026-01-01T00:00:00Z'])->json();
        egal(2, count($lista['rezervari']));
        egal(null, $lista['dupa']);
        adevarat(str_ends_with($lista['rezervari'][0]['actualizat_la'], 'Z'));
        // Resursele citite: ETag și 304.
        $r = $c->citeste('/camere');
        egal(304, $c->citeste('/camere', [], $r->etag())->status);
        $e = arunca(fn () => $c->citeste('/preturi', ['de' => '2026-12-01', 'pana' => '2028-12-01']), \Cazo\EasyCheck\EroareApi::class);
        egal('validare', $e->cod);
    },

    'trecerea: prețurile de bază din grilă, cel mai des întâlnit preț pe zilele lucrătoare și pe weekend, rotunjite la leu' => function (Mediu $m): void {
        $app = $m->aplicatie();
        $b = $app->baza();
        $b->insereaza('planuri', ['id' => 'plan_std', 'titlu' => 'Standard', 'fel' => 'propriu', 'mod' => 'camera', 'avans_procent' => 20, 'anulare_zile' => 3, 'ordine' => 0]);
        foreach (Depozit::zile('2026-11-14', '2027-02-12') as $i => $zi) {
            $p = Depozit::weekend($zi) ? 31049 : ($i % 10 === 0 ? 99900 : 25000);
            $b->insereaza('nopti_pret', ['camera' => 'cam_0123456789abcdef', 'plan' => 'plan_std', 'zi' => $zi, 'pret' => $p, 'sejur_minim' => 2, 'sejur_minim_prin' => 2, 'sejur_maxim' => 0,
                'sosire_inchisa' => 0, 'plecare_inchisa' => 0, 'inchis' => 0]);
        }
        $tr = $app->localTrecere();
        egal([25000, 31000], $tr->preturiDeBaza('cam_0123456789abcdef', 'plan_std'));
        egal([0, 0], $tr->preturiDeBaza('cam_0123456789abcdef', null));
        egal(['id' => 'plan_std', 'avans_procent' => 20, 'anulare_zile' => 3, 'sejur_minim' => 2], $tr->planDeBaza());
    },

    'toate camerele primite de panou ajung în tema site-ului: cât permite panoul, atâtea vede oaspetele' => function (Mediu $m) use ($aplicatie): void {
        [$app] = $aplicatie($m);
        for ($i = 2; $i <= Depozit::CAMERE_MAX; $i++) {
            $app->localDepozit()->salveazaCamera(null, ['titlu' => "Camera $i", 'unitati' => 1, 'oaspeti_max' => 2, 'pret_noapte' => 20000, 'pret_weekend' => 20000]);
        }
        $app->localDupaScriere(['camere', 'disponibilitate', 'preturi']);
        egal(Depozit::CAMERE_MAX, count($app->localDepozit()->camere()));
        egal(Depozit::CAMERE_MAX, count($app->modelVedere('ro')->camere()), 'tema primește toate camerele, nu doar o parte');
        egal(Depozit::CAMERE_MAX, ModelVedere::CAMERE_MAX);
    },
];
