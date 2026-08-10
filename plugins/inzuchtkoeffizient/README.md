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
- **Verpaarungsrechner:** baut für die zwei ausgewählten Pferde jeweils einen
  eigenen Stammbaum über `App\Service\PedigreeBuilder::build()` auf (wählbare
  Tiefe 1-8) und berechnet daraus den COI des hypothetischen Fohlens - hier
  **ungefiltert**, also einschließlich unveröffentlichter Vorfahren, denn die
  Route ist berechtigungsgeschützt. Derselbe Verpaarungsfall kann deshalb auf
  der öffentlichen Detailseite und im Rechner unterschiedliche Werte liefern,
  sobald unveröffentlichte Vorfahren im Spiel sind.
- **Pferde-Auswahl:** Die Elterntiere werden über Suchfelder
  (`<input list>` + `<datalist>`) gewählt, die ihre Vorschläge serverseitig
  von `GET /plugin/inzuchtkoeffizient/suche?q=…&rolle=sire|dam` holen
  (höchstens 50 Treffer, gleiche Berechtigung wie der Rechner). Das ersetzt
  das frühere `<select>` über den kompletten Pferdebestand (#74). Der
  `rolle`-Parameter filtert nach Geschlecht (Hengst-Feld ohne Stuten/Wallache,
  Stuten-Feld ohne Hengste/Wallache; Pferde ohne Geschlechtsangabe bleiben in
  beiden wählbar), die getroffene Auswahl wird serverseitig erneut geprüft.
  Jeder Vorschlag endet auf `[#<id>]`; daraus füllt die Seite die eigentlichen
  Parameter `sire_id`/`dam_id`, Ergebnis-URLs bleiben also unverändert teilbar.

## Berechnungsmethode

Verwendet die im Zuchtwesen gängige Pfad-Koeffizienten-Formel

```
F = Σ (0,5)^(n1 + n2 + 1)
```

summiert über alle gemeinsamen Vorfahren, wobei `n1`/`n2` die Anzahl der
Generationsschritte vom jeweiligen Elternteil zum gemeinsamen Vorfahren sind.
Dabei gilt **Wrights Pfadregel**: Die Pfade enden am jeweils ersten
gemeinsamen Vorfahren - dessen eigene Ahnen zählen nicht zusätzlich als
gemeinsame Vorfahren, denn jeder Pfad zu ihnen enthielte den bereits
gezählten Vorfahren erneut. (Ohne diese Regel lieferte der Rechenkern früher
z. B. 48,44 % statt korrekt 25,00 % für das Fohlen zweier Vollgeschwister.)
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
