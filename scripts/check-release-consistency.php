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
if (!preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', (string)$tag, $m)) {
    fail("Tag '{$tagArg}' hat nicht die Form vX.Y.z (Versionierung folgt dem Framework, siehe docs/releasing.md).");
}
$line = $m[1] . '.' . $m[2];
$lineVersion = $line . '.0';

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
