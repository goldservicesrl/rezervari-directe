<?php

declare(strict_types=1);

namespace Cazo\Securitate;

use Cazo\Baza\Baza;
use Cazo\Baza\Zavor;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Utilizatorii panoului (14 Securitate): invitarea, dezactivarea și reactivarea, rolul, dreptul
 * „Poate face rambursări” (prin SesiuneAdmin), parola (schimbată din panou sau resetată prin e-mail), limba
 * panoului și resetarea 2FA a unui angajat de către proprietar.
 *
 * Regula de bază: rămâne mereu cel puțin un proprietar activ, cu parolă. Invitația și resetarea trimit pe e-mail un
 * jeton de 32 de octeți; în bază stă doar SHA-256 al lui, iar adresa din e-mail e criptată în cutia de e-mailuri.
 * Invitația ține 72 de ore, resetarea o oră; un jeton nou îl înlocuiește pe cel vechi, de același fel.
 */
final class Utilizatori
{
    public const INVITATIE = 259200;
    public const RESETARE = 3600;
    public const ROLURI = ['proprietar', 'personal'];
    public const LIMBI = ['ro', 'en'];

    public function __construct(private Baza $baza, private Ceas $ceas, private SesiuneAdmin $sesiuni, private ?Zavor $zavor = null)
    {
    }

    /** @return list<array> toți utilizatorii, cu starea 2FA și a invitației */
    public function toti(): array
    {
        return $this->baza->toate("SELECT id, email, nume, rol, limba_panou, poate_rambursa, activ, ultima_logare_la, creat_la, dezactivat_la,
            CASE WHEN totp_secret IS NULL THEN 0 ELSE 1 END AS are_2fa, CASE WHEN parola_hash = '' THEN 1 ELSE 0 END AS invitat FROM {utilizatori} ORDER BY activ DESC, rol DESC, id");
    }

    public function dupaId(int $id): ?array
    {
        return $this->baza->rand("SELECT id, email, nume, rol, limba_panou, poate_rambursa, activ, CASE WHEN totp_secret IS NULL THEN 0 ELSE 1 END AS are_2fa,
            CASE WHEN parola_hash = '' THEN 1 ELSE 0 END AS invitat FROM {utilizatori} WHERE id = ?", [$id]);
    }

    /**
     * Invită un utilizator nou: contul se creează fără parolă (nu se poate intra în el), iar jetonul pleacă pe e-mail.
     * Întoarce ['id' => ..., 'jeton' => ...] sau ['eroare' => 'email'|'exista'|'rol'].
     */
    public function invita(string $email, string $nume, string $rol, string $limba, bool $poateRambursa, int $deCatre): array
    {
        $email = mb_strtolower(trim($email));
        $nume = trim(mb_substr($nume, 0, 120));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['eroare' => 'email'];
        }
        if (!in_array($rol, self::ROLURI, true) || !in_array($limba, self::LIMBI, true)) {
            return ['eroare' => 'rol'];
        }
        $existent = $this->baza->rand('SELECT id, activ, parola_hash FROM {utilizatori} WHERE email = ?', [$email]);
        if ($existent !== null && (int) $existent['activ'] === 1 && $existent['parola_hash'] !== '') {
            return ['eroare' => 'exista'];
        }
        $drept = $rol === 'personal' && $poateRambursa ? 1 : 0;
        if ($existent !== null) {
            // Un cont dezactivat sau o invitație neacceptată: invitația nouă îl repornește, fără parola veche.
            $id = (int) $existent['id'];
            $this->baza->executa("UPDATE {utilizatori} SET nume = ?, rol = ?, limba_panou = ?, poate_rambursa = ?, parola_hash = '', totp_secret = NULL, totp_ultimul_pas = NULL,
                activ = 1, dezactivat_la = NULL WHERE id = ?", [$nume, $rol, $limba, $drept, $id]);
            $this->baza->executa('DELETE FROM {coduri_rezerva} WHERE utilizator_id = ?', [$id]);
            $this->sesiuni->inchideToate($id);
        } else {
            $id = $this->baza->insereaza('utilizatori', ['email' => $email, 'nume' => $nume, 'parola_hash' => '', 'rol' => $rol, 'limba_panou' => $limba, 'poate_rambursa' => $drept,
                'activ' => 1, 'creat_la' => $this->ceas->moment()]);
        }
        return ['id' => $id, 'jeton' => $this->jetonNou($id, 'invitatie', self::INVITATIE, $deCatre)];
    }

    /** O invitație nouă pentru un cont încă fără parolă („Trimiteți din nou invitația”). */
    public function reinvita(int $id, int $deCatre): ?string
    {
        $u = $this->baza->rand("SELECT id FROM {utilizatori} WHERE id = ? AND activ = 1 AND parola_hash = ''", [$id]);
        return $u !== null ? $this->jetonNou($id, 'invitatie', self::INVITATIE, $deCatre) : null;
    }

    /**
     * Cererea de resetare a parolei („Ați uitat parola?”): doar pentru un cont activ, cu parolă. Întoarce utilizatorul
     * și jetonul, sau null; pagina răspunde la fel în ambele cazuri.
     * @return array{id:int, email:string, limba_panou:string, jeton:string}|null
     */
    public function cereResetare(string $email): ?array
    {
        $u = $this->baza->rand("SELECT id, email, limba_panou FROM {utilizatori} WHERE email = ? AND activ = 1 AND parola_hash <> ''", [mb_strtolower(trim($email))]);
        if ($u === null) {
            return null;
        }
        return ['id' => (int) $u['id'], 'email' => (string) $u['email'], 'limba_panou' => (string) $u['limba_panou'], 'jeton' => $this->jetonNou((int) $u['id'], 'resetare', self::RESETARE, null)];
    }

    /** Utilizatorul unui jeton valabil (nefolosit, neexpirat), cu scopul dat. */
    public function dupaJeton(string $jeton, string $scop): ?array
    {
        if (!preg_match('~^[A-Za-z0-9_-]{43}$~', $jeton)) {
            return null;
        }
        $j = $this->baza->rand('SELECT * FROM {jetoane_utilizator} WHERE jeton_hash = ? AND scop = ? AND folosit_la IS NULL AND expira_la > ?', [hash('sha256', $jeton), $scop, $this->ceas->moment()]);
        if ($j === null) {
            return null;
        }
        $u = $this->baza->rand('SELECT id, email, nume, rol, limba_panou FROM {utilizatori} WHERE id = ? AND activ = 1', [$j['utilizator_id']]);
        return $u !== null ? $u + ['jeton_id' => (int) $j['id']] : null;
    }

    /**
     * Parola aleasă cu un jeton (invitația acceptată sau resetarea): jetonul se consumă o singură dată, sesiunile vechi
     * ale contului se închid, iar 2FA rămâne cum era (resetarea parolei nu ocolește verificarea în doi pași).
     * Întoarce id-ul utilizatorului, 'parola' (prea scurtă), 'compromisa' (în lista parolelor ușor de ghicit) sau null (jeton nevalabil).
     */
    public function folosesteJeton(string $jeton, string $scop, string $parola, ?string $limba = null): int|string|null
    {
        $u = $this->dupaJeton($jeton, $scop);
        if ($u === null) {
            return null;
        }
        if (mb_strlen($parola) < Parole::MINIM || strlen($parola) > 4096) {
            return 'parola';
        }
        // Lista parolelor compromise, ca în instalator (auditul de securitate.2026).
        if (ParoleCompromise::compromisa($parola, [(string) strstr((string) $u['email'], '@', true), (string) $u['nume']])) {
            return 'compromisa';
        }
        if ($this->baza->executa('UPDATE {jetoane_utilizator} SET folosit_la = ? WHERE id = ? AND folosit_la IS NULL', [$this->ceas->moment(), $u['jeton_id']])->rowCount() !== 1) {
            return null;
        }
        $this->baza->executa('UPDATE {utilizatori} SET parola_hash = ?, blocat_pana = NULL WHERE id = ?', [Parole::hash($parola), $u['id']]);
        $this->baza->executa('DELETE FROM {jetoane_utilizator} WHERE utilizator_id = ? AND folosit_la IS NULL', [$u['id']]);
        if ($limba !== null && in_array($limba, self::LIMBI, true)) {
            $this->baza->executa('UPDATE {utilizatori} SET limba_panou = ? WHERE id = ?', [$limba, $u['id']]);
        }
        $this->sesiuni->inchideToate((int) $u['id']);
        return (int) $u['id'];
    }

    /**
     * Schimbarea parolei din „Contul meu”: parola de acum și cea nouă. Celelalte sesiuni se închid.
     * Întoarce 'ok', 'parola' (cea de acum e greșită), 'scurta' sau 'compromisa'.
     */
    public function schimbaParola(int $id, string $actuala, string $noua, ?string $jetonCurent): string
    {
        if (!$this->sesiuni->reconfirma($id, $actuala)) {
            return 'parola';
        }
        if (mb_strlen($noua) < Parole::MINIM || strlen($noua) > 4096) {
            return 'scurta';
        }
        $u = $this->baza->rand('SELECT email, nume FROM {utilizatori} WHERE id = ?', [$id]);
        if (ParoleCompromise::compromisa($noua, [(string) strstr((string) ($u['email'] ?? ''), '@', true), (string) ($u['nume'] ?? '')])) {
            return 'compromisa';   // auditul de securitate.2026
        }
        $this->baza->executa('UPDATE {utilizatori} SET parola_hash = ? WHERE id = ?', [Parole::hash($noua), $id]);
        // O parolă nouă anulează legăturile de resetare încă nefolosite.
        $this->baza->executa("DELETE FROM {jetoane_utilizator} WHERE utilizator_id = ? AND scop = 'resetare' AND folosit_la IS NULL", [$id]);
        $this->sesiuni->inchideCelelalte($id, $jetonCurent);
        return 'ok';
    }

    public function schimbaLimba(int $id, string $limba): bool
    {
        return in_array($limba, self::LIMBI, true) && $this->baza->executa('UPDATE {utilizatori} SET limba_panou = ? WHERE id = ?', [$limba, $id])->rowCount() >= 0;
    }

    public function schimbaNumele(int $id, string $nume): void
    {
        $this->baza->executa('UPDATE {utilizatori} SET nume = ? WHERE id = ?', [trim(mb_substr($nume, 0, 120)), $id]);
    }

    /**
     * Dezactivarea: sesiunile se închid pe loc. Refuzată pentru ultimul proprietar activ. Întoarce 'ok', 'ultimul' sau
     * 'lipsa'. Verificarea „rămâne un proprietar activ” și schimbarea se fac sub zăvorul `utilizatori`, într-o
     * tranzacție, ca doi proprietari care se dezactivează unul pe altul în aceeași clipă să nu lase site-ul fără
     * proprietar.
     */
    public function dezactiveaza(int $id): string
    {
        return $this->subZavor(function () use ($id): string {
            $u = $this->dupaId($id);
            if ($u === null || (int) $u['activ'] !== 1) {
                return 'lipsa';
            }
            if ($u['rol'] === 'proprietar' && $this->proprietariActivi($id) === 0) {
                return 'ultimul';
            }
            $this->baza->executa('UPDATE {utilizatori} SET activ = 0, dezactivat_la = ? WHERE id = ?', [$this->ceas->moment(), $id]);
            $this->baza->executa('DELETE FROM {jetoane_utilizator} WHERE utilizator_id = ? AND folosit_la IS NULL', [$id]);
            $this->sesiuni->inchideToate($id);
            return 'ok';
        });
    }

    /** Reactivarea unui cont dezactivat, cu parola și 2FA de dinainte. */
    public function reactiveaza(int $id): string
    {
        return $this->baza->executa('UPDATE {utilizatori} SET activ = 1, dezactivat_la = NULL WHERE id = ? AND activ = 0', [$id])->rowCount() === 1 ? 'ok' : 'lipsa';
    }

    /**
     * Schimbarea rolului. Un proprietar devenit angajat pierde dreptul de rambursare (îl primește din nou explicit),
     * iar sesiunile lui se închid (id nou la schimbarea rolului). Ultimul proprietar activ rămâne proprietar;
     * verificarea, sub același zăvor ca dezactivarea.
     */
    public function schimbaRol(int $id, string $rol): string
    {
        return $this->subZavor(function () use ($id, $rol): string {
            $u = $this->dupaId($id);
            if ($u === null || !in_array($rol, self::ROLURI, true)) {
                return 'lipsa';
            }
            if ($u['rol'] === $rol) {
                return 'ok';
            }
            if ($u['rol'] === 'proprietar' && (int) $u['activ'] === 1 && $this->proprietariActivi($id) === 0) {
                return 'ultimul';
            }
            $this->baza->executa('UPDATE {utilizatori} SET rol = ?, poate_rambursa = 0 WHERE id = ?', [$rol, $id]);
            $this->sesiuni->inchideToate($id);
            return 'ok';
        });
    }

    /** Rulează $f sub zăvorul `utilizatori`, într-o tranzacție; zăvorul ocupat întoarce 'ocupat' (încercați din nou). */
    private function subZavor(callable $f): string
    {
        if ($this->zavor === null) {
            return (string) $this->baza->tranzactie($f);
        }
        [$ok, $r] = $this->zavor->cuZavor('utilizatori', 30, fn (): string => (string) $this->baza->tranzactie($f));
        return $ok ? (string) $r : 'ocupat';
    }

    /**
     * Resetarea 2FA a altui utilizator, de către proprietar (calea de recuperare a celui care și-a pierdut telefonul și
     * codurile de rezervă): secretul și codurile dispar, sesiunile se închid.
     */
    public function reseteaza2fa(int $id): string
    {
        $u = $this->dupaId($id);
        if ($u === null || (int) $u['are_2fa'] !== 1) {
            return 'lipsa';
        }
        $this->sesiuni->dezactiveazaTotp($id);
        $this->sesiuni->inchideToate($id);
        return 'ok';
    }

    /** Proprietarii activi, cu parolă, în afară de $exceptat. */
    public function proprietariActivi(int $exceptat = 0): int
    {
        return (int) $this->baza->valoare("SELECT COUNT(*) FROM {utilizatori} WHERE rol = 'proprietar' AND activ = 1 AND parola_hash <> '' AND id <> ?", [$exceptat]);
    }

    /** Jetoanele expirate sau folosite, după o zi (curățenia orară). */
    public function curata(): int
    {
        return $this->baza->executa('DELETE FROM {jetoane_utilizator} WHERE expira_la < ? OR folosit_la < ?', [$this->ceas->moment(-86400), $this->ceas->moment(-86400)])->rowCount();
    }

    private function jetonNou(int $id, string $scop, int $secunde, ?int $deCatre): string
    {
        $jeton = Codare::jeton(32);
        $this->baza->executa('DELETE FROM {jetoane_utilizator} WHERE utilizator_id = ? AND scop = ? AND folosit_la IS NULL', [$id, $scop]);
        $this->baza->insereaza('jetoane_utilizator', ['utilizator_id' => $id, 'scop' => $scop, 'jeton_hash' => hash('sha256', $jeton), 'expira_la' => $this->ceas->moment($secunde),
            'creat_de' => $deCatre, 'creat_la' => $this->ceas->moment()]);
        return $jeton;
    }
}
