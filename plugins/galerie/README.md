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
  JPEG/PNG/WebP, max. 5 MB, Zufallsname) und unter
  `public/uploads/plugin_galerie/` gespeichert.
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
2. Die Galerie erscheint automatisch als Abschnitt "🖼️ Galerie" auf der
   öffentlichen Pferde-Detailseite (Klick auf ein Foto öffnet die
   Lightbox, Escape oder Klick schließt sie).
