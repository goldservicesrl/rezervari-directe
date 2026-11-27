<?php

declare(strict_types=1);

namespace Cazo\Controlere;

use Cazo\DateExterne\CursBnr;
use Cazo\DateExterne\Repere;
use Cazo\DateExterne\Vreme;
use Cazo\I18n\Format;
use Cazo\I18n\Traducator;
use Cazo\Nucleu\Aplicatie;
use Cazo\Nucleu\Cerere;
use Cazo\Nucleu\Raspuns;
use Cazo\Pret\EroareReducere;
use Cazo\Pret\ReduceriLocale;

defined('CAZO') or exit;

/**
 * Prețurile de pe site și împrejurimile, în două pagini din meniu, doar pentru proprietar:
 *
 *   /admin/preturi      „Prețuri pe site” (grupul Prețuri și plăți): reducerile locale la „Prețul de pe site-ul meu” (
 *                       alegerea gazdei pentru suprapuneri, cerută când se adaugă sau se pornește a doua reducere),
 *                       ce plătește oaspetele pe site și paritatea nocturnă la „Prețul din EasyCheck” (
 *                       verificarea iese la vedere doar când găsește diferențe), echivalentul în euro
 *                       și durata maximă a sejurului. Vremea (MET Norway, fără cheie) s-a mutat pe pagina
 *                       Aspect: cardul ei ia datele din `vreme()`, iar bifa se salvează tot aici (`actiune=vreme`).
 *   /admin/imprejurimi  „Împrejurimi” (grupul Site): punctele de reper: reperele de acum, căutarea Nominatim,
 *                       reperele scrise de mână, reperul principal, minutele corectate și, sub
 *                       „Avansat”, numele serviciului de hărți.
 *
 * La o greșeală, pagina se întoarce cu 422, cu valorile scrise de gazdă și cu eroarea lângă câmp. Cererile spre serviciile
 * externe pleacă doar la apăsarea gazdei, cu limitele din Locuri. Profilul de antete „admin”, CSRF pe fiecare POST (routerul).
 */
final class AdminPret
{
    public const MESAJE = ['salvat' => 'admin.pret.mesaj.salvat', 'titluri_planuri' => 'admin.pret.mesaj.titluri_planuri', 'reducere' => 'admin.pret.mesaj.reducere', 'pornita' => 'admin.pret.mesaj.pornita', 'oprita' => 'admin.pret.mesaj.oprita',
        'sters' => 'admin.pret.mesaj.sters', 'mod_salvat' => 'admin.pret.mesaj.mod_salvat', 'euro_pornit' => 'admin.pret.mesaj.euro_pornit', 'euro_oprit' => 'admin.pret.mesaj.euro_oprit',
        'include' => 'admin.pret.mesaj.include', 'nopti_max' => 'admin.pret.mesaj.nopti_max'];
    public const MESAJE_REPERE = ['salvat' => 'admin.repere.mesaj.salvat', 'adaugat' => 'admin.repere.mesaj.adaugat', 'sters' => 'admin.repere.mesaj.sters',
        'principal' => 'admin.repere.mesaj.principal', 'minute' => 'admin.repere.mesaj.minute'];
    public const ERORI_REDUCERE = ['nopti_max', 'titlu', 'fel', 'valoare', 'perioada', 'zile_inainte', 'nopti_min', 'planuri', 'camere', 'lipsa', 'maxim', 'mod', 'mod_lipsa'];
    public const ERORI_REPERE = ['maxim', 'nume', 'categorie', 'distanta', 'minute', 'duplicat', 'limita', 'eroare', 'coordonate'];

    // ---- prețul ----

    public static function preturi(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        return self::paginaPreturi($app, $c, $s[0], $s[1], null, null);
    }

    public static function preturiPost(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $red = $app->reduceriLocale();
        $id = preg_match('~^\d{1,9}$~', (string) ($c->camp('id') ?? '')) ? (int) $c->camp('id') : null;
        $mod = is_string($c->camp('mod')) ? (string) $c->camp('mod') : null;
        $uid = (int) $u['id'];
        $inapoi = static fn (string $mesaj): Raspuns => AdminPanou::inapoi($app, '/admin/preturi', ['mesaj' => $mesaj]);
        try {
            switch ((string) $c->camp('actiune')) {
                case 'reducere':
                    $red->salveaza(self::formularReducere($c), $id, $mod, $uid);
                    self::dupaReduceri($app);
                    return $inapoi('reducere');
                case 'comuta':
                    $red->comuta((int) $id, $c->camp('activa') === '1', $mod, $uid);
                    self::dupaReduceri($app);
                    return $inapoi($c->camp('activa') === '1' ? 'pornita' : 'oprita');
                case 'sterge':
                    $red->sterge((int) $id, $uid);
                    self::dupaReduceri($app);
                    return $inapoi('sters');
                case 'mod':
                    $red->seteazaMod((string) $mod, $uid);
                    self::dupaReduceri($app);
                    return $inapoi('mod_salvat');
                case 'euro':
                    $app->setari()->pune('euro.pornit', $c->camp('pornit') === '1', $uid);
                    $app->ecOglinda()->schimbaContinutul();
                    if ($c->camp('pornit') === '1') {
                        // Prima citire a cursului pleacă imediat după răspuns, ca echivalentul să apară fără așteptare.
                        $app->dupaRaspuns(static function () use ($app): void {
                            if ($app->cursBnr()->citeste()) {
                                $app->ecOglinda()->schimbaContinutul();
                            }
                        });
                    }
                    return $inapoi($c->camp('pornit') === '1' ? 'euro_pornit' : 'euro_oprit');
                case 'vreme':
                    // Cardul „Vremea pe site” stă pe pagina Aspect; bifa se salvează aici și întoarce gazda acolo.
                    $app->setari()->pune('vreme.afisata', $c->camp('afisata') === '1', $uid);
                    $app->ecOglinda()->schimbaContinutul();
                    return AdminPanou::inapoi($app, '/admin/aspect', ['mesaj' => $c->camp('afisata') === '1' ? 'vreme_pornita' : 'vreme_oprita']);
                case 'include':
                    // la „Prețul din EasyCheck”, ce intră în totalul plătit pe site pe lângă nopți; site-ul o declară
                    // lui EasyCheck la fiecare cerere cu total.
                    if (($app->ecLicenta()->cheie()['sursa'] ?? null) === 'easycheck') {
                        $app->alegeCePlateste($c->camp('include_taxe') === '1', (string) ($c->camp('taxa_locala') ?? ''), $uid);
                    }
                    return $inapoi('include');
                case 'titluri_planuri':
                    // Numele tarifelor pe site, pe limbi (`planuri_local.titlu_local`): au întâietate față de numele
                    // automat; lăsat gol, tariful își ia numele automat.
                    $trimise = is_array($c->post['titlu_plan'] ?? null) ? $c->post['titlu_plan'] : [];
                    foreach (array_keys($app->pretNoapte()->planuri()) as $plan) {
                        $titluri = [];
                        foreach (['ro', 'en'] as $lb) {
                            $v = trim((string) (is_array($trimise[$plan] ?? null) ? ($trimise[$plan][$lb] ?? '') : ''));
                            if ($v !== '') {
                                $titluri[$lb] = mb_substr($v, 0, 80);
                            }
                        }
                        $json = $titluri === [] ? null : json_encode($titluri, JSON_UNESCAPED_UNICODE);
                        if ($app->baza()->valoare('SELECT 1 FROM {planuri_local} WHERE plan = ?', [(string) $plan]) !== null) {
                            $app->baza()->executa('UPDATE {planuri_local} SET titlu_local = ? WHERE plan = ?', [$json, (string) $plan]);
                        } elseif ($json !== null) {
                            $app->baza()->insereaza('planuri_local', ['plan' => (string) $plan, 'vizibilitate' => 'vizibil', 'titlu_local' => $json, 'ordine' => 0]);
                        }
                    }
                    $app->ecOglinda()->schimbaContinutul();
                    return $inapoi('titluri_planuri');
                case 'nopti_max':
                    // durata maximă a sejurului pe site, cât vrea gazda (de la o noapte); gol = fără limită.
                    $n = trim((string) ($c->camp('nopti_max') ?? ''));
                    if ($n !== '' && (!preg_match('~^\d{1,4}$~', $n) || (int) $n < 1)) {
                        return self::paginaPreturi($app, $c, $u, $jeton, 'nopti_max', null, 422, $n);
                    }
                    $app->setari()->pune('sejur.nopti_max', $n === '' ? 0 : (int) $n, $uid);
                    $app->ecOglinda()->schimbaContinutul();
                    return $inapoi('nopti_max');
            }
        } catch (EroareReducere $e) {
            // Formularul revine completat, cu eroarea lângă câmp; la `mod_lipsa`, cu alegerea gazdei cerută lângă butonul de salvare.
            return self::paginaPreturi($app, $c, $u, $jeton, $e->cod, $c->camp('actiune') === 'reducere' ? ['id' => $id] + self::formularReducere($c) : null, 422);
        }
        return $inapoi('salvat');
    }

    /** Reducerile s-au schimbat: calendarul și „de la” din paginile ținute minte se refac. */
    private static function dupaReduceri(Aplicatie $app): void
    {
        $app->ecOglinda()->schimbaContinutul();
    }

    private static function formularReducere(Cerere $c): array
    {
        $lista = static function (mixed $x): array {
            return is_array($x) ? array_values(array_filter($x, 'is_string')) : [];
        };
        return [
            'titlu' => (string) ($c->camp('titlu') ?? ''), 'fel' => (string) ($c->camp('fel') ?? ''), 'valoare' => (string) ($c->camp('valoare') ?? ''),
            'sejur_de' => (string) ($c->camp('sejur_de') ?? ''), 'sejur_pana' => (string) ($c->camp('sejur_pana') ?? ''),
            'zile_inainte_min' => (string) ($c->camp('zile_inainte_min') ?? ''), 'zile_inainte_max' => (string) ($c->camp('zile_inainte_max') ?? ''),
            'nopti_min' => (string) ($c->camp('nopti_min') ?? ''), 'planuri' => $lista($c->post['planuri'] ?? null), 'camere' => $lista($c->post['camere'] ?? null),
            'activa' => $c->camp('activa') === '1',
        ];
    }

    /**
     * @param ?string $eroare codul erorii (ERORI_REDUCERE), arătată sus și lângă câmpul ei
     * @param ?array $formular reducerea scrisă de gazdă, pentru formularul întors după o greșeală
     * @param ?string $noptiScrise numărul de nopți scris greșit, păstrat în câmp
     */
    private static function paginaPreturi(Aplicatie $app, Cerere $c, array $u, string $jeton, ?string $eroare, ?array $formular, int $status = 200, ?string $noptiScrise = null): Raspuns
    {
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $red = $app->reduceriLocale();
        $sursa = $app->ecLicenta()->cheie()['sursa'] ?? null;
        $planuri = [];
        foreach ($app->pretNoapte()->planuri() as $id => $pl) {
            $planuri[] = ['id' => $id, 'titlu' => (string) $pl['titlu']];
        }
        $camere = [];
        foreach ((array) ($app->ecOglinda()->corp('camere')['camere'] ?? []) as $cam) {
            if (is_array($cam) && is_string($cam['id'] ?? null)) {
                $camere[] = ['id' => $cam['id'], 'titlu' => (string) ($cam['titlu'] ?? $t->t('admin.pret.camera_fara_nume'))];
            }
        }
        $titluri = array_column($planuri, 'titlu', 'id') + array_column($camere, 'titlu', 'id');
        $lista = [];
        foreach ($red->toate() as $r) {
            $lista[] = ['id' => $r['id'], 'titlu' => $r['titlu'], 'activa' => $r['activa'], 'valoare' => self::valoare($r, $l), 'conditii' => self::conditii($r, $t, $l, $titluri)];
        }
        $editare = null;
        if ($formular !== null) {
            $editare = $formular;
        } elseif (preg_match('~^\d{1,9}$~', (string) ($c->query['reducere'] ?? ''))) {
            $r = $red->una((int) $c->query['reducere']);
            if ($r !== null) {
                $editare = ['id' => $r['id'], 'titlu' => $r['titlu'], 'fel' => $r['fel'], 'valoare' => self::numarScris($r['valoare'], $l),
                    'sejur_de' => (string) $r['sejur_de'], 'sejur_pana' => (string) $r['sejur_pana'], 'zile_inainte_min' => (string) ($r['zile_inainte_min'] ?? ''),
                    'zile_inainte_max' => (string) ($r['zile_inainte_max'] ?? ''), 'nopti_min' => (string) ($r['nopti_min'] ?? ''),
                    'planuri' => $r['planuri'] ?? [], 'camere' => $r['camere'] ?? [], 'activa' => $r['activa']];
            }
        }
        $editare ??= ['id' => null, 'titlu' => '', 'fel' => 'procent_sutimi', 'valoare' => '', 'sejur_de' => '', 'sejur_pana' => '', 'zile_inainte_min' => '',
            'zile_inainte_max' => '', 'nopti_min' => '', 'planuri' => [], 'camere' => [], 'activa' => true];
        $par = $app->paritate()->stare();
        $diferente = [];
        foreach ((array) ($par['diferente'] ?? []) as $d) {
            $diferente[] = $t->t('admin.pret.paritate_rand', ['perioada' => Format::zi($d['sosire'], $l) . ' - ' . Format::zi($d['plecare'], $l),
                'camera' => (string) ($titluri[$d['camera']] ?? $t->t('admin.pret.camera_fara_nume')), 'local' => Format::bani((int) $d['local'], $l), 'easycheck' => Format::bani((int) $d['easycheck'], $l)]);
        }
        $curs = $app->dateExterne()->citeste(CursBnr::NUME);
        $cursAfisat = $app->cursBnr()->curs($app->azi());
        $r = Raspuns::html('', $status);
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'preturi', self::MESAJE);
        $cod = $eroare !== null ? (in_array($eroare, self::ERORI_REDUCERE, true) ? $eroare : 'valoare') : null;
        if ($cod !== null) {
            $d['eroare'] = $t->t('admin.pret.eroare.' . $cod);
        }
        $d += [
            'url' => ['preturi' => $app->url()->cale('/admin/preturi'), 'metode' => $app->url()->cale('/admin/metode-plata'), 'conexiuni' => $app->url()->cale('/admin/conexiuni')],
            // Câmpul lângă care apare eroarea: cel al reducerii scrise (formularul întors) sau numărul maxim de nopți.
            'eroare_camp' => $cod !== null && ($formular !== null || $cod === 'nopti_max') ? ($cod === 'mod_lipsa' ? 'mod' : $cod) : null,
            'sursa' => $sursa,
            // fără cheie EasyCheck, prețul e doar cel pe noapte și cel de weekend, fără reduceri locale.
            'local' => $app->modLocal(),
            'reduceri' => $lista,
            'mod' => $red->mod(),
            'fara_mod' => $red->lipsaMod() ? count($red->active()) : 0,
            'cere_mod' => $red->cereMod($editare['id'] ?? null, (bool) ($editare['activa'] ?? true)),
            'cere_mod_pornire' => $red->mod() === null && count(array_filter($lista, static fn (array $x): bool => $x['activa'])) >= 1,
            'formular' => $editare,
            'planuri' => $planuri,
            'titluri_planuri' => self::titluriPlanuri($app, $planuri, $l),
            'camere' => $camere,
            'maxim' => count($lista) >= ReduceriLocale::MAXIM,
            'paritate' => ['la' => isset($par['la']) ? Format::moment((string) $par['la'], $l) : null, 'verificate' => (int) ($par['verificate'] ?? 0), 'diferente' => $diferente],
            'euro' => [
                'pornit' => $app->cursBnr()->pornit(),
                'curs' => $cursAfisat !== null ? $t->t('admin.pret.euro_curs', ['data' => Format::zi($cursAfisat['data'], $l), 'curs' => str_replace('.', $l === 'ro' ? ',' : '.', $cursAfisat['eur'])]) : null,
                'vechi' => $cursAfisat === null && isset($curs['corp']['data']),
                'eroare' => $curs['ultima_eroare'] ?? null,
                'exemplu' => $cursAfisat !== null ? $t->t('admin.pret.euro_exemplu', ['lei' => Format::bani(100000, $l), 'euro' => (string) \Cazo\Pret\EchivalentEuro::euro(100000, $cursAfisat['eur'])]) : null,
            ],
            'nopti_max' => $noptiScrise ?? ($app->noptiMaxFaraLimita() ? '' : $app->noptiMaxSite()),
            'inclus' => $app->includeTotal() + ['taxa' => $app->taxaLocalaAleasa(), 'card' => $app->modPlata()->incaseazaInainte()],
        ];
        return AdminPanou::pagina($app, $u, 'pret', $d, $r);
    }

    /**
     * Cardul „Vremea pe site” de pe pagina Aspect: dacă tema de acum arată vremea, bifa gazdei, ultima citire și
     * dacă proprietatea are coordonate. Bifa se salvează prin POST /admin/preturi, `actiune=vreme`.
     * @return array{afisata:bool, tema:bool, citita:?string, eroare:bool, coordonate:bool, url:string}
     */
    public static function vreme(Aplicatie $app, string $l): array
    {
        $vreme = $app->dateExterne()->citeste(Vreme::NUME);
        return [
            'afisata' => (bool) $app->setari()->get('vreme.afisata', true),
            'tema' => $app->temaFolosesteVremea(),
            'citita' => isset($vreme['citit_la']) ? Format::moment((string) $vreme['citit_la'], $l) : null,
            'eroare' => ($vreme['ultima_eroare'] ?? null) !== null,
            'coordonate' => $app->coordonate() !== null,
            'url' => $app->url()->cale('/admin/preturi'),
        ];
    }

    /** Mesajele cardului „Vremea pe site”, arătate pe pagina Aspect după salvare. */
    /**
     * Numele tarifelor pe site, pentru formularul din „Prețuri pe site”: titlul din EasyCheck, numele automat de pe site
     * (ca exemplu în câmp) și numele scrise de gazdă pe limbi.
     * @param list<array{id:string|int, titlu:string}> $planuri
     * @return list<array{id:string, titlu:string, auto:array{ro:string, en:string}, local:array{ro:string, en:string}}>
     */
    private static function titluriPlanuri(Aplicatie $app, array $planuri, string $l): array
    {
        $r = [];
        $toate = $app->pretNoapte()->planuri();
        foreach ($planuri as $pl) {
            $id = (string) $pl['id'];
            $local = $app->baza()->valoare('SELECT titlu_local FROM {planuri_local} WHERE plan = ?', [$id]);
            $local = is_string($local) ? (array) json_decode($local, true) : [];
            $auto = [];
            foreach (['ro', 'en'] as $lb) {
                try {
                    // Numele automat: cel pe care l-ar vedea oaspetele fără un titlu scris de gazdă.
                    $fara = ['id' => '__fara__'] + (array) ($toate[$pl['id']] ?? []);
                    $auto[$lb] = (string) $pl['id'] === \Cazo\Pret\Oferte::STANDARD ? $app->traducator($lb)->t('tarif.standard')
                        : (string) (\Cazo\Teme\Componente::numePlan($fara, $lb, $app)['titlu'] ?? '');
                } catch (\Throwable) {
                    $auto[$lb] = '';
                }
            }
            $r[] = ['id' => $id, 'titlu' => (string) $pl['titlu'], 'auto' => $auto, 'local' => ['ro' => (string) ($local['ro'] ?? ''), 'en' => (string) ($local['en'] ?? '')]];
        }
        return $r;
    }

    public const MESAJE_VREME =
['vreme_pornita' => 'admin.pret.vreme_pornita', 'vreme_oprita' => 'admin.pret.vreme_oprita'];

    /** „10%” sau „50,00 lei pe noapte”. */
    private static function valoare(array $r, string $l): string
    {
        return $r['fel'] === 'procent_sutimi' ? self::numarScris($r['valoare'], $l) . '%' : Format::bani($r['valoare'], $l);
    }

    /** O valoare în sutimi, scrisă cum o tastează gazda: „10”, „12,5”. */
    private static function numarScris(int $sutimi, string $l): string
    {
        $s = rtrim(rtrim(sprintf('%d.%02d', intdiv($sutimi, 100), $sutimi % 100), '0'), '.');
        return $l === 'ro' ? str_replace('.', ',', $s) : $s;
    }

    /** Condițiile reducerii, pe scurt, pentru lista din panou. */
    private static function conditii(array $r, Traducator $t, string $l, array $titluri): string
    {
        $fara = $t->t('admin.pret.camera_fara_nume');
        $p = [];
        if ($r['sejur_de'] !== null || $r['sejur_pana'] !== null) {
            $p[] = $t->t('admin.pret.cond.perioada', ['de' => $r['sejur_de'] !== null ? Format::zi($r['sejur_de'], $l) : '…', 'pana' => $r['sejur_pana'] !== null ? Format::zi($r['sejur_pana'], $l) : '…']);
        }
        if ($r['zile_inainte_min'] !== null) {
            $p[] = $t->t('admin.pret.cond.timpurie', ['n' => (string) $r['zile_inainte_min']]);
        }
        if ($r['zile_inainte_max'] !== null) {
            $p[] = $t->t('admin.pret.cond.ultimul_moment', ['n' => (string) $r['zile_inainte_max']]);
        }
        if ($r['nopti_min'] !== null) {
            $p[] = $t->t('admin.pret.cond.nopti', ['n' => (string) $r['nopti_min']]);
        }
        if ($r['planuri'] !== null) {
            $p[] = $t->t('admin.pret.cond.planuri', ['lista' => implode(', ', array_map(static fn (string $x): string => (string) ($titluri[$x] ?? $fara), $r['planuri']))]);
        }
        if ($r['camere'] !== null) {
            $p[] = $t->t('admin.pret.cond.camere', ['lista' => implode(', ', array_map(static fn (string $x): string => (string) ($titluri[$x] ?? $fara), $r['camere']))]);
        }
        return $p === [] ? $t->t('admin.pret.cond.toate') : implode('; ', $p);
    }

    // ---- împrejurimile ----

    public static function imprejurimi(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        return self::paginaImprejurimi($app, $c, $s[0], $s[1], [], null);
    }

    public static function imprejurimiPost(Aplicatie $app, Cerere $c, array $p, string $limba): Raspuns
    {
        $s = AdminPanou::sesiune($app, $c, 'proprietar');
        if ($s === null) {
            return AdminPanou::inapoi($app);
        }
        [$u, $jeton] = $s;
        $rep = $app->repere();
        $coord = $app->coordonate();
        $inapoi = static fn (string $mesaj): Raspuns => AdminPanou::inapoi($app, '/admin/imprejurimi', ['mesaj' => $mesaj]);
        $id = preg_match('~^\d{1,9}$~', (string) ($c->camp('id') ?? '')) ? (int) $c->camp('id') : 0;
        // Ce a scris gazda, pentru formularul întors după o greșeală: reperul de mână sau minutele corectate ale unui reper.
        $scris = [];
        try {
            switch ((string) $c->camp('actiune')) {
                case 'cauta':
                    $r = $app->locuri()->cauta((string) ($c->camp('text') ?? ''), $coord['lat'] ?? null, $coord['lng'] ?? null, (string) $u['limba_panou']);
                    if ($r['stare'] !== 'ok') {
                        return self::paginaImprejurimi($app, $c, $u, $jeton, ['text' => (string) $c->camp('text')], $r['stare'], 503);
                    }
                    return self::paginaImprejurimi($app, $c, $u, $jeton, ['cautare' => $r['rezultate'], 'text' => (string) $c->camp('text')], null);
                case 'propune':
                    // Sugestiile din jurul proprietății au ieșit din panou; un formular vechi duce înapoi la pagină.
                    return $inapoi('salvat');
                case 'adauga_osm':
                    $alese = $c->post['loc'] ?? [];
                    $categorieAleasa = null;
                    if (is_string($c->post['ales'] ?? null) && is_array($c->post['optiune'] ?? null)) {
                        // Rezultatul ales din căutare, cu categoria aleasă de gazdă.
                        $alese = [$c->post['optiune'][$c->post['ales']] ?? null];
                        $categorieAleasa = (string) ($c->camp('categorie') ?? '');
                    }
                    $n = 0;
                    foreach (is_array($alese) ? array_slice($alese, 0, Repere::MAXIM) : [] as $v) {
                        $x = is_string($v) ? json_decode($v, true) : null;
                        if (!is_array($x)) {
                            continue;
                        }
                        $rep->adauga(['nume' => (string) ($x['nume'] ?? ''), 'categorie' => $categorieAleasa ?? (string) ($x['categorie'] ?? ''), 'lat' => $x['lat'] ?? null, 'lng' => $x['lng'] ?? null,
                            'osm' => (string) ($x['osm'] ?? '')], $coord);
                        $n++;
                    }
                    $app->ecOglinda()->schimbaContinutul();
                    return $inapoi($n > 0 ? 'adaugat' : 'salvat');
                case 'adauga':
                    // Distanța: un număr și unitatea lui („minute pe jos” sau „km”). Câmpurile vechi `minute` și `km` rămân primite.
                    $distanta = trim((string) ($c->camp('distanta') ?? ''));
                    $unitate = $c->camp('unitate') === 'km' ? 'km' : 'minute';
                    if ($distanta === '' && trim((string) ($c->camp('minute') ?? '')) !== '') {
                        [$distanta, $unitate] = [trim((string) $c->camp('minute')), 'minute'];
                    } elseif ($distanta === '' && trim((string) ($c->camp('km') ?? '')) !== '') {
                        [$distanta, $unitate] = [trim((string) $c->camp('km')), 'km'];
                    }
                    $scris = ['manual' => ['nume' => (string) ($c->camp('nume') ?? ''), 'categorie' => (string) ($c->camp('categorie') ?? ''), 'distanta' => $distanta, 'unitate' => $unitate]];
                    $nr = str_replace(',', '.', $distanta);
                    $rep->adauga(['nume' => $scris['manual']['nume'], 'categorie' => $scris['manual']['categorie'],
                        'minute' => $unitate === 'minute' && preg_match('~^\d{1,2}$~', $nr) ? (int) $nr : null,
                        'km' => $unitate === 'km' && preg_match('~^\d{1,3}(\.\d)?$~', $nr) ? (float) $nr : null], null);
                    $app->ecOglinda()->schimbaContinutul();
                    return $inapoi('adaugat');
                case 'minute':
                    $min = trim((string) ($c->camp('minute') ?? ''));
                    $scris = ['minute_reper' => $id, 'minute_scrise' => $min];
                    if (!preg_match('~^\d{1,2}$~', $min)) {
                        throw new \InvalidArgumentException('minute');
                    }
                    try {
                        $rep->minuteGazda($id, (int) $min);
                    } catch (\InvalidArgumentException) {
                        throw new \InvalidArgumentException('minute');
                    }
                    $app->ecOglinda()->schimbaContinutul();
                    return $inapoi('minute');
                case 'principal':
                    $rep->principal($id);
                    $app->ecOglinda()->schimbaContinutul();
                    return $inapoi('principal');
                case 'sterge':
                    $rep->sterge($id);
                    $app->ecOglinda()->schimbaContinutul();
                    return $inapoi('sters');
            }
        } catch (\InvalidArgumentException $e) {
            return self::paginaImprejurimi($app, $c, $u, $jeton, $scris, $e->getMessage(), 422);
        }
        return $inapoi('salvat');
    }

    /**
     * @param array $rezultate rezultatele căutării (`cautare`, `text`) și, după o greșeală,
     *                         ce a scris gazda (`manual`: reperul de mână; `minute_reper`, `minute_scrise`: minutele corectate)
     */
    private static function paginaImprejurimi(Aplicatie $app, Cerere $c, array $u, string $jeton, array $rezultate, ?string $eroare, int $status = 200): Raspuns
    {
        $l = (string) $u['limba_panou'];
        $t = $app->traducator($l, 'admin');
        $categorii = [];
        foreach (Repere::CATEGORII as $k) {
            $categorii[] = ['cheie' => $k, 'eticheta' => $t->t('admin.repere.categorie.' . $k)];
        }
        $lista = [];
        foreach ($app->repere()->toate() as $x) {
            $lista[] = $x + ['eticheta' => $t->t('admin.repere.categorie.' . $x['categorie']),
                'distanta' => $x['minute'] !== null ? $t->t('admin.repere.minute', ['n' => (string) $x['minute']]) : ($x['distanta_m'] !== null ? $t->t('admin.repere.km', ['n' => Repere::km($x['distanta_m'], $l)]) : '')];
        }
        $coord = $app->coordonate();
        $cod = static fn (array $x, ?string $cat): string => (string) json_encode(['nume' => $x['nume'], 'lat' => $x['lat'], 'lng' => $x['lng'], 'osm' => $x['osm'], 'categorie' => $cat],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cautare = [];
        foreach ($rezultate['cautare'] ?? [] as $i => $x) {
            $dist = $coord !== null ? Repere::distanta($coord['lat'], $coord['lng'], $x['lat'], $x['lng']) : null;
            $cautare[] = ['i' => $i, 'nume' => $x['nume'], 'eticheta' => $x['eticheta'] ?? '', 'categorie' => $x['categorie'],
                'distanta' => $dist !== null ? $t->t('admin.repere.km', ['n' => Repere::km($dist, $l)]) : '',
                'valoare' => $cod($x, null)];
        }
        $r = Raspuns::html('', $status);
        $d = AdminPanou::date($app, $c, $r, $u, $jeton, 'imprejurimi', self::MESAJE_REPERE);
        $codEroare = $eroare !== null ? (in_array($eroare, self::ERORI_REPERE, true) ? $eroare : 'nume') : null;
        if ($codEroare !== null) {
            $d['eroare'] = $t->t('admin.repere.eroare.' . $codEroare);
        }
        $manual = (array) ($rezultate['manual'] ?? []) + ['nume' => '', 'categorie' => '', 'distanta' => '', 'unitate' => 'minute'];
        $d += [
            'url' => ['imprejurimi' => $app->url()->cale('/admin/imprejurimi')],
            'repere' => $lista,
            'maxim' => count($lista) >= Repere::MAXIM,
            'categorii' => $categorii,
            'coordonate' => $coord !== null,
            'cautare' => $cautare,
            'cautat' => isset($rezultate['cautare']),
            'text' => (string) ($rezultate['text'] ?? ''),
            'manual' => $manual,
            // Câmpul reperului de mână lângă care apare eroarea (numele, categoria sau distanța), după o greșeală la adăugare.
            'eroare_camp' => isset($rezultate['manual']) && in_array($codEroare, ['nume', 'categorie', 'distanta'], true) ? $codEroare : null,
            // Reperul cu minutele scrise greșit, cu valoarea păstrată în câmpul lui.
            'minute_reper' => $codEroare === 'minute' ? (int) ($rezultate['minute_reper'] ?? 0) : 0,
            'minute_scrise' => (string) ($rezultate['minute_scrise'] ?? ''),
            'atribuire' => Repere::ATRIBUIRE,
            'atribuire_url' => Repere::ATRIBUIRE_URL,
        ];
        return AdminPanou::pagina($app, $u, 'imprejurimi', $d, $r);
    }
}
