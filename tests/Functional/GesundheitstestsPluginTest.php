<?php
// tests/Functional/GesundheitstestsPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/gesundheitstests gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 *
 * Seit #120 läuft die Dokumentpflege ausschließlich über den Abschnitt im
 * Bearbeitungsformular des Pferdes (Kern-Hook `horse.edit_sections`); die
 * addoneigene Verwaltungsseite, ihre Dashboard-Kachel und ihre Pferdesuche
 * (Addons#125) sind entfallen. Der Test hält deshalb beides fest: dass der
 * Pferdeabschnitt den vollen Umfang trägt (anlegen samt Dokument-Upload,
 * auflisten, löschen) - und dass die alten Adressen wirklich weg sind.
 *
 * Der Upload läuft dabei ECHT durch den neuen Abschnitt (HttpClient::postFile),
 * nicht mehr nur über direkt gesetzte Datenbankzeilen: Der Abschnitt steht
 * außerhalb des Kern-Formulars und muss `enctype="multipart/form-data"` selbst
 * mitbringen - ohne das käme der Upload als leeres $_FILES an, und zwar ohne
 * Fehlermeldung. Geprüft wird beides, das Attribut im HTML und der Weg der
 * Datei bis in die Download-Route.
 *
 * Das Zugriffs-Gate der Download-Route (#71) wird weiterhin über direkt per
 * Database angelegte Einträge plus eine echte Datei im storage-Verzeichnis
 * geprüft - so lassen sich die Gate-Bedingungen (is_public, is_published,
 * Verwaltungs-Berechtigung in angemeldeter Sitzung) einzeln stellen.
 */
class GesundheitstestsPluginTest extends FunctionalTestCase {

    use HorseListHelper;
    use PferdesucheHelper;
    use DeinstallationHelper;

    private const SLUG = 'gesundheitstests';

    /** Die entfallene Verwaltungsseite (#120) - hier nur noch als Negativprobe. */
    private const ALTE_SEITE = '/plugin/gesundheitstests/verwaltung';

    /** Kleinste Datei, die `finfo` als application/pdf erkennt. */
    private const PDF_INHALT = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    /**
     * Aktiviert das Plugin idempotent - die Download-Tests (#71) sollen nicht
     * davon abhängen, dass der Lifecycle-Test zuerst gelaufen ist. Der
     * anschließende GET stellt sicher, dass die App einmal mit aktiviertem
     * Plugin gebootet hat.
     *
     * Seit #120 führt der Abgleich über das Dashboard statt über die eigene
     * Verwaltungsseite: Die gibt es nicht mehr, ein GET darauf liefert 404.
     */
    private function enablePlugin(\Tests\Support\HttpClient $admin): void {
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame(200, $admin->get('/admin')->statusCode);
    }

    /**
     * Legt einen Eintrag MIT Dokument direkt per Database an und schreibt die
     * zugehörige PDF-Datei in das storage-Verzeichnis des vendorierten
     * Framework-Checkouts (identisch zu Plugin::storageDir(), das vom
     * Plugin-Verzeichnis aus zwei Ebenen nach oben geht). Für das Download-Gate
     * (#71) zählt nur, dass Zeile und Datei existieren - der Weg durch das
     * Formular wird in testUploadUeberDenPferdeabschnitt() gegangen.
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

        // 1. Weder Kachel noch eigene Seite (#120): Die Kachel führte auf eine
        //    Seite, die dasselbe Pferd über eine zweite Suche erneut
        //    heraussuchen ließ, obwohl die Pflege im Pferdedatensatz steht.
        $dashboard = $admin->get('/admin');
        $this->assertStringNotContainsString(
            self::ALTE_SEITE . '"',
            $dashboard->body,
            'Das Dashboard darf keine Kachel auf die entfallene Verwaltungsseite mehr enthalten (#120).'
        );

        $this->assertSame(
            404,
            $admin->get(self::ALTE_SEITE)->statusCode,
            'Die addoneigene Verwaltungsseite ist mit #120 entfallen.'
        );
        $this->assertEigeneSuchrouteEntfallen($admin, '/plugin/gesundheitstests/suche');

        $unique = uniqid();
        $horseName = "GesundheitTestPferd-{$unique}";
        $horseId = $this->createHorse($admin, $horseName, ['status' => 'active']);

        // 2. Der Abschnitt hängt im Bearbeitungsformular des Pferdes, mit der
        //    horse_id aus dem Aufrufkontext statt einer zweiten Pferdesuche.
        $form = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $form->statusCode);
        $this->assertStringContainsString('🩺 Gesundheitstests', $form->body);
        $this->assertStringContainsString(self::ALTE_SEITE . '/store', $form->body);
        $this->assertMatchesRegularExpression(
            '/name="horse_id" value="' . $horseId . '"/',
            $form->body,
            'Die horse_id muss aus dem Kontext stammen, nicht erneut gesucht werden.'
        );
        $this->assertStringNotContainsString('list="horse_q_liste"', $form->body);
        $this->assertStringNotContainsString('<datalist', $form->body);

        // 2b. Die Falle aus #120: Der Abschnitt steht AUSSERHALB des
        //     Kern-Formulars und muss die Kodierung selbst mitbringen - ohne
        //     enctype käme der Upload als leeres $_FILES an, ohne Fehlermeldung.
        $this->assertMatchesRegularExpression(
            '/<form[^>]*action="' . preg_quote(self::ALTE_SEITE . '/store', '/') . '"[^>]*enctype="multipart\/form-data"/',
            $form->body,
            'Das Formular des Abschnitts muss enctype="multipart/form-data" tragen (#120).'
        );

        // Vollständigkeit (#120): Jedes Feld, das store() auswertet, muss der
        // Abschnitt anbieten - solange eines fehlt, wäre die alte Seite nicht
        // überflüssig, sondern die Integration unfertig.
        foreach (['test_type', 'result_summary', 'issued_by', 'issued_at', 'is_public', 'document'] as $feld) {
            $this->assertStringContainsString(
                'name="' . $feld . '"',
                $form->body,
                "Der Abschnitt muss das Feld '{$feld}' anbieten (#120)."
            );
        }

        // 2c. Opt-in: Das Freigabe-Kästchen ist leer vorbelegt. Ein `checked`
        //     hier wäre die Umkehr der Vorgabe, um die es in diesem Addon geht.
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*name="is_public"[^>]*checked/',
            $form->body,
            'Das Freigabe-Kästchen muss leer vorbelegt sein - Gesundheitsdaten sind Opt-in (#120).'
        );

        // 3. Eintrag OHNE Öffentlich-Flag anlegen: erscheint NICHT auf der
        //    öffentlichen Detailseite (Opt-in-Prinzip, Standard aus).
        $csrf = $form->formField('csrf_token') ?? '';
        $privateType = "Röntgen-{$unique}";
        $storePrivate = $admin->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $csrf,
            'horse_id' => (string) $horseId,
            'test_type' => $privateType,
            'result_summary' => 'Ohne Befund.',
        ]);
        $this->assertSame(
            '/admin/horses/edit?id=' . $horseId,
            $storePrivate->location(),
            'Der Rückweg führt auf das Pferd, nicht auf die entfallene Seite (#120).'
        );

        $visitor = $this->newClient();
        $detailPrivate = $visitor->get("/horse?id={$horseId}");
        $this->assertSame(200, $detailPrivate->statusCode);
        $this->assertStringNotContainsString(
            $privateType,
            $detailPrivate->body,
            'Nicht freigegebene Gesundheitsdaten dürfen nie öffentlich erscheinen (Opt-in).'
        );

        // ... im Abschnitt steht er trotzdem, mit ausgewiesener Freigabe.
        $formNachPrivat = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertStringContainsString($privateType, $formNachPrivat->body);
        $this->assertStringContainsString('Ohne Befund.', $formNachPrivat->body);

        // 4. Eintrag MIT Öffentlich-Flag anlegen: erscheint auf der Detailseite.
        $publicType = "DNA-Abstammungstest-{$unique}";
        $storePublic = $admin->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $csrf,
            'horse_id' => (string) $horseId,
            'test_type' => $publicType,
            'result_summary' => 'Abstammung bestätigt.',
            'issued_by' => 'Testlabor',
            'issued_at' => '2024-03-01',
            'is_public' => '1',
        ]);
        $this->assertSame('/admin/horses/edit?id=' . $horseId, $storePublic->location());

        $detailPublic = $visitor->get("/horse?id={$horseId}");
        $this->assertStringContainsString('DNA-/Gesundheitstests', $detailPublic->body);
        $this->assertStringContainsString($publicType, $detailPublic->body);
        $this->assertStringContainsString('Abstammung bestätigt.', $detailPublic->body);
        $this->assertStringContainsString('Testlabor', $detailPublic->body);
        $this->assertStringNotContainsString($privateType, $detailPublic->body);

        // 5. Ein Eintrag ohne Pferd im Kontext (POST von Hand, erfundene ID)
        //    legt nichts an und endet in der Pferdeliste statt in einem 500er.
        $storeOhnePferd = $admin->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $csrf,
            'horse_id' => '999999',
            'test_type' => "Verwaist-{$unique}",
        ]);
        $this->assertSame('/admin/horses', $storeOhnePferd->location());
        $this->assertSame(
            0,
            $this->countEntries("Verwaist-{$unique}"),
            'Eine erfundene horse_id darf keinen Eintrag anlegen.'
        );

        // 6. Download-Route: unbekannte ID und Eintrag ohne Dokument liefern
        //    identisch 404 (kein Existenz-Orakel).
        $unknownDownload = $visitor->get('/plugin/gesundheitstests/download?id=999999');
        $this->assertSame(404, $unknownDownload->statusCode);

        preg_match(
            '/action="' . preg_quote(self::ALTE_SEITE . '/delete', '/') . '".*?name="id" value="(\d+)"/s',
            $admin->get('/admin/horses/edit?id=' . $horseId)->body,
            $idMatch
        );
        $this->assertNotEmpty($idMatch, 'Konnte ID eines erfassten Eintrags nicht aus dem Abschnitt ermitteln.');
        $noFileDownload = $visitor->get('/plugin/gesundheitstests/download?id=' . $idMatch[1]);
        $this->assertSame(404, $noFileDownload->statusCode);

        // 7. Fail-closed: Ohne gesundheitstests.manage erscheint der Abschnitt
        //    gar nicht - kein Formular, das beim Absenden 403 liefert -, und
        //    die POST-Route weist ab.
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "gtester{$unique}",
            "gesundheitstests-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $editorFormOhneRecht = $editor->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $editorFormOhneRecht->statusCode);
        $this->assertStringNotContainsString(
            self::ALTE_SEITE . '/store',
            $editorFormOhneRecht->body,
            'Ohne gesundheitstests.manage darf der Abschnitt nicht erscheinen.'
        );
        $this->assertStringNotContainsString(
            $privateType,
            $editorFormOhneRecht->body,
            'Ohne die Berechtigung dürfen auch die erfassten Gesundheitsdaten nicht im Formular stehen.'
        );

        $deniedResponse = $editor->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $editorFormOhneRecht->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'test_type' => "Unerlaubt-{$unique}",
        ]);
        $this->assertSame(403, $deniedResponse->statusCode);

        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'gesundheitstests' => ['manage'],
        ]);

        $editorFormMitRecht = $editor->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $editorFormMitRecht->statusCode);
        $this->assertStringContainsString(self::ALTE_SEITE . '/store', $editorFormMitRecht->body);
        $this->assertStringContainsString($privateType, $editorFormMitRecht->body);

        // 8. Löschen aus dem Abschnitt heraus entfernt den Eintrag wieder von
        //    der Detailseite; der Rückweg führt auf das Pferd.
        $formVorLoeschen = $admin->get('/admin/horses/edit?id=' . $horseId);
        preg_match_all(
            '/action="' . preg_quote(self::ALTE_SEITE . '/delete', '/') . '".*?name="id" value="(\d+)"/s',
            $formVorLoeschen->body,
            $allIds
        );
        $this->assertNotEmpty($allIds[1], 'Der Abschnitt muss je Eintrag ein Löschformular tragen.');
        foreach ($allIds[1] as $entryId) {
            $deleteResponse = $admin->post(self::ALTE_SEITE . '/delete', [
                'csrf_token' => $formVorLoeschen->formField('csrf_token') ?? '',
                'id' => $entryId,
            ]);
            $this->assertSame('/admin/horses/edit?id=' . $horseId, $deleteResponse->location());
        }

        $detailAfterDelete = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('DNA-/Gesundheitstests', $detailAfterDelete->body);
    }

    /**
     * #120: Der Upload geht den echten Weg - Formular des Pferdeabschnitts,
     * multipart-POST, Ablage außerhalb des Webroots, Auslieferung über die
     * Download-Route.
     *
     * Das prüft die zweite Hälfte der enctype-Falle: Das Attribut im HTML
     * belegt testFullPluginLifecycle(), dass store() eine hochgeladene Datei
     * aus dem Abschnitt heraus tatsächlich annimmt und wiederfindet, belegt
     * dieser Test. Vorher ging der Upload in diesem Repo überhaupt nicht durch
     * das Formular - die Zeilen wurden direkt in die Datenbank geschrieben.
     */
    public function testUploadUeberDenPferdeabschnitt(): void {
        $admin = $this->authenticatedClient();
        $this->enablePlugin($admin);

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "GTestUpload-{$unique}", ['status' => 'active']);
        $form = $admin->get('/admin/horses/edit?id=' . $horseId);

        $testArt = "Upload-Befund-{$unique}";
        $antwort = $admin->postFile(
            self::ALTE_SEITE . '/store',
            [
                'csrf_token' => $form->formField('csrf_token') ?? '',
                'horse_id' => (string) $horseId,
                'test_type' => $testArt,
                'is_public' => '1',
            ],
            'document',
            "befund-{$unique}.pdf",
            self::PDF_INHALT,
            'application/pdf'
        );
        $this->assertSame('/admin/horses/edit?id=' . $horseId, $antwort->location());

        // Der Abschnitt verweist auf das Dokument ...
        $formNachUpload = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertStringContainsString($testArt, $formNachUpload->body);
        $this->assertMatchesRegularExpression(
            '#/plugin/gesundheitstests/download\?id=\d+#',
            $formNachUpload->body,
            'Ein Eintrag mit Dokument muss im Abschnitt einen Download-Verweis tragen.'
        );
        preg_match('#/plugin/gesundheitstests/download\?id=(\d+)#', $formNachUpload->body, $treffer);

        // ... und die Datei kommt durch die Download-Route zurück. Käme der
        // Upload als leeres $_FILES an (fehlendes enctype), stünde hier gar
        // kein Verweis, und dieser Abruf liefe in die 404 des Gates.
        $antwortDatei = $admin->get('/plugin/gesundheitstests/download?id=' . $treffer[1]);
        $this->assertSame(200, $antwortDatei->statusCode);
        $this->assertStringContainsString('%PDF', $antwortDatei->body);
        $this->assertSame('application/pdf', $antwortDatei->header('Content-Type'));

        // Die Ablage liegt außerhalb des Webroots - der Dateiname taucht in
        // keiner Antwort auf, und der rohe Pfad ist nicht abrufbar.
        $this->assertStringNotContainsString(
            "befund-{$unique}.pdf",
            $admin->get('/uploads/plugin_gesundheitstests/')->body
        );
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

    /**
     * #134: Das LÖSCHEN eines Gesundheitsdokuments ist der wichtigste Fall
     * des ganzen Issues - Gesundheitsdaten sind der heikelste Bestand im
     * Verzeichnis, und ihr Verschwinden war bisher im Protokoll nicht zu
     * sehen. Ein Protokoll, das an dieser Stelle schweigt, beweist scheinbar,
     * daß nichts passiert ist.
     *
     * Geprüft wird deshalb beides zusammen: daß der Eintrag entsteht und
     * aussagekräftig ist (Eintrag, Pferd, Untersuchungsart, entfernte Datei) -
     * und daß er den INHALT nicht mitnimmt. Das Protokoll wird dauerhaft
     * aufbewahrt; stünde die Ergebnis-Zusammenfassung darin, überlebte
     * ausgerechnet der sensible Teil die Löschung, um die es geht.
     *
     * Gegenprobe gelaufen: Ohne die AuditLogger::log()-Aufrufe in
     * VerwaltungController::store()/delete() findet die Abfrage keine
     * Einträge, und der Test schlägt an den assertCount(1)-Zeilen fehl.
     */
    public function testAnlegenUndLoeschenStehenImProtokoll(): void {
        $admin = $this->authenticatedClient();
        $this->enablePlugin($admin);

        $unique = uniqid();
        $horseName = "GTestProtokoll-{$unique}";
        $horseId = $this->createHorse($admin, $horseName, ['status' => 'active']);

        // 1. Anlegen über den Pferdeabschnitt. Die Ergebnis-Zusammenfassung ist
        // bewusst wiedererkennbar - unten wird belegt, daß sie NICHT im
        // Protokoll steht.
        $form = $admin->get('/admin/horses/edit?id=' . $horseId);
        $testArt = "Röntgenbefund-{$unique}";
        $befund = "Vertraulicher Befundtext {$unique}";
        $admin->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'test_type' => $testArt,
            'result_summary' => $befund,
            'issued_by' => "Tierklinik {$unique}",
        ]);

        $angelegt = $this->protokollEintraege('Gesundheitstest-Eintrag angelegt', "Pferd #{$horseId} ");
        $this->assertCount(1, $angelegt, 'Ein angelegter Gesundheitstest-Eintrag muss genau einen Protokolleintrag erzeugen.');
        $angelegtDetails = (string) $angelegt[0]['details'];
        $this->assertStringContainsString($horseName, $angelegtDetails, 'Der Eintrag muss das betroffene Pferd benennen.');
        $this->assertStringContainsString($testArt, $angelegtDetails, 'Ohne die Untersuchungsart ist nicht erkennbar, worum es ging.');
        $this->assertStringContainsString('öffentlich: nein', $angelegtDetails, 'Die Freigabe entscheidet über die öffentliche Sichtbarkeit und gehört in den Nachweis.');
        $this->assertStringNotContainsString($befund, $angelegtDetails, 'Die Ergebnis-Zusammenfassung gehört nicht ins dauerhafte Protokoll.');

        // 2. Löschen eines Eintrags MIT Dokument - der Fall aus dem Issue.
        $entryId = $this->insertEntryWithDocument($horseId, false, "protokoll_{$unique}");
        $dateiName = "gtest_functional_protokoll_{$unique}.pdf";
        $ablage = \FRAMEWORK_VENDOR_DIR . '/storage/plugin_gesundheitstests';
        $this->assertFileExists($ablage . '/' . $dateiName, 'Voraussetzung: Das Dokument muss vor dem Löschen in der Ablage liegen.');

        $formVorLoeschen = $admin->get('/admin/horses/edit?id=' . $horseId);
        $admin->post(self::ALTE_SEITE . '/delete', [
            'csrf_token' => $formVorLoeschen->formField('csrf_token') ?? '',
            'id' => (string) $entryId,
        ]);

        $this->assertFileDoesNotExist(
            $ablage . '/' . $dateiName,
            'Voraussetzung des Protokollfalls: Das Dokument muss beim Löschen wirklich verschwinden.'
        );

        $geloescht = $this->protokollEintraege('Gesundheitstest-Eintrag gelöscht', "Eintrag #{$entryId},");
        $this->assertCount(
            1,
            $geloescht,
            'Das Löschen eines Gesundheitsdokuments muss protokolliert werden - genau das fehlte (#134).'
        );

        $details = (string) $geloescht[0]['details'];
        $this->assertStringContainsString($horseName, $details, 'Ohne Angabe, zu welchem Pferd das Dokument gehörte, hilft der Eintrag niemandem.');
        $this->assertStringContainsString($dateiName, $details, 'Der Eintrag muss die entfernte Datei benennen.');
        $this->assertStringContainsString('entfernt', $details, 'Der Eintrag muss festhalten, daß das Dokument entfernt wurde.');
        $this->assertStringNotContainsString(
            "befund-protokoll_{$unique}.pdf",
            $details,
            'Der frei wählbare Originaldateiname kann personenbezogen sein und gehört nicht ins dauerhafte Protokoll.'
        );
    }

    /**
     * Audit M27: „Deinstallieren → Daten löschen“ entfernt Tabelle UND
     * Dokumentablage. Bis 1.2.0 fehlte das Datenregister: Die Rückfrage
     * meldete „rückstandsfrei“, beides blieb stehen, und nach erneuter
     * Aktivierung waren die Gesundheitsdaten samt Download-IDs wieder da.
     */
    public function testDeinstallationMitDatenLoeschenEntferntTabelleUndAblage(): void {
        $admin = $this->authenticatedClient();
        $this->enablePlugin($admin);

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "GTestDeinstallation-{$unique}", ['status' => 'active']);
        $this->dokumentHochladen($admin, $horseId, "Deinstallation-{$unique}");
        $eintragId = $this->eintragId("Deinstallation-{$unique}");
        $this->assertSame(200, $admin->get('/plugin/gesundheitstests/download?id=' . $eintragId)->statusCode);

        try {
            $rueckfrage = $this->deinstallierenMitDaten($admin, self::SLUG);
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($rueckfrage->body, '<code>plugin_gesundheitstests</code>'),
                'Die Rückfrage muss Tabelle und Ablage (basename) nennen.'
            );
            $this->assertStringNotContainsString('rückstandsfrei', $rueckfrage->body);

            $this->assertFalse($this->tabelleVorhanden('plugin_gesundheitstests'), 'Die Tabelle muss verschwinden.');
            $this->assertDirectoryDoesNotExist(self::ablage(), 'Die Dokumentablage muss verschwinden.');
            $protokoll = $this->deinstallationsProtokoll(self::SLUG);
            $this->assertStringContainsString('Verzeichnis plugin_gesundheitstests entfernt.', $protokoll);
            $this->assertStringNotContainsString('WARNUNG', $protokoll, $protokoll);
        } finally {
            $this->aktivieren($admin, self::SLUG);
        }

        $this->assertSame(
            0,
            (int) \App\Database::getInstance()->query('SELECT COUNT(*) FROM `plugin_gesundheitstests`')->fetchColumn(),
            'Nach erneuter Aktivierung ist die Tabelle leer.'
        );
        $this->assertSame(404, $admin->get('/plugin/gesundheitstests/download?id=' . $eintragId)->statusCode);
    }

    /**
     * Audit N26: Das endgültige Löschen eines Pferdes nimmt seine Dokumente
     * mit. Der FK-Cascade entfernte bisher nur die Zeilen - die Dateien
     * blieben ohne Zeile liegen, unsichtbar und nicht mehr löschbar.
     *
     * Der Papierkorb allein lässt sie stehen (Wiederherstellung). Das
     * Protokoll nennt die Anzahl, aber keinen Originalnamen.
     */
    public function testEndgueltigesLoeschenEntferntDokumente(): void {
        $admin = $this->authenticatedClient();
        $this->enablePlugin($admin);

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "GTestEndgueltig-{$unique}", ['status' => 'active']);
        $datei = $this->dokumentHochladen($admin, $horseId, "Endgueltig-{$unique}", "endgueltig-{$unique}.pdf");

        $this->inDenPapierkorb($admin, $horseId);
        $this->assertFileExists(self::ablage() . '/' . $datei, 'Im Papierkorb bleibt das Dokument (Wiederherstellung).');

        $antwort = $admin->post('/admin/trash/permanent-delete', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'type' => 'horse',
            'id' => (string) $horseId,
        ]);
        $this->assertSame('/admin/trash?success=purged', $antwort->location(), $antwort->body);
        $this->assertFileDoesNotExist(self::ablage() . '/' . $datei, 'Mit dem Pferd muss sein Dokument verschwinden.');

        $eintraege = $this->protokollEintraege('Gesundheitstest-Dokumente mit Pferd endgültig gelöscht', "Pferd #{$horseId} (");
        $this->assertCount(1, $eintraege);
        $this->assertStringContainsString('1 Dokument(e) entfernt', (string) $eintraege[0]['details']);
        $this->assertStringContainsString("GTestEndgueltig-{$unique}", (string) $eintraege[0]['details']);
        $this->assertStringNotContainsString("endgueltig-{$unique}.pdf", (string) $eintraege[0]['details']);
    }

    /**
     * Audit N26: „Papierkorb leeren“ löscht in Chargen und feuert die Hooks
     * je Pferd - beide Dokumente verschwinden, das eines nicht gelöschten
     * Pferdes bleibt.
     */
    public function testPapierkorbLeerenEntferntDokumenteAllerPferde(): void {
        $admin = $this->authenticatedClient();
        $this->enablePlugin($admin);

        $unique = uniqid();
        $ersterId = $this->createHorse($admin, "GTestLeeren1-{$unique}", ['status' => 'active']);
        $zweiterId = $this->createHorse($admin, "GTestLeeren2-{$unique}", ['status' => 'active']);
        $bleibtId = $this->createHorse($admin, "GTestLeerenBleibt-{$unique}", ['status' => 'active']);
        $erste = $this->dokumentHochladen($admin, $ersterId, "Leeren1-{$unique}");
        $zweite = $this->dokumentHochladen($admin, $zweiterId, "Leeren2-{$unique}");
        $bleibt = $this->dokumentHochladen($admin, $bleibtId, "LeerenBleibt-{$unique}");

        $this->inDenPapierkorb($admin, $ersterId);
        $this->inDenPapierkorb($admin, $zweiterId);

        $antwort = $admin->post('/admin/trash/empty', ['csrf_token' => $this->currentCsrfToken($admin)]);
        $this->assertSame('/admin/trash?success=emptied', $antwort->location(), $antwort->body);

        $this->assertFileDoesNotExist(self::ablage() . '/' . $erste);
        $this->assertFileDoesNotExist(self::ablage() . '/' . $zweite);
        $this->assertFileExists(self::ablage() . '/' . $bleibt, 'Das Dokument eines nicht gelöschten Pferdes bleibt.');
        $this->assertSame(1, $this->countEntries("LeerenBleibt-{$unique}"));
    }

    /** Audit N26: Papierkorb und Wiederherstellung lassen das Dokument unberührt. */
    public function testWiederherstellungBehaeltDokument(): void {
        $admin = $this->authenticatedClient();
        $this->enablePlugin($admin);

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "GTestWiederher-{$unique}", ['status' => 'active']);
        $datei = $this->dokumentHochladen($admin, $horseId, "Wiederher-{$unique}");

        $this->inDenPapierkorb($admin, $horseId);
        $antwort = $admin->post('/admin/trash/restore', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'type' => 'horse',
            'id' => (string) $horseId,
        ]);
        $this->assertNotNull($antwort->location(), $antwort->body);

        $this->assertFileExists(self::ablage() . '/' . $datei);
        $download = $admin->get('/plugin/gesundheitstests/download?id=' . $this->eintragId("Wiederher-{$unique}"));
        $this->assertSame(200, $download->statusCode);
        $this->assertStringContainsString('%PDF', $download->body);
    }

    /**
     * Audit N26: Eine zu lange Zusammenfassung lief bisher erst beim INSERT
     * auf den Fehler (500) - die Datei lag da schon in der Ablage, ohne Zeile.
     * Jetzt: Hinweis, keine Datei, keine Zeile.
     */
    public function testZuLangeZusammenfassungHinterlaesstKeineWaise(): void {
        $this->pruefeAbgewieseneEingabe(['result_summary' => str_repeat('x', 70000)], 'Lang');
    }

    /** Audit N26: Ein ungültiges Datum wird ebenso vor dem Upload abgewiesen. */
    public function testUngueltigesDatumWirdAbgelehnt(): void {
        $this->pruefeAbgewieseneEingabe(['issued_at' => '2026-13-45'], 'Datum');
    }

    /**
     * Audit N26: file_original_name ist VARCHAR(255). Ein längerer Name ließ
     * den INSERT scheitern und die Datei verwaist zurück.
     */
    public function testUeberlangerOriginalnameWirdGekuerzt(): void {
        $admin = $this->authenticatedClient();
        $this->enablePlugin($admin);

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "GTestLangerName-{$unique}", ['status' => 'active']);
        $name = str_repeat('b', 296) . '.pdf';
        $datei = $this->dokumentHochladen($admin, $horseId, "LangerName-{$unique}", $name);

        $this->assertFileExists(self::ablage() . '/' . $datei);
        $stmt = \App\Database::getInstance()->prepare(
            'SELECT file_original_name FROM `plugin_gesundheitstests` WHERE test_type = ?'
        );
        $stmt->execute(["LangerName-{$unique}"]);
        $this->assertSame(substr($name, 0, 255), $stmt->fetchColumn());
    }

    /**
     * Audit N26, Altbestand: install() räumt Dokumente ohne Zeile ab - aber
     * nur selbst vergebene Ablagenamen, die älter als 24 Stunden sind.
     */
    public function testInstallRaeumtAlteWaisenAuf(): void {
        $admin = $this->authenticatedClient();
        $this->enablePlugin($admin);

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "GTestWaisen-{$unique}", ['status' => 'active']);
        $ablage = self::ablage();
        if (!is_dir($ablage)) {
            mkdir($ablage, 0750, true);
        }
        $alt = time() - 3 * 86400;
        $name = static fn(): string => 'gtest_' . (time() - 3 * 86400) . '_' . bin2hex(random_bytes(8)) . '.pdf';

        $alteWaise = $name();
        $alteReferenzierte = $name();
        $frischeWaise = $name();
        $fremd = "fremd_{$unique}.pdf";
        foreach ([$alteWaise, $alteReferenzierte, $frischeWaise, $fremd] as $datei) {
            file_put_contents($ablage . '/' . $datei, self::PDF_INHALT);
        }
        touch($ablage . '/' . $alteWaise, $alt);
        touch($ablage . '/' . $alteReferenzierte, $alt);
        touch($ablage . '/' . $fremd, $alt);
        \App\Database::getInstance()->prepare(
            'INSERT INTO `plugin_gesundheitstests` (horse_id, test_type, file_name, file_mime) VALUES (?, ?, ?, ?)'
        )->execute([$horseId, "Waisen-{$unique}", $alteReferenzierte, 'application/pdf']);

        try {
            // install() läuft bei jeder Aktivierung.
            $admin->post('/admin/plugins/toggle', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'slug' => self::SLUG,
                'enable' => '0',
            ]);
            $this->enablePlugin($admin);

            $this->assertFileDoesNotExist($ablage . '/' . $alteWaise, 'Eine alte Datei ohne Zeile ist eine Waise.');
            $this->assertFileExists($ablage . '/' . $alteReferenzierte, 'Eine referenzierte Datei bleibt, egal wie alt.');
            $this->assertFileExists($ablage . '/' . $frischeWaise, 'Jünger als 24 Stunden: womöglich ein laufender Upload.');
            $this->assertFileExists($ablage . '/' . $fremd, 'Fremd benannte Dateien fasst die Bereinigung nicht an.');
            $this->assertNotEmpty($this->protokollEintraege('Verwaiste Gesundheitstest-Dokumente entfernt', 'Dokument(e)'));
        } finally {
            @unlink($ablage . '/' . $frischeWaise);
            @unlink($ablage . '/' . $fremd);
        }
    }

    /**
     * Gemeinsamer Ablauf der abgewiesenen Eingaben: Upload MIT Dokument,
     * Rückweg mit Hinweis, keine neue Datei, keine Zeile, kein 500.
     *
     * @param array<string, string> $felder
     */
    private function pruefeAbgewieseneEingabe(array $felder, string $art): void {
        $admin = $this->authenticatedClient();
        $this->enablePlugin($admin);

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "GTestAbgewiesen{$art}-{$unique}", ['status' => 'active']);
        $form = $admin->get('/admin/horses/edit?id=' . $horseId);
        $testArt = "Abgewiesen{$art}-{$unique}";
        $vorher = $this->dateienInAblage();

        $antwort = $admin->postFile(
            self::ALTE_SEITE . '/store',
            ['csrf_token' => $form->formField('csrf_token') ?? '', 'horse_id' => (string) $horseId, 'test_type' => $testArt] + $felder,
            'document',
            "abgewiesen-{$unique}.pdf",
            self::PDF_INHALT,
            'application/pdf'
        );

        $this->assertSame('/admin/horses/edit?id=' . $horseId . '&gtest_fehler=eingabe', $antwort->location(), $antwort->body);
        $this->assertSame(0, $this->countEntries($testArt), 'Keine Zeile.');
        $this->assertSame($vorher, $this->dateienInAblage(), 'Keine neue Datei in der Ablage.');

        $hinweis = $admin->get('/admin/horses/edit?id=' . $horseId . '&gtest_fehler=eingabe');
        $this->assertStringContainsString('Der Test wurde nicht gespeichert', $hinweis->body);
        // Ein fremder Wert zeigt nichts und wird nie ausgegeben.
        $fremd = $admin->get('/admin/horses/edit?id=' . $horseId . '&gtest_fehler=fremd' . $unique);
        $this->assertStringNotContainsString('Der Test wurde nicht gespeichert', $fremd->body);
        $this->assertStringNotContainsString('fremd' . $unique, $fremd->body);
    }

    /**
     * Lädt über den Pferdeabschnitt ein PDF hoch und liefert den selbst
     * vergebenen Ablagenamen aus der Datenbank.
     */
    private function dokumentHochladen(\Tests\Support\HttpClient $admin, int $horseId, string $testArt, ?string $originalName = null): string {
        $form = $admin->get('/admin/horses/edit?id=' . $horseId);
        $antwort = $admin->postFile(
            self::ALTE_SEITE . '/store',
            ['csrf_token' => $form->formField('csrf_token') ?? '', 'horse_id' => (string) $horseId, 'test_type' => $testArt],
            'document',
            $originalName ?? 'befund.pdf',
            self::PDF_INHALT,
            'application/pdf'
        );
        $this->assertSame('/admin/horses/edit?id=' . $horseId, $antwort->location(), $antwort->body);

        $stmt = \App\Database::getInstance()->prepare(
            'SELECT file_name FROM `plugin_gesundheitstests` WHERE test_type = ? AND horse_id = ?'
        );
        $stmt->execute([$testArt, $horseId]);
        $datei = (string) $stmt->fetchColumn();
        $this->assertMatchesRegularExpression('/^gtest_\d+_[0-9a-f]{16}\.pdf$/', $datei);
        $this->assertFileExists(self::ablage() . '/' . $datei);
        return $datei;
    }

    private function eintragId(string $testArt): int {
        $stmt = \App\Database::getInstance()->prepare('SELECT id FROM `plugin_gesundheitstests` WHERE test_type = ?');
        $stmt->execute([$testArt]);
        $id = (int) $stmt->fetchColumn();
        $this->assertGreaterThan(0, $id, "Kein Eintrag '{$testArt}'.");
        return $id;
    }

    /** Verschiebt ein Pferd über den Kern-Weg in den Papierkorb. */
    private function inDenPapierkorb(\Tests\Support\HttpClient $admin, int $horseId): void {
        $antwort = $admin->post('/admin/horses/delete', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string) $horseId,
        ]);
        $this->assertNotNull($antwort->location(), $antwort->body);
        $stmt = \App\Database::getInstance()->prepare('SELECT deleted_at FROM horses WHERE id = ?');
        $stmt->execute([$horseId]);
        $this->assertNotEmpty($stmt->fetchColumn(), 'Das Pferd muss im Papierkorb liegen.');
    }

    /** @return string[] */
    private function dateienInAblage(): array {
        $dateien = is_dir(self::ablage()) ? array_values(array_diff(scandir(self::ablage()) ?: [], ['.', '..'])) : [];
        sort($dateien);
        return $dateien;
    }

    private static function ablage(): string {
        return \FRAMEWORK_VENDOR_DIR . '/storage/plugin_gesundheitstests';
    }

    /**
     * Zählt Einträge einer Untersuchungsart direkt in der Datenbank - der
     * unabhängige Nachweis dafür, dass ein verworfener POST wirklich nichts
     * angelegt hat (eine leere Seite belegt das nicht).
     */
    private function countEntries(string $testType): int {
        $stmt = \App\Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM `plugin_gesundheitstests` WHERE test_type = :art'
        );
        $stmt->execute(['art' => $testType]);
        return (int) $stmt->fetchColumn();
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
