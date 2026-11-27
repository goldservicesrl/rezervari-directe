# Cazo, software rezervări directe

Site de rezervări directe pentru pensiuni, apartamente în regim hotelier și hoteluri mici. Gazda îl instalează pe domeniul ei, pe o găzduire obișnuită cu cPanel. Site-ul merge de sine stătător, cu camerele și prețurile puse în panoul lui, sau legat de EasyCheck Pro, de unde le preia singur.

*English below.*

## Ce face

- **Rezervări directe pe domeniul gazdei:** căutarea pe date, camerele cu totalul pe sejur, calendarul cu prețurile și zilele libere, comanda pe o singură pagină.
- **Plata online prin contul Stripe al gazdei** (cardul și celelalte metode active în cont), cu avansul și restul la sosire, **transferul bancar** în contul gazdei sau **plata la sosire**, prin cerere acceptată de gazdă.
- **Un singur calendar:** cu EasyCheck Pro, fiecare rezervare ajunge în EasyCheck, alături de cele de pe platforme; fără el, calendarul se leagă de platforme prin iCal.
- **Teme** gata făcute, în română și în engleză, plus un sistem de teme documentat pentru cine își face tema proprie.
- **Elementele legale** cerute în România: datele operatorului și legătura ANPC pe fiecare pagină, informarea precontractuală pe pagina de comandă, contractul pe e-mail.
- **Siguranță:** un singur fișier PHP public, datele sensibile criptate, fără actualizări automate: versiunile oficiale se descarcă de pe GitHub.

## Obligațiile legale ale unei cazări în România

Un site de rezervări directe nu te scutește de obligațiile de regim hotelier. Pe scurt, în 2026 o cazare din România are de regulă:

- **fișa de cazare** pentru fiecare oaspete (date de identificare, păstrate și puse la dispoziția autorităților);
- **anunțarea oaspeților străini la IGI** (Inspectoratul General pentru Imigrări);
- **fișa de ocupare a capacității de cazare**;
- **taxa locală de turism / taxa specială** stabilită prin hotărâre de consiliu local;
- **factura / e-Factura** și regimul de TVA aplicabil.

Cazo se ocupă de rezervarea directă și de plată. Pentru partea legală — fișe, anunțarea la IGI, taxe locale, e-Factura — poți folosi [EasyCheck](https://easycheck.ro/), de la aceeași echipă. Ghiduri explicate: [fișa de cazare](https://easycheck.ro/ghid/model-fisa-de-cazare), [anunțarea la IGI](https://easycheck.ro/ghid/anuntarea-la-igi), [taxele de turism 2026](https://easycheck.ro/ghid/taxe-turism-2026), [e-Factura](https://easycheck.ro/ghid/e-factura-regim-hotelier).

> Informațiile de mai sus sunt orientative. Verifică obligațiile exacte pentru situația ta; actele și cotele se pot schimba.

## Cerințe

- PHP 8.2 sau mai nou, cu extensiile `pdo_mysql` sau `pdo_sqlite`, `curl`, `openssl`, `mbstring`, `json`, `zip`, `gd` (extensia `sodium` e recomandată; site-ul merge și fără ea);
- MySQL sau MariaDB (recomandat), ori SQLite;
- Apache, LiteSpeed sau OpenLiteSpeed, cu HTTPS;
- opțional, un cont EasyCheck Pro, cu o cheie pentru site (fără el, site-ul merge de sine stătător);
- pentru plata online, un cont Stripe al gazdei.

## Instalare, pe scurt

1. Descărcați arhiva ultimei versiuni din pagina de versiuni a proiectului.
2. Urcați-o în folderul contului de găzduire (cel care conține `public_html`) și extrageți-o.
3. Deschideți `https://domeniul-dumneavoastră.ro/instalare/` și urmați asistentul.

Pașii compleți sunt în [ghidul gazdei](docs/ghid-gazda.md).

## Documentație

| Pentru cine | Română | English |
|---|---|---|
| Gazde | [Ghidul gazdei](docs/ghid-gazda.md) | [Host guide](docs/ghid-gazda.en.md) |
| Autorii de teme | [Ghidul pentru teme](docs/ghid-teme.md) | [Theme guide](docs/ghid-teme.en.md) |
| Dezvoltatori | [Ghidul pentru dezvoltatori](docs/dezvoltare.md) | [Developer guide](docs/dezvoltare.en.md) |
| Integrare | [API-ul public EasyCheck](https://easycheck.ro/api/) | [EasyCheck public API](https://easycheck.ro/api/) |

## Licență

Cazo este gratuit, iar codul sursă este public, sub **Licența Cazo** (fișierul `LICENSE`, în română și în engleză). Îl puteți folosi, copia, modifica și distribui liber, inclusiv în variante modificate. Singura condiție este linkul din subsol, „Software Regim Hotelier - Cazo.ro” (pe paginile în engleză, „Vacation Rental Software - Cazo.ro”), spre https://cazo.ro/, care rămâne la vedere pe fiecare pagină publică.

**Fără garanție:** softul este oferit „ca atare”, fără nicio garanție, iar titularul nu răspunde pentru folosirea lui (articolul 6 din `LICENSE`). La instalare, condițiile se acceptă cu o bifă.

**Temele:** temele făcute de alți autori pentru acest soft pot avea orice licență aleasă de autorul lor, inclusiv o licență plătită, iar fișierele lor nu sunt considerate lucrări derivate ale softului (articolul 4 din `LICENSE`). Temele oficiale incluse în soft sunt sub Licența Cazo.

## Componente terțe

Softul include biblioteci scrise de alți autori, fiecare cu licența ei, păstrată lângă fișierele ei; Licența Cazo nu se aplică lor. Trimiterea e-mailurilor nu folosește o bibliotecă: clientul SMTP e al softului.

| Componenta | Versiunea | Licența | Unde |
|---|---|---|---|
| Twig | 3.30.0 | BSD-3-Clause | `vendor/twig/` |
| Symfony Deprecation Contracts | 3.8.1 | MIT | `vendor/symfony/deprecation-contracts/` |
| Symfony Polyfill Ctype | 1.37.0 | MIT | `vendor/symfony/polyfill-ctype/` |
| sodium_compat | 2.5.2 | ISC | `vendor/paragonie/sodium_compat/` (doar când găzduirea n-are extensia `sodium`) |
| Leaflet | 1.9.4 | BSD-2-Clause | `front/vendor/leaflet/` |

Fonturile temelor sunt sub SIL Open Font License 1.1, cu textul licenței în `assets/fonts/` al fiecărei teme: Montan (Fraunces, Source Sans 3), Bulevard (Inter, Space Grotesk), Faleză (DM Serif Display, Manrope), Hotel (Cormorant, Jost), Rustic (Alegreya, Alegreya Sans), Studio (Bodoni Moda, Instrument Sans). Versiunea și amprenta SHA-256 a fiecărui fișier stau în `vendor/VENDOR.lock` și în `front/vendor/VENDOR.lock`.

## Teste

Depozitul conține testele care rulează fără servicii externe (unitare, de securitate, ale migrărilor, ale textelor și ale arhivei de lansare): `php teste/run.php`. Testele de integrare cu EasyCheck, testele contractului API și probele în browser rulează în depozitul de dezvoltare, înaintea fiecărei versiuni.

## Ajutor

Softul se oferă gratuit, așa cum este. Pentru ajutor la instalare sau la găzduire, vă puteți adresa firmei de găzduire sau unui programator.

## Securitate

Problemele de securitate se raportează doar prin raportarea privată a vulnerabilităților de pe GitHub (fila **Security** a depozitului, **Report a vulnerability**), nu public. Nu promitem un răspuns sau un termen. Detaliile sunt în [SECURITY.md](SECURITY.md).

---

# Cazo, direct booking software

A direct booking website for guesthouses, holiday apartments and small hotels. The host installs it on their own domain, on ordinary cPanel hosting. The site runs standalone, with rooms and prices set in its own dashboard, or linked to EasyCheck Pro, which it picks them up from.

## What it does

- **Direct bookings on the host's domain:** date search, rooms with the total price for the stay, a calendar with prices and free days, and a one-page checkout.
- **Online payment through the host's own Stripe account** (cards and the other payment methods enabled in the account), with a deposit and the rest paid on arrival, **bank transfer** into the host's account, or **payment on arrival**, through a booking request the host accepts.
- **One calendar:** with EasyCheck Pro, every booking goes into EasyCheck, next to the bookings from the booking platforms; without it, the calendar links to the platforms through iCal.
- **Ready-made themes**, in Romanian and English, plus a documented theme system for anyone who wants their own.
- **The legal elements required in Romania:** the operator details and the ANPC link on every page, the pre-contract information on the checkout page, the contract by email.
- **Security:** a single public PHP file, encrypted sensitive data, no automatic updates: official versions are downloaded from GitHub.

## Legal obligations of an accommodation business in Romania

A direct booking website does not exempt you from the obligations of the Romanian accommodation regime. In short, in 2026 an accommodation business in Romania usually has:

- **a guest registration form** for every guest (identification details, kept and made available to the authorities);
- **reporting foreign guests to IGI** (the General Inspectorate for Immigration);
- **the accommodation occupancy form**;
- **the local tourist tax / special tax** set by a local council decision;
- **invoices / e-Factura** and the applicable VAT regime.

Cazo handles the direct booking and the payment. For the legal side — registration forms, IGI reporting, local taxes, e-Factura — you can use [EasyCheck](https://easycheck.ro/), from the same team. Guides (in Romanian): [registration form](https://easycheck.ro/ghid/model-fisa-de-cazare), [IGI reporting](https://easycheck.ro/ghid/anuntarea-la-igi), [tourist taxes 2026](https://easycheck.ro/ghid/taxe-turism-2026), [e-Factura](https://easycheck.ro/ghid/e-factura-regim-hotelier).

> The information above is indicative. Check the exact obligations for your situation; laws and rates can change.

## Requirements

- PHP 8.2 or newer, with the `pdo_mysql` or `pdo_sqlite`, `curl`, `openssl`, `mbstring`, `json`, `zip` and `gd` extensions (the `sodium` extension is recommended; the site works without it);
- MySQL or MariaDB (recommended), or SQLite;
- Apache, LiteSpeed or OpenLiteSpeed, with HTTPS;
- optionally, an EasyCheck Pro account with a key for the site (without it, the site runs standalone);
- for online payment, the host's own Stripe account.

## Installation in short

1. Download the latest release archive from the project's releases page.
2. Upload it to your hosting account folder (the one that contains `public_html`) and extract it.
3. Open `https://your-domain.com/instalare/` and follow the setup wizard.

The full steps are in the [host guide](docs/ghid-gazda.en.md).

## License

Cazo is free and its source code is public, under the **Cazo License** (the `LICENSE` file, in Romanian and English; the Romanian text prevails). You may use, copy, modify and distribute it freely, including modified versions. The only condition is the footer link, “Software Regim Hotelier - Cazo.ro” (on the English pages, “Vacation Rental Software - Cazo.ro”), to https://cazo.ro/, which stays visible on every public page.

**No warranty:** the software is provided “as is”, without any warranty, and the licensor is not liable for its use (section 6 of `LICENSE`). The terms are accepted with a tick box during installation.

**Themes:** themes made by other authors for this software may use any license their author chooses, including a paid one, and their files are not considered derivative works of the software (section 4 of `LICENSE`). The official themes included with the software are under the Cazo License.

## Third-party components

The software includes libraries written by other authors, each under its own license, kept next to its files; the Cazo License does not apply to them. Sending email uses no library: the SMTP client is part of the software.

| Component | Version | License | Where |
|---|---|---|---|
| Twig | 3.30.0 | BSD-3-Clause | `vendor/twig/` |
| Symfony Deprecation Contracts | 3.8.1 | MIT | `vendor/symfony/deprecation-contracts/` |
| Symfony Polyfill Ctype | 1.37.0 | MIT | `vendor/symfony/polyfill-ctype/` |
| sodium_compat | 2.5.2 | ISC | `vendor/paragonie/sodium_compat/` (only when the hosting lacks the `sodium` extension) |
| Leaflet | 1.9.4 | BSD-2-Clause | `front/vendor/leaflet/` |

The theme fonts are under the SIL Open Font License 1.1, with the license text in each theme's `assets/fonts/`: Mountain (Fraunces, Source Sans 3), Bulevard (Inter, Space Grotesk), Faleză (DM Serif Display, Manrope), Hotel (Cormorant, Jost), Rustic (Alegreya, Alegreya Sans), Studio (Bodoni Moda, Instrument Sans). The version and the SHA-256 checksum of every file are in `vendor/VENDOR.lock` and `front/vendor/VENDOR.lock`.

## Tests

The repository contains the tests that run without external services (unit, security, migration, text and release archive tests): `php teste/run.php`. The integration tests with EasyCheck, the API contract tests and the browser checks run in the development repository, before every release.

## Help

The software is offered free of charge, as is. For help with installation or hosting, you can turn to your hosting company or to a developer.

## Security

Security issues are reported only through GitHub private vulnerability reporting (the repository's **Security** tab, **Report a vulnerability**), not in public. There is no promise of a reply or of a deadline. Details are in [SECURITY.md](SECURITY.md).
