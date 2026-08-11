# titel-praemierungen

Erfasst Titel, Prämierungen und sportliche Erfolge strukturiert pro Pferd
(Art, Bezeichnung, Jahr, Kommentar) - der v2-Altbestand führte diese Angaben
als Listen (`titel[]`, `praemierungen[]`, `erfolge[]`), die in Gen 3 mangels
Gegenstück bisher nur als Textblöcke in `horses.description` landeten: nicht
filterbar, nicht einzeln pflegbar.

Löst [Celestial0579/Hengstverzeichnis_Addons#81](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/81).

## Installation

```bash
cp -r titel-praemierungen /pfad/zu/Hengstverzeichnis_Framework/plugins/titel-praemierungen
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren und
der gewünschten Gruppe unter `/admin/groups` die Berechtigung
"Titel & Prämierungen → Verwalten" zuweisen.

## Nutzung

- Über die Dashboard-Kachel bzw. `/plugin/titel-praemierungen/auszeichnungen`
  Auszeichnungen für ein bestehendes Pferd erfassen (Art als feste Auswahl
  Titel/Prämierung/Erfolg, Bezeichnung, optionales Jahr, Kommentar) oder
  löschen (POST-Routen `/auszeichnungen/store` und `/auszeichnungen/delete`;
  Tabelle `plugin_titel_praemierungen`, `art` als ENUM).
- Erfasste Auszeichnungen erscheinen automatisch auf der öffentlichen
  Detailseite des jeweiligen Pferdes - gruppiert nach Art (Titel zuerst),
  innerhalb der Art neueste Jahre oben. Der Abschnitt wird nur angezeigt,
  wenn mindestens eine Auszeichnung vorliegt. **Es gibt kein
  Freigabe-Häkchen** (anders als bei `gesundheitstests`): alles, was hier
  erfasst wird, ist bei veröffentlichtem Pferd sofort öffentlich sichtbar -
  einschließlich des Freitext-Kommentars.
- Beim Löschen eines Pferdes verschwinden dessen Auszeichnungen automatisch
  mit (`ON DELETE CASCADE`).

## Berechtigungen

| Modul | Aktion | Beschreibung |
|---|---|---|
| `titel-praemierungen` | `manage` | Auszeichnungen erfassen/löschen |
