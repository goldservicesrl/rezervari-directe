<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\EasyCheck\Client;
use Cazo\EasyCheck\Conectare;
use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Plata prin transfer bancar (contractul EasyCheck din 20.11.2026): rezervarea trimisă cu `plata_asteptata`
 * și urmărită până la decizia gazdei din EasyCheck („Am primit banii” / „Nu am primit banii”) sau până la termen.
 *
 * Trimiterea, sub lacătul comenzii (`blocat_pana`, 60 s): `noua` → `trimitere_ec` (o singură dată), apoi POST /rezervari
 * cu `Idempotency-Key: idem_ec` și corpul `corp_ec`, octet cu octet (cu `plata_asteptata {metoda, suma, termen,
 * referinta}` în loc de `plata`). Rezultatele:
 *   201                  → `transfer_in_asteptare`, cu `ec_rezervare` și `transfer_termen`; EasyCheck a închis nopțile;
 *                          oaspetele primește „Așteptăm transferul” (contul, suma, termenul, textul plății), iar gazda
 *                          e-mailul scurt cu ce are de urmărit în bancă
 *   409 indisponibil     → `indisponibila` (spre deosebire de plata cu cardul, transferul se refuză peste nopți ocupate)
 *   422, 409 fără in_lucru, 4xx, 401, 403 → `respinsa_ec`, cu codul și detaliile (ca la cererea cash)
 *   429, 503, 5xx, timeout, 409 în lucru → o reîncercare pe loc, apoi reluări la 5 s, 30 s, 2, 10, 30 de minute, cu
 *                          aceeași cheie și același corp; după RENUNTARE (sau când termenul din corp e prea aproape),
 *                          `respinsa_ec` cu `ec_indisponibil` și e-mailul `cerere_netrimisa`. O rezervare creată
 *                          totuși de o încercare cu răspunsul pierdut expiră singură în EasyCheck, la termen.
 *
 * Urmărirea (anunțurile `rezervare.plata_*` și `rezervare.schimbata`, cronul la 15 minute și o citire imediat după
 * termen, plasa de siguranță): GET /rezervari/{id}, apoi după `plata_asteptata.stare`:
 *   confirmata → `confirmata`, cu e-mailul de confirmare obișnuit (contractul, .ics, adresa), cu suma primită și restul;
 *   neprimita  → `transfer_neprimit`; expirata → `transfer_expirat`; fiecare cu e-mailul oaspetelui și alerta gazdei;
 *   anulata    → `anulata_oaspete` (anularea pornită din „Rezervarea mea”) sau `anulata_gazda` (din calendarul EasyCheck);
 *   in_asteptare cu alt termen → termenul nou pe comandă și e-mailul scurt către oaspete.
 * Fiecare trecere e o tranziție a mașinii de stări (UPDATE ... WHERE stare = :veche), deci anunțurile repetate sau
 * sosite în altă ordine n-au efect a doua oară.
 *
 * Reamintirea: un singur e-mail, cu 12 ore înainte de termen; la termenele sub 24 de ore, la jumătatea lor.
 * Anularea oaspetelui cât plata e așteptată: POST /rezervari/{id}/anuleaza, fără rambursare automată; banii
 * trimiși între timp îi întoarce gazda, din bancă.
 */
final class Transfer
{
    public const FEL = 'transfer';
    public const ASTEPTARE = 'transfer_in_asteptare';
    public const NEPRIMIT = 'transfer_neprimit';
    public const EXPIRAT = 'transfer_expirat';
    public const LACAT = 60;
    public const REINCERCARI = [5, 30, 120, 600, 1800];
    /** După atâtea secunde de la prima trimitere fără răspuns de la EasyCheck, comanda se închide (termenul de plată curge deja). */
    public const RENUNTARE = 2 * 3600;
    /** Trimiterea se oprește și când până la termenul din corp rămâne sub atât. */
    public const MARJA_TERMEN = 1800;
    public const URMARIRE = 900;
    public const ABANDON = 3600;
    /** Reamintirea pleacă atât înainte de termen; la termenele scurte, la jumătatea lor. */
    public const REAMINTIRE = 12 * 3600;
    public const REINCERCARI_ANULARE = [30, 120, 600, 1800];
    public const JOB = 'transfer:urmareste';
    /** Anunțurile EasyCheck ale plății prin transfer (contractul, secțiunea 4). */
    public const ANUNTURI = ['rezervare.plata_confirmata', 'rezervare.plata_neprimita', 'rezervare.plata_expirata', 'rezervare.plata_prelungita', 'rezervare.plata_anuntata'];
    /** „Am plătit”: reluarea anunțului către EasyCheck după o eroare de rețea, și cât se tot reia. */
    public const ANUNT_REIA = 300;
    public const ANUNT_RENUNTARE = 2 * 3600;
    /** A doua notificare a gazdei, când site-ul a anunțat-o singur: după atâtea zile lucrătoare de la apăsare. */
    public const ANUNT_ZILE_LUCRATOARE = 3;

    public function __construct(private Aplicatie $app)
    {
    }

    /** `plata_asteptata` pentru corpul POST /rezervari (contractul, secțiunea 2). */
    public static function plataAsteptata(int $suma, int $termen, string $ref): array
    {
        return ['metoda' => 'transfer', 'suma' => $suma, 'termen' => gmdate('Y-m-d\TH:i:s\Z', $termen), 'referinta' => $ref];
    }

    // ---- trimiterea ----

    /** Trimite rezervarea (din POST /rezerva, după cod sau din cron). Întoarce starea comenzii după încercare. */
    public function trimite(int $id, string $actor, bool $inline = true): string
    {
        $comenzi = $this->app->comenzi();
        $c = $comenzi->dupaId($id);
        if ($c === null || $c['fel'] !== self::FEL) {
            throw new \InvalidArgumentException('Comandă cu transfer necunoscută.');
        }
        $ceas = $this->app->ceas();
        $blocat = $ceas->moment(self::LACAT);
        if ($c['stare'] === 'noua') {
            $trimisa = $ceas->moment();
            if (!$this->app->masinaStari()->trece($id, self::FEL, 'noua', 'trimitere_ec', $actor, ['blocat_pana' => $blocat, 'incercari' => (int) $c['incercari'] + 1, 'trimisa_la' => $trimisa])) {
                return (string) $comenzi->dupaId($id)['stare'];
            }
            $c['trimisa_la'] = $trimisa;
        } elseif ($c['stare'] === 'trimitere_ec') {
            $n = $this->app->baza()->executa("UPDATE {comenzi} SET blocat_pana = ?, incercari = incercari + 1 WHERE id = ? AND stare = 'trimitere_ec'
                AND (blocat_pana IS NULL OR blocat_pana <= ?)", [$blocat, $id, $ceas->moment()])->rowCount();
            if ($n !== 1) {
                return 'trimitere_ec';
            }
        } else {
            return (string) $c['stare'];
        }
        $corp = $comenzi->corpEcDin($c);
        // rezervarea e a cheii care o trimite acum.
        $prefix = Conectare::prefix($this->app->cheieEc());
        for ($incercare = 0; ; $incercare++) {
            try {
                $r = $this->app->ecClient()->scrie('POST', '/rezervari', $corp, (string) $c['idem_ec']);
                return $this->creata($c, $r->json() ?? [], $r->requestId(), $actor, $prefix);
            } catch (EroareApi $e) {
                $inLucru = $e->cod === 'idempotency_conflict' && (($e->detalii()['in_lucru'] ?? false) === true);
                if (!$inLucru && !in_array($e->comportament, ['reincearca', 'pauza'], true) && $e->raspuns->status < 500 && $e->raspuns->status !== 429) {
                    return $this->refuzata($c, $e, $actor, $inline);
                }
                $motiv = $e->cod ?? 'http_' . $e->raspuns->status;
                $requestId = $e->requestId;
            } catch (EroareTransport $e) {
                $motiv = $e->felul;
                $requestId = null;
            }
            if ($inline && $incercare === 0 && $motiv !== 'pauza') {
                continue;
            }
            return $this->amana($c, $motiv, $requestId, $actor);
        }
    }

    private function creata(array $c, array $j, ?string $requestId, string $actor, ?string $prefix): string
    {
        $id = (int) $c['id'];
        $rez = is_string($j['id'] ?? null) && preg_match('~^rez_[0-9A-Za-z]{1,64}$~', $j['id']) ? $j['id'] : null;
        if ($rez === null) {
            return $this->amana($c, 'forma', $requestId, $actor);
        }
        // Termenul în vigoare: cel din răspuns; altfel cel trimis în corp.
        $termen = CerereCash::moment($j['plata_asteptata']['termen'] ?? null) ?? (string) $c['transfer_termen'];
        $ok = $this->app->masinaStari()->trece($id, self::FEL, 'trimitere_ec', self::ASTEPTARE, $actor, [
            'ec_rezervare' => $rez, 'transfer_termen' => $termen, 'transfer_reamintire_la' => $this->momentReamintire($termen), 'blocat_pana' => null, 'urmatoarea_incercare' => null,
            'verificat_la' => $this->app->ceas()->moment(), 'eroare_cod' => null, 'eroare_detalii' => null, 'request_id' => $requestId,
        ] + ($prefix !== null ? ['cheie_prefix' => $prefix] : []), ['request_id' => $requestId, 'rezervare' => $rez]);
        if ($ok) {
            $this->anunta($id, 'transfer_asteptat');
            $this->anuntaGazda($id, 'gazda_transfer');
            // O reluare cu aceeași cheie poate aduce o rezervare deja hotărâtă între timp: starea ei se aplică pe loc.
            if (($j['stare'] ?? 'in_asteptarea_platii') !== 'in_asteptarea_platii' || ($j['plata_asteptata']['stare'] ?? 'in_asteptare') !== 'in_asteptare') {
                $this->aplica($this->app->comenzi()->dupaId($id), $j, $requestId, $actor);
            }
        }
        return (string) $this->app->comenzi()->dupaId($id)['stare'];
    }

    private function refuzata(array $c, EroareApi $e, string $actor, bool $peLoc): string
    {
        $in = $e->raspuns->status === 409 && $e->cod === 'indisponibil' ? 'indisponibila' : 'respinsa_ec';
        $ok = $this->app->masinaStari()->trece((int) $c['id'], self::FEL, 'trimitere_ec', $in, $actor, [
            'blocat_pana' => null, 'urmatoarea_incercare' => null, 'eroare_cod' => $e->cod ?? 'http_' . $e->raspuns->status,
            'eroare_detalii' => json_encode($e->detalii() + (in_array($e->raspuns->status, [401, 403], true) ? ['_status' => $e->raspuns->status] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'request_id' => $e->requestId,
        ], ['request_id' => $e->requestId, 'cod' => $e->cod, 'status' => $e->raspuns->status]);
        if ($in === 'respinsa_ec' && !in_array($e->cod, ['restrictie', 'pret_diferit'], true)) {
            $this->app->jurnal()->eroare('Rezervarea cu transfer respinsă de EasyCheck', ['ref' => $c['ref'], 'status' => $e->raspuns->status, 'cod' => $e->cod, 'request_id' => $e->requestId]);
        }
        if ($ok) {
            // Refuzul văzut pe loc rămâne pe ecran; cel venit mai târziu, din cron, ajunge pe e-mail.
            if (!$peLoc) {
                $this->anunta((int) $c['id'], $in === 'indisponibila' ? 'cerere_indisponibila' : 'cerere_respinsa');
            }
            if (in_array($e->raspuns->status, [401, 403], true)) {
                $this->app->alertaGazda('alerta_cheie', ['ref' => (string) $c['ref'], 'cod' => (string) ($e->cod ?? 'http_' . $e->raspuns->status)], 'cheie:' . ($e->cod ?? $e->raspuns->status), 86400);
            }
        }
        return $in;
    }

    private function amana(array $c, string $motiv, ?string $requestId, string $actor): string
    {
        $id = (int) $c['id'];
        $ceas = $this->app->ceas();
        $varsta = $ceas->acum() - Ceas::dinMoment((string) ($c['trimisa_la'] ?? $c['creat_la']));
        $termen = $c['transfer_termen'] !== null ? Ceas::dinMoment((string) $c['transfer_termen']) : PHP_INT_MAX;
        if ($varsta >= self::RENUNTARE || $termen - $ceas->acum() < self::MARJA_TERMEN) {
            $ok = $this->app->masinaStari()->trece($id, self::FEL, 'trimitere_ec', 'respinsa_ec', $actor, ['blocat_pana' => null, 'urmatoarea_incercare' => null,
                'eroare_cod' => 'ec_indisponibil', 'request_id' => $requestId], ['motiv' => $motiv, 'request_id' => $requestId]);
            if ($ok) {
                $this->anunta($id, 'cerere_netrimisa');
            }
            return 'respinsa_ec';
        }
        $incercari = max(1, (int) ($this->app->comenzi()->dupaId($id)['incercari'] ?? 1));
        $pauza = self::REINCERCARI[min($incercari - 1, count(self::REINCERCARI) - 1)];
        $this->app->baza()->executa("UPDATE {comenzi} SET blocat_pana = NULL, urmatoarea_incercare = ?, eroare_cod = ?, request_id = ?, actualizat_la = ? WHERE id = ? AND stare = 'trimitere_ec'",
            [$ceas->moment($pauza), mb_substr($motiv, 0, 64), $requestId, $ceas->moment(), $id]);
        $this->app->masinaStari()->jurnal($id, 'trimitere_ec', 'trimitere_ec', $actor, ['reincercare_in' => $pauza, 'motiv' => $motiv, 'request_id' => $requestId]);
        return 'trimitere_ec';
    }

    /** Momentul reamintirii pentru un termen, sau null când a trecut deja. */
    private function momentReamintire(?string $termen): ?string
    {
        if ($termen === null || $termen === '') {
            return null;
        }
        $acum = $this->app->ceas()->acum();
        $t = Ceas::dinMoment($termen);
        $la = max($t - self::REAMINTIRE, $acum + intdiv(max(0, $t - $acum), 2));
        return $la > $acum && $la < $t ? Ceas::formateaza($la) : null;
    }

    // ---- urmărirea ----

    /**
     * Citește din EasyCheck rezervările care așteaptă plata. $toate: după un anunț (anunțul nu poartă id-ul), toate;
     * altfel doar cele scadente: necitite de 15 minute sau ajunse la termen (plasa de siguranță).
     * @return array{citite:int, schimbate:int, erori:int}
     */
    public function urmareste(bool $toate = false, ?int $doarId = null, string $actor = 'cron'): array
    {
        $acum = $this->app->ceas()->acum();
        $sql = "SELECT * FROM {comenzi} WHERE fel = 'transfer' AND stare = 'transfer_in_asteptare' AND ec_rezervare IS NOT NULL";
        $p = [];
        if ($doarId !== null) {
            $sql .= ' AND id = ?';
            $p[] = $doarId;
        } elseif (!$toate) {
            $sql .= ' AND (verificat_la IS NULL OR verificat_la <= ? OR (transfer_termen <= ? AND verificat_la <= transfer_termen))';
            $p[] = Ceas::formateaza($acum - self::URMARIRE);
            $p[] = Ceas::formateaza($acum - 60);
        }
        $rez = ['citite' => 0, 'schimbate' => 0, 'erori' => 0];
        foreach ($this->app->baza()->toate($sql . ' ORDER BY id LIMIT 50', $p) as $c) {
            try {
                $r = $this->app->ecClient()->citeste('/rezervari/' . $c['ec_rezervare']);
                $rez['citite']++;
                $rez['schimbate'] += $this->aplica($c, $r->json() ?? [], $r->requestId(), $actor) ? 1 : 0;
            } catch (EroareApi $e) {
                $rez['erori']++;
                // După „Am plătit”, site-ul nu mai socotește singur rezervarea expirată: decizia rămâne a gazdei.
                if ($e->raspuns->status === 404 && $c['transfer_anuntat_la'] === null && $c['transfer_termen'] !== null && (string) $c['transfer_termen'] <= Ceas::formateaza($acum)) {
                    // Rezervarea a ieșit din API după ce termenul ei trecuse.
                    $rez['schimbate'] += $this->aplica($c, ['stare' => 'anulata', 'plata_asteptata' => ['stare' => 'expirata']], $e->requestId, $actor) ? 1 : 0;
                } else {
                    $this->app->baza()->executa('UPDATE {comenzi} SET verificat_la = ? WHERE id = ?', [Ceas::formateaza($acum), $c['id']]);
                }
            } catch (EroareTransport) {
                $rez['erori']++;
                break;
            }
        }
        return $rez;
    }

    /** Aplică starea citită din EasyCheck (GET /rezervari/{id}). Întoarce true când comanda și-a schimbat starea. */
    private function aplica(array $c, array $j, ?string $requestId, string $actor): bool
    {
        $id = (int) $c['id'];
        $masina = $this->app->masinaStari();
        // „Am plătit” stă tot în `plata_asteptata` (nota de implementare a secțiunii 9); `plata` e altceva (plata online).
        $pa = is_array($j['plata_asteptata'] ?? null) ? $j['plata_asteptata'] : [];
        $plata = (string) ($pa['stare'] ?? '');
        $stare = (string) ($j['stare'] ?? '');
        $acum = $this->app->ceas()->moment();
        $campuri = ['verificat_la' => $acum, 'request_id' => $requestId, 'transfer_reamintire_la' => null];
        $d = ['request_id' => $requestId, 'plata' => $plata];
        if ($plata === 'confirmata' || ($plata === '' && $stare === 'confirmata')) {
            if (!$masina->trece($id, self::FEL, self::ASTEPTARE, 'confirmata', $actor, $campuri + ['platita_la' => CerereCash::moment($pa['decisa_la'] ?? null) ?? $acum], $d)) {
                return false;
            }
            $this->anunta($id, 'plata_confirmata');
            $this->app->anulare()->dupaConfirmare($id);
            return true;
        }
        if ($plata === 'neprimita' || $plata === 'expirata') {
            $in = $plata === 'neprimita' ? self::NEPRIMIT : self::EXPIRAT;
            if (!$masina->trece($id, self::FEL, self::ASTEPTARE, $in, $actor, $campuri, $d)) {
                return false;
            }
            $this->anunta($id, $in);
            $this->app->alertaGazda('alerta_transfer', ['ref' => (string) $c['ref'], 'sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare'],
                'suma_bani' => (int) $c['suma_acum'], 'fel' => $plata], 'transfer:' . $c['ref'], 30 * 86400);
            return true;
        }
        if ($plata === 'anulata' || $stare === 'anulata') {
            // Anulată în EasyCheck cât aștepta plata: de oaspete (marcajul anulării pornite din „Rezervarea mea”) sau de gazdă, din calendar.
            $in = $c['anulare_actor'] === 'oaspete' ? 'anulata_oaspete' : 'anulata_gazda';
            return $this->incheieAnularea($id, $in, $actor, $requestId);
        }
        if ($plata === 'in_asteptare') {
            $termen = CerereCash::moment($pa['termen'] ?? null);
            $b = $this->app->baza();
            $b->executa('UPDATE {comenzi} SET verificat_la = ? WHERE id = ?', [$acum, $id]);
            // EasyCheck are „Am plătit” (de exemplu, răspunsul anunțului s-a pierdut): termenul nu mai curge.
            $anuntat = CerereCash::moment($pa['anuntata_de_oaspete_la'] ?? null);
            if ($anuntat !== null) {
                $b->executa("UPDATE {comenzi} SET transfer_anuntat_la = COALESCE(transfer_anuntat_la, ?), transfer_anunt_fel = 'easycheck', transfer_anunt_ec_la = COALESCE(transfer_anunt_ec_la, ?),
                    transfer_anunt_urmatoarea = NULL, transfer_reamintire_la = NULL WHERE id = ? AND stare = 'transfer_in_asteptare' AND (transfer_anunt_fel IS NULL OR transfer_anunt_fel <> 'easycheck')",
                    [$anuntat, $acum, $id]);
                return false;
            }
            if ($termen !== null && $termen !== (string) $c['transfer_termen']) {
                // Termenul prelungit de gazdă: o singură scriere pe fiecare termen nou, deci un singur e-mail.
                $n = $b->executa("UPDATE {comenzi} SET transfer_termen = ?, transfer_reamintire_la = CASE WHEN transfer_reamintire_la IS NULL THEN NULL ELSE ? END, actualizat_la = ?
                    WHERE id = ? AND stare = 'transfer_in_asteptare' AND (transfer_termen IS NULL OR transfer_termen <> ?)",
                    [$termen, $this->momentReamintire($termen), $acum, $id, $termen])->rowCount();
                if ($n === 1) {
                    $masina->jurnal($id, self::ASTEPTARE, self::ASTEPTARE, $actor, ['termen' => $termen, 'request_id' => $requestId]);
                    if ($termen > (string) $c['transfer_termen']) {
                        $this->anunta($id, 'transfer_prelungit');
                    }
                }
            }
            return false;
        }
        $this->app->baza()->executa('UPDATE {comenzi} SET verificat_la = ? WHERE id = ?', [$acum, $id]);
        return false;
    }

    // ---- cronul ----

    /** Ceva de făcut pentru cron (o interogare ieftină, ca sarcina să iasă repede pe un site fără transferuri). */
    public function areLucru(): bool
    {
        return $this->app->baza()->valoare("SELECT 1 FROM {comenzi} WHERE fel = 'transfer' AND stare IN ('noua', 'trimitere_ec', 'transfer_in_asteptare') LIMIT 1") !== null;
    }

    /**
     * Cronul (prioritatea 2): retrimiterile scadente, anulările oaspeților rămase fără răspuns, citirile scadente (cu
     * plasa de siguranță de după termen), reamintirile, comenzile cu codul neconfirmat.
     * @return array{retrimise:int, anulari:int, citite:int, schimbate:int, reamintiri:int, abandonate:int, anunturi:int, notificari:int}
     */
    public function ruleaza(): array
    {
        $b = $this->app->baza();
        $acum = $this->app->ceas()->moment();
        $rez = ['retrimise' => 0, 'anulari' => 0, 'citite' => 0, 'schimbate' => 0, 'reamintiri' => 0, 'abandonate' => 0, 'anunturi' => 0, 'notificari' => 0];
        foreach ($b->executa("SELECT id FROM {comenzi} WHERE fel = 'transfer' AND stare = 'trimitere_ec' AND (blocat_pana IS NULL OR blocat_pana <= ?)
            AND (urmatoarea_incercare IS NULL OR urmatoarea_incercare <= ?) ORDER BY urmatoarea_incercare, id LIMIT 10", [$acum, $acum])->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $this->trimite((int) $id, 'cron', false);
            $rez['retrimise']++;
        }
        foreach ($b->toate("SELECT id FROM {comenzi} WHERE fel = 'transfer' AND stare = 'transfer_in_asteptare' AND anulare_actor IS NOT NULL AND anulare_urmatoarea IS NOT NULL
            AND anulare_urmatoarea <= ? ORDER BY anulare_urmatoarea, id LIMIT 10", [$acum]) as $r) {
            $id = (int) $r['id'];
            $this->app->zavor()->cuZavor('comanda:' . $id, 60, fn (): string => $this->trimiteAnularea($id, 'cron'));
            $rez['anulari']++;
        }
        // anunțurile „Am plătit” rămase netrimise la EasyCheck (rețeaua), apoi a doua notificare a gazdei (felul `site`).
        foreach ($b->toate("SELECT id FROM {comenzi} WHERE fel = 'transfer' AND stare = 'transfer_in_asteptare' AND transfer_anuntat_la IS NOT NULL AND transfer_anunt_fel IS NULL
            AND (transfer_anunt_urmatoarea IS NULL OR transfer_anunt_urmatoarea <= ?) ORDER BY id LIMIT 10", [$acum]) as $r) {
            $id = (int) $r['id'];
            $this->app->zavor()->cuZavor('comanda:' . $id, 60, function () use ($id): bool {
                $this->trimiteAnuntul($id, 'cron');
                return true;
            });
            $rez['anunturi']++;
        }
        $rez['notificari'] = $this->notificariAnunt();
        $u = $this->urmareste();
        $rez['citite'] = $u['citite'];
        $rez['schimbate'] = $u['schimbate'];
        $rez['reamintiri'] = $this->reamintiri();
        foreach ($b->toate("SELECT id FROM {comenzi} WHERE fel = 'transfer' AND stare = 'noua' AND creat_la < ?", [$this->app->ceas()->moment(-self::ABANDON)]) as $r) {
            $rez['abandonate'] += $this->app->masinaStari()->trece((int) $r['id'], self::FEL, 'noua', 'abandonata', 'cron') ? 1 : 0;
        }
        return $rez;
    }

    /** Reamintirile scadente: una pe comandă; momentul planificat se golește înaintea e-mailului. */
    public function reamintiri(int $cate = 20): int
    {
        $b = $this->app->baza();
        $acum = $this->app->ceas()->moment();
        $n = 0;
        foreach ($b->toate("SELECT id, transfer_termen FROM {comenzi} WHERE fel = 'transfer' AND stare = 'transfer_in_asteptare' AND anulare_actor IS NULL AND transfer_anuntat_la IS NULL AND transfer_reamintire_la IS NOT NULL
            AND transfer_reamintire_la <= ? ORDER BY transfer_reamintire_la, id LIMIT " . max(1, $cate), [$acum]) as $r) {
            if ($b->executa("UPDATE {comenzi} SET transfer_reamintire_la = NULL WHERE id = ? AND transfer_reamintire_la IS NOT NULL AND stare = 'transfer_in_asteptare'", [$r['id']])->rowCount() !== 1) {
                continue;
            }
            // Cu termenul deja trecut (cronul a stat), reamintirea rămâne netrimisă: urmează citirea de după termen.
            if ((string) $r['transfer_termen'] > $acum) {
                $this->anunta((int) $r['id'], 'transfer_reamintire');
                $n++;
            }
        }
        return $n;
    }

    // ---- „Am plătit” de la oaspete (contractul, secțiunea 9) ----

    /** Butonul „Am plătit” apare cât plata e așteptată, înainte de apăsare și fără o anulare pornită. */
    public static function poateAnunta(array $c): bool
    {
        return ($c['fel'] ?? null) === self::FEL && ($c['stare'] ?? null) === self::ASTEPTARE && ($c['anulare_actor'] ?? null) === null
            && ($c['transfer_anuntat_la'] ?? null) === null && ($c['ec_rezervare'] ?? null) !== null;
    }

    /**
     * POST /r/{token}/am-platit: momentul apăsării se scrie întâi (primul rămâne), reamintirea plății se oprește, apoi
     * anunțul pleacă la EasyCheck (POST /rezervari/{id}/transfer-anuntat, cu cheie de idempotență), care o anunță pe
     * gazdă, nu mai anulează rezervarea la termen și trimite singur a doua notificare după 3 zile lucrătoare. Fără
     * răspuns (rețeaua), cronul reia anunțul; când EasyCheck nu cunoaște ruta sau refuză anunțul, site-ul o anunță
     * singur pe gazdă (alerta pe e-mail și în panou) și trimite tot el a doua notificare. Rezervarea o confirmă sau o
     * refuză tot gazda. @return string anuntat, deja, stare, ocupat
     */
    public function anuntaPlata(int $id): string
    {
        $r = $this->app->zavor()->cuZavor('comanda:' . $id, 60, function () use ($id): string {
            $c = $this->app->comenzi()->dupaId($id);
            if ($c === null || ($c['fel'] ?? null) !== self::FEL) {
                return 'stare';
            }
            if ($c['transfer_anuntat_la'] !== null) {
                return 'deja';
            }
            if (!self::poateAnunta($c)) {
                return 'stare';
            }
            $acum = $this->app->ceas()->moment();
            $n = $this->app->baza()->executa("UPDATE {comenzi} SET transfer_anuntat_la = ?, transfer_anunt_idem = ?, transfer_reamintire_la = NULL, actualizat_la = ?
                WHERE id = ? AND stare = 'transfer_in_asteptare' AND transfer_anuntat_la IS NULL AND anulare_actor IS NULL", [$acum, Client::uuid(), $acum, $id])->rowCount();
            if ($n !== 1) {
                return 'stare';
            }
            $this->app->masinaStari()->jurnal($id, self::ASTEPTARE, self::ASTEPTARE, 'oaspete', ['transfer' => 'anuntat']);
            $this->trimiteAnuntul($id, 'oaspete');
            return 'anuntat';
        });
        return $r[0] === true ? (string) $r[1] : 'ocupat';
    }

    /** Anunțul „Am plătit” către EasyCheck; chemat sub zăvorul comenzii. */
    private function trimiteAnuntul(int $id, string $actor): void
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || $c['stare'] !== self::ASTEPTARE || $c['transfer_anuntat_la'] === null || $c['transfer_anunt_fel'] !== null) {
            return;
        }
        $b = $this->app->baza();
        $acum = $this->app->ceas()->moment();
        try {
            $r = $this->app->ecClient()->scrie('POST', '/rezervari/' . $c['ec_rezervare'] . '/transfer-anuntat', null, (string) $c['transfer_anunt_idem']);
            $b->executa("UPDATE {comenzi} SET transfer_anunt_fel = 'easycheck', transfer_anunt_ec_la = ?, transfer_anunt_urmatoarea = NULL WHERE id = ?", [$acum, $id]);
            $this->app->masinaStari()->jurnal($id, self::ASTEPTARE, self::ASTEPTARE, $actor, ['transfer' => 'anunt_easycheck', 'request_id' => $r->requestId()]);
            $j = $r->json() ?? [];
            if (($j['stare'] ?? 'in_asteptarea_platii') !== 'in_asteptarea_platii') {
                $this->aplica($this->app->comenzi()->dupaId($id), $j, $r->requestId(), $actor);
            }
            return;
        } catch (EroareApi $e) {
            $cod = $e->cod ?? 'http_' . $e->raspuns->status;
            // 409 `stare_invalida` (codul din EasyCheck); `stare_nepotrivita`, numele din prima formă a secțiunii 9, la fel.
            if ($e->raspuns->status === 409 && in_array($cod, ['stare_invalida', 'stare_nepotrivita'], true)) {
                // Plata s-a hotărât între timp (confirmată, refuzată, expirată): starea de acum se citește și se aplică.
                $b->executa('UPDATE {comenzi} SET transfer_anunt_urmatoarea = NULL WHERE id = ?', [$id]);
                $this->urmareste(false, $id, $actor);
                return;
            }
            $inLucru = $cod === 'idempotency_conflict' && (($e->detalii()['in_lucru'] ?? false) === true);
            if (!$inLucru && !in_array($e->comportament, ['reincearca', 'pauza'], true) && $e->raspuns->status < 500 && $e->raspuns->status !== 429) {
                $this->anuntLocal($c, $actor, $cod);
                return;
            }
            $motiv = $cod;
        } catch (EroareTransport $e) {
            $motiv = $e->felul;
        }
        if ($this->app->ceas()->acum() - Ceas::dinMoment((string) $c['transfer_anuntat_la']) >= self::ANUNT_RENUNTARE) {
            // EasyCheck n-a răspuns ore în șir: gazda află de la site, ca vestea oaspetelui să nu se piardă.
            $this->anuntLocal($c, $actor, $motiv);
            return;
        }
        $b->executa('UPDATE {comenzi} SET transfer_anunt_urmatoarea = ? WHERE id = ?', [$this->app->ceas()->moment(self::ANUNT_REIA), $id]);
        $this->app->masinaStari()->jurnal($id, self::ASTEPTARE, self::ASTEPTARE, $actor, ['transfer' => 'anunt_amanat', 'motiv' => $motiv, 'reincercare_in' => self::ANUNT_REIA]);
    }

    /** Gazda e anunțată de site (e-mail și jurnalul alertelor din panou), când EasyCheck n-a luat anunțul. */
    private function anuntLocal(array $c, string $actor, string $motiv): void
    {
        $id = (int) $c['id'];
        $this->app->baza()->executa("UPDATE {comenzi} SET transfer_anunt_fel = 'site', transfer_anunt_urmatoarea = NULL WHERE id = ? AND transfer_anunt_fel IS NULL", [$id]);
        $this->app->masinaStari()->jurnal($id, self::ASTEPTARE, self::ASTEPTARE, $actor, ['transfer' => 'anunt_site', 'motiv' => $motiv]);
        $this->app->alertaGazda('alerta_transfer_anuntat', self::dateAlerta($c), 'transfer_anuntat:' . $c['ref'], 30 * 86400);
    }

    /** A doua notificare a gazdei (felul `site`): la 3 zile lucrătoare de la apăsare, cât plata încă e așteptată. */
    public function notificariAnunt(): int
    {
        $b = $this->app->baza();
        $acum = $this->app->ceas()->acum();
        $n = 0;
        foreach ($b->toate("SELECT * FROM {comenzi} WHERE fel = 'transfer' AND stare = 'transfer_in_asteptare' AND transfer_anunt_fel = 'site' AND transfer_anunt_reamintit_la IS NULL
            AND transfer_anuntat_la IS NOT NULL ORDER BY id LIMIT 20") as $c) {
            if (Ceas::dinMoment(self::dupaZileLucratoare((string) $c['transfer_anuntat_la'], self::ANUNT_ZILE_LUCRATOARE)) > $acum) {
                continue;
            }
            if ($b->executa('UPDATE {comenzi} SET transfer_anunt_reamintit_la = ? WHERE id = ? AND transfer_anunt_reamintit_la IS NULL', [Ceas::formateaza($acum), $c['id']])->rowCount() !== 1) {
                continue;
            }
            $this->app->masinaStari()->jurnal((int) $c['id'], self::ASTEPTARE, self::ASTEPTARE, 'cron', ['transfer' => 'anunt_reamintit']);
            $this->app->alertaGazda('alerta_transfer_anuntat_reamintire', self::dateAlerta($c), 'transfer_anuntat_reamintire:' . $c['ref'], 30 * 86400);
            $n++;
        }
        return $n;
    }

    /** Datele alertelor „Am plătit”: codul, perioada, suma și ziua apăsării (în ora României). */
    private static function dateAlerta(array $c): array
    {
        $la = (new \DateTimeImmutable('@' . Ceas::dinMoment((string) $c['transfer_anuntat_la'])))->setTimezone(new \DateTimeZone('Europe/Bucharest'));
        return ['ref' => (string) $c['ref'], 'sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare'], 'suma_bani' => (int) $c['suma_acum'],
            'data' => $la->format('d.m.Y H:i')];
    }

    /** Momentul de după $zile zile lucrătoare (luni-vineri, în ora României), la aceeași oră. */
    public static function dupaZileLucratoare(string $moment, int $zile): string
    {
        $d = (new \DateTimeImmutable('@' . Ceas::dinMoment($moment)))->setTimezone(new \DateTimeZone('Europe/Bucharest'));
        for ($n = 0; $n < $zile;) {
            $d = $d->modify('+1 day');
            if ((int) $d->format('N') <= 5) {
                $n++;
            }
        }
        return Ceas::formateaza($d->getTimestamp());
    }

    // ---- anularea oaspetelui cât plata e așteptată ----

    /**
     * POST /r/{token}/anuleaza pe o rezervare care așteaptă transferul: anularea în EasyCheck, fără rambursare
     * automată. @return string anulata, in_lucru, stare, ocupat
     */
    public function anuleazaOaspete(int $id): string
    {
        $r = $this->app->zavor()->cuZavor('comanda:' . $id, 60, function () use ($id): string {
            $c = $this->app->comenzi()->dupaId($id);
            if ($c === null || $c['fel'] !== self::FEL) {
                return 'stare';
            }
            if (in_array($c['stare'], ['anulata_oaspete', 'anulata_gazda'], true)) {
                return 'anulata';
            }
            if ($c['stare'] !== self::ASTEPTARE) {
                return 'stare';
            }
            if ($c['anulare_actor'] === null) {
                $acum = $this->app->ceas()->moment();
                $n = $this->app->baza()->executa("UPDATE {comenzi} SET anulare_actor = 'oaspete', anulare_spre = 'anulata_oaspete', anulare_suma = 0, anulare_net = 0, anulare_idem = ?, anulare_pornita_la = ?,
                    anulare_incercari = 0, anulare_urmatoarea = ?, anulare_eroare = NULL, actualizat_la = ? WHERE id = ? AND stare = 'transfer_in_asteptare' AND anulare_actor IS NULL",
                    [Client::uuid(), $acum, $this->app->ceas()->moment(self::LACAT), $acum, $id])->rowCount();
                if ($n !== 1) {
                    return 'stare';
                }
                $this->app->masinaStari()->jurnal($id, self::ASTEPTARE, self::ASTEPTARE, 'oaspete', ['anulare' => 'pornita', 'spre' => 'anulata_oaspete']);
            }
            return $this->trimiteAnularea($id, 'oaspete');
        });
        return $r[0] === true ? (string) $r[1] : 'ocupat';
    }

    /** POST /rezervari/{id}/anuleaza cu cheia din marcaj; chemată sub zăvorul comenzii. @return string anulata, in_lucru, stare */
    private function trimiteAnularea(int $id, string $actor): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || $c['stare'] !== self::ASTEPTARE || $c['anulare_actor'] === null) {
            return in_array($c['stare'] ?? '', ['anulata_oaspete', 'anulata_gazda'], true) ? 'anulata' : 'stare';
        }
        $b = $this->app->baza();
        $b->executa('UPDATE {comenzi} SET anulare_incercari = anulare_incercari + 1, anulare_urmatoarea = ? WHERE id = ?', [$this->app->ceas()->moment(self::LACAT), $id]);
        $rezEc = (string) $c['ec_rezervare'];
        try {
            $r = $this->app->ecClient()->scrie('POST', '/rezervari/' . $rezEc . '/anuleaza', null, (string) $c['anulare_idem']);
            // Anunțul `rezervare.schimbata` al aceleiași anulări poate închide comanda cu o clipă înainte: tot anulată.
            return $this->incheieAnularea($id, 'anulata_oaspete', $actor, $r->requestId()) || ($this->app->comenzi()->dupaId($id)['stare'] ?? '') === 'anulata_oaspete' ? 'anulata' : 'stare';
        } catch (EroareApi $e) {
            $cod = $e->cod ?? 'http_' . $e->raspuns->status;
            if ($e->raspuns->status === 409 && $cod === 'stare_invalida') {
                // Deja hotărâtă în EasyCheck (anulată, refuzată sau expirată chiar acum): starea se citește și se aplică.
                $this->urmareste(false, $id, $actor);
                $stare = (string) ($this->app->comenzi()->dupaId($id)['stare'] ?? '');
                return $stare === self::ASTEPTARE ? $this->amanaAnularea($id, $cod, $e->requestId, $actor) : ($stare === 'anulata_oaspete' ? 'anulata' : 'stare');
            }
            $inLucru = $cod === 'idempotency_conflict' && (($e->detalii()['in_lucru'] ?? false) === true);
            if ($inLucru || in_array($e->comportament, ['reincearca', 'pauza'], true) || $e->raspuns->status >= 500 || $e->raspuns->status === 429) {
                return $this->amanaAnularea($id, $cod, $e->requestId, $actor);
            }
            // Refuz definitiv: fără reluări; gazda primește alerta și anulează din EasyCheck (citirea încheie apoi comanda).
            $b->executa('UPDATE {comenzi} SET anulare_urmatoarea = NULL, anulare_eroare = ? WHERE id = ?', [mb_substr($cod, 0, 64), $id]);
            $this->app->masinaStari()->jurnal($id, self::ASTEPTARE, self::ASTEPTARE, $actor, ['anulare' => 'refuzata_ec', 'cod' => $cod, 'request_id' => $e->requestId]);
            $this->app->alertaGazda('alerta_anulare', ['ref' => (string) $c['ref'], 'cod' => $cod, 'sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare']],
                'anulare_refuz:' . $c['ref'] . ':' . $cod, 30 * 86400);
            return 'in_lucru';
        } catch (EroareTransport $e) {
            return $this->amanaAnularea($id, $e->felul, null, $actor);
        }
    }

    private function amanaAnularea(int $id, string $motiv, ?string $requestId, string $actor): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        $n = max(1, (int) $c['anulare_incercari']);
        $pauza = self::REINCERCARI_ANULARE[min($n - 1, count(self::REINCERCARI_ANULARE) - 1)];
        $this->app->baza()->executa('UPDATE {comenzi} SET anulare_urmatoarea = ?, anulare_eroare = ? WHERE id = ?', [$this->app->ceas()->moment($pauza), mb_substr($motiv, 0, 64), $id]);
        $this->app->masinaStari()->jurnal($id, self::ASTEPTARE, self::ASTEPTARE, $actor, ['anulare' => 'amanata', 'motiv' => $motiv, 'reincercare_in' => $pauza, 'request_id' => $requestId]);
        return 'in_lucru';
    }

    /**
     * Anularea confirmată de EasyCheck cât plata era așteptată: tranziția, e-mailul oaspetelui (cu banii trimiși între
     * timp, pe care îi întoarce gazda) și, la anularea oaspetelui, e-mailul gazdei. Momentele `anulata_ec_la` și
     * `email_anulare_la` se scriu odată cu starea, ca sarcina anulărilor (Anulare::ruleaza) să lase comanda în pace.
     */
    private function incheieAnularea(int $id, string $in, string $actor, ?string $requestId): bool
    {
        $acum = $this->app->ceas()->moment();
        if (!$this->app->masinaStari()->trece($id, self::FEL, self::ASTEPTARE, $in, $actor, ['anulata_ec_la' => $acum, 'email_anulare_la' => $acum, 'anulare_urmatoarea' => null, 'anulare_eroare' => null,
            'anulare_spre' => $in, 'verificat_la' => $acum, 'transfer_reamintire_la' => null, 'request_id' => $requestId], ['anulare' => $in === 'anulata_oaspete' ? 'oaspete' : 'easycheck', 'request_id' => $requestId])) {
            return false;
        }
        $this->anunta($id, $in === 'anulata_oaspete' ? 'transfer_anulat' : 'transfer_anulat_gazda');
        if ($in === 'anulata_oaspete') {
            $this->anuntaGazda($id, 'gazda_anulare');
        }
        return true;
    }

    // ---- e-mailurile ----

    /** Pune în coadă e-mailul către oaspete, în limba comenzii. */
    public function anunta(int $id, string $sablon): void
    {
        $c = $this->app->comenzi()->dupaId($id);
        $o = $c !== null ? $this->app->comenzi()->oaspete($c) : [];
        if ($c === null || !isset($o['email'])) {
            return;
        }
        $this->app->emailuri()->pune($id, $sablon, (string) $c['limba'], (string) $o['email'], ['comanda_id' => $id]);
        $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], 'sistem', ['email' => $sablon]);
    }

    /** E-mailul către gazdă (fiecare proprietar activ, în limba panoului lui). */
    private function anuntaGazda(int $id, string $sablon): void
    {
        foreach ($this->app->baza()->toate("SELECT email, limba_panou FROM {utilizatori} WHERE rol = 'proprietar' AND activ = 1 ORDER BY id") as $u) {
            $this->app->emailuri()->pune($id, $sablon, (string) $u['limba_panou'], (string) $u['email'], ['comanda_id' => $id, 'suma_bani' => 0]);
        }
    }

    // ---- textele comune paginilor și e-mailurilor ----

    /** Contul înghețat pe comandă (sau cel de acum, la comenzile fără instantaneu). @return array{titular:string, iban:string, banca:string, swift:string} */
    public function cont(array $c): array
    {
        $k = json_decode((string) ($c['transfer_cont'] ?? ''), true);
        $k = is_array($k) ? $k : $this->app->contTransfer()->instantaneu();
        return ['titular' => (string) ($k['titular'] ?? ''), 'iban' => Iban::curata((string) ($k['iban'] ?? '')), 'banca' => (string) ($k['banca'] ?? ''), 'swift' => (string) ($k['swift'] ?? '')];
    }

    /**
     * Instrucțiunile de plată ale unei comenzi: rândurile etichetă / valoare pentru pagina comenzii, „Rezervarea
     * mea” și e-mailuri: titularul, IBAN-ul, banca, SWIFT-ul (dacă există), suma exactă, termenul în ora României și
     * textul de pus la detaliile plății (codul rezervării).
     * @return list<array{cheie:string, eticheta:string, valoare:string}>
     */
    public function instructiuni(array $c, Traducator $t): array
    {
        $l = $t->limba();
        $k = $this->cont($c);
        [$zi, $ora] = \Cazo\Email\Sabloane::termen($c['transfer_termen'], $l);
        $r = [
            ['cheie' => 'titular', 'eticheta' => $t->t('transfer.titular'), 'valoare' => $k['titular']],
            // `copie`: valoarea pusă în clipboard de butonul „Copiați”: IBAN-ul fără spații, suma ca număr.
            ['cheie' => 'iban', 'eticheta' => $t->t('transfer.iban'), 'valoare' => $k['iban'], 'copie' => (string) preg_replace('~\s+~', '', (string) $k['iban'])],
            ['cheie' => 'banca', 'eticheta' => $t->t('transfer.banca'), 'valoare' => $k['banca']],
            ['cheie' => 'swift', 'eticheta' => $t->t('transfer.swift'), 'valoare' => $k['swift'], 'copie' => (string) $k['swift']],
            ['cheie' => 'suma', 'eticheta' => $t->t('transfer.suma'), 'valoare' => Format::baniOaspete((int) $c['suma_acum'], $l),
                'copie' => intdiv((int) $c['suma_acum'], 100) . ($l === 'ro' ? ',' : '.') . sprintf('%02d', (int) $c['suma_acum'] % 100)],
            ['cheie' => 'termen', 'eticheta' => $t->t('transfer.termen'), 'valoare' => $zi !== '' ? $t->t('transfer.termen_valoare', ['data' => $zi, 'ora' => $ora]) : ''],
            ['cheie' => 'detalii', 'eticheta' => $t->t('transfer.detalii'), 'valoare' => (string) $c['ref'], 'copie' => (string) $c['ref']],
        ];
        return array_values(array_filter($r, static fn (array $x): bool => $x['valoare'] !== ''));
    }

    /** Ce rămâne de plătit la sosire, ca propoziție (sau '' când transferul acoperă tot). */
    public static function textRest(Traducator $t, array $c): string
    {
        return (int) $c['rest_la_sosire'] > 0 ? $t->t('transfer.rest', ['rest' => Format::baniOaspete((int) $c['rest_la_sosire'], $t->limba())]) : '';
    }

    // ---- panoul ----

    /**
     * Starea unei comenzi cu transfer, pentru lista și pagina rezervării din panou: „Așteaptă transferul”,
     * „Plătită prin transfer”, „Transfer neprimit”, „Termen depășit”, cu suma și, cât plata e așteptată, termenul.
     * Null la celelalte comenzi și stări (panoul le arată ca până acum).
     */
    public static function stareAdmin(Traducator $t, array $cm): ?string
    {
        if (($cm['fel'] ?? null) !== self::FEL || !in_array($cm['stare'], [self::ASTEPTARE, self::NEPRIMIT, self::EXPIRAT, 'confirmata'], true)) {
            return null;
        }
        $l = $t->limba();
        $suma = Format::bani((int) $cm['suma_acum'], $l);
        if ($cm['stare'] === self::ASTEPTARE && ($cm['transfer_anuntat_la'] ?? null) !== null) {
            // după „Am plătit”, termenul nu mai curge; panoul spune când a anunțat oaspetele plata.
            return $t->t('admin.transfer.stare.anuntat', ['suma' => $suma, 'data' => Format::moment((string) $cm['transfer_anuntat_la'], $l)]);
        }
        if ($cm['stare'] === self::ASTEPTARE) {
            return $t->t('admin.transfer.stare.asteptare', ['suma' => $suma, 'termen' => $cm['transfer_termen'] !== null ? Format::moment((string) $cm['transfer_termen'], $l) : '-']);
        }
        return $t->t('admin.transfer.stare.' . ($cm['stare'] === 'confirmata' ? 'platita' : ($cm['stare'] === self::NEPRIMIT ? 'neprimit' : 'expirat')), ['suma' => $suma]);
    }
}
