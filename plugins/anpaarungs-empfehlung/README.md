# anpaarungs-empfehlung — Anpaarungs-Empfehlung

Addon für [Hengstverzeichnis_Framework](https://github.com/Celestial0579/Hengstverzeichnis_Framework).
Findet für ein ausgewähltes Pferd die genetisch vielfältigsten Partner.

## Funktion

Route `/plugin/anpaarungs-empfehlung/empfehlung`: ein Basispferd (typischerweise
eine Stute) auswählen – das Addon berechnet für jedes andere Pferd im Register
den voraussichtlichen Inzuchtkoeffizienten (Wright's COI) eines gemeinsamen
Fohlens und sortiert die Vorschläge aufsteigend, also die vielfältigste
(niedrigster COI) Verpaarung zuerst. Verpaarungen ab einem Fohlen-COI von
6,25 % (etwa Halbgeschwister- bzw. Onkel/Nichte-Niveau) werden optisch markiert.
Als Kandidaten zählen alle nicht gelöschten Pferde des Registers, auch
unveröffentlichte, und die Stammbäume werden ungefiltert geladen - für eine
berechtigungsgeschützte Zucht-Auswertung gewollt; die Werte können deshalb von
der öffentlichen Detailseite abweichen, die nur veröffentlichte Vorfahren
einbezieht. Die Route ist nur per direkter URL erreichbar, das Addon
registriert keine Dashboard-Kachel und keinen Hook.

Generationstiefe (1–8) und Anzahl der Vorschläge sind einstellbar.

Zugriff nur mit der Berechtigung `anpaarung.recommend` (unter **Admin →
Gruppen** zuweisbar; Admins haben sie systemseitig immer).

## Verhältnis zum Inzuchtkoeffizient-Addon

Das Addon `inzuchtkoeffizient` beantwortet „wie hoch ist der COI dieser einen
Verpaarung?" (zwei fest gewählte Tiere). Dieses Addon dreht die Frage um: „welcher
Partner passt am besten zu diesem Tier?" und rankt dafür das gesamte Register.
Die COI-Rechenlogik (Pfad-Koeffizienten-Verfahren) bringt es bewusst selbst mit,
damit es unabhängig davon funktioniert, ob `inzuchtkoeffizient` aktiviert ist.

## Annahmen

Näherung `F = Σ (0,5)^(n1+n2+1)` über alle gemeinsamen Vorfahren, ausgewertet
über den verfügbaren Stammbaum (Standard 5, bis zu 8 Generationen). Der exakte
Wright-Term für die Eigen-Ingezüchtetheit gemeinsamer Vorfahren wird – wie beim
`inzuchtkoeffizient`-Addon – nicht rekursiv nachberechnet.

**Bekannte Abweichung zum `inzuchtkoeffizient`-Addon:** Dessen Rechenkern
wendet inzwischen Wrights Pfadregel an (die Ahnen eines bereits gezählten
gemeinsamen Vorfahren zählen nicht zusätzlich); die hiesige Näherung summiert
dagegen über **alle** gemeinsamen Vorfahren weiter. Sobald ein gemeinsamer
Vorfahre selbst bekannte Vorfahren im Baum hat, liefert dieses Addon deshalb
systematisch **höhere** COI-Werte als der Verpaarungsrechner. Für das Ranking
(aufsteigende Sortierung) ist das meist unschädlich, die absoluten Prozentwerte
beider Addons sind aber nicht vergleichbar. Eine Angleichung an die
Pfadregel steht aus.

Die Empfehlung ist eine genetische Kennzahl, keine vollständige züchterische
Bewertung. Für die Farbprognose siehe das Addon `farbvererbung`.

## Installation

```bash
cp -r anpaarungs-empfehlung /pfad/zu/Hengstverzeichnis_Framework/plugins/anpaarungs-empfehlung
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren und die
Berechtigung `anpaarung.recommend` der gewünschten Gruppe zuweisen.
