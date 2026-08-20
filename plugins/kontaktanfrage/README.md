# kontaktanfrage

Ein Besucher kann einen Kontakt des Verzeichnisses anschreiben, **ohne dessen
Adresse zu sehen**. Das Formular auf der öffentlichen Kontaktseite
(`/kontakt?id=…`) fragt genau drei Angaben ab: E-Mail des Anfragenden, Name
und einen **Grund aus fester Auswahl** - kein Freitext.

Löst [Celestial0579/Hengstverzeichnis_Addons#106](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/106),
umgestellt auf die zusammengeführte Kontaktliste mit
[#136](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/136).

## Installation

```bash
cp -r kontaktanfrage /pfad/zu/Hengstverzeichnis_Framework/plugins/kontaktanfrage
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren, der
zuständigen Gruppe unter `/admin/groups` die Berechtigung **Kontaktanfragen →
Anfragen einsehen, weiterleiten und löschen** zuweisen und unter **Dashboard →
Kontaktanfragen** (`/plugin/kontaktanfrage/verwaltung`) die **Team-Adresse**
hinterlegen. Ohne gültige Team-Adresse erscheint auf den öffentlichen Seiten
kein Formular - eine Anfrage, die nirgends ankommt, wäre schlimmer als gar
keine, weil der Besucher sie für zugestellt hält.

Damit tatsächlich E-Mails hinausgehen, muss unter **Admin → E-Mail & SMTP
Einstellungen** ein funktionierender Versand konfiguriert sein (derselbe
`App\Service\Mailer`, den auch der Kern nutzt).

## Die drei tragenden Entscheidungen

**1. Zugestellt wird immer an die Team-Adresse, nie direkt an den angefragten
Kontakt.** Das Team prüft die Anfrage und fragt nach, ob Kontakt überhaupt
gewünscht ist. Ein Formular, das ungeprüft an eine fremde Adresse
zustellt, ist ein Spam-Relais mit dem Verzeichnis als Adressbuch.

**2. Der Grund kommt aus einer abgeschlossenen Liste, es gibt kein
Nachrichtenfeld.** Nur so setzt der Server die Nachricht aus geprüften
Bausteinen zusammen; vom Absender kommt ausschließlich, was gegen die Liste
geprüft werden kann. Wer später „nur ein kleines Bemerkungsfeld" ergänzt,
gibt genau diesen Schutz auf.

**3. Opt-out statt Opt-in.** Kontaktanfragen sind erlaubt und lassen sich je
Kontakt abschalten. Das Kennzeichen gehört dem Addon (eigene Tabelle), der
Kern bekommt dafür keine Spalte.

## Nutzung

1. **Besucher** öffnet `/kontakt?id=…`, wählt einen Grund, trägt Name und
   E-Mail ein, löst die Spam-Aufgabe und sendet ab. Die Anfrage geht an das
   Team. (Die alten Adressen `/person?id=…` und `/station?id=…` leiten seit
   Kern-#336 dauerhaft auf `/kontakt?id=…` um.)
2. **Team** sieht sie unter **Dashboard → Kontaktanfragen**, klärt mit dem
   angefragten Kontakt und drückt dort **Weiterleiten** - erst damit erfährt
   er von der Anfrage, mit Name, E-Mail und Grund des Anfragenden.
3. **Redaktion** kann Anfragen für einen Kontakt abschalten: im
   Bearbeitungsformular des Kontakts (`/admin/contacts/edit?id=…`), Abschnitt
   „✉️ Kontaktanfragen", Häkchen entfernen und übernehmen.

Gründe sind vorbelegt mit *Deckanfrage*, *Kaufinteresse*, *Frage zur
Abstammung* und *Sonstiges*; weitere trägt der Admin in den Addon-
Einstellungen ein (einer je Zeile, höchstens 30). Der gespeicherte Schlüssel
wird aus dem Anzeigetext abgeleitet und ist damit stabil gegen Umsortieren;
der Anzeigetext wird zusätzlich in der Anfrage mitgespeichert, sodass ein
später umbenannter Grund lesbar bleibt.

## Missbrauchsschutz

Ein offenes Kontaktformular ist binnen Tagen ein Spam-Ziel, deshalb fünf
Hürden statt einer:

- **CSRF-Token** (`\App\Router::generateCsrfToken()`/`verifyCsrfToken()`) als
  erste Prüfung jedes schreibenden Endpunkts.
- **Honeypot-Feld**: für Menschen unsichtbar; füllt ein Bot es aus, meldet
  der Server scheinbar Erfolg und verwirft die Anfrage.
- **Rate-Limit je IP (5/Stunde) und je Empfänger (10/Tag)** über
  `App\Security\RateLimiter`. Zwei Zähler, weil sie zwei verschiedene
  Missbräuche treffen: Der IP-Zähler bremst den einzelnen Absender, der
  Empfänger-Zähler verhindert, dass ein Kontakt über wechselnde Anschlüsse
  zugemüllt wird. Einer allein wäre jeweils leicht zu umgehen.
- **Spam-Aufgabe** über den CAPTCHA-Unterbau des Kerns. Das Addon meldet sein
  Formular mit `captchaContexts()` als Kontext `kontaktanfrage` an
  (Kern-#351); welcher Anbieter greift, wählt der Betreiber unter den
  Systemeinstellungen je Formular, ohne Wahl gilt die eingebaute
  Rechenaufgabe. Geprüft wird **nach** der Buchung der Mengenzähler, und das
  ist Absicht: Zählte ein falscher Versuch nicht, könnte ein Bot die knapp
  zwanzig möglichen Antworten der Rechenaufgabe durchprobieren, bis eine
  passt. Erst die Begrenzung der Rateversuche macht die Aufgabe wirksam - der
  Preis ist, dass ein Vertipper einen der fünf Versuche je Stunde kostet, und
  genau das sagt die Rückmeldung auch.
- **Adressprüfung mit `FILTER_VALIDATE_EMAIL`, CR/LF wird abgelehnt** - und
  zwar **vor** dem Trimmen: `trim()` würde einen angehängten Zeilenumbruch
  entfernen und die Adresse damit „reparieren". Eine Adresse, die mit
  Zeilenumbruch ankommt, ist keine Eingabe eines Menschen, sondern der
  Anlauf, eine zweite Kopfzeile einzuschleusen.

Dazu die Regel, die für jede öffentliche Route dieses Repos gilt: **kein
Existenz-Orakel.** Fehlender, unveröffentlichter oder abgeschalteter
Datensatz führt zur selben Rückmeldung wie eine erfolgreiche Anfrage - der
Rückgabestatus verrät nicht, welche IDs es gibt und wer Anfragen abgeschaltet
hat.

Jede Anfrage, jede Weiterleitung, jede Änderung der Einstellungen und jedes
Setzen/Aufheben eines Opt-outs steht im Audit-Log, ebenso eine wegen
Rate-Limit abgewiesene Anfrage. Geschrieben wird über
`App\Plugin\PluginAudit::log()` (Kern-#352); die Kategorie ist damit der Slug
`kontaktanfrage` und im Filter unter `/admin/audit-log` eine Auswahl statt
einer Volltextsuche. **Name und Adresse des Anfragenden stehen nicht drin**:
Das Protokoll wird dauerhaft aufbewahrt und von keiner Löschfrist erfasst -
was dort landete, überlebte die Aufbewahrungsfrist dieses Addons.

## Datenschutz (DSGVO)

**Was gespeichert wird:** Name und E-Mail-Adresse des Anfragenden, der
gewählte Grund, das Ziel der Anfrage und die Zeitpunkte von Eingang und
Weiterleitung. Beides - Name und Adresse - sind personenbezogene Daten des
Anfragenden. Ein Nachrichtentext existiert nicht, weil es kein Freitextfeld
gibt.

**Wozu:** Bearbeitung der Anfrage durch das Team und Nachvollziehbarkeit bei
Missbrauch. Der Besucher wird im Formular darauf hingewiesen.

**Wie lange:** Voreingestellt **180 Tage**, in den Addon-Einstellungen
zwischen 0 und 3650 Tagen einstellbar; `0` schaltet die automatische Löschung
ab. Ältere Anfragen entfernt die Cron-Aufgabe `kontaktanfrage.aufraeumen`
(`App\Service\Scheduler`, einmal täglich, ausgelöst über den Cron-Lauf des
Kerns unter `/admin/cron`). Dieselbe Routine läuft auf Knopfdruck über
**Abgelaufene jetzt löschen**; einzelne Anfragen lassen sich in der Verwaltung
sofort löschen - der Weg für ein Löschersuchen nach Art. 17 DSGVO.

**Wer sieht was:** Die Verwaltung zeigt die Adresse des **Anfragenden** (das
Team muss antworten können), aber nie die des **Empfängers** - dort steht nur,
ob eine hinterlegt ist. Wer Anfragen bearbeiten darf, braucht dafür keinen
Blick in die Kontaktdaten; das Recht dazu ist `contacts.view` und hängt nicht
an diesem Addon.

**Deinstallation:** Die drei eigenen Tabellen stehen unter `owns` in der
`plugin.json` (Kern-#338) - der Betreiber sieht vor dem Löschen, wie viele
Datensätze verschwinden, statt nur „3 Tabellen". Der `uninstall()`-Hook räumt
zusätzlich die Zeilen weg, die dieses Addon in Kern-Tabellen hinterlassen hat:
die beiden Mengenzähler in `login_attempts` und den Zeitstempel der eigenen
Cron-Aufgabe. Die Protokolleinträge bleiben **bewusst** stehen: Sie sind der
Nachweis darüber, was mit personenbezogenen Anfragen geschehen ist, und ein
Nachweis, den das Deinstallieren mitnimmt, ist keiner.

Die Aufbewahrungsfrist und der Zweck gehören in die Datenschutzerklärung der
Installation (Kern-Seite `/datenschutz`) - das kann ein Addon nicht für den
Betreiber tun.

## Technik

- **Hooks:** `contact.detail_sections` (Formular), `contact.edit_sections`
  (Opt-out je Kontakt), `admin.dashboard_tiles` (Kachel). Ausdrücklich **nur**
  die `contact.*`-Namen: Der Kern löst `person.*` und `station.*` bis v0.9.0
  zusätzlich als kaskadierenden Alias aus, und seit beide Datensatzarten eine
  Tabelle sind, erschiene das Formular sonst zweimal auf derselben Seite.
- **Berechtigungen:** Modul `kontaktanfrage`, Aktion `manage` für die
  Verwaltung. Das Opt-out hängt dagegen an `contacts.edit` - wer einen Kontakt
  bearbeiten darf, darf über dessen Erreichbarkeit entscheiden, ohne alle
  eingegangenen Anfragen lesen zu dürfen.
- **Routen:** `/plugin/kontaktanfrage/senden` (POST, öffentlich),
  `/plugin/kontaktanfrage/verwaltung` (GET) mit
  `/verwaltung/einstellungen`, `/verwaltung/weiterleiten`,
  `/verwaltung/loeschen`, `/verwaltung/aufraeumen` (POST) sowie
  `/plugin/kontaktanfrage/opt-out` (POST). Die Anfragenliste ist mit 50
  Einträgen je Seite paginiert (`?seite=…`).
- **Tabellen** (angelegt im `install()`-Hook, idempotent):
  `plugin_kontaktanfrage_requests`, `plugin_kontaktanfrage_optout`
  (Anwesenheit einer Zeile *ist* das Opt-out) und
  `plugin_kontaktanfrage_config`. Beide Zieltabellen speichern eine
  `contact_id`, bewusst **ohne** Fremdschlüssel auf `contacts`: Eine Anfrage
  ist ein Vorgang und soll den Datensatz überleben, auf den sie sich bezog -
  ein Fremdschlüssel mit `CASCADE` löschte sie mit, einer ohne verhinderte das
  Löschen des Kontakts. Zeilen endgültig gelöschter Kontakte zeigt die
  Verwaltung als „Datensatz entfernt"; verwaiste Opt-outs räumt die
  Cron-Aufgabe mit weg. Opt-outs weich gelöschter Kontakte bleiben erhalten,
  damit eine Wiederherstellung sie nicht verliert.
- **Eigene Konfigurationstabelle** statt der Kern-Tabelle `settings`: deren
  Schlüsselspalte ist `VARCHAR(50)`, und die Systemeinstellungen sind eine
  redaktionell gepflegte Oberfläche, in die ein Addon keine Fremdschlüssel
  streuen sollte.
- **Einmalige Umrechnung der gespeicherten Ziele (#136).** Bis v0.7 hielten
  beide Tabellen ihr Ziel als `(target_type, target_id)` mit
  `target_type = 'person'|'station'` - also mit einem *eigenen*
  Diskriminator neben dem des Kerns. Mit Kern-#336 gibt es die Unterscheidung
  nicht mehr: Personen behalten bei der Zusammenführung ihre Kennung,
  Deckstationen bekommen neue oberhalb des Personenbestands. Eine nicht
  umgerechnete Stationszeile zeigte deshalb nicht ins Leere, sondern auf eine
  **fremde Person** - beim Opt-out hieße das, der Abbestellende wäre wieder
  erreichbar und ein Unbeteiligter stumm geschaltet. `install()` rechnet die
  Zeilen deshalb einmalig über die dauerhaft stehenbleibende Zuordnungstabelle
  `contact_id_map` um.

  Die Umrechnung ist **nicht wiederholbar** - sie schreibt eine neue Kennung
  in dieselbe Zeile, und ein zweiter Lauf läse die bereits umgerechnete
  Kennung wieder als alte Stationskennung. `install()` läuft aber bei jeder
  Aktivierung erneut. Deshalb hält ein Marker in
  `plugin_kontaktanfrage_config` fest, dass sie gelaufen ist, und er wird in
  **derselben Transaktion** geschrieben wie die Umrechnung selbst: Ein Abbruch
  nimmt beides zurück, ein Erfolg hält beides fest, einen Zwischenstand gibt
  es nicht. Steht der Kern noch auf der 0.7-Linie (keine `contact_id_map`)
  oder ist sie noch leer, während Altzeilen vorliegen, passiert **nichts** und
  der Marker bleibt ungesetzt - „konnte nicht" und „war nichts zu tun" sind
  verschiedene Aussagen. Anfragen ohne Abbildung behalten `contact_id = 0` und
  erscheinen als „Datensatz entfernt"; Opt-outs ohne Abbildung werden
  entfernt, weil ein Opt-out eine Aussage über einen Datensatz ist, den es
  nicht mehr gibt.
- **Sichtbarkeitsregel doppelt angewandt:** Anzeige und Verarbeitung prüfen
  beide auf `is_published = 1` und `deleted_at IS NULL` sowie auf das
  Opt-out - sonst liefe ein direkter POST mit gültigem Token an einem bewusst
  unveröffentlichten Datensatz vorbei.
- **Ein nachträgliches Opt-out gilt rückwirkend:** Eine bereits gespeicherte
  Anfrage lässt sich danach nicht mehr weiterleiten. Eine gespeicherte
  Anfrage ist kein Freibrief, eine später erklärte Ablehnung zu übergehen.
- **Reine Logik ohne Datenbank** (`Eingabe`, `Gruende`) ist als Unit-Test
  festgenagelt: `tests/Unit/KontaktanfrageEingabeTest.php` und
  `tests/Unit/KontaktanfrageGruendeTest.php`. Die Umrechnung und ihr Marker
  stehen in `tests/Functional/KontaktanfragePluginTest.php`.

## Bekannte Einschränkungen

- `App\Service\Mailer::send()` kennt keinen `Reply-To`-Header. Die Adresse des
  Anfragenden steht deshalb gut sichtbar im Nachrichtentext, damit
  Team und Empfänger manuell darauf antworten können.
- **Kein Double-Opt-in der Absenderadresse.** Eine unbestätigte Adresse kann
  falsch sein - das trifft aber nur das Team, das dann ins Leere antwortet,
  nicht die angefragte Person: Weitergeleitet wird ausschließlich nach
  Prüfung durch einen Menschen. Ein Bestätigungslauf würde eine zweite
  E-Mail an eine ungeprüfte Adresse versenden und damit genau den Kanal
  aufmachen, den die Team-Adresse zumacht.
- **Das Formular erscheint unabhängig davon, ob beim Kontakt eine E-Mail
  hinterlegt ist.** Das ist Absicht: Sichtbarkeit nur bei vorhandener Adresse
  wäre eine öffentliche Auskunft darüber, wer eine hinterlegt hat. Das Team
  kann den Kontakt auch auf anderem Weg erreichen; die Verwaltung zeigt, dass
  eine Weiterleitung per E-Mail nicht möglich ist.
- **Mengenzähler wandern nicht mit.** Die Zähler des Empfänger-Limits liefen
  bis v0.7 unter `person:5`/`station:5` und laufen jetzt unter `kontakt:5`.
  Ein laufendes Fenster beginnt mit der Umstellung also von vorn - das
  Empfänger-Limit greift über 24 Stunden und läuft ohnehin von selbst aus, ein
  Nachziehen wäre Aufwand für einen Effekt, der sich binnen eines Tages
  erledigt.
