<?php
// tests/Functional/FarbvererbungPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/farbvererbung gegen eine echte, per `php -S`
 * gestartete Hengstverzeichnis_Framework-Instanz (siehe tests/bootstrap.php und
 * Tests\Functional\FunctionalTestCase).
 *
 * Deckt ab: Aktivierung über /admin/plugins, die genetische Einordnung der
 * eingetragenen Farbe auf der öffentlichen Detailseite (horse.detail_sections),
 * den Farbrechner mit einem eindeutigen Kreuzungsfall (Rotfalbe × Rotfalbe →
 * 100 % Rotfalbe, da ee × ee immer ee ergibt) sowie die Durchsetzung der selbst
 * registrierten Berechtigung farbvererbung.calculate; seit #74 außerdem das
 * gedeckelte Nachschlage-Element "Farben im Register" und seit #125 die
 * Farbübernahme aus dem Register über die GEMEINSAME Pferdesuche des Kerns
 * (die addoneigene /suche-Route ist entfallen).
 *
 * Die Rechen-MATRIX (Agouti-/Cream-Locus, Heterozygotie-Annahme, Summe = 1)
 * prüft tests/Unit/FarbvererbungGenetikTest.php ohne HTTP (#76) - hier bleibt
 * bewusst nur der eine eindeutige Durchstich durch den ganzen Stack.
 */
class FarbvererbungPluginTest extends FunctionalTestCase {

    use HorseListHelper;
    use PferdesucheHelper;

    private const SLUG = 'farbvererbung';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString(
            'Fjord-Farbvererbungsrechner',
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

        // 1. Detailseite: ein Pferd mit erkennbarer Farbe zeigt die genetische
        // Einordnung der Falbfarbe.
        $horseId = $this->createHorse($admin, "Farbe-{$unique}", [
            'status' => 'active',
            'color' => 'Rotfalbe',
        ]);
        $visitor = $this->newClient();
        $detailPage = $visitor->get("/horse?id={$horseId}");
        $this->assertSame(200, $detailPage->statusCode);
        $this->assertStringContainsString(
            'Rotfalbe (Rødblakk)',
            $detailPage->body,
            "Detailseite sollte die erkannte Falbfarbe anzeigen. Body: {$detailPage->body}"
        );

        // 1b. Generische Grundfarben lösen KEINE Falb-Einordnung mehr aus (#50):
        // ein schlicht braunes Pferd ist ohne Dun-Gen kein Braunfalbe. Vor dem
        // Fix ordnete das Addon jedem "Braun" die Fjord-Farbe Brunblakk zu.
        $plainBrownId = $this->createHorse($admin, "Braunes-{$unique}", [
            'status' => 'active',
            'color' => 'Braun',
        ]);
        $plainPage = $visitor->get("/horse?id={$plainBrownId}");
        $this->assertSame(200, $plainPage->statusCode);
        $this->assertStringNotContainsString(
            'Falbfarbe',
            $plainPage->body,
            "Ein braunes Pferd ohne Falb-Hinweis darf keine Fjord-Falbfarben-Box erhalten. Body: {$plainPage->body}"
        );

        // 1c. Rasse-Gate (#50, seit Framework #163): Bei explizit ANDERER Rasse
        // gibt es trotz Falb-Farbtext keine Fjord-Box; bei bestätigter Rasse
        // Fjordpferd ist die Aussage nicht mehr als bloße "Deutung" formuliert.
        $trakId = $this->createHorse($admin, "RotfalbeTrak-{$unique}", [
            'status' => 'active',
            'color' => 'Rotfalbe',
            'breed' => 'Trakehner',
        ]);
        $trakPage = $visitor->get("/horse?id={$trakId}");
        $this->assertStringNotContainsString(
            'Falbfarbe',
            $trakPage->body,
            'Bei Rasse Trakehner darf keine Fjord-Falbfarben-Box erscheinen.'
        );

        $fjordId = $this->createHorse($admin, "RotfalbeFjord-{$unique}", [
            'status' => 'active',
            'color' => 'Rotfalbe',
            'breed' => 'Norwegisches Fjordpferd',
        ]);
        $fjordPage = $visitor->get("/horse?id={$fjordId}");
        $this->assertStringContainsString('Falbfarbe:</strong>', $fjordPage->body, 'Bei bestätigter Fjord-Rasse erscheint die Box.');
        $this->assertStringNotContainsString('Fjord-Deutung', $fjordPage->body, 'Bei bestätigter Rasse ist die Einordnung keine bloße Deutung mehr.');

        // Ohne Rassenangabe (Pferd aus Schritt 1) bleibt die vorsichtige Deutung.
        $this->assertStringContainsString('Fjord-Deutung', $detailPage->body);

        // 2. Farbrechner: Rotfalbe × Rotfalbe ergibt exakt 100 % Rotfalbe.
        $calcResponse = $admin->get('/plugin/farbvererbung/rechner?sire_color=rodblakk&dam_color=rodblakk');
        $this->assertSame(200, $calcResponse->statusCode);
        $this->assertStringContainsString('100,00 %', $calcResponse->body);
        $this->assertStringContainsString('Rotfalbe (Rødblakk)', $calcResponse->body);

        // 2b. Nachschlage-Element "Farben im Register" (#74): statt des
        // früheren Komplettbestands eine gedeckelte Tabelle (nur Pferde MIT
        // eingetragener Farbe).
        $noColorId = $this->createHorse($admin, "Farblos-{$unique}", ['status' => 'active']);
        $registerPage = $admin->get('/plugin/farbvererbung/rechner');
        $this->assertSame(200, $registerPage->statusCode);
        $this->assertStringContainsString("Farbe-{$unique}", $registerPage->body, 'Pferd mit eingetragener Farbe gehört in die Nachschlage-Tabelle.');
        $this->assertStringNotContainsString("Farblos-{$unique}", $registerPage->body,
            'Ein Pferd OHNE eingetragene Farbe trägt zur Farb-Nachschlage-Tabelle nichts bei (#74).');

        // 2c. Register-Übernahme (#125): Je Elternteil steht das gemeinsame
        // Suchfeld des Kerns bereit, eingeschränkt auf Pferde MIT Farbe. Die
        // addoneigene /suche-Route ist entfallen - sie war eine von sieben
        // Kopien derselben Pferdesuche.
        $this->assertGemeinsamePferdesuche($registerPage->body, 'sire_horse', ['data-nur-mit-farbe' => '1']);
        $this->assertGemeinsamePferdesuche($registerPage->body, 'dam_horse', ['data-nur-mit-farbe' => '1']);
        $this->assertEigeneSuchrouteEntfallen($admin, '/plugin/farbvererbung/suche');

        // 2d. Und die Übernahme rechnet auch: Das frühere Feld ZEIGTE die
        // Farbe nur an, man musste sie danach von Hand im Auswahlfeld
        // wiederfinden. Jetzt setzt das gewählte Pferd sie selbst.
        $uebernahme = $admin->get("/plugin/farbvererbung/rechner?sire_horse={$horseId}&dam_horse={$horseId}");
        $this->assertSame(200, $uebernahme->statusCode);
        $this->assertStringContainsString('100,00 %', $uebernahme->body,
            'Rotfalbe x Rotfalbe ergibt 100 % Rotfalbe - die Farbe muss also aus dem gewählten Pferd stammen.');
        $this->assertMatchesRegularExpression(
            '/<option value="rodblakk" selected>/',
            $uebernahme->body,
            'Die übernommene Farbe muss im Auswahlfeld sichtbar sein, nicht nur im Ergebnis.'
        );
        $this->assertMatchesRegularExpression(
            '/<option value="' . $horseId . '" selected>[^<]*Farbe-' . preg_quote($unique, '/') . '/',
            $uebernahme->body,
            'Das gewählte Pferd muss als vorgetragene Option stehenbleiben.'
        );

        // 2e. Eine ausdrücklich gewählte Farbe schlägt die Übernahme - sonst
        // überschriebe ein nur zum Nachschlagen gewähltes Pferd still die
        // eigene Eingabe.
        $vorrang = $admin->get("/plugin/farbvererbung/rechner?sire_color=gulblakk&sire_horse={$horseId}&dam_color=gulblakk");
        $this->assertStringContainsString('nicht übernommen', $vorrang->body,
            'Der Vorrang der ausdrücklichen Farbe muss benannt werden, nicht stillschweigend gelten.');
        $this->assertMatchesRegularExpression('/<option value="gulblakk" selected>/', $vorrang->body);

        // 2f. Die generische Grundfarbe aus 1b lässt sich keiner Falbfarbe
        // zuordnen (gleiche keyFromText()-Regel wie auf der Detailseite, #50) -
        // die Übernahme sagt das und ändert nichts.
        $unzuordenbar = $admin->get("/plugin/farbvererbung/rechner?sire_horse={$plainBrownId}");
        $this->assertStringContainsString('keiner der fünf Falbfarben zuordnen', $unzuordenbar->body);
        $this->assertStringNotContainsString('Voraussichtliche Fohlenfarbe', $unzuordenbar->body,
            'Ohne zuordenbare Farbe darf kein Ergebnis erscheinen.');

        // 3. Berechtigungsdurchsetzung: Editor ohne farbvererbung.calculate -> 403 ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "farbtester{$unique}",
            "farbvererbung-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/farbvererbung/rechner');
        $this->assertSame(
            403,
            $deniedResponse->statusCode,
            'Ohne farbvererbung.calculate sollte die Plugin-Route 403 liefern.'
        );
        // Die Vorschläge holt seit #125 der Kern-Endpunkt (er verlangt
        // `horses.view`, siehe README) - dieses Addon stellt keine eigene
        // Suchroute mehr daneben.
        $this->assertEigeneSuchrouteEntfallen($editor, '/plugin/farbvererbung/suche');

        // ... und ist nach Zuweisung der Berechtigung erreichbar.
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'farbvererbung' => ['calculate'],
        ]);

        $allowedResponse = $editor->get('/plugin/farbvererbung/rechner?sire_color=rodblakk&dam_color=rodblakk');
        $this->assertSame(200, $allowedResponse->statusCode);
        $this->assertStringContainsString('100,00 %', $allowedResponse->body);
    }
}
