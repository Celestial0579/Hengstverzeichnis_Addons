<?php
// tests/Functional/ZuchtSuchePluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/zucht-suche gegen eine echte, per `php -S`
 * gestartete Hengstverzeichnis_Framework-Instanz (siehe tests/bootstrap.php
 * und Tests\Functional\FunctionalTestCase).
 *
 * Seit Addons#122 läuft das Addon auf der zusammengeführten Kontaktliste
 * (Framework#336): ein Ergebnis statt zweier Reiter, die Rolle als Filter,
 * ein Rechte-Modul (`contacts`) statt zweier, ein Ziel (`/kontakt?id=`) statt
 * zweier.
 */
class ZuchtSuchePluginTest extends FunctionalTestCase {

    use PersonStationHelper;
    use HorseListHelper;

    private const SLUG = 'zucht-suche';
    private const SEITE = '/plugin/zucht-suche';

    /**
     * Gast-Standardrechte für den Wiederherstellungsschritt in Punkt 8.
     *
     * Bewusst NICHT self::GUEST_DEFAULT_PERMISSIONS: Die Konstante im Kern
     * (vendor/.../tests/Functional/FunctionalTestCase.php) listet noch
     * `persons` und `breeding_stations` - Module, die es seit Framework#336
     * nicht mehr gibt. Wer damit wiederherstellt, lässt die Gäste ohne
     * `contacts.view` zurück, und jeder folgende Schritt scheitert an einer
     * 404, die mit der eigentlichen Prüfung nichts zu tun hat. Der Kern muss
     * die Konstante nachziehen; bis dahin steht die Vorgabe hier.
     *
     * @var array<string, array<int, string>>
     */
    private const GAST_STANDARD = [
        'horses' => ['view'],
        'contacts' => ['view'],
    ];

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

        // 0. Auf dem Admin-Dashboard steht der Menüpunkt „Zucht" in der
        //    Kopfnavigation - der Kern baut layout.nav_items für JEDE View auf,
        //    auch für /admin. Eine Dashboard-Kachel daneben wäre derselbe
        //    Verweis ein zweites Mal, deshalb gibt es sie seit #115 nicht mehr.
        //    Der Kern rendert Kacheln als <a href="…" class="btn btn-secondary">,
        //    den Menüpunkt als nav-link - die beiden sind so unterscheidbar.
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString(
            'href="' . self::SEITE . '"',
            $dashboard->body,
            'Der Menuepunkt Zucht gehoert auch im Adminbereich in die Navigation.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<a href="' . preg_quote(self::SEITE, '/') . '"[^>]*class="btn/',
            $dashboard->body,
            'Das Dashboard darf keine Kachel auf die Zucht-Suche mehr enthalten (#115).'
        );
        $this->assertStringNotContainsString(
            'Zucht-Suche',
            $dashboard->body,
            'Die Kachelbeschriftung „🧬 Zucht-Suche" darf auf dem Dashboard nicht wieder auftauchen (#115).'
        );

        $unique = uniqid();
        $zuechterName = "ZSZuechter-{$unique}";
        $keinZuechterName = "ZSKeinZuechter-{$unique}";
        $stationName = "ZSStation-{$unique}";
        $zuechterEmail = "zuechter-{$unique}@example.test";
        $zuechterPlz = '24' . substr((string) crc32($unique), 0, 3);

        // Drei Kontakte in EINER Liste - vor #336 waren das zwei Tabellen:
        //  - einer MIT Züchter-Kennzeichen (redaktionell gepflegt, unabhängig
        //    von den Pferde-Zuordnungen, Kern-#293),
        //  - einer ohne jedes Kennzeichen und ohne Pferd,
        //  - einer ohne Kennzeichen, den unten ein Pferd als Deckstation nennt.
        $zuechterId = $this->createContact($admin, $zuechterName, [
            'is_breeder' => '1',
            'city' => "Zuchtdorf-{$unique}",
            'state' => 'Bayern',
            'country' => 'Deutschland',
            'postal_code' => $zuechterPlz,
            'email' => $zuechterEmail,
            'membership_status' => 'Mitglied',
        ]);
        $this->createContact($admin, $keinZuechterName, [
            'city' => "Zuchtdorf-{$unique}",
            'country' => 'Deutschland',
        ]);
        $stationId = $this->createContact($admin, $stationName, [
            'city' => "Stationsort-{$unique}",
            'country' => 'Deutschland',
        ]);

        // Ein veröffentlichtes Pferd, das den dritten Kontakt als Deckstation
        // nennt und den ersten als Züchter - erst damit gibt es überhaupt eine
        // Rolle, die aus Zuordnungen abgeleitet werden kann.
        $this->createHorse($admin, "ZSPferd-{$unique}", [
            'status' => 'active',
            'persons' => [
                ['role' => 'breeder', 'contact_id' => (string) $zuechterId],
                ['role' => 'owner', 'station_contact_id' => (string) $stationId],
            ],
        ]);

        $visitor = $this->newClient();

        // 1. Die Suchseite ist ohne Anmeldung erreichbar und führt OHNE
        //    Rollenfilter alle veröffentlichten Kontakte - vor #122 zeigte der
        //    Standardreiter nur die gekennzeichneten Züchter.
        $seite = $visitor->get(self::SEITE);
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString('🧬 Zucht', $seite->body);
        foreach ([$zuechterName, $keinZuechterName, $stationName] as $name) {
            $this->assertStringContainsString(
                $name,
                $seite->body,
                'Ohne Rollenfilter gehört jeder veröffentlichte Kontakt in die Liste.'
            );
        }

        // 2. Die Suche veröffentlicht keine Kontaktdaten. Sie ist eine
        //    Einstiegsseite, kein zweiter Weg an den Adressen vorbei an
        //    contact_public (Kern-#293/#336).
        $this->assertStringNotContainsString(
            $zuechterEmail,
            $seite->body,
            'Die Zucht-Suche darf keine Kontaktdaten ausgeben.'
        );
        // Die PLZ stand bis 0.7 in der Ortsspalte der Deckstationen, weil eine
        // Station eine Geschäftsadresse war. Diese Begründung ist mit der
        // Gattung weggefallen: postal_code ist seit #336 eine der Spalten, die
        // nur bei contact_public = 1 öffentlich sind (siehe
        // docs/kontaktliste-umstellung.md). Die Suche fragt sie gar nicht mehr ab.
        $this->assertStringNotContainsString(
            $zuechterPlz,
            $seite->body,
            'Die Postleitzahl gehört seit #336 zu den geschützten Feldern und darf in der Trefferliste nicht stehen.'
        );

        // 3. Rollenfilter „Züchter": das redaktionelle Kennzeichen
        //    contacts.is_breeder, ausdrücklich NICHT horse_persons.role.
        $zuechterListe = $visitor->get(self::SEITE . '?rolle=zuechter');
        $this->assertSame(200, $zuechterListe->statusCode);
        $this->assertStringContainsString($zuechterName, $zuechterListe->body);
        $this->assertStringNotContainsString(
            $keinZuechterName,
            $zuechterListe->body,
            'Ohne contacts.is_breeder gehört ein Kontakt nicht in den Züchter-Filter.'
        );
        $this->assertStringNotContainsString($stationName, $zuechterListe->body);

        // 4. Rollenfilter „Deckstation": abgeleitet aus den Zuordnungen
        //    veröffentlichter Pferde, nicht aus einem Feld am Datensatz - die
        //    Gattung „Deckstation" gibt es nicht mehr.
        $stationsListe = $visitor->get(self::SEITE . '?rolle=station');
        $this->assertSame(200, $stationsListe->statusCode);
        $this->assertStringContainsString($stationName, $stationsListe->body);
        $this->assertStringNotContainsString(
            $keinZuechterName,
            $stationsListe->body,
            'Ein Kontakt ohne Pferd ist keine Deckstation.'
        );

        // 5. Ortsfilter greift und schließt Nichttreffer aus.
        $gefiltert = $visitor->get(self::SEITE . '?ort=' . urlencode("Zuchtdorf-{$unique}"));
        $this->assertStringContainsString($zuechterName, $gefiltert->body);

        $daneben = $visitor->get(self::SEITE . '?ort=' . urlencode("Nirgendwo-{$unique}"));
        $this->assertStringNotContainsString($zuechterName, $daneben->body);

        // 6. Der Mitgliedsstatus ist seit #336 ein Feld der gemeinsamen Liste
        //    und war bis dahin an den Züchter-Reiter gebunden. Er filtert
        //    deshalb auch dann, wenn gar keine Rolle gewählt ist.
        $mitglieder = $visitor->get(self::SEITE . '?mitglied=' . urlencode('Mitglied'));
        $this->assertStringContainsString($zuechterName, $mitglieder->body);
        $this->assertStringNotContainsString(
            $keinZuechterName,
            $mitglieder->body,
            'Der Mitgliedsstatus muss unabhängig von der Rolle filtern.'
        );

        // 7. Verlinkung auf die EINE Kontaktseite des Kerns. /person?id= und
        //    /station?id= gibt es nur noch als 301 für Altbestände - die
        //    Trefferliste kennt die neue Kennung und nimmt keinen Umweg.
        $this->assertStringContainsString('/kontakt?id=' . $zuechterId, $seite->body);
        $this->assertStringContainsString('/kontakt?id=' . $stationId, $seite->body);
        $this->assertStringNotContainsString('/person?id=', $seite->body);
        $this->assertStringNotContainsString('/station?id=', $seite->body);

        // 7b. Der Verweis auf der Kontaktseite steht GENAU EINMAL da. Das ist
        //     der Kern von #122: Bis 0.7 registrierte das Addon beide alten
        //     Paare (person.* UND station.*). Seit #336 gibt es nur noch einen
        //     Datensatz, und der Kern feuert beide alten Namen als Alias
        //     hinterher - das Addon bekäme seinen Abschnitt zweimal auf
        //     derselben Seite.
        $kontaktSeite = $visitor->get('/kontakt?id=' . $zuechterId);
        $this->assertSame(200, $kontaktSeite->statusCode);
        $this->assertSame(
            1,
            substr_count($kontaktSeite->body, '>Zucht-Suche</a>'),
            'Der Verweis auf die Zucht-Suche darf auf der Kontaktseite nur einmal stehen - '
            . 'sonst sind noch die Alias-Hooks person.*/station.* mitregistriert (#122).'
        );

        // 7c. Der Menüpunkt „Zucht" steht in der öffentlichen Navigation - auf
        //     jeder Seite, nicht nur auf der eigenen. Genau das war der Wunsch
        //     hinter diesem Addon: ein Einstieg neben dem Verzeichnis, statt
        //     Kontakte nur über ein einzelnes Pferd zu finden. Möglich seit dem
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

        // 7d. Unveröffentlichte Datensätze bleiben draußen - die Trefferzahl
        //     einer öffentlichen Suche wäre sonst ein Existenz-Orakel für
        //     bewusst depublizierte Namen (Kern-#121).
        $verstecktName = "ZSVersteckt-{$unique}";
        $this->createContact($admin, $verstecktName, [
            'is_breeder' => '1',
            'is_published' => '0',
        ]);
        $nachVerstecken = $visitor->get(self::SEITE);
        $this->assertStringNotContainsString($verstecktName, $nachVerstecken->body);

        $gastGruppe = $this->findBuiltinGroupId($admin, 'Gast');

        // 8. Ohne horses.view fallen die abgeleiteten Rollen weg - sie sind
        //    Aussagen über Pferde. Bliebe ?rolle=station wirksam, wäre der
        //    Filter ein Orakel darüber, welche Kontakte Pferde haben, obwohl
        //    die Pferde selbst unsichtbar sind.
        $this->setGroupPermissions($admin, $gastGruppe, ['contacts' => ['view']]);
        try {
            $ohnePferde = $this->newClient()->get(self::SEITE);
            $this->assertSame(200, $ohnePferde->statusCode);
            $this->assertStringNotContainsString(
                'value="station"',
                $ohnePferde->body,
                'Ohne horses.view darf die Rolle „Deckstation" nicht angeboten werden.'
            );
            $this->assertStringNotContainsString('Als Deckstation', $ohnePferde->body);

            // Und der Wert wirkt auch nicht, wenn er von Hand gesetzt wird:
            // die Anfrage fällt auf „(alle)" zurück, der Züchter erscheint also.
            $erzwungen = $this->newClient()->get(self::SEITE . '?rolle=station');
            $this->assertSame(200, $erzwungen->statusCode);
            $this->assertStringContainsString(
                $zuechterName,
                $erzwungen->body,
                '?rolle=station muss ohne horses.view auf „(alle)" zurückfallen, nicht heimlich filtern.'
            );
        } finally {
            $this->setGroupPermissions($admin, $gastGruppe, self::GAST_STANDARD);
        }

        // 9. Fail-closed: Nimmt man der Gästegruppe contacts.view, ist die
        //    Seite nicht mehr erreichbar (404 statt einer leeren Liste, die
        //    verriete, dass es die Seite gibt). Vor #336 waren dafür ZWEI
        //    Module zu entziehen.
        $this->setGroupPermissions($admin, $gastGruppe, ['horses' => ['view']]);
        try {
            $gesperrt = $this->newClient()->get(self::SEITE);
            $this->assertSame(
                404,
                $gesperrt->statusCode,
                'Ohne contacts.view darf die Zucht-Suche nicht antworten.'
            );
            // Und der Menüpunkt verschwindet mit ihr - ein Eintrag, der in
            // eine 404 führt, wäre eine Sackgasse.
            $this->assertStringNotContainsString(
                'href="' . self::SEITE . '"',
                $this->newClient()->get('/katalog')->body,
                'Ohne Sichtrecht darf der Menüpunkt nicht in der Navigation stehen.'
            );
        } finally {
            $this->setGroupPermissions($admin, $gastGruppe, self::GAST_STANDARD);
        }

        // 10. Plugin wieder abschalten: Die Route verschwindet mit ihm.
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
