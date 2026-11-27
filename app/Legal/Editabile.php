<?php

declare(strict_types=1);

namespace Cazo\Legal;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cai;
use Cazo\Nucleu\Config;
use Cazo\Nucleu\Url;

defined('CAZO') or exit;

/**
 * Paginile legale și subsolul, editabile integral din panou.
 *
 * Fiecare pagină legală și subsolul (conținutul zonei de jos a nucleului: contactul, datele legale, legăturile legale,
 * SAL, rândul cu „©”) pornesc de la modelul softului, scris cu câmpuri între acolade ({denumire}, {email}...), pe care
 * nucleul le completează la afișare. Gazda poate schimba integral textul; fiecare salvare e o versiune nouă în
 * `texte_legale` (format `integral`), cu modelul de atunci alături, ca panoul să arate când s-a schimbat modelul la o
 * actualizare. Revenirea la model e tot o versiune (format `model`). Versiunile vechi ale paragrafelor adăugate (format
 * `adaugat`) se afișează ca înainte, până la prima salvare integrală.
 *
 * Garanțiile rămân ale nucleului: zona de jos și conținutul paginilor sunt ale lui, textul e text simplu escapat,
 * legăturile au doar adrese http(s), căi ale site-ului, mailto: sau tel:, iar poarta de vânzare
 * citește datele legale (BlocLegal), nu aceste texte.
 */
final class Editabile
{
    /** Paginile și subsolul, în ordinea din panou. */
    public const FELURI = ['termeni', 'confidentialitate', 'accesibilitate', 'date_legale', 'subsol'];

    /** Grupurile subsolului: contactul (primul rând e titlul), datele legale, legăturile, SAL, rândul cu „©”. */
    public const GRUPURI = ['contact', 'date', 'legaturi', 'sal', 'an'];

    public const MAXIM = 30000;

    public const MAXIM_GRUP = 3000;

    /** Valorile de probă ale câmpurilor, la verificarea adreselor din panou. */
    private const PROBA = ['email' => 'a@b.ro', 'telefon' => '0700000000', 'telefon_tel' => '+40700000000', 'site' => 'https://a.ro'];

    public function __construct(private Aplicatie $app)
    {
    }

    /** Versiunea activă a paginii sau a subsolului. @return array{versiune:int, format:string, corp:string, model:?string}|null */
    public function activ(string $fel, string $limba): ?array
    {
        $r = $this->app->baza()->rand('SELECT versiune, format, corp, model FROM {texte_legale} WHERE fel = ? AND limba = ? AND activ = 1 ORDER BY versiune DESC LIMIT 1', [$fel, $limba]);
        if ($r === null) {
            return null;
        }
        return ['versiune' => (int) $r['versiune'], 'format' => (string) ($r['format'] ?? 'adaugat'), 'corp' => (string) $r['corp'], 'model' => $r['model'] !== null ? (string) $r['model'] : null];
    }

    /** Textul editat activ, când gazda a salvat o versiune integrală; altfel null (se afișează modelul). */
    public function integral(string $fel, string $limba): ?string
    {
        $a = $this->activ($fel, $limba);
        return $a !== null && $a['format'] === 'integral' ? $a['corp'] : null;
    }

    /**
     * Modelul în forma editabilă. Pagina: introducerea, apoi secțiunile („## Titlu” și paragrafele, despărțite de un
     * rând gol). Subsolul: JSON-ul grupurilor, fiecare cu un rând pe linie („text | adresă” pentru o legătură).
     */
    public function model(string $fel, string $limba): string
    {
        return $this->modelCu($fel, $limba, BlocLegal::persoanaFizica($this->app), BlocLegal::accesibilitate($this->app));
    }

    /**
     * Modelul pentru o formă de organizare, o pagină Accesibilitate publicată sau ascunsă și adresele cu sau
     * fără rescriere (config), oricare ar fi setările.
     */
    private function modelCu(string $fel, string $limba, bool $pf, bool $accesibilitate, ?bool $rescriere = null): string
    {
        if ($fel === 'subsol') {
            return self::json($this->modelSubsol($limba, $accesibilitate, $rescriere));
        }
        $t = $this->app->traducator($limba);
        $blocuri = [];
        if ($t->are("legal.$fel.intro")) {
            $blocuri[] = $t->t("legal.$fel.intro");
        }
        for ($i = 1; $i <= PaginiLegale::SECTIUNI_MAX && $t->are("legal.$fel.$i.titlu"); $i++) {
            $blocuri[] = '## ' . $t->t("legal.$fel.$i.titlu");
            for ($j = 1; $j <= 8 && $t->are("legal.$fel.$i.p$j"); $j++) {
                $blocuri[] = $t->t(PaginiLegale::cheiePf($t, "legal.$fel.$i.p$j", $pf));
            }
        }
        return implode("\n\n", $blocuri);
    }

    /**
     * @param ?bool $accesibilitate cu legătura spre Accesibilitate; null: după setarea site-ului
     * @param ?bool $rescriere adresele cu rescriere (/termeni) sau fără (/?r=%2Ftermeni); null: după config
     * @return array<string,string>
     */
    public function modelSubsol(string $limba, ?bool $accesibilitate = null, ?bool $rescriere = null): array
    {
        $t = $this->app->traducator($limba);
        $accesibilitate ??= BlocLegal::accesibilitate($this->app);
        $config = $this->app->config();
        $url = $rescriere === null || $rescriere === $config->rescriere()
            ? $this->app->url()
            : new Url(new Config(['rescriere' => $rescriere] + $config->toate(), $config->privat()));
        $legaturi = [];
        foreach (Cai::LEGALE as $n) {
            if ($n === 'accesibilitate' && !$accesibilitate) {
                continue;
            }
            $legaturi[] = $t->t('legal.link.' . $n) . ' | ' . Cai::legala($url, $n, $limba);
        }
        // datele firmei, scurte, pe un singur rând al subsolului, ca în componenta nucleului (BlocLegal::date).
        // clasificarea vine din date (câte o bucată pe document, cu tipul, categoria și camerele).
        $date = ['{denumire}', $t->t('legal.scurt.cui', ['cui' => '{cui}']), '{registrul_comertului}', $t->t('legal.scurt.sediu', ['eticheta' => '{sediu_eticheta}', 'sediu' => '{sediu}']),
            $t->t('legal.scurt.capital', ['suma' => '{capital_social}']), '{clasificare}'];
        return [
            // Numele proprietății, adresa publică, e-mailul; telefonul apare doar cu bifa „Arătați telefonul pe site” (
            // fără ea, {telefon} e gol, iar rândul lui lipsește).
            'contact' => implode("\n", ['{nume}', '{adresa}', '{email} | mailto:{email}', '{telefon} | tel:{telefon_tel}']),
            'date' => implode("\n", $date),
            'legaturi' => implode("\n", $legaturi),
            'sal' => $t->t('legal.sal_alt') . ' | ' . BlocLegal::SAL,
            'an' => $t->t('legal.an'),
        ];
    }

    /** Textul de pornire al editorului: versiunea integrală, altfel modelul (cu paragrafele adăugate înainte). */
    public function deEditat(string $fel, string $limba): string
    {
        $a = $this->activ($fel, $limba);
        if ($a !== null && $a['format'] === 'integral') {
            return $a['corp'];
        }
        $m = $this->model($fel, $limba);
        if ($a !== null && $a['format'] === 'adaugat' && trim($a['corp']) !== '' && $fel !== 'subsol') {
            $m .= "\n\n## " . $this->app->traducator($limba)->t('legal.adaugat_gazda') . "\n\n" . $a['corp'];
        }
        return $m;
    }

    /**
     * Modelul s-a schimbat de la ultima salvare integrală (o actualizare a softului)? Întoarce modelul de atunci, pentru
     * diferențe; null când gazda folosește modelul sau când modelul a rămas același. Modelul salvat se compară cu
     * modelul de acum pentru oricare setări ale site-ului (forma de organizare, pagina Accesibilitate, rescrierea
     * adreselor din config): o setare schimbată după salvare schimbă modelul afișat, dar nu e o actualizare.
     */
    public function modelVechi(string $fel, string $limba): ?string
    {
        $a = $this->activ($fel, $limba);
        if ($a === null || $a['format'] !== 'integral' || $a['model'] === null) {
            return null;
        }
        foreach ([false, true] as $pf) {
            foreach ([false, true] as $accesibilitate) {
                foreach ($fel === 'subsol' ? [false, true] : [null] as $rescriere) {
                    if ($a['model'] === $this->modelCu($fel, $limba, $pf, $accesibilitate, $rescriere)) {
                        return null;
                    }
                }
            }
        }
        return $a['model'];
    }

    /** @return list<array{fel:string, limba:string}> paginile al căror model s-a schimbat de la salvarea gazdei */
    public function modeleSchimbate(): array
    {
        $r = [];
        foreach ($this->app->baza()->toate("SELECT DISTINCT fel, limba FROM {texte_legale} WHERE activ = 1 AND format = 'integral' ORDER BY fel, limba") as $x) {
            if (in_array($x['fel'], self::FELURI, true) && in_array($x['limba'], ['ro', 'en'], true) && $this->modelVechi((string) $x['fel'], (string) $x['limba']) !== null) {
                $r[] = ['fel' => (string) $x['fel'], 'limba' => (string) $x['limba']];
            }
        }
        return $r;
    }

    /** O versiune nouă: textul gazdei (`integral`) sau revenirea la model (`model`). Întoarce numărul versiunii. */
    public function salveaza(string $fel, string $limba, string $format, string $corp, int $utilizator): int
    {
        $model = $this->model($fel, $limba);
        for ($i = 0; ; $i++) {
            try {
                return $this->app->baza()->tranzactie(function () use ($fel, $limba, $format, $corp, $model, $utilizator): int {
                    $b = $this->app->baza();
                    $v = (int) $b->valoare('SELECT COALESCE(MAX(versiune), 0) + 1 FROM {texte_legale} WHERE fel = ? AND limba = ?', [$fel, $limba]);
                    $b->executa('UPDATE {texte_legale} SET activ = 0 WHERE fel = ? AND limba = ?', [$fel, $limba]);
                    $b->insereaza('texte_legale', ['fel' => $fel, 'limba' => $limba, 'versiune' => $v, 'corp' => $format === 'model' ? '' : $corp, 'format' => $format,
                        'model' => $model, 'aprobat_la' => $this->app->ceas()->moment(), 'aprobat_de' => $utilizator, 'activ' => 1]);
                    return $v;
                });
            } catch (\PDOException $e) {
                // Cheia unică (fel, limbă, versiune) prinde două salvări simultane: a doua reia cu versiunea următoare.
                if (!$this->app->baza()->esteDuplicat($e) || $i >= 3) {
                    throw $e;
                }
            }
        }
    }

    // ---- Paginile ----

    /**
     * Textul unei pagini, desfăcut: introducerea (blocurile dinaintea primului titlu) și secțiunile, cu câmpurile
     * completate. Introducerea are câte un paragraf pentru fiecare bloc despărțit de un rând gol, ca secțiunile.
     * @param array<string,string> $param @return array{intro:list<string>, sectiuni:list<array{titlu:string, paragrafe:list<string>}>}
     */
    public static function desfacePagina(string $text, array $param): array
    {
        $intro = [];
        $sectiuni = [];
        foreach (preg_split('~\n[ \t]*\n+~', trim(str_replace("\r", '', $text))) ?: [] as $bloc) {
            $bloc = trim($bloc);
            if ($bloc === '') {
                continue;
            }
            if (str_starts_with($bloc, '## ') || $bloc === '##') {
                $linii = explode("\n", $bloc, 2);
                $sectiuni[] = ['titlu' => self::completeaza(trim(substr($linii[0], 2)), $param), 'paragrafe' => []];
                if (trim($linii[1] ?? '') !== '') {
                    $sectiuni[count($sectiuni) - 1]['paragrafe'][] = self::completeaza(trim($linii[1]), $param);
                }
                continue;
            }
            // Un paragraf rămas gol după completare (un câmp fără valoare la setările site-ului) nu apare.
            $text = trim(self::completeaza($bloc, $param));
            if ($text === '') {
                continue;
            }
            if ($sectiuni === []) {
                $intro[] = $text;
            } else {
                $sectiuni[count($sectiuni) - 1]['paragrafe'][] = $text;
            }
        }
        return ['intro' => $intro, 'sectiuni' => $sectiuni];
    }

    /** Câmpurile cunoscute se completează; celelalte acolade rămân text. @param array<string,string> $param */
    public static function completeaza(string $text, array $param): string
    {
        return (string) preg_replace_callback('~\{([a-z_]+)\}~', static fn (array $m): string => array_key_exists($m[1], $param) ? (string) $param[$m[1]] : $m[0], $text);
    }

    // ---- Subsolul ----

    /** Grupurile subsolului din JSON-ul salvat (cheile cunoscute, text). @return array<string,string> */
    public static function grupuri(string $json): array
    {
        $d = json_decode($json, true);
        $r = [];
        foreach (self::GRUPURI as $g) {
            $r[$g] = is_array($d) && is_string($d[$g] ?? null) ? $d[$g] : '';
        }
        return $r;
    }

    /** @param array<string,string> $grupuri */
    public static function json(array $grupuri): string
    {
        $r = [];
        foreach (self::GRUPURI as $g) {
            $r[$g] = (string) ($grupuri[$g] ?? '');
        }
        return json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Rândurile unui grup: textul și adresa legăturii (după ultimul „ | ”). Un rând cu un câmp gol (de exemplu {cui},
     * cât CUI-ul lipsește) se ascunde, ca rândurile modelului. @param array<string,string> $param
     * @return list<array{text:string, url:?string}>
     */
    public static function randuri(string $grup, array $param): array
    {
        $r = [];
        foreach (explode("\n", str_replace("\r", '', $grup)) as $linie) {
            $linie = trim($linie);
            if ($linie === '') {
                continue;
            }
            $gol = false;
            preg_replace_callback('~\{([a-z_]+)\}~', static function (array $m) use ($param, &$gol): string {
                if (array_key_exists($m[1], $param) && trim((string) $param[$m[1]]) === '') {
                    $gol = true;
                }
                return '';
            }, $linie);
            if ($gol) {
                continue;
            }
            [$text, $url] = self::legatura($linie);
            $text = self::completeaza($text, $param);
            // În adrese, câmpurile intră fără spații (un telefon scris „0700 000 000”).
            $url = $url !== null ? self::completeaza($url, array_map(static fn ($v): string => str_replace(' ', '', (string) $v), $param)) : null;
            $url = $url !== null && self::adresaPermisa($url) ? $url : null;
            // Un câmp cu mai multe rânduri ({clasificare}, câte unul pe document) dă câte un rând fiecare, cu textul din jur.
            if (str_contains($text, "\n")) {
                foreach (self::randuriCamp($linie, $param) as $x) {
                    $r[] = ['text' => $x, 'url' => $url];
                }
                continue;
            }
            $r[] = ['text' => $text, 'url' => $url];
        }
        return $r;
    }

    /**
     * Rândul gazdei repetat pentru fiecare valoare a câmpurilor cu mai multe rânduri (de exemplu „Clasificarea:
     * {clasificare}” cu două documente). @param array<string,string> $param @return list<string>
     */
    private static function randuriCamp(string $linie, array $param): array
    {
        [$text] = self::legatura($linie);
        $multe = [];
        foreach ($param as $k => $v) {
            if (str_contains((string) $v, "\n") && str_contains($text, '{' . $k . '}')) {
                $multe[$k] = explode("\n", (string) $v);
            }
        }
        $n = max([1, ...array_map('count', array_values($multe))]);
        $r = [];
        for ($i = 0; $i < $n; $i++) {
            $p = $param;
            foreach ($multe as $k => $v) {
                $p[$k] = $v[$i] ?? '';
            }
            $r[] = self::completeaza($text, $p);
        }
        return $r;
    }

    /** „text | adresă”: adresa e după ultimul „ | ”. @return array{0:string, 1:?string} */
    public static function legatura(string $linie): array
    {
        $p = strrpos($linie, ' | ');
        if ($p === false) {
            return [$linie, null];
        }
        return [trim(substr($linie, 0, $p)), trim(substr($linie, $p + 3))];
    }

    /** Adresele permise în subsol: căi ale site-ului, http(s), mailto:, tel:; fără spații sau caractere de control. */
    public static function adresaPermisa(string $url): bool
    {
        if ($url === '' || preg_match('~[\s\x00-\x1f\x7f<>"\'`\\\\]~', $url)) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }
        return (bool) preg_match('~^(https?://[^/?#]+|mailto:[^@]+@[^@]+$|tel:\+?[0-9][0-9().-]*$)~i', $url);
    }

    /** Adresele greșite dintr-un subsol trimis din panou. @param array<string,string> $grupuri @return list<array{grup:string, rand:int}> */
    public static function adreseGresite(array $grupuri): array
    {
        $r = [];
        foreach ($grupuri as $g => $text) {
            foreach (explode("\n", str_replace("\r", '', $text)) as $i => $linie) {
                [, $url] = self::legatura(trim($linie));
                // Câmpurile din adresă se verifică cu valori de probă; adresa reală se verifică din nou la afișare.
                if ($url !== null && !self::adresaPermisa(self::completeaza($url, self::PROBA))) {
                    $r[] = ['grup' => $g, 'rand' => $i + 1];
                }
            }
        }
        return $r;
    }

    /**
     * Subsolul editat, gata de afișat: contactul (titlul și rândurile) și blocul legal (rândurile datelor, legăturile,
     * SAL, rândul cu „©”), cu câmpurile completate din datele de acum. Null când gazda folosește modelul.
     * @param array<string,mixed> $contact datele contactului (Componente::contact) @param array<string,mixed> $legal BlocLegal::date()
     */
    public function subsol(string $limba, array $contact, array $legal): ?array
    {
        $text = $this->integral('subsol', $limba);
        if ($text === null) {
            return null;
        }
        $g = self::grupuri($text);
        $param = $this->campuriSubsol($limba, $contact, $legal);
        // Cât pagina Accesibilitate e ascunsă, rândurile cu legătura spre ea ies din fiecare grup al subsolului
        // scris de gazdă (panoul primește „text | adresă” pe orice rând).
        $accesibilitate = BlocLegal::accesibilitate($this->app);
        $randuri = static fn (string $grup): array => array_values(array_filter(self::randuri($grup, $param),
            static fn (array $x): bool => $accesibilitate || !self::spreAccesibilitate($x['url'])));
        $c = self::randuri($g['contact'], $param);
        $titlu = $c !== [] ? array_shift($c)['text'] : '';
        $c = array_values(array_filter($c, static fn (array $x): bool => $accesibilitate || !self::spreAccesibilitate($x['url'])));
        $sal = $randuri($g['sal']);
        $an = $randuri($g['an']);
        return [
            'contact' => ['titlu' => $titlu, 'linii' => $c],
            'legal' => ['linii' => $randuri($g['date']), 'legaturi' => array_values(array_filter($randuri($g['legaturi']), static fn (array $x): bool => $x['text'] !== '')),
                'sal' => $sal !== [] && $sal[0]['url'] !== null ? $sal[0] : null, 'an' => implode(' ', array_column($an, 'text'))],
        ];
    }

    /**
     * Adresa duce la pagina Accesibilitate (în oricare limbă), cu rescriere (/accesibilitate) sau fără (/?r=%2Faccesibilitate,
     * forma din Url::cale), relativă sau absolută.
     */
    public static function spreAccesibilitate(?string $url): bool
    {
        if ($url === null) {
            return false;
        }
        $cai = [(string) parse_url($url, PHP_URL_PATH)];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        if (is_string($q['r'] ?? null)) {
            $cai[] = (string) parse_url($q['r'], PHP_URL_PATH);
        }
        foreach ($cai as $cale) {
            $cale = strtolower(rtrim($cale, '/'));
            foreach (Cai::CAI['accesibilitate'] as $l => $c) {
                $c = ($l === 'ro' ? '' : '/' . $l) . $c;
                if ($cale === $c || str_ends_with($cale, $c)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<string,string> */
    private function campuriSubsol(string $limba, array $contact, array $legal): array
    {
        $op = (new BlocLegal($this->app, $this->app->traducator($limba), $limba))->operator();
        $a = (array) ($contact['adresa'] ?? []);
        $telefon = (string) ($contact['telefon'] ?? '');
        $p = [
            'nume' => (string) ($legal['nume'] ?? $contact['nume'] ?? ''),
            'telefon' => $telefon,
            'telefon_tel' => (string) preg_replace('~[^0-9+]~', '', $telefon),
            // Adresa site-ului (originea, ca în paginile legale): „Blogul | {site}/blog” e o legătură permisă, ca la salvare (PROBA).
            'site' => $this->app->config()->origine(),
            'email' => (string) ($contact['email'] ?? ''),
            // La persoana fizică, adresa rămâne în afara subsolului.
            'adresa' => BlocLegal::persoanaFizica($this->app) ? '' : implode(', ', array_filter([(string) ($a['strada'] ?? ''), (string) ($a['localitate'] ?? ''), (string) ($a['judet'] ?? '')])),
            'an' => (string) ($legal['an'] ?? substr($this->app->azi(), 0, 4)),
            'sediu_eticheta' => $this->app->traducator($limba)->t($op['sediu_tip'] !== null ? 'legal.sediu_' . $op['sediu_tip'] : 'legal.sediu'),
        ];
        foreach (['denumire', 'cui', 'registrul_comertului', 'sediu', 'capital_social', 'certificat_clasificare'] as $c) {
            $p[$c] = (string) ($op[$c] ?? '');
        }
        if ($p['capital_social'] !== '') {
            $p['capital_social'] = BlocLegal::capital($p['capital_social'], $this->app->traducator($limba));
        }
        // rândurile clasificării, unul pe document.
        $p['clasificare'] = implode("\n", (new BlocLegal($this->app, $this->app->traducator($limba), $limba))->clasificari());
        return $p;
    }

    // ---- Diferențele ----

    /**
     * Diferențele pe rânduri dintre două texte (cea mai lungă subsecvență comună): rândurile comune, cele scoase din
     * primul și cele adăugate în al doilea. @return list<array{fel:string, text:string}> fel: egal, scos, adaugat
     */
    public static function diferente(string $vechi, string $nou): array
    {
        $a = explode("\n", str_replace("\r", '', $vechi));
        $b = explode("\n", str_replace("\r", '', $nou));
        $n = count($a);
        $m = count($b);
        if ($n * $m > 4000000) {
            return array_merge(array_map(static fn (string $x): array => ['fel' => 'scos', 'text' => $x], $a), array_map(static fn (string $x): array => ['fel' => 'adaugat', 'text' => $x], $b));
        }
        $l = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $l[$i][$j] = $a[$i] === $b[$j] ? $l[$i + 1][$j + 1] + 1 : max($l[$i + 1][$j], $l[$i][$j + 1]);
            }
        }
        $r = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $r[] = ['fel' => 'egal', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($l[$i + 1][$j] >= $l[$i][$j + 1]) {
                $r[] = ['fel' => 'scos', 'text' => $a[$i++]];
            } else {
                $r[] = ['fel' => 'adaugat', 'text' => $b[$j++]];
            }
        }
        for (; $i < $n; $i++) {
            $r[] = ['fel' => 'scos', 'text' => $a[$i]];
        }
        for (; $j < $m; $j++) {
            $r[] = ['fel' => 'adaugat', 'text' => $b[$j]];
        }
        return $r;
    }

    /** Textul de comparat: pagina așa cum e, subsolul cu grupurile unul după altul, cu numele lor. */
    public static function deComparat(string $fel, string $text, callable $numeGrup): string
    {
        if ($fel !== 'subsol') {
            return $text;
        }
        $r = [];
        foreach (self::grupuri($text) as $g => $x) {
            $r[] = '# ' . $numeGrup($g) . "\n" . $x;
        }
        return implode("\n\n", $r);
    }
}
