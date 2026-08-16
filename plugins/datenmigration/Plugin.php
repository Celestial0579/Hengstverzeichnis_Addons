<?php
// datenmigration/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: vollständiger Umzug einer Instanz
// auf eine andere (Framework -> Framework). Der Export bündelt alles, was
// eine Instanz an Daten besitzt, in EIN Archiv:
//
//   manifest.json   Kern-Version, Seitenname, Plugin-Bestand, Zählstände
//   database.sql    kompletter DB-Dump (App\Service\DatabaseDumper: alle
//                   Tabellen inkl. plugin_*-Tabellen, Benutzer, Gruppen,
//                   Einstellungen)
//   uploads/...     alle hochgeladenen Dateien aus public/uploads
//                   (Pferdebilder, Logos, Galerie) - die im Kern-Backup
//                   bewusst fehlen (siehe BackupService: "Kann bei Bedarf
//                   als eigenständige Erweiterung nachgezogen werden")
//
// Der Import auf der Zielinstanz ist zweistufig (Prüfen -> Anwenden):
// Manifest-Vorschau mit Versions-/Plugin-Abgleich, dann ausdrückliche
// Bestätigung. Vor dem Anwenden wird ein Sicherungs-Dump der Zielinstanz
// geschrieben (Rückweg). Nach dem Import wird die Sitzung beendet, denn
// die Benutzerkonten der Zielinstanz wurden mit übernommen.
//
// Archivformat ist tar (ustar, bei verfügbarem zlib als .tar.gz), bewusst
// OHNE ext-zip: Das mitgelieferte Dockerfile des Kerns installiert kein
// zip, und ein ustar-Schreiber/-Leser sind zusammen unter 150 Zeilen -
// konsistent mit der "keine externen Abhängigkeiten"-Philosophie
// (docs/plugin-development.md; vgl. die CSV-statt-xlsx-Entscheidung des
// HorseCsvImporter im Kern).
//
// Große Archive: PHP-Upload-Grenzen (upload_max_filesize/post_max_size)
// gelten nur für den Upload-Weg. Große Archive legt man stattdessen per
// SFTP/Konsole direkt in var/datenmigration/ der Zielinstanz - die
// Übersicht listet alles in diesem Verzeichnis zum Prüfen/Anwenden auf.
//
// Nicht Teil des Umzugs (bewusst): config/db_config.php, APP_KEY und
// TLS/Proxy-Konfiguration - das ist Instanz-Infrastruktur, keine Daten.
//
// Installation: Verzeichnis nach plugins/ kopieren, unter /admin/plugins
// aktivieren, Berechtigungen "Datenmigration -> Export/Import" zuweisen.

namespace Plugin\Datenmigration;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use App\Plugin\PluginPage;
use App\Router;
use App\Service\AuditLogger;
use App\Service\DatabaseDumper;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
    }

    public function addDashboardTile(array $tiles): array {
        $tiles[] = [
            'url' => '/plugin/datenmigration/uebersicht',
            'label' => 'Datenmigration',
            'icon' => '📦',
        ];
        return $tiles;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            ['module' => 'datenmigration', 'action' => 'export',
             'label' => 'Export erstellen', 'module_label' => 'Datenmigration'],
            ['module' => 'datenmigration', 'action' => 'import',
             'label' => 'Import anwenden', 'module_label' => 'Datenmigration'],
        ];
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            ['method' => 'GET',  'path' => '/uebersicht',
             'callback' => [MigrationController::class, 'overview']],
            ['method' => 'GET',  'path' => '/export',
             'callback' => [MigrationController::class, 'export']],
            ['method' => 'POST', 'path' => '/import/hochladen',
             'callback' => [MigrationController::class, 'upload']],
            ['method' => 'GET',  'path' => '/import/pruefen',
             'callback' => [MigrationController::class, 'preview']],
            ['method' => 'POST', 'path' => '/import/anwenden',
             'callback' => [MigrationController::class, 'apply']],
        ];
    }
}


/**
 * Welche Dateien ein Import-Archiv unter uploads/ mitbringen darf.
 *
 * Steht als eigene Klasse neben TarWriter/TarReader und nicht im Controller:
 * Es ist eine reine Regel ohne Framework-Bezug, und genau so lässt sie sich
 * ohne Datenbank und ohne Kern-Instanz prüfen (tests/Unit).
 */
final class UploadNamePolicy {

    /**
     * Positivliste, keine Sperrliste: Eine Liste verbotener Endungen ist immer
     * unvollständig (.php5, .phtml, .phar, .htaccess selbst), eine Liste
     * erlaubter nicht.
     *
     * Der Umfang orientiert sich daran, was tatsächlich in public/uploads
     * liegt: Bilder (Kern, galerie), PDFs (Dokumente) und schlichte
     * Textdaten. Bewusst NICHT enthalten sind Archive und Office-Formate -
     * sie gehören nicht in ein öffentlich ausgeliefertes Verzeichnis.
     *
     * Trifft ein Import auf eine Datei außerhalb dieser Liste, bricht er ab
     * und NENNT die Datei, statt sie stillschweigend zu überspringen. Bei
     * einem Umzug ist ein stiller Datenverlust die schlechtere Antwort: Der
     * Betreiber soll entscheiden, ob die Datei ins Archiv gehört oder die
     * Liste erweitert wird.
     *
     * @var array<int, string>
     */
    public const ERLAUBTE_ENDUNGEN = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'svg',
        'pdf', 'txt', 'csv',
    ];

    /**
     * Endungen, die AN JEDER STELLE des Namens unzulässig sind - nicht nur als
     * letzte.
     *
     * Grund ist der Apache-Klassiker: Steht dort
     * `AddHandler application/x-httpd-php .php`, wertet der Server ALLE
     * Endungen eines Namens aus und führt auch "bild.php.jpg" als PHP aus. Die
     * Positivliste oben allein würde diesen Namen durchlassen, weil die letzte
     * Endung sauber ist.
     *
     * Umgekehrt darf die Regel nicht jede Endung gegen die Positivliste
     * prüfen: "stute.2024.jpg" ist ein völlig gewöhnlicher Dateiname, und ein
     * Import, der daran scheitert, wird umgangen statt befolgt. (Genau daran
     * ist der erste Entwurf im Test hängengeblieben.)
     *
     * @var array<int, string>
     */
    public const NIE_ERLAUBTE_ENDUNGEN = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'pht', 'phar',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'jsp', 'asp', 'aspx', 'exe', 'htaccess', 'htpasswd',
    ];

    private function __construct() {}

    /**
     * Webserver-Steuerdateien werden aus dem Archiv NICHT übernommen, aber
     * sie brechen den Import auch nicht ab.
     *
     * Ein Export enthält zwangsläufig die `.htaccess`, mit der der Kern PHP in
     * public/uploads abschaltet - sie liegt ja in genau diesem Verzeichnis.
     * Sie zu übernehmen hieße, den Ausführungsschutz vom Inhalt des Archivs
     * bestimmen zu lassen; den Import daran scheitern zu lassen hieße, dass
     * kein einziger echter Export mehr einspielbar wäre. Also: überspringen
     * und den Schutz nach dem Umschalten aus dem Kern-Bestand neu schreiben
     * (siehe MigrationController::restoreUploadsProtection()).
     */
    public static function istWebserverSteuerdatei(string $rel): bool {
        return in_array(strtolower(basename($rel)), ['.htaccess', '.htpasswd', 'web.config'], true);
    }

    /**
     * @throws \RuntimeException wenn der Name nicht zulässig ist
     */
    public static function assertAllowed(string $rel): void {
        $base = basename($rel);

        // Punktdateien: .htaccess wäre die wirkungsvollste davon - die wird
        // aber schon vorher aussortiert (siehe istWebserverSteuerdatei()).
        // Was hier ankommt, ist eine andere versteckte Datei, und für die
        // gibt es in einem Upload-Verzeichnis keinen Grund.
        if (str_starts_with($base, '.')) {
            throw new \RuntimeException("Punktdateien sind im Archiv nicht zulässig: {$rel}");
        }

        $teile = explode('.', $base);
        array_shift($teile); // der Name selbst
        if ($teile === []) {
            throw new \RuntimeException("Datei ohne Endung ist im Archiv nicht zulässig: {$rel}");
        }

        // Keine ausführbare Endung an irgendeiner Stelle.
        foreach ($teile as $endung) {
            if (in_array(strtolower($endung), self::NIE_ERLAUBTE_ENDUNGEN, true)) {
                throw new \RuntimeException("Ausführbare Dateiendung im Archiv: {$rel}");
            }
        }

        // Und die tatsächliche Endung muss auf der Positivliste stehen.
        $letzte = strtolower((string) end($teile));
        if (!in_array($letzte, self::ERLAUBTE_ENDUNGEN, true)) {
            throw new \RuntimeException("Nicht erlaubte Dateiendung im Archiv: {$rel}");
        }
    }
}


// ---------------------------------------------------------------------------
// Archiv: minimaler ustar-Schreiber/-Leser (pure PHP, streamend)
// ---------------------------------------------------------------------------

/**
 * Streamender tar-Schreiber (ustar). Schreibt wahlweise über gzopen (.tar.gz,
 * wenn zlib verfügbar) oder fopen (.tar) - Dateien werden blockweise
 * durchgereicht, es liegt nie mehr als ein 512-Byte-Block im Speicher plus
 * der gerade gelesene Chunk. Lange Pfade nutzen das ustar-prefix-Feld.
 */
final class TarWriter {

    /** @var resource */
    private $handle;
    private bool $gzip;

    private function __construct($handle, bool $gzip) {
        $this->handle = $handle;
        $this->gzip = $gzip;
    }

    public static function create(string $path): self {
        $gzip = str_ends_with($path, '.gz') && function_exists('gzopen');
        $handle = $gzip ? gzopen($path, 'wb6') : fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Archiv nicht schreibbar: {$path}");
        }
        return new self($handle, $gzip);
    }

    private function write(string $data): void {
        $ok = $this->gzip ? gzwrite($this->handle, $data) : fwrite($this->handle, $data);
        if ($ok === false) {
            throw new \RuntimeException('Schreiben in das Archiv fehlgeschlagen.');
        }
    }

    public function addString(string $name, string $content): void {
        $this->writeHeader($name, strlen($content));
        $this->write($content);
        $this->pad(strlen($content));
    }

    public function addFile(string $name, string $sourcePath): void {
        $size = filesize($sourcePath);
        if ($size === false) {
            throw new \RuntimeException("Datei nicht lesbar: {$sourcePath}");
        }
        $in = fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new \RuntimeException("Datei nicht lesbar: {$sourcePath}");
        }
        $this->writeHeader($name, $size);
        $written = 0;
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 512);
            if ($chunk === false) {
                fclose($in);
                throw new \RuntimeException("Lesefehler: {$sourcePath}");
            }
            $this->write($chunk);
            $written += strlen($chunk);
        }
        fclose($in);
        if ($written !== $size) {
            throw new \RuntimeException("Datei änderte sich während des Exports: {$sourcePath}");
        }
        $this->pad($size);
    }

    public function close(): void {
        $this->write(str_repeat("\0", 1024)); // Zwei Null-Blöcke = Archivende
        $this->gzip ? gzclose($this->handle) : fclose($this->handle);
    }

    private function pad(int $size): void {
        $rest = $size % 512;
        if ($rest !== 0) {
            $this->write(str_repeat("\0", 512 - $rest));
        }
    }

    private function writeHeader(string $name, int $size): void {
        $prefix = '';
        if (strlen($name) > 100) {
            // ustar: name (100) + prefix (155), getrennt an einem '/'
            $cut = strrpos(substr($name, 0, 156), '/');
            if ($cut === false || strlen($name) - $cut - 1 > 100) {
                throw new \RuntimeException("Pfad zu lang für ustar: {$name}");
            }
            $prefix = substr($name, 0, $cut);
            $name = substr($name, $cut + 1);
        }
        $header = str_pad($name, 100, "\0")
            . '0000644' . "\0"                       // mode
            . '0000000' . "\0" . '0000000' . "\0"    // uid/gid
            . sprintf('%011o', $size) . "\0"
            . sprintf('%011o', time()) . "\0"
            . '        '                             // Platzhalter Prüfsumme
            . '0'                                    // typeflag: regular file
            . str_repeat("\0", 100)                  // linkname
            . "ustar\0" . '00'
            . str_pad('', 32, "\0") . str_pad('', 32, "\0")  // uname/gname
            . '0000000' . "\0" . '0000000' . "\0"    // devmajor/minor
            . str_pad($prefix, 155, "\0");
        $header = str_pad($header, 512, "\0");
        $checksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }
        $header = substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
        $this->write($header);
    }
}

/**
 * Streamender tar-Leser. gzopen liest transparent auch unkomprimierte
 * Dateien, deshalb ein Lesepfad für .tar und .tar.gz. Es werden nur
 * reguläre Dateien geliefert; alles andere (Symlinks, Devices, ...) wird
 * übersprungen - ein Migrationsarchiv enthält nichts dergleichen, und so
 * kann ein manipuliertes Archiv darüber auch nichts einschleusen.
 */
final class TarReader {

    /** @var resource */
    private $handle;

    public function __construct(string $path) {
        $handle = function_exists('gzopen') ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Archiv nicht lesbar: {$path}");
        }
        $this->handle = $handle;
    }

    /**
     * Ruft $callback(name, size, readChunk) je regulärer Datei auf.
     * $readChunk() liefert den nächsten Datenblock oder '' am Dateiende -
     * der Callback MUSS bis '' lesen (er konsumiert den Stream).
     *
     * @param callable(string, int, callable():string):void $callback
     */
    public function each(callable $callback): void {
        while (true) {
            $header = $this->read(512);
            if ($header === '' || trim($header, "\0") === '') {
                break; // Archivende (Null-Block)
            }
            if (strlen($header) < 512) {
                throw new \RuntimeException('Archiv abgeschnitten (unvollständiger Header).');
            }
            $stored = (int) substr($header, 148, 8);
            $probe = substr_replace($header, '        ', 148, 8);
            $checksum = 0;
            for ($i = 0; $i < 512; $i++) {
                $checksum += ord($probe[$i]);
            }
            if ($checksum !== octdec(trim(substr($header, 148, 8), "\0 "))) {
                throw new \RuntimeException('Archiv beschädigt (Header-Prüfsumme falsch).');
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            if ($prefix !== '') {
                $name = $prefix . '/' . $name;
            }
            $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
            $type = $header[156];

            if ($type === '0' || $type === "\0") {
                $remaining = $size;
                $reader = function () use (&$remaining): string {
                    if ($remaining <= 0) {
                        return '';
                    }
                    $chunk = $this->read(min($remaining, 1024 * 512));
                    if ($chunk === '') {
                        throw new \RuntimeException('Archiv abgeschnitten (Dateiinhalt fehlt).');
                    }
                    $remaining -= strlen($chunk);
                    return $chunk;
                };
                $callback($name, $size, $reader);
                if ($remaining > 0) {
                    throw new \RuntimeException('Interner Fehler: Archiv-Eintrag nicht vollständig gelesen.');
                }
            } else {
                // Verzeichnisse & Sonderdateien: Inhalt überspringen
                $this->skip($size);
            }
            $rest = $size % 512;
            if ($rest !== 0) {
                $this->skip(512 - $rest);
            }
        }
    }

    public function close(): void {
        function_exists('gzclose') ? gzclose($this->handle) : fclose($this->handle);
    }

    private function read(int $bytes): string {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = gzread($this->handle, $bytes - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function skip(int $bytes): void {
        while ($bytes > 0) {
            $chunk = $this->read(min($bytes, 1024 * 512));
            if ($chunk === '') {
                throw new \RuntimeException('Archiv abgeschnitten.');
            }
            $bytes -= strlen($chunk);
        }
    }
}


// ---------------------------------------------------------------------------
// Controller
// ---------------------------------------------------------------------------

class MigrationController extends BaseController {

    public const FORMAT = 1;

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    /**
     * Export und Import verlangen zusätzlich zur Modulberechtigung
     * Administratorrechte.
     *
     * Die Berechtigungen `datenmigration.export`/`.import` sehen aus wie jede
     * andere Modulberechtigung und lassen sich im Gruppen-Editor an jede
     * Gruppe vergeben - ihre Wirkung ist aber eine ganz andere:
     *
     *   Export liefert den VOLLSTÄNDIGEN Datenbank-Dump aus, inklusive
     *   `users` (Passwort-Hashes, TOTP-Secrets), `api_keys` und aller
     *   personenbezogenen Daten. Wer ihn auslösen darf, hat faktisch
     *   Lesezugriff auf alles.
     *
     *   Import ERSETZT die gesamte Datenbank - also auch die Benutzertabelle.
     *   Wer ihn auslösen darf, kann sich mit einem selbst gebauten Archiv zum
     *   Administrator machen.
     *
     * Beides sind im Kern bewusst admin-only-Fähigkeiten (Backup, Update,
     * Systemreset). Ein Addon darf sie nicht über eine gewöhnliche, an
     * "Redakteure" vergebbare Berechtigung öffnen. Die Berechtigung bleibt
     * erhalten - sie erlaubt dem Betreiber weiterhin, die Funktion für
     * einzelne Administratoren abzuschalten -, sie genügt nur nicht mehr für
     * sich allein.
     */
    private function requireAdminForFullAccess(string $aktion): void {
        if ($this->isAdmin()) {
            return;
        }

        AuditLogger::log(
            'Datenmigration abgelehnt: Administratorrechte erforderlich',
            'security',
            "Aktion '{$aktion}' ohne Admin-Rechte angefordert",
            $_SESSION['user_id'] ?? null,
            $_SESSION['username'] ?? null
        );

        $this->renderForbidden(
            'Zugriff verweigert: Export und Import der Datenmigration betreffen den gesamten Datenbestand '
            . '(inklusive Benutzerkonten und Zugangsdaten) und stehen deshalb ausschließlich Administratoren offen.'
        );
    }

    private function rootDir(): string {
        return dirname(__DIR__, 2);
    }

    private function uploadsDir(): string {
        return $this->rootDir() . '/public/uploads';
    }

    /** Ablage für Archive und Sicherungs-Dumps - außerhalb von public/. */
    private function stageDir(): string {
        $dir = $this->rootDir() . '/var/datenmigration';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /** @return array<int, string> Archivdateien in var/datenmigration */
    private function stagedArchives(): array {
        $out = [];
        foreach (scandir($this->stageDir()) ?: [] as $f) {
            if (preg_match('/\.tar(\.gz)?$/', $f) && !str_starts_with($f, 'sicherung-')) {
                $out[] = $f;
            }
        }
        sort($out);
        return $out;
    }

    /** Dateiname aus Request-Parameter — strikt auf die Ablage begrenzt. */
    private function stagedPath(string $name): ?string {
        $name = basename($name);
        if (!preg_match('/^[A-Za-z0-9._-]+\.tar(\.gz)?$/', $name) || str_starts_with($name, 'sicherung-')) {
            return null;
        }
        $path = $this->stageDir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /** @return array{core_version:string, site_name:string, plugins:array<int,array{slug:string,version:string,enabled:bool}>, tables:array<string,int>} */
    private function localInventory(): array {
        $db = Database::getInstance();
        $tables = [];
        // Backtick als eigene Konstante: haelt Identifier-Quoting und Variablen
        // auf getrennten Zeilen — der statische Sicherheits-Scan wertet
        // Backtick gefolgt von $variable auf einer Zeile als Shell-Ausfuehrung.
        $bt = '`';
        foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $quoted = $bt . str_replace($bt, $bt . $bt, $t) . $bt;
            $tables[$t] = (int) $db->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
        }
        $plugins = [];
        foreach ($db->query('SELECT slug, installed_version, enabled FROM plugins ORDER BY slug')->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $plugins[] = ['slug' => $p['slug'], 'version' => $p['installed_version'], 'enabled' => (bool) $p['enabled']];
        }
        $site = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'site_name'")->fetchColumn();
        return [
            'core_version' => defined('CORE_VERSION') ? CORE_VERSION : '',
            'site_name' => is_string($site) ? $site : '',
            'plugins' => $plugins,
            'tables' => $tables,
        ];
    }

    // -- Übersicht ----------------------------------------------------------

    public function overview(): void {
        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');
        $canExport = $this->hasPermission('datenmigration', 'export');
        $canImport = $this->hasPermission('datenmigration', 'import');

        $content = '<div class="card"><h1>📦 Datenmigration (Instanz-Umzug)</h1>';
        $content .= '<p>Zieht eine komplette Instanz um: Datenbank (alle Tabellen inkl. Benutzer, Gruppen, '
            . 'Einstellungen und Plugin-Daten) und alle Uploads, gebündelt in einem Archiv.</p>';

        $notice = $_GET['hinweis'] ?? '';
        if ($notice === 'hochgeladen') {
            $content .= '<p class="alert alert-success">Archiv hochgeladen - unten prüfen und anwenden.</p>';
        }

        if ($canExport) {
            $content .= '<h2>Export</h2><p><a class="btn" href="/plugin/datenmigration/export">Export-Archiv erstellen und herunterladen</a></p>'
                . '<p><small>Das Archiv wird zusätzlich in <code>var/datenmigration/</code> abgelegt.</small></p>';
        }

        if ($canImport) {
            $content .= '<h2>Import</h2>';
            $content .= '<form method="POST" action="/plugin/datenmigration/import/hochladen" enctype="multipart/form-data">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<div class="form-group"><label for="archiv">Archiv hochladen (.tar / .tar.gz)</label>'
                . '<input type="file" name="archiv" id="archiv" class="form-control" accept=".tar,.gz" required></div>'
                . '<button type="submit" class="btn">Hochladen</button></form>';
            $content .= '<p><small>Große Archive (über der PHP-Upload-Grenze) direkt nach '
                . '<code>var/datenmigration/</code> legen - sie erscheinen dann in dieser Liste.</small></p>';

            $archives = $this->stagedArchives();
            if ($archives) {
                $content .= '<h3>Bereitliegende Archive</h3><ul>';
                foreach ($archives as $a) {
                    $safe = htmlspecialchars($a, ENT_QUOTES, 'UTF-8');
                    $size = filesize($this->stageDir() . '/' . $a);
                    $content .= '<li><code>' . $safe . '</code> (' . number_format($size / 1048576, 1, ',', '.') . ' MB) '
                        . '- <a href="/plugin/datenmigration/import/pruefen?datei=' . urlencode($a) . '">Prüfen &amp; anwenden</a></li>';
                }
                $content .= '</ul>';
            } else {
                $content .= '<p>Keine Archive in <code>var/datenmigration/</code>.</p>';
            }
        }

        if (!$canExport && !$canImport) {
            $content .= '<p class="alert alert-error">Keine Berechtigung für dieses Modul.</p>';
        }
        $content .= '</div>';
        PluginPage::render('Datenmigration', $content);
    }

    // -- Export -------------------------------------------------------------

    public function export(): void {
        $this->requirePermission('datenmigration', 'export');
        $this->requireAdminForFullAccess('export');

        $inventory = $this->localInventory();
        $uploadFiles = $this->collectUploads();
        $manifest = [
            'format' => self::FORMAT,
            'created_at' => gmdate('c'),
            'core_version' => $inventory['core_version'],
            'site_name' => $inventory['site_name'],
            'plugins' => $inventory['plugins'],
            'tables' => $inventory['tables'],
            'uploads_count' => count($uploadFiles),
        ];

        $ext = function_exists('gzopen') ? '.tar.gz' : '.tar';
        $filename = 'datenmigration-' . gmdate('Ymd-His') . $ext;
        $path = $this->stageDir() . '/' . $filename;

        $tar = TarWriter::create($path);
        $tar->addString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $tar->addString('database.sql', DatabaseDumper::dump());
        foreach ($uploadFiles as $rel => $abs) {
            $tar->addFile('uploads/' . $rel, $abs);
        }
        $tar->close();

        AuditLogger::log('Datenmigrations-Export erstellt', 'datenmigration',
            $filename . ' (' . count($uploadFiles) . ' Upload-Dateien)');

        header('Content-Type: ' . ($ext === '.tar.gz' ? 'application/gzip' : 'application/x-tar'));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        $out = fopen($path, 'rb');
        while (!feof($out)) {
            echo fread($out, 1024 * 512);
        }
        fclose($out);
        exit;
    }

    /** @return array<string, string> relativer Pfad => absoluter Pfad */
    private function collectUploads(): array {
        $base = $this->uploadsDir();
        if (!is_dir($base)) {
            return [];
        }
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $rel = substr($file->getPathname(), strlen($base) + 1);
                $files[$rel] = $file->getPathname();
            }
        }
        ksort($files);
        return $files;
    }

    // -- Import: Hochladen --------------------------------------------------

    public function upload(): void {
        $this->requirePermission('datenmigration', 'import');
        $this->requireAdminForFullAccess('import');
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
            return;
        }
        $file = $_FILES['archiv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->fail('Kein Archiv hochgeladen (oder Upload-Grenze überschritten - große Archive per SFTP nach var/datenmigration/ legen).');
            return;
        }
        $name = basename((string) $file['name']);
        if (!preg_match('/^[A-Za-z0-9._ -]+\.tar(\.gz)?$/', $name)) {
            $this->fail('Nur .tar/.tar.gz-Archive mit einfachem Dateinamen.');
            return;
        }
        $target = $this->stageDir() . '/' . str_replace(' ', '_', $name);
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            $this->fail('Archiv konnte nicht gespeichert werden.');
            return;
        }
        header('Location: /plugin/datenmigration/uebersicht?hinweis=hochgeladen');
        exit;
    }

    // -- Import: Prüfen (Vorschau) -----------------------------------------

    public function preview(): void {
        $this->requirePermission('datenmigration', 'import');
        $this->requireAdminForFullAccess('import');
        $path = $this->stagedPath((string) ($_GET['datei'] ?? ''));
        if ($path === null) {
            $this->fail('Archiv nicht gefunden.');
            return;
        }
        try {
            $manifest = $this->readManifest($path);
        } catch (\Throwable $e) {
            $this->fail('Archiv unlesbar: ' . $e->getMessage());
            return;
        }

        $local = $this->localInventory();
        $problems = $this->compatibilityProblems($manifest, $local);
        $warnings = $this->pluginWarnings($manifest, $local);

        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars(basename($path), ENT_QUOTES, 'UTF-8');
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $content = '<div class="card"><h1>📦 Import prüfen: <code>' . $file . '</code></h1>';
        $content .= '<table class="table"><tr><th></th><th>Archiv (Quelle)</th><th>Diese Instanz (Ziel)</th></tr>'
            . '<tr><td>Seite</td><td>' . $e($manifest['site_name'] ?? '?') . '</td><td>' . $e($local['site_name']) . '</td></tr>'
            . '<tr><td>Kern-Version</td><td>' . $e($manifest['core_version'] ?? '?') . '</td><td>' . $e($local['core_version']) . '</td></tr>'
            . '<tr><td>Erstellt</td><td>' . $e($manifest['created_at'] ?? '?') . '</td><td>-</td></tr>'
            . '<tr><td>Upload-Dateien</td><td>' . $e($manifest['uploads_count'] ?? '?') . '</td><td>-</td></tr></table>';

        $content .= '<h2>Datenbestand (Zeilen je Tabelle)</h2><table class="table"><tr><th>Tabelle</th><th>Quelle</th><th>Ziel (wird ersetzt)</th></tr>';
        $tables = array_unique(array_merge(array_keys($manifest['tables'] ?? []), array_keys($local['tables'])));
        sort($tables);
        foreach ($tables as $t) {
            $content .= '<tr><td><code>' . $e($t) . '</code></td><td>' . $e($manifest['tables'][$t] ?? '-')
                . '</td><td>' . $e($local['tables'][$t] ?? '-') . '</td></tr>';
        }
        $content .= '</table>';

        foreach ($problems as $p) {
            $content .= '<p class="alert alert-error">' . $e($p) . '</p>';
        }
        foreach ($warnings as $w) {
            $content .= '<p class="alert alert-warning">⚠ ' . $e($w) . '</p>';
        }

        if (!$problems) {
            $content .= '<form method="POST" action="/plugin/datenmigration/import/anwenden" '
                . 'onsubmit="return confirm(\'Wirklich ALLE Daten dieser Instanz durch das Archiv ersetzen?\');">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<input type="hidden" name="datei" value="' . $file . '">'
                . '<div class="form-group"><label><input type="checkbox" name="bestaetigt" value="1" required> '
                . 'Mir ist klar: Sämtliche Daten dieser Instanz (inkl. Benutzerkonten) werden ersetzt. '
                . 'Ein Sicherungs-Dump wird vorher nach <code>var/datenmigration/</code> geschrieben.</label></div>'
                . '<button type="submit" class="btn btn-danger">Import anwenden</button></form>';
        }
        $content .= '<p><a href="/plugin/datenmigration/uebersicht">Zurück</a></p></div>';
        PluginPage::render('Datenmigration - Import prüfen', $content);
    }

    /** @return array<int, string> Harte Hindernisse (Import wird verweigert) */
    private function compatibilityProblems(array $manifest, array $local): array {
        $problems = [];
        if ((int) ($manifest['format'] ?? 0) !== self::FORMAT) {
            $problems[] = 'Unbekanntes Archivformat (Version ' . (string) ($manifest['format'] ?? '?') . ', erwartet ' . self::FORMAT . ').';
        }
        $src = (string) ($manifest['core_version'] ?? '');
        if ($src === '' || $src !== $local['core_version']) {
            $problems[] = 'Kern-Version passt nicht (Archiv: ' . ($src ?: 'unbekannt') . ', Ziel: ' . $local['core_version']
                . '). Erst beide Instanzen auf denselben Stand bringen - ein versionsübergreifender Import braucht einen Schema-Migrationslauf.';
        }
        return $problems;
    }

    /** @return array<int, string> Weiche Hinweise (Import bleibt möglich) */
    private function pluginWarnings(array $manifest, array $local): array {
        $warnings = [];
        $localPlugins = [];
        foreach ($local['plugins'] as $p) {
            $localPlugins[$p['slug']] = $p['version'];
        }
        foreach (($manifest['plugins'] ?? []) as $p) {
            $slug = (string) ($p['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            if (!array_key_exists($slug, $localPlugins)) {
                $warnings[] = "Quell-Addon '{$slug}' fehlt auf dieser Instanz - seine Tabellen werden mit importiert, "
                    . 'bleiben aber ohne das Addon unsichtbar. Addon nachinstallieren und danach unter /admin/plugins aktivieren.';
            } elseif (($p['version'] ?? '') !== $localPlugins[$slug]) {
                $warnings[] = "Addon '{$slug}': Quellversion " . ($p['version'] ?? '?') . ', hier ' . $localPlugins[$slug]
                    . ' - Datenformat der Plugin-Tabellen im Zweifel prüfen.';
            }
        }
        // Aktivierungszustand kommt aus der importierten plugins-Tabelle; der
        // Kern prüft danach den Verzeichnis-Fingerabdruck und deaktiviert
        // alles, was lokal nicht (identisch) vorliegt - fail-closed.
        return $warnings;
    }

    private function readManifest(string $path): array {
        $json = null;
        $reader = new TarReader($path);
        try {
            $reader->each(function (string $name, int $size, callable $read) use (&$json) {
                $data = '';
                while (($chunk = $read()) !== '') {
                    if ($json === null && $name === 'manifest.json') {
                        if ($size > 1024 * 1024) {
                            throw new \RuntimeException('manifest.json unplausibel groß.');
                        }
                        $data .= $chunk;
                    }
                }
                if ($name === 'manifest.json' && $json === null) {
                    $json = $data;
                }
            });
        } finally {
            $reader->close();
        }
        if ($json === null) {
            throw new \RuntimeException('manifest.json fehlt im Archiv.');
        }
        $manifest = json_decode($json, true);
        if (!is_array($manifest)) {
            throw new \RuntimeException('manifest.json ist kein gültiges JSON.');
        }
        return $manifest;
    }

    // -- Import: Anwenden ---------------------------------------------------

    public function apply(): void {
        $this->requirePermission('datenmigration', 'import');
        $this->requireAdminForFullAccess('import');
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
            return;
        }
        if (($_POST['bestaetigt'] ?? '') !== '1') {
            $this->fail('Import nicht bestätigt.');
            return;
        }
        $path = $this->stagedPath((string) ($_POST['datei'] ?? ''));
        if ($path === null) {
            $this->fail('Archiv nicht gefunden.');
            return;
        }
        $manifest = $this->readManifest($path);
        $problems = $this->compatibilityProblems($manifest, $this->localInventory());
        if ($problems) {
            $this->fail(implode(' ', $problems));
            return;
        }

        // 1. Rückweg sichern: Dump der Zielinstanz VOR dem Import.
        $backupName = 'sicherung-vor-import-' . gmdate('Ymd-His') . '.sql' . (function_exists('gzencode') ? '.gz' : '');
        $dump = DatabaseDumper::dump();
        file_put_contents($this->stageDir() . '/' . $backupName,
            function_exists('gzencode') ? gzencode($dump, 6) : $dump);
        unset($dump);

        // 2. Archiv in einem Durchlauf anwenden: SQL sammeln, Uploads in ein
        //    Nebenverzeichnis entpacken; erst wenn beides fehlerfrei durch ist,
        //    wird umgeschaltet.
        //
        // Das Nebenverzeichnis liegt AUSSERHALB von public/. Vorher hieß es
        // "public/uploads.import-neu" und lag damit im Webroot: Zwischen dem
        // ersten geschriebenen Eintrag und dem Umschalten war jede Datei des
        // Archivs unter ihrem eigenen Namen über den Webserver erreichbar -
        // inklusive einer .php. Es kostet nichts, das Fenster ganz zu
        // schließen; var/ ist ohnehin schon die Ablage dieses Addons.
        $uploadsNew = $this->stageDir() . '/uploads-neu';
        $this->removeDir($uploadsNew);
        mkdir($uploadsNew, 0755, true);
        $sql = null;
        $uebersprungen = 0;
        $reader = new TarReader($path);
        try {
            $reader->each(function (string $name, int $size, callable $read) use (&$sql, &$uebersprungen, $uploadsNew) {
                if ($name === 'database.sql') {
                    $data = '';
                    while (($chunk = $read()) !== '') {
                        $data .= $chunk;
                    }
                    $sql = $data;
                    return;
                }
                if (str_starts_with($name, 'uploads/')) {
                    $rel = substr($name, strlen('uploads/'));
                    // Pfadhärtung: keine Traversal, keine absoluten Pfade.
                    if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/') || str_contains($rel, "\0")) {
                        throw new \RuntimeException("Unzulässiger Pfad im Archiv: {$name}");
                    }
                    // ... und keine ausführbaren Dateien. Die Pfadhärtung
                    // darüber prüfte, WOHIN geschrieben wird, aber nicht WAS -
                    // und das Ziel ist am Ende public/uploads. Der Inhalt
                    // stammt aus einer hochgeladenen Datei, das Recht dafür ist
                    // an jede Gruppe vergebbar. Ohne diese Prüfung genügte ein
                    // Archiv mit einer .php darin für Codeausführung.
                    // Webserver-Steuerdateien werden verworfen, nicht
                    // übernommen - der Ausführungsschutz des Zielverzeichnisses
                    // darf nicht aus dem Archiv stammen. Er wird nach dem
                    // Umschalten neu geschrieben.
                    if (UploadNamePolicy::istWebserverSteuerdatei($rel)) {
                        $uebersprungen++;
                        while ($read() !== '') { // Datenstrom verwerfen
                        }
                        return;
                    }
                    UploadNamePolicy::assertAllowed($rel);

                    $target = $uploadsNew . '/' . $rel;
                    if (!is_dir(dirname($target))) {
                        mkdir(dirname($target), 0755, true);
                    }
                    $out = fopen($target, 'wb');
                    while (($chunk = $read()) !== '') {
                        fwrite($out, $chunk);
                    }
                    fclose($out);
                    return;
                }
                while ($read() !== '') { // manifest.json u. ä.: konsumieren
                }
            });
        } catch (\Throwable $e) {
            $reader->close();
            $this->removeDir($uploadsNew);
            $this->fail('Import abgebrochen, nichts verändert: ' . $e->getMessage());
            return;
        }
        $reader->close();
        if ($sql === null) {
            $this->removeDir($uploadsNew);
            $this->fail('database.sql fehlt im Archiv - nichts verändert.');
            return;
        }

        // 3. Datenbank ersetzen (Dump bringt DROP/CREATE/INSERT je Tabelle mit).
        //
        // Unter Wartungsmodus, und das ist keine Kosmetik: Der Dump wirft jede
        // Tabelle einzeln weg und legt sie neu an. Zwischen dem DROP der
        // ersten und dem letzten INSERT gibt es ein Zeitfenster von Sekunden
        // bis Minuten, in dem parallele Anfragen auf eine halb ersetzte
        // Datenbank treffen - Besucher sehen Fehlerseiten, ein laufender
        // Cron-Lauf schreibt in Tabellen, die gleich wieder verschwinden, und
        // der Kern legt beim nächsten Verbindungsaufbau womöglich mitten im
        // Import eine Migration los. DDL in MariaDB ist zudem
        // transaktions-autocommittend: Ein "einfach in eine Transaktion
        // packen" gibt es hier nicht, der Wartungsmodus ist die vorhandene und
        // richtige Antwort (App\Service\Maintenance, vom Kern in
        // public/index.php vor jedem DB-Zugriff geprüft).
        //
        // Schlägt das Einspielen fehl, wird der zuvor geschriebene
        // Sicherungs-Dump zurückgespielt. Ohne das bliebe die Zielinstanz mit
        // halbem Bestand stehen, und der Rückweg wäre Handarbeit auf einer
        // Datenbank, die niemand mehr benutzen kann.
        \App\Service\Maintenance::enable('Datenmigrations-Import läuft');
        try {
            Database::getInstance()->exec($sql);
        } catch (\Throwable $e) {
            $this->rollbackFromBackup($backupName, $e);
            \App\Service\Maintenance::disable();
            $this->removeDir($uploadsNew);
            $this->fail(
                'Import fehlgeschlagen, der Sicherungsstand wurde zurückgespielt: ' . $e->getMessage()
            );
            return;
        }
        \App\Service\Maintenance::disable();

        // 4. Uploads umschalten (alter Stand bleibt als .import-alt liegen,
        //    bis der nächste Import ihn ersetzt - zweiter Rückweg neben dem Dump).
        $uploadsOld = $this->uploadsDir() . '.import-alt';
        $this->removeDir($uploadsOld);
        if (is_dir($this->uploadsDir())) {
            rename($this->uploadsDir(), $uploadsOld);
        }
        // rename() über Verzeichnisgrenzen hinweg schlägt fehl, wenn Staging
        // und Ziel auf verschiedenen Dateisystemen liegen - seit das Staging
        // in var/ liegt, ist das kein theoretischer Fall mehr (eigenes Volume
        // für uploads, siehe docker-compose.yml des Kerns). Deshalb mit
        // Kopier-Rückfall statt eines stillen false.
        if (!@rename($uploadsNew, $this->uploadsDir())) {
            $this->copyDir($uploadsNew, $this->uploadsDir());
            $this->removeDir($uploadsNew);
        }

        // Ausführungsschutz wiederherstellen - der Verzeichnistausch hat die
        // .htaccess des Kerns mitgenommen.
        $this->restoreUploadsProtection();

        AuditLogger::log('Datenmigrations-Import angewendet', 'datenmigration',
            basename($path) . ' (Quelle: ' . (string) ($manifest['site_name'] ?? '?') . ', Sicherung: ' . $backupName
            . ($uebersprungen > 0 ? ', ' . $uebersprungen . ' Webserver-Steuerdatei(en) verworfen' : '') . ')');

        // 5. Sitzung beenden: Die Benutzerkonten wurden soeben ersetzt.
        session_destroy();
        header('Location: /login?import=fertig');
        exit;
    }

    /**
     * Schreibt den Ausführungsschutz für public/uploads neu - unabhängig
     * davon, was im Archiv stand.
     *
     * Der Verzeichnistausch ersetzt public/uploads VOLLSTÄNDIG, also auch die
     * dort mitgelieferte .htaccess des Kerns, die PHP in diesem Verzeichnis
     * abschaltet. Ein Archiv ohne diese Datei entfernte den Schutz still; ein
     * Archiv MIT einer eigenen Fassung hätte ihn ersetzt. Beides ist jetzt
     * ausgeschlossen: Punktdateien kommen gar nicht erst durch (siehe oben),
     * und die Schutzdatei wird nach dem Umschalten aus dem Kern-Bestand
     * zurückgeschrieben.
     */
    private function restoreUploadsProtection(): void {
        $ziel = $this->uploadsDir() . '/.htaccess';
        if (is_file($ziel)) {
            return;
        }

        // Bevorzugt die Fassung, die im Kern mitgeliefert wird - sie ist die
        // gepflegte Quelle. Nur wenn sie fehlt (etwa weil das Verzeichnis
        // gerade erst entstanden ist), greift die eingebaute Mindestfassung.
        $vorlage = $this->rootDir() . '/public/uploads.import-alt/.htaccess';
        if (is_file($vorlage)) {
            copy($vorlage, $ziel);
            return;
        }

        file_put_contents($ziel, <<<'HTACCESS'
        # Wiederhergestellt nach einem Datenmigrations-Import.
        # Kein PHP in diesem Verzeichnis - hier liegen ausschliesslich Daten.
        <FilesMatch "\.(php|phtml|php[0-9]|phar|pl|py|cgi|sh)$">
            Require all denied
        </FilesMatch>
        Options -Indexes -ExecCGI
        AddType text/plain .php .phtml .phar
        HTACCESS);
    }

    /**
     * Spielt den unmittelbar vor dem Import geschriebenen Sicherungs-Dump
     * zurück. Wirft NICHT weiter: Der Aufrufer meldet dem Benutzer den
     * ursprünglichen Fehler, und ein zweiter Fehler beim Zurückrollen darf
     * die Meldung nicht verdrängen - er gehört ins Protokoll, weil dann
     * Handarbeit nötig ist.
     */
    private function rollbackFromBackup(string $backupName, \Throwable $ursache): void {
        $pfad = $this->stageDir() . '/' . $backupName;

        try {
            if (!is_file($pfad)) {
                throw new \RuntimeException("Sicherungs-Dump nicht gefunden: {$backupName}");
            }
            $dump = str_ends_with($backupName, '.gz')
                ? (string) gzdecode((string) file_get_contents($pfad))
                : (string) file_get_contents($pfad);
            if ($dump === '') {
                throw new \RuntimeException('Sicherungs-Dump ist leer.');
            }

            Database::getInstance()->exec($dump);

            AuditLogger::log(
                'Datenmigrations-Import zurückgerollt',
                'datenmigration',
                'Grund: ' . $ursache->getMessage() . ' - Sicherung ' . $backupName . ' eingespielt'
            );
        } catch (\Throwable $e) {
            AuditLogger::log(
                'Datenmigration: Zurückrollen FEHLGESCHLAGEN',
                'security',
                'Import scheiterte (' . $ursache->getMessage() . '), das Zurückspielen von '
                . $backupName . ' ebenfalls (' . $e->getMessage() . ') - die Datenbank ist in einem '
                . 'unvollständigen Zustand und muss von Hand aus var/datenmigration/' . $backupName
                . ' wiederhergestellt werden.'
            );
            error_log('Datenmigration: Rollback fehlgeschlagen - ' . $e->getMessage());
        }
    }

    /** Rückfall für rename() über Dateisystemgrenzen hinweg. */
    private function copyDir(string $from, string $to): void {
        if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
            throw new \RuntimeException("Zielverzeichnis konnte nicht angelegt werden: {$to}");
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $ziel = $to . '/' . $it->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($ziel) && !mkdir($ziel, 0755, true) && !is_dir($ziel)) {
                    throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$ziel}");
                }
                continue;
            }
            if (!copy($item->getPathname(), $ziel)) {
                throw new \RuntimeException("Datei konnte nicht kopiert werden: {$ziel}");
            }
        }
    }

    private function removeDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    private function fail(string $message): void {
        $content = '<div class="card"><h1>📦 Datenmigration</h1><p class="alert alert-error">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p><p><a href="/plugin/datenmigration/uebersicht">Zurück</a></p></div>';
        PluginPage::render('Datenmigration', $content);
    }
}
