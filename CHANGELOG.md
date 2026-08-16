# Changelog

Alle nennenswerten Änderungen an den offiziellen Addons werden in dieser
Datei dokumentiert, je Release nach Addon gruppiert. Das Format orientiert
sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/); die
Release-Tags `vX.Y.z` folgen der Framework-Linie `X.Y`
(siehe [docs/releasing.md](docs/releasing.md)).

## [Unreleased]

### Hinzugefügt

- **Funktionstest für `embed-widget`** (`tests/Functional/EmbedWidgetPluginTest.php`).
  Er fehlte, weil das Addon `core_compatibility: ">=0.5.1"` verlangt und der
  Kern `0.5.0` meldete - der PluginManager wies es fail-closed ab, es liess
  sich auf keiner Instanz aktivieren und damit auch nicht testen. Mit
  Framework 0.5.1 (dortiger PR #285) greift es. Der Test prüft genau das,
  was die Versionsschranke zusichern soll: dass die Aktivierung gelingt.
  Dazu die Zugriffsgrenzen (Anonyme und Redakteure ohne
  `embed-widget.manage` kommen nicht an den Generator), dass der Schnipsel
  **escaped** ausgegeben wird - er soll gelesen und kopiert, nicht ausgeführt
  werden -, und dass ohne freigegebene Domain **keine** Live-Vorschau
  gerendert wird, die im eigenen Tab funktioniert und beim Empfänger nicht.

### Geändert

- **`SECURITY.md`: Angabe zu unterstützten Versionen berichtigt.** Dort stand
  weiterhin, das Repository veröffentliche keine Tags oder Releases - v0.4.0,
  v0.4.1 und v0.5.0 existieren, und `docs/releasing.md` beschreibt den
  Tag-Prozess. Für einen Melder ist das keine Kleinigkeit: Die Angabe
  entscheidet, gegen welchen Stand er prüft und was er in seiner Meldung
  angibt.

### Sicherheit

- **datenmigration: Import kann keinen ausführbaren Code mehr in den Webroot
  legen.** Die Pfadhärtung beim Entpacken prüfte Traversal, absolute Pfade und
  NUL - also WOHIN geschrieben wird, aber nicht WAS. Ziel war
  `public/uploads.import-neu` (im Webroot), und der abschließende
  Verzeichnistausch ersetzte `public/uploads` samt der `.htaccess`, die dort
  die PHP-Ausführung abschaltet. Ein Archiv mit einer `.php` darin genügte für
  Codeausführung - ausgelöst von einem Benutzer ohne Administratorrechte.
  - Das Staging liegt jetzt **außerhalb** von `public/`
    (`var/datenmigration/uploads-neu`); zwischen erstem Schreiben und
    Umschalten ist nichts über den Webserver erreichbar.
  - Neue `UploadNamePolicy`: Positivliste erlaubter Endungen plus eine Liste
    ausführbarer Endungen, die an **jeder** Stelle des Namens unzulässig sind
    (`bild.php.jpg` wird von einem Apache mit `AddHandler` ausgeführt).
  - Die Schutz-`.htaccess` wird nach dem Umschalten neu geschrieben; eine im
    Archiv enthaltene wird verworfen statt übernommen - ein echter Export
    enthält sie zwangsläufig, sie darf den Import also weder abbrechen noch
    den Ausführungsschutz bestimmen.
- **Export und Import verlangen zusätzlich Administratorrechte.** Die
  Berechtigungen `datenmigration.export`/`.import` sahen aus wie jede andere
  Modulberechtigung und ließen sich an jede Gruppe vergeben - ihre Wirkung ist
  eine andere: Export liefert den vollständigen Datenbank-Dump inklusive
  `users` (Passwort-Hashes, TOTP-Secrets) und `api_keys`; Import ersetzt die
  gesamte Datenbank, also auch die Benutzertabelle, womit sich ein Angreifer
  mit einem selbst gebauten Archiv zum Administrator machen kann. Im Kern sind
  vergleichbare Fähigkeiten (Backup, Update, Systemreset) bewusst admin-only.
  Die Berechtigung bleibt erhalten, sie genügt nur nicht mehr allein.

### Behoben

- **datenmigration: Import läuft unter Wartungsmodus und rollt bei einem
  Fehler zurück.** Der Dump wirft jede Tabelle einzeln weg und legt sie neu
  an; im Fenster dazwischen trafen parallele Anfragen auf eine halb ersetzte
  Datenbank. DDL ist in MariaDB autocommittend, eine Transaktion gibt es hier
  also nicht - der Wartungsmodus des Kerns (`App\Service\Maintenance`) ist die
  richtige Antwort. Schlägt das Einspielen fehl, wird der unmittelbar zuvor
  geschriebene Sicherungs-Dump automatisch zurückgespielt; scheitert auch
  das, sagt das Audit-Log genau, welche Datei von Hand einzuspielen ist.
- **Der Verzeichnistausch fällt auf Kopieren zurück**, wenn `rename()` über
  eine Dateisystemgrenze scheitert - seit das Staging in `var/` liegt, ist das
  kein theoretischer Fall mehr (eigenes Volume für `uploads`).

### Geändert

- `security/plugin-security-scan.sh`: Das Muster für dynamische
  `include`/`require` verlangt jetzt Whitespace oder eine öffnende Klammer
  nach dem Schlüsselwort. Vorher traf es jeden Methodennamen, der damit
  beginnt und eine Variable im Argument hat (`requireAdminForFullAccess(string
  $aktion)`, `$this->requirePermission($modul, $aktion)`). Ein Gate, das bei
  sauberem Code ausschlägt, wird umbenannt statt behoben - und dann fällt der
  echte Fund beim nächsten Mal nicht mehr auf.

- **gesundheitstests: Der Verwaltungs-Zweig des Dokument-Downloads prüft die
  Sitzung jetzt wie das übrige Backend.** `isset($_SESSION['user_id'])` fragt
  nur, ob irgendwann einmal jemand angemeldet war - eine Sitzung, deren Konto
  gelöscht wurde, deren Passwort anderswo geändert wurde (Framework #113),
  die von einem anderen User-Agent kommt oder die längst abgelaufen ist, flog
  über `checkAuth()` überall hinaus, lieferte hier aber weiterhin
  Gesundheitsdokumente aus. `checkAuth()` wird nur betreten, wenn der
  öffentliche Opt-in-Pfad nicht greift - für einen anonymen Abruf eines
  freigegebenen Dokuments wäre eine Umleitung auf `/login` die falsche
  Antwort.
- **galerie: Video-Links werden aus den geprüften Teilen neu gebaut**, statt
  die Eingabe durchzureichen. Geprüft wird mit PHPs `parse_url()`, angezeigt
  wird die Zeichenkette im `<iframe src>` des Browsers; solange die Eingabe
  unverändert weitergereicht wird, hängt die Allowlist daran, dass beide
  Parser jede Eingabe gleich lesen. Benutzerinfo und Fragment fallen beim
  Neubau weg, Steuer- und attributbrechende Zeichen führen zur Ablehnung.
  (Der ursprünglich vermutete Rückwärtsschrägstrich-Trick greift bei
  `parse_url()` übrigens nicht - der Host wird korrekt als `evil.tld` erkannt
  und abgelehnt. Die Härtung schließt die Fehlerklasse, nicht den Einzelfall.)

### Behoben

- **Sieben Addons setzten pro Anfrage eine überflüssige Datenbankabfrage ab.**
  `register()` prüfte mit `SELECT 1 FROM <tabelle> LIMIT 1`, ob die eigene
  Tabelle existiert, und holte sonst `install()` nach - ein Rückfall für Kerne
  ohne den `install()`-Hook (Framework #75). Den gibt es nicht mehr: Die
  `core_compatibility`-Untergrenze in `plugin.json` verlangt eine Kern-Version,
  die ihn sicher hat. Geblieben war nur der Preis, bei allen sieben aktivierten
  Addons sieben zusätzliche Roundtrips, bevor die erste Zeile der Seite steht.
- **zuchtschau-ergebnisse: drei unbegrenzte Abfragen gedeckelt.** Die Übersicht
  lud den kompletten Pferdebestand, alle Ergebnisse **und** alle
  Teilwertungen - letztere, um höchstens ein paar Dutzend sichtbare Zeilen zu
  beschriften. Jetzt: Pferdeauswahl auf 500, Ergebnisliste auf die 200
  neuesten (mit sichtbarem Hinweis), Teilwertungen nur noch für die tatsächlich
  angezeigten Ergebnis-IDs.
- **genealogie-vergleich: die anonyme Route lud den gesamten veröffentlichten
  Bestand** und rendert ihn zweimal, einmal je Auswahlfeld - auch dann, wenn
  gar kein Vergleich angefordert war. Jetzt gedeckelt; bereits gewählte Pferde
  werden gezielt nachgeladen, damit die Auswahl beim nächsten Seitenaufruf
  nicht still leer wird.

### Geändert (Prüfkette)

- **Semgrep läuft jetzt auch über die Addons** (`.github/workflows/semgrep.yml`,
  Aufbau identisch zum Kern). Die einzige PHP-Analyse hier war bisher
  `security/plugin-security-scan.sh` - 144 Zeilen grep. Der Scanner ist gut
  darin, wofür er gebaut wurde, arbeitet aber zeilenweise und ohne Datenfluss:
  Mehrzeilig zusammengesetztes SQL sieht er nicht, und ob ein interpolierter
  Wert aus einem Literal oder aus `$_GET` stammt, kann er nicht unterscheiden.
  Die rund 8.000 Zeilen PHP in `plugins/` liefen damit ohne die Analyse, die
  der Kern längst hat - obwohl sie im selben Prozess mit denselben Rechten
  laufen. Der Gate-Scan nutzt `--error`, weil `semgrep scan` sich sonst auch
  bei Funden mit Exit 0 beendet (dieselbe Falle, in die der Kern schon einmal
  gelaufen ist).
  - **Der erste Lauf hat prompt fünf ERROR-Funde geliefert - alle fünf sind
    geprüfte Falschbefunde und einzeln begründet unterdrückt.** Dreimal
    `tainted-sql-string` auf `value="' . $page . '"`: Das ist HTML und kein
    SQL, und `$page` entsteht aus `min($pageCount, max(1, (int) $_GET['seite']))`
    - Semgreps Taint-Analyse erkennt den `(int)`-Cast nicht als Bereinigung
    (derselbe Grund wie beim einzigen `nosemgrep` des Kerns). Einmal auf dem
    Platzhalter-String `?,?,?` in `merkliste` und einmal auf
    `echo $this->renderNode($tree)` in `pedigree-export`, wo die Funktion jeden
    Wert selbst escaped. Jede Unterdrückung nennt die Regel-ID und den Grund;
    eine pauschale Ausnahme gibt es nicht.
- **pre-commit läuft in der CI** (`.github/workflows/pre-commit.yml`). Bisher
  rein lokal - wer die Hooks nicht installiert hatte, umging gitleaks und
  shellcheck vollständig.
- **shellcheck-Falschbefund gekennzeichnet:** Die Suchmuster in
  `plugin-security-scan.sh` stehen in einfachen Anführungszeichen, weil das
  `$` darin ein Regex-Anker ist und kein Shell-Variablenname. Doppelte
  Anführungszeichen würden die Muster von der Shell expandieren lassen und die
  Prüfung lautlos entkernen - deshalb eine begründete `disable`-Direktive
  statt einer Umschreibung.

### Hinzugefügt

- **Neues Addon `embed-widget` (1.0.0):** erzeugt im Admin-Bereich den
  fertigen iframe-Schnipsel, mit dem sich der öffentliche Pferdekatalog auf
  einer fremden Website einbetten lässt (#89) — der Anwendungsfall, für den
  Kern-#260 die Voraussetzung geschaffen hat.
  - **Baut den Katalog bewusst NICHT nach.** Der Kern rendert ihn seit
    #260/#264 selbst einbettbar (`/katalog?embed=1` über `layout_embed.php`),
    inklusive Filter, Nachladen, Sprachen, Bildauslieferung mit
    `is_published`-Prüfung und dem Hook `catalog.card_sections`. Ein Nachbau
    hätte all das dupliziert und wäre bei jeder Kern-Änderung zurückgefallen.
    Das Addon liefert den Weg dorthin: absolute Adresse, Vorfilter, Maße.
  - **Sagt vorher, was sonst als Fehler zurückkommt.** Ohne
    `EMBED_ALLOWED_DOMAINS` bleibt der Rahmen beim Empfänger leer — das ist
    beabsichtigter Clickjacking-Schutz und keine Störung. Die Seite nennt den
    Zustand, die nötige Einstellung und die aktuell freigegebenen Domains.
    Fehlt `base_url`, wird das ebenfalls benannt statt ersatzweise der
    Host-Header genommen: Der ist vom Aufrufer bestimmbar, und ein daraus
    gebauter Schnipsel zeigte stillschweigend auf eine falsche Domain.
  - Vorfilter (Suche, Rasse, Farbe, Deckstation, Züchter, Besitzer,
    Geschlecht) als Adressparameter; der Besucher kann im Rahmen weiter
    filtern. Maße mit Grenzen, feste Höhe — ein iframe wächst nicht mit
    seinem Inhalt, und dieses Addon liefert dafür bewusst **kein** Skript zum
    Einbinden auf der fremden Seite: Das ist eine andere Vertrauensfrage als
    ein Rahmen.
  - Deklariert ehrlich `core_compatibility: >=0.5.1`. Der eingebundene Kern
    weist noch `CORE_VERSION 0.5.0` aus (der Versionsstring wird erst beim
    nächsten Kern-Release nachgezogen), weshalb `PluginManager` das Addon
    derzeit fail-closed abweist. Die Logik ist deshalb framework-frei in
    `EmbedCode` gekapselt und dort geprüft; der Lebenszyklus-Test gehört
    nachgezogen, sobald der Kern 0.5.1 ausweist.

### Geändert

- **Release-Gate prüft gegen die echte Tag-Version statt gegen `X.Y.0`.**
  `scripts/check-release-consistency.php` leitete bisher stur die
  Linien-Untergrenze ab: Ein Release `v0.5.1` wurde gegen `0.5.0` geprüft.
  Damit fiel jedes Addon durch, das eine erst im Patch-Release dazugekommene
  Kern-Funktion braucht — es hätte `>=0.5.0` behaupten müssen, obwohl es dort
  nachweislich nicht läuft. Aufgefallen am Embed-Widget (siehe oben).
  Die Obergrenze `core_supported_max` bleibt bewusst auf `Major.Minor`: Sie
  sagt „bis zu dieser Linie geprüft" und soll nicht bei jedem Patch-Release
  nachgezogen werden müssen. Ein Test hält beide Richtungen fest (`>=0.5.1`
  geht für `v0.5.1` durch und für `v0.5.0` nicht) — nachgewiesen, dass er mit
  dem alten Gate rot ist.

- **Galerie (1.2.0):** Medienpflege hängt jetzt über `horse.edit_sections`
  direkt im Bearbeitungsformular des Hengstes (#88). Wer Medien zu *einem*
  Pferd pflegt, musste dafür bisher die bestandsweite Verwaltungsseite öffnen
  und das Pferd dort erneut heraussuchen, obwohl er längst in dessen Datensatz
  stand. Die `horse_id` kommt jetzt aus dem Aufrufkontext.
  - Der Abschnitt bringt sein eigenes `enctype="multipart/form-data"`-Formular
    mit — ohne die Kodierung käme der Upload als leeres `$_FILES` an, ohne
    Fehlermeldung.
  - Bild **oder** Video-Link, mit ausdrücklichem Hinweis: Bei beidem gewinnt
    der Upload, der Link verfiele sonst stillschweigend.
  - **Keine Lightbox** im Abschnitt — sie hängt an JS/CSS der öffentlichen
    Detailseite und wäre im Bearbeitungsformular funktionslos.
  - Nach dem Anlegen und Löschen geht es zurück in den Pferdedatensatz, nicht
    auf die Verwaltungsseite.
  - Die Verwaltungsseite bleibt als bestandsweite Übersicht bestehen, und auf
    einem Kern ohne den Hook passiert schlicht nichts.
- `composer.lock` hebt `hengstverzeichnis/framework` von `e1f760b` auf
  `7f54071` (Stand Kern-`main`). Damit laufen Suite und CI wieder gegen den
  Kern, gegen den die Addons tatsächlich betrieben werden.

### Behoben

- **Weitere Lebensnummern wurden von zwei Addons nicht mitgesucht** (#91).
  Der Kern zieht seit Framework #246 die Kindtabelle `horse_registrations`
  überall dort mit heran, wo `ueln`/`foreign_ueln` durchsucht wird. Zwei
  Addons, die Kern-Abfragen spiegeln, taten das nicht:
  - *Anpaarungs-Empfehlung:* Der kantenbasierte `AncestorTreeBuilder` löste
    einen Freitext-Elternteil nur über `ueln`/`foreign_ueln` auf. Ein Pferd,
    dessen Elternteil über eine weitere Lebensnummer referenziert ist, wurde
    dort als Platzhalter statt als echter Ahn geführt — mit abweichendem
    Abstammungsbaum und damit abweichendem Inzuchtkoeffizienten gegenüber dem
    Kern. Der Gleichlauf-Test gegen `PedigreeBuilder` deckt den Fall jetzt ab
    (auch die Gegenprobe: die Nummer eines gelöschten Pferdes darf *nicht*
    auflösen).
  - *Katalog-Export:* Volltext- und UELN-Suche fanden ein Pferd nicht, das
    der Katalog über dieselbe Nummer findet.

### Titel & Prämierungen (1.1.0)

- Erfassung direkt im Bearbeitungsformular des Hengstes (#87, nutzt den neuen
  Kern-Hook `horse.edit_sections` aus Framework#255). Im Pferdekontext ist die
  `horse_id` durch die Seite gegeben — die Auswahl über den gesamten Bestand
  entfällt dort ersatzlos, geladen wird nur noch, was zu diesem einen Pferd
  gehört.
- Die bestandsweite Verwaltungsseite **bleibt** und lädt nicht mehr den
  gesamten Bestand: Die Pferdeauswahl läuft über ein Textfeld mit
  `<datalist>` und AJAX-Suche (höchstens 50 Treffer, Muster aus der Galerie),
  die Liste der Einträge paginiert. Das war der zweite, im Issue nicht
  genannte Vollscan — ohne ihn wäre #87 nur halb behoben.
  Die Seite ganz zu streichen kam nicht in Frage: Auf einem Kern ohne den Hook
  gäbe es sonst überhaupt keinen Weg mehr, eine Auszeichnung zu erfassen.
- Ohne JavaScript löst `store()` den getippten Text serverseitig zu einer
  Pferde-ID auf; der Rückweg nach Speichern/Löschen wird aus einem Schalter und
  einer geprüften Integer gebaut, nie aus einer übergebenen Adresse.

## [0.5.0] – 2026-08-11

Alle 17 Addons deklarieren jetzt die Kern-Linie 0.5 (`core_supported_max`,
Patch-Version-Bump je Plugin) — passend zum Framework-Release v0.5.0. Ohne
diesen Bump würde ein 0.5-Kern die Addons fail-closed als inkompatibel
ablehnen.

### Datenmigration (NEU, 1.0.x)

- Vollständiger Instanz-Umzug Framework → Framework (#80): Export bündelt
  Datenbank (DatabaseDumper), sämtliche Uploads und ein Manifest
  (Kern-Version, Plugin-Bestand, Zählstände) in ein tar(.gz)-Archiv —
  eigener streamender ustar-Schreiber, bewusst ohne ext-zip. Import auf der
  Zielinstanz zweistufig (Prüfseite mit Versions-/Plugin-Abgleich →
  ausdrückliche Bestätigung), mit Sicherungs-Dump vor dem Anwenden,
  Pfadhärtung gegen Archiv-Traversal und Sitzungsende nach der
  Benutzerübernahme. Große Archive laufen am PHP-Upload-Limit vorbei über
  `var/datenmigration/`.

### Titel & Prämierungen (NEU, 1.0.x)

- Strukturierte Auszeichnungen je Pferd (#81): Tabelle
  `plugin_titel_praemierungen` (Art: Titel/Prämierung/Erfolg, Bezeichnung,
  Jahr, Kommentar), Detail-Sektion nach Art gruppiert, CRUD mit eigener
  Berechtigung, Dashboard-Kachel. Zielstruktur für die v1/v2-Altdaten
  (258 Titel-, 125 Prämierungs-, 104 Erfolgs-Listen), die bisher nur als
  Beschreibungstext ankommen konnten.

### Zuchtschau-Ergebnisse (1.2.x)

- Teilwertungen strukturiert statt im Kommentarfeld (#82): Neue Kindtabelle
  `plugin_zuchtschau_teilwertungen` (`ergebnis_id` FK mit
  `ON DELETE CASCADE`; `bezeichnung`, `wertung`, `note`, `platzierung`,
  `distanz`, `zeit` - alle Fachspalten NULL-tolerant, die Altdaten aus
  v1/v2 sind lückig). Pflege (anlegen/löschen) im Admin-Bereich unterhalb
  der jeweiligen Ergebniszeile, Anzeige auf der öffentlichen Detailseite
  unterhalb des Ergebnisses. `install()` legt beide Tabellen idempotent an;
  die `SELECT`-Probe des Fallbacks für Kerne ohne `install()`-Hook zielt
  jetzt auf die jüngste Tabelle, damit Bestandsinstallationen die
  Kindtabelle nachziehen. Plugin-Version 1.2.0

## [0.4.1] – 2026-08-10

### Farbvererbung

- Freitext-Zuordnung erkennt auch die adjektivischen Kurzformen ohne
  End-e („graufalb", „rotfalb", „braunfalb", „gelbfalb", „hellfalb",
  „weißfalb") - die Nadeln sind jetzt Wortstämme, die Substantivformen
  („Graufalbe" …) bleiben als Obermenge abgedeckt. Neue Unit-Testfamilie
  für `keyFromText()` inkl. Negativfällen („Grauschimmel" bleibt ohne
  Falb-Deutung); Plugin-Version 1.1.2

## [0.4.0] – 2026-08-10

### Alle Addons

- Eigenständige Plugin-Seiten rendern im Haupt-Layout des Frameworks
  (`App\Plugin\PluginPage::render()`, Addons#66): mit Header, Navigation,
  Footer, Theme-Umschalter und den admin-konfigurierten Markenfarben statt
  als eigenständige, unthemebare HTML-Dokumente. Formulare und Buttons
  nutzen die gemeinsamen Framework-Klassen, Farben ausschließlich
  Theme-Variablen. Dokumentierte Ausnahmen mit Marker
  `/* theming-ausnahme: ... */`: Druckansichten von `pedigree-export` und
  `qr-code`, Lightbox-Scrim der `galerie`, funktionales QR-Schwarz/Weiß.
  Alle Addon-Versionen auf 1.1.0 gehoben
- Manifeste: `core_compatibility` auf `">=0.4.0"` (die Layout-Integration
  braucht den Kern-Dienst aus Framework 0.4) und neues **Pflichtfeld**
  `"core_supported_max": "0.4"` (höchste unterstützte Kern-Linie,
  Framework#197)
- Neues Test-Gate `tests/Manifest/PluginThemingLintTest.php`: verbietet
  eigenständige Dokumente, eigene Schriftarten, rohe Farbwerte und
  Radius-Hardcodes außerhalb markierter Ausnahmen - läuft automatisch über
  jedes Verzeichnis unter `plugins/`
- Pferde-Auswahl ohne Voll-`<select>` (#74): Die Formulare von Galerie,
  Gesundheitstests, Verkaufsbörse, Inzuchtkoeffizient,
  Anpaarungs-Empfehlung und Farbvererbung laden nicht mehr den gesamten
  Pferdebestand in Auswahllisten, sondern nutzen ein Suchfeld mit Datalist
  an einer eigenen JSON-Suchroute (`/plugin/<slug>/suche`, max. 50
  Treffer, `[#id]`-Zusatz bei Namensgleichheit, berechtigungsgeschützt,
  No-JS-Fallback über serverseitige Namensauflösung); lange Listen sind
  paginiert bzw. gedeckelt
- Tabellenanlage nicht mehr in jedem Request (#75): Die sechs Addons mit
  eigener Tabelle richten sie über den neuen `install()`-Hook des Kerns
  ein (Framework 0.4.0); für ältere Kerne bleibt ein Fallback, der statt
  des DDL-Statements nur noch eine billige `SELECT`-Probe je Request
  ausführt. Bewusst ohne Marker-Datei im Plugin-Verzeichnis: Der Kern gibt
  Plugin-Verzeichnisse per Inhalts-Fingerabdruck frei, eine zur Laufzeit
  angelegte Datei würde das Addon deaktivieren
- Alle Addon-Versionen auf 1.1.1 gehoben

### Anpaarungs-Empfehlung

- Kandidatensuche ohne Pedigree-Explosion (#69): eine einzige
  Kantenabfrage, die Ahnenbäume entstehen in PHP (`AncestorTreeBuilder`);
  der Gleichlauf mit dem Pedigree-Aufbau des Kerns ist per Test belegt
  (identische Bäume und COI-Werte bei Tiefe 3/6/8), der Kandidaten-Deckel
  greift vor der Berechnung
- Rechentiefe an den Inzuchtkoeffizient-Rechner angeglichen (#72):
  6 Generationen je Elternteil, Beschreibungstexte entsprechend

### Farbvererbung

- Genetik-Modell mit statischer Unit-Testfamilie festgenagelt (#76) -
  inklusive korrigierter Erwartung gegenüber dem Issue-Entwurf: Grå × Grå
  ergibt nach der Modellannahme 93,75 % Grå + 6,25 % Rødblakk (Ee × Ee
  kann ee liefern), Brunblakk bleibt 0
- Nachschlage-Tabelle auf 200 Zeilen gedeckelt (mit Hinweistext) und über
  das Suchfeld vollständig nachschlagbar (#74)

### Galerie

- Theme-Bruch behoben: Die Video-Platzhalter-Kachel war fest `#222`/`#fff`
  und ignorierte damit Hell-/Dunkelmodus - jetzt Theme-Variablen
- Medienverwaltung paginiert (50 je Seite, geklemmter `?seite=`-Parameter);
  Löschen kehrt auf die Ausgangsseite zurück (#74)

### Gesundheitstests

- Download-Gate functional abgesichert (#71): private Dokumente anonym
  404 ohne Inhalt, öffentliche 200 als PDF-Attachment, Dokumente
  unveröffentlichter Pferde nur mit `gesundheitstests.manage`

### Inzuchtkoeffizient

- Detailseiten-Abschnitt rechnet mit derselben Tiefe wie der Rechner
  (#72): je Elternteil ein eigener Baum über 6 Generationen - der
  6-Generationen-Fall liefert auf Detailseite und Rechner identisch 0,39 %

### Katalog-Export

- Zeilen-Multiplikation behoben (#70): Personen kommen als Aggregat
  (`GROUP_CONCAT` je Rolle) statt über multiplizierende JOINs - ein Pferd
  mit mehreren Besitzer-Historienzeilen erzeugt wieder genau eine
  Exportzeile; Züchter-/Besitzer-Filter arbeiten rollenscharf als
  EXISTS-Unterabfragen

### Merkliste

- Katalog-Script als statisches, cachebares Asset (#73): ein
  `<script src>` je Seite (`/plugin/merkliste/assets.js`, `Cache-Control:
  public, max-age=86400`, Cache-Buster über den Dateistand) statt eines
  identischen Inline-Blocks je Katalogkarte; der MutationObserver
  beobachtet nur noch den Karten-Container statt des ganzen Dokuments

### Verkaufsbörse

- Admin-Inseratsliste und öffentliche Börse paginiert (50 je Seite),
  Pferde-Auswahl per Suchfeld (#74); der Verkaufs-Badge-Hook lädt die
  Inserats-IDs einmal je Request statt je Pferd (Framework#222)

### Repo

- Release-Prozess eingeführt (#65): Tags/Releases `vX.Y.z` folgen der
  Framework-Linie; Release-Pipeline mit Testsuite-Gate und
  Konsistenzprüfung (`scripts/check-release-consistency.php`: Unter- und
  Pflicht-Obergrenze aller Manifeste müssen die Ziel-Linie einschließen);
  dieses CHANGELOG; Ablaufdokumentation in `docs/releasing.md`
