<?php

declare(strict_types=1);

namespace Cazo\Dezvoltare;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Teme\ProbaTema;
use Cazo\Teme\Tema;
use Cazo\Teme\Validator;

defined('CAZO') or exit;

/**
 * Routerul serverului `cazo-tema previzualizeaza` (php -S): site-ul de probă din fixturi, creat la prima cerere,
 * cu tema din dosarul autorului citită din nou la fiecare cerere (o modificare se vede la reîncărcarea paginii).
 * Activele publicate (_c, _t, _s) le servește direct php -S; o eroare a temei apare în pagină, cu motivul.
 */
final class SiteProba
{
    public static function serveste(string $dosar, string $lucru, string $varianta): bool
    {
        $cale = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (preg_match('~^/_(c|t|s)/~', $cale) && is_file($lucru . '/site/public' . $cale)) {
            return false;
        }
        $m = json_decode((string) file_get_contents($dosar . '/theme.json'), true);
        $tema = Tema::din($dosar, Aplicatie::radacina() . '/teme/_schelet', false, Validator::slugDin((string) ($m['slug'] ?? 'tema')));
        $app = is_file($lucru . '/site/privat/proba.sqlite') ? self::deschide($lucru . '/site', $tema) : ProbaTema::site($lucru . '/site', $varianta, $tema);
        $r = $app->trateaza(Cerere::dinGlobale());
        if ($r->status >= 500) {
            $r = \Cazo\Nucleu\Raspuns::html('<!doctype html><meta charset="utf-8"><title>Eroare</title><pre>' . htmlspecialchars(ProbaTema::ultimaEroare($app)) . '</pre>', 500);
        }
        $r->trimite(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
        return true;
    }

    private static function deschide(string $dir, Tema $tema): Aplicatie
    {
        $cfg = new \Cazo\Nucleu\Config([
            'id_instalare' => 'proba-tema', 'site_url' => 'https://proba.cazo.test', 'limba_implicita' => 'ro', 'limbi' => ['ro', 'en'], 'rescriere' => true,
            'cale_public' => $dir . '/public', 'baza' => ['driver' => 'sqlite', 'cale' => $dir . '/privat/proba.sqlite', 'prefix' => 'pg_'],
        ], $dir . '/privat');
        $app = new Aplicatie($cfg, \Cazo\Securitate\Chei::dinFisier($dir . '/privat/cheie.bin'));
        $app->temaFixa = $tema;
        $app->randare()->faraRezerva = true;
        return $app;
    }
}
