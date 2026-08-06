# statistik-dashboard

Ergänzt das Admin-Dashboard um eine Kennzahlen-Übersicht: `admin_dashboard.php`
zeigt im Kern bisher nur Navigation und den Papierkorb-Zähler, keinerlei
Kennzahlen zum Datenbestand.

Löst [Celestial0579/Hengstverzeichnis_Addons#5](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/5).

## Installation

```bash
cp -r statistik-dashboard /pfad/zu/Hengstverzeichnis_Framework/plugins/statistik-dashboard
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren und
der gewünschten Gruppe unter `/admin/groups` die Berechtigung
"Statistik-Dashboard → Statistik einsehen" zuweisen.

## Enthaltene Kennzahlen (`/plugin/statistik-dashboard/statistik`)

- Anzahl aktiver/inaktiver/verstorbener Pferde (Gesamtzahl + je Status)
- Verteilung nach Deckstation (Top 15)
- Wachstum der Datenbank über Zeit (neu angelegte Pferde je Jahr, basierend
  auf `created_at`)
- Top-Blutlinien: meistgenutzte Väter und Mütter (Top 10 je Elternteil,
  berücksichtigt sowohl verknüpfte Pferde über `sire_id`/`dam_id` als auch
  unverknüpfte Namenseinträge über `sire_name`/`dam_name`)

## Berechtigungen

| Modul | Aktion | Beschreibung |
|---|---|---|
| `statistik-dashboard` | `view` | Zugriff auf die Statistik-Seite |
