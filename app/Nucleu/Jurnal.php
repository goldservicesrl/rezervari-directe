<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

defined('CAZO') or exit;

/**
 * Jurnalul tehnic: jurnale/cazo-AAAA-LL-ZZ.log, o linie pe eveniment, păstrat 14 zile.
 * Maschează automat cheile (ec_, rk_, sk_, pk_, whsec_), e-mailurile, numerele lungi, ultimul octet al IPv4
 * și tot ce trece de /48 la IPv6;
 * erorile poartă id-ul lor, `ref` și `request_id`, gata de copiat pentru suport.
 */
final class Jurnal
{
    public const ZILE_PASTRARE = 14;

    public function __construct(private string $director, private Ceas $ceas)
    {
    }

    public function info(string $mesaj, array $context = []): void
    {
        $this->scrie('INFO', $mesaj, $context);
    }

    public function avertisment(string $mesaj, array $context = []): void
    {
        $this->scrie('AVERT', $mesaj, $context);
    }

    public function eroare(string $mesaj, array $context = []): void
    {
        $this->scrie('EROARE', $mesaj, $context);
    }

    public function scrie(string $nivel, string $mesaj, array $context = []): void
    {
        $linie = gmdate('Y-m-d\TH:i:s\Z', $this->ceas->acum()) . ' ' . $nivel . ' ' . self::mascheaza($mesaj);
        if ($context !== []) {
            $linie .= ' ' . self::mascheaza((string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        $linie = str_replace(["\r", "\n"], ['\\r', '\\n'], $linie) . "\n";
        if (!is_dir($this->director)) {
            @mkdir($this->director, 0700, true);
        }
        $fisier = $this->fisier();
        $nou = !is_file($fisier);
        @file_put_contents($fisier, $linie, FILE_APPEND | LOCK_EX);
        if ($nou) {
            \Cazo\Nucleu\Functii::chmod($fisier, 0600);
        }
    }

    public function fisier(?int $unix = null): string
    {
        return $this->director . '/cazo-' . gmdate('Y-m-d', $unix ?? $this->ceas->acum()) . '.log';
    }

    /** Șterge jurnalele mai vechi de 14 zile. */
    public function curata(): int
    {
        $limita = gmdate('Y-m-d', $this->ceas->acum() - self::ZILE_PASTRARE * 86400);
        $sterse = 0;
        // Și jurnalele scrise înainte de redenumire (pragul-AAAA-LL-ZZ.log).
        foreach (array_merge(glob($this->director . '/cazo-????-??-??.log') ?: [], glob($this->director . '/pragul-????-??-??.log') ?: []) as $f) {
            if (substr(basename($f), -14, 10) < $limita && @unlink($f)) {
                $sterse++;
            }
        }
        return $sterse;
    }

    public static function mascheaza(string $text): string
    {
        // Cheile API și secretele de semnare: prefixul de tip rămâne, restul dispare, inclusiv după alte „_”.
        // Cheile EasyCheck au forma ec_live_<prefix 8>_<secret 32>; prefixul de 8 e public (lista din EasyCheck)
        // și rămâne, ca gazda să recunoască cheia; secretul dispare.
        $text = (string) preg_replace_callback(
            '~(?<![A-Za-z0-9])((?:ec|rk|sk|pk)_(?:live_|test_|proba_)?|whsec_)[A-Za-z0-9_\-]{4,}~',
            static function (array $m): string {
                if (preg_match('~^ec_(live|test)_([0-9A-Za-z]{8})_~', $m[0], $p)) {
                    return 'ec_' . $p[1] . '_' . $p[2] . '_***';
                }
                return $m[1] . '***';
            },
            $text
        );
        // Antetele Authorization cu Bearer.
        $text = (string) preg_replace('~(Bearer\s+)[^\s"\\\\]+~i', '$1***', $text);
        // E-mailurile.
        $text = (string) preg_replace('~[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}~', '***@***', $text);
        // IPv4: ultimul octet.
        $text = (string) preg_replace('~\b(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}\b~', '$1.x', $text);
        // IPv6: rămâne doar rețeaua /48, ca la limite (Ip::trunchiaza). Candidatul stă între caractere
        // care nu pot face parte dintr-o adresă, ca „Clasa::metoda” și orele „10:00:00” să rămână neatinse.
        $text = (string) preg_replace_callback(
            '~(?<![0-9A-Za-z_:.])(?:[0-9A-Fa-f]{0,4}:){2,7}[0-9A-Fa-f]{0,4}(?![0-9A-Za-z_:.])~',
            static fn (array $m): string => preg_match('~[0-9A-Fa-f]~', $m[0]) && filter_var($m[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? \Cazo\Securitate\Ip::trunchiaza($m[0])
                : $m[0],
            $text
        );
        // Numere lungi (telefoane, carduri): 9 cifre sau mai multe, cu separatori obișnuiți.
        return (string) preg_replace('~\+?\d(?:[\s\-.]?\d){8,}~', '***', $text);
    }
}
