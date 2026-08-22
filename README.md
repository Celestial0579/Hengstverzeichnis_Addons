# Hengstverzeichnis_Addons

Enthält Addons/Plugins für [Hengstverzeichnis_Framework](https://github.com/Celestial0579/Hengstverzeichnis_Framework).

## Struktur

Jedes Addon liegt in einem eigenen Unterverzeichnis von `plugins/`, benannt
nach seinem Slug - genau das Format, das der Framework-Kern erwartet (siehe
[docs/plugin-development.md](https://github.com/Celestial0579/Hengstverzeichnis_Framework/blob/main/docs/plugin-development.md)
im Framework-Repo):

```
plugins/
  beispiel-erweiterungspunkte/
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
cp -r plugins/beispiel-erweiterungspunkte /pfad/zu/Hengstverzeichnis_Framework/plugins/beispiel-erweiterungspunkte
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren.

## Verfügbare Addons

- [`beispiel-erweiterungspunkte`](plugins/beispiel-erweiterungspunkte/README.md) -
  **Lehrbeispiel, nicht für den Produktivbetrieb.** Belegt jeden
  Erweiterungspunkt des Kerns mit einem sichtbaren Ergebnis - alle Hooks,
  eigene Routen, Berechtigungen, Zusatzfunktion, eigene Tabellen,
  Spam-Schutz-Anbieter, Protokollierung, Übersetzungen. Wer ein Addon
  schreibt, fängt hier an. Ersetzt `besucherstatistik` als Referenz, das mit
  der Zusammenführung der Statistik-Addons (#127) entfallen ist.
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
- [`deckanfrage`](plugins/deckanfrage/README.md) - Deckanfrage-Formular auf
  der Pferde-Detailseite, sendet direkt an die hinterlegte Deckstation.
  Erscheint nur, wenn die verknüpfte Deckstation veröffentlicht und ihre
  E-Mail-Adresse damit öffentlich sichtbar ist.
  (Uploads plus YouTube/Vimeo-Links) mit eigener Verwaltungsseite.
- [`gesundheitstests`](plugins/gesundheitstests/README.md) - verwaltet
  Gesundheits-/Gentest-Befunde je Pferd; Dokumente liegen außerhalb des
  Webroots, öffentlich wird nur, was ausdrücklich freigegeben ist.
- [`merkliste`](plugins/merkliste/README.md) - clientseitige Merkliste
  (localStorage) mit Merken-Buttons auf Katalogkarten und Detailseite und
  eigener Übersichtsseite.
- [`verkaufsboerse`](plugins/verkaufsboerse/README.md) - markiert Pferde
  als zum Verkauf/zur Vermittlung, mit eigener Übersichtsseite und
  Kontaktformular.
- [`genealogie-vergleich`](plugins/genealogie-vergleich/README.md) - stellt
  die Stammbäume zweier Pferde nebeneinander dar und hebt gemeinsame
  Vorfahren hervor.
- [`farbvererbung`](plugins/farbvererbung/README.md) - Farbvererbungsrechner
  für das Fjordpferd: schätzt die Fohlenfarbe aus den Farben zweier
  Elterntiere über die fünf anerkannten Falbfarben und ordnet die eingetragene
  Farbe auf der Detailseite genetisch ein.
- [`anpaarungs-empfehlung`](plugins/anpaarungs-empfehlung/README.md) - rankt
  für ein ausgewähltes Pferd alle möglichen Partner nach dem voraussichtlichen
  Inzuchtkoeffizienten eines Fohlens (geringste Inzucht zuerst).
- [`kontaktanfrage`](plugins/kontaktanfrage/README.md) - Kontaktformular auf
  Personen- und Deckstationsseiten mit E-Mail, Name und fester Gründe-Auswahl,
  ohne dass die Adresse des Empfängers öffentlich wird. Anfragen gehen an eine
  Team-Adresse, werden gespeichert und lassen sich im Backend weiterleiten;
  je Datensatz abschaltbar.
- [`zucht-suche`](plugins/zucht-suche/README.md) - Öffentliche Einstiegsseite
  „Zucht": Züchter und Deckstationen suchen und filtern, statt sie nur über ein
  Pferd zu finden.
- [`captcha-altcha`](plugins/captcha-altcha/README.md) - Spam-Schutz per
  Rechennachweis im Browser (Proof of Work), **selbst gehostet**: keine
  Drittanbieter, keine Schlüssel, keine Übermittlung von IP-Adressen, keine
  Lockerung der Content-Security-Policy. Von den drei Anbieter-Addons die
  einzige Variante, die auch für das DSGVO-Portal in Frage kommt.
- [`captcha-turnstile`](plugins/captcha-turnstile/README.md) - Spam-Schutz per
  Cloudflare Turnstile. **Drittanbieter:** IP-Adresse und Browser-Angaben der
  Besucher gehen an Cloudflare, Inc. (USA) - gehört in die
  Datenschutzerklärung, siehe README des Addons.
- [`captcha-hcaptcha`](plugins/captcha-hcaptcha/README.md) - Spam-Schutz per
  hCaptcha. **Drittanbieter:** IP-Adresse und Browser-Angaben der Besucher
  gehen an Intuition Machines, Inc. (USA) - gehört in die
  Datenschutzerklärung, siehe README des Addons.

## Automatisierte Tests

Jedes Addon wird automatisiert getestet, ohne dass dafür der
Framework-Quellcode in dieses Repo kopiert werden muss: `composer.json`
bindet `hengstverzeichnis/framework` als reine Dev-Abhängigkeit über ein
VCS-Repository (GitHub-URL) ein - die PHPUnit-Suite läuft damit gegen den
echten Kern, genau wie die Testsuite im Framework-Repo selbst.

Drei Ebenen (siehe [`phpunit.xml`](phpunit.xml)):

- **`tests/Manifest`** - generische Strukturprüfung für **jedes** Verzeichnis
  unter `plugins/` (Manifest-Pflichtfelder, Slug-Format, PHP-Syntax,
  Namenskonvention der Plugin-Klasse, Form von `permissions()`/`routes()`).
  Läuft ohne Datenbank und muss bei einem neuen Addon **nicht** angepasst
  werden - der Test scannt `plugins/` zur Laufzeit.
- **`tests/Unit`** - Rechenkerne einzelner Addons, die als reine Funktionen
  prüfbar sind (derzeit der COI-Rechner des Plugins `inzuchtkoeffizient`).
  Läuft wie die Manifest-Suite ohne Datenbank und ohne Framework-Instanz -
  gedacht für Fälle, die über HTTP nur mit unverhältnismäßigem Aufwand
  abzubilden wären.
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

# Nur Unit-Suite (ebenfalls ohne Datenbank):
composer test:unit

# Functional-Suite (braucht eine erreichbare MariaDB/MySQL-Testdatenbank):
DB_HOST=127.0.0.1 DB_NAME=hengst_addons_functional DB_USER=hengst DB_PASS=hengst \
  APP_KEY=lokaler-test-schluessel SITE_NAME="Testverband" \
  ADMIN_USERNAME=e2eadmin ADMIN_EMAIL=e2e@example.com ADMIN_PASSWORD=Test1234! \
  composer test:functional
```

Läuft automatisch bei jedem Push/PR gegen `main` über
[`.github/workflows/tests.yml`](.github/workflows/tests.yml). Die
Unit-Suite läuft dort bewusst im selben Job wie die Manifest-Prüfung
(`Plugin-Manifest-Prüfung` ist der als Pflicht-Check hinterlegte Name);
die Functional-Suite hat einen eigenen Job mit MariaDB-Service-Container.

## Abhängigkeitspflege

[`.github/dependabot.yml`](.github/dependabot.yml) hält Composer-Pakete
(PHPUnit) und die in den Workflows verwendeten GitHub Actions wöchentlich
aktuell - analog zum Framework-Repo.

**Die Framework-Abhängigkeit selbst hält Dependabot NICHT aktuell:**
`hengstverzeichnis/framework` hängt als `dev-main` über ein VCS-Repository
in `composer.json`, und Branch-Abhängigkeiten dieser Bauart aktualisiert
Dependabot nicht - es hebt Versionsbereiche, keine Zweig-Zeiger. Ohne
Gegenmaßnahme bliebe `composer.lock` auf einem alten Framework-Commit
stehen, und die Suite prüfte grün gegen einen Kern, den es so nicht mehr
gibt (genau so blieb die Regression Framework-#151 tagelang unbemerkt).
Deshalb übernimmt das
[`.github/workflows/framework-update.yml`](.github/workflows/framework-update.yml):
wöchentlich (und per Hand auslösbar) hebt es das Framework auf den
aktuellen `main`, lässt die volle Suite dagegen laufen und öffnet bei Grün
einen PR mit dem neuen Lock - bei Rot stattdessen ein Issue, das sich
selbst schließt, sobald ein späterer Lauf wieder grün ist. Bewusst kein
Auto-Merge: Ein Kern-Update kann das Verhalten der Plugins ändern.

Weitere Automatik:

- [`.github/workflows/dependabot-auto-merge.yml`](.github/workflows/dependabot-auto-merge.yml) -
  merged Dependabot-PRs automatisch per Squash, sobald `Tests` grün ist,
  außer bei Major-Updates (die brauchen manuelle Prüfung).
- [`.github/workflows/dependency-review.yml`](.github/workflows/dependency-review.yml) -
  scannt PRs, die Abhängigkeiten ändern, auf bekannte Sicherheitslücken.
- [`.github/workflows/security-scan.yml`](.github/workflows/security-scan.yml) -
  statischer Plugin-Sicherheits-Check bei jedem Push/PR plus wöchentlicher
  DAST-Lauf; Details in [`SECURITY.md`](SECURITY.md) und
  [`security/`](security/).
- [`.github/workflows/scorecard.yml`](.github/workflows/scorecard.yml) -
  OpenSSF-Scorecard-Bewertung der Repo-Absicherung.

## Versionierung & Releases

Dieses Repo wird als **Gesamtstand** released: Tags `vX.Y.z` folgen der
Framework-Linie `X.Y` (Patch-Stelle `z` frei für Addon-Fixes zwischen
Framework-Releases). Der Addon-Store und das Addon-Autoupdate des
Frameworks lesen für dieses offizielle Repo den besten Release-Tag zur
laufenden Kern-Linie statt des `main`-HEAD - was auf `main` liegt, ist
damit erst nach einem Release auf Produktivinstanzen. Ablauf, Pipeline und
die Pflicht-Manifestgrenzen (`core_compatibility` als Untergrenze,
`core_supported_max` als Obergrenze): siehe
[docs/releasing.md](docs/releasing.md); Änderungen je Release stehen im
[CHANGELOG.md](CHANGELOG.md).

## Neues Addon hinzufügen

1. Neues Verzeichnis unter `plugins/<slug>/` anlegen (Konventionen siehe
   `docs/plugin-development.md` im Framework-Repo).
2. `tests/Manifest/PluginManifestTest.php` deckt die Struktur automatisch mit
   ab - nichts weiter zu tun.
3. Eine eigene `tests/Functional/<Addon>Test.php` ergänzen, die das Addon
   aktiviert und sein tatsächliches Verhalten (Hooks, Routen, Berechtigungen)
   gegen die echte Framework-Instanz prüft - am einfachsten als Kopie von
   [`tests/Functional/BeispielErweiterungspunktePluginTest.php`](tests/Functional/BeispielErweiterungspunktePluginTest.php).
   Welcher Hook wofür da ist und was die Falle daran ist, steht kommentiert in
   [`plugins/beispiel-erweiterungspunkte/Plugin.php`](plugins/beispiel-erweiterungspunkte/Plugin.php).
4. Bei jeder späteren inhaltlichen Änderung am Addon-Code die `version` in
   dessen `plugin.json` erhöhen - der Kern erkennt Änderungen an aktivierten
   Plugins über einen Inhalts-Hash und sperrt sie fail-closed, bis ein Admin
   die neue Fassung erneut freigibt; der Versionssprung macht den Anlass
   sichtbar.
