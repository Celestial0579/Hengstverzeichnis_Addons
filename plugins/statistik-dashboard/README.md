# statistik-dashboard

Eine Statistik-Seite für den Admin-Bereich mit zwei Abschnitten:

- **Bestand** — was in der Datenbank steht: Pferde nach Status, Verteilung
  nach Deckstation, Wachstum über Zeit, meistgenutzte Blutlinien.
- **Aufrufe** — was Besucher tatsächlich ansehen: Rangliste der
  meistgesehenen Pferde, dazu ein Zähler auf der öffentlichen Detailseite.

Löst [Celestial0579/Hengstverzeichnis_Addons#5](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/5)
und [#127](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/127).

Dieses Addon ist zugleich das **Beispiel-Addon dieses Repos** — eine Rolle,
die es mit #127 ausdrücklich von `besucherstatistik` übernommen hat. Es führt
alle Erweiterungspunkte des Plugin-Systems vor (siehe
[docs/plugin-development.md](https://github.com/Celestial0579/Hengstverzeichnis_Framework/blob/main/docs/plugin-development.md)
im Framework-Repo):

- **Filter**: `horse.detail_sections` (Zähler auf der Detailseite),
  `admin.dashboard_tiles` (Kachel im Admin-Dashboard)
- **Action**: `horse.after_save` (legt für ein neues Pferd sofort eine
  Zähler-Zeile an)
- **Eigene Tabellen**: `plugin_statistik_dashboard_views` (Zähler),
  `plugin_statistik_dashboard_meta` (Marker, siehe unten), angelegt in
  `install()` statt in `register()`
- **Eigene Route**: `/plugin/statistik-dashboard/statistik`, geschützt über
  eine von `BaseController` erbende Klasse
- **Eigenes Berechtigungsmodul**: `statistik-dashboard` / `view`

## Zusammenführung mit `besucherstatistik` (#127)

Bis v0.7 waren das zwei Addons, die dieselbe Frage beantworteten und sogar
unter demselben Pfadstück `/plugin/<slug>/statistik` lagen. Seit #127 ist es
eines: eine Seite, eine Kachel, eine Berechtigung, eine README.

**Der Slug `statistik-dashboard` bleibt.** Ein neuer Slug (etwa `statistik`)
wäre für jede Bestandsinstallation ein Umzug gewesen — neues Verzeichnis,
neuer Store-Eintrag, neue Aktivierung, neu zu vergebende Rechte, und das für
*beide* alten Addons. So bleibt eine der beiden Installationen vollständig
unangetastet; nur `besucherstatistik` muss weichen. Die Seite heißt in der
Oberfläche schlicht „Statistik".

### Was beim Update passiert

`install()` läuft bei der Aktivierung und nach jedem Addon-Update
(Framework#75) und übernimmt dabei **einmalig**:

1. **Die Rechtezuweisungen.** Jede Gruppe, die `besucherstatistik.view`
   hatte, bekommt `statistik-dashboard.view` dazu. Ohne das sähe nach dem
   Update niemand mehr etwas — und zwar ohne dass eine Meldung erschiene.
2. **Die Aufrufzahlen.** Die Zeilen aus `plugin_besucherstatistik_views`
   wandern nach `plugin_statistik_dashboard_views`. Aufrufzahlen lassen sich
   nicht rekonstruieren; sie werden übernommen, nicht neu angelegt.

**Beides ist durch einen Marker geschützt** (`uebernahme.besucherstatistik` in
`plugin_statistik_dashboard_meta`). Der Grund ist nicht Kosmetik:

- Die Zahlen werden **addiert**, weil ein Pferd in beiden Zählern stehen kann.
  Ohne Marker verdoppelten sie sich bei jedem Deaktivieren/Aktivieren —
  lautlos und nicht mehr auseinanderzurechnen.
- Ein zweiter Lauf würde ein Recht wiederherstellen, das ein Admin
  zwischenzeitlich bewusst entzogen hat. Ein Rechteentzug, den eine
  Reaktivierung rückgängig macht, ist eine Sicherheitslücke.

Der Marker wird in derselben Transaktion geschrieben wie die
Zählerübernahme: Bricht der Lauf ab, gilt nichts davon, und der nächste
findet einen sauberen Ausgangszustand vor.

### Die alte Tabelle bleibt liegen

`plugin_besucherstatistik_views` wird **nicht** gelöscht. Sie gehört einem
fremden Addon; sie zu löschen wäre nicht Sache dieses Addons und nähme die
einzige Rückfallebene, falls an der Übernahme etwas nicht stimmt. Der
geordnete Weg für Daten deinstallierter Addons entsteht in
[Framework#338](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/338).

### Zu tun nach dem Update

`besucherstatistik` unter **Admin → Plugins verwalten** deaktivieren und sein
Verzeichnis aus `plugins/` entfernen. Solange es aktiv bleibt, zählt es
parallel in seine eigene Tabelle und hängt einen zweiten Zähler an die
öffentliche Detailseite — die Statistik-Seite weist mit einem Hinweis darauf
hin, solange das der Fall ist.

## Zählweise

Gezählt wird direkt im `horse.detail_sections`-Filter: **jeder** Aufruf erhöht
den Zähler, auch der von Bots und Crawlern. Eine Deduplizierung über Sitzung
oder IP gibt es bewusst **nicht** — das wäre eine personenbezogene
Speicherung. Diese Entscheidung stammt aus `besucherstatistik` und bleibt
unverändert; die Zusammenführung ändert die Zählweise nicht.

## Installation

```bash
cp -r statistik-dashboard /pfad/zu/Hengstverzeichnis_Framework/plugins/statistik-dashboard
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren und
der gewünschten Gruppe unter `/admin/groups` die Berechtigung
„Statistik → Statistik einsehen" zuweisen.

## Enthaltene Kennzahlen (`/plugin/statistik-dashboard/statistik`)

### Bestand

- Anzahl aktiver/inaktiver Pferde nach Zuchtstatus; die Kachel
  "Pferde gesamt" summiert genau diese beiden Status. "Verstorben" zählt
  seit dem Status-Split des Frameworks (#188) quer dazu (`is_deceased`) -
  ein verstorbenes Tier steckt also zusätzlich in einer der beiden
  Zuchtstatus-Kacheln
- Verteilung nach Deckstation (Top 15). Seit Addons#139 gegen die
  zusammengeführte Kontaktliste: `horses.breeding_station_id` heißt weiter so,
  zeigt seit Framework#336 aber auf `contacts` — die Tabelle
  `breeding_stations` gibt es nicht mehr. Gezählt wird die **aktuelle**
  Deckstation je Pferd (den Freitext-Spiegel `horses.breeding_station`, wo kein
  Kontakt verknüpft ist, sonst „Unbekannt"), ausdrücklich **nicht** zusätzlich
  `horse_persons.station_contact_id`: Dort stehen auch historische Stationen,
  und ein Pferd, das bei jeder je genutzten Station mitzählte, summierte die
  Verteilung über den Bestand hinaus
- Wachstum der Datenbank über Zeit (neu angelegte Pferde je Jahr, basierend
  auf `created_at`)
- Top-Blutlinien: meistgenutzte Väter und Mütter (Top 10 je Elternteil,
  berücksichtigt sowohl verknüpfte Pferde über `sire_id`/`dam_id` als auch
  unverknüpfte Namenseinträge über `sire_name`/`dam_name`)

### Aufrufe

- Rangliste der 50 meistaufgerufenen Pferde-Profile mit Geburtsjahr und
  Aufrufzahl
- Auf der öffentlichen Detailseite: „👁 Dieses Profil wurde N mal aufgerufen."

Alle Kennzahlen beziehen sich auf den Gesamtbestand (nur gelöschte
Datensätze ausgenommen), also **einschließlich unveröffentlichter** Pferde
und Stationen - als berechtigungsgeschützte Verwaltungssicht gewollt. Die
Zahlen weichen deshalb von der öffentlichen Katalogsicht ab.

## Berechtigungen

| Modul | Aktion | Beschreibung |
|---|---|---|
| `statistik-dashboard` | `view` | Zugriff auf die Statistik-Seite (Bestand und Aufrufe) |

## Hinweis

Bei jeder inhaltlichen Änderung am Code muss `version` in `plugin.json`
erhöht werden, sonst erkennt der Kern die Änderung als verdächtig und lädt
das Plugin nicht mehr (siehe Abschnitt "Update-Erkennung" in der
Framework-Dokumentation).
