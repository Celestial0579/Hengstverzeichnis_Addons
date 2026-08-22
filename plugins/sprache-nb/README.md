# sprache-nb

Ergänzt die Oberfläche des Hengstverzeichnisses um **Norwegisch (Bokmål)
(Norsk bokmål)**. Löst
[Framework#344](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/344)
für diese Sprache.

## Warum es dieses Addon gibt

Bis v0.8 lagen zwölf vollständige Sprachdateien im Kern, mit je über
dreihundert Schlüsseln. Jeder neue Text im Kern war damit eine
Übersetzungsaufgabe in elf Fremdsprachen, bevor die Testsuite grün wurde. Seit
v0.9.0 bringt der Kern **Deutsch und Englisch** mit; jede weitere Sprache ist
ein eigenes Addon.

## Was drin ist

Eine Datei: `lang/core/nb.php`. Sonst nichts — keine Berechtigungen,
keine Routen, keine Tabellen, keine Einstellungen.

Der Kern erkennt das Verzeichnis `lang/core/` von selbst und meldet die Sprache
an; den **Anzeigenamen** liefert er ebenfalls, damit im Umschalter nicht
einmal „Norsk bokmål" und einmal „Norwegisch (Bokmål)" steht.

## Installieren

1. Addon unter *Verwaltung → Plugins* aktivieren.
2. Die Sprache steht danach im Umschalter im Fuß der Seite und in den
   Systemeinstellungen zur Auswahl.

Wird das Addon deaktiviert, verschwindet die Sprache aus der Auswahl. Wer sie
als Standardsprache eingestellt hatte, landet auf Deutsch — der Kern meldet
das im Adminbereich.

## Vollständigkeit

`tests/Unit/SprachAddonVollstaendigkeitTest.php` in diesem Repo prüft gegen den
Schlüsselsatz des Kerns: Jede Sprache muss ihn **exakt** abdecken. Fehlende
Schlüssel fielen zur Laufzeit auf Deutsch zurück — eine gemischtsprachige Seite
ist kein akzeptabler Endzustand, und ohne Prüfung verrottet die Übersetzung
unbemerkt.
