<?php
// tests/Functional/EmbedWidgetPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/embed-widget.
 *
 * WARUM ES DEN BISHER NICHT GAB: Das Addon verlangt laut plugin.json
 * `core_compatibility: ">=0.5.1"`, der Kern meldete aber `CORE_VERSION 0.5.0`.
 * Der PluginManager wies es damit fail-closed ab - es liess sich auf keiner
 * Instanz aktivieren, also auch nicht testen. Das war im Addons-CHANGELOG
 * ausdrücklich als offener Punkt vermerkt. Mit Kern 0.5.1 greift es, und der
 * Test kann nachgeholt werden.
 *
 * Er prüft damit zugleich das, was die Versionsschranke eigentlich zusichern
 * soll: dass das Addon auf einem Kern dieser Linie wirklich lädt.
 */
class EmbedWidgetPluginTest extends FunctionalTestCase {

    private const SLUG = 'embed-widget';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Embed-Widget', $pluginsPage->body);

        // Der eigentliche Punkt: Die Aktivierung muss GELINGEN. Auf einem Kern
        // unterhalb 0.5.1 endete sie hier mit einer Kompatibilitätsmeldung.
        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame(
            '/admin/plugins?success=1',
            $toggleResponse->location(),
            "Aktivierung fehlgeschlagen - laeuft der Kern auf >= 0.5.1? Body: {$toggleResponse->body}"
        );

        // 1. Ohne die Berechtigung ist der Generator nicht erreichbar.
        $unique = uniqid();
        $editor = $this->createAndLoginEditor($admin, "embed{$unique}", "embed-{$unique}@example.com");
        $verboten = $editor->get('/plugin/embed-widget/generator');
        $this->assertSame(403, $verboten->statusCode, 'Der Generator gehört hinter embed-widget.manage');

        // 2. Für Anonyme ebenfalls nicht - er landet auf dem Login.
        $anonym = $this->newClient();
        $anonymAntwort = $anonym->get('/plugin/embed-widget/generator');
        $this->assertNotSame(200, $anonymAntwort->statusCode, 'Der Generator darf für Anonyme nicht offen sein');

        // 3. Als Admin: Die Seite rendert und liefert einen iframe-Schnipsel
        //    auf die Embed-Ansicht des eigenen Katalogs.
        $generator = $admin->get('/plugin/embed-widget/generator');
        $this->assertSame(200, $generator->statusCode);
        $this->assertStringContainsString('Embed-Widget', $generator->body);
        // Der Schnipsel steht ESCAPED auf der Seite - er soll gelesen und
        // kopiert, nicht ausgefuehrt werden. Genau deshalb '&lt;iframe' und
        // nicht '<iframe': Stuende er unescaped da, waere das ein Fehler.
        $this->assertStringContainsString('&lt;iframe', $generator->body);
        $this->assertStringContainsString('/katalog?embed=1', $generator->body);

        // 4. Die Frage, die das Addon selbst stellt: Ohne freigegebene Domains
        //    bleibt die Frame-Sperre des Kerns bestehen. Der Generator muss
        //    darauf hinweisen, statt einen Schnipsel zu liefern, der beim
        //    Empfänger stumm leer bleibt - und er darf in diesem Zustand auch
        //    keine Live-Vorschau rendern, die im eigenen Tab funktioniert und
        //    beim Empfänger nicht.
        $this->assertStringContainsString('EMBED_ALLOWED_DOMAINS', $generator->body);
        $this->assertStringContainsString('der Rahmen bliebe leer', $generator->body);
        $this->assertStringNotContainsString(
            '<iframe src=',
            $generator->body,
            'Ohne freigegebene Domain darf keine Live-Vorschau gerendert werden'
        );

        // 5. Deaktivieren lässt sich das Addon wieder.
        $aus = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '0',
        ]);
        $this->assertSame('/admin/plugins?success=1', $aus->location());

        $nachAus = $admin->get('/plugin/embed-widget/generator');
        $this->assertSame(404, $nachAus->statusCode, 'Nach dem Deaktivieren darf die Route nicht mehr existieren');
    }
}
