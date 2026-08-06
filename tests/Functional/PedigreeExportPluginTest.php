<?php
// tests/Functional/PedigreeExportPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/pedigree-export gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 */
class PedigreeExportPluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'pedigree-export';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Pedigree-Export', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        // 1. Elternpferd anlegen, dann Fohlen mit Verweis darauf (sire_id),
        // um mindestens eine Generation im Export sichtbar zu haben.
        $unique = uniqid();
        $sireName = "PdfVater-{$unique}";
        $sireId = $this->createHorse($admin, $sireName, ['status' => 'active']);
        $foalName = "PdfFohlen-{$unique}";
        $foalId = $this->createHorse($admin, $foalName, ['status' => 'active', 'sire_id' => (string) $sireId]);

        // 2. Öffentliche Detailseite des Fohlens enthält den Export-Link
        // (unangemeldeter Besucher, wie auf der echten Detailseite üblich).
        $visitor = $this->newClient();
        $detailPage = $visitor->get("/hengst?id={$foalId}");
        $this->assertSame(200, $detailPage->statusCode);
        $this->assertStringContainsString(
            "/plugin/pedigree-export/export?id={$foalId}",
            $detailPage->body,
            "Detailseite sollte den vom Plugin ergänzten Export-Link enthalten. Body: {$detailPage->body}"
        );

        // 3. Export-Ansicht selbst ist öffentlich erreichbar (keine Rechteausweitung
        // gegenüber der ohnehin öffentlichen Detailseite) und enthält Fohlen + Vater.
        $exportPage = $visitor->get("/plugin/pedigree-export/export?id={$foalId}");
        $this->assertSame(200, $exportPage->statusCode);
        $this->assertStringContainsString($foalName, $exportPage->body);
        $this->assertStringContainsString($sireName, $exportPage->body);
        $this->assertStringContainsString('window.print()', $exportPage->body);

        // 4. Unbekannte/ungültige ID liefert 404 statt eines Fehlers.
        $notFound = $visitor->get('/plugin/pedigree-export/export?id=0');
        $this->assertSame(404, $notFound->statusCode);
    }
}
