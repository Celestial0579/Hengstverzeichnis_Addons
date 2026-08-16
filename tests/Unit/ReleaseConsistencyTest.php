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
        // Der echte Bestand muss für seine Ziel-Linie durchgehen - auch mit
        // refs/tags/-Präfix, wie ihn die Pipeline übergibt. Bewusst OHNE
        // feste Addon-Anzahl im Kommentar: Die stand hier jahrelang als
        // "alle 15 Addons, >=0.4.0" und stimmte längst nicht mehr (real 17
        // Addons auf Linie 0.5) - eine Zahl, die niemand nachzieht, ist eine
        // falsche Aussage im Test.
        //
        // Geprueft wird gegen v0.5.1 und nicht gegen v0.5.0: Seit dem
        // Embed-Widget (#89) enthaelt der Bestand ein Addon, das Kern-Code
        // aus #260 braucht und deshalb ehrlich `>=0.5.1` deklariert. Der
        // frueheste Tag, unter dem sich der Gesamtstand ausliefern laesst,
        // ist damit v0.5.1 - und genau das soll das Gate durchsetzen.
        [$exitCode, $output] = $this->runScript('v0.5.1');
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('0.5', $output);

        [$exitCode] = $this->runScript('refs/tags/v0.5.1');
        $this->assertSame(0, $exitCode);

        // Gegenprobe: Als v0.5.0 darf derselbe Bestand NICHT durchgehen.
        [$exitCode, $output] = $this->runScript('v0.5.0');
        $this->assertNotSame(0, $exitCode, 'embed-widget verlangt >=0.5.1 - v0.5.0 muss abgelehnt werden.');
        $this->assertStringContainsString('embed-widget', $output);
    }

    public function testFailsWhenTargetLineIsOutsideTheBounds(): void {
        // Linie 0.3 liegt unter der Untergrenze aller Addons.
        [$exitCode, $output] = $this->runScript('v0.3.9');
        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('core_compatibility', $output);

        // Linie 0.6 reißt die Obergrenze core_supported_max 0.5.
        [$exitCode, $output] = $this->runScript('v0.6.0');
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

    /**
     * Das Gate prüft gegen die ECHTE Tag-Version, nicht gegen X.Y.0.
     *
     * Vorher leitete es stur die Linien-Untergrenze ab: Ein Release v0.5.1
     * wurde gegen "0.5.0" geprüft, und ein Addon, das eine erst im
     * Patch-Release dazugekommene Kern-Funktion braucht, fiel zwangsläufig
     * durch. Es hätte `>=0.5.0` behaupten müssen, obwohl es auf 0.5.0
     * nachweislich nicht läuft - eine Unwahrheit im Manifest, die der Kern
     * beim Laden nicht mehr abfangen kann.
     *
     * Beide Richtungen werden geprüft, sonst bewiese der Test nur die Hälfte:
     * Für v0.5.1 muss `>=0.5.1` durchgehen, für v0.5.0 muss es scheitern.
     */
    public function testUsesTheActualTagVersionNotTheLineFloor(): void {
        $root = sys_get_temp_dir() . '/hengst_release_patch_' . bin2hex(random_bytes(6));
        $this->cleanupDirs[] = $root;
        mkdir($root . '/scripts', 0755, true);
        mkdir($root . '/plugins/braucht-patch', 0755, true);
        copy(self::SCRIPT, $root . '/scripts/check-release-consistency.php');
        file_put_contents($root . '/plugins/braucht-patch/plugin.json', json_encode([
            'slug' => 'braucht-patch',
            'name' => 'Braucht eine Patch-Version des Kerns',
            'version' => '1.0.0',
            'core_compatibility' => '>=0.5.1',
            'core_supported_max' => '0.5',
        ]));

        $script = $root . '/scripts/check-release-consistency.php';

        [$exitCode, $output] = $this->runScript('v0.5.1', $script);
        $this->assertSame(0, $exitCode, "Für v0.5.1 muss >=0.5.1 durchgehen. Ausgabe: {$output}");

        [$exitCode, $output] = $this->runScript('v0.5.2', $script);
        $this->assertSame(0, $exitCode, "Für eine spätere Patch-Version erst recht. Ausgabe: {$output}");

        [$exitCode, $output] = $this->runScript('v0.5.0', $script);
        $this->assertNotSame(0, $exitCode, 'Für v0.5.0 darf >=0.5.1 NICHT durchgehen.');
        $this->assertStringContainsString('core_compatibility', $output);
        $this->assertStringContainsString('0.5.0', $output, 'Die Meldung soll die geprüfte Version nennen, nicht die Linie.');
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
