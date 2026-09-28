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

    protected function setUp(): void {
        // Der PedigreeBuilder-Cache ist request-global - in diesem Prozess
        // also über Testfälle hinweg. Ohne Reset sähe ein Fall die Bäume und
        // Freitext-Treffer eines vorigen.
        if (class_exists(PedigreeBuilder::class)) {
            PedigreeBuilder::resetCache();
        }
    }

    protected function tearDown(): void {
        if (class_exists(PedigreeBuilder::class)) {
            PedigreeBuilder::resetCache();
        }
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
     * zählen hier NICHT zusätzlich als eigene gemeinsame Vorfahren - aber nur,
     * weil 10-13 auf BEIDEN Seiten ausschließlich durch 1 bzw. 2 hindurch
     * erreichbar sind: Jedes Pfadpaar zu ihnen enthielte 1 bzw. 2 doppelt.
     * Genau hier steckte ein Fehler, der 48,44 % statt 25,00 % lieferte (siehe
     * Klassenkommentar in plugins/inzuchtkoeffizient/Plugin.php); dieser Fall
     * hält die Korrektur fest. Ist ein Ahne auf einer Seite auch auf eigenem
     * Weg erreichbar, zählt er sehr wohl (siehe die Linienzucht-Fälle, M29).
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
    /**
     * Audit M29, Gegenbeispiel 1: beidseitige Linienzucht auf B (2) und
     * dessen Vater A (1). Vater 10 = B × 11, Mutter 20 = B × 21, und 21 hat
     * ebenfalls den Vater A.
     *
     *   B:  10-2 / 20-2                -> 0,5^3 = 0,125
     *   A:  10-2-1 / 20-21-1           -> 0,5^5 = 0,03125 (schneiden sich nur in A)
     *       10-2-1 / 20-2-1            -> zählt NICHT (B doppelt)
     *
     * Richtig 0,15625. Bis Revision 2 endete der Pfad 10-2 an B, der zweite
     * Term fehlte: 0,125. Ohne die Schnittmengenprüfung am Paar käme
     * 0,1875 heraus - derselbe Fall deckt also auch die Paar-Bedingung ab.
     */
    public function testLinienzuchtUeberVaterDesGemeinsamenVorfahren(): void {
        $b = fn(): array => self::node(2, self::node(1));
        $sire = self::node(10, $b(), self::node(11));
        $dam = self::node(20, $b(), self::node(21, self::node(1)));

        $this->assertEqualsWithDelta(0.15625, CoiCalculator::fromParentTrees($sire, $dam), 1e-12);
    }

    /**
     * Audit M29, Gegenbeispiel 2: A (1) ist auf der Vaterseite nur hinter
     * einem anderen gemeinsamen Vorfahren B (2, Sohn von A) erreichbar, auf
     * der Mutterseite aber direkt. Vater 10 hat den Vater 4 (Sohn von B),
     * Mutter 20 = A × 6, 6 ist Tochter von B.
     *
     *   B:  10-4-2 / 20-6-2            -> 0,5^5 = 0,03125
     *   A:  10-4-2-1 / 20-1            -> 0,5^5 = 0,03125
     *
     * Richtig 0,0625 (Warnschwelle der Anpaarungs-Empfehlung), bisher 0,03125.
     */
    public function testTieferGemeinsamerVorfahreNurHinterAnderemGemeinsamemVorfahren(): void {
        $b = fn(): array => self::node(2, self::node(1));
        $sire = self::node(10, self::node(4, $b()));
        $dam = self::node(20, self::node(1), self::node(6, $b()));

        $this->assertEqualsWithDelta(0.0625, CoiCalculator::fromParentTrees($sire, $dam), 1e-12);
    }

    /**
     * Elter mit eigenen Ahnen × eigener Nachkomme: Vater 5 (= 1 × 2) mit
     * seiner Tochter 6 (= 5 × 3). Der einzige gültige Beitrag ist 5 selbst
     * (0,5^2). Die Ahnen 1 und 2 sind von der Mutterseite nur durch 5 hindurch
     * erreichbar - ohne Pfadregel kämen sie hinzu.
     */
    public function testElterMitEigenenAhnenUndNachkommeBleibt25Prozent(): void {
        $sire = self::node(5, self::node(1), self::node(2));
        $dam = self::node(6, self::node(5, self::node(1), self::node(2)), self::node(3));

        $this->assertEqualsWithDelta(0.25, CoiCalculator::fromParentTrees($sire, $dam), 1e-12);
    }

    /**
     * Defensiver Zyklusschutz: Ein fremd gebauter Baum, in dem Pferd 3 sich
     * über 1 selbst als Vorfahre enthält (3 -> 1 -> 3 -> 1). PedigreeBuilder
     * verhindert das (#131), eine andere Baumquelle vielleicht nicht. Die
     * Rechnung terminiert und zählt die Wiederholung nicht: 1 und 2 als
     * gemeinsame Vorfahren wie bei Vollgeschwistern, 0,25 - ohne Schutz käme
     * über den Umweg ein zweiter Pfad zu 1 hinzu (0,375).
     */
    public function testZyklusImFremdbaumBrichtAb(): void {
        $sire = self::node(3, self::node(1, self::node(3, self::node(1))), self::node(2));
        $dam = self::node(4, self::node(1), self::node(2));

        $this->assertEqualsWithDelta(0.25, CoiCalculator::fromParentTrees($sire, $dam), 1e-12);
    }

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

    private function insertHorse(
        PDO $db,
        string $name,
        ?int $sireId = null,
        ?int $damId = null,
        ?string $ueln = null,
        ?string $sire_name = null,
        ?string $sire_ueln = null,
        ?string $dam_name = null,
        ?string $dam_ueln = null,
        int $is_published = 1,
    ): int {
        $stmt = $db->prepare(
            'INSERT INTO horses (name, sire_id, dam_id, ueln, sire_name, sire_ueln, dam_name, dam_ueln, is_published)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $sireId, $damId, $ueln, $sire_name, $sire_ueln, $dam_name, $dam_ueln, $is_published]);
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

    // ------------------------------------------------------------------
    // Detailseite mit per UELN/Name verknüpften Eltern (Audit M28)
    // ------------------------------------------------------------------

    /**
     * Großeltern G1 × G2, deren Kinder S und D Vollgeschwister per FK sind.
     * S trägt eine eindeutige UELN.
     *
     * @return array{s: int, d: int, s_ueln: string, s_name: string, d_name: string}
     */
    private function vollgeschwister(PDO $db, bool $sVeroeffentlicht = true): array {
        $u = uniqid('coi28-', true);
        $g1 = $this->insertHorse($db, "G1-{$u}");
        $g2 = $this->insertHorse($db, "G2-{$u}");
        $sUeln = 'DE' . substr(md5($u), 0, 13);
        $s = $this->insertHorse($db, "S-{$u}", $g1, $g2, ueln: $sUeln, is_published: $sVeroeffentlicht ? 1 : 0);
        $d = $this->insertHorse($db, "D-{$u}", $g1, $g2);

        return ['s' => $s, 'd' => $d, 's_ueln' => $sUeln, 's_name' => "S-{$u}", 'd_name' => "D-{$u}"];
    }

    /** @return array<string, mixed> */
    private function zeile(PDO $db, int $id): array {
        $stmt = $db->prepare('SELECT * FROM horses WHERE id = ?');
        $stmt->execute([$id]);
        $zeile = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($zeile);
        return $zeile;
    }

    /**
     * Der Vater ist nur per Lebensnummer eingetragen (CSV-Import, Formular-
     * Freitext), die Mutter per FK. Der Stammbaum des Kerns löst den Vater
     * auf - der COI-Abschnitt nahm bisher nur sire_id und zeigte 0,00 %.
     */
    public function testAddDetailSectionNutztPerLebensnummerVerknuepftenVater(): void {
        $db = $this->db();
        $e = $this->vollgeschwister($db);
        $fohlen = $this->insertHorse($db, 'F-' . uniqid('coi28-', true), null, $e['d'], sire_ueln: $e['s_ueln']);

        $pedigree = PedigreeBuilder::build($fohlen, 6, true);
        $sections = (new Plugin())->addDetailSection([], $this->zeile($db, $fohlen), [], $pedigree);

        $this->assertCount(1, $sections);
        $this->assertStringContainsString('25,00 %', $sections[0]);
    }

    /** Beide Eltern nur per Name: Der Abschnitt fehlte bisher ganz. */
    public function testAddDetailSectionBeideElternNurPerFreitext(): void {
        $db = $this->db();
        $e = $this->vollgeschwister($db);
        $fohlen = $this->insertHorse(
            $db,
            'F-' . uniqid('coi28-', true),
            sire_name: $e['s_name'],
            dam_name: $e['d_name'],
        );

        $pedigree = PedigreeBuilder::build($fohlen, 6, true);
        $sections = (new Plugin())->addDetailSection([], $this->zeile($db, $fohlen), [], $pedigree);

        $this->assertCount(1, $sections, 'Mit zwei auflösbaren Freitext-Eltern gehört der Abschnitt auf die Seite.');
        $this->assertStringContainsString('25,00 %', $sections[0]);
    }

    /**
     * Ein unveröffentlichter Vater, nur per UELN eingetragen: Der Kern macht
     * daraus einen Platzhalter, und der COI darf ihn nicht doch noch über
     * einen eigenen Weg einbeziehen (kein Leck).
     */
    public function testUnveroeffentlichterFreitextElternteilFliesstNichtEin(): void {
        $db = $this->db();
        $e = $this->vollgeschwister($db, false);
        $fohlen = $this->insertHorse($db, 'F-' . uniqid('coi28-', true), null, $e['d'], sire_ueln: $e['s_ueln']);

        $pedigree = PedigreeBuilder::build($fohlen, 6, true);
        $this->assertTrue($pedigree['sire']['is_placeholder'] ?? false,
            'Voraussetzung: Der Kern zeigt den unveröffentlichten Vater nur als Platzhalter.');

        $sections = (new Plugin())->addDetailSection([], $this->zeile($db, $fohlen), [], $pedigree);

        $this->assertCount(1, $sections);
        $this->assertStringNotContainsString('25,00 %', $sections[0]);
        $this->assertStringContainsString('0,00 %', $sections[0], 'Nur der Mutterbaum zählt.');
    }
}
