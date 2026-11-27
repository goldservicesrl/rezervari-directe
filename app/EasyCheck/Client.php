<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

defined('CAZO') or exit;

/**
 * Clientul API-ului EasyCheck v1.
 *
 *  - baza fixă https://easycheck.ro/api/v1 (alta doar în mediul de test, spre serverele false);
 *  - cheia doar în Authorization: Bearer; niciodată antetul Origin; corpul trimis ≤ 16 KB;
 *  - timeouts: conectare 3 s, citiri 6 s, scrieri 12 s, 2 s în calea anunțurilor;
 *  - citirile trimit If-None-Match; scrierile poartă mereu Idempotency-Key și corpul exact primit (șir), ca o
 *    reluare să fie identică octet cu octet; la 409 idempotency_conflict cu in_lucru, reluare după Retry-After sau 2 s;
 *  - fiecare răspuns trece prin observatori (bugetul, licența) înainte de orice decizie;
 *  - în pauza cerută prin Retry-After, nicio cerere nu pleacă (EroareTransport, felul „pauza”).
 */
final class Client
{
    public const BAZA = 'https://easycheck.ro/api/v1';
    public const CORP_MAXIM = 16384;
    public const RASPUNS_MAXIM = 8 * 1024 * 1024;
    public const TIMEOUTS = ['conectare' => 3.0, 'citire' => 6.0, 'scriere' => 12.0, 'anunt' => 2.0];

    /** @var list<callable(string, string, RaspunsEc): void> */
    private array $observatori = [];

    /** @var \Closure(float): void */
    private \Closure $dormi;

    /**
     * @param \Closure(): ?string $cheie cititorul cheii (secretul `ec_cheie`), apelat la fiecare cerere
     * @param array<string,float> $timeouts
     */
    public function __construct(
        private Transport $transport,
        private \Closure $cheie,
        private ?Buget $buget = null,
        private string $baza = self::BAZA,
        private array $timeouts = self::TIMEOUTS,
        ?\Closure $dormi = null,
    ) {
        $this->baza = rtrim($baza, '/');
        $this->timeouts += self::TIMEOUTS;
        $this->dormi = $dormi ?? static function (float $s): void {
            usleep((int) ($s * 1_000_000));
        };
    }

    public function baza(): string
    {
        return $this->baza;
    }

    /** @param callable(string $metoda, string $cale, RaspunsEc $r): void $f */
    public function observa(callable $f): void
    {
        $this->observatori[] = $f;
    }

    /**
     * Citire cu ETag: întoarce 200 sau 304; orice altă stare aruncă EroareApi.
     * @param array<string,mixed> $query
     */
    public function citeste(string $cale, array $query = [], ?string $etag = null, bool $caleaAnuntului = false): RaspunsEc
    {
        $antete = [];
        if ($etag !== null && $etag !== '') {
            $antete['If-None-Match'] = $etag;
        }
        $t = $caleaAnuntului ? $this->timeouts['anunt'] : $this->timeouts['citire'];
        $r = $this->cere('GET', $cale, $query, $antete, null, min($this->timeouts['conectare'], $t), $t);
        if ($r->status !== 304 && !$r->reusit()) {
            throw new EroareApi($r, 'GET ' . $cale);
        }
        return $r;
    }

    /**
     * Mai multe citiri deodată (GET /total pentru cardurile căutării): în paralel când transportul o permite,
     * altfel una după alta. Fiecare răspuns trece prin buget și prin observatori, ca o citire obișnuită.
     * @param array<int|string, array{0:string, 1:array}> $cereri [calea, parametrii]
     * @return array<int|string, RaspunsEc|EroareApi|EroareTransport>
     */
    public function citesteMulte(array $cereri): array
    {
        $rez = [];
        $cheie = ($this->cheie)();
        $pauza = $this->buget?->pauza() ?? 0;
        if ($cheie === null || $cheie === '' || preg_match('~[\r\n\0]~', $cheie) || $pauza > 0) {
            foreach (array_keys($cereri) as $k) {
                $rez[$k] = $pauza > 0 ? new EroareTransport('EasyCheck a cerut o pauză de ' . $pauza . ' s.', 'pauza', $pauza) : new EroareTransport('Cheia EasyCheck lipsește.', 'fara_cheie');
            }
            return $rez;
        }
        if (!$this->transport instanceof TransportMultiplu) {
            foreach ($cereri as $k => [$cale, $query]) {
                try {
                    $rez[$k] = $this->citeste($cale, $query);
                } catch (EroareApi|EroareTransport $e) {
                    $rez[$k] = $e;
                }
            }
            return $rez;
        }
        $de = [];
        foreach ($cereri as $k => [$cale, $query]) {
            if (!preg_match('~^/[A-Za-z0-9_/\-]{1,128}$~', $cale)) {
                throw new \InvalidArgumentException('Cale API invalidă: ' . $cale);
            }
            $de[$k] = ['url' => $this->baza . $cale . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)),
                'antete' => ['Authorization' => 'Bearer ' . $cheie, 'Accept' => 'application/json']];
        }
        $t = $this->timeouts['citire'];
        foreach ($this->transport->trimiteMulte($de, ['conectare' => min($this->timeouts['conectare'], $t), 'total' => $t, 'max_octeti' => self::RASPUNS_MAXIM]) as $k => $r) {
            if ($r instanceof RaspunsEc) {
                $this->buget?->inregistreaza(false, $r);
                foreach ($this->observatori as $f) {
                    $f('GET', $cereri[$k][0], $r);
                }
                $rez[$k] = $r->reusit() || $r->status === 304 ? $r : new EroareApi($r, 'GET ' . $cereri[$k][0]);
            } else {
                $rez[$k] = $r;
            }
        }
        return $rez;
    }

    /**
     * Scriere: corpul se trimite exact cum vine (șirul păstrat în `corp_ec` la reluări), cu Idempotency-Key.
     * Întoarce răspunsul 2xx; orice altă stare aruncă EroareApi; lipsa răspunsului aruncă EroareTransport.
     */
    public function scrie(string $metoda, string $cale, ?string $corpJson, ?string $idempotency = null): RaspunsEc
    {
        if (!in_array($metoda, ['POST', 'PUT', 'DELETE'], true)) {
            throw new \InvalidArgumentException('Metodă de scriere necunoscută.');
        }
        if ($corpJson !== null && strlen($corpJson) > self::CORP_MAXIM) {
            throw new \LengthException('Corpul trimis spre EasyCheck depășește 16 KB.');
        }
        $antete = [];
        if ($corpJson !== null) {
            $antete['Content-Type'] = 'application/json';
        }
        if ($idempotency !== null) {
            if (!preg_match('~^[A-Za-z0-9\-_.:]{8,255}$~', $idempotency)) {
                throw new \InvalidArgumentException('Idempotency-Key invalidă.');
            }
            $antete['Idempotency-Key'] = $idempotency;
        }
        for ($incercare = 0; ; $incercare++) {
            $r = $this->cere($metoda, $cale, [], $antete, $corpJson, $this->timeouts['conectare'], $this->timeouts['scriere']);
            if ($r->reusit()) {
                return $r;
            }
            $inLucru = $r->status === 409 && $r->codEroare() === 'idempotency_conflict' && (($r->eroare()['detalii']['in_lucru'] ?? false) === true);
            if ($inLucru && $incercare < 2) {
                ($this->dormi)((float) min(5, $r->retryAfter() ?? 2));
                continue;
            }
            throw new EroareApi($r, $metoda . ' ' . $cale);
        }
    }

    /** Corpul JSON al unei scrieri, în forma trimisă (fără escapări inutile). */
    public static function json(array $corp): string
    {
        return json_encode($corp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Un UUID v4 pentru Idempotency-Key. */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function cere(string $metoda, string $cale, array $query, array $antete, ?string $corp, float $conectare, float $total): RaspunsEc
    {
        if (!preg_match('~^/[A-Za-z0-9_/\-]{1,128}$~', $cale)) {
            throw new \InvalidArgumentException('Cale API invalidă: ' . $cale);
        }
        $cheie = ($this->cheie)();
        if ($cheie === null || $cheie === '') {
            throw new EroareTransport('Cheia EasyCheck lipsește.', 'fara_cheie');
        }
        if (preg_match('~[\r\n\0]~', $cheie)) {
            throw new EroareTransport('Cheia EasyCheck are o formă greșită.', 'fara_cheie');
        }
        $pauza = $this->buget?->pauza() ?? 0;
        if ($pauza > 0) {
            throw new EroareTransport('EasyCheck a cerut o pauză de ' . $pauza . ' s.', 'pauza', $pauza);
        }
        $url = $this->baza . $cale . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $antete = ['Authorization' => 'Bearer ' . $cheie, 'Accept' => 'application/json'] + $antete;
        $r = $this->transport->trimite($metoda, $url, $antete, $corp, ['conectare' => $conectare, 'total' => $total, 'max_octeti' => self::RASPUNS_MAXIM]);
        $scriere = $metoda !== 'GET';
        $this->buget?->inregistreaza($scriere, $r);
        foreach ($this->observatori as $f) {
            $f($metoda, $cale, $r);
        }
        return $r;
    }
}
