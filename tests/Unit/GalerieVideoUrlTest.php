<?php
// tests/Unit/GalerieVideoUrlTest.php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\Galerie\Plugin as GaleriePlugin;

require_once __DIR__ . '/../../plugins/galerie/Plugin.php';

/**
 * Die Host-Allowlist für Video-Links prüft mit PHPs parse_url(), angezeigt
 * wird die Zeichenkette später im Browser. Solange die Eingabe unverändert
 * durchgereicht wurde, hing die Sicherheit daran, dass beide Parser jede
 * Eingabe gleich lesen - und Abweichungen zwischen Parsern sind der Stoff,
 * aus dem Allowlist-Umgehungen gemacht sind.
 *
 * Seit die URL aus den geprüften Teilen NEU GEBAUT wird, ist die Frage
 * gegenstandslos. Die Tests halten beides fest: dass die bekannten Tricks
 * abgelehnt werden, und dass die Rückgabe wirklich die normalisierte Form ist.
 */
class GalerieVideoUrlTest extends TestCase {

    /**
     * @return array<string, array{0: string}>
     */
    public static function abgelehnt(): array {
        return [
            'Benutzerinfo vor dem @' => ['https://youtube.com@evil.tld/video'],
            'Rueckwaertsschraegstrich vor dem @' => ['https://youtube.com\\@evil.tld/video'],
            'Fremder Host mit Fragment' => ['https://evil.tld#youtube.com'],
            'Suffix-Trick' => ['https://youtube.com.evil.tld/'],
            'Kein https' => ['http://youtube.com/watch?v=abc'],
            'Anderes Schema' => ['javascript:alert(1)'],
            'Leer' => [''],
        ];
    }

    #[DataProvider('abgelehnt')]
    public function testFremdeHostsUndSchemataWerdenVerworfen(string $url): void {
        $this->assertNull(GaleriePlugin::sanitizeVideoUrl($url));
    }

    public function testErlaubteHostsKommenNormalisiertZurueck(): void {
        $this->assertSame(
            'https://www.youtube.com/watch?v=abc123',
            GaleriePlugin::sanitizeVideoUrl('https://www.youtube.com/watch?v=abc123')
        );
        $this->assertSame(
            'https://youtu.be/abc123',
            GaleriePlugin::sanitizeVideoUrl('  https://youtu.be/abc123  ')
        );
        // Grossschreibung im Host ist zulaessig und wird normalisiert.
        $this->assertSame(
            'https://vimeo.com/12345',
            GaleriePlugin::sanitizeVideoUrl('https://VIMEO.com/12345')
        );
    }

    /**
     * Das Fragment gehört nicht in eine eingebettete Video-URL und fällt beim
     * Neubau weg - damit kann es auch nichts mehr verschleiern.
     */
    public function testFragmentWirdEntfernt(): void {
        $this->assertSame(
            'https://vimeo.com/12345',
            GaleriePlugin::sanitizeVideoUrl('https://vimeo.com/12345#evil')
        );
    }

    /**
     * Anführungszeichen und spitze Klammern könnten im Attributwert den Wert
     * beenden - sie werden verworfen.
     */
    public function testAttributBrechendeZeichenWerdenVerworfen(): void {
        $this->assertNull(GaleriePlugin::sanitizeVideoUrl('https://vimeo.com/12"onload=x'));
        $this->assertNull(GaleriePlugin::sanitizeVideoUrl("https://vimeo.com/12'x"));
        $this->assertNull(GaleriePlugin::sanitizeVideoUrl('https://vimeo.com/<script>'));
    }

    /**
     * Zeilenumbruch, Wagenrücklauf und Tabulator ersetzt PHPs parse_url() von
     * sich aus durch '_' - sie erreichen die eigene Prüfung also gar nicht
     * mehr. Festgehalten, weil sich sonst niemand darauf verlassen sollte:
     * Ändert PHP dieses Verhalten, wird der Test rot und nicht die Anwendung
     * unsicher.
     */
    public function testZeilenumbruecheWerdenVonParseUrlEntschaerft(): void {
        $this->assertSame(
            'https://vimeo.com/12_345',
            GaleriePlugin::sanitizeVideoUrl("https://vimeo.com/12\n345")
        );
    }
}
