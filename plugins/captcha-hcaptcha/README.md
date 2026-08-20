# captcha-hcaptcha

Bindet **hCaptcha** als wählbaren Spam-Schutz für die öffentlichen Formulare an
- über die Erweiterungspunkte `captcha.providers`, `captcha.render` und
`captcha.verify` des Kerns.

Teil von
[Celestial0579/Hengstverzeichnis_Addons#133](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/133)
("mindestens drei Anbieter zur Auswahl"). Die beiden Geschwister sind
`captcha-turnstile` (ebenfalls Drittanbieter) und `captcha-altcha` (selbst
gehostet, ohne Drittanbieter).

> **Lesen Sie den Datenschutz-Abschnitt, bevor Sie dieses Addon aktivieren.**
> Der Kern bringt bewusst kein Drittanbieter-CAPTCHA mit
> (`src/Security/Captcha.php` begründet das ausführlich). Dieses Addon macht
> es möglich - es hebt die Begründung nicht auf.

## Installation

```bash
cp -r captcha-hcaptcha /pfad/zu/Hengstverzeichnis_Framework/plugins/captcha-hcaptcha
```

Danach in dieser Reihenfolge:

1. **Admin → Plugins verwalten** (`/admin/plugins`): aktivieren.
2. **Admin-Dashboard → 🧩 hCaptcha-Schlüssel**: Sitekey und Secret aus dem
   hCaptcha-Dashboard hinterlegen.
3. Auf derselben Seite: **Content-Security-Policy freischalten** (siehe unten -
   hier sind es **vier** Origins).
4. Erst jetzt **Admin → Systemeinstellungen**: hCaptcha als Spam-Schutz
   auswählen.

Vor Schritt 2 erscheint der Anbieter in Schritt 4 **gar nicht** - das ist
Absicht, siehe „Ohne Schlüssel keine Anmeldung".

Berechtigung: `captcha-hcaptcha` / `manage` („Schlüssel verwalten"), zuweisbar
unter **Admin → Gruppen**. Administratoren haben sie immer. Ohne diese
Berechtigung ist die Verwaltungsseite nicht erreichbar (403) und die
Dashboard-Kachel erscheint nicht.

## Content-Security-Policy - hier besonders tückisch

Die Policy des Kerns erlaubt standardmäßig nur Ressourcen von der eigenen
Seite. hCaptcha braucht **vier** Origins:

```
https://js.hcaptcha.com
https://newassets.hcaptcha.com
https://assets.hcaptcha.com
https://api.hcaptcha.com
```

- `js.hcaptcha.com` liefert das Widget-Skript (`script-src`),
- `newassets.hcaptcha.com` und `assets.hcaptcha.com` den Rahmen und die
  Aufgabeninhalte (`frame-src`, `script-src`),
- `api.hcaptcha.com` nimmt die Rückrufe aus dem Rahmen entgegen
  (`connect-src`).

**Eine halbe Freigabe ist schlimmer als keine:** Mit nur `js.hcaptcha.com` lädt
das Skript, und danach bleibt der Rahmen leer. Das Addon wirkt dann „fast
funktionierend", und man sucht überall ausser in der Content-Security-Policy.

Die Verwaltungsseite prüft alle vier, meldet die fehlenden namentlich und trägt
sie auf Knopf in `captcha_domains` (`config/db_config.php`) nach - bereits
eingetragene Origins anderer Addons bleiben erhalten. Wird `CAPTCHA_DOMAINS`
auf Ihrer Instanz über eine **Umgebungsvariable** gesetzt, hat die Vorrang;
dann nennt die Seite den Wert zum Eintragen.

## Datenschutz

**Was passiert:** Jeder Besucher eines geschützten Formulars lädt ein Skript
von hCaptcha. Dabei erhält **Intuition Machines, Inc. (USA)** seine IP-Adresse
und technische Angaben seines Browsers.

**Was hCaptcha von Turnstile unterscheidet - und was nicht:** hCaptcha gilt als
der etablierte Ersatz für Google reCAPTCHA und bietet eine
Auftragsverarbeitung mit EU-Bezug an. Das ändert die Rechtslage in **einem**
Punkt: Sie können einen Vertrag schliessen, der die Verarbeitung ordnet. Es
ändert sie **nicht** darin, dass die IP-Adresse des Besuchers das Haus
verlässt und der Anbieter eine US-Gesellschaft ist.

**Was Sie brauchen:**

- eine Rechtsgrundlage - in aller Regel Art. 6 Abs. 1 lit. f DSGVO,
- einen Eintrag **in Ihrer Datenschutzerklärung**: Empfänger, Zweck, Hinweis
  auf die Übermittlung in ein Drittland,
- bei EU-Verarbeitung zusätzlich den passenden Vertrag mit dem Anbieter.

**Was dieses Addon dafür tut:**

- Der Anzeigename in der Anbieterauswahl lautet
  „hCaptcha (Drittanbieter: Intuition Machines, Inc., USA)".
- Unter dem Widget steht ein Hinweis für den **Besucher**, samt Link auf die
  Datenschutzerklärung von hCaptcha.
- Das Anbieterskript wird **nur auf den geschützten Formularen** geladen.
- Die IP-Adresse des Besuchers wird beim serverseitigen Prüfaufruf **nicht**
  zusätzlich mitgeschickt (`remoteip` bleibt leer).

### Barrierefreiheit

Anders als Turnstile zeigt hCaptcha einem Teil der Besucher eine
**Bildaufgabe**. Nicht jeder kann Bilder zuordnen. hCaptcha bringt dafür eine
eigene Lösung mit (`accessibility.hcaptcha.com`); der Hinweis darauf steht im
Widget. Wenn Ihre Besucherschaft darauf angewiesen ist, ist `captcha-altcha`
die freundlichere Wahl - dort gibt es überhaupt nichts zu lösen.

### Nicht empfohlen für das DSGVO-Portal

Das Formular unter `/dsgvo` ist die Stelle, an der Betroffene ihre Rechte aus
Art. 15/17 DSGVO geltend machen. Ausgerechnet dort ihre IP-Adresse an einen
weiteren Empfänger in einem Drittland zu senden, ist schwer zu rechtfertigen.

Seit Framework#351 lässt sich der Anbieter **je Formular** wählen: Nutzen Sie
hCaptcha für Kontakt- und Deckanfragen und lassen Sie das DSGVO-Portal auf der
eingebauten Rechenaufgabe. Wer ganz ohne Drittanbieter auskommen will, nimmt
`captcha-altcha`.

## Verhalten

### Ohne Schlüssel keine Anmeldung im Anbieterverzeichnis

Solange Sitekey **oder** Secret fehlt, meldet das Addon seinen Slug nicht über
`captcha.providers`. Ein Anbieter ohne Zugangsdaten lehnt jede Prüfung ab; er
in der Auswahl zu führen hiesse, dem Betreiber einen Weg anzubieten, sein
eigenes Formular unbenutzbar zu machen, ohne es zu merken.

### Fail-closed

| Lage | Ergebnis |
|---|---|
| Widget nicht gelöst (leeres Antwortfeld) | **nicht bestanden** - ohne Netzaufruf |
| hCaptcha nicht erreichbar / Zeitüberschreitung | **nicht bestanden**, protokolliert, Fehlertext auf der Verwaltungsseite |
| `invalid-or-already-seen-response` / `expired-or-already-used-captcha` | **abgelaufen** - der Besucher bekommt „bitte neu lösen", nicht „falsch beantwortet" |
| `sitekey-secret-mismatch`, `invalid-input-secret`, … | **nicht bestanden**, protokolliert, Fehlertext auf der Verwaltungsseite |
| Kein entschlüsselbares Secret (Schlüssel entfernt, APP_KEY gewechselt) | **kein Urteil** - der Kern prüft mit seiner eigenen Rechenaufgabe weiter |

`sitekey-secret-mismatch` verdient besondere Aufmerksamkeit: Es ist der Fehler,
den man beim Umzug zwischen zwei hCaptcha-Konten baut, und der einzige, dem man
ohne die Meldung auf der Verwaltungsseite nicht auf die Spur kommt.

### Ein fremdes Urteil wird nicht überschrieben

`captcha.verify` ist eine Filterkette, durch die **alle** installierten
Anbieter-Addons laufen. Ist ein anderer Anbieter gewählt, reicht dieses Addon
den eingehenden Wert unverändert weiter, statt hart `null` zurückzugeben - ein
hartes `null` würde das Urteil eines anderen Anbieters löschen und damit alle
anderen Addons aussperren. Der Testfall
`tests/Functional/CaptchaAnbieterPluginTest.php` hält das fest.

### Wo Schlüssel und Geheimnis liegen

In der Kern-Tabelle `settings`, unter `plugin_captcha_hcaptcha_site_key`,
`plugin_captcha_hcaptcha_secret` und `plugin_captcha_hcaptcha_letzter_fehler`.
Das Secret liegt **verschlüsselt** (`App\Security\Crypto`, AES-256-GCM mit dem
`APP_KEY` der Installation).

Das Geheimnis taucht in **keiner** Antwort auf: Die Verwaltungsseite zeigt nur
„hinterlegt" oder „nicht hinterlegt", das Eingabefeld ist immer leer (ein
leeres Feld heisst „unverändert lassen"; zum Entfernen gibt es ein eigenes
Häkchen), und ins Protokoll gehen nur Fehlercodes des Anbieters, nie das Secret
und nie das Token des Besuchers.

### Protokollierung

Jede schreibende Aktion geht über `App\Plugin\PluginAudit` unter der Kategorie
`captcha-hcaptcha` (Framework#352). Zu finden unter **Admin → Logs**, Filter
`captcha-hcaptcha`.

### Deinstallation

Das Manifest deklariert unter `owns` die drei eigenen Einstellungen; der Kern
zeigt vor dem Löschen, was verschwindet. Zusätzlich räumt `uninstall()` die
Anbieterwahl in `captcha_provider` bzw. `captcha_provider_<kontext>` weg, damit
dort kein toter Anbietername stehen bleibt.

## Grenzen

- **Ohne JavaScript** funktioniert hCaptcha nicht. Im Formular steht dazu ein
  `<noscript>`-Hinweis.
- **Der Prüfaufruf gegen hCaptcha wird nicht automatisch getestet.** Ein Test,
  der eine fremde API anruft, misst deren Erreichbarkeit, nicht diesen Code.
- Bei ausgehend gesperrter Firewall besteht **niemand** die Prüfung
  (fail-closed).

## Was der Kern dazu sagt

- `src/Security/Captcha.php` - warum der Kern selbst kein
  Drittanbieter-CAPTCHA benutzt, und warum der Startwert von `captcha.verify`
  `null` ist.
- `src/Security/CaptchaContext.php` - der Katalog der Formulare, die einen
  Spam-Schutz haben können (Framework#351).
- `src/Security/ContentSecurityPolicy.php` - warum externe CAPTCHA-Anbieter
  `script-src`, `frame-src` **und** `connect-src` brauchen.
- `docs/plugin-development.md`, Abschnitt „Zu den `captcha.*`-Hooks".
