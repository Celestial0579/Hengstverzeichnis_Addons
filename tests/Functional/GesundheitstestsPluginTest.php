<?php
// tests/Functional/GesundheitstestsPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/gesundheitstests gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 *
 * Der Datei-Upload selbst wird hier nicht durchgespielt (der Test-HttpClient
 * sendet keine multipart-Anfragen) - abgedeckt sind das Opt-in-Prinzip der
 * öffentlichen Sichtbarkeit, die Berechtigungsdurchsetzung der Verwaltung
 * und das 404-Verhalten der Download-Route für unbekannte/dokumentlose
 * Einträge.
 */
class GesundheitstestsPluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'gesundheitstests';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('DNA-/Gesundheitstest-Verwaltung', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        // 1. Dashboard-Kachel muss erscheinen.
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString('/plugin/gesundheitstests/verwaltung', $dashboard->body);

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "GesundheitTestPferd-{$unique}", ['status' => 'active']);

        // 2. Eintrag OHNE Öffentlich-Flag anlegen: erscheint NICHT auf der
        // öffentlichen Detailseite (Opt-in-Prinzip, Standard aus).
        $verwaltungPage = $admin->get('/plugin/gesundheitstests/verwaltung');
        $this->assertSame(200, $verwaltungPage->statusCode);

        $privateType = "Röntgen-{$unique}";
        $storePrivate = $admin->post('/plugin/gesundheitstests/verwaltung/store', [
            'csrf_token' => $verwaltungPage->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'test_type' => $privateType,
            'result_summary' => 'Ohne Befund.',
        ]);
        $this->assertSame('/plugin/gesundheitstests/verwaltung', $storePrivate->location());

        $visitor = $this->newClient();
        $detailPrivate = $visitor->get("/horse?id={$horseId}");
        $this->assertSame(200, $detailPrivate->statusCode);
        $this->assertStringNotContainsString(
            $privateType,
            $detailPrivate->body,
            'Nicht freigegebene Gesundheitsdaten dürfen nie öffentlich erscheinen (Opt-in).'
        );

        // 3. Eintrag MIT Öffentlich-Flag anlegen: erscheint auf der Detailseite.
        $publicType = "DNA-Abstammungstest-{$unique}";
        $storePublic = $admin->post('/plugin/gesundheitstests/verwaltung/store', [
            'csrf_token' => $verwaltungPage->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'test_type' => $publicType,
            'result_summary' => 'Abstammung bestätigt.',
            'issued_by' => 'Testlabor',
            'is_public' => '1',
        ]);
        $this->assertSame('/plugin/gesundheitstests/verwaltung', $storePublic->location());

        $detailPublic = $visitor->get("/horse?id={$horseId}");
        $this->assertStringContainsString('DNA-/Gesundheitstests', $detailPublic->body);
        $this->assertStringContainsString($publicType, $detailPublic->body);
        $this->assertStringContainsString('Abstammung bestätigt.', $detailPublic->body);
        $this->assertStringNotContainsString($privateType, $detailPublic->body);

        // 4. Download-Route: unbekannte ID und Eintrag ohne Dokument liefern
        // identisch 404 (kein Existenz-Orakel).
        $unknownDownload = $visitor->get('/plugin/gesundheitstests/download?id=999999');
        $this->assertSame(404, $unknownDownload->statusCode);

        preg_match('/name="id" value="(\d+)"/', $admin->get('/plugin/gesundheitstests/verwaltung')->body, $idMatch);
        $this->assertNotEmpty($idMatch, 'Konnte ID eines erfassten Eintrags nicht ermitteln.');
        $noFileDownload = $visitor->get('/plugin/gesundheitstests/download?id=' . $idMatch[1]);
        $this->assertSame(404, $noFileDownload->statusCode);

        // 5. Berechtigungsdurchsetzung: Editor ohne gesundheitstests.manage
        // wird abgewiesen ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "gtester{$unique}",
            "gesundheitstests-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/gesundheitstests/verwaltung');
        $this->assertSame(403, $deniedResponse->statusCode);

        // ... und ist nach Zuweisung der Berechtigung erreichbar.
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'gesundheitstests' => ['manage'],
        ]);

        $allowedResponse = $editor->get('/plugin/gesundheitstests/verwaltung');
        $this->assertSame(200, $allowedResponse->statusCode);

        // 6. Löschen entfernt den öffentlichen Eintrag wieder von der Detailseite.
        $verwaltungAfter = $admin->get('/plugin/gesundheitstests/verwaltung');
        preg_match_all('/name="id" value="(\d+)"/', $verwaltungAfter->body, $allIds);
        foreach ($allIds[1] as $entryId) {
            $deleteResponse = $admin->post('/plugin/gesundheitstests/verwaltung/delete', [
                'csrf_token' => $verwaltungAfter->formField('csrf_token') ?? '',
                'id' => $entryId,
            ]);
            $this->assertSame('/plugin/gesundheitstests/verwaltung', $deleteResponse->location());
        }

        $detailAfterDelete = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('DNA-/Gesundheitstests', $detailAfterDelete->body);
    }
}
