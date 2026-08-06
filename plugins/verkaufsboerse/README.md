# verkaufsboerse

Markiert Pferde als "zum Verkauf"/"zur Vermittlung" - bewusst unabhängig von
`horses.status`, ein Pferd kann gleichzeitig `active` (gekört, im normalen
Katalog sichtbar) und zusätzlich gelistet sein. Eigene öffentliche
Übersichtsseite sowie ein Kontaktformular direkt auf der Detailseite des
jeweiligen Pferdes.

Löst [Celestial0579/Hengstverzeichnis_Addons#13](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/13).

## Installation

```bash
cp -r verkaufsboerse /pfad/zu/Hengstverzeichnis_Framework/plugins/verkaufsboerse
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren und
der gewünschten Gruppe unter `/admin/groups` die Berechtigung
"Verkaufsbörse → Verwalten" zuweisen. Für den tatsächlichen Versand der
Kontaktanfragen muss unter **Admin → E-Mail & SMTP Einstellungen** ein
funktionierender Mailversand konfiguriert sein.

## Funktionsweise

- **Admin** (`/plugin/verkaufsboerse/verwaltung`): Inserat je Pferd anlegen
  (Preis oder "auf Anfrage", Beschreibung, Kontakt-E-Mail, optionales
  Ablaufdatum) oder entfernen. Ein Pferd hat höchstens ein aktives Inserat -
  erneutes Speichern aktualisiert es.
- **Öffentliche Übersicht** (`/plugin/verkaufsboerse/liste`): listet alle
  aktiven Inserate, verlinkt jeweils auf die normale Pferde-Detailseite.
- **Pferde-Detailseite**: zeigt bei einem aktiven Inserat automatisch ein
  "🏷️ Zum Verkauf"-Badge mit Preis, Beschreibung und Kontaktformular (via
  `horse.detail_sections`).

Schutz gegen Missbrauch des Kontaktformulars: unsichtbares Honeypot-Feld
sowie IP-basiertes Rate-Limiting (max. 5 Anfragen/Stunde), analog zum
`deckanfrage`-Addon - dort auch die Einschränkung zum fehlenden
`Reply-To`-Header von `App\Service\Mailer::send()` dokumentiert (gilt hier
identisch).

## Berechtigungen

| Modul | Aktion | Beschreibung |
|---|---|---|
| `verkaufsboerse` | `manage` | Inserate anlegen/aktualisieren/entfernen |
