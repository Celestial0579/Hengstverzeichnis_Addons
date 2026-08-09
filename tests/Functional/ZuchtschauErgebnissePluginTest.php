<?php
// tests/Functional/ZuchtschauErgebnissePluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/zuchtschau-ergebnisse gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 */
class ZuchtschauErgebnissePluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'zuchtschau-ergebnisse';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Zuchtschau-/Körungs-Ergebnisverwaltung', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        $unique = uniqid();
        $horseName = "ZSTestPferd-{$unique}";
        $horseId = $this->createHorse($admin, $horseName, ['status' => 'active']);

        // 1. Vor dem Erfassen eines Ergebnisses: kein Ergebnis-Abschnitt auf der Detailseite.
        $visitor = $this->newClient();
        $detailBefore = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('Zuchtschau-/Körungsergebnisse', $detailBefore->body);

        // 2. Ergebnis über die Admin-Route erfassen.
        $indexPage = $admin->get('/plugin/zuchtschau-ergebnisse/ergebnisse');
        $this->assertSame(200, $indexPage->statusCode);

        $eventName = "Bundeschampionat-{$unique}";
        $storeResponse = $admin->post('/plugin/zuchtschau-ergebnisse/ergebnisse/store', [
            'csrf_token' => $indexPage->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'event_name' => $eventName,
            'event_date' => '2024-06-15',
            'category' => 'Körung',
            'score' => '8,5',
            'judge' => 'Dr. Testrichter',
            'placement' => '1. Platz',
            'comment' => 'Hervorragende Bewegungsqualität.',
        ]);
        $this->assertSame('/plugin/zuchtschau-ergebnisse/ergebnisse', $storeResponse->location());

        // 3. Admin-Übersicht enthält das neue Ergebnis.
        $indexAfter = $admin->get('/plugin/zuchtschau-ergebnisse/ergebnisse');
        $this->assertStringContainsString($eventName, $indexAfter->body);
        $this->assertStringContainsString($horseName, $indexAfter->body);

        // 4. Öffentliche Detailseite zeigt das Ergebnis jetzt automatisch an.
        $detailAfter = $visitor->get("/horse?id={$horseId}");
        $this->assertStringContainsString('Zuchtschau-/Körungsergebnisse', $detailAfter->body);
        $this->assertStringContainsString($eventName, $detailAfter->body);
        $this->assertStringContainsString('Dr. Testrichter', $detailAfter->body);
        $this->assertStringContainsString('Hervorragende Bewegungsqualität.', $detailAfter->body);

        // 5. Berechtigungsdurchsetzung: Editor ohne zuchtschau-ergebnisse.manage wird abgewiesen ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "zstester{$unique}",
            "zuchtschau-ergebnisse-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/zuchtschau-ergebnisse/ergebnisse');
        $this->assertSame(403, $deniedResponse->statusCode);

        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'zuchtschau-ergebnisse' => ['manage'],
        ]);

        $allowedResponse = $editor->get('/plugin/zuchtschau-ergebnisse/ergebnisse');
        $this->assertSame(200, $allowedResponse->statusCode);
        $this->assertStringContainsString($eventName, $allowedResponse->body);

        // 6. Löschen entfernt das Ergebnis wieder von der Detailseite.
        preg_match('/name="id" value="(\d+)"/', $indexAfter->body, $idMatch);
        $this->assertNotEmpty($idMatch, 'Konnte ID des erfassten Ergebnisses nicht ermitteln.');

        $deleteResponse = $admin->post('/plugin/zuchtschau-ergebnisse/ergebnisse/delete', [
            'csrf_token' => $indexAfter->formField('csrf_token') ?? '',
            'id' => $idMatch[1],
        ]);
        $this->assertSame('/plugin/zuchtschau-ergebnisse/ergebnisse', $deleteResponse->location());

        $detailAfterDelete = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('Zuchtschau-/Körungsergebnisse', $detailAfterDelete->body);
    }
}
