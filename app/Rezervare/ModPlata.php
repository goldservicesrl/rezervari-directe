<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Modurile de plată active și starea plății online.
 *
 * Gazda alege în panou, la configurare, fără valoare impusă, cu trei bife independente: „Cu cardul,
 * online”, „La sosire (numerar)” și „Prin transfer bancar”, cel puțin una. Setarea `plati.metode` ține lista metodelor
 * bifate (`card`, `cash`, `transfer`; migrarea 0038 aduce valorile vechi card / cash / ambele). Cardul cere Stripe
 * configurat, cu cheia verificată; transferul cere contul bancar completat (ContTransfer). Până la alegere, site-ul
 * arată doar prezentarea, fără nicio rezervare (Aplicatie::configurareLipsa), iar panoul cere alegerea.
 *
 * Transferul bancar: oferit când e bifat, contul e complet, planul are avans și politică de anulare, cheia
 * EasyCheck permite rezervări și sosirea e destul de departe (ContTransfer::oferitPentru). Când lipsește, oaspetele
 * vede doar celelalte metode, fără nicio explicație.
 *
 * Planul cu avans 0%: oaspetele nu plătește nimic la rezervare; rezervarea pleacă drept cerere cu plata la
 * sosire, acceptată de gazdă în EasyCheck, oricare ar fi metodele alese. Panoul îi spune gazdei asta lângă metode,
 * iar cu cardul sau transferul bifat îi numește tarifele cu avans 0% și ce are de schimbat în EasyCheck.
 *
 * Tariful nerambursabil: se oferă doar cu metodele plătite la rezervare (cardul, transferul bancar), când
 * gazda le are și pot fi oferite acum. Plata la sosire lipsește mereu la el, și ca rezervă sau la avans 0%
 *. Fără nicio metodă plătită la rezervare, tariful lipsește de pe site (`ascuns`), iar oaspetele vede
 * celelalte tarife; panoul îi spune gazdei de ce (`notaNerambursabil`).
 *
 * Tariful cu avans: cât o metodă plătită la rezervare e oferită pentru el (cardul sau transferul bancar), plata
 * la sosire lipsește, chiar bifată: altfel oaspetele ar ocoli avansul. Plata la sosire rămâne la tarifele cu avans 0%
 * și, la un tarif cu avans, doar când nicio metodă plătită la rezervare nu e oferită acum: gazda a bifat numai
 * plata la sosire, plata cu cardul e oprită ori sosirea e prea aproape pentru transfer.
 *
 * Plata online se oprește pentru un motiv global (cheia Stripe care pică verificarea, amestecul test/live, cheia
 * EasyCheck respinsă, oglinda disponibilității mai veche de 30 de minute) sau pentru un plan fără politică de
 * anulare. Oglinda veche se recitește întâi: un site care doar n-a rulat (fără cron, fără vizitatori)
 * nu oprește plata; o oprește doar citirea care eșuează. Cu „Card” și plata oprită, oaspetele trimite o cerere cash ca rezervă; cu „Ambele”, cererea
 * cash rămâne oricum. Întrerupătorul oprește toate rezervările, cu e-mailul lui (alerta_intrerupator).
 *
 * la fiecare schimbare de stare (oprire sau revenire), proprietarii primesc imediat e-mailul cu motivul și ce
 * au de făcut (`plata_oprita`, `plata_revenita`), în limba panoului fiecăruia; o oprire care continuă nu mai
 * trimite alt e-mail (oprirea și revenirea pleacă la fiecare schimbare, nu zilnic).
 */
final class ModPlata
{
    /** Metodele de plată, independente una de alta. */
    public const METODE = ['card', 'cash', 'transfer'];
    /** Numele fiecărei combinații de bife (pentru texte și pentru apelurile de dinaintea plății prin transfer bancar). */
    public const COMBINATII = ['card' => ['card'], 'cash' => ['cash'], 'ambele' => ['card', 'cash'], 'transfer' => ['transfer'],
        'card_transfer' => ['card', 'transfer'], 'cash_transfer' => ['cash', 'transfer'], 'toate' => ['card', 'cash', 'transfer']];
    public const PROSPETIME = 1800;
    public const REVENIRE = 600;

    public function __construct(private Aplicatie $app)
    {
    }

    /**
     * Metodele bifate de gazdă, în ordinea din METODE; lista goală până la alegere. Valorile de dinaintea migrării 0038
     * (card, cash, ambele) se citesc la fel, ca o copie veche a bazei să rămână valabilă.
     * @return list<string>
     */
    public function lista(): array
    {
        return self::normalizeaza($this->app->setari()->get('plati.metode'));
    }

    /** @return list<string> lista curată a unei valori salvate (listă, numele unei combinații) sau [] */
    public static function normalizeaza(mixed $v): array
    {
        if (is_string($v)) {
            $v = self::COMBINATII[$v] ?? [];
        }
        return is_array($v) ? array_values(array_filter(self::METODE, static fn (string $m): bool => in_array($m, $v, true))) : [];
    }

    /** Gazda a bifat metoda. */
    public function are(string $metoda): bool
    {
        return in_array($metoda, $this->lista(), true);
    }

    /** Numele combinației bifate (card, cash, ambele, transfer, card_transfer, cash_transfer, toate) sau null până la alegere. */
    public function metode(): ?string
    {
        return self::cheie($this->lista());
    }

    /** @param list<string> $lista */
    public static function cheie(array $lista): ?string
    {
        $k = array_search(self::normalizeaza($lista), self::COMBINATII, true);
        return is_string($k) ? $k : null;
    }

    /**
     * Bifele gazdei („Cu cardul, online”, „La sosire (numerar)”, „Prin transfer bancar”) traduse în numele
     * combinației lor. Fără nicio bifă: null.
     */
    public static function dinBife(bool $card, bool $cash, bool $transfer = false): ?string
    {
        return self::cheie(array_keys(array_filter(['card' => $card, 'cash' => $cash, 'transfer' => $transfer])));
    }

    /** Bifele unei alegeri salvate (sau ale niciuneia). @return array{card:bool, cash:bool, transfer:bool} */
    public static function bife(string|array|null $m): array
    {
        $l = self::normalizeaza($m);
        return ['card' => in_array('card', $l, true), 'cash' => in_array('cash', $l, true), 'transfer' => in_array('transfer', $l, true)];
    }

    /** @param string|list<string> $m numele combinației sau lista metodelor */
    public function alegeMetode(string|array $m): void
    {
        $lista = self::normalizeaza($m);
        if ($lista === [] || (is_string($m) && !isset(self::COMBINATII[$m])) || (is_array($m) && count($lista) !== count($m))) {
            throw new \InvalidArgumentException('Metodă de plată necunoscută.');
        }
        // Cardul cere Stripe configurat, verificat și pe server, nu doar în formular.
        if (in_array('card', $lista, true) && !$this->app->stripe()->configurat()) {
            throw new \DomainException('Cardul cere contul Stripe configurat.');
        }
        // Transferul cere contul bancar: titularul și IBAN-ul.
        if (in_array('transfer', $lista, true) && !$this->app->contTransfer()->complet()) {
            throw new \DomainException('Transferul bancar cere titularul și IBAN-ul contului.');
        }
        $prima = $this->lista() === [];
        $this->app->setari()->pune('plati.metode', $lista);
        if ($prima) {
            // Prima alegere încheie configurarea: paginile din cache, cu bara de contact, se refac.
            $this->app->ecOglinda()->schimbaContinutul();
        }
        $this->evalueaza();
    }

    /** Bifează sau debifează o singură metodă, celelalte rămânând cum sunt; false când ar rămâne fără nicio metodă. */
    public function comuta(string $metoda, bool $bifata): bool
    {
        $lista = array_values(array_diff($this->lista(), [$metoda]));
        if ($bifata) {
            $lista[] = $metoda;
        }
        if ($lista === []) {
            return false;
        }
        $this->alegeMetode($lista);
        return true;
    }

    /** Gazda a ales cardul (singur sau cu alte metode). */
    public function vreaCard(): bool
    {
        return $this->are('card');
    }

    /** Gazda încasează o parte din sejur înaintea sosirii (cu cardul sau prin transfer): taxa locală „online” are rost. */
    public function incaseazaInainte(): bool
    {
        return $this->are('card') || $this->are('transfer');
    }

    /**
     * Motivele globale pentru care plata online e oprită acum (fără planuri). Lista goală: plata merge.
     * @return list<string> stripe_lipsa, stripe_cheie, mod_mixt, easycheck_cheie, easycheck
     */
    public function motive(): array
    {
        $m = [];
        $stripe = $this->app->stripe()->stare();
        if (!$stripe['configurata']) {
            $m[] = 'stripe_lipsa';
        } elseif (!$stripe['ok']) {
            $m[] = 'stripe_cheie';
        }
        $lic = $this->app->ecLicenta()->stare();
        $cheie = $this->app->secrete()->citeste('stripe_cheie');
        // Fără cheie EasyCheck, modul site-ului urmează chiar cheia Stripe, deci amestecul nu există.
        if ($cheie !== null && $lic['mod'] !== null && !$this->app->modLocal() && !\Cazo\EasyCheck\Licenta::compatibilCuStripe((string) $lic['mod'], $cheie)) {
            $m[] = 'mod_mixt';
        }
        if (!$lic['rezervari']) {
            $m[] = 'easycheck_cheie';
        } else {
            // Histerezis: plata se oprește la o oglindă mai veche de 30 de minute și revine abia la una mai
            // nouă de 10 minute, ca un pseudo-cron rar să n-o pornească și oprească la fiecare vizită.
            // o oglindă veche spune întâi că site-ul n-a rulat (fără cron, fără vizitatori), nu că EasyCheck
            // tace. Înainte de oprire, site-ul citește disponibilitatea chiar acum; plata se oprește doar când citirea
            // eșuează (ori a eșuat de curând și așteaptă reluarea), adică doar când EasyCheck chiar nu răspunde.
            $eraOprita = in_array('easycheck', (array) (($this->app->setari()->get('plati.online') ?? [])['motive'] ?? []), true);
            $prag = $eraOprita ? self::REVENIRE : self::PROSPETIME;
            $varsta = $this->varstaDisponibilitatii();
            if ($varsta >= $prag) {
                $this->improspateaza();
                $varsta = $this->varstaDisponibilitatii();
            }
            if ($varsta >= $prag) {
                $m[] = 'easycheck';
            }
        }
        return $m;
    }

    /** Câte secunde au trecut de la ultima citire reușită a disponibilității (PHP_INT_MAX: niciodată). */
    private function varstaDisponibilitatii(): int
    {
        $v = $this->app->baza()->valoare("SELECT verificat_la FROM {ec_resurse} WHERE resursa = 'disponibilitate'");
        return $v === null ? PHP_INT_MAX : $this->app->ceas()->acum() - Ceas::dinMoment((string) $v);
    }

    /**
     * Rezultatul ultimei încercări de a reciti disponibilitatea înaintea deciziei: `citita`, `esuata`
     * (eroare acum ori reluare încă în așteptare după o eroare), `ocupata` (o altă sincronizare rulează chiar acum),
     * `sarita` (fără cheie, într-o tranzacție deschisă) sau null (n-a fost nevoie).
     */
    private ?string $improspatare = null;
    private ?int $improspatareLa = null;

    /**
     * Recitește disponibilitatea din EasyCheck înaintea deciziei de oprire a plății, cu timpii obișnuiți ai
     * clientului. O singură încercare pe secundă și instanță (pagina cheamă `pentru` pentru fiecare tarif); după o eroare,
     * reluarea așteaptă pauza oglinzii (`reincercare_la`, Oglinda::PAUZA_MAXIMA), deci un EasyCheck căzut costă cel mult o
     * cerere lentă la câteva minute, nu una pe fiecare pagină. Fără cerere în rețea într-o tranzacție deschisă.
     */
    public function improspateaza(): string
    {
        $acum = $this->app->ceas()->acum();
        if ($this->improspatareLa === $acum && $this->improspatare !== null) {
            return $this->improspatare;
        }
        $this->improspatareLa = $acum;
        if ($this->app->cheieEc() === null || $this->app->baza()->inTranzactie()) {
            return $this->improspatare = 'sarita';
        }
        $reluare = $this->app->baza()->valoare("SELECT reincercare_la FROM {ec_resurse} WHERE resursa = 'disponibilitate'");
        if (is_string($reluare) && $reluare > $this->app->ceas()->moment()) {
            return $this->improspatare = 'esuata';
        }
        try {
            $r = $this->app->ecSincronizare()->ruleaza(['disponibilitate'], 10.0);
        } catch (\Throwable) {
            return $this->improspatare = 'esuata';
        }
        if (!empty($r['ocupat'])) {
            return $this->improspatare = 'ocupata';
        }
        return $this->improspatare = isset($r['citite']['disponibilitate']) ? 'citita' : 'esuata';
    }

    /** Planul are politica de anulare completă: nerambursabil sau cu termen ales. */
    public static function politicaCompleta(?array $plan): bool
    {
        return $plan === null || ($plan['tip'] ?? null) === 'nerambursabil' || $plan['anulare_zile'] !== null;
    }

    /** Planul are avansul 0%: rezervarea lui pleacă drept cerere cu plata la sosire. */
    public static function faraAvans(?array $plan): bool
    {
        return $plan !== null && ($plan['avans_procent'] ?? null) !== null && (int) $plan['avans_procent'] === 0;
    }

    /** Planul are un avans de plătit la rezervare: procentul avansului e peste 0. */
    public static function cuAvans(?array $plan): bool
    {
        return $plan !== null && ($plan['avans_procent'] ?? null) !== null && (int) $plan['avans_procent'] > 0;
    }

    /** Planul e nerambursabil: se oferă doar cu o metodă plătită la rezervare. */
    public static function nerambursabil(?array $plan): bool
    {
        return $plan !== null && ($plan['tip'] ?? null) === 'nerambursabil';
    }

    /**
     * Tariful nerambursabil lipsește acum de pe site: nicio metodă plătită la rezervare nu e oferită pentru el
     * (gazda are doar plata la sosire, tariful are avans 0%, plata cu cardul e oprită, iar transferul lipsește ori
     * sosirea e prea aproape pentru el). Până la alegerea metodelor (site doar de prezentare), nimic nu se ascunde.
     */
    public function ascuns(?array $plan, string $sosire): bool
    {
        if (!self::nerambursabil($plan) || $this->lista() === []) {
            return false;
        }
        $m = $this->pentru($plan, $sosire);
        return !$m['card'] && !$m['transfer'];
    }

    /**
     * Tariful nerambursabil care lipsește de pe site oricare ar fi datele: gazda are doar plata la sosire sau
     * tariful are avans 0%. Pentru listele fără zi de sosire (planurile din modelul temei, nota din panou).
     */
    public function ascunsMereu(?array $plan): bool
    {
        return self::nerambursabil($plan) && $this->lista() !== [] && (self::faraAvans($plan) || !$this->incaseazaInainte());
    }

    /**
     * Ce oferă comanda pentru planul ales și, la transfer, pentru ziua sosirii.
     * @return array{card:bool, cash:bool, transfer:bool, rezerva:bool, motive:list<string>, fara_avans:bool}
     *   rezerva = cererea cash apare doar ca rezervă, fiindcă plata online e oprită
     *   fara_avans = planul cu avans 0%: doar cererea cash, oricare ar fi metodele
     *   transfer = transferul bancar e oferit acum; fără ziua sosirii, false
     */
    public function pentru(?array $plan, ?string $sosire = null): array
    {
        $nerambursabil = self::nerambursabil($plan);
        if ($nerambursabil && self::faraAvans($plan)) {
            // un tarif nerambursabil cu avans 0% ar însemna „plata e definitivă” fără nicio plată: lipsește de pe site.
            return ['card' => false, 'cash' => false, 'transfer' => false, 'rezerva' => false, 'motive' => [], 'fara_avans' => true];
        }
        if (self::faraAvans($plan)) {
            return ['card' => false, 'cash' => true, 'transfer' => false, 'rezerva' => false, 'motive' => [], 'fara_avans' => true];
        }
        $lista = $this->lista();
        $motive = $this->vreaCard() ? $this->motive() : [];
        if ($this->vreaCard() && !self::politicaCompleta($plan)) {
            $motive[] = 'politica';
        }
        $card = $this->vreaCard() && $motive === [];
        // transferul e o plată în avans, deci cere aceeași politică de anulare completă ca plata cu cardul.
        $transfer = in_array('transfer', $lista, true) && $sosire !== null && self::politicaCompleta($plan)
            && $this->app->ecLicenta()->stare()['rezervari'] && $this->app->contTransfer()->oferitPentru($sosire);
        // Plata la sosire: bifată, sau singura cale rămasă când nicio altă metodă nu e oferită acum.
        $cash = in_array('cash', $lista, true) || $lista === [] || (!$card && !$transfer);
        // la un tarif cu avans, plata la sosire lipsește cât o metodă plătită la rezervare e oferită acum.
        if (self::cuAvans($plan) && ($card || $transfer)) {
            $cash = false;
        }
        if ($nerambursabil && $lista !== []) {
            // tariful nerambursabil se plătește la rezervare; plata la sosire lipsește, și ca rezervă.
            return ['card' => $card, 'cash' => false, 'transfer' => $transfer, 'rezerva' => false, 'motive' => $motive, 'fara_avans' => false];
        }
        return ['card' => $card, 'cash' => $cash, 'transfer' => $transfer, 'rezerva' => $this->vreaCard() && !in_array('cash', $lista, true) && !$card && !$transfer,
            'motive' => $motive, 'fara_avans' => false];
    }

    /**
     * Planurile vizibile fără politică de anulare (alerta din panou). Planurile cu avans 0% rămân în afară:
     * ele nu încasează nimic online. @return list<string>
     */
    public function planuriFaraPolitica(): array
    {
        $r = [];
        foreach ($this->app->pretNoapte()->planuri() as $id => $p) {
            if (!$p['ascuns'] && !self::politicaCompleta($p) && !self::faraAvans($p)) {
                $r[] = (string) $id;
            }
        }
        return $r;
    }

    /**
     * Numele tarifelor vizibile cu avans 0%, în limba dată: oaspeții le rezervă doar cu plata la sosire.
     * Tariful nerambursabil cu avans 0% are nota lui (`notaNerambursabil`): el lipsește de pe site.
     * @return list<string>
     */
    public function tarifeFaraAvans(string $limba): array
    {
        $r = [];
        foreach ($this->app->pretNoapte()->planuri() as $p) {
            if (!$p['ascuns'] && self::faraAvans($p) && !self::nerambursabil($p)) {
                $r[] = \Cazo\Teme\Componente::titluPlan($p, $limba, $this->app);
            }
        }
        return $r;
    }

    /**
     * Nota de informare a gazdei despre tarifele cu avans 0%: le numește și spune ce are de schimbat în EasyCheck
     * ca oaspeții să le poată plăti cu cardul sau prin transfer bancar. În panou apare doar cu cardul sau transferul
     * bifat; în instalator (`$oricum`), ori de câte ori oglinda are asemenea tarife. Null când nu e nimic de spus.
     */
    public function notaFaraAvans(\Cazo\I18n\Traducator $t, bool $oricum = false): ?string
    {
        if (!$oricum && !$this->incaseazaInainte()) {
            return null;
        }
        $tarife = $this->tarifeFaraAvans($t->limba());
        if ($tarife === []) {
            return null;
        }
        return $t->t(count($tarife) === 1 ? 'admin.plati.avans_zero_tarif' : 'admin.plati.avans_zero_tarife', ['tarife' => implode(', ', $tarife)]);
    }

    /**
     * Numele tarifelor vizibile cu avans, în limba dată: la ele, plata la sosire lipsește cât oaspetele poate plăti
     * cu cardul sau prin transfer bancar. Tariful nerambursabil rămâne în afară (la el plata la sosire lipsește mereu).
     * @return list<string>
     */
    public function tarifeCuAvans(string $limba): array
    {
        $r = [];
        foreach ($this->app->pretNoapte()->planuri() as $p) {
            if (!$p['ascuns'] && self::cuAvans($p) && !self::nerambursabil($p)) {
                $r[] = \Cazo\Teme\Componente::titluPlan($p, $limba, $this->app);
            }
        }
        return $r;
    }

    /**
     * Nota de informare a gazdei despre plata la sosire la tarifele cu avans, lângă bifele metodelor: cu plata la
     * sosire bifată lângă card sau transfer, numește tarifele cu avans la care plata la sosire lipsește; cu plata la sosire
     * singură, pe cele pe care oaspeții le rezervă fără să plătească avansul. În instalator (`$oricum`, bifele se aleg
     * abia acolo), prima formă apare ori de câte ori oglinda are tarife cu avans. Null când nu e nimic de spus.
     */
    public function notaCuAvans(\Cazo\I18n\Traducator $t, bool $oricum = false): ?string
    {
        $tarife = $this->tarifeCuAvans($t->limba());
        if ($tarife === [] || (!$oricum && !$this->are('cash'))) {
            return null;
        }
        $fel = !$oricum && !$this->incaseazaInainte() ? 'doar_sosire' : 'lipseste';
        return $t->t('admin.plati.avans_sosire.' . $fel . (count($tarife) === 1 ? '_tarif' : '_tarife'), ['tarife' => implode(', ', $tarife)]);
    }

    /**
     * Nota de informare a gazdei despre tariful nerambursabil, pe pagina „Metodele de plată” și în instalator:
     * cu un tarif nerambursabil vizibil și doar plata la sosire bifată, că tariful apare pe site doar cu plata cu
     * cardul sau prin transfer bancar; cu un tarif nerambursabil cu avans 0%, că rămâne în afara site-ului până
     * primește un avans în EasyCheck. În instalator (`$oricum`, bifele se aleg abia acolo), prima apare ori de câte
     * ori oglinda are un tarif nerambursabil vizibil. Null când nu e nimic de spus.
     */
    public function notaNerambursabil(\Cazo\I18n\Traducator $t, bool $oricum = false): ?string
    {
        $zero = false;
        $are = false;
        foreach ($this->app->pretNoapte()->planuri() as $p) {
            if ($p['ascuns'] || !self::nerambursabil($p)) {
                continue;
            }
            if (self::faraAvans($p)) {
                $zero = true;
            } else {
                $are = true;
            }
        }
        $parti = [];
        if ($are && ($oricum || ($this->lista() !== [] && !$this->incaseazaInainte()))) {
            $parti[] = $t->t('admin.plati.nerambursabil_plata');
        }
        if ($zero) {
            $parti[] = $t->t('admin.plati.nerambursabil_avans_zero');
        }
        return $parti === [] ? null : implode(' ', $parti);
    }

    /**
     * Starea plății online, ținută în `plati.online` {oprita, motive, la}; la o schimbare, e-mailul către proprietari.
     * Întoarce starea nouă.
     */
    public function evalueaza(): array
    {
        $vechi = (array) ($this->app->setari()->get('plati.online') ?? []);
        if (!$this->vreaCard()) {
            if (($vechi['oprita'] ?? false) === true) {
                $this->app->setari()->pune('plati.online', null);
            }
            return ['oprita' => false, 'motive' => []];
        }
        $this->improspatare = null;
        $motive = $this->motive();
        if (in_array('easycheck', $motive, true) && $this->improspatare === 'ocupata') {
            // o altă sincronizare citește chiar acum; decizia o ia rularea următoare, pe datele ei.
            return ['oprita' => ($vechi['oprita'] ?? false) === true, 'motive' => (array) ($vechi['motive'] ?? [])] + $vechi;
        }
        if ($this->planuriFaraPolitica() !== []) {
            $motive[] = 'politica';
        }
        $oprita = $motive !== [];
        $nou = ['oprita' => $oprita, 'motive' => $motive, 'la' => $this->app->ceas()->acum()];
        $schimbat = ($vechi['oprita'] ?? false) !== $oprita || ($oprita && array_diff($motive, (array) ($vechi['motive'] ?? [])) !== []);
        if ($vechi === [] && !$oprita) {
            $schimbat = false;
        }
        if ($schimbat || $vechi === []) {
            $this->app->setari()->pune('plati.online', $nou);
        }
        if ($schimbat) {
            $this->anuntaGazda($oprita ? 'plata_oprita' : 'plata_revenita', $motive);
        }
        return $nou;
    }

    private function anuntaGazda(string $sablon, array $motive): void
    {
        $this->app->jurnal()->avertisment($sablon === 'plata_oprita' ? 'Plata online s-a oprit' : 'Plata online a revenit', ['motive' => $motive]);
        foreach ($this->app->baza()->toate("SELECT email, limba_panou FROM {utilizatori} WHERE rol = 'proprietar' AND activ = 1 ORDER BY id") as $u) {
            // `metode`: ce le rămâne oaspeților cât plata online e oprită (cererea ca rezervă, plata la sosire, transferul).
            $this->app->emailuri()->pune(null, $sablon, (string) $u['limba_panou'], (string) $u['email'], ['motive' => implode(',', $motive),
                'metode' => $this->are('cash') ? 'cash' : ($this->are('transfer') ? 'transfer' : 'card')]);
        }
    }
}
