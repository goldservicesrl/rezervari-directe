<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

use Cazo\Baza\Baza;
use Cazo\Nucleu\CachePagini;
use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Jurnal;
use Cazo\Nucleu\Setari;

defined('CAZO') or exit;

/**
 * Oglinda EasyCheck: corpul brut al fiecărei resurse în `ec_resurse`, cu ETag, și proiecțiile.
 *
 * O citire trimite mereu If-None-Match. La 304 se actualizează doar `verificat_la`. La 200 se compară amprenta
 * corpului (fără momentul generării); la un corp nou, proiecțiile se reconstruiesc într-o tranzacție, `versiune`
 * crește, iar la resursele de conținut crește și versiunea conținutului, care schimbă generația cache-ului de
 * pagini și golește totalurile ținute minte. Un corp cu altă formă se refuză: datele de dinainte rămân.
 */
final class Oglinda
{
    /** resursa => [calea, intervalul de interogare în secunde, e conținut public, are fereastră de zile] */
    public const RESURSE = [
        'eu' => ['/eu', 21600, false, false],
        'proprietate' => ['/proprietate', 1800, true, false],
        'camere' => ['/camere', 1800, true, false],
        'disponibilitate' => ['/disponibilitate', 300, true, true],
        'preturi' => ['/preturi', 900, true, true],
        'webhook' => ['/webhook', 21600, false, false],
    ];

    /** Pauza cea mai lungă între reluări după eșecuri la rând. */
    public const PAUZA_MAXIMA = 900;

    /** Fereastra disponibilității și a prețurilor: de ieri, 366 de nopți. */
    public const NOPTI_FEREASTRA = 366;

    /** @var array<string, list<callable(array|null, string): void>> */
    private array $laSchimbare = [];

    public function __construct(
        private Baza $baza,
        private Ceas $ceas,
        private Client $client,
        private Setari $setari,
        private string $privat,
        private ?Jurnal $jurnal = null,
    ) {
    }

    /** @param callable(?array $corp, string $resursa): void $f rulează după tranzacția unui corp nou */
    public function laSchimbare(string $resursa, callable $f): void
    {
        $this->laSchimbare[$resursa][] = $f;
    }

    /** [de, pana) pentru ziua curentă din București (cel puțin ieri și în UTC, deci acceptată de EasyCheck). */
    public function fereastra(): array
    {
        $tz = new \DateTimeZone('Europe/Bucharest');
        $azi = (new \DateTimeImmutable('@' . $this->ceas->acum()))->setTimezone($tz)->setTime(0, 0);
        $de = $azi->modify('-1 day');
        return ['de' => $de->format('Y-m-d'), 'pana' => $de->modify('+' . self::NOPTI_FEREASTRA . ' days')->format('Y-m-d')];
    }

    public function rand(string $resursa): ?array
    {
        return $this->baza->rand('SELECT * FROM {ec_resurse} WHERE resursa = ?', [$resursa]);
    }

    /**
     * Corpul decodat al resursei, sau null. E citirea pentru afișare (site, e-mailuri, panou): literele vechi cu sedilă
     * din textele scrise în EasyCheck (Ş, ş, Ţ, ţ) ies corectate, prin `Format::diacritice`, în toate
     * câmpurile deodată (numele, adresa, sediul, denumirea firmei, titlurile camerelor). Valoarea păstrată în bază
     * rămâne cea primită, iar forma textului (majusculele, prescurtările) rămâne a gazdei.
     */
    public function corp(string $resursa): ?array
    {
        $c = $this->baza->valoare('SELECT corp FROM {ec_resurse} WHERE resursa = ?', [$resursa]);
        $d = is_string($c) ? json_decode($c, true) : null;
        if (!is_array($d)) {
            return null;
        }
        array_walk_recursive($d, static function (mixed &$v): void {
            if (is_string($v)) {
                $v = \Cazo\I18n\Format::diacritice($v);
            }
        });
        return $d;
    }

    /** Setarea bifei „Arătați telefonul pe site” din panou (Legal), implicit nebifată. */
    public const TELEFON_PE_SITE = 'contact.telefon_pe_site';

    /**
     * Proprietatea așa cum apare pe paginile publice: telefonul gazdei rămâne în afara site-ului până
     * când gazda bifează „Arătați telefonul pe site”, chiar dacă EasyCheck îl are. Legea cere un contact înaintea
     * contractului (OUG 34/2014): e-mailul rămâne mereu, iar o gazdă fără e-mail în EasyCheck își păstrează telefonul
     * pe site, ca singurul ei contact. După rezervarea confirmată (e-mailul de confirmare, „Rezervarea mea”, „Cum
     * ajungeți”), oaspetele primește telefonul din `corp('proprietate')`.
     */
    public function proprietatePublica(): ?array
    {
        $p = $this->corp('proprietate');
        if ($p === null || !is_array($p['contact'] ?? null)) {
            return $p;
        }
        if ($this->setari->get(self::TELEFON_PE_SITE, false) !== true && trim((string) ($p['contact']['email'] ?? '')) !== '') {
            $p['contact']['telefon'] = '';
        }
        return $p;
    }

    /** Versiunea conținutului (crește la orice corp nou al unei resurse de conținut); intră în cheile de cache. */
    public function versiuneContinut(): int
    {
        return (int) $this->setari->get('ec.versiune_continut', 0);
    }

    /** @param string[] $resurse */
    public function marcheazaInvechit(array $resurse): void
    {
        foreach (array_unique($resurse) as $r) {
            if (!isset(self::RESURSE[$r])) {
                continue;
            }
            $this->asiguraRand($r);
            $this->baza->executa('UPDATE {ec_resurse} SET invechit = 1 WHERE resursa = ?', [$r]);
        }
    }

    /**
     * Golește oglinda: corpurile, ETag-urile și proiecțiile lor (nopțile, prețurile, tarifele, taxele,
     * totalurile ținute minte). Pentru cheia înlocuită în asistentul de instalare cu una de alt fel (de probă în loc de
     * live) sau a altei proprietăți: nimic citit cu cheia veche nu rămâne, nici când o citire cu cheia nouă pică.
     */
    public function goleste(): void
    {
        $this->baza->tranzactie(function (Baza $b): void {
            foreach (['nopti', 'nopti_pret', 'planuri', 'taxe_ec', 'totaluri', 'ec_mesaje'] as $t) {
                if ($b->tabelExista($t)) {
                    $b->executa('DELETE FROM ' . $b->t($t));
                }
            }
            $b->executa('DELETE FROM {ec_resurse}');
            $this->setari->pune('ec.versiune_continut', $this->versiuneContinut() + 1);
        });
        $this->schimbaGeneratiaCache();
    }

    /**
     * Resursele de citit acum: cele învechite (anunț, eroare), apoi cele scadente după intervalul lor de citire,
     * în ordinea priorității (cheia, disponibilitatea, prețurile, conținutul, webhookul).
     * @return string[]
     */
    public function scadente(): array
    {
        $randuri = [];
        foreach ($this->baza->toate('SELECT resursa, verificat_la, invechit, fereastra, reincercare_la FROM {ec_resurse}') as $r) {
            $randuri[$r['resursa']] = $r;
        }
        $fereastra = $this->fereastraText();
        $rez = [];
        foreach (self::RESURSE as $nume => [, $interval, , $cuFereastra]) {
            $r = $randuri[$nume] ?? null;
            if ($r !== null && $r['reincercare_la'] !== null && (string) $r['reincercare_la'] > $this->ceas->moment()) {
                continue;
            }
            if ($r === null || $r['verificat_la'] === null || (int) $r['invechit'] === 1
                || Ceas::dinMoment((string) $r['verificat_la']) + $interval <= $this->ceas->acum()
                || ($cuFereastra && $r['fereastra'] !== $fereastra)) {
                $rez[] = $nume;
            }
        }
        return $rez;
    }

    /** @return string[] */
    public function invechite(): array
    {
        // Doar resursele oglinzii: rândul `mesaje` îl citește MesajeEc, sub funcția lui.
        return array_values(array_filter(array_map('strval', $this->baza->executa('SELECT resursa FROM {ec_resurse} WHERE invechit = 1 ORDER BY resursa')->fetchAll(\PDO::FETCH_COLUMN)),
            static fn (string $r): bool => isset(self::RESURSE[$r])));
    }

    /**
     * Citește resursa cu If-None-Match și actualizează oglinda.
     * @return string „neschimbat” (304 sau același corp), „schimbat” (corp nou, proiecții refăcute), „absent” (404 la webhook), „nepermis” (403 scop_insuficient la webhook)
     * @throws EroareApi|EroareTransport|\UnexpectedValueException; ultima eroare rămâne în `ec_resurse`
     */
    public function citeste(string $resursa, bool $caleaAnuntului = false): string
    {
        [$cale, , , $cuFereastra] = self::RESURSE[$resursa] ?? throw new \InvalidArgumentException('Resursă necunoscută: ' . $resursa);
        $this->asiguraRand($resursa);
        $rand = (array) $this->rand($resursa);
        $query = [];
        $fereastra = null;
        if ($cuFereastra) {
            $query = $this->fereastra();
            $fereastra = $query['de'] . '/' . $query['pana'];
        }
        // O fereastră nouă (o zi nouă) cere corpul întreg: ETag-ul vechi descrie alte zile.
        $etag = $rand['corp'] !== null && ($fereastra === null || $rand['fereastra'] === $fereastra) ? $rand['etag'] : null;
        $acum = $this->ceas->moment();
        try {
            try {
                $r = $this->client->citeste($cale, $query, $etag, $caleaAnuntului);
            } catch (EroareApi $e) {
                if ($resursa === 'webhook' && $e->comportament === 'negasit') {
                    return $this->salveaza($resursa, null, null, $fereastra, 'absent');
                }
                // Cheia demo și cea cu scop citire nu văd webhookul: starea se ține minte ca resursă citită,
                // cu intervalul ei obișnuit (6 h), în locul reluărilor la 15 minute.
                if ($resursa === 'webhook' && $e->cod === 'scop_insuficient') {
                    return $this->salveaza($resursa, null, null, $fereastra, 'nepermis');
                }
                throw $e;
            }
            if ($r->status === 304) {
                $this->baza->executa('UPDATE {ec_resurse} SET verificat_la = ?, invechit = 0, ultima_eroare = NULL, esecuri = 0, reincercare_la = NULL WHERE resursa = ?', [$acum, $resursa]);
                return 'neschimbat';
            }
            $corp = $r->json();
            if ($corp === null || array_is_list($corp)) {
                throw new \UnexpectedValueException('Corpul nu e un obiect JSON.');
            }
            return $this->salveaza($resursa, $r->corp, $r->etag(), $fereastra, null, $corp);
        } catch (\Throwable $e) {
            $esecuri = (int) ($rand['esecuri'] ?? 0) + 1;
            $pauza = min(self::PAUZA_MAXIMA, 60 * (2 ** min(10, $esecuri - 1)));
            $this->baza->executa('UPDATE {ec_resurse} SET ultima_eroare = ?, esecuri = ?, reincercare_la = ? WHERE resursa = ?',
                [mb_substr(Jurnal::mascheaza(self::descrie($e)), 0, 480), $esecuri, $this->ceas->moment($pauza), $resursa]);
            throw $e;
        }
    }

    public static function descrie(\Throwable $e): string
    {
        return $e instanceof EroareApi || $e instanceof EroareTransport ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage();
    }

    private function salveaza(string $resursa, ?string $corpBrut, ?string $etag, ?string $fereastra, ?string $stareFaraCorp, ?array $corp = null): string
    {
        $acum = $this->ceas->moment();
        $hash = $corpBrut === null ? $stareFaraCorp : self::amprenta($corp);
        $vechi = (string) ($this->baza->valoare('SELECT hash FROM {ec_resurse} WHERE resursa = ?', [$resursa]) ?? '');
        if ($vechi === $hash) {
            $this->baza->executa(
                'UPDATE {ec_resurse} SET etag = ?, corp = ?, citit_la = ?, verificat_la = ?, invechit = 0, ultima_eroare = NULL, esecuri = 0, reincercare_la = NULL, fereastra = ? WHERE resursa = ?',
                [$etag, $corpBrut, $acum, $acum, $fereastra, $resursa]
            );
            return $stareFaraCorp ?? 'neschimbat';
        }
        [, , $continut] = self::RESURSE[$resursa];
        $this->baza->tranzactie(function (Baza $b) use ($resursa, $corp, $corpBrut, $etag, $hash, $acum, $fereastra, $continut): void {
            if ($corp !== null) {
                $this->proiecteaza($resursa, $corp);
            }
            $b->executa(
                'UPDATE {ec_resurse} SET etag = ?, corp = ?, hash = ?, citit_la = ?, verificat_la = ?, schimbat_la = ?, invechit = 0,
                    versiune = versiune + 1, ultima_eroare = NULL, esecuri = 0, reincercare_la = NULL, fereastra = ? WHERE resursa = ?',
                [$etag, $corpBrut, $hash, $acum, $acum, $acum, $fereastra, $resursa]
            );
            if ($continut) {
                $this->setari->pune('ec.versiune_continut', $this->versiuneContinut() + 1);
                if ($resursa === 'preturi' && $b->tabelExista('totaluri')) {
                    $b->executa('DELETE FROM {totaluri}');
                }
            }
        });
        if ($continut) {
            $this->schimbaGeneratiaCache();
        }
        foreach ($this->laSchimbare[$resursa] ?? [] as $f) {
            $f($corp, $resursa);
        }
        $this->jurnal?->info('Oglinda EasyCheck: ' . $resursa . ' nou', ['versiune' => (int) $this->baza->valoare('SELECT versiune FROM {ec_resurse} WHERE resursa = ?', [$resursa])]);
        return $stareFaraCorp ?? 'schimbat';
    }

    /** Conținutul paginilor s-a schimbat în afara oglinzii (modul cheii): versiunea nouă și generația nouă a cache-ului. */
    public function schimbaContinutul(): void
    {
        $this->setari->pune('ec.versiune_continut', $this->versiuneContinut() + 1);
        $this->schimbaGeneratiaCache();
    }

    /** Generația cache-ului de pagini: versiunea conținutului din oglindă și versiunea setărilor temei. */
    public function schimbaGeneratiaCache(): void
    {
        try {
            CachePagini::schimbaGeneratia($this->privat, (string) $this->versiuneContinut(), (string) (int) $this->setari->get('tema.versiune', 0));
        } catch (\Throwable $e) {
            $this->jurnal?->avertisment('Generația cache-ului nu s-a putut schimba', ['eroare' => $e->getMessage()]);
        }
    }

    /** Amprenta corpului, fără câmpul generat la fiecare cerere (`generat_la`). */
    public static function amprenta(array $corp): string
    {
        unset($corp['generat_la']);
        return hash('sha256', json_encode($corp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function proiecteaza(string $resursa, array $corp): void
    {
        switch ($resursa) {
            case 'disponibilitate':
                $randuri = Proiectii::disponibilitate($corp);
                $this->baza->executa('DELETE FROM {nopti}');
                $this->insereazaMulte('nopti', ['camera', 'zi', 'libere', 'inchis'], $randuri);
                break;
            case 'preturi':
                $p = Proiectii::preturi($corp);
                $this->baza->executa('DELETE FROM {nopti_pret}');
                $this->insereazaMulte('nopti_pret', ['camera', 'plan', 'zi', 'pret', 'sejur_minim', 'sejur_minim_prin', 'sejur_maxim', 'sosire_inchisa', 'plecare_inchisa', 'inchis'], $p['nopti']);
                $this->baza->executa('DELETE FROM {planuri}');
                $this->insereazaMulte('planuri', ['id', 'titlu', 'fel', 'tip', 'prag_nopti', 'mod', 'din_plan', 'regula', 'rotunjire', 'pe_persoana', 'avans_procent', 'anulare_zile', 'masa', 'masa_eticheta', 'masa_eticheta_en', 'ordine'], $p['planuri']);
                $v = (int) ($this->baza->valoare("SELECT versiune FROM {taxe_ec} WHERE nume = 'taxe'") ?? 0);
                $this->baza->upsert('taxe_ec', ['nume' => 'taxe', 'corp' => json_encode($p['taxe'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'versiune' => $v + 1],
                    ['nume'], ['corp', 'versiune']);
                break;
            case 'eu':
                Proiectii::eu($corp);
                break;
            case 'proprietate':
                Proiectii::poze($corp, null);
                break;
            case 'camere':
                Proiectii::poze(null, $corp);
                break;
        }
    }

    /** Cei mai mulți parametri legați într-o singură instrucțiune: SQLite mai vechi de 3.32 primește cel mult 999. */
    public const PARAMETRI_MAX = \Cazo\Baza\Baza::PARAMETRI_MAX;

    /** Câte rânduri intră într-un pachet de INSERT, ca numărul parametrilor să rămână sub PARAMETRI_MAX (cel mult 100). */
    public static function marimePachet(int $coloane): int
    {
        return \Cazo\Baza\Baza::marimePachet($coloane, 100);
    }

    /**
     * INSERT pe mai multe rânduri, în pachete. Pe găzduirile cu SQLite mai vechi de 3.32 (proba pe server.2026: PHP 8.4
     * din cPanel), 100 de rânduri cu peste 9 coloane dădeau „too many SQL variables”, iar prețurile rămâneau necitite.
     */
    private function insereazaMulte(string $tabel, array $coloane, array $randuri): void
    {
        $d = $this->baza->dialect();
        $col = implode(', ', array_map($d->citeaza(...), $coloane));
        $unul = '(' . implode(', ', array_fill(0, count($coloane), '?')) . ')';
        foreach (array_chunk($randuri, self::marimePachet(count($coloane))) as $pachet) {
            $valori = [];
            foreach ($pachet as $r) {
                foreach ($coloane as $c) {
                    $valori[] = $r[$c];
                }
            }
            $this->baza->executa('INSERT INTO ' . $this->baza->t($tabel) . " ($col) VALUES " . implode(', ', array_fill(0, count($pachet), $unul)), $valori);
        }
    }

    private function asiguraRand(string $resursa): void
    {
        $this->baza->upsert('ec_resurse', ['resursa' => $resursa, 'invechit' => 0, 'versiune' => 0], ['resursa'], []);
    }

    private function fereastraText(): string
    {
        $f = $this->fereastra();
        return $f['de'] . '/' . $f['pana'];
    }
}
