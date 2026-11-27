<?php

declare(strict_types=1);

namespace Cazo\Legal;

defined('CAZO') or exit;

/**
 * Adresa publică a proprietății: pe paginile publice apar strada, numărul, blocul,
 * localitatea și județul; scara, etajul și apartamentul (și interfonul) ajung la oaspete doar după rezervare
 * (e-mailul de confirmare, „Rezervarea mea”, „Cum ajungeți”).
 *
 * API-ul EasyCheck dă adresa pe trei câmpuri (`adresa.strada`, `adresa.localitate`, `adresa.judet`), iar `strada`
 * e un singur text scris de gazdă („Str. Siderurgiștilor nr. 2, Bl. I1, Et. 7, Ap. 178”). Părțile se scot după
 * cuvântul care le anunță, urmat de valoarea lui: „Sc.”, „Scara”, „Et.”, „Etaj”, „Etajul”, „Ap.”, „Apt.”,
 * „Apart.”, „Apartament”, „Apartamentul”, „Interfon”, plus „parter”, „mansardă” și „demisol” scrise singure.
 * Cuvântul trebuie să fie întreg („Str. Scării 4”, „Aleea Etajului 2” și „Bd. Apelor 3” rămân neatinse).
 */
final class Adresa
{
    private const CUVINTE = '(?:apartamentul|apartament|apart|apt|ap|etajul|etaj|et|scara|sc|interfonul|interfon)';

    private const VALOARE = '(?:parter|mansard[ăa]|demisol|[\p{L}]?\d{1,4}[\p{L}]?(?:\s?[\-\/]\s?[\p{L}\d]{1,4})?|[\p{L}]{1,2}(?![\p{L}]))';

    /** Strada fără scară, etaj, apartament și interfon. */
    public static function publica(string $strada): string
    {
        // Cuvântul întreg (fără literă înainte și după), cu punct, două puncte sau „nr.” opționale, apoi valoarea.
        $s = (string) preg_replace('~(?<![\p{L}\d])' . self::CUVINTE . '(?![\p{L}])\s*[.:]?\s*(?:nr\.?\s*)?' . self::VALOARE . '(?![\p{L}\d])~iu', '', $strada);
        // Nivelul scris singur, ca parte a adresei („..., parter, ...”).
        $s = (string) preg_replace('~(?:^|(?<=[,;]))\s*(?:parter|mansard[ăa]|demisol)\s*(?=[,;]|$)~iu', '', $s);
        // Curățenia: virgulele rămase una lângă alta, spațiile, semnele de la capete.
        $s = (string) preg_replace('~\s+~u', ' ', $s);
        $s = (string) preg_replace('~\s*([,;])(?:\s*[,;])+~u', '$1', $s);
        $s = (string) preg_replace('~\s+([,;])~u', '$1', $s);
        return trim($s, " ,;-\t\n");
    }

    /**
     * Adresa într-un singur text, din câmpurile `adresa` ale proprietății: publică (fără scară, etaj, apartament)
     * sau completă (după rezervare).
     */
    public static function text(array $adresa, bool $completa = false): string
    {
        $strada = trim((string) ($adresa['strada'] ?? ''));
        if (!$completa) {
            $strada = self::publica($strada);
        }
        return implode(', ', array_filter([$strada, trim((string) ($adresa['localitate'] ?? '')), trim((string) ($adresa['judet'] ?? ''))], static fn (string $x): bool => $x !== ''));
    }
}
