# Ghidul pentru dezvoltatori

Acest ghid vă arată cum rulați softul pe calculatorul dumneavoastră, cum e organizat codul, cum rulați testele și cum contribuiți. Pentru instalarea pe o găzduire, citiți [ghidul gazdei](ghid-gazda.md); pentru teme, [ghidul pentru teme](ghid-teme.md).

[English Version](dezvoltare.en.md)

## Cerințe

- PHP 8.2 sau mai nou (testat pe 8.2, 8.3 și 8.4), cu extensiile `pdo_sqlite` (sau `pdo_mysql`), `curl`, `openssl`, `mbstring`, `json`, `zip`, `gd`; extensia `sodium` e recomandată (fără ea se încarcă biblioteca vendorizată `paragonie/sodium_compat`);
- opțional, MySQL sau MariaDB, pentru testele pe această bază;
- opțional, Node.js și Chromium, pentru probele în browser.

Proiectul merge fără Composer: bibliotecile (Twig, Leaflet) stau în `vendor/` și `front/vendor/`, cu versiunea, licența și amprenta SHA-256 a fiecărui fișier în `VENDOR.lock`.

## O instanță locală

```
bin/cazo configureaza                     # instanța locală în local/ (SQLite), cu lansarea legată de depozit
bin/cazo configureaza --mysql=mysql://utilizator:parola@127.0.0.1:3306/rezervari
bin/cazo serveste --port=8080             # serverul PHP încorporat, cu încărcătorul ca router
bin/cazo admin --email=gazda@exemplu.ro --parola=...   # utilizatorul panoului (/admin/)
```

Instanța are aceeași structură ca pe o găzduire: `local/cazo-privat/` (codul, datele, configurarea) și `local/public_html/` (singurul fișier PHP public, plus activele). Lansarea din instanța locală e o legătură spre depozit, deci schimbările din cod se văd imediat.

Pentru legătura cu EasyCheck, folosiți o cheie de probă și, în dezvoltare, un server EasyCheck de test:

```
bin/cazo configureaza --easycheck=http://localhost:8090/api/v1   # adresa unui server EasyCheck de test
bin/cazo easycheck --cheie=ec_test_...    # leagă cheia, înregistrează anunțurile, proba și sincronizarea inițială
bin/cazo sincronizeaza [--resurse=eu,preturi]
bin/cazo oglinda                          # starea copiei locale, a cheii, a pozelor și a bugetului API
```

Adresa de test se acceptă doar în modul de dezvoltare; o instalare reală vorbește doar cu `https://easycheck.ro/api/v1`.

Celelalte comenzi utile:

```
bin/cazo cron                             # o rulare a lucrărilor de fundal
bin/cazo stare                            # migrările, ultima rulare a cronului, coada
bin/cazo email --gazda=smtp.exemplu.ro --utilizator=... --parola=... [--securitate=tls|ssl|niciuna]
bin/cazo comenzi                          # ultimele comenzi, cu jurnalul tranzițiilor
bin/cazo texte                            # regenerează lista textelor din cataloage
php bin/lansare.php                         # arhiva dist/cazo-<versiune>.zip (rulează întâi testele)
```

## Publicarea unei versiuni

`php bin/lansare.php [--note-ro="..."] [--note-en="..."] [--securitate]` rulează testele și scrie în `dist/` arhiva `cazo-<versiune>.zip` și fișierul `versiune.json`. Lansările nu se semnează. Arhiva se urcă în pagina de versiuni a depozitului (eticheta `v<versiune>`), apoi `versiune.json` se urcă pe `https://cazo.ro/versiune.json`: site-urile îl citesc o dată pe zi și își anunță proprietarii, în panou și pe e-mail.

Forma fișierului:

```json
{
  "versiune": "0.1.1",
  "data": "2026-12-10",
  "note": {"ro": "Ce aduce versiunea, pe scurt.", "en": "What the version brings, in short."},
  "descarcare": "https://github.com/goldservicesrl/rezervari-directe/releases/tag/v0.1.1",
  "securitate": false
}
```

`versiune` (MAJOR.MINOR.PATCH), `data` (AAAA-LL-ZZ) și `descarcare` (release-urile depozitului oficial, `https://github.com/goldservicesrl/rezervari-directe/releases/...`, sau `https://cazo.ro/descarcare/`; altă adresă face fișierul ignorat) sunt obligatorii. `note` poate fi și un singur text, cel mult 2000 de caractere; `securitate` (implicit `false`) adaugă în anunț rândul despre reparația de securitate. Un fișier care nu respectă forma e ignorat, iar site-urile păstrează ultima versiune văzută.

## Structura codului

| Folderul | Ce conține |
|---|---|
| `app/` | nucleul, pe module: `Nucleu` (router, cerere, răspuns, antete, cache), `Baza` (dialectele, migrările), `Securitate`, `EasyCheck` (clientul și copia locală a datelor), `Pret`, `Rezervare`, `Plati` (Stripe), `Email`, `Teme`, `Legal`, `I18n`, `Joburi`, `Controlere` |
| `vederi/` | șabloanele Twig ale nucleului: componentele, panoul, e-mailurile |
| `front/` | JavaScript-ul și CSS-ul nucleului (elemente web, fără cadru), plus Leaflet |
| `teme/` | temele: `_schelet` (biblioteca de secțiuni), `_pornire` (punctul de plecare pentru o temă nouă) și temele oficiale; `studio` e tema implicită |
| `limbi/` | cataloagele de texte: site-ul și panoul, în română și în engleză |
| `migrari/` | migrările bazei, doar aditive |
| `public/` | încărcătorul (`index.php`) și `.htaccess` |
| `bin/` | cronul, comanda de dezvoltare și construcția arhivei |
| `teste/` | rulatorul, testele și corpusul de teste ostile |
| `vendor/` | bibliotecile PHP vendorizate |

## Testele

```
php teste/run.php [grupa] [filtru]
CAZO_TEST_MYSQL=mysql://utilizator:parola@127.0.0.1:3306/rezervari_test php teste/run.php
```

Grupele:

| Grupa | Acoperă |
|---|---|
| `unitare` | prețul, regula de plată, formatarea, criptarea, TOTP, ALTCHA, limitatorul, temele |
| `migrari` | instalarea curată a schemei |
| `securitate` | semnăturile anunțurilor, antetele și CSP-ul pe fiecare rută, CSRF, sandboxul temelor, arhivele ostile |
| `integrare` | încărcătorul (`public/index.php`, cache-ul de pagini, întreținerea, cronul) și arhiva de lansare, cu serverul PHP încorporat |
| `texte` | regulile textelor pe toate cataloagele |

Fiecare test rulează pe SQLite; testele care folosesc baza rulează încă o dată pe MySQL sau MariaDB când `CAZO_TEST_MYSQL` e setat (tabele cu prefix unic, șterse după test). `CAZO_TEST_PHP_ARGS` trece opțiuni suplimentare serverelor PHP pornite de teste (de exemplu `-d extension=pdo_mysql.so`).

Testele de integrare cu EasyCheck (fluxurile complete ale rezervării și ale plății, contractul API-ului, mașina de stări a comenzilor, temele pe proprietatea demo) și probele în browser rulează în depozitul de dezvoltare, înaintea fiecărei versiuni.

Testele sunt scrise pentru Linux. Rulatorul descoperă `teste/<grupa>/**/*_test.php`; fiecare fișier întoarce o listă de funcții `nume => function (Mediu $m)`, cu aserțiunile din `teste/lib/aserti.php`.

## Regulile codului

- **PHP 8.2**, fără funcții din versiunile mai noi; fără Composer; dependențe noi doar vendorizate, cu `VENDOR.lock`.
- **Un singur fișier PHP public** (`public/index.php`); restul stă în folderul privat.
- **CSP strict:** zero JavaScript și zero stil în linie; scripturile și stilurile stau în `front/`.
- **Migrările sunt doar aditive:** coloane și tabele noi, fără ștergeri; codul versiunii anterioare merge peste schema nouă.
- **Banii au o singură mașină de stări**, cu tranziții condiționate și chei de idempotență pe fiecare apel extern.
- **Textele** stau în cataloagele din `limbi/`, cu aceleași chei în română și în engleză. În română: formulare afirmativă, cu „dumneavoastră”, fără linia de pauză lungă (em dash), cu ș și ț cu virgulă; în textele pentru oaspeți, fără numele furnizorilor tehnici. Grupa de teste `texte` le verifică.
- **Teste pentru tot:** o reparație vine cu testul care pică pe codul vechi.

## Cum contribuiți

1. Deschideți întâi o discuție (issue) pentru o schimbare mare, ca să stabilim direcția.
2. Lucrați pe o ramură proprie și trimiteți un pull request, cu testele care acoperă schimbarea; `php teste/run.php` trebuie să treacă.
3. Contribuțiile intră sub licența proiectului, Licența Cazo. Temele făcute de dumneavoastră pentru soft pot avea orice licență (articolul 4 din `LICENSE`).

**Licența.** Cazo este gratuit, iar codul sursă este public, sub Licența Cazo (fișierul `LICENSE`). Îl puteți folosi, copia, modifica și distribui liber, inclusiv în variante modificate. Singura condiție este linkul din subsol, „Software Regim Hotelier - Cazo.ro” (pe paginile în engleză, „Vacation Rental Software - Cazo.ro”), spre https://cazo.ro/, care rămâne la vedere pe fiecare pagină publică. Blocul cu linkul e al nucleului (`vederi/componente/legal.twig`), deci o temă nu îl poate scoate.

**Problemele de securitate** nu le deschideți public: le raportați doar prin raportarea privată a vulnerabilităților de pe GitHub, fără promisiunea unui răspuns. Softul se oferă gratuit, așa cum este. Pentru ajutor la instalare sau la găzduire, vă puteți adresa firmei de găzduire sau unui programator.
