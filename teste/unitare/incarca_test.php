<?php

declare(strict_types=1);

// Căile instalării: încărcătorul de clase verifică doar partea venită din numele clasei (o instalare poate sta într-un
// dosar cu spații), iar lansarea de dezvoltare se copiază unde găzduirea sau Windows refuză symlink().

use Cazo\Dezvoltare\Instanta;

return [
    'încărcătorul de clase: Twig se încarcă și dintr-o instalare cu spații în cale' => function (Mediu $m): void {
        $d = $m->dosarTemporar() . '/site nou (2026)/cazo-privat';
        mkdir("$d/app", 0700, true);
        copy(Mediu::radacina() . '/app/incarca.php', "$d/app/incarca.php");
        symlink(Mediu::radacina() . '/vendor', "$d/vendor");
        $cod = 'define("CAZO", true); require ' . var_export("$d/app/incarca.php", true) . '; '
            . 'echo class_exists("Twig\\\\Environment") ? "twig " : "fara-twig ", '
            . 'class_exists("Twig\\\\..\\\\x") ? "traversare" : "ok";';
        $iesire = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($cod) . ' 2>&1');
        // PHPMailer a ieșit: e-mailurile pleacă prin clientul SMTP al nucleului.
        egal('twig ok', trim($iesire));
    },

    'lansarea de dezvoltare: legătura simbolică sau, refuzată, copia dosarelor' => function (Mediu $m): void {
        $d = $m->dosarTemporar();
        egal('copie', Instanta::legaLansarea(Mediu::radacina(), "$d/dev", static fn (): bool => false));
        adevarat(!is_link("$d/dev"));
        foreach (['app/porneste.php', 'app/incarca.php', 'limbi/ro.php', 'front/css/admin.css', 'vendor/VENDOR.lock'] as $f) {
            adevarat(is_file("$d/dev/$f"), $f);
        }
        adevarat(!is_dir("$d/dev/teste") && !is_dir("$d/dev/docs"), 'copia are teste sau documentație');
        egal('legatura', Instanta::legaLansarea(Mediu::radacina(), "$d/dev2"));
        adevarat(is_link("$d/dev2"));
    },

];
