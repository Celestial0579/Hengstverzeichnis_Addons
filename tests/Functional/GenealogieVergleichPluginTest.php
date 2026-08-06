<?php
// tests/Functional/GenealogieVergleichPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/genealogie-vergleich gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 *
 * Nutzt denselben Vollgeschwister-Aufbau wie InzuchtkoeffizientPluginTest
 * (zwei unverwandte Großeltern A/B, zwei Nachkommen C/D aus A x B) - C und D
 * haben exakt zwei gemeinsame Vorfahren (A und B).
 */
class GenealogieVergleichPluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'genealogie-vergleich';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Genealogie-Vergleichstool', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        $unique = uniqid();
        $aId = $this->createHorse($admin, "GVA-{$unique}", ['status' => 'active']);
        $bId = $this->createHorse($admin, "GVB-{$unique}", ['status' => 'active']);
        $cId = $this->createHorse($admin, "GVC-{$unique}", ['status' => 'active', 'sire_id' => (string) $aId, 'dam_id' => (string) $bId]);
        $dId = $this->createHorse($admin, "GVD-{$unique}", ['status' => 'active', 'sire_id' => (string) $aId, 'dam_id' => (string) $bId]);
        $eId = $this->createHorse($admin, "GVE-{$unique}", ['status' => 'active']);

        $visitor = $this->newClient();

        // 1. Detailseite von C enthält den Link zum Vergleichstool, vorbelegt mit C.
        $detailC = $visitor->get("/hengst?id={$cId}");
        $this->assertStringContainsString("/plugin/genealogie-vergleich?horse_a={$cId}", $detailC->body);

        // 2. Ohne beide Auswahlen: Hinweistext, kein Vergleich.
        $onlyA = $visitor->get("/plugin/genealogie-vergleich?horse_a={$cId}");
        $this->assertSame(200, $onlyA->statusCode);
        $this->assertStringContainsString('Bitte beide Pferde auswählen', $onlyA->body);

        // 3. C vs. D: zwei gemeinsame Vorfahren (A und B), beide als "shared" markiert.
        $comparisonCD = $visitor->get("/plugin/genealogie-vergleich?horse_a={$cId}&horse_b={$dId}");
        $this->assertSame(200, $comparisonCD->statusCode);
        $this->assertStringContainsString("GVC-{$unique}", $comparisonCD->body);
        $this->assertStringContainsString("GVD-{$unique}", $comparisonCD->body);
        $this->assertStringContainsString('Gemeinsame Vorfahren gefunden: 2', $comparisonCD->body);
        $this->assertSame(
            4,
            substr_count($comparisonCD->body, 'box shared'),
            'A und B sollten je einmal pro Spalte (also insgesamt 4x) als "shared" markiert sein.'
        );

        // 4. C vs. E: keine gemeinsamen Vorfahren.
        $comparisonCE = $visitor->get("/plugin/genealogie-vergleich?horse_a={$cId}&horse_b={$eId}");
        $this->assertSame(200, $comparisonCE->statusCode);
        $this->assertStringContainsString('Keine gemeinsamen Vorfahren', $comparisonCE->body);
        $this->assertStringNotContainsString('box shared', $comparisonCE->body);

        // 5. Sicherheit: ein UNVERÖFFENTLICHTES Pferd (is_published = 0) darf weder
        // im öffentlichen Auswahl-Dropdown erscheinen noch über einen direkten
        // Vergleich sichtbar werden - sonst würden im Kern verborgene Daten leaken.
        $unpubName = "GVUnpub-{$unique}";
        $unpubId = $this->createHorse($admin, $unpubName, ['status' => 'active', 'is_published' => '0']);

        $toolPage = $visitor->get('/plugin/genealogie-vergleich');
        $this->assertSame(200, $toolPage->statusCode);
        $this->assertStringNotContainsString(
            $unpubName,
            $toolPage->body,
            'Unveröffentlichtes Pferd darf nicht im öffentlichen Auswahl-Dropdown erscheinen.'
        );

        $compareUnpub = $visitor->get("/plugin/genealogie-vergleich?horse_a={$cId}&horse_b={$unpubId}");
        $this->assertSame(200, $compareUnpub->statusCode);
        $this->assertStringNotContainsString(
            $unpubName,
            $compareUnpub->body,
            'Vergleich mit einem unveröffentlichten Pferd darf dessen Daten nicht offenlegen.'
        );
    }
}
