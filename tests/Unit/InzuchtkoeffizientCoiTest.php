<?php
// tests/Unit/InzuchtkoeffizientCoiTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\Inzuchtkoeffizient\CoiCalculator;

/**
 * Rechenkern des Plugins "inzuchtkoeffizient": Wright'scher Inzuchtkoeffizient
 * aus den beiden Eltern-Teilbäumen.
 *
 * Warum ein eigener Unit-Test neben tests/Functional/InzuchtkoeffizientPluginTest.php:
 * Der Functional-Test fährt einen echten HTTP-Durchlauf gegen eine laufende
 * Framework-Instanz und prüft dabei genau zwei Werte (25,00 % und 0,00 %). Der
 * Aufwand, dort weitere Verwandtschaftsgrade abzubilden, steht in keinem
 * Verhältnis - für jeden zusätzlichen Fall müssten Pferde über HTTP angelegt und
 * verknüpft werden. `CoiCalculator::fromParentTrees()` ist dagegen eine reine
 * Funktion auf einfachen Arrays: keine Datenbank, keine Instanz, Millisekunden
 * statt Sekunden. Genau die lehrbuchbekannten Verwandtschaftsfälle, an denen ein
 * Fehler in der Pfadformel zuerst auffällt, lassen sich so vollständig abdecken.
 *
 * Die Fälle stammen aus einem verworfenen frühen Entwurf des Plugins
 * (Branch claude/inbreeding-coefficient-plugin, 05.08.2026), dessen eigene
 * Implementierung durch die heutige ersetzt wurde. Die Erwartungswerte sind
 * implementierungsunabhängig und gelten unverändert weiter.
 *
 * Zur Bedeutung: `fromParentTrees($sire, $dam)` liefert die Verwandtschaft
 * (Kinship) der beiden ELTERN - und die ist per Definition der COI ihres
 * Nachkommen. "Vollgeschwister als Eltern -> 0,25" heißt also: Das Fohlen zweier
 * Vollgeschwister hat einen COI von 25 %.
 */
class InzuchtkoeffizientCoiTest extends TestCase {

    public static function setUpBeforeClass(): void {
        // Plugins liegen nicht im Composer-Autoloader (sie werden zur Laufzeit
        // vom PluginManager des Kerns geladen), deshalb hier ausdrücklich.
        require_once __DIR__ . '/../../plugins/inzuchtkoeffizient/Plugin.php';
    }

    /** Knoten im Format des Framework-PedigreeBuilder (siehe Hook-Parameter $pedigree). */
    private static function node(int $id, ?array $sire = null, ?array $dam = null): array {
        return ['id' => $id, 'name' => "Pferd {$id}", 'sire' => $sire, 'dam' => $dam];
    }

    /** Unveröffentlichter/unbekannter Vorfahre - trägt nichts zur Rechnung bei. */
    private static function placeholder(int $id): array {
        return ['id' => $id, 'name' => null, 'is_placeholder' => true, 'sire' => null, 'dam' => null];
    }

    public function testVollgeschwisterAlsElternErgeben25Prozent(): void {
        // Beide Eltern (3, 4) haben denselben Vater 1 und dieselbe Mutter 2.
        // Zwei gemeinsame Vorfahren, je ein Pfad mit n1 = n2 = 1:
        // 2 * 0,5^(1+1+1) = 0,25.
        $sire = self::node(3, self::node(1), self::node(2));
        $dam = self::node(4, self::node(1), self::node(2));

        $this->assertEqualsWithDelta(0.25, CoiCalculator::fromParentTrees($sire, $dam), 1e-12);
    }

    public function testHalbgeschwisterAlsElternErgeben125Prozent(): void {
        // Nur der Vater (1) ist gemeinsam: 0,5^(1+1+1) = 0,125.
        $sire = self::node(6, self::node(1), self::node(2));
        $dam = self::node(7, self::node(1), self::node(5));

        $this->assertEqualsWithDelta(0.125, CoiCalculator::fromParentTrees($sire, $dam), 1e-12);
    }

    public function testElterMitEigenemNachkommenErgibt25Prozent(): void {
        // Vater 1 wird mit seiner eigenen Tochter 3 verpaart. Der gemeinsame
        // Vorfahre ist 1 selbst: n1 = 0 (er IST der Elternteil), n2 = 1.
        // 0,5^(0+1+1) = 0,25.
        $sire = self::node(1);
        $dam = self::node(3, self::node(1), self::node(2));

        $this->assertEqualsWithDelta(0.25, CoiCalculator::fromParentTrees($sire, $dam), 1e-12);
    }

    public function testGrosselterMitEnkelinErgibt125Prozent(): void {
        // Großvater 1 mit Enkelin 5 (Tochter von 3, und 3 ist Kind von 1 und 2):
        // n1 = 0, n2 = 2 -> 0,5^(0+2+1) = 0,125.
        $sire = self::node(1);
        $dam = self::node(5, self::node(3, self::node(1), self::node(2)), self::node(4));

        $this->assertEqualsWithDelta(0.125, CoiCalculator::fromParentTrees($sire, $dam), 1e-12);
    }

    public function testNichtVerwandteElternErgeben0(): void {
        $sire = self::node(10, self::node(12), self::node(13));
        $dam = self::node(11, self::node(14), self::node(15));

        $this->assertSame(0.0, CoiCalculator::fromParentTrees($sire, $dam));
    }

    /**
     * Wrights Pfadregel: Die Ahnen eines bereits gezählten gemeinsamen Vorfahren
     * dürfen NICHT zusätzlich als eigene gemeinsame Vorfahren zählen - jeder Pfad
     * zu ihnen enthielte den schon gezählten Vorfahren erneut. Genau hier steckte
     * ein Fehler, der 48,44 % statt 25,00 % lieferte (siehe Klassenkommentar in
     * plugins/inzuchtkoeffizient/Plugin.php); dieser Fall hält die Korrektur fest.
     */
    public function testAhnenGemeinsamerVorfahrenWerdenNichtDoppeltGezaehlt(): void {
        // Wie der Vollgeschwister-Fall, aber die gemeinsamen Vorfahren 1 und 2
        // haben ihrerseits bekannte, paarweise verschiedene Eltern.
        $grossvaeterlich = fn(): array => self::node(1, self::node(10), self::node(11));
        $grossmuetterlich = fn(): array => self::node(2, self::node(12), self::node(13));

        $sire = self::node(3, $grossvaeterlich(), $grossmuetterlich());
        $dam = self::node(4, $grossvaeterlich(), $grossmuetterlich());

        // Weiterhin 0,25 - nicht 0,375, was herauskäme, wenn 10-13 zusätzlich
        // als gemeinsame Vorfahren mitsummiert würden.
        $this->assertEqualsWithDelta(0.25, CoiCalculator::fromParentTrees($sire, $dam), 1e-12);
    }

    /**
     * Ein Vorfahre kann über MEHRERE Abstammungspfade erreichbar sein. Im
     * Pfad-Koeffizienten-Verfahren zählt dann jede Kombination aus einem Pfad der
     * Vater- und einem der Mutterseite einzeln - deshalb sammelt der Rechenkern je
     * Vorfahre eine Liste von Schrittzahlen und nicht nur eine.
     */
    public function testMehrfachePfadeZumSelbenVorfahrenSummierenSichAuf(): void {
        // Vorfahre 1 steht auf der Vaterseite zweimal: als Vater von 20 (n1 = 1)
        // und als Vater von dessen Mutter 21 (n1 = 2). Auf der Mutterseite einmal,
        // als Vater von 23 (n2 = 2).
        // 0,5^(1+2+1) + 0,5^(2+2+1) = 0,0625 + 0,03125 = 0,09375.
        $sire = self::node(20, self::node(1), self::node(21, self::node(1), self::node(26)));
        $dam = self::node(22, self::node(23, self::node(1), self::node(24)), self::node(25));

        $this->assertEqualsWithDelta(0.09375, CoiCalculator::fromParentTrees($sire, $dam), 1e-12);
    }

    /**
     * Platzhalter stehen für unveröffentlichte oder unbekannte Vorfahren. Sie
     * tragen keine Identität und dürfen deshalb nie als gemeinsamer Vorfahre
     * gelten - sonst entstünde aus zwei "Unbekannt"-Knoten eine Verwandtschaft,
     * und der öffentliche Stammbaum ließe Rückschlüsse auf ausgeblendete Pferde zu.
     */
    public function testPlatzhalterZaehlenNichtAlsGemeinsamerVorfahre(): void {
        $sire = self::node(30, self::placeholder(99), self::node(31));
        $dam = self::node(32, self::placeholder(99), self::node(33));

        $this->assertSame(0.0, CoiCalculator::fromParentTrees($sire, $dam));
    }

    public function testFehlendeElternbaeumeErgeben0(): void {
        $this->assertSame(0.0, CoiCalculator::fromParentTrees(null, null));
        $this->assertSame(0.0, CoiCalculator::fromParentTrees(self::node(1), null));
        $this->assertSame(0.0, CoiCalculator::fromParentTrees(null, self::node(1)));
    }
}
