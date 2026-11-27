<?php

declare(strict_types=1);

namespace Cazo\Joburi;

use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Pseudo-cronul (sursa 2): după răspunsul la o cerere publică, cel mult o dată la 60 s.
 * Pragul de timp stă în data de modificare a unui fișier, ca cererile obișnuite să nu scrie în bază.
 * Exclusivitatea rămâne la zăvorul `cron` din Rulator.
 */
final class PseudoCron
{
    public const INTERVAL = 60;

    public function __construct(private string $fisier, private Ceas $ceas)
    {
    }

    /** Doar verificarea, fără marcaj (cache-ul de pagini o face înainte de a trimite răspunsul). */
    public static function scadent(string $fisier, int $acum): bool
    {
        clearstatcache(true, $fisier);
        $ultima = @filemtime($fisier);
        return $ultima === false || $acum - $ultima >= self::INTERVAL;
    }

    /** Lucru rămas (pozele de la prima sincronizare): următoarea vizită rulează pseudo-cronul fără așteptarea intervalului. */
    public function grabeste(): void
    {
        if (is_file($this->fisier)) {
            @touch($this->fisier, $this->ceas->acum() - self::INTERVAL);
        }
    }

    /** Spune dacă cererea curentă rulează pseudo-cronul și, dacă da, marchează momentul. */
    public function eRandul(): bool
    {
        $acum = $this->ceas->acum();
        if (!self::scadent($this->fisier, $acum)) {
            return false;
        }
        $dir = dirname($this->fisier);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        // Două cereri simultane pot trece amândouă de verificare; zăvorul `cron` lasă una singură să lucreze.
        return @touch($this->fisier, $acum);
    }
}
