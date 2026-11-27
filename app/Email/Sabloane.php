<?php

declare(strict_types=1);

namespace Cazo\Email;

use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cai;
use Cazo\Rezervare\Ics;
use Cazo\Teme\Jetoane;
use Cazo\Teme\MediuNucleu;

defined('CAZO') or exit;

/**
 * Șabloanele e-mailurilor (layoutul nucleului, cu culoarea de accent a temei). Textele vin din
 * cataloagele nucleului (email.*), deci intră în docs/texte-de-aprobat.md ca orice text; corpul se construiește la
 * trimitere din comandă (citită și decriptată atunci), din oglinda EasyCheck și din `date`.
 *
 * Către oaspete, în limba comenzii: cerere_primita, cerere_acceptata (contractul pe suport durabil, din textele
 * înghețate la comandă: identitatea gazdei, adresa, regulile casei, componentele totalului, informarea
 * precontractuală și termenii acceptați, atașați în versiunea lor; plus .ics și „Rezervarea mea”), cerere_refuzata,
 * cerere_expirata, cerere_netrimisa, cerere_indisponibila (409), cerere_respinsa (422, 401/403 și celelalte refuzuri
 * ale API-ului, cu textul motivului), cerere_intarziata (6 ore fără răspuns), cod; la transferul bancar:
 * transfer_asteptat (contul, suma, termenul, textul plății), transfer_reamintire, transfer_prelungit, transfer_neprimit,
 * transfer_expirat, transfer_anulat (anularea oaspetelui cât plata era așteptată), transfer_anulat_gazda, iar
 * confirmarea e plata_confirmata, cu suma primită prin transfer. Către gazdă, în limba panoului
 * proprietarului principal: alerta_intrerupator, alerta_tema (pagina servită cu _schelet) și alerta_cheie
 * (EasyCheck a refuzat cheia site-ului la o cerere a unui oaspete, 401/403).
 */
final class Sabloane
{
    public const OASPETE = ['cerere_primita', 'cerere_acceptata', 'cerere_refuzata', 'cerere_expirata', 'cerere_netrimisa', 'cerere_indisponibila',
        'cerere_respinsa', 'cerere_intarziata', 'cod', 'plata_confirmata', 'plata_in_procesare', 'plata_esuata', 'plata_rambursata', 'plata_verificare', 'plata_inchisa',
        'anulare_oaspete', 'anulare_gazda', 'anulare_ceruta', 'anulare_pastrata', 'rambursare_gazda', 'anulare_renuntata', 'anulare_renuntata_gratuita', 'schimbare_facuta', 'schimbare_esuata', 'rezervare_anulata',
        'transfer_asteptat', 'transfer_reamintire', 'transfer_prelungit', 'transfer_neprimit', 'transfer_expirat', 'transfer_anulat', 'transfer_anulat_gazda',
        // restituirea prin transfer bancar notată de gazdă în EasyCheck, anunțată la comanda `mesaj`.
        'restituire_transfer'];
    /** Confirmările (contractul pe suport durabil): șablonul gazdei se îngheață la comandă, ca paragraful (M6b). */
    public const CONFIRMARI = ['cerere_acceptata', 'plata_confirmata'];
    public const GAZDA = ['alerta_intrerupator', 'alerta_tema', 'alerta_cheie', 'gazda_rezervare', 'plata_oprita', 'plata_revenita', 'alerta_rambursare', 'alerta_comanda',
        'alerta_recuperare', 'alerta_disputa', 'alerta_protectie_oprita', 'alerta_preturi', 'alerta_plata_lipsa', 'alerta_recuperare_necunoscuta', 'alerta_anunt_stripe', 'drept_rambursare',
        'gazda_anulare', 'gazda_anulare_ceruta', 'alerta_anulare', 'alerta_captura', 'alerta_captura_necitita', 'alerta_eliberare', 'alerta_eliberare_diferenta', 'alerta_fereastra', 'alerta_orfana', 'alerta_orfana_negasita', 'alerta_diferenta', 'alerta_rambursare_procesare', 'alerta_rambursare_nelamurita', 'alerta_rambursare_nelamurita_auto',
        'alerta_rambursare_intoarsa', 'alerta_anulare_gazda', 'alerta_anulare_gazda_reamintire', 'gazda_schimbare', 'gazda_schimbare_cerere', 'alerta_schimbare', 'alerta_paritate', 'alerta_reduceri_mod',
        'invitatie', 'resetare_parola', 'proba_email', 'versiune_noua', 'gazda_transfer', 'alerta_transfer', 'alerta_taxa_transfer', 'alerta_despagubire', 'alerta_transfer_anuntat', 'alerta_transfer_anuntat_reamintire', 'gazda_cerere', 'alerta_plati_diferite', 'gazda_rambursare_ec'];
    /** Motivele opririi plății online (ModPlata), fiecare cu textul lui și pasul de urmat, în catalogul panoului. */
    public const MOTIVE_PLATA = ['stripe_lipsa', 'stripe_cheie', 'mod_mixt', 'easycheck_cheie', 'easycheck', 'politica'];

    public function __construct(private Aplicatie $app)
    {
    }

    /**
     * Un e-mail despre schimbarea datelor rămas în coadă până după anularea comenzii (de exemplu la reluările SMTP) nu
     * mai pleacă: datele lui („rămâne pe…”, „am mutat…”, .ics-ul, termenul gratuit) nu mai sunt adevărate, iar banii îi
     * spune e-mailul anulării. Rămâne doar e-mailul schimbării oprite chiar de anulare (`situatie` = `anulata`).
     */
    public function depasit(string $sablon, array $date): bool
    {
        if ($sablon !== 'schimbare_facuta' && $sablon !== 'schimbare_esuata') {
            return false;
        }
        $c = $this->app->comenzi()->dupaId((int) ($date['comanda_id'] ?? 0));
        if ($c === null || in_array($c['stare'], ['confirmata', 'anulare_ceruta'], true)) {
            return false;
        }
        if ($sablon === 'schimbare_facuta') {
            return true;
        }
        $s = $this->app->schimbareDate()->pentruEmail($c, (int) ($date['schimbare'] ?? 0));
        return $s !== null && \Cazo\Controlere\Schimbare::situatie($this->app, $s) !== 'anulata';
    }

    public function mesaj(string $sablon, string $limba, array $date, string $catre): Mesaj
    {
        $gazda = in_array($sablon, self::GAZDA, true);
        if (!$gazda && !in_array($sablon, self::OASPETE, true)) {
            throw new \InvalidArgumentException('Șablon de e-mail necunoscut: ' . $sablon);
        }
        $t = $this->app->traducator($limba, $gazda ? 'admin' : '');
        $p = (array) ($this->app->ecOglinda()->corp('proprietate') ?? []);
        $nume = (string) ($p['nume'] ?? '');
        // Contactul public: e-mailul; telefonul gazdei doar la alegerea ei. Într-un e-mail despre o rezervare
        // confirmată (mai jos), oaspetele primește și telefonul.
        $contact = self::contact((array) ($this->app->ecOglinda()->proprietatePublica() ?? []));
        $m = ['salut' => '', 'paragrafe' => [], 'detalii' => [], 'sectiuni' => [], 'butoane' => [], 'semnatura' => $t->t('email.semnatura', ['nume' => $nume]),
            'contact' => $contact !== '' && !$gazda ? $t->t('email.contact', ['contact' => $contact]) : ''];
        $atasamente = [];
        if ($gazda) {
            $m['salut'] = $t->t('email.salut');
            $m['subiect'] = $t->t('email.' . $sablon . '.subiect');
            $param = array_filter($date, 'is_scalar');
            // Sumele și datele se formatează în limba destinatarului, nu în limba celui care a pus alerta.
            foreach ($date as $k => $v) {
                // Orice sumă `<nume>_bani` ajunge în text ca {<nume>}, formatată în limba destinatarului.
                if (is_int($v) && is_string($k) && str_ends_with($k, '_bani')) {
                    $param[substr($k, 0, -5)] = Format::bani($v, $limba);
                }
            }
            if (is_string($date['sosire'] ?? null) && is_string($date['plecare'] ?? null)) {
                $param['perioada'] = Format::zi($date['sosire'], $limba) . ' - ' . Format::zi($date['plecare'], $limba);
            }
            if (isset($param['motiv']) && $sablon === 'alerta_tema') {
                $param['motiv'] = $t->t('email.alerta_tema.motiv.' . (in_array($param['motiv'], ['adancime', 'iteratii', 'valoare', 'elemente', 'iesire', 'timp', 'memorie'], true) ? $param['motiv'] : 'eroare'));
            }
            // Textele care spun gazdei că oaspeții i-au văzut contactul au varianta `_fara_contact`: fără telefon și fără
            // e-mail în EasyCheck, oaspetele a primit trimiterea spre pagina Date legale (Motiv::refuz).
            if ($sablon === 'gazda_rezervare') {
                $m = $this->gazdaRezervare($m, $t, $date);
            } elseif ($sablon === 'gazda_transfer') {
                $m = $this->gazdaTransfer($m, $t, $date);
            } elseif ($sablon === 'gazda_cerere') {
                $m = $this->gazdaCerere($m, $t, $date);
            } elseif ($sablon === 'alerta_transfer') {
                // transferul marcat neprimit de gazdă în EasyCheck sau termenul trecut; rezervarea e anulată.
                $param += ['suma' => '-', 'perioada' => '-', 'ref' => '-'];
                $m['subiect'] = $t->t('email.alerta_transfer.subiect', $param);
                $m['paragrafe'] = [$t->t(($date['fel'] ?? '') === 'neprimita' ? 'email.alerta_transfer.p1_neprimita' : 'email.alerta_transfer.p1_expirata', $param), $t->t('email.alerta_transfer.p2')];
            } elseif ($sablon === 'alerta_taxa_transfer') {
                // rezervarea plătită prin transfer s-a anulat; gazda returnează din bancă taxa locală plătită online.
                $param += ['taxa' => '-', 'perioada' => '-', 'ref' => '-'];
                $m['subiect'] = $t->t('email.alerta_taxa_transfer.subiect', $param);
                $m['paragrafe'] = [$t->t('email.alerta_taxa_transfer.p1', $param), $t->t('email.alerta_taxa_transfer.p2', $param)];
            } elseif ($sablon === 'alerta_despagubire') {
                // gazda a anulat rezervarea, iar contractul îi cere o despăgubire egală cu avansul (plătită din bancă).
                $param += ['avans' => '-', 'perioada' => '-', 'ref' => '-'];
                $m['subiect'] = $t->t('email.alerta_despagubire.subiect', $param);
                $m['paragrafe'] = [$t->t('email.alerta_despagubire.p1', $param)];
                // Forța majoră se notează după anulare doar la clauza cu despăgubirea egală cu avansul.
                if (($date['clauza'] ?? \Cazo\Rezervare\Anulare::GAZDA_DESPAGUBIRE) === \Cazo\Rezervare\Anulare::GAZDA_DESPAGUBIRE) {
                    $m['paragrafe'][] = $t->t('email.alerta_despagubire.p2', $param);
                }
            } elseif ($sablon === 'alerta_transfer_anuntat' || $sablon === 'alerta_transfer_anuntat_reamintire') {
                // oaspetele a apăsat „Am plătit”, iar site-ul o anunță singur pe gazdă.
                $param += ['suma' => '-', 'perioada' => '-', 'ref' => '-', 'data' => '-'];
                $m['subiect'] = $t->t('email.' . $sablon . '.subiect', $param);
                $m['paragrafe'] = [$t->t('email.' . $sablon . '.p1', $param), $t->t('email.' . $sablon . '.p2', $param)];
            } elseif ($sablon === 'gazda_anulare' || $sablon === 'gazda_anulare_ceruta') {
                $m = $this->gazdaAnulare($m, $t, $sablon, $date, $param);
            } elseif ($sablon === 'gazda_schimbare' || $sablon === 'gazda_schimbare_cerere') {
                $m = $this->gazdaSchimbare($m, $t, $sablon, $date);
            } elseif ($sablon === 'alerta_schimbare') {
                // Pasul de făcut în EasyCheck, după felul problemei (118d): rezervarea veche de anulat, rezervarea veche de
                // adăugat din nou, o rezervare nouă rămasă fără comandă sau EasyCheck care răspunde greu (la noile date sau la
                // refacerea celei vechi).
                $fel = in_array($date['fel'] ?? '', ['veche', 'refacere', 'noua', 'intarziere', 'intarziere_refacere', 'cerere', 'cerere_necitita'], true) ? (string) $date['fel'] : 'refacere';
                $param += ['ec' => '-', 'ec_noua' => '-', 'cod' => '-', 'perioada' => '-'];
                // La cererea rămasă după anulare, EasyCheck n-a răspuns nimic: primul paragraf spune ce s-a întâmplat pe site.
                $m['paragrafe'] = [$t->t(in_array($fel, ['cerere', 'cerere_necitita'], true) ? 'email.alerta_schimbare.p1_cerere' : 'email.alerta_schimbare.p1', $param), $t->t('email.alerta_schimbare.p2_' . $fel, $param)];
            } elseif ($sablon === 'alerta_anulare_gazda' || $sablon === 'alerta_anulare_gazda_reamintire') {
                // fraza banilor după rambursările anterioare și după o rambursare încă nelămurită (Anulare::textBaniLamurire).
                $b = ['incasat' => (int) ($date['incasat_bani'] ?? $date['suma_bani'] ?? 0), 'rambursat' => (int) ($date['rambursat_bani'] ?? 0),
                    'cerut' => (int) ($date['cerut_bani'] ?? 0), 'net' => (int) ($date['suma_bani'] ?? 0)];
                $m['paragrafe'] = [$t->t('email.' . $sablon . '.p1', $param) . ' ' . \Cazo\Rezervare\Anulare::textBaniLamurire($t, $b, static fn (int $s): string => Format::bani($s, $limba)),
                    $t->t('email.' . $sablon . '.p2', $param)];
            } elseif ($sablon === 'invitatie' || $sablon === 'resetare_parola') {
                // legătura cu jetonul stă criptată în coadă (Cutie::secret), ca o copie a bazei să n-o dezvăluie.
                $m['paragrafe'] = [$t->t('email.' . $sablon . '.p1', ['site' => $nume !== '' ? $nume : self::domeniu($this->app->config()->origine()),
                    'rol' => $t->t('admin.rol.' . (($date['rol'] ?? '') === 'personal' ? 'personal' : 'proprietar'))]), $t->t('email.' . $sablon . '.p2')];
                $url = is_string($date['url'] ?? null) && $date['url'] !== '' ? $this->app->emailuri()->dezvaluie($date['url']) : $this->app->url()->absolut('/admin/', 'ro');
                $m['butoane'][] = ['text' => $t->t('email.' . $sablon . '.buton'), 'url' => $url];
                $m['subiect'] = $t->t('email.' . $sablon . '.subiect', ['site' => $nume !== '' ? $nume : self::domeniu($this->app->config()->origine())]);
            } elseif ($sablon === 'versiune_noua') {
                // versiunea nouă de pe cazo.ro, cu notele în limba destinatarului și legătura de descărcare.
                $pv = ['versiune' => (string) ($date['versiune'] ?? ''), 'data' => is_string($date['data'] ?? null) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $date['data']) ? Format::zi($date['data'], $limba) : '-'];
                $m['subiect'] = $t->t('email.versiune_noua.subiect', $pv);
                $m['paragrafe'] = [$t->t('email.versiune_noua.p1', $pv)];
                if (($date['securitate'] ?? false) === true) {
                    $m['paragrafe'][] = $t->t('actualizari.noua_securitate');
                }
                $note = trim((string) ($date[$limba === 'ro' ? 'note_ro' : 'note_en'] ?? ''));
                if ($note !== '') {
                    $m['paragrafe'][] = $t->t('email.versiune_noua.note', ['note' => $note]);
                }
                $m['paragrafe'][] = $t->t('actualizari.cum');
                if (is_string($date['url'] ?? null) && str_starts_with($date['url'], 'https://')) {
                    $m['butoane'][] = ['text' => $t->t('actualizari.descarca'), 'url' => $date['url']];
                }
            } elseif ($sablon === 'alerta_plati_diferite') {
                // sumele din EasyCheck față de registrul site-ului, cu codul rezervării în subiect.
                $param += ['ref' => '-', 'incasat' => '-', 'rambursat' => '-', 'ec_platit' => '-', 'ec_rambursat' => '-'];
                $m['subiect'] = $t->t('email.alerta_plati_diferite.subiect', $param);
                $m['paragrafe'] = [$t->t('email.alerta_plati_diferite.p1', $param), $t->t('email.alerta_plati_diferite.p2', $param)];
            } elseif ($sablon === 'proba_email') {
                $m['paragrafe'] = [$t->t('email.proba_email.p1')];
            } elseif ($sablon === 'plata_oprita' || $sablon === 'plata_revenita') {
                $m['paragrafe'] = [$t->t('email.' . $sablon . '.p1')];
                foreach (array_intersect(explode(',', (string) ($date['motive'] ?? '')), self::MOTIVE_PLATA) as $mot) {
                    $m['paragrafe'][] = $t->t('plata.motiv.' . $mot) . ' ' . $t->t('plata.pas.' . $mot);
                }
                if ($sablon === 'plata_oprita') {
                    $m['paragrafe'][] = $t->t(($date['metode'] ?? '') === 'card' ? 'email.plata_oprita.oaspeti_rezerva' : (($date['metode'] ?? '') === 'transfer' ? 'email.plata_oprita.oaspeti_transfer' : 'email.plata_oprita.oaspeti_cash'));
                }
            } else {
                $m['paragrafe'] = [$t->cuContact('email.' . $sablon . '.p1', $contact, $param), $t->t('email.' . $sablon . '.p2', $param)];
            }
            if ($m['butoane'] === []) {
                $m['butoane'][] = ['text' => $t->t('email.panou'), 'url' => $this->app->url()->absolut('/admin/', 'ro')];
            }
        } else {
            $c = $this->app->comenzi()->dupaId((int) ($date['comanda_id'] ?? 0));
            if ($c === null) {
                throw new \RuntimeException('Comanda e-mailului lipsește.');
            }
            // în e-mailurile de după rezervare, rândul de contact are mereu și telefonul proprietății din
            // EasyCheck, chiar când pe site e ascuns; fără telefon în EasyCheck, rămâne e-mailul. E-mailurile de dinaintea
            // rezervării (FARA_TELEFON) păstrează contactul public, și când pleacă abia după acceptare. Cererea primită,
            // refuzul și expirarea (TELEFON_CERERE) au telefonul și fără bifa „Arătați telefonul pe site”.
            if (!in_array($sablon, self::FARA_TELEFON, true)) {
                if (in_array($sablon, ['cerere_acceptata', 'plata_confirmata'], true) || in_array($sablon, self::TELEFON_CERERE, true) || \Cazo\Controlere\Rezerva::confirmata($c)) {
                    $contact = self::contact($p);
                }
                $m['contact'] = self::randContact($t, (array) ($p['contact'] ?? []));
            }
            $o = $this->app->comenzi()->oaspete($c);
            $l = (string) $c['limba'];
            $ref = (string) $c['ref'];
            $perioada = $t->t('email.perioada', ['sosire' => Format::zi((string) $c['sosire'], $l), 'plecare' => Format::zi((string) $c['plecare'], $l)]);
            $m['salut'] = $t->t('email.salut', ['nume' => (string) ($o['nume'] ?? '')]);
            $m['subiect'] = $t->t('email.' . $sablon . '.subiect', ['ref' => $ref, 'cod' => '']);
            $rezervareaMea = ['text' => $t->t('stare.rezervarea_mea'), 'url' => $this->app->url()->absolut('/r/' . $this->app->comenzi()->token($c), $l)];
            $cautare = ['text' => $t->t('bara.cauta'), 'url' => $this->cautare($c, $l)];
            // blocurile și câmpurile proprii mesajului ({suma}, {termen}), din aceleași valori
            // care scriu mai jos mesajul de azi; le folosește doar un șablon scris de gazdă în EasyCheck.
            $b = [];
            $camp = [];
            switch ($sablon) {
                case 'cerere_primita':
                    [$zi, $ora] = self::termen($c['ec_expira_la'], $l);
                    $m['paragrafe'] = [$t->t('email.cerere_primita.p1', ['nume' => $nume, 'data' => $zi, 'ora' => $ora]), $t->t('stare.cerere_noptile')];
                    $m['detalii'] = $this->detalii($c, $t);
                    $m['butoane'][] = $rezervareaMea;
                    $camp['termen'] = $zi !== '' ? $t->t('email.camp.termen', ['data' => $zi, 'ora' => $ora]) : '';
                    break;
                case 'cerere_acceptata':
                case 'plata_confirmata':
                    // La transferul bancar, confirmarea spune suma primită și restul de la sosire.
                    $p1 = $sablon === 'plata_confirmata'
                        ? $t->t($c['fel'] === 'transfer' ? 'email.plata_confirmata.p1_transfer' : 'email.plata_confirmata.p1', ['nume' => $nume]) . ' ' . \Cazo\Controlere\Stare::textPlatita($t, $l, $c)
                        : $t->t('email.cerere_acceptata.p1', ['nume' => $nume]);
                    $m['paragrafe'] = [$p1, $t->t('stare.checkin'), $t->t('email.cerere_acceptata.ics')];
                    $b['bloc_plata'] = [['p', $c['fel'] === 'cash' ? $this->textPlata($c, $t) : \Cazo\Controlere\Stare::textPlatita($t, $l, $c)]];
                    $k = json_decode((string) ($c['contract'] ?? ''), true);
                    if (is_array($k) && isset($k['termeni']['versiune'])) {
                        // Contractul pe suport durabil, din textele înghețate la comandă, nu din oglinda de acum.
                        $m['paragrafe'][] = $t->t('email.contract.p1', ['versiune' => (string) $k['termeni']['versiune']]);
                        $m['detalii'] = $this->detaliiContract($c, $t, $k);
                        $m['sectiuni'][] = ['titlu' => $t->t('email.contract.precontractual'), 'paragrafe' => array_values(array_filter((array) ($k['precontractual'] ?? []), 'is_string'))];
                        $b['bloc_contract'] = [['p', end($m['paragrafe'])], ['detalii', $m['detalii']], ['sectiune', $m['sectiuni'][0]['titlu'], $m['sectiuni'][0]['paragrafe']]];
                        $m['butoane'][] = $rezervareaMea;
                        if (is_string($k['termeni']['url'] ?? null)) {
                            $m['butoane'][] = ['text' => $t->t('legal.link.termeni'), 'url' => $k['termeni']['url']];
                        }
                        $atasamente[] = ['nume' => 'termeni-' . preg_replace('~[^0-9a-z]~', '', (string) $k['termeni']['versiune']) . '.txt', 'continut' => self::textTermeni($k['termeni']),
                            'tip' => 'text/plain; charset=utf-8'];
                    } else {
                        $m['detalii'] = $this->detalii($c, $t);
                        $m['butoane'][] = $rezervareaMea;
                        $b['bloc_contract'] = [['detalii', $m['detalii']]];
                    }
                    $atasamente[] = ['nume' => strtolower($ref) . '.ics', 'continut' => $this->ics($c, $l), 'tip' => 'text/calendar; charset=utf-8; method=PUBLISH'];
                    break;
                case 'transfer_asteptat':
                case 'transfer_reamintire':
                case 'transfer_prelungit':
                    // Instrucțiunile de plată: contul înghețat pe comandă, suma exactă, termenul în ora României
                    // și textul de pus la detaliile plății; apoi rezumatul comenzii.
                    [$zi, $ora] = self::termen($c['transfer_termen'] ?? null, $l);
                    $param = ['nume' => $nume, 'ref' => $ref, 'suma' => Format::baniOaspete((int) $c['suma_acum'], $l), 'data' => $zi, 'ora' => $ora];
                    $m['paragrafe'] = [$t->t('email.' . $sablon . '.p1', $param), $t->t('email.' . $sablon . '.p2', $param)];
                    if ($sablon === 'transfer_prelungit' && $this->app->modLocal()) {
                        // termenul prelungit de gazdă din panoul site-ului, cu textul cerut de operator.
                        $m['subiect'] = $t->t('email.transfer_prelungit.subiect_local', $param);
                        $m['paragrafe'][0] = $t->t('email.transfer_prelungit.p1_local', $param);
                    }
                    $rest = \Cazo\Rezervare\Transfer::textRest($t, $c);
                    if ($sablon === 'transfer_asteptat') {
                        if ($rest !== '') {
                            $m['paragrafe'][] = $rest;
                        }
                        $m['paragrafe'][] = $t->t('email.transfer_asteptat.p3');
                    }
                    $instr = array_map(static fn (array $x): array => [$x['eticheta'], $x['valoare']], $this->app->transfer()->instructiuni($c, $t));
                    $det = $this->detalii($c, $t);
                    $m['detalii'] = array_merge($instr, array_slice($det, 1));
                    $m['butoane'][] = $rezervareaMea;
                    $b['bloc_transfer'] = $rest !== '' ? [['detalii', $instr], ['p', $rest]] : [['detalii', $instr]];
                    $b['bloc_rezervare'] = [['detalii', $det]];
                    $camp['suma'] = $param['suma'];
                    $camp['termen'] = $zi !== '' ? $t->t('email.camp.termen', ['data' => $zi, 'ora' => $ora]) : '';
                    break;
                case 'transfer_neprimit':
                case 'transfer_expirat':
                    // rezervarea s-a închis fiindcă plata a lipsit; oaspetele care a plătit totuși scrie gazdei.
                    $m['paragrafe'] = [$t->t('email.' . $sablon . '.p1', ['perioada' => $perioada, 'suma' => Format::baniOaspete((int) $c['suma_acum'], $l)]),
                        $t->cuContact('email.transfer_neprimit.p2', $contact)];
                    $m['detalii'] = $this->detalii($c, $t);
                    $m['butoane'][] = $cautare;
                    $camp['suma'] = Format::baniOaspete((int) $c['suma_acum'], $l);
                    break;
                case 'transfer_anulat':
                case 'transfer_anulat_gazda':
                    // anularea cât plata era așteptată; banii trimiși între timp îi întoarce gazda, din bancă.
                    $m['paragrafe'] = [$sablon === 'transfer_anulat' ? $t->t('email.transfer_anulat.p1', ['perioada' => $perioada]) : $t->cuContact('email.anulare_gazda.p1', $contact, ['perioada' => $perioada]),
                        $sablon === 'transfer_anulat' ? $t->cuContact('email.transfer_anulat.p2', $contact) : $t->t('email.transfer_anulat_gazda.p2')];
                    $m['detalii'] = $this->detalii($c, $t);
                    $b['bloc_restituire'] = [['p', $m['paragrafe'][1]]];
                    if ($sablon === 'transfer_anulat_gazda') {
                        $m['butoane'][] = $cautare;
                    }
                    break;
                case 'cerere_refuzata':
                case 'cerere_expirata':
                case 'cerere_netrimisa':
                    $m['paragrafe'] = [$t->cuContact('email.' . $sablon . '.p1', $contact, ['perioada' => $perioada])];
                    $m['detalii'] = $this->detalii($c, $t);
                    if ($sablon !== 'cerere_netrimisa') {
                        $m['butoane'][] = $cautare;
                    }
                    break;
                case 'cerere_indisponibila':
                case 'cerere_respinsa':
                    // Textul după motivul real, același cu pagina de stare (Motiv).
                    $mot = \Cazo\Rezervare\Motiv::refuz($this->app, $t, $c, $contact, true);
                    $zile = array_values(array_filter((array) ((json_decode((string) ($c['eroare_detalii'] ?? ''), true) ?: [])['zile'] ?? []), 'is_string'));
                    $m['paragrafe'] = [$sablon === 'cerere_indisponibila'
                        ? $t->t('email.cerere_indisponibila.p1', ['perioada' => $perioada, 'zile' => \Cazo\Rezervare\Motiv::zile($zile, $l)]) : $mot['text']];
                    $m['detalii'] = $this->detalii($c, $t);
                    $b['bloc_motiv'] = [['p', $m['paragrafe'][0]]];
                    if ($mot['continua'] !== null) {
                        $m['butoane'][] = ['text' => $t->t('stare.continua_total'), 'url' => $mot['continua']];
                    } elseif ($mot['cauta']) {
                        $m['butoane'][] = $cautare;
                    }
                    break;
                case 'cerere_intarziata':
                    [$zi, $ora] = self::termen(is_string($date['pana_la'] ?? null) ? $date['pana_la'] : null, $l);
                    $m['paragrafe'] = [$t->t('email.cerere_intarziata.p1', ['perioada' => $perioada, 'data' => $zi, 'ora' => $ora])];
                    $camp['termen'] = $zi !== '' ? $t->t('email.camp.termen', ['data' => $zi, 'ora' => $ora]) : '';
                    $m['detalii'] = $this->detalii($c, $t);
                    $m['butoane'][] = $rezervareaMea;
                    break;
                case 'plata_inchisa':
                    // Textul stării (Motiv): gazda rezolvă plata direct, cu contactul ei sau trimiterea spre Date legale.
                    $m['paragrafe'] = [\Cazo\Rezervare\Motiv::refuz($this->app, $t, $c, $contact, true)['text']];
                    $m['detalii'] = $this->detalii($c, $t);
                    $b['bloc_motiv'] = [['p', $m['paragrafe'][0]]];
                    break;
                case 'anulare_oaspete':
                case 'anulare_gazda':
                    // Anularea încheiată: suma returnată (`anulare_suma`), suma rămasă la gazdă sau, la plata la sosire, fără sume.
                    // Cu o parte a rambursării eșuată (două plăți), suma care pleacă de fapt (`suma_bani`).
                    $suma = (int) ($date['suma_bani'] ?? $c['anulare_suma'] ?? 0);
                    $bani = Format::baniOaspete($suma, $l);
                    $gazdaReturneaza = ($c['ec_restituire_cale'] ?? null) === 'in_afara_site';
                    if ($sablon === 'anulare_gazda') {
                        $m['paragrafe'] = [$t->cuContact('email.anulare_gazda.p1', $contact, ['perioada' => $perioada])];
                        // suma aleasă de gazdă, și zero; ce rămâne la gazdă se spune afirmativ (netul de la decizie
                        // minus suma returnată). După rambursări anterioare, netul e doar restul plății, nu suma plătită.
                        $rest = $c['fel'] === 'card' && $c['anulare_net'] !== null ? max(0, (int) $c['anulare_net'] - (int) ($c['anulare_suma'] ?? 0)) : 0;
                        $anterior = $rest > 0 && (int) ($this->app->anulare()->plata((int) $c['id'])['suma_incasata'] ?? 0) > (int) $c['anulare_net'];
                        if ($c['fel'] === 'card' && $suma > 0) {
                            // pe calea `in_afara_site` banii îi trimite gazda, în afara site-ului: doar suma, fără metodă.
                            $m['paragrafe'][] = $t->t($gazdaReturneaza ? 'email.anulare_gazda.ramb_gazda' : 'email.anulare_gazda.ramb', ['suma' => $bani]);
                            if ($rest > 0) {
                                $m['paragrafe'][] = $t->t('email.anulare_gazda.rest', ['rest' => Format::baniOaspete($rest, $l)]);
                            }
                        } elseif ($rest > 0) {
                            $m['paragrafe'][] = $t->t($anterior ? 'email.anulare_gazda.retinut_rest' : 'email.anulare_gazda.retinut', ['suma' => Format::baniOaspete($rest, $l)]);
                        }
                        // E-mailul anulării hotărâte în EasyCheck lasă deoparte despăgubirea; o plătește
                        // gazda, separat, după contract.
                    } else {
                        $cheie = $c['fel'] !== 'card' ? 'email.anulare_oaspete.p1' : ($suma > 0 ? ($gazdaReturneaza ? 'email.anulare_oaspete.p1_ramb_gazda' : 'email.anulare_oaspete.p1_ramb') : 'email.anulare_oaspete.p1_retinut');
                        $m['paragrafe'] = [$t->t($cheie, ['perioada' => $perioada, 'suma' => $bani])];
                    }
                    // banii primiți prin transfer bancar îi întoarce gazda, din bancă, după politica de anulare.
                    if ($c['fel'] === 'transfer' && $c['platita_la'] !== null && (int) $c['suma_acum'] > 0) {
                        // la tariful nerambursabil, suma plătită pentru cazare rămâne la gazdă; taxa locală din transfer se
                        // returnează.
                        $taxa = (int) ($c['nerambursabil'] ?? 0) === 1 ? \Cazo\Rezervare\Anulare::taxaOnline($c, (int) $c['suma_acum']) : 0;
                        $m['paragrafe'][] = $t->t((int) ($c['nerambursabil'] ?? 0) === 1 ? ($taxa > 0 ? 'email.anulare.transfer_bani_nerambursabil_taxa' : 'email.anulare.transfer_bani_nerambursabil')
                            : 'email.anulare.transfer_bani', ['suma' => Format::baniOaspete((int) $c['suma_acum'] - $taxa, $l), 'taxa' => Format::baniOaspete($taxa, $l)]);
                    }
                    // banii anulării (fără prima frază a anulării gazdei; la anularea oaspetelui cu cardul, prima frază e
                    // chiar suma returnată sau păstrată).
                    $b['bloc_restituire'] = array_map(static fn (string $x): array => ['p', $x], $sablon === 'anulare_gazda' || $c['fel'] !== 'card' ? array_slice($m['paragrafe'], 1) : $m['paragrafe']);
                    $m['detalii'] = $this->detalii($c, $t);
                    // Cu o plată a diferenței pentru o schimbare (oprită sau aplicată), banii plătiți nu mai sunt doar `suma_acum`:
                    // e-mailul anulării spune deja suma returnată și ce rămâne la gazdă, deci rândul plății lipsește.
                    if ((int) $this->app->baza()->valoare('SELECT COUNT(*) FROM {plati} WHERE comanda_id = ? AND schimbare_id IS NOT NULL AND suma_incasata > 0', [$c['id']]) > 0) {
                        $m['detalii'] = array_values(array_filter($m['detalii'], static fn (array $r): bool => $r[0] !== $t->t('comanda.plata')));
                    }
                    $m['butoane'][] = $sablon === 'anulare_gazda' ? $cautare : $rezervareaMea;
                    break;
                case 'schimbare_facuta':
                case 'schimbare_esuata':
                    // Schimbarea datelor (118-118d): datele noi cu banii (diferența plătită sau returnată) și noul termen
                    // gratuit, cu .ics-ul actualizat; sau motivul opririi și datele pe care rămâne rezervarea.
                    $s = $this->app->schimbareDate()->pentruEmail($c, (int) ($date['schimbare'] ?? 0));
                    if ($sablon === 'schimbare_facuta') {
                        $m['paragrafe'] = [$t->t('email.schimbare_facuta.p1', ['perioada' => $perioada])];
                        if ($c['fel'] === 'card' && (int) ($s['diferenta_la_sosire'] ?? 0) > 0) {
                            // banca a eliberat diferența reținută pe card înainte de încasare; ea se plătește la sosire.
                            $m['paragrafe'][] = $t->t('email.schimbare_facuta.diferenta_sosire', ['suma' => Format::baniOaspete((int) $s['diferenta_la_sosire'], $l)]);
                        } elseif ($c['fel'] === 'card' && (int) $s['diferenta'] > 0) {
                            $m['paragrafe'][] = $t->t('email.schimbare_facuta.platit', ['suma' => Format::baniOaspete((int) $s['diferenta'], $l)]);
                        } elseif ($c['fel'] === 'card' && (int) $s['rambursare_suma'] > 0) {
                            $m['paragrafe'][] = $t->t('email.schimbare_facuta.ramb', ['suma' => Format::baniOaspete((int) $s['rambursare_suma'], $l)]);
                        } elseif ($c['fel'] === 'card' && (int) $s['diferenta'] === 0 && \Cazo\Rezervare\SchimbareDate::laAvans($c)) {
                            // Plata cu avans (varianta B): avansul plătit rămâne, diferența de preț trece în restul de la sosire.
                            $m['paragrafe'][] = $t->t('schimbare.avans_ramane', ['suma' => Format::baniOaspete((int) $s['suma_acum'], $l), 'rest' => Format::baniOaspete((int) $s['rest_la_sosire'], $l)]);
                        } elseif ($c['fel'] === 'card' && (int) $s['diferenta'] === 0) {
                            // Plata întreagă (cu taxa locală la sosire, restul de la sosire e taxa): ca pagina mutării.
                            $m['paragrafe'][] = $t->t('schimbare.la_fel');
                            if ((int) $s['rest_la_sosire'] > 0) {
                                $m['paragrafe'][] = $t->t('comanda.la_sosire', ['suma' => Format::baniOaspete((int) $s['rest_la_sosire'], $l)]);
                            }
                        }
                        if ($c['fel'] === 'card' && $c['anulare_limita'] !== null && (int) $c['nerambursabil'] === 0) {
                            $m['paragrafe'][] = $t->t('schimbare.termen', ['data' => Format::ziLunga((string) $c['anulare_limita'], $l)]);
                        }
                        $atasamente[] = ['nume' => strtolower($ref) . '.ics', 'continut' => $this->ics($c, $l), 'tip' => 'text/calendar; charset=utf-8; method=PUBLISH'];
                    } else {
                        $m['paragrafe'] = [\Cazo\Controlere\Schimbare::textEsec($this->app, $t, $l, $s)];
                        $anulata = \Cazo\Controlere\Schimbare::situatie($this->app, $s) === 'anulata';
                        if ($anulata) {
                            // Comanda anulată între timp: subiectul spune că mutarea s-a oprit (rezervarea nu mai e pe nicio dată).
                            $m['subiect'] = $t->t('email.schimbare_esuata.subiect_anulata', ['ref' => $ref]);
                        }
                        // Diferența returnată integral; la o comandă anulată (oricând, din orice motiv), banii rămași îi spune
                        // e-mailul anulării, iar aici paragraful apare doar cu suma întreagă.
                        $activa = in_array($c['stare'], ['confirmata', 'anulare_ceruta'], true);
                        if ((int) $s['rambursare_suma'] > 0 && (($activa && !$anulata) || (int) $s['rambursare_suma'] >= (int) $s['diferenta'])) {
                            $m['paragrafe'][] = $t->t('email.schimbare_esuata.ramb', ['suma' => Format::baniOaspete((int) $s['rambursare_suma'], $l)]);
                        }
                        if ($this->app->schimbareDate()->conditii($c)['poate']) {
                            $m['paragrafe'][] = $t->t('email.schimbare_esuata.din_nou');
                        }
                    }
                    $m['detalii'] = $this->detalii($c, $t);
                    $m['butoane'][] = $rezervareaMea;
                    $b['bloc_schimbare'] = array_map(static fn (string $x): array => ['p', $x], $sablon === 'schimbare_facuta' ? array_slice($m['paragrafe'], 1) : $m['paragrafe']);
                    break;
                case 'anulare_ceruta':
                    $m['paragrafe'] = [$t->t('email.anulare_ceruta.p1', ['perioada' => $perioada])];
                    $m['detalii'] = $this->detalii($c, $t);
                    $m['butoane'][] = $rezervareaMea;
                    break;
                case 'anulare_renuntata':
                case 'anulare_renuntata_gratuita':
                    // Gazda a renunțat la o anulare refuzată de EasyCheck: rezervarea rămâne valabilă, iar oaspetele
                    // află ce poate face (contactul gazdei).
                    $m['paragrafe'] = [$t->cuContact('email.anulare_renuntata.p1', $contact, ['perioada' => $perioada])];
                    if ($sablon === 'anulare_renuntata_gratuita') {
                        // Anularea gratuită a oaspetelui rămâne valabilă până la sosire.
                        $m['paragrafe'][] = $t->t('email.anulare_renuntata.gratuita');
                        $b['bloc_anulare'] = [['p', $t->t('email.anulare_renuntata.gratuita')]];
                    }
                    $m['detalii'] = $this->detalii($c, $t);
                    $m['butoane'][] = $rezervareaMea;
                    break;
                case 'anulare_pastrata':
                    $m['paragrafe'] = [$t->cuContact('email.anulare_pastrata.p1', $contact, ['perioada' => $perioada])];
                    $m['detalii'] = $this->detalii($c, $t);
                    $m['butoane'][] = $rezervareaMea;
                    break;
                case 'rambursare_gazda':
                    $op = $this->app->baza()->rand('SELECT suma FROM {operatii_plata} WHERE id = ? AND comanda_id = ?', [(int) ($date['operatie'] ?? 0), (int) $c['id']]);
                    // Suma întregii rambursări (și împărțită pe două plăți); e-mailurile vechi au doar operația.
                    $m['paragrafe'] = [$t->t('email.rambursare_gazda.p1', ['perioada' => $perioada, 'suma' => Format::baniOaspete((int) ($date['suma_bani'] ?? $op['suma'] ?? 0), $l)])];
                    $m['detalii'] = $this->detalii($c, $t);
                    $m['butoane'][] = $rezervareaMea;
                    $camp['suma'] = Format::baniOaspete((int) ($date['suma_bani'] ?? $op['suma'] ?? 0), $l);
                    break;
                case 'restituire_transfer':
                    // gazda a notat în EasyCheck o restituire prin transfer bancar și a cerut anunțul (comanda `mesaj`).
                    $suma = Format::baniOaspete((int) ($date['suma_bani'] ?? 0), $l);
                    $m['paragrafe'] = [$t->t('email.restituire_transfer.p1', ['perioada' => $perioada, 'suma' => $suma])];
                    $m['detalii'] = $this->detalii($c, $t);
                    $m['butoane'][] = $rezervareaMea;
                    $camp['suma'] = $suma;
                    break;
                case 'plata_verificare':
                    $m['paragrafe'] = [$t->t('email.plata_verificare.p1', ['perioada' => $perioada])];
                    $m['detalii'] = $this->detalii($c, $t);
                    break;
                case 'plata_in_procesare':
                    $m['paragrafe'] = [$t->t('email.plata_in_procesare.p1', ['nume' => $nume])];
                    $m['detalii'] = $this->detalii($c, $t);
                    break;
                case 'rezervare_anulata':
                    // confirmarea anulării în EasyCheck, promisă în e-mailul de rezervă al capturii imposibile.
                    $m['paragrafe'] = [$t->t('email.rezervare_anulata.' . ($this->app->plataOnline()->cuRetinere((int) $c['id']) ? 'p1_eliberare' : 'p1_rambursare'), ['perioada' => $perioada])];
                    $b['bloc_motiv'] = [['p', $m['paragrafe'][0]]];
                    $m['detalii'] = $this->detalii($c, $t);
                    $m['butoane'][] = $cautare;
                    break;
                case 'plata_esuata':
                case 'plata_rambursata':
                    $mot = \Cazo\Rezervare\Motiv::refuz($this->app, $t, $c, $contact, true);
                    // suma doar rezervată pe card se eliberează, nu se returnează.
                    if ($sablon === 'plata_rambursata' && $this->app->plataOnline()->cuRetinere((int) $c['id'])) {
                        $m['subiect'] = $t->t('email.plata_eliberata.subiect', ['ref' => (string) $c['ref']]);
                    }
                    $m['paragrafe'] = [$sablon === 'plata_esuata' ? $t->t('email.plata_esuata.p1', ['perioada' => $perioada]) : $mot['text']];
                    $b['bloc_motiv'] = [['p', $mot['text']]];
                    $m['detalii'] = $this->detalii($c, $t);
                    if ($mot['continua'] !== null) {
                        $m['butoane'][] = ['text' => $t->t($sablon === 'plata_esuata' ? 'stare.reia_plata' : 'stare.continua_total'), 'url' => $mot['continua']];
                    } elseif ($mot['cauta']) {
                        $m['butoane'][] = $cautare;
                    }
                    break;
                case 'cod':
                    $cod = $this->app->emailuri()->dezvaluie((string) ($date['cod'] ?? ''));
                    $m['subiect'] = $t->t('email.cod.subiect', ['cod' => $cod]);
                    $m['paragrafe'] = [$t->t('email.cod.p1', ['cod' => $cod])];
                    $m['cod'] = $cod;
                    break;
            }
        }
        // M6b: zona editabilă a gazdei, la finalul e-mailului către oaspete, după tot conținutul contractual, separată ca
        // mesaj al gazdei (text simplu, escapat). În confirmări (contractul pe suport durabil) vine din contractul înghețat la
        // comandă, ca o retrimitere să iasă identic; la comenzile de dinainte de M6b, din setarea de acum.
        $m['nota_gazda'] = '';
        $m['nota_titlu'] = '';
        if (!$gazda) {
            $c = $this->app->comenzi()->dupaId((int) ($date['comanda_id'] ?? 0));
            $k = $c !== null ? json_decode((string) ($c['contract'] ?? ''), true) : null;
            // mesajul rescris de gazdă în EasyCheck, când există un șablon valid (ordinea surselor din contract).
            $sg = $sablon !== 'cod' && $c !== null ? $this->sablonGazda($sablon, $limba, $k, $date) : null;
            if ($sg !== null) {
                $cuSablon = $this->cuSablonGazda($sg, $c, $t, $m, $b, $camp, ['oaspete' => (string) ($o['nume'] ?? ''), 'proprietate' => $nume, 'perioada' => $perioada,
                    'contact_gazda' => $contact, 'link_rezervare' => $rezervareaMea['url']], $k);
                if ($cuSablon !== null) {
                    return $this->cadru($cuSablon, $t, $limba, $catre, true, $atasamente);
                }
            }
            // Previzualizarea din panou arată textul editat acum, nu pe cel înghețat la o comandă veche.
            $nota = empty($date['previzualizare']) && is_array($k) && is_array($k['nota_gazda'] ?? null) && in_array($sablon, ['cerere_acceptata', 'plata_confirmata'], true)
                ? (string) ($k['nota_gazda'][$sablon] ?? '') : self::notaEfectiva($this->app, $sablon, $limba);
            if ($nota !== '') {
                $m['nota_gazda'] = $nota;
                $m['nota_titlu'] = $t->t('email.nota_gazda.titlu');
            }
        }
        return $this->cadru($m, $t, $limba, $catre, !$gazda, $atasamente);
    }

    /**
     * Șablonul gazdei pentru acest e-mail, sau null (textul standard): la confirmări, cel înghețat la comandă (o
     * retrimitere iese identică; o comandă de dinainte de etapa 4 rămâne pe textul standard); altfel cel valid din oglindă.
     * Previzualizarea din panou arată mereu șablonul de acum.
     */
    private function sablonGazda(string $sablon, string $limba, mixed $k, array $date): ?array
    {
        if (in_array($sablon, self::CONFIRMARI, true) && empty($date['previzualizare'])) {
            $x = is_array($k) && is_array($k['sablon_gazda'] ?? null) ? ($k['sablon_gazda'][$sablon] ?? null) : null;
            return is_array($x) && is_string($x['subiect'] ?? null) && is_string($x['corp'] ?? null) && $x['corp'] !== '' ? $x : null;
        }
        return $this->app->mesajeEc()->sablon($sablon, $limba);
    }

    /**
     * Mesajul scris de gazdă: subiectul și corpul din șablon, cu câmpurile completate și
     * blocurile scrise de site; butoanele și atașamentele rămân ale site-ului, ca azi. Null când nu rămâne nimic de trimis din
     * șablon (toate paragrafele lăsate deoparte): atunci pleacă textul standard.
     * @param array<string, list<array>> $b blocurile calculate pe drumul de azi
     * @param array<string,string> $camp câmpurile proprii mesajului ({suma}, {termen})
     * @param array<string,string> $baza câmpurile știute deja
     */
    private function cuSablonGazda(array $sg, array $c, Traducator $t, array $m, array $b, array $camp, array $baza, mixed $k): ?array
    {
        $l = (string) $c['limba'];
        $b['bloc_rezervare'] ??= [['detalii', $this->detalii($c, $t)]];
        if (!isset($b['bloc_anulare'])) {
            // Regula de anulare a rezervării, ca propoziție: cea înghețată în contract, altfel rândul ei din detalii.
            $pol = is_array($k) && is_string($k['politica'] ?? null) ? trim($k['politica']) : '';
            if ($pol === '') {
                foreach ($b['bloc_rezervare'][0][1] as $r) {
                    if ($r[0] === $t->t('comanda.anulare')) {
                        $pol = (string) $r[1];
                    }
                }
            }
            $b['bloc_anulare'] = $pol !== '' ? [['p', $pol]] : [];
        }
        $platit = match ((string) $c['fel']) {
            'card' => (int) ($this->app->baza()->valoare('SELECT SUM(suma_incasata) FROM {plati} WHERE comanda_id = ?', [(int) $c['id']]) ?? 0),
            'transfer' => $c['platita_la'] !== null ? (int) $c['suma_acum'] : 0,
            default => 0,
        };
        $nopti = (int) (new \DateTimeImmutable((string) $c['sosire']))->diff(new \DateTimeImmutable((string) $c['plecare']))->days;
        $campuri = $baza + $camp + ['ref' => (string) $c['ref'], 'sosire' => Format::zi((string) $c['sosire'], $l), 'plecare' => Format::zi((string) $c['plecare'], $l),
            'nopti' => (string) $nopti, 'total' => Format::baniOaspete((int) $c['total'], $l), 'platit' => $platit > 0 ? Format::baniOaspete($platit, $l) : '',
            'rest' => (int) $c['rest_la_sosire'] > 0 ? Format::baniOaspete((int) $c['rest_la_sosire'], $l) : '', 'suma' => '', 'termen' => ''];
        $seg = SabloaneGazda::corp((string) $sg['corp'], $campuri, $b);
        if ($seg === []) {
            return null;
        }
        $subiect = SabloaneGazda::subiect((string) $sg['subiect'], $campuri);
        return ['subiect' => $subiect !== '' ? $subiect : (string) $m['subiect'], 'salut' => '', 'paragrafe' => [], 'detalii' => [], 'sectiuni' => [], 'butoane' => $m['butoane'],
            'contact' => '', 'semnatura' => '', 'segmente' => $seg, 'segmente_text' => SabloaneGazda::text($seg) . "\n\n", 'nota_gazda' => '', 'nota_titlu' => ''];
    }

    /**
     * Mesajul gata de trimis din datele unui e-mail ($m: subiect, salut, paragrafe, detalii, secțiuni, butoane, contact,
     * semnătură, nota gazdei; `subsol` pentru linkul de dezabonare al ofertelor): layoutul nucleului cu accentul temei,
     * expeditorul ales în panou și, către oaspete, răspunsul la adresa proprietății.
     * @param list<array{nume:string, continut:string, tip:string}> $atasamente
     * @param array<string,string> $antete
     */
    public function cadru(array $m, Traducator $t, string $limba, string $catre, bool $catreOaspete, array $atasamente = [], array $antete = []): Mesaj
    {
        $p = (array) ($this->app->ecOglinda()->corp('proprietate') ?? []);
        $nume = (string) ($p['nume'] ?? '');
        $setari = array_replace($this->app->temaActiva()->setariImplicite(), $this->app->temaSetari($this->app->temaActiva()->slug())['valori'] ?? []);
        $accent = is_string($setari['culoare_accent'] ?? null) && preg_match('~^#[0-9a-fA-F]{6}$~', $setari['culoare_accent']) ? $setari['culoare_accent'] : Jetoane::IMPLICITE['culoare_accent'];
        $m += ['cod' => null, 'limba' => $limba, 'accent' => $accent, 'nume' => $nume, 'subsol' => null];
        $env = MediuNucleu::creeaza(Aplicatie::radacina() . '/vederi', null, $t);
        $expeditor = $this->app->setari()->get('email.expeditor');
        $raspunsLa = filter_var((string) ($p['contact']['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string) $p['contact']['email'] : null;
        // Expeditorul: cel ales în panou; altfel rezervari@<domeniul site-ului>; pe o adresă fără domeniu (IP), e-mailul gazdei.
        $implicit = 'rezervari@' . self::domeniu($this->app->config()->origine());
        $deLa = is_array($expeditor) && filter_var($expeditor['adresa'] ?? '', FILTER_VALIDATE_EMAIL) ? (string) $expeditor['adresa']
            : (filter_var($implicit, FILTER_VALIDATE_EMAIL) && !filter_var(self::domeniu($this->app->config()->origine()), FILTER_VALIDATE_IP) ? $implicit : ($raspunsLa ?? 'rezervari@localhost.localdomain'));
        $numeDeLa = is_array($expeditor) && ($expeditor['nume'] ?? '') !== '' ? (string) $expeditor['nume'] : ($nume !== '' ? $nume : self::domeniu($this->app->config()->origine()));
        return new Mesaj($catre, $deLa, $numeDeLa, $catreOaspete ? $raspunsLa : null, (string) $m['subiect'],
            $env->render('emailuri/mesaj.html.twig', $m), $env->render('emailuri/mesaj.txt.twig', $m), $atasamente, $antete);
    }

    /** Rândurile cu detaliile comenzii: codul, perioada, camerele, tariful, totalul, plata. */
    private function detalii(array $c, Traducator $t): array
    {
        $l = (string) $c['limba'];
        $p = (array) ($this->app->ecOglinda()->corp('proprietate') ?? []);
        $ore = (array) ($p['ore'] ?? []);
        $r = [[$t->t('email.cod_comanda'), (string) $c['ref']]];
        $sosire = Format::zi((string) $c['sosire'], $l);
        $plecare = Format::zi((string) $c['plecare'], $l);
        $r[] = [$t->t('comanda.sosire'), is_string($ore['checkin_de_la'] ?? null) ? $t->t('comanda.sosire_ora', ['data' => $sosire, 'ora' => $ore['checkin_de_la']]) : $sosire];
        $r[] = [$t->t('comanda.plecare'), is_string($ore['plecare_pana_la'] ?? null) ? $t->t('comanda.plecare_ora', ['data' => $plecare, 'ora' => $ore['plecare_pana_la']]) : $plecare];
        $titluri = [];
        foreach ((array) ($this->app->ecOglinda()->corp('camere')['camere'] ?? []) as $x) {
            if (is_array($x) && is_string($x['id'] ?? null)) {
                $titluri[$x['id']] = (string) ($x['titlu'] ?? '');
            }
        }
        $camere = [];
        foreach ($this->app->comenzi()->camere($c) as $x) {
            $camere[] = $t->t('comanda.camera_oaspeti', ['camera' => $titluri[$x['camera']] ?? $x['camera'], 'oaspeti' => $t->plural('unitate.oaspeti', (int) $x['adulti'] + (int) $x['copii'])]);
        }
        $r[] = [$t->t('comanda.camere'), implode('; ', $camere)];
        if ($c['plan'] !== null) {
            $plan = $this->app->pretNoapte()->planuri()[(string) $c['plan']] ?? null;
            if ($plan !== null) {
                $r[] = [$t->t('comanda.tarif'), \Cazo\Teme\Componente::titluCuMasa($plan, $l, $this->app)];
                // Politica de anulare intră în contractul pe suport durabil (11).
                $comp = new \Cazo\Teme\Componente($this->app, $this->app->modelVedere($l), $t, $l, null, '0.0.0.0/24');
                $r[] = [$t->t('comanda.anulare'), $comp->politicaAnulare($plan, \Cazo\Rezervare\Rezumat::metodaComenzii($c))];
            }
        }
        $r[] = [$t->t('comanda.total'), Format::baniOaspete((int) $c['total'], $l)];
        $r[] = [$t->t('comanda.plata'), $this->textPlata($c, $t)];
        return $r;
    }

    /** Rândul plății: la sosire; la plata online, suma plătită și restul de la sosire; la transfer, suma primită sau de plătit și restul. */
    private function textPlata(array $c, Traducator $t): string
    {
        $l = (string) $c['limba'];
        $sosire = $t->t('comanda.la_sosire', ['suma' => Format::baniOaspete((int) $c['rest_la_sosire'], $l)]);
        if (($c['fel'] ?? 'cash') === 'transfer' && (int) $c['suma_acum'] > 0) {
            $transfer = $t->t($c['platita_la'] !== null ? 'comanda.platit_transfer' : 'comanda.transfer_de_platit', ['suma' => Format::baniOaspete((int) $c['suma_acum'], $l)]);
            return (int) $c['rest_la_sosire'] > 0 ? $transfer . '; ' . $sosire : $transfer;
        }
        return ($c['fel'] ?? 'cash') === 'card' && (int) $c['suma_acum'] > 0 ? $t->t('comanda.platit', ['suma' => Format::baniOaspete((int) $c['suma_acum'], $l)]) . '; ' . $sosire : $sosire;
    }

    /**
     * E-mailul scurt către gazdă la fiecare rezervare plătită: codul, oaspetele, perioada, camerele, plătit și
     * restul; suprapunerea apare prima, evidențiată. În limba panoului destinatarului.
     */
    private function gazdaRezervare(array $m, Traducator $t, array $date): array
    {
        $c = $this->app->comenzi()->dupaId((int) ($date['comanda_id'] ?? 0));
        if ($c === null) {
            throw new \RuntimeException('Comanda e-mailului lipsește.');
        }
        $l = $t->limba();
        $o = $this->app->comenzi()->oaspete($c);
        $param = ['ref' => (string) $c['ref'], 'oaspete' => (string) ($o['nume'] ?? '-'), 'perioada' => Format::zi((string) $c['sosire'], $l) . ' - ' . Format::zi((string) $c['plecare'], $l),
            'platit' => Format::bani((int) $c['suma_acum'], $l), 'rest' => Format::bani((int) $c['rest_la_sosire'], $l), 'total' => Format::bani((int) $c['total'], $l)];
        $m['subiect'] = $t->t('email.gazda_rezervare.subiect', $param);
        $m['paragrafe'] = [];
        if ((int) $c['suprapunere'] === 1) {
            $m['subiect'] = $t->t('email.gazda_rezervare.subiect_suprapunere', $param);
            $m['paragrafe'][] = $t->t('email.gazda_rezervare.suprapunere');
        }
        $m['paragrafe'][] = $t->t('email.gazda_rezervare.p1', $param);
        $m['paragrafe'][] = $t->t('email.gazda_rezervare.p2');
        $m['detalii'] = [[$t->t('email.gazda_rezervare.cod'), (string) $c['ref']], [$t->t('email.gazda_rezervare.oaspete'), $param['oaspete']],
            [$t->t('email.gazda_rezervare.contact'), implode(', ', array_filter([(string) ($o['email'] ?? ''), (string) ($o['telefon'] ?? '')]))]];
        return $m;
    }

    /**
     * E-mailul scurt către gazdă la o rezervare cu transfer bancar: ce are de urmărit în bancă (suma, termenul,
     * textul plății) și unde confirmă primirea banilor (în EasyCheck). În limba panoului destinatarului.
     */
    private function gazdaTransfer(array $m, Traducator $t, array $date): array
    {
        $c = $this->app->comenzi()->dupaId((int) ($date['comanda_id'] ?? 0));
        if ($c === null) {
            throw new \RuntimeException('Comanda e-mailului lipsește.');
        }
        $l = $t->limba();
        $o = $this->app->comenzi()->oaspete($c);
        $param = ['ref' => (string) $c['ref'], 'oaspete' => (string) ($o['nume'] ?? '-'), 'perioada' => Format::zi((string) $c['sosire'], $l) . ' - ' . Format::zi((string) $c['plecare'], $l),
            'suma' => Format::bani((int) $c['suma_acum'], $l), 'rest' => Format::bani((int) $c['rest_la_sosire'], $l), 'total' => Format::bani((int) $c['total'], $l),
            'termen' => ($c['transfer_termen'] ?? null) !== null ? Format::moment((string) $c['transfer_termen'], $l) : '-'];
        $m['subiect'] = $t->t('email.gazda_transfer.subiect', $param);
        $m['paragrafe'] = [$t->t('email.gazda_transfer.p1', $param), $t->t('email.gazda_transfer.p2', $param)];
        $m['detalii'] = [[$t->t('email.gazda_rezervare.cod'), (string) $c['ref']], [$t->t('email.gazda_rezervare.oaspete'), $param['oaspete']],
            [$t->t('email.gazda_rezervare.contact'), implode(', ', array_filter([(string) ($o['email'] ?? ''), (string) ($o['telefon'] ?? '')]))]];
        return $m;
    }

    /** Cererea nouă cu plata la sosire, fără cheie EasyCheck: gazda răspunde din panoul site-ului. */
    private function gazdaCerere(array $m, Traducator $t, array $date): array
    {
        $c = $this->app->comenzi()->dupaId((int) ($date['comanda_id'] ?? 0));
        if ($c === null) {
            throw new \RuntimeException('Comanda e-mailului lipsește.');
        }
        $l = $t->limba();
        $o = $this->app->comenzi()->oaspete($c);
        $param = ['ref' => (string) $c['ref'], 'oaspete' => (string) ($o['nume'] ?? '-'), 'perioada' => Format::zi((string) $c['sosire'], $l) . ' - ' . Format::zi((string) $c['plecare'], $l),
            'total' => Format::bani((int) $c['total'], $l), 'termen' => ($c['ec_expira_la'] ?? null) !== null ? Format::moment((string) $c['ec_expira_la'], $l) : '-',
            'panou' => $this->app->url()->absolut('/admin/')];
        $m['subiect'] = $t->t('email.gazda_cerere.subiect', $param);
        $m['paragrafe'] = [$t->t('email.gazda_cerere.p1', $param), $t->t('email.gazda_cerere.p2', $param)];
        $m['detalii'] = [[$t->t('email.gazda_rezervare.cod'), (string) $c['ref']], [$t->t('email.gazda_rezervare.oaspete'), $param['oaspete']],
            [$t->t('email.gazda_rezervare.contact'), implode(', ', array_filter([(string) ($o['email'] ?? ''), (string) ($o['telefon'] ?? '')]))]];
        return $m;
    }

    /**
     * E-mailurile către gazdă despre anulările oaspeților: anularea automată făcută (în perioada gratuită sau la
     * plata la sosire) și cererea de anulare de decis în panou, cu propunerea după regula EasyCheck.
     */
    private function gazdaAnulare(array $m, Traducator $t, string $sablon, array $date, array $param): array
    {
        $c = $this->app->comenzi()->dupaId((int) ($date['comanda_id'] ?? 0));
        if ($c === null) {
            throw new \RuntimeException('Comanda e-mailului lipsește.');
        }
        $l = $t->limba();
        $o = $this->app->comenzi()->oaspete($c);
        $param += ['ref' => (string) $c['ref'], 'oaspete' => (string) ($o['nume'] ?? '-'), 'perioada' => Format::zi((string) $c['sosire'], $l) . ' - ' . Format::zi((string) $c['plecare'], $l),
            'suma' => Format::bani((int) ($date['suma_bani'] ?? 0), $l)];
        $m['subiect'] = $t->t('email.' . $sablon . '.subiect', $param);
        $m['paragrafe'] = [$t->t('email.' . $sablon . '.p1', $param)];
        if ($sablon === 'gazda_anulare') {
            // la transferul bancar, banii (primiți sau trimiși între timp) îi întoarce gazda, din bancă.
            $p2 = $c['fel'] === 'transfer' ? ($c['platita_la'] !== null ? 'email.gazda_anulare.p2_transfer_platit' : 'email.gazda_anulare.p2_transfer_asteptat')
                : ($c['fel'] === 'card' ? ((int) ($date['suma_bani'] ?? 0) > 0 ? 'email.gazda_anulare.p2_card' : 'email.gazda_anulare.p2_retinut') : 'email.gazda_anulare.p2_cash');
            $m['paragrafe'][] = $t->t($p2, ['suma' => $c['fel'] === 'transfer' ? Format::bani((int) $c['suma_acum'], $l) : $param['suma']] + $param);
        } else {
            $m['paragrafe'][] = $t->t('email.gazda_anulare_ceruta.p2', $param);
        }
        $m['detalii'] = [[$t->t('email.gazda_rezervare.cod'), (string) $c['ref']], [$t->t('email.gazda_rezervare.oaspete'), $param['oaspete']]];
        return $m;
    }

    /**
     * E-mailurile către gazdă despre schimbarea datelor (118-118d): schimbarea făcută, cu ambele rezervări EasyCheck și
     * banii (diferența plătită, returnată sau totalul de la sosire), și cererea oaspetelui cu plata la sosire, la care
     * gazda răspunde din EasyCheck.
     */
    private function gazdaSchimbare(array $m, Traducator $t, string $sablon, array $date): array
    {
        $c = $this->app->comenzi()->dupaId((int) ($date['comanda_id'] ?? 0));
        if ($c === null) {
            throw new \RuntimeException('Comanda e-mailului lipsește.');
        }
        $s = $this->app->schimbareDate()->pentruEmail($c, (int) ($date['schimbare'] ?? 0));
        $l = $t->limba();
        $o = $this->app->comenzi()->oaspete($c);
        $param = ['ref' => (string) $c['ref'], 'oaspete' => (string) ($o['nume'] ?? '-'), 'perioada_veche' => Format::zi((string) $s['sosire_veche'], $l) . ' - ' . Format::zi((string) $s['plecare_veche'], $l),
            'perioada' => Format::zi((string) $s['sosire'], $l) . ' - ' . Format::zi((string) $s['plecare'], $l), 'ec_veche' => (string) $s['ec_veche'], 'ec_noua' => (string) ($s['ec_noua'] ?? ''),
            'total' => Format::bani((int) $s['total'], $l), 'suma' => Format::bani(abs((int) $s['diferenta']), $l),
            'avans' => Format::bani((int) ($s['suma_acum'] ?? 0), $l), 'rest' => Format::bani((int) ($s['rest_la_sosire'] ?? 0), $l)];
        $m['subiect'] = $t->t('email.' . $sablon . '.subiect', $param);
        if ($sablon === 'gazda_schimbare_cerere') {
            $m['paragrafe'] = [$t->t('email.gazda_schimbare_cerere.p1', $param), $t->t('email.gazda_schimbare_cerere.p2', $param)];
        } else {
            // La plata cu avans, cu diferența zero (varianta B), avansul rămâne și restul de la sosire se schimbă. La plata
            // întreagă cu taxa locală la sosire, suma plătită rămâne, iar restul de la sosire e taxa noilor date.
            // diferența trecută la sosire (captura imposibilă) e spusă ca atare, nu ca plătită.
            $bani = $s['fel'] !== 'card' ? 'p2_cash' : ((int) $s['diferenta'] > 0 ? ((int) ($s['diferenta_la_sosire'] ?? 0) > 0 ? 'p2_mai_scump_sosire' : 'p2_mai_scump') : ((int) $s['diferenta'] < 0 ? 'p2_mai_ieftin'
                : (\Cazo\Rezervare\SchimbareDate::laAvans($c) ? 'p2_avans' : ((int) ($s['rest_la_sosire'] ?? 0) > 0 ? 'p2_la_fel_rest' : 'p2_la_fel'))));
            $m['paragrafe'] = [$t->t('email.gazda_schimbare.p1', $param), $t->t('email.gazda_schimbare.' . $bani, $param)];
        }
        $m['detalii'] = [[$t->t('email.gazda_rezervare.cod'), (string) $c['ref']], [$t->t('email.gazda_rezervare.oaspete'), $param['oaspete']]];
        return $m;
    }

    /**
     * Detaliile contractului, din textele înghețate la comandă ($k, Rezerva::contract): codul, perioada cu orele,
     * camerele, tariful și anularea, totalul cu componentele, plata, gazda (identitatea legală), adresa, regulile casei.
     */
    private function detaliiContract(array $c, Traducator $t, array $k): array
    {
        $l = (string) $c['limba'];
        $r = [[$t->t('email.cod_comanda'), (string) $c['ref']]];
        $r[] = [$t->t('comanda.sosire'), (string) ($k['sosire'] ?? Format::zi((string) $c['sosire'], $l))];
        $r[] = [$t->t('comanda.plecare'), (string) ($k['plecare'] ?? Format::zi((string) $c['plecare'], $l))];
        $r[] = [$t->t('comanda.camere'), implode('; ', array_filter((array) ($k['camere'] ?? []), 'is_string'))];
        if (is_string($k['tarif'] ?? null)) {
            $r[] = [$t->t('comanda.tarif'), $k['tarif']];
        }
        $r[] = [$t->t('comanda.anulare'), (string) ($k['politica'] ?? '')];
        $r[] = [$t->t('comanda.total'), (string) ($k['total'] ?? Format::baniOaspete((int) $c['total'], $l))];
        foreach ((array) ($k['componente'] ?? []) as $x) {
            if (is_array($x) && is_string($x['eticheta'] ?? null) && is_string($x['text'] ?? null)) {
                $r[] = [$x['eticheta'], $x['text']];
            }
        }
        $r[] = [$t->t('comanda.plata'), $this->textPlata($c, $t)];
        $gazda = [];
        foreach ((array) ($k['operator'] ?? []) as $x) {
            if (is_array($x) && is_string($x['valoare'] ?? null)) {
                $gazda[] = (is_string($x['eticheta'] ?? null) ? $x['eticheta'] . ': ' : '') . $x['valoare'];
            }
        }
        if ($gazda !== []) {
            $r[] = [$t->t('email.contract.gazda'), implode('; ', $gazda)];
        }
        if (is_string($k['adresa'] ?? null) && $k['adresa'] !== '') {
            $r[] = [$t->t('email.contract.adresa'), $k['adresa']];
        }
        $reguli = array_values(array_filter((array) ($k['reguli'] ?? []), 'is_string'));
        if ($reguli !== []) {
            $r[] = [$t->t('comanda.reguli'), implode('; ', $reguli)];
        }
        return $r;
    }

    /** Termenii acceptați, ca text simplu (atașamentul contractului), în versiunea înghețată la comandă. */
    public static function textTermeni(array $termeni): string
    {
        $s = (string) ($termeni['titlu'] ?? '') . "\n\n";
        // Introducerea: lista paragrafelor; contractele înghețate înainte o au ca text.
        $intro = $termeni['intro'] ?? [];
        foreach (is_array($intro) ? $intro : [$intro] as $p) {
            if ((string) $p !== '') {
                $s .= (string) $p . "\n\n";
            }
        }
        foreach ((array) ($termeni['sectiuni'] ?? []) as $x) {
            $s .= (string) ($x['titlu'] ?? '') . "\n";
            foreach ((array) ($x['paragrafe'] ?? []) as $p) {
                $s .= (string) $p . "\n";
            }
            $s .= "\n";
        }
        return $s . (string) ($termeni['eticheta_versiune'] ?? '') . "\n";
    }

    /** Fișierul .ics al comenzii (și pentru /r/{token}.ics). */
    public function ics(array $c, string $limba): string
    {
        $t = $this->app->traducator($limba);
        $p = (array) ($this->app->ecOglinda()->corp('proprietate') ?? []);
        $a = (array) ($p['adresa'] ?? []);
        $ore = (array) ($p['ore'] ?? []);
        return Ics::eveniment([
            'ref' => (string) $c['ref'],
            'sosire' => (string) $c['sosire'],
            'plecare' => (string) $c['plecare'],
            'checkin' => (string) ($ore['checkin_de_la'] ?? ''),
            'plecare_ora' => (string) ($ore['plecare_pana_la'] ?? ''),
            'titlu' => $t->t('ics.titlu', ['nume' => (string) ($p['nume'] ?? '')]),
            'descriere' => $t->t(self::cheieIcs($c), ['ref' => (string) $c['ref'], 'checkin' => (string) ($ore['checkin_de_la'] ?? ''), 'plecare' => (string) ($ore['plecare_pana_la'] ?? ''),
                'platit' => Format::baniOaspete((int) $c['suma_acum'], $limba), 'rest' => Format::baniOaspete((int) $c['rest_la_sosire'], $limba)]),
            'locatie' => implode(', ', array_filter([(string) ($a['strada'] ?? ''), (string) ($a['localitate'] ?? ''), (string) ($a['judet'] ?? '')])),
            'url' => $this->app->url()->absolut('/r/' . $this->app->comenzi()->token($c), $limba),
            'domeniu' => self::domeniu($this->app->config()->origine()),
            'acum' => $this->app->ceas()->acum(),
            // După o schimbare de date, același UID cu SEQUENCE mai mare: calendarul înlocuiește evenimentul (RFC 5545).
            'secventa' => (int) ($c['schimbari'] ?? 0),
        ]);
    }

    /** Textul plății din .ics: la sosire (cash), plătită integral online sau avansul plătit cu restul la sosire. */
    private static function cheieIcs(array $c): string
    {
        if (!in_array($c['fel'] ?? 'cash', ['card', 'transfer'], true) || (int) $c['suma_acum'] <= 0) {
            return 'ics.descriere';
        }
        $transfer = $c['fel'] === 'transfer' ? '_transfer' : '';
        return (int) $c['rest_la_sosire'] > 0 ? 'ics.descriere_rest' . $transfer : 'ics.descriere_platita' . $transfer;
    }

    /** Căutarea precompletată pe aceleași date (pagina arată și datele vecine când totul e ocupat). */
    private function cautare(array $c, string $limba): string
    {
        $camere = $this->app->comenzi()->camere($c);
        return $this->app->url()->absolut(Cai::cale('disponibil', $limba), $limba, ['sosire' => (string) $c['sosire'], 'plecare' => (string) $c['plecare'],
            'adulti' => (string) ($camere[0]['adulti'] ?? 2), 'copii' => (string) ($camere[0]['copii'] ?? 0)]);
    }

    /** @return array{0:string,1:string} ziua și ora termenului, în ora României */
    public static function termen(?string $momentUtc, string $limba): array
    {
        if ($momentUtc === null || $momentUtc === '') {
            return ['', ''];
        }
        $d = (new \DateTimeImmutable($momentUtc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(Format::FUS));
        return [Format::ziLunga($d->format('Y-m-d'), $limba), $d->format('H:i')];
    }

    /** E-mailurile către oaspete trimise înainte să existe o rezervare (codul, cererea încă neacceptată): fără telefonul ascuns. */
    public const FARA_TELEFON = ['cod', 'cerere_netrimisa', 'cerere_indisponibila', 'cerere_respinsa', 'cerere_intarziata'];

    /**
     * E-mailurile cererii dinaintea confirmării care au telefonul gazdei din EasyCheck, și cu bifa „Arătați telefonul pe
     * site” nebifată: cererea primită, refuzul, expirarea.
     * Paginile rămân după bifă (`Oglinda::proprietatePublica`).
     */
    public const TELEFON_CERERE = ['cerere_primita', 'cerere_refuzata', 'cerere_expirata'];

    /** „Pentru orice întrebare, ne găsiți la {email} sau la telefon {telefon}.”; cu o singură cale, doar ea; fără ele, ''. */
    public static function randContact(Traducator $t, array $contact): string
    {
        $email = trim((string) ($contact['email'] ?? ''));
        $telefon = trim((string) ($contact['telefon'] ?? ''));
        if ($email !== '' && $telefon !== '') {
            return $t->t('email.contact_telefon', ['email' => $email, 'telefon' => $telefon]);
        }
        return $email . $telefon !== '' ? $t->t('email.contact', ['contact' => $email . $telefon]) : '';
    }

    public static function contact(array $p): string
    {
        return implode(', ', array_filter([(string) ($p['contact']['telefon'] ?? ''), (string) ($p['contact']['email'] ?? '')]));
    }

    /**
     * Paragraful gazdei care pleacă acum: fără `functii.mesaje` (și în modul local), cel scris pe site, ca azi;
     * cu funcția, până la încheierea importului în EasyCheck, apoi doar cel respins la import (păstrat de site).
     */
    public static function notaEfectiva(Aplicatie $app, string $sablon, string $limba): string
    {
        try {
            if (!$app->mesajeEc()->notaInca($sablon, $limba)) {
                return '';
            }
        } catch (\Throwable) {
        }
        return self::notaGazdei($app, $sablon, $limba);
    }

    /** Paragraful gazdei pentru un șablon către oaspeți (M6b), sau ''. */
    public static function notaGazdei(Aplicatie $app, string $sablon, string $limba): string
    {
        try {
            return trim((string) ($app->baza()->valoare('SELECT nota FROM {sabloane_email} WHERE cheie = ? AND limba = ?', [$sablon, $limba]) ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    public static function domeniu(string $origine): string
    {
        $h = (string) parse_url($origine, PHP_URL_HOST);
        return $h !== '' ? $h : 'localhost';
    }
}
