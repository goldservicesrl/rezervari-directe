<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

defined('CAZO') or exit;

/**
 * Sursa timpului. Toate momentele din bază sunt în UTC, ca text „AAAA-LL-ZZ HH:MM:SS”
 * (DATETIME la MySQL, text la SQLite; ordinea lexicografică e și cea cronologică).
 */
class Ceas
{
    public function acum(): int
    {
        return time();
    }

    /** Momentul curent, în formatul bazei (UTC). */
    public function moment(int $plusSecunde = 0): string
    {
        return self::formateaza($this->acum() + $plusSecunde);
    }

    public static function formateaza(int $unix): string
    {
        return gmdate('Y-m-d H:i:s', $unix);
    }

    public static function dinMoment(string $moment): int
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $moment, new \DateTimeZone('UTC'));
        if ($d === false) {
            throw new \InvalidArgumentException('Moment invalid: ' . $moment);
        }
        return $d->getTimestamp();
    }
}
