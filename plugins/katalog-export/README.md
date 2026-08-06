# katalog-export

CSV-Export des Pferdekatalogs - wahlweise gefiltert (dieselben Filterfelder
wie der öffentliche Katalog) oder komplett ungefiltert. CSV statt eines
echten `.xlsx`-Formats, damit keine zusätzliche Composer-Abhängigkeit nötig
ist - die Datei öffnet direkt in Excel/LibreOffice/Numbers (inkl.
UTF-8-BOM, damit Umlaute unter Windows-Excel korrekt dargestellt werden).

Löst den CSV/Excel-Teil von
[Celestial0579/Hengstverzeichnis_Addons#6](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/6).
Der PDF-Export einzelner Pferde-Pedigrees aus derselben Issue wird vom
separaten Addon [`pedigree-export`](../pedigree-export/README.md) abgedeckt
(siehe dort für die Begründung, warum PDF-Export als eigenes Addon statt
hier gelöst ist).

## Installation

```bash
cp -r katalog-export /pfad/zu/Hengstverzeichnis_Framework/plugins/katalog-export
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren und
der gewünschten Gruppe unter `/admin/groups` die Berechtigung
"Katalog-Export → Exportieren" zuweisen.

## Nutzung

1. Über die Dashboard-Kachel "📤 Katalog-Export" zu
   `/plugin/katalog-export/formular` navigieren.
2. Optional Filter setzen (Name, UELN, Geburtsjahr-Bereich, Farbe, Status,
   Deckstation, Vater, Mutter, Züchter, Besitzer) - ohne Filter wird der
   komplette Katalog exportiert.
3. "⬇️ Als CSV herunterladen" klicken.

Die Export-Route `/plugin/katalog-export/csv` akzeptiert dieselben
Query-Parameter wie die öffentliche Katalogseite (`search`, `q_name`,
`q_ueln`, `birth_year_from`, `birth_year_to`, `q_color`, `q_status`,
`q_breeder`, `q_owner`, `q_station`, `q_sire`, `q_dam`) und lässt sich daher
auch direkt mit einer kopierten `/katalog?...`-Query-String aufrufen.

## Berechtigungen

| Modul | Aktion | Beschreibung |
|---|---|---|
| `katalog-export` | `export` | Zugriff auf Formular und CSV-Download |
