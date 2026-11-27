<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Fereastra plății cu reținere: plata, reținerea și răspunsul EasyCheck se rezolvă în cel mult
 * 10 minute de la plată. Până atunci, trimiterea spre EasyCheck se reia cu aceeași cheie, iar captura urmează imediat
 * după 201. După fereastră, nimic nu se mai capturează: comanda e refuzată, autorizarea se eliberează (oaspetele primește
 * e-mailul), iar o rezervare creată totuși în EasyCheck (un răspuns pierdut) se caută și se anulează automat, cu alerta
 * gazdei. La fel când captura nu mai e posibilă (banca a eliberat suma): rezervarea creată se anulează în EasyCheck.
 * Cererile cu plata la sosire nu au bani blocați, deci fereastra nu li se aplică.
 *
 * `comenzi.ec_orfana`: `de_cautat` (rezervarea poate exista; se caută după `ref_site` până la `ec_orfana_pana`),
 * `de_anulat` (găsită sau știută, se anulează), `anulata`, `negasita`. După `ec_orfana_pana` (o zi), o rezervare tot
 * neanulată sau o căutare tot nelămurită aduce alerta gazdei (o dată, vizibilă în panou), iar reluările se răresc la
 * REIA_RAR; o căutare încheiată fără rezultat (`negasita`) aduce și ea alerta: gazda verifică în EasyCheck.
 */
final class Retinere
{
    public const FEREASTRA = 600;
    /** Cât se caută o rezervare creată după fereastră (o cerere aflată încă în lucru la EasyCheck). */
    public const CAUTARE = 86400;
    /** Pauza dintre căutări și dintre anulările reluate în EasyCheck. */
    public const REIA = 1800;
    /** Pauza reluărilor după prima zi, odată cu alerta gazdei. */
    public const REIA_RAR = 6 * 3600;

    public function __construct(private Aplicatie $app)
    {
    }

    /** Sfârșitul ferestrei, pentru o comandă cu suma doar autorizată; null când plata s-a încasat pe loc sau lipsește. */
    public function termen(array $c): ?int
    {
        if (($c['fel'] ?? null) !== 'card' || ($c['platita_la'] ?? null) === null || $this->app->plataOnline()->autorizata((int) $c['id']) === null) {
            return null;
        }
        return Ceas::dinMoment((string) $c['platita_la']) + self::FEREASTRA;
    }

    public function depasita(array $c): bool
    {
        $t = $this->termen($c);
        return $t !== null && $this->app->ceas()->acum() >= $t;
    }

    /**
     * Fereastra a trecut înainte de confirmare: comanda e refuzată (`de_rambursat`), autorizarea se eliberează, oaspetele
     * primește e-mailul; după o trimitere, rezervarea poate exista în EasyCheck, deci se caută și se anulează.
     */
    public function expira(array $c, string $din, string $actor): string
    {
        $id = (int) $c['id'];
        $trimisa = ($c['trimisa_la'] ?? null) !== null || ($c['ec_rezervare'] ?? null) !== null;
        $orfana = ($c['ec_rezervare'] ?? null) !== null ? 'de_anulat' : ($trimisa ? 'de_cautat' : null);
        if (!$this->app->masinaStari()->trece($id, 'card', $din, 'de_rambursat', $actor, ['blocat_pana' => null, 'urmatoarea_incercare' => null, 'eroare_cod' => 'fereastra',
            'motiv_rambursare' => 'fereastra', 'ec_orfana' => $orfana, 'ec_orfana_pana' => $orfana !== null ? $this->app->ceas()->moment(self::CAUTARE) : null], ['fereastra' => self::FEREASTRA])) {
            return (string) $this->app->comenzi()->dupaId($id)['stare'];
        }
        $this->app->plataOnline()->dupaRefuz($id);
        if ($orfana !== null) {
            $this->orfana($id);
        }
        return (string) $this->app->comenzi()->dupaId($id)['stare'];
    }

    /**
     * Captura nu mai e posibilă după 201 (banca a eliberat suma) sau fereastra a trecut: rezervarea creată se anulează în
     * EasyCheck, autorizarea se eliberează, oaspetele primește e-mailul, gazda alerta.
     */
    public function capturaImposibila(array $c, string $motiv, string $actor, string $lacat): string
    {
        $id = (int) $c['id'];
        // Doar cel care ține lacătul capturii (PlataOnline::captureaza) refuză comanda: nicio captură nu e în drum.
        if (!$this->app->masinaStari()->trece($id, 'card', 'de_capturat', 'de_rambursat', $actor, ['blocat_pana' => null, 'urmatoarea_incercare' => null, 'eroare_cod' => $motiv,
            'motiv_rambursare' => $motiv, 'ec_orfana' => 'de_anulat', 'ec_orfana_pana' => $this->app->ceas()->moment(self::CAUTARE)], ['captura' => $motiv], $lacat)) {
            return (string) $this->app->comenzi()->dupaId($id)['stare'];
        }
        $this->app->plataOnline()->dupaRefuz($id);
        $this->orfana($id);
        return (string) $this->app->comenzi()->dupaId($id)['stare'];
    }

    /** Rezervarea EasyCheck rămasă fără plată: căutată (după `ref_site`), apoi anulată, cu alerta gazdei. */
    public function orfana(int $id): void
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || !in_array($c['ec_orfana'] ?? null, ['de_cautat', 'de_anulat'], true)) {
            return;
        }
        $b = $this->app->baza();
        if ($c['ec_orfana'] === 'de_cautat') {
            try {
                $r = $this->app->recuperare()->cauta((string) $c['ref'], Ceas::dinMoment((string) $c['creat_la']) - 60);
            } catch (EroareApi|EroareTransport) {
                $r = ['gasita' => null, 'completa' => false];
            }
            if ($r['gasita'] === null) {
                // Nimic încă: căutarea continuă până la `ec_orfana_pana` (o cerere poate fi încă în lucru la EasyCheck). După
                // termen, gazda verifică în EasyCheck: căutarea încheiată fără rezultat, sau una care tot nu se poate face.
                if ($this->dupaTermen($c)) {
                    if ($r['completa']) {
                        $b->executa("UPDATE {comenzi} SET ec_orfana = 'negasita' WHERE id = ? AND ec_orfana = 'de_cautat'", [$id]);
                    }
                    $this->alertaOrfana($c, null);
                }
                return;
            }
            $b->executa("UPDATE {comenzi} SET ec_orfana = 'de_anulat', ec_rezervare = ? WHERE id = ? AND ec_orfana = 'de_cautat'", [$r['gasita'], $id]);
            $c = $this->app->comenzi()->dupaId($id);
        }
        $rez = (string) ($c['ec_rezervare'] ?? '');
        if (!preg_match('~^rez_[0-9A-Za-z]{1,64}$~', $rez)) {
            return;
        }
        // cu rapoartele plăților pornite, EasyCheck află întâi că suma s-a eliberat (`incasare_anulata`), apoi anularea,
        // ca rezervarea anulată să nu-i ceară gazdei o restituire. Ce nu pleacă acum pleacă din cron.
        $this->app->raportPlati()->trimiteAcum($id);
        try {
            $this->app->ecClient()->scrie('POST', '/rezervari/' . $rez . '/anuleaza', null, 'ret-' . $c['ref']);
        } catch (EroareApi $e) {
            $anulata = false;
            if ($e->raspuns->status === 409) {
                try {
                    $anulata = ($this->app->ecClient()->citeste('/rezervari/' . $rez)->json()['stare'] ?? null) === 'anulata';
                } catch (EroareApi|EroareTransport) {
                    $anulata = false;
                }
            }
            if (!$anulata) {
                $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], 'sistem', ['ec_orfana' => 'amanata', 'cod' => $e->cod ?? 'http_' . $e->raspuns->status]);
                if ($this->dupaTermen($c)) {
                    $this->alertaOrfana($c, $rez);
                }
                return;
            }
        } catch (EroareTransport $e) {
            $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], 'sistem', ['ec_orfana' => 'amanata', 'cod' => $e->felul]);
            if ($this->dupaTermen($c)) {
                $this->alertaOrfana($c, $rez);
            }
            return;
        }
        if ($b->executa("UPDATE {comenzi} SET ec_orfana = 'anulata' WHERE id = ? AND ec_orfana = 'de_anulat'", [$id])->rowCount() === 1) {
            $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], 'sistem', ['ec_orfana' => 'anulata', 'rezervare' => $rez]);
            $this->app->alertaGazda($c['motiv_rambursare'] === 'fereastra' ? 'alerta_fereastra' : 'alerta_captura',
                ['ref' => (string) $c['ref'], 'sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare']], 'orfana:' . $c['ref'], 30 * 86400);
            // La captura imposibilă, e-mailul oaspetelui („am anulat rezervarea”) pleacă abia acum; după e-mailul de rezervă
            // (o zi fără anulare), oaspetele primește confirmarea anulării promisă acolo.
            if ($c['motiv_rambursare'] === 'captura' && $c['email_refuz_la'] !== null) {
                $this->app->plataOnline()->anunta($id, 'rezervare_anulata');
            } else {
                $this->app->plataOnline()->anuntaRefuzul($id);
            }
        }
    }

    /** Termenul căutării și al anulării automate (`ec_orfana_pana`, o zi) a trecut. */
    private function dupaTermen(array $c): bool
    {
        return ($c['ec_orfana_pana'] ?? null) !== null && $this->app->ceas()->acum() >= Ceas::dinMoment((string) $c['ec_orfana_pana']);
    }

    /**
     * Rezervarea rămasă fără plată tot neanulată după o zi (cu id-ul ei) sau tot negăsită: gazda o anulează din EasyCheck,
     * ca nopțile să fie din nou libere. O singură alertă pe comandă (și în panou); reluările continuă, rar.
     */
    private function alertaOrfana(array $c, ?string $rez): void
    {
        if (!$this->app->limitator()->incearca('orfana_alerta:' . $c['id'], 1, 30 * 86400)) {
            return;
        }
        $date = ['ref' => (string) $c['ref'], 'sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare']];
        $rez !== null
            ? $this->app->alertaGazda('alerta_orfana', $date + ['rez' => $rez], 'orfana_esec:' . $c['ref'], 30 * 86400)
            : $this->app->alertaGazda('alerta_orfana_negasita', $date, 'orfana_negasita:' . $c['ref'], 30 * 86400);
        // Oaspetele așteaptă e-mailul promis pe pagină: după o zi primește starea adevărată (banii eliberați sau returnați),
        // iar anularea rezervării i-o confirmăm separat, când se face.
        $this->app->plataOnline()->anuntaRefuzul((int) $c['id'], true);
    }

    /**
     * Gazda a anulat în EasyCheck rezervarea unei comenzi `de_capturat` (anunțul sau lista): nimic nu se mai capturează,
     * comanda se refuză, iar suma se eliberează. Sub lacătul capturii: cât o captură e în drum, întoarce false și anularea
     * se reia; o captură reușită între timp face comanda `confirmata`, iar anularea urmează calea obișnuită a anulării gazdei.
     */
    public function anulataInEc(int $id, string $actor): bool
    {
        $b = $this->app->baza();
        $acumM = $this->app->ceas()->moment();
        $lacat = $this->app->ceas()->moment(Finalizare::LACAT);
        if ($b->executa('UPDATE {comenzi} SET blocat_pana = ? WHERE id = ? AND stare = ? AND (blocat_pana IS NULL OR blocat_pana <= ?)', [$lacat, $id, 'de_capturat', $acumM])->rowCount() !== 1) {
            return ($this->app->comenzi()->dupaId($id)['stare'] ?? null) !== 'de_capturat';
        }
        if ($this->app->masinaStari()->trece($id, 'card', 'de_capturat', 'de_rambursat', $actor, ['blocat_pana' => null, 'urmatoarea_incercare' => null, 'eroare_cod' => 'anulata_gazda',
            'motiv_rambursare' => 'anulata_gazda', 'anulata_ec_la' => $acumM], ['anulata_ec' => 'de_capturat'], $lacat)) {
            $this->app->plataOnline()->dupaRefuz($id);
        }
        return true;
    }

    /** Cronul: ferestrele trecute (comenzile încă neconfirmate) și rezervările rămase fără plată, de căutat sau de anulat. */
    public function ruleaza(int $cate = 20): int
    {
        $b = $this->app->baza();
        $n = 0;
        $prag = $this->app->ceas()->moment(-self::FEREASTRA);
        foreach ($b->toate("SELECT * FROM {comenzi} WHERE fel = 'card' AND stare IN ('platita', 'de_capturat') AND platita_la <= ? ORDER BY id LIMIT " . $cate, [$prag]) as $c) {
            if (!$this->depasita($c)) {
                continue;
            }
            // `de_capturat` trece prin captureaza(): lacătul unei capturi în drum se respectă, iar Stripe e citit înainte de refuz.
            if ($c['stare'] === 'de_capturat' && $c['urmatoarea_incercare'] !== null && $c['urmatoarea_incercare'] > $this->app->ceas()->moment()) {
                // Reluarea capturii (sau a citirii din Stripe) are ora ei.
                continue;
            }
            $c['stare'] === 'platita' ? $this->expira($c, 'platita', 'cron') : $this->app->plataOnline()->captureaza((int) $c['id'], 'cron');
            $n++;
        }
        foreach ($b->toate("SELECT id, ec_orfana_pana FROM {comenzi} WHERE fel = 'card' AND ec_orfana IN ('de_cautat', 'de_anulat') ORDER BY id LIMIT " . $cate) as $r) {
            // După prima zi (alerta gazdei a plecat), reluările se răresc.
            $rar = $this->dupaTermen($r);
            if ($this->app->limitator()->incearca(($rar ? 'orfana_rar:' : 'orfana:') . $r['id'], 1, $rar ? self::REIA_RAR : self::REIA)) {
                $this->orfana((int) $r['id']);
                $n++;
            }
        }
        return $n;
    }
}
