<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\I18n\Format;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;
use Cazo\Oferte\Abonati;
use Cazo\Oferte\Trimiteri;

defined('CAZO') or exit;

/**
 * „Abonații la oferte” (Rezervări și plăți), doar pentru proprietar (lista are datele oaspeților, iar trimiterea
 * pleacă în numele site-ului):
 *
 *   GET  /admin/abonati                          câți sunt, lista unită (site și, cu `functii.abonati`), oferta
 *                                                de scris, jurnalul trimiterilor
 *   POST /admin/abonati/previzualizeaza          oferta, cum o vede abonatul (subiectul și textul rămân în ciornă)
 *   POST /admin/abonati/proba                    oferta, la adresa proprietarului, pe loc
 *   POST /admin/abonati/trimite                  oferta, la toată lista, prin cutia de e-mailuri, cu confirmarea și parola
 *   POST /admin/abonati/dezaboneaza              scoate o adresă din listă (și din EasyCheck)
 *   POST /admin/abonati/export                   lista unită, ca CSV, cu parola
 */
final class AdminAbonati
{
    public const MESAJE = ['proba' => 'admin.abonati.mesaj_proba', 'dezabonat' => 'admin.abonati.mesaj_dezabonat'];
    public const ERORI = ['reauth' => 'admin.reauth.eroare', 'proba' => 'admin.abonati.eroare_proba', 'in_curs' => 'admin.abonati.eroare_in_curs',
        'gol' => 'admin.abonati.eroare_gol', 'confirmare' => 'admin.abonati.eroare_confirmare', 'email' => 'admin.abonati.eroare_email'];

    public static function pagina(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'abonati', self::MESAJE, self::ERORI);
        $n = (string) ($c->param('n') ?? '');
        if (($c->param('mesaj') ?? '') === 'trimisa' && preg_match('~^[1-9][0-9]{0,6}$~', $n) === 1) {
            $d['mesaj'] = $app->traducator((string) $u['limba_panou'], 'admin')->t('admin.abonati.mesaj_trimisa', ['n' => $n, 'ora' => (string) $app->emailuri()->ofertePeOra()]);
        }
        return self::randeaza($app, $u, $d, $r);
    }

    public static function actiune(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $actiune = (string) $p['actiune'];
        if ($actiune === 'dezaboneaza') {
            $email = trim((string) ($c->camp('email') ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return AdminPanou::inapoi($app, '/admin/abonati', ['eroare' => 'email']);
            }
            $n = $app->abonati()->dezaboneaza($email, 'gazda');
            $app->auditDetalii(['acorduri' => $n]);
            return AdminPanou::inapoi($app, '/admin/abonati', ['mesaj' => 'dezabonat']);
        }
        if ($actiune === 'export') {
            if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
                return self::cuEroare($app, $c, $u, $jeton, 'admin.reauth.eroare', ['eroare_la' => 'export']);
            }
            return self::csv($app, (string) $u['limba_panou']);
        }
        $subiect = trim((string) preg_replace('~\s+~u', ' ', (string) ($c->camp('subiect') ?? '')));
        $text = str_replace("\r\n", "\n", trim((string) ($c->camp('text') ?? '')));
        $scris = ['subiect' => mb_substr($subiect, 0, Trimiteri::SUBIECT_MAX + 50), 'text' => mb_substr($text, 0, Trimiteri::TEXT_MAX + 100)];
        $erori = Trimiteri::valideaza($subiect, $text);
        if ($erori === []) {
            $app->setari()->pune(Trimiteri::SETARE_CIORNA, $scris, (int) $u['id']);
        }
        if ($erori !== []) {
            return self::cuEroare($app, $c, $u, $jeton, 'admin.abonati.eroare_formular', ['scris' => $scris, 'erori_camp' => $erori, 'eroare_la' => 'oferta']);
        }
        if ($actiune === 'previzualizeaza') {
            $r = Raspuns::html('');
            $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'abonati', self::MESAJE, self::ERORI);
            return self::randeaza($app, $u, $d + ['scris' => $scris, 'previzualizare' => true], $r);
        }
        if ($actiune === 'proba') {
            $ok = $app->oferteTrimiteri()->proba($subiect, $text, $u);
            return AdminPanou::inapoi($app, '/admin/abonati', $ok ? ['mesaj' => 'proba'] : ['eroare' => 'proba']);
        }
        // trimite: confirmarea și parola, apoi toată lista.
        if ($c->camp('confirm') !== '1') {
            return self::cuEroare($app, $c, $u, $jeton, 'admin.abonati.eroare_confirmare', ['scris' => $scris, 'eroare_la' => 'trimite']);
        }
        if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
            return self::cuEroare($app, $c, $u, $jeton, 'admin.reauth.eroare', ['scris' => $scris, 'eroare_la' => 'trimite']);
        }
        $rez = $app->oferteTrimiteri()->trimite($subiect, $text, $u);
        if (is_string($rez)) {
            return self::cuEroare($app, $c, $u, $jeton, self::ERORI[$rez], ['scris' => $scris, 'eroare_la' => 'trimite']);
        }
        $app->auditDetalii(['trimitere' => $rez['id'], 'destinatari' => $rez['destinatari']]);
        return AdminPanou::inapoi($app, '/admin/abonati', ['mesaj' => 'trimisa', 'n' => (string) $rez['destinatari']]);
    }

    /** Pagina întoarsă după o greșeală (422), cu ce a scris gazda păstrat. */
    private static function cuEroare(Aplicatie $app, Cerere $c, array $u, string $jeton, string $eroare, array $extra): Raspuns
    {
        $r = Raspuns::html('', 422);
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'abonati', self::MESAJE, self::ERORI);
        $d['mesaj'] = null;
        $d['eroare'] = $app->traducator((string) $u['limba_panou'], 'admin')->t($eroare);
        return self::randeaza($app, $u, $extra + $d, $r);
    }

    private static function randeaza(Aplicatie $app, array $u, array $d, Raspuns $r): Raspuns
    {
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $lista = $app->abonati()->lista();
        $abonati = [];
        foreach ($lista['abonati'] as $a) {
            $abonati[] = ['email' => $a['email'], 'nume' => $a['nume'], 'limba' => $t->t('admin.abonati.limba.' . $a['limba']),
                'sursa' => implode(', ', array_map(static fn (string $x): string => $t->t('admin.abonati.sursa.' . $x), $a['surse'])),
                'acord_la' => Format::moment($a['acord_la'], $l)];
        }
        $ciorna = $app->setari()->get(Trimiteri::SETARE_CIORNA);
        $scris = (array) ($d['scris'] ?? (is_array($ciorna) ? $ciorna : [])) + ['subiect' => '', 'text' => ''];
        $previzualizare = null;
        if (($d['previzualizare'] ?? false) === true) {
            $previzualizare = $app->oferteTrimiteri()->continut((string) $scris['subiect'], (string) $scris['text'], $l === 'en' ? 'en' : 'ro', (string) $u['email'], '');
        }
        $smtp = $app->setari()->get('email.smtp');
        $jurnal = [];
        foreach ($app->oferteTrimiteri()->jurnal() as $x) {
            $jurnal[] = $x + ['la' => Format::moment($x['creat_la'], $l)];
        }
        $d = array_merge($d, [
            'abonati' => $abonati,
            'numar' => count($abonati),
            'easycheck' => $lista['easycheck'],
            'eroare_ec' => $lista['eroare_ec'],
            'de_urcat' => $lista['de_urcat'] ?? 0,
            'ec_la' => $lista['ec_la'] !== null ? Format::moment($lista['ec_la'], $l) : null,
            'fara_smtp' => !is_array($smtp) || !is_string($smtp['gazda'] ?? null) || $smtp['gazda'] === '',
            'scris' => $scris,
            'erori_camp' => array_map(static fn (string $k): string => $t->t($k), (array) ($d['erori_camp'] ?? [])),
            'eroare_la' => $d['eroare_la'] ?? null,
            'previzualizare' => $previzualizare,
            'in_curs' => $app->oferteTrimiteri()->inCurs(),
            'pe_ora' => $app->emailuri()->ofertePeOra(),
            'jurnal' => $jurnal,
            'url' => ['baza' => $app->url()->cale('/admin/abonati/'), 'emailuri' => $app->url()->cale('/admin/emailuri') . '#pa-smtp', 'easycheck' => Abonati::EASYCHECK_LISTA,
                'gdpr' => $app->url()->cale('/admin/gdpr') . '#oaspeti'],
        ]);
        return AdminPanou::pagina($app, $u, 'abonati', $d, $r);
    }

    /** Lista unită ca CSV: adresa, numele, limba, sursa, data acordului (în fusul site-ului). */
    private static function csv(Aplicatie $app, string $l): Raspuns
    {
        $t = $app->traducator($l, 'admin');
        $date = [[$t->t('admin.legal.csv.email'), $t->t('admin.csv.oaspete'), $t->t('admin.legal.csv.limba'), $t->t('admin.abonati.col.sursa'), $t->t('admin.legal.csv.la')]];
        $lista = $app->abonati()->lista(true)['abonati'];
        foreach ($lista as $a) {
            $date[] = [$a['email'], $a['nume'], $a['limba'], implode(', ', $a['surse']), Format::moment($a['acord_la'], $l)];
        }
        $app->auditDetalii(['randuri' => count($lista)]);
        return AdminLista::csv($date, 'abonati-' . $app->azi() . '.csv');
    }
}
