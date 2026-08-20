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

- Gepflegt wird **im Bearbeitungsformular des Pferdes**
  (`/admin/horses/edit?id=…`, Kern-Hook `horse.edit_sections`): Der Abschnitt
  „🏅 Titel & Prämierungen" listet die Auszeichnungen dieses Pferdes und
  erlaubt **Anlegen, Ändern und Löschen** — Art als feste Auswahl
  Titel/Prämierung/Erfolg, Bezeichnung, optionales Jahr, Kommentar
  (POST-Routen `/auszeichnungen/store`, `/auszeichnungen/update` und
  `/auszeichnungen/delete`; Tabelle `plugin_titel_praemierungen`, `art` als
  ENUM). Das Pferd selbst ist im Änderungsformular nicht umstellbar: Es kommt
  aus dem Aufrufkontext; eine Auszeichnung von Pferd A auf Pferd B umzuhängen
  wäre eine Verwechslungsquelle, dafür ist Löschen und Neuanlegen der
  ehrlichere Weg.
- Eine **eigene Verwaltungsseite und eine Dashboard-Kachel gibt es seit #117
  nicht mehr**. Sie verlangten, dasselbe Pferd über eine zweite Suche erneut
  herauszusuchen, obwohl man in dessen Datensatz bereits steht. Aufgegeben
  wurde damit bewusst die bestandsweite Liste „Erfasste Auszeichnungen"; das
  Ändern einzelner Einträge, das die Seite ebenfalls nicht konnte, kam dafür
  im Pferdeabschnitt hinzu.
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
| `titel-praemierungen` | `manage` | Auszeichnungen erfassen/ändern/löschen |

**Fail-closed:** Ohne `titel-praemierungen.manage` erscheint der Abschnitt im
Bearbeitungsformular gar nicht — kein leeres Formular, das beim Absenden 403
liefert. Die POST-Routen prüfen dieselbe Berechtigung noch einmal selbst.

Seit #117 ist zusätzlich **`horses.edit`** nötig, denn der Abschnitt hängt am
Bearbeitungsformular des Pferdes. Wer `titel-praemierungen.manage` ohne
`horses.edit` hat, kommt nicht mehr an die Pflege — bewusst so entschieden
(dieselbe Entscheidung wie bei `galerie`, #116): Eine zweite Seite allein für
diesen Zuschnitt offenzuhalten hieße, den doppelten Pflegeweg zu behalten.
