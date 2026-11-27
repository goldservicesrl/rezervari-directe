<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Twig\Environment;
use Twig\Source;
use Twig\Token;
use Twig\TokenStream;

defined('CAZO') or exit;

/**
 * Tagurile HTML ale unei teme, citite cum le citește browserul (HTML5), nu cu expresii
 * regulate: „/” desparte atributele („<meta/http-equiv=…>”), o valoare între ghilimele poate conține „>” fără să
 * închidă tagul, iar <script>, <style>, <textarea> și <title> au text brut. Același cititor lucrează pe textul literal
 * al unui șablon (din lexerul Twig) și pe HTML-ul randat de probă, fără DOMDocument (parserul HTML4 al lui libxml
 * aruncă atributele de după „/”) și fără Dom\HTMLDocument (doar din PHP 8.4).
 */
final class HtmlTaguri
{
    /** Locul unei construcții Twig care scrie ceva ({{ … }}, include, block …) în textul literal. */
    public const MARCAJ = "\x01";

    /** Marginea unui tag de control ({% if %}, {% endif %}, {% set %} …): textul de o parte sau de alta poate lipsi la randare. */
    public const MARCAJ_CONTROL = "\x02";

    /** Tagurile Twig fără ieșire proprie: textul ramurilor lor intră oricum în textul literal. */
    public const TAGURI_FARA_IESIRE = ['if', 'elseif', 'else', 'endif', 'for', 'endfor', 'set', 'endset', 'with', 'endwith', 'apply', 'endapply', 'verbatim', 'endverbatim'];

    /** Elementele cu conținut străin (HTML5): înăuntrul lor, title, textarea, style și script sunt taguri obișnuite, nu text brut. */
    private const STRAINE = ['svg', 'math'];

    private const TEXT_BRUT = ['script', 'style', 'textarea', 'title', 'xmp', 'noembed', 'noframes', 'iframe', 'plaintext'];

    /** Elementele cu text brut sau învechite, refuzate în conținutul temei (<noscript> îl folosește doar nucleul). */
    public const INTERZISE_BRUT = ['xmp', 'noembed', 'noframes', 'plaintext', 'listing'];

    /** Spațiile HTML5: tab, LF, FF, CR și spațiul (\x0B nu e spațiu în HTML). */
    private const SPATII = " \t\n\f\r";

    /** Caracterele de control (afară de spațiile HTML5 și de marcajele textului literal, \x01 și \x02). */
    public const CONTROL = '~[\x00\x03-\x08\x0B\x0E-\x1F\x7F]~';

    private static function spatiu(string $c): bool
    {
        return $c !== '' && str_contains(self::SPATII, $c);
    }

    /**
     * Textul literal al unui șablon, din tokenurile lexerului Twig (nu din expresii regulate: un șir „%}{#” dintr-un
     * tag nu mai ascunde text, iar un comentariu nu mai taie un tag în două): textul rămâne, comentariile dispar, o
     * expresie sau un tag care scrie ceva devine MARCAJ, iar un tag de control (if, for, set, with …) devine
     * MARCAJ_CONTROL: o ramură poate lipsi la randare, deci marginile ei nu pot cădea în numele unui tag, al unui
     * atribut sau într-o valoare fără ghilimele. Corpul unui {% set x %} cu captură se scrie abia unde e folosit, deci
     * se citește separat ($extra['capturi']); în locul lui rămâne MARCAJ_CONTROL. Un {% apply %} cu un filtru care
     * poate schimba textul (replace, slice …) ar face taguri pe care textul literal nu le arată: e refuzat
     * ($extra['probleme']). Nu se adaugă rânduri: linia din fișier vine din $linii, locul de început al fiecărei
     * bucăți de text, cu linia ei.
     * @param list<array{0:int, 1:int}>|null $linii
     * @param array{capturi: list<array{0:string, 1:list<array{0:int,1:int}>}>, probleme: list<array{0:string,1:int}>}|null $extra
     */
    public static function textLiteral(Environment $env, string $sursa, string $nume, ?array &$linii = null, ?array &$extra = null): string
    {
        $flux = $env->tokenize(new Source($sursa, $nume));
        $extra = ['capturi' => [], 'probleme' => [], 'nucleu' => []];
        [$text, $linii] = self::flux($flux, null, $extra);
        return $text;
    }

    /** Filtrele lui {% apply %} care nu pot face un tag nou din text (schimbă doar litere mari și mici, spațiile de la margini, rândurile). */
    public const FILTRE_APPLY = ['escape', 'e', 'upper', 'lower', 'capitalize', 'title', 'trim', 'nl2br'];

    /**
     * Textul tokenurilor până la tagul $pana (endset), sau până la sfârșit.
     * @return array{0:string, 1:list<array{0:int,1:int}>}
     */
    private static function flux(TokenStream $flux, ?string $pana, array &$extra): array
    {
        $text = '';
        $linii = [];
        $alinia = static function (int $linie) use (&$text, &$linii): void {
            $linii[] = [strlen($text), $linie];
        };
        while (!$flux->isEOF()) {
            $t = $flux->getCurrent();
            $tip = $t->getType();
            if ($tip === Token::TEXT_TYPE) {
                $alinia($t->getLine());
                $text .= (string) $t->getValue();
                $flux->next();
                continue;
            }
            if ($tip === Token::VAR_START_TYPE) {
                $alinia($t->getLine());
                $urm = $flux->look(1);
                if ($pana === null && $urm->getType() === Token::NAME_TYPE && $urm->getValue() === 'cazo') {
                    $extra['nucleu'][] = strlen($text);
                }
                $text .= self::MARCAJ;
                while (!$flux->isEOF() && $flux->getCurrent()->getType() !== Token::VAR_END_TYPE) {
                    $flux->next();
                }
                $flux->next();
                continue;
            }
            if ($tip === Token::BLOCK_START_TYPE) {
                $linie = $t->getLine();
                $flux->next();
                $tok = [];
                while (!$flux->isEOF() && $flux->getCurrent()->getType() !== Token::BLOCK_END_TYPE) {
                    $tok[] = $flux->getCurrent();
                    $flux->next();
                }
                $flux->next();
                $tag = isset($tok[0]) && $tok[0]->getType() === Token::NAME_TYPE ? (string) $tok[0]->getValue() : null;
                if ($pana !== null && $tag === $pana) {
                    return [$text, $linii];
                }
                $alinia($linie);
                if ($tag === 'set' && count($tok) === 2 && $tok[1]->getType() === Token::NAME_TYPE) {
                    $extra['capturi'][] = self::flux($flux, 'endset', $extra);
                    $text .= self::MARCAJ_CONTROL;
                    continue;
                }
                if ($tag === 'apply') {
                    foreach ($tok as $i => $x) {
                        $dupaBara = $i > 0 && $tok[$i - 1]->getType() === Token::PUNCTUATION_TYPE && $tok[$i - 1]->getValue() === '|';
                        if ($x->getType() === Token::NAME_TYPE && ($i === 1 || $dupaBara) && !in_array($x->getValue(), self::FILTRE_APPLY, true)) {
                            $extra['probleme'][] = ['{% apply ' . $x->getValue() . ' %} poate schimba HTML-ul după verificare: în {% apply %} sunt permise doar ' . implode(', ', self::FILTRE_APPLY) . '.', $linie];
                        }
                    }
                }
                if ($pana === null && $tag === 'include' && isset($tok[1]) && $tok[1]->getType() === Token::STRING_TYPE && str_starts_with((string) $tok[1]->getValue(), '@schelet')) {
                    $extra['nucleu'][] = strlen($text);
                }
                $text .= in_array($tag, self::TAGURI_FARA_IESIRE, true) ? self::MARCAJ_CONTROL : self::MARCAJ;
                continue;
            }
            $flux->next();
        }
        return [$text, $linii];
    }

    /**
     * Tagurile de deschidere ale unui text HTML, după tokenizarea HTML5, cu atributele (valorile decodate) și locurile
     * în care un MARCAJ ar da numele unui tag sau al unui atribut (sau o valoare fără ghilimele). Un MARCAJ_CONTROL e
     * permis între atribute (după un spațiu sau după o valoare între ghilimele) și în valorile între ghilimele; lipit de
     * un nume sau de o valoare fără ghilimele, e un loc ca al lui MARCAJ. Un comentariu, un tag „<!…>” sau un text brut
     * care conține un marcaj se citește ca HTML obișnuit: valoarea lui l-ar putea închide („-->”, „</title>”). `deschis`
     * spune că textul se termină în interiorul unui tag.
     * @return array{taguri: list<array{nume:string, atribute: list<array{0:string, 1:string}>, linie:int}>, marcaje: list<int>, deschis: bool}
     */
    public static function citeste(string $html, array $linii = []): array
    {
        $taguri = [];
        $toate = [];
        $marcaje = [];
        $comentarii = [];
        $deschis = false;
        $straine = 0;
        $n = strlen($html);
        $cuMarcaj = static fn (int $de, int $pana): bool => strcspn($html, self::MARCAJ . self::MARCAJ_CONTROL, $de, max(0, $pana - $de)) < $pana - $de;
        $linie = static function (int $poz) use ($html, $n, $linii): int {
            $poz = min($poz, $n);
            $baza = [0, 1];
            foreach ($linii as $x) {
                if ($x[0] > $poz) {
                    break;
                }
                $baza = $x;
            }
            return $baza[1] + substr_count($html, "\n", $baza[0], $poz - $baza[0]);
        };
        $i = 0;
        while ($i < $n) {
            $lt = strpos($html, '<', $i);
            if ($lt === false) {
                break;
            }
            $urm = $html[$lt + 1] ?? '';
            if (substr($html, $lt, 4) === '<!--') {
                $comentarii[] = [$lt, $linie($lt), false];
                // HTML5: „<!-->” și „<!--->” se închid pe loc; altfel comentariul ține până la „-->” sau „--!>”.
                if (($html[$lt + 4] ?? '') === '>') {
                    $i = $lt + 5;
                    continue;
                }
                if (substr($html, $lt + 4, 2) === '->') {
                    $i = $lt + 6;
                    continue;
                }
                $f1 = strpos($html, '-->', $lt + 4);
                $f2 = strpos($html, '--!>', $lt + 4);
                $f = $f1 === false ? $f2 : ($f2 === false ? $f1 : min($f1, $f2));
                $sf = $f === false ? $n : $f + ($f === $f2 ? 4 : 3);
                $i = $cuMarcaj($lt + 4, $sf) ? $lt + 4 : $sf;
                continue;
            }
            if ($urm === '!' || $urm === '?') {
                $comentarii[] = [$lt, $linie($lt), strncasecmp(substr($html, $lt, 9), '<!doctype', 9) === 0];
                $f = strpos($html, '>', $lt + 2);
                $sf = $f === false ? $n : $f + 1;
                $i = $cuMarcaj($lt + 2, $sf) ? $lt + 2 : $sf;
                continue;
            }
            $inchidere = $urm === '/';
            $j = $lt + ($inchidere ? 2 : 1);
            $c = $html[$j] ?? '';
            if ($c === self::MARCAJ || $c === self::MARCAJ_CONTROL) {
                $marcaje[] = $linie($j);
            } elseif (!ctype_alpha($c)) {
                if ($inchidere) {
                    // HTML5: „</” urmat de o non-literă e un comentariu fals până la „>” („</>” nu produce nimic), nu text.
                    $comentarii[] = [$lt, $linie($lt), false];
                    $f = strpos($html, '>', $lt + 2);
                    $sf = $f === false ? $n : $f + 1;
                    $i = $cuMarcaj($lt + 2, $sf) ? $lt + 2 : $sf;
                    continue;
                }
                $i = $lt + 1;
                continue;
            }
            // Numele tagului.
            $nume = '';
            while ($j < $n && !self::spatiu($html[$j]) && $html[$j] !== '/' && $html[$j] !== '>') {
                if ($html[$j] === self::MARCAJ || $html[$j] === self::MARCAJ_CONTROL) {
                    $marcaje[] = $linie($j);
                }
                $nume .= $html[$j];
                $j++;
            }
            $nume = strtolower(str_replace([self::MARCAJ, self::MARCAJ_CONTROL], '', $nume));
            $atribute = [];
            // Atributele: „/” și spațiile le despart; valoarea poate fi între ghilimele (cu „>” în ea) sau fără.
            while ($j < $n && $html[$j] !== '>') {
                $c = $html[$j];
                if (self::spatiu($c) || $c === '/') {
                    $j++;
                    continue;
                }
                // Între atribute: după un spațiu, după „/” sau după o valoare între ghilimele (nu lipit de un nume).
                if ($c === self::MARCAJ_CONTROL) {
                    $prec = $html[$j - 1] ?? ' ';
                    if (!(self::spatiu($prec) || $prec === '/' || $prec === '"' || $prec === "'" || $prec === self::MARCAJ_CONTROL)) {
                        $marcaje[] = $linie($j);
                    }
                    $j++;
                    continue;
                }
                $an = '';
                while ($j < $n && !self::spatiu($html[$j]) && $html[$j] !== '/' && $html[$j] !== '>' && ($html[$j] !== '=' || $an === '')) {
                    if ($html[$j] === self::MARCAJ || $html[$j] === self::MARCAJ_CONTROL) {
                        $marcaje[] = $linie($j);
                    }
                    $an .= $html[$j];
                    $j++;
                }
                while ($j < $n && self::spatiu($html[$j])) {
                    $j++;
                }
                $val = '';
                if (($html[$j] ?? '') === '=') {
                    $j++;
                    while ($j < $n && self::spatiu($html[$j])) {
                        $j++;
                    }
                    $q = $html[$j] ?? '';
                    if ($q === '"' || $q === "'") {
                        $f = strpos($html, $q, $j + 1);
                        $f = $f === false ? $n : $f;
                        $val = substr($html, $j + 1, $f - $j - 1);
                        $j = $f + 1;
                    } else {
                        while ($j < $n && !self::spatiu($html[$j]) && $html[$j] !== '>') {
                            if ($html[$j] === self::MARCAJ || $html[$j] === self::MARCAJ_CONTROL) {
                                $marcaje[] = $linie($j);
                            }
                            $val .= $html[$j];
                            $j++;
                        }
                    }
                }
                if (!$inchidere) {
                    $atribute[] = [strtolower(str_replace([self::MARCAJ, self::MARCAJ_CONTROL], '', $an)), html_entity_decode(self::referinte(str_replace([self::MARCAJ, self::MARCAJ_CONTROL], '', $val)), ENT_QUOTES | ENT_HTML5, 'UTF-8')];
                }
            }
            if ($j >= $n) {
                $deschis = true;
            }
            if (!$inchidere) {
                $taguri[] = ['nume' => $nume, 'atribute' => $atribute, 'linie' => $linie($lt)];
            }
            $autoinchis = $j < $n && $j > 0 && $html[$j - 1] === '/';
            $toate[] = ['nume' => $nume, 'inchidere' => $inchidere, 'poz' => $lt, 'sfarsit' => min($n, $j + 1), 'atribute' => $atribute, 'autoinchis' => $autoinchis, 'linie' => $linie($lt)];
            if (in_array($nume, self::STRAINE, true)) {
                $straine = $inchidere ? max(0, $straine - 1) : $straine + ($autoinchis ? 0 : 1);
            }
            $i = $j + 1;
            // Text brut: până la tagul de închidere al elementului, doar în afara conținutului străin (svg, math).
            if (!$inchidere && $straine === 0 && in_array($nume, self::TEXT_BRUT, true)) {
                // <plaintext> face text tot restul documentului.
                $f = $nume === 'plaintext' ? false : stripos($html, '</' . $nume, $i);
                $sf = $f === false ? $n : $f;
                $i = $cuMarcaj($i, $sf) ? $i : $sf;
            }
        }
        return ['taguri' => $taguri, 'marcaje' => $marcaje, 'deschis' => $deschis, 'toate' => $toate, 'comentarii' => $comentarii];
    }

    /**
     * Gazda unei adrese absolute (http, https sau „//”, cu „\” citit ca „/”, ca în browser), sau null pentru o adresă
     * relativă, o ancoră, mailto: sau tel:. Pentru ping, fiecare adresă din listă.
     */
    public static function gazdaExterna(string $v): ?string
    {
        foreach (preg_split('~\s+~', trim($v)) ?: [] as $u) {
            $u = strtolower(str_replace('\\', '/', (string) preg_replace('~[\x00-\x20]+~', '', $u)));
            if (preg_match('~^(?:[a-z][a-z0-9+.\-]*:)?//+(?:[^/?#@]*@)?(\[[^\]]*\]|[^/?#:]*)~', $u, $m) || preg_match('~^https?:/*(?:[^/?#@]*@)?(\[[^\]]*\]|[^/?#:]*)~', $u, $m)) {
                return rtrim($m[1], '.');
            }
        }
        return null;
    }

    /** Clasele și atributul zonelor nucleului, închise temei (izolarea). */
    public const CLASE_NUCLEU = '~(^|\s)pg-(tema|nucleu|corp)~i';

    /**
     * Elementele rămase deschise la sfârșitul unui fragment (stiva, în ordinea deschiderii), cu atributele lor: locul
     * comenzii împarte conținutul temei, iar Randare::zone le închide și le redeschide. Fragmentul e începutul unui
     * conținut deja verificat (continutTema), deci închiderile se potrivesc.
     * @return list<array{nume:string, atribute:list<array{0:string,1:string}>}>
     */
    public static function deschise(string $html): array
    {
        $stiva = [];
        foreach (self::citeste($html)['toate'] as $t) {
            if ($t['inchidere']) {
                if ($stiva !== [] && end($stiva)['nume'] === $t['nume']) {
                    array_pop($stiva);
                }
            } elseif (!$t['autoinchis'] && !in_array($t['nume'], self::GOALE, true)) {
                $stiva[] = ['nume' => $t['nume'], 'atribute' => $t['atribute']];
            }
        }
        return $stiva;
    }

    /**
     * Conținutul temei dintre <body> și </body>, randat, verificat la fiecare randare (ramurile {% if %} ocolesc
     * fixturile probei): fără comentarii și declarații (<!--, <!, <?), fără clasele pg-tema, pg-nucleu, pg-corp și
     * fără data-pg-nucleu, fără <html>, <head>, <body>, iar tagurile se închid în ordine (stiva goală la sfârșit; un
     * </div> în plus ar scoate conținutul din container, un tag neînchis ar cuprinde zona nucleului). Motivul, sau null.
     */
    public static function continutTema(string $html, ?string $gazda = null): ?string
    {
        if (preg_match('~[\x00-\x08\x0B\x0E-\x1F\x7F]~', $html)) {
            return 'Conținutul temei are un caracter de control.';
        }
        $c = self::citeste($html);
        if ($c['comentarii'] !== []) {
            return 'Conținutul temei are un comentariu HTML sau o declarație (<!--, <!, <?), pe linia ' . $c['comentarii'][0][1] . '.';
        }
        $stiva = [];
        // Adâncimea primului bloc al nucleului deschis (data-pg, pus doar de nucleu: în șabloane e refuzat), sau null.
        $nucleu = null;
        foreach ($c['toate'] as $t) {
            if (in_array($t['nume'], ['html', 'head', 'body'], true)) {
                return 'Conținutul temei are <' . ($t['inchidere'] ? '/' : '') . $t['nume'] . '>, pe linia ' . $t['linie'] . '.';
            }
            if ($t['inchidere']) {
                if ($stiva === [] || end($stiva) !== $t['nume']) {
                    return 'Tagul </' . $t['nume'] . '> de pe linia ' . $t['linie'] . ' nu închide ultimul tag deschis' . ($stiva === [] ? '' : ' (<' . end($stiva) . '>)') . '.';
                }
                array_pop($stiva);
                if ($nucleu !== null && count($stiva) <= $nucleu) {
                    $nucleu = null;
                }
                continue;
            }
            $inNucleu = $nucleu !== null || self::atribut($t['atribute'], 'data-pg') !== null;
            if (in_array($t['nume'], self::INTERZISE_BRUT, true)) {
                return 'Conținutul temei are <' . $t['nume'] . '>, pe linia ' . $t['linie'] . '.';
            }
            foreach ($t['atribute'] as [$a, $v]) {
                if ($a === 'data-pg-nucleu' || ($a === 'class' && preg_match(self::CLASE_NUCLEU, $v))) {
                    return 'Conținutul temei folosește o clasă sau un atribut al zonelor nucleului (pg-tema, pg-nucleu, pg-corp, data-pg-nucleu), pe linia ' . $t['linie'] . '.';
                }
                // Legăturile blocurilor nucleului (harta online, „Cum ajungeți”) rămân; cele ale temei, nu.
                if ($gazda !== null && !$inNucleu && in_array($a, ['href', 'xlink:href', 'ping'], true) && (($h = self::gazdaExterna($v)) !== null && $h !== strtolower($gazda))) {
                    return 'Pe paginile rezervării, conținutul temei are o legătură spre alt domeniu (' . $h . '), pe linia ' . $t['linie'] . '.';
                }
            }
            if (!$t['autoinchis'] && !in_array($t['nume'], self::GOALE, true)) {
                if ($inNucleu && $nucleu === null) {
                    $nucleu = count($stiva);
                }
                $stiva[] = $t['nume'];
            }
        }
        return $stiva === [] ? null : 'Tagul <' . end($stiva) . '> rămâne neînchis la sfârșitul conținutului temei.';
    }

    /** Referințele numerice fără „;” (HTML5 le acceptă): li se adaugă „;”, ca html_entity_decode să le citească. */
    private static function referinte(string $v): string
    {
        $v = (string) preg_replace('~&#[xX]([0-9a-fA-F]+)(?![0-9a-fA-F;])~', '&#x$1;', $v);
        return (string) preg_replace('~&#([0-9]+)(?![0-9;])~', '&#$1;', $v);
    }

    /** Elementele fără conținut (nu intră în lanțul strămoșilor). */
    private const GOALE = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /**
     * Strămoșii locului $poz (taguri deschise înainte și neînchise) care ar ascunde un bloc al nucleului: atributele
     * hidden, inert, aria-hidden="true", popover, un <details> sau <dialog> fără open. Motivul, sau null.
     * @param list<array{nume:string, inchidere:bool, poz:int, atribute:list<array{0:string,1:string}>, autoinchis:bool}> $toate
     */
    public static function ascunsLa(array $toate, int $poz): ?string
    {
        $stiva = [];
        foreach ($toate as $t) {
            if ($t['poz'] >= $poz) {
                break;
            }
            if ($t['inchidere']) {
                for ($i = count($stiva) - 1; $i >= 0; $i--) {
                    if ($stiva[$i]['nume'] === $t['nume']) {
                        array_splice($stiva, $i);
                        break;
                    }
                }
                continue;
            }
            if (!$t['autoinchis'] && !in_array($t['nume'], self::GOALE, true)) {
                $stiva[] = $t;
            }
        }
        foreach ($stiva as $t) {
            foreach ($t['atribute'] as [$a, $v]) {
                if (in_array($a, ['hidden', 'inert', 'popover'], true) || ($a === 'aria-hidden' && strtolower(trim($v)) === 'true')) {
                    return '<' . $t['nume'] . ' ' . $a . '>';
                }
            }
            if (in_array($t['nume'], ['details', 'dialog'], true) && self::atribut($t['atribute'], 'open') === null) {
                return '<' . $t['nume'] . '> fără open';
            }
        }
        return null;
    }

    /** Valoarea unui atribut (primul cu numele dat), sau null. @param list<array{0:string,1:string}> $atribute */
    public static function atribut(array $atribute, string $nume): ?string
    {
        foreach ($atribute as [$a, $v]) {
            if ($a === $nume) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Problemele tagurilor, pe textul literal ($randat = false) sau pe HTML-ul randat ($randat = true,
     * unde nucleul pune legături, scripturile lui din /_c/, formularele cu clasa pg- și refresh-ul din <noscript>).
     * @return list<array{0:string, 1:int}> mesajul și linia
     */
    public static function probleme(string $html, bool $randat, array $linii = [], array $nucleu = []): array
    {
        $p = [];
        $c = self::citeste($html, $linii);
        // Blocurile nucleului: pe pagina randată, elementele cu data-pg; în șablon, locurile în care scrie nucleul (cazo.*, @schelet).
        $locuri = [];
        foreach ($randat ? $c['toate'] : [] as $t) {
            if (!$t['inchidere'] && self::atribut($t['atribute'], 'data-pg') !== null) {
                $locuri[] = [$t['poz'], $t['linie']];
            }
        }
        foreach ($nucleu as $poz) {
            $locuri[] = [$poz, 0];
        }
        foreach ($locuri as [$poz, $l]) {
            if (($motiv = self::ascunsLa($c['toate'], $poz)) !== null) {
                if ($l === 0) {
                    // În șablon: linia tagului de dinaintea locului.
                    $l = 1;
                    foreach ($c['toate'] as $t) {
                        if ($t['poz'] > $poz) {
                            break;
                        }
                        $l = $t['linie'];
                    }
                }
                $p[] = ['Un bloc al nucleului stă într-un element care îl ascunde (' . $motiv . '): blocurile nucleului rămân vizibile.', $l];
            }
        }
        if (!$randat && preg_match(self::CONTROL, $html, $cc, PREG_OFFSET_CAPTURE)) {
            $p[] = ['Caracter de control în șablon: folosiți doar spațiile obișnuite, tab și rândul nou.', 1 + substr_count($html, "\n", 0, $cc[0][1])];
        }
        foreach ($randat ? [] : $c['comentarii'] as [, $l, $doctype]) {
            if (!$doctype) {
                $p[] = ['Fără comentarii HTML în șabloane (<!-- -->): folosiți comentariile Twig, {# … #}, care nu ajung în pagină.', $l];
            }
        }
        foreach ($randat ? [] : $c['toate'] as $t) {
            foreach ($t['inchidere'] ? [] : $t['atribute'] as [$a, $v]) {
                if ($a === 'data-pg-nucleu' || ($a === 'class' && preg_match(self::CLASE_NUCLEU, $v))) {
                    $p[] = ['Clasele pg-tema, pg-nucleu, pg-corp și atributul data-pg-nucleu sunt ale nucleului (containerul temei și zonele lui).', $t['linie']];
                } elseif ($a === 'data-pg') {
                    $p[] = ['Atributul data-pg îl pun doar componentele nucleului (cazo.*).', $t['linie']];
                }
            }
        }
        foreach ($randat ? [] : $c['marcaje'] as $l) {
            $p[] = ['O expresie Twig dă numele unui tag sau al unui atribut (sau o valoare fără ghilimele): scrieți tagul și atributul în text, iar valoarea între ghilimele.', $l];
        }
        $refreshNucleu = $randat ? substr_count($html, '<noscript><meta http-equiv="refresh" content=') : 0;
        foreach ($c['taguri'] as ['nume' => $nume, 'atribute' => $at, 'linie' => $l]) {
            $src = self::atribut($at, 'src');
            if ($nume === 'script' && !($randat && $src !== null && str_starts_with($src, '/_c/'))) {
                $p[] = [$randat ? 'Pagina are un <script> din afara nucleului.' : 'Fără <script> în șabloane: interactivitatea vine din componentele nucleului și din CSS.', $l];
            } elseif (in_array($nume, ['iframe', 'object', 'embed', 'base', 'frame', 'frameset', 'applet', ...self::INTERZISE_BRUT], true) || (!$randat && $nume === 'noscript')) {
                $p[] = ['Element interzis: <' . $nume . '>.', $l];
            } elseif ($nume === 'dialog') {
                // Un <dialog> deschis cu un clic stă în top layer, deasupra blocurilor nucleului.
                $p[] = ['Element interzis: <dialog> (stă deasupra blocurilor nucleului).', $l];
            } elseif ($nume === 'template' || $nume === 'slot') {
                // <template shadowrootmode> dă elementului părinte o rădăcină shadow: fără <slot>, copiii lui (blocurile nucleului) nu se mai afișează.
                $p[] = ['Element interzis: <' . $nume . '> (o rădăcină shadow ar ascunde blocurile nucleului).', $l];
            } elseif ($nume === 'form' && !($randat && str_starts_with((string) self::atribut($at, 'class'), 'pg-'))) {
                $p[] = [$randat ? 'Pagina are un formular din afara nucleului.' : 'Element interzis: <form>.', $l];
            } elseif (!$randat && $nume === 'style') {
                $p[] = ['Fără <style> în șabloane: stilurile stau în assets/css.', $l];
            } elseif (!$randat && $nume === 'link') {
                $p[] = ['Fără <link> în șabloane: cazo.head() pune foile și fonturile.', $l];
            }
            foreach ($at as [$a, $v]) {
                $compact = strtolower((string) preg_replace('~[\x00-\x20]+~', '', $v));
                if ($a === 'http-equiv') {
                    if ($randat && $refreshNucleu > 0 && $nume === 'meta' && strtolower($v) === 'refresh' && preg_match('~^\d+(;url=/(?!/)[^<>"]*)?$~', (string) self::atribut($at, 'content'))) {
                        $refreshNucleu--;
                        continue;
                    }
                    $p[] = [$randat ? 'Pagina are un <meta http-equiv> din afara nucleului.' : 'Fără atributul http-equiv: antetele le pune nucleul.', $l];
                } elseif (in_array($a, ['popover', 'popovertarget', 'popovertargetaction', 'command', 'commandfor', 'interestfor'], true)) {
                    $p[] = ['Fără ' . $a . ': un element din top layer acoperă blocurile nucleului după un clic.', $l];
                } elseif (!$randat && in_array($a, ['hidden', 'inert'], true)) {
                    $p[] = ['Fără atributul ' . $a . ' în șabloane: ascunsul se face din CSS, pe elemente care nu cuprind blocuri ale nucleului.', $l];
                } elseif ($a === 'shadowrootmode' || $a === 'shadowroot') {
                    $p[] = ['Fără rădăcini shadow declarative (shadowrootmode): ar ascunde blocurile nucleului.', $l];
                } elseif (str_starts_with($a, 'on')) {
                    $p[] = ['Fără atribute on*.', $l];
                } elseif (!$randat && $a === 'style') {
                    $p[] = ['Fără atributul style: stilurile stau în assets/css.', $l];
                } elseif (str_contains($compact, 'javascript:') || str_contains($compact, 'vbscript:')) {
                    $p[] = ['Fără adrese javascript:.', $l];
                } elseif (in_array($a, ['src', 'srcset', 'poster', 'data', 'action', 'formaction', 'background', 'xlink:href'], true) || ($a === 'href' && $nume === 'use')) {
                    if (preg_match('~(^|[\s,])(https?:)?//~i', $v)) {
                        $p[] = ['Resursă de pe alt domeniu: tema folosește doar fișierele ei.', $l];
                    }
                }
            }
        }
        return $p;
    }
}
