<?php
// tests/Functional/AnpaarungsEmpfehlungPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/anpaarungs-empfehlung gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 *
 * Aufbau: zwei unverwandte Großeltern (GpA/GpB), ein Basispferd und ein
 * Vollgeschwister daraus (Base bzw. AAsib aus GpA x GpB) sowie ein völlig
 * unverwandtes Pferd (ZZfree). Erwartung:
 *   - Base x ZZfree -> Fohlen-COI 0 %  (keine gemeinsamen Vorfahren)
 *   - Base x AAsib  -> Fohlen-COI 25 % (zwei gemeinsame Vorfahren, je n1=n2=1)
 * Das Ranking muss daher ZZfree (0 %) VOR AAsib (25 %) listen - obwohl AAsib
 * alphabetisch früher steht. Die Reihenfolge wird bewusst nur innerhalb der
 * Ergebnistabelle (<tbody>) geprüft, nicht über die gesamte Seite.
 *
 * Deckt zusätzlich ab: die Durchsetzung der Berechtigung anpaarung.recommend
 * (Empfehlungs- UND Suchroute), die serverseitige Basispferd-Suche (#69,
 * /suche als JSON-Datalist-Quelle statt Voll-<select>), den No-JS-Fallback
 * über base_q, die Tiefensemantik "Generationen je Elternteil = 6" (#72)
 * sowie den Kandidaten-Deckel vor der Berechnung (#69, limit x 5, max. 200)
 * samt Hinweistext.
 */
class AnpaarungsEmpfehlungPluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'anpaarungs-empfehlung';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString(
            'Anpaarungs-Empfehlung',
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
        $gpA = $this->createHorse($admin, "GpA-{$unique}", ['status' => 'active']);
        $gpB = $this->createHorse($admin, "GpB-{$unique}", ['status' => 'active']);
        $baseId = $this->createHorse($admin, "Base-{$unique}", ['status' => 'active', 'sire_id' => (string) $gpA, 'dam_id' => (string) $gpB]);
        // Vollgeschwister des Basispferds - alphabetisch bewusst FRÜH ("AA...").
        $sibId = $this->createHorse($admin, "AAsib-{$unique}", ['status' => 'active', 'sire_id' => (string) $gpA, 'dam_id' => (string) $gpB]);
        // Unverwandtes Pferd - alphabetisch bewusst SPÄT ("ZZ...").
        $freeId = $this->createHorse($admin, "ZZfree-{$unique}", ['status' => 'active']);

        $response = $admin->get("/plugin/anpaarungs-empfehlung/empfehlung?base_id={$baseId}");
        $this->assertSame(200, $response->statusCode);

        // Basispferd-Auswahl (#69): Suchfeld mit Datalist statt Voll-<select>
        // über den gesamten Bestand; die gewählte ID reist im Hidden-Feld.
        $this->assertStringContainsString('list="base_q_liste"', $response->body, 'Das Basispferd-Feld sollte eine Datalist referenzieren.');
        $this->assertStringContainsString('<datalist id="base_q_liste">', $response->body);
        $this->assertStringContainsString('name="base_id" id="base_id" value="' . $baseId . '"', $response->body, 'Die aufgelöste Basis-ID sollte im Hidden-Feld vorbelegt sein.');
        $this->assertStringNotContainsString('<select name="base_id"', $response->body, 'Der frühere Voll-<select> über alle Pferde darf nicht mehr gerendert werden.');

        // Tiefensemantik (#72): Standard sind 6 Generationen JE ELTERNTEIL -
        // identisch zum Verpaarungsrechner des Inzuchtkoeffizient-Addons.
        $this->assertStringContainsString('Generationen je Elternteil (1–8)', $response->body);
        $this->assertStringContainsString(
            'name="depth" id="depth" class="form-control" min="1" max="8" value="6"',
            $response->body,
            'Die Standard-Generationstiefe je Elternteil sollte 6 sein (#72).'
        );
        $this->assertStringContainsString('bis zu 6 Generationen je Elternteil tiefen Stammbaum', $response->body);

        $this->assertStringContainsString('0,00 %', $response->body, 'Unverwandte Verpaarung sollte 0,00 % zeigen.');
        $this->assertStringContainsString('25,00 %', $response->body, 'Vollgeschwister-Verpaarung sollte 25,00 % zeigen.');

        // Nur die Ergebnistabelle betrachten (das vorbelegte Suchfeld davor
        // würde die COI-Sortierung sonst verfälschen).
        $table = strstr($response->body, '<tbody>');
        $this->assertIsString($table, "Ergebnistabelle (<tbody>) nicht gefunden. Body: {$response->body}");

        $posFree = strpos($table, "ZZfree-{$unique}");
        $posSib = strpos($table, "AAsib-{$unique}");
        $this->assertNotFalse($posFree, 'Unverwandtes Pferd fehlt in der Empfehlungstabelle.');
        $this->assertNotFalse($posSib, 'Vollgeschwister-Pferd fehlt in der Empfehlungstabelle.');
        $this->assertLessThan(
            $posSib,
            $posFree,
            'Ranking muss die genetisch vielfältigste (COI 0 %) Verpaarung vor der Vollgeschwister-Verpaarung (25 %) listen.'
        );

        // Geschlechtsfilter (#52): Für eine STUTE als Basispferd erscheinen nur
        // Hengste und Pferde ohne Geschlechtsangabe als Partner - keine Stuten,
        // keine Wallache. Pferde ohne Angabe sind gekennzeichnet.
        $mareBase = $this->createHorse($admin, "GfStuteBasis-{$unique}", ['status' => 'active', 'sex' => 'mare']);
        $mareOther = $this->createHorse($admin, "GfStuteAndere-{$unique}", ['status' => 'active', 'sex' => 'mare']);
        $stallionPartner = $this->createHorse($admin, "GfHengst-{$unique}", ['status' => 'active', 'sex' => 'stallion']);
        $geldingId = $this->createHorse($admin, "GfWallach-{$unique}", ['status' => 'active', 'sex' => 'gelding']);
        $this->assertGreaterThan(0, $mareOther);
        $this->assertGreaterThan(0, $geldingId);

        $sexResponse = $admin->get("/plugin/anpaarungs-empfehlung/empfehlung?base_id={$mareBase}&limit=100");
        $sexTable = strstr($sexResponse->body, '<tbody>');
        $this->assertIsString($sexTable, "Ergebnistabelle für Stuten-Basis nicht gefunden. Body: {$sexResponse->body}");
        $this->assertStringContainsString("GfHengst-{$unique}", $sexTable, 'Hengst muss als Partner einer Stute vorgeschlagen werden.');
        $this->assertStringNotContainsString("GfStuteAndere-{$unique}", $sexTable, 'Stute × Stute darf nicht vorgeschlagen werden.');
        $this->assertStringNotContainsString("GfWallach-{$unique}", $sexTable, 'Wallache sind keine Zuchtpartner.');
        $this->assertStringContainsString('(Geschlecht unbekannt)', $sexTable, 'Pferde ohne Geschlechtsangabe bleiben gekennzeichnet in der Liste.');

        // Ein Wallach als Basispferd bekommt gar keine Empfehlung.
        $geldingResponse = $admin->get("/plugin/anpaarungs-empfehlung/empfehlung?base_id={$geldingId}");
        $this->assertStringContainsString('Wallach erfasst', $geldingResponse->body);
        $this->assertStringNotContainsString('<tbody>', $geldingResponse->body, 'Für einen Wallach darf kein Ranking berechnet werden.');

        // Serverseitige Basispferd-Suche (#69): JSON mit id + label, wie sie
        // das Datalist-Script der Seite konsumiert.
        $sucheResponse = $admin->get('/plugin/anpaarungs-empfehlung/suche?q=' . urlencode("AAsib-{$unique}"));
        $this->assertSame(200, $sucheResponse->statusCode);
        $suggestions = json_decode($sucheResponse->body, true);
        $this->assertIsArray($suggestions, "Suchroute sollte JSON liefern. Body: {$sucheResponse->body}");
        $this->assertCount(1, $suggestions, 'Der eindeutige Testname sollte genau einen Treffer liefern.');
        $this->assertSame($sibId, (int) $suggestions[0]['id']);
        $this->assertStringContainsString("AAsib-{$unique}", (string) $suggestions[0]['label']);

        // Leere Suche liefert eine leere Liste statt des Gesamtbestands.
        $emptySuche = $admin->get('/plugin/anpaarungs-empfehlung/suche?q=');
        $this->assertSame('[]', trim($emptySuche->body), 'Ohne Suchbegriff darf die Suchroute nichts ausliefern.');

        // No-JS-Fallback: Ohne base_id löst die Seite den getippten Text
        // (base_q) serverseitig auf, sofern er eindeutig ist.
        $fallbackResponse = $admin->get('/plugin/anpaarungs-empfehlung/empfehlung?base_q=' . urlencode("Base-{$unique}"));
        $this->assertSame(200, $fallbackResponse->statusCode);
        $this->assertStringContainsString("Empfehlungen für „Base-{$unique}", $fallbackResponse->body, 'Ein eindeutiger Name in base_q sollte serverseitig aufgelöst werden.');
        $this->assertStringContainsString('25,00 %', $fallbackResponse->body);

        // ... ein unauflösbarer Text erzeugt einen Hinweis statt eines Rankings.
        $unresolvedResponse = $admin->get('/plugin/anpaarungs-empfehlung/empfehlung?base_q=' . urlencode("GibtEsNicht-{$unique}"));
        $this->assertStringContainsString('kein eindeutiges Pferd gefunden', $unresolvedResponse->body);
        $this->assertStringNotContainsString('<tbody>', $unresolvedResponse->body, 'Ohne aufgelöstes Basispferd darf kein Ranking erscheinen.');

        // Kandidaten-Deckel (#69): limit=1 deckelt die berechnete Menge auf 5
        // Kandidaten. Für die Stuten-Basis existieren hier mindestens sechs
        // (GpA, GpB, Base, AAsib, ZZfree ohne Geschlecht plus GfHengst), der
        // Hinweis auf die Deckelung muss also erscheinen - und angezeigt wird
        // genau eine Zeile.
        $capResponse = $admin->get("/plugin/anpaarungs-empfehlung/empfehlung?base_id={$mareBase}&limit=1");
        $this->assertStringContainsString('alphabetisch ersten', $capResponse->body, 'Bei greifendem Kandidaten-Deckel muss die Seite darauf hinweisen.');
        $capTable = strstr($capResponse->body, '<tbody>');
        $this->assertIsString($capTable, "Ergebnistabelle für den Deckel-Fall nicht gefunden. Body: {$capResponse->body}");
        $capTbody = substr($capTable, 0, (int) strpos($capTable, '</tbody>'));
        $this->assertSame(1, substr_count($capTbody, '<tr'), 'limit=1 darf genau eine Ergebniszeile anzeigen.');

        // Das Basispferd selbst darf nicht als Partner-Vorschlag erscheinen.
        $this->assertStringNotContainsString(
            "Base-{$unique}",
            $table,
            'Das Basispferd darf nicht als eigener Partner-Vorschlag in der Tabelle stehen.'
        );

        // Berechtigungsdurchsetzung: Editor ohne anpaarung.recommend -> 403 ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "anptester{$unique}",
            "anpaarung-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/anpaarungs-empfehlung/empfehlung');
        $this->assertSame(
            403,
            $deniedResponse->statusCode,
            'Ohne anpaarung.recommend sollte die Plugin-Route 403 liefern.'
        );

        // ... die Suchroute gibt ohne die Berechtigung ebenfalls nichts preis
        // (sie liefert Namen auch unveröffentlichter Pferde).
        $deniedSuche = $editor->get('/plugin/anpaarungs-empfehlung/suche?q=a');
        $this->assertSame(
            403,
            $deniedSuche->statusCode,
            'Auch die Suchroute verlangt anpaarung.recommend.'
        );

        // ... und ist nach Zuweisung der Berechtigung erreichbar.
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'anpaarung' => ['recommend'],
        ]);

        $allowedResponse = $editor->get("/plugin/anpaarungs-empfehlung/empfehlung?base_id={$baseId}");
        $this->assertSame(200, $allowedResponse->statusCode);
        $this->assertStringContainsString('25,00 %', $allowedResponse->body);
    }
}
