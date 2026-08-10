<?php
// tests/Unit/DatenmigrationTarTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\Datenmigration\TarReader;
use Plugin\Datenmigration\TarWriter;

require_once __DIR__ . '/../../plugins/datenmigration/Plugin.php';

/**
 * Rechenkern des Datenmigrations-Addons: der pure-PHP-ustar-Schreiber/-Leser.
 * Ohne Datenbank und ohne Framework-Instanz prüfbar; das Zusammenspiel mit
 * dem Kern (Export/Import über HTTP) liegt in tests/Functional.
 */
class DatenmigrationTarTest extends TestCase {

    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/dm-tar-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    /** @return array<string, string> name => inhalt */
    private function readAll(string $path): array {
        $reader = new TarReader($path);
        $result = [];
        $reader->each(function (string $name, int $size, callable $read) use (&$result) {
            $data = '';
            while (($chunk = $read()) !== '') {
                $data .= $chunk;
            }
            $result[$name] = $data;
            $this->assertSame($size, strlen($data), "Größe im Header passt nicht zum Inhalt von {$name}");
        });
        $reader->close();
        return $result;
    }

    public function testRoundtripStringsUndDateien(): void {
        $binary = random_bytes(2048 + 123); // absichtlich kein 512er-Vielfaches
        $src = $this->dir . '/quelle.bin';
        file_put_contents($src, $binary);

        $archive = $this->dir . '/test.tar';
        $tar = TarWriter::create($archive);
        $tar->addString('manifest.json', '{"format":1}');
        $tar->addString('leer.txt', '');
        $tar->addFile('uploads/horses/bild.bin', $src);
        $tar->close();

        $entries = $this->readAll($archive);
        $this->assertSame(['manifest.json', 'leer.txt', 'uploads/horses/bild.bin'], array_keys($entries));
        $this->assertSame('{"format":1}', $entries['manifest.json']);
        $this->assertSame('', $entries['leer.txt']);
        $this->assertSame($binary, $entries['uploads/horses/bild.bin']);
    }

    public function testRoundtripGzip(): void {
        if (!function_exists('gzopen')) {
            $this->markTestSkipped('zlib nicht verfügbar');
        }
        $archive = $this->dir . '/test.tar.gz';
        $tar = TarWriter::create($archive);
        $tar->addString('database.sql', str_repeat("INSERT INTO x VALUES ('ä', 'O''Brien');\n", 500));
        $tar->close();

        // gz-Magic am Dateianfang: Es wurde wirklich komprimiert geschrieben.
        $head = file_get_contents($archive, false, null, 0, 2);
        $this->assertSame("\x1f\x8b", $head);

        $entries = $this->readAll($archive);
        $this->assertArrayHasKey('database.sql', $entries);
        $this->assertStringContainsString("O''Brien", $entries['database.sql']);
    }

    public function testLangePfadeUeberUstarPrefix(): void {
        $deep = 'uploads/' . str_repeat('sehr-langes-verzeichnis/', 6) . 'datei-mit-langem-namen.jpg';
        $this->assertGreaterThan(100, strlen($deep));

        $archive = $this->dir . '/lang.tar';
        $tar = TarWriter::create($archive);
        $tar->addString($deep, 'x');
        $tar->close();

        $entries = $this->readAll($archive);
        $this->assertSame([$deep => 'x'], $entries);
    }

    public function testBeschaedigtesArchivFaelltAuf(): void {
        $archive = $this->dir . '/kaputt.tar';
        $tar = TarWriter::create($archive);
        $tar->addString('manifest.json', '{"format":1}');
        $tar->close();

        // Ein Byte im Header kippen -> Prüfsumme muss den Schaden melden.
        $data = file_get_contents($archive);
        $data[10] = $data[10] === 'A' ? 'B' : 'A';
        file_put_contents($archive, $data);

        $this->expectException(\RuntimeException::class);
        $this->readAll($archive);
    }

    public function testAbgeschnittenesArchivFaelltAuf(): void {
        $archive = $this->dir . '/kurz.tar';
        $tar = TarWriter::create($archive);
        $tar->addString('database.sql', str_repeat('x', 4096));
        $tar->close();

        file_put_contents($archive, substr(file_get_contents($archive), 0, 1024));

        $this->expectException(\RuntimeException::class);
        $this->readAll($archive);
    }
}
