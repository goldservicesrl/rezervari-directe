<?php

declare(strict_types=1);

namespace Cazo\Actualizari;

use Cazo\DateExterne\Http;
use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Setari;

defined('CAZO') or exit;

/**
 * Anunțul versiunii noi (completat): softul nu se actualizează singur și nu verifică semnături; gazda descarcă
 * versiunea oficială și o urcă după ghidul gazdei. Site-ul citește o dată pe zi fișierul public al programului,
 * `https://cazo.ro/versiune.json` (o cerere GET simplă, cu User-Agent-ul generic, fără date personale), ține răspunsul
 * în setări, îl arată în panou (Acasă și Setări › Întreținere) și trimite proprietarilor un e-mail, o singură dată
 * pentru fiecare versiune nouă. Fără setare: anunțul e mereu pornit.
 *
 * Forma fișierului (o scrie bin/lansare.php lângă arhivă, docs/public/dezvoltare.md):
 *   {"versiune": "0.1.1", "data": "2026-12-10", "note": "…" sau {"ro": "…", "en": "…"},
 *    "descarcare": "https://github.com/goldservicesrl/rezervari-directe/releases/tag/v0.1.1", "securitate": false}
 * `versiune` (MAJOR.MINOR.PATCH), `data` (AAAA-LL-ZZ) și `descarcare` (release-urile depozitului oficial sau
 * https://cazo.ro/descarcare/) sunt
 * obligatorii; `note` (cel mult 2000 de caractere) și `securitate` sunt opționale.
 *
 * Tolerant la eșec: un răspuns lipsă, un cod HTTP de eroare sau un corp neașteptat păstrează ultima versiune văzută și
 * notează doar momentul eșecului; nimic nu se descarcă și nimic nu se instalează.
 */
final class VersiuneNoua
{
    public const SURSA = 'https://cazo.ro/versiune.json';
    public const SETARE = 'actualizari.cazo';
    /** Ultima versiune pentru care a plecat e-mailul către proprietari. */
    public const SETARE_ANUNTATA = 'actualizari.anuntata';

    private const VERSIUNE = '~^\d{1,4}\.\d{1,3}\.\d{1,3}$~';
    private const NOTE_MAX = 2000;

    /**
     * @param \Closure(): Http $http
     * @param ?\Closure(array): void $anunta trimite e-mailul proprietarilor pentru versiunea dată (Aplicatie::alertaGazda)
     */
    public function __construct(private Setari $setari, private Ceas $ceas, private \Closure $http, private string $proiect, private ?string $sursa,
        private ?\Closure $anunta = null)
    {
    }

    /** Pagina versiunilor de pe GitHub. */
    public function paginaVersiuni(): string
    {
        return $this->proiect . '/releases';
    }

    /**
     * Sarcina zilnică a cronului: citește fișierul versiunii, ține minte răspunsul și, la o versiune mai nouă decât
     * $instalata încă neanunțată, trimite e-mailul. Întoarce un rând pentru jurnalul sarcinilor.
     */
    public function verifica(string $instalata): string
    {
        if ($this->sursa === null) {
            return 'fără sursă';
        }
        $vechi = $this->stare();
        $acum = $this->ceas->moment();
        try {
            $r = ($this->http)()->get($this->sursa, ['Accept' => 'application/json'], 10.0);
        } catch (\Throwable) {
            $this->setari->pune(self::SETARE, ['eroare_la' => $acum] + $vechi);
            return 'cazo.ro nu a răspuns';
        }
        $v = $r['status'] === 200 ? self::citeste($r['corp'], $this->proiect) : null;
        if ($v === null) {
            $this->setari->pune(self::SETARE, ['eroare_la' => $acum] + $vechi);
            return 'răspuns neașteptat (' . $r['status'] . ')';
        }
        $this->setari->pune(self::SETARE, $v + ['verificat_la' => $acum, 'eroare_la' => null]);
        $noua = $this->noua($instalata);
        if ($noua !== null && $this->setari->get(self::SETARE_ANUNTATA) !== $noua['versiune'] && $this->anunta !== null) {
            // Întâi semnul, apoi e-mailul: o eroare la punerea în coadă nu trimite de două ori.
            $this->setari->pune(self::SETARE_ANUNTATA, $noua['versiune']);
            ($this->anunta)($noua);
            return 'versiune nouă: ' . $noua['versiune'] . ' (anunțată)';
        }
        return 'ultima versiune: ' . $v['versiune'];
    }

    /**
     * Corpul fișierului versiunii, validat, sau null.
     * @return ?array{versiune:string, data:string, note:array{ro:string, en:string}, descarcare:string, securitate:bool}
     */
    public static function citeste(string $corp, string $proiect = \Cazo\Nucleu\Aplicatie::ADRESA_PROIECT): ?array
    {
        $j = strlen($corp) <= 65536 ? json_decode($corp, true) : null;
        if (!is_array($j) || !is_string($j['versiune'] ?? null) || !preg_match(self::VERSIUNE, $j['versiune'])
            || !is_string($j['data'] ?? null) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $j['data'])
            || !is_string($j['descarcare'] ?? null) || !self::adresaBuna($j['descarcare'], $proiect)) {
            return null;
        }
        $note = $j['note'] ?? '';
        $text = static fn (mixed $x): string => is_string($x) ? mb_substr(trim((string) preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', $x)), 0, self::NOTE_MAX) : '';
        $note = is_array($note) ? ['ro' => $text($note['ro'] ?? ''), 'en' => $text($note['en'] ?? '')] : ['ro' => $text($note), 'en' => $text($note)];
        return ['versiune' => $j['versiune'], 'data' => $j['data'], 'note' => $note, 'descarcare' => $j['descarcare'], 'securitate' => ($j['securitate'] ?? false) === true];
    }

    /**
     * Legătura de descărcare: https, fără spații sau ghilimele, doar spre release-urile depozitului oficial
     * ($proiect . '/releases/') sau spre pagina de descărcare de pe cazo.ro. Un fișier versiune.json modificat
     * (cazo.ro compromis) nu poate trimite gazdele spre alt cont GitHub sau alt domeniu (auditul de securitate.2026).
     */
    private static function adresaBuna(string $u, string $proiect): bool
    {
        if (strlen($u) > 500 || preg_match('~[\s"\'<>\\\\]|\.\.~', $u)) {
            return false;
        }
        return str_starts_with($u, rtrim($proiect, '/') . '/releases/')
            || preg_match('~^https://cazo\.ro/(descarcare|en/download)/~', $u) === 1;
    }

    /** @return array{versiune:?string, data:?string, note:array{ro:string, en:string}, descarcare:?string, securitate:bool, verificat_la:?string, eroare_la:?string} */
    public function stare(): array
    {
        $s = $this->setari->get(self::SETARE);
        $s = is_array($s) ? $s : [];
        $text = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;
        $versiune = $text($s['versiune'] ?? null);
        $note = is_array($s['note'] ?? null) ? $s['note'] : [];
        return [
            'versiune' => $versiune !== null && preg_match(self::VERSIUNE, $versiune) ? $versiune : null,
            'data' => $text($s['data'] ?? null),
            'note' => ['ro' => (string) ($note['ro'] ?? ''), 'en' => (string) ($note['en'] ?? '')],
            'descarcare' => $text($s['descarcare'] ?? null),
            'securitate' => ($s['securitate'] ?? false) === true,
            'verificat_la' => $text($s['verificat_la'] ?? null),
            'eroare_la' => $text($s['eroare_la'] ?? null),
        ];
    }

    /**
     * Versiunea mai nouă decât cea instalată, sau null.
     * @return ?array{versiune:string, data:string, note:array{ro:string, en:string}, url:string, securitate:bool}
     */
    public function noua(string $instalata): ?array
    {
        $s = $this->stare();
        if ($s['versiune'] === null || !preg_match('~^\d+\.\d+\.\d+~', $instalata) || version_compare($s['versiune'], $instalata, '<=')) {
            return null;
        }
        return ['versiune' => $s['versiune'], 'data' => (string) $s['data'], 'note' => $s['note'], 'url' => $s['descarcare'] ?? $this->paginaVersiuni(),
            'securitate' => $s['securitate']];
    }
}
