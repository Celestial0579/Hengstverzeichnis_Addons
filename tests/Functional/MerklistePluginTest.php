<?php
// tests/Functional/MerklistePluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/merkliste gegen eine echte, per `php -S`
 * gestartete Hengstverzeichnis_Framework-Instanz (siehe tests/bootstrap.php
 * und Tests\Functional\FunctionalTestCase).
 *
 * Die Merkliste ist rein clientseitig (localStorage) - serverseitig testbar
 * sind der "Merken"-Button auf Detailseite und Katalogkarten, die
 * Merklisten-Seite selbst sowie die JSON-API inkl. ihres Sichtbarkeits-
 * Gatings (nur veröffentlichte, nicht gelöschte Pferde).
 */
class MerklistePluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'merkliste';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Merkliste', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        $unique = uniqid();
        $publishedName = "MerklisteTestPferd-{$unique}";
        $publishedId = $this->createHorse($admin, $publishedName, ['status' => 'active']);
        $unpublishedId = $this->createHorse($admin, "MerklisteVerborgen-{$unique}", [
            'status' => 'active',
            'is_published' => '0',
        ]);

        $visitor = $this->newClient();

        // 1. "Merken"-Button auf der öffentlichen Detailseite - und der
        // "Zur Merkliste"-Link dort als App-Schaltfläche statt nackter Link (#49).
        $detailPage = $visitor->get("/horse?id={$publishedId}");
        $this->assertSame(200, $detailPage->statusCode);
        $this->assertStringContainsString('data-hv-merkliste="' . $publishedId . '"', $detailPage->body);
        $this->assertSame(
            1,
            preg_match('/<a href="\/plugin\/merkliste" class="btn btn-secondary"[^>]*>Zur Merkliste<\/a>/', $detailPage->body),
            'Der "Zur Merkliste"-Link muss als .btn btn-secondary gerendert werden'
        );

        // 2. Kompakter Button auf den Katalogkarten (catalog.card_sections) und
        // der Katalog-Einstieg zur Merkliste (#49): das idempotente Skript hängt
        // clientseitig GENAU EINEN Einstiegs-Link neben den Trefferzahl-Badge -
        // serverseitig prüfbar ist, dass der Einfüge-Code mit ausgeliefert wird
        // und das Ziel-Element (hit-count-badge) auf der Seite existiert.
        $catalogPage = $visitor->get('/katalog');
        $this->assertSame(200, $catalogPage->statusCode);
        $this->assertStringContainsString('data-hv-merkliste=', $catalogPage->body);
        $this->assertStringContainsString('hv-merkliste-entry', $catalogPage->body, 'Katalog-Einstiegs-Skript fehlt');
        $this->assertStringContainsString('id="hit-count-badge"', $catalogPage->body, 'Ankerelement für den Einstieg fehlt');

        // 3. Merklisten-Seite ist anonym erreichbar.
        $listPage = $visitor->get('/plugin/merkliste');
        $this->assertSame(200, $listPage->statusCode);
        $this->assertStringContainsString('Meine Merkliste', $listPage->body);

        // 4. JSON-API löst nur veröffentlichte Pferde auf - die unveröffentlichte
        // ID fehlt schlicht in der Antwort (kein Existenz-Orakel über den Fehlerpfad).
        $apiResponse = $visitor->get("/plugin/merkliste/api?ids={$publishedId},{$unpublishedId},999999");
        $this->assertSame(200, $apiResponse->statusCode);

        $data = json_decode($apiResponse->body, true);
        $this->assertIsArray($data);
        $returnedIds = array_column($data, 'id');
        $this->assertContains($publishedId, $returnedIds);
        $this->assertNotContains($unpublishedId, $returnedIds);

        $published = $data[array_search($publishedId, $returnedIds, true)];
        $this->assertSame($publishedName, $published['name']);
        $this->assertSame('/horse?id=' . $publishedId, $published['url']);

        // 5. Leere/unsinnige Eingaben liefern ein leeres Array statt Fehler.
        foreach (['', 'abc,-5,0', str_repeat('999999,', 150) . '999999'] as $badIds) {
            $badResponse = $visitor->get('/plugin/merkliste/api?ids=' . urlencode($badIds));
            $this->assertSame(200, $badResponse->statusCode);
            $this->assertSame([], json_decode($badResponse->body, true));
        }
    }
}
