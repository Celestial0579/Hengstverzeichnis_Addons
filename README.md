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

## Neues Addon hinzufügen

1. Neues Verzeichnis unter `plugins/<slug>/` anlegen (Konventionen siehe
   `docs/plugin-development.md` im Framework-Repo).
2. `tests/Manifest/PluginManifestTest.php` deckt die Struktur automatisch mit
   ab - nichts weiter zu tun.
3. Eine eigene `tests/Functional/<Addon>Test.php` ergänzen, die das Addon
   aktiviert und sein tatsächliches Verhalten (Hooks, Routen, Berechtigungen)
   gegen die echte Framework-Instanz prüft - am einfachsten als Kopie von
   [`tests/Functional/BesucherstatistikPluginTest.php`](tests/Functional/BesucherstatistikPluginTest.php).
