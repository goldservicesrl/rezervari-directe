<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Legal\BlocLegal;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cai;
use Cazo\Pret\Sejur;

defined('CAZO') or exit;

/**
 * Datele componentelor nucleului (bara, calendarul, rezumatul, harta, contactul, blocul legal, pozele), pregătite
 * pentru șabloanele din vederi/componente/. Textele vin din cataloagele nucleului; sumele, din PretNoapte și din
 * fațada Total.
 */
final class Componente
{
    /** @var list<string> nopțile ocupate chiar acum, marcate peste oglindă (ocupateDin) */
    public array $ocupateAcum = [];

    public const OASPETI_MAX = 16;

    public function __construct(
        private Aplicatie $app,
        private ModelVedere $model,
        private Traducator $t,
        private string $limba,
        private ?Sejur $cerere,
        private string $ip24,
    ) {
    }

    public function date(string $componenta, array $a): ?array
    {
        return match ($componenta) {
            'bara' => $this->bara(),
            'calendar' => $this->calendar($a['camera'] ?? null),
            'rezumat' => $this->rezumat($a['camera'] ?? null),
            'harta' => $this->harta(),
            'vreme' => $this->vreme(),
            'contact' => $this->contact() + ['editat' => $this->subsol()['contact'] ?? null],
            'legal' => (new BlocLegal($this->app, $this->t, $this->limba))->date() + ['editat' => $this->subsol()['legal'] ?? null],
            'poza' => $this->app->ecPoze()->pentruTema((string) $a['sha']),
            'poza_in_lucru' => ['da' => $this->app->ecPoze()->inLucru((string) $a['sha'])],
            default => [],
        };
    }

    /** Cererea din adresă, pentru modelul de vizualizare (sosire, plecare, camere[]). */
    public function cerere(): ?array
    {
        return $this->cerere === null ? null : ['sosire' => $this->cerere->sosire, 'plecare' => $this->cerere->plecare, 'camere' => $this->cerere->camere];
    }

    /**
     * Planurile tarifare vizibile pe site, pentru modelul de vizualizare (`cerere.plan`
     * „mesele doar ca planuri”): doar planurile cu prețuri pe cel puțin o cameră afișată, în ordinea din EasyCheck,
     * cu titlul (cel local, când gazda l-a tradus) și politica de anulare scrisă de nucleu, aceeași ca lângă preț.
     * @return list<array{id:string, titlu:string, politica:string, camere:int, masa:?string, masa_eticheta:string}>
     */
    public function planuri(): array
    {
        $pn = $this->app->pretNoapte();
        $pe = [];
        foreach ($this->model->camere() as $c) {
            foreach ($pn->planuriCamera((string) $c['id']) as $pid) {
                $pe[$pid] = ($pe[$pid] ?? 0) + 1;
            }
        }
        // Tarifele de sejur lung sunt reducerea de durată a tarifului de bază, aplicată singură la datele alese:
        // în lista temei rămân doar când niciun alt tarif cu aceeași politică de anulare nu e pe site.
        // tariful nerambursabil care lipsește mereu de pe site (doar plata la sosire, avans 0%) lipsește și de aici.
        // la fel tariful rezervării din timp (modul fără cheie), tot o reducere aplicată singură la datele alese.
        $toate = array_filter($pn->planuri(), fn (array $p): bool => !$this->app->modPlata()->ascunsMereu($p));
        $reducere = static fn (array $p): bool => in_array($p['tip'] ?? null, ['sejur_lung', 'din_timp'], true);
        // „aceeași politică” înseamnă aceeași politică de anulare și aceeași masă, ca la tarifele pe date (Oferte).
        $grup = static fn (array $p): string => \Cazo\Pret\Oferte::grup($p) . '|' . (\Cazo\Pret\Oferte::masa($p) ?? '');
        $cuAlt = [];
        foreach ($toate as $pid => $p) {
            if (isset($pe[$pid]) && !$reducere($p)) {
                $cuAlt[$grup($p)] = true;
            }
        }
        $rez = [];
        foreach ($toate as $pid => $p) {
            if (isset($pe[$pid]) && (!$reducere($p) || !isset($cuAlt[$grup($p)]))) {
                $rez[] = ['id' => (string) $pid, 'titlu' => self::titluPlan($p, $this->limba, $this->app), 'politica' => $this->politicaAnulare($p), 'camere' => $pe[$pid],
                    // masa inclusă (codul și eticheta în limba paginii); fără masă, codul null și eticheta goală sau „Doar cazare”.
                    'masa' => in_array($p['masa'] ?? null, \Cazo\EasyCheck\Proiectii::MESE, true) ? $p['masa'] : null, 'masa_eticheta' => self::masaPlan($p, $this->limba, $this->t)];
            }
        }
        return self::cuDoarCazare($rez, 'masa_eticheta', $this->t);
    }

    /** Adresa absolută a imaginii Open Graph (varianta JPEG de 1440 px), sau null. */
    public function imagineOg(?array $poza): ?string
    {
        $poza ??= $this->model->proprietate()['poze'][0] ?? null;
        $d = is_array($poza) ? $this->app->ecPoze()->pentruTema((string) $poza['sha']) : null;
        if (!is_array($d) || !isset($d['surse']['jpg'])) {
            return null;
        }
        $j = $d['surse']['jpg'];
        return $this->app->config()->origine() . ($j[1440] ?? end($j));
    }

    /** Acțiunea unui formular GET și câmpurile ascunse (fără rescriere, calea merge în ?r=). */
    public function formular(string $cale): array
    {
        $u = $this->app->url()->cale($cale, $this->limba);
        $parti = explode('?', $u, 2);
        $ascunse = [];
        if (isset($parti[1])) {
            parse_str($parti[1], $q);
            foreach ($q as $k => $v) {
                if (is_string($v)) {
                    $ascunse[] = ['nume' => (string) $k, 'valoare' => $v];
                }
            }
        }
        return ['actiune' => $parti[0], 'ascunse' => $ascunse];
    }

    private function rezervariActive(): bool
    {
        // Până la configurarea completă, bara și rezumatul arată contactul, ca la o cheie fără rezervări.
        return $this->app->ecLicenta()->stare()['rezervari'] && $this->app->configurareLipsa() === [];
    }

    public function bara(): array
    {
        $c = $this->cerere;
        $p = $this->model->proprietate();
        return [
            'formular' => $this->formular(Cai::cale('disponibil', $this->limba)),
            'sosire' => $c?->sosire ?? '',
            'plecare' => $c?->plecare ?? '',
            'adulti' => $c !== null ? $c->camere[0]['adulti'] : 2,
            'copii' => $c !== null ? $c->camere[0]['copii'] : 0,
            'adulti_optiuni' => $this->optiuniOaspeti()['adulti'],
            'copii_optiuni' => $this->optiuniOaspeti()['copii'],
            'activa' => $this->rezervariActive(),
            'oprite' => $this->app->rezervariOprite(),
            'contact' => $p['contact'],
            'modul_calendar' => '',
        ];
    }

    /**
     * Câți adulți și copii se pot alege în căutare: cel mult capacitatea celei mai mari camere (căutarea
     * arată camerele care îi cuprind pe toți oaspeții); o cameră fără capacitate în EasyCheck lasă plafonul general.
     * @return array{adulti:list<int>, copii:list<int>}
     */
    public function optiuniOaspeti(): array
    {
        $max = 0;
        foreach ($this->model->camere() as $c) {
            if ($c['oaspeti_max'] === null) {
                $max = self::OASPETI_MAX;
                break;
            }
            $max = max($max, (int) $c['oaspeti_max']);
        }
        $max = $max > 0 ? min(self::OASPETI_MAX, $max) : self::OASPETI_MAX;
        return ['adulti' => range(1, $max), 'copii' => range(0, min(10, max(0, $max - 1)))];
    }

    /** Calendarul camerei: luna cererii (sau luna curentă) și următoarea, cu starea, prețul și sejurul minim. */
    public function calendar(?array $camera): array
    {
        $azi = $this->app->azi();
        $luna = substr($this->cerere?->sosire ?? $azi, 0, 7);
        return ['camera' => $camera['id'] ?? '', 'luni' => $camera === null ? [] : $this->luni($camera['id'], $luna, $this->cerere !== null ? $this->cerere->oaspeti(0) : 2),
            'api' => $this->app->url()->cale('/_api/calendar'), 'luna' => $luna, 'oaspeti' => $this->cerere !== null ? $this->cerere->oaspeti(0) : 2,
            'zile' => $this->zileSaptamana(), 'limba' => $this->limba, 'ocupate' => implode(',', $this->ocupateAcum),
            'mesaj' => $this->ocupateAcum !== [] ? $this->t->t('stare.indisponibila', ['zile' => $this->zile($this->ocupateAcum)]) : ''];
    }

    /**
     * Nopțile găsite ocupate chiar acum (409 indisponibil la cererea cash), din adresa `ocupate`
     * (zile AAAA-LL-ZZ, despărțite prin virgulă): calendarul le marchează ocupate, peste oglindă.
     * @return list<string>
     */
    public static function ocupateDin(mixed $v): array
    {
        if (!is_string($v) || $v === '') {
            return [];
        }
        $z = array_values(array_unique(array_filter(explode(',', $v), static fn (string $x): bool => (bool) preg_match('~^\d{4}-\d{2}-\d{2}$~', $x))));
        sort($z);
        return array_slice($z, 0, 31);
    }

    /** Două luni de calendar pornind de la $luna (AAAA-LL); folosit și de /_api/calendar. */
    public function luni(string $camera, string $luna, int $oaspeti): array
    {
        $azi = $this->app->azi();
        $start = $luna . '-01';
        $t0 = strtotime($start . ' 00:00:00 UTC');
        $sfarsit = gmdate('Y-m-t', strtotime('+1 month', $t0));
        $cuPreturi = $this->app->ecLicenta()->stare()['valida'];
        $zile = $cuPreturi ? $this->app->pretNoapte()->calendar($camera, $start, $sfarsit, $oaspeti, $azi) : [];
        $dupaZi = [];
        foreach ($zile as $z) {
            $dupaZi[$z['zi']] = $z;
        }
        $rez = [];
        for ($k = 0; $k < 2; $k++) {
            $tl = strtotime('+' . $k . ' month', $t0);
            $an = (int) gmdate('Y', $tl);
            $ln = (int) gmdate('n', $tl);
            $nrZile = (int) gmdate('t', $tl);
            $primaZi = (int) gmdate('N', $tl);
            $saptamani = [];
            $sapt = array_fill(0, $primaZi - 1, null);
            for ($d = 1; $d <= $nrZile; $d++) {
                $zi = sprintf('%04d-%02d-%02d', $an, $ln, $d);
                $x = $dupaZi[$zi] ?? ['zi' => $zi, 'stare' => $zi < $azi ? 'trecuta' : 'necunoscuta', 'pret' => null, 'sejur_minim' => 1, 'sosire_inchisa' => false, 'plecare_inchisa' => false];
                $x['acum'] = in_array($zi, $this->ocupateAcum, true) && $zi >= $azi;
                if ($x['acum']) {
                    $x['stare'] = 'ocupata';
                }
                // oaspetele vede doar „liberă” și „indisponibilă”; starea de lucru (data-stare) rămâne cea reală.
                $x['vizibil'] = self::stareVizibila($x['stare']);
                $sapt[] = $x + ['numar' => $d, 'eticheta' => $this->etichetaZi($x), 'pret_scurt' => $x['pret'] !== null ? self::leiScurt($x['pret'], $this->limba) : ''];
                if (count($sapt) === 7) {
                    $saptamani[] = $sapt;
                    $sapt = [];
                }
            }
            if ($sapt !== []) {
                $saptamani[] = array_pad($sapt, 7, null);
            }
            $rez[] = ['id' => sprintf('%04d-%02d', $an, $ln), 'titlu' => Format::luna($ln, $this->limba) . ' ' . $an, 'saptamani' => $saptamani];
        }
        return $rez;
    }

    /**
     * Starea arătată oaspetelui: „liberă”, „indisponibilă” (ocupată, închisă pentru rezervări, fără preț
     * sau încă necitită) ori ziua trecută.
     */
    public static function stareVizibila(string $stare): string
    {
        return in_array($stare, ['libera', 'trecuta'], true) ? $stare : 'indisponibila';
    }

    /** „20 decembrie 2026, liberă, 480 lei, minimum 2 nopți” (19). */
    public function etichetaZi(array $z): string
    {
        $p = [Format::ziLunga($z['zi'], $this->limba), $this->t->t('calendar.stare.' . self::stareVizibila((string) $z['stare']))];
        if ($z['pret'] !== null) {
            // Aceeași formă ca textul vizibil din celulă (WCAG 2.5.3, eticheta conține textul afișat).
            $p[] = self::leiScurt($z['pret'], $this->limba);
        }
        if ($z['stare'] === 'libera' && $z['sejur_minim'] > 1) {
            $p[] = $this->t->t('calendar.minim', ['nopti' => $this->t->plural('unitate.nopti', $z['sejur_minim'])]);
        }
        if (!empty($z['sosire_inchisa'])) {
            $p[] = $this->t->t('calendar.sosire_inchisa');
        }
        return implode(', ', $p);
    }

    /** 480 lei / RON 480 (fără zecimale la sume întregi), pentru celulele calendarului. */
    public static function leiScurt(int $bani, string $limba): string
    {
        return Format::baniOaspete($bani, $limba);
    }

    private function zileSaptamana(): array
    {
        $r = [];
        for ($i = 1; $i <= 7; $i++) {
            $r[] = ['scurt' => $this->t->t('calendar.zi_scurt.' . $i), 'lung' => $this->t->t('calendar.zi.' . $i)];
        }
        return $r;
    }

    /** Rezumatul camerei: formularul (date, oaspeți, plan), planurile cu politica de anulare și totalul cererii. */
    public function rezumat(?array $camera): array
    {
        $id = $camera['id'] ?? '';
        $pn = $this->app->pretNoapte();
        // Formularul cere doar datele și oaspeții: tarifele apar lângă total, câte unul pe politică de anulare și masă.
        $planuri = [];
        $c = $this->cerere !== null && ($this->cerere->camere[0]['camera'] ?? null) === $id ? $this->cerere : null;
        $planAles = $c?->planCamera(0) ?? $pn->planImplicit($id);
        $total = null;
        if ($c !== null) {
            $total = $this->oferte($c);
        }
        return [
            'camera' => $id,
            'formular' => $this->formular($camera !== null ? self::caleCamera($camera['url'], $this->app) : '/'),
            'api' => $this->app->url()->cale('/_api/pret'),
            'sosire' => $c?->sosire ?? '',
            'plecare' => $c?->plecare ?? '',
            'adulti' => $c !== null ? $c->camere[0]['adulti'] : 2,
            'copii' => $c !== null ? $c->camere[0]['copii'] : 0,
            'adulti_optiuni' => range(1, max(1, min(self::OASPETI_MAX, (int) ($camera['oaspeti_max'] ?? self::OASPETI_MAX)))),
            'copii_optiuni' => range(0, min(10, max(0, min(self::OASPETI_MAX, (int) ($camera['oaspeti_max'] ?? self::OASPETI_MAX)) - 1))),
            'planuri' => $planuri,
            'plan' => $planAles,
            'total' => $total,
            'activa' => $this->rezervariActive(),
            'oprite' => $this->app->rezervariOprite(),
            'contact' => $this->model->proprietate()['contact'],
        ];
    }

    /**
     * Totalul unui sejur, pregătit pentru afișare (rezumat, căutare, /_api/pret): suma, componentele la un clic,
     * mesajul restricției sau al nopților ocupate, „Vedeți totalul” peste plafonul bugetului.
     */
    public function rezultatTotal(Sejur $s, ?array $camera = null): array
    {
        $lung = $this->mesajPreaLung($s->sosire, $s->plecare);
        if ($lung !== null) {
            $n = $s->numarNopti();
            return ['nopti' => $n, 'nopti_text' => $this->t->plural('unitate.nopti', $n), 'sejur' => $s->query(), 'stare' => 'restrictie', 'mesaj' => $lung];
        }
        $pline = $this->app->pretNoapte()->noptiPline(array_column($s->camere, 'camera'), $s->sosire, $s->plecare);
        $n = $s->numarNopti();
        $baza = ['nopti' => $n, 'nopti_text' => $this->t->plural('unitate.nopti', $n), 'sejur' => $s->query()];
        if (!$this->app->ecLicenta()->stare()['valida']) {
            return $baza + ['stare' => 'indisponibil', 'mesaj' => $this->t->t('rezumat.contact_gazda')];
        }
        if ($pline !== []) {
            return $baza + ['stare' => 'ocupat', 'mesaj' => $this->t->t('rezumat.restrictie.ocupat', ['zile' => $this->zile($pline)]), 'zile' => $pline]
                + $this->alternative($s);
        }
        // tariful nerambursabil fără nicio metodă plătită la rezervare lipsește de pe site; cerut direct (o legătură
        // mai veche) și fără alt tarif pentru aceste date, rămâne contactul gazdei, fără preț.
        if ($this->app->oferte()->ascuns($s->planCamera(0), $s->sosire)) {
            return $baza + ['stare' => 'indisponibil', 'mesaj' => $this->t->t('rezumat.contact_gazda')];
        }
        $r = $this->app->total()->unul($s, $this->ip24);
        if ($r['stare'] === 'ok') {
            $comp = [];
            foreach (['nopti', 'taxe', 'taxa_locala'] as $k) {
                if (($r['componente'][$k] ?? null) !== null && ($k === 'nopti' || $r['componente'][$k] > 0)) {
                    $comp[] = ['cheie' => $k, 'eticheta' => $this->t->t('rezumat.comp.' . $k), 'suma' => $r['componente'][$k], 'text' => Format::baniOaspete($r['componente'][$k], $this->limba)];
                }
            }
            return $baza + [
                'stare' => 'ok',
                'total' => $r['total'],
                'total_text' => Format::baniOaspete($r['total'], $this->limba),
                'titlu' => $this->t->t('rezumat.total', ['nopti' => $baza['nopti_text'], 'suma' => Format::baniOaspete($r['total'], $this->limba)]),
                'componente' => $comp,
                'sursa' => $r['sursa'],
                // Comanda, cu sejurul precompletat (deeplinkul de rezervare); doar cât rezervările merg.
                'rezerva_url' => $this->app->rezervariActive() ? $this->app->url()->cale('/rezerva', $this->limba, $s->query()) : null,
                'rezerva_text' => $this->t->t('comanda.continua'),
                // Echivalentul orientativ în euro, doar cu gazda care l-a pornit și cu un curs BNR recent.
                'euro' => $this->app->echivalentEuro()->text((int) $r['total'], $this->t, $this->limba),
                // Ce rămâne în afara totalului și se plătește separat, la sosire, spus lângă preț.
                'separat' => $this->separat($s),
            ];
        }
        if ($r['stare'] === 'restrictie') {
            $motiv = (string) ($r['restrictie']['motiv'] ?? '');
            return $baza + ['stare' => 'restrictie', 'mesaj' => $this->mesajRestrictie($r['restrictie']), 'motiv' => $motiv]
                + (in_array($motiv, self::MOTIVE_ALTERNATIVE, true) ? $this->alternative($s) : []);
        }
        if ($r['stare'] === 'peste_buget') {
            return $baza + ['stare' => 'peste_buget', 'mesaj' => $this->t->t('rezumat.vedeti_totalul')];
        }
        return $baza + ['stare' => 'indisponibil', 'mesaj' => $this->t->t('rezumat.indisponibil')];
    }

    /**
     * Rândurile de lângă preț despre ce plătește oaspetele separat, la sosire: taxa locală și taxele
     * suplimentare lăsate de gazdă în afara totalului, cu suma când se știe. @return list<string>
     */
    public function separat(Sejur $s): array
    {
        $r = [];
        foreach ($this->app->inAfaraTotalului($s) as $k => $suma) {
            $r[] = $suma !== null
                ? $this->t->t('rezumat.separat.' . $k . '_suma', ['suma' => Format::baniOaspete($suma, $this->limba)])
                : $this->t->t('rezumat.separat.' . $k);
        }
        return $r;
    }

    /** Restricțiile la care oaspetele primește și cele mai apropiate date libere. */
    public const MOTIVE_ALTERNATIVE = ['inchis', 'fara_pret', 'sosire_inchisa', 'plecare_inchisa', 'sejur_minim', 'sejur_minim_prin'];
    /** Câte variante, cât de departe se caută (zile, înainte și după check-in-ul cerut). */
    public const ALTERNATIVE = 3;
    public const ALTERNATIVE_ZILE = 45;

    /**
     * Cele mai apropiate date libere pentru același număr de nopți, la o singură cameră: perioadele în care
     * toate nopțile sunt libere în oglindă (calendarul camerei, starea `libera`), cu check-in permis în prima zi,
     * check-out permis în ultima și sejurul minim respectat. Se aleg cele mai apropiate de check-in-ul cerut (la egalitate,
     * întâi cea de după), arătate în ordinea datelor. Fiecare variantă vine cu textul ei și cu adresa paginii camerei (merge și fără JavaScript);
     * verificarea finală a prețului și a restricțiilor o face tot calculul obișnuit, la clic.
     * @return array{alternative?: list<array{sosire:string, plecare:string, text:string, url:string}>, alternative_titlu?: string}
     */
    public function alternative(Sejur $s): array
    {
        if (count($s->camere) !== 1) {
            return [];
        }
        $cam = $s->camere[0];
        $n = $s->numarNopti();
        $azi = $this->app->azi();
        $t0 = strtotime($s->sosire . ' 00:00:00 UTC');
        $de = max($azi, gmdate('Y-m-d', $t0 - self::ALTERNATIVE_ZILE * 86400));
        $pana = gmdate('Y-m-d', $t0 + (self::ALTERNATIVE_ZILE + $n) * 86400);
        $zile = [];
        foreach ($this->app->pretNoapte()->calendar((string) $cam['camera'], $de, $pana, (int) $cam['adulti'] + (int) $cam['copii'], $azi) as $z) {
            $zile[$z['zi']] = $z;
        }
        $poate = static function (string $start) use ($zile, $n): bool {
            $z0 = $zile[$start] ?? null;
            if ($z0 === null || $z0['sosire_inchisa'] || (int) $z0['sejur_minim'] > $n) {
                return false;
            }
            $t = strtotime($start . ' 00:00:00 UTC');
            for ($k = 0; $k < $n; $k++) {
                if ((($zile[gmdate('Y-m-d', $t + $k * 86400)] ?? null)['stare'] ?? '') !== 'libera') {
                    return false;
                }
            }
            $ultima = $zile[gmdate('Y-m-d', $t + $n * 86400)] ?? null;
            return $ultima === null || !$ultima['plecare_inchisa'];
        };
        $gasite = [];
        for ($d = 1; $d <= self::ALTERNATIVE_ZILE && count($gasite) < self::ALTERNATIVE; $d++) {
            foreach ([$d, -$d] as $x) {
                $start = gmdate('Y-m-d', $t0 + $x * 86400);
                if ($start >= $azi && count($gasite) < self::ALTERNATIVE && $poate($start)) {
                    $gasite[] = $start;
                }
            }
        }
        if ($gasite === []) {
            return [];
        }
        sort($gasite);
        $camera = $this->model->cameraDupaId((string) $cam['camera']);
        $cale = $camera !== null ? self::caleCamera((string) $camera['url'], $this->app) : '/';
        $rez = [];
        foreach ($gasite as $start) {
            $plecare = gmdate('Y-m-d', strtotime($start . ' 00:00:00 UTC') + $n * 86400);
            $rez[] = ['sosire' => $start, 'plecare' => $plecare, 'text' => Format::interval($start, $plecare, $this->limba),
                'url' => $this->app->url()->cale($cale, $this->limba, ['sosire' => $start, 'plecare' => $plecare, 'adulti' => (string) $cam['adulti'],
                    'copii' => (string) $cam['copii'], 'camera' => (string) $cam['camera']])];
        }
        return ['alternative' => $rez, 'alternative_titlu' => $this->t->t('rezumat.alternative', ['n' => $this->t->plural('unitate.nopti', $n)])];
    }

    /** Mesajul unei restricții, cu aceleași motive ca API-ul EasyCheck. */
    /** Mesajul pentru un sejur peste durata maximă de pe site, înaintea oricărei verificări de preț; altfel null. */
    public function mesajPreaLung(string $sosire, string $plecare): ?string
    {
        $max = $this->app->noptiMaxSite();
        return Sejur::nopti($sosire, $plecare) > $max ? $this->mesajRestrictie(['motiv' => 'sejur_maxim_site', 'valoare' => $max]) : null;
    }

    public function mesajRestrictie(array $r): string
    {
        $m = (string) ($r['motiv'] ?? '');
        $v = (int) ($r['valoare'] ?? 0);
        $zile = array_values(array_filter((array) ($r['zile'] ?? []), 'is_string'));
        return match ($m) {
            'sejur_minim', 'sejur_maxim' => $this->t->t('rezumat.restrictie.' . $m, ['n' => $this->t->plural('unitate.nopti', $v)]),
            'sejur_minim_prin' => $this->t->t('rezumat.restrictie.sejur_minim_prin', ['n' => $this->t->plural('unitate.nopti', $v), 'data' => $zile !== [] ? Format::zi($zile[0], $this->limba) : '']),
            'sosire_inchisa', 'plecare_inchisa' => $this->t->t('rezumat.restrictie.' . $m, ['data' => $zile !== [] ? Format::zi($zile[0], $this->limba) : '']),
            'inchis', 'fara_pret' => $this->t->t('rezumat.restrictie.' . $m, ['zile' => $this->zile($zile)]),
            'oaspeti_max' => $this->t->t('rezumat.restrictie.oaspeti_max', ['n' => $this->t->plural('unitate.oaspeti', $v)]),
            // Durata maximă pe site: oaspetele află cum ne contactează pentru un sejur mai lung.
            'sejur_maxim_site' => $this->t->cuContact('rezumat.restrictie.sejur_maxim_site', \Cazo\Controlere\Rezerva::contactText($this->app), ['n' => $this->t->plural('unitate.nopti', $v)]),
            default => $this->t->t('rezumat.restrictie.alta'),
        };
    }

    /**
     * Politica de anulare arătată oaspetelui lângă preț, aceeași pe toate paginile și în e-mailuri:
     * regula care i se aplică. Politica planului privește banii plătiți la rezervare (cu cardul sau prin transfer);
     * rezervarea cu plata la sosire se anulează gratuit până în ziua sosirii. De aceea:
     *  - $metode null (listele de tarife ale temelor, fără date alese): politica planului;
     *  - doar plata la sosire (singura metodă oferită sau cea aleasă): „Anulare gratuită până în ziua sosirii.”;
     *  - plata la sosire lângă o plată la rezervare: politica planului, apoi regula plății la sosire;
     *  - doar plăți la rezervare: politica planului.
     * @param array{card?:bool, transfer?:bool, cash?:bool}|null $metode metodele oferite (ModPlata::pentru) sau cea aleasă
     */
    public function politicaAnulare(array $plan, ?array $metode = null): string
    {
        $plan = $this->politicaPlan($plan);
        if ($metode === null || empty($metode['cash'])) {
            return $plan;
        }
        $sosire = $this->t->t('anulare.pana_la_sosire');
        if (empty($metode['card']) && empty($metode['transfer'])) {
            return $sosire;
        }
        return $plan === $sosire ? $plan : $plan . ' ' . $this->t->t('anulare.cu_plata_sosire');
    }

    /** Politica de anulare a planului, cum vine din EasyCheck: nerambursabil, la cerere, până în ziua sosirii sau cu N zile înainte. */
    private function politicaPlan(array $plan): string
    {
        if (($plan['tip'] ?? null) === 'nerambursabil') {
            return $this->t->t('anulare.nerambursabil');
        }
        $z = $plan['anulare_zile'] ?? null;
        if ($z === null || $z === '') {
            return $this->t->t('anulare.la_cerere');
        }
        $z = (int) $z;
        return $z === 0 ? $this->t->t('anulare.pana_la_sosire') : $this->t->t('anulare.gratuita', ['n' => $this->t->plural('unitate.zile', $z)]);
    }

    /**
     * Metodele de plată oferite acum pentru un plan și o zi de sosire, pentru politica de anulare de lângă preț
     *; null cât site-ul nu primește rezervări (rămâne politica planului).
     * @return array{card:bool, transfer:bool, cash:bool}|null
     */
    public function metodePlan(array $plan, string $sosire): ?array
    {
        if (!$this->app->rezervariActive()) {
            return null;
        }
        $m = $this->app->modPlata()->pentru($plan, $sosire);
        return ['card' => $m['card'], 'transfer' => $m['transfer'], 'cash' => $m['cash']];
    }

    /** Numele tarifului într-un singur text (rezumatul comenzii, e-mailurile): numele și, când o are, nota lui. */
    public static function titluPlan(array $p, string $limba, Aplicatie $app): string
    {
        $n = self::numePlan($p, $limba, $app);
        return $n['nota'] !== '' ? $n['titlu'] . ', ' . $n['nota'] : $n['titlu'];
    }

    /**
     * Masa inclusă în prețul planului (EasyCheck 7.550.142), în limba paginii: eticheta din EasyCheck
     * (`masa_eticheta` în română, `masa_eticheta_en` în rest), altfel eticheta nucleului pentru cod. Fără masă (`fara`),
     * cu un EasyCheck mai vechi (fără câmp) sau cu un cod necunoscut: textul gol, deci temele nu arată nimic.
     */
    public static function masaPlan(array $p, string $limba, Traducator $t): string
    {
        $cod = $p['masa'] ?? null;
        if (!in_array($cod, \Cazo\EasyCheck\Proiectii::MESE, true)) {
            return '';
        }
        $e = $p[$limba === 'ro' ? 'masa_eticheta' : 'masa_eticheta_en'] ?? null;
        return is_string($e) && trim($e) !== '' ? trim($e) : $t->t('tarif.masa.' . $cod);
    }

    /**
     * „Doar cazare” lângă tarifele fără masă (23.11.2026), doar într-o listă în care cel
     * puțin un tarif are masa inclusă; fără nicio masă în listă, lista rămâne neschimbată (nicio etichetă).
     * $cheie e câmpul cu eticheta mesei, gol fără masă.
     * @param list<array<string,mixed>> $lista
     * @return list<array<string,mixed>>
     */
    public static function cuDoarCazare(array $lista, string $cheie, Traducator $t): array
    {
        $cuMasa = array_filter($lista, static fn (array $x): bool => ($x[$cheie] ?? '') !== '');
        if ($cuMasa === []) {
            return $lista;
        }
        foreach ($lista as $i => $x) {
            if (($x[$cheie] ?? '') === '') {
                $lista[$i][$cheie] = $t->t('tarif.masa.fara');
            }
        }
        return $lista;
    }

    /** Măcar un tarif de pe site (fără cele ascunse mereu) are masa inclusă. */
    public static function siteCuMasa(Aplicatie $app): bool
    {
        foreach ($app->pretNoapte()->planuri() as $p) {
            if (in_array($p['masa'] ?? null, \Cazo\EasyCheck\Proiectii::MESE, true) && !$app->modPlata()->ascunsMereu($p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Titlul tarifului cu masa inclusă, pentru rezumatul rezervării și e-mailuri: „Tarif standard, mic dejun inclus”;
     * fără masă, „Tarif standard, doar cazare” când alt tarif de pe site are masa, altfel doar titlul.
     */
    public static function titluCuMasa(array $p, string $limba, Aplicatie $app): string
    {
        $titlu = self::titluPlan($p, $limba, $app);
        $masa = self::masaPlan($p, $limba, $app->traducator($limba));
        if ($masa === '' && self::siteCuMasa($app)) {
            $masa = $app->traducator($limba)->t('tarif.masa.fara');
        }
        return $masa === '' ? $titlu : $titlu . ', ' . mb_strtolower(mb_substr($masa, 0, 1)) . mb_substr($masa, 1);
    }

    /**
     * Numele tarifului pe înțelesul oaspetelui, în limba paginii: titlul scris de gazdă în panou
     * pentru limba aceea, când există; altfel „Tarif standard” pentru planul Standard, „Tarif standard” cu nota
     * „cu reducerea pentru sejururi de la N nopți” pentru un tarif de sejur lung, aceeași formă cu nota rezervării din
     * timp pentru tariful `din_timp` (modul fără cheie), „Tarif nerambursabil” pentru cel
     * nerambursabil; un alt plan al gazdei își păstrează titlul din EasyCheck (scris de ea, într-o singură limbă).
     * @return array{titlu:string, nota:string}
     */
    public static function numePlan(array $p, string $limba, Aplicatie $app): array
    {
        $local = $app->baza()->valoare('SELECT titlu_local FROM {planuri_local} WHERE plan = ?', [$p['id']]);
        $l = is_string($local) ? (json_decode($local, true)[$limba] ?? null) : null;
        if (is_string($l) && $l !== '') {
            return ['titlu' => $l, 'nota' => ''];
        }
        $t = $app->traducator($limba);
        $tip = $p['tip'] ?? null;
        if ($tip === 'nerambursabil') {
            return ['titlu' => $t->t('tarif.nerambursabil'), 'nota' => ''];
        }
        if ($tip === 'sejur_lung') {
            $prag = (int) ($p['prag_nopti'] ?? 0);
            return ['titlu' => $t->t('tarif.standard'), 'nota' => $prag > 1 ? $t->t('tarif.nota_sejur_lung', ['n' => $t->plural('unitate.nopti', $prag)]) : $t->t('tarif.nota_sejur')];
        }
        if ($tip === 'din_timp') {
            // în modul fără cheie, pragul în zile e setarea proprietății, aceeași din care s-a făcut tariful.
            $zile = $app->modLocal() ? (int) ($app->localDepozit()->reduceri()['timp']['zile'] ?? 0) : 0;
            return ['titlu' => $t->t('tarif.standard'), 'nota' => $zile > 0 ? $t->t('tarif.nota_din_timp', ['n' => $t->plural('unitate.zile', $zile)]) : $t->t('tarif.nota_timp')];
        }
        if (($p['id'] ?? null) === \Cazo\Pret\Oferte::STANDARD) {
            return ['titlu' => $t->t('tarif.standard'), 'nota' => ''];
        }
        return ['titlu' => (string) $p['titlu'], 'nota' => ''];
    }

    /**
     * Tarifele pentru datele alese: totalul celui mai bun tarif pe fiecare politică de anulare și masă, cu numele
     * pe înțelesul oaspetelui. Primul nivel al rezultatului e primul tarif (cel cu anulare), iar `oferte` le are pe
     * toate; când niciun tarif nu se aplică datelor, rezultatul e mesajul restricției tarifului afișat al camerei.
     */
    public function oferte(Sejur $s): array
    {
        $pn = $this->app->pretNoapte();
        $toate = $pn->planuri();
        $lista = [];
        $cuPret = $this->app->ecLicenta()->stare()['valida'] && $this->mesajPreaLung($s->sosire, $s->plecare) === null
            && $pn->noptiPline(array_column($s->camere, 'camera'), $s->sosire, $s->plecare) === [];
        foreach ($cuPret ? $this->app->oferte()->alese($s) : [] as $a) {
            $r = $this->rezultatTotal($s->cuPlan($a['plan']));
            $nume = self::numePlan($toate[$a['plan']], $this->limba, $this->app);
            $lista[] = $r + ['plan' => $a['plan'], 'tarif' => $nume['titlu'], 'tarif_nota' => $nume['nota'], 'masa' => self::masaPlan($toate[$a['plan']], $this->limba, $this->t),
                'politica' => $this->politicaAnulare($toate[$a['plan']], $this->metodePlan($toate[$a['plan']], $s->sosire))];
        }
        $lista = self::cuDoarCazare($lista, 'masa', $this->t);
        if ($lista === []) {
            return $this->rezultatTotal($s->planCamera(0) !== null ? $s : $s->cuPlan($pn->planImplicit($s->camere[0]['camera'])));
        }
        return $lista[0] + ['oferte' => $lista];
    }

    private function zile(array $zile): string
    {
        $f = array_map(fn (string $z): string => Format::zi($z, $this->limba), array_slice($zile, 0, 5));
        return implode(', ', $f) . (count($zile) > 5 ? ' …' : '');
    }

    /** Calea logică a camerei din adresa ei (fără prefixul limbii și fără ?r=). */
    private static function caleCamera(string $url, Aplicatie $app): string
    {
        if (str_starts_with($url, '/?')) {
            parse_str(substr($url, 2), $q);
            $url = (string) ($q['r'] ?? '/');
        }
        $url = rawurldecode($url);
        return (string) preg_replace('~^/en(?=/)~', '', $url);
    }

    /**
     * Harta: adresa, coordonatele clădirii, serverul de plăci și legătura spre aplicația de hărți. Pe paginile
     * publice, adresa clădirii (fără scară, etaj și apartament); $completa, pentru „Cum ajungeți” de după
     * rezervare, dă adresa întreagă din EasyCheck.
     */
    public function harta(bool $completa = false): array
    {
        $p = $this->model->proprietate();
        $a = $p['adresa'];
        $text = $completa
            ? \Cazo\Legal\Adresa::text((array) ($this->app->ecOglinda()->corp('proprietate')['adresa'] ?? []), true)
            : implode(', ', array_filter([$a['strada'], $a['localitate'], $a['judet']], static fn ($x): bool => $x !== ''));
        $c = $p['coordonate'];
        $placi = (string) $this->app->setari()->get('harta.placi', 'https://tile.openstreetmap.org');
        return [
            'adresa' => $text,
            'nume' => $p['nume'],
            'lat' => $c['lat'] ?? null,
            'lng' => $c['lng'] ?? null,
            'placi' => rtrim($placi, '/') . '/{z}/{x}/{y}.png',
            'geo' => $c !== null ? 'geo:' . $c['lat'] . ',' . $c['lng'] . '?q=' . rawurlencode($text) : null,
        ];
    }

    /**
     * Vremea pentru temă: temperatura, cerul, 3 zile, răsăritul și apusul de azi, sursa cerută de licență;
     * null când gazda a oprit-o sau când datele au peste 3 ore.
     */
    public function vreme(): ?array
    {
        try {
            $d = $this->app->vreme()->date();
        } catch (\Throwable) {
            return null;
        }
        if ($d === null) {
            return null;
        }
        $zile = [];
        foreach ((array) ($d['zile'] ?? []) as $z) {
            $stare = in_array($z['stare'] ?? null, \Cazo\DateExterne\Vreme::STARI, true) ? $z['stare'] : 'noros';
            $zile[] = ['zi' => $z['zi'], 'eticheta' => Format::zi((string) $z['zi'], $this->limba), 'min' => (int) $z['min'], 'max' => (int) $z['max'],
                'stare' => $stare, 'text' => $this->t->t('vreme.stare.' . $stare)];
        }
        $stare = $d['stare'];
        $soare = $this->soare();
        return [
            'temperatura' => (int) $d['temperatura'],
            'stare' => $stare,
            'text' => $this->t->t('vreme.stare.' . $stare),
            'zile' => $zile,
            'rasarit' => $soare['rasarit'] ?? null,
            'apus' => $soare['apus'] ?? null,
            'sursa' => $this->t->t('vreme.sursa', ['sursa' => \Cazo\DateExterne\Vreme::SURSA]),
            'sursa_url' => \Cazo\DateExterne\Vreme::SURSA_URL,
        ];
    }

    /** Răsăritul și apusul de azi, calculate din coordonatele proprietății, sau null fără coordonate. */
    public function soare(): ?array
    {
        $c = $this->model->proprietate()['coordonate'] ?? null;
        return is_array($c) && is_numeric($c['lat'] ?? null) ? \Cazo\DateExterne\Soare::ore((float) $c['lat'], (float) $c['lng'], $this->app->azi()) : null;
    }

    /** Punctele de reper pentru temă și atribuirea OpenStreetMap, cât lista are un reper luat de acolo. */
    public function repere(): array
    {
        try {
            $r = $this->app->repere();
            return ['repere' => $r->pentruTema($this->limba), 'atribuire' => $r->auOsm() ? $this->t->t('repere.atribuire', ['sursa' => \Cazo\DateExterne\Repere::ATRIBUIRE]) : ''];
        } catch (\Throwable) {
            return ['repere' => [], 'atribuire' => ''];
        }
    }

    /** Subsolul editat de gazdă din panou: contactul și blocul legal, cu aceleași date ca modelul; null pe model. */
    private function subsol(): ?array
    {
        $this->subsol ??= [(new \Cazo\Legal\Editabile($this->app))->subsol($this->limba, $this->contact(), (new BlocLegal($this->app, $this->t, $this->limba))->date())];
        return $this->subsol[0];
    }

    /** @var array{0:?array}|null */
    private ?array $subsol = null;

    public function contact(): array
    {
        $p = $this->model->proprietate();
        $tip = match ($p['tip']) {
            'hotel' => 'Hotel',
            'pensiune', 'pensiune_agroturistica', 'casa_oaspeti' => 'BedAndBreakfast',
            default => 'LodgingBusiness',
        };
        // La persoana fizică, adresa rămâne în afara contactului din subsol.
        $adresa = BlocLegal::persoanaFizica($this->app) ? ['strada' => '', 'localitate' => '', 'judet' => ''] : $p['adresa'];
        return ['nume' => $p['nume'], 'tip_schema' => $tip, 'adresa' => $adresa, 'telefon' => $p['contact']['telefon'], 'email' => $p['contact']['email'],
            'url' => $this->app->config()->origine() . '/'];
    }
}
