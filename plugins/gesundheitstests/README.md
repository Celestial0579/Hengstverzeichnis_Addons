# gesundheitstests

Erfasst DNA-Tests, Röntgenbefunde und Gesundheitszeugnisse strukturiert pro
Pferd (Test-Art, Ergebnis-Zusammenfassung, Aussteller, Datum) inkl.
optionalem Dokument-Upload (PDF/Bild) - statt unstrukturiert im freien
`description`-Textfeld.

Löst [Celestial0579/Hengstverzeichnis_Addons#15](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/15).

## Installation

```bash
cp -r gesundheitstests /pfad/zu/Hengstverzeichnis_Framework/plugins/gesundheitstests
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren und
der gewünschten Gruppe unter `/admin/groups` die Berechtigung
**Gesundheitstests → Verwalten** zuweisen.

## Datenschutz/Sensibilität

Gesundheitsdaten sind sensibel, daher gilt durchgehend Opt-in statt
automatischer Veröffentlichung:

- Jeder Eintrag ist standardmäßig **nicht** öffentlich; erst das explizite
  Häkchen "Öffentlich sichtbar" zeigt ihn auf der öffentlichen Detailseite
  (und auch nur bei veröffentlichten Pferden, `is_published = 1`).
- Hochgeladene Dokumente liegen **außerhalb des Webroots**
  (`storage/plugin_gesundheitstests/` im Framework-Verzeichnis) und sind nie
  direkt per URL erreichbar - ausschließlich über die Download-Route
  `/plugin/gesundheitstests/download?id=...`, die dieselben
  Sichtbarkeitsregeln durchsetzt: öffentliche Einträge für alle (sofern die
  Gast-Gruppe `horses.view` hat **und** das Pferd veröffentlicht ist), alle
  übrigen nur mit der Verwaltungs-Berechtigung **in einer angemeldeten
  Sitzung** (Framework#218: ein Rechte-Fehlgriff bei der frei editierbaren
  Gast-Gruppe kann den Verwaltungs-Zweig damit nie für Anonyme öffnen).
  Unbekannte und nicht zugängliche IDs liefern eine identische 404 (kein
  Existenz-Orakel).
- Uploads werden per echter MIME-Prüfung (`finfo`) auf PDF/JPEG/PNG/WebP
  begrenzt (max. 10 MB) und unter einem zufälligen Dateinamen gespeichert -
  gleiches Muster wie `HorseController::handleImageUpload()` im Kern.

## Protokollierung

Anlegen und Löschen eines Eintrags stehen im Audit-Log des Kerns
(Kategorie `gesundheitstests`, sichtbar unter **Admin → Protokoll**) - das
Löschen eines Gesundheitsdokuments ist der Fall, für den es dieses Protokoll
gibt ([#134](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/134)).

Der Eintrag nennt **was, wer, wann, welcher Datensatz und welches Pferd**,
bei einem Dokument zusätzlich dessen Ablagenamen und daß es entfernt wurde.
Er nennt bewusst **nicht** die Ergebnis-Zusammenfassung, den Aussteller und
den ursprünglichen Dateinamen des Uploads: Das Protokoll wird dauerhaft
aufbewahrt, während die Gesundheitsdaten selbst löschbar bleiben sollen -
was dort landete, überlebte genau die Löschung, um die es geht.

## Nutzung

1. Im Datensatz des Pferdes (`/admin/horses/edit?id=…`, Kern-Hook
   `horse.edit_sections`): Der Abschnitt „🩺 Gesundheitstests" listet die
   Einträge dieses Pferdes samt Freigabe-Status, Dokument-Verweis und
   Löschen-Knopf und trägt alle Felder der Erfassung - Test-/Untersuchungsart,
   Ausstellungsdatum, Aussteller, Ergebnis-Zusammenfassung, Dokument-Upload und
   das Freigabe-Häkchen.
2. Als öffentlich markierte Einträge erscheinen automatisch als Abschnitt
   "🩺 DNA-/Gesundheitstests" auf der öffentlichen Pferde-Detailseite.

Seit [#120](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/120)
ist der Abschnitt der einzige Pflegeweg: Die addoneigene Verwaltungsseite
(`/plugin/gesundheitstests/verwaltung`), ihre Dashboard-Kachel und ihre
Pferdesuche (`/suche`,
[#125](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/125))
sind entfallen - sie ließen dasselbe Pferd über eine zweite Suche erneut
heraussuchen, obwohl man in dessen Datensatz bereits stand. Der Abschnitt
erscheint nur mit `gesundheitstests.manage`; die Berechtigung ist damit ein
Zusatzschalter zu `horses.edit`. Wer Tierarzt-/Zuchtverbandsdaten pflegen
soll, ohne Stammdaten ändern zu dürfen, ist damit nicht mehr abbildbar - das
war die bewusste Abwägung in #120.

## Technik

- Berechtigung: Modul `gesundheitstests`, Aktion `manage` (Abschnitt im
  Pferdeformular und alle schreibenden Routen).
- Routen: `/plugin/gesundheitstests/verwaltung/store` und
  `/verwaltung/delete` (POST, Ziele der Formulare im Pferdeabschnitt; der
  Rückweg führt auf `/admin/horses/edit?id=…`) sowie
  `/plugin/gesundheitstests/download?id=…` (GET, siehe oben). GET-Routen für
  eine eigene Verwaltungsseite und eine eigene Pferdesuche gibt es seit #120
  bzw. #125 nicht mehr.
- Das Formular des Abschnitts trägt `enctype="multipart/form-data"`: Es steht
  außerhalb des Kern-Formulars und muß die Kodierung selbst mitbringen - sonst
  käme der Upload als leeres `$_FILES` an, ohne Fehlermeldung.
- Schema-Anlage: über den `install()`-Hook des PluginManagers (einmal bei
  Aktivierung bzw. nach einem Addon-Update); auf älteren Kernen ohne diesen
  Hook greift ein marker-geführter Fallback (`.schema-1` im
  Plugin-Verzeichnis), damit nicht bei jedem Request ein DDL-Statement
  läuft.
- Tabelle: `plugin_gesundheitstests` (`ON DELETE CASCADE` auf `horses`).
