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

## Spam-Schutz des Kontaktformulars

Das Formular schickt Name, Adresse und Nachricht eines Dritten an die
Kontakt-E-Mail des Inserats. Die Hürden greifen in dieser Reihenfolge
(dieselbe wie in `kontaktanfrage` und `deckanfrage`):

1. **CSRF-Prüfung.** Ein manipuliertes Feld (Array) führt seit 1.3.0 zu 403
   statt zu HTTP 500.
2. **Honeypot** mit dem Feldnamen des Kerns (`website`,
   `App\Security\Captcha::HONEYPOT_FIELD`, geprüft mit
   `Captcha::honeypotTripped()`). Bis 1.2.0 hieß das Feld `webseite`; der
   alte Name wird noch eine Version lang ausgewertet. Ein Treffer meldet
   scheinbar Erfolg und bucht keinen Zähler.
3. **Zähler je IP**: höchstens 5 Anfragen je Stunde (`login_attempts`, Typ
   `verkaufsboerse`).
4. **Leserecht `horses.view`** der Gast-Gruppe (seit 1.3.0, Audit N4). Ohne
   das Recht zeigen Börse und Pferdeseite 404, ein Direkt-POST versendet dann
   nichts und meldet trotzdem „erfolg“. `contacts.view` spielt keine Rolle:
   Der Empfänger ist die Kontakt-E-Mail des Inserats, kein Kontakt-Datensatz.
5. **Sicherheitsfrage** (seit 1.4.0, Audit N3). Das Formular meldet sich
   als Kontext `verkaufsboerse` im Captcha-Katalog des Kerns an
   („Kontaktanfrage zu einem Verkaufsinserat“). Es gilt der Anbieter aus
   `captcha_provider_verkaufsboerse`, ohne eigenen Eintrag die globale Wahl
   (`captcha_provider`), ohne globale die eingebaute Rechenaufgabe. Die
   Aufgabe liegt in einem eigenen Platz der Sitzung, das Eingabefeld hat die
   ID `captcha-verkaufsboerse`. Das setzt einen Kern nach v0.9.0 voraus
   (Framework-Stand 25940ae, erkannt an `Captcha::MAX_CONTEXTS`). Auf einem
   älteren Kern mit nur einem Platz bleibt das Formular ohne
   Sicherheitsfrage, weil es sonst die Aufgabe der Deckanfrage auf derselben
   Seite überschriebe. Deckanfrage und Verkaufsbörse auf derselben
   Hengstseite bleiben so unabhängig voneinander lösbar; mit `captcha-altcha`
   ab 1.0.2 gilt das auch für dessen Nachweis. Eine falsch gelöste Aufgabe
   führt zu `?verkaufsanfrage=captcha` mit eigenem Hinweis. Ungelöste
   Versuche buchen den Zähler je Inserat nicht. Honeypot und fehlendes
   Leserecht verwerfen nur die Aufgabe dieses Formulars.
6. **Eingabeprüfung** (seit 1.3.0): Name und E-Mail-Adresse höchstens 150
   Zeichen, Nachricht höchstens 5000 Zeichen, gültiges UTF-8, kein
   Zeilenumbruch in Name und Adresse (bei der Adresse vor dem Trimmen
   geprüft). Das Formular setzt dieselben Grenzen als `maxlength`. Ein
   Verstoß führt zu `?verkaufsanfrage=fehler`.
7. **Kein Existenz-Orakel**: Kein aktives Inserat, abgelaufenes Inserat oder
   unveröffentlichtes Pferd melden seit 1.3.0 „erfolg“ statt „fehler“ -
   ohne Versand. Der Status verrät nicht mehr, zu welchem Pferd ein Inserat
   läuft.
8. **Zähler je Inserat** (seit 1.3.0): höchstens 10 Anfragen je Inserat in
   24 Stunden (`login_attempts`, Typ `verkaufsinserat`, Bezeichner
   `inserat:<horse_id>`). Er zählt nur Anfragen, die alle Prüfungen davor
   bestanden haben; darüber meldet das Formular „fehler“. Der IP-Zähler
   allein ließe sich über wechselnde Anschlüsse umgehen.

Die Einschränkung zum fehlenden `Reply-To`-Header von
`App\Service\Mailer::send()` ist beim `deckanfrage`-Addon dokumentiert und
gilt hier identisch. Anders als `deckanfrage` protokolliert die Verkaufsbörse
eingehende Anfragen **nicht** in einer Tabelle - es gibt nur den Mailversand.
Inserate liegen in `plugin_verkaufsboerse_listings`.

## Deinstallation (Framework #338)

`uninstall()` entfernt seit 1.3.0 die Zähler des Kontaktformulars aus der
Kern-Tabelle `login_attempts` (Typen `verkaufsboerse` und
`verkaufsinserat`; der IP-Zähler enthält IP-Adressen), seit 1.4.0 auch die
Anbieterwahl der Sicherheitsfrage (`captcha_provider_verkaufsboerse`). Die
Inserate bleiben stehen: Die `plugin.json` deklariert kein `owns`.

Schema-Anlage: über den `install()`-Hook des PluginManagers (einmal bei
Aktivierung bzw. nach einem Addon-Update); auf älteren Kernen ohne diesen
Hook greift ein marker-geführter Fallback (`.schema-1` im
Plugin-Verzeichnis), damit nicht bei jedem Request ein DDL-Statement läuft.

## Berechtigungen

| Modul | Aktion | Beschreibung |
|---|---|---|
| `verkaufsboerse` | `manage` | Inserate anlegen/aktualisieren/entfernen |
