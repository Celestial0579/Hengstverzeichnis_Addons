<?php
// tests/Functional/StatistikDashboardPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/statistik-dashboard gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 *
 * Da die zugrunde liegende Testdatenbank über den gesamten PHPUnit-Prozess
 * (und damit auch andere Testklassen) hinweg geteilt wird, werden absolute
 * Gesamtzahlen bewusst NICHT geprüft - stattdessen eindeutige, per uniqid()
 * generierte Deckstations-/Vaternamen, die garantiert nur von diesem Test
 * stammen.
 */
class StatistikDashboardPluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'statistik-dashboard';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Statistik-Dashboard', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        // 1. Dashboard-Kachel muss erscheinen.
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString('/plugin/statistik-dashboard/statistik', $dashboard->body);

        $unique = uniqid();
        $station = "StatTestStation-{$unique}";

        // Die Deckstation eines Pferds wird von HorseController::saveHorsePersons()
        // aus der aktuellsten Rollen-Zuordnung in "persons[]" synchronisiert und
        // überschreibt ein direkt übergebenes "breeding_station"-Feld sofort wieder
        // (siehe HorseController::store() im Framework-Repo) - daher hier über eine
        // Personen-Rolle mit Freitext-Deckstation gesetzt, nicht direkt.
        $stationAssignment = ['persons' => [['role' => 'owner', 'breeding_station_text' => $station]]];

        $sireId = $this->createHorse($admin, "Vater-{$unique}", ['status' => 'active']);
        $this->createHorse($admin, "Sohn1-{$unique}", ['status' => 'active', 'sire_id' => (string) $sireId] + $stationAssignment);
        $this->createHorse($admin, "Sohn2-{$unique}", ['status' => 'active', 'sire_id' => (string) $sireId] + $stationAssignment);

        // 2. Statistikseite enthält die neue Deckstation mit Anzahl 2 ...
        $statsPage = $admin->get('/plugin/statistik-dashboard/statistik');
        $this->assertSame(200, $statsPage->statusCode);
        $this->assertMatchesRegularExpression(
            '/<td>' . preg_quote($station, '/') . '<\/td>\s*<td>2<\/td>/',
            $statsPage->body,
            "Erwartete Deckstations-Zeile '{$station}' mit Anzahl 2 nicht gefunden. Body: {$statsPage->body}"
        );

        // ... sowie den Vater "Vater-{unique}" als meistgenutzten Hengst mit Anzahl 2.
        $this->assertMatchesRegularExpression(
            '/<td>Vater-' . preg_quote($unique, '/') . '<\/td>\s*<td>2<\/td>/',
            $statsPage->body,
            "Erwartete Vater-Zeile 'Vater-{$unique}' mit Anzahl 2 nicht gefunden. Body: {$statsPage->body}"
        );

        // Wachstum der Datenbank: aktuelles Jahr sollte mit mindestens 3 (den
        // gerade angelegten Pferden) vertreten sein.
        $currentYear = date('Y');
        $this->assertMatchesRegularExpression(
            '/<td>' . $currentYear . '<\/td>\s*<td>(\d+)<\/td>/',
            $statsPage->body,
            'Erwartete Zeile für das aktuelle Jahr im Wachstumsverlauf nicht gefunden.'
        );

        // 3. Berechtigungsdurchsetzung: Editor ohne statistik-dashboard.view wird abgewiesen ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "statstester{$unique}",
            "statistik-dashboard-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/statistik-dashboard/statistik');
        $this->assertSame(403, $deniedResponse->statusCode);

        // ... und ist nach Zuweisung der Berechtigung erreichbar.
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'statistik-dashboard' => ['view'],
        ]);

        $allowedResponse = $editor->get('/plugin/statistik-dashboard/statistik');
        $this->assertSame(200, $allowedResponse->statusCode);
    }
}
