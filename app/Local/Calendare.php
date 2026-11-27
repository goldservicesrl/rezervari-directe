<?php

declare(strict_types=1);

namespace Cazo\Local;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Setari;
use Cazo\Securitate\Cutie;

defined('CAZO') or exit;

/**
 * Calendarele site-ului fără cheie EasyCheck.
 *
 * Citite: adresele iCal puse de gazdă în panou (Booking, Airbnb, orice alt calendar), fiecare legată de o
 * cameră; sarcina `local:ical` le citește la fiecare N minute (15 implicit, `local.ical_minute`, între 5 și 180), cu
 * cronul din cPanel sau, fără el, cu vizitele site-ului. Fiecare eveniment ocupă o unitate a camerei pe nopțile lui.
 * Adresa trece prin AdresaSigura (doar https public, IP fixat, redirecționări verificate, cel mult 3), răspunsul are
 * limite de timp și de mărime, iar un răspuns stricat (o pagină de eroare, un calendar fără VCALENDAR) lasă nopțile
 * citite ultima dată neatinse, cu eroarea în panou. Adresa se ține criptată: are în ea jetonul platformei.
 *
 * Dat: calendarul site-ului, câte unul pe cameră, la o adresă secretă (`/calendar/<jeton>/<camera>.ics`), cu nopțile
 * fără nicio unitate liberă după rezervările site-ului și zilele închise de gazdă (nu și nopțile citite din alte
 * calendare, ca o platformă să nu-și primească înapoi propriile rezervări). Jetonul e derivat din cheia site-ului și
 * se schimbă din panou („Schimbați adresa”), când adresa veche a ajuns unde nu trebuia. La camerele cu mai multe
 * unități, câte o adresă pe unitate (`<camera>-<n>.ics`), cu rezervările repartizate pe ea (`Depozit::repartizeaza`).
 */
final class Calendare
{
    public const INTERVAL = 'local.ical_minute';
    public const VERSIUNE_JETON = 'local.ical_jeton';
    public const SURSE_MAXIME = 30;
    public const REDIRECTARI = 3;
    /** Sursa scrisă de trecerea din modul EasyCheck: nopțile ocupate de rezervările din EasyCheck, la acea oră. */
    public const PRELUARE = 'preluare';

    private ?\Closure $rezolva = null;

    public function __construct(private Aplicatie $app)
    {
    }

    /** Rezolvarea numelor de gazdă a testelor (null = DNS-ul real). */
    public function cuRezolvare(?\Closure $f): self
    {
        $this->rezolva = $f;
        return $this;
    }

    public static function interval(Setari $s): int
    {
        $m = $s->get(self::INTERVAL, 15);
        return is_int($m) && $m >= 5 && $m <= 180 ? $m : 15;
    }

    private function cutie(): Cutie
    {
        return new Cutie($this->app->chei()->subcheie('ical'));
    }

    /** @return list<array{id:int, nume:string, fel:string, gazda:string, camera:string, activa:bool, citita_la:?string, reusita_la:?string, eroare:?string, evenimente:int}> */
    public function surse(): array
    {
        $rez = [];
        foreach ($this->app->baza()->toate('SELECT id, nume, fel, gazda, camera, activa, citita_la, reusita_la, eroare, evenimente FROM {local_ical_surse} ORDER BY id') as $r) {
            $rez[] = ['id' => (int) $r['id'], 'nume' => (string) $r['nume'], 'fel' => (string) $r['fel'], 'gazda' => (string) ($r['gazda'] ?? ''), 'camera' => (string) $r['camera'],
                'activa' => (int) $r['activa'] === 1, 'citita_la' => $r['citita_la'], 'reusita_la' => $r['reusita_la'], 'eroare' => $r['eroare'], 'evenimente' => (int) $r['evenimente']];
        }
        return $rez;
    }

    /**
     * Adaugă un calendar și îl citește pe loc. Aruncă EroareIcal la o adresă respinsă (nimic nu se salvează).
     * @return array{id:int, citire:array}
     */
    public function adauga(string $nume, string $url, string $camera): array
    {
        $nume = trim(preg_replace('~\s+~u', ' ', $nume) ?? '');
        if ($nume === '' || mb_strlen($nume) > 80) {
            throw new EroareIcal('adresa', 'nume');
        }
        if ($this->app->localDepozit()->camera($camera) === null) {
            throw new EroareIcal('adresa', 'camera');
        }
        if ((int) $this->app->baza()->valoare("SELECT COUNT(*) FROM {local_ical_surse} WHERE fel = 'ical'") >= self::SURSE_MAXIME) {
            throw new EroareIcal('adresa', 'limita');
        }
        $a = AdresaSigura::verifica($url, $this->rezolva);
        $id = $this->app->baza()->insereaza('local_ical_surse', ['nume' => $nume, 'fel' => 'ical', 'url' => $this->cutie()->cripteaza($a['url']),
            'gazda' => AdresaSigura::scurta($a['url']), 'camera' => $camera, 'activa' => 1, 'evenimente' => 0, 'creat_la' => $this->app->ceas()->moment()]);
        return ['id' => $id, 'citire' => $this->citesteUna($id)];
    }

    public function sterge(int $id): void
    {
        $this->app->baza()->tranzactie(function () use ($id): void {
            $this->app->baza()->executa('DELETE FROM {local_ical_nopti} WHERE sursa_id = ?', [$id]);
            $this->app->baza()->executa('DELETE FROM {local_ical_surse} WHERE id = ?', [$id]);
        });
        $this->app->localDupaScriere();
    }

    /** Citește un calendar acum (butonul „Citiți acum” și adăugarea). @return array{stare:string, evenimente?:int, eroare?:string} */
    public function citesteUna(int $id): array
    {
        $s = $this->app->baza()->rand("SELECT * FROM {local_ical_surse} WHERE id = ? AND fel = 'ical'", [$id]);
        return $s === null ? ['stare' => 'eroare', 'eroare' => 'adresa'] : $this->citeste($s);
    }

    /** Sarcina periodică: toate calendarele active, în ordinea celor citite de cel mai mult timp. */
    public function sarcina(): string
    {
        $surse = $this->app->baza()->toate("SELECT * FROM {local_ical_surse} WHERE fel = 'ical' AND activa = 1 ORDER BY COALESCE(citita_la, '1970-01-01 00:00:00'), id LIMIT " . self::SURSE_MAXIME);
        if ($surse === []) {
            return 'niciun calendar iCal';
        }
        $start = microtime(true);
        $n = ['citite' => 0, 'neschimbate' => 0, 'erori' => 0];
        foreach ($surse as $s) {
            // Cel mult 20 de secunde pe rulare; restul la rularea următoare (primele sunt cele citite de cel mai mult timp).
            if (microtime(true) - $start > 20.0) {
                break;
            }
            $r = $this->citeste($s);
            $n[$r['stare'] === 'eroare' ? 'erori' : ($r['stare'] === 'neschimbat' ? 'neschimbate' : 'citite')]++;
        }
        return "citite: {$n['citite']}, neschimbate: {$n['neschimbate']}, erori: {$n['erori']}";
    }

    /** @return array{stare:string, evenimente?:int, eroare?:string} stare: citit, neschimbat, eroare */
    private function citeste(array $s): array
    {
        $b = $this->app->baza();
        $acum = $this->app->ceas()->moment();
        $id = (int) $s['id'];
        try {
            $url = $this->cutie()->decripteaza((string) $s['url']);
            $antete = ['Accept' => 'text/calendar, text/plain;q=0.8, */*;q=0.5'];
            if (is_string($s['etag'] ?? null) && $s['etag'] !== '') {
                $antete['If-None-Match'] = (string) $s['etag'];
            }
            if (is_string($s['modificat'] ?? null) && $s['modificat'] !== '') {
                $antete['If-Modified-Since'] = (string) $s['modificat'];
            }
            $r = null;
            for ($i = 0; $i <= self::REDIRECTARI; $i++) {
                $adresa = AdresaSigura::verifica($url, $this->rezolva);
                $r = $this->http()->get($adresa, $antete);
                if (in_array($r['status'], [301, 302, 303, 307, 308], true) && is_string($r['antete']['location'] ?? null)) {
                    $url = self::absoluta($adresa['url'], $r['antete']['location']);
                    continue;
                }
                break;
            }
            if ($r === null || in_array($r['status'], [301, 302, 303, 307, 308], true)) {
                throw new EroareIcal('redirectari');
            }
            if ($r['status'] === 304) {
                $b->executa('UPDATE {local_ical_surse} SET citita_la = ?, reusita_la = ?, eroare = NULL WHERE id = ?', [$acum, $acum, $id]);
                return ['stare' => 'neschimbat', 'evenimente' => (int) $s['evenimente']];
            }
            if ($r['status'] !== 200) {
                throw new EroareIcal('http', 'HTTP ' . $r['status']);
            }
            $f = $this->app->ecOglinda()->fereastra();
            $perioade = Ical::citeste($r['corp'], $f['de'], $f['pana']);
        } catch (EroareIcal $e) {
            $b->executa('UPDATE {local_ical_surse} SET citita_la = ?, eroare = ? WHERE id = ?', [$acum, $e->cod, $id]);
            return ['stare' => 'eroare', 'eroare' => $e->cod];
        } catch (\UnexpectedValueException $e) {
            $cod = in_array($e->getMessage(), EroareIcal::CODURI, true) ? $e->getMessage() : 'forma';
            $b->executa('UPDATE {local_ical_surse} SET citita_la = ?, eroare = ? WHERE id = ?', [$acum, $cod, $id]);
            return ['stare' => 'eroare', 'eroare' => $cod];
        }
        $nopti = Ical::nopti($perioade, $f['de'], $f['pana']);
        $camera = (string) $s['camera'];
        $etag = isset($r['antete']['etag']) ? mb_substr((string) $r['antete']['etag'], 0, 191) : null;
        $modificat = isset($r['antete']['last-modified']) ? mb_substr((string) $r['antete']['last-modified'], 0, 64) : null;
        $b->tranzactie(function () use ($b, $id, $camera, $nopti, $acum, $etag, $modificat, $perioade): void {
            $b->executa('DELETE FROM {local_ical_nopti} WHERE sursa_id = ?', [$id]);
            foreach ($nopti as $zi => $numar) {
                $b->insereaza('local_ical_nopti', ['sursa_id' => $id, 'camera' => $camera, 'zi' => $zi, 'numar' => $numar]);
            }
            $b->executa('UPDATE {local_ical_surse} SET citita_la = ?, reusita_la = ?, eroare = NULL, etag = ?, modificat = ?, evenimente = ? WHERE id = ?',
                [$acum, $acum, $etag, $modificat, count($perioade), $id]);
        });
        $this->app->localDupaScriere();
        return ['stare' => 'citit', 'evenimente' => count($perioade)];
    }

    private function http(): IcalHttp
    {
        return $this->app->httpIcal ?? new IcalCurl(Aplicatie::agentExtern($this->app->config()->get('site_url', '')));
    }

    /** Adresa unei redirecționări, față de adresa cerută (relativă sau întreagă). */
    public static function absoluta(string $baza, string $location): string
    {
        $location = trim($location);
        if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $location)) {
            return $location;
        }
        $p = parse_url($baza);
        $origine = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($p['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origine . $location;
        }
        $dir = rtrim(dirname((string) ($p['path'] ?? '/')), '/');
        return $origine . $dir . '/' . $location;
    }

    // ---- trecerea din modul EasyCheck ----

    /**
     * Nopțile ocupate de rezervările din EasyCheck (altele decât cele ale site-ului), la momentul trecerii, ca surse
     * „preluate”, câte una pe cameră; gazda le șterge după ce pune calendarul iCal din EasyCheck.
     * @param array<string, array<string,int>> $nopti camera => [zi => unități ocupate]
     */
    public function preluare(array $nopti, string $nume): int
    {
        $b = $this->app->baza();
        $n = 0;
        $b->tranzactie(function () use ($b, $nopti, $nume, &$n): void {
            foreach ($b->toate("SELECT id FROM {local_ical_surse} WHERE fel = ?", [self::PRELUARE]) as $r) {
                $b->executa('DELETE FROM {local_ical_nopti} WHERE sursa_id = ?', [$r['id']]);
            }
            $b->executa('DELETE FROM {local_ical_surse} WHERE fel = ?', [self::PRELUARE]);
            foreach ($nopti as $camera => $zile) {
                $zile = array_filter($zile, static fn (int $x): bool => $x > 0);
                if ($zile === []) {
                    continue;
                }
                $id = $b->insereaza('local_ical_surse', ['nume' => mb_substr($nume, 0, 80), 'fel' => self::PRELUARE, 'url' => null, 'gazda' => null, 'camera' => (string) $camera,
                    'activa' => 1, 'evenimente' => count($zile), 'citita_la' => $this->app->ceas()->moment(), 'reusita_la' => $this->app->ceas()->moment(), 'creat_la' => $this->app->ceas()->moment()]);
                foreach ($zile as $zi => $x) {
                    $b->insereaza('local_ical_nopti', ['sursa_id' => $id, 'camera' => (string) $camera, 'zi' => $zi, 'numar' => $x]);
                    $n++;
                }
            }
        });
        return $n;
    }

    // ---- calendarul site-ului ----

    public function jeton(): string
    {
        $v = (int) $this->app->setari()->get(self::VERSIUNE_JETON, 1);
        return substr(hash_hmac('sha256', 'export:' . $v, $this->app->chei()->subcheie('ical')), 0, 32);
    }

    /** Adresa nouă a calendarului site-ului (cea veche nu mai merge). */
    public function schimbaJetonul(?int $utilizator = null): void
    {
        $this->app->setari()->pune(self::VERSIUNE_JETON, (int) $this->app->setari()->get(self::VERSIUNE_JETON, 1) + 1, $utilizator);
    }

    public function adresa(string $camera, ?int $unitate = null): string
    {
        return $this->app->url()->absolut('/calendar/' . $this->jeton() . '/' . $camera . ($unitate !== null ? '-' . $unitate : '') . '.ics');
    }

    /** Calendarul unei camere, sau null la un jeton greșit ori la o cameră necunoscută. */
    public function exporta(string $jeton, string $camera): ?string
    {
        if (!hash_equals($this->jeton(), $jeton)) {
            return null;
        }
        $d = $this->app->localDepozit();
        $cam = $d->camera($camera);
        if ($cam === null || !$cam['activa']) {
            return null;
        }
        $f = $this->app->ecOglinda()->fereastra();
        $o = $d->ocupare($f['de'], $f['pana']);
        $inchise = $d->inchise($f['de'], $f['pana']);
        $nopti = [];
        foreach (Depozit::zile($f['de'], $f['pana']) as $zi) {
            if (($o['site'][$camera][$zi] ?? 0) >= $cam['unitati'] || isset($inchise['*'][$zi]) || isset($inchise[$camera][$zi])) {
                $nopti[] = $zi;
            }
        }
        $p = $d->proprietate();
        $domeniu = (string) (parse_url($this->app->config()->origine(), PHP_URL_HOST) ?: 'cazo.local');
        return Ical::scrie($nopti, $camera, $domeniu, trim(($p['nume'] !== '' ? $p['nume'] . ': ' : '') . $cam['titlu']), 'Ocupat', $this->app->ceas()->acum());
    }

    /**
     * Adresele calendarului site-ului de pus pe platforme: una pe cameră la camerele cu o singură unitate și una
     * pe unitate la cele cu mai multe („Studio, unitatea 2” → `<camera>-2.ics`). Adresa pe cameră a unei camere cu mai
     * multe unități rămâne valabilă (calendarele puse deja pe platforme), dar nu se mai arată.
     * @return list<array{camera:string, titlu:string, unitate:?int, url:string}>
     */
    public function adrese(): array
    {
        $rez = [];
        foreach ($this->app->localDepozit()->camere() as $c) {
            if ($c['unitati'] <= 1) {
                $rez[] = ['camera' => $c['id'], 'titlu' => $c['titlu'], 'unitate' => null, 'url' => $this->adresa($c['id'])];
                continue;
            }
            for ($u = 1; $u <= $c['unitati']; $u++) {
                $rez[] = ['camera' => $c['id'], 'titlu' => $c['titlu'], 'unitate' => $u, 'url' => $this->adresa($c['id'], $u)];
            }
        }
        return $rez;
    }

    /**
     * Calendarul unei unități: nopțile rezervărilor repartizate pe ea și zilele închise ale camerei; null la un
     * jeton greșit, o cameră necunoscută sau cu o singură unitate, ori o unitate peste numărul camerei.
     */
    public function exportaUnitate(string $jeton, string $camera, int $unitate): ?string
    {
        if (!hash_equals($this->jeton(), $jeton)) {
            return null;
        }
        $d = $this->app->localDepozit();
        $cam = $d->camera($camera);
        if ($cam === null || !$cam['activa'] || $cam['unitati'] < 2 || $unitate < 1 || $unitate > $cam['unitati']) {
            return null;
        }
        // Doar citire: repartizarea se face la scriere (Aplicatie::localDupaScriere).
        $f = $this->app->ecOglinda()->fereastra();
        $ocupate = $d->noptiUnitate($camera, $unitate, $f['de'], $f['pana']);
        $inchise = $d->inchise($f['de'], $f['pana']);
        $nopti = [];
        foreach (Depozit::zile($f['de'], $f['pana']) as $zi) {
            if (isset($ocupate[$zi]) || isset($inchise['*'][$zi]) || isset($inchise[$camera][$zi])) {
                $nopti[] = $zi;
            }
        }
        $p = $d->proprietate();
        $domeniu = (string) (parse_url($this->app->config()->origine(), PHP_URL_HOST) ?: 'cazo.local');
        return Ical::scrie($nopti, $camera . '-' . $unitate, $domeniu, trim(($p['nume'] !== '' ? $p['nume'] . ': ' : '') . $cam['titlu'] . ' ' . $unitate), 'Ocupat', $this->app->ceas()->acum());
    }

    /** Ultimul moment de citire, pentru panou (ora României). */
    public static function cand(?string $moment, string $limba): string
    {
        return $moment === null ? '' : \Cazo\I18n\Format::moment($moment, $limba);
    }

    public static function vechi(?string $moment, Ceas $ceas, int $secunde): bool
    {
        return $moment === null || $ceas->acum() - Ceas::dinMoment($moment) > $secunde;
    }
}
