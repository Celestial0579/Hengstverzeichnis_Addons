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
  Das ist bewusst **nicht** dasselbe wie "in der Kontaktzeile der Station
  steht eine Adresse": Der Kern übergibt dem Hook `horse.detail_sections`
  öffentlich gefilterte Daten - die `station_*`-Felder sind null, wenn die
  Station unveröffentlicht (`is_published = 0`, der Default) oder gelöscht ist
  oder der Gast-Gruppe `contacts.view` fehlt. In all diesen Fällen erscheint
  das Formular nicht, auch bei gepflegter Adresse (siehe "Was in `$horse` und
  `$horsePersons` steht" in `docs/plugin-development.md` des Frameworks).
- Schutz gegen Missbrauch: CSRF-Prüfung als erste Hürde, unsichtbares
  Honeypot-Feld (Bots, die es ausfüllen, erhalten scheinbar eine
  Erfolgsmeldung, die Anfrage wird aber verworfen), die Sicherheitsfrage des
  Kerns (siehe unten) sowie IP-basiertes Rate-Limiting (max. 5 Anfragen/Stunde
  über `App\Security\RateLimiter`, eigener `type`-Wert `deckanfrage`). Bei
  unauffindbarem oder unveröffentlichtem Pferd antwortet der Handler ebenfalls
  scheinbar mit Erfolg, ohne etwas zu versenden - bewusst kein
  Existenz-Orakel.
- Jede tatsächlich verarbeitete Anfrage wird in der Tabelle
  `plugin_deckanfrage_requests` protokolliert (inkl. Versandstatus). Name,
  E-Mail-Adresse und Nachricht des Interessenten liegen dort dauerhaft im
  Klartext; eine Löschroutine bringt das Addon nicht mit - für
  DSGVO-Löschersuchen muss der Betreiber die Tabelle selbst bereinigen.

Anzeige und Verarbeitung wenden dieselbe Sichtbarkeitsregel an: Auch der
POST-Handler (`/plugin/deckanfrage/anfrage`) filtert die Deckstation auf
`is_published`, `deleted_at` **und `contact_public`** und prüft zusätzlich die
Rechte der Gast-Gruppe (`horses.view`, `contacts.view`) - ein direkter POST an
ein Pferd, dessen Station auf der öffentlichen Seite nicht erreichbar ist,
wird stillschweigend verworfen (Antwort wie beim Honeypot-Pfad, kein Versand,
kein Existenz-Orakel).

## Die Kontaktliste (Framework #336)

Seit v0.8 gibt es keine Tabelle `breeding_stations` mehr: Personen und
Deckstationen liegen gemeinsam in `contacts`, und `horses.breeding_station_id`
zeigt auf einen Kontakt in der **Rolle** Deckstation. Für dieses Addon hat das
eine Folge, die über den Tabellennamen hinausgeht.

`contacts.contact_public` entscheidet, ob die zustellbaren Felder eines
Kontakts (E-Mail, Telefon, Anschrift) als öffentlich gelten. Bis v0.7 schützte
die Trennung der Tabellen: Eine Deckstation war eine Geschäftsadresse ohne
personenbezogene Felder, ihre E-Mail-Adresse war schlicht öffentlich. Nach der
Zusammenlegung steht dieselbe Spalte auch bei Privatpersonen - und der
Empfänger einer Deckanfrage bekommt Namen, Adresse und Anliegen eines Dritten
zugeschickt.

Deshalb sendet dieses Addon **nur an Kontakte mit `contact_public = 1`**.
Genau das tut auch die öffentliche Pferdeseite, aus der das Formular seine
Anzeigebedingung bezieht; ohne die Bedingung im POST-Handler wäre das Formular
zwar unsichtbar, ein Direkt-POST aber weiterhin zustellbar.

## Sicherheitsfrage (Framework #351)

Das Formular meldet sich als Kontext `deckanfrage` im Captcha-Katalog des
Kerns an ("Deckanfrage an eine Deckstation"). Damit gilt für dieses Formular
der Anbieter aus `captcha_provider_deckanfrage`; ohne eigenen Eintrag die
globale Wahl (`captcha_provider`), ohne globale die eingebaute Rechenaufgabe.
Ein Formular ganz ohne Schutz gibt es nicht.

Hinweis zum Stand v0.8: Eine Oberfläche, in der sich der Anbieter **je
Formular** anklicken lässt, bringt der Kern noch nicht mit -
`CaptchaContext::all()` wird von keiner Einstellungsseite gelesen. Bis dahin
wirkt die globale Wahl; der eigene Schlüssel lässt sich von Hand in der
Tabelle `settings` setzen.

Eine falsch beantwortete Aufgabe führt zu `?deckanfrage=captcha` mit einem
eigenen Hinweistext - nicht zu `fehler`: Das ist der einzige Fehlerfall, den
der Besucher selbst beheben kann. Die Prüfung läuft **vor** der Abfrage des
Pferdes, damit sie nichts über dessen Existenz verrät.

Das Honeypot-Feld heißt seit v0.8 wie das des Kerns
(`App\Security\Captcha::HONEYPOT_FIELD`, vorher `webseite`) und wird mit
`Captcha::honeypotTripped()` geprüft - eine Stelle statt zwei, die
auseinanderlaufen können.

## Deinstallation (Framework #338)

Die `plugin.json` deklariert `plugin_deckanfrage_requests` als eigene Tabelle;
die Deinstallation zeigt vorher, wie viele Anfragen darin liegen.
`uninstall()` räumt zusätzlich ab, was in Kern-Tabellen liegt und sich deshalb
nicht deklarieren lässt: die Rate-Limit-Zeilen (`login_attempts` vom Typ
`deckanfrage`, sie enthalten IP-Adressen) und die Anbieterwahl der
Sicherheitsfrage (`captcha_provider_deckanfrage`).

Die Protokolleinträge bleiben ausdrücklich stehen - sie enthalten keine
Absenderdaten und sind der Nachweis, dass hier Anfragen weitergeleitet
wurden.

**Bekannte Einschränkung:** `App\Service\Mailer::send()` unterstützt aktuell
keinen `Reply-To`-Header - die E-Mail-Adresse des Interessenten wird daher
gut sichtbar im Nachrichtentext genannt, damit die Deckstation manuell
darauf antworten kann, statt direkt per "Antworten"-Knopf.

## Protokollierung

Jede eingegangene Anfrage steht im Audit-Log des Kerns (Kategorie
`deckanfrage`, sichtbar unter **Admin → Protokoll**) - mit Anfrage-Nummer,
betroffenem Pferd und dem Ergebnis der Weiterleitung
([#134](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/134)).
Geschrieben wird über `App\Plugin\PluginAudit::log()` (Framework #352): Die
Kategorie leitet sich aus dem Slug ab und wird gegen die geladenen Addons
geprüft, damit ein Eintrag nicht über seinen Urheber lügen kann.

Name, E-Mail-Adresse und Nachricht des Absenders stehen dort **nicht**: Das
Protokoll wird dauerhaft aufbewahrt und soll die Handlung nachweisen, nicht
die Anfrage ein zweites Mal speichern.

## Berechtigungen

Keine - die Formular-Route ist bewusst öffentlich, analog zum bestehenden
DSGVO-Kontaktformular des Kerns.
