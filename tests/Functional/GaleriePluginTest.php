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
 * Berechtigungsdurchsetzung der Verwaltung sowie (seit #125) der Einbau der
 * GEMEINSAMEN Pferdesuche des Kerns samt No-JS-Fallback und die Paginierung
 * der Medienliste.
 */
class GaleriePluginTest extends FunctionalTestCase {

    use HorseListHelper;
    use PferdesucheHelper;

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

        // Pferde-Auswahl (#125): das GEMEINSAME Suchfeld des Kerns statt einer
        // achten Kopie derselben Suche. Das Textfeld behält seinen Namen
        // `horse_q` - ohne JavaScript bleibt das Auswahlfeld leer, und dann
        // löst store() den getippten Text serverseitig auf.
        $this->assertGemeinsamePferdesuche($verwaltungPage->body, 'horse_id');
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="horse_q"[^>]*class="[^"]*hv-pferdesuche/',
            $verwaltungPage->body,
            'Das Textfeld muss weiterhin `horse_q` heißen - daran hängt der No-JS-Fallback.'
        );
        $this->assertEigeneSuchrouteEntfallen($admin, '/plugin/galerie/suche');

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

        // Die Vorschläge holt seit #125 der Kern-Endpunkt (er verlangt
        // `horses.view`, siehe README) - dieses Addon stellt keine eigene
        // Suchroute mehr daneben, an der die Rechteprüfung ein zweites Mal
        // richtig sein müsste.
        $this->assertEigeneSuchrouteEntfallen($editor, '/plugin/galerie/suche');

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

    /**
     * #134: Anlegen und Löschen eines Galeriemediums müssen im Audit-Log
     * stehen.
     *
     * Der Löschfall ist der eigentliche Anlass: Ein Galeriebild verschwand
     * bisher samt hochgeladener Datei spurlos aus dem Verzeichnis - im
     * Protokoll blieb davon nichts, obwohl es genau der Vorgang ist, den man
     * später nachvollziehen will. Deshalb wird hier ein Medium mit einer
     * ECHTEN Datei in der geschützten Ablage angelegt (der Test-HttpClient
     * spricht kein multipart, siehe Klassenkommentar) und über die
     * Lösch-Route entfernt: Nur so lässt sich prüfen, dass der Eintrag den
     * Dateinamen nennt UND dass die Datei tatsächlich weg ist.
     *
     * Gegenprobe gelaufen: Ohne die AuditLogger::log()-Aufrufe in
     * VerwaltungController::store()/delete() findet die Abfrage keine
     * Einträge, und der Test schlägt an den assertCount(1)-Zeilen fehl.
     */
    public function testAnlegenUndLoeschenStehenImProtokoll(): void {
        $admin = $this->authenticatedClient();
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $unique = uniqid();
        $horseName = "GalerieProtokoll-{$unique}";
        $horseId = $this->createHorse($admin, $horseName, ['status' => 'active']);

        // 1. Anlegen über die Verwaltung (Video-Weg, weil der Upload über
        // HTTP hier nicht spielbar ist).
        $verwaltung = $admin->get('/plugin/galerie/verwaltung');
        $videoUrl = 'https://vimeo.com/7654' . $unique;
        $bildunterschrift = "Bildunterschrift mit Klarnamen {$unique}";
        $admin->post('/plugin/galerie/verwaltung/store', [
            'csrf_token' => $verwaltung->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'video_url' => $videoUrl,
            'caption' => $bildunterschrift,
        ]);

        $angelegt = $this->protokollEintraege('Galerie: Medium hinzugefügt', "Pferd #{$horseId} ");
        $this->assertCount(1, $angelegt, 'Ein hinzugefügtes Medium muss genau einen Protokolleintrag erzeugen.');
        $this->assertStringContainsString($horseName, (string) $angelegt[0]['details'], 'Der Eintrag muss das Pferd benennen.');
        $this->assertStringContainsString($videoUrl, (string) $angelegt[0]['details'], 'Bei einem Video gehört der Verweis in den Eintrag.');
        $this->assertStringNotContainsString(
            $bildunterschrift,
            (string) $angelegt[0]['details'],
            'Die Bildunterschrift ist freier Text und gehört nicht ins dauerhafte Protokoll.'
        );

        // 2. Löschen eines BILDES samt Datei - der Fall aus dem Issue.
        $ablage = \FRAMEWORK_VENDOR_DIR . '/storage/plugin_galerie';
        if (!is_dir($ablage)) {
            mkdir($ablage, 0750, true);
        }
        $dateiName = "galerie_protokoll_{$unique}.png";
        file_put_contents($ablage . '/' . $dateiName, "\x89PNG\r\n\x1a\n");

        $db = \App\Database::getInstance();
        $stmt = $db->prepare(
            "INSERT INTO `plugin_galerie_media` (horse_id, type, file_path, caption, sort_order)
             VALUES (?, 'image', ?, ?, 0)"
        );
        $stmt->execute([$horseId, $dateiName, "Foto {$unique}"]);
        $mediumId = (int) $db->lastInsertId();

        $verwaltungVorLoeschen = $admin->get('/plugin/galerie/verwaltung');
        $admin->post('/plugin/galerie/verwaltung/delete', [
            'csrf_token' => $verwaltungVorLoeschen->formField('csrf_token') ?? '',
            'id' => (string) $mediumId,
        ]);

        $this->assertFileDoesNotExist(
            $ablage . '/' . $dateiName,
            'Voraussetzung des Protokollfalls: Die Datei muss beim Löschen wirklich verschwinden.'
        );

        $geloescht = $this->protokollEintraege('Galerie: Medium gelöscht', "Medium #{$mediumId},");
        $this->assertCount(1, $geloescht, 'Das Löschen eines Galeriebildes muss protokolliert werden - genau das fehlte (#134).');

        $details = (string) $geloescht[0]['details'];
        $this->assertStringContainsString($horseName, $details, 'Ohne Angabe, zu welchem Pferd das Bild gehörte, hilft der Eintrag niemandem.');
        $this->assertStringContainsString($dateiName, $details, 'Der Eintrag muss die entfernte Datei benennen.');
        $this->assertStringContainsString('entfernt', $details, 'Der Eintrag muss festhalten, dass die Datei entfernt wurde.');
    }

    /**
     * Protokolleinträge dieses Addons zu einer Aktion und einem Bezug.
     * Kategorie ist fest der Addon-Slug (#134) - die Auswahlliste der
     * Protokollansicht entsteht im Kern aus SELECT DISTINCT category.
     *
     * @return array<int, array<string, mixed>>
     */
    private function protokollEintraege(string $aktion, string $detailFragment): array {
        $stmt = \App\Database::getInstance()->prepare(
            'SELECT username, details FROM audit_logs
             WHERE category = ? AND action = ? AND details LIKE ?
               AND created_at >= (NOW() - INTERVAL 10 MINUTE)'
        );
        $stmt->execute([self::SLUG, $aktion, '%' . $detailFragment . '%']);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
