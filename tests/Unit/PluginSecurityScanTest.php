<?php
// tests/Unit/PluginSecurityScanTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regressionstest fuer die SQL-Pruefung des statischen Plugin-Checks
 * (security/plugin-security-scan.sh + security/lib/sql-concat-check.php,
 * Audit N36).
 *
 * Die alten grep-Regeln sahen nur `"` direkt vor dem Verkettungspunkt, liessen
 * ->exec() bei Verkettung aus und arbeiteten zeilenweise. Genau die im Repo
 * uebliche Schreibweise - `$db->query('... ' . $x)`, meist mit dem SQL auf der
 * Folgezeile - fiel dadurch komplett durch, und das Gate meldete "bestanden".
 *
 * Die Fixtures stehen bewusst als Nowdoc HIER und nicht als .php-Datei im
 * Repo: Das Semgrep-Gate scannt das ganze Repo und soll keine absichtlich
 * verwundbare Datei finden. Sie entstehen je Test in einem Temp-Verzeichnis
 * <tmp>/plugins/scan-fixture/ und werden in tearDown geloescht.
 *
 * Aufgerufen wird das Skript als Prozess, genau wie in der CI; geprueft wird
 * der Exitcode, nicht nur die Ausgabe (Lehre "Pruefschritt ohne Exitcode").
 */
class PluginSecurityScanTest extends TestCase {

    private const SCRIPT = __DIR__ . '/../../security/plugin-security-scan.sh';
    private const ALLOW = __DIR__ . '/../../security/baseline/plugin-findings.allow';

    private const T_INTERPOLATION = 'SQL mit Variable im Query-String (Interpolation)';
    private const T_KONKATENATION = 'SQL mit konkatenierter Variable';
    private const T_ZUSAMMENGESETZT = 'SQL-String aus Variable zusammengesetzt (Funktionsaufruf/Ausdruck)';

    /** Die Muster aus dem Befund plus die mehrzeiligen Varianten. */
    private const FIXTURE_UNSICHER = <<<'PHP'
<?php
namespace Plugin\ScanFixture;

class Plugin {
    public function a($db, $id) {
        $db->query('SELECT * FROM horses WHERE id = ' . $id);
    }
    public function b($db) {
        $db->prepare('SELECT * FROM x WHERE name = \'' . $_POST['n'] . '\'');
    }
    public function c($db, $id) {
        $db->exec("DELETE FROM x WHERE id = " . $id);
    }
    public function d($db, $id) {
        $stmt = $db->query(
            'SELECT *
               FROM horses
              WHERE id = ' . $id
        );
    }
    public function e($db, $id) {
        $stmt = $db->prepare(
            "SELECT * FROM horses
              WHERE id = $id"
        );
    }
    public function f($db, $x) {
        $db->query(<<<SQL
            SELECT * FROM horses WHERE name = '{$x}'
            SQL);
    }
    public function g($db, $x) {
        $db->query(sprintf('SELECT * FROM horses WHERE name = %s', $x));
    }
}
PHP;

    /** Zeile => erwarteter Titel (Zeile des Methodennamens). */
    private const ERWARTET_UNSICHER = [
        6 => self::T_KONKATENATION,
        9 => self::T_KONKATENATION,
        12 => self::T_KONKATENATION,
        15 => self::T_KONKATENATION,
        22 => self::T_INTERPOLATION,
        28 => self::T_INTERPOLATION,
        33 => self::T_ZUSAMMENGESETZT,
    ];

    private const FIXTURE_SAUBER = <<<'PHP'
<?php
namespace Plugin\ScanFixture;

use App\Database;

class Plugin {
    private $db;
    public function a($db, $n) {
        $db->query('SELECT * FROM horses WHERE name LIKE ' . $db->quote($n));
        $this->db->query('SELECT * FROM horses WHERE name = ' . $this->db->quote($n));
        Database::getInstance()->query('SELECT 1 FROM x WHERE n = ' . Database::getInstance()->quote($n));
        $db->query('SELECT * FROM horses ORDER BY id LIMIT ' . (int)$n);
        $db->prepare('SELECT * FROM horses WHERE id = ?');
        $sql = 'SELECT 1';
        $db->query($sql);
        $db->query($this->sql);
        // $db->query('x' . $y)
        /* $db->exec("DELETE FROM x WHERE id = $id"); */
        $db->query(<<<'SQL'
            SELECT * FROM horses WHERE name = '$nichtInterpoliert'
            SQL);
        $db->query('SELECT * FROM `' . self::TABELLE . '`');
    }
}
PHP;

    /** Zwei Konkatenationen, eine davon mit langem Argument. */
    private const FIXTURE_ZWEI = <<<'PHP'
<?php
namespace Plugin\ScanFixture;

class Plugin {
    public function a($db, $erste, $zweite) {
        $db->query('SELECT * FROM horses WHERE id = ' . $erste);
        $db->query(
            'SELECT id, name, birth_year, sex, color, breed, status, is_published, deleted_at, created_at, updated_at
               FROM horses WHERE breeding_station_id IN (' . $zweite . ') ORDER BY name ASC'
        );
    }
}
PHP;

    private ?string $tmp = null;

    protected function setUp(): void {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === '') {
            $this->markTestSkipped('bash nicht verfuegbar.');
        }
    }

    protected function tearDown(): void {
        if ($this->tmp !== null) {
            $this->removeTree($this->tmp);
            $this->tmp = null;
        }
    }

    public function testMusterAusDemBefundWerdenBlockierendGemeldet(): void {
        $plugins = $this->fixture(self::FIXTURE_UNSICHER);

        [$exit, $out] = $this->scan($plugins, $this->allow(''));

        $this->assertSame(2, $exit, $out);
        foreach (self::ERWARTET_UNSICHER as $zeile => $titel) {
            $this->assertStringContainsString(
                "[HIGH] {$titel} (plugins/scan-fixture/Plugin.php:{$zeile})",
                $out,
                "Stelle Zeile {$zeile} nicht als '{$titel}' gemeldet."
            );
        }
        $this->assertMatchesRegularExpression('/HIGH=' . count(self::ERWARTET_UNSICHER) . '\b/', $out);
    }

    public function testSauberesPluginBesteht(): void {
        $plugins = $this->fixture(self::FIXTURE_SAUBER);

        [$exit, $out] = $this->scan($plugins, $this->allow(''));

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('HIGH=0 MED=0', $out);
    }

    public function testDreifeldEintragGibtNurDieEineStelleFrei(): void {
        $plugins = $this->fixture(self::FIXTURE_ZWEI);

        [$exit, $out] = $this->scan(
            $plugins,
            $this->allow("scan-fixture|" . self::T_KONKATENATION . "|'SELECT * FROM horses WHERE id = ' . \$erste\n")
        );

        $this->assertSame(2, $exit, $out);
        $this->assertStringContainsString('allowlisted=1', $out);
        $this->assertStringContainsString('(plugins/scan-fixture/Plugin.php:7)', $out);
        $this->assertStringNotContainsString('(plugins/scan-fixture/Plugin.php:6)', $out);
    }

    public function testZweifeldEintragGibtDieRegelFuerDasPluginFrei(): void {
        $plugins = $this->fixture(self::FIXTURE_ZWEI);

        [$exit, $out] = $this->scan($plugins, $this->allow('scan-fixture|' . self::T_KONKATENATION . "\n"));

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('allowlisted=2', $out);
    }

    public function testFremderPluginPraefixGibtNichtsFrei(): void {
        $plugins = $this->fixture(self::FIXTURE_ZWEI);

        [$exit, $out] = $this->scan($plugins, $this->allow(
            'x-scan-fixture|' . self::T_KONKATENATION . "\n"
            . 'scan-fixture|' . self::T_KONKATENATION . " \n"   // Titel mit Leerzeichen: kein exakter Treffer
            . 'scan-fixture|Konkatenation' . "\n"                // Kurztitel: kein exakter Treffer
        ));

        $this->assertSame(2, $exit, $out);
        $this->assertStringContainsString('allowlisted=0', $out);
    }

    /**
     * Der Fingerabdruck wird NICHT gekuerzt: Ein Ausschnitt weit hinter
     * Zeichen 120 des Arguments muss greifen.
     */
    public function testAusschnittHinterZeichen120Greift(): void {
        $plugins = $this->fixture(self::FIXTURE_ZWEI);

        [$exit, $out] = $this->scan($plugins, $this->allow(
            'scan-fixture|' . self::T_KONKATENATION . "|'SELECT * FROM horses WHERE id = ' . \$erste\n"
            . 'scan-fixture|' . self::T_KONKATENATION . "|breeding_station_id IN (' . \$zweite . ')\n"
        ));

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('allowlisted=2', $out);
    }

    public function testRueckfallOhnePhpMeldetEinfacheAnfuehrungszeichenAlsMed(): void {
        $plugins = $this->fixture(self::FIXTURE_UNSICHER);

        [$exit, $out] = $this->scan($plugins, $this->allow(''), ['PLUGIN_SCAN_NO_PHP' => '1']);

        $this->assertStringContainsString('Rueckfall zeilenweise', $out);
        // Frueherer Regelbestand bleibt blockierend: exec("..." . $id).
        $this->assertStringContainsString('[HIGH] ' . self::T_KONKATENATION . ' (plugins/scan-fixture/Plugin.php:12)', $out);
        // Einzeilige Konkatenation in einfachen Anfuehrungszeichen: nur MED.
        $this->assertStringContainsString('[MED ] ' . self::T_KONKATENATION . ' (plugins/scan-fixture/Plugin.php:6)', $out);
        $this->assertStringContainsString('[MED ] ' . self::T_KONKATENATION . ' (plugins/scan-fixture/Plugin.php:9)', $out);
        $this->assertSame(2, $exit, $out);
    }

    public function testRueckfallOhnePhpBleibtBeiSauberemPluginGruen(): void {
        $plugins = $this->fixture(self::FIXTURE_SAUBER);

        [$exit, $out] = $this->scan($plugins, $this->allow(''), ['PLUGIN_SCAN_NO_PHP' => '1']);

        $this->assertSame(0, $exit, $out);
    }

    /**
     * Der echte Repo-Stand mit der echten Allowlist besteht - und JEDER
     * Eintrag wird gebraucht. So passen Allowlist und Code auch bei einem
     * lokalen `composer test` zusammen; ein veralteter Eintrag faellt auf.
     */
    public function testEchtesRepoBestehtUndJederAllowlistEintragGreift(): void {
        $eintraege = 0;
        foreach (file(self::ALLOW, FILE_IGNORE_NEW_LINES) ?: [] as $zeile) {
            if (trim($zeile) !== '' && !str_starts_with(ltrim($zeile), '#')) {
                $eintraege++;
            }
        }

        $cmd = 'bash ' . escapeshellarg(self::SCRIPT) . ' 2>&1';
        exec($cmd, $lines, $exit);
        $out = implode("\n", $lines);

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString("allowlisted={$eintraege})", $out);
    }

    // --- Hilfen ------------------------------------------------------------

    private function fixture(string $code): string {
        $this->tmp = sys_get_temp_dir() . '/plugin-scan-' . uniqid('', true);
        $dir = $this->tmp . '/plugins/scan-fixture';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/Plugin.php', $code);
        return $this->tmp . '/plugins';
    }

    private function allow(string $inhalt): string {
        $pfad = $this->tmp . '/allow';
        file_put_contents($pfad, "# Test-Allowlist\n" . $inhalt);
        return $pfad;
    }

    /**
     * @param array<string, string> $env
     * @return array{0: int, 1: string}
     */
    private function scan(string $plugins, string $allow, array $env = []): array {
        $prefix = 'PLUGIN_SCAN_ALLOW=' . escapeshellarg($allow) . ' ';
        foreach ($env as $k => $v) {
            $prefix .= $k . '=' . escapeshellarg($v) . ' ';
        }
        $cmd = $prefix . 'bash ' . escapeshellarg(self::SCRIPT) . ' ' . escapeshellarg($plugins) . ' 2>&1';
        exec($cmd, $lines, $exit);
        return [$exit, implode("\n", $lines)];
    }

    private function removeTree(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
