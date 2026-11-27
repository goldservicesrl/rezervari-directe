<?php

declare(strict_types=1);

namespace Cazo\Email;

defined('CAZO') or exit;

/**
 * Trimiterea e-mailurilor, cu clientul SMTP al nucleului, fără bibliotecă: SMTP cu datele din
 * panou (gazda, portul, securitatea, utilizatorul și parola, criptată în `secrete`), sau funcția mail() a găzduirii
 * când SMTP-ul lipsește. Mesajul îl scrie `Mime`.
 *
 * Securitatea: `ssl` = TLS de la conectare (de obicei portul 465); `tls` = STARTTLS cerut (portul 587; un server care nu
 * îl oferă oprește trimiterea); `niciuna` = fără TLS (doar serverele locale de probă); altă valoare = STARTTLS când
 * serverul îl oferă. Certificatul și numele serverului se verifică mereu, cu TLS 1.2 sau mai nou. Autentificarea:
 * AUTH PLAIN sau, când serverul oferă doar atât, AUTH LOGIN. Timeout de 15 secunde pe fiecare pas.
 *
 * @param array{gazda:string, port:int, securitate:string, utilizator:string}|null $smtp
 * @param array<string, mixed> $tls opțiuni în plus pentru contextul TLS (testele: `cafile` cu certificatul serverului de probă)
 */
final class TransportSmtp implements Transport
{
    public const TIMEOUT = 15;

    /** @var resource|null */
    private $conexiune = null;

    public function __construct(private ?array $smtp, private ?string $parola, private array $tls = [])
    {
    }

    public function nume(): string
    {
        return $this->smtp !== null ? 'smtp' : 'mail';
    }

    public function trimite(Mesaj $m): void
    {
        try {
            $this->smtp !== null ? $this->prinSmtp($m) : $this->prinMail($m);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Trimiterea e-mailului a eșuat: ' . $e->getMessage(), 0, $e);
        } finally {
            if (is_resource($this->conexiune)) {
                fclose($this->conexiune);
            }
            $this->conexiune = null;
        }
    }

    private function prinMail(Mesaj $m): void
    {
        $c = Mime::compune($m, null, true);
        $deLa = Mime::adresa($m->deLa);
        // -f pune expeditorul plicului; doar pentru o adresă fără caractere pe care shell-ul le-ar citi altfel.
        $param = preg_match('~^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+$~', $deLa) === 1 ? '-f' . $deLa : '';
        // Un singur fel de sfârșit de rând în tot ce primește mail() (subiectul, antetele, corpul, plierea antetelor), cel
        // pe care PHP îl pune singur după To, Subject și antete: amestecul (CRLF de la PHP, LF de la noi) face ca un MTA
        // care desparte rândurile doar la CRLF să lipească Date, From, MIME-Version și Content-Type într-un singur antet,
        // iar mesajul ajunge cu limitele MIME afișate în clar.
        $sep = self::separatorMail();
        $rand = static fn (string $s): string => preg_replace("~\r\n?|\n~", $sep, $s) ?? $s;
        $corp = $rand($c['corp']);
        $antete = $rand(implode("\r\n", $c['antete']));
        $subiect = $rand($c['subiect']);
        if (!@mail(Mime::adresa($m->catre), $subiect, $corp, $antete, $param)) {
            throw new \RuntimeException('funcția mail() a găzduirii a refuzat mesajul.');
        }
    }

    /**
     * Sfârșitul de rând pe care funcția mail() îl scrie ea însăși după To, Subject și antete: CRLF pe Windows și din PHP 8
     * (cu `mail.mixed_lf_and_crlf` oprit, cum e implicit), LF când `mail.mixed_lf_and_crlf` e pornit.
     */
    public static function separatorMail(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return "\r\n";
        }
        return filter_var(ini_get('mail.mixed_lf_and_crlf'), FILTER_VALIDATE_BOOLEAN) ? "\n" : "\r\n";
    }

    private function prinSmtp(Mesaj $m): void
    {
        $s = (array) $this->smtp;
        $gazda = trim((string) $s['gazda']);
        if ($gazda === '' || preg_match('~^[A-Za-z0-9.\-\[\]:]+$~', $gazda) !== 1) {
            throw new \RuntimeException('gazda SMTP nu e validă.');
        }
        $port = (int) $s['port'];
        $sec = (string) ($s['securitate'] ?? 'tls');
        $brut = Mime::brut($m);
        $deLa = Mime::adresa($m->deLa);
        $catre = Mime::adresa($m->catre);

        $ctx = stream_context_create(['ssl' => $this->tls + [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => trim($gazda, '[]'),
            'SNI_enabled' => true, 'crypto_method' => self::metodaTls(),
        ]]);
        $schema = $sec === 'ssl' ? 'tls' : 'tcp';
        $c = @stream_socket_client($schema . '://' . $gazda . ':' . $port, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
        if ($c === false) {
            throw new \RuntimeException('conectarea la ' . $gazda . ':' . $port . ' a eșuat (' . trim((string) $errstr) . ').');
        }
        $this->conexiune = $c;
        stream_set_timeout($c, self::TIMEOUT);
        $this->asteapta([220], 'salutul serverului');
        $ext = $this->ehlo();
        $criptat = $sec === 'ssl';
        if (!$criptat && $sec !== 'niciuna' && ($sec === 'tls' || isset($ext['STARTTLS']))) {
            if (!isset($ext['STARTTLS'])) {
                throw new \RuntimeException('serverul nu oferă STARTTLS (alegeți SSL sau alt port).');
            }
            $this->comanda('STARTTLS', [220]);
            if (@stream_socket_enable_crypto($c, true, self::metodaTls()) !== true) {
                throw new \RuntimeException('negocierea TLS a eșuat (certificatul sau numele serverului).');
            }
            $ext = $this->ehlo();
        }
        if (($s['utilizator'] ?? '') !== '') {
            $this->autentifica($ext, (string) $s['utilizator'], (string) $this->parola);
        }
        $this->comanda('MAIL FROM:<' . $deLa . '>', [250]);
        $this->comanda('RCPT TO:<' . $catre . '>', [250, 251]);
        $this->comanda('DATA', [354]);
        // Punctul de la începutul unui rând se dublează (RFC 5321 4.5.2); mesajul se încheie cu „.” pe un rând.
        $date = preg_replace('~^\.~m', '..', str_replace("\r\n", "\n", $brut)) ?? '';
        $this->scrie(str_replace("\n", "\r\n", rtrim($date, "\n")) . "\r\n.\r\n");
        $this->asteapta([250], 'primirea mesajului');
        try {
            $this->comanda('QUIT', [221]);
        } catch (\RuntimeException) {
            // Mesajul e primit; un server care închide fără „221” nu schimbă nimic.
        }
    }

    private static function metodaTls(): int
    {
        $m = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $m |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        return $m;
    }

    /** @return array<string, string> extensiile anunțate de server (cheia cu majuscule, restul rândului) */
    private function ehlo(): array
    {
        $r = $this->comanda('EHLO ' . self::numeClient(), [250]);
        $ext = [];
        foreach (array_slice($r, 1) as $l) {
            $p = explode(' ', strtoupper(trim($l)), 2);
            $ext[$p[0]] = $p[1] ?? '';
        }
        return $ext;
    }

    /** @param array<string, string> $ext */
    private function autentifica(array $ext, string $utilizator, string $parola): void
    {
        $metode = preg_split('~\s+~', $ext['AUTH'] ?? '') ?: [];
        if (in_array('PLAIN', $metode, true) || !in_array('LOGIN', $metode, true)) {
            $this->comanda('AUTH PLAIN ' . base64_encode("\0" . $utilizator . "\0" . $parola), [235], 'AUTH PLAIN');
            return;
        }
        $this->comanda('AUTH LOGIN', [334]);
        $this->comanda(base64_encode($utilizator), [334], 'utilizatorul');
        $this->comanda(base64_encode($parola), [235], 'parola');
    }

    /** Numele cu care clientul se prezintă (EHLO): numele serverului web sau al mașinii, altfel o adresă literală. */
    private static function numeClient(): string
    {
        foreach ([$_SERVER['SERVER_NAME'] ?? null, gethostname()] as $n) {
            if (is_string($n) && preg_match('~^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)+$~', $n) === 1) {
                return $n;
            }
        }
        return '[127.0.0.1]';
    }

    /**
     * Trimite o comandă și citește răspunsul; un cod neașteptat oprește trimiterea, cu răspunsul serverului (fără
     * comanda, care poate purta parola).
     *
     * @param list<int> $coduri
     * @return list<string>
     */
    private function comanda(string $cmd, array $coduri, ?string $eticheta = null): array
    {
        $this->scrie($cmd . "\r\n");
        return $this->asteapta($coduri, $eticheta ?? (string) preg_replace('~[:<].*$~s', '', $cmd));
    }

    private function scrie(string $date): void
    {
        $c = $this->conexiune;
        while ($date !== '') {
            $n = @fwrite($c, $date);
            if ($n === false || $n === 0) {
                throw new \RuntimeException('conexiunea SMTP s-a închis la scriere.');
            }
            $date = substr($date, $n);
        }
    }

    /**
     * Răspunsul serverului: rândurile „250-...” până la „250 ...”.
     *
     * @param list<int> $coduri
     * @return list<string> textele rândurilor, fără cod
     */
    private function asteapta(array $coduri, string $pas): array
    {
        $randuri = [];
        $cod = 0;
        while (true) {
            $l = fgets($this->conexiune, 4096);
            if ($l === false) {
                $meta = stream_get_meta_data($this->conexiune);
                throw new \RuntimeException(($meta['timed_out'] ?? false) ? 'serverul SMTP nu a răspuns la timp (' . $pas . ').' : 'conexiunea SMTP s-a închis (' . $pas . ').');
            }
            $l = rtrim($l, "\r\n");
            if (preg_match('~^(\d{3})([ \-])(.*)$~s', $l, $p) !== 1 && preg_match('~^(\d{3})()$~', $l, $p) !== 1) {
                throw new \RuntimeException('răspuns SMTP necunoscut (' . $pas . ').');
            }
            $cod = (int) $p[1];
            $randuri[] = $p[3] ?? '';
            if (($p[2] ?? ' ') !== '-') {
                break;
            }
        }
        if (!in_array($cod, $coduri, true)) {
            throw new \RuntimeException('serverul a răspuns ' . $cod . ' la ' . $pas . ': ' . mb_substr(implode(' ', $randuri), 0, 300));
        }
        return $randuri;
    }
}
