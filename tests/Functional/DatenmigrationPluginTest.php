<?php
// tests/Functional/DatenmigrationPluginTest.php

namespace Tests\Functional;

use App\Database;

require_once __DIR__ . '/../../plugins/datenmigration/Plugin.php';

use App\Security\ApiKey;
use App\Security\Crypto;
use App\Service\DatabaseDumper;
use Plugin\Datenmigration\DumpPruefer;
use Plugin\Datenmigration\Exportauswahl;
use Plugin\Datenmigration\TarReader;
use Plugin\Datenmigration\TarWriter;

/**
 * End-to-End-Test für plugins/datenmigration: exportiert die laufende
 * Instanz als Archiv, verfälscht danach gezielt Daten und Uploads, spielt
 * das Archiv über den Import-Weg zurück und prüft, dass beides wieder auf
 * dem exportierten Stand ist. Damit ist der komplette Umzugsweg
 * (Quelle -> Archiv -> Ziel) gegen eine echte Instanz durchgespielt -
 * Quelle und Ziel sind hier dieselbe Instanz, was die Rundreise erlaubt,
 * ohne eine zweite Datenbank aufzubauen.
 *
 * Seit #121 gibt es zusätzlich Teilarchive. Deren Prüfung ist die
 * eigentliche Sicherheitsprüfung dieses Addons: dass das Zugangsmaterial
 * (users, api_keys) NICHT im Archiv liegt, wenn es nicht angehakt war.
 */
class DatenmigrationPluginTest extends FunctionalTestCase {

    use PersonStationHelper;

    private const SLUG = 'datenmigration';

    private function frameworkRoot(): string {
        return \FRAMEWORK_VENDOR_DIR;
    }

    /** Aktiviert das Addon und liefert einen angemeldeten Admin-Client. */
    private function aktiviertesAddon(): \Tests\Support\HttpClient {
        $admin = $this->authenticatedClient();
        $toggle = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggle->location());
        return $admin;
    }

    /**
     * Erstellt ein Archiv über den POST-Weg und legt es unter dem
     * zurückgemeldeten Namen ab.
     *
     * @param array<int, string> $gruppen
     * @param array<string, string> $zusatz weitere Formularfelder (Exportpasswort)
     * @return array{name:string, body:string}
     */
    private function erstelleArchiv(\Tests\Support\HttpClient $admin, array $gruppen, array $zusatz = []): array {
        $form = $admin->get('/plugin/datenmigration/export');
        $this->assertSame(200, $form->statusCode);
        $antwort = $admin->post('/plugin/datenmigration/export', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'gruppen' => $gruppen,
            // Fehlende Gegenstücke werden in erstelleArchiv() bewusst
            // übergangen - die Warnseite hat einen eigenen Test.
            'trotzdem' => '1',
        ] + $zusatz);
        $this->assertSame(200, $antwort->statusCode, "Export fehlgeschlagen, Body: {$antwort->body}");
        $disposition = (string) $antwort->header('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        preg_match('/filename="([^"]+)"/', $disposition, $m);
        $this->assertNotEmpty($m, "Kein Dateiname im Content-Disposition: {$disposition}");

        // Der Export legt jedes Archiv zusätzlich in var/datenmigration ab
        // (der Weg für große Archive). In der Testinstanz sind das komplette
        // Datenbank-Dumps, die sich sonst über die Läufe hinweg ansammeln -
        // sie kommen deshalb am Ende des Tests weg.
        $this->aufzuraeumen[] = $this->stageDir() . '/' . $m[1];

        return ['name' => $m[1], 'body' => $antwort->body];
    }

    /** @var array<int, string> Dateien, die nach dem Test verschwinden sollen. */
    private array $aufzuraeumen = [];

    /** @var array<string, string|null> Datei => Inhalt vor dem Test (null = gab es nicht) */
    private array $wiederherstellen = [];

    /** Ursprünglicher settings-Wert, falls ein Test ihn setzt: [Schlüssel => Wert|null]. */
    private array $einstellungenVorher = [];

    protected function tearDown(): void {
        foreach ($this->aufzuraeumen as $datei) {
            if (is_file($datei)) {
                unlink($datei);
            }
        }
        $this->aufzuraeumen = [];
        foreach ($this->wiederherstellen as $datei => $inhalt) {
            if ($inhalt === null) {
                @unlink($datei);
            } else {
                if (!is_dir(dirname($datei))) {
                    mkdir(dirname($datei), 0755, true);
                }
                file_put_contents($datei, $inhalt);
            }
        }
        $this->wiederherstellen = [];
        foreach ($this->einstellungenVorher as $schluessel => $wert) {
            $db = Database::getInstance();
            if ($wert === null) {
                $db->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$schluessel]);
            } else {
                $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) '
                    . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$schluessel, $wert]);
            }
        }
        $this->einstellungenVorher = [];
        foreach (glob($this->stageDir() . '/ersetzte-dateien-*') ?: [] as $d) {
            exec('rm -rf ' . escapeshellarg($d));
        }
        exec('rm -rf ' . escapeshellarg($this->frameworkRoot() . '/public/uploads.import-alt'));
        foreach ($this->sicherungen() as $b) {
            @unlink($b);
        }
        parent::tearDown();
    }

    /** Merkt den Stand einer Datei, damit tearDown() ihn zurückschreibt. */
    private function merkeDatei(string $datei): void {
        if (!array_key_exists($datei, $this->wiederherstellen)) {
            $this->wiederherstellen[$datei] = is_file($datei) ? (string) file_get_contents($datei) : null;
        }
    }

    /**
     * Liest ein Archiv vollständig ein.
     *
     * @return array<string, string> Eintragsname => Inhalt
     */
    private function archivEintraege(string $pfad): array {
        $entries = [];
        $reader = new TarReader($pfad);
        $reader->each(function (string $name, int $size, callable $read) use (&$entries) {
            $data = '';
            while (($chunk = $read()) !== '') {
                $data .= $chunk;
            }
            $entries[$name] = $data;
        });
        $reader->close();
        return $entries;
    }

    private function stageDir(): string {
        $dir = $this->frameworkRoot() . '/var/datenmigration';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    public function testExportImportRundreise(): void {
        $admin = $this->aktiviertesAddon();

        // Dashboard-Kachel und Übersichtsseite.
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString('/plugin/datenmigration/uebersicht', $dashboard->body);
        $overview = $admin->get('/plugin/datenmigration/uebersicht');
        $this->assertSame(200, $overview->statusCode);
        $this->assertStringContainsString('Export-Archiv zusammenstellen', $overview->body);

        // Testdaten: ein Pferd und eine Upload-Datei, die die Rundreise
        // nachweisbar machen.
        $unique = uniqid();
        $horseName = "MigrationsPferd-{$unique}";
        $createForm = $admin->get('/admin/horses/create');
        $create = $admin->post('/admin/horses/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => $horseName,
            'status' => 'active',
            'sex' => 'stallion',
            'breed' => 'Fjordpferd',
            'birth_year' => '2020',
        ]);
        $this->assertSame('/admin/horses?success=created', $create->location());

        $uploadsDir = $this->frameworkRoot() . '/public/uploads/horses';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        $uploadRel = "horses/migrationstest-{$unique}.txt";
        $uploadAbs = $this->frameworkRoot() . '/public/uploads/' . $uploadRel;
        file_put_contents($uploadAbs, "upload-inhalt-{$unique}");
        $this->aufzuraeumen[] = $uploadAbs;

        // Audit N1: Die Schutzdatei unter horses/ steht vor dem Import mit
        // einem erkennbaren Inhalt - nach dem Verzeichnistausch muss genau
        // dieser Stand der Zielinstanz zurück sein.
        $horsesSchutz = $this->frameworkRoot() . '/public/uploads/horses/.htaccess';
        $this->merkeDatei($horsesSchutz);
        file_put_contents($horsesSchutz, "# Stand der Zielinstanz {$unique}\nRequire all denied\n");

        // Das Auswahlformular: Vorgabe ist "alles außer Benutzer" - genau
        // dieser Haken darf nicht vorbelegt sein, sonst ist der Vollexport
        // samt Passwort-Hashes wieder der Regelfall.
        $form = $admin->get('/plugin/datenmigration/export');
        $this->assertSame(200, $form->statusCode);
        $this->assertStringContainsString('Benutzer, Gruppen, Rechte', $form->body);
        $this->assertMatchesRegularExpression(
            '/value="benutzer"(?! checked)/',
            $form->body,
            'Die Gruppe "Benutzer, Gruppen, Rechte" darf nicht vorbelegt sein.'
        );
        $this->assertMatchesRegularExpression('/value="pferde" checked/', $form->body);

        // Export: VOLLarchiv (alle Gruppen), damit die Rundreise den
        // kompletten Bestand zurückholt.
        $archiv = $this->erstelleArchiv($admin, Exportauswahl::schluessel());
        $this->assertMatchesRegularExpression('/^datenmigration-\d{8}-\d{6}\.tar(\.gz)?$/', $archiv['name']);

        $archivePath = sys_get_temp_dir() . '/dm-functional-' . $unique
            . (str_ends_with($archiv['name'], '.gz') ? '.tar.gz' : '.tar');
        file_put_contents($archivePath, $archiv['body']);

        $entries = $this->archivEintraege($archivePath);

        $this->assertArrayHasKey('manifest.json', $entries);
        $this->assertArrayHasKey('database.sql', $entries);
        $this->assertArrayHasKey('uploads/' . $uploadRel, $entries);
        $manifest = json_decode($entries['manifest.json'], true);
        $this->assertIsArray($manifest);
        $this->assertSame(3, $manifest['format']);
        $this->assertTrue($manifest['vollstaendig']);
        // CORE_VERSION ist nur im App-Subprozess definiert; dass die Version
        // zur Zielinstanz passt, weist die Vorschau unten nach (kein
        // "Kern-Version passt nicht").
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', (string) $manifest['core_version']);
        $this->assertArrayHasKey('horses', $manifest['tables']);
        $this->assertStringContainsString($horseName, $entries['database.sql']);
        $this->assertSame("upload-inhalt-{$unique}", $entries['uploads/' . $uploadRel]);

        // Verfälschen: Pferd umbenennen, Upload-Datei löschen — der Import
        // muss beides zurückholen.
        $db = Database::getInstance();
        $db->exec("UPDATE horses SET name = 'VERFAELSCHT-{$unique}' WHERE name = " . $db->quote($horseName));
        unlink($uploadAbs);
        $this->assertFalse(file_exists($uploadAbs));

        // Archiv in die Server-Ablage legen (der Weg für große Archive).
        $stageDir = $this->frameworkRoot() . '/var/datenmigration';
        if (!is_dir($stageDir)) {
            mkdir($stageDir, 0750, true);
        }
        $stagedName = 'rundreise-' . $unique . (str_ends_with($archivePath, '.gz') ? '.tar.gz' : '.tar');
        copy($archivePath, $stageDir . '/' . $stagedName);

        // Prüfseite: zeigt Manifest-Abgleich, keine Hindernisse.
        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($stagedName));
        $this->assertSame(200, $preview->statusCode);
        $this->assertStringContainsString('Import anwenden', $preview->body);
        $this->assertStringNotContainsString('Kern-Version passt nicht', $preview->body);
        $this->assertStringContainsString('Vollarchiv', $preview->body);

        // Anwenden: ersetzt Datenbank und Uploads, beendet die Sitzung.
        $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
            'csrf_token' => $preview->formField('csrf_token') ?? '',
            'datei' => $stagedName,
            'bestaetigt' => '1',
        ] + $this->pflichtwahl($preview));
        $this->assertSame('/login?import=fertig', $apply->location(), "Import fehlgeschlagen, Body: {$apply->body}");

        // Rundreise geprüft: Pferdename wieder original, Upload-Datei zurück.
        $restored = $db->query("SELECT COUNT(*) FROM horses WHERE name = " . $db->quote($horseName))->fetchColumn();
        $this->assertSame(1, (int) $restored, 'Pferd nach Import nicht auf Export-Stand');
        $gone = $db->query("SELECT COUNT(*) FROM horses WHERE name = 'VERFAELSCHT-{$unique}'")->fetchColumn();
        $this->assertSame(0, (int) $gone, 'Verfälschter Stand hat den Import überlebt');
        $this->assertTrue(file_exists($uploadAbs), 'Upload-Datei nach Import nicht wiederhergestellt');
        $this->assertSame("upload-inhalt-{$unique}", file_get_contents($uploadAbs));

        // Audit N1: ALLE Schutzdateien stehen wieder, horses/.htaccess mit dem
        // Stand der Zielinstanz vor dem Import (aus public/uploads.import-alt).
        $this->assertFileExists($this->frameworkRoot() . '/public/uploads/.htaccess');
        $this->assertFileExists($horsesSchutz, 'public/uploads/horses/.htaccess fehlt nach dem Vollimport');
        $this->assertSame("# Stand der Zielinstanz {$unique}\nRequire all denied\n", file_get_contents($horsesSchutz));

        // Sicherungs-Dump wurde vor dem Anwenden geschrieben.
        $backups = glob($stageDir . '/sicherung-vor-import-*') ?: [];
        $this->assertNotEmpty($backups, 'Kein Sicherungs-Dump vor dem Import angelegt');

        // Alte Sitzung ist tot, eine frische Anmeldung funktioniert weiter
        // (dieselben Konten: Quelle == Ziel in dieser Rundreise).
        $this->assertSame(302, $admin->get('/admin/plugins')->statusCode);
        $fresh = $this->authenticatedClient();
        $this->assertSame(200, $fresh->get('/admin/plugins')->statusCode);

        // Aufräumen: Test-Artefakte entfernen (das importierte Archiv
        // bleibt Teil des DB-Zustands; Pferd stammt aus dem Export).
        unlink($archivePath);
        unlink($stageDir . '/' . $stagedName);
        foreach ($backups as $b) {
            unlink($b);
        }
    }

    /**
     * #108: Die Berechtigung datenmigration.export/.import genuegt NICHT -
     * es braucht zusaetzlich Administratorrechte (#97).
     *
     * Diese Zusatzhuerde war von keinem Test beruehrt. Faellt sie bei einem
     * Refactoring weg, laedt ein Redakteur mit der harmlos aussehenden
     * Berechtigung `datenmigration.export` den vollstaendigen Datenbankdump
     * herunter - einschliesslich users (Passwort-Hashes, TOTP-Secrets) und
     * api_keys - und macht sich ueber /import/anwenden mit einem selbstgebauten
     * Archiv zum Administrator.
     *
     * Der Aufbau ist bewusst der unguenstigste: Der Editor bekommt die
     * Modulrechte AUSDRUECKLICH zugewiesen. Ohne sie schiede er schon an
     * requirePermission() aus, und der Test bewiese nur, dass die
     * Rechtepruefung greift - nicht, dass die Adminpflicht dahinter existiert.
     */
    public function testExportUndImportVerlangenAdminZusaetzlichZumModulrecht(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $toggle = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggle->location());

        $editorGroupId = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "dmtester{$unique}",
            "datenmigration-test-{$unique}@example.com",
            [$editorGroupId]
        );
        $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS + [
            'datenmigration' => ['export', 'import'],
        ]);

        try {
            // editorCsrfToken(): /admin/users/create ist fuer diesen Benutzer
            // gesperrt, das Token waere leer - und der POST scheiterte dann am
            // CSRF-Check statt an der Adminpflicht, die hier geprueft werden
            // soll (Framework#377).
            $csrf = $this->editorCsrfToken($editor);

            $exportForm = $editor->get('/plugin/datenmigration/export');
            $this->assertSame(403, $exportForm->statusCode, 'Auswahlseite ohne Adminrechte muss abgewiesen werden');

            // Der Weg, an dem seit #121 das Archiv entsteht, ist der POST -
            // die Adminpflicht muss dort ebenso greifen. Die Auswahlseite
            // abzuweisen und den POST offen zu lassen wäre ein Türsteher vor
            // einer offenen Seitentür.
            $export = $editor->post('/plugin/datenmigration/export', [
                'csrf_token' => $csrf,
                'gruppen' => ['benutzer'],
                'trotzdem' => '1',
            ]);
            $this->assertSame(403, $export->statusCode, 'Export ohne Adminrechte muss abgewiesen werden');
            $this->assertStringNotContainsString(
                'password_hash',
                $export->body,
                'Die Ablehnung darf keine Spur des Dumps enthalten'
            );

            $pruefen = $editor->get('/plugin/datenmigration/import/pruefen');
            $this->assertSame(403, $pruefen->statusCode, 'Import-Vorschau ohne Adminrechte muss abgewiesen werden');

            $hochladen = $editor->post('/plugin/datenmigration/import/hochladen', ['csrf_token' => $csrf]);
            $this->assertSame(403, $hochladen->statusCode, 'Hochladen ohne Adminrechte muss abgewiesen werden');

            $anwenden = $editor->post('/plugin/datenmigration/import/anwenden', ['csrf_token' => $csrf]);
            $this->assertSame(403, $anwenden->statusCode, 'Anwenden ohne Adminrechte muss abgewiesen werden');

            // Die Ablehnung wird protokolliert - ohne Eintrag bliebe ein
            // Versuch, an den gesamten Datenbestand zu kommen, spurlos.
            $stmt = Database::getInstance()->prepare(
                "SELECT COUNT(*) FROM audit_logs WHERE action LIKE ? AND created_at >= (NOW() - INTERVAL 10 MINUTE)"
            );
            $stmt->execute(['Datenmigration abgelehnt%']);
            $this->assertGreaterThan(
                0,
                (int)$stmt->fetchColumn(),
                'Der abgewiesene Zugriff muss im Audit-Log stehen'
            );

            // Gegenprobe: Als Administrator geht derselbe Weg weiterhin - sonst
            // belegte der Test nur, dass die Route kaputt ist.
            $this->assertSame(
                200,
                $admin->get('/plugin/datenmigration/import/pruefen')->statusCode,
                'Der Adminweg muss unveraendert offen sein'
            );
        } finally {
            // Die Editor-Gruppe ist geteilter Zustand der Suite.
            $this->setGroupPermissions($admin, $editorGroupId, self::EDITOR_DEFAULT_PERMISSIONS);
        }
    }

    public function testImportVerweigertFremdeKernVersion(): void {
        $admin = $this->authenticatedClient();
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        // Mini-Archiv mit falscher core_version direkt in die Ablage legen.
        $stageDir = $this->frameworkRoot() . '/var/datenmigration';
        if (!is_dir($stageDir)) {
            mkdir($stageDir, 0750, true);
        }
        $unique = uniqid();
        $name = "fremdversion-{$unique}.tar";
        $tar = \Plugin\Datenmigration\TarWriter::create($stageDir . '/' . $name);
        $tar->addString('manifest.json', json_encode([
            'format' => 1,
            'core_version' => '0.0.1-anders',
            'site_name' => 'Fremde Quelle',
            'tables' => [],
            'plugins' => [],
            'uploads_count' => 0,
        ]));
        $tar->addString('database.sql', '-- leer');
        $tar->close();

        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
        $this->assertSame(200, $preview->statusCode);
        $this->assertStringContainsString('Kern-Version passt nicht', $preview->body);
        $this->assertStringNotContainsString('Import anwenden</button>', $preview->body);

        // Auch ein direkter POST (an der Vorschau vorbei) wird abgewiesen.
        $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'datei' => $name,
            'bestaetigt' => '1',
        ]);
        $this->assertSame(200, $apply->statusCode);
        $this->assertStringContainsString('Kern-Version passt nicht', $apply->body);

        unlink($stageDir . '/' . $name);
    }

    /**
     * #121, der eigentliche Punkt der Auswahl: Ein Archiv ohne die Gruppe
     * "Benutzer, Gruppen, Rechte" enthält KEIN Zugangsmaterial.
     *
     * Bis v0.7 nahm jeder Export `users` mit - Passwort-Hashes,
     * TOTP-Geheimnisse, Backup-Codes - und `api_keys` dazu. Wer sein
     * Pferdeverzeichnis an einen Zuchtverband weitergab, gab die Zugänge
     * seines Vereins mit. Der Test greift deshalb nicht die Oberfläche ab,
     * sondern den TATSÄCHLICHEN Inhalt von database.sql.
     */
    public function testTeilarchivEnthaeltKeinZugangsmaterial(): void {
        $admin = $this->aktiviertesAddon();
        $unique = uniqid();

        $archiv = $this->erstelleArchiv($admin, ['pferde', 'kontakte']);
        $this->assertStringStartsWith('datenmigration-teil-', $archiv['name']);

        $pfad = sys_get_temp_dir() . '/dm-teil-' . $unique
            . (str_ends_with($archiv['name'], '.gz') ? '.tar.gz' : '.tar');
        file_put_contents($pfad, $archiv['body']);
        $entries = $this->archivEintraege($pfad);

        $manifest = json_decode($entries['manifest.json'], true);
        $this->assertSame(3, $manifest['format']);
        $this->assertFalse($manifest['vollstaendig']);
        $this->assertSame(['pferde', 'kontakte'], $manifest['auswahl']);
        $this->assertArrayHasKey('horses', $manifest['tables']);
        $this->assertArrayNotHasKey('users', $manifest['tables']);

        $sql = $entries['database.sql'];
        foreach (['users', 'api_keys', 'password_resets', 'group_permissions', 'settings', 'audit_logs'] as $tabelle) {
            $this->assertStringNotContainsString(
                "DROP TABLE IF EXISTS `{$tabelle}`",
                $sql,
                "Tabelle '{$tabelle}' war nicht ausgewählt und darf nicht im Dump stehen."
            );
        }
        $this->assertStringContainsString('DROP TABLE IF EXISTS `horses`', $sql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `contacts`', $sql);
        $this->assertStringNotContainsString('password_hash', $sql, 'Kein Feld der Benutzertabelle im Teilarchiv.');

        // Und der Dump sagt selbst, dass er keine Sicherung ist - wer die
        // Datei Monate später vor sich hat, sieht sonst eine gültige .sql
        // und hält sie für ein Backup (Framework#342).
        $this->assertStringContainsString('KEINE vollständige Sicherung', $sql);

        // Keine Dateien angehakt -> keine im Archiv.
        foreach (array_keys($entries) as $eintrag) {
            $this->assertStringStartsNotWith('uploads/', $eintrag);
        }

        unlink($pfad);
    }

    /**
     * Ein Teilarchiv wird ZUSAMMENGEFÜHRT, nicht eingesetzt: Es ersetzt die
     * enthaltenen Tabellen und lässt alles andere in Ruhe.
     *
     * Die Gegenprobe ist der Kern des Tests. Würde der Import ein Teilarchiv
     * wie einen vollständigen Stand behandeln, verschwänden das eben
     * angelegte Benutzerkonto und die Upload-Datei - und der Betreiber, der
     * nur seine Pferdedaten zurückspielen wollte, stünde ohne Konten da.
     */
    public function testTeilarchivWirdZusammengefuehrtStattErsetzt(): void {
        $admin = $this->aktiviertesAddon();
        $unique = uniqid();
        $db = Database::getInstance();

        // Ausgangslage: ein Pferd (kommt ins Archiv) und eine Upload-Datei
        // (kommt NICHT ins Archiv).
        $horseName = "TeilarchivPferd-{$unique}";
        $createForm = $admin->get('/admin/horses/create');
        $create = $admin->post('/admin/horses/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => $horseName,
            'status' => 'active',
            'sex' => 'stallion',
            'breed' => 'Fjordpferd',
            'birth_year' => '2019',
        ]);
        $this->assertSame('/admin/horses?success=created', $create->location());

        $uploadsDir = $this->frameworkRoot() . '/public/uploads/horses';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        $uploadAbs = $uploadsDir . "/teilarchiv-{$unique}.txt";
        file_put_contents($uploadAbs, "bleibt-{$unique}");

        $archiv = $this->erstelleArchiv($admin, ['pferde', 'kontakte']);
        $stagedName = 'teilarchiv-' . $unique . (str_ends_with($archiv['name'], '.gz') ? '.tar.gz' : '.tar');
        file_put_contents($this->stageDir() . '/' . $stagedName, $archiv['body']);

        // Nach dem Export: Pferd verfälschen (muss zurückkommen) und einen
        // Benutzer anlegen (muss bleiben).
        $db->exec("UPDATE horses SET name = 'VERFAELSCHT-{$unique}' WHERE name = " . $db->quote($horseName));
        $benutzer = "teilarchiv{$unique}";
        $this->createAndLoginEditor(
            $admin,
            $benutzer,
            "teilarchiv-{$unique}@example.com",
            [$this->findBuiltinGroupId($admin, 'Editor')]
        );

        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($stagedName));
        $this->assertSame(200, $preview->statusCode);
        $this->assertStringContainsString('Teilarchiv', $preview->body);
        $this->assertStringContainsString('bleibt unverändert', $preview->body);
        // Keine Benutzertabelle ersetzt -> kein Hinweis auf beendete Sitzungen.
        $this->assertStringNotContainsString('Sitzungen und API-Schlüssel', $preview->body);

        $sessionVersionen = $this->sessionVersionen();
        $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
            'csrf_token' => $preview->formField('csrf_token') ?? '',
            'datei' => $stagedName,
            'bestaetigt' => '1',
        ] + $this->pflichtwahl($preview));
        $this->assertSame(
            '/plugin/datenmigration/uebersicht?hinweis=importiert',
            $apply->location(),
            "Teilimport fehlgeschlagen, Body: {$apply->body}"
        );

        // Enthalten -> ersetzt.
        $this->assertSame(
            1,
            (int) $db->query('SELECT COUNT(*) FROM horses WHERE name = ' . $db->quote($horseName))->fetchColumn(),
            'Pferd nach dem Teilimport nicht auf dem Archivstand'
        );

        // Nicht enthalten -> unangetastet.
        $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmt->execute([$benutzer]);
        $this->assertSame(
            1,
            (int) $stmt->fetchColumn(),
            'Der Teilimport hat die Benutzertabelle angefasst, obwohl sie nicht im Archiv war'
        );
        $this->assertTrue(
            file_exists($uploadAbs),
            'Der Teilimport hat public/uploads getauscht, obwohl das Archiv keine Dateien enthielt'
        );

        // Die Sitzung bleibt gültig - die Konten wurden ja nicht getauscht.
        // Gegenprobe zu Audit M2: Ohne ersetzte Benutzertabelle bleibt auch
        // jede session_version, wie sie war.
        $this->assertSame(200, $admin->get('/admin/plugins')->statusCode);
        $this->assertSame($sessionVersionen, $this->sessionVersionen());

        // Aufräumen.
        unlink($uploadAbs);
        unlink($this->stageDir() . '/' . $stagedName);
        foreach (glob($this->stageDir() . '/sicherung-vor-import-*') ?: [] as $b) {
            unlink($b);
        }
    }

    /**
     * Teilarchiv MIT Dateien: Der Verzeichnistausch wäre hier falsch - er
     * löschte jede Datei, die nur die Zielinstanz hat. Also zusammenführen,
     * und überschriebene Originale vorher sichern: Ein Teilimport soll keine
     * Datei vernichten, für die es keinen Rückweg gibt.
     */
    public function testTeilarchivFuehrtDateienZusammenUndSichertUeberschriebene(): void {
        $admin = $this->aktiviertesAddon();
        $unique = uniqid();

        $uploadsDir = $this->frameworkRoot() . '/public/uploads/horses';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        $imArchiv = $uploadsDir . "/merge-archiv-{$unique}.txt";
        file_put_contents($imArchiv, 'stand-aus-dem-archiv');

        $archiv = $this->erstelleArchiv($admin, ['pferde', Exportauswahl::GRUPPE_DATEIEN]);
        $stagedName = 'merge-' . $unique . (str_ends_with($archiv['name'], '.gz') ? '.tar.gz' : '.tar');
        file_put_contents($this->stageDir() . '/' . $stagedName, $archiv['body']);

        // Nach dem Export: die Archivdatei verändern (muss zurückkommen) und
        // eine zweite anlegen, die das Archiv nicht kennt (muss bleiben).
        file_put_contents($imArchiv, 'neuerer-stand-des-ziels');
        $nurZiel = $uploadsDir . "/merge-nurziel-{$unique}.txt";
        file_put_contents($nurZiel, 'nur-auf-dem-ziel');

        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($stagedName));
        $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
            'csrf_token' => $preview->formField('csrf_token') ?? '',
            'datei' => $stagedName,
            'bestaetigt' => '1',
        ] + $this->pflichtwahl($preview));
        $this->assertSame(
            '/plugin/datenmigration/uebersicht?hinweis=importiert',
            $apply->location(),
            "Teilimport mit Dateien fehlgeschlagen, Body: {$apply->body}"
        );

        $this->assertSame('stand-aus-dem-archiv', file_get_contents($imArchiv));
        $this->assertTrue(file_exists($nurZiel), 'Der Teilimport hat eine Datei gelöscht, die nur das Ziel hatte');
        $this->assertSame('nur-auf-dem-ziel', file_get_contents($nurZiel));

        // Der Ausführungsschutz des Upload-Verzeichnisses steht weiterhin -
        // auch der unter horses/ (Audit N1).
        $this->assertTrue(file_exists($this->frameworkRoot() . '/public/uploads/.htaccess'));
        $this->assertTrue(file_exists($this->frameworkRoot() . '/public/uploads/horses/.htaccess'));

        // Das überschriebene Original ist der Rückweg - es liegt in der Ablage.
        $gesichert = glob($this->stageDir() . "/ersetzte-dateien-*/horses/merge-archiv-{$unique}.txt") ?: [];
        $this->assertNotEmpty($gesichert, 'Kein Rückweg für die überschriebene Datei angelegt');
        $this->assertSame('neuerer-stand-des-ziels', file_get_contents($gesichert[0]));

        // Aufräumen.
        unlink($imArchiv);
        unlink($nurZiel);
        unlink($this->stageDir() . '/' . $stagedName);
        foreach (glob($this->stageDir() . '/sicherung-vor-import-*') ?: [] as $b) {
            unlink($b);
        }
        foreach (glob($this->stageDir() . '/ersetzte-dateien-*') ?: [] as $d) {
            exec('rm -rf ' . escapeshellarg($d));
        }
    }

    /**
     * "Pferde ohne Kontakte" ist eine plausible Auswahl und erzeugt beim
     * Einspielen verwaiste Verweise - ohne jede Fehlermeldung, denn der Dump
     * setzt FOREIGN_KEY_CHECKS=0 und das abschließende =1 prüft den Bestand
     * nicht nach. Genau deshalb muss die Zahl VOR dem Erstellen auf dem
     * Bildschirm stehen; auf eine Fehlermeldung zu warten, die nie kommt, ist
     * keine Absicherung.
     */
    public function testExportWarntMitZahlenVorFehlendenGegenstuecken(): void {
        $admin = $this->aktiviertesAddon();
        $unique = uniqid();
        $db = Database::getInstance();

        $kontaktId = $this->createContact($admin, "WarnKontakt-{$unique}");
        $horseName = "WarnPferd-{$unique}";
        $createForm = $admin->get('/admin/horses/create');
        $admin->post('/admin/horses/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => $horseName,
            'status' => 'active',
            'sex' => 'stallion',
            'breed' => 'Fjordpferd',
            'birth_year' => '2018',
        ]);
        // Die Verknüpfung direkt setzen: Geprüft wird die Zählung, nicht das
        // Zuordnungsformular des Kerns.
        $stmt = $db->prepare('UPDATE horses SET breeding_station_id = ? WHERE name = ?');
        $stmt->execute([$kontaktId, $horseName]);

        $form = $admin->get('/plugin/datenmigration/export');
        $warnung = $admin->post('/plugin/datenmigration/export', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'gruppen' => ['pferde'],
        ]);
        $this->assertSame(200, $warnung->statusCode);
        $this->assertStringContainsString('fehlende Gegenstücke', $warnung->body);
        $this->assertStringContainsString('verweisen auf contacts', $warnung->body);
        // Audit M3: Die Verweise zeigen auf dem Ziel nicht "ins Leere",
        // sondern auf fremde Datensätze gleicher Kennung.
        $this->assertStringContainsString('Datensätze mit derselben Kennung', $warnung->body);
        $this->assertStringContainsString('Archiv trotzdem so erstellen', $warnung->body);
        // Kein Archiv, solange nicht bestätigt wurde.
        $this->assertStringNotContainsString('attachment', (string) $warnung->header('Content-Disposition'));

        // Mit Bestätigung entsteht es dann doch - die Warnung ist ein
        // Hinweis, keine Bevormundung.
        $trotzdem = $admin->post('/plugin/datenmigration/export', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'gruppen' => ['pferde'],
            'trotzdem' => '1',
        ]);
        $disposition = (string) $trotzdem->header('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        if (preg_match('/filename="([^"]+)"/', $disposition, $m)) {
            $this->aufzuraeumen[] = $this->stageDir() . '/' . $m[1];
        }

        // Leere Auswahl erzeugt kein leeres Archiv, sondern führt zurück.
        $leer = $admin->post('/plugin/datenmigration/export', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
        ]);
        $this->assertSame('/plugin/datenmigration/export?fehler=leer', $leer->location());

        $stmt = $db->prepare('UPDATE horses SET breeding_station_id = NULL WHERE name = ?');
        $stmt->execute([$horseName]);
    }

    // -- Hilfen für die Import-Prüfung (Audit M1, M2, M3, N21, N23) -------

    /**
     * Die Pflichtwahl der Vorschau (Audit N23), falls sie erscheint. Welche
     * Addon-Tabellen mit Zeilen gerade in der geteilten Testdatenbank stehen,
     * hängt von der Reihenfolge der Suite ab - Tests, die NICHT die
     * Pflichtwahl prüfen, lassen die Zeilen stehen, wie sie sind.
     *
     * @return array<string, string>
     */
    private function pflichtwahl(\Tests\Support\HttpResponse $preview): array {
        return str_contains($preview->body, 'name="abhaengige"') ? ['abhaengige' => 'stehen_lassen'] : [];
    }

    /** @return array<int, int> id => session_version */
    private function sessionVersionen(): array {
        $aus = [];
        foreach (Database::getInstance()->query('SELECT id, session_version FROM users ORDER BY id')->fetchAll() as $r) {
            $aus[(int) $r['id']] = (int) $r['session_version'];
        }
        return $aus;
    }

    /** @return array<int, string> Sicherungs-Dumps in der Ablage */
    private function sicherungen(): array {
        return glob($this->stageDir() . '/sicherung-vor-import-*') ?: [];
    }

    private function wartungAktiv(): bool {
        return is_file($this->frameworkRoot() . '/var/wartung.lock');
    }

    /**
     * Exportiert die Gruppen und liefert Manifest und Dump des Archivs - der
     * Ausgangsstoff für handgebaute und manipulierte Archive.
     *
     * @param array<int, string> $gruppen
     * @return array{manifest: array<string, mixed>, sql: string, body: string, name: string}
     */
    private function exportiere(\Tests\Support\HttpClient $admin, array $gruppen): array {
        $archiv = $this->erstelleArchiv($admin, $gruppen);
        $pfad = sys_get_temp_dir() . '/dm-quelle-' . uniqid() . (str_ends_with($archiv['name'], '.gz') ? '.tar.gz' : '.tar');
        file_put_contents($pfad, $archiv['body']);
        $eintraege = $this->archivEintraege($pfad);
        unlink($pfad);
        return [
            'manifest' => json_decode($eintraege['manifest.json'], true),
            'sql' => $eintraege['database.sql'],
            'body' => $archiv['body'],
            'name' => $archiv['name'],
        ];
    }

    /** Legt das (Export-)Archiv unter eigenem Namen in die Ablage. */
    private function legeAb(string $body, string $praefix): string {
        $name = $praefix . '-' . uniqid() . '.tar' . (str_starts_with($body, "\x1f\x8b") ? '.gz' : '');
        file_put_contents($this->stageDir() . '/' . $name, $body);
        $this->aufzuraeumen[] = $this->stageDir() . '/' . $name;
        return $name;
    }

    /**
     * Baut ein Archiv von Hand in die Ablage.
     *
     * @param array<int, array{0:string, 1:string}> $eintraege [Name, Inhalt]
     */
    private function baueArchiv(string $praefix, array $eintraege): string {
        $name = $praefix . '-' . uniqid() . '.tar';
        $tar = TarWriter::create($this->stageDir() . '/' . $name);
        foreach ($eintraege as [$eintrag, $inhalt]) {
            $tar->addString($eintrag, $inhalt);
        }
        $tar->close();
        $this->aufzuraeumen[] = $this->stageDir() . '/' . $name;
        return $name;
    }

    /** Hängt Blöcke vor den abschließenden SET-Zeilen an einen Dump. */
    private static function vorDemFuss(string $sql, string $zusatz): string {
        $fuss = strrpos($sql, 'SET FOREIGN_KEY_CHECKS=1;');
        return substr($sql, 0, (int) $fuss) . $zusatz . substr($sql, (int) $fuss);
    }

    private function legePferdAn(string $name): int {
        $db = Database::getInstance();
        $db->prepare("INSERT INTO horses (name, status, sex, breed, birth_year) VALUES (?, 'active', 'stallion', 'Fjordpferd', 2015)")
            ->execute([$name]);
        return (int) $db->lastInsertId();
    }

    /** Admin-Konto dieser Suite: [id, password_hash]. */
    private function adminKonto(): array {
        $stmt = Database::getInstance()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([self::$adminEmail]);
        $r = $stmt->fetch();
        return [(int) $r['id'], (string) $r['password_hash']];
    }

    /**
     * Die Prüfung im Functional-Lauf gegen den TATSÄCHLICHEN Dump des
     * gepinnten Kerns - mit den Kern- und den hier aktivierten
     * Addon-Tabellen, so wie SHOW CREATE TABLE des laufenden Servers sie
     * schreibt. Fällt dieser Test, weist der Import echte Archive ab.
     */
    public function testEchterDumpDesGepinntenKernsBestehtDiePruefung(): void {
        $this->aktiviertesAddon();
        $dump = DatabaseDumper::dump();
        $pruefer = new DumpPruefer(1 << 24);
        $anzahl = 0;
        foreach (str_split($dump, 7919) as $teil) {
            foreach ($pruefer->zufuehren($teil) as $_) {
                $anzahl++;
            }
        }
        foreach ($pruefer->abschliessen() as $_) {
            $anzahl++;
        }
        $this->assertGreaterThan(40, $anzahl);
        $this->assertArrayHasKey('users', $pruefer->befund()->tabellen);
    }

    /**
     * Audit M1, der Kern der Sache: Ein "Teilarchiv Pferde", dessen Dump
     * zusätzlich an users schreibt. Bis 1.1.0 sagte die Vorschau "alle
     * übrigen Tabellen bleiben unverändert", und der Dump legte nebenbei ein
     * Konto an. Jetzt: Fehler in der Vorschau, kein Knopf, und auch ein
     * direkter POST ändert nichts - ohne Sicherung, ohne Wartungsmodus, denn
     * die Prüfung liegt vor beidem.
     */
    public function testManipuliertesTeilarchivWirdAbgewiesen(): void {
        $admin = $this->aktiviertesAddon();
        $db = Database::getInstance();
        $quelle = $this->exportiere($admin, ['pferde']);
        [$adminId, $hashVorher] = $this->adminKonto();
        $benutzerVorher = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $boeseZeile = "INSERT INTO `users` (`id`, `username`, `email`, `password_hash`) "
            . "VALUES ('" . $adminId . "', 'boese', 'boese@example.com', 'x');\n";
        $varianten = [
            // INSERT in eine fremde Tabelle innerhalb eines Pferde-Blocks
            'fremdes INSERT' => self::vorDemFuss($quelle['sql'], $boeseZeile),
            // ein vollständiger users-Block, den das Manifest nicht nennt
            'users-Block' => self::vorDemFuss($quelle['sql'], "DROP TABLE IF EXISTS `users`;\n"
                . "CREATE TABLE `users` (\n  `id` int(11) NOT NULL,\n  `username` varchar(50) NOT NULL,\n"
                . "  `email` varchar(100) NOT NULL,\n  `password_hash` varchar(255) NOT NULL,\n  PRIMARY KEY (`id`)\n"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n" . $boeseZeile . "\n"),
        ];

        foreach ($varianten as $art => $sql) {
            $name = $this->baueArchiv('manipuliert', [
                ['manifest.json', json_encode($quelle['manifest'])],
                ['database.sql', $sql],
            ]);
            $sicherungenVorher = $this->sicherungen();

            $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
            $this->assertSame(200, $preview->statusCode);
            $this->assertStringContainsString('alert-error', $preview->body, $art);
            $this->assertStringContainsString('nicht einspielbar', $preview->body, $art);
            $this->assertStringNotContainsString('Import anwenden</button>', $preview->body, $art);

            $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'datei' => $name,
                'bestaetigt' => '1',
                'abhaengige' => 'stehen_lassen',
            ]);
            $this->assertSame(200, $apply->statusCode, $art);
            $this->assertStringContainsString('nichts verändert', $apply->body, $art);

            $this->assertSame($benutzerVorher, (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(), $art);
            $this->assertSame($hashVorher, $this->adminKonto()[1], "{$art}: Passwort-Hash des Admins verändert");
            $this->assertFalse($this->wartungAktiv(), "{$art}: Wartungsmodus aktiv");
            $this->assertSame($sicherungenVorher, $this->sicherungen(), "{$art}: Sicherung vor der Prüfung geschrieben");
        }
        // Die Sitzung lebt - users wurde nicht angefasst.
        $this->assertSame(200, $admin->get('/admin/plugins')->statusCode);
    }

    /**
     * Format 2 schreibt Manifest und Dump aus derselben Tabellenliste.
     * Weichen sie ab, wurde das Archiv nachbearbeitet - abweisen.
     */
    public function testDumpUndManifestMuessenUebereinstimmen(): void {
        $admin = $this->aktiviertesAddon();
        $quelle = $this->exportiere($admin, ['pferde']);
        $manifest = $quelle['manifest'];
        unset($manifest['tables']['match_labels']);
        $manifest['tables']['horse_media'] = 0;
        $name = $this->baueArchiv('abweichend', [
            ['manifest.json', json_encode($manifest)],
            ['database.sql', $quelle['sql']],
        ]);

        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
        $this->assertStringContainsString('stimmen nicht mit dem Manifest überein', $preview->body);
        $this->assertStringContainsString('nur im Dump: match_labels', $preview->body);
        $this->assertStringContainsString('nur im Manifest: horse_media', $preview->body);
        $this->assertStringNotContainsString('Import anwenden</button>', $preview->body);

        $sicherungenVorher = $this->sicherungen();
        $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'datei' => $name,
            'bestaetigt' => '1',
        ]);
        $this->assertStringContainsString('stimmen nicht mit dem Manifest überein', $apply->body);
        $this->assertSame($sicherungenVorher, $this->sicherungen());
    }

    /**
     * tar erlaubt doppelte Einträge; welcher gewinnt, hing vom Lesepfad ab -
     * Vorschau und Anwenden hätten verschiedene Dumps sehen können.
     */
    public function testDoppelteDatabaseSqlImArchivWirdAbgewiesen(): void {
        $admin = $this->aktiviertesAddon();
        $quelle = $this->exportiere($admin, ['pferde']);
        $name = $this->baueArchiv('doppelt', [
            ['manifest.json', json_encode($quelle['manifest'])],
            ['database.sql', $quelle['sql']],
            ['database.sql', "DROP TABLE IF EXISTS `users`;\n"],
        ]);

        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
        $this->assertStringContainsString('database.sql steht mehrfach im Archiv', $preview->body);
        $this->assertStringNotContainsString('Import anwenden</button>', $preview->body);

        $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'datei' => $name,
            'bestaetigt' => '1',
        ]);
        $this->assertStringContainsString('database.sql steht mehrfach im Archiv', $apply->body);
        $this->assertFalse($this->wartungAktiv());

        $doppeltesManifest = $this->baueArchiv('doppelt', [
            ['manifest.json', json_encode($quelle['manifest'])],
            ['manifest.json', json_encode(['format' => 1] + $quelle['manifest'])],
            ['database.sql', $quelle['sql']],
        ]);
        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($doppeltesManifest));
        $this->assertStringContainsString('manifest.json steht mehrfach im Archiv', $preview->body);
    }

    /**
     * Audit M2: Werden Benutzerkonten ersetzt, enden ALLE Sitzungen und alle
     * API-Schlüssel - nicht nur die des Importierenden.
     *
     * Der Aufbau ist der ungünstigste: Redakteur und Schlüssel existieren
     * schon VOR dem Export, stehen also mit derselben session_version im
     * Archiv. Ohne die Anhebung liefe die Sitzung danach einfach weiter -
     * als das gleichnamige Konto der Quelle.
     */
    public function testImportDerBenutzerBeendetAlleSitzungen(): void {
        $admin = $this->aktiviertesAddon();
        $unique = uniqid();
        $db = Database::getInstance();

        $redakteur = $this->createAndLoginEditor(
            $admin,
            "dmsitzung{$unique}",
            "dm-sitzung-{$unique}@example.com",
            [$this->findBuiltinGroupId($admin, 'Editor')]
        );
        $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute(["dmsitzung{$unique}"]);
        $redakteurId = (int) $stmt->fetchColumn();
        $schluessel = ApiKey::create($redakteurId, 'DatenmigrationTest ' . $unique, null);
        $this->assertTrue($schluessel['ok'], 'API-Schlüssel nicht ausgestellt');
        $bearer = ['Authorization' => 'Bearer ' . $schluessel['token']];

        $this->assertSame(200, $redakteur->get('/admin/horses')->statusCode);
        $this->assertSame(200, $this->newClient()->get('/api/horses', $bearer)->statusCode);

        $quelle = $this->exportiere($admin, [Exportauswahl::GRUPPE_BENUTZER]);
        $this->assertArrayHasKey('users', $quelle['manifest']['tables']);
        $name = $this->legeAb($quelle['body'], 'benutzer');

        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
        $this->assertStringContainsString('Sitzungen und API-Schlüssel dieser Instanz werden ungültig', $preview->body);
        $maxVorher = max($this->sessionVersionen());

        $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
            'csrf_token' => $preview->formField('csrf_token') ?? '',
            'datei' => $name,
            'bestaetigt' => '1',
        ] + $this->pflichtwahl($preview));
        $this->assertSame('/login?import=fertig', $apply->location(), "Import fehlgeschlagen, Body: {$apply->body}");

        // Die fremde Sitzung ist beendet ...
        $nachher = $redakteur->get('/admin/horses');
        $this->assertSame(302, $nachher->statusCode, 'Die Sitzung des Redakteurs lebt nach dem Import weiter.');
        $this->assertStringContainsString('/login', (string) $nachher->location());
        // ... der Schlüssel ebenso ...
        $this->assertSame(401, $this->newClient()->get('/api/horses', $bearer)->statusCode);
        // ... und jede session_version liegt über dem bisherigen Höchstwert.
        foreach ($this->sessionVersionen() as $id => $version) {
            $this->assertGreaterThan($maxVorher, $version, "session_version von Konto {$id} nicht angehoben");
        }
        // Die eigene Sitzung ist ebenfalls beendet, eine neue Anmeldung geht.
        $this->assertSame(302, $admin->get('/admin/plugins')->statusCode);
        $this->assertSame(200, $this->authenticatedClient()->get('/admin/plugins')->statusCode);

        foreach ($this->sicherungen() as $b) {
            unlink($b);
        }
        $db->prepare('DELETE FROM api_keys WHERE label = ?')->execute(['DatenmigrationTest ' . $unique]);
    }

    /** Audit M3: Die Standardauswahl nimmt Passkeys und E-Mail-Anmeldecodes nicht mehr mit. */
    public function testStandardTeilarchivEnthaeltKeinePasskeys(): void {
        $admin = $this->aktiviertesAddon();
        $quelle = $this->exportiere($admin, Exportauswahl::vorgabe());
        foreach (['user_passkeys', 'email_2fa_codes', 'users'] as $tabelle) {
            $this->assertArrayNotHasKey($tabelle, $quelle['manifest']['tables']);
            $this->assertStringNotContainsString("DROP TABLE IF EXISTS `{$tabelle}`", $quelle['sql']);
        }
        $this->assertStringContainsString('DROP TABLE IF EXISTS `horses`', $quelle['sql']);
    }

    /**
     * Audit M3, Altarchive: Bis 1.1.0 lief user_passkeys unter "sonstiges".
     * Ein solches Archiv wird weiter eingespielt - die Passkeys aber
     * übersprungen, sonst hingen sie an fremden Konten gleicher Kennung.
     * Übersprungen heißt nicht ersetzt: keine Anhebung der session_version.
     */
    public function testAltesTeilarchivMitPasskeysUeberspringtDiese(): void {
        $admin = $this->aktiviertesAddon();
        $unique = uniqid();
        $db = Database::getInstance();
        [$adminId] = $this->adminKonto();

        $quelle = $this->exportiere($admin, ['pferde']);
        $create = (string) $db->query('SHOW CREATE TABLE `user_passkeys`')->fetch()['Create Table'];
        $passkeyBlock = "-- Tabelle: user_passkeys\nDROP TABLE IF EXISTS `user_passkeys`;\n{$create};\n"
            . "INSERT INTO `user_passkeys` (`id`, `user_id`, `credential_id`, `credential`, `label`, `sign_count`, `created_at`, `last_used_at`) "
            . "VALUES ('990001', '{$adminId}', 'fremd-{$unique}', '{}', 'Fremd', '0', '2026-01-01 00:00:00', NULL);\n\n";
        $manifest = $quelle['manifest'];
        $manifest['auswahl'] = ['pferde', Exportauswahl::GRUPPE_SONSTIGES];
        $manifest['tables']['user_passkeys'] = 1;
        $name = $this->baueArchiv('altarchiv', [
            ['manifest.json', json_encode($manifest)],
            ['database.sql', self::vorDemFuss($quelle['sql'], $passkeyBlock)],
        ]);

        $db->prepare("INSERT INTO user_passkeys (user_id, credential_id, credential, label, created_at) VALUES (?, ?, '{}', 'Ziel', NOW())")
            ->execute([$adminId, "ziel-{$unique}"]);
        try {
            $versionen = $this->sessionVersionen();
            $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
            $this->assertStringContainsString('wird übersprungen', $preview->body);
            $this->assertStringContainsString('Import anwenden</button>', $preview->body);
            $this->assertStringNotContainsString('Sitzungen und API-Schlüssel', $preview->body);

            $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
                'csrf_token' => $preview->formField('csrf_token') ?? '',
                'datei' => $name,
                'bestaetigt' => '1',
            ] + $this->pflichtwahl($preview));
            $this->assertSame('/plugin/datenmigration/uebersicht?hinweis=importiert', $apply->location(), $apply->body);

            $stmt = $db->prepare('SELECT COUNT(*) FROM user_passkeys WHERE credential_id = ?');
            $stmt->execute(["ziel-{$unique}"]);
            $this->assertSame(1, (int) $stmt->fetchColumn(), 'Der Passkey der Zielinstanz ist verschwunden.');
            $stmt->execute(["fremd-{$unique}"]);
            $this->assertSame(0, (int) $stmt->fetchColumn(), 'Der fremde Passkey wurde eingespielt.');
            $this->assertSame($versionen, $this->sessionVersionen());
        } finally {
            $db->prepare('DELETE FROM user_passkeys WHERE credential_id IN (?, ?)')
                ->execute(["ziel-{$unique}", "fremd-{$unique}"]);
            foreach ($this->sicherungen() as $b) {
                unlink($b);
            }
        }
    }

    /**
     * Audit N21: Ein Bestand über max_allowed_packet. Bis 1.1.0 ging der
     * Dump als EIN Paket an den Server und scheiterte - jetzt Anweisung für
     * Anweisung. Die Größe richtet sich nach dem Server; ab 64 MiB wäre der
     * Test unverhältnismäßig und wird übersprungen.
     */
    public function testGrosserImportUeberMaxAllowedPacket(): void {
        $db = Database::getInstance();
        $paket = (int) $db->query('SELECT @@max_allowed_packet')->fetchColumn();
        if ($paket > 64 * 1048576) {
            $this->markTestSkipped("max_allowed_packet ist {$paket} Byte - zu groß für diesen Test.");
        }
        $admin = $this->aktiviertesAddon();
        $marker = 'dm-gross-' . uniqid();
        $zeile = str_repeat('x', 60000);
        $zeilen = intdiv($paket + 2 * 1048576, 60000) + 1;
        try {
            for ($i = 0; $i < $zeilen; $i += 10) {
                $n = min(10, $zeilen - $i);
                $db->prepare('INSERT INTO audit_logs (action, category, details) VALUES '
                    . implode(', ', array_fill(0, $n, '(?, ?, ?)')))
                    ->execute(array_merge(...array_fill(0, $n, [$marker, 'test', $zeile])));
            }
            $stichprobe = $db->prepare('SELECT id, created_at, UNIX_TIMESTAMP(created_at) AS ts FROM audit_logs WHERE action = ? ORDER BY id LIMIT 1');
            $stichprobe->execute([$marker]);
            $vorher = $stichprobe->fetch();

            $quelle = $this->exportiere($admin, Exportauswahl::schluessel());
            $this->assertGreaterThan($paket, strlen($quelle['sql']));
            $name = $this->legeAb($quelle['body'], 'gross');
            $db->prepare('DELETE FROM audit_logs WHERE action = ?')->execute([$marker]);

            $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
            $this->assertStringContainsString('Import anwenden</button>', $preview->body);
            $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
                'csrf_token' => $preview->formField('csrf_token') ?? '',
                'datei' => $name,
                'bestaetigt' => '1',
            ] + $this->pflichtwahl($preview));
            $this->assertSame('/login?import=fertig', $apply->location(), "Großer Import fehlgeschlagen: {$apply->body}");

            $stmt = $db->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = ?');
            $stmt->execute([$marker]);
            $this->assertSame($zeilen, (int) $stmt->fetchColumn());
            $stichprobe->execute([$marker]);
            $nachher = $stichprobe->fetch();
            $this->assertSame($vorher['created_at'], $nachher['created_at'], 'TIMESTAMP beim Import verschoben');
            $this->assertSame($vorher['ts'], $nachher['ts']);
            $this->assertFalse($this->wartungAktiv());
        } finally {
            $db->prepare('DELETE FROM audit_logs WHERE action = ?')->execute([$marker]);
            foreach ($this->sicherungen() as $b) {
                unlink($b);
            }
        }
    }

    /**
     * Audit N21: Scheitert der Dump mittendrin (hier ein doppelter
     * Primärschlüssel), wird die Sicherung zurückgespielt - und die Meldung
     * stimmt: Die Pferde stehen wieder auf dem Stand VOR dem Import, der
     * Wartungsmodus ist aufgehoben.
     */
    public function testFehlschlagMittenImDumpRolltZurueck(): void {
        $admin = $this->aktiviertesAddon();
        $unique = uniqid();
        $db = Database::getInstance();
        $id = $this->legePferdAn("RueckwegPferd-{$unique}");

        $quelle = $this->exportiere($admin, ['pferde']);
        preg_match('/^INSERT INTO `horses` .*$/m', $quelle['sql'], $m);
        $this->assertNotEmpty($m, 'Kein Pferd im Dump');
        $kaputt = str_replace($m[0], $m[0] . "\n" . $m[0], $quelle['sql']);
        $name = $this->baueArchiv('kaputt', [
            ['manifest.json', json_encode($quelle['manifest'])],
            ['database.sql', $kaputt],
        ]);

        // Stand nach dem Export verändern: Genau DIESER Stand muss nach dem
        // Rückweg wieder da sein.
        $db->prepare('UPDATE horses SET name = ? WHERE id = ?')->execute(["NachExport-{$unique}", $id]);
        $vorher = $db->query('SELECT id, name FROM horses ORDER BY id')->fetchAll();

        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
        $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
            'csrf_token' => $preview->formField('csrf_token') ?? '',
            'datei' => $name,
            'bestaetigt' => '1',
        ] + $this->pflichtwahl($preview));
        $this->assertSame(200, $apply->statusCode);
        $this->assertStringContainsString('Sicherungsstand wurde zurückgespielt', $apply->body);
        $this->assertSame($vorher, $db->query('SELECT id, name FROM horses ORDER BY id')->fetchAll());
        $this->assertFalse($this->wartungAktiv(), 'Nach gelungenem Rückweg muss der Wartungsmodus aufgehoben sein.');
        $this->assertSame(200, $admin->get('/admin/plugins')->statusCode);

        $db->prepare('DELETE FROM horses WHERE id = ?')->execute([$id]);
        foreach ($this->sicherungen() as $b) {
            unlink($b);
        }
    }

    /**
     * Audit N21: Ließe sich die Sicherung dieser Instanz selbst nicht wieder
     * einspielen (hier wegen einer View - der Kern schreibt dafür eine leere
     * Anweisung), bricht der Import ab, BEVOR sich etwas ändert. Sonst fiele
     * der Rückweg genau dann aus, wenn er gebraucht wird.
     */
    public function testNichtEinspielbareSicherungVerhindertImport(): void {
        $admin = $this->aktiviertesAddon();
        $unique = uniqid();
        $db = Database::getInstance();
        $id = $this->legePferdAn("SicherungPferd-{$unique}");
        $quelle = $this->exportiere($admin, ['pferde']);
        $name = $this->legeAb($quelle['body'], 'view');
        $db->prepare('UPDATE horses SET name = ? WHERE id = ?')->execute(["NachExport-{$unique}", $id]);

        $db->exec('CREATE OR REPLACE VIEW `dm_testansicht` AS SELECT id FROM horses');
        try {
            $sicherungenVorher = $this->sicherungen();
            $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
            $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
                'csrf_token' => $preview->formField('csrf_token') ?? '',
                'datei' => $name,
                'bestaetigt' => '1',
            ] + $this->pflichtwahl($preview));
            $this->assertStringContainsString('ließe sich nicht zurückspielen', $apply->body);
            $this->assertStringContainsString('Leere Anweisung', $apply->body);
            $this->assertFalse($this->wartungAktiv());
            $this->assertSame($sicherungenVorher, $this->sicherungen(), 'Die unbrauchbare Sicherung blieb liegen.');
            $stmt = $db->prepare('SELECT name FROM horses WHERE id = ?');
            $stmt->execute([$id]);
            $this->assertSame("NachExport-{$unique}", $stmt->fetchColumn(), 'Der Import hat trotzdem etwas verändert.');
        } finally {
            $db->exec('DROP VIEW IF EXISTS `dm_testansicht`');
            $db->prepare('DELETE FROM horses WHERE id = ?')->execute([$id]);
        }
    }

    /**
     * Audit N23: Ein Teilarchiv [pferde, kontakte] ersetzt Pferde und
     * Kontakte - die Kontaktanfragen, Opt-outs und Gesundheitstests des
     * Ziels bleiben stehen und hingen danach an den Datensätzen des Archivs
     * mit derselben Kennung. Die Vorschau sagt das, verlangt eine Wahl ohne
     * Vorgabe, und "trennen" löst die Verweise.
     */
    public function testTeilimportTrenntAbhaengigeAddonZeilen(): void {
        $admin = $this->aktiviertesAddon();
        foreach (['kontaktanfrage', 'gesundheitstests'] as $slug) {
            $toggle = $admin->post('/admin/plugins/toggle', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'slug' => $slug,
                'enable' => '1',
            ]);
            $this->assertSame('/admin/plugins?success=1', $toggle->location());
        }
        $unique = uniqid();
        $db = Database::getInstance();
        $kontaktId = $this->createContact($admin, "TrennKontakt-{$unique}");
        $pferdId = $this->legePferdAn("TrennPferd-{$unique}");

        $quelle = $this->exportiere($admin, ['pferde', 'kontakte']);
        $name = $this->legeAb($quelle['body'], 'trennen');
        $mail = "trennen-{$unique}@example.test";

        $zeilenAnlegen = function () use ($db, $kontaktId, $pferdId, $mail): void {
            $db->prepare("INSERT INTO plugin_kontaktanfrage_requests (contact_id, reason_key, reason_label, requester_name, requester_email)
                          VALUES (?, 'x', 'X', 'Anfragender', ?)")->execute([$kontaktId, $mail]);
            $db->prepare('INSERT IGNORE INTO plugin_kontaktanfrage_optout (contact_id) VALUES (?)')->execute([$kontaktId]);
            $db->prepare("INSERT INTO plugin_gesundheitstests (horse_id, test_type) VALUES (?, ?)")->execute([$pferdId, $mail]);
        };
        $stand = function () use ($db, $kontaktId, $mail): array {
            $s = $db->prepare('SELECT contact_id FROM plugin_kontaktanfrage_requests WHERE requester_email = ? ORDER BY id DESC LIMIT 1');
            $s->execute([$mail]);
            $o = $db->prepare('SELECT COUNT(*) FROM plugin_kontaktanfrage_optout WHERE contact_id = ?');
            $o->execute([$kontaktId]);
            $g = $db->prepare('SELECT COUNT(*) FROM plugin_gesundheitstests WHERE test_type = ?');
            $g->execute([$mail]);
            return ['anfrage' => (int) $s->fetchColumn(), 'optout' => (int) $o->fetchColumn(), 'test' => (int) $g->fetchColumn()];
        };
        $anwenden = function (array $wahl) use ($admin, $name): \Tests\Support\HttpResponse {
            $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
            return $admin->post('/plugin/datenmigration/import/anwenden', [
                'csrf_token' => $preview->formField('csrf_token') ?? '',
                'datei' => $name,
                'bestaetigt' => '1',
            ] + $wahl);
        };

        try {
            $zeilenAnlegen();

            $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
            $this->assertStringContainsString('an den Datensätzen des Archivs', $preview->body);
            $this->assertStringContainsString('plugin_kontaktanfrage_requests', $preview->body);
            $this->assertStringContainsString('plugin_kontaktanfrage_optout', $preview->body);
            $this->assertStringContainsString('plugin_gesundheitstests', $preview->body);
            $this->assertStringContainsString('name="abhaengige" value="trennen" required', $preview->body);
            $this->assertStringNotContainsString('value="trennen" checked', $preview->body);
            $this->assertStringNotContainsString('value="stehen_lassen" checked', $preview->body);

            // Ohne Wahl: nichts verändert, keine Sicherung.
            $sicherungenVorher = $this->sicherungen();
            $ohne = $anwenden([]);
            $this->assertStringContainsString('„trennen“ oder „stehen lassen“', $ohne->body);
            $this->assertSame($sicherungenVorher, $this->sicherungen());
            $this->assertSame(['anfrage' => $kontaktId, 'optout' => 1, 'test' => 1], $stand());

            // Trennen: Anfrage auf 0 ("Datensatz entfernt"), Opt-out und
            // Gesundheitstest (NOT-NULL-Fremdschlüssel) gelöscht.
            $trennen = $anwenden(['abhaengige' => 'trennen']);
            $this->assertSame('/plugin/datenmigration/uebersicht?hinweis=importiert', $trennen->location(), $trennen->body);
            $this->assertSame(['anfrage' => 0, 'optout' => 0, 'test' => 0], $stand());

            // Stehen lassen: unverändert.
            $zeilenAnlegen();
            $lassen = $anwenden(['abhaengige' => 'stehen_lassen']);
            $this->assertSame('/plugin/datenmigration/uebersicht?hinweis=importiert', $lassen->location(), $lassen->body);
            $this->assertSame(['anfrage' => $kontaktId, 'optout' => 1, 'test' => 1], $stand());
        } finally {
            $db->prepare('DELETE FROM plugin_kontaktanfrage_requests WHERE requester_email = ?')->execute([$mail]);
            $db->prepare('DELETE FROM plugin_kontaktanfrage_optout WHERE contact_id = ?')->execute([$kontaktId]);
            $db->prepare('DELETE FROM plugin_gesundheitstests WHERE test_type = ?')->execute([$mail]);
            $db->prepare('DELETE FROM horses WHERE id = ?')->execute([$pferdId]);
            foreach ($this->sicherungen() as $b) {
                unlink($b);
            }
        }
    }

    /**
     * Audit N23: Auch ein Vollarchiv lässt Tabellen stehen - die eines
     * Addons, das die Quelle nicht hat. Bis 1.1.0 lief die Prüfung nur für
     * Teilarchive.
     */
    public function testVollarchivMeldetStehenbleibendeAddonTabellen(): void {
        $admin = $this->aktiviertesAddon();
        $unique = uniqid();
        $db = Database::getInstance();
        $pferdId = $this->legePferdAn("VollPferd-{$unique}");
        $quelle = $this->exportiere($admin, Exportauswahl::schluessel());
        $name = $this->legeAb($quelle['body'], 'voll');

        $db->exec('CREATE TABLE IF NOT EXISTS `plugin_dmtest_kind` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `horse_id` INT NOT NULL,
            FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB');
        try {
            $db->prepare('INSERT INTO plugin_dmtest_kind (horse_id) VALUES (?)')->execute([$pferdId]);
            $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
            $this->assertStringContainsString('Vollarchiv', $preview->body);
            $this->assertStringContainsString('Zeile(n) in plugin_dmtest_kind (horse_id) verweisen auf horses', $preview->body);
            $this->assertStringContainsString('name="abhaengige"', $preview->body);
        } finally {
            $db->exec('DROP TABLE IF EXISTS `plugin_dmtest_kind`');
            $db->prepare('DELETE FROM horses WHERE id = ?')->execute([$pferdId]);
        }
    }

    /**
     * Audit N22: Ein unlesbares Archiv (beschädigt, oder ein .tar.gz auf
     * einem Server ohne zlib) endet beim Anwenden mit einer Meldung, nicht
     * mit einer Fehlerseite - und ändert nichts.
     */
    public function testUnlesbaresArchivEndetBeimAnwendenMitMeldung(): void {
        $admin = $this->aktiviertesAddon();
        $name = 'kaputt-' . uniqid() . '.tar';
        file_put_contents($this->stageDir() . '/' . $name, str_repeat('kein tar ', 200));
        $this->aufzuraeumen[] = $this->stageDir() . '/' . $name;

        $sicherungenVorher = $this->sicherungen();
        $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'datei' => $name,
            'bestaetigt' => '1',
        ]);
        $this->assertSame(200, $apply->statusCode);
        $this->assertStringContainsString('Archiv unlesbar', $apply->body);
        $this->assertStringContainsString('nichts verändert', $apply->body);
        $this->assertSame($sicherungenVorher, $this->sicherungen());
        $this->assertFalse($this->wartungAktiv());
    }

    // -- Pferdefotos und Schutzdateien (Audit M26, N1) ----------------------

    private function horsesDir(): string {
        return $this->frameworkRoot() . '/storage/horses';
    }

    /**
     * Audit M26: Seit Kern 0.8 liegen die Pferdefotos unter storage/horses,
     * außerhalb von public/uploads - und fehlten nach jedem Umzug. Ein
     * Vollarchiv nimmt sie jetzt mit und ersetzt beim Import den INHALT von
     * storage/horses: Fotos, die nur das Ziel hatte, wandern in die
     * Sicherung, die .gitkeep des Kerns bleibt stehen und geht auch nicht ins
     * Archiv (der Import lehnte sie als Punktdatei ab).
     *
     * Zugleich Audit N1 ohne Vorlage: public/uploads/horses/.htaccess fehlt
     * vor dem Import - danach steht die eingebaute Fassung.
     */
    public function testVollarchivNimmtPferdefotosAusStorageMit(): void {
        $admin = $this->aktiviertesAddon();
        $unique = uniqid();
        $foto = $this->horsesDir() . "/horse_test_{$unique}.jpg";
        $nurZiel = $this->horsesDir() . "/horse_nurziel_{$unique}.jpg";
        $this->aufzuraeumen[] = $foto;
        $this->aufzuraeumen[] = $nurZiel;
        $this->assertFileExists($this->horsesDir() . '/.gitkeep', 'Vorbedingung: der Kern liefert storage/horses/.gitkeep aus');
        file_put_contents($foto, "jpeg-{$unique}");

        $schutz = $this->frameworkRoot() . '/public/uploads/horses/.htaccess';
        $this->merkeDatei($schutz);
        @unlink($schutz);

        $archiv = $this->erstelleArchiv($admin, Exportauswahl::schluessel());
        $pfad = sys_get_temp_dir() . '/dm-fotos-' . $unique . (str_ends_with($archiv['name'], '.gz') ? '.tar.gz' : '.tar');
        file_put_contents($pfad, $archiv['body']);
        $eintraege = $this->archivEintraege($pfad);
        unlink($pfad);

        $this->assertArrayHasKey("storage-horses/horse_test_{$unique}.jpg", $eintraege);
        $this->assertSame("jpeg-{$unique}", $eintraege["storage-horses/horse_test_{$unique}.jpg"]);
        $this->assertArrayNotHasKey('storage-horses/.gitkeep', $eintraege);
        $manifest = json_decode($eintraege['manifest.json'], true);
        $this->assertSame(3, $manifest['format']);
        $this->assertGreaterThanOrEqual(1, $manifest['horses_count']);

        // Nach dem Export: das Foto verschwindet (muss zurückkommen), ein
        // Foto nur auf dem Ziel entsteht (muss in die Sicherung wandern).
        unlink($foto);
        file_put_contents($nurZiel, 'nur-auf-dem-ziel');

        $name = $this->legeAb($archiv['body'], 'fotos');
        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
        $this->assertStringContainsString('Pferdefotos (storage/horses)', $preview->body);
        $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
            'csrf_token' => $preview->formField('csrf_token') ?? '',
            'datei' => $name,
            'bestaetigt' => '1',
        ] + $this->pflichtwahl($preview));
        $this->assertSame('/login?import=fertig', $apply->location(), "Import fehlgeschlagen, Body: {$apply->body}");

        $this->assertFileExists($foto, 'Pferdefoto nach dem Vollimport nicht wiederhergestellt');
        $this->assertSame("jpeg-{$unique}", file_get_contents($foto));
        $this->assertFileDoesNotExist($nurZiel, 'Ein Foto, das nur das Ziel hatte, ist nach dem Vollimport noch da');
        $gesichert = glob($this->stageDir() . "/ersetzte-dateien-*/storage-horses/horse_nurziel_{$unique}.jpg") ?: [];
        $this->assertCount(1, $gesichert, 'Das entfernte Foto liegt nicht in der Sicherung');
        $this->assertSame('nur-auf-dem-ziel', file_get_contents($gesichert[0]));
        $this->assertFileExists($this->horsesDir() . '/.gitkeep', '.gitkeep des Kerns wurde entfernt');

        // Audit N1: Beide Schutzdateien stehen, horses/.htaccess in der
        // eingebauten Fassung, denn es gab keine Vorlage.
        $this->assertFileExists($this->frameworkRoot() . '/public/uploads/.htaccess');
        $this->assertFileExists($schutz, 'public/uploads/horses/.htaccess nach dem Vollimport nicht wiederhergestellt');
        $inhalt = (string) file_get_contents($schutz);
        $this->assertStringContainsString('Wiederhergestellt nach einem Datenmigrations-Import (#366)', $inhalt);
        $this->assertStringContainsString('Require all denied', $inhalt);
    }

    /**
     * Teilarchiv mit Pferdefotos: zusammenführen. Das gleichnamige Foto des
     * Ziels wird überschrieben und vorher gesichert, ein Foto nur auf dem Ziel
     * bleibt.
     */
    public function testTeilarchivFuehrtPferdefotosZusammen(): void {
        $admin = $this->aktiviertesAddon();
        $unique = uniqid();
        $foto = $this->horsesDir() . "/horse_merge_{$unique}.jpg";
        $nurZiel = $this->horsesDir() . "/horse_merge_nurziel_{$unique}.jpg";
        $this->aufzuraeumen[] = $foto;
        $this->aufzuraeumen[] = $nurZiel;
        file_put_contents($foto, 'stand-aus-dem-archiv');

        $archiv = $this->erstelleArchiv($admin, ['pferde', Exportauswahl::GRUPPE_DATEIEN]);
        $name = $this->legeAb($archiv['body'], 'fotos-teil');
        file_put_contents($foto, 'neuerer-stand-des-ziels');
        file_put_contents($nurZiel, 'nur-auf-dem-ziel');

        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
        $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
            'csrf_token' => $preview->formField('csrf_token') ?? '',
            'datei' => $name,
            'bestaetigt' => '1',
        ] + $this->pflichtwahl($preview));
        $this->assertSame('/plugin/datenmigration/uebersicht?hinweis=importiert', $apply->location(),
            "Teilimport fehlgeschlagen, Body: {$apply->body}");

        $this->assertSame('stand-aus-dem-archiv', file_get_contents($foto));
        $this->assertSame('nur-auf-dem-ziel', file_get_contents($nurZiel), 'Das Teilarchiv hat ein Foto nur des Ziels angefasst');
        $gesichert = glob($this->stageDir() . "/ersetzte-dateien-*/storage-horses/horse_merge_{$unique}.jpg") ?: [];
        $this->assertCount(1, $gesichert, 'Kein Rückweg für das überschriebene Foto');
        $this->assertSame('neuerer-stand-des-ziels', file_get_contents($gesichert[0]));
    }

    /**
     * storage-horses/ geht durch dieselbe Pfad- und Namensprüfung wie
     * uploads/: Traversal und ausführbare Endungen brechen ab, bevor sich
     * etwas ändert. Eine Punktdatei (.gitkeep) wird dagegen still verworfen -
     * ein fremd gebautes Archiv mit ihr darf nicht scheitern.
     */
    public function testStorageHorsesPfadhaertung(): void {
        $admin = $this->aktiviertesAddon();
        $unique = uniqid();
        $quelle = $this->exportiere($admin, ['pferde']);
        $kopf = [['manifest.json', json_encode($quelle['manifest'])], ['database.sql', $quelle['sql']]];

        $boese = [
            "storage-horses/../../public/dm-trav-{$unique}.jpg" => 'Unzulässiger Pfad',
            "storage-horses/shell-{$unique}.php.jpg" => 'Ausführbare Dateiendung',
        ];
        // Falls die Prüfung versagt, sollen die Dateien trotzdem nicht liegen bleiben.
        $this->aufzuraeumen[] = $this->horsesDir() . "/shell-{$unique}.php.jpg";
        $this->aufzuraeumen[] = $this->frameworkRoot() . "/public/dm-trav-{$unique}.jpg";
        $this->aufzuraeumen[] = $this->frameworkRoot() . "/var/public/dm-trav-{$unique}.jpg";
        foreach ($boese as $eintrag => $meldung) {
            $name = $this->baueArchiv('fotos-boese', array_merge($kopf, [[$eintrag, 'x']]));
            $sicherungenVorher = $this->sicherungen();
            $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'datei' => $name,
                'bestaetigt' => '1',
                'abhaengige' => 'stehen_lassen',
            ]);
            $this->assertStringContainsString('nichts verändert', $apply->body, $eintrag);
            $this->assertStringContainsString($meldung, $apply->body, $eintrag);
            $this->assertSame($sicherungenVorher, $this->sicherungen(), "{$eintrag}: Sicherung geschrieben");
            $this->assertFalse($this->wartungAktiv());
        }
        foreach (['/public', '/var', '/storage', '/storage/horses'] as $ort) {
            $this->assertSame([], glob($this->frameworkRoot() . $ort . "/*{$unique}*") ?: [], "Datei unter {$ort} geschrieben");
        }
        $this->assertDirectoryDoesNotExist($this->stageDir() . '/horses-neu', 'Nebenverzeichnis nicht aufgeräumt');

        $ok = $this->horsesDir() . "/horse_ok_{$unique}.jpg";
        $this->aufzuraeumen[] = $ok;
        $name = $this->baueArchiv('fotos-gitkeep', array_merge($kopf, [
            ['storage-horses/.gitkeep', ''],
            ["storage-horses/horse_ok_{$unique}.jpg", 'ok'],
        ]));
        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
        $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
            'csrf_token' => $preview->formField('csrf_token') ?? '',
            'datei' => $name,
            'bestaetigt' => '1',
        ] + $this->pflichtwahl($preview));
        $this->assertSame('/plugin/datenmigration/uebersicht?hinweis=importiert', $apply->location(),
            "Archiv mit storage-horses/.gitkeep abgewiesen, Body: {$apply->body}");
        $this->assertSame('ok', file_get_contents($ok));
        $this->assertFileExists($this->horsesDir() . '/.gitkeep');
    }

    // -- Zugangsdaten und APP_KEY (Audit M25) --------------------------------

    private const EXPORTPASSWORT = 'Umzugs-Passwort-2026!';

    /**
     * Setzt eine Einstellung verschlüsselt - mit demselben APP_KEY wie der
     * Server (bootstrap.php/Umgebung), direkt in der Datenbank. Liefert den
     * Chiffretext.
     */
    private function setzeVerschluesselt(string $schluessel, string $klar): string {
        if (!defined('APP_KEY') && is_string(getenv('APP_KEY')) && getenv('APP_KEY') !== '') {
            define('APP_KEY', getenv('APP_KEY'));
        }
        $db = Database::getInstance();
        if (!array_key_exists($schluessel, $this->einstellungenVorher)) {
            $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
            $stmt->execute([$schluessel]);
            $vorher = $stmt->fetchColumn();
            $this->einstellungenVorher[$schluessel] = $vorher === false ? null : (string) $vorher;
        }
        $wert = Crypto::encrypt($klar);
        $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$schluessel, $wert]);
        return $wert;
    }

    private function einstellung(string $schluessel): ?string {
        $stmt = Database::getInstance()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$schluessel]);
        $wert = $stmt->fetchColumn();
        return $wert === false ? null : (string) $wert;
    }

    /**
     * Ein Chiffrat im Format von Crypto, aber unter einem ANDEREN Schlüssel -
     * so, wie es aus einer Quelle mit fremdem APP_KEY käme. Von Hand gebaut,
     * weil Crypto::getKey() sich nicht übersteuern lässt.
     */
    private static function fremdesChiffrat(string $klar): string {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($klar, 'aes-256-gcm', hash('sha256', 'fremd', true), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $ct);
    }

    /**
     * Baut aus einem Export das Archiv einer Quelle mit FREMDEM APP_KEY:
     * anderer Fingerabdruck, die Einstellung im Dump unter fremdem Schlüssel.
     * geheimnisse.json bleibt die des echten Exports.
     *
     * @param callable(array<string, mixed>):array<string, mixed>|null $manifestAendern
     */
    private function fremdesArchiv(string $body, string $chiffrat, ?callable $manifestAendern = null): string {
        $pfad = sys_get_temp_dir() . '/dm-fremd-' . uniqid() . (str_starts_with($body, "\x1f\x8b") ? '.tar.gz' : '.tar');
        file_put_contents($pfad, $body);
        $eintraege = $this->archivEintraege($pfad);
        unlink($pfad);

        $manifest = json_decode($eintraege['manifest.json'], true);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $manifest['app_key_fingerabdruck']);
        $manifest['app_key_fingerabdruck'] = str_repeat('0', 64);
        if ($manifestAendern !== null) {
            $manifest = $manifestAendern($manifest);
        }
        $sql = str_replace($chiffrat, self::fremdesChiffrat('fremd-geheim'), $eintraege['database.sql'], $ersetzt);
        $this->assertSame(1, $ersetzt, 'Chiffrat nicht im Dump gefunden');

        $neu = [['manifest.json', (string) json_encode($manifest)], ['database.sql', $sql]];
        if (isset($eintraege['geheimnisse.json'])) {
            $neu[] = ['geheimnisse.json', $eintraege['geheimnisse.json']];
        }
        return $this->baueArchiv('fremder-schluessel', $neu);
    }

    /** @param array<string, string> $zusatz */
    private function wendeAn(\Tests\Support\HttpClient $admin, string $name, array $zusatz = []): \Tests\Support\HttpResponse {
        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
        return $admin->post('/plugin/datenmigration/import/anwenden', [
            'csrf_token' => $preview->formField('csrf_token') ?? '',
            'datei' => $name,
            'bestaetigt' => '1',
        ] + $zusatz + $this->pflichtwahl($preview));
    }

    /**
     * Gleicher APP_KEY: Das Manifest trägt den Fingerabdruck und nennt die
     * verschlüsselten Einstellungen (nur Namen); die Rundreise braucht kein
     * Passwort, und das Passwort-Feld erscheint gar nicht.
     */
    public function testGleicherSchluesselBrauchtKeinExportpasswort(): void {
        $admin = $this->aktiviertesAddon();
        $unique = uniqid();
        $chiffrat = $this->setzeVerschluesselt('smtp_pass', "smtp-{$unique}");

        $quelle = $this->exportiere($admin, ['einstellungen']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $quelle['manifest']['app_key_fingerabdruck']);
        $this->assertContains('smtp_pass', $quelle['manifest']['verschluesselt']['settings']);
        $this->assertFalse($quelle['manifest']['geheimnisse']);
        $this->assertStringNotContainsString("smtp-{$unique}", $quelle['sql']);

        $name = $this->legeAb($quelle['body'], 'gleicher-schluessel');
        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
        $this->assertStringNotContainsString('anderen APP_KEY', $preview->body);
        $this->assertStringNotContainsString('name="export_passwort"', $preview->body);

        $this->setzeVerschluesselt('smtp_pass', 'zwischenstand');
        $apply = $this->wendeAn($admin, $name);
        $this->assertSame('/plugin/datenmigration/uebersicht?hinweis=importiert', $apply->location(), $apply->body);
        $this->assertSame($chiffrat, $this->einstellung('smtp_pass'));
        $this->assertSame("smtp-{$unique}", Crypto::decrypt((string) $this->einstellung('smtp_pass')));
    }

    /**
     * Fremder APP_KEY MIT Exportpasswort: Die Zugangsdaten reisen in
     * geheimnisse.json und werden beim Import mit dem Schlüssel des Ziels neu
     * verschlüsselt. Ein falsches Passwort ändert nichts - keine Sicherung,
     * keine Einstellung.
     */
    public function testFremderSchluesselMitExportpasswortVerschluesseltNeu(): void {
        $admin = $this->aktiviertesAddon();
        $unique = uniqid();
        $klar = "smtp-{$unique}";
        $chiffrat = $this->setzeVerschluesselt('smtp_pass', $klar);

        // Tippfehler in der Wiederholung und zu kurze Passwörter erzeugen kein Archiv.
        foreach ([[self::EXPORTPASSWORT, self::EXPORTPASSWORT . 'x'], ['kurz', 'kurz']] as [$pw, $wdh]) {
            $form = $admin->get('/plugin/datenmigration/export');
            $abgewiesen = $admin->post('/plugin/datenmigration/export', [
                'csrf_token' => $form->formField('csrf_token') ?? '',
                'gruppen' => ['einstellungen'],
                'trotzdem' => '1',
                'export_passwort' => $pw,
                'export_passwort_wdh' => $wdh,
            ]);
            $this->assertStringStartsWith('/plugin/datenmigration/export?fehler=passwort', (string) $abgewiesen->location());
            $this->assertStringNotContainsString($pw, (string) $abgewiesen->location());
        }

        $archiv = $this->erstelleArchiv($admin, ['einstellungen'], [
            'export_passwort' => self::EXPORTPASSWORT,
            'export_passwort_wdh' => self::EXPORTPASSWORT,
        ]);
        $pfad = sys_get_temp_dir() . '/dm-geheim-' . $unique . (str_ends_with($archiv['name'], '.gz') ? '.tar.gz' : '.tar');
        file_put_contents($pfad, $archiv['body']);
        $eintraege = $this->archivEintraege($pfad);
        unlink($pfad);
        $this->assertArrayHasKey('geheimnisse.json', $eintraege);
        $this->assertTrue(json_decode($eintraege['manifest.json'], true)['geheimnisse']);
        foreach ($eintraege as $eintrag => $inhalt) {
            $this->assertStringNotContainsString($klar, $inhalt, "Klartext in {$eintrag}");
            $this->assertStringNotContainsString(self::EXPORTPASSWORT, $inhalt, "Exportpasswort in {$eintrag}");
        }

        $name = $this->fremdesArchiv($archiv['body'], $chiffrat);
        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
        $this->assertStringContainsString('anderen APP_KEY', $preview->body);
        $this->assertStringContainsString('<code>smtp_pass</code>', $preview->body);
        $this->assertStringContainsString('name="export_passwort"', $preview->body);
        $this->assertStringContainsString('Ohne Exportpasswort fortfahren', $preview->body);
        $this->assertStringContainsString('Import anwenden</button>', $preview->body);

        $sicherungenVorher = $this->sicherungen();
        $falsch = $this->wendeAn($admin, $name, ['export_passwort' => 'ganz-falsches-Passwort']);
        $this->assertStringContainsString('nichts verändert', $falsch->body);
        $this->assertStringContainsString('Exportpasswort falsch', $falsch->body);
        $this->assertSame($sicherungenVorher, $this->sicherungen(), 'Falsches Passwort: Sicherung geschrieben');
        $this->assertSame($chiffrat, $this->einstellung('smtp_pass'), 'Falsches Passwort: Einstellung verändert');

        $ohne = $this->wendeAn($admin, $name);
        $this->assertStringContainsString('nichts verändert', $ohne->body);
        $this->assertSame($chiffrat, $this->einstellung('smtp_pass'));

        $apply = $this->wendeAn($admin, $name, ['export_passwort' => self::EXPORTPASSWORT]);
        $this->assertSame('/plugin/datenmigration/uebersicht?hinweis=importiert', $apply->location(), $apply->body);
        $neu = (string) $this->einstellung('smtp_pass');
        $this->assertNotSame($chiffrat, $neu);
        $this->assertSame($klar, Crypto::decrypt($neu), 'smtp_pass nach dem Import nicht mit dem Schlüssel des Ziels lesbar');
    }

    /**
     * Fremder APP_KEY OHNE Exportpasswort: nur mit ausdrücklicher Zustimmung,
     * und dann wird nur geleert, was sich hier wirklich nicht entschlüsseln
     * lässt. Ein manipuliertes Manifest, das zusätzlich site_name als
     * "verschlüsselt" führt, leert den Seitennamen nicht.
     */
    public function testFremderSchluesselOhnePasswortLeertNurUnlesbareEinstellungen(): void {
        $admin = $this->aktiviertesAddon();
        $unique = uniqid();
        $chiffrat = $this->setzeVerschluesselt('smtp_pass', "smtp-{$unique}");
        $seitenname = $this->einstellung('site_name');
        $this->assertNotSame('', (string) $seitenname);

        $archiv = $this->erstelleArchiv($admin, ['einstellungen']);
        $name = $this->fremdesArchiv($archiv['body'], $chiffrat, static function (array $m): array {
            $m['verschluesselt']['settings'][] = 'site_name';
            return $m;
        });

        $preview = $admin->get('/plugin/datenmigration/import/pruefen?datei=' . urlencode($name));
        $this->assertStringContainsString('anderen APP_KEY', $preview->body);
        $this->assertStringContainsString('Ohne Exportpasswort fortfahren', $preview->body);
        $this->assertStringNotContainsString('name="export_passwort"', $preview->body);

        $sicherungenVorher = $this->sicherungen();
        $abgewiesen = $this->wendeAn($admin, $name);
        $this->assertStringContainsString('nichts verändert', $abgewiesen->body);
        $this->assertSame($sicherungenVorher, $this->sicherungen());
        $this->assertSame($chiffrat, $this->einstellung('smtp_pass'));

        $apply = $this->wendeAn($admin, $name, ['ohne_geheimnisse' => '1']);
        $this->assertSame('/plugin/datenmigration/uebersicht?hinweis=importiert', $apply->location(), $apply->body);
        $this->assertSame('', $this->einstellung('smtp_pass'), 'Nicht entschlüsselbares smtp_pass wurde nicht geleert');
        $this->assertSame($seitenname, $this->einstellung('site_name'), 'Das manipulierte Manifest hat site_name geleert');
    }
}
