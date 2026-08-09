<?php
// tests/Functional/GaleriePluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/galerie gegen eine echte, per `php -S`
 * gestartete Hengstverzeichnis_Framework-Instanz (siehe tests/bootstrap.php
 * und Tests\Functional\FunctionalTestCase).
 *
 * Der Foto-Upload selbst wird hier nicht durchgespielt (der Test-HttpClient
 * sendet keine multipart-Anfragen) - abgedeckt sind der Video-Link-Pfad
 * inkl. Host-/Schema-Validierung, die öffentliche Galerie-Sektion und die
 * Berechtigungsdurchsetzung der Verwaltung.
 */
class GaleriePluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'galerie';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Foto-/Video-Galerie', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        // 1. Dashboard-Kachel muss erscheinen.
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString('/plugin/galerie/verwaltung', $dashboard->body);

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "GalerieTestPferd-{$unique}", ['status' => 'active']);

        // 2. Ohne Medien: keine Galerie-Sektion auf der Detailseite.
        $visitor = $this->newClient();
        $detailBefore = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('Galerie</h3>', $detailBefore->body);

        // 3. Video-Link (YouTube, https) hinzufügen.
        $verwaltungPage = $admin->get('/plugin/galerie/verwaltung');
        $this->assertSame(200, $verwaltungPage->statusCode);

        $videoUrl = 'https://www.youtube.com/watch?v=test' . $unique;
        $storeVideo = $admin->post('/plugin/galerie/verwaltung/store', [
            'csrf_token' => $verwaltungPage->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'video_url' => $videoUrl,
            'caption' => "Freispringen {$unique}",
        ]);
        $this->assertSame('/plugin/galerie/verwaltung', $storeVideo->location());

        $detailWithVideo = $visitor->get("/horse?id={$horseId}");
        $this->assertStringContainsString('Galerie', $detailWithVideo->body);
        $this->assertStringContainsString("Freispringen {$unique}", $detailWithVideo->body);
        $this->assertStringContainsString(
            htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8'),
            $detailWithVideo->body
        );

        // 4. Unerlaubte Video-URLs (fremder Host bzw. http statt https)
        // werden verworfen und erscheinen nirgends.
        foreach (['https://evil.example/video.mp4', 'http://www.youtube.com/watch?v=insecure' . $unique] as $badUrl) {
            $storeBad = $admin->post('/plugin/galerie/verwaltung/store', [
                'csrf_token' => $verwaltungPage->formField('csrf_token') ?? '',
                'horse_id' => (string) $horseId,
                'video_url' => $badUrl,
            ]);
            $this->assertSame('/plugin/galerie/verwaltung', $storeBad->location());
        }

        $verwaltungAfterBad = $admin->get('/plugin/galerie/verwaltung');
        $this->assertStringNotContainsString('evil.example', $verwaltungAfterBad->body);
        $this->assertStringNotContainsString('insecure' . $unique, $verwaltungAfterBad->body);

        // 5. Berechtigungsdurchsetzung: Editor ohne galerie.manage wird
        // abgewiesen ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "galtester{$unique}",
            "galerie-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/galerie/verwaltung');
        $this->assertSame(403, $deniedResponse->statusCode);

        // ... und ist nach Zuweisung der Berechtigung erreichbar.
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'galerie' => ['manage'],
        ]);

        $allowedResponse = $editor->get('/plugin/galerie/verwaltung');
        $this->assertSame(200, $allowedResponse->statusCode);

        // 6. Löschen entfernt das Medium wieder von der Detailseite.
        preg_match('/name="id" value="(\d+)"/', $verwaltungAfterBad->body, $idMatch);
        $this->assertNotEmpty($idMatch, 'Konnte ID des erfassten Mediums nicht ermitteln.');

        $deleteResponse = $admin->post('/plugin/galerie/verwaltung/delete', [
            'csrf_token' => $verwaltungAfterBad->formField('csrf_token') ?? '',
            'id' => $idMatch[1],
        ]);
        $this->assertSame('/plugin/galerie/verwaltung', $deleteResponse->location());

        $detailAfterDelete = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString("Freispringen {$unique}", $detailAfterDelete->body);
    }
}
