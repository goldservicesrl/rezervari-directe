# Developer guide

This guide shows you how to run the software on your own computer, how the code is organised, how to run the tests and how to contribute. To install it on hosting, read the [host guide](ghid-gazda.en.md); for themes, the [theme guide](ghid-teme.en.md).

[Versiunea în limba română](dezvoltare.md)

## Requirements

- PHP 8.2 or newer (tested on 8.2, 8.3 and 8.4), with the `pdo_sqlite` (or `pdo_mysql`), `curl`, `openssl`, `mbstring`, `json`, `zip` and `gd` extensions; the `sodium` extension is recommended (without it, the vendored `paragonie/sodium_compat` library is loaded);
- optionally, MySQL or MariaDB, for the tests on that database;
- optionally, Node.js and Chromium, for the browser checks.

The project works without Composer: the libraries (Twig, Leaflet) live in `vendor/` and `front/vendor/`, with the version, the license and the SHA-256 hash of every file in `VENDOR.lock`.

## A local instance

```
bin/cazo configureaza                     # local instance in local/ (SQLite), with the release linked to the repository
bin/cazo configureaza --mysql=mysql://user:password@127.0.0.1:3306/rezervari
bin/cazo serveste --port=8080             # PHP built-in server, with the loader as router
bin/cazo admin --email=host@example.com --parola=...   # the dashboard user (/admin/)
```

The instance has the same layout as on hosting: `local/cazo-privat/` (code, data, configuration) and `local/public_html/` (the single public PHP file, plus the assets). The release in the local instance is a link to the repository, so code changes show up immediately.

To connect to EasyCheck, use a test key and, during development, an EasyCheck test server:

```
bin/cazo configureaza --easycheck=http://localhost:8090/api/v1   # the address of an EasyCheck test server
bin/cazo easycheck --cheie=ec_test_...    # links the key, registers the notifications, sends the test and runs the first sync
bin/cazo sincronizeaza [--resurse=eu,preturi]
bin/cazo oglinda                          # status of the local data copy, the key, the photos and the API budget
```

The test address is accepted only in development mode; a real installation talks only to `https://easycheck.ro/api/v1`.

Other useful commands:

```
bin/cazo cron                             # one run of the background jobs
bin/cazo stare                            # migrations, last cron run, queue
bin/cazo email --gazda=smtp.example.com --utilizator=... --parola=... [--securitate=tls|ssl|niciuna]
bin/cazo comenzi                          # latest orders, with their transition log
bin/cazo texte                            # regenerates the list of texts from the catalogs
php bin/lansare.php                         # the dist/cazo-<version>.zip archive (runs the tests first)
```

## Publishing a version

`php bin/lansare.php [--note-ro="..."] [--note-en="..."] [--securitate]` runs the tests and writes the `cazo-<version>.zip` archive and the `versiune.json` file into `dist/`. Releases are not signed. The archive goes on the repository's releases page (tag `v<version>`), then `versiune.json` goes on `https://cazo.ro/versiune.json`: sites read it once a day and notify their owners, in the dashboard and by email.

The file format:

```json
{
  "versiune": "0.1.1",
  "data": "2026-12-10",
  "note": {"ro": "Ce aduce versiunea, pe scurt.", "en": "What the version brings, in short."},
  "descarcare": "https://github.com/goldservicesrl/rezervari-directe/releases/tag/v0.1.1",
  "securitate": false
}
```

`versiune` (MAJOR.MINOR.PATCH), `data` (YYYY-MM-DD) and `descarcare` (an https address on github.com or cazo.ro) are required. `note` may also be a single text, at most 2000 characters; `securitate` (default `false`) adds the security fix line to the notice. A file that does not follow the format is ignored, and sites keep the last version they saw.

## Code layout

| Folder | Contents |
|---|---|
| `app/` | the core, by module: `Nucleu` (router, request, response, headers, cache), `Baza` (database dialects, migrations), `Securitate` (security), `EasyCheck` (client and local data copy), `Pret` (prices), `Rezervare` (bookings), `Plati` (Stripe payments), `Email`, `Teme` (themes), `Legal`, `I18n`, `Joburi` (background jobs), `Controlere` (controllers) |
| `vederi/` | the core Twig templates: components, dashboard, emails |
| `front/` | the core JavaScript and CSS (web components, no framework), plus Leaflet |
| `teme/` | the themes: `_schelet` (the section library), `_pornire` (the starting point for a new theme) and the official themes; `studio` is the default theme |
| `limbi/` | the text catalogs: site and dashboard, in Romanian and English |
| `migrari/` | the database migrations, additive only |
| `public/` | the loader (`index.php`) and `.htaccess` |
| `bin/` | cron, the development command and the archive build |
| `teste/` | the test runner, the tests and the hostile test corpus |
| `vendor/` | the vendored PHP libraries |

## Tests

```
php teste/run.php [group] [filter]
CAZO_TEST_MYSQL=mysql://user:password@127.0.0.1:3306/rezervari_test php teste/run.php
```

The groups:

| Group | Covers |
|---|---|
| `unitare` | prices, the payment rule, formatting, encryption, TOTP, ALTCHA, the rate limiter, themes |
| `migrari` | a clean schema installation |
| `securitate` | notification signatures, headers and CSP on every route, CSRF, the theme sandbox, hostile archives |
| `integrare` | the loader (`public/index.php`, the page cache, maintenance, cron) and the release archive, with the built-in PHP server |
| `texte` | the text rules across all catalogs |

Every test runs on SQLite; tests that use the database run once more on MySQL or MariaDB when `CAZO_TEST_MYSQL` is set (tables with a unique prefix, dropped after the test). `CAZO_TEST_PHP_ARGS` passes extra options to the PHP servers started by the tests (for example `-d extension=pdo_mysql.so`).

The integration tests with EasyCheck (the complete booking and payment flows, the API contract, the order state machine, the themes on the demo property) and the browser checks run in the development repository, before every release.

The tests are written for Linux. The runner discovers `teste/<group>/**/*_test.php`; each file returns a list of functions `name => function (Mediu $m)`, with the assertions from `teste/lib/aserti.php`.

## Code rules

- **PHP 8.2**, with no functions from newer versions; no Composer; new dependencies only vendored, with `VENDOR.lock`.
- **A single public PHP file** (`public/index.php`); everything else lives in the private folder.
- **Strict CSP:** zero inline JavaScript and zero inline styles; scripts and styles live in `front/`.
- **Migrations are additive only:** new columns and tables, no removals; the previous version's code runs on the new schema.
- **Money has a single state machine**, with conditional transitions and idempotency keys on every external call.
- **Texts** live in the catalogs in `limbi/`, with the same keys in Romanian and English. In Romanian: affirmative wording, the formal „dumneavoastră”, no em dash, ș and ț with a comma; in guest-facing texts, no names of technical providers. The `texte` test group checks them.
- **Tests for everything:** a fix comes with the test that fails on the old code.

## How to contribute

1. For a large change, open an issue first, so we can agree on the direction.
2. Work on your own branch and send a pull request, with tests covering the change; `php teste/run.php` must pass.
3. Contributions are licensed under the project license, the Cazo License. Themes you make for the software may use any license (section 4 of `LICENSE`).

**The license.** Cazo is free and its source code is public, under the Cazo License (the `LICENSE` file). You may use, copy, modify and distribute it freely, including modified versions. The only condition is the footer link, “Software Regim Hotelier - Cazo.ro” (on the English pages, “Vacation Rental Software - Cazo.ro”), to https://cazo.ro/, which stays visible on every public page. The block with the link belongs to the core (`vederi/componente/legal.twig`), so a theme cannot remove it.

**Security issues** should not be opened in public: report them only through GitHub private vulnerability reporting, with no promise of a reply. The software is offered free of charge, as is. For help with installation or hosting, you can turn to your hosting company or to a developer.
