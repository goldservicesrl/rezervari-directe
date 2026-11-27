<?php

declare(strict_types=1);

namespace Cazo\Instalare;

defined('CAZO') or exit;

/**
 * Starea instalării în curs: cazo-privat/instalare/stare.php. Fișierul începe cu „<?php exit; ?>”, deci o cerere
 * directă la el (varianta B, înaintea sondei) nu întoarce nimic; restul e JSON, citit ca text (fără include, ca
 * OPcache să nu servească o stare veche). Fără secrete: doar amprentele codului și ale sesiunii.
 */
final class Stare
{
    public const ANTET = "<?php exit; ?>\n";

    private array $date;

    public function __construct(private string $fisier)
    {
        $t = @file_get_contents($fisier);
        $d = is_string($t) && str_starts_with($t, self::ANTET) ? json_decode(substr($t, strlen(self::ANTET)), true) : null;
        $this->date = is_array($d) ? $d : [];
    }

    public static function pentru(string $privat): self
    {
        return new self($privat . '/' . Loc::STARE);
    }

    public function exista(): bool
    {
        return $this->date !== [];
    }

    public function get(string $cheie, mixed $implicit = null): mixed
    {
        return $this->date[$cheie] ?? $implicit;
    }

    public function pune(string $cheie, mixed $valoare): self
    {
        $this->date[$cheie] = $valoare;
        return $this;
    }

    public function toate(): array
    {
        return $this->date;
    }

    public function salveaza(): void
    {
        $dir = dirname($this->fisier);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Dosarul instalării nu s-a putut crea.');
        }
        $tmp = $this->fisier . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, self::ANTET . json_encode($this->date, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new \RuntimeException('Starea instalării nu s-a putut scrie.');
        }
        \Cazo\Nucleu\Functii::chmod($tmp, 0600);
        rename($tmp, $this->fisier);
    }
}
