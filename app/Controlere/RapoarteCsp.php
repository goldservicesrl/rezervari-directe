<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;
use Cazo\Securitate\Ip;

defined('CAZO') or exit;

/**
 * POST /_csp: rapoartele CSP (report-uri și report-to), agregate pe (directivă, sursă, pagină), fără IP.
 * Limite: 10 pe minut pe IP/24, 100 pe zi pe site. Peste limită, raportul se ignoră; răspunsul rămâne 204.
 */
final class RapoarteCsp
{
    public const CORP_MAXIM = 16384;

    public static function primeste(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $corp = $c->corp();
        if (strlen($corp) > self::CORP_MAXIM) {
            return Raspuns::gol(413);
        }
        $lim = $app->limitator();
        if (!$lim->incearca('csp:ip:' . Ip::trunchiaza($c->ip), 10, 60) || !$lim->incearca('csp:site', 100, 86400)) {
            return Raspuns::gol(204);
        }
        foreach (self::extrage($corp) as $raport) {
            self::inregistreaza($app, $raport);
        }
        return Raspuns::gol(204);
    }

    /**
     * Normalizează ambele formate: {"csp-report": {...}} (report-uri) și [{"type":"csp-violation","body":{...}}] (report-to).
     * @return array<int,array{directiva:string,sursa:string,pagina:string}>
     */
    public static function extrage(string $corp): array
    {
        $date = json_decode($corp, true, 16);
        if (!is_array($date)) {
            return [];
        }
        $brute = [];
        if (isset($date['csp-report']) && is_array($date['csp-report'])) {
            $brute[] = $date['csp-report'];
        } elseif (array_is_list($date)) {
            foreach (array_slice($date, 0, 10) as $e) {
                if (is_array($e) && ($e['type'] ?? '') === 'csp-violation' && is_array($e['body'] ?? null)) {
                    $brute[] = $e['body'];
                }
            }
        }
        $rez = [];
        foreach ($brute as $b) {
            $directiva = (string) ($b['effective-directive'] ?? $b['effectiveDirective'] ?? $b['violated-directive'] ?? '');
            $directiva = strtolower(explode(' ', trim($directiva))[0]);
            if (!preg_match('~^[a-z\-]{1,64}$~', $directiva)) {
                continue;
            }
            $rez[] = [
                'directiva' => $directiva,
                'sursa' => self::sursa((string) ($b['blocked-uri'] ?? $b['blockedURL'] ?? '')),
                'pagina' => self::pagina((string) ($b['document-uri'] ?? $b['documentURL'] ?? '')),
            ];
        }
        return $rez;
    }

    /** Doar originea sursei blocate sau cuvântul-cheie (inline, eval, data...), fără cale și parametri. */
    private static function sursa(string $uri): string
    {
        $uri = trim($uri);
        if ($uri === '' || preg_match('~^[a-z\-]{1,20}$~', $uri)) {
            return $uri === '' ? 'necunoscuta' : $uri;
        }
        $p = parse_url($uri);
        if (!is_array($p) || !isset($p['scheme'])) {
            return 'necunoscuta';
        }
        if (!isset($p['host'])) {
            return mb_substr(strtolower($p['scheme']), 0, 20);
        }
        return mb_substr(strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : ''), 0, 191);
    }

    /** Doar calea paginii; parametrii pot conține tokenuri sau date personale. */
    private static function pagina(string $uri): string
    {
        $cale = parse_url($uri, PHP_URL_PATH);
        if (!is_string($cale) || $cale === '') {
            return '/';
        }
        // Linkurile oaspeților (/r/<token>) și comenzile (/comanda/<ref>) se adună sub un singur rând.
        $cale = preg_replace('~^(/(?:en/)?(?:r|comanda))/[^/]+~', '$1/*', $cale);
        return mb_substr($cale, 0, 191);
    }

    private static function inregistreaza(Aplicatie $app, array $r): void
    {
        $b = $app->baza();
        $acum = $app->ceas()->moment();
        $b->upsert('csp_rapoarte', [
            'cheie' => hash('sha256', $r['directiva'] . "\0" . $r['sursa'] . "\0" . $r['pagina']),
            'directiva' => $r['directiva'],
            'sursa' => $r['sursa'],
            'pagina' => $r['pagina'],
            'numar' => 1,
            'prima_la' => $acum,
            'ultima_la' => $acum,
        ], ['cheie'], ['numar' => ['expr' => $b->dialect()->citeaza('numar') . ' + 1'], 'ultima_la']);
    }
}
