<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

defined('CAZO') or exit;

/**
 * Comportamentul pentru fiecare `eroare.cod` al API-ului v1 (erorile se tratează după cod).
 * Testul de contract verifică faptul că fiecare cod din openapi-v1.json are aici un comportament.
 *
 *  reincearca  trecătoare: aceeași cerere mai târziu (scrierile cu aceeași Idempotency-Key și același corp),
 *              după Retry-After când există
 *  licenta     cheia nu mai merge pe această proprietate: Licenta oprește rezervările, prezentarea rămâne
 *  refuz       EasyCheck a refuzat cererea înainte de scriere: comanda trece pe ramura de refuz (cu
 *              rambursarea automată la plata online)
 *  idempotenta 409 idempotency_conflict: cu `in_lucru`, reluare după Retry-After; fără, eroare de program
 *  negasit     resursa lipsește (webhookul neînregistrat, o cerere ștearsă după 30 de zile)
 *  program     cererea Cazo e greșită: alertă tehnică în panou, fără reluare automată
 */
final class Erori
{
    public const COMPORTAMENT = [
        'cerere_invalida' => 'program',
        'cheie_in_adresa' => 'program',
        'json_invalid' => 'program',
        'idempotency_lipsa' => 'program',
        'doar_server' => 'program',
        'metoda' => 'program',
        'corp_prea_mare' => 'program',
        'tip_continut' => 'program',
        'cheie_lipsa' => 'licenta',
        'cheie_invalida' => 'licenta',
        'scop_insuficient' => 'licenta',
        'functie_indisponibila' => 'licenta',
        'cont_inactiv' => 'licenta',
        'proprietate_inactiva' => 'licenta',
        'versiune_retrasa' => 'licenta',
        'negasit' => 'negasit',
        'indisponibil' => 'refuz',
        'stare_invalida' => 'refuz',
        'validare' => 'refuz',
        'restrictie' => 'refuz',
        'pret_diferit' => 'refuz',
        'plan_lipsa' => 'refuz',
        'avans_insuficient' => 'refuz',
        'inlocuieste_invalid' => 'refuz',
        'plan_diferit' => 'refuz',
        // 403 la ștergerea datelor oaspetelui: rezervarea e a altei chei; panoul trimite gazda în
        // EasyCheck, iar cheia rămâne bună.
        'rezervare_straina' => 'refuz',
        'idempotency_conflict' => 'idempotenta',
        'prea_multe_cereri' => 'reincearca',
        'eroare_interna' => 'reincearca',
        'suprasolicitat' => 'reincearca',
        'curs_indisponibil' => 'reincearca',
        // Subdomeniul gratuit al site-ului, cerut din EasyCheck (pagina Site web / API): numele e luat sau există deja.
        'subdomeniu_ocupat' => 'refuz',
        'subdomeniu_existent' => 'refuz',
    ];

    /** Comportamentul unui răspuns cu eroare: după cod, iar fără cod după starea HTTP. */
    public static function comportament(RaspunsEc $r): string
    {
        $cod = $r->codEroare();
        if ($cod !== null && isset(self::COMPORTAMENT[$cod])) {
            return self::COMPORTAMENT[$cod];
        }
        return match (true) {
            $r->status === 401, $r->status === 403, $r->status === 410 => 'licenta',
            $r->status === 404 => 'negasit',
            $r->status === 409 => 'idempotenta',
            $r->status === 422 => 'refuz',
            $r->status === 429, $r->status >= 500 => 'reincearca',
            default => 'program',
        };
    }
}
