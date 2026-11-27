<?php

declare(strict_types=1);

// Serverul SMTP fals (teste/fals/smtp.php) pornit de un test, oprit prin propriul proces; citirea mesajelor primite.

defined('CAZO') or exit;

final class FalsSmtp
{
    /** @var resource */
    private $proces;

    private function __construct($proces, public readonly int $port, public readonly string $dir)
    {
        $this->proces = $proces;
    }

    /** @param array<string, string> $optiuni opțiunile serverului (`tls`, `cert`, `auth`; teste/fals/smtp.php) */
    public static function porneste(string $dir, array $optiuni = []): self
    {
        $port = ServerTest::portLiber();
        $arg = [PHP_BINARY, Mediu::radacina() . '/teste/fals/smtp.php', '--port=' . $port, '--dir=' . $dir];
        foreach ($optiuni as $k => $v) {
            $arg[] = '--' . $k . '=' . $v;
        }
        $p = proc_open($arg,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
        $s = new self($p, $port, $dir);
        $limita = microtime(true) + 5;
        while (microtime(true) < $limita) {
            $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
            if ($c !== false) {
                fclose($c);
                return $s;
            }
            usleep(50_000);
        }
        $s->opreste();
        throw new \RuntimeException('Serverul SMTP fals nu a pornit.');
    }

    /** Setările SMTP ale Cazo spre acest server. */
    public function setari(): array
    {
        return ['gazda' => '127.0.0.1', 'port' => $this->port, 'securitate' => 'niciuna', 'utilizator' => 'proba'];
    }

    /** @return list<array{antete:array<string,string>, corp:string, brut:string}> mesajele primite, în ordine */
    public function mesaje(): array
    {
        $r = [];
        $f = glob($this->dir . '/*.eml') ?: [];
        sort($f);
        foreach ($f as $x) {
            $r[] = self::analizeaza((string) file_get_contents($x));
        }
        return $r;
    }

    /** Antetele (decodate MIME) și corpul text decodat (quoted-printable), plus atașamentele. */
    public static function analizeaza(string $brut): array
    {
        [$cap, $corp] = array_pad(preg_split("~\r?\n\r?\n~", $brut, 2), 2, '');
        $antete = [];
        foreach (preg_split("~\r?\n(?![ \t])~", $cap) as $l) {
            $p = explode(':', $l, 2);
            if (count($p) === 2) {
                $antete[strtolower(trim($p[0]))] = iconv_mime_decode(trim(preg_replace("~\r?\n[ \t]+~", ' ', $p[1])), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            }
        }
        $text = quoted_printable_decode($corp);
        $atasamente = [];
        if (preg_match_all('~Content-Type: text/calendar[^\r\n]*(?:\r?\n[^\r\n]+)*\r?\n\r?\n([A-Za-z0-9+/=\r\n]+)~', $corp, $m)) {
            foreach ($m[1] as $b) {
                $atasamente[] = base64_decode(preg_replace('~\s+~', '', $b));
            }
        }
        return ['antete' => $antete, 'corp' => $text, 'brut' => $brut, 'ics' => $atasamente];
    }

    public function opreste(): void
    {
        if (is_resource($this->proces)) {
            proc_terminate($this->proces);
            proc_close($this->proces);
        }
    }

    public function __destruct()
    {
        $this->opreste();
    }
}
