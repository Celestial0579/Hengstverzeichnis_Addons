<?php
// tests/Functional/GesundheitstestsPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/gesundheitstests gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 *
 * Der Datei-Upload selbst wird hier nicht über das Formular durchgespielt
 * (der Test-HttpClient sendet keine multipart-Anfragen) - abgedeckt sind das
 * Opt-in-Prinzip der öffentlichen Sichtbarkeit, die
 * Berechtigungsdurchsetzung der Verwaltung, das 404-Verhalten der
 * Download-Route für unbekannte/dokumentlose Einträge sowie (#74) die
 * Datalist-Pferdesuche samt No-JS-Fallback und die Paginierung der
 * Eintragsliste.
 *
 * Das Zugriffs-Gate der Download-Route für Einträge MIT Dokument (#71) wird
 * über direkt per Database angelegte Einträge plus eine echte Datei im
 * storage-Verzeichnis geprüft - so werden die Gate-Bedingungen (is_public,
 * is_published, Verwaltungs-Berechtigung in angemeldeter Sitzung) erstmals
 * mit `true`-Potenzial ausgewertet statt immer schon am
 * "kein Dokument"-Kurzschluss zu enden.
 */
class GesundheitstestsPluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'gesundheitstests';

    /**
     * Aktiviert das Plugin idempotent - die Download-Tests (#71) sollen nicht
     * davon abhängen, dass der Lifecycle-Test zuerst gelaufen ist. Der
     * anschließende GET stellt sicher, dass die App einmal mit aktiviertem
     * Plugin gebootet hat (register() legt die Tabelle notfalls an).
     */
    private function enablePlugin(\Tests\Support\HttpClient $admin): void {
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame(200, $admin->get('/plugin/gesundheitstests/verwaltung')->statusCode);
    }

    /**
     * Legt einen Eintrag MIT Dokument direkt per Database an und schreibt die
     * zugehörige PDF-Datei in das storage-Verzeichnis des vendorierten
     * Framework-Checkouts (identisch zu Plugin::storageDir(), das vom
     * Plugin-Verzeichnis aus zwei Ebenen nach oben geht). Der Upload-Weg über
     * das Formular scheidet aus, weil der Test-HttpClient kein multipart für
     * beliebige Formulare spricht - für das Download-Gate (#71) zählt nur,
     * dass Zeile und Datei existieren.
     *
     * @return int ID des angelegten Eintrags
     */
    private function insertEntryWithDocument(int $horseId, bool $isPublic, string $unique): int {
        $storageDir = \FRAMEWORK_VENDOR_DIR . '/storage/plugin_gesundheitstests';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0750, true);
        }
        $fileName = "gtest_functional_{$unique}.pdf";
        file_put_contents($storageDir . '/' . $fileName, "%PDF-1.4\n% Test-Dokument {$unique}\n");

        $db = \App\Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO `plugin_gesundheitstests`
                (horse_id, test_type, file_name, file_original_name, file_mime, is_public)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $horseId,
            "Befund-{$unique}",
            $fileName,
            "befund-{$unique}.pdf",
            'application/pdf',
            $isPublic ? 1 : 0,
        ]);

        return (int) $db->lastInsertId();
    }

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('DNA-/Gesundheitstest-Verwaltung', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        // 1. Dashboard-Kachel muss erscheinen.
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString('/plugin/gesundheitstests/verwaltung', $dashboard->body);

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "GesundheitTestPferd-{$unique}", ['status' => 'active']);

        // 2. Eintrag OHNE Öffentlich-Flag anlegen: erscheint NICHT auf der
        // öffentlichen Detailseite (Opt-in-Prinzip, Standard aus).
        $verwaltungPage = $admin->get('/plugin/gesundheitstests/verwaltung');
        $this->assertSame(200, $verwaltungPage->statusCode);

        // Pferde-Auswahl (#74): Suchfeld mit Datalist statt Voll-<select>
        // über den gesamten Bestand; die gewählte ID reist im Hidden-Feld.
        $this->assertStringContainsString('list="horse_q_liste"', $verwaltungPage->body, 'Das Pferd-Feld sollte eine Datalist referenzieren.');
        $this->assertStringContainsString('<datalist id="horse_q_liste">', $verwaltungPage->body);
        $this->assertStringContainsString('name="horse_id" id="horse_id" value=""', $verwaltungPage->body);
        $this->assertStringNotContainsString('<select name="horse_id"', $verwaltungPage->body, 'Der frühere Voll-<select> über alle Pferde darf nicht mehr gerendert werden.');

        // Suchroute (#74): JSON {id, label}, nur für Berechtigte, leere
        // Suche liefert eine leere Liste statt des Gesamtbestands.
        $sucheResponse = $admin->get('/plugin/gesundheitstests/suche?q=' . urlencode("GesundheitTestPferd-{$unique}"));
        $this->assertSame(200, $sucheResponse->statusCode);
        $suggestions = json_decode($sucheResponse->body, true);
        $this->assertIsArray($suggestions, "Suchroute sollte JSON liefern. Body: {$sucheResponse->body}");
        $this->assertCount(1, $suggestions, 'Der eindeutige Testname sollte genau einen Treffer liefern.');
        $this->assertSame($horseId, (int) $suggestions[0]['id']);
        $this->assertStringContainsString("GesundheitTestPferd-{$unique}", (string) $suggestions[0]['label']);

        $emptySuche = $admin->get('/plugin/gesundheitstests/suche?q=');
        $this->assertSame('[]', trim($emptySuche->body), 'Ohne Suchbegriff darf die Suchroute nichts ausliefern.');

        $privateType = "Röntgen-{$unique}";
        $storePrivate = $admin->post('/plugin/gesundheitstests/verwaltung/store', [
            'csrf_token' => $verwaltungPage->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'test_type' => $privateType,
            'result_summary' => 'Ohne Befund.',
        ]);
        $this->assertSame('/plugin/gesundheitstests/verwaltung', $storePrivate->location());

        $visitor = $this->newClient();
        $detailPrivate = $visitor->get("/horse?id={$horseId}");
        $this->assertSame(200, $detailPrivate->statusCode);
        $this->assertStringNotContainsString(
            $privateType,
            $detailPrivate->body,
            'Nicht freigegebene Gesundheitsdaten dürfen nie öffentlich erscheinen (Opt-in).'
        );

        // 3. Eintrag MIT Öffentlich-Flag anlegen: erscheint auf der Detailseite.
        $publicType = "DNA-Abstammungstest-{$unique}";
        $storePublic = $admin->post('/plugin/gesundheitstests/verwaltung/store', [
            'csrf_token' => $verwaltungPage->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'test_type' => $publicType,
            'result_summary' => 'Abstammung bestätigt.',
            'issued_by' => 'Testlabor',
            'is_public' => '1',
        ]);
        $this->assertSame('/plugin/gesundheitstests/verwaltung', $storePublic->location());

        $detailPublic = $visitor->get("/horse?id={$horseId}");
        $this->assertStringContainsString('DNA-/Gesundheitstests', $detailPublic->body);
        $this->assertStringContainsString($publicType, $detailPublic->body);
        $this->assertStringContainsString('Abstammung bestätigt.', $detailPublic->body);
        $this->assertStringNotContainsString($privateType, $detailPublic->body);

        // 3b. No-JS-Fallback (#74): Ohne horse_id löst store() den getippten
        // Text (horse_q) serverseitig zu einer Pferde-ID auf.
        $noJsType = "NoJS-Befund-{$unique}";
        $storeNoJs = $admin->post('/plugin/gesundheitstests/verwaltung/store', [
            'csrf_token' => $verwaltungPage->formField('csrf_token') ?? '',
            'horse_q' => "GesundheitTestPferd-{$unique}",
            'test_type' => $noJsType,
        ]);
        $this->assertSame('/plugin/gesundheitstests/verwaltung', $storeNoJs->location());

        $verwaltungAfterNoJs = $admin->get('/plugin/gesundheitstests/verwaltung');
        $this->assertStringContainsString($noJsType, $verwaltungAfterNoJs->body, 'Ein eindeutiger Name in horse_q sollte serverseitig aufgelöst werden.');

        // ... ein unauflösbarer Text legt nichts an.
        $storeUnresolved = $admin->post('/plugin/gesundheitstests/verwaltung/store', [
            'csrf_token' => $verwaltungPage->formField('csrf_token') ?? '',
            'horse_q' => "GibtEsNicht-{$unique}",
            'test_type' => "Verwaist-{$unique}",
        ]);
        $this->assertSame('/plugin/gesundheitstests/verwaltung', $storeUnresolved->location());
        $this->assertStringNotContainsString(
            "Verwaist-{$unique}",
            $admin->get('/plugin/gesundheitstests/verwaltung')->body,
            'Ein unauflösbarer Pferdename darf keinen Eintrag anlegen.'
        );

        // 3c. Paginierung (#74): Unterhalb der Seitengröße erscheint keine
        // Blätter-Leiste, und ein zu großer ?seite=-Wert wird auf die letzte
        // vorhandene Seite geklemmt statt eine leere Liste zu zeigen.
        $this->assertStringNotContainsString('Seite 1 von 1', $verwaltungAfterNoJs->body, 'Bei nur einer Seite darf keine Blätter-Leiste erscheinen.');
        $clampedPage = $admin->get('/plugin/gesundheitstests/verwaltung?seite=999');
        $this->assertSame(200, $clampedPage->statusCode);
        $this->assertStringContainsString($noJsType, $clampedPage->body, 'Ein überzogener seite-Wert sollte auf die letzte Seite geklemmt werden.');

        // 4. Download-Route: unbekannte ID und Eintrag ohne Dokument liefern
        // identisch 404 (kein Existenz-Orakel).
        $unknownDownload = $visitor->get('/plugin/gesundheitstests/download?id=999999');
        $this->assertSame(404, $unknownDownload->statusCode);

        preg_match('/name="id" value="(\d+)"/', $admin->get('/plugin/gesundheitstests/verwaltung')->body, $idMatch);
        $this->assertNotEmpty($idMatch, 'Konnte ID eines erfassten Eintrags nicht ermitteln.');
        $noFileDownload = $visitor->get('/plugin/gesundheitstests/download?id=' . $idMatch[1]);
        $this->assertSame(404, $noFileDownload->statusCode);

        // 5. Berechtigungsdurchsetzung: Editor ohne gesundheitstests.manage
        // wird abgewiesen ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "gtester{$unique}",
            "gesundheitstests-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/gesundheitstests/verwaltung');
        $this->assertSame(403, $deniedResponse->statusCode);

        // ... auch von der Suchroute (#74): Pferdenamen (inkl.
        // unveröffentlichter Pferde) bleiben auf den berechtigten Kreis
        // beschränkt.
        $deniedSuche = $editor->get('/plugin/gesundheitstests/suche?q=' . urlencode("GesundheitTestPferd-{$unique}"));
        $this->assertSame(403, $deniedSuche->statusCode);

        // ... und ist nach Zuweisung der Berechtigung erreichbar.
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'gesundheitstests' => ['manage'],
        ]);

        $allowedResponse = $editor->get('/plugin/gesundheitstests/verwaltung');
        $this->assertSame(200, $allowedResponse->statusCode);

        // 6. Löschen entfernt den öffentlichen Eintrag wieder von der Detailseite.
        $verwaltungAfter = $admin->get('/plugin/gesundheitstests/verwaltung');
        preg_match_all('/name="id" value="(\d+)"/', $verwaltungAfter->body, $allIds);
        foreach ($allIds[1] as $entryId) {
            $deleteResponse = $admin->post('/plugin/gesundheitstests/verwaltung/delete', [
                'csrf_token' => $verwaltungAfter->formField('csrf_token') ?? '',
                'id' => $entryId,
            ]);
            $this->assertSame('/plugin/gesundheitstests/verwaltung', $deleteResponse->location());
        }

        $detailAfterDelete = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('DNA-/Gesundheitstests', $detailAfterDelete->body);
    }

    /**
     * #71: Ein NICHT freigegebener Eintrag mit Dokument (is_public = 0,
     * Pferd veröffentlicht) darf anonym weder als 200 noch mit Dateiinhalt
     * beantwortet werden - das ist der Kern des Opt-in-Versprechens der
     * Download-Route.
     */
    public function testPrivateDocumentIsNotDownloadableAnonymously(): void {
        $admin = $this->authenticatedClient();
        $this->enablePlugin($admin);

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "GTestPrivat-{$unique}", ['status' => 'active']);
        $entryId = $this->insertEntryWithDocument($horseId, false, "privat_{$unique}");

        $visitor = $this->newClient();
        $response = $visitor->get("/plugin/gesundheitstests/download?id={$entryId}");
        $this->assertSame(404, $response->statusCode, 'Ein nicht freigegebenes Dokument darf anonym nicht ausgeliefert werden.');
        $this->assertStringNotContainsString('%PDF', $response->body, 'Der Dateiinhalt darf die 404-Antwort nicht erreichen.');
    }

    /**
     * #71: Der freigegebene Gegenfall - is_public = 1 und veröffentlichtes
     * Pferd - liefert das Dokument anonym als Attachment aus. Ohne diesen
     * Positivfall würde auch ein versehentlich komplett dichtes Gate
     * (z. B. immer 404) grün bleiben.
     */
    public function testPublicDocumentIsDownloadableAnonymously(): void {
        $admin = $this->authenticatedClient();
        $this->enablePlugin($admin);

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "GTestOeffentlich-{$unique}", ['status' => 'active']);
        $entryId = $this->insertEntryWithDocument($horseId, true, "public_{$unique}");

        $visitor = $this->newClient();
        $response = $visitor->get("/plugin/gesundheitstests/download?id={$entryId}");
        $this->assertSame(200, $response->statusCode, 'Ein freigegebenes Dokument zu einem veröffentlichten Pferd muss anonym abrufbar sein.');
        $this->assertStringContainsString('%PDF', $response->body);
        $this->assertStringContainsString('attachment;', (string) $response->header('Content-Disposition'));
        $this->assertSame('application/pdf', $response->header('Content-Type'));
    }

    /**
     * #71: Bei einem UNVERÖFFENTLICHTEN Pferd bleibt auch ein als öffentlich
     * markierter Eintrag für Gäste eine 404 - während eine angemeldete
     * Sitzung mit gesundheitstests.manage das Dokument erhält. Das belegt
     * beide Zweige des Gates unabhängig voneinander: die Gast-Sperre hängt
     * an is_published, die Manager-Ausnahme (Framework#218: nur mit Session)
     * greift trotzdem.
     */
    public function testPublicDocumentOfUnpublishedHorseIs404ForGuestsButServedForManager(): void {
        $admin = $this->authenticatedClient();
        $this->enablePlugin($admin);

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "GTestUnveroeffentlicht-{$unique}", [
            'status' => 'active',
            'is_published' => '0',
        ]);
        $entryId = $this->insertEntryWithDocument($horseId, true, "unpub_{$unique}");

        $visitor = $this->newClient();
        $guestResponse = $visitor->get("/plugin/gesundheitstests/download?id={$entryId}");
        $this->assertSame(404, $guestResponse->statusCode, 'Dokumente unveröffentlichter Pferde dürfen Gästen nie ausgeliefert werden.');
        $this->assertStringNotContainsString('%PDF', $guestResponse->body);

        // Manager-Ausnahme: echte Nicht-Admin-Sitzung mit
        // gesundheitstests.manage (Admin hätte serverseitig ohnehin alle
        // Rechte und würde den Berechtigungs-Zweig nicht belegen).
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'gesundheitstests' => ['manage'],
        ]);
        $manager = $this->createAndLoginEditor(
            $admin,
            "gmanager{$unique}",
            "gesundheitstests-manager-{$unique}@example.com",
            [$editorGroupId]
        );

        $managerResponse = $manager->get("/plugin/gesundheitstests/download?id={$entryId}");
        $this->assertSame(200, $managerResponse->statusCode, 'Die Verwaltungs-Berechtigung muss das Dokument auch bei unveröffentlichtem Pferd ausliefern.');
        $this->assertStringContainsString('%PDF', $managerResponse->body);
    }
}
