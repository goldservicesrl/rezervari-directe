<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

defined('CAZO') or exit;

/** EasyCheck a răspuns cu o eroare; codul, comportamentul (Erori) și request_id-ul, gata pentru jurnal și panou. */
final class EroareApi extends \RuntimeException
{
    public readonly ?string $cod;
    public readonly string $comportament;
    public readonly ?string $requestId;

    public function __construct(public readonly RaspunsEc $raspuns, string $operatie)
    {
        $this->cod = $raspuns->codEroare();
        $this->comportament = Erori::comportament($raspuns);
        $this->requestId = $raspuns->requestId();
        parent::__construct($operatie . ': ' . $raspuns->status . ' ' . ($this->cod ?? 'fara_cod') . ($this->requestId !== null ? ' (' . $this->requestId . ')' : ''));
    }

    public function detalii(): array
    {
        $d = $this->raspuns->eroare()['detalii'] ?? [];
        return is_array($d) ? $d : [];
    }
}
