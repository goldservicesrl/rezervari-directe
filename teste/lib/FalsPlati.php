<?php

declare(strict_types=1);

/**
 * Rapoartele plăților ca în EasyCheck (contract-plati.md, etapa 2 din „EasyCheck întâi”): `POST /rezervari/{id}/plati`, pentru
 * serverul EasyCheck fals (teste/fals/easycheck.php) și pentru EasyCheck-ul fără rețea al testelor (CereriFalse). Ține, pe
 * rezervare, evenimentele după cheia naturală (`tip`, `ref`) și calculează `plati` ({platit, rambursat}) din plata trimisă la
 * `POST /rezervari` (`plata.suma`, 0 fără plată cu cardul) și din evenimente. Plățile notate de gazdă nu există aici.
 *
 * Idempotența (`Idempotency-Key`) și cheia rezervării (403 `rezervare_straina`) le face apelantul; aici: validarea (422),
 * regulile cheii naturale (409 `stare_invalida`, `la` mai vechi) și răspunsul 200.
 */
final class FalsPlati
{
    public const TIPURI = ['incasare_confirmata', 'incasare_anulata', 'rambursare', 'contestare'];
    private const REF = '~^[A-Za-z0-9_.\-]{1,100}$~D';
    private const MOMENT = '~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$~D';
    private const MOTIVE_RAMBURSARE = ['anulare_oaspete', 'anulare_gazda', 'gest_gazda', 'schimbare_date', 'refuz_automat', 'din_cont_stripe'];
    /** Câmpurile opționale primite de fiecare tip. */
    private const CAMPURI = [
        'incasare_confirmata' => ['plata_ref', 'comision', 'comanda', 'suma', 'moneda'],
        'incasare_anulata' => ['plata_ref', 'comanda', 'suma', 'moneda'],
        'rambursare' => ['plata_ref', 'motiv', 'txn', 'comanda', 'suma', 'moneda', 'stare'],
        'contestare' => ['plata_ref', 'motiv', 'txn', 'termen', 'taxa', 'taxa_returnata', 'debitat_la', 'comanda', 'suma', 'moneda', 'stare'],
    ];
    private const OPTIONALE = ['termen', 'comision', 'plata_ref', 'motiv', 'taxa', 'taxa_returnata', 'txn', 'debitat_la', 'comanda'];

    /**
     * Aplică un raport pe rezervarea $rez (schimbată pe loc: `plati_ev`, `incasat`) și întoarce [status, corp].
     * $plata: suma plătită cu cardul la `POST /rezervari` (null: fără plată cu cardul).
     * @return array{0:int, 1:array}
     */
    public static function aplica(array &$rez, ?int $plata, mixed $j, int $acum): array
    {
        if (!is_array($j)) {
            return [422, self::eroare('validare', ['campuri' => ['tip']])];
        }
        $gr = [];
        $tip = $j['tip'] ?? null;
        if (!is_string($tip) || !in_array($tip, self::TIPURI, true)) {
            return [422, self::eroare('validare', ['campuri' => ['tip']])];
        }
        $moment = static fn (mixed $v): bool => is_string($v) && preg_match(self::MOMENT, $v) === 1 && strtotime($v) !== false;
        $natural = static fn (mixed $v): bool => is_int($v) && $v >= 0;
        foreach (['plata_ref', 'motiv', 'comision', 'termen', 'taxa', 'taxa_returnata', 'txn', 'debitat_la', 'stare', 'suma', 'moneda', 'comanda'] as $k) {
            if (array_key_exists($k, $j) && $j[$k] !== null && !in_array($k, self::CAMPURI[$tip], true)) {
                $gr[] = $k;
            }
        }
        if (!is_string($j['ref'] ?? null) || !preg_match(self::REF, $j['ref'])) {
            $gr[] = 'ref';
        }
        $suma = $j['suma'] ?? null;
        if (($suma !== null || in_array($tip, ['rambursare', 'contestare'], true)) && (!is_int($suma) || $suma < 1 || $suma > 1_000_000_000)) {
            $gr[] = 'suma';
        }
        if (array_key_exists('moneda', $j) && $j['moneda'] !== null && $j['moneda'] !== 'RON') {
            $gr[] = 'moneda';
        }
        $stari = ['rambursare' => ['in_procesare', 'reusita', 'esuata'], 'contestare' => ['deschisa', 'castigata', 'pierduta']][$tip] ?? null;
        if ($stari !== null && !in_array($j['stare'] ?? null, $stari, true)) {
            $gr[] = 'stare';
        }
        if (!$moment($j['la'] ?? null) || strtotime($j['la']) > $acum + 300) {
            $gr[] = 'la';
        }
        if (isset($j['plata_ref']) && (!is_string($j['plata_ref']) || !preg_match(self::REF, $j['plata_ref']))) {
            $gr[] = 'plata_ref';
        }
        if (isset($j['txn']) && (!is_string($j['txn']) || !preg_match(self::REF, $j['txn']))) {
            $gr[] = 'txn';
        }
        if (isset($j['motiv']) && ($tip === 'rambursare' ? !in_array($j['motiv'], self::MOTIVE_RAMBURSARE, true) : (!is_string($j['motiv']) || !preg_match('~^[a-z_]{1,40}$~D', $j['motiv'])))) {
            $gr[] = 'motiv';
        }
        foreach (['comision', 'taxa', 'taxa_returnata'] as $k) {
            if (isset($j[$k]) && !$natural($j[$k])) {
                $gr[] = $k;
            }
        }
        foreach (['termen', 'debitat_la'] as $k) {
            if (isset($j[$k]) && !$moment($j[$k])) {
                $gr[] = $k;
            }
        }
        if (isset($j['comanda']) && (!is_string($j['comanda']) || !preg_match('~^cmd_[A-Za-z0-9]{24}$~D', $j['comanda']))) {
            $gr[] = 'comanda';
        }
        if ($gr !== []) {
            return [422, self::eroare('validare', ['campuri' => array_values(array_unique($gr))])];
        }
        $rez['plati_ev'] ??= [];
        $rez['incasat'] ??= $plata ?? 0;
        $cheie = $tip . '|' . $j['ref'];
        $ev = $rez['plati_ev'][$cheie] ?? null;
        $la = (int) strtotime($j['la']);
        if (in_array($tip, ['incasare_confirmata', 'incasare_anulata'], true) && $ev === null) {
            if ($plata === null) {
                return [409, self::eroare('stare_invalida', ['motiv' => 'fara_plata_card'])];
            }
            if ($tip === 'incasare_anulata') {
                if ($rez['incasat'] <= 0) {
                    return [409, self::eroare('stare_invalida', ['motiv' => 'fara_plata_card'])];
                }
                if ($suma !== null && $suma > $rez['incasat']) {
                    return [422, self::eroare('validare', ['campuri' => ['suma'], 'maxim' => $rez['incasat']])];
                }
            } elseif ($rez['incasat'] <= 0) {
                return [409, self::eroare('stare_invalida', ['motiv' => 'plata_eliberata'])];
            }
        }
        if ($ev !== null && $suma !== null && $ev['suma'] !== null && $suma !== $ev['suma']) {
            return [409, self::eroare('stare_invalida', ['motiv' => 'suma_diferita', 'suma' => $ev['suma']])];
        }
        $inregistrat = false;
        if ($ev === null) {
            $ev = ['tip' => $tip, 'ref' => $j['ref'], 'suma' => $suma, 'stare' => $j['stare'] ?? null, 'la' => $la, 'rapoarte' => 1];
            foreach (self::OPTIONALE as $k) {
                if (isset($j[$k])) {
                    $ev[$k] = $j[$k];
                }
            }
            if ($tip === 'incasare_anulata') {
                $ev['suma'] = $suma ?? $rez['incasat'];
                $rez['incasat'] -= $ev['suma'];
            }
            $inregistrat = true;
        } elseif ($la >= $ev['la']) {
            $ev['rapoarte']++;
            $ev['la'] = $la;
            if (($j['stare'] ?? null) !== null && $j['stare'] !== $ev['stare']) {
                $ev['stare'] = $j['stare'];
                $inregistrat = true;
            }
            foreach (self::OPTIONALE as $k) {
                if (isset($j[$k]) && ($ev[$k] ?? null) !== $j[$k]) {
                    $ev[$k] = $j[$k];
                    $inregistrat = true;
                }
            }
        }
        $rez['plati_ev'][$cheie] = $ev;
        $plati = self::plati($rez, $plata);
        $r = ['rezervare' => $rez['id'] ?? null, 'inregistrat' => $inregistrat, 'plati' => $plati];
        if ($plati['platit'] < 0) {
            $r['avertizare'] = 'peste_platit';
        }
        return [200, $r + ['proba' => true]];
    }

    /** `plati` al rezervării: plata cu cardul (minus eliberările), minus returnările; returnările, ca număr pozitiv. */
    public static function plati(array $rez, ?int $plata): array
    {
        $incasat = $rez['incasat'] ?? $plata ?? 0;
        $ramb = 0;
        foreach ($rez['plati_ev'] ?? [] as $ev) {
            if (($ev['tip'] === 'rambursare' && $ev['stare'] === 'reusita') || ($ev['tip'] === 'contestare' && in_array($ev['stare'], ['deschisa', 'pierduta'], true))) {
                $ramb += (int) $ev['suma'];
            }
        }
        return ['platit' => $incasat - $ramb, 'rambursat' => $ramb];
    }

    private static function eroare(string $cod, ?array $detalii = null): array
    {
        $e = ['cod' => $cod, 'mesaj' => 'probă: ' . $cod, 'request_id' => 'req_' . str_repeat('P', 24)];
        if ($detalii !== null) {
            $e['detalii'] = $detalii;
        }
        return ['eroare' => $e];
    }
}
