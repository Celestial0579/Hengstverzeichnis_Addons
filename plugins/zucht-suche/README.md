# zucht-suche

Öffentliche Einstiegsseite **Zucht** (`/plugin/zucht-suche`), unter der sich
die **Kontakte** des Verzeichnisses suchen lassen — nach Rolle, Name, Ort,
Bundesland/Kanton und Land.

Bis dahin führte der Weg zu einem Kontakt immer über ein Pferd: Wer wissen
wollte, welche Züchter es in einer Region gibt, hatte keinen Einstieg, obwohl
die Daten seit Kern-#293 da sind.

Löst [Celestial0579/Hengstverzeichnis_Addons#105](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/105);
auf die zusammengeführte Kontaktliste umgestellt mit
[#122](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/122).

## Installation

```bash
cp -r zucht-suche /pfad/zu/Hengstverzeichnis_Framework/plugins/zucht-suche
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren. Eine
eigene Berechtigung ist **nicht** zuzuweisen — siehe „Berechtigungen".

Setzt Kern **0.8** voraus: Die Suche liest `contacts`, das Rechte-Modul heißt
`contacts`, das Ziel eines Treffers ist `/kontakt?id=`, und der
Erweiterungspunkt auf der Detailseite ist `contact.detail_sections`. Auf einem
0.7-Kern gibt es nichts davon.

## Von zwei Reitern zu einem Rollenfilter (#122)

Bis 0.7 hatte die Seite zwei feste Reiter, „Züchter" und „Deckstationen" — sie
waren zwei Tabellen, `persons` und `breeding_stations`. Seit Framework#336 ist
beides **derselbe Datensatz**: Ein Hof kann gleichzeitig züchten, Pferde
besitzen und Deckstation sein. Ein Reiter müsste sich für eine der Aussagen
entscheiden und die anderen unsichtbar machen.

An die Stelle der Gattung tritt deshalb ein **Filter „Rolle"** über einer
einzigen Trefferliste. Die Rolle ist keine Eigenschaft des Datensatzes mehr,
sondern eine Frage an das, was um ihn herum steht:

| Rolle | woraus sie folgt |
|---|---|
| *(alle)* | keine Bedingung — jeder veröffentlichte Kontakt |
| **Züchter** | `contacts.is_breeder` |
| **Deckstation** | ein veröffentlichtes Pferd nennt den Kontakt als Deckstation |
| **Besitzer** | `horse_persons.role = 'owner'` an einem veröffentlichten Pferd |
| **Halter** | `horse_persons.role = 'keeper'` an einem veröffentlichten Pferd |

Zwei Punkte daran sind bewusst so und keine Vereinfachung:

- **„Züchter" bleibt das redaktionelle Kennzeichen**, nicht
  `horse_persons.role = 'breeder'`. Der Kern begründet das in
  `database/schema.sql`: Wer noch kein Pferd im Verzeichnis hat, wäre sonst
  nicht auffindbar, obwohl er züchtet; und wer früher gezüchtet hat, bliebe
  dauerhaft markiert, denn die alten Zuordnungen sind Historie und
  verschwinden nicht. Das Kennzeichen sagt „züchtet heute", die Zuordnungen
  sagen „hat dieses Pferd gezüchtet".
- **„Deckstation" bekommt kein eigenes Kennzeichen.** Eines zu erfinden hieße,
  die gerade abgeschaffte Gattung wieder in die Daten zu schreiben — und es
  wäre sofort veraltet, sobald das letzte Pferd die Station verlässt. Geprüft
  werden beide Wege, auf denen ein Pferd eine Station nennt (wie in
  `PublicController::contactDetail()`): `horses.breeding_station_id` (die
  aktuelle Station) und `horse_persons.station_contact_id` (auch historische
  Zuordnungszeilen). Nur den ersten zu prüfen ließe jede Station
  verschwinden, bei der ein Pferd früher stand.

Die drei abgeleiteten Rollen zählen ausschließlich **veröffentlichte, nicht
gelöschte** Pferde und stehen nur mit `horses.view` zur Verfügung — siehe
„Fail-closed".

## Funktionsweise

- **Trefferliste**: `contacts WHERE is_published = 1 AND deleted_at IS NULL`,
  dazu die Bedingung des Rollenfilters. 50 Treffer je Seite (`?seite=…`); die
  Blätter-Links tragen die eingestellten Filter mit.
- **Filter**: Name und Ort als Teilstringsuche, Rolle, Bundesland/Kanton und
  Land als Auswahllisten. Alle Werte gehen als **gebundene
  Parameter** in die Abfrage; `%` und `_` im Suchtext werden maskiert, damit
  sie als Zeichen gesucht und nicht als LIKE-Platzhalter ausgewertet werden.
  Die Auswahllisten zeigen den Bestand der ganzen Kontaktliste, unabhängig vom
  Rollenfilter — eine je Rolle gefilterte Auswahl zöge die abgeleiteten Rollen
  in jede Liste hinein, für einen Gewinn, den die Trefferliste ohnehin sofort
  zeigt.
- **Den Filter „Mitgliedsstatus" gibt es seit v0.9.0 nicht mehr.** Er ist mit
  Framework#349 ersatzlos entfallen, zusammen mit dem Feld im Kern: Freitext
  ohne Vokabular, bedingungslos öffentlich — und „X ist kein Mitglied" ist eine
  Aussage über einen Menschen. Die Angabe führt jetzt das Addon
  `mitgliedsstatus` mit fester Werteliste und Freigabe **je Kontakt**
  (Addons#132); eine Trefferliste, die ungefragt danach filtert und die Werte
  in einer Spalte ausgibt, würde genau diese Freigabe umgehen. Ein altes
  Lesezeichen mit `?mitglied=…` schadet nicht: Der Parameter wird nicht mehr
  gelesen, die Suche liefert die Treffer ohne diese Einschränkung.
- **Treffer** verlinken auf `/kontakt?id=`. `/person?id=` und `/station?id=`
  bestehen nur noch als dauerhafte Weiterleitung für Altbestände (aufgelöst
  über `contact_id_map`) — die Trefferliste kennt die neue Kennung und nimmt
  den Umweg nicht.
- **Zwei Pferdespalten**, nicht eine: „Pferde" zählt die veröffentlichten
  Pferde, denen der Kontakt als Züchter, Besitzer oder Halter zugeordnet ist
  (`horse_persons.contact_id`), „Als Deckstation" die, die ihn als Deckstation
  nennen. Sie zu addieren wäre falsch — „hat dieses Pferd gezüchtet" und
  „dieses Pferd stand hier" sind verschiedene Aussagen über dasselbe Pferd, die
  Summe zählte es doppelt. Der Kern zeigt beide ebenso getrennt (auf
  `/kontakt` als zwei Blöcke, in der Verwaltungsliste als zwei Spalten).
  Innerhalb einer Spalte zählt jedes Pferd genau einmal, auch bei mehreren
  Zuordnungszeilen (Züchter *und* Besitzer ist der Normalfall) — die nach
  Rollen gruppierte Liste auf `/kontakt?id=` kann deshalb mehr Zeilen haben als
  diese Zahl. Je Spalte eine Abfrage für die ganze Seite, nicht eine je Zeile.

Das Addon legt **keine eigenen Tabellen** an und speichert nichts; es liest
ausschließlich vorhandene Kern-Daten. Einen `install()`-Hook braucht es daher
nicht.

## Fail-closed: was ohne Berechtigung passiert

Die Sichtbarkeit ist dieselbe wie im Kern (`PublicController::contactDetail()`),
nicht eine zweite, eigene Regel. Vor #336 waren dafür zwei Module zu prüfen,
`persons.view` und `breeding_stations.view`; sie sind mit den Tabellen zu
`contacts` zusammengefallen.

| Gast-Gruppe hat | Wirkung |
|---|---|
| `contacts.view` | Die Seite antwortet, Menüpunkt und Verweise erscheinen |
| `horses.view` | Die Rollen Deckstation/Besitzer/Halter werden angeboten, die beiden Pferdespalten erscheinen |
| kein `contacts.view` | Seite antwortet mit **404**, Menüpunkt und Verweise entfallen |

Eine leere Liste wäre die Aussage „es gibt keine", und die stimmt dann nicht —
deshalb 404 statt einer leeren Seite. `?rolle=station` in der Adresszeile hilft
ohne `horses.view` ebenfalls nicht: Der Wert wird gegen die erlaubten Rollen
geprüft und fällt auf „(alle)" zurück, statt übernommen zu werden. Andernfalls
wäre der Rollenfilter ein Orakel darüber, welche Kontakte Pferde haben, obwohl
die Pferde selbst unsichtbar sind.

## Datenschutz

Seit die Trennung `persons`/`breeding_stations` weggefallen ist, ist
`contact_public` der **einzige** Schutz je Datensatz. Die Trefferliste hält
sich deshalb an die strengere der beiden alten Regeln (siehe
`docs/kontaktliste-umstellung.md` im Kern) und fragt ausschließlich die
immer-öffentlichen Spalten ab: `id`, `name`, `city`, `state`, `country`,
`is_breeder`.

**E-Mail, Telefon, Mobil, Straße, Hausnummer, Anschrift, Ansprechpartner und
`contact_info` werden nicht einmal abgefragt** — kein `SELECT *`, aus demselben
Grund, aus dem der Kern es im öffentlichen Bereich ausdrücklich verbietet: Was
gar nicht erst ankommt, kann auch der nächste nicht versehentlich ausgeben (die
Lehre aus Kern-#293).

**Auch die Postleitzahl nicht.** Bis 0.7 stand sie bei Deckstationen in der
Ortsspalte, weil eine Station eine Geschäftsadresse war und keine
Privatanschrift. Diese Begründung ist mit der Gattung weggefallen:
`postal_code` gehört seit #336 zu den Feldern, die nur bei `contact_public = 1`
öffentlich sind. Sie weiter auszugeben, wäre genau die Sichtbarkeitserhöhung,
die #336 ausschließt.

`contact_public` wertet das Addon deshalb gar nicht erst aus — eine
Trefferliste braucht keine Kontaktdaten; ihr Zweck ist der Klick auf die
Detailseite, und dort entscheidet der Kern. Auch die Website bleibt der
Detailseite vorbehalten.

## Menüpunkt „Zucht"

Der Kern 0.7.0 hat dafür den Filter **`layout.nav_items`**. Das Addon hängt
sich dort ein und erscheint als „🧬 Zucht" in der öffentlichen Navigation,
hinter „Verzeichnis" und vor dem Anmelde-Knopf — auf jeder öffentlichen Seite,
und auf der eigenen Seite als aktiver Eintrag markiert.

Der Menüpunkt entfällt ohne `contacts.view`; er führte sonst in eine 404.

Zusätzlich ist die Seite dort erreichbar, wo Besucher heute überhaupt erst auf
Züchter und Stationen stoßen:

- **Öffentliche Detailseiten** von Pferd und Kontakt: eine einzeilige
  Verweiszeile (`horse.detail_sections`, `contact.detail_sections`), unter
  derselben Sichtbarkeitsbedingung.

Registriert wird ausdrücklich **nur** `contact.detail_sections`, nicht
zusätzlich die alten Namen `person.detail_sections` und
`station.detail_sections`. Der Kern feuert die beiden seit 0.8 als Alias
hinterher, kaskadierend auf demselben Ergebnis — wer wie dieses Addon bis 0.7
beide Paare registriert hatte, bekam seinen Abschnitt seither **zweimal** auf
derselben Seite, denn es gibt nur noch eine Detailseite. Die Aliasse entfallen
in 0.9.0 ohnehin.

Eine **Dashboard-Kachel gibt es bewusst nicht** (#115). Der Menüpunkt wird im
Kern für jede View aufgebaut, steht also auch im Adminbereich in der
Kopfnavigation — Kachel und Menüpunkt standen dort nebeneinander und zeigten
auf dieselbe Adresse. Die Kachel war dabei die schlechtere von beiden: Ihr
fehlte die Sichtbarkeitsprüfung des Menüpunkts, sie erschien also auch dann,
wenn die Seite dem Betrachter mit 404 antwortet.

## Berechtigungen

Keine. Das Addon registriert bewusst **kein eigenes Modul** und keine eigene
Aktion: Es zeigt ausschließlich Daten, die der Kern über `contacts.view` und
`horses.view` ohnehin öffentlich macht. Ein zusätzlicher Schalter wäre ein
zweites Tor vor derselben Tür — er würde die Sichtbarkeit nicht enger machen,
sondern nur die Frage aufwerfen, welcher der beiden gilt.

## Formular und CSRF

Das Suchformular ist ein **GET**-Formular ohne CSRF-Token — wie der
Katalogfilter des Kerns (`src/Views/public_catalog.php`). Das Addon hat
überhaupt keinen schreibenden Endpunkt: `routes()` deklariert eine einzige
GET-Route. Ein Token in der Adresszeile schützte hier nichts, machte eine Suche
aber unverlinkbar und landete in Server-Protokollen und Referrern.

## Tests

`tests/Unit/ZuchtSucheSuchanfrageTest.php` nagelt die Eingabeprüfung fest
(`Plugin\ZuchtSuche\Suchanfrage`) — die einzige Stelle, an der Werte aus der
Adresszeile in das Addon gelangen: die Rollen-Whitelist samt Rückfall auf
„(alle)", die Pferderecht-Schranke vor den abgeleiteten Rollen, `?name[]=x`
statt eines Strings, Längendeckel ohne zerschnittene UTF-8-Zeichen, „3x" als
Seitennummer und die LIKE-Maskierung. Das läuft ohne Datenbank in
Millisekunden.

`tests/Functional/ZuchtSuchePluginTest.php` spielt den Lebenszyklus gegen eine
echte Instanz durch: aktivieren, Rollenfilter „Züchter" und „Deckstation",
Ortsfilter, Verlinkung auf `/kontakt?id=`, der Verweis auf der
Kontaktseite **genau einmal** (die Doppelregistrierung aus #122), keine
Kontaktdaten und keine PLZ in der Trefferliste, 404 ohne `contacts.view`,
Rückfall auf „(alle)" ohne `horses.view`, und das Verschwinden von Route und
Menüpunkt beim Abschalten.
