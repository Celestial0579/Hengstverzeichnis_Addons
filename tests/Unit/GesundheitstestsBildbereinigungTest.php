<?php
// tests/Unit/GesundheitstestsBildbereinigungTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\Gesundheitstests\Bildbereinigung;

require_once __DIR__ . '/../../plugins/gesundheitstests/Plugin.php';

/**
 * Befundfotos verlieren ihre Metadaten (GHSA-v26w-vc3j-wm5x). Die
 * Bereinigung selbst kommt aus dem Kern (App\Service\BildMetadaten, Audit
 * M21); hier zählt, dass das Addon sie nutzt und ohne sie kein Bild durchlässt.
 */
class GesundheitstestsBildbereinigungTest extends TestCase {

    /** 8x8-JPEG mit EXIF: Make "GPSKAMERA", Model "Testmodell" und GPS-Position. */
    public const JPEG_MIT_GPS = '/9j/4AAQSkZJRgABAQAAAQABAAD/4QC2RXhpZgAATU0AKgAAAAgAAwEPAAIAAAAKAAAAMgEQAAIAAAALAAAAPIglAAQAAAABAAAASAAAAABHUFNLQU1FUkEAVGVzdG1vZGVsbAAAAAQAAQACAAAAAk4AAAAAAgAFAAAAAwAAAH4AAwACAAAAAkUAAAAABAAFAAAAAwAAAJYAAAAAAAAANQAAAAEAAAAEAAAAAQAAAAwAAAABAAAACAAAAAEAAAAwAAAAAQAAAB4AAAAB/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgACAAIAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A84ooorwj6U//2Q==';

    public function testNurJpegPngWebpGeltenAlsBild(): void {
        $this->assertTrue(Bildbereinigung::istBild('image/jpeg'));
        $this->assertTrue(Bildbereinigung::istBild('image/png'));
        $this->assertTrue(Bildbereinigung::istBild('image/webp'));
        $this->assertFalse(Bildbereinigung::istBild('application/pdf'));
    }

    public function testOhneBereinigerKommtKeinBildDurch(): void {
        $this->assertNull(Bildbereinigung::bereinigt(base64_decode(self::JPEG_MIT_GPS), null));
    }

    public function testEinScheiternderBereinigerLiefertNull(): void {
        $this->assertNull(Bildbereinigung::bereinigt('kaputt', static fn(string $b): ?string => null));
        $this->assertNull(Bildbereinigung::bereinigt('', static fn(string $b): ?string => $b));
    }

    public function testDerKernBereinigerEntferntGpsUndKamera(): void {
        $bereiniger = Bildbereinigung::kernBereiniger();
        if ($bereiniger === null) {
            $this->markTestSkipped('Kern ohne App\\Service\\BildMetadaten');
        }
        $roh = base64_decode(self::JPEG_MIT_GPS);
        $this->assertStringContainsString('GPSKAMERA', $roh, 'Testbild muss EXIF tragen');

        $sauber = Bildbereinigung::bereinigt($roh, $bereiniger);
        $this->assertNotNull($sauber);
        $this->assertStringNotContainsString('GPSKAMERA', $sauber);
        $this->assertStringNotContainsString('Testmodell', $sauber);
        $this->assertStringNotContainsString("\x88\x25", substr($sauber, 0, 200), 'GPS-IFD-Zeiger darf nicht bleiben');
        $groesse = getimagesizefromstring($sauber);
        $this->assertSame([8, 8, IMAGETYPE_JPEG], [$groesse[0], $groesse[1], $groesse[2]]);
    }

    public function testSchreibeAtomarLegtDieDateiVollstaendigAn(): void {
        $verz = sys_get_temp_dir() . '/gt-bild-' . bin2hex(random_bytes(4));
        mkdir($verz);
        try {
            $this->assertTrue(Bildbereinigung::schreibeAtomar($verz . '/a.jpg', 'inhalt'));
            $this->assertSame('inhalt', file_get_contents($verz . '/a.jpg'));
            $this->assertSame(['a.jpg'], array_values(array_diff(scandir($verz), ['.', '..'])), 'keine Zwischendatei');
            $this->assertFalse(Bildbereinigung::schreibeAtomar($verz . '/fehlt/b.jpg', 'x'));
        } finally {
            array_map('unlink', glob($verz . '/*') ?: []);
            rmdir($verz);
        }
    }
}
