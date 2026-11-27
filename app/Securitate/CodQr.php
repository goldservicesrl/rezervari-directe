<?php

declare(strict_types=1);

namespace Cazo\Securitate;

use InvalidArgumentException;

defined('CAZO') or exit;

/**
 * Generator de coduri QR (ISO/IEC 18004, modelul 2), propriu, fără biblioteci și fără extensia gd: text → matrice → SVG.
 * Făcut pentru adresa `otpauth://` a verificării în doi pași, care nu are voie să plece spre un serviciu extern.
 *
 * Acoperă: modul octet (orice șir de octeți, deci și UTF-8), versiunile 1-20 (21×21 până la 97×97 module), nivelurile
 * de corecție L, M, Q, H, Reed-Solomon pe GF(256) cu polinomul 0x11D, împărțirea pe blocuri cu intercalare, tiparele
 * de aliniere, informația de versiune (de la versiunea 7), cele 8 măști cu alegerea după cele 4 reguli de penalizare
 * și informația de format (BCH 15,5). Se alege cea mai mică versiune în care încape textul.
 *
 * Coordonatele sunt (x = coloana, y = rândul), cu originea în colțul din stânga sus; matricea e listă de rânduri.
 */
final class CodQr
{
    public const VERSIUNE_MAX = 20;

    /** Biții nivelului de corecție din informația de format. */
    private const NIVEL_BITI = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    /** Cuvinte de corecție pe bloc, pe nivel, pentru versiunile 1-20. */
    private const CORECTIE_PE_BLOC = [
        'L' => [7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28],
        'M' => [10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26],
        'Q' => [13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30],
        'H' => [17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28],
    ];

    /** Numărul de blocuri, pe nivel, pentru versiunile 1-20. */
    private const BLOCURI = [
        'L' => [1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8],
        'M' => [1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16],
        'Q' => [1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20],
        'H' => [1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25],
    ];

    /**
     * Matricea codului: listă de rânduri, fiecare o listă de bool (true = modul întunecat), fără zona liberă.
     *
     * @return list<list<bool>>
     */
    public static function matrice(string $text, string $nivel = 'M'): array
    {
        return self::construieste($text, $nivel)['matrice'];
    }

    /**
     * Codul ca un singur element `<svg>`: un dreptunghi alb de fundal și o singură cale neagră cu toate modulele.
     * Fără stiluri inline și fără scripturi (CSP-ul panoului nu le lasă); mărimea o dă pagina, prin CSS.
     * `$margine` e zona liberă, în module (standardul cere 4). `$titlu`, dacă e dat, ajunge în `<title>`.
     */
    public static function svg(string $text, string $nivel = 'M', int $margine = 4, ?string $titlu = null): string
    {
        if ($margine < 0 || $margine > 64) {
            throw new InvalidArgumentException('Marginea codului QR trebuie să fie între 0 și 64 de module.');
        }
        $m = self::matrice($text, $nivel);
        $n = count($m);
        $latura = $n + 2 * $margine;
        $cale = '';
        foreach ($m as $y => $rand) {
            // Modulele alăturate de pe un rând se unesc într-un singur dreptunghi.
            for ($x = 0; $x < $n; $x++) {
                if (!$rand[$x]) {
                    continue;
                }
                $inceput = $x;
                while ($x + 1 < $n && $rand[$x + 1]) {
                    $x++;
                }
                $l = $x - $inceput + 1;
                $cale .= 'M' . ($inceput + $margine) . ' ' . ($y + $margine) . 'h' . $l . 'v1h-' . $l . 'z';
            }
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $latura . ' ' . $latura . '" role="img" shape-rendering="crispEdges">';
        if ($titlu !== null && $titlu !== '') {
            $svg .= '<title>' . htmlspecialchars($titlu, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</title>';
        }
        return $svg . '<rect width="' . $latura . '" height="' . $latura . '" fill="#fff"/><path fill="#000" d="' . $cale . '"/></svg>';
    }

    /**
     * Construcția completă, cu versiunea și masca alese. `$masca` (0-7) forțează o mască anume; null = cea cu
     * penalizarea cea mai mică (la egalitate, prima).
     *
     * @return array{versiune: int, masca: int, matrice: list<list<bool>>}
     */
    public static function construieste(string $text, string $nivel = 'M', ?int $masca = null): array
    {
        if (!isset(self::NIVEL_BITI[$nivel])) {
            throw new InvalidArgumentException('Nivel de corecție necunoscut pentru codul QR: se acceptă L, M, Q, H.');
        }
        if ($masca !== null && ($masca < 0 || $masca > 7)) {
            throw new InvalidArgumentException('Masca codului QR trebuie să fie între 0 și 7.');
        }
        $lungime = strlen($text);
        $versiune = 0;
        for ($v = 1; $v <= self::VERSIUNE_MAX; $v++) {
            if ($lungime <= self::capacitate($v, $nivel)) {
                $versiune = $v;
                break;
            }
        }
        if ($versiune === 0) {
            throw new InvalidArgumentException('Text prea lung pentru un cod QR: ' . $lungime . ' octeți, cel mult '
                . self::capacitate(self::VERSIUNE_MAX, $nivel) . ' la nivelul ' . $nivel . '.');
        }

        $cuvinte = self::intercaleaza(self::cuvinteDate($text, $versiune, $nivel), $versiune, $nivel);
        $n = self::latura($versiune);
        $g = array_fill(0, $n, array_fill(0, $n, 0));
        $f = $g;
        self::tipareFixe($g, $f, $versiune);
        self::asazaCuvinte($g, $f, $cuvinte);

        $ales = $masca;
        $celMaiBun = [];
        if ($ales === null) {
            $min = PHP_INT_MAX;
            for ($i = 0; $i < 8; $i++) {
                $incercare = self::cuMasca($g, $f, $i);
                self::scrieFormat($incercare, $nivel, $i);
                $p = self::penalizare($incercare);
                if ($p < $min) {
                    [$min, $ales, $celMaiBun] = [$p, $i, $incercare];
                }
            }
        } else {
            $celMaiBun = self::cuMasca($g, $f, $ales);
            self::scrieFormat($celMaiBun, $nivel, $ales);
        }

        $matrice = [];
        foreach ($celMaiBun as $rand) {
            $matrice[] = array_map(static fn (int $b): bool => $b === 1, $rand);
        }
        return ['versiune' => $versiune, 'masca' => (int) $ales, 'matrice' => $matrice];
    }

    /** Câți octeți de text încap la versiunea și nivelul date (modul octet). */
    public static function capacitate(int $versiune, string $nivel): int
    {
        if ($versiune < 1 || $versiune > self::VERSIUNE_MAX || !isset(self::NIVEL_BITI[$nivel])) {
            throw new InvalidArgumentException('Versiune sau nivel de corecție necunoscut pentru codul QR.');
        }
        $biti = self::cuvinteDeDate($versiune, $nivel) * 8 - 4 - ($versiune < 10 ? 8 : 16);
        return intdiv($biti, 8);
    }

    /** Latura matricei, în module, fără zona liberă. */
    public static function latura(int $versiune): int
    {
        return 17 + 4 * $versiune;
    }

    /**
     * Cuvintele de corecție Reed-Solomon (GF(256), polinomul 0x11D, generatorul cu rădăcinile 2^0 ... 2^(grad-1)).
     *
     * @param list<int> $date
     * @return list<int>
     */
    public static function reedSolomon(array $date, int $grad): array
    {
        // Polinomul generator, cu coeficientul dominant (1) lăsat pe dinafară.
        $gen = array_fill(0, $grad, 0);
        $gen[$grad - 1] = 1;
        $radacina = 1;
        for ($i = 0; $i < $grad; $i++) {
            for ($j = 0; $j < $grad; $j++) {
                $gen[$j] = self::inmulteste($gen[$j], $radacina);
                if ($j + 1 < $grad) {
                    $gen[$j] ^= $gen[$j + 1];
                }
            }
            $radacina = self::inmulteste($radacina, 2);
        }
        // Restul împărțirii datelor la generator.
        $rest = array_fill(0, $grad, 0);
        foreach ($date as $b) {
            $factor = $b ^ $rest[0];
            array_shift($rest);
            $rest[] = 0;
            foreach ($gen as $i => $c) {
                $rest[$i] ^= self::inmulteste($c, $factor);
            }
        }
        return $rest;
    }

    /** Cei 15 biți ai informației de format (BCH 15,5 cu masca 0x5412), ca număr; bitul 14 e primul din șirul publicat. */
    public static function bitiFormat(string $nivel, int $masca): int
    {
        $date = (self::NIVEL_BITI[$nivel] << 3) | $masca;
        $rest = $date;
        for ($i = 0; $i < 10; $i++) {
            $rest = ($rest << 1) ^ (($rest >> 9) * 0x537);
        }
        return (($date << 10) | $rest) ^ 0x5412;
    }

    /** Cei 18 biți ai informației de versiune (BCH 18,6), ca număr; are sens de la versiunea 7. */
    public static function bitiVersiune(int $versiune): int
    {
        $rest = $versiune;
        for ($i = 0; $i < 12; $i++) {
            $rest = ($rest << 1) ^ (($rest >> 11) * 0x1F25);
        }
        return ($versiune << 12) | $rest;
    }

    /**
     * Pozițiile (pe o axă) ale centrelor tiparelor de aliniere; gol la versiunea 1.
     *
     * @return list<int>
     */
    public static function pozitiiAliniere(int $versiune): array
    {
        if ($versiune === 1) {
            return [];
        }
        $cate = intdiv($versiune, 7) + 2;
        $pas = intdiv($versiune * 8 + $cate * 3 + 5, $cate * 4 - 4) * 2;
        $poz = [6];
        for ($p = self::latura($versiune) - 7; count($poz) < $cate; $p -= $pas) {
            array_splice($poz, 1, 0, [$p]);
        }
        return $poz;
    }

    /** Înmulțirea în GF(256) modulo 0x11D. */
    private static function inmulteste(int $a, int $b): int
    {
        $r = 0;
        for ($i = 7; $i >= 0; $i--) {
            $r = ($r << 1) ^ (($r >> 7) * 0x11D);
            $r ^= (($b >> $i) & 1) * $a;
        }
        return $r;
    }

    /** Toate cuvintele de cod (date + corecție) ale versiunii. */
    private static function cuvinteTotal(int $versiune): int
    {
        $module = (16 * $versiune + 128) * $versiune + 64;
        if ($versiune >= 2) {
            $cate = intdiv($versiune, 7) + 2;
            $module -= (25 * $cate - 10) * $cate - 55;
            if ($versiune >= 7) {
                $module -= 36;
            }
        }
        return intdiv($module, 8);
    }

    private static function cuvinteDeDate(int $versiune, string $nivel): int
    {
        return self::cuvinteTotal($versiune) - self::CORECTIE_PE_BLOC[$nivel][$versiune - 1] * self::BLOCURI[$nivel][$versiune - 1];
    }

    /**
     * Cuvintele de date: indicatorul de mod (0100), lungimea, octeții, terminatorul și umplutura (EC, 11).
     *
     * @return list<int>
     */
    private static function cuvinteDate(string $text, int $versiune, string $nivel): array
    {
        $loc = self::cuvinteDeDate($versiune, $nivel) * 8;
        $biti = '0100' . str_pad(decbin(strlen($text)), $versiune < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        for ($i = 0, $l = strlen($text); $i < $l; $i++) {
            $biti .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }
        $biti .= str_repeat('0', min(4, $loc - strlen($biti)));
        $biti .= str_repeat('0', (8 - strlen($biti) % 8) % 8);
        $cuvinte = [];
        foreach (str_split($biti, 8) as $octet) {
            $cuvinte[] = (int) bindec($octet);
        }
        for ($umplutura = 0xEC; count($cuvinte) < intdiv($loc, 8); $umplutura ^= 0xEC ^ 0x11) {
            $cuvinte[] = $umplutura;
        }
        return $cuvinte;
    }

    /**
     * Împarte datele pe blocuri, adaugă corecția fiecărui bloc și intercalează: întâi datele, coloană cu coloană,
     * apoi corecția. Blocurile scurte sunt primele; cele lungi au un cuvânt de date în plus.
     *
     * @param list<int> $date
     * @return list<int>
     */
    private static function intercaleaza(array $date, int $versiune, string $nivel): array
    {
        $blocuri = self::BLOCURI[$nivel][$versiune - 1];
        $corectie = self::CORECTIE_PE_BLOC[$nivel][$versiune - 1];
        $total = self::cuvinteTotal($versiune);
        $scurte = $blocuri - $total % $blocuri;
        $dateScurt = intdiv($total, $blocuri) - $corectie;

        $bd = [];
        $bc = [];
        for ($i = 0, $k = 0; $i < $blocuri; $i++) {
            $l = $dateScurt + ($i < $scurte ? 0 : 1);
            $bd[] = array_slice($date, $k, $l);
            $bc[] = self::reedSolomon($bd[$i], $corectie);
            $k += $l;
        }
        $rezultat = [];
        for ($i = 0; $i <= $dateScurt; $i++) {
            foreach ($bd as $b) {
                if (isset($b[$i])) {
                    $rezultat[] = $b[$i];
                }
            }
        }
        for ($i = 0; $i < $corectie; $i++) {
            foreach ($bc as $b) {
                $rezultat[] = $b[$i];
            }
        }
        return $rezultat;
    }

    /**
     * Tiparele fixe: sincronizare, căutare (cu separatoare), aliniere, modulul întunecat, locul informației de format
     * și informația de versiune. `$f` ține minte modulele de funcție, pe care datele și masca nu le ating.
     *
     * @param list<list<int>> $g
     * @param list<list<int>> $f
     */
    private static function tipareFixe(array &$g, array &$f, int $versiune): void
    {
        $n = count($g);
        $pune = static function (int $x, int $y, int $v) use (&$g, &$f): void {
            $g[$y][$x] = $v;
            $f[$y][$x] = 1;
        };
        // Sincronizarea: rândul 6 și coloana 6, alternat.
        for ($i = 0; $i < $n; $i++) {
            $pune(6, $i, ($i + 1) % 2);
            $pune($i, 6, ($i + 1) % 2);
        }
        // Tiparele de căutare, cu separatorul deschis din jur.
        foreach ([[3, 3], [$n - 4, 3], [3, $n - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $n && $y >= 0 && $y < $n) {
                        $d = max(abs($dx), abs($dy));
                        $pune($x, $y, $d !== 2 && $d !== 4 ? 1 : 0);
                    }
                }
            }
        }
        // Tiparele de aliniere, fără cele trei care ar cădea peste tiparele de căutare.
        $poz = self::pozitiiAliniere($versiune);
        $ultim = count($poz) - 1;
        foreach ($poz as $i => $cy) {
            foreach ($poz as $j => $cx) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $ultim) || ($i === $ultim && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $pune($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1 ? 1 : 0);
                    }
                }
            }
        }
        // Locul informației de format (valorile se scriu la fiecare mască) și modulul mereu întunecat.
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $pune(8, $i, 0);
                $pune($i, 8, 0);
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $pune($n - 1 - $i, 8, 0);
            $pune(8, $n - 1 - $i, 0);
        }
        $pune(8, $n - 8, 1);
        // Informația de versiune: două blocuri de 6×3, lângă tiparele de căutare din dreapta sus și stânga jos.
        if ($versiune >= 7) {
            $biti = self::bitiVersiune($versiune);
            for ($i = 0; $i < 18; $i++) {
                $bit = ($biti >> $i) & 1;
                $a = $n - 11 + $i % 3;
                $b = intdiv($i, 3);
                $pune($a, $b, $bit);
                $pune($b, $a, $bit);
            }
        }
    }

    /**
     * Așază biții cuvintelor în zigzag, pe perechi de coloane, de la dreapta jos, sărind peste modulele de funcție.
     * Biții rămași la urmă (cel mult 7) rămân deschiși.
     *
     * @param list<list<int>> $g
     * @param list<list<int>> $f
     * @param list<int> $cuvinte
     */
    private static function asazaCuvinte(array &$g, array $f, array $cuvinte): void
    {
        $n = count($g);
        $totalBiti = count($cuvinte) * 8;
        $i = 0;
        for ($dreapta = $n - 1; $dreapta >= 1; $dreapta -= 2) {
            if ($dreapta === 6) {
                $dreapta = 5;
            }
            $inSus = (($dreapta + 1) & 2) === 0;
            for ($vert = 0; $vert < $n; $vert++) {
                $y = $inSus ? $n - 1 - $vert : $vert;
                for ($j = 0; $j < 2; $j++) {
                    $x = $dreapta - $j;
                    if ($f[$y][$x] === 0 && $i < $totalBiti) {
                        $g[$y][$x] = ($cuvinte[$i >> 3] >> (7 - ($i & 7))) & 1;
                        $i++;
                    }
                }
            }
        }
    }

    /**
     * Matricea cu masca aplicată pe modulele de date.
     *
     * @param list<list<int>> $g
     * @param list<list<int>> $f
     * @return list<list<int>>
     */
    private static function cuMasca(array $g, array $f, int $masca): array
    {
        $n = count($g);
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($f[$y][$x] === 1) {
                    continue;
                }
                $inverseaza = match ($masca) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($inverseaza) {
                    $g[$y][$x] ^= 1;
                }
            }
        }
        return $g;
    }

    /**
     * Scrie cele două copii ale informației de format.
     *
     * @param list<list<int>> $g
     */
    private static function scrieFormat(array &$g, string $nivel, int $masca): void
    {
        $n = count($g);
        $biti = self::bitiFormat($nivel, $masca);
        $bit = static fn (int $i): int => ($biti >> $i) & 1;
        // Prima copie, în jurul tiparului de căutare din stânga sus.
        for ($i = 0; $i <= 5; $i++) {
            $g[$i][8] = $bit($i);
        }
        $g[7][8] = $bit(6);
        $g[8][8] = $bit(7);
        $g[8][7] = $bit(8);
        for ($i = 9; $i < 15; $i++) {
            $g[8][14 - $i] = $bit($i);
        }
        // A doua copie, împărțită între dreapta sus și stânga jos.
        for ($i = 0; $i < 8; $i++) {
            $g[8][$n - 1 - $i] = $bit($i);
        }
        for ($i = 8; $i < 15; $i++) {
            $g[$n - 15 + $i][8] = $bit($i);
        }
    }

    /**
     * Scorul de penalizare al unei matrice mascate, după cele 4 reguli din standard. Zona liberă din jur se socotește
     * deschisă la regula 3, iar tiparul 1:1:3:1:1 se caută la orice scară (ca în implementarea de referință Nayuki).
     *
     * @param list<list<int>> $g
     */
    private static function penalizare(array $g): int
    {
        $n = count($g);
        $linii = $g;
        for ($x = 0; $x < $n; $x++) {
            $linii[] = array_column($g, $x);
        }
        $scor = 0;
        foreach ($linii as $linie) {
            $culoare = 0;
            $sir = 0;
            $istoric = [0, 0, 0, 0, 0, 0, 0];
            foreach ($linie as $modul) {
                if ($modul === $culoare) {
                    // Regula 1: 5 module la rând de aceeași culoare: 3 puncte, apoi câte 1 pentru fiecare în plus.
                    $sir++;
                    if ($sir === 5) {
                        $scor += 3;
                    } elseif ($sir > 5) {
                        $scor++;
                    }
                } else {
                    self::tineMinteSirul($sir, $istoric, $n);
                    if ($culoare === 0) {
                        $scor += 40 * self::tipareCautare($istoric);
                    }
                    $culoare = $modul;
                    $sir = 1;
                }
            }
            // Capătul liniei: șirul întunecat se încheie, apoi vine zona liberă.
            if ($culoare === 1) {
                self::tineMinteSirul($sir, $istoric, $n);
                $sir = 0;
            }
            self::tineMinteSirul($sir + $n, $istoric, $n);
            $scor += 40 * self::tipareCautare($istoric);
        }
        // Regula 2: fiecare pătrat de 2×2 de aceeași culoare: 3 puncte.
        $intunecate = 0;
        for ($y = 0; $y < $n; $y++) {
            $sus = $g[$y];
            $intunecate += array_sum($sus);
            if ($y === $n - 1) {
                break;
            }
            $jos = $g[$y + 1];
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $sus[$x];
                if ($c === $sus[$x + 1] && $c === $jos[$x] && $c === $jos[$x + 1]) {
                    $scor += 3;
                }
            }
        }
        // Regula 4: abaterea ponderii modulelor întunecate de la 50%, câte 10 puncte pentru fiecare 5 procente depășite.
        $total = $n * $n;
        return $scor + 10 * (intdiv(abs($intunecate * 20 - $total * 10) + $total - 1, $total) - 1);
    }

    /**
     * Adaugă lungimea unui șir încheiat în istoricul ultimelor 7 șiruri ale liniei (cel mai nou primul). Primul șir
     * deschis al liniei se lungește cu zona liberă.
     *
     * @param list<int> $istoric
     */
    private static function tineMinteSirul(int $sir, array &$istoric, int $n): void
    {
        if ($istoric[0] === 0) {
            $sir += $n;
        }
        array_pop($istoric);
        array_unshift($istoric, $sir);
    }

    /**
     * Regula 3: câte tipare asemănătoare celui de căutare (1:1:3:1:1, cu cel puțin 4 unități deschise într-o parte și
     * una în cealaltă) se încheie cu ultimul șir deschis din istoric: 0, 1 sau 2.
     *
     * @param list<int> $i
     */
    private static function tipareCautare(array $i): int
    {
        $u = $i[1];
        if ($u === 0 || $i[2] !== $u || $i[3] !== $u * 3 || $i[4] !== $u || $i[5] !== $u) {
            return 0;
        }
        return ($i[0] >= $u * 4 && $i[6] >= $u ? 1 : 0) + ($i[6] >= $u * 4 && $i[0] >= $u ? 1 : 0);
    }
}
