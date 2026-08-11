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

        // 5. Teilwertungen (#82): ID des Ergebnisses aus der Admin-Übersicht
        // ziehen (erste id-Zeile ist das Lösch-Formular des Ergebnisses,
        // die Teilwertungs-Formulare folgen erst danach im Markup).
        preg_match('/name="id" value="(\d+)"/', $indexAfter->body, $ergebnisIdMatch);
        $this->assertNotEmpty($ergebnisIdMatch, 'Konnte ID des erfassten Ergebnisses nicht ermitteln.');
        $ergebnisId = (int) $ergebnisIdMatch[1];

        // 5a. Teilwertung anlegen - Distanz bewusst leer gelassen: die
        // Fachfelder sind NULL-tolerant (lückige Altdaten aus v1/v2).
        $teilwertungName = "Dressur-{$unique}";
        $twStoreResponse = $admin->post('/plugin/zuchtschau-ergebnisse/ergebnisse/teilwertung/store', [
            'csrf_token' => $indexAfter->formField('csrf_token') ?? '',
            'ergebnis_id' => (string) $ergebnisId,
            'bezeichnung' => $teilwertungName,
            'wertung' => 'A',
            'note' => '7,8',
            'platzierung' => '2.',
            'distanz' => '',
            'zeit' => '4:32,1',
        ]);
        $this->assertSame('/plugin/zuchtschau-ergebnisse/ergebnisse', $twStoreResponse->location());

        // 5b. Admin-Übersicht und öffentliche Detailseite zeigen die
        // Teilwertung unterhalb des Ergebnisses.
        $indexWithTw = $admin->get('/plugin/zuchtschau-ergebnisse/ergebnisse');
        $this->assertStringContainsString($teilwertungName, $indexWithTw->body);
        $this->assertStringContainsString('Teilwertungen (1)', $indexWithTw->body);

        $detailWithTw = $visitor->get("/horse?id={$horseId}");
        $this->assertStringContainsString($teilwertungName, $detailWithTw->body);
        $this->assertStringContainsString('7,8', $detailWithTw->body);
        $this->assertStringContainsString('4:32,1', $detailWithTw->body);

        // 5c. Einzelne Teilwertung wieder löschen (eigene Route), ohne das
        // Ergebnis anzutasten - danach eine zweite anlegen, die später den
        // CASCADE-Nachweis liefert.
        preg_match('#teilwertung/delete.*?name="id" value="(\d+)"#s', $indexWithTw->body, $twIdMatch);
        $this->assertNotEmpty($twIdMatch, 'Konnte ID der erfassten Teilwertung nicht ermitteln.');

        $twDeleteResponse = $admin->post('/plugin/zuchtschau-ergebnisse/ergebnisse/teilwertung/delete', [
            'csrf_token' => $indexWithTw->formField('csrf_token') ?? '',
            'id' => $twIdMatch[1],
        ]);
        $this->assertSame('/plugin/zuchtschau-ergebnisse/ergebnisse', $twDeleteResponse->location());

        $detailAfterTwDelete = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString($teilwertungName, $detailAfterTwDelete->body);
        $this->assertStringContainsString($eventName, $detailAfterTwDelete->body);

        $cascadeName = "Springen-{$unique}";
        $admin->post('/plugin/zuchtschau-ergebnisse/ergebnisse/teilwertung/store', [
            'csrf_token' => $indexWithTw->formField('csrf_token') ?? '',
            'ergebnis_id' => (string) $ergebnisId,
            'bezeichnung' => $cascadeName,
        ]);
        $this->assertSame(1, $this->countTeilwertungen($ergebnisId));

        // 6. Berechtigungsdurchsetzung: Editor ohne zuchtschau-ergebnisse.manage wird abgewiesen ...
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

        // 7. Löschen entfernt das Ergebnis wieder von der Detailseite - und
        // per FK ON DELETE CASCADE auch seine Teilwertungen. Der Nachweis
        // läuft nicht nur über die (dann ohnehin leere) Detailseite, sondern
        // direkt gegen die Datenbank: Ein grün gerenderter Abschnitt ist kein
        // Beleg dafür, dass die Kindzeilen wirklich weg sind.
        $deleteResponse = $admin->post('/plugin/zuchtschau-ergebnisse/ergebnisse/delete', [
            'csrf_token' => $indexAfter->formField('csrf_token') ?? '',
            'id' => (string) $ergebnisId,
        ]);
        $this->assertSame('/plugin/zuchtschau-ergebnisse/ergebnisse', $deleteResponse->location());

        $detailAfterDelete = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('Zuchtschau-/Körungsergebnisse', $detailAfterDelete->body);
        $this->assertStringNotContainsString($cascadeName, $detailAfterDelete->body);
        $this->assertSame(
            0,
            $this->countTeilwertungen($ergebnisId),
            'FK ON DELETE CASCADE hat die Teilwertungen des gelöschten Ergebnisses nicht entfernt.'
        );
    }

    /**
     * Zählt Teilwertungen direkt in der Datenbank - der unabhängige
     * Nebeneffekt-Nachweis für Anlage und CASCADE (die DB_*-Konstanten für
     * diesen Prozess setzt tests/bootstrap.php aus der Umgebung).
     */
    private function countTeilwertungen(int $ergebnisId): int {
        $stmt = \App\Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM `plugin_zuchtschau_teilwertungen` WHERE ergebnis_id = :id'
        );
        $stmt->execute(['id' => $ergebnisId]);
        return (int) $stmt->fetchColumn();
    }
}
