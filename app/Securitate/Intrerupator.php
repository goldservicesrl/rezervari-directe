<?php

declare(strict_types=1);

namespace Cazo\Securitate;

use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Setari;

defined('CAZO') or exit;

/**
 * Întrerupătorul și codul de 6 cifre la cererea cash.
 *
 * Pornit automat implicit: când cererile POST /rezerva din ultima oră depășesc de 5 ori media orară pe 7 zile,
 * peste un prag minim egal cu maximul dintre 30 și numărul de camere (configurabil în `limite`, cheia
 * `intrerupator_minim_ora`), sau când eșecurile de plată trec de 10 într-o oră (`limite.esecuri_plata_ora`),
 * rezervările online și cererile trec pe contact („Rezervați telefonic sau pe e-mail”), codul pe e-mail se pornește
 * (sursa „automat”) și gazda primește e-mail. Gazda repornește rezervările din panou.
 * Codul pornit de întrerupător se oprește singur după 24 de ore fără comenzi peste prag, iar gazda primește
 * e-mailul „Protecția s-a oprit”; codul pornit de gazdă rămâne cum l-a lăsat ea.
 *
 * Setările: `intrerupator` {pornit, motiv, la}, `intrerupator.automat` (implicit true), `cod_email` {activ, sursa, la},
 * `intrerupator.peste_prag_la` (ultima oră cu comenzi peste prag).
 */
final class Intrerupator
{
    public const FACTOR = 5;
    public const MINIM_ORA = 30;
    public const ESECURI_PLATA_ORA = 10;
    public const OPRIRE_COD = 86400;
    public const ISTORIC = 'istoric:rezerva:ora';
    public const ISTORIC_PLATI = 'istoric:plata_esuata:ora';

    /** @var list<callable(string $motiv): void> */
    private array $laDeclansare = [];

    /** @var list<callable(): void> */
    private array $laOprireCod = [];

    /** @param \Closure(): int|null $camere numărul de camere (unitățile din oglindă), pentru pragul minim */
    public function __construct(private Setari $setari, private Ceas $ceas, private Limitator $limitator, private ?\Closure $camere = null)
    {
    }

    public function laOprireCod(callable $f): void
    {
        $this->laOprireCod[] = $f;
    }

    /** Pragul minim pe oră: maximul dintre 30 și numărul de camere, sau valoarea aleasă în panou. */
    public function pragMinim(): int
    {
        $l = (array) $this->setari->get('limite', []);
        if (is_int($l['intrerupator_minim_ora'] ?? null) && $l['intrerupator_minim_ora'] > 0) {
            return $l['intrerupator_minim_ora'];
        }
        return max(self::MINIM_ORA, $this->camere !== null ? (int) ($this->camere)() : 0);
    }

    public function laDeclansare(callable $f): void
    {
        $this->laDeclansare[] = $f;
    }

    public function pornit(): bool
    {
        return (bool) ($this->setari->get('intrerupator')['pornit'] ?? false);
    }

    /** @return array{pornit:bool, motiv:?string, la:?int, automat:bool} */
    public function stare(): array
    {
        $s = $this->setari->get('intrerupator');
        return ['pornit' => (bool) ($s['pornit'] ?? false), 'motiv' => $s['motiv'] ?? null, 'la' => $s['la'] ?? null,
            'automat' => (bool) $this->setari->get('intrerupator.automat', true)];
    }

    /** Numără un POST /rezerva și declanșează la abuz. Întoarce true când întrerupătorul e pornit după numărare. */
    public function numaraRezerva(): bool
    {
        $acum = $this->ceas->acum();
        $ora = $this->limitator->inregistreaza(self::ISTORIC, 3600);
        // Media pe cele 168 de ore încheiate dinaintea orei curente.
        $sfarsitOraCurenta = (intdiv($acum, 3600) + 1) * 3600;
        $media = $this->limitator->suma(self::ISTORIC, $sfarsitOraCurenta - 169 * 3600, $sfarsitOraCurenta - 3600) / 168;
        $peste = $ora > max($this->pragMinim(), self::FACTOR * $media);
        if ($peste) {
            // Și cât întrerupătorul e pornit: valul încă în curs ține codul pornit.
            $this->setari->pune('intrerupator.peste_prag_la', $acum);
        }
        if ($this->pornit()) {
            return true;
        }
        if (!$this->setari->get('intrerupator.automat', true)) {
            return false;
        }
        if ($peste) {
            $this->declanseaza('rezerva_peste_medie');
            return true;
        }
        return false;
    }

    /**
     * Numără o plată eșuată sau refuzată, o singură dată pe $cheie (comanda sau PaymentIntent-ul: anunțul
     * payment_failed și trecerea în plata_esuata a aceleiași comenzi fac un singur eșec); peste 10 într-o oră,
     * întrerupătorul pornește.
     */
    public function numaraEsecPlata(?string $cheie = null): bool
    {
        if ($cheie !== null && $cheie !== '' && !$this->limitator->incearca('esec_plata:' . mb_substr($cheie, 0, 120), 1, 86400)) {
            return $this->pornit();
        }
        $n = $this->limitator->inregistreaza(self::ISTORIC_PLATI, 3600);
        if ($this->pornit() || !$this->setari->get('intrerupator.automat', true)) {
            return $this->pornit();
        }
        $l = (array) $this->setari->get('limite', []);
        $prag = is_int($l['esecuri_plata_ora'] ?? null) && $l['esecuri_plata_ora'] > 0 ? $l['esecuri_plata_ora'] : self::ESECURI_PLATA_ORA;
        if ($n > $prag) {
            $this->setari->pune('intrerupator.peste_prag_la', $this->ceas->acum());
            $this->declanseaza('esecuri_plata');
            return true;
        }
        return false;
    }

    /**
     * Cronul: codul pornit de întrerupător se oprește după 24 de ore fără comenzi peste prag; gazda primește
     * e-mailul „Protecția s-a oprit”. Întoarce true când l-a oprit acum.
     */
    public function verificaCodul(): bool
    {
        $c = $this->setari->get('cod_email');
        if (!is_array($c) || !($c['activ'] ?? false) || ($c['sursa'] ?? null) !== 'automat') {
            return false;
        }
        $ultima = max((int) ($c['la'] ?? 0), (int) $this->setari->get('intrerupator.peste_prag_la', 0));
        if ($ultima > 0 && $this->ceas->acum() - $ultima < self::OPRIRE_COD) {
            return false;
        }
        $this->setari->pune('cod_email', ['activ' => false, 'sursa' => null]);
        foreach ($this->laOprireCod as $f) {
            $f();
        }
        return true;
    }

    public function declanseaza(string $motiv): void
    {
        $this->setari->pune('intrerupator', ['pornit' => true, 'motiv' => $motiv, 'la' => $this->ceas->acum()]);
        $this->pornesteCodul('automat');
        foreach ($this->laDeclansare as $f) {
            $f($motiv);
        }
    }

    /** Repornirea din panou (sau acționarea manuală, cu $pornit = true). */
    public function seteaza(bool $pornit): void
    {
        $this->setari->pune('intrerupator', ['pornit' => $pornit, 'motiv' => $pornit ? 'manual' : null, 'la' => $this->ceas->acum()]);
    }

    public function codActiv(): bool
    {
        return (bool) ($this->setari->get('cod_email')['activ'] ?? false);
    }

    /** Metodele de plată la care se cere codul, alese de gazdă; implicit plata la sosire și transferul. */
    public const METODE_COD = ['cash', 'transfer', 'card'];
    public const METODE_COD_IMPLICIT = ['cash', 'transfer'];

    /** @return list<string> */
    public function metodeCod(): array
    {
        $m = $this->setari->get('cod_email.metode');
        return is_array($m) ? array_values(array_intersect(self::METODE_COD, $m)) : self::METODE_COD_IMPLICIT;
    }

    public function salveazaMetodeCod(array $metode): void
    {
        $this->setari->pune('cod_email.metode', array_values(array_intersect(self::METODE_COD, $metode)));
    }

    /** Codul se cere acum pentru o comandă cu metoda dată: pornit (de gazdă sau automat) și metoda bifată. */
    public function codCerut(string $metoda): bool
    {
        return $this->codActiv() && in_array($metoda, $this->metodeCod(), true);
    }

    /** @return array{activ:bool, sursa:?string, metode:list<string>} */
    public function cod(): array
    {
        $c = $this->setari->get('cod_email');
        return ['activ' => (bool) ($c['activ'] ?? false), 'sursa' => $c['sursa'] ?? null, 'metode' => $this->metodeCod()];
    }

    /** Comutatorul gazdei: pornit sau oprit, oricare ar fi sursa anterioară. */
    public function comutaCodul(bool $activ): void
    {
        $this->setari->pune('cod_email', ['activ' => $activ, 'sursa' => $activ ? 'gazda' : null, 'la' => $this->ceas->acum()]);
    }

    private function pornesteCodul(string $sursa): void
    {
        if (!$this->codActiv()) {
            $this->setari->pune('cod_email', ['activ' => true, 'sursa' => $sursa, 'la' => $this->ceas->acum()]);
        }
    }
}
