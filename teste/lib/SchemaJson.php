<?php

declare(strict_types=1);

// Validatorul JSON Schema minimal al testelor de contract (`contract`): exact cuvintele folosite
// de openapi-v1.json (type cu null, properties, required, items, enum, pattern, minimum, maximum, minItems, maxItems,
// minLength, maxLength, oneOf, allOf, $ref local, format uri/date/date-time). Câmpurile necunoscute trec, ca în API.

defined('CAZO') or exit;

final class SchemaJson
{
    private array $document;

    public function __construct(string $fisierOpenApi)
    {
        $d = json_decode((string) file_get_contents($fisierOpenApi), true, 512, JSON_THROW_ON_ERROR);
        $this->document = $d;
    }

    public function document(): array
    {
        return $this->document;
    }

    /** Schema unei componente (#/components/schemas/<nume>). */
    public function schema(string $nume): array
    {
        return ['$ref' => '#/components/schemas/' . $nume];
    }

    /** @return string[] erorile (gol = valid) */
    public function valideaza(mixed $valoare, array $schema, string $cale = '$'): array
    {
        $erori = [];
        $this->verifica($valoare, $schema, $cale, $erori);
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
        return $n;
    }

    private function verifica(mixed $v, array $s, string $cale, array &$erori): void
    {
        if (isset($s['$ref'])) {
            $this->verifica($v, $this->rezolva($s['$ref']), $cale, $erori);
            return;
        }
        foreach ($s['allOf'] ?? [] as $sub) {
            $this->verifica($v, $sub, $cale, $erori);
        }
        if (isset($s['oneOf'])) {
            $potrivite = 0;
            foreach ($s['oneOf'] as $sub) {
                $e = [];
                $this->verifica($v, $sub, $cale, $e);
                if ($e === []) {
                    $potrivite++;
                }
            }
            if ($potrivite !== 1) {
                $erori[] = "$cale: oneOf potrivit de $potrivite variante";
            }
        }
        if (isset($s['type'])) {
            $tipuri = (array) $s['type'];
            $ok = false;
            foreach ($tipuri as $t) {
                if ($this->areTip($v, $t)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $erori[] = "$cale: așteptat " . implode('|', $tipuri) . ', primit ' . get_debug_type($v);
                return;
            }
        }
        if (isset($s['enum']) && !in_array($v, $s['enum'], true)) {
            $erori[] = "$cale: valoarea " . json_encode($v, JSON_UNESCAPED_UNICODE) . ' lipsește din enum';
        }
        if (is_string($v)) {
            if (isset($s['pattern']) && !preg_match('~' . str_replace('~', '\~', $s['pattern']) . '~u', $v)) {
                $erori[] = "$cale: „{$v}” nu respectă {$s['pattern']}";
            }
            if (isset($s['minLength']) && mb_strlen($v) < $s['minLength']) {
                $erori[] = "$cale: prea scurt";
            }
            if (isset($s['maxLength']) && mb_strlen($v) > $s['maxLength']) {
                $erori[] = "$cale: prea lung";
            }
            $format = $s['format'] ?? null;
            if ($format === 'uri' && !preg_match('~^[a-z][a-z0-9+.\-]*://\S+$~i', $v)) {
                $erori[] = "$cale: nu e o adresă";
            } elseif ($format === 'date' && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $v)) {
                $erori[] = "$cale: nu e o zi";
            } elseif ($format === 'date-time' && !preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+\-]\d{2}:\d{2})$~', $v)) {
                $erori[] = "$cale: nu e un moment";
            }
        }
        if (is_int($v) || is_float($v)) {
            if (isset($s['minimum']) && $v < $s['minimum']) {
                $erori[] = "$cale: sub minimum {$s['minimum']}";
            }
            if (isset($s['maximum']) && $v > $s['maximum']) {
                $erori[] = "$cale: peste maximum {$s['maximum']}";
            }
        }
        if (is_array($v) && array_is_list($v) && ($v !== [] || $this->asteaptaLista($s))) {
            if (isset($s['minItems']) && count($v) < $s['minItems']) {
                $erori[] = "$cale: prea puține elemente";
            }
            if (isset($s['maxItems']) && count($v) > $s['maxItems']) {
                $erori[] = "$cale: prea multe elemente";
            }
            if (isset($s['items'])) {
                foreach ($v as $i => $e) {
                    $this->verifica($e, $s['items'], "{$cale}[$i]", $erori);
                }
            }
            return;
        }
        if (is_array($v)) {
            foreach ($s['required'] ?? [] as $r) {
                if (!array_key_exists($r, $v)) {
                    $erori[] = "$cale: lipsește „{$r}”";
                }
            }
            foreach ($s['properties'] ?? [] as $k => $sub) {
                if (array_key_exists($k, $v)) {
                    $this->verifica($v[$k], $sub, "$cale.$k", $erori);
                }
            }
        }
    }

    private function asteaptaLista(array $s): bool
    {
        return in_array('array', (array) ($s['type'] ?? []), true) || isset($s['items']);
    }

    private function areTip(mixed $v, string $t): bool
    {
        return match ($t) {
            'null' => $v === null,
            'boolean' => is_bool($v),
            'integer' => is_int($v),
            'number' => is_int($v) || is_float($v),
            'string' => is_string($v),
            'array' => is_array($v) && array_is_list($v),
            // Un obiect JSON gol ({}) se decodează ca listă goală.
            'object' => is_array($v) && (!array_is_list($v) || $v === []),
            default => false,
        };
    }
}
