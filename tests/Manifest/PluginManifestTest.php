<?php
// tests/Manifest/PluginManifestTest.php

namespace Tests\Manifest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use FilesystemIterator;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Generische Strukturprüfung für JEDES Plugin unter plugins/ - läuft ohne
 * Datenbank oder laufende Framework-Instanz und muss bei einem neu
 * hinzugefügten Plugin-Verzeichnis nicht angepasst werden (dataProvider
 * scannt plugins/ zur Laufzeit). Prüft ausschließlich das, was
 * docs/plugin-development.md im Framework-Repo als verbindliche Konvention
 * beschreibt: Manifest-Pflichtfelder, Slug/Verzeichnis-Übereinstimmung,
 * PHP-Syntax und die Namensregel für die Plugin-Klasse. Tatsächliches
 * Hook-/Routen-/Berechtigungsverhalten eines einzelnen Plugins gegen eine
 * echte Framework-Instanz gehört in einen eigenen Test unter
 * tests/Functional/<Plugin>Test.php.
 */
class PluginManifestTest extends TestCase {

    private const PLUGINS_DIR = __DIR__ . '/../../plugins';
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    public function testAtLeastOnePluginExists(): void {
        $this->assertNotEmpty(
            self::discoverSlugs(),
            'Es sollte mindestens ein Plugin unter plugins/ vorhanden sein.'
        );
    }

    #[DataProvider('pluginSlugProvider')]
    public function testSlugMatchesDirectoryNameAndFormat(string $slug): void {
        $this->assertMatchesRegularExpression(
            self::SLUG_PATTERN,
            $slug,
            "Verzeichnisname 'plugins/{$slug}' entspricht nicht ^[a-z0-9][a-z0-9-]*$ (siehe docs/plugin-development.md im Framework-Repo)."
        );

        $manifest = $this->readManifest($slug);
        $this->assertSame(
            $slug,
            $manifest['slug'] ?? null,
            "plugin.json von '{$slug}': Feld 'slug' muss exakt dem Verzeichnisnamen entsprechen."
        );
    }

    #[DataProvider('pluginSlugProvider')]
    public function testManifestHasRequiredFields(string $slug): void {
        $manifest = $this->readManifest($slug);

        // core_supported_max ist seit Framework#197 Pflicht - ohne die
        // Obergrenze verweigert der Kern Installation und Laden.
        foreach (['slug', 'name', 'version', 'core_compatibility', 'core_supported_max'] as $field) {
            $this->assertArrayHasKey($field, $manifest, "plugin.json von '{$slug}' fehlt Pflichtfeld '{$field}'.");
            $this->assertNotSame(
                '',
                trim((string) $manifest[$field]),
                "Pflichtfeld '{$field}' in plugin.json von '{$slug}' darf nicht leer sein."
            );
        }
    }

    /**
     * Die Pflicht-Obergrenze (Framework#197) muss eine Major.Minor-Angabe
     * sein - exakt das Format, das PluginManager::validateManifest() und
     * die Release-Konsistenzprüfung (scripts/check-release-consistency.php)
     * erwarten.
     */
    #[DataProvider('pluginSlugProvider')]
    public function testCoreSupportedMaxHasValidFormat(string $slug): void {
        $manifest = $this->readManifest($slug);
        $value = $manifest['core_supported_max'] ?? null;

        $this->assertIsString($value, "core_supported_max in '{$slug}' muss ein String sein.");
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+$/',
            $value,
            "core_supported_max '{$value}' in '{$slug}' muss eine Major.Minor-Angabe wie \"0.4\" sein."
        );
    }

    #[DataProvider('pluginSlugProvider')]
    public function testCoreCompatibilityHasValidFormat(string $slug): void {
        $manifest = $this->readManifest($slug);
        $expression = (string) ($manifest['core_compatibility'] ?? '');

        $this->assertMatchesRegularExpression(
            '/^(>=|<=|>|<|=)?\s*\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/',
            $expression,
            "core_compatibility '{$expression}' in '{$slug}' hat kein gültiges Format " .
            "(optionaler Operator gefolgt von einer Versionsnummer, siehe docs/plugin-development.md)."
        );
    }

    #[DataProvider('pluginSlugProvider')]
    public function testEntryFileExists(string $slug): void {
        $manifest = $this->readManifest($slug);
        $entry = (string) ($manifest['entry'] ?? 'Plugin.php');

        $this->assertFileExists(
            self::PLUGINS_DIR . "/{$slug}/{$entry}",
            "Entry-Datei '{$entry}' für Plugin '{$slug}' fehlt (Feld 'entry' in plugin.json bzw. Default 'Plugin.php')."
        );
    }

    #[DataProvider('pluginSlugProvider')]
    public function testAllPhpFilesHaveValidSyntax(string $slug): void {
        $dir = self::PLUGINS_DIR . "/{$slug}";
        $checked = 0;

        foreach (self::phpFilesIn($dir) as $file) {
            $checked++;
            $output = [];
            $exitCode = 0;
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode, "PHP-Syntaxfehler in {$file}:\n" . implode("\n", $output));
        }

        $this->assertGreaterThan(0, $checked, "Keine .php-Dateien in Plugin '{$slug}' gefunden.");
    }

    #[DataProvider('pluginSlugProvider')]
    public function testEntryClassFollowsNamingConvention(string $slug): void {
        $fqcn = $this->loadPluginClass($slug);

        $this->assertTrue(
            class_exists($fqcn),
            "Erwarte Klasse '{$fqcn}' (Konvention: namespace Plugin\\<StudlySlug>; class Plugin, siehe docs/plugin-development.md)."
        );

        $reflection = new ReflectionClass($fqcn);
        $constructor = $reflection->getConstructor();
        $this->assertTrue(
            $constructor === null || $constructor->getNumberOfRequiredParameters() === 0,
            "'{$fqcn}' darf keine verpflichtenden Konstruktor-Parameter haben - PluginManager instanziiert ohne Argumente."
        );

        // Ein SPRACH-ADDON ist die eine begruendete Ausnahme (Framework#344).
        //
        // Es bringt eine Sprache mit und sonst nichts: keine Hooks, keine
        // Routen, keine Berechtigungen. Der Kern erkennt sein Verzeichnis
        // `lang/core/` von selbst - es taete also durchaus etwas Sichtbares,
        // nur eben nicht ueber eine dieser beiden Methoden. Ein leeres
        // register() nur zur Beruhigung dieses Tests waere schlimmer als die
        // Ausnahme: Es sagt "hier passiert etwas", wo nichts passiert.
        //
        // Die Ausnahme haengt nicht am Namen, sondern am Inhalt: Nur wer
        // wirklich eine Sprachdatei mitbringt, faellt darunter.
        $sprachdateien = glob(self::PLUGINS_DIR . "/{$slug}/lang/core/*.php") ?: [];
        if ($sprachdateien !== []) {
            $this->assertFalse(
                $reflection->hasMethod('register') || $reflection->hasMethod('routes'),
                "'{$fqcn}' ist ein Sprach-Addon (lang/core/) und sollte NICHTS weiter tun - "
                . 'weder Hooks noch Routen. Wer mehr will, baut ein zweites Addon.'
            );
            return;
        }

        $this->assertTrue(
            $reflection->hasMethod('register') || $reflection->hasMethod('routes'),
            "'{$fqcn}' implementiert weder register() noch routes() - mindestens eines sollte etwas Sichtbares tun."
        );
    }

    #[DataProvider('pluginSlugProvider')]
    public function testPermissionsDeclarationIsWellFormedIfPresent(string $slug): void {
        $fqcn = $this->loadPluginClass($slug);
        $instance = new $fqcn();

        if (!method_exists($instance, 'permissions')) {
            $this->markTestSkipped("Plugin '{$slug}' registriert keine eigenen Berechtigungen.");
        }

        foreach ($instance->permissions() as $i => $permission) {
            foreach (['module', 'action', 'label'] as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $permission,
                    "permissions()[{$i}] von '{$slug}' fehlt Feld '{$field}'."
                );
            }
        }
    }

    #[DataProvider('pluginSlugProvider')]
    public function testRoutesDeclarationIsWellFormedIfPresent(string $slug): void {
        $fqcn = $this->loadPluginClass($slug);
        $instance = new $fqcn();

        if (!method_exists($instance, 'routes')) {
            $this->markTestSkipped("Plugin '{$slug}' registriert keine eigenen Routen.");
        }

        foreach ($instance->routes() as $i => $route) {
            $this->assertArrayHasKey('method', $route, "routes()[{$i}] von '{$slug}' fehlt 'method'.");
            $this->assertContains(
                $route['method'],
                ['GET', 'POST'],
                "routes()[{$i}] von '{$slug}': 'method' muss GET oder POST sein."
            );

            $this->assertArrayHasKey('path', $route, "routes()[{$i}] von '{$slug}' fehlt 'path'.");
            $this->assertStringStartsNotWith(
                '/plugin/',
                $route['path'],
                "routes()[{$i}] von '{$slug}': 'path' ist relativ zum Plugin - PluginManager stellt " .
                "'/plugin/{$slug}/' selbst voran, kein eigenes Präfix angeben (siehe docs/plugin-development.md)."
            );

            $this->assertArrayHasKey('callback', $route, "routes()[{$i}] von '{$slug}' fehlt 'callback'.");
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pluginSlugProvider(): array {
        $cases = [];
        foreach (self::discoverSlugs() as $slug) {
            $cases[$slug] = [$slug];
        }
        return $cases;
    }

    /**
     * @return array<int, string>
     */
    private static function discoverSlugs(): array {
        if (!is_dir(self::PLUGINS_DIR)) {
            return [];
        }

        $slugs = [];
        foreach (scandir(self::PLUGINS_DIR) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir(self::PLUGINS_DIR . "/{$entry}")) {
                $slugs[] = $entry;
            }
        }
        sort($slugs);
        return $slugs;
    }

    /**
     * @return array<int, string>
     */
    private static function phpFilesIn(string $dir): array {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $slug): array {
        $path = self::PLUGINS_DIR . "/{$slug}/plugin.json";
        $this->assertFileExists($path, "plugin.json für '{$slug}' fehlt.");

        $json = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($json, "plugin.json für '{$slug}' ist kein gültiges JSON.");

        return $json;
    }

    /**
     * Lädt die Entry-Datei des Plugins und liefert den vollqualifizierten
     * Klassennamen gemäß Namenskonvention zurück (require_once, daher
     * gefahrlos mehrfach pro Testlauf aufrufbar).
     */
    private function loadPluginClass(string $slug): string {
        $manifest = $this->readManifest($slug);
        $entry = (string) ($manifest['entry'] ?? 'Plugin.php');
        $path = self::PLUGINS_DIR . "/{$slug}/{$entry}";
        $this->assertFileExists($path, "Entry-Datei '{$entry}' für Plugin '{$slug}' fehlt.");

        require_once $path;

        $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug)));
        return "Plugin\\{$studly}\\Plugin";
    }
}
