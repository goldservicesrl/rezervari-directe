<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/**
 * Limitele de resurse ale randării temelor. Twig nu are limite proprii, iar o temă terță poate
 * scrie un șablon care se include singur sau dublează un text într-o buclă: memoria s-ar epuiza înaintea oricărei
 * verificări de după randare, cu o eroare fatală pe care PHP nu o poate prinde. Garda oprește devreme, cu o excepție
 * obișnuită, deci Randare servește pagina cu _schelet. E primul strat: o eroare fatală care i-ar scăpa totuși (o cale
 * de alocare încă necunoscută) o prinde Plasa, al doilea strat, tot cu pagina în _schelet.
 *
 * Verificările, puse în șablonul compilat de VizitatorGarda:
 *  - adâncimea șabloanelor (include și extends): cel mult ADANCIME, deci și recursivitatea se oprește;
 *  - pașii buclelor, pe toată randarea: cel mult ITERATII;
 *  - fiecare valoare produsă de un filtru, de `~` sau de `set`: cel mult VALOARE octeți, cel mult ELEMENTE elemente;
 *  - fiecare captură (`{% set %}...{% endset %}`, `{% apply %}`): numărată pe bucăți cât se adună, cel mult VALOARE
 *    octeți (GardaCaptura), deci lipirea finală rămâne mică;
 *  - filtrele care pot multiplica (replace, join, batch) și t() cu parametri: dimensiunea rezultatului se calculează
 *    înainte;
 *  - filtrul sort: forma se mărginește înainte de operația nativă (numai elemente scalare, cel mult ELEMENTE), fiindcă
 *    asort() e o singură operație pe care garda de timp nu o poate întrerupe, iar pe elemente tablou devine O(n^2);
 *  - ieșirea, numărată pe bucăți, cât curge: cel mult IESIRE octeți (Randare);
 *  - timpul (TIMP secunde) și memoria (MEMORIE octeți peste nivelul de la pornire), la fiecare pas, și la compilare
 *    (verifica(), din LexerTema și VizitatorSandbox): bugetul de 3 s cuprinde și compilarea șabloanelor.
 *
 * Valorile numerice (ADANCIME, ITERATII, VALOARE, ELEMENTE, SORT_TABLOURI, MEMORIE și cei 80% din memory_limit) sunt alese
 * pentru această versiune; IESIRE și TIMP vin din spec.
 *
 * În afara unei randări pornite cu porneste(), garda tace (validatorul static compilează fără să randeze).
 */
final class Garda
{
    public const ADANCIME = 12;
    public const ITERATII = 20000;
    public const VALOARE = 256 * 1024;
    public const ELEMENTE = 250000;
    public const SORT_TABLOURI = 128;
    public const IESIRE = 2 * 1024 * 1024;
    public const TIMP = 3.0;
    public const MEMORIE = 64 * 1024 * 1024;

    /** @var array{start:float, timp:float, memorie:int, adancime:int, iteratii:int, iesire:int}|null */
    private static ?array $s = null;

    public static function porneste(float $timp = self::TIMP): void
    {
        $limita = self::memoriaPermisa();
        $baza = memory_get_usage();
        // Plafonul memoriei: MEMORIE peste nivelul de acum, dar cel mult 80% din memory_limit.
        $max = $baza + self::MEMORIE;
        if ($limita > 0) {
            $max = min($max, (int) ($limita * 0.8));
        }
        self::$s = ['start' => microtime(true), 'timp' => $timp, 'memorie' => $max, 'adancime' => 0, 'iteratii' => 0, 'iesire' => 0];
    }

    public static function opreste(): void
    {
        self::$s = null;
    }

    public static function activa(): bool
    {
        return self::$s !== null;
    }

    /** Durata randării de până acum, în secunde (0 în afara unei randări). */
    public static function durata(): float
    {
        return self::$s === null ? 0.0 : microtime(true) - self::$s['start'];
    }

    /** Un pas de buclă. */
    public static function pas(): void
    {
        if (self::$s === null) {
            return;
        }
        if (++self::$s['iteratii'] > self::ITERATII) {
            throw new LimitaDepasita('iteratii', 'Tema depășește ' . self::ITERATII . ' de pași de buclă pe pagină.');
        }
        self::resurse();
    }

    /** Intrarea într-un șablon (pagina, layoutul, o secțiune, un partial). */
    public static function intra(): void
    {
        if (self::$s === null) {
            return;
        }
        if (++self::$s['adancime'] > self::ADANCIME) {
            throw new LimitaDepasita('adancime', 'Șabloanele temei se includ pe mai mult de ' . self::ADANCIME . ' niveluri (o includere recursivă).');
        }
        self::resurse();
    }

    public static function iese(): void
    {
        if (self::$s !== null && self::$s['adancime'] > 0) {
            self::$s['adancime']--;
        }
    }

    /** Verifică o valoare produsă de șablon și o întoarce neschimbată. */
    public static function valoare(mixed $v): mixed
    {
        if (self::$s === null) {
            return $v;
        }
        if (is_string($v) || $v instanceof \Stringable) {
            $n = is_string($v) ? strlen($v) : strlen((string) $v);
            if ($n > self::VALOARE) {
                throw new LimitaDepasita('valoare', 'O valoare din temă are ' . $n . ' octeți (peste ' . self::VALOARE . ').');
            }
        } elseif (is_array($v)) {
            $n = 0;
            if (!self::elemente($v, $n, 0)) {
                throw new LimitaDepasita('elemente', 'O valoare din temă are peste ' . self::ELEMENTE . ' de elemente.');
            }
        }
        self::resurse();
        return $v;
    }

    /**
     * Captura unui corp de șablon (`{% set x %}...{% endset %}`, `{% apply f %}...{% endapply %}`, GardaCaptura):
     * fiecare bucată se numără cu mărimea ei reală, inclusiv un Markup (care în tablou ar costa doar o referință),
     * iar captura se oprește la VALOARE octeți, cât se adună, înaintea lipirii.
     */
    public static function captura(iterable $corp): string
    {
        $bucati = [];
        $n = 0;
        foreach ($corp as $b) {
            $b = (string) $b;
            if (self::$s !== null) {
                $n += strlen($b);
                if ($n > self::VALOARE) {
                    throw new LimitaDepasita('valoare', 'O captură din temă ({% set %} sau {% apply %}) depășește ' . self::VALOARE . ' octeți.');
                }
                self::resurse();
            }
            $bucati[] = $b;
        }
        return implode('', $bucati);
    }

    /** Ieșirea paginii, numărată pe bucăți. */
    public static function iesire(int $octeti): void
    {
        if (self::$s === null) {
            return;
        }
        self::$s['iesire'] += $octeti;
        if (self::$s['iesire'] > self::IESIRE) {
            throw new LimitaDepasita('iesire', 'Ieșirea temei depășește 2 MB.');
        }
        self::resurse();
    }

    // ---- filtrele care pot multiplica, cu dimensiunea verificată înainte ----

    public static function replace(mixed $text, mixed $din): string
    {
        $s = (string) ($text ?? '');
        if (is_iterable($din) && self::$s !== null) {
            $n = strlen($s);
            foreach ($din as $k => $v) {
                $k = (string) $k;
                $v = (string) self::valoare($v);
                if ($k !== '' && strlen($v) > strlen($k)) {
                    $n += substr_count($s, $k) * (strlen($v) - strlen($k));
                }
                if ($n > self::VALOARE) {
                    throw new LimitaDepasita('valoare', 'Filtrul replace ar produce peste ' . self::VALOARE . ' octeți.');
                }
            }
        }
        return \Twig\Extension\CoreExtension::replace($s, $din);
    }

    /**
     * Mărimea lui strtr($text, $inloc), calculată înainte (t() al temei): un text cu {a} repetat de multe ori ar
     * multiplica valoarea parametrului într-un singur pas, înaintea oricărei verificări de după.
     * @param array<string,string> $inloc
     */
    public static function inlocuire(string $text, array $inloc): void
    {
        if (self::$s === null) {
            return;
        }
        $n = strlen($text);
        foreach ($inloc as $k => $v) {
            if ($k !== '' && strlen($v) > strlen($k)) {
                $n += substr_count($text, $k) * (strlen($v) - strlen($k));
            }
            if ($n > self::VALOARE) {
                throw new LimitaDepasita('valoare', 'Textul t() ar produce peste ' . self::VALOARE . ' octeți.');
            }
        }
    }

    public static function join(mixed $lista, mixed $lipici = '', mixed $si = null): string
    {
        if (self::$s !== null && is_iterable($lista)) {
            $n = 0;
            $i = 0;
            $pas = strlen((string) $lipici) + strlen((string) $si);
            foreach ($lista as $x) {
                if (++$i > self::ELEMENTE) {
                    throw new LimitaDepasita('elemente', 'Filtrul join primește peste ' . self::ELEMENTE . ' de elemente.');
                }
                $n += (is_scalar($x) || $x instanceof \Stringable ? strlen((string) $x) : 5) + $pas;
                if ($n > self::VALOARE) {
                    throw new LimitaDepasita('valoare', 'Filtrul join ar produce peste ' . self::VALOARE . ' octeți.');
                }
            }
        }
        return \Twig\Extension\CoreExtension::join($lista, (string) $lipici, $si);
    }

    public static function batch(mixed $lista, mixed $marime, mixed $umplutura = null, mixed $chei = true): array
    {
        $m = (int) ceil((float) $marime);
        if (self::$s !== null && ($m < 1 || $m > self::ITERATII)) {
            throw new LimitaDepasita('elemente', 'Filtrul batch cere grupe între 1 și ' . self::ITERATII . '.');
        }
        return \Twig\Extension\CoreExtension::batch($lista, $m, $umplutura, (bool) $chei);
    }

    /**
     * Filtrul sort fără săgeată (în temă săgeata e refuzată la compilare): Twig cheamă asort() cu SORT_REGULAR, o
     * singură operație nativă pe care garda de timp nu o poate întrerupe — nu are punct de control înăuntru. Pe
     * elemente scalare comparația costă O(1) și PHP are o cale rapidă pe multe valori egale, deci asort rămâne rapid
     * chiar la ELEMENTE elemente. Pe elemente tablou (de pildă dintr-un batch(1)), fiecare comparație compară tablouri,
     * iar pe multe elemente egale asort devine O(n^2): un split("") ieftin umflă un șir mărginit în sute de mii de
     * tablouri și sortarea arde un nucleu de procesor minute întregi (DoS). De aceea forma se mărginește înainte de
     * operația nativă, nu după rezultat: o listă cu orice element netscalar se sortează doar până la SORT_TABLOURI
     * elemente (unde asort rămâne O(SORT_TABLOURI x ELEMENTE), sub bugetul de 3 s), iar peste atât se oprește cu
     * „forma”; o listă pur scalară trece până la ELEMENTE. Un Stringable (de pildă Markup) se aduce la șir, ca asort
     * să compare șiruri, nu obiecte.
     */
    public static function sort(mixed $lista): array
    {
        if ($lista instanceof \Traversable) {
            $lista = iterator_to_array($lista);
        } elseif (!is_array($lista)) {
            throw new \Twig\Error\RuntimeError(sprintf('Filtrul sort cere o secvență sau o mapare, a primit „%s”.', get_debug_type($lista)));
        }
        if (self::$s !== null) {
            $i = 0;
            $tablouri = false;
            foreach ($lista as $cheie => $x) {
                ++$i;
                if ($x === null || is_scalar($x)) {
                    // scalar: comparație O(1)
                } elseif ($x instanceof \Stringable) {
                    $lista[$cheie] = (string) $x;
                } else {
                    $tablouri = true;
                }
                $plafon = $tablouri ? self::SORT_TABLOURI : self::ELEMENTE;
                if ($i > $plafon) {
                    throw new LimitaDepasita(
                        $tablouri ? 'forma' : 'elemente',
                        $tablouri
                            ? 'Filtrul sort primește peste ' . self::SORT_TABLOURI . ' de elemente netscalare (sortarea lor ar fi pătratică).'
                            : 'Filtrul sort primește peste ' . self::ELEMENTE . ' de elemente.'
                    );
                }
            }
            self::resurse();
        }
        asort($lista);
        return $lista;
    }

    /**
     * Timpul și memoria, în afara unui pas al șablonului: la compilarea temei (LexerTema, după fiecare șablon citit,
     * și VizitatorSandbox, la fiecare nod), care rulează înaintea codului compilat, deci înaintea celorlalte verificări.
     */
    public static function verifica(): void
    {
        if (self::$s !== null) {
            self::resurse();
        }
    }

    // ----

    private static function resurse(): void
    {
        $s = self::$s;
        if (microtime(true) - $s['start'] > $s['timp']) {
            throw new LimitaDepasita('timp', 'Randarea temei depășește ' . $s['timp'] . ' s.');
        }
        if (memory_get_usage() > $s['memorie']) {
            throw new LimitaDepasita('memorie', 'Randarea temei depășește plafonul de memorie.');
        }
    }

    /** Numără elementele, cu ieșire la plafon (o structură cu referințe comune poate fi uriașă logic). */
    private static function elemente(array $v, int &$n, int $nivel): bool
    {
        if ($nivel > 64) {
            return false;
        }
        foreach ($v as $x) {
            if (++$n > self::ELEMENTE) {
                return false;
            }
            if (is_array($x) && !self::elemente($x, $n, $nivel + 1)) {
                return false;
            }
        }
        return true;
    }

    /** memory_limit în octeți (0: fără plafon). */
    public static function memoriaPermisa(): int
    {
        $l = trim((string) ini_get('memory_limit'));
        if ($l === '' || $l === '-1') {
            return 0;
        }
        $n = (int) $l;
        return match (strtolower(substr($l, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
