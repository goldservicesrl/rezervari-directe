<?php

declare(strict_types=1);

namespace Cazo\Sistem;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Functii;

defined('CAZO') or exit;

/**
 * Copiile de siguranță ale bazei (folderul `copii/`, panoul › Sistem): aceeași formă pe MySQL și pe SQLite,
 * un fișier gzip cu rânduri JSON: antetul (versiunea programului, migrările aplicate, tabelele), apoi, pe fiecare tabel,
 * o linie cu coloanele și câte o linie pe rând. Secretele și datele personale rămân criptate, ca în bază (cheia-mamă
 * stă în afara copiei), deci copia se restaurează doar pe același site.
 *
 * Copia zilnică automată (sarcina `copii:zilnic`) păstrează ultimele 7; copiile făcute din panou, ultimele 5.
 * Restaurarea înlocuiește conținutul tabelelor cu cel din copie, într-o tranzacție, după o copie de siguranță a stării de
 * acum („inainte-restaurare”); schema rămâne cea curentă (migrările sunt aditive, deci coloanele noi își păstrează
 * valoarea implicită). Rămân pe loc: sesiunile panoului, limitele, zăvoarele, totalurile ținute în cache, tabelul
 * migrărilor și tabelele din PE_LOC (utilizatorii, codurile de rezervă, jetoanele, auditul, secretele). O copie făcută de o versiune mai nouă (cu migrări necunoscute aici) se refuză.
 */
final class Copii
{
    public const PASTRATE = ['zilnica' => 7, 'copie' => 5, 'inainte' => 3];
    /** Tabelele care nu intră în copie (starea de lucru: sesiunile, limitele, zăvoarele, cache-ul, migrările). */
    public const FARA = ['sesiuni_admin', 'limite', 'zavoare', 'totaluri', 'migrari'];

    /**
     * Tabelele care rămân pe loc la restaurare, deși intră în copie (descărcarea le păstrează): conturile și dovezile
     * lor. O restaurare nu reactivează un cont dezactivat, nu readuce o parolă, un cod de rezervă sau un jeton vechi,
     * nu rescrie auditul și nu întoarce o cheie rotită. Ștergerile cerute de oaspeți și trimiterea lor la
     * EasyCheck rămân și ele: o restaurare nu readuce o ștergere netrimisă și nu o trimite a doua oară.
     */
    public const PE_LOC = ['utilizatori', 'coduri_rezerva', 'jetoane_utilizator', 'audit', 'secrete', 'gdpr_stergeri', 'gdpr_easycheck'];
    private const FORMAT = 1;
    /** Vârsta maximă a oricărei copii (zile). */
    public const ZILE_MAXIM = 7;

    public function __construct(private Baza $baza, private Ceas $ceas, private string $director, private string $versiune, private ?string $dirPublic = null)
    {
    }

    public static function numeValid(string $nume): bool
    {
        return (bool) preg_match('~^(zilnica|copie|inainte)-\d{8}-\d{6}-[a-z0-9]{6}\.json\.gz$~', $nume);
    }

    /** @return list<array{nume:string, fel:string, marime:int, la:int}> cele mai noi întâi */
    public function lista(): array
    {
        $r = [];
        foreach (glob($this->director . '/*.json.gz') ?: [] as $f) {
            $n = basename($f);
            if (self::numeValid($n)) {
                $r[] = ['nume' => $n, 'fel' => explode('-', $n, 2)[0], 'marime' => (int) filesize($f), 'la' => (int) filemtime($f)];
            }
        }
        usort($r, static fn (array $a, array $b): int => strcmp($b['nume'], $a['nume']) ?: $b['la'] <=> $a['la']);
        return $r;
    }

    public function cale(string $nume): ?string
    {
        if (!self::numeValid($nume)) {
            return null;
        }
        $f = $this->director . '/' . $nume;
        return is_file($f) ? $f : null;
    }

    /** O copie nouă; întoarce numele fișierului. Copiile mai vechi de același fel, peste limită, se șterg. */
    public function creeaza(string $fel = 'copie'): string
    {
        if (!isset(self::PASTRATE[$fel])) {
            throw new \InvalidArgumentException('Fel de copie necunoscut.');
        }
        if (!is_dir($this->director)) {
            @mkdir($this->director, 0700, true);
        }
        $nume = $fel . '-' . gmdate('Ymd-His', $this->ceas->acum()) . '-' . substr(bin2hex(random_bytes(4)), 0, 6) . '.json.gz';
        $tmp = $this->director . '/.' . $nume . '.tmp';
        $vechi = Functii::umask(0077);
        try {
            $gz = gzopen($tmp, 'wb6');
            if ($gz === false) {
                throw new \RuntimeException('Copia nu se poate scrie în dosarul copiilor.');
            }
            $tabele = array_values(array_filter($this->baza->tabele(), static fn (string $t): bool => !in_array($t, self::FARA, true)));
            sort($tabele);
            $migrari = array_map('strval', array_column($this->baza->toate('SELECT versiune FROM {migrari} ORDER BY versiune'), 'versiune'));
            gzwrite($gz, self::linie(['cazo_copie' => self::FORMAT, 'versiune' => $this->versiune, 'migrari' => $migrari, 'tabele' => $tabele,
                'dialect' => $this->baza->dialect()->nume(), 'creat_la' => $this->ceas->moment(), 'media' => $this->shaIncarcate()]));
            foreach ($tabele as $t) {
                $st = $this->baza->executa('SELECT * FROM ' . $this->baza->t($t));
                $coloane = null;
                while (($r = $st->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    if ($coloane === null) {
                        $coloane = array_keys($r);
                        gzwrite($gz, self::linie(['t' => $t, 'c' => $coloane]));
                    }
                    gzwrite($gz, self::linie(['v' => array_map(self::valoare(...), array_values($r))]));
                }
                if ($coloane === null) {
                    gzwrite($gz, self::linie(['t' => $t, 'c' => []]));
                }
            }
            gzclose($gz);
            Functii::chmod($tmp, 0600);
            rename($tmp, $this->director . '/' . $nume);
        } finally {
            Functii::restabilesteUmask($vechi);
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        $this->roteste($fel);
        // Fișierele pozelor încărcate merg în depozitul media, o singură dată pe fișier, cu bugetul de timp al rulării.
        $this->copiazaMedia(20.0);
        return $nume;
    }

    /**
     * Derivatele pozelor încărcate de gazdă nu intră în fiecare copie a bazei: stau o singură dată, în
     * depozitul `copii/media/<sha>/`, copiate incremental (un fișier deja copiat nu se mai copiază). Fiecare copie a
     * bazei ține în antet lista pozelor încărcate (`media`); depozitul păstrează doar pozele cerute de bază sau de o copie
     * păstrată. O poză încărcată are 4-12 derivate, de obicei 0,5-2 MB în total, deci depozitul crește cu pozele, nu cu
     * numărul copiilor.
     * @return list<string> amprentele pozelor încărcate, din baza de acum
     */
    private function shaIncarcate(): array
    {
        if (!$this->baza->coloanaExista('poze_local', 'incarcata')) {
            return [];
        }
        return array_values(array_filter(array_map('strval', array_column($this->baza->toate('SELECT DISTINCT sha256 FROM {poze_local} WHERE incarcata = 1 ORDER BY sha256'), 'sha256')),
            static fn (string $x): bool => preg_match('~^[0-9a-f]{64}$~', $x) === 1));
    }

    public static function fisierPozaValid(string $rel): bool
    {
        return (bool) preg_match('~^media/[0-9a-f]{64}/[0-9]{2,5}\.(jpg|webp|avif)$~', $rel);
    }

    /**
     * Copiază în depozit fișierele pozelor încărcate, care lipsesc acolo, cât ține bugetul (secunde); restul la rularea
     * următoare (curățenia orară). Curăță din depozit pozele pe care nu le mai cere nici baza, nici o copie păstrată.
     * @return array{copiate:int, gata:bool}
     */
    public function copiazaMedia(float $buget = 20.0): array
    {
        if ($this->dirPublic === null) {
            return ['copiate' => 0, 'gata' => true];
        }
        $start = microtime(true);
        $n = 0;
        foreach ($this->shaIncarcate() as $sha) {
            foreach (glob($this->dirPublic . '/media/' . $sha . '/*') ?: [] as $f) {
                $rel = 'media/' . $sha . '/' . basename($f);
                $tinta = $this->director . '/' . $rel;
                if (!self::fisierPozaValid($rel) || is_file($tinta)) {
                    continue;
                }
                if (microtime(true) - $start > $buget) {
                    return ['copiate' => $n, 'gata' => false];
                }
                if (!is_dir(dirname($tinta))) {
                    @mkdir(dirname($tinta), 0700, true);
                }
                if (@copy($f, $tinta . '.tmp') && @rename($tinta . '.tmp', $tinta)) {
                    $n++;
                }
            }
        }
        $cerute = array_flip($this->shaIncarcate());
        foreach ($this->lista() as $c) {
            try {
                foreach ((array) ($this->antet($this->director . '/' . $c['nume'])['media'] ?? []) as $sha) {
                    $cerute[(string) $sha] = true;
                }
            } catch (\RuntimeException) {
            }
        }
        foreach (glob($this->director . '/media/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (!isset($cerute[basename($d)])) {
                foreach (glob($d . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($d);
            }
        }
        return ['copiate' => $n, 'gata' => true];
    }

    /** Mărimea depozitului media, în octeți. */
    public function marimeMedia(): int
    {
        $t = 0;
        foreach (glob($this->director . '/media/*/*') ?: [] as $f) {
            $t += (int) @filesize($f);
        }
        return $t;
    }

    /**
     * După restaurarea bazei (și după commit): fișierele pozelor încărcate cunoscute în baza restaurată, care lipsesc de pe
     * site, vin din depozit (sau din rândurile „f” ale copiilor mai vechi). Derivatele pozelor EasyCheck nu se ating.
     * @param array<string,string> $dinCopie rândurile „f” ale unei copii vechi: calea => conținutul
     */
    private function refaMedia(array $dinCopie): void
    {
        if ($this->dirPublic === null) {
            return;
        }
        foreach ($this->shaIncarcate() as $sha) {
            if ($this->baza->valoare('SELECT 1 FROM {poze} WHERE sha256 = ? AND url_sursa = ?', [$sha, 'local:incarcata']) === null) {
                continue;
            }
            $surse = [];
            foreach (glob($this->director . '/media/' . $sha . '/*') ?: [] as $f) {
                $surse['media/' . $sha . '/' . basename($f)] = (string) file_get_contents($f);
            }
            foreach ($dinCopie as $rel => $octeti) {
                if (str_starts_with($rel, 'media/' . $sha . '/')) {
                    $surse[$rel] ??= $octeti;
                }
            }
            foreach ($surse as $rel => $octeti) {
                $tinta = $this->dirPublic . '/' . $rel;
                if (!self::fisierPozaValid($rel) || is_file($tinta) || @getimagesizefromstring($octeti) === false) {
                    continue;
                }
                if (!is_dir(dirname($tinta))) {
                    @mkdir(dirname($tinta), 0755, true);
                }
                $tmp = $tinta . '.' . bin2hex(random_bytes(4)) . '.tmp';
                if (@file_put_contents($tmp, $octeti) !== false) {
                    @rename($tmp, $tinta);
                }
            }
        }
    }

    public function sterge(string $nume): bool
    {
        $f = $this->cale($nume);
        return $f !== null && @unlink($f);
    }

    /**
     * Restaurarea unei copii. Întoarce numele copiei de siguranță făcute înainte, sau aruncă la o copie nepotrivită.
     * @throws \RuntimeException
     */
    /**
     * Setările legate de secretele rămase pe loc (cheia EasyCheck, Stripe, SMTP) și setările de lucru ale panoului rămân
     * și ele pe loc la restaurare: altfel o copie veche ar descrie altă cheie decât cea salvată sau ar trimite parola SMTP
     * de acum spre serverul vechi.
     */
    public const SETARI_PE_LOC = ['ec.', 'stripe.', 'email.smtp', 'coduri_noi.', 'audit.verificare'];

    public static function setarePeLoc(string $cheie): bool
    {
        foreach (self::SETARI_PE_LOC as $p) {
            if ($cheie === $p || (str_ends_with($p, '.') && str_starts_with($cheie, $p))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param ?callable $inTranzactie lucrul de făcut în aceeași tranzacție, după restaurare (reanonimizarea comenzilor),
     *        ca un proces întrerupt să nu lase datele vechi la vedere
     */
    public function restaureaza(string $nume, ?callable $inTranzactie = null): string
    {
        $f = $this->cale($nume) ?? throw new \RuntimeException('copia_lipsa');
        $antet = $this->antet($f);
        $cunoscute = array_map('strval', array_column($this->baza->toate('SELECT versiune FROM {migrari}'), 'versiune'));
        if (array_diff((array) $antet['migrari'], $cunoscute) !== []) {
            throw new \RuntimeException('copia_noua');
        }
        $inainte = $this->creeaza('inainte');
        $existente = $this->baza->tabele();
        $dinCopie = [];
        $gz = gzopen($f, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('copia_citire');
        }
        try {
            try {
                $this->baza->tranzactie(function () use ($gz, $existente, $inTranzactie, &$dinCopie): void {
                    gzgets($gz);
                    $tabel = null;
                    $coloane = [];
                    $pastrate = [];
                    while (($l = gzgets($gz)) !== false) {
                        $x = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
                        if (isset($x['f'])) {
                            // Copiile din versiunea de dinainte aveau fișierele în ele; se scriu abia după commit.
                            $tabel = null;
                            $octeti = base64_decode((string) ($x['b64'] ?? ''), true);
                            if (is_string($octeti) && self::fisierPozaValid((string) $x['f'])) {
                                $dinCopie[(string) $x['f']] = $octeti;
                            }
                            continue;
                        }
                        if (isset($x['t'])) {
                            $tabel = in_array($x['t'], $existente, true) && !in_array($x['t'], self::FARA, true) && !in_array($x['t'], self::PE_LOC, true) ? (string) $x['t'] : null;
                            $coloane = (array) $x['c'];
                            if ($tabel === 'setari') {
                                // Setările care rămân pe loc se păstrează; restul se șterg, în pachete sub limita de parametri.
                                $deSters = array_values(array_filter(array_map('strval', array_column($this->baza->toate('SELECT cheie FROM {setari}'), 'cheie')), static fn (string $k): bool => !self::setarePeLoc($k)));
                                foreach (\Cazo\Baza\Baza::pachete($deSters) as $pachet) {
                                    $this->baza->executa('DELETE FROM {setari} WHERE cheie IN (' . implode(', ', array_fill(0, count($pachet), '?')) . ')', $pachet);
                                }
                                $pastrate = array_values(array_filter($coloane, fn (string $c): bool => $this->baza->coloanaExista('setari', $c)));
                            } elseif ($tabel !== null) {
                                $this->baza->executa('DELETE FROM ' . $this->baza->t($tabel));
                                // Doar coloanele care există și în schema de acum (o copie mai veche are mai puține).
                                $pastrate = array_values(array_filter($coloane, fn (string $c): bool => $this->baza->coloanaExista($tabel, $c)));
                            }
                            continue;
                        }
                        if ($tabel === null || !isset($x['v'])) {
                            continue;
                        }
                        $rand = array_combine($coloane, array_map(self::dinValoare(...), (array) $x['v']));
                        if ($tabel === 'setari' && self::setarePeLoc((string) ($rand['cheie'] ?? ''))) {
                            continue;
                        }
                        $this->baza->insereaza($tabel, array_intersect_key($rand, array_flip($pastrate)));
                    }
                    if ($inTranzactie !== null) {
                        $inTranzactie();
                    }
                });
            } catch (\JsonException | \ValueError | \TypeError) {
                // O copie stricată: tranzacția s-a întors, baza rămâne cum era.
                throw new \RuntimeException('copia_forma');
            }
        } finally {
            gzclose($gz);
        }
        $this->refaMedia($dinCopie);
        return $inainte;
    }

    /** Antetul unei copii (prima linie). @return array */
    public function antet(string $fisier): array
    {
        $gz = gzopen($fisier, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('copia_citire');
        }
        $l = gzgets($gz);
        gzclose($gz);
        $a = is_string($l) ? json_decode($l, true) : null;
        if (!is_array($a) || ($a['cazo_copie'] ?? $a['pragul_copie'] ?? null) !== self::FORMAT || !is_array($a['migrari'] ?? null)) {
            throw new \RuntimeException('copia_forma');
        }
        return $a;
    }

    private function roteste(string $fel): void
    {
        $ale = array_values(array_filter($this->lista(), static fn (array $c): bool => $c['fel'] === $fel));
        foreach (array_slice($ale, self::PASTRATE[$fel]) as $c) {
            @unlink($this->director . '/' . $c['nume']);
        }
        $this->curataVechi();
    }

    /**
     * Nicio copie nu trăiește mai mult de 7 zile, de niciun fel: datele șterse la cererea oaspetelui sau
     * anonimizate la termen dispar și din copii în cel mult 7 zile. Rulează la fiecare copie nouă și la
     * curățenia orară. Întoarce câte copii s-au șters.
     */
    public function curataVechi(): int
    {
        $n = 0;
        foreach ($this->lista() as $c) {
            // Momentul copiei e în numele ei (scris cu ceasul aplicației).
            preg_match('~-(\d{8}-\d{6})-~', $c['nume'], $x);
            $la = \DateTimeImmutable::createFromFormat('!Ymd-His', $x[1] ?? '', new \DateTimeZone('UTC'));
            if ($la !== false && $la->getTimestamp() < $this->ceas->acum() - self::ZILE_MAXIM * 86400) {
                $n += (int) @unlink($this->director . '/' . $c['nume']);
            }
        }
        return $n;
    }

    private static function linie(array $x): string
    {
        return json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** Textele care nu sunt UTF-8 (un blob) trec în base64, marcate. */
    private static function valoare(mixed $v): mixed
    {
        if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
            return ['b64' => base64_encode($v)];
        }
        return $v;
    }

    private static function dinValoare(mixed $v): mixed
    {
        if (is_array($v) && isset($v['b64'])) {
            return base64_decode((string) $v['b64'], true);
        }
        return $v;
    }
}
