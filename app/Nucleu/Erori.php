<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

use Cazo\I18n\Traducator;

defined('CAZO') or exit;

/**
 * Paginile de eroare: fără detalii tehnice, cu id-ul erorii (500) pentru suport.
 * Detaliile ajung doar în jurnal, sub același id.
 */
final class Erori
{
    public const CODURI = [400, 403, 404, 405, 429, 500, 503];

    public static function id(): string
    {
        return 'E-' . strtoupper(bin2hex(random_bytes(4)));
    }

    public static function pagina(Traducator $t, int $status, string $acasa, ?string $id = null): Raspuns
    {
        $cod = in_array($status, self::CODURI, true) ? $status : 500;
        $chei = (string) $cod;
        $paragrafe = [$t->t('eroare.' . $chei . '.text', ['id' => $id ?? ''])];
        $html = Html::pagina($t, $t->t('eroare.' . $chei . '.titlu'), $paragrafe, [
            'noindex' => true,
            'link' => [$acasa, $t->t('eroare.link_acasa')],
        ]);
        return Raspuns::html($html, $status);
    }
}
