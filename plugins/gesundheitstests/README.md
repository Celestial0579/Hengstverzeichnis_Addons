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

## Deinstallation und Sicherung

Seit 1.3.0 steht im Datenregister (`owns`) der `plugin.json`, was dem Addon
gehört: die Tabelle `plugin_gesundheitstests` und die Dokumentablage
`storage/plugin_gesundheitstests` (Audit M27). **„Deinstallieren → Daten
löschen“ entfernt damit beides**; die Rückfrageseite nennt vorher die Zahl der
Einträge und Dokumente. Bis 1.2.0 blieb beides trotz „Daten löschen“ stehen,
und nach erneuter Aktivierung waren die Einträge samt Dokumenten wieder da.

**Sicherung (Audit N27):** Die Kern-Option „Hochgeladene Dateien mitsichern“
erfasst `storage/plugin_gesundheitstests` bis zu einem Kern-Release, das die
Verzeichnisse aus dem Datenregister mitsichert, **nicht**. Der SQL-Dump enthält
die Einträge, die Dokumente fehlen. Das Verzeichnis bitte separat sichern
(etwa per rsync oder Hoster-Backup) - insbesondere **vor einer Deinstallation
mit „Daten löschen“** und vor einem Umzug.

**Endgültiges Löschen eines Pferdes (Audit N26):** Wird ein Pferd endgültig
gelöscht - einzeln, über „Papierkorb leeren“ oder durch die 30-Tage-Bereinigung
-, entfernt das Addon auch dessen Dokumente aus der Ablage, und zwar erst,
nachdem der Kern das Pferd tatsächlich gelöscht hat (Hooks
`horse.before_delete` und `horse.deleted`). Das Verschieben in den Papierkorb
lässt alles stehen; eine Wiederherstellung findet ihre Dokumente wieder.

**Verwaiste Dokumente:** Bis 1.2.0 blieben Dokumente endgültig gelöschter
Pferde und gescheiterter Uploads ohne Eintrag in der Ablage liegen. Jede
Aktivierung und jedes Addon-Update (`install()`) entfernt solche Dateien - nur
selbst vergebene Ablagenamen (`gtest_<zeit>_<zufall>.<endung>`), nur älter als
24 Stunden und nur, wenn kein Eintrag auf sie verweist; scheitert die Abfrage,
wird nichts gelöscht. Die Anzahl steht im Protokoll. **Achtung nach einer
Rücksicherung:** Wird nur ein älterer Datenbankstand zurückgespielt, die
Ablage aber nicht, gelten die Dokumente neuerer Einträge als verwaist und
werden bei der nächsten Aktivierung bzw. dem nächsten Update entfernt. Ablage
und Datenbank deshalb immer gemeinsam zurückspielen.

**Eingabeprüfung:** Zu lange Angaben (Art über 100, Aussteller über 150
Zeichen, Zusammenfassung über 64 KB) und ungültige Daten werden vor dem
Hochladen mit einem Hinweis abgewiesen, statt mit einem Serverfehler zu enden
und die Datei ohne Eintrag zurückzulassen. Überlange Originaldateinamen werden
auf 255 Zeichen gekürzt.

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
- Tabelle: `plugin_gesundheitstests` (`ON DELETE CASCADE` auf `horses`);
  Datenregister siehe „Deinstallation und Sicherung“.
