<?php
// tests/Functional/KontaktanfragePluginTest.php

namespace Tests\Functional;

use Tests\Support\HttpClient;

/**
 * End-to-End-Test für plugins/kontaktanfrage gegen eine echte, per `php -S`
 * gestartete Hengstverzeichnis_Framework-Instanz (siehe tests/bootstrap.php
 * und Tests\Functional\FunctionalTestCase).
 *
 * Der Mailversand läuft in dieser Umgebung bewusst ins Leere: Ohne
 * konfiguriertes SMTP liefert Mailer::send() kontrolliert false. Genau das
 * ist der interessante Fall - die Anfrage muss trotzdem gespeichert und im
 * Backend als unzugestellt sichtbar sein (kein Datenverlust), und der
 * Besucher bekommt "fehler" statt eines falschen Erfolgsversprechens.
 */
class KontaktanfragePluginTest extends FunctionalTestCase {

    use PersonStationHelper;

    private const SLUG = 'kontaktanfrage';
    private const VERWALTUNG = '/plugin/kontaktanfrage/verwaltung';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Kontaktanfrage', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        // 1. Dashboard-Kachel (admin.dashboard_tiles).
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString(self::VERWALTUNG, $dashboard->body);

        $unique = uniqid();
        $personName = "KAPerson-{$unique}";
        $personEmail = "person-{$unique}@example.test";
        $teamAdresse = "team-{$unique}@example.test";

        $personId = $this->createPerson($admin, $personName, [
            'email' => $personEmail,
            'city' => "Kontaktdorf-{$unique}",
        ]);

        $visitor = $this->newClient();

        // 2. Ohne hinterlegte Team-Adresse gibt es kein Formular. Eine Anfrage
        //    ohne Empfänger wäre ein Formular ins Nichts.
        $ohneTeam = $visitor->get("/person?id={$personId}");
        $this->assertSame(200, $ohneTeam->statusCode);
        $this->assertStringNotContainsString('Kontakt aufnehmen', $ohneTeam->body);

        // 3. Team-Adresse und ein zusätzlicher Grund im Backend setzen.
        $verwaltung = $admin->get(self::VERWALTUNG);
        $this->assertSame(200, $verwaltung->statusCode);
        $einstellungen = $admin->post('/plugin/kontaktanfrage/verwaltung/einstellungen', [
            'csrf_token' => $verwaltung->formField('csrf_token') ?? '',
            'team_email' => $teamAdresse,
            'zusatz_gruende' => "Besichtigungstermin\n",
            'aufbewahrung_tage' => '180',
        ]);
        $this->assertSame(self::VERWALTUNG . '?ka=gespeichert', $einstellungen->location());

        // 4. Jetzt erscheint das Formular auf der Personenseite - mit Honeypot
        //    und ohne die E-Mail-Adresse der Person preiszugeben.
        $mitFormular = $visitor->get("/person?id={$personId}");
        $this->assertStringContainsString('Kontakt aufnehmen', $mitFormular->body);
        $this->assertStringContainsString('name="webseite"', $mitFormular->body, 'Honeypot-Feld fehlt.');
        $this->assertStringNotContainsString(
            $personEmail,
            $mitFormular->body,
            'Das Kontaktformular darf die Adresse des Empfängers nicht offenlegen.'
        );

        // 5. Anfrage absenden. Ohne SMTP schlägt der Versand fehl - die
        //    Anfrage wird trotzdem gespeichert (siehe Klassenkommentar).
        $anfragenderName = "Interessent-{$unique}";
        $anfragenderEmail = "interessent-{$unique}@example.test";
        $gesendet = $this->sendeAnfrage($visitor, $personId, [
            'grund' => 'kaufinteresse',
            'name' => $anfragenderName,
            'email' => $anfragenderEmail,
        ]);
        $this->assertSame(
            "/person?id={$personId}&kontaktanfrage=fehler",
            $gesendet->location(),
            "Ohne Mailversand meldet das Addon ehrlich einen Fehler. Body: {$gesendet->body}"
        );

        // 6. Die Anfrage steht im Backend, mit Anfragendem und Grund.
        $nachAnfrage = $admin->get(self::VERWALTUNG);
        $this->assertStringContainsString($anfragenderName, $nachAnfrage->body);
        $this->assertStringContainsString($anfragenderEmail, $nachAnfrage->body);
        $this->assertStringContainsString($personName, $nachAnfrage->body);
        $this->assertStringContainsString('Eingegangene Anfragen (1)', $nachAnfrage->body);

        // 7. Ein ausgefüllter Honeypot meldet Erfolg und legt nichts an - der
        //    Bot soll nicht erfahren, woran es lag.
        $honeypot = $this->sendeAnfrage($visitor, $personId, [
            'grund' => 'kaufinteresse',
            'name' => "Bot-{$unique}",
            'email' => "bot-{$unique}@example.test",
            'webseite' => 'https://spam.example',
        ]);
        $this->assertSame("/person?id={$personId}&kontaktanfrage=erfolg", $honeypot->location());
        $nachHoneypot = $admin->get(self::VERWALTUNG);
        $this->assertStringContainsString('Eingegangene Anfragen (1)', $nachHoneypot->body);
        $this->assertStringNotContainsString("Bot-{$unique}", $nachHoneypot->body);

        // 8. Ein Grund außerhalb der Weißliste wird abgewiesen.
        $fremderGrund = $this->sendeAnfrage($visitor, $personId, [
            'grund' => 'ausgedacht',
            'name' => "Fremd-{$unique}",
            'email' => "fremd-{$unique}@example.test",
        ]);
        $this->assertSame("/person?id={$personId}&kontaktanfrage=fehler", $fremderGrund->location());
        $this->assertStringNotContainsString("Fremd-{$unique}", $admin->get(self::VERWALTUNG)->body);

        // 9. Ohne gültiges CSRF-Token: 403, kein stiller Durchlauf.
        $ohneToken = $visitor->post('/plugin/kontaktanfrage/senden', [
            'csrf_token' => 'ungueltig',
            'ziel_typ' => 'person',
            'ziel_id' => (string) $personId,
            'grund' => 'kaufinteresse',
            'name' => 'Test',
            'email' => 'test@example.test',
        ]);
        $this->assertSame(403, $ohneToken->statusCode);

        // 10. Opt-out über den Hook person.edit_sections im Bearbeitungsformular:
        //     eigenes Formular, eigene Route, eigene Tabelle - keine Spalte im
        //     Kern.
        $bearbeiten = $admin->get("/admin/persons/edit?id={$personId}");
        $this->assertStringContainsString('Kontaktanfragen über das Formular zulassen', $bearbeiten->body);
        $optOut = $admin->post('/plugin/kontaktanfrage/opt-out', [
            'csrf_token' => $bearbeiten->formField('csrf_token') ?? '',
            'ziel_typ' => 'person',
            'ziel_id' => (string) $personId,
            'erlaubt' => '0',
        ]);
        $this->assertSame(302, $optOut->statusCode);

        $nachOptOut = $this->newClient()->get("/person?id={$personId}");
        $this->assertStringNotContainsString(
            'Kontakt aufnehmen',
            $nachOptOut->body,
            'Nach dem Opt-out darf kein Kontaktformular mehr erscheinen.'
        );

        // 11. Das Opt-out gilt auch rückwirkend für die bereits gespeicherte
        //     Anfrage - eine Weiterleitung wird abgelehnt.
        $verwaltungNachOptOut = $admin->get(self::VERWALTUNG);
        $anfrageId = $this->ersteAnfrageId($verwaltungNachOptOut->body);
        $weiterleiten = $admin->post('/plugin/kontaktanfrage/verwaltung/weiterleiten', [
            'csrf_token' => $verwaltungNachOptOut->formField('csrf_token') ?? '',
            'id' => (string) $anfrageId,
        ]);
        $this->assertSame(self::VERWALTUNG . '?ka=opt-out', $weiterleiten->location());

        // 12. Löschen räumt die Anfrage wieder ab.
        $geloescht = $admin->post('/plugin/kontaktanfrage/verwaltung/loeschen', [
            'csrf_token' => $verwaltungNachOptOut->formField('csrf_token') ?? '',
            'id' => (string) $anfrageId,
        ]);
        $this->assertSame(self::VERWALTUNG . '?ka=geloescht', $geloescht->location());
        $this->assertStringContainsString('Eingegangene Anfragen (0)', $admin->get(self::VERWALTUNG)->body);

        // 13. Das Backend ist nichts für Gäste.
        $this->assertContains(
            $this->newClient()->get(self::VERWALTUNG)->statusCode,
            [302, 403, 404],
            'Die Anfragen-Verwaltung darf ohne Anmeldung nicht ausgeliefert werden.'
        );

        // 14. Plugin abschalten: Formular und Route verschwinden mit ihm.
        $disable = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '0',
        ]);
        $this->assertSame('/admin/plugins?success=1', $disable->location());
        $this->assertSame(404, $admin->get(self::VERWALTUNG)->statusCode);
    }

    /**
     * Holt die Personenseite (wegen des frischen CSRF-Tokens) und schickt das
     * Kontaktformular ab.
     *
     * @param array<string, string> $felder
     */
    /**
     * #109: Die vierte Huerde des anonymen Endpunkts - das Rate-Limit.
     *
     * CSRF, Honeypot und Gruende-Weissliste waren getestet, die Mengensperre
     * nicht: weder das IP-Limit (5/Stunde) noch das Empfaenger-Limit
     * (10/Tag), noch der daraus folgende Status `zuviele`, noch die Buchung
     * ueber recordAttempt(). Vertauschte Argumente oder ein doppelt
     * verwendeter Typ-String in RateLimiter::tooManyAttempts() haetten die
     * Sperre aufgehoben, ohne dass irgendein Test rot wird - ein Bot koennte
     * dann eine einzelne Person unbegrenzt zumuellen, jede Anfrage mit einer
     * DB-Zeile und einer Mail ans Team.
     *
     * Beide Zaehler werden einzeln belegt. Nur einer von beiden waere leicht
     * zu umgehen: Der IP-Zaehler bremst den einzelnen Absender, der
     * Empfaenger-Zaehler die Belaestigung ueber wechselnde Anschluesse.
     */
    public function testRateLimitGreiftJeIpUndJeEmpfaenger(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $personId = $this->createPerson($admin, "KALimit-{$unique}", [
            'email' => "limit-person-{$unique}@example.test",
        ]);

        $verwaltung = $admin->get(self::VERWALTUNG);
        $this->assertSame(200, $verwaltung->statusCode);
        $this->assertSame(
            self::VERWALTUNG . '?ka=gespeichert',
            $admin->post('/plugin/kontaktanfrage/verwaltung/einstellungen', [
                'csrf_token' => $verwaltung->formField('csrf_token') ?? '',
                'team_email' => "team-limit-{$unique}@example.test",
                'zusatz_gruende' => '',
                'aufbewahrung_tage' => '180',
            ])->location()
        );

        $besucher = $this->newClient();
        $zuvieleZiel = "/person?id={$personId}&kontaktanfrage=zuviele";

        // Die Zaehler liegen in login_attempts und ueberdauern den einzelnen
        // Testfall - der Lebenszyklus-Test darueber hat schon Anfragen
        // abgesetzt. Ohne dieses Zuruecksetzen liefe die Schwelle hier bei
        // einem beliebigen Zwischenstand los.
        $this->leereRateLimitZaehler('kontaktanfrage-ip');
        $this->leereRateLimitZaehler('kontaktanfrage-ziel');

        // (a) Ein ausgefuellter Honeypot wird VOR der Buchung verworfen und
        //     darf den Zaehler deshalb nicht belasten. Ein zu scharfes Limit
        //     wiese sonst echte Besucher nach wenigen Aufrufen ab.
        $this->sendeAnfrage($besucher, $personId, [
            'grund' => 'kaufinteresse',
            'name' => "Bot-{$unique}",
            'email' => "bot-{$unique}@example.test",
            'webseite' => 'https://spam.example',
        ]);
        $this->assertSame(
            0,
            $this->rateLimitStand('kontaktanfrage-ip'),
            'Ein Honeypot-Treffer darf nicht als Versuch gezaehlt werden'
        );

        // (b) IP-Limit: Die ersten fuenf gehen durch, die sechste nicht.
        for ($i = 1; $i <= 5; $i++) {
            $antwort = $this->sendeAnfrage($besucher, $personId, [
                'grund' => 'kaufinteresse',
                'name' => "Absender-{$i}-{$unique}",
                'email' => "absender-{$i}-{$unique}@example.test",
            ]);
            $this->assertNotSame(
                $zuvieleZiel,
                $antwort->location(),
                "Anfrage {$i} von 5 darf noch nicht am Limit scheitern"
            );
        }

        $vorher = $this->anzahlAnfragen();
        $sechste = $this->sendeAnfrage($besucher, $personId, [
            'grund' => 'kaufinteresse',
            'name' => "Absender-6-{$unique}",
            'email' => "absender-6-{$unique}@example.test",
        ]);
        $this->assertSame($zuvieleZiel, $sechste->location(), 'Die sechste Anfrage derselben IP muss abgewiesen werden');
        $this->assertSame(
            $vorher,
            $this->anzahlAnfragen(),
            'Eine abgewiesene Anfrage darf nicht gespeichert werden'
        );

        // (c) Empfaenger-Limit: unabhaengig von der IP. Der IP-Zaehler wird
        //     zwischendurch geleert - das ist genau der Angreifer, der ueber
        //     wechselnde Anschluesse kommt, nur ohne dass der Test dafuer
        //     einen zweiten Anschluss braeuchte.
        $this->leereRateLimitZaehler('kontaktanfrage-ip');
        for ($i = 6; $i <= 10; $i++) {
            $antwort = $this->sendeAnfrage($besucher, $personId, [
                'grund' => 'kaufinteresse',
                'name' => "Absender-{$i}-{$unique}",
                'email' => "absender-{$i}-{$unique}@example.test",
            ]);
            $this->assertNotSame(
                $zuvieleZiel,
                $antwort->location(),
                "Anfrage {$i} an dasselbe Ziel darf noch nicht am Limit scheitern"
            );
            $this->leereRateLimitZaehler('kontaktanfrage-ip');
        }

        $elfte = $this->sendeAnfrage($besucher, $personId, [
            'grund' => 'kaufinteresse',
            'name' => "Absender-11-{$unique}",
            'email' => "absender-11-{$unique}@example.test",
        ]);
        $this->assertSame(
            $zuvieleZiel,
            $elfte->location(),
            'Bei frischem IP-Zaehler muss das Empfaenger-Limit greifen - sonst zaehlen beide Sperren dasselbe'
        );

        // (d) Die Abweisung steht im Protokoll.
        $stmt = \App\Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM audit_logs WHERE action = ? AND created_at >= (NOW() - INTERVAL 10 MINUTE)"
        );
        $stmt->execute(['Kontaktanfrage abgewiesen (Rate-Limit)']);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn(), 'Die Abweisung gehoert ins Audit-Log');

        $this->leereRateLimitZaehler('kontaktanfrage-ip');
        $this->leereRateLimitZaehler('kontaktanfrage-ziel');
    }

    private function leereRateLimitZaehler(string $typ): void {
        \App\Database::getInstance()
            ->prepare('DELETE FROM login_attempts WHERE type = ?')
            ->execute([$typ]);
    }

    private function rateLimitStand(string $typ): int {
        $stmt = \App\Database::getInstance()->prepare('SELECT COUNT(*) FROM login_attempts WHERE type = ?');
        $stmt->execute([$typ]);
        return (int)$stmt->fetchColumn();
    }

    private function anzahlAnfragen(): int {
        return (int)\App\Database::getInstance()
            ->query('SELECT COUNT(*) FROM `plugin_kontaktanfrage_requests`')
            ->fetchColumn();
    }

    private function sendeAnfrage(HttpClient $client, int $personId, array $felder): \Tests\Support\HttpResponse {
        $seite = $client->get("/person?id={$personId}");
        return $client->post('/plugin/kontaktanfrage/senden', array_merge([
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'ziel_typ' => 'person',
            'ziel_id' => (string) $personId,
        ], $felder));
    }

    /** Liest die ID der ersten Anfrage aus einem Aktionsformular der Liste. */
    private function ersteAnfrageId(string $body): int {
        preg_match('/name="id" value="(\d+)"/', $body, $treffer);
        $this->assertNotEmpty($treffer, 'Keine Anfrage-ID in der Verwaltungsliste gefunden.');
        return (int) $treffer[1];
    }
}
