<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Setari;
use Cazo\Securitate\Secrete;

defined('CAZO') or exit;

/**
 * Legarea site-ului de EasyCheck, folosită de instalator, de panou (Conexiuni) și de
 * bin/cazo: cheia verificată cu GET /eu înainte de salvare, apoi PUT /webhook cu adresa site-ului (secretul
 * whsec_ criptat pe loc), POST /webhook/proba și așteptarea anunțului `proba`, apoi sincronizarea inițială.
 * Fără anunț, site-ul merge pe interogare, iar starea `ec.anunturi` păstrează rezultatul pentru panou.
 */
final class Conectare
{
    public const ASTEPTARE_PROBA = 30;

    /** @var \Closure(float): void */
    private \Closure $dormi;

    /**
     * @param \Closure(string): Client $clientPentru clientul pentru o cheie anume (cheia nouă, înainte de salvare)
     */
    public function __construct(
        private \Closure $clientPentru,
        private Client $client,
        private Secrete $secrete,
        private Setari $setari,
        private Oglinda $oglinda,
        private Licenta $licenta,
        private Anunturi $anunturi,
        private Buget $buget,
        private Ceas $ceas,
        private string $adresaAnunt,
        ?\Closure $dormi = null,
    ) {
        $this->dormi = $dormi ?? static function (float $s): void {
            usleep((int) ($s * 1_000_000));
        };
    }

    public static function formaCheii(string $cheie): bool
    {
        return (bool) preg_match('~^ec_(live|test)_[0-9A-Za-z]{8}_[0-9A-Za-z]{16,64}$~', $cheie);
    }

    /** Legăturile notate între chei: prefixul cheii noi => prefixul cheii pe care a înlocuit-o. */
    public const LEGATURI = 'ec.chei_legate';

    /** Prefixul cheii (ec_live_<prefix8>_<secret32>: partea a treia), sau null la o altă formă. */
    public static function prefix(?string $cheie): ?string
    {
        $p = $cheie !== null ? (explode('_', $cheie)[2] ?? null) : null;
        return is_string($p) && preg_match('~^[0-9A-Za-z]{8}$~', $p) ? $p : null;
    }

    /**
     * Cheia cu prefixul $acum e legată de cheia cu prefixul $al: aceeași cheie sau o înlocuire notată, direct sau prin
     * lanțul înlocuirilor (EasyCheck primește atunci `inlocuieste` pentru rezervările cheii vechi).
     */
    public function legata(string $acum, string $al): bool
    {
        $legaturi = (array) $this->setari->get(self::LEGATURI, []);
        $vazute = [];
        for ($p = $acum; is_string($p) && !isset($vazute[$p]); $p = $legaturi[$p]['inlocuieste'] ?? null) {
            if ($p === $al) {
                return true;
            }
            $vazute[$p] = true;
        }
        return false;
    }

    /**
     * Cheia $cheieAcum nu poate lucra pe rezervarea comenzii $c: comanda ține prefixul cheii care a creat-o
     * (`comenzi.cheie_prefix`); aceeași cheie sau o cheie legată de ea prin înlocuiri (legata) dă „nu”. La comenzile de
     * dinaintea prefixului, regula de până acum: o cheie pusă după plata comenzii (chiar cu aceeași valoare) dă „da”.
     * Folosită de schimbarea datelor (ordinea pașilor) și de rapoartele plăților.
     */
    public function comandaStraina(array $c, ?string $cheieAcum): bool
    {
        $acum = self::prefix($cheieAcum);
        $al = $c['cheie_prefix'] ?? null;
        if (is_string($al) && $al !== '' && $acum !== null) {
            return !$this->legata($acum, $al);
        }
        $la = $this->secrete->schimbatLa('ec_cheie');
        $reper = $c['platita_la'] ?? $c['creat_la'] ?? null;
        return $la !== null && $reper !== null && strcmp($la, (string) $reper) > 0;
    }

    /**
     * Verifică o cheie nouă cu GET /eu și, la reușită, o salvează (criptată), cu rezumatul ei.
     * @return array rezumatul cheii (Proiectii::eu)
     * @throws EroareApi|EroareTransport|\InvalidArgumentException
     */
    public function legaCheia(string $cheie): array
    {
        $cheie = trim($cheie);
        if (!self::formaCheii($cheie)) {
            throw new \InvalidArgumentException('Cheia EasyCheck are o formă necunoscută.');
        }
        $r = ($this->clientPentru)($cheie)->citeste('/eu');
        $eu = $r->json() ?? throw new \UnexpectedValueException('GET /eu: corpul nu e JSON.');
        $rezumat = Proiectii::eu($eu);
        $vechi = $this->licenta->cheie();
        if ($vechi !== null && ($vechi['proprietate'] !== $rezumat['proprietate'] || ($vechi['mod'] ?? null) !== ($rezumat['mod'] ?? null))) {
            // O cheie a altei proprietăți sau de alt fel (de probă în loc de live): oglinda veche nu mai descrie
            // site-ul și se citește din nou, întreagă.
            $this->oglinda->marcheazaInvechit(array_keys(Oglinda::RESURSE));
        }
        // Pauza de 5 minute pusă de un 401 privea cheia veche; cea de după un Retry-After rămâne.
        $pauza401 = $this->licenta->areUn401();
        // cheia nouă făcută cu „Înlocuiește” din cheia de acum (GET /eu › cheie.inlocuieste.prefix) se notează ca
        // legată, ca mutările rezervărilor existente să meargă; o cheie oarecare se acceptă, cu `legatura` = nelegata.
        // și „Înlocuiește” din cheia anterioară (cele 24 de ore ale rotirii) se notează. `legatura_prefix` e cheia
        // înlocuită (legata) sau cheia de înlocuit cu „Înlocuiește” (nelegata), pe care panoul o numește.
        $legatura = null;
        $legaturaPrefix = null;
        $prefixVechi = self::prefix($this->secrete->citeste('ec_cheie'));
        $prefixNou = self::prefix($cheie);
        if ($prefixVechi !== null && $prefixNou !== null && $prefixVechi !== $prefixNou) {
            $legatura = 'nelegata';
            $legaturaPrefix = $prefixVechi;
            $inlocuit = $rezumat['inlocuieste']['prefix'] ?? null;
            $anterior = $this->prefixAnterior();
            if (is_string($inlocuit) && $inlocuit !== $prefixNou && ($inlocuit === $prefixVechi || $inlocuit === $anterior)) {
                $legaturi = (array) $this->setari->get(self::LEGATURI, []);
                $legaturi[$prefixNou] = ['inlocuieste' => $inlocuit, 'inlocuita_la' => $rezumat['inlocuieste']['inlocuita_la']];
                $this->setari->pune(self::LEGATURI, $legaturi);
                $legatura = 'legata';
                $legaturaPrefix = $inlocuit;
            }
        }
        $this->secrete->pune('ec_cheie', $cheie);
        $this->licenta->cheieNoua();
        $this->licenta->dinEu($eu);
        $this->licenta->dupaRezumat($vechi);
        if ($pauza401) {
            $this->buget->ridicaPauza();
        }
        // Rezumatul nou e salvat aici, înaintea sincronizării: generația cache-ului se schimbă acum, față de cheia
        // de dinainte (proba și live dau antete diferite).
        if (Licenta::schimbaPaginile($vechi, $rezumat)) {
            $this->oglinda->schimbaContinutul();
        }
        return $rezumat + ['legatura' => $legatura, 'legatura_prefix' => $legaturaPrefix];
    }

    /** Prefixul cheii anterioare, cât ține suprapunerea rotirii (24 de ore de la `ec.rotire`); altfel null. */
    private function prefixAnterior(): ?string
    {
        $rot = $this->setari->get('ec.rotire');
        if (!is_array($rot) || !is_int($rot['la'] ?? null) || $this->ceas->acum() - $rot['la'] > 86400) {
            return null;
        }
        return self::prefix($this->secrete->citeste('ec_cheie_anterioara'));
    }

    /**
     * PUT /webhook cu adresa site-ului, secretul nou salvat (cel vechi păstrat 24 h), apoi proba.
     * @return array{inregistrat:bool, proba:?array, primit:bool, motiv:?string}
     */
    public function inregistreazaAnunturile(): array
    {
        if (!$this->buget->permiteScriereNecritica()) {
            return $this->noteaza(['inregistrat' => false, 'proba' => null, 'primit' => false, 'motiv' => 'buget']);
        }
        $r = $this->client->scrie('PUT', '/webhook', Client::json(['adresa' => $this->adresaAnunt]));
        $secret = $r->json()['secret'] ?? null;
        if (!is_string($secret)) {
            throw new \UnexpectedValueException('PUT /webhook: secretul lipsește din răspuns.');
        }
        $this->anunturi->puneSecret($secret);
        $proba = $this->proba();
        return $this->noteaza(['inregistrat' => true] + $proba);
    }

    /**
     * POST /webhook/proba și așteptarea anunțului `proba` (cel mult 30 s). EasyCheck trimite proba pe loc;
     * anunțul ajunge la site printr-o cerere separată, deci se urmărește în setări.
     * @return array{proba:?array, primit:bool, motiv:?string}
     */
    public function proba(): array
    {
        $r = $this->client->scrie('POST', '/webhook/proba', null);
        $p = $r->json() ?? [];
        $id = is_string($p['id'] ?? null) ? $p['id'] : null;
        $proba = ['id' => $id, 'livrat' => ($p['livrat'] ?? null) === true, 'cod_http' => is_int($p['cod_http'] ?? null) ? $p['cod_http'] : null];
        $limita = microtime(true) + self::ASTEPTARE_PROBA;
        do {
            $acum = $this->citesteProba();
            if ($id !== null && ($acum['id'] ?? null) === $id) {
                return ['proba' => $proba, 'primit' => true, 'motiv' => null];
            }
            if ($proba['livrat'] === false) {
                break;
            }
            ($this->dormi)(0.5);
        } while (microtime(true) < $limita);
        $motiv = $proba['livrat'] ? 'neconfirmat' : ($proba['cod_http'] === null ? 'fara_legatura' : 'cod_' . $proba['cod_http']);
        if ($proba['cod_http'] !== null && $proba['cod_http'] >= 300 && $proba['cod_http'] < 400) {
            $motiv = 'redirectare';
        }
        return ['proba' => $proba, 'primit' => false, 'motiv' => $motiv];
    }

    /** Sincronizarea inițială, după legarea cheii: toate resursele, cu bugetul dat. */
    public function sincronizeazaTot(Sincronizare $s, float $buget = 60.0): array
    {
        return $s->ruleaza(array_keys(Oglinda::RESURSE), $buget);
    }

    /** @return array{id:?string, primit_la:?int} */
    private function citesteProba(): array
    {
        // Setările se citesc din bază la fiecare verificare: anunțul îl primește alt proces.
        $v = $this->setari->proaspat('ec.anunt_proba');
        return is_array($v) ? $v : ['id' => null, 'primit_la' => null];
    }

    private function noteaza(array $rez): array
    {
        $this->setari->pune('ec.anunturi', [
            'verificat_la' => $this->ceas->acum(),
            'inregistrat' => $rez['inregistrat'],
            'primit' => $rez['primit'],
            'motiv' => $rez['motiv'],
            'cod_http' => $rez['proba']['cod_http'] ?? null,
        ]);
        return $rez;
    }
}
