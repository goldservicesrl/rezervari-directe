<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Erori;
use Cazo\Nucleu\Raspuns;
use Cazo\Pret\Sejur;

defined('CAZO') or exit;

/**
 * Randarea unei pagini publice în tema activă:
 *
 *  1. compoziția: secțiunile din templates/<sablon>.json (cu alegerile gazdei din teme_setari.compozitie), fiecare
 *     cu setările implicite din schema ei; sau, pe paginile nucleului (căutarea, paginile legale), conținutul
 *     nucleului în {% block continut %};
 *  2. un șablon compus de nucleu, cu nume statice, care extinde layout/baza.twig al temei;
 *  3. randarea în sandbox, sub Garda: adâncimea șabloanelor, pașii buclelor, mărimea valorilor, ieșirea
 *     numărată pe bucăți cât curge (plafon 2 MB), memoria și timpul (3 s); timpul se jurnalizează peste 1 s;
 *  4. preîncărcarea imaginii LCP pusă în <head>, verificarea marcajelor data-pg;
 *  5. la orice eroare a temei (inclusiv o limită depășită sau o eroare PHP din codul compilat al temei), aceeași
 *     pagină în _schelet (biblioteca nucleului), cu alertă în jurnal și e-mail către gazdă (cel mult unul pe zi
 *     pentru aceeași temă și același motiv);
 *  6. o temă terță randează și sub Plasa: o eroare fatală a PHP (memorie, timp, orice altă eroare fatală) apărută
 *     în randarea ei duce, din funcția de oprire, la aceeași pagină în _schelet, cu starea și antetele răspunsului
 *     construit de controler ($p['raspuns']), fără ieșirea parțială, cu aceeași alertă.
 */
final class Randare
{
    public const IESIRE_MAXIMA = Garda::IESIRE;
    /** Peste atâtea secunde, pagina se servește cu _schelet și gazda primește alerta. */
    public const TIMP_MAXIM = Garda::TIMP;
    public const SECTIUNI_MAX = 50;

    /** @var list<string> marcajele data-pg care trebuie să apară cel mult o dată */
    public const MARCAJE_UNICE = ['legal', 'contact', 'bara', 'calendar', 'rezumat', 'harta', 'banda', 'cautare', 'continut-legal', 'continut-pagina', 'comanda', 'stare', 'rezervarea-mea', 'eroare'];

    /** Plafonul de timp al temei active și al rezervei _schelet, în secunde (probele îl pot coborî). */
    public float $timpMaxim = self::TIMP_MAXIM;
    public float $timpSchelet = self::TIMP_MAXIM;

    /**
     * Randarea de probă a validatorului: eroarea temei ajunge la apelant, fără _schelet, fără alertă
     * și fără plasă (raportul o arată autorului cu fișierul și motivul).
     */
    public bool $faraRezerva = false;

    public function __construct(private Aplicatie $app)
    {
    }

    /**
     * @param array{
     *   pagina:string, sablon:?string, limba:string, titlu:string, descriere?:string, canonic:string,
     *   alternative:array<string,string>, noindex?:bool, poza_og?:?array, model?:array, continut?:string, activ?:string,
     *   cerere?:?Sejur, cautare?:?array, ip24?:string, raspuns?:\Closure(array): Raspuns
     * } $p  raspuns: cum construiește controlerul răspunsul din rezultat (folosit și de Plasa, după o eroare fatală)
     * @return array{html:string, harta:bool, folosite:array<string,int>, tema:string, rezerva:bool}
     */
    public function pagina(array $p): array
    {
        $tema = $this->app->temaActiva();
        $schelet = $this->app->temaSchelet();
        if ($tema->slug() === $schelet->slug()) {
            return $this->cuTema($tema, $p, $this->timpSchelet);
        }
        if ($this->faraRezerva) {
            return $this->cuTema($tema, $p, $this->timpMaxim);
        }
        // O temă terță randează sub plasă: o eroare fatală a PHP din randarea ei ajunge tot la _schelet.
        $plasa = !$tema->oficiala();
        if ($plasa) {
            Plasa::armeaza(fn (string $motiv, string $mesaj) => $this->dupaFatala($tema, $schelet, $p, $motiv, $mesaj));
        }
        $eroare = null;
        try {
            $r = $this->cuTema($tema, $p, $this->timpMaxim);
        } catch (\Throwable $e) {
            $eroare = $e;
        } finally {
            if ($plasa) {
                Plasa::dezarmeaza();
            }
        }
        if ($eroare === null) {
            return $r;
        }
        // Orice eroare a temei, inclusiv \Error din codul ei compilat; pagina rămâne servită, cu _schelet.
        $limita = LimitaDepasita::din($eroare);
        $this->alerta($tema, $p, $limita !== null ? $limita->limita : 'eroare', $eroare->getMessage(), false);
        return $this->cuTema($schelet, $p, $this->timpSchelet);
    }

    /** Jurnalul și alerta gazdei pentru o temă care a eșuat; cel mult o alertă pe zi pentru aceeași temă și același motiv. */
    private function alerta(Tema $tema, array $p, string $motiv, string $mesaj, bool $fatala): void
    {
        $this->app->jurnal()->eroare('Tema a eșuat; pagina se servește cu _schelet', [
            'tema' => $tema->slug(), 'pagina' => $p['pagina'], 'motiv' => $motiv, 'fatala' => $fatala, 'eroare' => mb_substr($mesaj, 0, 500),
        ]);
        $this->app->alertaGazda('alerta_tema', ['tema' => $tema->slug(), 'motiv' => $motiv], 'tema:' . $tema->slug() . ':' . $motiv, 86400);
    }

    /**
     * Ultimul strat (Plasa): după o eroare fatală a PHP în randarea temei terțe, din funcția de oprire. Aceeași pagină
     * în _schelet, construită de controler cu răspunsul lui ($p['raspuns']: starea, CSP-ul, cookie-urile, antetele),
     * apoi trimisă de aplicație cu antetele profilului; alerta gazdei intră în coadă, ca la orice eșec al temei.
     */
    private function dupaFatala(Tema $tema, Tema $schelet, array $p, string $motiv, string $mesaj): void
    {
        $this->alerta($tema, $p, $motiv, $mesaj, true);
        try {
            $r = $this->cuTema($schelet, $p, $this->timpSchelet);
            $raspuns = isset($p['raspuns']) ? ($p['raspuns'])($r) : Raspuns::html($r['html']);
        } catch (\Throwable $e) {
            $this->app->jurnal()->eroare('Pagina _schelet a eșuat după eroarea fatală a temei', ['tema' => $tema->slug(), 'eroare' => mb_substr($e->getMessage(), 0, 500)]);
            $raspuns = Erori::pagina($this->app->traducator($p['limba']), 500, $this->app->url()->cale('/', $p['limba']));
        }
        $this->app->trimiteDupaFatala($raspuns);
    }

    private function cuTema(Tema $tema, array $p, float $timp): array
    {
        $start = microtime(true);
        $limba = $p['limba'];
        $t = $this->app->traducator($limba);
        $active = $this->app->active();
        $activeNucleu = $active->nucleu();
        $activeTema = $active->tema($tema);
        $implicite = $tema->setariImplicite();
        $setari = Jetoane::sigure(array_replace($implicite, $this->app->temaSetari($tema->slug())['valori'] ?? []), $implicite, $tema->contrastTema());
        // Înălțimea siglei gazdei stă în aceeași foaie, după variabile.
        $jetoane = $active->jetoane(Jetoane::css($setari, $tema->fonturi(), $tema->latime()) . $this->app->sigla()->css());
        $model = $this->app->modelVedere($limba);
        $proba = $this->app->ecLicenta()->stare()['proba'];
        $componente = new Componente($this->app, $model, $t, $limba, $p['cerere'] ?? null, $p['ip24'] ?? '0.0.0.0/24');
        $componente->ocupateAcum = $p['ocupate'] ?? [];
        $meta = [
            'titlu' => $p['titlu'],
            'descriere' => $p['descriere'] ?? '',
            'canonic' => $p['canonic'],
            'alternative' => $p['alternative'],
            'noindex' => (bool) ($p['noindex'] ?? false),
            'site' => $model->site([], $proba, $this->app->config()->limbi())['nume'],
            'limba' => $limba,
            'og_imagine' => $componente->imagineOg($p['poza_og'] ?? null),
            'og_locale' => $limba === 'ro' ? 'ro_RO' : 'en_US',
            'proba' => $proba,
            // Modul de pregătire: adresa paginii de publicare, doar pentru administratorul autentificat.
            'pregatire' => is_string($p['pregatire'] ?? null) ? $p['pregatire'] : null,
            // Alerta site publicat cu setări lipsă, tot doar pentru administratorul autentificat.
            'date_lipsa' => is_string($p['date_lipsa'] ?? null) ? $p['date_lipsa'] : null,
            'refresh' => self::refresh($p['refresh'] ?? null),
            // Pictograma site-ului din sigla gazdei; fără siglă, litera numelui (Functii::favicon).
            'favicon' => $this->app->sigla()->favicon(),
        ];
        $nucleu = MediuNucleu::creeaza(Aplicatie::radacina() . '/vederi', $this->dirCache('nucleu-' . Aplicatie::versiune()), $t);
        $f = new Functii($nucleu, $t, $this->app->url(), $tema, $activeNucleu, $activeTema, $jetoane, $meta,
            fn (string $c, array $a): ?array => $componente->date($c, $a), $p['continut'] ?? '');
        // Pe comandă, stare și „Rezervarea mea” rulează doar scripturile nucleului (CSP pe căi).
        $f->faraJsTema = !empty($p['rezervare']);

        $sectiuni = $p['sablon'] !== null ? $this->compozitie($tema, $p['sablon']) : [];
        $sursa = self::sablonCompus($sectiuni, $p['sablon'] === null);
        $env = MediuSandbox::creeaza($tema->dir(), $tema->dirSchelet(), $this->dirCache('tema-' . $tema->sha() . '-' . Aplicatie::versiune()), $f, ['pagina' => $sursa]);
        $alternative = $p['alternative'];
        unset($alternative['x-default']);
        $context = [
            'site' => $model->site($alternative, $proba, $this->app->config()->limbi()),
            'proprietate' => $model->proprietate(),
            'camere' => $model->camere(),
            'camera' => $p['model']['camera'] ?? null,
            'pagina' => ['tip' => $p['pagina'], 'titlu' => $p['titlu'], 'sectiuni' => $sectiuni, 'corp' => $p['pagina'] === 'pagina' ? ($p['continut'] ?? '') : '', 'seo' => ['descriere' => $p['descriere'] ?? '']],
            'continut' => $model->continut(),
            'planuri' => $componente->planuri(),
            'setari' => $setari,
            'meniu' => $model->meniu($p['activ'] ?? $p['pagina']),
            'cerere' => $componente->cerere(),
            // vremea (null fără date proaspete), punctele de reper și atribuirea lor.
            'vreme' => $componente->vreme(),
            // Componenta-semnătură cerută în manifest (`componente`), doar pe pagina de început și pe cea a
            // camerelor (pagina camerei are calendarul nucleului). Paginile acestea nu primesc cerere, deci luna pe
            // zile e prima lună cu prețuri.
            'preturi_sezon' => in_array('harta_termica_preturi', (array) ($tema->manifest()['componente'] ?? []), true) && in_array($p['sablon'], ['acasa', 'camere'], true)
                ? $model->preturiSezon(null) : null,
            // Răsăritul și apusul: azi, ziua sosirii și dimineața de după, plus rasarit/apus de azi la primul nivel.
            'soare' => ($soare = $model->soare($componente->cerere()['sosire'] ?? null)) !== null
                ? $soare + ['rasarit' => $soare['azi']['rasarit'] ?? null, 'apus' => $soare['azi']['apus'] ?? null] : null,
            'repere' => ($repere = $componente->repere())['repere'],
            'repere_atribuire' => $repere['atribuire'],
            // Notele de pe platforme.
            'recenzii' => $model->recenzii(),
        ];
        $html = MediuSandbox::randeaza($env, '@pagina/pagina', $context, $timp);
        if (strlen($html) > self::IESIRE_MAXIMA) {
            throw new \LengthException('Ieșirea temei depășește 2 MB.');
        }
        $html = str_replace($f->marcaj, $f->linkuriPreincarcare(), $html);
        // Pe paginile rezervării (comanda, starea, „Rezervarea mea”), conținutul temei n-are legături spre alte domenii.
        $gazda = !empty($p['rezervare']) ? (string) parse_url($this->app->url()->absolut('/'), PHP_URL_HOST) : null;
        $html = self::zone($html, $f, !$tema->oficiala(), $gazda);
        // Pagina finală, citită ca în browser (parserul HTML5 al PHP 8.4): structura zonelor și legăturile.
        if (($motiv = self::structura($html, $gazda)) !== null) {
            throw new \UnexpectedValueException($motiv);
        }
        $durata = microtime(true) - $start;
        if ($durata > 1.0) {
            $this->app->jurnal()->avertisment('Randare lentă', ['tema' => $tema->slug(), 'pagina' => $p['pagina'], 'ms' => (int) ($durata * 1000)]);
        }
        foreach (self::MARCAJE_UNICE as $m) {
            if (substr_count($html, 'data-pg="' . $m . '"') > 1) {
                throw new \UnexpectedValueException('Marcajul data-pg="' . $m . '" apare de mai multe ori.');
            }
        }
        if (substr_count($html, 'data-pg="legal"') !== 1 || !isset($f->folosite['head'], $f->folosite['foot'])) {
            throw new \UnexpectedValueException('Tema trebuie să cheme cazo.head() și cazo.foot(); blocul legal apare o singură dată.');
        }
        return ['html' => $html, 'harta' => isset($f->folosite['harta']), 'folosite' => $f->folosite, 'tema' => $tema->slug(), 'rezerva' => false];
    }

    /**
     * Pagina finală citită cu un parser HTML5 (Dom\HTMLDocument, PHP 8.4+; închiderile implicite, comentariile false,
     * textul brut și foster parenting, ca în browser): <body> are exact copiii [sus?] .pg-tema [continut .pg-tema--dupa]
     * jos, fără text în afara lor; blocul legal și contactul stau în zona de jos, comanda în zona ei; pe paginile
     * rezervării ($gazda), conținutul temei n-are legături spre alte domenii. Motivul, sau null. Fără parser (PHP 8.2-8.3),
     * rămâne verificarea tokenizatorului (HtmlTaguri::continutTema).
     */
    public static function structura(string $html, ?string $gazda = null): ?string
    {
        if (!class_exists(\Dom\HTMLDocument::class)) {
            return null;
        }
        $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $body = $doc->body;
        if ($body === null || $body->getAttribute('class') !== 'pg-corp' && $body->getAttribute('class') !== 'pg-corp pg-corp--terta') {
            return 'Pagina citită ca în browser n-are corpul nucleului.';
        }
        $copii = [];
        $zone = [];
        for ($n = $body->firstChild; $n !== null; $n = $n->nextSibling) {
            if ($n instanceof \Dom\Element) {
                $cls = ' ' . $n->getAttribute('class') . ' ';
                $copii[] = $fel = match (true) {
                    str_contains($cls, ' pg-nucleu--sus ') && $n->getAttribute('data-pg-nucleu') === 'sus' => 'sus',
                    str_contains($cls, ' pg-nucleu--continut ') && $n->getAttribute('data-pg-nucleu') === 'continut' => 'continut',
                    str_contains($cls, ' pg-nucleu--jos ') && $n->getAttribute('data-pg-nucleu') === 'jos' && $n->tagName === 'FOOTER' => 'jos',
                    str_contains($cls, ' pg-tema--dupa ') && str_starts_with($cls, ' pg-tema ') => 'dupa',
                    str_starts_with($cls, ' pg-tema ') && $n->tagName === 'DIV' => 'tema',
                    in_array($n->tagName, ['SCRIPT', 'TEMPLATE'], true) && str_starts_with((string) $n->getAttribute('src'), '/_c/') => 'script',
                    default => '?' . strtolower($n->tagName),
                };
                $zone[$fel][] = $n;
            } elseif ($n instanceof \Dom\Text && trim($n->data, " \t\n\f\r") !== '') {
                $copii[] = 'text';
            }
        }
        $copii = array_values(array_filter($copii, static fn (string $c): bool => $c !== 'script'));
        if (!in_array(implode(',', $copii), ['tema,jos', 'sus,tema,jos', 'tema,continut,dupa,jos', 'sus,tema,continut,dupa,jos'], true)) {
            return 'Pagina citită ca în browser are alte zone decât cele ale nucleului: ' . implode(', ', $copii) . '.';
        }
        $jos = $zone['jos'][0];
        if ($jos->querySelector('[data-pg="legal"]') === null || $jos->querySelector('[data-pg="contact"]') === null) {
            return 'Pagina citită ca în browser n-are blocul legal și contactul în zona de jos.';
        }
        if ($body->querySelector('[data-pg="comanda"]') !== null && ($zone['continut'][0] ?? null)?->querySelector('[data-pg="comanda"]') === null) {
            return 'Pagina citită ca în browser n-are comanda în zona nucleului.';
        }
        if ($gazda !== null) {
            foreach (array_merge($zone['tema'], $zone['dupa'] ?? []) as $t) {
                foreach ($t->getElementsByTagName('*') as $e) {
                    // Legăturile blocurilor nucleului (data-pg, pus doar de nucleu) rămân; cele ale temei, nu.
                    if ($e->closest('[data-pg]') !== null) {
                        continue;
                    }
                    foreach ($e->attributes as $a) {
                        if (in_array($a->localName, ['href', 'ping'], true) && ($h = HtmlTaguri::gazdaExterna((string) $a->value)) !== null && $h !== strtolower($gazda)) {
                            return 'Pe paginile rezervării, conținutul temei are o legătură spre alt domeniu (' . $h . ').';
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * Secțiunile șablonului: templates/<sablon>.json al temei, cu compoziția gazdei peste el; tipurile necunoscute
     * se sar (jurnalizate), setările se completează cu valorile implicite din schemă.
     * @return list<array{id:string, tip:string, sablon:string, setari:array, blocuri:list<array>}>
     */
    public function compozitie(Tema $tema, string $sablon): array
    {
        $j = $tema->sablon($sablon) ?? $this->app->temaSchelet()->sablon($sablon) ?? ['sectiuni' => []];
        $gazda = $this->app->temaSetari($tema->slug())['compozitie'][$sablon] ?? null;
        $lista = is_array($gazda['sectiuni'] ?? null) ? $gazda['sectiuni'] : (array) ($j['sectiuni'] ?? []);
        $rez = [];
        foreach (array_slice($lista, 0, self::SECTIUNI_MAX) as $i => $s) {
            if (!is_array($s) || !is_string($s['tip'] ?? null) || !empty($s['ascunsa'])) {
                continue;
            }
            $fisier = $tema->sectiune($s['tip']);
            if ($fisier === null) {
                $this->app->jurnal()->avertisment('Secțiune necunoscută în șablon', ['tema' => $tema->slug(), 'sablon' => $sablon, 'tip' => $s['tip']]);
                continue;
            }
            $schema = $tema->schemaSectiune($s['tip']);
            $setari = [];
            foreach ((array) ($schema['setari'] ?? []) as $x) {
                if (is_array($x) && is_string($x['id'] ?? null)) {
                    $setari[$x['id']] = $x['implicit'] ?? null;
                }
            }
            foreach ((array) ($s['setari'] ?? []) as $k => $v) {
                if (array_key_exists($k, $setari) && (is_scalar($v) || $v === null)) {
                    $setari[$k] = $v;
                }
            }
            $rez[] = [
                'id' => 's' . $i,
                'tip' => $s['tip'],
                'sablon' => $fisier,
                'setari' => $setari,
                'blocuri' => array_values(array_filter((array) ($s['blocuri'] ?? []), 'is_array')),
            ];
        }
        return $rez;
    }

    /** Șablonul compus: extinde layoutul temei; secțiunile sunt incluse cu nume statice, validate mai sus. */
    public static function sablonCompus(array $sectiuni, bool $continutNucleu): string
    {
        $s = "{% extends \"layout/baza.twig\" %}\n{% block continut %}{{ cazo.banda() }}";
        if ($continutNucleu) {
            $s .= '{{ cazo.continut() }}';
        }
        foreach ($sectiuni as $i => $x) {
            if (!preg_match('~^(@schelet/)?sections/[a-z0-9_\-]+\.twig$~', $x['sablon'])) {
                throw new \UnexpectedValueException('Nume de secțiune greșit.');
            }
            $s .= "\n{% include \"" . $x['sablon'] . "\" with {sectiune: pagina.sectiuni[" . (int) $i . "]} %}";
        }
        return $s . "\n{% endblock %}\n";
    }

    /**
     * Izolarea: nucleul deține <body>. Ce a scris tema între <body> și </body> intră în <div class="pg-tema">
     * (cu clasele și atributele pe care tema le punea pe <body>), iar zona nucleului stă alături, soră cu el: sus banda
     * „Site în probă”, jos contactul și blocul legal; pe pagina comenzii, totalul și acordul, între antetul și subsolul
     * temei. CSS-ul temei e prefixat cu :where(.pg-tema) (ValidatorCss::prefixeaza), deci nu ajunge în zona nucleului,
     * iar .pg-tema e un context de suprapunere (z-index 0) sub zona nucleului.
     * Conținutul temei se verifică la fiecare randare (HtmlTaguri::continutTema): o problemă aruncă, iar pagina vine
     * cu _schelet. $terta: tema activă e terță (fontul zonei e al nucleului). $gazda: pe paginile rezervării, domeniul
     * site-ului (legăturile temei spre alte domenii sunt refuzate).
     * @throws \UnexpectedValueException când <body> lipsește, se repetă sau conținutul temei nu trece verificarea
     */
    public static function zone(string $html, Functii $f, bool $terta = false, ?string $gazda = null): string
    {
        // <body> și </body> citite cu tokenizatorul (comentariile, valorile atributelor și textul brut se sar): câte
        // unul din fiecare, iar după </body> doar </html> și spații.
        $deschideri = [];
        $inchideri = [];
        foreach (HtmlTaguri::citeste($html)['toate'] as $t) {
            if ($t['nume'] === 'body') {
                $t['inchidere'] ? $inchideri[] = $t : $deschideri[] = $t;
            }
        }
        if (count($deschideri) !== 1 || count($inchideri) !== 1 || $inchideri[0]['poz'] < $deschideri[0]['sfarsit']) {
            throw new \UnexpectedValueException('Layoutul temei are exact un <body> … </body>.');
        }
        $sfarsit = $inchideri[0]['poz'];
        if (!preg_match('~^\s*(</html\s*>\s*)?$~i', substr($html, $inchideri[0]['sfarsit']))) {
            throw new \UnexpectedValueException('După </body> urmează doar </html>.');
        }
        $inceput = $deschideri[0]['sfarsit'];
        $interior = substr($html, $inceput, $sfarsit - $inceput);
        // Locul comenzii e aleator (Functii::locZona); conținutul temei se verifică fără el, la fiecare randare.
        if (substr_count($interior, $f->locZona) > 1) {
            throw new \UnexpectedValueException('Locul conținutului nucleului apare de mai multe ori.');
        }
        if (($motiv = HtmlTaguri::continutTema(str_replace($f->locZona, '', $interior), $gazda)) !== null) {
            throw new \UnexpectedValueException($motiv);
        }
        $atribute = $deschideri[0]['atribute'];
        foreach ($atribute as [$nume, $valoare]) {
            if ($nume === 'data-pg-nucleu' || ($nume === 'class' && preg_match(HtmlTaguri::CLASE_NUCLEU, $valoare))) {
                throw new \UnexpectedValueException('Clasa de pe <body> folosește o clasă a zonelor nucleului (pg-tema, pg-nucleu, pg-corp).');
            }
        }
        $clase = '';
        $alte = '';
        foreach ($atribute as [$nume, $valoare]) {
            if (!preg_match('~^[a-z][a-z0-9\-]*$~', $nume) || str_starts_with($nume, 'on')) {
                continue;
            }
            if ($nume === 'class') {
                $clase = ' ' . trim($valoare);
            } else {
                $alte .= ' ' . $nume . '="' . htmlspecialchars($valoare, ENT_QUOTES | ENT_HTML5) . '"';
            }
        }
        $clase = htmlspecialchars($clase, ENT_QUOTES | ENT_HTML5);
        $tema = static fn (string $continut, string $extra = ''): string => '<div class="pg-tema' . $clase . $extra . '"' . $alte . '>' . $continut . '</div>';
        $sus = $f->zona('sus');
        $corp = $sus !== '' ? '<div class="pg-nucleu pg-nucleu--sus" data-pg-nucleu="sus">' . $sus . '</div>' : '';
        $loc = strpos($interior, $f->locZona);
        if ($loc !== false && $f->continutZona !== null) {
            // Elementele temei deschise la locul comenzii se închid la sfârșitul primei părți și se redeschid (cu aceleași
            // atribute, fără id) la începutul celei de-a doua: fiecare container își închide singur tagurile, iar „</div>”-ul
            // containerului nu închide un <div> al temei.
            $inainte = substr($interior, 0, $loc);
            $dupa = substr($interior, $loc + strlen($f->locZona));
            $deschise = HtmlTaguri::deschise($inainte);
            // Un singur <main> pe pagină: când locul conținutului e în <main>-ul temei, reperul principal
            // trece pe zona nucleului (cu id-ul lui, deci „Mergeți la conținut” sare la conținutul adevărat), iar
            // cele două jumătăți ale <main>-ului temei rămân simple <div>-uri cu aceleași clase.
            $idMain = null;
            $areMain = false;
            foreach ($deschise as $k => $t) {
                if ($t['nume'] !== 'main') {
                    continue;
                }
                $poz = strripos($inainte, '<main');
                if ($poz === false || substr_count(strtolower($dupa), '</main') !== 1) {
                    break;
                }
                foreach ($t['atribute'] as $a) {
                    if ($a[0] === 'id' && preg_match('~^[A-Za-z][A-Za-z0-9_\-]{0,63}$~', $a[1])) {
                        $idMain = $a[1];
                    }
                }
                $capat = strpos($inainte, '>', $poz);
                $atribute = implode('', array_map(static fn (array $a): string => ' ' . $a[0] . '="' . htmlspecialchars($a[1], ENT_QUOTES | ENT_HTML5) . '"',
                    array_filter($t['atribute'], static fn (array $a): bool => $a[0] !== 'id' && preg_match('~^[a-z][a-z0-9\-]*$~', $a[0]) === 1 && !str_starts_with($a[0], 'on'))));
                if ($capat === false) {
                    break;
                }
                $inainte = substr($inainte, 0, $poz) . '<div' . $atribute . '>' . substr($inainte, $capat + 1);
                $dupa = (string) preg_replace('~</main\s*>~i', '</div>', $dupa, 1);
                $deschise[$k]['nume'] = 'div';
                $areMain = true;
                break;
            }
            $inchid = implode('', array_map(static fn (array $t): string => '</' . $t['nume'] . '>', array_reverse($deschise)));
            $redeschid = implode('', array_map(static fn (array $t): string => '<' . $t['nume'] . implode('', array_map(
                static fn (array $a): string => ' ' . $a[0] . '="' . htmlspecialchars($a[1], ENT_QUOTES | ENT_HTML5) . '"',
                array_filter($t['atribute'], static fn (array $a): bool => $a[0] !== 'id' && preg_match('~^[a-z][a-z0-9\-]*$~', $a[0]) === 1 && !str_starts_with($a[0], 'on')))) . '>', $deschise));
            $zona = $areMain ? 'main' : 'div';
            $corp .= $tema($inainte . $inchid)
                . '<' . $zona . ($idMain !== null ? ' id="' . $idMain . '"' : '') . ' class="pg-nucleu pg-nucleu--continut" data-pg-nucleu="continut"><div class="pg-nucleu-latime">' . $f->continutZona . '</div></' . $zona . '>'
                . $tema($redeschid . $dupa, ' pg-tema--dupa');
        } else {
            $corp .= $tema($interior);
        }
        // Zona de jos e reperul de subsol al paginii (<footer>); subsolurile decorative ale temelor sunt <div>.
        $corp .= '<footer class="pg-nucleu pg-nucleu--jos" data-pg-nucleu="jos"><div class="pg-nucleu-latime">' . $f->zona('jos') . '</div></footer>';
        // La o temă terță, zona nucleului are fontul de sistem al nucleului (un font terț poate desena glife goale).
        return substr($html, 0, $deschideri[0]['poz']) . '<body class="pg-corp' . ($terta ? ' pg-corp--terta' : '') . '">' . $corp . substr($html, $sfarsit);
    }

    /** Reîncărcarea fără JS: {secunde (1-60), url (o cale locală) sau null}, ori null. */
    private static function refresh(mixed $r): ?array
    {
        if (is_int($r)) {
            return ['secunde' => max(1, min(60, $r)), 'url' => null];
        }
        if (is_array($r) && is_int($r['secunde'] ?? null)) {
            $url = is_string($r['url'] ?? null) && str_starts_with($r['url'], '/') && !str_starts_with($r['url'], '//') ? $r['url'] : null;
            return ['secunde' => max(1, min(60, $r['secunde'])), 'url' => $url];
        }
        return null;
    }

    private function dirCache(string $nume): ?string
    {
        $d = $this->app->privat() . '/cache/twig/' . preg_replace('~[^A-Za-z0-9.\-]~', '_', $nume);
        if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) {
            return null;
        }
        return $d;
    }
}
