<?php
// tests/Functional/SprachAddonNlTest.php

namespace Tests\Functional;

/**
 * Ein echtes Sprach-Addon über den echten Weg (Framework#344).
 *
 * Stichprobe für `sprache-nl` — der Mechanismus ist für alle zehn derselbe,
 * und die Vollständigkeit jeder einzelnen Sprache prüft
 * `tests/Unit/SprachAddonVollstaendigkeitTest.php`.
 *
 * WAS HIER GEPRÜFT WIRD UND DORT NICHT: dass die Sprache im laufenden System
 * ankommt. Der Kern hat für dieselbe Frage einen eigenen Test mit einem
 * Wegwerf-Addon; dieser hier nimmt das ausgelieferte Addon. Beide zusammen
 * decken ab, was eine Gegenprobe im Kern aufgedeckt hat: Der Aufruf, der
 * Sprach-Addons überhaupt erst anmeldet, liess sich ersatzlos löschen, ohne
 * dass ein Test rot wurde.
 */
class SprachAddonNlTest extends FunctionalTestCase {

    private const SLUG = 'sprache-nl';

    protected function tearDown(): void {
        $db = \App\Database::getInstance();
        $db->prepare('DELETE FROM plugins WHERE slug = ?')->execute([self::SLUG]);
        parent::tearDown();
    }

    public function testDasAddonMachtNiederlaendischWaehlbarUndUebersetzt(): void {
        $admin = $this->authenticatedClient();
        $gast = $this->newClient();

        // Vorher: Der Kern kennt den Namen, bietet die Sprache aber nicht an.
        $vorher = $gast->get('/');
        $this->assertSame(200, $vorher->statusCode);
        $this->assertStringContainsString('<select id="footer-lang-select"', $vorher->body);
        $this->assertStringNotContainsString('>Nederlands</option>', $vorher->body);

        $antwort = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $antwort->location(), "Body: {$antwort->body}");

        $nachher = $gast->get('/');
        $this->assertStringContainsString('>Nederlands</option>', $nachher->body);

        $niederlaendisch = $gast->get('/?lang=nl');
        $this->assertStringContainsString('lang="nl"', $niederlaendisch->body);

        // Ein Text, der wirklich aus der Sprachdatei kommt - nicht nur das
        // lang-Attribut. Sonst waere der Test auch dann gruen, wenn die Datei
        // gar nicht gelesen wuerde.
        $tabelle = require __DIR__ . '/../../plugins/' . self::SLUG . '/lang/core/nl.php';
        $this->assertStringContainsString(
            htmlspecialchars((string)$tabelle['footer.impressum'], ENT_QUOTES, 'UTF-8'),
            $niederlaendisch->body
        );

        $gast->get('/?lang=de');
    }
}
