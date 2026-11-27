<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Finalizarea unei comenzi plătite: aceeași funcție pentru anunțul Stripe,
 * întoarcerea browserului și cron.
 *
 *  1. a doua verificare proaspătă a disponibilității (GET /disponibilitate pe interval), doar înaintea primei
 *     trimiteri (după ea, o rezervare creată cu răspunsul pierdut ar ocupa chiar ea noaptea): o noapte ocupată duce
 *     la rambursarea integrală automată, cu camerele libere pentru aceleași date;
 *  2. lacătul: `platita` → `trimitere_ec` cu `blocat_pana` = acum + 60 s (UPDATE ... WHERE stare = 'platita');
 *     zero rânduri = alt proces lucrează, ieșire liniștită;
 *  3. POST /rezervari cu `Idempotency-Key: idem_ec` și corpul înghețat la plată (`corp_ec`, cu `plata`);
 *  4. rezultatul: 201 → `confirmata` (cu `suprapunere`), e-mailul oaspetelui și cel al gazdei; 429, 503,
 *     5xx, timeout, 409 în lucru → înapoi în `platita`, cu reluarea la 5 s, 30 s, 2, 10, 30 de minute, apoi la
 *     30 de minute; orice alt refuz (422, 409 fără in_lucru, 401, 403) → `de_rambursat` și rambursarea integrală;
 *  5. după 6 ore de la prima trimitere fără răspuns: Recuperare (căutarea după `ref_site`).
 */
final class Finalizare
{
    public const LACAT = 60;
    public const REINCERCARI = [5, 30, 120, 600, 1800];
    public const RECUPERARE = 6 * 3600;
    /** Refuzurile care arată o eroare de program: alertă tehnică la gazdă. */
    public const TEHNICE = ['avans_insuficient', 'plan_lipsa', 'plan_diferit', 'validare', 'idempotency_conflict'];

    public function __construct(private Aplicatie $app)
    {
    }

    public function ruleaza(int $id, string $actor): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || $c['fel'] !== 'card' || $c['stare'] !== 'platita') {
            return (string) ($c['stare'] ?? '');
        }
        $acum = $this->app->ceas()->acum();
        // plata cu reținere se rezolvă în 10 minute de la plată; după ele, comanda e refuzată și suma eliberată.
        if ($this->app->retinere()->depasita($c)) {
            return $this->app->retinere()->expira($c, 'platita', $actor);
        }
        if ($c['trimisa_la'] !== null && $acum - Ceas::dinMoment((string) $c['trimisa_la']) >= self::RECUPERARE) {
            return $this->app->recuperare()->verifica($c, $actor);
        }
        // Lacătul întâi (UPDATE ... WHERE stare = 'platita'), apoi a doua verificare, doar la prima trimitere: așa două
        // procese care citiseră amândouă comanda netrimisă nu verifică și nu trimit pe rând (cursa dintre citire și lacăt).
        $acumM = $this->app->ceas()->moment();
        $n = $this->app->baza()->executa("UPDATE {comenzi} SET stare = 'trimitere_ec', blocat_pana = ?, incercari = incercari + 1, trimisa_la = COALESCE(trimisa_la, ?), actualizat_la = ?
            WHERE id = ? AND stare = 'platita' AND fel = 'card'", [$this->app->ceas()->moment(self::LACAT), $acumM, $acumM, $id])->rowCount();
        if ($n !== 1) {
            return (string) $this->app->comenzi()->dupaId($id)['stare'];
        }
        $c = $this->app->comenzi()->dupaId($id);
        $this->app->masinaStari()->jurnal($id, 'platita', 'trimitere_ec', $actor, ['incercare' => (int) $c['incercari']]);
        if ((int) $c['incercari'] === 1) {
            $ocupate = $this->noptiOcupate($c);
            if ($ocupate !== []) {
                if ($this->app->masinaStari()->trece($id, 'card', 'trimitere_ec', 'de_rambursat', $actor, ['blocat_pana' => null, 'eroare_cod' => 'noapte_ocupata',
                    'eroare_detalii' => json_encode(['zile' => $ocupate]), 'motiv_rambursare' => 'noapte_ocupata'], ['zile' => $ocupate])) {
                    $this->app->plataOnline()->dupaRefuz($id);
                }
                return (string) $this->app->comenzi()->dupaId($id)['stare'];
            }
        }
        $corp = $this->app->comenzi()->corpEcDin($c);
        // rezervarea e a cheii care trimite cererea acum (gazda o poate schimba între comandă și plată).
        $prefix = \Cazo\EasyCheck\Conectare::prefix($this->app->cheieEc());
        try {
            $r = $this->app->ecClient()->scrie('POST', '/rezervari', $corp, (string) $c['idem_ec']);
            return $this->creata($c, $r->json() ?? [], $r->requestId(), $actor, $prefix);
        } catch (EroareApi $e) {
            $inLucru = $e->cod === 'idempotency_conflict' && (($e->detalii()['in_lucru'] ?? false) === true);
            if ($inLucru || in_array($e->comportament, ['reincearca', 'pauza'], true) || $e->raspuns->status >= 500 || $e->raspuns->status === 429) {
                return $this->amana($id, $e->cod ?? 'http_' . $e->raspuns->status, $e->requestId, $actor);
            }
            return $this->refuzata($c, $e, $actor);
        } catch (EroareTransport $e) {
            return $this->amana($id, $e->felul, null, $actor);
        }
    }

    private function creata(array $c, array $j, ?string $requestId, string $actor, ?string $prefix = null): string
    {
        $id = (int) $c['id'];
        $rez = is_string($j['id'] ?? null) && preg_match('~^rez_[0-9A-Za-z]{1,64}$~', $j['id']) ? $j['id'] : null;
        if ($rez === null) {
            return $this->amana($id, 'forma', $requestId, $actor);
        }
        $supra = ($j['suprapunere'] ?? false) === true;
        // rezervarea există; suma autorizată se capturează (PlataOnline::captureaza), apoi comanda e confirmată.
        if ($this->app->masinaStari()->trece($id, 'card', 'trimitere_ec', 'de_capturat', $actor, ['ec_rezervare' => $rez, 'suprapunere' => $supra, 'blocat_pana' => null,
            'urmatoarea_incercare' => null, 'eroare_cod' => null, 'eroare_detalii' => null, 'request_id' => $requestId] + ($prefix !== null ? ['cheie_prefix' => $prefix] : []),
            ['rezervare' => $rez, 'suprapunere' => $supra, 'request_id' => $requestId])) {
            return $this->app->plataOnline()->captureaza($id, $actor);
        }
        return (string) $this->app->comenzi()->dupaId($id)['stare'];
    }

    /**
     * E-mailurile unei rezervări plătite confirmate: confirmarea către oaspete și e-mailul scurt către gazdă; apoi
     * rezervarea se citește o dată din EasyCheck, pentru o anulare a gazdei făcută înainte ca site-ul să afle.
     */
    public function confirmata(int $id): void
    {
        $this->app->plataOnline()->anunta($id, 'plata_confirmata');
        $this->app->plataOnline()->anuntaGazda($id);
        $this->app->anulare()->dupaConfirmare($id);
    }

    private function amana(int $id, string $motiv, ?string $requestId, string $actor): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        $n = max(1, (int) $c['incercari']);
        $pauza = self::REINCERCARI[min($n - 1, count(self::REINCERCARI) - 1)];
        // reluările încap în fereastra de 10 minute; la sfârșitul ei, cronul refuză comanda (Retinere::expira).
        $urm = $this->app->ceas()->acum() + $pauza;
        $termen = $this->app->retinere()->termen($c);
        if ($termen !== null) {
            $urm = min($urm, $termen);
        }
        $this->app->masinaStari()->trece($id, 'card', 'trimitere_ec', 'platita', $actor, ['blocat_pana' => null, 'urmatoarea_incercare' => Ceas::formateaza($urm),
            'eroare_cod' => mb_substr($motiv, 0, 64), 'request_id' => $requestId], ['reincercare_in' => $pauza, 'motiv' => $motiv, 'request_id' => $requestId]);
        return 'platita';
    }

    private function refuzata(array $c, EroareApi $e, string $actor): string
    {
        $id = (int) $c['id'];
        $status = $e->raspuns->status;
        $cod = $e->cod ?? 'http_' . $status;
        $detalii = $e->detalii() + (in_array($status, [401, 403], true) ? ['_status' => $status] : []);
        if (!$this->app->masinaStari()->trece($id, 'card', 'trimitere_ec', 'de_rambursat', $actor, ['blocat_pana' => null, 'urmatoarea_incercare' => null, 'eroare_cod' => $cod,
            'eroare_detalii' => json_encode($detalii, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'request_id' => $e->requestId, 'motiv_rambursare' => 'respinsa_ec'],
            ['request_id' => $e->requestId, 'cod' => $cod, 'status' => $status])) {
            return (string) $this->app->comenzi()->dupaId($id)['stare'];
        }
        if (in_array($status, [401, 403], true)) {
            $this->app->alertaGazda('alerta_cheie', ['ref' => (string) $c['ref'], 'cod' => $cod], 'cheie:' . $cod, 86400);
        } elseif (!in_array($cod, ['pret_diferit', 'restrictie'], true)) {
            $this->app->jurnal()->eroare('Comanda plătită respinsă de EasyCheck', ['ref' => $c['ref'], 'status' => $status, 'cod' => $cod, 'request_id' => $e->requestId]);
            $this->app->alertaGazda('alerta_comanda', ['ref' => (string) $c['ref'], 'cod' => $cod, 'request_id' => (string) ($e->requestId ?? '-')], 'comanda:' . $cod);
        }
        $this->app->plataOnline()->dupaRefuz($id);
        return (string) $this->app->comenzi()->dupaId($id)['stare'];
    }

    /** @return list<string> nopțile sejurului fără unitate liberă (sau închise); [] la o eroare (trimiterea merge) */
    private function noptiOcupate(array $c): array
    {
        try {
            $r = $this->app->ecClient()->citeste('/disponibilitate', ['de' => (string) $c['sosire'], 'pana' => (string) $c['plecare']]);
        } catch (EroareApi|EroareTransport) {
            return [];
        }
        $cerute = array_count_values(array_column($this->app->comenzi()->camere($c), 'camera'));
        $pline = [];
        foreach ((array) ($r->json()['camere'] ?? []) as $cam) {
            $n = $cerute[$cam['id'] ?? ''] ?? 0;
            foreach ($n > 0 ? (array) ($cam['nopti'] ?? []) : [] as $z) {
                if (is_array($z) && is_string($z['zi'] ?? null) && $z['zi'] >= $c['sosire'] && $z['zi'] < $c['plecare'] && (!empty($z['inchis']) || (int) ($z['libere'] ?? 0) < $n)) {
                    $pline[$z['zi']] = true;
                }
            }
        }
        $z = array_keys($pline);
        sort($z);
        return $z;
    }
}
