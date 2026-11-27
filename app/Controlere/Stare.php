<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\Email\Sabloane;
use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cai;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Erori;
use Cazo\Nucleu\Raspuns;
use Cazo\Rezervare\LinkOaspete;
use Cazo\Rezervare\Motiv;
use Cazo\Rezervare\Rezumat;

defined('CAZO') or exit;

/**
 * Starea comenzii și „Rezervarea mea”, în layoutul temei:
 *
 *   GET  /comanda/{ref}             legată de cookie-ul __Host-pg_comanda; fără JS (noscript), reîncărcarea se rărește
 *                                   (3 s, 10 s, 60 s) și se oprește după aproximativ 32 de minute, cu linkul manual;
 *                                   interogare la 2 s cu JS, cât comanda e în lucru; codul de 6 cifre, când e cerut
 *   GET  /_api/comanda/{ref}        starea, JSON, pentru interogare
 *   POST /comanda/{ref}/cod         codul de 6 cifre; la „ok”, cererea pleacă spre EasyCheck
 *   POST /comanda/{ref}/cod-nou     un cod nou (cel mult 3 pe oră)
 *   GET  /r/{token}                 „Rezervarea mea”, până la anonimizare
 *   GET  /r/{token}.ics             sejurul pentru calendar (după confirmare)
 *   POST /r/{token}/noutati         retragerea acordului pentru noutăți, la un clic
 *   POST /r/{token}/anuleaza        anularea: automată în perioada gratuită și la plata la sosire,
 *                                   cererea către gazdă după perioadă; cu bifa de confirmare și felul văzut pe pagină
 *   POST /r/{token}/am-platit       „Am plătit”, cât rezervarea așteaptă transferul bancar
 *
 * Schimbarea datelor (118-118d) are controlerul ei (Schimbare); „Rezervarea mea” arată secțiunea ei: butonul cât
 * schimbarea e posibilă, schimbarea în lucru, plata diferenței de continuat sau schimbarea făcută.
 */
final class Stare
{
    public const IN_LUCRU = ['trimitere_ec', 'plata_in_curs', 'platita', 'plata_in_procesare', 'de_rambursat', 'de_capturat'];
    /** Reîncărcările fără JS: 10 la 3 s, 8 la 10 s, 30 la 60 s (în jur de 32 de minute), apoi doar linkul. */
    public const REINCARCARI = [[10, 3], [8, 10], [30, 60]];

    /** Secundele până la reîncărcarea cu numărul $n (de la 0), sau null după ultima. */
    public static function pauzaReincarcare(int $n): ?int
    {
        foreach (self::REINCARCARI as [$cate, $secunde]) {
            if ($n < $cate) {
                return $secunde;
            }
            $n -= $cate;
        }
        return null;
    }

    public static function comanda(Aplicatie $app, Cerere $c, array $p, string $limba, ?string $mesajCod = null, int $status = 200): Raspuns
    {
        $t = $app->traducator($limba);
        $ref = (string) $p['ref'];
        $cm = $app->comenzi()->dupaRef($ref);
        if ($cm === null) {
            return Erori::pagina($t, 404, $app->url()->cale('/', $limba));
        }
        $legata = $app->linkOaspete()->cookieValid($c->cookie(LinkOaspete::COOKIE), $ref);
        $d = ['ref' => $ref, 'legata' => $legata, 'stare' => (string) $cm['stare'], 'api' => $app->url()->cale('/_api/comanda/' . $ref, $limba)];
        $refresh = null;
        if ($legata) {
            $d += self::date($app, $t, $limba, $cm);
            if ($cm['stare'] === 'noua' && $cm['cod_confirmat_la'] === null) {
                $o = $app->comenzi()->oaspete($cm);
                $r0 = Raspuns::html('');
                $d['cod'] = ['email' => (string) ($o['email'] ?? ''), 'mesaj' => $mesajCod, 'csrf' => $app->jetonCsrf($c, $r0), 'card' => $cm['fel'] === 'card',
                    'actiune' => $app->url()->cale('/comanda/' . $ref . '/cod', $limba), 'actiune_nou' => $app->url()->cale('/comanda/' . $ref . '/cod-nou', $limba)];
                $cookies = $r0->cookies();
            } elseif ((in_array($cm['stare'], self::IN_LUCRU, true) || $cm['stare'] === 'noua') && !self::eliberareInDrum($app, $cm)) {
                // Fără JS: reîncărcarea se rărește (3 s, apoi 10 s, apoi 60 s) și se oprește după REINCARCARI;
                // rămân linkul de reîncărcare și promisiunea e-mailului.
                $n = max(0, min(1000, (int) ($c->query['n'] ?? 0)));
                $secunde = self::pauzaReincarcare($n);
                if ($secunde !== null) {
                    $refresh = ['secunde' => $secunde, 'url' => $app->url()->cale('/comanda/' . $ref, $limba, ['n' => (string) ($n + 1)])];
                }
                $d['in_lucru'] = true;
                $d['reincarca'] = $app->url()->cale('/comanda/' . $ref, $limba);
            }
        }
        $html = Prezentare::nucleu($app, $limba, 'stare', $d);
        $alternative = [];
        foreach ($app->config()->limbi() as $l) {
            $alternative[$l] = $app->url()->absolut('/comanda/' . $ref, $l);
        }
        return Prezentare::paginaRezervare($app, $c, $limba, $t->t('stare.titlu', ['ref' => $ref]), $html, $alternative, $refresh, $status, $cookies ?? []);
    }

    /**
     * eliberarea sumei rezervate e în drum (comanda `de_rambursat`, plata tot autorizată): pagina spune „Eliberăm
     * acum…” și promite e-mailul, fără reîncărcarea și textul cererilor în lucru.
     */
    private static function eliberareInDrum(Aplicatie $app, array $cm): bool
    {
        return $cm['stare'] === 'de_rambursat' && $app->plataOnline()->autorizata((int) $cm['id']) !== null;
    }

    public static function api(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $ref = (string) $p['ref'];
        $cm = $app->comenzi()->dupaRef($ref);
        if ($cm === null || !$app->linkOaspete()->cookieValid($c->cookie(LinkOaspete::COOKIE), $ref)) {
            return Raspuns::json(['eroare' => 'negasit'], 404);
        }
        return Raspuns::json(['ref' => $ref, 'stare' => $cm['stare'], 'in_lucru' => in_array($cm['stare'], self::IN_LUCRU, true)]);
    }

    public static function cod(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $t = $app->traducator($limba);
        $ref = (string) $p['ref'];
        $cm = $app->comenzi()->dupaRef($ref);
        if ($cm === null || !$app->linkOaspete()->cookieValid($c->cookie(LinkOaspete::COOKIE), $ref)) {
            return Erori::pagina($t, 404, $app->url()->cale('/', $limba));
        }
        if ($cm['stare'] !== 'noua') {
            return Raspuns::redirectare($app->url()->cale('/comanda/' . $ref, $limba));
        }
        $rez = $app->codEmail()->verifica((int) $cm['id'], (string) ($c->camp('cod') ?? ''));
        if ($rez !== 'ok') {
            return self::comanda($app, $c, $p, $limba, $t->t($rez === 'gresit' ? 'stare.cod_gresit' : 'stare.cod_expirat'), 422);
        }
        $app->baza()->executa("UPDATE {comenzi} SET cod_confirmat_la = ? WHERE id = ? AND stare = 'noua'", [$app->ceas()->moment(), $cm['id']]);
        $app->masinaStari()->jurnal((int) $cm['id'], 'noua', 'noua', 'oaspete', ['cod' => 'confirmat']);
        if ($cm['fel'] === 'card') {
            // Plata online după cod (comanda fără JavaScript): întâi verificările din POST /rezerva refăcute
            // (Rezerva::gardaDupaCod), apoi sesiunea Checkout.
            $garda = Rezerva::gardaDupaCod($app, $c, $limba, $cm);
            if ($garda !== null) {
                return $garda;
            }
            try {
                $sejur = \Cazo\I18n\Format::zi((string) $cm['sosire'], $limba) . ' - ' . \Cazo\I18n\Format::zi((string) $cm['plecare'], $limba);
                $produs = $t->t(($cm['plata_aleasa'] ?? null) === 'avans' ? 'comanda.produs_avans' : 'comanda.produs', ['sejur' => $sejur, 'ref' => $ref]);
                return Raspuns::redirectare($app->plataOnline()->porneste((int) $cm['id'], $produs) ?? $app->url()->cale('/comanda/' . $ref, $limba));
            } catch (\Cazo\Plati\EroareStripe) {
                return Raspuns::redirectare($app->url()->cale('/comanda/' . $ref, $limba));
            }
        }
        if ($cm['fel'] === 'transfer') {
            // Rezervarea cu transfer bancar după cod: poarta rezervărilor și oferta transferului se reverifică acum.
            $garda = Rezerva::gardaTransferDupaCod($app, $c, $limba, $cm);
            if ($garda !== null) {
                return $garda;
            }
            $app->transfer()->trimite((int) $cm['id'], 'oaspete', true);
            $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
            return Rezerva::dupaTrimitere($app, $c, $limba, (int) $cm['id'], Raspuns::redirectare($app->url()->cale('/comanda/' . $ref, $limba)));
        }
        // Cererea cu plata la sosire după cod: poarta rezervărilor (157) se reverifică acum, nu doar la POST /rezerva.
        $garda = Rezerva::gardaCashDupaCod($app, $c, $limba, $cm);
        if ($garda !== null) {
            return $garda;
        }
        $app->cerereCash()->trimite((int) $cm['id'], 'oaspete', true);
        $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
        return Rezerva::dupaTrimitere($app, $c, $limba, (int) $cm['id'], Raspuns::redirectare($app->url()->cale('/comanda/' . $ref, $limba)));
    }

    public static function codNou(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $t = $app->traducator($limba);
        $ref = (string) $p['ref'];
        $cm = $app->comenzi()->dupaRef($ref);
        if ($cm === null || !$app->linkOaspete()->cookieValid($c->cookie(LinkOaspete::COOKIE), $ref)) {
            return Erori::pagina($t, 404, $app->url()->cale('/', $limba));
        }
        if ($cm['stare'] !== 'noua') {
            return Raspuns::redirectare($app->url()->cale('/comanda/' . $ref, $limba));
        }
        $o = $app->comenzi()->oaspete($cm);
        $ok = Rezerva::trimiteCod($app, (int) $cm['id'], (string) ($o['email'] ?? ''));
        $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
        return self::comanda($app, $c, $p, $limba, $t->t($ok ? 'stare.cod_trimis' : 'stare.cod_limita'));
    }

    // ---- „Rezervarea mea” ----

    public static function rezervareaMea(Aplicatie $app, Cerere $c, array $p, string $limba, ?string $mesaj = null, int $status = 200): Raspuns
    {
        $t = $app->traducator($limba);
        $cm = $app->comenzi()->dupaToken((string) $p['token']);
        if ($cm === null) {
            return Erori::pagina($t, 404, $app->url()->cale('/', $limba));
        }
        $prop = $app->modelVedere($limba)->proprietate();
        // După confirmare, oaspetele are și telefonul gazdei; până atunci, contactul public.
        $contact = Rezerva::contactText($app, $cm);
        $intreg = (array) ($app->ecOglinda()->corp('proprietate')['contact'] ?? []);
        $d = ['ref' => (string) $cm['ref'], 'stare' => (string) $cm['stare'], 'mesaj' => $mesaj, 'incheiata' => $cm['anonimizat_la'] !== null,
            'contact' => Rezerva::confirmata($cm) ? ['email' => (string) ($intreg['email'] ?? ''), 'telefon' => (string) ($intreg['telefon'] ?? '')] : $prop['contact']];
        if ($d['incheiata']) {
            $d['text'] = $t->cuContact('rmea.incheiata', $contact);
        } else {
            $d += self::date($app, $t, $limba, $cm);
            if ($cm['stare'] === 'confirmata' || $cm['stare'] === 'anulare_ceruta') {
                $d['text'] = $cm['fel'] !== 'cash' ? self::textPlatita($t, $limba, $cm) : $t->t('rmea.confirmata', ['suma' => Format::baniOaspete((int) $cm['rest_la_sosire'], $limba)]);
                $d['ics'] = $app->url()->cale('/r/' . $p['token'] . '.ics', $limba);
                // După rezervare, adresa întreagă, cu etajul și apartamentul.
                $d['adresa'] = \Cazo\Legal\Adresa::text((array) ($app->ecOglinda()->corp('proprietate')['adresa'] ?? []), true);
            } elseif ($cm['stare'] === 'cerere_in_asteptare') {
                [$zi, $ora] = Sabloane::termen($cm['ec_expira_la'], $limba);
                $d['text'] = $t->t('rmea.cerere', ['data' => $zi, 'ora' => $ora]);
            }
            $d['schimbare'] = $t->cuContact('rmea.schimbare', $contact);
            $sd = self::schimbareDate($app, $t, $limba, $cm, (string) $p['token']);
            if ($sd !== null) {
                $d['schimbare_date'] = $sd;
            }
            $r0 = Raspuns::html('');
            if (\Cazo\Rezervare\Transfer::poateAnunta($cm)) {
                $d['am_platit'] = ['text' => $t->t('rmea.am_platit.text'), 'buton' => $t->t('rmea.am_platit.buton'),
                    'actiune' => $app->url()->cale('/r/' . $p['token'] . '/am-platit', $limba), 'csrf' => $app->jetonCsrf($c, $r0)];
            }
            $an = self::anulare($app, $t, $limba, $cm);
            if ($an !== null) {
                $d['anulare'] = $an + ['actiune' => $app->url()->cale('/r/' . $p['token'] . '/anuleaza', $limba), 'csrf' => $app->jetonCsrf($c, $r0)];
            }
            if ((int) $cm['marketing_acord'] === 1 && $cm['marketing_retras_la'] === null) {
                $d['noutati'] = ['text' => $t->t('rmea.noutati_da', ['nume' => $prop['nume']]), 'actiune' => $app->url()->cale('/r/' . $p['token'] . '/noutati', $limba),
                    'csrf' => $app->jetonCsrf($c, $r0)];
            }
            $cookies = $r0->cookies();
        }
        $html = Prezentare::nucleu($app, $limba, 'rezervarea-mea', $d);
        $alternative = [];
        foreach ($app->config()->limbi() as $l) {
            $alternative[$l] = $app->url()->absolut('/r/' . $p['token'], $l);
        }
        // Linkul din e-mail poartă tokenul în adresă: fără Referer spre alte site-uri.
        return Prezentare::paginaRezervare($app, $c, $limba, $t->t('rmea.titlu') . ' | ' . $prop['nume'], $html, $alternative, null, $status, $cookies ?? [],
            ['Referrer-Policy' => 'no-referrer']);
    }

    /**
     * Secțiunea schimbării datelor din „Rezervarea mea” (118-118d, Rezervare\SchimbareDate::conditii): butonul cât
     * schimbarea e posibilă (până la termenul gratuit inclusiv, o singură dată), schimbarea în lucru (cu plata
     * diferenței de continuat), cererea către gazdă (plata la sosire) sau schimbarea făcută; deasupra, motivul ultimei
     * încercări oprite în ultimele 3 zile.
     * @return array{paragrafe: list<string>, link?: string, buton?: string}|null
     */
    private static function schimbareDate(Aplicatie $app, Traducator $t, string $limba, array $cm, string $token): ?array
    {
        $sd = $app->schimbareDate();
        $k = $sd->conditii($cm);
        $p = [];
        $ultima = $app->baza()->rand('SELECT * FROM {schimbari_date} WHERE comanda_id = ? ORDER BY id DESC LIMIT 1', [$cm['id']]);
        // O anulare venită după oprirea schimbării are textul ei; motivul vechi („rezervarea rămâne pe…”) nu se mai arată.
        $activa = in_array($cm['stare'], ['confirmata', 'anulare_ceruta'], true);
        if ($ultima !== null && $ultima['stare'] === 'esuata' && (string) $ultima['incheiata_la'] >= $app->ceas()->moment(-3 * 86400)
            && ($activa || Schimbare::situatie($app, $ultima) === 'anulata')) {
            $p[] = Schimbare::textEsec($app, $t, $limba, $ultima);
        }
        switch ($k['motiv']) {
            case 'ok':
                $p[] = $k['limita'] !== null ? $t->t('rmea.schimbare_date.poate', ['data' => Format::ziLunga($k['limita'], $limba)]) : $t->t('rmea.schimbare_date.poate_sosire');
                return ['paragrafe' => $p, 'link' => $app->url()->cale('/r/' . $token . '/schimba', $limba), 'buton' => $t->t('rmea.schimbare_date.buton')];
            case 'in_lucru':
                $s = $k['schimbare'];
                if (!in_array($cm['stare'], ['confirmata', 'anulare_ceruta'], true)) {
                    // Comanda anulată: cererea rămasă se oprește odată cu anularea, deci textul ei nu se mai arată.
                    return $p === [] ? null : ['paragrafe' => $p];
                }
                if ($s['stare'] === 'plata_in_curs') {
                    $url = $sd->urlPlata($s);
                    $p[] = $t->t('rmea.schimbare_date.plata', ['perioada' => Schimbare::perioada((string) $s['sosire'], (string) $s['plecare'], $limba),
                        'suma' => Format::baniOaspete((int) $s['diferenta'], $limba)]);
                    return ['paragrafe' => $p] + ($url !== null ? ['link' => $url, 'buton' => $t->t('rmea.schimbare_date.continua_plata')] : []);
                }
                // rezervarea nouă e făcută, iar suma reținută pentru diferență se eliberează încă: pagina spune de ce arată
                // datele vechi.
                $eliberare = $s['stare'] === 'de_aplicat' && $s['plata_id'] !== null
                    && $app->baza()->valoare("SELECT 1 FROM {plati} WHERE id = ? AND stare = 'de_eliberat'", [$s['plata_id']]) !== null;
                $p[] = $t->t($s['stare'] === 'cerere' ? 'rmea.schimbare_date.cerere' : ($eliberare ? 'rmea.schimbare_date.in_lucru_eliberare' : 'rmea.schimbare_date.in_lucru'),
                    ['perioada' => Schimbare::perioada((string) $s['sosire'], (string) $s['plecare'], $limba)]);
                return ['paragrafe' => $p];
            case 'vanzare_oprita':
                // cât vânzarea e oprită, mutarea se face direct cu gazda.
                $p[] = $t->cuContact('stare.respinsa_cheie', Rezerva::contactText($app), []);
                return ['paragrafe' => $p];
            case 'facuta':
                $s = $app->baza()->rand("SELECT aplicata_la FROM {schimbari_date} WHERE comanda_id = ? AND stare = 'aplicata' ORDER BY id DESC LIMIT 1", [$cm['id']]);
                [$zi] = Sabloane::termen($s['aplicata_la'] ?? null, $limba);
                $p[] = $t->cuContact('rmea.schimbare_date.facuta', Rezerva::contactText($app, $cm), ['data' => $zi]);
                return ['paragrafe' => $p];
        }
        return $p === [] ? null : ['paragrafe' => $p];
    }

    /**
     * Secțiunea anulării din „Rezervarea mea”, după condițiile de acum (Rezervare\Anulare::conditii): textul
     * condițiilor, butonul și felul arătat (trimis înapoi, ca serverul să facă exact ce a văzut oaspetele).
     */
    private static function anulare(Aplicatie $app, Traducator $t, string $limba, array $cm): ?array
    {
        // cât plata prin transfer e așteptată, oaspetele poate renunța; banii trimiși între timp îi întoarce gazda.
        if ($cm['fel'] === 'transfer' && $cm['stare'] === \Cazo\Rezervare\Transfer::ASTEPTARE) {
            return $cm['anulare_actor'] !== null ? ['fel' => null, 'paragrafe' => [$t->t('rmea.anulare.in_lucru')]]
                : ['fel' => 'transfer_asteptat', 'paragrafe' => [$t->t('rmea.anulare.transfer_asteptat')], 'buton' => $t->t('rmea.anulare.buton')];
        }
        $k = $app->anulare()->conditii($cm);
        $bani = static fn (int $s): string => Format::baniOaspete($s, $limba);
        // „Pentru alte date, anulați...” doar când schimbarea datelor nu se mai oferă; după schimbarea făcută, secțiunea ei
        // spune deja ce face oaspetele pentru alte date (rmea.schimbare_date.facuta), deci rândul lipsește.
        // Cât o schimbare e în lucru (de exemplu cererea cu plata la sosire, care nu blochează anularea), secțiunea ei e deja
        // pe pagină, deci rândul lipsește și atunci.
        // Cât vânzarea e oprită, „faceți una nouă pe site” nu se poate: secțiunea schimbării dă deja contactul gazdei.
        $alteDate = in_array($app->schimbareDate()->conditii($cm)['motiv'], ['ok', 'facuta', 'in_lucru', 'vanzare_oprita'], true) ? [] : [$t->t('rmea.alte_date')];
        $limita = $k['limita'] !== null ? Format::ziLunga((string) $k['limita'], $limba) : '';
        // după o mutare pe alte date, regula avansului păstrat la o anulare după termen, după alegerea gazdei.
        $notaMutare = [];
        if (in_array($k['fel'], ['gratuita', 'cerere'], true) && $cm['fel'] === 'card' && ($cm['avans_initial'] ?? null) !== null && (int) ($cm['nerambursabil'] ?? 0) === 0
            && $cm['anulare_limita'] !== null && (int) ($cm['avans_procent'] ?? 100) < 100) {
            $mutare = $app->anulare()->avansMutare($cm);
            $notaMutare[] = $t->t('rmea.anulare.mutare_' . $mutare, ['avans' => $bani(min($app->anulare()->net($cm), \Cazo\Rezervare\Anulare::retinereDupaTermen($cm, $mutare)))]);
        }
        switch ($k['fel']) {
            case 'gratuita':
                // Plata returnată integral: anularea e gratuită, fără sumă de arătat; returnată în parte: „ce a rămas”.
                $incasat = (int) ($app->anulare()->plata((int) $cm['id'])['suma_incasata'] ?? 0);
                $cheie = $k['suma'] <= 0 ? 'rmea.anulare.gratuita_returnata'
                    : ($k['suma'] < $incasat ? ($k['limita'] !== null ? 'rmea.anulare.gratuita_rest' : 'rmea.anulare.gratuita_rest_sosire') : ($k['limita'] !== null ? 'rmea.anulare.gratuita' : 'rmea.anulare.gratuita_sosire'));
                return ['fel' => 'gratuita', 'paragrafe' => array_merge([$t->t($cheie, ['data' => $limita, 'suma' => $bani($k['suma'])])], $notaMutare, $alteDate),
                    'buton' => $t->t('rmea.anulare.buton')];
            case 'nerambursabil':
                // butonul rămâne; anularea eliberează nopțile, suma plătită pentru cazare rămâne la gazdă. taxa
                // locală plătită online se returnează.
                $p = $k['suma'] > 0 ? $t->t('rmea.anulare.nerambursabil_directa_taxa', ['retine' => $bani($k['retine']), 'suma' => $bani($k['suma'])])
                    : $t->t('rmea.anulare.nerambursabil_directa');
                return ['fel' => 'nerambursabil', 'paragrafe' => array_merge([$p], $alteDate), 'buton' => $t->t('rmea.anulare.buton')];
            case 'cash':
                return ['fel' => 'cash', 'paragrafe' => array_merge([$t->t('rmea.anulare.cash')], $alteDate), 'buton' => $t->t('rmea.anulare.buton')];
            case 'transfer':
                // rezervarea plătită prin transfer se anulează pe loc; banii îi întoarce gazda, după politica de anulare.
                // la tariful nerambursabil, butonul rămâne; nopțile se eliberează, banii rămân la gazdă.
                // taxa locală din suma transferului o returnează gazda și la tariful nerambursabil.
                $taxa = \Cazo\Rezervare\Anulare::taxaOnline($cm, (int) $cm['suma_acum']);
                $cheie = (int) ($cm['nerambursabil'] ?? 0) === 1 ? ($taxa > 0 ? 'rmea.anulare.nerambursabil_transfer_taxa' : 'rmea.anulare.nerambursabil_directa') : 'rmea.anulare.transfer';
                return ['fel' => 'transfer', 'paragrafe' => [$t->t($cheie, ['suma' => $bani((int) $cm['suma_acum']), 'retine' => $bani((int) $cm['suma_acum'] - $taxa), 'taxa' => $bani($taxa)])],
                    'buton' => $t->t('rmea.anulare.buton')];
            case 'schimbare':
                // O schimbare de date e în lucru (118d): anularea se oferă imediat după încheierea ei.
                return ['fel' => null, 'paragrafe' => [$t->t('rmea.anulare.schimbare')]];
            case 'cerere':
                if ($k['motiv'] === 'necunoscuta') {
                    // Comanda dintr-o versiune mai veche, fără regula din ziua rezervării (migrarea 0013): doar cererea către gazdă.
                    return ['fel' => 'cerere', 'paragrafe' => [$t->t('rmea.anulare.cerere')], 'buton' => $t->t('rmea.anulare.buton_cerere')];
                }
                $conditie = $k['motiv'] === 'nerambursabil' ? $t->t($k['suma'] > 0 ? 'rmea.anulare.nerambursabil_taxa' : 'rmea.anulare.nerambursabil', ['retine' => $bani($k['retine']), 'suma' => $bani($k['suma'])])
                    : ($k['suma'] > 0 ? $t->t('rmea.anulare.dupa_termen', ['data' => $limita, 'retine' => $bani($k['retine']), 'suma' => $bani($k['suma'])])
                        : $t->t('rmea.anulare.dupa_termen_tot', ['data' => $limita, 'retine' => $bani($k['retine'])]));
                return ['fel' => 'cerere', 'paragrafe' => array_merge([$conditie], $notaMutare, [$t->t('rmea.anulare.cerere')]), 'buton' => $t->t('rmea.anulare.buton_cerere')];
            case 'in_lucru':
                return ['fel' => null, 'paragrafe' => [$t->t('rmea.anulare.in_lucru')]];
            case 'in_lucru_gazda':
                // După refuzul definitiv al EasyCheck: anularea o încheie gazda, fără promisiunea „câteva minute”.
                return ['fel' => null, 'paragrafe' => [$t->cuContact('rmea.anulare.in_lucru_gazda', Rezerva::contactText($app, $cm))]];
            case 'ceruta':
                [$zi] = Sabloane::termen($cm['anulare_ceruta_la'], $limba);
                return ['fel' => null, 'paragrafe' => [$t->t('rmea.anulare.ceruta', ['data' => $zi])]];
        }
        return null;
    }

    /** POST /r/{token}/anuleaza: anularea sau cererea de anulare, cu bifa de confirmare. */
    public static function anuleaza(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $t = $app->traducator($limba);
        $cm = $app->comenzi()->dupaToken((string) $p['token']);
        if ($cm === null || $cm['anonimizat_la'] !== null) {
            return Erori::pagina($t, 404, $app->url()->cale('/', $limba));
        }
        $vazut = (string) ($c->camp('fel') ?? '');
        if ($c->camp('confirm') !== '1') {
            return self::rezervareaMea($app, $c, $p, $limba, $t->t('rmea.anulare.bifati'), 422);
        }
        // rezervarea care așteaptă transferul se anulează în EasyCheck, fără rambursare automată.
        $r = $cm['fel'] === 'transfer' && $cm['stare'] === \Cazo\Rezervare\Transfer::ASTEPTARE
            ? ($vazut === 'transfer_asteptat' ? $app->transfer()->anuleazaOaspete((int) $cm['id']) : 'schimbat')
            : $app->anulare()->deOaspete((int) $cm['id'], $vazut);
        if ($r === 'stare' && $cm['fel'] === 'transfer') {
            // Hotărâtă între timp (plata confirmată, refuzată sau termenul trecut): pagina arată starea de acum.
            $r = 'schimbat';
        }
        $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
        return match ($r) {
            'anulata', 'in_lucru' => Raspuns::redirectare($app->url()->cale('/r/' . $p['token'], $limba)),
            'ceruta' => self::rezervareaMea($app, $c, $p, $limba, $t->t('rmea.anulare.ceruta_mesaj')),
            'schimbat' => self::rezervareaMea($app, $c, $p, $limba, $t->t('rmea.anulare.schimbat'), 409),
            'ocupat' => self::rezervareaMea($app, $c, $p, $limba, $t->t('rmea.anulare.ocupat'), 409),
            'in_curs' => self::rezervareaMea($app, $c, $p, $limba, $t->t('rmea.anulare.rambursare_in_curs'), 409),
            'schimbare' => self::rezervareaMea($app, $c, $p, $limba, $t->t('rmea.anulare.schimbare'), 409),
            default => self::rezervareaMea($app, $c, $p, $limba, $t->cuContact('rmea.anulare.direct', Rezerva::contactText($app)), 409),
        };
    }

    /**
     * POST /r/{token}/am-platit: oaspetele spune că a făcut transferul. Gazda e anunțată (prin EasyCheck sau,
     * când EasyCheck nu ia anunțul, de site), anularea la termen nu mai are loc, iar pagina arată mulțumirea, fără termen.
     * A doua apăsare (sau o pagină veche) arată același lucru, fără un anunț nou.
     */
    public static function amPlatit(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $t = $app->traducator($limba);
        $cm = $app->comenzi()->dupaToken((string) $p['token']);
        if ($cm === null || $cm['anonimizat_la'] !== null) {
            return Erori::pagina($t, 404, $app->url()->cale('/', $limba));
        }
        $r = $app->transfer()->anuntaPlata((int) $cm['id']);
        $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
        return match ($r) {
            // Pagina arată mulțumirea în locul termenului (titlul stării); reîncărcarea nu retrimite formularul.
            'anuntat', 'deja' => Raspuns::redirectare($app->url()->cale('/r/' . $p['token'], $limba)),
            'ocupat' => self::rezervareaMea($app, $c, $p, $limba, $t->t('rmea.anulare.ocupat'), 409),
            default => self::rezervareaMea($app, $c, $p, $limba, $t->t('rmea.am_platit.schimbat'), 409),
        };
    }

    public static function ics(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $cm = $app->comenzi()->dupaToken((string) $p['token']);
        if ($cm === null || $cm['anonimizat_la'] !== null || !in_array($cm['stare'], ['confirmata', 'anulare_ceruta'], true)) {
            return Raspuns::text('', 404);
        }
        $r = new Raspuns(200, $app->sabloaneEmail()->ics($cm, (string) $cm['limba']));
        $r->antet('Content-Type', 'text/calendar; charset=utf-8');
        $r->antet('Content-Disposition', 'attachment; filename="' . strtolower((string) $cm['ref']) . '.ics"');
        return $r;
    }

    public static function noutati(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $t = $app->traducator($limba);
        $cm = $app->comenzi()->dupaToken((string) $p['token']);
        if ($cm === null || $cm['anonimizat_la'] !== null) {
            return Erori::pagina($t, 404, $app->url()->cale('/', $limba));
        }
        // retragerea ține de adresă (toate acordurile ei, lista EasyCheck după răspuns), ca dezabonarea din oferte.
        $email = (string) ($app->comenzi()->oaspete($cm)['email'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $app->abonati()->dezaboneaza($email, 'oaspete', false);
            $app->dupaRaspuns(static fn () => $app->abonati()->sarcina());
        }
        return self::rezervareaMea($app, $c, $p, $limba, $t->t('rmea.noutati_retras'));
    }

    /**
     * „Cum ajungeți”: adresa întreagă (cu etajul și apartamentul) și harta, din datele proprietății, plus indicațiile scrise de
     * gazdă în blocul de conținut `cum_ajungeti` (câmpul `text`, paragrafe despărțite de un rând gol), dacă există.
     */
    private static function cumAjungeti(Aplicatie $app, string $limba): array
    {
        $m = $app->modelVedere($limba);
        $h = (new \Cazo\Teme\Componente($app, $m, $app->traducator($limba), $limba, null, '0.0.0.0/24'))->harta(true);
        $activ = $app->active()->nucleu();
        $text = $m->continut()['cum_ajungeti']['text'] ?? '';
        $indicatii = is_string($text) ? array_values(array_filter(array_map('trim', preg_split('~\R\s*\R~u', $text) ?: []), 'strlen')) : [];
        return ['harta' => $h + ['leaflet_js' => $activ['leaflet/leaflet.js'] ?? '', 'leaflet_css' => $activ['leaflet/leaflet.css'] ?? '', 'nivel' => 3],
            'indicatii' => $indicatii];
    }

    /** Confirmarea unei rezervări plătite online sau prin transfer bancar: codul, suma plătită și restul de la sosire. */
    public static function textPlatita(Traducator $t, string $limba, array $cm): string
    {
        $rest = (int) $cm['rest_la_sosire'];
        if (($cm['fel'] ?? null) === 'transfer') {
            return $rest > 0
                ? $t->t('stare.confirmata_transfer_avans', ['ref' => (string) $cm['ref'], 'platit' => Format::baniOaspete((int) $cm['suma_acum'], $limba), 'rest' => Format::baniOaspete($rest, $limba)])
                : $t->t('stare.confirmata_transfer_integral', ['ref' => (string) $cm['ref'], 'platit' => Format::baniOaspete((int) $cm['suma_acum'], $limba)]);
        }
        return $rest > 0
            ? $t->t('stare.confirmata_avans', ['ref' => (string) $cm['ref'], 'platit' => Format::baniOaspete((int) $cm['suma_acum'], $limba), 'rest' => Format::baniOaspete($rest, $limba)])
            : $t->t('stare.confirmata_integral', ['ref' => (string) $cm['ref'], 'platit' => Format::baniOaspete((int) $cm['suma_acum'], $limba)]);
    }

    /**
     * Rezervarea anulată: de oaspete sau de gazdă, cu suma returnată (`anulare_suma`) sau, la tariful care
     * reține tot, cu suma rămasă la gazdă; la plata la sosire, fără sume.
     */
    public static function textAnulata(Traducator $t, string $limba, array $cm, int $incasat = 0): string
    {
        $gazda = $cm['stare'] === 'anulata_gazda';
        $suma = (int) ($cm['anulare_suma'] ?? 0);
        if ($cm['fel'] === 'transfer') {
            // banii primiți prin transfer (sau trimiși cât plata era așteptată) îi întoarce gazda, din bancă.
            // plătită la tariful nerambursabil, suma rămâne la gazdă.
            // taxa locală din suma transferului se returnează și la tariful nerambursabil.
            $taxa = \Cazo\Rezervare\Anulare::taxaOnline($cm, (int) $cm['suma_acum']);
            $nerambursabil = $cm['platita_la'] !== null && (int) ($cm['nerambursabil'] ?? 0) === 1 ? ($taxa > 0 ? '_nerambursabil_taxa' : '_nerambursabil') : '';
            return $t->t(($gazda ? 'stare.anulata_gazda' : 'stare.anulata_oaspete') . ($cm['platita_la'] !== null ? '_transfer' : '_transfer_asteptat') . ($gazda ? '' : $nerambursabil),
                ['suma' => Format::baniOaspete((int) $cm['suma_acum'] - ($nerambursabil === '_nerambursabil_taxa' ? $taxa : 0), $limba), 'taxa' => Format::baniOaspete($taxa, $limba)]);
        }
        if ($cm['fel'] !== 'card') {
            return $t->t($gazda ? 'stare.anulata_gazda' : 'stare.anulata_oaspete');
        }
        if ($suma > 0) {
            return $t->t($gazda ? 'stare.anulata_gazda_ramb' : 'stare.anulata_oaspete_ramb', ['suma' => Format::baniOaspete($suma, $limba)]);
        }
        // gazda a ales să păstreze netul de la decizie: suma plătită sau, după rambursări anterioare (netul sub
        // suma încasată), restul plății; fără sumă reținută, doar anularea.
        $retinut = (int) ($cm['anulare_net'] ?? 0);
        if ($gazda && $retinut > 0) {
            return $t->t($incasat > $retinut ? 'stare.anulata_gazda_retinut_rest' : 'stare.anulata_gazda_retinut', ['suma' => Format::baniOaspete($retinut, $limba)]);
        }
        return $t->t($gazda ? 'stare.anulata_gazda' : 'stare.anulata_oaspete_retinut');
    }

    /** Textul stării și rezumatul, comune paginii de stare și „Rezervării mele”. */
    private static function date(Aplicatie $app, Traducator $t, string $limba, array $cm): array
    {
        $contact = Rezerva::contactText($app, $cm);
        $ref = (string) $cm['ref'];
        $camere = $app->comenzi()->camere($cm);
        $cautare = $app->url()->cale(Cai::cale('disponibil', $limba), $limba, ['sosire' => (string) $cm['sosire'], 'plecare' => (string) $cm['plecare'],
            'adulti' => (string) ($camere[0]['adulti'] ?? 2), 'copii' => (string) ($camere[0]['copii'] ?? 0)]);
        $d = ['rezumat' => Rezumat::dinComanda($app, $t, $cm), 'rezervarea_mea' => $app->url()->cale('/r/' . $app->comenzi()->token($cm), $limba)];
        switch ($cm['stare']) {
            case 'plata_in_curs':
                $d['titlu'] = $t->t('stare.plata_in_curs');
                $p = $app->checkout()->ultima((int) $cm['id']);
                if ($p !== null && is_string($p['url']) && str_starts_with($p['url'], 'https://')) {
                    $d['plata_url'] = $p['url'];
                }
                break;
            case 'plata_in_procesare':
                $d['titlu'] = $t->t('stare.plata_in_procesare');
                break;
            case 'platita':
            case 'de_capturat':
                $d['titlu'] = $t->t('stare.confirmam');
                // După 24 de ore fără o confirmare sigură (Recuperare): verificăm la gazdă. suma e doar rezervată pe card.
                $ret = $app->plataOnline()->cuRetinere((int) $cm['id']);
                $d['paragrafe'] = [$t->t($cm['intarziata_la'] !== null ? ($ret ? 'stare.verificam_retinere' : 'stare.verificam_gazda') : ($ret ? 'stare.retinere' : 'stare.confirmam_minute'))];
                break;
            case 'trimitere_ec':
                if ($cm['fel'] === 'card') {
                    $d['titlu'] = $t->t('stare.confirmam');
                    $d['paragrafe'] = [$t->t($app->plataOnline()->cuRetinere((int) $cm['id']) ? 'stare.retinere' : 'stare.confirmam_minute')];
                    break;
                }
                // no break
            case 'noua':
                $d['titlu'] = $t->t('stare.trimitem');
                $d['paragrafe'] = [$t->t($cm['intarziata_la'] !== null ? 'stare.intarziata' : 'stare.trimitem_minute')];
                break;
            case 'cerere_in_asteptare':
                [$zi, $ora] = Sabloane::termen($cm['ec_expira_la'], $limba);
                $d['titlu'] = $t->t('stare.cerere_trimisa', ['data' => $zi, 'ora' => $ora]);
                $d['paragrafe'] = [$t->t('stare.cerere_noptile')];
                break;
            case \Cazo\Rezervare\Transfer::ASTEPTARE:
                if ($cm['transfer_anuntat_la'] !== null) {
                    // după „Am plătit”, mulțumirea; termenul de plată nu mai apare, iar datele contului rămân, fără el.
                    $d['titlu'] = $t->t('stare.transfer_anuntat');
                    $d['paragrafe'] = array_values(array_filter([\Cazo\Rezervare\Transfer::textRest($t, $cm)]));
                    $d['transfer'] = ['randuri' => array_values(array_filter($app->transfer()->instructiuni($cm, $t), static fn (array $x): bool => $x['cheie'] !== 'termen')),
                        'paragrafe' => []];
                    break;
                }
                // așteptăm transferul; contul, suma exactă, termenul în ora României și textul plății.
                [$zi, $ora] = Sabloane::termen($cm['transfer_termen'], $limba);
                $d['titlu'] = $t->t('stare.transfer_asteptat', ['suma' => Format::baniOaspete((int) $cm['suma_acum'], $limba), 'data' => $zi, 'ora' => $ora]);
                $d['paragrafe'] = array_values(array_filter([\Cazo\Rezervare\Transfer::textRest($t, $cm), $t->t('stare.transfer_noptile')]));
                $d['transfer'] = ['randuri' => $app->transfer()->instructiuni($cm, $t), 'paragrafe' => [$t->t('transfer.detalii_ajutor', ['ref' => $ref])]];
                break;
            case 'confirmata':
                $d['titlu'] = $t->t('stare.confirmata_titlu');
                $d['paragrafe'] = [$cm['fel'] !== 'cash' ? self::textPlatita($t, $limba, $cm) : $t->t('stare.confirmata', ['ref' => $ref]), $t->t('stare.checkin')];
                $d['ics'] = $app->url()->cale('/r/' . $app->comenzi()->token($cm) . '.ics', $limba);
                $d['cum_ajungeti'] = self::cumAjungeti($app, $limba);
                break;
            case 'anulare_ceruta':
                $d['titlu'] = $t->t('stare.anulare_ceruta');
                $d['paragrafe'] = [$cm['fel'] === 'card' ? self::textPlatita($t, $limba, $cm) : $t->t('stare.confirmata', ['ref' => $ref])];
                $d['ics'] = $app->url()->cale('/r/' . $app->comenzi()->token($cm) . '.ics', $limba);
                break;
            case 'anulare_de_lamurit':
                // gazda a anulat în EasyCheck și alege suma în panou; pagina arată anularea, fără o sumă promisă.
                $d['titlu'] = $t->t('stare.anulare_de_lamurit');
                $d['paragrafe'] = [$t->cuContact('stare.anulata_gazda_contact', $contact)];
                $d['cauta'] = $cautare;
                break;
            case 'anulata_oaspete':
            case 'anulata_gazda':
                $d['titlu'] = self::textAnulata($t, $limba, $cm, (int) ($app->anulare()->plata((int) $cm['id'])['suma_incasata'] ?? 0));
                if ($cm['stare'] === 'anulata_gazda') {
                    $d['paragrafe'] = [$t->cuContact('stare.anulata_gazda_contact', $contact)];
                    $d['cauta'] = $cautare;
                }
                break;
            default:
                // Comenzile încheiate fără rezervare: textul după motivul real (Motiv), același ca în e-mail.
                $m = Motiv::refuz($app, $t, $cm, $contact);
                $d['titlu'] = $m['text'];
                if ($m['cauta']) {
                    $d['cauta'] = $cautare;
                }
                if ($m['continua'] !== null) {
                    $d['continua'] = $m['continua'];
                    if ($m['motiv'] === 'plata_esuata') {
                        $d['continua_text'] = $t->t('stare.reia_plata');
                    }
                }
        }
        return $d;
    }
}
