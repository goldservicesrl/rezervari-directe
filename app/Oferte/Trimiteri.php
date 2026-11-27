<?php

declare(strict_types=1);

namespace Cazo\Oferte;

use Cazo\Email\Cutie;
use Cazo\Email\Mesaj;
use Cazo\Email\Sabloane;
use Cazo\Nucleu\Aplicatie;

defined('CAZO') or exit;

/**
 * Ofertele trimise de gazdă abonaților: subiectul și textul (paragrafe despărțite de un rând gol), proba la adresa
 * gazdei, apoi trimiterea către lista unită (Abonati), prin cutia de e-mailuri. Fiecare destinatar e un e-mail `oferta`
 * în coadă, eșalonat după plafonul pe oră al cutiei (Cutie::OFERTE_PE_ORA), cu linkul lui de dezabonare și antetele
 * `List-Unsubscribe` + `List-Unsubscribe-Post` (RFC 8058). Corpul se scrie la trimitere: o adresă dezabonată între timp
 * rămâne în coadă cu starea `renuntat`.
 */
final class Trimiteri
{
    public const PROBA = 'oferta_proba';
    public const SUBIECT_MAX = 150;
    public const TEXT_MAX = 20000;
    public const SETARE_CIORNA = 'oferte.ciorna';

    public function __construct(private Aplicatie $app)
    {
    }

    public static function alSau(string $sablon): bool
    {
        return $sablon === Cutie::SABLON_OFERTA || $sablon === self::PROBA;
    }

    /** Paragrafele textului: despărțite de un rând gol; rândurile unui paragraf se unesc. @return list<string> */
    public static function paragrafe(string $text): array
    {
        $r = [];
        foreach (preg_split('~\R\s*\R~u', str_replace("\r\n", "\n", trim($text))) ?: [] as $p) {
            $p = trim((string) preg_replace('~\s*\R\s*~u', ' ', $p));
            if ($p !== '') {
                $r[] = $p;
            }
        }
        return $r;
    }

    /** Greșelile formularului: câmpul => cheia textului. @return array<string,string> */
    public static function valideaza(string $subiect, string $text): array
    {
        $e = [];
        if ($subiect === '' || mb_strlen($subiect) > self::SUBIECT_MAX || preg_match('~[\x00-\x1f\x7f]~', $subiect) === 1) {
            $e['subiect'] = 'admin.abonati.eroare_subiect';
        }
        if (self::paragrafe($text) === [] || mb_strlen($text) > self::TEXT_MAX) {
            $e['text'] = 'admin.abonati.eroare_text';
        }
        return $e;
    }

    /** O trimitere are încă e-mailuri în coadă. */
    public function inCurs(): bool
    {
        return $this->app->baza()->valoare("SELECT 1 FROM {emailuri} WHERE sablon = ? AND stare IN ('in_coada', 'in_lucru') LIMIT 1", [Cutie::SABLON_OFERTA]) !== null;
    }

    /** Proba la adresa gazdei: pleacă pe loc, prin cutie. Întoarce true când a plecat. */
    public function proba(string $subiect, string $text, array $u): bool
    {
        $id = $this->app->emailuri()->pune(null, self::PROBA, (string) $u['limba_panou'] === 'en' ? 'en' : 'ro', (string) $u['email'],
            ['subiect' => $subiect, 'text' => $text, 'nume' => (string) ($u['nume'] ?? '')]);
        return $this->app->emailuri()->trimite(1, $id)['trimise'] === 1;
    }

    /**
     * Trimiterea către toată lista unită de acum (citită din nou din EasyCheck). Fiecare e-mail are întârzierea lui, ca
     * într-o oră să plece cel mult plafonul cutiei.
     * @return array{id:int, destinatari:int}|string `in_curs` sau `gol`
     */
    public function trimite(string $subiect, string $text, array $u): array|string
    {
        if ($this->inCurs()) {
            return 'in_curs';
        }
        $lista = $this->app->abonati()->lista(true)['abonati'];
        if ($lista === []) {
            return 'gol';
        }
        $peOra = $this->app->emailuri()->ofertePeOra();
        $cutie = $this->app->emailuri();
        $id = $this->app->baza()->tranzactie(function () use ($subiect, $text, $u, $lista, $peOra, $cutie): int {
            $id = $this->app->baza()->insereaza('oferte_trimiteri', ['subiect' => $subiect, 'text' => $text, 'limba' => (string) $u['limba_panou'],
                'destinatari' => count($lista), 'creat_de' => (int) $u['id'], 'creat_la' => $this->app->ceas()->moment()]);
            foreach ($lista as $i => $a) {
                $e = $cutie->pune(null, Cutie::SABLON_OFERTA, $a['limba'], $a['email'], ['trimitere' => $id, 'nume' => $a['nume'] !== '' ? $cutie->secret($a['nume']) : ''],
                    intdiv($i * 3600, max(1, $peOra)));
                $this->app->baza()->insereaza('oferte_destinatari', ['trimitere_id' => $id, 'email_id' => $e]);
            }
            return $id;
        });
        $this->app->setari()->pune(self::SETARE_CIORNA, null);
        return ['id' => (int) $id, 'destinatari' => count($lista)];
    }

    /**
     * Jurnalul trimiterilor: data, subiectul, destinatarii și starea e-mailurilor lor.
     * @return list<array{id:int, subiect:string, creat_la:string, destinatari:int, trimise:int, esuate:int, in_coada:int, renuntate:int}>
     */
    public function jurnal(int $cate = 20): array
    {
        $r = [];
        foreach ($this->app->baza()->toate('SELECT * FROM {oferte_trimiteri} ORDER BY id DESC LIMIT ' . max(1, min(100, $cate))) as $x) {
            $n = array_column($this->app->baza()->toate('SELECT e.stare, COUNT(*) AS n FROM {oferte_destinatari} d JOIN {emailuri} e ON e.id = d.email_id WHERE d.trimitere_id = ? GROUP BY e.stare',
                [$x['id']]), 'n', 'stare');
            $r[] = ['id' => (int) $x['id'], 'subiect' => (string) $x['subiect'], 'creat_la' => (string) $x['creat_la'], 'destinatari' => (int) $x['destinatari'],
                'trimise' => (int) ($n['trimis'] ?? 0), 'esuate' => (int) ($n['esuat'] ?? 0), 'in_coada' => (int) ($n['in_coada'] ?? 0) + (int) ($n['in_lucru'] ?? 0),
                'renuntate' => (int) ($n['renuntat'] ?? 0)];
        }
        return $r;
    }

    /** Mesajul unei oferte (sau al probei); null când adresa s-a dezabonat după punerea în coadă ori trimiterea lipsește. */
    public function mesaj(string $sablon, string $limba, array $date, string $catre): ?Mesaj
    {
        if ($sablon === self::PROBA) {
            return $this->construieste((string) ($date['subiect'] ?? ''), (string) ($date['text'] ?? ''), $limba, $catre, (string) ($date['nume'] ?? ''));
        }
        $tr = $this->app->baza()->rand('SELECT * FROM {oferte_trimiteri} WHERE id = ?', [(int) ($date['trimitere'] ?? 0)]);
        if ($tr === null || $this->app->abonati()->dezabonatDupa($catre, (string) $tr['creat_la'])) {
            return null;
        }
        $nume = is_string($date['nume'] ?? null) && $date['nume'] !== '' ? $this->app->emailuri()->dezvaluie($date['nume']) : '';
        return $this->construieste((string) $tr['subiect'], (string) $tr['text'], $limba, $catre, $nume);
    }

    /** Datele mesajului, pentru e-mail și pentru previzualizarea din panou. @return array<string,mixed> */
    public function continut(string $subiect, string $text, string $limba, string $catre, string $nume): array
    {
        $t = $this->app->traducator($limba);
        $numeProp = (string) (($this->app->ecOglinda()->corp('proprietate') ?? [])['nume'] ?? '');
        $contact = Sabloane::contact((array) ($this->app->ecOglinda()->proprietatePublica() ?? []));
        $link = $this->app->abonati()->link($catre, $limba);
        return ['subiect' => $subiect, 'salut' => $nume !== '' ? $t->t('email.salut', ['nume' => $nume]) : $t->t('oferte.email.salut'),
            'paragrafe' => self::paragrafe($text), 'detalii' => [], 'sectiuni' => [], 'butoane' => [],
            'contact' => $contact !== '' ? $t->t('email.contact', ['contact' => $contact]) : '', 'semnatura' => $t->t('email.semnatura', ['nume' => $numeProp]),
            'nota_gazda' => '', 'nota_titlu' => '',
            'subsol' => ['text' => $t->t('oferte.email.subsol', ['nume' => $numeProp]), 'link' => $t->t('oferte.email.dezabonare'), 'url' => $link]];
    }

    private function construieste(string $subiect, string $text, string $limba, string $catre, string $nume): Mesaj
    {
        $m = $this->continut($subiect, $text, $limba, $catre, $nume);
        return $this->app->sabloaneEmail()->cadru($m, $this->app->traducator($limba), $limba, $catre, true, [],
            ['List-Unsubscribe' => '<' . $m['subsol']['url'] . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }
}
