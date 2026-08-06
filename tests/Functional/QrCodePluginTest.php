<?php
// tests/Functional/QrCodePluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/qr-code gegen eine echte, per `php -S`
 * gestartete Hengstverzeichnis_Framework-Instanz (siehe tests/bootstrap.php
 * und Tests\Functional\FunctionalTestCase).
 */
class QrCodePluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'qr-code';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('QR-Code pro Pferdeprofil', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        $unique = uniqid();
        $horseName = "QrTestPferd-{$unique}";
        $horseId = $this->createHorse($admin, $horseName, ['status' => 'active', 'birth_year' => '2019']);

        // 1. Öffentliche Detailseite enthält den QR-Code-Bereich, den Link zum
        // Aushang und lädt die core-vendorte qrcode.js (unangemeldeter Besucher).
        $visitor = $this->newClient();
        $detailPage = $visitor->get("/hengst?id={$horseId}");
        $this->assertSame(200, $detailPage->statusCode);
        $this->assertStringContainsString('QR-Code anzeigen', $detailPage->body);
        $this->assertStringContainsString('/js/qrcode.js', $detailPage->body);
        $this->assertStringContainsString("/plugin/qr-code/aushang?id={$horseId}", $detailPage->body);

        // 2. Aushang-Ansicht ist öffentlich erreichbar und enthält Name + QR-Code-Skript.
        $aushangPage = $visitor->get("/plugin/qr-code/aushang?id={$horseId}");
        $this->assertSame(200, $aushangPage->statusCode);
        $this->assertStringContainsString($horseName, $aushangPage->body);
        $this->assertStringContainsString('new QRCode(', $aushangPage->body);
        $this->assertStringContainsString('/hengst?id=' . $horseId, $aushangPage->body);

        // 3. Unbekannte ID liefert 404.
        $notFound = $visitor->get('/plugin/qr-code/aushang?id=0');
        $this->assertSame(404, $notFound->statusCode);
    }
}
