<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Cazo\Nucleu\Aplicatie;
use Twig\Source;

defined('CAZO') or exit;

/**
 * Validatorul complet al temelor. Același cod rulează pe server (la încărcare, la activare, după
 * fiecare actualizare a nucleului) și local, în `cazo-tema verifica`. Pașii, cu numele lor din raport:
 *
 *   fisiere      numele și locul fiecărui fișier (aceleași reguli ca la despachetare)
 *   manifest     theme.json după scheme/theme.schema.json, semver, `necesita`, licența, cheia `js`
 *   scheme       settings.schema.json, sections/*.json, templates/*.json, locales/*.json
 *   sabloane     compilarea fiecărui .twig sub politica sandboxului, cu fișierul și linia; variabilele din model
 *   compozitie   șabloanele obligatorii, secțiunile existente, funcțiile obligatorii din layout
 *   html         textul literal din șabloane: fără script, iframe, object, embed, base, meta http-equiv, form,
 *                atribute on*, style, javascript:, resurse de pe alte domenii
 *   css          ValidatorCss pe fiecare foaie, acoladele închise, url() spre fișiere existente, fonturile woff2
 *   js           la temele terțe, orice JavaScript e eroare; la cele oficiale, regulile de revizuire
 *   imagini      imaginile se citesc, SVG-urile trec prin curățare
 *   proba        randarea de probă (ProbaTema): fixturile normale și extreme, RO și EN, fiecare pagină
 *   limbi        cheile din ro.json și en.json identice; fără „—”; fără Ș/Ț cu sedilă
 *   fonturi      fișiere WOFF2 cu glifele U+0218-U+021B
 *   bugete       CSS ≤ 60 KB gzip, JS ≤ 30 KB gzip, fonturi ≤ 200 KB pe pagină
 */
final class Validator
{
    public const CSS_KB_GZ = 60;
    public const JS_KB_GZ = 30;
    public const FONTURI_KB = 200;

    /**
     * Imaginile: cel mult 16 megapixeli (de exemplu 4000 x 4000) și 6000 px pe o latură. GD decodează imaginea întreagă
     * în memorie (aprox. 4 octeți pe pixel), deci limita se verifică din antet (getimagesize), înainte de decodare.
     */
    public const PIXELI_MAX = 16_000_000;
    public const LATURA_MAX = 6000;

    /** Mărimea unei imagini se încadrează în limite (lățimea și înălțimea din antetul ei). */
    public static function imagineIncape(int $latime, int $inaltime): bool
    {
        return $latime >= 1 && $inaltime >= 1 && $latime <= self::LATURA_MAX && $inaltime <= self::LATURA_MAX && $latime * $inaltime <= self::PIXELI_MAX;
    }

    /** Variabilele modelului de vizualizare, plus `sectiune` (în secțiuni) și `loop` (în bucle). */
    /**
     * Variabilele pe care le poate citi o temă: contextul randării (scheme/model-vedere-v1.json) și sloturile modelului de
     * conținut citite opțional (galerie, prezentare, gazda, acces, parcare, recenzii, pret), plus cele
     * din această versiune (repere, repere_atribuire). Până le dă nucleul, temele le citesc cu „??” sau „is defined”.
     */
    public const VARIABILE = ['site', 'proprietate', 'camere', 'camera', 'pagina', 'continut', 'planuri', 'setari', 'meniu', 'cerere', 'vreme', 'preturi_sezon', 'soare',
        'repere', 'repere_atribuire', 'galerie', 'prezentare', 'gazda', 'acces', 'parcare', 'recenzii', 'pret', 'sectiune', 'loop', 'bloc'];

    public const SABLOANE_OBLIGATORII = ['acasa', 'camere', 'camera', 'pagina'];

    /** Cheile manifestului cunoscute de nucleu (celelalte trec, cu un avertisment). */
    public const CHEI_MANIFEST = ['slug', 'nume', 'versiune', 'autor', 'licenta', 'necesita', 'limbi', 'pentru', 'latime_rem', 'captura', 'componente', 'continut', 'fonturi', 'css', 'js',
        'stiluri', 'buget', 'privata', '$schema'];

    /** Captura temei (theme.json, cheia `captura`): cel mult 200 KB și 1600 px pe o latură. */
    public const CAPTURA_OCTETI = 200 * 1024;
    public const CAPTURA_LATURA = 1600;

    private Raport $r;
    private ?Tema $tema = null;

    /**
     * @param array{proba?:bool, lucru?:string, schelet?:string, slug?:string, radacina?:string, variante?:list<string>} $o
     *        proba: rulează randarea de probă (implicit da); lucru: dosarul ei temporar; schelet: biblioteca _schelet;
     *        slug: slugul generat de server pentru o temă terță.
     */
    public function __construct(private string $dir, private bool $oficiala, private array $o = [])
    {
        $this->r = new Raport();
    }

    public static function verifica(string $dir, bool $oficiala, array $o = []): Raport
    {
        return (new self(rtrim($dir, '/'), $oficiala, $o))->ruleaza();
    }

    private function radacina(): string
    {
        return $this->o['radacina'] ?? Aplicatie::radacina();
    }

    private function schelet(): string
    {
        return $this->o['schelet'] ?? $this->radacina() . '/teme/_schelet';
    }

    public function ruleaza(): Raport
    {
        if (!is_file($this->dir . '/theme.json')) {
            $this->r->eroare('manifest', 'theme.json lipsește.');
            return $this->r;
        }
        $fisiere = Active::lista($this->dir);
        $this->fisiere($fisiere);
        $this->manifest();
        if ($this->tema === null) {
            return $this->r;
        }
        $this->scheme($fisiere);
        $date = $this->sabloane($fisiere);
        $this->compozitie($date);
        $this->html($fisiere);
        $this->css($fisiere);
        $this->js($fisiere);
        $this->imagini($fisiere);
        $this->limbi($fisiere, $date);
        $this->fonturi();
        $this->bugete($fisiere);
        $blocante = ['manifest', 'scheme', 'sabloane', 'compozitie'];
        $poateRanda = true;
        foreach ($blocante as $p) {
            $poateRanda = $poateRanda && !$this->r->areEroareLa($p);
        }
        if (($this->o['proba'] ?? true) && $poateRanda) {
            (new ProbaTema($this->tema, $this->o['lucru'] ?? sys_get_temp_dir(), $this->o['variante'] ?? FixturiTeme::VARIANTE))->ruleaza($this->r);
        } elseif ($this->o['proba'] ?? true) {
            $this->r->avertisment('proba', 'Randarea de probă rulează după ce se rezolvă erorile din manifest, scheme, șabloane și compoziție.');
        }
        return $this->r;
    }

    // ---- pașii ----

    /** @param list<string> $fisiere */
    private function fisiere(array $fisiere): void
    {
        foreach ($fisiere as $f) {
            try {
                Despachetare::verificaNume($f);
                Despachetare::verificaLoc($f);
            } catch (EroareTema $e) {
                $this->r->eroare('fisiere', $e->getMessage(), $f);
            }
        }
        if (count($fisiere) > Despachetare::FISIERE_MAX) {
            $this->r->eroare('fisiere', 'Tema are peste ' . Despachetare::FISIERE_MAX . ' de fișiere.');
        }
        // Intrările de la rădăcină, și cele ascunse (fără acoladele din glob(), care lipsesc pe musl): întâi cele vizibile, apoi cele
        // cu punct, fiecare grupă în ordine, ca glob('{,.}*').
        $intrari = array_values(array_diff(scandir($this->dir) ?: [], ['.', '..']));
        usort($intrari, static fn (string $a, string $b): int => [$a[0] === '.', $a] <=> [$b[0] === '.', $b]);
        foreach ($intrari as $nume) {
            if (is_link($this->dir . '/' . $nume)) {
                $this->r->eroare('fisiere', 'Legătură simbolică: ' . $nume);
            }
        }
    }

    private function json(string $rel): mixed
    {
        $c = @file_get_contents($this->dir . '/' . $rel);
        if (!is_string($c)) {
            $this->r->eroare('scheme', 'Fișierul nu se poate citi.', $rel);
            return null;
        }
        try {
            return json_decode($c, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->r->eroare(str_ends_with($rel, 'theme.json') ? 'manifest' : 'scheme', 'JSON invalid: ' . $e->getMessage(), $rel);
            return null;
        }
    }

    private function manifest(): void
    {
        $m = $this->json('theme.json');
        if (!is_array($m)) {
            if ($this->r->erori === [] || !$this->r->areEroareLa('manifest')) {
                $this->r->eroare('manifest', 'theme.json nu e un obiect JSON.', 'theme.json');
            }
            return;
        }
        foreach (SchemaJson::din($this->radacina() . '/scheme/theme.schema.json')->valideaza($m) as $e) {
            $this->r->eroare('manifest', $e, 'theme.json');
        }
        foreach (array_keys($m) as $k) {
            if (!in_array($k, self::CHEI_MANIFEST, true)) {
                $this->r->avertisment('manifest', 'Cheie necunoscută, ignorată de nucleu: ' . $k, 'theme.json');
            }
        }
        if (!$this->oficiala && isset($m['js'])) {
            $this->r->eroare('js', 'Temele încărcate din panou sunt fără JavaScript: scoateți cheia „js”.', 'theme.json');
        }
        if (!$this->oficiala && isset($m['privata'])) {
            $this->r->avertisment('manifest', 'Cheia „privata” se aplică doar temelor livrate cu softul; o temă încărcată din panou e oricum doar a acestui site.', 'theme.json');
        }
        // `necesita.pragul`: numele vechi al cheii, primit la fel.
        $necesita = $m['necesita']['cazo'] ?? $m['necesita']['pragul'] ?? null;
        if (is_string($necesita) && !self::compatibil($necesita, Aplicatie::versiune())) {
            // Înainte de 1.0.0 nucleul e în dezvoltare: constrângerea se arată, fără să blocheze.
            $mesaj = 'Tema cere versiunea ' . $necesita . ' a softului; versiunea instalată e ' . Aplicatie::versiune() . '.';
            str_starts_with(Aplicatie::versiune(), '0.') ? $this->r->avertisment('manifest', $mesaj, 'theme.json') : $this->r->eroare('manifest', $mesaj, 'theme.json');
        }
        if ($this->r->areEroareLa('manifest')) {
            return;
        }
        try {
            $this->tema = Tema::din($this->dir, $this->schelet(), $this->oficiala, $this->oficiala ? null : ($this->o['slug'] ?? self::slugDin((string) $m['slug'])));
        } catch (\UnexpectedValueException $e) {
            $this->r->eroare('manifest', $e->getMessage(), 'theme.json');
            return;
        }
        foreach ($this->tema->fonturi() as $f) {
            if (!is_file($this->dir . '/' . $f['fisier'])) {
                $this->r->eroare('manifest', 'Fontul declarat lipsește: ' . $f['fisier'], 'theme.json');
            }
        }
        foreach ((array) ($m['css'] ?? []) as $c) {
            if (is_string($c) && !is_file($this->dir . '/' . $c)) {
                $this->r->eroare('manifest', 'Foaia de stil declarată lipsește: ' . $c, 'theme.json');
            }
        }
        $this->captura($m);
    }

    /**
     * Captura temei: fișierul declarat există, e chiar o imagine de tipul din nume (după conținut, nu după
     * extensie), are cel mult 200 KB și 1600 px pe o latură. O temă fără captură trece: cardul ei arată numele temei.
     */
    private function captura(array $m): void
    {
        $c = $m['captura'] ?? null;
        foreach (scandir($this->dir) ?: [] as $nume) {
            if (preg_match(Tema::CAPTURA, $nume) && $nume !== $c) {
                $this->r->avertisment('imagini', 'Captura din rădăcina temei rămâne nefolosită: declarați-o în theme.json, la cheia „captura”.', $nume);
            }
        }
        if (!is_string($c) || !preg_match(Tema::CAPTURA, $c)) {
            return;
        }
        $cale = $this->dir . '/' . $c;
        if (!is_file($cale) || is_link($cale)) {
            $this->r->eroare('manifest', 'Captura declarată lipsește: ' . $c, 'theme.json');
            return;
        }
        if ((int) filesize($cale) > self::CAPTURA_OCTETI) {
            $this->r->eroare('imagini', 'Captura temei are cel mult ' . intdiv(self::CAPTURA_OCTETI, 1024) . ' KB.', $c);
            return;
        }
        $i = @getimagesize($cale);
        $tip = ['webp' => IMAGETYPE_WEBP, 'png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG][strtolower(pathinfo($c, PATHINFO_EXTENSION))];
        if ($i === false || $i[2] !== $tip) {
            $this->r->eroare('imagini', 'Captura temei este o imagine WebP, PNG sau JPEG, cu extensia potrivită conținutului.', $c);
            return;
        }
        if ($i[0] < 1 || $i[1] < 1 || $i[0] > self::CAPTURA_LATURA || $i[1] > self::CAPTURA_LATURA) {
            $this->r->eroare('imagini', 'Captura temei are cel mult ' . self::CAPTURA_LATURA . ' px pe o latură (recomandat 600 x 750).', $c);
        }
    }

    /** Un slug curat din numele propus de autor (pentru probă și pentru teme/<slug>/). */
    public static function slugDin(string $s): string
    {
        $s = strtolower((string) preg_replace('~[^a-z0-9]+~i', '-', $s));
        $s = trim($s, '-');
        return $s === '' ? 'tema' : substr($s, 0, 48);
    }

    /** Constrângerea `necesita.cazo` (">=1.0 <2.0", "^1.2", "1.4") față de versiunea nucleului. */
    public static function compatibil(string $cerinta, string $versiune): bool
    {
        $v = self::numere($versiune);
        if (!preg_match_all('#(>=|<=|>|<|=|\^|~)?\s*([0-9]+(?:\.[0-9]+){0,2})#', $cerinta, $m, PREG_SET_ORDER)) {
            return false;
        }
        foreach ($m as [, $op, $nr]) {
            $c = self::numere($nr);
            $cmp = $v <=> $c;
            $ok = match ($op) {
                '>=' => $cmp >= 0,
                '<=' => $cmp <= 0,
                '>' => $cmp > 0,
                '<' => $cmp < 0,
                '^' => $cmp >= 0 && $v[0] === $c[0],
                '~' => $cmp >= 0 && $v[0] === $c[0] && $v[1] === $c[1],
                default => $cmp === 0 || ($op === '' && substr_count($nr, '.') < 2 && array_slice($v, 0, substr_count($nr, '.') + 1) === array_slice($c, 0, substr_count($nr, '.') + 1)),
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function numere(string $v): array
    {
        $p = array_map('intval', explode('.', (string) preg_replace('~[^0-9.].*$~', '', $v)));
        return [(int) ($p[0] ?? 0), (int) ($p[1] ?? 0), (int) ($p[2] ?? 0)];
    }

    /** @param list<string> $fisiere */
    private function scheme(array $fisiere): void
    {
        $s = SchemaJson::din($this->radacina() . '/scheme/settings.schema.json');
        if (is_file($this->dir . '/settings.schema.json')) {
            $d = $this->json('settings.schema.json');
            if ($d !== null) {
                foreach ($s->valideaza($d) as $e) {
                    $this->r->eroare('scheme', $e, 'settings.schema.json');
                }
                if (is_array($d)) {
                    $this->setari((array) ($d['setari'] ?? []), 'settings.schema.json');
                }
            }
        } else {
            $this->r->avertisment('scheme', 'settings.schema.json lipsește: tema folosește culorile și fonturile implicite ale nucleului.');
        }
        foreach ($fisiere as $f) {
            if (preg_match('~^sections/(.+)\.json$~', $f)) {
                $d = $this->json($f);
                if ($d !== null) {
                    foreach ($s->valideaza($d, '#/$defs/sectiune') as $e) {
                        $this->r->eroare('scheme', $e, $f);
                    }
                    if (is_array($d)) {
                        $this->setari((array) ($d['setari'] ?? []), $f);
                    }
                }
            } elseif (preg_match('~^templates/[^/]+\.json$~', $f)) {
                $d = $this->json($f);
                if ($d !== null) {
                    foreach ($s->valideaza($d, '#/$defs/sablon') as $e) {
                        $this->r->eroare('scheme', $e, $f);
                    }
                }
            } elseif (preg_match('~^sections/(.+)\.twig$~', $f, $m) && !in_array('sections/' . $m[1] . '.json', $fisiere, true)) {
                $this->r->eroare('scheme', 'Secțiunea nu are schema sections/' . $m[1] . '.json.', $f);
            } elseif (preg_match('~^locales/[^/]+\.json$~', $f)) {
                $d = $this->json($f);
                if ($d !== null && (!is_array($d) || array_is_list($d) && $d !== [])) {
                    $this->r->eroare('scheme', 'Localele sunt un obiect {cheie: text}.', $f);
                } elseif (is_array($d)) {
                    foreach ($d as $k => $v) {
                        if (!is_string($v) || !preg_match('~^[a-z0-9_.\-]{1,100}$~', (string) $k)) {
                            $this->r->eroare('scheme', 'Cheie sau text greșit în locale: ' . mb_substr((string) $k, 0, 60), $f);
                        }
                    }
                }
            }
        }
    }

    /** Valorile implicite ale setărilor: tipul potrivit, fonturile cunoscute, culorile hex. */
    private function setari(array $setari, string $f): void
    {
        $fonturi = array_merge(array_keys(Jetoane::FONTURI_SISTEM), array_column($this->tema?->fonturi() ?? [], 'familie'));
        $iduri = [];
        foreach ($setari as $x) {
            if (!is_array($x) || !is_string($x['id'] ?? null)) {
                continue;
            }
            if (isset($iduri[$x['id']])) {
                $this->r->eroare('scheme', 'Setare repetată: ' . $x['id'], $f);
            }
            $iduri[$x['id']] = true;
            $impl = $x['implicit'] ?? null;
            if ($impl === null) {
                continue;
            }
            $tip = (string) ($x['tip'] ?? '');
            $ok = match ($tip) {
                'color' => is_string($impl) && (bool) preg_match('~^#[0-9a-fA-F]{6}$~', $impl),
                'font' => is_string($impl) && in_array($impl, $fonturi, true),
                'range' => is_int($impl) && $impl >= (int) ($x['min'] ?? PHP_INT_MIN) && $impl <= (int) ($x['max'] ?? PHP_INT_MAX),
                'checkbox' => is_bool($impl),
                'select', 'radio' => is_string($impl) && in_array($impl, SetariTema::valoriOptiuni($x), true),
                'url' => is_string($impl) && SetariTema::urlPermis($impl),
                default => true,
            };
            if (!$ok) {
                $this->r->eroare('scheme', 'Valoarea implicită a setării „' . $x['id'] . '” nu se potrivește cu tipul ' . $tip . '.', $f);
            }
        }
    }

    /**
     * Compilarea fiecărui șablon sub politica sandboxului; erorile cu fișierul și linia.
     * @param list<string> $fisiere
     * @return array<string, array> datele adunate de VizitatorRaport, pe șablon (calea relativă)
     */
    private function sabloane(array $fisiere): array
    {
        $env = MediuSandbox::creeaza($this->dir, $this->schelet(), null, null);
        $viz = new VizitatorRaport();
        $env->addNodeVisitor($viz);
        foreach ($fisiere as $f) {
            if (!str_ends_with($f, '.twig')) {
                continue;
            }
            Garda::porneste();
            try {
                $sursa = (string) file_get_contents($this->dir . '/' . $f);
                $env->compileSource(new Source($sursa, $f, $this->dir . '/' . $f));
                // Încărcarea verifică și politica (tagurile, filtrele și funcțiile permise).
                $env->load($f);
            } catch (\Twig\Error\Error $e) {
                $this->r->eroare('sabloane', self::mesajTwig($e), $f, $e->getTemplateLine() > 0 ? $e->getTemplateLine() : null);
            } catch (LimitaDepasita $e) {
                $this->r->eroare('sabloane', 'Șablonul depășește limitele compilării: ' . $e->getMessage(), $f);
            } finally {
                Garda::opreste();
            }
        }
        // Variabilele: doar cele din modelul de vizualizare și cele definite în temă (set, for, with, include ... with).
        $definite = array_fill_keys(self::VARIABILE, true);
        foreach ($viz->date as $d) {
            $definite += $d['definite'];
        }
        // `true` e testul pe care Twig 3.30 îl pune singur la {% if x %}; nu vine din textul temei.
        $permise = ['filtru' => Politica::FILTRE, 'functie' => Politica::FUNCTII, 'test' => array_merge(Politica::TESTE, ['true', 'false']), 'tag' => array_merge(Politica::TAGURI, ['else', 'elseif'])];
        $nume = ['filtru' => 'Filtrul', 'functie' => 'Funcția', 'test' => 'Testul', 'tag' => 'Tagul'];
        foreach ($viz->date as $sablon => $d) {
            foreach ($d['folosite'] as [$fel, $n, $linie]) {
                if (!in_array($n, $permise[$fel], true)) {
                    $this->r->eroare('sabloane', $nume[$fel] . ' „' . $n . '” e interzis în teme.', $sablon, $linie);
                }
            }
        }
        foreach ($viz->date as $sablon => $d) {
            $raportate = [];
            foreach ($d['citite'] as [$nume, $linie]) {
                if (!isset($definite[$nume]) && !isset($raportate[$nume]) && $nume !== 'cazo') {
                    $raportate[$nume] = true;
                    $this->r->eroare('sabloane', 'Variabila „' . $nume . '” lipsește din modelul de vizualizare.', $sablon, $linie);
                }
            }
            foreach ($d['include'] as [$tinta, $linie]) {
                $cale = str_starts_with($tinta, '@schelet/') ? $this->schelet() . '/' . substr($tinta, 9) : $this->dir . '/' . $tinta;
                if (!is_file($cale)) {
                    $this->r->eroare('sabloane', 'Șablonul inclus lipsește: ' . $tinta, $sablon, $linie);
                }
            }
        }
        return $viz->date;
    }

    /** Mesajul unei erori Twig, în română (raportul îl citește autorul temei); un mesaj necunoscut rămâne, cu prefix. */
    public static function mesajTwig(\Twig\Error\Error $e): string
    {
        $m = trim($e->getRawMessage());
        $sugestie = '';
        if (preg_match('~\s*Did you mean "([^"]+)"\?$~', $m, $x)) {
            $sugestie = ' Ați vrut să scrieți „' . $x[1] . '”?';
            $m = substr($m, 0, -strlen($x[0]));
        }
        $fel = ['filter' => 'un filtru cunoscut', 'function' => 'o funcție cunoscută', 'tag' => 'un tag cunoscut', 'test' => 'un test cunoscut'];
        $interzis = ['Filter' => 'Filtrul', 'Function' => 'Funcția', 'Tag' => 'Tagul', 'Test' => 'Testul'];
        $asteptat = ['end of print statement' => 'închiderea }}', 'end of statement block' => 'închiderea %}', 'name' => 'un nume', 'end of template' => 'sfârșitul șablonului'];
        $tipare = [
            '~^Unknown "([^"]+)" (filter|function|tag|test)\.?$~' => static fn (array $x): string => '„' . $x[1] . '” nu e ' . $fel[$x[2]] . '.',
            '~^(Filter|Function|Tag|Test) "([^"]+)" is not allowed\.?$~' => static fn (array $x): string => $interzis[$x[1]] . ' „' . $x[2] . '” e interzis în teme.',
            '~^Calling "([^"]+)" method on a "[^"]+" object is not allowed\.?$~' => static fn (array $x): string => 'Metoda „' . $x[1] . '” nu se poate chema în teme.',
            '~^Calling "([^"]+)" property on a "[^"]+" object is not allowed\.?$~' => static fn (array $x): string => 'Proprietatea „' . $x[1] . '” nu se poate citi în teme.',
            '~^Unexpected token "[^"]+" of value "([^"]*)" \("([^"]+)" expected(?: with value "[^"]*")?\)\.?$~' => static fn (array $x): string => 'Simbol neașteptat: „' . $x[1] . '”; aici trebuia ' . ($asteptat[$x[2]] ?? '„' . $x[2] . '”') . '.',
            '~^Unexpected token "[^"]+" of value "([^"]*)"\.?$~' => static fn (array $x): string => 'Simbol neașteptat: „' . $x[1] . '”.',
            '~^Unexpected "([^"]+)" tag \(expecting closing tag for the "([^"]+)" tag defined near line (\d+)\)\.?$~' => static fn (array $x): string => 'Tagul „' . $x[1] . '” apare înainte de închiderea tagului „' . $x[2] . '” (deschis pe rândul ' . $x[3] . ').',
            '~^Unclosed comment\.?$~' => static fn (array $x): string => 'Comentariul {# … #} nu e închis.',
            '~^Unclosed "([^"]+)"\.?$~' => static fn (array $x): string => '„' . $x[1] . '” nu e închis.',
            '~^Unexpected end of template\.?$~' => static fn (array $x): string => 'Șablonul se termină înainte să fie închise toate tagurile.',
            '~^Unexpected character "([^"]+)"\.?$~' => static fn (array $x): string => 'Caracter neașteptat: „' . $x[1] . '”.',
            '~^Variable "([^"]+)" does not exist\.?$~' => static fn (array $x): string => 'Variabila „' . $x[1] . '” lipsește.',
            '~^A template that extends another one cannot include content outside Twig blocks~' => static fn (array $x): string => 'Un șablon care extinde alt șablon are conținut doar în blocuri ({% block %}).',
        ];
        foreach ($tipare as $model => $f) {
            if (preg_match($model, $m, $x)) {
                return $f($x) . $sugestie;
            }
        }
        return 'Eroare de șablon (mesajul Twig): ' . $m . $sugestie;
    }

    /** @param array<string,array> $date */
    private function compozitie(array $date): void
    {
        $t = $this->tema;
        if (!is_file($this->dir . '/layout/baza.twig')) {
            $this->r->eroare('compozitie', 'layout/baza.twig lipsește.');
        } else {
            $layout = $date['layout/baza.twig']['cazo'] ?? [];
            foreach (['head', 'foot'] as $fn) {
                if (!isset($layout[$fn])) {
                    $this->r->eroare('compozitie', 'Layoutul trebuie să cheme cazo.' . $fn . '().', 'layout/baza.twig');
                }
            }
        }
        foreach (self::SABLOANE_OBLIGATORII as $s) {
            if (!is_file($this->dir . '/templates/' . $s . '.json')) {
                $this->r->eroare('compozitie', 'Șablonul obligatoriu templates/' . $s . '.json lipsește.');
                continue;
            }
            $j = json_decode((string) file_get_contents($this->dir . '/templates/' . $s . '.json'), true, 32);
            foreach ((array) ($j['sectiuni'] ?? []) as $i => $x) {
                $tip = is_array($x) ? ($x['tip'] ?? null) : null;
                if (!is_string($tip) || $t->sectiune($tip) === null) {
                    $this->r->eroare('compozitie', 'Secțiunea „' . (is_string($tip) ? $tip : '?') . '” lipsește din temă și din _schelet.', 'templates/' . $s . '.json');
                }
            }
        }
    }

    /**
     * Textul literal din șabloane: din tokenurile lexerului Twig (HtmlTaguri::textLiteral), citit ca
     * HTML5 (HtmlTaguri::probleme): elementele și atributele interzise, plus orice construcție Twig care scrie ceva în
     * locul numelui unui tag sau al unui atribut (de exemplu „<meta {{ 'http-equiv' }}=…>”), pe care randarea de probă
     * n-ar vedea-o sub o condiție pe fixturi. Randarea de probă rămâne plasa de siguranță (ProbaTema::verificaHtml).
     * @param list<string> $fisiere
     */
    private function html(array $fisiere): void
    {
        $env = MediuSandbox::creeaza($this->dir, $this->schelet(), null, null);
        foreach ($fisiere as $f) {
            if (!str_ends_with($f, '.twig')) {
                continue;
            }
            try {
                $text = HtmlTaguri::textLiteral($env, (string) file_get_contents($this->dir . '/' . $f), $f, $linii, $extra);
            } catch (\Twig\Error\Error) {
                continue; // eroarea de sintaxă o raportează pasul „sabloane”
            }
            $vazute = [];
            $probleme = HtmlTaguri::probleme($text, false, $linii, $extra['nucleu']);
            // Capturile {% set x %}: HTML-ul lor se scrie abia unde e folosit, deci se citește separat și nu se termină într-un tag deschis.
            foreach ($extra['capturi'] as [$corp, $liniiCorp]) {
                array_push($probleme, ...HtmlTaguri::probleme($corp, false, $liniiCorp));
                if (HtmlTaguri::citeste($corp, $liniiCorp)['deschis']) {
                    $probleme[] = ['Corpul unui {% set %} se termină într-un tag deschis: tagul se completează abia la folosire, nevăzut de verificare. Închideți tagul în captură.', $liniiCorp[0][1] ?? 1];
                }
                // Folosită într-o valoare între ghilimele, o captură cu ghilimele în text ar închide valoarea și ar adăuga atribute.
                if (preg_match('~["\x27]~', (string) preg_replace('~<[^>]*>~', '', $corp))) {
                    $probleme[] = ['Corpul unui {% set %} are ghilimele în afara tagurilor: folosit într-o valoare de atribut, ar închide-o. Scrieți textul fără ghilimele drepte (de exemplu „ ”).', $liniiCorp[0][1] ?? 1];
                }
            }
            // Un șablon inclus care se termină într-un tag deschis se completează în șablonul care îl include, nevăzut de verificare.
            if (HtmlTaguri::citeste($text, $linii)['deschis']) {
                $probleme[] = ['Șablonul se termină într-un tag deschis: închideți tagul în același fișier.', ($linii === [] ? 1 : $linii[count($linii) - 1][1])];
            }
            array_push($probleme, ...$extra['probleme']);
            foreach ($probleme as [$mesaj, $linie]) {
                if (!isset($vazute[$mesaj . '#' . $linie])) {
                    $vazute[$mesaj . '#' . $linie] = true;
                    $this->r->eroare('html', $mesaj, $f, $linie);
                }
            }
        }
    }

    /**
     * Clasele de pe <body> din layout (ajung pe containerul .pg-tema): cele scrise în text și cele din șirurile unei
     * expresii Twig; o clasă lipită de o expresie („cs-corp--{{ pagina.tip }}”) devine prefix.
     * @return array{0:list<string>, 1:list<string>} clasele și prefixele
     */
    private function claseCorp(): array
    {
        $layout = (string) @file_get_contents($this->dir . '/layout/baza.twig');
        if (!preg_match('~<body\b[^>]*\bclass\s*=\s*"([^"]*)"~i', $layout, $m) && !preg_match("~<body\\b[^>]*\\bclass\\s*=\\s*'([^']*)'~i", $layout, $m)) {
            return [[], []];
        }
        $clase = [];
        $prefixe = [];
        $text = (string) preg_replace_callback('~\{\{.*?\}\}|\{%.*?%\}~s', static function (array $x) use (&$clase): string {
            preg_match_all('~[\'"]([A-Za-z][\w\-]*)[\'"]~', $x[0], $s);
            array_push($clase, ...$s[1]);
            return "\x01";
        }, $m[1]);
        foreach (preg_split('~\s+~', $text, -1, PREG_SPLIT_NO_EMPTY) as $bucata) {
            if (preg_match('~^([A-Za-z][\w\-]*)\x01~', $bucata, $p)) {
                $prefixe[] = $p[1];
            } elseif (preg_match('~^[A-Za-z][\w\-]*$~', $bucata)) {
                $clase[] = $bucata;
            }
        }
        return [array_values(array_unique($clase)), array_values(array_unique($prefixe))];
    }

    /** @param list<string> $fisiere */
    private function css(array $fisiere): void
    {
        foreach ($fisiere as $f) {
            if (!str_ends_with($f, '.css')) {
                continue;
            }
            $css = (string) file_get_contents($this->dir . '/' . $f);
            foreach (ValidatorCss::probleme($css) as $p) {
                $this->r->eroare('css', $p, $f);
            }
            foreach (ValidatorCss::structura($css) as $p) {
                $this->r->eroare('css', $p, $f);
            }
            foreach (ValidatorCss::peCorp($css, ...$this->claseCorp()) as $p) {
                $this->r->eroare('css', $p, $f);
            }
            $dirCss = dirname($f);
            $tok = ValidatorCss::tokenizeaza($css);
            foreach (ValidatorCss::adrese($tok) as $u) {
                if ($u === '' || str_starts_with($u, '#') || preg_match('~^[a-z]+:|^//|^/~i', $u)) {
                    continue;
                }
                $rel = Fonturi::normalizeaza($dirCss . '/' . (string) preg_replace('~[?#].*$~', '', $u));
                if ($rel === null || !str_starts_with($rel, 'assets/') || !is_file($this->dir . '/' . $rel)) {
                    $this->r->eroare('css', 'url() spre un fișier care lipsește din temă: ' . $u, $f);
                }
            }
            if (preg_match_all('~@font-face\s*\{([^}]*)\}~i', $tok['cod'], $ff)) {
                foreach ($ff[1] as $corp) {
                    if (preg_match('~\blocal\s*\(~i', $corp)) {
                        $this->r->avertisment('css', '@font-face cu local(): fontul se ia doar din fișierele temei.', $f);
                    }
                    $woff2 = array_filter(ValidatorCss::adrese(['cod' => $corp, 'siruri' => $tok['siruri']]), static fn (string $u): bool => (bool) preg_match('~\.woff2$~i', (string) preg_replace('~[?#].*$~', '', $u)));
                    if ($woff2 === []) {
                        $this->r->eroare('css', '@font-face fără fișier woff2 local.', $f);
                    }
                }
            }
        }
    }

    /** @param list<string> $fisiere */
    private function js(array $fisiere): void
    {
        foreach ($fisiere as $f) {
            if (!preg_match('~\.m?js$~i', $f)) {
                continue;
            }
            if (!$this->oficiala) {
                $this->r->eroare('js', 'Temele încărcate din panou sunt fără JavaScript: scoateți fișierul.', $f);
                continue;
            }
            $js = (string) file_get_contents($this->dir . '/' . $f);
            $reguli = [
                '~\beval\s*\(~' => 'eval',
                '~\bnew\s+Function\b~' => 'new Function',
                '~document\s*\.\s*write~' => 'document.write',
                '~\bimport\s*\(\s*[\'"`](https?:)?//~' => 'import() cu adresă externă',
                '~\b(fetch|sendBeacon)\s*\(\s*[\'"`](https?:)?//~' => 'cerere spre alt domeniu',
                '~new\s+(XMLHttpRequest|WebSocket)\b~' => 'XMLHttpRequest sau WebSocket',
                '~\.innerHTML\s*=|insertAdjacentHTML~' => 'HTML construit din text',
                '~\.on[a-z]+\s*=~' => 'atribuire de eveniment on*',
            ];
            foreach ($reguli as $model => $nume) {
                if (preg_match($model, $js)) {
                    $this->r->eroare('js', 'Construcție respinsă la revizuire: ' . $nume . '.', $f);
                }
            }
        }
    }

    /** @param list<string> $fisiere */
    private function imagini(array $fisiere): void
    {
        foreach ($fisiere as $f) {
            if (!preg_match('~^(assets/img|previews)/.+\.(png|jpe?g|webp|avif|svg)$~i', $f, $m)) {
                continue;
            }
            $cale = $this->dir . '/' . $f;
            if (strtolower($m[2]) === 'svg') {
                try {
                    Svg::curata((string) file_get_contents($cale));
                } catch (EroareTema $e) {
                    $this->r->eroare('imagini', $e->getMessage(), $f);
                }
                continue;
            }
            $i = @getimagesize($cale);
            if ($i === false || !self::imagineIncape((int) $i[0], (int) $i[1])) {
                $this->r->eroare('imagini', 'Imaginea nu se poate citi sau depășește 16 megapixeli (de exemplu 4000 x 4000) sau 6000 px pe o latură.', $f);
            }
        }
    }

    /** @param list<string> $fisiere @param array<string,array> $date */
    private function limbi(array $fisiere, array $date): void
    {
        $chei = [];
        foreach (['ro', 'en'] as $l) {
            $f = 'locales/' . $l . '.json';
            if (!is_file($this->dir . '/' . $f)) {
                $this->r->eroare('limbi', 'Lipsesc textele în ' . ($l === 'ro' ? 'română' : 'engleză') . ': ' . $f);
                continue;
            }
            $d = json_decode((string) file_get_contents($this->dir . '/' . $f), true, 32);
            $chei[$l] = is_array($d) ? array_keys($d) : [];
        }
        if (isset($chei['ro'], $chei['en'])) {
            foreach (array_diff($chei['ro'], $chei['en']) as $k) {
                $this->r->eroare('limbi', 'Cheia „' . $k . '” lipsește din en.json.', 'locales/en.json');
            }
            foreach (array_diff($chei['en'], $chei['ro']) as $k) {
                $this->r->eroare('limbi', 'Cheia „' . $k . '” lipsește din ro.json.', 'locales/ro.json');
            }
        }
        // Textele: fără linia lungă și fără Ș/Ț cu sedilă, în locale și în etichetele din JSON.
        foreach ($fisiere as $f) {
            if (!str_ends_with($f, '.json')) {
                continue;
            }
            $c = (string) file_get_contents($this->dir . '/' . $f);
            $d = json_decode($c, true, 32);
            if (!is_array($d)) {
                continue;
            }
            array_walk_recursive($d, function (mixed $v) use ($f): void {
                if (!is_string($v)) {
                    return;
                }
                if (str_contains($v, "\u{2014}")) {
                    $this->r->eroare('limbi', 'Text cu linia lungă „—”: ' . mb_substr($v, 0, 60), $f);
                }
                if (preg_match('~[\x{015E}\x{015F}\x{0162}\x{0163}]~u', $v)) {
                    $this->r->eroare('limbi', 'Ș sau Ț cu sedilă (folosiți virgula de dedesubt, U+0218-U+021B): ' . mb_substr($v, 0, 60), $f);
                }
            });
        }
        // Cheile t() din șabloane: lipsa unei chei arată cheia în pagină.
        $toate = array_merge(array_keys($this->tema?->locale('ro') ?? []), array_keys($this->tema?->locale('en') ?? []));
        $toate = array_fill_keys($toate, true);
        foreach ($date as $sablon => $d) {
            foreach ($d['chei'] as [$cheie, $linie]) {
                if (!isset($toate[$cheie])) {
                    $this->r->avertisment('limbi', 'Cheia t("' . $cheie . '") lipsește din locale; pagina arată cheia.', $sablon, $linie);
                }
            }
        }
    }

    private function fonturi(): void
    {
        $familii = [];
        foreach ($this->tema->fonturi() as $f) {
            $cale = $this->dir . '/' . $f['fisier'];
            if (!is_file($cale)) {
                continue;
            }
            $date = (string) file_get_contents($cale);
            $forma = Fonturi::forma($date);
            if (!$forma['ok']) {
                $this->r->eroare('fonturi', ucfirst($forma['motiv']) . '.', $f['fisier']);
                continue;
            }
            $familii[$f['familie']][] = ['fisier' => $f['fisier'], 'glife' => Fonturi::areGlifeRomanesti($date)];
        }
        if ($familii === []) {
            return;
        }
        $intervale = [];
        foreach ($this->tema->css() as $c) {
            if (is_file($this->dir . '/' . $c)) {
                $intervale += Fonturi::intervaleCss((string) file_get_contents($this->dir . '/' . $c), dirname($c));
            }
        }
        $faraBrotli = false;
        foreach ($familii as $familie => $lista) {
            $glife = array_column($lista, 'glife');
            if (in_array(true, $glife, true)) {
                continue;
            }
            if (!in_array(null, $glife, true)) {
                $this->r->eroare('fonturi', 'Familia „' . $familie . '” nu are glifele Ș ș Ț ț (U+0218-U+021B).');
                continue;
            }
            $faraBrotli = true;
            $acoperit = false;
            foreach ($lista as $x) {
                $acoperit = $acoperit || (array_key_exists($x['fisier'], $intervale) && Fonturi::acopera($intervale[$x['fisier']]));
            }
            if (!$acoperit) {
                $this->r->eroare('fonturi', 'Familia „' . $familie . '” nu declară în CSS un @font-face care acoperă U+0218-U+021B (Ș ș Ț ț).');
            }
        }
        if ($faraBrotli) {
            $this->r->avertisment('fonturi', 'Serverul nu are extensia PHP brotli: glifele Ș ș Ț ț s-au verificat după intervalul unicode-range declarat în CSS.');
        }
    }

    /** @param list<string> $fisiere */
    private function bugete(array $fisiere): void
    {
        $css = 0;
        foreach ($this->tema->css() as $c) {
            if (is_file($this->dir . '/' . $c)) {
                $css += strlen((string) gzencode((string) file_get_contents($this->dir . '/' . $c), 9));
            }
        }
        $js = 0;
        foreach ($fisiere as $f) {
            if (preg_match('~^assets/js/.+\.m?js$~', $f)) {
                $js += strlen((string) gzencode((string) file_get_contents($this->dir . '/' . $f), 9));
            }
        }
        $fonturi = 0;
        foreach ($this->tema->fonturi() as $f) {
            $fonturi += (int) @filesize($this->dir . '/' . $f['fisier']);
        }
        $this->r->masuratori['css_kb_gz'] = round($css / 1024, 1);
        $this->r->masuratori['js_kb_gz'] = round($js / 1024, 1);
        $this->r->masuratori['fonturi_kb'] = round($fonturi / 1024, 1);
        if ($css > self::CSS_KB_GZ * 1024) {
            $this->r->eroare('bugete', 'CSS-ul temei are ' . round($css / 1024, 1) . ' KB comprimat; bugetul e ' . self::CSS_KB_GZ . ' KB.');
        }
        if ($js > self::JS_KB_GZ * 1024) {
            $this->r->eroare('bugete', 'JavaScript-ul temei are ' . round($js / 1024, 1) . ' KB comprimat; bugetul e ' . self::JS_KB_GZ . ' KB.');
        }
        // Fonturile pe pagină: toate fonturile declarate, ca limită de sus (o pagină poate cere oricare dintre ele).
        if ($fonturi > self::FONTURI_KB * 1024) {
            $this->r->eroare('bugete', 'Fonturile temei au ' . round($fonturi / 1024, 1) . ' KB; bugetul pe pagină e ' . self::FONTURI_KB . ' KB.');
        }
        $b = $this->tema->manifest()['buget'] ?? [];
        if (is_int($b['css_kb_gz'] ?? null) && $css > $b['css_kb_gz'] * 1024) {
            $this->r->avertisment('bugete', 'CSS-ul depășește bugetul declarat în theme.json (' . $b['css_kb_gz'] . ' KB).', 'theme.json');
        }
        if (is_int($b['js_kb_gz'] ?? null) && $js > $b['js_kb_gz'] * 1024) {
            $this->r->avertisment('bugete', 'JavaScript-ul depășește bugetul declarat în theme.json (' . $b['js_kb_gz'] . ' KB).', 'theme.json');
        }
    }
}
