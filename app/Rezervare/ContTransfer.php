<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\I18n\Format;
use Cazo\Nucleu\Aplicatie;

defined('CAZO') or exit;

/**
 * Setările plății prin transfer bancar: contul în care plătesc oaspeții și regulile de timp. Setarea
 * `plati.transfer` ține titularul, IBAN-ul (fără spații), banca, codul SWIFT/BIC (opțional), termenul de plată în ore
 * (implicit 48, între 12 și 120) și cu câte zile înainte de sosire mai e oferit transferul (implicit 3, între 0 și 30).
 *
 * Bifa transferului (ModPlata, metoda `transfer`) se salvează doar cu titularul și IBAN-ul completate (panoul:
 * Metodele de plată, AdminTransfer; instalatorul: pasul plăților). Comanda își ține
 * propriul instantaneu al contului (`comenzi.transfer_cont`), ca oaspetele să vadă mereu contul primit la rezervare.
 */
final class ContTransfer
{
    public const CHEIE = 'plati.transfer';
    public const TERMEN_ORE = [12, 120];
    public const TERMEN_IMPLICIT = 48;
    public const ZILE = [0, 30];
    public const ZILE_IMPLICIT = 3;
    /** Cel puțin atât timp trebuie să rămână oaspetelui până la termen, ca transferul să mai fie oferit. */
    public const TERMEN_MINIM = 3600;
    public const CAMPURI = ['titular', 'iban', 'banca', 'swift', 'termen_ore', 'zile_inainte'];

    public function __construct(private Aplicatie $app)
    {
    }

    /** @return array{titular:string, iban:string, banca:string, swift:string, termen_ore:int, zile_inainte:int} */
    public function citeste(): array
    {
        $v = $this->app->setari()->get(self::CHEIE);
        $v = is_array($v) ? $v : [];
        $ore = is_int($v['termen_ore'] ?? null) ? $v['termen_ore'] : self::TERMEN_IMPLICIT;
        $zile = is_int($v['zile_inainte'] ?? null) ? $v['zile_inainte'] : self::ZILE_IMPLICIT;
        return [
            'titular' => is_string($v['titular'] ?? null) ? $v['titular'] : '',
            'iban' => is_string($v['iban'] ?? null) ? $v['iban'] : '',
            'banca' => is_string($v['banca'] ?? null) ? $v['banca'] : '',
            'swift' => is_string($v['swift'] ?? null) ? $v['swift'] : '',
            'termen_ore' => max(self::TERMEN_ORE[0], min(self::TERMEN_ORE[1], $ore)),
            'zile_inainte' => max(self::ZILE[0], min(self::ZILE[1], $zile)),
        ];
    }

    /** Contul poate primi plăți: titularul și un IBAN valid. */
    public function complet(): bool
    {
        $c = $this->citeste();
        return $c['titular'] !== '' && Iban::valid($c['iban']);
    }

    /**
     * Curăță și verifică valorile din formular. `$bifat`: gazda vrea transferul pornit, deci titularul și IBAN-ul sunt
     * obligatorii; nebifat, contul se poate salva și pe jumătate, dar un IBAN scris trebuie să fie corect.
     * @param array<string,mixed> $in
     * @return array{valori: array{titular:string, iban:string, banca:string, swift:string, termen_ore:int, zile_inainte:int}, erori: array<string,string>}
     *   erori: câmp => cheia textului din catalogul panoului
     */
    public static function valideaza(array $in, bool $bifat): array
    {
        $text = static fn (string $k, int $max): string => mb_substr(trim((string) preg_replace('~[\x00-\x1F\x7F]+~u', ' ', is_scalar($in[$k] ?? null) ? (string) $in[$k] : '')), 0, $max);
        $v = ['titular' => $text('titular', 120), 'iban' => Iban::curata($text('iban', 60)), 'banca' => $text('banca', 120), 'swift' => strtoupper(str_replace(' ', '', $text('swift', 20)))];
        $e = [];
        if ($v['titular'] === '' && $bifat) {
            $e['titular'] = 'admin.transfer.eroare.titular';
        }
        if ($v['iban'] === '' ? $bifat : !Iban::valid($v['iban'])) {
            $e['iban'] = $v['iban'] === '' ? 'admin.transfer.eroare.iban_lipsa' : 'admin.transfer.eroare.iban';
        }
        if ($v['swift'] !== '' && !Iban::swiftValid($v['swift'])) {
            $e['swift'] = 'admin.transfer.eroare.swift';
        }
        foreach (['termen_ore' => [self::TERMEN_ORE, self::TERMEN_IMPLICIT], 'zile_inainte' => [self::ZILE, self::ZILE_IMPLICIT]] as $k => [[$min, $max], $implicit]) {
            $brut = trim(is_scalar($in[$k] ?? null) ? (string) $in[$k] : '');
            if ($brut === '') {
                $v[$k] = $implicit;
            } elseif (!preg_match('~^\d{1,3}$~', $brut) || (int) $brut < $min || (int) $brut > $max) {
                $v[$k] = $implicit;
                $e[$k] = 'admin.transfer.eroare.' . $k;
            } else {
                $v[$k] = (int) $brut;
            }
        }
        return ['valori' => $v, 'erori' => $e];
    }

    /**
     * Salvează contul (valorile trecute prin valideaza()). Titularul și banca rămân cum le-a scris gazda; când
     * unul dintre ele s-a schimbat și diferă de forma lui cu majuscule, setarea ține minte că softul are de propus forma
     * cu majuscule (`propune_majuscule`). O salvare cu titularul și banca neschimbate înseamnă că gazda a văzut
     * propunerea și a păstrat ce a scris, deci propunerea se retrage.
     */
    public function salveaza(array $valori, ?int $utilizator = null): void
    {
        $vechi = $this->citeste();
        $v = array_intersect_key($valori, array_flip(self::CAMPURI));
        $schimbat = ($v['titular'] ?? '') !== $vechi['titular'] || ($v['banca'] ?? '') !== $vechi['banca'];
        $v['propune_majuscule'] = $schimbat && self::cuMajuscule((string) ($v['titular'] ?? ''), (string) ($v['banca'] ?? '')) !== null;
        $this->app->setari()->pune(self::CHEIE, $v, $utilizator);
    }

    /**
     * Forma cu majuscule a titularului și a băncii, doar pentru cele care diferă de ce a scris gazda („goldservice srl”
     * → „GOLDSERVICE SRL”). Null când amândouă sunt deja așa (sau goale).
     * @return array{titular?:string, banca?:string}|null
     */
    public static function cuMajuscule(string $titular, string $banca): ?array
    {
        $r = [];
        foreach (['titular' => $titular, 'banca' => $banca] as $k => $x) {
            $mare = mb_strtoupper($x);
            if ($x !== '' && $mare !== $x) {
                $r[$k] = $mare;
            }
        }
        return $r === [] ? null : $r;
    }

    /**
     * Propunerea de arătat gazdei după salvarea contului: ce a scris și forma cu majuscule, cât propunerea e
     * încă deschisă. @return array{acum:string, propus:string}|null
     */
    public function propunere(): ?array
    {
        $v = $this->app->setari()->get(self::CHEIE);
        if (!is_array($v) || ($v['propune_majuscule'] ?? false) !== true) {
            return null;
        }
        $c = $this->citeste();
        $m = self::cuMajuscule($c['titular'], $c['banca']);
        if ($m === null) {
            return null;
        }
        return ['acum' => implode(', ', array_intersect_key(['titular' => $c['titular'], 'banca' => $c['banca']], $m)), 'propus' => implode(', ', $m)];
    }

    /** Gazda a ales forma cu majuscule: titularul și banca se rescriu așa, iar propunerea se închide. */
    public function aplicaMajuscule(?int $utilizator = null): void
    {
        $c = $this->citeste();
        $c['titular'] = mb_strtoupper($c['titular']);
        $c['banca'] = mb_strtoupper($c['banca']);
        $this->app->setari()->pune(self::CHEIE, $c + ['propune_majuscule' => false], $utilizator);
    }

    /** Contul de pus pe comandă, înghețat la rezervare. @return array{titular:string, iban:string, banca:string, swift:string} */
    public function instantaneu(): array
    {
        return array_intersect_key($this->citeste(), array_flip(['titular', 'iban', 'banca', 'swift']));
    }

    /**
     * Termenul de plată al unei rezervări făcute acum: acum plus orele alese de gazdă, cel mult sfârșitul zilei
     * de sosire (ora României). Null când până la termen rămâne sub o oră, deci transferul nu mai are rost.
     */
    public function termenPentru(string $sosire): ?int
    {
        $acum = $this->app->ceas()->acum();
        $t = $acum + $this->citeste()['termen_ore'] * 3600;
        try {
            $sfarsit = (new \DateTimeImmutable($sosire . ' 23:59:59', new \DateTimeZone(Format::FUS)))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
        $t = min($t, $sfarsit);
        return $t - $acum >= self::TERMEN_MINIM ? $t : null;
    }

    /**
     * Transferul se oferă pentru o sosire în ziua dată: contul e complet, până la sosire sunt cel puțin atâtea zile
     * câte a ales gazda și termenul de plată mai lasă timp.
     */
    public function oferitPentru(string $sosire): bool
    {
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $sosire) || !$this->complet()) {
            return false;
        }
        $zile = (int) floor((strtotime($sosire . ' 00:00:00 UTC') - strtotime($this->app->azi() . ' 00:00:00 UTC')) / 86400);
        return $zile >= $this->citeste()['zile_inainte'] && $this->termenPentru($sosire) !== null;
    }
}
