<?php
// tests/Functional/ZuchtschauErgebnissePluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/zuchtschau-ergebnisse gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 *
 * Seit #124 läuft die Ergebnispflege ausschließlich über den Abschnitt im
 * Bearbeitungsformular des Pferdes (Kern-Hook `horse.edit_sections`); die
 * addoneigene Ergebnisseite ist entfallen. Das ist der aufwendigste der fünf
 * Integrationsfälle, weil hier ZWEI Ebenen aneinander hängen: ein Ergebnis
 * und seine Teilwertungen. Der Test prüft deshalb ausdrücklich den Ablauf
 * über beide Ebenen hinweg - Ergebnis anlegen, Teilwertung am gespeicherten
 * Ergebnis anlegen, einzeln löschen, Ergebnis samt CASCADE löschen - und dass
 * die Rückwege den Benutzer nicht die Stelle verlieren lassen (Anker
 * `#zs-ergebnis-<id>`).
 */
class ZuchtschauErgebnissePluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'zuchtschau-ergebnisse';

    /** Die entfallene Ergebnisseite (#124) - hier nur noch als Negativprobe. */
    private const ALTE_SEITE = '/plugin/zuchtschau-ergebnisse/ergebnisse';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Zuchtschau-/Körungs-Ergebnisverwaltung', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        // 1. Die eigene Seite ist weg (#124). Sie ließ dasselbe Pferd über eine
        //    zweite Auswahl erneut heraussuchen, obwohl die Pflege im
        //    Pferdedatensatz steht.
        $this->assertSame(
            404,
            $admin->get(self::ALTE_SEITE)->statusCode,
            'Die addoneigene Ergebnisseite ist mit #124 entfallen.'
        );

        $unique = uniqid();
        $horseName = "ZSTestPferd-{$unique}";
        $horseId = $this->createHorse($admin, $horseName, ['status' => 'active']);

        // 2. Vor dem Erfassen: kein Ergebnis-Abschnitt auf der Detailseite.
        $visitor = $this->newClient();
        $detailBefore = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('Zuchtschau-/Körungsergebnisse', $detailBefore->body);

        // 3. Der Abschnitt hängt im Bearbeitungsformular des Pferdes, mit der
        //    horse_id aus dem Aufrufkontext statt einer zweiten Pferdeauswahl.
        $form = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $form->statusCode);
        $this->assertStringContainsString('Zuchtschau-/Körungsergebnisse', $form->body);
        $this->assertStringContainsString(self::ALTE_SEITE . '/store', $form->body);
        $this->assertMatchesRegularExpression(
            '/name="horse_id" value="' . $horseId . '"/',
            $form->body,
            'Die horse_id muss aus dem Kontext stammen, nicht erneut ausgewählt werden.'
        );
        // Und ausdrücklich nicht: der Voll-<select> über den gesamten Bestand.
        $this->assertStringNotContainsString('<select name="horse_id"', $form->body);

        // Vollständigkeit (#124): Jedes Feld, das store() auswertet, muss der
        // Abschnitt anbieten - solange eines fehlt, wäre die alte Seite nicht
        // überflüssig, sondern die Integration unfertig.
        foreach (['event_name', 'event_date', 'category', 'score', 'judge', 'placement', 'comment'] as $feld) {
            $this->assertStringContainsString(
                'name="' . $feld . '"',
                $form->body,
                "Der Abschnitt muss das Ergebnis-Feld '{$feld}' anbieten (#124)."
            );
        }

        // 3b. Ohne gespeichertes Ergebnis gibt es (noch) kein
        //     Teilwertungs-Formular - die zweite Ebene braucht ihre erste.
        $this->assertStringNotContainsString(
            self::ALTE_SEITE . '/teilwertung/store',
            $form->body,
            'Teilwertungen hängen an einem Ergebnis - ohne eines gibt es nichts zu erfassen.'
        );

        // 4. Ergebnis anlegen - der Rückweg führt auf das Pferd zurück.
        $csrf = $form->formField('csrf_token') ?? '';
        $eventName = "Bundeschampionat-{$unique}";
        $storeResponse = $admin->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $csrf,
            'horse_id' => (string) $horseId,
            'event_name' => $eventName,
            'event_date' => '2024-06-15',
            'category' => 'Körung',
            'score' => '8,5',
            'judge' => 'Dr. Testrichter',
            'placement' => '1. Platz',
            'comment' => 'Hervorragende Bewegungsqualität.',
        ]);
        $this->assertSame('/admin/horses/edit?id=' . $horseId, $storeResponse->location());

        // 5. Der Abschnitt zeigt das Ergebnis mit allen Angaben - und trägt
        //    jetzt das Teilwertungs-Formular der zweiten Ebene.
        $formNachStore = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertStringContainsString($eventName, $formNachStore->body);
        $this->assertStringContainsString('Dr. Testrichter', $formNachStore->body);
        $this->assertStringContainsString('Hervorragende Bewegungsqualität.', $formNachStore->body);
        $this->assertStringContainsString(self::ALTE_SEITE . '/teilwertung/store', $formNachStore->body);
        $this->assertStringContainsString('Teilwertungen (0)', $formNachStore->body);

        // Die ergebnis_id des Blocks - sie ist der Bezug der zweiten Ebene.
        preg_match(
            '/action="' . preg_quote(self::ALTE_SEITE . '/teilwertung/store', '/') . '".*?name="ergebnis_id" value="(\d+)"/s',
            $formNachStore->body,
            $ergebnisIdMatch
        );
        $this->assertNotEmpty($ergebnisIdMatch, 'Konnte die ergebnis_id nicht aus dem Teilwertungs-Formular ermitteln.');
        $ergebnisId = (int) $ergebnisIdMatch[1];

        // Der Anker, auf den die Rückwege der zweiten Ebene zeigen.
        $this->assertStringContainsString(
            'id="zs-ergebnis-' . $ergebnisId . '"',
            $formNachStore->body,
            'Ohne Anker im Dokument führt der Rückweg der Teilwertungen ins Leere (#124).'
        );

        // 6. Öffentliche Detailseite zeigt das Ergebnis automatisch an.
        $detailAfter = $visitor->get("/horse?id={$horseId}");
        $this->assertStringContainsString('Zuchtschau-/Körungsergebnisse', $detailAfter->body);
        $this->assertStringContainsString($eventName, $detailAfter->body);
        $this->assertStringContainsString('Dr. Testrichter', $detailAfter->body);
        $this->assertStringContainsString('Hervorragende Bewegungsqualität.', $detailAfter->body);

        // 7. Zweite Ebene (#82/#124): Teilwertung am gespeicherten Ergebnis
        //    anlegen. Distanz bewusst leer - die Fachfelder sind NULL-tolerant
        //    (lückige Altdaten aus v1/v2).
        $teilwertungName = "Dressur-{$unique}";
        $twStoreResponse = $admin->post(self::ALTE_SEITE . '/teilwertung/store', [
            'csrf_token' => $csrf,
            'ergebnis_id' => (string) $ergebnisId,
            'bezeichnung' => $teilwertungName,
            'wertung' => 'A',
            'note' => '7,8',
            'platzierung' => '2.',
            'distanz' => '',
            'zeit' => '4:32,1',
        ]);
        // Der Rückweg führt auf das Pferd UND an den Block des Ergebnisses:
        // Ohne den Anker landete der Benutzer am Anfang eines langen
        // Formulars und müsste sein Ergebnis wiederfinden (#124).
        $this->assertSame(
            '/admin/horses/edit?id=' . $horseId . '#zs-ergebnis-' . $ergebnisId,
            $twStoreResponse->location()
        );

        $formMitTw = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertStringContainsString($teilwertungName, $formMitTw->body);
        $this->assertStringContainsString('Teilwertungen (1)', $formMitTw->body);
        $this->assertSame(1, $this->countTeilwertungen($ergebnisId));

        $detailWithTw = $visitor->get("/horse?id={$horseId}");
        $this->assertStringContainsString($teilwertungName, $detailWithTw->body);
        $this->assertStringContainsString('7,8', $detailWithTw->body);
        $this->assertStringContainsString('4:32,1', $detailWithTw->body);

        // 7b. Eine Teilwertung ohne existierendes Elternergebnis legt nichts an
        //     und endet in der Pferdeliste statt in einem FK-Fehler (500).
        $twVerwaist = $admin->post(self::ALTE_SEITE . '/teilwertung/store', [
            'csrf_token' => $csrf,
            'ergebnis_id' => '999999',
            'bezeichnung' => "Verwaist-{$unique}",
        ]);
        $this->assertSame('/admin/horses', $twVerwaist->location());

        // 8. Einzelne Teilwertung löschen (eigene Route), ohne das Ergebnis
        //    anzutasten - der Rückweg führt wieder an den Block.
        preg_match(
            '/action="' . preg_quote(self::ALTE_SEITE . '/teilwertung/delete', '/') . '".*?name="id" value="(\d+)"/s',
            $formMitTw->body,
            $twIdMatch
        );
        $this->assertNotEmpty($twIdMatch, 'Konnte ID der erfassten Teilwertung nicht ermitteln.');

        $twDeleteResponse = $admin->post(self::ALTE_SEITE . '/teilwertung/delete', [
            'csrf_token' => $csrf,
            'id' => $twIdMatch[1],
        ]);
        $this->assertSame(
            '/admin/horses/edit?id=' . $horseId . '#zs-ergebnis-' . $ergebnisId,
            $twDeleteResponse->location()
        );

        $detailAfterTwDelete = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString($teilwertungName, $detailAfterTwDelete->body);
        $this->assertStringContainsString(
            $eventName,
            $detailAfterTwDelete->body,
            'Das Löschen einer Teilwertung darf das Ergebnis nicht mitnehmen.'
        );

        // Eine zweite Teilwertung anlegen - sie liefert unten den
        // CASCADE-Nachweis.
        $cascadeName = "Springen-{$unique}";
        $admin->post(self::ALTE_SEITE . '/teilwertung/store', [
            'csrf_token' => $csrf,
            'ergebnis_id' => (string) $ergebnisId,
            'bezeichnung' => $cascadeName,
        ]);
        $this->assertSame(1, $this->countTeilwertungen($ergebnisId));

        // 9. Fail-closed: Ohne zuchtschau-ergebnisse.manage erscheint der
        //    Abschnitt gar nicht - kein Formular, das beim Absenden 403
        //    liefert -, und beide Ebenen weisen den POST ab.
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "zstester{$unique}",
            "zuchtschau-ergebnisse-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $editorFormOhneRecht = $editor->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $editorFormOhneRecht->statusCode);
        $this->assertStringNotContainsString(
            self::ALTE_SEITE . '/store',
            $editorFormOhneRecht->body,
            'Ohne zuchtschau-ergebnisse.manage darf der Abschnitt nicht erscheinen.'
        );
        $this->assertStringNotContainsString('Zuchtschau-/Körungsergebnisse', $editorFormOhneRecht->body);

        $editorCsrf = $editorFormOhneRecht->formField('csrf_token') ?? '';
        $deniedErgebnis = $editor->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $editorCsrf,
            'horse_id' => (string) $horseId,
            'event_name' => "Unerlaubt-{$unique}",
        ]);
        $this->assertSame(403, $deniedErgebnis->statusCode);

        $deniedTeilwertung = $editor->post(self::ALTE_SEITE . '/teilwertung/store', [
            'csrf_token' => $editorCsrf,
            'ergebnis_id' => (string) $ergebnisId,
            'bezeichnung' => "Unerlaubt-{$unique}",
        ]);
        $this->assertSame(
            403,
            $deniedTeilwertung->statusCode,
            'Auch die zweite Ebene muss ohne die Berechtigung abweisen.'
        );

        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'zuchtschau-ergebnisse' => ['manage'],
        ]);

        $editorFormMitRecht = $editor->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $editorFormMitRecht->statusCode);
        $this->assertStringContainsString($eventName, $editorFormMitRecht->body);
        $this->assertStringContainsString(self::ALTE_SEITE . '/store', $editorFormMitRecht->body);

        // 10. Löschen entfernt das Ergebnis wieder von der Detailseite - und
        //     per FK ON DELETE CASCADE auch seine Teilwertungen. Der Nachweis
        //     läuft nicht nur über die (dann ohnehin leere) Detailseite,
        //     sondern direkt gegen die Datenbank: Ein grün gerenderter
        //     Abschnitt ist kein Beleg dafür, dass die Kindzeilen weg sind.
        $deleteResponse = $admin->post(self::ALTE_SEITE . '/delete', [
            'csrf_token' => $csrf,
            'id' => (string) $ergebnisId,
        ]);
        // Ohne Anker: Den Block, auf den er zeigte, gibt es nicht mehr.
        $this->assertSame('/admin/horses/edit?id=' . $horseId, $deleteResponse->location());

        $detailAfterDelete = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('Zuchtschau-/Körungsergebnisse', $detailAfterDelete->body);
        $this->assertStringNotContainsString($cascadeName, $detailAfterDelete->body);
        $this->assertSame(
            0,
            $this->countTeilwertungen($ergebnisId),
            'FK ON DELETE CASCADE hat die Teilwertungen des gelöschten Ergebnisses nicht entfernt.'
        );
    }

    /**
     * #134: Anlegen und Löschen eines Ergebnisses samt Teilwertungen müssen
     * im Audit-Log stehen.
     *
     * Beim Löschen zählt zusätzlich, was der Eintrag über den CASCADE sagt:
     * Die Teilwertungen räumt die Datenbank selbst ab, sie hinterlassen also
     * von sich aus keine Spur. Steht ihre Zahl nicht im Protokolleintrag des
     * Ergebnisses, ist sie nirgends.
     *
     * Der Richtername bleibt bewusst draußen - er ist ein Personenbezug, und
     * das Protokoll wird dauerhaft aufbewahrt.
     *
     * Gegenprobe gelaufen: Ohne die AuditLogger::log()-Aufrufe in
     * ErgebnisseController findet die Abfrage keine Einträge, und der Test
     * schlägt an den assertCount(1)-Zeilen fehl.
     */
    public function testAnlegenUndLoeschenStehenImProtokoll(): void {
        $admin = $this->authenticatedClient();
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $unique = uniqid();
        $horseName = "ZSProtokoll-{$unique}";
        $horseId = $this->createHorse($admin, $horseName, ['status' => 'active']);

        $form = $admin->get('/admin/horses/edit?id=' . $horseId);
        $csrf = $form->formField('csrf_token') ?? '';
        $eventName = "Protokollschau-{$unique}";
        $richter = "Dr. Richterin {$unique}";
        $admin->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $csrf,
            'horse_id' => (string) $horseId,
            'event_name' => $eventName,
            'event_date' => '2024-06-15',
            'judge' => $richter,
            'comment' => "Vertraulicher Kommentar {$unique}",
        ]);

        $angelegt = $this->protokollEintraege('Zuchtschau-Ergebnis angelegt', "Pferd #{$horseId} ");
        $this->assertCount(1, $angelegt, 'Ein angelegtes Ergebnis muss genau einen Protokolleintrag erzeugen.');
        $angelegtDetails = (string) $angelegt[0]['details'];
        $this->assertStringContainsString($horseName, $angelegtDetails, 'Der Eintrag muss das betroffene Pferd benennen.');
        $this->assertStringContainsString($eventName, $angelegtDetails, 'Ohne die Veranstaltung ist der Eintrag nicht wiedererkennbar.');
        $this->assertStringNotContainsString($richter, $angelegtDetails, 'Der Richtername ist ein Personenbezug und gehört nicht ins dauerhafte Protokoll.');

        preg_match('/Ergebnis #(\d+)/', $angelegtDetails, $treffer);
        $this->assertNotEmpty($treffer, 'Der Eintrag muss die Ergebnis-Nummer nennen.');
        $ergebnisId = (int) $treffer[1];

        // Teilwertung anlegen: eigener Schreibzugriff, eigener Eintrag - und
        // zugleich die Vorbereitung für den CASCADE-Nachweis unten.
        $admin->post(self::ALTE_SEITE . '/teilwertung/store', [
            'csrf_token' => $csrf,
            'ergebnis_id' => (string) $ergebnisId,
            'bezeichnung' => "Dressur-{$unique}",
        ]);

        $twAngelegt = $this->protokollEintraege('Zuchtschau-Teilwertung angelegt', "zu Ergebnis #{$ergebnisId},");
        $this->assertCount(1, $twAngelegt, 'Auch die Kindtabelle ist ein Schreibzugriff und gehört ins Protokoll.');
        $this->assertStringContainsString("Dressur-{$unique}", (string) $twAngelegt[0]['details']);
        $this->assertSame(1, $this->countTeilwertungen($ergebnisId));

        // Eine zweite Teilwertung wird EINZELN gelöscht: eigener Schreibpfad,
        // eigener Protokolleintrag. Die ID kommt aus der Datenbank, nicht aus
        // dem Protokoll - sonst prüfte das Protokoll sich selbst.
        $admin->post(self::ALTE_SEITE . '/teilwertung/store', [
            'csrf_token' => $csrf,
            'ergebnis_id' => (string) $ergebnisId,
            'bezeichnung' => "Springen-{$unique}",
        ]);
        $twStmt = \App\Database::getInstance()->prepare(
            'SELECT id FROM `plugin_zuchtschau_teilwertungen` WHERE ergebnis_id = ? AND bezeichnung = ?'
        );
        $twStmt->execute([$ergebnisId, "Springen-{$unique}"]);
        $twId = (int) $twStmt->fetchColumn();
        $this->assertGreaterThan(0, $twId, 'Die zweite Teilwertung wurde nicht angelegt.');

        $admin->post(self::ALTE_SEITE . '/teilwertung/delete', [
            'csrf_token' => $csrf,
            'id' => (string) $twId,
        ]);
        $this->assertSame(1, $this->countTeilwertungen($ergebnisId), 'Nur die zweite Teilwertung sollte gelöscht sein.');

        $twGeloescht = $this->protokollEintraege('Zuchtschau-Teilwertung gelöscht', "Teilwertung #{$twId} ");
        $this->assertCount(1, $twGeloescht, 'Auch das Löschen einer Teilwertung gehört ins Protokoll.');
        $this->assertStringContainsString("Springen-{$unique}", (string) $twGeloescht[0]['details']);

        // Löschen des Ergebnisses - die verbliebene Teilwertung geht per
        // CASCADE mit.
        $admin->post(self::ALTE_SEITE . '/delete', [
            'csrf_token' => $csrf,
            'id' => (string) $ergebnisId,
        ]);
        $this->assertSame(0, $this->countTeilwertungen($ergebnisId));

        $geloescht = $this->protokollEintraege('Zuchtschau-Ergebnis gelöscht', "Ergebnis #{$ergebnisId},");
        $this->assertCount(1, $geloescht, 'Das Löschen eines Ergebnisses muss protokolliert werden - genau das fehlte (#134).');

        $details = (string) $geloescht[0]['details'];
        $this->assertStringContainsString($horseName, $details, 'Ohne Angabe, zu welchem Pferd das Ergebnis gehörte, hilft der Eintrag niemandem.');
        $this->assertStringContainsString($eventName, $details, 'Der Eintrag muss die Veranstaltung benennen.');
        $this->assertStringContainsString(
            'mitgelöschte Teilwertungen: 1',
            $details,
            'Der CASCADE hinterlässt selbst keine Spur - was er mitgenommen hat, steht sonst nirgends.'
        );
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

    /**
     * Zählt Teilwertungen direkt in der Datenbank - der unabhängige
     * Nebeneffekt-Nachweis für Anlage und CASCADE (die DB_*-Konstanten für
     * diesen Prozess setzt tests/bootstrap.php aus der Umgebung).
     */
    private function countTeilwertungen(int $ergebnisId): int {
        $stmt = \App\Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM `plugin_zuchtschau_teilwertungen` WHERE ergebnis_id = :id'
        );
        $stmt->execute(['id' => $ergebnisId]);
        return (int) $stmt->fetchColumn();
    }
}
