<?php

declare(strict_types=1);

// Migrarea 0055: temele Castel și cristal, Cheie, Curte cu leagăn, Deltă, Izvor și Pridvor ies din program.
// Un site care folosea una dintre ele trece pe tema rămasă cea mai apropiată (castel și izvor → faleza, cheie →
// bulevard, curte, delta și pridvor → brad), iar `tema.scoasa` ține minte trecerea pentru nota de pe Acasă. Setările
// temei vechi nu se transferă: rândurile lor din `teme_setari` se șterg, la fel „Reveniți” spre o temă scoasă. O temă
// terță instalată cu același slug (după scoatere, slugul e din nou liber) rămâne neatinsă. Numărul lasă loc migrărilor
// ramurilor care se unesc mai târziu.

use Cazo\Baza\Schema;

defined('CAZO') or exit;

return [
    'descriere' => 'Temele scoase din program: site-ul trece pe tema rămasă cea mai apropiată',
    'ruleaza' => static function (Schema $s): void {
        $b = $s->baza();
        $harta = ['castel' => 'faleza', 'cheie' => 'bulevard', 'curte' => 'brad', 'delta' => 'brad', 'izvor' => 'faleza', 'pridvor' => 'brad'];
        $terta = static fn (string $slug): bool => $b->valoare("SELECT slug FROM {teme} WHERE slug = ? AND sursa = 'terta'", [$slug]) !== null;
        $setare = static function (string $cheie) use ($b): mixed {
            $v = $b->valoare('SELECT valoare FROM {setari} WHERE cheie = ?', [$cheie]);
            return is_string($v) ? json_decode($v, true) : null;
        };
        $scrie = static function (string $cheie, mixed $valoare) use ($b): void {
            $json = json_encode($valoare, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $acum = gmdate('Y-m-d H:i:s');
            if ($b->valoare('SELECT cheie FROM {setari} WHERE cheie = ?', [$cheie]) !== null) {
                $b->executa('UPDATE {setari} SET valoare = ?, actualizat_la = ?, actualizat_de = NULL WHERE cheie = ?', [$json, $acum, $cheie]);
            } else {
                $b->insereaza('setari', ['cheie' => $cheie, 'valoare' => $json, 'actualizat_la' => $acum, 'actualizat_de' => null]);
            }
        };
        $activa = $setare('tema.activa');
        if (is_string($activa) && isset($harta[$activa]) && !$terta($activa)) {
            $scrie('tema.activa', $harta[$activa]);
            $scrie('tema.scoasa', ['veche' => $activa, 'noua' => $harta[$activa]]);
            $scrie('tema.versiune', (int) $setare('tema.versiune') + 1);
        }
        $ant = $setare('tema.anterioara');
        $slugAnt = is_string($ant) ? explode('@', $ant, 2)[0] : '';
        if (isset($harta[$slugAnt]) && !$terta($slugAnt)) {
            $b->executa("DELETE FROM {setari} WHERE cheie = 'tema.anterioara'");
        }
        foreach (array_keys($harta) as $slug) {
            if (!$terta($slug)) {
                $b->executa('DELETE FROM {teme_setari} WHERE tema = ?', [$slug]);
            }
        }
    },
];
