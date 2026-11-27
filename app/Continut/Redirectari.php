<?php

declare(strict_types=1);

namespace Cazo\Continut;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Redirectările gazdei (M6b, SEO): de la o cale a site-ului spre o cale a site-ului sau o adresă https, 301 sau 302.
 *
 * Regulile: sursa e o cale a site-ului, în afara panoului (`/admin` și `/admin/...`), a căilor tehnice (`/_`) și
 * a paginii de început; destinația e o cale (fără `//` și `/\`, care ar duce pe alt domeniu) sau o adresă https; fără
 * caractere de control sau de direcție Unicode; fără bucle și fără lanțuri (o destinație nu poate fi sursa altei
 * redirectări, iar o sursă nu poate fi destinația alteia). La schimbarea adresei unei pagini, redirectarea 301 de la
 * adresa veche se face singură, iar redirectările spre adresa veche se mută pe cea nouă.
 */
final class Redirectari
{
    public function __construct(private Baza $baza, private Ceas $ceas)
    {
    }

    public static function normalizeaza(string $cale): string
    {
        $cale = trim($cale);
        return rtrim($cale, '/') === '' ? '/' : rtrim($cale, '/');
    }

    /** Validarea formei, fără baza (buclele și lanțurile le verifică `adauga`). */
    public static function formaValida(string $de, string $la): bool
    {
        $ascunse = '~[\x00-\x1F\x7F\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]~u';
        if (preg_match($ascunse, $de . $la) || !mb_check_encoding($de . $la, 'UTF-8')) {
            return false;
        }
        if (!preg_match('~^/[^\s?#\\\\]{1,189}$~u', $de) || $de === '/admin' || str_starts_with($de, '/admin/') || str_starts_with($de, '/_')) {
            return false;
        }
        $cale = preg_match('~^/(?![/\\\\])[^\s\\\\]{0,498}$~u', $la) === 1 && self::caleSigura($la);
        $https = preg_match('~^https://[a-z0-9.\-]+(?::\d{1,5})?(/[^\s\\\\]*)?$~i', $la) === 1;
        return ($cale || $https) && self::normalizeaza($la) !== $de;
    }

    /**
     * O destinație-cale rămâne pe site și după decodare: fiecare formă decodată (până la trei treceri, pentru dubla
     * codificare: %252F) începe cu un singur „/”, fără „\\” (și fără %5C), fără caractere de control sau de direcție.
     */
    public static function caleSigura(string $la): bool
    {
        $x = $la;
        for ($i = 0; $i < 4; $i++) {
            if (!str_starts_with($x, '/') || str_starts_with($x, '//') || str_contains($x, '\\') || stripos($x, '%5c') !== false
                || preg_match('~[\x00-\x1F\x7F\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]~u', $x) || !mb_check_encoding($x, 'UTF-8')) {
                return false;
            }
            $d = rawurldecode($x);
            if ($d === $x) {
                return true;
            }
            $x = $d;
        }
        return false;
    }

    /** Adaugă sau schimbă o redirectare; întoarce false la o formă greșită, o buclă sau un lanț. */
    public function adauga(string $de, string $la, int $cod = 301): bool
    {
        $de = self::normalizeaza($de);
        if (!self::formaValida($de, $la)) {
            return false;
        }
        $laNorm = str_starts_with($la, '/') ? self::normalizeaza($la) : $la;
        // Lanțuri și bucle: destinația nu e sursa altei redirectări, iar sursa nu e destinația alteia.
        if ($this->baza->valoare('SELECT 1 FROM {redirectari} WHERE de = ? AND de <> ?', [$laNorm, $de]) !== null
            || $this->baza->valoare('SELECT 1 FROM {redirectari} WHERE (la = ? OR la = ?) AND de <> ?', [$de, $de . '/', $de]) !== null) {
            return false;
        }
        $this->scrie($de, $la, $cod);
        return true;
    }

    /**
     * Adresa unei pagini s-a schimbat: 301 de la cea veche spre cea nouă; redirectările spre cea veche trec pe cea nouă
     * (fără lanțuri); o redirectare de la adresa nouă (rămasă de dinainte) dispare.
     */
    public function mutare(string $vechi, string $nou): void
    {
        $vechi = self::normalizeaza($vechi);
        $nou = self::normalizeaza($nou);
        if ($vechi === $nou) {
            return;
        }
        $this->baza->tranzactie(function () use ($vechi, $nou): void {
            $this->baza->executa('DELETE FROM {redirectari} WHERE de = ?', [$nou]);
            $this->baza->executa('UPDATE {redirectari} SET la = ? WHERE la = ? OR la = ?', [$nou, $vechi, $vechi . '/']);
            $this->scrie($vechi, $nou, 301);
        });
    }

    /** @return array{la:string, cod:int}|null */
    public function pentru(string $cale): ?array
    {
        $r = $this->baza->rand('SELECT la, cod FROM {redirectari} WHERE de = ?', [mb_substr(self::normalizeaza($cale), 0, 191)]);
        return $r === null ? null : ['la' => (string) $r['la'], 'cod' => (int) $r['cod'] === 302 ? 302 : 301];
    }

    private function scrie(string $de, string $la, int $cod): void
    {
        $this->baza->upsert('redirectari', ['de' => mb_substr($de, 0, 191), 'la' => mb_substr($la, 0, 500), 'cod' => $cod === 302 ? 302 : 301, 'creat_la' => $this->ceas->moment()],
            ['de'], ['la', 'cod']);
    }
}
