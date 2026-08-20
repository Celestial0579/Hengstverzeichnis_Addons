# beispiel-erweiterungspunkte

> **Lehrbeispiel. Nicht für den Produktivbetrieb.**
> Dieses Addon tut fachlich nichts Nützliches. Es belegt jeden
> Erweiterungspunkt des Kerns mit einem sichtbaren Ergebnis, damit man
> nachlesen *und nachsehen* kann, wie ein Hook sich verhält. Auf einer echten
> Instanz aktiviert, hängt es an jeder öffentlichen Seite einen
> Beispielabschnitt an.

Löst [Celestial0579/Hengstverzeichnis_Addons#128](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/128).

## Warum es das gibt

Das Referenz-Addon im Framework-Repo (`docs/examples/demo-plugin/`) zeigt drei
Hooks. Es ist nicht falsch — es ist alt: Der Kern löst inzwischen **22 Hooks**
aus (plus acht Aliasse aus der 0.7-Linie). Wer wissen wollte, wie
`horse.restored` aussieht oder was `captcha.verify` zurückgeben muss, fand nur
eine Tabellenzeile in `docs/plugin-development.md` und kein laufendes Beispiel.

Ein unvollständiges Beispiel ist genauso grün wie ein vollständiges — deshalb
fiel das jahrelang niemandem auf. Der Schutz dagegen ist ein Test, nicht
Disziplin: **`tests/Manifest/BeispielErweiterungspunkteAbdeckungTest.php` liest
den Kern-Quelltext und wird rot, sobald dort ein Hook hinzukommt, den dieses
Addon nicht belegt.**

## Installation

```bash
cp -r beispiel-erweiterungspunkte /pfad/zu/Hengstverzeichnis_Framework/plugins/beispiel-erweiterungspunkte
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren.
Nötige Berechtigungen anschließend unter **Admin → Gruppen** zuweisen — ab
Werk hat sie niemand (fail-closed):

| Modul | Aktion | wofür |
|---|---|---|
| `horses` | `beispielnotiz` | Beispiel-Notiz im Pferdeformular pflegen |
| `beispiel-erweiterungspunkte` | `view` | Ereignisbuch und Dashboard-Kachel |
| `beispiel-erweiterungspunkte` | `notiz` | Kontakt-Notiz und Sperrwort ändern |

## Was man wo sieht

| Ort | Was dort erscheint |
|---|---|
| Startseite | Abschnitt oben (Foto des ersten Pferds) und unten (Ereigniszähler) |
| Öffentliche Navigation | Menüpunkt „Beispiel-Schaufenster" |
| Katalog | Abzeichen an jeder Karte mit Notiz |
| Pferde-Detailseite | Abschnitt mit Notiz, Stationsangabe, Pedigree-Hinweis |
| Kontaktseite (`/kontakt?id=`) | Abschnitt, der zeigt, *ob* Zustelldaten freigegeben sind |
| Pferd bearbeiten | Eigenes Formular für die Notiz |
| Kontakt bearbeiten | Eigenes Formular für die Kontakt-Notiz |
| Admin-Dashboard | Kachel „Beispiel: Erweiterungspunkte" |
| `/plugin/beispiel-erweiterungspunkte/ereignisbuch` | Abdeckungstafel + Ereignisbuch + Sperrwort |
| `/plugin/beispiel-erweiterungspunkte/schaufenster` | öffentliche Seite hinter der Zusatzfunktion (#57) |
| `/plugin/beispiel-erweiterungspunkte/probeformular` | öffentliches Formular mit eigenem Spam-Schutz-Kontext |

Das **Ereignisbuch** ist der Kern der Idee: Jeder ausgelöste Hook schreibt eine
Zeile in `plugin_beispiel_ereignisse`. Man legt ein Pferd an, öffnet die Seite
und sieht, was gefeuert hat — statt es aus einer Logdatei zu fischen.

## Abgedeckte Erweiterungspunkte

**Actions** (reagieren, können nichts abbrechen):
`horse.before_save` · `horse.after_save` · `horse.before_delete` ·
`horse.trashed` · `horse.restored` · `horse.deleted` · `contact.after_save` ·
`contact.deleted`

**Filter** (Wert hereinnehmen, verändert zurückgeben):
`horse.detail_sections` · `horse.edit_sections` · `horse.publish_blockers` ·
`horse.search_ids` · `catalog.card_sections` · `contact.detail_sections` ·
`contact.edit_sections` · `home.sections_top` · `home.sections_bottom` ·
`admin.dashboard_tiles` · `layout.nav_items` · `captcha.providers` ·
`captcha.render` · `captcha.verify`

**Alles andere, was kein Hook ist:** eigene Routen (GET *und* POST),
`permissions()` in allen drei Bauarten, `features()` (#57), `install()` mit
eigenen Tabellen, `uninstall()`, das `owns`-Register, `captchaContexts()`
(#351), `PluginAudit::log()` (#352), eigene `lang/de.php` und `lang/en.php`,
Seiten über `PluginPage` im Haupt-Layout.

**Bewusst nicht abgedeckt:** die acht Aliasse `person.*` und `station.*`. Der
Kern feuert sie seit v0.8 zusätzlich zu ihren `contact.*`-Gegenstücken, damit
Addons aus der 0.7-Linie weiterlaufen; sie entfallen in v0.9.0. Wer beide
registriert, bekommt seinen Abschnitt doppelt auf derselben Seite — seit der
Zusammenlegung von `persons` und `breeding_stations` gibt es nur noch einen
Datensatz. Die Begründung steht je Hook in
`Plugin::BEWUSST_NICHT_ABGEDECKT`, und der Abdeckungstest besteht darauf, dass
sie da ist.

## Die Fallen, die dieses Repo Arbeit gekostet haben

Jede steht als Kommentar an der Stelle, an der sie zuschlägt. Die Kurzfassung:

1. **Actions können nichts blockieren.** `horse.before_save` sieht aus wie ein
   Veto und ist keins — der `HookManager` fängt jede Ausnahme ab. Das einzige
   Veto des Systems ist `horse.publish_blockers`, und es gilt nur der
   *Veröffentlichung*, nicht dem Speichern.
2. **`layout.nav_items` liefert Daten, keinen HTML-String.** Der Kern prüft sie
   (`App\Helper\NavItems`) und verwirft still, was durchfällt. Wer seinen
   Menüpunkt nicht sieht, prüft zuerst, ob der Pfad mit `/` beginnt. Über alle
   Addons hinweg sind höchstens fünf Einträge erlaubt.
3. **Fail-closed prüfen, statt ein Formular zu zeigen, das 403 liefert.** Der
   Abschnitt im Pferdeformular steht hinter `horses.edit`, seine Daten aber
   hinter `horses.beispielnotiz` — ohne eigene Prüfung sähe ein Redakteur ein
   Formular, das beim Absenden abweist.
4. **Das FELD prüfen, nicht die Verknüpfung.** `$horse['breeding_station_id']`
   ist auch dann gesetzt, wenn die Station unveröffentlicht, gelöscht oder für
   Gäste gesperrt ist; die `station_*`-Felder sind dann sämtlich `null`. An
   dieser Verwechslung ist hier schon ein Addon still gebrochen.
5. **Pferdefotos über `App\Helper\MediaUrl`**, nie über den rohen Spaltenwert
   `image_url`. Der rohe Wert funktioniert — aber am Anwendungscode vorbei und
   damit ohne den Einbettungsschutz.
6. **`null` und `[]` sind bei `horse.search_ids` verschiedene Aussagen.**
   `null` heißt „nichts beizutragen", `[]` heißt „keine Treffer". Beides
   gleichzusetzen zeigt dem Benutzer den vollen Bestand, obwohl sein Filter
   etwas anderes meint.
7. **Der `CASCADE` greift beim Soft-Delete nicht.** Eigene Daten gehören in
   `horse.trashed` stillgelegt und in `horse.restored` wieder in Betrieb — sonst
   kann ein Addon nach jedem Papierkorb-Ausflug still weniger.
8. **`catalog.card_sections` läuft je Karte**, bis zu 24-mal pro Seitenaufruf.
   Die Notizen werden deshalb einmal pro Request geladen, nicht je Karte
   abgefragt.
9. **`captcha.verify` kennt vier gültige Antworten** (`OK`/`WRONG`/`EXPIRED`/
   `TOO_FAST`) und `null` für „nicht zuständig". Alles andere — auch `true` —
   liest der Kern als „nicht geantwortet" und prüft selbst.
10. **Der eigene Slug ist bei allen drei `captcha.*`-Filtern zu prüfen.** Sie
    laufen für jeden Anbieter, auch für fremde.
11. **Ein eigenes Formular mit Datei-Upload braucht
    `enctype="multipart/form-data"`.** Dieses Addon nimmt bewusst keine Dateien
    entgegen (ein Upload gehört nicht in ein Lehrbeispiel, ohne dass auch
    Typprüfung, Größenlimit und Ablageort mitgelehrt würden) — den fertigen
    Umgang damit zeigt `plugins/gesundheitstests`.

## Daten und Deinstallation

```json
"owns": {
    "tables":   ["plugin_beispiel_ereignisse", "plugin_beispiel_notizen"],
    "settings": ["plugin_beispiel_sperrwort"]
}
```

Der Präfix `plugin_` ist Pflicht — ohne ihn weist der Kern den Eintrag ab, und
die Deinstallation ließe die Daten liegen. Aus dem Register kann der Kern dem
Betreiber **vor** dem Löschen sagen, wie viele Datensätze verschwänden.

`uninstall()` räumt zusätzlich zwei Zeilen in der Kern-Tabelle `settings` weg,
die dieses Addon *verursacht*, aber nicht selbst benannt hat:
`feature_visibility__beispiel-schaufenster` und
`captcha_provider_beispiel-formular`. Sie beginnen nicht mit `plugin_` und
dürfen deshalb gar nicht im Register stehen — genau dafür gibt es die Methode
zusätzlich zum Register.

Verzeichnisse beansprucht das Addon keine: Es legt keine Dateien an. Ein
Eintrag „auf Vorrat" wäre keine Vorsicht, sondern eine falsche Behauptung
darüber, was bei der Deinstallation verschwindet.

## Tests

| Datei | Was sie festhält |
|---|---|
| `tests/Manifest/BeispielErweiterungspunkteAbdeckungTest.php` | Hooks des Kerns gegen die hier belegten — **ohne DB, läuft überall mit** |
| `tests/Functional/BeispielErweiterungspunktePluginTest.php` | jeder Hook einmal ausgelöst, gegen eine echte Instanz |

Der Abdeckungstest misst nicht an einer gepflegten Liste — das wäre dasselbe
Problem eine Ebene höher. Er liest den Kern-Quelltext mit dem PHP-Tokenizer
(Kommentare zählen nicht mit) und findet jeden `doAction()`/`applyFilters()`-
Aufruf. Aufrufe, deren Hook-Name erst zur Laufzeit entsteht, stehen namentlich
in `DYNAMISCHE_AUFRUFE`; kommt eine solche Stelle hinzu, wird der Test
ebenfalls rot — denn genau die wäre der Hook, der unbemerkt unabgedeckt
bliebe.

Der Funktionstest ist zugleich der einzige geschlossene Nachweis, dass die
Hooks des Kerns überhaupt noch feuern. Jedes andere Addon prüft die zwei, drei
Hooks, an denen es hängt; ob `horse.restored` noch ausgelöst wird, fiel bisher
nur auf, wenn zufällig jemand ein Addon dafür hatte.
