<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Erori;
use Cazo\Securitate\Ip;
use Cazo\Nucleu\Raspuns;
use Cazo\Plati\ContStripe;
use Cazo\Rezervare\LinkOaspete;

defined('CAZO') or exit;

/**
 * Întoarcerea din Checkout și anunțurile Stripe:
 *
 *   GET  /plata/intoarcere?c={ref}&s={CHECKOUT_SESSION_ID}   citește sesiunea pe server (cea salvată pe comandă; `s`
 *                                                            nu schimbă nimic), confirmă și finalizează, 303 spre stare
 *   GET  /plata/renuntare?c={ref}                            expiră sesiunea, comanda devine `abandonata`, înapoi la
 *                                                            comandă, cu datele păstrate (legată de cookie-ul comenzii)
 *   POST /stripe-anunt                                       corpul de cel mult 256 KB, semnătura, apoi 120 de anunțuri
 *                                                            semnate pe minut, deduplicarea, 200 imediat; procesarea
 *                                                            după răspuns
 *
 * Auditul de securitate.2026: la anunțurile Stripe, ca la cele EasyCheck, semnătura se verifică înaintea
 * limitei globale, iar cererile refuzate au limita lor, pe rețea (AnuntEasyCheck::refuzat).
 */
final class Plata
{
    public const LIMITA_MINUT = 120;
    /** Întoarcerile din Checkout pe minut, de pe aceeași rețea (/24). */
    public const LIMITA_INTOARCERE = 20;

    public static function intoarcere(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        if (!$app->limitator()->incearca('intoarcere:ip:' . Ip::trunchiaza($c->ip), self::LIMITA_INTOARCERE, 60)) {
            return Erori::pagina($app->traducator($limba), 429, $app->url()->cale('/', $limba));
        }
        $cm = $app->comenzi()->dupaRef((string) ($c->query['c'] ?? ''));
        if ($cm === null || $cm['fel'] !== 'card') {
            return Raspuns::redirectare($app->url()->cale('/', $limba));
        }
        if ($app->plataOnline()->confirma((int) $cm['id'], 'intoarcere') === 'platita') {
            $app->finalizare()->ruleaza((int) $cm['id'], 'intoarcere');
        }
        $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
        $r = Raspuns::redirectare($app->url()->cale('/comanda/' . $cm['ref'], $limba));
        if (!$app->linkOaspete()->cookieValid($c->cookie(LinkOaspete::COOKIE), (string) $cm['ref']) && self::sesiuneaComenzii($app, $cm, (string) ($c->query['s'] ?? ''))) {
            // Plata s-a încheiat în alt context de navigare: cookie-ul comenzii se pune din nou, dar doar cu sesiunea
            // Checkout a comenzii (parametrul `s` adus de Stripe), nu cu simpla referință PG-....
            $r->cookie($app->linkOaspete()->setCookie((string) $cm['ref']));
        }
        return $r;
    }

    /**
     * `s` e sesiunea Checkout salvată pe comandă (hash_equals) și, citită de pe server, sesiunea aparține comenzii
     * (client_reference_id).
     */
    private static function sesiuneaComenzii(Aplicatie $app, array $cm, string $s): bool
    {
        $pl = $app->checkout()->ultima((int) $cm['id']);
        if ($pl === null || !is_string($pl['stripe_sesiune']) || $s === '' || !hash_equals($pl['stripe_sesiune'], $s)) {
            return false;
        }
        try {
            $ses = $app->checkout()->citeste($s);
        } catch (\Cazo\Plati\EroareStripe) {
            return false;
        }
        return is_string($ses['client_reference_id'] ?? null) && hash_equals((string) $cm['ref'], $ses['client_reference_id']);
    }

    public static function renuntare(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $ref = (string) ($c->query['c'] ?? '');
        $cm = $app->comenzi()->dupaRef($ref);
        if ($cm === null || $cm['fel'] !== 'card' || !$app->linkOaspete()->cookieValid($c->cookie(LinkOaspete::COOKIE), $ref)) {
            return Raspuns::redirectare($app->url()->cale('/', $limba));
        }
        if ($app->plataOnline()->renunta((int) $cm['id']) !== 'abandonata') {
            return Raspuns::redirectare($app->url()->cale('/comanda/' . $ref, $limba));
        }
        $q = ['sosire' => (string) $cm['sosire'], 'plecare' => (string) $cm['plecare']];
        foreach ($app->comenzi()->camere($cm) as $i => $x) {
            $q['camere'][$i] = ['camera' => $x['camera'], 'adulti' => (string) $x['adulti'], 'copii' => (string) $x['copii']];
        }
        if ($cm['plan'] !== null) {
            $q['plan'] = (string) $cm['plan'];
        }
        $q['reia'] = $ref;
        return Raspuns::redirectare($app->url()->cale('/rezerva', $limba, $q));
    }

    public static function anunt(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $corp = $c->corp();
        if ($corp === '' || strlen($corp) > ContStripe::CORP_MAXIM) {
            return AnuntEasyCheck::refuzat($app, $c, 'stripe_anunt_refuzat', 'Anunț Stripe refuzat', 'corp');
        }
        $antet = $c->antet('stripe-signature');
        if (!$app->stripe()->semnaturaValida($corp, $antet)) {
            return AnuntEasyCheck::refuzat($app, $c, 'stripe_anunt_refuzat', 'Anunț Stripe refuzat', 'semnatura');
        }
        if (!$app->limitator()->incearca('stripe_anunt:site', self::LIMITA_MINUT, 60)) {
            return Raspuns::gol(429)->antet('Retry-After', '60');
        }
        $rez = $app->stripe()->primeste($corp, $antet);
        if ($rez['rezultat'] === 'semnatura' || $rez['rezultat'] === 'forma') {
            $app->jurnal()->avertisment('Anunț Stripe refuzat', ['motiv' => $rez['rezultat']]);
            return Raspuns::gol(400);
        }
        if ($rez['rezultat'] === 'primit') {
            $e = $rez['eveniment'];
            $app->dupaRaspuns(static function () use ($app, $e): void {
                // Zăvorul comenzii ocupat sau o eroare: rândul rămâne neprocesat, iar cronul îl reia (reiaAnunturile).
                if ($app->plataOnline()->dinAnunt($e)) {
                    $app->stripe()->procesat((string) $e['id']);
                }
                $app->emailuri()->trimite(5);
            });
        }
        return Raspuns::gol(200);
    }
}
