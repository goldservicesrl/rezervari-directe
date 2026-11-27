<?php

declare(strict_types=1);

namespace Cazo\Legal;

use Cazo\I18n\Traducator;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cai;

defined('CAZO') or exit;

/**
 * Blocul legal: datele operatorului din GET /proprietate (`operator`, Ordinul MEDAT 948/2026),
 * completate în `date_legale` unde API-ul le lasă goale (API-ul are prioritate), contactul, linkurile spre paginile
 * legale și pictograma SAL spre reclamatiisal.anpc.ro. E al nucleului: tema îl poziționează, fără să-l schimbe.
 * Câmpurile lipsă rămân deoparte pe site; panoul arată alerta. Fără datele cerute înaintea contractului la distanță
 * (VANZARE: denumirea, CUI, sediul, contactul; OUG 34/2014), site-ul arată doar prezentarea, fără nicio rezervare
 * (Aplicatie::configurareLipsa).
 *
 * Gazda persoană fizică: rezervările merg cu numele (sau denumirea comercială) și contactul, iar adresa ei
 * rămâne în afara site-ului (blocul legal, pagina Date legale, precontractualul, contractul trimis pe e-mail). Tipul
 * gazdei: un cod fiscal primit de la EasyCheck înseamnă firmă; altfel decide forma de organizare: cea trimisă de EasyCheck
 * (`operator.forma`) sau, fără ea, cea aleasă în instalator ori în panou (`legal.forma`). EasyCheck trimite fără cod fiscal, registru și sediu și o gazdă care și-a lăsat datele de facturare
 * necompletate, deci lipsa lor singură rămâne neconcludentă.
 *
 * Clasificarea: fiecare document din `operator.clasificari` e un rând,
 * cu tipul structurii și categoria (stele, iar la tipurile agroturistice flori), felul și numărul documentului și, când
 * documentul cuprinde doar unele camere, camerele lui; câmpurile null se omit. Fără listă (un EasyCheck mai vechi sau
 * niciun document), rândul rămâne numărul certificatului (`certificat_clasificare`, din API sau completat local).
 */
final class BlocLegal
{
    public const CAMPURI = ['denumire', 'cui', 'registrul_comertului', 'sediu', 'sediu_tip', 'capital_social', 'certificat_clasificare'];

    /**
     * Câmpurile obligatorii: alerta din panou și asumarea la publicare, când lipsesc. `contact` (e-mailul sau
     * telefonul) vine din EasyCheck (`GET /proprietate` → `contact`), nu din `date_legale`.
     */
    public const OBLIGATORII = ['denumire', 'cui', 'sediu', 'contact', 'certificat_clasificare'];

    /**
     * Datele fără de care site-ul nu primește rezervări: identitatea, adresa și contactul
     * comerciantului, cerute de OUG 34/2014 înaintea contractului la distanță.
     */
    public const VANZARE = ['denumire', 'cui', 'sediu', 'contact'];

    /** Datele care privesc doar firmele: la persoana fizică ies din liste și de pe site. */
    public const DOAR_FIRMA = ['cui', 'sediu', 'sediu_tip', 'registrul_comertului', 'capital_social'];

    /** Setarea paginii Accesibilitate: pornită doar pentru firmele peste pragul microîntreprinderii. */
    public const ACCESIBILITATE = 'legal.accesibilitate';

    public const SAL = 'https://reclamatiisal.anpc.ro/';

    /**
     * Linkul Cazo din subsol: „Software Regim Hotelier - Cazo.ro” / „Vacation Rental Software - Cazo.ro”,
     * spre pagina programului, pe fiecare pagină publică. E singura condiție a licenței Cazo, deci nu are comutator:
     * setarea veche care îl ascundea (`subsol.mentiune_easycheck`) nu se mai citește (migrarea 0050 o șterge).
     */
    public const MENTIUNE_EC_URL = 'https://cazo.ro/?utm_source=cazo';

    public function __construct(private Aplicatie $app, private Traducator $t, private string $limba)
    {
    }

    /** Formele de organizare cunoscute (instalatorul, panoul, `operator.forma` din EasyCheck). */
    public const FORME = ['srl', 'sa', 'pfa', 'ii', 'if', 'persoana_fizica', 'alta'];

    /** Codurile din `operator.forma` și forma site-ului pe care o înseamnă fiecare. */
    public const FORME_EASYCHECK = ['srl' => 'srl', 'sa' => 'sa', 'pfa' => 'pfa', 'ii' => 'ii', 'if' => 'if', 'pf' => 'persoana_fizica', 'alta' => 'alta'];

    /** Gazda e persoană fizică: fără cod fiscal din EasyCheck și cu forma „persoană fizică” (din EasyCheck sau aleasă local). */
    public static function persoanaFizica(Aplicatie $app, ?string $forma = null): bool
    {
        $cui = $app->ecOglinda()->corp('proprietate')['operator']['cui'] ?? null;
        if (is_string($cui) && trim($cui) !== '') {
            return false;
        }
        // $forma: forma aleasă și încă nesalvată (pasul datelor legale al instalatorului, întors cu o eroare).
        return ($forma ?? self::forma($app)) === 'persoana_fizica';
    }

    /**
     * Forma de organizare trimisă de EasyCheck: `operator.forma` din GET /proprietate (`srl`, `sa`, `pfa`,
     * `ii`, `if`, `pf`, `alta`; EasyCheck o ia din registrul ANAF sau din forma aleasă de gazdă), adusă la codul site-ului
     * (`pf` = `persoana_fizica`). Null când EasyCheck trimite null, lipsește (un EasyCheck mai vechi) sau are o valoare
     * necunoscută: atunci decide deducerea din denumire ori alegerea gazdei.
     */
    public static function formaEasyCheck(Aplicatie $app): ?string
    {
        $f = $app->ecOglinda()->corp('proprietate')['operator']['forma'] ?? null;
        return is_string($f) ? (self::FORME_EASYCHECK[$f] ?? null) : null;
    }

    /** Forma de organizare în vigoare: cea din EasyCheck (API-ul are întâietate, ca la celelalte date), altfel cea aleasă local. */
    public static function forma(Aplicatie $app): ?string
    {
        $f = self::formaEasyCheck($app) ?? $app->setari()->get('legal.forma');
        return is_string($f) && in_array($f, self::FORME, true) ? $f : null;
    }

    /**
     * Forma de organizare pe care o știm fără să întrebăm gazda și de unde: `easycheck` (câmpul din API) sau
     * `dedus` (din denumire și din numărul de la registru, din EasyCheck ori completate local). Null: de întrebat.
     * @return array{forma:string, sursa:string}|null
     */
    public static function formaCunoscuta(Aplicatie $app): ?array
    {
        $ec = self::formaEasyCheck($app);
        if ($ec !== null) {
            return ['forma' => $ec, 'sursa' => 'easycheck'];
        }
        $o = (array) ($app->ecOglinda()->corp('proprietate')['operator'] ?? []);
        foreach ($app->baza()->toate("SELECT camp, valoare FROM {date_legale} WHERE camp IN ('denumire', 'registrul_comertului')") as $r) {
            if (!is_string($o[$r['camp']] ?? null) || trim($o[$r['camp']]) === '') {
                $o[$r['camp']] = $r['valoare'];
            }
        }
        $f = self::formaDedusa(is_string($o['denumire'] ?? null) ? $o['denumire'] : '', is_string($o['registrul_comertului'] ?? null) ? $o['registrul_comertului'] : '');
        return $f === null ? null : ['forma' => $f, 'sursa' => 'dedus'];
    }

    /**
     * Forma de organizare dedusă din denumire și din numărul de la registrul comerțului: „SRL” / „S.R.L.”,
     * „PFA” / „P.F.A.” / „persoană fizică autorizată”, „întreprindere individuală” / „II” / „Î.I.”, „întreprindere
     * familială” / „IF” / „Î.F.”. Prescurtările de două litere („II”, „IF”) se pot citi și altfel (o cifră romană, un
     * cuvânt), deci contează doar cu un număr de registru care începe cu F (persoane fizice autorizate și
     * întreprinderi) ori, fără număr, când stau la capătul denumirii. Un număr care începe cu J (societăți) fără
     * niciun cuvânt cunoscut rămâne de întrebat: societatea poate fi SRL, SA sau altceva.
     */
    public static function formaDedusa(string $denumire, string $registru): ?string
    {
        $d = ' ' . mb_strtoupper(\Cazo\I18n\Format::diacritice(trim($denumire))) . ' ';
        $d = strtr($d, ['Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ț' => 'T']);
        $r = mb_strtoupper(trim($registru));
        if ($d === '  ') {
            return null;
        }
        if (preg_match('~[\s,.(\-]S\.?\s?R\.?\s?L\.?(-D)?[\s,.)]~u', $d)) {
            return 'srl';
        }
        if (preg_match('~[\s,.(\-]P\.?\s?F\.?\s?A\.?[\s,.)]|PERSOANA FIZICA AUTORIZATA~u', $d)) {
            return 'pfa';
        }
        if (str_contains($d, 'INTREPRINDERE INDIVIDUALA')) {
            return 'ii';
        }
        if (str_contains($d, 'INTREPRINDERE FAMILIALA')) {
            return 'if';
        }
        $firmaMica = str_starts_with($r, 'F');
        if ($firmaMica || $r === '') {
            // „Popescu Ion II”, „II Popescu Ion”, „Popescu I.I.”: la capetele denumirii, ori oriunde când registrul începe cu F.
            foreach (['ii' => 'I\.?\s?I\.?', 'if' => 'I\.?\s?F\.?'] as $forma => $x) {
                if (preg_match($firmaMica ? "~[\s,.(\-]{$x}[\s,.)]~u" : "~^\s{$x}\s|[\s,]{$x}\s$~u", $d)) {
                    return $forma;
                }
            }
        }
        return null;
    }

    /** Pagina Accesibilitate e publicată; implicit ascunsă, până când gazda o pornește. */
    public static function accesibilitate(Aplicatie $app): bool
    {
        return $app->setari()->get(self::ACCESIBILITATE, false) === true;
    }

    /** Paginile legale cu legătură pe site (Cai::LEGALE), fără Accesibilitate cât e ascunsă. @return list<string> */
    public static function legaturi(Aplicatie $app): array
    {
        return array_values(array_filter(Cai::LEGALE, static fn (string $n): bool => $n !== 'accesibilitate' || self::accesibilitate($app)));
    }

    /**
     * Adresa proprietății în textele legale (subsolul, Date legale, precontractualul, contractul): goală la persoana
     * fizică, al cărei loc de cazare e de obicei chiar adresa ei. Pe paginile publice e adresa clădirii, fără
     * scară, etaj și apartament; $completa o dă întreagă, pentru ce primește oaspetele după rezervare
     * (contractul din e-mailul de confirmare).
     */
    public function adresa(bool $completa = false): string
    {
        if (self::persoanaFizica($this->app)) {
            return '';
        }
        return Adresa::text((array) ($this->app->ecOglinda()->corp('proprietate')['adresa'] ?? []), $completa);
    }

    /** Datele operatorului, cu completările locale; la persoana fizică, fără datele de firmă. @return array<string,?string> */
    public function operator(): array
    {
        $api = (array) ($this->app->ecOglinda()->corp('proprietate')['operator'] ?? []);
        $local = [];
        foreach ($this->app->baza()->toate('SELECT camp, valoare FROM {date_legale}') as $r) {
            $local[$r['camp']] = $r['valoare'];
        }
        $r = [];
        foreach (self::CAMPURI as $c) {
            $v = $api[$c] ?? null;
            if (!is_string($v) || trim($v) === '') {
                $v = $local[$c] ?? null;
            }
            $r[$c] = is_string($v) && trim($v) !== '' ? trim($v) : null;
        }
        if (self::persoanaFizica($this->app)) {
            foreach (self::DOAR_FIRMA as $c) {
                $r[$c] = null;
            }
        }
        return $r;
    }

    /** Datele cerute de lege acestei gazde: la persoana fizică, fără cele care privesc doar firmele. @return list<string> */
    public static function obligatorii(Aplicatie $app, ?string $forma = null): array
    {
        return self::persoanaFizica($app, $forma) ? array_values(array_diff(self::OBLIGATORII, self::DOAR_FIRMA)) : self::OBLIGATORII;
    }

    /** @return list<string> câmpurile obligatorii lipsă (alerta din panou și asumarea la publicare) */
    public function lipsa(): array
    {
        return $this->lipsaDin(self::OBLIGATORII);
    }

    /** @return list<string> datele lipsă fără de care site-ul nu primește rezervări */
    public function lipsaVanzare(): array
    {
        return $this->lipsaDin(self::VANZARE);
    }

    /** @param list<string> $campuri @return list<string> */
    private function lipsaDin(array $campuri): array
    {
        if (self::persoanaFizica($this->app)) {
            $campuri = array_diff($campuri, self::DOAR_FIRMA);
        }
        $o = $this->operator();
        // O autorizație temporară (sau orice document din `clasificari`) ține locul certificatului în alertă.
        $o['certificat_clasificare'] ??= $this->documente() !== [] ? 'da' : null;
        $contact = (array) ($this->app->ecOglinda()->corp('proprietate')['contact'] ?? []);
        $o['contact'] = trim((string) ($contact['email'] ?? '')) !== '' || trim((string) ($contact['telefon'] ?? '')) !== '' ? 'da' : null;
        return array_values(array_filter($campuri, static fn (string $c): bool => $o[$c] === null));
    }

    public function date(): array
    {
        $o = $this->operator();
        $randuri = [];
        foreach (['denumire', 'cui', 'registrul_comertului', 'sediu', 'capital_social'] as $c) {
            if ($o[$c] === null) {
                continue;
            }
            $eticheta = $c === 'sediu' && $o['sediu_tip'] !== null ? $this->t->t('legal.sediu_' . $o['sediu_tip']) : $this->t->t('legal.' . $c);
            $randuri[] = ['eticheta' => $eticheta, 'valoare' => $c === 'capital_social' ? self::capital($o[$c], $this->t) : $o[$c]];
        }
        foreach ($this->clasificari() as $x) {
            $randuri[] = ['eticheta' => $this->t->t('legal.clasificare'), 'valoare' => $x];
        }
        // Subsolul: datele firmei pe un singur rând de text mic, despărțite prin „·”: denumirea, „CUI …”,
        // numărul de registru, „Sediul social: …”, „Capital social … lei”, clasificarea. Aceleași date ca `randuri`
        // (pagina Date legale și contractul le păstrează cu etichetele întregi).
        $firma = [];
        foreach (['denumire', 'cui', 'registrul_comertului', 'sediu', 'capital_social'] as $c) {
            if ($o[$c] === null) {
                continue;
            }
            $firma[] = match ($c) {
                'cui' => $this->t->t('legal.scurt.cui', ['cui' => $o[$c]]),
                'sediu' => $this->t->t('legal.scurt.sediu', ['eticheta' => $this->t->t($o['sediu_tip'] !== null ? 'legal.sediu_' . $o['sediu_tip'] : 'legal.sediu'), 'sediu' => $o[$c]]),
                'capital_social' => $this->t->t('legal.scurt.capital', ['suma' => self::capital($o[$c], $this->t)]),
                default => $o[$c],
            };
        }
        foreach ($this->clasificari() as $x) {
            $firma[] = $x;
        }
        // Blocul legal e public: telefonul gazdei apare doar la alegerea ei.
        $p = (array) ($this->app->ecOglinda()->proprietatePublica() ?? []);
        $linkuri = [];
        foreach (self::legaturi($this->app) as $n) {
            $linkuri[] = ['titlu' => $this->t->t('legal.link.' . $n), 'url' => Cai::legala($this->app->url(), $n, $this->limba)];
        }
        return [
            'randuri' => $randuri,
            'firma' => $firma,
            'linkuri' => $linkuri,
            'sal_url' => self::SAL,
            'sal_img' => $this->app->active()->nucleu()[self::SAL_IMAGINE] ?? '',
            'an' => (int) substr($this->app->azi(), 0, 4),
            'nume' => (string) ($p['nume'] ?? ($o['denumire'] ?? '')),
            'email' => (string) ($p['contact']['email'] ?? ''),
            'telefon' => (string) ($p['contact']['telefon'] ?? ''),
            'mentiune_ec' => ['url' => self::MENTIUNE_EC_URL, 'text' => $this->t->t('legal.mentiune_ec')],
        ];
    }

    /**
     * Pictograma SAL oficială (Ordinul ANPC 449/2022, modificat prin Ordinul 270/2026: o singură pictogramă, cu
     * legătura spre https://reclamatiisal.anpc.ro): fișierul de pe anpc.ro, neschimbat (evidența în front/img/anpc-sal.lock).
     */
    public const SAL_IMAGINE = 'anpc-sal.png';

    /**
     * Capitalul social cu moneda: o valoare scrisă doar în cifre („200”, „1.000”, „200,00”) primește
     * „lei”; una care are deja un cuvânt („200 lei”, „10000 EUR”) rămâne cum a scris-o gazda.
     */
    public static function capital(string $valoare, Traducator $t): string
    {
        $v = trim($valoare);
        return preg_match('~^\d[\d .,]*$~', $v) ? $t->t('legal.capital_valoare', ['suma' => $v]) : $v;
    }

    /** Documentele de clasificare din EasyCheck (`operator.clasificari`), cele cu număr. @return list<array> */
    public function documente(): array
    {
        $lista = $this->app->ecOglinda()->corp('proprietate')['operator']['clasificari'] ?? null;
        $r = [];
        foreach (is_array($lista) ? $lista : [] as $d) {
            if (is_array($d) && in_array($d['fel'] ?? null, ['certificat', 'autorizatie_temporara'], true) && is_scalar($d['numar'] ?? null) && trim((string) $d['numar']) !== '') {
                $r[] = $d;
            }
        }
        return $r;
    }

    /**
     * Rândurile clasificării, gata de afișat: „Pensiune turistică 3 stele, certificat de clasificare nr. 8821”,
     * „autorizație temporară nr. …”, cu „pentru {camere}” când documentul cuprinde doar unele camere. @return list<string>
     */
    public function clasificari(): array
    {
        $r = [];
        $camere = null;
        foreach ($this->documente() as $d) {
            $parti = [];
            $tip = is_string($d['tip_structura'] ?? null) ? trim($d['tip_structura']) : '';
            $cat = is_int($d['categorie'] ?? null) && $d['categorie'] >= 1 && $d['categorie'] <= 5 ? $d['categorie'] : null;
            $cap = $this->tipInLimba($d, $tip);
            if ($cat !== null) {
                // EasyCheck 7.550.133: pe paginile în altă limbă, categoria vine scrisă de EasyCheck (`categorie_en`: „3 stars”,
                // „2 flowers”, „category 3”); fără ea (un EasyCheck mai vechi), stelele sau florile se scriu după eticheta română.
                $sora = $this->limba !== 'ro' && preg_match('~^[a-z]{2}$~', $this->limba) ? ($d['categorie_' . $this->limba] ?? null) : null;
                $categorie = is_string($sora) && trim($sora) !== '' ? trim($sora)
                    : $this->t->plural(self::agroturistic($tip) ? 'legal.clasificare.flori' : 'legal.clasificare.stele', $cat);
                // Fără tip, categoria scrisă de EasyCheck („category 3”) stă singură; cea din catalog, după tip, ca până acum.
                $cap = trim($cap . ($cap !== '' && is_string($sora) && trim($sora) !== '' ? ', ' : ' ') . $categorie);
            }
            if ($cap !== '') {
                $parti[] = $cap;
            }
            $parti[] = $this->t->t($d['fel'] === 'autorizatie_temporara' ? 'legal.clasificare.autorizatie' : 'legal.clasificare.certificat', ['numar' => trim((string) $d['numar'])]);
            $ids = array_values(array_filter(is_array($d['camere'] ?? null) ? $d['camere'] : [], 'is_string'));
            if ($ids !== []) {
                $camere ??= $this->numeCamere();
                $nume = array_values(array_filter(array_map(static fn (string $id): ?string => $camere[$id] ?? null, $ids)));
                if ($nume !== []) {
                    $parti[] = $this->t->t('legal.clasificare.camere', ['camere' => implode(', ', $nume)]);
                }
            }
            $r[] = self::majuscula(implode(', ', $parti));
        }
        if ($r === []) {
            $nr = $this->operator()['certificat_clasificare'];
            if ($nr !== null) {
                $r[] = self::majuscula($this->t->t('legal.clasificare.certificat', ['numar' => $nr]));
            }
        }
        return $r;
    }

    /**
     * Eticheta tipului de structură în limba paginii: pe paginile în altă limbă decât româna, sora opțională
     * `tip_structura_{limba}` din EasyCheck (de exemplu `tip_structura_en`), când vine; altfel eticheta română.
     * Florile sau stelele se aleg tot după eticheta română (nomenclatorul).
     */
    private function tipInLimba(array $d, string $tip): string
    {
        if ($this->limba !== 'ro' && preg_match('~^[a-z]{2}$~', $this->limba)) {
            $sora = $d['tip_structura_' . $this->limba] ?? null;
            if (is_string($sora) && trim($sora) !== '') {
                return trim($sora);
            }
        }
        return $tip;
    }

    /** Pensiunile agroturistice au flori; celelalte tipuri, stele (nomenclatorul de clasificare). */
    public static function agroturistic(string $tip): bool
    {
        return mb_stripos($tip, 'agroturistic') !== false;
    }

    /** Numele camerelor pe site: titlul local al limbii, altfel titlul din EasyCheck. @return array<string,string> */
    private function numeCamere(): array
    {
        $local = [];
        foreach ($this->app->baza()->toate('SELECT camera, titlu FROM {camere_local}') as $x) {
            $t = json_decode((string) ($x['titlu'] ?? ''), true);
            if (is_array($t) && is_string($t[$this->limba] ?? null) && trim($t[$this->limba]) !== '') {
                $local[(string) $x['camera']] = trim($t[$this->limba]);
            }
        }
        $r = [];
        foreach ((array) ($this->app->ecOglinda()->corp('camere')['camere'] ?? []) as $c) {
            if (is_array($c) && is_string($c['id'] ?? null)) {
                $titlu = $local[$c['id']] ?? (is_string($c['titlu'] ?? null) ? trim($c['titlu']) : '');
                if ($titlu !== '') {
                    $r[$c['id']] = $titlu;
                }
            }
        }
        return $r;
    }

    private static function majuscula(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
