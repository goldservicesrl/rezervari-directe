<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\I18n\Format;
use Cazo\Legal\BlocLegal;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;
use Cazo\Securitate\SesiuneAdmin;

defined('CAZO') or exit;

/**
 * Modul de pregătire și publicarea site-ului. După instalare, site-ul e vizibil
 * oricui, cu noindex (Aplicatie::noindex, site.publicat), iar administratorul autentificat vede pe paginile publice
 * banda „În pregătire”, cu linkul spre pagina de publicare.
 *
 * O singură pagină: „Publicarea site-ului”, la /admin/site (în meniu, grupul Site). Adresa veche
 * /admin/publicare duce acolo: GET-ul redirecționează, iar POST-ul face aceeași publicare, cu aceleași garduri.
 *
 * Publicarea: un clic doar cu TOATE setările completate (datele legale cerute pe site și datele de vânzare:
 * metodele de plată, orele confirmate în EasyCheck, și termenele de păstrare). Cu oricare dintre ele
 * lipsă, pagina arată ce lipsește, fiecare cu linkul spre locul unde se completează, iar gazda confirmă explicit („Îmi
 * asum publicarea cu aceste date lipsă”); confirmarea rămâne în setarea site.publicare, în jurnal și în jurnalul de
 * audit, cu data și utilizatorul. Alerta rămâne până la completare: banda de pe paginile publice (doar pentru
 * administratorul autentificat) și pagina de publicare arată lipsurile și după publicare.
 * Doar proprietarul publică și retrage; angajatul ajuns aici din bandă vede starea și cine publică.
 */
final class Publicare
{
    /** Câmpurile legale cu avertizarea lor: temeiul, în catalogul panoului (admin.site.avertizare.*). */
    public const LEGALE = BlocLegal::OBLIGATORII;

    /** Pagina unică de publicare. */
    public const CALE = '/admin/site';

    public const MESAJE = ['publicat' => 'admin.site.publicat_mesaj', 'retras' => 'admin.site.retras_mesaj'];

    /** Adresa paginii de publicare pentru banda „În pregătire”, sau null (site publicat ori vizitator obișnuit). */
    public static function bandaPentru(Aplicatie $app, Cerere $c): ?string
    {
        try {
            if ($app->setari()->get('site.publicat', false) === true || !self::administrator($app, $c)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }
        return $app->url()->cale(self::CALE);
    }

    /**
     * Alerta de după publicare: adresa paginii de publicare cât site-ul publicat are setări lipsă, doar pentru
     * administratorul autentificat; altfel null. Dispare singură când setările se completează.
     */
    public static function alertaPentru(Aplicatie $app, Cerere $c): ?string
    {
        try {
            if ($app->setari()->get('site.publicat', false) !== true || !self::administrator($app, $c) || self::complet(self::lipsa($app))) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }
        return $app->url()->cale(self::CALE);
    }

    private static function administrator(Aplicatie $app, Cerere $c): bool
    {
        $jeton = $c->cookie(SesiuneAdmin::COOKIE);
        return $jeton !== null && $app->sesiuneAdmin()->utilizator($jeton) !== null;
    }

    /**
     * Ce lipsește înaintea publicării: datele legale cerute pe site și celelalte setări (`metode`: metodele de
     * plată; `ore`: orele de check-in și check-out neconfirmate în EasyCheck; `pastrare`: termenele de
     * păstrare). Oricare lipsă cere confirmarea gazdei.
     * @return array{legale:list<string>, altele:list<string>}
     */
    public static function lipsa(Aplicatie $app): array
    {
        $legale = (new BlocLegal($app, $app->traducator('ro'), 'ro'))->lipsa();
        $altele = [];
        $configurare = $app->configurareLipsa();
        if (in_array('metode', $configurare, true)) {
            $altele[] = 'metode';
        }
        if (in_array('ore_neconfirmate', $configurare, true)) {
            $altele[] = 'ore';
        }
        if (in_array('anulare_gazda', $configurare, true)) {
            $altele[] = 'anulare_gazda';
        }
        if ($app->gdpr()->termene() === null) {
            $altele[] = 'pastrare';
        }
        return ['legale' => $legale, 'altele' => $altele];
    }

    /** @param array{legale:list<string>, altele:list<string>} $lipsa */
    public static function complet(array $lipsa): bool
    {
        return $lipsa['legale'] === [] && $lipsa['altele'] === [];
    }

    /**
     * Publică site-ul (doar proprietarul; apelantul verifică rolul). Cu setări lipsă, fără bifa de asumare, întoarce
     * false și lasă totul cum era. Asumarea rămâne în `site.publicare`, în jurnal și în jurnalul de audit.
     */
    public static function publica(Aplicatie $app, array $u, bool $asum): bool
    {
        $lipsa = self::lipsa($app);
        $asumat = !self::complet($lipsa);
        if ($asumat && !$asum) {
            return false;
        }
        $uid = (int) $u['id'];
        // Datele cerute înaintea contractului sau o altă setare de vânzare lipsă: se publică doar prezentarea.
        $vanzare = $app->configurareLipsa() !== [];
        $app->setari()->pune('site.publicare', ['la' => $app->ceas()->moment(), 'utilizator' => $uid, 'lipsa' => $lipsa['legale'],
            'lipsa_altele' => $lipsa['altele'], 'asumat' => $asumat, 'vanzare' => $vanzare], $uid);
        $app->setari()->pune('site.publicat', true, $uid);
        $app->jurnal()->info('Site-ul a fost publicat', ['utilizator' => $uid, 'date_legale_lipsa' => implode(',', $lipsa['legale']),
            'setari_lipsa' => implode(',', $lipsa['altele']), 'asumat' => $asumat]);
        $app->auditDetalii(['asumat' => implode(',', $lipsa['legale']), 'setari_lipsa' => implode(',', $lipsa['altele']), 'vanzare_incompleta' => $vanzare]);
        // Antetul noindex intră și în paginile din cache: generația nouă le reface.
        $app->ecOglinda()->schimbaContinutul();
        return true;
    }

    /** Trece site-ul înapoi în pregătire (doar proprietarul; apelantul verifică rolul). */
    public static function retrage(Aplicatie $app, array $u): void
    {
        $app->setari()->pune('site.publicat', false, (int) $u['id']);
        $app->jurnal()->info('Site-ul a revenit în pregătire', ['utilizator' => (int) $u['id']]);
        $app->auditDetalii(['retras' => true]);
        $app->ecOglinda()->schimbaContinutul();
    }

    /**
     * Pagina „Publicarea site-ului” (GET /admin/site). O vede oricine din panou: proprietarul cu butoanele, angajatul
     * (ajuns aici din banda „În pregătire”) doar cu starea și cu rândul „Publicarea o face proprietarul site-ului.”.
     * $eroareAsum: publicarea a venit fără bifa de asumare (422, cu eroarea lângă bifă).
     */
    public static function paginaSite(Aplicatie $app, Cerere $c, bool $eroareAsum = false): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c);
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $r = Raspuns::html('', $eroareAsum ? 422 : 200);
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'site', self::MESAJE);
        $proprietar = ($u['rol'] ?? null) === 'proprietar';
        $lipsa = self::lipsa($app);
        $bloc = new BlocLegal($app, $app->traducator($l), $l);
        $eticheta = static fn (string $k): string => $t->t('admin.legal.camp.' . $k);
        $temeiuri = [];
        foreach ($lipsa['legale'] as $k) {
            $temeiuri[] = ['camp' => $k, 'eticheta' => $eticheta($k), 'text' => $t->t('admin.site.avertizare.' . $k)];
        }
        // Lista scurtă: fiecare lipsă cu linkul spre pagina unde se completează (doar proprietarul ajunge pe acele pagini).
        $lista = [];
        if ($lipsa['legale'] !== []) {
            $lista[] = ['cheie' => 'legale', 'link' => $t->t('admin.site.lipsa.legale'), 'text' => implode(', ', array_map($eticheta, $lipsa['legale'])),
                'url' => $proprietar ? $app->url()->cale('/admin/conexiuni') . '#date-firma' : null];
        }
        foreach (['metode' => '/admin/metode-plata', 'ore' => null, 'anulare_gazda' => '/admin/metode-plata', 'pastrare' => '/admin/gdpr'] as $k => $cale) {
            if (in_array($k, $lipsa['altele'], true)) {
                $lista[] = ['cheie' => $k, 'link' => $t->t('admin.site.lipsa.' . $k), 'text' => $t->t('admin.site.lipsa.' . $k . '_text'),
                    'url' => $proprietar && $cale !== null ? $app->url()->cale($cale) : null];
            }
        }
        if ($eroareAsum) {
            $d['eroare'] = $t->t('admin.site.eroare_confirmare');
        }
        $pub = $app->setari()->get('site.publicare');
        $publicat = $app->setari()->get('site.publicat', false) === true;
        $d += [
            'publicat' => $publicat,
            'publicat_la' => $publicat && is_array($pub) && isset($pub['la']) ? Format::moment((string) $pub['la'], $l) : null,
            'proprietar' => $proprietar,
            'lipsa' => $lista,
            'temeiuri' => $temeiuri,
            // Fără datele cerute înaintea contractului, publicarea deschide doar prezentarea.
            'fara_rezervari' => implode(', ', array_map($eticheta, $bloc->lipsaVanzare())),
            'eroare_asum' => $eroareAsum,
            'url' => ['baza' => $app->url()->cale(self::CALE), 'site' => $app->url()->cale('/')],
        ];
        return AdminPanou::pagina($app, $u, 'site', $d, $r);
    }

    /**
     * POST /admin/site/{publica|retrage} (și, prin `trimite`, POST /admin/publicare): doar proprietarul. Publicarea fără
     * bifa de asumare, cu setări lipsă, întoarce pagina cu eroarea lângă bifă.
     */
    public static function actiune(Aplicatie $app, Cerere $c, string $actiune): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u] = $s;
        if ($actiune === 'retrage') {
            self::retrage($app, $u);
            return AdminPanou::inapoi($app, self::CALE, ['mesaj' => 'retras']);
        }
        if ($actiune === 'publica') {
            if (!self::publica($app, $u, $c->camp('asum') === '1')) {
                return self::paginaSite($app, $c, true);
            }
            return AdminPanou::inapoi($app, self::CALE, ['mesaj' => 'publicat']);
        }
        return AdminPanou::inapoi($app, self::CALE);
    }

    /** GET /admin/publicare (adresa veche): spre pagina unică; fără sesiune, spre intrarea în panou. */
    public static function pagina(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return AdminPanou::sesiune($app, $c) === null ? AdminPanou::inapoi($app) : AdminPanou::inapoi($app, self::CALE);
    }

    /** POST /admin/publicare (adresa veche): aceleași garduri ca pe pagina unică; „publica” sau „pregatire”. */
    public static function trimite(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return self::actiune($app, $c, match ((string) ($c->camp('actiune') ?? '')) {
            'publica' => 'publica',
            'pregatire' => 'retrage',
            default => '',
        });
    }
}
