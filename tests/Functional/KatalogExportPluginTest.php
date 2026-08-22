<?php
// tests/Functional/KatalogExportPluginTest.php

namespace Tests\Functional;

/**
 * End-to-End-Test für plugins/katalog-export gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 */
class KatalogExportPluginTest extends FunctionalTestCase {

    use HorseListHelper;

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
        // Kontakte direkt per DB angelegt (Präzedenz: GdprEraseTest im
        // Framework-Repo, VerkaufsboersePluginTest hier) - für den
        // Backoffice-Export ist keine Veröffentlichung nötig.
        //
        // Tabelle `contacts` seit Framework#336 (#137): Personen und
        // Deckstationen liegen gemeinsam darin, die Rolle steht in
        // horse_persons.role.
        $db = \App\Database::getInstance();
        $personStmt = $db->prepare('INSERT INTO contacts (name) VALUES (?)');
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
            // Feldname contact_id statt person_id seit Framework#336 - der
            // Kern nimmt den alten Namen zwar noch an, aber ein Test, der auf
            // dem Alias sitzt, prueft in v0.9.0 nichts mehr.
            'persons' => [
                ['contact_id' => (string) $personIds['Zuechter'], 'role' => 'breeder'],
                ['contact_id' => (string) $personIds['BesitzerB'], 'role' => 'owner', 'from_year' => '1998', 'until_year' => '2005'],
                ['contact_id' => (string) $personIds['BesitzerC'], 'role' => 'owner', 'from_year' => '2005', 'until_year' => '2015'],
                ['contact_id' => (string) $personIds['BesitzerD'], 'role' => 'owner', 'from_year' => '2015'],
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

    /**
     * #137: Der Export darf ab v0.8 keine Kontaktfelder hinaustragen, die
     * vorher nicht darin standen.
     *
     * Bis v0.7 zog dieser Export seine Stationsangabe aus `breeding_stations`
     * und seine Namen aus `persons` - zwei Tabellen, von denen eine gar keine
     * zustellbaren Felder hatte. Seit Framework#336 ist beides EINE Tabelle
     * `contacts`, und sie traegt zusaetzlich email, phone, mobile, street,
     * house_number, postal_code, address, contact_person und das interne
     * Freitextfeld contact_info.
     *
     * Der Umbau war deshalb NICHT nur ein Tabellenname: Wer beim Umstellen zu
     * `SELECT *` oder zu einer bequem erweiterten Spaltenliste greift,
     * verwandelt eine CSV-Datei mit Namen still in eine mit Anschriften und
     * Telefonnummern - und die wird per E-Mail weitergereicht und auf
     * Fremdrechnern abgelegt, wo keine Loeschfrist sie mehr erreicht.
     *
     * Der Test haelt beide Haelften fest:
     * - Der NAME des Kontakts steht im Export (sonst waere der Export kaputt).
     * - Kein einziges der zustellbaren Felder steht darin, weder ueber den
     *   Zuechter-/Besitzer-Weg (horse_persons.contact_id) noch ueber den
     *   Stations-Weg (horses.breeding_station_id).
     * - Die Zeile hat weiterhin genau 18 Spalten: Eine zusaetzliche
     *   Kontaktspalte fiele hier auf, auch wenn sie leer bliebe.
     *
     * Gegenprobe gelaufen: Nimmt man `bs.email` in Abfrage, Kopfzeile und
     * Datenzeile auf - der naheliegendste "waere doch praktisch"-Zusatz -,
     * schlaegt der Test an der Spaltenzahl fehl (19 statt 18).
     */
    public function testExportTraegtKeineKontaktfelderHinaus(): void {
        $admin = $this->authenticatedClient();
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $unique = uniqid();
        $kontaktName = "CsvKontakt-{$unique}";

        // Bewusst wiedererkennbare Werte in JEDEM zustellbaren Feld - nur so
        // laesst sich unten belegen, dass keines davon im Export gelandet ist.
        $geheim = [
            'email' => "geheim-{$unique}@example.test",
            'phone' => "0900-{$unique}",
            'mobile' => "0171-{$unique}",
            'street' => "Geheimstrasse-{$unique}",
            'house_number' => "77{$unique}",
            'postal_code' => "9{$unique}",
            'address' => "Freitextanschrift-{$unique}",
            'contact_person' => "Ansprechpartner-{$unique}",
            'contact_info' => "Interne Notiz-{$unique}",
        ];

        $db = \App\Database::getInstance();
        $spalten = implode(', ', array_keys($geheim));
        $platzhalter = implode(', ', array_fill(0, count($geheim), '?'));
        // contact_public = 1: der fuer den Datenschutz unguenstigste Fall.
        // Selbst mit Freigabe gehoeren diese Felder nicht in einen
        // CSV-Download - die Freigabe gilt der Anzeige auf der Kontaktseite,
        // nicht einer Datei, die das System verlaesst.
        $stmt = $db->prepare(
            "INSERT INTO contacts (name, {$spalten}, contact_public, is_published) VALUES (?, {$platzhalter}, 1, 1)"
        );
        $stmt->execute([$kontaktName, ...array_values($geheim)]);
        $kontaktId = (int) $db->lastInsertId();

        // Eine Zuordnungszeile besetzt beide Steckplaetze (#336): derselbe
        // Kontakt als Zuechter (contact_id) UND als Deckstation
        // (station_contact_id). Damit laufen beide JOIN-Wege der
        // Exportabfrage ueber genau diesen Datensatz.
        $horseName = "CsvKontaktPferd-{$unique}";
        $horseId = $this->createHorse($admin, $horseName, [
            'status' => 'active',
            'persons' => [[
                'contact_id' => (string) $kontaktId,
                'role' => 'breeder',
                'station_contact_id' => (string) $kontaktId,
            ]],
        ]);
        $this->assertGreaterThan(0, $horseId);

        $csv = $admin->get('/plugin/katalog-export/csv?q_name=' . urlencode($horseName));
        $this->assertSame(200, $csv->statusCode);

        $zeilen = array_values(array_filter(
            preg_split('/\r\n|\n/', $csv->body) ?: [],
            static fn (string $line): bool => trim($line) !== ''
        ));
        $this->assertCount(2, $zeilen, "Kopfzeile + genau eine Datenzeile erwartet. Body: {$csv->body}");

        $datenzeile = str_getcsv($zeilen[1], ';', '"', '');
        $this->assertCount(
            18,
            $datenzeile,
            'Der Export hat 18 Spalten. Eine zusaetzliche Spalte heisst: die '
            . 'Feldliste ist beim Umstellen auf `contacts` gewachsen (#137).'
        );

        // Der Name MUSS drin sein - sonst prueft der Rest nichts.
        $this->assertSame($kontaktName, $datenzeile[13], 'Deckstation-Spalte muss den Kontaktnamen tragen.');
        $this->assertSame($kontaktName, $datenzeile[16], 'Zuechter-Spalte muss den Kontaktnamen tragen.');

        foreach ($geheim as $feld => $wert) {
            $this->assertStringNotContainsString(
                $wert,
                $csv->body,
                "Das Kontaktfeld '{$feld}' darf nicht im CSV-Export stehen - es stand vor "
                . 'der Zusammenlegung von persons und breeding_stations (#336) auch nicht darin.'
            );
        }
    }

    /**
     * #137: Die Auswahlliste "Deckstation" im Exportformular darf nach der
     * Zusammenlegung nicht jeden Kontakt des Verzeichnisses anbieten.
     *
     * Bis v0.7 stand dort `SELECT DISTINCT name FROM breeding_stations` - eine
     * Tabelle, die ausschliesslich Deckstationen enthielt. Ein blosses
     * Ersetzen des Tabellennamens durch `contacts` haette daraus ein
     * Aufklappmenue mit saemtlichen Personen des Verzeichnisses gemacht:
     * unbrauchbar lang, und jeder Eintrag eine Behauptung ("das ist eine
     * Deckstation"), die fuer die meisten nicht stimmt.
     *
     * Deckstation ist seit #336 eine ROLLE. Angeboten wird deshalb, wer in
     * einem der beiden Steckplaetze steht, die auf eine Station zeigen.
     *
     * Gegenprobe gelaufen: Mit dem mechanisch umgestellten
     * `SELECT DISTINCT name FROM contacts` steht der Zuechter im Menue, und
     * der Test schlaegt fehl.
     */
    public function testStationsAuswahlZeigtNurKontakteInDerRolleDeckstation(): void {
        $admin = $this->authenticatedClient();
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $unique = uniqid();
        $db = \App\Database::getInstance();
        $insert = $db->prepare('INSERT INTO contacts (name, is_published) VALUES (?, 1)');

        $stationName = "CsvRolleStation-{$unique}";
        $insert->execute([$stationName]);
        $stationId = (int) $db->lastInsertId();

        // Ein Kontakt, der KEINE Deckstation ist: eine Privatperson, hier als
        // Zuechter zugeordnet. Genau dieser Name darf nicht im Menue stehen.
        $personName = "CsvRollePerson-{$unique}";
        $insert->execute([$personName]);
        $personId = (int) $db->lastInsertId();

        $this->createHorse($admin, "CsvRollePferd-{$unique}", [
            'status' => 'active',
            'persons' => [[
                'contact_id' => (string) $personId,
                'role' => 'breeder',
                'station_contact_id' => (string) $stationId,
            ]],
        ]);

        $form = $admin->get('/plugin/katalog-export/formular');
        $this->assertSame(200, $form->statusCode);

        preg_match('/<select name="q_station".*?<\/select>/s', $form->body, $treffer);
        $this->assertNotEmpty($treffer, 'Das Exportformular muss ein Auswahlfeld "q_station" enthalten.');
        $auswahl = $treffer[0];

        $this->assertStringContainsString(
            $stationName,
            $auswahl,
            'Ein Kontakt, der als Deckstation verknuepft ist, muss im Auswahlfeld stehen.'
        );
        $this->assertStringNotContainsString(
            $personName,
            $auswahl,
            'Ein Kontakt, der nur Zuechter ist, darf nicht als Deckstation angeboten werden - '
            . 'seit #336 stehen Personen und Stationen in derselben Tabelle, und ein blosses '
            . 'Ersetzen des Tabellennamens haette das Menue mit allen Personen gefuellt.'
        );
    }

    /**
     * #352: Ein Export traegt den halben Bestand samt Zuechter- und
     * Besitzernamen aus dem System heraus. docs/plugin-development.md des
     * Kerns nennt "exportieren" ausdruecklich unter dem, was ins Protokoll
     * gehoert - hinterher will jemand wissen, wann das geschah und wer es tat.
     *
     * Geprueft werden beide Haelften: dass der Eintrag entsteht und
     * aussagekraeftig ist, und dass die FILTERWERTE nicht darin stehen.
     * `q_breeder` nimmt einen Personennamen entgegen, und `audit_logs` wird
     * dauerhaft aufbewahrt und von keiner Loeschfrist erfasst.
     *
     * Gegenprobe gelaufen: Ohne den PluginAudit::log()-Aufruf in
     * ExportController::exportCsv() findet die Abfrage keinen Eintrag.
     */
    public function testExportStehtImProtokoll(): void {
        $admin = $this->authenticatedClient();
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $unique = uniqid();
        $suchbegriff = "ProtokollFilter-{$unique}";

        $csv = $admin->get('/plugin/katalog-export/csv?q_breeder=' . urlencode($suchbegriff));
        $this->assertSame(200, $csv->statusCode);

        $stmt = \App\Database::getInstance()->prepare(
            'SELECT username, details FROM audit_logs
             WHERE category = ? AND action = ? AND created_at >= (NOW() - INTERVAL 10 MINUTE)
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([self::SLUG, 'Katalog als CSV exportiert']);
        $eintrag = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($eintrag, 'Ein CSV-Download muss einen Protokolleintrag der Kategorie "katalog-export" erzeugen.');

        $details = (string) $eintrag['details'];
        $this->assertStringContainsString(
            'q_breeder',
            $details,
            'Der Eintrag muss nennen, WELCHE Filterfelder gesetzt waren - sonst sagt er nur, dass irgendetwas exportiert wurde.'
        );
        $this->assertMatchesRegularExpression(
            '/\d+ Datens/u',
            $details,
            'Ohne die Zeilenzahl laesst sich hinterher nicht abschaetzen, wie viel das Haus verlassen hat.'
        );
        $this->assertStringNotContainsString(
            $suchbegriff,
            $details,
            'Der eingegebene Filterwert gehoert nicht ins dauerhafte Protokoll - q_breeder nimmt einen Personennamen entgegen.'
        );
        // Der Name kommt aus der UMGEBUNG, nicht aus einem Literal.
        //
        // Hier stand 'e2eadmin' fest verdrahtet. Lokal hiess der Testadmin
        // zufaellig genauso, in der CI aber 'e2eaddonadmin' (ADMIN_USERNAME in
        // .github/workflows/tests.yml) - der Test war lokal gruen und in der
        // CI rot, und der Fehler sah aus wie ein Protokollierungsfehler des
        // Addons. Der Name der Instanz gehoert der Instanz; wer ihn im Test
        // wiederholt, prueft seine eigene Annahme statt des Verhaltens.
        $erwarteterAdmin = (string) (getenv('ADMIN_USERNAME') ?: '');
        $this->assertNotSame('', $erwarteterAdmin, 'ADMIN_USERNAME muss gesetzt sein - siehe tests/bootstrap.php.');
        $this->assertSame(
            $erwarteterAdmin,
            (string) $eintrag['username'],
            'Der Eintrag muss dem angemeldeten Konto zugeordnet sein - genau das ist der Zweck.'
        );
    }
    /**
     * Ein Platzhalter-Datum gehört nicht als Tatsache in eine CSV-Datei
     * (Framework#379, Addons#156).
     *
     * `birth_date_precision = 'year'` heißt: Nur das Jahr ist bekannt,
     * Monat und Tag sind Platzhalter — in dieser Branche der 1. Januar, im
     * Altbestand bei knapp der Hälfte aller Pferde. Der Kern zeigt in dem
     * Fall nur noch das Jahr; diese Datei wird per E-Mail weitergereicht und
     * in eine Tabellenkalkulation geladen, wo der Tag als Tatsache steht —
     * und zwar weiter weg von jeder Korrektur als die Seite, von der er
     * stammt.
     *
     * Die Zelle bleibt LEER statt das Jahr zu wiederholen: Genau das ist die
     * Form, die der CSV-Import des Kerns als „nur das Jahr bekannt" annimmt.
     */
    public function testEinPlatzhalterDatumErscheintNichtImExport(): void {
        $admin = $this->authenticatedClient();
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $unique = uniqid();

        $genau = "CsvGenau-{$unique}";
        $platzhalter = "CsvPlatzhalter-{$unique}";

        $this->createHorse($admin, $genau, [
            'birth_date' => '1976-06-13',
            'is_published' => '1',
        ]);
        $mitPlatzhalter = $this->createHorse($admin, $platzhalter, [
            'birth_date' => '1976-01-01',
            'birth_date_precision' => 'year',
            'is_published' => '1',
        ]);

        // Vorbedingung: Der Kern hat die Genauigkeit wirklich gespeichert -
        // sonst prüfte der Rest an einem tagesgenauen Datensatz vorbei.
        $stmt = \App\Database::getInstance()->prepare(
            'SELECT birth_date, birth_date_precision FROM horses WHERE id = ?'
        );
        $stmt->execute([$mitPlatzhalter]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('year', (string)$zeile['birth_date_precision']);
        $this->assertSame('1976-01-01', (string)$zeile['birth_date'],
            'Das Quelldatum bleibt in der Datenbank - nur der Export zeigt es nicht.');

        $csv = $admin->get('/plugin/katalog-export/csv');
        $this->assertSame(200, $csv->statusCode);

        $zeilen = [];
        foreach (preg_split('/\r\n|\n/', $csv->body) as $line) {
            foreach ([$genau, $platzhalter] as $name) {
                if (str_contains($line, $name)) {
                    $zeilen[$name] = str_getcsv($line, ';', '"', '');
                }
            }
        }
        $this->assertArrayHasKey($genau, $zeilen);
        $this->assertArrayHasKey($platzhalter, $zeilen);

        // Spalte 4 = Geburtsjahr, Spalte 5 = Geburtsdatum (siehe Kopfzeile).
        $this->assertSame('1976-06-13', $zeilen[$genau][5],
            'Ein tagesgenaues Datum muss weiterhin exportiert werden.');
        $this->assertSame('1976', $zeilen[$platzhalter][4],
            'Das Jahr ist die Angabe, die stimmt - es bleibt stehen.');
        $this->assertSame('', $zeilen[$platzhalter][5],
            'Ein Platzhalter-Datum darf nicht als Tatsache in der Datei stehen.');

        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '0',
        ]);
    }

}
