<?php

declare(strict_types=1);

// Regulile textelor, pe toate cataloagele (site și panou, RO și EN):
// fără „—”, fără Ș/Ț cu sedilă, cheile RO/EN identice, formele de plural complete, furnizorii tehnici absenți
// din textele pentru oaspeți, formulare afirmativă, „dumneavoastră” în RO, fiecare cheie în texte-de-aprobat.md.

use Cazo\Dezvoltare\TexteDeAprobat;
use Cazo\I18n\Plural;

$catalog = static fn (string $f): array => require Mediu::radacina() . '/limbi/' . $f;

/** Toate textele, ca [eticheta => text], pentru o pereche de cataloage. */
$texte = static function (array $c, string $eticheta): array {
    $r = [];
    foreach ($c as $k => $v) {
        foreach (is_array($v) ? $v : ['' => $v] as $forma => $t) {
            $r["$eticheta:$k" . ($forma !== '' ? "[$forma]" : '')] = (string) $t;
        }
    }
    return $r;
};

$toate = static function () use ($catalog, $texte): array {
    $r = [];
    foreach (['ro', 'en', 'admin-ro', 'admin-en', 'instalare-ro', 'instalare-en', 'actualizari-ro', 'actualizari-en'] as $c) {
        $r[$c] = $texte($catalog("$c.php"), $c);
    }
    // Localele temelor oficiale: aceleași reguli ca pe site.
    foreach (TexteDeAprobat::teme(Mediu::radacina()) as $slug => $tt) {
        foreach ($tt as $cheie => $t) {
            $r['ro']["tema-$slug:$cheie"] = $t['ro'];
            $r['en']["tema-$slug:$cheie"] = $t['en'];
        }
    }
    // „Pentru cine e tema” (theme.json), doar în română.
    foreach (TexteDeAprobat::pentru(Mediu::radacina()) as $cheie => $t) {
        $r['ro']["pentru:$cheie"] = $t['ro'];
    }
    foreach (TexteDeAprobat::tari(Mediu::radacina()) as $cheie => $t) {
        $r['ro']["tari:$cheie"] = $t['ro'];
        $r['en']["tari:$cheie"] = $t['en'];
    }
    foreach (TexteDeAprobat::INCARCATOR as $cheie => [$ro, $en]) {
        $r['ro']["incarcator:$cheie"] = $ro;
        $r['en']["incarcator:$cheie"] = (string) $en;
    }
    return $r;
};

/**
 * Textele aprobate de proprietar, cuvânt cu cuvânt, cu formulări pe care regula afirmativă le-ar respinge (negație,
 * „Nearătată”, „dar”): rămân cum au fost aprobate. Excepția ține doar cât textul e exact cel aprobat; un
 * text schimbat trece din nou prin regulă. Engleza e traducerea textului aprobat.
 */
$aprobate = [
    'admin-ro:admin.pret.taxa.online' => 'Arătată în preț și încasată online, cu restul plății',
    'admin-ro:admin.pret.taxa.la_sosire' => 'Arătată în preț, dar încasată la sosire',
    'admin-ro:admin.pret.taxa.afara' => 'Nearătată în preț (o cereți separat, la sosire)',
    'admin-en:admin.pret.taxa.la_sosire' => 'Shown in the price, but collected on arrival',
    'admin-en:admin.pret.taxa.afara' => 'Not shown in the price (you ask for it separately, on arrival)',
    'admin-ro:admin.gdpr.termene_jurist' => 'Dacă nu știți ce termen vi se potrivește, întrebați contabilul.',
    // textul, cuvânt cu cuvânt.
    'ro:rmea.anulare.nerambursabil_directa' => 'Puteți anula rezervarea, dar tariful e nerambursabil: suma plătită nu se returnează.',
    // „liberă” și „indisponibilă”; engleza e traducerea stării hotărâte.
    'en:calendar.stare.indisponibila' => 'unavailable',
    // renunțarea la garanție e, prin natura ei, o negație (articolul 6 al licenței): textul bifei, cuvânt cu cuvânt.
    'instalare-ro:instalare.cod.licenta' => 'Am citit Licența Cazo și accept că programul este oferit „ca atare”, fără nicio garanție, iar titularul nu răspunde pentru folosirea lui.',
    'instalare-en:instalare.cod.licenta' => 'I have read the Cazo License and accept that the software is provided “as is”, without any warranty, and that the licensor is not liable for its use.',
    'instalare-en:instalare.cod.licenta_ajutor' => 'The license is in the LICENSE file in the archive. Article 6 says what we do not guarantee and what we are not liable for.',
    'instalare-ro:instalare.cod.licenta_ajutor' => 'Licența e în fișierul LICENSE din arhivă. Articolul 6 spune ce nu garantăm și pentru ce nu răspundem.',
    // negația cerută de lege, cuvânt cu cuvânt: informarea precontractuală spune că dreptul de retragere lipsește
    // (OUG 34/2014 art. 6 alin. (1) lit. k), cu excepția de la art. 16 lit. l): cazarea cu dată stabilită).
    'ro:comanda.precontractual.retragere' => 'Rezervarea e pentru date stabilite, deci nu aveți dreptul de retragere de 14 zile. Anularea urmează regulile de mai sus.',
    'en:comanda.precontractual.retragere' => 'This booking is for set dates, so you do not have the 14-day right of withdrawal. Cancellation follows the rules above.',
    // clauza anulării gazdei, cuvânt cu cuvânt: păstrează dreptul legal la despăgubiri
    // (Codul civil art. 1350, Legea 193/2000, anexa lit. r): „fără a afecta” e formularea cerută.
    'ro:comanda.anulare_gazda.tot' => 'Dacă anulăm noi rezervarea, vă returnăm tot ce ați plătit, fără a afecta dreptul dumneavoastră la despăgubiri potrivit legii.',
    'ro:comanda.anulare_gazda.caz' => 'Dacă anulăm noi rezervarea, vă returnăm tot ce ați plătit, iar o eventuală despăgubire în plus o stabilim de la caz la caz, fără a afecta dreptul dumneavoastră la despăgubiri potrivit legii.',
    'en:comanda.anulare_gazda.tot' => 'If we cancel the booking, we refund everything you paid, without affecting your right to compensation under the law.',
    'en:comanda.anulare_gazda.caz' => 'If we cancel the booking, we refund everything you paid and decide case by case on any additional compensation, without affecting your right to compensation under the law.',
    // textele asistentului trecerii, cuvânt cu cuvânt (operatorul.2026).
    'admin-ro:admin.local.as.pas1_text' => 'Am adus din EasyCheck camerele, prețul pe noapte și prețul de weekend. Verificați-le: de acum le schimbați aici, nu în EasyCheck.',
    'admin-ro:admin.local.as.pas2_nu' => 'Nu închiriez pe Booking',
    'admin-ro:admin.local.as.pas3_nu' => 'Nu închiriez pe Airbnb',
    'admin-en:admin.local.as.pas1_text' => 'We brought the rooms, the nightly price and the weekend price over from EasyCheck. Check them: from now on you change them here, not in EasyCheck.',
    'admin-en:admin.local.as.pas2_nu' => 'I do not rent on Booking',
    'admin-en:admin.local.as.pas3_nu' => 'I do not rent on Airbnb',
];

return [
    'cheile RO și EN sunt identice, pe site, în panou, în instalator și la actualizări' => function (Mediu $m) use ($catalog): void {
        foreach ([['ro.php', 'en.php'], ['admin-ro.php', 'admin-en.php'], ['instalare-ro.php', 'instalare-en.php'], ['actualizari-ro.php', 'actualizari-en.php']] as [$a, $b]) {
            $ka = array_keys($catalog($a));
            $kb = array_keys($catalog($b));
            sort($ka);
            sort($kb);
            egal($ka, $kb, "$a față de $b");
        }
    },

    'pluralele au exact formele limbii' => function (Mediu $m) use ($catalog): void {
        foreach (['ro.php' => 'ro', 'en.php' => 'en', 'admin-ro.php' => 'ro', 'admin-en.php' => 'en', 'instalare-ro.php' => 'ro', 'instalare-en.php' => 'en', 'actualizari-ro.php' => 'ro', 'actualizari-en.php' => 'en'] as $f => $limba) {
            foreach ($catalog($f) as $k => $v) {
                if (is_array($v)) {
                    egal(Plural::forme($limba), array_keys($v), "$f: $k");
                    foreach ($v as $t) {
                        contine($t, '{n}', "$f: $k");
                    }
                }
            }
        }
    },

    'fără „—” și fără Ș/Ț cu sedilă' => function (Mediu $m) use ($toate): void {
        foreach ($toate() as $texte) {
            foreach ($texte as $et => $t) {
                lipseste($t, "\u{2014}", $et);
                adevarat(!preg_match('~[\x{015E}\x{015F}\x{0162}\x{0163}]~u', $t), "$et are Ș/Ț cu sedilă");
            }
        }
    },

    'formulare afirmativă (fără negații)' => function (Mediu $m) use ($toate, $aprobate): void {
        $neg = [
            'ro' => '~\b(nu|nici|niciun|nicio|niciodată|nimic|nimeni|fără|n-)\b~iu',
            'en' => "~\\b(not|no|never|none|nothing|nobody|without|cannot|can't|don't|doesn't|won't|isn't|aren't|unable|unavailable)\\b~iu",
        ];
        foreach ($toate() as $c => $texte) {
            $limba = str_ends_with($c, 'en') ? 'en' : 'ro';
            foreach ($texte as $et => $t) {
                if (($aprobate[$et] ?? null) === $t) {
                    continue;
                }
                adevarat(!preg_match($neg[$limba], $t, $x), "$et conține negația „" . ($x[0] ?? '') . '”: ' . $t);
            }
        }
    },

    'excepțiile regulii afirmative: doar textele aprobate, exact cum au fost aprobate' => function (Mediu $m) use ($toate, $aprobate): void {
        $neg = '~\\b(nu|nici|fără|not|no|without)\\b~iu';
        $texte = $toate();
        foreach ($aprobate as $et => $aprobat) {
            $catalog = explode(':', $et)[0];
            egal($aprobat, $texte[$catalog][$et] ?? null, "$et: textul din catalog e altul decât cel aprobat, deci excepția lui a căzut");
        }
        // Cele trei variante ale taxei locale și nota contabilului sunt chiar textele aprobate (punctele 11 și 12).
        $ro = require Mediu::radacina() . '/limbi/admin-ro.php';
        egal('Arătată în preț și încasată online, cu restul plății', $ro['admin.pret.taxa.online']);
        egal('Arătată în preț, dar încasată la sosire', $ro['admin.pret.taxa.la_sosire']);
        egal('Nearătată în preț (o cereți separat, la sosire)', $ro['admin.pret.taxa.afara']);
        // Excepția e legată de text: aceeași cheie cu alt text, ori alt text cu negație, rămâne respinsă.
        $respins = static fn (string $et, string $t): bool => ($aprobate[$et] ?? null) !== $t && (bool) preg_match($neg, $t);
        fals($respins('admin-en:admin.pret.taxa.afara', $aprobate['admin-en:admin.pret.taxa.afara']));
        fals($respins('admin-ro:admin.gdpr.termene_jurist', $aprobate['admin-ro:admin.gdpr.termene_jurist']));
        adevarat($respins('admin-en:admin.pret.taxa.afara', 'Not shown in the price'));
        adevarat($respins('admin-ro:admin.gdpr.termene_jurist', 'Dacă nu știți, întrebați contabilul.'));
        adevarat($respins('admin-ro:admin.pret.include_nota', $aprobate['admin-ro:admin.gdpr.termene_jurist']));
        // Textele interne despre avizul juristului au ieșit din panou și din instalator.
        foreach (['admin-ro', 'admin-en', 'instalare-ro', 'instalare-en'] as $c) {
            foreach ($texte[$c] as $et => $t) {
                adevarat(!preg_match('~avizul juristului|pending legal advice|after legal review~iu', $t), "$et pomenește avizul juristului");
            }
        }
    },

    'româna se adresează cu „dumneavoastră”' => function (Mediu $m) use ($toate): void {
        $informal = '~\b(tu|te|ți|ție|tău|ta|tale|tăi|tine|ai|poți|vei|vrei|ești|apasă|alege|scrie|intră|mergi|încearcă)\b~iu';
        foreach (['ro', 'admin-ro', 'instalare-ro', 'actualizari-ro'] as $c) {
            foreach ($toate()[$c] as $et => $t) {
                adevarat(!preg_match($informal, $t, $x), "$et folosește forma informală „" . ($x[0] ?? '') . '”');
            }
        }
    },

    'fiecare text cu {contact} are varianta _fara_contact, scrisă fără contact (o gazdă fără contact publicat)' => function (Mediu $m) use ($catalog): void {
        foreach (['ro', 'en'] as $l) {
            $c = $catalog($l . '.php');
            foreach ($c as $k => $v) {
                $forme = is_array($v) ? $v : [$v];
                if ($k === 'email.contact' || str_ends_with($k, '_fara_contact') || !str_contains(implode(' ', $forme), '{contact}')) {
                    continue;
                }
                // Variantele după felul proprietății: `<cheie>_fara_contact_apartament`, fiindcă varianta se alege după _fara_contact.
                if (preg_match('~^(.+)(_apartament|_unica)$~', $k, $x)) {
                    adevarat(isset($c[$x[1] . '_fara_contact' . $x[2]]), "$l: $k fără varianta {$x[1]}_fara_contact{$x[2]}");
                    lipseste(implode(' ', (array) $c[$x[1] . '_fara_contact' . $x[2]]), '{contact}', "$l: {$x[1]}_fara_contact{$x[2]}");
                    continue;
                }
                adevarat(isset($c[$k . '_fara_contact']), "$l: $k fără varianta _fara_contact");
                foreach ((array) ($c[$k . '_fara_contact'] ?? []) as $x) {
                    lipseste($x, '{contact}', "$l: {$k}_fara_contact");
                }
            }
        }
    },

    'textele din șabloane urmate de telefonul și e-mailul gazdei au varianta _fara_contact, afișată fără contact' => function (Mediu $m) use ($catalog): void {
        // Componentele nucleului și temele oficiale: un text urmat în șablon de contact.telefon / contact.email
        // (de exemplu „... la telefon sau pe e-mail:”) stă sub {% if contact.telefon or contact.email %}, iar
        // ramura {% else %} afișează varianta `_fara_contact`, o frază întreagă.
        $r = Mediu::radacina();
        $sabloane = [];
        foreach ([$r . '/vederi', $r . '/teme'] as $d) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (str_ends_with($f->getFilename(), '.twig')) {
                    $sabloane[] = $f->getPathname();
                }
            }
        }
        $gasite = [];
        foreach ($sabloane as $f) {
            $s = (string) file_get_contents($f);
            $nume = substr($f, strlen($r) + 1);
            foreach (explode("\n", $s) as $linie) {
                if (!preg_match_all("~t\\('([a-z0-9_.]+)'\\)~", $linie, $x, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                foreach ($x[1] as [$k, $poz]) {
                    $dupa = substr($linie, $poz);
                    if (str_ends_with($k, '_fara_contact') || !preg_match('~^[^<]*(<a [^>]*>)?[^<]*\{%\s*if contact\.(telefon|email)~', substr($dupa, strlen($k) + 5))) {
                        continue;
                    }
                    $gasite[] = "$nume:$k";
                    foreach (['ro', 'en'] as $l) {
                        $c = $catalog($l . '.php');
                        adevarat(isset($c[$k . '_fara_contact']), "$nume: $l.$k fără varianta _fara_contact");
                        adevarat(!str_ends_with(trim((string) ($c[$k . '_fara_contact'] ?? '')), ':'), "$nume: {$k}_fara_contact se termină cu „:”");
                    }
                    contine($linie, "{% if contact.telefon or contact.email %}{{ t('$k') }}", $nume);
                    contine($linie, "{% else %}{{ t('{$k}_fara_contact') }}{% endif %}", $nume);
                }
            }
        }
        foreach (['vederi/componente/bara.twig:bara.contact', 'vederi/componente/cautare.twig:bara.contact', 'vederi/componente/rezumat.twig:bara.contact', 'vederi/componente/comanda.twig:comanda.contact'] as $asteptat) {
            adevarat(in_array($asteptat, $gasite, true), "regula a sărit $asteptat");
        }
        // Randarea: fără telefon și fără e-mail, fiecare componentă afișează fraza întreagă, fără „:” urmat de nimic.
        foreach (['ro', 'en'] as $l) {
            $t = \Cazo\I18n\Traducator::pentru($r . '/limbi', $l);
            $env = \Cazo\Teme\MediuNucleu::creeaza($r . '/vederi', null, $t);
            $gol = ['telefon' => '', 'email' => ''];
            $formular = ['actiune' => '/disponibil', 'ascunse' => []];
            foreach ([
                'bara' => ['activa' => false, 'contact' => $gol],
                'cautare' => ['activa' => false, 'contact' => $gol, 'formular' => $formular, 'cerere' => null, 'adulti_optiuni' => [1, 2], 'copii_optiuni' => [0]],
                'rezumat' => ['activa' => false, 'contact' => $gol, 'camera' => 'c1', 'api' => '/_api/pret'],
                'comanda' => ['activa' => false, 'contact' => $gol, 'mesaj' => ''],
            ] as $comp => $date) {
                $html = $env->render("componente/$comp.twig", $date);
                $cheie = $comp === 'comanda' ? 'comanda.contact' : 'bara.contact';
                contine($html, htmlspecialchars($t->t($cheie . '_fara_contact'), ENT_QUOTES), "$l: $comp");
                lipseste($html, htmlspecialchars($t->t($cheie), ENT_QUOTES), "$l: $comp");
                adevarat(!preg_match('~:\s*</p>~', $html), "$l: $comp se termină cu „:” urmat de nimic");
                $cu = $env->render("componente/$comp.twig", ['contact' => ['telefon' => '0722 000 000', 'email' => '']] + $date);
                contine($cu, htmlspecialchars($t->t($cheie), ENT_QUOTES) . ' <a href="tel:0722000000">', "$l: $comp cu telefon");
            }
        }
    },

    'furnizorii tehnici lipsesc din textele pentru oaspeți' => function (Mediu $m) use ($toate): void {
        $furnizori = '~(easy\s*check|stripe|open-?meteo|openstreetmap|github|google|leaflet|twig|phpmailer|cpanel|litespeed|cloudflare|php\b|mysql|sqlite)~iu';
        foreach (['ro', 'en'] as $c) {
            foreach ($toate()[$c] as $et => $t) {
                adevarat(!preg_match($furnizori, $t, $x), "$et numește furnizorul „" . ($x[0] ?? '') . '”');
            }
        }
    },

    'fiecare text are un rând actual în docs/texte-de-aprobat.md' => function (Mediu $m): void {
        $r = Mediu::radacina();
        if (!is_file($r . '/' . TexteDeAprobat::FISIER)) {
            return; // lista textelor de aprobat se ține în afara acestui depozit
        }
        $lista = TexteDeAprobat::citeste($r . '/' . TexteDeAprobat::FISIER);
        adevarat($lista !== [], 'docs/texte-de-aprobat.md lipsește sau e gol; rulați bin/cazo texte');
        foreach (TexteDeAprobat::sectiuni($r) as $sectiune => $texte) {
            foreach ($texte as $cheie => $t) {
                $rand = $lista[$sectiune][$cheie] ?? null;
                adevarat($rand !== null, "$cheie lipsește din texte-de-aprobat.md ($sectiune); rulați bin/cazo texte");
                egal($t['ro'], $rand['ro'], "$cheie: textul RO din listă e vechi");
                egal($t['en'], $rand['en'], "$cheie: textul EN din listă e vechi");
                adevarat((bool) preg_match('~^(provizoriu|aprobat \d{4}-\d{2}-\d{2})$~', $rand['stare']), "$cheie: stare necunoscută „{$rand['stare']}”");
            }
            foreach (array_keys($lista[$sectiune] ?? []) as $cheie) {
                adevarat(isset($texte[$cheie]), "$cheie din listă nu mai există în cataloage ($sectiune)");
            }
        }
    },

    'textele „pentru cine e tema” vin din theme.json, fără temele interne' => function (Mediu $m): void {
        $p = TexteDeAprobat::pentru(Mediu::radacina());
        egal(['ro' => 'hotel sau motel, în oraș', 'en' => ''], $p['tema.hotel.pentru']);
        egal('pensiune sau pensiune agroturistică, la țară sau la munte', $p['tema.rustic.pentru']['ro']);
        egal('casă sau cabană, la munte sau la țară', $p['tema.brad.pentru']['ro']);
        egal([], array_filter(array_keys($p), static fn (string $k): bool => str_starts_with($k, 'tema._')));
        egal($p, TexteDeAprobat::sectiuni(Mediu::radacina())[TexteDeAprobat::PENTRU]);
    },

    'textele încărcătorului sunt chiar cele din public/index.php' => function (Mediu $m): void {
        $index = (string) file_get_contents(Mediu::radacina() . '/public/index.php');
        foreach (TexteDeAprobat::INCARCATOR as $cheie => [$ro, $en]) {
            contine($index, $ro, $cheie);
            adevarat($en !== null && $en !== '', "$cheie: textul EN lipsește");
            contine($index, (string) $en, $cheie);
        }
    },

    'regenerarea listei păstrează aprobările doar pentru textele neschimbate' => function (Mediu $m): void {
        $d = $m->dosarTemporar();
        mkdir("$d/limbi");
        mkdir("$d/docs");
        $scrie = function (array $ro, array $en) use ($d): void {
            file_put_contents("$d/limbi/ro.php", '<?php return ' . var_export($ro, true) . ';');
            file_put_contents("$d/limbi/en.php", '<?php return ' . var_export($en, true) . ';');
        };
        $scrie(['a' => 'Unu | doi', 'b' => 'Trei'], ['a' => 'One', 'b' => 'Three']);
        file_put_contents("$d/limbi/admin-ro.php", '<?php return [];');
        file_put_contents("$d/limbi/admin-en.php", '<?php return [];');
        TexteDeAprobat::scrie($d);
        $md = (string) file_get_contents("$d/docs/texte-de-aprobat.md");
        $md = str_replace(['| One | provizoriu |', '| Three | provizoriu |'], ['| One | aprobat 2026-11-15 |', '| Three | aprobat 2026-11-15 |'], $md);
        file_put_contents("$d/docs/texte-de-aprobat.md", $md);
        $scrie(['a' => 'Unu | doi', 'b' => 'Trei, schimbat'], ['a' => 'One', 'b' => 'Three']);
        TexteDeAprobat::scrie($d);
        $l = TexteDeAprobat::citeste("$d/docs/texte-de-aprobat.md")['Site (limbi/ro.php, limbi/en.php)'];
        egal('aprobat 2026-11-15', $l['a']['stare']);
        egal('Unu | doi', $l['a']['ro'], 'bara verticală din text');
        egal('provizoriu', $l['b']['stare'], 'textul schimbat și-a păstrat aprobarea');
    },

    'formatele din listă sunt cele produse de Format' => function (Mediu $m): void {
        $f = TexteDeAprobat::sectiuni(Mediu::radacina())['Formatele (app/I18n/Format.php)'];
        foreach (['ro', 'en'] as $l) {
            $luni = [];
            for ($k = 1; $k <= 12; $k++) {
                $luni[] = explode(' ', \Cazo\I18n\Format::zi(sprintf('2026-%02d-01', $k), $l))[1];
            }
            egal($f['format.luni_scurt'][$l], implode(', ', $luni), $l);
            egal($f['format.bani'][$l], \Cazo\I18n\Format::bani(145000, $l));
            egal($f['format.zi'][$l], \Cazo\I18n\Format::zi('2026-12-20', $l));
        }
    },
];
