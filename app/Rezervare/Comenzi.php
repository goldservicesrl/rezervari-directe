<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\Baza\Baza;
use Cazo\EasyCheck\Client;
use Cazo\Nucleu\Ceas;
use Cazo\Securitate\Chei;
use Cazo\Securitate\Cutie;
use Cazo\Securitate\IndexOrb;

defined('CAZO') or exit;

/**
 * Comenzile: crearea, citirea, datele personale criptate (subcheia „pii”) și indexul orb pe e-mail.
 * Referința (`PG-7K3M9Q`, trimisă ca `ref_site`) are 6 caractere dintr-un alfabet fără semne ușor de confundat.
 * Corpul trimis spre EasyCheck (`corp_ec`) se serializează o singură dată, la creare, și se criptează: reîncercările
 * trimit exact aceiași octeți, cu aceeași cheie de idempotență (`idem_ec`).
 */
final class Comenzi
{
    public const ALFABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private Cutie $pii;

    public function __construct(private Baza $baza, private Ceas $ceas, Chei $chei, private IndexOrb $indexOrb, private LinkOaspete $link)
    {
        $this->pii = new Cutie($chei->subcheie('pii'));
    }

    public static function refNoua(): string
    {
        $r = 'PG-';
        for ($i = 0; $i < 6; $i++) {
            $r .= self::ALFABET[random_int(0, strlen(self::ALFABET) - 1)];
        }
        return $r;
    }

    /**
     * Creează comanda în starea `noua`, cu jurnalul ei și acordul. $d:
     *   fel, limba, proba, sosire, plecare, camere (lista spre API), plan, sursa_pret, total, componente, camere_parti,
     *   avans_procent, suma_acum, rest_la_sosire, anulare_zile, anulare_limita, avans, nerambursabil (regula de reținere
     *   din ziua rezervării), oaspete {nume, email, telefon, tara},
     *   nota, marketing (bool), ip24, acord {termeni_versiune, termeni_hash, confidentialitate_versiune, text_buton,
     *   text_precontractual_hash, text_marketing_hash, ip, agent}, contract (opțional: textele înghețate, Rezerva::contract)
     * @return array{id:int, ref:string, token:string}
     */
    public function creeaza(array $d): array
    {
        $acum = $this->ceas->moment();
        $idem = Client::uuid();
        $o = $d['oaspete'];
        for ($incercare = 0; ; $incercare++) {
            $ref = self::refNoua();
            $token = $this->link->token($ref, $idem);
            $corp = self::corpEc($d, $ref);
            try {
                $id = $this->baza->tranzactie(function (Baza $b) use ($d, $o, $ref, $idem, $token, $corp, $acum): int {
                    $id = $b->insereaza('comenzi', [
                        'ref' => $ref,
                        'fel' => $d['fel'],
                        'stare' => 'noua',
                        'limba' => $d['limba'],
                        'proba' => (int) $d['proba'],
                        'sosire' => $d['sosire'],
                        'plecare' => $d['plecare'],
                        'camere' => self::json($d['camere']),
                        'plan' => $d['plan'],
                        'sursa_pret' => $d['sursa_pret'],
                        'total' => $d['total'],
                        'componente' => self::json($d['componente']),
                        'camere_parti' => isset($d['camere_parti']) ? self::json($d['camere_parti']) : null,
                        'reduceri' => isset($d['reduceri']) ? self::json($d['reduceri']) : null,
                        'avans_procent' => $d['avans_procent'] ?? null,
                        'plata_aleasa' => $d['plata_aleasa'] ?? null,
                        'taxa_incasare' => $d['taxa_incasare'] ?? null,
                        // prefixul cheii EasyCheck a comenzii (schimbarea datelor verifică legătura cheii de acum).
                        'cheie_prefix' => $d['cheie_prefix'] ?? null,
                        'suma_acum' => $d['suma_acum'] ?? 0,
                        'rest_la_sosire' => $d['rest_la_sosire'] ?? $d['total'],
                        'anulare_zile' => $d['anulare_zile'] ?? null,
                        'anulare_limita' => $d['anulare_limita'] ?? null,
                        // Regula de reținere din ziua rezervării: avansul planului și tariful nerambursabil.
                        'avans' => isset($d['avans']) ? (int) $d['avans'] : null,
                        'nerambursabil' => (int) (bool) ($d['nerambursabil'] ?? false),
                        // alegerea gazdei pentru reținerea după o mutare pe alte date, din ziua comenzii (spusă în contract).
                        'avans_mutare' => $d['avans_mutare'] ?? null,
                        // ce primește oaspetele când gazda anulează, din ziua comenzii (spus în contract).
                        'anulare_gazda' => $d['anulare_gazda'] ?? null,
                        // Contractul înghețat la comandă, fără date personale ale oaspetelui.
                        'contract' => isset($d['contract']) ? self::json($d['contract']) : null,
                        // Transferul bancar: termenul de plată trimis în corp și contul arătat oaspetelui, înghețate la comandă.
                        'transfer_termen' => isset($d['transfer']['termen']) ? gmdate('Y-m-d H:i:s', (int) $d['transfer']['termen']) : null,
                        'transfer_cont' => isset($d['transfer']['cont']) ? self::json($d['transfer']['cont']) : null,
                        'oaspete' => $this->pii->cripteaza(self::json($o)),
                        'email_io' => $this->indexOrb->email($o['email']),
                        'tara' => $o['tara'] ?? null,
                        'nota' => ($d['nota'] ?? '') !== '' ? $this->pii->cripteaza((string) $d['nota']) : null,
                        'marketing_acord' => (int) (bool) $d['marketing'],
                        'idem_ec' => $idem,
                        'corp_ec' => $this->pii->cripteaza($corp),
                        'token_oaspete_hash' => LinkOaspete::hash($token),
                        'incercari' => 0,
                        'ip24' => $d['ip24'],
                        'creat_la' => $acum,
                        'actualizat_la' => $acum,
                    ]);
                    $a = $d['acord'];
                    $b->insereaza('acorduri', [
                        'comanda_id' => $id,
                        'termeni_versiune' => $a['termeni_versiune'],
                        'termeni_hash' => $a['termeni_hash'],
                        'confidentialitate_versiune' => $a['confidentialitate_versiune'],
                        'text_buton' => $a['text_buton'],
                        'text_precontractual_hash' => $a['text_precontractual_hash'],
                        'marketing' => (int) (bool) $d['marketing'],
                        'text_marketing_hash' => $d['marketing'] ? $a['text_marketing_hash'] : null,
                        'la' => $acum,
                        'ip' => $this->pii->cripteaza((string) $a['ip']),
                        'agent' => $this->pii->cripteaza(mb_substr((string) $a['agent'], 0, 300)),
                    ]);
                    $b->insereaza('comenzi_jurnal', ['comanda_id' => $id, 'la' => $acum, 'din_stare' => null, 'in_stare' => 'noua', 'actor' => 'oaspete', 'detalii' => null]);
                    return $id;
                });
                return ['id' => $id, 'ref' => $ref, 'token' => $token];
            } catch (\PDOException $e) {
                if (!$this->baza->esteDuplicat($e) || $incercare >= 5) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Corpul POST /cereri (fără `plata`) sau POST /rezervari: lista `camere`, un singur plan pe toată rezervarea,
     * totalul în lei, referința site-ului, oaspetele și nota; la „Prețul din EasyCheck”, și `include`. La
     * transferul bancar (`$d['transfer']`: suma și termenul), și `plata_asteptata`, cu codul comenzii ca referință a plății.
     */
    public static function corpEc(array $d, string $ref): string
    {
        $camere = [];
        foreach ($d['camere'] as $c) {
            $camere[] = ['camera' => $c['camera'], 'adulti' => (int) $c['adulti'], 'copii' => (int) $c['copii']];
        }
        $o = $d['oaspete'];
        $corp = ['camere' => $camere, 'sosire' => $d['sosire'], 'plecare' => $d['plecare']];
        if ($d['plan'] !== null) {
            $corp['plan'] = $d['plan'];
        }
        $corp += ['total' => (int) $d['total'], 'moneda' => 'RON', 'ref_site' => $ref,
            'oaspete' => ['nume' => $o['nume'], 'email' => $o['email'], 'telefon' => $o['telefon'] ?? null, 'tara' => $o['tara'] ?? null]];
        if (($d['nota'] ?? '') !== '') {
            $corp['nota'] = (string) $d['nota'];
        }
        // ce a intrat în totalul afișat pe lângă nopți (alegerea gazdei din ziua comenzii), ca EasyCheck să verifice
        // același total; lipsește la „Prețul de pe site-ul meu”.
        if (is_array($d['include'] ?? null)) {
            $corp['include'] = ['taxe' => (bool) ($d['include']['taxe'] ?? true), 'taxa_locala' => (bool) ($d['include']['taxa_locala'] ?? true)];
        }
        // când se încasează taxa locală, declarat odată cu totalul (alegerea gazdei din ziua comenzii).
        if (in_array($d['taxa_locala_incasare'] ?? null, ['online', 'la_sosire'], true)) {
            $corp['taxa_locala_incasare'] = $d['taxa_locala_incasare'];
        }
        // acordul pleacă și cu cererea cash (`POST /cereri` îl primește din EasyCheck 7.550.152).
        if (is_array($d['oferte'] ?? null)) {
            $corp['oferte'] = $d['oferte'];
        }
        // clauza anulării gazdei din contractul oaspetelui, ca EasyCheck s-o arate la anulare.
        if (is_array($d['contract_anulare'] ?? null)) {
            $corp['contract_anulare'] = $d['contract_anulare'];
        }
        if (is_array($d['transfer'] ?? null)) {
            $corp['plata_asteptata'] = Transfer::plataAsteptata((int) $d['transfer']['suma'], (int) $d['transfer']['termen'], $ref);
        }
        return self::json($corp);
    }

    public function dupaId(int $id): ?array
    {
        return $this->baza->rand('SELECT * FROM {comenzi} WHERE id = ?', [$id]);
    }

    public function dupaRef(string $ref): ?array
    {
        if (!preg_match('~^PG-[' . self::ALFABET . ']{6}$~', $ref)) {
            return null;
        }
        return $this->baza->rand('SELECT * FROM {comenzi} WHERE ref = ?', [$ref]);
    }

    public function dupaToken(string $token): ?array
    {
        if (!LinkOaspete::tokenValid($token)) {
            return null;
        }
        $r = $this->baza->rand('SELECT * FROM {comenzi} WHERE token_oaspete_hash = ?', [LinkOaspete::hash($token)]);
        // Comparația în timp constant, pe lângă căutarea după amprentă.
        return $r !== null && hash_equals((string) $r['token_oaspete_hash'], LinkOaspete::hash($token)) ? $r : null;
    }

    public function token(array $c): string
    {
        return $this->link->token((string) $c['ref'], (string) $c['idem_ec']);
    }

    /** @return array{nume:string, email:string, telefon:?string, tara:?string} sau [] după anonimizare */
    public function oaspete(array $c): array
    {
        if ($c['anonimizat_la'] !== null || $c['oaspete'] === null || $c['oaspete'] === '') {
            return [];
        }
        $o = json_decode($this->pii->decripteaza((string) $c['oaspete']), true);
        return is_array($o) ? $o : [];
    }

    public function nota(array $c): string
    {
        return $c['nota'] !== null && $c['nota'] !== '' ? $this->pii->decripteaza((string) $c['nota']) : '';
    }

    public function corpEcDin(array $c): string
    {
        return $this->pii->decripteaza((string) $c['corp_ec']);
    }

    public function cripteaza(string $text): string
    {
        return $this->pii->cripteaza($text);
    }

    public function decripteaza(string $text): string
    {
        return $this->pii->decripteaza($text);
    }

    public function emailIo(string $email): string
    {
        return $this->indexOrb->email($email);
    }

    /** Cererile cash și rezervările cu transfer în așteptare pe un e-mail (limita: cel mult 2). */
    public function inAsteptarePeEmail(string $email): int
    {
        return (int) $this->baza->valoare("SELECT COUNT(*) FROM {comenzi} WHERE fel IN ('cash', 'transfer') AND email_io = ? AND stare IN ('noua', 'trimitere_ec', 'cerere_in_asteptare', 'transfer_in_asteptare')",
            [$this->indexOrb->email($email)]);
    }

    /** Cererile cash și rezervările cu transfer create în ultimele 24 de ore din aceeași rețea (limita: cel mult 5). */
    public function peIpAzi(string $ip24): int
    {
        return (int) $this->baza->valoare("SELECT COUNT(*) FROM {comenzi} WHERE fel IN ('cash', 'transfer') AND ip24 = ? AND creat_la > ?", [$ip24, $this->ceas->moment(-86400)]);
    }

    public function camere(array $c): array
    {
        return json_decode((string) $c['camere'], true) ?: [];
    }

    public function componente(array $c): array
    {
        return json_decode((string) $c['componente'], true) ?: [];
    }

    public static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
