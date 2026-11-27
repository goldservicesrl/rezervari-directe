<?php

declare(strict_types=1);

namespace Cazo\I18n;

defined('CAZO') or exit;

/**
 * Cataloagele de texte: limbi/ro.php, limbi/en.php (site) și limbi/admin-ro.php, limbi/admin-en.php (panou).
 * Cheile au puncte („eroare.404.titlu”); valorile sunt texte cu {parametri} sau, la plural,
 * tablouri pe forme (Plural::forme). Un text lipsă în limba cerută cade pe limba de rezervă (română).
 */
final class Traducator
{
    /** @var array<string,array> */
    private static array $cataloage = [];

    /**
     * @param list<string> $variante sufixele variantelor de text, de la cea mai precisă: cu „_apartament”,
     *   o cheie care are și forma `<cheie>_apartament` se citește din ea („Apartamentul” în loc de „Camere” la o
     *   proprietate cu un singur apartament); cu „_unica”, forma pentru o singură unitate, de orice fel
     */
    public function __construct(
        private string $limba,
        private array $catalog,
        private array $rezerva = [],
        private array $variante = [],
    ) {
    }

    /** Același catalog, cu variantele de text date. */
    public function cuVariante(array $variante): self
    {
        return new self($this->limba, $this->catalog, $this->rezerva, array_values(array_filter($variante, 'is_string')));
    }

    /** @return list<string> */
    public function variante(): array
    {
        return $this->variante;
    }

    /** Cheia variantei care se aplică (prima care există în catalog), altfel cheia însăși. */
    private function cheie(string $cheie): string
    {
        foreach ($this->variante as $v) {
            if (isset($this->catalog[$cheie . $v]) || isset($this->rezerva[$cheie . $v])) {
                return $cheie . $v;
            }
        }
        return $cheie;
    }

    /**
     * @param string $domeniu '' pentru site, 'admin' pentru panou, 'instalare' pentru instalator (peste cel al panoului);
     *   panoul primește și textele actualizărilor (limbi/actualizari-*.php), sub ale lui
     */
    public static function pentru(string $directorLimbi, string $limba, string $domeniu = '', string $limbaRezerva = 'ro'): self
    {
        $catalog = self::incarca($directorLimbi, $limba, $domeniu);
        $rezerva = $limba === $limbaRezerva ? [] : self::incarca($directorLimbi, $limbaRezerva, $domeniu);
        if ($domeniu === 'admin') {
            $catalog += self::incarca($directorLimbi, $limba, 'actualizari');
            $rezerva += $limba === $limbaRezerva ? [] : self::incarca($directorLimbi, $limbaRezerva, 'actualizari');
        }
        return new self($limba, $catalog, $rezerva);
    }

    public static function incarca(string $directorLimbi, string $limba, string $domeniu = ''): array
    {
        if (!preg_match('~^[a-z]{2}$~', $limba) || !in_array($domeniu, ['', 'admin', 'instalare', 'actualizari'], true)) {
            throw new \InvalidArgumentException('Limbă sau domeniu invalid.');
        }
        $fisier = $directorLimbi . '/' . ($domeniu === '' ? '' : $domeniu . '-') . $limba . '.php';
        if (!isset(self::$cataloage[$fisier])) {
            $c = is_file($fisier) ? require $fisier : [];
            self::$cataloage[$fisier] = is_array($c) ? $c : [];
        }
        return self::$cataloage[$fisier];
    }

    public function limba(): string
    {
        return $this->limba;
    }

    public function are(string $cheie): bool
    {
        return isset($this->catalog[$cheie]) || isset($this->rezerva[$cheie]);
    }

    /** Textul cheii, cu parametrii înlocuiți. O cheie lipsă întoarce chiar cheia (vizibilă la testare). */
    public function t(string $cheie, array $parametri = []): string
    {
        $cheie = $this->variante !== [] ? $this->cheie($cheie) : $cheie;
        $v = $this->catalog[$cheie] ?? $this->rezerva[$cheie] ?? null;
        if ($v === null) {
            return $cheie;
        }
        if (is_array($v)) {
            throw new \LogicException("Cheia $cheie are forme de plural; se folosește plural().");
        }
        return self::inlocuieste($v, $parametri);
    }

    /**
     * Un text care trimite spre contactul gazdei ({contact}). Fără contact publicat, varianta `<cheie>_fara_contact`,
     * scrisă fără {contact}, ca oaspetele să citească o frază întreagă.
     */
    public function cuContact(string $cheie, string $contact, array $parametri = []): string
    {
        if (trim($contact) === '' && $this->are($cheie . '_fara_contact')) {
            return $this->t($cheie . '_fara_contact', $parametri);
        }
        return $this->t($cheie, $parametri + ['contact' => $contact]);
    }

    /** Ca cuContact(), pentru un text cu forme de plural. */
    public function pluralCuContact(string $cheie, int $n, string $contact, array $parametri = []): string
    {
        if (trim($contact) === '' && $this->are($cheie . '_fara_contact')) {
            return $this->plural($cheie . '_fara_contact', $n, $parametri);
        }
        return $this->plural($cheie, $n, $parametri + ['contact' => $contact]);
    }

    /** Textul la plural: {n} se înlocuiește cu numărul formatat. */
    public function plural(string $cheie, int $n, array $parametri = []): string
    {
        $v = $this->catalog[$cheie] ?? null;
        $limba = $this->limba;
        if (!is_array($v)) {
            $v = $this->rezerva[$cheie] ?? null;
            $limba = 'ro';
        }
        if (!is_array($v)) {
            return $cheie;
        }
        $forma = Plural::forma($limba, $n);
        $text = $v[$forma] ?? $v['multe'] ?? reset($v);
        return self::inlocuieste((string) $text, $parametri + ['n' => Format::numar($n, $limba)]);
    }

    private static function inlocuieste(string $text, array $parametri): string
    {
        if ($parametri === []) {
            return $text;
        }
        $inloc = [];
        foreach ($parametri as $k => $v) {
            $inloc['{' . $k . '}'] = (string) $v;
        }
        return strtr($text, $inloc);
    }
}
