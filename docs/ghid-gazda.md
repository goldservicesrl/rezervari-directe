# Ghidul gazdei

Acest ghid vă arată cum instalați și folosiți **Cazo**, software rezervări directe (pe scurt, „softul”): site-ul de rezervări directe pe care îl puneți pe domeniul dumneavoastră și îl legați de EasyCheck. Este scris pentru gazde, pe înțelesul oricui. Dacă știți să urcați un fișier în File Manager-ul din cPanel, puteți instala singur softul.

[English Version](ghid-gazda.en.md)

## Cuprins

1. [Ce este softul](#1-ce-este-softul)
2. [Ce vă trebuie](#2-ce-vă-trebuie)
3. [Instalarea pe cPanel, pas cu pas](#3-instalarea-pe-cpanel-pas-cu-pas)
4. [Legătura cu EasyCheck](#4-legătura-cu-easycheck)
5. [Plata online cu Stripe](#5-plata-online-cu-stripe)
6. [Transferul bancar și plata la sosire](#6-transferul-bancar-și-plata-la-sosire)
7. [Site-ul: aspectul, conținutul și publicarea](#7-site-ul-aspectul-conținutul-și-publicarea)
8. [Rezervările](#8-rezervările)
9. [Anulările și rambursările](#9-anulările-și-rambursările)
10. [Actualizările](#10-actualizările)
11. [Securitatea](#11-securitatea)
12. [Datele oaspeților (GDPR) și termenele de păstrare](#12-datele-oaspeților-gdpr-și-termenele-de-păstrare)
13. [Cronul](#13-cronul)
14. [Întrebări frecvente](#14-întrebări-frecvente)

---

## 1. Ce este softul

Softul transformă domeniul dumneavoastră într-un site de rezervări directe:

- oaspeții văd proprietatea, camerele, prețurile și zilele libere, aleg datele și rezervă pe loc;
- plătesc online, cu cardul și cu celelalte metode active în contul dumneavoastră Stripe, sau trimit o cerere cu plata la sosire;
- cu EasyCheck Pro, fiecare rezervare ajunge în EasyCheck, la fel ca rezervările venite de pe platformele de rezervări, deci calendarul rămâne unul singur.

Cum lucrează softul:

- **Softul este gratuit.** Codul sursă este public, sub Licența Cazo. Singura condiție a licenței este linkul „Software Regim Hotelier - Cazo.ro” din subsolul site-ului.
- **Site-ul este al dumneavoastră.** Îl instalați pe găzduirea dumneavoastră, pe domeniul dumneavoastră. Banii din plățile online intră direct în contul dumneavoastră Stripe.
- **Vine fără garanție.** Softul e oferit „ca atare”, fără nicio garanție, iar noi nu răspundem pentru felul în care îl folosiți. Condițiile complete sunt în Licența Cazo, la articolul 6. La instalare, le acceptați cu o bifă.
- **Merge și fără EasyCheck.** Folosit singur, site-ul își ține camerele, prețurile, avansul și politica de anulare în panoul lui, iar calendarul se leagă de platforme prin iCal.
- **Cu EasyCheck Pro, totul vine automat.** O cheie EasyCheck leagă site-ul de proprietatea dumneavoastră: camerele, prețurile, planurile tarifare, avansul, politica de anulare și disponibilitatea se setează în EasyCheck, iar site-ul le preia singur.

## 2. Ce vă trebuie

### Găzduirea

O găzduire obișnuită cu cPanel ajunge. Softul are nevoie de:

| Cerința | Valoarea |
|---|---|
| PHP | 8.2 sau mai nou (8.2, 8.3 și 8.4 sunt testate) |
| Extensiile PHP obligatorii | `pdo_mysql` sau `pdo_sqlite`, `curl`, `openssl`, `mbstring`, `json`, `zip`, `gd` |
| Extensiile PHP utile | `sodium` (site-ul merge și fără ea, cu biblioteca inclusă în soft; cu extensia, criptarea este mai rapidă), `intl`, `imagick`, `fileinfo` |
| Baza de date | MySQL sau MariaDB (recomandat); SQLite merge și el, după un test pe care îl face instalatorul |
| Serverul web | Apache (cu mod_php sau PHP-FPM), LiteSpeed sau OpenLiteSpeed |
| HTTPS | certificat valid pe domeniu (cel gratuit, Let's Encrypt sau AutoSSL, este suficient) |
| Cronul | recomandat (capitolul 13); site-ul merge și fără el |

Instalatorul verifică singur toate aceste cerințe. Când ceva lipsește, vă spune ce anume și vă dă un text gata de trimis firmei de găzduire.

**Găzduirea recomandată.** Dacă vă alegeți acum găzduirea, vă recomandăm [SiteBunker](https://client.sitebunker.net/aff.php?aff=1302): găzduire cu cPanel în România, cu tot ce cere softul (PHP 8.2 sau mai nou, MySQL, HTTPS gratuit, cron). Linkul SiteBunker e un link de afiliere: dacă vă faceți cont prin el, primim un comision, fără niciun cost în plus pentru dumneavoastră.

### Contul EasyCheck (opțional)

Site-ul merge și fără EasyCheck: la instalare alegeți **Completez datele aici, în site** și puneți proprietatea și camerele direct în site (capitolul 4, „Site-ul de sine stătător”). Pentru legătura automată vă trebuie un cont EasyCheck Pro cu proprietatea configurată: camerele, prețurile, planurile tarifare și politica de anulare. Cheia pentru site o creați în EasyCheck, la **Site web**, în varianta **Site Web / Acces API**. Vă recomandăm să începeți cu o cheie de probă: rezervările făcute cu ea rămân în spațiul de probă și se șterg singure.

### Contul Stripe (doar pentru plata online)

Pentru plata online vă trebuie un cont Stripe al dumneavoastră (firmă, PFA sau II). Dacă vreți doar transferul bancar sau plata la sosire, site-ul funcționează fără Stripe.

## 3. Instalarea pe cPanel, pas cu pas

Instalarea durează în jur de 10 minute.

### Înainte de instalare: baza de date

Dacă folosiți MySQL sau MariaDB (recomandat), creați întâi baza în cPanel, la **MySQL Databases**:

1. creați o bază nouă (de exemplu `cont_rezervari`);
2. creați un utilizator nou, cu o parolă lungă;
3. adăugați utilizatorul la bază, cu **ALL PRIVILEGES**.

Notați numele bazei, utilizatorul și parola: instalatorul vi le cere la pasul 3.

### Urcarea arhivei

1. Descărcați ultima versiune a arhivei (`.zip`) de pe pagina de versiuni a proiectului.
2. În cPanel, deschideți **File Manager** și intrați în folderul contului (`/home/<cont>/`, cel care conține `public_html`).
3. Urcați arhiva acolo și apăsați **Extract**.

Arhiva are două foldere:

- `public_html/`: fișierele publice ale site-ului (un singur fișier PHP, plus activele);
- `cazo-privat/`: codul și datele site-ului. Stă în afara folderului public, unde nimeni nu îl poate citi din browser.

Dacă domeniul are deja un site în `public_html`, mutați-l întâi într-un loc sigur sau folosiți un subdomeniu nou.

### Asistentul de instalare

Deschideți în browser `https://domeniul-dumneavoastră.ro/instalare/`. Asistentul e în română și în engleză; limba o alegeți pe primul ecran.

Din motive de siguranță, asistentul rămâne deschis cel mult 72 de ore de la prima accesare și se închide definitiv la finalul instalării.

**Pasul 1. Codul de instalare.** La prima vizită, asistentul scrie un cod de 8 caractere în fișierul `cazo-privat/COD-INSTALARE.txt`. Deschideți fișierul în File Manager și copiați codul în asistent. Așa doar cineva cu acces la cPanel poate instala site-ul.

**Pasul 2. Verificările.** Asistentul verifică versiunea PHP, extensiile, memoria și timpul de execuție, mărimea fișierelor încărcate, HTTPS, legătura securizată cu EasyCheck și cu Stripe, rescrierea adreselor și folderele în care trebuie să scrie. Fiecare problemă vine cu explicația ei și cu textul gata de trimis firmei de găzduire.

**Locul datelor** (în pasul 2). Asistentul alege unde stau datele site-ului:

- **varianta A** (preferată): folderul `cazo-privat` stă în afara folderului public;
- **varianta B**: când găzduirea lasă scrierea doar în `public_html`, asistentul mută datele într-un folder protejat din `public_html`.

Apoi face **sonda**: încearcă, din exterior, să citească fișierele care trebuie să rămână ascunse. Orice răspuns în afară de „acces interzis” sau „pagină inexistentă” oprește instalarea, cu pașii de urmat. Sonda se repetă zilnic după instalare.

**Cheia de criptare** (în pasul 2). Asistentul creează cheia cu care se criptează cheile de acces și datele oaspeților. Cheia stă în folderul privat; pasul se face singur.

**Pasul 3. Baza de date.** Scrieți datele bazei create mai sus. Asistentul testează conexiunea și crearea unui tabel, apoi pregătește baza. Pentru SQLite, asistentul testează întâi scrierea și blocarea fișierului.

**Pasul 4. EasyCheck sau datele din site.** Asistentul vă întreabă **Legați site-ul de EasyCheck?**. Cu **Completez datele aici, în site**, scrieți proprietatea (numele, felul, adresa, orele, regulile casei, contactul), regula de rezervare și prima cameră, cu prețul pe noapte și cel de weekend; restul camerelor, pozele și calendarele le adăugați apoi din panou. Cu **Da, cu cheia API EasyCheck**, lipiți cheia creată în EasyCheck. Asistentul vă arată proprietatea, modul cheii (probă sau live), sursa prețului și ce poate face cheia (capitolul 4). Apoi înregistrează anunțurile de la EasyCheck, trimite un anunț de probă și face prima sincronizare, cu o bară de progres. Pozele continuă să se descarce în fundal. După legare, câmpul cheii stă ascuns sub legătura **Folosiți altă cheie**: o cheie pusă acolo o înlocuiește pe cea de acum.

**Pasul 5. Adresa site-ului.** Fără cheie EasyCheck, site-ul folosește **Domeniul dumneavoastră**, iar asistentul verifică doar că adresa salvată pentru site este cea la care rulează. Cu cheie, alegeți **Domeniul dumneavoastră** sau **Subdomeniul gratuit cazo.ro** (capitolul 4, „Subdomeniul gratuit cazo.ro”). Când site-ul rulează deja pe un subdomeniu cazo.ro, asistentul întreabă EasyCheck dacă subdomeniul e rezervat pentru cheia dumneavoastră și îl leagă singur.

**Pasul 6. Datele legale și termenele de păstrare.** Cu cheie EasyCheck, datele firmei vin precompletate: denumirea, CUI-ul, registrul comerțului, sediul, certificatul de clasificare. Completați ce lipsește; instalarea continuă și cu câmpuri goale, iar panoul vă amintește de ele. Tot aici alegeți **cele patru termene de păstrare a datelor oaspeților** (capitolul 12). Câmpurile vin completate cu valorile recomandate; le păstrați sau le schimbați, între limitele arătate lângă fiecare.

**Pasul 7. Plățile.** Bifați cum plătesc oaspeții: **Cu cardul, online**, **Prin transfer bancar**, **La sosire (numerar)**, una sau mai multe (capitolele 5 și 6); cel puțin o bifă e obligatorie, iar cu mai multe oaspetele alege la rezervare. Pentru transfer scrieți contul bancar: titularul, IBAN-ul și banca. Plata la sosire apare doar la tarifele cu avans 0%: la un tarif cu avans, oaspetele plătește avansul la rezervare, cu cardul sau prin transfer bancar. Pentru plata cu cardul, lipiți cheia restricționată din Stripe; asistentul o verifică și creează singur punctul de anunț pentru plăți. La cheile cu „Prețul din EasyCheck”, tot aici alegeți **ce plătește oaspetele pe site** pe lângă nopți: taxele suplimentare din EasyCheck și taxa locală, cu momentul în care o încasați (capitolul 4).

**Pasul 8. Tema.** Vedeți temele randate pe loc cu numele, pozele și prețurile dumneavoastră, plus o propunere după tipul proprietății. Alegeți una; o puteți schimba oricând din panou.

**Pasul 9. Administratorul.** Scrieți adresa de e-mail, parola și limba panoului. Parola se verifică față de o listă de parole compromise. Vă recomandăm să porniți și verificarea în doi pași (2FA), cu o aplicație de autentificare și codurile de rezervă.

**Pasul 10. Cronul.** Copiați linia afișată în cPanel, la **Cron Jobs**, apoi apăsați **Verifică** (capitolul 13).

**Pasul 11. Gata.** Instalarea s-a încheiat, iar asistentul se închide definitiv. Site-ul intră în **modul de pregătire** (capitolul 7): îl vede oricine, motoarele de căutare îl ignoră, iar dumneavoastră vedeți banda „În pregătire”. Pagina „Ce urmează” vă arată ultimii pași: în EasyCheck, acceptați termenii pentru legarea site-urilor înainte de prima cheie live și treceți acolo adresa site-ului.

### Panoul site-ului

Panoul (`https://domeniul-dumneavoastră.ro/admin/`) are patru meniuri:

- **Acasă**: ce cere atenția azi. Alertele de rezolvat (numărul lor apare lângă „Acasă”, în meniu), publicarea site-ului cât e în pregătire, sosirile de azi și de mâine, cererile care așteaptă răspuns și starea site-ului: site-ul merge, sarcinile automate rulează, e-mailurile pleacă.
- **Site**: **Camerele** (la un singur apartament, **Apartamentul**): titlul, descrierea și pozele; **Pagini și repere**; **Aspect**: tema, cu culorile și fonturile ei; **Google și limbi**.
- **Rezervări și plăți**: **Rezervări**, o singură listă, cu ce s-a plătit, cum și ce rămâne de plătit la sosire pe fiecare rând, plus plățile online, avansurile și raportul lunar pentru contabilitate; **Cum plătesc oaspeții**: metodele de plată, contul bancar, cheia Stripe și ce plătește oaspetele pe site; **Mesajele către oaspeți**.
- **Setări**: **Legătura cu EasyCheck**, cu datele firmei, care vin din EasyCheck; **Legal și datele oaspeților**; **E-mail**; **Echipa**; **Întreținere**: actualizările, copiile de siguranță, jurnalul și diagnosticul pentru firma de găzduire.

Setările folosite rar stau pe pagina lor, sub **Avansat**: tema proprie, copia setărilor temei, adresele site-ului vechi, selectorul DKIM (doar când semnătura lipsește), numele serviciului de hărți, durata maximă a sejurului, confirmarea adresei de e-mail a oaspetelui, diagnosticul și tabelul sarcinilor automate. **Contul meu** e sus, în dreapta. Adresele vechi ale paginilor (de exemplu `/admin/poze`) duc la pagina nouă.

## 4. Legătura cu EasyCheck

### Ce preia site-ul din EasyCheck

Site-ul ține o copie locală a datelor din EasyCheck și o actualizează singur:

- proprietatea, descrierea, adresa, orele sejurului și regulile casei;
- camerele, cu numărul de oaspeți, spațiile (dormitoare, băi, bucătărie, paturi) și pozele, cu categoria lor;
- disponibilitatea, la câteva minute;
- prețurile, planurile tarifare, avansul și politica de anulare;
- datele legale ale operatorului și contactul publicat;
- notele de pe platforme, când EasyCheck le are.

Când schimbați ceva în EasyCheck, EasyCheck anunță site-ul, iar schimbarea apare pe site în câteva secunde. Dacă anunțurile nu ajung (de obicei din cauza unui firewall al găzduirii), site-ul se actualizează singur la câteva minute, iar panoul vă arată cauza probabilă și textul pentru firma de găzduire.

### Modul cheii: probă sau live

- **Cheia de probă:** rezervările și cererile ajung în spațiul de probă EasyCheck și se șterg după 24 de ore. Site-ul arată banda „Site de probă”, iar motoarele de căutare îl ignoră.
- **Cheia live:** rezervările ajung în calendarul real.

O cheie EasyCheck de probă merge doar cu o cheie Stripe de test, iar una live doar cu una Stripe live. Site-ul refuză amestecul.

### Sursa prețului

În EasyCheck alegeți, pentru cheie, de unde vine prețul:

- **„Prețul din EasyCheck”**: totalul fiecărui sejur îl calculează EasyCheck, exact ca pentru celelalte canale, cu planurile și reducerile setate acolo. Ce intră în total pe lângă nopți hotărâți în panoul site-ului, la **Rezervări și plăți › Cum plătesc oaspeții**, în blocul „Prețuri pe site”, la **Ce plătește oaspetele pe site**: bifa „Taxele suplimentare din EasyCheck (curățenia, lenjeria, taxa pe persoană)” și „Taxa locală”, cu trei variante: „Arătată în preț și încasată online, cu restul plății”, „Arătată în preț, dar încasată la sosire” sau „Nearătată în preț (o cereți separat, la sosire)”. Varianta încasată online apare doar cu plata cu cardul bifată. La instalare, taxele și taxa locală sunt arătate în preț. Ce rămâne nearătat în preț cereți separat, la sosire; taxa pentru animale o cereți mereu separat.
- **„Prețul de pe site-ul meu”**: totalul îl calculează site-ul, pornind de la grila de prețuri din EasyCheck, plus reducerile pe care le definiți în panoul site-ului (rezervare timpurie, ultimul moment, sejur lung). EasyCheck păstrează totalul exact cum îl primește. Când adăugați a doua reducere, panoul vă cere să alegeți dacă reducerile se cumulează sau se aplică doar cea mai mare.

### Site-ul de sine stătător (fără cheie EasyCheck)

Site-ul merge și singur, fără cheia API EasyCheck. Instalatorul vă întreabă, la pasul 4, „Legați site-ul de EasyCheck?”. Cu „Completez datele aici, în site”, completați chiar acolo proprietatea și prima cameră, iar restul din panou:

- **Site › Proprietatea**: numele, felul, descrierea în română și în engleză, adresa cu punctul de pe hartă, orele de check-in și check-out, regulile casei, contactul și **regula de rezervare**, aceeași pentru toate camerele: „Număr minim de nopți” (implicit 1), avansul în procente, câte zile înainte de check-in se poate anula gratuit și „Timpul pentru răspuns la o cerere”, între 2 și 72 de ore (implicit 24): cât timp aveți să acceptați sau să refuzați o cerere cu plata la sosire. Timpul nou se aplică cererilor primite de atunci; cele trimise deja își păstrează termenul;
- **Site › Camerele și prețurile**: fiecare cameră, cu numele, câte unități la fel aveți, câți oaspeți încap, descrierea și două prețuri: „Preț pe noapte” și „Preț pe noapte în weekend (vineri și sâmbătă)”. Totalul unui sejur e suma nopților: vinerea și sâmbăta cu prețul de weekend, celelalte nopți cu prețul pe noapte, rotunjit la leu. Fără taxa locală și fără alte suplimente în total. Pozele le încărcați la **Site › Camerele**, ca până acum. Tot aici, la **Reducerile**, aceleași pentru toate camerele: „Reducere pentru sejur lung” (de exemplu 10% de la 7 nopți) și „Reducere pentru rezervare din timp” (de exemplu 15% când check-in-ul e la cel puțin 30 de zile). Oaspetele vede tariful redus cu nota reducerii („Tarif standard, cu reducerea pentru sejururi de la 7 nopți”), în preț, în total și în e-mailuri. Când un sejur primește amândouă reducerile, se aplică cea mai mare. Lăsați procentul gol ca să opriți o reducere;
- **Rezervări și plăți › Calendarul**: adresele iCal ale platformelor (Booking, Airbnb, EasyCheck sau oricare alta), citite la fiecare 15 minute (intervalul se alege), adresa calendarului site-ului pentru fiecare cameră (o puneți pe platforme) și zilele pe care le închideți de mână.

Plățile merg ca în modul cu EasyCheck: cu cardul (Stripe), prin transfer bancar sau la sosire. Diferența: hotărârile sunt în panoul site-ului. Pe **Acasă** acceptați sau refuzați cererile cu plata la sosire și apăsați „Am primit banii” când ajunge un transfer. Cât termenul transferului curge, îl puteți prelungi cu o zi, 2, 3 sau 7 zile, cu „Prelungiți termenul”: zilele se adaugă la termenul de acum, iar oaspetele primește termenul nou pe e-mail. E-mailurile către oaspeți și către dumneavoastră pleacă de pe site. Plata la sosire apare doar când avansul e 0%.

**EasyCheck ca sursă iCal.** EasyCheck se leagă de site și prin iCal, la orice abonament: adresa iCal din EasyCheck o puneți în site, la Calendarul; adresa site-ului o puneți în EasyCheck, la „Orice alt calendar iCal”. Pe abonamentul Start, legătura prin iCal închide doar nopțile; prețurile și pozele vin în site doar prin cheia API, pe abonamentul Pro.

**Trecerea dintr-un mod în altul**, fără reinstalare, din **Setări › Legătura cu EasyCheck**, cu parola și o bifă de confirmare:

- *de la EasyCheck la site-ul de sine stătător*: site-ul păstrează camerele, pozele, textele, datele firmei și rezervările viitoare primite, toate editabile în panou; prețul pe noapte și cel de weekend se precompletează din tariful de bază, iar dumneavoastră le verificați și salvați fiecare cameră. Nopțile ocupate atunci în EasyCheck rămân închise, până puneți calendarul iCal din EasyCheck;
- *de la site-ul de sine stătător la EasyCheck*: site-ul trece pe datele din EasyCheck și trimite o singură dată în EasyCheck rezervările viitoare făcute pe site. Cele cu plata la sosire ajung acolo drept cereri, pe care le acceptați în EasyCheck. Ce nu poate trimite (de exemplu o cameră fără pereche în EasyCheck sau un preț calculat altfel acolo) apare pe aceeași pagină, cu motivul; pe acestea le adăugați direct în calendarul EasyCheck.

### Subdomeniul gratuit cazo.ro

Cu **EasyCheck Pro**, site-ul poate folosi gratuit o adresă de forma `numele-ales.cazo.ro`, una pe cheie. Subdomeniul se rezervă doar în EasyCheck, la **Site web**, la cheia site-ului; Cazo îl recunoaște și îl leagă, fără să-l rezerve el. Pașii, în ordine:

1. **Rezervarea.** În EasyCheck, la **Site web**, la cheia site-ului, scrieți numele dorit (litere mici, cifre și cratimă, între 3 și 40 de caractere) și rezervați-l.
2. **Găzduirea.** La firma de găzduire, adăugați subdomeniul ca domeniu al site-ului (în cPanel, la **Domenii**), cu dosarul în care urcați Cazo.
3. **IP-ul.** Puneți în EasyCheck, la aceeași cheie, IP-ul serverului de găzduire (îl găsiți în cPanel, la **Server Information**, sau îl cereți firmei de găzduire). EasyCheck trimite apoi adresa spre serverul dumneavoastră.
4. **Instalarea.** Deschideți `https://numele-ales.cazo.ro/instalare/` și urmați asistentul. La pasul **Adresa site-ului**, asistentul recunoaște subdomeniul rezervat și îl leagă.

Un site instalat deja pe domeniul dumneavoastră trece pe subdomeniul rezervat din panou, la **Setări**, **Legătura cu EasyCheck**, **Adresa site-ului**: butonul **Treceți site-ul pe …** adaugă singur subdomeniul în găzduire, acolo unde găzduirea permite asta fără parole; altfel vă arată pașii din cPanel. Apoi site-ul verifică singur, la câteva minute, când adresa nouă răspunde pe HTTPS și trece pe ea, iar adresa veche trimite vizitatorii spre cea nouă. După trecere, intrați în panou pe adresa nouă.

### Trecerea rezervărilor de pe pagina EasyCheck pe site

Pe o proprietate, oaspeții rezervă direct într-un singur loc: pe **pagina EasyCheck** sau pe **site** (varianta **Site Web / Acces API**). Cât pregătiți site-ul, pagina EasyCheck rămâne activă. EasyCheck urmărește singur pregătirea, la **Site web**: cheia creată, primul răspuns al site-ului, instalarea încheiată (site-ul o anunță singur la finalul asistentului), o rezervare de probă și cheia live folosită de site. Când toate sunt gata, apare butonul **Treceți rezervările pe site**: pagina EasyCheck trimite apoi vizitatorii pe site.

Când aduceți rezervările înapoi pe pagina EasyCheck, cheile live ale site-ului se revocă: site-ul arată mai departe prezentarea, iar la rezervare oaspeții citesc „Rezervările sunt oprite momentan.” și datele dumneavoastră de contact. Panoul site-ului vă explică ce s-a întâmplat.

### Ce poate face cheia

Dacă cheia permite doar citirea, site-ul arată prezentarea și contactul, fără rezervări online. Instalatorul și panoul vă arată pașii pentru o cheie nouă, cu dreptul de rezervare.

### Când cheia nu mai e activă

- Dacă EasyCheck respinge cheia de două ori la rând, la cel puțin 5 minute distanță, rezervările online se opresc, iar dumneavoastră primiți un e-mail și o alertă în panou. Prezentarea rămâne pe site, din copia locală, fără limită de timp, iar oaspeții citesc „Rezervările sunt oprite momentan.” și datele dumneavoastră de contact. Se întâmplă de obicei după ce rezervările directe revin pe pagina EasyCheck (cheile live se revocă atunci). Creați o cheie nouă în EasyCheck, la **Site web**, și puneți-o în panou, la **Setări**, **Legătura cu EasyCheck**.
- Dacă EasyCheck suspendă accesul (contul, proprietatea sau abonamentul), prezentarea rămâne, rezervările se opresc, iar site-ul verifică starea cheii din 30 în 30 de minute. După reactivare, rezervările revin singure. Primiți un e-mail și la oprire, și la revenire.

## 5. Plata online cu Stripe

Capitolele 5-9 descriu site-ul legat de EasyCheck. Fără EasyCheck, plățile și rezervările merg la fel, dar cererile, transferurile și anulările le hotărâți în panoul site-ului (capitolul 4, „Site-ul de sine stătător”).

### Metodele de plată

Metodele le alegeți dumneavoastră, la instalare și apoi în panou, cu trei bife: **Cu cardul, online**, **Prin transfer bancar** (capitolul 6) și **La sosire (numerar)**. Bifați una sau mai multe; cel puțin una e obligatorie. Cu mai multe bifate, oaspetele alege la rezervare cum plătește; plata la sosire apare doar la tarifele cu avans 0%. Până alegeți, site-ul arată doar prezentarea: oaspeții văd tot, dar încă nu pot rezerva.

Cu „Cu cardul, online”, oaspetele plătește pe pagina securizată Stripe, cu toate metodele active în contul dumneavoastră Stripe (card, portofele electronice și celelalte pe care le-ați pornit în Dashboard). Banii intră direct în contul dumneavoastră Stripe. Site-ul nu vede și nu păstrează datele cardului.

### Cheia restricționată

Site-ul lucrează cu o **cheie restricționată** Stripe (începe cu `rk_test_` sau `rk_live_`), cu exact drepturile de care are nevoie. Cheia secretă completă (`sk_`) se refuză. Creați cheia în Stripe Dashboard, la **Developers › API keys › Create restricted key**, cu aceste permisiuni:

| Resursa | Permisiunea |
|---|---|
| Checkout Sessions | Write |
| Payment Intents | Write |
| Refunds | Write |
| Charges | Read |
| Events | Read |
| Webhook Endpoints | Write (opțional: așa site-ul își creează singur punctul de anunț) |
| Disputes | Read (opțional) |
| Balance Transactions | Read (opțional) |
| restul | None |

În Stripe, fiecare resursă are o singură alegere: None, Read sau Write. Write o cuprinde și pe Read.

Panoul verifică fiecare permisiune și vă arată o listă verde sau roșie. Verificarea se repetă zilnic. Contul Stripe ar trebui să aibă moneda RON; panoul vă avertizează la altă monedă.

Începeți cu cheia de test (`rk_test_`) și faceți câteva rezervări de probă. Cheia live o puneți doar după ce site-ul are HTTPS valid.

### Anunțurile de plată

Site-ul creează singur, în contul Stripe, punctul de anunț `https://domeniul-dumneavoastră.ro/stripe-anunt` (în Stripe se numește webhook). Cu el, plățile se confirmă în câteva secunde. Dacă cheia nu are permisiunea pentru Webhook Endpoints, panoul vă dă adresa și lista de evenimente pentru crearea manuală, plus câmpul pentru secretul de semnare. Plățile se confirmă și fără anunțuri, puțin mai încet.

### Avansul

Avansul îl setați în EasyCheck, pe planul tarifar, ca procent din total:

- **100%**: oaspetele plătește tot acum;
- **între 1% și 99%**: la „Cât plătiți acum”, oaspetele alege cât plătește acum: avansul (restul la sosire), ales implicit, sau toată suma;
- **0%**: oaspetele nu plătește nimic la rezervare, iar rezervarea pleacă drept cerere cu plata la sosire, pe care o acceptați în EasyCheck.

### Comisionul Stripe

Plata se încasează imediat. Dacă EasyCheck refuză apoi rezervarea (de exemplu, s-a ocupat noaptea chiar în acel moment), site-ul returnează automat toată suma, iar comisionul Stripe rămâne la dumneavoastră.

### Când plata online se oprește

Plata online se oprește singură, temporar, în câteva situații: un plan tarifar fără politică de anulare, o cheie Stripe care nu trece verificarea, date prea vechi de la EasyCheck, protecția automată la abuz. Primiți imediat un e-mail cu motivul și cu ce aveți de făcut, plus alerta din panou, și încă un e-mail când plata revine. Dacă ați ales doar „Cu cardul, online”, oaspeții pot trimite între timp o cerere cu plata la sosire.

### Verificarea în doi pași

Când puneți cheia live, panoul vă recomandă să porniți verificarea în doi pași (2FA). Plățile live pornesc și fără ea, dar vă recomandăm s-o folosiți.

## 6. Transferul bancar și plata la sosire

### Transferul bancar

Cu bifa **Prin transfer bancar**, oaspetele rezervă pe site și plătește avansul (sau tot) în contul dumneavoastră bancar. În panou, la **Transferul bancar**, scrieți titularul contului, IBAN-ul, banca și, pentru plățile din străinătate, codul SWIFT/BIC. Tot acolo alegeți:

- **termenul de plată**, între 12 și 120 de ore: cât timp are oaspetele să plătească. După termen, rezervarea se anulează și nopțile se eliberează;
- **până cu câte zile înainte de sosire** e oferit transferul (între 0 și 30). La o sosire mai apropiată, oaspetele vede doar celelalte metode.

Oaspetele primește pe e-mail contul, suma, termenul și codul rezervării, pe care îl trece la detaliile plății. Când banii ajung, confirmați primirea: cu EasyCheck, la Cererile de pe site, cu **Am primit banii**; fără EasyCheck, în panou, pe pagina rezervării, tot cu **Am primit banii**. Atunci oaspetele primește confirmarea rezervării. Dacă banii nu vin până la termen, oaspetele află pe e-mail că rezervarea a rămas neconfirmată.

### Plata la sosire

Cu bifa „La sosire (numerar)” (singură sau împreună cu cardul, când oaspetele alege plata la sosire), oaspetele trimite o **cerere de rezervare**:

1. cererea ajunge în EasyCheck, cu termenul de răspuns pe care l-ați setat acolo (2, 12, 24 sau 48 de ore); fără EasyCheck, ajunge în panou, pe **Acasă**, cu termenul ales la Proprietatea;
2. o acceptați sau o refuzați în EasyCheck sau, fără EasyCheck, în panou;
3. oaspetele primește răspunsul pe e-mail: confirmarea, cu plata la sosire, sau refuzul, cu legătura spre alte date libere.

Bine de știut:

- La plata la sosire nu se încasează avans, deci la o anulare nu aveți ce reține. Dacă vreți un avans reținut la anulare, folosiți plata cu cardul, pe un plan tarifar cu avans.
- Oaspetele își poate anula singur rezervarea cu plata la sosire, din „Rezervarea mea”, până în ziua sosirii inclusiv. După ziua sosirii, butonul dispare, iar o plecare mai devreme o stabiliți direct cu oaspetele.
- Pentru protecție la abuz, site-ul poate cere un cod de 6 cifre, trimis pe e-mailul oaspetelui, înaintea cererii. Codul e oprit implicit, pornește singur la abuz și îl puteți porni sau opri din panou. Tot acolo bifați la ce metode de plată se cere: implicit la plata la sosire și la transfer bancar; la plata cu cardul îl bifați doar dacă vreți.
- Durata maximă a sejurului pe site e implicit 30 de nopți. O schimbați la **Rezervări și plăți › Cum plătesc oaspeții › Avansat**: orice număr de nopți, de la 1 în sus, sau câmpul gol, pentru un sejur oricât de lung.

## 7. Site-ul: aspectul, conținutul și publicarea

### Modul de pregătire și publicarea

După instalare, site-ul e în modul de pregătire: îl vede oricine are adresa, motoarele de căutare îl ignoră, iar dumneavoastră, autentificat în panou, vedeți banda „În pregătire”.

Când totul e gata, apăsați **Publică site-ul** (în panou, pe **Acasă**, în blocul „Publicarea site-ului”). Cu toate setările completate (datele legale și datele de vânzare), publicarea se face dintr-un clic. Puteți publica și cu setări lipsă, pe răspunderea dumneavoastră: panoul vă arată, pentru fiecare câmp cerut de lege, ce lipsește și ce risc are, iar dumneavoastră confirmați „Îmi asum publicarea cu aceste date lipsă”. Alerta rămâne în panou până la completare.

### Temele

Softul vine cu teme gata făcute, fiecare cu alt stil. În panou, la **Aspect**:

- vedeți fiecare temă cu datele dumneavoastră, inclusiv pagina de comandă și „Rezervarea mea”;
- personalizați culorile, fonturile și secțiunile, cu previzualizare;
- reveniți oricând la o variantă anterioară;
- încărcați o temă făcută de altcineva; panoul o verifică înainte de activare.

Indiferent de temă, prețul, plata, textele legale, calendarul și formularul de comandă rămân ale softului: tema le aranjează și le colorează, fără să le poată schimba sau ascunde.

### Conținutul

Cu EasyCheck, descrierea proprietății, camerele, pozele, spațiile camerelor, orele sejurului și regulile casei vin din EasyCheck. În panou, la **Site › Camerele** (la un singur apartament, **Site › Apartamentul**), completați ce ține doar de site:

- titlurile și descrierile camerelor în engleză;
- pozele încărcate direct în site, pentru galerie și secțiuni, cu categoria lor;
- paginile proprii (ghid, întrebări frecvente) și meniurile;
- textele scurte cerute de temă: „Cum ajungeți”, „Despre gazdă”, punctele de reper din apropiere.

Textele sunt opționale și scurte. Lângă fiecare vedeți regula: scrieți despre zone și repere, fără materiale, mărci sau dimensiuni.

### Limbile

Site-ul e în română și în engleză: româna la adresa principală, engleza sub `/en/`. Panoul vă arată ce traduceri mai lipsesc.

### Datele legale

Site-ul afișează în subsolul fiecărei pagini datele cerute de lege: denumirea, CUI-ul, registrul comerțului, sediul, capitalul social, certificatul de clasificare, contactul și legătura spre soluționarea alternativă a litigiilor (ANPC). Datele vin din EasyCheck; în panou, la **Legal**, completați ce lipsește.

Paginile legale (termenii și condițiile, confidențialitatea cu cookie-urile, accesibilitatea, datele legale) și subsolul pornesc de la modele completate din datele dumneavoastră. În panou, la **Setări › Legal și datele oaspeților**, le puteți schimba integral, ca să le adaptați la o lege nouă: vedeți diferențele față de model și puteți reveni oricând la modelul actual. Când o versiune nouă a softului schimbă un model, panoul vă arată ce s-a schimbat, iar textul dumneavoastră rămâne pe site. Răspunderea pentru texte vă aparține; vă recomandăm să le citiți cu un jurist înainte de publicare.

### E-mailurile

Site-ul trimite e-mailurile către oaspeți (confirmarea, cererea, anularea) și către dumneavoastră (rezervările noi, alertele). În panou, la **Setări › E-mail** (textele mesajelor, la **Rezervări și plăți › Mesajele către oaspeți**):

- dacă vreți, setați serverul SMTP (cu indicațiile pentru cPanel); fără el, site-ul trimite prin funcția de e-mail a găzduirii;
- alegeți expeditorul e-mailurilor către oaspeți: adresa site-ului (de exemplu `rezervari@domeniul-dumneavoastră.ro`, cu răspunsurile trimise la dumneavoastră), adresa dumneavoastră sau altă adresă;
- verificați SPF, DKIM și DMARC, ca e-mailurile să nu ajungă în spam;
- vedeți previzualizarea, trimiteți un e-mail de probă și urmăriți coada.

Adresele Gmail sau Yahoo trimise de pe alt server ajung des în spam. Folosiți, de preferat, o adresă pe domeniul site-ului.

Răspunsurile oaspeților la e-mailurile site-ului ajung la dumneavoastră. Un mesaj scris direct la adresa site-ului (de exemplu `rezervari@domeniul-dumneavoastră.ro`) ajunge însă în căsuța implicită a găzduirii. Ca să-l primiți, în cPanel, la **Email › Forwarders**, faceți o redirecționare de la adresa site-ului spre adresa pe care o citiți.

E-mailurile către dumneavoastră și colegii dumneavoastră pleacă în limba panoului fiecăruia.

## 8. Rezervările

### Ce face oaspetele

1. Alege datele și numărul de oaspeți. Site-ul caută pe toate camerele și arată totalul pe sejur, cu componentele la un clic, plus politica de anulare a fiecărui plan.
2. Completează o singură pagină de comandă: nume, e-mail, telefon, țară, plus o notă opțională pentru dumneavoastră (de exemplu pentru micul dejun sau un pat suplimentar).
3. Plătește online sau trimite cererea cu plata la sosire.
4. Primește pe e-mail confirmarea, care e și contractul, cu fișierul de calendar (`.ics`) și legătura „Rezervarea mea”.

Totul funcționează și fără JavaScript, și de pe telefon.

### Ce primiți dumneavoastră

- Rezervarea apare în EasyCheck, ca oricare alta.
- Primiți un e-mail scurt la fiecare rezervare plătită.
- În panou, la **Rezervări și plăți › Rezervări**, vedeți toate comenzile, cu ce s-a plătit și ce rămâne de plătit la sosire, și istoricul fiecăreia.

### Verificările dinaintea plății

Înainte de plată, site-ul recitește totalul și disponibilitatea. Dacă totalul s-a schimbat, oaspetele vede noul total și butonul „Continuați cu noul total”; plătește doar o sumă pe care a văzut-o. După plată, site-ul verifică încă o dată disponibilitatea și trimite rezervarea în EasyCheck.

### Când ceva nu merge

| Situația | Ce se întâmplă |
|---|---|
| Noaptea s-a ocupat chiar în momentul plății | site-ul returnează automat toată suma și îi arată oaspetelui camerele libere pentru aceleași date |
| EasyCheck refuză rezervarea (preț schimbat, sejur minim) | site-ul returnează automat toată suma; la un preț schimbat, oaspetele poate continua cu noul total |
| EasyCheck nu răspunde | site-ul reîncearcă timp de 6 ore, apoi verifică dacă rezervarea a ajuns totuși; dacă nu a ajuns, returnează suma și îl anunță pe oaspete |
| Rezultatul nu se poate afla | banii rămân neatinși, oaspetele primește mesajul „Verificăm rezervarea direct cu gazda”, iar după 24 de ore dumneavoastră primiți alerta și decideți |
| Două rezervări pe aceeași noapte | oaspetele primește confirmarea normală; dumneavoastră primiți alerta și rezolvați în EasyCheck, ca la platforme |

### Protecția la abuz

Site-ul are limite pentru comenzi, plăți și cereri, plus o protecție automată: când site-ul vede semne de abuz, rezervările online se opresc, oaspeții văd datele dumneavoastră de contact, la cererile cu plata la sosire pornește codul de 6 cifre, iar dumneavoastră primiți un e-mail. Limitele se pot schimba din panou. Rezervările online le reporniți dumneavoastră din panou, la **Cereri**, cu „Reporniți rezervările”, după ce trece valul. Doar codul de 6 cifre pornit automat se oprește singur, după 24 de ore fără comenzi peste prag, iar dumneavoastră primiți e-mailul „Codul pe e-mail pentru cererile cash s-a oprit”.

## 9. Anulările și rambursările

### Unde hotărâți: în EasyCheck sau în panou

Cât site-ul e legat cu o cheie EasyCheck, iar programul site-ului și EasyCheck știu amândouă să lucreze împreună, rezervările, banii și mesajele către oaspeți se hotărăsc în EasyCheck. Panoul site-ului le arată, dar nu le mai schimbă:

- pe **Acasă** vedeți rândul „Rezervările, banii și mesajele se hotărăsc în EasyCheck.”, cu butonul **Deschideți EasyCheck**;
- la **Rezervări**, fiecare rezervare are **Deschideți în EasyCheck**. Acolo anulați, hotărâți cererea de anulare a oaspetelui, returnați bani pe card și retrimiteți confirmarea. Rambursarea o face site-ul, din contul dumneavoastră Stripe, și primiți un e-mail la fiecare;
- plățile online devin **Jurnalul plăților cu cardul**, doar de citit; raportul lunar pentru contabilitate, cu comisioanele Stripe, rămâne;
- la **Cum plătesc oaspeții** găsiți comutatorul **Primesc rambursări comandate din EasyCheck**, pornit de la început, și plafonul pe zi (5 rambursări și 5.000 de lei), pe care le schimbați cu parola. Dreptul „Poate face rambursări” dispare din panou: în EasyCheck, rambursările le face cine are permisiunea „Rambursări pe card”.

Pe pagina rezervării rămân **Reluați finalizarea acum** și **Am rezolvat plata în contul Stripe**, cu care reparați o comandă rămasă la jumătate pe site.

Panoul rămâne cel descris mai jos, cu toate hotărârile în el, când site-ul nu are cheie EasyCheck, când EasyCheck nu are încă aceste funcții și când programul site-ului nu e actualizat. Pe o cheie de probă, pagina unei rezervări de probă are butoane care țin locul gazdei din EasyCheck (de exemplu „Returnați pe card (probă)” sau „Retrimiteți confirmarea (probă)”), ca să încercați tot drumul fără bani adevărați.

### Anularea de către oaspete

- **În perioada de anulare gratuită** (stabilită în EasyCheck pe planul tarifar): oaspetele anulează singur din „Rezervarea mea”. Pe aceeași pagină vede condițiile și bifează „Confirm că vreau să anulez rezervarea.”; dacă între timp condițiile s-au schimbat, pagina i le arată din nou, iar anularea așteaptă confirmarea lui. Site-ul anulează rezervarea în EasyCheck și returnează automat toată suma plătită. Primiți și dumneavoastră e-mailul.
- **După perioada gratuită**: oaspetele vede condițiile și vă trimite o cerere de anulare. În panou, la **Rezervări**, decideți: **Păstrați rezervarea** (fără parolă, fiindcă nu se mută bani) sau o anulați și alegeți suma returnată, de la zero la toată suma plătită (cu parola).
- **Rezervările cu plata la sosire**: oaspetele le anulează singur, până în ziua sosirii inclusiv (capitolul 6).

### Anularea făcută de dumneavoastră în EasyCheck

Când anulați în EasyCheck o rezervare venită de pe site, site-ul vede anularea, iar comanda apare în panou ca **„Anulată: alegeți suma returnată”**. Banii rămân neatinși până decideți dumneavoastră cât returnați:

- panoul vă arată cât s-a plătit online și cât e încă în contul Stripe, plus propunerea după regula tarifului (cât returnați și cât rețineți);
- alegeți **Returnați toată suma**, **Păstrați toată suma** sau scrieți altă sumă și apăsați **Încheiați anularea cu suma scrisă**;
- oaspetele primește e-mailul anulării după decizia dumneavoastră.

Până decideți, vedeți alerta în panou, iar după o zi primiți pe e-mail o amintire. Regula se aplică oricând, și după sosire (de exemplu la o neprezentare).

Când anularea vine din motive care țin de dumneavoastră (de exemplu o suprarezervare sau o defecțiune), oaspetele are dreptul la toată suma. Reținerea după regula tarifului se potrivește când oaspetele a renunțat la sejur sau a absentat în ziua sosirii.

O anulare pornită de pe site și încheiată în EasyCheck (de exemplu, o anulare aprobată în panou cu 700 de lei returnați dintr-o plată de 1.000) se încheie cu suma aleasă pe site.

### Rambursările din panou

Din panou, la **Rezervări**, returnați toată suma sau o parte, și fără anulare. Alegeți motivul dintr-o listă (cererea oaspetelui, o problemă la cazare, un gest comercial, alt motiv) și, dacă vreți, scrieți o notă internă, păstrată criptat. Oaspetele primește un e-mail la fiecare rambursare reușită. După o anulare, puteți returna din panou și suma reținută, toată sau o parte.

Rambursările le fac proprietarul și angajații cărora proprietarul le-a dat acest drept, în panou, la **„Cine poate face rambursări”** (pe pagina **Cum plătesc oaspeții**). Fiecare rambursare cere reconfirmarea parolei. Lista plăților o văd doar proprietarul și angajații cu acest drept; exportul plăților pentru contabil și lista avansurilor încasate pentru sejururi viitoare le vede doar proprietarul.

Comisionul Stripe al plății inițiale rămâne la Stripe și la o rambursare.

### Rambursările care întârzie

- **O rambursare eșuată** (de exemplu, soldul Stripe e prea mic): site-ul reîncearcă de 3 ori în 24 de ore, apoi primiți alerta, cu pașii din Stripe Dashboard.
- **O rambursare care rămâne în procesare la bancă peste 48 de ore**: primiți alerta pe e-mail și în panou, apoi câte o amintire pe zi, până se rezolvă. De obicei, cauza e soldul din contul Stripe; site-ul vede singur rezultatul.

### Schimbarea datelor

Oaspetele își poate schimba singur datele **o singură dată** pe rezervare, din „Rezervarea mea”, cu butonul **Schimbați datele**, până la termenul de anulare gratuită inclusiv. După termen, oaspetele vă contactează direct.

- Site-ul verifică disponibilitatea pentru noile date și recalculează prețul.
- **Noul sejur e mai scump:** oaspetele plătește diferența cu cardul, pe loc, înainte de schimbare.
- **Noul sejur e mai ieftin:** site-ul returnează automat diferența.
- **La plata la sosire:** se schimbă doar totalul de plătit la sosire.
- Regula de anulare din ziua rezervării rămâne, calculată după noile date: termenul gratuit, avansul, tariful nerambursabil.

În EasyCheck, schimbarea apare ca o rezervare nouă și anularea celei vechi; pentru oaspete rămâne aceeași comandă, cu aceeași legătură „Rezervarea mea”. Dacă noile date se suprapun cu cele vechi și rezervarea nouă nu reușește, site-ul reface rezervarea veche și îl anunță pe oaspete.

## 10. Actualizările

Versiunile noi se publică pe pagina de versiuni a proiectului, pe GitHub, și se anunță pe cazo.ro. Site-ul nu se actualizează singur și nu descarcă nimic: o dată pe zi citește fișierul public `https://cazo.ro/versiune.json`, iar când apare o versiune mai nouă, panoul arată anunțul „Există o versiune nouă”, pe **Acasă** și la **Setări › Întreținere**, cu data, ce aduce și legătura de descărcare. Proprietarii site-ului primesc și un e-mail, o singură dată pentru fiecare versiune. Cererea spre cazo.ro nu conține date despre site sau despre oaspeți.

Actualizarea o faceți dumneavoastră, din File Manager:

1. În panou, la **Setări › Întreținere**, faceți o copie a bazei de date.
2. Descărcați arhiva versiunii noi (`.zip`) de pe pagina de versiuni și dezarhivați-o pe calculator.
3. În arhivă, în `cazo-privat/lansari/`, găsiți folderul versiunii noi (de exemplu `0.2.0`). Urcați-l în folderul `lansari/` din folderul privat al site-ului (la varianta A, `cazo-privat`, lângă `public_html`, sau `pragul-privat` la instalările făcute înainte ca softul să se numească Cazo; la varianta B, folderul cu nume greu de ghicit din `public_html`).
4. Înlocuiți fișierul `curent.php` din folderul privat al site-ului cu cel din arhivă. Din acest moment, site-ul rulează versiunea nouă; la prima vizită își aplică singur schimbările bazei de date.
5. Deschideți câteva pagini ale site-ului și panoul. Dacă ceva nu merge, puneți la loc vechiul `curent.php`: versiunea anterioară rămâne în `lansari/`.

Fișierele din `public_html` și linia cronului nu se schimbă la actualizare. Folderele versiunilor vechi din `lansari/` le puteți șterge după ce versiunea nouă merge.

## 11. Securitatea

- **Un singur fișier PHP public.** Restul codului și toate datele stau în folderul privat, verificat la instalare și zilnic de sonda de protecție.
- **Datele sensibile sunt protejate:** cheile de acces și datele oaspeților sunt criptate, iar parolele se păstrează doar ca amprentă.
- **Panoul** cere parolă, verificarea în doi pași pentru fiecare utilizator care o pornește și reconfirmarea parolei pentru operațiile importante. Autentificarea are limite la încercările greșite.
- **Rolurile:** proprietarul are acces la tot; angajații văd rezervările, cererile și conținutul. Dreptul de rambursare îl dă proprietarul fiecărei persoane în parte.
- **Jurnalul de audit** păstrează operațiile importante, într-un lanț care se poate verifica.
- **Copiile de siguranță:** site-ul face zilnic o copie a bazei (ultimele 7 zile), iar înaintea unei actualizări faceți una și din panou. Le găsiți la **Setări › Întreținere**.

Cine are acces complet la contul dumneavoastră cPanel are acces și la site. Păstrați parola cPanel în siguranță și porniți verificarea în doi pași și acolo, dacă găzduirea o oferă.

## 12. Datele oaspeților (GDPR) și termenele de păstrare

### Cine răspunde de date

Dumneavoastră sunteți operatorul datelor oaspeților. Softul rulează pe găzduirea dumneavoastră și nu trimite date de utilizare (fără telemetrie). Datele unei rezervări pleacă doar spre EasyCheck, ca persoană împuternicită, și, la plata online, spre Stripe. Comanda păstrează doar datele necesare rezervării; fișa de cazare o completează oaspetele prin check-in-ul online EasyCheck.

### Cele patru termene de păstrare

Termenele le alegeți dumneavoastră, la instalare și apoi în panou, la **Setări › Legal și datele oaspeților**. Câmpurile vin completate cu valorile recomandate; le păstrați sau le schimbați, între limitele de mai jos:

| Termenul | Limitele | Recomandat |
|---|---|---|
| **rezervările confirmate**, după plecare | 180-1095 de zile | 1095 de zile (3 ani) |
| **comenzile abandonate** (începute și lăsate la jumătate, ori cu plata refuzată de bancă) | 24-720 de ore | 72 de ore |
| **comenzile rambursate sau respinse** (banii returnați, cererile refuzate sau expirate) | 30-1095 de zile | 180 de zile |
| **adresa IP completă din acorduri** | 6-36 de luni | 36 de luni (3 ani) |

Când trece termenul, site-ul anonimizează datele: rămân doar codul comenzii, datele sejurului, sumele și identificatorii plăților, pentru contabilitate. Odată cu anonimizarea se închide și pagina „Rezervarea mea” a comenzii.

Dacă ați instalat site-ul înainte de aceste limite și un termen al dumneavoastră e în afara lor, el rămâne în vigoare până îl schimbați; panoul vă arată o avertizare lângă câmp.

Tot aici alegeți cât păstrează panoul **jurnalul alertelor**: între 30 și 3650 de zile, de la început 365.

Pentru termenul care vi se potrivește, întrebați contabilul.

### Cookie-urile

Implicit, site-ul folosește doar cookie-urile strict necesare (comanda în curs și panoul), deci funcționează fără banner. Dacă porniți Google Analytics (opțional, oprit implicit), apare bannerul de cookie-uri, cu „Refuz” la fel de vizibil ca „Accept”.

### Drepturile oaspeților

Din panou, la **Setări › Legal și datele oaspeților**, găsiți datele unui oaspete după adresa de e-mail, le exportați și le ștergeți. Fiecare operație intră în jurnalul de audit.

### Acordul pentru noutăți

Pe pagina de comandă, oaspetele poate bifa acordul pentru noutăți (căsuța e nebifată implicit). Site-ul păstrează acordul cu textul și versiunea lui, iar oaspetele îl retrage cu un clic, din „Rezervarea mea” sau din orice ofertă primită.

Abonații îi găsiți în panou, la **Rezervări și plăți › Abonații la oferte** (pagina e a proprietarului): câți sunt, numele, adresa, limba, de unde vine acordul și data lui. De acolo scoateți o adresă din listă și descărcați lista ca fișier CSV (cu parola).

Tot acolo scrieți o ofertă: subiectul și textul, cu un rând gol între paragrafe. Salutul, semnătura și linkul de dezabonare le adaugă site-ul. Previzualizați oferta, trimiteți-vă o probă, apoi bifați confirmarea și trimiteți-o tuturor abonaților (cu parola). Ofertele pleacă prin cutia de e-mailuri a site-ului, treptat, cel mult 60 pe oră, ca serverul de e-mail să le livreze în siguranță (valoarea se schimbă în `config.php`, cu `oferte_pe_ora`). Fiecare mesaj are linkul personal de dezabonare, pe care oaspetele îl folosește direct, fără cont, și antetele de dezabonare cu un clic pe care Gmail și Outlook le arată lângă expeditor. Pagina ține jurnalul trimiterilor: data, subiectul, câți destinatari, câte mesaje au plecat și câte au eșuat. Pentru trimiteri către mulți abonați vă recomandăm serverul SMTP al domeniului (**Setări › E-mail › Opțional: trimiterea prin contul de e-mail al domeniului**).

Cu cheia EasyCheck, când EasyCheck are pornită lista abonaților, acordul dat pe site pleacă odată cu rezervarea, iar pagina arată lista unită: abonații de pe site și cei din EasyCheck (de exemplu, de la check-in-ul online), câte o dată pe adresă. O dezabonare de pe site ajunge și în EasyCheck, iar ofertele pleacă la toată lista. Legătura „Lista din EasyCheck” deschide lista în contul EasyCheck. Fără cheie EasyCheck, lista are doar acordurile de pe site.

## 13. Cronul

Cronul pornește la intervale regulate lucrările de fundal: confirmarea plăților, urmărirea cererilor, sincronizarea cu EasyCheck, e-mailurile, pozele, copiile de siguranță, anunțul versiunii noi.

În cPanel, la **Cron Jobs**, adăugați linia afișată de instalator. Arată așa:

```
*/5 * * * * php -q /home/<cont>/cazo-privat/cron.php
```

Dacă găzduirea nu are PHP în linia de comandă, folosiți varianta cu adresa (tokenul îl găsiți în panou):

```
*/5 * * * * curl -s https://domeniul-dumneavoastră.ro/_cron/<token>
```

Fără cron, site-ul merge oricum: lucrările pornesc și după vizitele oaspeților și când deschideți panoul. Cronul le face însă la timp. Panoul vă arată când a rulat ultima dată și vă avertizează după 30 de minute fără rulare. Când lucrările au stat cel puțin o oră (de exemplu noaptea, fără vizitatori), panoul vă arată pe Acasă, timp de 24 de ore, intervalul în care au stat și vă cere linia de cron. Plata online merge mai departe: înainte să o oprească, site-ul citește din nou camerele libere din EasyCheck și o oprește doar dacă citirea eșuează.

## 14. Întrebări frecvente

**Cât costă softul?**
Softul este gratuit. Aveți nevoie de găzduire și de domeniu; contul EasyCheck e opțional. Pentru plata online, Stripe își ia comisionul lui pe fiecare plată.

**Unde schimb prețurile și disponibilitatea?**
Cu EasyCheck, în EasyCheck: site-ul le preia singur, în câteva secunde. Fără EasyCheck, în panoul site-ului.

**Pot folosi site-ul doar cu plata la sosire?**
Da. Bifați doar „La sosire (numerar)” la instalare sau în panou: ajunge plata la sosire, fără Stripe.

**Ce se întâmplă dacă se rezervă aceeași noapte pe site și pe o platformă?**
Site-ul verifică disponibilitatea înainte și după plată, deci fereastra rămasă e de câteva secunde. Dacă se întâmplă totuși, oaspetele primește confirmarea, iar dumneavoastră primiți alerta și rezolvați în EasyCheck, ca la platforme.

**Primesc banii direct?**
Da. La plată, suma se rezervă pe cardul oaspetelui și se încasează după ce EasyCheck confirmă rezervarea, de obicei în câteva secunde; dacă rezervarea nu se poate confirma în 10 minute, suma se eliberează, fără comision. Plățile încasate intră direct în contul dumneavoastră Stripe, iar de acolo în contul bancar, după programul de plăți Stripe.

**Pot face o probă înainte să primesc rezervări reale?**
Da. Folosiți o cheie EasyCheck de probă și o cheie Stripe de test. Rezervările de probă se șterg singure după 24 de ore. Cardul de test Stripe este `4242 4242 4242 4242`, cu orice dată viitoare și orice cod.

**Când apare site-ul în Google?**
După ce apăsați „Publică site-ul”. În modul de pregătire și cu cheia de probă, site-ul le cere motoarelor de căutare să-l lase deoparte.

**Pot schimba tema după publicare?**
Da, oricând, din panou, la **Aspect**. Previzualizarea vă arată tema cu datele dumneavoastră înainte de activare.

**Ce fac dacă anunțurile de la EasyCheck nu ajung la site?**
Site-ul merge oricum, cu actualizare la câteva minute. Panoul vă arată cauza probabilă (firewall, mod_security, o redirecționare spre HTTPS sau spre www) și textul gata de trimis firmei de găzduire.

**Cine răspunde de textele legale ale site-ului?**
Dumneavoastră, ca operator al site-ului. Softul vă dă modele completate cu datele dumneavoastră, pe care le puteți schimba integral din panou; vă recomandăm să le verificați cu un jurist.

**Cine mă ajută cu site-ul?**
Softul se oferă gratuit, așa cum este. Pentru ajutor la instalare sau la găzduire, vă puteți adresa firmei de găzduire sau unui programator.

**Pot modifica softul?**
Da. Cazo este gratuit, iar codul sursă este public, sub Licența Cazo (fișierul `LICENSE`). Îl puteți folosi, copia, modifica și distribui liber, inclusiv în variante modificate. Singura condiție este linkul din subsol, „Software Regim Hotelier - Cazo.ro” (pe paginile în engleză, „Vacation Rental Software - Cazo.ro”), spre https://cazo.ro/, care rămâne la vedere pe fiecare pagină publică. Poziția și stilul linkului se pot schimba, textul și ținta lui nu.

**Pot scoate linkul din subsol?**
Nu. Linkul face parte din licență, deci panoul nu are o bifă pentru el și apare la toate temele. Dacă lipsește, aveți 30 de zile de la notificare ca să-l puneți la loc; altfel licența încetează.
