<?php
// tests/Unit/InzuchtkoeffizientCoiTest.php

namespace Tests\Unit;

use App\Database;
use App\Service\PedigreeBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use Plugin\Inzuchtkoeffizient\CoiCalculator;
use Plugin\Inzuchtkoeffizient\Plugin;

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
 *
 * Zweite Schicht seit #72 (Detailseite rechnete eine Generation flacher als
 * angegeben): Die handgebauten Baum-Arrays oben prüfen die FORMEL, aber nie die
 * TIEFENSEMANTIK echter `PedigreeBuilder::build()`-Bäume - genau dort steckte
 * der Fehler, denn build() zählt die Wurzel als Generation 1. Die
 * "...AufEchtemPedigreeBuilderBaum"-Fälle unten bauen deshalb den Baum aus der
 * echten vendorierten Framework-Klasse gegen die Testdatenbank auf
 * (integrationsartig, aber ohne HTTP). Ohne konfigurierte Datenbank (DB_HOST,
 * siehe tests/bootstrap.php; lokal z. B. DB_HOST=127.0.0.1 DB_PORT=13306
 * DB_USER=root DB_PASS=hv-test-root DB_NAME=hengst_addons_functional) werden
 * sie übersprungen - so bleibt die Unit-Suite in der CI weiterhin ohne
 * Datenbank lauffähig.
 */
class InzuchtkoeffizientCoiTest extends TestCase {

    /** @var list<int> In DB-gestützten Fällen angelegte Pferde-IDs (Aufräumliste). */
    private array $createdHorseIds = [];

    public static function setUpBeforeClass(): void {
        // Plugins liegen nicht im Composer-Autoloader (sie werden zur Laufzeit
        // vom PluginManager des Kerns geladen), deshalb hier ausdrücklich.
        require_once __DIR__ . '/../../plugins/inzuchtkoeffizient/Plugin.php';
    }

    protected function tearDown(): void {
        if ($this->createdHorseIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->createdHorseIds), '?'));
            // FKs stehen auf ON DELETE SET NULL - Löschreihenfolge ist egal,
            // und es verschwinden ausschließlich die selbst angelegten Zeilen.
            Database::getInstance()
                ->prepare("DELETE FROM horses WHERE id IN ({$placeholders})")
                ->execute($this->createdHorseIds);
            $this->createdHorseIds = [];
        }
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

    // ------------------------------------------------------------------
    // Integrationsartige Fälle gegen echte PedigreeBuilder-Bäume (#72)
    // ------------------------------------------------------------------

    /**
     * Verbindet zur Testdatenbank und stellt die horses-Tabelle sicher; ohne
     * DB-Konfiguration wird der Fall übersprungen (siehe Klassenkommentar).
     */
    private function db(): PDO {
        if (!defined('DB_HOST')) {
            $this->markTestSkipped(
                'PedigreeBuilder-Integrationsfall benötigt die Testdatenbank - DB_HOST & Co. setzen (siehe tests/bootstrap.php).'
            );
        }
        $pdo = Database::getInstance();

        // Frische Datenbank (z. B. lokaler Testcontainer vor dem ersten
        // Functional-Lauf): Schema wie SetupController::store() einspielen.
        if ($pdo->query("SHOW TABLES LIKE 'horses'")->fetchColumn() === false) {
            $schemaFile = \FRAMEWORK_VENDOR_DIR . '/database/schema.sql';
            if (is_file($schemaFile)) {
                try {
                    $pdo->exec((string) file_get_contents($schemaFile));
                } catch (\PDOException) {
                    // Prüfung unten entscheidet, ob es gereicht hat.
                }
            }
            if ($pdo->query("SHOW TABLES LIKE 'horses'")->fetchColumn() === false) {
                // Umgebungsfehler, kein Testergebnis: nicht geprüft.
                $this->markTestSkipped('horses-Tabelle fehlt und database/schema.sql ließ sich nicht einspielen.');
            }
        }

        return $pdo;
    }

    private function insertHorse(PDO $db, string $name, ?int $sireId = null, ?int $damId = null): int {
        $stmt = $db->prepare(
            'INSERT INTO horses (name, sire_id, dam_id, is_published) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$name, $sireId, $damId]);
        $id = (int) $db->lastInsertId();
        $this->createdHorseIds[] = $id;
        return $id;
    }

    /**
     * Baut das Szenario aus Issue #72 in der Datenbank auf: gemeinsamer Ahne
     * "Rex" 5 Schritte vom Hengst (H -> V1 -> V2 -> V3 -> V4 -> Rex) und
     * 2 Schritte von der Stute (S -> M -> Rex) entfernt.
     *
     * @return array{hengst: int, stute: int, fohlen: int, rex: int}
     */
    private function createIssue72Pedigree(PDO $db): array {
        $u = uniqid('coi72-', true);

        $rex = $this->insertHorse($db, "Rex-{$u}");
        $v4 = $this->insertHorse($db, "V4-{$u}", $rex);
        $v3 = $this->insertHorse($db, "V3-{$u}", $v4);
        $v2 = $this->insertHorse($db, "V2-{$u}", $v3);
        $v1 = $this->insertHorse($db, "V1-{$u}", $v2);
        $hengst = $this->insertHorse($db, "H-{$u}", $v1);

        $mutter = $this->insertHorse($db, "M-{$u}", $rex);
        $stute = $this->insertHorse($db, "S-{$u}", null, $mutter);

        $fohlen = $this->insertHorse($db, "F-{$u}", $hengst, $stute);

        return ['hengst' => $hengst, 'stute' => $stute, 'fohlen' => $fohlen, 'rex' => $rex];
    }

    /**
     * Tiefensemantik der Wurzel (#72): build() zählt die WURZEL als Generation 1.
     * Nur mit dem ELTERNTEIL als Wurzel erreicht ein Baum der Tiefe 6 auch die
     * sechste Ahnengeneration (Schrittzahlen 0..5) - Rex trägt dann
     * 0,5^(5+2+1) = 0,390625 % bei. Die Teilbäume ['sire']/['dam'] eines mit
     * dem FOHLEN als Wurzel gebauten Baums derselben Tiefe reichen dagegen nur
     * bis Schrittzahl 4: Rex fehlt, der Beitrag verschwindet - exakt der
     * frühere Fehler des Detailseiten-Abschnitts.
     */
    public function testSechsteGenerationZaehltNurMitElternteilAlsWurzelAufEchtemPedigreeBuilderBaum(): void {
        $db = $this->db();
        $ids = $this->createIssue72Pedigree($db);

        $sireTree = PedigreeBuilder::build($ids['hengst'], Plugin::DETAIL_PARENT_DEPTH, true);
        $damTree = PedigreeBuilder::build($ids['stute'], Plugin::DETAIL_PARENT_DEPTH, true);

        // Wurzelsemantik ausdrücklich festhalten: das Elternteil selbst liegt
        // auf depth 1, Rex als fünffacher Ur-Ahne der Vaterlinie auf depth 6.
        $this->assertSame($ids['hengst'], (int) $sireTree['id']);
        $this->assertSame(1, $sireTree['depth']);
        $rexNode = $sireTree['sire']['sire']['sire']['sire']['sire'] ?? null;
        $this->assertNotNull($rexNode, 'Rex (6. Generation der Vaterlinie) muss im Eltern-Baum der Tiefe 6 enthalten sein.');
        $this->assertSame($ids['rex'], (int) $rexNode['id']);
        $this->assertSame(6, $rexNode['depth']);

        $this->assertEqualsWithDelta(
            0.5 ** 8,
            CoiCalculator::fromParentTrees($sireTree, $damTree),
            1e-12,
            'Gemeinsamer Ahne in der 6. Generation (n1=5, n2=2) muss mit 0,5^8 beitragen.'
        );

        // Gegenprobe - der Fehler aus #72: Teilbäume des Fohlen-Baums gleicher
        // Tiefe sind je Elternteil eine Generation flacher, Rex fehlt in der
        // Vaterlinie und der COI fällt fälschlich auf 0.
        $foalTree = PedigreeBuilder::build($ids['fohlen'], Plugin::DETAIL_PARENT_DEPTH, true);
        $this->assertSame(2, $foalTree['sire']['depth'], 'Im Fohlen-Baum beginnt die Vaterlinie erst auf depth 2.');
        $this->assertSame(
            0.0,
            CoiCalculator::fromParentTrees($foalTree['sire'] ?? null, $foalTree['dam'] ?? null),
            'Dokumentiert die Fehlerursache: die Fohlen-Teilbäume erreichen die 6. Ahnengeneration nicht.'
        );
    }

    /**
     * Derselbe Fall durch den Detailseiten-Abschnitt des Plugins (ohne HTTP):
     * addDetailSection() muss je Elternteil einen eigenen Baum mit dem
     * Elternteil als Wurzel bauen und damit 0,39 % ausweisen - und der
     * Beschreibungstext muss die Tiefe "je Elternteil" nennen.
     */
    public function testAddDetailSectionRechnetSechsGenerationenJeElternteil(): void {
        $db = $this->db();
        $ids = $this->createIssue72Pedigree($db);

        $stmt = $db->prepare('SELECT * FROM horses WHERE id = ?');
        $stmt->execute([$ids['fohlen']]);
        $horseRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($horseRow);

        $sections = (new Plugin())->addDetailSection([], $horseRow, [], null);

        $this->assertCount(1, $sections);
        $this->assertStringContainsString('0,39 %', $sections[0]);
        $this->assertStringContainsString(
            Plugin::DETAIL_PARENT_DEPTH . ' Generationen je Elternteil',
            $sections[0]
        );
    }
}
