<?php

declare(strict_types=1);

namespace Cazo\Email;

defined('CAZO') or exit;

/** Transportul e-mailurilor: SMTP (clientul nucleului, TransportSmtp) sau mail() ca rezervă; testele au transportul lor. */
interface Transport
{
    /** Trimite mesajul; la eșec aruncă \RuntimeException (cutia îl reîncearcă). */
    public function trimite(Mesaj $m): void;

    /** Numele transportului, pentru jurnal și panou. */
    public function nume(): string;
}
