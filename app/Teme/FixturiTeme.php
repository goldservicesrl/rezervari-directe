<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/**
 * Fixturile randării de probă a temelor: o proprietate inventată, în forma API-ului public
 * EasyCheck documentat (GET /eu, /proprietate, /camere, /disponibilitate, /preturi), în două variante:
 *
 *  - `normal`: o pensiune cu trei camere, poze, descrieri în română și în engleză;
 *  - `extrem`: 50 de camere (cât primește panoul), zero poze, nume de 80 de caractere, engleza lipsă (descrierile doar în română).
 *
 * Prețul vine din motorul local (sursa `site`), ca proba să nu ceară GET /total.
 */
final class FixturiTeme
{
    public const VARIANTE = ['normal', 'extrem'];
    public const PROPRIETATE = 'prop_0000000000000001';

    public static function eu(string $varianta): array
    {
        return ['proprietate' => ['id' => self::PROPRIETATE, 'nume' => self::nume($varianta)], 'scop' => 'rezervari', 'mod' => 'proba',
            'pret' => ['sursa' => 'site', 'include' => null], 'limite' => ['citiri_pe_minut' => 120, 'scrieri_pe_minut' => 30, 'cereri_pe_zi' => 20000, 'proprietate_pe_minut' => 240],
            'versiune_api' => 'v1', 'webhook' => null];
    }

    public static function nume(string $varianta): string
    {
        return $varianta === 'extrem' ? self::lung('Pensiunea cu numele cel mai lung din toată zona de munte a județului Brașov', 80) : 'Pensiunea Probă';
    }

    /** @return list<array{url:string, sha256:string, latime:int, inaltime:int, pozitie:int}> */
    public static function poze(string $varianta, int $n, int $start = 0): array
    {
        if ($varianta === 'extrem') {
            return [];
        }
        $r = [];
        for ($i = 0; $i < $n; $i++) {
            $r[] = ['url' => 'https://easycheck.ro/p/' . ($start + $i) . '.jpg', 'sha256' => hash('sha256', 'poza-' . ($start + $i)), 'latime' => $i % 2 ? 800 : 1200,
                'inaltime' => $i % 2 ? 1200 : 800, 'pozitie' => $i + 1];
        }
        return $r;
    }

    public static function proprietate(string $varianta): array
    {
        $extrem = $varianta === 'extrem';
        $ro = 'Pensiune de probă, cu camere luminoase, grădină și mic dejun la cerere. Gazdele vă așteaptă cu informații despre traseele din zonă.';
        return [
            'id' => self::PROPRIETATE, 'nume' => self::nume($varianta), 'tip' => 'pensiune',
            'descriere' => $extrem ? ['ro' => str_repeat($ro . ' ', 6)] : ['ro' => $ro, 'en' => 'A test guesthouse with bright rooms, a garden and breakfast on request.'],
            'adresa' => ['strada' => $extrem ? self::lung('Strada Lungă a Pădurii de Brazi', 80) : 'Strada Exemplului 1', 'localitate' => 'Brașov', 'judet' => 'Brașov'],
            'coordonate' => $extrem ? null : ['lat' => 45.6427, 'lng' => 25.5887],
            'ore' => ['checkin_de_la' => '14:00', 'plecare_pana_la' => '11:00'],
            'reguli_casa' => ['copii' => true, 'bebelusi' => true, 'animale' => false, 'animale_max' => null, 'evenimente' => false, 'fumat' => false],
            'anulare_zile' => 7,
            'contact' => ['email' => 'rezervari@pensiunea-proba.test', 'telefon' => '+40 700 000 000'],
            'operator' => ['denumire' => 'Probă Turism SRL', 'cui' => 'RO12345678', 'registrul_comertului' => 'J08/123/2020', 'sediu' => 'Brașov, Strada Exemplului 1',
                'sediu_tip' => 'social', 'capital_social' => '200 lei', 'certificat_clasificare' => '1234/2026',
                // EasyCheck 7.550.92: documentele de clasificare, cu tipul și categoria.
                'clasificari' => [['fel' => 'certificat', 'numar' => '1234/2026', 'tip_structura' => 'Pensiune turistică', 'tip_structura_en' => 'Tourist guesthouse', 'categorie' => 3, 'camere' => []]]],
            'linkuri_platforme' => [],
            'poze' => self::poze($varianta, 8),
            'actualizat_la' => '2026-11-14T08:00:00Z',
        ];
    }

    /** @return list<string> */
    public static function idCamere(string $varianta): array
    {
        $r = [];
        for ($i = 1; $i <= ($varianta === 'extrem' ? ModelVedere::CAMERE_MAX : 3); $i++) {
            $r[] = 'cam_' . str_pad(dechex($i), 16, '0', STR_PAD_LEFT);
        }
        return $r;
    }

    public static function camere(string $varianta): array
    {
        $titluri = ['Cameră dublă', 'Apartament cu două camere', 'Cameră de familie'];
        $r = [];
        foreach (self::idCamere($varianta) as $i => $id) {
            $titlu = $varianta === 'extrem' ? self::lung('Camera ' . ($i + 1) . ' cu balcon spre munte, baie proprie și pat matrimonial foarte larg', 80) : $titluri[$i];
            $r[] = ['id' => $id, 'titlu' => $titlu, 'unitati' => $i === 0 ? 2 : 1, 'oaspeti_max' => 2 + $i % 4, 'poze' => self::poze($varianta, 4, 10 * ($i + 1))];
        }
        return ['camere' => $r, 'actualizat_la' => '2026-11-14T08:00:00Z'];
    }

    public static function disponibilitate(string $varianta, string $de, string $pana): array
    {
        $camere = [];
        foreach (self::idCamere($varianta) as $i => $id) {
            $u = $i === 0 ? 2 : 1;
            $nopti = [];
            for ($t = strtotime($de . ' 00:00:00 UTC'); $t < strtotime($pana . ' 00:00:00 UTC'); $t += 86400) {
                $nopti[] = ['zi' => gmdate('Y-m-d', $t), 'libere' => $u, 'inchis' => false];
            }
            $camere[] = ['id' => $id, 'unitati' => $u, 'nopti' => $nopti];
        }
        return ['proprietate' => self::PROPRIETATE, 'de' => $de, 'pana' => $pana, 'camere' => $camere];
    }

    public static function preturi(string $varianta, string $de, string $pana): array
    {
        $camere = [];
        foreach (self::camere($varianta)['camere'] as $i => $c) {
            $zile = [];
            for ($t = strtotime($de . ' 00:00:00 UTC'); $t <= strtotime($pana . ' 00:00:00 UTC'); $t += 86400) {
                $zile[] = ['zi' => gmdate('Y-m-d', $t), 'pret' => 20000 + 5000 * ($i % 5), 'sejur_minim' => 1, 'sejur_minim_prin' => 1, 'sejur_maxim' => 0,
                    'sosire_inchisa' => false, 'plecare_inchisa' => false, 'inchis' => false];
            }
            $camere[] = ['id' => $c['id'], 'titlu' => $c['titlu'], 'unitati' => $c['unitati'], 'moneda' => 'RON', 'planuri' => [['plan' => 'plan_std', 'pe_persoana' => null, 'zile' => $zile]]];
        }
        $ani = [];
        for ($a = (int) substr($de, 0, 4); $a <= (int) substr($pana, 0, 4); $a++) {
            $ani[] = ['an' => $a, 'taxe' => []];
        }
        return [
            'proprietate' => self::PROPRIETATE, 'de' => $de, 'pana' => $pana, 'moneda' => 'RON',
            'planuri' => [['id' => 'plan_std', 'titlu' => 'Standard', 'fel' => 'propriu', 'tip' => null, 'prag_nopti' => null, 'mod' => 'camera', 'din_plan' => null,
                'regula' => null, 'rotunjire' => null, 'avans_procent' => 0, 'rest_plata' => 'la_sosire', 'anulare_zile' => 7]],
            'camere' => $camere,
            'taxe_suplimentare' => [],
            'taxa_locala' => ['cota_tva_cazare_sutimi' => 900, 'ani' => $ani],
            'anulare_zile' => 7,
            'actualizat_la' => '2026-11-14T08:00:00Z',
        ];
    }

    /** Un text de exact $n caractere (litere, nu octeți), completat cu „ X”. */
    private static function lung(string $s, int $n): string
    {
        while (mb_strlen($s) < $n) {
            $s .= ' X';
        }
        return mb_substr($s, 0, $n);
    }
}
