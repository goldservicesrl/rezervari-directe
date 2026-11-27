<?php

declare(strict_types=1);

namespace Cazo\Teme;

use Cazo\Baza\Baza;
use Cazo\EasyCheck\Oglinda;
use Cazo\EasyCheck\Poze;
use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Nucleu\Cai;
use Cazo\Nucleu\Url;
use Cazo\Pret\PretNoapte;

defined('CAZO') or exit;

/**
 * Modelul de vizualizare v1 (scheme/model-vedere-v1.json): contractul dintre nucleu și teme.
 * Doar tablouri și scalari, mărginite prin construcție (≤ 50 de camere, cât primește panoul; ≤ 200 de poze). Datele operatorului lipsesc
 * din context: blocul legal e al nucleului.
 *
 * Sursele: oglinda EasyCheck (proprietatea, camerele, pozele), completările locale (camere_local, poze_local,
 * pagini, continut) și prețul pe noapte (PretNoapte), pentru „de la X lei pe noapte”.
 */
final class ModelVedere
{
    public const CAMERE_MAX = \Cazo\Local\Depozit::CAMERE_MAX;
    public const POZE_MAX = 200;
    public const VERSIUNE = '1';

    private ?array $camere = null;
    /** @var list<string>|null */
    private ?array $pozeAscunse = null;

    public function __construct(
        private Baza $baza,
        private Oglinda $oglinda,
        private Poze $poze,
        private PretNoapte $pret,
        private Url $url,
        private Traducator $t,
        private string $azi,
        private bool $cuPreturi,
        /** Pagina Accesibilitate e publicată; fixturile temelor o arată. */
        private bool $accesibilitate = true,
        /** Sigla site-ului: fn(): ?array, Sigla::pentruTema, citită la fiecare randare. */
        private ?\Closure $sigla = null,
    ) {
    }

    public function limba(): string
    {
        return $this->t->limba();
    }

    /** @param array<string,string> $alternative limba => adresa paginii curente în acea limbă */
    public function site(array $alternative, bool $proba, array $limbi): array
    {
        $p = $this->oglinda->corp('proprietate') ?? [];
        $rez = [];
        foreach ($limbi as $l) {
            $rez[] = ['cod' => $l, 'url' => $alternative[$l] ?? $this->url->cale('/', $l), 'activa' => $l === $this->limba(), 'nume' => $this->t->t('limba.' . $l)];
        }
        return [
            'nume' => $this->nume(),
            'limba' => $this->limba(),
            'limbi' => $rez,
            'url_baza' => $this->url->cale('/', $this->limba()),
            'mod_proba' => $proba,
            'an' => (int) substr($this->azi, 0, 4),
            // Luna curentă (1-12), pentru temele cu aspect pe anotimpuri (Montan).
            'luna' => (int) substr($this->azi, 5, 2),
            // Sigla încărcată de gazdă: adresa, mărimea la înălțimea de pe calculator și dacă numele rămâne lângă ea; null fără siglă.
            'sigla' => $this->sigla !== null ? ($this->sigla)() : null,
        ];
    }

    public function proprietate(): array
    {
        // Modelul temelor e public: telefonul gazdei apare doar la alegerea ei.
        $p = $this->oglinda->proprietatePublica() ?? [];
        $l = $this->limba();
        $descriere = $p['descriere'][$l] ?? null;
        $limbaDescriere = $l;
        if (!is_string($descriere) || trim($descriere) === '') {
            $descriere = $p['descriere']['ro'] ?? '';
            $limbaDescriere = 'ro';
        }
        // Pozele: cele din EasyCheck și cele încărcate de gazdă, în ordinea și cu categoriile alese în panou (M6b).
        // La cele încărcate, legenda e eticheta categoriei, cu indicele („Dormitorul 2”); la cele din EasyCheck, legenda
        // vine din EasyCheck (7.550.85: textul gazdei, altfel spațiul, altfel categoria; ModelVedere::poza).
        $poze = [];
        $lista = $this->continutPoze()->proprietate((array) ($p['poze'] ?? []));
        foreach (array_slice($lista, 0, self::POZE_MAX) as $i => $x) {
            $ec = is_array($x['ec'] ?? null) ? $x['ec'] : null;
            $legenda = null;
            if ($ec === null && is_string($x['categorie'] ?? null) && $x['categorie'] !== 'altele') {
                // cu indicele, forma articulată („Dormitorul 2”, „Baia 2”, „Camera 2”).
                $legenda = $x['indice'] !== null && $x['indice'] > 1 && in_array($x['categorie'], \Cazo\Continut\Poze::CU_INDICE, true)
                    ? $this->t->t('poza.categorie_n.' . $x['categorie'], ['n' => (string) $x['indice']]) : $this->t->t('poza.categorie.' . $x['categorie']);
            }
            $poza = $this->poza(['sha256' => $x['sha256'], 'latime' => $x['latime'], 'inaltime' => $x['inaltime']] + ($ec ?? []), (string) ($p['nume'] ?? ''), $i + 1, null, $x['categorie'] ?? null);
            if ($legenda !== null) {
                $poza['legenda'] = $legenda;
                $poza['categorie'] = $x['categorie'];
            }
            if ($poza['legenda'] !== '') {
                $poza['alt'] = $this->t->t('poza.alt_categorie', ['categorie' => $poza['legenda'], 'nume' => (string) ($p['nume'] ?? ''), 'n' => (string) ($i + 1)]);
            }
            $poze[] = $poza;
        }
        $coord = $p['coordonate'] ?? null;
        return [
            'nume' => $this->nume(),
            'tip' => (string) ($p['tip'] ?? ''),
            'descriere' => (string) $descriere,
            'descriere_limba' => $limbaDescriere,
            // Descrierea întreagă, pe paragrafe, cu titlul de pe primul rând despărțit de text.
            'descriere_titlu' => self::descriereParti((string) $descriere)['titlu'],
            'descriere_paragrafe' => self::descriereParti((string) $descriere)['paragrafe'],
            'adresa' => [
                // Adresa clădirii, fără scară, etaj și apartament: modelul temelor e public.
                'strada' => \Cazo\Legal\Adresa::publica((string) ($p['adresa']['strada'] ?? '')),
                'localitate' => (string) ($p['adresa']['localitate'] ?? ''),
                'judet' => (string) ($p['adresa']['judet'] ?? ''),
            ],
            'coordonate' => is_array($coord) && is_numeric($coord['lat'] ?? null) && is_numeric($coord['lng'] ?? null) ? ['lat' => (float) $coord['lat'], 'lng' => (float) $coord['lng']] : null,
            'ore' => [
                'checkin_de_la' => (string) ($p['ore']['checkin_de_la'] ?? ''),
                // EasyCheck 7.550.85: ora până la care se poate face check-in (gol: oricând după ora de început).
                'checkin_pana_la' => is_string($p['ore']['checkin_pana_la'] ?? null) && preg_match('~^\d{2}:\d{2}$~', $p['ore']['checkin_pana_la']) ? $p['ore']['checkin_pana_la'] : '',
                'plecare_pana_la' => (string) ($p['ore']['plecare_pana_la'] ?? ''),
            ],
            'reguli_casa' => array_map(static fn ($v) => is_scalar($v) || $v === null ? $v : null, (array) ($p['reguli_casa'] ?? [])),
            'anulare_zile' => is_int($p['anulare_zile'] ?? null) ? $p['anulare_zile'] : null,
            'contact' => [
                'email' => (string) ($p['contact']['email'] ?? ''),
                'telefon' => (string) ($p['contact']['telefon'] ?? ''),
            ],
            'linkuri_platforme' => [],
            // Spațiile comune ale proprietății (grădina, terasa comună, parcarea...), cu eticheta în limba paginii.
            'spatii_comune' => array_values(array_filter(array_map(fn ($x): ?array => is_array($x) && is_string($x['tip'] ?? null)
                ? ['tip' => $x['tip'], 'eticheta' => $this->eticheta($x['eticheta'] ?? null)] : null, array_slice((array) ($p['spatii_comune'] ?? []), 0, 20)))),
            'poze' => $poze,
        ];
    }

    /**
     * Descrierea proprietății, desfăcută: un paragraf la fiecare rând nou; primul rând e titlu când e scurt
     * (cel mult 80 de caractere), fără punct la sfârșit și urmat de text („Apartament boutique în inima Galațiului”,
     * apoi descrierea). Tăierea rămâne doar în meta (Functii::scurtat).
     * @return array{titlu:string, paragrafe:list<string>}
     */
    public static function descriereParti(string $descriere): array
    {
        $linii = array_values(array_filter(array_map(static fn (string $x): string => trim((string) preg_replace('~[ \t]+~u', ' ', $x)),
            preg_split('~\R+~u', $descriere) ?: []), static fn (string $x): bool => $x !== ''));
        $titlu = '';
        if (count($linii) > 1 && mb_strlen($linii[0]) <= 80 && !preg_match('~[.!?:;,]$~u', $linii[0])) {
            $titlu = array_shift($linii);
        }
        return ['titlu' => $titlu, 'paragrafe' => array_slice($linii, 0, 40)];
    }

    /**
     * Notele de pe platforme: `recenzii[{platforma, scor, scala, numar, actualizat_la}]`, din
     * `note_platforme`, doar Booking.com (din 10) și Airbnb (din 5); fără note, listă goală.
     */
    public function recenzii(): array
    {
        $rez = [];
        foreach (array_slice((array) ($this->oglinda->corp('proprietate')['note_platforme'] ?? []), 0, 4) as $n) {
            $scala = ['booking' => 10, 'airbnb' => 5][$n['platforma'] ?? ''] ?? null;
            if (!is_array($n) || $scala === null || !is_numeric($n['nota'] ?? null) || (int) ($n['scala'] ?? 0) !== $scala || (float) $n['nota'] < 0 || (float) $n['nota'] > $scala) {
                continue;
            }
            $rez[] = ['platforma' => (string) $n['platforma'], 'scor' => round((float) $n['nota'], 1), 'scala' => $scala,
                'numar' => is_int($n['numar_recenzii'] ?? null) && $n['numar_recenzii'] >= 0 ? $n['numar_recenzii'] : null,
                'actualizat_la' => is_string($n['citita_la'] ?? null) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $n['citita_la']) ? $n['citita_la'] : ''];
        }
        return $rez;
    }

    /** Eticheta {ro, en} a EasyCheck, în limba paginii (altfel în română). */
    private function eticheta(mixed $e): string
    {
        $l = $this->limba();
        return is_array($e) ? (string) (is_string($e[$l] ?? null) && $e[$l] !== '' ? $e[$l] : ($e['ro'] ?? '')) : '';
    }

    /** Numele proprietății; înaintea primei sincronizări, domeniul site-ului (pagina are mereu un titlu). */
    public function nume(): string
    {
        $n = trim((string) ($this->oglinda->corp('proprietate')['nume'] ?? ''));
        return $n !== '' ? $n : (string) parse_url($this->url->absolut('/'), PHP_URL_HOST);
    }

    /** @return list<array> camerele vizibile, în ordinea gazdei (camere_local.ordine), apoi a EasyCheck */
    public function camere(): array
    {
        if ($this->camere !== null) {
            return $this->camere;
        }
        $local = [];
        foreach ($this->baza->toate('SELECT * FROM {camere_local}') as $r) {
            $local[$r['camera']] = $r;
        }
        $alocate = [];
        foreach ($this->baza->toate('SELECT sha256, camera, rol, alt, ordine FROM {poze_local} WHERE camera IS NOT NULL ORDER BY ordine, id') as $r) {
            $alocate[$r['camera']][] = $r;
        }
        $lista = [];
        $folosite = [];
        foreach (array_slice((array) ($this->oglinda->corp('camere')['camere'] ?? []), 0, self::CAMERE_MAX) as $i => $c) {
            if (!is_array($c) || !is_string($c['id'] ?? null)) {
                continue;
            }
            $loc = $local[$c['id']] ?? [];
            if ((int) ($loc['ascunsa'] ?? 0) === 1) {
                continue;
            }
            $lista[] = $this->camera($c, $loc, $alocate[$c['id']] ?? [], (int) ($loc['ordine'] ?? 0) * 1000 + $i, $folosite);
        }
        usort($lista, static fn (array $a, array $b): int => $a['_ordine'] <=> $b['_ordine']);
        foreach ($lista as &$c) {
            unset($c['_ordine']);
        }
        unset($c);
        return $this->camere = $lista;
    }

    public function cameraDupaSlug(string $slug): ?array
    {
        foreach ($this->camere() as $c) {
            if ($c['slug'] === $slug) {
                return $c;
            }
        }
        return null;
    }

    public function cameraDupaId(string $id): ?array
    {
        foreach ($this->camere() as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        return null;
    }

    /** Slugul camerei în fiecare limbă (alternativele hreflang ale paginii camerei). */
    public function sluguri(string $id): array
    {
        $loc = $this->baza->rand('SELECT slug, titlu FROM {camere_local} WHERE camera = ?', [$id]) ?? [];
        $c = null;
        foreach ((array) ($this->oglinda->corp('camere')['camere'] ?? []) as $x) {
            if (is_array($x) && ($x['id'] ?? null) === $id) {
                $c = $x;
            }
        }
        $rez = [];
        foreach (['ro', 'en'] as $l) {
            $rez[$l] = $this->slug($c ?? ['id' => $id, 'titlu' => $id], $loc, $l);
        }
        return $rez;
    }

    /** Meniul: Acasă, Camere și paginile publicate ale gazdei. */
    public function meniu(string $activ): array
    {
        $l = $this->limba();
        $principal = [
            ['titlu' => $this->t->t('meniu.acasa'), 'url' => $this->url->cale('/', $l), 'activ' => $activ === 'acasa'],
            // la o singură unitate, „Apartamentul” (sau „Camere”) duce direct la pagina ei, nu la o listă cu un card.
            ['titlu' => $this->t->t('meniu.camere'), 'url' => count($this->camere()) === 1 ? (string) $this->camere()[0]['url'] : $this->url->cale(Cai::cale('camere', $l), $l),
                'activ' => $activ === 'camere' || $activ === 'camera'],
        ];
        foreach ($this->baza->toate('SELECT slug, titlu FROM {pagini} WHERE publicata = 1 ORDER BY ordine, id LIMIT 8') as $p) {
            $slug = json_decode((string) $p['slug'], true)[$l] ?? null;
            $titlu = json_decode((string) $p['titlu'], true)[$l] ?? null;
            if (is_string($slug) && is_string($titlu) && preg_match('~^' . Cai::SLUG . '$~', $slug)) {
                $principal[] = ['titlu' => $titlu, 'url' => $this->url->cale(Cai::cale('pagina', $l, ['slug' => $slug]), $l), 'activ' => $activ === 'pagina:' . $slug];
            }
        }
        $subsol = [];
        foreach (Cai::LEGALE as $n) {
            if ($n === 'accesibilitate' && !$this->accesibilitate) {
                continue;
            }
            $subsol[] = ['titlu' => $this->t->t('legal.link.' . $n), 'url' => Cai::legala($this->url, $n, $l), 'activ' => $activ === $n];
        }
        return ['principal' => $principal, 'subsol' => $subsol];
    }

    /**
     * Harta termică a prețurilor (`harta_termica_preturi`): medianele lunilor, o lună pe zile (luna zilei
     * date, când e între cele 12, altfel prima cu prețuri) și planurile camerei de referință; null fără prețuri
     * afișabile. Randarea dă null: harta stă doar pe paginile fără cerere (Acasă, Camere).
     */
    public function preturiSezon(?string $ziLuna): ?array
    {
        return $this->cuPreturi ? (new PreturiSezon($this->baza, $this->pret, $this->t))->date($this->camere(), $this->azi, $ziLuna) : null;
    }

    /**
     * Răsăritul și apusul la coordonatele proprietății (calculate local): azi, ziua sosirii din cerere
     * (altfel azi) și dimineața de după ea; null fără coordonate.
     */
    public function soare(?string $sosire): ?array
    {
        return Soare::pentruTema($this->proprietate()['coordonate'], $this->azi, $sosire);
    }

    /** Blocurile de conținut administrate în site (cum_ajungeti, ritualuri ...), în limba paginii. */
    public function continut(): array
    {
        $rez = [];
        foreach ($this->baza->toate('SELECT cheie, date FROM {continut} WHERE limba = ?', [$this->limba()]) as $r) {
            $d = json_decode((string) $r['date'], true);
            if (is_array($d)) {
                $rez[$r['cheie']] = self::doarScalari($d);
            }
        }
        return $rez;
    }

    private function continutPoze(): \Cazo\Continut\Poze
    {
        return new \Cazo\Continut\Poze($this->baza, new \Cazo\Nucleu\Ceas(), '');
    }

    /** @return list<string> pozele ascunse de gazdă (o citire pe pagină) */
    private function pozeAscunse(): array
    {
        return $this->pozeAscunse ??= $this->continutPoze()->ascunse();
    }

    private function camera(array $c, array $loc, array $alocate, int $ordine, array &$folosite): array
    {
        $l = $this->limba();
        $titlu = self::json($loc['titlu'] ?? null)[$l] ?? null;
        $titluLimba = $l;
        if (!is_string($titlu) || $titlu === '') {
            $titlu = (string) ($c['titlu'] ?? '');
            $titluLimba = 'ro';
        }
        $descriere = self::json($loc['descriere'] ?? null)[$l] ?? null;
        $slug = $this->slug($c, $loc, $l);
        while (isset($folosite[$slug])) {
            $slug .= '-2';
        }
        $folosite[$slug] = true;
        // Pozele: cele alocate de gazdă camerei, altfel ale camerei din EasyCheck (în v1, pozele proprietății), fără cele
        // ascunse de gazdă în panou (aceeași regulă ca la galeria proprietății, Continut\Poze::ascunse).
        $poze = [];
        $ascunse = array_flip($this->pozeAscunse());
        $vizibila = static fn (mixed $x): bool => is_array($x) && !isset($ascunse[(string) ($x['sha256'] ?? '')]);
        $alocate = array_values(array_filter($alocate, $vizibila));
        $ec = array_values(array_filter((array) ($c['poze'] ?? []), $vizibila));
        $aleCamerei = array_values(array_filter($ec, static fn (array $x): bool => ($x['camera_id'] ?? null) === $c['id']));
        $sursa = $alocate !== [] ? $alocate : ($aleCamerei !== [] ? $aleCamerei : $ec);
        foreach (array_slice($sursa, 0, 60) as $i => $p) {
            $x = is_array($p) ? $p : [];
            $sha = (string) ($x['sha256'] ?? '');
            if (preg_match('~^[0-9a-f]{64}$~', $sha)) {
                $alt = is_string($x['alt'] ?? null) ? (self::json($x['alt'])[$l] ?? null) : null;
                $poze[] = $this->poza($x, $titlu, $i + 1, is_string($alt) ? $alt : null, $x['rol'] ?? null);
            }
        }
        $spatii = is_array($c['spatii'] ?? null) ? $c['spatii'] : null;
        $deLa = $this->cuPreturi ? $this->pret->deLa((string) $c['id'], $this->azi) : null;
        $fisa = self::json($loc['fisa'] ?? null);
        return [
            'id' => (string) $c['id'],
            'slug' => $slug,
            'url' => $this->url->cale(Cai::cale('camera', $l, ['slug' => $slug]), $l),
            'titlu' => $titlu,
            'titlu_limba' => $titluLimba,
            'descriere' => is_string($descriere) ? $descriere : '',
            'unitati' => (int) ($c['unitati'] ?? 1),
            'oaspeti_max' => is_int($c['oaspeti_max'] ?? null) ? $c['oaspeti_max'] : null,
            'fisa' => [
                'pat' => is_string($fisa['pat'][$l] ?? null) ? $fisa['pat'][$l] : (is_string($fisa['pat'] ?? null) ? $fisa['pat'] : ''),
                'baie' => is_string($fisa['baie'][$l] ?? null) ? $fisa['baie'][$l] : (is_string($fisa['baie'] ?? null) ? $fisa['baie'] : ''),
                'suprafata_mp' => is_int($fisa['suprafata_mp'] ?? null) ? $fisa['suprafata_mp'] : null,
                'vedere' => is_string($fisa['vedere'][$l] ?? null) ? $fisa['vedere'][$l] : (is_string($fisa['vedere'] ?? null) ? $fisa['vedere'] : ''),
            ],
            'dotari' => array_values(array_filter(array_map(static fn ($d) => is_array($d) ? ($d[$l] ?? null) : $d, self::json($loc['dotari'] ?? null)), 'is_string')),
            // EasyCheck 7.550.85: spațiile unității, paturile lor, baia proprie sau comună, suprafața.
            'spatii' => $this->spatii($spatii, $poze),
            'paturi' => array_merge(...array_map(static fn (array $s): array => $s['paturi'], $this->spatii($spatii, []))),
            'baie_tip' => self::baieTip($spatii),
            'suprafata_mp' => is_int($fisa['suprafata_mp'] ?? null) ? $fisa['suprafata_mp'] : (is_int($spatii['suprafata_m2'] ?? null) && $spatii['suprafata_m2'] > 0 ? $spatii['suprafata_m2'] : null),
            'poze' => $poze,
            'poza_principala' => $poze[0] ?? null,
            'pret_de_la' => $deLa === null ? null : ['suma' => $deLa, 'text' => $this->t->t('camera.pret_de_la', ['suma' => Format::baniOaspete($deLa, $l)])],
            '_ordine' => $ordine,
        ];
    }

    private function slug(array $c, array $loc, string $limba): string
    {
        $s = self::json($loc['slug'] ?? null)[$limba] ?? null;
        if (is_string($s) && preg_match('~^' . Cai::SLUG . '$~', $s)) {
            return $s;
        }
        $titlu = self::json($loc['titlu'] ?? null)[$limba] ?? null;
        return Cai::slug(is_string($titlu) && $titlu !== '' ? $titlu : (string) ($c['titlu'] ?? 'camera'));
    }

    /**
     * O poză în forma contractului; dimensiunile vin din derivate, când există. Din EasyCheck 7.550.85: `categorie`
     * (cheia), `legenda` (textul gazdei, altfel eticheta spațiului, altfel a categoriei) și `index` (al spațiului); rolul
     * local rămâne, iar fără el rolul e categoria (temele citesc `rol`).
     */
    private function poza(array $p, string $nume, int $n, ?string $alt = null, ?string $rol = null): array
    {
        $d = $this->poze->pentruTema($p['sha256']);
        $categorie = is_array($p['categorie'] ?? null) && is_string($p['categorie']['cheie'] ?? null) && preg_match('~^[a-z_]{1,32}$~', $p['categorie']['cheie']) ? $p['categorie']['cheie'] : '';
        $legenda = is_string($p['legenda'] ?? null) && trim($p['legenda']) !== '' ? mb_substr(trim($p['legenda']), 0, 30)
            : ($this->eticheta($p['spatiu']['eticheta'] ?? null) ?: ($categorie !== '' && $categorie !== 'altele' ? $this->eticheta($p['categorie']) : ''));
        return [
            'sha' => $p['sha256'],
            'alt' => $alt ?? $this->t->t('poza.alt', ['nume' => $nume, 'n' => (string) $n]),
            'rol' => is_string($rol) && $rol !== '' ? $rol : $categorie,
            'categorie' => $categorie,
            'legenda' => $legenda,
            'index' => is_int($p['spatiu']['index'] ?? null) ? $p['spatiu']['index'] : null,
            'spatiu' => is_string($p['spatiu']['id'] ?? null) ? $p['spatiu']['id'] : '',
            'latime' => $d['latime'] ?? (is_int($p['latime'] ?? null) ? $p['latime'] : 1200),
            'inaltime' => $d['inaltime'] ?? (is_int($p['inaltime'] ?? null) ? $p['inaltime'] : 800),
        ];
    }

    /**
     * Spațiile unității (EasyCheck `spatii.lista`), în ordinea gazdei: `{tip, index, eticheta, paturi[{tip, numar,
     * eticheta}], baie{dotare, acces}|null, poze[]}` (pozele cu `spatiu.id` al lui); null de la EasyCheck: listă goală.
     */
    private function spatii(?array $spatii, array $poze): array
    {
        $rez = [];
        foreach (array_slice(is_array($spatii['lista'] ?? null) ? $spatii['lista'] : [], 0, 30) as $s) {
            if (!is_array($s) || !is_string($s['tip'] ?? null)) {
                continue;
            }
            $paturi = [];
            foreach (array_slice(is_array($s['paturi'] ?? null) ? $s['paturi'] : [], 0, 10) as $p) {
                if (is_array($p) && is_string($p['tip'] ?? null) && is_int($p['numar'] ?? null) && $p['numar'] > 0) {
                    $e = $this->eticheta($p);
                    $paturi[] = ['tip' => $p['tip'], 'numar' => $p['numar'], 'eticheta' => $p['numar'] > 1 ? $p['numar'] . ' × ' . $e : $e];
                }
            }
            $b = is_array($s['baie'] ?? null) ? $s['baie'] : null;
            $rez[] = ['tip' => $s['tip'], 'index' => is_int($s['index'] ?? null) ? $s['index'] : 1, 'eticheta' => $this->eticheta($s['eticheta'] ?? null), 'paturi' => $paturi,
                'baie' => $b === null ? null : ['dotare' => $this->eticheta($b['dotare'] ?? null), 'acces' => $this->eticheta($b['acces'] ?? null), 'comuna' => ($b['acces']['cheie'] ?? '') === 'comuna'],
                'poze' => array_values(array_filter($poze, static fn (array $x): bool => $x['spatiu'] !== '' && $x['spatiu'] === ($s['id'] ?? null)))];
        }
        // Un spațiu fără poze legate în EasyCheck ia pozele nelegate ale camerei cu aceeași categorie (dormitorul, baia...),
        // a câta e spațiul de felul lui (al doilea dormitor, a doua poză de dormitor), ca temele să nu arate casete goale.
        $cuFel = [];
        foreach ($rez as $i => $s) {
            if ($s['poze'] !== []) {
                continue;
            }
            $fel = $s['tip'] === 'camera' ? ['camera', 'dormitor'] : [$s['tip']];
            $libere = array_values(array_filter($poze, static fn (array $x): bool => $x['spatiu'] === '' && in_array($x['categorie'], $fel, true)));
            $k = $cuFel[$s['tip']] = ($cuFel[$s['tip']] ?? -1) + 1;
            if ($libere !== []) {
                $rez[$i]['poze'] = [$libere[$k % count($libere)]];
            }
        }
        return $rez;
    }

    /** `proprie` când o baie a unității e în cameră sau proprie, `comuna` când toate sunt comune, altfel gol. */
    private static function baieTip(?array $spatii): string
    {
        $acces = [];
        foreach (is_array($spatii['lista'] ?? null) ? $spatii['lista'] : [] as $s) {
            if (is_array($s) && ($s['tip'] ?? '') === 'baie' && is_string($s['baie']['acces']['cheie'] ?? null)) {
                $acces[] = $s['baie']['acces']['cheie'];
            }
        }
        return array_intersect($acces, ['in_camera', 'proprie_in_afara']) !== [] ? 'proprie' : ($acces !== [] ? 'comuna' : '');
    }

    private static function json(mixed $v): array
    {
        $d = is_string($v) ? json_decode($v, true) : null;
        return is_array($d) ? $d : [];
    }

    private static function doarScalari(array $d, int $adancime = 0): array
    {
        $r = [];
        foreach ($d as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $r[$k] = $v;
            } elseif (is_array($v) && $adancime < 4) {
                $r[$k] = self::doarScalari($v, $adancime + 1);
            }
        }
        return $r;
    }
}
