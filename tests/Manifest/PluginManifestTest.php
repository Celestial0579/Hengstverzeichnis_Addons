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
     * Audit M27/M30: Jede Tabelle, die ein Addon anlegt, gehört in sein
     * Datenregister (`owns.tables`, Framework#338). Fehlt sie dort, meldet
     * die Rückfrage beim Deinstallieren nichts, „Daten löschen“ lässt sie
     * stehen, und nach erneuter Aktivierung ist der alte Bestand wieder da.
     */
    #[DataProvider('pluginSlugProvider')]
    public function testTabellenAusInstallStehenImDatenregister(string $slug): void {
        $register = $this->datenregister($slug);
        $fehlend = array_values(array_diff(array_keys(self::tabellenAusInstall($slug)), $register['tables']));

        $this->assertSame(
            [],
            $fehlend,
            "plugin.json von '{$slug}': Diese Tabellen legt das Addon an, sie fehlen aber in owns.tables - "
            . '„Daten löschen“ ließe sie stehen: ' . implode(', ', $fehlend)
        );
    }

    /**
     * Audit M27/N27: Eine Ablage unter storage/plugin_* gehört in
     * `owns.directories` - sonst löscht „Daten löschen“ sie nicht, und die
     * Sicherung des Kerns kann sie nicht finden.
     */
    #[DataProvider('pluginSlugProvider')]
    public function testAblageverzeichnisseStehenImDatenregister(string $slug): void {
        $register = $this->datenregister($slug);

        $ablagen = [];
        foreach (self::quelldateien($slug) as $datei) {
            if (preg_match_all('#/storage/(plugin_[a-z0-9_]+)#', (string) file_get_contents($datei), $treffer)) {
                foreach ($treffer[1] as $name) {
                    $ablagen['storage/' . $name] = true;
                }
            }
        }
        $fehlend = array_values(array_diff(array_keys($ablagen), $register['directories']));

        $this->assertSame(
            [],
            $fehlend,
            "plugin.json von '{$slug}': Diese Ablagen nutzt das Addon, sie fehlen aber in owns.directories: "
            . implode(', ', $fehlend)
        );
    }

    /**
     * Audit M30: Der Kern löscht die Tabellen des Registers der Reihe nach
     * per DROP TABLE, ohne FOREIGN_KEY_CHECKS=0. Steht die Elterntabelle vor
     * einer Kindtabelle mit Fremdschlüssel auf sie, scheitert ihr DROP - sie
     * bliebe stehen.
     */
    #[DataProvider('pluginSlugProvider')]
    public function testKindtabellenStehenVorIhrerElterntabelle(string $slug): void {
        $reihenfolge = array_flip($this->datenregister($slug)['tables']);

        foreach (self::tabellenAusInstall($slug) as $kind => $eltern) {
            foreach ($eltern as $elter) {
                if ($elter === $kind || !isset($reihenfolge[$elter], $reihenfolge[$kind])) {
                    continue;
                }
                $this->assertLessThan(
                    $reihenfolge[$elter],
                    $reihenfolge[$kind],
                    "plugin.json von '{$slug}': {$kind} verweist per Fremdschlüssel auf {$elter} und muss "
                    . 'in owns.tables VOR ihr stehen, sonst scheitert das DROP der Elterntabelle.'
                );
            }
        }
        $this->addToAssertionCount(1);
    }

    /**
     * Dieselben Grenzen, die PluginDataRegistry::fuer() im Kern zieht - hier
     * schon beim Schreiben des Manifests statt erst als „abgelehnt“ auf der
     * Rückfrageseite eines Betreibers. Verzeichnisse müssen NICHT unter
     * storage/plugin_ liegen (datenmigration hält var/datenmigration).
     */
    #[DataProvider('pluginSlugProvider')]
    public function testDatenregisterIstFormalGueltig(string $slug): void {
        $manifest = $this->readManifest($slug);
        if (!array_key_exists('owns', $manifest)) {
            $this->addToAssertionCount(1);
            return;
        }

        $owns = $manifest['owns'];
        $this->assertTrue(
            is_array($owns) && ($owns === [] || !array_is_list($owns)),
            "plugin.json von '{$slug}': owns muss ein Objekt sein."
        );

        foreach (['tables', 'directories', 'settings'] as $schluessel) {
            if (!array_key_exists($schluessel, $owns)) {
                continue;
            }
            $this->assertIsArray($owns[$schluessel], "plugin.json von '{$slug}': owns.{$schluessel} muss eine Liste sein.");
            foreach ($owns[$schluessel] as $eintrag) {
                $this->assertIsString($eintrag, "plugin.json von '{$slug}': owns.{$schluessel} enthält einen Nicht-String.");
            }
        }

        foreach (['tables', 'settings'] as $schluessel) {
            foreach ($owns[$schluessel] ?? [] as $name) {
                $this->assertMatchesRegularExpression(
                    '/^plugin_[a-z0-9_]+$/',
                    $name,
                    "plugin.json von '{$slug}': owns.{$schluessel} '{$name}' muss mit plugin_ beginnen."
                );
            }
        }

        foreach ($owns['directories'] ?? [] as $verzeichnis) {
            $this->assertMatchesRegularExpression(
                '#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#',
                $verzeichnis,
                "plugin.json von '{$slug}': owns.directories '{$verzeichnis}' muss ein relativer Pfad ohne / am Rand sein."
            );
            $teile = explode('/', $verzeichnis);
            $this->assertNotContains('..', $teile, "plugin.json von '{$slug}': '{$verzeichnis}' enthält '..'.");
            $this->assertNotContains('.', $teile, "plugin.json von '{$slug}': '{$verzeichnis}' enthält '.'.");
            $this->assertNotSame('storage', $verzeichnis, "plugin.json von '{$slug}': storage selbst gehört keinem Addon.");
            foreach (self::KERN_TABU as $tabu) {
                $this->assertFalse(
                    $verzeichnis === $tabu
                        || str_starts_with($verzeichnis . '/', $tabu . '/')
                        || str_starts_with($tabu . '/', $verzeichnis . '/'),
                    "plugin.json von '{$slug}': '{$verzeichnis}' ist ein geschützter Ort des Kerns ({$tabu}) oder berührt ihn."
                );
            }
        }
    }

    /**
     * Ein Addon ohne Tabellen und Ablage braucht kein Register - ein
     * vorhandenes Register ohne erkennbare Tabellen ist dagegen erlaubt
     * (Einstellungen, var/…). Gegenprobe zur Erkennung selbst: Die fünf
     * Addons aus Audit M27/M30 und die Konstanten-Formen der übrigen müssen
     * gefunden werden, sonst prüften die Tests oben ins Leere.
     */
    public function testTabellenerkennungFindetAlleBekanntenFormen(): void {
        $erwartet = [
            'gesundheitstests' => ['plugin_gesundheitstests'],
            'verkaufsboerse' => ['plugin_verkaufsboerse_listings'],
            'zuchtschau-ergebnisse' => ['plugin_zuchtschau_ergebnisse', 'plugin_zuchtschau_teilwertungen'],
            'titel-praemierungen' => ['plugin_titel_praemierungen'],
            // self::KONSTANTE
            'statistik-dashboard' => ['plugin_statistik_dashboard_views', 'plugin_statistik_dashboard_meta'],
            // self::KONSTANTE in einer Nebendatei
            'beispiel-erweiterungspunkte' => ['plugin_beispiel_ereignisse', 'plugin_beispiel_notizen'],
            // Klasse::KONSTANTE
            'mitglieder-konten' => ['plugin_mitglieder_konten_zuordnung'],
            // zwei Klassen mit gleichnamiger Konstante in einer Datei
            'mitgliedsstatus' => ['plugin_mitgliedsstatus_kontakt', 'plugin_mitgliedsstatus_civicrm'],
        ];
        foreach ($erwartet as $slug => $tabellen) {
            $gefunden = array_keys(self::tabellenAusInstall($slug));
            sort($gefunden);
            sort($tabellen);
            $this->assertSame($tabellen, $gefunden, "Tabellenerkennung für '{$slug}'");
        }

        $this->assertSame(
            ['plugin_zuchtschau_ergebnisse'],
            self::tabellenAusInstall('zuchtschau-ergebnisse')['plugin_zuchtschau_teilwertungen'],
            'Der Fremdschlüssel der Teilwertungen auf die Ergebnisse muss erkannt werden.'
        );
    }

    /**
     * Kern-Orte, die kein Addon beanspruchen darf - Spiegel von
     * PluginDataRegistry::TABU im Framework.
     */
    private const KERN_TABU = ['public/uploads', 'plugins', 'config', 'storage/logs', 'storage/horses', '.git'];

    /**
     * @return array{tables: string[], directories: string[]}
     */
    private function datenregister(string $slug): array {
        $owns = $this->readManifest($slug)['owns'] ?? [];
        $owns = is_array($owns) ? $owns : [];
        return [
            'tables' => array_values(array_filter((array) ($owns['tables'] ?? []), 'is_string')),
            'directories' => array_values(array_filter((array) ($owns['directories'] ?? []), 'is_string')),
        ];
    }

    /**
     * Alle Tabellen, die das Addon per CREATE TABLE anlegt, samt den
     * plugin_-Tabellen, auf die ihr Statement per REFERENCES verweist.
     *
     * Gelesen wird der Quelltext aller *.php des Addons (ohne tests/), nicht
     * geladen. Zwei Schreibweisen kommen vor:
     *
     * - das Literal: 'CREATE TABLE IF NOT EXISTS `plugin_x` (…'
     * - die Konstante: 'CREATE TABLE IF NOT EXISTS `' . self::TABELLE . '` (…'
     *   bzw. Klasse::TABELLE. Aufgelöst wird KLASSENBEZOGEN: self/static
     *   meint die Klasse, in der das Statement steht, ein Klassenname die
     *   gleichnamige Klasse im Addon. mitgliedsstatus hat zwei Klassen mit
     *   derselben Konstante TABELLE in einer Datei - ein Abgleich nur über den
     *   Konstantennamen läge dort falsch.
     *
     * Ein Verweis, der sich nicht auflösen lässt, lässt den Test scheitern:
     * Still übergangen prüfte er ins Leere.
     *
     * @return array<string, string[]> Tabelle => referenzierte plugin_-Tabellen
     */
    private static function tabellenAusInstall(string $slug): array {
        $dateien = [];
        $konstanten = [];
        foreach (self::quelldateien($slug) as $datei) {
            $tokens = token_get_all((string) file_get_contents($datei));
            $dateien[$datei] = $tokens;
            foreach (self::klassenKonstanten($tokens) as $klasse => $werte) {
                $konstanten[$klasse] = ($konstanten[$klasse] ?? []) + $werte;
            }
        }

        $tabellen = [];
        foreach ($dateien as $datei => $tokens) {
            $klasse = null;
            $klassenTiefe = null;
            $tiefe = 0;
            $anzahl = count($tokens);
            for ($i = 0; $i < $anzahl; $i++) {
                $token = $tokens[$i];
                if (!is_array($token)) {
                    if ($token === '{') {
                        $tiefe++;
                    } elseif ($token === '}') {
                        $tiefe--;
                        if ($klassenTiefe !== null && $tiefe === $klassenTiefe) {
                            $klasse = null;
                            $klassenTiefe = null;
                        }
                    }
                    continue;
                }
                if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $tiefe++;
                    continue;
                }
                if (in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true)) {
                    $name = self::naechstesToken($tokens, $i);
                    if ($name !== null && is_array($tokens[$name]) && $tokens[$name][0] === T_STRING) {
                        $klasse = $tokens[$name][1];
                        $klassenTiefe = $tiefe;
                    }
                    continue;
                }
                if (!in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    || !preg_match('/CREATE\s+TABLE\s/i', $token[1])) {
                    continue;
                }

                // Das Statement: dieser String und alles bis zum Semikolon,
                // aufgelöste Konstanten eingesetzt.
                $statement = '';
                for ($j = $i; $j < $anzahl && $tokens[$j] !== ';'; $j++) {
                    $teil = $tokens[$j];
                    if (is_array($teil) && in_array($teil[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                        $statement .= $teil[1];
                        continue;
                    }
                    if (is_array($teil) && $teil[0] === T_DOUBLE_COLON) {
                        $links = self::vorigesToken($tokens, $j);
                        $rechts = self::naechstesToken($tokens, $j);
                        if ($links === null || $rechts === null
                            || !is_array($tokens[$links])
                            || !in_array($tokens[$links][0], [T_STRING, T_STATIC, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                            || !is_array($tokens[$rechts]) || $tokens[$rechts][0] !== T_STRING) {
                            continue;
                        }
                        $verweis = $tokens[$links][1] . '::' . $tokens[$rechts][1];
                        $zielKlasse = in_array(strtolower($tokens[$links][1]), ['self', 'static'], true)
                            ? $klasse
                            : substr((string) strrchr('\\' . $tokens[$links][1], '\\'), 1);
                        $wert = $zielKlasse !== null ? ($konstanten[$zielKlasse][$tokens[$rechts][1]] ?? null) : null;
                        // Nicht auflösbar: markiert statt übergangen, siehe unten.
                        $statement .= '`' . ($wert ?? '?' . $verweis) . '`';
                    }
                }

                // Der Tabellenname selbst ist ein Verweis, der sich nicht
                // auflösen ließ - dann ließe sich nichts prüfen.
                if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`\'\s*`\?([^`]+)`/i', $statement, $offen)) {
                    throw new \RuntimeException(
                        "{$datei}: Tabellenname {$offen[1]} im CREATE TABLE lässt sich nicht auflösen "
                        . '(erwartet: Konstante mit String-Literal in dieser oder einer gleichnamigen Klasse des Addons).'
                    );
                }

                if (!preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`\'"]*\s*`?(plugin_[a-z0-9_]+)/i', $statement, $erstellt)) {
                    continue;
                }
                preg_match_all('/REFERENCES\s+`?(plugin_[a-z0-9_]+)/i', $statement, $verweise);
                foreach ($erstellt[1] as $name) {
                    $tabellen[$name] = array_values(array_unique(array_merge($tabellen[$name] ?? [], $verweise[1])));
                }
            }
        }

        return $tabellen;
    }

    /**
     * Konstanten mit einem String-Literal als Wert, je Klasse.
     *
     * @param array<int, mixed> $tokens
     * @return array<string, array<string, string>>
     */
    private static function klassenKonstanten(array $tokens): array {
        $ergebnis = [];
        $klasse = null;
        $klassenTiefe = null;
        $tiefe = 0;
        $anzahl = count($tokens);
        for ($i = 0; $i < $anzahl; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                if ($token === '{') {
                    $tiefe++;
                } elseif ($token === '}') {
                    $tiefe--;
                    if ($klassenTiefe !== null && $tiefe === $klassenTiefe) {
                        $klasse = null;
                        $klassenTiefe = null;
                    }
                }
                continue;
            }
            if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $tiefe++;
                continue;
            }
            if (in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true)) {
                $name = self::naechstesToken($tokens, $i);
                if ($name !== null && is_array($tokens[$name]) && $tokens[$name][0] === T_STRING) {
                    $klasse = $tokens[$name][1];
                    $klassenTiefe = $tiefe;
                }
                continue;
            }
            if ($token[0] !== T_CONST || $klasse === null) {
                continue;
            }
            // const NAME = 'wert';
            $name = self::naechstesToken($tokens, $i);
            $gleich = $name !== null ? self::naechstesToken($tokens, $name) : null;
            $wert = $gleich !== null ? self::naechstesToken($tokens, $gleich) : null;
            if ($wert === null || !is_array($tokens[$name]) || $tokens[$gleich] !== '='
                || !is_array($tokens[$wert]) || $tokens[$wert][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $ergebnis[$klasse][$tokens[$name][1]] = substr($tokens[$wert][1], 1, -1);
        }
        return $ergebnis;
    }

    /** @param array<int, mixed> $tokens */
    private static function naechstesToken(array $tokens, int $i): ?int {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }
        return null;
    }

    /** @param array<int, mixed> $tokens */
    private static function vorigesToken(array $tokens, int $i): ?int {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }
        return null;
    }

    /**
     * Alle *.php des Addons ohne ein eigenes tests/-Verzeichnis.
     *
     * @return array<int, string>
     */
    private static function quelldateien(string $slug): array {
        $dateien = [];
        foreach (self::phpFilesIn(self::PLUGINS_DIR . "/{$slug}") as $datei) {
            if (!str_contains(str_replace('\\', '/', $datei), "/{$slug}/tests/")) {
                $dateien[] = $datei;
            }
        }
        sort($dateien);
        return $dateien;
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
