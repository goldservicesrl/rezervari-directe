<?php

declare(strict_types=1);

namespace Cazo\EasyCheck;

use Cazo\Controlere\AdminPanou;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Ceas;

defined('CAZO') or exit;

/**
 * „Starea site-ului” trimisă la EasyCheck (etapa 1 din planul „EasyCheck întâi”, contractul din 20.11.2026): gazda
 * află în EasyCheck, la „Azi” și pe e-mail, problemele site-ului, inclusiv că site-ul a amuțit.
 *
 *  - `PUT /site`: declarația (programul, versiunea, capabilitățile de azi, adresa panoului), la legarea cheii, după
 *    fiecare actualizare a programului și o dată pe zi; un 404 (un EasyCheck mai vechi) oprește încercările o zi;
 *  - `PUT /site/stare`: starea întreagă (ultima rulare a cronului, plata online, e-mailurile, lista alertelor de acum, pe
 *    codurile contractului), la fiecare schimbare a listei de alerte și, ca semn de viață, la 15 minute;
 *  - doar când `GET /eu` are `functii.stare` = true; cu un EasyCheck mai vechi (fără `functii`), softul tace;
 *  - pe cheia de probă pleacă la fel (EasyCheck le arată doar pe cardul cheii);
 *  - prin stare trec doar coduri, numere, momente, id-urile EasyCheck ale rezervărilor (`rez_…`) și o cale din panou:
 *    nicio dată a oaspeților, niciun text liber;
 *  - erorile de trimitere rămân aici: o linie pe oră în jurnal, următoarea încercare după 5 minute; scrierile trec doar
 *    peste rezerva scrierilor critice (Buget::permiteScriereNecritica), deci plățile și cererile au mereu loc;
 *  - `stare_easycheck => false` în config.php oprește tot (fără interfață).
 *
 * Alertele vin din trei locuri: alertele de acum ale panoului (`AdminPanou::alerte()`, tabelul `PANOU`), două stări ținute
 * de site (verificarea fișierelor și paritatea prețurilor, `stari()`) și jurnalul alertelor trimise gazdei pe e-mail în
 * ultimele 24 de ore (tabelul `alerte`, șabloanele din `SABLOANE`): acestea sunt întâmplări fără o stare a lor în panou
 * (o captură eșuată, o plată contestată), așa că rămân în stare o zi, cât să ajungă la gazdă și în EasyCheck.
 * E-mailurile proprii ale site-ului pleacă în continuare ca până acum.
 */
final class StareSite
{
    public const PROGRAM = 'cazo';
    /**
     * Ce știe programul de azi din ce recunoaște EasyCheck (contractul): starea site-ului și rapoartele plăților
     * (contractul), apoi anulările și rambursările hotărâte în EasyCheck (comenzile,
     * rambursările pe card, cererile de anulare ale oaspeților) și mesajele către oaspeți scrise în EasyCheck (
     * contract-mesaje.md). O funcție se aprinde în EasyCheck doar când amândouă
     * părțile au declarat-o; comutatorul rambursărilor (`rambursari_permise`) poate fi oprit cu capabilitatea declarată.
     */
    public const CAPABILITATI = ['stare', 'plati', 'comenzi', 'rambursari', 'cereri_anulare', 'mesaje'];
    public const SETARE = 'ec.site';
    /** Semnul de viață: la 15 minute (cu un minut de îngăduință, ca un cron la 5 minute să nu-l împingă la 20). */
    public const RITM = 900;
    public const INGADUINTA = 60;
    public const ZILNIC = 86400;
    /** După o eroare, următoarea încercare. */
    public const RELUARE = 300;
    /** Jurnalul site-ului: cel mult o linie pe oră despre trimiterile nereușite. */
    public const JURNAL = 3600;
    /** Cât rămâne în stare o alertă trimisă gazdei pe e-mail (o întâmplare fără stare proprie în panou). */
    public const JURNAL_ALERTE = 86400;
    public const ALERTE_MAX = 50;
    /** Rândul din panou („le vedeți și în EasyCheck”) apare cât ultima stare trimisă e mai nouă de atât. */
    public const PROASPAT = 2700;
    /**
     * Semnalul „instalare încheiată”: setarea ținută de la încheierea instalatorului până la
     * confirmarea EasyCheck. Starea pleacă cu `instalare`, `adresa` și `versiune` cât semnalul așteaptă confirmarea
     * (`instalare_confirmata` în răspuns).
     */
    public const SETARE_INSTALARE = 'ec.instalare';
    public const INSTALARE_INCHEIATA = 'incheiata';

    /**
     * Alertele de acum ale panoului (cheia din `AdminPanou::alerte()`) => [codul contractului sau null, gravă]. Null: rămâne a
     * site-ului (contractul): `cheie` (EasyCheck nu poate fi anunțat cu o cheie refuzată) și cele pe care EasyCheck le
     * știe singur sau le va primi ca decizii în etapele următoare. Gravitatea e recomandarea contractului.
     */
    public const PANOU = [
        'configurare' => ['configurare', false],
        'plata_oprita' => ['plata_oprita', true],
        'politica' => ['politica', false],
        'avans_zero' => ['avans_zero', false],
        'intrerupator' => ['intrerupator', true],
        // nota temei scoase din program e doar a gazdei.
        'tema_scoasa' => [null, false],
        'cheie' => [null, false],
        // EasyCheck știe singur unde sunt rezervările directe (`site_web.rezervari_pe`).
        'rezervari_pagina' => [null, false],
        'legal_vanzare' => ['legal_vanzare', true],
        'ore_neconfirmate' => ['ore_neconfirmate', false],
        // alegerea gazdei pentru anularea făcută de ea lipsește: o setare obligatorie, ca metodele de plată.
        'anulare_gazda' => ['configurare', false],
        'legal' => ['legal', false],
        'model_legal' => ['model_legal', false],
        'termene' => ['termene', false],
        'termene_praguri' => ['termene_praguri', false],
        'anulare_ceruta' => [null, false],
        'anulare_refuzata' => [null, false],
        'transfer_asteptat' => [null, false],
        'anulare_de_lamurit' => [null, false],
        'de_rambursat' => ['de_rambursat', true],
        'rambursare_esuata' => ['rambursare_esuata', true],
        'rambursare_procesare' => ['rambursare_procesare', true],
        'blocata' => ['plata_blocata', true],
        // taxa locală de returnat din bancă (transfer): rămâne a site-ului, fără cod în contractul EasyCheck.
        'taxa_de_returnat' => [null, false],
        // despăgubirea oaspetelui după anularea gazdei, plătită din bancă: a site-ului, fără cod în contractul EasyCheck.
        'despagubire' => [null, false],
        'suprapunere' => [null, false],
        // o rambursare comandată din EasyCheck nu se poate încheia (raportul nu ajunge sau comanda s-a oprit cu bani mișcați).
        'comenzi_blocate' => ['comenzi_blocate', true],
        'cron' => ['cron', true],
        // sarcinile au stat cel puțin o oră pe un site fără cron; același cod al contractului („adăugați cronul”).
        'cron_pauza' => ['cron', true],
        'emailuri' => ['emailuri', true],
        'audit' => ['audit', true],
        'nepublicat' => ['nepublicat', false],
        // diferența de preț pe noapte apare și ca alertă pe Acasă; la EasyCheck pleacă, ca până acum, din `stari()`.
        'paritate' => [null, false],
    ];

    /**
     * Șabloanele e-mailurilor către gazdă (`Sabloane::GAZDA`) => [codul contractului sau null, gravă]. Cele cu cod sunt
     * alerte ajunse în jurnalul alertelor (`Aplicatie::alertaGazda`); null: rămâne a site-ului (e-mailuri de sistem, alerte
     * pe care EasyCheck le știe singur) ori e acoperită de o stare (`plata_oprita`, `intrerupator` din panou;
     * `alerta_paritate` din `stari()`).
     */
    public const SABLOANE = [
        'alerta_intrerupator' => [null, false],
        'alerta_tema' => ['tema', true],
        'alerta_cheie' => [null, false],
        'gazda_rezervare' => [null, false],
        'plata_oprita' => [null, false],
        'plata_revenita' => [null, false],
        'alerta_rambursare' => ['de_rambursat', true],
        'alerta_comanda' => ['rezervare_netrimisa', true],
        'alerta_recuperare' => ['rezervare_netrimisa', true],
        'alerta_disputa' => ['plata_contestata', true],
        'alerta_protectie_oprita' => ['protectie_oprita', true],
        'alerta_preturi' => ['preturi', false],
        'alerta_plata_lipsa' => ['plata_lipsa', true],
        'alerta_recuperare_necunoscuta' => ['plata_blocata', true],
        'alerta_anunt_stripe' => ['anunt_stripe', true],
        'drept_rambursare' => [null, false],
        'gazda_anulare' => [null, false],
        'gazda_anulare_ceruta' => [null, false],
        'alerta_anulare' => [null, false],
        'alerta_captura' => ['captura_esuata', true],
        'alerta_captura_necitita' => ['captura_esuata', true],
        'alerta_eliberare' => ['eliberare_esuata', true],
        'alerta_eliberare_diferenta' => ['eliberare_esuata', true],
        'alerta_fereastra' => ['captura_esuata', true],
        'alerta_orfana' => ['plata_orfana', true],
        'alerta_orfana_negasita' => ['plata_orfana', true],
        'alerta_diferenta' => ['plata_diferita', true],
        'alerta_rambursare_procesare' => ['rambursare_procesare', true],
        'alerta_rambursare_nelamurita' => ['rambursare_nelamurita', true],
        'alerta_rambursare_nelamurita_auto' => ['rambursare_nelamurita', true],
        'alerta_rambursare_intoarsa' => ['rambursare_intoarsa', true],
        'alerta_anulare_gazda' => [null, false],
        'alerta_anulare_gazda_reamintire' => [null, false],
        'gazda_schimbare' => [null, false],
        'gazda_schimbare_cerere' => [null, false],
        'alerta_schimbare' => [null, false],
        'alerta_paritate' => [null, false],
        'alerta_reduceri_mod' => ['reduceri_mod', false],
        'invitatie' => [null, false],
        'resetare_parola' => [null, false],
        'proba_email' => [null, false],
        'versiune_noua' => [null, false],
        'gazda_transfer' => [null, false],
        // doar fără cheie EasyCheck, deci fără nimic de trimis la EasyCheck.
        'gazda_cerere' => [null, false],
        'alerta_transfer' => [null, false],
        // taxa locală de returnat din bancă, după anularea unei rezervări plătite prin transfer.
        'alerta_taxa_transfer' => [null, false],
        // despăgubirea datorată oaspetelui după anularea gazdei.
        'alerta_despagubire' => [null, false],
        // doar când EasyCheck n-a luat anunțul „Am plătit”; altfel o anunță EasyCheck.
        'alerta_transfer_anuntat' => [null, false],
        'alerta_transfer_anuntat_reamintire' => [null, false],
        // sumele știute de EasyCheck (răspunsul la `POST /rezervari/{id}/plati` sau lista rezervărilor) diferă de
        // registrul site-ului.
        'alerta_plati_diferite' => ['plati_diferite', true],
        // e-mailul de siguranță la fiecare rambursare executată la o comandă din EasyCheck; EasyCheck știe singur comanda.
        'gazda_rambursare_ec' => [null, false],
    ];

    /** Stările ținute de site în afara panoului => [codul contractului, gravă, pagina din panou]. */
    public const STARI = [
        'paritate' => ['paritate', false, '/admin/preturi'],
        // comutatorul „Primesc rambursări comandate din EasyCheck” e oprit, cu funcția aprinsă.
        'rambursari_oprite' => ['rambursari_oprite', false, '/admin/metode-plata'],
    ];

    public function __construct(private Aplicatie $app)
    {
    }

    /** Comutatorul din config.php: `stare_easycheck => false` oprește declarația și starea. */
    public function pornit(): bool
    {
        // fără cheie EasyCheck, site-ul n-are cui trimite starea.
        return $this->app->config()->get('stare_easycheck', true) !== false && !$this->app->modLocal();
    }

    /**
     * Ce are pornit EasyCheck pentru proprietatea cheii: răspunsul ultimului `PUT /site` al cheii de acum, altfel `functii`
     * din `GET /eu` (rezumatul cheii, apoi oglinda). Null: un EasyCheck mai vechi, fără aceste rute.
     */
    public function functii(): ?array
    {
        $s = $this->pentruCheie();
        if (is_array($s['functii'] ?? null)) {
            return $s['functii'];
        }
        return $this->functiiEu();
    }

    private function functiiEu(): ?array
    {
        $f = $this->app->ecLicenta()->cheie()['functii'] ?? null;
        if (!is_array($f)) {
            try {
                $f = $this->app->ecOglinda()->corp('eu')['functii'] ?? null;
            } catch (\Throwable) {
                $f = null;
            }
        }
        return is_array($f) ? $f : null;
    }

    /**
     * O funcție a etapelor „EasyCheck întâi” e pornită pentru proprietatea cheii: în răspunsul ultimului `PUT /site` sau în
     * `GET /eu` (rezumatul cheii, apoi oglinda). Oricare din ele ajunge: `GET /eu` se citește la 6 ore, declarația o dată
     * pe zi, deci o funcție aprinsă de EasyCheck se vede la prima dintre citiri. Fără `functii` (un EasyCheck mai vechi), false.
     */
    public function functie(string $nume): bool
    {
        return ((($this->functii() ?? [])[$nume] ?? null) === true) || ((($this->functiiEu() ?? [])[$nume] ?? null) === true);
    }

    /** Site-ul își trimite starea: comutatorul pornit, cheia EasyCheck valabilă, iar EasyCheck are funcția pornită. */
    public function activa(): bool
    {
        return $this->pornit() && $this->app->ecLicenta()->stare()['valida'] && $this->app->secrete()->citeste('ec_cheie') !== null
            && (($this->functii() ?? [])['stare'] ?? null) === true;
    }

    /** Declarația cheii de acum a ajuns la EasyCheck (măcar un `PUT /site` primit): programul și-a declarat capabilitățile. */
    public function declarata(): bool
    {
        return is_int($this->pentruCheie()['declarat_la'] ?? null);
    }

    /** Semnalul instalării așteaptă confirmarea EasyCheck (după încheierea instalatorului; refăcut la reinstalare). */
    public function instalareDeSemnalat(): bool
    {
        $i = $this->app->setari()->get(self::SETARE_INSTALARE);
        return is_array($i) && is_string($i['incheiata_la'] ?? null) && ($i['confirmata_la'] ?? null) === null;
    }

    /**
     * Încheierea instalatorului: semnalul pornește de la zero (o reinstalare îl cere din nou), apoi pleacă pe loc. Cu cheie de
     * probă și cu cheie live; fără cheie (modul local) sau cu comutatorul oprit, nimic nu pleacă. Întoarce true la confirmare.
     */
    public function instalareIncheiata(): bool
    {
        $this->app->setari()->pune(self::SETARE_INSTALARE, ['incheiata_la' => self::moment($this->app->ceas()->acum()), 'confirmata_la' => null]);
        return $this->semnaleazaInstalarea();
    }

    /** Trimite starea cu semnalul instalării, când așteaptă confirmarea și EasyCheck are ruta. Întoarce true la confirmare. */
    public function semnaleazaInstalarea(): bool
    {
        try {
            if (!$this->instalareDeSemnalat() || !$this->pornit() || $this->app->secrete()->citeste('ec_cheie') === null
                || !$this->app->ecLicenta()->stare()['valida'] || $this->functiiEu() === null) {
                return false;
            }
            $this->trimite(true);
            return !$this->instalareDeSemnalat();
        } catch (\Throwable $e) {
            $this->app->jurnal()->avertisment('Semnalul instalării către EasyCheck s-a oprit', ['eroare' => get_class($e)]);
            return false;
        }
    }

    /**
     * Rândul din panou „Problemele importante ale site-ului le vedeți și în EasyCheck, la Azi.”: funcția e activă, cheia e
     * live (alertele unei chei de probă stau doar pe cardul cheii) și ultima stare a ajuns de curând la EasyCheck.
     */
    public function inPanou(): bool
    {
        if (!$this->activa() || $this->app->ecLicenta()->stare()['proba']) {
            return false;
        }
        $la = $this->pentruCheie()['trimis_la'] ?? null;
        return is_int($la) && $this->app->ceas()->acum() - $la < self::PROASPAT;
    }

    /** Declarația trimisă la `PUT /site`. */
    public function declaratie(): array
    {
        // comutatorul și plafonul zilnic ale rambursărilor comandate din EasyCheck; plafonul îl
        // aplică site-ul, EasyCheck doar îl arată.
        $cmd = $this->app->ecComenzi();
        $d = ['program' => self::PROGRAM, 'versiune' => Aplicatie::versiune(), 'capabilitati' => self::CAPABILITATI, 'rambursari_permise' => $cmd->permise(),
            'rambursari_plafon' => $cmd->plafon()];
        $panou = $this->app->url()->absolut('/admin/');
        if (str_starts_with($panou, 'https://') && strlen($panou) <= 300 && !str_contains($panou, '?') && !str_contains($panou, '@')) {
            $d['panou'] = $panou;
        }
        return $d;
    }

    /**
     * `PUT /site`, acum (legarea cheii din instalator, din panou și din linia de comandă; sarcina cronului). Întoarce true
     * când declarația a ajuns. Erorile rămân aici.
     */
    public function declara(): bool
    {
        try {
            if (!$this->pornit() || !$this->app->ecLicenta()->stare()['valida'] || $this->functiiEu() === null) {
                return false;
            }
            $acum = $this->app->ceas()->acum();
            $s = $this->pentruCheie();
            $s['declarat_incercat_la'] = $acum;
            try {
                $r = $this->app->ecClient()->scrie('PUT', '/site', Client::json($this->declaratie()));
            } catch (EroareApi $e) {
                if ($e->raspuns->status === 404) {
                    // Un EasyCheck fără aceste rute: softul rămâne pe purtarea de dinainte și încearcă din nou peste o zi.
                    $s['lipsa_pana'] = $acum + self::ZILNIC;
                    $s['functii'] = null;
                    $this->tine($s);
                    return false;
                }
                $this->tine($this->noteazaEroare($s, 'PUT /site', $e));
                return false;
            } catch (EroareTransport | \LengthException $e) {
                $this->tine($this->noteazaEroare($s, 'PUT /site', $e));
                return false;
            }
            $f = $r->json()['functii'] ?? null;
            $s['functii'] = is_array($f) ? array_filter($f, 'is_bool') : null;
            $s['versiune'] = Aplicatie::versiune();
            $s['capabilitati'] = self::CAPABILITATI;
            $s['declarat_la'] = $acum;
            $s['declarat_incercat_la'] = null;
            $s['lipsa_pana'] = null;
            $this->tine($s);
            return true;
        } catch (\Throwable $e) {
            $this->app->jurnal()->avertisment('Declarația site-ului către EasyCheck s-a oprit', ['eroare' => get_class($e)]);
            return false;
        }
    }

    /**
     * Declarația e de trimis: cheie nouă, altă versiune a programului, alte capabilități (o arhivă refăcută cu aceeași versiune)
     * sau a trecut o zi; după un 404 sau o eroare, mai târziu.
     */
    private function deDeclarat(array $s, int $acum): bool
    {
        if (is_int($s['lipsa_pana'] ?? null) && $s['lipsa_pana'] > $acum) {
            return false;
        }
        if (is_int($s['declarat_incercat_la'] ?? null) && $acum - $s['declarat_incercat_la'] < self::RELUARE) {
            return false;
        }
        return !is_int($s['declarat_la'] ?? null) || ($s['versiune'] ?? null) !== Aplicatie::versiune()
            || ($s['capabilitati'] ?? null) !== self::CAPABILITATI || $acum - $s['declarat_la'] >= self::ZILNIC;
    }

    /**
     * Sarcina cronului (`ec:stare`, la fiecare rulare; prin ea trec și pseudo-cronul, și lanțul fără cron): declarația când
     * e de trimis, apoi starea când s-a schimbat lista alertelor ori au trecut 15 minute. Întoarce rezumatul pentru panou.
     */
    public function sarcina(): string
    {
        if (!$this->pornit()) {
            return 'oprită din config.php';
        }
        if ($this->app->secrete()->citeste('ec_cheie') === null || !$this->app->ecLicenta()->stare()['valida']) {
            return 'fără cheie EasyCheck valabilă';
        }
        if ($this->functiiEu() === null) {
            return 'EasyCheck fără starea site-ului';
        }
        $acum = $this->app->ceas()->acum();
        if ($this->deDeclarat($this->pentruCheie(), $acum)) {
            if (!$this->app->ecBuget()->permiteScriereNecritica()) {
                return 'amânată: bugetul scrierilor';
            }
            $this->declara();
        }
        if (!$this->activa() && !$this->instalareDeSemnalat()) {
            return 'starea site-ului e oprită în EasyCheck';
        }
        return $this->trimite();
    }

    /**
     * `PUT /site/stare`, când e scadentă. Cu semnalul instalării în așteptare, starea pleacă la fiecare rulare a cronului,
     * cel mult o dată la 5 minute, până la confirmare; $forteaza (încheierea instalatorului) o trimite pe loc.
     * Întoarce rezumatul pentru panou.
     */
    public function trimite(bool $forteaza = false): string
    {
        $acum = $this->app->ceas()->acum();
        $s = $this->pentruCheie();
        if (is_int($s['lipsa_pana'] ?? null) && $s['lipsa_pana'] > $acum) {
            return 'EasyCheck fără starea site-ului';
        }
        if (is_int($s['eroare_la'] ?? null) && is_int($s['incercat_la'] ?? null) && $acum - $s['incercat_la'] < self::RELUARE) {
            return 'așteaptă după o trimitere nereușită';
        }
        $corp = $this->stare($s);
        $amprenta = self::amprenta($corp);
        $scadenta = !is_int($s['trimis_la'] ?? null) || $amprenta !== ($s['amprenta'] ?? null) || $acum - $s['trimis_la'] >= self::RITM - self::INGADUINTA
            || (isset($corp['instalare']) && ($forteaza || $acum - (int) $s['trimis_la'] >= self::RELUARE));
        if (!$scadenta) {
            return 'la zi';
        }
        if (!$this->app->ecBuget()->permiteScriereNecritica()) {
            return 'amânată: bugetul scrierilor';
        }
        $s['incercat_la'] = $acum;
        try {
            $r = $this->app->ecClient()->scrie('PUT', '/site/stare', Client::json($corp));
        } catch (EroareApi $e) {
            if ($e->raspuns->status === 404) {
                $s['lipsa_pana'] = $acum + self::ZILNIC;
                $s['functii'] = null;
                $s['declarat_la'] = null;
            }
            $this->tine($this->noteazaEroare($s, 'PUT /site/stare', $e));
            return 'trimiterea a eșuat';
        } catch (EroareTransport | \LengthException $e) {
            $this->tine($this->noteazaEroare($s, 'PUT /site/stare', $e));
            return 'trimiterea a eșuat';
        }
        $s['trimis_la'] = $acum;
        $s['amprenta'] = $amprenta;
        $s['eroare_la'] = null;
        $this->tine($s);
        // plasa de siguranță a anunțului `comanda.noua`; comenzile se citesc din cron.
        $asteapta = ($r->json() ?? [])['comenzi_in_asteptare'] ?? null;
        if (is_int($asteapta) && $asteapta > 0) {
            $this->app->ecComenzi()->deCitit();
        }
        // plasa de siguranță a mesajelor; o versiune a șabloanelor diferită de oglindă cere o citire.
        try {
            $this->app->mesajeEc()->versiuneStare(($r->json() ?? [])['mesaje_versiune'] ?? null);
        } catch (\Throwable) {
        }
        // Semnalul instalării: confirmat când răspunsul are `instalare_confirmata` = true (contractul, „Ce s-a construit”);
        // altfel pleacă din nou la rularea următoare a cronului.
        if (isset($corp['instalare']) && (($r->json() ?? [])['instalare_confirmata'] ?? null) === true) {
            $i = (array) $this->app->setari()->get(self::SETARE_INSTALARE);
            $this->app->setari()->pune(self::SETARE_INSTALARE, ['confirmata_la' => self::moment($acum)] + $i);
            return 'trimisă, alerte: ' . count($corp['alerte']) . ', instalarea confirmată';
        }
        return 'trimisă, alerte: ' . count($corp['alerte']);
    }

    /**
     * Starea întreagă de acum, în forma contractului. `$s` e starea ținută (momentul de la care durează fiecare
     * alertă), adusă la zi aici.
     */
    public function stare(?array &$s = null): array
    {
        $s ??= $this->pentruCheie();
        $acum = $this->app->ceas()->acum();
        $corp = ['la' => self::moment($acum)];
        $cron = $this->app->setari()->get('cron.ultima');
        $ultima = is_array($cron) && is_string($cron['la'] ?? null) ? $cron['la'] : $this->app->baza()->valoare('SELECT MAX(ultima_la) FROM {sarcini}');
        $corp['cron'] = ['ultima_rulare' => is_string($ultima) && $ultima !== '' ? self::moment(Ceas::dinMoment($ultima)) : null];
        if (is_array($cron) && is_string($cron['sursa'] ?? null) && preg_match('~^[a-z0-9_]{1,20}$~', $cron['sursa'])) {
            $corp['cron']['sursa'] = $cron['sursa'];
        }
        if ($this->app->modPlata()->vreaCard()) {
            $online = (array) ($this->app->setari()->get('plati.online') ?? []);
            $corp['plata_online'] = ['activa' => ($online['oprita'] ?? false) !== true, 'motive' => self::coduri((array) ($online['motive'] ?? []))];
        }
        $em = AdminPanou::emailuriCuProbleme($this->app);
        $corp['email'] = ['in_coada' => (int) $this->app->baza()->valoare("SELECT COUNT(*) FROM {emailuri} WHERE stare = 'in_coada'"), 'cu_probleme' => $em['reincercate'] + $em['esuate']];
        $corp['alerte'] = $this->alerte($s);
        if ($this->instalareDeSemnalat()) {
            $corp['instalare'] = self::INSTALARE_INCHEIATA;
            // `adresa` e opțională în contract și trebuie să fie `https://...` (cel mult 300 de caractere): un site fără HTTPS
            // n-o trimite, altfel 422 ar opri toată starea.
            $adresa = $this->app->config()->origine();
            if (str_starts_with($adresa, 'https://') && strlen($adresa) <= 300) {
                $corp['adresa'] = $adresa;
            }
            $corp['versiune'] = Aplicatie::versiune();
        }
        return $corp;
    }

    /**
     * Alertele de acum, pe codurile contractului. O alertă despre mai multe rezervări pleacă o dată pe rezervare (identitatea
     * ei în EasyCheck e codul și rezervările), ca rezolvarea uneia să le lase pe celelalte neatinse.
     * @return list<array{cod:string, grav:bool, de_la:string, rezervari?:list<string>, detalii?:array}>
     */
    public function alerte(?array &$s = null): array
    {
        $s ??= $this->pentruCheie();
        $acum = $this->app->ceas()->acum();
        $lista = [];
        $adauga = static function (string $cod, bool $grav, ?string $rez, array $detalii, ?int $deLa) use (&$lista): void {
            $id = $cod . '|' . ($rez ?? '');
            if (isset($lista[$id])) {
                // Aceeași alertă din două surse: rămâne o dată, cu momentul cel mai vechi.
                if ($deLa !== null && ($lista[$id]['de_la'] === null || $deLa < $lista[$id]['de_la'])) {
                    $lista[$id]['de_la'] = $deLa;
                }
                return;
            }
            $lista[$id] = ['cod' => $cod, 'grav' => $grav, 'de_la' => $deLa, 'rez' => $rez, 'detalii' => $detalii];
        };
        $t = $this->app->traducator('ro', 'admin');
        foreach (AdminPanou::alerte($this->app, $t) as $a) {
            $cheie = (string) $a['cheie'];
            // O alertă nouă a panoului, încă fără rând în tabel, pleacă pe cheia ei (EasyCheck îi dă textul general).
            [$cod, $grav] = self::PANOU[$cheie] ?? [preg_match('~^[a-z0-9_]{1,60}$~', $cheie) ? $cheie : null, (bool) $a['grav']];
            if ($cod === null) {
                continue;
            }
            $detalii = self::cuPagina([], is_string($a['url'] ?? null) ? $a['url'] : null);
            if ($cheie === 'plata_oprita') {
                $motive = self::coduri((array) (($this->app->setari()->get('plati.online') ?? [])['motive'] ?? []));
                if ($motive !== []) {
                    $detalii['motive'] = $motive;
                }
            }
            if ($cheie === 'emailuri') {
                $em = AdminPanou::emailuriCuProbleme($this->app);
                $detalii['numar'] = max($em['reincercate'], $em['esuate']);
            }
            if ($a['refs'] === []) {
                $adauga($cod, $grav, null, $detalii, null);
                continue;
            }
            $fara = 0;
            foreach ($a['refs'] as $ref) {
                $rez = $this->rezervare((string) $ref);
                if ($rez === null) {
                    $fara++;
                } else {
                    $adauga($cod, $grav, $rez, $detalii, null);
                }
            }
            if ($fara > 0) {
                // Comenzile care nu au (încă) o rezervare în EasyCheck: o singură alertă, cu numărul lor.
                $adauga($cod, $grav, null, $detalii + ['numar' => $fara], null);
            }
        }
        // un șablon scris în EasyCheck respins de gărzile site-ului (sau un paragraf respins la import): pleacă textul
        // standard, iar gazda vede alerta ușoară `mesaj_invalid`, cu gărzile în `detalii.motive`.
        $motive = $this->app->mesajeEc()->motiveInvalide();
        if ($motive !== []) {
            $adauga('mesaj_invalid', false, null, self::cuPagina(['motive' => self::coduri($motive)], $this->app->url()->cale('/admin/emailuri/sabloane')), null);
        }
        foreach ($this->stari() as $cheie) {
            [$cod, $grav, $pagina] = self::STARI[$cheie];
            $adauga($cod, $grav, null, self::cuPagina([], $this->app->url()->cale($pagina)), null);
        }
        foreach ($this->app->baza()->toate('SELECT la, sablon, date FROM {alerte} WHERE la > ? ORDER BY id DESC LIMIT 200', [$this->app->ceas()->moment(-self::JURNAL_ALERTE)]) as $x) {
            [$cod, $grav] = self::SABLOANE[(string) $x['sablon']] ?? [null, false];
            if ($cod === null) {
                continue;
            }
            $date = json_decode((string) ($x['date'] ?? ''), true);
            $date = is_array($date) ? $date : [];
            $detalii = self::cuPagina([], $this->app->url()->cale('/admin/alerte'));
            if (is_int($date['suma_bani'] ?? null) && $date['suma_bani'] > 0) {
                $detalii['suma'] = $date['suma_bani'];
            }
            $adauga($cod, $grav, is_string($date['ref'] ?? null) ? $this->rezervare($date['ref']) : null, $detalii, Ceas::dinMoment((string) $x['la']));
        }
        // De când durează fiecare alertă: jurnalul are momentul lui; celelalte îl primesc la prima apariție și îl păstrează.
        $tinute = is_array($s['de_la'] ?? null) ? $s['de_la'] : [];
        $noi = [];
        foreach ($lista as $id => &$a) {
            $a['de_la'] ??= is_int($tinute[$id] ?? null) ? $tinute[$id] : $acum;
            $noi[$id] = $a['de_la'];
        }
        unset($a);
        $s['de_la'] = $noi;
        // Cel mult 50 (contractul): întâi cele grave, apoi cele mai vechi.
        $lista = array_values($lista);
        usort($lista, static fn (array $a, array $b): int => [$b['grav'], $a['de_la'], $a['cod'], (string) $a['rez']] <=> [$a['grav'], $b['de_la'], $b['cod'], (string) $b['rez']]);
        $rez = [];
        foreach (array_slice($lista, 0, self::ALERTE_MAX) as $a) {
            $r = ['cod' => $a['cod'], 'grav' => $a['grav'], 'de_la' => self::moment(min($a['de_la'], $acum))];
            if ($a['rez'] !== null) {
                $r['rezervari'] = [$a['rez']];
            }
            if ($a['detalii'] !== []) {
                $r['detalii'] = $a['detalii'];
            }
            $rez[] = $r;
        }
        return $rez;
    }

    /** Stările ținute de site în afara panoului, active acum. @return list<string> cheile din `STARI` */
    public function stari(): array
    {
        $r = [];
        if (\Cazo\Pret\Paritate::diferentaDin($this->app->setari())) {
            $r[] = 'paritate';
        }
        if ($this->app->modEc()->comenziAprinse() && !$this->app->ecComenzi()->permise()) {
            $r[] = 'rambursari_oprite';
        }
        return $r;
    }

    /** Id-ul EasyCheck al rezervării unei comenzi (`rez_` + 24 de caractere), sau null. */
    private function rezervare(string $ref): ?string
    {
        if ($ref === '' || $ref === '-') {
            return null;
        }
        $id = $this->app->baza()->valoare('SELECT ec_rezervare FROM {comenzi} WHERE ref = ?', [$ref]);
        return is_string($id) && preg_match('~^rez_[0-9A-Za-z]{24}$~D', $id) ? $id : null;
    }

    /** Calea din panou a alertei (`detalii.pagina`), doar în forma primită de contract (fără parametri, fără `..`). */
    private static function cuPagina(array $detalii, ?string $cale): array
    {
        if ($cale !== null && preg_match('~^/[A-Za-z0-9/_.-]{0,199}$~D', $cale) && !str_contains($cale, '..')) {
            $detalii['pagina'] = $cale;
        }
        return $detalii;
    }

    /** @return list<string> cel mult 10 coduri în forma contractului */
    private static function coduri(array $v): array
    {
        return array_slice(array_values(array_filter($v, static fn (mixed $x): bool => is_string($x) && (bool) preg_match('~^[a-z0-9_]{1,60}$~D', $x))), 0, 10);
    }

    /** Amprenta listei de alerte (fără momente): starea pleacă din nou când ea se schimbă. */
    private static function amprenta(array $corp): string
    {
        $a = array_map(static function (array $x): array {
            unset($x['de_la']);
            return $x;
        }, $corp['alerte']);
        return hash('sha256', json_encode([$a, $corp['plata_online']['activa'] ?? null], JSON_THROW_ON_ERROR));
    }

    /** Momentul în forma API-ului: UTC cu `Z`. */
    public static function moment(int $t): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    private function tinuta(): array
    {
        $s = $this->app->setari()->get(self::SETARE);
        return is_array($s) ? $s : [];
    }

    /** Starea ținută a cheii de acum; o cheie nouă pornește de la zero (declarația pleacă din nou). */
    private function pentruCheie(): array
    {
        $prefix = Conectare::prefix($this->app->secrete()->citeste('ec_cheie'));
        $s = $this->tinuta();
        return ($s['cheie'] ?? null) === $prefix ? $s : ['cheie' => $prefix];
    }

    private function tine(array $s): void
    {
        $this->app->setari()->pune(self::SETARE, $s);
    }

    /** O trimitere nereușită: cel mult o linie pe oră în jurnalul site-ului, fără cheie și fără corpul cererii. */
    private function noteazaEroare(array $s, string $cerere, \Throwable $e): array
    {
        $acum = $this->app->ceas()->acum();
        $s['eroare_la'] ??= $acum;
        if (!is_int($s['jurnal_la'] ?? null) || $acum - $s['jurnal_la'] >= self::JURNAL) {
            $s['jurnal_la'] = $acum;
            $this->app->jurnal()->avertisment('Starea site-ului nu a ajuns la EasyCheck; site-ul încearcă din nou singur', ['cerere' => $cerere,
                'eroare' => $e instanceof EroareApi ? $e->raspuns->status . ' ' . ($e->cod ?? '') : ($e instanceof EroareTransport ? $e->felul : get_class($e))]);
        }
        return $s;
    }
}
