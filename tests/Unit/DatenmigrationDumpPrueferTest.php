<?php
// tests/Unit/DatenmigrationDumpPrueferTest.php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\Datenmigration\Anweisung;
use Plugin\Datenmigration\DumpAbgelehnt;
use Plugin\Datenmigration\DumpPruefer;
use Plugin\Datenmigration\Exportauswahl;
use Plugin\Datenmigration\Importregel;

require_once __DIR__ . '/../../plugins/datenmigration/Plugin.php';

/**
 * Die Prüfung von database.sql vor jedem Import (Audit M1).
 *
 * Bis 1.1.0 lief der Dump eines Import-Archivs ungeprüft als ein einziges
 * Multi-Statement durch PDO::exec(); ob ein Archiv ein Teilarchiv ist,
 * entnahm der Import dem Manifest. Ein "Teilarchiv Pferde" mit einem
 * zusätzlichen `INSERT INTO users …` legte so ein Administratorkonto an,
 * während die Vorschau "alle übrigen Tabellen bleiben unverändert" zusagte.
 *
 * Geprüft wird hier ohne Datenbank: das Zerlegen (Quotes, Kommentare,
 * Chunkgrenzen), die Positivliste, die Blockreihenfolge und die
 * Tabellenregel samt Plan. Das Zusammenspiel mit einer echten Instanz liegt
 * in tests/Functional/DatenmigrationPluginTest.php - dort wird auch der
 * Dump des gepinnten Kerns live durch den Prüfer geschickt.
 */
class DatenmigrationDumpPrueferTest extends TestCase {

    private const MAX = 1048576;

    private const KOPF = "-- Automatisches Backup (#59) - 2026-09-28 00:00:00 UTC\n-- Datenbank: x\n"
        . "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n";

    private const FUSS = 'SET FOREIGN_KEY_CHECKS=1;';

    private static function block(string $tabelle, string $inserts = ''): string {
        return "-- Tabelle: {$tabelle}\nDROP TABLE IF EXISTS `{$tabelle}`;\n"
            . "CREATE TABLE `{$tabelle}` (\n  `id` int(11) NOT NULL AUTO_INCREMENT,\n  `name` varchar(100) DEFAULT NULL,\n"
            . "  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n"
            . $inserts . "\n";
    }

    private static function insert(string $tabelle, string $id, string $name): string {
        return "INSERT INTO `{$tabelle}` (`id`, `name`) VALUES ({$id}, {$name});\n";
    }

    /**
     * Lässt $dump in Stücken der Größe $stueck durch den Prüfer laufen.
     *
     * @return array{0: array<int, Anweisung>, 1: DumpPruefer}
     */
    private static function lauf(string $dump, ?DumpPruefer $pruefer = null, int $stueck = 65536): array {
        $pruefer ??= new DumpPruefer(self::MAX);
        $aus = [];
        foreach ($dump === '' ? [] : str_split($dump, $stueck) as $teil) {
            foreach ($pruefer->zufuehren($teil) as $a) {
                $aus[] = $a;
            }
        }
        foreach ($pruefer->abschliessen() as $a) {
            $aus[] = $a;
        }
        return [$aus, $pruefer];
    }

    private function assertAbgelehnt(string $dump, string $grund = '', ?DumpPruefer $pruefer = null): void {
        try {
            self::lauf($dump, $pruefer);
        } catch (DumpAbgelehnt $e) {
            if ($grund !== '') {
                $this->assertStringContainsString($grund, $e->getMessage());
            }
            $this->addToAssertionCount(1);
            return;
        }
        $this->fail("Dump wurde angenommen, erwartet war eine Ablehnung:\n" . $dump);
    }

    /**
     * Die echte Ausgabe des DatabaseDumper im gepinnten Kern (SHOW CREATE
     * TABLE von MariaDB für alle Kerntabellen - mit current_timestamp(),
     * COMMENT und CHECK) muss durchgehen, mit korrekter Zahl und Reihenfolge
     * der Anweisungen. Die Fixture ist aus einem Functional-Lauf erzeugt;
     * die Live-Variante steht im Functional-Test.
     */
    public function testEchterDumpDesKernsWirdAngenommen(): void {
        $dump = (string) file_get_contents(__DIR__ . '/fixtures/datenmigration-dump.sql');
        preg_match_all('/^DROP TABLE IF EXISTS `([a-z0-9_]+)`;$/m', $dump, $m);
        $inserts = preg_match_all('/^INSERT INTO /m', $dump);

        [$anweisungen, $pruefer] = self::lauf($dump);

        $this->assertNotEmpty($m[1]);
        $this->assertSame(2 + count($m[1]) * 2 + $inserts + 1, count($anweisungen));
        $this->assertSame($m[1], array_keys($pruefer->befund()->tabellen), 'Reihenfolge der Tabellen');
        $this->assertSame('set', $anweisungen[0]->art);
        $this->assertSame('drop', $anweisungen[2]->art);
        $this->assertSame('create', $anweisungen[3]->art);
        $this->assertSame(self::FUSS, $anweisungen[count($anweisungen) - 1]->sql . ';');
        // Die Fremdschlüssel aus dem Dump kommen im Befund an.
        $this->assertSame(['users'], $pruefer->befund()->tabellen['user_passkeys']['verweise']);
    }

    /** Chunkgrenzen dürfen das Ergebnis nicht ändern - auch nicht mitten in '' oder \'. */
    #[DataProvider('stueckgroessen')]
    public function testChunkgrenzenAendernNichts(int $stueck): void {
        // Die Fixture endet (Dateikonvention) mit Zeilenumbruch, der Fuß des
        // Dumps nicht - für das Einfügen vor dem Fuß abschneiden.
        $dump = rtrim((string) file_get_contents(__DIR__ . '/fixtures/datenmigration-dump.sql'), "\n");
        // Ein Block mit allem, was ein Zerleger falsch machen kann, vor dem Fuß.
        $knifflig = self::block('knifflig', self::insert('knifflig', "'1'", "'a;b\\nc\\\\'' -- d /* e */ # f`'"));
        $dump = substr($dump, 0, -strlen(self::FUSS)) . $knifflig . self::FUSS;
        [$ganz] = self::lauf($dump, null, 1 << 20);
        [$stuecke] = self::lauf($dump, null, $stueck);

        $this->assertSame(
            array_map(static fn(Anweisung $a): string => $a->sql, $ganz),
            array_map(static fn(Anweisung $a): string => $a->sql, $stuecke)
        );
    }

    /** @return array<string, array{0:int}> */
    public static function stueckgroessen(): array {
        return ['1 Byte' => [1], '7 Byte' => [7], '512 Byte' => [512]];
    }

    /**
     * ';', Zeilenumbrüche, \' und '' in Zeichenketten trennen nicht - sonst
     * könnte ein Wert eine zweite Anweisung beginnen.
     */
    public function testQuotesTrennenNicht(): void {
        $wert = "'x;y\nz \\' still drin '' auch; DROP TABLE `users`'";
        $dump = self::KOPF . self::block('horses', self::insert('horses', "'1'", $wert)) . self::FUSS;

        [$anweisungen, $pruefer] = self::lauf($dump);

        $this->assertCount(6, $anweisungen);
        $this->assertSame('insert', $anweisungen[4]->art);
        $this->assertStringEndsWith($wert . ')', $anweisungen[4]->sql);
        $this->assertSame(1, $pruefer->befund()->tabellen['horses']['zeilen']);
    }

    /**
     * '-- leer' ohne Zeilenumbruch am Ende ist ein Kommentar (so sieht der
     * Dump im bestehenden Versionstest aus). '--' mitten in einer Anweisung
     * und '--x' sind keine - dort deuten Client und Server verschieden.
     */
    public function testKommentarsemantik(): void {
        [$leer, $p] = self::lauf('-- leer');
        $this->assertSame([], $leer);
        $this->assertSame([], $p->befund()->tabellen);

        [$mitKommentaren] = self::lauf("-- eins\n\t--\ttab\n--\n" . self::KOPF . "-- zwei\n" . self::FUSS . "\n-- ende");
        $this->assertCount(3, $mitKommentaren);

        $this->assertAbgelehnt("SET NAMES utf8mb4 -- dahinter\n;", '„--“ innerhalb');
        $this->assertAbgelehnt("--x\nSET NAMES utf8mb4;");
        $this->assertAbgelehnt(self::KOPF . "SET FOREIGN_KEY_CHECKS=1--1;");
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function manipulierteDumps(): array {
        $h = self::block('horses');
        return [
            'UPDATE' => [self::KOPF . $h . "UPDATE `users` SET `password_hash` = 'x';\n", 'nicht vorgesehen'],
            'DELETE' => [self::KOPF . "DELETE FROM `users`;\n", 'nicht vorgesehen'],
            'INSERT … SELECT' => [self::KOPF . $h . "INSERT INTO `horses` (`id`, `name`) SELECT 1, 'x';\n", 'INSERT'],
            'Unterabfrage' => [self::KOPF . $h . self::insert('horses', "'1'", '(SELECT password_hash FROM users LIMIT 1)'), 'weder NULL'],
            'Funktion' => [self::KOPF . $h . self::insert('horses', "'1'", "CONCAT('a', 'b')"), 'weder NULL'],
            'Zahl' => [self::KOPF . $h . self::insert('horses', '1', "'x'"), 'weder NULL'],
            'ON DUPLICATE KEY UPDATE' => [self::KOPF . $h
                . "INSERT INTO `horses` (`id`, `name`) VALUES ('1', 'x') ON DUPLICATE KEY UPDATE `name` = 'y';\n",
                'weitere Bestandteile'],
            'CREATE … SELECT' => [self::KOPF . "DROP TABLE IF EXISTS `horses`;\n"
                . "CREATE TABLE `horses` (`id` int) SELECT * FROM `users`;\n", 'SELECT'],
            'CREATE … LIKE' => [self::KOPF . "DROP TABLE IF EXISTS `horses`;\nCREATE TABLE `horses` LIKE `users`;\n", 'nicht vorgesehen'],
            'ENGINE=CONNECT' => [self::KOPF . "DROP TABLE IF EXISTS `horses`;\n"
                . "CREATE TABLE `horses` (`id` int) ENGINE=CONNECT TABLE_TYPE=MYSQL;\n", 'Speicher-Engines'],
            'ENGINE=FEDERATED' => [self::KOPF . "DROP TABLE IF EXISTS `horses`;\n"
                . "CREATE TABLE `horses` (`id` int) ENGINE = FEDERATED;\n", 'Speicher-Engines'],
            'DATA DIRECTORY' => [self::KOPF . "DROP TABLE IF EXISTS `horses`;\n"
                . "CREATE TABLE `horses` (`id` int) ENGINE=InnoDB DATA DIRECTORY='/var/www/public';\n", 'DIRECTORY'],
            'LOAD_FILE' => [self::KOPF . "DROP TABLE IF EXISTS `horses`;\n"
                . "CREATE TABLE `horses` (`id` int, `x` text DEFAULT (LOAD_FILE('/etc/passwd')));\n", 'LOAD_FILE'],
            'Versionskommentar' => [self::KOPF . "DROP TABLE IF EXISTS `horses`;\n"
                . "CREATE TABLE `horses` (`id` int /*!50000 INVISIBLE */);\n", '/*'],
            '#-Kommentar' => [self::KOPF . "# kommentar\nSET NAMES utf8mb4;\n", '#-Kommentare'],
            'DELIMITER' => [self::KOPF . "DELIMITER //\nSET NAMES utf8mb4;\n", 'nicht vorgesehen'],
            'INSERT vor CREATE' => [self::KOPF . "DROP TABLE IF EXISTS `horses`;\n" . self::insert('horses', "'1'", "'x'"), 'außerhalb'],
            'INSERT ohne Block' => [self::KOPF . self::insert('horses', "'1'", "'x'"), 'außerhalb'],
            'Tabelle doppelt' => [self::KOPF . $h . $h, 'mehrfach'],
            'fremde Tabelle im Block' => [self::KOPF . $h
                . "INSERT INTO `users` (`id`, `name`) VALUES ('1', 'admin');\n", 'im Block der Tabelle `horses`'],
            'offener Quote' => [self::KOPF . $h . "INSERT INTO `horses` (`id`, `name`) VALUES ('1', 'offen);", 'offenen Zeichenkette'],
            'Rest ohne Semikolon' => [self::KOPF . 'SET NAMES utf8mb4', 'ohne abschließendes Semikolon'],
            'DROP ohne CREATE' => [self::KOPF . "DROP TABLE IF EXISTS `horses`;\n" . self::FUSS, 'kein CREATE'],
            'Block nach dem Fuß' => [self::KOPF . $h . self::FUSS . "\n" . self::block('contacts'), 'nach den abschließenden'],
            'USERS' => [self::KOPF . self::block('USERS'), 'Kleinbuchstaben'],
            'Users' => [self::KOPF . self::block('Users'), 'Kleinbuchstaben'],
            'leere Anweisung (View)' => [self::KOPF . "DROP TABLE IF EXISTS `v`;\n;\n", 'Leere Anweisung'],
            'SQL_MODE NO_BACKSLASH_ESCAPES' => ["SET SQL_MODE='NO_BACKSLASH_ESCAPES';\n", 'SET-Anweisung'],
            'SQL_MODE ANSI_QUOTES' => ["SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO,ANSI_QUOTES';\n", 'SET-Anweisung'],
            'SET GLOBAL' => ["SET GLOBAL general_log = 1;\n", 'SET-Anweisung'],
            'CREATE TRIGGER' => [self::KOPF . "CREATE TRIGGER t BEFORE INSERT ON horses FOR EACH ROW SET NEW.name = 'x';\n", 'nicht vorgesehen'],
        ];
    }

    #[DataProvider('manipulierteDumps')]
    public function testManipulierterDumpWirdAbgelehnt(string $dump, string $grund): void {
        $this->assertAbgelehnt($dump, $grund);
    }

    /** Die Meldung nennt die Anweisungsnummer und den Anfang der Anweisung. */
    public function testMeldungNenntAnweisungUndAnfang(): void {
        try {
            self::lauf(self::KOPF . "UPDATE `users` SET `is_admin` = 1;\n");
            $this->fail('Nicht abgelehnt');
        } catch (DumpAbgelehnt $e) {
            $this->assertStringContainsString('Anweisung 3', $e->getMessage());
            $this->assertStringContainsString('UPDATE `users` SET', $e->getMessage());
        }
    }

    public function testAnweisungUeberDerGrenzeWirdAbgelehnt(): void {
        $gross = self::insert('horses', "'1'", "'" . str_repeat('a', 2000) . "'");
        $this->assertAbgelehnt(self::KOPF . self::block('horses', $gross), 'Paketgrenze', new DumpPruefer(1000));
        // Auch mitten im Lesen, bevor das Semikolon kommt.
        $pruefer = new DumpPruefer(1000);
        $this->expectException(DumpAbgelehnt::class);
        foreach ($pruefer->zufuehren(self::KOPF . "INSERT INTO `horses` (`id`) VALUES ('" . str_repeat('b', 1500)) as $_) {
        }
    }

    /**
     * Die Kopf- und Fußzeilen aus Framework N66 (Zeitzone) und ein
     * NO_AUTO_VALUE_ON_ZERO sind optional zulässig.
     */
    public function testOptionaleKopfzeilenAusN66(): void {
        $dump = "SET @hv_dump_zeitzone = @@SESSION.time_zone;\nSET time_zone = '+00:00';\n"
            . "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" . self::KOPF . self::block('horses')
            . "SET FOREIGN_KEY_CHECKS=1;\nSET time_zone = @hv_dump_zeitzone;";
        [$anweisungen] = self::lauf($dump);
        $this->assertCount(9, $anweisungen);

        [$ohne] = self::lauf(self::KOPF . self::block('horses') . self::FUSS);
        $this->assertCount(5, $ohne);

        $this->assertAbgelehnt("SET time_zone = 'Europe/Berlin';\n", 'SET-Anweisung');
        $this->assertAbgelehnt("SET @andere = @@SESSION.time_zone;\n", 'SET-Anweisung');
    }

    /** Die Tabellenregel (a) bis (c), inklusive Altarchiv und Verweisziel aus der Zielinstanz. */
    public function testTabellenregel(): void {
        $pferde = Importregel::fuer(['pferde'], []);
        $this->assertSame(DumpPruefer::AUSFUEHREN, $pferde('horses', [])[0]);
        // users ohne gewählte Gruppe: (a), nicht (b).
        $this->assertSame(DumpPruefer::ABLEHNEN, $pferde('users', [])[0]);
        $this->assertSame(DumpPruefer::ABLEHNEN, $pferde('settings', [])[0]);

        // Altarchiv [pferde, sonstiges]: user_passkeys lief damals unter
        // "sonstiges" - übersprungen, nicht abgelehnt.
        $alt = Importregel::fuer(['pferde', Exportauswahl::GRUPPE_SONSTIGES], []);
        [$aktion, $grund] = $alt('user_passkeys', ['users']);
        $this->assertSame(DumpPruefer::UEBERSPRINGEN, $aktion);
        $this->assertStringContainsString('users', (string) $grund);

        // plugin_-Tabelle mit Verweis auf users unter "addons".
        $addons = Importregel::fuer(['addons'], []);
        $this->assertSame(DumpPruefer::UEBERSPRINGEN, $addons('plugin_x_konten', ['users'])[0]);
        $this->assertSame(DumpPruefer::AUSFUEHREN, $addons('plugin_x_konten', ['horses'])[0]);

        // Ein manipuliertes CREATE ohne REFERENCES hebelt die Regel nicht aus:
        // Der Verweis der gleichnamigen Tabelle auf dem Ziel zählt mit.
        $mitKarte = Importregel::fuer(['addons'], ['plugin_mitglieder_konten_zuordnung' => ['users']]);
        $this->assertSame(DumpPruefer::UEBERSPRINGEN, $mitKarte('plugin_mitglieder_konten_zuordnung', [])[0]);

        // Mit der Gruppe "benutzer" läuft alles.
        $voll = Importregel::fuer(Exportauswahl::schluessel(), []);
        $this->assertSame(DumpPruefer::AUSFUEHREN, $voll('users', [])[0]);
        $this->assertSame(DumpPruefer::AUSFUEHREN, $voll('user_passkeys', ['users'])[0]);
    }

    /** Validierung liefert den Plan; eine abgelehnte Tabelle bricht den ganzen Dump ab. */
    public function testPlanAusDemErstenDurchlauf(): void {
        $dump = self::KOPF . self::block('horses', self::insert('horses', "'1'", "'a'") . self::insert('horses', "'2'", 'NULL'))
            . "DROP TABLE IF EXISTS `user_passkeys`;\nCREATE TABLE `user_passkeys` (\n  `id` int NOT NULL,\n"
            . "  `user_id` int NOT NULL,\n  CONSTRAINT `fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE\n"
            . ") ENGINE=InnoDB;\nINSERT INTO `user_passkeys` (`id`, `user_id`) VALUES ('1', '1');\n\n" . self::FUSS;
        $regel = Importregel::fuer(['pferde', Exportauswahl::GRUPPE_SONSTIGES], []);

        [$anweisungen, $pruefer] = self::lauf($dump, new DumpPruefer(self::MAX, $regel));
        $befund = $pruefer->befund();

        $this->assertSame([], $anweisungen, 'Der Validierungslauf führt nichts aus.');
        $this->assertSame(['horses' => DumpPruefer::AUSFUEHREN, 'user_passkeys' => DumpPruefer::UEBERSPRINGEN], $befund->plan());
        $this->assertSame(2, $befund->tabellen['horses']['zeilen']);
        $this->assertSame(['horses'], $befund->ersetzt());
        $this->assertArrayHasKey('user_passkeys', $befund->uebersprungen());

        // Zweiter Durchlauf mit Plan: nur horses und die SET-Zeilen kommen heraus.
        [$ausfuehren] = self::lauf($dump, new DumpPruefer(self::MAX, $regel, $befund->plan()));
        $tabellen = array_values(array_unique(array_filter(array_map(
            static fn(Anweisung $a): ?string => $a->tabelle,
            $ausfuehren
        ))));
        $this->assertSame(['horses'], $tabellen);
        $this->assertCount(3 + 2 + 2, $ausfuehren);

        // Das präparierte Teilarchiv: [pferde] mit einem users-Block.
        $this->assertAbgelehnt(
            self::KOPF . self::block('horses') . self::block('users', self::insert('users', "'9'", "'boese'")),
            'Tabelle `users` darf dieses Archiv nicht ersetzen',
            new DumpPruefer(self::MAX, Importregel::fuer(['pferde'], []))
        );
    }

    /** Der zweite Durchlauf hält sich an den Plan - eine Abweichung bricht ab. */
    public function testZweiterDurchlaufMitAbweichendemPlanWirft(): void {
        $dump = self::KOPF . self::block('horses') . self::block('contacts') . self::FUSS;
        $regel = Importregel::fuer(['pferde', 'kontakte'], []);

        // Tabelle fehlt im Plan.
        $this->assertAbgelehnt($dump, 'nicht im geprüften Plan',
            new DumpPruefer(self::MAX, $regel, ['horses' => DumpPruefer::AUSFUEHREN]));
        // Andere Aktion als im Plan.
        $this->assertAbgelehnt($dump, 'weicht vom geprüften Plan ab',
            new DumpPruefer(self::MAX, $regel, ['horses' => DumpPruefer::UEBERSPRINGEN, 'contacts' => DumpPruefer::AUSFUEHREN]));
        // Plan nennt eine Tabelle, die im Dump fehlt.
        $this->assertAbgelehnt($dump, 'fehlt aber im Dump', new DumpPruefer(self::MAX, $regel, [
            'horses' => DumpPruefer::AUSFUEHREN, 'contacts' => DumpPruefer::AUSFUEHREN, 'settings' => DumpPruefer::AUSFUEHREN,
        ]));
    }

    /** Sammel-INSERTs brauchen einen einheitlichen Kopf je Tabelle und Spaltenliste. */
    public function testInsertLiefertKopfUndWerte(): void {
        [$anweisungen] = self::lauf(self::KOPF . self::block('horses',
            "INSERT  INTO `horses`(`id`,`name`) VALUES ('1','a'), ('2', NULL);\n"));
        $insert = $anweisungen[4];
        $this->assertSame('INSERT INTO `horses` (`id`, `name`) VALUES ', $insert->kopf);
        $this->assertSame("('1','a'), ('2', NULL)", $insert->werte);
    }
}
