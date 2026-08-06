<?php
// tests/Functional/VerkaufsboersePluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/verkaufsboerse gegen eine echte, per `php -S`
 * gestartete Hengstverzeichnis_Framework-Instanz (siehe tests/bootstrap.php
 * und Tests\Functional\FunctionalTestCase).
 *
 * Kein SMTP ist in der Testumgebung konfiguriert - wie bei
 * DeckanfragePluginTest liefert App\Service\Mailer::send() daher
 * kontrolliert `false`, die reale Anfrage erwartet folglich das Ergebnis
 * "fehler", nicht "erfolg" (siehe dortiger Kommentar für die Begründung).
 */
class VerkaufsboersePluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'verkaufsboerse';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Verkaufs-/Vermittlungsbörse', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        // 1. Dashboard-Kachel muss erscheinen.
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString('/plugin/verkaufsboerse/verwaltung', $dashboard->body);

        $unique = uniqid();
        $horseName = "VerkaufTestPferd-{$unique}";
        $horseId = $this->createHorse($admin, $horseName, ['status' => 'active']);
        $contactEmail = "verkauf-{$unique}@example.test";

        // 2. Vor dem Anlegen eines Inserats: kein Badge auf der Detailseite,
        // keine Einträge in der öffentlichen Übersicht.
        $visitor = $this->newClient();
        $detailBefore = $visitor->get("/hengst?id={$horseId}");
        $this->assertStringNotContainsString('Zum Verkauf', $detailBefore->body);

        // 3. Inserat über die Admin-Route anlegen.
        $verwaltungPage = $admin->get('/plugin/verkaufsboerse/verwaltung');
        $this->assertSame(200, $verwaltungPage->statusCode);

        $storeResponse = $admin->post('/plugin/verkaufsboerse/verwaltung/store', [
            'csrf_token' => $verwaltungPage->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'price' => '1500.00',
            'description' => 'Verkauf wegen Bestandsreduzierung.',
            'contact_email' => $contactEmail,
        ]);
        $this->assertSame('/plugin/verkaufsboerse/verwaltung', $storeResponse->location());

        // 4. Admin-Übersicht enthält das neue Inserat.
        $verwaltungAfter = $admin->get('/plugin/verkaufsboerse/verwaltung');
        $this->assertStringContainsString($horseName, $verwaltungAfter->body);
        $this->assertStringContainsString($contactEmail, $verwaltungAfter->body);
        $this->assertStringContainsString('1.500,00 €', $verwaltungAfter->body);

        // 5. Öffentliche Übersicht zeigt das Inserat.
        $listePage = $visitor->get('/plugin/verkaufsboerse/liste');
        $this->assertSame(200, $listePage->statusCode);
        $this->assertStringContainsString($horseName, $listePage->body);
        $this->assertStringContainsString('1.500,00 €', $listePage->body);

        // 6. Detailseite zeigt jetzt automatisch das Badge inkl. Preis und Formular.
        $detailAfter = $visitor->get("/hengst?id={$horseId}");
        $this->assertStringContainsString('Zum Verkauf', $detailAfter->body);
        $this->assertStringContainsString('1.500,00 €', $detailAfter->body);
        $this->assertStringContainsString('Verkauf wegen Bestandsreduzierung.', $detailAfter->body);
        $this->assertStringContainsString('name="webseite"', $detailAfter->body);

        // 7. Honeypot ausgefüllt: wird stillschweigend als "erfolg" behandelt.
        $csrfToken = $detailAfter->formField('csrf_token') ?? '';
        $honeypotResponse = $visitor->post('/plugin/verkaufsboerse/kontakt', [
            'csrf_token' => $csrfToken,
            'horse_id' => (string) $horseId,
            'requester_name' => 'Bot',
            'requester_email' => 'bot@example.test',
            'message' => 'Spam',
            'webseite' => 'https://spam.example',
        ]);
        $this->assertSame("/hengst?id={$horseId}&verkaufsanfrage=erfolg", $honeypotResponse->location());

        // 8. Echte Anfrage: Versand schlägt mangels SMTP-Konfiguration kontrolliert fehl.
        $realResponse = $visitor->post('/plugin/verkaufsboerse/kontakt', [
            'csrf_token' => $csrfToken,
            'horse_id' => (string) $horseId,
            'requester_name' => 'Kaufinteressent',
            'requester_email' => 'kaufinteressent@example.test',
            'message' => 'Ist der Preis verhandelbar?',
        ]);
        $this->assertSame("/hengst?id={$horseId}&verkaufsanfrage=fehler", $realResponse->location());

        // 9. CSRF-Schutz.
        $csrfRejected = $visitor->post('/plugin/verkaufsboerse/kontakt', [
            'csrf_token' => 'invalid-token',
            'horse_id' => (string) $horseId,
            'requester_name' => 'Test',
            'requester_email' => 'test@example.test',
            'message' => 'Test',
        ]);
        $this->assertSame(403, $csrfRejected->statusCode);

        // 10. Berechtigungsdurchsetzung: Editor ohne verkaufsboerse.manage wird abgewiesen ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "vbtester{$unique}",
            "verkaufsboerse-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/verkaufsboerse/verwaltung');
        $this->assertSame(403, $deniedResponse->statusCode);

        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'verkaufsboerse' => ['manage'],
        ]);

        $allowedResponse = $editor->get('/plugin/verkaufsboerse/verwaltung');
        $this->assertSame(200, $allowedResponse->statusCode);

        // 11. Löschen entfernt das Inserat wieder aus Übersicht und Detailseite.
        preg_match('/name="id" value="(\d+)"/', $verwaltungAfter->body, $idMatch);
        $this->assertNotEmpty($idMatch, 'Konnte ID des erfassten Inserats nicht ermitteln.');

        $deleteResponse = $admin->post('/plugin/verkaufsboerse/verwaltung/delete', [
            'csrf_token' => $verwaltungAfter->formField('csrf_token') ?? '',
            'id' => $idMatch[1],
        ]);
        $this->assertSame('/plugin/verkaufsboerse/verwaltung', $deleteResponse->location());

        $detailAfterDelete = $visitor->get("/hengst?id={$horseId}");
        $this->assertStringNotContainsString('Zum Verkauf', $detailAfterDelete->body);
    }
}
