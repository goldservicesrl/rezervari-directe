<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

defined('CAZO') or exit;

/**
 * Adresele generate de site, cu și fără rescriere:
 *   cu rescriere   /camere, /en/rooms
 *   fără rescriere /?r=/camere, /?r=/en/rooms
 */
final class Url
{
    public function __construct(private Config $config)
    {
    }

    public function cale(string $cale, ?string $limba = null, array $query = []): string
    {
        if ($cale === '' || $cale[0] !== '/') {
            throw new \InvalidArgumentException('Calea începe cu /.');
        }
        $limba ??= $this->config->limbaImplicita();
        if ($limba !== $this->config->limbaImplicita()) {
            $cale = '/' . $limba . ($cale === '/' ? '/' : $cale);
        }
        $codata = implode('/', array_map('rawurlencode', explode('/', $cale)));
        if (!$this->config->rescriere() && $cale !== '/') {
            $query = ['r' => $cale] + $query;
            $codata = '/';
        }
        return $codata . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function absolut(string $cale, ?string $limba = null, array $query = []): string
    {
        return $this->config->origine() . $this->cale($cale, $limba, $query);
    }
}
