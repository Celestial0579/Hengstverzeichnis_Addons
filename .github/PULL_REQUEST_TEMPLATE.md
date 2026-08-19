## Was ändert sich, und warum

<!-- Was war vorher, was ist nachher, und was war der Anlass. Ein Satz zur
     Begründung ist mehr wert als eine Liste geänderter Dateien - die steht
     ohnehin im Diff. -->

Schließt #

## Beleg

<!-- Womit ist das geprüft? Testlauf, Zahlen, Vorher/Nachher. "Läuft bei mir"
     ist kein Beleg. -->

## Prüfliste

- [ ] Testsuite läuft lokal grün (`composer test` bzw. `vendor/bin/phpunit`)
- [ ] `version` in `plugin.json` angehoben, wenn sich das Addon geändert hat
- [ ] `core_compatibility` / `core_supported_max` geprüft, wenn der Kern eine neue Linie hat
- [ ] README des Addons nachgezogen (auch bei geänderten Berechtigungen!)
- [ ] Neue oder geänderte Berechtigungen sind im PR-Text genannt — sie ändern die Rechtevergabe bestehender Installationen
- [ ] Keine personenbezogenen Daten und keine Zugangsdaten in Code, Tests oder Beispielen
- [ ] Bei Sicherheitsbezug: **noch offene** Schwachstellen gehören nicht in diesen Text, sondern in ein [Security Advisory](../../security/advisories/new)
