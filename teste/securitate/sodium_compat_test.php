<?php

declare(strict_types=1);

// Softul fără extensia sodium: funcțiile sodium_* și constantele SODIUM_* vin din biblioteca vendorizată
// paragonie/sodium_compat, cu același format al datelor criptate și al semnăturilor.
//
// Trei feluri de probe:
//   1. vectorii ficși, făcuți cu extensia: cheile derivate, textele criptate și semnătura trebuie să iasă la fel din
//      orice implementare rulează testul (extensia, sau biblioteca, pe un PHP fără extensie);
//   2. cu extensia încărcată, calea bibliotecii forțată prin cârligul ei de test
//      (ParagonIE_Sodium_Compat::$disableFallbackForUnitTests): ce criptează sau semnează extensia se deschide sau se
//      verifică prin bibliotecă, și invers;
//   3. un proces PHP fără extensie, cu clasele aplicației (Chei, Cutie).
//      PHP-ul fără extensie: CAZO_TEST_PHP_FARA_SODIUM (calea binarului, apoi eventualii indicatori), PHP-ul testelor
//      când el însuși n-are extensia, sau `php -n`, când sodium e modul încărcabil (Windows). Unde sodium e compilat
//      în PHP și variabila lipsește, probele 3 se sar, iar 1 și 2 rămân.

use Cazo\Securitate\Chei;
use Cazo\Securitate\Codare;
use Cazo\Securitate\Cutie;
use Cazo\Securitate\Secrete;

/** Vectorii ficși (cheia-mamă a testelor, 32 × 0x42; nonce 24 × 0x07; sămânța cheii de semnare 32 × 0x05). */
const SODIUM_VECTORI = [
    'pii' => '49edfb8014d4ffe5d40a2498067acb7ed481f922b08b38441ae8ef4d87b8c8a4',
    'secrete' => '8465a0077a2bb42a680764941962704b833f813c2335c3573f1fc99740a6cb53',
    'audit16' => 'c3a0be38ae86f4fef4e36dafb674569b',
    'text' => 'ana@example.com, țărănuș',
    'cutie' => 'v1.BwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHXLpyY2xOmZmxkORp2vo6Ur4y4Ha23GAui952KTmp1WWnqNRxbQvqP4A33pM',
    'secret_text' => 'rk_test_51Abc',
    'secret' => 'v1.BwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcH29PNZD6IHRAsedFnuT94zehcU-DQonk_SkEJ3Mg',
    'sk' => 'BQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQVuehzdKbC3j9E69MVZj+/07yqXFm48pvLk+/zNgFBb8Q==',
    'pk' => 'bnoc3Smwt4/ROvTFWY/v9O8qlxZuPKby5Pv8zYBQW/E=',
    'id' => '7599776c3085e3f9',
    'manifest' => '{"versiune":"1.0.0"}',
    'sig' => "7599776c3085e3f9 6MZNN7Nl2/W0n7EntNVbc65tx3CIC7W/Cc608fUF+mzISdN4+T/5TyNNoGWh77JlW73ZjnKohav5ijhax1YwDw==\n",
];

/** Mesajul semnat al vectorului fix (formatul în care a fost făcută semnătura din SODIUM_VECTORI). */
function sodium_mesaj_vector(string $continut): string
{
    return "pragul-actualizari-v1\nmanifest\n" . hash('sha256', $continut);
}

/**
 * Comanda unui PHP fără extensia sodium, sau null când nu există pe mașina aceasta.
 * @return ?list<string>
 */
function sodium_php_fara_extensie(): ?array
{
    static $gasit = false;
    if ($gasit !== false) {
        return $gasit;
    }
    $candidati = [];
    $env = trim((string) getenv('CAZO_TEST_PHP_FARA_SODIUM'));
    if ($env !== '') {
        $candidati[] = preg_split('~\s+~', $env) ?: [];
    }
    if (!extension_loaded('sodium')) {
        $candidati[] = [PHP_BINARY];
    }
    $candidati[] = [PHP_BINARY, '-n'];
    foreach ($candidati as $c) {
        [$cod, $iesire] = sodium_ruleaza(array_merge($c, ['-r', 'echo extension_loaded("sodium") ? "cu" : "fara";']));
        if ($cod === 0 && trim($iesire) === 'fara') {
            return $gasit = $c;
        }
    }
    if ($env !== '') {
        esec('CAZO_TEST_PHP_FARA_SODIUM e setat, dar PHP-ul lui are extensia sodium sau nu pornește: ' . $env);
    }
    return $gasit = null;
}

/**
 * Rulează o comandă fără shell (aceeași pe Linux și pe Windows).
 * @param list<string> $comanda
 * @return array{0:int, 1:string} codul de ieșire și ieșirea (cu erorile)
 */
function sodium_ruleaza(array $comanda): array
{
    $p = @proc_open($comanda, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tevi);
    if (!is_resource($p)) {
        return [-1, ''];
    }
    fclose($tevi[0]);
    $iesire = (string) stream_get_contents($tevi[1]) . (string) stream_get_contents($tevi[2]);
    fclose($tevi[1]);
    fclose($tevi[2]);
    return [proc_close($p), $iesire];
}

/** Scrie scriptul copilului și îl rulează cu PHP-ul dat; întoarce JSON-ul lui. */
function sodium_copil(Mediu $m, array $php, string $cod, array $date = []): array
{
    $d = $m->dosarTemporar();
    file_put_contents("$d/copil.php", "<?php\n\ndeclare(strict_types=1);\n\nerror_reporting(E_ALL);\nini_set('display_errors', '1');\n"
        . '$date = json_decode((string) file_get_contents(__DIR__ . "/date.json"), true);' . "\n" . $cod);
    file_put_contents("$d/date.json", json_encode($date, JSON_THROW_ON_ERROR));
    [$iesit, $iesire] = sodium_ruleaza(array_merge($php, ["$d/copil.php"]));
    $r = json_decode($iesire, true);
    if ($iesit !== 0 || !is_array($r)) {
        esec('procesul fără extensie a ieșit cu ' . $iesit . ': ' . substr($iesire, 0, 600));
    }
    return $r;
}

function sodium_copiaza(string $din, string $in): void
{
    @mkdir($in, 0755, true);
    foreach (scandir($din) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        is_dir("$din/$f") ? sodium_copiaza("$din/$f", "$in/$f") : copy("$din/$f", "$in/$f");
    }
}

return [
    'vectorii ficși: subcheile, cutia, secretul din bază și semnătura ies la fel din implementarea care rulează' => function (Mediu $m): void {
        $v = SODIUM_VECTORI;
        $chei = $m->chei();
        egal($v['pii'], bin2hex($chei->subcheie('pii')));
        egal($v['secrete'], bin2hex($chei->subcheie('secrete')));
        egal($v['audit16'], bin2hex($chei->subcheie('audit', 16)));
        // Cutia: textul criptat cu extensia se deschide; același nonce dă același text criptat.
        $cutie = new Cutie($chei->subcheie('pii'));
        egal($v['text'], $cutie->decripteaza($v['cutie']));
        $nonce = str_repeat("\x07", 24);
        egal($v['cutie'], 'v1.' . Codare::b64u($nonce . sodium_crypto_secretbox($v['text'], $nonce, $chei->subcheie('pii'))));
        arunca(fn () => $cutie->decripteaza(substr($v['cutie'], 0, -2) . 'A' . substr($v['cutie'], -1)), \RuntimeException::class, 'deteriorate sau cheie greșită');
        arunca(fn () => (new Cutie($chei->subcheie('secrete')))->decripteaza($v['cutie']), \RuntimeException::class, 'deteriorate sau cheie greșită');
        // Secretul din bază (cheia lui: generichash cu subcheia „secrete”), scris de extensie.
        $b = $m->baza();
        $b->insereaza('secrete', ['nume' => 'stripe_cheie', 'valoare' => $v['secret'], 'ultimele4' => '1Abc', 'creat_la' => $m->ceas()->moment()]);
        $secrete = new Secrete($b, $chei, $m->ceas());
        egal($v['secret_text'], $secrete->citeste('stripe_cheie'));
        $secrete->pune('smtp_parola', 'parola de probă');
        egal('parola de probă', $secrete->citeste('smtp_parola'));
        // Semnătura Ed25519 (primitiva bibliotecii) e deterministă, deci iese identică.
        $sk = (string) base64_decode($v['sk'], true);
        $pk = (string) base64_decode($v['pk'], true);
        egal($pk, sodium_crypto_sign_publickey_from_secretkey($sk));
        egal(substr(trim($v['sig']), 17), base64_encode(sodium_crypto_sign_detached(sodium_mesaj_vector($v['manifest']), $sk)));
        egal([32, 32, 24, 16, 64, 32, 64], [SODIUM_CRYPTO_KDF_KEYBYTES, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES,
            SODIUM_CRYPTO_SECRETBOX_MACBYTES, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, SODIUM_CRYPTO_SIGN_BYTES]);
    },

    'cu extensia încărcată, biblioteca forțată pe calea ei: ce criptează sau semnează una deschide sau verifică cealaltă' => function (Mediu $m): void {
        if (!extension_loaded('sodium')) {
            // Pe un PHP fără extensie rulează deja biblioteca: interoperabilitatea o dau vectorii ficși de mai sus.
            adevarat(class_exists(\ParagonIE_Sodium_Compat::class, false), 'fără extensie, biblioteca trebuia încărcată de app/incarca.php');
            return;
        }
        require_once Mediu::radacina() . '/vendor/paragonie/sodium_compat/autoload.php';
        $c = \ParagonIE_Sodium_Compat::class;
        $inainte = $c::$disableFallbackForUnitTests;
        $c::$disableFallbackForUnitTests = true;
        try {
            $v = SODIUM_VECTORI;
            $mama = str_repeat("\x42", 32);
            // Derivarea subcheilor și cheia secretului.
            foreach ([['pii', 2, 32], ['secrete', 1, 32], ['audit16', 10, 16]] as [$nume, $numar, $lungime]) {
                egal($v[$nume], bin2hex($c::crypto_kdf_derive_from_key($lungime, $numar, Chei::CONTEXT, $mama)), $nume);
            }
            $subcheie = (string) hex2bin($v['secrete']);
            egal(sodium_crypto_generichash('pragul-secret-v1:stripe_cheie', $subcheie, 32), $c::crypto_generichash('pragul-secret-v1:stripe_cheie', $subcheie, 32));
            // secretbox, în ambele sensuri, pe texte de mai multe lungimi (și peste un bloc).
            $cheie = (string) hex2bin($v['pii']);
            foreach (['', 'a', $v['text'], str_repeat('ț', 700), random_bytes(4096)] as $i => $text) {
                $nonce = random_bytes(24);
                $ext = sodium_crypto_secretbox($text, $nonce, $cheie);
                $bib = $c::crypto_secretbox($text, $nonce, $cheie);
                egal(bin2hex($ext), bin2hex($bib), "secretbox $i");
                egal($text, $c::crypto_secretbox_open($ext, $nonce, $cheie), "extensia criptează, biblioteca deschide ($i)");
                egal($text, sodium_crypto_secretbox_open($bib, $nonce, $cheie), "biblioteca criptează, extensia deschide ($i)");
                $stricat = $bib;
                $stricat[strlen($stricat) - 1] = chr(ord($stricat[strlen($stricat) - 1]) ^ 1);
                // Clasa bibliotecii aruncă SodiumException; funcția sodium_crypto_secretbox_open a ei întoarce false, ca extensia.
                arunca(fn () => $c::crypto_secretbox_open($stricat, $nonce, $cheie), \SodiumException::class);
                fals(sodium_crypto_secretbox_open($stricat, $nonce, $cheie), "text alterat ($i)");
            }
            // Semnăturile: perechea extensiei citită de bibliotecă și invers.
            $sk = (string) base64_decode($v['sk'], true);
            $pk = (string) base64_decode($v['pk'], true);
            egal($pk, $c::crypto_sign_publickey_from_secretkey($sk));
            $mesaj = sodium_mesaj_vector($v['manifest']);
            $sig = (string) base64_decode(substr(trim($v['sig']), 17), true);
            egal(bin2hex($sig), bin2hex($c::crypto_sign_detached($mesaj, $sk)));
            adevarat($c::crypto_sign_verify_detached($sig, $mesaj, $pk), 'extensia semnează, biblioteca verifică');
            fals($c::crypto_sign_verify_detached($sig, $mesaj . 'x', $pk), 'alt mesaj');
            $kpBib = $c::crypto_sign_keypair();
            [$skBib, $pkBib] = [$c::crypto_sign_secretkey($kpBib), $c::crypto_sign_publickey($kpBib)];
            egal($pkBib, sodium_crypto_sign_publickey_from_secretkey($skBib));
            adevarat(sodium_crypto_sign_verify_detached($c::crypto_sign_detached('m', $skBib), 'm', $pkBib), 'biblioteca semnează, extensia verifică');
            $kpExt = sodium_crypto_sign_keypair();
            adevarat($c::crypto_sign_verify_detached(sodium_crypto_sign_detached('m', sodium_crypto_sign_secretkey($kpExt)), 'm', sodium_crypto_sign_publickey($kpExt)), 'pereche nouă a extensiei');
        } finally {
            $c::$disableFallbackForUnitTests = $inainte;
        }
    },

    'încărcătorul: cu extensia, biblioteca rămâne neîncărcată; fără extensie, aduce funcțiile și constantele înaintea claselor' => function (Mediu $m): void {
        $cod = 'define("CAZO", true); require ' . var_export(Mediu::radacina() . '/app/incarca.php', true) . ';'
            . 'echo json_encode(["ext" => extension_loaded("sodium"), "bib" => class_exists("ParagonIE_Sodium_Compat", false),'
            . ' "functii" => function_exists("sodium_crypto_secretbox") && function_exists("sodium_crypto_kdf_derive_from_key") && function_exists("sodium_crypto_generichash")'
            . ' && function_exists("sodium_crypto_sign_detached") && function_exists("sodium_crypto_sign_verify_detached") && function_exists("sodium_crypto_sign_keypair")'
            . ' && function_exists("sodium_crypto_sign_publickey_from_secretkey"),'
            . ' "constante" => defined("SODIUM_CRYPTO_KDF_KEYBYTES") && defined("SODIUM_CRYPTO_SECRETBOX_NONCEBYTES") && defined("SODIUM_CRYPTO_SIGN_BYTES")]);';
        if (extension_loaded('sodium')) {
            egal(['ext' => true, 'bib' => false, 'functii' => true, 'constante' => true], sodium_copil($m, [PHP_BINARY], $cod));
        }
        $fara = sodium_php_fara_extensie();
        if ($fara !== null) {
            egal(['ext' => false, 'bib' => true, 'functii' => true, 'constante' => true], sodium_copil($m, $fara, $cod));
        }
    },

    'un PHP fără extensia sodium: Chei și Cutie lucrează cu datele făcute de testul părinte, și invers' => function (Mediu $m): void {
        $fara = sodium_php_fara_extensie();
        if ($fara === null) {
            return;
        }
        $v = SODIUM_VECTORI;
        $d = $m->dosarTemporar();
        // Cheia-mamă scrisă de părinte, ca la trecerea de verificări din instalator; copilul o citește din fișier.
        $chei = Chei::genereaza("$d/cheie.bin");
        $cutie = new Cutie($chei->subcheie('pii'));
        $r = sodium_copil($m, $fara, 'define("CAZO", true); require ' . var_export(Mediu::radacina() . '/app/incarca.php', true) . ';' . <<<'PHP'

            use Cazo\Securitate\Chei;
            use Cazo\Securitate\Cutie;

            $chei = Chei::dinFisier($date['cheie']);
            $cutie = new Cutie($chei->subcheie('pii'));
            $noua = Chei::genereaza($date['cheie_noua']);
            $alterat = false;
            try {
                $cutie->decripteaza(substr($date['criptat'], 0, -2) . (substr($date['criptat'], -2, 1) === 'A' ? 'B' : 'A') . substr($date['criptat'], -1));
            } catch (\RuntimeException) {
                $alterat = true;
            }
            echo json_encode([
                'ext' => extension_loaded('sodium'),
                'pii' => bin2hex($chei->subcheie('pii')),
                'text' => $cutie->decripteaza($date['criptat']),
                'vector' => $cutie->decripteaza($date['vector_cutie_noua']),
                'alterat' => $alterat,
                'criptat' => $cutie->cripteaza('răspunsul copilului'),
                'noua_pii' => bin2hex($noua->subcheie('pii')),
                'noua_criptat' => (new Cutie($noua->subcheie('secrete')))->cripteaza('cu cheia făcută fără extensie'),
            ]);
            PHP, [
            'cheie' => "$d/cheie.bin", 'cheie_noua' => "$d/cheie-noua.bin", 'criptat' => $cutie->cripteaza('scris de părinte: ' . $v['text']),
            'vector_cutie_noua' => (new Cutie($chei->subcheie('pii')))->cripteaza($v['secret_text']),
        ]);
        fals($r['ext'], 'copilul a rulat cu extensia');
        egal(bin2hex($chei->subcheie('pii')), $r['pii']);
        egal('scris de părinte: ' . $v['text'], $r['text']);
        egal($v['secret_text'], $r['vector']);
        adevarat($r['alterat'], 'textul alterat s-a deschis');
        egal('răspunsul copilului', $cutie->decripteaza($r['criptat']));
        // Cheia-mamă făcută fără extensie (trecerea de verificări din instalator, pe o asemenea găzduire) se citește la fel cu extensia.
        $noua = Chei::dinFisier("$d/cheie-noua.bin");
        egal(32, filesize("$d/cheie-noua.bin"));
        egal(bin2hex($noua->subcheie('pii')), $r['noua_pii']);
        egal('cu cheia făcută fără extensie', (new Cutie($noua->subcheie('secrete')))->decripteaza($r['noua_criptat']));
    },

    'un PHP fără extensia sodium: după redenumirea dosarului privat în mijlocul cererii, încărcătorul inclus de la calea nouă găsește clasele bibliotecii' => function (Mediu $m): void {
        $fara = sodium_php_fara_extensie();
        if ($fara === null) {
            return;
        }
        $v = SODIUM_VECTORI;
        $d = $m->dosarTemporar();
        // O lansare minimă: încărcătorul, funcțiile păzite și biblioteca.
        mkdir("$d/vechi/app/Nucleu", 0755, true);
        copy(Mediu::radacina() . '/app/incarca.php', "$d/vechi/app/incarca.php");
        copy(Mediu::radacina() . '/app/Nucleu/Functii.php', "$d/vechi/app/Nucleu/Functii.php");
        sodium_copiaza(Mediu::radacina() . '/vendor/paragonie/sodium_compat', "$d/vechi/vendor/paragonie/sodium_compat");
        $r = sodium_copil($m, $fara, <<<'PHP'
            define('CAZO', true);
            require $date['dir'] . '/vechi/app/incarca.php';
            // Doar derivarea cheilor, ca la începutul unei cereri: clasele semnăturilor rămân neîncărcate.
            $r = ['kdf' => bin2hex(sodium_crypto_kdf_derive_from_key(32, 2, 'PragulV1', str_repeat("\x42", 32))),
                'ed_inainte' => class_exists('ParagonIE_Sodium_Core_Ed25519', false)];
            rename($date['dir'] . '/vechi', $date['dir'] . '/nou');
            require $date['dir'] . '/nou/app/incarca.php';
            $r['verificat'] = sodium_crypto_sign_verify_detached(base64_decode($date['sig'], true), $date['mesaj'], base64_decode($date['pk'], true));
            $r['ed_dupa'] = class_exists('ParagonIE_Sodium_Core_Ed25519', false);
            $nonce = str_repeat("\x07", 24);
            $r['cutie'] = sodium_crypto_secretbox_open(sodium_crypto_secretbox('text', $nonce, hex2bin($r['kdf'])), $nonce, hex2bin($r['kdf']));
            echo json_encode($r);
            PHP, ['dir' => $d, 'sig' => substr(trim($v['sig']), 17), 'pk' => $v['pk'], 'mesaj' => sodium_mesaj_vector($v['manifest'])]);
        egal(['kdf' => $v['pii'], 'ed_inainte' => false, 'verificat' => true, 'ed_dupa' => true, 'cutie' => 'text'], $r);
    },
];
