<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;
use Cazo\Securitate\Limitator;

defined('CAZO') or exit;

/**
 * Bugetul API, o resursă protejată: oglinda locală a antetelor RateLimit-* (tabelul `ec_buget`,
 * câte un rând pentru citiri și pentru scrieri) și pauza cerută prin Retry-After (rândul `pauza`).
 *
 *  - 10 scrieri pe minut rămân mereu pentru POST /rezervari, POST /cereri și anulări (scrierile critice);
 *    celelalte scrieri (PUT /webhook, proba) trec doar peste această rezervă;
 *  - citirile de sincronizare se amână când rămân sub 30 de citiri în fereastră;
 *  - GET /total cerut de vizitatori: cel mult 40 pe minut pe site și 6 pe minut pe IP/24;
 *  - la 429 sau 503, tot clientul respectă Retry-After, fără să blocheze paginile servite din oglindă.
 */
final class Buget
{
    public const REZERVA_SCRIERI = 10;
    public const PRAG_CITIRI_SINCRONIZARE = 30;
    public const TOTAL_PE_SITE = 40;
    public const TOTAL_PE_IP = 6;

    public function __construct(private Baza $baza, private Ceas $ceas, private ?Limitator $limitator = null)
    {
    }

    /** Secundele de pauză rămase (Retry-After), 0 fără pauză. */
    public function pauza(): int
    {
        $p = $this->baza->valoare("SELECT pauza_pana FROM {ec_buget} WHERE fereastra = 'pauza'");
        if ($p === null) {
            return 0;
        }
        return max(0, Ceas::dinMoment((string) $p) - $this->ceas->acum());
    }

    public function puneInPauza(int $secunde): void
    {
        $secunde = max(1, min(3600, $secunde));
        $pana = $this->ceas->moment($secunde);
        $actual = $this->baza->valoare("SELECT pauza_pana FROM {ec_buget} WHERE fereastra = 'pauza'");
        if ($actual !== null && (string) $actual >= $pana) {
            return;
        }
        $this->baza->upsert('ec_buget', ['fereastra' => 'pauza', 'citiri' => 0, 'scrieri' => 0, 'ramase' => null, 'reset_la' => null, 'pauza_pana' => $pana],
            ['fereastra'], ['pauza_pana']);
    }

    /** Ridică pauza (o cheie nouă, verificată, după pauza pusă de 401-ul celei vechi). */
    public function ridicaPauza(): void
    {
        $this->baza->executa("UPDATE {ec_buget} SET pauza_pana = NULL WHERE fereastra = 'pauza'");
    }

    /** Oglinda antetelor după fiecare răspuns; 429 și 503 pun pauza din Retry-After (implicit 60 s). */
    public function inregistreaza(bool $scriere, RaspunsEc $r): void
    {
        $fereastra = $scriere ? 'scriere' : 'citire';
        $rl = $r->rateLimit();
        $acum = $this->ceas->moment();
        $rand = $this->baza->rand('SELECT reset_la FROM {ec_buget} WHERE fereastra = ?', [$fereastra]);
        $fereastraNoua = $rand === null || $rand['reset_la'] === null || (string) $rand['reset_la'] <= $acum;
        $resetLa = $rl['reset'] !== null ? $this->ceas->moment(max(1, $rl['reset'])) : ($fereastraNoua ? $this->ceas->moment(60) : (string) $rand['reset_la']);
        $col = $scriere ? 'scrieri' : 'citiri';
        $this->baza->upsert('ec_buget', [
            'fereastra' => $fereastra,
            'citiri' => $scriere ? 0 : 1,
            'scrieri' => $scriere ? 1 : 0,
            'ramase' => $rl['ramase'],
            'reset_la' => $resetLa,
            'pauza_pana' => null,
        ], ['fereastra'], [
            $col => ['expr' => $fereastraNoua ? '1' : $this->baza->dialect()->citeaza($col) . ' + 1'],
            'ramase' => $rl['ramase'] === null ? ['expr' => $this->baza->dialect()->citeaza('ramase')] : 'nou',
            'reset_la',
        ]);
        if ($r->status === 429 || $r->status === 503) {
            $this->puneInPauza($r->retryAfter() ?? 60);
        }
    }

    /** @return array{ramase:?int, activa:bool} cele rămase în fereastra curentă (null = necunoscut) */
    private function fereastra(string $fel): array
    {
        $r = $this->baza->rand('SELECT ramase, reset_la FROM {ec_buget} WHERE fereastra = ?', [$fel]);
        if ($r === null || $r['reset_la'] === null || (string) $r['reset_la'] <= $this->ceas->moment()) {
            return ['ramase' => null, 'activa' => false];
        }
        return ['ramase' => $r['ramase'] === null ? null : (int) $r['ramase'], 'activa' => true];
    }

    /** Citirile periodice și cele cerute de anunțuri trec doar cu cel puțin 30 de citiri rămase. */
    public function permiteSincronizare(): bool
    {
        if ($this->pauza() > 0) {
            return false;
        }
        $f = $this->fereastra('citire');
        return $f['ramase'] === null || $f['ramase'] >= self::PRAG_CITIRI_SINCRONIZARE;
    }

    /** Scrierile necritice (PUT /webhook, proba) lasă loc celor 10 scrieri rezervate. */
    public function permiteScriereNecritica(): bool
    {
        if ($this->pauza() > 0) {
            return false;
        }
        $f = $this->fereastra('scriere');
        return $f['ramase'] === null || $f['ramase'] > self::REZERVA_SCRIERI;
    }

    /** GET /total pornit de un vizitator: 40 pe minut pe site, 6 pe minut pe IP/24 (cardurile arată atunci „Vedeți totalul”). */
    public function permiteTotalVizitator(string $ip24): bool
    {
        if ($this->limitator === null || $this->pauza() > 0) {
            return false;
        }
        return $this->limitator->incearca('ec_total:ip:' . $ip24, self::TOTAL_PE_IP, 60)
            && $this->limitator->incearca('ec_total:site', self::TOTAL_PE_SITE, 60);
    }

    /** Starea pentru panou (Sistem › bugetul API). */
    public function stare(): array
    {
        $r = [];
        foreach ($this->baza->toate('SELECT * FROM {ec_buget}') as $rand) {
            $r[$rand['fereastra']] = $rand;
        }
        return $r + ['pauza_secunde' => $this->pauza()];
    }
}
