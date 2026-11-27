<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Erori;
use Cazo\Nucleu\Raspuns;
use Cazo\Securitate\Ip;

defined('CAZO') or exit;

/**
 * Schimbarea datelor din „Rezervarea mea” (Rezervare\SchimbareDate), în layoutul temei:
 *
 *   GET  /r/{token}/schimba                           noile date (aceleași camere și oaspeți); cu `sosire` și
 *                                                     `plecare`, oferta: disponibilitatea, noul total, diferența,
 *                                                     noul termen gratuit și butonul potrivit
 *   POST /r/{token}/schimba                           pornește schimbarea cu totalul văzut (o a doua apăsare duce
 *                                                     la aceeași schimbare); mai scump: 303 spre plata diferenței
 *   GET  /schimbare/intoarcere?c={ref}&s={sesiune}     întoarcerea din Checkout: sesiunea salvată, citită de pe server
 *   GET  /schimbare/renuntare?c={ref}&k={jeton}        renunțarea la plata diferenței: sesiunea expiră, nimic încasat
 *
 * Adresele plății poartă referința comenzii, nu tokenul „Rezervării mele”: linkul oaspetelui rămâne doar la el.
 */
final class Schimbare
{
    /** Ofertele cerute pe oră, pe comandă (fiecare citește totalul și disponibilitatea din EasyCheck). */
    public const OFERTE_ORA = 30;

    public static function pagina(Aplicatie $app, Cerere $c, array $p, string $limba, ?string $mesaj = null, int $status = 200, ?array $oferta = null, array $cerute = []): Raspuns
    {
        $t = $app->traducator($limba);
        $token = (string) $p['token'];
        $cm = $app->comenzi()->dupaToken($token);
        if ($cm === null || $cm['anonimizat_la'] !== null) {
            return Erori::pagina($t, 404, $app->url()->cale('/', $limba));
        }
        $rmea = $app->url()->cale('/r/' . $token, $limba);
        $k = $app->schimbareDate()->conditii($cm);
        if (!$k['poate'] && $oferta === null) {
            return Raspuns::redirectare($rmea);
        }
        $sosire = (string) ($cerute['sosire'] ?? $c->query['sosire'] ?? '');
        $plecare = (string) ($cerute['plecare'] ?? $c->query['plecare'] ?? '');
        $r0 = Raspuns::html('');
        $d = ['ref' => (string) $cm['ref'], 'mesaj' => $mesaj, 'acum' => $t->t('schimbare.acum', ['perioada' => self::perioada((string) $cm['sosire'], (string) $cm['plecare'], $limba)]),
            'actiune' => $app->url()->cale('/r/' . $token . '/schimba', $limba), 'sosire' => $sosire, 'plecare' => $plecare, 'min' => $app->azi(), 'inapoi' => $rmea];
        if ($sosire !== '' && $plecare !== '' && $k['poate'] && ($oferta['stare'] ?? '') !== 'limita') {
            if ($oferta === null && !$app->limitator()->incearca('schimbare_oferta:' . $cm['id'], self::OFERTE_ORA, 3600)) {
                $d['mesaj'] = $t->t('schimbare.limita');
                $status = 429;
            } else {
                $o = $oferta ?? $app->schimbareDate()->oferta($cm, $sosire, $plecare);
                $d += self::oferta($app, $t, $limba, $cm, $o, $app->jetonCsrf($c, $r0));
            }
        }
        $html = Prezentare::nucleu($app, $limba, 'schimbare', $d);
        $alternative = [];
        foreach ($app->config()->limbi() as $l) {
            $alternative[$l] = $app->url()->absolut('/r/' . $token . '/schimba', $l);
        }
        return Prezentare::paginaRezervare($app, $c, $limba, $t->t('schimbare.titlu'), $html, $alternative, null, $status, $r0->cookies(), ['Referrer-Policy' => 'no-referrer']);
    }

    /** Oferta pentru noile date, ca paragrafe și formularul de confirmare (cu totalul văzut, trimis înapoi). */
    private static function oferta(Aplicatie $app, Traducator $t, string $limba, array $cm, array $o, string $csrf): array
    {
        $bani = static fn (int $s): string => Format::baniOaspete($s, $limba);
        switch ($o['stare']) {
            case 'ok':
                $p = [$t->t('schimbare.oferta', ['perioada' => self::perioada($o['sosire'], $o['plecare'], $limba), 'total' => $bani($o['total'])])];
                // taxa locală a noilor date, cu felul în care se plătește (alegerea din ziua comenzii).
                $taxa = \Cazo\Pret\RegulaPlata::taxa((array) ($o['componente'] ?? []));
                $felTaxa = \Cazo\Rezervare\Rezumat::taxaComanda($cm);
                if ($taxa > 0 && $felTaxa !== null) {
                    $p[] = $t->t('rezumat.comp.taxa_locala') . ': ' . $t->t('rezumat.taxa_locala.' . $felTaxa, ['suma' => $bani($taxa)]);
                }
                if ($cm['fel'] === 'card' && $o['diferenta'] === 0 && $o['la_avans']) {
                    // La plata cu avans (varianta B): avansul plătit rămâne, iar diferența de preț trece în restul de la sosire.
                    $p[] = $t->t('schimbare.avans_ramane', ['suma' => $bani($o['suma_acum']), 'rest' => $bani($o['rest'])]);
                }
                if ($cm['fel'] === 'card') {
                    if ($o['diferenta'] !== 0 || !$o['la_avans']) {
                        $p[] = $o['diferenta'] > 0 ? $t->t('schimbare.mai_scump', ['suma' => $bani($o['diferenta'])])
                            : ($o['diferenta'] < 0 ? $t->t('schimbare.mai_ieftin', ['suma' => $bani(-$o['diferenta'])]) : $t->t('schimbare.la_fel'));
                        if ($o['rest'] > 0) {
                            $p[] = $t->t('comanda.la_sosire', ['suma' => $bani($o['rest'])]);
                        }
                    }
                    if ($o['anulare_limita'] !== null) {
                        $p[] = $t->t('schimbare.termen', ['data' => Format::ziLunga($o['anulare_limita'], $limba)]);
                    }
                    $buton = $o['diferenta'] > 0 ? 'schimbare.buton_plata' : 'schimbare.buton';
                } else {
                    $p[] = $t->t('schimbare.cash', ['total' => $bani($o['total'])]);
                    $p[] = $t->t('schimbare.cash_cerere');
                    $buton = 'schimbare.buton_cerere';
                }
                return ['oferta' => ['paragrafe' => $p, 'buton' => $t->t($buton), 'csrf' => $csrf, 'total' => (string) $o['total'], 'sosire' => $o['sosire'], 'plecare' => $o['plecare']]];
            case 'aceleasi':
                return ['eroare' => $t->t('schimbare.aceleasi')];
            case 'restrictie':
                $comp = new \Cazo\Teme\Componente($app, $app->modelVedere($limba), $t, $limba, null, '0.0.0.0/24');
                return ['eroare' => $comp->mesajRestrictie($o['restrictie'])];
            case 'ocupat':
                return ['eroare' => $t->t('schimbare.ocupat', ['zile' => \Cazo\Rezervare\Motiv::zile($o['zile'], $limba)])];
            case 'indisponibil':
                return ['eroare' => $t->t('schimbare.pret_lipsa')];
        }
        return ['eroare' => $t->t('schimbare.date_gresite')];
    }

    /** POST /r/{token}/schimba. */
    public static function trimite(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $t = $app->traducator($limba);
        $token = (string) $p['token'];
        $cm = $app->comenzi()->dupaToken($token);
        if ($cm === null || $cm['anonimizat_la'] !== null) {
            return Erori::pagina($t, 404, $app->url()->cale('/', $limba));
        }
        $sosire = (string) ($c->camp('sosire') ?? '');
        $plecare = (string) ($c->camp('plecare') ?? '');
        $vazut = (string) ($c->camp('total') ?? '');
        $rmea = $app->url()->cale('/r/' . $token, $limba);
        $cerute = ['sosire' => $sosire, 'plecare' => $plecare];
        // Fiecare apăsare citește totalul și disponibilitatea din EasyCheck: aceeași limită ca ofertele.
        if (!$app->limitator()->incearca('schimbare_oferta:' . $cm['id'], self::OFERTE_ORA, 3600)) {
            return self::pagina($app, $c, $p, $limba, $t->t('schimbare.limita'), 429, ['stare' => 'limita'], $cerute);
        }
        $r = $app->schimbareDate()->porneste((int) $cm['id'], $sosire, $plecare, ctype_digit($vazut) ? (int) $vazut : -1);
        $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
        switch ($r['rez']) {
            case 'plata':
                return Raspuns::redirectare((string) $r['url']);
            case 'in_lucru':
                return Raspuns::redirectare($r['url'] ?? $rmea);
            case 'aplicata':
                return Stare::rezervareaMea($app, $c, $p, $limba, $t->t('schimbare.facuta_mesaj'));
            case 'cerere':
                return Stare::rezervareaMea($app, $c, $p, $limba, $t->t('schimbare.cerere_mesaj'));
            case 'esuata':
                $s = $app->schimbareDate()->dupaId((int) $r['schimbare']);
                return Stare::rezervareaMea($app, $c, $p, $limba, self::textEsec($app, $t, $limba, $s), 409);
            case 'schimbat':
                return self::pagina($app, $c, $p, $limba, $t->t('schimbare.total_schimbat', ['suma' => Format::baniOaspete((int) $r['oferta']['total'], $limba)]), 200, $r['oferta'], $cerute);
            case 'refuz':
                return self::pagina($app, $c, $p, $limba, null, 409, $r['oferta'], $cerute);
            case 'ocupat':
                return self::pagina($app, $c, $p, $limba, $t->t('rmea.anulare.ocupat'), 409, null, $cerute);
            case 'eroare_plata':
                return self::pagina($app, $c, $p, $limba, $t->t('schimbare.eroare_plata'), 503, null, $cerute);
        }
        return Raspuns::redirectare($rmea);
    }

    /** GET /schimbare/intoarcere?c={ref}&s={CHECKOUT_SESSION_ID}. */
    public static function intoarcere(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        if (!$app->limitator()->incearca('intoarcere:ip:' . Ip::trunchiaza($c->ip), Plata::LIMITA_INTOARCERE, 60)) {
            return Erori::pagina($app->traducator($limba), 429, $app->url()->cale('/', $limba));
        }
        $cm = $app->comenzi()->dupaRef((string) ($c->query['c'] ?? ''));
        $s = $cm !== null ? $app->schimbareDate()->intoarcere((int) $cm['id'], (string) ($c->query['s'] ?? '')) : null;
        if ($s === null) {
            return Raspuns::redirectare($app->url()->cale('/', $limba));
        }
        $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
        return Raspuns::redirectare($app->url()->cale('/r/' . $app->comenzi()->token($cm), $limba));
    }

    /** GET /schimbare/renuntare?c={ref}&k={jeton}. */
    public static function renuntare(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $cm = $app->comenzi()->dupaRef((string) ($c->query['c'] ?? ''));
        $s = $cm !== null ? $app->schimbareDate()->renunta((int) $cm['id'], (string) ($c->query['k'] ?? '')) : null;
        if ($s === null) {
            return Raspuns::redirectare($app->url()->cale('/', $limba));
        }
        return Raspuns::redirectare($app->url()->cale('/r/' . $app->comenzi()->token($cm), $limba));
    }

    /**
     * Textul unei schimbări oprite, pentru „Rezervarea mea” și e-mailul oaspetelui: motivul real și datele pe care
     * rămâne rezervarea.
     */
    public static function textEsec(Aplicatie $app, Traducator $t, string $limba, array $s): string
    {
        $param = ['perioada' => self::perioada((string) $s['sosire_veche'], (string) $s['plecare_veche'], $limba),
            'perioada_noua' => self::perioada((string) $s['sosire'], (string) $s['plecare'], $limba)];
        switch (self::situatie($app, $s)) {
            case 'anulata':
                // Comanda s-a anulat cât schimbarea era în lucru: anularea are e-mailul ei, cu banii.
                return $t->t('email.schimbare_esuata.anulata', $param);
            case 'de_refacut':
                // Rezervarea veche e anulată în EasyCheck și n-a fost refăcută: gazda o adaugă din nou (alerta ei).
                return $t->cuContact('email.schimbare_esuata.de_refacut', Rezerva::contactText($app), $param);
        }
        return match ((string) $s['eroare']) {
            'noapte_ocupata' => $t->t('email.schimbare_esuata.ocupat', $param),
            'pret_diferit' => $t->t('email.schimbare_esuata.pret', $param),
            'cerere_refuzata' => $t->t('email.schimbare_esuata.gazda', $param),
            'cerere_expirata' => $t->t('email.schimbare_esuata.expirata', $param),
            'plata_esuata' => $t->t('email.schimbare_esuata.plata', $param),
            default => $t->cuContact('email.schimbare_esuata.alta', Rezerva::contactText($app), $param),
        };
    }

    /**
     * Unde a rămas rezervarea după o schimbare oprită: `anulata` (schimbarea s-a oprit din cauza anulării comenzii),
     * `de_refacut` (rezervarea veche anulată în EasyCheck, fără rezervare nouă și fără refacere: gazda o adaugă din nou)
     * sau `pe_loc` (rezervarea veche e neatinsă sau refăcută). O anulare venită după încheierea schimbării lasă
     * schimbării motivul ei (de exemplu noaptea ocupată): anularea are e-mailul ei.
     */
    public static function situatie(Aplicatie $app, array $s): string
    {
        $c = $app->comenzi()->dupaId((int) $s['comanda_id']);
        if ($c !== null && !in_array($c['stare'], ['confirmata', 'anulare_ceruta'], true)) {
            $momente = array_filter([$c['anulare_pornita_la'] ?? null, $c['anulata_ec_la'] ?? null], static fn ($v): bool => $v !== null);
            $anulataLa = $momente === [] ? null : (string) min($momente);
            $incheiataLa = $s['incheiata_la'] ?? null;
            if (($s['eroare'] ?? null) === 'comanda' || $incheiataLa === null || ($anulataLa !== null && $anulataLa < (string) $incheiataLa)) {
                return 'anulata';
            }
        }
        if ($s['stare'] === 'esuata' && ($s['anulare_veche_la'] ?? null) !== null && ($s['ec_refacuta'] ?? null) === null && ($s['ec_noua'] ?? null) === null) {
            return 'de_refacut';
        }
        return 'pe_loc';
    }

    public static function perioada(string $sosire, string $plecare, string $limba): string
    {
        return Format::zi($sosire, $limba) . ' - ' . Format::zi($plecare, $limba);
    }
}
