<?php

declare(strict_types=1);

/**
 * Etapa 3 din „EasyCheck întâi” (contract-comenzi.md) în dublurile EasyCheck: serverul fals (teste/fals/easycheck.php)
 * și EasyCheck-ul fără rețea al testelor (CereriFalse). Logica e aceeași pentru amândouă, pe o stare în tablou:
 *
 *   $st['functii']['comenzi']   funcția pornită (altfel toate rutele noi: 404 negasit, înaintea validării)
 *   $st['rezervari'][id]        rezervările (`plata.suma` sau `corp.plata.suma` = plătit), cu `anulare` și `cerere_anulare`
 *   $st['comenzi'][id]          comenzile
 *   $st['idem3'][cheie]         idempotența rutelor cu `Idempotency-Key` (cerere-anulare, rezultat)
 *   $st['rezultate'][]          rapoartele primite (corpul, cheia, răspunsul)
 *   $st['cereri_anulare'][]     cererile de anulare primite
 *   $st['contracte'][id]        clauzele primite prin PUT .../contract-anulare
 *   $st['preia_409'][id]        cârlig: `preia` răspunde 409 cu starea dată (o comandă retrasă chiar atunci)
 *
 * trateaza() întoarce [status, corp|null] sau null când ruta nu e a etapei 3. Cârligele testelor (gazda în EasyCheck):
 * comanda(), anuleazaGazda(), decide(), expira(), retrage().
 */
final class FalsComenzi
{
    public const STARI = ['in_asteptare', 'preluata', 'in_procesare', 'reusita', 'esuata', 'refuzata', 'retrasa', 'expirata'];
    public const FINALE = ['reusita', 'esuata', 'refuzata', 'retrasa', 'expirata'];
    public const CODURI = ['rambursari_oprite', 'peste_plafon_zilnic', 'rezervare_necunoscuta', 'fara_plata_card', 'peste_incasat', 'mod_amestecat', 'tip_necunoscut', 'date_sterse',
        'stripe_cheie', 'stripe_sold', 'stripe_refuz', 'fara_confirmare'];
    /** Comanda `mesaj`: mesajele permise și limita pe rezervare pe zi. */
    public const MESAJE = ['retrimite_confirmarea', 'restituire_transfer'];
    public const MESAJE_PE_ZI = 5;
    /** Referințele părților (ca referințele etapei 2). */
    private const REF = '~^[A-Za-z0-9_.-]{1,100}$~D';
    private const MOMENT = '~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$~D';

    public static function pornita(array $st): bool
    {
        return (($st['functii']['comenzi'] ?? false) === true);
    }

    public static function moment(int $t): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    /** Rezervarea în forma contractului: `anulare` și `cerere_anulare` doar cu funcția pornită. */
    public static function forma(array $st, array $r): array
    {
        if (self::pornita($st)) {
            $r['anulare'] ??= null;
            $r['cerere_anulare'] ??= null;
        } else {
            unset($r['anulare'], $r['cerere_anulare']);
        }
        return $r;
    }

    public static function platit(array $r): int
    {
        $p = $r['plata']['suma'] ?? $r['corp']['plata']['suma'] ?? 0;
        return is_int($p) ? $p : 0;
    }

    private static function id(): string
    {
        $a = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $s = 'cmd_';
        for ($i = 0; $i < 24; $i++) {
            $s .= $a[random_int(0, 61)];
        }
        return $s;
    }

    private static function eroare(int $status, string $cod, ?array $detalii = null): array
    {
        $e = ['cod' => $cod, 'mesaj' => 'Eroare de probă: ' . $cod, 'request_id' => 'req_fals'];
        if ($detalii !== null) {
            $e['detalii'] = $detalii;
        }
        return [$status, ['eroare' => $e]];
    }

    // ---- cârligele: gazda în EasyCheck ----

    /** O comandă nouă (`in_asteptare`, 72 de ore), cum o scrie EasyCheck la hotărârea gazdei. */
    public static function comanda(array &$st, string $rez, int $suma, string $motiv, ?string $mesaj, int $acum): array
    {
        $id = self::id();
        $st['comenzi'][$id] = ['id' => $id, 'tip' => 'rambursare', 'stare' => 'in_asteptare', 'rezervare' => $rez, 'ref_site' => $st['rezervari'][$rez]['ref_site'] ?? null,
            'suma' => $suma, 'moneda' => 'RON', 'motiv' => $motiv, 'mesaj' => $mesaj, 'parametri' => null, 'creata_la' => self::moment($acum),
            'expira_la' => self::moment($acum + 72 * 3600), 'preluata_la' => null, 'rezultat' => null, 'actualizat_la' => self::moment($acum), 'proba' => true];
        return $st['comenzi'][$id];
    }

    /** O comandă `mesaj` (etapa 4): fără sumă, cu parametrii. */
    public static function comandaMesaj(array &$st, string $rez, string $mesaj, ?array $parametri, int $acum): array
    {
        $id = self::id();
        $st['comenzi'][$id] = ['id' => $id, 'tip' => 'mesaj', 'stare' => 'in_asteptare', 'rezervare' => $rez, 'ref_site' => $st['rezervari'][$rez]['ref_site'] ?? null,
            'suma' => null, 'moneda' => null, 'motiv' => null, 'mesaj' => $mesaj, 'parametri' => $parametri, 'creata_la' => self::moment($acum),
            'expira_la' => self::moment($acum + 72 * 3600), 'preluata_la' => null, 'rezultat' => null, 'actualizat_la' => self::moment($acum), 'proba' => true];
        return $st['comenzi'][$id];
    }

    /** O comandă de rambursare vie pe rezervare (indexul privește doar rambursările). */
    public static function rambursareVie(array $st, string $rid): bool
    {
        return array_filter($st['comenzi'] ?? [], static fn (array $c): bool => $c['rezervare'] === $rid && ($c['tip'] ?? 'rambursare') === 'rambursare'
            && in_array($c['stare'], ['in_asteptare', 'preluata', 'in_procesare'], true)) !== [];
    }

    /**
     * Anularea gazdei cu hotărârea despre bani: `motiv` decizia_gazdei, sau cerere_oaspete la o cerere în
     * așteptare; la `cale = comanda`, comanda de rambursare.
     */
    public static function anuleazaGazda(array &$st, string $rez, int $restituie, ?string $cale, int $acum, bool $fortaMajora = false, ?int $despagubire = null): ?array
    {
        $r = &$st['rezervari'][$rez];
        $cale ??= $restituie === 0 ? 'fara' : 'comanda';
        $cerere = ($r['cerere_anulare']['stare'] ?? null) === 'in_asteptare';
        $cmd = $cale === 'comanda' && $restituie > 0 ? self::comanda($st, $rez, $restituie, $cerere ? 'anulare_oaspete' : 'anulare_gazda', null, $acum) : null;
        $m = self::moment($acum);
        $r['stare'] = 'anulata';
        $r['anulare'] = ['de' => 'gazda', 'motiv' => $cerere ? 'cerere_oaspete' : 'decizia_gazdei', 'la' => $m,
            'restituire' => ['suma' => $restituie, 'cale' => $cale, 'comanda' => $cmd['id'] ?? null, 'decisa_la' => $m],
            'despagubire' => $despagubire !== null ? ['suma' => $despagubire] : null, 'forta_majora' => $fortaMajora];
        if ($cerere) {
            $r['cerere_anulare']['stare'] = 'acceptata';
            $r['cerere_anulare']['decisa_la'] = $m;
        }
        $r['actualizat_la'] = $m;
        return $cmd;
    }

    /** Anularea gazdei de dinainte de etapa 3 sau fără hotărâre (`restituire` null). */
    public static function anuleazaFaraHotarare(array &$st, string $rez, int $acum): void
    {
        $m = self::moment($acum);
        $st['rezervari'][$rez]['stare'] = 'anulata';
        $st['rezervari'][$rez]['anulare'] = ['de' => 'gazda', 'motiv' => 'decizia_gazdei', 'la' => $m, 'restituire' => null, 'despagubire' => null, 'forta_majora' => false];
        $st['rezervari'][$rez]['actualizat_la'] = $m;
    }

    /** Hotărârea pe cererea de anulare: `accepta` (cu suma) sau `pastreaza`. */
    public static function decide(array &$st, string $rez, string $decizie, int $restituie, int $acum, ?string $cale = null): ?array
    {
        if ($decizie === 'accepta') {
            return self::anuleazaGazda($st, $rez, $restituie, $cale, $acum);
        }
        $m = self::moment($acum);
        $st['rezervari'][$rez]['cerere_anulare']['stare'] = 'respinsa';
        $st['rezervari'][$rez]['cerere_anulare']['decisa_la'] = $m;
        $st['rezervari'][$rez]['actualizat_la'] = $m;
        return null;
    }

    /** Cronul EasyCheck: comenzile `in_asteptare` cu `expira_la` trecut devin `expirata`. */
    public static function expira(array &$st, int $acum): int
    {
        $n = 0;
        foreach ($st['comenzi'] ?? [] as $id => $c) {
            if ($c['stare'] === 'in_asteptare' && strtotime($c['expira_la']) <= $acum) {
                $st['comenzi'][$id]['stare'] = 'expirata';
                $st['comenzi'][$id]['actualizat_la'] = self::moment($acum);
                $n++;
            }
        }
        return $n;
    }

    public static function retrage(array &$st, string $id, int $acum): bool
    {
        if (($st['comenzi'][$id]['stare'] ?? null) !== 'in_asteptare') {
            return false;
        }
        $st['comenzi'][$id]['stare'] = 'retrasa';
        $st['comenzi'][$id]['actualizat_la'] = self::moment($acum);
        return true;
    }

    // ---- rutele ----

    /** @return array{0:int, 1:?array}|null */
    public static function trateaza(array &$st, string $metoda, string $cale, array $q, array $antete, ?string $corp, int $acum): ?array
    {
        $etapa3 = preg_match('~^/comenzi(/|$)~', $cale) || preg_match('~^/rezervari/[^/]+/(cerere-anulare|contract-anulare|proba-anuleaza-gazda|proba-anulare-decide|proba-rambursare|proba-mesaj)$~', $cale);
        if (!$etapa3) {
            return null;
        }
        if (!self::pornita($st)) {
            // Funcția oprită arată exact ca un EasyCheck vechi: 404 înaintea validării și a cheii de idempotență.
            return self::eroare(404, 'negasit');
        }
        $idem = (string) ($antete['idempotency-key'] ?? $antete['Idempotency-Key'] ?? '');
        $j = $corp !== null && $corp !== '' ? json_decode($corp, true) : [];
        if ($metoda === 'GET' && $cale === '/comenzi') {
            $stare = (string) ($q['stare'] ?? 'in_asteptare');
            if (!in_array($stare, [...self::STARI, 'vii'], true)) {
                return self::eroare(422, 'validare', ['campuri' => ['stare']]);
            }
            self::expira($st, $acum);
            $vii = ['in_asteptare', 'preluata', 'in_procesare'];
            $l = array_values(array_filter($st['comenzi'] ?? [], static fn (array $c): bool => $stare === 'vii' ? in_array($c['stare'], $vii, true) : $c['stare'] === $stare));
            usort($l, static fn (array $a, array $b): int => [$a['creata_la'], $a['id']] <=> [$b['creata_la'], $b['id']]);
            return [200, ['comenzi' => array_slice($l, 0, 50), 'urmatoarea' => null, 'proba' => true]];
        }
        if (preg_match('~^/comenzi/(cmd_[0-9A-Za-z]{24})(/preia|/rezultat|/proba-retrage)?$~', $cale, $m)) {
            $c = $st['comenzi'][$m[1]] ?? null;
            if ($c === null) {
                return self::eroare(404, 'negasit');
            }
            $act = $m[2] ?? '';
            if ($metoda === 'GET' && $act === '') {
                return [200, $c];
            }
            if ($metoda === 'POST' && $act === '/proba-retrage') {
                return self::retrage($st, $m[1], $acum) ? [200, $st['comenzi'][$m[1]]] : self::eroare(409, 'stare_invalida', ['stare' => $c['stare']]);
            }
            if ($metoda === 'POST' && $act === '/preia') {
                if (isset($st['preia_409'][$m[1]])) {
                    $st['comenzi'][$m[1]]['stare'] = $st['preia_409'][$m[1]];
                    unset($st['preia_409'][$m[1]]);
                    return self::eroare(409, 'stare_invalida', ['stare' => $st['comenzi'][$m[1]]['stare']]);
                }
                $st['preluari'][] = $m[1];
                if ($c['stare'] === 'in_asteptare' && strtotime($c['expira_la']) <= $acum) {
                    $st['comenzi'][$m[1]]['stare'] = 'expirata';
                    return self::eroare(409, 'stare_invalida', ['stare' => 'expirata']);
                }
                if ($c['stare'] === 'in_asteptare') {
                    $st['comenzi'][$m[1]]['stare'] = 'preluata';
                    $st['comenzi'][$m[1]]['preluata_la'] = self::moment($acum);
                    $st['comenzi'][$m[1]]['actualizat_la'] = self::moment($acum);
                    return [200, $st['comenzi'][$m[1]]];
                }
                if (in_array($c['stare'], ['preluata', 'in_procesare'], true)) {
                    return [200, $c];
                }
                return self::eroare(409, 'stare_invalida', ['stare' => $c['stare']]);
            }
            if ($metoda === 'POST' && $act === '/rezultat') {
                return self::rezultat($st, $m[1], $idem, (string) $corp, is_array($j) ? $j : null, $acum);
            }
            return self::eroare(405, 'metoda');
        }
        if (!preg_match('~^/rezervari/(rez_[0-9A-Za-z]{24})/([a-z-]+)$~', $cale, $m) || !isset($st['rezervari'][$m[1]])) {
            return self::eroare(404, 'negasit');
        }
        $rid = $m[1];
        $r = $st['rezervari'][$rid];
        switch ($metoda . ' ' . $m[2]) {
            case 'POST cerere-anulare':
                if ($idem === '') {
                    return self::eroare(400, 'idempotency_lipsa');
                }
                if (isset($st['idem3'][$idem])) {
                    return $st['idem3'][$idem]['hash'] === hash('sha256', (string) $corp) ? $st['idem3'][$idem]['r'] : self::eroare(409, 'idempotency_conflict');
                }
                $gr = [];
                if (!is_array($j) || !is_string($j['ceruta_la'] ?? null) || !preg_match(self::MOMENT, $j['ceruta_la']) || strtotime($j['ceruta_la']) > $acum + 300) {
                    $gr[] = 'ceruta_la';
                }
                if (is_array($j) && array_key_exists('propunere', $j)) {
                    $p = $j['propunere'];
                    if (!is_array($p) || !is_int($p['restituie'] ?? null) || $p['restituie'] < 0 || !is_int($p['retine'] ?? null) || $p['retine'] < 0) {
                        $gr[] = 'propunere';
                    }
                }
                if ($gr !== []) {
                    return self::eroare(422, 'validare', ['campuri' => $gr]);
                }
                if ($r['stare'] === 'anulata') {
                    return self::eroare(409, 'stare_invalida', ['motiv' => 'anulata']);
                }
                if ($r['stare'] === 'in_asteptarea_platii') {
                    return self::eroare(409, 'stare_invalida', ['motiv' => 'in_asteptarea_platii']);
                }
                if (($r['cerere_anulare']['stare'] ?? null) === 'in_asteptare') {
                    return self::eroare(409, 'stare_invalida', ['motiv' => 'cerere_existenta']);
                }
                $st['rezervari'][$rid]['cerere_anulare'] = ['stare' => 'in_asteptare', 'ceruta_la' => $j['ceruta_la'], 'decisa_la' => null];
                $st['rezervari'][$rid]['propunere'] = $j['propunere'] ?? null;
                $st['rezervari'][$rid]['actualizat_la'] = self::moment($acum);
                $st['cereri_anulare'][] = ['rezervare' => $rid, 'corp' => $j, 'cheie' => $idem];
                $rasp = [201, ['rezervare' => $rid, 'cerere_anulare' => $st['rezervari'][$rid]['cerere_anulare'], 'proba' => true]];
                $st['idem3'][$idem] = ['hash' => hash('sha256', (string) $corp), 'r' => $rasp];
                return $rasp;
            case 'DELETE cerere-anulare':
                $ca = $r['cerere_anulare'] ?? null;
                if (is_array($ca) && in_array($ca['stare'], ['acceptata', 'respinsa'], true)) {
                    return self::eroare(409, 'stare_invalida', ['motiv' => 'decisa']);
                }
                if (is_array($ca) && $ca['stare'] === 'in_asteptare') {
                    $st['rezervari'][$rid]['cerere_anulare']['stare'] = 'retrasa';
                    $st['rezervari'][$rid]['cerere_anulare']['decisa_la'] = self::moment($acum);
                }
                return [204, null];
            case 'PUT contract-anulare':
                if (!is_array($j) || !in_array($j['gazda'] ?? null, ['tot', 'despagubire', 'caz'], true)
                    || (array_key_exists('avans', $j) && (!is_int($j['avans']) || $j['avans'] < 0)) || (($j['gazda'] ?? null) === 'despagubire' && !is_int($j['avans'] ?? null))) {
                    return self::eroare(422, 'validare', ['campuri' => ['contract_anulare']]);
                }
                if (isset($st['contracte'][$rid]) && $st['contracte'][$rid] !== $j) {
                    return self::eroare(409, 'stare_invalida', ['motiv' => 'deja_fixat']);
                }
                $st['contracte'][$rid] = $j;
                return [200, ['rezervare' => $rid, 'contract_anulare' => $j]];
            case 'POST proba-anuleaza-gazda':
            case 'POST proba-anulare-decide':
            case 'POST proba-rambursare':
            case 'POST proba-mesaj':
                return self::proba($st, $rid, $m[2], is_array($j) ? $j : [], $acum);
        }
        return self::eroare(405, 'metoda');
    }

    /** Rutele de probă: același drum ca hotărârile din interfață, fără parolă. */
    private static function proba(array &$st, string $rid, string $ruta, array $j, int $acum): array
    {
        $r = $st['rezervari'][$rid];
        $platit = self::platit($r);
        $vie = self::rambursareVie($st, $rid);
        $cmd = null;
        if ($ruta === 'proba-mesaj') {
            // Etapa 4: cu `functii.mesaje`; cel mult 5 pe rezervare pe zi; nu o oprește o rambursare vie.
            if (($st['functii']['mesaje'] ?? false) !== true) {
                return self::eroare(404, 'negasit');
            }
            $mesaj = $j['mesaj'] ?? null;
            $param = $j['parametri'] ?? null;
            if (!in_array($mesaj, self::MESAJE, true)) {
                return self::eroare(422, 'validare', ['campuri' => ['mesaj']]);
            }
            if ($mesaj === 'restituire_transfer' && (!is_array($param) || !is_int($param['suma'] ?? null) || $param['suma'] < 1 || $param['suma'] > 1000000000)) {
                return self::eroare(422, 'validare', ['campuri' => ['parametri']]);
            }
            if ($mesaj === 'retrimite_confirmarea' && ($r['stare'] ?? null) !== 'confirmata') {
                return self::eroare(409, 'stare_invalida', ['motiv' => 'fara_confirmare']);
            }
            $azi = gmdate('Y-m-d', $acum);
            $n = count(array_filter($st['comenzi'] ?? [], static fn (array $c): bool => $c['rezervare'] === $rid && ($c['tip'] ?? '') === 'mesaj' && str_starts_with((string) $c['creata_la'], $azi)));
            if ($n >= self::MESAJE_PE_ZI) {
                return self::eroare(409, 'stare_invalida', ['motiv' => 'limita_zi']);
            }
            $cmd = self::comandaMesaj($st, $rid, $mesaj, $mesaj === 'restituire_transfer' ? ['suma' => $param['suma']] : null, $acum);
        } elseif ($ruta === 'proba-rambursare') {
            $suma = $j['suma'] ?? null;
            if (!is_int($suma) || $suma < 1 || $suma > $platit) {
                return self::eroare(422, 'validare', ['campuri' => ['suma'], 'maxim' => $platit]);
            }
            if ($vie) {
                return self::eroare(409, 'stare_invalida', ['motiv' => 'comanda_vie']);
            }
            $cmd = self::comanda($st, $rid, $suma, 'gest_gazda', 'rambursare_gazda', $acum);
        } else {
            if ($r['stare'] === 'anulata') {
                return self::eroare(409, 'stare_invalida', ['motiv' => 'anulata']);
            }
            $decizie = $ruta === 'proba-anuleaza-gazda' ? 'accepta' : ($j['decizie'] ?? null);
            if ($ruta === 'proba-anulare-decide' && (($r['cerere_anulare']['stare'] ?? null) !== 'in_asteptare')) {
                return self::eroare(409, 'stare_invalida', ['motiv' => 'fara_cerere']);
            }
            if (!in_array($decizie, ['accepta', 'pastreaza'], true)) {
                return self::eroare(422, 'validare', ['campuri' => ['decizie']]);
            }
            $rest = (int) ($j['restituie'] ?? 0);
            $cale = is_string($j['cale'] ?? null) ? $j['cale'] : null;
            // calea `comanda` cerută explicit fără condițiile căii (aici: fără plată cu cardul) dă 409 card_indisponibil;
            // fără `cale`, cea implicită e `comanda` doar cu condițiile îndeplinite, altfel `in_afara_site` (`fara` la 0).
            if ($decizie === 'accepta' && $rest > 0 && $platit === 0) {
                if ($cale === 'comanda') {
                    return self::eroare(409, 'stare_invalida', ['motiv' => 'card_indisponibil', 'cauza' => 'fara_card']);
                }
                $cale ??= 'in_afara_site';
            }
            if ($decizie === 'accepta' && $cale !== 'in_afara_site' && ($rest < 0 || $rest > $platit)) {
                return self::eroare(422, 'validare', ['campuri' => ['restituie'], 'maxim' => $platit]);
            }
            if ($decizie === 'accepta' && $vie) {
                return self::eroare(409, 'stare_invalida', ['motiv' => 'comanda_vie']);
            }
            $cmd = $decizie === 'accepta' ? self::anuleazaGazda($st, $rid, $rest, $cale, $acum, ($j['forta_majora'] ?? false) === true, is_int($j['despagubire'] ?? null) ? $j['despagubire'] : null)
                : self::decide($st, $rid, 'pastreaza', 0, $acum);
        }
        $rez = self::forma($st, $st['rezervari'][$rid]);
        unset($rez['corp'], $rez['corp_brut'], $rez['plata'], $rez['propunere'], $rez['cheie']);
        return [200, ['rezervare' => $rez, 'comanda' => $cmd, 'proba' => true]];
    }

    /** `POST /comenzi/{id}/rezultat`: validarea, trecerile permise, idempotența. */
    private static function rezultat(array &$st, string $id, string $idem, string $brut, ?array $j, int $acum): array
    {
        if ($idem === '') {
            return self::eroare(400, 'idempotency_lipsa');
        }
        if (isset($st['idem3'][$idem])) {
            return $st['idem3'][$idem]['hash'] === hash('sha256', $brut) ? $st['idem3'][$idem]['r'] : self::eroare(409, 'idempotency_conflict');
        }
        $c = $st['comenzi'][$id];
        $gr = self::valideazaRezultat($j, (int) $c['suma'], $acum, (string) ($c['tip'] ?? 'rambursare'));
        $st['rezultate'][] = ['comanda' => $id, 'cheie' => $idem, 'corp' => $j, 'brut' => $brut];
        if ($gr !== []) {
            return self::eroare(422, 'validare', ['campuri' => $gr] + (in_array('suma_executata', $gr, true) ? ['maxim' => (int) $c['suma']] : []));
        }
        $permise = ['preluata' => ['in_procesare', 'reusita', 'esuata', 'refuzata'], 'in_procesare' => ['reusita', 'esuata']];
        $nou = $j['stare'];
        if ($c['stare'] === $nou && ($c['rezultat'] ?? null) !== null && ($c['rezultat']['suma_executata'] ?? null) === ($j['suma_executata'] ?? null)) {
            $r = [200, $c];
        } elseif (!in_array($nou, $permise[$c['stare']] ?? [], true)) {
            return self::eroare(409, 'stare_invalida', ['stare' => $c['stare']]);
        } else {
            $st['comenzi'][$id]['stare'] = $nou;
            $st['comenzi'][$id]['rezultat'] = $j;
            $st['comenzi'][$id]['actualizat_la'] = self::moment($acum);
            $r = [200, $st['comenzi'][$id]];
        }
        $st['idem3'][$idem] = ['hash' => hash('sha256', $brut), 'r' => $r];
        return $r;
    }

    /** @return list<string> câmpurile greșite ale raportului */
    public static function valideazaRezultat(mixed $j, int $suma, int $acum, string $tip = 'rambursare'): array
    {
        if (!is_array($j)) {
            return ['$'];
        }
        $gr = [];
        $stare = $j['stare'] ?? null;
        if (!in_array($stare, ['in_procesare', 'reusita', 'esuata', 'refuzata'], true)) {
            $gr[] = 'stare';
        }
        if ($tip === 'mesaj') {
            // Etapa 4: `reusita`, `esuata` sau `refuzata` (cu cod), fără bani și fără părți.
            if (!in_array($stare, ['reusita', 'esuata', 'refuzata'], true)) {
                $gr[] = 'stare';
            }
            if (!is_string($j['la'] ?? null) || !preg_match(self::MOMENT, $j['la']) || strtotime($j['la']) > $acum + 300) {
                $gr[] = 'la';
            }
            if (($j['suma_executata'] ?? null) !== null || ($j['parti'] ?? null) !== null) {
                $gr[] = 'suma_executata';
            }
            $cod = $j['cod'] ?? null;
            if ($stare === 'refuzata' ? !in_array($cod, ['rezervare_necunoscuta', 'date_sterse', 'tip_necunoscut', 'fara_confirmare'], true) : ($stare === 'esuata' ? !is_string($cod) : $cod !== null)) {
                $gr[] = 'cod';
            }
            return array_values(array_unique($gr));
        }
        if (!is_string($j['la'] ?? null) || !preg_match(self::MOMENT, $j['la']) || strtotime($j['la']) > $acum + 300) {
            $gr[] = 'la';
        }
        $cuBani = in_array($stare, ['in_procesare', 'reusita'], true);
        $se = $j['suma_executata'] ?? null;
        if ($cuBani && (!is_int($se) || $se < 1 || $se > $suma)) {
            $gr[] = 'suma_executata';
        }
        if (!$cuBani && $se !== null && $se !== 0) {
            $gr[] = 'suma_executata';
        }
        $parti = $j['parti'] ?? null;
        if ($cuBani && (!is_array($parti) || count($parti) < 1 || count($parti) > 5)) {
            $gr[] = 'parti';
        } elseif (is_array($parti)) {
            $total = 0;
            foreach ($parti as $i => $p) {
                if (!is_array($p) || !is_string($p['ref'] ?? null) || !preg_match(self::REF, $p['ref']) || !is_int($p['suma'] ?? null) || $p['suma'] < 1
                    || !in_array($p['stare'] ?? null, ['in_procesare', 'reusita', 'esuata'], true) || (isset($p['plata_ref']) && !preg_match(self::REF, (string) $p['plata_ref']))) {
                    $gr[] = "parti[$i]";
                    continue;
                }
                if ($p['stare'] !== 'esuata') {
                    $total += $p['suma'];
                }
            }
            if ($cuBani && is_int($se) && $total !== $se) {
                $gr[] = 'parti';
            }
        }
        $cod = $j['cod'] ?? null;
        if (in_array($stare, ['esuata', 'refuzata'], true) ? !in_array($cod, self::CODURI, true) : $cod !== null) {
            $gr[] = 'cod';
        }
        $d = $j['detalii'] ?? null;
        if ($d !== null && (!is_array($d) || array_diff(array_keys($d), ['maxim', 'plafon', 'ramas', 'motiv_procesator']) !== [])) {
            $gr[] = 'detalii';
        }
        return array_values(array_unique($gr));
    }
}
