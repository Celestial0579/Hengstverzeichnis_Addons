# Mitglieder-Konten

Legt Benutzerkonten für Verbandsmitglieder aus einer **CiviCRM**-Instanz an.
Löst [Addons#131](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/131).

- **Benutzername = Mitgliedschafts-ID** aus CiviCRM
- **Erstpasswort wird erzeugt**, beim ersten Anmelden ist ein Wechsel Pflicht
- **Kein Mitglieds-Postfach?** Die Zugangsdaten gehen gesammelt an das
  Verwaltungsteam
- **Endet die Mitgliedschaft**, sperrt der tägliche Lauf das Konto — nie löschen
- **Tritt ein Mitglied wieder ein**, bekommt es sein altes Konto zurück

## Was es ausdrücklich nicht tut

**Es gleicht keine Daten ab.** Kein Mitgliedsstatus, keine Anschrift, keine
Kontaktdaten, und nichts zurück nach CiviCRM. CiviCRM beantwortet genau zwei
Fragen: *wer* bekommt ein Konto und *unter welcher Nummer*. Der Zugang kann
deshalb auch nur lesen (siehe `CiviApi.php`).

Wer eine CiviCRM-Kennung am **Kontakt** führen will, nimmt das Addon
`mitgliedsstatus` — das ist eine andere Sache und liegt bewusst woanders.

## Voraussetzungen

| | |
|---|---|
| Kern | ab `0.9.0-beta.3` — es braucht `App\Service\UserProvisioning` (Framework#384) |
| CiviCRM | eine Instanz mit APIv4 und einem **lesenden** API-Benutzer, erreichbar über **https** unter einer öffentlichen Adresse (Ausnahme siehe unten) |
| Gruppe | eine **reine Lesegruppe** für die neuen Konten (siehe unten) |

### Warum die Zielgruppe eine reine Lesegruppe sein muss

Seit Framework#348 dürfen Konten **ohne E-Mail-Adresse** nur Leserechte haben —
ohne Adresse gibt es kein „Passwort vergessen", keine Benachrichtigungen und
keinen zweiten Faktor per E-Mail. Mitglieder ohne eigenes Postfach sind aber
genau der Fall, für den dieses Addon gebaut ist.

Der zweite Grund ist die Rechtevergabe: Das Recht `mitglieder_konten.manage`
lässt sich auch an Nicht-Admins geben, etwa an eine Geschäftsstelle. Im Kern
legt dagegen nur ein Admin Konten an und weist Gruppen zu. Stünde hier jede
Gruppe zur Wahl, könnte das Addon-Recht Administrator-Konten erzeugen.

Deshalb stehen **nur reine Lesegruppen** zur Auswahl — `Administrator`, `Gast`
und jede Gruppe mit Bearbeitungs- oder Veröffentlichungsrechten sind
ausgeschlossen. Der Server prüft das beim Speichern, in der Vorschau und
unmittelbar vor dem Anlegen. Bekommt eine gewählte Gruppe später
Schreibrechte, zeigt die Verwaltungsseite statt der Vorschau einen Hinweis,
und es entsteht kein Konto, bis wieder eine Lesegruppe gewählt ist.

## Einrichten

1. Addon unter *Verwaltung → Plugins* aktivieren.
2. In CiviCRM einen API-Benutzer mit **Leserecht** anlegen und einen API-Key
   erzeugen. Der Schlüssel gehört in die KeePass-Datei des Hosts, nicht in
   eine Notiz.
3. Als **Admin** unter *Verwaltung → Mitglieder-Konten* eintragen:
   Basis-Adresse, API-Schlüssel, Zielgruppe, Adresse des Verwaltungsteams,
   optional die Mitgliedschaftsarten (leer = alle).
4. Vorschau ansehen, auswählen, anlegen.

**Den Zugang richtet nur ein Admin ein** (seit 1.1.0). Wer Adresse und
Schlüssel setzt, bestimmt die Datenquelle für Vorschau, Anlage und den
täglichen Lauf — mit einer eigenen Quelle ließen sich Konten nach Belieben
sperren. Das Recht `mitglieder_konten.manage` reicht für die Seite, die
Vorschau und das Anlegen in der vom Admin gewählten Lesegruppe; die
Einstellungen sehen Nicht-Admins nur zum Lesen.

Der Schlüssel wird **verschlüsselt** abgelegt (AES-256-GCM über
`App\Security\Crypto`, derselbe Weg wie das TOTP-Secret im Kern) und nie wieder
angezeigt. Ein leeres Schlüsselfeld heißt „nicht ändern", nicht „löschen" —
aber **nur bei gleicher Adresse**: Der Schlüssel ist an die Basis-Adresse
gebunden. Wer die Adresse ändert, muss ihn neu eingeben; bis dahin wird
nichts gespeichert. Wer die Adresse leert, entfernt auch den Schlüssel.

### Welche Adressen zulässig sind

- nur `https://`, ohne Zugangsdaten (`user:pass@`), Parameter oder `#`
- ein Pfad ist erlaubt, etwa `https://verband.example.org/crm`
- nicht `localhost` und keine private, Loopback-, Link-Local-, CGNAT- oder
  reservierte Adresse — auch nicht versteckt in IPv6 (`::ffff:…`, NAT64
  `64:ff9b::…`, 6to4 `2002:…`)

Geprüft wird zweimal: beim Speichern die Form, bei **jeder** Anfrage das
Ziel. Dann wird der Host aufgelöst, jede Adresse muss öffentlich sein, und
die Verbindung geht genau an die geprüfte Adresse (`CURLOPT_RESOLVE`) — ein
zweiter DNS-Abruf kann sie nicht umlenken. Fehlermeldungen in der Verwaltung
sind bewusst allgemein („nicht erreichbar oder nicht zulässig"); was genau
schiefging, steht nur im Serverprotokoll (`[mitglieder-konten] …`), nie mit
dem Schlüssel.

**CiviCRM im eigenen Netz?** Der Serverbetreiber gibt den Host über die
Umgebungsvariable frei — kommagetrennt, exakte Hostnamen, nicht über die
Oberfläche setzbar, `https` bleibt Pflicht:

```
MITGLIEDER_KONTEN_INTERNE_HOSTS=civicrm.intern.example.org
```

**Mit Egress-Proxy** (`https_proxy`/`HTTPS_PROXY` gesetzt) löst der Proxy
den Host auf; das Festnageln der Adresse greift dann nicht. Die Grenze ist in
dem Fall der Filter des Proxys.

## Der Ablauf

**Vorschau statt Automatik.** Auf der Erprobungsinstanz stehen 1.496
Mitglieder. Ein Lauf, der ungefragt 1.496 Konten anlegt und 1.496 Mails
verschickt, ist nicht rückholbar. Die Vorschau zeigt je Zeile, was geschähe:
anlegbar, hat schon ein Konto, oder der Hinderungsgrund. Je Durchgang werden
höchstens 100 Konten angelegt.

**Keine stille Zweitanlage.** Die Zuordnung Mitgliedschafts-ID → Benutzer-ID
steht in `plugin_mitglieder_konten_zuordnung`, mit der Mitgliedschafts-ID als
Primärschlüssel. Ein zweiter Lauf findet die Zeile und legt nichts noch einmal
an; ein bestehendes Passwort wird nie zurückgesetzt.

**Belegte Adressen sieht die Vorschau.** `users.email` ist eindeutig, auch
für Konten im Papierkorb. Eine Adresse, die schon einem Konto gehört, oder
dieselbe Adresse zweimal im Stapel (etwa eine gemeinsame Familienadresse)
erscheint vorher als „geht nicht" — nicht erst als Fehler beim Anlegen.

**Versandfehler werden gemeldet.** Scheitert die Willkommensmail oder die
Sammelmail an das Verwaltungsteam, oder ist keine Team-Adresse hinterlegt,
nennt die Verwaltung die betroffenen Benutzernamen, und das Protokoll hält
den Fall fest — nie mit Passwort. Ein Weg „Zugangsdaten neu erzeugen und
versenden" fehlt noch (Folge-Issue); bis dahin setzt ein Admin das Passwort
neu, bei Konten mit Adresse geht auch „Passwort vergessen".

**Der tägliche Lauf legt nichts an.** Er fragt den Status der zugeordneten
Mitgliedschaften **gezielt per ID** ab, ohne den Filter „Mitgliedschaftsarten"
— der gilt nur für die Anlage. Gesperrt wird (`deactivated_at`, Grund
`membership_ended`), was CiviCRM ausdrücklich als nicht laufend meldet oder
was dort nicht mehr auffindbar ist. Eine Zeile ohne Statusangabe gilt als
„unklar" und sperrt nicht. Ist CiviCRM nicht erreichbar, sperrt er
**nichts** und schreibt das ins Protokoll: „konnte nicht prüfen" und
„geprüft, läuft nicht mehr" sind verschiedene Aussagen.

**Läuft eine Mitgliedschaft wieder**, entsperrt der Lauf das Konto — aber nur
Sperren mit dem Grund `membership_ended`, und nur Konten, die ausschließlich
in Lesegruppen sind. Eine Sperre durch einen Admin oder die Ruhesperre
bleibt; ein Konto in einer Gruppe mit Schreibrechten meldet der Lauf im
Protokoll zur Prüfung durch einen Admin.

**Unplausibel viele Änderungen hält der Lauf an.** Würden mehr als 20 % der
Konten (mindestens 10) auf einmal gesperrt — oder entsperrt —, ändert er in
dieser Richtung nichts. Die Verwaltungsseite zeigt dann einen Warnkasten mit
Anzahl und Mitgliedschaften; **nur ein Admin** kann bestätigen, und die
Bestätigung gilt nur für genau die angezeigte Menge. Kommen bis dahin weitere
dazu, werden die wieder gegen die Grenze geprüft. Ein Jahreswechsel mit
vielen Austritten braucht deshalb einen Klick — das ist gewollt.

## Zwei Entscheidungen, die man dem Code nicht ansieht

**Benutzername = Mitgliedschafts-ID.** Vom Betreiber so entschieden. Die Folge
gehört benannt: Endet eine Mitgliedschaft und tritt jemand später neu ein,
vergibt CiviCRM eine *neue* Mitgliedschafts-ID. Seit 1.1.0 erkennt die
Vorschau das über die CiviCRM-Kontakt-ID und **übernimmt das alte Konto**
(„wird übernommen"): Die Zuordnung wandert auf die neue Mitgliedschaft, das
Konto wird nach denselben Regeln wie im täglichen Lauf entsperrt, Passwort
und Adresse bleiben. Der Benutzername bleibt dabei die *alte*
Mitgliedschafts-ID. Ein zweites Konto scheiterte ohnehin an der eindeutigen
E-Mail-Adresse. Läuft die alte Mitgliedschaft noch (etwa eine andere Art)
oder liegt das alte Konto im Papierkorb, ist die Zeile blockiert.

**Klartext-Passwort statt Einmal-Link.** Ein Einmal-Link wäre besser, er stünde
nie in einem Postfach. Der Rückweg des Kerns ist aber auf die
E-Mail-*Adresse* geschlüsselt (`password_resets.email`), und die Konten, um die
es hier geht, haben definitionsgemäß keine. Deshalb: erzeugtes Passwort,
`must_change_password = 1`, und der Hinweis in der Mail, es sofort zu wechseln.

## Beim Deinstallieren

Tabelle und Einstellungen verschwinden (Register `owns`). **Die angelegten
Konten bleiben** — sie gehören dem Betreiber, Menschen melden sich damit an.
Ohne die Zuordnung endet allerdings die automatische Sperre bei beendeter
Mitgliedschaft.
