<?php

declare(strict_types=1);

namespace Cazo\DateExterne;

defined('CAZO') or exit;

/** Un serviciu de date externe n-a răspuns sau a răspuns altceva decât forma așteptată. */
final class EroareExterna extends \RuntimeException
{
}
