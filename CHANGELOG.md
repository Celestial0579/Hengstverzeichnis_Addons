# Changelog

Alle nennenswerten Änderungen an den offiziellen Addons werden in dieser
Datei dokumentiert, je Release nach Addon gruppiert. Das Format orientiert
sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/); die
Release-Tags `vX.Y.z` folgen der Framework-Linie `X.Y`
(siehe [docs/releasing.md](docs/releasing.md)).

## [Unreleased]

### Sicherheit

- **`datenmigration` (1.2.0): Der Import prüft `database.sql`, bevor er
  etwas ausführt** (Audit M1). Ob ein Archiv ein Teilarchiv ist und welche
  Tabellen es ersetzt, entnahm der Import bisher allein dem Manifest; der Dump
  lief ungeprüft durch. Ein präpariertes „Teilarchiv Pferde“ konnte so nebenbei
  ein Administratorkonto anlegen, während die Vorschau „alle übrigen Tabellen
  bleiben unverändert“ zusagte. Der Dump wird jetzt Anweisung für Anweisung
  gegen das Format des Kern-Dumpers geprüft:
  - Zugelassen sind nur die `SET`-Kopfzeilen (auch die Zeitzonen-Zeilen aus
    Framework N66), `DROP TABLE IF EXISTS`, `CREATE TABLE` und
    `INSERT … VALUES` mit reinen Literalen, nur für Tabellen der Auswahl und
    mit kleingeschriebenen Namen.
  - Jede Abweichung verhindert den Import, bevor die Sicherung geschrieben
    oder etwas verändert wird.
  - Die Vorschau zeigt, was tatsächlich im Dump steht.
  - Ausgeführt wird über eine eigene Verbindung ohne Mehrfachanweisungen.

  **Für Betreiber:** Archive, deren `database.sql` vom Format des Kerns
  abweicht, werden abgewiesen – handgebaute oder nachbearbeitete Dumps,
  Views, Trigger, Routinen, `UPDATE`/`DELETE`, `/* */`-, `/*! */`- und
  `#`-Kommentare, Tabellennamen mit Großbuchstaben, Anweisungen über
  `max_allowed_packet`, Archive mit abweichender Tabellenliste (Format 2) und
  Archive mit doppelten Einträgen. Die Import-Sicherung entsteht erst nach
  dieser Prüfung; ein abgewiesenes Archiv hinterlässt keine Sicherungsdatei
  mehr.

- **`datenmigration` (1.2.0): Nach dem Import von Benutzerkonten sind alle
  Sitzungen ungültig** (Audit M2). Bisher endete nur die Sitzung des
  importierenden Administrators. Andere Sitzungen liefen unter derselben
  Kontonummer weiter, nach dem Import aber als das Konto der Quellinstanz,
  bis hin zu dessen Administratorrechten. Die `session_version` aller Konten
  wird jetzt über jeden bisherigen Wert angehoben.

  **Für Betreiber:** Nach einem Import mit Benutzertabellen melden sich alle
  neu an; API-Schlüssel sind neu auszustellen.

- **`datenmigration` (1.2.0): Passkeys und E-Mail-Codes verlassen die
  Instanz nicht mehr mit der Standardauswahl** (Audit M3). `user_passkeys`,
  `email_2fa_codes` und jede Tabelle mit Fremdschlüssel auf `users` gehören
  jetzt zur Gruppe „Benutzer, Gruppen, Rechte“. Beim Einspielen hängten sich
  solche Zeilen sonst an fremde Konten gleicher Kennung, und die eigenen
  Passkeys der Zielinstanz waren gelöscht. Ältere Archive mit diesen Tabellen
  ohne `users` werden weiter eingespielt; die Tabellen werden dabei
  übersprungen. Der Standardexport enthält damit weniger Tabellen als bisher.

- **`datenmigration` (1.3.0): Der Vollimport stellt auch
  `public/uploads/horses/.htaccess` wieder her** (Audit N1, Framework#366).
  Bisher schrieb der Import nach dem Verzeichnistausch nur die Schutzdatei im
  Wurzelverzeichnis von `public/uploads` zurück. Pferdefotos, die noch am
  alten Ort lagen, waren danach wieder statisch abrufbar – am
  Sichtbarkeitsschutz von `/media/horse-image` vorbei. Jetzt werden alle
  Schutzdateien des Kerns wiederhergestellt, wo sie fehlen: aus dem Stand
  des Ziels vor dem Import, sonst aus einer eingebauten Mindestfassung.
  Lässt sich eine nicht schreiben, nennen Abschlussmeldung und Protokoll sie.

- **`datenmigration` (1.3.0): Das Exportpasswort schützt mitgenommene
  Zugangsdaten** (Audit M25) mit PBKDF2-SHA256 (600 000 Runden) und
  AES-256-GCM, Mindestlänge 12 Zeichen. Das Passwort wird weder protokolliert
  noch auf Zwischenseiten mitgeführt. Beim Import begrenzt das Addon die
  Rundenzahl eines fremden Archivs und prüft den Aufbau der Datei, bevor es
  etwas ändert.

- **`mitglieder-konten` (1.0.1): Das Recht „Mitglieder-Konten anlegen“ führt
  nicht mehr zu Administrator-Konten.** Die Gruppe für neue Konten wurde
  ungeprüft gespeichert, und die Auswahl bot auch „Administrator“ und jede
  Gruppe mit Bearbeitungsrechten an. Wer `mitglieder_konten.manage` besaß,
  etwa eine Geschäftsstelle, konnte so Mitglieder als Administratoren anlegen
  – auch das eigene Mitgliedskonto. Im Kern legt nur ein Admin Konten an.

  Zulässig sind jetzt nur noch reine Lesegruppen oder „keine“ – dieselbe
  Regel, nach der der Kern Konten ohne Adresse erlaubt (Framework#348).
  Geprüft wird beim Speichern (die ganze Eingabe wird verworfen und der
  Versuch protokolliert), in der Vorschau und unmittelbar vor dem Anlegen.

  **Für Betreiber:** Steht in einer bestehenden Einrichtung eine unzulässige
  Gruppe, zeigt die Verwaltungsseite statt der Vorschau einen Hinweis; Konten
  entstehen erst nach Auswahl einer Lesegruppe. Der tägliche Lauf sperrt
  beendete Mitgliedschaften unverändert und vermerkt die unzulässige Gruppe
  im Protokoll. Wer das Recht an Nicht-Admins vergeben hatte, sollte prüfen,
  ob Konten dieses Addons in `admin` oder einer Gruppe mit Schreibrechten
  stehen, und das Protokoll auf „CiviCRM-Zugang gespeichert“ durch
  Nicht-Admins durchsehen.

- **`mitglieder-konten` (1.1.0): Den CiviCRM-Zugang richtet nur noch ein
  Admin ein.** Wer Basis-Adresse und API-Schlüssel setzt, bestimmt die
  Datenquelle für Vorschau, Anlage und den täglichen Lauf. Mit
  `mitglieder_konten.manage` ließ sich eine eigene Quelle samt eigenem
  Schlüssel eintragen und damit jede gewünschte Antwort liefern – bis hin zu
  Massensperren. Das Recht reicht weiterhin für die Verwaltungsseite, die
  Vorschau und das Anlegen in der vom Admin gewählten Lesegruppe; die
  Einstellungen sehen Nicht-Admins nur zum Lesen, jeder Speicherversuch
  endet mit 403.

- **`mitglieder-konten` (1.1.0): API-Schlüssel an die CiviCRM-Adresse
  gebunden** (Audit M5). Wer die Basis-Adresse ändert, muss den
  API-Schlüssel neu eingeben; bis dahin wird nichts gespeichert. Bisher ging
  der gespeicherte Schlüssel an jede neu eingetragene Adresse. Erlaubt ist
  nur noch `https://` ohne Zugangsdaten, Parameter oder Fragment; ein Pfad
  bleibt zulässig. `localhost` und Ziele, die auf private, Loopback-,
  Link-Local-, CGNAT- oder reservierte Adressen auflösen, werden abgelehnt –
  auch in IPv6-Verpackung (IPv4-mapped, NAT64, 6to4). Vor jeder Anfrage wird
  der Host aufgelöst, und die Verbindung geht genau an die geprüfte Adresse.
  Fehlermeldungen in der Verwaltung sind allgemein gehalten, Einzelheiten
  stehen nur im Serverprotokoll, nie mit dem Schlüssel.

  **Für Betreiber:** Eine `http://`-Adresse muss auf `https://` umgestellt
  werden. Ein CiviCRM im eigenen Netz gibt der Serverbetreiber über die
  Umgebungsvariable `MITGLIEDER_KONTEN_INTERNE_HOSTS` (kommagetrennte
  Hostnamen) frei. Mit Egress-Proxy löst der Proxy auf; dann ist dessen
  Filter die Grenze. Ein bereits gespeicherter Schlüssel wird beim ersten
  Lesen an die jetzige Adresse gebunden.

- **Der statische Plugin-Check erkennt im Aufruf zusammengesetztes SQL jetzt
  zuverlässig** (Audit N36). Bisher schlug die Regel nur an, wenn direkt vor
  dem Verkettungspunkt ein doppeltes Anführungszeichen stand und der Aufruf
  auf eine Zeile passte. `->exec()` fehlte ganz. Die im Repo übliche
  Schreibweise `$db->query('… ' . $x)`, meist über mehrere Zeilen, fiel
  deshalb komplett durch. Das Gate meldete „bestanden“, obwohl README und
  SECURITY.md genau diese Musterklasse als blockierend zusagen.

  Die Prüfung läuft jetzt über den PHP-Tokenizer
  (`security/lib/sql-concat-check.php`). Sie erfasst `query`, `prepare` und
  `exec`, beide Anführungszeichenarten, mehrzeilige Aufrufe sowie per
  `sprintf`/`implode` gebautes SQL. `->quote(…)` und `(int)`-Casts bleiben
  ausgenommen. Die zwölf bestehenden Treffer (Bezeichner-Quoting, feste
  Spaltenlisten, Platzhalterlisten) sind geprüft und einzeln freigegeben. Eine
  ausnutzbare Stelle war nicht darunter.

  Weiterhin nicht erfasst wird SQL, das vorab in einer Variable gebaut wird.
  Das bleibt Aufgabe von Semgrep.

- **Die Allowlist des Plugin-Checks gibt jetzt Einzelstellen frei.** Neben
  `<plugin>|<titel>` gilt das Format `<plugin>|<titel>|<Code-Ausschnitt>`.
  Plugin und Titel werden dabei exakt verglichen. Eine geprüfte Ausnahme
  schaltet damit nicht mehr die ganze Regel für ein Addon ab, und eine spätere
  echte Injection im selben Addon fällt weiterhin auf. Ein Regressionstest
  (`tests/Unit/PluginSecurityScanTest.php`) prüft die Muster aus dem Befund.

- **Der Rückfall des Plugin-Checks ohne PHP-CLI prüfte gar nichts.** Das
  `sed`-Muster, das dort Kommentarzeilen entfernt, brach an seinem eigenen
  Trennzeichen ab; jede Datei blieb leer, und der Lauf meldete still „keine
  auffälligen Muster“. Das Muster ist korrigiert. Weil der Rückfall
  SQL-Backticks nicht von Shell-Backticks trennen kann, meldet er
  Backtick-Funde nur noch als MED; blockierend prüft die CI mit Tokenizer.

- **`kontaktanfrage` (1.1.2): Fehlgeschlagene Versuche sperren keinen
  Kontakt mehr** (Audit M4). Der Zähler je Empfänger (10 Anfragen in 24
  Stunden) wurde schon vor der Sicherheitsfrage gebucht. Zehn Anfragen mit
  falscher oder leerer Antwort sperrten so das Formular eines Kontakts für
  einen Tag, ohne dass eine Anfrage ankam. Jetzt zählt der Empfängerzähler
  nur noch angenommene Anfragen: nach gelöster Aufgabe, gültiger Eingabe und
  Zielprüfung. Der IP-Zähler bucht weiterhin jeden Versuch vor der Aufgabe.
  Unveröffentlichte Kontakte, Opt-out und fehlende Team-Adresse buchen
  nichts.

- **`kontaktanfrage` (1.1.2): Ohne `contacts.view` wird nichts mehr
  gespeichert** (Audit N2). Fehlt der Gast-Gruppe das Leserecht auf
  Kontakte, zeigt die Kontaktseite 404. Ein direkter POST legte trotzdem eine
  Anfrage an und schickte sie ans Team. Er wird jetzt vor der Sicherheitsfrage
  still verworfen, die Rückmeldung bleibt „erfolg“.

- **`verkaufsboerse` (1.3.0): Kontaktformular mit Zähler je Inserat,
  Längengrenzen und dem Honeypot des Kerns** (Audit N3). Bisher bremste nur
  ein IP-Zähler; über wechselnde Anschlüsse ließ sich jedes Inserat als
  Mail-Relais an den Inserenten nutzen. Jetzt gilt:
  - höchstens zehn Anfragen je Inserat in 24 Stunden (darüber „fehler“);
    gezählt werden nur Anfragen, die alle Prüfungen bestanden haben
  - Name und E-Mail-Adresse je höchstens 150 Zeichen, Nachricht höchstens
    5000 Zeichen, gültiges UTF-8, kein Zeilenumbruch in Name und Adresse
  - das Honeypot-Feld des Kerns (`website`); der alte Name `webseite` wird
    eine Version lang weiter ausgewertet
  - ein manipuliertes CSRF-Feld (Array) führt zu 403 statt HTTP 500

  Die Sicherheitsfrage für dieses Formular kam mit 1.4.0 (Kern-Captcha je
  Kontext, siehe den nächsten Eintrag).

- **`verkaufsboerse` (1.4.0): Sicherheitsfrage im Kontaktformular** (Audit
  N3). Das Formular meldet sich als Captcha-Kontext `verkaufsboerse` an
  („Kontaktanfrage zu einem Verkaufsinserat“) und bindet den Spam-Schutz des
  Kerns ein – eingebaute Rechenaufgabe oder der für dieses Formular bzw.
  global gewählte Anbieter. Geprüft wird nach dem Leserecht `horses.view` und
  vor Eingabeprüfung, Inseratsabfrage und Zähler je Inserat; eine ungelöste
  Aufgabe meldet `?verkaufsanfrage=captcha` mit eigenem Hinweis und bucht den
  Zähler des Inserats nicht. Honeypot und fehlendes Leserecht verwerfen nur
  die Aufgabe dieses Formulars. `uninstall()` entfernt zusätzlich
  `captcha_provider_verkaufsboerse`.

  Möglich wurde das mit Framework 25940ae (Captcha je Kontext, Framework
  #472): Die eingebaute Aufgabe liegt dort je Formular in einem eigenen Platz
  der Sitzung, das Feld hat die ID `captcha-<kontext>`. Deckanfrage und
  Verkaufsbörse auf derselben Hengstseite sind damit unabhängig voneinander
  lösbar.

- **`captcha-altcha` (1.0.2): Eine Aufgabe je Formular** (Audit N3). Der
  Rechennachweis lag in einem einzigen Platz der Sitzung
  (`plugin_captcha_altcha_challenge`), die Rückfall-Aufgabe im gemeinsamen
  Platz des Kerns. Mit zwei geschützten Formularen auf einer Seite
  überschrieb das zweite beim Rendern die Aufgabe des ersten. Jetzt liegen
  Nachweis (`plugin_captcha_altcha_challenges[<kontext>]`) und
  Rückfall-Aufgabe (`Captcha::issue($kontext)`, `verifyBuiltin(…, $kontext)`)
  je Kontext. Je Sitzung bleiben höchstens zehn Nachweise offen, der älteste
  fällt zuerst weg. Ein Nachweis aus dem alten Platz wird beim Prüfen noch
  einmal angenommen.

- **`deckanfrage` (1.2.2), `kontaktanfrage` (1.1.3): Honeypot und fehlendes
  Leserecht verwerfen nur die eigene Aufgabe** (Audit N3). `Captcha::clear()`
  ohne Kontext hätte den gemeinsamen Platz geleert; mit Kontext bleibt die
  Aufgabe eines anderen Formulars derselben Seite gültig.

- **`verkaufsboerse` (1.3.0): Kein Versand und kein Existenz-Orakel ohne
  `horses.view`** (Audit N4). Ohne Leserecht der Gast-Gruppe auf Pferde
  zeigen Börse und Pferdeseite 404; ein direkter POST verschickt jetzt auch
  nichts mehr. Anfragen an Pferde ohne aktives Inserat, mit abgelaufenem
  Inserat oder an unveröffentlichte Pferde melden „erfolg“ statt „fehler“ –
  ohne Versand. Der Status verriet bisher, zu welchem Pferd ein Inserat läuft.

### Behoben

- **`deckanfrage` (1.2.1): Überlange Eingaben führen nicht mehr zu einer
  Fehlerseite** (Audit N24). Namen oder E-Mail-Adressen über 150 Zeichen,
  sehr lange Nachrichten und ungültiges UTF-8 scheiterten erst am Speichern
  (HTTP 500), die Anfrage ging verloren. Jetzt begrenzen `maxlength` und eine
  Prüfung auf dem Server die Eingaben – Name und E-Mail-Adresse auf 150
  Zeichen, die Nachricht auf 5000 Zeichen – und melden „fehler“. Ein
  manipuliertes CSRF-Feld (Array) führt zu 403 statt HTTP 500.

- **`deckanfrage` (1.2.1): Ohne `horses.view` bzw. `contacts.view` wird vor
  der Sicherheitsfrage verworfen** (Audit N24). Die Rechteprüfung stand erst
  hinter der Aufgabe; ein Direkt-POST ohne Recht bekam „captcha“ statt der
  stillen Rückmeldung „erfolg“. Versendet wurde auch bisher nichts.

- **`mitgliedsstatus` (1.1.0): Übernahme aus dem v0.7-Altbestand** (Audit
  N78). Fehlt `contacts.membership_status`, weil die Instanz direkt von v0.7
  gehoben wurde, übernimmt das Addon die Werte aus `persons_pre_contacts`.
  Das gilt auch dann, wenn 1.0.0 die Übernahme schon mit „keine Spalte“
  abgeschlossen hatte. Es gelten dieselben Kontakt-IDs. Gelöschte und
  anonymisierte Kontakte sind ausgenommen, bereits gepflegte Angaben im Addon
  werden nicht überschrieben. Der Bericht nennt die Quelle.
  - Nur beim direkten Sprung: Hat der Kern-Schritt 395 die Spalte in
    `contacts` gelöscht (Marker `migration_395_membership_status_faellt`),
    ist der Altbestand ein womöglich überholter Stand und wird nicht
    übernommen.
  - Der Marker `plugin_mitgliedsstatus_uebernahme` trägt jetzt die Felder
    `quelle` und (beim Altbestand) `bestand`. Der Kern liest `grund` und
    `quelle` für seinen Dashboard-Hinweis; das Format ist in der README
    dokumentiert.
- **`mitgliedsstatus` (1.1.0): Wortlaute mit Leerraum am Rand ließen sich nicht
  zuordnen** (Audit N32). Übernahme, Anzeige und Zuordnen verwenden jetzt
  dieselbe Regel. ' Nichtmitglied NO' und 'Nichtmitglied NO' erscheinen als
  eine Zeile und werden gemeinsam zugeordnet. Der gesicherte Wortlaut bleibt
  Zeichen für Zeichen erhalten. Werte, die nur aus Leerraum bestehen, werden
  nicht mehr als offen übernommen.

- **`inzuchtkoeffizient`/`anpaarungs-empfehlung` (1.2.1): Inzuchtkoeffizient
  bei Linienzucht zu niedrig** (Audit M29). Der gemeinsame Rechenkern
  `WrightCoi` brach jeden Abstammungspfad am ersten gemeinsamen Vorfahren ab.
  Dadurch fehlten Pfade zu tiefer liegenden gemeinsamen Vorfahren, die auf der
  anderen Seite auf einem eigenen Weg erreichbar sind.
  - Beispiel: Bei beidseitiger Linienzucht auf einen Hengst und dessen Vater
    zeigte das Register 12,50 % statt 15,63 %.
  - Ein anderer Fall landete mit 3,13 % statt 6,25 % unter der Warnschwelle
    der Anpaarungs-Empfehlung.

  Jetzt gilt Wrights Pfadregel vollständig: Ein Pfadpaar zählt, sobald es
  außer dem gemeinsamen Vorfahren kein Pferd doppelt enthält. Detailseite,
  Verpaarungsrechner und Empfehlungs-Ranking zeigen bei Linienzucht dadurch
  höhere, korrekte Werte; Reihenfolge und Markierungen der Empfehlung können
  sich verschieben.

  **Für Betreiber:** Bitte beide Addons gemeinsam aktualisieren. Sind beide
  aktiv, rechnet immer die Fassung aus `anpaarungs-empfehlung` (alphabetische
  Ladereihenfolge). Eine veraltete Fassung meldet sich jetzt im
  Fehlerprotokoll („Veralteter WrightCoi-Rechenkern geladen“).

- **`inzuchtkoeffizient` (1.2.1): Per Lebensnummer oder Name verknüpfte
  Eltern zählen** (Audit M28). Der Abschnitt auf der Pferde-Detailseite las
  die Eltern nur aus den festen Verknüpfungen. Nach einem CSV-Import, der
  Eltern nur per UELN oder Name einträgt, zeigte er 0,00 % oder fehlte ganz,
  obwohl der Stammbaum daneben die Eltern anzeigte. Er verwendet jetzt
  dieselben, nur aus veröffentlichten Pferden aufgelösten Eltern wie der
  Stammbaum.

- **`farbvererbung` (1.3.0): Cream-Vererbung im Farbrechner korrigiert**
  (Audit N25). Hell- und Gelbfalbe tragen genau eine Cream-Dosis. Der Rechner
  behandelte sie bisher, als könnten sie auch zwei Dosen tragen, und zählte
  doppelt verdünnte Fohlen als Hell- bzw. Gelbfalbe. Gelbfalbe × Rotfalbe
  ergibt jetzt korrekt 50 % / 50 % statt 75 % / 25 %. Bei Cream × Cream
  erscheinen Fohlen mit doppelter Cream-Dosis in einer eigenen Zeile
  „Doppelte Cream-Dosis (Cr Cr)“.

- **`datenmigration` (1.2.0): Große Instanzen lassen sich importieren**
  (Audit N21). Der Dump ging als ein einziges Datenbankpaket an den Server
  und scheiterte ab 16 MiB an `max_allowed_packet`. Die Meldung behauptete
  trotzdem, der Sicherungsstand sei zurückgespielt. Jetzt gilt:
  - Import und Rückweg laufen streamend, Anweisung für Anweisung, über eine
    eigene Verbindung in der Zeitzone der Anwendung.
  - Die Sicherung wird vor dem Import darauf geprüft, ob sie sich wieder
    einspielen lässt (einschließlich gzip-Prüfsumme). Lässt sie sich nicht
    einspielen, etwa wegen einer View, wird der Import verweigert, bevor sich
    etwas ändert.
  - Scheitert auch das Zurückspielen, bleibt der Wartungsmodus aktiv, und die
    Meldung nennt Sicherungsdatei und nötige Schritte (Sicherung einspielen,
    danach `var/wartung.lock` löschen).
  - Schreibfehler beim Archiv, beim Export-Dump und bei der Sicherung werden
    an der geschriebenen Menge erkannt; `gzwrite()` meldet eine volle Platte
    mit 0 statt `false` (Audit M43, Duplikat im Addon).

- **`datenmigration` (1.2.0): Importe ordnen Addon-Daten nicht mehr still
  fremden Datensätzen zu** (Audit N23). Ersetzt ein Archiv Pferde, Kontakte
  oder Konten, ohne die abhängigen Addon-Tabellen mitzubringen, zeigen deren
  Zeilen nicht „ins Leere“. Sie hängen an den Datensätzen des Archivs mit
  derselben Kennung, etwa Inserate, Befunde, Mitgliedsstatus und
  Kontaktanfragen. Die Vorschau sagt das jetzt auch bei Vollarchiven, nennt
  die Kontaktanfrage-Tabellen und verlangt eine Entscheidung zwischen
  „trennen“ und „stehen lassen“. Addons können eigene Verweise ohne
  Fremdschlüssel über `weiche_verweise` in ihrer `plugin.json` angeben
  (nur für eigene `plugin_`-Tabellen aus `owns.tables`).

- **`datenmigration` (1.3.0): Pferdefotos ziehen beim Umzug mit** (Audit
  M26, Framework#366). Seit Kern 0.8 liegen die Pferdefotos unter
  `storage/horses`, außerhalb von `public/uploads`, und fehlten deshalb nach
  jedem Umzug. Sie gehen jetzt mit der Gruppe „Dateien“ als
  `storage-horses/…` ins Archiv und werden beim Import mit derselben Pfad- und
  Namensprüfung wie `uploads/` wiederhergestellt.
  - Ein Vollarchiv ersetzt den Inhalt von `storage/horses`, ein Teilarchiv
    führt zusammen. Überschriebene und – beim Vollarchiv – nur auf dem Ziel
    vorhandene Fotos werden unter `var/datenmigration/ersetzte-dateien-…/storage-horses`
    gesichert.
  - Getauscht wird Datei für Datei, nicht das Verzeichnis. Das funktioniert
    auch, wenn `storage/horses` ein eigenes Docker-Volume ist.
  - Die `.gitkeep` des Kerns geht nicht ins Archiv; ein fremdes Archiv mit
    Punktdateien unter `storage-horses/` scheitert nicht daran.
  - Wird „Pferde“ ohne „Dateien“ exportiert, weist eine Zwischenseite darauf
    hin, dass die Fotos fehlen werden.

- **`datenmigration` (1.3.0): Zugangsdaten bleiben nach einem Umzug auf einen
  anderen `APP_KEY` brauchbar** (Audit M25). SMTP- und Backup-Passwörter,
  Addon-Secrets und TOTP-Geheimnisse sind an den `APP_KEY` gebunden und waren
  auf einer neuen Instanz bisher still unbrauchbar.
  - Das Manifest trägt jetzt einen Fingerabdruck des `APP_KEY` und die Namen
    der verschlüsselten Einstellungen.
  - Mit einem optionalen Exportpasswort werden die Werte mitgenommen und
    beim Import mit dem `APP_KEY` des Ziels neu verschlüsselt.
  - Ohne Exportpasswort nennt die Vorschau die betroffenen Einstellungen.
    Nach ausdrücklicher Bestätigung werden sie geleert, statt unlesbaren
    Chiffretext stehen zu lassen. Geleert wird nur, was sich auf dem Ziel
    tatsächlich nicht entschlüsseln lässt; ein manipuliertes Manifest kann
    keine Klartext-Einstellung leeren.
  - TOTP ohne Exportpasswort bleibt unangetastet (fail-closed), Passkeys
    lassen sich grundsätzlich nicht umschlüsseln. Beide müssen neu
    eingerichtet werden; die Vorschau nennt die Zahl.
  - Bei älteren Archiven ohne Fingerabdruck zählt der Import nicht
    entschlüsselbare Werte und nennt die Zahl.

- **`datenmigration` (1.3.0): Archive lassen sich auch ohne die
  PHP-Erweiterung zlib einlesen** (Audit N22). Bisher endete ohne zlib jedes
  Archiv mit einem Fatal Error. Ein `.tar` wird jetzt gelesen, ein `.tar.gz`
  mit einer verständlichen Meldung abgewiesen. Ein unlesbares Archiv führt
  beim Anwenden nicht zu einer Fehlerseite.

- **`datenmigration` (1.3.0): Scheitert nach dem Datenbank-Import das
  Übernehmen der Dateien, sagt der Import das** („Datenbank importiert,
  Dateien unvollständig“, mit den Sicherungen) und protokolliert es, statt mit
  einer Fehlerseite zu enden.

- **`kontaktanfrage` (1.1.1): Keine Weiterleitung an einen Kontakt, der
  jünger ist als die Anfrage** (Audit N23). Wurde eine Kontaktkennung neu
  vergeben, ging die Anfrage an eine fremde Person. Solche Anfragen erscheinen
  jetzt als „Datensatz entfernt“ und werden nicht weitergeleitet. Die
  `plugin.json` nennt beide Kontaktanfrage-Tabellen unter `weiche_verweise`.

- **`mitglieder-konten` (1.1.0): keine Massensperre mehr durch eine leere
  oder gefilterte CiviCRM-Antwort** (Audit N30). Der tägliche Lauf fragt den
  Status der zugeordneten Mitgliedschaften gezielt per ID ab; der Filter
  „Mitgliedschaftsarten“ gilt nur noch für die Anlage. Gesperrt wird nur, was
  CiviCRM ausdrücklich als beendet meldet oder dort nicht mehr auffindbar
  ist; eine Zeile ohne Statusangabe sperrt nicht. Würden mehr als 20 %
  (mindestens 10) der Konten auf einmal gesperrt oder entsperrt, hält der
  Lauf in dieser Richtung an. Ein Admin bestätigt auf der Verwaltungsseite
  genau die angezeigte Menge (neue Route
  `POST /plugin/mitglieder-konten/verwaltung/sperren-bestaetigen`).

- **`mitglieder-konten` (1.1.0): Konten werden wieder entsperrt, wenn die
  Mitgliedschaft wieder läuft** (Audit N30). Das gilt nur für Sperren mit dem
  Grund „Mitgliedschaft beendet“ und nur für Konten in reinen Lesegruppen.
  Sperren durch einen Admin oder die Ruhesperre bleiben bestehen.

- **`mitglieder-konten` (1.1.0): Versandfehler bei den Zugangsdaten werden
  gemeldet** (Audit N31). Scheitert die Willkommensmail oder die Sammelmail
  an das Verwaltungsteam, oder fehlt die Team-Adresse, nennt die Verwaltung
  die betroffenen Benutzernamen. Das Protokoll hält den Fall fest, ohne
  Passwörter. Ein Weg „Zugangsdaten neu erzeugen und versenden“ folgt als
  eigenes Issue.

- **`mitglieder-konten` (1.1.0): belegte E-Mail-Adressen und Wiedereintritte
  in der Vorschau** (Audit N29). Bereits vergebene oder im Stapel doppelte
  Adressen (etwa eine gemeinsame Familienadresse) erscheinen vor der Anlage
  als „geht nicht“. Tritt ein Mitglied wieder ein, übernimmt das Addon das
  bisherige Konto samt Benutzername und Passwort, statt an der doppelten
  Adresse zu scheitern; läuft die alte Mitgliedschaft noch, ist die Zeile
  blockiert.

- **`pferd-des-tages` (1.0.1): Die Vorgabenliste zeigt heute und die
  nächsten Tage wieder zuverlässig an** (Audit N33). Die Verwaltungsseite hat
  alle Einträge absteigend sortiert und nach 14 Zeilen abgeschnitten. Wer mehr
  als zwei Wochen im Voraus plante, sah danach nur die spätesten Termine. Die
  heutige Vorgabe, die unmittelbar anstehenden Vorgaben und die Rückschau
  fehlten dann, und ausgeblendete Vorgaben ließen sich nicht aufheben. Die
  Liste ist jetzt zweigeteilt:
  - „Heute und anstehend“: aufsteigend sortiert, mit Hinweis, falls die Liste
    gekürzt wird (ab 366 Einträgen).
  - „Zurückliegend“: die letzten 14 Tage. Vergangene Vorgaben, die älter sind,
    erscheinen nicht mehr; sie wirken ohnehin nicht mehr.

- **`plausibilitaetspruefung` (1.0.1): Die Regel „Zeichenschaden“
  funktioniert** (Audit N34). Das Suchmuster für U+FFFD trug die
  Standardkollation des Zeichensatzes und kollidierte mit der Kollation der
  Spalten `horses.name` und `horses.description` („Illegal mix of
  collations“). Die Regel meldete deshalb immer „Keine Fälle“. Das Muster
  vergleicht jetzt ausdrücklich binär (`utf8mb4_bin`). Nach dem Update können
  Bericht und Kachel erstmals Fälle zu dieser Regel zeigen.

- **`plausibilitaetspruefung` (1.0.1): Eine fehlerhafte Regel verschluckt
  nicht mehr alle Funde eines Pferds** (Audit N34). Bisher ließ ein einziger
  Fehler die gemeinsame Abfrage scheitern. Das Bearbeitungsformular zeigte
  dann keinen einzigen Fund, auch keinen blockierenden. Scheitert die
  gemeinsame Abfrage, läuft jetzt jede Regel einzeln; die fehlerhafte wird
  übersprungen und im Server-Log vermerkt. Kann eine Regel im Bericht nicht
  ausgewertet werden, steht dort „Diese Regel konnte nicht ausgewertet werden“
  statt „Keine Fälle im Bestand“.

- **Die Composer-Zeitgrenze vorsorglich angehoben** (Framework#424). Composer
  bricht Kindprozesse nach 300 Sekunden ab. Im Framework hat die Suite diese
  Grenze erreicht und einen nächtlichen Lauf als „gescheitert" gemeldet, ohne
  dass ein Test fehlgeschlagen wäre.

  Hier zählt die Grenze seit #160 stärker: Die CI fährt jetzt `composer test`,
  also alle drei Suiten in **einem** Prozess — die 300 Sekunden gelten damit
  für den gesamten Lauf statt je Suite. `config.process-timeout` steht
  deshalb auch hier auf 1800.

- **`qr-code` (1.2.1): Die Kompatibilitätsgrenze stimmt wieder** (Audit N35).
  Seit dem Sicherheitsfix GHSA-xrrq-9j94-fr5g holt der Aushang das Foto über
  `App\Helper\MediaUrl`. Diese Klasse gibt es erst ab Kern 0.5.1
  (Framework#262). Das Manifest erlaubte trotzdem weiter Kern ab 0.4.0. Auf
  Kernen 0.4.0–0.5.0 lud das Addon deshalb, und „Aushang drucken“ brach bei
  jedem Pferd mit HTTP 500 ab. `core_compatibility` steht jetzt auf `>=0.5.1`.

- **`merkliste` (1.2.1): Dieselbe Korrektur.** Auch die Merkliste nutzt
  `MediaUrl` für die Vorschaubilder und erklärte bisher `>=0.4.0`. Jetzt steht
  dort `>=0.5.1`.

- **`captcha-altcha` (1.0.1): Rückfall auch für Browser ohne
  `crypto.subtle`** (Audit N20). Die Rechenaufgabe des Kerns stand bisher nur
  im `<noscript>`-Bereich. Auf einer per HTTP ausgelieferten Instanz fehlt
  `crypto.subtle`, weil SubtleCrypto einen sicheren Kontext verlangt.
  Besucher mit JavaScript sahen dort weder einen Nachweis noch eine Aufgabe
  und scheiterten an jedem geschützten Formular, auch am DSGVO-Portal. Die
  Aufgabe steht jetzt zusätzlich verborgen im Formular. Das Skript blendet
  sie ein, wenn der Browser den Nachweis nicht rechnen kann oder die
  Berechnung scheitert. Ohne JavaScript bleibt alles wie bisher. README und
  Verwaltungsseite beschreiben das jetzt zutreffend.

- **`merkliste` (1.2.2): „Merken“ funktioniert auch nach einem Filterwechsel
  auf einem zunächst leeren Katalog** (Audit N28). Das Merklisten-Skript
  wurde nur zusammen mit der ersten Katalogkarte eingebunden. Startete der
  Katalog ohne Treffer, etwa über einen geteilten Suchlink, und kamen die
  Karten danach per Live-Filter, blieb ein Klick auf „Merken“ ohne Wirkung.
  Der Knopf lädt das Skript jetzt bei Bedarf selbst nach. Ein bereits
  gemerktes Pferd wird dabei nicht versehentlich wieder entfernt.

### Geändert

- **`datenmigration` 1.2.0, `kontaktanfrage` 1.1.1.** `core_compatibility`
  und `core_supported_max` bleiben unverändert (Linie 0.9).

- **`datenmigration` 1.3.0: Archivformat 3, `core_compatibility` `>=0.8.0`.**
  Neue Archive enthalten `storage-horses/` und gegebenenfalls
  `geheimnisse.json`. datenmigration bis 1.2.x weist sie ab, statt die
  Pferdefotos still zu übergehen. Archive der Formate 1 und 2 bleiben
  einspielbar; die Vorschau weist darauf hin, dass sie keine Pferdefotos aus
  `storage/horses` enthalten. Die Untergrenze steigt von `>=0.8.0-beta.1` auf
  `>=0.8.0`, weil `App\Helper\HorseImagePath` erst mit dem Tag v0.8.0 kam
  (im `KernMindestversionTest` eingetragen). `core_supported_max` bleibt
  `0.9`.

  **Für Betreiber:** Ein Vollarchiv mit Dateien ersetzt jetzt auch den Inhalt
  von `storage/horses`. Bei abweichendem `APP_KEY` verlangt der Import das
  Exportpasswort oder die Zustimmung zum Leeren der Zugangsdaten. Archive
  werden um die Pferdefotos größer.

- **Neuer Manifest-Test: Die Kern-Untergrenze muss zu den genutzten Kern-APIs
  passen.** `tests/Manifest/KernMindestversionTest.php` sammelt je Addon die
  referenzierten `App\`-Klassen und statischen Methoden. Er gleicht sie mit
  einer Tabelle ab, in der die Kernversion ihrer Einführung steht. Zusätzlich
  prüft er, dass jede genutzte Kernklasse und jede statisch aufgerufene
  Methode im gepinnten Framework existiert. Wer ein Addon auf ein neues
  Kern-API umstellt, trägt dessen Einführungsversion in die Tabelle ein. Wird
  die Anhebung von `core_compatibility` vergessen, wird der Lauf rot.

- **`inzuchtkoeffizient` 1.2.1, `anpaarungs-empfehlung` 1.2.1,
  `farbvererbung` 1.3.0.** `core_compatibility` und `core_supported_max`
  bleiben unverändert (Linie 0.9). Manuell installierte Addons (Herkunft kein
  Release-Tag) müssen nach dem Update unter `/admin/plugins` erneut
  freigegeben werden.

- **Zehn Sprach-Addons 1.0.1** (`sprache-cs`, `sprache-da`, `sprache-fi`,
  `sprache-fr`, `sprache-it`, `sprache-lb`, `sprache-nb`, `sprache-nl`,
  `sprache-pl`, `sprache-sv`): Übersetzung für `auth.login_captcha_required`,
  die Meldung der neuen kontoweiten Anmeldebremse im Kern (Framework, Audit
  M7). Ohne sie erschiene die Meldung dort auf Deutsch. Der Kern-Schlüssel
  kommt erst mit dem nächsten Framework-Stand; bis dahin steht er in
  `SprachAddonVollstaendigkeitTest` als angekündigter Vorab-Schlüssel und
  gehört nach dem Pin dort wieder gestrichen. `core_compatibility` und
  `core_supported_max` bleiben unverändert (Linie 0.9).

- **Einheitliche Prüfreihenfolge der drei öffentlichen Anfrageformulare**
  (`kontaktanfrage` 1.1.2, `verkaufsboerse` 1.3.0, `deckanfrage` 1.2.1):
  CSRF, Honeypot, IP-Zähler, Leserechte der Gast-Gruppe, Sicherheitsfrage,
  Eingabeprüfung, Ziel bzw. Inserat, Empfänger- bzw. Inseratszähler,
  Speichern bzw. Versand. Fehlt das Recht oder das Ziel, wird still mit
  „erfolg“ geantwortet. `core_compatibility` und `core_supported_max` bleiben
  unverändert (Linie 0.9).

  **Für Betreiber (`verkaufsboerse`):** Je Inserat gehen höchstens zehn
  Anfragen in 24 Stunden an den Inserenten. Das Honeypot-Feld heißt jetzt
  `website`, die ID `verkaufsboerse-website`; eigene CSS- oder
  JS-Anpassungen an `#verkaufsboerse-webseite` müssen umgestellt werden.
  Das Deinstallieren entfernt jetzt die Zähler des Formulars aus
  `login_attempts`; die Inserate bleiben. Die Sicherheitsfrage kam mit
  `verkaufsboerse` 1.4.0 (siehe „Sicherheit“).

- **`verkaufsboerse` 1.4.0, `captcha-altcha` 1.0.2, `deckanfrage` 1.2.2,
  `kontaktanfrage` 1.1.3 (Captcha je Kontext).** `core_compatibility` und
  `core_supported_max` bleiben unverändert (Linie 0.9). Die Aufrufe mit
  Kontext (`Captcha::issue($kontext)`, `verifyBuiltin(…, $kontext)`,
  `clear($kontext)`) sind neue optionale Parameter an bestehenden
  Kern-Methoden. Ein Kern bis v0.9.0 ignoriert das zusätzliche Argument und
  nimmt den gemeinsamen Platz – dort bleibt es beim bisherigen Verhalten.
  `verkaufsboerse` erkennt einen solchen Kern an der fehlenden Konstante
  `Captcha::MAX_CONTEXTS` und lässt die Sicherheitsfrage dort weg; sonst
  überschriebe sie die Aufgabe der Deckanfrage auf derselben Seite. Getrennte
  Plätze gibt es erst mit dem Kern nach v0.9.0 (gepinnt: Framework 25940ae).

## [0.9.0] – 2026-08-27

**Der Addon-Stand zur Kern-Freigabe v0.9.0.** Alle 36 Addons sind gegen den
freigegebenen Kern geprüft; keines brauchte eine Anpassung seiner
Kompatibilitätsgrenzen — `core_supported_max` steht überall bereits auf `0.9`.

> **Warum dieses Release nötig ist, auch ohne neue Funktion:** Der
> Addon-Katalog des Kerns überspringt Entwürfe und Vorabversionen
> bedingungslos und verlangt ein Tag der Form `vX.Y.Z`. Für die Kern-Linie 0.9
> gab es bisher nur Vorabversionen — die automatische Addon-Phase eines
> Kern-Updates hätte damit auf jeder Installation verweigert. Zu jeder
> Kern-Freigabe ohne Suffix gehört ein Addons-Release ohne Suffix.

### Angepasst an den Kern

- **Die Hook-Aliasse `person.*` und `station.*` sind im Kern entfallen**
  (Framework#347). Kein Addon dieses Repos hat sie je registriert — betroffen
  waren nur Beschreibungstexte: Das Lehrbeispiel `beispiel-erweiterungspunkte`
  führte die acht Aliasse in seiner Ausnahmeliste, und `zucht-suche`
  begründete in seiner README, warum es sie bewusst nicht registriert. Beides
  ist nachgezogen.

  Die Ausnahmeliste des Lehrbeispiels ist damit **leer** — und das ist der
  gute Zustand: Es belegt jetzt jeden Erweiterungspunkt, den der Kern hat.
  Der Abdeckungstest sichert das ausdrücklich zu, auch für den leeren Fall;
  sonst liefe er ohne eine einzige Prüfung durch.

### Behoben

- **Der Abdeckungstest führte eine Kern-Stelle, die es nicht mehr gibt.** Er
  kennt eine Liste von Aufrufen, deren Hook-Name erst zur Laufzeit entsteht —
  dort stand die Methode, die jedes Kontakt-Ereignis dreifach auslöste. Sie
  ist mit dem Kern-Umbau verschwunden. Der Test hat das selbst gemeldet und
  den Eintrag verlangt; genau dafür ist die Gegenrichtung dieser Prüfung da.

- **Der wöchentliche Lauf gegen Framework-main stand still** (#160). Seit dem
  25.08. meldete er „Addons brechen gegen den aktuellen Framework-main" und
  liess `composer.lock` unangetastet. Tatsächlich war kein einziger Test gegen
  den neuen Kern gelaufen: `composer test` brach vorher mit
  `Cannot redeclare class Plugin\MitgliederKonten\CiviApi` ab.

  Ursache waren zwei Ladewege für dieselbe Klasse. `tests/bootstrap.php`
  spiegelt `plugins/` vor jedem Lauf nach `vendor/…/framework/plugins/`; die
  Dateien sind danach inhaltlich gleich, für `require_once` aber zwei Pfade.
  `PluginManifestTest` lädt die Entry-Datei jedes Plugins aus `plugins/`,
  `MitgliederKontenPluginTest` lud sie anschliessend aus der Kopie.

  **Gesehen hat es niemand, weil die CI die drei Suiten in drei getrennten
  Prozessen fuhr** — dort begegnen sich die beiden Ladewege nie. `composer
  test` fährt sie in einem, und genau so ruft `framework-update.yml` sie auf.
  Die CI meldete grün, während der Lauf, auf den es ankam, gar nicht erst
  startete.

  Behoben an drei Stellen: der Test lädt die Repo-Fassung wie jeder andere;
  ein Wächter in der Manifest-Suite lässt kein Laden aus dem vendorierten
  `plugins/`-Verzeichnis mehr durch (und nennt Datei und Zeile); und die CI
  fährt jetzt denselben Ein-Prozess-Aufruf wie der wöchentliche Lauf.

- **`composer.lock` auf den aktuellen Framework-Stand gehoben**
  (`56a61ca` → `b15c434`). Die Suite ist dagegen vollständig grün: 929 Tests,
  5.276 Zusicherungen, 26 übersprungen. Die Addons brachen also nie gegen
  Framework-main — der Befund in #160 war die Suite selbst.

### Dokumentation

- **Das README verschwieg sieben von 36 Addons** und schleppte einen Satzrest
  eines gelöschten Eintrags mit. Nachgetragen sind `titel-praemierungen`,
  `pferd-des-tages`, `plausibilitaetspruefung`, `mitgliedsstatus`,
  `mitglieder-konten`, `embed-widget` und `datenmigration`.

- **`docs/releasing.md` behauptete, das Framework falle auf den Branch-Stand
  zurück**, solange zu einer Kern-Linie kein Release existiert. Das stimmt
  seit Framework#212 nicht mehr: Die automatischen Addon-Updates verweigern
  dann, statt einem veränderlichen HEAD zu folgen — nur der Store-Install als
  bewusste Admin-Aktion darf das. Die falsche Zusage war genau der Grund,
  warum eine fehlende Release-Marke leicht als harmlos durchgeht.

### Tests

- **Pferd des Tages: der Rückzugsfilter im Abrufpfad** (#159). Eine
  getroffene Tageswahl bleibt festgehalten; dass ein danach zurückgezogenes
  Pferd trotzdem verschwindet, leistet allein die Sichtbarkeitsbedingung im
  LEFT JOIN von `gespeichert()`. Das Addon hängt an keinem Hook, der beim
  Depublizieren oder Löschen anspringen würde, und der Fremdschlüssel räumt
  nur beim endgültigen Löschen auf — ein Pferd im Papierkorb behält seine
  Wahl-Zeile.

  Vom Befund war die eine Hälfte bereits abgedeckt: Der Lebenszyklus-Test
  prüft das Depublizieren, allerdings nur gegen eine redaktionelle Vorgabe.
  Neu geprüft werden jetzt der **Papierkorb** — streicht man
  `AND h.deleted_at IS NULL`, blieb die gesamte Suite bisher grün — und die
  **automatisch getroffene** Tageswahl. Dazu, dass die Wahl-Zeile den Rückzug
  überlebt und nach dem Wiederherstellen dasselbe Pferd zurückkommt: Ein
  zweiter Kandidat dient als Zeuge, damit eine unbemerkte Neuwahl auffällt
  statt den Test grün zu lassen.

## [0.9.0-beta.5] – 2026-08-23

> **Warum Beta und nicht 0.9.0.** Ein `v0.9.0` war am 22.08. kurzzeitig
> getaggt und ist zurückgezogen worden — die Freigabe war nicht abgesprochen
> (Framework#402). Null Downloads, keine Instanz betroffen.

Der geprüfte Gesamtstand zur Framework-Linie **0.9**. Dieser Abschnitt fasst
auch zusammen, was zwischendurch als `v0.9.0-beta.3` und `v0.9.0-beta.4`
herausging — für beide wurde seinerzeit kein eigener Abschnitt angelegt.

> **Setzt Kern `v0.9.0` voraus**, wo die Addons an den neuen
> Erweiterungspunkten hängen. Alle Manifeste tragen `core_supported_max: 0.9`.

### Geändert

- **`katalog-export` exportiert ein Geburtsdatum nur noch, wenn es
  tagesgenau ist** (#156, Framework#379). Steht in
  `horses.birth_date_precision` der Wert `year`, sind Monat und Tag
  Platzhalter — in dieser Branche der 1. Januar, im Altbestand bei knapp der
  Hälfte aller Pferde.

  Der Kern zeigt in dem Fall nur noch das Jahr. Diese Datei wird per E-Mail
  weitergereicht und in eine Tabellenkalkulation geladen; dort stand der Tag
  dann als Tatsache, und zwar weiter weg von jeder Korrektur als die Seite,
  von der er stammt.

  Die Zelle bleibt **leer** statt das Jahr zu wiederholen: Genau so sieht die
  Form aus, die der CSV-Import des Kerns als „nur das Jahr bekannt" annimmt
  (leeres `birth_date`, gefülltes `birth_year`). Die Datei lässt sich damit
  ohne Zeilenfehler zurückspielen.

  `katalog-export` 1.2.0 → **1.3.0** und verlangt jetzt Kern `>=0.9.0` — ohne
  die Spalte gibt es die Unterscheidung nicht.

### Geändert

- **Responsives Verhalten der Addon-Fragmente** (#129, Gegenstück zu
  Framework#345).

  Ein Addon liefert sein Fragment **in** eine Kernseite. Was dort überläuft,
  sprengt nicht nur das Fragment, sondern die ganze Seite — und der Betreiber
  sucht den Fehler im Kern. Gemessen wurde auf 360 px:

  - **19 Tabellen in 10 Addons** in `<div class="tabelle-scroll">` gefasst.
    Die Klasse liefert der Kern; kein Addon erfindet sie neu.
  - **Feste Rasterspalten in 7 Addons** durch
    `repeat(auto-fit, minmax(200px, 1fr))` ersetzt.
    `grid-template-columns: 1fr 1fr` heisst auf 360 px zwei Felder von je rund
    150 Pixeln.
  - **Bildreihen in `merkliste` und `verkaufsboerse`** brechen jetzt um, statt
    die Textspalte auf Restbreite zu quetschen.
  - **Eigener Umbruchpunkt in `embed-widget` entfernt.** Ein zweiter Satz
    neben dem des Kerns wäre genau die Doppelung, die dieses Issue beendet.

  Zwei neue Regeln in `tests/Manifest/PluginThemingLintTest.php` halten das
  fest; die Ansage für Addon-Autoren steht im Kern in
  `docs/plugin-development.md`.

- **`zucht-suche`: Filter und Spalte „Mitgliedsstatus" sind weg**
  (Framework#349).

  Der Kern führt das Freitextfeld nicht mehr. Die Angabe führt jetzt
  `mitgliedsstatus` mit fester Werteliste und Freigabe **je Kontakt** — eine
  öffentliche Trefferliste, die ungefragt danach filtert und die Werte in
  einer Spalte ausgibt, würde genau diese Freigabe umgehen.

  Ein altes Lesezeichen mit `?mitglied=…` schadet nicht: Der Parameter wird
  nicht mehr gelesen und wandert auch nicht in die Blätter-Links zurück.

- **`mitgliedsstatus` verlangt jetzt Kern `>=0.9.0`.** Auf einem 0.8er Kern
  hielte seine zentrale Zusage nicht: Dort veröffentlicht der Kern
  `contacts.membership_status` weiterhin bedingungslos, während das Addon
  Freigabe je Kontakt verspricht — der Betreiber hielte etwas für nicht
  öffentlich, das es ist.

- **`field.membership_status` aus den zehn Sprach-Addons entfernt.** Der Kern
  kennt den Schlüssel seit `v0.9.0` nicht mehr.

### Neu

- **Zehn Sprach-Addons** (`sprache-cs`, `sprache-da`, `sprache-fi`,
  `sprache-fr`, `sprache-it`, `sprache-lb`, `sprache-nb`, `sprache-nl`,
  `sprache-pl`, `sprache-sv`) — Framework#344. Der Kern bringt seit
  `v0.9.0-beta.4` nur noch Deutsch und Englisch mit; jede weitere Sprache ist
  ein eigenes Addon.

  **Wer nicht auf Deutsch oder Englisch läuft, braucht ab dann das passende
  Addon.** Der Kern sagt es von sich aus: Der Umschalter bietet keine Sprache
  mehr an, die auf Deutsch zurückfiele, und Dashboard wie Systemeinstellungen
  nennen die fehlende Sprache samt Addon-Slug.

  Jedes Addon bringt **eine Datei** mit (`lang/core/<code>.php`) und sonst
  nichts — keine Hooks, keine Routen, keine Berechtigungen, keine Tabellen.
  Den Anzeigenamen liefert der Kern.

  `tests/Unit/SprachAddonVollstaendigkeitTest.php` prüft jede Sprache gegen den
  Schlüsselsatz des Kerns: fehlende Schlüssel, leere Werte und verlorene
  Platzhalter. Ohne diese Prüfung verrottete eine Übersetzung unbemerkt —
  fehlende Schlüssel fallen zur Laufzeit still auf Deutsch zurück, und eine
  gemischtsprachige Seite sieht nicht nach einem Fehler aus.

### Geändert

- `PluginManifestTest` lässt für Sprach-Addons ausdrücklich zu, dass sie weder
  `register()` noch `routes()` haben — und verlangt umgekehrt, dass sie es
  **nicht** tun. Ein leeres `register()` nur zur Beruhigung des Tests wäre
  schlimmer als die Ausnahme: Es sagt „hier passiert etwas", wo nichts
  passiert.

### Entfernt

- **Das Addon `galerie` gibt es nicht mehr** (Addons#116, Framework#339). Der
  Kern pflegt Fotos und Videos je Pferd seit `v0.9.0` selbst — samt Hauptbild,
  Reihenfolge, Bildunterschrift und geschützter Auslieferung. Zwei
  Pflegeoberflächen für dieselben Daten wären genau der Zustand, den
  Framework#339 beendet.

  **Für Betreiber:** Das Kern-Update entfernt das Addon von selbst
  (`UpdateService::ABGELOESTE_ADDONS`) — vorher holt der Migrationsschritt
  `339_galerie_uebernahme` Zeilen und Dateien in den Kern. Es geht nichts
  verloren, und es ist nichts von Hand zu tun.

  Addons#116 verlangte ursprünglich nur, Dashboard-Kachel und eigene
  Verwaltungsseite zu entfernen. Das ist mit dem ganzen Addon erledigt; die
  dort offene Frage nach dem Rechteschnitt (`galerie.manage` neben
  `horses.edit`) beantwortet Framework#339: Wer `horses.edit` hat, pflegt die
  Medien — eine eigene Berechtigung gibt es nicht mehr.

  **Was bewusst wegfällt**, wie in Addons#116 festgehalten: die bestandsweite
  Übersicht „Erfasste Medien". Die Frage „welche Medien liegen insgesamt im
  Verzeichnis" hat keine Antwortseite mehr — die Pflege läuft am Pferd, und
  dort war sie schon vollständig.

  Nebenbefund aus Addons#116 mit erledigt: Das Dateifeld bot `image/gif` an,
  serverseitig war GIF nicht erlaubt. Der Kern lässt es jetzt beides.

  **Und ein Fund beim Aufräumen:** Der Unit-Test `GalerieVideoUrlTest` lief
  nach dem Entfernen ins Leere — und beim Nachsehen, was er eigentlich prüfte,
  stellte sich heraus, dass die Kern-Fassung **schwächer** prüfte als das
  Addon: nur das Schema statt einer Host-Allowlist mit Neubau der URL aus den
  geprüften Teilen. Im Kern nachgezogen (Framework#339, PR #388), Tests
  übernommen. Eine Ablösung, die weniger kann als das Abgelöste, ist der
  unangenehmste Fall dabei — die Oberfläche sieht gleich aus.


### Neu

- **`mitglieder-konten`** (Addons#131): legt Benutzerkonten für
  Verbandsmitglieder aus einer CiviCRM-Instanz an. Benutzername =
  Mitgliedschafts-ID, Erstpasswort erzeugt, Zuordnung zu einer reinen
  Lesegruppe. Hat ein Mitglied kein eigenes Postfach, gehen die Zugangsdaten
  **gesammelt** an das Verwaltungsteam.

  **Es gleicht keine Daten ab.** Kein Mitgliedsstatus, keine Anschrift, nichts
  zurück nach CiviCRM. CiviCRM beantwortet zwei Fragen: wer bekommt ein Konto,
  unter welcher Nummer. Der Zugang kann deshalb auch nur lesen.

  Drei Dinge, die den Unterschied machen:

  - **Vorschau statt Automatik.** Auf der Erprobungsinstanz stehen 1.496
    Mitglieder; ein Lauf, der ungefragt 1.496 Konten anlegt und 1.496 Mails
    verschickt, ist nicht rückholbar. Die Vorschau zeigt je Zeile, was
    geschähe — anlegbar, hat schon ein Konto, oder der Hinderungsgrund. Je
    Durchgang höchstens 100 Konten.
  - **Keine stille Zweitanlage.** Die Zuordnung Mitgliedschafts-ID →
    Benutzer-ID hat die Mitgliedschafts-ID als Primärschlüssel; ein zweiter
    Lauf legt nichts noch einmal an und setzt kein Passwort zurück.
  - **Endet die Mitgliedschaft, wird gesperrt — nie gelöscht.** Und ist
    CiviCRM nicht erreichbar, sperrt der Lauf **nichts**: „konnte nicht
    prüfen" und „geprüft, läuft nicht mehr" sind verschiedene Aussagen. Ohne
    diese Trennung räumte ein Netzausfall über Nacht den ganzen Bestand ab.

  Der API-Schlüssel liegt verschlüsselt (AES-256-GCM, wie das TOTP-Secret im
  Kern) und wird nie wieder angezeigt. Das Konto legt der Kern an
  (`App\Service\UserProvisioning`, Framework#384), nicht das Addon.

### Geändert

- `composer.lock` auf Kern `44fbed2` (`v0.9.0-beta.3`) — das neue Addon setzt
  `UserProvisioning` voraus.

## [0.9.0-beta.2] – 2026-08-22

Anschluss an **Framework v0.9.0-beta.2** (Anmelde-Block: Anmeldung über den
Benutzernamen, zweiter Faktor per E-Mail).

**An den Addons selbst hat sich nichts geändert.** Alle 26 stehen weiterhin auf
`core_supported_max: "0.9"` und waren damit auch auf dem neuen Kern schon
sichtbar — dieser Release ist, anders als `0.9.0-beta.1`, **keine Pflicht**.

Er wird trotzdem gesetzt, und zwar aus einem handfesten Grund: `composer.lock`
nagelt den Kern über einen Branch-Zeiger fest, und Dependabot hebt Zweig-Zeiger
nicht. Bleibt der Lock stehen, prüft die Addons-Suite dauerhaft gegen einen
alten Kern und meldet grün — genau der Fall, der in diesem Repo schon einmal
tagelang unbemerkt lief. Der Lock zeigt jetzt auf `1a6f04a`.

### Geprüft gegen den neuen Kern

Die Bruchstelle des Kerns (Login-Formularfeld `email` → `kennung`) betrifft
kein Addon: Kein Plugin bedient das Anmeldeformular, und keines liest die
`users`-Tabelle. Die Testsuiten laufen über `FunctionalTestCase` des Kerns,
das den neuen Feldnamen mitbringt.

| Suite | |
|---|---|
| Manifest | 372 Tests |
| Unit | 199 Tests |
| Functional | 61 Tests (gegen den echten Kern `1a6f04a`) |

## [0.9.0-beta.1] – 2026-08-21

Anschluss an **Framework v0.9.0-beta.1**. Alle 26 Addons stehen auf
`core_supported_max: "0.9"`.

### Dieser Release ist Pflicht

Ein Addon mit `core_supported_max: "0.8"` ist auf einem Kern der Linie 0.9
**fail-closed unsichtbar** — das ist die Kompatibilitätsmechanik und kein
Fehler. Wer den Kern auf 0.9.0-beta.1 hebt, ohne die Addons nachzuziehen, sieht
aktivierte Addons, deren Funktionen verschwunden sind.

### Inhaltlich

Keine funktionalen Änderungen an den Addons. Der Kern-Block dieser Beta (#340
API-Schlüssel-Ablauf, #357 Profilseite, #358 Sperre statt Löschung) berührt
kein Addon-Verhalten; die Suiten laufen unverändert gegen den neuen Kern
(Manifest 372, Unit 199, Functional 61).

**Für Betreiber mit eigenen Addon-Anbindungen an die JSON-API:** Die
Bestandsschlüssel laufen mit dem Kern-Update ab (Framework #340). Das trifft
auch Skripte, die über einen API-Schlüssel auf Addon-Daten zugreifen.

### Noch offen im Meilenstein

#131 (Mitglieder als Benutzer anlegen) setzt Framework #348 und #354 voraus,
#116 (galerie-Verwaltungsseite entfernen) setzt Framework #339 voraus. #129
(responsives Verhalten) folgt mit dem Kern-Gegenstück #345.

## [0.8.0] – 2026-08-21

Die stabile Fassung der 0.8er-Linie. Inhaltlich ist sie `0.8.0-beta.2` plus
zwei Befunde eines Code-Scans, der vor der Freigabe lief.

### galerie

- **Die Bildauslieferung reihte sich selbst auf** (#142). `BildController::serve()`
  hatte die Sicherheitsregeln des Kerns wortgetreu übernommen, aber keine
  seiner Entlastungen. Vor allem fehlte `session_write_close()`: PHPs
  Standard-Sitzungsspeicher hält die Sitzungsdatei bis zum Ende des Requests
  exklusiv gesperrt, und `config/config.php` startet für **jeden** Besucher
  eine Sitzung. Eine Verwaltungsseite mit 50 Vorschaubildern löst 50 Anfragen
  aus — die liefen damit hintereinander statt parallel, bei 60 ms je Anfrage
  rund 3 s blockiertes Nachladen. Der Kern gibt die Sperre aus genau diesem
  Grund frei.

  Dazu jetzt `ETag` und `Last-Modified` samt 304-Behandlung. Das hilft
  besonders bei unveröffentlichten Pferden: Dort gilt `no-store`, der Browser
  darf also nichts ablegen — mit einer bedingten Anfrage spart er trotzdem die
  Übertragung.

  **Nicht enthalten:** der Bootstrap-Kurzschluss und echte Vorschaubilder. Für
  das erste fehlt im Kern der Erweiterungspunkt (`/media/horse-image` ist in
  `public/index.php` fest verdrahtet, eine Addon-Route kann das nicht), das
  zweite ist eine eigene Bildverarbeitungsstrecke samt Migration des
  Bestands. Beides steht als eigenes Issue.

### plausibilitaetspruefung

- **Die Trennlinie zwischen Lesen und Abhaken hielt keine Zusicherung**
  (#143). Das Addon führt zwei Rechte: `bericht` öffnet die Liste, `abhaken`
  hebt eine Veröffentlichungssperre auf. Getestet war nur ein Benutzer, der
  beide nicht hat — dass ein reiner Leser keine Blocker abräumen darf, stand
  nirgends fest. Fiele die zweite Prüfung bei einem Umbau weg (sie sieht neben
  dem `requirePermission` des Konstruktors wie eine Dopplung aus), dürfte
  jeder Leser Pferde mit widersprüchlicher Abstammung öffentlich schalten. Der
  Knopf ist in der Ansicht zwar ausgeblendet, aber ein direkter POST kommt
  ohne Knopf aus.

### Tests

- **Die Lückenliste des Hook-Abdeckungstests ist leer** (`DOKU_LUECKEN` in
  `BeispielErweiterungspunkteAbdeckungTest`). Die vier Hooks aus Framework
  #335, #346 und #356 stehen inzwischen in der Hook-Tabelle des Kerns; der
  Test verlangt in genau diesem Fall, dass der Eintrag verschwindet.

  Bemerkenswert daran ist, **wie** es aufgefallen ist: Gegen den in
  `composer.lock` festgenagelten Kern war der Test grün, gegen den aktuellen
  rot. Dieselbe Klasse wie in Framework #151 — eine über einen Zweig-Zeiger
  eingebundene Abhängigkeit wird nicht mitgehoben, und die Suite prüft dann
  dauerhaft gegen einen alten Stand.

## [0.8.0-beta.2] – 2026-08-20

Der Addon-Nachzug zur Kontaktliste (Framework#336) — **21 Issues**, davon
sieben neue Addons. Der Bestand wächst von 19 auf 26.

**Alle Addons brauchen diesen Release.** Ein Kern der Linie 0.8 macht jedes
Addon mit `core_supported_max: "0.7"` fail-closed unsichtbar; das ist die
Kompatibilitätsmechanik und kein Fehler.

### Umgestellt auf die Kontaktliste

- **`kontaktanfrage`** (#136) — der schwerste Fall der Runde. Das Addon führte
  `target_type = 'person'|'station'` als eigenen Diskriminator, und seine
  beiden Tabellen speichern `(target_type, target_id)` **ohne Fremdschlüssel**.
  Person 5 und Station 5 gab es beide; nach der Zusammenführung hätte jede
  gespeicherte Zeile auf einen falschen Kontakt gezeigt. Beim **Opt-out** wäre
  das eine Datenschutz-Regression im Wortsinn gewesen: Wer Kontaktanfragen
  abbestellt hat, wäre wieder erreichbar, und jemand anderes stumm geschaltet.

  Die Umrechnung über `contact_id_map` läuft **genau einmal**, transaktional
  und mit Marker in derselben Transaktion — ein Abbruch nimmt beides zurück,
  es gibt keinen Zwischenstand „umgerechnet, aber Marker fehlt". Anfragen ohne
  Abbildung behalten `contact_id = 0` und erscheinen als „Datensatz entfernt"
  statt still zu verschwinden.

- **`zucht-suche`** (#122) — die zwei festen Reiter fallen weg. An ihre Stelle
  tritt ein Rollenfilter, denn seit der Zusammenführung ist ein Hof derselbe
  Datensatz, der gleichzeitig züchtet, Pferde besitzt und Deckstation ist. Ein
  Reiter müsste sich für eine Aussage entscheiden und die anderen unsichtbar
  machen. „Deckstation" bekommt **kein** neues Kennzeichen — das schriebe die
  abgeschaffte Gattung wieder in die Daten.

- **`katalog-export`** (#137), **`deckanfrage`** (#138),
  **`statistik-dashboard`** (#139).

### Doppelungen aufgelöst

- **Sieben eigene Pferdesuchen** (#125) ersatzlos gestrichen — der Kern bringt
  seit Framework#341 eine mit. Nachgemessen und dabei den Issue-Text
  korrigiert: Es maskierten **fünf** der sieben Kopien die SQL-Platzhalter
  `%` und `_`, nicht eine.

- **Der Inzuchtkoeffizient stand zweimal im Repo** (#123), nach
  Kommentarentfernung zeichengleich. Jetzt eine Klasse unter einem Namen, von
  beiden Addons zeichengleich mitgeliefert. Ein Symlink schied aus: Der
  Addon-Installer verwirft ein Paket, sobald es einen Symlink enthält
  (Pfad-Traversal-Schutz). Ein Test verbietet die Formel **ausserhalb** dieser
  einen Datei — er hätte den Befund am Tag seiner Entstehung gemeldet.

- **`besucherstatistik` ist in `statistik-dashboard` aufgegangen** (#127).

### Pflege wandert in die Pferdeseite

**#115**, **#117**, **#119**, **#120**, **#124** — Verwaltungsseiten und
Dashboard-Kacheln entfallen, die Pflege läuft über `horse.edit_sections`.
Fail-closed: ohne das jeweilige Recht erscheint der Abschnitt gar nicht.

### Neue Addons

- **`plausibilitaetspruefung`** (#114) — findet Widersprüche und verhindert
  ihre Veröffentlichung über `horse.publish_blockers`. Blockierend ist
  ausschliesslich, was **nicht wahr sein kann** (Elternteil jünger als das
  Fohlen, Vater gleich Mutter, Tod vor Geburt, Zeitraum nach dem Tod). Alles
  andere meldet nur: „Gestorbenes Pferd mit offenem Halterzeitraum" trifft im
  Bestand 35 Datensätze und wäre als Blocker eine Zumutung — es nähme 35
  gepflegte Seiten vom Netz, um eine Konvention durchzusetzen, über die
  niemand entschieden hat.

- **`captcha-turnstile`**, **`captcha-hcaptcha`**, **`captcha-altcha`**
  (#133) — drei Anbieter für die `captcha.*`-Hooks. Ohne Schlüssel meldet
  sich ein Anbieter gar nicht erst an.

- **`pferd-des-tages`** (#135) — hängt in `home.sections_top`. „Des Tages"
  heisst: **ein** Pferd je Kalendertag für alle Besucher, aus dem Datum
  abgeleitet statt je Aufruf gewürfelt.

- **`beispiel-erweiterungspunkte`** (#128) — belegt jeden Erweiterungspunkt
  des Kerns mit einem sichtbaren Ergebnis. Die Hooks wurden ausgezählt, nicht
  geschätzt: Der Kern löst 22 eigene aus, dazu 8 Aliasse.

- **`mitgliedsstatus`** (#132) — Mitglied/Nichtmitglied je Kontakt mit fester
  Werteliste statt Freitext, plus die CiviCRM-Verlinkung aus #130. Die
  Übernahme der Bestandswerte läuft markergeschützt: Der Kern entfernt
  `contacts.membership_status` in v0.9.0, und ohne diesen Schritt fielen die
  Werte zwischen die beiden Releases.

### Weiter

- **`datenmigration`: Auswahl, was exportiert wird** (#121). Bis dahin nahm
  jeder Export zwangsläufig `users` mit — Passwort-Hashes, 2FA-Geheimnisse,
  Backup-Codes — und `api_keys` dazu. Wer nur seine Pferde zu einer anderen
  Instanz tragen wollte, verschickte die Anmeldedaten seines Vereins gleich
  mit. Vorgabe ist jetzt: **Zugangsmaterial ab, Daten an.**

- **Fünf fehlende Protokollierungen nachgezogen** (#134), darunter das Löschen
  eines Gesundheitsdokuments — der heikelste Bestand im Verzeichnis, und es
  verschwand spurlos.

### Behoben

- **Der Sicherheitsscan meldete sieben Fehlalarme.** Backticks in
  einfach-quotierten Strings sind SQL-Bezeichner, kein Shell-Aufruf. Behoben
  ist der **Detektor**, nicht per Allowlist: Der Tokenizer trennt die beiden
  Fälle sauber, ein echter Shell-Backtick ist ein eigenständiges Token.
  Gegenprobe gefahren — `` `ls $dir` `` wird weiterhin gemeldet. Beide
  Allowlists bleiben leer.

- **`ReleaseConsistencyTest` nagelte die Ziel-Linie auf `v0.7.0` fest** und
  wurde beim Sprung auf 0.8 rot, obwohl der Repo-Stand in Ordnung war. Der
  Kommentar daneben warnte vor genau diesem Fehler — bezog sich aber nur auf
  die Addon-*Anzahl*. Die Linie wird jetzt aus den Manifesten abgeleitet.

## [0.7.2] – 2026-08-20

Ein Sicherheitsrelease. Vier Addons lieferten Pferdefotos an der geschützten
Ausliefer-Route des Kerns vorbei aus; damit blieb ein Foto abrufbar, nachdem
das Pferd depubliziert worden war. Keine funktionalen Änderungen daneben.

### Sicherheit

- **Fotos laufen ausnahmslos über die geschützte Route des Kerns**
  (GHSA-xrrq-9j94-fr5g). `qr-code` (1.1.3), `verkaufsboerse` (1.1.4) und
  `merkliste` (1.1.4) gaben den rohen Spaltenwert `horses.image_url` aus —
  also den Speicherort `/uploads/horses/<datei>` statt der Adresse
  `/media/horse-image?id=<pferd>`. Der Kern prüft auf dieser Route je Anfrage
  Sitzung, `horses.view` und `is_published` (Framework #262, #314); genau
  darauf beruht der Schutz, dass die Adresse nur die Pferde-ID trägt und der
  Dateiname nie öffentlich wird. Sobald ein Addon ihn ausgab, war er dauerhaft
  bekannt: Nach `is_published = 0` — etwa nach einem Widerspruch nach Art. 21
  DSGVO — antwortete die Route mit 404, die Datei lieferte aber weiterhin
  ihren Inhalt. Für die Betroffenenanfrage hieß das: Die Depublikation war für
  Fotos wirkungslos. Bei `merkliste` steht die geschützte Adresse jetzt schon
  im JSON, weil das Skript den Wert unbesehen als `img.src` setzt.

- **`galerie` (1.3.0) legt seine Medien außerhalb des Webroots ab.** Für die
  addoneigenen Bilder gab es bisher **überhaupt keine** Prüfung: Sie lagen
  unter `public/uploads/plugin_galerie/`, es existierte keine Route, und der
  Webserver lieferte sie direkt aus. Sie liegen jetzt unter
  `storage/plugin_galerie/` (0750) und sind ausschließlich über
  `/plugin/galerie/bild?id=<medium>` erreichbar — mit denselben Prüfungen wie
  das Kernfoto, samt `X-Content-Type-Options: nosniff`,
  `Cross-Origin-Resource-Policy: same-origin` und `private, no-store` für
  unveröffentlichte Pferde. Das Muster stammt aus `gesundheitstests`, das
  seine Dokumente schon immer so ablegt. **Bestandsdateien zieht `install()`
  beim Update selbsttätig um**; der Schritt ist wiederholbar und stellt
  `file_path` erst um, wenn die Datei nachweislich am Ziel liegt.

- **Regressionstest** `tests/Functional/BildauslieferungTest.php`. Er hält die
  **Abwesenheit** roher `/uploads/`-Pfade in öffentlichen Antworten fest, nicht
  bloß die Anwesenheit der richtigen — ein Test auf „enthält
  /media/horse-image" wäre grün geblieben, während daneben weiterhin der rohe
  Pfad stand. Für JSON-Antworten normalisiert er maskierte Schrägstriche
  (`\/uploads\/`), sonst wäre er ausgerechnet dort blind gewesen, wo der
  Befund lag. Jede der vier Fundstellen wurde einzeln gegengeprobt: Der alte
  Zustand wurde wiederhergestellt und der Test musste rot werden.

## [0.7.0] – 2026-08-18

Diese Linie überspringt 0.6: Der Kern hat mit v0.6.0 und v0.7.0 zwei Releases
kurz hintereinander bekommen, und `core_supported_max: "0.7"` deckt beide ab.
Ein eigenes 0.6-Addon-Release hätte denselben Bestand ein zweites Mal
ausgeliefert.

### Hinzugefügt

- **`zucht-suche` (neu, 1.0.0)** — die öffentliche Einstiegsseite „Zucht".
  Züchter und Deckstationen lassen sich damit nach Name, Ort,
  Bundesland/Kanton, Land und Mitgliedsstatus suchen, statt sie nur über ein
  einzelnes Pferd zu finden. Zwei Reiter, 50 Treffer je Seite, je Eintrag die
  Zahl der zugeordneten veröffentlichten Pferde.

  Grundlage ist `persons.is_breeder` aus dem Kern 0.7.0 — ein redaktionell
  gepflegtes Kennzeichen, ausdrücklich **nicht** aus `horse_persons.role`
  abgeleitet: Wer früher gezüchtet hat, ist heute vielleicht keiner mehr, und
  umgekehrt.

  Der Menüpunkt „🧬 Zucht" steht neben dem Verzeichnis, über den Filter
  `layout.nav_items` des Kerns. Er entfällt, wenn Gäste weder Personen noch
  Deckstationen sehen dürfen — er führte sonst in eine 404, und die Seite
  selbst antwortet in dem Fall fail-closed mit 404 statt mit einer leeren
  Liste.

  **Die Suche gibt keine Kontaktdaten aus.** Sie wählt die Spalten gar nicht
  erst aus. Sie ist ein Einstieg, kein zweiter Weg an `contact_public` vorbei.

- **`kontaktanfrage` (neu, 1.0.0)** — ein Kontaktformular auf Personen- und
  Deckstationsseiten, **ohne** dass die Adresse des Empfängers öffentlich
  wird. Abgefragt werden nur E-Mail, Name und ein Grund aus einer festen
  Auswahl; ein Freitextfeld gibt es bewusst nicht.

  Die Anfrage geht an eine Team-Adresse, nie direkt an die Person. Sie wird
  gespeichert und lässt sich im Backend weiterleiten. Ein Opt-out je Datensatz
  sitzt über `person.edit_sections` / `station.edit_sections` im
  Bearbeitungsformular und liegt in einer **eigenen** Tabelle des Addons — der
  Kern bekommt dafür keine Spalte.

  Das Opt-out gilt auch rückwirkend: Eine schon gespeicherte Anfrage lässt
  sich danach nicht mehr weiterleiten. Wer erklärt hat, keine Anfragen zu
  wollen, ist nicht durch das Datum seiner Erklärung übergangen.

  Härtung: CSRF, Honeypot, zwei Zähler gegen Missbrauch (5/Stunde je Anschluss
  gegen den einzelnen Absender, 10/Tag je Empfänger gegen wechselnde
  Anschlüsse), `FILTER_VALIDATE_EMAIL` samt Ablehnung von CR/LF, Gründe gegen
  eine serverseitige Weißliste, Audit-Log. Fehlender Datensatz, Opt-out oder
  fehlende Team-Adresse melden dem Besucher „Erfolg" — der Rückgabewert darf
  kein Orakel dafür sein, welche IDs es gibt und wer Anfragen abgeschaltet hat.

### Geändert

- **Alle 18 bestehenden Addons auf `core_supported_max: "0.7"`.** Mit dem
  Kern-Release 0.6.0 galten sie als nicht unterstützt und wären nach einem
  Update kommentarlos deaktiviert worden — die angekündigte Wirkung der
  Obergrenze, aber eben eine, die jemand nachziehen muss.

- **Das Release-Gate steht auf Linie 0.7** (`ReleaseConsistencyTest`). Es hing
  noch an v0.5.1. Die Gegenproben sind mitgezogen: Linie 0.6 muss jetzt
  scheitern (`kontaktanfrage` und `zucht-suche` verlangen `>=0.7.0`), Linie
  0.8 ebenfalls (`core_supported_max` 0.7).

### Tests

- **Lifecycle-Tests für beide neuen Addons**, nach dem Muster der 18
  bestehenden — gegen eine echte, per `php -S` gestartete Kern-Instanz. Neu
  ist der Helfer `PersonStationHelper`, der Personen und Deckstationen über
  die echten Admin-Endpunkte anlegt; bisher gab es das nur für Pferde.

  `zucht-suche` prüft dabei die Grenze doppelt: dass das Kennzeichen
  `is_breeder` filtert, dass unveröffentlichte Datensätze draußen bleiben,
  dass keine Kontaktdaten in der Ausgabe stehen, und dass Menüpunkt wie Seite
  ohne Sichtrechte verschwinden.

  `kontaktanfrage` prüft unter anderem den Fall, der in dieser Umgebung
  eintritt: Ohne SMTP schlägt der Versand fehl — die Anfrage muss trotzdem
  gespeichert und im Backend als unzugestellt erkennbar sein, und der Besucher
  bekommt ehrlich „Fehler" statt eines falschen Erfolgsversprechens.

## [0.5.2] – 2026-08-16

### Sicherheit

- **Alle `nosemgrep`-Unterdrückungen entfernt — samt Ursachen.** Sechs Marker
  waren im letzten Durchgang gesetzt worden, jeder mit Begründung. Begründet
  oder nicht: Sie haben Funde zugedeckt statt sie zu beheben. Der Scan ist
  jetzt ohne eine einzige Ausnahme sauber.
  - **Seitennummern werden validiert statt umgedeutet** (`galerie`,
    `gesundheitstests`, `verkaufsboerse`, `pedigree-export`). `(int) $_GET[…]`
    machte aus `"abc"` eine 0 und aus `"3x"` eine 3; `filter_var` mit
    `FILTER_VALIDATE_INT` lehnt ab, was keine Zahl **ist**, und fällt auf den
    dokumentierten Standard zurück. In `verkaufsboerse` liegt die Prüfung in
    einer eigenen Klasse `Seitenzahl`, weil zwei Controller sie brauchen.
  - **`merkliste`: Die Platzhalterliste hat eine feste Länge**, abgeleitet
    allein aus der Konstanten `MAX_IDS` statt aus der Anzahl übergebener IDs.
    Der Abfragetext ist damit über alle Aufrufe hinweg identisch und enthält
    keinen aus der Eingabe abgeleiteten Wert mehr; aufgefüllt wird mit 0, das
    keine Zeile trifft. Der Index bleibt nutzbar.
  - **`genealogie-vergleich`: feste Abfrage mit zwei Platzhaltern** statt
    einer zur Laufzeit zusammengesetzten `IN`-Liste — nachzuladen sind
    höchstens die beiden gewählten Pferde.
  - **`pedigree-export`: Pferde-ID und Tiefe hinter Methoden mit
    `int`-Rückgabetyp.** Eine Bereinigung mitten im Ausdruck sieht man dem
    Aufrufer nicht an.

### Geändert

- **`pre-commit` in der CI per Hash festgenagelt**
  (`.github/pre-commit-requirements.txt`, erzeugt mit
  `pip-compile --generate-hashes`). OpenSSF Scorecard meldete das
  unversionierte `pip install` als `Pinned-Dependencies` — eine Regression
  gegen die Pinning-Disziplin, die hier sonst überall gilt.

  Eine Versionsangabe allein genügt dafür nicht: `pre-commit==4.6.2` stand
  bereits im Workflow, und Scorecard flaggte die Zeile im Lauf gegen
  `4844a9f` weiter, weil der Abhängigkeitsbaum darunter offen blieb.
  `--require-hashes` schließt ihn. Gleiche Fassung wie im Framework-Repo.

## [0.5.1] – 2026-08-16

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
