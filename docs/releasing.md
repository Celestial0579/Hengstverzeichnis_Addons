# Releases des Addons-Repos

Dieses Repo bekommt Release-Tags und GitHub-Releases (#65). **Die
Versionierung folgt dem Framework:** Ein Release `vX.Y.z` ist der geprüfte
Gesamtstand **aller** Addons, der zur Framework-Linie `X.Y` passt. Die
Patch-Stelle `z` läuft unabhängig - für Addon-Fixes zwischen zwei
Framework-Releases (`v0.4.0`, `v0.4.1`, ... alle kompatibel zu Framework
0.4).

Die 15 einzelnen Addons behalten daneben ihre eigene `version` in
`plugin.json` (läuft pro Addon weiter wie bisher); der Release-Tag
versioniert den **Gesamtstand des Repos**.

## Warum Releases statt Branch-HEAD

Der Addon-Store und das Addon-Autoupdate des Frameworks lesen für das
offizielle Repo den **besten Release-Tag zur laufenden Kern-Linie** statt
des `main`-HEAD (Framework#197). Ein halb fertiger Zwischenstand auf `main`
kann damit nie auf einer Produktivinstanz landen - und die Frage „welcher
Addons-Stand passt zu Framework X.Y?" hat genau eine benannte, getestete
Antwort.

**Solange zu einer Kern-Linie kein Release OHNE Vorabsuffix existiert, gibt es
für sie keinen Addon-Katalog.** Der Kern überspringt Entwürfe und
Vorabversionen bedingungslos (`GithubAddonRepository::selectBestReleaseTagForCoreLine()`)
und verlangt ein Tag der Form `vX.Y.Z`. Findet er keines, verweigern die
automatischen Addon-Updates — sie fallen ausdrücklich **nicht** auf den
Branch-Stand zurück, denn ein veränderlicher HEAD ist keine geprüfte Fassung
(Framework#212). Nur der Store-Install als bewusste Admin-Aktion darf das.

Praktische Folge: Zu jeder Kern-Freigabe ohne Suffix gehört ein Addons-Release
ohne Suffix. Fehlt es, laufen die installierten Addons zwar weiter, werden aber
nie nachgezogen, und im Update-Protokoll steht je Addon eine Fehlerzeile.

## Ablauf eines Releases

1. **CHANGELOG.md** pflegen: Abschnitt `## [X.Y.z] – <Datum>` aus
   `## [Unreleased]` herausziehen (Einträge nach Addon gruppiert).
2. Sicherstellen, dass alle `plugins/*/plugin.json` zur Ziel-Linie passen:

   ```bash
   php scripts/check-release-consistency.php vX.Y.z
   ```

   Geprüft werden Untergrenze (`core_compatibility`, Ein-Operator-Format)
   **und** Pflicht-Obergrenze (`core_supported_max`, `"Major.Minor"`) jedes
   Addons - dieselbe Prüfung bricht auch die Release-Pipeline ab.
3. Tag auf den freigegebenen `main`-Stand setzen und pushen:

   ```bash
   git tag vX.Y.z
   git push origin vX.Y.z
   ```

4. Die Release-Pipeline (`.github/workflows/release.yml`) läuft: Testsuite
   als Gate → Konsistenzprüfung → GitHub-Release. **Titel/Beschreibung des
   Releases bleiben manuell kuratiert** (CHANGELOG als Grundlage), wie im
   Framework-Repo.

## Zusammenspiel mit den Manifest-Grenzen

Jedes Addon muss ausweisen, mit welchen Kern-Versionen es läuft:

- `core_compatibility` - Untergrenze, Ein-Operator-Ausdruck
  (z. B. `">=0.4.0"`). **Bereichs-Syntax ist ungültig** (der
  Framework-Parser lehnt sie fail-closed ab).
- `core_supported_max` - **Pflicht**-Obergrenze als `"Major.Minor"`
  (z. B. `"0.4"`): höchste bekannte unterstützte Kern-Linie. Fehlt die
  Angabe, verweigert das Framework Installation und Laden.

Beim Kern-Update zieht das Framework die offiziellen Addons automatisch auf
den Release der Ziel-Linie mit; ein Addon, dessen Obergrenze die Ziel-Linie
ausschließt, wird vor dem Update angekündigt statt still deaktiviert
(Framework#197).
