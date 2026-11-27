<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

use Cazo\Baza\Baza;
use Cazo\Joburi\Coada;
use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Setari;
use Cazo\Securitate\Secrete;

defined('CAZO') or exit;

/**
 * Anunțurile EasyCheck primite la POST /easycheck-anunt.
 *
 * Anunțul poartă doar tipul, nu id-ul resursei: semnătura dovedește originea, iar datele se citesc apoi prin API.
 * Primirea: semnătura (secretul curent și, 24 h după o rotire, cel anterior), proprietatea din GET /eu și `api = v1`,
 * deduplicarea după id, resursele vizate marcate învechite și un singur job în coadă (cheie_unica: zece anunțuri
 * fac un job). Anunțul `proba` se ține minte, ca instalatorul și panoul să vadă că anunțurile ajung la site.
 * Semnul `procesat_la` se pune la sfârșit: un anunț rămas neprocesat după o eroare se procesează la reluarea
 * trimisă de EasyCheck (același id) sau, cel târziu, la sincronizarea periodică (recupereaza).
 */
final class Anunturi
{
    public const CORP_MAXIM = 16384;
    public const ROTIRE_SECUNDE = 86400;
    public const JOB = 'ec:anunt';

    /** tip => resursele de recitit */
    public const RESURSE = [
        'disponibilitate.schimbata' => ['disponibilitate'],
        'preturi.schimbate' => ['preturi'],
        // la schimbarea stării „Site web” (pagina | site) site-ul recitește și GET /eu.
        'continut.schimbat' => ['proprietate', 'camere', 'eu'],
        'rezervare.schimbata' => ['disponibilitate'],
        'cerere.acceptata' => [],
        'cerere.refuzata' => [],
        'cerere.expirata' => [],
        // Transferul bancar (contractul din 20.11.2026): decizia gazdei, expirarea și prelungirea termenului de plată.
        // Nopțile eliberate vin separat, cu `disponibilitate.schimbata`.
        'rezervare.plata_confirmata' => [],
        'rezervare.plata_neprimita' => [],
        'rezervare.plata_expirata' => [],
        'rezervare.plata_prelungita' => [],
        // oaspetele a apăsat „Am plătit” (EasyCheck a scris anunțul); site-ul își recitește rezervarea.
        'rezervare.plata_anuntata' => [],
        // o comandă nouă (citită din cron) și hotărârea gazdei despre o anulare (lista rezervărilor).
        'comanda.noua' => [],
        'rezervare.anulare_decisa' => [],
        // șabloanele mesajelor către oaspeți; le citește sarcina `ec:mesaje` (MesajeEc), nu oglinda.
        'mesaje.schimbate' => [],
        'proba' => ['eu'],
    ];

    /** @var list<callable(string $tip): void> */
    private array $observatori = [];

    public function __construct(
        private Baza $baza,
        private Ceas $ceas,
        private Secrete $secrete,
        private Oglinda $oglinda,
        private Coada $coada,
        private Setari $setari,
    ) {
    }

    /** Modulele care urmăresc alte tipuri (cererile cash din această versiune, rezervările din această versiune) se abonează aici. */
    public function observa(callable $f): void
    {
        $this->observatori[] = $f;
    }

    /** Secretele acceptate acum: cel curent și, în primele 24 h după o rotire, cel anterior. */
    public function secreteAcceptate(): array
    {
        $s = [];
        $curent = $this->secrete->citeste('ec_whsec');
        if ($curent !== null) {
            $s[] = $curent;
        }
        $anterior = $this->secrete->citeste('ec_whsec_anterior');
        $rotit = $this->setari->get('ec.whsec_rotit_la');
        if ($anterior !== null && is_int($rotit) && $this->ceas->acum() - $rotit <= self::ROTIRE_SECUNDE) {
            $s[] = $anterior;
        }
        return $s;
    }

    /** Un secret nou (PUT /webhook): cel vechi rămâne 24 h ca `ec_whsec_anterior`. */
    public function puneSecret(string $secret): void
    {
        if (!preg_match('~^whsec_[0-9A-Za-z]{16,128}$~', $secret)) {
            throw new \UnexpectedValueException('Secretul de semnare are o formă necunoscută.');
        }
        $vechi = $this->secrete->citeste('ec_whsec');
        if ($vechi !== null && !hash_equals($vechi, $secret)) {
            $this->secrete->pune('ec_whsec_anterior', $vechi);
            $this->setari->pune('ec.whsec_rotit_la', $this->ceas->acum());
        }
        $this->secrete->pune('ec_whsec', $secret);
    }

    /**
     * Doar semnătura, cu secretele acceptate acum. Punctul de anunț o verifică înaintea limitei globale (auditul de
     * securitate.2026): un HMAC e ieftin, iar cererile false nu mai consumă limita anunțurilor adevărate.
     */
    public function semnaturaValida(string $corp, ?string $semnatura): bool
    {
        $secrete = $this->secreteAcceptate();
        return $secrete !== [] && $semnatura !== null && Semnatura::verifica($semnatura, $corp, $secrete, $this->ceas->acum());
    }

    /**
     * Primește un anunț. Întoarce „primit”, „duplicat” sau motivul refuzului (pentru jurnal; răspunsul HTTP
     * la refuz e 400 fără detalii).
     */
    public function primeste(string $corp, ?string $semnatura): string
    {
        if (strlen($corp) > self::CORP_MAXIM) {
            return 'corp_prea_mare';
        }
        if (!$this->semnaturaValida($corp, $semnatura)) {
            return 'semnatura';
        }
        $a = json_decode($corp, true, 8);
        if (!is_array($a) || !is_string($a['id'] ?? null) || !preg_match('~^evt_[0-9A-Za-z]{1,60}$~', $a['id'])
            || !is_string($a['tip'] ?? null) || !preg_match('~^[a-z_.]{1,64}$~', $a['tip'])) {
            return 'forma';
        }
        if (($a['api'] ?? null) !== 'v1') {
            return 'api';
        }
        $proprietate = $this->setari->get('ec.cheie')['proprietate'] ?? null;
        if (!is_string($proprietate) || ($a['proprietate'] ?? null) !== $proprietate) {
            return 'proprietate';
        }
        $acum = $this->ceas->moment();
        $creat = is_string($a['creat_la'] ?? null) && preg_match('~^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})Z$~', $a['creat_la'], $m) ? $m[1] . ' ' . $m[2] : null;
        try {
            $this->baza->insereaza('ec_anunturi', ['id' => $a['id'], 'tip' => $a['tip'], 'creat_la' => $creat, 'primit_la' => $acum, 'procesat_la' => null]);
        } catch (\PDOException $e) {
            if (!$this->baza->esteDuplicat($e)) {
                throw $e;
            }
            // Același id: duplicat doar dacă a fost și procesat. Unul rămas neprocesat (o eroare după primire)
            // se procesează acum, la reluarea trimisă de EasyCheck.
            if ($this->baza->valoare('SELECT procesat_la FROM {ec_anunturi} WHERE id = ?', [$a['id']]) !== null) {
                return 'duplicat';
            }
        }
        $this->proceseaza($a['id'], $a['tip']);
        return 'primit';
    }

    /**
     * Anunțurile primite și rămase neprocesate (o eroare între primire și procesare), cel puțin un minut după
     * primire, ca să nu prindă unul aflat chiar atunci în lucru. Întoarce câte s-au procesat.
     */
    public function recupereaza(int $cate = 20): int
    {
        $n = 0;
        $randuri = $this->baza->toate('SELECT id, tip FROM {ec_anunturi} WHERE procesat_la IS NULL AND primit_la <= ? ORDER BY primit_la LIMIT ' . max(1, $cate),
            [$this->ceas->moment(-60)]);
        foreach ($randuri as $r) {
            $this->proceseaza((string) $r['id'], (string) $r['tip']);
            $n++;
        }
        return $n;
    }

    /** Resursele vizate marcate învechite, jobul în coadă, observatorii; apoi semnul de procesare. */
    private function proceseaza(string $id, string $tip): void
    {
        if ($tip === 'proba') {
            $this->setari->pune('ec.anunt_proba', ['id' => $id, 'primit_la' => $this->ceas->acum()]);
        }
        // Un tip necunoscut (apărut în aceeași versiune a API-ului) recitește tot ce ține site-ul la zi.
        $resurse = self::RESURSE[$tip] ?? ['disponibilitate', 'preturi', 'proprietate', 'camere'];
        if ($resurse !== []) {
            $this->oglinda->marcheazaInvechit($resurse);
            $this->coada->pune(self::JOB, [], self::JOB);
        }
        foreach ($this->observatori as $f) {
            $f($tip);
        }
        $this->baza->executa('UPDATE {ec_anunturi} SET procesat_la = ? WHERE id = ?', [$this->ceas->moment(), $id]);
    }

    /** Anunțurile mai vechi de 30 de zile. */
    public function curata(): int
    {
        return $this->baza->executa('DELETE FROM {ec_anunturi} WHERE primit_la < ?', [$this->ceas->moment(-30 * 86400)])->rowCount();
    }
}
