<?php

declare(strict_types=1);

namespace Cazo\Securitate;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Auditul înlănțuit (14 Securitate): fiecare rând poartă amprenta rândului dinainte
 * (`hash_anterior`) și amprenta lui (`hash`), un HMAC-SHA256 cu subcheia „audit” derivată din cheia-mamă. Cheia stă în
 * afara bazei, deci cine are doar baza (o injecție, o copie, un export) poate citi auditul, dar nu poate schimba sau
 * șterge un rând din mijloc fără ca verificarea să-l găsească. `hash_anterior` e unic: două scrieri simultane nu pot
 * porni două ramuri din același rând; a doua reia citirea capătului.
 *
 * Auditul ține identificatori, nu conținut personal: id-ul utilizatorului, codul comenzii, numele setării, rezultatul.
 * `detalii` primește doar valori scalare scurte; textele lungi se taie, iar cheile au forma [a-z0-9_].
 */
final class Audit
{
    public const GENEZA = '0000000000000000000000000000000000000000000000000000000000000000';
    private const INCERCARI = 5;

    public function __construct(private Baza $baza, private Ceas $ceas, private string $cheie)
    {
    }

    /**
     * Un rând nou în lanț. Întoarce id-ul lui.
     * @param array<string, scalar|null> $detalii
     */
    public function scrie(string $actiune, ?string $obiect = null, array $detalii = [], ?int $utilizator = null, ?string $ip24 = null): int
    {
        $actiune = mb_substr($actiune, 0, 64);
        $obiect = $obiect !== null ? mb_substr($obiect, 0, 191) : null;
        $d = self::curata($detalii);
        $json = $d === [] ? null : json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $la = $this->ceas->moment();
        for ($i = 0; ; $i++) {
            $anterior = (string) ($this->baza->valoare('SELECT hash FROM {audit} ORDER BY id DESC LIMIT 1') ?? self::GENEZA);
            $hash = $this->amprenta($anterior, $la, $utilizator, $actiune, $obiect, $json, $ip24);
            try {
                return $this->baza->insereaza('audit', ['la' => $la, 'utilizator_id' => $utilizator, 'actiune' => $actiune, 'obiect' => $obiect, 'detalii' => $json,
                    'ip24' => $ip24, 'hash_anterior' => $anterior, 'hash' => $hash]);
            } catch (\PDOException $e) {
                // Alt proces a scris între citirea capătului și inserare: se reia de la capătul nou.
                if (!$this->baza->esteDuplicat($e) || $i >= self::INCERCARI) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Verifică tot lanțul, în ordinea id-urilor. Un rând schimbat, șters din mijloc sau inserat în afara lanțului
     * oprește verificarea la el.
     * @return array{ok:bool, verificate:int, rupt_la:?int}
     */
    public function verifica(): array
    {
        $anterior = self::GENEZA;
        $n = 0;
        $dupa = 0;
        do {
            $randuri = $this->baza->toate('SELECT * FROM {audit} WHERE id > ? ORDER BY id LIMIT 500', [$dupa]);
            foreach ($randuri as $r) {
                $asteptat = $this->amprenta((string) $r['hash_anterior'], (string) $r['la'], $r['utilizator_id'] !== null ? (int) $r['utilizator_id'] : null, (string) $r['actiune'],
                    $r['obiect'] !== null ? (string) $r['obiect'] : null, $r['detalii'] !== null ? (string) $r['detalii'] : null, $r['ip24'] !== null ? (string) $r['ip24'] : null);
                if (!hash_equals($anterior, (string) $r['hash_anterior']) || !hash_equals($asteptat, (string) $r['hash'])) {
                    return ['ok' => false, 'verificate' => $n, 'rupt_la' => (int) $r['id']];
                }
                $anterior = (string) $r['hash'];
                $dupa = (int) $r['id'];
                $n++;
            }
        } while (count($randuri) === 500);
        return ['ok' => true, 'verificate' => $n, 'rupt_la' => null];
    }

    /** O pagină din audit, cele mai noi întâi. @return list<array> */
    public function pagina(int $pagina, int $pe = 50, ?string $actiune = null): array
    {
        $pagina = max(1, $pagina);
        $sql = 'SELECT * FROM {audit}';
        $p = [];
        if ($actiune !== null && $actiune !== '') {
            $sql .= ' WHERE actiune = ?';
            $p[] = $actiune;
        }
        return $this->baza->toate($sql . ' ORDER BY id DESC LIMIT ' . $pe . ' OFFSET ' . (($pagina - 1) * $pe), $p);
    }

    public function numar(?string $actiune = null): int
    {
        return $actiune !== null && $actiune !== '' ? (int) $this->baza->valoare('SELECT COUNT(*) FROM {audit} WHERE actiune = ?', [$actiune])
            : (int) $this->baza->valoare('SELECT COUNT(*) FROM {audit}');
    }

    /** @return list<string> acțiunile din audit, pentru filtrul din panou */
    public function actiuni(): array
    {
        return array_map('strval', array_column($this->baza->toate('SELECT DISTINCT actiune FROM {audit} ORDER BY actiune'), 'actiune'));
    }

    private function amprenta(string $anterior, string $la, ?int $utilizator, string $actiune, ?string $obiect, ?string $detalii, ?string $ip24): string
    {
        $text = implode("\x1f", [$anterior, $la, $utilizator === null ? '' : (string) $utilizator, $actiune, $obiect ?? '', $detalii ?? '', $ip24 ?? '']);
        return hash_hmac('sha256', $text, $this->cheie);
    }

    /** Doar chei [a-z0-9_] și valori scalare scurte (fără texte libere). @return array<string, scalar|null> */
    public static function curata(array $detalii): array
    {
        $r = [];
        foreach ($detalii as $k => $v) {
            if (!is_string($k) || !preg_match('~^[a-z0-9_]{1,40}$~', $k)) {
                continue;
            }
            if (is_string($v)) {
                $r[$k] = mb_substr($v, 0, 120);
            } elseif (is_int($v) || is_bool($v) || $v === null) {
                $r[$k] = $v;
            }
        }
        return $r;
    }
}
