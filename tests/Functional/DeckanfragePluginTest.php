<?php
// tests/Functional/DeckanfragePluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/deckanfrage gegen eine echte, per `php -S`
 * gestartete Hengstverzeichnis_Framework-Instanz (siehe tests/bootstrap.php
 * und Tests\Functional\FunctionalTestCase).
 *
 * Kein SMTP ist in der Testumgebung konfiguriert - App\Service\Mailer::send()
 * liefert dadurch kontrolliert `false` (dasselbe etablierte Verhalten, das
 * auch der Kern in tests/Integration/DigestServiceTest.php voraussetzt).
 * Die tatsächliche Ergebnis-Erwartung ist daher "fehler" (Versand
 * fehlgeschlagen), nicht "erfolg" - der Test deckt damit den kompletten Weg
 * bis zum (fehlschlagenden) Versandversuch inkl. Protokollierung ab.
 */
class DeckanfragePluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'deckanfrage';

    public function testFullPluginLifecycle(): void {
        self::rateLimitZuruecksetzen();
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Deckanfrage-Formular', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        $unique = uniqid();

        // 1. Deckstation MIT E-Mail-Adresse anlegen - VERÖFFENTLICHT und mit
        // freigegebenen Kontaktdaten.
        // Neu angelegte Kontakte sind per Default unveröffentlicht
        // (contacts.is_published DEFAULT 0), und die öffentliche
        // Pferde-Detailseite joint die Station nur mit is_published = 1
        // (Kern-#122). Seit Framework#336 kommt contact_public dazu: Ohne
        // Freigabe liefert der Kern `station_email` als NULL. Fehlt eines von
        // beiden, wäre $horse['station_email'] im Hook horse.detail_sections
        // null, das Formular erschiene nie, und der Test schlüge fehl, ohne
        // dass am Plugin etwas kaputt wäre (Kern-#151).
        $stationName = "Deckstation-{$unique}";
        $stationEmail = "station-{$unique}@example.test";
        $stationId = $this->createStationContact($admin, $stationName, ['email' => $stationEmail]);

        // 2. Pferd anlegen und über eine Personen-Rolle mit der Deckstation
        // verknüpfen (horses.breeding_station_id wird von
        // HorseController::saveHorsePersons() aus der Personen-Zuordnung
        // synchronisiert, siehe Kommentar in StatistikDashboardPluginTest -
        // ein direktes "breeding_station_id"-Feld gibt es auf Pferde-Ebene
        // nicht in der Admin-Oberfläche).
        $horseWithStation = $this->createHorse($admin, "MitDeckstation-{$unique}", [
            'status' => 'active',
            'persons' => [['role' => 'owner', 'station_contact_id' => (string) $stationId]],
        ]);
        $horseWithoutStation = $this->createHorse($admin, "OhneDeckstation-{$unique}", ['status' => 'active']);

        $visitor = $this->newClient();

        // 3. Formular erscheint nur beim Pferd MIT verknüpfter Deckstation.
        $detailWith = $visitor->get("/horse?id={$horseWithStation}");
        $this->assertStringContainsString('Deckanfrage stellen', $detailWith->body);
        $this->assertStringContainsString(
            'name="' . \App\Security\Captcha::HONEYPOT_FIELD . '"',
            $detailWith->body,
            'Honeypot-Feld sollte im Formular enthalten sein - seit #351 unter dem Namen des Kerns.'
        );

        $detailWithout = $visitor->get("/horse?id={$horseWithoutStation}");
        $this->assertStringNotContainsString('Deckanfrage stellen', $detailWithout->body);

        // 3b. Geschlechts-Gate (#53): Eine STUTE mit Station samt E-Mail bekommt
        // KEIN Deckanfrage-Formular - eine Deckanfrage richtet sich an einen
        // Hengst. Pferde ohne Geschlechtsangabe (wie oben) bleiben zugelassen.
        $mareWithStation = $this->createHorse($admin, "StuteMitStation-{$unique}", [
            'status' => 'active',
            'sex' => 'mare',
            'persons' => [['role' => 'owner', 'station_contact_id' => (string) $stationId]],
        ]);
        $detailMare = $visitor->get("/horse?id={$mareWithStation}");
        $this->assertSame(200, $detailMare->statusCode);
        $this->assertStringNotContainsString(
            'Deckanfrage stellen',
            $detailMare->body,
            'Für eine Stute darf trotz Station-E-Mail kein Deckanfrage-Formular erscheinen.'
        );

        // 4. Honeypot ausgefüllt: wird stillschweigend als "erfolg" behandelt.
        // Ohne geloeste Sicherheitsfrage - der Honeypot wird VOR ihr geprueft,
        // ein Bot soll gar nicht erst so weit kommen.
        $csrfToken = $detailWith->formField('csrf_token') ?? '';
        $honeypotResponse = $visitor->post('/plugin/deckanfrage/anfrage', [
            'csrf_token' => $csrfToken,
            'horse_id' => (string) $horseWithStation,
            'requester_name' => 'Bot',
            'requester_email' => 'bot@example.test',
            'message' => 'Spam',
            \App\Security\Captcha::HONEYPOT_FIELD => 'https://spam.example',
        ]);
        $this->assertSame("/horse?id={$horseWithStation}&deckanfrage=erfolg", $honeypotResponse->location());

        // 5. Echte Anfrage: CSRF-, Sicherheitsfrage-, Validierungs- und
        // Versandpfad durchlaufen - Versand schlägt mangels
        // SMTP-Konfiguration kontrolliert fehl.
        $realResponse = $visitor->post('/plugin/deckanfrage/anfrage', $this->formularVorbereiten($visitor, $horseWithStation) + [
            'horse_id' => (string) $horseWithStation,
            'requester_name' => 'Maria Musterfrau',
            'requester_email' => 'interessent@example.test',
            'message' => 'Ist der Hengst noch für die Decksaison verfügbar?',
        ]);
        $this->assertSame("/horse?id={$horseWithStation}&deckanfrage=fehler", $realResponse->location());

        $detailAfter = $visitor->get("/horse?id={$horseWithStation}&deckanfrage=fehler");
        $this->assertStringContainsString('konnte nicht versendet werden', $detailAfter->body);

        // 6. Regression zu Issue #26: Ein UNVERÖFFENTLICHTES Pferd (auch mit
        // Deckstation) darf über den Redirect-Status kein Existenz-Orakel
        // liefern - die Anfrage wird stillschweigend verworfen und wie beim
        // Honeypot als "erfolg" beantwortet, es geht keine E-Mail raus.
        $unpublishedHorse = $this->createHorse($admin, "Unveroeffentlicht-{$unique}", [
            'status' => 'active',
            'is_published' => '0',
            'persons' => [['role' => 'owner', 'station_contact_id' => (string) $stationId]],
        ]);
        $unpublishedResponse = $visitor->post('/plugin/deckanfrage/anfrage', $this->formularVorbereiten($visitor, $horseWithStation) + [
            'horse_id' => (string) $unpublishedHorse,
            'requester_name' => 'Neugieriger Dritter',
            'requester_email' => 'dritter@example.test',
            'message' => 'Gibt es dieses Pferd?',
        ]);
        $this->assertSame(
            "/horse?id={$unpublishedHorse}&deckanfrage=erfolg",
            $unpublishedResponse->location(),
            'Unveröffentlichte Pferde müssen denselben Status wie der Honeypot-Pfad liefern (kein Existenz-Orakel).'
        );

        // 6b. Härtung analog für die STATION: Ein veröffentlichtes Pferd,
        // dessen Deckstation UNveröffentlicht ist, zeigt das Formular nicht
        // (Kern-#122/#151) - dann darf auch ein direkter POST mit gültigem
        // CSRF-Token nichts an die Station versenden. Der Handler wendet
        // denselben bs.is_published-Filter an wie die Anzeige und antwortet
        // wie beim Honeypot-Pfad mit "erfolg" (stillschweigend verworfen,
        // kein Existenz-Orakel, keine E-Mail). Vor der Härtung antwortete er
        // hier mit "fehler", weil er den Versand tatsächlich versuchte.
        $hiddenStationName = "VerborgeneStation-{$unique}";
        $hiddenStationId = $this->createStationContact($admin, $hiddenStationName, [
            'email' => "verborgen-{$unique}@example.test",
            // is_published ausdrücklich leer: bleibt auf dem Spalten-Default 0.
            'is_published' => '',
        ]);

        $horseWithHiddenStation = $this->createHorse($admin, "MitVerborgenerStation-{$unique}", [
            'status' => 'active',
            'persons' => [['role' => 'owner', 'station_contact_id' => (string) $hiddenStationId]],
        ]);
        $hiddenStationPost = $visitor->post('/plugin/deckanfrage/anfrage', $this->formularVorbereiten($visitor, $horseWithStation) + [
            'horse_id' => (string) $horseWithHiddenStation,
            'requester_name' => 'Direkter Poster',
            'requester_email' => 'direkt@example.test',
            'message' => 'Anfrage an eine unveröffentlichte Station.',
        ]);
        $this->assertSame(
            "/horse?id={$horseWithHiddenStation}&deckanfrage=erfolg",
            $hiddenStationPost->location(),
            'Eine unveröffentlichte Station darf per Direkt-POST nicht erreichbar sein - '
            . 'stillschweigend verwerfen wie beim Honeypot, kein Versand, kein Orakel.'
        );

        // 7. CSRF-Schutz: fehlendes/ungültiges Token wird abgewiesen.
        $csrfRejected = $visitor->post('/plugin/deckanfrage/anfrage', [
            'csrf_token' => 'invalid-token',
            'horse_id' => (string) $horseWithStation,
            'requester_name' => 'Test',
            'requester_email' => 'test@example.test',
            'message' => 'Test',
        ]);
        $this->assertSame(403, $csrfRejected->statusCode);
    }

    /**
     * #134: Eine eingegangene Deckanfrage muss im Audit-Log stehen.
     *
     * Vorher schrieb dieses Addon eine Zeile in die Datenbank und verschickte
     * eine E-Mail, ohne dass davon irgendwo eine Spur blieb - wer sich das
     * Protokoll ansah, durfte annehmen, es sei nichts geschehen. Genau das
     * macht ein lückenhaftes Protokoll schlimmer als gar keins.
     *
     * Der Test prüft beide Hälften der Anforderung, weil die eine ohne die
     * andere nichts taugt:
     *
     * - Der Eintrag ENTSTEHT und ist aussagekräftig (Anfrage-Nummer und
     *   betroffenes Pferd stehen darin).
     * - Er enthält NICHT Name, E-Mail-Adresse und Nachricht des Absenders.
     *   Das Protokoll wird dauerhaft aufbewahrt; was hier landete, überlebte
     *   jede spätere Löschung der Anfrage selbst.
     *
     * Gegenprobe gelaufen: Ohne den AuditLogger::log()-Aufruf in
     * AnfrageController::submit() findet die Abfrage keinen Eintrag, und der
     * Test schlägt an der assertCount(1)-Zeile fehl.
     */
    public function testEingegangeneAnfrageStehtImProtokoll(): void {
        self::rateLimitZuruecksetzen();
        $admin = $this->authenticatedClient();
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $unique = uniqid();

        $stationName = "ProtokollStation-{$unique}";
        $stationId = $this->createStationContact($admin, $stationName, [
            'email' => "protokoll-station-{$unique}@example.test",
        ]);

        $horseName = "ProtokollHengst-{$unique}";
        $horseId = $this->createHorse($admin, $horseName, [
            'status' => 'active',
            'persons' => [['role' => 'owner', 'station_contact_id' => (string) $stationId]],
        ]);

        $visitor = $this->newClient();

        // Bewusst wiedererkennbare Absenderdaten: Nur so lässt sich unten
        // belegen, dass sie NICHT im Protokoll gelandet sind.
        $absenderName = "Absenderin Protokoll {$unique}";
        $absenderMail = "absender-{$unique}@example.test";
        $nachricht = "Vertraulicher Anfragetext {$unique}";

        $visitor->post('/plugin/deckanfrage/anfrage', $this->formularVorbereiten($visitor, $horseId) + [
            'horse_id' => (string) $horseId,
            'requester_name' => $absenderName,
            'requester_email' => $absenderMail,
            'message' => $nachricht,
        ]);

        $eintraege = $this->protokollEintraege('Deckanfrage eingegangen', "Pferd #{$horseId} ");
        $this->assertCount(
            1,
            $eintraege,
            'Eine eingegangene Deckanfrage muss genau einen Protokolleintrag der Kategorie "deckanfrage" erzeugen.'
        );

        $details = (string) $eintraege[0]['details'];
        $this->assertMatchesRegularExpression(
            '/Anfrage #\d+/',
            $details,
            'Ohne Angabe, WELCHE Anfrage gemeint ist, hilft der Eintrag niemandem.'
        );
        $this->assertStringContainsString(
            $horseName,
            $details,
            'Der Eintrag muss das betroffene Pferd benennen, nicht nur dessen ID.'
        );
        // Die Route ist für anonyme Besucher: Der Eintrag darf keinem
        // angemeldeten Konto zugeschrieben werden.
        //
        // Bis v0.7 stand hier ausdrücklich 'GAST' - das Addon rief
        // AuditLogger::log() mit eigenem Benutzernamen auf. Seit #352 läuft
        // die Protokollierung über PluginAudit::log(), das die Kategorie aus
        // dem geprüften Slug ableitet, dafür aber keinen Benutzernamen
        // entgegennimmt; ohne Sitzung schreibt der Kern 'SYSTEM'.
        //
        // Der Test hält deshalb die Eigenschaft fest, auf die es ankommt, und
        // nicht die Zeichenkette: kein user_id, kein angemeldetes Konto. Dass
        // eine anonyme Besucher-Handlung dabei mit Cron- und CLI-Läufen unter
        // demselben 'SYSTEM' zusammenfällt, ist ein Verlust an Aussagekraft -
        // er gehört in den Kern (PluginAudit ohne Weg, einen Gast-Kontext
        // auszuweisen), nicht in dieses Addon.
        $this->assertNull(
            $eintraege[0]['user_id'],
            'Ein Eintrag zu einer anonymen Anfrage darf keine Benutzer-ID tragen.'
        );
        $this->assertNotSame(
            'e2eadmin',
            (string) $eintraege[0]['username'],
            'Der Eintrag darf nicht dem gerade angemeldeten Admin-Konto zugeschrieben werden.'
        );

        $this->assertStringNotContainsString($absenderName, $details, 'Der Absendername gehört nicht ins dauerhafte Protokoll.');
        $this->assertStringNotContainsString($absenderMail, $details, 'Die Absenderadresse gehört nicht ins dauerhafte Protokoll.');
        $this->assertStringNotContainsString($nachricht, $details, 'Der Anfragetext gehört nicht ins dauerhafte Protokoll.');
    }

    /**
     * Protokolleinträge dieses Addons zu einer Aktion und einem Bezug.
     *
     * Die Kategorie ist fest der Addon-Slug (#134): Die Auswahlliste der
     * Protokollansicht entsteht im Kern aus SELECT DISTINCT category, neue
     * Kategorien erscheinen dort also von selbst.
     *
     * @return array<int, array<string, mixed>>
     */
    private function protokollEintraege(string $aktion, string $detailFragment): array {
        $stmt = \App\Database::getInstance()->prepare(
            'SELECT user_id, username, details FROM audit_logs
             WHERE category = ? AND action = ? AND details LIKE ?
               AND created_at >= (NOW() - INTERVAL 10 MINUTE)'
        );
        $stmt->execute([self::SLUG, $aktion, '%' . $detailFragment . '%']);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Legt einen Kontakt in der Rolle Deckstation ueber die echte
     * Admin-Route an und liefert seine ID.
     *
     * Seit Framework#336 (#138) gibt es /admin/breeding-stations nicht mehr -
     * die Route leitet dauerhaft auf /admin/contacts um, und Personen wie
     * Stationen werden ueber dasselbe Formular gepflegt.
     *
     * `contact_public` wird ausdruecklich mitgegeben und ist NICHT
     * vorbelegt: Die Stationen hatten die Freigabe bis v0.7 per
     * Spalten-Default, seit der Zusammenlegung muss sie angehakt werden
     * (sonst stuende die naechste Privatperson mit Telefonnummer im Netz).
     * Ohne Freigabe liefert der Kern kein `station_email`, und das
     * Deckanfrage-Formular erscheint nicht - genau das prueft
     * testOhneFreigabeKeinFormularUndKeinVersand() gezielt.
     *
     * @param array<string, string> $extra Zusaetzliche POST-Felder.
     */
    private function createStationContact(\Tests\Support\HttpClient $admin, string $name, array $extra = []): int {
        $form = $admin->get('/admin/contacts/create');
        $response = $admin->post('/admin/contacts/store', array_merge([
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'is_published' => '1',
            'contact_public' => '1',
        ], $extra));
        $this->assertSame(
            '/admin/contacts?success=created',
            $response->location(),
            "Anlegen des Kontakts '{$name}' fehlgeschlagen, Body: {$response->body}"
        );

        return $this->findBreedingStationIdByName($admin, $name);
    }

    /**
     * #138: Empfänger und Pferd gegeneinander festgehalten.
     *
     * Die Umstellung auf die Kontaktliste hat genau eine Abfrage getroffen -
     * die, aus der die Empfängeradresse stammt. Trifft sie den falschen
     * Kontakt, geht eine Deckanfrage samt Namen, Adresse und Anliegen des
     * Anfragenden an jemanden, den sie nichts angeht. Ein Tabellenname allein
     * fällt beim Testen nicht auf: `contacts` enthält Personen UND Stationen,
     * eine Abfrage über den falschen Steckplatz liefert also weiterhin einen
     * plausiblen Datensatz mit plausibler E-Mail-Adresse.
     *
     * Der Test baut deshalb drei Kontakte mit unterscheidbaren Adressen und
     * belegt, dass genau der richtige angeschrieben wird:
     *
     * - `alpha`  - eine ANDERE Deckstation, an einem anderen Pferd.
     * - `beta`   - die Deckstation DIESES Pferdes (horse_persons.station_contact_id
     *              und darüber horses.breeding_station_id).
     * - `gamma`  - der ZÜCHTER desselben Pferdes (horse_persons.contact_id).
     *              Seit #336 steht er in derselben Tabelle wie die Station;
     *              wer den falschen Steckplatz abfragt, landet bei ihm.
     *
     * Nachgewiesen wird über das Protokoll des Mailers: Er schreibt bei jedem
     * Fehlschlag eine Zeile der Kategorie `email` mit der Empfängeradresse
     * (App\Service\Mailer::sendViaSmtp()). In der Testumgebung ist kein SMTP
     * konfiguriert, der Versand scheitert also kontrolliert - genau diese
     * Zeile ist der Beleg, an wen er gegangen WÄRE.
     *
     * Gegenprobe gelaufen: Löst man die Station über den falschen Steckplatz
     * auf (`horse_persons.contact_id` statt `station_contact_id`), geht die
     * Anfrage an den Züchter, und der Test schlägt fehl.
     */
    public function testEmpfaengerIstDieStationDesAngefragtenPferdes(): void {
        self::rateLimitZuruecksetzen();
        $admin = $this->authenticatedClient();
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $unique = uniqid();
        $alphaMail = "alpha-station-{$unique}@example.test";
        $betaMail = "beta-station-{$unique}@example.test";
        $gammaMail = "gamma-zuechter-{$unique}@example.test";

        $alphaId = $this->createStationContact($admin, "AlphaStation-{$unique}", ['email' => $alphaMail]);
        $betaId = $this->createStationContact($admin, "BetaStation-{$unique}", ['email' => $betaMail]);
        $gammaId = $this->createStationContact($admin, "GammaZuechter-{$unique}", ['email' => $gammaMail]);

        // Ein Pferd an Alpha - es soll nicht angefragt werden und dient nur
        // dazu, dass Alpha überhaupt als Station im Bestand vorkommt.
        $this->createHorse($admin, "AlphaHengst-{$unique}", [
            'status' => 'active',
            'persons' => [['role' => 'owner', 'station_contact_id' => (string) $alphaId]],
        ]);

        // Das angefragte Pferd: Station Beta, Züchter Gamma - beide Steckplätze
        // der Zuordnungszeile belegt, mit VERSCHIEDENEN Kontakten.
        $betaHorse = $this->createHorse($admin, "BetaHengst-{$unique}", [
            'status' => 'active',
            'persons' => [[
                'contact_id' => (string) $gammaId,
                'role' => 'breeder',
                'station_contact_id' => (string) $betaId,
            ]],
        ]);

        $visitor = $this->newClient();
        $visitor->post('/plugin/deckanfrage/anfrage', $this->formularVorbereiten($visitor, $betaHorse) + [
            'horse_id' => (string) $betaHorse,
            'requester_name' => 'Interessentin Beta',
            'requester_email' => "interessentin-{$unique}@example.test",
            'message' => 'Steht der Hengst zur Verfügung?',
        ]);

        $empfaenger = $this->mailEmpfaengerSeitKurzem();

        $this->assertContains(
            $betaMail,
            $empfaenger,
            'Die Anfrage muss an die Deckstation DIESES Pferdes gehen.'
        );
        $this->assertNotContains(
            $gammaMail,
            $empfaenger,
            'Der Züchter des Pferdes steht seit #336 in derselben Tabelle wie die Station - '
            . 'er darf trotzdem keine Deckanfrage bekommen (falscher Steckplatz: contact_id statt station_contact_id).'
        );
        $this->assertNotContains(
            $alphaMail,
            $empfaenger,
            'Die Station eines ANDEREN Pferdes darf keine Anfrage bekommen.'
        );
    }

    /**
     * #138: Ohne Freigabe der Kontaktdaten (`contacts.contact_public = 0`)
     * gibt es weder Formular noch Versand.
     *
     * Das ist die eigentliche Neuerung der Zusammenlegung. Bis v0.7 war eine
     * Deckstation eine Geschäftsadresse in einer eigenen Tabelle ohne
     * personenbezogene Felder - ihre E-Mail-Adresse war schlicht öffentlich.
     * Seit #336 steht dieselbe Spalte auch bei Privatpersonen, und der Kern
     * liefert `station_email` nur noch bei gesetzter Freigabe.
     *
     * Geprüft werden beide Hälften, weil die eine ohne die andere nichts
     * taugt: Das Formular erscheint nicht - UND ein direkter POST mit
     * gültigem Token und gelöster Sicherheitsfrage versendet nichts. Ohne die
     * zweite Hälfte wäre die Adresse weiterhin erreichbar, nur eben nicht
     * mehr über einen Klick.
     *
     * Gegenprobe gelaufen: Nimmt man das `CASE WHEN bs.contact_public = 1`
     * aus der Abfrage in AnfrageController::submit() heraus - der Zustand vor
     * #138 -, antwortet der Direkt-POST mit "fehler" statt "erfolg", weil der
     * Versand tatsächlich versucht wird. Der Test schlägt dort fehl.
     */
    public function testOhneKontaktfreigabeKeinFormularUndKeinVersand(): void {
        self::rateLimitZuruecksetzen();
        $admin = $this->authenticatedClient();
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $unique = uniqid();

        // Freigegebene Station - liefert Token und Sicherheitsfrage für den
        // Direkt-POST unten.
        $offenId = $this->createStationContact($admin, "OffeneStation-{$unique}", [
            'email' => "offen-{$unique}@example.test",
        ]);
        $offenHorse = $this->createHorse($admin, "OffenerHengst-{$unique}", [
            'status' => 'active',
            'persons' => [['role' => 'owner', 'station_contact_id' => (string) $offenId]],
        ]);

        // Veröffentlicht, nicht gelöscht, E-Mail-Adresse gepflegt - und
        // trotzdem gesperrt, weil die Freigabe fehlt.
        $gesperrtMail = "gesperrt-{$unique}@example.test";
        $gesperrtId = $this->createStationContact($admin, "GesperrteStation-{$unique}", [
            'email' => $gesperrtMail,
            'contact_public' => '',
        ]);
        $gesperrtHorse = $this->createHorse($admin, "GesperrterHengst-{$unique}", [
            'status' => 'active',
            'persons' => [['role' => 'owner', 'station_contact_id' => (string) $gesperrtId]],
        ]);

        $visitor = $this->newClient();
        $seite = $visitor->get("/horse?id={$gesperrtHorse}");
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringNotContainsString(
            'Deckanfrage stellen',
            $seite->body,
            'Ohne contact_public darf kein Deckanfrage-Formular erscheinen.'
        );
        $this->assertStringNotContainsString(
            $gesperrtMail,
            $seite->body,
            'Die nicht freigegebene Adresse darf auf der öffentlichen Seite nirgends stehen.'
        );

        $antwort = $visitor->post('/plugin/deckanfrage/anfrage', $this->formularVorbereiten($visitor, $offenHorse) + [
            'horse_id' => (string) $gesperrtHorse,
            'requester_name' => 'Direkter Poster',
            'requester_email' => "direkt-{$unique}@example.test",
            'message' => 'Anfrage an eine nicht freigegebene Adresse.',
        ]);
        $this->assertSame(
            "/horse?id={$gesperrtHorse}&deckanfrage=erfolg",
            $antwort->location(),
            'Stillschweigend verwerfen wie beim Honeypot-Pfad - kein Existenz-Orakel.'
        );

        $this->assertNotContains(
            $gesperrtMail,
            $this->mailEmpfaengerSeitKurzem(),
            'An eine nicht freigegebene Adresse darf auch per Direkt-POST nichts hinausgehen.'
        );
        $this->assertSame(
            0,
            $this->anfragenZuPferd($gesperrtHorse),
            'Eine verworfene Anfrage darf auch nicht gespeichert werden.'
        );
    }

    /**
     * #351: Ohne gelöste Sicherheitsfrage passiert nichts - und der Besucher
     * erfährt, dass es an ihr lag.
     *
     * Die Prüfung liegt bewusst VOR der Abfrage des Pferdes: Sie darf nichts
     * darüber verraten, ob das Pferd existiert oder eine erreichbare Station
     * hat. Sonst wäre ausgerechnet der Spam-Schutz das Existenz-Orakel, das
     * der Rest dieses Controllers sorgfältig vermeidet.
     *
     * Gegenprobe gelaufen: Ohne den Captcha::verify()-Aufruf in
     * AnfrageController::submit() antwortet der Handler mit "fehler" (der
     * Versand wird versucht), und der Test schlägt an der ersten Zusicherung
     * fehl.
     */
    public function testOhneGeloesteSicherheitsfrageWirdNichtsVersendet(): void {
        self::rateLimitZuruecksetzen();
        $admin = $this->authenticatedClient();
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $unique = uniqid();
        $stationMail = "captcha-station-{$unique}@example.test";
        $stationId = $this->createStationContact($admin, "CaptchaStation-{$unique}", ['email' => $stationMail]);
        $horseId = $this->createHorse($admin, "CaptchaHengst-{$unique}", [
            'status' => 'active',
            'persons' => [['role' => 'owner', 'station_contact_id' => (string) $stationId]],
        ]);

        $visitor = $this->newClient();
        $seite = $visitor->get("/horse?id={$horseId}");
        $this->assertStringContainsString(
            'name="captcha"',
            $seite->body,
            'Das öffentliche Formular muss seit #351 eine Sicherheitsfrage tragen.'
        );

        // Gültiger CSRF-Token, Mindest-Ausfüllzeit abgewartet - nur die
        // Antwort ist falsch. 99 liegt ausserhalb jedes möglichen Ergebnisses
        // (höchstens neun plus neun) und ist trotzdem ein formal gültiger
        // Wert, kann also nicht schon an der Formatprüfung scheitern.
        $antwort = $visitor->post('/plugin/deckanfrage/anfrage', array_merge(
            $this->formularVorbereiten($visitor, $horseId),
            [
                'captcha' => '99',
                'horse_id' => (string) $horseId,
                'requester_name' => 'Bot ohne Rechenkenntnisse',
                'requester_email' => "bot-{$unique}@example.test",
                'message' => 'Spam',
            ]
        ));

        $this->assertSame(
            "/horse?id={$horseId}&deckanfrage=captcha",
            $antwort->location(),
            'Eine falsch beantwortete Sicherheitsfrage bekommt einen eigenen Status - '
            . '"fehler" hiesse "später erneut versuchen", und genau das hilft hier nicht.'
        );

        $this->assertSame(
            0,
            $this->anfragenZuPferd($horseId),
            'Ohne gelöste Sicherheitsfrage darf nichts gespeichert werden.'
        );
        $this->assertNotContains(
            $stationMail,
            $this->mailEmpfaengerSeitKurzem(),
            'Ohne gelöste Sicherheitsfrage darf nichts hinausgehen.'
        );

        $hinweisSeite = $visitor->get("/horse?id={$horseId}&deckanfrage=captcha");
        $this->assertStringContainsString(
            'Sicherheitsfrage wurde nicht korrekt beantwortet',
            $hinweisSeite->body,
            'Der Besucher muss erfahren, dass es an der Sicherheitsfrage lag - sonst versucht er es unverändert erneut.'
        );
    }

    /**
     * Die Empfängeradressen aller Versandversuche der letzten zehn Minuten.
     *
     * App\Service\Mailer protokolliert jeden Versuch unter der Kategorie
     * `email` mit "Empfänger: <adresse>" in den Details - in der
     * Testumgebung ohne SMTP-Konfiguration ist das der Fehlschlag-Eintrag.
     * Damit lässt sich prüfen, an wen eine Anfrage gegangen wäre, ohne einen
     * echten Mailserver zu betreiben.
     *
     * @return array<int, string>
     */
    private function mailEmpfaengerSeitKurzem(): array {
        $zeilen = \App\Database::getInstance()
            ->query("SELECT details FROM audit_logs WHERE category = 'email' AND created_at >= (NOW() - INTERVAL 10 MINUTE)")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $adressen = [];
        foreach ((array) $zeilen as $details) {
            if (preg_match('/Empf\S*nger:\s*([^,\s]+)/u', (string) $details, $treffer)) {
                $adressen[] = $treffer[1];
            }
        }

        return $adressen;
    }

    /** Anzahl gespeicherter Anfragen zu einem Pferd. */
    private function anfragenZuPferd(int $horseId): int {
        $stmt = \App\Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM `plugin_deckanfrage_requests` WHERE horse_id = ?'
        );
        $stmt->execute([$horseId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Zahlwoerter der deutschen Sprachdatei (`captcha.number_*`).
     *
     * Die Aufgabe wird ausgeschrieben gestellt, damit sie sich nicht per
     * Zahlen-Regex loesen laesst - hier wird sie deshalb wie von einem
     * Menschen ueber die Wortbedeutung geloest. Der Kern hat denselben Helfer
     * fuer sein DSGVO-Formular (tests/Functional/DsgvoFormHelper.php); er
     * liegt dort im Framework-Repo und wird vom Bootstrap dieses Repos nicht
     * geladen, deshalb steht die Rechenhilfe hier noch einmal.
     *
     * Umgangen wird der Schutz ausdruecklich NICHT: Ein Test, der ihn
     * abschaltet, laesst ihn genau dort ungeprueft, wo er zaehlt.
     *
     * @var array<string, int>
     */
    private const ZAHLWOERTER = [
        'eins' => 1, 'zwei' => 2, 'drei' => 3, 'vier' => 4, "f\u{00fc}nf" => 5,
        'sechs' => 6, 'sieben' => 7, 'acht' => 8, 'neun' => 9,
    ];

    /**
     * Holt die Pferdeseite, liest CSRF-Token und Sicherheitsfrage aus dem
     * gerenderten Deckanfrage-Formular und wartet die Mindest-Ausfuellzeit ab
     * (Captcha::MIN_SOLVE_SECONDS - ein sofort abgeschicktes Formular gilt als
     * Bot).
     *
     * Die Aufgabe ist EINMAL verwendbar und wird bei jedem Rendern neu
     * gestellt: Fuer jeden POST, der bis zur Pruefung durchkommen soll, muss
     * die Seite deshalb frisch geholt werden.
     *
     * @return array{csrf_token:string, captcha:string}
     */
    private function formularVorbereiten(\Tests\Support\HttpClient $besucher, int $horseId): array {
        $seite = $besucher->get("/horse?id={$horseId}");
        $this->assertStringContainsString(
            'Deckanfrage stellen',
            $seite->body,
            "Auf der Seite von Pferd #{$horseId} erscheint kein Deckanfrage-Formular - "
            . 'ohne Formular gibt es weder CSRF-Token noch Sicherheitsfrage.'
        );

        $token = $seite->formField('csrf_token') ?? '';
        $this->assertNotSame('', $token);

        preg_match('/<label for="captcha">.*?<strong>([^<]+)<\/strong>/su', $seite->body, $treffer);
        $this->assertNotEmpty(
            $treffer,
            "Konnte die Sicherheitsfrage nicht aus dem Formular lesen, Body: {$seite->body}"
        );

        $teile = preg_split('/\s+/u', trim($treffer[1]));
        $this->assertCount(3, $teile, "Unerwarteter Aufgabentext: {$treffer[1]}");
        $links = self::ZAHLWOERTER[$teile[0]] ?? null;
        $rechts = self::ZAHLWOERTER[$teile[2]] ?? null;
        $this->assertNotNull($links, "Unbekanntes Zahlwort: {$teile[0]}");
        $this->assertNotNull($rechts, "Unbekanntes Zahlwort: {$teile[2]}");
        $this->assertContains($teile[1], ['plus', 'minus'], "Unbekannter Operator: {$teile[1]}");

        sleep(\App\Security\Captcha::MIN_SOLVE_SECONDS);

        return [
            'csrf_token' => $token,
            'captcha' => (string) ($teile[1] === 'minus' ? $links - $rechts : $links + $rechts),
        ];
    }

    /**
     * Setzt den IP-Zaehler dieses Formulars zurueck.
     *
     * Alle Requests der Functional-Suite kommen von 127.0.0.1 und teilen sich
     * damit denselben Zaehler (max. 5 Anfragen je Stunde). Ohne diesen Reset
     * haenge das Ergebnis an der Reihenfolge der Testmethoden und an
     * vorherigen Laeufen gegen dieselbe Test-Datenbank - dasselbe Vorgehen wie
     * DsgvoFormHelper::resetDsgvoRateLimit() im Kern.
     */
    private static function rateLimitZuruecksetzen(): void {
        \App\Database::getInstance()->exec("DELETE FROM login_attempts WHERE type = 'deckanfrage'");
    }

    private function findBreedingStationIdByName(\Tests\Support\HttpClient $admin, string $name): int {
        // Ueber den Namensparameter, damit der Treffer auch dann auf Seite 1
        // steht, wenn die Liste blaettert (Kern 0.7.0, 50 Zeilen je Seite).
        $page = $admin->get('/admin/contacts?search=' . urlencode($name));
        preg_match_all('/<tr[^>]*>((?:(?!<\/tr>).)*?)<\/tr>/s', $page->body, $rowMatches);
        foreach ($rowMatches[1] as $rowHtml) {
            if (!str_contains($rowHtml, '<strong>' . $name . '</strong>')) {
                continue;
            }
            preg_match('/<td[^>]*>(\d+)<\/td>/', $rowHtml, $idMatch);
            $this->assertNotEmpty($idMatch, "Zeile für Deckstation '{$name}' enthält keine numerische ID-Zelle.");
            return (int) $idMatch[1];
        }
        $this->fail("Konnte ID der Deckstation '{$name}' nicht aus /admin/contacts ermitteln. Body: {$page->body}");
    }
}
