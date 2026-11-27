<?php

declare(strict_types=1);

// Aserțiunile rulatorului: egal, adevarat, arunca, contine_html, potriveste_json, plus câteva ajutătoare.

defined('CAZO') or exit;

final class EsecTest extends \Exception
{
}

function esec(string $mesaj): never
{
    throw new EsecTest($mesaj);
}

function descrie(mixed $v): string
{
    return is_string($v) ? '"' . (strlen($v) > 300 ? substr($v, 0, 300) . '...' : $v) . '"' : var_export($v, true);
}

function egal(mixed $asteptat, mixed $obtinut, string $mesaj = ''): void
{
    if ($asteptat !== $obtinut) {
        esec(($mesaj !== '' ? $mesaj . ': ' : '') . 'așteptat ' . descrie($asteptat) . ', obținut ' . descrie($obtinut));
    }
}

function adevarat(mixed $v, string $mesaj = 'condiția e falsă'): void
{
    if ($v !== true) {
        esec($mesaj . ' (obținut ' . descrie($v) . ')');
    }
}

function fals(mixed $v, string $mesaj = 'condiția e adevărată'): void
{
    if ($v !== false) {
        esec($mesaj . ' (obținut ' . descrie($v) . ')');
    }
}

/** Verifică faptul că $f aruncă o excepție de clasa dată, opțional cu un fragment în mesaj. */
function arunca(callable $f, string $clasa = \Throwable::class, ?string $fragment = null): \Throwable
{
    try {
        $f();
    } catch (EsecTest $e) {
        throw $e;
    } catch (\Throwable $e) {
        if (!$e instanceof $clasa) {
            esec('așteptat ' . $clasa . ', aruncat ' . get_class($e) . ': ' . $e->getMessage());
        }
        if ($fragment !== null && !str_contains($e->getMessage(), $fragment)) {
            esec('mesajul excepției nu conține "' . $fragment . '": ' . $e->getMessage());
        }
        return $e;
    }
    esec('așteptat ' . $clasa . ', nu s-a aruncat nimic');
}

function contine(string $text, string $fragment, string $mesaj = ''): void
{
    if (!str_contains($text, $fragment)) {
        esec(($mesaj !== '' ? $mesaj . ': ' : '') . 'lipsește ' . descrie($fragment) . ' din ' . descrie($text));
    }
}

function lipseste(string $text, string $fragment, string $mesaj = ''): void
{
    if (str_contains($text, $fragment)) {
        esec(($mesaj !== '' ? $mesaj . ': ' : '') . 'apare ' . descrie($fragment) . ' în ' . descrie($text));
    }
}

/** Fragmentul HTML apare în document (spațiile albe dintre etichete se ignoră). */
function contine_html(string $html, string $fragment): void
{
    $norm = static fn (string $s): string => trim((string) preg_replace('~>\s+<~', '><', preg_replace('~\s+~', ' ', $s)));
    if (!str_contains($norm($html), $norm($fragment))) {
        esec('HTML-ul nu conține ' . descrie($fragment));
    }
}

/** JSON-ul conține (recursiv) toate cheile și valorile din $asteptat. */
function potriveste_json(string $json, array $asteptat, string $cale = '$'): void
{
    $date = json_decode($json, true);
    if (!is_array($date)) {
        esec('JSON invalid: ' . descrie($json));
    }
    potriveste_tablou($date, $asteptat, $cale);
}

function potriveste_tablou(array $date, array $asteptat, string $cale = '$'): void
{
    foreach ($asteptat as $k => $v) {
        if (!array_key_exists($k, $date)) {
            esec("lipsește $cale.$k");
        }
        if (is_array($v)) {
            if (!is_array($date[$k])) {
                esec("$cale.$k nu e tablou");
            }
            potriveste_tablou($date[$k], $v, "$cale.$k");
        } elseif ($date[$k] !== $v) {
            esec("$cale.$k: așteptat " . descrie($v) . ', obținut ' . descrie($date[$k]));
        }
    }
}
