<?php
// tests/Functional/KontaktanfragePluginTest.php

namespace Tests\Functional;

use App\Security\Captcha;
use Tests\Support\HttpClient;
use Tests\Support\HttpResponse;

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
 *
 * Seit Addons#136 hängt das Addon an der zusammengeführten Kontaktliste
 * (Kern-#336): eine Tabelle `contacts`, eine öffentliche Adresse
 * `/kontakt?id=`, ein Rechte-Modul `contacts`. Der eigene Diskriminator
 * `target_type` ist ersatzlos entfallen. Die Datensätze legt der gemeinsame
 * PersonStationHelper über /admin/contacts an (Addons#122); die alten
 * schreibenden Endpunkte (/admin/persons/store) beantwortet der Kern seit
 * v0.8 absichtlich mit 404.
 */
class KontaktanfragePluginTest extends FunctionalTestCase {

    use PersonStationHelper;

    private const SLUG = 'kontaktanfrage';
    private const VERWALTUNG = '/plugin/kontaktanfrage/verwaltung';

    /**
     * Zahlwörter der deutschen Sprachdatei des Kerns (`captcha.number_*`).
     * Die Aufgabe wird ausdrücklich GELÖST, nicht umgangen: Die Lösung steht
     * nur serverseitig in der Session, hier wird sie wie von einem Menschen
     * über die Bedeutung der Zahlwörter gelesen. Den Schutz für Tests
     * abzuschalten wäre der falsche Weg - er wäre dann genau dort ungeprüft,
     * wo er zählt. Bauart übernommen aus dem DsgvoFormHelper des Kerns.
     *
     * @var array<string, int>
     */
    private const ZAHLWOERTER = [
        'eins' => 1, 'zwei' => 2, 'drei' => 3, 'vier' => 4, 'fünf' => 5,
        'sechs' => 6, 'sieben' => 7, 'acht' => 8, 'neun' => 9,
    ];

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
        $kontaktName = "KAKontakt-{$unique}";
        $kontaktEmail = "kontakt-{$unique}@example.test";
        $teamAdresse = "team-{$unique}@example.test";

        $kontaktId = $this->createContact($admin, $kontaktName, [
            'email' => $kontaktEmail,
            'city' => "Kontaktdorf-{$unique}",
        ]);

        $visitor = $this->newClient();

        // 2. Ohne hinterlegte Team-Adresse gibt es kein Formular. Eine Anfrage
        //    ohne Empfänger wäre ein Formular ins Nichts.
        $ohneTeam = $visitor->get("/kontakt?id={$kontaktId}");
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

        // 4. Jetzt erscheint das Formular auf der Kontaktseite - mit Honeypot,
        //    mit der Spam-Aufgabe des Kerns (#351) und ohne die E-Mail-Adresse
        //    des Empfängers preiszugeben.
        $mitFormular = $visitor->get("/kontakt?id={$kontaktId}");
        $this->assertStringContainsString('Kontakt aufnehmen', $mitFormular->body);
        $this->assertStringContainsString('name="webseite"', $mitFormular->body, 'Honeypot-Feld fehlt.');
        $this->assertStringContainsString(
            '<label for="captcha">',
            $mitFormular->body,
            'Das öffentliche Formular muss den Spam-Schutz des Kerns einbinden (#351).'
        );
        $this->assertStringNotContainsString(
            $kontaktEmail,
            $mitFormular->body,
            'Das Kontaktformular darf die Adresse des Empfängers nicht offenlegen.'
        );

        // Das Formular darf nur EINMAL auf der Seite stehen. Der Kern löst
        // person.detail_sections und station.detail_sections bis v0.9.0
        // zusätzlich als kaskadierenden Alias aus - ein Addon, das (wie dieses
        // bis v0.7) beide alten Namen registriert hat, bekommt seit der
        // Zusammenführung denselben Datensatz zweimal.
        $this->assertSame(
            1,
            substr_count($mitFormular->body, 'action="/plugin/kontaktanfrage/senden"'),
            'Das Kontaktformular steht doppelt auf der Seite - vermutlich sind die '
                . 'person.*/station.*-Aliasse zusätzlich registriert (Addons#136).'
        );

        // 5. Anfrage absenden. Ohne SMTP schlägt der Versand fehl - die
        //    Anfrage wird trotzdem gespeichert (siehe Klassenkommentar).
        $anfragenderName = "Interessent-{$unique}";
        $anfragenderEmail = "interessent-{$unique}@example.test";
        $gesendet = $this->sendeAnfrageMitAufgabe($visitor, $kontaktId, [
            'grund' => 'kaufinteresse',
            'name' => $anfragenderName,
            'email' => $anfragenderEmail,
        ]);
        $this->assertSame(
            "/kontakt?id={$kontaktId}&kontaktanfrage=fehler",
            $gesendet->location(),
            "Ohne Mailversand meldet das Addon ehrlich einen Fehler. Body: {$gesendet->body}"
        );

        // 6. Die Anfrage steht im Backend, mit Anfragendem und Grund.
        $nachAnfrage = $admin->get(self::VERWALTUNG);
        $this->assertStringContainsString($anfragenderName, $nachAnfrage->body);
        $this->assertStringContainsString($anfragenderEmail, $nachAnfrage->body);
        $this->assertStringContainsString($kontaktName, $nachAnfrage->body);
        $this->assertStringContainsString('Eingegangene Anfragen (1)', $nachAnfrage->body);
        $this->assertStringContainsString(
            "/admin/contacts/edit?id={$kontaktId}",
            $nachAnfrage->body,
            'Die Verwaltung muss auf die Kontaktverwaltung verlinken, nicht auf die alten Masken.'
        );

        // 7. Ein ausgefüllter Honeypot meldet Erfolg und legt nichts an - der
        //    Bot soll nicht erfahren, woran es lag. Er wird VOR der Spam-
        //    Aufgabe ausgewertet, deshalb ohne gelöste Aufgabe.
        $honeypot = $this->sendeAnfrage($visitor, $kontaktId, [
            'grund' => 'kaufinteresse',
            'name' => "Bot-{$unique}",
            'email' => "bot-{$unique}@example.test",
            'webseite' => 'https://spam.example',
        ]);
        $this->assertSame("/kontakt?id={$kontaktId}&kontaktanfrage=erfolg", $honeypot->location());
        $nachHoneypot = $admin->get(self::VERWALTUNG);
        $this->assertStringContainsString('Eingegangene Anfragen (1)', $nachHoneypot->body);
        $this->assertStringNotContainsString("Bot-{$unique}", $nachHoneypot->body);

        // 8. Ein Grund außerhalb der Weißliste wird abgewiesen - geprüft wird
        //    er erst hinter der Spam-Aufgabe, die deshalb gelöst wird.
        $fremderGrund = $this->sendeAnfrageMitAufgabe($visitor, $kontaktId, [
            'grund' => 'ausgedacht',
            'name' => "Fremd-{$unique}",
            'email' => "fremd-{$unique}@example.test",
        ]);
        $this->assertSame("/kontakt?id={$kontaktId}&kontaktanfrage=fehler", $fremderGrund->location());
        $this->assertStringNotContainsString("Fremd-{$unique}", $admin->get(self::VERWALTUNG)->body);

        // 9. Ohne gültiges CSRF-Token: 403, kein stiller Durchlauf.
        $ohneToken = $visitor->post('/plugin/kontaktanfrage/senden', [
            'csrf_token' => 'ungueltig',
            'kontakt_id' => (string) $kontaktId,
            'grund' => 'kaufinteresse',
            'name' => 'Test',
            'email' => 'test@example.test',
        ]);
        $this->assertSame(403, $ohneToken->statusCode);

        // 10. Opt-out über den Hook contact.edit_sections im
        //     Bearbeitungsformular: eigenes Formular, eigene Route, eigene
        //     Tabelle - keine Spalte im Kern.
        $bearbeiten = $admin->get("/admin/contacts/edit?id={$kontaktId}");
        $this->assertStringContainsString('Kontaktanfragen über das Formular zulassen', $bearbeiten->body);
        $this->assertSame(
            1,
            substr_count($bearbeiten->body, 'action="/plugin/kontaktanfrage/opt-out"'),
            'Der Opt-out-Abschnitt steht doppelt im Formular - siehe die Alias-Hooks (Addons#136).'
        );
        $optOut = $admin->post('/plugin/kontaktanfrage/opt-out', [
            'csrf_token' => $bearbeiten->formField('csrf_token') ?? '',
            'kontakt_id' => (string) $kontaktId,
            'erlaubt' => '0',
        ]);
        $this->assertSame(302, $optOut->statusCode);
        $this->assertSame("/admin/contacts/edit?id={$kontaktId}", $optOut->location());

        $nachOptOut = $this->newClient()->get("/kontakt?id={$kontaktId}");
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
     * #109: Die Mengensperre des anonymen Endpunkts.
     *
     * CSRF, Honeypot und Gruende-Weissliste waren getestet, die Mengensperre
     * nicht: weder das IP-Limit (5/Stunde) noch das Empfaenger-Limit
     * (10/Tag), noch der daraus folgende Status `zuviele`, noch die Buchung
     * ueber recordAttempt(). Vertauschte Argumente oder ein doppelt
     * verwendeter Typ-String in RateLimiter::tooManyAttempts() haetten die
     * Sperre aufgehoben, ohne dass irgendein Test rot wird - ein Bot koennte
     * dann einen einzelnen Kontakt unbegrenzt zumuellen, jede Anfrage mit
     * einer DB-Zeile und einer Mail ans Team.
     *
     * Beide Zaehler werden einzeln belegt. Nur einer von beiden waere leicht
     * zu umgehen: Der IP-Zaehler bremst den einzelnen Absender, der
     * Empfaenger-Zaehler die Belaestigung ueber wechselnde Anschluesse.
     *
     * DIE SPAM-AUFGABE WIRD HIER ABSICHTLICH NICHT GELOEST (Addons#136). Das
     * ist genau der Fall, der zaehlt: ein Bot, der die Antwort raet. Er muss
     * trotzdem am Mengenzaehler haengenbleiben - sonst koennte er die knapp
     * zwanzig moeglichen Antworten der eingebauten Rechenaufgabe
     * durchprobieren, bis eine passt, und die Aufgabe waere Zierde. Wer die
     * CAPTCHA-Pruefung vor die Buchung zieht, macht diesen Test rot: Die
     * sechste Anfrage kaeme dann durch.
     */
    public function testRateLimitGreiftJeIpUndJeEmpfaenger(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $kontaktId = $this->createContact($admin, "KALimit-{$unique}", [
            'email' => "limit-kontakt-{$unique}@example.test",
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
        $zuvieleZiel = "/kontakt?id={$kontaktId}&kontaktanfrage=zuviele";
        $captchaZiel = "/kontakt?id={$kontaktId}&kontaktanfrage=captcha";

        // Die Zaehler liegen in login_attempts und ueberdauern den einzelnen
        // Testfall - der Lebenszyklus-Test darueber hat schon Anfragen
        // abgesetzt. Ohne dieses Zuruecksetzen liefe die Schwelle hier bei
        // einem beliebigen Zwischenstand los.
        $this->leereRateLimitZaehler('kontaktanfrage-ip');
        $this->leereRateLimitZaehler('kontaktanfrage-ziel');

        // (a) Ein ausgefuellter Honeypot wird VOR der Buchung verworfen und
        //     darf den Zaehler deshalb nicht belasten. Ein zu scharfes Limit
        //     wiese sonst echte Besucher nach wenigen Aufrufen ab.
        $this->sendeAnfrage($besucher, $kontaktId, [
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
            $antwort = $this->sendeAnfrage($besucher, $kontaktId, [
                'grund' => 'kaufinteresse',
                'name' => "Absender-{$i}-{$unique}",
                'email' => "absender-{$i}-{$unique}@example.test",
            ]);
            $this->assertSame(
                $captchaZiel,
                $antwort->location(),
                "Anfrage {$i} von 5 darf noch nicht am Limit scheitern, sondern erst an der Aufgabe"
            );
        }

        $vorher = $this->anzahlAnfragen();
        $sechste = $this->sendeAnfrage($besucher, $kontaktId, [
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
            $antwort = $this->sendeAnfrage($besucher, $kontaktId, [
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

        $elfte = $this->sendeAnfrage($besucher, $kontaktId, [
            'grund' => 'kaufinteresse',
            'name' => "Absender-11-{$unique}",
            'email' => "absender-11-{$unique}@example.test",
        ]);
        $this->assertSame(
            $zuvieleZiel,
            $elfte->location(),
            'Bei frischem IP-Zaehler muss das Empfaenger-Limit greifen - sonst zaehlen beide Sperren dasselbe'
        );

        // (d) Die Abweisung steht im Protokoll - seit Kern-#352 unter der
        //     Kategorie des Addons, nicht unter einer frei gewaehlten.
        $stmt = \App\Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM audit_logs
             WHERE action = ? AND category = ? AND created_at >= (NOW() - INTERVAL 10 MINUTE)'
        );
        $stmt->execute(['Kontaktanfrage abgewiesen (Rate-Limit)', self::SLUG]);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn(), 'Die Abweisung gehoert ins Audit-Log');

        $this->leereRateLimitZaehler('kontaktanfrage-ip');
        $this->leereRateLimitZaehler('kontaktanfrage-ziel');
    }

    /**
     * Addons#136: Die einmalige Umrechnung der gespeicherten Ziele auf
     * Kontakt-Kennungen - und ihr Marker.
     *
     * WORUM ES GEHT. Bis v0.7 speicherten beide Tabellen dieses Addons
     * `(target_type, target_id)` ohne Fremdschluessel. Person 5 und Station 5
     * gab es BEIDE. Bei der Zusammenfuehrung (Kern-#336) behalten Personen
     * ihre Kennung, Deckstationen bekommen neue oberhalb des
     * Personenbestands - eine nicht umgerechnete Stationszeile zeigt also
     * nicht ins Leere, sondern auf eine FREMDE Person. Beim Opt-out heisst
     * das: Der Abbestellende ist wieder erreichbar, ein Unbeteiligter ist
     * stumm geschaltet. Genau diese Verwechslung prueft der Test, indem
     * dieselbe alte Kennung einmal als Person und einmal als Station
     * abgebildet wird.
     *
     * DER MARKER. install() laeuft bei JEDER Aktivierung erneut. Der zweite
     * Teil des Tests laesst ihn ein zweites Mal laufen und stellt ihm dabei
     * absichtlich einen ANDEREN Altverweis hin (siehe unten) - so
     * unterscheidet er "die Uebernahme lief kein zweites Mal" von "sie lief
     * noch einmal und kam zufaellig auf dasselbe Ergebnis".
     *
     * Dieser Testfall raeumt die beiden Addon-Tabellen ab und baut sie neu
     * auf. Er steht deshalb als letzter in der Klasse - PHPUnit fuehrt die
     * Faelle in Deklarationsreihenfolge aus - und hinterlaesst sie leer und
     * in der neuen Gestalt.
     */
    public function testUebernahmeRechnetAlteZieleGenauEinmalUm(): void {
        $this->ladePluginKlassen();

        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        // Zwei Kontakte und eine dritte Abbildung als Sonde fuer den zweiten
        // Lauf. Die alten Kennungen liegen bewusst hoch, damit sie mit dem
        // Bestand der Testinstanz nicht kollidieren.
        $alsPerson = $this->createContact($admin, "KAMigPerson-{$unique}");
        $alsStation = $this->createContact($admin, "KAMigStation-{$unique}");
        $sonde = $this->createContact($admin, "KAMigSonde-{$unique}");

        $db = \App\Database::getInstance();
        $alteKennung = 900000 + (int)(microtime(true) * 10) % 90000;

        $abbildung = $db->prepare(
            'INSERT INTO contact_id_map (old_type, old_id, contact_id) VALUES (?, ?, ?)'
        );
        // Dieselbe alte Kennung, zwei verschiedene Kontakte - der Kern der
        // Sache. Wer den Diskriminator beim Umrechnen wegwirft, trifft hier
        // den falschen von beiden.
        $abbildung->execute(['person', $alteKennung, $alsPerson]);
        $abbildung->execute(['station', $alteKennung, $alsStation]);
        $abbildung->execute(['station', $alteKennung + 1, $sonde]);

        // Bestand aus v0.7 nachbauen: alte Gestalt, alte Zeilen.
        $this->baueAltbestand($db, $alteKennung);

        // Marker entfernen - die Uebernahme lief auf dieser Instanz bei der
        // Aktivierung oben schon einmal (auf leerem Bestand).
        $db->prepare('DELETE FROM `plugin_kontaktanfrage_config` WHERE config_key = ?')
            ->execute([\Plugin\Kontaktanfrage\Uebernahme::MARKER]);
        $protokollVorher = $this->anzahlUebernahmeEintraege();

        $this->fuehreInstallAus();

        // 1. Die Anfrage an die PERSON zeigt auf den Personen-Kontakt.
        $this->assertSame(
            $alsPerson,
            $this->kontaktIdDerAnfrage($db, 'person-anfrage'),
            'Die Anfrage an die alte Person muss auf den daraus entstandenen Kontakt zeigen.'
        );

        // 2. Die Anfrage an die STATION zeigt auf den Stations-Kontakt - NICHT
        //    auf den gleichnamig nummerierten Personen-Kontakt.
        $this->assertSame(
            $alsStation,
            $this->kontaktIdDerAnfrage($db, 'station-anfrage'),
            'Die Anfrage an die alte Deckstation zeigt auf einen fremden Kontakt - '
                . 'der Diskriminator wurde beim Umrechnen nicht ausgewertet.'
        );

        // 3. Dasselbe fuer das Opt-out. Das ist der datenschutzrelevante Fall:
        //    Ein falsch umgerechnetes Opt-out macht den Abbestellenden wieder
        //    erreichbar und schaltet einen Unbeteiligten stumm.
        $optouts = $db->query('SELECT contact_id FROM `plugin_kontaktanfrage_optout` ORDER BY contact_id')
            ->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(
            [$alsStation],
            array_map('intval', $optouts),
            'Das Opt-out der alten Deckstation muss genau auf deren Kontakt zeigen - '
                . 'und das verwaiste Opt-out ohne Abbildung muss verschwunden sein.'
        );

        // 4. Eine Anfrage ohne Abbildung bleibt erhalten (kein stiller
        //    Datenverlust an einem protokollpflichtigen Vorgang) und zeigt als
        //    "kein Kontakt mehr" auf 0.
        $this->assertSame(
            0,
            $this->kontaktIdDerAnfrage($db, 'waisen-anfrage'),
            'Eine Anfrage ohne Abbildung gehoert erhalten, mit contact_id 0.'
        );

        // 5. Der Diskriminator ist weg - solange er in der Tabelle steht, wird
        //    er eines Tages wieder benutzt.
        $this->assertFalse(
            $this->spalteExistiert($db, 'plugin_kontaktanfrage_requests', 'target_type'),
            'Die Altspalte target_type muss nach der Uebernahme entfernt sein.'
        );
        $this->assertFalse(
            $this->spalteExistiert($db, 'plugin_kontaktanfrage_optout', 'target_type'),
            'Die Altspalte target_type muss auch im Opt-out entfernt sein.'
        );

        // 6. Der Marker steht, und die Uebernahme hat sich genau einmal
        //    protokolliert.
        $this->assertTrue($this->markerGesetzt($db), 'Die Uebernahme muss ihren Marker hinterlassen.');
        $this->assertSame(
            $protokollVorher + 1,
            $this->anzahlUebernahmeEintraege(),
            'Die Uebernahme gehoert genau einmal ins Protokoll.'
        );

        // 7. DER ZWEITE LAUF. Nachgestellt wird der Abbruch zwischen
        //    Umrechnung und dem Entfernen der Altspalten: Die Spalten sind
        //    wieder da, der Marker steht. Sie tragen absichtlich einen ANDEREN
        //    Verweis, als die Umrechnung sie vorgefunden hat - so entsteht das
        //    Ergebnis "unveraendert" nur, wenn wirklich nichts gerechnet
        //    wurde. Diese Kombination gibt es im Betrieb nicht; sie ist eine
        //    Sonde, kein Szenario.
        $db->exec(
            'ALTER TABLE `plugin_kontaktanfrage_requests`
             ADD COLUMN `target_type` VARCHAR(10) NOT NULL DEFAULT \'\',
             ADD COLUMN `target_id` INT NOT NULL DEFAULT 0'
        );
        $db->prepare(
            'UPDATE `plugin_kontaktanfrage_requests` SET target_type = ?, target_id = ? WHERE reason_key = ?'
        )->execute(['station', $alteKennung + 1, 'station-anfrage']);

        $this->fuehreInstallAus();

        $this->assertSame(
            $alsStation,
            $this->kontaktIdDerAnfrage($db, 'station-anfrage'),
            'Der zweite install()-Lauf hat die bereits umgerechnete Zeile erneut angefasst - '
                . 'der Marker aus Addons#136 greift nicht.'
        );
        $this->assertSame(
            $protokollVorher + 1,
            $this->anzahlUebernahmeEintraege(),
            'Die Uebernahme ist ein zweites Mal gelaufen - der Marker greift nicht.'
        );

        // Aufraeumen: Die Sonden-Spalten wieder weg, Tabellen leer und in der
        // neuen Gestalt hinterlassen.
        $db->exec('DROP TABLE IF EXISTS `plugin_kontaktanfrage_requests`');
        $db->exec('DROP TABLE IF EXISTS `plugin_kontaktanfrage_optout`');
        $this->fuehreInstallAus();
        $db->prepare('DELETE FROM contact_id_map WHERE old_id IN (?, ?)')
            ->execute([$alteKennung, $alteKennung + 1]);
    }

    /**
     * Legt beide Tabellen in der Gestalt aus v0.7 an und fuellt sie mit dem
     * Bestand, den eine echte Installation zum Zeitpunkt der Umstellung hat.
     */
    private function baueAltbestand(\PDO $db, int $alteKennung): void {
        $db->exec('DROP TABLE IF EXISTS `plugin_kontaktanfrage_requests`');
        $db->exec('DROP TABLE IF EXISTS `plugin_kontaktanfrage_optout`');

        $db->exec(
            'CREATE TABLE `plugin_kontaktanfrage_requests` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `target_type` VARCHAR(10) NOT NULL,
                `target_id` INT NOT NULL,
                `reason_key` VARCHAR(64) NOT NULL,
                `reason_label` VARCHAR(100) NOT NULL,
                `requester_name` VARCHAR(150) NOT NULL,
                `requester_email` VARCHAR(150) NOT NULL,
                `team_notified` TINYINT(1) NOT NULL DEFAULT 0,
                `forwarded_at` DATETIME NULL DEFAULT NULL,
                `forwarded_by` VARCHAR(100) NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_ka_ziel` (`target_type`, `target_id`),
                INDEX `idx_ka_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $db->exec(
            'CREATE TABLE `plugin_kontaktanfrage_optout` (
                `target_type` VARCHAR(10) NOT NULL,
                `target_id` INT NOT NULL,
                `disabled_by` VARCHAR(100) NULL DEFAULT NULL,
                `disabled_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`target_type`, `target_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $anfrage = $db->prepare(
            'INSERT INTO `plugin_kontaktanfrage_requests`
                (target_type, target_id, reason_key, reason_label, requester_name, requester_email)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $anfrage->execute(['person', $alteKennung, 'person-anfrage', 'Kaufinteresse', 'Alt Person', 'alt-person@example.test']);
        $anfrage->execute(['station', $alteKennung, 'station-anfrage', 'Deckanfrage', 'Alt Station', 'alt-station@example.test']);
        // Ziel schon vor der Umstellung endgueltig geloescht: keine Abbildung.
        $anfrage->execute(['station', 999999, 'waisen-anfrage', 'Sonstiges', 'Alt Waise', 'alt-waise@example.test']);

        $optout = $db->prepare(
            'INSERT INTO `plugin_kontaktanfrage_optout` (target_type, target_id, disabled_by) VALUES (?, ?, ?)'
        );
        $optout->execute(['station', $alteKennung, 'redaktion']);
        $optout->execute(['station', 999999, 'redaktion']);
    }

    /**
     * Ruft Plugin::install() im PHPUnit-Prozess auf - dieselbe Methode, die
     * der PluginManager bei jeder Aktivierung aufruft. Ueber HTTP waere der
     * Zeitpunkt nicht zu treffen: Die Aktivierung ueber /admin/plugins/toggle
     * legt die Tabellen an, bevor der Test den Altbestand hinstellen kann.
     */
    private function fuehreInstallAus(): void {
        $this->ladePluginKlassen();
        (new \Plugin\Kontaktanfrage\Plugin())->install();
    }

    /**
     * Laedt die Klassen des Addons in den PHPUnit-Prozess. Composer kennt den
     * Namensraum Plugin\ nicht - im Betrieb laedt der PluginManager die
     * Einstiegsdatei selbst, hier tut es require_once.
     */
    private function ladePluginKlassen(): void {
        require_once __DIR__ . '/../../plugins/kontaktanfrage/Plugin.php';
    }

    /**
     * Zaehlt die Protokolleintraege der Uebernahme - das Mass dafuer, wie oft
     * sie gelaufen ist.
     *
     * Bewusst OHNE Bedingung auf die Kategorie: install() laeuft hier im
     * PHPUnit-Prozess, und dort ist der PluginManager nicht gebootet.
     * PluginAudit kann den Slug deshalb nicht gegen die entdeckten Addons
     * pruefen und legt den Eintrag unter `plugin:unbekannt` ab - der
     * Rueckfallweg aus Kern-#352, "falsch einsortiert ist besser als weg".
     * Dass der normale Weg ueber HTTP unter `kontaktanfrage` protokolliert,
     * prueft testRateLimitGreiftJeIpUndJeEmpfaenger().
     */
    private function anzahlUebernahmeEintraege(): int {
        $stmt = \App\Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM audit_logs WHERE action = ?'
        );
        $stmt->execute(['Gespeicherte Ziele auf die Kontaktliste umgerechnet']);
        return (int)$stmt->fetchColumn();
    }

    private function kontaktIdDerAnfrage(\PDO $db, string $reasonKey): int {
        $stmt = $db->prepare('SELECT contact_id FROM `plugin_kontaktanfrage_requests` WHERE reason_key = ?');
        $stmt->execute([$reasonKey]);
        $wert = $stmt->fetchColumn();
        $this->assertNotFalse($wert, "Keine Anfrage mit reason_key '{$reasonKey}' gefunden.");
        return (int)$wert;
    }

    private function markerGesetzt(\PDO $db): bool {
        $stmt = $db->prepare('SELECT 1 FROM `plugin_kontaktanfrage_config` WHERE config_key = ?');
        $stmt->execute([\Plugin\Kontaktanfrage\Uebernahme::MARKER]);
        return $stmt->fetchColumn() !== false;
    }

    private function spalteExistiert(\PDO $db, string $tabelle, string $spalte): bool {
        $stmt = $db->query('SHOW COLUMNS FROM `' . $tabelle . '` LIKE ' . $db->quote($spalte));
        return $stmt !== false && $stmt->rowCount() > 0;
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

    /**
     * Holt die Kontaktseite (wegen des frischen CSRF-Tokens) und schickt das
     * Kontaktformular ab - ohne die Spam-Aufgabe zu loesen.
     *
     * @param array<string, string> $felder
     */
    private function sendeAnfrage(HttpClient $client, int $kontaktId, array $felder): HttpResponse {
        $seite = $client->get("/kontakt?id={$kontaktId}");
        return $client->post('/plugin/kontaktanfrage/senden', array_merge([
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'kontakt_id' => (string) $kontaktId,
        ], $felder));
    }

    /**
     * Wie oben, aber mit geloester Spam-Aufgabe - fuer jeden Pfad, der hinter
     * der Aufgabe liegt. Die Wartezeit ist Teil des Schutzes: Ein sofort nach
     * dem Rendern abgeschicktes Formular gilt als Bot
     * (Captcha::MIN_SOLVE_SECONDS).
     *
     * @param array<string, string> $felder
     */
    private function sendeAnfrageMitAufgabe(HttpClient $client, int $kontaktId, array $felder): HttpResponse {
        $seite = $client->get("/kontakt?id={$kontaktId}");
        $antwort = $this->loeseAufgabe($seite);
        sleep(Captcha::MIN_SOLVE_SECONDS);

        return $client->post('/plugin/kontaktanfrage/senden', array_merge([
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'kontakt_id' => (string) $kontaktId,
            'captcha' => (string) $antwort,
        ], $felder));
    }

    /** Loest die ausgeschriebene Rechenaufgabe ueber die Bedeutung der Zahlwoerter. */
    private function loeseAufgabe(HttpResponse $seite): int {
        preg_match('/<label for="captcha">.*?<strong>([^<]+)<\/strong>/su', $seite->body, $treffer);
        $this->assertNotEmpty(
            $treffer,
            "Konnte die Spam-Aufgabe nicht aus dem Formular lesen, Body: {$seite->body}"
        );

        $teile = preg_split('/\s+/u', trim($treffer[1]));
        $this->assertCount(3, $teile, "Unerwarteter Aufgabentext: {$treffer[1]}");

        $links = self::ZAHLWOERTER[$teile[0]] ?? null;
        $rechts = self::ZAHLWOERTER[$teile[2]] ?? null;
        $this->assertNotNull($links, "Unbekanntes Zahlwort: {$teile[0]}");
        $this->assertNotNull($rechts, "Unbekanntes Zahlwort: {$teile[2]}");
        $this->assertContains($teile[1], ['plus', 'minus'], "Unbekannter Operator: {$teile[1]}");

        return $teile[1] === 'minus' ? $links - $rechts : $links + $rechts;
    }

    /** Liest die ID der ersten Anfrage aus einem Aktionsformular der Liste. */
    private function ersteAnfrageId(string $body): int {
        preg_match('/name="id" value="(\d+)"/', $body, $treffer);
        $this->assertNotEmpty($treffer, 'Keine Anfrage-ID in der Verwaltungsliste gefunden.');
        return (int) $treffer[1];
    }
}
