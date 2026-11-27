<?php

declare(strict_types=1);

namespace Cazo\Plati;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Setari;
use Cazo\Securitate\Secrete;

defined('CAZO') or exit;

/**
 * Contul Stripe al gazdei: cheia restricționată (secretul `stripe_cheie`), verificarea
 * permisiunilor (la salvare și zilnic, setarea `stripe.verificare`) și punctul de anunț (`POST /v1/webhook_endpoints`,
 * secretul `stripe_whsec` criptat, setarea `stripe.anunt` cu id-ul, adresa și amprenta cheii). Punctul se recreează
 * la schimbarea cheii sau a domeniului; fără permisiunea Write, gazda îl creează manual și lipește secretul.
 *
 * Anunțurile primite (`POST /stripe-anunt`): semnătura, deduplicarea în `stripe_evenimente`; procesarea o face
 * apelantul, după răspuns. Evenimentul e doar un semnal: starea se citește mereu din API.
 *
 * Rotirea secretului (auditul de securitate.2026), ca la EasyCheck (Anunturi::puneSecret): un secret nou pe
 * aceeași cheie (punctul recreat la schimbarea domeniului, secretul lipit din nou de gazdă) păstrează 24 de ore
 * secretul de dinainte ca `stripe_whsec_anterior`, ca anunțurile semnate cu el să nu fie refuzate. La schimbarea
 * cheii (poate alt cont) și la ștergerea ei, ambele secrete se șterg.
 */
final class ContStripe
{
    public const EVENIMENTE = [
        'checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed',
        'checkout.session.expired', 'payment_intent.payment_failed', 'charge.refunded', 'charge.refund.updated', 'charge.dispute.created',
        // drumul contestării (răspunsul, decizia, retragerea și întoarcerea banilor), raportat și la EasyCheck.
        'charge.dispute.updated', 'charge.dispute.closed', 'charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated',
    ];
    public const CORP_MAXIM = 262144;
    /** Cât se mai acceptă secretul de dinainte după o rotire (ca Anunturi::ROTIRE_SECUNDE). */
    public const ROTIRE_SECUNDE = 86400;

    private ?StripeClient $client = null;

    public function __construct(
        private Baza $baza,
        private Ceas $ceas,
        private Secrete $secrete,
        private Setari $setari,
        private Http $http,
        private string $adresaAnunt,
        private string $baza_url = StripeClient::BAZA,
    ) {
    }

    public function client(): StripeClient
    {
        return $this->client ??= new StripeClient($this->http, fn (): ?string => $this->secrete->citeste('stripe_cheie'), $this->baza_url);
    }

    public function configurat(): bool
    {
        return $this->secrete->citeste('stripe_cheie') !== null;
    }

    public function mod(): ?string
    {
        $c = $this->secrete->citeste('stripe_cheie');
        return $c !== null ? StripeClient::mod($c) : null;
    }

    /**
     * Starea pentru panou și pentru regula de plată: cheia (ultimele 4, modul), permisiunile, anunțul.
     * @return array{configurata:bool, mod:?string, ultimele4:?string, ok:bool, permisiuni:array, verificat_la:?int, anunt:array}
     */
    public function stare(): array
    {
        $v = (array) ($this->setari->get('stripe.verificare') ?? []);
        $a = (array) ($this->setari->get('stripe.anunt') ?? []);
        return [
            'configurata' => $this->configurat(),
            'mod' => $this->mod(),
            'ultimele4' => $this->secrete->ultimele4Din('stripe_cheie'),
            'ok' => $this->configurat() && ($v['ok'] ?? false) === true && ($v['amprenta'] ?? null) === $this->amprenta(),
            'permisiuni' => (array) ($v['permisiuni'] ?? []),
            'verificat_la' => isset($v['la']) ? (int) $v['la'] : null,
            'anunt' => ['mod' => $a['mod'] ?? null, 'id' => $a['id'] ?? null, 'url' => $this->adresaAnunt, 'secret' => $this->secrete->citeste('stripe_whsec') !== null,
                'primit_la' => $a['primit_la'] ?? null],
        ];
    }

    /**
     * Salvează o cheie nouă după forma ei și verificarea permisiunilor; creează punctul de anunț când se poate.
     * Cheia cu altă formă (o cheie secretă `sk_`) se refuză fără să fie salvată.
     * @return array{ok:bool, motiv:?string, permisiuni:array}
     */
    public function salveazaCheie(string $cheie): array
    {
        $cheie = trim($cheie);
        $f = VerificareCheie::forma($cheie);
        if ($f !== null) {
            return ['ok' => false, 'motiv' => $f, 'permisiuni' => []];
        }
        $proba = new StripeClient($this->http, static fn (): string => $cheie, $this->baza_url);
        $v = VerificareCheie::verifica($proba);
        if ($v['cheie_invalida']) {
            return ['ok' => false, 'motiv' => 'invalida', 'permisiuni' => $v['permisiuni']];
        }
        $vechea = $this->secrete->citeste('stripe_cheie');
        $this->secrete->pune('stripe_cheie', $cheie);
        $this->client = null;
        $this->scrieVerificare($v);
        if ($vechea !== $cheie) {
            $this->anuntPeCheiaNoua($vechea, $v['permisiuni']['webhook_write'] === 'ok');
        }
        return ['ok' => $v['ok'], 'motiv' => $v['ok'] ? null : 'permisiuni', 'permisiuni' => $v['permisiuni']];
    }

    /** Verificarea zilnică și recrearea punctului de anunț la schimbarea domeniului. */
    public function verificaZilnic(): array
    {
        if (!$this->configurat()) {
            return ['ok' => false, 'permisiuni' => [], 'cheie_invalida' => false];
        }
        // Un timeout nu e un refuz al cheii: până la 3 încercări; cu erori de rețea rămase și fără permisiuni lipsă,
        // starea veche rămâne (cardul nu se oprește o zi pentru o clipă de rețea).
        for ($i = 0; $i < 3; $i++) {
            $v = VerificareCheie::verifica($this->client());
            if (!in_array('eroare', $v['permisiuni'], true)) {
                break;
            }
        }
        if (in_array('eroare', $v['permisiuni'], true) && !in_array('lipsa', $v['permisiuni'], true) && !$v['cheie_invalida']) {
            $this->setari->pune('stripe.verificare_eroare', $this->ceas->acum());
            return $v;
        }
        $this->scrieVerificare($v);
        $a = (array) ($this->setari->get('stripe.anunt') ?? []);
        if (($a['mod'] ?? null) === 'auto' && ($a['url'] ?? null) !== $this->adresaAnunt && $v['permisiuni']['webhook_write'] === 'ok') {
            $this->creeazaAnunt();
        } elseif ($v['permisiuni']['webhook_write'] === 'ok') {
            $this->aduceLaZiEvenimentele();
        }
        return $v;
    }

    public function stergeCheie(): void
    {
        $this->stergeAnuntVechi();
        $this->secrete->sterge('stripe_cheie');
        $this->stergeSecreteleAnuntului();
        $this->setari->pune('stripe.verificare', null);
        $this->setari->pune('stripe.anunt', null);
        $this->client = null;
    }

    /** Punctul de anunț creat prin API, cu secretul criptat pe loc. */
    public function creeazaAnunt(): bool
    {
        $this->stergeAnuntVechi();
        try {
            $r = $this->client()->post('/v1/webhook_endpoints', [
                'url' => $this->adresaAnunt,
                'enabled_events' => self::EVENIMENTE,
                'api_version' => StripeClient::VERSIUNE,
                'description' => 'Site de rezervări directe',
                'metadata' => ['sursa' => 'cazo'],
            ], 'we-' . bin2hex(random_bytes(12)));
            // Cheia de idempotență e nouă la fiecare creare: o cheie derivată din adresă și cheie ar întoarce, 24 de ore,
            // punctul creat și apoi șters (găsit de proba pe API-ul real, teste/e2e/stripe_api.php).
        } catch (EroareStripe) {
            $this->setari->pune('stripe.anunt', ['mod' => 'manual', 'id' => null, 'url' => $this->adresaAnunt]);
            return false;
        }
        if (!is_string($r['secret'] ?? null) || !str_starts_with($r['secret'], 'whsec_') || !is_string($r['id'] ?? null)) {
            $this->setari->pune('stripe.anunt', ['mod' => 'manual', 'id' => null, 'url' => $this->adresaAnunt]);
            return false;
        }
        $this->puneSecret($r['secret']);
        $this->setari->pune('stripe.anunt', ['mod' => 'auto', 'id' => $r['id'], 'url' => $this->adresaAnunt, 'amprenta' => $this->amprenta(), 'evenimente' => self::amprentaEvenimente()]);
        return true;
    }

    /**
     * Punctul creat automat înaintea unei liste noi de evenimente (contestările) primește lista de acum
     * (`POST /v1/webhook_endpoints/{id}`, `enabled_events`), din verificarea zilnică. Fără reușită, încearcă a doua zi.
     */
    public function aduceLaZiEvenimentele(): bool
    {
        $a = (array) ($this->setari->get('stripe.anunt') ?? []);
        if (($a['mod'] ?? null) !== 'auto' || !is_string($a['id'] ?? null) || ($a['evenimente'] ?? null) === self::amprentaEvenimente()) {
            return false;
        }
        try {
            $this->client()->post('/v1/webhook_endpoints/' . $a['id'], ['enabled_events' => self::EVENIMENTE], 'we-ev-' . self::amprentaEvenimente() . '-' . substr(hash('sha256', $a['id']), 0, 8));
        } catch (EroareStripe) {
            return false;
        }
        $this->setari->pune('stripe.anunt', ['evenimente' => self::amprentaEvenimente()] + $a);
        return true;
    }

    private static function amprentaEvenimente(): string
    {
        return substr(hash('sha256', implode(',', self::EVENIMENTE)), 0, 16);
    }

    /** Varianta manuală: gazda a creat punctul în Dashboard și lipește secretul. */
    public function secretManual(string $secret): bool
    {
        $secret = trim($secret);
        if (!preg_match('~^whsec_[A-Za-z0-9+/=]{16,200}$~', $secret)) {
            return false;
        }
        $this->puneSecret($secret);
        $this->setari->pune('stripe.anunt', ['mod' => 'manual', 'id' => null, 'url' => $this->adresaAnunt]);
        return true;
    }

    /** Secretele acceptate acum: cel curent și, în primele 24 de ore după o rotire, cel de dinainte. */
    public function secreteAcceptate(): array
    {
        $s = [];
        $curent = $this->secrete->citeste('stripe_whsec');
        if ($curent !== null) {
            $s[] = $curent;
        }
        $anterior = $this->secrete->citeste('stripe_whsec_anterior');
        $rotit = $this->setari->get('stripe.whsec_rotit_la');
        if ($anterior !== null && is_int($rotit) && $this->ceas->acum() - $rotit <= self::ROTIRE_SECUNDE) {
            $s[] = $anterior;
        }
        return $s;
    }

    /**
     * Doar semnătura, cu secretele acceptate acum. Punctul de anunț o verifică înaintea limitei globale (auditul de
     * securitate.2026).
     */
    public function semnaturaValida(string $corp, ?string $antet): bool
    {
        $secrete = $this->secreteAcceptate();
        return $secrete !== [] && SemnaturaStripe::verifica($corp, $antet, $secrete, $this->ceas->acum());
    }

    /** Un secret nou pe aceeași cheie: cel vechi rămâne 24 de ore ca `stripe_whsec_anterior` (auditul de securitate.2026). */
    private function puneSecret(string $secret): void
    {
        $vechi = $this->secrete->citeste('stripe_whsec');
        if ($vechi !== null && !hash_equals($vechi, $secret)) {
            $this->secrete->pune('stripe_whsec_anterior', $vechi);
            $this->setari->pune('stripe.whsec_rotit_la', $this->ceas->acum());
        }
        $this->secrete->pune('stripe_whsec', $secret);
    }

    /** Cheia s-a schimbat sau s-a șters: secretul de acum și cel de dinainte țin de punctul vechi, deci pleacă amândouă. */
    private function stergeSecreteleAnuntului(): void
    {
        $this->secrete->sterge('stripe_whsec');
        $this->secrete->sterge('stripe_whsec_anterior');
        $this->setari->pune('stripe.whsec_rotit_la', null);
    }

    /**
     * Un anunț primit: 'primit' (nou, de procesat), 'duplicat', 'semnatura', 'forma'.
     * @return array{rezultat:string, eveniment:?array}
     */
    public function primeste(string $corp, ?string $antet): array
    {
        if (!$this->semnaturaValida($corp, $antet)) {
            return ['rezultat' => 'semnatura', 'eveniment' => null];
        }
        $e = json_decode($corp, true, 64);
        if (!is_array($e) || !is_string($e['id'] ?? null) || !preg_match('~^evt_[A-Za-z0-9_]{1,200}$~', $e['id']) || !is_string($e['type'] ?? null)) {
            return ['rezultat' => 'forma', 'eveniment' => null];
        }
        try {
            $this->baza->insereaza('stripe_evenimente', ['id' => $e['id'], 'tip' => mb_substr($e['type'], 0, 64), 'obiect' => is_string($e['data']['object']['id'] ?? null)
                ? mb_substr($e['data']['object']['id'], 0, 255) : null, 'primit_la' => $this->ceas->moment(), 'date' => json_encode(self::dateMinime((array) ($e['data']['object'] ?? [])), JSON_UNESCAPED_SLASHES),
                'incercari' => 0]);
        } catch (\PDOException $ex) {
            if ($this->baza->esteDuplicat($ex)) {
                return ['rezultat' => 'duplicat', 'eveniment' => $e];
            }
            throw $ex;
        }
        $a = (array) ($this->setari->get('stripe.anunt') ?? []);
        if (($a['primit_la'] ?? null) === null) {
            $this->setari->pune('stripe.anunt', $a + ['primit_la' => $this->ceas->acum()]);
        }
        return ['rezultat' => 'primit', 'eveniment' => $e];
    }

    /**
     * Câmpurile obiectului de care are nevoie procesarea, fără date personale (fără e-mailul sau numele oaspetelui):
     * păstrate pe rând, ca un anunț rămas neprocesat să se poată relua din cron.
     */
    public static function dateMinime(array $o): array
    {
        $pi = $o['payment_intent'] ?? null;
        return array_filter([
            'id' => is_string($o['id'] ?? null) ? $o['id'] : null,
            'object' => is_string($o['object'] ?? null) ? $o['object'] : null,
            'metadata' => is_string($o['metadata']['comanda'] ?? null) ? ['comanda' => $o['metadata']['comanda']] : null,
            'client_reference_id' => is_string($o['client_reference_id'] ?? null) ? $o['client_reference_id'] : null,
            'payment_intent' => is_string($pi) ? $pi : (is_array($pi) && is_string($pi['id'] ?? null) ? $pi['id'] : null),
            'amount_refunded' => is_int($o['amount_refunded'] ?? null) ? $o['amount_refunded'] : null,
            // Starea unei rambursări (`charge.refund.updated`): eșecul ei se aplică și la reluarea anunțului din cron.
            'status' => ($o['object'] ?? null) === 'refund' && is_string($o['status'] ?? null) && preg_match('~^[a-z_]{1,32}$~', $o['status']) ? $o['status'] : null,
            'failure_reason' => ($o['object'] ?? null) === 'refund' && is_string($o['failure_reason'] ?? null) && preg_match('~^[a-z_]{1,64}$~', $o['failure_reason']) ? $o['failure_reason'] : null,
        ], static fn ($v): bool => $v !== null) + (($o['object'] ?? null) === 'dispute' ? self::dateContestare($o) : []);
    }

    /**
     * Câmpurile unei contestări, fără date personale: suma, starea, motivul băncii, termenul și tranzacțiile de sold
     * (id, comision, moment). Folosite când cheia n-are permisiunea „Disputes: Read” (Plati\Contestari).
     */
    private static function dateContestare(array $o): array
    {
        $txn = [];
        foreach (array_slice((array) ($o['balance_transactions'] ?? []), 0, 10) as $t) {
            if (is_array($t) && is_string($t['id'] ?? null)) {
                $txn[] = ['id' => $t['id'], 'fee' => is_int($t['fee'] ?? null) ? $t['fee'] : 0, 'created' => is_int($t['created'] ?? null) ? $t['created'] : null];
            }
        }
        return array_filter([
            'amount' => is_int($o['amount'] ?? null) ? $o['amount'] : null,
            'status' => is_string($o['status'] ?? null) && preg_match('~^[a-z_]{1,32}$~', $o['status']) ? $o['status'] : null,
            'reason' => is_string($o['reason'] ?? null) && preg_match('~^[a-z_]{1,64}$~', $o['reason']) ? $o['reason'] : null,
            'created' => is_int($o['created'] ?? null) ? $o['created'] : null,
            'evidence_details' => is_int($o['evidence_details']['due_by'] ?? null) ? ['due_by' => $o['evidence_details']['due_by']] : null,
            'balance_transactions' => $txn !== [] ? $txn : null,
        ], static fn ($v): bool => $v !== null);
    }

    /**
     * Contul Stripe al unei chei: id-ul din GET /v1/account, sau null când cheia restricționată n-are acces la el.
     */
    public function contul(?string $cheie = null): ?string
    {
        $c = $cheie !== null ? new StripeClient($this->http, static fn (): string => $cheie, $this->baza_url) : $this->client();
        try {
            $a = $c->get('/v1/account');
            return is_string($a['id'] ?? null) ? $a['id'] : null;
        } catch (EroareStripe) {
            return null;
        }
    }

    /**
     * Cheia nouă e pe același cont cu plățile deschise? $obiecte: id-uri de sesiuni Checkout (cs_) sau PaymentIntent-uri
     * (pi_) ale plăților încă în lucru. Întâi comparația conturilor (GET /v1/account cu ambele chei); dacă una n-are
     * acces, cheia nouă citește un obiect al contului vechi: „resource_missing” înseamnă alt cont.
     */
    public function acelasiCont(string $cheieNoua, array $obiecte): bool
    {
        if ($obiecte === [] || !$this->configurat()) {
            return true;
        }
        $vechi = $this->contul();
        $nou = $this->contul($cheieNoua);
        if ($vechi !== null && $nou !== null) {
            return hash_equals($vechi, $nou);
        }
        $c = new StripeClient($this->http, static fn (): string => $cheieNoua, $this->baza_url);
        $o = (string) $obiecte[0];
        try {
            $c->get(str_starts_with($o, 'cs_') ? '/v1/checkout/sessions/' . $o : '/v1/payment_intents/' . $o);
            return true;
        } catch (EroareStripe) {
            // Lipsă, fără permisiune sau fără răspuns: cu plăți deschise, „nu pot verifica” contează ca alt cont.
            return false;
        }
    }

    /** Metodele de plată oferite la ultima sesiune Checkout (cheia restricționată n-are o listă a lor). */
    public function tineMetode(array $metode): void
    {
        $m = array_values(array_filter($metode, static fn ($x): bool => is_string($x) && preg_match('~^[a-z_]{2,40}$~', $x) === 1));
        if ($m !== [] && $m !== $this->setari->get('stripe.metode')) {
            $this->setari->pune('stripe.metode', $m);
        }
    }

    public function procesat(string $idEveniment): void
    {
        $this->baza->executa('UPDATE {stripe_evenimente} SET procesat_la = ? WHERE id = ?', [$this->ceas->moment(), $idEveniment]);
    }

    /** Curățenia: evenimentele peste 30 de zile. */
    public function curata(): int
    {
        $prag = $this->ceas->moment(-30 * 86400);
        // Fără nimic de șters, nicio scriere (pe SQLite, orice DELETE ia lacătul de scriere).
        if ($this->baza->valoare('SELECT 1 FROM {stripe_evenimente} WHERE primit_la < ? LIMIT 1', [$prag]) === null) {
            return 0;
        }
        return $this->baza->executa('DELETE FROM {stripe_evenimente} WHERE primit_la < ?', [$prag])->rowCount();
    }

    private function amprenta(): ?string
    {
        $c = $this->secrete->citeste('stripe_cheie');
        return $c !== null ? substr(hash('sha256', $c), 0, 16) : null;
    }

    private function scrieVerificare(array $v): void
    {
        $this->setari->pune('stripe.verificare', ['ok' => $v['ok'], 'permisiuni' => $v['permisiuni'], 'la' => $this->ceas->acum(), 'amprenta' => $this->amprenta()]);
    }

    private function anuntPeCheiaNoua(?string $vechea, bool $poateScrie): void
    {
        if ($vechea !== null) {
            // Punctul vechi aparține poate altui cont: îl ștergem cu cheia veche, cât se poate.
            $a = (array) ($this->setari->get('stripe.anunt') ?? []);
            if (is_string($a['id'] ?? null)) {
                try {
                    (new StripeClient($this->http, static fn (): string => $vechea, $this->baza_url))->delete('/v1/webhook_endpoints/' . $a['id']);
                } catch (EroareStripe) {
                }
            }
            $this->setari->pune('stripe.anunt', null);
            $this->stergeSecreteleAnuntului();
        }
        if ($poateScrie) {
            $this->creeazaAnunt();
        } else {
            $this->setari->pune('stripe.anunt', ['mod' => 'manual', 'id' => null, 'url' => $this->adresaAnunt]);
        }
    }

    private function stergeAnuntVechi(): void
    {
        $a = (array) ($this->setari->get('stripe.anunt') ?? []);
        if (($a['mod'] ?? null) === 'auto' && is_string($a['id'] ?? null)) {
            try {
                $this->client()->delete('/v1/webhook_endpoints/' . $a['id']);
            } catch (EroareStripe) {
                // Punctul vechi rămâne în Dashboard; panoul arată doar punctul nou.
            }
        }
    }
}
