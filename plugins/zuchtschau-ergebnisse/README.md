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

- Im Datensatz des Pferdes (`/admin/horses/edit?id=…`, Kern-Hook
  `horse.edit_sections`): Der Abschnitt „🏆 Zuchtschau-/Körungsergebnisse"
  listet die Ergebnisse dieses Pferdes und trägt alle Felder der Erfassung -
  Veranstaltung, Datum, Kategorie, Note, Platzierung, Richter, Kommentar -
  sowie das Löschen (POST-Routen `/ergebnisse/store` und
  `/ergebnisse/delete`; Tabelle `plugin_zuchtschau_ergebnisse`).
- Zu jedem Ergebnis lassen sich **Teilwertungen** erfassen (Bezeichnung,
  Wertung, Note, Platzierung, Distanz, Zeit - alle Fachfelder außer der
  Bezeichnung optional, die Altdaten aus v1/v2 sind lückig): aufklappbarer
  Abschnitt im Block des jeweiligen Ergebnisses, POST-Routen
  `/ergebnisse/teilwertung/store` und `/ergebnisse/teilwertung/delete`;
  Kindtabelle `plugin_zuchtschau_teilwertungen` mit
  `ON DELETE CASCADE` - beim Löschen eines Ergebnisses verschwinden seine
  Teilwertungen mit.

  **Zwei Ebenen, eine Reihenfolge:** Eine Teilwertung gehört zu einem
  Ergebnis, das erst gespeichert sein muß. Erst das Ergebnis anlegen, danach
  die Teilwertungen in dessen Block. Damit die Stelle dabei nicht verloren
  geht, führen die Rückwege der Teilwertungs-Routen einen Anker auf den Block
  des Ergebnisses mit (`/admin/horses/edit?id=…#zs-ergebnis-<id>`); der
  Rückweg der Ergebnis-Routen führt auf das Pferd.

  Seit [#124](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/124)
  ist der Abschnitt der einzige Pflegeweg: Die addoneigene Ergebnisseite
  (`/plugin/zuchtschau-ergebnisse/ergebnisse`) ist entfallen - sie ließ
  dasselbe Pferd über eine zweite Auswahl erneut heraussuchen, obwohl man in
  dessen Datensatz bereits stand. Der Abschnitt erscheint nur mit
  `zuchtschau-ergebnisse.manage`; die Berechtigung ist damit ein
  Zusatzschalter zu `horses.edit`. Wie zuvor trägt er anlegen und löschen,
  kein Ändern.
- Erfasste Ergebnisse erscheinen automatisch chronologisch (neuestes zuerst)
  auf der öffentlichen Detailseite des jeweiligen Pferdes - der Abschnitt
  wird nur angezeigt, wenn mindestens ein Ergebnis vorliegt. **Es gibt kein
  Freigabe-Häkchen** (anders als bei `gesundheitstests`): alles, was hier
  erfasst wird, ist bei veröffentlichtem Pferd sofort öffentlich sichtbar -
  einschließlich des Freitext-Kommentars, der Richternamen und Bewertungen
  enthalten kann.

## Protokollierung

Anlegen und Löschen von Ergebnissen und Teilwertungen stehen im Audit-Log des
Kerns (Kategorie `zuchtschau-ergebnisse`, sichtbar unter
**Admin → Protokoll**). Beim Löschen eines Ergebnisses hält der Eintrag fest,
wie viele Teilwertungen der Fremdschlüssel-CASCADE mitgenommen hat - die
hinterlassen von sich aus keine Spur
([#134](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/134)).
Richtername und Kommentar bleiben draußen (Personenbezug bzw. freier Text).

## Berechtigungen

| Modul | Aktion | Beschreibung |
|---|---|---|
| `zuchtschau-ergebnisse` | `manage` | Ergebnisse erfassen/löschen |
