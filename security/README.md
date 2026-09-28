# Sicherheits-Checks für die Addons

Zwei sich ergänzende Prüfungen vor jedem Release — eine statische über den
Plugin-Code, eine dynamische (Kali-DAST) über den ausgelieferten Build.

## 1. Statischer Plugin-Check — `plugin-security-scan.sh`

Schnell, ohne Infrastruktur. Durchsucht jeden Plugin-PHP-Code nach
hoch-konfidenten gefährlichen Mustern:

- Code-/Kommando-Ausführung (`eval`/`system`/`exec`/… als Funktion — **nicht**
  PDO-`->exec()`), Backtick-Shell
- dynamisches `include`/`require` mit Variable
- SQL, das im Aufruf aus Variablen zusammengesetzt wird (statt gebundener
  Parameter) — siehe „SQL-Prüfung“ unten
- Datei-Operationen mit Nutzereingabe (LFI/Path-Traversal)
- Ausgabe von Superglobals ohne Encoding (XSS)
- `unserialize()` (Eingabequelle prüfen)
- zusätzlich: Zählung direkter Superglobal-Nutzung je Plugin (Review-Fläche)

Kommentare werden vorher entfernt (`lib/strip-comments.php` über die PHP-CLI,
Zeilennummern bleiben erhalten) — sonst würde z. B. `` `$var` `` in einem
Doc-Kommentar fälschlich als Backtick-Shell gemeldet.

```bash
security/plugin-security-scan.sh                 # alle plugins/
security/plugin-security-scan.sh /pfad/zu/plugins
```

Exit: `0` = keine blockierenden Funde, `2` = HIGH/CRIT gefunden, `1` =
Aufruf-/Umgebungsfehler (auch: der SQL-Prüfer selbst ist gescheitert).

### SQL-Prüfung (Tokenizer)

Die SQL-Regeln laufen nicht zeilenweise per grep, sondern über den
PHP-Tokenizer (`lib/sql-concat-check.php`). Geprüft wird das **erste
Argument** jedes `->query(`, `->prepare(` und `->exec(` (auch `?->` und `::`)
— über mehrere Zeilen hinweg und in beiden Anführungszeichenarten. Drei
Titel, alle HIGH:

| Titel | Beispiel |
| --- | --- |
| SQL mit Variable im Query-String (Interpolation) | `"… WHERE id = $id"`, Heredoc mit `{$x}` |
| SQL mit konkatenierter Variable | `'… WHERE id = ' . $id` |
| SQL-String aus Variable zusammengesetzt (Funktionsaufruf/Ausdruck) | `sprintf('… %s', $x)`, `implode(' UNION ALL ', $teile)`, Ternär |

Ausgenommen sind nur `->quote(…)`/`->quoteIdentifier(…)`-Ketten (Empfänger
wie `$db`, `$this->db`, `Database::getInstance()` samt Argumenten) und eine
Variable direkt nach `(int)`/`(float)`. Ein bloßes `->query($sql)` oder
`->query($this->sql)` meldet der Check nicht.

**Grenzen (bewusst):** SQL, das vorab in einer Variable gebaut und dann als
`->query($sql)` übergeben wird, und Aufbau per `.=` sieht auch der Tokenizer
nicht — dafür braucht es Datenfluss, das bleibt Aufgabe von Semgrep
(`.github/workflows/semgrep.yml`).

**Rückfall ohne PHP-CLI** (oder mit `PLUGIN_SCAN_NO_PHP=1`): zeilenweise, mit
dem Hinweis „SQL-Pruefung: Rueckfall zeilenweise …“. Die früheren beiden
Regeln bleiben blockierend, die erweiterte Regel für einfache
Anführungszeichen und die Backtick-Regel melden nur MED — ohne Tokenizer gibt
es keinen Fingerabdruck für die Einzelfreigaben und keine Trennung von
SQL-Bezeichnern und Shell-Backticks. Die CI hat immer PHP und prüft
vollständig.

### Bewusste Ausnahmen — `baseline/plugin-findings.allow`

```text
<plugin>|<titel>                     # ganze Regel für das Plugin
<plugin>|<titel>|<Code-Ausschnitt>   # genau eine Stelle
```

Plugin und Titel werden **exakt** verglichen (voller Titel). Der
Code-Ausschnitt muss als fester Teilstring im Fingerabdruck des Funds stehen
(erstes Argument, Kommentare entfernt, Whitespace zu einem Leerzeichen
zusammengefasst, ungekürzt) — zeilenunabhängig, der Eintrag überlebt Umbauten
oberhalb der Stelle. Über jedem Eintrag steht als Kommentar, warum die Stelle
sicher ist. Mit `PLUGIN_SCAN_ALLOW=<datei>` lässt sich eine andere Allowlist
angeben (für Tests).

`tests/Unit/PluginSecurityScanTest.php` prüft die Muster, die Ausnahmen, die
Allowlist-Granularität und dass jeder Eintrag der echten Allowlist greift.

Kein Ersatz für Semgrep, sondern eine gezielte, fehlalarmarme Ergänzung.

## 2. Kali-DAST über den addon-haltigen Build — `run-addon-dast.sh`

Holt das Framework, kopiert alle `plugins/` dieses Repos hinein und lässt den
**DAST-Gate des Frameworks** (`security/run-security-scan.sh`) gegen den so
entstehenden Build laufen: eine **ephemere, isolierte** Instanz wird gebaut,
mit Kali-Werkzeugen gescannt und wieder abgeräumt. Deckt auf, wenn die Addons
die Auslieferung verschlechtern — eine Plugin-Datei exponieren, den
Docroot-Schutz aushebeln, Header verlieren.

```bash
# gegen einen lokalen Framework-Checkout (nutzt dessen committeten Stand):
FRAMEWORK_DIR=/pfad/zu/Hengstverzeichnis_Framework security/run-addon-dast.sh

# gegen einen geklonten Framework-Stand (Default main):
security/run-addon-dast.sh --only exposed-paths,content-discovery
```

Alle Argumente werden an den Framework-Scan durchgereicht (`--only`, `--strict`,
`--runner`, …). Details zu Werkzeug-Modi (kali/local/docker) und Checks:
`security/README.md` **im Framework-Repo**.

> **Grenze (bewusst).** Die Plugins werden **nicht aktiviert**: Der Kern lädt
> nur über `/admin/plugins` freigegebene Plugins, und der Admin-Login erzwingt
> 2FA — in einem automatisierten Blackbox-Scan nicht sinnvoll nachstellbar.
> Addon-**Routen** prüfen daher die PHPUnit-**Functional-Tests** dieses Repos
> (sie aktivieren jedes Plugin in einer laufenden Instanz); der DAST deckt die
> **Deployment**-Sicht ab (Dateiexposition, Server-Härtung mit vorhandenen
> Addons). Voraussetzung: ein Framework-Stand **mit** `security/`-Harness.

## Vor einem Release

```bash
security/plugin-security-scan.sh          # statisch — immer
FRAMEWORK_DIR=… security/run-addon-dast.sh   # DAST — auf dem Devhost (kali)
```

In CI läuft der statische Check bei jedem PR (`.github/workflows/security-scan.yml`);
der DAST wöchentlich/manuell (er braucht Docker und einen Framework-Stand mit
Harness).
