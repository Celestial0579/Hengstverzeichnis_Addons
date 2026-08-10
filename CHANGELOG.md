# Changelog

Alle nennenswerten Änderungen an den offiziellen Addons werden in dieser
Datei dokumentiert, je Release nach Addon gruppiert. Das Format orientiert
sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/); die
Release-Tags `vX.Y.z` folgen der Framework-Linie `X.Y`
(siehe [docs/releasing.md](docs/releasing.md)).

## [Unreleased]

### Alle Addons

- Eigenständige Plugin-Seiten rendern im Haupt-Layout des Frameworks
  (`App\Plugin\PluginPage::render()`, Addons#66): mit Header, Navigation,
  Footer, Theme-Umschalter und den admin-konfigurierten Markenfarben statt
  als eigenständige, unthemebare HTML-Dokumente. Formulare und Buttons
  nutzen die gemeinsamen Framework-Klassen, Farben ausschließlich
  Theme-Variablen. Dokumentierte Ausnahmen mit Marker
  `/* theming-ausnahme: ... */`: Druckansichten von `pedigree-export` und
  `qr-code`, Lightbox-Scrim der `galerie`, funktionales QR-Schwarz/Weiß.
  Alle Addon-Versionen auf 1.1.0 gehoben
- Manifeste: `core_compatibility` auf `">=0.4.0"` (die Layout-Integration
  braucht den Kern-Dienst aus Framework 0.4) und neues **Pflichtfeld**
  `"core_supported_max": "0.4"` (höchste unterstützte Kern-Linie,
  Framework#197)
- Neues Test-Gate `tests/Manifest/PluginThemingLintTest.php`: verbietet
  eigenständige Dokumente, eigene Schriftarten, rohe Farbwerte und
  Radius-Hardcodes außerhalb markierter Ausnahmen - läuft automatisch über
  jedes Verzeichnis unter `plugins/`

### Galerie

- Theme-Bruch behoben: Die Video-Platzhalter-Kachel war fest `#222`/`#fff`
  und ignorierte damit Hell-/Dunkelmodus - jetzt Theme-Variablen

### Repo

- Release-Prozess eingeführt (#65): Tags/Releases `vX.Y.z` folgen der
  Framework-Linie; Release-Pipeline mit Testsuite-Gate und
  Konsistenzprüfung (`scripts/check-release-consistency.php`: Unter- und
  Pflicht-Obergrenze aller Manifeste müssen die Ziel-Linie einschließen);
  dieses CHANGELOG; Ablaufdokumentation in `docs/releasing.md`
