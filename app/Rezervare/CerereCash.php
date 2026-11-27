<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\Baza\Baza;
use Cazo\EasyCheck\Client;
use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\Email\Cutie;
use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Jurnal;
use Cazo\Nucleu\Setari;

defined('CAZO') or exit;

/**
 * Cererea cash: trimiterea POST /cereri și urmărirea răspunsului gazdei.
 *
 * Trimiterea, sub lacătul comenzii (`blocat_pana`, 60 s): `noua` → `trimitere_ec` (o singură dată), apoi POST /cereri
 * cu `Idempotency-Key: idem_ec` și corpul `corp_ec`, octet cu octet. Rezultatele:
 *   201                  → `cerere_in_asteptare`, cu `ec_cerere` și `ec_expira_la`; e-mailul „Am primit cererea”
 *   409 indisponibil     → `indisponibila`, cu zilele în `eroare_detalii`; e-mailul `cerere_indisponibila`
 *   422 (orice cod), 409 idempotency_conflict fără in_lucru, 4xx, 401, 403 → `respinsa_ec`, cu codul și detaliile,
 *                          și e-mailul `cerere_respinsa`, cu textul motivului (401/403 trec și prin protocolul
 *                          licenței, prin observatorul clientului, iar gazda primește alerta `alerta_cheie`)
 *   429, 503, 5xx, timeout, 409 idempotency_conflict cu in_lucru → o reîncercare pe loc, apoi rămâne în
 *                          `trimitere_ec`, cu `urmatoarea_incercare` la 5 s, 30 s, 2, 10, 30 de minute, apoi la 30 de
 *                          minute, aceeași cheie și același corp.
 *   După 6 ore fără răspuns: oaspetele primește o dată e-mailul `cerere_intarziata`, iar trimiterea
 *   continuă cu aceeași cheie, pe care EasyCheck o păstrează 24 de ore: o cerere creată de o încercare al cărei
 *   răspuns s-a pierdut revine la reluare (201, același `cer_...`) și se urmărește apoi ca oricare alta, cât gazda o
 *   poate accepta. Doar la 23 de ore de la prima trimitere (înainte să expire cheia), comanda devine `respinsa_ec` cu
 *   `ec_indisponibil` și e-mailul `cerere_netrimisa`. Ambele termene se numără de la `trimisa_la` (trecerea `noua` →
 *   `trimitere_ec`), nu de la comandă: pe calea cu cod, comanda poate aștepta codul până la o oră.
 * Refuzul văzut pe loc (409/422 chiar la trimitere, din POST /rezerva sau după cod) rămâne doar pe ecran;
 * refuzul venit mai târziu (din cron) trimite oaspetelui e-mailul lui.
 *
 * Urmărirea (anunțul `cerere.*`, cronul la 15 minute și o citire la `expira_la` + 1 minut): GET /cereri/{id}:
 *   acceptata → `confirmata` (cu `ec_rezervare`) și confirmarea pe e-mail, cu .ics și „Rezervarea mea”;
 *   refuzata → `cerere_refuzata`; expirata → `cerere_expirata`; fiecare cu e-mailul ei.
 */
final class CerereCash
{
    public const LACAT = 60;
    public const REINCERCARI = [5, 30, 120, 600, 1800];
    /** După atâtea secunde de la prima trimitere fără răspuns, oaspetele află că cererea întârzie (e-mailul cerere_intarziata). */
    public const RENUNTARE = 6 * 3600;
    /** Renunțarea, numărată tot de la prima trimitere: înainte ca EasyCheck să uite cheia de idempotență (24 de ore), ca reluarea să rămână o căutare. */
    public const RENUNTARE_FINALA = 23 * 3600;
    public const URMARIRE = 900;
    public const ABANDON = 3600;
    public const JOB = 'cerere:urmareste';

    /** @param \Closure(): Client $client */
    public function __construct(
        private Baza $baza,
        private Ceas $ceas,
        private Comenzi $comenzi,
        private MasinaStari $masina,
        private \Closure $client,
        private Cutie $emailuri,
        private Setari $setari,
        private ?Jurnal $jurnal = null,
        /** @var \Closure(string, array, string): void|null alerta către gazdă (Aplicatie::alertaGazda) */
        private ?\Closure $alerta = null,
        /** @var \Closure(int): void|null după `confirmata` (Rezervare\Anulare::dupaConfirmare) */
        private ?\Closure $dupaConfirmare = null,
        /** @var \Closure(int): void|null o cerere nouă în așteptare (fără cheie EasyCheck, e-mailul către gazdă) */
        private ?\Closure $laCerereNoua = null,
    ) {
    }

    /**
     * Trimite cererea (din POST /rezerva, după cod sau din cron). Întoarce starea comenzii după încercare.
     */
    public function trimite(int $id, string $actor, bool $inline = true): string
    {
        $c = $this->comenzi->dupaId($id);
        if ($c === null || $c['fel'] !== 'cash') {
            throw new \InvalidArgumentException('Comandă cash necunoscută.');
        }
        $blocat = $this->ceas->moment(self::LACAT);
        if ($c['stare'] === 'noua') {
            // `trimisa_la`: prima trimitere, de la care se numără întârzierea și renunțarea (cheia trăiește de acum).
            $trimisa = $this->ceas->moment();
            if (!$this->masina->trece($id, 'cash', 'noua', 'trimitere_ec', $actor, ['blocat_pana' => $blocat, 'incercari' => (int) $c['incercari'] + 1, 'trimisa_la' => $trimisa])) {
                return (string) $this->comenzi->dupaId($id)['stare'];
            }
            $c['trimisa_la'] = $trimisa;
        } elseif ($c['stare'] === 'trimitere_ec') {
            $acum = $this->ceas->moment();
            $n = $this->baza->executa("UPDATE {comenzi} SET blocat_pana = ?, incercari = incercari + 1 WHERE id = ? AND stare = 'trimitere_ec'
                AND (blocat_pana IS NULL OR blocat_pana <= ?)", [$blocat, $id, $acum])->rowCount();
            if ($n !== 1) {
                return 'trimitere_ec';
            }
        } else {
            return (string) $c['stare'];
        }
        $corp = $this->comenzi->corpEcDin($c);
        for ($incercare = 0; ; $incercare++) {
            try {
                $r = ($this->client)()->scrie('POST', '/cereri', $corp, (string) $c['idem_ec']);
                return $this->creata($c, $r->json() ?? [], $r->requestId(), $actor);
            } catch (EroareApi $e) {
                // 409 idempotency_conflict cu in_lucru: cererea e încă în lucru la EasyCheck; se reia cu aceeași cheie.
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

    private function creata(array $c, array $j, ?string $requestId, string $actor): string
    {
        $cerere = is_string($j['id'] ?? null) && preg_match('~^cer_[0-9A-Za-z]{1,64}$~', $j['id']) ? $j['id'] : null;
        $expira = self::moment($j['expira_la'] ?? null);
        if ($cerere === null) {
            return $this->amana($c, 'forma', $requestId, $actor);
        }
        $ok = $this->masina->trece((int) $c['id'], 'cash', 'trimitere_ec', 'cerere_in_asteptare', $actor, [
            'ec_cerere' => $cerere, 'ec_expira_la' => $expira, 'blocat_pana' => null, 'urmatoarea_incercare' => null, 'verificat_la' => $this->ceas->moment(),
            'eroare_cod' => null, 'eroare_detalii' => null, 'request_id' => $requestId,
        ], ['request_id' => $requestId, 'cerere' => $cerere]);
        if ($ok) {
            if ($expira !== null) {
                // Termenul gazdei (2, 12, 24 sau 48 de ore) se ține minte pentru textul din comandă.
                $ore = (int) round((Ceas::dinMoment($expira) - $this->ceas->acum()) / 3600);
                if ($ore >= 1 && $ore <= 72) {
                    $this->setari->pune('cereri.termen_ore', $ore);
                }
            }
            $this->anunta((int) $c['id'], 'cerere_primita');
            if ($this->laCerereNoua !== null) {
                ($this->laCerereNoua)((int) $c['id']);
            }
        }
        return 'cerere_in_asteptare';
    }

    private function refuzata(array $c, EroareApi $e, string $actor, bool $peLoc = false): string
    {
        $in = $e->raspuns->status === 409 && $e->cod === 'indisponibil' ? 'indisponibila' : 'respinsa_ec';
        $ok = $this->masina->trece((int) $c['id'], 'cash', 'trimitere_ec', $in, $actor, [
            'blocat_pana' => null, 'urmatoarea_incercare' => null, 'eroare_cod' => $e->cod ?? 'http_' . $e->raspuns->status,
            'eroare_detalii' => json_encode($e->detalii() + (in_array($e->raspuns->status, [401, 403], true) ? ['_status' => $e->raspuns->status] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'request_id' => $e->requestId,
        ], ['request_id' => $e->requestId, 'cod' => $e->cod, 'status' => $e->raspuns->status]);
        if ($in === 'respinsa_ec' && !in_array($e->cod, ['restrictie', 'pret_diferit'], true)) {
            // O eroare de program sau de cheie: alerta tehnică ajunge în jurnal (panoul o arată într-o versiune următoare).
            $this->jurnal?->eroare('Cererea cash respinsă de EasyCheck', ['ref' => $c['ref'], 'status' => $e->raspuns->status, 'cod' => $e->cod, 'request_id' => $e->requestId]);
        }
        if ($ok) {
            // Refuzul văzut pe loc (409/422 chiar la trimitere) rămâne doar pe ecran; e-mailul pleacă doar când refuzul
            // vine mai târziu, din cron (după 5xx sau timeout).
            if (!$peLoc) {
                $this->anunta((int) $c['id'], $in === 'indisponibila' ? 'cerere_indisponibila' : 'cerere_respinsa');
            }
            if (in_array($e->raspuns->status, [401, 403], true) && $this->alerta !== null) {
                // Cheia site-ului: gazda primește alerta (oaspetele vede un text neutru, cu contactul gazdei).
                ($this->alerta)('alerta_cheie', ['ref' => (string) $c['ref'], 'cod' => (string) ($e->cod ?? 'http_' . $e->raspuns->status)], 'cheie:' . ($e->cod ?? $e->raspuns->status));
            }
        }
        return $in;
    }

    private function amana(array $c, string $motiv, ?string $requestId, string $actor): string
    {
        $id = (int) $c['id'];
        // Vârsta se numără de la prima trimitere (pe calea cu cod, comanda poate sta `noua` până la o oră înainte);
        // comenzile de dinaintea coloanei, de la creare.
        $start = Ceas::dinMoment((string) ($c['trimisa_la'] ?? $c['creat_la']));
        $varsta = $this->ceas->acum() - $start;
        if ($varsta >= self::RENUNTARE_FINALA) {
            $ok = $this->masina->trece($id, 'cash', 'trimitere_ec', 'respinsa_ec', $actor, ['blocat_pana' => null, 'urmatoarea_incercare' => null,
                'eroare_cod' => 'ec_indisponibil', 'request_id' => $requestId], ['motiv' => $motiv, 'request_id' => $requestId]);
            if ($ok) {
                $this->anunta($id, 'cerere_netrimisa');
            }
            return 'respinsa_ec';
        }
        if ($varsta >= self::RENUNTARE && ($c['intarziata_la'] ?? null) === null) {
            // O singură dată: cererea întârzie; trimiterea continuă cu aceeași cheie până la RENUNTARE_FINALA.
            $n = $this->baza->executa("UPDATE {comenzi} SET intarziata_la = ? WHERE id = ? AND intarziata_la IS NULL AND stare = 'trimitere_ec'", [$this->ceas->moment(), $id])->rowCount();
            if ($n === 1) {
                $this->anunta($id, 'cerere_intarziata', ['pana_la' => Ceas::formateaza($start + self::RENUNTARE_FINALA)]);
            }
        }
        $incercari = max(1, (int) ($this->comenzi->dupaId($id)['incercari'] ?? 1));
        $pauza = self::REINCERCARI[min($incercari - 1, count(self::REINCERCARI) - 1)];
        $this->baza->executa("UPDATE {comenzi} SET blocat_pana = NULL, urmatoarea_incercare = ?, eroare_cod = ?, request_id = ?, actualizat_la = ? WHERE id = ? AND stare = 'trimitere_ec'",
            [$this->ceas->moment($pauza), mb_substr($motiv, 0, 64), $requestId, $this->ceas->moment(), $id]);
        $this->masina->jurnal($id, 'trimitere_ec', 'trimitere_ec', $actor, ['reincercare_in' => $pauza, 'motiv' => $motiv, 'request_id' => $requestId]);
        return 'trimitere_ec';
    }

    /** Comenzile cash de retrimis acum (cronul, prioritatea 2). @return list<int> */
    public function deRetrimis(int $cate = 10): array
    {
        $acum = $this->ceas->moment();
        return array_map('intval', $this->baza->executa("SELECT id FROM {comenzi} WHERE fel = 'cash' AND stare = 'trimitere_ec' AND (blocat_pana IS NULL OR blocat_pana <= ?)
            AND (urmatoarea_incercare IS NULL OR urmatoarea_incercare <= ?) ORDER BY urmatoarea_incercare, id LIMIT " . max(1, $cate), [$acum, $acum])->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Citește cererile în așteptare din EasyCheck. $toate: după un anunț `cerere.*` (anunțul nu poartă id-ul), toate;
     * altfel doar cele scadente (citite acum peste 15 minute, sau ajunse la `expira_la` + 1 minut).
     * @return array{citite:int, schimbate:int, erori:int}
     */
    public function urmareste(bool $toate = false, ?int $doarId = null, string $actor = 'cron'): array
    {
        $acum = $this->ceas->acum();
        $sql = "SELECT * FROM {comenzi} WHERE fel = 'cash' AND stare = 'cerere_in_asteptare' AND ec_cerere IS NOT NULL";
        $p = [];
        if ($doarId !== null) {
            $sql .= ' AND id = ?';
            $p[] = $doarId;
        } elseif (!$toate) {
            $sql .= ' AND (verificat_la IS NULL OR verificat_la <= ? OR (ec_expira_la <= ? AND verificat_la < ec_expira_la))';
            $p[] = Ceas::formateaza($acum - self::URMARIRE);
            $p[] = Ceas::formateaza($acum - 60);
        }
        $rez = ['citite' => 0, 'schimbate' => 0, 'erori' => 0];
        foreach ($this->baza->toate($sql . ' ORDER BY id LIMIT 50', $p) as $c) {
            try {
                $r = ($this->client)()->citeste('/cereri/' . $c['ec_cerere']);
                $rez['citite']++;
                $rez['schimbate'] += $this->aplica($c, $r->json() ?? [], $r->requestId(), $actor) ? 1 : 0;
            } catch (EroareApi $e) {
                $rez['erori']++;
                if ($e->raspuns->status === 404 && $c['ec_expira_la'] !== null && (string) $c['ec_expira_la'] <= Ceas::formateaza($acum)) {
                    // Cererea a ieșit din API (după 30 de zile de la răspuns) și termenul ei trecuse.
                    $rez['schimbate'] += $this->aplica($c, ['stare' => 'expirata'], $e->requestId, $actor) ? 1 : 0;
                } else {
                    $this->baza->executa('UPDATE {comenzi} SET verificat_la = ? WHERE id = ?', [Ceas::formateaza($acum), $c['id']]);
                }
            } catch (EroareTransport) {
                $rez['erori']++;
                break;
            }
        }
        return $rez;
    }

    private function aplica(array $c, array $j, ?string $requestId, string $actor): bool
    {
        $id = (int) $c['id'];
        $stare = (string) ($j['stare'] ?? '');
        $d = ['request_id' => $requestId];
        $campuri = ['verificat_la' => $this->ceas->moment(), 'request_id' => $requestId];
        if ($stare === 'acceptata') {
            $rez = is_string($j['rezervare'] ?? null) && preg_match('~^rez_[0-9A-Za-z]{1,64}$~', $j['rezervare']) ? $j['rezervare'] : null;
            if ($this->masina->trece($id, 'cash', 'cerere_in_asteptare', 'confirmata', $actor, $campuri + ['ec_rezervare' => $rez], $d + ['rezervare' => $rez])) {
                $this->anunta($id, 'cerere_acceptata');
                if ($this->dupaConfirmare !== null) {
                    ($this->dupaConfirmare)($id);
                }
                return true;
            }
            return false;
        }
        if ($stare === 'refuzata' || $stare === 'expirata') {
            $in = $stare === 'refuzata' ? 'cerere_refuzata' : 'cerere_expirata';
            if ($this->masina->trece($id, 'cash', 'cerere_in_asteptare', $in, $actor, $campuri, $d)) {
                $this->anunta($id, $stare === 'refuzata' ? 'cerere_refuzata' : 'cerere_expirata');
                return true;
            }
            return false;
        }
        $expira = self::moment($j['expira_la'] ?? null);
        $this->baza->executa('UPDATE {comenzi} SET verificat_la = ?, ec_expira_la = COALESCE(?, ec_expira_la) WHERE id = ?', [$this->ceas->moment(), $expira, $id]);
        return false;
    }

    /** Comenzile cash rămase `noua` (codul neconfirmat) peste o oră devin `abandonata`; codul expiră odată cu ele. */
    public function abandoneaza(): int
    {
        $n = 0;
        foreach ($this->baza->toate("SELECT id FROM {comenzi} WHERE fel = 'cash' AND stare = 'noua' AND creat_la < ?", [$this->ceas->moment(-self::ABANDON)]) as $r) {
            $n += $this->masina->trece((int) $r['id'], 'cash', 'noua', 'abandonata', 'cron') ? 1 : 0;
        }
        return $n;
    }

    /** Pune în coadă e-mailul către oaspete, în limba comenzii. */
    public function anunta(int $id, string $sablon, array $date = []): void
    {
        $c = $this->comenzi->dupaId($id);
        $o = $c !== null ? $this->comenzi->oaspete($c) : [];
        if ($c === null || !isset($o['email'])) {
            return;
        }
        $this->emailuri->pune($id, $sablon, (string) $c['limba'], (string) $o['email'], ['comanda_id' => $id] + $date);
        $this->masina->jurnal($id, (string) $c['stare'], (string) $c['stare'], 'sistem', ['email' => $sablon]);
    }

    /** Un moment ISO din API („2026-11-13T08:00:00Z”) în formatul bazei, sau null. */
    public static function moment(mixed $iso): ?string
    {
        if (!is_string($iso) || !preg_match('~^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.\d+)?Z$~', $iso, $m)) {
            return null;
        }
        return $m[1] . ' ' . $m[2];
    }
}
