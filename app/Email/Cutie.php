<?php

declare(strict_types=1);

namespace Cazo\Email;

use Cazo\Baza\Baza;
use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Jurnal;
use Cazo\Securitate\Cutie as CutieCriptare;

defined('CAZO') or exit;

/**
 * Cutia de e-mailuri: fiecare e-mail intră întâi în tabelul `emailuri` (destinatarul
 * criptat, `date` fără secrete în clar), apoi pleacă din cron sau imediat după răspuns. Corpul se randează la
 * trimitere (Sabloane), deci un e-mail reîncercat arată starea de atunci. Cel mult 20 pe rulare; reîncercările la
 * 1, 5, 30 de minute, 2 ore și 6 ore, apoi starea `esuat`, vizibilă în panou.
 *
 * Ofertele către abonați (șablonul `oferta`) au plafonul lor pe oră (OFERTE_PE_ORA, schimbat din config.php cu
 * `oferte_pe_ora`): pleacă doar cât mai e loc în ultima oră și nu țin pe loc e-mailurile rezervărilor.
 */
final class Cutie
{
    public const PE_RULARE = 20;
    public const REINCERCARI = [60, 300, 1800, 7200, 21600];
    public const OFERTE_PE_ORA = 60;
    public const SABLON_OFERTA = 'oferta';

    /**
     * @param \Closure(): Transport $transport
     * @param \Closure(string $sablon, string $limba, array $date, string $catre): ?Mesaj $construieste (null: e-mailul nu mai e
     *     adevărat și nu mai pleacă; rămâne în tabel cu starea `renuntat`)
     */
    public function __construct(
        private Baza $baza,
        private Ceas $ceas,
        private CutieCriptare $pii,
        private \Closure $transport,
        private \Closure $construieste,
        private ?Jurnal $jurnal = null,
        private int $ofertePeOra = self::OFERTE_PE_ORA,
    ) {
    }

    /** Pune un e-mail în coadă (cu $intarziere secunde până la prima încercare); întoarce id-ul lui. */
    public function pune(?int $comandaId, string $sablon, string $limba, string $catre, array $date = [], int $intarziere = 0): int
    {
        if (!preg_match('~^[a-z_]{1,64}$~', $sablon) || !in_array($limba, ['ro', 'en'], true) || filter_var($catre, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('E-mail invalid pentru coadă.');
        }
        $acum = $this->ceas->moment();
        return $this->baza->insereaza('emailuri', [
            'comanda_id' => $comandaId,
            'sablon' => $sablon,
            'limba' => $limba,
            'catre' => $this->pii->cripteaza($catre),
            'date' => json_encode($date, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'stare' => 'in_coada',
            'incercari' => 0,
            'urmatoarea_incercare' => $intarziere > 0 ? $this->ceas->moment($intarziere) : $acum,
            'creat_la' => $acum,
        ]);
    }

    /** O valoare secretă din `date` (codul de 6 cifre), criptată ca destinatarul. */
    public function secret(string $valoare): string
    {
        return $this->pii->cripteaza($valoare);
    }

    public function dezvaluie(string $criptat): string
    {
        return $this->pii->decripteaza($criptat);
    }

    /**
     * Trimite e-mailurile scadente (cel mult $cate). Fiecare e preluat întâi cu un UPDATE condiționat (`in_lucru`),
     * deci două rulări simultane trimit fiecare mesaj o singură dată.
     * @return array{trimise:int, esuate:int}
     */
    public function trimite(int $cate = self::PE_RULARE, ?int $doarId = null): array
    {
        $acum = $this->ceas->moment();
        $sql = "SELECT id FROM {emailuri} WHERE ((stare = 'in_coada' AND urmatoarea_incercare <= ?) OR (stare = 'in_lucru' AND urmatoarea_incercare <= ?))";
        $praguri = [$acum, $this->ceas->moment(-600)];
        $p = $praguri;
        if ($doarId !== null) {
            $sql .= ' AND id = ?';
            $p[] = $doarId;
        }
        $cate = max(1, min(100, $cate));
        $ids = $this->baza->executa($sql . ' AND sablon <> ? ORDER BY urmatoarea_incercare, id LIMIT ' . $cate, [...$p, self::SABLON_OFERTA])->fetchAll(\PDO::FETCH_COLUMN);
        // Ofertele, în locul rămas și doar cât mai încap în plafonul ultimei ore (cele în lucru se numără și ele).
        $loc = min($cate - count($ids), $this->locOferte());
        if ($loc > 0) {
            $ids = array_merge($ids, $this->baza->executa($sql . ' AND sablon = ? ORDER BY urmatoarea_incercare, id LIMIT ' . $loc, [...$p, self::SABLON_OFERTA])->fetchAll(\PDO::FETCH_COLUMN));
        }
        $rez = ['trimise' => 0, 'esuate' => 0];
        $transport = null;
        foreach ($ids as $id) {
            // Preluarea repetă condiția selecției: un e-mail `in_lucru` se reia doar după 10 minute (rularea lui s-a oprit),
            // altfel o rulare simultană, care tocmai l-a preluat, l-ar trimite și ea.
            $n = $this->baza->executa("UPDATE {emailuri} SET stare = 'in_lucru', urmatoarea_incercare = ? WHERE id = ?
                AND ((stare = 'in_coada' AND urmatoarea_incercare <= ?) OR (stare = 'in_lucru' AND urmatoarea_incercare <= ?))",
                [$this->ceas->moment(), $id, ...$praguri])->rowCount();
            if ($n !== 1) {
                continue;
            }
            $e = $this->baza->rand('SELECT * FROM {emailuri} WHERE id = ?', [$id]);
            try {
                $date = json_decode((string) $e['date'], true, 32, JSON_THROW_ON_ERROR);
                $mesaj = ($this->construieste)((string) $e['sablon'], (string) $e['limba'], is_array($date) ? $date : [], $this->pii->decripteaza((string) $e['catre']));
                if ($mesaj === null) {
                    $this->baza->executa("UPDATE {emailuri} SET stare = 'renuntat', eroare = NULL WHERE id = ?", [$id]);
                    continue;
                }
                $transport ??= ($this->transport)();
                $transport->trimite($mesaj);
                $this->baza->executa("UPDATE {emailuri} SET stare = 'trimis', trimis_la = ?, incercari = incercari + 1, eroare = NULL WHERE id = ?", [$this->ceas->moment(), $id]);
                $rez['trimise']++;
            } catch (\Throwable $ex) {
                $incercari = (int) $e['incercari'] + 1;
                $stare = $incercari > count(self::REINCERCARI) ? 'esuat' : 'in_coada';
                $pauza = self::REINCERCARI[min($incercari - 1, count(self::REINCERCARI) - 1)];
                $mesajEroare = mb_substr(Jurnal::mascheaza(get_class($ex) . ': ' . $ex->getMessage()), 0, 480);
                $this->baza->executa('UPDATE {emailuri} SET stare = ?, incercari = ?, urmatoarea_incercare = ?, eroare = ? WHERE id = ?',
                    [$stare, $incercari, $this->ceas->moment($pauza), $mesajEroare, $id]);
                $this->jurnal?->avertisment('E-mail netrimis', ['id' => (int) $id, 'sablon' => $e['sablon'], 'incercari' => $incercari, 'eroare' => $mesajEroare]);
                $rez['esuate']++;
            }
        }
        return $rez;
    }

    /** Câte oferte mai pot pleca acum: plafonul pe oră minus cele trimise în ultima oră și cele în lucru. */
    public function locOferte(): int
    {
        $n = (int) $this->baza->valoare("SELECT COUNT(*) FROM {emailuri} WHERE sablon = ? AND ((stare = 'trimis' AND trimis_la > ?) OR (stare = 'in_lucru' AND urmatoarea_incercare > ?))",
            [self::SABLON_OFERTA, $this->ceas->moment(-3600), $this->ceas->moment(-600)]);
        return max(0, $this->ofertePeOra - $n);
    }

    public function ofertePeOra(): int
    {
        return $this->ofertePeOra;
    }

    public function inAsteptare(): int
    {
        return (int) $this->baza->valoare("SELECT COUNT(*) FROM {emailuri} WHERE stare IN ('in_coada', 'in_lucru')");
    }
}
