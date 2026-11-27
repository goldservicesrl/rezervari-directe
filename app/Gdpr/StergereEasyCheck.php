<?php

declare(strict_types=1);

namespace Cazo\Gdpr;

use Cazo\EasyCheck\Client;
use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\I18n\Format;
use Cazo\Joburi\Coada;
use Cazo\Nucleu\Aplicatie;

defined('CAZO') or exit;

/**
 * Ștergerea datelor unui oaspete, transmisă la EasyCheck.
 *
 * Ștergerea din panou (Gdpr::sterge) anonimizează întâi comenzile pe site, ca până acum, și notează aici, pentru fiecare
 * rezervare EasyCheck a comenzilor anonimizate (cea de acum și cele legate prin mutare: `schimbari_date.ec_veche`,
 * `ec_noua`, `ec_refacuta`), câte un rând cu o cheie de idempotență proprie. Trimiterea pleacă după răspunsul panoului
 * și din cron (`gdpr:easycheck`): `POST /rezervari/{id}/anonimizeaza`, fără corp.
 *
 *  200            `facuta`: răspunsul (ce s-a șters și ce rămâne, cu temeiul și ziua) rămâne pentru panou și pentru
 *                 textul către oaspete
 *  403 rezervare_straina, 404, celelalte refuzuri
 *                 `de_facut`: panoul îi spune gazdei să șteargă datele direct în EasyCheck
 *  429, 5xx, rețeaua, 409 în lucru
 *                 reluarea cu aceeași cheie, după pauzele cozii (1, 5, 30 de minute, 2 h, 6 h); după ultima, `de_facut`;
 *                 pauza cerută prin Retry-After amână trimiterea fără s-o socotească
 */
final class StergereEasyCheck
{
    public const ZAVOR = 'gdpr:easycheck';

    /** Cât ține o trimitere preluată, ca o rulare paralelă s-o lase în pace. */
    private const LACAT = 120;

    public function __construct(private Aplicatie $app)
    {
    }

    /**
     * Notează o ștergere: comenzile anonimizate (cu rezervările lor EasyCheck) și cele rămase până se încheie.
     * @param list<array> $anonimizate rândurile comenzilor anonimizate @param list<string> $ramase codurile comenzilor rămase
     * @return int id-ul ștergerii
     */
    public function noteaza(array $anonimizate, array $ramase, ?int $utilizator): int
    {
        $b = $this->app->baza();
        $acum = $this->app->ceas()->moment();
        return (int) $b->tranzactie(function () use ($b, $anonimizate, $ramase, $utilizator, $acum): int {
            $id = $b->insereaza('gdpr_stergeri', ['utilizator_id' => $utilizator, 'limba' => (string) ($anonimizate[0]['limba'] ?? 'ro'),
                'anonimizate' => json_encode(array_map(static fn (array $c): string => (string) $c['ref'], $anonimizate)), 'ramase' => json_encode(array_values($ramase)), 'creat_la' => $acum]);
            $vazute = [];
            foreach ($anonimizate as $c) {
                foreach ($this->rezervari($c) as $rez) {
                    if (isset($vazute[$rez])) {
                        continue;
                    }
                    $vazute[$rez] = true;
                    $b->insereaza('gdpr_easycheck', ['stergere_id' => $id, 'ref' => (string) $c['ref'], 'ec_rezervare' => $rez, 'idem' => 'gdpr-' . Client::uuid(),
                        'stare' => 'in_lucru', 'incercari' => 0, 'urmatoarea' => $acum, 'creat_la' => $acum, 'actualizat_la' => $acum]);
                }
            }
            return $id;
        });
    }

    /** Rezervările EasyCheck ale comenzii: cea de acum și cele ale mutărilor. @return list<string> */
    public function rezervari(array $c): array
    {
        // rezervările făcute pe site fără cheie EasyCheck n-au ajuns în EasyCheck, iar cât site-ul lucrează de sine
        // stătător nu are cui trimite ștergerea (datele lui stau doar în baza site-ului).
        if (\Cazo\Local\Mod::comandaLocala($c) || $this->app->modLocal()) {
            return [];
        }
        $ids = [(string) ($c['ec_rezervare'] ?? '')];
        foreach ($this->app->baza()->toate('SELECT ec_veche, ec_noua, ec_refacuta FROM {schimbari_date} WHERE comanda_id = ? ORDER BY id', [(int) $c['id']]) as $s) {
            array_push($ids, (string) $s['ec_veche'], (string) ($s['ec_noua'] ?? ''), (string) ($s['ec_refacuta'] ?? ''));
        }
        return array_values(array_unique(array_filter($ids, static fn (string $x): bool => (bool) preg_match('~^rez_[0-9A-Za-z]{1,60}$~', $x))));
    }

    public function areLucru(): bool
    {
        // Contractul trecerii, punctul 3: cât site-ul lucrează de sine stătător, EasyCheck nu mai are datele oaspeților de pe
        // site (le-a șters la anunțul trecerii), deci ștergerile rămase în coadă nu mai pleacă.
        if ($this->app->modLocal()) {
            return false;
        }
        return $this->app->baza()->valoare("SELECT 1 FROM {gdpr_easycheck} WHERE stare = 'in_lucru' AND urmatoarea <= ? LIMIT 1", [$this->app->ceas()->moment()]) !== null;
    }

    /** Trimiterile scadente, sub zăvor (panoul, după răspuns, și cronul). @return array{facute:int, de_facut:int, amanate:int} */
    public function ruleaza(int $cate = 20): array
    {
        $r = ['facute' => 0, 'de_facut' => 0, 'amanate' => 0];
        if ($this->app->modLocal()) {
            return $r;
        }
        $this->app->zavor()->cuZavor(self::ZAVOR, 300, function () use ($cate, &$r): void {
            $randuri = $this->app->baza()->toate("SELECT * FROM {gdpr_easycheck} WHERE stare = 'in_lucru' AND urmatoarea <= ? ORDER BY id LIMIT " . max(1, $cate),
                [$this->app->ceas()->moment()]);
            foreach ($randuri as $x) {
                $r[$this->trimite($x)]++;
            }
        });
        return $r;
    }

    /** @return string facute, de_facut, amanate */
    private function trimite(array $x): string
    {
        $b = $this->app->baza();
        $id = (int) $x['id'];
        $n = (int) $x['incercari'] + 1;
        $b->executa('UPDATE {gdpr_easycheck} SET incercari = ?, urmatoarea = ?, actualizat_la = ? WHERE id = ?', [$n, $this->app->ceas()->moment(self::LACAT), $this->app->ceas()->moment(), $id]);
        try {
            $j = $this->app->ecClient()->scrie('POST', '/rezervari/' . $x['ec_rezervare'] . '/anonimizeaza', null, (string) $x['idem'])->json() ?? [];
            $raspuns = ['anonimizata_la' => $j['anonimizata_la'] ?? null, 'rezervari' => array_values(array_filter((array) ($j['rezervari'] ?? []), 'is_string')),
                'sterse' => array_values(array_filter((array) ($j['sterse'] ?? []), 'is_string')), 'pastrate' => self::pastrate($j['pastrate'] ?? [])];
            $this->incheie($id, 'facuta', null, $raspuns);
            $this->app->audit()->scrie('gdpr.easycheck', (string) $x['ref'], ['rezervare' => $x['ec_rezervare'], 'stare' => 'facuta']);
            return 'facute';
        } catch (EroareApi $e) {
            $cod = $e->cod ?? 'http_' . $e->raspuns->status;
            $inLucru = $cod === 'idempotency_conflict' && (($e->detalii()['in_lucru'] ?? false) === true);
            if ($inLucru || $e->comportament === 'reincearca' || $e->raspuns->status >= 500 || $e->raspuns->status === 429) {
                return $this->amana($x, $n, $cod);
            }
            return $this->opreste($x, $e->raspuns->status === 404 ? 'negasit' : $cod, $e->requestId);
        } catch (EroareTransport $e) {
            if ($e->felul === 'pauza') {
                // Pauza cerută de EasyCheck (Retry-After): cererea n-a plecat, deci încercarea nu se socotește.
                $this->app->baza()->executa('UPDATE {gdpr_easycheck} SET incercari = ?, urmatoarea = ?, cod = ? WHERE id = ?',
                    [$n - 1, $this->app->ceas()->moment(max(60, (int) $e->reiaDupa)), $e->felul, $id]);
                return 'amanate';
            }
            return $this->amana($x, $n, $e->felul);
        }
    }

    private function amana(array $x, int $n, string $cod): string
    {
        if ($n >= Coada::MAX_INCERCARI) {
            return $this->opreste($x, $cod, null);
        }
        $pauza = Coada::REINCERCARI[min($n - 1, count(Coada::REINCERCARI) - 1)];
        $this->app->baza()->executa('UPDATE {gdpr_easycheck} SET urmatoarea = ?, cod = ?, actualizat_la = ? WHERE id = ?',
            [$this->app->ceas()->moment($pauza), mb_substr($cod, 0, 64), $this->app->ceas()->moment(), (int) $x['id']]);
        return 'amanate';
    }

    private function opreste(array $x, string $cod, ?string $requestId): string
    {
        $this->incheie((int) $x['id'], 'de_facut', $cod, null);
        $this->app->jurnal()->avertisment('Ștergerea datelor oaspetelui în EasyCheck a rămas gazdei', ['ref' => $x['ref'], 'rezervare' => $x['ec_rezervare'], 'cod' => $cod, 'request_id' => $requestId]);
        $this->app->audit()->scrie('gdpr.easycheck', (string) $x['ref'], ['rezervare' => $x['ec_rezervare'], 'stare' => 'de_facut', 'cod' => $cod]);
        return 'de_facut';
    }

    private function incheie(int $id, string $stare, ?string $cod, ?array $raspuns): void
    {
        $this->app->baza()->executa('UPDATE {gdpr_easycheck} SET stare = ?, cod = ?, raspuns = ?, urmatoarea = NULL, actualizat_la = ? WHERE id = ?',
            [$stare, $cod !== null ? mb_substr($cod, 0, 64) : null, $raspuns !== null ? json_encode($raspuns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $this->app->ceas()->moment(), $id]);
    }

    /** Lista `pastrate` din răspuns, doar cu câmpurile cunoscute. @return list<array{date:string, numar:int, temei:?string, pana_la:?string, text:string}> */
    public static function pastrate(mixed $lista): array
    {
        $r = [];
        foreach (is_array($lista) ? $lista : [] as $p) {
            if (!is_array($p) || !is_string($p['date'] ?? null)) {
                continue;
            }
            $r[] = ['date' => $p['date'], 'numar' => (int) ($p['numar'] ?? 0), 'temei' => is_string($p['temei'] ?? null) && trim($p['temei']) !== '' ? trim($p['temei']) : null,
                'pana_la' => is_string($p['pana_la'] ?? null) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $p['pana_la']) ? $p['pana_la'] : null, 'text' => (string) ($p['text'] ?? '')];
        }
        return $r;
    }

    // ---- Panoul ----

    /**
     * Ultimele ștergeri, pentru pagina GDPR: rezervările trimise, cu starea fiecăreia, și textul pentru oaspete când
     * toate au primit răspuns. @return list<array>
     */
    public function recente(string $limbaPanou, int $cate = 10): array
    {
        $t = $this->app->traducator($limbaPanou, 'admin');
        $r = [];
        foreach ($this->app->baza()->toate('SELECT * FROM {gdpr_stergeri} ORDER BY id DESC LIMIT ' . max(1, $cate)) as $s) {
            $randuri = [];
            foreach ($this->app->baza()->toate('SELECT * FROM {gdpr_easycheck} WHERE stergere_id = ? ORDER BY id', [(int) $s['id']]) as $x) {
                $randuri[] = $this->rand($x, $t, $limbaPanou);
            }
            $r[] = ['id' => (int) $s['id'], 'la' => Format::moment((string) $s['creat_la'], $limbaPanou), 'refs' => implode(', ', (array) json_decode((string) $s['anonimizate'], true)),
                'randuri' => $randuri, 'text' => $this->textOaspete((int) $s['id'])];
        }
        return $r;
    }

    private function rand(array $x, \Cazo\I18n\Traducator $t, string $l): array
    {
        $p = ['ref' => (string) $x['ref'], 'rez' => (string) $x['ec_rezervare'], 'cod' => (string) ($x['cod'] ?? '')];
        $pastrate = [];
        if ($x['stare'] === 'facuta') {
            $j = (array) json_decode((string) $x['raspuns'], true);
            $text = $t->t('admin.gdpr.ec.facuta', $p);
            foreach (self::pastrate($j['pastrate'] ?? []) as $d) {
                $pastrate[] = trim($d['text'] . ' ' . $t->t($d['temei'] !== null ? 'admin.gdpr.ec.pastrata_temei' : 'admin.gdpr.ec.pastrata', ['temei' => (string) $d['temei'],
                    'pana_la' => $d['pana_la'] !== null ? Format::zi($d['pana_la'], $l) : '']));
            }
        } elseif ($x['stare'] === 'de_facut') {
            $text = $t->t(match ((string) $x['cod']) {
                'rezervare_straina' => 'admin.gdpr.ec.straina',
                'negasit' => 'admin.gdpr.ec.negasita',
                default => 'admin.gdpr.ec.oprita',
            }, $p);
        } else {
            $text = $t->t('admin.gdpr.ec.in_lucru', $p);
        }
        return ['stare' => (string) $x['stare'], 'text' => $text, 'pastrate' => $pastrate];
    }

    /**
     * Confirmarea pentru oaspete, în limba comenzilor lui, la persoana I: ce am șters și ce am păstrat și de ce,
     * după lista `pastrate` a fiecărei rezervări EasyCheck. Null cât o trimitere e încă în lucru.
     */
    public function textOaspete(int $stergere): ?string
    {
        $b = $this->app->baza();
        $s = $b->rand('SELECT * FROM {gdpr_stergeri} WHERE id = ?', [$stergere]);
        if ($s === null) {
            return null;
        }
        $randuri = $b->toate('SELECT stare, raspuns FROM {gdpr_easycheck} WHERE stergere_id = ? ORDER BY id', [$stergere]);
        if (in_array('in_lucru', array_column($randuri, 'stare'), true)) {
            return null;
        }
        $l = (string) $s['limba'];
        $t = $this->app->traducator($l);
        $l = $t->limba();
        // Aceleași date din mai multe rezervări: un singur rând, cu ziua cea mai târzie.
        $pastrate = [];
        foreach ($randuri as $x) {
            if ($x['stare'] !== 'facuta') {
                continue;
            }
            foreach (self::pastrate(((array) json_decode((string) $x['raspuns'], true))['pastrate'] ?? []) as $p) {
                $cheie = $p['date'] === 'sejur' && $p['temei'] === null ? 'sejur_calendar' : (in_array($p['date'], ['fisa_cazare', 'facturi', 'sejur'], true) ? $p['date'] : 'alte');
                if (!isset($pastrate[$cheie]) || (string) $p['pana_la'] > (string) $pastrate[$cheie]['pana_la']) {
                    $pastrate[$cheie] = $p;
                }
            }
        }
        $proprietate = (array) ($this->app->ecOglinda()->corp('proprietate') ?? []);
        $linii = [$t->t('gdpr.confirmare.salut'), '',
            $t->t('gdpr.confirmare.sterse', ['refs' => implode(', ', (array) json_decode((string) $s['anonimizate'], true))])];
        $linii[] = '';
        $linii[] = $t->t('gdpr.confirmare.pastrate');
        foreach (['fisa_cazare', 'facturi', 'sejur', 'sejur_calendar', 'alte'] as $k) {
            if (!isset($pastrate[$k])) {
                continue;
            }
            $p = $pastrate[$k];
            $rand = $t->t('gdpr.confirmare.date.' . $k, ['pana_la' => $p['pana_la'] !== null ? Format::zi($p['pana_la'], $l) : '']);
            $linii[] = '- ' . $rand . ($p['temei'] !== null ? ' ' . $t->t('gdpr.confirmare.temei', ['temei' => $p['temei']]) : '');
        }
        $linii[] = '- ' . $t->t('gdpr.confirmare.site');
        $ramase = (array) json_decode((string) $s['ramase'], true);
        if ($ramase !== []) {
            $linii[] = '';
            $linii[] = $t->t('gdpr.confirmare.ramase', ['refs' => implode(', ', $ramase)]);
        }
        $linii[] = '';
        $linii[] = $t->t('gdpr.confirmare.final');
        $nume = trim((string) ($proprietate['nume'] ?? ''));
        if ($nume !== '') {
            $linii[] = $nume;
        }
        return implode("\n", $linii);
    }
}
