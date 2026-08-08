# Besucherstatistik

Beispiel-Addon für [Hengstverzeichnis_Framework](https://github.com/Celestial0579/Hengstverzeichnis_Framework).
Zählt pro Pferd, wie oft dessen öffentliche Detailseite (`/hengst?id=`)
aufgerufen wurde, zeigt die Aufrufzahl direkt auf der Detailseite an und
stellt eine Rangliste der meistgesehenen Pferde unter einer eigenen,
berechtigungsgeschützten Route bereit.

Demonstriert alle drei Erweiterungspunkte des Plugin-Systems (siehe
[docs/plugin-development.md](https://github.com/Celestial0579/Hengstverzeichnis_Framework/blob/main/docs/plugin-development.md)
im Framework-Repo):

- **Hooks**: `horse.detail_sections` (Filter), `admin.dashboard_tiles`
  (Filter), `horse.after_save` (Action). Gezählt wird direkt im
  `horse.detail_sections`-Filter - jeder Seitenaufruf erhöht den Zähler,
  auch Bots und Crawler; eine Deduplizierung per Session oder IP gibt es
  bewusst nicht (keine personenbezogene Speicherung).
- **Eigene Tabelle**: `plugin_besucherstatistik_views`, in `register()`
  idempotent per `CREATE TABLE IF NOT EXISTS` angelegt
- **Eigene Route**: `/plugin/besucherstatistik/statistik`, geschützt über
  eine von `BaseController` erbende Klasse
- **Eigenes Berechtigungsmodul**: `besucherstatistik` / `view` ("Statistik
  einsehen"), sichtbar in der Berechtigungsmatrix unter `/admin/groups`

## Installation

Im Framework-Repo (`plugins/` ist dort gitignored, Addons werden lokal
hineinkopiert):

```bash
cp -r plugins/besucherstatistik /pfad/zu/Hengstverzeichnis_Framework/plugins/besucherstatistik
```

Danach:

1. Unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren.
2. Unter **Admin → Gruppen & Berechtigungen** (`/admin/groups`) der
   gewünschten Gruppe die Berechtigung *Besucherstatistik → Statistik
   einsehen* zuweisen.
3. Die Rangliste ist danach über die neue Dashboard-Kachel oder direkt unter
   `/plugin/besucherstatistik/statistik` erreichbar.

## Hinweis

Bei jeder inhaltlichen Änderung am Code muss `version` in `plugin.json`
erhöht werden, sonst erkennt der Kern die Änderung als verdächtig und lädt
das Plugin nicht mehr (siehe Abschnitt "Update-Erkennung" in der
Framework-Dokumentation).
