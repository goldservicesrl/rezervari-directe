<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Setari;

defined('CAZO') or exit;

/**
 * Licența și starea cheii: programul funcționează doar cu o cheie EasyCheck activă.
 *
 *  - 401 confirmat (două răspunsuri 401 la rând, la cel puțin 5 minute distanță): rezervările și cererile se opresc;
 *    prezentarea rămâne fără limită de timp, din oglindă și din cache; nimic nu se șterge. După primul 401,
 *    clientul face o pauză de 5 minute (cererile cu o cheie respinsă numără la EasyCheck ca eșecuri pe IP);
 *    după confirmare, sincronizarea se oprește până la o cheie nouă sau la o verificare cerută din panou.
 *  - 403 pe cont, proprietate sau funcție (cont_inactiv, proprietate_inactiva, functie_indisponibila): rezervările
 *    se opresc la fel, iar GET /eu se verifică la 30 de minute, ca site-ul să revină singur după reactivare.
 *  - cheia de probă (mod = proba): banda „Site în probă” și noindex.
 *  - scop = citire: site de prezentare cu contactul.
 *  - amestecul test/live cu cheia Stripe e refuzat.
 *
 * Starea stă în setarea `ec.licenta`; rezumatul cheii (din GET /eu) în `ec.cheie`.
 */
final class Licenta
{
    public const CONFIRMARE_401 = 300;
    public const VERIFICARE_403 = 1800;

    public function __construct(private Setari $setari, private Ceas $ceas, private ?Buget $buget = null)
    {
    }

    /** Observatorul clientului: fiecare răspuns EasyCheck actualizează starea cheii. */
    public function observa(string $metoda, string $cale, RaspunsEc $r): void
    {
        $l = $this->licenta();
        if ($r->status === 401) {
            $acum = $this->ceas->acum();
            if ($l['primul_401_la'] === null) {
                $l['primul_401_la'] = $acum;
                $this->buget?->puneInPauza(self::CONFIRMARE_401);
            } elseif ($l['confirmat_401_la'] === null && $acum - $l['primul_401_la'] >= self::CONFIRMARE_401) {
                $l['confirmat_401_la'] = $acum;
            }
            $this->scrie($l);
            return;
        }
        if ($r->status === 403 && in_array($r->codEroare(), ['cont_inactiv', 'proprietate_inactiva', 'functie_indisponibila'], true)) {
            $l['inactiv'] = $r->codEroare();
            $l['inactiv_la'] ??= $this->ceas->acum();
            $this->scrie($l);
            return;
        }
        if ($r->reusit() || $r->status === 304) {
            if ($l['primul_401_la'] !== null || $l['confirmat_401_la'] !== null || $l['inactiv'] !== null) {
                $this->scrie(self::GOL);
            }
        }
    }

    /** O cheie nouă pusă în Conexiuni pornește din nou de la zero. */
    public function cheieNoua(): void
    {
        $this->scrie(self::GOL);
        $this->setari->pune('ec.cheie', null);
    }

    /** Rezumatul din GET /eu (Proiectii::eu), la fiecare corp nou. */
    public function dinEu(array $eu): void
    {
        $this->setari->pune('ec.cheie', Proiectii::eu($eu));
    }

    /**
     * După un rezumat nou: dacă sursa prețului diferă de cea din $vechi, starea parității ține de sursa veche și se
     * golește (calendarul își recapătă sumele pe zi, alerta din panou dispare până la verificarea următoare).
     */
    public function dupaRezumat(?array $vechi): void
    {
        if (($vechi['sursa'] ?? null) !== ($this->cheie()['sursa'] ?? null)) {
            $this->setari->pune('paritate.stare', null);
        }
    }

    /** Modul (noindex, banda de probă) sau sursa prețului diferă între două rezumate: paginile din cache se refac. */
    public static function schimbaPaginile(?array $vechi, ?array $nou): bool
    {
        return ($vechi['mod'] ?? null) !== ($nou['mod'] ?? null) || ($vechi['sursa'] ?? null) !== ($nou['sursa'] ?? null);
    }

    /** Pauza clientului vine dintr-un 401 al cheii salvate (nu dintr-un Retry-After). */
    public function areUn401(): bool
    {
        return $this->licenta()['primul_401_la'] !== null;
    }

    public function cheie(): ?array
    {
        $c = $this->setari->get('ec.cheie');
        return is_array($c) ? $c : null;
    }

    public function invalidaConfirmata(): bool
    {
        return $this->licenta()['confirmat_401_la'] !== null;
    }

    /** Sincronizarea merge: cheia e cunoscută și nu e respinsă definitiv; la un cont inactiv, doar GET /eu, rar. */
    public function permiteSincronizare(string $resursa, ?int $verificatLa): bool
    {
        $l = $this->licenta();
        if ($l['confirmat_401_la'] !== null) {
            return false;
        }
        if ($l['inactiv'] !== null) {
            return $resursa === 'eu' && ($verificatLa === null || $this->ceas->acum() - $verificatLa >= self::VERIFICARE_403);
        }
        return true;
    }

    /**
     * Starea pentru site, comandă și panou.
     * @return array{configurata:bool, valida:bool, rezervari:bool, mod:?string, scop:?string, sursa:?string, include:?array,
     *               proba:bool, noindex:bool, alerte:string[]}
     */
    public function stare(): array
    {
        $c = $this->cheie();
        $l = $this->licenta();
        $alerte = [];
        $valida = $c !== null && $l['confirmat_401_la'] === null && $l['inactiv'] === null;
        if ($l['confirmat_401_la'] !== null) {
            $alerte[] = 'conexiuni.ec.cheie_invalida';
        } elseif ($l['inactiv'] !== null) {
            $alerte[] = 'conexiuni.ec.cont_inactiv';
        }
        if ($c !== null && $c['scop'] === 'citire') {
            $alerte[] = 'conexiuni.ec.scop_citire';
        }
        if ($c !== null && $c['mod'] === 'proba') {
            $alerte[] = 'conexiuni.ec.mod_proba';
        }
        if (($c['webhook']['stare'] ?? null) === 'oprit') {
            $alerte[] = 'conexiuni.ec.anunturi_oprite';
        } elseif (($this->setari->get('ec.anunturi')['primit'] ?? null) === false) {
            // Proba de la instalare sau din panou a rămas neconfirmată: site-ul merge pe interogare.
            $alerte[] = 'conexiuni.ec.anunturi_neprimite';
        }
        return [
            'configurata' => $c !== null,
            'valida' => $valida,
            'rezervari' => $valida && $c['scop'] === 'rezervari',
            'mod' => $c['mod'] ?? null,
            'scop' => $c['scop'] ?? null,
            'sursa' => $c['sursa'] ?? null,
            'include' => $c['include'] ?? null,
            'proba' => ($c['mod'] ?? null) === 'proba',
            'noindex' => ($c['mod'] ?? null) === 'proba',
            'alerte' => $alerte,
        ];
    }

    /** Cheia EasyCheck de probă merge doar cu o cheie Stripe de test, iar cea live doar cu una live. */
    public static function compatibilCuStripe(string $modEc, string $cheieStripe): bool
    {
        $stripeTest = (bool) preg_match('~^(rk|sk)_test_~', $cheieStripe);
        $stripeLive = (bool) preg_match('~^(rk|sk)_live_~', $cheieStripe);
        return ($modEc === 'proba' && $stripeTest) || ($modEc === 'live' && $stripeLive);
    }

    private const GOL = ['primul_401_la' => null, 'confirmat_401_la' => null, 'inactiv' => null, 'inactiv_la' => null];

    private function licenta(): array
    {
        $l = $this->setari->get('ec.licenta');
        return is_array($l) ? $l + self::GOL : self::GOL;
    }

    private function scrie(array $l): void
    {
        $this->setari->pune('ec.licenta', $l);
    }
}
