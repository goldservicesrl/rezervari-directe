<?php

declare(strict_types=1);

// Construcția arhivei livrate: cazo-<versiune>.zip, reproductibilă.
//
//   php bin/lansare.php [--iesire=dist] [--fara-teste] [--versiune=1.0.1] [--cu-teme-private=slug,slug]
//                       [--note-ro="..."] [--note-en="..."] [--securitate]
//
// Lângă arhivă scrie versiune.json, fișierul de urcat pe https://cazo.ro/versiune.json după publicarea versiunii pe
// GitHub: site-urile îl citesc o dată pe zi și anunță gazda (panoul și e-mailul).
//
// --versiune dă altă versiune decât app/versiune.php.
// Temele private ("privata": true în theme.json) rămân în afara arhivei; --cu-teme-private le adaugă, pentru arhiva
// făcută site-ului căruia îi aparțin.
//
// Conținutul:
//   public_html/index.php, public_html/.htaccess, public_html/_c/.htaccess (din .htaccess-active)
//   cazo-privat/curent.php
//   cazo-privat/lansari/<versiune>/{app,vederi,limbi,migrari,teme,vendor,bin,public}/ + MANIFEST.json
// MANIFEST.json are SHA-256 pe fiecare fișier al lansării. Lansările nu se semnează și nu se publică automat:
// arhiva se urcă de mână în GitHub Releases, iar gazdele o descarcă de acolo. Testele și sursele front-end rămân în
// afara arhivei.
// Intrări sortate, permisiuni fixe; data fișierelor e data construirii (o dată fixă lăsa OPcache pe codul vechi
// la dezarhivarea peste o instalare mai veche). Cu SOURCE_DATE_EPOCH, arhiva e reproductibilă octet cu octet.

if (PHP_SAPI !== 'cli') {
    exit;
}

define('CAZO', true);
$radacina = dirname(__DIR__);
require $radacina . '/app/incarca.php';

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('~^--([a-z\-]+)(?:=(.*))?$~', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? true;
    }
}
$iesire = (string) ($opt['iesire'] ?? $radacina . '/dist');
if (!str_starts_with($iesire, '/')) {
    $iesire = getcwd() . '/' . $iesire;
}

try {
    $private = is_string($opt['cu-teme-private'] ?? null) ? array_values(array_filter(explode(',', $opt['cu-teme-private']))) : [];
    $zip = \Cazo\Dezvoltare\Lansare::construieste($radacina, $iesire, !isset($opt['fara-teste']), isset($opt['versiune']) ? (string) $opt['versiune'] : null, $private,
        ['ro' => is_string($opt['note-ro'] ?? null) ? $opt['note-ro'] : '', 'en' => is_string($opt['note-en'] ?? null) ? $opt['note-en'] : ''], isset($opt['securitate']));
} catch (\Throwable $e) {
    fwrite(STDERR, 'Lansarea a eșuat: ' . $e->getMessage() . "\n");
    exit(1);
}
echo $zip['cale'], "\n", 'sha256 ', $zip['sha256'], "\n", $zip['fisiere'], " fișiere\n", dirname($zip['cale']), "/versiune.json\n";
