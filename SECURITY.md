# Security Policy

Dieses Repository enthält Addons/Plugins für das
[Hengstverzeichnis_Framework](https://github.com/Celestial0579/Hengstverzeichnis_Framework).
Da die Plugins im Framework-Kern ausgeführt werden und dieser personenbezogene
Daten (Züchter, Besitzer, Halter) verwaltet, nehmen wir Sicherheitsmeldungen
ernst und bitten darum, sie **nicht** über öffentliche Issues zu melden.

## Eine Sicherheitslücke melden

Bitte nutze **[GitHub Security Advisories](../../security/advisories/new)**
("Report a vulnerability"), um eine Schwachstelle vertraulich zu melden. Betrifft
der Fund den Framework-Kern statt eines Addons, melde ihn bitte im
[Framework-Repository](https://github.com/Celestial0579/Hengstverzeichnis_Framework/security/advisories/new).

Bitte gib nach Möglichkeit an:

- Betroffenes Addon (Slug) und Version/Commit
- Schritte zur Reproduktion (inkl. Beispiel-Request/Payload, falls zutreffend)
- Erwartetes vs. tatsächliches Verhalten
- Mögliche Auswirkungen (z. B. Zugriff auf personenbezogene Daten, XSS, SQLi,
  Rechteausweitung)

## Was du erwarten kannst

- Eingangsbestätigung so schnell wie möglich
- Rückmeldung zur Einschätzung (bestätigt/nicht bestätigt) und geplantem
  weiteren Vorgehen
- Nennung als Melder in den Release Notes, sofern gewünscht — Details
  besprechen wir im Advisory

## Unterstützte Versionen

Dieses Repository veröffentlicht keine Tags oder Releases; maßgeblich ist
stets der aktuelle Stand von `main`. Sicherheitsupdates erfolgen nur dort -
bitte gib bei Meldungen deshalb den Commit-Hash an (die `version` in
`plugins/<slug>/plugin.json` allein reicht zur Eingrenzung nicht aus).

## Bereits umgesetzte Schutzmaßnahmen

Das Sicherheitskonzept des Kerns (2FA, Session-Hardening, CSRF, Verschlüsselung,
Rate-Limiting, Audit-Log u. a.) ist in
[docs/security.md](https://github.com/Celestial0579/Hengstverzeichnis_Framework/blob/main/docs/security.md)
des Frameworks beschrieben. Die Plugins folgen denselben Grundsätzen (gebundene
SQL-Parameter, Ausgabe-Encoding, CSRF-Prüfung, Eingabevalidierung). Geprüft
wird das automatisiert (siehe [`security/`](security/)):

- Ein **statischer Plugin-Check** läuft bei jedem Push und PR gegen `main`
  und lässt sich vor einem Beitrag auch lokal ausführen:
  `security/plugin-security-scan.sh` (Exit ≠ 0 bei HIGH/CRITICAL-Funden).
- Ein **Kali-DAST-Lauf** läuft wöchentlich sowie auf manuellen Anstoß. Er
  prüft bewusst nur die Deployment-Sicht, ohne die Plugins zu aktivieren -
  das Verhalten der Addon-Routen selbst deckt stattdessen die
  PHPUnit-Functional-Suite ab.
