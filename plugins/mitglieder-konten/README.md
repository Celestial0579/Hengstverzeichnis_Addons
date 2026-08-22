# Mitglieder-Konten

Legt Benutzerkonten für Verbandsmitglieder aus einer **CiviCRM**-Instanz an.
Löst [Addons#131](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/131).

- **Benutzername = Mitgliedschafts-ID** aus CiviCRM
- **Erstpasswort wird erzeugt**, beim ersten Anmelden ist ein Wechsel Pflicht
- **Kein Mitglieds-Postfach?** Die Zugangsdaten gehen gesammelt an das
  Verwaltungsteam
- **Endet die Mitgliedschaft**, sperrt der tägliche Lauf das Konto — nie löschen

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
| CiviCRM | eine Instanz mit APIv4 und einem **lesenden** API-Benutzer |
| Gruppe | eine **reine Lesegruppe** für die neuen Konten (siehe unten) |

### Warum die Zielgruppe eine reine Lesegruppe sein muss

Seit Framework#348 dürfen Konten **ohne E-Mail-Adresse** nur Leserechte haben —
ohne Adresse gibt es kein „Passwort vergessen", keine Benachrichtigungen und
keinen zweiten Faktor per E-Mail. Mitglieder ohne eigenes Postfach sind aber
genau der Fall, für den dieses Addon gebaut ist.

Gibt die gewählte Gruppe mehr als Lesen, weist die **Vorschau** jedes Mitglied
ohne Adresse ab — vorher, nicht nach dem dreihundertsten Konto. Die
Gruppenauswahl auf der Verwaltungsseite markiert solche Gruppen.

## Einrichten

1. Addon unter *Verwaltung → Plugins* aktivieren.
2. In CiviCRM einen API-Benutzer mit **Leserecht** anlegen und einen API-Key
   erzeugen. Der Schlüssel gehört in die KeePass-Datei des Hosts, nicht in
   eine Notiz.
3. Unter *Verwaltung → Mitglieder-Konten* eintragen: Basis-Adresse,
   API-Schlüssel, Zielgruppe, Adresse des Verwaltungsteams, optional die
   Mitgliedschaftsarten (leer = alle).
4. Vorschau ansehen, auswählen, anlegen.

Der Schlüssel wird **verschlüsselt** abgelegt (AES-256-GCM über
`App\Security\Crypto`, derselbe Weg wie das TOTP-Secret im Kern) und nie wieder
angezeigt. Ein leeres Schlüsselfeld heißt „nicht ändern", nicht „löschen".

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

**Der tägliche Lauf legt nichts an.** Er liest nur, welche Mitgliedschaften
laufen, und sperrt Konten, deren Mitgliedschaft nicht mehr dabei ist
(`deactivated_at`, Grund `membership_ended`). Ist CiviCRM nicht erreichbar,
sperrt er **nichts** und schreibt das ins Protokoll: „konnte nicht prüfen" und
„geprüft, läuft nicht mehr" sind verschiedene Aussagen.

## Zwei Entscheidungen, die man dem Code nicht ansieht

**Benutzername = Mitgliedschafts-ID.** Vom Betreiber so entschieden. Die Folge
gehört benannt: Endet eine Mitgliedschaft und tritt jemand später neu ein,
vergibt CiviCRM eine *neue* Mitgliedschafts-ID. Es entsteht dann ein zweites
Konto, und das erste bleibt gesperrt stehen — ein Konto je
Mitgliedschaftszeitraum.

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
