<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\Nucleu\Aplicatie;
use Cazo\Plati\Checkout;
use Cazo\Plati\EroareStripe;
use Cazo\Pret\RegulaPlata;

defined('CAZO') or exit;

/**
 * Plata online a unei comenzi, pe ramura `card` a mașinii de stări.
 *
 *   porneste      `noua` → `plata_in_curs`: sesiunea Checkout pentru suma de acum (avansul sau tot)
 *   confirma      sesiunea citită mereu din API (anunțul, întoarcerea browserului și reconcilierea cheamă aceeași
 *                 funcție): plătită → `platita` (apoi Finalizare); confirmare întârziată → `plata_in_procesare`
 *                 (pagina de stare și e-mailul); refuzată → `plata_esuata`; expirată → `abandonata`; plătită cu altă sumă
 *                 → `de_rambursat`
 *   renunta       expiră sesiunea, apoi `abandonata`; o sesiune deja plătită merge pe confirmare
 *   ramburseaza   `de_rambursat` → `rambursata` abia când plata e rambursată integral (pe orice cale), cu reluările
 *                 din Plati\Rambursari; după ultima reluare eșuată, gazda primește alerta (o singură dată); o
 *                 rambursare confirmată care eșuează după aceea readuce comanda din `rambursata` în `de_rambursat`
 *   ruleaza       cronul: plățile în curs de peste 2 minute, cele în procesare, comenzile plătite de
 *                 trimis, trimiterile cu lacătul expirat, capturile și rambursările în lucru
 *
 * Plata cu reținere: suma doar se autorizează la plată (`platita`, cu plata `autorizata`); după 201 la
 * POST /rezervari, comanda trece în `de_capturat`, iar captureaza() încasează suma și o confirmă. La un refuz înainte de
 * confirmare (`de_rambursat`), ramburseaza() eliberează autorizarea, fără rambursare și fără comision:
 * `autorizare_anulata`. Totul în fereastra de 10 minute de la plată (Retinere). Un PaymentIntent găsit deja încasat
 * (capturat din Dashboard) urmează calea încasării, cu rambursarea la refuz.
 */
final class PlataOnline
{
    public const RECONCILIERE = 120;
    public const IN_PROCESARE = 900;
    /** Citirile la rând cu sesiunea lipsă din Stripe, după care gazda primește alerta. */
    public const LIPSA_ALERTA = 3;
    /** Reluările capturii, ca la trimiterea spre EasyCheck. */
    public const REINCERCARI_CAPTURA = [5, 30, 120, 600, 1800];
    /** O eliberare care tot eșuează de atâtea secunde aduce alerta gazdei. */
    public const ELIBERARE_ALERTA = 86400;

    public function __construct(private Aplicatie $app)
    {
    }

    /**
     * @return ?string adresa paginii de plată (Checkout); null când comanda a plecat din `noua` (de exemplu cronul a
     * abandonat-o între timp): atunci sesiunea creată expiră pe loc, iar oaspetele nu primește nicio adresă de plată.
     */
    public function porneste(int $id, string $produs): ?string
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || $c['fel'] !== 'card' || $c['stare'] !== 'noua') {
            return null;
        }
        $o = $this->app->comenzi()->oaspete($c);
        $limba = (string) $c['limba'];
        $url = $this->app->url();
        try {
            $s = $this->app->checkout()->creeaza([
                'comanda_id' => $id, 'ref' => (string) $c['ref'], 'suma' => (int) $c['suma_acum'], 'produs' => $produs, 'email' => (string) ($o['email'] ?? ''),
                'limba' => $limba, 'proba' => (int) $c['proba'] === 1,
                'succes' => $url->absolut('/plata/intoarcere', $limba, ['c' => (string) $c['ref']]) . '&s={CHECKOUT_SESSION_ID}',
                'renuntare' => $url->absolut('/plata/renuntare', $limba, ['c' => (string) $c['ref']]),
            ]);
        } catch (EroareStripe $e) {
            $this->app->masinaStari()->trece($id, 'card', 'noua', 'abandonata', 'sistem', ['eroare_cod' => 'stripe_' . ($e->cod ?? $e->felul)], ['stripe' => $e->getMessage()]);
            $this->app->jurnal()->eroare('Sesiunea Checkout n-a pornit', ['ref' => $c['ref'], 'eroare' => $e->getMessage()]);
            throw $e;
        }
        if (!$this->app->masinaStari()->trece($id, 'card', 'noua', 'plata_in_curs', 'oaspete', [], ['sesiune' => $s['id']])) {
            // Alt proces a mutat comanda cât se crea sesiunea: sesiunea se închide, ca nicio plată să nu ajungă pe o
            // comandă încheiată.
            try {
                $this->app->checkout()->expira((string) $s['id']);
            } catch (EroareStripe $e) {
                $this->app->jurnal()->eroare('Sesiunea Checkout a unei comenzi încheiate n-a expirat', ['ref' => $c['ref'], 'eroare' => $e->getMessage()]);
            }
            $this->app->baza()->executa("UPDATE {plati} SET stare = 'expirata', actualizat_la = ? WHERE comanda_id = ? AND stripe_sesiune = ?",
                [$this->app->ceas()->moment(), $id, (string) $s['id']]);
            $this->app->masinaStari()->jurnal($id, null, (string) ($this->app->comenzi()->dupaId($id)['stare'] ?? ''), 'sistem', ['sesiune' => $s['id'], 'expirata' => 'comanda_mutata']);
            return null;
        }
        return $s['url'];
    }

    /** Citește sesiunea curentă a comenzii din Stripe și aplică rezultatul. Întoarce starea comenzii după. */
    public function confirma(int $id, string $actor): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || $c['fel'] !== 'card' || !in_array($c['stare'], ['plata_in_curs', 'plata_in_procesare'], true)) {
            return (string) ($c['stare'] ?? '');
        }
        $p = $this->app->checkout()->ultima($id);
        if ($p === null || $p['stripe_sesiune'] === null) {
            return (string) $c['stare'];
        }
        try {
            $s = $this->app->checkout()->citeste((string) $p['stripe_sesiune']);
        } catch (EroareStripe $e) {
            $this->app->masinaStari()->jurnal($id, (string) $c['stare'], (string) $c['stare'], $actor, ['stripe' => $e->felul . ':' . ($e->cod ?? $e->status)]);
            // Sesiunea lipsă (de obicei cheia schimbată pe alt cont Stripe): după 3 citiri la rând, gazda află.
            if ($e->cod === 'resource_missing' && $this->app->limitator()->inregistreaza('stripe_lipsa:' . $id, 86400) >= self::LIPSA_ALERTA) {
                $this->app->alertaGazda('alerta_plata_lipsa', ['ref' => (string) $c['ref']], 'plata_lipsa:' . $c['ref'], 86400);
            }
            return (string) $c['stare'];
        }
        $i = Checkout::interpreteaza($s, (int) $c['suma_acum']);
        $din = (string) $c['stare'];
        $acum = $this->app->ceas()->moment();
        $masina = $this->app->masinaStari();
        switch ($i['stare']) {
            case 'platita':
            case 'autorizata':
            case 'nepotrivita':
                // Garda: doar plata încă deschisă se scrie; o citire veche (întoarcerea după anunț și captură) lasă neatinsă o
                // plată deja autorizată, capturată sau eliberată.
                if ($i['autorizata']) {
                    // suma doar reținută pe card; se încasează după confirmarea EasyCheck (captureaza).
                    $this->app->baza()->executa("UPDATE {plati} SET stare = 'autorizata', stripe_pi = ?, suma_autorizata = ?, capturare_inainte = ?, metoda = ?, metoda_scurt = ?, actualizat_la = ? WHERE id = ? AND stare IN ('sesiune', 'in_procesare')",
                        [$i['pi'], $i['suma'], $i['capturare_inainte'] !== null ? \Cazo\Nucleu\Ceas::formateaza($i['capturare_inainte']) : null, $i['metoda'], $i['metoda_scurt'], $acum, $p['id']]);
                } else {
                    $this->app->baza()->executa("UPDATE {plati} SET stare = 'incasata', stripe_pi = ?, suma_incasata = ?, metoda = ?, metoda_scurt = ?, capturata_la = ?, actualizat_la = ? WHERE id = ? AND stare IN ('sesiune', 'in_procesare')",
                        [$i['pi'], $i['suma'], $i['metoda'], $i['metoda_scurt'], $acum, $acum, $p['id']]);
                }
                if ($i['stare'] === 'nepotrivita') {
                    if ($masina->trece($id, 'card', $din, 'de_rambursat', $actor, ['platita_la' => $acum, 'eroare_cod' => 'suma_diferita', 'motiv_rambursare' => 'suma_diferita'],
                        ['pi' => $i['pi'], 'suma' => $i['suma']])) {
                        $this->app->jurnal()->eroare('Plată încasată cu altă sumă decât comanda', ['ref' => $c['ref'], 'suma' => $i['suma'], 'asteptat' => (int) $c['suma_acum']]);
                        $this->dupaRefuz($id);
                    }
                    break;
                }
                $corp = json_decode($this->app->comenzi()->corpEcDin($c), true, 32, JSON_THROW_ON_ERROR);
                $corp['plata'] = ['suma' => (int) $c['suma_acum'], 'ref' => mb_substr((string) $i['pi'], 0, 100), 'metoda' => 'card'];
                // partea din plată care e taxă locală (toată taxa la „online”, 0 la „la_sosire”).
                $taxa = RegulaPlata::taxaOnline($c['taxa_incasare'] ?? null, RegulaPlata::taxa($this->app->comenzi()->componente($c)), (int) $c['suma_acum'], (int) $c['total']);
                if ($taxa !== null) {
                    $corp['plata']['taxa_locala'] = $taxa;
                }
                $masina->trece($id, 'card', $din, 'platita', $actor, ['platita_la' => $acum, 'corp_ec' => $this->app->comenzi()->cripteaza(Comenzi::json($corp)),
                    'urmatoarea_incercare' => null, 'eroare_cod' => null], ['pi' => $i['pi'], 'metoda' => $i['metoda']]);
                break;
            case 'in_procesare':
                if ($din === 'plata_in_curs') {
                    $this->app->baza()->executa("UPDATE {plati} SET stare = 'in_procesare', stripe_pi = ?, metoda = ?, actualizat_la = ? WHERE id = ? AND stare IN ('sesiune', 'in_procesare')", [$i['pi'], $i['metoda'], $acum, $p['id']]);
                    if ($masina->trece($id, 'card', $din, 'plata_in_procesare', $actor, ['verificat_la' => $acum], ['pi' => $i['pi'], 'metoda' => $i['metoda']])) {
                        $this->anunta($id, 'plata_in_procesare');
                    }
                } else {
                    $this->app->baza()->executa('UPDATE {comenzi} SET verificat_la = ? WHERE id = ?', [$acum, $id]);
                }
                break;
            case 'esuata':
                $this->app->baza()->executa("UPDATE {plati} SET stare = 'esuata', stripe_pi = ?, actualizat_la = ? WHERE id = ? AND stare IN ('sesiune', 'in_procesare')", [$i['pi'], $acum, $p['id']]);
                if ($masina->trece($id, 'card', $din, 'plata_esuata', $actor, [], ['pi' => $i['pi']])) {
                    $this->anunta($id, 'plata_esuata');
                    // Eșecul acestui PaymentIntent a numărat deja dacă a venit anunțul payment_failed.
                    if ($i['pi'] === null || $this->app->limitator()->numar('pi_esec:' . $i['pi'], 86400) === 0) {
                        $this->numaraEsec($i['pi'] !== null ? 'pi:' . $i['pi'] : 'c:' . $id, $i['pi']);
                    }
                }
                break;
            case 'expirata':
                $this->app->baza()->executa("UPDATE {plati} SET stare = 'expirata', actualizat_la = ? WHERE id = ? AND stare IN ('sesiune', 'in_procesare')", [$acum, $p['id']]);
                if ($din === 'plata_in_curs') {
                    $masina->trece($id, 'card', $din, 'abandonata', $actor, [], ['sesiune' => 'expirata']);
                }
                break;
        }
        return (string) $this->app->comenzi()->dupaId($id)['stare'];
    }

    /** Oaspetele a renunțat la plată (`cancel_url`): sesiunea expiră, comanda devine `abandonata`. */
    public function renunta(int $id): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || $c['stare'] !== 'plata_in_curs') {
            return (string) ($c['stare'] ?? '');
        }
        $p = $this->app->checkout()->ultima($id);
        if ($p !== null && $p['stripe_sesiune'] !== null) {
            try {
                $this->app->checkout()->expira((string) $p['stripe_sesiune']);
            } catch (EroareStripe $e) {
                // Sesiunea s-a încheiat între timp (plătită sau în procesare): o tratează confirmarea.
                return $this->confirma($id, 'intoarcere');
            }
            $this->app->baza()->executa("UPDATE {plati} SET stare = 'expirata', actualizat_la = ? WHERE id = ?", [$this->app->ceas()->moment(), $p['id']]);
        }
        $this->app->masinaStari()->trece($id, 'card', 'plata_in_curs', 'abandonata', 'intoarcere', [], ['renuntare' => true]);
        return 'abandonata';
    }

    /**
     * După intrarea în `de_rambursat`: e-mailul către oaspete (motivul real), o singură dată (marcajul
     * `email_refuz_la`; un proces mort între cele două pași îl lasă cronului), apoi rambursarea.
     */
    public function dupaRefuz(int $id): void
    {
        $this->anuntaRefuzul($id);
        $this->ramburseaza($id);
        // cu plata doar rezervată, e-mailul pleacă abia acum: după eliberare (elibereaza) sau, cu plata găsită deja
        // capturată, odată cu rambursarea pornită.
        $this->anuntaRefuzul($id);
    }

    /** @param bool $rezerva e-mailul de rezervă după o zi (Retinere::alertaOrfana): starea banilor, anularea confirmată separat */
    public function anuntaRefuzul(int $id, bool $rezerva = false): void
    {
        // cât suma e încă rezervată pe card, e-mailul „Am eliberat” așteaptă eliberarea (elibereaza() îl trimite).
        if ($this->autorizata($id) !== null) {
            return;
        }
        // Captura imposibilă: textul spune că am anulat rezervarea, deci e-mailul așteaptă anularea în EasyCheck
        // (Retinere::orfana îl trimite).
        $c = $this->app->comenzi()->dupaId($id);
        if (!$rezerva && $c !== null && ($c['motiv_rambursare'] ?? null) === 'captura' && in_array($c['ec_orfana'] ?? null, ['de_cautat', 'de_anulat'], true)) {
            return;
        }
        $n = $this->app->baza()->executa('UPDATE {comenzi} SET email_refuz_la = ? WHERE id = ? AND email_refuz_la IS NULL', [$this->app->ceas()->moment(), $id])->rowCount();
        if ($n === 1) {
            $this->anunta($id, 'plata_rambursata');
        }
    }

    /**
     * Rambursarea automată integrală a sumei încasate; o plată doar autorizată se eliberează în locul ei.
     * Întoarce starea comenzii după.
     */
    public function ramburseaza(int $id): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || $c['stare'] !== 'de_rambursat') {
            return (string) ($c['stare'] ?? '');
        }
        $aut = $this->autorizata($id);
        if ($aut !== null) {
            $r = $this->elibereaza($c, $aut);
            if ($r !== null) {
                return $r;
            }
            // Capturată între timp (de pe altă cale): rambursarea obișnuită, mai jos.
        }
        $p = $this->app->baza()->rand("SELECT * FROM {plati} WHERE comanda_id = ? AND stripe_pi IS NOT NULL AND suma_incasata > 0 ORDER BY id DESC LIMIT 1", [$id]);
        if ($p === null) {
            $this->app->jurnal()->eroare('Rambursare fără încasare cunoscută', ['ref' => $c['ref']]);
            return 'de_rambursat';
        }
        $ramb = $this->app->rambursari();
        // Înaintea primei rambursări automate și după una eșuată: întâi lista din Stripe, poate gazda a rambursat
        // (o parte sau tot) din Dashboard. Citirea listei rărită: cel mult o dată pe oră cât reluările merg,
        // o dată la 6 ore după ce s-au epuizat.
        $b = $this->app->baza();
        $esuate = (int) $b->valoare("SELECT COUNT(*) FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND stare IN ('esuat', 'cerut')", [$id]);
        $automate = (int) $b->valoare("SELECT COUNT(*) FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND idem LIKE ?", [$id, 'ref-' . $c['ref'] . '-auto%']);
        $lim = $this->app->limitator();
        if ($automate === 0 ? $lim->incearca('sync_ramb_prima:' . $id, 1, 3600)
            : ($esuate > 0 && $lim->incearca('sync_ramb:' . $id, 1, $esuate > count(\Cazo\Plati\Rambursari::REINCERCARI) ? 6 * 3600 : 3600))) {
            $ramb->sincronizeaza($id, (string) $p['stripe_pi']);
        }
        // Rambursările încă în procesare (de exemplu una a gazdei din Dashboard) se recitesc, cel mult la RECITIRE: o
        // rambursare care eșuează mărește din nou restul cerut.
        foreach ($b->toate("SELECT * FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND stare = 'in_procesare' AND stripe_id IS NOT NULL", [$id]) as $op) {
            $ramb->reia($op, (string) $p['stripe_pi']);
        }
        if ($ramb->integralRambursata($id)) {
            $this->app->masinaStari()->trece($id, 'card', 'de_rambursat', 'rambursata', 'sistem', [], ['rambursare' => 'integrala']);
            return 'rambursata';
        }
        // Se cere doar restul nerambursat: suma încasată minus rambursările reușite sau încă în procesare (Stripe
        // refuză o sumă peste soldul rambursabil al plății). Restul crește la loc când o rambursare în procesare eșuează:
        // atunci pleacă o operație nouă (`-auto-s<n>`), cu reluările ei.
        $rest = (int) $p['suma_incasata'] - $ramb->rambursatSauInCurs($id);
        $motiv = (string) ($c['motiv_rambursare'] ?? 'respinsa_ec');
        $r = $ramb->automata($id, (string) $c['ref'], (string) $p['stripe_pi'], $rest, $motiv, 'auto', true);
        // Comanda se încheie doar când plata e rambursată integral: o rambursare reușită a restului, cu altă rambursare
        // încă în procesare, lasă comanda în `de_rambursat` până se află rezultatul celeilalte.
        if ($ramb->integralRambursata($id)) {
            $this->app->masinaStari()->trece($id, 'card', 'de_rambursat', 'rambursata', 'sistem', [], ['rambursare' => $r['operatie']['stripe_id'] ?? 'integrala']);
            return 'rambursata';
        }
        if ($r['stare'] === 'reusit' || $r['stare'] === 'in_procesare') {
            return 'de_rambursat';
        }
        // Epuizarea printr-o operație nelămurită are alerta ei („verificați întâi în Stripe”, Anulare::lamuresteCerute).
        if ($r['epuizata'] && !$ramb->areNelamurita($id)) {
            $this->app->alertaGazda('alerta_rambursare', ['ref' => (string) $c['ref'], 'suma_bani' => max(0, $rest)], 'rambursare:' . $c['ref'], 30 * 86400);
        }
        return 'de_rambursat';
    }

    /** Plata doar autorizată a comenzii: suma reținută pe card, încă necapturată; null fără una. */
    public function autorizata(int $id): ?array
    {
        return $this->app->baza()->rand("SELECT * FROM {plati} WHERE comanda_id = ? AND stare = 'autorizata' AND stripe_pi IS NOT NULL AND schimbare_id IS NULL ORDER BY id DESC LIMIT 1", [$id]);
    }

    /** Comanda a avut o plată doar autorizată (reținută sau eliberată), deci textele vorbesc de suma rezervată pe card. */
    public function cuRetinere(int $id): bool
    {
        return $this->app->baza()->valoare("SELECT 1 FROM {plati} WHERE comanda_id = ? AND stare IN ('autorizata', 'anulata') AND suma_incasata = 0 AND schimbare_id IS NULL LIMIT 1", [$id]) !== null;
    }

    /**
     * Eliberează autorizarea la un refuz dinainte de confirmare: PaymentIntent-ul anulat, fără rambursare și fără
     * comision. Starea reală din Stripe decide: anulat → `autorizare_anulata`; capturat între timp → null (rambursarea
     * obișnuită); altfel comanda rămâne `de_rambursat`, iar cronul reia (după o zi, gazda primește alerta).
     */
    private function elibereaza(array $c, array $p): ?string
    {
        $id = (int) $c['id'];
        $pi = (string) $p['stripe_pi'];
        try {
            $o = $this->app->checkout()->anuleaza($pi, 'elib-' . $c['ref'] . '-' . $p['id']);
        } catch (EroareStripe $e) {
            // Răspunsul pierdut sau refuzat: starea se citește din Stripe.
            try {
                $o = $this->app->checkout()->citestePi($pi);
            } catch (EroareStripe) {
                $o = null;
            }
            if ($o === null || !in_array($o['status'] ?? '', ['canceled', 'succeeded'], true)) {
                $this->app->masinaStari()->jurnal($id, 'de_rambursat', 'de_rambursat', 'sistem', ['eliberare' => $e->felul . ':' . ($e->cod ?? $e->status)]);
                if ($this->app->ceas()->acum() - \Cazo\Nucleu\Ceas::dinMoment((string) $c['actualizat_la']) >= self::ELIBERARE_ALERTA
                    && $this->app->limitator()->incearca('eliberare_alerta:' . $id, 1, 30 * 86400)) {
                    $this->app->alertaGazda('alerta_eliberare', ['ref' => (string) $c['ref'], 'suma_bani' => (int) $p['suma_autorizata']], 'eliberare:' . $c['ref'], 30 * 86400);
                }
                return 'de_rambursat';
            }
        }
        $acum = $this->app->ceas()->moment();
        if (($o['status'] ?? '') === 'succeeded') {
            $this->app->baza()->executa("UPDATE {plati} SET stare = 'incasata', suma_incasata = ?, capturata_la = ?, actualizat_la = ? WHERE id = ?",
                [(int) ($o['amount_received'] ?? $p['suma_autorizata']), $acum, $acum, $p['id']]);
            return null;
        }
        $this->app->baza()->executa("UPDATE {plati} SET stare = 'anulata', actualizat_la = ? WHERE id = ?", [$acum, $p['id']]);
        if ($this->app->masinaStari()->trece($id, 'card', 'de_rambursat', 'autorizare_anulata', 'sistem', [], ['eliberare' => $pi])) {
            $this->anuntaRefuzul($id);
        }
        return (string) $this->app->comenzi()->dupaId($id)['stare'];
    }

    /**
     * Captura: după 201 la POST /rezervari, suma autorizată se încasează, iar comanda devine `confirmata`, cu
     * e-mailurile. Sub lacătul comenzii (`blocat_pana`), pe care îl cer și tranzițiile de ieșire: cronul ferestrei sau o
     * anulare din EasyCheck nu refuză comanda cât o captură e în drum. După orice eroare (și după 5xx sau timeout: captura
     * poate fi făcută, iar Stripe păstrează răspunsul 500 pe cheia de idempotență) starea se citește din Stripe:
     * `succeeded` confirmă, `requires_capture` se reia cu o cheie nouă (`cap-<ref>-<plata>-<n>`), anulată duce la captura imposibilă.
     * După fereastra de 10 minute nimic nu se mai capturează, dar întâi se citește PaymentIntent-ul: o captură
     * reușită cu răspunsul pierdut confirmă comanda, nu o refuză. Reluările: 5 s, 30 s, 2, 10 și 30 de minute.
     */
    public function captureaza(int $id, string $actor): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || $c['fel'] !== 'card' || $c['stare'] !== 'de_capturat') {
            return (string) ($c['stare'] ?? '');
        }
        $b = $this->app->baza();
        $acumM = $this->app->ceas()->moment();
        $lacat = $this->app->ceas()->moment(Finalizare::LACAT);
        // Lacătul: un singur proces capturează (anunțul, cronul și întoarcerea pot ajunge deodată).
        if ($b->executa('UPDATE {comenzi} SET blocat_pana = ?, actualizat_la = ? WHERE id = ? AND stare = ? AND (blocat_pana IS NULL OR blocat_pana <= ?)',
            [$lacat, $acumM, $id, 'de_capturat', $acumM])->rowCount() !== 1) {
            return 'de_capturat';
        }
        $c = $this->app->comenzi()->dupaId($id);
        $p = $this->autorizata($id);
        if ($p !== null) {
            $pi = (string) $p['stripe_pi'];
            if ($this->app->retinere()->depasita($c)) {
                // Fereastra de 10 minute a trecut: nimic nu se mai capturează; o captură făcută deja confirmă comanda.
                $o = $this->citestePiSigur($pi);
                if ($o === null) {
                    // Stripe tace și după fereastră: după o zi, gazda primește alerta (o dată), iar citirea se reia la 6 ore.
                    if ($this->app->ceas()->acum() - \Cazo\Nucleu\Ceas::dinMoment((string) $c['platita_la']) >= self::ELIBERARE_ALERTA
                        && $this->app->limitator()->incearca('captura_necitita_alerta:' . $id, 1, 30 * 86400)) {
                        $this->app->alertaGazda('alerta_captura_necitita', ['ref' => (string) $c['ref'], 'sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare']],
                            'captura_necitita:' . $c['ref'], 30 * 86400);
                    }
                    return $this->capturaAmanata($c, 'stripe_citire', null, $lacat);
                }
                if (($o['status'] ?? '') !== 'succeeded') {
                    return $this->app->retinere()->capturaImposibila($c, 'fereastra', $actor, $lacat);
                }
            } else {
                $n = $this->capturiEsuate($id);
                try {
                    $o = $this->app->checkout()->captureaza($pi, 'cap-' . $c['ref'] . '-' . $p['id'] . ($n > 0 ? '-' . $n : ''));
                } catch (EroareStripe $e) {
                    $o = $this->citestePiSigur($pi);
                    if ($o === null || ($o['status'] ?? '') === 'requires_capture') {
                        return $this->capturaAmanata($c, 'stripe_' . ($e->cod ?? $e->felul), $o, $lacat);
                    }
                }
                if (($o['status'] ?? '') !== 'succeeded') {
                    return $this->capturaAmanata($c, 'captura_' . (string) ($o['status'] ?? 'necunoscut'), $o, $lacat);
                }
            }
            $b->executa("UPDATE {plati} SET stare = 'incasata', suma_incasata = ?, capturata_la = ?, actualizat_la = ? WHERE id = ? AND stare = 'autorizata'",
                [(int) ($o['amount_received'] ?? $p['suma_autorizata']), $acumM, $acumM, $p['id']]);
        }
        if ($this->app->masinaStari()->trece($id, 'card', 'de_capturat', 'confirmata', $actor, ['blocat_pana' => null, 'urmatoarea_incercare' => null, 'eroare_cod' => null],
            ['captura' => $p !== null ? (string) $p['stripe_pi'] : 'incasata'], $lacat)) {
            $this->app->finalizare()->confirmata($id);
        }
        return (string) $this->app->comenzi()->dupaId($id)['stare'];
    }

    /** Capturile nereușite ale comenzii până acum (reluările din jurnal): numărul din cheia de idempotență a celei următoare. */
    private function capturiEsuate(int $id): int
    {
        return (int) $this->app->baza()->valoare('SELECT COUNT(*) FROM {comenzi_jurnal} WHERE comanda_id = ? AND din_stare = ? AND in_stare = ?', [$id, 'de_capturat', 'de_capturat']);
    }

    /** PaymentIntent-ul citit din Stripe; null când Stripe nu răspunde. */
    private function citestePiSigur(string $pi): ?array
    {
        try {
            return $this->app->checkout()->citestePi($pi);
        } catch (EroareStripe) {
            return null;
        }
    }

    /**
     * Captura nereușită: o autorizare care nu se mai poate captura (anulată sau expirată la bancă) duce la anularea
     * rezervării în EasyCheck (Retinere::capturaImposibila); altfel reluarea la REINCERCARI_CAPTURA, cel mult până
     * la sfârșitul ferestrei de 10 minute.
     */
    private function capturaAmanata(array $c, string $motiv, ?array $o, string $lacat): string
    {
        $id = (int) $c['id'];
        if (in_array($o['status'] ?? null, ['canceled', 'requires_payment_method'], true)) {
            return $this->app->retinere()->capturaImposibila($c, 'captura', 'sistem', $lacat);
        }
        $n = 1 + $this->capturiEsuate($id);
        $pauza = self::REINCERCARI_CAPTURA[min($n - 1, count(self::REINCERCARI_CAPTURA) - 1)];
        if ($c['platita_la'] !== null && $this->app->ceas()->acum() - \Cazo\Nucleu\Ceas::dinMoment((string) $c['platita_la']) >= self::ELIBERARE_ALERTA) {
            $pauza = 6 * 3600;
        }
        $termen = $this->app->retinere()->termen($c);
        $urm = $this->app->ceas()->acum() + $pauza;
        if ($termen !== null && $termen > $this->app->ceas()->acum()) {
            $urm = min($urm, $termen);
        }
        // Lacătul se eliberează doar dacă e încă al acestui proces.
        $this->app->baza()->executa('UPDATE {comenzi} SET blocat_pana = NULL, urmatoarea_incercare = ?, eroare_cod = ?, actualizat_la = ? WHERE id = ? AND blocat_pana = ?',
            [\Cazo\Nucleu\Ceas::formateaza($urm), mb_substr($motiv, 0, 64), $this->app->ceas()->moment(), $id, $lacat]);
        $this->app->masinaStari()->jurnal($id, 'de_capturat', 'de_capturat', 'sistem', ['captura' => $motiv, 'reincercare_in' => $pauza, 'pi_stare' => $o['status'] ?? null]);
        return 'de_capturat';
    }

    /**
     * Plățile încă în lucru pe contul Stripe de acum (sesiuni deschise, confirmări întârziate, comenzi plătite de
     * trimis sau de rambursat, anulările cu rambursarea neîncheiată sau cu suma încă de ales și rambursările din
     * panou în lucru): o cheie a
     * altui cont le-ar lăsa fără urmă. @return list<array{ref:string, obiect:string}>
     */
    public function deschise(): array
    {
        $r = [];
        foreach ($this->app->baza()->toate("SELECT c.ref, p.stripe_pi, p.stripe_sesiune FROM {comenzi} c JOIN {plati} p ON p.comanda_id = c.id
            WHERE c.fel = 'card' AND (c.stare IN ('plata_in_curs', 'plata_in_procesare', 'platita', 'trimitere_ec', 'de_rambursat', 'de_capturat', 'anulare_de_lamurit')
                OR (c.anulare_suma > 0 AND c.anulare_rambursata_la IS NULL AND c.stare IN ('confirmata', 'anulare_ceruta', 'anulata_oaspete', 'anulata_gazda') AND c.anulare_actor IS NOT NULL)
                OR (c.anulare_suma > 0 AND c.anulare_rambursata_la IS NULL AND c.stare IN ('anulata_oaspete', 'anulata_gazda'))
                OR EXISTS (SELECT 1 FROM {operatii_plata} o WHERE o.comanda_id = c.id AND o.tip = 'rambursare' AND (o.stare = 'in_procesare' OR (o.stare = 'cerut' AND " . \Cazo\Plati\Rambursari::CERUT_VIU . ")))
                OR EXISTS (SELECT 1 FROM {schimbari_date} sd WHERE sd.comanda_id = c.id AND (sd.stare IN ('plata_in_curs', 'de_aplicat', 'cerere') OR (sd.rambursare_suma > 0 AND sd.rambursata_la IS NULL))))
            AND p.id = (SELECT MAX(id) FROM {plati} WHERE comanda_id = c.id) ORDER BY c.id") as $x) {
            $o = $x['stripe_pi'] ?? $x['stripe_sesiune'];
            if (is_string($o) && $o !== '') {
                $r[] = ['ref' => (string) $x['ref'], 'obiect' => $o];
            }
        }
        return $r;
    }

    /**
     * Gazda închide din panou o comandă rămasă fără ieșire automată (de rambursat cu reluările epuizate, sau
     * plătită cu recuperarea „necunoscut”), după ce a rezolvat plata în contul Stripe: `inchisa_gazda`.
     * Întâi rambursările se recitesc din Stripe; fără răspuns, închiderea se refuză (`sincronizare`), ca să nu se
     * închidă pe date vechi. O plată rambursată între timp integral se încheie ca `rambursata`. Cu un rest nerambursat,
     * închiderea cere confirmarea explicită a gazdei (`confirmare`), salvată în jurnalul comenzii și al aplicației cu
     * suma nerambursată. Oaspetele primește e-mailul închiderii, o singură dată. Nu mută bani.
     * @return string ok, rambursata, confirmare, sincronizare, stare
     */
    public function inchide(int $id, int $utilizator, bool $confirmRest = false): string
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || $c['fel'] !== 'card' || !self::poateFiInchisa($c, $this->app)) {
            return 'stare';
        }
        $s = $this->sume($id, true);
        if ($s === null) {
            return 'sincronizare';
        }
        if ($c['stare'] === 'de_rambursat' && $this->app->rambursari()->integralRambursata($id)) {
            $this->app->masinaStari()->trece($id, 'card', 'de_rambursat', 'rambursata', 'admin:' . $utilizator, [], ['rambursare' => 'integrala']);
            return 'rambursata';
        }
        $rest = $s['incasat'] - $s['rambursat'];
        if ($rest > 0 && !$confirmRest) {
            return 'confirmare';
        }
        $detalii = ['inchisa' => 'rezolvata_in_stripe', 'incasat' => $s['incasat'], 'rambursat' => $s['rambursat'], 'nerambursat' => $rest, 'confirmat_rest' => $rest > 0];
        $ok = $this->app->masinaStari()->trece($id, 'card', (string) $c['stare'], 'inchisa_gazda', 'admin:' . $utilizator, ['blocat_pana' => null, 'urmatoarea_incercare' => null], $detalii);
        if (!$ok) {
            return 'stare';
        }
        $this->app->jurnal()->avertisment('Comandă închisă de gazdă', ['ref' => $c['ref'], 'din' => $c['stare'], 'utilizator' => $utilizator] + $detalii);
        // oaspetele află încheierea fără rezervare: gazda rezolvă plata direct, cu contactul ei.
        if ($this->app->baza()->executa('UPDATE {comenzi} SET email_inchidere_la = ? WHERE id = ? AND email_inchidere_la IS NULL', [$this->app->ceas()->moment(), $id])->rowCount() === 1) {
            $this->anunta($id, 'plata_inchisa');
        }
        return 'ok';
    }

    /**
     * Suma încasată și cea rambursată ale comenzii; cu $sincronizeaza, întâi lista rambursărilor din Stripe (null când
     * Stripe nu răspunde).
     * @return array{incasat:int, rambursat:int}|null
     */
    public function sume(int $id, bool $sincronizeaza = false): ?array
    {
        $p = $this->app->baza()->rand('SELECT * FROM {plati} WHERE comanda_id = ? AND stripe_pi IS NOT NULL ORDER BY id DESC LIMIT 1', [$id]);
        if ($p === null) {
            return ['incasat' => 0, 'rambursat' => 0];
        }
        if ($sincronizeaza && !$this->app->rambursari()->sincronizeaza($id, (string) $p['stripe_pi'])) {
            return null;
        }
        $p = $this->app->baza()->rand('SELECT suma_incasata, suma_rambursata FROM {plati} WHERE id = ?', [$p['id']]);
        return ['incasat' => (int) $p['suma_incasata'], 'rambursat' => (int) $p['suma_rambursata']];
    }

    /** O comandă fără ieșire automată: de rambursat cu reluările epuizate sau plătită cu recuperarea „necunoscut”. */
    public static function poateFiInchisa(array $c, Aplicatie $app): bool
    {
        if ($c['stare'] === 'platita') {
            return $c['intarziata_la'] !== null;
        }
        if ($c['stare'] === 'de_rambursat') {
            // Reluările epuizate, sau o operație nelămurită (fără reluări automate).
            return (int) $app->baza()->valoare("SELECT COUNT(*) FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND stare = 'esuat'", [$c['id']])
                > count(\Cazo\Plati\Rambursari::REINCERCARI)
                || $app->baza()->valoare("SELECT 1 FROM {operatii_plata} WHERE comanda_id = ? AND tip = 'rambursare' AND stare = 'esuat' AND eroare = 'nelamurita' LIMIT 1", [$c['id']]) !== null;
        }
        return false;
    }

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

    /** E-mailul scurt către gazdă la fiecare rezervare plătită, la fiecare proprietar, în limba panoului lui. */
    public function anuntaGazda(int $id): void
    {
        foreach ($this->app->baza()->toate("SELECT email, limba_panou FROM {utilizatori} WHERE rol = 'proprietar' AND activ = 1 ORDER BY id") as $u) {
            $this->app->emailuri()->pune($id, 'gazda_rezervare', (string) $u['limba_panou'], (string) $u['email'], ['comanda_id' => $id]);
        }
    }

    /**
     * Cronul: banii în mișcare.
     * @return array{reconciliate:int, finalizate:int, rambursate:int}
     */
    public function ruleaza(int $cate = 20): array
    {
        $b = $this->app->baza();
        $acum = $this->app->ceas()->moment();
        $rez = ['reconciliate' => 0, 'finalizate' => 0, 'rambursate' => 0];
        // Reconcilierea fără webhook: plățile deschise de peste 2 minute și cele cu confirmare întârziată.
        $deCitit = $b->toate("SELECT id FROM {comenzi} WHERE fel = 'card' AND ((stare = 'plata_in_curs' AND actualizat_la <= ?)
            OR (stare = 'plata_in_procesare' AND (verificat_la IS NULL OR verificat_la <= ?))) ORDER BY id LIMIT " . $cate,
            [$this->app->ceas()->moment(-self::RECONCILIERE), $this->app->ceas()->moment(-self::IN_PROCESARE)]);
        foreach ($deCitit as $r) {
            $this->confirma((int) $r['id'], 'cron');
            $rez['reconciliate']++;
        }
        // Lacătul expirat: un proces a murit la jumătate; comanda revine în `platita`, aceeași cheie, același corp.
        foreach ($b->toate("SELECT id FROM {comenzi} WHERE fel = 'card' AND stare = 'trimitere_ec' AND blocat_pana <= ? LIMIT " . $cate, [$acum]) as $r) {
            $this->app->masinaStari()->trece((int) $r['id'], 'card', 'trimitere_ec', 'platita', 'cron', ['blocat_pana' => null], ['lacat' => 'expirat']);
        }
        foreach ($b->toate("SELECT id FROM {comenzi} WHERE fel = 'card' AND stare = 'platita' AND (urmatoarea_incercare IS NULL OR urmatoarea_incercare <= ?) ORDER BY id LIMIT " . $cate, [$acum]) as $r) {
            $this->app->finalizare()->ruleaza((int) $r['id'], 'cron');
            $rez['finalizate']++;
        }
        // fereastra de 10 minute (refuzul și eliberarea) și rezervările EasyCheck rămase fără plată.
        $this->app->retinere()->ruleaza($cate);
        // capturile rămase (procesul mort după 201 sau o eroare trecătoare Stripe).
        foreach ($b->toate("SELECT id FROM {comenzi} WHERE fel = 'card' AND stare = 'de_capturat' AND (urmatoarea_incercare IS NULL OR urmatoarea_incercare <= ?) ORDER BY id LIMIT " . $cate, [$acum]) as $r) {
            $this->captureaza((int) $r['id'], 'cron');
            $rez['finalizate']++;
        }
        // Comanda cu cardul care a așteptat codul de 6 cifre peste o oră se abandonează.
        foreach ($b->toate("SELECT id FROM {comenzi} WHERE fel = 'card' AND stare = 'noua' AND creat_la < ? LIMIT " . $cate, [$this->app->ceas()->moment(-3600)]) as $r) {
            $this->app->masinaStari()->trece((int) $r['id'], 'card', 'noua', 'abandonata', 'cron', [], ['cod' => 'neconfirmat']);
        }
        // E-mailul rambursării rămas netrimis (procesul a murit între `de_rambursat` și e-mail).
        foreach ($b->toate("SELECT id FROM {comenzi} WHERE fel = 'card' AND stare IN ('de_rambursat', 'rambursata', 'autorizare_anulata') AND email_refuz_la IS NULL AND motiv_rambursare IS NOT NULL LIMIT " . $cate) as $r) {
            $this->anuntaRefuzul((int) $r['id']);
        }
        foreach ($b->toate("SELECT id, actualizat_la FROM {comenzi} WHERE fel = 'card' AND stare = 'de_rambursat' ORDER BY id LIMIT " . $cate) as $r) {
            // eliberarea unei plăți doar autorizate se reia rar (ca celelalte reluări): la minut în primele 10 minute, apoi
            // la 5 minute, la 30 de minute după prima oră și la 6 ore după prima zi.
            if ($this->autorizata((int) $r['id']) !== null) {
                $varsta = $this->app->ceas()->acum() - \Cazo\Nucleu\Ceas::dinMoment((string) $r['actualizat_la']);
                $pauza = $varsta < 600 ? 60 : ($varsta < 3600 ? 300 : ($varsta < 86400 ? 1800 : 6 * 3600));
                if (!$this->app->limitator()->incearca('eliberare_cron:' . $pauza . ':' . $r['id'], 1, $pauza)) {
                    continue;
                }
            }
            $this->ramburseaza((int) $r['id']);
            $rez['rambursate']++;
        }
        // Operațiile rămase `cerut`, pe orice comandă: lămurite pe o cale garantată.
        $this->app->anulare()->lamuresteCerute();
        // Rambursările rămase prea mult în procesare (soldul Stripe): alerta gazdei.
        $this->app->anulare()->alerteProcesare();
        return $rez;
    }

    /**
     * Un anunț Stripe primit (doar un semnal: starea se citește din API), procesat sub zăvorul comenzii.
     * Întoarce false când zăvorul e ocupat sau când lista rambursărilor din Stripe nu s-a putut citi: anunțul rămâne
     * neprocesat și îl reia cronul (reiaAnunturile).
     */
    public function dinAnunt(array $e): bool
    {
        $tip = (string) ($e['type'] ?? '');
        $o = (array) ($e['data']['object'] ?? []);
        $id = $this->comandaDin($o);
        $evt = is_string($e['id'] ?? null) ? $e['id'] : null;
        if ($tip === 'payment_intent.payment_failed') {
            // Fiecare eșec distinct (un anunț nou) numără, chiar pe aceeași comandă: testarea cardurilor într-o singură
            // sesiune Checkout dă multe eșecuri pe același PaymentIntent. Trecerea în plata_esuata care urmează
            // aceluiași PaymentIntent nu mai numără a doua oară.
            $this->numaraEsec($evt !== null ? 'evt:' . $evt : null, is_string($o['id'] ?? null) ? $o['id'] : null);
        }
        if ($id === null) {
            return true;
        }
        $r = $this->app->zavor()->cuZavor('comanda:' . $id, 60, fn (): bool => $this->proceseaza($tip, $o, $id));
        return $r[0] === true && $r[1] === true;
    }

    /** @return bool false când anunțul trebuie reluat (lista rambursărilor din Stripe necitită) */
    private function proceseaza(string $tip, array $o, int $id): bool
    {
        if (str_starts_with($tip, 'checkout.session.')) {
            if (isset($o['metadata']['schimbare'])) {
                // Plata diferenței la schimbarea datelor (118b): sesiunea ei, nu a comenzii.
                $this->app->schimbareDate()->dinAnunt($id, $o);
                return true;
            }
            if ($this->confirma($id, 'webhook_stripe') === 'platita') {
                $this->app->finalizare()->ruleaza($id, 'webhook_stripe');
            }
            return true;
        }
        if ($tip === 'payment_intent.payment_failed') {
            $this->confirma($id, 'webhook_stripe');
            return true;
        }
        $pi = is_string($o['payment_intent'] ?? null) ? $o['payment_intent'] : null;
        if (($tip === 'charge.refunded' || $tip === 'charge.refund.updated') && $pi !== null) {
            $ramb = $this->app->rambursari();
            // Eșecul din obiectul semnat se aplică întâi (e final la Stripe); lista din Stripe aduce restul. Fără listă,
            // anunțul rămâne neprocesat, iar cronul îl reia.
            if ($tip === 'charge.refund.updated') {
                $ramb->esecDinAnunt($o);
            }
            $citita = $ramb->sincronizeaza($id, $pi);
            $this->redeschide($id);
            $this->ramburseaza($id);
            // O anulare cu rambursarea în lucru se reverifică la rularea următoare a cronului, fără pauza ei.
            $this->app->baza()->executa('UPDATE {comenzi} SET anulare_rambursare_reia = NULL WHERE id = ? AND anulare_rambursata_la IS NULL', [$id]);
            $this->app->anulare()->reverificaRambursarea($id);
            return $citita;
        }
        if (str_starts_with($tip, 'charge.dispute.')) {
            // contestarea se ține (Plati\Contestari, cu alerta gazdei la prima apariție) și se raportează la EasyCheck.
            return $this->app->contestari()->dinAnunt($id, $o);
        }
        return true;
    }

    /**
     * O comandă `rambursata` (refuzul EasyCheck) a cărei rambursare, confirmată de Stripe, a eșuat după aceea
     * (`charge.refund.updated`, succeeded → failed): banii sunt din nou la gazdă, deci comanda revine în `de_rambursat`,
     * rambursarea se reia cu reluările ei (`-auto-r<n>`), iar gazda primește o dată alerta „O rambursare confirmată a
     * eșuat după aceea” (aceeași cheie ca la anulări).
     */
    private function redeschide(int $id): void
    {
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || $c['fel'] !== 'card' || $c['stare'] !== 'rambursata' || $this->app->rambursari()->integralRambursata($id)) {
            return;
        }
        $p = $this->app->baza()->rand('SELECT * FROM {plati} WHERE comanda_id = ? AND stripe_pi IS NOT NULL AND suma_incasata > 0 ORDER BY id DESC LIMIT 1', [$id]);
        if ($p === null) {
            return;
        }
        $lipsa = (int) $p['suma_incasata'] - $this->app->rambursari()->sumaPrefix($id, '', ['reusit']);
        if ($lipsa <= 0 || !$this->app->masinaStari()->trece($id, 'card', 'rambursata', 'de_rambursat', 'sistem', [], ['rambursare' => 'redeschisa', 'lipsa' => $lipsa])) {
            return;
        }
        $this->app->jurnal()->avertisment('Rambursarea automată a eșuat după confirmare', ['ref' => $c['ref'], 'lipsa' => $lipsa]);
        $this->app->alertaGazda('alerta_rambursare_intoarsa', ['ref' => (string) $c['ref'], 'suma_bani' => $lipsa], 'rambursare_intoarsa:' . $c['ref'], 30 * 86400);
    }

    /** Un eșec de plată pentru întrerupător (o dată pe $cheie), cu PaymentIntent-ul marcat ca numărat. */
    private function numaraEsec(?string $cheie, ?string $pi): void
    {
        $this->app->intrerupator()->numaraEsecPlata($cheie);
        if ($pi !== null && $pi !== '') {
            $this->app->limitator()->inregistreaza('pi_esec:' . mb_substr($pi, 0, 120), 86400);
        }
    }

    /**
     * Cronul: anunțurile Stripe primite, dar rămase neprocesate (o eroare sau un proces mort după răspuns), mai
     * vechi de 5 minute, se reiau din câmpurile păstrate, de cel mult 5 ori; apoi rămân în jurnal.
     */
    public function reiaAnunturile(int $cate = 20): int
    {
        $n = 0;
        $cont = $this->app->stripe();
        foreach ($this->app->baza()->toate('SELECT * FROM {stripe_evenimente} WHERE procesat_la IS NULL AND incercari < 5 AND primit_la <= ? ORDER BY primit_la LIMIT ' . $cate,
            [$this->app->ceas()->moment(-300)]) as $r) {
            $this->app->baza()->executa('UPDATE {stripe_evenimente} SET incercari = incercari + 1 WHERE id = ?', [$r['id']]);
            $date = json_decode((string) ($r['date'] ?? ''), true);
            try {
                if (is_array($date) && $this->dinAnunt(['id' => $r['id'], 'type' => $r['tip'], 'data' => ['object' => $date]])) {
                    $cont->procesat((string) $r['id']);
                    $n++;
                    continue;
                }
            } catch (\Throwable $e) {
                $this->app->jurnal()->eroare('Anunț Stripe reluat cu eroare', ['id' => $r['id'], 'tip' => $r['tip'], 'eroare' => $e->getMessage()]);
            }
            if ((int) $r['incercari'] + 1 >= 5) {
                // A cincea reluare eșuată: alertă tehnică la gazdă, cel mult una pe zi.
                $this->app->alertaGazda('alerta_anunt_stripe', ['tip' => (string) $r['tip'], 'id' => (string) $r['id']], 'anunt_stripe', 86400);
            }
        }
        return $n;
    }

    /** Comanda unui obiect Stripe: metadata.comanda, client_reference_id sau PaymentIntent-ul plății. */
    private function comandaDin(array $o): ?int
    {
        $ref = $o['metadata']['comanda'] ?? $o['client_reference_id'] ?? null;
        if (is_string($ref) && ($c = $this->app->comenzi()->dupaRef($ref)) !== null) {
            return (int) $c['id'];
        }
        $pi = is_string($o['payment_intent'] ?? null) ? $o['payment_intent'] : (str_starts_with((string) ($o['id'] ?? ''), 'pi_') ? (string) $o['id'] : null);
        if ($pi !== null) {
            $id = $this->app->baza()->valoare('SELECT comanda_id FROM {plati} WHERE stripe_pi = ? ORDER BY id DESC LIMIT 1', [$pi]);
            return $id !== null ? (int) $id : null;
        }
        return null;
    }
}
