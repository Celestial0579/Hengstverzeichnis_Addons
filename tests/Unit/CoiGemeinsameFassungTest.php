<?php
// tests/Unit/CoiGemeinsameFassungTest.php

namespace Tests\Unit;

use Hengstverzeichnis\Addons\Shared\WrightCoi;
use PHPUnit\Framework\TestCase;
use Plugin\AnpaarungsEmpfehlung\CoiEstimator;
use Plugin\Inzuchtkoeffizient\CoiCalculator;

/**
 * Der eine Test zur einen Fassung des COI-Rechenkerns (Addons#123).
 *
 * Vorgeschichte: Der Inzuchtkoeffizient war zweimal implementiert -
 * `CoiCalculator` in `inzuchtkoeffizient`, `CoiEstimator` in
 * `anpaarungs-empfehlung`. Nach Entfernen der Kommentare blieb zwischen beiden
 * kein einziges Zeichen Unterschied. Das ist keine Formalie: Der eine Wert
 * erscheint auf der öffentlichen Detailseite, der andere SORTIERT die
 * Anpaarungs-Empfehlungen. Wäre nur eine der beiden korrigiert worden, hätte
 * dasselbe Verzeichnis dieselbe Paarung mit zwei verschiedenen Werten gezeigt.
 * Ein Hinweiskommentar stand schon in beiden Dateien - er hat die Doppelung
 * nicht verhindert. Deshalb wird das Ergebnis der Zusammenlegung hier
 * maschinell festgehalten.
 *
 * Geprüft werden drei Dinge:
 *
 *   1. Jedes Addon, das den Rechenkern mitbringt, liefert `WrightCoi.php`
 *      BYTEWEISE identisch aus. Mitgeliefert werden MUSS die Datei, weil Addons
 *      einzeln installierbar sind und der Addon-Installer des Kerns
 *      (GithubAddonRepository::verifyExtractedTreeIsSafe()) ein entpacktes
 *      Paket verwirft, sobald es irgendeinen Symlink enthält - die Begründung
 *      steht ausführlich im Kopfkommentar von WrightCoi.php.
 *   2. Es gibt keine ZWEITE Rechnung. Die Pfad-Koeffizienten-Formel
 *      `0.5 ** (n1 + n2 + 1)` darf unter `plugins/` ausschließlich in
 *      `WrightCoi.php` vorkommen. Genau diese Prüfung hätte den Befund von #123
 *      am Tag seiner Entstehung gemeldet.
 *   3. Die Altnamen `CoiCalculator`/`CoiEstimator` bezeichnen dieselbe Klasse -
 *      sind also Alias und nicht wieder eine eigene Fassung -, und diese Klasse
 *      rechnet die Lehrbuchfälle korrekt.
 *
 * Die fachlichen Einzelfälle je Addon (Tiefensemantik echter
 * PedigreeBuilder-Bäume in #72, kantenbasierter AncestorTreeBuilder in #69)
 * bleiben in `InzuchtkoeffizientCoiTest` bzw. `AnpaarungsEmpfehlungCoiTest`;
 * hier steht nur, was die Addons GEMEINSAM haben. Wie beide läuft dieser Test
 * ohne Datenbank und ohne Framework-Instanz.
 */
class CoiGemeinsameFassungTest extends TestCase {

    private const PLUGINS_DIR = __DIR__ . '/../../plugins';
    private const SHARED_FILE = 'WrightCoi.php';

    /**
     * Die Formel als Zeichenfolge. Bewusst der nackte Ausdruck und nicht ein
     * ganzer Codeblock: Wer eine zweite Fassung einführt, kann sie umbenennen
     * und umformatieren - die Potenz zur Basis 0.5 bleibt.
     */
    private const FORMEL = '0.5 **';

    public static function setUpBeforeClass(): void {
        // Plugins liegen nicht im Composer-Autoloader (sie werden zur Laufzeit
        // vom PluginManager des Kerns geladen), deshalb hier ausdrücklich.
        // Beide Entry-Dateien nacheinander - genau der Fall "beide Addons
        // aktiv", in dem der class_exists()-Wächter vor dem zweiten
        // require_once greifen muss. Ohne ihn stürbe schon diese Zeile mit
        // "Cannot redeclare class".
        require_once self::PLUGINS_DIR . '/inzuchtkoeffizient/Plugin.php';
        require_once self::PLUGINS_DIR . '/anpaarungs-empfehlung/Plugin.php';
    }

    /**
     * Alle Addons, die den gemeinsamen Rechenkern mitliefern - zur Laufzeit
     * ermittelt, damit ein drittes Addon automatisch mitgeprüft wird.
     *
     * @return array<string, string> Slug => absoluter Pfad
     */
    private static function ausgelieferteKopien(): array {
        $kopien = [];
        foreach (glob(self::PLUGINS_DIR . '/*/' . self::SHARED_FILE) ?: [] as $pfad) {
            $kopien[basename(dirname($pfad))] = $pfad;
        }
        ksort($kopien);
        return $kopien;
    }

    /** @return array<int, string> Alle .php-Dateien unter plugins/ */
    private static function allePluginDateien(): array {
        $dateien = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::PLUGINS_DIR, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $datei) {
            /** @var \SplFileInfo $datei */
            if ($datei->isFile() && $datei->getExtension() === 'php') {
                $dateien[] = $datei->getPathname();
            }
        }
        sort($dateien);
        return $dateien;
    }

    /**
     * Der Kern der Sache: Die mitgelieferten Kopien müssen Byte für Byte
     * übereinstimmen. Wer den Rechenkern ändert, kopiert ihn danach in die
     * übrigen Addon-Verzeichnisse - ein `cp`, mehr ist nicht zu tun.
     */
    public function testAlleAusgeliefertenKopienSindByteweiseGleich(): void {
        $kopien = self::ausgelieferteKopien();

        $this->assertGreaterThanOrEqual(
            2,
            count($kopien),
            'Der gemeinsame Rechenkern sollte von mindestens zwei Addons mitgeliefert werden - '
            . 'sonst prüft dieser Test nichts. Erwartet: inzuchtkoeffizient und anpaarungs-empfehlung.'
        );

        $hashes = [];
        foreach ($kopien as $slug => $pfad) {
            $hashes[$slug] = hash_file('sha256', $pfad);
        }

        $this->assertCount(
            1,
            array_unique($hashes),
            "Die ausgelieferten Fassungen von " . self::SHARED_FILE . " sind auseinandergelaufen "
            . "(Addons#123). Gefundene Prüfsummen:\n"
            . implode("\n", array_map(
                static fn(string $slug, string $hash): string => "  plugins/{$slug}/" . self::SHARED_FILE . ": {$hash}",
                array_keys($hashes),
                $hashes
            ))
            . "\nDie geänderte Fassung in die übrigen Addon-Verzeichnisse kopieren."
        );
    }

    /**
     * Auch der Klassenname muss überall derselbe sein - zwei zeichengleiche
     * Dateien nützen nichts, wenn eine davon irgendwann in einen eigenen
     * Namensraum umgezogen würde: Dann stünden wieder zwei Klassen im Speicher.
     */
    public function testAlleKopienDeklarierenDieselbeKlasse(): void {
        foreach (self::ausgelieferteKopien() as $slug => $pfad) {
            $inhalt = (string) file_get_contents($pfad);

            // Namensraum und Klassenname aus dem erwarteten FQCN herleiten,
            // damit ein Umbenennen der Klasse hier nicht vergessen wird.
            $namensraum = substr(WrightCoi::class, 0, strrpos(WrightCoi::class, '\\') ?: 0);
            $kurzname = substr(WrightCoi::class, strrpos(WrightCoi::class, '\\') + 1);

            $this->assertStringContainsString(
                "namespace {$namensraum};",
                $inhalt,
                "plugins/{$slug}/" . self::SHARED_FILE . " deklariert nicht den Namensraum {$namensraum}."
            );
            $this->assertStringContainsString(
                "class {$kurzname}",
                $inhalt,
                "plugins/{$slug}/" . self::SHARED_FILE . " deklariert nicht die Klasse {$kurzname}."
            );
        }
    }

    /**
     * Keine zweite Rechnung irgendwo unter plugins/: Die Pfad-Formel darf nur
     * im gemeinsamen Rechenkern stehen. Diese Prüfung ist der eigentliche
     * Wächter gegen einen Rückfall in #123 - der Hinweiskommentar in den
     * Plugin.php-Dateien war es nachweislich nicht.
     */
    public function testDiePfadFormelStehtNurImGemeinsamenRechenkern(): void {
        $funde = [];
        foreach (self::allePluginDateien() as $datei) {
            if (basename($datei) === self::SHARED_FILE) {
                continue;
            }
            foreach (explode("\n", (string) file_get_contents($datei)) as $nr => $zeile) {
                if (str_contains($zeile, self::FORMEL)) {
                    $relativ = substr($datei, strlen(dirname(self::PLUGINS_DIR)) + 1);
                    $funde[] = $relativ . ':' . ($nr + 1) . ': ' . trim($zeile);
                }
            }
        }

        $this->assertSame(
            [],
            $funde,
            'Die COI-Formel steht außerhalb von ' . self::SHARED_FILE . ' - das ist die Doppelung '
            . 'aus Addons#123. Den gemeinsamen Rechenkern benutzen '
            . '(Hengstverzeichnis\\Addons\\Shared\\WrightCoi), statt die Rechnung erneut zu schreiben.'
        );
    }

    /**
     * Die Altnamen sind Alias auf DIESELBE Klasse, keine eigenen Fassungen.
     * `::class` allein bewiese das nicht - eine Unterklasse hätte einen eigenen
     * Namen und trotzdem eigenen Code; verglichen wird deshalb der von PHP
     * aufgelöste Klassenname der Reflection.
     */
    public function testAltnamenZeigenAufDieselbeKlasse(): void {
        $this->assertSame(
            WrightCoi::class,
            (new \ReflectionClass(CoiCalculator::class))->getName(),
            'Plugin\\Inzuchtkoeffizient\\CoiCalculator ist wieder eine eigene Klasse statt eines Alias.'
        );
        $this->assertSame(
            WrightCoi::class,
            (new \ReflectionClass(CoiEstimator::class))->getName(),
            'Plugin\\AnpaarungsEmpfehlung\\CoiEstimator ist wieder eine eigene Klasse statt eines Alias.'
        );
    }

    /** Knoten im Format des Framework-PedigreeBuilder (siehe Hook-Parameter $pedigree). */
    private static function node(int $id, ?array $sire = null, ?array $dam = null): array {
        return ['id' => $id, 'name' => "Pferd {$id}", 'sire' => $sire, 'dam' => $dam];
    }

    /**
     * Lehrbuchfälle am gemeinsamen Rechenkern selbst. `fromParentTrees($sire,
     * $dam)` liefert die Verwandtschaft der beiden ELTERN - und die ist per
     * Definition der COI ihres Nachkommen.
     *
     * @return array<string, array{0: ?array, 1: ?array, 2: float}>
     */
    public static function lehrbuchFaelle(): array {
        $grossvaeterlich = static fn(): array => self::node(1, self::node(10), self::node(11));
        $grossmuetterlich = static fn(): array => self::node(2, self::node(12), self::node(13));

        return [
            // Beide Eltern (3, 4) haben denselben Vater 1 und dieselbe Mutter 2:
            // zwei gemeinsame Vorfahren, je ein Pfad mit n1 = n2 = 1.
            'Vollgeschwister als Eltern' => [
                self::node(3, self::node(1), self::node(2)),
                self::node(4, self::node(1), self::node(2)),
                0.25,
            ],
            // Nur der Vater (1) ist gemeinsam: 0,5^(1+1+1).
            'Halbgeschwister als Eltern' => [
                self::node(6, self::node(1), self::node(2)),
                self::node(7, self::node(1), self::node(5)),
                0.125,
            ],
            // Vater 1 mit eigener Tochter 3: n1 = 0 (er IST der Elternteil), n2 = 1.
            'Elter x Nachkomme' => [
                self::node(1),
                self::node(3, self::node(1), self::node(2)),
                0.25,
            ],
            // Wrights Pfadregel: 10-13 dürfen NICHT zusätzlich zählen, sonst 0,375.
            'Vollgeschwister mit bekannten Grosseltern' => [
                self::node(3, $grossvaeterlich(), $grossmuetterlich()),
                self::node(4, $grossvaeterlich(), $grossmuetterlich()),
                0.25,
            ],
            // Vorfahre 1 steht auf der Vaterseite zweimal (n1 = 1 und n1 = 2),
            // auf der Mutterseite einmal (n2 = 2): 0,5^4 + 0,5^5.
            'Mehrfache Pfade zum selben Vorfahren' => [
                self::node(20, self::node(1), self::node(21, self::node(1), self::node(26))),
                self::node(22, self::node(23, self::node(1), self::node(24)), self::node(25)),
                0.09375,
            ],
            'Nicht verwandte Eltern' => [
                self::node(30, self::node(31), self::node(32)),
                self::node(33, self::node(34), self::node(35)),
                0.0,
            ],
            'Kein Stammbaum vorhanden' => [null, null, 0.0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lehrbuchFaelle')]
    public function testGemeinsamerRechenkernLiefertDieLehrbuchwerte(?array $sire, ?array $dam, float $erwartet): void {
        $this->assertEqualsWithDelta($erwartet, WrightCoi::fromParentTrees($sire, $dam), 1e-12);
    }
}
