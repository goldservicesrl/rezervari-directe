<?php

declare(strict_types=1);

namespace Cazo\Gdpr;

use Cazo\I18n\Traducator;
use Cazo\Nucleu\Aplicatie;

defined('CAZO') or exit;

/**
 * GDPR: termenele de păstrare alese de gazdă, anonimizarea la termen, exportul și
 * ștergerea datelor unui oaspete, găsit după e-mail prin indexul orb.
 *
 * Termenele: (a) datele personale ale comenzilor confirmate, în zile după plecare; (b) comenzile
 * abandonate, în ore de la ultima schimbare; (c) comenzile rambursate sau respinse, în zile de la ultima schimbare;
 * (d) IP-ul complet din acorduri, în luni. Instalatorul și panoul propun PROPUSE, între pragurile LIMITE; gazda le
 * confirmă sau le schimbă, iar până la confirmare anonimizarea așteaptă și panoul arată alerta. O valoare salvată
 * înaintea pragurilor (între LIMITE_VECHI) rămâne în vigoare, cu avertizarea din panou, până o schimbă gazda.
 *
 * Anonimizarea golește: datele oaspetelui (`oaspete`, `nota`, `email_io`, `corp_ec`, `ip24`), IP-ul și browserul din
 * acorduri, nota internă a rambursărilor din panou (`operatii_plata.nota`), cardul scurt al plății (`metoda_scurt`),
 * e-mailurile comenzii din cutie (destinatarul și datele) și codurile pe e-mail. Rămân, pentru reconciliere: `ref`,
 * datele sejurului, sumele, stările și jurnalul lor, id-urile EasyCheck și Stripe, țara. `anonimizat_la` închide și
 * linkul „Rezervarea mea”.
 *
 * Doar comenzile încheiate se anonimizează: cele cu bani în mișcare (o rambursare cerută sau în procesare, o anulare în
 * lucru, o schimbare de date neîncheiată) și stările care așteaptă ceva (o cerere de anulare, `anulare_de_lamurit`)
 * rămân neatinse până se încheie; apoi comanda urmează grupa stării ei finale.
 *
 * Ștergerea cerută de oaspete se transmite și la EasyCheck (StergereEasyCheck), după ce site-ul a șters.
 */
final class Gdpr
{
    public const CHEIE = 'gdpr.termene';
    /** Pragurile fiecărui termen: între ele alege gazda, în instalator și în panou. */
    public const LIMITE = ['confirmate_zile' => [180, 1095], 'abandonate_ore' => [24, 720], 'rambursate_zile' => [30, 1095], 'ip_luni' => [6, 36]];

    /** Valorile propuse, cu care vin completate câmpurile; gazda le confirmă sau le schimbă. */
    public const PROPUSE = ['confirmate_zile' => 1095, 'abandonate_ore' => 72, 'rambursate_zile' => 180, 'ip_luni' => 36];

    /** Unitatea fiecărui termen. */
    public const UNITATI = ['confirmate_zile' => 'zile', 'abandonate_ore' => 'ore', 'rambursate_zile' => 'zile', 'ip_luni' => 'luni'];

    /**
     * Limitele de formă de dinaintea pragurilor: o valoare salvată atunci și aflată acum în afara LIMITE rămâne
     * în vigoare cât e între ele; `alegeTermene` primește doar valori dintre LIMITE.
     */
    public const LIMITE_VECHI = ['confirmate_zile' => [1, 3650], 'abandonate_ore' => [1, 8760], 'rambursate_zile' => [1, 3650], 'ip_luni' => [1, 120]];

    /** Jurnalul alertelor din panou: câte zile se păstrează, între ce praguri, și valoarea de la început. */
    public const ALERTE_CHEIE = 'alerte.pastrare_zile';
    public const ALERTE_LIMITE = [30, 3650];
    public const ALERTE_PROPUS = 365;

    /** (a) `confirmate_zile`: sejurul a avut loc sau a fost anulat după confirmare; termenul curge de la plecare. */
    public const STARI_SEJUR = ['confirmata', 'anulata_oaspete', 'anulata_gazda', 'inchisa_gazda'];

    /** (b) `abandonate_ore`: comanda a rămas neterminată, fără bani luați; termenul curge de la ultima schimbare. */
    public const STARI_ABANDONATE = ['abandonata', 'plata_esuata'];

    /**
     * (c) `rambursate_zile`: comanda trimisă și oprită înaintea sejurului, cu banii returnați sau eliberați ori cu cererea
     * refuzată, expirată sau respinsă; termenul curge de la ultima schimbare.
     */
    public const STARI_RESPINSE = ['rambursata', 'autorizare_anulata', 'respinsa_ec', 'indisponibila', 'cerere_refuzata', 'cerere_expirata', 'transfer_neprimit', 'transfer_expirat'];

    /** Stările în lucru (bani în mișcare sau o decizie așteptată): comanda rămâne neatinsă până ajunge într-o stare finală. */
    public const STARI_VII = ['noua', 'plata_in_curs', 'plata_in_procesare', 'platita', 'trimitere_ec', 'de_capturat', 'de_rambursat', 'cerere_in_asteptare', 'transfer_in_asteptare',
        'anulare_ceruta', 'anulare_de_lamurit'];

    public function __construct(private Aplicatie $app)
    {
    }

    /**
     * Termenele în vigoare, sau null cât gazda nu le-a ales. O valoare de dinaintea pragurilor rămâne în vigoare;
     * `rambursate_zile`, termenul nou, pornește de la valoarea propusă când lipsește din setare (o instalare de dinaintea
     * migrării 0035, ori versiunea anterioară a softului care a salvat peste schema nouă).
     * @return array{confirmate_zile:int, abandonate_ore:int, rambursate_zile:int, ip_luni:int}|null
     */
    public function termene(): ?array
    {
        $t = $this->app->setari()->get(self::CHEIE);
        if (!is_array($t)) {
            return null;
        }
        $t += ['rambursate_zile' => self::PROPUSE['rambursate_zile']];
        $r = [];
        foreach (self::LIMITE_VECHI as $k => [$min, $max]) {
            if (!is_int($t[$k] ?? null) || $t[$k] < $min || $t[$k] > $max) {
                return null;
            }
            $r[$k] = $t[$k];
        }
        return $r;
    }

    /**
     * Termenele în vigoare aflate în afara pragurilor de acum (salvate înaintea lor): rămân cum sunt până le schimbă
     * gazda, iar panoul o avertizează. @return array<string,int> cheia termenului => valoarea în vigoare
     */
    public function termeneInAfara(): array
    {
        $r = [];
        foreach ($this->termene() ?? [] as $k => $v) {
            if ($v < self::LIMITE[$k][0] || $v > self::LIMITE[$k][1]) {
                $r[$k] = $v;
            }
        }
        return $r;
    }

    /**
     * Verifică termenele scrise într-un formular, față de praguri. @param array<string, string|int|null> $valori
     * @return array{0: array<string,int>, 1: list<string>} valorile bune și cheile câmpurilor greșite
     */
    public static function verificaTermene(array $valori): array
    {
        $t = [];
        $gresite = [];
        foreach (self::LIMITE as $k => [$min, $max]) {
            $v = trim((string) ($valori[$k] ?? ''));
            if (!preg_match('~^\d{1,5}$~', $v) || (int) $v < $min || (int) $v > $max) {
                $gresite[] = $k;
            } else {
                $t[$k] = (int) $v;
            }
        }
        return [$t, $gresite];
    }

    /**
     * Salvează cele patru termene, toate între praguri. Întoarce null la reușită sau cheia primului câmp greșit.
     * @param array<string, string|int|null> $valori
     */
    public function alegeTermene(array $valori, ?int $utilizator): ?string
    {
        [$t, $gresite] = self::verificaTermene($valori);
        if ($gresite !== []) {
            return $gresite[0];
        }
        $this->app->setari()->pune(self::CHEIE, $t, $utilizator);
        return null;
    }

    /**
     * Termenele în vigoare, cu eticheta și fraza lor, pentru Politica de confidențialitate și pentru panou (funcție
     * stabilă). Lista e goală cât gazda nu le-a ales. `unitate`: `zile`, `ore` sau `luni`. Textele vin din catalogul
     * primit, când are cheile `gdpr.termen.<cheie>.eticheta` și `gdpr.termen.<cheie>.text` (plural, cu {n}); altfel din
     * catalogul panoului, în aceeași limbă (`admin.gdpr.public.<cheie>.eticheta`, `admin.gdpr.public.<cheie>.text`).
     * @return list<array{cheie:string, valoare:int, unitate:string, eticheta:string, text:string}>
     */
    public function termeneCuEticheta(Traducator|string $limba): array
    {
        $public = $limba instanceof Traducator ? $limba : null;
        $panou = $this->app->traducator($public !== null ? $public->limba() : $limba, 'admin');
        $r = [];
        foreach ($this->termene() ?? [] as $k => $v) {
            $alAlt = $public !== null && $public->are("gdpr.termen.$k.eticheta") && $public->are("gdpr.termen.$k.text");
            $r[] = ['cheie' => $k, 'valoare' => $v, 'unitate' => self::UNITATI[$k],
                'eticheta' => $alAlt ? $public->t("gdpr.termen.$k.eticheta") : $panou->t("admin.gdpr.public.$k.eticheta"),
                'text' => $alAlt ? $public->plural("gdpr.termen.$k.text", $v) : $panou->plural("admin.gdpr.public.$k.text", $v)];
        }
        return $r;
    }

    /**
     * O durată pe înțelesul gazdei, pentru Politica de confidențialitate și pentru explicația câmpurilor
     *: zilele în ani (la 365) sau în luni (la 30), orele în zile, lunile în ani, când se împart
     * exact; altfel în unitatea termenului. $t e catalogul site-ului (`unitate.zile`, `unitate.ore`,
     * `unitate.luni`, `unitate.ani`).
     */
    public static function durata(Traducator $t, string $cheie, int $n): string
    {
        return match (self::UNITATI[$cheie] ?? 'zile') {
            'ore' => $n % 24 === 0 ? $t->plural('unitate.zile', intdiv($n, 24)) : $t->plural('unitate.ore', $n),
            'luni' => $n % 12 === 0 ? $t->plural('unitate.ani', intdiv($n, 12)) : $t->plural('unitate.luni', $n),
            default => $n % 365 === 0 ? $t->plural('unitate.ani', intdiv($n, 365)) : ($n % 30 === 0 ? $t->plural('unitate.luni', intdiv($n, 30)) : $t->plural('unitate.zile', $n)),
        };
    }

    /** Câte zile păstrează jurnalul alertelor din panou: alegerea gazdei sau valoarea de la început. */
    public function alerteZile(): int
    {
        $v = $this->app->setari()->get(self::ALERTE_CHEIE);
        return is_int($v) && $v >= self::ALERTE_LIMITE[0] && $v <= self::ALERTE_LIMITE[1] ? $v : self::ALERTE_PROPUS;
    }

    /** Salvează păstrarea jurnalului alertelor; false la o valoare din afara pragurilor. */
    public function alegeAlerteZile(string $v, ?int $utilizator): bool
    {
        $v = trim($v);
        if (!preg_match('~^\d{2,4}$~', $v) || (int) $v < self::ALERTE_LIMITE[0] || (int) $v > self::ALERTE_LIMITE[1]) {
            return false;
        }
        $this->app->setari()->pune(self::ALERTE_CHEIE, (int) $v, $utilizator);
        return true;
    }

    /**
     * Anonimizarea la termen (sarcina orară). Fără termene alese, nu face nimic. Fiecare stare finală are termenul
     * ei: (a) STARI_SEJUR, (b) STARI_ABANDONATE, (c) STARI_RESPINSE; stările în lucru (STARI_VII) rămân neatinse.
     * @return array{comenzi:int, ip:int}|null
     */
    public function anonimizeazaLaTermen(int $cate = 200): ?array
    {
        $t = $this->termene();
        if ($t === null) {
            return null;
        }
        $b = $this->app->baza();
        $ziLimita = (new \DateTimeImmutable($this->app->azi() . ' 00:00:00', new \DateTimeZone('UTC')))->modify('-' . $t['confirmate_zile'] . ' days')->format('Y-m-d');
        $ids = [];
        foreach ($b->toate('SELECT id FROM {comenzi} WHERE anonimizat_la IS NULL AND stare IN (' . self::lista(self::STARI_SEJUR) . ') AND plecare <= ? ORDER BY id LIMIT ' . $cate,
            [...self::STARI_SEJUR, $ziLimita]) as $r) {
            $ids[] = (int) $r['id'];
        }
        // (b) și (c): de la ultima schimbare a comenzii, fiecare grupă cu termenul ei.
        foreach ([[self::STARI_ABANDONATE, 3600 * $t['abandonate_ore']], [self::STARI_RESPINSE, 86400 * $t['rambursate_zile']]] as [$stari, $secunde]) {
            foreach ($b->toate('SELECT id FROM {comenzi} WHERE anonimizat_la IS NULL AND stare IN (' . self::lista($stari) . ') AND actualizat_la <= ? ORDER BY id LIMIT ' . $cate,
                [...$stari, $this->app->ceas()->moment(-$secunde)]) as $r) {
                $ids[] = (int) $r['id'];
            }
        }
        $n = 0;
        foreach ($ids as $id) {
            if ($this->anonimizeaza($id)) {
                $n++;
            }
        }
        // (c) IP-ul complet și browserul din acorduri, după termenul în luni (și la comenzile încă vii).
        $prag = (new \DateTimeImmutable('@' . $this->app->ceas()->acum()))->modify('-' . $t['ip_luni'] . ' months')->format('Y-m-d H:i:s');
        $ip = $b->executa("UPDATE {acorduri} SET ip = '', agent = '' WHERE la <= ? AND (ip <> '' OR agent <> '')", [$prag])->rowCount();
        if ($n > 0 || $ip > 0) {
            $this->app->audit()->scrie('gdpr.anonimizare', null, ['comenzi' => $n, 'acorduri_ip' => $ip]);
        }
        return ['comenzi' => $n, 'ip' => $ip];
    }

    /**
     * Comanda se poate anonimiza acum: e încheiată, fără bani în mișcare. La „ștergerea” cerută de oaspete, o
     * rezervare confirmată se anonimizează doar după plecare (rezervarea lui încă trăiește până atunci).
     */
    public function poateFiAnonimizata(array $c): bool
    {
        if ($c['anonimizat_la'] !== null) {
            return false;
        }
        $stare = (string) $c['stare'];
        if (!in_array($stare, [...self::STARI_SEJUR, ...self::STARI_ABANDONATE, ...self::STARI_RESPINSE], true)) {
            return false;
        }
        if ($stare === 'confirmata' && (string) $c['plecare'] > $this->app->azi()) {
            return false;
        }
        // O anulare pornită din site și încă neîncheiată (EasyCheck sau rambursarea ei).
        if ($c['anulare_actor'] !== null && ($c['anulata_ec_la'] === null || ((int) ($c['anulare_suma'] ?? 0) > 0 && $c['anulare_rambursata_la'] === null))) {
            return false;
        }
        // O schimbare de date neîncheiată sau cu returnarea ei încă de făcut ține comanda deschisă
        // (executarea contractului, obligația de restituire); după încheiere, comanda urmează grupa stării ei.
        if ($this->app->baza()->valoare('SELECT 1 FROM {schimbari_date} WHERE comanda_id = ? AND (incheiata_la IS NULL OR (rambursare_suma > 0 AND rambursata_la IS NULL)) LIMIT 1',
            [(int) $c['id']]) !== null) {
            return false;
        }
        return $this->app->baza()->valoare("SELECT 1 FROM {operatii_plata} WHERE comanda_id = ? AND stare IN ('cerut', 'in_procesare') LIMIT 1", [(int) $c['id']]) === null;
    }

    /**
     * Anonimizează o comandă (dacă poate). Idempotentă. $deja: comanda era anonimizată înaintea unei restaurări, deci se
     * anonimizează din nou fără verificările de stare (datele ei nu trebuiau să revină).
     */
    public function anonimizeaza(int $id, bool $deja = false): bool
    {
        $b = $this->app->baza();
        $c = $this->app->comenzi()->dupaId($id);
        if ($c === null || (!$deja && !$this->poateFiAnonimizata($c)) || ($deja && $c['anonimizat_la'] !== null && $c['oaspete'] === '')) {
            return false;
        }
        return (bool) $b->tranzactie(function () use ($b, $id, $deja): bool {
            $acum = $this->app->ceas()->moment();
            $n = $b->executa("UPDATE {comenzi} SET oaspete = '', nota = NULL, email_io = '', corp_ec = '', ip24 = '', anonimizat_la = COALESCE(anonimizat_la, ?) WHERE id = ?"
                . ($deja ? '' : ' AND anonimizat_la IS NULL'), [$acum, $id])->rowCount();
            if ($n !== 1) {
                return false;
            }
            $b->executa("UPDATE {acorduri} SET ip = '', agent = '' WHERE comanda_id = ?", [$id]);
            $b->executa('UPDATE {operatii_plata} SET nota = NULL WHERE comanda_id = ?', [$id]);
            $b->executa('UPDATE {plati} SET metoda_scurt = NULL WHERE comanda_id = ?', [$id]);
            $b->executa('DELETE FROM {emailuri} WHERE comanda_id = ?', [$id]);
            $b->executa('DELETE FROM {coduri_email} WHERE comanda_id = ?', [$id]);
            // Corpurile rezervărilor EasyCheck ale schimbărilor de date poartă datele oaspetelui.
            $b->executa("UPDATE {schimbari_date} SET corp_ec = '', corp_refacere = NULL WHERE comanda_id = ?", [$id]);
            return true;
        });
    }

    /** Comenzile unui oaspete, după e-mail (indexul orb); cele anonimizate nu se mai găsesc. @return list<array> */
    public function comenziDupaEmail(string $email): array
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [];
        }
        return $this->app->baza()->toate('SELECT * FROM {comenzi} WHERE email_io = ? AND anonimizat_la IS NULL ORDER BY id', [$this->app->comenzi()->emailIo($email)]);
    }

    /**
     * Exportul datelor unui oaspete (dreptul de acces și portabilitatea), ca JSON: comenzile cu datele lui, acordurile
     * (versiunile textelor, acordul pentru noutăți, IP-ul cât e păstrat), e-mailurile trimise și plățile.
     */
    public function exporta(string $email): array
    {
        $b = $this->app->baza();
        $comenzi = [];
        foreach ($this->comenziDupaEmail($email) as $c) {
            $id = (int) $c['id'];
            $acorduri = [];
            foreach ($b->toate('SELECT * FROM {acorduri} WHERE comanda_id = ? ORDER BY id', [$id]) as $a) {
                $acorduri[] = ['la' => $a['la'], 'termeni_versiune' => $a['termeni_versiune'], 'confidentialitate_versiune' => $a['confidentialitate_versiune'],
                    'text_buton' => $a['text_buton'], 'noutati' => (int) $a['marketing'] === 1,
                    'ip' => $a['ip'] !== '' ? $this->app->comenzi()->decripteaza((string) $a['ip']) : null,
                    'browser' => $a['agent'] !== '' ? $this->app->comenzi()->decripteaza((string) $a['agent']) : null];
            }
            $emailuri = [];
            foreach ($b->toate('SELECT sablon, limba, stare, creat_la, trimis_la FROM {emailuri} WHERE comanda_id = ? ORDER BY id', [$id]) as $e) {
                $emailuri[] = $e;
            }
            $plati = [];
            foreach ($b->toate('SELECT suma_incasata, suma_rambursata, metoda, metoda_scurt, stare, creat_la FROM {plati} WHERE comanda_id = ? ORDER BY id', [$id]) as $p) {
                $plati[] = ['incasat_bani' => (int) $p['suma_incasata'], 'rambursat_bani' => (int) $p['suma_rambursata'], 'metoda' => $p['metoda'], 'card' => $p['metoda_scurt'],
                    'stare' => $p['stare'], 'la' => $p['creat_la']];
            }
            // Schimbările de date: datele vechi și noi, sumele și starea; corpul trimis la EasyCheck repetă datele de mai sus.
            $schimbari = [];
            foreach ($b->toate('SELECT stare, sosire_veche, plecare_veche, sosire, plecare, total_vechi, total, diferenta, rambursare_suma, creat_la, incheiata_la FROM {schimbari_date} WHERE comanda_id = ? ORDER BY id',
                [$id]) as $x) {
                $schimbari[] = ['stare' => $x['stare'], 'sosire_veche' => $x['sosire_veche'], 'plecare_veche' => $x['plecare_veche'], 'sosire' => $x['sosire'], 'plecare' => $x['plecare'],
                    'total_vechi_bani' => (int) $x['total_vechi'], 'total_bani' => (int) $x['total'], 'diferenta_bani' => (int) $x['diferenta'],
                    'returnat_bani' => (int) $x['rambursare_suma'], 'cerut_la' => $x['creat_la'], 'incheiat_la' => $x['incheiata_la']];
            }
            $comenzi[] = [
                'ref' => $c['ref'], 'stare' => $c['stare'], 'fel' => $c['fel'], 'limba' => $c['limba'], 'sosire' => $c['sosire'], 'plecare' => $c['plecare'],
                'camere' => json_decode((string) $c['camere'], true), 'total_bani' => (int) $c['total'], 'oaspete' => $this->app->comenzi()->oaspete($c), 'tara' => $c['tara'],
                'nota' => $this->app->comenzi()->nota($c), 'noutati' => (int) $c['marketing_acord'] === 1, 'noutati_retras_la' => $c['marketing_retras_la'], 'creat_la' => $c['creat_la'],
                'acorduri' => $acorduri, 'emailuri' => $emailuri, 'plati' => $plati, 'schimbari_date' => $schimbari,
            ];
        }
        return ['generat_la' => $this->app->ceas()->moment(), 'comenzi' => $comenzi];
    }

    /**
     * Ștergerea cerută de oaspete: anonimizarea pe loc a comenzilor încheiate. Cele încă vii (o rezervare viitoare,
     * bani în mișcare) rămân și se întorc în `pastrate`, ca gazda să revină după încheierea lor. Ștergerea se notează
     *: rezervările EasyCheck ale comenzilor anonimizate pleacă apoi spre EasyCheck (StergereEasyCheck), fără ca
     * ștergerea din site să le aștepte. `stergere` e id-ul ei (null când nicio comandă nu s-a anonimizat).
     * @return array{anonimizate:list<string>, pastrate:list<string>, stergere:?int}
     */
    public function sterge(string $email, ?int $utilizator = null): array
    {
        $r = ['anonimizate' => [], 'pastrate' => [], 'stergere' => null];
        $sterse = [];
        foreach ($this->comenziDupaEmail($email) as $c) {
            if ($this->anonimizeaza((int) $c['id'])) {
                $r['anonimizate'][] = (string) $c['ref'];
                $sterse[] = $c;
            } else {
                $r['pastrate'][] = (string) $c['ref'];
            }
        }
        if ($sterse !== []) {
            $r['stergere'] = $this->app->gdprEasyCheck()->noteaza($sterse, $r['pastrate'], $utilizator);
        }
        return $r;
    }

    private static function lista(array $x): string
    {
        return implode(', ', array_fill(0, count($x), '?'));
    }
}
