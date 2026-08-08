<?php
// tests/Unit/AnpaarungsEmpfehlungCoiTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\AnpaarungsEmpfehlung\CoiEstimator;
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
 */
class AnpaarungsEmpfehlungCoiTest extends TestCase {

    public static function setUpBeforeClass(): void {
        // Plugins liegen nicht im Composer-Autoloader (sie werden zur Laufzeit
        // vom PluginManager des Kerns geladen), deshalb hier ausdrücklich.
        require_once __DIR__ . '/../../plugins/anpaarungs-empfehlung/Plugin.php';
        require_once __DIR__ . '/../../plugins/inzuchtkoeffizient/Plugin.php';
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
}
