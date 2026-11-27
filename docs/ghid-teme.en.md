# Theme author guide

This guide shows how to build a theme for Cazo, the direct booking software. The host installs the software on their own domain; the host fills in the property data in the dashboard, or it comes from EasyCheck when the site is linked to it. You will see the same name in the code, in functions (`cazo.head()`) and in tools (`bin/cazo-tema`).

The guide is written for web developers who know HTML, CSS and a template language. Themes use Twig 3. Once you have read it, you can build a complete theme without any other document.

Theme files use Romanian names for keys, folders and settings (for example `nume` = name, `setari` = settings, `sectiuni` = sections). This guide keeps them exactly as they are, since they are part of the contract, and explains each one.

---

## 1. What a theme is

A theme decides how the host's site looks. It receives ready-made data and places it on the page. The booking logic, the price and the legal texts belong to the core.

### 1.1. The boundary between theme and core

| The theme decides | The core decides |
|---|---|
| the structure of the presentation pages and the order of sections | the booking bar, the calendar, the summary and the search |
| typography, galleries, CSS animations | the price, the total, the rate plan and the cancellation policy shown next to the price |
| colours and fonts, through the settings in the schema | payment and the entire content of the checkout, the status page and the "My booking" page |
| the look of every public page, including checkout, status and "My booking" | the legal block, the SAL pictogram, the contact details and the legal links |
| its own labels, in Romanian and English | the emails to guests (with the theme colours) |
| | the interactive map and the weather |
| | the HTTP headers, the content security policy (CSP), metadata, Microdata, `hreflang` |

In short: the theme positions and styles the core components. Their content stays unchanged.

### 1.2. Three basic rules

1. **The theme receives only data.** Every name, number, distance, time or price on the page comes from the view model (section 6). The texts written in the theme are generic labels, the same for every host: "Rooms", "Surroundings", "House rules".
2. **The theme shows only what can be true.** Claims the software cannot check stay out of the theme: "best price", "chosen by most guests", "we answer around the clock". The same goes for invented decoration (temperatures, access codes, made-up figures).
3. **Missing data leaves no trace.** When a field is empty, its label disappears. Sections without data disappear entirely.

### 1.3. The pages of the site

| Page | Address (RO / EN) | Built by |
|---|---|---|
| Home | `/` | the theme, from `templates/acasa.json` |
| Rooms | `/camere` / `/rooms` | the theme, from `templates/camere.json` |
| A room | `/camere/{slug}` / `/rooms/{slug}` | the theme, from `templates/camera.json` |
| A host page | `/pagina/{slug}` / `/page/{slug}` | the theme, from `templates/pagina.json` |
| Search, legal pages | for example `/legal-information`, `/terms` | the core, inside the theme layout |
| Checkout, status, "My booking" | | the core, inside the theme layout |

The pages built by the core use the theme's `layout/baza.twig`. The core fills the `{% block continut %}` block. This way, the whole site carries the theme's design.

---

## 2. Package structure

A theme is a folder, delivered as a `.zip` archive. The folder name is the theme identifier (`slug`): lowercase letters, digits and hyphens.

```
lumina/
  theme.json                 the manifest (required)
  settings.schema.json       the global settings (required)
  layout/
    baza.twig                the layout of every page (required)
  templates/
    acasa.json               home, recommended
    camere.json              rooms, recommended
    camera.json              one room, recommended
    pagina.json              host page, recommended
  sections/
    prezentare.twig          a section
    prezentare.json          its schema
  partials/
    antet.twig               reusable pieces
  assets/
    css/lumina.css
    img/pattern.webp
    fonts/text.woff2
  locales/
    ro.json                  required
    en.json                  required
  previews/
    1440.webp                preview on a wide screen
    390.webp                 preview on a phone
  LICENTA.txt                the license
  CHANGELOG.md
```

The templates in `templates/` are recommended: a missing template is taken from `_schelet` (section 2.1).

### 2.1. The `_schelet` library

The core has a base theme, `_schelet` (skeleton). It contains accessible, fast sections, ready to use: search as the first screen, the room grid, the gallery, the room details, the calendar with the summary, the house rules, the map, the property text.

Your theme inherits three things from `_schelet` automatically:

- **sections**: a section `tip` (type) missing from the theme's `sections/` is looked up in `_schelet`;
- **page templates**: a file missing from `templates/` is taken from `_schelet`;
- **texts**: the keys in the theme's `locales/` are added on top of those in `_schelet` and replace the ones with the same name.

You can include a section or a partial from the library explicitly:

```twig
{% include "@schelet/sections/harta.twig" %}
{% include "@schelet/partials/subsol.twig" %}
```

`_schelet` is also the fallback theme. If your theme fails while rendering, the page is served with `_schelet` and the host receives an alert (section 10).

The `_schelet` sections use classes prefixed with `sc-`. Your theme's CSS can style them directly.

Sections currently available in `_schelet`:

| `tip` | What it shows |
|---|---|
| `hero_cautare` | the property name, the subtitle and the booking bar, optionally with the main photo |
| `bara` | the booking bar |
| `camere_antet` | the title of the rooms page |
| `camere_grila` | the room cards, with photo, capacity and "from X lei" |
| `text_proprietate` | the property description |
| `galerie_proprietate` | the property photos |
| `reguli_casa` | the times and the house rules |
| `harta` | the map, loaded on click |
| `camera_antet` | the room title, the starting price and the main photo |
| `camera_rezervare` | the room calendar and summary |
| `camera_fisa` | the room details: guests, bed, bathroom, area, view, amenities |
| `camera_galerie` | the room photos |
| `pagina_corp` | the content of a host page |

The library will grow to about 25 sections (hero variants, carousel, frequently asked questions, surroundings, contact, quotes).

---

## 3. The `theme.json` manifest

```json
{
  "slug": "lumina",
  "nume": {"ro": "Lumina", "en": "Light"},
  "versiune": "1.0.0",
  "autor": {"nume": "Example Studio", "url": "https://example.com"},
  "licenta": "proprietary",
  "necesita": {"cazo": ">=1.0 <2.0", "model_vedere": "1"},
  "limbi": ["ro", "en"],
  "pentru": "city apartment",
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

| Key | Required | Meaning |
|---|---|---|
| `slug` | yes | the identifier you propose: lowercase letters, digits, hyphen, at most 64 characters; on upload, the site generates the theme slug from it (without overwriting another theme) |
| `nume` | yes | the name shown in the dashboard, in Romanian and English |
| `versiune` | yes | the theme version, in semver format (`1.0.0`) |
| `autor` | yes | the author's name (`nume`) and, optionally, address (`url`) |
| `licenta` | yes | the license you chose (section 16) |
| `necesita` | yes | the compatible versions of the software (`cazo`) and of the view model (`model_vedere`) |
| `limbi` | yes | the theme languages; `ro` and `en` are required |
| `pentru` | no | the type of property the theme is designed for, shown to the host |
| `captura` | no | the screenshot of the home page, in the theme root: `captura.webp`, `captura.png` or `captura.jpg`. It is the small image in the list of themes, in the dashboard and in the installer; without it, the list shows the theme name. At most 200 KB and 1600 px on a side; 640 x 480 recommended |
| `latime_rem` | no | the width of the theme's content column, in rem (40 to 90). The core pages (search, booking, legal pages) and the footer take the same width, so the theme header and their content share one column; 72 when missing. A core block placed on a card in the surface colour takes the card colour when one of its ancestors has the class `pg-pe-suprafata`. |
| `componente` | no | the interactive core components the theme asks for (section 8.3) |
| `continut` | no | the content blocks the dashboard offers the host for this theme |
| `fonturi` | no | the theme's own fonts: family, `.woff2` file, license and whether it is preloaded |
| `css` | no | the stylesheets, in loading order; without this key, every file in `assets/css/` loads, in alphabetical order |
| `stiluri` | no | the visual variants of the theme (for example winter and summer) |
| `buget` | no | the target CSS size, in compressed KB |
| `privata` | no | only for themes shipped with the software: `{"domenii": ["example.com"]}`; the theme stays out of the catalog and activates only on the listed domains (with or without `www.`) |

Fonts marked `"preincarcat": true` are preloaded, at most two. Without a mark, the first font in the list is preloaded.

The `js` key is reserved for official themes. In a third-party theme, its presence causes the theme to be rejected (section 11).

---

## 4. Settings in `settings.schema.json`

Global settings are the ones the host changes from the dashboard without code: colours, fonts, corner rounding, spacing. Theme settings are visual. Content comes from data, not from settings.

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

Each setting has `id`, `tip` (type) and `implicit` (default). `range` settings also have `min` and `max`, and `select` or `radio` settings have `optiuni` (options).

### 4.1. CSS tokens

From the settings named above, the core builds a file of CSS variables. The theme uses them in its styles. Write colours through the variables, so the host's choices apply everywhere.

| Setting | CSS variable | Values |
|---|---|---|
| `culoare_fundal` | `--pg-culoare-fundal` | background, hex colour `#rrggbb` |
| `culoare_text` | `--pg-culoare-text` | text, hex colour |
| `culoare_accent` | `--pg-culoare-accent` | accent, hex colour |
| `culoare_accent_contrast` | `--pg-culoare-accent-contrast` | the text colour on the accent |
| `culoare_linie` | `--pg-culoare-linie` | borders and separators |
| `culoare_suprafata` | `--pg-culoare-suprafata` | the background of highlighted blocks |
| `font_titlu` | `--pg-font-titlu` | headings: a font declared in `theme.json`, or `sistem`, `serif`, `mono` |
| `font_text` | `--pg-font-text` | body text, same options |
| `raza` | `--pg-raza` | corner radius, 0-32, in pixels |
| `spatiu` | `--pg-spatiu-1` ... `--pg-spatiu-8` | base 12-24; the scale is 0.25, 0.5, 0.75, 1, 1.5, 2, 3 and 4 times the base |
| | `--pg-umbra` | the core's standard shadow |

An invalid value (a colour in a format other than `#rrggbb`, an undeclared font, a radius or spacing base outside its range) falls back to the core's default value, not to the one in the theme schema.

### 4.2. Contrast

On save, the core checks the colour pairs against WCAG 2.2:

| Text | Background | Minimum ratio |
|---|---|---|
| `culoare_text` | `culoare_fundal` | 4.5:1 |
| `culoare_text` | `culoare_suprafata` | 4.5:1 |
| `culoare_accent_contrast` | `culoare_accent` | 4.5:1 |
| `culoare_accent` | `culoare_fundal` | 4.5:1 |
| `culoare_linie` | `culoare_fundal` | 3:1 |

Below the threshold, the save is refused. The message shows the required ratio, the current ratio and the nearest shade of the text colour that passes on all its backgrounds (for example `#767676` instead of `#999999` on white). The validator requires defaults that pass every pair. A `color_scheme` setting (background and text) is checked at 4.5:1.

### 4.3. Setting types

The types used in themes today: `color`, `font`, `range`, `select`, `checkbox` and `text`. A `text` setting in a section holds the key of a label from `locales/` (example in section 7.2).

The full schema (`scheme/settings.schema.json`) also includes `color_scheme` (background and text pairs), `radio`, `image` (uploaded through the core and re-encoded as WebP or PNG, without SVG, up to 8 MB; the value is the image address), `url` (http, https or relative), `textarea`, `richtext` (a closed list of tags: `p`, `strong`, `em`, `a`, `ul`, `ol`, `li`, `h2`-`h4`, `br`) and `continut` (the key of a content block from the dashboard). Free code fields and free HTML stay out of the schema; the text of a setting reaches the template as text, escaped like any other value.

Each setting may have `eticheta` and `ajutor`, in Romanian and English (`{"ro": "...", "en": "..."}`); without them, the dashboard uses its own labels for the known names (`culoare_fundal`, `font_titlu` ...) and the `id` for the rest. A `select` or `radio` setting has `optiuni`: plain strings (`["simplu", "poza"]`) or objects `{"valoare": "poza", "eticheta": {"ro": "Cu fotografie", "en": "With a photo"}}`. `range` settings may have `pas` and `unitate`.

In the dashboard, under Appearance, the host downloads the theme settings as a JSON file and uploads them again, on the same site or on another one with the same theme; the import goes through the same checks, contrast included.

The values of all global settings reach the templates in the `setari` variable, for example `setari.raza`.

---

## 5. Language files

The site has Romanian at the root and English under `/en/`. The theme has two required files, `locales/ro.json` and `locales/en.json`, with the same keys.

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

In a template, you request a text with `t()` and pass parameters in a mapping:

```twig
<p>{{ t('hero.subtitlu', {localitate: proprietate.adresa.localitate}) }}</p>
```

Text rules:

- the keys in `ro.json` and `en.json` are identical;
- keys are simple, with dots for grouping (`reguli.checkin`);
- a key missing in English falls back to Romanian, then to the key name;
- Romanian uses ș and ț with a comma below (U+0218-U+021B), never with a cedilla;
- instead of the em dash (U+2014), use a colon, a comma or a new sentence;
- the texts are affirmative; in Romanian they address the guest with the polite "dumneavoastră";
- the texts do not name the software's technical providers.

For plurals (for example "1 night", "2 nights") use the core filter `nopti`. For prices, the `lei` filter (section 8.4).

A text that comes from data in a language other than the page gets the `lang` attribute. The model tells you the real language through the `titlu_limba` and `descriere_limba` fields:

```twig
<h3{% if c.titlu_limba != site.limba %} lang="{{ c.titlu_limba }}"{% endif %}>{{ c.titlu }}</h3>
```

---

## 6. The view model

The view model is the contract between the core and themes. It contains only lists, mappings and simple values (text, numbers, true or false). Its current version is `1`, declared in `theme.json` under `necesita.model_vedere`.

The data (the property, rooms, photos, prices) comes from EasyCheck or, for a standalone site, from the dashboard, plus what the host fills in. The theme receives the same model either way. The host's company details are not part of the model: they appear only in the legal block, rendered by the core.

Volumes are bounded: at most 50 rooms, at most 200 photos and at most 50 sections per page.

### 6.1. Top-level variables

| Variable | Contents |
|---|---|
| `site` | site data: name, language, languages, base address |
| `proprietate` | the property: name, type, description, address, times, rules, contact, photos |
| `camere` | the list of visible rooms, in the order chosen by the host |
| `camera` | the room of the current page, only on a room page; otherwise `null` |
| `pagina` | the current page: type, title, sections |
| `sectiune` | the current section, inside a file in `sections/` |
| `continut` | the content blocks written by the host in the dashboard, in the page language |
| `setari` | the values of the theme's global settings |
| `meniu` | the main menu and the footer links |
| `cerere` | the current search (arrival, departure, rooms), when there is one; otherwise `null` |
| `vreme` | the weather, when the theme uses it and the data is at most 3 hours old; otherwise `null`: `temperatura`, `stare` (senin, partial_noros, noros, ceata, burnita, ploaie, ninsoare, furtuna), `text`, `zile[{zi, eticheta, min, max, stare, text}]` (today and the next 2 days), `rasarit`, `apus`, `sursa`, `sursa_url`. The data comes from MET Norway, and the source is shown next to it (CC BY 4.0 licence) |

### 6.2. `site`

| Field | Example |
|---|---|
| `site.nume` | "Casa din deal" |
| `site.limba` | `ro` |
| `site.limbi[]` | `{cod: "en", url: "/en/", activa: false, nume: "English"}` |
| `site.url_baza` | the home page address, in the current language |
| `site.mod_proba` | `true` when the site runs on a test key |
| `site.an` | the current year |
| `site.sigla` | the logo uploaded by the host in the dashboard, or `null`: `{url, latime, inaltime, cu_nume}`. In the header, `<img class="pg-sigla-img" src="{{ site.sigla.url }}" width="{{ site.sigla.latime }}" height="{{ site.sigla.inaltime }}" alt="...">`: the core sets the height on computers and phones, as the host chose. With `cu_nume` false, the host wants the logo alone (then `alt` is the site name); with `cu_nume` true, the name stays next to it (and `alt` stays empty). Without a logo, the header shows the name, as before. |

### 6.3. `proprietate`

| Field | Contents |
|---|---|
| `nume` | the property name |
| `tip` | the type, as a key (`apartament`, `pensiune`, `vila` ...) |
| `descriere`, `descriere_limba` | the short description and its real language |
| `descriere_titlu`, `descriere_paragrafe[]` | the title on the first line of the description (when short and followed by text, otherwise empty) and the description split into paragraphs, without the title; show it whole, for example the first paragraph and the rest in a `<details>` |
| `adresa.strada`, `adresa.localitate`, `adresa.judet` | the building address: street, town, county |
| `coordonate.lat`, `coordonate.lng` | the coordinates, or `null` |
| `ore.checkin_de_la`, `ore.plecare_pana_la` | check-in from and check-out until, for example `15:00` and `11:00` |
| `ore.checkin_pana_la` | the latest check-in time, or empty text (any time after the start) |
| `reguli_casa` | `copii` (children), `bebelusi` (infants), `animale` (pets), `animale_max`, `evenimente` (events), `fumat` (smoking); each may be `null` |
| `anulare_zile` | the days of free cancellation, or `null` |
| `contact.email`, `contact.telefon` | the published contact details |
| `linkuri_platforme[]` | links to other platforms, when the host shows them |
| `spatii_comune[]` | the property's shared spaces: `{tip, eticheta}` (garden, shared terrace, parking ...) |
| `poze[]` | the property photos (6.5) |

The platform scores sit in `recenzii[]`, at the top level: `{platforma, scor, scala, numar, actualizat_la}`, only Booking.com (out of 10) and Airbnb (out of 5). Show each score with its platform and date; without scores, the list is empty.

### 6.4. `camere[]` and `camera`

| Field | Contents |
|---|---|
| `id`, `slug`, `url` | the identifier, the address segment and the room page address |
| `titlu`, `titlu_limba` | the title and its real language |
| `descriere` | one optional sentence |
| `unitati` | how many units of this kind the property has |
| `oaspeti_max` | the capacity, or `null` |
| `fisa.pat`, `fisa.baie`, `fisa.suprafata_mp`, `fisa.vedere` | the room details: bed, bathroom, area, view; empty fields are empty strings or `null` |
| `dotari[]` | the labels of the selected amenities, in the page language |
| `poze[]`, `poza_principala` | the room photos and the first of them |
| `pret_de_la.suma`, `pret_de_la.text` | the lowest price per night, in bani (minor units), and the ready-formatted text; or `null` |
| `spatii[]` | the unit's spaces, in the host's order: `{tip, index, eticheta, paturi[{tip, numar, eticheta}], baie, poze[]}`; an empty list until the host fills them in |
| `paturi[]`, `baie_tip`, `suprafata_mp` | all the unit's beds, the bathroom (`proprie`, `comuna` or empty) and the area, or `null` |

For the price, show `pret_de_la.text`. The core formats it ready for display (for example "from RON 250.00 per night"). When the price is missing, the field is `null` and the theme shows another label (for example "Price on request").

### 6.5. `poza` (photo)

| Field | Contents |
|---|---|
| `sha` | the photo identifier |
| `alt` | the alternative text, written by the core |
| `rol` | the photo role, when there is one (otherwise the category) |
| `categorie` | what the photo shows (`exterior`, `dormitor`, `baie` ...), or empty text |
| `legenda` | the caption, already chosen by the core (the host's text, otherwise the space, otherwise the category), or empty text |
| `index`, `spatiu` | the number and id of the space the photo belongs to, when the host chose it |
| `latime`, `inaltime` | the original width and height, in pixels |

A photo is shown through the `img()` function (8.2), which also builds the image addresses.

### 6.6. `pagina`, `sectiune`, `meniu`, `cerere`

- `pagina.tip`: `acasa`, `camere`, `camera`, `pagina` or the type of a core page; `pagina.titlu`; `pagina.sectiuni[]`; `pagina.seo.descriere`.
- `sectiune.id`: an identifier unique on the page (`s0`, `s1` ...), good for `id` and `aria-labelledby`; `sectiune.tip`; `sectiune.setari`: the section settings, with defaults filled in; `sectiune.blocuri[]`.
- `meniu.principal[]` and `meniu.subsol[]`: `{titlu, url, activ}`. The main menu holds Home, Rooms and the pages published by the host. The footer holds the legal pages.
- `cerere.sosire`, `cerere.plecare` (format `YYYY-MM-DD`), `cerere.camere[]`: the search from the address, already validated.

### 6.7. What is coming to the model

The theme content model adds new fields, in the same contract version:

- **Photo categories.** Each photo gets `categorie`, `index` and `legenda`. The visible caption is the category label, with an index when there are several ("Bedroom 2"). The alternative text follows the form "{Label}, {property name}, photo {n}". The categories, in gallery order: `exterior`, `intrare` (entrance), `living`, `camera` (room), `dormitor` (bedroom), `bucatarie` (kitchen), `loc_de_masa` (dining area), `baie` (bathroom), `spatiu_lucru` (workspace), `balcon`, `terasa`, `gradina` (garden or yard), `piscina` (pool), `spatii_comune` (common areas), `restaurant`, `parcare` (parking), `priveliste` (view), `imprejurimi` (surroundings), `altele` (other, without a caption).
- **The spaces of a unit.** `camere[].spatii[]`: `{tip, index, eticheta, paturi[], poze[]}`, built by the core from the number of bedrooms, bathrooms and other spaces, in a fixed order: living room, room, bedrooms, kitchen or kitchenette, bathrooms, workspace, balcony or terrace or garden. The theme reads the labels ("Bedroom 1", "Bathroom 2") from the list.
- **The property form.** `proprietate.forma`: `A` (one whole unit), `B` (several units of the same kind) or `C` (several room types). With form A, the home page shows the spaces, without room cards.
- **A ready gallery.** `galerie.numar`, `galerie.grupe[]` (photos by category) and `galerie.grila[]` (one photo per category).
- **Landmarks.** `repere[]`: between 0 and 5 nearby places, with `nume`, `categorie`, `minute`, `mod` and `principal`. The "Surroundings" section appears from the first landmark.
- **Times.** `ore.checkin_pana_la` (check-in until), when the host fills it in.

Build the theme so that it works with any number of items: a studio with one room and zero landmarks, just like a guesthouse with 50 rooms.

---

## 7. Page composition

### 7.1. JSON templates

Each file in `templates/` states which sections the page has and in what order. It contains no HTML.

```json
{"sectiuni": [
  {"tip": "hero_cautare", "setari": {"cu_poza": true, "varianta": "poza"}},
  {"tip": "camere_grila"},
  {"tip": "prezentare"},
  {"tip": "reguli_casa"},
  {"tip": "harta"}
]}
```

Each item has `tip` (the section name) and, optionally, `setari`. The settings given here replace the defaults from the section schema. An unknown `tip` is skipped and logged.

The host's choices for sections (the order, the hidden sections, their settings) are stored apart from the theme, so they are kept when the theme is updated.

### 7.2. Sections

A section has two files with the same name: `sections/<tip>.twig` and `sections/<tip>.json`.

The section schema:

```json
{
  "nume": {"ro": "Prezentarea", "en": "Introduction"},
  "setari": [
    {"id": "titlu", "tip": "text", "implicit": "prezentare.titlu"},
    {"id": "cu_poza", "tip": "checkbox", "implicit": true}
  ]
}
```

The section template:

```twig
<section class="lu-prezentare" aria-labelledby="{{ sectiune.id }}-t">
  <h2 id="{{ sectiune.id }}-t">{{ t(sectiune.setari.titlu) }}</h2>
  {% if proprietate.descriere %}
    <p{% if proprietate.descriere_limba != site.limba %} lang="{{ proprietate.descriere_limba }}"{% endif %}>{{ proprietate.descriere|nl2br }}</p>
  {% endif %}
</section>
```

The `titlu` setting holds a key from `locales/`, so the title translates itself. Section titles are fixed theme labels, the same for every host.

A section schema can also define `blocuri` (repeatable items, configured by the host) and `presetari` (ready-made variants of the section). Its shape is in `scheme/settings.schema.json`, under `$defs/sectiune`; a section without its `.json` file is a validator error.

### 7.3. The layout

`layout/baza.twig` is the common frame of every page. The core extends it and places the sections in `{% block continut %}`.

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

The layout must include: `lang` on `<html>`, `cazo.head()` in `<head>`, `<header>`, `<main>` and `cazo.foot()` as the last element in `<body>`.

The core owns `<body>`: what you write between `<body>` and `</body>` goes into a `<div class="pg-tema …">` container, with the classes and attributes you put on `<body>` (for example `lu-corp`). After the container, the core adds its own footer, with the contact details and the legal block, on every page, and with the test key it adds the "Test site" banner at the top. The theme's own footer (logo, menu, social links) is optional and sits in the container, before the core footer.

---

## 8. Core functions

Core functions produce HTML from the core's templates, outside the theme. Their output is safe and complete, with the `data-pg` marker.

### 8.1. The `cazo.*` functions

| Function | Where | What it produces |
|---|---|---|
| `cazo.head()` | required, in `<head>` | `meta`, the title, `canonical`, `hreflang`, Open Graph, preloading of fonts and of the main image, the core stylesheets, the CSS variables and the theme CSS |
| `cazo.foot()` | required, last in `<body>` | the core scripts for the components in use |
| `cazo.bara_rezervare()` | required on `acasa`; on the room page the dates are chosen in `cazo.rezumat(camera)`, so the official themes leave the bar out there | the search and booking bar |
| `cazo.calendar(camera)` | required on `camera` | the room calendar, with prices and free days |
| `cazo.rezumat(camera)` | required on `camera` | the summary of the chosen stay and the total |
| `cazo.harta()` | required on `acasa` or on the contact page | the address, the map frame and the navigation links; the interactive map loads when the guest clicks |
| `cazo.continut()` | in the section of content pages | the content prepared by the core (the host page text, the legal pages, the search) |
| `cazo.vreme()` | optional | the 3-day weather at the property (MET Norway), with its source and the link to the licence; hidden when the data is older than 3 hours |
| `cazo.componenta(nume, optiuni)` | optional | the special components of the official themes |

Each component appears at most once per page. The core places the contact details and the legal block (provider details, SAL, legal links) in its footer, after the theme content, on every page. `cazo.contact()` and `cazo.subsol_legal()` remain valid in themes written earlier and output nothing.

### 8.2. General functions

| Function | What it does |
|---|---|
| `img(poza, latimi, prioritate, sizes)` | produces `<picture>` with AVIF, WebP and JPEG, plus correct `srcset`, `sizes`, `width`, `height`, `loading` and `fetchpriority` |
| `t(cheie, parametri)` | the theme text in the page language |
| `url(ruta, parametri)` | the address of a page, in the current language |
| `asset(cale)` | the public address of a file in `assets/` |
| `cycle`, `min`, `max`, `range` | the usual Twig functions; `range` returns at most 100 items |

`img()`:

- `latimi` (widths) is a list of the values 480, 960, 1440 and 2048;
- `prioritate` (priority) is `true` only for the main image of the page (LCP), which loads immediately and is preloaded; every other image loads on scroll (`loading="lazy"`);
- `sizes` describes the displayed width, as in HTML.

```twig
{{ img(proprietate.poze|first, [960, 1440, 2048], true, '100vw') }}
{{ img(c.poza_principala, [480, 960], false, '(min-width: 48rem) 33vw, 100vw') }}
```

`url()` knows the routes `acasa`, `camere`, `camera` (with `{slug: ...}`), `pagina` (with `{slug: ...}`), `disponibil`, `date_legale`, `termeni`, `confidentialitate`, `cookie` and `accesibilitate`.

```twig
<a href="{{ url('camere') }}">{{ t('camere.titlu') }}</a>
<a href="{{ url('camera', {slug: c.slug}) }}">{{ c.titlu }}</a>
```

`asset()` takes a path in `img/`, `fonts/`, `css/` or `js/`, without the `assets/` prefix:

```twig
<img src="{{ asset('img/pattern.webp') }}" alt="" width="1200" height="80">
```

### 8.3. Core components

The bar, the calendar, the summary and the map are web components: `<cazo-bara>`, `<cazo-calendar>`, `<cazo-rezumat>`, `<cazo-harta>`. Their first view is rendered on the server, so they also work without JavaScript. The core loads their scripts, only on the pages where they appear.

You style them in three ways:

1. **through tokens**: the components use the `--pg-*` variables, so they pick up the colours, fonts and rounding chosen by the host on their own;
2. **through the `pg-*` classes** in their markup, for example `.pg-bara-formular`, `.pg-camp`, `.pg-buton`, `.pg-calendar-luna`, `.pg-legal-linkuri`;
3. **through the page structure**: the components live in the page's ordinary DOM, without Shadow DOM, so you place them with the usual layout rules (grid, flex, spacing) on the theme's containers; `::part()` is not used.

Every core marker carries the `data-pg` attribute (for example `data-pg="bara"`, `data-pg="legal"`). Use it for positioning, not for hiding (section 9).

### 8.4. Filters

Allowed Twig filters: `escape` (`e`), `default`, `length`, `upper`, `lower`, `capitalize`, `title`, `trim`, `join`, `split`, `slice`, `first`, `last`, `keys`, `sort` (without a comparison function), `reverse`, `replace` (with a literal mapping), `nl2br`, `striptags`, `abs`, `round`, `batch`.

Core filters:

| Filter | Example | Result |
|---|---|---|
| `lei` | `{{ c.pret_de_la.suma\|lei }}` | "1.450,00 lei" in Romanian, "RON 1,450.00" in English; takes the amount in bani |
| `data` | `{{ cerere.sosire\|data }}` | "20 dec. 2026" / "20 Dec 2026" |
| `nopti` | `{{ 3\|nopti }}` | "3 nights", with the correct plural |
| `scurtat` | `{{ proprietate.descriere\|scurtat(160) }}` | the text cut at a whole word, with "…" |

Allowed tests: `defined`, `empty`, `null`, `none`, `odd`, `even`, `iterable`, `same as`, `divisible by`, `sequence`, `mapping`.

---

## 9. Theme CSS and the legal block

### 9.1. Layers

The core publishes the theme CSS wrapped in the `@layer tema` layer. The core's base styles sit in the `nucleu` layer, declared before the theme. The order is `@layer nucleu, tema;`, so the theme rules beat the core's base styles.

Write plain CSS, without `@layer`: the core adds the layer. The core also prefixes every selector with the theme container (`:where(.pg-tema)`), so your rules apply only to the theme content, with the same specificity. Put the page-wide styles (font, colours, background) on the class on `<body>`, for example `.lu-corp`, which moves to the container.

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

### 9.2. What theme CSS accepts

- `url()` only to the theme's own files, with a relative path (`../fonts/...`, `../img/...`);
- local fonts only, in `woff2` format, with the Romanian glyphs Ș, ș, Ț, ț (U+0218-U+021B);
- the `--pg-*` variables, modern selectors, `@media`, `@supports`, `@container`, animations, `@view-transition`.

The validator rejects: selectors on `html`, `body` and `:root` (the core styles them; use the class on `<body>`), on `.pg-tema`, `.pg-nucleu`, `.pg-corp` and `[data-pg-nucleu]`, a selector that starts with a combinator (`>`, `+`, `~`), nested rules (write each selector in full), `!important`, `@import`, `@layer`, `url()` to other domains, `data:` or absolute paths, `expression`, `behavior`, `-moz-binding`, `javascript:` and fonts in formats other than `woff2`. The `--pg-*` variables are set by the core, from the host's settings, with the contrast checked: the theme uses them with `var(--pg-…)` without redefining them. The `\` character (CSS escapes) is allowed only inside strings, for example `content: "\A"`, and not in `url()` addresses: the browser would read `!\important` as `!important`.

### 9.3. The legal block stays visible

The legal details, the contact details, the total, the cancellation policy and the final button are the host's legal obligations. That is why the core protects them:

1. The theme CSS always sits in `@layer tema`.
2. `!important` is rejected in theme CSS.
3. The core declares, outside any layer and with `!important`, on `html`, `body` and `[data-pg]`: `display`, `visibility`, `opacity`, `position`, `transform`, `clip-path`, `filter`, `content-visibility`, `font-size`, `color`, `background-color`, `height` and `overflow`. In the cascade, these declarations beat any rule inside a layer.
4. Rendering checks that the `data-pg` markers are present and unique.
5. On checkout, status and "My booking", the protection covers all core content and its descendants.
6. The elements that contain a core block (its ancestors) cannot be cut off: the core sets `height: auto`, `max-height: none`, `overflow: visible` and `text-indent: 0` on them, and their pseudo-elements (`::before`, `::after`) are not shown; masks, `zoom`, `translate`, `scale` and `rotate` on them return to neutral values, and the core blocks stay above any theme element. Put decorations and effects on elements that do not contain core blocks.
7. On `html`, `body`, `main`, `footer`, footer classes, selectors without a theme class and selectors with `[data-pg]` or `pg-` inside `:has()`, `:is()`, `:where()` or `:not()`, the validator rejects `display: none` or `contents`, large negative offsets with any positioning, a negative margin of at least one screen width, a negative `text-indent`, a width under 1em and `translate`, `scale`, `rotate` or `zoom` (effects on links, buttons and text elements stay allowed). On the core blocks (`[data-pg]`, `.pg-*`) it rejects any negative margin and any width, and only the core sets the `--pg-*` variables. In the whole theme CSS, `@property` is rejected, and `z-index` is a plain number of at most 10000. On the same elements it also rejects `opacity` below 1, `transform`, `filter`, `clip-path`, `content-visibility`, masks and a padding of at least one screen width (effects on links, buttons and text elements stay allowed); letter and word spacing stay between -0.1em and 0.5em, and the line height is at least 0.8. On the core blocks, padding is at most 4em and their pseudo-elements stay in the flow. In `@font-face`, `size-adjust` and `*-override` stay between 50% and 200%.
8. The contact details, the legal block and, on the order page, the total and the consent sit in the core area, outside the theme container: the theme CSS does not reach them, and the area stays above any theme element, including those with `position: fixed`. The theme container is a stacking context: a theme `z-index` counts only inside it. Elements that open above the page (`<dialog>`, `popover`) are rejected. For a third-party theme, the core area uses the core system font.
9. The theme container cannot move the core area: the core fixes its margin, padding and float, and the validator rejects these properties on the class on `<body>` (neutral values, `0` and `none`, are accepted). Put the spacing on the theme elements.
10. On every render, the core checks the theme HTML: tags close in order, with no HTML comments and without the `pg-tema`, `pg-nucleu`, `pg-corp` classes or the `data-pg-nucleu` attribute. A page that fails is served with the fallback theme, and the host receives an alert. In templates, use Twig comments, `{# … #}`. `<xmp>`, `<noembed>`, `<noframes>`, `<plaintext>`, `<listing>`, `<noscript>`, control characters and the `data-pg` attribute (set only by core components) are also rejected. On the booking pages (order, status, "My booking"), the theme content has no links to other domains.
11. Known limit: an element with a theme class that contains a block from the content (the bar, the calendar, the map) and is hidden some other way (for example placed outside the visible area by the grid) cannot be caught automatically. That is why the host activates a theme only after the preview. Keep the bar, the calendar and the map visible.
12. Responsibility (17 November 2026): the protections above stay as they are, with no further checks for third-party themes. Responsibility for a third-party theme lies with the host who uploads it and with its author.

What remains the theme's choice: margins, spacing, borders, rounding, the font, and how the block sits in the page grid. Component colours change through the tokens.

---

## 10. The sandbox and its limits

Theme templates run in a closed environment (sandbox). The context holds only simple data, with no objects. All text is escaped as HTML automatically. Undefined variables raise an error, so check optional fields with `is defined`, `default` or `??`.

### 10.1. What is allowed

| Category | Allowed |
|---|---|
| Tags | `if`, `for`, `set`, `block`, `extends`, `include`, `with`, `apply` (with allowed filters), `verbatim` |
| `include` | names written literally, only from `partials/`, `sections/` or `@schelet/partials/`, `@schelet/sections/` |
| `extends` | names written literally, from `layout/`, `partials/`, `sections/` or their `@schelet/` equivalents |
| Functions | `cazo.*`, `img`, `t`, `url`, `asset`, `cycle`, `min`, `max`, `range` |
| Filters and tests | those in section 8.4 |

### 10.2. What stays outside the sandbox

- the tags `macro`, `import`, `from`, `use`, `embed`, `sandbox`, `deprecated`, `cache`;
- the filters `raw`, `map`, `filter`, `reduce`, `column`, `format`, `date`, `date_modify`, `json_encode`, `url_encode`, `convert_encoding`, `spaceless` and any filter with an arrow function;
- the functions `template_from_string`, `source`, `constant`, `attribute`, `include()`, `dump`, `random`, `parent` with a computed name, `_self`;
- any template name computed at run time;
- assignment inside expressions (`=`) and the `...` operator;
- object methods and properties.

Ready-made HTML comes only from the core functions; everything else is escaped.

### 10.3. HTML in templates

The validator rejects in theme templates: `<script>`, `<iframe>`, `<object>`, `<embed>`, `<base>`, `<meta http-equiv>`, `<form>`, `on*` attributes, the `style=` attribute, `javascript:` addresses and resources from other domains. Templates are read as HTML5, the way the browser reads them (for example `<meta/http-equiv=…>` is still a `<meta http-equiv>`). Tag and attribute names are written directly in the template: a Twig expression (`{{ … }}`, `include`) may only give the content of an element or the value of an attribute, in quotes (`href="{{ c.url }}"`). Control tags (`{% if %}`, `{% for %}`) may stand between tags, between attributes (after a space or after a quoted value) and inside quoted values, for example `<li {% if loop.first %}class="first"{% endif %}>`; glued to a name (`<li{% if loop.first %} class=…`) they are rejected. A `{% set x %}…{% endset %}` capture ends with its tags closed and has no straight quotes in its text, a file ends with its tags closed, and `{% apply %}` accepts only `escape`, `upper`, `lower`, `capitalize`, `title`, `trim` and `nl2br`. The `hidden` and `inert` attributes, `<dialog>` and the top-layer attributes (`popover`, `popovertarget`, `commandfor`, `command`, `interestfor`) are rejected, and the core blocks do not sit inside an element with `aria-hidden="true"` or inside a closed `<details>`.

The site's forms belong to the core (the bar, the calendar, the checkout). Styles live only in CSS files.

### 10.4. Resource limits

| Limit | Value |
|---|---|
| template size | 64 KB and 4,000 tokens |
| nesting of body tags and of brackets | 32 levels |
| operators, filters and accesses in one tag | 100 |
| `include` and `extends` depth | 12 levels |
| loop steps, over the whole page | 20,000 |
| a value produced by a filter, by `~` or by `set` | 256 KB; a list, at most 250,000 items |
| sorting a list of compound items | at most 128 items |
| HTML output | 2 MB |
| render time | 3 seconds |
| memory | 64 MB above the starting level |

Beyond a limit, rendering stops. The page is served with `_schelet` and the host receives an email alert, at most one per day for the same theme and the same reason. The guest always receives a whole page.

---

## 11. No JavaScript in third-party themes

Third-party themes contain only HTML, CSS, images and fonts. The validator rejects any `.js` or `.mjs` file, the `js` key in the manifest, any `<script>` and `on*` attributes, and unpacking rejects any file executable on the server.

The security policy of pages with a third-party theme allows only the core's scripts. On checkout, status and "My booking", only the core's scripts run, whatever the theme.

Interactivity comes from:

- the core components (the bar, the calendar, the summary, the map);
- CSS: `<details>` and `<summary>`, `:target`, `:has()`, `scroll-snap` for carousels, scroll-driven animations with `animation-timeline: view()`, page transitions with `@view-transition { navigation: auto; }`.

Official themes may include presentational JavaScript, reviewed and published together with the software.

---

## 12. Assets

| Folder | Allowed types |
|---|---|
| `assets/css/` | `.css` |
| `assets/fonts/` | `.woff2`, plus the font license text (`.txt`) |
| `assets/img/` | `.png`, `.jpg`, `.jpeg`, `.webp`, `.avif`, `.svg` |
| `previews/` | `.webp`, `.png`, `.jpg` |
| the root | `theme.json`, `settings.schema.json`, `LICENTA.txt`, `CHANGELOG.md`, `README.md`, the theme screenshot (`captura.webp`, `.png` or `.jpg`) |
| `layout/`, `partials/`, `sections/` | `.twig` (and `.json` for sections); `partials/` and `sections/` may have subfolders |
| `templates/`, `locales/` | `.json` |

- Theme images are re-encoded on installation (PNG, JPEG, WebP; AVIF where the server can write it), without metadata. Each image is at most 6000 pixels per side.
- SVG files are rebuilt from a closed list of elements and attributes: no `<script>`, `<foreignObject>`, events, `style`, DOCTYPE or entities, and `href` only to an `#id` in the same file. They are shown only as `<img>`.
- Assets are published under an address that contains the theme fingerprint. A new version gets a new address, so the browser cache stays correct.
- Fonts have their license declared in `theme.json`. Put the font license text in `assets/fonts/`, next to the font file (for example `assets/fonts/LICENTA-OFL.txt`).

The property photos are not part of the theme: the model provides them and `img()` displays them.

---

## 13. The validator, upload and preview

### 13.1. Uploading in the dashboard

The host uploads the `.zip` archive from the dashboard, under Appearance, with their account password. The archive may hold the theme at its root or inside a single top folder (as "Compress folder" makes it). The validator runs on the server and produces a report with blocking errors and warnings, each with its file and line. The theme installs only with zero errors. The host previews it with the site's data (home page, rooms, one room), then activates it; activation asks for the password again and runs the validator once more. The dashboard keeps the previous theme, for a one-click return.

The server sets the theme's technical name (slug) from the `slug` in the manifest: a theme with the name of an included theme gets a suffix (`cheie-2`). Uploaded again, the same theme becomes a new version, with the same slug.

A new version of the theme uploads the same way and goes through the same validation. The previous version stays available, so going back is instant. Validation runs again after every software update.

### 13.2. What the validator checks

1. **The archive.** At most 15 MB, at most 60 MB unpacked, at most 800 files, at most 5 MB per file, a depth of at most 6 folders, a compression ratio of at most 50:1. File names use Latin letters, digits, `-`, `_` and `.`; names starting with a dot are rejected. The archive holds no symbolic links, encrypted entries, absolute paths or `..`. Files executable on the server (`.php`, `.phtml`, `.phar`, `.cgi`, `.pl`, `.py`, `.sh`, `.htaccess`, `.user.ini` and others) are rejected, including with double extensions.
2. **The manifest and the schemas.** Valid JSON, following the schemas published in `scheme/` (`theme.schema.json`, `settings.schema.json`), a semver version, `necesita` compatible with the installed software, the `ro` and `en` languages present, the declared files (fonts, style sheets) present, setting defaults that match their type. An unknown key in the manifest gives a warning.
3. **The templates.** Each `.twig` compiles under the sandbox policy. The report shows the file and line of any tag, filter, test or function outside the list, of any variable outside the model (or never defined in the theme with `set`, `for` or `with`) and of any `include` of a missing template. A `t()` key missing from the language files gives a warning.
4. **The composition.** The `acasa`, `camere`, `camera` and `pagina` templates exist and point to existing sections (the theme's or `_schelet`'s), and the layout calls `cazo.head()` and `cazo.foot()`.
5. **The HTML written in templates** (section 10.3).
6. **The CSS** (section 9.2): in addition, braces and comments closed, `url()` only to existing theme files, `@font-face` only with `.woff2` files.
7. **JavaScript**: any file or reference is a blocking error.
8. **The images**: they are readable, and SVG files pass the cleaning.
9. **The test render.** The theme runs on an isolated test site, with normal data (three rooms, photos, texts in both languages) and with extreme data (50 rooms, zero photos, 80-character names, the English translation missing), in Romanian and English, on the home page, the room list, a room page, a host page, search, a legal page and the order page. It checks: `lang` on `<html>`, `<header>`, `<main>` and `<footer>`, a single `<h1>`, `alt` and dimensions on images, the room's main image through `img(..., true)`, the `data-pg` markers exactly once (the legal block and the contact on every page; the bar and the map on the home page; the bar, the calendar and the summary on the room page; the order on the order page), at most 150 ms (with the templates already compiled) and at most 512 KB of HTML per page. A theme error shows in the report with its reason.
10. **The languages**: identical keys in `ro.json` and `en.json`, no em dash (U+2014), no Ș or Ț with a cedilla (also in the labels of the JSON files).
11. **The fonts**: valid WOFF2 files, with the U+0218-U+021B glyphs. When the server has the PHP `brotli` extension, the validator reads the glyphs from the font; otherwise it checks the `unicode-range` declared in the CSS for each family and shows a warning.
12. **The budgets**: the CSS listed in `theme.json` (`css`) at most 60 KB compressed, the declared fonts at most 200 KB in total.

---

## 14. The starter theme and the author tool

The software ships with a starter theme, `_pornire`: a minimal, valid skeleton with every required file, meant as the starting point for new themes. The starter theme is an official theme, under the Cazo License: a theme started from it remains a third-party theme and may have any license.

The `cazo-tema` command-line tool runs on your computer, with PHP, without Node:

| Command | What it does |
|---|---|
| `cazo-tema init <folder> --autor="Your name" [--nume="Theme name"] [--licenta=MIT]` | creates a new theme from `_pornire`, with the slug taken from the folder name, your name and your license (MIT by default) |
| `cazo-tema previzualizeaza <folder> [--port=8090] [--varianta=normal\|extrem]` | starts a local server (`http://127.0.0.1:8090/`) with the validator's sample data; a change shows when you reload the page, and a theme error shows in the page, with its reason |
| `cazo-tema verifica <folder> [--fara-proba] [--json]` | runs the full validator, the same one as on the server; the exit code is 0 when the theme passes |
| `cazo-tema impacheteaza <folder> [--iesire=tema.zip]` | checks the theme, then produces the deterministic `.zip` archive (the same theme gives the same archive, with the same SHA-256 fingerprint) and the `tema.raport.json` report |

The tool is a single file, `cazo-tema.phar`, for PHP 8.2 or newer with the `zip`, `pdo_sqlite`, `mbstring`, `dom` and `gd` extensions. On its first run it unpacks itself into `~/.cache/cazo-tema/`. From the repository, the same tool runs as `php bin/cazo-tema`, and `bin/cazo tema-phar` builds the `.phar` archive.

A theme's settings can be exported and imported as JSON from the dashboard, to move a configuration from one site to another.

---

## 15. Accessibility and performance

### 15.1. Accessibility (WCAG 2.2 AA)

The core components are already accessible: the calendar works from the keyboard, and forms have labels and linked error messages. The theme is responsible for the rest of the page:

- `lang` on `<html>` and on fragments in another language;
- the `<header>`, `<nav>`, `<main>`, `<footer>` landmarks and a "Skip to content" link at the start;
- a single `<h1>` per page and headings in order;
- alternative text on images (`img()` provides it); decorative images get `alt=""`;
- contrast of at least 4.5:1 for text and 3:1 for interface elements;
- touch targets of at least 44×44 px on phones;
- a visible focus, not covered by a sticky header (use `scroll-padding-top`);
- every animation respects `prefers-reduced-motion`;
- the contact details sit in the same place on every page.

### 15.2. Performance

The targets, on mobile (75th percentile):

| Metric | Target |
|---|---|
| LCP | at most 1.8 s |
| INP | at most 150 ms |
| CLS | at most 0.05 |

How to get there:

- the main image through `img(..., true)`, never loaded on scroll;
- dimensions on every image (`img()` adds them);
- at most two preloaded fonts, `font-display: swap`, Latin and Latin Extended subsets;
- no resources from other domains;
- small CSS: the limit is 60 KB compressed.

Public pages are cached whole on the server. The HTML is the same for every visitor, and prices and availability arrive through the core components. The theme works only with the data shared by the whole site.

---

## 16. Theme licensing

Cazo is free and its source code is public, under the Cazo License (the `LICENSE` file). You may use, copy, modify and distribute it freely, including modified versions. The only condition is the footer link, “Software Regim Hotelier - Cazo.ro” (on the English pages, “Vacation Rental Software - Cazo.ro”), to https://cazo.ro/, which stays visible on every public page. The link sits in the core legal block, in the footer the theme does not touch, so it shows with any theme; the validator rejects the rules that would hide the footer.

Themes have their own rules, in section 4 of the license (the `LICENSE` file in the repository):

- **Third-party themes may use any license the author chooses, including a paid license.** The files of a third-party theme are not considered a work derived from the software, and using the documented theme interface (the package format, the view model, the template functions and the settings schema), including the components the core adds to the page at render time, does not make your theme a derived work.
- **Official themes** (including `_schelet`) are under the Cazo License, like the files copied from them, and declare the `LicenseRef-Cazo` identifier in `theme.json`.
- **The starter theme** `_pornire` is under the Cazo License, like the official themes, but a theme started from it, by copying and changing its files, remains a third-party theme, under the license you choose. `cazo-tema init` writes your name and your license into the new theme (MIT by default).

Declare the license in `theme.json` under `licenta` (an SPDX identifier, for example `MIT`, or a text of up to 200 characters, for example "Commercial license for one site") and put the full text in `LICENTA.txt`. The dashboard shows the license next to the theme name.

---

## 17. Example: a minimal theme, step by step

We build the `lumina` theme: a header, one custom introduction section, and the `_schelet` sections for everything else.

### Step 1. The folder and the manifest

Create the `lumina/` folder and the `theme.json` file:

```json
{
  "slug": "lumina",
  "nume": {"ro": "Lumina", "en": "Light"},
  "versiune": "1.0.0",
  "autor": {"nume": "Example Studio"},
  "licenta": "proprietary",
  "necesita": {"cazo": ">=1.0 <2.0", "model_vedere": "1"},
  "limbi": ["ro", "en"],
  "fonturi": [],
  "css": ["assets/css/lumina.css"]
}
```

### Step 2. The settings

`settings.schema.json`, with system fonts and colours that pass the contrast check:

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

### Step 3. The layout and the header

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

The `salt_continut` and `meniu.principal` keys come from `_schelet`, so the theme does not need to repeat them.

### Step 4. A custom section

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

### Step 5. The page templates

`templates/acasa.json` (the booking bar comes from `hero_cautare`, the map from `harta`):

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

`templates/camera.json` (the calendar and the summary come from `camera_rezervare`, the bar from `bara`):

```json
{"sectiuni": [{"tip": "camera_antet"}, {"tip": "camera_rezervare"}, {"tip": "camera_fisa"}, {"tip": "camera_galerie"}, {"tip": "bara"}]}
```

`templates/pagina.json`:

```json
{"sectiuni": [{"tip": "pagina_corp"}]}
```

### Step 6. The texts

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

### Step 7. The styles

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

### Step 8. License, changelog, previews

- `LICENTA.txt`: the text of the license you chose;
- `CHANGELOG.md`: "1.0.0: first version.";
- `captura.webp` (640 x 480), declared in `theme.json` under the `captura` key: the image of the theme in the list the host sees;
- `previews/1440.webp` and `previews/390.webp`: screenshots of the home page.

### Step 9. Checking and uploading

```
cazo-tema verifica lumina
cazo-tema impacheteaza lumina
```

Fix every error in the report, then upload the archive from the dashboard (Appearance › Upload a theme), preview the theme and activate it.

### Checklist before publishing

- [ ] `theme.json` has `slug`, `nume`, `versiune`, `autor`, `licenta`, `necesita`, `limbi`, and no `js`
- [ ] `layout/baza.twig` has `lang`, `cazo.head()`, `<header>`, `<main>`, `cazo.foot()` and the theme class on `<body>`
- [ ] the CSS does not use `html`, `body` or `:root` (page-wide styles sit on the class on `<body>`)
- [ ] the home page has the booking bar and the map; the room page has the bar, the calendar and the summary
- [ ] a single `<h1>` on every page
- [ ] every image goes through `img()`; the main image has `prioritate` set to `true`
- [ ] the default colours pass the contrast check
- [ ] the CSS uses the `--pg-*` variables and contains no `!important`, `@import`, `@layer` or external addresses
- [ ] `ro.json` and `en.json` have the same keys, no em dash (U+2014) and correct ș, ț
- [ ] the theme looks good with one room and with 50 rooms, with zero photos and with many photos
- [ ] animations respect `prefers-reduced-motion`
