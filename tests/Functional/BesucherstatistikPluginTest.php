<?php
// tests/Functional/BesucherstatistikPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/besucherstatistik gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php, das dieses Plugin vor Testbeginn nach
 * vendor/hengstverzeichnis/framework/plugins/ kopiert, und
 * Tests\Functional\FunctionalTestCase, das von dort direkt mitgeladen wird).
 *
 * Deckt den vollständigen Lebenszyklus ab: Aktivierung über /admin/plugins,
 * Besucherzählung über den horse.detail_sections-Hook auf der öffentlichen
 * Detailseite, die admin.dashboard_tiles-Kachel und die selbst registrierte
 * Berechtigung besucherstatistik.view (inkl. Verweigerung ohne Berechtigung).
 *
 * Alles in einer Testmethode, da die Schritte zwingend aufeinander aufbauen
 * (PHPUnit garantiert keine Ausführungsreihenfolge mehrerer Testmethoden).
 */
class BesucherstatistikPluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'besucherstatistik';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        // 1. Plugin aktivieren (siehe currentCsrfToken()-Kommentar in
        // FunctionalTestCase: admin_plugins.php rendert das csrf_token-Feld
        // nur pro gefundenem Plugin, hier ist besucherstatistik aber bereits
        // durch tests/bootstrap.php nach vendor/.../plugins kopiert worden).
        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString(
            'Besucherstatistik',
            $pluginsPage->body,
            'Plugin sollte unter /admin/plugins als entdeckt gelistet sein - wurde es nach vendor/hengstverzeichnis/framework/plugins kopiert?'
        );

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        // 2. Pferd anlegen (Dreh- und Angelpunkt für die restlichen Schritte).
        $createForm = $admin->get('/admin/horses/create');
        $horseName = 'Testhengst ' . uniqid();
        $createResponse = $admin->post('/admin/horses/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => $horseName,
            'color' => 'Rappe',
            'breeding_station' => 'Testgestüt',
            'birth_year' => '2020',
            'status' => 'active',
        ]);
        $this->assertSame('/admin/horses?success=created', $createResponse->location());
        $horseId = $this->findHorseIdByName($admin, $horseName);

        // 3. Dashboard-Kachel des Plugins muss erscheinen (admin.dashboard_tiles-Filter).
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString(
            '/plugin/besucherstatistik/statistik',
            $dashboard->body,
            'Dashboard sollte die vom Plugin über admin.dashboard_tiles ergänzte Kachel enthalten.'
        );

        // 4. Öffentliche Detailseite mehrfach unangemeldet aufrufen
        // (horse.detail_sections-Filter zählt und zeigt die Aufrufzahl an).
        $visitor = $this->newClient();
        for ($i = 0; $i < 3; $i++) {
            $detailPage = $visitor->get("/hengst?id={$horseId}");
            $this->assertSame(200, $detailPage->statusCode);
        }
        $this->assertStringContainsString(
            '3 mal aufgerufen',
            $detailPage->body,
            'Detailseite sollte nach 3 Aufrufen den vom Plugin ergänzten Zähler "3 mal aufgerufen" enthalten.'
        );

        // 5. Eigene Route /plugin/besucherstatistik/statistik ist als Admin
        // erreichbar (Admin hat serverseitig immer alle Berechtigungen) und
        // zeigt die zuvor erzeugten Aufrufe.
        $statsAsAdmin = $admin->get('/plugin/besucherstatistik/statistik');
        $this->assertSame(200, $statsAsAdmin->statusCode);
        $this->assertStringContainsString($horseName, $statsAsAdmin->body);

        // 6. Berechtigungsdurchsetzung: ein Editor, Mitglied der eingebauten
        // Editor-Gruppe (Security-by-Design: role='editor' allein gewährt
        // KEINE Rechte, Mitgliedschaft ist immer explizit - siehe
        // GroupPermissionEnforcementTest im Framework-Repo), aber OHNE die vom
        // Plugin selbst registrierte Berechtigung besucherstatistik.view in
        // deren Standardrechten, muss abgewiesen werden ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $unique = uniqid();
        $editor = $this->createAndLoginEditor(
            $admin,
            "besuchertester{$unique}",
            "besucherstatistik-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $statsWithoutPermission = $editor->get('/plugin/besucherstatistik/statistik');
        $this->assertSame(
            403,
            $statsWithoutPermission->statusCode,
            'Ohne besucherstatistik.view sollte die Plugin-Route 403 liefern (Zugriffsschutz ist Aufgabe des Plugins, siehe StatistikController).'
        );

        // ... und nach Zuweisung der Berechtigung über die echte
        // Gruppenverwaltung erreichbar sein.
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'besucherstatistik' => ['view'],
        ]);

        $statsWithPermission = $editor->get('/plugin/besucherstatistik/statistik');
        $this->assertSame(200, $statsWithPermission->statusCode);
        $this->assertStringContainsString($horseName, $statsWithPermission->body);
    }

}
