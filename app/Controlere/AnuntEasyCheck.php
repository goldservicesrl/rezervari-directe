<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\EasyCheck\Anunturi;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;
use Cazo\Securitate\Ip;

defined('CAZO') or exit;

/**
 * POST /easycheck-anunt: corpul brut de cel mult 16 KB, semnătura, apoi 120 de anunțuri semnate
 * pe minut, proprietatea, deduplicarea. Răspunsul pleacă imediat (204, sau 400 fără detalii), iar sincronizarea
 * rulează după închiderea conexiunii, sub zăvorul `sincronizare`, cu buget de 15 s; ce rămâne preia cronul.
 *
 * Auditul de securitate.2026: semnătura (un HMAC, ieftin) se verifică înaintea limitei globale, ca cererile
 * false să nu consume limita anunțurilor adevărate. Cererile refuzate au limita lor, pe rețea (IP/24, /48 la IPv6).
 */
final class AnuntEasyCheck
{
    public const LIMITA_MINUT = 120;
    /** Cererile refuzate (corpul prea mare, semnătura greșită) pe minut, de pe aceeași rețea. */
    public const LIMITA_REFUZATE_IP = 30;

    public static function primeste(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        // site-ul de sine stătător ascultă doar de el; anunțurile rămase de la cheia de dinainte primesc 204.
        if ($app->modLocal()) {
            return Raspuns::gol(204);
        }
        $corp = $c->corp();
        if (strlen($corp) > Anunturi::CORP_MAXIM) {
            return self::refuzat($app, $c, 'ec_anunt_refuzat', 'Anunț EasyCheck refuzat', 'corp_prea_mare');
        }
        $semnatura = $c->antet('easycheck-semnatura');
        if (!$app->ecAnunturi()->semnaturaValida($corp, $semnatura)) {
            return self::refuzat($app, $c, 'ec_anunt_refuzat', 'Anunț EasyCheck refuzat', 'semnatura');
        }
        if (!$app->limitator()->incearca('ec_anunt:site', self::LIMITA_MINUT, 60)) {
            return Raspuns::gol(429)->antet('Retry-After', '60');
        }
        $rez = $app->ecAnunturi()->primeste($corp, $semnatura);
        if ($rez !== 'primit' && $rez !== 'duplicat') {
            $app->jurnal()->avertisment('Anunț EasyCheck refuzat', ['motiv' => $rez]);
            return Raspuns::gol(400);
        }
        if ($rez === 'primit') {
            $app->dupaRaspuns(static function () use ($app): void {
                $app->ruleazaInCaleaAnuntului();
            });
        }
        return Raspuns::gol(204);
    }

    /**
     * O cerere refuzată înaintea limitei globale (auditul de securitate.2026): se numără pe rețea, cu limita ei;
     * peste limită, 429 fără o linie nouă în jurnal. Folosită și de POST /stripe-anunt.
     */
    public static function refuzat(Aplicatie $app, Cerere $c, string $cheie, string $mesaj, string $motiv): Raspuns
    {
        if (!$app->limitator()->incearca($cheie . ':ip:' . Ip::trunchiaza($c->ip), self::LIMITA_REFUZATE_IP, 60)) {
            return Raspuns::gol(429)->antet('Retry-After', '60');
        }
        $app->jurnal()->avertisment($mesaj, ['motiv' => $motiv]);
        return Raspuns::gol(400);
    }
}
