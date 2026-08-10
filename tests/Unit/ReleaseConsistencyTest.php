<?php
// tests/Unit/ReleaseConsistencyTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests für das Konsistenz-Gate der Release-Pipeline (#65,
 * scripts/check-release-consistency.php): Ein Release vX.Y.z darf nur
 * entstehen, wenn JEDES Addon die Framework-Linie X.Y per Unter- und
 * Pflicht-Obergrenze einschließt. Das Skript wird als Prozess gegen den
 * echten Repo-Stand bzw. präparierte Kopien ausgeführt - genau so, wie die
 * Pipeline es aufruft (Lehre "Prüfschritt ohne Exitcode": geprüft wird der
 * Rückgabewert, nicht die Ausgabe).
 */
class ReleaseConsistencyTest extends TestCase {

    private const SCRIPT = __DIR__ . '/../../scripts/check-release-consistency.php';

    /** @var array<int, string> */
    private array $cleanupDirs = [];

    protected function tearDown(): void {
        foreach ($this->cleanupDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->cleanupDirs = [];
    }

    /** @return array{0: int, 1: string} Exitcode und Ausgabe */
    private function runScript(string $tag, ?string $scriptPath = null): array {
        $cmd = 'php ' . escapeshellarg($scriptPath ?? self::SCRIPT) . ' ' . escapeshellarg($tag) . ' 2>&1';
        exec($cmd, $lines, $exitCode);
        return [$exitCode, implode("\n", $lines)];
    }

    public function testRealRepoStatePassesForItsTargetLine(): void {
        // Der echte Bestand (alle 15 Addons, >=0.4.0 + core_supported_max 0.4)
        // muss für die Ziel-Linie 0.4 durchgehen - auch mit refs/tags/-Präfix,
        // wie ihn die Pipeline übergibt.
        [$exitCode, $output] = $this->runScript('v0.4.0');
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('0.4', $output);

        [$exitCode] = $this->runScript('refs/tags/v0.4.0');
        $this->assertSame(0, $exitCode);
    }

    public function testFailsWhenTargetLineIsOutsideTheBounds(): void {
        // Linie 0.3 liegt unter der Untergrenze >=0.4.0 aller Addons.
        [$exitCode, $output] = $this->runScript('v0.3.9');
        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('core_compatibility', $output);

        // Linie 0.5 reißt die Obergrenze core_supported_max 0.4.
        [$exitCode, $output] = $this->runScript('v0.5.0');
        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('core_supported_max', $output);
    }

    public function testFailsOnInvalidTagFormat(): void {
        foreach (['0.4', 'v0.4', 'release-1', ''] as $tag) {
            [$exitCode] = $this->runScript($tag);
            $this->assertNotSame(0, $exitCode, "Tag '{$tag}' dürfte nicht akzeptiert werden");
        }
    }

    public function testFailsWhenAManifestLacksTheUpperBound(): void {
        // Kopie des Skripts in eine Miniatur-Repo-Struktur legen, in der ein
        // Addon die Pflicht-Obergrenze nicht trägt.
        $root = sys_get_temp_dir() . '/hengst_release_check_' . bin2hex(random_bytes(6));
        $this->cleanupDirs[] = $root;
        mkdir($root . '/scripts', 0755, true);
        mkdir($root . '/plugins/ohne-max', 0755, true);
        copy(self::SCRIPT, $root . '/scripts/check-release-consistency.php');
        file_put_contents($root . '/plugins/ohne-max/plugin.json', json_encode([
            'slug' => 'ohne-max',
            'name' => 'Ohne Obergrenze',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.4.0',
        ]));

        [$exitCode, $output] = $this->runScript('v0.4.0', $root . '/scripts/check-release-consistency.php');
        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('core_supported_max', $output);
        $this->assertStringContainsString('ohne-max', $output);
    }

    private function removeTree(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
