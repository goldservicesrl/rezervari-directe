<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\EasyCheck\EroareApi;
use Cazo\EasyCheck\EroareTransport;
use Cazo\I18n\Traducator;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;
use Cazo\Rezervare\ContTransfer;
use Cazo\Rezervare\Iban;
use Cazo\Rezervare\Transfer;
use Cazo\Securitate\SesiuneAdmin;

defined('CAZO') or exit;

/**
 * Transferul bancar în panou: contul în care plătesc oaspeții și deciziile de probă ale plății.
 *
 * Contul (titularul, IBAN-ul, banca, SWIFT-ul, termenul de plată, zilele dinaintea sosirii) stă pe pagina „Metodele de
 * plată”, sub bifa „Prin transfer bancar”, în formularul metodelor: șablonul parțial
 * `vederi/admin/_transfer.twig` (doar câmpurile), datele lui (`date()`) și salvarea împreună cu bifele
 * (`salveazaCuMetode()`, POST /admin/plati cu acțiunea `metode`). Regula: bifa cere contul complet, trimis
 * în aceeași salvare sau salvat dinainte. Schimbarea contului bancar cere parola confirmată în ultimele 10 minute
 *; termenul și zilele se schimbă fără ea. La o greșeală, pagina se întoarce cu 422, cu bifele și valorile trimise
 * de gazdă și cu erorile lângă câmpuri. Salvarea doar a contului (`salveaza()`, POST /admin/plati/transfer) rămâne pentru
 * formularele vechi. Instalatorul (pasul plăților) folosește aceleași câmpuri, în formularul lui. După salvare, softul
 * propune forma cu majuscule a titularului și a băncii, fără să o impună (ContTransfer::propunere).
 *
 * Butoanele de probă ale plății (doar pe cheia de probă, ca „Acceptați (probă)” la cereri): POST
 * /admin/transfer/{ref}/proba-confirma | proba-refuza, de pe Acasă și de pe pagina rezervării. Pe cheia reală,
 * primirea banilor se confirmă doar în EasyCheck.
 */
final class AdminTransfer
{
    public const CAMPURI = ['titular', 'iban', 'banca', 'swift', 'termen_ore', 'zile_inainte'];
    /** Câmpurile contului bancar: schimbarea lor cere parola confirmată. */
    public const CONT = ['titular', 'iban', 'banca', 'swift'];

    /** Erorile ultimei salvări refuzate (câmp => cheia textului), arătate de pagina reafișată în aceeași cerere. */
    private static ?array $erori = null;

    /** Valorile componentei dintr-un formular trimis. @return array{bifat:bool, valori:array<string,string>} */
    public static function dinCerere(Cerere $c): array
    {
        $v = [];
        foreach (self::CAMPURI as $k) {
            $v[$k] = (string) ($c->camp('transfer_' . $k) ?? '');
        }
        return ['bifat' => $c->camp('metoda_transfer') === '1', 'valori' => $v];
    }

    /** Formularul trimis acum cuprinde câmpurile contului (câmpul ascuns din șablon). */
    public static function trimis(Cerere $c): bool
    {
        return $c->metoda === 'POST' && $c->camp('actiune_transfer') === '1';
    }

    /**
     * Datele șablonului parțial: valorile salvate sau, după o trimitere cu erori, cele scrise de gazdă, cu erorile lor.
     * @param string $id prefixul id-urilor din HTML (panoul și instalatorul au fiecare prefixul lui)
     * @param ?bool $bifat transferul e (sau va fi) bifat între metode, deci titularul și IBAN-ul sunt obligatorii; null: după setarea salvată
     * @return array<string,mixed>
     */
    public static function date(Aplicatie $app, Cerere $c, Traducator $t, string $id = 'pa-tr', ?bool $bifat = null): array
    {
        $cont = $app->contTransfer()->citeste();
        $valori = ['titular' => $cont['titular'], 'iban' => Iban::grupat($cont['iban']), 'banca' => $cont['banca'], 'swift' => $cont['swift'],
            'termen_ore' => (string) $cont['termen_ore'], 'zile_inainte' => (string) $cont['zile_inainte']];
        $erori = [];
        if (self::trimis($c)) {
            $f = self::dinCerere($c);
            $valori = array_map(static fn (string $x): string => mb_substr($x, 0, 200), $f['valori']);
            foreach (self::$erori ?? ContTransfer::valideaza($f['valori'], $bifat ?? $app->modPlata()->are('transfer'))['erori'] as $camp => $cheie) {
                $erori[$camp] = $t->t($cheie);
            }
        }
        return ['id' => $id, 'valori' => $valori, 'erori' => $erori, 'url' => $app->url()->cale('/admin/plati/transfer'), 'ec_url' => self::urlEasyCheck($app),
            // forma cu majuscule a titularului și a băncii, propusă după salvarea contului.
            'majuscule' => $app->contTransfer()->propunere(),
            'limite' => ['termen' => ContTransfer::TERMEN_ORE, 'zile' => ContTransfer::ZILE]];
    }

    /** Pagina „Cererile de pe site” din EasyCheck, unde gazda confirmă primirea banilor. */
    public static function urlEasyCheck(Aplicatie $app): string
    {
        $p = parse_url((string) ($app->config()->easycheck()['baza'] ?? ''));
        $origine = is_array($p) && isset($p['scheme'], $p['host']) ? $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') : 'https://easycheck.ro';
        return $origine . '/host/cereri-site.php';
    }

    /**
     * POST /admin/plati/transfer: doar proprietarul. Cu transferul bifat între metode, titularul și IBAN-ul rămân
     * obligatorii; schimbarea contului bancar cere parola confirmată.
     */
    public static function salveaza(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $f = self::dinCerere($c);
        $bifat = $app->modPlata()->are('transfer');
        $r = ContTransfer::valideaza($f['valori'], $bifat);
        $erori = $r['erori'];
        $salvat = $app->contTransfer()->citeste();
        $schimbat = array_intersect_key($r['valori'], array_flip(self::CONT)) !== array_intersect_key($salvat, array_flip(self::CONT));
        if ($erori === [] && $schimbat && !AdminPanou::reautentificat($app, $c, $u, $jeton)) {
            $erori['reauth'] = 'admin.transfer.eroare.reauth';
        }
        if ($erori !== []) {
            // Pagina metodelor, cu valorile scrise și erorile lângă câmpuri (date() le ia de aici, în aceeași cerere).
            self::$erori = $erori;
            try {
                $pagina = Admin::metode($app, $c, $p, $limba);
            } finally {
                self::$erori = null;
            }
            $pagina->status = 422;
            return $pagina;
        }
        $app->contTransfer()->salveaza($r['valori'], (int) $u['id']);
        $app->auditDetalii(['cont' => $schimbat ? 'schimbat' : 'neschimbat']);
        return Raspuns::redirectare($app->url()->cale('/admin/metode-plata', null, ['mesaj' => 'cont_transfer']) . '#transfer');
    }

    /**
     * POST /admin/plati, acțiunea `metode`, cu câmpurile contului în formular: bifele metodelor și contul
     * transferului bancar se salvează împreună. Cu bifa transferului pusă, contul trebuie să fie complet, iar
     * schimbarea lui cere parola confirmată; fără bifă, câmpurile contului stau ascunse și contul salvat rămâne
     * neatins. La o greșeală, pagina metodelor se întoarce cu 422, cu ce a trimis gazda și cu erorile lângă câmpuri.
     */
    public static function salveazaCuMetode(Aplicatie $app, Cerere $c): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $inapoi = static fn (array $q): Raspuns => Raspuns::redirectare($app->url()->cale('/admin/metode-plata', null, $q));
        $f = self::dinCerere($c);
        $cuCard = $c->camp('metoda_card') === '1';
        $m = \Cazo\Rezervare\ModPlata::dinBife($cuCard, $c->camp('metoda_cash') === '1', $f['bifat']);
        if ($m === null) {
            return $inapoi(['eroare' => 'metode_lipsa']);
        }
        if ($cuCard && !$app->stripe()->configurat()) {
            return $inapoi(['eroare' => 'metode_fara_stripe']);
        }
        $schimbat = false;
        if ($f['bifat']) {
            $r = ContTransfer::valideaza($f['valori'], true);
            $erori = $r['erori'];
            $schimbat = array_intersect_key($r['valori'], array_flip(self::CONT)) !== array_intersect_key($app->contTransfer()->citeste(), array_flip(self::CONT));
            if ($erori === [] && $schimbat && !AdminPanou::reautentificat($app, $c, $u, $jeton)) {
                $erori['reauth'] = 'admin.transfer.eroare.reauth';
            }
            if ($erori !== []) {
                self::$erori = $erori;
                try {
                    $pagina = Admin::metode($app, $c, [], (string) $u['limba_panou']);
                } finally {
                    self::$erori = null;
                }
                $pagina->status = 422;
                return $pagina;
            }
            $app->contTransfer()->salveaza($r['valori'], (int) $u['id']);
        }
        $app->modPlata()->alegeMetode($m);
        $app->auditDetalii(['metode' => $m] + ($f['bifat'] ? ['cont' => $schimbat ? 'schimbat' : 'neschimbat'] : []));
        return $inapoi(['mesaj' => 'metode_' . $m]);
    }

    /** Rezervarea își poate primi decizia de probă: cheia de probă, plata în așteptare, comanda de probă. */
    public static function poateProba(Aplicatie $app, array $cm): bool
    {
        // fără cheie EasyCheck, „Am primit banii” e decizia reală a gazdei, pe orice rezervare.
        if ($app->modLocal()) {
            return $cm['fel'] === Transfer::FEL && $cm['stare'] === Transfer::ASTEPTARE && $cm['ec_rezervare'] !== null;
        }
        return $app->ecLicenta()->stare()['proba'] && $cm['fel'] === Transfer::FEL && $cm['stare'] === Transfer::ASTEPTARE && (int) $cm['proba'] === 1 && $cm['ec_rezervare'] !== null;
    }

    /** POST /admin/transfer/{ref}/proba-confirma | proba-refuza: doar la cheile de probă, pe o rezervare care așteaptă plata. */
    public static function proba(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $u = $app->sesiuneAdmin()->utilizator($c->cookie(SesiuneAdmin::COOKIE));
        if ($u === null) {
            return Raspuns::redirectare($app->url()->cale('/admin/'));
        }
        $inapoi = static fn (array $q): Raspuns => Raspuns::redirectare($app->url()->cale('/admin/', null, $q));
        // butoanele de probă stau și pe pagina rezervării; decizia luată acolo se întoarce acolo (erorile, pe Acasă).
        $reusit = static fn (string $mesaj): Raspuns => $c->camp('inapoi') === 'rezervare'
            ? Raspuns::redirectare($app->url()->cale('/admin/rezervari/' . $p['ref'], null, ['mesaj' => $mesaj])) : $inapoi(['mesaj' => $mesaj]);
        // Decizia asupra plății (chiar de probă) e a proprietarului.
        if (!Admin::poateDecide($u)) {
            return $inapoi(['eroare' => 'rol.-']);
        }
        $cm = $app->comenzi()->dupaRef((string) $p['ref']);
        if ($cm === null || !self::poateProba($app, $cm)) {
            return $inapoi(['eroare' => 'stare_invalida.-']);
        }
        try {
            $app->ecClient()->scrie('POST', '/rezervari/' . $cm['ec_rezervare'] . '/' . ($p['decizie'] === 'proba-confirma' ? 'proba-plata-confirma' : 'proba-plata-refuza'), null);
        } catch (EroareApi $e) {
            return $inapoi(['eroare' => ($e->cod ?? 'http_' . $e->raspuns->status) . '.' . ($e->requestId ?? '-')]);
        } catch (EroareTransport) {
            return $inapoi(['eroare' => 'retea.-']);
        }
        // Decizia se citește pe loc; anunțul `rezervare.plata_*` vine oricum, după, și nu mai schimbă nimic.
        $app->transfer()->urmareste(false, (int) $cm['id'], 'admin:' . (int) $u['id']);
        $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
        return $reusit($p['decizie'] === 'proba-confirma' ? 'plata_confirmata_proba' : 'plata_refuzata_proba');
    }

    /** „Prelungiți termenul” se poate apăsa: modul fără cheie, plata încă așteptată, termenul încă în curs. */
    public static function poatePrelungi(Aplicatie $app, array $cm): bool
    {
        return $app->modLocal() && self::poateProba($app, $cm) && $cm['transfer_anuntat_la'] === null && $cm['transfer_termen'] !== null
            && (string) $cm['transfer_termen'] > $app->ceas()->moment();
    }

    /** Variantele prelungirii, pentru lista din panou: [zile => text]. @return array<int,string> */
    public static function variantePrelungire(Traducator $t): array
    {
        $r = [];
        foreach (\Cazo\Local\TransportLocal::PRELUNGIRI_ZILE as $z) {
            $r[$z] = $t->plural('admin.transfer.prelungire_zile', $z);
        }
        return $r;
    }

    /**
     * POST /admin/transfer/{ref}/prelungeste: doar în modul fără cheie EasyCheck și doar proprietarul, ca
     * „Am primit banii”. Termenul în vigoare crește cu 1, 2, 3 sau 7 zile; după termen, după decizie sau după „Am plătit”
     * de la oaspete, refuzat. Termenul nou se citește pe loc (`Transfer::urmareste`): comanda îl primește, jurnalul
     * rezervării scrie cine a prelungit, iar oaspetele primește e-mailul `transfer_prelungit`.
     */
    public static function prelungeste(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $u = $app->sesiuneAdmin()->utilizator($c->cookie(SesiuneAdmin::COOKIE));
        if ($u === null) {
            return Raspuns::redirectare($app->url()->cale('/admin/'));
        }
        $peRezervare = $c->camp('inapoi') === 'rezervare';
        $inapoi = static fn (array $q): Raspuns => Raspuns::redirectare($peRezervare ? $app->url()->cale('/admin/rezervari/' . $p['ref'], null, $q) : $app->url()->cale('/admin/', null, $q));
        if (!Admin::poateDecide($u)) {
            return $inapoi(['eroare' => $peRezervare ? 'drept' : 'rol.-']);
        }
        $zile = (int) $c->camp('zile');
        $cm = $app->comenzi()->dupaRef((string) $p['ref']);
        if ($cm === null || !$app->modLocal() || !in_array($zile, \Cazo\Local\TransportLocal::PRELUNGIRI_ZILE, true)) {
            return $inapoi(['eroare' => $peRezervare ? 'stare' : 'stare_invalida.-']);
        }
        if (!self::poatePrelungi($app, $cm)) {
            return $inapoi(['eroare' => $peRezervare ? 'termen_trecut' : 'termen_trecut.-']);
        }
        try {
            $app->ecClient()->scrie('POST', '/rezervari/' . $cm['ec_rezervare'] . '/prelungeste-termen', \Cazo\Local\TransportLocal::json(['zile' => $zile]));
        } catch (EroareApi $e) {
            // 409: termenul a trecut sau plata s-a hotărât între timp; citirea de mai jos aduce starea.
            $app->transfer()->urmareste(false, (int) $cm['id'], 'admin:' . (int) $u['id']);
            return $inapoi(['eroare' => $peRezervare ? 'termen_trecut' : 'termen_trecut.-']);
        } catch (EroareTransport) {
            return $inapoi(['eroare' => $peRezervare ? 'stare' : 'retea.-']);
        }
        $app->auditDetalii(['ref' => (string) $cm['ref'], 'zile' => $zile]);
        $app->transfer()->urmareste(false, (int) $cm['id'], 'admin:' . (int) $u['id']);
        $app->dupaRaspuns(static fn () => $app->emailuri()->trimite(5));
        return $inapoi(['mesaj' => 'termen_prelungit']);
    }
}
