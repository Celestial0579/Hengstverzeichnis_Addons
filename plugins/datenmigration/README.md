# Datenmigration (Instanz-Umzug)

Zieht eine komplette Framework-Instanz auf eine andere um — **vollständige
Datenübernahme** in einem Archiv:

| Bestandteil | Inhalt |
|---|---|
| `manifest.json` | Kern-Version, Seitenname, Plugin-Bestand, Zeilen je Tabelle |
| `database.sql` | kompletter DB-Dump (alle Tabellen inkl. Benutzer, Gruppen, Einstellungen, `plugin_*`) |
| `uploads/…` | alle Dateien aus `public/uploads` (Pferdebilder, Logos, Galerie) |

## Ablauf

1. **Quelle**: Admin → Datenmigration → *Export-Archiv erstellen* (liegt danach
   auch in `var/datenmigration/`).
2. **Ziel**: Archiv hochladen — oder (große Archive, PHP-Upload-Grenzen!) per
   SFTP nach `var/datenmigration/` legen.
3. *Prüfen*: Manifest-Vorschau mit Versions- und Plugin-Abgleich sowie
   Zählständen Quelle/Ziel.
4. *Anwenden*: erst nach ausdrücklicher Bestätigung. Vorher wird ein
   Sicherungs-Dump der Zielinstanz nach `var/datenmigration/sicherung-…`
   geschrieben; der alte Uploads-Stand bleibt als `public/uploads.import-alt`
   liegen. Danach ist die Sitzung beendet (die Benutzerkonten wurden ja
   ersetzt) — Anmeldung mit den Konten der Quellinstanz.

## Grenzen (bewusst)

- **Gleiche Kern-Version Pflicht.** Versionsübergreifender Import braucht
  einen Schema-Migrationslauf im Kern (siehe Feature-Request im Framework-Repo).
- `config/db_config.php`, `APP_KEY`, TLS/Proxy sind Instanz-Infrastruktur und
  wandern nicht mit.
- Addons wandern nicht mit (nur ihre Daten): Quell-Addons auf dem Ziel
  nachinstallieren; die Vorschau warnt bei Abweichungen. Der Kern deaktiviert
  nach dem Import automatisch alles, was lokal nicht identisch vorliegt
  (Verzeichnis-Fingerabdruck, fail-closed).

## Berechtigungen

`Datenmigration → Export erstellen` und `Datenmigration → Import anwenden`
getrennt vergebbar (Matrix unter `/admin/groups`).

## Technik

Archivformat ustar (`.tar.gz`, wenn zlib da ist) mit eigenem, streamendem
Schreiber/Leser — bewusst ohne `ext-zip`, das im mitgelieferten Dockerfile
des Kerns fehlt (siehe „keine externen Abhängigkeiten“,
`docs/plugin-development.md`).
