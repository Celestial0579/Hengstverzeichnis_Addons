<?php
// scripts/check-release-consistency.php
//
// Konsistenz-Gate der Release-Pipeline (#65): Ein Addons-Release vX.Y.z
// behauptet, der geprüfte Gesamtstand zur Framework-Linie X.Y zu sein -
// dieses Skript stellt sicher, dass KEIN enthaltenes Addon dieser Behauptung
// widerspricht. Der Release bricht ab (Exit != 0), wenn in irgendeinem
// plugins/*/plugin.json
//
//   - core_supported_max fehlt oder kein "Major.Minor"-Format hat
//     (Pflichtfeld, das Framework verweigert sonst Installation und Laden),
//   - die Obergrenze core_supported_max die Ziel-Linie X.Y ausschließt, oder
//   - die Untergrenze core_compatibility die Version X.Y.0 nicht erfüllt
//     (Ein-Operator-Format wie im Framework-Parser, Bereichs-Syntax ist
//     ungültig).
//
// Aufruf: php scripts/check-release-consistency.php <tag>
//   <tag> z. B. "v0.4.0" oder "refs/tags/v0.4.0" (die Pipeline übergibt
//   github.ref); ein führendes "v" ist optional.

declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, "FEHLER: {$message}\n");
    exit(1);
}

$tagArg = $argv[1] ?? '';
$tag = preg_replace('~^refs/tags/~', '', trim($tagArg));

// Vorabversionen sind zugelassen (v0.8.0-beta.1).
//
// Bis v0.8 verlangte dieses Gate genau vX.Y.z - und das war kein bewusster
// Ausschluss, sondern schlicht der Fall, den es noch nie gegeben hatte. Der
// Kern kennt den Beta-Kanal seit Langem (UpdateService::CHANNEL_BETA); nur
// war noch nie eine Vorabversion durch die Release-Kette gegangen, und beim
// ersten Versuch fiel sie hier durch.
//
// Der Zusatz wird für die Prüfung ABGESCHNITTEN, nicht mitverglichen: Eine
// Vorabversion gehört zur Linie X.Y wie die spätere endgültige Fassung, und
// ein Addon soll für "0.8" nicht zusätzlich "-beta.1" behaupten müssen. Nach
// SemVer ist 0.8.0-beta.1 zwar KLEINER als 0.8.0 - für die Frage "welche
// Kern-Linie ist das?" ist dieser Unterschied ohne Belang, und die
// Untergrenze eines Addons (>=0.8.0) würde sonst gegen die eigene
// Vorabversion verlieren und jeden Beta-Release blockieren.
if (!preg_match('/^v?(\d+)\.(\d+)\.(\d+)(?:-[0-9A-Za-z.-]+)?$/', (string)$tag, $m)) {
    fail(
        "Tag '{$tagArg}' hat nicht die Form vX.Y.z oder vX.Y.z-vorab "
        . '(Versionierung folgt dem Framework, siehe docs/releasing.md).'
    );
}
$line = $m[1] . '.' . $m[2];

// Geprüft wird gegen die ECHTE Tag-Version, nicht gegen X.Y.0.
//
// Bis dahin leitete das Gate stur die Linien-Untergrenze ab: Ein Release
// v0.5.1 wurde gegen "0.5.0" geprüft. Damit fiel jedes Addon durch, das eine
// Funktion des Kerns braucht, die erst in einem Patch-Release dazukam - es
// müsste `>=0.5.0` behaupten, obwohl es auf 0.5.0 nachweislich nicht läuft,
// oder ganz draußen bleiben. Aufgefallen am Embed-Widget (#89), das
// layout_embed.php und FrameGuard aus Kern-#260 voraussetzt und deshalb
// ehrlich `>=0.5.1` deklariert.
//
// Die Obergrenze core_supported_max bleibt bewusst auf Major.Minor: Sie sagt
// "bis zu dieser Linie geprüft" und soll nicht bei jedem Patch-Release
// nachgezogen werden müssen.
$lineVersion = $m[1] . '.' . $m[2] . '.' . $m[3];

// Ein-Operator-Vergleich, exakt wie App\Plugin\PluginManager::constraintSatisfied()
// im Framework - Bereichs-Syntax ist dort fail-closed ungültig.
function constraintSatisfied(string $constraint, string $version): bool {
    $constraint = trim($constraint);
    if ($constraint === '' || !preg_match('/^(>=|<=|>|<|=)?\s*([0-9][0-9A-Za-z.\-]*)$/', $constraint, $m)) {
        return false;
    }
    $operator = $m[1] !== '' ? $m[1] : '=';
    return version_compare($version, $m[2], $operator);
}

$pluginDirs = glob(__DIR__ . '/../plugins/*', GLOB_ONLYDIR) ?: [];
if ($pluginDirs === []) {
    fail('Keine Addons unter plugins/ gefunden - falscher Aufrufort?');
}

$errors = [];
foreach ($pluginDirs as $dir) {
    $slug = basename($dir);
    $manifestFile = $dir . '/plugin.json';
    $manifest = json_decode((string)@file_get_contents($manifestFile), true);
    if (!is_array($manifest)) {
        $errors[] = "{$slug}: plugin.json fehlt oder ist kein gültiges JSON.";
        continue;
    }

    $constraint = (string)($manifest['core_compatibility'] ?? '');
    if (!preg_match('/^(>=|<=|>|<|=)?\s*\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $constraint)) {
        $errors[] = "{$slug}: core_compatibility '{$constraint}' ist kein Ein-Operator-Ausdruck (Bereichs-Syntax ist ungültig).";
        continue;
    }
    if (!constraintSatisfied($constraint, $lineVersion)) {
        $errors[] = "{$slug}: core_compatibility '{$constraint}' schließt die Ziel-Version {$lineVersion} aus.";
    }

    $max = $manifest['core_supported_max'] ?? null;
    if (!is_string($max) || !preg_match('/^(\d+)\.(\d+)$/', $max, $mm)) {
        $errors[] = "{$slug}: Pflichtfeld core_supported_max fehlt oder ist keine Major.Minor-Angabe (z. B. \"0.4\").";
        continue;
    }
    $maxMajor = (int)$mm[1];
    $maxMinor = (int)$mm[2];
    $lineMajor = (int)$m[1];
    $lineMinor = (int)$m[2];
    if ($lineMajor > $maxMajor || ($lineMajor === $maxMajor && $lineMinor > $maxMinor)) {
        $errors[] = "{$slug}: core_supported_max '{$max}' schließt die Ziel-Linie {$line} aus.";
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Release {$tag} abgebrochen - der Gesamtstand widerspricht der behaupteten Framework-Linie {$line}:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }
    exit(1);
}

echo 'OK: ' . count($pluginDirs) . " Addons passen zur Framework-Linie {$line} (Tag {$tag}).\n";
