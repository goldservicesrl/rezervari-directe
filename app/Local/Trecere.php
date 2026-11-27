<?php

declare(strict_types=1);

namespace Cazo\Local;

use Cazo\EasyCheck\Client;
use Cazo\EasyCheck\Conectare;
use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\EasyCheck\Oglinda;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Trecerea unui site în funcțiune dintr-un mod în celălalt, fără reinstalare.
 *
 * EasyCheck → fără cheie (`spreLocal`): oglinda de acum devine datele locale, editabile în panou: proprietatea (cu
 * pozele, clasificările și restul prezentării), datele firmei (în `date_legale`, unde lipsesc), camerele (cu spațiile
 * și pozele lor), prețurile pe noapte și de weekend precompletate din tariful de bază (marcate „de verificat”),
 * avansul, anularea gratuită și sejurul minim din același tarif, rezervările viitoare ale site-ului (cu aceleași
 * coduri, ca anulările și transferurile să meargă mai departe), cererile încă fără răspuns, zilele închise și, ca
 * „preluate din EasyCheck”, nopțile ocupate de celelalte rezervări din EasyCheck (până pune gazda calendarul iCal din
 * EasyCheck). Cheia EasyCheck rămâne salvată, pentru întoarcere. Din panou, trecerea merge prin asistent:
 * datele se pregătesc la pornire (`pregatesteDatele`), gazda le verifică pas cu pas, iar ultimul pas anunță EasyCheck
 * (`POST /site/trecere-local`) și trece site-ul (`treciCuAsistentul`).
 *
 * Fără cheie → EasyCheck (`spreEasyCheck`): cheia se verifică și se leagă (Conectare), oglinda se citește din nou din
 * EasyCheck, iar rezervările viitoare făcute local pleacă o singură dată spre EasyCheck (`local_trecere`, cheia de
 * idempotență `trecere-<cod>`): cele plătite cu cardul sau prin transfer, cu plata, ca `POST /rezervari`; cele care
 * așteaptă transferul, cu `plata_asteptata`; cele cu plata la sosire (și cererile fără răspuns), ca `POST /cereri`, de
 * acceptat de gazdă în EasyCheck. Camerele se potrivesc după cod, apoi după nume; o cameră fără pereche și orice refuz
 * apar în panou, cu motivul, iar o eroare de rețea se reia din sarcina `local:trecere`.
 */
final class Trecere
{
    public const MAPARE = 'local.trecere.camere';
    public const MOMENT = 'local.trecere.la';
    public const INCERCARI = 10;
    /** Stările comenzilor care trec: viitoare și încă în picioare. */
    public const STARI = ['confirmata', 'anulare_ceruta', 'de_capturat', 'transfer_in_asteptare', 'cerere_in_asteptare'];

    public function __construct(private Aplicatie $app)
    {
    }

    // ---- EasyCheck → fără cheie ----

    /**
     * Pregătirea datelor locale din oglinda EasyCheck, înaintea trecerii (pasul 1 al asistentului): proprietatea, datele
     * firmei (doar unde lipsesc), regula de rezervare și camerele cu prețurile precompletate (marcate „de verificat”).
     * Site-ul rămâne pe EasyCheck: datele locale nu se văd pe site până la trecere. Cu $doarCamereNoi, camerele care
     * există deja local rămân cum le-a lăsat gazda (trecerea de după asistent).
     * @return array{camere:int}
     */
    public function pregatesteDatele(?int $utilizator = null, bool $doarCamereNoi = false): array
    {
        $app = $this->app;
        $o = $app->ecOglinda();
        $prop = $o->corp('proprietate') ?? [];
        $camere = $this->camereOglinda();
        $b = $app->baza();
        $d = $app->localDepozit();
        $plan = $this->planDeBaza();
        $n = 0;
        $b->tranzactie(function () use ($app, $b, $d, $prop, $camere, $plan, $utilizator, $doarCamereNoi, &$n): void {
            // Proprietatea, cu tot ce arată site-ul (pozele, clasificările, notele de pe platforme).
            $d->salveazaProprietate([
                'nume' => $prop['nume'] ?? '', 'tip' => $prop['tip'] ?? 'pensiune', 'descriere' => (array) ($prop['descriere'] ?? []), 'adresa' => (array) ($prop['adresa'] ?? []),
                'coordonate' => $prop['coordonate'] ?? null, 'ore' => (array) ($prop['ore'] ?? []), 'reguli_casa' => (array) ($prop['reguli_casa'] ?? []),
                'contact' => (array) ($prop['contact'] ?? []), 'clasificari' => (array) ($prop['operator']['clasificari'] ?? []), 'poze' => (array) ($prop['poze'] ?? []),
                'linkuri_platforme' => (array) ($prop['linkuri_platforme'] ?? []), 'note_platforme' => $prop['note_platforme'] ?? null, 'spatii_comune' => $prop['spatii_comune'] ?? null,
            ], $utilizator);
            // Datele firmei: cele din EasyCheck intră în `date_legale` doar unde gazda n-a scris deja altceva.
            foreach (\Cazo\Legal\BlocLegal::CAMPURI as $camp) {
                $v = $prop['operator'][$camp] ?? null;
                if (is_string($v) && trim($v) !== '' && $b->valoare('SELECT 1 FROM {date_legale} WHERE camp = ?', [$camp]) === null) {
                    $b->insereaza('date_legale', ['camp' => $camp, 'valoare' => mb_substr(trim($v), 0, 500), 'actualizat_la' => $app->ceas()->moment()]);
                }
            }
            $forma = \Cazo\Legal\BlocLegal::formaEasyCheck($app);
            if ($forma !== null && $app->setari()->get('legal.forma') === null) {
                $app->setari()->pune('legal.forma', $forma);
            }
            // Regula de rezervare din tariful de bază.
            $d->salveazaReguli(['sejur_minim' => $plan['sejur_minim'], 'avans_procent' => $plan['avans_procent'], 'anulare_zile' => $plan['anulare_zile']], $utilizator);
            // Camerele, cu prețurile precompletate, pe care gazda le verifică.
            foreach ($camere as $c) {
                if ($doarCamereNoi && $d->camera((string) $c['id']) !== null) {
                    continue;
                }
                [$noapte, $weekend] = $this->preturiDeBaza((string) $c['id'], $plan['id']);
                $d->salveazaCamera((string) $c['id'], ['titlu' => mb_substr(trim((string) ($c['titlu'] ?? $c['nume'] ?? '')), 0, 120) ?: (string) $c['id'],
                    'unitati' => max(1, min(Depozit::UNITATI_MAX, (int) ($c['unitati'] ?? 1))), 'oaspeti_max' => max(1, min(Depozit::OASPETI_MAX, (int) ($c['oaspeti_max'] ?? 2))),
                    'pret_noapte' => $noapte, 'pret_weekend' => $weekend, 'de_verificat' => 1],
                    ['spatii' => $c['spatii'] ?? null, 'poze' => array_values(array_filter((array) ($c['poze'] ?? []), 'is_array'))]);
                $n++;
            }
        });
        return ['camere' => $n];
    }

    /** Camerele din oglinda EasyCheck, cu id. @return list<array> */
    private function camereOglinda(): array
    {
        return array_values(array_filter((array) ($this->app->ecOglinda()->corp('camere')['camere'] ?? []), static fn ($c): bool => is_array($c) && is_string($c['id'] ?? null)));
    }

    /** @return array{camere:int, rezervari:int, cereri:int, nopti_preluate:int, inchise:int} */
    public function spreLocal(?int $utilizator = null): array
    {
        $app = $this->app;
        if ($app->modLocal()) {
            throw new \LogicException('Site-ul lucrează deja fără cheie EasyCheck.');
        }
        $camere = $this->camereOglinda();
        $b = $app->baza();
        $rez = ['camere' => 0, 'rezervari' => 0, 'cereri' => 0, 'nopti_preluate' => 0, 'inchise' => 0];
        $azi = $app->azi();
        // Cu asistentul, camerele și prețurile verificate de gazdă la pasul 1 rămân; fără el, se pregătesc acum.
        $pregatite = is_string($this->asistent()['pregatit_la'] ?? null);
        $b->tranzactie(function () use ($app, $b, $camere, $azi, $utilizator, $pregatite, &$rez): void {
            $p = $this->pregatesteDatele($utilizator, $pregatite);
            $rez['camere'] = $pregatite ? count($camere) : $p['camere'];
            // Rezervările viitoare ale site-ului, cu aceleași coduri EasyCheck.
            $proprii = [];
            foreach ($b->toate("SELECT * FROM {comenzi} WHERE plecare > ? AND ec_rezervare IS NOT NULL AND stare IN ('confirmata', 'anulare_ceruta', 'de_capturat', 'transfer_in_asteptare')", [$azi]) as $cm) {
                if ($b->valoare('SELECT 1 FROM {local_rezervari} WHERE id = ?', [$cm['ec_rezervare']]) !== null) {
                    continue;
                }
                $cam = array_values(array_filter((array) json_decode((string) $cm['camere'], true), 'is_array'));
                $acum = $app->ceas()->moment();
                $pa = null;
                if ($cm['stare'] === 'transfer_in_asteptare') {
                    $pa = ['metoda' => 'transfer', 'suma' => (int) $cm['suma_acum'], 'termen' => $cm['transfer_termen'] !== null ? TransportLocal::iso((string) $cm['transfer_termen']) : null,
                        'referinta' => (string) $cm['ref'], 'stare' => 'in_asteptare', 'decisa_la' => null,
                        'anuntata_de_oaspete_la' => $cm['transfer_anuntat_la'] !== null ? TransportLocal::iso((string) $cm['transfer_anuntat_la']) : null];
                }
                $b->insereaza('local_rezervari', ['id' => $cm['ec_rezervare'], 'ref_site' => $cm['ref'], 'stare' => $pa !== null ? 'in_asteptarea_platii' : 'confirmata',
                    'sosire' => $cm['sosire'], 'plecare' => $cm['plecare'], 'camere' => TransportLocal::json(array_map(static fn (array $x): array => ['camera' => (string) $x['camera'],
                        'adulti' => (int) ($x['adulti'] ?? 1), 'copii' => (int) ($x['copii'] ?? 0)], $cam)),
                    'total' => (int) $cm['total'], 'plata' => null, 'plata_asteptata' => $pa !== null ? TransportLocal::json($pa) : null, 'sursa' => 'preluata', 'proba' => (int) $cm['proba'],
                    'creat_la' => $acum, 'actualizat_la' => $acum]);
                foreach (Depozit::zile((string) $cm['sosire'], (string) $cm['plecare']) as $zi) {
                    foreach ($cam as $x) {
                        $proprii[(string) $x['camera']][$zi] = ($proprii[(string) $x['camera']][$zi] ?? 0) + 1;
                    }
                }
                $rez['rezervari']++;
            }
            // Cererile fără răspuns: gazda le hotărăște de acum în panoul site-ului.
            foreach ($b->toate("SELECT * FROM {comenzi} WHERE fel = 'cash' AND stare = 'cerere_in_asteptare' AND ec_cerere IS NOT NULL") as $cm) {
                if ($b->valoare('SELECT 1 FROM {local_cereri} WHERE id = ?', [$cm['ec_cerere']]) !== null) {
                    continue;
                }
                $cam = array_values(array_filter((array) json_decode((string) $cm['camere'], true), 'is_array'));
                $b->insereaza('local_cereri', ['id' => $cm['ec_cerere'], 'ref_site' => $cm['ref'], 'stare' => 'in_asteptare', 'sosire' => $cm['sosire'], 'plecare' => $cm['plecare'],
                    'camere' => TransportLocal::json(array_map(static fn (array $x): array => ['camera' => (string) $x['camera'], 'adulti' => (int) ($x['adulti'] ?? 1), 'copii' => (int) ($x['copii'] ?? 0)], $cam)),
                    'total' => (int) $cm['total'], 'expira_la' => $cm['ec_expira_la'] ?? $app->ceas()->moment(TransportLocal::TERMEN_CERERE), 'proba' => (int) $cm['proba'],
                    'creat_la' => $app->ceas()->moment()]);
                $rez['cereri']++;
            }
            // Celelalte nopți ocupate în EasyCheck (rezervările de pe platforme) și zilele închise acolo.
            $preluate = [];
            foreach ($b->toate('SELECT n.camera, n.zi, n.libere, n.inchis FROM {nopti} n WHERE n.zi >= ?', [$azi]) as $n) {
                $cam = (string) $n['camera'];
                $u = 1;
                foreach ($camere as $c) {
                    if ($c['id'] === $cam) {
                        $u = max(1, (int) ($c['unitati'] ?? 1));
                    }
                }
                $alte = $u - (int) $n['libere'] - ($proprii[$cam][(string) $n['zi']] ?? 0);
                if ($alte > 0) {
                    $preluate[$cam][(string) $n['zi']] = $alte;
                }
                if ((int) $n['inchis'] === 1 && $b->valoare('SELECT 1 FROM {local_inchise} WHERE camera = ? AND zi = ?', [$cam, $n['zi']]) === null) {
                    $b->insereaza('local_inchise', ['camera' => $cam, 'zi' => $n['zi']]);
                    $rez['inchise']++;
                }
            }
            if (!$pregatite) {
                $rez['nopti_preluate'] = $app->localIcal()->preluare($preluate, $app->traducator('ro', 'admin')->t('admin.local.ical.preluate_nume'));
                return;
            }
            // Cu asistentul: calendarele platformelor puse la pasul 4 țin locul nopților oglindite din EasyCheck, care
            // nu mai contează pentru disponibilitate și nu se mai preiau. La o cameră fără niciun calendar importat, nopțile
            // ocupate acum în EasyCheck rămân închise pe site, ca să nu apară libere; panoul o spune.
            $app->localIcal()->preluare([], '');
            $rez['inchise_camere'] = [];
            foreach ($preluate as $cam => $zile) {
                if ($b->valoare("SELECT 1 FROM {local_ical_surse} WHERE fel = 'ical' AND camera = ? LIMIT 1", [$cam]) !== null) {
                    continue;
                }
                foreach (array_keys($zile) as $zi) {
                    if ($b->valoare('SELECT 1 FROM {local_inchise} WHERE camera = ? AND zi = ?', [$cam, $zi]) === null) {
                        $b->insereaza('local_inchise', ['camera' => $cam, 'zi' => $zi]);
                        $rez['inchise']++;
                    }
                }
                $rez['inchise_camere'][] = (string) $cam;
            }
        });
        // șabloanele scrise în EasyCheck rămân acolo (pentru o revenire); paragrafele din panou rămân cele de dinainte de
        // import, iar la revenire importul se reia (EasyCheck redeschide fereastra după `POST /site/trecere-local`).
        $app->mesajeEc()->uitaImportul();
        \Cazo\Local\Mod::alege($app->setari(), Mod::LOCAL, $utilizator);
        $app->setari()->pune(self::MOMENT, ['spre' => Mod::LOCAL, 'la' => $app->ceas()->moment()], $utilizator);
        $app->uitaLegatura();
        $app->localDepozit()->repartizeaza($app->azi());
        $this->recitesteTot();
        return $rez;
    }

    // ---- asistentul trecerii pe datele proprii ----

    public const ASISTENT = 'local.trecere.asistent';
    public const PASI = 5;
    /** Platformele pașilor 2 și 3: cheia din stare => numele calendarului citit la pasul 4. */
    public const PLATFORME = ['booking' => 'Booking.com', 'airbnb' => 'Airbnb'];
    public const ZAVOR = 'local:trecere-asistent';

    /**
     * Starea asistentului, pe server (gazda închide și reia de unde a rămas): `pregatit_la`, `facuti` (pașii bifați),
     * `optiuni` (booking/airbnb: adaugat sau nu), `idem` (cheia de idempotență a anunțului) și, după trecere, `gata`.
     */
    public function asistent(): array
    {
        $s = $this->app->setari()->get(self::ASISTENT);
        return is_array($s) ? $s : [];
    }

    /** Asistentul e pornit, iar site-ul încă pe EasyCheck: datele locale pregătite se pot verifica și completa. */
    public function asistentDeschis(): bool
    {
        $s = $this->asistent();
        return !$this->app->modLocal() && is_string($s['pregatit_la'] ?? null) && !isset($s['gata']);
    }

    /** Pornește asistentul (pregătește datele locale din oglindă) sau îl reia, neschimbat, când e deja pornit. */
    public function incepeAsistent(?int $utilizator = null): array
    {
        if ($this->app->modLocal()) {
            throw new \LogicException('Site-ul lucrează deja fără cheie EasyCheck.');
        }
        if ($this->asistentDeschis()) {
            return $this->asistent();
        }
        $this->pregatesteDatele($utilizator);
        $s = ['pregatit_la' => $this->app->ceas()->moment(), 'facuti' => [], 'optiuni' => [], 'idem' => 'trecere-local-' . Client::uuid()];
        $this->app->setari()->pune(self::ASISTENT, $s, $utilizator);
        return $s;
    }

    /** Primul pas încă nebifat (1-4) sau 5, când primii patru sunt bifați. */
    public function pasCurent(): int
    {
        $f = (array) ($this->asistent()['facuti'] ?? []);
        for ($i = 1; $i < self::PASI; $i++) {
            if (empty($f[(string) $i])) {
                return $i;
            }
        }
        return self::PASI;
    }

    /**
     * Bifa unui pas (1-4), verificată pe server: doar pașii până la cel curent; la pașii 2 și 3, alegerea `adaugat` sau
     * `nu` (nu închiriez pe platformă). La pasul 1, prețurile camerelor devin verificate.
     * @throws \DomainException `asistent` (asistentul nu e pornit), `ordine` (pas sărit), `bifa` (bifa lipsește)
     */
    public function bifeaza(int $pas, ?string $alegere, ?int $utilizator = null): void
    {
        if (!$this->asistentDeschis()) {
            throw new \DomainException('asistent');
        }
        if ($pas < 1 || $pas >= self::PASI || $pas > $this->pasCurent()) {
            throw new \DomainException('ordine');
        }
        $s = $this->asistent();
        if ($pas === 2 || $pas === 3) {
            if (!in_array($alegere, ['adaugat', 'nu'], true)) {
                throw new \DomainException('bifa');
            }
            $s['optiuni'][$pas === 2 ? 'booking' : 'airbnb'] = $alegere;
        } elseif ($alegere !== '1') {
            throw new \DomainException('bifa');
        }
        if ($pas === 1) {
            $this->app->baza()->executa('UPDATE {local_camere} SET de_verificat = 0, actualizat_la = ? WHERE activa = 1', [$this->app->ceas()->moment()]);
        }
        $s['facuti'][(string) $pas] = true;
        $this->app->setari()->pune(self::ASISTENT, $s, $utilizator);
    }

    /** Platformele pasului 4: cele pe care gazda închiriază (fără „Nu închiriez pe …”). @return array<string,string> */
    public function platformeFolosite(): array
    {
        $o = (array) ($this->asistent()['optiuni'] ?? []);
        return array_filter(self::PLATFORME, static fn (string $nume, string $k): bool => ($o[$k] ?? 'adaugat') !== 'nu', ARRAY_FILTER_USE_BOTH);
    }

    /** Asistentul se închide fără trecere; datele locale pregătite rămân, neafișate pe site. */
    public function renuntaAsistent(?int $utilizator = null): void
    {
        if ($this->asistentDeschis()) {
            $this->app->setari()->pune(self::ASISTENT, null, $utilizator);
        }
    }

    /**
     * Calendarul unei platforme la pasul 4: adresa se citește pe loc (garda SSRF a calendarelor) și ia locul celei
     * puse înainte pentru aceeași cameră și platformă. @return array{stare:string, evenimente?:int, eroare?:string}
     * @throws EroareIcal adresa respinsă (nimic nu se salvează) | \DomainException
     */
    public function calendarPlatforma(string $camera, string $platforma, string $url): array
    {
        if (!$this->asistentDeschis()) {
            throw new \DomainException('asistent');
        }
        $nume = self::PLATFORME[$platforma] ?? null;
        if ($nume === null) {
            throw new \DomainException('platforma');
        }
        $vechi = $this->sursaPlatforma($camera, $platforma);
        $r = $this->app->localIcal()->adauga($nume, $url, $camera);
        if ($vechi !== null) {
            $this->app->localIcal()->sterge((int) $vechi['id']);
        }
        return $r['citire'];
    }

    /** Calendarul citit pentru o cameră și o platformă (cel mai nou), sau null. */
    public function sursaPlatforma(string $camera, string $platforma): ?array
    {
        $nume = self::PLATFORME[$platforma] ?? null;
        $gasita = null;
        foreach ($this->app->localIcal()->surse() as $s) {
            if ($s['fel'] === 'ical' && $s['camera'] === $camera && $s['nume'] === $nume) {
                $gasita = $s;
            }
        }
        return $gasita;
    }

    /**
     * Anunțul de trecere către EasyCheck (contractul): `POST /site/trecere-local`, cu cheia site-ului și aceeași
     * cheie de idempotență la fiecare reluare. @return array{stare:string, sterse?:array, din?:?string, cod?:string}
     */
    public function anuntaEasyCheck(): array
    {
        $s = $this->asistent();
        $idem = is_string($s['idem'] ?? null) ? $s['idem'] : 'trecere-local-' . Client::uuid();
        try {
            $j = $this->app->ecClient()->scrie('POST', '/site/trecere-local', '{}', $idem)->json() ?? [];
        } catch (EroareApi $e) {
            return ['stare' => 'esuat', 'cod' => mb_substr((string) ($e->cod ?? 'http_' . $e->raspuns->status), 0, 64)];
        } catch (EroareTransport) {
            return ['stare' => 'esuat', 'cod' => 'retea'];
        } catch (\Throwable $e) {
            $this->app->jurnal()->avertisment('Anunțul trecerii către EasyCheck a eșuat', ['eroare' => $e->getMessage()]);
            return ['stare' => 'esuat', 'cod' => 'eroare'];
        }
        $st = (array) ($j['sterse'] ?? []);
        return ['stare' => 'ok', 'sterse' => ['rezervari' => (int) ($st['rezervari'] ?? 0), 'cereri' => (int) ($st['cereri'] ?? 0)], 'din' => is_string($j['din'] ?? null) ? $j['din'] : null];
    }

    /**
     * Ultimul pas al asistentului: anunțul către EasyCheck, apoi trecerea (`spreLocal`). Un anunț eșuat (rețea, cheie
     * ștearsă) nu oprește trecerea; panoul îi spune gazdei ce mai rămâne de făcut în EasyCheck. Repetat (dublu clic),
     * sub zăvor: a doua cerere găsește site-ul trecut și întoarce rezultatul primei.
     * @return array{camere:int, rezervari:int, cereri:int, nopti_preluate:int, inchise:int, anunt:string, repetat?:bool}
     * @throws \DomainException `asistent`, `ordine`, `ocupat`
     */
    public function treciCuAsistentul(?int $utilizator = null): array
    {
        [$ok, $rez] = $this->app->zavor()->cuZavor(self::ZAVOR, 120, function () use ($utilizator): array {
            $this->app->setari()->proaspat(self::ASISTENT);
            $s = $this->asistent();
            if (isset($s['gata']) && $this->app->modLocal()) {
                return (array) ($s['gata']['rezultat'] ?? []) + ['anunt' => (string) ($s['gata']['anunt'] ?? 'ok'), 'repetat' => true];
            }
            if (!$this->asistentDeschis()) {
                throw new \DomainException('asistent');
            }
            if ($this->pasCurent() < self::PASI) {
                throw new \DomainException('ordine');
            }
            $anunt = $this->anuntaEasyCheck();
            $rez = $this->spreLocal($utilizator);
            $s['gata'] = ['la' => $this->app->ceas()->moment(), 'anunt' => $anunt['stare'], 'cod' => $anunt['cod'] ?? null, 'sterse' => $anunt['sterse'] ?? null, 'rezultat' => $rez];
            $this->app->setari()->pune(self::ASISTENT, $s, $utilizator);
            return $rez + ['anunt' => $anunt['stare']];
        });
        if (!$ok) {
            throw new \DomainException('ocupat');
        }
        return $rez;
    }

    /** Anunțul trecerii a eșuat, iar site-ul lucrează pe datele proprii: gazda șterge rezervările din EasyCheck. */
    public function anuntEsuat(): bool
    {
        $g = $this->asistent()['gata'] ?? null;
        return $this->app->modLocal() && is_array($g) && ($g['anunt'] ?? null) === 'esuat';
    }

    /**
     * Tariful de bază al oglinzii: primul tarif propriu (nu calculat), cu avansul, anularea și sejurul minim cel mai des
     * întâlnit în grila lui.
     * @return array{id:?string, avans_procent:int, anulare_zile:int, sejur_minim:int}
     */
    public function planDeBaza(): array
    {
        $b = $this->app->baza();
        $p = $b->rand("SELECT * FROM {planuri} WHERE fel <> 'calculat' ORDER BY ordine, id LIMIT 1") ?? $b->rand('SELECT * FROM {planuri} ORDER BY ordine, id LIMIT 1');
        $anulare = $p['anulare_zile'] ?? null;
        $min = $p === null ? null : $b->valoare('SELECT sejur_minim FROM {nopti_pret} WHERE plan = ? AND zi >= ? GROUP BY sejur_minim ORDER BY COUNT(*) DESC, sejur_minim LIMIT 1',
            [$p['id'], $this->app->azi()]);
        $prop = $this->app->ecOglinda()->corp('proprietate') ?? [];
        return ['id' => $p['id'] ?? null, 'avans_procent' => max(0, min(100, (int) ($p['avans_procent'] ?? 0))),
            'anulare_zile' => is_numeric($anulare) ? max(0, min(Depozit::ANULARE_MAX, (int) $anulare)) : (is_int($prop['anulare_zile'] ?? null) ? min(Depozit::ANULARE_MAX, $prop['anulare_zile']) : 7),
            'sejur_minim' => max(1, min(Depozit::SEJUR_MINIM_MAX, (int) ($min ?? 1)))];
    }

    /**
     * Prețul pe noapte și cel de weekend precompletate: prețul cel mai des întâlnit în următoarele 90 de nopți ale
     * tarifului de bază, separat pentru nopțile de duminică-joi și pentru cele de vineri și sâmbătă; fără nopți de un fel,
     * prețul celuilalt fel.
     * @return array{0:int, 1:int} în bani
     */
    public function preturiDeBaza(string $camera, ?string $plan): array
    {
        if ($plan === null) {
            return [0, 0];
        }
        $azi = $this->app->azi();
        $pana = gmdate('Y-m-d', strtotime($azi . ' 00:00:00 UTC') + 90 * 86400);
        $f = ['noapte' => [], 'weekend' => []];
        foreach ($this->app->baza()->toate('SELECT zi, pret FROM {nopti_pret} WHERE camera = ? AND plan = ? AND zi >= ? AND zi < ? AND pret IS NOT NULL', [$camera, $plan, $azi, $pana]) as $r) {
            $k = Depozit::weekend((string) $r['zi']) ? 'weekend' : 'noapte';
            $f[$k][(int) $r['pret']] = ($f[$k][(int) $r['pret']] ?? 0) + 1;
        }
        $cel = static function (array $x): int {
            if ($x === []) {
                return 0;
            }
            arsort($x);
            $max = max($x);
            return min(array_keys(array_filter($x, static fn (int $n): bool => $n === $max)));
        };
        $noapte = $cel($f['noapte']);
        $weekend = $cel($f['weekend']);
        // Prețurile se scriu în lei întregi în panou.
        $leu = static fn (int $b): int => intdiv($b + 50, 100) * 100;
        return [$leu($noapte > 0 ? $noapte : $weekend), $leu($weekend > 0 ? $weekend : $noapte)];
    }

    private function recitesteTot(): void
    {
        $o = $this->app->ecOglinda();
        foreach (array_keys(Oglinda::RESURSE) as $r) {
            try {
                $o->citeste($r);
            } catch (\Throwable $e) {
                $this->app->jurnal()->avertisment('Prima citire după trecere a eșuat', ['resursa' => $r, 'eroare' => $e->getMessage()]);
            }
        }
        $o->schimbaContinutul();
    }

    // ---- fără cheie → EasyCheck ----

    /**
     * Leagă cheia și trece site-ul pe datele EasyCheck; rezervările locale viitoare se pun la trimis. La o cheie
     * respinsă, site-ul rămâne fără cheie, neschimbat. $cheie null: cheia EasyCheck salvată de dinainte.
     * @return array rezumatul cheii (Proiectii::eu) și `trimise` (rezultatul primei trimiteri)
     * @throws EroareApi|EroareTransport|\InvalidArgumentException|\UnexpectedValueException
     */
    public function spreEasyCheck(?string $cheie, ?int $utilizator = null): array
    {
        $app = $this->app;
        if (!$app->modLocal()) {
            throw new \LogicException('Site-ul e deja legat de EasyCheck.');
        }
        $cheie = $cheie !== null && trim($cheie) !== '' ? trim($cheie) : $app->secrete()->citeste('ec_cheie');
        if ($cheie === null) {
            throw new \InvalidArgumentException('Cheia EasyCheck lipsește.');
        }
        Mod::alege($app->setari(), Mod::EASYCHECK, $utilizator);
        $app->uitaLegatura();
        try {
            $rez = $app->ecConectare()->legaCheia($cheie);
            if (($rez['scop'] ?? null) !== 'rezervari') {
                throw new \UnexpectedValueException('scop_citire');
            }
        } catch (\Throwable $e) {
            Mod::alege($app->setari(), Mod::LOCAL, $utilizator);
            $app->uitaLegatura();
            throw $e;
        }
        // Nimic din oglinda locală nu rămâne: proprietatea, camerele și prețurile vin de acum din EasyCheck.
        $app->ecOglinda()->goleste();
        $app->ecConectare()->sincronizeazaTot($app->ecSincronizare(), 20.0);
        try {
            $app->ecConectare()->inregistreazaAnunturile();
        } catch (\Throwable) {
            // Fără anunțuri, site-ul merge pe interogare; panoul arată pasul.
        }
        $app->stareSite()->declara();
        $app->setari()->pune(self::MOMENT, ['spre' => Mod::EASYCHECK, 'la' => $app->ceas()->moment()], $utilizator);
        // O trecere viitoare pe datele proprii pornește asistentul de la capăt.
        $app->setari()->pune(self::ASISTENT, null, $utilizator);
        $this->pregateste();
        $this->mapeazaAutomat();
        $app->ecOglinda()->schimbaContinutul();
        return $rez + ['trimise' => $this->trimite(10)];
    }

    /** Pune la trimis comenzile viitoare făcute local, o singură dată fiecare. */
    public function pregateste(): int
    {
        $b = $this->app->baza();
        $n = 0;
        foreach ($b->toate("SELECT id, ref, fel, stare FROM {comenzi} WHERE cheie_prefix = ? AND plecare > ? AND stare IN ('confirmata', 'anulare_ceruta', 'de_capturat', 'transfer_in_asteptare', 'cerere_in_asteptare')",
            [Mod::PREFIX, $this->app->azi()]) as $c) {
            if ($b->valoare('SELECT 1 FROM {local_trecere} WHERE comanda_id = ?', [$c['id']]) !== null) {
                continue;
            }
            $fel = $c['fel'] === 'cash' ? 'cerere' : 'rezervare';
            $b->insereaza('local_trecere', ['comanda_id' => (int) $c['id'], 'ref' => $c['ref'], 'fel' => $fel, 'stare' => 'de_trimis', 'incercari' => 0,
                'la' => $this->app->ceas()->moment(), 'actualizat_la' => $this->app->ceas()->moment()]);
            $n++;
        }
        return $n;
    }

    /**
     * Perechile camerelor (cod local → cod EasyCheck): același cod (camerele venite din EasyCheck), apoi același nume
     * (fără majuscule și diacritice). Alegerea gazdei din panou rămâne.
     * @return array<string,?string>
     */
    public function mapeazaAutomat(): array
    {
        $ec = [];
        foreach ((array) ($this->app->ecOglinda()->corp('camere')['camere'] ?? []) as $c) {
            if (is_array($c) && is_string($c['id'] ?? null)) {
                $ec[$c['id']] = self::cheieNume((string) ($c['titlu'] ?? ''));
            }
        }
        $m = (array) $this->app->setari()->get(self::MAPARE, []);
        foreach ($this->app->localDepozit()->camere(true) as $c) {
            if (isset($m[$c['id']]) && isset($ec[$m[$c['id']]])) {
                continue;
            }
            $m[$c['id']] = isset($ec[$c['id']]) ? $c['id'] : (array_search(self::cheieNume($c['titlu']), $ec, true) ?: null);
        }
        $this->app->setari()->pune(self::MAPARE, $m);
        return $m;
    }

    public function salveazaMapare(array $m, ?int $utilizator = null): void
    {
        $ec = [];
        foreach ((array) ($this->app->ecOglinda()->corp('camere')['camere'] ?? []) as $c) {
            if (is_array($c) && is_string($c['id'] ?? null)) {
                $ec[$c['id']] = true;
            }
        }
        $curat = [];
        foreach ($this->app->localDepozit()->camere(true) as $c) {
            $v = $m[$c['id']] ?? null;
            $curat[$c['id']] = is_string($v) && isset($ec[$v]) ? $v : null;
        }
        $this->app->setari()->pune(self::MAPARE, $curat, $utilizator);
        // O pereche nouă poate debloca trimiterile oprite de camera fără pereche.
        $this->app->baza()->executa("UPDATE {local_trecere} SET stare = 'de_trimis', cod = NULL, incercari = 0 WHERE stare = 'esuata' AND cod = 'camera'");
    }

    private static function cheieNume(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return strtr($s, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    }

    /**
     * Trimite cel mult $cate comenzi puse la trimis. @return array{trimise:int, esuate:int, amanate:int}
     */
    public function trimite(int $cate = 20): array
    {
        $rez = ['trimise' => 0, 'esuate' => 0, 'amanate' => 0];
        if ($this->app->modLocal()) {
            return $rez;
        }
        foreach ($this->app->baza()->toate("SELECT * FROM {local_trecere} WHERE stare = 'de_trimis' ORDER BY comanda_id LIMIT " . max(1, $cate)) as $t) {
            $r = $this->una($t);
            $rez[$r === 'trimisa' || $r === 'cerere' ? 'trimise' : ($r === 'esuata' ? 'esuate' : 'amanate')]++;
        }
        return $rez;
    }

    private function una(array $t): string
    {
        $b = $this->app->baza();
        $c = $this->app->comenzi()->dupaId((int) $t['comanda_id']);
        $pune = function (string $stare, ?string $cod = null, ?string $ecId = null) use ($b, $t): string {
            $b->executa('UPDATE {local_trecere} SET stare = ?, cod = ?, ec_id = COALESCE(?, ec_id), incercari = incercari + 1, actualizat_la = ? WHERE comanda_id = ?',
                [$stare, $cod, $ecId, $this->app->ceas()->moment(), $t['comanda_id']]);
            return $stare;
        };
        if ($c === null || !in_array($c['stare'], self::STARI, true) || (string) $c['plecare'] <= $this->app->azi()) {
            return $pune('renuntata', 'stare');
        }
        $corp = json_decode($this->app->comenzi()->corpEcDin($c), true);
        if (!is_array($corp)) {
            return $pune('esuata', 'corp');
        }
        $m = (array) $this->app->setari()->get(self::MAPARE, []);
        foreach ($corp['camere'] ?? [] as $i => $x) {
            $nou = $m[$x['camera'] ?? ''] ?? null;
            if (!is_string($nou)) {
                return $pune('esuata', 'camera');
            }
            $corp['camere'][$i]['camera'] = $nou;
        }
        unset($corp['plan'], $corp['inlocuieste']);
        $cale = '/rezervari';
        // Contractul trecerii, punctele 2 și 4: EasyCheck primește rezervarea (și cererea) adusă la trecere cu prețul plătit pe site,
        // fără verificarea prețului, a avansului și a nopților, o singură dată pe `ref_site`.
        $corp['trecere'] = true;
        if ($c['fel'] === 'cash') {
            // Plata la sosire: cererea ajunge în EasyCheck, unde o acceptă gazda.
            unset($corp['plata'], $corp['plata_asteptata']);
            $cale = '/cereri';
        } elseif ($c['fel'] === 'transfer' && $c['stare'] === 'transfer_in_asteptare') {
            $termen = $c['transfer_termen'] !== null ? Ceas::dinMoment((string) $c['transfer_termen']) : 0;
            $acum = $this->app->ceas()->acum();
            // Termenul trebuie să fie viitor (oaspetele a spus deja că a plătit: încă trei zile pentru verificare).
            $corp['plata_asteptata'] = \Cazo\Rezervare\Transfer::plataAsteptata((int) $c['suma_acum'], $termen > $acum + 600 ? $termen : $acum + 3 * 86400, (string) $c['ref']);
            unset($corp['plata']);
        } elseif ($c['fel'] === 'transfer') {
            unset($corp['plata_asteptata']);
            $corp['plata'] = ['suma' => max(1, (int) $c['suma_acum']), 'ref' => (string) $c['ref'], 'metoda' => 'transfer'];
        } elseif (!is_array($corp['plata'] ?? null)) {
            return $pune('esuata', 'corp');
        }
        try {
            $r = $this->app->ecClient()->scrie('POST', $cale, Client::json($corp), 'trecere-' . $c['ref']);
        } catch (EroareApi $e) {
            if ($e->raspuns->status >= 500 || $e->raspuns->status === 429 || in_array($e->comportament, ['reincearca', 'pauza'], true)) {
                return (int) $t['incercari'] + 1 >= self::INCERCARI ? $pune('esuata', 'retea') : $pune('de_trimis', 'retea');
            }
            return $pune('esuata', mb_substr((string) ($e->cod ?? 'http_' . $e->raspuns->status), 0, 64));
        } catch (EroareTransport) {
            return (int) $t['incercari'] + 1 >= self::INCERCARI ? $pune('esuata', 'retea') : $pune('de_trimis', 'retea');
        }
        $j = $r->json() ?? [];
        $prefix = Conectare::prefix($this->app->secrete()->citeste('ec_cheie'));
        if ($cale === '/cereri') {
            $cer = is_string($j['id'] ?? null) ? $j['id'] : null;
            if ($cer === null) {
                return $pune('de_trimis', 'forma');
            }
            if ($c['stare'] === 'cerere_in_asteptare') {
                // Cererea fără răspuns continuă în EasyCheck: comanda urmărește de acum cererea nouă.
                $b->executa('UPDATE {comenzi} SET ec_cerere = ?, ec_expira_la = ?, cheie_prefix = ? WHERE id = ?',
                    [$cer, \Cazo\Rezervare\CerereCash::moment($j['expira_la'] ?? null), $prefix, $c['id']]);
                return $pune('trimisa', null, $cer);
            }
            return $pune('cerere', null, $cer);
        }
        $rez = is_string($j['id'] ?? null) && preg_match('~^rez_[0-9A-Za-z]{1,64}$~', $j['id']) ? $j['id'] : null;
        if ($rez === null) {
            return $pune('de_trimis', 'forma');
        }
        $b->executa('UPDATE {comenzi} SET ec_rezervare = ?, cheie_prefix = ?, suprapunere = ? WHERE id = ?', [$rez, $prefix, ($j['suprapunere'] ?? false) === true ? 1 : 0, $c['id']]);
        $this->app->masinaStari()->jurnal((int) $c['id'], (string) $c['stare'], (string) $c['stare'], 'sistem', ['trecere' => 'easycheck', 'rezervare' => $rez]);
        return $pune('trimisa', null, $rez);
    }

    /** Cererile trimise pentru rezervările cu plata la sosire: după acceptarea din EasyCheck, comanda primește rezervarea. */
    public function urmareste(): int
    {
        $n = 0;
        foreach ($this->app->baza()->toate("SELECT * FROM {local_trecere} WHERE stare = 'cerere' AND ec_id IS NOT NULL ORDER BY comanda_id LIMIT 20") as $t) {
            try {
                $j = $this->app->ecClient()->citeste('/cereri/' . $t['ec_id'])->json() ?? [];
            } catch (EroareApi|EroareTransport) {
                continue;
            }
            $stare = (string) ($j['stare'] ?? '');
            if ($stare === 'acceptata' && is_string($j['rezervare'] ?? null)) {
                $this->app->baza()->executa('UPDATE {comenzi} SET ec_rezervare = ?, cheie_prefix = ? WHERE id = ?',
                    [$j['rezervare'], Conectare::prefix($this->app->secrete()->citeste('ec_cheie')), $t['comanda_id']]);
                $this->app->baza()->executa("UPDATE {local_trecere} SET stare = 'trimisa', ec_id = ?, actualizat_la = ? WHERE comanda_id = ?", [$j['rezervare'], $this->app->ceas()->moment(), $t['comanda_id']]);
                $n++;
            } elseif (in_array($stare, ['refuzata', 'expirata'], true)) {
                $this->app->baza()->executa("UPDATE {local_trecere} SET stare = 'esuata', cod = ?, actualizat_la = ? WHERE comanda_id = ?", ['cerere_' . $stare, $this->app->ceas()->moment(), $t['comanda_id']]);
                $n++;
            }
        }
        return $n;
    }

    public function areLucru(): bool
    {
        try {
            return $this->app->baza()->valoare("SELECT 1 FROM {local_trecere} WHERE stare IN ('de_trimis', 'cerere') LIMIT 1") !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function sarcina(): string
    {
        $r = $this->trimite();
        $u = $this->urmareste();
        return "trimise: {$r['trimise']}, eșuate: {$r['esuate']}, amânate: {$r['amanate']}, cereri acceptate sau închise: $u";
    }

    /** Rândurile trecerii, pentru panou. @return list<array> */
    public function stari(): array
    {
        try {
            return $this->app->baza()->toate('SELECT * FROM {local_trecere} ORDER BY comanda_id');
        } catch (\Throwable) {
            return [];
        }
    }
}
