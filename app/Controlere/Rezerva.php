<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Legal\PaginiLegale;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cai;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Erori;
use Cazo\Nucleu\Raspuns;
use Cazo\Plati\EroareStripe;
use Cazo\Pret\RegulaPlata;
use Cazo\Pret\Sejur;
use Cazo\Rezervare\LinkOaspete;
use Cazo\Rezervare\Rezumat;
use Cazo\Securitate\Altcha;
use Cazo\Securitate\Csrf;
use Cazo\Securitate\Ip;
use Cazo\Teme\Componente;

defined('CAZO') or exit;

/**
 * Comanda și cererea cash, în layoutul temei:
 *
 *   GET  /rezerva?...             rezumatul (totalul recalculat pe server), datele oaspetelui, informarea
 *                                 precontractuală, acordul, căsuța nebifată pentru noutăți, ALTCHA, butonul
 *   POST /rezerva                 CSRF (routerul), câmpul-capcană, ALTCHA, limitele, validarea, totalul live,
 *                                 disponibilitatea live, comanda; apoi codul de 6 cifre sau POST /cereri; 303
 *   GET  /altcha                  provocarea ALTCHA
 *
 * Fără JavaScript, ALTCHA nu se poate rezolva în browser: comanda cere atunci codul de 6 cifre pe e-mail,
 * ca dovadă în locul ei. Clientul nu trimite nicio sumă: totalul văzut vine semnat, doar pentru comparație.
 *
 * Plata online: metodele le alege gazda (card, cash, ambele; ModPlata). Cu cardul, butonul
 * „Rezerv și plătesc” creează comanda pe ramura `card` și sesiunea Checkout (303 spre pagina de plată); cu
 * „Ambele”, fiecare metodă are butonul ei (`metoda` = card sau cash), deci formularul merge și fără JavaScript.
 * Oaspetele alege între avans și plata întreagă când planul are avans sub 100% (RegulaPlata). Cu doar cardul
 * și plata online oprită, cererea cash rămâne ca rezervă. Planul cu avans 0% se rezervă drept cerere cash,
 * oricare ar fi metodele; până la configurarea completă, nicio rezervare (Aplicatie::rezervariActive).
 *
 * Transferul bancar: când e oferit (ModPlata::pentru, cu ziua sosirii), are butonul lui (`metoda` =
 * transfer), cu suma (avansul planului sau, fără avans, tot), termenul și restul de la sosire spuse înainte de
 * trimitere. Comanda pe ramura `transfer` pleacă spre EasyCheck ca rezervare cu `plata_asteptata` (Rezervare\Transfer),
 * cu aceleași limite ca cererea cash; pagina comenzii arată apoi contul, suma, termenul și textul plății.
 */
final class Rezerva
{
    public const NOTA_MAX = 1000;

    public static function comanda(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        // După renunțarea la plată sau o plată refuzată (`reia`), datele oaspetelui revin în formular, în același browser.
        $v = [];
        $reia = $c->query['reia'] ?? null;
        $cm = is_string($reia) ? $app->comenzi()->dupaRef($reia) : null;
        if ($cm !== null && in_array($cm['stare'], ['abandonata', 'plata_esuata'], true) && $app->linkOaspete()->cookieValid($c->cookie(LinkOaspete::COOKIE), (string) $cm['ref'])) {
            $o = $app->comenzi()->oaspete($cm);
            $v = ['nume' => (string) ($o['nume'] ?? ''), 'email' => (string) ($o['email'] ?? ''), 'telefon' => (string) ($o['telefon'] ?? ''), 'tara' => (string) ($o['tara'] ?? ''),
                'nota' => $app->comenzi()->nota($cm), 'plata' => (string) ($cm['plata_aleasa'] ?? '')];
        }
        return self::afiseaza($app, $c, $limba, Sejur::dinQuery($c->query), $v, []);
    }

    public static function altcha(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return Raspuns::json($app->altcha()->provocare(Ip::trunchiaza($c->ip)));
    }

    public static function trimite(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $t = $app->traducator($limba);
        $s = Sejur::dinQuery($c->post);
        $v = self::valori($c);
        $ip24 = Ip::trunchiaza($c->ip);
        if (!$app->rezervariActive() || $s === null) {
            // Cât întrerupătorul e pornit, valul se numără mai departe (codul rămâne pornit cât durează valul).
            if ($s !== null && $app->intrerupator()->pornit()) {
                $app->intrerupator()->numaraRezerva();
            }
            return self::afiseaza($app, $c, $limba, $s, $v, []);
        }
        // Câmpul-capcană: un vizitator îl vede ascuns și gol; un robot îl completează.
        if (($c->camp('site_web') ?? '') !== '') {
            return self::afiseaza($app, $c, $limba, $s, $v, [], $t->t('comanda.eroare.altcha'));
        }
        $erori = self::valideaza($v, $t);
        if ($erori !== []) {
            return self::afiseaza($app, $c, $limba, $s, $v, $erori, null, 422);
        }
        $altcha = $c->camp(Altcha::CAMP);
        $faraJs = $altcha === null || $altcha === '';
        if (!$faraJs && !$app->altcha()->verifica($altcha)) {
            return self::afiseaza($app, $c, $limba, $s, $v, [], $t->t('comanda.eroare.altcha'), 422);
        }
        $contact = self::contactText($app);
        // Limitele: POST /rezerva pe IP/24, pe e-mail și pe site; cererile în așteptare pe e-mail și pe IP.
        $l = (array) $app->setari()->get('limite', []);
        $lim = $app->limitator();
        $emailIo = $app->comenzi()->emailIo($v['email']);
        if (!$lim->incearca('rezerva:ip:' . $ip24, (int) ($l['rezerva_ip_ora'] ?? 5), 3600)
            || !$lim->incearca('rezerva:email:' . $emailIo, (int) ($l['rezerva_email_ora'] ?? 3), 3600)
            || !$lim->incearca('rezerva:site', (int) ($l['rezerva_site_zi'] ?? 50), 86400)) {
            return self::afiseaza($app, $c, $limba, $s, $v, [], $t->cuContact('comanda.limita', $contact), 429);
        }
        if ($app->intrerupator()->numaraRezerva()) {
            return self::afiseaza($app, $c, $limba, $s, $v, []);
        }
        $s = self::cuPlan($app, $s);
        $mod = $app->modPlata()->pentru(self::planRand($app, $s), $s->sosire);
        // Fără butonul unei metode (o singură metodă oferită): cardul, altfel plata la sosire, altfel transferul.
        $metoda = in_array($c->camp('metoda'), ['cash', 'card', 'transfer'], true) ? (string) $c->camp('metoda') : ($mod['card'] ? 'card' : ($mod['cash'] ? 'cash' : 'transfer'));
        if (!$mod[$metoda]) {
            // Metoda s-a oprit între afișare și trimitere: formularul revine cu metodele de acum.
            return self::afiseaza($app, $c, $limba, $s, $v, [], $t->t('comanda.plata_schimbata'));
        }
        if ($metoda === 'card') {
            // codul pe e-mail la card doar fără JavaScript (în locul verificării ALTCHA) sau dacă gazda a bifat cardul.
            return self::trimiteCard($app, $c, $limba, $s, $v, $ip24, $faraJs || $app->intrerupator()->codCerut('card'));
        }
        $inAsteptare = $app->comenzi()->inAsteptarePeEmail($v['email']);
        if ($inAsteptare >= (int) ($l['cereri_email'] ?? 2)) {
            // Numărul din text e cel real (limita e configurabilă), cu formele de plural ale limbii.
            return self::afiseaza($app, $c, $limba, $s, $v, [], $t->pluralCuContact('comanda.limita_email', $inAsteptare, $contact), 429);
        }
        if ($app->comenzi()->peIpAzi($ip24) >= (int) ($l['cereri_ip_zi'] ?? 5)) {
            return self::afiseaza($app, $c, $limba, $s, $v, [], $t->cuContact('comanda.limita', $contact), 429);
        }
        [$total, $raspuns] = self::totalLive($app, $c, $limba, $s, $v);
        if ($raspuns !== null) {
            return $raspuns;
        }
        // Disponibilitatea live, doar pe intervalul cerut; la o eroare, cererea merge (EasyCheck verifică nopțile, 409).
        $ocupate = self::noptiOcupate($app, $s);
        if ($ocupate !== []) {
            return self::afiseaza($app, $c, $limba, $s, $v, [], $t->t('comanda.ocupat', ['zile' => self::zile($ocupate, $limba)]), 409, $total, true);
        }
        // Cererea cu plata la sosire sau rezervarea cu transfer bancar: aceleași limite și aceiași pași, altă trimitere.
        $comanda = self::creeazaComanda($app, $c, $limba, $s, $v, $total, $ip24, $metoda === 'transfer' ? 'transfer' : 'cash');
        $r = Raspuns::redirectare($app->url()->cale('/comanda/' . $comanda['ref'], $limba));
        $r->cookie($app->linkOaspete()->setCookie($comanda['ref']));
        $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
        if ($faraJs || $app->intrerupator()->codCerut($metoda === 'transfer' ? 'transfer' : 'cash')) {
            self::trimiteCod($app, $comanda['id'], $v['email']);
            return $r;
        }
        if ($metoda === 'transfer') {
            $app->transfer()->trimite($comanda['id'], 'oaspete', true);
        } else {
            $app->cerereCash()->trimite($comanda['id'], 'oaspete', true);
        }
        return self::dupaTrimitere($app, $c, $limba, $comanda['id'], $r);
    }

    /**
     * Totalul live. La „Prețul de pe site-ul meu”, garda dinaintea plății: site-ul recitește din
     * EasyCheck prețurile (citire condiționată, de obicei 304); dacă EasyCheck tace, vânzarea merge cu prețul din copie,
     * iar gazda primește alerta (cel mult una pe zi). Un total diferit de cel văzut reafișează comanda cu
     * noul total și butonul „Continuați cu noul total”.
     * @return array{0:?array, 1:?Raspuns}
     */
    private static function totalLive(Aplicatie $app, Cerere $c, string $limba, Sejur $s, array $v, ?int $vazut = null): array
    {
        $t = $app->traducator($limba);
        if ($app->total()->sursa() === 'site') {
            try {
                $app->ecOglinda()->citeste('preturi');
            } catch (\Throwable $e) {
                $app->alertaGazda('alerta_preturi', ['eroare' => mb_substr($e->getMessage(), 0, 120)], 'preturi_vechi', 86400);
            }
        }
        $total = $app->total()->live($s);
        if ($total['stare'] !== 'ok') {
            return [null, self::afiseaza($app, $c, $limba, $s, $v, [], null, 200, $total)];
        }
        $vazut ??= $app->linkOaspete()->totalVazut($c->camp('pret'), $s->canonic());
        if ($vazut !== $total['total']) {
            return [null, self::afiseaza($app, $c, $limba, $s, $v, [], $t->t('comanda.total_schimbat', ['suma' => Format::baniOaspete($total['total'], $limba)]), 200, $total, false, true)];
        }
        return [$total, null];
    }

    /**
     * Plata online: totalul și disponibilitatea live, comanda pe ramura `card` cu suma de acum
     * (avansul sau tot), sesiunea Checkout și 303 spre pagina de plată. Sesiunile deschise: una pe e-mail (cea
     * veche expiră când oaspetele trimite din nou) și două pe IP/24.
     */
    private static function trimiteCard(Aplicatie $app, Cerere $c, string $limba, Sejur $s, array $v, string $ip24, bool $cuCod = false): Raspuns
    {
        $t = $app->traducator($limba);
        $l = (array) $app->setari()->get('limite', []);
        $emailIo = $app->comenzi()->emailIo($v['email']);
        foreach ($app->baza()->toate("SELECT id FROM {comenzi} WHERE fel = 'card' AND stare = 'plata_in_curs' AND email_io = ?", [$emailIo]) as $vechi) {
            $app->plataOnline()->renunta((int) $vechi['id']);
        }
        $deschise = (int) $app->baza()->valoare("SELECT COUNT(*) FROM {comenzi} WHERE fel = 'card' AND stare = 'plata_in_curs' AND ip24 = ?", [$ip24]);
        if ($deschise >= (int) ($l['sesiuni_ip'] ?? 2)) {
            return self::afiseaza($app, $c, $limba, $s, $v, [], $t->cuContact('comanda.limita', self::contactText($app)), 429);
        }
        [$total, $raspuns] = self::totalLive($app, $c, $limba, $s, $v);
        if ($raspuns !== null) {
            return $raspuns;
        }
        $ocupate = self::noptiOcupate($app, $s);
        if ($ocupate !== []) {
            return self::afiseaza($app, $c, $limba, $s, $v, [], $t->t('comanda.ocupat', ['zile' => self::zile($ocupate, $limba)]), 409, $total, true);
        }
        $comanda = self::creeazaComanda($app, $c, $limba, $s, $v, $total, $ip24, 'card', $c->camp('plata'));
        if ($cuCod) {
            // Fără JavaScript (fără ALTCHA): codul de 6 cifre pe e-mail, apoi plata.
            $r = Raspuns::redirectare($app->url()->cale('/comanda/' . $comanda['ref'], $limba));
            $r->cookie($app->linkOaspete()->setCookie($comanda['ref']));
            self::trimiteCod($app, $comanda['id'], $v['email']);
            $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
            return $r;
        }
        try {
            $url = $app->plataOnline()->porneste($comanda['id'], self::produs($app, $limba, $s, $comanda));
        } catch (EroareStripe) {
            return self::afiseaza($app, $c, $limba, $s, $v, [], $t->t('comanda.eroare.plata'), 503);
        }
        $r = Raspuns::redirectare($url ?? $app->url()->cale('/comanda/' . $comanda['ref'], $limba));
        $r->cookie($app->linkOaspete()->setCookie($comanda['ref']));
        return $r;
    }

    /**
     * Garda plății după codul de 6 cifre: comanda cu cardul a așteptat codul până la o oră, deci înainte de
     * sesiunea Checkout se refac verificările din POST /rezerva: rezervările active și întrerupătorul, plata
     * online pe planul comenzii, sesiunile deschise pe e-mail și pe IP/24, totalul live cu
     * garda dinaintea plății (recitirea prețurilor) și disponibilitatea. Când una oprește plata, comanda devine `abandonata`, iar formularul revine cu
     * datele oaspetelui și cu metodele de acum (de exemplu cererea cash ca rezervă), ca la POST /rezerva.
     * Întoarce null când plata poate porni.
     */
    public static function gardaDupaCod(Aplicatie $app, Cerere $c, string $limba, array $cm): ?Raspuns
    {
        $t = $app->traducator($limba);
        $o = $app->comenzi()->oaspete($cm);
        $v = ['nume' => (string) ($o['nume'] ?? ''), 'email' => (string) ($o['email'] ?? ''), 'telefon' => (string) ($o['telefon'] ?? ''), 'tara' => (string) ($o['tara'] ?? ''),
            'nota' => $app->comenzi()->nota($cm), 'plata' => (string) ($cm['plata_aleasa'] ?? '')];
        try {
            $s = self::cuPlan($app, Sejur::din((string) $cm['sosire'], (string) $cm['plecare'], $app->comenzi()->camere($cm), $cm['plan'] !== null ? (string) $cm['plan'] : null), true);
        } catch (\InvalidArgumentException) {
            $s = null;
        }
        $opreste = static fn (string $motiv, ?Raspuns $r): Raspuns => self::opresteDupaCod($app, $c, $limba, $cm, $s, $v, $motiv, $r);
        if ($s === null || !$app->rezervariActive()) {
            return $opreste('rezervari_oprite', null);
        }
        if (!$app->modPlata()->pentru(self::planRand($app, $s), $s->sosire)['card']) {
            return $opreste('plata_oprita', self::afiseaza($app, $c, $limba, $s, $v, [], $t->t('comanda.plata_schimbata')));
        }
        $l = (array) $app->setari()->get('limite', []);
        foreach ($app->baza()->toate("SELECT id FROM {comenzi} WHERE fel = 'card' AND stare = 'plata_in_curs' AND email_io = ? AND id <> ?", [(string) $cm['email_io'], (int) $cm['id']]) as $vechi) {
            $app->plataOnline()->renunta((int) $vechi['id']);
        }
        $deschise = (int) $app->baza()->valoare("SELECT COUNT(*) FROM {comenzi} WHERE fel = 'card' AND stare = 'plata_in_curs' AND ip24 = ?", [Ip::trunchiaza($c->ip)]);
        if ($deschise >= (int) ($l['sesiuni_ip'] ?? 2)) {
            return $opreste('limita', self::afiseaza($app, $c, $limba, $s, $v, [], $t->cuContact('comanda.limita', self::contactText($app)), 429));
        }
        [$total, $raspuns] = self::totalLive($app, $c, $limba, $s, $v, (int) $cm['total']);
        if ($raspuns !== null) {
            return $opreste('total', $raspuns);
        }
        $ocupate = self::noptiOcupate($app, $s);
        if ($ocupate !== []) {
            return $opreste('indisponibil', self::afiseaza($app, $c, $limba, $s, $v, [], $t->t('comanda.ocupat', ['zile' => self::zile($ocupate, $limba)]), 409, $total, true));
        }
        return null;
    }

    /**
     * Poarta rezervărilor refăcută după codul de 6 cifre, la cererea cu plata la sosire: comanda a așteptat codul până
     * la o oră, timp în care gazda poate goli o dată legală sau orele pot redeveni
     * neconfirmate. Cu poarta închisă, comanda devine `abandonata`, nimic nu pleacă spre EasyCheck, iar
     * oaspetele vede pagina de rezervare în starea de pregătire, ca la plata cu cardul (gardaDupaCod). Întoarce null
     * când cererea poate pleca.
     */
    public static function gardaCashDupaCod(Aplicatie $app, Cerere $c, string $limba, array $cm): ?Raspuns
    {
        if ($app->rezervariActive()) {
            return null;
        }
        $o = $app->comenzi()->oaspete($cm);
        $v = ['nume' => (string) ($o['nume'] ?? ''), 'email' => (string) ($o['email'] ?? ''), 'telefon' => (string) ($o['telefon'] ?? ''), 'tara' => (string) ($o['tara'] ?? ''),
            'nota' => $app->comenzi()->nota($cm), 'plata' => (string) ($cm['plata_aleasa'] ?? '')];
        try {
            $s = self::cuPlan($app, Sejur::din((string) $cm['sosire'], (string) $cm['plecare'], $app->comenzi()->camere($cm), $cm['plan'] !== null ? (string) $cm['plan'] : null), true);
        } catch (\InvalidArgumentException) {
            $s = null;
        }
        return self::opresteDupaCod($app, $c, $limba, $cm, $s, $v, 'rezervari_oprite', null);
    }

    /**
     * Poarta transferului bancar refăcută după codul de 6 cifre: pe lângă poarta rezervărilor, transferul trebuie
     * să fie încă oferit pentru planul și sosirea comenzii, iar termenul de plată scris în comandă să mai lase timp.
     * Altfel comanda devine `abandonata`, iar formularul revine cu metodele de acum. Întoarce null când rezervarea poate pleca.
     */
    public static function gardaTransferDupaCod(Aplicatie $app, Cerere $c, string $limba, array $cm): ?Raspuns
    {
        $garda = self::gardaCashDupaCod($app, $c, $limba, $cm);
        if ($garda !== null) {
            return $garda;
        }
        $o = $app->comenzi()->oaspete($cm);
        $v = ['nume' => (string) ($o['nume'] ?? ''), 'email' => (string) ($o['email'] ?? ''), 'telefon' => (string) ($o['telefon'] ?? ''), 'tara' => (string) ($o['tara'] ?? ''),
            'nota' => $app->comenzi()->nota($cm), 'plata' => ''];
        try {
            $s = self::cuPlan($app, Sejur::din((string) $cm['sosire'], (string) $cm['plecare'], $app->comenzi()->camere($cm), $cm['plan'] !== null ? (string) $cm['plan'] : null), true);
        } catch (\InvalidArgumentException) {
            return self::opresteDupaCod($app, $c, $limba, $cm, null, $v, 'rezervari_oprite', null);
        }
        $termen = $cm['transfer_termen'] !== null ? \Cazo\Nucleu\Ceas::dinMoment((string) $cm['transfer_termen']) : 0;
        if (!$app->modPlata()->pentru(self::planRand($app, $s), $s->sosire)['transfer'] || $termen - $app->ceas()->acum() < \Cazo\Rezervare\Transfer::MARJA_TERMEN) {
            return self::opresteDupaCod($app, $c, $limba, $cm, $s, $v, 'plata_oprita', self::afiseaza($app, $c, $limba, $s, $v, [], $app->traducator($limba)->t('comanda.plata_schimbata')));
        }
        return null;
    }

    /** Comanda oprită după cod devine `abandonata`; oaspetele primește formularul (sau $r), cu linkul comenzii păstrat. */
    private static function opresteDupaCod(Aplicatie $app, Cerere $c, string $limba, array $cm, ?Sejur $s, array $v, string $motiv, ?Raspuns $r): Raspuns
    {
        $app->masinaStari()->trece((int) $cm['id'], (string) $cm['fel'], 'noua', 'abandonata', 'oaspete', ['eroare_cod' => $motiv], ['dupa_cod' => $motiv]);
        $r ??= self::afiseaza($app, $c, $limba, $s, $v, []);
        $r->cookie($app->linkOaspete()->setCookie((string) $cm['ref']));
        return $r;
    }

    /** Numele plății din Checkout: „Sejur 20-23 decembrie 2026, PG-7K3M9Q” sau „Avans pentru sejurul ...”. */
    private static function produs(Aplicatie $app, string $limba, Sejur $s, array $comanda): string
    {
        $t = $app->traducator($limba);
        $cm = $app->comenzi()->dupaId($comanda['id']);
        $sejur = Format::zi($s->sosire, $limba) . ' - ' . Format::zi($s->plecare, $limba);
        return $t->t(($cm['plata_aleasa'] ?? null) === 'avans' ? 'comanda.produs_avans' : 'comanda.produs', ['sejur' => $sejur, 'ref' => $comanda['ref']]);
    }

    /** Rândul planului rezervării, sau null. */
    private static function planRand(Aplicatie $app, Sejur $s): ?array
    {
        $plan = $s->planCamera(0);
        return $plan !== null ? ($app->pretNoapte()->planuri()[$plan] ?? null) : null;
    }

    /**
     * Răspunsul după POST /cereri, pe loc (din POST /rezerva sau după cod): 409 indisponibil duce înapoi la
     * selecție, cu nopțile marcate în calendar; un 422 care privește datele comenzii reafișează
     * formularul, cu mesajul după cod pe câmpul vizat; restul merge la pagina de stare ($r).
     */
    public static function dupaTrimitere(Aplicatie $app, Cerere $c, string $limba, int $id, Raspuns $r): Raspuns
    {
        $cm = $app->comenzi()->dupaId($id);
        if ($cm === null) {
            return $r;
        }
        if ($cm['stare'] === 'indisponibila') {
            $s = self::inapoiLaSelectie($app, $limba, $cm);
            foreach ($r->cookies() as $k) {
                $s->cookie($k);
            }
            return $s;
        }
        if ($cm['stare'] === 'respinsa_ec' && in_array($cm['eroare_cod'], self::CODURI_FORMULAR, true)) {
            // Formularul refăcut păstrează legătura cu comanda respinsă (pagina ei de stare rămâne deschisă oaspetelui).
            $f = self::afiseazaDinComanda($app, $c, $limba, $cm);
            foreach ($r->cookies() as $k) {
                $f->cookie($k);
            }
            return $f;
        }
        return $r;
    }

    /** Codurile 422 care privesc datele comenzii, arătate pe câmpul lor. */
    public const CODURI_FORMULAR = ['restrictie', 'pret_diferit', 'validare', 'plan_lipsa', 'plan_diferit'];

    /**
     * 409 indisponibil: pagina primei camere din comandă, cu datele și oaspeții ei și cu nopțile din `detalii.zile`
     * marcate ocupate în calendar (parametrul `ocupate`). La mai multe camere, selecția pornește tot de la prima.
     */
    private static function inapoiLaSelectie(Aplicatie $app, string $limba, array $cm): Raspuns
    {
        $camere = $app->comenzi()->camere($cm);
        $prima = $camere[0] ?? null;
        $detalii = json_decode((string) ($cm['eroare_detalii'] ?? ''), true) ?: [];
        $zile = \Cazo\Teme\Componente::ocupateDin(implode(',', array_filter((array) ($detalii['zile'] ?? []), 'is_string')));
        $cam = $prima !== null ? $app->modelVedere($limba)->cameraDupaId($prima['camera']) : null;
        if ($cam === null) {
            return Raspuns::redirectare($app->url()->cale('/comanda/' . $cm['ref'], $limba));
        }
        $q = ['sosire' => (string) $cm['sosire'], 'plecare' => (string) $cm['plecare'], 'adulti' => (string) $prima['adulti'], 'copii' => (string) $prima['copii']];
        if ($cm['plan'] !== null) {
            $q['plan'] = (string) $cm['plan'];
        }
        if ($zile !== []) {
            $q['ocupate'] = implode(',', $zile);
        }
        return Raspuns::redirectare($app->url()->cale(Cai::cale('camera', $limba, ['slug' => $cam['slug']]), $limba, $q));
    }

    /**
     * Formularul comenzii, refăcut din comanda respinsă de EasyCheck cu 422: aceleași camere, date și valori, cu
     * mesajul după cod pe câmpul vizat: camera din listă (`detalii.index` sau `camere[i]...` în `detalii.campuri`),
     * datele sejurului, tariful, un câmp al oaspetelui; totalul actualizat la `pret_diferit`. Trimiterea din nou
     * creează o comandă nouă (cu altă cheie de idempotență).
     */
    public static function afiseazaDinComanda(Aplicatie $app, Cerere $c, string $limba, array $cm): Raspuns
    {
        $t = $app->traducator($limba);
        $o = $app->comenzi()->oaspete($cm);
        try {
            $s = Sejur::din((string) $cm['sosire'], (string) $cm['plecare'], $app->comenzi()->camere($cm), $cm['plan'] !== null ? (string) $cm['plan'] : null);
        } catch (\InvalidArgumentException) {
            return Raspuns::redirectare($app->url()->cale('/comanda/' . $cm['ref'], $limba));
        }
        $v = ['nume' => (string) ($o['nume'] ?? ''), 'email' => (string) ($o['email'] ?? ''), 'telefon' => (string) ($o['telefon'] ?? ''), 'tara' => (string) ($o['tara'] ?? ''),
            'nota' => $app->comenzi()->nota($cm), 'acord' => '', 'marketing' => (int) $cm['marketing_acord'] === 1 ? '1' : ''];
        $d = json_decode((string) ($cm['eroare_detalii'] ?? ''), true) ?: [];
        $erori = [];
        $mesaj = null;
        $total = null;
        switch ($cm['eroare_cod']) {
            case 'restrictie':
                $comp = new Componente($app, $app->modelVedere($limba), $t, $limba, null, Ip::trunchiaza($c->ip));
                $erori[is_int($d['index'] ?? null) ? 'camera_' . $d['index'] : 'sejur'] = $comp->mesajRestrictie($d);
                break;
            case 'pret_diferit':
                $total = $app->total()->live(self::cuPlan($app, $s));
                $mesaj = $t->t('comanda.total_schimbat', ['suma' => Format::baniOaspete(is_int($d['total'] ?? null) ? $d['total'] : (int) ($total['total'] ?? 0), $limba)]);
                break;
            case 'plan_lipsa':
            case 'plan_diferit':
                $erori['plan'] = $t->t('comanda.eroare.plan');
                break;
            default:
                foreach ((array) ($d['campuri'] ?? []) as $camp) {
                    $camp = (string) $camp;
                    if (preg_match('~^camere\[(\d+)\]~', $camp, $x)) {
                        $erori['camera_' . $x[1]] = $t->t('comanda.eroare.camera');
                    } elseif (preg_match('~^(?:oaspete\.)?(nume|email|telefon|tara)$~', $camp, $x)) {
                        $erori[$x[1]] = $t->t('comanda.eroare.' . $x[1]);
                    } elseif ($camp === 'nota') {
                        $erori['nota'] = $t->t('comanda.eroare.nota');
                    } elseif (in_array($camp, ['sosire', 'plecare'], true)) {
                        $erori['sejur'] = $t->t('comanda.eroare.sejur');
                    }
                }
                if ($erori === []) {
                    $mesaj = $t->cuContact('stare.respinsa', self::contactText($app));
                }
        }
        // Acordul se bifează din nou: oaspetele trimite o comandă nouă.
        return self::afiseaza($app, $c, $limba, $s, $v, $erori, $mesaj, 422, $total);
    }

    /** Pune în coadă un cod nou de 6 cifre; false peste limita de trimiteri. */
    public static function trimiteCod(Aplicatie $app, int $id, string $email): bool
    {
        $c = $app->comenzi()->dupaId($id);
        $cod = $app->codEmail()->nou($id, (string) $c['email_io']);
        if ($cod === null) {
            return false;
        }
        $app->emailuri()->pune($id, 'cod', (string) $c['limba'], $email, ['comanda_id' => $id, 'cod' => $app->emailuri()->secret($cod)]);
        return true;
    }

    // ---- afișarea comenzii ----

    /**
     * @param array<string,string> $v valorile câmpurilor
     * @param array<string,string> $erori câmp => mesaj
     */
    private static function afiseaza(Aplicatie $app, Cerere $c, string $limba, ?Sejur $s, array $v, array $erori, ?string $mesaj = null, int $status = 200,
        ?array $total = null, bool $ocupat = false, bool $totalNou = false): Raspuns
    {
        $t = $app->traducator($limba);
        $m = $app->modelVedere($limba);
        $prop = $m->proprietate();
        $d = ['activa' => $app->rezervariActive(), 'oprite' => $app->rezervariOprite(), 'contact' => $prop['contact'], 'mesaj' => $mesaj, 'erori' => [], 'sejur' => false, 'total_ok' => false,
            'cautare' => null, 'restrictie' => null];
        foreach ($erori as $camp => $text) {
            $d['erori'][] = ['camp' => $camp, 'text' => $text];
        }
        $d['erori_camp'] = $erori;
        // Erorile din rezumat (camera din listă, datele sejurului, tariful), după un 422 al EasyCheck.
        $d['erori_rezumat'] = array_filter($erori, static fn (string $k): bool => $k === 'sejur' || $k === 'plan' || str_starts_with($k, 'camera_'), ARRAY_FILTER_USE_KEY);
        if ($s !== null) {
            foreach ($s->camere as $x) {
                if ($m->cameraDupaId($x['camera']) === null) {
                    $s = null;
                    break;
                }
            }
        }
        if ($s !== null && $d['activa']) {
            $s = self::cuPlan($app, $s);
            $d['sejur'] = true;
            $d['cautare'] = $app->url()->cale(Cai::cale('disponibil', $limba), $limba, ['sosire' => $s->sosire, 'plecare' => $s->plecare,
                'adulti' => (string) $s->camere[0]['adulti'], 'copii' => (string) $s->camere[0]['copii']]);
            $comp = new Componente($app, $m, $t, $limba, null, Ip::trunchiaza($c->ip));
            $pline = $app->pretNoapte()->noptiPline(array_column($s->camere, 'camera'), $s->sosire, $s->plecare);
            // Peste durata maximă de pe site: mesajul cu contactul, fără nopțile ocupate și fără preț.
            $lung = $comp->mesajPreaLung($s->sosire, $s->plecare);
            if ($lung !== null) {
                $total = ['stare' => 'restrictie', 'restrictie' => ['motiv' => 'sejur_maxim_site', 'valoare' => $app->noptiMaxSite()]];
                $pline = [];
            }
            if ($total === null && $pline !== []) {
                $d['restrictie'] = $t->t('rezumat.restrictie.ocupat', ['zile' => self::zile($pline, $limba)]);
                $ocupat = true;
            }
            if ($lung === null && $pline === [] && $app->oferte()->ascuns($s->planCamera(0), $s->sosire)) {
                // singurul tarif al datelor e cel nerambursabil, iar nicio metodă plătită la rezervare nu e oferită acum.
                $total = ['stare' => 'ascuns'];
                $d['restrictie'] = $t->t('rezumat.contact_gazda');
                $ocupat = true;
            }
            $total ??= $pline === [] ? $app->total()->unul($s, Ip::trunchiaza($c->ip)) : ['stare' => 'ocupat'];
            if ($total['stare'] === 'ok' && !$ocupat) {
                $d['total_ok'] = true;
                $d['plata'] = self::plata($app, $t, $limba, $s, $total, $v);
                // Politica de anulare după metodele oferite acum, aceeași ca pe pagina de disponibilitate.
                $d['rezumat'] = Rezumat::construieste($app, $t, $limba, $s->sosire, $s->plecare, $s->camere, $s->planCamera(0), $total['total'], $total['componente'], $total['total'],
                    self::modTaxa($app, $s, $total), 0, ['card' => $d['plata']['card'], 'transfer' => $d['plata']['transfer'], 'cash' => $d['plata']['cash']]);
                // Ce rămâne în afara totalului (taxa locală, taxele suplimentare) și se plătește separat, la sosire.
                $d['rezumat']['separat'] = $comp->separat($s);
                // Pe fiecare metodă de plată oferită, ce se plătește acum și ce la sosire, în locul rândului „La sosire: …”.
                $d['rezumat']['metode'] = $d['plata']['metode'];
                $d['planuri'] = self::planuri($app, $comp, $s, $limba);
                $d['schimba'] = $comp->formular('/rezerva');
                $d['sejur_campuri'] = self::campuriSejur($s, false);
                $d['formular'] = $comp->formular('/rezerva');
                $d['ascunse'] = array_merge(self::campuriSejur($s, true), [
                    ['nume' => 'pret', 'valoare' => $app->linkOaspete()->semneazaTotal($s->canonic(), $total['total'])],
                ]);
                $d['linkuri'] = ['termeni' => $app->url()->cale(Cai::cale('termeni', $limba), $limba), 'confidentialitate' => $app->url()->cale(Cai::cale('confidentialitate', $limba), $limba)];
                $d['noutati_text'] = $t->t('comanda.noutati', ['nume' => $prop['nume']]);
                $d['cod_info'] = $app->intrerupator()->codActiv();
                $d['altcha_api'] = $app->url()->cale('/altcha', $limba);
                $d['tari'] = self::tari($limba);
                $d['buton'] = $t->t('comanda.buton_cash');
                $d['rezumat']['plata'] = $d['plata']['precontractual'];
                $d['precontractual'] = self::precontractual($app, $t, $limba, $d['rezumat']);
                if ($d['plata']['card']) {
                    $d['buton_card'] = $t->t($totalNou ? 'stare.continua_total' : 'comanda.buton_card');
                }
                if ($d['plata']['transfer']) {
                    $d['buton_transfer'] = $t->t('comanda.buton_transfer');
                }
                if ($totalNou && !$d['plata']['card']) {
                    $d['buton'] = $t->t('stare.continua_total');
                }
            } elseif ($total['stare'] === 'restrictie') {
                $d['restrictie'] = $comp->mesajRestrictie($total['restrictie']);
            } elseif (!$ocupat) {
                $d['restrictie'] = $t->t('rezumat.indisponibil');
            }
        }
        $d['valori'] = $v + ['nume' => '', 'email' => '', 'telefon' => '', 'tara' => $limba === 'ro' ? 'RO' : '', 'nota' => '', 'acord' => '', 'marketing' => ''];
        $d['csrf'] = '';
        $r = Raspuns::html('', $status);
        $d['csrf'] = $app->jetonCsrf($c, $r);
        $html = Prezentare::nucleu($app, $limba, 'comanda', $d);
        $alternative = [];
        foreach ($app->config()->limbi() as $l) {
            $alternative[$l] = $app->url()->absolut('/rezerva', $l, $s !== null ? $s->query() : []);
        }
        return Prezentare::paginaRezervare($app, $c, $limba, $t->t('comanda.titlu') . ' | ' . $prop['nume'], $html, $alternative, null, $status, $r->cookies());
    }

    /**
     * Plata în comandă: metodele active (ModPlata), alegerea între avans și plata întreagă, textele lângă
     * butoane: „Plătiți acum 1.450,00 lei.” sau „Plătiți acum avansul de 435,00 lei. Restul de 1.015,00 lei îl
     * plătiți la sosire.”; la cash, textul plății la sosire; la transferul bancar, suma (avansul planului sau,
     * fără avans, tot), termenul și restul de la sosire.
     */
    private static function plata(Aplicatie $app, Traducator $t, string $limba, Sejur $s, array $tot, array $v): array
    {
        $total = (int) $tot['total'];
        $plan = self::planRand($app, $s);
        $mod = $app->modPlata()->pentru($plan, $s->sosire);
        $termen = (int) $app->setari()->get('cereri.termen_ore', 24);
        $textCash = $t->t('comanda.plata_sosire', ['termen' => $t->plural('unitate.ore', $termen >= 1 && $termen <= 72 ? $termen : 24)]);
        $p = ['card' => $mod['card'], 'cash' => $mod['cash'], 'transfer' => $mod['transfer'], 'rezerva' => $mod['rezerva'], 'optiuni' => [], 'texte' => [], 'text_cash' => $textCash,
            'text_card' => null, 'text_transfer' => null, 'metode' => []];
        // pe fiecare metodă oferită, ce se plătește acum și ce la sosire, în rezumatul comenzii.
        $bani = static fn (int $x): string => Format::baniOaspete($x, $limba);
        $rand = static fn (string $metoda, string $acum, string $sosire): array => ['metoda' => $metoda, 'titlu' => $t->t('comanda.metode.' . $metoda), 'acum' => $acum,
            'sosire' => $t->t('comanda.metode.sosire', ['suma' => $sosire])];
        if ($mod['card']) {
            $r = self::regula($plan, $tot);
            $ales = in_array($v['plata'] ?? null, $r['optiuni'], true) ? $v['plata'] : $r['implicit'];
            // la „la_sosire”, plata întreagă de acum lasă taxa locală pentru sosire, iar textele o spun.
            $faraTaxa = $r['incasare'] === RegulaPlata::TAXA_LA_SOSIRE;
            $taxaText = Format::baniOaspete($r['taxa'], $limba);
            foreach ($r['optiuni'] as $o) {
                $p['optiuni'][] = ['id' => $o, 'ales' => $o === $ales, 'text' => $o === 'avans'
                    ? $t->t('comanda.plata_optiune_avans', ['suma' => Format::baniOaspete($r['avans'], $limba), 'rest' => Format::baniOaspete($r['rest_avans'], $limba)])
                    : ($faraTaxa ? $t->t('comanda.plata_optiune_integral_taxa', ['suma' => Format::baniOaspete($r['integral'], $limba), 'taxa' => $taxaText])
                        : $t->t('comanda.plata_optiune_integral', ['suma' => Format::baniOaspete($total, $limba)]))];
            }
            if (count($p['optiuni']) < 2) {
                $p['optiuni'] = [];
            }
            $p['text_card'] = $ales === 'avans'
                ? $t->t('comanda.plata_card_avans', ['avans' => Format::baniOaspete($r['avans'], $limba), 'rest' => Format::baniOaspete($r['rest_avans'], $limba)])
                : ($faraTaxa ? $t->t('comanda.plata_card_integral_taxa', ['suma' => Format::baniOaspete($r['integral'], $limba), 'taxa' => $taxaText])
                    : $t->t('comanda.plata_card_integral', ['suma' => Format::baniOaspete($total, $limba)]));
            // Ce se întâmplă cu suma plătită după termenul de anulare gratuită (OUG 34/2014 art. 6 alin. (1) lit. e), g)),
            // în ambele limbi: lângă plată, în informarea precontractuală, în contract și în e-mailul de confirmare.
            $retinere = self::retinere($t, $limba, $plan, $r, $total);
            // rezervarea plătită cu cardul se poate muta pe alte date: ce avans se păstrează atunci, după alegerea gazdei.
            if ($retinere !== null && ($plan['tip'] ?? null) !== 'nerambursabil' && $r['anulare_zile'] !== null && $r['avans_procent'] < 100) {
                $retinere .= ' ' . $t->t('comanda.retinere.mutare_' . \Cazo\Rezervare\Anulare::mutareSite($app));
            }
            // ce primește oaspetele când gazda anulează, după alegerea gazdei (înghețată pe comandă).
            $retinere = self::cuMinim($retinere, self::clauzaGazda($app, $t, $limba, $r));
            // cu cardul, oaspetele alege între avans și plata întreagă, deci înainte de plată avansul minim se spune
            // fără „acum plătiți” (`comanda.avans_minim_optiuni`); contractul spune varianta aleasă (`regula_aleasa`).
            if (in_array('avans', $r['optiuni'], true)) {
                $p['regula_aleasa'] = self::cuMinim(self::avansMinim($t, $limba, $r, $ales === 'avans' ? 'avans' : 'integral'), $retinere);
                $retinere = self::cuMinim(self::avansMinim($t, $limba, $r, 'optiuni'), $retinere);
            }
            if ($retinere !== null) {
                $p['regula'] = $retinere;
                $p['retinere'] = $retinere;
            }
            $p['texte'][] = $p['text_card'];
            $p['metode'][] = count($r['optiuni']) > 1
                ? $rand('card', $t->t('comanda.metode.acum', ['suma' => $t->t('comanda.metode.avans_sau_tot', ['avans' => $bani($r['avans']), 'total' => $bani($r['integral'])])]),
                    $t->t('comanda.metode.rest_dupa_alegere', ['avans' => $bani($r['rest_avans']), 'total' => $bani($total - $r['integral'])]))
                : $rand('card', $t->t('comanda.metode.acum', ['suma' => $bani($r['integral'])]), $bani($total - $r['integral']));
            // În informarea precontractuală, plata cu cardul arată toate opțiunile oferite (avansul și plata întreagă):
            // textul afișat rămâne același oricare ar fi alegerea, iar copia durabilă îl repetă exact.
            $precCard = count($r['optiuni']) > 1
                ? ($faraTaxa ? $t->t('comanda.precontractual.plata_optiuni_taxa', ['avans' => Format::baniOaspete($r['avans'], $limba), 'rest' => Format::baniOaspete($r['rest_avans'], $limba),
                    'total' => Format::baniOaspete($r['integral'], $limba), 'taxa' => $taxaText])
                    : $t->t('comanda.precontractual.plata_optiuni', ['avans' => Format::baniOaspete($r['avans'], $limba), 'rest' => Format::baniOaspete($r['rest_avans'], $limba), 'total' => Format::baniOaspete($total, $limba)]))
                : ($faraTaxa ? $t->t('comanda.plata_card_integral_taxa', ['suma' => Format::baniOaspete($r['integral'], $limba), 'taxa' => $taxaText])
                    : $t->t('comanda.plata_card_integral', ['suma' => Format::baniOaspete($total, $limba)]));
            if ($retinere !== null) {
                // Reținerea privește doar banii plătiți acum, deci stă lângă plata cu cardul.
                $precCard .= ' ' . $retinere;
            }
        }
        if ($mod['transfer']) {
            // prin transfer se plătește avansul planului (ca la card); fără avans, tot. Taxa locală urmează aceeași
            // regulă ca la card: „online” o cuprinde în suma transferului, „la sosire” o lasă pentru sosire.
            $r = self::regula($plan, $tot);
            $a = RegulaPlata::sumaAcum($r, $total, 'avans');
            $faraTaxa = $r['incasare'] === RegulaPlata::TAXA_LA_SOSIRE;
            $param = ['suma' => Format::baniOaspete($a['suma_acum'], $limba), 'rest' => Format::baniOaspete($a['rest_la_sosire'], $limba), 'taxa' => Format::baniOaspete($r['taxa'], $limba),
                'termen' => self::termenTransfer($app, $t, $limba, $s)];
            $p['text_transfer'] = $t->t($a['alegere'] === 'avans' ? 'comanda.plata_transfer_avans' : ($faraTaxa ? 'comanda.plata_transfer_integral_taxa' : 'comanda.plata_transfer_integral'), $param);
            // cu avansul minim, propoziția plății spune și cum se calculează avansul.
            if ($a['alegere'] === 'avans') {
                $p['text_transfer'] = self::avansMinim($t, $limba, $r, 'transfer', ['termen' => $param['termen']]) ?? $p['text_transfer'];
            }
            $p['transfer_cont'] = $t->t('comanda.plata_transfer_cont');
            $p['texte'][] = $p['text_transfer'];
            $cand = $param['termen'];
            $p['metode'][] = $rand('transfer', $t->t('comanda.metode.termen', ['termen' => mb_strtoupper(mb_substr($cand, 0, 1)) . mb_substr($cand, 1), 'suma' => $param['suma']]), $param['rest']);
            $precTransfer = $p['text_transfer'];
            $retinere = self::cuMinim(self::retinere($t, $limba, $plan, $r, $total), self::clauzaGazda($app, $t, $limba, $r));
            if ($retinere !== null) {
                $p['retinere'] = $retinere;
                $p['regula_transfer'] = $retinere;
                $precTransfer .= ' ' . $retinere;
            }
        }
        if ($mod['cash']) {
            $p['texte'][] = $textCash;
            $p['metode'][] = $rand('cash', $t->t('comanda.metode.acum', ['suma' => $bani(0)]), $bani($total));
        }
        // Cu mai multe metode, fiecare text spune metoda lui (OUG 34/2014 art. 6 alin. (1) lit. g).
        $parti = array_filter(['cu_cardul' => $precCard ?? null, 'prin_transfer' => $precTransfer ?? null, 'la_sosire' => $mod['cash'] ? $textCash : null], 'is_string');
        $p['precontractual'] = count($parti) > 1
            // fiecare metodă pe rândul ei (un paragraf), nu toate într-un singur paragraf.
            ? implode("\n", array_map(static fn (string $k, string $text): string => $t->t('comanda.precontractual.' . $k, ['text' => $text]), array_keys($parti), $parti))
            : (string) (array_values($parti)[0] ?? $textCash);
        return $p;
    }

    /**
     * Până când se plătește transferul, spus la fel pe formular și în copia durabilă: „în cel mult 48 de ore de la
     * rezervare”; când ziua sosirii vine mai devreme decât termenul gazdei, „până la sfârșitul zilei de {data}”.
     */
    private static function termenTransfer(Aplicatie $app, Traducator $t, string $limba, Sejur $s): string
    {
        $ore = $app->contTransfer()->citeste()['termen_ore'];
        $termen = $app->contTransfer()->termenPentru($s->sosire);
        if ($termen !== null && $termen < $app->ceas()->acum() + $ore * 3600) {
            return $t->t('comanda.plata_transfer_termen_zi', ['data' => Format::ziLunga($s->sosire, $limba)]);
        }
        return $t->t('comanda.plata_transfer_termen_ore', ['termen' => $t->plural('unitate.ore', $ore)]);
    }

    /**
     * Ce se întâmplă cu banii plătiți după termenul de anulare gratuită, după regula EasyCheck a planului: planul
     * nerambursabil reține tot ce s-a plătit; cu termen, după el se reține avansul (procentul și suma din GET /total sau
     * din GET /preturi, RegulaPlata), iar ce s-a plătit peste avans se restituie; la avans 100%, se reține tot. Cu taxa
     * locală plătită online, se reține avansul plătit, iar restul, cu taxa, se returnează. Planul
     * fără politică de anulare rămâne pe regula de până acum (`anulare.la_cerere`, fără plată online): null.
     * Textul `avans.regula` al EasyCheck vine doar în română, deci propoziția se construiește din câmpurile lui
     * structurate (`procent`, `suma`, `anulare_zile`), la fel în ambele limbi.
     */
    public static function retinere(Traducator $t, string $limba, ?array $plan, array $r, int $total): ?string
    {
        // cu taxa locală plătită online, după termen se păstrează avansul plătit (cu avansul
        // minim, cât taxa), ca avans al cazării, iar restul, cu taxa, se returnează; la avansul 100% (sau unul peste prețul
        // cazării), se păstrează prețul cazării. La tariful nerambursabil, taxa plătită online se returnează și ea.
        $cuTaxa = (int) ($r['taxa_online'] ?? 0) > 0;
        if ($plan !== null && ($plan['tip'] ?? null) === 'nerambursabil') {
            return $t->t($cuTaxa ? 'comanda.retinere.nerambursabil_taxa' : 'comanda.retinere.nerambursabil');
        }
        if ($r['anulare_zile'] === null) {
            return null;
        }
        if ($cuTaxa) {
            return $r['avans_procent'] >= 100 || (int) $r['retinere'] < (int) $r['avans'] ? $t->t('comanda.retinere.tot_taxa')
                : $t->t('comanda.retinere.avans_taxa_returnata', ['avans' => Format::baniOaspete((int) $r['avans'], $limba)]);
        }
        if ($r['avans'] >= ($r['integral'] ?? $total) || $r['avans_procent'] >= 100) {
            return $t->t('comanda.retinere.tot');
        }
        // la „la_sosire”, procentul avansului se aplică totalului fără taxa locală.
        $cheie = ($r['incasare'] ?? null) === RegulaPlata::TAXA_LA_SOSIRE ? 'comanda.retinere.avans_fara_taxa' : 'comanda.retinere.avans';
        return $t->t($cheie, ['avans' => Format::baniOaspete($r['avans'], $limba), 'procent' => (string) $r['avans_procent']]);
    }

    /**
     * Avansul minim, spus în propoziția plății, înainte de plată, în informarea precontractuală și în contract,
     * fără pomenirea taxei locale (calculul rămâne: avansul minim e taxa locală plătită online). Variantele ($fel):
     *   avans     „Acum plătiți un avans de 300 lei: 30% din total, dar cel puțin 50 lei. Restul, 700 lei, îl plătiți la sosire.”
     *   integral  „Acum plătiți toată suma, 1.000 lei, din care avansul este de 300 lei: 30% din total, dar cel puțin 50 lei.”
     *   optiuni   „Avansul este de 300 lei: 30% din total, dar cel puțin 50 lei.” (cu alegerea încă de făcut)
     *   transfer  „Plătiți prin transfer bancar, {termen}, un avans de 300 lei: … Restul, 700 lei, îl plătiți la sosire.”
     * Doar cu taxa locală plătită online și un avans între 1 și 99%; altfel null.
     */
    public static function avansMinim(Traducator $t, string $limba, array $r, string $fel = 'avans', array $extra = []): ?string
    {
        if ((int) ($r['avans_minim'] ?? 0) <= 0 || $r['avans_procent'] <= 0 || $r['avans_procent'] >= 100) {
            return null;
        }
        $b = static fn (int $x): string => Format::baniOaspete($x, $limba);
        return $t->t($fel === 'avans' ? 'comanda.avans_minim' : 'comanda.avans_minim_' . $fel, ['avans' => $b((int) $r['avans']), 'procent' => (string) $r['avans_procent'],
            'avans_minim' => $b((int) $r['avans_minim']), 'rest' => $b((int) $r['rest_avans']), 'total' => $b((int) $r['integral'])] + $extra);
    }

    /**
     * ce primește oaspetele când gazda anulează rezervarea plătită, după alegerea gazdei din Metodele de plată
     * (`comanda.anulare_gazda.tot`, `.despagubire` cu avansul, `.caz`). Avansul e cel al plății de pe site (la avansul
     * 100%, toată plata).
     */
    private static function clauzaGazda(Aplicatie $app, Traducator $t, string $limba, array $r): ?string
    {
        // Fără alegere, site-ul nu primește rezervări (configurareLipsa), deci textul lipsește doar de pe paginile de probă.
        $v = \Cazo\Rezervare\Anulare::gazdaSite($app);
        return $v === null ? null : $t->t('comanda.anulare_gazda.' . $v, ['avans' => Format::baniOaspete((int) $r['avans'], $limba)]);
    }

    /** Lipește avansul minim înaintea propoziției reținerii. */
    private static function cuMinim(?string $minim, ?string $retinere): ?string
    {
        return $minim === null ? $retinere : ($retinere === null ? $minim : $minim . ' ' . $retinere);
    }

    /** Regula de plată a unui total (avansul, plata întreagă și taxa locală). */
    private static function regula(?array $plan, array $total): array
    {
        return RegulaPlata::calculeaza($plan, (int) $total['total'], $total['avans'] ?? null, RegulaPlata::taxa((array) ($total['componente'] ?? [])),
            RegulaPlata::incasare($total['taxa_incasare'] ?? null), ...RegulaPlata::rotunjire($total));
    }

    /**
     * Cum se spune pe rândul taxei locale din rezumat, din precontractual și din contract: „o plătiți la sosire”
     * când gazda o încasează la sosire; „inclusă în plata de acum” când e încasată online și site-ul primește doar plata
     * cu cardul; „inclusă în suma transferului” ori „în plata de la rezervare” când se plătește doar prin transfer sau
     * prin oricare dintre ele; altfel (cu plata la sosire printre metode), doar suma. Aceeași pe formular și în
     * copia durabilă.
     */
    private static function modTaxa(Aplicatie $app, Sejur $s, array $total): ?string
    {
        if (RegulaPlata::taxa((array) ($total['componente'] ?? [])) === 0) {
            return null;
        }
        if (RegulaPlata::incasare($total['taxa_incasare'] ?? null) === RegulaPlata::TAXA_LA_SOSIRE) {
            return 'la_sosire';
        }
        $mod = $app->modPlata()->pentru(self::planRand($app, $s), $s->sosire);
        if ($mod['cash']) {
            return null;
        }
        // Doar plăți făcute la rezervare: cu cardul („plata de acum”), prin transfer („suma transferului”) sau oricare („plata de la rezervare”).
        return $mod['card'] ? ($mod['transfer'] ? 'rezervare' : 'online') : ($mod['transfer'] ? 'transfer' : null);
    }

    /**
     * Planul unic al rezervării: cel cerut, dacă îl au toate camerele; altfel planul implicit al primei camere.
     * $comanda: sejurul unei comenzi începute își păstrează planul (un tarif nerambursabil rămas fără metodă oprește plata).
     */
    private static function cuPlan(Aplicatie $app, Sejur $s, bool $comanda = false): Sejur
    {
        $plan = $s->planCamera(0);
        $comune = null;
        foreach ($s->camere as $x) {
            $p = $app->pretNoapte()->planuriCamera($x['camera']);
            $comune = $comune === null ? $p : array_values(array_intersect($comune, $p));
        }
        // tariful nerambursabil cerut (o legătură mai veche), rămas fără nicio metodă plătită la rezervare, lasă
        // locul celui mai bun tarif oferit acum.
        if ($plan === null || !in_array($plan, (array) $comune, true) || (!$comanda && $app->oferte()->ascuns($plan, $s->sosire))) {
            // Fără un plan cerut: cel mai bun tarif cu anulare pentru datele alese; altfel Standard.
            $plan = $app->oferte()->potrivit($s, null)
                ?? (in_array('plan_std', (array) $comune, true) ? 'plan_std' : ((array) $comune)[0] ?? $app->pretNoapte()->planImplicit($s->camere[0]['camera']));
        }
        return $s->cuPlan($plan);
    }

    /**
     * Tarifele dintre care oaspetele poate alege pe comandă: cel mai bun tarif aplicabil pe fiecare
     * politică de anulare și masă, plus tariful comenzii, când e altul (o legătură mai veche, un tarif ales direct).
     */
    private static function planuri(Aplicatie $app, Componente $comp, Sejur $s, string $limba): array
    {
        $toate = $app->pretNoapte()->planuri();
        $ids = array_column($app->oferte()->alese($s), 'plan');
        $ales = $s->planCamera(0);
        if ($ales !== null && isset($toate[$ales]) && !in_array($ales, $ids, true)) {
            $ids[] = $ales;
        }
        $r = [];
        foreach ($ids as $id) {
            $r[] = ['id' => $id, 'titlu' => Componente::titluPlan($toate[$id], $limba, $app), 'masa' => Componente::masaPlan($toate[$id], $limba, $app->traducator($limba)), 'politica' => $comp->politicaAnulare($toate[$id], $comp->metodePlan($toate[$id], $s->sosire)), 'ales' => $id === $ales];
        }
        return count($r) > 1 ? Componente::cuDoarCazare($r, 'masa', $app->traducator($limba)) : [];
    }

    /** Câmpurile ascunse ale sejurului; $cuPlan = false pentru formularul de schimbare a tarifului. */
    private static function campuriSejur(Sejur $s, bool $cuPlan): array
    {
        $r = [['nume' => 'sosire', 'valoare' => $s->sosire], ['nume' => 'plecare', 'valoare' => $s->plecare]];
        foreach ($s->camere as $i => $x) {
            $r[] = ['nume' => "camere[$i][camera]", 'valoare' => $x['camera']];
            $r[] = ['nume' => "camere[$i][adulti]", 'valoare' => (string) $x['adulti']];
            $r[] = ['nume' => "camere[$i][copii]", 'valoare' => (string) $x['copii']];
        }
        if ($cuPlan && $s->plan !== null) {
            $r[] = ['nume' => 'plan', 'valoare' => $s->plan];
        }
        return $r;
    }

    /** Informarea precontractuală (11; OUG 34/2014): identitatea, prețul total cu componentele, plata, anularea, reclamațiile (ANPC, SAL). */
    private static function precontractual(Aplicatie $app, Traducator $t, string $limba, array $rez): array
    {
        $p = (array) ($app->ecOglinda()->corp('proprietate') ?? []);
        $bloc = new \Cazo\Legal\BlocLegal($app, $t, $limba);
        $op = $bloc->operator();
        $componente = implode(', ', array_map(static fn (array $x): string => mb_strtolower($x['eticheta']) . ' ' . $x['text'], $rez['componente']));
        // La persoana fizică: numele și contactul, fără adresă. La firmă: sediul ei (adresa comerciantului,
        // OUG 34/2014) și, separat, adresa cazării, fără scară, etaj și apartament: adresa proprietății nu se
        // prezintă ca a firmei.
        $adresa = $bloc->adresa();
        $sediu = trim((string) ($op['sediu'] ?? ''));
        $cheie = $adresa === '' ? 'comanda.precontractual.gazda_pf' : ($sediu !== '' ? 'comanda.precontractual.gazda' : 'comanda.precontractual.gazda_fara_sediu');
        $pret = $t->t('comanda.precontractual.pret', ['total' => $rez['total'], 'componente' => $componente]);
        // Ce se plătește separat, la sosire, în afara totalului, lângă preț.
        foreach ((array) ($rez['separat'] ?? []) as $x) {
            $pret .= ' ' . $x;
        }
        return [
            $t->cuContact($cheie, self::contactText($app),
                ['denumire' => (string) ($op['denumire'] ?? $p['nume'] ?? ''), 'adresa' => $adresa, 'sediu' => $sediu]),
            $pret,
            ...explode("\n", (string) $rez['plata']),
            $t->t('comanda.precontractual.anulare', ['politica' => $rez['politica']]),
            // lipsa dreptului de retragere, spusă explicit (OUG 34/2014 art. 6 alin. (1) lit. k) și art. 16 lit. l)),
            // după politica de anulare, la orice metodă de plată.
            $t->t('comanda.precontractual.retragere'),
            $t->cuContact('comanda.precontractual.reclamatii', self::contactText($app)),
        ];
    }

    /** @return list<array{cod:string, nume:string}> România întâi, apoi ordinea alfabetică a numelor în limba paginii */
    public static function tari(string $limba): array
    {
        $l = (array) require Aplicatie::radacina() . '/limbi/tari.php';
        $r = [];
        foreach ($l as $cod => [$ro, $en]) {
            $r[] = ['cod' => (string) $cod, 'nume' => $limba === 'ro' ? $ro : $en];
        }
        $prima = array_shift($r);
        usort($r, static fn (array $a, array $b): int => strcmp(self::fara($a['nume']), self::fara($b['nume'])));
        array_unshift($r, $prima);
        return $r;
    }

    private static function fara(string $s): string
    {
        return strtr(mb_strtolower($s), ['ă' => 'az', 'â' => 'az', 'î' => 'iz', 'ș' => 'sz', 'ț' => 'tz', 'é' => 'e', 'ô' => 'o', 'ç' => 'c', 'í' => 'i']);
    }

    // ---- validarea și crearea comenzii ----

    /** @return array<string,string> */
    private static function valori(Cerere $c): array
    {
        $camp = static fn (string $n, int $max): string => mb_substr(trim((string) preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', (string) ($c->camp($n) ?? ''))), 0, $max);
        return [
            'nume' => $camp('nume', 200),
            'email' => mb_strtolower($camp('email', 300)),
            'telefon' => $camp('telefon', 60),
            'tara' => strtoupper($camp('tara', 2)),
            'nota' => $camp('nota', self::NOTA_MAX + 200),
            'acord' => $c->camp('acord') === '1' ? '1' : '',
            'marketing' => $c->camp('marketing') === '1' ? '1' : '',
            'plata' => in_array($c->camp('plata'), ['avans', 'integral'], true) ? (string) $c->camp('plata') : '',
        ];
    }

    /** @return array<string,string> */
    private static function valideaza(array $v, Traducator $t): array
    {
        $e = [];
        if (mb_strlen($v['nume']) < 2 || mb_strlen($v['nume']) > 120 || !preg_match('~\p{L}~u', $v['nume'])) {
            $e['nume'] = $t->t('comanda.eroare.nume');
        }
        if (strlen($v['email']) > 254 || filter_var($v['email'], FILTER_VALIDATE_EMAIL) === false) {
            $e['email'] = $t->t('comanda.eroare.email');
        }
        $cifre = strlen((string) preg_replace('~\D~', '', $v['telefon']));
        if (!preg_match('~^\+?[0-9 ()./\-]{6,40}$~', $v['telefon']) || $cifre < 6 || $cifre > 20) {
            $e['telefon'] = $t->t('comanda.eroare.telefon');
        }
        $tari = (array) require Aplicatie::radacina() . '/limbi/tari.php';
        if (!isset($tari[$v['tara']])) {
            $e['tara'] = $t->t('comanda.eroare.tara');
        }
        if (mb_strlen($v['nota']) > self::NOTA_MAX) {
            $e['nota'] = $t->t('comanda.eroare.nota');
        }
        if ($v['acord'] !== '1') {
            $e['acord'] = $t->t('comanda.eroare.acord');
        }
        return $e;
    }

    /** @return list<string> nopțile fără unitate liberă (sau închise), din GET /disponibilitate pe interval; [] la eroare */
    private static function noptiOcupate(Aplicatie $app, Sejur $s): array
    {
        try {
            $r = $app->ecClient()->citeste('/disponibilitate', ['de' => $s->sosire, 'pana' => $s->plecare]);
        } catch (EroareApi|EroareTransport) {
            return [];
        }
        $cerute = array_count_values(array_column($s->camere, 'camera'));
        $pline = [];
        foreach ((array) ($r->json()['camere'] ?? []) as $cam) {
            $n = $cerute[$cam['id'] ?? ''] ?? 0;
            if ($n === 0) {
                continue;
            }
            foreach ((array) ($cam['nopti'] ?? []) as $z) {
                if (is_array($z) && is_string($z['zi'] ?? null) && $z['zi'] >= $s->sosire && $z['zi'] < $s->plecare
                    && (!empty($z['inchis']) || (int) ($z['libere'] ?? 0) < $n)) {
                    $pline[$z['zi']] = true;
                }
            }
        }
        $z = array_keys($pline);
        sort($z);
        return $z;
    }

    /** @return array{id:int, ref:string, token:string} */
    private static function creeazaComanda(Aplicatie $app, Cerere $c, string $limba, Sejur $s, array $v, array $total, string $ip24, string $fel = 'cash', ?string $alegere = null): array
    {
        $t = $app->traducator($limba);
        $termeni = (new PaginiLegale($app, $t, $limba))->pagina('termeni');
        $conf = (new PaginiLegale($app, $t, $limba))->pagina('confidentialitate');
        // Informarea precontractuală repetă exact ce a văzut oaspetele pe formular: politica de anulare după metodele
        // oferite. Rândul „Anularea” al contractului spune apoi regula metodei alese.
        $pl = self::plata($app, $t, $limba, $s, $total, $v);
        $rez = Rezumat::construieste($app, $t, $limba, $s->sosire, $s->plecare, $s->camere, $s->planCamera(0), $total['total'], $total['componente'], $total['total'],
            self::modTaxa($app, $s, $total), 0, ['card' => $pl['card'], 'transfer' => $pl['transfer'], 'cash' => $pl['cash']]);
        $plan = $s->planCamera(0);
        $planRand = $plan !== null ? ($app->pretNoapte()->planuri()[$plan] ?? null) : null;
        $anulareZile = $planRand !== null ? (($planRand['tip'] ?? null) === 'nerambursabil' ? 0 : ($planRand['anulare_zile'] !== null ? (int) $planRand['anulare_zile'] : null)) : null;
        $prop = $app->modelVedere($limba)->proprietate();
        $sumaAcum = 0;
        $rest = (int) $total['total'];
        $procent = null;
        $buton = $t->t('comanda.buton_cash');
        $transfer = null;
        if ($fel === 'card' || $fel === 'transfer') {
            $regula = self::regula($planRand, $total);
            // prin transfer se plătește avansul planului; fără avans (sau la 100%), tot.
            $a = RegulaPlata::sumaAcum($regula, (int) $total['total'], $fel === 'transfer' ? 'avans' : $alegere);
            $sumaAcum = $a['suma_acum'];
            $rest = $a['rest_la_sosire'];
            $procent = $regula['avans_procent'];
            $buton = $t->t($fel === 'transfer' ? 'comanda.buton_transfer' : 'comanda.buton_card');
            $rez['la_sosire'] = $t->t('comanda.la_sosire', ['suma' => Format::baniOaspete($rest, $limba)]);
            if ($fel === 'transfer') {
                // Termenul de plată și contul arătat oaspetelui, înghețate acum (termenul pleacă în corpul trimis lui EasyCheck).
                $transfer = ['suma' => $sumaAcum, 'termen' => (int) $app->contTransfer()->termenPentru($s->sosire), 'cont' => $app->contTransfer()->instantaneu()];
            }
        }
        $cuPlata = $fel === 'card' || $fel === 'transfer';
        // Plata din informarea precontractuală: același text ca pe formular (toate opțiunile și metodele arătate), cu
        // reținerea după termen în politica de anulare a contractului.
        $rez['plata'] = $pl['precontractual'];
        // În contractul plății cu cardul, politica de anulare spune și ce se reține după termen.
        // textul metodei alese (cu cardul, și regula mutării pe alte date; prin transfer, fără ea). cu cardul,
        // avansul minim în varianta aleasă de oaspete (avansul sau toată suma).
        $rez['retinere'] = $fel === 'card' ? ($pl['regula_aleasa'] ?? $pl['regula'] ?? null) : ($fel === 'transfer' ? ($pl['regula_transfer'] ?? null) : null);
        // Ce se plătește separat, la sosire, în afara totalului: și în informarea din contract.
        $rez['separat'] = (new Componente($app, $app->modelVedere($limba), $t, $limba, null, $ip24))->separat($s);
        $precontractual = self::precontractual($app, $t, $limba, $rez);
        if ($planRand !== null) {
            $rez['politica'] = (new Componente($app, $app->modelVedere($limba), $t, $limba, null, $ip24))->politicaAnulare($planRand, [$fel => true]);
        }
        $contract = self::contract($app, $t, $limba, $rez, $precontractual, $termeni, $conf);
        $contract['text_buton'] = $buton;
        return $app->comenzi()->creeaza([
            'contract' => $contract,
            'fel' => $fel,
            'transfer' => $transfer,
            'plata_aleasa' => $cuPlata ? $a['alegere'] : null,
            // cum încasează gazda taxa locală, din ziua comenzii (plata cu cardul și schimbarea datelor o folosesc).
            // Alegerea gazdei, oricât ar fi taxa sejurului de acum (și 0): noile date ale unei mutări o urmează.
            'taxa_incasare' => $cuPlata ? RegulaPlata::incasare($total['taxa_incasare'] ?? null) : null,
            'limba' => $limba,
            'proba' => $app->ecLicenta()->stare()['proba'],
            'sosire' => $s->sosire,
            'plecare' => $s->plecare,
            'camere' => array_map(static fn (array $x): array => ['camera' => $x['camera'], 'adulti' => $x['adulti'], 'copii' => $x['copii']], $s->camere),
            'plan' => $plan,
            'sursa_pret' => (string) $total['sursa'],
            'cheie_prefix' => \Cazo\EasyCheck\Conectare::prefix($app->cheieEc()),
            'total' => (int) $total['total'],
            // alegerea gazdei cu care s-a calculat totalul (la „Prețul din EasyCheck”), repetată în corpul trimis.
            'include' => $total['include'] ?? null,
            // momentul încasării taxei locale declarat la GET /total, repetat în corpul trimis.
            'taxa_locala_incasare' => ($total['taxa_incasare_declarata'] ?? false) === true ? RegulaPlata::incasare($total['taxa_incasare'] ?? null) : null,
            'componente' => $total['componente'],
            'camere_parti' => $total['camere'] ?? null,
            // Reducerile locale aplicate, informativ pentru panou; oaspetele vede doar prețul redus.
            'reduceri' => $total['reduceri'] ?? null,
            'avans_procent' => $procent,
            'suma_acum' => $sumaAcum,
            'rest_la_sosire' => $rest,
            'anulare_zile' => $anulareZile,
            'anulare_limita' => $anulareZile !== null ? gmdate('Y-m-d', strtotime($s->sosire . ' 00:00:00 UTC') - $anulareZile * 86400) : null,
            // Regula de reținere din ziua rezervării (ca EasyCheck): după termen se reține avansul planului; la
            // tariful nerambursabil, tot ce s-a plătit.
            'avans' => $cuPlata ? $regula['avans'] : null,
            'nerambursabil' => ($planRand['tip'] ?? null) === 'nerambursabil',
            // după o mutare pe alte date, ce avans se păstrează la anularea după termen (alegerea gazdei de azi, în contract).
            'avans_mutare' => $fel === 'card' ? \Cazo\Rezervare\Anulare::mutareSite($app) : null,
            // ce primește oaspetele când gazda anulează (alegerea gazdei de azi, în contract); doar cu plata de pe site.
            'anulare_gazda' => $cuPlata ? \Cazo\Rezervare\Anulare::gazdaSite($app) : null,
            // aceeași clauză, trimisă la EasyCheck cu rezervarea, doar cu funcția etapei 3 aprinsă.
            'contract_anulare' => $cuPlata ? $app->hotarariEc()->contract(['anulare_gazda' => \Cazo\Rezervare\Anulare::gazdaSite($app), 'avans' => $regula['avans']]) : null,
            'oaspete' => ['nume' => $v['nume'], 'email' => $v['email'], 'telefon' => $v['telefon'], 'tara' => $v['tara']],
            'nota' => $v['nota'],
            'marketing' => $v['marketing'] === '1',
            // cu `functii.abonati` în EasyCheck, acordul pentru noutăți pleacă odată cu rezervarea sau cererea cash
            // (textul văzut, limba). Textul are cel mult 500 de caractere; unul mai lung urcă prin `POST /abonati/acorduri` tăiat.
            'oferte' => $v['marketing'] === '1' && $app->abonati()->cuEasyCheck() && mb_strlen($t->t('comanda.noutati', ['nume' => $prop['nume']])) <= 500
                ? ['acord' => true, 'la' => gmdate('Y-m-d\TH:i:s\Z', $app->ceas()->acum()), 'text' => $t->t('comanda.noutati', ['nume' => $prop['nume']]), 'limba' => $limba] : null,
            'ip24' => $ip24,
            'acord' => [
                'termeni_versiune' => $termeni['versiune'],
                'termeni_hash' => hash('sha256', json_encode($termeni, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                'confidentialitate_versiune' => $conf['versiune'],
                'text_buton' => $buton,
                'text_precontractual_hash' => hash('sha256', implode("\n", $precontractual)),
                'text_marketing_hash' => hash('sha256', $t->t('comanda.noutati', ['nume' => $prop['nume']])),
                'ip' => $c->ip,
                'agent' => (string) ($c->antet('user-agent') ?? ''),
            ],
        ]);
    }

    /**
     * Contractul înghețat la comandă: exact textele văzute și acceptate de oaspete, în limba comenzii;
     * e-mailul de confirmare se scrie din ele, chiar dacă oglinda sau textele legale se schimbă până la răspunsul gazdei.
     */
    private static function contract(Aplicatie $app, Traducator $t, string $limba, array $rez, array $precontractual, array $termeni, array $conf): array
    {
        $bloc = new \Cazo\Legal\BlocLegal($app, $t, $limba);
        return [
            'v' => 1,
            'camere' => $rez['camere'],
            'sosire' => $rez['sosire'],
            'plecare' => $rez['plecare'],
            'tarif' => $rez['tarif'],
            'politica' => trim($rez['politica'] . ' ' . ($rez['retinere'] ?? '')),
            'total' => $rez['total'],
            'componente' => $rez['componente'],
            'reguli' => $rez['reguli'],
            'operator' => $bloc->date()['randuri'],
            // La persoana fizică, contractul păstrează doar numele și contactul.
            // Contractul ajunge la oaspete cu e-mailul de confirmare, deci după rezervare: adresa întreagă.
            'adresa' => $bloc->adresa(true),
            // Contractul pleacă odată cu confirmarea: contactul întreg, cu telefonul gazdei.
            'contact' => \Cazo\Email\Sabloane::contact((array) ($app->ecOglinda()->corp('proprietate') ?? [])),
            'precontractual' => $precontractual,
            'termeni' => ['versiune' => $termeni['versiune'], 'titlu' => $termeni['titlu'], 'intro' => $termeni['intro'], 'sectiuni' => $termeni['sectiuni'],
                'url' => $app->url()->absolut(Cai::cale('termeni', $limba), $limba), 'eticheta_versiune' => $t->t('email.contract.versiune', ['versiune' => $termeni['versiune']])],
            'confidentialitate' => ['versiune' => $conf['versiune'], 'url' => $app->url()->absolut(Cai::cale('confidentialitate', $limba), $limba)],
            'text_buton' => $t->t('comanda.buton_cash'),
            // M6b: paragraful gazdei din confirmări, înghețat odată cu contractul (o retrimitere iese identic).
            'nota_gazda' => ['cerere_acceptata' => \Cazo\Email\Sabloane::notaEfectiva($app, 'cerere_acceptata', $limba),
                'plata_confirmata' => \Cazo\Email\Sabloane::notaEfectiva($app, 'plata_confirmata', $limba)],
            // șablonul confirmării scris de gazdă în EasyCheck (subiectul, corpul, versiunea oglinzii, limba), înghețat ca
            // paragraful; null = textul standard al programului.
            'sablon_gazda' => ['cerere_acceptata' => $app->mesajeEc()->sablon('cerere_acceptata', $limba), 'plata_confirmata' => $app->mesajeEc()->sablon('plata_confirmata', $limba)],
        ];
    }

    /**
     * Contactul gazdei într-un text („telefon, e-mail”). Pe site și până la confirmarea rezervării e contactul public
     * (e-mailul; telefonul doar la alegerea gazdei); pentru o rezervare confirmată ($cm), oaspetele primește
     * și telefonul.
     */
    public static function contactText(Aplicatie $app, ?array $cm = null): string
    {
        $o = $app->ecOglinda();
        return \Cazo\Email\Sabloane::contact((array) (($cm !== null && self::confirmata($cm) ? $o->corp('proprietate') : $o->proprietatePublica()) ?? []));
    }

    /** Rezervarea e confirmată: de acum oaspetele are nevoie de telefonul gazdei. */
    public static function confirmata(array $cm): bool
    {
        return in_array($cm['stare'] ?? null, ['confirmata', 'anulare_ceruta'], true);
    }

    private static function zile(array $zile, string $limba): string
    {
        $f = array_map(static fn (string $z): string => Format::zi($z, $limba), array_slice($zile, 0, 5));
        return implode(', ', $f) . (count($zile) > 5 ? ' …' : '');
    }
}
