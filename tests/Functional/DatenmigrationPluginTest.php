<?php
// tests/Functional/DatenmigrationPluginTest.php

namespace Tests\Functional;

use App\Database;

require_once __DIR__ . '/../../plugins/datenmigration/Plugin.php';

use Plugin\Datenmigration\Exportauswahl;
use Plugin\Datenmigration\TarReader;

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
     * @return array{name:string, body:string}
     */
    private function erstelleArchiv(\Tests\Support\HttpClient $admin, array $gruppen): array {
        $form = $admin->get('/plugin/datenmigration/export');
        $this->assertSame(200, $form->statusCode);
        $antwort = $admin->post('/plugin/datenmigration/export', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'gruppen' => $gruppen,
            // Fehlende Gegenstücke werden in erstelleArchiv() bewusst
            // übergangen - die Warnseite hat einen eigenen Test.
            'trotzdem' => '1',
        ]);
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

    protected function tearDown(): void {
        foreach ($this->aufzuraeumen as $datei) {
            if (is_file($datei)) {
                unlink($datei);
            }
        }
        $this->aufzuraeumen = [];
        parent::tearDown();
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
        $this->assertSame(2, $manifest['format']);
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
            $csrf = $this->currentCsrfToken($editor);

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
        $this->assertSame(2, $manifest['format']);
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

        $apply = $admin->post('/plugin/datenmigration/import/anwenden', [
            'csrf_token' => $preview->formField('csrf_token') ?? '',
            'datei' => $stagedName,
            'bestaetigt' => '1',
        ]);
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
        $this->assertSame(200, $admin->get('/admin/plugins')->statusCode);

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
        ]);
        $this->assertSame(
            '/plugin/datenmigration/uebersicht?hinweis=importiert',
            $apply->location(),
            "Teilimport mit Dateien fehlgeschlagen, Body: {$apply->body}"
        );

        $this->assertSame('stand-aus-dem-archiv', file_get_contents($imArchiv));
        $this->assertTrue(file_exists($nurZiel), 'Der Teilimport hat eine Datei gelöscht, die nur das Ziel hatte');
        $this->assertSame('nur-auf-dem-ziel', file_get_contents($nurZiel));

        // Der Ausführungsschutz des Upload-Verzeichnisses steht weiterhin.
        $this->assertTrue(file_exists($this->frameworkRoot() . '/public/uploads/.htaccess'));

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
}
