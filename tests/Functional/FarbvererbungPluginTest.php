<?php
// tests/Functional/FarbvererbungPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/farbvererbung gegen eine echte, per `php -S`
 * gestartete Hengstverzeichnis_Framework-Instanz (siehe tests/bootstrap.php und
 * Tests\Functional\FunctionalTestCase).
 *
 * Deckt ab: Aktivierung über /admin/plugins, die genetische Einordnung der
 * eingetragenen Farbe auf der öffentlichen Detailseite (horse.detail_sections),
 * den Farbrechner mit einem eindeutigen Kreuzungsfall (Rotfalbe × Rotfalbe →
 * 100 % Rotfalbe, da ee × ee immer ee ergibt) sowie die Durchsetzung der selbst
 * registrierten Berechtigung farbvererbung.calculate.
 */
class FarbvererbungPluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'farbvererbung';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString(
            'Fjord-Farbvererbungsrechner',
            $pluginsPage->body,
            'Plugin sollte unter /admin/plugins als entdeckt gelistet sein - wurde es nach vendor/hengstverzeichnis/framework/plugins kopiert?'
        );

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        $unique = uniqid();

        // 1. Detailseite: ein Pferd mit erkennbarer Farbe zeigt die genetische
        // Einordnung der Falbfarbe.
        $horseId = $this->createHorse($admin, "Farbe-{$unique}", [
            'status' => 'active',
            'color' => 'Rotfalbe',
        ]);
        $visitor = $this->newClient();
        $detailPage = $visitor->get("/hengst?id={$horseId}");
        $this->assertSame(200, $detailPage->statusCode);
        $this->assertStringContainsString(
            'Rotfalbe (Rødblakk)',
            $detailPage->body,
            "Detailseite sollte die erkannte Falbfarbe anzeigen. Body: {$detailPage->body}"
        );

        // 2. Farbrechner: Rotfalbe × Rotfalbe ergibt exakt 100 % Rotfalbe.
        $calcResponse = $admin->get('/plugin/farbvererbung/rechner?sire_color=rodblakk&dam_color=rodblakk');
        $this->assertSame(200, $calcResponse->statusCode);
        $this->assertStringContainsString('100,00 %', $calcResponse->body);
        $this->assertStringContainsString('Rotfalbe (Rødblakk)', $calcResponse->body);

        // 3. Berechtigungsdurchsetzung: Editor ohne farbvererbung.calculate -> 403 ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "farbtester{$unique}",
            "farbvererbung-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/farbvererbung/rechner');
        $this->assertSame(
            403,
            $deniedResponse->statusCode,
            'Ohne farbvererbung.calculate sollte die Plugin-Route 403 liefern.'
        );

        // ... und ist nach Zuweisung der Berechtigung erreichbar.
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'farbvererbung' => ['calculate'],
        ]);

        $allowedResponse = $editor->get('/plugin/farbvererbung/rechner?sire_color=rodblakk&dam_color=rodblakk');
        $this->assertSame(200, $allowedResponse->statusCode);
        $this->assertStringContainsString('100,00 %', $allowedResponse->body);
    }
}
