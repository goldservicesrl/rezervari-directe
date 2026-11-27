<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

defined('CAZO') or exit;

/**
 * Închiderea conexiunii după răspuns, ca lucrul de după (pseudo-cron, anunțuri) să nu-l țină pe vizitator.
 * PHP-FPM: fastcgi_finish_request(); LiteSpeed: litespeed_finish_request(); altfel răspunsul are deja
 * Content-Length, se adaugă Connection: close și se golesc bufferele.
 */
final class Inchidere
{
    /** Antetul de pus înainte de trimitere, când serverul nu are o funcție de închidere. */
    public static function pregateste(Raspuns $r): void
    {
        if (!function_exists('fastcgi_finish_request') && !function_exists('litespeed_finish_request')) {
            $r->antet('Connection', 'close');
        }
    }

    public static function inchide(): void
    {
        // Găzduirile pot opri ignore_user_abort (disable_functions); lucrul de după răspuns merge și fără ea.
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
            return;
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }
}
