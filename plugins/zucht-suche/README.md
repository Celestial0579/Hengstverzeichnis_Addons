# zucht-suche

Öffentliche Einstiegsseite **Zucht** (`/plugin/zucht-suche`), unter der sich
**Züchter** und **Deckstationen** suchen lassen — nach Name, Ort,
Bundesland/Kanton und Land, bei Züchtern zusätzlich nach Mitgliedsstatus.

Bis dahin führte der Weg zu einer Person oder einer Station immer über ein
Pferd: Wer wissen wollte, welche Züchter es in einer Region gibt, hatte keinen
Einstieg, obwohl die Daten seit Kern-#293 da sind.

Löst [Celestial0579/Hengstverzeichnis_Addons#105](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/105).

## Installation

```bash
cp -r zucht-suche /pfad/zu/Hengstverzeichnis_Framework/plugins/zucht-suche
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren. Eine
eigene Berechtigung ist **nicht** zuzuweisen — siehe „Berechtigungen".

Setzt Kern **0.7.0** voraus (`persons.is_breeder`, die öffentliche Seite
`/person?id=` und die Hooks `person.detail_sections`/`station.detail_sections`
kamen mit 0.6.0/0.7.0 dazu).

## Funktionsweise

- **Reiter „Züchter"**: `persons WHERE is_breeder = 1 AND is_published = 1 AND
  deleted_at IS NULL`. Das Kennzeichen ist redaktionell gepflegt („züchtet
  heute") und ausdrücklich **nicht** aus `horse_persons.role = 'breeder'`
  abgeleitet — wer noch kein Pferd im Verzeichnis hat, wäre sonst nicht
  auffindbar, und wer früher gezüchtet hat, bliebe dauerhaft markiert.
- **Reiter „Deckstationen"**: `breeding_stations WHERE is_published = 1 AND
  deleted_at IS NULL`.
- **Filter**: Name und Ort als Teilstringsuche, Bundesland/Kanton, Land und
  Mitgliedsstatus als Auswahllisten aus dem tatsächlichen Bestand des jeweils
  gewählten Reiters. Alle Werte gehen als **gebundene Parameter** in die
  Abfrage; `%` und `_` im Suchtext werden maskiert, damit sie als Zeichen
  gesucht und nicht als LIKE-Platzhalter ausgewertet werden.
- **Treffer** verlinken auf `/person?id=` bzw. `/station?id=`; 50 je Seite
  (`?seite=…`). Reiter- und Blätter-Links tragen die eingestellten Filter mit.
- **Spalte „Pferde"**: Zahl der zugeordneten, **veröffentlichten** Pferde. Bei
  Züchtern zählt jedes Pferd genau einmal, auch wenn die Person ihm mit
  mehreren Rollen zugeordnet ist (Züchter *und* Besitzer ist der Normalfall) —
  die nach Rollen gruppierte Liste auf `/person?id=` kann deshalb mehr Zeilen
  haben als diese Zahl. Eine Abfrage je Seite, nicht je Zeile.

Das Addon legt **keine eigenen Tabellen** an und speichert nichts; es liest
ausschließlich vorhandene Kern-Daten. Einen `install()`-Hook braucht es daher
nicht.

## Fail-closed: was ohne Berechtigung passiert

Die Sichtbarkeit ist dieselbe wie im Kern (`PublicController::personDetail()` /
`stationDetail()`), nicht eine zweite, eigene Regel:

| Gast-Gruppe hat | Wirkung |
|---|---|
| `persons.view` | Reiter „Züchter" erscheint |
| `breeding_stations.view` | Reiter „Deckstationen" erscheint |
| `horses.view` | Spalte „Pferde" erscheint |
| keines von persons/breeding_stations | Seite antwortet mit **404** |

Fehlt eine Leseberechtigung, wird die Gattung **nicht** angezeigt — auch nicht
leer. Eine leere Liste wäre die Aussage „es gibt keine", und die stimmt dann
nicht. `?art=stationen` in der Adresszeile hilft ebenfalls nicht: Die
Reiter-Wahl wird gegen die erlaubten Reiter geprüft, nicht übernommen.

## Datenschutz

Ausgegeben werden nur die öffentlichen Felder: Ort, Bundesland/Kanton, Land und
(bei Züchtern) Mitgliedsstatus. **E-Mail, Telefon und Mobil werden nicht
einmal abgefragt** — die Spaltenliste im Code nennt sie nicht, aus demselben
Grund, aus dem der Kern dort `SELECT *` vermeidet: Was gar nicht erst ankommt,
kann auch der nächste nicht versehentlich ausgeben.

`contact_public` wertet das Addon deshalb bewusst **nicht** aus. Eine
Trefferliste braucht keine Kontaktdaten; ihr Zweck ist der Klick auf die
Detailseite, und dort entscheidet der Kern. Auch die Website bleibt der
Detailseite vorbehalten.

Die Trennlinie folgt dem Kern: Was eine Sendung zustellbar macht, bleibt intern;
die grobe geografische Verortung ist öffentlich. Bei Deckstationen ist die
Anschrift eine Geschäftsadresse und vollständig öffentlich — dort steht deshalb
die PLZ mit in der Ortsspalte, bei Personen nicht.

Sobald es ein Kontaktanfrage-Addon gibt, gehört der Trefferliste ein „Kontakt
aufnehmen"-Knopf beigegeben, statt Adressen zu zeigen (siehe Issue #105).

## Menüpunkt „Zucht"

Der Kern 0.7.0 hat dafür den Filter **`layout.nav_items`**. Das Addon hängt
sich dort ein und erscheint als „🧬 Zucht" in der öffentlichen Navigation,
hinter „Verzeichnis" und vor dem Anmelde-Knopf — auf jeder öffentlichen Seite,
und auf der eigenen Seite als aktiver Eintrag markiert.

Der Menüpunkt entfällt, wenn Gäste weder Personen noch Deckstationen sehen
dürfen; er führte sonst in eine 404.

Zusätzlich ist die Seite dort erreichbar, wo Besucher heute überhaupt erst auf
Züchter und Stationen stoßen:

- **Admin-Dashboard**: Kachel „🧬 Zucht-Suche" (`admin.dashboard_tiles`).
- **Öffentliche Detailseiten** von Pferd, Person und Deckstation: eine
  einzeilige Verweiszeile (`horse.detail_sections`, `person.detail_sections`,
  `station.detail_sections`), unter derselben Sichtbarkeitsbedingung.

## Berechtigungen

Keine. Das Addon registriert bewusst **kein eigenes Modul** und keine eigene
Aktion: Es zeigt ausschließlich Daten, die der Kern über `persons.view`,
`breeding_stations.view` und `horses.view` ohnehin öffentlich macht. Ein
zusätzlicher Schalter wäre ein zweites Tor vor derselben Tür — er würde die
Sichtbarkeit nicht enger machen, sondern nur die Frage aufwerfen, welcher der
beiden gilt.

## Formular und CSRF

Das Suchformular ist ein **GET**-Formular ohne CSRF-Token — wie der
Katalogfilter des Kerns (`src/Views/public_catalog.php`). Das Addon hat
überhaupt keinen schreibenden Endpunkt: `routes()` deklariert eine einzige
GET-Route. Ein Token in der Adresszeile schützte hier nichts, machte eine Suche
aber unverlinkbar und landete in Server-Protokollen und Referrern.

## Tests

`tests/Unit/ZuchtSucheSuchanfrageTest.php` nagelt die Eingabeprüfung fest
(`Plugin\ZuchtSuche\Suchanfrage`) — die einzige Stelle, an der Werte aus der
Adresszeile in das Addon gelangen: Reiter-Whitelist, `?name[]=x` statt eines
Strings, Längendeckel ohne zerschnittene UTF-8-Zeichen, „3x" als Seitennummer
und die LIKE-Maskierung. Das läuft ohne Datenbank in Millisekunden.

Der Lebenszyklus-Test (aktivieren, Seite aufrufen, 404 ohne Berechtigung,
Verlinkung auf `/person` und `/station`) gehört nach `tests/Functional/` und
braucht eine Datenbank sowie einen Kern, der `CORE_VERSION 0.7.0` ausweist —
er ist noch nachzuziehen.
