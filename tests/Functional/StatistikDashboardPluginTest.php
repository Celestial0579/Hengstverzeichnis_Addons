<?php
// tests/Functional/StatistikDashboardPluginTest.php

namespace Tests\Functional;

use App\Database;
use PDO;
use Tests\Support\HttpClient;

/**
 * End-to-End-Test für plugins/statistik-dashboard gegen eine echte, per
 * `php -S` gestartete Hengstverzeichnis_Framework-Instanz (siehe
 * tests/bootstrap.php und Tests\Functional\FunctionalTestCase).
 *
 * Deckt seit der Zusammenführung mit `besucherstatistik` (Addons#127) beide
 * Abschnitte der Seite ab - "Bestand" und "Aufrufe" - sowie die einmalige
 * Übernahme der Bestandsdaten des aufgegangenen Addons. Dieser Test ist
 * damit zugleich die Vorlage für neue Addon-Tests (siehe README): er spielt
 * Filter, Action, eigene Route, eigene Tabelle und eigene Berechtigung durch.
 *
 * Da die zugrunde liegende Testdatenbank über den gesamten PHPUnit-Prozess
 * (und damit auch andere Testklassen) hinweg geteilt wird, werden absolute
 * Gesamtzahlen bewusst NICHT geprüft - stattdessen eindeutige, per uniqid()
 * generierte Deckstations-/Vaternamen, die garantiert nur von diesem Test
 * stammen.
 *
 * Alles in einer Testmethode, da die Schritte zwingend aufeinander aufbauen
 * (PHPUnit garantiert keine Ausführungsreihenfolge mehrerer Testmethoden).
 */
class StatistikDashboardPluginTest extends FunctionalTestCase {

    use HorseListHelper;
    // Für Schritt 2c: eine Deckstation als verknüpfter Kontakt (Addons#139).
    use PersonStationHelper;

    private const SLUG = 'statistik-dashboard';
    private const TABELLE_AUFRUFE = 'plugin_statistik_dashboard_views';
    private const TABELLE_META = 'plugin_statistik_dashboard_meta';
    private const TABELLE_ALT = 'plugin_besucherstatistik_views';
    private const MARKER = 'uebernahme.besucherstatistik';

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString(
            self::SLUG,
            $pluginsPage->body,
            'Plugin sollte unter /admin/plugins als entdeckt gelistet sein - wurde es nach vendor/hengstverzeichnis/framework/plugins kopiert?'
        );
        $this->assertStringNotContainsString(
            'besucherstatistik',
            $pluginsPage->body,
            'Das Addon `besucherstatistik` ist seit #127 in statistik-dashboard aufgegangen. Taucht es hier auf, '
            . 'liegt vermutlich noch ein Rest in vendor/hengstverzeichnis/framework/plugins/ - tests/bootstrap.php '
            . 'kopiert plugins/ nur hinein und räumt Entferntes nicht weg (Verzeichnis löschen und erneut laufen lassen).'
        );

        $this->setzeAktivierung($admin, true);

        // 1. Genau EINE Dashboard-Kachel - vor #127 waren es zwei, je eine
        // für Bestand und Aufrufe.
        $dashboard = $admin->get('/admin');
        $this->assertSame(
            1,
            substr_count($dashboard->body, '/plugin/statistik-dashboard/statistik'),
            'Nach der Zusammenführung (#127) darf es nur noch eine Statistik-Kachel geben.'
        );

        $unique = uniqid();
        $station = "StatTestStation-{$unique}";

        // Die Deckstation eines Pferds wird von HorseController::saveHorsePersons()
        // aus der aktuellsten Rollen-Zuordnung in "persons[]" synchronisiert und
        // überschreibt ein direkt übergebenes "breeding_station"-Feld sofort wieder
        // (siehe HorseController::store() im Framework-Repo) - daher hier über eine
        // Personen-Rolle mit Freitext-Deckstation gesetzt, nicht direkt.
        $stationAssignment = ['persons' => [['role' => 'owner', 'breeding_station_text' => $station]]];

        $sireId = $this->createHorse($admin, "Vater-{$unique}", ['status' => 'active']);
        $this->createHorse($admin, "Sohn1-{$unique}", ['status' => 'active', 'sire_id' => (string) $sireId] + $stationAssignment);
        $this->createHorse($admin, "Sohn2-{$unique}", ['status' => 'active', 'sire_id' => (string) $sireId] + $stationAssignment);

        // 2. Abschnitt "Bestand": die neue Deckstation mit Anzahl 2 ...
        $statsPage = $admin->get('/plugin/statistik-dashboard/statistik');
        $this->assertSame(200, $statsPage->statusCode);
        $this->assertStringContainsString('<h2>Bestand</h2>', $statsPage->body);
        $this->assertMatchesRegularExpression(
            '/<td>' . preg_quote($station, '/') . '<\/td>\s*<td>2<\/td>/',
            $statsPage->body,
            "Erwartete Deckstations-Zeile '{$station}' mit Anzahl 2 nicht gefunden. Body: {$statsPage->body}"
        );

        // ... sowie den Vater "Vater-{unique}" als meistgenutzten Hengst mit Anzahl 2.
        $this->assertMatchesRegularExpression(
            '/<td>Vater-' . preg_quote($unique, '/') . '<\/td>\s*<td>2<\/td>/',
            $statsPage->body,
            "Erwartete Vater-Zeile 'Vater-{$unique}' mit Anzahl 2 nicht gefunden. Body: {$statsPage->body}"
        );

        // Wachstum der Datenbank: aktuelles Jahr sollte mit mindestens 3 (den
        // gerade angelegten Pferden) vertreten sein.
        $currentYear = date('Y');
        $this->assertMatchesRegularExpression(
            '/<td>' . $currentYear . '<\/td>\s*<td>(\d+)<\/td>/',
            $statsPage->body,
            'Erwartete Zeile für das aktuelle Jahr im Wachstumsverlauf nicht gefunden.'
        );

        // 2b. Status-Split (Framework #188): Verstorben ist orthogonal zum
        // Zuchtstatus. Kacheln vorher/nachher vergleichen (absolute Zahlen
        // wären fragil, die DB ist über die Suite geteilt): ein verstorbenes,
        // zuchtinaktives Pferd erhöht Gesamt, Inaktiv und Verstorben um je 1;
        // die Invariante Gesamt = Aktiv + Inaktiv gilt in beiden Messungen.
        $readTiles = function (string $body): array {
            $tiles = [];
            foreach (['Pferde gesamt', 'Aktiv \(Zucht\)', 'Inaktiv \(Zucht\)', 'Verstorben'] as $label) {
                $this->assertSame(1, preg_match(
                    '/<div class="num">(\d+)<\/div><div class="label">' . $label . '<\/div>/',
                    $body,
                    $m
                ), "Kachel '{$label}' nicht gefunden");
                $tiles[stripslashes($label)] = (int) $m[1];
            }
            return $tiles;
        };
        $before = $readTiles($statsPage->body);
        $this->assertSame($before['Pferde gesamt'], $before['Aktiv (Zucht)'] + $before['Inaktiv (Zucht)'], 'Zuchtstatus muss den Bestand partitionieren');

        $this->createHorse($admin, "Verstorben-{$unique}", ['status' => 'inactive', 'death_year' => '2018', 'birth_year' => '1994']);
        $after = $readTiles($admin->get('/plugin/statistik-dashboard/statistik')->body);
        $this->assertSame($before['Pferde gesamt'] + 1, $after['Pferde gesamt']);
        $this->assertSame($before['Aktiv (Zucht)'], $after['Aktiv (Zucht)']);
        $this->assertSame($before['Inaktiv (Zucht)'] + 1, $after['Inaktiv (Zucht)']);
        $this->assertSame($before['Verstorben'] + 1, $after['Verstorben'], 'Todesjahr muss das Pferd als verstorben zählen');
        $this->assertSame($after['Pferde gesamt'], $after['Aktiv (Zucht)'] + $after['Inaktiv (Zucht)']);

        // 2c. Deckstation als VERKNÜPFTER KONTAKT (Addons#139): Bis 0.7 las die
        // Verteilung `breeding_stations`, seit Framework#336 gibt es die
        // Tabelle nicht mehr - `horses.breeding_station_id` zeigt jetzt auf
        // `contacts`. Der Fall oben (Freitext) lief auch mit der alten
        // Abfrage; erst dieser hier fasst den JOIN an. Ohne die Umstellung
        // scheitert die Seite schon an "Table 'breeding_stations' doesn't
        // exist" - der Test liefe dann in 500 statt 200.
        $stationsKontakt = "StatTestKontakt-{$unique}";
        $stationsKontaktId = $this->createContact($admin, $stationsKontakt, [
            'city' => "Statistikdorf-{$unique}",
        ]);
        $this->createHorse($admin, "BeiKontakt-{$unique}", [
            'status' => 'active',
            'persons' => [['role' => 'owner', 'station_contact_id' => (string) $stationsKontaktId]],
        ]);

        $mitKontakt = $admin->get('/plugin/statistik-dashboard/statistik');
        $this->assertSame(200, $mitKontakt->statusCode);
        $this->assertMatchesRegularExpression(
            '/<td>' . preg_quote($stationsKontakt, '/') . '<\/td>\s*<td>1<\/td>/',
            $mitKontakt->body,
            "Die Verteilung muss den Namen des verknüpften Kontakts zeigen ('{$stationsKontakt}'), "
            . "nicht nur den Freitext-Spiegel. Body: {$mitKontakt->body}"
        );

        // 3. Abschnitt "Aufrufe" (#127, aus `besucherstatistik` übernommen):
        // die öffentliche Detailseite zählt jeden Aufruf über den
        // horse.detail_sections-Filter mit.
        $visitor = $this->newClient();
        for ($i = 0; $i < 3; $i++) {
            $detailPage = $visitor->get("/horse?id={$sireId}");
            $this->assertSame(200, $detailPage->statusCode);
        }
        $this->assertStringContainsString(
            '3 mal aufgerufen',
            $detailPage->body,
            'Detailseite sollte nach 3 Aufrufen den vom Plugin ergänzten Zähler "3 mal aufgerufen" enthalten.'
        );

        $statsPage = $admin->get('/plugin/statistik-dashboard/statistik');
        $this->assertStringContainsString('<h2>Aufrufe</h2>', $statsPage->body);
        $this->assertStringContainsString("Vater-{$unique}", $statsPage->body);

        // 4. Übernahme aus `besucherstatistik` samt Marker-Schutz.
        $this->pruefeUebernahmeLaeuftGenauEinmal($admin, $unique, $sireId);

        // 5. Hinweis, solange das aufgegangene Addon noch aktiviert ist (#127).
        $this->pruefeHinweisAufAltesAddon($admin);

        // 6. Berechtigungsdurchsetzung: Editor ohne statistik-dashboard.view wird abgewiesen ...
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS);

        $editor = $this->createAndLoginEditor(
            $admin,
            "statstester{$unique}",
            "statistik-dashboard-test-{$unique}@example.com",
            [$editorGroupId]
        );

        $deniedResponse = $editor->get('/plugin/statistik-dashboard/statistik');
        $this->assertSame(403, $deniedResponse->statusCode);

        // ... und ist nach Zuweisung der Berechtigung erreichbar.
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'statistik-dashboard' => ['view'],
        ]);

        $allowedResponse = $editor->get('/plugin/statistik-dashboard/statistik');
        $this->assertSame(200, $allowedResponse->statusCode);
    }

    /**
     * Kern von Addons#127: `install()` übernimmt Aufrufzahlen und
     * Rechtezuweisungen des aufgegangenen Addons `besucherstatistik` - und
     * läuft dabei bei JEDER Aktivierung erneut.
     *
     * Genau daran scheitert eine Übernahme ohne Marker, und zwar lautlos:
     * Die Aufrufzahlen werden addiert (ein Pferd kann in beiden Zählern
     * stehen), ein zweiter Lauf verdoppelt sie also. Und ein Recht, das ein
     * Admin zwischenzeitlich bewusst entzogen hat, käme bei der nächsten
     * Reaktivierung wieder - ein Rechteentzug, den ein Neustart aufhebt, ist
     * eine Sicherheitslücke.
     *
     * Der Test spielt deshalb genau das durch: einmal übernehmen (muss
     * wirken), Zustand verändern, ein zweites Mal aktivieren (darf nichts
     * mehr tun).
     */
    private function pruefeUebernahmeLaeuftGenauEinmal(HttpClient $admin, string $unique, int $sireId): void {
        $db = Database::getInstance();

        // Die Tabelle des alten Addons nachbauen - exakt so, wie
        // besucherstatistik::install() sie angelegt hat. Sie überlebt dessen
        // Deaktivierung, weil Deaktivieren seit Framework#338 ausdrücklich
        // nichts löscht (das tut erst das Deinstallieren, und auch das nur
        // anhand des Registers `owns` im Manifest des jeweiligen Addons) -
        // genau darauf setzt die Übernahme auf.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::TABELLE_ALT . '` (
                `horse_id` INT NOT NULL PRIMARY KEY,
                `views` INT UNSIGNED NOT NULL DEFAULT 0,
                `last_viewed_at` DATETIME NULL DEFAULT NULL,
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Zwei Fälle, die sich unterschiedlich verhalten müssen:
        //  A) Ein Pferd, das im neuen Zähler bereits steht -> Summe.
        //  B) Ein Pferd, das dort fehlt -> unveränderte Übernahme.
        $ohneZeileId = $this->createHorse($admin, "NurAlt-{$unique}", ['status' => 'active']);
        $loeschen = $db->prepare('DELETE FROM `' . self::TABELLE_AUFRUFE . '` WHERE `horse_id` = :id');
        $loeschen->execute(['id' => $ohneZeileId]);

        $vorher = $this->aufrufe($db, $sireId);
        $this->assertGreaterThan(0, $vorher, 'Der Zähler des Vaters sollte durch die drei Besuche gefüllt sein.');

        $seed = $db->prepare(
            'REPLACE INTO `' . self::TABELLE_ALT . '` (`horse_id`, `views`, `last_viewed_at`) VALUES (:id, :views, NOW())'
        );
        $seed->execute(['id' => $sireId, 'views' => 1000]);
        $seed->execute(['id' => $ohneZeileId, 'views' => 7]);

        // Rechtezuweisung des alten Moduls, wie sie auf einer
        // Bestandsinstallation vorliegt. Direkt in die Kern-Tabelle, weil das
        // Modul `besucherstatistik` in der Berechtigungsmatrix gar nicht mehr
        // auftaucht - über die Oberfläche wäre es nicht mehr setzbar, auf
        // einer Bestandsinstallation liegt die Zeile aber genau so vor.
        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $db->prepare(
            "INSERT IGNORE INTO `group_permissions` (`group_id`, `module`, `action`) VALUES (:g, 'besucherstatistik', 'view')"
        )->execute(['g' => $editorGroupId]);
        $db->prepare(
            "DELETE FROM `group_permissions` WHERE `group_id` = :g AND `module` = 'statistik-dashboard'"
        )->execute(['g' => $editorGroupId]);

        // Ausgangszustand "Übernahme noch nicht gelaufen" herstellen. Nötig
        // für Wiederholungsläufe gegen dieselbe Testdatenbank: der Marker
        // überlebt den Testlauf, und ohne diesen Schritt prüfte der Test beim
        // zweiten Mal nur noch, dass nichts passiert.
        $db->prepare('DELETE FROM `' . self::TABELLE_META . '` WHERE `meta_key` = :k')
            ->execute(['k' => self::MARKER]);
        $this->assertNull($this->markerWert($db), 'Ausgangszustand: kein Marker.');

        // --- Erster Lauf: die Übernahme muss wirken. ---
        $this->setzeAktivierung($admin, false);
        $this->setzeAktivierung($admin, true);

        $this->assertNotNull(
            $this->markerWert($db),
            'Nach der Übernahme muss der Marker stehen. Fehlt er, ist install() gescheitert - '
            . 'PluginManager::runInstallHook() fängt Fehler ab und protokolliert sie nur im Audit-Log.'
        );
        $this->assertSame(
            $vorher + 1000,
            $this->aufrufe($db, $sireId),
            'Steht ein Pferd in beiden Zählern, ist die Summe die richtige Zahl.'
        );
        $this->assertSame(
            7,
            $this->aufrufe($db, $ohneZeileId),
            'Ein im neuen Zähler unbekanntes Pferd muss unverändert übernommen werden.'
        );
        $this->assertTrue(
            $this->hatRecht($db, $editorGroupId, 'statistik-dashboard', 'view'),
            'Die Zuweisung des alten Moduls muss auf das neue übergehen - sonst sähe nach dem Update niemand mehr etwas.'
        );
        $this->assertTrue(
            $this->tabelleExistiert($db, self::TABELLE_ALT),
            'Die alte Tabelle bleibt liegen: Sie gehört einem fremden Addon und ist die einzige Rückfallebene.'
        );

        // --- Zweiter Lauf: darf nichts mehr tun. ---
        // Der Admin entzieht das Recht bewusst; eine Reaktivierung darf es
        // nicht wieder herstellen.
        $db->prepare(
            "DELETE FROM `group_permissions` WHERE `group_id` = :g AND `module` = 'statistik-dashboard'"
        )->execute(['g' => $editorGroupId]);

        $this->setzeAktivierung($admin, false);
        $this->setzeAktivierung($admin, true);

        $this->assertSame(
            $vorher + 1000,
            $this->aufrufe($db, $sireId),
            'Zweite Aktivierung: Ohne Marker hätte sich die übernommene Zahl verdoppelt.'
        );
        $this->assertSame(
            7,
            $this->aufrufe($db, $ohneZeileId),
            'Zweite Aktivierung: auch der neu angelegte Zähler darf sich nicht verdoppeln.'
        );
        $this->assertFalse(
            $this->hatRecht($db, $editorGroupId, 'statistik-dashboard', 'view'),
            'Zweite Aktivierung: ein bewusst entzogenes Recht darf nicht zurückkommen.'
        );

        // Aufräumen für die folgenden Schritte und andere Testklassen: die
        // nachgebaute Fremdtabelle und die Zeile des alten Moduls.
        $db->exec('DROP TABLE IF EXISTS `' . self::TABELLE_ALT . '`');
        $db->prepare(
            "DELETE FROM `group_permissions` WHERE `group_id` = :g AND `module` = 'besucherstatistik'"
        )->execute(['g' => $editorGroupId]);
    }

    /**
     * Solange `besucherstatistik` auf der Instanz noch aktiviert ist, zählen
     * beide parallel und die öffentliche Detailseite trägt zwei Zähler. Die
     * Statistik-Seite weist darauf hin - dort, wo jemand die Zahlen
     * tatsächlich liest.
     */
    private function pruefeHinweisAufAltesAddon(HttpClient $admin): void {
        $db = Database::getInstance();

        $this->assertStringNotContainsString(
            'ist noch aktiviert',
            $admin->get('/plugin/statistik-dashboard/statistik')->body,
            'Ohne aktiviertes Alt-Addon darf kein Hinweis erscheinen.'
        );

        // Aktivierungszeile des alten Addons nachstellen. Der PluginManager
        // lädt nur, was er unter plugins/ findet (loadEnabledPlugins()
        // iteriert über die entdeckten Verzeichnisse) - eine Zeile ohne
        // Verzeichnis ist deshalb folgenlos.
        $db->exec(
            "INSERT INTO `plugins` (`slug`, `enabled`, `installed_version`) VALUES ('besucherstatistik', 1, '1.1.3')
             ON DUPLICATE KEY UPDATE `enabled` = 1"
        );

        try {
            $seite = $admin->get('/plugin/statistik-dashboard/statistik');
            $this->assertSame(200, $seite->statusCode);
            $this->assertStringContainsString('ist noch aktiviert', $seite->body);
        } finally {
            $db->exec("DELETE FROM `plugins` WHERE `slug` = 'besucherstatistik'");
        }
    }

    /** Aktiviert/deaktiviert das Addon über den echten Admin-Endpunkt. */
    private function setzeAktivierung(HttpClient $admin, bool $aktiv): void {
        $antwort = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => $aktiv ? '1' : '0',
        ]);
        $this->assertSame('/admin/plugins?success=1', $antwort->location(), "Body: {$antwort->body}");
    }

    private function aufrufe(PDO $db, int $horseId): int {
        $stmt = $db->prepare('SELECT `views` FROM `' . self::TABELLE_AUFRUFE . '` WHERE `horse_id` = :id');
        $stmt->execute(['id' => $horseId]);
        $wert = $stmt->fetchColumn();
        $this->assertNotFalse($wert, "Für Pferd {$horseId} fehlt eine Zeile im Zähler.");
        return (int) $wert;
    }

    private function markerWert(PDO $db): ?string {
        $stmt = $db->prepare('SELECT `meta_value` FROM `' . self::TABELLE_META . '` WHERE `meta_key` = :k');
        $stmt->execute(['k' => self::MARKER]);
        $wert = $stmt->fetchColumn();
        return $wert === false ? null : (string) $wert;
    }

    private function hatRecht(PDO $db, int $groupId, string $modul, string $aktion): bool {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM `group_permissions` WHERE `group_id` = :g AND `module` = :m AND `action` = :a'
        );
        $stmt->execute(['g' => $groupId, 'm' => $modul, 'a' => $aktion]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function tabelleExistiert(PDO $db, string $name): bool {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :name'
        );
        $stmt->execute(['name' => $name]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
