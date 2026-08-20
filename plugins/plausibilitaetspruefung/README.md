# plausibilitaetspruefung

Findet Widersprüche im Bestand, **hindert die betroffenen Pferde an der
Veröffentlichung** und sammelt alles in einem eigenen Bereich zum Reparieren.

Löst [Celestial0579/Hengstverzeichnis_Addons#114](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/114).
Das Veto läuft über den Kern-Filter `horse.publish_blockers`
([Framework#335](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/335));
zwei der Regeln stammen aus
[Framework#334](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/334).

## Warum

Beim Aufräumen der Deckstationsdaten ist über Tage immer dasselbe passiert:
Ein Widerspruch fällt auf, weil ihn jemand zufällig ansieht - nicht, weil ihn
etwas meldet. Und er steht die ganze Zeit öffentlich im Katalog. Gemessen an
der Dev-Instanz (Stand v0.7.1, nach der Bereinigung): 9 Elternteile jünger als
das Fohlen, 1× Vater = Mutter, 7 Halterzeiträume nach dem Todesjahr, 35
gestorbene Pferde mit offenem Zeitraum, 36 Pferde ohne Lebensnummer, 53 ohne
Geschlecht, 26 Datensätze mit Zeichenschaden.

## Installation

```bash
cp -r plausibilitaetspruefung /pfad/zu/Hengstverzeichnis_Framework/plugins/plausibilitaetspruefung
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren und
unter `/admin/groups` die Rechte vergeben:

| Recht | Wirkung |
|---|---|
| **Plausibilitätsprüfung → Bericht ansehen** | Dashboard-Kachel, Bericht (`/plugin/plausibilitaetspruefung/bericht`) und der Abschnitt im Bearbeitungsformular eines Pferdes |
| **Plausibilitätsprüfung → Fälle als geprüft abhaken** | zusätzlich: einen Fund mit Begründung stehenlassen |

Ohne das jeweilige Recht erscheint der zugehörige Teil **gar nicht** - nicht
als ausgegrauter Knopf, der beim Drücken 403 liefert.

**Das Veto beim Veröffentlichen hängt an keinem Recht.** Es ist eine Aussage
über die Daten, keine über den Benutzer: Ein Widerspruch bleibt einer, auch
wenn ein Administrator speichert.

## Die vier tragenden Entscheidungen

**1. Das Veto gilt dem Veröffentlichen, nicht dem Speichern.** Der Datensatz
wird gespeichert, nur das Häkchen „veröffentlicht" fällt, und der Bearbeiter
bekommt die Gründe genannt. Wer seine halbfertige Eingabe nicht speichern
kann, kommt nie an den Punkt, an dem er den Widerspruch auflöst - deshalb ist
`horse.before_save` im Kern ein `doAction` ohne Rückgabewert und bleibt es.

**2. Blockierend ist nur, was nicht wahr sein kann.**

| Regel | Schwere | Warum |
|---|---|---|
| Elternteil jünger als das Fohlen | **blockierend** | physikalisch unmöglich |
| Vater und Mutter sind dasselbe Pferd | **blockierend** | physikalisch unmöglich |
| Todesjahr vor dem Geburtsjahr | **blockierend** | physikalisch unmöglich |
| Zuordnungszeitraum nach dem Todesjahr | **blockierend** | physikalisch unmöglich |
| Verstorbenes Pferd mit offenem Zeitraum | Hinweis | im Bestand der Normalfall (35×) - wer bis zum Tod Halter war, hat kein Endjahr eingetragen |
| Keine Lebensnummer | Hinweis | fehlende Angabe, keine falsche (36×) |
| Kein Geschlecht | Hinweis | fehlende Angabe, keine falsche (53×) |
| Zeichenschaden (U+FFFD) | Hinweis | Darstellungsfehler; die Aussage des Datensatzes bleibt richtig (26×) |

Die Trennlinie ist nicht „wie schlimm sieht es aus", sondern „steht hier etwas
Falsches". „Gestorbenes Pferd mit offenem Halterzeitraum" als Blocker zu
führen nähme 35 gepflegte Seiten vom Netz, um eine Konvention durchzusetzen,
über die noch niemand entschieden hat.

**3. Es wird nichts selbsttätig repariert.** Ob das Todesjahr falsch ist oder
der Zeitraum, ist eine Sachfrage. Jeder Fund führt ins Bearbeitungsformular
des Datensatzes; entschieden wird dort.

**4. Ein Fall lässt sich mit Begründung abhaken.** Ohne diese Möglichkeit
wächst die Liste zu, wird ignoriert und ist wertlos. Ein abgehakter Fall
blockiert die Veröffentlichung nicht mehr; die Begründung bleibt am Datensatz
sichtbar (Abschnitt im Bearbeitungsformular) und lässt sich zurücknehmen. Ohne
Begründung wird nicht abgehakt - ein Häkchen ohne Grund ist genau das, was aus
einer Prüfliste eine leere Liste macht.

## Nutzung

1. **Dashboard → Plausibilität**: Kachel mit der Zahl der offenen Funde,
   führt auf den Bericht.
2. **Bericht** (`/plugin/plausibilitaetspruefung/bericht`): nach Schwere und
   Regel gruppiert, je Fall der Befund im Klartext, ob das Pferd öffentlich
   ist, ein Link ins Bearbeitungsformular und das Feld zum Abhaken. Darunter
   je Regel die bewusst stehengelassenen Fälle mit Begründung, Prüfer und
   Datum.
3. **Bearbeitungsformular eines Pferdes**: derselbe Befund am Datensatz,
   inklusive der Begründung eines abgehakten Falls.
4. **Beim Speichern mit gesetztem Häkchen „veröffentlicht"**: Greift eine
   blockierende Regel, bleibt der Datensatz gespeichert, das Häkchen fällt,
   und der Kern nennt die Gründe.

## Was das Addon nicht tut

- **Nichts reparieren** (siehe oben).
- **Keine zweite Prüfung neben dem Kern.** `HorseController` prüft beim
  Speichern bereits `pedigreeContradiction()`, `parentSexMismatch()`,
  `personPeriodAfterDeath()` und `death_year < birth_year`. Dieses Addon ist
  für den **Altbestand** da, der nie durch dieses Formular gelaufen ist. Wo
  sich die Aussagen decken, ist es dieselbe Regel zu einem anderen Zeitpunkt -
  der Kern beim Speichern eines neuen Datensatzes, das Addon beim
  Veröffentlichen eines alten.
- **Keine Personendaten anfassen.** Geprüft werden Jahreszahlen und
  Verknüpfungen; im Protokoll steht „Pferd #42", nie ein Kontaktfeld.

## Kosten

Die Prüfung läuft bei **jedem** Speichern eines Pferds, das veröffentlicht
werden soll. Deshalb:

1. Jede Regel trägt eine billige **Vorbedingung** aus den bereits
   vorliegenden Feldern des Pferds. Ohne Todesjahr entfällt die Regel
   „Zeitraum nach dem Todesjahr", ohne verknüpfte Eltern die Regel
   „Elternteil jünger". Beim typischen Datensatz bleiben null bis zwei Regeln
   übrig - und bei null bleibt es bei **null Abfragen**.
2. Was übrig bleibt, läuft in **einer** Abfrage: die Teilabfragen per
   `UNION ALL`, jede über den Primärschlüssel des Pferds.
3. Die abgehakten Fälle werden nur nachgeschlagen, wenn überhaupt etwas
   gefunden wurde.
4. Die **Dashboard-Kachel** liest einen Zwischenstand
   (`plugin_plausibilitaet_zaehler`, höchstens 15 Minuten alt) statt acht
   Aggregate über den ganzen Bestand zu rechnen, nur weil jemand `/admin`
   aufruft. Der Bericht selbst und das Veto rechnen immer frisch.

**Jede Regel hat genau eine Abfrage** - dieselbe SQL trägt einmal `1=1`
(Bericht) und einmal `h.id = ?` (Veto). Zwei Fassungen derselben Regel driften
auseinander, und zwar unbemerkt: Der Bericht zeigt einen Fall, den das Veto
nicht kennt, und beide sehen für sich plausibel aus.

**Fail-open bei jedem Fehler.** Kann das Addon nicht prüfen (Tabelle fehlt,
Datenbank antwortet nicht), meldet es *keine* Einwände. Ein abgestürztes Addon
darf keine Veröffentlichung blockieren - niemand könnte den Grund beheben.

## Eine Regel ergänzen

In `Regelwerk::alle()` einen `Regel`-Eintrag anlegen: Kennung, Schwere, Titel,
Klartextbegründung, SQL mit dem Platzhalter `{WO}` und die Vorbedingung. Die
Abfrage liefert immer dieselben vier Spalten `horse_id`, `name`,
`oeffentlich`, `detail`. Kein Eingriff in den Kern und keiner an anderer
Stelle dieses Addons; Bericht, Kachel und Veto kennen die neue Regel
anschliessend von selbst.

Die Vorbedingung darf nur **enger** sein als die `WHERE`-Klausel der Abfrage,
nie enger als nötig - sonst übersieht das Veto, was der Bericht zeigt. Sie
steht deshalb unmittelbar neben der SQL.

## Eigene Daten

| Tabelle | Inhalt |
|---|---|
| `plugin_plausibilitaet_ausnahmen` | abgehakte Fälle: Pferd, Regel, Begründung, Prüfer, Zeitpunkt |
| `plugin_plausibilitaet_zaehler` | Zwischenstand der Bestandszahlen für die Kachel |

Beide stehen unter `owns` in der `plugin.json`
([Framework#338](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/338)) -
die Deinstallation zeigt vorher, wie viele Datensätze verschwänden.

Die Protokolleinträge unter der Kategorie `plausibilitaetspruefung`
(`/admin/audit-log`) bleiben bewusst stehen: Sie sind der Nachweis, **wer**
einen Widerspruch als geprüft abgehakt hat, und ein Nachweis, den das
Deinstallieren mitnimmt, ist keiner.
