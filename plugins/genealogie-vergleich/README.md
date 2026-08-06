# genealogie-vergleich

Stellt die Stammbäume zweier frei wählbarer Pferde nebeneinander dar und
hebt Vorfahren, die in beiden Bäumen vorkommen (gemeinsame Blutlinie), gold
hervor.

Löst [Celestial0579/Hengstverzeichnis_Addons#18](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/18).

## Installation

```bash
cp -r genealogie-vergleich /pfad/zu/Hengstverzeichnis_Framework/plugins/genealogie-vergleich
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren.
Keine Berechtigung nötig - das Tool zeigt ausschließlich Kombinationen von
Daten, die für jedes einzelne Pferd bereits öffentlich über `/hengst?id=...`
einsehbar sind.

## Nutzung

- Direkt über `/plugin/genealogie-vergleich` aufrufen und zwei Pferde
  auswählen (optional Generationstiefe 2-7 anpassen, Standard 5).
- Oder von der Pferde-Detailseite aus über den Link "🔬 Stammbaum mit einem
  anderen Pferd vergleichen" - startet das Tool mit diesem Pferd bereits als
  erste Auswahl vorbelegt (`?horse_a=<id>`).

## Funktionsweise

Nutzt `App\Service\PedigreeBuilder::build()` für beide Pferde unabhängig
voneinander (laut `docs/plugin-development.md` im Framework-Repo explizit
für diesen Anwendungsfall vorgesehen). Ein Vorfahre gilt als "gemeinsam",
wenn seine Pferde-ID in beiden Bäumen vorkommt - Platzhalter-Knoten
(unverknüpfte Namens-/UELN-Einträge ohne passenden Datenbankeintrag) werden
dabei nicht mitgezählt, da sie keine echte, eindeutige ID haben.
