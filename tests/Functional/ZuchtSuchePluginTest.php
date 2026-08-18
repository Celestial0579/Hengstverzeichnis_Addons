<?php
// tests/Functional/ZuchtSuchePluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/zucht-suche gegen eine echte, per `php -S`
 * gestartete Hengstverzeichnis_Framework-Instanz (siehe tests/bootstrap.php
 * und Tests\Functional\FunctionalTestCase).
 */
class ZuchtSuchePluginTest extends FunctionalTestCase {

    use PersonStationHelper;

    private const SLUG = 'zucht-suche';
    private const SEITE = '/plugin/zucht-suche';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Zucht-Suche', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        $unique = uniqid();
        $zuechterName = "ZSZuechter-{$unique}";
        $keinZuechterName = "ZSKeinZuechter-{$unique}";
        $stationName = "ZSStation-{$unique}";
        $zuechterEmail = "zuechter-{$unique}@example.test";

        // Eine Person MIT Züchter-Kennzeichen, eine ohne. Das Kennzeichen ist
        // redaktionell gepflegt und unabhängig von den Pferde-Zuordnungen
        // (Kern-#293) - genau darauf filtert der Reiter "Züchter".
        $zuechterId = $this->createPerson($admin, $zuechterName, [
            'is_breeder' => '1',
            'city' => "Zuchtdorf-{$unique}",
            'state' => 'Bayern',
            'country' => 'Deutschland',
            'email' => $zuechterEmail,
            'membership_status' => 'Mitglied',
        ]);
        $this->createPerson($admin, $keinZuechterName, [
            'city' => "Zuchtdorf-{$unique}",
            'country' => 'Deutschland',
        ]);
        $stationId = $this->createStation($admin, $stationName, [
            'city' => "Stationsort-{$unique}",
            'country' => 'Deutschland',
        ]);

        $visitor = $this->newClient();

        // 1. Die Suchseite ist ohne Anmeldung erreichbar und führt im
        //    Standardreiter die gekennzeichneten Züchter - und nur die.
        $seite = $visitor->get(self::SEITE);
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString('🧬 Zucht', $seite->body);
        $this->assertStringContainsString($zuechterName, $seite->body);
        $this->assertStringNotContainsString(
            $keinZuechterName,
            $seite->body,
            'Ohne persons.is_breeder gehört eine Person nicht in den Züchter-Reiter.'
        );

        // 2. Die Suche veröffentlicht keine Kontaktdaten. Sie ist eine
        //    Einstiegsseite, kein zweiter Weg an die Adressen vorbei an
        //    contact_public (Kern-#293).
        $this->assertStringNotContainsString(
            $zuechterEmail,
            $seite->body,
            'Die Zucht-Suche darf keine Kontaktdaten ausgeben.'
        );

        // 3. Ortsfilter greift und schließt Nichttreffer aus.
        $gefiltert = $visitor->get(self::SEITE . '?ort=' . urlencode("Zuchtdorf-{$unique}"));
        $this->assertStringContainsString($zuechterName, $gefiltert->body);

        $daneben = $visitor->get(self::SEITE . '?ort=' . urlencode("Nirgendwo-{$unique}"));
        $this->assertStringNotContainsString($zuechterName, $daneben->body);

        // 4. Zweiter Reiter: Deckstationen, mit Verlinkung auf die Detailseite.
        $stationen = $visitor->get(self::SEITE . '?art=stationen');
        $this->assertSame(200, $stationen->statusCode);
        $this->assertStringContainsString($stationName, $stationen->body);
        $this->assertStringContainsString('/station?id=' . $stationId, $stationen->body);
        $this->assertStringNotContainsString($zuechterName, $stationen->body);

        // 5. Verlinkung des Züchters auf die Personenseite des Kerns.
        $this->assertStringContainsString('/person?id=' . $zuechterId, $seite->body);

        // 5b. Der Menüpunkt „Zucht" steht in der öffentlichen Navigation - auf
        //     jeder Seite, nicht nur auf der eigenen. Genau das war der Wunsch
        //     hinter diesem Addon: ein Einstieg neben dem Verzeichnis, statt
        //     Züchter nur über ein einzelnes Pferd zu finden. Möglich seit dem
        //     Filter layout.nav_items im Kern 0.7.0.
        foreach (['/', '/katalog'] as $pfad) {
            $body = $visitor->get($pfad)->body;
            $this->assertStringContainsString(
                'href="' . self::SEITE . '"',
                $body,
                "Der Menuepunkt Zucht fehlt auf {$pfad}."
            );
            $this->assertStringContainsString('🧬 Zucht', $body);
        }

        // Auf der eigenen Seite ist er als aktiv markiert - sonst verschwände
        // die Markierung ausgerechnet beim Draufklicken.
        $this->assertMatchesRegularExpression(
            '/href="' . preg_quote(self::SEITE, '/') . '"\s+class="nav-link active"/',
            $seite->body
        );

        // 6. Unveröffentlichte Datensätze bleiben draußen - die Trefferzahl
        //    einer öffentlichen Suche wäre sonst ein Existenz-Orakel für
        //    bewusst depublizierte Namen (Kern-#121).
        $verstecktName = "ZSVersteckt-{$unique}";
        $this->createPerson($admin, $verstecktName, [
            'is_breeder' => '1',
            'is_published' => '0',
        ]);
        $nachVerstecken = $visitor->get(self::SEITE);
        $this->assertStringNotContainsString($verstecktName, $nachVerstecken->body);

        // 7. Fail-closed: Nimmt man der Gästegruppe beide Sichtrechte, ist die
        //    Seite nicht mehr erreichbar (404 statt einer leeren Liste, die
        //    verriete, dass es die Seite gibt).
        $gastGruppe = $this->findBuiltinGroupId($admin, 'Gast');
        $this->setGroupPermissions($admin, $gastGruppe, ['horses' => ['view']]);
        try {
            $gesperrt = $this->newClient()->get(self::SEITE);
            $this->assertSame(
                404,
                $gesperrt->statusCode,
                'Ohne persons.view und breeding_stations.view darf die Zucht-Suche nicht antworten.'
            );
            // Und der Menüpunkt verschwindet mit ihr - ein Eintrag, der in
            // eine 404 führt, wäre eine Sackgasse.
            $this->assertStringNotContainsString(
                'href="' . self::SEITE . '"',
                $this->newClient()->get('/katalog')->body,
                'Ohne Sichtrechte darf der Menüpunkt nicht in der Navigation stehen.'
            );
        } finally {
            $this->setGroupPermissions($admin, $gastGruppe, self::GUEST_DEFAULT_PERMISSIONS);
        }

        // 8. Plugin wieder abschalten: Die Route verschwindet mit ihm.
        $disableResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '0',
        ]);
        $this->assertSame('/admin/plugins?success=1', $disableResponse->location());
        $nachAbschalten = $this->newClient();
        $this->assertSame(404, $nachAbschalten->get(self::SEITE)->statusCode);
        $this->assertStringNotContainsString(
            'href="' . self::SEITE . '"',
            $nachAbschalten->get('/katalog')->body,
            'Mit dem Plugin verschwindet auch sein Menüpunkt.'
        );
    }
}
