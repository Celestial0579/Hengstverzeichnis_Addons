# Hengstverzeichnis_Addons

Enthält Addons/Plugins für [Hengstverzeichnis_Framework](https://github.com/Celestial0579/Hengstverzeichnis_Framework).

## Struktur

Jedes Addon liegt in einem eigenen Unterverzeichnis von `plugins/`, benannt
nach seinem Slug - genau das Format, das der Framework-Kern erwartet (siehe
[docs/plugin-development.md](https://github.com/Celestial0579/Hengstverzeichnis_Framework/blob/main/docs/plugin-development.md)
im Framework-Repo):

```
plugins/
  besucherstatistik/
    plugin.json
    Plugin.php
    README.md
  <weiteres-addon>/
    plugin.json
    ...
```

Zur Installation eines Addons wird dessen Verzeichnis lokal in das (dort
gitignorete) `plugins/`-Verzeichnis des Framework-Checkouts kopiert:

```bash
cp -r plugins/besucherstatistik /pfad/zu/Hengstverzeichnis_Framework/plugins/besucherstatistik
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren.

## Verfügbare Addons

- [`besucherstatistik`](plugins/besucherstatistik/README.md) - zählt
  Seitenaufrufe je Pferd und zeigt eine Rangliste der meistgesehenen Pferde.
- [`inzuchtkoeffizient`](plugins/inzuchtkoeffizient/README.md) - berechnet
  Wright's Inzuchtkoeffizienten auf der Pferde-Detailseite und bietet einen
  Verpaarungsrechner für den voraussichtlichen COI eines Fohlens.
- [`statistik-dashboard`](plugins/statistik-dashboard/README.md) -
  Kennzahlen-Übersicht im Admin-Bereich (Status-Verteilung, Deckstationen,
  Wachstum über Zeit, Top-Blutlinien).
- [`katalog-export`](plugins/katalog-export/README.md) - CSV-Export des
  Pferdekatalogs, gefiltert oder ungefiltert.
- [`pedigree-export`](plugins/pedigree-export/README.md) - druckoptimierte
  Stammbaum-Ansicht zum Sichern als PDF über die Browser-Druckfunktion.
- [`qr-code`](plugins/qr-code/README.md) - QR-Code zur Profil-URL auf der
  Pferde-Detailseite sowie eine druckfertige Aushang-Ansicht.
- [`zuchtschau-ergebnisse`](plugins/zuchtschau-ergebnisse/README.md) -
  erfasst Zuchtschau-/Körungsergebnisse (Note, Richter, Platzierung) pro
  Pferd und zeigt sie auf der Detailseite.

## Automatisierte Tests

Jedes Addon wird automatisiert getestet, ohne dass dafür der
Framework-Quellcode in dieses Repo kopiert werden muss: `composer.json`
bindet `hengstverzeichnis/framework` als reine Dev-Abhängigkeit über ein
VCS-Repository (GitHub-URL) ein - die PHPUnit-Suite läuft damit gegen den
echten Kern, genau wie die Testsuite im Framework-Repo selbst.

Zwei Ebenen (siehe [`phpunit.xml`](phpunit.xml)):

- **`tests/Manifest`** - generische Strukturprüfung für **jedes** Verzeichnis
  unter `plugins/` (Manifest-Pflichtfelder, Slug-Format, PHP-Syntax,
  Namenskonvention der Plugin-Klasse, Form von `permissions()`/`routes()`).
  Läuft ohne Datenbank und muss bei einem neuen Addon **nicht** angepasst
  werden - der Test scannt `plugins/` zur Laufzeit.
- **`tests/Functional`** - ein Test pro Addon, der es tatsächlich in einer
  echten, per `php -S` gestarteten Framework-Instanz aktiviert und per HTTP
  durchspielt (Hooks, eigene Routen, Berechtigungsdurchsetzung). Das
  [`tests/bootstrap.php`](tests/bootstrap.php) kopiert dafür bei jedem
  Testlauf automatisch jedes `plugins/<slug>/` nach
  `vendor/hengstverzeichnis/framework/plugins/`.

### Lokal ausführen

```bash
composer install

# Nur Manifest-Prüfung (keine Datenbank nötig):
composer test:manifest

# Functional-Suite (braucht eine erreichbare MariaDB/MySQL-Testdatenbank):
DB_HOST=127.0.0.1 DB_NAME=hengst_addons_functional DB_USER=hengst DB_PASS=hengst \
  APP_KEY=lokaler-test-schluessel SITE_NAME="Testverband" \
  ADMIN_USERNAME=e2eadmin ADMIN_EMAIL=e2e@example.com ADMIN_PASSWORD=Test1234! \
  composer test:functional
```

Läuft automatisch bei jedem Push/PR gegen `main` über
[`.github/workflows/tests.yml`](.github/workflows/tests.yml) (die
Functional-Suite dort mit einem MariaDB-Service-Container).

## Abhängigkeitspflege

[`.github/dependabot.yml`](.github/dependabot.yml) hält Composer-Pakete
(PHPUnit, das VCS-Dev-Requirement auf `hengstverzeichnis/framework`) und die
in den Workflows verwendeten GitHub Actions wöchentlich aktuell - analog zum
Framework-Repo. Ergänzt um:

- [`.github/workflows/dependabot-auto-merge.yml`](.github/workflows/dependabot-auto-merge.yml) -
  merged Dependabot-PRs automatisch per Squash, sobald `Tests` grün ist,
  außer bei Major-Updates (die brauchen manuelle Prüfung).
- [`.github/workflows/dependency-review.yml`](.github/workflows/dependency-review.yml) -
  scannt PRs, die Abhängigkeiten ändern, auf bekannte Sicherheitslücken.

Da `hengstverzeichnis/framework` als `dev-main` eingebunden ist (das
Framework veröffentlicht bislang keine Tags/Releases), zeigt jede neue
Dependabot-PR dafür schlicht den jeweils neuesten Commit auf `main` - die
Functional-Suite läuft dabei jedes Mal gegen den echten, aktuellen Kern und
deckt so auch Breaking Changes im Framework selbst auf.

## Neues Addon hinzufügen

1. Neues Verzeichnis unter `plugins/<slug>/` anlegen (Konventionen siehe
   `docs/plugin-development.md` im Framework-Repo).
2. `tests/Manifest/PluginManifestTest.php` deckt die Struktur automatisch mit
   ab - nichts weiter zu tun.
3. Eine eigene `tests/Functional/<Addon>Test.php` ergänzen, die das Addon
   aktiviert und sein tatsächliches Verhalten (Hooks, Routen, Berechtigungen)
   gegen die echte Framework-Instanz prüft - am einfachsten als Kopie von
   [`tests/Functional/BesucherstatistikPluginTest.php`](tests/Functional/BesucherstatistikPluginTest.php).
