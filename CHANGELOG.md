# Changelog

Alle nennenswerten Änderungen an den offiziellen Addons werden in dieser
Datei dokumentiert, je Release nach Addon gruppiert. Das Format orientiert
sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/); die
Release-Tags `vX.Y.z` folgen der Framework-Linie `X.Y`
(siehe [docs/releasing.md](docs/releasing.md)).

## [Unreleased]

## [0.4.1] – 2026-08-10

### Farbvererbung

- Freitext-Zuordnung erkennt auch die adjektivischen Kurzformen ohne
  End-e („graufalb", „rotfalb", „braunfalb", „gelbfalb", „hellfalb",
  „weißfalb") - die Nadeln sind jetzt Wortstämme, die Substantivformen
  („Graufalbe" …) bleiben als Obermenge abgedeckt. Neue Unit-Testfamilie
  für `keyFromText()` inkl. Negativfällen („Grauschimmel" bleibt ohne
  Falb-Deutung); Plugin-Version 1.1.2

## [0.4.0] – 2026-08-10

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
- Pferde-Auswahl ohne Voll-`<select>` (#74): Die Formulare von Galerie,
  Gesundheitstests, Verkaufsbörse, Inzuchtkoeffizient,
  Anpaarungs-Empfehlung und Farbvererbung laden nicht mehr den gesamten
  Pferdebestand in Auswahllisten, sondern nutzen ein Suchfeld mit Datalist
  an einer eigenen JSON-Suchroute (`/plugin/<slug>/suche`, max. 50
  Treffer, `[#id]`-Zusatz bei Namensgleichheit, berechtigungsgeschützt,
  No-JS-Fallback über serverseitige Namensauflösung); lange Listen sind
  paginiert bzw. gedeckelt
- Tabellenanlage nicht mehr in jedem Request (#75): Die sechs Addons mit
  eigener Tabelle richten sie über den neuen `install()`-Hook des Kerns
  ein (Framework 0.4.0); für ältere Kerne bleibt ein Fallback, der statt
  des DDL-Statements nur noch eine billige `SELECT`-Probe je Request
  ausführt. Bewusst ohne Marker-Datei im Plugin-Verzeichnis: Der Kern gibt
  Plugin-Verzeichnisse per Inhalts-Fingerabdruck frei, eine zur Laufzeit
  angelegte Datei würde das Addon deaktivieren
- Alle Addon-Versionen auf 1.1.1 gehoben

### Anpaarungs-Empfehlung

- Kandidatensuche ohne Pedigree-Explosion (#69): eine einzige
  Kantenabfrage, die Ahnenbäume entstehen in PHP (`AncestorTreeBuilder`);
  der Gleichlauf mit dem Pedigree-Aufbau des Kerns ist per Test belegt
  (identische Bäume und COI-Werte bei Tiefe 3/6/8), der Kandidaten-Deckel
  greift vor der Berechnung
- Rechentiefe an den Inzuchtkoeffizient-Rechner angeglichen (#72):
  6 Generationen je Elternteil, Beschreibungstexte entsprechend

### Farbvererbung

- Genetik-Modell mit statischer Unit-Testfamilie festgenagelt (#76) -
  inklusive korrigierter Erwartung gegenüber dem Issue-Entwurf: Grå × Grå
  ergibt nach der Modellannahme 93,75 % Grå + 6,25 % Rødblakk (Ee × Ee
  kann ee liefern), Brunblakk bleibt 0
- Nachschlage-Tabelle auf 200 Zeilen gedeckelt (mit Hinweistext) und über
  das Suchfeld vollständig nachschlagbar (#74)

### Galerie

- Theme-Bruch behoben: Die Video-Platzhalter-Kachel war fest `#222`/`#fff`
  und ignorierte damit Hell-/Dunkelmodus - jetzt Theme-Variablen
- Medienverwaltung paginiert (50 je Seite, geklemmter `?seite=`-Parameter);
  Löschen kehrt auf die Ausgangsseite zurück (#74)

### Gesundheitstests

- Download-Gate functional abgesichert (#71): private Dokumente anonym
  404 ohne Inhalt, öffentliche 200 als PDF-Attachment, Dokumente
  unveröffentlichter Pferde nur mit `gesundheitstests.manage`

### Inzuchtkoeffizient

- Detailseiten-Abschnitt rechnet mit derselben Tiefe wie der Rechner
  (#72): je Elternteil ein eigener Baum über 6 Generationen - der
  6-Generationen-Fall liefert auf Detailseite und Rechner identisch 0,39 %

### Katalog-Export

- Zeilen-Multiplikation behoben (#70): Personen kommen als Aggregat
  (`GROUP_CONCAT` je Rolle) statt über multiplizierende JOINs - ein Pferd
  mit mehreren Besitzer-Historienzeilen erzeugt wieder genau eine
  Exportzeile; Züchter-/Besitzer-Filter arbeiten rollenscharf als
  EXISTS-Unterabfragen

### Merkliste

- Katalog-Script als statisches, cachebares Asset (#73): ein
  `<script src>` je Seite (`/plugin/merkliste/assets.js`, `Cache-Control:
  public, max-age=86400`, Cache-Buster über den Dateistand) statt eines
  identischen Inline-Blocks je Katalogkarte; der MutationObserver
  beobachtet nur noch den Karten-Container statt des ganzen Dokuments

### Verkaufsbörse

- Admin-Inseratsliste und öffentliche Börse paginiert (50 je Seite),
  Pferde-Auswahl per Suchfeld (#74); der Verkaufs-Badge-Hook lädt die
  Inserats-IDs einmal je Request statt je Pferd (Framework#222)

### Repo

- Release-Prozess eingeführt (#65): Tags/Releases `vX.Y.z` folgen der
  Framework-Linie; Release-Pipeline mit Testsuite-Gate und
  Konsistenzprüfung (`scripts/check-release-consistency.php`: Unter- und
  Pflicht-Obergrenze aller Manifeste müssen die Ziel-Linie einschließen);
  dieses CHANGELOG; Ablaufdokumentation in `docs/releasing.md`
