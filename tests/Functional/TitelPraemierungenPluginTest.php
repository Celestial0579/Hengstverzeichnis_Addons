<?php
// tests/Functional/TitelPraemierungenPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/titel-praemierungen gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 *
 * Seit #117 läuft die Pflege ausschließlich über den Abschnitt im
 * Bearbeitungsformular des Pferdes (Kern-Hook `horse.edit_sections`); die
 * addoneigene Verwaltungsseite und ihre Dashboard-Kachel sind entfallen.
 * Der Test hält deshalb beides fest: dass der Pferdeabschnitt den vollen
 * Umfang trägt (anlegen, ändern, löschen) - und dass die alte Seite samt
 * Kachel wirklich weg ist.
 */
class TitelPraemierungenPluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'titel-praemierungen';

    /** Die entfallene Verwaltungsseite (#117) - hier nur noch als Negativprobe. */
    private const ALTE_SEITE = '/plugin/titel-praemierungen/auszeichnungen';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        // Bewusst ohne '&' geprüft: das Kaufmanns-Und des Plugin-Namens
        // ("Titel & Prämierungen") erscheint im HTML als Entity.
        $this->assertStringContainsString('Prämierungen', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        // 1. Weder Kachel noch eigene Seite (#117): Die Kachel führte auf eine
        //    Seite, die dasselbe Pferd über eine zweite Suche erneut
        //    heraussuchen ließ, obwohl die Pflege im Pferdedatensatz steht.
        $dashboard = $admin->get('/admin');
        $this->assertStringNotContainsString(
            self::ALTE_SEITE,
            $dashboard->body,
            'Das Dashboard darf keine Kachel auf die entfallene Verwaltungsseite mehr enthalten (#117).'
        );

        $this->assertSame(
            404,
            $admin->get(self::ALTE_SEITE)->statusCode,
            'Die addoneigene Verwaltungsseite ist mit #117 entfallen.'
        );
        $this->assertSame(
            404,
            $admin->get(self::ALTE_SEITE . '/suche?q=Test')->statusCode,
            'Die JSON-Pferdesuche diente allein der entfallenen Seite (#117).'
        );

        $unique = uniqid();
        $horseName = "TPTestPferd-{$unique}";
        $horseId = $this->createHorse($admin, $horseName, ['status' => 'active']);

        // 2. Der Abschnitt hängt im Bearbeitungsformular des Pferdes, mit der
        //    horse_id aus dem Aufrufkontext statt einer zweiten Pferdesuche.
        $form = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $form->statusCode);
        $this->assertStringContainsString('Titel &amp; Prämierungen', $form->body);
        $this->assertStringContainsString(self::ALTE_SEITE . '/store', $form->body);
        $this->assertMatchesRegularExpression(
            '/name="horse_id" value="' . $horseId . '"/',
            $form->body,
            'Die horse_id muss aus dem Kontext stammen, nicht erneut gesucht werden.'
        );
        // Und ausdrücklich nicht: die Pferdeauswahl der alten Seite.
        $this->assertStringNotContainsString('list="horse_q_liste"', $form->body);

        // 3. Vor dem Erfassen: kein Auszeichnungs-Abschnitt auf der Detailseite.
        $visitor = $this->newClient();
        $detailBefore = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('Titel &amp; Prämierungen', $detailBefore->body);

        // 4. Anlegen aus dem Pferdeabschnitt heraus - der Rückweg führt auf
        //    das Pferd zurück, nicht auf eine bestandsweite Liste.
        $csrf = $form->formField('csrf_token') ?? '';
        $bezeichnung = "Elitehengst-{$unique}";
        $storeResponse = $admin->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $csrf,
            'horse_id' => (string) $horseId,
            'art' => 'titel',
            'bezeichnung' => $bezeichnung,
            'jahr' => '2019',
            'kommentar' => 'Verliehen auf der Hauptkörung.',
        ]);
        $this->assertSame('/admin/horses/edit?id=' . $horseId, $storeResponse->location());

        // 4b. Eine Art außerhalb der ENUM-Whitelist wird still verworfen.
        $invalidBezeichnung = "Ungueltig-{$unique}";
        $invalidResponse = $admin->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $csrf,
            'horse_id' => (string) $horseId,
            'art' => 'pokal',
            'bezeichnung' => $invalidBezeichnung,
        ]);
        $this->assertSame('/admin/horses/edit?id=' . $horseId, $invalidResponse->location());

        // 5. Der Abschnitt listet die Auszeichnung des Pferdes, die verworfene
        //    nicht - und trägt für sie ein Änderungsformular.
        $formNachStore = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertStringContainsString($bezeichnung, $formNachStore->body);
        $this->assertStringNotContainsString($invalidBezeichnung, $formNachStore->body);
        $this->assertStringContainsString(self::ALTE_SEITE . '/update', $formNachStore->body);

        // 6. Öffentliche Detailseite zeigt die Auszeichnung automatisch an.
        $detailAfter = $visitor->get("/horse?id={$horseId}");
        $this->assertStringContainsString('Titel &amp; Prämierungen', $detailAfter->body);
        $this->assertStringContainsString($bezeichnung, $detailAfter->body);
        $this->assertStringContainsString('2019', $detailAfter->body);
        $this->assertStringContainsString('Verliehen auf der Hauptkörung.', $detailAfter->body);

        // 7. Ändern (#117). Das konnte vorher KEINER der beiden Wege - mit dem
        //    Wegfall der Verwaltungsseite wäre der Pferdeabschnitt der einzige
        //    Pflegeweg geblieben, also gehört das Ändern dorthin.
        //
        //    Die ID gezielt aus dem Änderungsformular lesen: Das
        //    Kern-Formular des Pferdes führt selbst ein Feld "id".
        $this->assertMatchesRegularExpression(
            '/action="' . preg_quote(self::ALTE_SEITE . '/update', '/') . '".*?name="id" value="\d+"/s',
            $formNachStore->body,
            'Der Abschnitt muss je Eintrag ein Änderungsformular tragen.'
        );
        preg_match(
            '/action="' . preg_quote(self::ALTE_SEITE . '/update', '/') . '".*?name="id" value="(\d+)"/s',
            $formNachStore->body,
            $idMatch
        );
        $eintragId = $idMatch[1];

        $geaendert = "Bundeschampion-{$unique}";
        $updateResponse = $admin->post(self::ALTE_SEITE . '/update', [
            'csrf_token' => $csrf,
            'id' => $eintragId,
            'art' => 'erfolg',
            'bezeichnung' => $geaendert,
            'jahr' => '2021',
            'kommentar' => 'Nachgetragen.',
        ]);
        $this->assertSame('/admin/horses/edit?id=' . $horseId, $updateResponse->location());

        $detailNachAenderung = $visitor->get("/horse?id={$horseId}");
        $this->assertStringContainsString($geaendert, $detailNachAenderung->body);
        $this->assertStringContainsString('2021', $detailNachAenderung->body);
        $this->assertStringContainsString('Nachgetragen.', $detailNachAenderung->body);
        $this->assertStringNotContainsString(
            $bezeichnung,
            $detailNachAenderung->body,
            'Die Änderung muss den alten Wert ersetzen, nicht eine zweite Zeile anlegen.'
        );

        // 7b. Eine Änderung auf eine Art außerhalb der Whitelist wird still
        //     verworfen - der Bestand bleibt, wie store() ihn angelegt hätte.
        $admin->post(self::ALTE_SEITE . '/update', [
            'csrf_token' => $csrf,
            'id' => $eintragId,
            'art' => 'pokal',
            'bezeichnung' => "Verworfen-{$unique}",
        ]);
        $detailNachVerwurf = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString("Verworfen-{$unique}", $detailNachVerwurf->body);
        $this->assertStringContainsString($geaendert, $detailNachVerwurf->body);

        // 8. Fail-closed: Ohne titel-praemierungen.manage erscheint der
        //    Abschnitt im Bearbeitungsformular gar nicht - kein Formular, das
        //    beim Absenden 403 liefert -, und die POST-Route weist ab.
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "tptester{$unique}",
            "titel-praemierungen-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $editorFormOhneRecht = $editor->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $editorFormOhneRecht->statusCode);
        $this->assertStringNotContainsString(
            self::ALTE_SEITE . '/store',
            $editorFormOhneRecht->body,
            'Ohne titel-praemierungen.manage darf der Abschnitt nicht erscheinen.'
        );
        $this->assertStringNotContainsString('Titel &amp; Prämierungen', $editorFormOhneRecht->body);

        $deniedResponse = $editor->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $editorFormOhneRecht->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'art' => 'titel',
            'bezeichnung' => "Unerlaubt-{$unique}",
        ]);
        $this->assertSame(403, $deniedResponse->statusCode);

        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'titel-praemierungen' => ['manage'],
        ]);

        $editorFormMitRecht = $editor->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $editorFormMitRecht->statusCode);
        $this->assertStringContainsString($geaendert, $editorFormMitRecht->body);
        $this->assertStringContainsString(self::ALTE_SEITE . '/store', $editorFormMitRecht->body);

        // 9. Löschen entfernt die Auszeichnung wieder von der Detailseite.
        $deleteResponse = $admin->post(self::ALTE_SEITE . '/delete', [
            'csrf_token' => $csrf,
            'id' => $eintragId,
        ]);
        $this->assertSame('/admin/horses/edit?id=' . $horseId, $deleteResponse->location());

        $detailAfterDelete = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('Titel &amp; Prämierungen', $detailAfterDelete->body);
    }
}
