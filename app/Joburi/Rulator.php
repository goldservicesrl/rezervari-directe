<?php

declare(strict_types=1);

namespace Cazo\Joburi;

use Cazo\Baza\Zavor;
use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Setari;

defined('CAZO') or exit;

/**
 * Rulatorul comun al tuturor surselor de timp: cronul cPanel, cronul extern prin URL,
 * pseudo-cronul după răspuns, anunțurile și panoul. Rulează sub zăvorul `cron`, cu buget de timp,
 * în ordinea priorităților: sarcinile periodice și joburile din coadă se amestecă după prioritate.
 */
final class Rulator
{
    public const SURSE = ['cpanel', 'pseudo', 'anunt', 'panou', 'lant'];

    /** Bugetele implicite, în secunde. */
    public const BUGETE = ['cpanel' => 20, 'pseudo' => 8, 'anunt' => 15, 'panou' => 8, 'lant' => 20];

    /** Prioritatea joburilor după prefixul tipului (partea dinaintea primului „:”). */
    public const PRIORITATI_JOBURI = [
        'comanda' => 1,
        'cerere' => 2,
        'ec' => 3,
        'sincronizare' => 3,
        'email' => 4,
        'poze' => 5,
        'date_externe' => 6,
        'curatenie' => 7,
        'zilnic' => 8,
    ];

    /**
     * În modul de întreținere (de exemplu restaurarea unei copii) nu rulează nicio sarcină: joburile ar lucra pe o bază
     * în mijlocul schimbării.
     */
    public const DOAR_IN_INTRETINERE = [];

    public function __construct(
        private Coada $coada,
        private Planificator $planificator,
        private Zavor $zavor,
        private Setari $setari,
        private Ceas $ceas,
        private ?\Closure $inIntretinere = null,
        /** @var ?\Closure(array, string): void chemată după eliberarea zăvorului, cu raportul și sursa (lanțul pozelor) */
        private ?\Closure $dupaRulare = null,
    ) {
    }

    /** Bugetul pentru o sursă, ajustat la max_execution_time (cu 5 s rezervă). */
    public static function buget(string $sursa, ?int $maxExecutie = null): int
    {
        $b = self::BUGETE[$sursa] ?? 8;
        $max = $maxExecutie ?? (int) ini_get('max_execution_time');
        if ($max > 0) {
            $b = max(1, min($b, $max - 5));
        }
        return $b;
    }

    public static function prioritateJob(string $tip): int
    {
        $prefix = explode(':', $tip, 2)[0];
        return self::PRIORITATI_JOBURI[$prefix] ?? 5;
    }

    /**
     * @return array{rulat:bool, sarcini:int, joburi:int, esecuri:int, ramase:int, durata_ms:int}
     *   rulat = false când zăvorul `cron` era ocupat
     */
    public function ruleaza(string $sursa, ?float $bugetSecunde = null): array
    {
        if (!in_array($sursa, self::SURSE, true)) {
            throw new \InvalidArgumentException('Sursă de cron necunoscută: ' . $sursa);
        }
        $buget = $bugetSecunde ?? (float) self::buget($sursa);
        $start = hrtime(true);
        $limita = $start + (int) ($buget * 1e9);
        $raport = ['rulat' => false, 'sarcini' => 0, 'joburi' => 0, 'esecuri' => 0, 'ramase' => 0, 'durata_ms' => 0];

        [$luat, $r] = $this->zavor->cuZavor('cron', (int) ceil($buget) + 60, function () use ($sursa, $limita, $raport): array {
            $raport['rulat'] = true;
            // Câteva runde: o sarcină poate pune joburi noi, care se rulează în același buget.
            for ($runda = 0; $runda < 5; $runda++) {
                $agenda = $this->agenda();
                if ($agenda === []) {
                    break;
                }
                $facut = 0;
                foreach ($agenda as $element) {
                    if (hrtime(true) >= $limita) {
                        break 2;
                    }
                    if ($element['fel'] === 'sarcina') {
                        $ok = $this->planificator->ruleaza($element['nume'], $sursa, max(0.0, ($limita - hrtime(true)) / 1e9));
                        $raport['sarcini']++;
                    } else {
                        $job = $this->coada->preia($element['id']);
                        if ($job === null) {
                            continue;
                        }
                        $ok = $this->coada->executa($job);
                        $raport['joburi']++;
                    }
                    $facut++;
                    if (!$ok) {
                        $raport['esecuri']++;
                    }
                }
                if ($facut === 0) {
                    break;
                }
            }
            return $raport;
        });

        if ($luat) {
            $raport = $r;
            $raport['ramase'] = count($this->agenda());
            $raport['durata_ms'] = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->noteazaPauza($sursa);
            $this->setari->pune('cron.ultima', ['la' => $this->ceas->moment(), 'sursa' => $sursa] + $raport);
            if ($this->dupaRulare !== null) {
                try {
                    ($this->dupaRulare)($raport, $sursa);
                } catch (\Throwable) {
                }
            }
        }
        return $raport;
    }

    /** O pauză a sarcinilor de cel puțin atât (o oră, ca alerta `cron` din panou) se ține minte. */
    public const PAUZA = 3600;

    /**
     * Pauza dintre rularea de dinainte și cea de acum, când a ținut cel puțin PAUZA și n-a încheiat-o cronul din cPanel:
     * pe o găzduire fără cron, sarcinile rulează doar la vizite (și la lanțul pozelor), deci stau cât site-ul n-are
     * vizitatori. Se ține în `cron.pauza` {de_la, pana_la}; panoul o arată 24 de ore (alerta `cron_pauza`, „adăugați
     * cronul”). Pauza nu oprește nimic: plata online decide după o citire proaspătă din EasyCheck (ModPlata::motive).
     */
    private function noteazaPauza(string $sursa): void
    {
        $vechi = $this->setari->get('cron.ultima');
        if ($sursa === 'cpanel' || !is_array($vechi) || !is_string($vechi['la'] ?? null)) {
            return;
        }
        try {
            $de = Ceas::dinMoment($vechi['la']);
        } catch (\Throwable) {
            return;
        }
        if ($this->ceas->acum() - $de >= self::PAUZA) {
            $this->setari->pune('cron.pauza', ['de_la' => $vechi['la'], 'pana_la' => $this->ceas->moment()]);
        }
    }

    /** @return array<int,array> sarcinile și joburile scadente, în ordinea priorității și a termenului */
    private function agenda(): array
    {
        $agenda = [];
        $intretinere = $this->inIntretinere !== null && ($this->inIntretinere)() === true;
        foreach ($this->planificator->scadente() as $s) {
            if ($intretinere && !in_array($s['nume'], self::DOAR_IN_INTRETINERE, true)) {
                continue;
            }
            $agenda[] = ['fel' => 'sarcina', 'nume' => $s['nume'], 'prioritate' => $s['prioritate'], 'termen' => $s['termen'], 'id' => 0];
        }
        foreach ($intretinere ? [] : $this->coada->scadente(100) as $j) {
            $agenda[] = ['fel' => 'job', 'id' => (int) $j['id'], 'nume' => (string) $j['tip'], 'prioritate' => self::prioritateJob((string) $j['tip']), 'termen' => (string) $j['ruleaza_dupa']];
        }
        usort($agenda, fn (array $a, array $b): int => [$a['prioritate'], $a['termen'], $a['id']] <=> [$b['prioritate'], $b['termen'], $b['id']]);
        return $agenda;
    }
}
