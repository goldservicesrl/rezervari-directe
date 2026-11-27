<?php

declare(strict_types=1);

namespace Cazo\Sistem;

defined('CAZO') or exit;

/**
 * Adăugarea unui domeniu în cPanel-ul găzduirii, din site, fără parole („Adresa site-ului”).
 *
 * Calea sigură și uzuală: comanda `uapi` a cPanel-ului, rulată de PHP ca utilizatorul contului (PHP-ul de pe cPanel rulează
 * cu utilizatorul contului: suEXEC, PHP-FPM sau LSAPI), deci cPanel o autentifică după utilizatorul Unix, fără parolă și fără
 * token. Merge doar unde găzduirea lasă PHP-ul să pornească procese (`proc_open` în afara `disable_functions`) și unde
 * comanda există (pe CloudLinux cu CageFS, prin proxyexec). Altfel gazda face pașii de mână.
 *
 *  - domeniul se adaugă ca „addon domain” (`AddonDomain::addaddondomain`) cu dosarul web al site-ului de acum, deci
 *    aceeași instalare răspunde pe ambele adrese până la trecere; un cPanel mai vechi, fără funcția în UAPI, primește
 *    aceeași cerere prin `cpapi2`;
 *  - un domeniu deja adăugat (în același cont) e un succes; cPanel refuză singur un domeniu al altui cont;
 *  - apoi o verificare AutoSSL (`SSL::start_autossl_check`), ca certificatul adresei noi să vină mai repede;
 *  - argumentele merg ca listă la `proc_open` (fără shell), validate înainte; ieșirea e citită cel mult 64 KB, 30 de secunde.
 */
final class Cpanel
{
    public const UAPI = ['/usr/local/cpanel/bin/uapi', '/usr/bin/uapi'];
    public const CPAPI2 = ['/usr/local/cpanel/bin/cpapi2', '/usr/bin/cpapi2'];
    public const TIMP = 30;

    /**
     * @param ?\Closure(list<string>): ?array{cod:int, iesire:string} $ruleaza comanda (testele dau un cPanel fals); null = proc_open
     * @param ?\Closure(string): bool $exista dacă o comandă există (testele); null = is_executable
     */
    public function __construct(private ?\Closure $ruleaza = null, private ?\Closure $exista = null, private ?bool $procese = null)
    {
    }

    /** PHP poate porni procese: `proc_open` există și găzduirea nu l-a oprit. */
    public function procesePermise(): bool
    {
        if ($this->procese !== null) {
            return $this->procese;
        }
        if (!function_exists('proc_open')) {
            return false;
        }
        $oprite = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
        return !in_array('proc_open', $oprite, true);
    }

    private function gaseste(array $cai): ?string
    {
        foreach ($cai as $c) {
            if ($this->exista !== null ? ($this->exista)($c) : @is_executable($c)) {
                return $c;
            }
        }
        return null;
    }

    /** Varianta automată e posibilă: procese permise și comanda `uapi` (sau `cpapi2`) pe server. */
    public function disponibil(): bool
    {
        return $this->procesePermise() && ($this->gaseste(self::UAPI) !== null || $this->gaseste(self::CPAPI2) !== null);
    }

    /**
     * Adaugă $domeniu ca addon domain, cu dosarul web $dir (relativ la dosarul contului) și eticheta $eticheta.
     * @return array{ok:bool, motiv:string} motiv: adaugat | exista | indisponibil | refuzat | eroare
     */
    public function adaugaDomeniul(string $domeniu, string $eticheta, string $dir): array
    {
        if (!preg_match('~^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$~D', $domeniu)
            || !preg_match('~^[a-z0-9]{1,40}$~D', $eticheta) || !preg_match('~^[A-Za-z0-9._/-]{1,255}$~D', $dir) || str_contains($dir, '..')) {
            return ['ok' => false, 'motiv' => 'eroare'];
        }
        if (!$this->procesePermise()) {
            return ['ok' => false, 'motiv' => 'indisponibil'];
        }
        $arg = ['newdomain=' . $domeniu, 'subdomain=' . $eticheta, 'dir=' . $dir];
        $uapi = $this->gaseste(self::UAPI);
        if ($uapi !== null) {
            $r = $this->uapi($uapi, 'AddonDomain', 'addaddondomain', $arg);
            if ($r['motiv'] !== 'necunoscuta') {
                return $r;
            }
        }
        $cpapi2 = $this->gaseste(self::CPAPI2);
        if ($cpapi2 === null) {
            return ['ok' => false, 'motiv' => 'indisponibil'];
        }
        $x = $this->porneste(array_merge([$cpapi2, '--output=json', 'AddonDomain', 'addaddondomain'], $arg));
        if ($x === null) {
            return ['ok' => false, 'motiv' => 'eroare'];
        }
        $j = json_decode($x['iesire'], true);
        $rez = is_array($j) ? ($j['cpanelresult']['data'][0] ?? null) : null;
        if (is_array($rez) && (int) ($rez['result'] ?? 0) === 1) {
            return ['ok' => true, 'motiv' => 'adaugat'];
        }
        $text = is_array($j) ? (string) ($rez['reason'] ?? $j['cpanelresult']['error'] ?? '') : '';
        return self::refuz($text);
    }

    /** Pornește o verificare AutoSSL a contului (certificatul adresei noi); erorile rămân aici. */
    public function pornesteAutoSsl(): bool
    {
        $uapi = $this->gaseste(self::UAPI);
        if ($uapi === null || !$this->procesePermise()) {
            return false;
        }
        return $this->uapi($uapi, 'SSL', 'start_autossl_check', [])['ok'];
    }

    /** @return array{ok:bool, motiv:string} motiv `necunoscuta`: UAPI-ul serverului n-are funcția (un cPanel mai vechi) */
    private function uapi(string $bin, string $modul, string $functie, array $arg): array
    {
        $x = $this->porneste(array_merge([$bin, '--output=json', $modul, $functie], $arg));
        if ($x === null) {
            return ['ok' => false, 'motiv' => 'eroare'];
        }
        $j = json_decode($x['iesire'], true);
        $rez = is_array($j) ? ($j['result'] ?? null) : null;
        if (!is_array($rez)) {
            return ['ok' => false, 'motiv' => str_contains(strtolower($x['iesire']), 'failed to load module') ? 'necunoscuta' : 'eroare'];
        }
        if ((int) ($rez['status'] ?? 0) === 1) {
            return ['ok' => true, 'motiv' => 'adaugat'];
        }
        $text = implode(' ', array_map('strval', (array) ($rez['errors'] ?? [])));
        if (preg_match('~failed to load module|unknown (function|module)|could not find function~i', $text)) {
            return ['ok' => false, 'motiv' => 'necunoscuta'];
        }
        return self::refuz($text);
    }

    /** @return array{ok:bool, motiv:string} */
    private static function refuz(string $text): array
    {
        // Domeniul există deja în cont: adăugat la o încercare de dinainte.
        if (preg_match('~already (exists|been|configured)|exists already|is already~i', $text)) {
            return ['ok' => true, 'motiv' => 'exista'];
        }
        return ['ok' => false, 'motiv' => 'refuzat'];
    }

    /** @param list<string> $argv @return ?array{cod:int, iesire:string} */
    private function porneste(array $argv): ?array
    {
        if ($this->ruleaza !== null) {
            return ($this->ruleaza)($argv);
        }
        $p = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
        if (!is_resource($p)) {
            return null;
        }
        fclose($pipe[0]);
        stream_set_blocking($pipe[1], false);
        stream_set_blocking($pipe[2], false);
        $iesire = '';
        $pana = microtime(true) + self::TIMP;
        while (microtime(true) < $pana) {
            $iesire .= (string) stream_get_contents($pipe[1], 65536 - strlen($iesire));
            stream_get_contents($pipe[2]);
            $s = proc_get_status($p);
            if (!$s['running'] || strlen($iesire) >= 65536) {
                break;
            }
            usleep(100000);
        }
        $iesire .= (string) stream_get_contents($pipe[1], max(0, 65536 - strlen($iesire)));
        fclose($pipe[1]);
        fclose($pipe[2]);
        $s = proc_get_status($p);
        if ($s['running']) {
            proc_terminate($p);
        }
        $cod = proc_close($p);
        return ['cod' => $s['running'] ? -1 : (int) ($s['exitcode'] >= 0 ? $s['exitcode'] : $cod), 'iesire' => $iesire];
    }
}
