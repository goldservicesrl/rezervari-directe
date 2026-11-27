<?php

declare(strict_types=1);

// Un server `php -S` pornit de test, pe un port liber, oprit prin propriul proces (fără pkill).
// Indicatorii suplimentari pentru PHP-ul copil (de exemplu extensia pdo_mysql) vin din CAZO_TEST_PHP_ARGS.

defined('CAZO') or exit;

final class ServerTest
{
    /** @var resource */
    private $proces;

    private function __construct($proces, public readonly int $port, public readonly string $jurnal)
    {
        $this->proces = $proces;
    }

    public static function portLiber(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($s === false) {
            throw new \RuntimeException('Niciun port liber: ' . $errstr);
        }
        $nume = stream_socket_get_name($s, false);
        fclose($s);
        return (int) substr((string) $nume, strrpos((string) $nume, ':') + 1);
    }

    /** @param ?string $router scriptul router (cu rescriere) sau null (fără rescriere) */
    /** @param array<string,string> $env variabile de mediu în plus pentru server (de exemplu dosarul serverului fals) */
    public static function porneste(string $docroot, ?string $router, ?int $port = null, array $env = []): self
    {
        $port ??= self::portLiber();
        $cmd = [PHP_BINARY];
        $extra = trim((string) getenv('CAZO_TEST_PHP_ARGS'));
        if ($extra !== '') {
            foreach (preg_split('~\s+~', $extra) as $a) {
                $cmd[] = $a;
            }
        }
        array_push($cmd, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $docroot);
        if ($router !== null) {
            $cmd[] = $router;
        }
        $jurnal = tempnam(sys_get_temp_dir(), 'cazo-srv-');
        $mediu = $env === [] ? null : $env + getenv();
        $proces = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $jurnal, 'a'], 2 => ['file', $jurnal, 'a']], $pipes, null, $mediu);
        if (!is_resource($proces)) {
            throw new \RuntimeException('php -S nu a pornit.');
        }
        $s = new self($proces, $port, $jurnal);
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
        throw new \RuntimeException('php -S nu răspunde pe portul ' . $port . ': ' . @file_get_contents($jurnal));
    }

    public function url(string $cale = '/'): string
    {
        return 'http://127.0.0.1:' . $this->port . $cale;
    }

    /**
     * @return array{status:int, antete:array<string,string>, corp:string}
     */
    public function cerere(string $metoda, string $cale, array $antete = [], ?string $corp = null): array
    {
        $h = curl_init($this->url($cale));
        $primite = [];
        $linii = [];
        foreach ($antete as $k => $v) {
            $linii[] = $k . ': ' . $v;
        }
        curl_setopt_array($h, [
            CURLOPT_CUSTOMREQUEST => $metoda,
            CURLOPT_NOBODY => $metoda === 'HEAD',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $linii,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$primite): int {
                $p = explode(':', $linie, 2);
                if (count($p) === 2) {
                    $primite[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($linie);
            },
        ]);
        if ($corp !== null) {
            curl_setopt($h, CURLOPT_POSTFIELDS, $corp);
        }
        $rasp = curl_exec($h);
        // Serverul e alt proces și poate schimba fișiere (de exemplu redenumirea dosarului privat): cache-ul stat al
        // PHP (ultimul is_dir/is_file) se golește, altfel o verificare repetată vede starea de dinaintea cererii.
        // Înainte de PHP 8.3.19 (bug #72666), deschiderea fișierelor din proc_open nu îl golea, deci pe PHP 8.3.6
        // is_dir întorcea starea veche; după, testele treceau doar datorită acestui efect secundar.
        clearstatcache(true);
        if ($rasp === false) {
            throw new \RuntimeException('Cerere eșuată: ' . curl_error($h));
        }
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        // Timpul până la primul octet, în milisecunde (bugetul TTFB).
        $ttfb = (float) curl_getinfo($h, CURLINFO_STARTTRANSFER_TIME) * 1000;
        return ['status' => $status, 'antete' => $primite, 'corp' => (string) $rasp, 'ttfb' => $ttfb];
    }

    public function opreste(): void
    {
        if (is_resource($this->proces)) {
            proc_terminate($this->proces);
            proc_close($this->proces);
        }
        @unlink($this->jurnal);
    }

    public function __destruct()
    {
        $this->opreste();
    }
}
