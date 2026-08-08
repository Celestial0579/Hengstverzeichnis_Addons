<?php
// tests/Functional/DeckanfragePluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/deckanfrage gegen eine echte, per `php -S`
 * gestartete Hengstverzeichnis_Framework-Instanz (siehe tests/bootstrap.php
 * und Tests\Functional\FunctionalTestCase).
 *
 * Kein SMTP ist in der Testumgebung konfiguriert - App\Service\Mailer::send()
 * liefert dadurch kontrolliert `false` (dasselbe etablierte Verhalten, das
 * auch der Kern in tests/Integration/DigestServiceTest.php voraussetzt).
 * Die tatsächliche Ergebnis-Erwartung ist daher "fehler" (Versand
 * fehlgeschlagen), nicht "erfolg" - der Test deckt damit den kompletten Weg
 * bis zum (fehlschlagenden) Versandversuch inkl. Protokollierung ab.
 */
class DeckanfragePluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'deckanfrage';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Deckanfrage-Formular', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        $unique = uniqid();

        // 1. Deckstation MIT E-Mail-Adresse anlegen - und VERÖFFENTLICHT.
        // Neu angelegte Stationen sind per Default unveröffentlicht
        // (breeding_stations.is_published DEFAULT 0), und die öffentliche
        // Pferde-Detailseite joint die Station nur mit is_published = 1
        // (Kern-#122). Ohne dieses Feld wäre $horse['station_email'] im Hook
        // horse.detail_sections null, das Formular erschiene nie, und der Test
        // schlüge fehl, ohne dass am Plugin etwas kaputt wäre (Kern-#151).
        $stationForm = $admin->get('/admin/breeding-stations/create');
        $stationName = "Deckstation-{$unique}";
        $stationEmail = "station-{$unique}@example.test";
        $stationResponse = $admin->post('/admin/breeding-stations/store', [
            'csrf_token' => $stationForm->formField('csrf_token') ?? '',
            'name' => $stationName,
            'email' => $stationEmail,
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/breeding-stations?success=created', $stationResponse->location());
        $stationId = $this->findBreedingStationIdByName($admin, $stationName);

        // 2. Pferd anlegen und über eine Personen-Rolle mit der Deckstation
        // verknüpfen (horses.breeding_station_id wird von
        // HorseController::saveHorsePersons() aus der Personen-Zuordnung
        // synchronisiert, siehe Kommentar in StatistikDashboardPluginTest -
        // ein direktes "breeding_station_id"-Feld gibt es auf Pferde-Ebene
        // nicht in der Admin-Oberfläche).
        $horseWithStation = $this->createHorse($admin, "MitDeckstation-{$unique}", [
            'status' => 'active',
            'persons' => [['role' => 'owner', 'breeding_station_id' => (string) $stationId]],
        ]);
        $horseWithoutStation = $this->createHorse($admin, "OhneDeckstation-{$unique}", ['status' => 'active']);

        $visitor = $this->newClient();

        // 3. Formular erscheint nur beim Pferd MIT verknüpfter Deckstation.
        $detailWith = $visitor->get("/hengst?id={$horseWithStation}");
        $this->assertStringContainsString('Deckanfrage stellen', $detailWith->body);
        $this->assertStringContainsString('name="webseite"', $detailWith->body, 'Honeypot-Feld sollte im Formular enthalten sein.');

        $detailWithout = $visitor->get("/hengst?id={$horseWithoutStation}");
        $this->assertStringNotContainsString('Deckanfrage stellen', $detailWithout->body);

        // 4. Honeypot ausgefüllt: wird stillschweigend als "erfolg" behandelt.
        $csrfToken = $detailWith->formField('csrf_token') ?? '';
        $honeypotResponse = $visitor->post('/plugin/deckanfrage/anfrage', [
            'csrf_token' => $csrfToken,
            'horse_id' => (string) $horseWithStation,
            'requester_name' => 'Bot',
            'requester_email' => 'bot@example.test',
            'message' => 'Spam',
            'webseite' => 'https://spam.example',
        ]);
        $this->assertSame("/hengst?id={$horseWithStation}&deckanfrage=erfolg", $honeypotResponse->location());

        // 5. Echte Anfrage: CSRF-, Validierungs- und Versandpfad durchlaufen -
        // Versand schlägt mangels SMTP-Konfiguration kontrolliert fehl.
        $realResponse = $visitor->post('/plugin/deckanfrage/anfrage', [
            'csrf_token' => $csrfToken,
            'horse_id' => (string) $horseWithStation,
            'requester_name' => 'Maria Musterfrau',
            'requester_email' => 'interessent@example.test',
            'message' => 'Ist der Hengst noch für die Decksaison verfügbar?',
        ]);
        $this->assertSame("/hengst?id={$horseWithStation}&deckanfrage=fehler", $realResponse->location());

        $detailAfter = $visitor->get("/hengst?id={$horseWithStation}&deckanfrage=fehler");
        $this->assertStringContainsString('konnte nicht versendet werden', $detailAfter->body);

        // 6. Regression zu Issue #26: Ein UNVERÖFFENTLICHTES Pferd (auch mit
        // Deckstation) darf über den Redirect-Status kein Existenz-Orakel
        // liefern - die Anfrage wird stillschweigend verworfen und wie beim
        // Honeypot als "erfolg" beantwortet, es geht keine E-Mail raus.
        $unpublishedHorse = $this->createHorse($admin, "Unveroeffentlicht-{$unique}", [
            'status' => 'active',
            'is_published' => '0',
            'persons' => [['role' => 'owner', 'breeding_station_id' => (string) $stationId]],
        ]);
        $unpublishedResponse = $visitor->post('/plugin/deckanfrage/anfrage', [
            'csrf_token' => $csrfToken,
            'horse_id' => (string) $unpublishedHorse,
            'requester_name' => 'Neugieriger Dritter',
            'requester_email' => 'dritter@example.test',
            'message' => 'Gibt es dieses Pferd?',
        ]);
        $this->assertSame(
            "/hengst?id={$unpublishedHorse}&deckanfrage=erfolg",
            $unpublishedResponse->location(),
            'Unveröffentlichte Pferde müssen denselben Status wie der Honeypot-Pfad liefern (kein Existenz-Orakel).'
        );

        // 7. CSRF-Schutz: fehlendes/ungültiges Token wird abgewiesen.
        $csrfRejected = $visitor->post('/plugin/deckanfrage/anfrage', [
            'csrf_token' => 'invalid-token',
            'horse_id' => (string) $horseWithStation,
            'requester_name' => 'Test',
            'requester_email' => 'test@example.test',
            'message' => 'Test',
        ]);
        $this->assertSame(403, $csrfRejected->statusCode);
    }

    private function findBreedingStationIdByName(\Tests\Support\HttpClient $admin, string $name): int {
        $page = $admin->get('/admin/breeding-stations');
        preg_match_all('/<tr[^>]*>((?:(?!<\/tr>).)*?)<\/tr>/s', $page->body, $rowMatches);
        foreach ($rowMatches[1] as $rowHtml) {
            if (!str_contains($rowHtml, '<strong>' . $name . '</strong>')) {
                continue;
            }
            preg_match('/<td[^>]*>(\d+)<\/td>/', $rowHtml, $idMatch);
            $this->assertNotEmpty($idMatch, "Zeile für Deckstation '{$name}' enthält keine numerische ID-Zelle.");
            return (int) $idMatch[1];
        }
        $this->fail("Konnte ID der Deckstation '{$name}' nicht aus /admin/breeding-stations ermitteln.");
    }
}
