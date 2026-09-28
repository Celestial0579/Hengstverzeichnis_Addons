# inzuchtkoeffizient

Berechnet **Wright's Inzuchtkoeffizienten (COI)** auf Basis des bestehenden
Pedigree-Baums und zeigt ihn auf der öffentlichen Pferde-Detailseite an.
Zusätzlich gibt es einen berechtigungsgeschützten **Verpaarungsrechner**
(`/plugin/inzuchtkoeffizient/rechner`, nur per direkter URL erreichbar - das
Addon registriert keine Dashboard-Kachel), der den voraussichtlichen COI
eines Fohlens aus zwei frei wählbaren Elterntieren schätzt - bevor die
beiden tatsächlich verpaart wurden.

Löst [Celestial0579/Hengstverzeichnis_Addons#4](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/4).

## Installation

```bash
cp -r inzuchtkoeffizient /pfad/zu/Hengstverzeichnis_Framework/plugins/inzuchtkoeffizient
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren.
Der Verpaarungsrechner (`/plugin/inzuchtkoeffizient/rechner`) benötigt die
Berechtigung `inzuchtkoeffizient.calculate`, die einer Gruppe unter
`/admin/groups` zugewiesen werden muss.

## Funktionsweise

- **Detailseite:** baut je Elternteil einen eigenen, bis zu 6 Generationen
  tiefen Stammbaum über `App\Service\PedigreeBuilder::build()` auf - mit dem
  **Elternteil als Wurzel**. Der vom Kern an den Filter übergebene Baum hat
  das Pferd selbst als Wurzel und reicht je Elternteil nur 5 Generationen;
  ihn zu übernehmen ließ einen gemeinsamen Vorfahren der sechsten Generation
  auf der Detailseite verschwinden, den der Verpaarungsrechner bei gleicher
  Datenlage noch zählte (#72). Öffentlich gefiltert: unveröffentlichte
  Vorfahren stecken nur als Platzhalter im Baum und fließen nicht in den COI ein.
  Welche Pferde die Eltern sind, übernimmt der Abschnitt aus dem Stammbaum des
  Kerns: auch Eltern, die nur per Lebensnummer oder Name eingetragen sind
  (CSV-Import, Freitext im Formular), sofern sie zu einem veröffentlichten
  Pferd auflösen (Audit M28). Vorher zählten nur die festen Verknüpfungen,
  und der Abschnitt zeigte in solchen Fällen 0,00 % oder fehlte.
- **Verpaarungsrechner:** baut für die zwei ausgewählten Pferde jeweils einen
  eigenen Stammbaum über `App\Service\PedigreeBuilder::build()` auf (wählbare
  Tiefe 1-8) und berechnet daraus den COI des hypothetischen Fohlens - hier
  **ungefiltert**, also einschließlich unveröffentlichter Vorfahren, denn die
  Route ist berechtigungsgeschützt. Derselbe Verpaarungsfall kann deshalb auf
  der öffentlichen Detailseite und im Rechner unterschiedliche Werte liefern,
  sobald unveröffentlichte Vorfahren im Spiel sind.
- **Pferde-Auswahl:** Die Elterntiere werden über das gemeinsame Suchfeld des
  Kerns gewählt (`hv-pferdesuche` + `/js/horse-search.js`, gespeist aus
  `GET /admin/horses/search?q=…&rolle=sire|dam`, Framework#341). Die
  addoneigene Route `/plugin/inzuchtkoeffizient/suche` ist mit #125 entfallen -
  sie war eine von sieben Kopien derselben Pferdesuche.
  Das gewählte Pferd steht im `<option>` des Auswahlfelds `sire_id`/`dam_id`;
  die frühere `[#<id>]`-Krücke im Anzeigetext entfällt, Ergebnis-URLs bleiben
  unverändert teilbar.
  Der `rolle`-Parameter soll die Vorschläge nach Geschlecht filtern
  (Hengst-Feld ohne Stuten/Wallache, Stuten-Feld ohne Hengste/Wallache; Pferde
  ohne Geschlechtsangabe bleiben in beiden wählbar). **Solange der
  Kern-Endpunkt unter `rolle` die Zuordnungsrolle aus `horse_persons`
  versteht, sind die Vorschläge ungefiltert** - die rollenwidrige Auswahl
  wird aber weiterhin serverseitig verworfen (#54), die Prüfung hängt also
  nicht an den Vorschlägen.
  **Achtung:** Der Kern-Endpunkt verlangt `horses.view`; wer den Rechner
  nutzen darf, braucht für die Suche zusätzlich dieses Leserecht.

## Berechnungsmethode

Der Rechenkern steht seit Addons#123 nicht mehr in `Plugin.php`, sondern in
`WrightCoi.php` (Klasse `Hengstverzeichnis\Addons\Shared\WrightCoi`) - dieselbe
Datei liefert das Addon `anpaarungs-empfehlung` zeichengleich mit, damit beide
einzeln installierbar bleiben und trotzdem durch **denselben** Code rechnen.
Vorher waren es zwei getrennte Klassen, die schon einmal auseinandergelaufen
sind. Die Begründung im Einzelnen steht im Kopfkommentar von `WrightCoi.php`;
die Zeichengleichheit der Kopien prüft
`tests/Unit/CoiGemeinsameFassungTest.php`. Der Altname `CoiCalculator` bleibt
als Alias auf dieselbe Klasse bestehen.

Verwendet die im Zuchtwesen gängige Pfad-Koeffizienten-Formel

```
F = Σ (0,5)^(n1 + n2 + 1)
```

summiert über alle gemeinsamen Vorfahren, wobei `n1`/`n2` die Anzahl der
Generationsschritte vom jeweiligen Elternteil zum gemeinsamen Vorfahren sind.
Dabei gilt **Wrights Pfadregel**: Ein Pfad Vater → … → gemeinsamer Vorfahre
→ … → Mutter darf kein Pferd doppelt enthalten. Gezählt wird deshalb jedes
Pfadpaar, dessen beide Hälften sich nur im gemeinsamen Vorfahren selbst
schneiden. Ahnen eines gemeinsamen Vorfahren, die nur durch ihn hindurch
erreichbar sind, zählen damit nicht zusätzlich. (Ohne diese Regel lieferte der
Rechenkern früher z. B. 48,44 % statt korrekt 25,00 % für das Fohlen zweier
Vollgeschwister.) Erreicht eine Seite einen solchen Ahnen aber auch auf eigenem
Weg, zählt er mit. Bis Version 1.2.0 endete jeder Pfad am ersten gemeinsamen
Vorfahren, und bei Linienzucht fehlten genau diese Pfade: Bei beidseitiger
Linienzucht auf einen Hengst und dessen Vater zeigte das Register 12,50 %
statt 15,63 % (Audit M29).

**Gemeinsam mit `anpaarungs-empfehlung` aktualisieren.** Beide Addons bringen
denselben Rechenkern mit, und geladen wird nur eine Kopie. Der PluginManager
lädt alphabetisch, sind beide aktiv, rechnet also immer die Fassung aus
`anpaarungs-empfehlung`. Eine veraltete Fassung (ohne `WrightCoi::REVISION`
bzw. mit einer Revision unter 2) meldet sich im Fehlerprotokoll.
Die Lehrbuchfälle sind in `tests/Unit/InzuchtkoeffizientCoiTest.php` als
Unit-Tests festgehalten.
Vereinfachung: der exakte Wright-Term `(1 + F_A)` für die Ingezüchtetheit des
gemeinsamen Vorfahren selbst wird **nicht** rekursiv nachberechnet, da dies
bei jedem Seitenaufruf zusätzliche, potenziell exponentiell viele
`PedigreeBuilder`-Abfragen auslösen würde (kein Caching, siehe
`docs/plugin-development.md` im Framework-Repo). Für die verfügbare Tiefe
(6-8 Generationen) ist die Abweichung in der Praxis gering - bei stark
ingezüchteten gemeinsamen Vorfahren kann der tatsächliche Wert etwas höher
liegen als der hier angezeigte.

## Berechtigungen

| Modul | Aktion | Beschreibung |
|---|---|---|
| `inzuchtkoeffizient` | `calculate` | Zugriff auf den Verpaarungsrechner (`/plugin/inzuchtkoeffizient/rechner`) |
