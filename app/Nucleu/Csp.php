<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

defined('CAZO') or exit;

/**
 * Constructorul CSP: static, fără nonce și fără 'unsafe-inline', ca HTML-ul paginilor
 * publice să fie identic pentru toți vizitatorii și să se țină în cache.
 * Sursele cu cale se scriu cu originea completă, din site_url.
 */
final class Csp
{
    public const STRIPE_CHECKOUT = 'https://checkout.stripe.com';

    /**
     * @param array{tema_cu_js?:bool, placi_harta?:?string} $optiuni
     *   tema_cu_js: tema activă e oficială și are JS (temele terțe sunt fără JS)
     *   placi_harta: originea serverului de plăci, doar pe paginile cu harta: cazo.harta() pe paginile
     *                publice, „Cum ajungeți” pe pagina rezervării confirmate
     */
    public static function pentru(string $profil, string $origine, array $optiuni = []): string
    {
        $origine = rtrim($origine, '/');
        $https = str_starts_with($origine, 'https://');
        $d = [];
        switch ($profil) {
            case 'public':
            case 'rezervare':
                $script = [$origine . '/_c/'];
                if ($profil === 'public' && ($optiuni['tema_cu_js'] ?? false)) {
                    $script[] = $origine . '/_t/';
                }
                $img = ["'self'", 'data:'];
                if (!empty($optiuni['placi_harta'])) {
                    $img[] = self::origineSigura((string) $optiuni['placi_harta']);
                }
                $d = [
                    'default-src' => "'none'",
                    'script-src' => implode(' ', $script),
                    'style-src' => "'self'",
                    'img-src' => implode(' ', $img),
                    'font-src' => "'self'",
                    'connect-src' => "'self'",
                    'manifest-src' => "'self'",
                    'form-action' => "'self' " . self::STRIPE_CHECKOUT,
                    'frame-ancestors' => "'none'",
                    'base-uri' => "'none'",
                    'object-src' => "'none'",
                ];
                if ($profil === 'rezervare') {
                    $d['require-trusted-types-for'] = "'script'";
                }
                break;
            case 'admin':
                $d = [
                    'default-src' => "'none'",
                    'script-src' => $origine . '/_c/admin/',
                    'style-src' => "'self'",
                    'img-src' => "'self' data:",
                    'font-src' => "'self'",
                    'connect-src' => "'self'",
                    'form-action' => "'self'",
                    'frame-ancestors' => "'none'",
                    'base-uri' => "'none'",
                    'object-src' => "'none'",
                    'require-trusted-types-for' => "'script'",
                ];
                break;
            case 'tehnic':
                // Răspunsuri JSON, text și 204: niciun conținut activ.
                return "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
            case 'svg':
                return "sandbox; default-src 'none'";
            default:
                throw new \InvalidArgumentException('Profil CSP necunoscut: ' . $profil);
        }
        // upgrade-insecure-requests doar pe site-urile HTTPS; pe http://localhost ar rupe activele locale.
        $text = [];
        foreach ($d as $k => $v) {
            $text[] = $k . ' ' . $v;
        }
        if ($https) {
            $text[] = 'upgrade-insecure-requests';
        }
        $text[] = 'report-uri /_csp';
        $text[] = 'report-to csp';
        return implode('; ', $text);
    }

    private static function origineSigura(string $url): string
    {
        if (!preg_match('~^https://[A-Za-z0-9.\-]+(:\d{1,5})?$~', rtrim($url, '/'))) {
            throw new \InvalidArgumentException('Serverul de plăci trebuie să fie o origine https.');
        }
        return rtrim($url, '/');
    }
}
