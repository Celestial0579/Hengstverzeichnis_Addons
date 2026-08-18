# kontaktanfrage

Ein Besucher kann eine Person oder eine Deckstation kontaktieren, **ohne
deren Adresse zu sehen**. Das Formular auf der öffentlichen Personen- bzw.
Deckstationsseite fragt genau drei Angaben ab: E-Mail des Anfragenden, Name
und einen **Grund aus fester Auswahl** - kein Freitext.

Löst [Celestial0579/Hengstverzeichnis_Addons#106](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/106).

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

**1. Zugestellt wird immer an die Team-Adresse, nie direkt an die angefragte
Person.** Das Team prüft die Anfrage und fragt die Person, ob Kontakt
überhaupt gewünscht ist. Ein Formular, das ungeprüft an eine fremde Adresse
zustellt, ist ein Spam-Relais mit dem Verzeichnis als Adressbuch.

**2. Der Grund kommt aus einer abgeschlossenen Liste, es gibt kein
Nachrichtenfeld.** Nur so setzt der Server die Nachricht aus geprüften
Bausteinen zusammen; vom Absender kommt ausschließlich, was gegen die Liste
geprüft werden kann. Wer später „nur ein kleines Bemerkungsfeld" ergänzt,
gibt genau diesen Schutz auf.

**3. Opt-out statt Opt-in.** Kontaktanfragen sind erlaubt und lassen sich je
Datensatz abschalten. Das Kennzeichen gehört dem Addon (eigene Tabelle), der
Kern bekommt dafür keine Spalte.

## Nutzung

1. **Besucher** öffnet `/person?id=…` oder `/station?id=…`, wählt einen Grund,
   trägt Name und E-Mail ein und sendet ab. Die Anfrage geht an das Team.
2. **Team** sieht sie unter **Dashboard → Kontaktanfragen**, klärt mit der
   angefragten Person und drückt dort **Weiterleiten** - erst damit erfährt
   die Person von der Anfrage, mit Name, E-Mail und Grund des Anfragenden.
3. **Redaktion** kann Anfragen für einen Datensatz abschalten: im
   Bearbeitungsformular der Person bzw. Deckstation, Abschnitt
   „✉️ Kontaktanfragen", Häkchen entfernen und übernehmen.

Gründe sind vorbelegt mit *Deckanfrage*, *Kaufinteresse*, *Frage zur
Abstammung* und *Sonstiges*; weitere trägt der Admin in den Addon-
Einstellungen ein (einer je Zeile, höchstens 30). Der gespeicherte Schlüssel
wird aus dem Anzeigetext abgeleitet und ist damit stabil gegen Umsortieren;
der Anzeigetext wird zusätzlich in der Anfrage mitgespeichert, sodass ein
später umbenannter Grund lesbar bleibt.

## Missbrauchsschutz

Ein offenes Kontaktformular ist binnen Tagen ein Spam-Ziel, deshalb von
Anfang an vier Hürden statt einer:

- **CSRF-Token** (`\App\Router::generateCsrfToken()`/`verifyCsrfToken()`) als
  erste Prüfung jedes schreibenden Endpunkts.
- **Honeypot-Feld**: für Menschen unsichtbar; füllt ein Bot es aus, meldet
  der Server scheinbar Erfolg und verwirft die Anfrage.
- **Rate-Limit je IP (5/Stunde) und je Empfänger (10/Tag)** über
  `App\Security\RateLimiter`. Zwei Zähler, weil sie zwei verschiedene
  Missbräuche treffen: Der IP-Zähler bremst den einzelnen Absender, der
  Empfänger-Zähler verhindert, dass eine Person über wechselnde Anschlüsse
  zugemüllt wird. Einer allein wäre jeweils leicht zu umgehen.
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
Setzen/Aufheben eines Opt-outs steht im Audit-Log (Kategorie
`kontaktanfrage`), ebenso eine wegen Rate-Limit abgewiesene Anfrage.

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
Blick in die Kontaktdaten der Personen; das Recht dazu ist `persons.view` und
hängt nicht an diesem Addon.

Die Aufbewahrungsfrist und der Zweck gehören in die Datenschutzerklärung der
Installation (Kern-Seite `/datenschutz`) - das kann ein Addon nicht für den
Betreiber tun.

## Technik

- **Hooks:** `person.detail_sections` und `station.detail_sections` (Formular),
  `person.edit_sections` und `station.edit_sections` (Opt-out je Datensatz),
  `admin.dashboard_tiles` (Kachel).
- **Berechtigungen:** Modul `kontaktanfrage`, Aktion `manage` für die
  Verwaltung. Das Opt-out hängt dagegen an `persons.edit` bzw.
  `breeding_stations.edit` - wer eine Person bearbeiten darf, darf über deren
  Erreichbarkeit entscheiden, ohne alle eingegangenen Anfragen lesen zu
  dürfen.
- **Routen:** `/plugin/kontaktanfrage/senden` (POST, öffentlich),
  `/plugin/kontaktanfrage/verwaltung` (GET) mit
  `/verwaltung/einstellungen`, `/verwaltung/weiterleiten`,
  `/verwaltung/loeschen`, `/verwaltung/aufraeumen` (POST) sowie
  `/plugin/kontaktanfrage/opt-out` (POST). Die Anfragenliste ist mit 50
  Einträgen je Seite paginiert (`?seite=…`).
- **Tabellen** (angelegt im `install()`-Hook, idempotent):
  `plugin_kontaktanfrage_requests`, `plugin_kontaktanfrage_optout`
  (Anwesenheit einer Zeile *ist* das Opt-out) und
  `plugin_kontaktanfrage_config`. Bewusst **ohne** Fremdschlüssel auf
  `persons`/`breeding_stations`: Das Ziel ist polymorph (`target_type` +
  `target_id`), ein Fremdschlüssel kann nur auf eine Tabelle zeigen. Zeilen
  endgültig gelöschter Datensätze zeigt die Verwaltung als „Datensatz
  entfernt"; verwaiste Opt-outs räumt die Cron-Aufgabe mit weg. Opt-outs weich
  gelöschter Datensätze bleiben erhalten, damit eine Wiederherstellung sie
  nicht verliert.
- **Eigene Konfigurationstabelle** statt der Kern-Tabelle `settings`: deren
  Schlüsselspalte ist `VARCHAR(50)`, und die Systemeinstellungen sind eine
  redaktionell gepflegte Oberfläche, in die ein Addon keine Fremdschlüssel
  streuen sollte.
- **Sichtbarkeitsregel doppelt angewandt:** Anzeige und Verarbeitung prüfen
  beide auf `is_published = 1` und `deleted_at IS NULL` sowie auf das
  Opt-out - sonst liefe ein direkter POST mit gültigem Token an einem bewusst
  unveröffentlichten Datensatz vorbei.
- **Ein nachträgliches Opt-out gilt rückwirkend:** Eine bereits gespeicherte
  Anfrage lässt sich danach nicht mehr weiterleiten. Eine gespeicherte
  Anfrage ist kein Freibrief, eine später erklärte Ablehnung zu übergehen.
- **Reine Logik ohne Datenbank** (`Eingabe`, `Gruende`) ist als Unit-Test
  festgenagelt: `tests/Unit/KontaktanfrageEingabeTest.php` und
  `tests/Unit/KontaktanfrageGruendeTest.php`.

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
- **Das Formular erscheint unabhängig davon, ob beim Datensatz eine E-Mail
  hinterlegt ist.** Das ist Absicht: Sichtbarkeit nur bei vorhandener Adresse
  wäre eine öffentliche Auskunft darüber, wer eine hinterlegt hat. Das Team
  kann die Person auch auf anderem Weg erreichen; die Verwaltung zeigt, dass
  eine Weiterleitung per E-Mail nicht möglich ist.
