<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

defined('CAZO') or exit;

/**
 * Antetele de securitate, puse din PHP la fiecare răspuns, după profilul rutei.
 *
 *   public     paginile randate de temă; cache public 60 s la răspunsurile 200
 *   rezervare  comanda, starea, „Rezervarea mea”, întoarcerea de la plată: no-store, Trusted Types, doar JS de nucleu
 *   admin      panoul și instalatorul: no-store, Trusted Types, COOP
 *   tehnic     puncte fără HTML (cron, rapoarte CSP, anunțuri, API intern)
 *   svg        SVG-urile temelor
 */
final class Antete
{
    public const PROFILURI = ['public', 'rezervare', 'admin', 'tehnic', 'svg'];

    public const PERMISSIONS = 'camera=(), microphone=(), geolocation=(), payment=(), usb=()';

    public const CACHE_PUBLIC = 'public, max-age=60, stale-while-revalidate=300';

    /**
     * @param array{tema_cu_js?:bool, placi_harta?:?string, hsts?:bool, noindex?:bool, metoda?:string} $optiuni
     */
    public static function aplica(Raspuns $r, string $profil, string $origine, array $optiuni = []): Raspuns
    {
        $r->antetImplicit('Content-Security-Policy', Csp::pentru($profil, $origine, $optiuni));
        if (in_array($profil, ['public', 'rezervare', 'admin'], true)) {
            $r->antet('Reporting-Endpoints', 'csp="' . rtrim($origine, '/') . '/_csp"');
        }
        $r->antet('X-Content-Type-Options', 'nosniff');
        // „Rezervarea mea” poartă tokenul în adresă și își pune singură no-referrer.
        $r->antetImplicit('Referrer-Policy', 'strict-origin-when-cross-origin');
        $r->antet('Permissions-Policy', self::PERMISSIONS);
        $r->antet('X-Frame-Options', 'DENY');
        // HSTS doar după ce HTTPS a fost verificat; includeSubDomains și preload doar la alegerea gazdei.
        if (($optiuni['hsts'] ?? false) && str_starts_with($origine, 'https://')) {
            $r->antet('Strict-Transport-Security', 'max-age=31536000');
        }
        $metoda = $optiuni['metoda'] ?? 'GET';
        if ($profil === 'public' && $r->status === 200 && ($metoda === 'GET' || $metoda === 'HEAD')) {
            $r->antetImplicit('Cache-Control', self::CACHE_PUBLIC);
        } elseif ($profil === 'tehnic' && $r->status === 200 && ($metoda === 'GET' || $metoda === 'HEAD') && $r->areAntet('Cache-Control')) {
            // API-ul intern public (/_api/calendar) își pune singur durata în cache; restul punctelor tehnice: no-store.
        } else {
            $r->antet('Cache-Control', 'no-store');
        }
        if ($profil === 'rezervare' || $profil === 'admin') {
            $r->antet('X-LiteSpeed-Cache-Control', 'no-cache');
        }
        if ($profil === 'admin') {
            $r->antet('Cross-Origin-Opener-Policy', 'same-origin');
        }
        if ($optiuni['noindex'] ?? false) {
            $r->antet('X-Robots-Tag', 'noindex');
        }
        return $r;
    }
}
