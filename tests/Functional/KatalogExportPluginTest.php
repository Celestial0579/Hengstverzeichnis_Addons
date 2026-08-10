<?php
// tests/Functional/KatalogExportPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/katalog-export gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 */
class KatalogExportPluginTest extends FunctionalTestCase {

    private const SLUG = 'katalog-export';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Katalog-Export', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggleResponse->location());

        // 1. Dashboard-Kachel muss erscheinen.
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString('/plugin/katalog-export/formular', $dashboard->body);

        // 2. Formularseite lädt.
        $formPage = $admin->get('/plugin/katalog-export/formular');
        $this->assertSame(200, $formPage->statusCode);
        $this->assertStringContainsString('Als CSV herunterladen', $formPage->body);

        // 3. Testpferd anlegen, um im Export sicher wiederzufinden.
        $unique = uniqid();
        $horseName = "CsvTestPferd-{$unique}";
        $createForm = $admin->get('/admin/horses/create');
        $createResponse = $admin->post('/admin/horses/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => $horseName,
            'status' => 'active',
            'color' => 'Fuchs',
            'sex' => 'mare',
            'breed' => 'Fjordpferd',
            'birth_year' => '2021',
        ]);
        $this->assertSame('/admin/horses?success=created', $createResponse->location());

        // 4. Ungefilterter CSV-Export enthält das Testpferd.
        $csvResponse = $admin->get('/plugin/katalog-export/csv');
        $this->assertSame(200, $csvResponse->statusCode);
        $this->assertStringContainsString('text/csv', (string) $csvResponse->header('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $csvResponse->header('Content-Disposition'));
        // Dateiname "verzeichnis-*" statt "hengstkatalog-*" (#57): der Export
        // enthält alle Pferde, nicht nur Hengste.
        $this->assertStringContainsString('filename="verzeichnis-', (string) $csvResponse->header('Content-Disposition'));
        $this->assertStringNotContainsString('hengstkatalog', (string) $csvResponse->header('Content-Disposition'));
        $this->assertStringContainsString($horseName, $csvResponse->body);
        $this->assertStringContainsString('Fuchs', $csvResponse->body);

        // 5. Gefilterter Export (q_name auf ein garantiert nicht existierendes
        // Pferd) enthält das Testpferd NICHT mehr.
        $filteredResponse = $admin->get('/plugin/katalog-export/csv?q_name=' . urlencode("KeinTreffer-{$unique}"));
        $this->assertSame(200, $filteredResponse->statusCode);
        $this->assertStringNotContainsString($horseName, $filteredResponse->body);

        // 5b. Status-Split (Framework #188): verstorbenes, zuchtinaktives Pferd
        // anlegen - die CSV bekommt Verstorben/Todesjahr-Spalten, q_deceased
        // filtert den Lebensstatus, q_status den Zuchtstatus.
        $deceasedName = "CsvVerstorben-{$unique}";
        $createResponse = $admin->post('/admin/horses/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => $deceasedName,
            'status' => 'inactive',
            'birth_year' => '1994',
            'death_year' => '2018',
        ]);
        $this->assertSame('/admin/horses?success=created', $createResponse->location());

        $csvAll = $admin->get('/plugin/katalog-export/csv');
        $this->assertStringContainsString('Verstorben;Todesjahr', $csvAll->body, 'CSV-Kopfzeile muss die neuen Lebensstatus-Spalten enthalten');
        $this->assertStringContainsString('Geschlecht;Rasse', $csvAll->body, 'CSV-Kopfzeile muss Geschlecht/Rasse enthalten (#172-Felder)');
        $this->assertStringContainsString("{$deceasedName};;;1994;;;;;;inactive;ja;2018", $csvAll->body, "Verstorbenen-Zeile unvollständig, Body: {$csvAll->body}");
        $this->assertStringContainsString(";Fuchs;mare;Fjordpferd;", $csvAll->body, 'Geschlecht/Rasse müssen als Spaltenwerte exportiert werden');

        // Geschlechts-/Rasse-Filter wie auf der Katalogseite.
        $csvSexed = $admin->get('/plugin/katalog-export/csv?q_sex=mare');
        $this->assertStringContainsString($horseName, $csvSexed->body);
        $this->assertStringNotContainsString($deceasedName, $csvSexed->body, 'q_sex=mare darf Pferde ohne Geschlechtsangabe nicht enthalten');
        $csvBreedFiltered = $admin->get('/plugin/katalog-export/csv?q_breed=Fjord');
        $this->assertStringContainsString($horseName, $csvBreedFiltered->body);
        $this->assertStringNotContainsString($deceasedName, $csvBreedFiltered->body);

        $csvDeceased = $admin->get('/plugin/katalog-export/csv?q_deceased=1');
        $this->assertStringContainsString($deceasedName, $csvDeceased->body);
        $this->assertStringNotContainsString($horseName, $csvDeceased->body, 'q_deceased=1 darf lebende Pferde nicht enthalten');

        $csvLiving = $admin->get('/plugin/katalog-export/csv?q_deceased=0');
        $this->assertStringContainsString($horseName, $csvLiving->body);
        $this->assertStringNotContainsString($deceasedName, $csvLiving->body);

        $csvInactive = $admin->get('/plugin/katalog-export/csv?q_status=inactive');
        $this->assertStringContainsString($deceasedName, $csvInactive->body);
        $this->assertStringNotContainsString($horseName, $csvInactive->body, 'q_status=inactive darf aktive Pferde nicht enthalten');

        // Alt-Wert q_status=deceased (kopierte Filter-URLs von vor dem Split)
        // mappt auf den Lebensstatus statt leer zu laufen.
        $csvLegacy = $admin->get('/plugin/katalog-export/csv?q_status=deceased');
        $this->assertStringContainsString($deceasedName, $csvLegacy->body, 'q_status=deceased muss wie q_deceased=1 wirken');
        $this->assertStringNotContainsString($horseName, $csvLegacy->body);

        // 6. Berechtigungsdurchsetzung: Editor ohne katalog-export.export wird abgewiesen ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "csvtester{$unique}",
            "katalog-export-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/katalog-export/csv');
        $this->assertSame(403, $deniedResponse->statusCode);

        // ... und ist nach Zuweisung der Berechtigung erreichbar.
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'katalog-export' => ['export'],
        ]);

        $allowedResponse = $editor->get('/plugin/katalog-export/csv');
        $this->assertSame(200, $allowedResponse->statusCode);
        $this->assertStringContainsString($horseName, $allowedResponse->body);

        // 7. Sicherheit: CSV-Formel-Injection wird neutralisiert. Ein Name, der mit
        // '=' beginnt (in Excel/LibreOffice sonst als Formel interpretiert), erscheint
        // im Export mit vorangestelltem Hochkomma als reiner Text.
        $formulaName = "=CsvInject-{$unique}";
        $createFormInj = $admin->get('/admin/horses/create');
        $createResponseInj = $admin->post('/admin/horses/store', [
            'csrf_token' => $createFormInj->formField('csrf_token') ?? '',
            'name' => $formulaName,
            'status' => 'active',
        ]);
        $this->assertSame('/admin/horses?success=created', $createResponseInj->location());

        $csvAfter = $admin->get('/plugin/katalog-export/csv');
        $this->assertStringContainsString(
            "'" . $formulaName,
            $csvAfter->body,
            'Zellwert mit führendem "=" muss im Export mit Hochkomma entschärft sein.'
        );
        $this->assertStringNotContainsString(
            ';' . $formulaName,
            $csvAfter->body,
            'Der rohe, mit "=" beginnende Wert darf nicht unentschärft direkt nach dem ;-Trenner stehen.'
        );

        // 8. Sicherheit: Ausbruch aus dem Feld. csvSafe() prüft nur das ERSTE Zeichen
        // eines Wertes - ein Name, der mittendrin \" enthält, beginnt harmlos und
        // kommt daran vorbei. Schreibt fputcsv() mit PHPs Vorgabe-$escape "\\", wird
        // das " nicht verdoppelt, ein RFC-4180-Parser beendet das Feld dort, und der
        // Rest des Namens wird zu EIGENEN Feldern - eines davon mit "=" beginnend,
        // also eine Formel, die csvSafe() nie zu sehen bekam.
        //
        // Deshalb wird hier nicht im Rohtext gesucht, sondern so geparst, wie Excel
        // und LibreOffice es tun (str_getcsv mit leerem $escape = strikt RFC 4180).
        $breakoutName = "CsvBreak-{$unique}" . '\\";=CsvInjectBreak-' . $unique;
        $createFormBreak = $admin->get('/admin/horses/create');
        $createResponseBreak = $admin->post('/admin/horses/store', [
            'csrf_token' => $createFormBreak->formField('csrf_token') ?? '',
            'name' => $breakoutName,
            'status' => 'active',
        ]);
        $this->assertSame('/admin/horses?success=created', $createResponseBreak->location());

        $csvBreak = $admin->get('/plugin/katalog-export/csv');
        $this->assertSame(200, $csvBreak->statusCode);

        $row = null;
        foreach (preg_split('/\r\n|\n/', $csvBreak->body) as $line) {
            if (str_contains($line, "CsvBreak-{$unique}")) {
                $row = str_getcsv($line, ';', '"', '');
                break;
            }
        }
        $this->assertNotNull($row, 'Das Testpferd muss im Export auftauchen.');

        $this->assertCount(
            18,
            $row,
            'Der Export hat 18 Spalten (seit #188: + Geburtsdatum, Stockmaß, '
            . 'Verstorben, Todesjahr; danach + Geschlecht, Rasse). Mehr Felder heißt: '
            . 'ein Zellwert ist aus seinem '
            . 'Feld ausgebrochen - dann stimmt die Spaltenzuordnung nicht mehr und '
            . 'csvSafe() greift für die entstandenen Felder nicht.'
        );

        foreach ($row as $index => $field) {
            $this->assertNotContains(
                substr((string) $field, 0, 1),
                ['=', '+', '-', '@', "\t", "\r"],
                sprintf(
                    'Feld %d beginnt mit einem Formelzeichen (%s) - Excel/LibreOffice '
                    . 'würden es beim Öffnen auswerten.',
                    $index,
                    var_export($field, true)
                )
            );
        }

        $this->assertContains(
            $breakoutName,
            $row,
            'Der Name muss vollständig in genau einem Feld stehen.'
        );

        // 9. Aggregation statt Zeilen-Vervielfachung (#70): Ein Pferd mit
        // 1 Züchter und 3 Besitzerzeilen (Besitzerhistorie über
        // from_year/until_year) erzeugt genau EINE Datenzeile; die Namen
        // stehen kommasepariert in ihrer jeweiligen Spalte. Vorher
        // multiplizierten die horse_persons-JOINs die Zeilen (3 Besitzer ->
        // 3 CSV-Zeilen), was SELECT DISTINCT nicht kompensieren konnte, weil
        // sich die Zeilen in der Besitzer-Spalte unterschieden.
        //
        // Personen direkt per DB angelegt (Präzedenz: GdprEraseTest im
        // Framework-Repo, VerkaufsboersePluginTest hier) - für den
        // Backoffice-Export ist keine Veröffentlichung nötig.
        $db = \App\Database::getInstance();
        $personStmt = $db->prepare('INSERT INTO persons (name) VALUES (?)');
        $personIds = [];
        foreach (['Zuechter', 'BesitzerB', 'BesitzerC', 'BesitzerD'] as $suffix) {
            $personStmt->execute(["CsvPerson{$suffix}-{$unique}"]);
            $personIds[$suffix] = (int) $db->lastInsertId();
        }

        $multiOwnerName = "CsvMehrbesitzer-{$unique}";
        $createFormMulti = $admin->get('/admin/horses/create');
        $createResponseMulti = $admin->post('/admin/horses/store', [
            'csrf_token' => $createFormMulti->formField('csrf_token') ?? '',
            'name' => $multiOwnerName,
            'status' => 'active',
            'persons' => [
                ['person_id' => (string) $personIds['Zuechter'], 'role' => 'breeder'],
                ['person_id' => (string) $personIds['BesitzerB'], 'role' => 'owner', 'from_year' => '1998', 'until_year' => '2005'],
                ['person_id' => (string) $personIds['BesitzerC'], 'role' => 'owner', 'from_year' => '2005', 'until_year' => '2015'],
                ['person_id' => (string) $personIds['BesitzerD'], 'role' => 'owner', 'from_year' => '2015'],
            ],
        ]);
        $this->assertSame('/admin/horses?success=created', $createResponseMulti->location());

        $csvMulti = $admin->get('/plugin/katalog-export/csv?q_name=' . urlencode($multiOwnerName));
        $this->assertSame(200, $csvMulti->statusCode);

        $csvLines = array_values(array_filter(
            preg_split('/\r\n|\n/', $csvMulti->body) ?: [],
            static fn (string $line): bool => trim($line) !== ''
        ));
        $this->assertCount(
            2,
            $csvLines,
            'Kopfzeile + genau EINE Datenzeile erwartet. Mehr Zeilen heißt: die '
            . 'horse_persons-JOINs vervielfachen den Export wieder je '
            . "Züchter x Besitzer (#70). Body: {$csvMulti->body}"
        );

        $dataRow = str_getcsv($csvLines[1], ';', '"', '');
        $this->assertCount(18, $dataRow);
        $this->assertSame($multiOwnerName, $dataRow[1]);
        $this->assertSame(
            "CsvPersonZuechter-{$unique}",
            $dataRow[16],
            'Züchter-Spalte muss den (einzigen) Züchter enthalten.'
        );

        // GROUP_CONCAT garantiert ohne ORDER BY keine Reihenfolge (der Kern
        // verzichtet in $personAggregateJoin ebenfalls darauf) - deshalb
        // sortiert vergleichen statt auf eine feste Reihenfolge zu bestehen.
        $owners = array_map('trim', explode(',', (string) $dataRow[17]));
        sort($owners);
        $expectedOwners = [
            "CsvPersonBesitzerB-{$unique}",
            "CsvPersonBesitzerC-{$unique}",
            "CsvPersonBesitzerD-{$unique}",
        ];
        $this->assertSame(
            $expectedOwners,
            $owners,
            'Alle drei Besitzer der Historie müssen kommasepariert in EINER Zelle stehen.'
        );

        // Die EXISTS-Filter finden das Pferd über jeden historischen Besitzer -
        // und liefern es auch dann nur EINMAL.
        $csvOwnerFiltered = $admin->get('/plugin/katalog-export/csv?q_owner=' . urlencode("CsvPersonBesitzerB-{$unique}"));
        $this->assertSame(200, $csvOwnerFiltered->statusCode);
        $this->assertSame(
            1,
            substr_count($csvOwnerFiltered->body, $multiOwnerName),
            'q_owner muss das Pferd genau einmal liefern - weder 0x (Filter kaputt) noch mehrfach (JOIN multipliziert).'
        );

        $csvBreederFiltered = $admin->get('/plugin/katalog-export/csv?q_breeder=' . urlencode("CsvPersonZuechter-{$unique}"));
        $this->assertStringContainsString($multiOwnerName, $csvBreederFiltered->body);

        // Rollenschärfe: Besitzer B ist kein Züchter, der q_breeder-EXISTS
        // darf ihn nicht als Züchter-Treffer werten.
        $csvRoleMiss = $admin->get('/plugin/katalog-export/csv?q_breeder=' . urlencode("CsvPersonBesitzerB-{$unique}"));
        $this->assertStringNotContainsString($multiOwnerName, $csvRoleMiss->body);

        // Allgemeine Suche trifft Personennamen rollenunabhängig (EXISTS).
        $csvSearch = $admin->get('/plugin/katalog-export/csv?search=' . urlencode("CsvPersonBesitzerC-{$unique}"));
        $this->assertStringContainsString($multiOwnerName, $csvSearch->body);
    }
}
