<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Mașina de stări a comenzilor. Fiecare tranziție:
 * UPDATE comenzi SET stare = :noua, ... WHERE id = :id AND stare = :veche, în tranzacție, cu o linie în
 * `comenzi_jurnal`. Zero rânduri afectate = alt proces a făcut pasul (întoarce false, fără eroare).
 * O tranziție absentă din tabel e o eroare de program (LogicException).
 *
 * Ramura cash, ramura online și ramura transferului bancar. Pe ramura online, în plus față de tranzițiile de bază: o comandă nouă a cărei
 * sesiune Checkout n-a pornit devine `abandonata`; o sesiune încheiată cu plata refuzată merge direct în
 * `plata_esuata`; o plată încasată cu altă sumă decât a comenzii merge direct în `de_rambursat`. O comandă
 * abandonată nu are sesiune deschisă: renunțarea și expirarea închid sesiunea întâi, iar porneste() expiră pe loc
 * sesiunea unei comenzi plecate între timp din `noua`. O comandă rămasă fără ieșire automată (rambursarea epuizată sau recuperarea „necunoscut”)
 * o închide gazda din panou, după ce a rezolvat plata în Stripe: `inchisa_gazda`, fără mișcare de bani.
 *
 * După rezervare (Rezervare\Anulare): `confirmata` → `anulata_oaspete` (perioada gratuită, plata la sosire),
 * `anulare_ceruta` (cererea după termen) sau `anulata_gazda` (panoul); `anulare_ceruta` → `anulata_oaspete`
 * (cererea acceptată), `confirmata` (păstrată) sau `anulata_gazda`. Starea se schimbă la confirmarea
 * EasyCheck a anulării; rambursarea ei rulează după, fără altă stare. anularea gazdei văzută în EasyCheck
 * (fără marcaj) pe o comandă cu bani încasați duce în `anulare_de_lamurit` („Anulare de lămurit”, banii neatinși), iar
 * de acolo, cu suma aleasă de gazdă în panou, în `anulata_gazda`.
 */
final class MasinaStari
{
    public const TRANZITII = [
        'cash' => [
            'noua' => ['trimitere_ec', 'abandonata'],
            'trimitere_ec' => ['cerere_in_asteptare', 'indisponibila', 'respinsa_ec'],
            'cerere_in_asteptare' => ['confirmata', 'cerere_refuzata', 'cerere_expirata'],
            'confirmata' => ['anulata_oaspete', 'anulata_gazda'],
        ],
        // Transferul bancar (Rezervare\Transfer): rezervarea trimisă cu `plata_asteptata` așteaptă decizia
        // gazdei din EasyCheck; cât așteaptă, o pot anula oaspetele („Rezervarea mea”) sau gazda (calendarul EasyCheck).
        'transfer' => [
            'noua' => ['trimitere_ec', 'abandonata'],
            'trimitere_ec' => ['transfer_in_asteptare', 'indisponibila', 'respinsa_ec'],
            'transfer_in_asteptare' => ['confirmata', 'transfer_neprimit', 'transfer_expirat', 'anulata_oaspete', 'anulata_gazda'],
            'confirmata' => ['anulata_oaspete', 'anulata_gazda'],
        ],
        'card' => [
            'noua' => ['plata_in_curs', 'abandonata'],
            'plata_in_curs' => ['abandonata', 'platita', 'plata_in_procesare', 'plata_esuata', 'de_rambursat'],
            'plata_in_procesare' => ['platita', 'plata_esuata', 'de_rambursat'],
            'platita' => ['de_rambursat', 'trimitere_ec', 'confirmata', 'de_capturat', 'inchisa_gazda'],
            'trimitere_ec' => ['platita', 'confirmata', 'de_rambursat', 'de_capturat'],
            // EasyCheck a creat rezervarea; suma autorizată pe card se capturează, apoi comanda e confirmată.
            // Captura imposibilă sau fereastra de 10 minute trecută: rezervarea se anulează în EasyCheck, suma se eliberează.
            'de_capturat' => ['confirmata', 'de_rambursat'],
            // la un refuz înainte de confirmare, o plată doar autorizată se eliberează (autorizarea anulată), fără rambursare.
            'de_rambursat' => ['rambursata', 'inchisa_gazda', 'autorizare_anulata'],
            // Rambursarea confirmată de Stripe a eșuat după aceea (charge.refund.updated): banii sunt din nou la gazdă.
            'rambursata' => ['de_rambursat'],
            'confirmata' => ['anulata_oaspete', 'anulare_ceruta', 'anulata_gazda', 'anulare_de_lamurit'],
            'anulare_ceruta' => ['anulata_oaspete', 'confirmata', 'anulata_gazda', 'anulare_de_lamurit'],
            // anularea gazdei din EasyCheck, cu bani încasați; gazda alege suma în panou.
            'anulare_de_lamurit' => ['anulata_gazda'],
        ],
    ];

    /** Stările din care comanda nu mai pleacă (pe ramura cash). */
    public const FINALE_CASH = ['abandonata', 'indisponibila', 'respinsa_ec', 'cerere_refuzata', 'cerere_expirata', 'anulata_oaspete', 'anulata_gazda'];

    /** Stările din care comanda nu mai pleacă, pe ramura transferului bancar. */
    public const FINALE_TRANSFER = ['abandonata', 'indisponibila', 'respinsa_ec', 'transfer_neprimit', 'transfer_expirat', 'anulata_oaspete', 'anulata_gazda'];

    /**
     * Stările din care comanda nu mai pleacă, pe ramura online (cu anulările din o rambursare după anulare se face
     * fără schimbarea stării). `rambursata` pleacă doar înapoi în `de_rambursat`, când rambursarea ei eșuează după
     * confirmare.
     */
    public const FINALE_CARD = ['abandonata', 'plata_esuata', 'anulata_oaspete', 'anulata_gazda', 'inchisa_gazda', 'autorizare_anulata'];

    public function __construct(private Baza $baza, private Ceas $ceas)
    {
    }

    public static function permisa(string $fel, string $din, string $in): bool
    {
        return in_array($in, self::TRANZITII[$fel][$din] ?? [], true);
    }

    /**
     * @param array<string,mixed> $campuri coloanele schimbate odată cu starea
     * @param array<string,mixed> $detalii pentru jurnal (fără date personale; request_id intră aici)
     * @param ?string $lacat tranziția cere ca lacătul comenzii (`blocat_pana`) să fie încă al apelantului
     */
    public function trece(int $id, string $fel, string $din, string $in, string $actor, array $campuri = [], array $detalii = [], ?string $lacat = null): bool
    {
        if (!self::permisa($fel, $din, $in)) {
            throw new \LogicException("Tranziție nepermisă ($fel): $din -> $in");
        }
        if (!preg_match('~^(sistem|webhook_stripe|webhook_ec|intoarcere|cron|oaspete|admin:\d+)$~', $actor)) {
            throw new \InvalidArgumentException('Actor necunoscut: ' . $actor);
        }
        return $this->baza->tranzactie(function (Baza $b) use ($id, $fel, $din, $in, $actor, $campuri, $detalii, $lacat): bool {
            $set = ['stare = ?', 'actualizat_la = ?'];
            $param = [$in, $this->ceas->moment()];
            foreach ($campuri as $c => $v) {
                if (!preg_match('~^[a-z_]{1,40}$~', (string) $c) || in_array($c, ['id', 'stare', 'fel'], true)) {
                    throw new \InvalidArgumentException('Coloană invalidă: ' . $c);
                }
                $set[] = $b->dialect()->citeaza((string) $c) . ' = ?';
                $param[] = is_bool($v) ? (int) $v : $v;
            }
            $param[] = $id;
            $param[] = $din;
            $param[] = $fel;
            if ($lacat !== null) {
                $param[] = $lacat;
            }
            $n = $b->executa('UPDATE {comenzi} SET ' . implode(', ', $set) . ' WHERE id = ? AND stare = ? AND fel = ?' . ($lacat !== null ? ' AND blocat_pana = ?' : ''), $param)->rowCount();
            if ($n !== 1) {
                return false;
            }
            $this->jurnal($id, $din, $in, $actor, $detalii);
            return true;
        });
    }

    /** O linie în jurnal fără schimbare de stare (o reîncercare, o eroare tranzitorie, un e-mail pus în coadă). */
    public function jurnal(int $id, ?string $din, string $in, string $actor, array $detalii = []): void
    {
        $this->baza->insereaza('comenzi_jurnal', [
            'comanda_id' => $id,
            'la' => $this->ceas->moment(),
            'din_stare' => $din,
            'in_stare' => $in,
            'actor' => $actor,
            'detalii' => $detalii === [] ? null : json_encode($detalii, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return list<array> jurnalul unei comenzi, în ordine */
    public function istoric(int $id): array
    {
        return $this->baza->toate('SELECT la, din_stare, in_stare, actor, detalii FROM {comenzi_jurnal} WHERE comanda_id = ? ORDER BY id', [$id]);
    }
}
