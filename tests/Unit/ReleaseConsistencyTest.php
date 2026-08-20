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

    /**
     * Die Ziel-Linie wird ABGELEITET, nicht festgenagelt.
     *
     * Hier stand bis v0.8 ein fester Tag (`v0.7.0`) samt Begründung, welche
     * Addons ihn erzwingen. Der Kommentar daneben warnte ausdrücklich vor
     * genau diesem Fehler - "eine Zahl, die niemand nachzieht, ist eine
     * falsche Aussage im Test" - und meinte damit die Addon-ANZAHL. Die
     * VERSION daneben war derselbe Fehler eine Ebene höher: Beim Sprung auf
     * 0.8 wurde der Test rot, obwohl der Repo-Stand in Ordnung war.
     *
     * Abgeleitet wird aus den Manifesten selbst: Die höchste
     * `core_supported_max` ist die Linie, für die dieser Bestand vorbereitet
     * ist. Der Test prüft dann nur noch, dass das Skript derselben Meinung
     * ist - und dass es die Linie darüber ablehnt.
     */
    public function testRealRepoStatePassesForItsTargetLine(): void {
        $linie = $this->hoechsteUnterstuetzteLinie();

        [$exitCode, $output] = $this->runScript("v{$linie}.0");
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString($linie, $output);

        // Mit refs/tags/-Präfix, wie ihn die Pipeline übergibt.
        [$exitCode] = $this->runScript("refs/tags/v{$linie}.0");
        $this->assertSame(0, $exitCode);

        // Und als Vorabversion derselben Linie (seit v0.8: die Beta-Phase
        // geht durch dieselbe Kette wie ein regulärer Release).
        [$exitCode, $output] = $this->runScript("v{$linie}.0-beta.1");
        $this->assertSame(0, $exitCode, $output);
    }

    public function testFailsWhenTargetLineIsOutsideTheBounds(): void {
        // Unterhalb der höchsten Untergrenze: mindestens ein Addon verlangt
        // mehr, als die Ziel-Linie hergibt.
        [$exitCode, $output] = $this->runScript('v0.3.9');
        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('core_compatibility', $output);

        // Oberhalb der höchsten Obergrenze: die Linie darüber reißt
        // core_supported_max. Auch das abgeleitet - sonst wäre der Test beim
        // nächsten Minor-Sprung wieder rot.
        [$major, $minor] = array_map('intval', explode('.', $this->hoechsteUnterstuetzteLinie()));
        $darueber = $major . '.' . ($minor + 1);

        [$exitCode, $output] = $this->runScript("v{$darueber}.0");
        $this->assertNotSame(0, $exitCode, "Linie {$darueber} muss die Obergrenzen reißen.");
        $this->assertStringContainsString('core_supported_max', $output);
    }

    /**
     * Höchste `core_supported_max` über alle Addons - die Linie, für die
     * dieser Bestand vorbereitet ist.
     */
    private function hoechsteUnterstuetzteLinie(): string {
        $hoechste = null;
        foreach (glob(__DIR__ . '/../../plugins/*/plugin.json') ?: [] as $datei) {
            $manifest = json_decode((string)file_get_contents($datei), true);
            $max = is_array($manifest) ? ($manifest['core_supported_max'] ?? null) : null;
            if (!is_string($max) || !preg_match('/^\d+\.\d+$/', $max)) {
                continue;
            }
            if ($hoechste === null || version_compare($max, $hoechste, '>')) {
                $hoechste = $max;
            }
        }

        $this->assertNotNull($hoechste, 'Kein Addon nennt eine gültige core_supported_max - der Bestand ist unbrauchbar.');
        return $hoechste;
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
