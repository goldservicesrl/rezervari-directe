<?php

declare(strict_types=1);

namespace Cazo\Rezervare;

use Cazo\Securitate\Chei;
use Cazo\Securitate\Codare;

defined('CAZO') or exit;

/**
 * Legăturile oaspetelui cu comanda lui:
 *
 *  - „Rezervarea mea” (/r/{token}): tokenul de 32 de octeți e HMAC-SHA256 cu subcheia „oaspete” peste referința și
 *    cheia de idempotență a comenzii; în bază stă doar SHA-256 al lui (`token_oaspete_hash`). Fiindcă se derivă din
 *    comandă, orice e-mail (inclusiv o retrimitere a confirmării) îl poate scrie fără ca tokenul să stea în bază.
 *    Valabil până la anonimizarea datelor comenzii.
 *  - cookie-ul comenzii în curs (`__Host-pg_comanda`, strict necesar): leagă pagina de stare (/comanda/{ref}) de
 *    browserul care a trimis comanda: <ref>.<HMAC cu subcheia „comanda”>.
 */
final class LinkOaspete
{
    public const COOKIE = '__Host-pg_comanda';

    private string $cheieOaspete;
    private string $cheieComanda;

    public function __construct(Chei $chei)
    {
        $this->cheieOaspete = $chei->subcheie('oaspete');
        $this->cheieComanda = $chei->subcheie('comanda');
    }

    public function token(string $ref, string $idem): string
    {
        return Codare::b64u(hash_hmac('sha256', $ref . "\0" . $idem, $this->cheieOaspete, true));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function tokenValid(string $token): bool
    {
        return (bool) preg_match('~^[A-Za-z0-9_-]{43}$~', $token);
    }

    public function valoareCookie(string $ref): string
    {
        return $ref . '.' . Codare::b64u(hash_hmac('sha256', 'comanda:' . $ref, $this->cheieComanda, true));
    }

    /** Cookie-ul arată spre comanda $ref? */
    public function cookieValid(?string $valoare, string $ref): bool
    {
        return is_string($valoare) && hash_equals($this->valoareCookie($ref), $valoare);
    }

    public function setCookie(string $ref): string
    {
        return self::COOKIE . '=' . $this->valoareCookie($ref) . '; Path=/; Secure; HttpOnly; SameSite=Lax';
    }

    /** Semnătura totalului afișat în comandă: pagina se reafișează dacă totalul live diferă de cel văzut. */
    public function semneazaTotal(array $sejurCanonic, int $total): string
    {
        return $total . '.' . Codare::b64u(hash_hmac('sha256', 'total:' . $total . ':' . json_encode($sejurCanonic, JSON_UNESCAPED_SLASHES), $this->cheieComanda, true));
    }

    /** Totalul văzut, dacă semnătura e bună pentru acest sejur; altfel null. */
    public function totalVazut(?string $semnat, array $sejurCanonic): ?int
    {
        if ($semnat === null || !preg_match('~^(\d{1,12})\.[A-Za-z0-9_-]{43}$~', $semnat, $m)) {
            return null;
        }
        return hash_equals($this->semneazaTotal($sejurCanonic, (int) $m[1]), $semnat) ? (int) $m[1] : null;
    }
}
