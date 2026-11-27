# Ghidul autorilor de teme

Acest ghid arată cum construiți o temă pentru Cazo, software rezervări directe. Programul îl instalează gazda pe domeniul ei; datele proprietății le completează gazda în panou sau vin din EasyCheck, când site-ul e legat de el. Același nume îl întâlniți în cod, în funcții (`cazo.head()`) și în unelte (`bin/cazo-tema`).

Ghidul e scris pentru dezvoltatori web care cunosc HTML, CSS și un limbaj de șabloane. Temele folosesc Twig 3. După ce îl citiți, puteți face o temă completă fără alt document.

---

## 1. Ce este o temă

O temă decide cum arată site-ul gazdei. Ea primește date gata pregătite și le așază în pagină. Logica rezervării, prețul și textele legale rămân ale nucleului.

### 1.1. Granița dintre temă și nucleu

| Tema decide | Nucleul decide |
|---|---|
| structura paginilor de prezentare și ordinea secțiunilor | bara de rezervare, calendarul, rezumatul și căutarea |
| tipografia, galeriile, animațiile CSS | prețul, totalul, planul tarifar și politica de anulare afișată lângă preț |
| culorile și fonturile, prin setările din schemă | plata și tot conținutul comenzii, al paginii de stare și al paginii „Rezervarea mea” |
| aspectul tuturor paginilor publice, inclusiv comanda, starea și „Rezervarea mea” | blocul legal, pictograma SAL, contactul și legăturile legale |
| etichetele proprii, în română și engleză | e-mailurile către oaspeți (cu culorile temei) |
| | harta interactivă și vremea |
| | antetele HTTP, politica de securitate a conținutului (CSP), metadatele, Microdata, `hreflang` |

Pe scurt: tema poziționează și stilizează componentele nucleului. Conținutul lor rămâne neschimbat.

### 1.2. Trei reguli de bază

1. **Tema primește doar date.** Orice nume, cifră, distanță, oră sau preț din pagină vine din modelul de vizualizare (secțiunea 6). Textele scrise în temă sunt etichete generice, aceleași la toate gazdele: „Camere”, „Împrejurimi”, „Regulile casei”.
2. **Tema afișează doar ce poate fi adevărat.** Afirmațiile pe care programul nu le poate verifica rămân în afara temei: „cel mai bun preț”, „ales de cei mai mulți”, „răspundem 24/24”. La fel decorul inventat (temperaturi, coduri de acces, cifre fictive).
3. **O dată lipsă nu lasă urme.** Când un câmp e gol, eticheta lui dispare. Secțiunile fără date dispar cu totul.

### 1.3. Paginile site-ului

| Pagina | Adresa (RO / EN) | Cine o compune |
|---|---|---|
| Acasă | `/` | tema, din `templates/acasa.json` |
| Camerele | `/camere` / `/rooms` | tema, din `templates/camere.json` |
| O cameră | `/camere/{slug}` / `/rooms/{slug}` | tema, din `templates/camera.json` |
| Pagină a gazdei | `/pagina/{slug}` / `/page/{slug}` | tema, din `templates/pagina.json` |
| Căutarea, paginile legale | de exemplu `/date-legale`, `/termeni` | nucleul, în layoutul temei |
| Comanda, starea, „Rezervarea mea” | | nucleul, în layoutul temei |

Paginile compuse de nucleu folosesc `layout/baza.twig` al temei. Nucleul umple blocul `{% block continut %}`. Așa, întreg site-ul are designul temei.

---

## 2. Structura pachetului

O temă este un folder, livrat ca arhivă `.zip`. Numele folderului este identificatorul temei (`slug`): litere mici, cifre și cratimă.

```
lumina/
  theme.json                 manifestul (obligatoriu)
  settings.schema.json       setările globale (obligatoriu)
  layout/
    baza.twig                layoutul tuturor paginilor (obligatoriu)
  templates/
    acasa.json               recomandat
    camere.json              recomandat
    camera.json              recomandat
    pagina.json              recomandat
  sections/
    prezentare.twig          o secțiune
    prezentare.json          schema ei
  partials/
    antet.twig               bucăți refolosite
  assets/
    css/lumina.css
    img/model.webp
    fonts/text.woff2
  locales/
    ro.json                  obligatoriu
    en.json                  obligatoriu
  previews/
    1440.webp                previzualizarea pe ecran lat
    390.webp                 previzualizarea pe telefon
  LICENTA.txt
  CHANGELOG.md
```

Șabloanele din `templates/` sunt recomandate: un șablon care lipsește se ia din `_schelet` (secțiunea 2.1).

### 2.1. Biblioteca `_schelet`

Nucleul are o temă de bază, `_schelet`. Ea conține secțiuni accesibile și rapide, gata de folosit: căutarea ca prim ecran, grila camerelor, galeria, fișa camerei, calendarul cu rezumatul, regulile casei, harta, textul proprietății.

Tema dumneavoastră moștenește automat trei lucruri din `_schelet`:

- **secțiunile**: un `tip` de secțiune care lipsește din `sections/` al temei se caută în `_schelet`;
- **șabloanele de pagină**: un fișier din `templates/` care lipsește se ia din `_schelet`;
- **textele**: cheile din `locales/` ale temei se adaugă peste cele din `_schelet` și le înlocuiesc pe cele cu același nume.

Puteți include explicit o secțiune sau un fragment din bibliotecă:

```twig
{% include "@schelet/sections/harta.twig" %}
{% include "@schelet/partials/subsol.twig" %}
```

`_schelet` este și tema de rezervă. Dacă tema dumneavoastră eșuează la randare, pagina se servește cu `_schelet`, iar gazda primește o alertă (secțiunea 10).

Secțiunile din `_schelet` folosesc clase cu prefixul `sc-`. CSS-ul temei dumneavoastră le poate stiliza direct.

Secțiunile disponibile acum în `_schelet`:

| `tip` | Ce afișează |
|---|---|
| `hero_cautare` | numele proprietății, subtitlul și bara de rezervare, opțional cu poza principală |
| `bara` | bara de rezervare |
| `camere_antet` | titlul paginii camerelor |
| `camere_grila` | cardurile camerelor, cu poza, capacitatea și „de la X lei” |
| `text_proprietate` | descrierea proprietății |
| `galerie_proprietate` | pozele proprietății |
| `reguli_casa` | orele și regulile casei |
| `harta` | harta, încărcată la clic |
| `camera_antet` | titlul camerei, prețul de la și poza principală |
| `camera_rezervare` | calendarul și rezumatul camerei |
| `camera_fisa` | fișa camerei: oaspeți, pat, baie, suprafață, vedere, dotări |
| `camera_galerie` | pozele camerei |
| `pagina_corp` | conținutul unei pagini a gazdei |

Biblioteca va ajunge la circa 25 de secțiuni (variante de hero, carusel, întrebări frecvente, împrejurimi, contact, citate).

---

## 3. Manifestul `theme.json`

```json
{
  "slug": "lumina",
  "nume": {"ro": "Lumina", "en": "Light"},
  "versiune": "1.0.0",
  "autor": {"nume": "Studio Exemplu", "url": "https://exemplu.ro"},
  "licenta": "proprietară",
  "necesita": {"cazo": ">=1.0 <2.0", "model_vedere": "1"},
  "limbi": ["ro", "en"],
  "pentru": "apartament în oraș",
  "componente": [],
  "continut": [],
  "fonturi": [
    {"familie": "Lumina Sans", "fisier": "assets/fonts/lumina-sans.woff2", "licenta": "OFL-1.1", "preincarcat": true}
  ],
  "css": ["assets/css/lumina.css"],
  "stiluri": [],
  "buget": {"css_kb_gz": 20, "js_kb_gz": 0}
}
```

| Cheia | Obligatorie | Ce înseamnă |
|---|---|---|
| `slug` | da | identificatorul propus de dumneavoastră: litere mici, cifre, cratimă, cel mult 64 de caractere; la încărcare, site-ul generează din el slugul temei (fără să suprascrie altă temă) |
| `nume` | da | numele afișat în panou, în română și engleză |
| `versiune` | da | versiunea temei, în format semver (`1.0.0`) |
| `autor` | da | numele și, opțional, adresa autorului |
| `licenta` | da | licența aleasă de dumneavoastră (secțiunea 16) |
| `necesita` | da | versiunile compatibile ale programului (`cazo`) și ale modelului de vizualizare (`model_vedere`) |
| `limbi` | da | limbile temei; `ro` și `en` sunt obligatorii |
| `pentru` | nu | tipul de proprietate pentru care e gândită tema, afișat gazdei |
| `captura` | nu | captura paginii de început, din rădăcina temei: `captura.webp`, `captura.png` sau `captura.jpg`. E imaginea mică din lista temelor, în panou și în instalator; fără ea, lista arată numele temei. Cel mult 200 KB și 1600 px pe o latură; recomandat 600 x 750 (prima pagină pe un ecran de telefon) |
| `latime_rem` | nu | lățimea coloanei de conținut a temei, în rem (între 40 și 90). Paginile nucleului (căutarea, rezervarea, paginile legale) și subsolul iau aceeași lățime, ca antetul temei și conținutul lor să stea pe aceeași coloană; lipsă, 72. Un bloc al nucleului așezat pe un card în culoarea suprafeței ia culoarea cardului când un strămoș al lui are clasa `pg-pe-suprafata`. |
| `componente` | nu | componentele interactive ale nucleului cerute de temă (secțiunea 8.3) |
| `continut` | nu | blocurile de conținut pe care panoul le oferă gazdei pentru această temă |
| `fonturi` | nu | fonturile proprii: familia, fișierul `.woff2`, licența și dacă se preîncarcă |
| `css` | nu | foile de stil, în ordinea încărcării; fără această cheie, se încarcă toate fișierele din `assets/css/`, în ordine alfabetică |
| `stiluri` | nu | variantele vizuale ale temei (de exemplu iarnă și vară) |
| `buget` | nu | mărimea țintă a CSS-ului, în KB comprimați |
| `privata` | nu | doar la temele livrate cu programul: `{"domenii": ["exemplu.ro"]}`; tema lipsește din catalog și se activează doar pe domeniile din listă (cu sau fără `www.`) |

Fonturile marcate `"preincarcat": true` se preîncarcă, cel mult două. Fără marcaj, se preîncarcă primul font din listă.

Cheia `js` este rezervată temelor oficiale. Într-o temă terță, prezența ei face tema să fie refuzată (secțiunea 11).

---

## 4. Setările din `settings.schema.json`

Setările globale sunt cele pe care gazda le schimbă din panou fără cod: culorile, fonturile, rotunjirea colțurilor, spațierea. Setările temei sunt vizuale. Conținutul vine din date, nu din setări.

```json
{
  "setari": [
    {"id": "culoare_fundal", "tip": "color", "implicit": "#fbfaf7"},
    {"id": "culoare_text", "tip": "color", "implicit": "#15171a"},
    {"id": "culoare_accent", "tip": "color", "implicit": "#0d5c4b"},
    {"id": "culoare_accent_contrast", "tip": "color", "implicit": "#ffffff"},
    {"id": "culoare_linie", "tip": "color", "implicit": "#6b6f72"},
    {"id": "culoare_suprafata", "tip": "color", "implicit": "#eef1ec"},
    {"id": "font_titlu", "tip": "font", "implicit": "Lumina Sans"},
    {"id": "font_text", "tip": "font", "implicit": "sistem"},
    {"id": "raza", "tip": "range", "min": 0, "max": 32, "implicit": 4},
    {"id": "spatiu", "tip": "range", "min": 12, "max": 24, "implicit": 16}
  ]
}
```

Fiecare setare are `id`, `tip` și `implicit`. Setările de tip `range` au și `min` și `max`, iar cele de tip `select` sau `radio` au `optiuni`.

### 4.1. Jetoanele CSS

Din setările cu numele de mai sus, nucleul construiește un fișier de variabile CSS. Tema le folosește în stilurile ei. Scrieți culorile prin variabile, ca alegerile gazdei să se aplice peste tot.

| Setarea | Variabila CSS | Valori |
|---|---|---|
| `culoare_fundal` | `--pg-culoare-fundal` | culoare hex, `#rrggbb` |
| `culoare_text` | `--pg-culoare-text` | culoare hex |
| `culoare_accent` | `--pg-culoare-accent` | culoare hex |
| `culoare_accent_contrast` | `--pg-culoare-accent-contrast` | culoarea textului pe accent |
| `culoare_linie` | `--pg-culoare-linie` | chenare și separatoare |
| `culoare_suprafata` | `--pg-culoare-suprafata` | fundalul blocurilor scoase în evidență |
| `font_titlu` | `--pg-font-titlu` | un font declarat în `theme.json` sau `sistem`, `serif`, `mono` |
| `font_text` | `--pg-font-text` | la fel |
| `raza` | `--pg-raza` | 0-32, în pixeli |
| `spatiu` | `--pg-spatiu-1` ... `--pg-spatiu-8` | baza 12-24; scara este 0,25, 0,5, 0,75, 1, 1,5, 2, 3 și 4 ori baza |
| | `--pg-umbra` | umbra standard a nucleului |

O valoare invalidă (o culoare în alt format decât `#rrggbb`, un font nedeclarat, o rază sau o bază de spațiere în afara intervalului) cade pe valoarea implicită a nucleului, nu pe cea din schema temei.

### 4.2. Contrastul

La salvare, nucleul verifică perechile de culori după WCAG 2.2:

| Text | Fundal | Raportul minim |
|---|---|---|
| `culoare_text` | `culoare_fundal` | 4,5:1 |
| `culoare_text` | `culoare_suprafata` | 4,5:1 |
| `culoare_accent_contrast` | `culoare_accent` | 4,5:1 |
| `culoare_accent` | `culoare_fundal` | 4,5:1 |
| `culoare_linie` | `culoare_fundal` | 3:1 |

Sub prag, salvarea se refuză. Mesajul arată raportul cerut, raportul de acum și cea mai apropiată nuanță a culorii de text care trece pe toate fundalurile ei (de exemplu `#767676` în locul lui `#999999` pe alb). Validatorul cere ca valorile implicite să treacă toate perechile. O setare de tip `color_scheme` (fundal și text) se verifică la 4,5:1.

### 4.3. Tipurile de setări

Tipurile folosite acum în teme: `color`, `font`, `range`, `select`, `checkbox` și `text`. O setare `text` dintr-o secțiune ține cheia unei etichete din `locales/` (exemplu la secțiunea 7.2).

Schema completă (`scheme/settings.schema.json`) mai cuprinde `color_scheme` (perechi fundal și text), `radio`, `image` (încărcată prin nucleu și recodificată ca WebP sau PNG, fără SVG, cel mult 8 MB; valoarea e adresa imaginii), `url` (http, https sau relativ), `textarea`, `richtext` (o listă închisă de etichete: `p`, `strong`, `em`, `a`, `ul`, `ol`, `li`, `h2`-`h4`, `br`) și `continut` (cheia unui bloc de conținut din panou). Câmpurile de cod liber și HTML-ul liber rămân în afara schemei; textul unei setări ajunge în șablon ca text, escapat ca orice altă valoare.

Fiecare setare poate avea `eticheta` și `ajutor`, în română și engleză (`{"ro": "...", "en": "..."}`); fără ele, panoul folosește etichetele lui pentru numele cunoscute (`culoare_fundal`, `font_titlu` ...) și `id`-ul pentru restul. O setare `select` sau `radio` are `optiuni`: texte simple (`["simplu", "poza"]`) sau obiecte `{"valoare": "poza", "eticheta": {"ro": "Cu fotografie", "en": "With a photo"}}`. Setările de tip `range` pot avea `pas` și `unitate`.

În panou, la Aspect, gazda descarcă setările temei ca fișier JSON și le încarcă din nou, pe același site sau pe altul cu aceeași temă; importul trece prin aceleași verificări, inclusiv contrastul.

Valorile tuturor setărilor globale ajung în șabloane în variabila `setari`, de exemplu `setari.raza`.

---

## 5. Fișierele de limbă

Site-ul are română la rădăcină și engleză sub `/en/`. Tema are două fișiere obligatorii, `locales/ro.json` și `locales/en.json`, cu aceleași chei.

```json
{
  "hero.subtitlu": "Cazare în {localitate}, cu rezervare directă la gazdă.",
  "camere.titlu_sectiune": "Camerele",
  "camera.oaspeti": "Capacitatea: {n}"
}
```

```json
{
  "hero.subtitlu": "A stay in {localitate}, booked directly with your host.",
  "camere.titlu_sectiune": "The rooms",
  "camera.oaspeti": "Capacity: {n}"
}
```

În șablon, textul se cere cu `t()`, iar parametrii se dau într-o mapare:

```twig
<p>{{ t('hero.subtitlu', {localitate: proprietate.adresa.localitate}) }}</p>
```

Regulile textelor:

- cheile din `ro.json` și `en.json` sunt identice;
- cheile sunt simple, cu puncte pentru grupare (`reguli.checkin`);
- o cheie lipsă din engleză cade pe română, apoi pe numele cheii;
- româna folosește ș și ț cu virgulă (U+0218-U+021B), niciodată cu sedilă;
- în locul liniei lungi (U+2014) folosiți două puncte, virgulă sau o propoziție nouă;
- textele sunt afirmative și se adresează oaspetelui cu „dumneavoastră”;
- textele lasă deoparte numele furnizorilor tehnici ai programului.

Pentru plurale (de exemplu „1 noapte”, „2 nopți”, „20 de nopți”) folosiți filtrul nucleului `nopti`. Pentru prețuri, filtrul `lei` (secțiunea 8.4).

Un text care vine din date într-o altă limbă decât pagina primește atributul `lang`. Modelul vă spune limba reală prin câmpurile `titlu_limba` și `descriere_limba`:

```twig
<h3{% if c.titlu_limba != site.limba %} lang="{{ c.titlu_limba }}"{% endif %}>{{ c.titlu }}</h3>
```

---

## 6. Modelul de vizualizare

Modelul de vizualizare este contractul dintre nucleu și teme. Conține doar liste, mapări și valori simple (texte, numere, adevărat sau fals). Versiunea lui actuală este `1`, declarată în `theme.json` la `necesita.model_vedere`.

Datele (proprietatea, camerele, pozele, prețurile) vin din EasyCheck sau, la site-ul de sine stătător, din panou, plus completările gazdei. Tema primește același model în ambele cazuri. Datele firmei gazdei lipsesc din model: ele apar doar în blocul legal, randat de nucleu.

Volumele sunt mărginite: cel mult 50 de camere, cel mult 200 de poze și cel mult 50 de secțiuni pe pagină.

### 6.1. Variabilele de nivel superior

| Variabila | Ce conține |
|---|---|
| `site` | datele site-ului: numele, limba, limbile, adresa de bază |
| `proprietate` | proprietatea: nume, tip, descriere, adresă, ore, reguli, contact, poze |
| `camere` | lista camerelor vizibile, în ordinea aleasă de gazdă |
| `camera` | camera paginii curente, doar pe pagina unei camere; altfel `null` |
| `pagina` | pagina curentă: tipul, titlul, secțiunile |
| `sectiune` | secțiunea curentă, în interiorul unui fișier din `sections/` |
| `continut` | blocurile de conținut scrise de gazdă în panou, în limba paginii |
| `setari` | valorile setărilor globale ale temei |
| `meniu` | meniul principal și legăturile din subsol |
| `cerere` | căutarea curentă (sosire, plecare, camere), când există; altfel `null` |
| `vreme` | vremea, când tema o folosește și datele au cel mult 3 ore; altfel `null`: `temperatura`, `stare` (senin, partial_noros, noros, ceata, burnita, ploaie, ninsoare, furtuna), `text`, `zile[{zi, eticheta, min, max, stare, text}]` (azi și următoarele 2 zile), `rasarit`, `apus`, `sursa`, `sursa_url`. Datele vin de la MET Norway, iar sursa se afișează lângă ele (licența CC BY 4.0) |

### 6.2. `site`

| Câmpul | Exemplu |
|---|---|
| `site.nume` | „Casa din deal” |
| `site.limba` | `ro` |
| `site.limbi[]` | `{cod: "en", url: "/en/", activa: false, nume: "English"}` |
| `site.url_baza` | adresa paginii de început, în limba curentă |
| `site.mod_proba` | `true` când site-ul rulează pe o cheie de probă |
| `site.an` | anul curent |
| `site.sigla` | sigla încărcată de gazdă în panou, sau `null`: `{url, latime, inaltime, cu_nume}`. În antet, `<img class="pg-sigla-img" src="{{ site.sigla.url }}" width="{{ site.sigla.latime }}" height="{{ site.sigla.inaltime }}" alt="...">`: înălțimea pe calculator și pe telefon o pune nucleul, după alegerea gazdei. Cu `cu_nume` fals, gazda vrea doar sigla (atunci `alt` e numele site-ului); cu `cu_nume` adevărat, numele rămâne lângă ea (și `alt` rămâne gol). Fără siglă, antetul arată numele, ca înainte. |

### 6.3. `proprietate`

| Câmpul | Ce conține |
|---|---|
| `nume` | numele proprietății |
| `tip` | tipul, ca cheie (`apartament`, `pensiune`, `vila` ...) |
| `descriere`, `descriere_limba` | descrierea scurtă și limba ei reală |
| `descriere_titlu`, `descriere_paragrafe[]` | titlul de pe primul rând al descrierii (când e scurt și urmat de text, altfel gol) și descrierea pe paragrafe, fără titlu; arătați-o întreagă, de exemplu primul paragraf și restul într-un `<details>` |
| `adresa.strada`, `adresa.localitate`, `adresa.judet` | adresa clădirii |
| `coordonate.lat`, `coordonate.lng` | coordonatele, sau `null` |
| `ore.checkin_de_la`, `ore.plecare_pana_la` | orele, de exemplu `15:00` și `11:00` |
| `ore.checkin_pana_la` | ora până la care se poate face check-in, sau text gol (oricând după ora de început) |
| `reguli_casa` | `copii`, `bebelusi`, `animale`, `animale_max`, `evenimente`, `fumat`; fiecare poate fi `null` |
| `anulare_zile` | zilele de anulare gratuită, sau `null` |
| `contact.email`, `contact.telefon` | contactul publicat |
| `linkuri_platforme[]` | legăturile spre alte platforme, când gazda le arată |
| `spatii_comune[]` | spațiile comune ale proprietății: `{tip, eticheta}` (grădina, terasa comună, parcarea ...) |
| `poze[]` | pozele proprietății (6.5) |

Notele de pe platforme stau în `recenzii[]`, la primul nivel: `{platforma, scor, scala, numar, actualizat_la}`, doar Booking.com (din 10) și Airbnb (din 5). Afișați-le cu platforma și data lângă fiecare notă; fără note, lista e goală.

### 6.4. `camere[]` și `camera`

| Câmpul | Ce conține |
|---|---|
| `id`, `slug`, `url` | identificatorul, partea din adresă și adresa paginii camerei |
| `titlu`, `titlu_limba` | titlul și limba lui reală |
| `descriere` | o propoziție opțională |
| `unitati` | câte unități de acest fel are proprietatea |
| `oaspeti_max` | capacitatea, sau `null` |
| `fisa.pat`, `fisa.baie`, `fisa.suprafata_mp`, `fisa.vedere` | fișa camerei; câmpurile goale sunt texte goale sau `null` |
| `dotari[]` | etichetele dotărilor bifate, în limba paginii |
| `poze[]`, `poza_principala` | pozele camerei și prima dintre ele |
| `pret_de_la.suma`, `pret_de_la.text` | cel mai mic preț pe noapte, în bani (subunități), și textul gata format; sau `null` |
| `spatii[]` | spațiile unității, în ordinea gazdei: `{tip, index, eticheta, paturi[{tip, numar, eticheta}], baie, poze[]}`; listă goală până când gazda le completează |
| `paturi[]`, `baie_tip`, `suprafata_mp` | toate paturile unității, baia (`proprie`, `comuna` sau gol) și suprafața, sau `null` |

Pentru preț, afișați `pret_de_la.text`. Nucleul îl formulează gata de afișat (de exemplu „de la 250,00 lei pe noapte”). Când prețul lipsește, câmpul e `null` și tema arată altă etichetă (de exemplu „Prețul la cerere”).

### 6.5. `poza`

| Câmpul | Ce conține |
|---|---|
| `sha` | identificatorul pozei |
| `alt` | textul alternativ, scris de nucleu |
| `rol` | rolul pozei, când există (altfel categoria) |
| `categorie` | ce se vede în poză (`exterior`, `dormitor`, `baie` ...), sau text gol |
| `legenda` | textul de sub poză, gata ales de nucleu (al gazdei, altfel al spațiului, altfel al categoriei), sau text gol |
| `index`, `spatiu` | numărul și identificatorul spațiului din care e poza, când gazda l-a ales |
| `latime`, `inaltime` | dimensiunile originale, în pixeli |

O poză se afișează prin funcția `img()` (8.2), care construiește și adresele imaginilor.

### 6.6. `pagina`, `sectiune`, `meniu`, `cerere`

- `pagina.tip`: `acasa`, `camere`, `camera`, `pagina` sau tipul unei pagini a nucleului; `pagina.titlu`; `pagina.sectiuni[]`; `pagina.seo.descriere`.
- `sectiune.id`: un identificator unic pe pagină (`s0`, `s1` ...), bun pentru `id` și `aria-labelledby`; `sectiune.tip`; `sectiune.setari`: setările secțiunii, cu valorile implicite completate; `sectiune.blocuri[]`.
- `meniu.principal[]` și `meniu.subsol[]`: `{titlu, url, activ}`. Meniul principal cuprinde Acasă, Camerele și paginile publicate de gazdă. Subsolul cuprinde paginile legale.
- `cerere.sosire`, `cerere.plecare` (format `AAAA-LL-ZZ`), `cerere.camere[]`: căutarea venită din adresă, deja validată.

### 6.7. Ce urmează în model

Modelul de conținut al temelor adaugă câmpuri noi, în aceeași versiune a contractului:

- **Categoriile pozelor.** Fiecare poză primește `categorie`, `index` și `legenda`. Legenda vizibilă este eticheta categoriei, cu index când sunt mai multe („Dormitorul 2”). Textul alternativ urmează forma „{Eticheta}, {numele proprietății}, fotografia {n}”. Categoriile, în ordinea din galerie: `exterior`, `intrare`, `living`, `camera`, `dormitor`, `bucatarie`, `loc_de_masa`, `baie`, `spatiu_lucru`, `balcon`, `terasa`, `gradina`, `piscina`, `spatii_comune`, `restaurant`, `parcare`, `priveliste`, `imprejurimi`, `altele` (fără legendă).
- **Spațiile unei unități.** `camere[].spatii[]`: `{tip, index, eticheta, paturi[], poze[]}`, construite de nucleu din numărul de dormitoare, băi și celelalte spații, în ordinea fixă: living, cameră, dormitoare, bucătărie sau chicinetă, băi, spațiu de lucru, balcon sau terasă sau grădină. Tema citește etichetele („Dormitorul 1”, „Baia 2”) din listă.
- **Forma proprietății.** `proprietate.forma`: `A` (o unitate întreagă), `B` (mai multe unități de același fel) sau `C` (mai multe tipuri de camere). La forma A, pagina de început arată spațiile, fără carduri de cameră.
- **Galeria pregătită.** `galerie.numar`, `galerie.grupe[]` (pozele pe categorii) și `galerie.grila[]` (câte o poză pe categorie).
- **Reperele.** `repere[]`: între 0 și 5 locuri din apropiere, cu `nume`, `categorie`, `minute`, `mod` și `principal`. Secțiunea „Împrejurimi” apare de la primul reper.
- **Orele.** `ore.checkin_pana_la`, când gazda o completează.

Construiți tema astfel încât să funcționeze cu oricâte elemente: o garsonieră cu o singură cameră și zero repere, la fel ca o pensiune cu 50 de camere.

---

## 7. Compoziția paginilor

### 7.1. Șabloanele JSON

Fiecare fișier din `templates/` spune ce secțiuni are pagina și în ce ordine. Nu conține HTML.

```json
{"sectiuni": [
  {"tip": "hero_cautare", "setari": {"cu_poza": true, "varianta": "poza"}},
  {"tip": "camere_grila"},
  {"tip": "prezentare"},
  {"tip": "reguli_casa"},
  {"tip": "harta"}
]}
```

Fiecare element are `tip` (numele secțiunii) și, opțional, `setari`. Setările date aici înlocuiesc valorile implicite din schema secțiunii. Un `tip` necunoscut se sare și se notează în jurnal.

Alegerile gazdei pentru secțiuni (ordinea, secțiunile ascunse, setările lor) stau separat de temă, deci se păstrează la actualizarea temei.

### 7.2. Secțiunile

O secțiune are două fișiere cu același nume: `sections/<tip>.twig` și `sections/<tip>.json`.

Schema secțiunii:

```json
{
  "nume": {"ro": "Prezentarea", "en": "Introduction"},
  "setari": [
    {"id": "titlu", "tip": "text", "implicit": "prezentare.titlu"},
    {"id": "cu_poza", "tip": "checkbox", "implicit": true}
  ]
}
```

Șablonul secțiunii:

```twig
<section class="lu-prezentare" aria-labelledby="{{ sectiune.id }}-t">
  <h2 id="{{ sectiune.id }}-t">{{ t(sectiune.setari.titlu) }}</h2>
  {% if proprietate.descriere %}
    <p{% if proprietate.descriere_limba != site.limba %} lang="{{ proprietate.descriere_limba }}"{% endif %}>{{ proprietate.descriere|nl2br }}</p>
  {% endif %}
</section>
```

Setarea `titlu` ține o cheie din `locales/`, deci titlul se traduce singur. Titlurile secțiunilor sunt etichete fixe ale temei, aceleași la toate gazdele.

Schema unei secțiuni poate defini și `blocuri` (elemente repetabile, configurate de gazdă) și `presetari` (variante gata făcute ale secțiunii). Forma ei e în `scheme/settings.schema.json`, la `$defs/sectiune`; o secțiune fără fișierul `.json` alăturat e o eroare a validatorului.

### 7.3. Layoutul

`layout/baza.twig` este cadrul comun al tuturor paginilor. Nucleul îl extinde și pune secțiunile în `{% block continut %}`.

```twig
<!doctype html>
<html lang="{{ site.limba }}">
<head>
{{ cazo.head() }}
</head>
<body class="lu-corp">
<a class="lu-salt" href="#continut">{{ t('salt_continut') }}</a>
{% include "partials/antet.twig" %}
<main id="continut">
{% block continut %}{% endblock %}
</main>
{{ cazo.foot() }}
</body>
</html>
```

Layoutul cuprinde obligatoriu: `lang` pe `<html>`, `cazo.head()` în `<head>`, `<header>`, `<main>` și `cazo.foot()` ca ultim element din `<body>`.

Nucleul deține `<body>`: ce scrieți între `<body>` și `</body>` ajunge într-un container `<div class="pg-tema …">`, cu clasele și atributele puse de dumneavoastră pe `<body>` (de exemplu `lu-corp`). După container, nucleul adaugă singur subsolul lui, cu contactul și blocul legal, pe fiecare pagină, iar cu cheia de probă adaugă sus banda „Site în probă”. Subsolul temei (sigla, meniul, rețelele) e opțional și stă în container, înaintea subsolului nucleului.

---

## 8. Funcțiile nucleului

Funcțiile nucleului produc HTML din șabloanele nucleului, în afara temei. Ieșirea lor e sigură și completă, cu marcajul `data-pg`.

### 8.1. Funcțiile `cazo.*`

| Funcția | Unde | Ce produce |
|---|---|---|
| `cazo.head()` | obligatoriu, în `<head>` | `meta`, titlul, `canonical`, `hreflang`, Open Graph, preîncărcarea fonturilor și a imaginii principale, foile nucleului, variabilele CSS și CSS-ul temei |
| `cazo.foot()` | obligatoriu, ultimul în `<body>` | scripturile nucleului pentru componentele folosite |
| `cazo.bara_rezervare()` | obligatoriu în `acasa`; pe pagina camerei, datele se aleg în `cazo.rezumat(camera)`, deci bara lipsește acolo la temele oficiale | bara de căutare și rezervare |
| `cazo.calendar(camera)` | obligatoriu în `camera` | calendarul camerei, cu prețurile și zilele libere |
| `cazo.rezumat(camera)` | obligatoriu în `camera` | rezumatul sejurului ales și totalul |
| `cazo.harta()` | obligatoriu în `acasa` sau pe pagina de contact | adresa, chenarul hărții și legăturile de navigare; harta interactivă se încarcă la clicul oaspetelui |
| `cazo.continut()` | în secțiunea paginilor de conținut | conținutul pregătit de nucleu (textul paginii gazdei, paginile legale, căutarea) |
| `cazo.vreme()` | opțional | vremea pe 3 zile la proprietate (MET Norway), cu sursa și legătura spre licență; se ascunde când datele au peste 3 ore |
| `cazo.componenta(nume, optiuni)` | opțional | componentele speciale ale temelor oficiale |

Fiecare componentă apare cel mult o dată pe pagină. Contactul și blocul legal (datele furnizorului, SAL, legăturile legale) le pune nucleul în subsolul lui, după conținutul temei, pe fiecare pagină. `cazo.contact()` și `cazo.subsol_legal()` rămân valabile în temele scrise înainte și nu mai afișează nimic.

### 8.2. Funcțiile generale

| Funcția | Ce face |
|---|---|
| `img(poza, latimi, prioritate, sizes)` | produce `<picture>` cu AVIF, WebP și JPEG, `srcset`, `sizes`, `width`, `height`, `loading` și `fetchpriority` corecte |
| `t(cheie, parametri)` | textul temei în limba paginii |
| `url(ruta, parametri)` | adresa unei pagini, în limba curentă |
| `asset(cale)` | adresa publică a unui fișier din `assets/` |
| `cycle`, `min`, `max`, `range` | funcțiile obișnuite din Twig; `range` dă cel mult 100 de elemente |

`img()`:

- `latimi` este o listă din valorile 480, 960, 1440 și 2048;
- `prioritate` este `true` doar pentru imaginea principală a paginii (LCP), care se încarcă imediat și se preîncarcă; toate celelalte se încarcă la derulare (`loading="lazy"`);
- `sizes` descrie lățimea afișată, ca în HTML.

```twig
{{ img(proprietate.poze|first, [960, 1440, 2048], true, '100vw') }}
{{ img(c.poza_principala, [480, 960], false, '(min-width: 48rem) 33vw, 100vw') }}
```

`url()` cunoaște rutele `acasa`, `camere`, `camera` (cu `{slug: ...}`), `pagina` (cu `{slug: ...}`), `disponibil`, `date_legale`, `termeni`, `confidentialitate`, `cookie` și `accesibilitate`.

```twig
<a href="{{ url('camere') }}">{{ t('camere.titlu') }}</a>
<a href="{{ url('camera', {slug: c.slug}) }}">{{ c.titlu }}</a>
```

`asset()` primește o cale din `img/`, `fonts/`, `css/` sau `js/`, fără prefixul `assets/`:

```twig
<img src="{{ asset('img/model.webp') }}" alt="" width="1200" height="80">
```

### 8.3. Componentele nucleului

Bara, calendarul, rezumatul și harta sunt elemente web: `<cazo-bara>`, `<cazo-calendar>`, `<cazo-rezumat>`, `<cazo-harta>`. Prima lor vizualizare e randată pe server, deci funcționează și fără JavaScript. Scripturile lor le încarcă nucleul, doar pe paginile unde apar.

Le stilizați în trei feluri:

1. **prin jetoane**: componentele folosesc variabilele `--pg-*`, deci preiau singure culorile, fonturile și rotunjirea alese de gazdă;
2. **prin clasele `pg-*`** din marcajul lor, de exemplu `.pg-bara-formular`, `.pg-camp`, `.pg-buton`, `.pg-calendar-luna`, `.pg-legal-linkuri`;
3. **prin structura paginii**: componentele stau în DOM-ul obișnuit al paginii, fără Shadow DOM, deci le așezați cu regulile obișnuite de layout (grilă, flex, spațiere) aplicate containerelor temei; `::part()` nu se folosește.

Toate marcajele nucleului poartă atributul `data-pg` (de exemplu `data-pg="bara"`, `data-pg="legal"`). Folosiți-l pentru poziționare, nu pentru a ascunde (secțiunea 9).

### 8.4. Filtrele

Filtrele Twig permise: `escape` (`e`), `default`, `length`, `upper`, `lower`, `capitalize`, `title`, `trim`, `join`, `split`, `slice`, `first`, `last`, `keys`, `sort` (fără funcție de comparație), `reverse`, `replace` (cu o mapare scrisă direct), `nl2br`, `striptags`, `abs`, `round`, `batch`.

Filtrele nucleului:

| Filtrul | Exemplu | Rezultat |
|---|---|---|
| `lei` | `{{ c.pret_de_la.suma\|lei }}` | „1.450,00 lei” în română, „RON 1,450.00” în engleză; primește suma în bani |
| `data` | `{{ cerere.sosire\|data }}` | „20 dec. 2026” / „20 Dec 2026” |
| `nopti` | `{{ 3\|nopti }}` | „3 nopți”, cu pluralul corect |
| `scurtat` | `{{ proprietate.descriere\|scurtat(160) }}` | textul tăiat la un cuvânt întreg, cu „…” |

Testele permise: `defined`, `empty`, `null`, `none`, `odd`, `even`, `iterable`, `same as`, `divisible by`, `sequence`, `mapping`.

---

## 9. CSS-ul temei și blocul legal

### 9.1. Straturile

Nucleul publică CSS-ul temei înfășurat în stratul `@layer tema`. Stilurile de bază ale nucleului stau în stratul `nucleu`, declarat înaintea temei. Ordinea e `@layer nucleu, tema;`, deci regulile temei bat stilurile de bază ale nucleului.

Scrieți CSS obișnuit, fără `@layer`: stratul îl pune nucleul. Nucleul prefixează și fiecare selector cu containerul temei (`:where(.pg-tema)`), deci regulile dumneavoastră se aplică doar conținutului temei, cu aceeași specificitate. Stilurile generale ale paginii (fontul, culorile, fundalul) le puneți pe clasa de pe `<body>`, de exemplu `.lu-corp`, care ajunge pe container.

```css
@font-face {
  font-family: "Lumina Sans";
  font-display: swap;
  src: url(../fonts/lumina-sans.woff2) format("woff2");
}
.lu-corp { font-family: var(--pg-font-text); }
h1, h2 { font-family: var(--pg-font-titlu); }
.lu-prezentare { padding: var(--pg-spatiu-6) var(--pg-spatiu-4); background: var(--pg-culoare-suprafata); }
.pg-buton { border-radius: var(--pg-raza); }
```

### 9.2. Ce acceptă CSS-ul temei

- `url()` doar spre fișierele temei, cu cale relativă (`../fonts/...`, `../img/...`);
- fonturi doar locale, în format `woff2`, cu glifele românești Ș, ș, Ț, ț (U+0218-U+021B);
- variabilele `--pg-*`, selectori moderni, `@media`, `@supports`, `@container`, animații, `@view-transition`.

Validatorul refuză: selectorii pe `html`, `body` și `:root` (le stilează nucleul; folosiți clasa de pe `<body>`), pe `.pg-tema`, `.pg-nucleu`, `.pg-corp` și `[data-pg-nucleu]`, un selector care începe cu un combinator (`>`, `+`, `~`), regulile imbricate (scrieți fiecare selector întreg), `!important`, `@import`, `@layer`, `url()` spre alte domenii, `data:` sau căi absolute, `expression`, `behavior`, `-moz-binding`, `javascript:` și fonturile în alte formate decât `woff2`. Variabilele `--pg-*` le pune nucleul, din setările gazdei, cu contrastul verificat: tema le folosește cu `var(--pg-…)`, fără să le redefinească. Caracterul `\` (escape-urile CSS) e permis doar în șiruri, de exemplu `content: "\A"`, și nu în adresele din `url()`: browserul ar citi `!\important` ca `!important`.

### 9.3. Blocul legal rămâne vizibil

Datele legale, contactul, totalul, politica de anulare și butonul final sunt obligații legale ale gazdei. De aceea nucleul le protejează:

1. CSS-ul temei stă mereu în `@layer tema`.
2. `!important` este refuzat în CSS-ul temei.
3. Nucleul declară, în afara straturilor și cu `!important`, pe `html`, `body` și `[data-pg]`: `display`, `visibility`, `opacity`, `position`, `transform`, `clip-path`, `filter`, `content-visibility`, `font-size`, `color`, `background-color`, `height` și `overflow`. În cascadă, aceste declarații bat orice regulă dintr-un strat.
4. Randarea verifică prezența și unicitatea marcajelor `data-pg`.
5. Pe comandă, stare și „Rezervarea mea”, protecția acoperă tot conținutul nucleului și descendenții lui.
6. Elementele care cuprind un bloc al nucleului (strămoșii lui) nu pot fi tăiate: nucleul le impune `height: auto`, `max-height: none`, `overflow: visible` și `text-indent: 0`, iar pseudo-elementele lor (`::before`, `::after`) nu se afișează; măștile, `zoom`, `translate`, `scale` și `rotate` pe ele revin la valorile neutre, iar blocurile nucleului stau deasupra oricărui element al temei. Puneți decorurile și efectele pe elemente care nu cuprind blocuri ale nucleului.
7. Validatorul refuză, pe `html`, `body`, `main`, `footer`, pe clasele de subsol, pe selectorii fără o clasă a temei și pe cei cu `[data-pg]` sau `pg-` în `:has()`, `:is()`, `:where()` sau `:not()`: `display: none` sau `contents`, offseturi negative mari la orice poziție, un margin negativ de cel puțin o lățime de ecran, `text-indent` negativ, o lățime sub 1em și `translate`, `scale`, `rotate` sau `zoom` (efectele pe linkuri, butoane și elementele de text rămân). Pe blocurile nucleului (`[data-pg]`, `.pg-*`) refuză orice margin negativ și orice lățime, iar variabilele `--pg-*` le pune doar nucleul. În tot CSS-ul temei, `@property` e refuzat, iar `z-index` e un număr scris direct, de cel mult 10000. Pe aceleași elemente refuză și `opacity` sub 1, `transform`, `filter`, `clip-path`, `content-visibility`, măștile și un padding de cel puțin o lățime de ecran (efectele pe linkuri, butoane și elementele de text rămân); spațierea literelor și a cuvintelor stă între -0,1em și 0,5em, iar înălțimea rândului e de cel puțin 0,8. Pe blocurile nucleului, padding-ul e de cel mult 4em, iar pseudo-elementele lor rămân în flux. În `@font-face`, `size-adjust` și `*-override` stau între 50% și 200%.
8. Contactul, blocul legal și, pe pagina comenzii, totalul și acordul stau în zona nucleului, în afara containerului temei: CSS-ul temei nu ajunge acolo, iar zona stă deasupra oricărui element al temei, și al celor cu `position: fixed`. Containerul temei e un context de suprapunere: un `z-index` al temei contează doar înăuntrul lui. Elementele care se deschid deasupra paginii (`<dialog>`, `popover`) sunt refuzate. La o temă terță, zona nucleului folosește fontul de sistem al nucleului.
9. Containerul temei nu poate muta zona nucleului: nucleul îi fixează marginea, padding-ul și float-ul, iar validatorul refuză aceste proprietăți pe clasa de pe `<body>` (valorile neutre, `0` și `none`, rămân). Puneți spațiul pe elementele temei.
10. La fiecare randare, nucleul verifică HTML-ul temei: tagurile se închid în ordine, fără comentarii HTML și fără clasele `pg-tema`, `pg-nucleu`, `pg-corp` sau atributul `data-pg-nucleu`. O pagină care nu trece se servește cu tema de rezervă, iar gazda primește alerta. În șabloane, folosiți comentariile Twig, `{# … #}`. Sunt refuzate și `<xmp>`, `<noembed>`, `<noframes>`, `<plaintext>`, `<listing>`, `<noscript>`, caracterele de control și atributul `data-pg` (îl pun doar componentele nucleului). Pe paginile rezervării (comanda, starea, „Rezervarea mea”), conținutul temei nu are legături spre alte domenii.
11. Limita cunoscută: un element cu o clasă a temei care cuprinde un bloc din conținut (bara, calendarul, harta) și e ascuns altfel (de exemplu așezat în afara zonei vizibile de grilă) nu poate fi prins automat. De aceea gazda activează tema doar după previzualizare. Țineți bara, calendarul și harta vizibile.
12. Răspunderea (17.11.2026): protecțiile de mai sus rămân cum sunt, fără verificări în plus pentru temele terțe. Răspunderea pentru o temă terță e a gazdei care o încarcă și a autorului ei.

Ce rămâne la alegerea temei: marginile, spațierea, chenarele, rotunjirea, fontul, așezarea în grilă a blocului în pagină. Culorile componentelor se schimbă prin jetoane.

---

## 10. Sandboxul și limitele

Șabloanele temei rulează într-un mediu închis (sandbox). Contextul are doar date simple, fără obiecte. Tot textul se escapează automat ca HTML. Variabilele nedefinite produc eroare, deci verificați câmpurile opționale cu `is defined`, `default` sau `??`.

### 10.1. Ce este permis

| Categoria | Permis |
|---|---|
| Taguri | `if`, `for`, `set`, `block`, `extends`, `include`, `with`, `apply` (cu filtre permise), `verbatim` |
| `include` | nume scrise direct, doar din `partials/`, `sections/` sau `@schelet/partials/`, `@schelet/sections/` |
| `extends` | nume scrise direct, din `layout/`, `partials/`, `sections/` sau echivalentele lor din `@schelet/` |
| Funcții | `cazo.*`, `img`, `t`, `url`, `asset`, `cycle`, `min`, `max`, `range` |
| Filtre și teste | cele din secțiunea 8.4 |

### 10.2. Ce rămâne în afara sandboxului

- tagurile `macro`, `import`, `from`, `use`, `embed`, `sandbox`, `deprecated`, `cache`;
- filtrele `raw`, `map`, `filter`, `reduce`, `column`, `format`, `date`, `date_modify`, `json_encode`, `url_encode`, `convert_encoding`, `spaceless` și orice filtru cu funcție săgeată;
- funcțiile `template_from_string`, `source`, `constant`, `attribute`, `include()`, `dump`, `random`, `parent` cu nume calculat, `_self`;
- orice nume de șablon calculat la rulare;
- atribuirea în expresii (`=`) și operatorul `...`;
- metodele și proprietățile de obiect.

HTML-ul gata format vine doar din funcțiile nucleului; restul se escapează.

### 10.3. HTML-ul din șabloane

Validatorul refuză în șabloanele temei: `<script>`, `<iframe>`, `<object>`, `<embed>`, `<base>`, `<meta http-equiv>`, `<form>`, atributele `on*`, atributul `style=`, adresele `javascript:` și resursele de pe alte domenii. Șabloanele se citesc ca HTML5, cum le citește browserul (de exemplu `<meta/http-equiv=…>` e tot un `<meta http-equiv>`). Numele tagurilor și ale atributelor se scriu direct în șablon: o expresie Twig (`{{ … }}`, `include`) poate da doar conținutul unui element sau valoarea unui atribut, între ghilimele (`href="{{ c.url }}"`). Tagurile de control (`{% if %}`, `{% for %}`) pot sta între taguri, între atribute (după un spațiu sau după o valoare între ghilimele) și în valorile între ghilimele, de exemplu `<li {% if loop.first %}class="prima"{% endif %}>`; lipite de un nume (`<li{% if loop.first %} class=…`) sunt refuzate. O captură `{% set x %}…{% endset %}` se încheie cu tagurile închise și fără ghilimele drepte în text, un fișier se termină cu tagurile închise, iar `{% apply %}` primește doar `escape`, `upper`, `lower`, `capitalize`, `title`, `trim` și `nl2br`. Atributele `hidden` și `inert`, `<dialog>` și atributele de top layer (`popover`, `popovertarget`, `commandfor`, `command`, `interestfor`) sunt refuzate, iar blocurile nucleului nu stau într-un element cu `aria-hidden="true"` sau într-un `<details>` închis.

Formularele site-ului sunt ale nucleului (bara, calendarul, comanda). Stilul stă doar în fișierele CSS.

### 10.4. Limitele de resurse

| Limita | Valoarea |
|---|---|
| mărimea unui șablon | 64 KB și 4.000 de jetoane |
| imbricarea tagurilor cu corp și a parantezelor | 32 de niveluri |
| operatori, filtre și accesări într-un tag | 100 |
| adâncimea `include` și `extends` | 12 niveluri |
| pașii buclelor, pe toată pagina | 20.000 |
| o valoare produsă de un filtru, de `~` sau de `set` | 256 KB; o listă, cel mult 250.000 de elemente |
| sortarea unei liste cu elemente compuse | cel mult 128 de elemente |
| HTML-ul produs | 2 MB |
| timpul de randare | 3 secunde |
| memoria | 64 MB peste nivelul de pornire |

Peste o limită, randarea se oprește. Pagina se servește cu `_schelet`, iar gazda primește o alertă pe e-mail, cel mult una pe zi pentru aceeași temă și același motiv. Oaspetele primește mereu o pagină întreagă.

---

## 11. Fără JavaScript în temele terților

Temele terților conțin doar HTML, CSS, imagini și fonturi. Validatorul refuză orice fișier `.js` sau `.mjs`, cheia `js` din manifest, orice `<script>` și atributele `on*`, iar despachetarea refuză orice fișier executabil pe server.

Politica de securitate a paginilor cu o temă terță permite doar scripturile nucleului. Pe comandă, stare și „Rezervarea mea” rulează doar scripturile nucleului, indiferent de temă.

Interactivitatea vine din:

- componentele nucleului (bara, calendarul, rezumatul, harta);
- CSS: `<details>` și `<summary>`, `:target`, `:has()`, `scroll-snap` pentru caruseluri, animațiile la derulare cu `animation-timeline: view()`, tranzițiile între pagini cu `@view-transition { navigation: auto; }`.

Temele oficiale pot avea JavaScript de prezentare, revizuit și publicat odată cu programul.

---

## 12. Activele

| Folderul | Tipurile permise |
|---|---|
| `assets/css/` | `.css` |
| `assets/fonts/` | `.woff2`, plus textul licenței fontului (`.txt`) |
| `assets/img/` | `.png`, `.jpg`, `.jpeg`, `.webp`, `.avif`, `.svg` |
| `previews/` | `.webp`, `.png`, `.jpg` |
| rădăcina | `theme.json`, `settings.schema.json`, `LICENTA.txt`, `CHANGELOG.md`, `README.md`, captura temei (`captura.webp`, `.png` sau `.jpg`) |
| `layout/`, `partials/`, `sections/` | `.twig` (secțiunile și `.json`); `partials/` și `sections/` pot avea subfoldere |
| `templates/`, `locales/` | `.json` |

- Imaginile temei se recodifică la instalare (PNG, JPEG, WebP; AVIF unde serverul îl poate scrie), fără metadate. Fiecare imagine are cel mult 6000 de pixeli pe latură.
- SVG-urile se reconstruiesc dintr-o listă închisă de elemente și atribute: fără `<script>`, `<foreignObject>`, evenimente, `style`, DOCTYPE sau entități, iar `href` doar spre un `#id` din același fișier. Se afișează doar ca `<img>`.
- Activele se publică sub o adresă care conține amprenta temei. O versiune nouă primește o adresă nouă, deci memoria cache a browserului rămâne corectă.
- Fonturile au licența declarată în `theme.json`. Puneți textul licenței fontului în `assets/fonts/`, lângă fișierul fontului (de exemplu `assets/fonts/LICENTA-OFL.txt`).

Pozele proprietății nu fac parte din temă: le dă modelul, iar `img()` le afișează.

---

## 13. Validatorul, încărcarea și previzualizarea

### 13.1. Încărcarea în panou

Gazda încarcă arhiva `.zip` din panou, la Aspect, cu parola contului. Arhiva poate avea tema la rădăcină sau într-un singur folder de sus (cum o face „Comprimă folderul”). Validatorul rulează pe server și dă un raport cu erori blocante și avertismente, fiecare cu fișierul și rândul. Tema se instalează doar fără erori. Gazda o previzualizează cu datele site-ului (pagina de început, camerele, o cameră), apoi o activează; activarea cere din nou parola și rulează validatorul încă o dată. Panoul păstrează tema de dinainte, pentru revenire cu un clic.

Numele tehnic al temei (slugul) îl dă serverul, din `slug`-ul din manifest: o temă cu numele unei teme incluse primește un sufix (`cheie-2`). Încărcată din nou, aceeași temă devine o versiune nouă, cu același slug.

O versiune nouă a temei se încarcă la fel și trece prin aceeași validare. Versiunea anterioară rămâne disponibilă, deci revenirea e imediată. Validarea se reface și după fiecare actualizare a programului.

### 13.2. Ce verifică validatorul

1. **Arhiva.** Cel mult 15 MB, cel mult 60 MB despachetat, cel mult 800 de fișiere, cel mult 5 MB pe fișier, adâncime de cel mult 6 foldere, raport de compresie de cel mult 50:1. Numele de fișiere folosesc litere latine, cifre, `-`, `_` și `.`; niciun nume nu începe cu punct. Arhiva nu conține legături simbolice, intrări criptate, căi absolute sau `..`. Fișierele executabile pe server (`.php`, `.phtml`, `.phar`, `.cgi`, `.pl`, `.py`, `.sh`, `.htaccess`, `.user.ini` și altele) sunt refuzate, inclusiv cu extensii duble.
2. **Manifestul și schemele.** JSON valid, după schemele publicate în `scheme/` (`theme.schema.json`, `settings.schema.json`), versiune semver, `necesita` compatibil cu programul instalat, limbile `ro` și `en` prezente, fișierele declarate (fonturi, foi de stil) existente, valorile implicite ale setărilor potrivite cu tipul lor. O cheie necunoscută în manifest dă un avertisment.
3. **Șabloanele.** Fiecare `.twig` se compilează sub politica sandboxului. Raportul arată fișierul și linia oricărui tag, filtru, test sau funcție din afara listei, a oricărei variabile din afara modelului (sau nedefinite în temă cu `set`, `for` ori `with`) și a oricărui `include` spre un șablon care lipsește. O cheie `t()` care lipsește din fișierele de limbă dă un avertisment.
4. **Compoziția.** Șabloanele `acasa`, `camere`, `camera` și `pagina` există, trimit spre secțiuni existente (ale temei sau din `_schelet`), iar layoutul cheamă `cazo.head()` și `cazo.foot()`.
5. **HTML-ul scris în șabloane** (secțiunea 10.3).
6. **CSS-ul** (secțiunea 9.2): în plus, acoladele și comentariile închise, `url()` doar spre fișiere existente ale temei, `@font-face` doar cu fișiere `.woff2`.
7. **JavaScript-ul**: orice fișier sau referință e eroare blocantă.
8. **Imaginile**: se citesc, iar SVG-urile trec curățarea.
9. **Randarea de probă.** Tema rulează pe un site de probă izolat, cu date normale (trei camere, poze, texte în ambele limbi) și cu date extreme (50 de camere, zero poze, nume de 80 de caractere, traducerea engleză lipsă), în română și engleză, pe pagina de început, lista camerelor, pagina unei camere, o pagină a gazdei, căutare, o pagină legală și comandă. Se verifică: `lang` pe `<html>`, `<header>`, `<main>` și `<footer>`, un singur `<h1>`, `alt` și dimensiuni pe imagini, imaginea principală a camerei prin `img(..., true)`, marcajele `data-pg` o singură dată (blocul legal și contactul pe fiecare pagină; bara și harta pe pagina de început; bara, calendarul și rezumatul pe pagina camerei; comanda pe pagina comenzii), cel mult 150 ms (cu șabloanele deja compilate) și cel mult 512 KB de HTML pe pagină. O eroare a temei apare în raport cu motivul ei.
10. **Limbile**: cheile identice în `ro.json` și `en.json`, fără linia lungă (U+2014), fără Ș și Ț cu sedilă (și în etichetele din fișierele JSON).
11. **Fonturile**: fișiere WOFF2 valide, cu glifele U+0218-U+021B. Când serverul are extensia PHP `brotli`, validatorul citește glifele din font; altfel verifică intervalul `unicode-range` declarat în CSS pentru fiecare familie și arată un avertisment.
12. **Bugetele**: CSS-ul din `theme.json` (`css`) de cel mult 60 KB comprimat, fonturile declarate de cel mult 200 KB în total.

---

## 14. Tema de pornire și unealta pentru autori

Programul vine cu o temă de pornire, `_pornire`: un schelet minim, valid, cu toate fișierele obligatorii, gândit ca punct de plecare pentru temele noi. Tema de pornire e o temă oficială, sub Licența Cazo: tema pornită de la ea rămâne o temă terță și poate avea orice licență.

Unealta de linie de comandă `cazo-tema` rulează pe calculatorul dumneavoastră, cu PHP, fără Node:

| Comanda | Ce face |
|---|---|
| `cazo-tema init <folder> --autor="Numele dumneavoastră" [--nume="Numele temei"] [--licenta=MIT]` | creează o temă nouă din `_pornire`, cu slugul din numele folderului, autorul și licența dumneavoastră (implicit MIT) |
| `cazo-tema previzualizeaza <folder> [--port=8090] [--varianta=normal\|extrem]` | pornește un server local (`http://127.0.0.1:8090/`) cu datele de probă ale validatorului; o modificare se vede la reîncărcarea paginii, iar o eroare a temei apare în pagină, cu motivul |
| `cazo-tema verifica <folder> [--fara-proba] [--json]` | rulează validatorul complet, același ca pe server; codul de ieșire e 0 când tema trece |
| `cazo-tema impacheteaza <folder> [--iesire=tema.zip]` | verifică tema, apoi produce arhiva `.zip` deterministă (aceeași temă dă aceeași arhivă, cu aceeași amprentă SHA-256) și raportul `tema.raport.json` |

Unealta e un singur fișier, `cazo-tema.phar`, cu PHP 8.2 sau mai nou și extensiile `zip`, `pdo_sqlite`, `mbstring`, `dom` și `gd`. La prima rulare se despachetează în `~/.cache/cazo-tema/`. Din depozit, aceeași unealtă rulează cu `php bin/cazo-tema`, iar `bin/cazo tema-phar` construiește arhiva `.phar`.

Setările unei teme se pot exporta și importa ca JSON din panou, ca să mutați o configurare de pe un site pe altul.

---

## 15. Accesibilitatea și performanța

### 15.1. Accesibilitatea (WCAG 2.2 AA)

Componentele nucleului sunt deja accesibile: calendarul se folosește din tastatură, formularele au etichete și mesaje de eroare legate. Tema răspunde de restul paginii:

- `lang` pe `<html>` și pe fragmentele în altă limbă;
- reperele `<header>`, `<nav>`, `<main>`, `<footer>` și o legătură „Mergeți la conținut” la început;
- un singur `<h1>` pe pagină și titluri în ordine;
- text alternativ pe imagini (îl dă `img()`); imaginile decorative primesc `alt=""`;
- contrast de cel puțin 4,5:1 pentru text și 3:1 pentru elementele de interfață;
- ținte de cel puțin 44×44 px pe telefon;
- focus vizibil, neacoperit de antetul lipicios (folosiți `scroll-padding-top`);
- toate animațiile respectă `prefers-reduced-motion`;
- contactul stă în același loc pe toate paginile.

### 15.2. Performanța

Țintele, pe telefon (percentila 75):

| Indicatorul | Ținta |
|---|---|
| LCP | cel mult 1,8 s |
| INP | cel mult 150 ms |
| CLS | cel mult 0,05 |

Cum ajungeți acolo:

- imaginea principală prin `img(..., true)`, niciodată încărcată la derulare;
- dimensiuni pe toate imaginile (le pune `img()`);
- cel mult două fonturi preîncărcate, `font-display: swap`, subseturi latin și latin extins;
- nicio resursă de pe alte domenii;
- CSS mic: limita este 60 KB comprimat.

Paginile publice se țin întregi în memoria cache a serverului. HTML-ul e același pentru toți vizitatorii, iar prețurile și disponibilitatea le aduc componentele nucleului. Tema lucrează doar cu datele comune ale site-ului.

---

## 16. Licența temelor

Cazo este gratuit, iar codul sursă este public, sub Licența Cazo (fișierul `LICENSE`). Îl puteți folosi, copia, modifica și distribui liber, inclusiv în variante modificate. Singura condiție este linkul din subsol, „Software Regim Hotelier - Cazo.ro” (pe paginile în engleză, „Vacation Rental Software - Cazo.ro”), spre https://cazo.ro/, care rămâne la vedere pe fiecare pagină publică. Linkul stă în blocul legal al nucleului, în subsolul pe care tema nu îl atinge, deci apare cu orice temă; validatorul refuză regulile care ar ascunde subsolul.

Temele au regulile lor, în articolul 4 al licenței (fișierul `LICENSE` din depozit):

- **Temele terților pot avea orice licență aleasă de autor, inclusiv o licență plătită.** Fișierele unei teme terțe nu sunt considerate lucrare derivată a programului, iar folosirea interfeței documentate a temelor (formatul pachetului, modelul de vizualizare, funcțiile pentru șabloane și schema setărilor), inclusiv a componentelor pe care nucleul le adaugă paginii la randare, nu face din tema dumneavoastră o lucrare derivată.
- **Temele oficiale** (inclusiv `_schelet`) sunt sub Licența Cazo, la fel ca fișierele copiate din ele, și declară în `theme.json` identificatorul `LicenseRef-Cazo`.
- **Tema de pornire** `_pornire` e sub Licența Cazo, ca temele oficiale, dar o temă pornită de la ea, prin copierea și modificarea fișierelor ei, rămâne o temă terță, cu licența aleasă de dumneavoastră. `cazo-tema init` scrie în tema nouă numele și licența dumneavoastră (implicit MIT).

Declarați licența în `theme.json` la `licenta` (un identificator SPDX, de exemplu `MIT`, sau un text de cel mult 200 de caractere, de exemplu „Licență comercială pentru un site”) și puneți textul complet în `LICENTA.txt`. Panoul arată licența lângă numele temei.

---

## 17. Exemplu: o temă minimă, pas cu pas

Construim tema `lumina`: un antet, o secțiune proprie de prezentare și secțiunile din `_schelet` pentru restul.

### Pasul 1. Folderul și manifestul

Creați folderul `lumina/` și fișierul `theme.json`:

```json
{
  "slug": "lumina",
  "nume": {"ro": "Lumina", "en": "Light"},
  "versiune": "1.0.0",
  "autor": {"nume": "Studio Exemplu"},
  "licenta": "proprietară",
  "necesita": {"cazo": ">=1.0 <2.0", "model_vedere": "1"},
  "limbi": ["ro", "en"],
  "fonturi": [],
  "css": ["assets/css/lumina.css"]
}
```

### Pasul 2. Setările

`settings.schema.json`, cu fonturile de sistem și culori care trec verificarea de contrast:

```json
{
  "setari": [
    {"id": "culoare_fundal", "tip": "color", "implicit": "#ffffff"},
    {"id": "culoare_text", "tip": "color", "implicit": "#1c1c1c"},
    {"id": "culoare_accent", "tip": "color", "implicit": "#7a3b00"},
    {"id": "culoare_accent_contrast", "tip": "color", "implicit": "#ffffff"},
    {"id": "culoare_linie", "tip": "color", "implicit": "#767676"},
    {"id": "culoare_suprafata", "tip": "color", "implicit": "#f6f1ea"},
    {"id": "font_titlu", "tip": "font", "implicit": "serif"},
    {"id": "font_text", "tip": "font", "implicit": "sistem"},
    {"id": "raza", "tip": "range", "min": 0, "max": 32, "implicit": 8},
    {"id": "spatiu", "tip": "range", "min": 12, "max": 24, "implicit": 16}
  ]
}
```

### Pasul 3. Layoutul și antetul

`layout/baza.twig`:

```twig
<!doctype html>
<html lang="{{ site.limba }}">
<head>
{{ cazo.head() }}
</head>
<body class="lu-corp">
<a class="lu-salt" href="#continut">{{ t('salt_continut') }}</a>
{% include "partials/antet.twig" %}
<main id="continut">
{% block continut %}{% endblock %}
</main>
{{ cazo.foot() }}
</body>
</html>
```

`partials/antet.twig`:

```twig
<header class="lu-antet">
  <a class="lu-sigla" href="{{ site.url_baza }}">{{ site.nume }}</a>
  <nav aria-label="{{ t('meniu.principal') }}">
    <ul>
      {% for m in meniu.principal %}
        <li><a href="{{ m.url }}"{% if m.activ %} aria-current="page"{% endif %}>{{ m.titlu }}</a></li>
      {% endfor %}
      {% for l in site.limbi %}{% if not l.activa %}
        <li><a href="{{ l.url }}" hreflang="{{ l.cod }}" lang="{{ l.cod }}">{{ l.nume }}</a></li>
      {% endif %}{% endfor %}
    </ul>
  </nav>
</header>
```

Cheile `salt_continut` și `meniu.principal` vin din `_schelet`, deci tema nu trebuie să le repete.

### Pasul 4. O secțiune proprie

`sections/prezentare.json`:

```json
{"nume": {"ro": "Prezentarea", "en": "Introduction"}, "setari": [{"id": "titlu", "tip": "text", "implicit": "prezentare.titlu"}]}
```

`sections/prezentare.twig`:

```twig
{% if proprietate.descriere %}
<section class="lu-prezentare" aria-labelledby="{{ sectiune.id }}-t">
  <h2 id="{{ sectiune.id }}-t">{{ t(sectiune.setari.titlu) }}</h2>
  <p{% if proprietate.descriere_limba != site.limba %} lang="{{ proprietate.descriere_limba }}"{% endif %}>{{ proprietate.descriere|nl2br }}</p>
</section>
{% endif %}
```

### Pasul 5. Șabloanele paginilor

`templates/acasa.json` (bara de rezervare vine din `hero_cautare`, harta din `harta`):

```json
{"sectiuni": [
  {"tip": "hero_cautare", "setari": {"cu_poza": true, "varianta": "poza"}},
  {"tip": "prezentare"},
  {"tip": "camere_grila"},
  {"tip": "reguli_casa"},
  {"tip": "harta"}
]}
```

`templates/camere.json`:

```json
{"sectiuni": [{"tip": "camere_antet"}, {"tip": "bara"}, {"tip": "camere_grila"}]}
```

`templates/camera.json` (calendarul și rezumatul vin din `camera_rezervare`, bara din `bara`):

```json
{"sectiuni": [{"tip": "camera_antet"}, {"tip": "camera_rezervare"}, {"tip": "camera_fisa"}, {"tip": "camera_galerie"}, {"tip": "bara"}]}
```

`templates/pagina.json`:

```json
{"sectiuni": [{"tip": "pagina_corp"}]}
```

### Pasul 6. Textele

`locales/ro.json`:

```json
{
  "prezentare.titlu": "Despre loc",
  "hero.subtitlu": "Cazare în {localitate}, cu rezervare directă la gazdă."
}
```

`locales/en.json`:

```json
{
  "prezentare.titlu": "About the place",
  "hero.subtitlu": "A stay in {localitate}, booked directly with your host."
}
```

### Pasul 7. Stilul

`assets/css/lumina.css`:

```css
.lu-corp { font-family: var(--pg-font-text); line-height: 1.6; }
h1, h2, h3 { font-family: var(--pg-font-titlu); line-height: 1.2; }
a { color: var(--pg-culoare-accent); }

.lu-salt { position: absolute; left: -100vw; }
.lu-salt:focus { left: var(--pg-spatiu-2); top: var(--pg-spatiu-2); padding: var(--pg-spatiu-2); background: var(--pg-culoare-accent); color: var(--pg-culoare-accent-contrast); }

.lu-antet { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; padding: var(--pg-spatiu-3) var(--pg-spatiu-4); border-bottom: 1px solid var(--pg-culoare-linie); }
.lu-antet ul { display: flex; gap: var(--pg-spatiu-4); list-style: none; margin: 0; padding: 0; }
.lu-antet a { display: inline-flex; align-items: center; min-height: 44px; }

.lu-prezentare { max-width: 44rem; margin: 0 auto; padding: var(--pg-spatiu-6) var(--pg-spatiu-4); }

.sc-latime { max-width: 72rem; margin: 0 auto; padding: 0 var(--pg-spatiu-4); }
.sc-sectiune { padding: var(--pg-spatiu-6) 0; }
.sc-grila { display: grid; gap: var(--pg-spatiu-5); grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr)); list-style: none; padding: 0; }
.sc-card { background: var(--pg-culoare-suprafata); border-radius: var(--pg-raza); padding: var(--pg-spatiu-3); }

@media (prefers-reduced-motion: no-preference) {
  @view-transition { navigation: auto; }
}
```

### Pasul 8. Licența, istoricul, previzualizările

- `LICENTA.txt`: textul licenței alese;
- `CHANGELOG.md`: „1.0.0: prima versiune.”;
- `captura.webp` (600 x 750), declarată în `theme.json` la cheia `captura`: imaginea temei din lista pe care o vede gazda;
- `previews/1440.webp` și `previews/390.webp`: capturi ale paginii de început.

### Pasul 9. Verificarea și încărcarea

```
cazo-tema verifica lumina
cazo-tema impacheteaza lumina
```

Corectați fiecare eroare din raport, apoi încărcați arhiva din panou (Aspect › Încărcați o temă), previzualizați tema și activați-o.

### Lista de verificare înainte de publicare

- [ ] `theme.json` are `slug`, `nume`, `versiune`, `autor`, `licenta`, `necesita`, `limbi`, fără `js`
- [ ] `layout/baza.twig` are `lang`, `cazo.head()`, `<header>`, `<main>`, `cazo.foot()` și clasa temei pe `<body>`
- [ ] CSS-ul nu folosește `html`, `body` sau `:root` (stilurile generale stau pe clasa de pe `<body>`)
- [ ] pagina de început are bara de rezervare și harta; pagina camerei are bara, calendarul și rezumatul
- [ ] un singur `<h1>` pe fiecare pagină
- [ ] toate imaginile trec prin `img()`; imaginea principală are `prioritate` `true`
- [ ] culorile implicite trec verificarea de contrast
- [ ] CSS-ul folosește variabilele `--pg-*` și nu conține `!important`, `@import`, `@layer` sau adrese externe
- [ ] `ro.json` și `en.json` au aceleași chei, fără linia lungă (U+2014) și cu ș, ț corecte
- [ ] tema arată bine cu o cameră și cu 50 de camere, cu zero poze și cu multe poze
- [ ] animațiile respectă `prefers-reduced-motion`
