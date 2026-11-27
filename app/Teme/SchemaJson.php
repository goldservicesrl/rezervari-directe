<?php

declare(strict_types=1);

namespace Cazo\Teme;

defined('CAZO') or exit;

/**
 * Validatorul JSON Schema al temelor, pentru schemele publicate în scheme/: doar cuvintele
 * folosite acolo (type, properties, required, additionalProperties, patternProperties, items, contains, enum, const,
 * pattern, minimum, maximum, minLength, maxLength, minItems, maxItems, uniqueItems, oneOf, anyOf, allOf, $ref local).
 * Erorile poartă calea JSON a valorii ($.fonturi[0].fisier), ca autorul temei să găsească locul.
 */
final class SchemaJson
{
    private const ADANCIME = 64;

    public function __construct(private array $document)
    {
    }

    public static function din(string $fisier): self
    {
        $d = json_decode((string) file_get_contents($fisier), true, 64, JSON_THROW_ON_ERROR);
        return new self($d);
    }

    /** @return list<string> erorile (gol = valid) */
    public function valideaza(mixed $valoare, ?string $ref = null): array
    {
        $erori = [];
        $this->verifica($valoare, $ref === null ? $this->document : ['$ref' => $ref], '$', $erori, 0);
        return $erori;
    }

    private function rezolva(string $ref): array
    {
        if (!str_starts_with($ref, '#/')) {
            throw new \InvalidArgumentException('Doar $ref locale: ' . $ref);
        }
        $n = $this->document;
        foreach (explode('/', substr($ref, 2)) as $p) {
            $p = str_replace(['~1', '~0'], ['/', '~'], $p);
            if (!is_array($n) || !array_key_exists($p, $n)) {
                throw new \InvalidArgumentException('$ref negăsit: ' . $ref);
            }
            $n = $n[$p];
        }
        return is_array($n) ? $n : [];
    }

    private static function tip(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            is_bool($v) => 'boolean',
            is_int($v) => 'integer',
            is_float($v) => 'number',
            is_string($v) => 'string',
            is_array($v) && array_is_list($v) => 'array',
            is_array($v) => 'object',
            default => 'necunoscut',
        };
    }

    private static function potrivesteTip(mixed $v, string $t): bool
    {
        $real = self::tip($v);
        return $real === $t || ($t === 'number' && $real === 'integer') || ($t === 'object' && $v === []) || ($t === 'array' && $v === []);
    }

    /** @param list<string> $erori */
    private function verifica(mixed $v, array $s, string $cale, array &$erori, int $nivel): void
    {
        if ($nivel > self::ADANCIME) {
            $erori[] = "$cale: prea multe niveluri";
            return;
        }
        if (isset($s['$ref'])) {
            $this->verifica($v, $this->rezolva((string) $s['$ref']), $cale, $erori, $nivel + 1);
            unset($s['$ref']);
        }
        if (isset($s['type'])) {
            $tipuri = (array) $s['type'];
            $ok = false;
            foreach ($tipuri as $t) {
                $ok = $ok || self::potrivesteTip($v, (string) $t);
            }
            if (!$ok) {
                $erori[] = "$cale: se așteaptă " . implode(' sau ', $tipuri) . ', nu ' . self::tip($v);
                return;
            }
        }
        if (array_key_exists('const', $s) && $v !== $s['const']) {
            $erori[] = "$cale: valoarea trebuie să fie " . json_encode($s['const'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($s['enum']) && !in_array($v, (array) $s['enum'], true)) {
            $erori[] = "$cale: valoare nepermisă (" . mb_substr((string) json_encode($v, JSON_UNESCAPED_UNICODE), 0, 60) . '); permise: ' . implode(', ', array_map(static fn ($x) => (string) json_encode($x, JSON_UNESCAPED_UNICODE), (array) $s['enum']));
        }
        if (is_string($v)) {
            $n = mb_strlen($v);
            if (isset($s['minLength']) && $n < $s['minLength']) {
                $erori[] = "$cale: cel puțin {$s['minLength']} caractere";
            }
            if (isset($s['maxLength']) && $n > $s['maxLength']) {
                $erori[] = "$cale: cel mult {$s['maxLength']} caractere";
            }
            if (isset($s['pattern']) && !preg_match('~' . str_replace('~', '\~', (string) $s['pattern']) . '~u', $v)) {
                $erori[] = "$cale: forma nu se potrivește (" . mb_substr($v, 0, 60) . ')';
            }
        }
        if (is_int($v) || is_float($v)) {
            if (isset($s['minimum']) && $v < $s['minimum']) {
                $erori[] = "$cale: cel puțin {$s['minimum']}";
            }
            if (isset($s['maximum']) && $v > $s['maximum']) {
                $erori[] = "$cale: cel mult {$s['maximum']}";
            }
        }
        if (is_array($v) && (array_is_list($v) && ($v !== [] || !isset($s['properties'])))) {
            if (isset($s['minItems']) && count($v) < $s['minItems']) {
                $erori[] = "$cale: cel puțin {$s['minItems']} elemente";
            }
            if (isset($s['maxItems']) && count($v) > $s['maxItems']) {
                $erori[] = "$cale: cel mult {$s['maxItems']} elemente";
                return;
            }
            if (!empty($s['uniqueItems']) && count(array_unique(array_map('serialize', $v))) !== count($v)) {
                $erori[] = "$cale: elemente repetate";
            }
            if (isset($s['items']) && is_array($s['items'])) {
                foreach ($v as $i => $x) {
                    $this->verifica($x, $s['items'], $cale . '[' . $i . ']', $erori, $nivel + 1);
                }
            }
            if (isset($s['contains'])) {
                $gasit = false;
                foreach ($v as $x) {
                    $e = [];
                    $this->verifica($x, $s['contains'], $cale, $e, $nivel + 1);
                    $gasit = $gasit || $e === [];
                }
                if (!$gasit) {
                    $erori[] = "$cale: lipsește un element cerut" . (isset($s['contains']['const']) ? ' (' . json_encode($s['contains']['const'], JSON_UNESCAPED_UNICODE) . ')' : '');
                }
            }
        }
        if (is_array($v) && (!array_is_list($v) || $v === [])) {
            foreach ((array) ($s['required'] ?? []) as $k) {
                if (!array_key_exists($k, $v)) {
                    $erori[] = "$cale: lipsește cheia „{$k}”";
                }
            }
            $proprietati = (array) ($s['properties'] ?? []);
            foreach ($v as $k => $x) {
                $k = (string) $k;
                $acoperit = false;
                if (isset($proprietati[$k])) {
                    $this->verifica($x, $proprietati[$k], $cale . '.' . $k, $erori, $nivel + 1);
                    $acoperit = true;
                }
                foreach ((array) ($s['patternProperties'] ?? []) as $model => $sub) {
                    if (preg_match('~' . str_replace('~', '\~', (string) $model) . '~u', $k)) {
                        $this->verifica($x, $sub, $cale . '.' . $k, $erori, $nivel + 1);
                        $acoperit = true;
                    }
                }
                if (!$acoperit && array_key_exists('additionalProperties', $s)) {
                    if ($s['additionalProperties'] === false) {
                        $erori[] = "$cale: cheie necunoscută „{$k}”";
                    } elseif (is_array($s['additionalProperties'])) {
                        $this->verifica($x, $s['additionalProperties'], $cale . '.' . $k, $erori, $nivel + 1);
                    }
                }
            }
        }
        foreach ((array) ($s['allOf'] ?? []) as $sub) {
            $this->verifica($v, $sub, $cale, $erori, $nivel + 1);
        }
        foreach (['anyOf', 'oneOf'] as $cuv) {
            if (!isset($s[$cuv])) {
                continue;
            }
            $bune = 0;
            $primele = null;
            foreach ((array) $s[$cuv] as $sub) {
                $e = [];
                $this->verifica($v, $sub, $cale, $e, $nivel + 1);
                if ($e === []) {
                    $bune++;
                } else {
                    $primele ??= $e;
                }
            }
            if ($bune === 0) {
                $erori[] = "$cale: nicio formă permisă nu se potrivește" . ($primele !== null ? ' (' . $primele[0] . ')' : '');
            } elseif ($cuv === 'oneOf' && $bune > 1) {
                $erori[] = "$cale: se potrivesc mai multe forme";
            }
        }
    }
}
