<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/**
 * CSS-ul temelor: fără `!important`, `@import`, `url()` extern, `expression`,
 * `behavior`, `-moz-binding`, `javascript:`; fonturile doar locale, `woff2`. La publicare, nucleul îl înfășoară în
 * `@layer tema`, deci orice declarație a temei pierde în fața protecțiilor nucleului, scrise în afara straturilor.
 * Regulile de aici țin blocul legal; Validator le aplică pe fiecare foaie, împreună cu structura (structura()) și cu
 * fișierele spre care trimit url()-urile.
 */
final class ValidatorCss
{
    /**
     * Foaia într-o singură trecere, de la stânga la dreapta, cum o citește browserul: comentariile dispar, iar fiecare
     * șir devine marcajul "#N" (conținutul lui rămâne în `siruri`). Un „/*” dintr-un șir nu mai ascunde reguli și un
     * șir dintr-un comentariu nu mai înghite cod: regulile se aplică pe `cod`, nu pe textul brut.
     * @return array{cod:string, siruri:list<string>}
     */
    public static function tokenizeaza(string $css): array
    {
        $cod = '';
        $siruri = [];
        $n = strlen($css);
        for ($i = 0; $i < $n; $i++) {
            $c = $css[$i];
            if ($c === '/' && ($css[$i + 1] ?? '') === '*') {
                $f = strpos($css, '*/', $i + 2);
                $i = $f === false ? $n : $f + 1;
                continue;
            }
            if ($c === '"' || $c === "'") {
                $sir = '';
                for ($j = $i + 1; $j < $n && $css[$j] !== $c && $css[$j] !== "\n"; $j++) {
                    if ($css[$j] === '\\' && $j + 1 < $n) {
                        $sir .= $css[$j] . $css[$j + 1];
                        $j++;
                        continue;
                    }
                    $sir .= $css[$j];
                }
                $cod .= '"#' . count($siruri) . '"';
                $siruri[] = $sir;
                $i = $j;
                continue;
            }
            $cod .= $c;
        }
        return ['cod' => $cod, 'siruri' => $siruri];
    }

    /**
     * Adresele din url(): cele fără ghilimele, din cod, și cele între ghilimele, din șiruri.
     * @param array{cod:string, siruri:list<string>} $t
     * @return list<string>
     */
    public static function adrese(array $t): array
    {
        $r = [];
        if (preg_match_all('~url\(\s*(?:"#(\d+)"|([^)"\s]*))\s*\)~i', $t['cod'], $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $r[] = trim(($x[1] ?? '') !== '' ? (string) ($t['siruri'][(int) $x[1]] ?? '') : (string) ($x[2] ?? ''));
            }
        }
        return $r;
    }

    /** @return list<string> problemele găsite (gol = CSS acceptat) */
    public static function probleme(string $css): array
    {
        $p = [];
        $t = self::tokenizeaza($css);
        $cod = $t['cod'];
        // Escape-urile CSS („!\important”, „@\import”, „u\72 l(”) le decodează browserul, nu regulile de mai jos: în
        // afara șirurilor, „\” e refuzat cu totul. În șiruri rămâne („content: "\A"”), dar nu în adresele din url().
        if (str_contains($cod, '\\')) {
            $p[] = 'caracterul „\\” e permis doar în șiruri (de exemplu content: "\\A"), nu în proprietăți, selectori sau reguli';
        }
        if (preg_match('~!\s*important~i', $cod)) {
            $p[] = '!important este interzis în CSS-ul temei';
        }
        if (preg_match('~@import\b~i', $cod)) {
            $p[] = '@import este interzis';
        }
        if (preg_match('~@layer\b~i', $cod)) {
            $p[] = '@layer îl pune nucleul';
        }
        // Blocurile nucleului stau la z-index 2147483646: tema rămâne sub ele, cu un număr scris direct, de cel mult 10000.
        if (preg_match_all('~(?:^|[;{\s])z-index\s*:\s*([^;}]*)~i', $cod, $zi)) {
            foreach ($zi[1] as $v) {
                $v = strtolower(trim($v));
                if (!in_array($v, ['auto', 'initial', 'inherit', 'unset', 'revert'], true) && (!preg_match('~^-?\d{1,5}$~', $v) || abs((int) $v) > 10000)) {
                    $p[] = 'z-index se scrie ca număr de cel mult 10000 (blocurile nucleului stau deasupra): ' . $v;
                }
            }
        }
        // Un font al temei micșorat la 1% (size-adjust) ar face textul nucleului de 0×0; și metricile (*-override) rămân în limite.
        if (preg_match_all('~(size-adjust|ascent-override|descent-override|line-gap-override)\s*:\s*([^;}]*)~i', $cod, $fm, PREG_SET_ORDER)) {
            foreach ($fm as [, $prop, $val]) {
                $val = strtolower(trim($val));
                if ($val !== 'normal' && (!preg_match('~^(\d*\.?\d+)%$~', $val, $pm) || (float) $pm[1] < 50 || (float) $pm[1] > 200)) {
                    $p[] = $prop . ' într-un @font-face rămâne între 50% și 200%: ' . $val;
                }
            }
        }
        // Izolarea: tema stilează doar containerul ei; html, body, :root și zonele nucleului îi sunt închise.
        if (preg_match_all('~(?:^|[{};])\s*([^{};@\s][^{};]*)\{~', $cod, $pre)) {
            foreach ($pre[1] as $sel) {
                if (preg_match('#(^|[\s,>+~(])(html|body)(?![\w\-])#i', $sel) || preg_match('~:root\b~i', $sel)) {
                    $p[] = 'html, body și :root le stilează nucleul; tema își pune regulile pe clasele ei: ' . trim($sel);
                } elseif (preg_match('~\.pg-(tema|nucleu|corp)\b|\[data-pg-nucleu~i', $sel)) {
                    $p[] = 'containerul temei și zonele nucleului (.pg-tema, .pg-nucleu) le stilează nucleul: ' . trim($sel);
                } elseif (array_filter(self::imparteSir($sel), static fn (string $x): bool => in_array($x[0], ['>', '+', '~'], true)) !== []) {
                    // Prefixat, „~ footer” ar deveni „:where(.pg-tema) ~ footer”, adică zona nucleului.
                    $p[] = 'un selector începe cu un element sau o clasă, nu cu un combinator (>, +, ~): ' . trim($sel);
                }
            }
        }
        // Regulile imbricate (CSS nesting) rămân neprefixate și „& ~ x” ar ajunge la frații containerului: fiecare regulă se scrie întreagă.
        if (self::areImbricate($cod)) {
            $p[] = 'regulile imbricate (CSS nesting) nu sunt permise: scrieți fiecare selector întreg, la nivelul de sus sau într-un @media';
        }
        // O proprietate înregistrată (inherits: false, initial-value: transparent) ar goli jetoanele nucleului pe toată pagina.
        if (preg_match('~@property\b~i', $cod)) {
            $p[] = '@property este interzis: proprietățile personalizate rămân neînregistrate';
        }
        // Jetoanele de culoare și de font le dă nucleul (din setări, cu contrastul verificat); o temă care le-ar
        // redefini, pe pagină sau doar pe blocul legal, ar ocoli verificarea (de exemplu text alb pe alb).
        if (preg_match('~(^|[{;\s])--pg-[a-z0-9\-]*\s*:~i', $cod)) {
            $p[] = 'variabilele --pg-* le pune nucleul; tema le folosește cu var(--pg-…), fără să le redefinească';
        }
        if (preg_match('~(expression\s*\(|behavior\s*:|-moz-binding|javascript:)~i', $cod . ' ' . implode(' ', $t['siruri']))) {
            $p[] = 'construcție CSS executabilă interzisă';
        }
        foreach (self::adrese($t) as $u) {
            if (str_contains($u, '\\')) {
                $p[] = 'url() fără „\\”: adresa se scrie direct, fără escape-uri: ' . $u;
            } elseif ($u === '' || preg_match('~^(https?:|//|data:|[a-z]+:)~i', $u) || str_contains($u, '..' . '/..') || str_starts_with($u, '/')) {
                $p[] = 'url() permis doar spre fișierele temei: ' . $u;
            } elseif (preg_match('~\.(ttf|otf|woff)$~i', $u)) {
                $p[] = 'fonturile se servesc doar ca woff2: ' . $u;
            }
        }
        if (preg_match('~[<>]\s*/?\s*style~i', $cod)) {
            $p[] = 'marcaj HTML în CSS';
        }
        foreach (self::stramosi($cod, $t['siruri']) as $x) {
            $p[] = $x;
        }
        return $p;
    }

    /**
     * Ce nu poate ține nucleul fără să strice grilele și centrarea temelor (v6): pe selectorii care pot prinde
     * strămoșii blocurilor nucleului (html, body, main, footer, un „subsol”, orice selector fără o clasă a temei și
     * orice :has()/:is()/:where()/:not() cu [data-pg] sau pg-), tema nu ascunde (display: none sau contents), nu mută
     * în afara ecranului (position absolută sau fixă cu offseturi negative mari, margin negativ de cel puțin o lățime
     * de ecran, text-indent negativ) și nu strânge sub 1em (width, max-width). Pe blocul însuși ([data-pg], .pg-*),
     * orice margin negativ și orice lățime sunt refuzate. Un strămoș cu o clasă a temei, ascuns altfel, rămâne
     * limita cunoscută: gazda activează tema doar după previzualizare, care arată blocul legal.
     * @return list<string>
     */
    public static function stramosi(string $cod, array $siruri = []): array
    {
        $p = [];
        $text = static fn (string $x): string => (string) preg_replace_callback('~"#(\d+)"~', static fn (array $m): string => '"' . ($siruri[(int) $m[1]] ?? '') . '"', $x);
        // Regulile cele mai din interior (la @media, conținutul lor), cu selectorii și declarațiile.
        preg_match_all('~([^{}]+)\{([^{}]*)\}~', $cod, $reguli, PREG_SET_ORDER);
        foreach ($reguli as [, $selectori, $decl]) {
            $selectori = trim($selectori);
            if ($selectori === '' || $selectori[0] === '@') {
                continue;
            }
            $declaratii = [];
            foreach (explode(';', $decl) as $d) {
                if (str_contains($d, ':')) {
                    [$k, $v] = explode(':', $d, 2);
                    $declaratii[strtolower(trim($k))] = strtolower(trim($v));
                }
            }
            foreach (self::imparte($selectori) as $sel) {
                $subiect = self::subiect($sel);
                $faraArgumente = (string) preg_replace('~\([^()]*(\([^()]*\))*[^()]*\)~', '', $subiect);
                $peBloc = (bool) preg_match('~\[data-pg|\.pg-~i', $faraArgumente);
                // Un pseudo-element (::before, ::marker …) nu cuprinde blocuri, deci nu poate fi strămoșul lor; pe un bloc, nu-l poate
                // acoperi (poziția absolută sau fixă).
                if (preg_match('~::[a-z\-]+~i', $sel) || preg_match('~:(before|after|first-line|first-letter)\b~i', self::subiect($sel, false))) {
                    if ($peBloc && preg_match('~^(absolute|fixed|sticky)\b~', $declaratii['position'] ?? '')) {
                        $p[] = 'un pseudo-element al unui bloc al nucleului rămâne în flux (fără position: ' . $declaratii['position'] . ') (' . $text($sel) . ')';
                    }
                    if (!$peBloc) {
                        continue;
                    }
                }
                $stramos = !$peBloc && self::poatePrindeStramosi($sel, $subiect);
                foreach ($declaratii as $k => $v) {
                    $mesaj = $peBloc ? self::peBloc($k, $v) : ($stramos ? self::peStramos($k, $v, $declaratii, $subiect) : null);
                    if ($mesaj !== null) {
                        $p[] = $mesaj . ' (' . $text($sel) . ' { ' . $k . ': ' . $text($v) . ' })';
                    }
                }
            }
        }
        return array_values(array_unique($p));
    }

    /** Selectorii unei liste, despărțiți la virgulele din afara parantezelor. @return list<string> */
    private static function imparte(string $lista): array
    {
        $r = [];
        $nivel = 0;
        $cur = '';
        foreach (str_split($lista) as $c) {
            $nivel += $c === '(' ? 1 : ($c === ')' ? -1 : 0);
            if ($c === ',' && $nivel === 0) {
                $r[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        $r[] = trim($cur);
        return array_values(array_filter($r, static fn (string $x): bool => $x !== ''));
    }

    /** Ultimul compus al selectorului (elementul pe care cade regula), fără pseudo-elemente. */
    private static function subiect(string $sel, bool $faraPseudo = true): string
    {
        $nivel = 0;
        $ultim = 0;
        for ($i = 0, $n = strlen($sel); $i < $n; $i++) {
            $c = $sel[$i];
            $nivel += $c === '(' ? 1 : ($c === ')' ? -1 : 0);
            if ($nivel === 0 && ($c === ' ' || $c === '>' || $c === '+' || $c === '~')) {
                $ultim = $i + 1;
            }
        }
        $s = trim(substr($sel, $ultim));
        return $faraPseudo ? (string) preg_replace('~::?(before|after|marker|placeholder|selection|first-line|first-letter)\b.*$~i', '', $s) : $s;
    }

    private static function poatePrindeStramosi(string $sel, string $subiect): bool
    {
        if (preg_match('~:(has|is|where|not)\([^)]*(\[data-pg|\bpg-)~i', $sel)) {
            return true;
        }
        if (preg_match('~^(\*|html|body|main|footer)\b~i', $subiect) || preg_match('~subsol~i', $subiect)) {
            return true;
        }
        // Elementele goale (input, img …) nu cuprind nimic, deci nu pot fi strămoși.
        if (preg_match('~^(' . self::GOALE . ')\b~i', $subiect)) {
            return false;
        }
        // Fără nicio clasă (doar tip, atribut sau pseudo-clasă): poate prinde orice element, deci și un strămoș.
        return !str_contains((string) preg_replace('~\([^)]*\)~', '', $subiect), '.');
    }

    /** Mărimea unei valori CSS, în px aproximativi (em/rem = 16 px, vw/vh/% = 1280 px la sută), sau null. */
    private static function px(string $v): ?float
    {
        if (!preg_match('~^(-?\d*\.?\d+)(px|em|rem|vw|vh|vmin|vmax|%|ch)?$~', $v, $m)) {
            return null;
        }
        $n = (float) $m[1];
        return match ($m[2] ?? '') {
            'em', 'rem', 'ch' => $n * 16,
            'vw', 'vh', 'vmin', 'vmax', '%' => $n * 12.8,
            default => $n,
        };
    }

    /** Elementele HTML fără conținut. */
    private const GOALE = 'input|img|br|hr|wbr|source|track|area|col|embed|meta|link|base|param|picture|svg|path|circle|rect|line|polyline|polygon|use';

    /** Elementele de text, cu care tema face pictograme mici; nu cuprind blocurile nucleului (blocuri, nu text). */
    private const DE_TEXT = 'i|b|em|small|strong|abbr|code|kbd|sub|sup|s|u|mark|q|cite|time|var|dfn|span';

    /** @param array<string,string> $toate */
    private static function peStramos(string $k, string $v, array $toate, string $subiect = ''): ?string
    {
        if ($k === 'display' && preg_match('~^(none|contents)\b~', $v)) {
            return 'un strămoș al blocurilor nucleului nu se ascunde (display: none sau contents)';
        }
        // Efectele obișnuite de hover pe linkuri, butoane și pictograme (a:hover { translate: 0 -2px }) rămân: aceste elemente
        // nu cuprind blocurile nucleului, iar CSS-ul nucleului anulează oricum transformările pe strămoși.
        if (in_array($k, ['translate', 'scale', 'rotate', 'zoom'], true) && !preg_match('~^(' . self::DE_TEXT . '|a|button)\b~i', $subiect) && !in_array($v, ['none', 'normal', '1', '100%', 'initial', 'unset'], true)) {
            return $k . ' pe un strămoș al blocurilor nucleului l-ar putea muta sau micșora; puneți efectul pe un element care nu cuprinde blocuri ale nucleului';
        }
        if (!preg_match('~^(' . self::DE_TEXT . '|a|button)\b~i', $subiect)) {
            if ($k === 'opacity' && ($o = self::fractie($v)) !== null && $o < 1) {
                return 'opacity sub 1 pe un strămoș ar face transparente blocurile nucleului';
            }
            if (in_array($k, ['transform', 'filter', 'clip-path', 'backdrop-filter', 'perspective', 'mask', 'mask-image', '-webkit-mask', '-webkit-mask-image'], true) && !in_array($v, ['none', 'initial', 'unset'], true)) {
                return $k . ' pe un strămoș al blocurilor nucleului le-ar putea ascunde, muta sau deforma; puneți efectul pe un element care nu cuprinde blocuri ale nucleului';
            }
            if ($k === 'content-visibility' && $v !== 'visible') {
                return 'content-visibility pe un strămoș ar ascunde blocurile nucleului';
            }
        }
        if (preg_match('~^padding(-.+)?$~', $k)) {
            foreach (preg_split('~\s+~', $v) ?: [] as $x) {
                $px = self::px($x);
                if ($px !== null && $px >= 1280) {
                    return 'un padding de cel puțin o lățime de ecran ar împinge blocurile nucleului în afara ecranului';
                }
            }
        }
        if (($m = self::text($k, $v)) !== null) {
            return $m;
        }
        if ($k === 'text-indent' && str_starts_with($v, '-')) {
            return 'text-indent negativ ar muta blocurile nucleului în afara ecranului';
        }
        if (in_array($k, ['width', 'max-width'], true) && !preg_match('~^(' . self::DE_TEXT . ')\b~i', $subiect)) {
            $px = self::px($v);
            if ($px !== null && $px < 16) {
                return 'un strămoș al blocurilor nucleului nu se strânge sub 1em';
            }
        }
        if (preg_match('~^margin(-(left|right|top|bottom|inline|block|inline-start|inline-end|block-start|block-end))?$~', $k)) {
            foreach (preg_split('~\s+~', $v) ?: [] as $x) {
                $px = self::px($x);
                if ($px !== null && $px <= -1280) {
                    return 'un margin negativ de cel puțin o lățime de ecran ar muta blocurile nucleului în afara ecranului';
                }
            }
        }
        // La orice position: și „relative” cu left: -9999px mută strămoșul în afara ecranului.
        if (in_array($k, ['left', 'right', 'top', 'bottom', 'inset', 'inset-inline', 'inset-block', 'inset-inline-start', 'inset-inline-end', 'inset-block-start', 'inset-block-end'], true)) {
            foreach (preg_split('~\s+~', $v) ?: [] as $x) {
                $px = self::px($x);
                if ($px !== null && $px <= -1000) {
                    return 'un offset negativ mare ar muta blocurile nucleului în afara ecranului';
                }
            }
        }
        return null;
    }

    /**
     * Spațierea literelor și a cuvintelor și înălțimea rândului, moștenite de textul blocurilor: între -0,1em și 0,5em,
     * respectiv cel puțin 0,8 (temele își spațiază etichetele și titlurile, dar nu pot face textul ilizibil).
     */
    private static function text(string $k, string $v): ?string
    {
        if (in_array($k, ['letter-spacing', 'word-spacing'], true) && !in_array($v, ['normal', 'initial', 'inherit', 'unset'], true)) {
            $px = self::px($v);
            if ($px === null || $px < -1.6 || $px > 8) {
                return $k . ' rămâne între -0.1em și 0.5em, scris direct, ca textul nucleului să rămână lizibil';
            }
        }
        if ($k === 'line-height' && !in_array($v, ['normal', 'initial', 'inherit', 'unset'], true)) {
            $n = preg_match('~^\d*\.?\d+$~', $v) ? (float) $v : (($px = self::px($v)) !== null ? $px / 16 : null);
            if ($n === null || $n < 0.8) {
                return 'line-height de cel puțin 0.8, scris direct, ca rândurile textului nucleului să nu se suprapună';
            }
        }
        return null;
    }

    /** O valoare de opacitate (50%), ca fracție, sau null dacă nu e scrisă direct. */
    private static function fractie(string $v): ?float
    {
        if (preg_match('~^(\d*\.?\d+)%$~', $v, $m)) {
            return (float) $m[1] / 100;
        }
        return preg_match('~^\d*\.?\d+$~', $v) ? (float) $v : 0.0;
    }

    private static function peBloc(string $k, string $v): ?string
    {
        if (in_array($k, ['width', 'max-width', 'min-width'], true)) {
            return 'lățimea blocurilor nucleului o dă nucleul';
        }
        // Padding-ul de până la 4em rămâne al temei (benzile și butoanele); mai mult ar împinge textul din bloc.
        if (preg_match('~^padding(-.+)?$~', $k)) {
            foreach (preg_split('~\s+~', $v) ?: [] as $x) {
                $px = self::px($x);
                if ($px !== null && $px > 64) {
                    return 'padding-ul blocurilor nucleului e de cel mult 4em';
                }
            }
        }
        if (($m = self::text($k, $v)) !== null) {
            return $m;
        }
        if (preg_match('~^margin(-.+)?$~', $k)) {
            foreach (preg_split('~\s+~', $v) ?: [] as $x) {
                if (str_starts_with($x, '-')) {
                    return 'blocurile nucleului nu primesc margin negativ';
                }
            }
        }
        return null;
    }

    /**
     * Structura foii: comentariile și șirurile închise, acoladele echilibrate, fără acolade închise
     * în plus (o acoladă închisă devreme ar ieși din `@layer tema`).
     * @return list<string>
     */
    public static function structura(string $css): array
    {
        $p = [];
        $n = strlen($css);
        $nivel = 0;
        for ($i = 0; $i < $n; $i++) {
            $c = $css[$i];
            if ($c === '/' && ($css[$i + 1] ?? '') === '*') {
                $f = strpos($css, '*/', $i + 2);
                if ($f === false) {
                    return ['comentariu neînchis'];
                }
                $i = $f + 1;
            } elseif ($c === '"' || $c === "'") {
                for ($j = $i + 1; $j < $n && $css[$j] !== $c; $j++) {
                    if ($css[$j] === '\\') {
                        $j++;
                    } elseif ($css[$j] === "\n") {
                        return ['șir neînchis'];
                    }
                }
                if ($j >= $n) {
                    return ['șir neînchis'];
                }
                $i = $j;
            } elseif ($c === '{') {
                $nivel++;
            } elseif ($c === '}') {
                $nivel--;
                if ($nivel < 0) {
                    return ['acoladă închisă fără pereche'];
                }
            }
        }
        if ($nivel !== 0) {
            $p[] = 'acolade neînchise';
        }
        return $p;
    }

    /** CSS-ul temei, prefixat cu :where(.pg-tema) și înfășurat în @layer tema; un CSS cu probleme nu se publică. */
    public static function inStrat(string $css): string
    {
        $p = array_merge(self::probleme($css), self::structura($css));
        if ($p !== []) {
            throw new \UnexpectedValueException('CSS-ul temei e refuzat: ' . implode('; ', $p));
        }
        return "@layer tema {\n" . self::prefixeaza($css) . "\n}\n";
    }

    /**
     * Izolarea: pe clasa de pe <body> a temei (containerul .pg-tema), margin, padding și float le fixează
     * nucleul (un margin-bottom uriaș ar muta zona nucleului); valorile neutre (0, none) rămân. Doar regulile al căror
     * selector e chiar containerul (un singur compus, cu una dintre clase sau un prefix al lor).
     * @param list<string> $clase @param list<string> $prefixe
     * @return list<string>
     */
    public static function peCorp(string $css, array $clase, array $prefixe = []): array
    {
        if ($clase === [] && $prefixe === []) {
            return [];
        }
        $p = [];
        $cod = self::tokenizeaza($css)['cod'];
        preg_match_all('~(?:^|(?<=[{};]))\s*([^{};@\s][^{};]*)\{([^{}]*)\}~', $cod, $reguli, PREG_SET_ORDER);
        foreach ($reguli as [, $lista, $corp]) {
            $peContainer = false;
            foreach (self::imparteSir($lista) as $sel) {
                if (preg_match('~[\s>+\~]~', (string) preg_replace('~\([^()]*\)|\[[^\]]*\]~', '', $sel))) {
                    continue;
                }
                preg_match_all('~\.([A-Za-z][\w\-]*)~', (string) preg_replace('~:not\([^()]*\)~i', '', $sel), $cl);
                foreach ($cl[1] as $c) {
                    if (in_array($c, $clase, true) || array_filter($prefixe, static fn (string $x): bool => str_starts_with($c, $x)) !== []) {
                        $peContainer = true;
                    }
                }
            }
            if (!$peContainer) {
                continue;
            }
            preg_match_all('~(?:^|;)\s*(margin|padding|float|margin-[a-z\-]+|padding-[a-z\-]+)\s*:\s*([^;]*)~i', $corp, $d, PREG_SET_ORDER);
            foreach ($d as [, $prop, $val]) {
                $val = strtolower(trim($val));
                if (!preg_match('~^((0(px|em|rem|%)?|none|auto)\s*){1,4}$~', $val)) {
                    $p[] = 'Pe clasa de pe <body> a temei (containerul ei), margin, padding și float le fixează nucleul; puneți spațiul pe elementele temei: ' . trim($lista) . ' { ' . strtolower($prop) . ': ' . $val . ' }';
                }
            }
        }
        return $p;
    }

    /** Prefixul izolării: selectorii temei ajung doar în containerul temei, nu și în zona nucleului. */
    public const PREFIX = ':where(.pg-tema)';

    /** Regulile @ al căror conținut sunt alte reguli (selectorii lor se prefixează). */
    private const GRUPURI = ['media', 'supports', 'container', 'document', 'scope', 'starting-style'];

    /**
     * Izolarea: fiecare selector S al temei devine „:where(.pg-tema) S, S'”, unde S' e S cu :where(.pg-tema)
     * pus pe primul compus (clasele pe care tema le punea pe <body> ajung pe containerul .pg-tema). Specificitatea
     * rămâne aceeași (:where are 0). Conținutul regulilor @font-face, @keyframes, @page, @counter-style și al regulilor
     * imbricate (CSS nesting, relative la părinte) rămâne neatins. Comentariile se scot; șirurile rămân cum sunt.
     */
    public static function prefixeaza(string $css): string
    {
        $out = '';
        $n = strlen($css);
        $stiva = [];
        $preludiu = '';
        for ($i = 0; $i < $n; $i++) {
            $c = $css[$i];
            if ($c === '/' && ($css[$i + 1] ?? '') === '*') {
                $f = strpos($css, '*/', $i + 2);
                $i = $f === false ? $n : $f + 1;
                continue;
            }
            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                while ($j < $n && $css[$j] !== $c) {
                    $j += $css[$j] === '\\' ? 2 : 1;
                }
                $preludiu .= substr($css, $i, $j - $i + 1);
                $i = $j;
                continue;
            }
            $context = end($stiva) ?: 'grup';
            if ($c === '{') {
                $pre = trim($preludiu);
                $preludiu = '';
                if ($context === 'grup' && $pre !== '' && $pre[0] === '@') {
                    $nume = strtolower((string) preg_replace('~^@(-[a-z]+-)?([a-z\-]+).*$~s', '$2', $pre));
                    $stiva[] = in_array($nume, self::GRUPURI, true) ? 'grup' : 'bloc';
                    $out .= $pre . ' {';
                } elseif ($context === 'grup') {
                    $stiva[] = 'stil';
                    $out .= self::prefixLista($pre) . ' {';
                } else {
                    // Într-o regulă de stil (imbricare) sau într-un bloc @: neatins.
                    $stiva[] = 'bloc';
                    $out .= $pre . ' {';
                }
                continue;
            }
            if ($c === '}') {
                $out .= $preludiu . '}';
                $preludiu = '';
                array_pop($stiva);
                continue;
            }
            if ($c === ';') {
                $out .= $preludiu . ';';
                $preludiu = '';
                continue;
            }
            $preludiu .= $c;
        }
        return $out . $preludiu;
    }

    /** Lista de selectori, fiecare dublat cu prefixul (descendent și pe primul compus). */
    private static function prefixLista(string $lista): string
    {
        $r = [];
        foreach (self::imparteSir($lista) as $sel) {
            // Un selector care începe cu un combinator (refuzat de probleme()) rămâne tot în container.
            $r[] = self::PREFIX . (in_array($sel[0], ['>', '+', '~'], true) ? ' :where(*) ' : ' ') . $sel;
            $pe = in_array($sel[0], ['>', '+', '~'], true) ? null : self::peContainer($sel);
            if ($pe !== null) {
                $r[] = $pe;
            }
        }
        return implode(', ', $r);
    }

    /** Selectorii unei liste, despărțiți la virgulele din afara parantezelor, a parantezelor drepte și a șirurilor. @return list<string> */
    private static function imparteSir(string $lista): array
    {
        $r = [];
        $nivel = 0;
        $cur = '';
        $n = strlen($lista);
        for ($i = 0; $i < $n; $i++) {
            $c = $lista[$i];
            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                while ($j < $n && $lista[$j] !== $c) {
                    $j += $lista[$j] === '\\' ? 2 : 1;
                }
                $cur .= substr($lista, $i, $j - $i + 1);
                $i = $j;
                continue;
            }
            $nivel += ($c === '(' || $c === '[') ? 1 : (($c === ')' || $c === ']') ? -1 : 0);
            if ($c === ',' && $nivel === 0) {
                $r[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        $r[] = trim($cur);
        return array_values(array_filter($r, static fn (string $x): bool => $x !== ''));
    }

    /**
     * Selectorul cu :where(.pg-tema) pus la sfârșitul primului compus, înaintea unui pseudo-element; null când după primul
     * compus urmează un combinator de frați (+, ~): frații containerului sunt zonele nucleului.
     */
    private static function peContainer(string $sel): ?string
    {
        $n = strlen($sel);
        $nivel = 0;
        $pseudo = null;
        for ($i = 0; $i < $n; $i++) {
            $c = $sel[$i];
            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                while ($j < $n && $sel[$j] !== $c) {
                    $j += $sel[$j] === '\\' ? 2 : 1;
                }
                $i = $j;
                continue;
            }
            if ($c === '(' || $c === '[') {
                $nivel++;
            } elseif ($c === ')' || $c === ']') {
                $nivel--;
            } elseif ($nivel === 0 && (ctype_space($c) || $c === '>' || $c === '+' || $c === '~')) {
                break;
            } elseif ($nivel === 0 && $pseudo === null && $c === ':' && (($sel[$i + 1] ?? '') === ':' || preg_match('~^:(before|after|first-line|first-letter)\b~i', substr($sel, $i)))) {
                $pseudo = $i;
            }
        }
        $urm = ltrim(substr($sel, $i));
        if ($urm !== '' && ($urm[0] === '+' || $urm[0] === '~')) {
            return null;
        }
        $p = $pseudo ?? $i;
        return substr($sel, 0, $p) . self::PREFIX . substr($sel, $p);
    }

    /** Dacă CSS-ul (fără comentarii și șiruri) are o regulă într-o regulă de stil. */
    private static function areImbricate(string $cod): bool
    {
        $stiva = [];
        $preludiu = '';
        $n = strlen($cod);
        for ($i = 0; $i < $n; $i++) {
            $c = $cod[$i];
            if ($c === '{') {
                $context = end($stiva) ?: 'grup';
                if ($context === 'stil') {
                    return true;
                }
                $pre = trim($preludiu);
                if ($context === 'bloc') {
                    $stiva[] = 'bloc';
                } elseif ($pre !== '' && $pre[0] === '@') {
                    $nume = strtolower((string) preg_replace('~^@(-[a-z]+-)?([a-z\-]+).*$~s', '$2', $pre));
                    $stiva[] = in_array($nume, self::GRUPURI, true) ? 'grup' : 'bloc';
                } else {
                    $stiva[] = 'stil';
                }
                $preludiu = '';
            } elseif ($c === '}') {
                array_pop($stiva);
                $preludiu = '';
            } elseif ($c === ';') {
                $preludiu = '';
            } else {
                $preludiu .= $c;
            }
        }
        return false;
    }
}
