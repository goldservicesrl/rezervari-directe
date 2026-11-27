<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\I18n\Format;
use Cazo\Local\EroareIcal;
use Cazo\Local\Trecere;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;

defined('CAZO') or exit;

/**
 * Asistentul trecerii site-ului de pe EasyCheck pe datele proprii, câte un pas pe ecran, fără JavaScript:
 *
 *  1. Camerele și prețurile: pregătite din oglinda EasyCheck la pornire (`Trecere::incepeAsistent`), schimbate la
 *     Camerele și prețurile (deschisă cât ține asistentul);
 *  2. și 3. Calendarul site-ului pe Booking și pe Airbnb: adresele de export, câte una pe unitate;
 *  4. Calendarele platformelor în site: adresele de import, citite pe loc, prin garda calendarelor;
 *  5. Rezervările din EasyCheck: bifa, parola și trecerea (`Trecere::treciCuAsistentul`, cu anunțul către EasyCheck).
 *
 * „Pasul următor” trece doar cu bifa pusă, verificată pe server; starea stă în setări (`local.trecere.asistent`), deci
 * gazda închide și reia de unde a rămas. Un pas de după cel curent nu se deschide.
 */
final class AdminTrecere
{
    public const ERORI = [
        'bifa' => 'admin.local.as.eroare_bifa', 'ordine' => 'admin.local.as.eroare_ordine', 'reauth' => 'admin.local.eroare.reauth',
        'ocupat' => 'admin.local.as.eroare_ocupat', 'platforma' => 'admin.local.as.eroare_platforma', 'mod' => 'admin.local.eroare.mod',
    ];
    public const MESAJE = ['citit' => 'admin.local.as.calendar_salvat'];

    public static function pagina(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $tr = $app->localTrecere();
        $stare = $tr->asistent();
        if ($app->modLocal() && !isset($stare['gata'])) {
            return AdminPanou::inapoi($app, '/admin/conexiuni');
        }
        if (!$app->modLocal() && !$tr->asistentDeschis()) {
            return AdminPanou::inapoi($app, '/admin/conexiuni');
        }
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'conexiuni', self::MESAJE, self::ERORI);
        $url = static fn (array $q = []): string => $app->url()->cale('/admin/trecere', null, $q);
        $d['url'] = ['actiune' => $app->url()->cale('/admin/trecere/'), 'conexiuni' => $app->url()->cale('/admin/conexiuni'), 'camere' => $app->url()->cale('/admin/camere-site'),
            'calendar' => $app->url()->cale('/admin/calendar'), 'copiaza' => $app->active()->nucleu()['admin/copiaza.js'] ?? ''];
        if ($app->modLocal()) {
            $g = (array) $stare['gata'];
            $titluri = [];
            foreach ((array) ($g['rezultat']['inchise_camere'] ?? []) as $cam) {
                $titluri[] = $app->localDepozit()->camera((string) $cam)['titlu'] ?? (string) $cam;
            }
            $d += ['gata' => true, 'anunt_esuat' => ($g['anunt'] ?? null) === 'esuat', 'sterse' => (int) ($g['sterse']['rezervari'] ?? 0) + (int) ($g['sterse']['cereri'] ?? 0),
                'inchise_camere' => implode(', ', $titluri)];
            return AdminPanou::pagina($app, $u, 'local-trecere', $d, $r);
        }
        $curent = $tr->pasCurent();
        $cerut = (int) ($c->param('pas') ?? $curent);
        $pas = $cerut >= 1 && $cerut <= $curent ? $cerut : $curent;
        if ($cerut > $curent && $d['eroare'] === null) {
            $d['eroare'] = $t->t(self::ERORI['ordine']);
        }
        $facuti = (array) ($stare['facuti'] ?? []);
        $pasi = [];
        for ($i = 1; $i <= Trecere::PASI; $i++) {
            $pasi[] = ['titlu' => 'admin.local.as.pas' . $i, 'facut' => !empty($facuti[(string) $i]), 'curent' => $i === $pas, 'url' => $i <= $curent ? $url(['pas' => $i]) : null];
        }
        $d += ['gata' => false, 'pas' => $pas, 'total_pasi' => Trecere::PASI, 'pasi' => $pasi, 'titlu_pas' => 'admin.local.as.pas' . $pas, 'bifat' => !empty($facuti[(string) $pas]),
            'url_inapoi' => $pas > 1 ? $url(['pas' => $pas - 1]) : null, 'optiuni' => (array) ($stare['optiuni'] ?? []),
            // mesajele rescrise în EasyCheck nu vin pe site; gazda află înainte de trecere.
            'mesaje_rescrise' => $pas === Trecere::PASI && $app->mesajeEc()->rescrise()];
        if ($pas === 1) {
            $d['camere'] = array_map(static fn (array $x): array => ['titlu' => $x['titlu'], 'unitati' => $x['unitati'],
                'pret_noapte' => $x['pret_noapte'] > 0 ? Format::bani($x['pret_noapte'], $l) : '', 'pret_weekend' => $x['pret_weekend'] > 0 ? Format::bani($x['pret_weekend'], $l) : ''],
                $app->localDepozit()->camere());
        } elseif ($pas === 2 || $pas === 3) {
            $d['exporturi'] = AdminLocal::adreseExport($app, $t);
            $d['are_unitati'] = in_array(true, array_map(static fn (array $x): bool => $x['unitate'] !== null, $d['exporturi']), true);
            $d['platforma'] = $pas === 2 ? 'booking' : 'airbnb';
        } elseif ($pas === 4) {
            $la = (string) ($c->param('la') ?? '');
            $cod = (string) ($c->param('ical') ?? '');
            $randuri = [];
            foreach ($app->localDepozit()->camere() as $cam) {
                foreach ($tr->platformeFolosite() as $k => $nume) {
                    $id = $cam['id'] . '-' . $k;
                    $sursa = $tr->sursaPlatforma($cam['id'], $k);
                    $eroare = $la === $id && in_array($cod, EroareIcal::CODURI, true) ? $t->t('admin.local.ical.eroare.' . $cod) : null;
                    $stareText = null;
                    if ($sursa !== null && $sursa['eroare'] !== null) {
                        $eroare ??= in_array($sursa['eroare'], EroareIcal::CODURI, true) ? $t->t('admin.local.ical.eroare.' . $sursa['eroare']) : $t->t('admin.local.ical.eroare.forma');
                    } elseif ($sursa !== null) {
                        $stareText = $sursa['evenimente'] === 1 ? $t->t('admin.local.as.citit_unu') : $t->t('admin.local.as.citit', ['n' => (string) $sursa['evenimente']]);
                    }
                    $randuri[] = ['id' => $id, 'camera' => $cam['id'], 'platforma' => $k, 'eticheta' => $t->t('admin.local.as.camp_calendar', ['platforma' => $nume, 'camera' => $cam['titlu']]),
                        'gazda' => $sursa['gazda'] ?? '', 'stare' => $stareText, 'eroare' => $eroare];
                }
            }
            $d['calendare'] = $randuri;
        }
        return AdminPanou::pagina($app, $u, 'local-trecere', $d, $r);
    }

    public static function actiune(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $tr = $app->localTrecere();
        $la = static fn (array $q = [], string $ancora = ''): Raspuns => Raspuns::redirectare($app->url()->cale('/admin/trecere', null, $q) . $ancora);
        $a = (string) $p['actiune'];
        if ($a === 'incepe') {
            if ($app->modLocal()) {
                return AdminPanou::inapoi($app, '/admin/conexiuni', ['eroare' => 'mod']);
            }
            $st = $tr->incepeAsistent((int) $u['id']);
            $app->auditDetalii(['pas' => $tr->pasCurent(), 'pregatit_la' => (string) ($st['pregatit_la'] ?? '')]);
            return $la();
        }
        if ($a === 'renunta') {
            $tr->renuntaAsistent((int) $u['id']);
            return AdminPanou::inapoi($app, '/admin/conexiuni');
        }
        if ($a === 'pas') {
            $pas = (int) ($c->camp('pas') ?? 0);
            try {
                $tr->bifeaza($pas, is_string($c->camp('bifa')) ? (string) $c->camp('bifa') : null, (int) $u['id']);
            } catch (\DomainException $e) {
                $app->auditDetalii(['pas' => $pas, 'eroare' => $e->getMessage()]);
                return $e->getMessage() === 'asistent' ? AdminPanou::inapoi($app, '/admin/conexiuni') : $la(['pas' => $pas, 'eroare' => $e->getMessage()]);
            }
            $app->auditDetalii(['pas' => $pas]);
            return $la(['pas' => $pas + 1]);
        }
        if ($a === 'calendar') {
            $camera = (string) ($c->camp('camera') ?? '');
            $platforma = (string) ($c->camp('platforma') ?? '');
            $id = $camera . '-' . $platforma;
            if ($tr->pasCurent() < 4) {
                return $la(['eroare' => 'ordine']);
            }
            try {
                $tr->calendarPlatforma($camera, $platforma, trim((string) ($c->camp('url') ?? '')));
            } catch (EroareIcal $e) {
                return $la(['pas' => 4, 'ical' => $e->cod, 'la' => $id], '#c-' . $id);
            } catch (\DomainException $e) {
                return $e->getMessage() === 'asistent' ? AdminPanou::inapoi($app, '/admin/conexiuni') : $la(['pas' => 4, 'eroare' => 'platforma']);
            }
            $app->auditDetalii(['camera' => $camera, 'platforma' => $platforma]);
            return $la(['pas' => 4, 'mesaj' => 'citit'], '#c-' . $id);
        }
        if ($a === 'treci') {
            if ($app->modLocal() && isset($tr->asistent()['gata'])) {
                // A doua apăsare (dublu clic, pagina reîncărcată): trecerea s-a făcut deja.
                return $la();
            }
            if ($c->camp('confirm') !== '1') {
                return $la(['pas' => 5, 'eroare' => 'bifa']);
            }
            if (!AdminPanou::reautentificat($app, $c, $u, $jeton)) {
                return $la(['pas' => 5, 'eroare' => 'reauth']);
            }
            try {
                $rez = $tr->treciCuAsistentul((int) $u['id']);
            } catch (\DomainException $e) {
                return $e->getMessage() === 'asistent' ? AdminPanou::inapoi($app, '/admin/conexiuni') : $la(['pas' => 5, 'eroare' => $e->getMessage()]);
            }
            $app->auditDetalii(['anunt' => $rez['anunt'], 'camere' => $rez['camere'] ?? 0, 'rezervari' => $rez['rezervari'] ?? 0, 'cereri' => $rez['cereri'] ?? 0]);
            $app->modPlata()->evalueaza();
            return $la();
        }
        return $la();
    }
}
