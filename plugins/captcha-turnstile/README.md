# captcha-turnstile

Bindet **Cloudflare Turnstile** als wählbaren Spam-Schutz für die öffentlichen
Formulare an - über die Erweiterungspunkte `captcha.providers`,
`captcha.render` und `captcha.verify` des Kerns.

Teil von
[Celestial0579/Hengstverzeichnis_Addons#133](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/133)
("mindestens drei Anbieter zur Auswahl"). Die beiden Geschwister sind
`captcha-hcaptcha` (ebenfalls Drittanbieter) und `captcha-altcha` (selbst
gehostet, ohne Drittanbieter).

> **Lesen Sie den Datenschutz-Abschnitt, bevor Sie dieses Addon aktivieren.**
> Der Kern bringt bewusst kein Drittanbieter-CAPTCHA mit
> (`src/Security/Captcha.php` begründet das ausführlich). Dieses Addon macht
> es möglich - es hebt die Begründung nicht auf.

## Installation

```bash
cp -r captcha-turnstile /pfad/zu/Hengstverzeichnis_Framework/plugins/captcha-turnstile
```

Danach in dieser Reihenfolge:

1. **Admin → Plugins verwalten** (`/admin/plugins`): aktivieren.
2. **Admin-Dashboard → 🛡️ Turnstile-Schlüssel**: Site-Key und Secret aus dem
   Cloudflare-Dashboard (Bereich *Turnstile*) hinterlegen.
3. Auf derselben Seite: **Content-Security-Policy freischalten** (siehe unten).
4. Erst jetzt **Admin → Systemeinstellungen**: Turnstile als Spam-Schutz
   auswählen.

Vor Schritt 2 erscheint der Anbieter in Schritt 4 **gar nicht** - das ist
Absicht, siehe „Ohne Schlüssel keine Anmeldung".

Berechtigung: `captcha-turnstile` / `manage` („Schlüssel verwalten"), zuweisbar
unter **Admin → Gruppen**. Administratoren haben sie immer. Ohne diese
Berechtigung ist die Verwaltungsseite nicht erreichbar (403) und die
Dashboard-Kachel erscheint nicht.

## Content-Security-Policy - der Punkt, an dem solche Addons scheitern

Die Policy des Kerns erlaubt standardmäßig nur Ressourcen von der eigenen
Seite. Turnstile lädt sein Skript von `challenges.cloudflare.com` und rendert
die Aufgabe von dort in einem `<iframe>`. Fehlt die Freigabe, blockiert der
Browser beides **ohne sichtbare Meldung** - im Formular ist dann einfach nichts
zu sehen, und der Fehler sieht nach einem kaputten Addon aus.

Nötig ist genau ein Origin:

```
https://challenges.cloudflare.com
```

Die Verwaltungsseite dieses Addons prüft das, meldet es und trägt es auf Knopf
in `captcha_domains` (`config/db_config.php`) nach - bereits eingetragene
Origins anderer Addons bleiben dabei erhalten. Wird `CAPTCHA_DOMAINS` auf Ihrer
Instanz über eine **Umgebungsvariable** gesetzt, hat die Vorrang; dann nennt
die Seite den Wert zum Eintragen:

```
CAPTCHA_DOMAINS=https://challenges.cloudflare.com
```

## Datenschutz

**Was passiert:** Jeder Besucher eines geschützten Formulars lädt ein Skript
von Cloudflare. Dabei erhält **Cloudflare, Inc. (USA)** seine IP-Adresse und
technische Angaben seines Browsers.

**Was Sie brauchen:**

- eine Rechtsgrundlage - in aller Regel Art. 6 Abs. 1 lit. f DSGVO
  (berechtigtes Interesse an der Abwehr automatisierter Anfragen),
- einen Eintrag **in Ihrer Datenschutzerklärung**: Empfänger, Zweck, Hinweis
  auf die Übermittlung in ein Drittland.

**Was dieses Addon dafür tut:**

- Der Anzeigename in der Anbieterauswahl lautet
  „Cloudflare Turnstile (Drittanbieter: Cloudflare, Inc., USA)" - die
  Entscheidung fällt in diesem Aufklappmenü, also steht die Information dort.
- Unter dem Widget steht ein Hinweis für den **Besucher**, samt Link auf die
  Datenschutzerklärung von Cloudflare.
- Das Anbieterskript wird **nur auf den geschützten Formularen** geladen, nicht
  auf jeder Seite des Verzeichnisses.
- Die IP-Adresse des Besuchers wird beim serverseitigen Prüfaufruf **nicht**
  zusätzlich mitgeschickt (`remoteip` bleibt leer). Cloudflare sieht sie
  ohnehin aus dem Browser; eine zweite, vom Server ausgehende Übermittlung
  derselben Adresse brächte für die Erkennung praktisch nichts und wäre eine
  weitere Verarbeitung, die Sie begründen müssten.

### Nicht empfohlen für das DSGVO-Portal

Das Formular unter `/dsgvo` ist die Stelle, an der Betroffene ihre Rechte aus
Art. 15/17 DSGVO geltend machen. Ausgerechnet dort ihre IP-Adresse an einen
weiteren Empfänger in einem Drittland zu senden, ist schwer zu rechtfertigen -
genau deshalb bringt der Kern kein Drittanbieter-CAPTCHA mit.

Seit Framework#351 lässt sich der Anbieter **je Formular** wählen: Nutzen Sie
Turnstile für Kontakt- und Deckanfragen und lassen Sie das DSGVO-Portal auf der
eingebauten Rechenaufgabe. Wer ganz ohne Drittanbieter auskommen will, nimmt
`captcha-altcha`.

## Verhalten

### Ohne Schlüssel keine Anmeldung im Anbieterverzeichnis

Solange Site-Key **oder** Secret fehlt, meldet das Addon seinen Slug nicht über
`captcha.providers`. Ein Anbieter ohne Zugangsdaten lehnt jede Prüfung ab; er
in der Auswahl zu führen hiesse, dem Betreiber einen Weg anzubieten, sein
eigenes Formular unbenutzbar zu machen, ohne es zu merken.

### Fail-closed

| Lage | Ergebnis |
|---|---|
| Widget nicht gelöst (leeres Antwortfeld) | **nicht bestanden** - ohne Netzaufruf |
| Cloudflare nicht erreichbar / Zeitüberschreitung | **nicht bestanden**, protokolliert, Fehlertext auf der Verwaltungsseite |
| Cloudflare meldet `timeout-or-duplicate` | **abgelaufen** - der Besucher bekommt die ehrliche Meldung „bitte neu lösen", nicht „falsch beantwortet" |
| Cloudflare meldet einen Konfigurationsfehler (falsches Secret o. Ä.) | **nicht bestanden**, protokolliert, Fehlertext auf der Verwaltungsseite |
| Kein entschlüsselbares Secret (Schlüssel entfernt, APP_KEY gewechselt) | **kein Urteil** - der Kern prüft mit seiner eigenen Rechenaufgabe weiter |

Die letzte Zeile ist der Grundsatz aus `App\Security\Captcha::verify()`:
Ein Urteil nur dann, wenn wirklich eines vorliegt. Der Startwert des Filters
ist `null` („niemand hat geantwortet"), und ein abgestürztes Addon liefert
damit nie versehentlich ein OK.

### Ein fremdes Urteil wird nicht überschrieben

`captcha.verify` ist eine Filterkette, durch die **alle** installierten
Anbieter-Addons laufen. Ist ein anderer Anbieter gewählt, reicht dieses Addon
den eingehenden Wert unverändert weiter, statt hart `null` zurückzugeben - ein
hartes `null` würde das Urteil eines anderen Anbieters löschen und damit alle
anderen Addons aussperren. Der Testfall
`tests/Functional/CaptchaAnbieterPluginTest.php` hält das fest.

### Wo Schlüssel und Geheimnis liegen

In der Kern-Tabelle `settings`, unter `plugin_captcha_turnstile_site_key`,
`plugin_captcha_turnstile_secret` und
`plugin_captcha_turnstile_letzter_fehler`. Das Secret liegt **verschlüsselt**
(`App\Security\Crypto`, AES-256-GCM mit dem `APP_KEY` der Installation) -
derselbe Weg, den der Kern für das SMTP-Passwort und die TOTP-Secrets nimmt.

Das Geheimnis taucht in **keiner** Antwort auf: Die Verwaltungsseite zeigt nur
„hinterlegt" oder „nicht hinterlegt", das Eingabefeld ist immer leer (ein
leeres Feld heisst „unverändert lassen"; zum Entfernen gibt es ein eigenes
Häkchen), und ins Protokoll gehen nur Fehlercodes des Anbieters, nie das Secret
und nie das Token des Besuchers.

### Protokollierung

Jede schreibende Aktion geht über `App\Plugin\PluginAudit` unter der Kategorie
`captcha-turnstile` (Framework#352) - Schlüsseländerungen, CSP-Freischaltung,
Konfigurations- und Netzfehler des Anbieters. Zu finden unter
**Admin → Logs**, Filter `captcha-turnstile`.

### Deinstallation

Das Manifest deklariert unter `owns` die drei eigenen Einstellungen; der Kern
zeigt vor dem Löschen, was verschwindet. Zusätzlich räumt `uninstall()` auf,
was sich nicht deklarieren lässt: Steht dieses Addon in `captcha_provider` oder
in einem der formularbezogenen `captcha_provider_<kontext>`, wird der Eintrag
entfernt, damit dort kein toter Anbietername stehen bleibt. Der Eintrag in
`captcha_domains` bleibt stehen - er ist Teil der Instanzkonfiguration, und
möglicherweise nutzt ihn ein anderes Addon.

## Grenzen

- **Ohne JavaScript** funktioniert Turnstile nicht. Im Formular steht dazu ein
  `<noscript>`-Hinweis; einen Rückfallweg wie bei `captcha-altcha` gibt es
  hier nicht, weil er die Aufgabe des Drittanbieters unterlaufen würde.
- **Der Prüfaufruf gegen Cloudflare wird nicht automatisch getestet.** Ein
  Test, der eine fremde API anruft, misst deren Erreichbarkeit, nicht diesen
  Code. Geprüft ist der Zweig, der ohne Netzaufruf entscheidet.
- Bei ausgehend gesperrter Firewall besteht **niemand** die Prüfung
  (fail-closed). Der Fehlertext auf der Verwaltungsseite nennt den Grund.

## Was der Kern dazu sagt

- `src/Security/Captcha.php` - warum der Kern selbst kein
  Drittanbieter-CAPTCHA benutzt, und warum der Startwert von `captcha.verify`
  `null` ist.
- `src/Security/CaptchaContext.php` - der Katalog der Formulare, die einen
  Spam-Schutz haben können (Framework#351).
- `src/Security/ContentSecurityPolicy.php` - warum externe CAPTCHA-Anbieter
  `script-src`, `frame-src` **und** `connect-src` brauchen.
- `docs/plugin-development.md`, Abschnitt „Zu den `captcha.*`-Hooks".
