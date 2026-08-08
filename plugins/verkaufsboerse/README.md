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
  (Preis - ein leeres Preisfeld wird automatisch zu "auf Anfrage" -,
  Beschreibung, Kontakt-E-Mail, optionales Ablaufdatum) oder entfernen. Ein
  Pferd hat höchstens ein aktives Inserat - erneutes Speichern aktualisiert
  es. Zur Auswahl stehen alle nicht gelöschten Pferde, auch
  unveröffentlichte.
- **Öffentliche Übersicht** (`/plugin/verkaufsboerse/liste`): listet aktive
  Inserate **veröffentlichter** Pferde (und nur, wenn die Gast-Gruppe
  `horses.view` hat), verlinkt jeweils auf die normale Pferde-Detailseite.
  Ein Inserat zu einem unveröffentlichten Pferd lässt sich anlegen, erscheint
  aber erst mit dessen Veröffentlichung - der häufigste Grund für "Inserat
  angelegt, taucht nicht auf".
- **Pferde-Detailseite**: zeigt bei einem aktiven Inserat automatisch ein
  "🏷️ Zum Verkauf"-Badge mit Preis, Beschreibung und Kontaktformular (via
  `horse.detail_sections`). Ziel des Formulars ist die POST-Route
  `/plugin/verkaufsboerse/kontakt`.

Schutz gegen Missbrauch des Kontaktformulars: CSRF-Prüfung, unsichtbares
Honeypot-Feld sowie IP-basiertes Rate-Limiting (max. 5 Anfragen/Stunde,
eigener `type`-Wert), analog zum `deckanfrage`-Addon - dort auch die
Einschränkung zum fehlenden `Reply-To`-Header von
`App\Service\Mailer::send()` dokumentiert (gilt hier identisch). Anders als
`deckanfrage` protokolliert die Verkaufsbörse eingehende Anfragen **nicht**
in einer Tabelle - es gibt nur den Mailversand. Inserate liegen in
`plugin_verkaufsboerse_listings`.

## Berechtigungen

| Modul | Aktion | Beschreibung |
|---|---|---|
| `verkaufsboerse` | `manage` | Inserate anlegen/aktualisieren/entfernen |
