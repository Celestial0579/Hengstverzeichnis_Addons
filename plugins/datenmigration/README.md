# Datenmigration (Instanz-Umzug)

Zieht eine Framework-Instanz auf eine andere um — Datenbank, Uploads und
Pferdefotos in einem Archiv:

| Bestandteil | Inhalt |
|---|---|
| `manifest.json` | Kern-Version, Seitenname, Plugin-Bestand, Zeilen je **enthaltener** Tabelle, die gewählten Gruppen, Fingerabdruck des `APP_KEY`, Namen der verschlüsselten Einstellungen |
| `database.sql` | DB-Dump der ausgewählten Tabellen |
| `uploads/…` | Dateien aus `public/uploads` (Logos, Galerie, Dokumente) — nur wenn die Gruppe „Dateien“ gewählt ist |
| `storage-horses/…` | Pferdefotos aus `storage/horses` (seit 1.3.0) — ebenfalls mit der Gruppe „Dateien“ |
| `geheimnisse.json` | nur mit Exportpasswort: die mit dem `APP_KEY` verschlüsselten Zugangsdaten, umverschlüsselt mit dem Exportpasswort (seit 1.3.0) |

## Was mitgeht, wird ausgewählt (#121)

Bis v0.7 nahm der Export **alles** mit — auch `users` mit den Passwort-Hashes,
den TOTP-Geheimnissen und den Backup-Codes, dazu `api_keys`. Wer nur seine
Pferde und Kontakte weitergeben wollte, verschickte die Anmeldedaten seiner
Instanz gleich mit, ohne es zu merken.

Seit v0.8 stellt Admin → Datenmigration → *Export-Archiv zusammenstellen* die
Frage vorher. Jede Gruppe nennt ihre Tabellen und deren Zeilenzahl:

| Gruppe | Tabellen | Vorgabe |
|---|---|---|
| Pferde, Abstammung & Zuordnungen | `horses`, `horse_registrations`, `horse_persons`, `match_labels` | an |
| Kontakte (Personen & Deckstationen) | `contacts`, `contact_id_map` | an |
| Addon-Daten | `plugins` und alles mit Präfix `plugin_` — außer Tabellen mit Fremdschlüssel auf `users` | an |
| Einstellungen & Branding | `settings`, `addon_repos` | an |
| Protokolle & Auskunftsanfragen | `audit_logs`, `gdpr_requests` | an |
| **Benutzer, Gruppen, Rechte** | `users`, `groups`, `user_groups`, `group_permissions`, `api_keys`, `password_resets`, `login_attempts`, `user_passkeys`, `email_2fa_codes` und **jede Tabelle mit Fremdschlüssel auf `users`** (auch von Addons, z. B. `plugin_mitglieder_konten_zuordnung`) | **aus** |
| Nicht zugeordnete Tabellen | alles Übrige (erscheint nur, wenn es welche gibt) | an |
| Dateien | `public/uploads` und die Pferdefotos aus `storage/horses` | an |

**Warum die Vorgabe so verläuft.** Beide bequemen Enden sind falsch: „alles
angehakt“ macht die Änderung wirkungslos, „nichts angehakt“ erzeugt ein leeres
Archiv und erzieht zum gedankenlosen Alles-Anhaken. Die Linie liegt dort, wo
sie sich in einem Satz begründen lässt — **Zugangsmaterial ist ab, Daten sind
an**. Ein Passwort-Hash oder ein API-Schlüssel verschafft dem Empfänger Zugang,
unabhängig davon, was er vorhat; das ist ein Vorfall, sobald es passiert.
Kontaktdaten und Protokolle sind Inhalte: heikel, aber genau das, was bei einem
Umzug mitsoll. Sie per Vorgabe wegzulassen entzöge dem Regelfall still Daten —
dieselbe Fehlerklasse, nur andersherum.

**Was an Konten hängt, gehört zu den Konten** (seit 1.2.0, Audit M3). Bis
1.1.0 liefen `user_passkeys` und `email_2fa_codes` unter „Nicht zugeordnete
Tabellen“ — per Vorgabe an. Passkeys verließen die Instanz damit mit jedem
Standardexport, und beim Einspielen hängten sie sich an die Konten der
Zielinstanz mit derselben Kennung, während deren eigene Passkeys verschwanden.
Ältere Archive mit diesen Tabellen (ohne `users`) werden weiter eingespielt;
die Tabellen werden dabei **übersprungen**.

**Neue Kern-Tabellen fallen nicht still heraus.** Was in keiner Gruppe steht,
landet in „Nicht zugeordnete Tabellen“ — namentlich sichtbar und per Vorgabe
an. Lieber eine Gruppe, die „diese kenne ich nicht“ sagt, als ein Archiv, das
schweigend unvollständig ist.

## Fremdschlüssel: was ein Teilarchiv tatsächlich anrichtet

Vor dem Erstellen zählt das Addon aus, welche Verweise ihr Gegenstück
verlieren („142 Zeile(n) in `horse_persons` verweisen auf `contacts` — diese
Tabelle ist nicht im Archiv“) und lässt erst danach erstellen. Dazu zählen
auch Verweise **ohne** Fremdschlüssel (siehe „Weiche Verweise“ unten).

Nachgemessen (MariaDB 11.8), damit hier steht, was *passiert*, und nicht, was
passieren sollte: Der Dump setzt `FOREIGN_KEY_CHECKS=0`, wirft die enthaltenen
Tabellen weg und legt sie neu an.

* Zeilen in Tabellen, die **nicht** im Archiv sind, bleiben stehen. Ihre
  Verweise zeigen danach auf die Datensätze **des Archivs mit derselben
  Kennung** — bei zwei verschiedenen Beständen also auf fremde Pferde,
  Kontakte oder Konten; nur wo die Kennung im Archiv fehlt, zeigen sie ins
  Leere. Es gibt **keine** Fehlermeldung.
* Das abschließende `FOREIGN_KEY_CHECKS=1` prüft den Bestand **nicht** nach.
* Die Fremdschlüssel selbst überleben das Neuanlegen der Elterntabelle und
  greifen wieder — aber erst beim nächsten **neuen** Verweis (dann `ERROR
  1452`). Ein `UPDATE` auf ein anderes Feld derselben verwaisten Zeile geht
  durch.

Es fällt also nichts um; es wird still falsch — das Inserat hängt am falschen
Pferd, die Kontaktanfrage geht an die falsche Person. Deshalb die Zahl vorher,
und beim Import die Pflichtwahl (siehe unten).

## Ablauf

1. **Quelle**: Admin → Datenmigration → *Export-Archiv zusammenstellen*,
   Gruppen wählen, erstellen (liegt danach auch in `var/datenmigration/`).
   Teilarchive heißen `datenmigration-teil-…`.
2. **Ziel**: Archiv hochladen — oder (große Archive, PHP-Upload-Grenzen!) per
   SFTP nach `var/datenmigration/` legen.
3. *Prüfen*: Der Dump des Archivs läuft trocken durch die Prüfung (siehe
   „Prüfung des Dumps“). Die Vorschau zeigt Versions- und Plugin-Abgleich,
   die **im Dump gezählten** Zeilen gegen den Stand des Ziels und je Tabelle
   „wird ersetzt“, „wird übersprungen (Grund)“ oder „bleibt unverändert“ —
   nach dem, was tatsächlich im Dump steht, nicht nach dem Manifest.
4. *Anwenden*: erst nach ausdrücklicher Bestätigung, in dieser Reihenfolge:
   Archiv erneut prüfen und entpacken → **vollständigen** Sicherungs-Dump der
   Zielinstanz nach `var/datenmigration/sicherung-…` schreiben → Sicherung
   trocken prüfen → Wartungsmodus → Dump einspielen → ggf. abhängige Zeilen
   trennen → ggf. Zugangsdaten an den `APP_KEY` angleichen → ggf. alle
   Sitzungen beenden → Wartungsmodus aufheben → Dateien und Pferdefotos
   übernehmen → Schutzdateien unter `public/uploads` wiederherstellen. Ein
   abgewiesenes Archiv hinterlässt **keine** Sicherung und ändert nichts.

### Prüfung des Dumps (seit 1.2.0, Audit M1)

Bis 1.1.0 entnahm der Import dem Manifest, welche Tabellen ein Archiv
ersetzt, und führte `database.sql` ungeprüft aus. Ein präpariertes
„Teilarchiv Pferde“ konnte so nebenbei ein Administratorkonto anlegen. Jetzt
wird der Dump Anweisung für Anweisung gegen das Format des Kern-Dumpers
(`App\Service\DatabaseDumper`) geprüft. Zulässig sind nur:

* die Kopf- und Fußzeilen `SET FOREIGN_KEY_CHECKS=0/1`, `SET NAMES utf8mb4`,
  die Zeitzonen-Zeilen des Kerns (`SET @hv_dump_zeitzone = @@SESSION.time_zone`,
  `SET time_zone = '+00:00'`, `SET time_zone = @hv_dump_zeitzone`) und
  `SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'` — sonst kein SET;
* je Tabelle genau ein Block `DROP TABLE IF EXISTS` → `CREATE TABLE` →
  `INSERT … VALUES` (nur `NULL` und `'…'`-Literale);
* Tabellennamen nur aus Kleinbuchstaben, Ziffern und `_` (auf Servern mit
  `lower_case_table_names=1/2` träfe ein Block `USERS` sonst die Tabelle
  `users`);
* Kommentare nur zwischen Anweisungen und nur als `-- …`.

Jede Tabelle muss zur Auswahl des Manifests passen; bei Format 2 muss die
Tabellenliste des Dumps exakt der des Manifests entsprechen. Tabellen mit
Verweis auf `users` in einem Archiv ohne „Benutzer“ werden übersprungen.

**Abgewiesen** werden damit: handgebaute oder nachbearbeitete Dumps, Views,
Trigger, Routinen, `UPDATE`/`DELETE`, `/* */`- und `/*! */`-Kommentare, `#`,
Tabellennamen mit Großbuchstaben, Anweisungen über `max_allowed_packet`,
Archive mit abweichender Tabellenliste und Archive mit doppelten Einträgen
`database.sql`/`manifest.json`. Das ist bewusst streng und geschieht vor jeder
Änderung. **Grenze:** Ein Dump von MySQL 8 oder einer MariaDB, deren
`SHOW CREATE TABLE` Versionskommentare schreibt (`/*!80000 INVISIBLE */`,
`/*!100301 COMPRESSED*/`), wird abgewiesen; der Kern nutzt diese Merkmale
nicht.

Ausgeführt wird über eine **eigene Datenbankverbindung** ohne
Mehrfachanweisungen, mit bereinigtem `sql_mode` und der Zeitzone der
Anwendung. Große Bestände laufen streamend, INSERTs derselben Tabelle werden
zu Sammel-INSERTs zusammengefasst — die frühere 16-MiB-Grenze
(`max_allowed_packet`) gilt nur noch je Anweisung.

### Benutzerkonten ersetzt: alle Sitzungen enden (Audit M2)

Ersetzt der Import eine Tabelle der Gruppe „Benutzer“, wird die
`session_version` **aller** Konten über jeden bisherigen Wert angehoben. Damit
enden alle Sitzungen dieser Instanz — nicht nur die des Importierenden — und
**alle API-Schlüssel werden ungültig**; sie sind danach neu auszustellen. Bis
1.1.0 liefen fremde Sitzungen weiter, nach dem Import aber als das Konto der
Quellinstanz mit derselben Kennung — bis hin zu dessen Administratorrechten.

### Abhängige Zeilen: trennen oder stehen lassen (Audit N23)

Ersetzt ein Import eine Elterntabelle (`horses`, `contacts`, `users` …),
während abhängige Tabellen mit Zeilen stehen bleiben, nennt die Vorschau die
Zahlen und verlangt eine Entscheidung **ohne Vorgabe** — auch bei
Vollarchiven, denn auch die lassen die Tabellen von Addons stehen, die die
Quelle nicht hat:

* **trennen** — nach dem Einspielen werden die Verweise gelöst: Fremdschlüssel
  auf eine nullbare Spalte werden `NULL`, sonst wird die Zeile **gelöscht**;
  weiche Verweise nach ihrer Angabe (`null`, `null_wert` = 0, `loeschen`).
  Richtig, wenn Quelle und Ziel verschiedene Bestände sind. Beim Löschen
  verschwinden auch Dateiverweise in Addon-Zeilen; die Dateien selbst bleiben
  liegen.
* **stehen lassen** — die Zeilen gehören danach zu den Datensätzen des
  Archivs mit derselben Kennung. Richtig nur, wenn das Archiv von dieser
  Instanz stammt.

Nullbarkeit und Fremdschlüssel werden vor dem Import einmal gelesen und
eingefroren. Die Audit-Zeile nennt die getrennten Zeilen.

### Weiche Verweise (`weiche_verweise` in der `plugin.json`)

Verweise ohne Fremdschlüssel sieht `information_schema` nicht. Das Addon
kennt `match_labels` und die Kontaktanfrage-Tabellen selbst; jedes Addon kann
eigene in seiner `plugin.json` angeben:

```json
"weiche_verweise": {
    "plugin_beispiel_eintraege": [
        {"ziel": "contacts", "spalte": "contact_id", "trennen": "null_wert"},
        {"ziel": "horses", "spalte": "ref_id", "wo": {"art": "pferd"}, "trennen": "loeschen"}
    ]
}
```

Harte Grenzen, weil die Angabe steuert, was „trennen“ löscht: Die Tabelle
muss mit `plugin_` beginnen und in `owns.tables` **desselben** Manifests
stehen; Tabelle, Ziel, Spalte und die Schlüssel von `wo` sind schlichte
Bezeichner (`^[a-z0-9_]+$`), die Werte von `wo` Skalare (sie werden
gebunden); `trennen` ist `null`, `null_wert` oder `loeschen`. Ungültige
Einträge werden verworfen und im Serverprotokoll vermerkt. Angaben gelten
auch für deaktivierte Addons. `pferd-des-tages` braucht keinen Eintrag: Seine
Pferdeverweise sind echte Fremdschlüssel, und die Pferde-Kennungen in seinen
Auswahlkriterien stecken in Konfigurationswerten, nicht in einer Spalte.

### Anderer `APP_KEY`: Zugangsdaten mitnehmen (seit 1.3.0, Audit M25)

Mit dem `APP_KEY` verschlüsselt liegen in der Datenbank: das SMTP-Passwort,
die Zugangsdaten der Backup-Ziele, die Secrets von Addons (`captcha-*`,
`mitglieder-konten`) und die TOTP-Geheimnisse der Konten. Der `APP_KEY`
wandert nicht mit. Bis 1.2.0 lag auf einer Zielinstanz mit eigenem Schlüssel
danach unlesbarer Chiffretext — ohne Meldung, bis die erste Mail nicht
rausging.

* **Export:** Das Manifest trägt einen Fingerabdruck des `APP_KEY` (HMAC, der
  Schlüssel lässt sich daraus nicht zurückgewinnen) und die **Namen** der
  verschlüsselten Einstellungen sowie die **Zahl** der TOTP-Geheimnisse. Wer
  die Werte mitnehmen will, gibt ein **Exportpasswort** an (mindestens 12
  Zeichen): Dann liegen sie zusätzlich in `geheimnisse.json`, verschlüsselt
  mit PBKDF2-SHA256 (600 000 Runden) und AES-256-GCM. Das Passwort wird
  weder protokolliert noch auf Zwischenseiten mitgeführt — nach der
  Warnseite ist es erneut einzugeben. Wer Archiv **und** Passwort hat, hat
  die Zugangsdaten; das Passwort also getrennt weitergeben.
* **Gleicher Schlüssel:** nichts zu tun, kein Passwortfeld.
* **Anderer Schlüssel:** Die Vorschau nennt die betroffenen Einstellungen,
  die Zahl der Konten mit TOTP und vorhandene Passkeys — und als Alternative,
  den `APP_KEY` der Quelle zu übernehmen. Anwenden verlangt dann
  * das **Exportpasswort** (falls das Archiv `geheimnisse.json` hat): Die
    Werte werden mit dem `APP_KEY` des Ziels neu verschlüsselt. Ein falsches
    Passwort bricht ab, bevor sich etwas ändert; **oder**
  * die ausdrückliche Zustimmung „Ohne Exportpasswort fortfahren“: Die
    genannten Einstellungen werden **geleert** und sind neu einzutragen.
* Geleert oder neu verschlüsselt wird nur, was wie ein Chiffrat aussieht und
  sich auf dem Ziel **tatsächlich nicht** entschlüsseln lässt — ein
  manipuliertes Manifest kann so keine Klartext-Einstellung wie `site_name`
  leeren.
* **TOTP ohne Exportpasswort** bleibt unangetastet (fail-closed): Leeren
  schaltete den zweiten Faktor still ab. Betroffene melden sich mit einem
  Backup-Code an und richten TOTP neu ein, oder ein Administrator setzt den
  zweiten Faktor zurück.
* **Passkeys** sind an den `APP_KEY` gebunden (Benutzer-Handle) und lassen
  sich nicht umschlüsseln. Sie bleiben stehen, funktionieren aber nicht mehr
  und sind neu zu registrieren; die Vorschau nennt die Zahl.
* **Unbekannt** (Archiv im Format 1/2 oder Ziel ohne `APP_KEY`): nur ein
  Hinweis. Nach dem Import zählt das Addon Werte, die wie ein Chiffrat
  aussehen, sich aber nicht entschlüsseln lassen, und nennt die Zahl im
  Protokoll und in der Abschlussmeldung; geleert wird nichts.

### Wenn Import und Rückweg scheitern

Scheitert der Import, wird die Sicherung streamend zurückgespielt, der
Wartungsmodus aufgehoben, und die Meldung sagt „zurückgespielt“. Vorher wurde
die Sicherung bereits trocken geprüft — lässt sie sich nicht einspielen (etwa
wegen einer View), wird der Import verweigert, **bevor** sich etwas ändert.

Scheitert trotzdem auch das Zurückspielen, bleibt der **Wartungsmodus aktiv**
(auch für Administratoren): Der Marker `var/wartung.lock` wird ohne
Prozesskennung neu geschrieben und verfällt deshalb nie von selbst. Die
Fehlerseite nennt den Pfad der Sicherung. Dann:

1. die Sicherung von Hand einspielen, z. B.
   `gunzip -c var/datenmigration/sicherung-vor-import-….sql.gz | mysql -u … -p DATENBANK`
   oder per phpMyAdmin;
2. danach `var/wartung.lock` löschen.

### Vollarchiv gegen Teilarchiv

|  | Vollarchiv | Teilarchiv |
|---|---|---|
| Datenbank | alle Tabellen ersetzt | nur die enthaltenen Tabellen ersetzt, übrige bleiben stehen |
| Uploads | Verzeichnistausch, alter Stand bleibt als `public/uploads.import-alt` | zusammengeführt; überschriebene Originale nach `var/datenmigration/ersetzte-dateien-…` |
| Uploads ohne Gruppe „Dateien“ | — | `public/uploads` wird **nicht angefasst** |
| Pferdefotos (`storage/horses`) | Inhalt ersetzt: nur hier vorhandene Fotos wandern nach `var/datenmigration/ersetzte-dateien-…/storage-horses`, `.gitkeep` bleibt | zusammengeführt; überschriebene Fotos nach `var/datenmigration/ersetzte-dateien-…/storage-horses` |
| Sitzungen | **alle** werden beendet, API-Schlüssel ungültig (Konten sind ausgetauscht) | bleiben bestehen, sofern keine Tabelle der Gruppe „Benutzer“ ersetzt wurde |
| stehenbleibende abhängige Tabellen | Pflichtwahl trennen/stehen lassen | Pflichtwahl trennen/stehen lassen |

Ob Dateien angefasst werden, entscheidet der tatsächliche Inhalt des Archivs,
nicht das Manifest — ein Manifest ist eine Behauptung. Ein Archiv ohne
Pferdefotos lässt `storage/horses` in Ruhe.

`storage/horses` wird nie als Verzeichnis getauscht, sondern Datei für Datei:
Im Docker-Setup des Kerns ist es ein eigenes Volume (`horses_data`), und ein
Mountpoint lässt sich nicht umbenennen. Unter `storage-horses/` gelten
dieselben Pfad- und Namensregeln wie unter `uploads/`; Punktdateien werden
beim Export ausgelassen und beim Import still verworfen.

Nach dem Umschalten stellt der Import **alle** Schutzdateien des Kerns unter
`public/uploads` wieder her, wo sie fehlen — seit 1.3.0 auch
`public/uploads/horses/.htaccess` (Audit N1), die liegengebliebene
Pferdefotos am alten Ort sperrt. Vorlage ist der Stand des Ziels vor dem
Import (`public/uploads.import-alt`), sonst eine eingebaute Mindestfassung.
Lässt sich eine nicht schreiben, sagen es Abschlussmeldung und Protokoll.

Scheitert die Dateiphase (die Datenbank ist dann schon eingespielt), meldet
der Import „Datenbank importiert, Dateien unvollständig“ samt der
Sicherungen, statt mit einer Fehlerseite zu enden.

### Archivformat

Geschrieben wird Format **3** (seit 1.3.0: `storage-horses/`,
`geheimnisse.json`, Fingerabdruck), gelesen werden **1, 2 und 3**: Ein Archiv
aus v0.7 ist immer ein Vollarchiv, das lässt sich beim Lesen einsetzen.
Archive der Formate 1 und 2 enthalten keine Pferdefotos aus `storage/horses`;
die Vorschau weist darauf hin. Umgekehrt gilt das nicht — datenmigration bis
1.2.x weist ein Format-3-Archiv ab, und das ist richtig: Es würde die
Pferdefotos still übergehen (so wie eine v0.7-Instanz ein Teilarchiv wie einen
vollständigen Stand einspielen würde).

## Grenzen (bewusst)

- **Gleiche Kern-Version Pflicht.** Versionsübergreifender Import braucht
  einen Schema-Migrationslauf im Kern (siehe Feature-Request im Framework-Repo).
- `config/db_config.php`, `APP_KEY`, TLS/Proxy sind Instanz-Infrastruktur und
  wandern nicht mit. Was mit dem `APP_KEY` verschlüsselt ist, siehe „Anderer
  `APP_KEY`“ oben.
- `storage/` wandert nicht mit — **außer** `storage/horses` (Pferdefotos,
  seit 1.3.0). Dort liegen sonst Protokolle (`storage/logs`) und
  Addon-Ablagen; ein pauschales Mitnehmen nähme die Logdateien der
  Quellinstanz mit auf das Ziel.
- Addons wandern nicht mit (nur ihre Daten): Quell-Addons auf dem Ziel
  nachinstallieren; die Vorschau warnt bei Abweichungen. Der Kern deaktiviert
  nach dem Import automatisch alles, was lokal nicht identisch vorliegt
  (Verzeichnis-Fingerabdruck, fail-closed).

## Berechtigungen

`Datenmigration → Export erstellen` und `Datenmigration → Import anwenden`
getrennt vergebbar (Matrix unter `/admin/groups`) — beide verlangen
**zusätzlich** Administratorrechte, weil sie den gesamten Datenbestand
betreffen.

## Deinstallation

Das Register `owns` in der `plugin.json` nennt `var/datenmigration`. Dort
liegen Export-Archive **und die Sicherungs-Dumps vor jedem Import** — also
vollständige Kopien der Datenbank samt Benutzertabelle — und unter
`ersetzte-dateien-…` die beim Import überschriebenen oder entfernten Dateien
und Pferdefotos. Genau das darf beim
Deinstallieren nicht liegenbleiben; der Kern zeigt vorher, wie viele Dateien es
sind (Framework#338).

## Technik

Archivformat ustar (`.tar.gz`, wenn zlib da ist) mit eigenem, streamendem
Schreiber/Leser — bewusst ohne `ext-zip`, das im mitgelieferten Dockerfile
des Kerns fehlt (siehe „keine externen Abhängigkeiten“,
`docs/plugin-development.md`). Ohne zlib liest der Leser ein `.tar` per
`fread()` (bis 1.2.0 endete das mit einem Fatal Error, Audit N22); ein
`.tar.gz` wird dann mit dem Hinweis abgewiesen, es vorher zu entpacken
(`gunzip`) oder zlib nachzuinstallieren.

Der Dump läuft über `DatabaseDumper::dumpTo()` (Framework#231/#342) in eine
Zwischendatei und von dort in das Archiv: streamend, konstanter Speicherbedarf
— der tar-Header braucht die Größe vor dem Inhalt, und eine Datei beantwortet
beides. Schreibfehler werden an der geschriebenen Menge erkannt (`gzwrite()`
meldet eine volle Platte mit 0, nicht mit `false`), die Sicherung vor dem
Import zusätzlich am gzip-Trailer (CRC32 und Länge).

Beim Import liegt der geprüfte Dump bis zur Ausführung in
`var/datenmigration/.import-….sql` (Rechte 0600). Die Datei verschwindet am
Ende des Laufs; liegengebliebene `.import-*`/`.dump-*`-Dateien älter als eine
Stunde entfernt der Aufruf der Übersicht.
