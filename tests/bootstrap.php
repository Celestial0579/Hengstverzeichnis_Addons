<?php
// tests/bootstrap.php
//
// PHPUnit-Bootstrap für dieses Addon-Repo. Zwei Aufgaben:
//
// 1. Lädt den Composer-Autoloader. Da hengstverzeichnis/framework als
//    require-dev-Abhängigkeit über ein VCS-Repository eingebunden ist (siehe
//    composer.json), stellt vendor/autoload.php auch dessen App\-Namespace
//    bereit (App\Database, App\Plugin\HookManager, ...) sowie den vollen
//    Framework-Checkout unter vendor/hengstverzeichnis/framework - inklusive
//    dessen eigenem tests/Support/*, das hier für die Functional-Suite direkt
//    per require_once nachgeladen wird (Composer autoloadet die
//    autoload-dev-Sektion einer Abhängigkeit bewusst nicht automatisch mit).
//
// 2. Kopiert jedes Verzeichnis unter plugins/ nach
//    vendor/hengstverzeichnis/framework/plugins/, damit App\Plugin\PluginManager
//    (der dortige Kern, siehe src/Plugin/PluginManager.php::pluginsDir()) sie
//    beim Aktivieren über die Functional-Tests tatsächlich findet - genau der
//    Schritt, den docs/plugin-development.md im Framework-Repo manuell per
//    "cp -r" beschreibt, hier automatisiert bei jedem Testlauf.

require __DIR__ . '/../vendor/autoload.php';

const FRAMEWORK_VENDOR_DIR = __DIR__ . '/../vendor/hengstverzeichnis/framework';
const ADDON_PLUGINS_DIR = __DIR__ . '/../plugins';

if (is_dir(FRAMEWORK_VENDOR_DIR)) {
    foreach (['HttpResponse', 'HttpClient', 'PhpBuiltInServer'] as $class) {
        $path = FRAMEWORK_VENDOR_DIR . "/tests/Support/{$class}.php";
        if (is_file($path)) {
            require_once $path;
        }
    }
    $functionalTestCase = FRAMEWORK_VENDOR_DIR . '/tests/Functional/FunctionalTestCase.php';
    if (is_file($functionalTestCase)) {
        require_once $functionalTestCase;
    }

    syncPluginsIntoFramework(ADDON_PLUGINS_DIR, FRAMEWORK_VENDOR_DIR . '/plugins');
}

// Die Functional-Suite startet die Framework-Instanz als eigenen `php -S`-
// Subprozess (siehe PhpBuiltInServer::ensureStarted()), der die aktuelle
// Prozessumgebung erbt. App\Security\Crypto::getKey() dort wirft bewusst eine
// Exception, wenn APP_KEY fehlt (Fail-Closed) - lokal (außerhalb von CI, wo
// die Workflow-Datei APP_KEY bereits setzt) daher ein fester Test-Schlüssel
// als Fallback, bevor der Subprozess gestartet wird.
if (getenv('APP_KEY') === false) {
    putenv('APP_KEY=phpunit-addon-test-key-not-for-production-use');
}
if (getenv('SITE_NAME') === false) {
    putenv('SITE_NAME=Addon-Testverband');
}
if (getenv('ADMIN_USERNAME') === false) {
    putenv('ADMIN_USERNAME=addontestadmin');
}
if (getenv('ADMIN_EMAIL') === false) {
    putenv('ADMIN_EMAIL=addon-functional-test@example.com');
}
if (getenv('ADMIN_PASSWORD') === false) {
    putenv('ADMIN_PASSWORD=FunctionalTest123!');
}

/**
 * Spiegelt jedes Plugin-Verzeichnis dieses Repos in das (gitignorete)
 * plugins/-Verzeichnis des vendorierten Framework-Checkouts. Idempotent und
 * überschreibt bestehende Dateien, damit lokale Wiederholungsläufe nach einer
 * Code-Änderung am Plugin den neuen Stand sehen.
 */
function syncPluginsIntoFramework(string $sourceRoot, string $destRoot): void {
    if (!is_dir($sourceRoot)) {
        return;
    }
    if (!is_dir($destRoot)) {
        mkdir($destRoot, 0777, true);
    }

    foreach (scandir($sourceRoot) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $sourceDir = "{$sourceRoot}/{$entry}";
        if (!is_dir($sourceDir)) {
            continue;
        }
        copyDirectoryRecursive($sourceDir, "{$destRoot}/{$entry}");
    }
}

function copyDirectoryRecursive(string $source, string $dest): void {
    if (!is_dir($dest)) {
        mkdir($dest, 0777, true);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0777, true);
            }
        } else {
            copy($item->getPathname(), $target);
        }
    }
}
