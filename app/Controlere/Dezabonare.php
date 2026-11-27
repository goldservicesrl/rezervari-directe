<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Erori;
use Cazo\Nucleu\Raspuns;

defined('CAZO') or exit;

/**
 * Dezabonarea de la oferte, fără cont: linkul semnat din fiecare ofertă (Oferte\Abonati::link).
 *
 *   GET  /dezabonare/{token}   pagina cu adresa și butonul de confirmare (GET-ul lasă totul cum e: programele de e-mail
 *                              deschid singure linkurile, ca să le verifice)
 *   POST /dezabonare/{token}   dezabonarea; o trimite și programul de e-mail, la un clic, din `List-Unsubscribe-Post`
 *                              (RFC 8058), deci fără sesiune și fără CSRF: o autentifică jetonul semnat al adresei
 */
final class Dezabonare
{
    public static function pagina(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        return self::raspuns($app, $c, $p, $limba, false);
    }

    public static function confirma(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $email = $app->abonati()->dinJeton((string) $p['token']);
        if ($email !== null) {
            $app->abonati()->dezaboneaza($email, 'oaspete', false);
            // Spre EasyCheck după răspuns, ca oaspetele să-și vadă confirmarea pe loc.
            $app->dupaRaspuns(static function () use ($app): void {
                try {
                    $app->abonati()->sarcina();
                } catch (\Throwable $e) {
                    $app->jurnal()->avertisment('Dezabonarea spre EasyCheck', ['eroare' => mb_substr($e->getMessage(), 0, 200)]);
                }
            });
        }
        return self::raspuns($app, $c, $p, $limba, true);
    }

    private static function raspuns(Aplicatie $app, Cerere $c, array $p, string $limba, bool $gata): Raspuns
    {
        $t = $app->traducator($limba);
        $email = $app->abonati()->dinJeton((string) $p['token']);
        if ($email === null) {
            return Erori::pagina($t, 404, $app->url()->cale('/', $limba));
        }
        $nume = (string) ($app->modelVedere($limba)->proprietate()['nume'] ?? '');
        $html = Prezentare::nucleu($app, $limba, 'dezabonare', ['gata' => $gata, 'email' => $email, 'nume' => $nume,
            'actiune' => $app->url()->cale('/dezabonare/' . $p['token'], $limba), 'acasa' => $app->url()->cale('/', $limba)]);
        $alternative = [];
        foreach ($app->config()->limbi() as $l) {
            $alternative[$l] = $app->url()->absolut('/dezabonare/' . $p['token'], $l);
        }
        // Jetonul e în adresă: fără Referer spre alte site-uri.
        return Prezentare::paginaRezervare($app, $c, $limba, $t->t('oferte.dezabonare.titlu') . ($nume !== '' ? ' | ' . $nume : ''), $html, $alternative, null, 200, [],
            ['Referrer-Policy' => 'no-referrer']);
    }
}
