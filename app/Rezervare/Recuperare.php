<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Recuperarea după 6 ore fără răspuns la POST /rezervari: înainte de rambursare, site-ul caută
 * rezervarea după `ref_site` în GET /rezervari?actualizate_de_la=<creat_la - 1 minut>, urmând `dupa` până la null,
 * ca o rezervare creată în tăcere (cu răspunsul pierdut) să nu fie rambursată. Găsită: `confirmata`, cu e-mailurile.
 * Negăsită: rambursarea integrală, e-mailul către oaspete și alerta la gazdă. Lista indisponibilă: comanda rămâne
 * `platita`, iar căutarea se reia peste 30 de minute.
 */
final class Recuperare
{
    public const PAGINI = 50;
    public const REIA = 1800;
    /** De la prima trimitere: după atâtea secunde fără o listă completă, gazda și oaspetele află. */
    public const NECUNOSCUT = 24 * 3600;

    public function __construct(private Aplicatie $app)
    {
    }

    public function verifica(array $c, string $actor): string
    {
        $id = (int) $c['id'];
        try {
            $rez = $this->cauta((string) $c['ref'], Ceas::dinMoment((string) $c['creat_la']) - 60);
        } catch (EroareApi|EroareTransport $e) {
            $rez = ['gasita' => null, 'completa' => false];
        }
        $gasita = $rez['gasita'];
        if ($gasita === null && !$rez['completa']) {
            // Lista lipsă sau citită doar în parte: rezultatul e necunoscut, deci fără rambursare (o rezervare creată
            // în tăcere ar rămâne fără bani). Căutarea se reia la 30 de minute.
            $this->app->baza()->executa("UPDATE {comenzi} SET urmatoarea_incercare = ?, actualizat_la = ? WHERE id = ? AND stare = 'platita'",
                [$this->app->ceas()->moment(self::REIA), $this->app->ceas()->moment(), $id]);
            $this->app->masinaStari()->jurnal($id, 'platita', 'platita', $actor, ['recuperare' => 'necunoscut']);
            $trimisa = Ceas::dinMoment((string) ($c['trimisa_la'] ?? $c['creat_la']));
            if ($this->app->ceas()->acum() - $trimisa >= self::NECUNOSCUT
                && $this->app->baza()->executa("UPDATE {comenzi} SET intarziata_la = ? WHERE id = ? AND intarziata_la IS NULL AND stare = 'platita'", [$this->app->ceas()->moment(), $id])->rowCount() === 1) {
                // După 24 de ore fără o listă completă: gazda verifică în EasyCheck, oaspetele află că verificăm.
                $this->app->alertaGazda('alerta_recuperare_necunoscuta', ['ref' => (string) $c['ref'], 'sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare']],
                    'recuperare_necunoscuta:' . $c['ref'], 30 * 86400);
                $this->app->plataOnline()->anunta($id, 'plata_verificare');
            }
            return 'platita';
        }
        $m = $this->app->masinaStari();
        if ($gasita !== null) {
            // rezervarea găsită; suma autorizată se capturează, apoi comanda e confirmată.
            if ($m->trece($id, 'card', 'platita', 'de_capturat', $actor, ['ec_rezervare' => $gasita, 'urmatoarea_incercare' => null, 'eroare_cod' => null], ['recuperare' => 'gasita', 'rezervare' => $gasita])) {
                return $this->app->plataOnline()->captureaza($id, $actor);
            }
            return (string) $this->app->comenzi()->dupaId($id)['stare'];
        }
        if ($m->trece($id, 'card', 'platita', 'de_rambursat', $actor, ['eroare_cod' => 'ec_indisponibil', 'motiv_rambursare' => 'ec_indisponibil', 'urmatoarea_incercare' => null],
            ['recuperare' => 'negasita'])) {
            $this->app->alertaGazda('alerta_recuperare', ['ref' => (string) $c['ref'], 'sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare']],
                'recuperare:' . $c['ref'], 30 * 86400);
            $this->app->plataOnline()->dupaRefuz($id);
        }
        return (string) $this->app->comenzi()->dupaId($id)['stare'];
    }

    /** Cursoarele rămase de la comenzile încheiate pe orice cale (confirmate, rambursate, închise). */
    public function curataCursoarele(): int
    {
        $n = 0;
        foreach ($this->app->baza()->toate("SELECT cheie FROM {setari} WHERE cheie LIKE 'recuperare.cursor.%'") as $r) {
            $ref = substr((string) $r['cheie'], strlen('recuperare.cursor.'));
            $c = $this->app->comenzi()->dupaRef($ref);
            if ($c === null || $c['stare'] !== 'platita') {
                $this->app->setari()->pune((string) $r['cheie'], null);
                $this->app->baza()->executa('DELETE FROM {setari} WHERE cheie = ?', [$r['cheie']]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Rezervarea (`rez_...`) cu `ref_site` = $ref, în starea confirmata. `completa` = lista s-a citit până la capăt
     * (`dupa` null); doar atunci o rezervare negăsită înseamnă „negăsită”.
     * @return array{gasita:?string, completa:bool}
     */
    public function cauta(string $ref, int $de): array
    {
        $q = ['actualizate_de_la' => gmdate('Y-m-d\TH:i:s\Z', $de)];
        // Cursorul `dupa` se păstrează între rulări: o listă mai lungă de 50 de pagini se citește în continuare de unde a
        // rămas, nu de la capăt, deci se termină odată (rezervările deja citite nu erau ale comenzii).
        $cheie = 'recuperare.cursor.' . $ref;
        $cursor = $this->app->setari()->get($cheie);
        if (is_string($cursor) && $cursor !== '') {
            $q['dupa'] = $cursor;
        }
        for ($i = 0; $i < self::PAGINI; $i++) {
            try {
                $j = $this->app->ecClient()->citeste('/rezervari', $q)->json() ?? [];
            } catch (EroareApi $e) {
                // Un cursor respins (4xx, de exemplu expirat): îl ștergem, iar rularea următoare reia lista de la început.
                // 429 și 408 sunt trecătoare: cursorul rămâne.
                if (isset($q['dupa']) && $e->raspuns->status >= 400 && $e->raspuns->status < 500 && !in_array($e->raspuns->status, [408, 429], true)) {
                    $this->app->setari()->pune($cheie, null);
                }
                throw $e;
            }
            foreach ((array) ($j['rezervari'] ?? []) as $r) {
                if (is_array($r) && ($r['ref_site'] ?? null) === $ref && ($r['stare'] ?? null) === 'confirmata' && is_string($r['id'] ?? null)
                    && preg_match('~^rez_[0-9A-Za-z]{1,64}$~', $r['id'])) {
                    $this->app->setari()->pune($cheie, null);
                    return ['gasita' => $r['id'], 'completa' => true];
                }
            }
            if (!is_string($j['dupa'] ?? null) || $j['dupa'] === '') {
                $this->app->setari()->pune($cheie, null);
                return ['gasita' => null, 'completa' => true];
            }
            $q['dupa'] = $j['dupa'];
            $this->app->setari()->pune($cheie, $j['dupa']);
        }
        return ['gasita' => null, 'completa' => false];
    }
}
