<?php
// tests/Functional/InzuchtkoeffizientPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/inzuchtkoeffizient gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 *
 * Baut einen klassischen Vollgeschwister-Verpaarungsfall auf (zwei
 * untereinander unverwandte Großeltern A/B - jeweils mit eigenen Eltern, siehe
 * Regressionshinweis zu Issue #25 im Testkörper -, zwei Nachkommen C/D aus
 * A x B, ein Fohlen E aus C x D) - der erwartete Inzuchtkoeffizient von E ist
 * exakt 25 % (zwei gemeinsame Vorfahren A und B, je ein Pfad mit n1=n2=1:
 * 2 x (0,5)^3 = 0,25).
 * Deckt sowohl den automatischen Abschnitt auf der Detailseite als auch den
 * Verpaarungsrechner samt Berechtigungsdurchsetzung ab.
 */
class InzuchtkoeffizientPluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'inzuchtkoeffizient';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString(
            // Apostroph wird von der Admin-Ansicht HTML-escaped ausgegeben (&#039;).
            'Wright&#039;s COI',
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

        // Regression zu Issue #25: Die gemeinsamen Vorfahren A und B erhalten
        // selbst je zwei Eltern(-Generationen). Nach Wrights Pfadregel darf das
        // den COI von E NICHT verändern (die Ahnen von A/B sind nur DURCH A/B
        // hindurch erreichbar, ihr Beitrag steckt allein im hier bewusst
        // weggelassenen Term (1+F_A)) - die fehlerhafte Implementierung
        // summierte sie mit und lieferte 48,44 % statt 25,00 %.
        $aSireId = $this->createHorse($admin, "A-Vater-{$unique}", ['status' => 'active']);
        $aDamId = $this->createHorse($admin, "A-Mutter-{$unique}", ['status' => 'active']);
        $bSireId = $this->createHorse($admin, "B-Vater-{$unique}", ['status' => 'active']);
        $bDamId = $this->createHorse($admin, "B-Mutter-{$unique}", ['status' => 'active']);

        $aId = $this->createHorse($admin, "A-{$unique}", ['status' => 'active', 'sire_id' => (string) $aSireId, 'dam_id' => (string) $aDamId]);
        $bId = $this->createHorse($admin, "B-{$unique}", ['status' => 'active', 'sire_id' => (string) $bSireId, 'dam_id' => (string) $bDamId]);
        $cId = $this->createHorse($admin, "C-{$unique}", ['status' => 'active', 'sire_id' => (string) $aId, 'dam_id' => (string) $bId]);
        $dId = $this->createHorse($admin, "D-{$unique}", ['status' => 'active', 'sire_id' => (string) $aId, 'dam_id' => (string) $bId]);
        $eId = $this->createHorse($admin, "E-{$unique}", ['status' => 'active', 'sire_id' => (string) $cId, 'dam_id' => (string) $dId]);

        // 1. Öffentliche Detailseite des Fohlens E zeigt automatisch 25,00 %.
        $visitor = $this->newClient();
        $detailPage = $visitor->get("/hengst?id={$eId}");
        $this->assertSame(200, $detailPage->statusCode);
        $this->assertStringContainsString(
            '25,00 %',
            $detailPage->body,
            "Detailseite von E sollte den berechneten Inzuchtkoeffizienten von 25,00 % enthalten. Body: {$detailPage->body}"
        );

        // 2. Ein Pferd ohne gemeinsame Vorfahren beider Elternseiten (A) hat COI 0,00 %.
        $unrelatedDetail = $visitor->get("/hengst?id={$aId}");
        $this->assertStringContainsString('0,00 %', $unrelatedDetail->body);

        // 3. Verpaarungsrechner: Admin hat serverseitig immer alle Berechtigungen.
        $calcResponse = $admin->get("/plugin/inzuchtkoeffizient/rechner?sire_id={$cId}&dam_id={$dId}");
        $this->assertSame(200, $calcResponse->statusCode);
        $this->assertStringContainsString('25,00 %', $calcResponse->body);

        // 4. Berechtigungsdurchsetzung: Editor ohne inzuchtkoeffizient.calculate wird abgewiesen ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "coitester{$unique}",
            "inzuchtkoeffizient-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/inzuchtkoeffizient/rechner');
        $this->assertSame(
            403,
            $deniedResponse->statusCode,
            'Ohne inzuchtkoeffizient.calculate sollte die Plugin-Route 403 liefern.'
        );

        // ... und ist nach Zuweisung der Berechtigung erreichbar.
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'inzuchtkoeffizient' => ['calculate'],
        ]);

        $allowedResponse = $editor->get("/plugin/inzuchtkoeffizient/rechner?sire_id={$cId}&dam_id={$dId}");
        $this->assertSame(200, $allowedResponse->statusCode);
        $this->assertStringContainsString('25,00 %', $allowedResponse->body);
    }
}
