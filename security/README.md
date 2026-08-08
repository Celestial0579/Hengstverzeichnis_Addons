# Sicherheits-Checks für die Addons

Zwei sich ergänzende Prüfungen vor jedem Release — eine statische über den
Plugin-Code, eine dynamische (Kali-DAST) über den ausgelieferten Build.

## 1. Statischer Plugin-Check — `plugin-security-scan.sh`

Schnell, ohne Infrastruktur. Durchsucht jeden Plugin-PHP-Code nach
hoch-konfidenten gefährlichen Mustern:

- Code-/Kommando-Ausführung (`eval`/`system`/`exec`/… als Funktion — **nicht**
  PDO-`->exec()`), Backtick-Shell
- dynamisches `include`/`require` mit Variable
- SQL mit interpolierter/konkatenierter Variable (statt gebundener Parameter)
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

Exit: `0` = keine blockierenden Funde, `2` = HIGH/CRIT gefunden.
Bewusste Ausnahmen: `baseline/plugin-findings.allow` (`<plugin>|<titel>`-Muster).

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
