<?php
// tests/Functional/GaleriePluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/galerie gegen eine echte, per `php -S`
 * gestartete Hengstverzeichnis_Framework-Instanz (siehe tests/bootstrap.php
 * und Tests\Functional\FunctionalTestCase).
 *
 * Der Foto-Upload selbst wird hier nicht durchgespielt (der Test-HttpClient
 * sendet keine multipart-Anfragen) - abgedeckt sind der Video-Link-Pfad
 * inkl. Host-/Schema-Validierung, die öffentliche Galerie-Sektion, die
 * Berechtigungsdurchsetzung der Verwaltung sowie (#74) die
 * Datalist-Pferdesuche samt No-JS-Fallback und die Paginierung der
 * Medienliste.
 */
class GaleriePluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'galerie';

    /**
     * Integration in die Hengstverwaltung (#88, Framework-Hook
     * horse.edit_sections).
     *
     * Anders als bei #87 ist hier nicht die Performance der Punkt, sondern die
     * UI-Verlagerung - und drei Eigenheiten, die man beim Verlagern still
     * verliert:
     *
     * 1. `enctype="multipart/form-data"`. Der Abschnitt steht ausserhalb des
     *    Kern-Formulars und muss die Kodierung selbst deklarieren. Fehlt sie,
     *    kommt der Upload als leeres $_FILES an - ohne Fehlermeldung, der
     *    Bearbeiter sieht nur, dass nichts passiert.
     * 2. `zurueck=pferd`. Ohne den Rücksprung landet man nach dem Anlegen auf
     *    der bestandsweiten Verwaltungsseite, obwohl man mit dem Pferd noch
     *    nicht fertig ist.
     * 3. KEINE Lightbox. Sie hängt an JS/CSS der öffentlichen Detailseite und
     *    wäre im Bearbeitungsformular funktionslos.
     */
    public function testEditFormCarriesTheGallerySection(): void {
        $admin = $this->authenticatedClient();

        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $horseId = $this->createHorse($admin, 'GalerieAbschnitt-' . uniqid(), ['status' => 'active']);
        $form = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $form->statusCode);

        $this->assertStringContainsString(
            'Galerie',
            $form->body,
            'Der Galerie-Abschnitt erscheint nicht im Bearbeitungsformular - genau das ist #88.'
        );
        $this->assertStringContainsString(
            '/plugin/galerie/verwaltung/store',
            $form->body,
            'Das Erfassungsformular des Abschnitts fehlt.'
        );

        // Der Upload braucht die Kodierung, sonst kommt $_FILES leer an.
        $this->assertMatchesRegularExpression(
            '/<form[^>]*action="\/plugin\/galerie\/verwaltung\/store"[^>]*enctype="multipart\/form-data"/',
            $form->body,
            'Ohne enctype="multipart/form-data" scheitert der Upload lautlos.'
        );

        // Die horse_id kommt aus dem Aufrufkontext - keine erneute Pferdesuche.
        $this->assertMatchesRegularExpression(
            '/name="horse_id" value="' . $horseId . '"/',
            $form->body,
            'Die horse_id muss aus dem Kontext stammen, nicht erneut gesucht werden.'
        );
        $this->assertStringContainsString('name="zurueck" value="pferd"', $form->body);

        // Und ausdruecklich NICHT: die Lightbox der oeffentlichen Detailseite.
        $this->assertStringNotContainsString(
            'galerie-lightbox',
            $form->body,
            'Die Lightbox ist im Bearbeitungsformular funktionslos und gehoert nicht hinein.'
        );
    }

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Foto-/Video-Galerie', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        // 1. Dashboard-Kachel muss erscheinen.
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString('/plugin/galerie/verwaltung', $dashboard->body);

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "GalerieTestPferd-{$unique}", ['status' => 'active']);

        // 2. Ohne Medien: keine Galerie-Sektion auf der Detailseite.
        $visitor = $this->newClient();
        $detailBefore = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('Galerie</h3>', $detailBefore->body);

        // 3. Video-Link (YouTube, https) hinzufügen.
        $verwaltungPage = $admin->get('/plugin/galerie/verwaltung');
        $this->assertSame(200, $verwaltungPage->statusCode);

        // Pferde-Auswahl (#74): Suchfeld mit Datalist statt Voll-<select>
        // über den gesamten Bestand; die gewählte ID reist im Hidden-Feld.
        $this->assertStringContainsString('list="horse_q_liste"', $verwaltungPage->body, 'Das Pferd-Feld sollte eine Datalist referenzieren.');
        $this->assertStringContainsString('<datalist id="horse_q_liste">', $verwaltungPage->body);
        $this->assertStringContainsString('name="horse_id" id="horse_id" value=""', $verwaltungPage->body);
        $this->assertStringNotContainsString('<select name="horse_id"', $verwaltungPage->body, 'Der frühere Voll-<select> über alle Pferde darf nicht mehr gerendert werden.');

        // Suchroute (#74): JSON {id, label}, nur für Berechtigte, leere
        // Suche liefert eine leere Liste statt des Gesamtbestands.
        $sucheResponse = $admin->get('/plugin/galerie/suche?q=' . urlencode("GalerieTestPferd-{$unique}"));
        $this->assertSame(200, $sucheResponse->statusCode);
        $suggestions = json_decode($sucheResponse->body, true);
        $this->assertIsArray($suggestions, "Suchroute sollte JSON liefern. Body: {$sucheResponse->body}");
        $this->assertCount(1, $suggestions, 'Der eindeutige Testname sollte genau einen Treffer liefern.');
        $this->assertSame($horseId, (int) $suggestions[0]['id']);
        $this->assertStringContainsString("GalerieTestPferd-{$unique}", (string) $suggestions[0]['label']);

        $emptySuche = $admin->get('/plugin/galerie/suche?q=');
        $this->assertSame('[]', trim($emptySuche->body), 'Ohne Suchbegriff darf die Suchroute nichts ausliefern.');

        $videoUrl = 'https://www.youtube.com/watch?v=test' . $unique;
        $storeVideo = $admin->post('/plugin/galerie/verwaltung/store', [
            'csrf_token' => $verwaltungPage->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'video_url' => $videoUrl,
            'caption' => "Freispringen {$unique}",
        ]);
        $this->assertSame('/plugin/galerie/verwaltung', $storeVideo->location());

        $detailWithVideo = $visitor->get("/horse?id={$horseId}");
        $this->assertStringContainsString('Galerie', $detailWithVideo->body);
        $this->assertStringContainsString("Freispringen {$unique}", $detailWithVideo->body);
        $this->assertStringContainsString(
            htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8'),
            $detailWithVideo->body
        );

        // 4. Unerlaubte Video-URLs (fremder Host bzw. http statt https)
        // werden verworfen und erscheinen nirgends.
        foreach (['https://evil.example/video.mp4', 'http://www.youtube.com/watch?v=insecure' . $unique] as $badUrl) {
            $storeBad = $admin->post('/plugin/galerie/verwaltung/store', [
                'csrf_token' => $verwaltungPage->formField('csrf_token') ?? '',
                'horse_id' => (string) $horseId,
                'video_url' => $badUrl,
            ]);
            $this->assertSame('/plugin/galerie/verwaltung', $storeBad->location());
        }

        $verwaltungAfterBad = $admin->get('/plugin/galerie/verwaltung');
        $this->assertStringNotContainsString('evil.example', $verwaltungAfterBad->body);
        $this->assertStringNotContainsString('insecure' . $unique, $verwaltungAfterBad->body);

        // 4b. No-JS-Fallback (#74): Ohne horse_id löst store() den getippten
        // Text (horse_q) serverseitig zu einer Pferde-ID auf.
        $storeNoJs = $admin->post('/plugin/galerie/verwaltung/store', [
            'csrf_token' => $verwaltungPage->formField('csrf_token') ?? '',
            'horse_q' => "GalerieTestPferd-{$unique}",
            'video_url' => 'https://vimeo.com/12345' . $unique,
            'caption' => "NoJS-{$unique}",
        ]);
        $this->assertSame('/plugin/galerie/verwaltung', $storeNoJs->location());

        $verwaltungAfterNoJs = $admin->get('/plugin/galerie/verwaltung');
        $this->assertStringContainsString("NoJS-{$unique}", $verwaltungAfterNoJs->body, 'Ein eindeutiger Name in horse_q sollte serverseitig aufgelöst werden.');

        // ... ein unauflösbarer Text legt nichts an.
        $storeUnresolved = $admin->post('/plugin/galerie/verwaltung/store', [
            'csrf_token' => $verwaltungPage->formField('csrf_token') ?? '',
            'horse_q' => "GibtEsNicht-{$unique}",
            'video_url' => 'https://vimeo.com/99999' . $unique,
            'caption' => "Verwaist-{$unique}",
        ]);
        $this->assertSame('/plugin/galerie/verwaltung', $storeUnresolved->location());
        $this->assertStringNotContainsString(
            "Verwaist-{$unique}",
            $admin->get('/plugin/galerie/verwaltung')->body,
            'Ein unauflösbarer Pferdename darf keinen Eintrag anlegen.'
        );

        // 4c. Paginierung (#74): Unterhalb der Seitengröße erscheint keine
        // Blätter-Leiste, und ein zu großer ?seite=-Wert wird auf die letzte
        // vorhandene Seite geklemmt statt eine leere Liste zu zeigen.
        $this->assertStringNotContainsString('Seite 1 von 1', $verwaltungAfterNoJs->body, 'Bei nur einer Seite darf keine Blätter-Leiste erscheinen.');
        $clampedPage = $admin->get('/plugin/galerie/verwaltung?seite=999');
        $this->assertSame(200, $clampedPage->statusCode);
        $this->assertStringContainsString("NoJS-{$unique}", $clampedPage->body, 'Ein überzogener seite-Wert sollte auf die letzte Seite geklemmt werden.');

        // 5. Berechtigungsdurchsetzung: Editor ohne galerie.manage wird
        // abgewiesen ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "galtester{$unique}",
            "galerie-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/galerie/verwaltung');
        $this->assertSame(403, $deniedResponse->statusCode);

        // ... auch von der Suchroute (#74): Pferdenamen (inkl.
        // unveröffentlichter Pferde) bleiben auf den berechtigten Kreis
        // beschränkt.
        $deniedSuche = $editor->get('/plugin/galerie/suche?q=' . urlencode("GalerieTestPferd-{$unique}"));
        $this->assertSame(403, $deniedSuche->statusCode);

        // ... und ist nach Zuweisung der Berechtigung erreichbar.
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'galerie' => ['manage'],
        ]);

        $allowedResponse = $editor->get('/plugin/galerie/verwaltung');
        $this->assertSame(200, $allowedResponse->statusCode);

        // 6. Löschen entfernt das Medium wieder von der Detailseite.
        preg_match('/name="id" value="(\d+)"/', $verwaltungAfterBad->body, $idMatch);
        $this->assertNotEmpty($idMatch, 'Konnte ID des erfassten Mediums nicht ermitteln.');

        $deleteResponse = $admin->post('/plugin/galerie/verwaltung/delete', [
            'csrf_token' => $verwaltungAfterBad->formField('csrf_token') ?? '',
            'id' => $idMatch[1],
        ]);
        $this->assertSame('/plugin/galerie/verwaltung', $deleteResponse->location());

        $detailAfterDelete = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString("Freispringen {$unique}", $detailAfterDelete->body);
    }
}
