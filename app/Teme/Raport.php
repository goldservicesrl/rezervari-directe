<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/**
 * Raportul validatorului: erorile blocante și avertismentele, fiecare cu pasul, fișierul și linia,
 * plus măsurătorile (bugetele, timpii randării de probă). Se păstrează în `teme.raport` și se arată în panou și în
 * `cazo-tema verifica`.
 */
final class Raport
{
    /** @var list<array{pas:string, mesaj:string, fisier:?string, linie:?int}> */
    public array $erori = [];
    /** @var list<array{pas:string, mesaj:string, fisier:?string, linie:?int}> */
    public array $avertismente = [];
    /** @var array<string,mixed> */
    public array $masuratori = [];

    public function eroare(string $pas, string $mesaj, ?string $fisier = null, ?int $linie = null): void
    {
        if (count($this->erori) < 500) {
            $this->erori[] = ['pas' => $pas, 'mesaj' => $mesaj, 'fisier' => $fisier, 'linie' => $linie];
        }
    }

    public function avertisment(string $pas, string $mesaj, ?string $fisier = null, ?int $linie = null): void
    {
        if (count($this->avertismente) < 500) {
            $this->avertismente[] = ['pas' => $pas, 'mesaj' => $mesaj, 'fisier' => $fisier, 'linie' => $linie];
        }
    }

    public function trece(): bool
    {
        return $this->erori === [];
    }

    public function areEroareLa(string $pas): bool
    {
        foreach ($this->erori as $e) {
            if ($e['pas'] === $pas) {
                return true;
            }
        }
        return false;
    }

    public function tablou(): array
    {
        return ['erori' => $this->erori, 'avertismente' => $this->avertismente, 'masuratori' => $this->masuratori];
    }

    public static function dinTablou(array $d): self
    {
        $r = new self();
        foreach (['erori', 'avertismente'] as $k) {
            foreach ((array) ($d[$k] ?? []) as $x) {
                if (is_array($x) && is_string($x['mesaj'] ?? null)) {
                    $r->{$k}[] = ['pas' => (string) ($x['pas'] ?? ''), 'mesaj' => $x['mesaj'], 'fisier' => is_string($x['fisier'] ?? null) ? $x['fisier'] : null,
                        'linie' => is_int($x['linie'] ?? null) ? $x['linie'] : null];
                }
            }
        }
        $r->masuratori = is_array($d['masuratori'] ?? null) ? $d['masuratori'] : [];
        return $r;
    }

    /** Raportul ca text, pentru linia de comandă. */
    public function text(): string
    {
        $linie = static fn (array $x): string => '  [' . $x['pas'] . '] ' . ($x['fisier'] !== null ? $x['fisier'] . ($x['linie'] !== null ? ':' . $x['linie'] : '') . ': ' : '') . $x['mesaj'];
        $s = '';
        if ($this->erori !== []) {
            $s .= 'Erori (' . count($this->erori) . "):\n" . implode("\n", array_map($linie, $this->erori)) . "\n";
        }
        if ($this->avertismente !== []) {
            $s .= 'Avertismente (' . count($this->avertismente) . "):\n" . implode("\n", array_map($linie, $this->avertismente)) . "\n";
        }
        foreach ($this->masuratori as $k => $v) {
            $s .= '  ' . $k . ': ' . (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)) . "\n";
        }
        return $s . ($this->trece() ? "Tema trece validatorul.\n" : "Tema are erori de rezolvat.\n");
    }
}
