<?php
// tests/Functional/TitelPraemierungenPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/titel-praemierungen gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 */
class TitelPraemierungenPluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'titel-praemierungen';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        // Bewusst ohne '&' geprüft: das Kaufmanns-Und des Plugin-Namens
        // ("Titel & Prämierungen") erscheint im HTML als Entity.
        $this->assertStringContainsString('Prämierungen', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        // 1. Dashboard-Kachel muss erscheinen (admin.dashboard_tiles-Filter).
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString(
            '/plugin/titel-praemierungen/auszeichnungen',
            $dashboard->body,
            'Dashboard sollte die vom Plugin über admin.dashboard_tiles ergänzte Kachel enthalten.'
        );

        $unique = uniqid();
        $horseName = "TPTestPferd-{$unique}";
        $horseId = $this->createHorse($admin, $horseName, ['status' => 'active']);

        // 2. Vor dem Erfassen: kein Auszeichnungs-Abschnitt auf der Detailseite.
        $visitor = $this->newClient();
        $detailBefore = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('Titel &amp; Prämierungen', $detailBefore->body);

        // 3. Auszeichnung über die Admin-Route erfassen.
        $indexPage = $admin->get('/plugin/titel-praemierungen/auszeichnungen');
        $this->assertSame(200, $indexPage->statusCode);

        $bezeichnung = "Elitehengst-{$unique}";
        $storeResponse = $admin->post('/plugin/titel-praemierungen/auszeichnungen/store', [
            'csrf_token' => $indexPage->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'art' => 'titel',
            'bezeichnung' => $bezeichnung,
            'jahr' => '2019',
            'kommentar' => 'Verliehen auf der Hauptkörung.',
        ]);
        $this->assertSame('/plugin/titel-praemierungen/auszeichnungen', $storeResponse->location());

        // 3b. Eine Art außerhalb der ENUM-Whitelist wird still verworfen.
        $invalidBezeichnung = "Ungueltig-{$unique}";
        $invalidResponse = $admin->post('/plugin/titel-praemierungen/auszeichnungen/store', [
            'csrf_token' => $indexPage->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'art' => 'pokal',
            'bezeichnung' => $invalidBezeichnung,
        ]);
        $this->assertSame('/plugin/titel-praemierungen/auszeichnungen', $invalidResponse->location());

        // 4. Admin-Übersicht enthält die neue Auszeichnung, nicht die verworfene.
        $indexAfter = $admin->get('/plugin/titel-praemierungen/auszeichnungen');
        $this->assertStringContainsString($bezeichnung, $indexAfter->body);
        $this->assertStringContainsString($horseName, $indexAfter->body);
        $this->assertStringNotContainsString($invalidBezeichnung, $indexAfter->body);

        // 5. Öffentliche Detailseite zeigt die Auszeichnung jetzt automatisch an.
        $detailAfter = $visitor->get("/horse?id={$horseId}");
        $this->assertStringContainsString('Titel &amp; Prämierungen', $detailAfter->body);
        $this->assertStringContainsString($bezeichnung, $detailAfter->body);
        $this->assertStringContainsString('2019', $detailAfter->body);
        $this->assertStringContainsString('Verliehen auf der Hauptkörung.', $detailAfter->body);

        // 6. Berechtigungsdurchsetzung: Editor ohne titel-praemierungen.manage wird abgewiesen ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "tptester{$unique}",
            "titel-praemierungen-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/titel-praemierungen/auszeichnungen');
        $this->assertSame(403, $deniedResponse->statusCode);

        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'titel-praemierungen' => ['manage'],
        ]);

        $allowedResponse = $editor->get('/plugin/titel-praemierungen/auszeichnungen');
        $this->assertSame(200, $allowedResponse->statusCode);
        $this->assertStringContainsString($bezeichnung, $allowedResponse->body);

        // 7. Löschen entfernt die Auszeichnung wieder von der Detailseite.
        preg_match('/name="id" value="(\d+)"/', $indexAfter->body, $idMatch);
        $this->assertNotEmpty($idMatch, 'Konnte ID der erfassten Auszeichnung nicht ermitteln.');

        $deleteResponse = $admin->post('/plugin/titel-praemierungen/auszeichnungen/delete', [
            'csrf_token' => $indexAfter->formField('csrf_token') ?? '',
            'id' => $idMatch[1],
        ]);
        $this->assertSame('/plugin/titel-praemierungen/auszeichnungen', $deleteResponse->location());

        $detailAfterDelete = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('Titel &amp; Prämierungen', $detailAfterDelete->body);
    }
}
