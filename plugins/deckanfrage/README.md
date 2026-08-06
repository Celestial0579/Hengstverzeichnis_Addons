# deckanfrage

Ergänzt ein "Deckanfrage stellen"-Formular auf der öffentlichen
Pferde-Detailseite - sichtbar, wenn dem Pferd über seine Deckstation eine
E-Mail-Adresse hinterlegt ist. Die Anfrage wird direkt an die Deckstation
gesendet, nicht nur allgemein protokolliert wie beim bestehenden
DSGVO-Kontaktformular des Kerns.

Löst [Celestial0579/Hengstverzeichnis_Addons#12](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/12).

## Installation

```bash
cp -r deckanfrage /pfad/zu/Hengstverzeichnis_Framework/plugins/deckanfrage
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren.
Keine Berechtigung nötig - die Route ist für anonyme Besucher gedacht.
Damit E-Mails tatsächlich versendet werden, muss unter
**Admin → E-Mail & SMTP Einstellungen** ein funktionierender Mailversand
konfiguriert sein (derselbe `App\Service\Mailer`, den der Kern auch für
DSGVO-Benachrichtigungen etc. nutzt).

## Funktionsweise

- Formular erscheint nur, wenn `breeding_stations.email` für die verknüpfte
  Deckstation des Pferdes gesetzt ist.
- Schutz gegen Missbrauch: unsichtbares Honeypot-Feld (Bots, die es
  ausfüllen, erhalten scheinbar eine Erfolgsmeldung, die Anfrage wird aber
  verworfen) sowie IP-basiertes Rate-Limiting (max. 5 Anfragen/Stunde über
  `App\Security\RateLimiter`, eigener `type`-Wert `deckanfrage`).
- Jede tatsächlich verarbeitete Anfrage wird in einer eigenen Tabelle
  protokolliert (inkl. Versandstatus).

**Bekannte Einschränkung:** `App\Service\Mailer::send()` unterstützt aktuell
keinen `Reply-To`-Header - die E-Mail-Adresse des Interessenten wird daher
gut sichtbar im Nachrichtentext genannt, damit die Deckstation manuell
darauf antworten kann, statt direkt per "Antworten"-Knopf.

## Berechtigungen

Keine - die Formular-Route ist bewusst öffentlich, analog zum bestehenden
DSGVO-Kontaktformular des Kerns.
