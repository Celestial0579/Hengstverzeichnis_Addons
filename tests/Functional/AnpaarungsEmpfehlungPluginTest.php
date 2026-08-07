<?php
// tests/Functional/AnpaarungsEmpfehlungPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/anpaarungs-empfehlung gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 *
 * Aufbau: zwei unverwandte Großeltern (GpA/GpB), ein Basispferd und ein
 * Vollgeschwister daraus (Base bzw. AAsib aus GpA x GpB) sowie ein völlig
 * unverwandtes Pferd (ZZfree). Erwartung:
 *   - Base x ZZfree -> Fohlen-COI 0 %  (keine gemeinsamen Vorfahren)
 *   - Base x AAsib  -> Fohlen-COI 25 % (zwei gemeinsame Vorfahren, je n1=n2=1)
 * Das Ranking muss daher ZZfree (0 %) VOR AAsib (25 %) listen - obwohl AAsib
 * alphabetisch früher steht (die Auswahl-Dropdowns sind nach Name sortiert).
 * Die Reihenfolge wird deshalb bewusst nur innerhalb der Ergebnistabelle
 * (<tbody>) geprüft, nicht über die gesamte Seite. Deckt zusätzlich die
 * Durchsetzung der Berechtigung anpaarung.recommend ab.
 */
class AnpaarungsEmpfehlungPluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'anpaarungs-empfehlung';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString(
            'Anpaarungs-Empfehlung',
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
        $gpA = $this->createHorse($admin, "GpA-{$unique}", ['status' => 'active']);
        $gpB = $this->createHorse($admin, "GpB-{$unique}", ['status' => 'active']);
        $baseId = $this->createHorse($admin, "Base-{$unique}", ['status' => 'active', 'sire_id' => (string) $gpA, 'dam_id' => (string) $gpB]);
        // Vollgeschwister des Basispferds - alphabetisch bewusst FRÜH ("AA...").
        $sibId = $this->createHorse($admin, "AAsib-{$unique}", ['status' => 'active', 'sire_id' => (string) $gpA, 'dam_id' => (string) $gpB]);
        // Unverwandtes Pferd - alphabetisch bewusst SPÄT ("ZZ...").
        $freeId = $this->createHorse($admin, "ZZfree-{$unique}", ['status' => 'active']);

        $response = $admin->get("/plugin/anpaarungs-empfehlung/empfehlung?base_id={$baseId}");
        $this->assertSame(200, $response->statusCode);

        $this->assertStringContainsString('0,00 %', $response->body, 'Unverwandte Verpaarung sollte 0,00 % zeigen.');
        $this->assertStringContainsString('25,00 %', $response->body, 'Vollgeschwister-Verpaarung sollte 25,00 % zeigen.');

        // Nur die Ergebnistabelle betrachten (die Auswahl-Dropdowns davor sind
        // nach Name sortiert und würden die COI-Sortierung sonst verfälschen).
        $table = strstr($response->body, '<tbody>');
        $this->assertIsString($table, "Ergebnistabelle (<tbody>) nicht gefunden. Body: {$response->body}");

        $posFree = strpos($table, "ZZfree-{$unique}");
        $posSib = strpos($table, "AAsib-{$unique}");
        $this->assertNotFalse($posFree, 'Unverwandtes Pferd fehlt in der Empfehlungstabelle.');
        $this->assertNotFalse($posSib, 'Vollgeschwister-Pferd fehlt in der Empfehlungstabelle.');
        $this->assertLessThan(
            $posSib,
            $posFree,
            'Ranking muss die genetisch vielfältigste (COI 0 %) Verpaarung vor der Vollgeschwister-Verpaarung (25 %) listen.'
        );

        // Das Basispferd selbst darf nicht als Partner-Vorschlag erscheinen.
        $this->assertStringNotContainsString(
            "Base-{$unique}",
            $table,
            'Das Basispferd darf nicht als eigener Partner-Vorschlag in der Tabelle stehen.'
        );

        // Berechtigungsdurchsetzung: Editor ohne anpaarung.recommend -> 403 ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "anptester{$unique}",
            "anpaarung-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/anpaarungs-empfehlung/empfehlung');
        $this->assertSame(
            403,
            $deniedResponse->statusCode,
            'Ohne anpaarung.recommend sollte die Plugin-Route 403 liefern.'
        );

        // ... und ist nach Zuweisung der Berechtigung erreichbar.
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'anpaarung' => ['recommend'],
        ]);

        $allowedResponse = $editor->get("/plugin/anpaarungs-empfehlung/empfehlung?base_id={$baseId}");
        $this->assertSame(200, $allowedResponse->statusCode);
        $this->assertStringContainsString('25,00 %', $allowedResponse->body);
    }
}
