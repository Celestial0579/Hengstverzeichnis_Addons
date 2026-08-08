# pedigree-export

Fügt der öffentlichen Pferde-Detailseite einen Link "🖨️ Stammbaum drucken /
als PDF exportieren" hinzu, der zu einer eigenständigen, druckoptimierten
Ansicht des Stammbaums führt (bis zu 6 Generationen - dieselbe Tiefe wie die
normale Detailseite; die Route ist anonym erreichbar, eine größere wählbare
Tiefe würde pro Aufruf exponentiell mehr Datenbank-Abfragen erlauben als der
Kern selbst). Der Export erfolgt über die Browser-Druckfunktion
("Ziel: Als PDF speichern").

Löst [Celestial0579/Hengstverzeichnis_Addons#7](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/7)
sowie den Pedigree-Teil von
[Celestial0579/Hengstverzeichnis_Addons#6](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/6)
(der CSV/Excel-Teil von #6 wird vom separaten Addon
[`katalog-export`](../katalog-export/README.md) abgedeckt).

## Installation

```bash
cp -r pedigree-export /pfad/zu/Hengstverzeichnis_Framework/plugins/pedigree-export
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren.
Keine plugin-eigene Berechtigung nötig - die Export-Ansicht zeigt exakt
dieselben Daten, die bereits öffentlich auf `/hengst?id=...` sichtbar sind,
nur anders aufbereitet. Es gelten dieselben Sichtbarkeitsregeln wie dort:
Ohne `horses.view` der Gast-Gruppe liefert die Route eine 404, unveröffentlichte
Pferde ergeben eine 404, und unveröffentlichte Vorfahren erscheinen nicht im
Export.

## Warum kein echtes PDF-Rendering?

Issue #7 stellt zur Wahl, entweder ein schlankes Druck-Stylesheet
(`@media print`, keine neue Abhängigkeit) oder eine vollwertige
serverseitige Bild-/PDF-Generierung umzusetzen. Diese Umsetzung entscheidet
sich bewusst für die schlanke Variante:

- Der Kern verfolgt durchgängig die Philosophie "keine externen
  Abhängigkeiten" (siehe `docs/plugin-development.md` im Framework-Repo).
  Echtes serverseitiges PDF-Rendering (z. B. über `dompdf` oder
  `mpdf`) würde diese Philosophie für ein einzelnes Addon durchbrechen.
- Jeder moderne Browser bietet mit "Drucken → Als PDF speichern" bereits
  eine zuverlässige, plattformunabhängige PDF-Erzeugung ohne Server-Last.
- `@page { size: A3 landscape; }` im Druck-Stylesheet sorgt dafür, dass auch
  ein voller 6-Generationen-Stammbaum auf eine überschaubare Anzahl Seiten
  passt.

## Nutzung

1. Auf der öffentlichen Pferde-Detailseite auf "🖨️ Stammbaum drucken / als
   PDF exportieren" klicken (öffnet in neuem Tab).
2. Optional Generationstiefe über `?depth=2-6` in der URL anpassen (Standard: 6;
   Werte außerhalb des Bereichs werden auf 2 bzw. 6 begrenzt).
3. Über den Button "🖨️ Drucken / Als PDF speichern" die Browser-Druckfunktion
   öffnen und als Ziel "Als PDF speichern" wählen.
