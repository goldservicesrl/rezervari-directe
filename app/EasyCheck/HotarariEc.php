<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;
use Cazo\Rezervare\Anulare;

defined('CAZO') or exit;

/**
 * Hotărârile gazdei luate în EasyCheck despre rezervările site-ului (etapa 3 din „EasyCheck întâi”),
 * doar cu funcția aprinsă (ModEc::comenziAprinse):
 *
 *   aplica          citirea lui `anulare` și `cerere_anulare` de pe rezervare (lista incrementală, anunțul
 *                   `rezervare.anulare_decisa`) și trecerile comenzii site-ului din contract
 *   trimiteCerere   cererea de anulare a oaspetelui (`POST /rezervari/{id}/cerere-anulare`), cu propunerea regulii
 *                   înghețate pe comandă; fără răspuns, reluări din cron; un refuz definitiv (404: funcția stinsă) lasă
 *                   cererea în panoul site-ului, ca până acum
 *   contract        clauza anulării gazdei (`contract_anulare`) pentru `POST /rezervari`
 *   pornire         o singură dată pe cheie, la aprindere: clauzele comenzilor vii (`PUT .../contract-anulare`) și
 *                   cererile de anulare în așteptare; comenzile „Anulare de lămurit” rămân, fără butoane (5.3 în EasyCheck)
 */
final class HotarariEc
{
    public const SETARE_PORNIRE = 'ec.comenzi.pornire';
    /** Reluările cererii de anulare fără răspuns: 1, 5, 30 de minute, apoi gazda o vede în panoul site-ului (după 2 ore). */
    public const REINCERCARI = [60, 300, 1800];
    public const CERERE_VIATA = 2 * 3600;

    public function __construct(private Aplicatie $app)
    {
    }

    /**
     * Hotărârea de pe o rezervare citită din EasyCheck. @return bool|null null = nimic de aplicat aici (rămâne calea de
     * azi: anularea pornită de site, anularea fără hotărâre); true = aplicată; false = zăvorul comenzii ocupat
     */
    public function aplica(array $r): ?bool
    {
        if (!is_string($r['id'] ?? null) || !$this->app->modEc()->comenziAprinse()) {
            return null;
        }
        $c = $this->app->baza()->rand("SELECT id, ref, stare, ec_cerere_stare, ec_cerere_la FROM {comenzi} WHERE ec_rezervare = ?
            AND stare IN ('confirmata', 'anulare_ceruta', 'anulare_de_lamurit', 'anulata_oaspete', 'anulata_gazda') ORDER BY id DESC LIMIT 1", [$r['id']]);
        if ($c === null || (is_string($r['ref_site'] ?? null) && $r['ref_site'] !== $c['ref'])) {
            return null;
        }
        $an = $r['anulare'] ?? null;
        if (($r['stare'] ?? null) === 'anulata' && is_array($an) && in_array($an['de'] ?? null, ['gazda', 'sistem'], true) && is_array($an['restituire'] ?? null)) {
            return $this->app->anulare()->hotarareEc((int) $c['id'], $an);
        }
        $cerere = $r['cerere_anulare'] ?? null;
        if (($r['stare'] ?? null) !== 'anulata' && is_array($cerere) && ($cerere['stare'] ?? null) === 'respinsa' && $c['stare'] === 'anulare_ceruta'
            && $c['ec_cerere_stare'] === 'trimisa' && $c['ec_cerere_la'] !== null && ($cerere['ceruta_la'] ?? null) === Comenzi::moment(Ceas::dinMoment((string) $c['ec_cerere_la']))) {
            // Doar cererea trimisă de noi (același moment): o cerere nouă, încă netrimisă, nu e închisă de răspunsul celei vechi.
            return $this->app->anulare()->pastrataInEc((int) $c['id']);
        }
        return null;
    }

    /**
     * Cererea de anulare a oaspetelui, spre EasyCheck. Întoarce true când EasyCheck a primit-o sau o va
     * primi din cron (rețeaua, 5xx); false când EasyCheck n-o poate lua (funcția stinsă, transferul neplătit): atunci gazda
     * o hotărăște în panoul site-ului, ca până acum.
     */
    public function trimiteCerere(int $id): bool
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || $c['stare'] !== 'anulare_ceruta' || !preg_match('~^rez_[0-9A-Za-z]{24}$~D', (string) ($c['ec_rezervare'] ?? ''))) {
            return false;
        }
        if ($c['ec_cerere_stare'] === 'trimisa' && $c['ec_cerere_la'] === $c['anulare_ceruta_la']) {
            return true;
        }
        if ($c['ec_cerere_la'] !== $c['anulare_ceruta_la'] || $c['ec_cerere_idem'] === null) {
            // O cerere nouă (prima, sau după una păstrată de gazdă): cheie nouă, momentul ei.
            $n = 1 + (int) $this->app->baza()->valoare("SELECT COUNT(*) FROM {comenzi_jurnal} WHERE comanda_id = ? AND in_stare = 'anulare_ceruta' AND din_stare = 'confirmata'", [$id]);
            $this->app->baza()->executa("UPDATE {comenzi} SET ec_cerere_la = ?, ec_cerere_idem = ?, ec_cerere_stare = 'de_trimis', ec_cerere_incercari = 0, ec_cerere_urmatoarea = NULL WHERE id = ?",
                [$c['anulare_ceruta_la'], mb_substr('cerere-anulare-' . $c['ref'] . '-' . max(1, $n - 1), 0, 100), $id]);
            $c = $this->app->comenzi()->dupaId($id);
        }
        return $this->trimite($c);
    }

    private function trimite(array $c): bool
    {
        $id = (int) $c['id'];
        $b = $this->app->baza();
        $acum = $this->app->ceas()->acum();
        $p = $this->app->anulare()->propunere($c);
        $corp = ['ceruta_la' => Comenzi::moment(min($acum, Ceas::dinMoment((string) $c['ec_cerere_la']))), 'propunere' => ['restituie' => max(0, (int) $p['suma']), 'retine' => max(0, (int) $p['retine'])]];
        $n = (int) $c['ec_cerere_incercari'] + 1;
        $b->executa('UPDATE {comenzi} SET ec_cerere_incercari = ? WHERE id = ?', [$n, $id]);
        try {
            $this->app->ecClient()->scrie('POST', '/rezervari/' . $c['ec_rezervare'] . '/cerere-anulare', Client::json($corp), (string) $c['ec_cerere_idem']);
        } catch (EroareApi $e) {
            $motiv = $e->detalii()['motiv'] ?? null;
            if ($e->raspuns->status === 409 && $motiv === 'cerere_existenta') {
                return $this->trimisa($c);
            }
            if ($e->raspuns->status === 409 && $motiv === 'anulata') {
                // Anulată între timp în EasyCheck: lista incrementală aduce hotărârea.
                $this->app->coada()->pune(Anulare::JOB, [], Anulare::JOB);
                return $this->trimisa($c);
            }
            if ($e->raspuns->status >= 500 || $e->raspuns->status === 429 || $e->comportament === 'reincearca' || ($e->cod === 'idempotency_conflict' && (($e->detalii()['in_lucru'] ?? false) === true))) {
                return $this->amana($c, $n, $e->raspuns->status . ' ' . ($e->cod ?? ''));
            }
            return $this->local($c, $e->raspuns->status . ' ' . ($e->cod ?? '') . (is_string($motiv) ? ':' . $motiv : ''));
        } catch (EroareTransport $e) {
            return $this->amana($c, $n, $e->felul);
        } catch (\LengthException) {
            return $this->local($c, 'corp_prea_mare');
        }
        return $this->trimisa($c);
    }

    private function trimisa(array $c): bool
    {
        $this->app->baza()->executa("UPDATE {comenzi} SET ec_cerere_stare = 'trimisa', ec_cerere_urmatoarea = NULL WHERE id = ?", [(int) $c['id']]);
        $this->app->masinaStari()->jurnal((int) $c['id'], (string) $c['stare'], (string) $c['stare'], 'webhook_ec', ['cerere_anulare' => 'trimisa_ec']);
        return true;
    }

    private function amana(array $c, int $n, string $cod): bool
    {
        if ($this->app->ceas()->acum() - Ceas::dinMoment((string) $c['ec_cerere_la']) >= self::CERERE_VIATA) {
            return $this->local($c, $cod);
        }
        $pauza = self::REINCERCARI[min($n - 1, count(self::REINCERCARI) - 1)];
        $this->app->baza()->executa("UPDATE {comenzi} SET ec_cerere_stare = 'de_trimis', ec_cerere_urmatoarea = ? WHERE id = ?", [$this->app->ceas()->moment($pauza), (int) $c['id']]);
        return true;
    }

    /** EasyCheck n-a luat cererea: rămâne în panoul site-ului, ca până acum, iar gazda primește e-mailul site-ului. */
    private function local(array $c, string $cod): bool
    {
        $n = $this->app->baza()->executa("UPDATE {comenzi} SET ec_cerere_stare = 'local', ec_cerere_urmatoarea = NULL WHERE id = ? AND (ec_cerere_stare IS NULL OR ec_cerere_stare <> 'local')", [(int) $c['id']])->rowCount();
        $this->app->jurnal()->avertisment('Cererea de anulare n-a ajuns la EasyCheck; gazda o hotărăște în panoul site-ului', ['ref' => $c['ref'], 'cod' => $cod]);
        if ($n === 1 && $c['ec_cerere_stare'] === 'de_trimis' && $c['ec_cerere_incercari'] > 0) {
            // Cererea pornise spre EasyCheck (e-mailul site-ului către gazdă fusese oprit): gazda îl primește acum.
            foreach ($this->app->baza()->toate("SELECT email, limba_panou FROM {utilizatori} WHERE rol = 'proprietar' AND activ = 1 ORDER BY id") as $u) {
                $this->app->emailuri()->pune((int) $c['id'], 'gazda_anulare_ceruta', (string) $u['limba_panou'], (string) $u['email'],
                    ['comanda_id' => (int) $c['id'], 'suma_bani' => (int) $this->app->anulare()->propunere($c)['suma']]);
            }
        }
        return false;
    }

    /** Cererile de anulare de trimis din nou (cronul). Întoarce câte s-au încercat. */
    public function reiaCereri(int $cate = 20): int
    {
        $n = 0;
        foreach ($this->app->baza()->toate("SELECT id FROM {comenzi} WHERE stare = 'anulare_ceruta' AND ec_cerere_stare = 'de_trimis' AND (ec_cerere_urmatoarea IS NULL OR ec_cerere_urmatoarea <= ?)
            ORDER BY id LIMIT " . $cate, [$this->app->ceas()->moment()]) as $r) {
            $c = $this->app->comenzi()->dupaId((int) $r['id']);
            if ($c !== null) {
                $this->trimite($c);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Clauza anulării gazdei înghețată pe comandă, în forma contractului, doar cu funcția aprinsă (un
     * EasyCheck fără etapa 3 n-o folosește); null fără clauză (comenzile de dinainte, cererile cu plata la sosire).
     * @return array{gazda:string, avans?:int}|null
     */
    public function contract(array $c): ?array
    {
        $g = $c['anulare_gazda'] ?? null;
        if (!in_array($g, Anulare::GAZDA, true) || !$this->app->modEc()->comenziAprinse()) {
            return null;
        }
        $avans = Anulare::despagubireContract($c);
        if ($avans <= 0) {
            $avans = (int) ($c['avans_initial'] ?? $c['avans'] ?? 0);
        }
        if ($g === Anulare::GAZDA_DESPAGUBIRE || $avans > 0) {
            return ['gazda' => (string) $g, 'avans' => max(0, $avans)];
        }
        return ['gazda' => (string) $g];
    }

    /**
     * Aprinderea, o singură dată pe cheie: clauzele comenzilor vii trimise cu `PUT .../contract-anulare`,
     * cererile de anulare în așteptare trimise la EasyCheck. Ce nu ajunge acum se reia la rularea următoare.
     */
    public function pornire(): void
    {
        $prefix = Conectare::prefix($this->app->secrete()->citeste('ec_cheie'));
        if ($prefix === null || $this->app->setari()->get(self::SETARE_PORNIRE) === $prefix) {
            return;
        }
        $ok = true;
        foreach ($this->app->baza()->toate("SELECT id FROM {comenzi} WHERE stare IN ('confirmata', 'anulare_ceruta') AND ec_rezervare IS NOT NULL AND anulare_gazda IS NOT NULL
            AND ec_contract_trimis_la IS NULL ORDER BY id LIMIT 200") as $r) {
            $c = $this->app->comenzi()->dupaId((int) $r['id']);
            $k = $c !== null ? $this->contract($c) : null;
            if ($c === null || $k === null || \Cazo\Local\Mod::comandaLocala($c) || $this->app->ecConectare()->comandaStraina($c, $this->app->secrete()->citeste('ec_cheie'))) {
                continue;
            }
            try {
                $this->app->ecClient()->scrie('PUT', '/rezervari/' . $c['ec_rezervare'] . '/contract-anulare', Client::json($k));
            } catch (EroareApi $e) {
                if ($e->raspuns->status >= 500 || $e->raspuns->status === 429) {
                    $ok = false;
                    continue;
                }
                // 409 deja_fixat (clauza e înghețată deja), 404, 403: nimic de reluat.
            } catch (EroareTransport | \LengthException) {
                $ok = false;
                continue;
            }
            $this->app->baza()->executa('UPDATE {comenzi} SET ec_contract_trimis_la = ? WHERE id = ?', [$this->app->ceas()->moment(), (int) $c['id']]);
        }
        foreach ($this->app->baza()->toate("SELECT id FROM {comenzi} WHERE stare = 'anulare_ceruta' AND anulare_actor IS NULL AND (ec_cerere_stare IS NULL OR ec_cerere_la <> anulare_ceruta_la) ORDER BY id LIMIT 200") as $r) {
            $this->trimiteCerere((int) $r['id']);
        }
        if ($ok) {
            $this->app->setari()->pune(self::SETARE_PORNIRE, $prefix);
        }
    }
}
