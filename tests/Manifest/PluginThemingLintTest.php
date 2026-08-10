<?php
// tests/Manifest/PluginThemingLintTest.php

namespace Tests\Manifest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Statischer Theming-Lint über alle Addons (Addons#66): Seiten rendern im
 * Framework-Layout (App\Plugin\PluginPage), Farben kommen aus den
 * Theme-Variablen. Die drei früheren Korrekturrunden (#56, #58, #66)
 * bestanden jeweils aus Symptomen derselben Fehlerklasse - dieser Test
 * macht die Regeln hart, damit die vierte Runde ausbleibt.
 *
 * Bewusste Ausnahmen (Druckansichten, Overlay-Scrims, funktionales
 * QR-Schwarz/Weiß) tragen einen Marker-Kommentar
 * `theming-ausnahme: <grund>` (siehe docs/plugin-development.md im
 * Framework) - der Lint prüft Marker-Nähe statt die Regeln aufzuweichen.
 *
 * Wie PluginManifestTest läuft der Provider über ALLE plugins/*-
 * Verzeichnisse: Ein neues Addon ist automatisch lint-pflichtig.
 */
class PluginThemingLintTest extends TestCase {

    private const MARKER = 'theming-ausnahme';
    /** Wie viele Zeilen vor einem Fund der Marker gelten darf. */
    private const MARKER_REICHWEITE = 4;

    /** @return array<string, array{0: string}> */
    public static function pluginSlugProvider(): array {
        $cases = [];
        foreach (glob(__DIR__ . '/../../plugins/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = basename($dir);
            $cases[$slug] = [$slug];
        }
        return $cases;
    }

    /** @return array<int, string> */
    private function phpFilesOf(string $slug): array {
        $dir = __DIR__ . '/../../plugins/' . $slug;
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Fund erlaubt, wenn der Marker auf derselben oder einer der wenigen
     * Zeilen davor steht.
     *
     * @param array<int, string> $lines
     */
    private function isExcepted(array $lines, int $index): bool {
        $from = max(0, $index - self::MARKER_REICHWEITE);
        for ($i = $index; $i >= $from; $i--) {
            if (str_contains($lines[$i], self::MARKER)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param callable(string): bool $matcher
     * @return array<int, string> "datei:zeile: inhalt"-Funde
     */
    private function findViolations(string $slug, callable $matcher): array {
        $violations = [];
        foreach ($this->phpFilesOf($slug) as $file) {
            $lines = explode("\n", (string)file_get_contents($file));
            foreach ($lines as $index => $line) {
                if ($matcher($line) && !$this->isExcepted($lines, $index)) {
                    $violations[] = basename($file) . ':' . ($index + 1) . ': ' . trim($line);
                }
            }
        }
        return $violations;
    }

    /**
     * Eigenständige HTML-Dokumente sind der Kern der Drift: Sie laufen an
     * Layout, Theme-Umschalter und Markenfarben vorbei. Seiten gehören
     * durch App\Plugin\PluginPage::render() (Ausnahme: markierte
     * Druck-/Exportansichten).
     */
    #[DataProvider('pluginSlugProvider')]
    public function testNoUnmarkedStandaloneDocuments(string $slug): void {
        $violations = $this->findViolations(
            $slug,
            static fn(string $line): bool => str_contains($line, '<!DOCTYPE') || str_contains($line, '<body')
        );
        $this->assertSame([], $violations, "{$slug}: eigenständige Dokumente ohne theming-ausnahme-Marker");
    }

    #[DataProvider('pluginSlugProvider')]
    public function testNoOwnFontFamilies(string $slug): void {
        $violations = $this->findViolations(
            $slug,
            static fn(string $line): bool => stripos($line, 'font-family') !== false
                && stripos($line, 'var(--font-family') === false
                && stripos($line, 'font-family: inherit') === false
        );
        $this->assertSame([], $violations, "{$slug}: eigene Schriftarten statt var(--font-family)");
    }

    /**
     * Rohe Hex-Farben in Stil-Kontexten ("...: #abc") brechen Darkmode oder
     * Markenfarbe - erlaubt nur mit Marker. Kommentarzeilen sind
     * ausgenommen (Issue-Verweise wie "siehe: #196" sind keine Farben);
     * rgba-Werte werden bewusst nicht pauschal verboten (Schatten-Overlays
     * sind theme-neutral), der Scrim-Fall ist über den Marker geregelt.
     */
    #[DataProvider('pluginSlugProvider')]
    public function testNoRawColorsInStyleContexts(string $slug): void {
        $violations = $this->findViolations(
            $slug,
            static function (string $line): bool {
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                    return false;
                }
                return (bool)preg_match('/:\s*#[0-9a-fA-F]{3,8}\b/', $line);
            }
        );
        $this->assertSame([], $violations, "{$slug}: rohe Farbwerte statt Theme-Variablen");
    }

    #[DataProvider('pluginSlugProvider')]
    public function testNoHardcodedBorderRadii(string $slug): void {
        $violations = $this->findViolations(
            $slug,
            static fn(string $line): bool => (bool)preg_match('/border-radius\s*:\s*\d+px/', $line)
                && !str_contains($line, 'var(--border-radius')
        );
        $this->assertSame([], $violations, "{$slug}: hartkodierte border-radius-Pixel statt var(--border-radius)");
    }

    /**
     * Der Marker ist eine bewusste Entscheidung mit Begründung - ein
     * nackter Marker ohne Grund unterläuft die Idee.
     */
    #[DataProvider('pluginSlugProvider')]
    public function testExceptionMarkersCarryAReason(string $slug): void {
        $violations = $this->findViolations(
            $slug,
            static fn(string $line): bool => str_contains($line, self::MARKER)
                && !(bool)preg_match('/theming-ausnahme:\s*\S+/', $line)
        );
        $this->assertSame([], $violations, "{$slug}: theming-ausnahme-Marker ohne Begründung");
    }
}
