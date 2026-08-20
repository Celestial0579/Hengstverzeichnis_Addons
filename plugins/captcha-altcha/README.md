# captcha-altcha

Ein **Rechennachweis im Browser** (Proof of Work) nach dem ALTCHA-Verfahren -
**selbst gehostet**, ohne Drittanbieter, ohne Schlüssel, ohne Übermittlung von
IP-Adressen und ohne Lockerung der Content-Security-Policy.

Teil von
[Celestial0579/Hengstverzeichnis_Addons#133](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/133)
("mindestens drei Anbieter zur Auswahl"). Die beiden Geschwister sind
`captcha-turnstile` und `captcha-hcaptcha` - beide binden einen Drittanbieter
ein, dieses hier nicht.

**Von den dreien ist das hier die datenschutzfreundlichste Wahl und die
einzige, die auch für das DSGVO-Portal in Frage kommt.**

## Installation

```bash
cp -r captcha-altcha /pfad/zu/Hengstverzeichnis_Framework/plugins/captcha-altcha
```

1. **Admin → Plugins verwalten** (`/admin/plugins`): aktivieren.
2. **Admin → Systemeinstellungen**: als Spam-Schutz auswählen.

Mehr ist nicht nötig - es gibt keine Schlüssel zu beantragen, kein Konto
anzulegen und nichts an der Content-Security-Policy zu ändern. Die
Verwaltungsseite (**Admin-Dashboard → 🔒 ALTCHA-Einstellungen**) betrifft nur
zwei Feineinstellungen.

Berechtigung: `captcha-altcha` / `manage` („Einstellungen verwalten"), zuweisbar
unter **Admin → Gruppen**. Administratoren haben sie immer. Ohne diese
Berechtigung ist die Verwaltungsseite nicht erreichbar (403).

## Wie der Nachweis funktioniert

Der Server würfelt eine Zahl `n` zwischen 0 und einer Obergrenze, würfelt dazu
ein zufälliges `salt` und veröffentlicht **nur** `salt` und `sha256(salt . n)`.
Der Browser probiert 0, 1, 2, … durch, bis er dieselbe Prüfsumme herausbekommt,
und schickt das gefundene `n` mit. Der Server rechnet nach.

Es gibt keine Abkürzung: Wer die Zahl haben will, muss im Mittel die halbe
Obergrenze durchrechnen. Für einen Besucher ist das ein Wimpernschlag; für eine
Spam-Maschine, die tausende Formulare pro Minute abschickt, ist es der
Kostenfaktor, um den es geht.

Der Besucher muss **nichts** lösen, nichts anklicken und keine Bilder zuordnen
- er sieht nur eine kurze Fortschrittsanzeige.

### Warum die Aufgabe in der Sitzung liegt

Das ALTCHA-Verfahren signiert die Aufgabe üblicherweise mit einem HMAC und gibt
sie dem Browser mit. Der Server bräuchte dann keinen Zustand, müsste aber gegen
das Wiederverwenden einer einmal gelösten Aufgabe eine Liste benutzter Aufgaben
führen (Tabelle, Index, Aufräumlauf) und ein Geheimnis für den HMAC verwahren.

Hier liegt die Aufgabe stattdessen in der **Sitzung** des Besuchers, genau wie
beim eingebauten Schutz des Kerns. Das erledigt drei Dinge auf einmal:

- **Einmalverwendung ist geschenkt** - beim Prüfen wird die Aufgabe aus der
  Sitzung entfernt, immer, auch bei Erfolg,
- es gibt **keine Tabelle** aufzuräumen (das Manifest deklariert unter `owns`
  entsprechend keine),
- es gibt **kein Geheimnis**, das auslaufen oder auftauchen könnte.

Der Preis: Das Formular muss in derselben Sitzung abgeschickt werden, in der es
geladen wurde - was es ohnehin tut.

## Datenschutz

- **Keine Übermittlung an Dritte.** Weder IP-Adresse noch Browser-Angaben
  verlassen diese Installation. Es gibt keine ausgehende Verbindung und kein
  fremdes Skript.
- **Keine Einwilligung nötig**, kein zusätzlicher Empfänger in der
  Datenschutzerklärung, kein Drittlandbezug.
- **Keine Lockerung der Content-Security-Policy nötig.** Das Rechenskript steht
  im Formularfragment selbst, und `script-src 'self' 'unsafe-inline'` deckt das
  bereits ab. Anders als bei `captcha-turnstile` und `captcha-hcaptcha` gibt es
  hier nichts freizuschalten - und damit auch nicht den Fehler, an dem solche
  Addons sonst scheitern.
- Es werden **keine zusätzlichen Cookies** gesetzt; die Sitzung, in der die
  Aufgabe liegt, ist die des Kerns.

## Die Einschränkung, die Sie kennen müssen

**Der Rechennachweis läuft im Browser.** Ohne JavaScript - oder auf einer
unverschlüsselt ausgelieferten Seite, wo `crypto.subtle` nicht zur Verfügung
steht - kommt kein Nachweis zustande. Auf dem DSGVO-Portal wäre das besonders
unangenehm: Dort machen Betroffene ihre Rechte aus Art. 15/17 DSGVO geltend,
und eine technische Hürde, die sie aussperrt, ist etwas anderes als ein
unbequemes Kontaktformular.

Deshalb gibt es den **Rückfall ohne JavaScript** (Einstellung, standardmäßig
**an**): Ist er aktiv, steht im `<noscript>`-Bereich zusätzlich die
Rechenaufgabe des Kerns, und wer den Nachweis nicht liefern kann, beantwortet
sie.

Das ist ehrlich zu benennen: **Der Schutz ist dann so stark wie die schwächere
der beiden Hürden**, also so stark wie der eingebaute Schutz des Kerns - nicht
stärker. Ein Bot muss den Rechennachweis nicht lösen, wenn die Rechenaufgabe im
Quelltext daneben steht.

Schalten Sie den Rückfall nur ab, wenn Sie bewusst in Kauf nehmen, Besucher
ohne JavaScript auszusperren. Für ein reines Kontaktformular kann das
vertretbar sein; für `/dsgvo` ist es das eher nicht.

## Einstellungen

| Einstellung | Standard | Bedeutung |
|---|---|---|
| Rechenaufwand | `mittel` (bis 100.000 Prüfsummen) | Obergrenze der zu probierenden Zahlen. Im Mittel ist es die Hälfte davon. Gerechnet wird auf dem **Gerät des Besuchers**, nicht auf dem Server. `niedrig` = 20.000, `hoch` = 400.000. |
| Rückfall ohne JavaScript | an | Siehe oben. |

`hoch` ist für Installationen gedacht, die tatsächlich unter Beschuss stehen -
es kostet jeden ehrlichen Besucher spürbar Zeit, und alte Mobilgeräte kostet es
mehr als neue.

Beide liegen als `plugin_captcha_altcha_aufwand` und
`plugin_captcha_altcha_fallback` in der Kern-Tabelle `settings` und stehen im
`owns`-Register des Manifests.

## Verhalten

### Immer wählbar

Die gemeinsame Regel der drei Anbieter-Addons lautet „ohne Schlüssel keine
Anmeldung im Anbieterverzeichnis". Hier greift sie nicht, weil es nichts zu
fehlen gibt: kein Anbieter, keine Zugangsdaten, keine ausgehende Verbindung.
Dieses Addon ist einsatzbereit, sobald es aktiviert ist.

### Fail-closed

| Lage | Ergebnis |
|---|---|
| Kein Nachweis, Rückfall **aus** | **nicht bestanden** |
| Kein Nachweis, Rückfall **an** | die Rechenaufgabe des Kerns entscheidet |
| Aufgabe abgelaufen (älter als `Captcha::TTL_SECONDS`) oder nicht mehr in der Sitzung | **abgelaufen** - „bitte neu lösen" |
| Salt, Prüfsumme oder Zahl passen nicht | **nicht bestanden** |
| Derselbe Nachweis ein zweites Mal | **abgelaufen** - die Aufgabe wurde beim ersten Prüfen verbraucht |

**Keine Mindest-Ausfüllzeit.** Der eingebaute Schutz des Kerns weist Formulare
ab, die schneller als drei Sekunden zurückkommen - dort muss ein Mensch eine
Aufgabe lesen und tippen. Hier tippt niemand: Der Browser rechnet, und wie
lange er braucht, hängt am Gerät. Eine Mindestzeit würde schnelle Rechner
bestrafen, ohne einen Angreifer aufzuhalten; dessen Kosten stehen im
Rechennachweis selbst.

### Ein fremdes Urteil wird nicht überschrieben

`captcha.verify` ist eine Filterkette, durch die **alle** installierten
Anbieter-Addons laufen. Ist ein anderer Anbieter gewählt, reicht dieses Addon
den eingehenden Wert unverändert weiter, statt hart `null` zurückzugeben - ein
hartes `null` würde das Urteil eines anderen Anbieters löschen und damit alle
anderen Addons aussperren. Der Testfall
`tests/Functional/CaptchaAnbieterPluginTest.php` hält das fest.

### Protokollierung

Änderungen an den Einstellungen gehen über `App\Plugin\PluginAudit` unter der
Kategorie `captcha-altcha` (Framework#352). Einzelne Prüfungen werden **nicht**
protokolliert - sie sind kein schreibender Vorgang, und ein Eintrag je
Formularabsendung ersäufte das Protokoll.

### Deinstallation

Das Manifest deklariert unter `owns` die beiden eigenen Einstellungen; der Kern
zeigt vor dem Löschen, was verschwindet. Zusätzlich räumt `uninstall()` die
Anbieterwahl in `captcha_provider` bzw. `captcha_provider_<kontext>` weg, damit
dort kein toter Anbietername stehen bleibt.

## Grenzen

- Ein Rechennachweis erhöht die **Kosten** automatisierter Massenanfragen; er
  hält einen gezielten, für diese Seite geschriebenen Angreifer nicht auf.
  Dasselbe sagt der Kern über seine eigene Rechenaufgabe. Der Mengenschutz
  bleibt das IP-Rate-Limiting (`App\Security\RateLimiter`), und der Honeypot
  des Kerns wirkt unabhängig weiter.
- Auf sehr alten Geräten kostet die Stufe `hoch` spürbar Zeit.
- `crypto.subtle` braucht einen sicheren Kontext. Auf einer per HTTP
  ausgelieferten Instanz greift daher immer der Rückfall - ein weiterer Grund,
  HTTPS zu benutzen.

## Was der Kern dazu sagt

- `src/Security/Captcha.php` - warum der Kern bewusst kein
  Drittanbieter-CAPTCHA benutzt (dieselbe Begründung, aus der dieses Addon
  entstanden ist), und warum der Startwert von `captcha.verify` `null` ist.
- `src/Security/CaptchaContext.php` - der Katalog der Formulare, die einen
  Spam-Schutz haben können (Framework#351).
- `docs/plugin-development.md`, Abschnitt „Zu den `captcha.*`-Hooks".
