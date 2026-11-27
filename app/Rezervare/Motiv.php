<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Nucleu\Aplicatie;
use Cazo\Teme\Componente;

defined('CAZO') or exit;

/**
 * Textul unei comenzi încheiate fără rezervare, după motivul real: pagina de stare și e-mailul folosesc
 * aceleași texte. Fiecare motiv are fraza lui: nopțile ocupate (409 indisponibil), restricția (422 restrictie),
 * totalul actualizat (422 pret_diferit), cheia site-ului (401/403: un text neutru, gazda primește alerta), o
 * verificare la gazdă (celelalte coduri), refuzul gazdei, termenul încheiat, cererea netrimisă.
 */
final class Motiv
{
    /** Codurile EasyCheck ale cheii (401, 403): oaspetele vede textul neutru. */
    public const CHEIE = ['cheie_lipsa', 'cheie_invalida', 'cheie_in_adresa', 'scop_insuficient', 'doar_server', 'functie_indisponibila', 'cont_inactiv', 'proprietate_inactiva', 'http_401', 'http_403'];

    /**
     * @param bool $absolut adresele complete (pentru e-mail)
     * @return array{text:string, cauta:bool, continua:?string, motiv:string}
     */
    public static function refuz(Aplicatie $app, Traducator $t, array $cm, string $contact, bool $absolut = false): array
    {
        $limba = $t->limba();
        $detalii = json_decode((string) ($cm['eroare_detalii'] ?? ''), true) ?: [];
        $cod = (string) ($cm['eroare_cod'] ?? '');
        $r = ['text' => '', 'cauta' => false, 'continua' => null, 'motiv' => $cod];
        if (($cm['fel'] ?? 'cash') === 'card') {
            $card = self::card($app, $t, $cm, $contact, $absolut, $detalii, $cod);
            if ($card !== null) {
                return $card;
            }
        }
        switch ($cm['stare']) {
            case 'indisponibila':
                $zile = array_values(array_filter((array) ($detalii['zile'] ?? []), 'is_string'));
                $r['text'] = $t->t('stare.indisponibila', ['zile' => self::zile($zile, $limba)]);
                $r['cauta'] = true;
                $r['motiv'] = 'indisponibil';
                return $r;
            case 'cerere_refuzata':
                return ['text' => $t->t('stare.refuzata'), 'cauta' => true, 'continua' => null, 'motiv' => 'refuzata'];
            case 'cerere_expirata':
                return ['text' => $t->cuContact('stare.expirata', $contact), 'cauta' => true, 'continua' => null, 'motiv' => 'expirata'];
            // Transferul bancar: plata marcată neprimită de gazdă sau termenul trecut; oaspetele care a plătit totuși scrie gazdei.
            case Transfer::NEPRIMIT:
                return ['text' => $t->cuContact('stare.transfer_neprimit', $contact), 'cauta' => true, 'continua' => null, 'motiv' => 'transfer_neprimit'];
            case Transfer::EXPIRAT:
                return ['text' => $t->cuContact('stare.transfer_expirat', $contact), 'cauta' => true, 'continua' => null, 'motiv' => 'transfer_expirat'];
            case 'abandonata':
                return ['text' => $t->t('stare.abandonata'), 'cauta' => true, 'continua' => null, 'motiv' => 'abandonata'];
        }
        if ($cod === 'restrictie') {
            $comp = new Componente($app, $app->modelVedere($limba), $t, $limba, null, '0.0.0.0/24');
            return ['text' => $comp->mesajRestrictie($detalii), 'cauta' => true, 'continua' => null, 'motiv' => $cod];
        }
        if ($cod === 'pret_diferit' && is_int($detalii['total'] ?? null)) {
            $q = ['sosire' => (string) $cm['sosire'], 'plecare' => (string) $cm['plecare']];
            foreach ($app->comenzi()->camere($cm) as $i => $x) {
                $q['camere'][$i] = ['camera' => $x['camera'], 'adulti' => (string) $x['adulti'], 'copii' => (string) $x['copii']];
            }
            if ($cm['plan'] !== null) {
                $q['plan'] = (string) $cm['plan'];
            }
            $r['text'] = $t->t('stare.pret_diferit', ['suma' => Format::baniOaspete($detalii['total'], $limba)]);
            $r['continua'] = $absolut ? $app->url()->absolut('/rezerva', $limba, $q) : $app->url()->cale('/rezerva', $limba, $q);
            return $r;
        }
        if ($cod === 'ec_indisponibil') {
            $r['text'] = $t->cuContact('stare.netrimisa', $contact);
            return $r;
        }
        if (in_array($cod, self::CHEIE, true) || in_array($detalii['_status'] ?? null, [401, 403], true)) {
            $r['text'] = $t->cuContact('stare.respinsa_cheie', $contact);
            $r['motiv'] = 'cheie';
            return $r;
        }
        $r['text'] = $t->cuContact('stare.respinsa', $contact);
        return $r;
    }

    /**
     * Textele ramurii online: plata refuzată de bancă și rambursările automate, după motiv. Fiecare
     * rambursare spune „Vă returnăm integral suma plătită.”, plus ce urmează: camerele libere (noaptea ocupată),
     * noul total, alte date (restricția), o rezervare nouă (6 ore fără răspuns) sau contactul gazdei.
     */
    private static function card(Aplicatie $app, Traducator $t, array $cm, string $contact, bool $absolut, array $detalii, string $cod): ?array
    {
        $limba = $t->limba();
        $q = ['sosire' => (string) $cm['sosire'], 'plecare' => (string) $cm['plecare']];
        foreach ($app->comenzi()->camere($cm) as $i => $x) {
            $q['camere'][$i] = ['camera' => $x['camera'], 'adulti' => (string) $x['adulti'], 'copii' => (string) $x['copii']];
        }
        if ($cm['plan'] !== null) {
            $q['plan'] = (string) $cm['plan'];
        }
        $url = static fn (string $cale, array $query): string => $absolut ? $app->url()->absolut($cale, $limba, $query) : $app->url()->cale($cale, $limba, $query);
        if ($cm['stare'] === 'plata_esuata') {
            return ['text' => $t->t('stare.plata_esuata'), 'cauta' => false, 'continua' => $url('/rezerva', $q + ['reia' => (string) $cm['ref']]), 'motiv' => 'plata_esuata'];
        }
        if ($cm['stare'] === 'inchisa_gazda') {
            return ['text' => $t->cuContact('stare.inchisa_gazda', $contact), 'cauta' => false, 'continua' => null, 'motiv' => 'inchisa_gazda'];
        }
        if (!in_array($cm['stare'], ['de_rambursat', 'rambursata', 'autorizare_anulata'], true)) {
            return null;
        }
        // o plată doar rezervată pe card se eliberează; textele spun asta în locul rambursării. Cât eliberarea e încă
        // în drum (plata tot autorizată), textul spune că o facem acum, cu același buton al motivului.
        $r = self::cardRefuz($app, $t, $cm, $contact, $detalii, $cod, $url, $q, $app->plataOnline()->cuRetinere((int) $cm['id']) ? 'stare.eliberare' : 'stare.rambursare');
        if ($cm['stare'] === 'de_rambursat' && $app->plataOnline()->autorizata((int) $cm['id']) !== null) {
            $r['text'] = $t->t('stare.eliberam');
        } elseif ($cod === 'captura' && in_array($cm['ec_orfana'] ?? null, ['de_cautat', 'de_anulat'], true)) {
            // Anularea în EasyCheck e încă în lucru: textul o spune ca atare, fără s-o afirme făcută.
            // După e-mailul de rezervă (o zi fără anulare), pagina spune ce spune și e-mailul: anularea, confirmată separat.
            $r['text'] = $t->t(($app->plataOnline()->cuRetinere((int) $cm['id']) ? 'stare.eliberare' : 'stare.rambursare') . ($cm['email_refuz_la'] !== null ? '_captura_separat' : '_captura_anulam'));
        }
        return $r;
    }

    /** Textul refuzului după motiv, în familia `$k` (rambursarea sau eliberarea). */
    private static function cardRefuz(Aplicatie $app, Traducator $t, array $cm, string $contact, array $detalii, string $cod, \Closure $url, array $q, string $k): array
    {
        $limba = $t->limba();
        $r = ['text' => '', 'cauta' => false, 'continua' => null, 'motiv' => $cod];
        switch ($cod) {
            case 'noapte_ocupata':
                $zile = array_values(array_filter((array) ($detalii['zile'] ?? []), 'is_string'));
                $r['text'] = $t->t(count($zile) === 1 ? $k . '_ocupata_una' : $k . '_ocupata', ['zile' => self::zile($zile, $limba)]);
                $r['cauta'] = true;
                return $r;
            case 'pret_diferit':
                if (is_int($detalii['total'] ?? null)) {
                    $r['text'] = $t->t($k . '_pret', ['suma' => Format::baniOaspete($detalii['total'], $limba)]);
                    $r['continua'] = $url('/rezerva', $q);
                    return $r;
                }
                break;
            case 'restrictie':
                $comp = new Componente($app, $app->modelVedere($limba), $t, $limba, null, '0.0.0.0/24');
                $r['text'] = $comp->mesajRestrictie($detalii) . ' ' . $t->t($k . '_restrictie');
                $r['cauta'] = true;
                return $r;
            // fereastra de 10 minute trecută, captura imposibilă, rezervarea anulată de gazdă înainte de confirmare; cu
            // banii deja capturați (și returnați), textul spune rambursarea, ca subiectul e-mailului.
            case 'fereastra':
            case 'captura':
                $r['text'] = $t->t($k . '_' . $cod);
                $r['cauta'] = true;
                return $r;
            case 'anulata_gazda':
                $r['text'] = $t->t($k . '_anulata_gazda');
                return $r;
            case 'ec_indisponibil':
                $r['text'] = $t->t($k . '_reveniti');
                $r['cauta'] = true;
                return $r;
        }
        $r['text'] = $t->cuContact($k, $contact);
        return $r;
    }

    /** @param list<string> $zile */
    public static function zile(array $zile, string $limba): string
    {
        $f = array_map(static fn (string $z): string => Format::zi($z, $limba), array_slice($zile, 0, 5));
        return implode(', ', $f) . (count($zile) > 5 ? ' …' : '');
    }
}
