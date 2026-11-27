<?php

declare(strict_types=1);

// Funcția mail() a găzduirii, pentru email_mail_test.php, în două roluri:
//
//   php teste/fals/mail.php <mesaj.ser>         trimite Mesaj-ul serializat prin TransportSmtp fără SMTP (deci prin mail());
//   php teste/fals/mail.php --captura=<fișier>  e sendmail-ul: păstrează exact ce primește pe intrare (și argumentele).
//
// Testul pornește primul rol cu `-d sendmail_path=` spre al doilea, deci mesajul capturat e cel pe care l-ar primi
// sendmail-ul (Exim) al găzduirii.

$arg = $argv[1] ?? '';
if (str_starts_with($arg, '--captura=')) {
    $fisier = substr($arg, strlen('--captura='));
    file_put_contents($fisier, (string) stream_get_contents(STDIN));
    file_put_contents($fisier . '.args', implode("\n", array_slice($argv, 2)));
    exit(0);
}

define('CAZO', true);
require dirname(__DIR__, 2) . '/app/incarca.php';

$mesaj = unserialize((string) file_get_contents($arg), ['allowed_classes' => [\Cazo\Email\Mesaj::class]]);
if (!$mesaj instanceof \Cazo\Email\Mesaj) {
    fwrite(STDERR, "Mesajul serializat lipsește.\n");
    exit(2);
}
(new \Cazo\Email\TransportSmtp(null, null))->trimite($mesaj);
echo "trimis\n";
