# deckanfrage

Ergänzt ein "Deckanfrage stellen"-Formular auf der öffentlichen
Pferde-Detailseite - sichtbar, wenn die E-Mail-Adresse der verknüpften
Deckstation öffentlich sichtbar ist. Die Anfrage wird direkt an die
Deckstation gesendet, nicht nur allgemein protokolliert wie beim bestehenden
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

- Formular erscheint nur, wenn `$horse['station_email']` im Hook gesetzt ist.
  Das ist bewusst **nicht** dasselbe wie "in `breeding_stations.email` steht
  etwas": Der Kern übergibt dem Hook `horse.detail_sections` öffentlich
  gefilterte Daten - alle `station_*`-Felder sind null, wenn die Station
  unveröffentlicht (`is_published = 0`, der Default neuer Stationen) oder
  gelöscht ist oder der Gast-Gruppe `breeding_stations.view` fehlt. In all
  diesen Fällen erscheint das Formular nicht, auch bei gepflegter Adresse
  (siehe "Was in `$horse` und `$horsePersons` steht" in
  `docs/plugin-development.md` des Frameworks).
- Schutz gegen Missbrauch: CSRF-Prüfung als erste Hürde, unsichtbares
  Honeypot-Feld (Bots, die es ausfüllen, erhalten scheinbar eine
  Erfolgsmeldung, die Anfrage wird aber verworfen) sowie IP-basiertes
  Rate-Limiting (max. 5 Anfragen/Stunde über `App\Security\RateLimiter`,
  eigener `type`-Wert `deckanfrage`). Bei unauffindbarem oder
  unveröffentlichtem Pferd antwortet der Handler ebenfalls scheinbar mit
  Erfolg, ohne etwas zu versenden - bewusst kein Existenz-Orakel.
- Jede tatsächlich verarbeitete Anfrage wird in der Tabelle
  `plugin_deckanfrage_requests` protokolliert (inkl. Versandstatus). Name,
  E-Mail-Adresse und Nachricht des Interessenten liegen dort dauerhaft im
  Klartext; eine Löschroutine bringt das Addon nicht mit - für
  DSGVO-Löschersuchen muss der Betreiber die Tabelle selbst bereinigen.

Anzeige und Verarbeitung wenden dieselbe Sichtbarkeitsregel an: Auch der
POST-Handler (`/plugin/deckanfrage/anfrage`) filtert die Deckstation auf
`is_published` - ein direkter POST an ein Pferd mit unveröffentlichter
Station wird stillschweigend verworfen (Antwort wie beim Honeypot-Pfad,
kein Versand, kein Existenz-Orakel).

**Bekannte Einschränkung:** `App\Service\Mailer::send()` unterstützt aktuell
keinen `Reply-To`-Header - die E-Mail-Adresse des Interessenten wird daher
gut sichtbar im Nachrichtentext genannt, damit die Deckstation manuell
darauf antworten kann, statt direkt per "Antworten"-Knopf.

## Berechtigungen

Keine - die Formular-Route ist bewusst öffentlich, analog zum bestehenden
DSGVO-Kontaktformular des Kerns.
