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

    /**
     * @param bool|null $gzip Lesemodus des TarReader (null = wie im Betrieb)
     * @return array<string, string> name => inhalt
     */
    private function readAll(string $path, ?bool $gzip = null): array {
        $reader = new TarReader($path, $gzip);
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

    /**
     * Audit N22: Ohne zlib öffnete der Leser per fopen(), las aber per
     * gzread() - jedes Archiv endete mit einem Fatal Error. Der fread-Pfad
     * muss dasselbe liefern wie der gzread-Pfad, byte-genau, auch für
     * Binärdaten und den langen ustar-Pfad.
     */
    public function testLesenOhneZlibPerFread(): void {
        $binary = random_bytes(4096 + 77);
        $src = $this->dir . '/quelle.bin';
        file_put_contents($src, $binary);
        $deep = 'storage-horses/' . str_repeat('unterverzeichnis-mit-namen/', 5) . 'foto.jpg';
        $this->assertGreaterThan(100, strlen($deep));

        $archive = $this->dir . '/ohne-zlib.tar';
        $tar = TarWriter::create($archive);
        $tar->addString('manifest.json', '{"format":3}');
        $tar->addFile('uploads/bild.bin', $src);
        $tar->addString($deep, 'foto');
        $tar->close();

        $entries = $this->readAll($archive, false);
        $this->assertSame(['manifest.json', 'uploads/bild.bin', $deep], array_keys($entries));
        $this->assertSame('{"format":3}', $entries['manifest.json']);
        $this->assertSame($binary, $entries['uploads/bild.bin']);
        $this->assertSame('foto', $entries[$deep]);
    }

    /** Audit N22: Ein .tar.gz ohne zlib wird mit einer verständlichen Meldung abgewiesen. */
    public function testGzipArchivOhneZlibWirdVerstaendlichAbgewiesen(): void {
        if (!function_exists('gzopen')) {
            $this->markTestSkipped('zlib nicht verfügbar - ein .tar.gz lässt sich hier nicht erzeugen');
        }
        $archive = $this->dir . '/komprimiert.tar.gz';
        $tar = TarWriter::create($archive);
        $tar->addString('manifest.json', '{"format":3}');
        $tar->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/zlib/');
        new TarReader($archive, false);
    }

    /**
     * Audit N22 unter echten Bedingungen: ein PHP-Prozess, in dem die
     * zlib-Funktionen fehlen (disable_functions). Nur so fällt ein
     * übersehenes gzread() auf - mit zlib ist es ein Alias von fread() und
     * der Fehler unsichtbar.
     */
    public function testImProzessOhneZlibLesbarUndGzipAbgewiesen(): void {
        $archive = $this->dir . '/prozess.tar';
        $tar = TarWriter::create($archive);
        $tar->addString('manifest.json', '{"format":3}');
        $tar->addString('storage-horses/foto.jpg', str_repeat('x', 1500));
        $tar->close();

        $skript = $this->dir . '/lesen.php';
        file_put_contents($skript, '<?php
            require ' . var_export(__DIR__ . '/../../vendor/autoload.php', true) . ';
            require ' . var_export(__DIR__ . '/../../plugins/datenmigration/Plugin.php', true) . ';
            if (function_exists("gzopen") || function_exists("gzread")) { echo "zlib-aktiv"; exit(1); }
            $r = new \\Plugin\\Datenmigration\\TarReader($argv[1]);
            $r->each(function (string $n, int $s, callable $read) {
                $d = ""; while (($c = $read()) !== "") { $d .= $c; }
                echo $n, "=", strlen($d), ";";
            });
            $r->close();
            if (isset($argv[2])) {
                try { new \\Plugin\\Datenmigration\\TarReader($argv[2]); echo "gzip-angenommen"; }
                catch (\\RuntimeException $e) { echo "|", $e->getMessage(); }
            }
        ');

        $gz = null;
        if (function_exists('gzencode')) {
            $gz = $this->dir . '/prozess.tar.gz';
            file_put_contents($gz, gzencode((string) file_get_contents($archive)));
        }
        $befehl = escapeshellarg(PHP_BINARY) . ' -d disable_functions=gzopen,gzread,gzclose,gzwrite,gzeof '
            . escapeshellarg($skript) . ' ' . escapeshellarg($archive) . ($gz !== null ? ' ' . escapeshellarg($gz) : '')
            . ' 2>&1';
        exec($befehl, $ausgabe, $code);
        $text = implode("\n", $ausgabe);
        $this->assertSame(0, $code, $text);
        $this->assertStringStartsWith('manifest.json=12;storage-horses/foto.jpg=1500;', $text);
        if ($gz !== null) {
            $this->assertStringContainsString('|Das Archiv ist gzip-komprimiert', $text);
            $this->assertStringContainsString('zlib', $text);
        }
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
