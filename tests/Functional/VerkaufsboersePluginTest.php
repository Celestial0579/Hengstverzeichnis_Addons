<?php
// tests/Functional/VerkaufsboersePluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/verkaufsboerse gegen eine echte, per `php -S`
 * gestartete Hengstverzeichnis_Framework-Instanz (siehe tests/bootstrap.php
 * und Tests\Functional\FunctionalTestCase).
 *
 * Kein SMTP ist in der Testumgebung konfiguriert - wie bei
 * DeckanfragePluginTest liefert App\Service\Mailer::send() daher
 * kontrolliert `false`, die reale Anfrage erwartet folglich das Ergebnis
 * "fehler", nicht "erfolg" (siehe dortiger Kommentar für die Begründung).
 *
 * Seit #119 läuft die Anzeigenpflege ausschließlich über den Abschnitt im
 * Bearbeitungsformular des Pferdes (Kern-Hook `horse.edit_sections`); die
 * addoneigene Verwaltungsseite, ihre Dashboard-Kachel und ihre Pferdesuche
 * (Addons#125) sind entfallen. Der Test hält deshalb beides fest: dass der
 * Pferdeabschnitt den vollen Umfang trägt (anlegen, ändern, Sichtbarkeit
 * ausweisen, entfernen) - und dass die alten Adressen wirklich weg sind.
 *
 * Die ÖFFENTLICHE Börse (`/liste`) bleibt und wird unverändert mitgeprüft:
 * Sie ist der Zweck des Addons und kein Doppel der Pferdeseite.
 */
class VerkaufsboersePluginTest extends FunctionalTestCase {

    use HorseListHelper;
    use PferdesucheHelper;

    private const SLUG = 'verkaufsboerse';

    /** Die entfallene Verwaltungsseite (#119) - hier nur noch als Negativprobe. */
    private const ALTE_SEITE = '/plugin/verkaufsboerse/verwaltung';

    /** `login_attempts.type` des Zählers je Inserat (Plugin::INSERAT_LIMIT_TYPE). */
    private const INSERAT_TYP = 'verkaufsinserat';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Verkaufs-/Vermittlungsbörse', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        // 1. Weder Kachel noch eigene Seite (#119): Die Kachel führte auf eine
        //    Seite, die dasselbe Pferd über eine zweite Suche erneut
        //    heraussuchen ließ, obwohl die Pflege im Pferdedatensatz steht.
        $dashboard = $admin->get('/admin');
        $this->assertStringNotContainsString(
            self::ALTE_SEITE . '"',
            $dashboard->body,
            'Das Dashboard darf keine Kachel auf die entfallene Verwaltungsseite mehr enthalten (#119).'
        );

        $this->assertSame(
            404,
            $admin->get(self::ALTE_SEITE)->statusCode,
            'Die addoneigene Verwaltungsseite ist mit #119 entfallen.'
        );
        $this->assertEigeneSuchrouteEntfallen($admin, '/plugin/verkaufsboerse/suche');

        $unique = uniqid();
        $horseName = "VerkaufTestPferd-{$unique}";
        $horseId = $this->createHorse($admin, $horseName, ['status' => 'active']);
        $contactEmail = "verkauf-{$unique}@example.test";

        // 2. Vor dem Anlegen eines Inserats: kein Badge auf der Detailseite.
        $visitor = $this->newClient();
        $detailBefore = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('Zum Verkauf', $detailBefore->body);

        // 3. Der Abschnitt hängt im Bearbeitungsformular des Pferdes, mit der
        //    horse_id aus dem Aufrufkontext statt einer zweiten Pferdesuche.
        $form = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $form->statusCode);
        $this->assertStringContainsString('Verkaufsanzeige', $form->body);
        $this->assertStringContainsString(self::ALTE_SEITE . '/store', $form->body);
        $this->assertMatchesRegularExpression(
            '/name="horse_id" value="' . $horseId . '"/',
            $form->body,
            'Die horse_id muss aus dem Kontext stammen, nicht erneut gesucht werden.'
        );
        // Und ausdrücklich nicht: die Pferdeauswahl der alten Seite.
        $this->assertStringNotContainsString('list="horse_q_liste"', $form->body);
        $this->assertStringNotContainsString('<datalist', $form->body);

        // Vollständigkeit (#119): Jedes Feld, das store() auswertet, muss der
        // Abschnitt anbieten - solange eines fehlt, wäre die alte Seite nicht
        // überflüssig, sondern die Integration unfertig.
        foreach (['price', 'description', 'contact_email', 'listed_until'] as $feld) {
            $this->assertStringContainsString(
                'name="' . $feld . '"',
                $form->body,
                "Der Abschnitt muss das Feld '{$feld}' anbieten (#119)."
            );
        }

        // 4. Anlegen aus dem Pferdeabschnitt heraus - der Rückweg führt auf
        //    das Pferd zurück, nicht auf eine bestandsweite Liste.
        $csrf = $form->formField('csrf_token') ?? '';
        $storeResponse = $admin->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $csrf,
            'horse_id' => (string) $horseId,
            'price' => '1500.00',
            'description' => 'Verkauf wegen Bestandsreduzierung.',
            'contact_email' => $contactEmail,
        ]);
        $this->assertSame('/admin/horses/edit?id=' . $horseId, $storeResponse->location());

        // 5. Der Abschnitt zeigt das Inserat mit vorbelegten Feldern und weist
        //    aus, dass es öffentlich sichtbar ist (#51 zieht mit um).
        $formNachStore = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertStringContainsString($contactEmail, $formNachStore->body);
        $this->assertStringContainsString('Verkauf wegen Bestandsreduzierung.', $formNachStore->body);
        $this->assertStringContainsString('öffentlich sichtbar', $formNachStore->body);
        $this->assertStringContainsString(self::ALTE_SEITE . '/delete', $formNachStore->body);

        // 6. Öffentliche Übersicht und Detailseite zeigen das Inserat.
        $listePage = $visitor->get('/plugin/verkaufsboerse/liste');
        $this->assertSame(200, $listePage->statusCode);
        $this->assertStringContainsString($horseName, $listePage->body);
        $this->assertStringContainsString('1.500,00 €', $listePage->body);

        $detailAfter = $visitor->get("/horse?id={$horseId}");
        $this->assertStringContainsString('Zum Verkauf', $detailAfter->body);
        $this->assertStringContainsString('1.500,00 €', $detailAfter->body);
        $this->assertStringContainsString('Verkauf wegen Bestandsreduzierung.', $detailAfter->body);
        // Honeypot mit dem Feldnamen des Kerns (1.3.0) und Längengrenzen.
        $this->assertStringContainsString('name="website"', $detailAfter->body);
        $this->assertStringContainsString('id="verkaufsboerse-website"', $detailAfter->body);
        $this->assertStringNotContainsString('name="webseite"', $detailAfter->body);
        $this->assertStringContainsString('name="requester_name" required maxlength="150"', $detailAfter->body);
        $this->assertStringContainsString('name="requester_email" required maxlength="150"', $detailAfter->body);
        $this->assertStringContainsString('name="message" required rows="3" maxlength="5000"', $detailAfter->body);

        // 6b. Upsert (horse_id ist UNIQUE): Erneutes Speichern aktualisiert das
        //     Inserat, es entsteht kein zweites. Damit trägt derselbe Abschnitt
        //     anlegen UND ändern - die alte Seite konnte nichts anderes.
        $updateResponse = $admin->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $csrf,
            'horse_id' => (string) $horseId,
            'price' => '1800.00',
            'description' => 'Preis angepasst.',
            'contact_email' => $contactEmail,
        ]);
        $this->assertSame('/admin/horses/edit?id=' . $horseId, $updateResponse->location());
        $this->assertSame(
            1,
            $this->countListings($horseId),
            'Erneutes Speichern muss das Inserat aktualisieren, nicht ein zweites anlegen.'
        );
        $listeNachUpdate = $visitor->get('/plugin/verkaufsboerse/liste');
        $this->assertStringContainsString('1.800,00 €', $listeNachUpdate->body);
        $this->assertStringNotContainsString('1.500,00 €', $listeNachUpdate->body);

        // 6c. Paginierung der öffentlichen Börse (#74): Unterhalb der
        //     Seitengröße erscheint keine Blätter-Leiste, und ein zu großer
        //     ?seite=-Wert wird auf die letzte vorhandene Seite geklemmt.
        $this->assertStringNotContainsString('Seite 1 von 1', $listeNachUpdate->body, 'Bei nur einer Seite darf keine Blätter-Leiste erscheinen.');
        $clampedListe = $visitor->get('/plugin/verkaufsboerse/liste?seite=999');
        $this->assertSame(200, $clampedListe->statusCode);
        $this->assertStringContainsString($horseName, $clampedListe->body, 'Ein zu großer ?seite=-Wert soll auf die letzte Seite geklemmt werden.');

        // 7. Regression zu Issue #24: Ein Inserat für ein UNVERÖFFENTLICHTES
        //    Pferd darf in der öffentlichen Übersicht nicht erscheinen - und
        //    der Abschnitt sagt, warum (#51).
        $unpublishedName = "UnveroeffentlichtVerkauf-{$unique}";
        $unpublishedId = $this->createHorse($admin, $unpublishedName, [
            'status' => 'active',
            'is_published' => '0',
        ]);
        $unpublishedForm = $admin->get('/admin/horses/edit?id=' . $unpublishedId);
        $unpublishedStore = $admin->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $unpublishedForm->formField('csrf_token') ?? '',
            'horse_id' => (string) $unpublishedId,
            'price' => '18500.00',
            'contact_email' => $contactEmail,
        ]);
        $this->assertSame('/admin/horses/edit?id=' . $unpublishedId, $unpublishedStore->location());

        $listeWithUnpublished = $visitor->get('/plugin/verkaufsboerse/liste');
        $this->assertStringNotContainsString(
            $unpublishedName,
            $listeWithUnpublished->body,
            'Unveröffentlichte Pferde dürfen nicht in der öffentlichen Verkaufsbörse erscheinen.'
        );
        $this->assertStringContainsString(
            'Pferd unveröffentlicht - Inserat öffentlich unsichtbar',
            $admin->get('/admin/horses/edit?id=' . $unpublishedId)->body,
            'Der Abschnitt muss ausweisen, warum das Inserat öffentlich fehlt (#51).'
        );

        // 7b. Ein abgelaufenes Inserat ist ebenfalls unsichtbar - und der
        //     Abschnitt zeigt es weiterhin, damit es verlängert werden kann.
        $abgelaufenName = "AbgelaufenVerkauf-{$unique}";
        $abgelaufenId = $this->createHorse($admin, $abgelaufenName, ['status' => 'active']);
        $abgelaufenForm = $admin->get('/admin/horses/edit?id=' . $abgelaufenId);
        $admin->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $abgelaufenForm->formField('csrf_token') ?? '',
            'horse_id' => (string) $abgelaufenId,
            'price' => '999.00',
            'contact_email' => $contactEmail,
            'listed_until' => '2000-01-01',
        ]);
        $this->assertStringNotContainsString(
            $abgelaufenName,
            $visitor->get('/plugin/verkaufsboerse/liste')->body,
            'Ein abgelaufenes Inserat gehört nicht in die öffentliche Börse.'
        );
        $this->assertStringContainsString(
            'abgelaufen - öffentlich unsichtbar',
            $admin->get('/admin/horses/edit?id=' . $abgelaufenId)->body,
            'Ein abgelaufenes Inserat muss im Abschnitt sichtbar und damit verlängerbar bleiben.'
        );

        // 8. Honeypot ausgefüllt: wird stillschweigend als "erfolg" behandelt -
        //    über das Kern-Feld und eine Version lang auch über den alten
        //    Namen "webseite" (Formulare aus Tabs von vor dem Update). Keiner
        //    der beiden Fälle bucht einen Zähler.
        self::zaehlerLeeren();
        $csrfToken = $detailAfter->formField('csrf_token') ?? '';
        foreach (['website', 'webseite'] as $feld) {
            $honeypotResponse = $visitor->post('/plugin/verkaufsboerse/kontakt', [
                'csrf_token' => $csrfToken,
                'horse_id' => (string) $horseId,
                'requester_name' => 'Bot',
                'requester_email' => 'bot@example.test',
                'message' => 'Spam',
                $feld => 'https://spam.example',
            ]);
            $this->assertSame("/horse?id={$horseId}&verkaufsanfrage=erfolg", $honeypotResponse->location(), "Honeypot '{$feld}'");
        }
        $this->assertSame(0, $this->zaehlerStand('verkaufsboerse'), 'Ein Honeypot-Treffer zählt nicht als Versuch');
        $this->assertSame(0, $this->zaehlerStand(self::INSERAT_TYP));

        // 9. Echte Anfrage: Versand schlägt mangels SMTP-Konfiguration
        //    kontrolliert fehl. Der Zähler je Inserat bucht genau einmal.
        $realResponse = $visitor->post('/plugin/verkaufsboerse/kontakt', [
            'csrf_token' => $csrfToken,
            'horse_id' => (string) $horseId,
            'requester_name' => 'Kaufinteressent',
            'requester_email' => 'kaufinteressent@example.test',
            'message' => 'Ist der Preis verhandelbar?',
        ]);
        $this->assertSame("/horse?id={$horseId}&verkaufsanfrage=fehler", $realResponse->location());
        $this->assertSame(1, $this->zaehlerStand(self::INSERAT_TYP, "inserat:{$horseId}"));
        self::zaehlerLeeren();

        // 10. CSRF-Schutz.
        $csrfRejected = $visitor->post('/plugin/verkaufsboerse/kontakt', [
            'csrf_token' => 'invalid-token',
            'horse_id' => (string) $horseId,
            'requester_name' => 'Test',
            'requester_email' => 'test@example.test',
            'message' => 'Test',
        ]);
        $this->assertSame(403, $csrfRejected->statusCode);

        // 11. Fail-closed: Ohne verkaufsboerse.manage erscheint der Abschnitt
        //     im Bearbeitungsformular gar nicht - kein Formular, das beim
        //     Absenden 403 liefert -, und die POST-Route weist ab.
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "vbtester{$unique}",
            "verkaufsboerse-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $editorFormOhneRecht = $editor->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $editorFormOhneRecht->statusCode);
        $this->assertStringNotContainsString(
            self::ALTE_SEITE . '/store',
            $editorFormOhneRecht->body,
            'Ohne verkaufsboerse.manage darf der Abschnitt nicht erscheinen.'
        );
        $this->assertStringNotContainsString('Verkaufsanzeige', $editorFormOhneRecht->body);

        $deniedResponse = $editor->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $editorFormOhneRecht->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'contact_email' => "unerlaubt-{$unique}@example.test",
        ]);
        $this->assertSame(403, $deniedResponse->statusCode);

        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'verkaufsboerse' => ['manage'],
        ]);

        $editorFormMitRecht = $editor->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $editorFormMitRecht->statusCode);
        $this->assertStringContainsString(self::ALTE_SEITE . '/store', $editorFormMitRecht->body);

        // 12. Papierkorb-Hooks (#51 / Framework #164): Das Plugin reagiert auf
        //     horse.trashed/horse.restored mit einem Audit-Log-Eintrag - die
        //     Diskrepanz "Inserat gespeichert, Börse zeigt es nicht" ist damit
        //     dokumentiert, nicht nur sichtbar.
        $trashedName = "PapierkorbVerkauf-{$unique}";
        $trashedId = $this->createHorse($admin, $trashedName, ['status' => 'active']);
        $trashedForm = $admin->get('/admin/horses/edit?id=' . $trashedId);
        $trashedStore = $admin->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $trashedForm->formField('csrf_token') ?? '',
            'horse_id' => (string) $trashedId,
            'price' => '15000.00',
            'contact_email' => $contactEmail,
        ]);
        $this->assertSame('/admin/horses/edit?id=' . $trashedId, $trashedStore->location());

        $adminHorses = $admin->get('/admin/horses');
        $trashResponse = $admin->post('/admin/horses/delete', [
            'csrf_token' => $adminHorses->formField('csrf_token') ?? '',
            'id' => (string) $trashedId,
        ]);
        $this->assertSame('/admin/horses?success=deleted', $trashResponse->location());

        $listeAfterTrash = $visitor->get('/plugin/verkaufsboerse/liste');
        $this->assertStringNotContainsString($trashedName, $listeAfterTrash->body, 'Papierkorb-Pferd darf nicht in der öffentlichen Börse erscheinen.');

        // Der Hook feuert auch für ein Pferd im Papierkorb (der rohe Datensatz
        // ist nicht deleted_at-gefiltert) - der Abschnitt weist den Zustand aus.
        $this->assertStringContainsString(
            'Pferd im Papierkorb - Inserat öffentlich unsichtbar',
            $admin->get('/admin/horses/edit?id=' . $trashedId)->body
        );

        $auditStmt = \App\Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'Verkaufsbörse: Inserat durch Papierkorb öffentlich unsichtbar' AND details LIKE ?"
        );
        $auditStmt->execute(['%' . $trashedName . '%']);
        $this->assertGreaterThan(0, (int) $auditStmt->fetchColumn(), 'horse.trashed muss einen Audit-Log-Eintrag des Börsen-Plugins auslösen.');

        $trashPage = $admin->get('/admin/trash');
        $restoreResponse = $admin->post('/admin/trash/restore', [
            'csrf_token' => $trashPage->formField('csrf_token') ?? '',
            'type' => 'horse',
            'id' => (string) $trashedId,
        ]);
        $this->assertSame('/admin/trash?success=restored', $restoreResponse->location());
        $auditStmt = \App\Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'Verkaufsbörse: Inserat nach Wiederherstellung wieder sichtbar' AND details LIKE ?"
        );
        $auditStmt->execute(['%' . $trashedName . '%']);
        $this->assertGreaterThan(0, (int) $auditStmt->fetchColumn(), 'horse.restored muss einen Audit-Log-Eintrag des Börsen-Plugins auslösen.');

        $listeAfterRestore = $visitor->get('/plugin/verkaufsboerse/liste');
        $this->assertStringContainsString($trashedName, $listeAfterRestore->body, 'Nach der Wiederherstellung ist das Inserat wieder öffentlich.');

        // 13. Entfernen aus dem Abschnitt heraus: Rückweg auf das Pferd, und
        //     das Badge verschwindet von der Detailseite.
        $formVorLoeschen = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertMatchesRegularExpression(
            '/action="' . preg_quote(self::ALTE_SEITE . '/delete', '/') . '".*?name="id" value="(\d+)"/s',
            $formVorLoeschen->body,
            'Der Abschnitt muss ein Entfernen-Formular mit der Inserats-ID tragen.'
        );
        preg_match(
            '/action="' . preg_quote(self::ALTE_SEITE . '/delete', '/') . '".*?name="id" value="(\d+)"/s',
            $formVorLoeschen->body,
            $idMatch
        );

        $deleteResponse = $admin->post(self::ALTE_SEITE . '/delete', [
            'csrf_token' => $formVorLoeschen->formField('csrf_token') ?? '',
            'id' => $idMatch[1],
        ]);
        $this->assertSame('/admin/horses/edit?id=' . $horseId, $deleteResponse->location());

        $detailAfterDelete = $visitor->get("/horse?id={$horseId}");
        $this->assertStringNotContainsString('Zum Verkauf', $detailAfterDelete->body);
        $this->assertSame(0, $this->countListings($horseId));
    }

    /**
     * Audit N3: Zähler je Inserat, Längengrenzen und das CSRF-Feld.
     *
     * Der IP-Zähler allein liess sich über wechselnde Anschlüsse umgehen, und
     * der Inserent bekam beliebig viele Mails. Jetzt gehen je Inserat
     * höchstens zehn Anfragen in 24 Stunden hinaus. Die ersten zehn Buchungen
     * stehen direkt in login_attempts; der IP-Zähler wird geleert - das ist
     * der Absender mit wechselnden Anschlüssen.
     */
    public function testInseratsLimitUndLaengengrenzen(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $inserentMail = "limit-inserent-{$unique}@example.test";
        $horseId = $this->inseratAnlegen($admin, "LimitVerkauf-{$unique}", $inserentMail);
        $visitor = $this->newClient();
        $gueltig = [
            'horse_id' => (string) $horseId,
            'requester_name' => 'Kaufinteressent',
            'requester_email' => "interessent-{$unique}@example.test",
            'message' => 'Ist der Preis verhandelbar?',
        ];

        self::zaehlerLeeren();
        try {
            // Limit erreicht: "fehler", kein Versand, keine weitere Buchung.
            $this->zaehlerFuellen("inserat:{$horseId}", 10);
            $antwort = $visitor->post('/plugin/verkaufsboerse/kontakt', [
                'csrf_token' => $this->token($visitor, $horseId),
            ] + $gueltig);
            $this->assertSame("/horse?id={$horseId}&verkaufsanfrage=fehler", $antwort->location());
            $this->assertSame(10, $this->zaehlerStand(self::INSERAT_TYP, "inserat:{$horseId}"), 'Eine abgewiesene Anfrage bucht nicht');
            $this->assertSame(0, $this->versandversuche($inserentMail), 'Über dem Limit darf keine Mail an den Inserenten gehen');

            // Längengrenzen und UTF-8: "fehler" ohne Buchung und ohne 500.
            self::zaehlerLeeren();
            $faelle = [
                'Nachricht mit 5001 Zeichen' => ['message' => str_repeat('m', 5001)],
                'E-Mail mit 151 Zeichen' => ['requester_email' => str_repeat('x', 40) . '@' . str_repeat('a', 50) . '.' . str_repeat('b', 46) . '.example.test'],
                'Name mit 151 Zeichen' => ['requester_name' => str_repeat('n', 151)],
                'ungültiges UTF-8 im Namen' => ['requester_name' => "Kaputt\xC3\x28"],
                'Zeilenumbruch in der E-Mail' => ['requester_email' => "interessent-{$unique}@example.test\r\nBcc: opfer@example.test"],
                'Name als Array' => ['requester_name' => ['a', 'b']],
            ];
            foreach ($faelle as $fall => $abweichung) {
                $antwort = $visitor->post('/plugin/verkaufsboerse/kontakt', [
                    'csrf_token' => $this->token($visitor, $horseId),
                ] + $abweichung + $gueltig);
                $this->assertSame(302, $antwort->statusCode, "{$fall}: erwartet eine Weiterleitung");
                $this->assertSame("/horse?id={$horseId}&verkaufsanfrage=fehler", $antwort->location(), $fall);
                $this->assertSame(0, $this->zaehlerStand(self::INSERAT_TYP), "{$fall}: keine Buchung");
                $this->leereTyp('verkaufsboerse');
            }
            $this->assertSame(151, strlen($faelle['E-Mail mit 151 Zeichen']['requester_email']));
            $this->assertSame(0, $this->versandversuche($inserentMail));

            // CSRF-Feld als Array: 403 statt TypeError und HTTP 500.
            $array = $visitor->post('/plugin/verkaufsboerse/kontakt', ['csrf_token' => ['x']] + $gueltig);
            $this->assertSame(403, $array->statusCode);
        } finally {
            self::zaehlerLeeren();
        }
    }

    /**
     * Audit N4: Ohne horses.view der Gast-Gruppe zeigen Börse und Pferdeseite
     * 404 - dann darf ein Direkt-POST auch nichts versenden. Und der Status
     * verrät nicht mehr, zu welchem Pferd ein Inserat läuft: kein Inserat,
     * abgelaufenes Inserat und unveröffentlichtes Pferd melden "erfolg" wie
     * der Honeypot, ohne Versand und ohne Zählerbuchung.
     */
    public function testOhneHorsesViewKeinVersandUndKeinOrakel(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $inserentMail = "orakel-inserent-{$unique}@example.test";
        $horseId = $this->inseratAnlegen($admin, "OrakelVerkauf-{$unique}", $inserentMail);
        $db = \App\Database::getInstance();
        $gast = (int) $db->query("SELECT id FROM `groups` WHERE slug = 'public'")->fetchColumn();
        $this->assertGreaterThan(0, $gast, 'Gast-Gruppe nicht gefunden');
        $felder = [
            'requester_name' => 'Kaufinteressent',
            'requester_email' => "orakel-{$unique}@example.test",
            'message' => 'Ist das Pferd noch zu haben?',
        ];

        self::zaehlerLeeren();
        try {
            $db->prepare("DELETE FROM `group_permissions` WHERE group_id = ? AND module = 'horses' AND action = 'view'")
                ->execute([$gast]);

            $visitor = $this->newClient();
            $this->assertSame(404, $visitor->get('/plugin/verkaufsboerse/liste')->statusCode);

            $antwort = $visitor->post('/plugin/verkaufsboerse/kontakt', [
                'csrf_token' => $visitor->get('/dsgvo')->formField('csrf_token') ?? '',
                'horse_id' => (string) $horseId,
            ] + $felder);
            $this->assertSame("/horse?id={$horseId}&verkaufsanfrage=erfolg", $antwort->location());
            $this->assertSame(0, $this->zaehlerStand(self::INSERAT_TYP), 'Ohne horses.view keine Buchung');
            $this->assertSame(0, $this->versandversuche($inserentMail), 'Ohne horses.view kein Versand');
        } finally {
            $db->prepare("INSERT IGNORE INTO `group_permissions` (group_id, module, action) VALUES (?, 'horses', 'view')")
                ->execute([$gast]);
            self::zaehlerLeeren();
        }

        // Mit Recht: Pferde ohne sichtbares Inserat melden "erfolg".
        $ohneInserat = $this->createHorse($admin, "OhneInserat-{$unique}", ['status' => 'active']);
        $abgelaufen = $this->inseratAnlegen($admin, "AbgelaufenOrakel-{$unique}", $inserentMail, ['listed_until' => '2000-01-01']);
        $verborgen = $this->inseratAnlegen($admin, "VerborgenOrakel-{$unique}", $inserentMail, [], ['is_published' => '0']);

        $visitor = $this->newClient();
        try {
            foreach (['kein Inserat' => $ohneInserat, 'abgelaufen' => $abgelaufen, 'unveröffentlicht' => $verborgen] as $fall => $id) {
                $antwort = $visitor->post('/plugin/verkaufsboerse/kontakt', [
                    'csrf_token' => $this->token($visitor, $horseId),
                    'horse_id' => (string) $id,
                ] + $felder);
                $this->assertSame("/horse?id={$id}&verkaufsanfrage=erfolg", $antwort->location(), $fall);
                $this->leereTyp('verkaufsboerse');
            }
            $this->assertSame(0, $this->zaehlerStand(self::INSERAT_TYP), 'Ein verworfenes Ziel bucht keinen Inseratszähler');
            $this->assertSame(0, $this->versandversuche($inserentMail));
        } finally {
            self::zaehlerLeeren();
        }
    }

    /**
     * Framework#338: uninstall() räumt die Zähler des Formulars aus der
     * Kern-Tabelle `login_attempts` ab - beide Typen, und nur diese. Die
     * Inserate bleiben: Die plugin.json deklariert kein `owns`.
     *
     * Läuft im PHPUnit-Prozess statt über /admin/plugins, damit das Addon für
     * die übrigen Tests aktiv bleibt.
     */
    public function testUninstallRaeumtZaehlerAuf(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $horseId = $this->inseratAnlegen($admin, "UninstallVerkauf-{$unique}", "uninstall-{$unique}@example.test");

        $db = \App\Database::getInstance();
        $fremd = "fremd-{$unique}";
        $stmt = $db->prepare('INSERT INTO login_attempts (identifier, type) VALUES (?, ?)');
        $stmt->execute(['127.0.0.1', 'verkaufsboerse']);
        $stmt->execute(["inserat:{$horseId}", self::INSERAT_TYP]);
        $stmt->execute([$fremd, 'deckanfrage']);

        try {
            require_once __DIR__ . '/../../plugins/verkaufsboerse/Plugin.php';
            $this->assertSame(self::INSERAT_TYP, \Plugin\Verkaufsboerse\Plugin::INSERAT_LIMIT_TYPE);
            $this->assertLessThanOrEqual(20, strlen(\Plugin\Verkaufsboerse\Plugin::INSERAT_LIMIT_TYPE), 'login_attempts.type ist VARCHAR(20)');

            (new \Plugin\Verkaufsboerse\Plugin())->uninstall();

            $this->assertSame(0, $this->zaehlerStand('verkaufsboerse'));
            $this->assertSame(0, $this->zaehlerStand(self::INSERAT_TYP));
            $this->assertSame(1, $this->zaehlerStand('deckanfrage', $fremd), 'Fremde Zähler bleiben stehen');
            $this->assertSame(1, $this->countListings($horseId), 'Die Inserate bleiben');
        } finally {
            $db->prepare('DELETE FROM login_attempts WHERE identifier = ? AND type = ?')->execute([$fremd, 'deckanfrage']);
        }
    }

    /**
     * Legt ein Pferd samt Inserat über den Abschnitt im Bearbeitungsformular
     * an und aktiviert vorher das Addon.
     *
     * @param array<string, string> $inserat Zusatzfelder für store()
     * @param array<string, string> $pferd Zusatzfelder für createHorse()
     */
    private function inseratAnlegen(\Tests\Support\HttpClient $admin, string $name, string $email, array $inserat = [], array $pferd = []): int {
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $horseId = $this->createHorse($admin, $name, ['status' => 'active'] + $pferd);
        $form = $admin->get('/admin/horses/edit?id=' . $horseId);
        $store = $admin->post(self::ALTE_SEITE . '/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'price' => '1000.00',
            'contact_email' => $email,
        ] + $inserat);
        $this->assertSame('/admin/horses/edit?id=' . $horseId, $store->location());
        $this->assertSame(1, $this->countListings($horseId));

        return $horseId;
    }

    /** CSRF-Token von der Pferdeseite mit dem Kontaktformular. */
    private function token(\Tests\Support\HttpClient $client, int $horseId): string {
        $seite = $client->get("/horse?id={$horseId}");
        $this->assertStringContainsString('/plugin/verkaufsboerse/kontakt', $seite->body, 'Kein Kontaktformular auf der Pferdeseite');
        return $seite->formField('csrf_token') ?? '';
    }

    /** Leert beide Zähler des Formulars (IP und je Inserat). */
    private static function zaehlerLeeren(): void {
        \App\Database::getInstance()->exec(
            "DELETE FROM login_attempts WHERE type IN ('verkaufsboerse', '" . self::INSERAT_TYP . "')"
        );
    }

    private function leereTyp(string $typ): void {
        \App\Database::getInstance()->prepare('DELETE FROM login_attempts WHERE type = ?')->execute([$typ]);
    }

    private function zaehlerStand(string $typ, ?string $bezeichner = null): int {
        $db = \App\Database::getInstance();
        if ($bezeichner === null) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE type = ?');
            $stmt->execute([$typ]);
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE type = ? AND identifier = ?');
            $stmt->execute([$typ, $bezeichner]);
        }
        return (int) $stmt->fetchColumn();
    }

    /** Schreibt $anzahl Buchungen des Inseratszählers direkt, statt sie abzuwarten. */
    private function zaehlerFuellen(string $bezeichner, int $anzahl): void {
        $stmt = \App\Database::getInstance()->prepare('INSERT INTO login_attempts (identifier, type) VALUES (?, ?)');
        for ($i = 0; $i < $anzahl; $i++) {
            $stmt->execute([$bezeichner, self::INSERAT_TYP]);
        }
    }

    /**
     * Versandversuche an eine Adresse: App\Service\Mailer protokolliert jeden
     * Versuch unter der Kategorie `email` mit dem Empfänger in den Details -
     * ohne SMTP ist das der Fehlschlag-Eintrag (siehe DeckanfragePluginTest).
     */
    private function versandversuche(string $adresse): int {
        $stmt = \App\Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM audit_logs WHERE category = 'email' AND details LIKE ?"
        );
        $stmt->execute(['%' . $adresse . '%']);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Zählt die Inserate eines Pferdes direkt in der Datenbank - der
     * unabhängige Nachweis für den Upsert (#119): Eine gerenderte Seite zeigt
     * nur EIN Inserat, weil `horse_id` UNIQUE ist; ob daneben ein zweites
     * entstand, sagt allein die Tabelle.
     */
    private function countListings(int $horseId): int {
        $stmt = \App\Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM `plugin_verkaufsboerse_listings` WHERE horse_id = :id'
        );
        $stmt->execute(['id' => $horseId]);
        return (int) $stmt->fetchColumn();
    }
}
