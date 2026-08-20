# galerie

Ergänzt das einzelne `image_url`-Feld des Kerns um eine Medien-Galerie pro
Pferd: mehrere hochgeladene Fotos sowie Videos als externer Link
(YouTube/Vimeo), sortierbar, mit Grid und schlanker Lightbox (reines
Inline-CSS/JS, keine externe Bibliothek) auf der öffentlichen Detailseite.

Löst [Celestial0579/Hengstverzeichnis_Addons#16](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/16).

## Installation

```bash
cp -r galerie /pfad/zu/Hengstverzeichnis_Framework/plugins/galerie
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren und
der gewünschten Gruppe unter `/admin/groups` die Berechtigung
**Galerie → Verwalten** zuweisen.

## Design-Entscheidungen

- **Fotos** werden mit demselben Validierungsmuster wie das bestehende
  `image_url`-Feld hochgeladen (echte MIME-Prüfung per `finfo`,
  JPEG/PNG/WebP, max. 5 MB, Zufallsname) und unter `storage/plugin_galerie/`
  gespeichert - **außerhalb des Webroots**. Ausgeliefert werden sie
  ausschließlich über die zugriffsgeschützte Route
  `/plugin/galerie/bild?id=<medium>`, die je Anfrage Sitzung, `horses.view`
  und `is_published` prüft. Der Dateiname erscheint damit nirgends in einer
  öffentlichen Seite. Vorher lagen die Dateien im Webroot und der rohe Pfad
  stand im `<img src>`; nach einer Depublikation des Pferdes blieb das Foto
  darüber weiter abrufbar (GHSA-xrrq-9j94-fr5g). Bestandsdateien zieht
  `install()` beim Update selbsttätig um.
- **Videos** werden bewusst nur als externer Link erfasst (nur `https://`
  auf YouTube/Vimeo-Hosts) statt selbst gehostet - eigenes
  Video-Hosting/Transcoding wäre ein erheblicher Mehraufwand und passt
  nicht zur "keine externen Abhängigkeiten"-Philosophie des Kerns.
- Videos öffnen in einem neuen Tab statt als eingebettetes iframe: die
  Content-Security-Policy des Kerns (`default-src 'self'`, keine
  `frame-src`-Ausnahme) würde fremde iframes lautlos blockieren.

## Nutzung

1. Unter **Dashboard → Galerie** (`/plugin/galerie/verwaltung`) je Pferd
   Fotos hochladen oder Video-Links hinzufügen, optional mit
   Bildunterschrift und Sortierreihenfolge.
2. Die Galerie erscheint als Abschnitt "🖼️ Galerie" auf der öffentlichen
   Pferde-Detailseite, sobald mindestens ein Medium erfasst ist (Klick auf
   ein Foto öffnet die Lightbox, Escape oder Klick schließt sie).

## Protokollierung

Hinzufügen und Löschen eines Mediums stehen im Audit-Log des Kerns
(Kategorie `galerie`, sichtbar unter **Admin → Protokoll**). Beim Löschen
vermerkt der Eintrag auch die entfernte Bilddatei - vorher verschwand ein
Galeriebild samt Datei spurlos
([#134](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/134)).
Die Bildunterschrift bleibt draußen: freier Text, der Personen benennen kann.

## Technik

- Berechtigung: Modul `galerie`, Aktion `manage` (Verwaltungsseite und alle
  schreibenden Routen).
- Routen: `/plugin/galerie/verwaltung` (GET), `/verwaltung/store` und
  `/verwaltung/delete` (POST).
- Die Pferde-Auswahl der Verwaltung ist das gemeinsame Suchfeld des Kerns
  (`hv-pferdesuche` + `/js/horse-search.js`, gespeist aus
  `GET /admin/horses/search?q=…`, Framework#341); die addoneigene Route
  `/plugin/galerie/suche` ist mit #125 entfallen - sie war eine von sieben
  Kopien derselben Pferdesuche. Ohne JavaScript bleibt das Auswahlfeld leer,
  und der getippte Name wird beim Speichern serverseitig aufgelöst
  (eindeutiger Name, „Name (Jahrgang)" oder ein ausdrücklich angegebenes
  `[#id]`-Suffix). Die Medienliste ist mit 50 Einträgen je Seite paginiert
  (`?seite=…`). **Achtung:** Der Kern-Endpunkt verlangt `horses.view`; wer
  `galerie.manage` hat, braucht für die Suche zusätzlich dieses Leserecht -
  sonst bleibt der No-JS-Weg über den getippten Namen.
- Schema-Anlage: über den `install()`-Hook des PluginManagers (einmal bei
  Aktivierung bzw. nach einem Addon-Update); auf älteren Kernen ohne diesen
  Hook greift ein marker-geführter Fallback (`.schema-1` im
  Plugin-Verzeichnis), damit nicht bei jedem Request ein DDL-Statement
  läuft.
- Tabelle: `plugin_galerie_media`. Beim endgültigen Löschen eines Pferdes
  entfernt `ON DELETE CASCADE` nur die Datenbank-Zeilen - die hochgeladenen
  Dateien unter `storage/plugin_galerie/` bleiben dann als Waisen
  liegen (nur das Löschen über die Verwaltungsseite entfernt auch die
  Datei). Betreiber sollten das Verzeichnis gelegentlich abgleichen.
