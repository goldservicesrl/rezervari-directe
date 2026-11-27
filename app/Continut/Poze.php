<?php

declare(strict_types=1);

namespace Cazo\Continut;

use Cazo\Baza\Baza;
use Cazo\EasyCheck\Imagini;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * Pozele site-ului (M6b): pe lângă pozele din EasyCheck, gazda încarcă poze
 * direct în site, pentru galerie și secțiuni. Pozele încărcate primesc în panou categoria (lista închisă a categoriilor, legenda
 * „Baie”, „Dormitorul 2”) și indicele; pozele din EasyCheck își iau categoria din EasyCheck (până o expune API-ul,
 * fără categorie, cu legenda generică). Toate se pot ordona, ascunde și alege ca poză principală.
 *
 * Încărcarea e sigură: doar JPEG, PNG și WebP (după conținut, nu după nume; SVG și orice alt tip se refuză), cel mult
 * 15 MB și 8000 px pe latură; din fișier nu se păstrează nimic neprelucrat: derivatele (480-2048 px, AVIF/WebP/JPEG)
 * sunt desenate din pixeli de GD, fără metadate (EXIF, GPS), prin aceeași `Imagini::deriva` ca pozele EasyCheck.
 * Cel mult 100 de poze încărcate; originalul nu se păstrează.
 */
final class Poze
{
    public const MARIME_MAXIMA = 15 * 1024 * 1024;
    public const MAXIM_INCARCATE = 100;
    public const SURSA_LOCALA = 'local:incarcata';
    /** Pozele încărcate fără ordinea aleasă stau după locul acesta (după pozele din EasyCheck, numerotate de la 1). */
    public const LOC_INCARCATE = 1000;

    /** Categoriile pozelor, în ordinea galeriei; cele cu indice: cameră, dormitor, baie. */
    public const CATEGORII = ['exterior', 'intrare', 'living', 'camera', 'dormitor', 'bucatarie', 'loc_de_masa', 'baie', 'spatiu_lucru', 'balcon', 'terasa', 'gradina',
        'piscina', 'spatii_comune', 'restaurant', 'parcare', 'priveliste', 'imprejurimi', 'altele'];
    public const CU_INDICE = ['camera', 'dormitor', 'baie'];

    public function __construct(private Baza $baza, private Ceas $ceas, private string $dirPublic)
    {
    }

    /**
     * Încarcă o poză: întoarce amprenta (SHA-256 al fișierului primit) sau aruncă o excepție cu un cod
     * (`marime`, `tip`, `limita`, `exista`).
     */
    public function incarca(string $octeti, ?string $categorie = null): string
    {
        if ($octeti === '' || strlen($octeti) > self::MARIME_MAXIMA) {
            throw new \UnexpectedValueException('marime');
        }
        $info = @getimagesizefromstring($octeti);
        if ($info === false || !isset(Imagini::TIPURI[$info[2]])) {
            throw new \UnexpectedValueException('tip');
        }
        if ((int) $this->baza->valoare('SELECT COUNT(*) FROM {poze_local} WHERE incarcata = 1') >= self::MAXIM_INCARCATE) {
            throw new \UnexpectedValueException('limita');
        }
        $sha = hash('sha256', $octeti);
        if ($this->baza->valoare('SELECT 1 FROM {poze} WHERE sha256 = ?', [$sha]) !== null) {
            // Poza există, dar fișierele ei lipsesc de pe disc (o mutare, o restaurare veche): reîncărcarea le reface.
            if ($this->areFisiere($sha) || $this->baza->valoare('SELECT 1 FROM {poze} WHERE sha256 = ? AND url_sursa = ?', [$sha, self::SURSA_LOCALA]) === null) {
                throw new \UnexpectedValueException('exista');
            }
            try {
                $d = Imagini::deriva($octeti, $this->dirMedia() . '/' . $sha);
            } catch (\UnexpectedValueException) {
                throw new \UnexpectedValueException('tip');
            }
            $this->baza->executa("UPDATE {poze} SET stare = 'derivata', latime = ?, inaltime = ?, variante = ? WHERE sha256 = ?",
                [$d['latime'], $d['inaltime'], json_encode(['latimi' => $d['latimi'], 'formate' => $d['formate']], JSON_THROW_ON_ERROR), $sha]);
            return $sha;
        }
        try {
            $d = Imagini::deriva($octeti, $this->dirMedia() . '/' . $sha);
        } catch (\UnexpectedValueException) {
            throw new \UnexpectedValueException('tip');
        }
        $acum = $this->ceas->moment();
        // Locul implicit al pozei încărcate (`poze.pozitie`, de la 1001, în ordinea încărcării), după pozele din EasyCheck;
        // ordinea aleasă de gazdă (`poze_local.ordine`) pornește de la 0, adică fără alegere.
        $pozitie = max(self::LOC_INCARCATE, (int) $this->baza->valoare('SELECT COALESCE(MAX(pozitie), 0) FROM {poze} WHERE url_sursa = ?', [self::SURSA_LOCALA])) + 1;
        $this->baza->tranzactie(function () use ($sha, $d, $acum, $categorie, $pozitie): void {
            $this->baza->insereaza('poze', ['sha256' => $sha, 'url_sursa' => self::SURSA_LOCALA, 'latime' => $d['latime'], 'inaltime' => $d['inaltime'], 'pozitie' => $pozitie,
                'stare' => 'derivata', 'variante' => json_encode(['latimi' => $d['latimi'], 'formate' => $d['formate']], JSON_THROW_ON_ERROR), 'folosit_la' => $acum, 'incercari' => 0]);
            $this->baza->insereaza('poze_local', ['sha256' => $sha, 'camera' => null, 'rol' => in_array($categorie, self::CATEGORII, true) ? $categorie : null, 'ordine' => 0,
                'incarcata' => 1, 'creat_la' => $acum]);
        });
        return $sha;
    }

    /** Șterge o poză încărcată (doar pe acestea; pozele EasyCheck se ascund, nu se șterg). */
    public function sterge(string $sha): bool
    {
        if (!preg_match('~^[0-9a-f]{64}$~', $sha) || $this->baza->valoare('SELECT 1 FROM {poze_local} WHERE sha256 = ? AND incarcata = 1', [$sha]) === null) {
            return false;
        }
        $this->baza->executa('DELETE FROM {poze_local} WHERE sha256 = ?', [$sha]);
        $this->baza->executa('DELETE FROM {poze} WHERE sha256 = ? AND url_sursa = ?', [$sha, self::SURSA_LOCALA]);
        $dir = $this->dirMedia() . '/' . $sha;
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
        return true;
    }

    /** Derivatele pozei sunt pe disc (cel puțin un fișier). */
    public function areFisiere(string $sha): bool
    {
        return preg_match('~^[0-9a-f]{64}$~', $sha) === 1 && (glob($this->dirMedia() . '/' . $sha . '/*') ?: []) !== [];
    }

    /**
     * Alegerile gazdei pe o poză a proprietății (din EasyCheck sau încărcată): categoria, indicele, principala, ordinea,
     * ascunderea. O singură poză principală.
     */
    public function seteaza(string $sha, ?string $categorie, ?int $indice, bool $principala, int $ordine, bool $ascunsa): bool
    {
        if (!preg_match('~^[0-9a-f]{64}$~', $sha) || $this->baza->valoare('SELECT 1 FROM {poze} WHERE sha256 = ?', [$sha]) === null) {
            return false;
        }
        // categoria pozelor din EasyCheck vine din EasyCheck; panoul o pune doar pozelor încărcate în site.
        $incarcata = $this->baza->valoare('SELECT 1 FROM {poze} WHERE sha256 = ? AND url_sursa = ?', [$sha, self::SURSA_LOCALA]) !== null;
        $categorie = $incarcata && in_array($categorie, self::CATEGORII, true) ? $categorie : null;
        $indice = $categorie !== null && in_array($categorie, self::CU_INDICE, true) && $indice !== null && $indice >= 1 && $indice <= 20 ? $indice : null;
        $this->baza->tranzactie(function () use ($sha, $categorie, $indice, $principala, $ordine, $ascunsa): void {
            if ($principala) {
                $this->baza->executa('UPDATE {poze_local} SET principala = 0 WHERE camera IS NULL');
            }
            $date = ['rol' => $categorie, 'indice' => $indice, 'principala' => (int) $principala, 'ordine' => max(-999, min(9999, $ordine)), 'ascunsa' => (int) $ascunsa];
            if ($this->baza->valoare('SELECT 1 FROM {poze_local} WHERE sha256 = ? AND camera IS NULL', [$sha]) !== null) {
                $set = implode(', ', array_map(static fn (string $k): string => "$k = ?", array_keys($date)));
                $this->baza->executa("UPDATE {poze_local} SET $set WHERE sha256 = ? AND camera IS NULL", [...array_values($date), $sha]);
            } else {
                $this->baza->insereaza('poze_local', $date + ['sha256' => $sha, 'camera' => null, 'incarcata' => 0, 'creat_la' => $this->ceas->moment()]);
            }
        });
        return true;
    }

    /**
     * Pozele proprietății pentru site, în ordinea gazdei, pe o singură scară pentru ambele feluri de poze: întâi cea
     * principală, apoi locul fiecăreia. Locul ales în panou (`poze_local.ordine`, diferit de 0) e chiar locul pozei
     * (1 = prima, după cea principală), la pozele din EasyCheck și la cele încărcate deopotrivă; 0 înseamnă fără alegere,
     * iar poza își păstrează locul implicit: pozele din EasyCheck în ordinea lor (1, 2, 3...), apoi cele încărcate, în
     * ordinea încărcării (după LOC_INCARCATE). La același loc, poza cu locul ales trece înaintea celei fără alegere.
     * Fără cele ascunse (ascunse()). $ec: pozele din oglindă (sha256, latime, inaltime).
     * @return list<array{sha256:string, latime:?int, inaltime:?int, categorie:?string, indice:?int}>
     */
    public function proprietate(array $ec): array
    {
        $local = [];
        foreach ($this->baza->toate('SELECT l.*, p.pozitie AS pozitie_incarcata FROM {poze_local} l LEFT JOIN {poze} p ON p.sha256 = l.sha256 AND p.url_sursa = ?
            WHERE l.camera IS NULL', [self::SURSA_LOCALA]) as $r) {
            $local[(string) $r['sha256']] = $r;
        }
        $ascunse = array_flip($this->ascunse());
        $lista = [];
        $ecDupaSha = [];
        foreach (array_values($ec) as $i => $x) {
            if (!is_array($x) || !is_string($x['sha256'] ?? null)) {
                continue;
            }
            $ecDupaSha[$x['sha256']] = $x;
            $lista[$x['sha256']] = ['sha256' => $x['sha256'], 'latime' => $x['latime'] ?? null, 'inaltime' => $x['inaltime'] ?? null, 'implicit' => $i + 1];
        }
        $ec = $ecDupaSha;
        foreach ($local as $sha => $r) {
            if ((int) $r['incarcata'] === 1 && !isset($lista[$sha])) {
                $lista[$sha] = ['sha256' => $sha, 'latime' => null, 'inaltime' => null, 'implicit' => max(self::LOC_INCARCATE + 1, (int) ($r['pozitie_incarcata'] ?? 0)), 'incarcata' => true];
            }
        }
        $rez = [];
        foreach ($lista as $sha => $x) {
            if (isset($ascunse[$sha])) {
                continue;
            }
            $r = $local[$sha] ?? null;
            // la pozele EasyCheck, categoria din EasyCheck (din 7.550.85, `categorie.cheie`); la cele încărcate, din panou.
            // Poza EasyCheck întreagă rămâne în `ec`, pentru legenda, spațiul și camera ei (ModelVedere::poza).
            $incarcata = (bool) ($x['incarcata'] ?? false);
            $cheieEc = $incarcata ? null : ($ec[$sha]['categorie']['cheie'] ?? $ec[$sha]['categorie'] ?? null);
            $cat = $incarcata ? ($r['rol'] ?? null) : (in_array($cheieEc, self::CATEGORII, true) ? $cheieEc : null);
            $ales = $r !== null && (int) $r['ordine'] !== 0;
            $rez[] = ['sha256' => $x['sha256'], 'latime' => $x['latime'], 'inaltime' => $x['inaltime'], 'categorie' => $cat,
                'ec' => $incarcata ? null : $ec[$sha],
                'indice' => $incarcata && isset($r['indice']) ? (int) $r['indice'] : null,
                '_cheie' => [-(int) ($r['principala'] ?? 0), $ales ? (int) $r['ordine'] : (int) $x['implicit'], $ales ? 0 : 1, (int) $x['implicit']]];
        }
        usort($rez, static fn (array $a, array $b): int => $a['_cheie'] <=> $b['_cheie']);
        return array_map(static function (array $x): array {
            unset($x['_cheie']);
            return $x;
        }, $rez);
    }

    /**
     * Pozele ascunse de gazdă în panou (`poze_local.ascunsa` pe rândul proprietății): lipsesc de pe tot site-ul, din
     * galeria proprietății și din paginile camerelor (ModelVedere::camera).
     * @return list<string> amprentele lor
     */
    public function ascunse(): array
    {
        return array_map('strval', $this->baza->executa('SELECT sha256 FROM {poze_local} WHERE camera IS NULL AND ascunsa = 1')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<array> toate pozele, pentru panou, cu sursa, starea și alegerile gazdei */
    public function pentruPanou(array $ec): array
    {
        $local = [];
        foreach ($this->baza->toate('SELECT * FROM {poze_local} WHERE camera IS NULL') as $r) {
            $local[(string) $r['sha256']] = $r;
        }
        $sha = array_values(array_unique(array_merge(array_values(array_filter(array_map(static fn ($x) => is_array($x) ? ($x['sha256'] ?? null) : null, $ec), 'is_string')),
            array_keys(array_filter($local, static fn (array $r): bool => (int) $r['incarcata'] === 1)))));
        $rez = [];
        foreach ($sha as $s) {
            $r = $local[$s] ?? [];
            $p = $this->baza->rand('SELECT stare FROM {poze} WHERE sha256 = ?', [$s]);
            $rez[] = ['sha256' => $s, 'incarcata' => (int) ($r['incarcata'] ?? 0) === 1, 'categorie' => $r['rol'] ?? '', 'indice' => $r['indice'] ?? null,
                'principala' => (int) ($r['principala'] ?? 0) === 1, 'ordine' => (int) ($r['ordine'] ?? 0), 'ascunsa' => (int) ($r['ascunsa'] ?? 0) === 1,
                'gata' => ($p['stare'] ?? null) === 'derivata', 'fisiere_lipsa' => ($p['stare'] ?? null) === 'derivata' && !$this->areFisiere($s)];
        }
        return $rez;
    }

    private function dirMedia(): string
    {
        $d = $this->dirPublic . '/media';
        if (!is_dir($d)) {
            mkdir($d, 0755, true);
        }
        return $d;
    }
}
