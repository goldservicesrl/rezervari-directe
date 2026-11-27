<?php

declare(strict_types=1);

namespace Cazo\Email;

defined('CAZO') or exit;

/**
 * Mesajele către oaspeți rescrise de gazdă în EasyCheck (etapa 4 din „EasyCheck întâi”, contract-mesaje.md):
 * catalogul (același cu `APIMSG_CATALOG` din EasyCheck), validarea cu gărzile (aceleași cazuri ca în EasyCheck:
 * `teste/fixturi/mesaje-validare-cazuri.json`) și completarea unui șablon.
 *
 * Clasa nu știe nimic despre comenzi sau despre oglindă: primește textul șablonului, valorile câmpurilor și blocurile scrise
 * de site (`Sabloane::mesaj`, din aceleași funcții care scriu azi părțile mesajului) și întoarce bucățile e-mailului, pe care
 * layoutul nucleului le scapă la randare (text simplu, fiecare paragraf un `<p>`, rândurile din paragraf cu `<br>`).
 */
final class SabloaneGazda
{
    /** Câmpurile permise în toate mesajele (fără `{suma}` și `{termen}`). */
    public const CAMPURI = ['oaspete', 'proprietate', 'ref', 'sosire', 'plecare', 'perioada', 'nopti', 'total', 'platit', 'rest', 'contact_gazda', 'link_rezervare'];
    /** Câmpurile proprii unor mesaje (coloana `{suma}` / `{termen}` din contract). */
    public const CAMPURI_MESAJ = ['suma', 'termen'];
    /** Blocurile scrise de site, pe care gazda le poate doar muta. */
    public const BLOCURI = ['bloc_rezervare', 'bloc_contract', 'bloc_plata', 'bloc_transfer', 'bloc_restituire', 'bloc_anulare', 'bloc_motiv', 'bloc_schimbare'];
    public const LIMBI = ['ro', 'en'];
    public const SUBIECT_MAXIM = 150;
    public const CORP_MAXIM = 8000;
    /** Gărzile, în ordinea tabelului din contract. */
    public const GARZI = ['lungime', 'text_simplu', 'acolade', 'camp_nepermis', 'bloc_lipsa', 'bloc_rand', 'iban', 'link'];

    /**
     * Catalogul: cheia => [`o`: obligatorii (blocuri sau `suma`), `p`: blocurile permise în plus, `c`: câmpurile proprii
     * mesajului]. Închis în cod; o schimbare se face întâi în contract, apoi în EasyCheck, apoi aici.
     */
    public const CATALOG = [
        'cerere_primita' => ['o' => ['bloc_rezervare'], 'p' => [], 'c' => ['termen']],
        'cerere_acceptata' => ['o' => ['bloc_contract'], 'p' => ['bloc_plata'], 'c' => []],
        'cerere_refuzata' => ['o' => [], 'p' => ['bloc_rezervare'], 'c' => []],
        'cerere_expirata' => ['o' => [], 'p' => ['bloc_rezervare'], 'c' => []],
        'cerere_netrimisa' => ['o' => [], 'p' => ['bloc_rezervare'], 'c' => []],
        'cerere_indisponibila' => ['o' => ['bloc_motiv'], 'p' => ['bloc_rezervare'], 'c' => []],
        'cerere_respinsa' => ['o' => ['bloc_motiv'], 'p' => ['bloc_rezervare'], 'c' => []],
        'cerere_intarziata' => ['o' => [], 'p' => ['bloc_rezervare'], 'c' => ['termen']],
        'plata_confirmata' => ['o' => ['bloc_plata', 'bloc_contract'], 'p' => ['bloc_rezervare'], 'c' => []],
        'plata_in_procesare' => ['o' => [], 'p' => ['bloc_rezervare'], 'c' => []],
        'plata_esuata' => ['o' => [], 'p' => ['bloc_motiv', 'bloc_rezervare'], 'c' => []],
        'plata_rambursata' => ['o' => ['bloc_motiv'], 'p' => ['bloc_rezervare'], 'c' => []],
        'plata_verificare' => ['o' => [], 'p' => ['bloc_rezervare'], 'c' => []],
        'plata_inchisa' => ['o' => ['bloc_motiv'], 'p' => ['bloc_rezervare'], 'c' => []],
        'transfer_asteptat' => ['o' => ['bloc_transfer'], 'p' => ['bloc_rezervare'], 'c' => ['suma', 'termen']],
        'transfer_reamintire' => ['o' => ['bloc_transfer'], 'p' => ['bloc_rezervare'], 'c' => ['suma', 'termen']],
        'transfer_prelungit' => ['o' => ['bloc_transfer'], 'p' => ['bloc_rezervare'], 'c' => ['suma', 'termen']],
        'transfer_neprimit' => ['o' => [], 'p' => ['bloc_rezervare'], 'c' => ['suma']],
        'transfer_expirat' => ['o' => [], 'p' => ['bloc_rezervare'], 'c' => ['suma']],
        'transfer_anulat' => ['o' => ['bloc_restituire'], 'p' => ['bloc_rezervare'], 'c' => []],
        'transfer_anulat_gazda' => ['o' => ['bloc_restituire'], 'p' => ['bloc_rezervare'], 'c' => []],
        'anulare_oaspete' => ['o' => ['bloc_restituire'], 'p' => ['bloc_rezervare'], 'c' => []],
        'anulare_gazda' => ['o' => ['bloc_restituire'], 'p' => ['bloc_rezervare'], 'c' => []],
        'anulare_ceruta' => ['o' => [], 'p' => ['bloc_rezervare', 'bloc_anulare'], 'c' => []],
        'anulare_pastrata' => ['o' => [], 'p' => ['bloc_rezervare', 'bloc_anulare'], 'c' => []],
        'rambursare_gazda' => ['o' => ['suma'], 'p' => ['bloc_rezervare'], 'c' => ['suma']],
        'restituire_transfer' => ['o' => ['suma'], 'p' => ['bloc_rezervare'], 'c' => ['suma']],
        'anulare_renuntata' => ['o' => [], 'p' => ['bloc_rezervare'], 'c' => []],
        'anulare_renuntata_gratuita' => ['o' => ['bloc_anulare'], 'p' => ['bloc_rezervare'], 'c' => []],
        'rezervare_anulata' => ['o' => ['bloc_motiv'], 'p' => ['bloc_rezervare'], 'c' => []],
        'schimbare_facuta' => ['o' => ['bloc_schimbare'], 'p' => ['bloc_rezervare'], 'c' => []],
        'schimbare_esuata' => ['o' => ['bloc_schimbare'], 'p' => ['bloc_rezervare'], 'c' => []],
    ];

    /**
     * Terminațiile de domeniu pe care garda `link` le recunoaște fără `http://` sau `www.` („un nume de domeniu cu punct și o
     * terminație cunoscută”). Aceeași listă ca în EasyCheck.
     */
    public const TERMINATII = ['ro', 'com', 'net', 'org', 'eu', 'info', 'biz', 'io', 'co', 'me', 'app', 'dev', 'site', 'online', 'store', 'shop', 'xyz',
        'top', 'club', 'live', 'link', 'click', 'page', 'tech', 'pro', 'md', 'bg', 'hu', 'de', 'at', 'ch', 'fr', 'it', 'es', 'uk', 'nl', 'be',
        'pl', 'cz', 'sk', 'gr', 'ru', 'ua', 'us', 'ca', 'tk', 'ml', 'ga', 'cf', 'gq', 'cc', 'tv', 'ws', 'pw', 'ly', 'gl', 'to', 'in', 'cn',
        'tr', 'travel', 'hotel', 'host', 'website', 'space', 'fun', 'icu', 'vip', 'work', 'world', 'today', 'digital', 'email', 'money',
        'pay', 'bank', 'cloud', 'one', 'ai', 'gg', 'su', 'mobi', 'name', 'asia', 'au', 'nz', 'se', 'no', 'dk', 'fi', 'pt', 'ie', 'il'];

    /** Toate cheile catalogului (cele 32 de mesaje). @return list<string> */
    public static function chei(): array
    {
        return array_keys(self::CATALOG);
    }

    /** Câmpurile permise mesajului (în subiect și în corp): cele comune și `{suma}` / `{termen}` unde le permite. @return list<string> */
    public static function campuri(string $cheie): array
    {
        $c = self::CATALOG[$cheie] ?? null;
        return $c === null ? [] : [...self::CAMPURI, ...$c['c']];
    }

    /** Blocurile permise mesajului (obligatorii, apoi celelalte). @return list<string> */
    public static function blocuri(string $cheie): array
    {
        $c = self::CATALOG[$cheie] ?? null;
        return $c === null ? [] : array_values(array_unique([...array_values(array_filter($c['o'], static fn (string $x): bool => $x !== 'suma')), ...$c['p']]));
    }

    /** Câmpurile și blocurile pe care le poate folosi mesajul. @return list<string> */
    public static function permise(string $cheie): array
    {
        return [...self::campuri($cheie), ...self::blocuri($cheie)];
    }

    /**
     * Gărzile încălcate de un șablon: sortate, fără repetări (forma fișierului de cazuri comun și a alertei `mesaj_invalid`);
     * lista goală: șablonul e valid. Port întocmai al lui `apimsg_valideaza()` din EasyCheck, cu precizările din contract
     * („Ce s-a construit”): rândurile aduse la `\n`, caracterele invizibile scoase la `iban` și `link`, un text care pică
     * `text_simplu` nu mai trece prin celelalte gărzi ale lui, o cheie necunoscută e `acolade`. $domenii: domeniile site-ului (cu
     * subdomeniile lor permise în text); fără niciunul, nicio adresă de site nu trece.
     * @param list<string> $domenii
     * @return list<string>
     */
    public static function valideaza(string $cheie, string $subiect, string $corp, array $domenii): array
    {
        if (!isset(self::CATALOG[$cheie])) {
            return ['acolade'];
        }
        $subiect = str_replace(["\r\n", "\r"], "\n", $subiect);
        $corp = str_replace(["\r\n", "\r"], "\n", $corp);
        $blocuri = self::blocuri($cheie);
        $permise = self::permise($cheie);
        $g = [];
        if (trim($subiect) === '' || mb_strlen($subiect, 'UTF-8') > self::SUBIECT_MAXIM || str_contains($subiect, "\n")) {
            $g[] = 'lungime';
        }
        if (trim($corp) === '' || mb_strlen($corp, 'UTF-8') > self::CORP_MAXIM) {
            $g[] = 'lungime';
        }
        array_push($g, ...self::garziText($subiect, $permise, $domenii));
        preg_match_all('~\{(bloc_[a-z_]+)\}~', $subiect, $ms);
        foreach (array_unique($ms[1]) as $b) {
            if (in_array($b, $blocuri, true)) {
                $g[] = 'bloc_rand';
            }
        }
        array_push($g, ...self::garziText($corp, $permise, $domenii));
        foreach ($blocuri as $b) {
            $tok = '{' . $b . '}';
            $n = substr_count($corp, $tok);
            if ($n > 1) {
                $g[] = 'bloc_rand';
                continue;
            }
            if ($n === 1) {
                foreach (explode("\n", $corp) as $rand) {
                    if (str_contains($rand, $tok) && trim($rand) !== $tok) {
                        $g[] = 'bloc_rand';
                        break;
                    }
                }
            }
        }
        foreach (self::CATALOG[$cheie]['o'] as $ob) {
            if ($ob === 'suma' ? !str_contains($corp, '{suma}') && !str_contains($subiect, '{suma}') : !str_contains($corp, '{' . $ob . '}')) {
                $g[] = 'bloc_lipsa';
            }
        }
        $g = array_values(array_unique($g));
        sort($g);
        return $g;
    }

    /** Gărzile unui text (subiectul sau corpul) care nu țin de blocuri: text_simplu, acolade, camp_nepermis, iban, link. @return list<string> */
    private static function garziText(string $t, array $permise, array $domenii): array
    {
        if (!self::textSimplu($t)) {
            return ['text_simplu'];
        }
        $g = [];
        $cunoscute = [...self::CAMPURI, ...self::CAMPURI_MESAJ, ...self::BLOCURI];
        preg_match_all('~\{([a-z_]+)\}~', $t, $m);
        foreach (array_unique($m[1]) as $nume) {
            if (!in_array($nume, $cunoscute, true)) {
                $g[] = 'acolade';
            } elseif (!in_array($nume, $permise, true)) {
                $g[] = 'camp_nepermis';
            }
        }
        if (preg_match('~[{}]~', (string) preg_replace('~\{[a-z_]+\}~', '', $t)) === 1) {
            $g[] = 'acolade';
        }
        if (self::areIban($t)) {
            $g[] = 'iban';
        }
        if (self::areLinkStrain((string) preg_replace('~\{[a-z_]+\}~', ' ', $t), $domenii)) {
            $g[] = 'link';
        }
        return $g;
    }

    /** Garda `text_simplu`: UTF-8 valid, fără caractere de control (nici C1) în afară de rândul nou, fără `<` urmat de literă, `/` sau `!`. */
    public static function textSimplu(string $t): bool
    {
        if (preg_match('~~u', $t) !== 1 || preg_match('~[\x00-\x09\x0B-\x1F\x7F]|[\x{80}-\x{9F}]~u', $t) === 1) {
            return false;
        }
        return preg_match('~<[A-Za-z/!]~', $t) !== 1;
    }

    /**
     * Garda `iban` (precizarea comună cu EasyCheck din fișierul de cazuri, „_iban”): după scoaterea caracterelor invizibile,
     * un șir lipit (două litere, două cifre, 11-30 de litere și cifre, litere mari sau mici) sau unul doar cu litere MARI și
     * cifre, cu câte un spațiu orizontal între ele. Un cod fiscal urmat de cuvinte („CUI RO12345678 din Brașov”) nu e un cont.
     */
    public static function areIban(string $text): bool
    {
        $text = (string) preg_replace('~\p{Cf}+~u', '', $text);
        return preg_match('~(?<![A-Za-z0-9])(?:[A-Za-z]{2}[0-9]{2}[A-Za-z0-9]{11,30}|[A-Z]{2}[0-9]{2}(?:\h?[A-Z0-9]){11,30})~u', $text) === 1;
    }

    /**
     * Garda `link`: o adresă de site în afara domeniilor site-ului (`http://`, `https://`, `www.` sau un nume cu punct și o
     * terminație din `TERMINATII`). Adresele de e-mail sunt permise; câmpurile se scot înainte (`{link_rezervare}` e mereu permis).
     * @param list<string> $domenii
     */
    public static function areLinkStrain(string $text, array $domenii): bool
    {
        foreach (self::gazde($text) as $g) {
            if (!self::gazdaPermisa($g, $domenii)) {
                return true;
            }
        }
        return false;
    }

    /** Gazdele adreselor de site din text, cu litere mici (`apimsg_gazde_in_text`). @return list<string> */
    public static function gazde(string $t): array
    {
        $t = (string) preg_replace('~\p{Cf}+~u', '', $t);
        $t = (string) preg_replace('~[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}~', ' ', $t);
        $o = [];
        if (preg_match_all('#https?://([^\s/?\#<>"\'{}]+)#i', $t, $m)) {
            foreach ($m[1] as $h) {
                $h = (string) preg_replace('~^.*@~', '', $h);
                $o[] = strtolower((string) preg_replace('~:\d*$~', '', $h));
            }
            $t = (string) preg_replace('#https?://[^\s<>"\'{}]*#i', ' ', $t);
        }
        if (preg_match_all('~(?<![A-Za-z0-9.-])((?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+([A-Za-z]{2,24}))(?![A-Za-z0-9-])~', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $gazda = strtolower($x[1]);
                if (str_starts_with($gazda, 'www.') || in_array(strtolower($x[2]), self::TERMINATII, true)) {
                    $o[] = $gazda;
                }
            }
        }
        if (preg_match_all('~(?<![A-Za-z0-9.-])www\.[A-Za-z0-9.-]*~i', $t, $m)) {
            foreach ($m[0] as $h) {
                $o[] = strtolower(rtrim($h, '.'));
            }
        }
        return array_values(array_unique($o));
    }

    /** Gazda e unul dintre domenii sau un subdomeniu al lui (domeniul fără `www.`). */
    public static function gazdaPermisa(string $gazda, array $domenii): bool
    {
        $g = rtrim(strtolower($gazda), '.');
        foreach ($domenii as $d) {
            $d = rtrim(strtolower(trim((string) $d)), '.');
            $d = str_starts_with($d, 'www.') ? substr($d, 4) : $d;
            if ($d !== '' && ($g === $d || str_ends_with($g, '.' . $d))) {
                return true;
            }
        }
        return false;
    }

    /** Subiectul completat: câmpurile scăpate la randare; un câmp gol rămâne gol (subiectul nu se poate lăsa deoparte). */
    public static function subiect(string $subiect, array $campuri): string
    {
        return trim((string) preg_replace('~\s{2,}~u', ' ', self::completeazaText($subiect, $campuri)));
    }

    /**
     * Corpul completat: bucățile e-mailului, în ordine.
     *  - paragrafele sunt despărțite de un rând gol; rândurile unui paragraf rămân rânduri (`<br>` în HTML);
     *  - un paragraf cu un câmp gol se lasă deoparte întreg (doar textul lui: un bloc nu se pierde niciodată din cauza unui câmp);
     *  - un bloc stă singur pe rândul lui; blocul scris doar din fraze, lipit de textul paragrafului, continuă fraza (ca azi:
     *    „Vă mulțumim pentru plată. ... Ați plătit ...”); altfel își scrie bucățile (tabelul, secțiunea) între paragrafe;
     *  - un bloc gol dispare fără urmă.
     * @param array<string,string> $campuri
     * @param array<string, list<array>> $blocuri numele (fără acolade) => bucățile: ['p', text] | ['detalii', rânduri] | ['sectiune', titlu, paragrafe]
     * @return list<array{tip:string, linii?:list<string>, randuri?:list<array>, text?:string}>
     */
    public static function corp(string $corp, array $campuri, array $blocuri): array
    {
        $corp = str_replace("\r\n", "\n", $corp);
        $out = [];
        foreach (preg_split('~\n[ \t]*\n~', trim($corp, "\n")) ?: [] as $paragraf) {
            $linii = explode("\n", $paragraf);
            $gol = false;
            foreach ($linii as $l) {
                if (preg_match('~^\s*\{(bloc_[a-z_]+)\}\s*$~', $l)) {
                    continue;
                }
                preg_match_all('~\{([a-z_]{1,40})\}~', $l, $mm);
                foreach ($mm[1] as $n) {
                    if (trim((string) ($campuri[$n] ?? '')) === '') {
                        $gol = true;
                    }
                }
            }
            $curent = [];
            $inchide = static function () use (&$curent, &$out): void {
                $curent = array_values(array_filter($curent, static fn (string $x): bool => trim($x) !== ''));
                if ($curent !== []) {
                    $out[] = ['tip' => 'p', 'linii' => $curent];
                }
                $curent = [];
            };
            foreach ($linii as $l) {
                if (preg_match('~^\s*\{(bloc_[a-z_]+)\}\s*$~', $l, $b)) {
                    $bucati = $blocuri[$b[1]] ?? [];
                    if ($bucati === []) {
                        continue;
                    }
                    $doarFraze = array_filter($bucati, static fn (array $x): bool => $x[0] !== 'p') === [];
                    if ($doarFraze && $curent !== []) {
                        $curent[count($curent) - 1] = rtrim($curent[count($curent) - 1]) . ' ' . (string) $bucati[0][1];
                        $bucati = array_slice($bucati, 1);
                    }
                    if ($bucati === []) {
                        continue;
                    }
                    $inchide();
                    foreach ($bucati as $x) {
                        if ($x[0] === 'p') {
                            if (trim((string) $x[1]) !== '') {
                                $out[] = ['tip' => 'p', 'linii' => [(string) $x[1]]];
                            }
                        } elseif ($x[0] === 'detalii') {
                            if ($x[1] !== []) {
                                $out[] = ['tip' => 'detalii', 'randuri' => array_values($x[1])];
                            }
                        } elseif ($x[0] === 'sectiune') {
                            $out[] = ['tip' => 'titlu', 'text' => (string) $x[1]];
                            foreach ((array) $x[2] as $p) {
                                $out[] = ['tip' => 'p', 'linii' => [(string) $p], 'mic' => true];
                            }
                        }
                    }
                    continue;
                }
                if (!$gol) {
                    $curent[] = self::completeazaText($l, $campuri);
                }
            }
            $inchide();
        }
        return $out;
    }

    /** Textul simplu al bucăților (pentru previzualizări și teste): paragrafele despărțite de un rând gol. */
    public static function text(array $segmente): string
    {
        $r = [];
        foreach ($segmente as $s) {
            $r[] = match ($s['tip']) {
                'p' => implode("\n", $s['linii']),
                'titlu' => $s['text'],
                'detalii' => implode("\n", array_map(static fn (array $x): string => $x[0] . ': ' . $x[1], $s['randuri'])),
                default => '',
            };
        }
        return implode("\n\n", $r);
    }

    private static function completeazaText(string $text, array $campuri): string
    {
        return (string) preg_replace_callback('~\{([a-z_]{1,40})\}~', static fn (array $x): string => str_starts_with($x[1], 'bloc_') ? '' : (string) ($campuri[$x[1]] ?? ''), $text);
    }
}
