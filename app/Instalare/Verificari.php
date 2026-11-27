<?php

declare(strict_types=1);

namespace Cazo\Instalare;

defined('CAZO') or exit;

/**
 * Verificările din pasul 2: PHP, extensiile, limitele PHP, HTTPS, TLS spre EasyCheck și Stripe,
 * rescrierea adreselor (cererea de probă la /__cazo_test) și dosarele scriibile. Fiecare problemă are explicația
 * și textul gata de trimis firmei de găzduire (cataloagele instalatorului: instalare.verificari.<id>.*); recomandarea
 * sodium are doar explicația (`gazduire` fals). Lista arătată gazdei se încheie cu rândurile locului datelor,
 * ale sondei și ale cheii de criptare, adăugate de instalator.
 *
 * Blocante: PHP, extensiile obligatorii, HTTPS (în afară de localhost), TLS spre EasyCheck, scrierea.
 * Avertismente: extensia sodium (fără ea, criptarea și semnăturile merg prin biblioteca vendorizată
 * paragonie/sodium_compat, mai încet), memoria sub 128 MB, timpul sub 30 s, încărcarea sub 8 MB (cea mai mică dintre
 * upload_max_filesize și post_max_size), TLS spre Stripe (cerut doar de
 * plata cu cardul), rescrierea (site-ul merge și cu adrese /?r=/cale).
 */
final class Verificari
{
    public const PHP_MINIM = '8.2.0';
    /** Extensiile obligatorii. `sodium` e doar recomandată: fără ea se încarcă biblioteca vendorizată (app/incarca.php). */
    public const EXTENSII = ['curl', 'openssl', 'mbstring', 'json', 'zip', 'gd'];
    public const MEMORIE_MINIMA = 128 * 1024 * 1024;
    public const TIMP_MINIM = 30;
    public const INCARCARE_MINIMA = 8 * 1024 * 1024;
    public const TLS = ['easycheck' => 'https://easycheck.ro/api/v1/eu', 'stripe' => 'https://api.stripe.com/v1/account'];
    public const MARCAJ_RESCRIERE = 'cazo-rescriere-ok';
    /** Verificările care au doar explicația, fără textul pentru firma de găzduire. */
    public const FARA_TEXT_GAZDUIRE = ['sodium'];

    /**
     * @param array{php?:string, extensii?:list<string>, ini?:array<string,string>, https?:bool, gazda?:string,
     *   scriere?:array<string,bool>} $mediu valorile reale, citite din PHP când lipsesc (testele le dau direct)
     * @param \Closure(string): array{status:?int, corp:string, eroare:?string} $http
     * @return array{ok:bool, rescriere:bool, elemente:list<array{id:string, ok:bool, blocant:bool, valoare:string, gazduire:bool}>}
     */
    public static function ruleaza(string $origine, array $mediu, \Closure $http): array
    {
        $php = $mediu['php'] ?? PHP_VERSION;
        $extensii = $mediu['extensii'] ?? array_map('strtolower', get_loaded_extensions());
        $ini = ($mediu['ini'] ?? []) + [
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
        ];
        $gazda = $mediu['gazda'] ?? (string) parse_url($origine, PHP_URL_HOST);
        $https = $mediu['https'] ?? str_starts_with($origine, 'https://');
        $el = [];
        $adauga = static function (string $id, bool $ok, bool $blocant, string $valoare = '') use (&$el): void {
            $el[] = ['id' => $id, 'ok' => $ok, 'blocant' => $blocant, 'valoare' => $valoare, 'gazduire' => !in_array($id, self::FARA_TEXT_GAZDUIRE, true)];
        };

        $adauga('php', version_compare($php, self::PHP_MINIM, '>='), true, $php);
        $lipsa = array_values(array_diff(self::EXTENSII, $extensii));
        if (!in_array('pdo_mysql', $extensii, true) && !in_array('pdo_sqlite', $extensii, true)) {
            $lipsa[] = 'pdo_mysql / pdo_sqlite';
        }
        $adauga('extensii', $lipsa === [], true, implode(', ', $lipsa));
        $adauga('sodium', in_array('sodium', $extensii, true), false);
        $mem = self::octeti($ini['memory_limit']);
        $adauga('memorie', $mem < 0 || $mem >= self::MEMORIE_MINIMA, false, $ini['memory_limit']);
        $timp = (int) $ini['max_execution_time'];
        $adauga('timp', $timp === 0 || $timp >= self::TIMP_MINIM, false, (string) $timp);
        [$incarcare, $afisat] = self::limitaIncarcare($ini['upload_max_filesize'], $ini['post_max_size']);
        $adauga('incarcare', $incarcare < 0 || $incarcare >= self::INCARCARE_MINIMA, false, $afisat);
        $adauga('https', $https || self::local($gazda), !self::local($gazda), $origine);
        foreach (self::TLS as $id => $url) {
            $r = $http($url);
            $adauga('tls_' . $id, $r['status'] !== null, $id === 'easycheck', (string) ($r['eroare'] ?? $r['status'] ?? ''));
        }
        $r = $http(rtrim($origine, '/') . '/__cazo_test');
        $rescriere = $r['status'] === 200 && trim($r['corp']) === self::MARCAJ_RESCRIERE;
        $adauga('rescriere', $rescriere, false);
        $scriere = $mediu['scriere'] ?? [];
        $nescriibile = array_keys(array_filter($scriere, static fn (bool $ok): bool => !$ok));
        $adauga('scriere', $nescriibile === [], true, implode(', ', $nescriibile));

        $ok = true;
        foreach ($el as $e) {
            if ($e['blocant'] && !$e['ok']) {
                $ok = false;
            }
        }
        return ['ok' => $ok, 'rescriere' => $rescriere, 'elemente' => $el];
    }

    /**
     * Limita reală a unei încărcări: cea mai mică dintre upload_max_filesize și post_max_size (formularul întreg
     * trece prin post_max_size). post_max_size 0 înseamnă fără limită.
     * @return array{0:int, 1:string} octeții (-1 = fără limită) și valoarea afișată gazdei
     */
    public static function limitaIncarcare(string $upload, string $post): array
    {
        $u = self::octeti($upload);
        $p = trim($post) === '0' ? -1 : self::octeti($post);
        if ($p >= 0 && ($u < 0 || $p < $u)) {
            return [$p, trim($post)];
        }
        return [$u, trim($upload)];
    }

    public static function local(string $gazda): bool
    {
        return in_array(strtolower(trim($gazda, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    /** „128M”, „1G”, „-1” => octeți (-1 = fără limită). */
    public static function octeti(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
