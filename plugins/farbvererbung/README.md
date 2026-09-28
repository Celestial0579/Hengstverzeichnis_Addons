# farbvererbung — Fjord-Farbvererbungsrechner

Addon für [Hengstverzeichnis_Framework](https://github.com/Celestial0579/Hengstverzeichnis_Framework).
Schätzt die voraussichtliche Fohlenfarbe aus den Farben zweier Elterntiere
anhand der Farbgenetik des Norwegischen Fjordpferds.

## Funktionen

- **Farbrechner** (`/plugin/farbvererbung/rechner`): zwei Elternfarben wählen,
  Wahrscheinlichkeitsverteilung der Fohlenfarbe über die fünf anerkannten
  Falbfarben erhalten. Zugriff nur mit der Berechtigung
  `farbvererbung.calculate` (unter **Admin → Gruppen** zuweisbar; Admins haben
  sie systemseitig immer). Die Route ist nur per direkter URL erreichbar -
  das Addon registriert keine Dashboard-Kachel. Das Nachschlage-Element
  "Farben im Register" dort listet die Farbwerte nicht gelöschter Pferde mit
  eingetragener Farbe, auch unveröffentlichter - zulässig, weil
  berechtigungsgeschützt, aber bewusst mehr als die öffentliche Sicht. Die
  Tabelle ist auf die ersten 200 Pferde (alphabetisch) gedeckelt (#74).
- **Farbe aus dem Register übernehmen** (#125): Neben jedem Farb-Auswahlfeld
  steht ein Pferde-Suchfeld (`hv-pferdesuche`, gespeist aus dem Kern-Endpunkt
  `GET /admin/horses/search?q=…&nur_mit_farbe=1`, Framework#341). Der Rechner
  übernimmt die Farbe des gewählten Pferdes selbst, statt sie - wie das
  frühere Nachschlage-Feld - nur anzuzeigen. Eine ausdrücklich gewählte Farbe
  hat Vorrang; lässt sich die eingetragene Farbe keiner der fünf Falbfarben
  zuordnen, sagt die Seite das und ändert nichts.
  Die addoneigene Route `/plugin/farbvererbung/suche` ist damit entfallen -
  sie war eine von sieben Kopien derselben Pferdesuche (#125). **Achtung:** Der
  Kern-Endpunkt verlangt `horses.view`; wer den Rechner nutzen darf, braucht
  für die Übernahme zusätzlich dieses Leserecht.
- **Detailseiten-Hinweis** (`horse.detail_sections`): ordnet die im Feld
  *Farbe* eingetragene Bezeichnung – sofern erkennbar – einer der fünf
  Falbfarben zu und zeigt die genetische Einordnung an.

## Die fünf Falbfarben

| Farbe (dt. / norw.) | Genetik (Dun fest vorhanden) |
|---|---|
| Braunfalbe (Brunblakk) | schwarze Basis + Agouti, kein Cream — `E_ A_ nn` |
| Graufalbe (Grå) | schwarze Basis ohne Agouti, kein Cream — `E_ aa nn` |
| Rotfalbe (Rødblakk) | fuchsfarbene Basis, kein Cream — `ee nn` |
| Hellfalbe (Ulsblakk) | schwarze Basis + eine Cream-Dosis — `E_ Cr n` |
| Gelbfalbe (Gulblakk) | fuchsfarbene Basis + eine Cream-Dosis — `ee Cr n` |

Cream ist unvollständig dominant: Zwei Dosen (`Cr Cr`) ergeben einen eigenen,
stark aufgehellten Phänotyp, der keine der fünf Falbfarben ist. Als Elternfarbe
ist er daher nicht wählbar; im Ergebnis des Rechners steht er – nur wenn er
vorkommen kann – in einer eigenen Zeile „Doppelte Cream-Dosis (Cr Cr)“.

Alle Fjordpferde tragen das Dun-(Falb-)Gen; die Farbunterschiede entstehen aus
der Grundfarbe (Extension/Agouti) und dem Cream-Gen. Das Grau-Gen (G), das zu
Ausbleichen führt, kommt bei der Rasse praktisch nicht vor und wird bewusst
nicht modelliert.

## Genauigkeit / Annahmen

Da im Register nur der Phänotyp (die Farbe), nicht der exakte Genotyp bekannt
ist, nimmt der Rechner je Genort alle mit der Farbe verträglichen Genotypen als
**gleich wahrscheinlich** an. Reale Anlageträger-Häufigkeiten weichen davon ab –
die Werte sind Schätzungen und ersetzen keinen Gentest. Eindeutige Kreuzungen
sind exakt (z. B. Rotfalbe × Rotfalbe → 100 % Rotfalbe, da `ee × ee` immer `ee`).

Hell- und Gelbfalbe tragen genau eine Cream-Dosis (`Cr n`), geben Cream also mit
50 % weiter. Beispiele: Gelbfalbe × Rotfalbe → 50 % Gelbfalbe / 50 % Rotfalbe;
Gelbfalbe × Gelbfalbe → 50 % Gelbfalbe, 25 % Rotfalbe, 25 % doppelte
Cream-Dosis. Bis Version 1.2.0 nahm der Rechner an, die beiden Farben könnten
auch `Cr Cr` sein, und zählte doppelt verdünnte Fohlen als Hell- bzw.
Gelbfalbe (Gelbfalbe × Rotfalbe ergab dort 75 % / 25 %, Audit N25).

## Installation

```bash
cp -r farbvererbung /pfad/zu/Hengstverzeichnis_Framework/plugins/farbvererbung
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren und die
Berechtigung `farbvererbung.calculate` der gewünschten Gruppe zuweisen.
