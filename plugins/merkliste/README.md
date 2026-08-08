# merkliste

Rein clientseitige Merkliste für anonyme Besucher: ein "☆ Merken"-Button auf
der öffentlichen Pferde-Detailseite und auf jeder Katalogkarte speichert die
Pferde-ID im `localStorage` des Browsers - kein Account, keine
Server-Session, keine Cookies. Die Seite `/plugin/merkliste` löst die
gespeicherten IDs über eine schreibgeschützte JSON-API zu Name/Bild/Link auf.

Löst [Celestial0579/Hengstverzeichnis_Addons#19](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/19).

## Installation

```bash
cp -r merkliste /pfad/zu/Hengstverzeichnis_Framework/plugins/merkliste
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren.
Keine Berechtigung nötig - die API gibt ausschließlich Daten aus, die der
öffentliche Katalog ohnehin zeigt.

## Hinweis zur ursprünglichen Einschränkung aus dem Issue

Issue #19 nannte als bekannte Einschränkung, dass es (Stand Phase 1 der
Hooks) keinen Hook auf der Katalog-Kartenansicht gab und der Button daher
nur auf der Detailseite möglich wäre. Der Framework-Kern stellt inzwischen
den Filter `catalog.card_sections` (#97) bereit - der Button erscheint
daher zusätzlich direkt auf jeder Katalogkarte.

## Technik

- `GET /plugin/merkliste` - die "Meine Merkliste"-Seite (liest die IDs per
  JS aus `localStorage`, Schlüssel `hv_merkliste`).
- `GET /plugin/merkliste/api?ids=1,2,3` - schreibgeschützte JSON-API
  (max. 100 IDs pro Anfrage), gleiche Sichtbarkeitsregeln wie der
  öffentliche Katalog: `horses.view`-Berechtigung der Gast-Gruppe, nur
  veröffentlichte (`is_published = 1`), nicht gelöschte Pferde. Unbekannte
  oder verborgene IDs fehlen schlicht in der Antwort.
- Die Button-Beschriftung ("☆ Merken"/"★ Gemerkt") wird per
  MutationObserver auch nach AJAX-Nachladen des Katalogs synchron gehalten.
