<?php

declare(strict_types=1);

namespace Cazo\Nucleu;

use Cazo\Controlere\Admin;
use Cazo\Controlere\AdminCont;
use Cazo\Controlere\AdminContinut;
use Cazo\Controlere\AdminGdpr;
use Cazo\Controlere\AdminLista;
use Cazo\Controlere\AdminRezervari;
use Cazo\Controlere\AdminSistem;
use Cazo\Controlere\AdminTeme;
use Cazo\Controlere\AnuntEasyCheck;
use Cazo\Controlere\ApiIntern;
use Cazo\Controlere\Cron;
use Cazo\Controlere\Plata;
use Cazo\Controlere\Prezentare;
use Cazo\Controlere\Publicare;
use Cazo\Controlere\RapoarteCsp;
use Cazo\Controlere\Rezerva;
use Cazo\Controlere\Stare;
use Cazo\Controlere\Seo;

defined('CAZO') or exit;

/**
 * Tabelul static de rute. Fiecare etapă adaugă aici rutele ei.
 * Testul de securitate parcurge tabelul: antetele și CSP-ul pe fiecare rută, CSRF pe fiecare POST.
 */
final class Rute
{
    /** @return Ruta[] */
    public static function toate(): array
    {
        $rute = [
            // Pagina de început, randată de temă.
            new Ruta('GET', '/', [Prezentare::class, 'acasa'], 'public', limbi: true, nume: 'acasa'),
        ];
        // Paginile publice cu căi traduse: RO la rădăcină, EN sub /en/ (Cai).
        $actiuni = [
            'camere' => 'camere', 'camera' => 'camera', 'pagina' => 'pagina', 'disponibil' => 'disponibil', 'date_legale' => 'dateLegale',
            'termeni' => 'termeni', 'confidentialitate' => 'confidentialitate', 'accesibilitate' => 'accesibilitate',
        ];
        foreach ($actiuni as $nume => $metoda) {
            foreach (Cai::CAI[$nume] as $limba => $cale) {
                $model = str_replace('{slug}', '{slug:' . Cai::SLUG . '}', $cale);
                $rute[] = new Ruta('GET', $model, [Prezentare::class, $metoda], 'public', limbi: $limba !== 'ro', nume: $nume . '.' . $limba, limba: $limba);
            }
        }
        // Căile vechi: redirecționări permanente spre noua adresă, pe ambele limbi.
        foreach (Cai::VECHI as $nume => $cai) {
            foreach ($cai as $limba => $cale) {
                $rute[] = new Ruta('GET', $cale, [Prezentare::class, $nume], 'public', limbi: $limba !== 'ro', nume: $nume . '-vechi.' . $limba, limba: $limba);
            }
        }
        $ref = '{ref:PG-[23456789A-HJKMNP-Z]{6}}';
        $token = '{token:[A-Za-z0-9_-]{43}}';
        return array_merge($rute, [
            // Comanda și cererea cash, în layoutul temei, cu profilul „rezervare”.
            new Ruta('GET', '/rezerva', [Rezerva::class, 'comanda'], 'rezervare', limbi: true, nume: 'rezerva'),
            new Ruta('POST', '/rezerva', [Rezerva::class, 'trimite'], 'rezervare', limbi: true, nume: 'rezerva-trimite'),
            new Ruta('GET', '/altcha', [Rezerva::class, 'altcha'], 'tehnic', limbi: true, nume: 'altcha'),
            new Ruta('GET', '/comanda/' . $ref, [Stare::class, 'comanda'], 'rezervare', limbi: true, nume: 'comanda'),
            new Ruta('POST', '/comanda/' . $ref . '/cod', [Stare::class, 'cod'], 'rezervare', limbi: true, nume: 'comanda-cod'),
            new Ruta('POST', '/comanda/' . $ref . '/cod-nou', [Stare::class, 'codNou'], 'rezervare', limbi: true, nume: 'comanda-cod-nou'),
            new Ruta('GET', '/_api/comanda/' . $ref, [Stare::class, 'api'], 'tehnic', limbi: true, nume: 'api-comanda'),
            new Ruta('GET', '/r/' . $token . '.ics', [Stare::class, 'ics'], 'tehnic', limbi: true, nume: 'rmea-ics'),
            // calendarul site-ului fără cheie EasyCheck, câte unul pe cameră, la adresa secretă pusă pe platforme.
            new Ruta('GET', '/calendar/{jeton:[0-9A-Za-z]{32,43}}/{camera:[a-z0-9_]{1,40}}.ics', [\Cazo\Controlere\AdminLocal::class, 'ics'], 'tehnic', nume: 'calendar-site'),
            new Ruta('GET', '/calendar/{jeton:[0-9A-Za-z]{32,43}}/{camera:[a-z0-9_]{1,40}}-{unitate:[1-9][0-9]?}.ics', [\Cazo\Controlere\AdminLocal::class, 'icsUnitate'], 'tehnic', nume: 'calendar-site-unitate'),
            new Ruta('GET', '/r/' . $token, [Stare::class, 'rezervareaMea'], 'rezervare', limbi: true, nume: 'rmea'),
            new Ruta('POST', '/r/' . $token . '/noutati', [Stare::class, 'noutati'], 'rezervare', limbi: true, nume: 'rmea-noutati'),
            // După rezervare: anularea oaspetelui sau cererea de anulare către gazdă.
            new Ruta('POST', '/r/' . $token . '/anuleaza', [Stare::class, 'anuleaza'], 'rezervare', limbi: true, nume: 'rmea-anuleaza'),
            // „Am plătit”, cât rezervarea așteaptă transferul bancar.
            new Ruta('POST', '/r/' . $token . '/am-platit', [Stare::class, 'amPlatit'], 'rezervare', limbi: true, nume: 'rmea-am-platit'),
            // Schimbarea datelor: noile date, oferta, pornirea; plata diferenței și renunțarea la ea.
            new Ruta('GET', '/r/' . $token . '/schimba', [\Cazo\Controlere\Schimbare::class, 'pagina'], 'rezervare', limbi: true, nume: 'rmea-schimba'),
            new Ruta('POST', '/r/' . $token . '/schimba', [\Cazo\Controlere\Schimbare::class, 'trimite'], 'rezervare', limbi: true, nume: 'rmea-schimba-trimite'),
            new Ruta('GET', '/schimbare/intoarcere', [\Cazo\Controlere\Schimbare::class, 'intoarcere'], 'rezervare', limbi: true, nume: 'schimbare-intoarcere'),
            new Ruta('GET', '/schimbare/renuntare', [\Cazo\Controlere\Schimbare::class, 'renuntare'], 'rezervare', limbi: true, nume: 'schimbare-renuntare'),

            // Plata online: întoarcerea din Checkout și renunțarea.
            new Ruta('GET', '/plata/intoarcere', [Plata::class, 'intoarcere'], 'rezervare', limbi: true, nume: 'plata-intoarcere'),
            new Ruta('GET', '/plata/renuntare', [Plata::class, 'renuntare'], 'rezervare', limbi: true, nume: 'plata-renuntare'),

            // Panoul minim: autentificarea, cererile cash cu butoanele de probă, codul de 6 cifre, întrerupătorul.
            new Ruta('GET', '/admin/', [Admin::class, 'acasa'], 'admin', nume: 'admin'),
            new Ruta('POST', '/admin/intrare', [Admin::class, 'intrare'], 'admin', nume: 'admin-intrare'),
            new Ruta('POST', '/admin/iesire', [Admin::class, 'iesire'], 'admin', nume: 'admin-iesire'),
            new Ruta('POST', '/admin/cereri/' . $ref . '/{decizie:proba-accepta|proba-refuza}', [Admin::class, 'proba'], 'admin', nume: 'admin-proba'),
            new Ruta('POST', '/admin/rezervare', [Admin::class, 'setari'], 'admin', nume: 'admin-rezervare'),
            // Transferul bancar: componenta setărilor (bifa și contul) și deciziile de probă ale plății.
            new Ruta('POST', '/admin/plati/transfer', [\Cazo\Controlere\AdminTransfer::class, 'salveaza'], 'admin', nume: 'admin-plati-transfer'),
            new Ruta('POST', '/admin/transfer/' . $ref . '/{decizie:proba-confirma|proba-refuza}', [\Cazo\Controlere\AdminTransfer::class, 'proba'], 'admin', nume: 'admin-transfer-proba'),
            // „Prelungiți termenul” la transferul bancar, în modul fără cheie EasyCheck.
            new Ruta('POST', '/admin/transfer/' . $ref . '/prelungeste', [\Cazo\Controlere\AdminTransfer::class, 'prelungeste'], 'admin', nume: 'admin-transfer-prelungeste'),
            // plățile (metodele, cheia Stripe, punctul de anunț) și 2FA (TOTP minim).
            new Ruta('POST', '/admin/plati', [Admin::class, 'plati'], 'admin', nume: 'admin-plati'),
            new Ruta('POST', '/admin/plati/' . $ref . '/inchide', [Admin::class, 'inchide'], 'admin', nume: 'admin-plati-inchide'),
            new Ruta('POST', '/admin/2fa', [AdminCont::class, 'doiFactori'], 'admin', nume: 'admin-2fa'),
            new Ruta('GET', '/admin/cont/2fa', [AdminCont::class, 'doiFactoriPagina'], 'admin', nume: 'admin-2fa-pagina'),
            new Ruta('GET', '/admin/metode-plata', [Admin::class, 'metode'], 'admin', nume: 'admin-metode'),
            new Ruta('GET', '/admin/protectie', [\Cazo\Controlere\AdminCompus::class, 'vechi'], 'admin', nume: 'admin-protectie'),
            // după rezervare (detaliul, cererile de anulare, rambursările, anularea), reconcilierea și avansurile.
            new Ruta('GET', '/admin/rezervari/' . $ref, [AdminRezervari::class, 'detaliu'], 'admin', nume: 'admin-rezervare'),
            new Ruta('POST', '/admin/rezervari/' . $ref . '/{actiune:decizie|rambursare|anulare|reincearca|renunta|lamurire|taxa|despagubire|forta_majora|despagubire_caz}', [AdminRezervari::class, 'actiune'], 'admin', nume: 'admin-rezervare-actiune'),
            // butoanele de probă ale etapelor 3 și 4 (modul P, cheia de probă cu funcția aprinsă).
            new Ruta('POST', '/admin/rezervari/' . $ref . '/{proba:proba-anuleaza|proba-accepta-anularea|proba-pastreaza|proba-rambursare|proba-confirmarea|proba-transfer}', [AdminRezervari::class, 'probaEc'], 'admin', nume: 'admin-rezervare-proba-ec'),
            new Ruta('GET', '/admin/plati/export.csv', [AdminRezervari::class, 'export'], 'admin', nume: 'admin-export'),
            new Ruta('GET', '/admin/plati/avansuri', [\Cazo\Controlere\AdminCompus::class, 'vechi'], 'admin', nume: 'admin-avansuri'),
            // prețul site-ului (reducerile locale, paritatea, echivalentul în euro, vremea) și punctele de reper.
            new Ruta('GET', '/admin/preturi', [\Cazo\Controlere\AdminCompus::class, 'vechi'], 'admin', nume: 'admin-preturi'),
            new Ruta('POST', '/admin/preturi', [\Cazo\Controlere\AdminPret::class, 'preturiPost'], 'admin', nume: 'admin-preturi-post'),
            new Ruta('GET', '/admin/imprejurimi', [\Cazo\Controlere\AdminCompus::class, 'vechi'], 'admin', nume: 'admin-imprejurimi'),
            new Ruta('POST', '/admin/imprejurimi', [\Cazo\Controlere\AdminPret::class, 'imprejurimiPost'], 'admin', nume: 'admin-imprejurimi-post'),
            // Aspectul: temele, încărcarea, previzualizarea, activarea, setările din schemă.
            new Ruta('GET', '/admin/aspect', [AdminTeme::class, 'lista'], 'admin', nume: 'admin-aspect'),
            new Ruta('POST', '/admin/aspect/incarca', [AdminTeme::class, 'incarca'], 'admin', nume: 'admin-aspect-incarca'),
            new Ruta('POST', '/admin/aspect/revino', [AdminTeme::class, 'revino'], 'admin', nume: 'admin-aspect-revino'),
            new Ruta('POST', '/admin/aspect/sigla', [AdminTeme::class, 'sigla'], 'admin', nume: 'admin-aspect-sigla'),
            new Ruta('GET', '/admin/aspect/{slug:[a-z0-9][a-z0-9\-]{0,63}}/previzualizare', [AdminTeme::class, 'previzualizare'], 'rezervare', nume: 'admin-aspect-previzualizare'),
            new Ruta('POST', '/admin/aspect/{slug:[a-z0-9][a-z0-9\-]{0,63}}/activeaza', [AdminTeme::class, 'activeaza'], 'admin', nume: 'admin-aspect-activeaza'),
            new Ruta('POST', '/admin/aspect/{slug:[a-z0-9][a-z0-9\-]{0,63}}/sterge', [AdminTeme::class, 'sterge'], 'admin', nume: 'admin-aspect-sterge'),
            new Ruta('GET', '/admin/aspect/{slug:[a-z0-9][a-z0-9\-]{0,63}}/setari', [\Cazo\Controlere\AdminCompus::class, 'setariTema'], 'admin', nume: 'admin-aspect-setari'),
            new Ruta('POST', '/admin/aspect/{slug:[a-z0-9][a-z0-9\-]{0,63}}/setari', [AdminTeme::class, 'setari'], 'admin', nume: 'admin-aspect-setari-salveaza'),
            new Ruta('GET', '/admin/aspect/{slug:[a-z0-9][a-z0-9\-]{0,63}}/setari.json', [AdminTeme::class, 'exportSetari'], 'admin', nume: 'admin-aspect-setari-export'),
            new Ruta('POST', '/admin/aspect/{slug:[a-z0-9][a-z0-9\-]{0,63}}/setari/import', [AdminTeme::class, 'importSetari'], 'admin', nume: 'admin-aspect-setari-import'),

            // panoul complet (14). Listele, alertele, contul, utilizatorii, auditul, GDPR, Legal, publicarea, Conexiunile,
            // E-mailurile, Conținutul și Sistemul; plus pașii fără sesiune (parola uitată, invitația).
            // Setări › Întreținere (actualizările, copiile, jurnalul, diagnosticul), pagina compusă nouă. Adresele GET ale
            // paginilor unite în altele (AdminCompus::VECHI) duc, cu 302, la pagina și la blocul lor; POST-urile lor rămân.
            new Ruta('GET', '/admin/intretinere', [\Cazo\Controlere\AdminCompus::class, 'intretinere'], 'admin', nume: 'admin-intretinere'),
            new Ruta('GET', '/admin/rezervari', [AdminLista::class, 'rezervari'], 'admin', nume: 'admin-rezervari'),
            new Ruta('POST', '/admin/rezervari', [AdminLista::class, 'cauta'], 'admin', nume: 'admin-rezervari-cauta'),
            new Ruta('POST', '/admin/rezervari/' . $ref . '/retrimite', [AdminLista::class, 'retrimite'], 'admin', nume: 'admin-rezervare-retrimite'),
            new Ruta('POST', '/admin/rezervari/' . $ref . '/reia', [AdminLista::class, 'reia'], 'admin', nume: 'admin-rezervare-reia'),
            // M6b: conținutul și setările avansate.
            new Ruta('GET', '/admin/poze', [\Cazo\Controlere\AdminCompus::class, 'vechi'], 'admin', nume: 'admin-poze'),
            new Ruta('POST', '/admin/poze/incarca', [AdminContinut::class, 'pozaIncarca'], 'admin', nume: 'admin-poze-incarca'),
            new Ruta('POST', '/admin/poze/{sha:[0-9a-f]{64}}/{actiune:seteaza|sterge}', [AdminContinut::class, 'pozaActiune'], 'admin', nume: 'admin-poza-actiune'),
            new Ruta('GET', '/admin/pagini', [AdminContinut::class, 'pagini'], 'admin', nume: 'admin-pagini'),
            new Ruta('GET', '/admin/pagini/{id:noua|[1-9][0-9]{0,9}}', [AdminContinut::class, 'pagina'], 'admin', nume: 'admin-pagina'),
            new Ruta('POST', '/admin/pagini/{id:noua|[1-9][0-9]{0,9}}', [AdminContinut::class, 'pagina'], 'admin', nume: 'admin-pagina-salveaza'),
            new Ruta('POST', '/admin/pagini/{id:[1-9][0-9]{0,9}}/sterge', [AdminContinut::class, 'paginaSterge'], 'admin', nume: 'admin-pagina-sterge'),
            new Ruta('GET', '/admin/seo', [AdminContinut::class, 'seo'], 'admin', nume: 'admin-seo'),
            new Ruta('POST', '/admin/seo/{actiune:titluri|redirectare|sterge-redirectare}', [AdminContinut::class, 'seoActiune'], 'admin', nume: 'admin-seo-actiune'),
            new Ruta('GET', '/admin/emailuri/sabloane', [AdminContinut::class, 'sabloane'], 'admin', nume: 'admin-sabloane'),
            new Ruta('POST', '/admin/emailuri/sabloane', [AdminContinut::class, 'sabloane'], 'admin', nume: 'admin-sabloane-salveaza'),
            new Ruta('GET', '/admin/legal/texte', [\Cazo\Controlere\AdminCompus::class, 'vechi'], 'admin', nume: 'admin-texte-legale'),
            new Ruta('POST', '/admin/legal/texte', [AdminContinut::class, 'texteLegale'], 'admin', nume: 'admin-texte-legale-salveaza'),
            new Ruta('GET', '/admin/plati', [\Cazo\Controlere\AdminCompus::class, 'vechi'], 'admin', nume: 'admin-plati-lista'),
            new Ruta('GET', '/admin/alerte', [\Cazo\Controlere\AdminCompus::class, 'vechi'], 'admin', nume: 'admin-alerte'),
            // gazda ascunde o notă de informare din Acasă și din Alerte („Am înțeles”).
            new Ruta('POST', '/admin/alerte/ascunde', [\Cazo\Controlere\AdminPanou::class, 'ascunde'], 'admin', nume: 'admin-alerte-ascunde'),
            new Ruta('GET', '/admin/cont', [AdminCont::class, 'cont'], 'admin', nume: 'admin-cont'),
            new Ruta('POST', '/admin/reautentificare', [AdminCont::class, 'reautentificare'], 'admin', nume: 'admin-reautentificare'),
            new Ruta('POST', '/admin/cont/{actiune:date|limba|nume|parola|sesiuni|coduri}', [AdminCont::class, 'contActiune'], 'admin', nume: 'admin-cont-actiune'),
            new Ruta('GET', '/admin/utilizatori', [AdminCont::class, 'utilizatori'], 'admin', nume: 'admin-utilizatori'),
            new Ruta('POST', '/admin/utilizatori/invita', [AdminCont::class, 'invita'], 'admin', nume: 'admin-utilizatori-invita'),
            new Ruta('POST', '/admin/utilizatori/{id:[1-9][0-9]{0,9}}/{actiune:dezactiveaza|reactiveaza|rol|drept|reinvita|reseteaza-2fa}', [AdminCont::class, 'utilizatorActiune'], 'admin',
                nume: 'admin-utilizator-actiune'),
            new Ruta('GET', '/admin/audit', [\Cazo\Controlere\AdminCompus::class, 'vechi'], 'admin', nume: 'admin-audit'),
            new Ruta('GET', '/admin/parola-uitata', [AdminCont::class, 'parolaUitata'], 'admin', nume: 'admin-parola-uitata'),
            new Ruta('POST', '/admin/parola-uitata', [AdminCont::class, 'parolaUitata'], 'admin', nume: 'admin-parola-uitata-trimite'),
            new Ruta('GET', '/admin/parola/' . str_replace('token', 'jeton', $token), [AdminCont::class, 'parolaNoua'], 'admin', nume: 'admin-parola-noua'),
            new Ruta('POST', '/admin/parola/' . str_replace('token', 'jeton', $token), [AdminCont::class, 'parolaNoua'], 'admin', nume: 'admin-parola-noua-salveaza'),
            new Ruta('GET', '/admin/invitatie/' . str_replace('token', 'jeton', $token), [AdminCont::class, 'parolaNoua'], 'admin', nume: 'admin-invitatie'),
            new Ruta('POST', '/admin/invitatie/' . str_replace('token', 'jeton', $token), [AdminCont::class, 'parolaNoua'], 'admin', nume: 'admin-invitatie-accepta'),
            new Ruta('GET', '/admin/gdpr', [AdminGdpr::class, 'gdpr'], 'admin', nume: 'admin-gdpr'),
            new Ruta('POST', '/admin/gdpr/{actiune:termene|alerte|cauta|export|sterge}', [AdminGdpr::class, 'actiune'], 'admin', nume: 'admin-gdpr-actiune'),
            new Ruta('GET', '/admin/legal', [\Cazo\Controlere\AdminCompus::class, 'vechi'], 'admin', nume: 'admin-legal'),
            new Ruta('POST', '/admin/legal', [AdminGdpr::class, 'legalSalveaza'], 'admin', nume: 'admin-legal-salveaza'),
            new Ruta('POST', '/admin/legal/noutati', [AdminGdpr::class, 'noutati'], 'admin', nume: 'admin-legal-noutati'),
            // abonații la oferte (lista unită, oferta, proba, trimiterea, exportul) și dezabonarea fără cont.
            new Ruta('GET', '/admin/abonati', [\Cazo\Controlere\AdminAbonati::class, 'pagina'], 'admin', nume: 'admin-abonati'),
            new Ruta('POST', '/admin/abonati/{actiune:previzualizeaza|proba|trimite|dezaboneaza|export}', [\Cazo\Controlere\AdminAbonati::class, 'actiune'], 'admin', nume: 'admin-abonati-actiune'),
            new Ruta('GET', '/dezabonare/{token:[A-Za-z0-9_-]{40,460}}', [\Cazo\Controlere\Dezabonare::class, 'pagina'], 'rezervare', limbi: true, nume: 'dezabonare'),
            new Ruta('POST', '/dezabonare/{token:[A-Za-z0-9_-]{40,460}}', [\Cazo\Controlere\Dezabonare::class, 'confirma'], 'rezervare', limbi: true,
                faraCsrf: 'dezabonarea cu un clic (RFC 8058) o trimite programul de e-mail al oaspetelui, fără sesiune; o autentifică jetonul semnat al adresei', nume: 'dezabonare-confirma'),
            new Ruta('GET', '/admin/site', [\Cazo\Controlere\AdminCompus::class, 'vechi'], 'admin', nume: 'admin-site'),
            new Ruta('POST', '/admin/site/{actiune:publica|retrage}', [AdminGdpr::class, 'siteActiune'], 'admin', nume: 'admin-site-actiune'),
            new Ruta('GET', '/admin/sistem', [\Cazo\Controlere\AdminCompus::class, 'vechi'], 'admin', nume: 'admin-sistem'),
            new Ruta('GET', '/admin/copii', [\Cazo\Controlere\AdminCompus::class, 'vechi'], 'admin', nume: 'admin-copii'),
            new Ruta('GET', '/admin/copii/restaurare', [AdminSistem::class, 'copiiRestaurare'], 'admin', nume: 'admin-copii-restaurare'),
            new Ruta('POST', '/admin/sistem/copii/{actiune:creeaza|descarca|restaureaza|sterge}', [AdminSistem::class, 'copii'], 'admin', nume: 'admin-sistem-copii'),
            new Ruta('POST', '/admin/sistem/pachet', [AdminSistem::class, 'pachet'], 'admin', nume: 'admin-sistem-pachet'),
            new Ruta('GET', '/admin/conexiuni', [AdminSistem::class, 'conexiuni'], 'admin', nume: 'admin-conexiuni'),
            new Ruta('POST', '/admin/conexiuni/{actiune:cheie|revino|anunturi|sincronizeaza}', [AdminSistem::class, 'conexiuniActiune'], 'admin', nume: 'admin-conexiuni-actiune'),
            // Adresa site-ului (trecerea pe subdomeniul cazo.ro rezervat pentru cheie).
            new Ruta('POST', '/admin/adresa/{actiune:treci|verifica|renunta}', [\Cazo\Controlere\AdminAdresa::class, 'actiune'], 'admin', nume: 'admin-adresa-actiune'),
            // site-ul fără cheie EasyCheck: proprietatea, camerele și prețurile, calendarul, trecerea.
            new Ruta('GET', '/admin/proprietate', [\Cazo\Controlere\AdminLocal::class, 'proprietate'], 'admin', nume: 'admin-proprietate'),
            new Ruta('POST', '/admin/proprietate', [\Cazo\Controlere\AdminLocal::class, 'proprietateSalveaza'], 'admin', nume: 'admin-proprietate-salveaza'),
            // dalele hărții din panou, prin serverul site-ului, din cache; doar cu sesiunea panoului.
            new Ruta('GET', '/admin/harta/{z:[0-9]{1,2}}/{x:[0-9]{1,7}}/{y:[0-9]{1,7}}.png', [\Cazo\Controlere\AdminLocal::class, 'dala'], 'tehnic', nume: 'admin-harta-dala'),
            new Ruta('GET', '/admin/camere-site', [\Cazo\Controlere\AdminLocal::class, 'camere'], 'admin', nume: 'admin-camere-site'),
            new Ruta('POST', '/admin/camere-site/{camera:[a-z0-9_]{1,40}}', [\Cazo\Controlere\AdminLocal::class, 'cameraSalveaza'], 'admin', nume: 'admin-camere-site-salveaza'),
            new Ruta('POST', '/admin/camere-site/{camera:[a-z0-9_]{1,40}}/retrage', [\Cazo\Controlere\AdminLocal::class, 'cameraRetrage'], 'admin', nume: 'admin-camere-site-retrage'),
            new Ruta('POST', '/admin/reduceri-site', [\Cazo\Controlere\AdminLocal::class, 'reduceriSalveaza'], 'admin', nume: 'admin-reduceri-site'),
            new Ruta('GET', '/admin/calendar', [\Cazo\Controlere\AdminLocal::class, 'calendar'], 'admin', nume: 'admin-calendar'),
            new Ruta('POST', '/admin/calendar/{actiune:adauga|citeste|sterge|inchide|deschide|jeton|interval}', [\Cazo\Controlere\AdminLocal::class, 'calendarActiune'], 'admin', nume: 'admin-calendar-actiune'),
            new Ruta('POST', '/admin/legatura/{actiune:spre-easycheck|camere|retrimite}', [\Cazo\Controlere\AdminLocal::class, 'legatura'], 'admin', nume: 'admin-legatura'),
            new Ruta('GET', '/admin/trecere', [\Cazo\Controlere\AdminTrecere::class, 'pagina'], 'admin', nume: 'admin-trecere'),
            new Ruta('POST', '/admin/trecere/{actiune:incepe|pas|calendar|treci|renunta}', [\Cazo\Controlere\AdminTrecere::class, 'actiune'], 'admin', nume: 'admin-trecere-actiune'),
            new Ruta('GET', '/admin/emailuri', [AdminSistem::class, 'emailuri'], 'admin', nume: 'admin-emailuri'),
            new Ruta('POST', '/admin/emailuri/{actiune:smtp|expeditor|proba|reia}', [AdminSistem::class, 'emailuriActiune'], 'admin', nume: 'admin-emailuri-actiune'),
            new Ruta('GET', '/admin/continut', [AdminSistem::class, 'continut'], 'admin', nume: 'admin-continut'),
            new Ruta('POST', '/admin/continut/{camera:[A-Za-z0-9_\-]{1,64}}', [AdminSistem::class, 'continutSalveaza'], 'admin', nume: 'admin-continut-salveaza'),

            // modul de pregătire și „Publică site-ul”.
            new Ruta('GET', '/admin/publicare', [Publicare::class, 'pagina'], 'admin', nume: 'admin-publicare'),
            new Ruta('POST', '/admin/publicare', [Publicare::class, 'trimite'], 'admin', nume: 'admin-publicare-trimite'),

            // Actualizările: doar anunțul versiunii noi de pe GitHub, în Setări › Întreținere.
            new Ruta('GET', '/admin/actualizari', [\Cazo\Controlere\AdminCompus::class, 'vechi'], 'admin', nume: 'admin-actualizari'),

            // API-ul intern al componentelor (calendarul, totalul), JSON.
            new Ruta('GET', '/_api/calendar', [ApiIntern::class, 'calendar'], 'tehnic', limbi: true, nume: 'api-calendar'),
            new Ruta('GET', '/_api/pret', [ApiIntern::class, 'pret'], 'tehnic', limbi: true, nume: 'api-pret'),

            // SEO.
            new Ruta('GET', '/sitemap.xml', [Seo::class, 'sitemap'], 'tehnic', nume: 'sitemap'),
            new Ruta('GET', '/robots.txt', [Seo::class, 'robots'], 'tehnic', nume: 'robots'),
            new Ruta('GET', '/.well-known/security.txt', [Seo::class, 'securitate'], 'tehnic', nume: 'security-txt'),
            new Ruta('GET', '/.well-known/cazo-adresa', [\Cazo\Controlere\AdminAdresa::class, 'sonda'], 'tehnic', nume: 'adresa-sonda'),

            // Cronul extern: token de 32 de octeți în base64url (43 de caractere); orice alt token primește 404.
            new Ruta('GET', '/_cron/{token:[A-Za-z0-9_-]{43}}', [Cron::class, 'extern'], 'tehnic', nume: 'cron'),

            // Anunțurile EasyCheck.
            new Ruta('POST', '/easycheck-anunt', [AnuntEasyCheck::class, 'primeste'], 'tehnic',
                faraCsrf: 'anunțul vine de la serverul EasyCheck, fără sesiune; îl autentifică semnătura HMAC (EasyCheck-Semnatura)', nume: 'easycheck-anunt'),

            // Anunțurile Stripe.
            new Ruta('POST', '/stripe-anunt', [Plata::class, 'anunt'], 'tehnic',
                faraCsrf: 'anunțul vine de la serverul Stripe, fără sesiune; îl autentifică semnătura HMAC (Stripe-Signature)', nume: 'stripe-anunt'),

            // Rapoartele CSP trimise de browser.
            new Ruta('POST', '/_csp', [RapoarteCsp::class, 'primeste'], 'tehnic',
                faraCsrf: 'raportul îl trimite automat browserul, fără formular și fără sesiune; cu limita de cereri contra abuzului', nume: 'csp'),
        ]);
    }
}
