<?php

declare(strict_types=1);

// Migrarea 0024 (verificarea M6b; 0018 pe ramură, înainte de aducerea la zi cu main): cheia unică pe (fel, limba, versiune) în `texte_legale`, ca două salvări simultane să
// nu facă aceeași versiune de două ori. Dublurile de dinainte (aceeași versiune scrisă de două ori) se renumerotează întâi:
// pe fiecare pagină și limbă, versiunile devin 1, 2, 3... în ordinea scrierii (id), iar versiunea activă rămâne aceeași
// rând. Acordurile țin amprenta paginii întregi, nu numărul de aici, deci renumerotarea nu le atinge.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Versiunile textelor legale unice pe pagină și limbă',
    'ruleaza' => static function (Schema $s): void {
        $b = $s->baza();
        if ($b->valoare('SELECT 1 FROM {texte_legale} GROUP BY fel, limba, versiune HAVING COUNT(*) > 1 LIMIT 1') !== null) {
            foreach ($b->toate('SELECT DISTINCT fel, limba FROM {texte_legale}') as $g) {
                $v = 0;
                foreach ($b->toate('SELECT id FROM {texte_legale} WHERE fel = ? AND limba = ? ORDER BY versiune, id', [$g['fel'], $g['limba']]) as $r) {
                    $b->executa('UPDATE {texte_legale} SET versiune = ? WHERE id = ?', [++$v, $r['id']]);
                }
            }
        }
        $s->unic('texte_legale', 'ux_texte_legale_versiune', ['fel', 'limba', 'versiune']);
    },
];
