<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\I18n\Format;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;

defined('CAZO') or exit;

/**
 * Blocul Actualizări din Setări › Întreținere: versiunea instalată și, când cazo.ro anunță una mai nouă, anunțul
 * „Există o versiune nouă”, cu data, notele și legătura de descărcare. Softul nu descarcă și nu instalează nimic:
 * gazda urcă singură versiunea nouă, după ghidul gazdei. Pagina e doar de citit, pentru oricine intră în panou.
 */
final class Actualizari
{
    public static function pagina(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $ses = AdminPanou::sesiune($app, $c);
        if ($ses === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $ses;
        $l = (string) $u['limba_panou'];
        $r = Raspuns::html('');
        $v = $app->versiuneNoua();
        $stare = $v->stare();
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'actualizari');
        $d = [
            'instalata' => Aplicatie::versiune(),
            'noua' => self::noua($app, $l, false),
            'la_zi' => $stare['verificat_la'] !== null,
            'verificat_la' => $stare['verificat_la'] !== null ? Format::moment($stare['verificat_la'], $l) : '',
            'versiuni' => $v->paginaVersiuni(),
        ] + $d;
        return AdminPanou::pagina($app, $u, 'actualizari', $d, $r);
    }

    /**
     * Versiunea nouă gata de arătat în panou (Acasă și blocul Actualizări): notele în limba panoului, data formatată,
     * legătura spre pașii de instalare doar pe Acasă. Null când site-ul are cea mai nouă versiune.
     */
    public static function noua(Aplicatie $app, string $limba, bool $cuPasi): ?array
    {
        $v = $app->versiuneNoua()->noua(Aplicatie::versiune());
        if ($v === null) {
            return null;
        }
        $note = $v['note'][$limba === 'ro' ? 'ro' : 'en'] !== '' ? $v['note'][$limba === 'ro' ? 'ro' : 'en'] : $v['note']['ro'];
        return ['versiune' => $v['versiune'], 'data' => preg_match('~^\d{4}-\d{2}-\d{2}$~', $v['data']) ? Format::zi($v['data'], $limba) : '',
            'note' => $note, 'url' => $v['url'], 'securitate' => $v['securitate'],
            'pasi' => $cuPasi ? $app->url()->cale('/admin/actualizari') : null];
    }
}
