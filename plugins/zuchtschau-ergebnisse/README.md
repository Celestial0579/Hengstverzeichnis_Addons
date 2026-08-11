# zuchtschau-ergebnisse

Erfasst detaillierte Zuchtschau-/Körungsergebnisse (Veranstaltung, Note,
Richter, Platzierung, Kommentar) pro Pferd - der Kern kennt bisher nur den
binären Status `active` ("Aktiv (Gekört)"), keine strukturierten
Einzelergebnisse.

Löst [Celestial0579/Hengstverzeichnis_Addons#14](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/14);
strukturierte Teilwertungen je Ergebnis kamen mit
[#82](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/82) hinzu.

## Installation

```bash
cp -r zuchtschau-ergebnisse /pfad/zu/Hengstverzeichnis_Framework/plugins/zuchtschau-ergebnisse
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren und
der gewünschten Gruppe unter `/admin/groups` die Berechtigung
"Zuchtschau-Ergebnisse → Verwalten" zuweisen.

## Nutzung

- Über `/plugin/zuchtschau-ergebnisse/ergebnisse` Ergebnisse für ein
  bestehendes Pferd erfassen (Veranstaltung, Datum, Kategorie, Note,
  Platzierung, Richter, Kommentar) oder löschen (POST-Routen
  `/ergebnisse/store` und `/ergebnisse/delete`; Tabelle
  `plugin_zuchtschau_ergebnisse`).
- Zu jedem Ergebnis lassen sich **Teilwertungen** erfassen (Bezeichnung,
  Wertung, Note, Platzierung, Distanz, Zeit - alle Fachfelder außer der
  Bezeichnung optional, die Altdaten aus v1/v2 sind lückig): aufklappbarer
  Abschnitt unter der jeweiligen Ergebniszeile, POST-Routen
  `/ergebnisse/teilwertung/store` und `/ergebnisse/teilwertung/delete`;
  Kindtabelle `plugin_zuchtschau_teilwertungen` mit
  `ON DELETE CASCADE` - beim Löschen eines Ergebnisses verschwinden seine
  Teilwertungen mit.
- Erfasste Ergebnisse erscheinen automatisch chronologisch (neuestes zuerst)
  auf der öffentlichen Detailseite des jeweiligen Pferdes - der Abschnitt
  wird nur angezeigt, wenn mindestens ein Ergebnis vorliegt. **Es gibt kein
  Freigabe-Häkchen** (anders als bei `gesundheitstests`): alles, was hier
  erfasst wird, ist bei veröffentlichtem Pferd sofort öffentlich sichtbar -
  einschließlich des Freitext-Kommentars, der Richternamen und Bewertungen
  enthalten kann.

## Berechtigungen

| Modul | Aktion | Beschreibung |
|---|---|---|
| `zuchtschau-ergebnisse` | `manage` | Ergebnisse erfassen/löschen |
