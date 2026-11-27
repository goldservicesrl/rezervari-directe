<?php

declare(strict_types=1);

namespace Cazo\Joburi;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Jurnal;

defined('CAZO') or exit;

/**
 * Coada de joburi, în tabelul `joburi`.
 *
 * Coalescența: un job cu `cheie_unica` existentă nu se dublează (zece anunțuri = un job). Dacă jobul existent
 * rulează chiar atunci, punerea îl „rearmează” (blocat_pana = NULL): la final, ștergerea lui condiționată
 * nu mai potrivește, iar jobul rulează încă o dată, deci evenimentul venit în timpul rulării nu se pierde.
 *
 * Preluarea: UPDATE ... WHERE blocat_pana e liber sau expirat; un singur proces câștigă jobul.
 * Eșecul: încercări crescute, reluare la 1, 5, 30 de minute, 2 h, 6 h; după max_incercari (implicit 6:
 * prima rulare plus cele 5 reluări), jobul rămâne în tabel, oprit, pentru panou.
 */
final class Coada
{
    public const REINCERCARI = [60, 300, 1800, 7200, 21600];

    /** Prima rulare plus câte o reluare pentru fiecare pauză din REINCERCARI. */
    public const MAX_INCERCARI = 6;

    public const BLOCARE_SECUNDE = 300;

    /** @var array<string,callable(array,self):void> */
    private array $executanti = [];

    public function __construct(private Baza $baza, private Ceas $ceas, private ?Jurnal $jurnal = null)
    {
    }

    public function inregistreaza(string $tip, callable $executant): void
    {
        $this->executanti[$tip] = $executant;
    }

    public function areExecutant(string $tip): bool
    {
        return isset($this->executanti[$tip]);
    }

    /**
     * Pune un job în coadă. Întoarce id-ul nou sau null când s-a contopit cu un job existent.
     */
    public function pune(string $tip, array $date = [], ?string $cheieUnica = null, int $intarziere = 0, int $maxIncercari = self::MAX_INCERCARI): ?int
    {
        if (!preg_match('~^[a-z0-9_:.\-]{1,64}$~', $tip)) {
            throw new \InvalidArgumentException('Tip de job invalid.');
        }
        $rand = [
            'tip' => $tip,
            'cheie_unica' => $cheieUnica,
            'date' => json_encode($date, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'ruleaza_dupa' => $this->ceas->moment(max(0, $intarziere)),
            'incercari' => 0,
            'max_incercari' => $maxIncercari,
            'creat_la' => $this->ceas->moment(),
        ];
        try {
            return $this->baza->insereaza('joburi', $rand);
        } catch (\PDOException $e) {
            if ($cheieUnica === null || !$this->baza->esteDuplicat($e)) {
                throw $e;
            }
        }
        // Contopirea: datele noi înlocuiesc datele vechi, termenul rămâne cel mai apropiat,
        // iar un job oprit după prea multe eșecuri pornește din nou.
        $this->baza->executa(
            'UPDATE {joburi} SET date = ?, blocat_pana = NULL, eroare = NULL, incercari = 0,
                ruleaza_dupa = CASE WHEN ruleaza_dupa < ? THEN ruleaza_dupa ELSE ? END
             WHERE cheie_unica = ?',
            [$rand['date'], $rand['ruleaza_dupa'], $rand['ruleaza_dupa'], $cheieUnica]
        );
        return null;
    }

    /**
     * Joburile scadente, nepreluate, în ordinea termenului.
     * @return array<int,array>
     */
    public function scadente(int $limita = 50): array
    {
        $acum = $this->ceas->moment();
        return $this->baza->toate(
            'SELECT id, tip, ruleaza_dupa FROM {joburi}
             WHERE ruleaza_dupa <= ? AND incercari < max_incercari AND (blocat_pana IS NULL OR blocat_pana <= ?)
             ORDER BY ruleaza_dupa, id LIMIT ' . max(1, min(500, $limita)),
            [$acum, $acum]
        );
    }

    /** Preia jobul; întoarce rândul complet (cu momentul preluării) sau null dacă l-a luat alt proces. */
    public function preia(int $id): ?array
    {
        $acum = $this->ceas->moment();
        $blocat = $this->ceas->moment(self::BLOCARE_SECUNDE);
        $n = $this->baza->executa(
            'UPDATE {joburi} SET blocat_pana = ? WHERE id = ? AND incercari < max_incercari AND ruleaza_dupa <= ? AND (blocat_pana IS NULL OR blocat_pana <= ?)',
            [$blocat, $id, $acum, $acum]
        )->rowCount();
        if ($n !== 1) {
            return null;
        }
        $rand = $this->baza->rand('SELECT * FROM {joburi} WHERE id = ?', [$id]);
        return $rand !== null && $rand['blocat_pana'] === $blocat ? $rand : null;
    }

    /** Rulează un job preluat; întoarce true la reușită. */
    public function executa(array $job): bool
    {
        $tip = (string) $job['tip'];
        try {
            if (!isset($this->executanti[$tip])) {
                throw new \RuntimeException('Tip de job fără executant: ' . $tip);
            }
            $date = json_decode((string) $job['date'], true, 64, JSON_THROW_ON_ERROR);
            ($this->executanti[$tip])(is_array($date) ? $date : [], $this);
        } catch (\Throwable $e) {
            $this->esec($job, $e);
            return false;
        }
        // Ștergerea e condiționată de preluare: un job rearmat în timpul rulării rămâne și rulează din nou.
        $this->baza->executa('DELETE FROM {joburi} WHERE id = ? AND blocat_pana = ?', [$job['id'], $job['blocat_pana']]);
        return true;
    }

    /** Preia și rulează un job scadent; întoarce null când coada e goală. */
    public function ruleazaUnul(): ?bool
    {
        foreach ($this->scadente(20) as $c) {
            $job = $this->preia((int) $c['id']);
            if ($job !== null) {
                return $this->executa($job);
            }
        }
        return null;
    }

    private function esec(array $job, \Throwable $e): void
    {
        $incercari = (int) $job['incercari'] + 1;
        $pauza = self::REINCERCARI[min($incercari - 1, count(self::REINCERCARI) - 1)];
        $mesaj = mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 480);
        $this->baza->executa(
            'UPDATE {joburi} SET incercari = ?, ruleaza_dupa = ?, blocat_pana = NULL, eroare = ? WHERE id = ? AND blocat_pana = ?',
            [$incercari, $this->ceas->moment($pauza), \Cazo\Nucleu\Jurnal::mascheaza($mesaj), $job['id'], $job['blocat_pana']]
        );
        $this->jurnal?->avertisment('Job eșuat', ['id' => (int) $job['id'], 'tip' => $job['tip'], 'incercari' => $incercari, 'eroare' => $mesaj]);
    }

    /** Joburile oprite după prea multe eșecuri (pentru panou). */
    public function oprite(): array
    {
        return $this->baza->toate('SELECT id, tip, incercari, eroare, ruleaza_dupa FROM {joburi} WHERE incercari >= max_incercari ORDER BY id');
    }

    public function numar(): int
    {
        return (int) $this->baza->valoare('SELECT COUNT(*) FROM {joburi}');
    }
}
