<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Erori;
use Cazo\Nucleu\Raspuns;

defined('CAZO') or exit;

/**
 * Cronul extern, pentru găzduirile fără PHP CLI: `curl -s https://<domeniu>/_cron/<token>`.
 * Tokenul (secretul `cron_token`) se compară cu hash_equals; orice alt token primește aceeași pagină 404
 * ca o adresă inexistentă.
 */
final class Cron
{
    public static function extern(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $asteptat = $app->secrete()->citeste('cron_token');
        if ($asteptat === null || !hash_equals($asteptat, (string) $p['token'])) {
            return Erori::pagina($app->traducator($limba), 404, $app->url()->cale('/', $limba));
        }
        // Cererea poate veni din lanțul cronului, care renunță la așteptare: rularea continuă și fără client.
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        // cererea pe care site-ul și-o face singur (lanțul) e „lant”; doar cronul adăugat de gazdă e „cpanel”.
        $raport = $app->rulator()->ruleaza($c->param('lant') === '1' ? 'lant' : 'cpanel');
        return Raspuns::json(['ok' => true] + $raport);
    }
}
