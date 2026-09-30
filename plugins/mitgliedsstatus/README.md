# mitgliedsstatus

Führt die Angabe **Mitglied / Nichtmitglied / keine Angabe** je Kontakt als
eigenständig gepflegtes Feld mit **fester Werteliste** - an der Stelle des
Freitextfelds `contacts.membership_status`, das der Kern in v0.9.0 entfernt.
Und es verlinkt einen Kontakt in eine **CiviCRM**-Instanz: eine Kennung, eine
Basis-URL, ein Link - ausdrücklich kein Datenabgleich.

Löst [Celestial0579/Hengstverzeichnis_Addons#132](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/132)
samt Zuschnitt A aus dem Bericht zu
[#130](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/130).
Gegenstück im Kern:
[Framework#349](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/349).

## Installation

```bash
cp -r mitgliedsstatus /pfad/zu/Hengstverzeichnis_Framework/plugins/mitgliedsstatus
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren.

**Die Aktivierung übernimmt die Bestandswerte** - das ist kein Nebeneffekt,
sondern der Zweck des Zeitpunkts (siehe unten). Anschliessend:

1. Unter **Admin → Gruppen** (`/admin/groups`) der zuständigen Gruppe das Recht
   **Mitgliedsstatus → Übernahme nacharbeiten und CiviCRM-Einstellungen
   pflegen** zuweisen.
2. Unter **Dashboard → Mitgliedsstatus**
   (`/plugin/mitgliedsstatus/verwaltung`) den Bericht der Übernahme lesen und
   die offenen Wortlaute nacharbeiten.
3. Sollen die Angaben öffentlich erscheinen: der **Gast-Gruppe** das Recht
   **Mitgliedsstatus → Freigegebene Angabe auf der öffentlichen Kontaktseite
   sehen** geben und je Kontakt das Häkchen setzen.

## Die vier tragenden Entscheidungen

**1. Feste Liste statt Freitext.** Der Kern führt die Angabe bis v0.8 als
freies Textfeld (Platzhalter „z. B. Mitglied / Nichtmitglied NO"). Freitext ist
nicht auswertbar: `Mitglied`, `mitglied`, `Vollmitglied` und `Nichtmitglied NO`
stehen nebeneinander und meinen teils dasselbe, teils etwas anderes. Hier ist
es ein Wert aus einer abgeschlossenen Liste.

**2. Die Übernahme ist eine Übergabe, kein Abgleich.** Sie läuft **genau
einmal**, bei der Installation, und ist durch einen Marker geschützt. Was sich
nicht ohne Raten abbilden lässt, wird **nicht verworfen**: Der Wortlaut bleibt
Zeichen für Zeichen erhalten, die Zeile wird als *offen* markiert, und die
Verwaltungsseite legt sie einem Menschen vor.

**3. Nicht öffentlich, solange es niemand freischaltet.** Heute ist der Wert
bedingungslos öffentlich. „X ist kein Mitglied" ist eine Aussage über einen
Menschen. Die Sichtbarkeit ist deshalb je Kontakt schaltbar (Vorgabe: aus) und
hängt **zusätzlich** am Recht `mitgliedsstatus.view` der Gast-Gruppe. Beides
muss zutreffen - fail-closed, wie `contact_public` im Kern.

**4. Kein Datenabgleich mit CiviCRM.** Gespeichert wird eine Kennung, gebaut
wird ein Link. Es wird nichts abgefragt, nichts übernommen, nichts
zurückgeschrieben. Die Zuordnung wird von Hand gesetzt oder importiert und
**nie** über Namensähnlichkeit geraten: Zwei Betriebe mit ähnlichem Namen sind
nicht derselbe, und ein falsch verknüpfter Mitgliedsdatensatz fällt niemandem
auf. (Addons#130: Zuschnitte B und D sind abgewählt, #131 sagt „kein
Datenabgleich".)

## Die Übernahme im Einzelnen

### Die Abbildungsregel

Exakte Übereinstimmung nach Normalisierung - sonst nichts. Normalisiert wird
nur, was keine Bedeutung trägt: Rand-Leerraum, mehrfacher Leerraum,
Gross-/Kleinschreibung. Die Synonymliste enthält ausschliesslich Schreibweisen
**desselben Wortes**, keine Schlüsse:

| Wortlaut im Bestand | Ergebnis |
|---|---|
| `Mitglied`, `mitglied`, `Vollmitglied`, `Member` | **Mitglied** |
| `Nichtmitglied`, `Nicht Mitglied`, `nicht-mitglied`, `kein Mitglied` | **Nichtmitglied** |
| `Nichtmitglied NO` | **offen** - Wortlaut bleibt stehen |
| `Ehrenmitglied seit 1998` | **offen** - Wortlaut bleibt stehen |

`Nichtmitglied NO` ist der Fall, an dem sich die Regel entscheidet. Das `NO`
ist in diesem Bestand ein Länderkürzel (siehe `database/schema.sql` im Kern,
Kommentar an `country`). Wer es wegwirft, um auf `Nichtmitglied` zu kommen,
behauptet, es hätte nichts bedeutet - das ist Raten. Stattdessen landet der
Wortlaut auf der Verwaltungsseite, gruppiert und gezählt, und **ein Mensch**
weist allen Kontakten mit genau diesem Wortlaut in einem Zug einen Status zu.

Leerraum am Rand zählt dabei nicht (seit 1.1.0, Audit N32): Übernahme, Liste
und Zuordnen verwenden dieselbe SQL-Regel (`Status::normiert()`,
`REGEXP_REPLACE` auf `[[:space:]]` am Rand). `' Nichtmitglied NO'`,
`'Nichtmitglied NO'` mit Tab oder Zeilenumbruch am Ende und
`'nichtmitglied no'` erscheinen als **eine** Zeile und werden gemeinsam
zugeordnet. Der gesicherte Wortlaut (`altwert`) bleibt trotzdem Zeichen für
Zeichen erhalten, normiert wird nur im Vergleich. Innerer Mehrfach-Leerraum
wird bewusst nicht zusammengefasst. Werte, die nur aus Leerraum bestehen,
werden nicht übernommen.

### Warum sie einen Marker braucht

`install()` läuft bei **jeder** Aktivierung und nach jedem Addon-Update erneut
- der Kern garantiert „mindestens einmal", nicht „genau einmal". Die Übernahme
ist aber eine Übergabe: Nach dem ersten Lauf ist der gepflegte Bestand hier,
und die Freitextspalte des Kerns ist ein eingefrorener Altstand. Ein zweiter
Lauf überschriebe die Arbeit eines Menschen („dieser Wortlaut bedeutet
Nichtmitglied") mit genau dem Altstand, der ihn zu dieser Arbeit gezwungen hat
- lautlos, denn ein Deaktivieren zur Fehlersuche und ein Wiedereinschalten
sieht niemand als Datenänderung an.

Marker und Daten stehen deshalb in **derselben Transaktion**. Ein Abbruch nimmt
beides zurück, ein Erfolg hält beides fest; einen Zwischenstand mit
übernommenen Daten und fehlendem Marker gibt es nicht.

Der Marker ist die Einstellung `plugin_mitgliedsstatus_uebernahme` und enthält
zugleich den Bericht als JSON:

| Feld | Bedeutung |
|---|---|
| `zeitpunkt` | Zeitpunkt der Übernahme |
| `gesamt`, `zugeordnet`, `offen` | gelesen, abgebildet, zur Nacharbeit offen |
| `quelle` | seit 1.1.0: `contacts` oder `persons_pre_contacts` |
| `bestand` | nur bei `persons_pre_contacts`: schon im Addon gepflegt, unverändert gelassen |
| `grund` | `keine-spalte`, wenn es nichts zu übernehmen gab |

**Das Format ist Vertrag mit dem Kern.** `App\Service\MitgliedsstatusAltbestand`
liest `grund` und `quelle`, um Admins auf dem Dashboard auf noch nicht
übernommene Werte im v0.7-Altbestand hinzuweisen. Wer die Felder oder die
Regel unten ändert, ändert beide Seiten.

„Konnte nicht" und „war nichts zu tun" sind dabei verschiedene Aussagen: Fehlt
die Tabelle `contacts` (Kern der 0.7-Linie), wird **kein** Marker gesetzt und
der nächste Lauf versucht es erneut. Fehlt die Spalte bei vorhandener Tabelle
(Kern jenseits v0.9.0), gibt es dauerhaft nichts zu übernehmen - dann gehört
der Marker gesetzt.

### Reihenfolge

Addons#132 nennt sie ausdrücklich: **Addon steht und hat übernommen → dann
entfernt der Kern die Spalte.** Läuft die Übernahme nicht, fallen die
gepflegten Werte zwischen die beiden Releases; nach dem Kern-Update ist nichts
mehr da, woraus man sie holen könnte.

**Ausnahme: der direkte Sprung aus v0.7** (seit 1.1.0, Audit N78). Eine
v0.7-Instanz kann das Addon vor dem Update nicht installieren - ihr Kern kennt
`contacts` noch nicht. Nach dem Sprung auf einen Kern nach Framework#395 hatte
`contacts` die Spalte nie; die Werte stehen nur im stillgelegten Altbestand
`persons_pre_contacts`, unter denselben Kontakt-IDs. Das Addon übernimmt sie
von dort, auch wenn es **nach** dem Kern-Update installiert wird. Bedingungen:

- `persons_pre_contacts.membership_status` existiert, `contacts.membership_status`
  nicht, und
- der Kern hat **keinen** Marker `migration_395_membership_status_faellt`
  gesetzt. Den setzt der Schritt 395 nur, wenn er die Spalte in `contacts`
  tatsächlich gelöscht hat - dann lief die Instanz über 0.8/0.9, und der
  Altbestand ist der womöglich überholte Stand der #336-Umstellung. Er wird in
  diesem Fall **nicht** übernommen.

Gelesen wird per JOIN auf `contacts`: gelöschte und zusammengeführte Kontakte
bleiben aussen vor, vom Kern anonymisierte (Name „Anonymisierte Person (#id)“)
ebenfalls. Es wird **nur eingefügt** - eine schon im Addon gepflegte Angabe
bleibt stehen und wird im Bericht als `bestand` gezählt.

Hatte 1.0.0 nach dem Sprung schon mit „keine Spalte“ abgeschlossen (Marker
mit `grund: keine-spalte`, ohne `quelle`), holt 1.1.0 die Übernahme beim Update
**einmal** nach. Danach trägt der Marker `quelle`, und es bleibt beim
Nur-einmal-Prinzip.

## Das Freitextfeld des Kerns danach

Bis Kern-v0.9.0 gibt es die Spalte noch, und der Kern zeigt sie auf der
öffentlichen Kontaktseite **bedingungslos** an. Solange sie befüllt ist, steht
die alte, ungeprüfte Angabe neben der neuen, und die Freigabe je Kontakt aus
diesem Addon läuft ins Leere.

Die Verwaltungsseite bietet dafür beide Richtungen, jeweils vom Betreiber
ausgelöst:

- **Gesicherte Werte im Kern leeren** - leert `contacts.membership_status`
  ausschliesslich dort, wo der aktuelle Wert **Zeichen für Zeichen** der
  gesicherten Fassung entspricht. Nach der Übernahme von Hand geänderte Werte
  bleiben stehen; eine Änderung, die niemand gesichert hat, räumt keine
  Automatik weg.
- **Aus der Sicherung wiederherstellen** - schreibt die Wortlaute zurück, wo
  die Spalte leer ist. Der Rückweg ist byte-identisch, weil der Wortlaut auch
  im abgebildeten Fall vollständig gesichert wird.

Es geschieht **nicht** automatisch: Es ist eine Änderung an einer Kern-Tabelle,
und sie nimmt der Züchtersuche ihren Filter „Mitgliedsstatus", solange dieser
noch auf der Kern-Spalte sitzt.

Das Deinstallieren schreibt ebenfalls **nichts** zurück - der Rückweg gehört
davor, danach ist die Sicherung mit der Tabelle weg.

## Was das Addon nicht kann, und warum

**Die Angabe verschwindet aus dem Personenblock der Pferdeseite.** Bis v0.8
steht sie an zwei Stellen: auf der Kontaktseite und inline im Personenblock von
`public_horse_detail.php`. Die zweite ist für ein Addon nicht erreichbar -
`horse.detail_sections` hängt Abschnitte hinten an, innerhalb der Personenzeile
gibt es keinen Erweiterungspunkt. Addons#132 hält das als bewusste Entscheidung
fest.

**Es fragt CiviCRM nicht ab.** Die in Addons#132 skizzierte Betriebsart 2
(Mitgliedszustand zur Anzeigezeit aus CiviCRM holen) ist mit dem Bericht zu
#130 abgewählt: Sie kollidiert mit der Entscheidung, den Status hier
eigenständig und mit fester Werteliste zu führen. Zwei Systeme, die dasselbe
Feld führen, bräuchten eine Feldhoheitstabelle - das wollte niemand.

**Es ersetzt den Filter der Züchtersuche nicht.** Der Filter
„Mitgliedsstatus" sitzt im Addon `zucht-suche` und liest die Kern-Spalte.
Ihn auf die Tabelle hier umzustellen ist eine Änderung an einem anderen Addon
und gehört in ein eigenes Issue.

## Nutzung

1. **Redaktion** öffnet einen Kontakt (`/admin/contacts/edit?id=…`) und findet
   dort zwei Abschnitte: „🎗 Mitgliedsstatus" und „🔗 CiviCRM". Beide haben ein
   eigenes Formular und einen eigenen Knopf - Änderungen an den Stammdaten
   oben zuerst speichern.
2. **Verwaltung** arbeitet unter **Dashboard → Mitgliedsstatus** die offenen
   Wortlaute ab und hinterlegt die CiviCRM-Basis-URL.
3. **Besucher** sieht die Angabe auf `/kontakt?id=…` nur, wenn sie für diesen
   Kontakt freigegeben ist **und** die Gast-Gruppe das Leserecht hat.

## Was dem Addon gehört (`owns`, Framework#338)

| Art | Name |
|---|---|
| Tabelle | `plugin_mitgliedsstatus_kontakt` |
| Tabelle | `plugin_mitgliedsstatus_civicrm` |
| Einstellung | `plugin_mitgliedsstatus_uebernahme` (Marker **und** Bericht) |
| Einstellung | `plugin_mitgliedsstatus_civicrm_url` |

Beide Tabellen hängen per Fremdschlüssel mit `ON DELETE CASCADE` an `contacts`:
Die Zeilen sind Aussagen **über** einen Kontakt und personenbezogen. Wird der
Kontakt endgültig gelöscht, müssen sie mitgehen - sonst bliebe „Kontakt 42 ist
kein Mitglied" in einer Nebentabelle liegen, während der Kern die Löschung für
vollständig hält.

Der Fremdschlüssel deckt nur das endgültige Löschen ab. Für die anderen
beiden Fälle hört das Addon (ab 1.2.0) auf die Kern-Hooks aus Framework#474
und #476:

- **`contact.anonymized`:** Der Kontakt bleibt bestehen, kein CASCADE greift.
  Mitgliedsstatus und CiviCRM-Zuordnung werden gelöscht - über die Kennung im
  Fremdsystem wäre der anonymisierte Mensch sonst wieder zu finden.
- **`contact.merged`:** Was dem behaltenen Kontakt fehlt, kommt von der
  Quelle: ein Status (wenn das Ziel keinen hat oder nur „keine Angabe“ ohne
  offenen Bestandswortlaut) und die CiviCRM-Zuordnung (wenn das Ziel keine
  hat). Die öffentliche Freigabe wird **nicht** übertragen, sie steht danach
  auf aus. Hat das Ziel eigene Angaben, gewinnt es; das Protokoll nennt den
  Konflikt, die Quelle im Papierkorb behält ihre Zeilen bis zum Leeren.
- **`contact.erased`:** zweite Linie hinter dem CASCADE.

Protokolleinträge der Kategorie `mitgliedsstatus` bleiben beim Deinstallieren
**stehen**: Sie sind der Nachweis, wer wann welchen Kontakt als Nichtmitglied
geführt und wer eine Angabe öffentlich geschaltet hat. Ein Nachweis, den das
Deinstallieren mitnimmt, ist keiner. Die CiviCRM-Kennung und die
Bestandswortlaute stehen nie im Protokoll - `audit_logs` kennt keine Löschfrist
und überlebte damit jede DSGVO-Löschung des Kontakts.

## Berechtigungen

| Modul × Aktion | Wofür |
|---|---|
| `mitgliedsstatus` × `view` | Gast-Gruppe: freigegebene Angabe auf `/kontakt?id=…` sehen |
| `mitgliedsstatus` × `manage` | Verwaltungsseite: Nacharbeit, CiviCRM-Basis-URL, Kern-Freitext |
| `contacts` × `edit` | die beiden Abschnitte im Kontaktformular sehen und speichern |

Bewusst getrennt: Wer eine Redaktionskraft ein Häkchen setzen lassen will, soll
ihr dafür kein Verwaltungsrecht geben müssen. Und das Leeren der Kern-Spalte
verlangt **beides** - `manage` und `contacts.edit`.

## Tests

- `tests/Functional/MitgliedsstatusPluginTest.php` - vollständiger Durchlauf
  gegen eine echte Instanz: Übernahme, Marker (mit **und** ohne), Nacharbeit,
  Sichtbarkeit in beiden Richtungen, CiviCRM-Link, Protokoll. Dazu
  Randleerraum- und Gross/Klein-Varianten beim Zuordnen (N32) und der
  v0.7-Sprung (N78): Übernahme aus `persons_pre_contacts`, Gegenprobe mit
  395-Marker, Nachholen eines 1.0.0-Markers ohne Überschreiben.
- `tests/Unit/MitgliedsstatusWerteTest.php` - die Abbildungsregel als reine
  Funktion, und dass es nur eine Randleerraum-Regel gibt.
