<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

defined('CAZO') or exit;

/**
 * Proiecțiile oglinzii, ca funcții pure: corpul unei resurse EasyCheck → rândurile tabelelor locale.
 * Câmpurile necunoscute se ignoră (rămân în corpul brut din `ec_resurse`); un câmp cerut care lipsește sau are
 * altă formă aruncă \UnexpectedValueException, iar oglinda păstrează atunci datele de dinainte.
 */
final class Proiectii
{
    private const CAMERA = '~^cam_[0-9a-f]{16}$~';
    private const PLAN = '~^plan_(std|[0-9a-f]{16})$~';
    private const ZI = '~^\d{4}-\d{2}-\d{2}$~';
    private const SHA = '~^[0-9a-f]{64}$~';
    private const ENUM = '~^[a-z_]{1,32}$~';

    /**
     * GET /disponibilitate → `nopti`.
     * @return list<array{camera:string, zi:string, libere:int, inchis:int}>
     */
    public static function disponibilitate(array $d): array
    {
        $rez = [];
        foreach (self::lista($d, 'camere') as $i => $c) {
            $cam = self::sir($c, 'id', self::CAMERA, "camere[$i]");
            foreach (self::lista($c, 'nopti', "camere[$i]") as $j => $n) {
                $rez[] = [
                    'camera' => $cam,
                    'zi' => self::sir($n, 'zi', self::ZI, "camere[$i].nopti[$j]"),
                    'libere' => self::intreg($n, 'libere', "camere[$i].nopti[$j]", 0),
                    'inchis' => self::bool($n, 'inchis', "camere[$i].nopti[$j]") ? 1 : 0,
                ];
            }
        }
        return $rez;
    }

    /**
     * GET /preturi → `nopti_pret`, `planuri` (cu avansul și anularea pe plan) și `taxe_ec`.
     * @return array{nopti: list<array>, planuri: list<array>, taxe: array}
     */
    public static function preturi(array $d): array
    {
        $pePersoana = [];
        $nopti = [];
        foreach (self::lista($d, 'camere') as $i => $c) {
            $cam = self::sir($c, 'id', self::CAMERA, "camere[$i]");
            foreach (self::lista($c, 'planuri', "camere[$i]") as $j => $p) {
                $et = "camere[$i].planuri[$j]";
                $plan = self::sir($p, 'plan', self::PLAN, $et);
                if (!array_key_exists('pe_persoana', $p)) {
                    throw new \UnexpectedValueException("$et.pe_persoana lipsește");
                }
                if ($p['pe_persoana'] !== null) {
                    $pp = $p['pe_persoana'];
                    if (!is_array($pp)) {
                        throw new \UnexpectedValueException("$et.pe_persoana are altă formă");
                    }
                    $pePersoana[$plan][$cam] = [
                        'ocupare_baza' => self::intreg($pp, 'ocupare_baza', "$et.pe_persoana", 0),
                        'minus_persoana' => self::intreg($pp, 'minus_persoana', "$et.pe_persoana", 0),
                        'plus_persoana' => self::intreg($pp, 'plus_persoana', "$et.pe_persoana", 0),
                        'oaspeti_max' => self::intregSauNull($pp, 'oaspeti_max', "$et.pe_persoana"),
                    ];
                }
                foreach (self::lista($p, 'zile', $et) as $k => $z) {
                    $ez = "$et.zile[$k]";
                    $nopti[] = [
                        'camera' => $cam,
                        'plan' => $plan,
                        'zi' => self::sir($z, 'zi', self::ZI, $ez),
                        'pret' => self::intregSauNull($z, 'pret', $ez, 0),
                        'sejur_minim' => self::intreg($z, 'sejur_minim', $ez, 0),
                        'sejur_minim_prin' => self::intreg($z, 'sejur_minim_prin', $ez, 0),
                        'sejur_maxim' => self::intreg($z, 'sejur_maxim', $ez, 0),
                        'sosire_inchisa' => self::bool($z, 'sosire_inchisa', $ez) ? 1 : 0,
                        'plecare_inchisa' => self::bool($z, 'plecare_inchisa', $ez) ? 1 : 0,
                        'inchis' => self::bool($z, 'inchis', $ez) ? 1 : 0,
                    ];
                }
            }
        }

        $planuri = [];
        foreach (self::lista($d, 'planuri') as $i => $p) {
            $et = "planuri[$i]";
            $id = self::sir($p, 'id', self::PLAN, $et);
            // Enumerările se păstrează ca text: o valoare nouă, apărută în aceeași versiune a API-ului, rămâne
            // vizibilă pentru calculul prețului (care o tratează prudent), fără să oprească toată grila.
            $fel = self::sir($p, 'fel', self::ENUM, $et);
            $regula = $p['regula'] ?? null;
            // `suma_oaspete` (EasyCheck 7.550.142): prețul pornirii plus o sumă pe oaspete, pe noapte (planurile de masă).
            // Prețul planului vine deja socotit în grilă și în `pe_persoana`; regula se păstrează doar ca informație.
            if ($regula !== null && (!is_array($regula) || !in_array($regula['tip'] ?? null, ['procent', 'suma', 'suma_oaspete'], true))) {
                throw new \UnexpectedValueException("$et.regula are altă formă");
            }
            $dinPlan = $p['din_plan'] ?? null;
            if ($dinPlan !== null && (!is_string($dinPlan) || !preg_match(self::PLAN, $dinPlan))) {
                throw new \UnexpectedValueException("$et.din_plan are altă formă");
            }
            $planuri[] = [
                'id' => $id,
                'titlu' => mb_substr(self::sir($p, 'titlu', null, $et), 0, 191),
                'fel' => $fel,
                'tip' => self::enumSauNull($p, 'tip', ['nerambursabil', 'sejur_lung'], $et),
                'prag_nopti' => self::intregSauNull($p, 'prag_nopti', $et, 0),
                'mod' => self::sir($p, 'mod', self::ENUM, $et),
                'din_plan' => $dinPlan,
                'regula' => $regula === null ? null : self::jsonText($regula),
                'rotunjire' => self::enumSauNull($p, 'rotunjire', ['leu', 'leu_sus'], $et),
                'pe_persoana' => isset($pePersoana[$id]) ? self::jsonText($pePersoana[$id]) : null,
                // Câmpurile avansului lipsesc la un API fără avans; atunci, null.
                'avans_procent' => array_key_exists('avans_procent', $p) ? self::intregInterval($p, 'avans_procent', $et, 0, 100) : null,
                'anulare_zile' => array_key_exists('anulare_zile', $p) ? self::intregSauNull($p, 'anulare_zile', $et, 0) : null,
                // Masa inclusă în prețul planului (EasyCheck 7.550.142): codul și eticheta în română și în engleză. Un
                // EasyCheck mai vechi nu le trimite: null, deci temele nu arată nimic.
                ...self::masa($p, $et),
                'ordine' => $i,
            ];
        }

        $taxe = [
            'taxe_suplimentare' => self::lista($d, 'taxe_suplimentare'),
            'taxa_locala' => is_array($d['taxa_locala'] ?? null) ? $d['taxa_locala'] : throw new \UnexpectedValueException('taxa_locala lipsește'),
            'cota_tva_cazare_sutimi' => self::intreg($d['taxa_locala'], 'cota_tva_cazare_sutimi', 'taxa_locala', 0),
            'anulare_zile' => self::intregSauNull($d, 'anulare_zile', '$', 0),
            'moneda' => self::sir($d, 'moneda', '~^[A-Z]{3}$~', '$'),
        ];
        foreach ($taxe['taxe_suplimentare'] as $i => $t) {
            if (!is_array($t) || !is_string($t['fel'] ?? null) || !is_int($t['suma'] ?? null) || !is_string($t['unitate'] ?? null)) {
                throw new \UnexpectedValueException("taxe_suplimentare[$i] are altă formă");
            }
        }
        self::lista($d['taxa_locala'], 'ani', 'taxa_locala');
        return ['nopti' => $nopti, 'planuri' => $planuri, 'taxe' => $taxe];
    }

    /** Felurile de masă ale unui plan (EasyCheck 7.550.142); `fara` și lipsa înseamnă aceeași „fără masă”. */
    public const MESE = ['mic_dejun', 'demipensiune', 'pensiune_completa', 'all_inclusive'];

    /**
     * Masa planului: `masa` (unul din MESE sau null, la „fara”, la lipsă ori la o valoare necunoscută), plus etichetele
     * din EasyCheck, păstrate doar lângă o masă cunoscută. O formă greșită aruncă, ca la restul câmpurilor.
     * @return array{masa:?string, masa_eticheta:?string, masa_eticheta_en:?string}
     */
    public static function masa(array $p, string $et): array
    {
        $cod = $p['masa'] ?? null;
        if ($cod !== null && (!is_string($cod) || !preg_match(self::ENUM, $cod))) {
            throw new \UnexpectedValueException("$et.masa are altă formă");
        }
        $et2 = [];
        foreach (['masa_eticheta', 'masa_eticheta_en'] as $k) {
            $v = $p[$k] ?? null;
            if ($v !== null && !is_string($v)) {
                throw new \UnexpectedValueException("$et.$k are altă formă");
            }
            $v = $v === null ? '' : trim(\Cazo\I18n\Format::diacritice(mb_substr($v, 0, 191)));
            $et2[$k] = $v === '' ? null : $v;
        }
        if (!in_array($cod, self::MESE, true)) {
            return ['masa' => null, 'masa_eticheta' => null, 'masa_eticheta_en' => null];
        }
        return ['masa' => $cod] + $et2;
    }

    /**
     * Pozele din GET /proprietate și GET /camere, unice după amprentă, în ordinea galeriei.
     * @return list<array{sha256:string, url:string, latime:?int, inaltime:?int, pozitie:int}>
     */
    public static function poze(?array $proprietate, ?array $camere): array
    {
        $rez = [];
        $liste = [];
        if ($proprietate !== null) {
            $liste[] = ['proprietate', $proprietate['poze'] ?? []];
        }
        foreach (($camere['camere'] ?? []) as $i => $c) {
            if (is_array($c)) {
                $liste[] = ["camere[$i]", $c['poze'] ?? []];
            }
        }
        foreach ($liste as [$et, $poze]) {
            if (!is_array($poze) || !array_is_list($poze)) {
                throw new \UnexpectedValueException("$et.poze are altă formă");
            }
            foreach ($poze as $j => $p) {
                if (!is_array($p)) {
                    throw new \UnexpectedValueException("$et.poze[$j] are altă formă");
                }
                $sha = self::sir($p, 'sha256', self::SHA, "$et.poze[$j]");
                if (isset($rez[$sha])) {
                    continue;
                }
                $rez[$sha] = [
                    'sha256' => $sha,
                    'url' => self::sir($p, 'url', '~^https?://~', "$et.poze[$j]"),
                    'latime' => self::intregSauNull($p, 'latime', "$et.poze[$j]", 0),
                    'inaltime' => self::intregSauNull($p, 'inaltime', "$et.poze[$j]", 0),
                    'pozitie' => is_int($p['pozitie'] ?? null) ? $p['pozitie'] : $j + 1,
                ];
            }
        }
        return array_values($rez);
    }

    /**
     * Rezumatul cheii din GET /eu: proprietatea, modul, scopul, sursa prețului, starea anunțurilor. Bifele cheii
     * (`pret.include`) sunt învechite: alegerea se face în panoul site-ului; rezumatul le mai ține doar pentru
     * migrarea 0031.
     */
    public static function eu(array $d): array
    {
        $p = is_array($d['proprietate'] ?? null) ? $d['proprietate'] : throw new \UnexpectedValueException('proprietate lipsește');
        $pret = is_array($d['pret'] ?? null) ? $d['pret'] : throw new \UnexpectedValueException('pret lipsește');
        $include = $pret['include'] ?? null;
        $wh = $d['webhook'] ?? null;
        // EasyCheck 7.550.88: cheia înlocuită de cheia curentă prin „Înlocuiește”, sau null (lipsă la un EasyCheck mai vechi).
        $inl = $d['cheie']['inlocuieste'] ?? null;
        $inl = is_array($inl) && is_string($inl['prefix'] ?? null) && preg_match('~^[0-9A-Za-z]{8}$~', $inl['prefix'])
            ? ['prefix' => $inl['prefix'], 'inlocuita_la' => is_string($inl['inlocuita_la'] ?? null) ? $inl['inlocuita_la'] : null] : null;
        return [
            'proprietate' => self::sir($p, 'id', '~^prop_[0-9a-f]{16}$~', 'proprietate'),
            'nume' => mb_substr(self::sir($p, 'nume', null, 'proprietate'), 0, 191),
            'scop' => self::sir($d, 'scop', '~^(citire|rezervari)$~', '$'),
            'mod' => self::sir($d, 'mod', '~^(live|proba)$~', '$'),
            'sursa' => self::sir($pret, 'sursa', '~^(easycheck|site)$~', 'pret'),
            'include' => is_array($include) ? ['taxe' => (bool) ($include['taxe'] ?? false), 'taxa_locala' => (bool) ($include['taxa_locala'] ?? false)] : null,
            'inlocuieste' => $inl,
            // EasyCheck 7.550.120: ce are pornit EasyCheck pentru proprietate (`stare`, `plati`, `comenzi`, `mesaje`); null la
            // un EasyCheck mai vechi. Cheile necunoscute trec mai departe, valorile care nu sunt adevărat/fals rămân afară.
            'functii' => is_array($d['functii'] ?? null) ? array_filter($d['functii'], 'is_bool') : null,
            // unde primesc oaspeții rezervările directe (`site_web.rezervari_pe`,
            // pagina | site); null la un EasyCheck mai vechi sau cu altă valoare.
            'rezervari_pe' => in_array($d['site_web']['rezervari_pe'] ?? null, ['pagina', 'site'], true) ? $d['site_web']['rezervari_pe'] : null,
            'webhook' => is_array($wh) ? ['stare' => in_array($wh['stare'] ?? null, ['activ', 'oprit'], true) ? $wh['stare'] : 'activ', 'ultima_livrare_la' => is_string($wh['ultima_livrare_la'] ?? null) ? $wh['ultima_livrare_la'] : null] : null,
        ];
    }

    // ---- verificările de formă ----

    private static function lista(array $d, string $cheie, string $et = '$'): array
    {
        $v = $d[$cheie] ?? null;
        if (!is_array($v) || !array_is_list($v)) {
            throw new \UnexpectedValueException("$et.$cheie lipsește sau nu e listă");
        }
        foreach ($v as $i => $e) {
            if (!is_array($e)) {
                throw new \UnexpectedValueException("$et.{$cheie}[$i] are altă formă");
            }
        }
        return $v;
    }

    private static function sir(array $d, string $cheie, ?string $model, string $et): string
    {
        $v = $d[$cheie] ?? null;
        if (!is_string($v) || ($model !== null && !preg_match($model, $v))) {
            throw new \UnexpectedValueException("$et.$cheie lipsește sau are altă formă");
        }
        return $v;
    }

    private static function intreg(array $d, string $cheie, string $et, ?int $min = null): int
    {
        $v = $d[$cheie] ?? null;
        if (!is_int($v) || ($min !== null && $v < $min)) {
            throw new \UnexpectedValueException("$et.$cheie lipsește sau are altă formă");
        }
        return $v;
    }

    private static function intregInterval(array $d, string $cheie, string $et, int $min, int $max): int
    {
        $v = self::intreg($d, $cheie, $et, $min);
        if ($v > $max) {
            throw new \UnexpectedValueException("$et.$cheie în afara intervalului");
        }
        return $v;
    }

    private static function intregSauNull(array $d, string $cheie, string $et, ?int $min = null): ?int
    {
        if (!array_key_exists($cheie, $d)) {
            throw new \UnexpectedValueException("$et.$cheie lipsește");
        }
        return $d[$cheie] === null ? null : self::intreg($d, $cheie, $et, $min);
    }

    private static function bool(array $d, string $cheie, string $et): bool
    {
        $v = $d[$cheie] ?? null;
        if (!is_bool($v)) {
            throw new \UnexpectedValueException("$et.$cheie lipsește sau are altă formă");
        }
        return $v;
    }

    /** @param string[] $cunoscute valorile din openapi-v1.json (documentare); o valoare nouă trece, ca text scurt */
    private static function enumSauNull(array $d, string $cheie, array $cunoscute, string $et): ?string
    {
        $v = $d[$cheie] ?? null;
        if ($v !== null && (!is_string($v) || !preg_match(self::ENUM, $v))) {
            throw new \UnexpectedValueException("$et.$cheie are altă formă");
        }
        return $v;
    }

    private static function jsonText(array $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
