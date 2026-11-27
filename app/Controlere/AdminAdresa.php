<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;
use Cazo\Sistem\Adresa;

defined('CAZO') or exit;

/**
 * Setări › Legătura cu EasyCheck › „Adresa site-ului”: adresa de acum și, cu cheie, trecerea
 * pe subdomeniul gratuit cazo.ro rezervat pentru cheie în EasyCheck › Site web.
 *
 *   GET  /admin/conexiuni                        blocul „adresa” al paginii (AdminCompus)
 *   POST /admin/adresa/{actiune}                 treci | verifica | renunta (doar proprietarul)
 *   GET  /.well-known/cazo-adresa                sonda adresei noi: jetonul trecerii în curs (text), altfel 404
 */
final class AdminAdresa
{
    public const MESAJE = ['adresa_pornita' => 'admin.adresa.mesaj_pornita', 'adresa_trecut' => 'admin.adresa.mesaj_trecut', 'adresa_asteapta' => 'admin.adresa.mesaj_asteapta',
        'adresa_renuntat' => 'admin.adresa.mesaj_renuntat'];
    public const ERORI = ['adresa_nerezervat' => 'admin.adresa.eroare_nerezervat', 'adresa_mod' => 'admin.adresa.eroare_mod'];

    public static function bloc(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $r = Raspuns::html('');
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'conexiuni', self::MESAJE, self::ERORI);
        $a = $app->adresa();
        $curenta = $a->curenta();
        $gazda = Adresa::gazda($curenta);
        $tin = $a->tinuta();
        $trecere = $a->trecere();
        $rez = $a->cuCheie() ? $a->rezervat() : null;
        $d += [
            'adresa' => $curenta,
            'cu_cheie' => $a->cuCheie(),
            'pe_cazo' => Adresa::numeCazo($gazda) !== null,
            'rezervare' => $rez,
            'pe_rezervat' => ($rez['stare'] ?? null) === 'rezervat' && $rez['domeniu'] === $gazda,
            'trecere' => $trecere,
            'automat' => $a->cpanel()->disponibil() && $a->dosarRelativ() !== null,
            'dosar' => $a->dosarRelativ() ?? $app->config()->calePublic(),
            'veche' => is_string($tin['de_la'] ?? null) && ($tin['trecut_la'] ?? null) !== null ? $tin['de_la'] : null,
            'url_ec' => Adresa::EASYCHECK_SITE_WEB,
            'url' => ['adresa' => $app->url()->cale('/admin/adresa/')],
        ];
        return AdminPanou::pagina($app, $u, 'adresa', $d, $r);
    }

    public static function actiune(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u] = $s;
        $inapoi = static fn (array $q): Raspuns => AdminCompus::spre($app, '/admin/conexiuni', 'adresa', $q, 303);
        $a = $app->adresa();
        if (!$a->cuCheie()) {
            return $inapoi(['eroare' => 'adresa_mod']);
        }
        switch ((string) $p['actiune']) {
            case 'treci':
                $rez = $a->rezervat();
                if (($rez['stare'] ?? null) !== 'rezervat' || $rez['domeniu'] === Adresa::gazda($a->curenta())) {
                    return $inapoi(['eroare' => 'adresa_nerezervat']);
                }
                $x = $a->pornesteTrecerea($rez['domeniu'], (int) $u['id']);
                $app->auditDetalii(['adresa' => $rez['domeniu'], 'cpanel' => $x['cpanel']]);
                // Domeniul adăugat acum în cPanel poate răspunde pe loc (un certificat deja valabil): o primă verificare.
                if (in_array($x['cpanel'], ['adaugat', 'exista'], true) && $a->verifica() === 'trecut') {
                    return $inapoi(['mesaj' => 'adresa_trecut']);
                }
                return $inapoi(['mesaj' => 'adresa_pornita']);
            case 'verifica':
                return $inapoi(['mesaj' => $a->verifica() === 'trecut' ? 'adresa_trecut' : 'adresa_asteapta']);
            default:
                $a->anuleaza();
                $app->auditDetalii(['adresa' => 'renuntat']);
                return $inapoi(['mesaj' => 'adresa_renuntat']);
        }
    }

    /** Sonda adresei noi: răspunde doar cu jetonul trecerii în curs, ca site-ul să se recunoască pe adresa nouă. */
    public static function sonda(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $j = $app->adresa()->jetonSonda();
        if ($j === null) {
            return Raspuns::text('Not Found', 404)->antet('Cache-Control', 'no-store');
        }
        return Raspuns::text($j)->antet('Cache-Control', 'no-store');
    }
}
