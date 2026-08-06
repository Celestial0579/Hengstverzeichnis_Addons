<?php
// tests/Functional/KatalogExportPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/katalog-export gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 */
class KatalogExportPluginTest extends FunctionalTestCase {

    private const SLUG = 'katalog-export';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Katalog-Export', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        // 1. Dashboard-Kachel muss erscheinen.
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString('/plugin/katalog-export/formular', $dashboard->body);

        // 2. Formularseite lädt.
        $formPage = $admin->get('/plugin/katalog-export/formular');
        $this->assertSame(200, $formPage->statusCode);
        $this->assertStringContainsString('Als CSV herunterladen', $formPage->body);

        // 3. Testpferd anlegen, um im Export sicher wiederzufinden.
        $unique = uniqid();
        $horseName = "CsvTestPferd-{$unique}";
        $createForm = $admin->get('/admin/horses/create');
        $createResponse = $admin->post('/admin/horses/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => $horseName,
            'status' => 'active',
            'color' => 'Fuchs',
            'birth_year' => '2021',
        ]);
        $this->assertSame('/admin/horses?success=created', $createResponse->location());

        // 4. Ungefilterter CSV-Export enthält das Testpferd.
        $csvResponse = $admin->get('/plugin/katalog-export/csv');
        $this->assertSame(200, $csvResponse->statusCode);
        $this->assertStringContainsString('text/csv', (string) $csvResponse->header('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $csvResponse->header('Content-Disposition'));
        $this->assertStringContainsString($horseName, $csvResponse->body);
        $this->assertStringContainsString('Fuchs', $csvResponse->body);

        // 5. Gefilterter Export (q_name auf ein garantiert nicht existierendes
        // Pferd) enthält das Testpferd NICHT mehr.
        $filteredResponse = $admin->get('/plugin/katalog-export/csv?q_name=' . urlencode("KeinTreffer-{$unique}"));
        $this->assertSame(200, $filteredResponse->statusCode);
        $this->assertStringNotContainsString($horseName, $filteredResponse->body);

        // 6. Berechtigungsdurchsetzung: Editor ohne katalog-export.export wird abgewiesen ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "csvtester{$unique}",
            "katalog-export-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/katalog-export/csv');
        $this->assertSame(403, $deniedResponse->statusCode);

        // ... und ist nach Zuweisung der Berechtigung erreichbar.
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'katalog-export' => ['export'],
        ]);

        $allowedResponse = $editor->get('/plugin/katalog-export/csv');
        $this->assertSame(200, $allowedResponse->statusCode);
        $this->assertStringContainsString($horseName, $allowedResponse->body);
    }
}
