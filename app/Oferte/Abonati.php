<?php

declare(strict_types=1);

namespace Cazo\Oferte;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;
use Cazo\Securitate\Cutie;

defined('CAZO') or exit;

/**
 * Abonații la oferte (observația 14 din 24.11.2026): oaspeții care au bifat acordul pentru noutăți și îl
 * păstrează. Lista stă într-un singur loc: cu cheia EasyCheck și `functii.abonati` pornit (fără modul local), în
 * EasyCheck (`GET /abonati`, citită la deschiderea paginii, cu un cache scurt, criptat); altfel, pe site (acordurile din comenzi).
 * Cu EasyCheck, acordul pleacă odată cu rezervarea sau cererea; acordurile de pe site care n-au ajuns încă acolo (adunate
 * înainte de cheie sau trimise fără funcție) urcă prin `POST /abonati/acorduri`, din cron, o singură dată fiecare.
 *
 * Dezabonarea (din panou sau din linkul semnat al fiecărei oferte) retrage acordurile adresei pe site, ține adresa în
 * `oferte_dezabonari` (o ofertă rămasă în coadă nu mai pleacă, iar lista EasyCheck o lasă deoparte până la un acord mai nou)
 * și, cu EasyCheck, trimite `POST /abonati/dezabonare`, reluat de cron până trece.
 */
final class Abonati
{
    public const CACHE_SECUNDE = 300;
    /** Lista abonaților în EasyCheck, pentru legătura din panou (modelul lui Adresa::EASYCHECK_SITE_WEB). */
    public const EASYCHECK_LISTA = 'https://easycheck.ro/host/properties.php';
    public const SETARE_EC = 'oferte.easycheck';
    public const SURSE = ['site', 'checkin'];
    /** Ultima comandă al cărei acord a urcat în EasyCheck. */
    public const SETARE_URCATE = 'oferte.urcate_pana_la';
    /** Câte acorduri urcă într-un apel; corpul rămâne sub limita de 16 KB a API-ului EasyCheck. */
    public const URCA_PE_APEL = 20;
    /** Reluările trimiterii unei dezabonări la EasyCheck: la 5 minute, apoi tot mai rar, cel mult o zi. */
    public const INCERCARI_EC = 12;

    public function __construct(private Aplicatie $app)
    {
    }

    /** Lista din EasyCheck face parte din listă: cheia EasyCheck salvată (fără modul local) și `functii.abonati` pornit. */
    public function cuEasyCheck(): bool
    {
        try {
            return !$this->app->modLocal() && $this->app->secrete()->citeste('ec_cheie') !== null && $this->app->stareSite()->functie('abonati');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Lista unită, ordonată după data acordului (cele mai noi întâi).
     * @return array{abonati: list<array{email:string, nume:string, limba:string, surse:list<string>, acord_la:string, local:bool}>, easycheck:bool, eroare_ec:bool, ec_la:?string}
     */
    public function lista(bool $proaspat = false): array
    {
        if (!$this->cuEasyCheck()) {
            $lista = array_values($this->locali());
            $ec = ['easycheck' => false, 'eroare_ec' => false, 'ec_la' => null, 'de_urcat' => 0];
        } else {
            // lista e cea din EasyCheck, fără unire cu acordurile de pe site; o dezabonare de pe site încă netrimisă o ascunde.
            $d = $this->dinEasyCheck($proaspat);
            $ec = ['easycheck' => true, 'eroare_ec' => $d['eroare'], 'ec_la' => $d['la'] !== null ? Ceas::formateaza($d['la']) : null, 'de_urcat' => count($this->deUrcat())];
            $dezabonati = $this->dezabonati();
            $lista = [];
            $vazute = [];
            foreach ($d['lista'] as $a) {
                $cheie = mb_strtolower($a['email'], 'UTF-8');
                $retras = $dezabonati[$this->app->comenzi()->emailIo($a['email'])] ?? null;
                if (isset($vazute[$cheie]) || ($retras !== null && $retras >= $a['acord_la'])) {
                    continue;
                }
                $vazute[$cheie] = true;
                $lista[] = ['email' => $a['email'], 'nume' => $a['nume'], 'limba' => $a['limba'], 'surse' => [$a['sursa']], 'acord_la' => $a['acord_la'], 'local' => false];
            }
        }
        usort($lista, static fn (array $a, array $b): int => [$b['acord_la'], $a['email']] <=> [$a['acord_la'], $b['email']]);
        return ['abonati' => $lista] + $ec;
    }

    /**
     * Acordurile de pe site care n-au urcat încă în EasyCheck: comenzile cu acordul păstrat, de după ultima urcată.
     * @return list<array{id:int, email:string, nume:string, limba:string, la:string}>
     */
    public function deUrcat(int $cate = 500): array
    {
        $dupa = (int) ($this->app->setari()->get(self::SETARE_URCATE) ?? 0);
        $r = [];
        foreach ($this->app->baza()->toate('SELECT * FROM {comenzi} WHERE id > ? AND marketing_acord = 1 AND marketing_retras_la IS NULL AND anonimizat_la IS NULL ORDER BY id LIMIT ' . max(1, $cate), [$dupa]) as $cm) {
            $o = $this->app->comenzi()->oaspete($cm);
            $email = trim((string) ($o['email'] ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $r[] = ['id' => (int) $cm['id'], 'email' => $email, 'nume' => mb_substr(trim((string) ($o['nume'] ?? '')), 0, 200), 'limba' => $cm['limba'] === 'en' ? 'en' : 'ro',
                'la' => (string) $cm['creat_la']];
        }
        return $r;
    }

    /**
     * Urcă în EasyCheck acordurile de pe site care nu sunt încă acolo (`POST /abonati/acorduri`, câte URCA_PE_APEL), apoi mută
     * semnul. Un acord trimis deja cu rezervarea ajunge a doua oară cu același moment, deci nu schimbă nimic acolo.
     * @return int câte acorduri au urcat
     */
    public function urca(): int
    {
        if (!$this->cuEasyCheck()) {
            return 0;
        }
        $n = 0;
        foreach (array_chunk($this->deUrcat(), self::URCA_PE_APEL) as $parte) {
            $acorduri = [];
            foreach ($parte as $x) {
                $nume = $this->app->modelVedere($x['limba'])->proprietate()['nume'];
                $acorduri[] = ['email' => $x['email'], 'nume' => $x['nume'], 'limba' => $x['limba'], 'la' => gmdate('Y-m-d\\TH:i:s\\Z', (int) strtotime($x['la'] . ' UTC')),
                    'text' => mb_substr($this->app->traducator($x['limba'])->t('comanda.noutati', ['nume' => $nume]), 0, 500)];
            }
            $ultim = (int) end($parte)['id'];
            try {
                $this->app->ecClient()->scrie('POST', '/abonati/acorduri', \Cazo\EasyCheck\Client::json(['acorduri' => $acorduri]), 'acorduri-' . $parte[0]['id'] . '-' . $ultim);
            } catch (\Throwable $e) {
                $this->app->jurnal()->avertisment('Acordurile urcate în EasyCheck', ['eroare' => mb_substr($e->getMessage(), 0, 200)]);
                break;
            }
            $this->app->setari()->pune(self::SETARE_URCATE, $ultim);
            $n += count($parte);
        }
        if ($n > 0) {
            $this->app->setari()->pune(self::SETARE_EC, null);
        }
        return $n;
    }

    /**
     * Acordurile păstrate pe site, câte unul pe adresă (cel mai nou: numele și limba lui).
     * @return array<string, array{email:string, nume:string, limba:string, surse:list<string>, acord_la:string, local:bool}>
     */
    public function locali(): array
    {
        $r = [];
        foreach ($this->app->baza()->toate('SELECT * FROM {comenzi} WHERE marketing_acord = 1 AND marketing_retras_la IS NULL AND anonimizat_la IS NULL ORDER BY creat_la, id') as $cm) {
            $o = $this->app->comenzi()->oaspete($cm);
            $email = trim((string) ($o['email'] ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $r[mb_strtolower($email, 'UTF-8')] = ['email' => $email, 'nume' => trim((string) ($o['nume'] ?? '')), 'limba' => $cm['limba'] === 'en' ? 'en' : 'ro',
                'surse' => ['site'], 'acord_la' => (string) $cm['creat_la'], 'local' => true];
        }
        return $r;
    }

    /**
     * Abonații din EasyCheck (`GET /abonati`), din cache cât e proaspăt (CACHE_SECUNDE). La o citire eșuată rămâne lista
     * din cache, cu `eroare`.
     * @return array{lista: list<array{email:string, nume:string, limba:string, sursa:string, acord_la:string}>, eroare:bool, la:?int}
     */
    public function dinEasyCheck(bool $proaspat = false): array
    {
        $acum = $this->app->ceas()->acum();
        $cache = $this->app->setari()->get(self::SETARE_EC);
        $vechi = null;
        if (is_array($cache) && is_int($cache['la'] ?? null) && is_string($cache['date'] ?? null)) {
            try {
                $vechi = json_decode($this->app->comenzi()->decripteaza($cache['date']), true, 8, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $vechi = null;
            }
            if (is_array($vechi) && !$proaspat && $acum - $cache['la'] < self::CACHE_SECUNDE && $acum >= $cache['la']) {
                return ['lista' => $vechi, 'eroare' => false, 'la' => $cache['la']];
            }
        }
        try {
            $j = $this->app->ecClient()->citeste('/abonati')->json() ?? [];
            $lista = self::curata(is_array($j['abonati'] ?? null) ? $j['abonati'] : []);
            $this->app->setari()->pune(self::SETARE_EC, ['la' => $acum, 'date' => $this->app->comenzi()->cripteaza(json_encode($lista, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))]);
            return ['lista' => $lista, 'eroare' => false, 'la' => $acum];
        } catch (\Throwable $e) {
            $this->app->jurnal()->avertisment('Abonații din EasyCheck', ['eroare' => mb_substr($e->getMessage(), 0, 200)]);
            return ['lista' => is_array($vechi) ? $vechi : [], 'eroare' => true, 'la' => is_array($vechi) ? (int) $cache['la'] : null];
        }
    }

    /**
     * Rândurile `GET /abonati` cu forma bună: adresa validă, limba ro sau en, sursa cunoscută, data acordului în UTC.
     * @return list<array{email:string, nume:string, limba:string, sursa:string, acord_la:string}>
     */
    public static function curata(array $abonati): array
    {
        $r = [];
        foreach ($abonati as $a) {
            $email = is_array($a) && is_string($a['email'] ?? null) ? trim($a['email']) : '';
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $la = is_string($a['acord_la'] ?? null) ? strtotime($a['acord_la']) : false;
            $r[] = ['email' => $email, 'nume' => is_string($a['nume'] ?? null) ? mb_substr(trim($a['nume']), 0, 191) : '',
                'limba' => ($a['limba'] ?? null) === 'en' ? 'en' : 'ro', 'sursa' => in_array($a['sursa'] ?? null, self::SURSE, true) ? (string) $a['sursa'] : 'checkin',
                'acord_la' => $la !== false ? Ceas::formateaza($la) : '1970-01-01 00:00:00'];
        }
        return $r;
    }

    /** Câți abonați are lista unită acum (din cache, pentru Acasă și pagina datelor oaspeților). */
    public function numar(): int
    {
        return count($this->lista()['abonati']);
    }

    /** @return array<string,string> indexul orb al adresei => momentul dezabonării */
    private function dezabonati(): array
    {
        return array_column($this->app->baza()->toate('SELECT email_io, la FROM {oferte_dezabonari}'), 'la', 'email_io');
    }

    /** Adresa s-a dezabonat după momentul dat (o ofertă pusă în coadă înainte nu mai pleacă). */
    public function dezabonatDupa(string $email, string $moment): bool
    {
        $la = $this->app->baza()->valoare('SELECT la FROM {oferte_dezabonari} WHERE email_io = ?', [$this->app->comenzi()->emailIo($email)]);
        return is_string($la) && $la >= $moment;
    }

    /**
     * Dezabonarea unei adrese: acordurile ei de pe site se retrag (cu rândul în jurnalul fiecărei comenzi), adresa intră în
     * `oferte_dezabonari`, iar cu EasyCheck pleacă și `POST /abonati/dezabonare` ($laEasyCheck: acum; altfel cronul).
     * $cine: `oaspete` (linkul, „Rezervarea mea”) sau `gazda` (panoul). Întoarce câte acorduri de pe site s-au retras.
     */
    public function dezaboneaza(string $email, string $cine, bool $laEasyCheck = true): int
    {
        $email = trim($email);
        $io = $this->app->comenzi()->emailIo($email);
        $acum = $this->app->ceas()->moment();
        $n = 0;
        foreach ($this->app->baza()->toate('SELECT id, stare FROM {comenzi} WHERE email_io = ? AND marketing_acord = 1 AND marketing_retras_la IS NULL', [$io]) as $cm) {
            if ($this->app->baza()->executa('UPDATE {comenzi} SET marketing_retras_la = ?, actualizat_la = ? WHERE id = ? AND marketing_retras_la IS NULL', [$acum, $acum, $cm['id']])->rowCount() === 1) {
                $this->app->masinaStari()->jurnal((int) $cm['id'], (string) $cm['stare'], (string) $cm['stare'], $cine, ['noutati' => $cine === 'gazda' ? 'retras_gazda' : 'retras']);
                $n++;
            }
        }
        $ec = $this->cuEasyCheck();
        $rand = ['la' => $acum, 'sursa' => $cine, 'email' => $ec ? $this->app->comenzi()->cripteaza($email) : null, 'ec_trimis_la' => null, 'incercari' => 0,
            'urmatoarea' => $ec ? $acum : null, 'eroare' => null];
        $id = $this->app->baza()->valoare('SELECT id FROM {oferte_dezabonari} WHERE email_io = ?', [$io]);
        if ($id === null) {
            try {
                $id = $this->app->baza()->insereaza('oferte_dezabonari', ['email_io' => $io] + $rand);
            } catch (\PDOException $e) {
                if (!$this->app->baza()->esteDuplicat($e)) {
                    throw $e;
                }
                $id = $this->app->baza()->valoare('SELECT id FROM {oferte_dezabonari} WHERE email_io = ?', [$io]);
            }
        }
        $this->app->baza()->executa('UPDATE {oferte_dezabonari} SET la = ?, sursa = ?, email = ?, ec_trimis_la = NULL, incercari = 0, urmatoarea = ?, eroare = NULL WHERE id = ?',
            [$acum, $cine, $rand['email'], $rand['urmatoarea'], $id]);
        if ($ec && $laEasyCheck) {
            $this->trimiteLaEasyCheck((int) $id);
        }
        return $n;
    }

    /** O dezabonare trimisă la EasyCheck; la reușită adresa criptată se șterge. Întoarce true când EasyCheck a primit-o. */
    public function trimiteLaEasyCheck(int $id): bool
    {
        $d = $this->app->baza()->rand('SELECT * FROM {oferte_dezabonari} WHERE id = ? AND email IS NOT NULL AND ec_trimis_la IS NULL', [$id]);
        if ($d === null) {
            return false;
        }
        try {
            $email = $this->app->comenzi()->decripteaza((string) $d['email']);
            $this->app->ecClient()->scrie('POST', '/abonati/dezabonare', \Cazo\EasyCheck\Client::json(['email' => $email]), \Cazo\EasyCheck\Client::uuid());
            $this->app->baza()->executa('UPDATE {oferte_dezabonari} SET ec_trimis_la = ?, email = NULL, urmatoarea = NULL, eroare = NULL WHERE id = ?', [$this->app->ceas()->moment(), $id]);
            return true;
        } catch (\Throwable $e) {
            $incercari = (int) $d['incercari'] + 1;
            $gata = $incercari >= self::INCERCARI_EC;
            $this->app->baza()->executa('UPDATE {oferte_dezabonari} SET incercari = ?, urmatoarea = ?, eroare = ? WHERE id = ?',
                [$incercari, $gata ? null : $this->app->ceas()->moment(min(86400, 300 * 2 ** ($incercari - 1))), mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 190), $id]);
            $this->app->jurnal()->avertisment('Dezabonarea netrimisă la EasyCheck', ['id' => $id, 'incercari' => $incercari]);
            return false;
        }
    }

    public function areDeTrimis(): bool
    {
        try {
            return $this->app->baza()->valoare('SELECT 1 FROM {oferte_dezabonari} WHERE email IS NOT NULL AND ec_trimis_la IS NULL AND urmatoarea IS NOT NULL LIMIT 1') !== null
                || ($this->cuEasyCheck() && $this->deUrcat(1) !== []);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Sarcina cronului: dezabonările scadente, trimise la EasyCheck (doar cât lista EasyCheck face parte din listă). */
    public function sarcina(): string
    {
        if (!$this->cuEasyCheck()) {
            return 'fără lista EasyCheck';
        }
        $urcate = $this->urca();
        $ok = 0;
        $ids = $this->app->baza()->executa('SELECT id FROM {oferte_dezabonari} WHERE email IS NOT NULL AND ec_trimis_la IS NULL AND urmatoarea IS NOT NULL AND urmatoarea <= ? ORDER BY id LIMIT 20',
            [$this->app->ceas()->moment()])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $ok += $this->trimiteLaEasyCheck((int) $id) ? 1 : 0;
        }
        return 'acorduri urcate: ' . $urcate . '; dezabonări trimise: ' . $ok . ' din ' . count($ids);
    }

    // ---- linkul semnat de dezabonare ----

    private function cutie(): Cutie
    {
        return new Cutie($this->app->chei()->subcheie('oferte_dezabonare'));
    }

    /** Jetonul linkului de dezabonare al unei adrese: adresa criptată și autentificată (fără bază, linkul nu expiră). */
    public function jeton(string $email): string
    {
        return substr($this->cutie()->cripteaza(mb_strtolower(trim($email), 'UTF-8')), 3);
    }

    /** Adresa din jeton, sau null pentru un jeton schimbat ori străin. */
    public function dinJeton(string $jeton): ?string
    {
        if (preg_match('~^[A-Za-z0-9_-]{40,460}$~', $jeton) !== 1) {
            return null;
        }
        try {
            $email = $this->cutie()->decripteaza('v1.' . $jeton);
        } catch (\Throwable) {
            return null;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /** Adresa publică a linkului de dezabonare, în limba abonatului. */
    public function link(string $email, string $limba): string
    {
        return $this->app->url()->absolut('/dezabonare/' . $this->jeton($email), $limba);
    }
}
