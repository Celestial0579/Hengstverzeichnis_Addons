<?php
// tests/Unit/DatenmigrationDumpAusfuehrerTest.php

namespace Tests\Unit;

use App\Service\Maintenance;
use PHPUnit\Framework\TestCase;
use Plugin\Datenmigration\Anweisung;
use Plugin\Datenmigration\DumpAusfuehrer;
use Plugin\Datenmigration\Wartung;

require_once __DIR__ . '/../../plugins/datenmigration/Plugin.php';

/**
 * Die Ausführung eines geprüften Dumps (Audit N21).
 *
 * Bis 1.1.0 ging der gesamte Dump als EIN Paket an den Server und scheiterte
 * ab 16 MiB an max_allowed_packet - die Meldung behauptete danach trotzdem,
 * der Sicherungsstand sei zurückgespielt. Jetzt geht jede Anweisung einzeln,
 * INSERTs derselben Tabelle gesammelt bis zu einer Grenze. Geprüft wird das
 * mit einem Spion statt einer Datenbank: Was kommt in welcher Größe an?
 *
 * Dazu die Entscheidung nach einem gescheiterten Import: Wartungsmodus
 * aufheben oder - wenn auch der Rückweg scheiterte - dauerhaft stehen lassen.
 */
class DatenmigrationDumpAusfuehrerTest extends TestCase {

    protected function tearDown(): void {
        Maintenance::disable();
    }

    private static function insert(string $tabelle, string $werte, string $spalten = '`id`, `name`'): Anweisung {
        $kopf = 'INSERT INTO `' . $tabelle . '` (' . $spalten . ') VALUES ';
        return new Anweisung(0, 'insert', $kopf . $werte, $tabelle, $kopf, $werte);
    }

    /** @return array{0: \Closure, 1: \ArrayObject<int, string>} */
    private static function spion(): array {
        $gesendet = new \ArrayObject();
        return [static function (string $sql) use ($gesendet): void {
            $gesendet[] = $sql;
        }, $gesendet];
    }

    public function testSammeltNurGleicheTabelleUndSpaltenliste(): void {
        [$senden, $gesendet] = self::spion();
        $anzahl = (new DumpAusfuehrer($senden, 10000))->ausfuehren([
            new Anweisung(1, 'set', 'SET NAMES utf8mb4'),
            new Anweisung(2, 'drop', 'DROP TABLE IF EXISTS `horses`', 'horses'),
            new Anweisung(3, 'create', 'CREATE TABLE `horses` (`id` int)', 'horses'),
            self::insert('horses', "('1', 'a')"),
            self::insert('horses', "('2', 'b')"),
            self::insert('horses', "('3')", '`id`'),
            self::insert('contacts', "('4', 'c')"),
            new Anweisung(8, 'set', 'SET FOREIGN_KEY_CHECKS=1'),
        ]);

        $this->assertSame(8, $anzahl);
        $this->assertSame([
            'SET NAMES utf8mb4',
            'DROP TABLE IF EXISTS `horses`',
            'CREATE TABLE `horses` (`id` int)',
            "INSERT INTO `horses` (`id`, `name`) VALUES ('1', 'a'), ('2', 'b')",
            "INSERT INTO `horses` (`id`) VALUES ('3')",
            "INSERT INTO `contacts` (`id`, `name`) VALUES ('4', 'c')",
            'SET FOREIGN_KEY_CHECKS=1',
        ], $gesendet->getArrayCopy());
    }

    /** DROP, CREATE und SET beenden eine Sammlung - auch zwischen zwei INSERTs derselben Tabelle. */
    public function testUmbruchBeiAnderenAnweisungen(): void {
        [$senden, $gesendet] = self::spion();
        (new DumpAusfuehrer($senden, 10000))->ausfuehren([
            self::insert('horses', "('1', 'a')"),
            new Anweisung(2, 'set', 'SET FOREIGN_KEY_CHECKS=0'),
            self::insert('horses', "('2', 'b')"),
        ]);
        $this->assertCount(3, $gesendet);
    }

    /**
     * Keine Sendung größer als die Grenze - außer eine einzelne Anweisung
     * ist schon allein größer; die geht dann für sich.
     */
    public function testGrenzeWirdEingehalten(): void {
        [$senden, $gesendet] = self::spion();
        $grenze = 200;
        $anweisungen = [];
        for ($i = 0; $i < 40; $i++) {
            $anweisungen[] = self::insert('horses', "('{$i}', '" . str_repeat('x', 20) . "')");
        }
        $riese = self::insert('horses', "('99', '" . str_repeat('y', 500) . "')");
        $anweisungen[] = $riese;
        $anweisungen[] = self::insert('horses', "('100', 'z')");

        (new DumpAusfuehrer($senden, $grenze))->ausfuehren($anweisungen);

        $this->assertGreaterThan(5, count($gesendet));
        $zeilen = 0;
        foreach ($gesendet as $sql) {
            if ($sql === $riese->sql) {
                continue;
            }
            $this->assertLessThanOrEqual($grenze, strlen($sql), $sql);
            $zeilen += substr_count($sql, "('");
        }
        $this->assertContains($riese->sql, $gesendet->getArrayCopy());
        $this->assertSame(41, $zeilen, 'Keine Zeile darf verloren gehen oder doppelt gesendet werden.');
    }

    /** Ein Fehler geht durch, danach wird nichts mehr gesendet. */
    public function testFehlerWirdDurchgereichtUndBeendetDieSendung(): void {
        $gesendet = [];
        $senden = static function (string $sql) use (&$gesendet): void {
            $gesendet[] = $sql;
            if (str_starts_with($sql, 'CREATE')) {
                throw new \RuntimeException('Duplicate entry');
            }
        };
        try {
            (new DumpAusfuehrer($senden, 10000))->ausfuehren([
                new Anweisung(1, 'drop', 'DROP TABLE IF EXISTS `horses`', 'horses'),
                new Anweisung(2, 'create', 'CREATE TABLE `horses` (`id` int)', 'horses'),
                self::insert('horses', "('1', 'a')"),
                new Anweisung(4, 'set', 'SET FOREIGN_KEY_CHECKS=1'),
            ]);
            $this->fail('Der Fehler wurde verschluckt.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Duplicate entry', $e->getMessage());
        }
        $this->assertCount(2, $gesendet);
    }

    /**
     * Rückweg gescheitert: Der Marker wird OHNE pid neu geschrieben und
     * bleibt stehen - so gilt er für den Kern als von Hand gesetzt und
     * verfällt nie (Maintenance::isStale()). Eine halb ersetzte Datenbank
     * darf nicht von selbst wieder online gehen.
     */
    public function testGescheiterterRueckwegLaesstDauerhaftenMarkerStehen(): void {
        Maintenance::enable('Datenmigrations-Import läuft');
        $this->assertNotNull(Maintenance::info()['pid'] ?? null, 'Vorbedingung: Marker mit pid');

        Wartung::nachFehlschlag(false, 'Import und Rückweg gescheitert');

        $this->assertTrue(Maintenance::isActive(), 'Der Wartungsmodus wurde aufgehoben.');
        $info = Maintenance::info();
        $this->assertNotNull($info);
        $this->assertNull($info['pid'], 'Ein Marker mit pid verfällt nach dem Prozessende.');
        $this->assertSame('Import und Rückweg gescheitert', $info['grund']);
        $this->assertFalse(Maintenance::isStale());
    }

    public function testGelungenerRueckwegHebtDenWartungsmodusAuf(): void {
        Maintenance::enable('Datenmigrations-Import läuft');
        Wartung::nachFehlschlag(true, 'egal');
        $this->assertFalse(Maintenance::isActive());
    }
}
