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

        // 2. Kompakter Button auf den Katalogkarten (catalog.card_sections).
        // Die clientseitige Logik kommt seit #73 aus einem statischen Asset:
        // das Script-Tag steht GENAU EINMAL in der Seite (Instanz-Flag) statt
        // als 3,8-KB-Inline-Block je Karte; das Ziel-Element des
        // Katalog-Einstiegs (hit-count-badge) existiert weiterhin.
        $catalogPage = $visitor->get('/katalog');
        $this->assertSame(200, $catalogPage->statusCode);
        $this->assertStringContainsString('data-hv-merkliste=', $catalogPage->body);
        $this->assertStringContainsString('id="hit-count-badge"', $catalogPage->body, 'Ankerelement für den Einstieg fehlt');
        $this->assertSame(
            1,
            preg_match_all('/<script src="\/plugin\/merkliste\/assets\.js(\?v=\d+)?" defer><\/script>/', $catalogPage->body),
            'Das Merklisten-Script-Tag muss genau EINMAL je Seite ausgegeben werden (#73)'
        );
        $this->assertStringNotContainsString('window.hvMerkliste =', $catalogPage->body, 'Inline-Skriptblock darf nicht mehr im Katalog-HTML stehen (#73)');

        // 2b. Das statische Asset selbst: 200, JS-Content-Type, cachebar -
        // und es enthält die Logik, die vorher inline lag (Buttons
        // synchronisieren, Katalog-Einstieg, Observer auf #catalog-grid).
        $assetResponse = $visitor->get('/plugin/merkliste/assets.js');
        $this->assertSame(200, $assetResponse->statusCode);
        $this->assertStringContainsString('application/javascript', (string) $assetResponse->header('Content-Type'));
        $cacheControl = (string) $assetResponse->header('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl, 'Asset muss browser-/proxy-cachebar sein (#73)');
        $this->assertStringContainsString('max-age=86400', $cacheControl);
        $this->assertStringContainsString('hvMerklisteToggle', $assetResponse->body);
        $this->assertStringContainsString('hv-merkliste-entry', $assetResponse->body, 'Katalog-Einstiegs-Code fehlt im Asset');
        $this->assertStringContainsString('catalog-grid', $assetResponse->body, 'Observer muss auf den Karten-Container eingeschränkt sein (#73)');
        $this->assertStringNotContainsString('document.documentElement', $assetResponse->body, 'Observer darf nicht mehr das ganze Dokument beobachten (#73)');

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
