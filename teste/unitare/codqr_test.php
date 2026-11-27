<?php

declare(strict_types=1);

// Generatorul de coduri QR (CodQr): Reed-Solomon, informația de format și de versiune, tiparele fixe, matrice întregi
// comparate bit cu bit cu două implementări independente (Nayuki qrcodegen și python-qrcode), și ieșirea SVG.

use Cazo\Securitate\CodQr;

/** Matricea ca șir hex: rândurile puse cap la cap, completate cu zerouri până la un multiplu de 4 biți. */
function codqr_hex(array $matrice): string
{
    $biti = '';
    foreach ($matrice as $rand) {
        foreach ($rand as $modul) {
            $biti .= $modul ? '1' : '0';
        }
    }
    $biti .= str_repeat('0', (4 - strlen($biti) % 4) % 4);
    $hex = '';
    foreach (str_split($biti, 4) as $patru) {
        $hex .= dechex((int) bindec($patru));
    }
    return $hex;
}

/** Biții informației de format citiți din matrice: [prima copie, a doua copie], ca șiruri cu bitul 14 primul. */
function codqr_format_citit(array $m): array
{
    $n = count($m);
    $a = [];
    $b = [];
    for ($i = 0; $i <= 5; $i++) {
        $a[$i] = $m[$i][8];
    }
    $a[6] = $m[7][8];
    $a[7] = $m[8][8];
    $a[8] = $m[8][7];
    for ($i = 9; $i < 15; $i++) {
        $a[$i] = $m[8][14 - $i];
    }
    for ($i = 0; $i < 8; $i++) {
        $b[$i] = $m[8][$n - 1 - $i];
    }
    for ($i = 8; $i < 15; $i++) {
        $b[$i] = $m[$n - 15 + $i][8];
    }
    $sir = static function (array $biti): string {
        $s = '';
        for ($i = 14; $i >= 0; $i--) {
            $s .= $biti[$i] ? '1' : '0';
        }
        return $s;
    };
    return [$sir($a), $sir($b)];
}

/** Text ASCII determinist de lungimea dată. */
function codqr_text(int $lungime): string
{
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789%&=?:/-._';
    $t = '';
    for ($i = 0; $i < $lungime; $i++) {
        $t .= $abc[($i * 37 + 11) % strlen($abc)];
    }
    return $t;
}

const CODQR_OTPAUTH = 'otpauth://totp/Pensiunea%20Trei%20Brazi:admin%40example.com?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=Pensiunea%20Trei%20Brazi&digits=6&period=30';

return [
    'Reed-Solomon dă cuvintele de corecție din exemplul publicat' => function (Mediu $m): void {
        // Exemplul din tutorialul Thonky (versiunea 1-M, 16 cuvinte de date, 10 de corecție).
        egal(
            [196, 35, 39, 119, 235, 215, 231, 226, 93, 23],
            CodQr::reedSolomon([32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17], 10)
        );
        // Un cuvânt de date nul nu schimbă nimic; lungimea ieșirii e gradul cerut.
        egal(array_fill(0, 7, 0), CodQr::reedSolomon([0, 0, 0], 7));
        egal(30, count(CodQr::reedSolomon(range(1, 40), 30)));
    },

    'informația de format: tabelul BCH întreg, pe niveluri și măști' => function (Mediu $m): void {
        $tabel = [
            'L' => ['111011111000100', '111001011110011', '111110110101010', '111100010011101', '110011000101111', '110001100011000', '110110001000001', '110100101110110'],
            'M' => ['101010000010010', '101000100100101', '101111001111100', '101101101001011', '100010111111001', '100000011001110', '100111110010111', '100101010100000'],
            'Q' => ['011010101011111', '011000001101000', '011111100110001', '011101000000110', '010010010110100', '010000110000011', '010111011011010', '010101111101101'],
            'H' => ['001011010001001', '001001110111110', '001110011100111', '001100111010000', '000011101100010', '000001001010101', '000110100001100', '000100000111011'],
        ];
        foreach ($tabel as $nivel => $siruri) {
            foreach ($siruri as $masca => $sir) {
                egal($sir, sprintf('%015b', CodQr::bitiFormat($nivel, $masca)), "nivelul $nivel, masca $masca");
            }
        }
    },

    'informația de versiune: șirurile BCH cunoscute' => function (Mediu $m): void {
        $tabel = [7 => '000111110010010100', 8 => '001000010110111100', 9 => '001001101010011001', 10 => '001010010011010011'];
        foreach ($tabel as $versiune => $sir) {
            egal($sir, sprintf('%018b', CodQr::bitiVersiune($versiune)), "versiunea $versiune");
        }
        // Peste tot: primii 6 biți sunt versiunea, iar întregul se împarte exact la polinomul generator 0x1F25.
        for ($v = 7; $v <= CodQr::VERSIUNE_MAX; $v++) {
            $biti = CodQr::bitiVersiune($v);
            egal($v, $biti >> 12);
            for ($i = 17; $i >= 12; $i--) {
                if (($biti >> $i) & 1) {
                    $biti ^= 0x1F25 << ($i - 12);
                }
            }
            egal(0, $biti, "restul la versiunea $v");
        }
    },

    'capacitatea în octeți și alegerea versiunii la limită' => function (Mediu $m): void {
        // Valorile din tabelul standardului (modul octet).
        $cunoscute = [
            'L' => [1 => 17, 2 => 32, 5 => 106, 9 => 230, 10 => 271, 15 => 520, 20 => 858],
            'M' => [1 => 14, 2 => 26, 5 => 84, 9 => 180, 10 => 213, 15 => 412, 20 => 666],
            'Q' => [1 => 11, 2 => 20, 5 => 60, 9 => 130, 10 => 151, 15 => 292, 20 => 482],
            'H' => [1 => 7, 2 => 14, 5 => 44, 9 => 98, 10 => 119, 15 => 220, 20 => 382],
        ];
        foreach ($cunoscute as $nivel => $pe) {
            foreach ($pe as $versiune => $octeti) {
                egal($octeti, CodQr::capacitate($versiune, $nivel), "$versiune-$nivel");
            }
        }
        foreach (['L', 'M', 'Q', 'H'] as $nivel) {
            foreach ([1, 2, 6, 9, 10, 14] as $v) {
                $c = CodQr::capacitate($v, $nivel);
                egal($v, CodQr::construieste(codqr_text($c), $nivel, 0)['versiune'], "plin la $v-$nivel");
                egal($v + 1, CodQr::construieste(codqr_text($c + 1), $nivel, 0)['versiune'], "un octet peste $v-$nivel");
            }
        }
        egal(1, CodQr::construieste('', 'M')['versiune']);
    },

    'matricea are latura versiunii, tiparele de căutare, sincronizarea și modulul întunecat' => function (Mediu $m): void {
        $cautare = ['1111111', '1000001', '1011101', '1011101', '1011101', '1000001', '1111111'];
        foreach ([[1, 'M', 3], [2, 'L', 20], [7, 'Q', 80], [10, 'M', 200], [15, 'H', 200], [20, 'L', 800]] as [$versiune, $nivel, $lungime]) {
            $r = CodQr::construieste(codqr_text($lungime), $nivel);
            egal($versiune, $r['versiune']);
            $mat = $r['matrice'];
            $n = 17 + 4 * $versiune;
            egal($n, CodQr::latura($versiune));
            egal($n, count($mat));
            foreach ($mat as $rand) {
                egal($n, count($rand));
                adevarat(array_is_list($rand) && $rand === array_map('boolval', $rand), 'rândul nu e listă de bool');
            }
            foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$x0, $y0]) {
                for ($y = 0; $y < 7; $y++) {
                    $rand = '';
                    for ($x = 0; $x < 7; $x++) {
                        $rand .= $mat[$y0 + $y][$x0 + $x] ? '1' : '0';
                    }
                    egal($cautare[$y], $rand, "tiparul de căutare de la ($x0, $y0), rândul $y, versiunea $versiune");
                }
            }
            // Separatoarele deschise de lângă tiparele de căutare.
            for ($i = 0; $i < 8; $i++) {
                fals($mat[7][$i]);
                fals($mat[$i][7]);
                fals($mat[7][$n - 1 - $i]);
                fals($mat[$i][$n - 8]);
                fals($mat[$n - 8][$i]);
                fals($mat[$n - 1 - $i][7]);
            }
            // Sincronizarea, între tiparele de căutare.
            for ($i = 8; $i < $n - 8; $i++) {
                egal($i % 2 === 0, $mat[6][$i], "sincronizarea pe rând, $i");
                egal($i % 2 === 0, $mat[$i][6], "sincronizarea pe coloană, $i");
            }
            adevarat($mat[$n - 8][8], 'modulul întunecat lipsește');
            // Cele două copii ale informației de format spun nivelul și masca alese.
            $asteptat = sprintf('%015b', CodQr::bitiFormat($nivel, $r['masca']));
            egal([$asteptat, $asteptat], codqr_format_citit($mat));
        }
    },

    'tiparele de aliniere stau la pozițiile din standard' => function (Mediu $m): void {
        $pozitii = [
            1 => [], 2 => [6, 18], 3 => [6, 22], 6 => [6, 34], 7 => [6, 22, 38], 10 => [6, 28, 50], 13 => [6, 34, 62],
            14 => [6, 26, 46, 66], 15 => [6, 26, 48, 70], 16 => [6, 26, 50, 74], 19 => [6, 30, 58, 86], 20 => [6, 34, 62, 90],
        ];
        foreach ($pozitii as $versiune => $poz) {
            egal($poz, CodQr::pozitiiAliniere($versiune), "versiunea $versiune");
        }
        // Versiunea 7: șase tipare (3×3 fără cele trei colțuri cu tipare de căutare).
        $mat = CodQr::matrice(codqr_text(120), 'M');
        egal(45, count($mat));
        $tipar = ['11111', '10001', '10101', '10001', '11111'];
        foreach ([[22, 6], [6, 22], [22, 22], [38, 22], [22, 38], [38, 38]] as [$cx, $cy]) {
            for ($dy = -2; $dy <= 2; $dy++) {
                $rand = '';
                for ($dx = -2; $dx <= 2; $dx++) {
                    $rand .= $mat[$cy + $dy][$cx + $dx] ? '1' : '0';
                }
                egal($tipar[$dy + 2], $rand, "alinierea de la ($cx, $cy)");
            }
        }
        // Informația de versiune (000111110010010100), în cele două blocuri de 6×3.
        $biti = 0b000111110010010100;
        for ($i = 0; $i < 18; $i++) {
            $bit = (($biti >> $i) & 1) === 1;
            egal($bit, $mat[intdiv($i, 3)][45 - 11 + $i % 3], "versiunea, dreapta sus, bitul $i");
            egal($bit, $mat[45 - 11 + $i % 3][intdiv($i, 3)], "versiunea, stânga jos, bitul $i");
        }
    },

    'matricele întregi sunt identice cu cele din implementările de referință' => function (Mediu $m): void {
        // Generate cu Nayuki qrcodegen (masca aleasă de el) și verificate și cu python-qrcode: versiunea, masca, matricea.
        $vectori = [
            ['Pragul', 'M', 1, 0, 'fe23fc14506e9cbb74e5dbadaec10907faafe00400aa2891c2aa4dadc711fb2de1708051a3f9a3f04863ba8b1dd1acaea5f304382fed7d8'],
            ['0123456789', 'L', 1, 0, 'fe2bfc10506ea8bb7415dba5aec13907faafe01400efae2603866dd53ad2fbab315c806367faaa30548bbacdc5d1b6aebdb3057aafe93d8'],
            ['https://example.com/panou?a=1&b=2%20x', 'Q', 4, 0, 'fec4373fc1652a906eb662abb7544ab5dbad4872ec12863907faaaaafe01fbda006b39d42ff68944784693f10caf29013d0bfd49125aab72a11538148316b3316c429f956ee0140b127873bc1090e5320be3142bd7a25321a2d1ae0eee71fed82e5b28ab123cfd806e54c6ffa8616af044b5113ba9c50fd5d19ea85eea13193b0530af9afe49ec8d8'],
            ['Verificarea în doi pași: ăîâșț ĂÎÂȘȚ', 'H', 6, 3, 'fe6fda96bfc123495dd06e99941f4bb74b2c4215dbadaf584aec12c64be107faaaaaaafe01e677190033db794ee8623048b3c23c8964b71c20bce8276112759d134a3841e98064957a5769efd0c9608a5cc9e2ae2234d84c161e800919a38760b6d8901afbf39b74e67b86530d8e8fc5510f6cd15cec286381d6d546e28034296cc81c4487128d34ce6a4c18045e21b531fde889c0ec3d915f86ecb2f6fdc2405a3c029f6203a92afe80623e7bc4ffa57c0eab5040586bb1cba7c0db1fadd62c6ca6ceea08b2a653040e238186fe55dd31970'],
            [CODQR_OTPAUTH, 'M', 8, 2, 'fe2675d704bfc10c15da73d06ea65d13526bb754d6702ba5dbade27ee0c2ec166891837107faaaaaaaaafe01840c5b4100be606be458be1e5b6aa386b3a899fd597a952f3bea38130e3025847831e306ab3eccb8a32a7aeab5dd560673453b9de21a63aa5bc31af716e02a2fecb828d68da95bc77e362589e3833271412c719b3e6a888e7c6bb63f388fc1ebfe11e3e47529135aef2ea51caffc4eb91fee4617f2dbfc7dff6486fccd7c8e0362c66134fb4504473dd86881fd748f4231863359c07503080e6d4f757913c0e09b6d821a07c04e113a2f630fd0d175c80fa3664c67fcf0ce23ae1caca51a377962bc86e00800dd4421e3bc5fe779fe80743b17cec7bf89daac1aaaf05b10c5da71bbaae3ff15cfadd48e103c4deeebf96e8178d904c54f9920e9fea239b75a238'],
        ];
        foreach ($vectori as [$text, $nivel, $versiune, $masca, $hex]) {
            $r = CodQr::construieste($text, $nivel);
            egal($versiune, $r['versiune'], "versiunea pentru „{$text}”");
            egal($masca, $r['masca'], "masca pentru „{$text}”");
            egal($hex, codqr_hex($r['matrice']), "matricea pentru „{$text}”");
            egal($r['matrice'], CodQr::matrice($text, $nivel));
            // Masca forțată pe cea aleasă dă aceeași matrice; alta dă o matrice diferită, cu aceleași tipare fixe.
            egal($r['matrice'], CodQr::construieste($text, $nivel, $masca)['matrice']);
            $alta = CodQr::construieste($text, $nivel, ($masca + 1) % 8);
            adevarat($alta['matrice'] !== $r['matrice'], 'masca nu schimbă matricea');
            egal(sprintf('%015b', CodQr::bitiFormat($nivel, ($masca + 1) % 8)), codqr_format_citit($alta['matrice'])[0]);
        }
        egal(150, strlen(CODQR_OTPAUTH));
    },

    'textul prea lung, nivelul și masca necunoscute sunt refuzate' => function (Mediu $m): void {
        foreach (['L' => 858, 'M' => 666, 'Q' => 482, 'H' => 382] as $nivel => $max) {
            egal(CodQr::VERSIUNE_MAX, CodQr::construieste(str_repeat('a', $max), $nivel, 0)['versiune']);
            arunca(static fn () => CodQr::matrice(str_repeat('a', $max + 1), $nivel), \InvalidArgumentException::class, 'prea lung');
        }
        arunca(static fn () => CodQr::svg(str_repeat('ș', 400)), \InvalidArgumentException::class, 'prea lung');
        arunca(static fn () => CodQr::matrice('a', 'X'), \InvalidArgumentException::class);
        arunca(static fn () => CodQr::matrice('a', 'm'), \InvalidArgumentException::class);
        arunca(static fn () => CodQr::construieste('a', 'M', 8), \InvalidArgumentException::class);
        arunca(static fn () => CodQr::svg('a', 'M', -1), \InvalidArgumentException::class);
        arunca(static fn () => CodQr::capacitate(21, 'M'), \InvalidArgumentException::class);
    },

    'SVG-ul e un singur element, fără stiluri și scripturi, cu aceleași module ca matricea' => function (Mediu $m): void {
        $mat = CodQr::matrice(CODQR_OTPAUTH);
        $n = count($mat);
        $svg = CodQr::svg(CODQR_OTPAUTH);
        $latura = $n + 8;
        adevarat(str_starts_with($svg, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $latura . ' ' . $latura . '" role="img" shape-rendering="crispEdges">'));
        adevarat(str_ends_with($svg, '</svg>'));
        egal(1, substr_count($svg, '<svg'));
        egal(1, substr_count($svg, '<path'));
        egal(1, substr_count($svg, '<rect'));
        contine($svg, '<rect width="' . $latura . '" height="' . $latura . '" fill="#fff"/>');
        contine($svg, '<path fill="#000" d="M');
        foreach (['style', '<script', 'class=', 'href', '<title', "\n"] as $oprit) {
            lipseste($svg, $oprit);
        }
        // Calea, citită înapoi, dă exact matricea, mutată cu marginea.
        adevarat(preg_match('~<path fill="#000" d="([^"]+)"/>~', $svg, $g) === 1);
        adevarat(preg_match('~^(?:M\d+ \d+h\d+v1h-\d+z)+$~', $g[1]) === 1, 'calea are și altceva decât dreptunghiuri');
        preg_match_all('~M(\d+) (\d+)h(\d+)v1h-(\d+)z~', $g[1], $buc, PREG_SET_ORDER);
        $citit = array_fill(0, $n, array_fill(0, $n, false));
        foreach ($buc as [, $x, $y, $l, $l2]) {
            egal($l, $l2);
            for ($i = 0; $i < (int) $l; $i++) {
                $citit[(int) $y - 4][(int) $x - 4 + $i] = true;
            }
        }
        egal($mat, $citit);

        // Marginea aleasă de apelant și titlul, escapat.
        $svg = CodQr::svg('Pragul', 'H', 0, 'Cod QR <pentru> "aplicație" & \'altele\'');
        contine($svg, 'viewBox="0 0 21 21"');
        contine($svg, '<title>Cod QR &lt;pentru&gt; &quot;aplicație&quot; &amp; &apos;altele&apos;</title><rect');
        lipseste(CodQr::svg('Pragul', 'H', 2, ''), '<title');
        contine(CodQr::svg('Pragul', 'L', 2), 'viewBox="0 0 25 25"');
        // Același text dă mereu același cod.
        egal(CodQr::svg('Pragul'), CodQr::svg('Pragul', 'M', 4, null));
    },
];
