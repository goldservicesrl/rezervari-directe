<?php

declare(strict_types=1);

namespace Cazo\Legal;

use Cazo\Gdpr\Gdpr;
use Cazo\I18n\Traducator;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cai;

defined('CAZO') or exit;

/**
 * Paginile legale din modele: termenii, confidențialitatea (cu secțiunea despre cookie-uri),
 * accesibilitatea și datele legale. Modelele stau în cataloagele nucleului (legal.<pagina>.<n>.titlu / .text), completate din datele
 * proprietății, și sunt versionate: versiunea e amprenta textului afișat, iar acordul indică versiunea exactă.
 * Gazda poate schimba integral fiecare pagină din panou (Editabile); versiunile vechi cu paragrafe adăugate
 * se afișează ca înainte, până la prima salvare integrală.
 *
 * Numele furnizorilor tehnici apar doar în lista destinatarilor din politica de confidențialitate (excepția de la regula textelor pentru oaspeți),
 * ca parametri, nu în cataloage.
 */
final class PaginiLegale
{
    public const PAGINI = ['date_legale', 'termeni', 'confidentialitate', 'accesibilitate'];

    /** Secțiunile cu ancoră, ținta unui link legal (Cai::ANCORE): pagina => [numărul secțiunii => linkul]. */
    public const SECTIUNI_ANCORA = ['confidentialitate' => [8 => 'cookie']];

    /** În textul integral al gazdei, ancora stă pe prima secțiune al cărei titlu conține cuvântul: pagina => [cuvânt => link]. */
    public const ANCORA_TITLU = ['confidentialitate' => ['cookie' => 'cookie']];

    /**
     * Destinatarii datelor (politica de confidențialitate), numiți ca excepție de la regula textelor pentru oaspeți; {harta} e serviciul de
     * hărți ales de gazdă (serviciuHarta()).
     */
    public const DESTINATARI = [
        'ro' => [
            'easycheck' => 'EasyCheck (programul nostru de gestiune a rezervărilor, persoană împuternicită pentru datele rezervării)',
            'stripe' => 'Stripe (procesatorul plăților online, când plătiți cu cardul pe site)',
            'harta' => 'serviciul de hărți {harta}, de la care se încarcă harta de pe site și care primește astfel adresa IP a dispozitivului dumneavoastră',
        ],
        'en' => [
            'easycheck' => 'EasyCheck (our booking management software, processor for the booking data)',
            'stripe' => 'Stripe (the online payment processor, when you pay by card on this site)',
            'harta' => 'the {harta} map service, which serves the map shown on this site and so receives the IP address of your device',
        ],
    ];

    /**
     * Destinatarii după setările site-ului: procesatorul plăților apare doar cu plata cu cardul pornită;
     * serviciul de hărți apare mereu (harta se încarcă fără clic).
     */
    public static function destinatari(Aplicatie $app, string $limba): string
    {
        $d = self::DESTINATARI[$limba] ?? self::DESTINATARI['ro'];
        if (!$app->modPlata()->vreaCard()) {
            unset($d['stripe']);
        }
        return str_replace('{harta}', self::serviciuHarta($app), implode('; ', $d));
    }

    /**
     * Frazele paginilor legale care urmează setările site-ului, puse în model prin câmpuri între acolade:
     * {contact_fraza} (telefonul și e-mailul care există), {total_cuprinde} (ce cuprinde totalul și ce se plătește
     * separat, la sosire), {plata_metode} și {plata_avans} (metodele de plată active, cu transferul bancar), {date_card} (doar cu plata cu
     * cardul) și {pastrare} (termenele de păstrare alese de gazdă, spuse în clar). Un paragraf rămas gol după
     * completare lipsește de pe pagină. Gazda care își scrie singură textul poate păstra câmpurile.
     * @return array<string,string>
     */
    public static function fraze(Aplicatie $app, Traducator $t, string $email, string $telefon): array
    {
        $contact = match (true) {
            $email !== '' && $telefon !== '' => $t->t('legal.contact.ambele', ['email' => $email, 'telefon' => $telefon]),
            $email !== '' => $t->t('legal.contact.unul', ['unde' => $email]),
            $telefon !== '' => $t->t('legal.contact.unul', ['unde' => $telefon]),
            default => $t->t('legal.contact.pagina'),
        };
        // Ce cuprinde totalul: la „Prețul din EasyCheck”, alegerea gazdei din panou; la „Prețul de pe
        // site-ul meu”, totalul motorului local le cuprinde pe toate.
        $easycheck = ($app->ecLicenta()->cheie()['sursa'] ?? null) === 'easycheck';
        $inc = $easycheck ? $app->includeTotal() : ['taxe' => true, 'taxa_locala' => true];
        $total = [$t->t('legal.total.cuprinde.' . ($inc['taxe'] ? ($inc['taxa_locala'] ? 'toate' : 'taxe') : ($inc['taxa_locala'] ? 'taxa_locala' : 'nopti')))];
        if (!$inc['taxe'] || !$inc['taxa_locala']) {
            $total[] = $t->t('legal.total.separat.' . (!$inc['taxe'] ? (!$inc['taxa_locala'] ? 'ambele' : 'taxe') : 'taxa_locala'));
        } elseif ($easycheck && $app->modPlata()->incaseazaInainte() && $app->taxaIncasare() === \Cazo\Pret\RegulaPlata::TAXA_LA_SOSIRE) {
            $total[] = $t->t('legal.total.taxa_la_sosire');
        }
        $bife = \Cazo\Rezervare\ModPlata::bife($app->modPlata()->lista());
        $card = $bife['card'];
        $cash = $bife['cash'];
        $transfer = $bife['transfer'];
        // {plata_metode} numește metodele bifate, cu transferul bancar; fără alegere, fraza generală.
        $metode = $app->modPlata()->metode();
        return [
            'contact_fraza' => $contact,
            'total_cuprinde' => implode(' ', $total),
            'plata_metode' => $t->t('legal.plata.' . ($metode === null ? 'general' : ($metode === 'cash' ? 'sosire' : $metode))),
            'plata_avans' => $card || $transfer || !$cash ? $t->t('legal.plata.avans') : '',
            // Datele plății: cardul (la procesator) și, la transferul bancar, numele și contul plătitorului văzute în extras.
            'date_card' => implode(' ', array_filter([$card || $metode === null ? $t->t('legal.date_card') : '', $transfer ? $t->t('legal.date_transfer') : ''])),
            'pastrare' => self::pastrare($app, $t),
        ];
    }

    /**
     * Perioada de păstrare a datelor, spusă în clar, din termenele alese de gazdă (`Gdpr::termene()`): merge
     * și cu trei termene (comenzile abandonate, rambursate și respinse împreună), și cu patru (cele rambursate sau
     * respinse pe termenul lor, orice cheie în plus cu „rambursat”, „respins” sau „oprit” în nume). Fără termene alese,
     * fraza generală.
     */
    public static function pastrare(Aplicatie $app, Traducator $t): string
    {
        $termene = $app->gdpr()->termene();
        if (!is_array($termene) || !is_int($termene['confirmate_zile'] ?? null)) {
            return $t->t('legal.pastrare.general');
        }
        // duratele în ani și luni când se împart exact (365 de zile = 1 an, 180 de zile = 6 luni).
        $durata = static fn (string $cheie, int $n): string => Gdpr::durata($t, str_ends_with($cheie, '_ore') ? 'abandonate_ore' : (str_ends_with($cheie, '_luni') ? 'ip_luni' : 'confirmate_zile'), $n);
        $separat = null;
        foreach ($termene as $k => $v) {
            if (is_int($v) && !in_array($k, ['confirmate_zile', 'abandonate_ore', 'ip_luni'], true) && preg_match('~rambursat|respins|oprit~', (string) $k)) {
                $separat = $durata((string) $k, $v);
                break;
            }
        }
        $f = [$t->t('legal.pastrare.confirmate', ['durata' => $durata('confirmate_zile', $termene['confirmate_zile'])])];
        if (is_int($termene['abandonate_ore'] ?? null)) {
            $f[] = $t->t($separat !== null ? 'legal.pastrare.abandonate' : 'legal.pastrare.oprite', ['durata' => $durata('abandonate_ore', $termene['abandonate_ore'])]);
        }
        if ($separat !== null) {
            $f[] = $t->t('legal.pastrare.rambursate', ['durata' => $separat]);
        }
        if (is_int($termene['ip_luni'] ?? null)) {
            $f[] = $t->t('legal.pastrare.ip', ['durata' => $durata('ip_luni', $termene['ip_luni'])]);
        }
        $f[] = $t->t('legal.pastrare.contabile');
        return implode(' ', $f);
    }

    /**
     * Data ultimei schimbări a textului, arătată oaspetelui în locul codului versiunii: la textul scris de
     * gazdă, ziua salvării lui; la model, ziua în care site-ul a văzut prima dată textul de acum (după o actualizare
     * sau după o setare care îl schimbă), ținută în `legal.actualizat`.
     */
    private function actualizat(string $pagina, string $versiune, bool $integral): string
    {
        try {
            if ($integral) {
                $la = $this->app->baza()->valoare("SELECT aprobat_la FROM {texte_legale} WHERE fel = ? AND limba = ? AND activ = 1 ORDER BY versiune DESC LIMIT 1", [$pagina, $this->limba]);
                $zi = is_string($la) ? substr($la, 0, 10) : '';
            } else {
                $toate = (array) ($this->app->setari()->get('legal.actualizat') ?? []);
                $k = $pagina . '.' . $this->limba;
                if (!is_array($toate[$k] ?? null) || ($toate[$k]['v'] ?? null) !== $versiune) {
                    $toate[$k] = ['v' => $versiune, 'la' => $this->app->azi()];
                    $this->app->setari()->pune('legal.actualizat', $toate);
                }
                $zi = (string) $toate[$k]['la'];
            }
            return $zi !== '' ? \Cazo\I18n\Format::ziLunga($zi, $this->limba) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** Serverele de hărți cunoscute, după gazda adresei plăcilor (`harta.placi`). */
    public const SERVICII_HARTA = ['tile.openstreetmap.org' => 'OpenStreetMap'];

    /**
     * Numele serviciului de hărți: numele cunoscut al serverului de plăci (`harta.placi`, implicit
     * OpenStreetMap), altfel gazda adresei lui. Câmpul din panou pentru un nume scris de gazdă s-a scos:
     * serviciul e OpenStreetMap, iar o valoare `harta.nume` rămasă de la o versiune veche nu se mai citește.
     */
    public static function serviciuHarta(Aplicatie $app): string
    {
        $gazda = strtolower((string) parse_url((string) $app->setari()->get('harta.placi', 'https://tile.openstreetmap.org'), PHP_URL_HOST));
        return self::SERVICII_HARTA[$gazda] ?? ($gazda !== '' ? $gazda : 'OpenStreetMap');
    }

    public const SECTIUNI_MAX = 20;

    /** La persoana fizică, paragraful are varianta `<cheie>_pf`, fără adresă, când catalogul o are. */
    public static function cheiePf(Traducator $t, string $cheie, bool $pf): string
    {
        return $pf && $t->are($cheie . '_pf') ? $cheie . '_pf' : $cheie;
    }

    public function __construct(private Aplicatie $app, private Traducator $t, private string $limba)
    {
    }

    /**
     * @return array{pagina:string, titlu:string, intro:list<string>, sectiuni:list<array{titlu:string, paragrafe:list<string>, ancora:string}>, versiune:string,
     *   operator?:list<array>, sal_url?:string, sal_img?:string}
     */
    public function pagina(string $pagina): array
    {
        if (!in_array($pagina, self::PAGINI, true)) {
            throw new \InvalidArgumentException('Pagină legală necunoscută.');
        }
        // Paginile legale sunt publice: telefonul gazdei apare doar la alegerea ei.
        $p = (array) ($this->app->ecOglinda()->proprietatePublica() ?? []);
        $bloc = new BlocLegal($this->app, $this->t, $this->limba);
        $op = $bloc->operator();
        // La persoana fizică, adresa rămâne în afara paginilor legale.
        $adresa = $bloc->adresa();
        $pf = BlocLegal::persoanaFizica($this->app);
        $email = trim((string) ($p['contact']['email'] ?? ''));
        $telefon = trim((string) ($p['contact']['telefon'] ?? ''));
        $param = self::fraze($this->app, $this->t, $email, $telefon) + [
            'nume' => (string) ($p['nume'] ?? ''),
            'denumire' => (string) ($op['denumire'] ?? $p['nume'] ?? ''),
            // Un contact lipsă lasă celălalt în locul lui, ca nicio frază să rămână cu un loc gol („și la .”).
            'email' => $email !== '' ? $email : $telefon,
            'telefon' => $telefon !== '' ? $telefon : $email,
            'adresa' => $adresa,
            'checkin' => (string) ($p['ore']['checkin_de_la'] ?? ''),
            'plecare' => (string) ($p['ore']['plecare_pana_la'] ?? ''),
            'site' => $this->app->config()->origine(),
            'destinatari' => self::destinatari($this->app, $this->limba),
            'harta' => self::serviciuHarta($this->app),
        ];
        $sectiuni = [];
        // Introducerea: paragrafele dinaintea primei secțiuni (modelul are unul).
        $intro = $this->t->are("legal.$pagina.intro") ? [$this->t->t("legal.$pagina.intro", $param)] : [];
        // Textul integral al gazdei, când l-a salvat; altfel modelul, cu paragrafele adăugate înainte.
        $integral = (new Editabile($this->app))->integral($pagina, $this->limba);
        if ($integral !== null) {
            $d = Editabile::desfacePagina($integral, $param);
            $intro = $d['intro'];
            foreach ($d['sectiuni'] as $s) {
                $sectiuni[] = $s + ['ancora' => ''];
            }
            // Ancora unui link legal (cookie-urile) stă pe secțiunea al cărei titlu numește linkul.
            foreach (self::ANCORA_TITLU[$pagina] ?? [] as $cuvant => $link) {
                foreach ($sectiuni as $k => $s) {
                    if (mb_stripos($s['titlu'], $cuvant) !== false) {
                        $sectiuni[$k]['ancora'] = (string) (Cai::ANCORE[$link][$this->limba] ?? Cai::ANCORE[$link]['ro']);
                        break;
                    }
                }
            }
        }
        for ($i = 1; $integral === null && $i <= self::SECTIUNI_MAX; $i++) {
            $baza = "legal.$pagina.$i";
            if (!$this->t->are("$baza.titlu")) {
                break;
            }
            $paragrafe = [];
            for ($j = 1; $j <= 8 && $this->t->are("$baza.p$j"); $j++) {
                // Un paragraf rămas gol după completare (o frază care lipsește la setările site-ului) nu apare.
                $text = trim($this->t->t(self::cheiePf($this->t, "$baza.p$j", $pf), $param));
                if ($text !== '') {
                    $paragrafe[] = $text;
                }
            }
            $link = self::SECTIUNI_ANCORA[$pagina][$i] ?? null;
            $ancora = $link !== null ? (string) (Cai::ANCORE[$link][$this->limba] ?? Cai::ANCORE[$link]['ro']) : '';
            $sectiuni[] = ['titlu' => $this->t->t("$baza.titlu", $param), 'paragrafe' => $paragrafe, 'ancora' => $ancora];
        }
        foreach ($integral !== null ? [] : $this->app->baza()->toate("SELECT corp FROM {texte_legale} WHERE fel = ? AND limba = ? AND activ = 1 AND format = 'adaugat' ORDER BY versiune DESC LIMIT 1", [$pagina, $this->limba]) as $r) {
            $paragrafe = array_values(array_filter(array_map('trim', preg_split('~\R{2,}~', strip_tags((string) $r['corp'])) ?: []), 'strlen'));
            if ($paragrafe !== []) {
                $sectiuni[] = ['titlu' => $this->t->t('legal.adaugat_gazda'), 'paragrafe' => $paragrafe, 'ancora' => ''];
            }
        }
        $rez = [
            'pagina' => $pagina,
            'titlu' => $this->t->t('legal.link.' . $pagina),
            'intro' => $intro,
            'sectiuni' => $sectiuni,
        ];
        if ($pagina === 'date_legale') {
            $d = $bloc->date();
            $rez['operator'] = $d['randuri'];
            $rez['sal_url'] = $d['sal_url'];
            $rez['sal_img'] = $d['sal_img'];
            $rez['contact'] = ['email' => $email, 'telefon' => $telefon, 'adresa' => $adresa];
        }
        // Versiunea se calculează cu introducerea ca text cât are cel mult un paragraf, ca paginile neschimbate să-și
        // păstreze versiunea de dinainte (acordurile și contractele o citează).
        $h = $rez;
        $h['intro'] = count($intro) <= 1 ? (string) ($intro[0] ?? '') : $intro;
        $rez['versiune'] = substr(hash('sha256', json_encode($h, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 10);
        $rez['actualizat'] = $this->actualizat($pagina, $rez['versiune'], $integral !== null);
        return $rez;
    }
}
