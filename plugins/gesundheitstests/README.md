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

## Nutzung

1. Unter **Dashboard → Gesundheitstests** (`/plugin/gesundheitstests/verwaltung`)
   Einträge je Pferd erfassen, optional mit Dokument.
2. Als öffentlich markierte Einträge erscheinen automatisch als Abschnitt
   "🩺 DNA-/Gesundheitstests" auf der öffentlichen Pferde-Detailseite.

## Technik

- Berechtigung: Modul `gesundheitstests`, Aktion `manage` (Verwaltungsseite,
  Pferdesuche und alle schreibenden Routen).
- Routen: `/plugin/gesundheitstests/verwaltung` (GET),
  `/plugin/gesundheitstests/suche?q=…` (GET, JSON für die Pferde-Datalist,
  max. 50 Treffer), `/verwaltung/store` und `/verwaltung/delete` (POST) sowie
  `/plugin/gesundheitstests/download?id=…` (GET, siehe oben).
- Die Pferde-Auswahl der Verwaltung ist ein Suchfeld mit serverseitig
  nachgeladener Vorschlagsliste (`<input list>` + `<datalist>`) statt eines
  Voll-`<select>` über den gesamten Bestand; ohne JavaScript wird der
  getippte Name beim Speichern serverseitig aufgelöst (eindeutiger Name,
  „Name (Jahrgang)" oder das `[#id]`-Suffix der Vorschläge). Die
  Eintragsliste ist mit 50 Einträgen je Seite paginiert (`?seite=…`).
- Schema-Anlage: über den `install()`-Hook des PluginManagers (einmal bei
  Aktivierung bzw. nach einem Addon-Update); auf älteren Kernen ohne diesen
  Hook greift ein marker-geführter Fallback (`.schema-1` im
  Plugin-Verzeichnis), damit nicht bei jedem Request ein DDL-Statement
  läuft.
- Tabelle: `plugin_gesundheitstests` (`ON DELETE CASCADE` auf `horses`).
