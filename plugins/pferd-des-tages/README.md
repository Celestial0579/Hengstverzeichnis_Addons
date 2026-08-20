# pferd-des-tages

Hebt auf der **Startseite** täglich ein veröffentlichtes Pferd hervor — mit
einstellbaren Kriterien, nach denen es ausgewählt wird.

Löst [Celestial0579/Hengstverzeichnis_Addons#135](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/135)
und setzt
[Framework#356](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/356)
voraus (`home.sections_top` / `home.sections_bottom`). Bis Kern 0.7 hatte
ausgerechnet die meistbesuchte Seite des Verzeichnisses keinen einzigen
Erweiterungspunkt — dieses Addon wäre nur zu bauen gewesen, indem man
`src/Views/public_home.php` im Kern anfasst.

## Installation

```bash
cp -r pferd-des-tages /pfad/zu/Hengstverzeichnis_Framework/plugins/pferd-des-tages
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren und
der zuständigen Gruppe unter `/admin/groups` die Berechtigung **Pferd des
Tages → Kriterien, Ausschlussliste und Vorgaben pflegen** zuweisen. Die
Verwaltung liegt unter **Dashboard → Pferd des Tages**
(`/plugin/pferd-des-tages/verwaltung`).

Ohne weitere Einstellung läuft das Addon sofort: Grundeinstellung ist „nur
Pferde mit Foto, keine verstorbenen, Schonfrist 30 Tage".

## Die drei tragenden Entscheidungen

**1. „Des Tages" heisst: an einem Tag dasselbe Pferd für alle Besucher.**
Ein `ORDER BY RAND()` je Seitenaufruf ist etwas anderes — es fällt beim ersten
Neuladen auf, macht jede Zwischenspeicherung wertlos und lässt geteilte Links
ins Leere zeigen. Die Wahl wird deshalb je Kalendertag **einmal** getroffen:
deterministisch aus dem Datum abgeleitet (`crc32("pferd-des-tages|JJJJ-MM-TT")
% Anzahl Kandidaten`) und in `plugin_pferd_des_tages_wahl` festgehalten.

Beides zusammen, nicht eines von beidem:

* Die **Ableitung aus dem Datum** kommt ohne Cron aus und macht die Wahl
  wettlauffrei — treffen zwei gleichzeitige erste Aufrufe des Tages dieselbe
  Entscheidung, kann das `INSERT IGNORE` keinen Unterschied mehr verdecken.
* Das **Festhalten** ist die Voraussetzung für die zwei Dinge, die eine reine
  Ableitung nicht kann: keine Wiederholung innerhalb einer Schonfrist, und
  eine redaktionelle Vorgabe für ein bestimmtes Datum.

**2. Die Anzeige kostet eine Abfrage.** Die Startseite ist die meistgeladene
Seite; die Auswertung der Kriterien läuft höchstens einmal je Kalendertag.
Auch „die Kriterien treffen nichts" wird festgehalten (Zeile mit
`horse_id = NULL`) — sonst liefe die teure Suche für den Rest des Tages bei
jedem Aufruf erneut ins Leere.

**3. Fail-closed gegenüber der Katalogseite.** Gezeigt wird nur, was der Kern
auch selbst zeigen würde: nur veröffentlichte, nicht gelöschte Pferde, und nur,
wenn die aktuelle Gruppe `horses.view` besitzt. Geprüft wird bei **jeder**
Anzeige gegen den aktuellen Stand — wird das Pferd des Tages mittags
depubliziert, steht es nicht bis Mitternacht weiter auf der Startseite.

## Einstellbare Kriterien

Grundmenge sind immer nur **veröffentlichte** Pferde. Das ist nicht
verhandelbar und deshalb keine Einstellung. Darüber hinaus:

| Kriterium | Bedeutung |
|---|---|
| Nur Pferde mit Foto | Standard **an** — ein Pferd des Tages ohne Bild verfehlt den Zweck |
| Verstorbene einschliessen | Standard **aus** (`horses.is_deceased`, seit Kern-#188 getrennt vom Zuchtstatus) |
| Zuchtstatus | aktiv / inaktiv / egal |
| Geschlecht | Hengst / Stute / Wallach / egal |
| Farbe, Rasse | Auswahl aus den im Bestand vorkommenden Werten |
| Geburtsjahr von/bis | vertauschte Grenzen werden getauscht, nicht abgewiesen |
| Deckstation, Züchter | Auswahl aus den Kontakten, die tatsächlich vorkommen |
| Schonfrist in Tagen | Standard 30, `0` schaltet sie ab |

### Kür: Kriterien aus anderen Addons

Diese drei erscheinen **nur**, wenn das jeweilige Addon installiert ist —
fehlt es, fällt das Kriterium weg, statt das Pferd des Tages lahmzulegen:

* nur Pferde mit Auszeichnung (`titel-praemierungen`)
* nur Pferde mit aktivem Verkaufsinserat (`verkaufsboerse`)
* mindestens N Seitenaufrufe (`statistik-dashboard`)

Wird ein solches Addon vorübergehend entfernt, bleibt die zugehörige
Einstellung gespeichert; sie wirkt nur nicht.

## Ausschlussliste und Vorgaben

**Dauerhaft ausnehmen** — auf Wunsch des Besitzers, oder weil die Daten
unvollständig sind. Ein ausgenommenes Pferd bleibt im Katalog sichtbar, es
kommt nur nicht mehr an die Reihe. Steht es gerade heute auf der Startseite,
wird die heutige Wahl beim Ausnehmen mit verworfen.

**Redaktionell vorgeben** — für ein bestimmtes Datum ein Pferd fest setzen:
Fohlenschau, Jubiläum, Verbandstermin. Eine Vorgabe schlägt die automatische
Wahl; die Kriterien gelten für sie nicht.

**Heute neu wählen** — verwirft die heutige Zeile, die nächste Anzeige trifft
die Wahl nach den aktuellen Kriterien neu. Das ist der bewusste Ausweg aus der
Lage „das Pferd des Tages wurde mittags depubliziert": Automatisch neu zu
wählen wäre die falsche Antwort, weil die Kandidatensuche dann für den Rest des
Tages bei *jedem* Aufruf der Startseite liefe. Ein Klick hier kostet einmal,
was sonst tausendmal kostete.

## Schonfrist: „keine Wiederholung, solange die Auswahl nicht erschöpft ist"

Der zweite Teil des Satzes ist der wichtige. Waren alle Kandidaten innerhalb
der Schonfrist schon einmal dran, gilt sie für diesen Tag **nicht** — sonst
verschwände der Kasten bei einem kleinen Bestand nach wenigen Tagen von der
Startseite, und der Betreiber suchte den Fehler in seinen Kriterien.

## Sicherheit

* Das Fragment für die Startseite wird vom Kern **unescaped** ausgegeben. Jede
  Angabe aus der Datenbank geht deshalb durch `htmlspecialchars()`; in der
  Adresse steht ausschliesslich eine geprüfte Integer-ID.
* Das Foto kommt ausschliesslich über `App\Helper\MediaUrl::horseImage()`. Ein
  roher `/uploads/`-Pfad umginge den Einbettungsschutz und war der Gegenstand
  des Sicherheitsgutachtens GHSA-xrrq-9j94-fr5g.
* Alle schreibenden Aktionen prüfen CSRF und die Berechtigung
  `pferd-des-tages.manage` und werden protokolliert
  (`App\Plugin\PluginAudit`, Kategorie `pferd-des-tages`) — einschliesslich
  der automatischen Tageswahl, damit „warum stand am 3. dieses Pferd da" ohne
  Raten zu beantworten ist. Personenbezogene Inhalte stehen nicht im
  Protokoll, nur Datensatzbezüge wie `Pferd #42`.
* Die Pferdesuche im Adminbereich ist der gemeinsame Endpunkt des Kerns
  (`/admin/horses/search`, `/js/horse-search.js`, Kern-#341) — keine achte
  eigene Kopie derselben AJAX-Suche.

## Eigene Daten (`owns`, Kern-#338)

| Tabelle | Inhalt |
|---|---|
| `plugin_pferd_des_tages_wahl` | die Wahl je Kalendertag (`fest = 1` bei redaktionellen Vorgaben) |
| `plugin_pferd_des_tages_ausschluss` | dauerhaft ausgenommene Pferde |
| `plugin_pferd_des_tages_config` | die eingestellten Kriterien als ein JSON-Wert |

Eigene Konfigurationstabelle statt Zeilen in der Kern-Tabelle `settings` —
dieselbe Begründung wie bei `plugin_kontaktanfrage_config`: deren
Schlüsselspalte ist `VARCHAR(50)`, und die Systemeinstellungen sind eine
redaktionell gepflegte Oberfläche, in die ein Addon keine Fremdschlüssel
streuen sollte. `owns.settings` ist deshalb leer.

Ein `uninstall()` gibt es nicht: In Kern-Tabellen bleibt nichts liegen, was
sich nicht aufzählen liesse. Die Protokolleinträge unter der Kategorie
`pferd-des-tages` werden bewusst **nicht** gelöscht — ein Nachweis, den das
Deinstallieren mitnimmt, ist keiner.
