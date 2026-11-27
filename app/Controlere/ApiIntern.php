<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;
use Cazo\Pret\Sejur;
use Cazo\Securitate\Ip;
use Cazo\Teme\Componente;

defined('CAZO') or exit;

/**
 * API-ul intern al componentelor nucleului, citit de scripturile din _c/:
 *
 *   GET /_api/calendar?camera=&luna=&adulti=   două luni din oglindă (starea, prețul pe noapte, sejurul minim),
 *                                              Cache-Control: public, max-age=60; fără apel spre EasyCheck
 *   GET /_api/pret?camera=&sosire=&plecare=&adulti=&copii=&plan=  (sau lista camere[i][...])
 *                                              totalul exact, prin `totaluri`, GET /total sau MotorLocal,
 *                                              cu limitele bugetului API și ale protecției la abuz
 */
final class ApiIntern
{
    public static function calendar(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $limba = in_array($c->query['limba'] ?? null, $app->config()->limbi(), true) ? (string) $c->query['limba'] : $limba;
        $camera = (string) ($c->query['camera'] ?? '');
        $luna = (string) ($c->query['luna'] ?? substr($app->azi(), 0, 7));
        $adulti = (string) ($c->query['adulti'] ?? '2');
        if (!preg_match('~^cam_[0-9a-f]{16}$~', $camera) || !preg_match('~^(\d{4})-(0[1-9]|1[0-2])$~', $luna) || !preg_match('~^\d{1,2}$~', $adulti)
            || (int) $adulti < 1 || (int) $adulti > Componente::OASPETI_MAX) {
            return Raspuns::json(['eroare' => 'parametri'], 400);
        }
        // Fereastra oglinzii: de ieri, 366 de nopți; lunile din afara ei nu au date.
        $azi = $app->azi();
        if ($luna < substr($azi, 0, 7) || $luna > gmdate('Y-m', strtotime($azi . ' 00:00:00 UTC') + 365 * 86400)) {
            return Raspuns::json(['eroare' => 'luna'], 400);
        }
        $m = $app->modelVedere($limba);
        if ($m->cameraDupaId($camera) === null) {
            return Raspuns::json(['eroare' => 'camera'], 404);
        }
        $comp = new Componente($app, $m, $app->traducator($limba), $limba, null, Ip::trunchiaza($c->ip));
        $comp->ocupateAcum = Componente::ocupateDin($c->query['ocupate'] ?? null);
        $luni = $comp->luni($camera, $luna, (int) $adulti);
        $r = Raspuns::json(['camera' => $camera, 'luna' => $luna, 'azi' => $azi, 'luni' => $luni]);
        $r->antet('Cache-Control', 'public, max-age=60');
        return $r;
    }

    public static function pret(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $limba = in_array($c->query['limba'] ?? null, $app->config()->limbi(), true) ? (string) $c->query['limba'] : $limba;
        $s = Sejur::dinQuery($c->query);
        if ($s === null) {
            return Raspuns::json(['eroare' => 'parametri'], 400);
        }
        $m = $app->modelVedere($limba);
        foreach ($s->camere as $x) {
            if ($m->cameraDupaId($x['camera']) === null) {
                return Raspuns::json(['eroare' => 'camera'], 404);
            }
        }
        $comp = new Componente($app, $m, $app->traducator($limba), $limba, null, Ip::trunchiaza($c->ip));
        // Fără plan în cerere: tarifele pentru datele alese, câte unul pe politică de anulare și masă;
        // cu plan, totalul acelui plan.
        return Raspuns::json($s->planCamera(0) === null ? $comp->oferte($s) : $comp->rezultatTotal($s));
    }
}
