<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

use Cazo\Baza\Zavor;
use Cazo\Nucleu\Ceas;
use Cazo\Nucleu\Jurnal;

defined('CAZO') or exit;

/**
 * Sincronizarea oglinzii: resursele învechite de anunțuri, apoi cele scadente, sub zăvorul
 * `sincronizare`, cu buget de timp. Bugetul API amână citirile când rămân sub 30 în fereastră; licența le oprește
 * după un 401 confirmat. O resursă care eșuează se reia cu pauză crescătoare (Oglinda::PAUZA_MAXIMA), iar
 * semnul de învechire pus de un anunț rămâne până la o citire reușită.
 */
final class Sincronizare
{
    public function __construct(
        private Oglinda $oglinda,
        private Buget $buget,
        private Licenta $licenta,
        private Zavor $zavor,
        private Ceas $ceas,
        private ?Jurnal $jurnal = null,
    ) {
    }

    /**
     * @param string[]|null $resurse null = cele învechite și cele scadente
     * @return array{rulat:bool, citite:array<string,string>, erori:array<string,string>, amanate:string[]}
     */
    public function ruleaza(?array $resurse = null, float $bugetSecunde = 15.0, bool $caleaAnuntului = false): array
    {
        $raport = ['rulat' => false, 'citite' => [], 'erori' => [], 'amanate' => []];
        $limita = hrtime(true) + (int) ($bugetSecunde * 1e9);
        [$luat, $r] = $this->zavor->cuZavor('sincronizare', (int) ceil($bugetSecunde) + 60, function () use ($resurse, $limita, $caleaAnuntului, $raport): array {
            $raport['rulat'] = true;
            $lista = $resurse ?? $this->oglinda->scadente();
            foreach (array_values(array_unique($lista)) as $res) {
                $rand = $this->oglinda->rand($res);
                $verificat = ($rand['verificat_la'] ?? null) !== null ? Ceas::dinMoment((string) $rand['verificat_la']) : null;
                if (hrtime(true) >= $limita || !$this->buget->permiteSincronizare() || !$this->licenta->permiteSincronizare($res, $verificat)) {
                    $raport['amanate'][] = $res;
                    continue;
                }
                try {
                    $raport['citite'][$res] = $this->oglinda->citeste($res, $caleaAnuntului);
                } catch (EroareTransport $e) {
                    $raport['erori'][$res] = $e->getMessage();
                    if ($e->felul === 'pauza' || $e->felul === 'fara_cheie') {
                        // Pauza cerută și cheia lipsă privesc toate resursele: restul așteaptă.
                        $raport['amanate'] = array_merge($raport['amanate'], array_slice($lista, array_search($res, $lista, true) + 1));
                        break;
                    }
                } catch (\Throwable $e) {
                    $raport['erori'][$res] = Oglinda::descrie($e);
                    $this->jurnal?->avertisment('Sincronizarea EasyCheck: ' . $res, ['eroare' => Oglinda::descrie($e)]);
                }
            }
            return $raport;
        });
        return $luat ? $r : $raport + ['ocupat' => true];
    }
}
