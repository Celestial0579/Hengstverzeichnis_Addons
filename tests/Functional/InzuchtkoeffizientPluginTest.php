<?php
// tests/Functional/InzuchtkoeffizientPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/inzuchtkoeffizient gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 *
 * Baut einen klassischen Vollgeschwister-Verpaarungsfall auf (zwei
 * untereinander unverwandte Großeltern A/B - jeweils mit eigenen Eltern, siehe
 * Regressionshinweis zu Issue #25 im Testkörper -, zwei Nachkommen C/D aus
 * A x B, ein Fohlen E aus C x D) - der erwartete Inzuchtkoeffizient von E ist
 * exakt 25 % (zwei gemeinsame Vorfahren A und B, je ein Pfad mit n1=n2=1:
 * 2 x (0,5)^3 = 0,25).
 * Deckt sowohl den automatischen Abschnitt auf der Detailseite als auch den
 * Verpaarungsrechner samt Berechtigungsdurchsetzung ab; seit #72 zusätzlich
 * einen Fall mit gemeinsamem Ahnen in der SECHSTEN Generation der Vaterlinie
 * (Detailseite und Rechner müssen denselben Wert liefern) und seit #125 den
 * Einbau der GEMEINSAMEN Pferdesuche des Kerns anstelle der addoneigenen
 * /suche-Route.
 */
class InzuchtkoeffizientPluginTest extends FunctionalTestCase {

    use HorseListHelper;
    use PferdesucheHelper;

    private const SLUG = 'inzuchtkoeffizient';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString(
            // Apostroph wird von der Admin-Ansicht HTML-escaped ausgegeben (&#039;).
            'Wright&#039;s COI',
            $pluginsPage->body,
            'Plugin sollte unter /admin/plugins als entdeckt gelistet sein - wurde es nach vendor/hengstverzeichnis/framework/plugins kopiert?'
        );

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        $unique = uniqid();

        // Regression zu Issue #25: Die gemeinsamen Vorfahren A und B erhalten
        // selbst je zwei Eltern(-Generationen). Nach Wrights Pfadregel darf das
        // den COI von E NICHT verändern (die Ahnen von A/B sind nur DURCH A/B
        // hindurch erreichbar, ihr Beitrag steckt allein im hier bewusst
        // weggelassenen Term (1+F_A)) - die fehlerhafte Implementierung
        // summierte sie mit und lieferte 48,44 % statt 25,00 %.
        $aSireId = $this->createHorse($admin, "A-Vater-{$unique}", ['status' => 'active']);
        $aDamId = $this->createHorse($admin, "A-Mutter-{$unique}", ['status' => 'active']);
        $bSireId = $this->createHorse($admin, "B-Vater-{$unique}", ['status' => 'active']);
        $bDamId = $this->createHorse($admin, "B-Mutter-{$unique}", ['status' => 'active']);

        $aId = $this->createHorse($admin, "A-{$unique}", ['status' => 'active', 'sire_id' => (string) $aSireId, 'dam_id' => (string) $aDamId]);
        $bId = $this->createHorse($admin, "B-{$unique}", ['status' => 'active', 'sire_id' => (string) $bSireId, 'dam_id' => (string) $bDamId]);
        $cId = $this->createHorse($admin, "C-{$unique}", ['status' => 'active', 'sire_id' => (string) $aId, 'dam_id' => (string) $bId]);
        $dId = $this->createHorse($admin, "D-{$unique}", ['status' => 'active', 'sire_id' => (string) $aId, 'dam_id' => (string) $bId]);
        $eId = $this->createHorse($admin, "E-{$unique}", ['status' => 'active', 'sire_id' => (string) $cId, 'dam_id' => (string) $dId]);

        // 1. Öffentliche Detailseite des Fohlens E zeigt automatisch 25,00 %.
        $visitor = $this->newClient();
        $detailPage = $visitor->get("/horse?id={$eId}");
        $this->assertSame(200, $detailPage->statusCode);
        $this->assertStringContainsString(
            '25,00 %',
            $detailPage->body,
            "Detailseite von E sollte den berechneten Inzuchtkoeffizienten von 25,00 % enthalten. Body: {$detailPage->body}"
        );

        // 2. Ein Pferd ohne gemeinsame Vorfahren beider Elternseiten (A) hat COI 0,00 %.
        $unrelatedDetail = $visitor->get("/horse?id={$aId}");
        $this->assertStringContainsString('0,00 %', $unrelatedDetail->body);

        // 3. Verpaarungsrechner: Admin hat serverseitig immer alle Berechtigungen.
        // Seit #125 wählt das Formular die Elterntiere über das GEMEINSAME
        // Suchfeld des Kerns (hv-pferdesuche + /js/horse-search.js), das den
        // Endpunkt /admin/horses/search anspricht - die addoneigene
        // /suche-Route war eine von sieben Kopien derselben Suche.
        $sexMare = $this->createHorse($admin, "IkStute-{$unique}", ['status' => 'active', 'sex' => 'mare']);
        $sexStallion = $this->createHorse($admin, "IkHengst-{$unique}", ['status' => 'active', 'sex' => 'stallion']);

        $formPage = $admin->get('/plugin/inzuchtkoeffizient/rechner');
        $this->assertSame(200, $formPage->statusCode);
        // Der rolle-Parameter (#54) muss durchgereicht werden: Das Hengst-Feld
        // soll keine Stuten/Wallache vorschlagen und umgekehrt.
        $this->assertGemeinsamePferdesuche($formPage->body, 'sire_id', ['data-rolle' => 'sire']);
        $this->assertGemeinsamePferdesuche($formPage->body, 'dam_id', ['data-rolle' => 'dam']);
        // Die ID reist im <option> des Auswahlfelds - die frühere
        // "[#id]"-Krücke im ANZEIGETEXT ist damit weg.
        $this->assertStringNotContainsString("[#{$sexStallion}]", $formPage->body);

        // 3a. Die addoneigene Suchroute ist entfallen (#125).
        $this->assertEigeneSuchrouteEntfallen($admin, '/plugin/inzuchtkoeffizient/suche');

        // 3b. Serverprüfung (#54) unabhängig von den Vorschlägen: eine
        // rollen-widrige ID im Request wird weiterhin verworfen.
        $mismatchResponse = $admin->get("/plugin/inzuchtkoeffizient/rechner?sire_id={$sexMare}&dam_id={$sexStallion}");
        $this->assertStringContainsString('als Stute erfasst', $mismatchResponse->body, 'Rollen-widrige Auswahl muss serverseitig gemeldet und verworfen werden.');
        $this->assertStringNotContainsString('Voraussichtlicher Inzuchtkoeffizient', $mismatchResponse->body, 'Mit verworfener Auswahl darf kein Ergebnis erscheinen.');

        $calcResponse = $admin->get("/plugin/inzuchtkoeffizient/rechner?sire_id={$cId}&dam_id={$dId}");
        $this->assertSame(200, $calcResponse->statusCode);
        $this->assertStringContainsString('25,00 %', $calcResponse->body);
        // Vorbelegung aus den übergebenen IDs: Das Auswahlfeld trägt das
        // gewählte Pferd als einzige Option vor - sonst ginge es verloren,
        // sobald man nur die Generationstiefe ändert und erneut absendet
        // (#125).
        $this->assertStringContainsString("C-{$unique}", $calcResponse->body);
        $this->assertMatchesRegularExpression(
            '/<option value="' . $cId . '" selected>[^<]*C-' . preg_quote($unique, '/') . '/',
            $calcResponse->body,
            'Das gewählte Pferd muss als vorgetragene Option im Auswahlfeld stehen.'
        );

        // 3c. Regression zu #72: gemeinsamer Ahne "Rex" in der SECHSTEN
        // Generation der Vaterlinie (H -> V1 -> ... -> V4 -> Rex, n1 = 5) und
        // der dritten der Mutterlinie (S -> M -> Rex, n2 = 2). Beitrag nach
        // Wright: 0,5^(5+2+1) = 0,390625 % - er ist NUR sichtbar, wenn je
        // Elternteil ein eigener Baum mit dem Elternteil als Wurzel gebaut
        // wird. Die frühere Fassung hängte den Abschnitt an die Teilbäume des
        // Fohlen-Baums und zeigte hier 0,00 %.
        $rexId = $this->createHorse($admin, "Rex-{$unique}", ['status' => 'active']);
        $v4Id = $this->createHorse($admin, "V4-{$unique}", ['status' => 'active', 'sire_id' => (string) $rexId]);
        $v3Id = $this->createHorse($admin, "V3-{$unique}", ['status' => 'active', 'sire_id' => (string) $v4Id]);
        $v2Id = $this->createHorse($admin, "V2-{$unique}", ['status' => 'active', 'sire_id' => (string) $v3Id]);
        $v1Id = $this->createHorse($admin, "V1-{$unique}", ['status' => 'active', 'sire_id' => (string) $v2Id]);
        $hId = $this->createHorse($admin, "H-{$unique}", ['status' => 'active', 'sire_id' => (string) $v1Id]);
        $mId = $this->createHorse($admin, "M-{$unique}", ['status' => 'active', 'sire_id' => (string) $rexId]);
        $sId = $this->createHorse($admin, "S-{$unique}", ['status' => 'active', 'dam_id' => (string) $mId]);
        $fId = $this->createHorse($admin, "F-{$unique}", ['status' => 'active', 'sire_id' => (string) $hId, 'dam_id' => (string) $sId]);

        $deepDetail = $visitor->get("/horse?id={$fId}");
        $this->assertSame(200, $deepDetail->statusCode);
        $this->assertStringContainsString('0,39 %', $deepDetail->body,
            'Detailseite muss den Ahnen der 6. Generation zählen (0,5^8 = 0,39 %) - nicht 0,00 % wie vor #72.');
        $this->assertStringContainsString('Generationen je Elternteil', $deepDetail->body,
            'Beschreibungstext muss die Tiefe "je Elternteil" ausweisen.');

        $deepCalc = $admin->get("/plugin/inzuchtkoeffizient/rechner?sire_id={$hId}&dam_id={$sId}");
        $this->assertStringContainsString('0,39 %', $deepCalc->body,
            'Rechner (Standardtiefe 6) und Detailseite müssen bei identischer Datenlage denselben Wert liefern.');

        // 4. Berechtigungsdurchsetzung: Editor ohne inzuchtkoeffizient.calculate wird abgewiesen ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "coitester{$unique}",
            "inzuchtkoeffizient-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/inzuchtkoeffizient/rechner');
        $this->assertSame(
            403,
            $deniedResponse->statusCode,
            'Ohne inzuchtkoeffizient.calculate sollte die Plugin-Route 403 liefern.'
        );
        // ... und ist nach Zuweisung der Berechtigung erreichbar.
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'inzuchtkoeffizient' => ['calculate'],
        ]);

        $allowedResponse = $editor->get("/plugin/inzuchtkoeffizient/rechner?sire_id={$cId}&dam_id={$dId}");
        $this->assertSame(200, $allowedResponse->statusCode);
        $this->assertStringContainsString('25,00 %', $allowedResponse->body);

        // Die Vorschläge holt seit #125 der Kern-Endpunkt, der `horses.view`
        // verlangt - nicht mehr diese Route. Wer den Rechner nutzen darf,
        // braucht dafür also zusätzlich das Leserecht auf Pferde; das steht so
        // im README. Geprüft wird hier nur, dass das Addon keine eigene
        // Suchroute mehr danebenstellt.
        $this->assertEigeneSuchrouteEntfallen($editor, '/plugin/inzuchtkoeffizient/suche');
    }
}
