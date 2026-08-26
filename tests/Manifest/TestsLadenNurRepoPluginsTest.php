<?php
// tests/Manifest/TestsLadenNurRepoPluginsTest.php

namespace Tests\Manifest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kein Test darf eine Plugin-Datei aus dem VENDORIERTEN Framework laden (#160).
 *
 * WARUM DAS EIN EIGENER TEST IST. `tests/bootstrap.php` spiegelt `plugins/`
 * vor jedem Lauf nach `vendor/hengstverzeichnis/framework/plugins/`. Die
 * Dateien sind danach inhaltlich gleich - für `require_once` aber ZWEI Pfade.
 * Lädt ein Test die eine Fassung und ein anderer die andere, bricht PHP mit
 * `Cannot redeclare class ...` ab, sobald beide im selben Prozess laufen.
 *
 * WARUM DIE CI DAS NICHT VON ALLEIN FÄNGT - der eigentliche Grund für diesen
 * Test. `.github/workflows/tests.yml` fährt Manifest, Unit und Functional in
 * DREI getrennten Prozessen; dort begegnen sich die beiden Ladewege nie.
 * `composer test` fährt sie in EINEM, und genau so ruft
 * `framework-update.yml` die Suite auf. Der wöchentliche Lauf gegen
 * Framework-main stand deshalb ab dem 25.08. still und meldete „Addons
 * brechen gegen Framework-main" (#160), obwohl kein einziger Test gegen den
 * neuen Kern gelaufen war - ein Umgebungsfehler, als Ergebnis ausgegeben.
 *
 * Dieser Test kostet Millisekunden und läuft in der Manifest-Suite mit, also
 * in jedem CI-Lauf. Er ersetzt keinen Ein-Prozess-Lauf, aber er fängt die
 * Ursache an der Stelle, an der sie entsteht.
 *
 * ERLAUBT bleibt jede ANDERE Verwendung von FRAMEWORK_VENDOR_DIR - Pfade auf
 * `storage/`, `database/schema.sql`, `src/` oder ein `assertFileExists` auf
 * die Kopie. Verboten ist ausschliesslich das LADEN einer Datei unter
 * `<vendor>/plugins/`.
 */
class TestsLadenNurRepoPluginsTest extends TestCase {

    /** @return array<string, array{0: string}> */
    public static function pruefbareTestdateien(): array {
        $wurzel = dirname(__DIR__);
        $faelle = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($wurzel, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $datei) {
            if (!$datei->isFile() || $datei->getExtension() !== 'php') {
                continue;
            }
            $relativ = substr($datei->getPathname(), strlen(dirname($wurzel)) + 1);
            $faelle[$relativ] = [$datei->getPathname()];
        }
        ksort($faelle);

        self::assertNotEmpty($faelle, 'Keine Testdateien gefunden - der Provider greift ins Leere.');

        return $faelle;
    }

    #[DataProvider('pruefbareTestdateien')]
    public function testKeinLadenAusDemVendoriertenPluginsVerzeichnis(string $pfad): void {
        $zeilen = file($pfad, FILE_IGNORE_NEW_LINES) ?: [];

        /* ZWEI FORMEN, UND DIE ZWEITE IST DIE, DIE WIRKLICH VORKAM.
           Ein reiner Zeilentest auf "require + FRAMEWORK_VENDOR_DIR" wäre
           wertlos: Im echten Fall (#160) stand der Pfad in einer Variablen und
           das require_once eine Zeile darunter. Genau daran ist die erste
           Fassung dieses Tests gescheitert - sie blieb grün, als ich den
           Fehler zur Gegenprobe wieder eingebaut habe. */
        $verdaechtig = [];   // Variablenname => Zeilennummer der Zuweisung
        $funde = [];

        foreach ($zeilen as $nr => $zeile) {
            $zeigtAufPluginKopie = str_contains($zeile, 'FRAMEWORK_VENDOR_DIR')
                && str_contains($zeile, 'plugins/');

            // Form 1: unmittelbar, alles in einer Zeile.
            if ($zeigtAufPluginKopie && preg_match('/\b(require|include)(_once)?\b/', $zeile)) {
                $funde[] = sprintf('  Zeile %d: %s', $nr + 1, trim($zeile));
                continue;
            }

            // Form 2a: Zuweisung eines solchen Pfads an eine Variable.
            if ($zeigtAufPluginKopie && preg_match('/\$([A-Za-z_]\w*)\s*=/', $zeile, $m)) {
                $verdaechtig[$m[1]] = $nr + 1;
                continue;
            }

            // Form 2b: require/include dieser Variablen - irgendwo danach.
            if (preg_match('/\b(require|include)(_once)?\s*\(?\s*\$([A-Za-z_]\w*)/', $zeile, $m)
                && isset($verdaechtig[$m[3]])
            ) {
                $funde[] = sprintf(
                    '  Zeile %d: %s   (Pfad gesetzt in Zeile %d)',
                    $nr + 1,
                    trim($zeile),
                    $verdaechtig[$m[3]]
                );
            }
        }

        $this->assertSame(
            [],
            $funde,
            'In ' . basename($pfad) . " wird eine Plugin-Datei aus dem vendorierten Framework geladen:\n"
            . implode("\n", $funde) . "\n\n"
            . "Das erzeugt einen zweiten Ladeweg für dieselbe Klasse. Sobald ein anderer Test "
            . "sie aus plugins/ lädt - tests/Manifest/PluginManifestTest tut das für JEDES Plugin -, "
            . "bricht `composer test` mit \"Cannot redeclare class\" ab.\n"
            . "Richtig ist: __DIR__ . '/../../plugins/<slug>/Plugin.php'."
        );
    }
}
