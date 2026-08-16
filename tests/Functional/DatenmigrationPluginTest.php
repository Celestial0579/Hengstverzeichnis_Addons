<?php
// tests/Functional/DatenmigrationPluginTest.php

namespace Tests\Functional;

use App\Database;

require_once __DIR__ . '/../../plugins/datenmigration/Plugin.php';

use Plugin\Datenmigration\TarReader;

/**
 * End-to-End-Test für plugins/datenmigration: exportiert die laufende
 * Instanz als Archiv, verfälscht danach gezielt Daten und Uploads, spielt
 * das Archiv über den Import-Weg zurück und prüft, dass beides wieder auf
 * dem exportierten Stand ist. Damit ist der komplette Umzugsweg
 * (Quelle -> Archiv -> Ziel) gegen eine echte Instanz durchgespielt -
 * Quelle und Ziel sind hier dieselbe Instanz, was die Rundreise erlaubt,
 * ohne eine zweite Datenbank aufzubauen.
 */
class DatenmigrationPluginTest extends FunctionalTestCase {

    private const SLUG = 'datenmigration';

    private function frameworkRoot(): string {
        return \FRAMEWORK_VENDOR_DIR;
    }

    public function testExportImportRundreise(): void {
        $admin = $this->authenticatedClient();

        // Aktivieren.
        $toggle = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggle->location());

        // Dashboard-Kachel und Übersichtsseite.
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString('/plugin/datenmigration/uebersicht', $dashboard->body);
        $overview = $admin->get('/plugin/datenmigration/uebersicht');
        $this->assertSame(200, $overview->statusCode);
        $this->assertStringContainsString('Export-Archiv erstellen', $overview->body);

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

        // Export: Archiv herunterladen und inhaltlich prüfen.
        $export = $admin->get('/plugin/datenmigration/export');
        $this->assertSame(200, $export->statusCode);
        $disposition = (string) $export->header('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertMatchesRegularExpression('/datenmigration-\d{8}-\d{6}\.tar(\.gz)?/', $disposition);

        $archivePath = sys_get_temp_dir() . '/dm-functional-' . $unique
            . (str_contains($disposition, '.tar.gz') ? '.tar.gz' : '.tar');
        file_put_contents($archivePath, $export->body);

        $entries = [];
        $reader = new TarReader($archivePath);
        $reader->each(function (string $name, int $size, callable $read) use (&$entries) {
            $data = '';
            while (($chunk = $read()) !== '') {
                $data .= $chunk;
            }
            $entries[$name] = $data;
        });
        $reader->close();

        $this->assertArrayHasKey('manifest.json', $entries);
        $this->assertArrayHasKey('database.sql', $entries);
        $this->assertArrayHasKey('uploads/' . $uploadRel, $entries);
        $manifest = json_decode($entries['manifest.json'], true);
        $this->assertIsArray($manifest);
        $this->assertSame(1, $manifest['format']);
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

        // Anwenden: ersetzt Datenbank und Uploads, beendet die Sitzung.
        $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
            'csrf_token' => $preview->formField('csrf_token') ?? '',
            'datei' => $stagedName,
            'bestaetigt' => '1',
        ]);
        $this->assertSame('/login?import=fertig', $apply->location(), "Import fehlgeschlagen, Body: {$apply->body}");

        // Rundreise geprüft: Pferdename wieder original, Upload-Datei zurück.
        $restored = $db->query("SELECT COUNT(*) FROM horses WHERE name = " . $db->quote($horseName))->fetchColumn();
        $this->assertSame(1, (int) $restored, 'Pferd nach Import nicht auf Export-Stand');
        $gone = $db->query("SELECT COUNT(*) FROM horses WHERE name = 'VERFAELSCHT-{$unique}'")->fetchColumn();
        $this->assertSame(0, (int) $gone, 'Verfälschter Stand hat den Import überlebt');
        $this->assertTrue(file_exists($uploadAbs), 'Upload-Datei nach Import nicht wiederhergestellt');
        $this->assertSame("upload-inhalt-{$unique}", file_get_contents($uploadAbs));

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
}
