# Datenmigration (Instanz-Umzug)

Zieht eine Framework-Instanz auf eine andere um — Datenbank und Uploads in
einem Archiv:

| Bestandteil | Inhalt |
|---|---|
| `manifest.json` | Kern-Version, Seitenname, Plugin-Bestand, Zeilen je **enthaltener** Tabelle, die gewählten Gruppen |
| `database.sql` | DB-Dump der ausgewählten Tabellen |
| `uploads/…` | Dateien aus `public/uploads` (Pferdebilder, Logos, Galerie) — nur wenn die Gruppe „Dateien“ gewählt ist |

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
| Addon-Daten | `plugins` und alles mit Präfix `plugin_` | an |
| Einstellungen & Branding | `settings`, `addon_repos` | an |
| Protokolle & Auskunftsanfragen | `audit_logs`, `gdpr_requests` | an |
| **Benutzer, Gruppen, Rechte** | `users`, `groups`, `user_groups`, `group_permissions`, `api_keys`, `password_resets`, `login_attempts` | **aus** |
| Nicht zugeordnete Tabellen | alles Übrige (erscheint nur, wenn es welche gibt) | an |
| Dateien | `public/uploads` | an |

**Warum die Vorgabe so verläuft.** Beide bequemen Enden sind falsch: „alles
angehakt“ macht die Änderung wirkungslos, „nichts angehakt“ erzeugt ein leeres
Archiv und erzieht zum gedankenlosen Alles-Anhaken. Die Linie liegt dort, wo
sie sich in einem Satz begründen lässt — **Zugangsmaterial ist ab, Daten sind
an**. Ein Passwort-Hash oder ein API-Schlüssel verschafft dem Empfänger Zugang,
unabhängig davon, was er vorhat; das ist ein Vorfall, sobald es passiert.
Kontaktdaten und Protokolle sind Inhalte: heikel, aber genau das, was bei einem
Umzug mitsoll. Sie per Vorgabe wegzulassen entzöge dem Regelfall still Daten —
dieselbe Fehlerklasse, nur andersherum.

**Neue Kern-Tabellen fallen nicht still heraus.** Was in keiner Gruppe steht,
landet in „Nicht zugeordnete Tabellen“ — namentlich sichtbar und per Vorgabe
an. Lieber eine Gruppe, die „diese kenne ich nicht“ sagt, als ein Archiv, das
schweigend unvollständig ist.

## Fremdschlüssel: was ein Teilarchiv tatsächlich anrichtet

Vor dem Erstellen zählt das Addon aus, welche Verweise ihr Gegenstück
verlieren („142 Zeile(n) in `horse_persons` verweisen auf `contacts` — diese
Tabelle ist nicht im Archiv“) und lässt erst danach erstellen.

Nachgemessen (MariaDB 11.8), damit hier steht, was *passiert*, und nicht, was
passieren sollte: Der Dump setzt `FOREIGN_KEY_CHECKS=0`, wirft die enthaltenen
Tabellen weg und legt sie neu an.

* Zeilen in Tabellen, die **nicht** im Archiv sind, bleiben stehen — auch wenn
  ihr Verweis danach ins Leere zeigt. Es gibt **keine** Fehlermeldung.
* Das abschließende `FOREIGN_KEY_CHECKS=1` prüft den Bestand **nicht** nach.
* Die Fremdschlüssel selbst überleben das Neuanlegen der Elterntabelle und
  greifen wieder — aber erst beim nächsten **neuen** Verweis (dann `ERROR
  1452`). Ein `UPDATE` auf ein anderes Feld derselben verwaisten Zeile geht
  durch.

Es fällt also nichts um; es wird still falsch, und der Fehler zeigt sich erst
Wochen später beim Anlegen eines neuen Datensatzes. Deshalb die Zahl vorher.

## Ablauf

1. **Quelle**: Admin → Datenmigration → *Export-Archiv zusammenstellen*,
   Gruppen wählen, erstellen (liegt danach auch in `var/datenmigration/`).
   Teilarchive heißen `datenmigration-teil-…`.
2. **Ziel**: Archiv hochladen — oder (große Archive, PHP-Upload-Grenzen!) per
   SFTP nach `var/datenmigration/` legen.
3. *Prüfen*: Vorschau mit Versions- und Plugin-Abgleich, Zählständen
   Quelle/Ziel und — je Tabelle — „wird ersetzt“ oder „bleibt unverändert“.
4. *Anwenden*: erst nach ausdrücklicher Bestätigung. Vorher wird **immer ein
   vollständiger** Sicherungs-Dump der Zielinstanz nach
   `var/datenmigration/sicherung-…` geschrieben.

### Vollarchiv gegen Teilarchiv

|  | Vollarchiv | Teilarchiv |
|---|---|---|
| Datenbank | alle Tabellen ersetzt | nur die enthaltenen Tabellen ersetzt, übrige bleiben stehen |
| Uploads | Verzeichnistausch, alter Stand bleibt als `public/uploads.import-alt` | zusammengeführt; überschriebene Originale nach `var/datenmigration/ersetzte-dateien-…` |
| Uploads ohne Gruppe „Dateien“ | — | `public/uploads` wird **nicht angefasst** |
| Sitzung | wird beendet (Konten sind ausgetauscht) | bleibt bestehen, sofern die Gruppe „Benutzer“ nicht dabei war |

Ob Dateien angefasst werden, entscheidet der tatsächliche Inhalt des Archivs,
nicht das Manifest — ein Manifest ist eine Behauptung.

### Archivformat

Geschrieben wird Format **2** (mit `auswahl`/`vollstaendig` im Manifest),
gelesen werden **1 und 2**: Ein Archiv aus v0.7 ist immer ein Vollarchiv, das
lässt sich beim Lesen einsetzen. Umgekehrt gilt das nicht — eine v0.7-Instanz
weist ein Format-2-Archiv ab, und das ist richtig: Sie würde ein Teilarchiv wie
einen vollständigen Stand einspielen und alles Nicht-Enthaltene wegwerfen.

## Grenzen (bewusst)

- **Gleiche Kern-Version Pflicht.** Versionsübergreifender Import braucht
  einen Schema-Migrationslauf im Kern (siehe Feature-Request im Framework-Repo).
- `config/db_config.php`, `APP_KEY`, TLS/Proxy sind Instanz-Infrastruktur und
  wandern nicht mit.
- `storage/` wandert nicht mit. Dort liegen Protokolle (`storage/logs`) und
  Addon-Ablagen; ein pauschales Mitnehmen nähme die Logdateien der Quellinstanz
  mit auf das Ziel.
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
vollständige Kopien der Datenbank samt Benutzertabelle. Genau das darf beim
Deinstallieren nicht liegenbleiben; der Kern zeigt vorher, wie viele Dateien es
sind (Framework#338).

## Technik

Archivformat ustar (`.tar.gz`, wenn zlib da ist) mit eigenem, streamendem
Schreiber/Leser — bewusst ohne `ext-zip`, das im mitgelieferten Dockerfile
des Kerns fehlt (siehe „keine externen Abhängigkeiten“,
`docs/plugin-development.md`).

Der Dump läuft über `DatabaseDumper::dumpTo()` (Framework#231/#342) in eine
Zwischendatei und von dort in das Archiv: streamend, konstanter Speicherbedarf
— der tar-Header braucht die Größe vor dem Inhalt, und eine Datei beantwortet
beides.
