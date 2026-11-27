<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/**
 * Plasa de siguranță a randării temelor terțe, ultimul strat după Garda: o eroare fatală a PHP
 * (memoria epuizată, timpul de execuție depășit, orice altă eroare fatală) apărută cât randează tema ajunge tot la
 * pagina în _schelet. Garda oprește devreme, cu excepții obișnuite, tot ce știe să măsoare; plasa prinde restul,
 * adică ce ar scăpa unei verificări (o cale nouă de alocare, un filtru nou din Twig, o greșeală a gărzii).
 *
 * armeaza() pune, înaintea randării:
 *  - o funcție de oprire (register_shutdown_function), înregistrată o singură dată pe cerere;
 *  - o rezervă de memorie (REZERVA octeți), eliberată la eroarea fatală, ca pagina de rezervă să aibă loc;
 *  - un tampon de ieșire, ca orice ieșire parțială să rămână în afara răspunsului.
 * dezarmeaza() le scoate după randare (reușită sau cu excepție).
 *
 * La oprirea cu o eroare fatală cât plasa e armată: rezerva se eliberează, plafonul de memorie urcă cu
 * MEMORIE_IN_PLUS peste nivelul de acum (unde găzduirea permite), timpul primește TIMP_IN_PLUS secunde, tampoanele
 * de ieșire se golesc, iar funcția dată la armare servește pagina (Randare: _schelet, alerta, răspunsul complet).
 *
 * REZERVA, MEMORIE_IN_PLUS și TIMP_IN_PLUS sunt alese pentru această versiune.
 */
final class Plasa
{
    public const REZERVA = 4 * 1024 * 1024;
    public const MEMORIE_IN_PLUS = 32 * 1024 * 1024;
    public const TIMP_IN_PLUS = 10;

    /** Erorile după care PHP oprește scriptul. */
    private const FATALE = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    /** @var (\Closure(string, string): void)|null primește motivul (memorie, timp, eroare) și mesajul erorii */
    private static ?\Closure $laFatala = null;
    private static ?string $rezerva = null;
    private static bool $inregistrata = false;
    private static int $nivelIesire = 0;

    /** @param \Closure(string, string): void $laFatala */
    public static function armeaza(\Closure $laFatala): void
    {
        if (!self::$inregistrata) {
            register_shutdown_function([self::class, 'laOprire']);
            self::$inregistrata = true;
        }
        self::$rezerva ??= str_repeat("\0", self::REZERVA);
        self::$laFatala = $laFatala;
        self::$nivelIesire = ob_get_level();
        ob_start();
    }

    public static function dezarmeaza(): void
    {
        if (self::$laFatala === null) {
            return;
        }
        self::$laFatala = null;
        self::$rezerva = null;
        // Randarea scrie într-un șir; tamponul rămâne gol, iar ce ar fi ajuns totuși în el merge mai departe.
        while (ob_get_level() > self::$nivelIesire) {
            ob_end_flush();
        }
    }

    public static function armata(): bool
    {
        return self::$laFatala !== null;
    }

    /** Funcția de oprire: lucrează doar după o eroare fatală apărută cât plasa e armată. */
    public static function laOprire(): void
    {
        $f = self::$laFatala;
        $e = error_get_last();
        if ($f === null || $e === null || ($e['type'] & self::FATALE) === 0) {
            return;
        }
        self::$laFatala = null;
        self::$rezerva = null;
        // Găzduirile pot opri ini_set și set_time_limit (disable_functions); atunci rămâne doar rezerva.
        $limita = Garda::memoriaPermisa();
        if ($limita > 0 && function_exists('ini_set')) {
            @ini_set('memory_limit', (string) max($limita, memory_get_usage() + self::MEMORIE_IN_PLUS));
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::TIMP_IN_PLUS);
        }
        Garda::opreste();
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        try {
            $f(self::motiv((string) $e['message']), (string) $e['message']);
        } catch (\Throwable) {
            // Pagina de rezervă a eșuat și ea: răspunsul rămâne o eroare de server, fără conținut parțial.
            if (!headers_sent()) {
                http_response_code(500);
            }
        }
    }

    /** Motivul erorii fatale, cu numele limitelor din Garda (alerta_tema): memorie, timp sau eroare. */
    public static function motiv(string $mesaj): string
    {
        if (str_starts_with($mesaj, 'Allowed memory size') || str_starts_with($mesaj, 'Out of memory')) {
            return 'memorie';
        }
        if (str_starts_with($mesaj, 'Maximum execution time')) {
            return 'timp';
        }
        return 'eroare';
    }
}
