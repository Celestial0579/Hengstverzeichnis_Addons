<?php
// tests/Functional/CaptchaAnbieterPluginTest.php

namespace Tests\Functional;

use App\Database;
use Tests\Support\HttpClient;

/**
 * End-to-End-Test für die drei Captcha-Anbieter-Addons aus Addons#133
 * (plugins/captcha-turnstile, plugins/captcha-hcaptcha, plugins/captcha-altcha)
 * gegen eine echte, per `php -S` gestartete Framework-Instanz.
 *
 * EIN TEST FÜR DREI ADDONS, WEIL SIE EINEN VERTRAG TEILEN. Die drei
 * Erweiterungen sind "ein Muster, dreimal angewandt" - und die Eigenschaften,
 * an denen ein Anbieter-Addon scheitert, sind bei allen dreien dieselben:
 * ohne Schlüssel nicht in der Auswahl erscheinen, ein fremdes Urteil nicht
 * überschreiben, fail-closed bleiben, das Geheimnis nicht ausplaudern.
 *
 * WAS HIER BEWUSST NICHT GEPRÜFT WIRD: der Prüfaufruf gegen Cloudflare bzw.
 * hCaptcha. Ein Test, der eine fremde API anruft, misst deren Erreichbarkeit,
 * nicht diesen Code - und in einem nächtlichen Lauf ohne Netz wäre er rot,
 * ohne dass etwas kaputt ist. Geprüft wird stattdessen der Zweig, der ohne
 * Netzaufruf entscheidet (leeres Antwortfeld -> nicht bestanden) und der
 * vollständige Weg des SELBST GEHOSTETEN Anbieters, bei dem es nichts
 * anzurufen gibt: Der Test rechnet den Nachweis in PHP nach, genau wie es
 * sonst der Browser täte.
 */
class CaptchaAnbieterPluginTest extends FunctionalTestCase {

    private const TURNSTILE = 'captcha-turnstile';
    private const HCAPTCHA = 'captcha-hcaptcha';
    private const ALTCHA = 'captcha-altcha';

    /**
     * Erfundene, aber formgültige Zugangsdaten. Das Geheimnis darf im
     * gesamten Lauf in KEINER Antwort auftauchen - dafür gibt es einen
     * eigenen Testfall.
     */
    private const TEST_SITEKEY = '0x4AAAAAAATestSiteKeyTurnstile';
    private const TEST_SECRET = 'testgeheimnis-turnstile-0815-nie-ausgeben';

    private const HC_SITEKEY = '10000000-ffff-ffff-ffff-000000000001';
    private const HC_SECRET = 'testgeheimnis-hcaptcha-4711-nie-ausgeben';

    /**
     * Die drei Addons erscheinen erst dann in der Anbieterauswahl, wenn sie
     * tatsächlich funktionieren können - der selbst gehostete sofort, die
     * beiden Drittanbieter erst mit Schlüssel und Geheimnis.
     *
     * Das ist die Anforderung aus Addons#133, die am leichtesten falsch
     * herum gebaut wird: Ein Anbieter ohne Zugangsdaten in der Liste sieht
     * harmlos aus, lehnt aber jede Prüfung ab - der Betreiber wählt ihn,
     * merkt nichts, und die Besucher kommen nicht mehr durch das Formular.
     */
    public function testAnbieterErscheinenErstWennSieFunktionierenKoennen(): void {
        $admin = $this->authenticatedClient();
        $this->addonsAktivieren($admin);
        self::schluesselEntfernen();

        $einstellungen = $admin->get('/admin/system-settings');
        $this->assertSame(200, $einstellungen->statusCode);

        $this->assertStringContainsString(
            'value="' . self::ALTCHA . '"',
            $einstellungen->body,
            'Der selbst gehostete Anbieter braucht keine Schlüssel und muss sofort wählbar sein.'
        );
        $this->assertStringNotContainsString(
            'value="' . self::TURNSTILE . '"',
            $einstellungen->body,
            'Ohne Site-Key und Secret darf Turnstile NICHT in der Anbieterauswahl stehen.'
        );
        $this->assertStringNotContainsString(
            'value="' . self::HCAPTCHA . '"',
            $einstellungen->body,
            'Ohne Sitekey und Secret darf hCaptcha NICHT in der Anbieterauswahl stehen.'
        );

        // Der eingebaute Anbieter des Kerns bleibt in jedem Fall wählbar - er
        // ist der Rückfallweg, den kein Addon unbrauchbar machen darf.
        $this->assertStringContainsString('value="builtin"', $einstellungen->body);

        $this->schluesselSpeichern($admin, self::TURNSTILE, self::TEST_SITEKEY, self::TEST_SECRET, 'ct');
        $this->schluesselSpeichern($admin, self::HCAPTCHA, self::HC_SITEKEY, self::HC_SECRET, 'hc');

        $nachher = $admin->get('/admin/system-settings');
        $this->assertStringContainsString(
            'value="' . self::TURNSTILE . '"',
            $nachher->body,
            'Mit hinterlegten Schlüsseln muss Turnstile wählbar werden.'
        );
        $this->assertStringContainsString(
            'value="' . self::HCAPTCHA . '"',
            $nachher->body,
            'Mit hinterlegten Schlüsseln muss hCaptcha wählbar werden.'
        );
    }

    /**
     * Das Geheimnis darf nirgends in einer Antwort auftauchen - weder auf der
     * Verwaltungsseite, die es entgegennimmt, noch im Protokoll, das die
     * Änderung festhält.
     *
     * Der Testfall wirkt trivial und ist es nicht: Ein Eingabefeld mit
     * `value="<gespeichertes Secret>"` vorzubelegen ist die naheliegendste
     * Art, so ein Formular zu bauen - und damit steht das Geheimnis im
     * Quelltext jeder Aufrufer-Sitzung.
     */
    public function testGeheimnisTauchtInKeinerAntwortAuf(): void {
        $admin = $this->authenticatedClient();
        $this->addonsAktivieren($admin);
        $this->schluesselSpeichern($admin, self::TURNSTILE, self::TEST_SITEKEY, self::TEST_SECRET, 'ct');

        $seite = $admin->get('/plugin/' . self::TURNSTILE . '/verwaltung');
        $this->assertSame(200, $seite->statusCode);

        // Der Site-Key ist öffentlich und wird angezeigt - das ist der
        // Gegenbeweis dafür, dass die Seite überhaupt die gespeicherten Werte
        // kennt und der folgende Test nicht bloss eine leere Seite prüft.
        $this->assertStringContainsString(self::TEST_SITEKEY, $seite->body);
        $this->assertStringContainsString('hinterlegt (verschlüsselt gespeichert)', $seite->body);

        $this->assertStringNotContainsString(
            self::TEST_SECRET,
            $seite->body,
            'Das gespeicherte Secret darf auf der Verwaltungsseite nicht auftauchen.'
        );

        $protokoll = $admin->get('/admin/logs');
        $this->assertSame(200, $protokoll->statusCode);
        $this->assertStringNotContainsString(
            self::TEST_SECRET,
            $protokoll->body,
            'Das Secret darf nicht im dauerhaft gespeicherten Protokoll landen.'
        );
        $this->assertStringContainsString(
            'Zugangsdaten geändert',
            $protokoll->body,
            'Die Änderung selbst gehört ins Protokoll (Framework#352) - nur eben ohne den Wert.'
        );
    }

    /**
     * Ist Turnstile gewählt, steht sein Widget im DSGVO-Formular statt der
     * eingebauten Rechenaufgabe - und ein Absenden ohne gelöstes Widget wird
     * abgewiesen, ohne dass dafür jemand angerufen werden müsste.
     */
    public function testTurnstileWidgetErscheintUndLeereAntwortWirdAbgewiesen(): void {
        $admin = $this->authenticatedClient();
        $this->addonsAktivieren($admin);
        $this->schluesselSpeichern($admin, self::TURNSTILE, self::TEST_SITEKEY, self::TEST_SECRET, 'ct');
        self::anbieterSetzen(self::TURNSTILE);

        $besucher = $this->newClient();
        $formular = $besucher->get('/dsgvo');
        $this->assertSame(200, $formular->statusCode);

        $this->assertStringContainsString('cf-turnstile', $formular->body);
        $this->assertStringContainsString('data-sitekey="' . self::TEST_SITEKEY . '"', $formular->body);
        $this->assertStringContainsString('challenges.cloudflare.com', $formular->body);
        $this->assertStringContainsString(
            'Cloudflare, Inc. (USA)',
            $formular->body,
            'Der Besucher muss im Formular erfahren, wohin seine Daten gehen.'
        );
        $this->assertStringNotContainsString(
            'id="captcha"',
            $formular->body,
            'Bei gewähltem Drittanbieter darf die eingebaute Rechenaufgabe nicht zusätzlich erscheinen.'
        );

        // Der Honeypot des Kerns bleibt unabhängig vom Anbieter bestehen.
        $this->assertStringContainsString('name="' . \App\Security\Captcha::HONEYPOT_FIELD . '"', $formular->body);

        self::dsgvoZaehlerZuruecksetzen();
        $antwort = $besucher->post('/dsgvo', [
            'csrf_token' => $formular->formField('csrf_token') ?? '',
            'name' => 'Testperson',
            'email' => 'captcha-test@example.test',
            'request_type' => 'info',
            'message' => 'Auskunft bitte.',
        ]);

        $this->assertNull(
            $antwort->location(),
            'Ohne gelöstes Widget darf die Anfrage NICHT angenommen werden (fail-closed).'
        );
        $this->assertStringContainsString('Die Rechenaufgabe wurde nicht richtig gelöst', $antwort->body);

        self::anbieterSetzen('builtin');
    }

    /**
     * Der selbst gehostete Anbieter, vollständig durchgespielt: Aufgabe aus
     * dem Formular lesen, Nachweis rechnen (in PHP, wie sonst der Browser),
     * abschicken - und die Anfrage kommt an.
     *
     * Zusätzlich die beiden Gegenproben, ohne die der Test nichts festhielte:
     * eine falsche Zahl wird abgewiesen, und eine EINMAL benutzte Aufgabe
     * lässt sich nicht wiederverwenden.
     */
    public function testAltchaNachweisWirdGerechnetGeprueftUndNurEinmalAkzeptiert(): void {
        $admin = $this->authenticatedClient();
        $this->addonsAktivieren($admin);
        self::anbieterSetzen(self::ALTCHA);

        $besucher = $this->newClient();
        $formular = $besucher->get('/dsgvo');
        $this->assertSame(200, $formular->statusCode);

        $this->assertStringContainsString('data-hv-altcha=', $formular->body);
        $this->assertStringContainsString('name="altcha_payload"', $formular->body);
        $this->assertStringContainsString(
            'keine Daten an Dritte übermittelt',
            $formular->body,
            'Der Unterschied zu den Drittanbietern gehört sichtbar ins Formular.'
        );
        $this->assertStringContainsString(
            '<noscript>',
            $formular->body,
            'Der Rückfall ohne JavaScript ist standardmässig an - ohne ihn wären Besucher ohne '
            . 'JavaScript vom DSGVO-Portal ausgesperrt.'
        );
        $this->assertStringNotContainsString(
            'challenges.cloudflare.com',
            $formular->body,
            'Der selbst gehostete Anbieter lädt nichts von fremden Herkünften.'
        );

        // 1. Falsche Zahl: der Nachweis stimmt nicht, die Anfrage wird abgewiesen.
        $aufgabe = self::aufgabeAuslesen($formular->body);
        $falsch = self::nutzlast($aufgabe, self::nachweisLoesen($aufgabe) + 1);

        self::dsgvoZaehlerZuruecksetzen();
        $abgelehnt = $besucher->post('/dsgvo', self::dsgvoFelder($formular->formField('csrf_token') ?? '', [
            'altcha_payload' => $falsch,
        ]));
        $this->assertNull($abgelehnt->location(), 'Ein falscher Nachweis darf nicht angenommen werden.');

        // 2. Richtiger Nachweis auf einer frischen Aufgabe: angenommen.
        $formular2 = $besucher->get('/dsgvo');
        $aufgabe2 = self::aufgabeAuslesen($formular2->body);
        $richtig = self::nutzlast($aufgabe2, self::nachweisLoesen($aufgabe2));

        self::dsgvoZaehlerZuruecksetzen();
        $angenommen = $besucher->post('/dsgvo', self::dsgvoFelder($formular2->formField('csrf_token') ?? '', [
            'altcha_payload' => $richtig,
        ]));
        $this->assertSame(
            '/dsgvo?success=1',
            $angenommen->location(),
            "Ein korrekt gerechneter Nachweis muss durchgelassen werden, Body: {$angenommen->body}"
        );

        // 3. Derselbe Nachweis ein zweites Mal: die Aufgabe ist verbraucht.
        // Ohne diese Eigenschaft taugte ein einmal gelöster Nachweis für eine
        // ganze Serie von Absendungen.
        self::dsgvoZaehlerZuruecksetzen();
        $wiederholt = $besucher->post('/dsgvo', self::dsgvoFelder($formular2->formField('csrf_token') ?? '', [
            'altcha_payload' => $richtig,
        ]));
        $this->assertNull(
            $wiederholt->location(),
            'Eine bereits verbrauchte Aufgabe darf kein zweites Mal durchgehen (Einmalverwendung).'
        );

        self::anbieterSetzen('builtin');
    }

    /**
     * Fail-closed bei den Berechtigungen: Wer das Verwaltungsrecht des Addons
     * nicht hat, kommt nicht auf die Verwaltungsseite - und wer es hat, schon.
     *
     * Geprüft mit einer echten Nicht-Admin-Sitzung, denn ein Admin hat
     * serverseitig immer alle Rechte - ein Test als Admin könnte diese
     * Zusicherung gar nicht verletzen sehen.
     *
     * NICHT geprüft wird die Dashboard-Kachel, und das ist eine gemessene
     * Feststellung, keine Auslassung: `src/Views/admin_dashboard.php` rendert
     * den ganzen Systembereich samt `$pluginTiles` nur innerhalb von
     * `if ($isAdmin)`. Ein Nicht-Admin sieht deshalb NIE eine Addon-Kachel,
     * unabhängig davon, ob das Addon selbst prüft. Eine Zusicherung
     * "Kachel fehlt ohne Berechtigung" wäre hier also immer erfüllt und hielte
     * nichts fest - der Rechte-Riegel in Plugin::dashboardKachel() bleibt
     * trotzdem stehen, siehe den Kommentar dort.
     */
    public function testVerwaltungIstOhneBerechtigungUnerreichbar(): void {
        $admin = $this->authenticatedClient();
        $this->addonsAktivieren($admin);

        $editorGruppe = $this->findBuiltinGroupId($admin, 'Editor');
        $this->setGroupPermissions($admin, $editorGruppe, self::EDITOR_DEFAULT_PERMISSIONS);

        $eindeutig = substr(uniqid(), -6);
        $editor = $this->createAndLoginEditor(
            $admin,
            "captchaeditor{$eindeutig}",
            "captcha-editor-{$eindeutig}@example.test",
            [$editorGruppe]
        );

        foreach ([self::TURNSTILE, self::HCAPTCHA, self::ALTCHA] as $slug) {
            $seite = $editor->get('/plugin/' . $slug . '/verwaltung');
            $this->assertSame(
                403,
                $seite->statusCode,
                "Die Verwaltungsseite von {$slug} muss ohne die Berechtigung 403 liefern."
            );
        }

        // Und der Gegenbeweis: Mit der Berechtigung geht es. Sonst wüsste der
        // Test nicht, ob er die Rechteprüfung misst oder eine kaputte Route.
        $this->setGroupPermissions($admin, $editorGruppe, array_merge(
            self::EDITOR_DEFAULT_PERMISSIONS,
            [self::TURNSTILE => ['manage']]
        ));
        $erlaubt = $editor->get('/plugin/' . self::TURNSTILE . '/verwaltung');
        $this->assertSame(200, $erlaubt->statusCode);

        $this->setGroupPermissions($admin, $editorGruppe, self::EDITOR_DEFAULT_PERMISSIONS);
    }

    /**
     * Die Content-Security-Policy ist der Punkt, an dem solche Addons
     * erfahrungsgemäß scheitern: Ohne Freigabe bleibt das Widget lautlos
     * leer. Die Verwaltungsseite meldet das und trägt die Origins auf Knopf
     * nach - bestehende Einträge anderer Addons bleiben dabei stehen.
     */
    public function testCspFreigabeWirdGemeldetUndNachgetragen(): void {
        $admin = $this->authenticatedClient();
        $this->addonsAktivieren($admin);

        $seite = $admin->get('/plugin/' . self::TURNSTILE . '/verwaltung');
        $this->assertSame(200, $seite->statusCode);

        if (str_contains($seite->body, 'Alle nötigen Origins sind freigegeben')) {
            // Ein früherer Lauf hat die Freigabe bereits geschrieben; die
            // Aussage des Tests ist damit schon erfüllt.
            $this->assertStringContainsString('challenges.cloudflare.com', $seite->body);
            return;
        }

        $this->assertStringContainsString(
            'Es fehlt:',
            $seite->body,
            'Ohne Freigabe muss die Seite den fehlenden Origin nennen, statt ihn zu verschweigen.'
        );

        $antwort = $admin->post('/plugin/' . self::TURNSTILE . '/verwaltung/csp', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
        ]);
        $this->assertSame(
            '/plugin/' . self::TURNSTILE . '/verwaltung?ct=csp-gesetzt',
            $antwort->location(),
            "CSP-Freigabe fehlgeschlagen, Body: {$antwort->body}"
        );

        $danach = $admin->get('/plugin/' . self::TURNSTILE . '/verwaltung');
        $this->assertStringContainsString('Alle nötigen Origins sind freigegeben', $danach->body);

        // Der selbst gehostete Anbieter braucht dagegen gar keine Freigabe -
        // das ist einer seiner Vorzüge und gehört auf seine Seite.
        $altcha = $admin->get('/plugin/' . self::ALTCHA . '/verwaltung');
        $this->assertSame(200, $altcha->statusCode);
        $this->assertStringContainsString('gibt es hier nichts', $altcha->body);
    }

    // ---------------------------------------------------------------- Helfer

    private function addonsAktivieren(HttpClient $admin): void {
        foreach ([self::TURNSTILE, self::HCAPTCHA, self::ALTCHA] as $slug) {
            $antwort = $admin->post('/admin/plugins/toggle', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'slug' => $slug,
                'enable' => '1',
            ]);
            $this->assertSame(
                '/admin/plugins?success=1',
                $antwort->location(),
                "Aktivieren von '{$slug}' fehlgeschlagen (Weiterleitung: " . (string) $antwort->location() . ')'
            );
        }
    }

    /**
     * @param string $praefix Der Query-Parameter der Rückmeldung ('ct'/'hc')
     */
    private function schluesselSpeichern(
        HttpClient $admin,
        string $slug,
        string $siteKey,
        string $secret,
        string $praefix
    ): void {
        $seite = $admin->get('/plugin/' . $slug . '/verwaltung');
        $this->assertSame(200, $seite->statusCode, "Verwaltungsseite von '{$slug}' nicht erreichbar.");

        $antwort = $admin->post('/plugin/' . $slug . '/verwaltung/speichern', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'site_key' => $siteKey,
            'secret' => $secret,
        ]);
        $this->assertSame(
            '/plugin/' . $slug . '/verwaltung?' . $praefix . '=gespeichert',
            $antwort->location(),
            "Speichern der Schlüssel von '{$slug}' fehlgeschlagen, Body: {$antwort->body}"
        );
    }

    /**
     * Setzt die globale Anbieterwahl direkt in der Datenbank.
     *
     * Bewusst nicht über POST /admin/system-settings: Dieser Endpunkt schreibt
     * ein Dutzend weiterer Einstellungen aus demselben Formular mit und würde
     * beim Absenden mit nur einem Feld den Rest der Instanz auf leere Werte
     * setzen. Der Weg über die Oberfläche gehört zum Kern und ist dort
     * geprüft; hier interessiert das Verhalten der Addons.
     */
    private static function anbieterSetzen(string $slug): void {
        $stmt = Database::getInstance()->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('captcha_provider', ?)
             ON DUPLICATE KEY UPDATE setting_value = ?"
        );
        $stmt->execute([$slug, $slug]);
    }

    /**
     * Entfernt die hinterlegten Zugangsdaten beider Drittanbieter-Addons -
     * der Ausgangszustand für die Prüfung "ohne Schlüssel keine Anmeldung".
     */
    private static function schluesselEntfernen(): void {
        Database::getInstance()->exec(
            "DELETE FROM settings WHERE setting_key LIKE 'plugin\\_captcha\\_turnstile\\_%'
             OR setting_key LIKE 'plugin\\_captcha\\_hcaptcha\\_%'"
        );
    }

    /**
     * Die beiden IP-Zähler des DSGVO-Formulars (20 Versuche bzw. 3 Anfragen je
     * Stunde, siehe PublicController::dsgvoSubmit()). Ohne Zurücksetzen liefe
     * dieser Test in genau die Grenze, die er nicht misst.
     */
    private static function dsgvoZaehlerZuruecksetzen(): void {
        Database::getInstance()->exec(
            "DELETE FROM login_attempts WHERE type IN ('dsgvo_attempt', 'dsgvo_request')"
        );
    }

    /**
     * @param array<string, string> $zusatz
     * @return array<string, string>
     */
    private static function dsgvoFelder(string $csrf, array $zusatz = []): array {
        return array_merge([
            'csrf_token' => $csrf,
            'name' => 'Testperson',
            'email' => 'altcha-test@example.test',
            'request_type' => 'info',
            'message' => 'Auskunft bitte.',
        ], $zusatz);
    }

    /**
     * Liest die gestellte Aufgabe aus dem HTML - genau das, was auch das
     * Skript im Browser tut.
     *
     * @return array{algorithm:string, challenge:string, salt:string, maxnumber:int}
     */
    private static function aufgabeAuslesen(string $html): array {
        self::assertSame(
            1,
            preg_match('/data-hv-altcha="([^"]*)"/', $html, $treffer),
            'Im Formular steht keine ALTCHA-Aufgabe.'
        );

        $json = html_entity_decode($treffer[1], ENT_QUOTES, 'UTF-8');
        $aufgabe = json_decode($json, true);
        self::assertIsArray($aufgabe, "Die Aufgabe ist kein gültiges JSON: {$json}");
        self::assertArrayHasKey('salt', $aufgabe);
        self::assertArrayHasKey('challenge', $aufgabe);
        self::assertArrayHasKey('maxnumber', $aufgabe);

        return $aufgabe;
    }

    /**
     * Rechnet den Nachweis - dieselbe Schleife wie im Browser, nur in PHP.
     *
     * @param array{challenge:string, salt:string, maxnumber:int} $aufgabe
     */
    private static function nachweisLoesen(array $aufgabe): int {
        for ($n = 0; $n <= (int) $aufgabe['maxnumber']; $n++) {
            if (hash('sha256', $aufgabe['salt'] . $n) === $aufgabe['challenge']) {
                return $n;
            }
        }

        self::fail('Die gestellte Aufgabe hat innerhalb der Obergrenze keine Lösung - das darf nicht vorkommen.');
    }

    /**
     * @param array{algorithm?:string, challenge:string, salt:string} $aufgabe
     */
    private static function nutzlast(array $aufgabe, int $zahl): string {
        return base64_encode((string) json_encode([
            'algorithm' => $aufgabe['algorithm'] ?? 'SHA-256',
            'challenge' => $aufgabe['challenge'],
            'number' => $zahl,
            'salt' => $aufgabe['salt'],
        ]));
    }
}
