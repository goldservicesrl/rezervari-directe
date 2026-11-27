<?php

declare(strict_types=1);

namespace Cazo\Email;

defined('CAZO') or exit;

/**
 * Un e-mail gata de trimis: destinatarul, expeditorul, subiectul, corpul HTML și text, atașamentele și antetele în plus
 * (numele => valoarea; ofertele către abonați au `List-Unsubscribe` și `List-Unsubscribe-Post`).
 */
final class Mesaj
{
    /**
     * @param list<array{nume:string, continut:string, tip:string}> $atasamente
     * @param array<string,string> $antete
     */
    public function __construct(
        public readonly string $catre,
        public readonly string $deLa,
        public readonly string $numeDeLa,
        public readonly ?string $raspunsLa,
        public readonly string $subiect,
        public readonly string $html,
        public readonly string $text,
        public readonly array $atasamente = [],
        public readonly array $antete = [],
    ) {
    }
}
