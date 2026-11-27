<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

use Cazo\Nucleu\Aplicatie;

defined('CAZO') or exit;

/**
 * Modurile panoului față de etapa 3 din „EasyCheck întâi”. Principiul operatorului: cât
 * site-ul e legat cu cheie, deciziile despre bani se iau în EasyCheck; site-ul fără cheie își păstrează toate deciziile în
 * panou. Butoanele de decizie se ascund (și se refuză pe server) doar în E și P; în L și V panoul rămâne cel de dinainte.
 *
 *   L  fără cheie (`site.mod = local`)
 *   V  cu cheie, funcția neaprinsă: un EasyCheck vechi, `functii.comenzi` lipsă sau false, `stare_easycheck => false`,
 *      cheia refuzată, programul n-a declarat încă (niciun `PUT /site` ajuns pentru cheia de acum)
 *   E  cu cheie live, funcția aprinsă
 *   P  cu cheie de probă, funcția aprinsă
 *
 * „Aprinsă”: programul a declarat capabilitățile (ultimul `PUT /site` al cheii de acum a ajuns), iar ultimul răspuns
 * (`PUT /site`, altfel `GET /eu`) are `functii.comenzi` = true. O valoare aflată rămâne până la un răspuns care spune
 * altceva: o pauză a EasyCheck (5xx, rețeaua) nu întoarce panoul la purtarea veche.
 */
final class ModEc
{
    public const LOCAL = 'L';
    public const VECHI = 'V';
    public const EASYCHECK = 'E';
    public const PROBA = 'P';

    public function __construct(private Aplicatie $app)
    {
    }

    public function mod(): string
    {
        if ($this->app->modLocal()) {
            return self::LOCAL;
        }
        if (!$this->comenziAprinse()) {
            return self::VECHI;
        }
        return $this->app->ecLicenta()->stare()['proba'] ? self::PROBA : self::EASYCHECK;
    }

    /** Funcția etapei 3 e aprinsă pentru acest site (modurile E și P). */
    public function comenziAprinse(): bool
    {
        try {
            $s = $this->app->stareSite();
            if ($this->app->modLocal() || !$s->pornit() || $this->app->secrete()->citeste('ec_cheie') === null || !$this->app->ecLicenta()->stare()['valida']) {
                return false;
            }
            return $s->declarata() && (($s->functii() ?? [])['comenzi'] ?? null) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Deciziile despre bani stau în EasyCheck (E, P): butoanele panoului se ascund și se refuză. */
    public function deciziiInEasyCheck(): bool
    {
        return in_array($this->mod(), [self::EASYCHECK, self::PROBA], true);
    }

    /**
     * Mesajele către oaspeți se cer tot din EasyCheck (comanda `mesaj`): modurile E și P, cu `functii.mesaje`
     * aprinsă. „Retrimiteți confirmarea” din panou se ascunde și se refuză atunci.
     */
    public function mesajeInEasyCheck(): bool
    {
        return $this->deciziiInEasyCheck() && $this->app->mesajeEc()->activa();
    }

    /** Originea EasyCheck-ului legat (schema, gazda, portul), din adresa API-ului; altfel easycheck.ro. */
    public function origine(): string
    {
        $p = parse_url((string) ($this->app->config()->easycheck()['baza'] ?? ''));
        return is_array($p) && isset($p['scheme'], $p['host']) ? $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') : 'https://easycheck.ro';
    }

    /** „Deschideți EasyCheck” de pe Acasă: panoul gazdei. */
    public function urlAcasa(): string
    {
        return $this->origine() . '/host/';
    }

    /**
     * „Deschideți în EasyCheck” pe o rezervare (etapa 5): adresa stabilă `host/rezervare-site.php?rez=` cu id-ul
     * rezervării din EasyCheck (pachetul 6a). Rezervările de probă (modul P) și cele fără rezervare acolo duc la lista
     * „Rezervări” (`host/cereri-directe.php`): în EasyCheck, o rezervare de probă nu e în contul gazdei.
     */
    public function urlRezervare(array $comanda): string
    {
        $rez = (string) ($comanda['ec_rezervare'] ?? '');
        if ($this->mod() === self::PROBA || (int) ($comanda['proba'] ?? 0) === 1 || !preg_match('~^rez_[0-9A-Za-z]{24}$~D', $rez)) {
            return $this->origine() . '/host/cereri-directe.php';
        }
        return $this->origine() . '/host/rezervare-site.php?rez=' . rawurlencode($rez);
    }
}
