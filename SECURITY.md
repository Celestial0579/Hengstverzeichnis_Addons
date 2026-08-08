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

Es wird jeweils nur die neueste veröffentlichte Version eines Addons mit
Sicherheitsupdates versorgt.

## Bereits umgesetzte Schutzmaßnahmen

Das Sicherheitskonzept des Kerns (2FA, Session-Hardening, CSRF, Verschlüsselung,
Rate-Limiting, Audit-Log u. a.) ist in
[docs/security.md](https://github.com/Celestial0579/Hengstverzeichnis_Framework/blob/main/docs/security.md)
des Frameworks beschrieben. Die Plugins folgen denselben Grundsätzen (gebundene
SQL-Parameter, Ausgabe-Encoding, CSRF-Prüfung, Eingabevalidierung); vor jedem
Release prüfen ein statischer Plugin-Check und ein Kali-DAST-Lauf die Addons
(siehe [`security/`](security/)).
