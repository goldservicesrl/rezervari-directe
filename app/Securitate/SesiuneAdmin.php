<?php

declare(strict_types=1);

namespace Cazo\Securitate;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Sesiunile panoului, în tabelul `sesiuni_admin`: cookie-ul `__Host-pg_admin` (Secure, HttpOnly,
 * SameSite=Strict, Path=/) poartă un jeton aleator de 32 de octeți; în bază stă doar SHA-256 al lui. Expirarea:
 * 30 de minute de inactivitate, 12 ore absolut. Limitele autentificării: 5 eșecuri pe cont în 15 minute,
 * 20 pe IP/24 pe oră. Un jeton nou la fiecare autentificare (fără fixare de sesiune).
 */
final class SesiuneAdmin
{
    public const COOKIE = '__Host-pg_admin';
    public const INACTIV = 1800;
    public const ABSOLUT = 43200;
    public const ESECURI_CONT = 5;
    public const ESECURI_IP = 20;

    public const RECONFIRMARI = 5;
    /**
     * Codurile 2FA greșite la oprire și la înlocuirea secretului (cod_vechi): 5 pe utilizator în 15 minute, ca la
     * intrare și la reconfirmarea parolei; altfel cine știe parola ar încerca toate codurile.
     */
    public const CODURI_2FA = 5;

    /** Ultima intrare reușită din acest proces a folosit un cod de rezervă în locul codului din aplicație. */
    public bool $intrareCuCodRezerva = false;

    /** Re-autentificarea pentru operațiile sensibile: parola confirmată cel mult acum 10 minute. */
    public const REAUTENTIFICARE = 600;

    /** Codurile de rezervă ale 2FA: 10, fiecare de 10 caractere, folosite o singură dată. */
    public const CODURI_REZERVA = 10;
    private const ALFABET_REZERVA = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(private Baza $baza, private Ceas $ceas, private Limitator $limitator, private ?Cutie $totp = null, private ?string $cheieCoduri = null)
    {
    }

    /**
     * Dreptul de rambursare: proprietarul îl are; un angajat (`personal`) doar când i l-a dat proprietarul.
     * Fiecare rambursare cere, în plus, reconfirmarea parolei (reconfirma).
     */
    public static function poateRambursa(array $u): bool
    {
        return ($u['rol'] ?? null) === 'proprietar' || (($u['rol'] ?? null) === 'personal' && (int) ($u['poate_rambursa'] ?? 0) === 1);
    }

    /**
     * Proprietarul dă sau ia unui angajat dreptul „Poate face rambursări”. Întoarce angajatul (email,
     * limba_panou, are_2fa) când dreptul abia i-a fost dat, pentru atenționarea de la primirea dreptului; altfel null.
     */
    public function dreptRambursare(int $utilizator, bool $da): ?array
    {
        $n = $this->baza->executa("UPDATE {utilizatori} SET poate_rambursa = ? WHERE id = ? AND rol = 'personal' AND poate_rambursa <> ?", [(int) $da, $utilizator, (int) $da])->rowCount();
        if ($n !== 1 || !$da) {
            return null;
        }
        return $this->baza->rand("SELECT email, limba_panou, CASE WHEN totp_secret IS NULL THEN 0 ELSE 1 END AS are_2fa FROM {utilizatori} WHERE id = ? AND activ = 1", [$utilizator]);
    }

    /** Reconfirmarea parolei la o operație sensibilă (fiecare rambursare), cu limita de 5 încercări în 15 minute. */
    public function reconfirma(int $utilizator, string $parola): bool
    {
        $cheie = 'admin:reconfirmare:' . $utilizator;
        // Încercarea se rezervă atomic înainte de verificare și se dă înapoi la succes (auditul de securitate.2026).
        if ($this->limitator->inregistreaza($cheie, 900) > self::RECONFIRMARI) {
            return false;
        }
        $h = $this->baza->valoare('SELECT parola_hash FROM {utilizatori} WHERE id = ? AND activ = 1', [$utilizator]);
        if (is_string($h) && Parole::verifica($parola, $h)) {
            $this->limitator->anuleaza($cheie, 900);
            return true;
        }
        return false;
    }

    /**
     * Activarea sau înlocuirea secretului 2FA (TOTP minim): secretul nou se salvează doar după un cod corect din
     * aplicație; peste un secret activ, doar cu un cod valid și din secretul vechi. Parola o reconfirmă apelantul.
     * Întoarce 'ok', 'cod' (codul secretului nou), 'cod_vechi' sau 'limita' (prea multe coduri vechi greșite).
     */
    public function activeazaTotp(int $utilizator, string $secret, string $cod, ?string $codVechi = null): string
    {
        if ($this->totp === null) {
            return 'cod';
        }
        $u = $this->baza->rand('SELECT id, totp_secret, totp_ultimul_pas FROM {utilizatori} WHERE id = ?', [$utilizator]);
        if ($u === null) {
            return 'cod';
        }
        // Ambele coduri se verifică întâi, fără să consume vreun pas; pasul vechi contează folosit doar la succes
        // (secretul vechi dispare oricum odată cu înlocuirea). Codurile vechi greșite intră în limita CODURI_2FA.
        $rezervaVeche = false;
        if ($u['totp_secret'] !== null) {
            if ($this->limitator->inregistreaza('admin:cod2fa:' . $utilizator, 900) > self::CODURI_2FA) {
                return 'limita';
            }
            if (Totp::verifica($this->totp->decripteaza((string) $u['totp_secret']), (string) $codVechi, $this->ceas->acum(),
                $u['totp_ultimul_pas'] !== null ? (int) $u['totp_ultimul_pas'] : null) === null) {
                // Telefonul vechi pierdut: un cod de rezervă ține locul codului din aplicația veche; se verifică acum
                // și se consumă doar la succes.
                $rezervaVeche = self::esteCodRezerva((string) $codVechi) && $this->baza->valoare('SELECT 1 FROM {coduri_rezerva} WHERE utilizator_id = ? AND cod_hash = ? AND folosit_la IS NULL',
                    [$utilizator, $this->amprentaCod((string) $codVechi)]) !== null;
                if (!$rezervaVeche) {
                    return 'cod_vechi';   // încercarea rezervată rămâne numărată
                }
            }
            $this->limitator->anuleaza('admin:cod2fa:' . $utilizator, 900);   // codul vechi a fost bun: încercarea se dă înapoi
        }
        $pas = Totp::verifica($secret, $cod, $this->ceas->acum());
        if ($pas === null) {
            return 'cod';
        }
        if ($rezervaVeche && !$this->folosesteCodRezerva($utilizator, (string) $codVechi)) {
            return 'cod_vechi';
        }
        $this->baza->executa('UPDATE {utilizatori} SET totp_secret = ?, totp_ultimul_pas = ? WHERE id = ?', [$this->totp->cripteaza($secret), $pas, $utilizator]);
        return 'ok';
    }

    /**
     * Un cod valid din aplicație (oprirea 2FA îl cere, pe lângă parolă); pasul se consumă. Un cod greșit intră în
     * limita CODURI_2FA; peste ea, niciun cod nu mai trece până se golește fereastra (coduriEpuizate).
     */
    public function codValid(int $utilizator, string $cod): bool
    {
        // Încercarea se rezervă atomic înainte de verificare și se dă înapoi la succes (auditul de securitate.2026).
        if ($this->limitator->inregistreaza('admin:cod2fa:' . $utilizator, 900) > self::CODURI_2FA) {
            return false;
        }
        $u = $this->baza->rand('SELECT id, totp_secret, totp_ultimul_pas FROM {utilizatori} WHERE id = ?', [$utilizator]);
        if ($u !== null && $u['totp_secret'] !== null && ($this->totpValid($u, $cod) || (self::esteCodRezerva($cod) && $this->folosesteCodRezerva($utilizator, $cod)))) {
            $this->limitator->anuleaza('admin:cod2fa:' . $utilizator, 900);
            return true;
        }
        return false;
    }

    /** Limita codurilor 2FA greșite (oprire și înlocuire) e atinsă pentru utilizator. */
    public function coduriEpuizate(int $utilizator): bool
    {
        return $this->limitator->numar('admin:cod2fa:' . $utilizator, 900) >= self::CODURI_2FA;
    }

    public function dezactiveazaTotp(int $utilizator): void
    {
        $this->baza->executa('UPDATE {utilizatori} SET totp_secret = NULL, totp_ultimul_pas = NULL WHERE id = ?', [$utilizator]);
        // Codurile de rezervă țin de 2FA: dispar odată cu ea.
        $this->baza->executa('DELETE FROM {coduri_rezerva} WHERE utilizator_id = ?', [$utilizator]);
    }

    // ---- codurile de rezervă ----

    /** Forma unui cod de rezervă: 10 caractere din alfabetul fără litere ușor de confundat, cu cratimă opțională. */
    public static function esteCodRezerva(string $cod): bool
    {
        return (bool) preg_match('~^[a-z2-9]{5}-?[a-z2-9]{5}$~', strtolower(trim(str_replace(' ', '', $cod))));
    }

    /**
     * Cele 10 coduri noi ale utilizatorului (cele vechi se șterg). Se arată o singură dată; în bază rămâne doar
     * amprenta HMAC a fiecăruia (subcheia „coduri_rezerva”, în afara bazei).
     * @return list<string> codurile, în forma „abcde-fghij”
     */
    public function coduriRezervaNoi(int $utilizator): array
    {
        $coduri = [];
        $max = strlen(self::ALFABET_REZERVA) - 1;
        for ($i = 0; $i < self::CODURI_REZERVA; $i++) {
            $c = '';
            for ($j = 0; $j < 10; $j++) {
                $c .= self::ALFABET_REZERVA[random_int(0, $max)];
            }
            $coduri[] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        $this->baza->tranzactie(function () use ($utilizator, $coduri): void {
            $this->baza->executa('DELETE FROM {coduri_rezerva} WHERE utilizator_id = ?', [$utilizator]);
            foreach ($coduri as $c) {
                $this->baza->insereaza('coduri_rezerva', ['utilizator_id' => $utilizator, 'cod_hash' => $this->amprentaCod($c), 'creat_la' => $this->ceas->moment()]);
            }
        });
        return $coduri;
    }

    /** Câte coduri de rezervă nefolosite are utilizatorul. */
    public function coduriRamase(int $utilizator): int
    {
        return (int) $this->baza->valoare('SELECT COUNT(*) FROM {coduri_rezerva} WHERE utilizator_id = ? AND folosit_la IS NULL', [$utilizator]);
    }

    /** Folosește un cod de rezervă: reușește o singură dată pe cod (UPDATE condiționat, deci și la două cereri simultane). */
    public function folosesteCodRezerva(int $utilizator, string $cod): bool
    {
        if (!self::esteCodRezerva($cod) || $this->cheieCoduri === null) {
            return false;
        }
        return $this->baza->executa('UPDATE {coduri_rezerva} SET folosit_la = ? WHERE utilizator_id = ? AND cod_hash = ? AND folosit_la IS NULL',
            [$this->ceas->moment(), $utilizator, $this->amprentaCod($cod)])->rowCount() === 1;
    }

    private function amprentaCod(string $cod): string
    {
        $c = str_replace(['-', ' '], '', strtolower(trim($cod)));
        return hash_hmac('sha256', $c, (string) $this->cheieCoduri);
    }

    // ---- re-autentificarea ----

    /** Id-ul utilizatorului sesiunii, fără să mute `vazut_la` (auditul cererilor panoului). */
    public function idDinJeton(?string $jeton): ?int
    {
        if ($jeton === null || !preg_match('~^[A-Za-z0-9_-]{43}$~', $jeton)) {
            return null;
        }
        $v = $this->baza->valoare('SELECT utilizator_id FROM {sesiuni_admin} WHERE id_hash = ?', [hash('sha256', $jeton)]);
        return $v !== null ? (int) $v : null;
    }

    /** Parola sesiunii a fost confirmată cel mult acum 10 minute (la intrare sau la o re-autentificare). */
    public function reautentificat(?string $jeton): bool
    {
        if ($jeton === null || !preg_match('~^[A-Za-z0-9_-]{43}$~', $jeton)) {
            return false;
        }
        $la = $this->baza->valoare('SELECT reautentificat_la FROM {sesiuni_admin} WHERE id_hash = ?', [hash('sha256', $jeton)]);
        return is_string($la) && Ceas::dinMoment($la) + self::REAUTENTIFICARE > $this->ceas->acum();
    }

    /**
     * Operația sensibilă trece când sesiunea e re-autentificată de curând sau când parola dată acum e corectă; parola
     * corectă mută `reautentificat_la`, deci următoarele operații din 10 minute trec fără ea.
     */
    public function cereReautentificare(?string $jeton, int $utilizator, ?string $parola): bool
    {
        if ($this->reautentificat($jeton)) {
            return true;
        }
        if ($jeton === null || $parola === null || $parola === '' || !$this->reconfirma($utilizator, $parola)) {
            return false;
        }
        $this->baza->executa('UPDATE {sesiuni_admin} SET reautentificat_la = ? WHERE id_hash = ? AND utilizator_id = ?', [$this->ceas->moment(), hash('sha256', $jeton), $utilizator]);
        return true;
    }

    // ---- sesiunile utilizatorului (14 Securitate) ----

    /** @return list<array{curenta:bool, creat_la:string, vazut_la:string, ip24:string, agent_scurt:string}> */
    public function sesiuni(int $utilizator, ?string $jetonCurent): array
    {
        $curent = $jetonCurent !== null ? hash('sha256', $jetonCurent) : '';
        $r = [];
        foreach ($this->baza->toate('SELECT * FROM {sesiuni_admin} WHERE utilizator_id = ? ORDER BY vazut_la DESC', [$utilizator]) as $s) {
            $r[] = ['curenta' => hash_equals((string) $s['id_hash'], $curent), 'creat_la' => (string) $s['creat_la'], 'vazut_la' => (string) $s['vazut_la'],
                'ip24' => (string) $s['ip24'], 'agent_scurt' => (string) $s['agent_scurt']];
        }
        return $r;
    }

    /** „Închideți celelalte sesiuni”: rămâne doar sesiunea curentă. Întoarce câte s-au închis. */
    public function inchideCelelalte(int $utilizator, ?string $jetonCurent): int
    {
        return $this->baza->executa('DELETE FROM {sesiuni_admin} WHERE utilizator_id = ? AND id_hash <> ?', [$utilizator, $jetonCurent !== null ? hash('sha256', $jetonCurent) : ''])->rowCount();
    }

    /** Toate sesiunile unui utilizator (dezactivarea, schimbarea rolului, parola resetată). */
    public function inchideToate(int $utilizator): int
    {
        return $this->baza->executa('DELETE FROM {sesiuni_admin} WHERE utilizator_id = ?', [$utilizator])->rowCount();
    }

    /** Codul TOTP al utilizatorului cu 2FA pornită; un pas se folosește o singură dată. */
    private function totpValid(array $u, string $cod): bool
    {
        if ($this->totp === null) {
            return false;
        }
        $pas = Totp::verifica($this->totp->decripteaza((string) $u['totp_secret']), $cod, $this->ceas->acum(), $u['totp_ultimul_pas'] !== null ? (int) $u['totp_ultimul_pas'] : null);
        if ($pas === null) {
            return false;
        }
        return $this->baza->executa('UPDATE {utilizatori} SET totp_ultimul_pas = ? WHERE id = ? AND (totp_ultimul_pas IS NULL OR totp_ultimul_pas < ?)', [$pas, $u['id'], $pas])->rowCount() === 1;
    }

    /** Utilizatorul nou (CLI-ul de dezvoltare; instalatorul îl face într-o versiune următoare). */
    public function creeazaUtilizator(string $email, string $nume, string $parola, string $rol = 'proprietar', string $limba = 'ro'): int
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || !in_array($rol, ['proprietar', 'personal'], true) || !in_array($limba, ['ro', 'en'], true)) {
            throw new \InvalidArgumentException('Utilizator invalid.');
        }
        $hash = Parole::hash($parola);
        $id = $this->baza->valoare('SELECT id FROM {utilizatori} WHERE email = ?', [$email]);
        if ($id !== null) {
            $this->baza->executa('UPDATE {utilizatori} SET nume = ?, parola_hash = ?, rol = ?, limba_panou = ?, activ = 1, blocat_pana = NULL WHERE id = ?', [$nume, $hash, $rol, $limba, $id]);
            if ($this->baza->tabelExista('jetoane_utilizator')) {
                $this->baza->executa("DELETE FROM {jetoane_utilizator} WHERE utilizator_id = ? AND scop = 'resetare' AND folosit_la IS NULL", [$id]);
            }
            return (int) $id;
        }
        return $this->baza->insereaza('utilizatori', ['email' => $email, 'nume' => $nume, 'parola_hash' => $hash, 'rol' => $rol, 'limba_panou' => $limba,
            'activ' => 1, 'creat_la' => $this->ceas->moment()]);
    }

    /**
     * Autentificarea. Întoarce jetonul sesiunii sau null (aceeași cale pentru cont lipsă și parolă greșită);
     * `limita` = true când autentificarea e oprită de limite. După o intrare reușită, `intrareCuCodRezerva` spune dacă a
     * folosit un cod de rezervă.
     * @return array{jeton:?string, limita:bool}
     */
    public function intra(string $email, string $parola, string $ip24, string $agent, ?string $cod = null): array
    {
        $email = mb_strtolower(trim($email));
        // Auditul de securitate (24.11.2026): limita pe cont se numără pe perechea cont + rețea (/24). Pe contul singur, oricine
        // bloca proprietarul cu 5 parole greșite la 15 minute, iar limita se aplică înaintea parolei, deci și parola bună era refuzată.
        // Ghicitul din multe rețele rămâne frânat de limita pe rețea (ESECURI_IP) și de Argon2id.
        $cheieCont = 'admin:cont:' . hash('sha256', $email . '|' . $ip24);
        $cheieIp = 'admin:ip:' . $ip24;
        // Încercarea se rezervă atomic pe ambele contoare înainte de verificarea parolei și se dă înapoi la succes; cererile
        // simultane nu mai citesc toate același contor sub limită (auditul de securitate.2026).
        $nCont = $this->limitator->inregistreaza($cheieCont, 900);
        $nIp = $this->limitator->inregistreaza($cheieIp, 3600);
        if ($nCont > self::ESECURI_CONT || $nIp > self::ESECURI_IP) {
            return ['jeton' => null, 'limita' => true];
        }
        $u = $this->baza->rand('SELECT * FROM {utilizatori} WHERE email = ? AND activ = 1', [$email]);
        // Fără cont, tot se calculează o verificare de parolă: timpul răspunsului rămâne același.
        static $fals = null;
        $ok = Parole::verifica($parola, $u !== null ? (string) $u['parola_hash'] : ($fals ??= password_hash(bin2hex(random_bytes(8)), Parole::algoritm())));
        // Cu 2FA pornită, codul din aplicație face parte din autentificare (același răspuns ca la o parolă greșită).
        // Un cod de rezervă ține locul codului din aplicație, o singură dată.
        $rezerva = false;
        if ($ok && $u !== null && $u['totp_secret'] !== null && !$this->totpValid($u, (string) $cod)) {
            $rezerva = self::esteCodRezerva((string) $cod) && $this->folosesteCodRezerva((int) $u['id'], (string) $cod);
            $ok = $rezerva;
        }
        if ($u === null || !$ok) {
            return ['jeton' => null, 'limita' => false];   // încercările rezervate rămân numărate
        }
        $this->limitator->anuleaza($cheieCont, 900);
        $this->limitator->anuleaza($cheieIp, 3600);
        if (Parole::deRefacut((string) $u['parola_hash'])) {
            $this->baza->executa('UPDATE {utilizatori} SET parola_hash = ? WHERE id = ?', [Parole::hash($parola), $u['id']]);
        }
        $jeton = Codare::jeton(32);
        $acum = $this->ceas->moment();
        $this->baza->insereaza('sesiuni_admin', ['id_hash' => hash('sha256', $jeton), 'utilizator_id' => (int) $u['id'], 'creat_la' => $acum, 'vazut_la' => $acum,
            'reautentificat_la' => $acum, 'ip24' => $ip24, 'agent_scurt' => mb_substr($agent, 0, 120)]);
        $this->baza->executa('UPDATE {utilizatori} SET ultima_logare_la = ? WHERE id = ?', [$acum, $u['id']]);
        $this->intrareCuCodRezerva = $rezerva;
        return ['jeton' => $jeton, 'limita' => false];
    }

    /** Utilizatorul sesiunii din cookie, sau null; o sesiune validă își mută `vazut_la`. */
    public function utilizator(?string $jeton): ?array
    {
        if ($jeton === null || !preg_match('~^[A-Za-z0-9_-]{43}$~', $jeton)) {
            return null;
        }
        $h = hash('sha256', $jeton);
        $s = $this->baza->rand('SELECT * FROM {sesiuni_admin} WHERE id_hash = ?', [$h]);
        if ($s === null) {
            return null;
        }
        $acum = $this->ceas->acum();
        if (Ceas::dinMoment((string) $s['vazut_la']) + self::INACTIV <= $acum || Ceas::dinMoment((string) $s['creat_la']) + self::ABSOLUT <= $acum) {
            $this->baza->executa('DELETE FROM {sesiuni_admin} WHERE id_hash = ?', [$h]);
            return null;
        }
        $u = $this->baza->rand('SELECT id, email, nume, rol, limba_panou, poate_rambursa, CASE WHEN totp_secret IS NULL THEN 0 ELSE 1 END AS are_2fa FROM {utilizatori} WHERE id = ? AND activ = 1',
            [$s['utilizator_id']]);
        if ($u === null) {
            return null;
        }
        $this->baza->executa('UPDATE {sesiuni_admin} SET vazut_la = ? WHERE id_hash = ?', [$this->ceas->moment(), $h]);
        return $u;
    }

    public function iese(?string $jeton): void
    {
        if ($jeton !== null) {
            $this->baza->executa('DELETE FROM {sesiuni_admin} WHERE id_hash = ?', [hash('sha256', $jeton)]);
        }
    }

    public static function cookie(string $jeton): string
    {
        return self::COOKIE . '=' . $jeton . '; Path=/; Secure; HttpOnly; SameSite=Strict';
    }

    public static function cookieSters(): string
    {
        return self::COOKIE . '=; Path=/; Secure; HttpOnly; SameSite=Strict; Max-Age=0';
    }

    /** Sesiunile expirate. */
    public function curata(): int
    {
        return $this->baza->executa('DELETE FROM {sesiuni_admin} WHERE vazut_la < ? OR creat_la < ?',
            [$this->ceas->moment(-self::INACTIV), $this->ceas->moment(-self::ABSOLUT)])->rowCount();
    }
}
