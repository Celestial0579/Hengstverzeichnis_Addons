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

- **Admin: im Datensatz des Pferdes** (`/admin/horses/edit?id=…`, Kern-Hook
  `horse.edit_sections`): Der Abschnitt „🏷️ Verkaufsanzeige" trägt alle Felder
  des Inserats - Preis (ein leeres Preisfeld wird automatisch zu „auf
  Anfrage"), Beschreibung, Kontakt-E-Mail, optionales Ablaufdatum - sowie das
  Entfernen einer bestehenden Anzeige. Ein Pferd hat höchstens ein Inserat;
  erneutes Speichern aktualisiert es. Der Abschnitt weist außerdem aus, ob das
  Inserat öffentlich sichtbar ist oder warum nicht (Pferd im Papierkorb,
  unveröffentlicht, Anzeige abgelaufen).

  Seit [#119](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/119)
  ist das der einzige Pflegeweg: Die addoneigene Verwaltungsseite
  (`/plugin/verkaufsboerse/verwaltung`), ihre Dashboard-Kachel und ihre
  Pferdesuche (`/suche`,
  [#125](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/125))
  sind entfallen - sie ließen dasselbe Pferd über eine zweite Suche erneut
  heraussuchen, obwohl man in dessen Datensatz bereits stand. Der Abschnitt
  erscheint nur mit `verkaufsboerse.manage`; die Berechtigung ist damit ein
  Zusatzschalter zu `horses.edit`. Die Ziele der Formulare sind unverändert
  `POST /plugin/verkaufsboerse/verwaltung/store` und `…/delete`; der Rückweg
  führt auf `/admin/horses/edit?id=…`.

  Die bestandsweite Frage „welche Anzeigen laufen gerade" beantwortet die
  öffentliche Börse (siehe unten).
- **Öffentliche Übersicht** (`/plugin/verkaufsboerse/liste`): listet aktive
  Inserate **veröffentlichter** Pferde (und nur, wenn die Gast-Gruppe
  `horses.view` hat), verlinkt jeweils auf die normale Pferde-Detailseite,
  paginiert mit 50 Inseraten je Seite (`?seite=…`).
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

Schema-Anlage: über den `install()`-Hook des PluginManagers (einmal bei
Aktivierung bzw. nach einem Addon-Update); auf älteren Kernen ohne diesen
Hook greift ein marker-geführter Fallback (`.schema-1` im
Plugin-Verzeichnis), damit nicht bei jedem Request ein DDL-Statement läuft.

## Berechtigungen

| Modul | Aktion | Beschreibung |
|---|---|---|
| `verkaufsboerse` | `manage` | Inserate anlegen/aktualisieren/entfernen |
