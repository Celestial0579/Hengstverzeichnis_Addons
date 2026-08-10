<?php
// tests/Unit/AnpaarungsEmpfehlungCoiTest.php

namespace Tests\Unit;

use App\Database;
use App\Service\PedigreeBuilder;
use PHPUnit\Framework\TestCase;
use Plugin\AnpaarungsEmpfehlung\AncestorTreeBuilder;
use Plugin\AnpaarungsEmpfehlung\CoiEstimator;
use Plugin\AnpaarungsEmpfehlung\EmpfehlungController;
use Plugin\Inzuchtkoeffizient\CoiCalculator;

/**
 * Rechenkern des Plugins "anpaarungs-empfehlung" - und sein Gleichlauf mit dem
 * CoiCalculator des Plugins "inzuchtkoeffizient".
 *
 * Beide Addons berechnen dieselbe Größe (Verwandtschaft der Eltern = COI des
 * Fohlens). Historisch liefen sie auseinander: Der CoiCalculator bekam Wrights
 * Pfadregel, der CoiEstimator hier nicht - für dieselbe Verpaarung zeigten die
 * beiden Addons dadurch unterschiedliche Prozentwerte. Diese Klasse nagelt
 * beides fest: die Lehrbuchwerte des Estimators selbst UND die Wertgleichheit
 * der beiden Rechenkerne, damit eine künftige Änderung an nur einem von beiden
 * sofort rot wird.
 *
 * Seit Addons#69 zusätzlich: Der Gleichlauf des kantenbasierten
 * AncestorTreeBuilder (ein Query für den ganzen Bestand, Bäume rein in PHP)
 * mit dem echten App\Service\PedigreeBuilder des Kerns (rekursive
 * Einzel-SELECTs) - der "alte Weg" des Addons. Dafür wird eine In-Memory-
 * SQLite-Datenbank per Reflection als App\Database-Singleton injiziert; der
 * PedigreeBuilder läuft dann unverändert gegen denselben konstruierten
 * Bestand wie der AncestorTreeBuilder.
 */
class AnpaarungsEmpfehlungCoiTest extends TestCase {

    public static function setUpBeforeClass(): void {
        // Plugins liegen nicht im Composer-Autoloader (sie werden zur Laufzeit
        // vom PluginManager des Kerns geladen), deshalb hier ausdrücklich.
        require_once __DIR__ . '/../../plugins/anpaarungs-empfehlung/Plugin.php';
        require_once __DIR__ . '/../../plugins/inzuchtkoeffizient/Plugin.php';
    }

    protected function tearDown(): void {
        // Eine ggf. injizierte SQLite-Attrappe wieder ausbauen, damit kein
        // anderer Test versehentlich auf ihr landet. In der Unit-Suite gibt es
        // keine echte Verbindung, das Zurücksetzen auf null ist daher sicher.
        $property = new \ReflectionProperty(Database::class, 'instance');
        $property->setValue(null, null);
        parent::tearDown();
    }

    /** Knoten im Format des Framework-PedigreeBuilder. */
    private static function node(int $id, ?array $sire = null, ?array $dam = null): array {
        return ['id' => $id, 'name' => "Pferd {$id}", 'sire' => $sire, 'dam' => $dam];
    }

    public function testVollgeschwisterAlsElternErgeben25Prozent(): void {
        $sire = self::node(3, self::node(1), self::node(2));
        $dam = self::node(4, self::node(1), self::node(2));

        $this->assertEqualsWithDelta(0.25, CoiEstimator::fromParentTrees($sire, $dam), 1e-12);
    }

    public function testHalbgeschwisterAlsElternErgeben125Prozent(): void {
        $sire = self::node(6, self::node(1), self::node(2));
        $dam = self::node(7, self::node(1), self::node(5));

        $this->assertEqualsWithDelta(0.125, CoiEstimator::fromParentTrees($sire, $dam), 1e-12);
    }

    /**
     * Wrights Pfadregel - der Fall, in dem die frühere Fassung 0,375 statt
     * 0,25 lieferte: Die gemeinsamen Vorfahren 1 und 2 haben eigene, paarweise
     * verschiedene Eltern, die NICHT zusätzlich als gemeinsame Vorfahren
     * zählen dürfen.
     */
    public function testAhnenGemeinsamerVorfahrenWerdenNichtDoppeltGezaehlt(): void {
        $grossvaeterlich = fn(): array => self::node(1, self::node(10), self::node(11));
        $grossmuetterlich = fn(): array => self::node(2, self::node(12), self::node(13));

        $sire = self::node(3, $grossvaeterlich(), $grossmuetterlich());
        $dam = self::node(4, $grossvaeterlich(), $grossmuetterlich());

        $this->assertEqualsWithDelta(0.25, CoiEstimator::fromParentTrees($sire, $dam), 1e-12);
    }

    public function testNichtVerwandteElternErgeben0(): void {
        $sire = self::node(10, self::node(12), self::node(13));
        $dam = self::node(11, self::node(14), self::node(15));

        $this->assertSame(0.0, CoiEstimator::fromParentTrees($sire, $dam));
    }

    /**
     * Der eigentliche Vertrag dieses PRs: Beide Rechenkerne liefern für
     * identische Bäume identische Werte. Läuft über mehrere Baumformen,
     * darunter die, an der sie früher auseinanderliefen.
     */
    public function testEstimatorUndCalculatorLiefernIdentischeWerte(): void {
        $faelle = [
            'Vollgeschwister' => [
                self::node(3, self::node(1), self::node(2)),
                self::node(4, self::node(1), self::node(2)),
            ],
            'Vollgeschwister mit bekannten Grosseltern' => [
                self::node(3, self::node(1, self::node(10), self::node(11)), self::node(2, self::node(12), self::node(13))),
                self::node(4, self::node(1, self::node(10), self::node(11)), self::node(2, self::node(12), self::node(13))),
            ],
            'Elter x Nachkomme' => [
                self::node(1),
                self::node(3, self::node(1), self::node(2)),
            ],
            'Mehrfache Pfade zum selben Vorfahren' => [
                self::node(20, self::node(1), self::node(21, self::node(1), self::node(26))),
                self::node(22, self::node(23, self::node(1), self::node(24)), self::node(25)),
            ],
            'Nicht verwandt' => [
                self::node(30, self::node(31), self::node(32)),
                self::node(33, self::node(34), self::node(35)),
            ],
        ];

        foreach ($faelle as $name => [$sire, $dam]) {
            $this->assertEqualsWithDelta(
                CoiCalculator::fromParentTrees($sire, $dam),
                CoiEstimator::fromParentTrees($sire, $dam),
                1e-12,
                "Estimator und Calculator weichen im Fall '{$name}' voneinander ab."
            );
        }
    }

    /**
     * Der Vertrag von Addons#69: Der kantenbasierte AncestorTreeBuilder
     * füttert den CoiEstimator fachlich identisch zum alten Weg
     * (PedigreeBuilder::build() je Kandidat). Beide laufen hier gegen
     * DENSELBEN konstruierten Bestand - der PedigreeBuilder unverändert über
     * die injizierte Datenbank, der AncestorTreeBuilder über seine eine
     * Kantenabfrage (EDGE_SQL) - und müssen für jede Basis/Kandidat-Paarung
     * über mehrere Tiefen strukturgleiche Bäume und identische COI-Werte
     * liefern.
     *
     * Der Bestand deckt die Auflösungsfälle des PedigreeBuilder ab:
     * FK-Eltern, gemeinsame Ahnen über mehrere Kandidaten, Freitext-Eltern
     * per Name (mit abweichender Groß-/Kleinschreibung), per UELN und per
     * Fremd-UELN, unauflösbarer Freitext (Platzhalter), gelöschte Eltern,
     * einen Zyklus in Altdaten sowie eine lange Ahnenkette, die erst jenseits
     * der Standardtiefe einen gemeinsamen Vorfahren erreicht (Tiefendeckel).
     */
    public function testKantenbasierteBaeumeLiefernIdentischeCoiWieDerPedigreeBuilder(): void {
        $pdo = self::fakeDatabase();
        self::injectDatabase($pdo);

        $graph = AncestorTreeBuilder::loadFromDatabase($pdo);

        $baseId = 20;
        $candidateIds = [21, 22, 23, 24, 25];
        $sawPositiveCoi = false;

        foreach ([3, 6, 8] as $depth) {
            $oldBase = PedigreeBuilder::build($baseId, $depth);
            $newBase = $graph->build($baseId, $depth);
            $this->assertSame(
                self::projectTree($oldBase),
                self::projectTree($newBase),
                "Basisbaum weicht bei Tiefe {$depth} vom PedigreeBuilder ab."
            );

            foreach ($candidateIds as $candidateId) {
                $oldTree = PedigreeBuilder::build($candidateId, $depth);
                $newTree = $graph->build($candidateId, $depth);
                $this->assertSame(
                    self::projectTree($oldTree),
                    self::projectTree($newTree),
                    "Kandidatenbaum {$candidateId} weicht bei Tiefe {$depth} vom PedigreeBuilder ab."
                );

                $oldCoi = CoiEstimator::fromParentTrees($oldBase, $oldTree);
                $newCoi = CoiEstimator::fromParentTrees($newBase, $newTree);
                $this->assertEqualsWithDelta(
                    $oldCoi,
                    $newCoi,
                    1e-12,
                    "COI für Kandidat {$candidateId} bei Tiefe {$depth} weicht vom alten Weg ab."
                );
                if ($oldCoi > 0.0) {
                    $sawPositiveCoi = true;
                }
            }
        }

        $this->assertTrue(
            $sawPositiveCoi,
            'Der konstruierte Bestand sollte verwandte Verpaarungen enthalten - sonst vergleicht der Test nur Nullen.'
        );
    }

    /**
     * Die Tiefensemantik "Generationen je Elternteil" am kantenbasierten Baum:
     * Basis 20 trägt den Ahnen 1 zwei Schritte entfernt, Kandidat 25 erreicht
     * ihn erst über eine sechsgliedrige Kette - bei Standardtiefe 6 liegt der
     * gemeinsame Vorfahre jenseits des Baums (COI 0), bei Tiefe 8 zählt er
     * mit n1=2, n2=6 als 0,5^9.
     */
    public function testTiefendeckelSchneidetFerneGemeinsameVorfahrenAb(): void {
        $pdo = self::fakeDatabase();
        self::injectDatabase($pdo);
        $graph = AncestorTreeBuilder::loadFromDatabase($pdo);

        $this->assertSame(
            0.0,
            CoiEstimator::fromParentTrees($graph->build(20, 6), $graph->build(25, 6))
        );
        $this->assertEqualsWithDelta(
            0.5 ** 9,
            CoiEstimator::fromParentTrees($graph->build(20, 8), $graph->build(25, 8)),
            1e-12
        );
    }

    /**
     * Kandidaten-Deckel (#69): das Fünffache der Anzeige-Anzahl, höchstens
     * CANDIDATE_CAP_MAX - VOR der Berechnung, nicht erst bei der Anzeige.
     */
    public function testKandidatenDeckel(): void {
        $this->assertSame(5, EmpfehlungController::candidateCap(1));
        $this->assertSame(100, EmpfehlungController::candidateCap(20));
        $this->assertSame(200, EmpfehlungController::candidateCap(40));
        $this->assertSame(200, EmpfehlungController::candidateCap(100));
    }

    /**
     * Injiziert die SQLite-Attrappe als App\Database-Singleton, damit der
     * unveränderte PedigreeBuilder des Kerns gegen den Testbestand läuft.
     */
    private static function injectDatabase(\PDO $pdo): void {
        $property = new \ReflectionProperty(Database::class, 'instance');
        $property->setValue(null, $pdo);
    }

    /**
     * Konstruierter Bestand als In-Memory-SQLite. Die Spalte name (und die
     * UELN-Spalten) sind NOCASE-kollationiert, damit der Namens-Lookup des
     * PedigreeBuilder wie in MariaDB (utf8mb4_unicode_ci) über die
     * Groß-/Kleinschreibung hinweg trifft.
     */
    private static function fakeDatabase(): \PDO {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('CREATE TABLE horses (
            id INTEGER PRIMARY KEY,
            name TEXT COLLATE NOCASE,
            ueln TEXT COLLATE NOCASE,
            foreign_ueln TEXT COLLATE NOCASE,
            birth_year INTEGER,
            color TEXT,
            sex TEXT,
            sire_id INTEGER,
            sire_name TEXT,
            sire_ueln TEXT,
            dam_id INTEGER,
            dam_name TEXT,
            dam_ueln TEXT,
            deleted_at TEXT,
            is_published INTEGER NOT NULL DEFAULT 1
        )');

        $insert = $pdo->prepare(
            'INSERT INTO horses
                (id, name, ueln, foreign_ueln, birth_year, sex,
                 sire_id, sire_name, sire_ueln, dam_id, dam_name, dam_ueln, deleted_at)
             VALUES
                (:id, :name, :ueln, :foreign_ueln, :birth_year, :sex,
                 :sire_id, :sire_name, :sire_ueln, :dam_id, :dam_name, :dam_ueln, :deleted_at)'
        );

        $defaults = [
            'ueln' => null, 'foreign_ueln' => null, 'birth_year' => null, 'sex' => null,
            'sire_id' => null, 'sire_name' => null, 'sire_ueln' => null,
            'dam_id' => null, 'dam_name' => null, 'dam_ueln' => null, 'deleted_at' => null,
        ];

        $herd = [
            // Ahnen-Generation
            ['id' => 1, 'name' => 'Old Rex', 'ueln' => 'DE001'],
            ['id' => 2, 'name' => 'Marena'],
            ['id' => 3, 'name' => 'Thunder'],                       // Ziel des Namens-Lookups (als "THUNDER" referenziert)
            ['id' => 4, 'name' => 'Foreign Star', 'foreign_ueln' => 'FR999'], // Ziel des Fremd-UELN-Lookups
            ['id' => 5, 'name' => 'Deleted Duke', 'deleted_at' => '2026-01-01 00:00:00'],
            ['id' => 6, 'name' => 'Loop A', 'sire_id' => 7],        // Zyklus in Altdaten
            ['id' => 7, 'name' => 'Loop B', 'sire_id' => 6],
            // Mittlere Generation
            ['id' => 10, 'name' => 'Alpha', 'sire_id' => 1, 'dam_id' => 2],
            ['id' => 11, 'name' => 'Beta', 'sire_id' => 1, 'dam_id' => 2], // Vollgeschwister von Alpha
            ['id' => 12, 'name' => 'Gamma', 'sire_id' => 5, 'dam_name' => 'THUNDER'], // gelöschter Vater, Namens-Fallback
            ['id' => 13, 'name' => 'Delta', 'sire_name' => 'Nirgendwo Bekannt', 'dam_ueln' => 'FR999'], // Platzhalter + UELN-Fallback
            ['id' => 14, 'name' => 'Epsilon', 'sire_id' => 10, 'dam_id' => 13],
            ['id' => 15, 'name' => 'Zeta', 'sire_id' => 6, 'dam_id' => 11],
            // Basis und Kandidaten
            ['id' => 20, 'name' => 'Basis-Stute', 'sex' => 'mare', 'sire_id' => 10, 'dam_id' => 12],
            ['id' => 21, 'name' => 'Kandidat Eins', 'sex' => 'stallion', 'sire_id' => 11, 'dam_id' => 13],
            ['id' => 22, 'name' => 'Kandidat Zwei', 'sex' => 'stallion', 'sire_id' => 14, 'dam_id' => 15],
            ['id' => 23, 'name' => 'Kandidat Drei', 'sire_id' => 1, 'dam_id' => 12],
            ['id' => 24, 'name' => 'Unverwandt', 'sex' => 'stallion'],
            ['id' => 25, 'name' => 'Kandidat Kette', 'sex' => 'stallion', 'sire_id' => 30],
            // Lange Ahnenkette bis zu Old Rex (erst bei Tiefe > 6 im Baum)
            ['id' => 30, 'name' => 'Kette 0', 'sire_id' => 31],
            ['id' => 31, 'name' => 'Kette 1', 'sire_id' => 32],
            ['id' => 32, 'name' => 'Kette 2', 'sire_id' => 33],
            ['id' => 33, 'name' => 'Kette 3', 'sire_id' => 34],
            ['id' => 34, 'name' => 'Kette 4', 'sire_id' => 1],
        ];

        foreach ($herd as $row) {
            $insert->execute($row + $defaults);
        }

        return $pdo;
    }

    /**
     * Projektion beider Baumformen auf die verglichenen Merkmale: Identität,
     * Platzhalter-Status, Tiefe, Name und die rekursive sire/dam-Struktur -
     * genau die Form, von der der CoiEstimator abhängt, plus Tiefensemantik.
     */
    private static function projectTree(?array $node): ?array {
        if ($node === null) {
            return null;
        }
        return [
            'id' => $node['id'] ?? null,
            'is_placeholder' => !empty($node['is_placeholder']),
            'depth' => $node['depth'] ?? null,
            'name' => $node['name'] ?? null,
            'sire' => self::projectTree($node['sire'] ?? null),
            'dam' => self::projectTree($node['dam'] ?? null),
        ];
    }
}
