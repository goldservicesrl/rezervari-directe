<?php

declare(strict_types=1);

namespace Cazo\Securitate;

use Cazo\Baza\Zavor;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * ALTCHA propriu: o dovadă de lucru, fără test cognitiv (WCAG 3.3.8), fără cookie-uri și fără
 * servicii externe. Formatul e cel al protocolului ALTCHA (algorithm, challenge, maxnumber, salt, signature):
 *
 *   salt      = aleator + „?expires=<moment Unix>” (10 minute)
 *   challenge = SHA-256(salt . număr), cu numărul ales aleator între 0 și maxnumber
 *   signature = HMAC-SHA256(challenge), cu subcheia „altcha”
 *
 * Browserul (front/js/comanda.js) caută numărul și trimite înapoi, în câmpul `altcha`, base64(JSON) cu
 * {algorithm, challenge, number, salt, signature}. Verificarea: semnătura, amprenta, termenul și folosirea o
 * singură dată (un zăvor `altcha:<challenge>` cât termenul). Peste PRAG_IP provocări pe oră de la același IP/24,
 * dificultatea crește de zece ori.
 */
final class Altcha
{
    public const CAMP = 'altcha';
    public const DURATA = 600;
    public const MAX_NORMAL = 50_000;
    public const MAX_GREU = 500_000;
    public const PRAG_IP = 20;

    private string $cheie;

    public function __construct(Chei $chei, private Ceas $ceas, private Limitator $limitator, private Zavor $zavor)
    {
        $this->cheie = $chei->subcheie('altcha');
    }

    /** O provocare nouă pentru vizitatorul din rețeaua dată (IP/24). */
    public function provocare(string $ip24): array
    {
        $n = $this->limitator->inregistreaza('altcha:ip:' . $ip24, 3600);
        $max = $n > self::PRAG_IP ? self::MAX_GREU : self::MAX_NORMAL;
        $salt = bin2hex(random_bytes(12)) . '?expires=' . ($this->ceas->acum() + self::DURATA);
        $challenge = hash('sha256', $salt . random_int(0, $max));
        return [
            'algorithm' => 'SHA-256',
            'challenge' => $challenge,
            'maxnumber' => $max,
            'salt' => $salt,
            'signature' => hash_hmac('sha256', $challenge, $this->cheie),
        ];
    }

    /** Răspunsul trimis cu formularul: valid, în termen și folosit acum pentru prima dată. */
    public function verifica(?string $payload): bool
    {
        if ($payload === null || $payload === '' || strlen($payload) > 2000) {
            return false;
        }
        $j = json_decode((string) base64_decode($payload, true), true, 4);
        if (!is_array($j) || ($j['algorithm'] ?? null) !== 'SHA-256' || !is_int($j['number'] ?? null) || $j['number'] < 0 || $j['number'] > self::MAX_GREU
            || !is_string($j['salt'] ?? null) || !is_string($j['challenge'] ?? null) || !is_string($j['signature'] ?? null)
            || !preg_match('~^[0-9a-f]{24}\?expires=(\d{1,12})$~', $j['salt'], $m) || !preg_match('~^[0-9a-f]{64}$~', $j['challenge'])) {
            return false;
        }
        if ((int) $m[1] < $this->ceas->acum() || (int) $m[1] > $this->ceas->acum() + self::DURATA) {
            return false;
        }
        if (!hash_equals(hash_hmac('sha256', $j['challenge'], $this->cheie), $j['signature'])
            || !hash_equals($j['challenge'], hash('sha256', $j['salt'] . $j['number']))) {
            return false;
        }
        // O singură folosire: zăvorul ține cât termenul provocării.
        return $this->zavor->ia('altcha:' . $j['challenge'], self::DURATA + 60) !== null;
    }

    /** Rezolvarea provocării (testele și probele din linia de comandă); browserul face același lucru în JS. */
    public static function rezolva(array $p): string
    {
        for ($n = 0; $n <= (int) $p['maxnumber']; $n++) {
            if (hash('sha256', $p['salt'] . $n) === $p['challenge']) {
                return base64_encode((string) json_encode(['algorithm' => 'SHA-256', 'challenge' => $p['challenge'], 'number' => $n,
                    'salt' => $p['salt'], 'signature' => $p['signature']], JSON_UNESCAPED_SLASHES));
            }
        }
        throw new \RuntimeException('Provocarea ALTCHA fără soluție.');
    }
}
