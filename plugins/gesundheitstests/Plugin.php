<?php
// gesundheitstests/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#15. Erfasst genetische
// Testergebnisse, Röntgenbefunde und Gesundheitszeugnisse strukturiert pro
// Pferd (statt unstrukturiert im freien description-Feld), inkl. optionalem
// Dokument-Upload. Gesundheitsdaten sind sensibel: jeder Eintrag ist
// standardmäßig NICHT öffentlich und muss explizit freigegeben werden
// (Opt-in), hochgeladene Dokumente liegen außerhalb des öffentlich
// erreichbaren Webroots und sind nur über eine zugriffsgeschützte
// Download-Route erreichbar.
//
// Installation (lokal im Framework-Repo):
//   cp -r gesundheitstests plugins/gesundheitstests
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// der gewünschten Gruppe unter /admin/groups die Berechtigung
// "Gesundheitstests -> Verwalten" zuweisen.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\Gesundheitstests;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use App\Router;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        $this->ensureTable();
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
    }

    private function ensureTable(): void {
        Database::getInstance()->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_gesundheitstests` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `horse_id` INT NOT NULL,
                `test_type` VARCHAR(100) NOT NULL,
                `result_summary` TEXT NULL DEFAULT NULL,
                `file_name` VARCHAR(100) NULL DEFAULT NULL,
                `file_original_name` VARCHAR(255) NULL DEFAULT NULL,
                `file_mime` VARCHAR(100) NULL DEFAULT NULL,
                `is_public` TINYINT(1) NOT NULL DEFAULT 0,
                `issued_by` VARCHAR(150) NULL DEFAULT NULL,
                `issued_at` DATE NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Ablageverzeichnis für hochgeladene Dokumente: bewusst AUSSERHALB des
     * Webroots (public/), damit Dateien nie direkt per URL abrufbar sind,
     * sondern ausschließlich über die zugriffsgeschützte Download-Route.
     */
    public static function storageDir(): string {
        return dirname(__DIR__, 2) . '/storage/plugin_gesundheitstests';
    }

    /**
     * Filter-Beispiel: zeigt auf der öffentlichen Detailseite ausschließlich
     * die explizit als öffentlich markierten Einträge (Opt-in, Standard aus) -
     * Gesundheitsdaten erscheinen nie automatisch.
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, test_type, result_summary, file_name, file_original_name, issued_by, issued_at
             FROM `plugin_gesundheitstests`
             WHERE horse_id = :id AND is_public = 1
             ORDER BY issued_at DESC, id DESC'
        );
        $stmt->execute(['id' => (int) $horse['id']]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($entries)) {
            return $sections;
        }

        $html = '<div style="margin-top:0.5rem;">';
        $html .= '<h3 style="margin-bottom:0.5rem;">🩺 DNA-/Gesundheitstests</h3>';
        $html .= '<table style="width:100%;border-collapse:collapse;">';
        $html .= '<thead><tr style="text-align:left;border-bottom:2px solid #ddd;">'
            . '<th style="padding:0.4rem;">Test/Untersuchung</th><th style="padding:0.4rem;">Ergebnis</th>'
            . '<th style="padding:0.4rem;">Ausgestellt von</th><th style="padding:0.4rem;">Datum</th>'
            . '<th style="padding:0.4rem;">Dokument</th></tr></thead><tbody>';

        foreach ($entries as $row) {
            $html .= '<tr style="border-bottom:1px solid #eee;">';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) $row['test_type'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['result_summary'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['issued_by'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['issued_at'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">';
            if (!empty($row['file_name'])) {
                $html .= '<a href="/plugin/gesundheitstests/download?id=' . (int) $row['id'] . '">'
                    . '📄 ' . htmlspecialchars((string) ($row['file_original_name'] ?: 'Dokument'), ENT_QUOTES, 'UTF-8') . '</a>';
            } else {
                $html .= '–';
            }
            $html .= '</td></tr>';
        }

        $html .= '</tbody></table></div>';

        $sections[] = $html;
        return $sections;
    }

    public function addDashboardTile(array $tiles): array {
        $tiles[] = [
            'url' => '/plugin/gesundheitstests/verwaltung',
            'label' => 'Gesundheitstests',
            'icon' => '🩺',
        ];
        return $tiles;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'gesundheitstests',
                'action' => 'manage',
                'label' => 'Verwalten',
                'module_label' => 'Gesundheitstests',
            ],
        ];
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            ['method' => 'GET', 'path' => '/verwaltung', 'callback' => [VerwaltungController::class, 'index']],
            ['method' => 'POST', 'path' => '/verwaltung/store', 'callback' => [VerwaltungController::class, 'store']],
            ['method' => 'POST', 'path' => '/verwaltung/delete', 'callback' => [VerwaltungController::class, 'delete']],
            ['method' => 'GET', 'path' => '/download', 'callback' => [DownloadController::class, 'serve']],
        ];
    }
}

/**
 * Admin-Verwaltung der Test-/Gesundheitsdokumente. Zugriffsschutz über die
 * selbst registrierte Berechtigung "gesundheitstests.manage", analog zum
 * zuchtschau-ergebnisse-Muster.
 */
class VerwaltungController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('gesundheitstests', 'manage');
    }

    public function index(): void {
        $db = Database::getInstance();

        $horses = $db->query(
            'SELECT id, name, birth_year FROM horses WHERE deleted_at IS NULL ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $entries = $db->query(
            'SELECT g.*, h.name AS horse_name
             FROM `plugin_gesundheitstests` g
             JOIN horses h ON h.id = g.horse_id
             ORDER BY g.issued_at DESC, g.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $csrfToken = Router::generateCsrfToken();

        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Gesundheitstests verwalten</title>';
        echo '<style>
            body{font-family:sans-serif;padding:2rem;max-width:900px;margin:0 auto;}
            table{width:100%;border-collapse:collapse;margin-top:1.5rem;}
            th,td{text-align:left;padding:0.5rem;border-bottom:1px solid #ddd;font-size:0.9rem;}
            label{display:block;margin-top:0.8rem;font-weight:bold;font-size:0.9rem;}
            input,select,textarea{width:100%;padding:0.4rem;margin-top:0.2rem;}
            input[type=checkbox]{width:auto;}
            .row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
            .hint{color:#666;font-size:0.85em;margin-top:0.3rem;}
        </style></head><body>';
        echo '<h1>🩺 DNA-/Gesundheitstest-Verwaltung</h1>';

        echo '<h2>Neuen Eintrag erfassen</h2>';
        echo '<form method="POST" action="/plugin/gesundheitstests/verwaltung/store" enctype="multipart/form-data">';
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';

        echo '<label for="horse_id">Pferd</label><select name="horse_id" id="horse_id" required>';
        echo '<option value="">– auswählen –</option>';
        foreach ($horses as $h) {
            echo '<option value="' . (int) $h['id'] . '">'
                . htmlspecialchars($h['name'] . ($h['birth_year'] ? ' (' . $h['birth_year'] . ')' : ''), ENT_QUOTES, 'UTF-8')
                . '</option>';
        }
        echo '</select>';

        echo '<div class="row">';
        echo '<div><label for="test_type">Test-/Untersuchungsart</label>'
            . '<input type="text" name="test_type" id="test_type" required placeholder="z. B. DNA-Abstammungstest, Röntgen, Gesundheitszeugnis"></div>';
        echo '<div><label for="issued_at">Ausgestellt am</label><input type="date" name="issued_at" id="issued_at"></div>';
        echo '</div>';

        echo '<label for="issued_by">Ausgestellt von</label><input type="text" name="issued_by" id="issued_by" placeholder="z. B. Labor, Tierklinik">';
        echo '<label for="result_summary">Ergebnis-Zusammenfassung</label><textarea name="result_summary" id="result_summary" rows="3"></textarea>';

        echo '<label for="document">Dokument (PDF oder Bild, max. 10 MB)</label>'
            . '<input type="file" name="document" id="document" accept="application/pdf,image/jpeg,image/png,image/webp">';
        echo '<p class="hint">Hochgeladene Dokumente werden außerhalb des Webroots gespeichert und sind nur über die zugriffsgeschützte Download-Route erreichbar.</p>';

        echo '<label><input type="checkbox" name="is_public" value="1"> Öffentlich sichtbar (Opt-in - Gesundheitsdaten erscheinen nie automatisch)</label>';

        echo '<p><button type="submit" style="margin-top:1.2rem;padding:0.6rem 1.2rem;">Speichern</button></p>';
        echo '</form>';

        echo '<h2>Erfasste Einträge</h2>';
        echo '<table><thead><tr><th>Pferd</th><th>Test</th><th>Datum</th><th>Öffentlich</th><th>Dokument</th><th></th></tr></thead><tbody>';
        foreach ($entries as $row) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars((string) $row['horse_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['test_type'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['issued_at'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . (!empty($row['is_public']) ? 'ja' : 'nein') . '</td>';
            echo '<td>';
            if (!empty($row['file_name'])) {
                echo '<a href="/plugin/gesundheitstests/download?id=' . (int) $row['id'] . '">'
                    . htmlspecialchars((string) ($row['file_original_name'] ?: 'Dokument'), ENT_QUOTES, 'UTF-8') . '</a>';
            } else {
                echo '–';
            }
            echo '</td>';
            echo '<td><form method="POST" action="/plugin/gesundheitstests/verwaltung/delete" style="margin:0;" onsubmit="return confirm(\'Eintrag (inkl. Dokument) wirklich löschen?\');">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                . '<button type="submit" style="color:#dc3545;">Löschen</button></form></td>';
            echo '</tr>';
        }
        if (empty($entries)) {
            echo '<tr><td colspan="6">Noch keine Einträge erfasst.</td></tr>';
        }
        echo '</tbody></table>';

        echo '<p style="margin-top:2rem;"><a href="/admin">Zurück zum Dashboard</a></p>';
        echo '</body></html>';
    }

    public function store(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $horseId = !empty($_POST['horse_id']) ? (int) $_POST['horse_id'] : null;
        $testType = trim($_POST['test_type'] ?? '');

        if ($horseId && $testType !== '') {
            $upload = $this->handleDocumentUpload($_FILES['document'] ?? null);

            $stmt = Database::getInstance()->prepare(
                'INSERT INTO `plugin_gesundheitstests`
                    (horse_id, test_type, result_summary, file_name, file_original_name, file_mime, is_public, issued_by, issued_at)
                 VALUES (:horse_id, :test_type, :result_summary, :file_name, :file_original_name, :file_mime, :is_public, :issued_by, :issued_at)'
            );
            $stmt->execute([
                'horse_id' => $horseId,
                'test_type' => $testType,
                'result_summary' => trim($_POST['result_summary'] ?? '') ?: null,
                'file_name' => $upload['name'] ?? null,
                'file_original_name' => $upload['original'] ?? null,
                'file_mime' => $upload['mime'] ?? null,
                'is_public' => !empty($_POST['is_public']) ? 1 : 0,
                'issued_by' => trim($_POST['issued_by'] ?? '') ?: null,
                'issued_at' => !empty($_POST['issued_at']) ? $_POST['issued_at'] : null,
            ]);
        }

        header('Location: /plugin/gesundheitstests/verwaltung');
        exit;
    }

    public function delete(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        if ($id) {
            $db = Database::getInstance();
            $stmt = $db->prepare('SELECT file_name FROM `plugin_gesundheitstests` WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                if (!empty($row['file_name'])) {
                    // file_name ist ein selbst generierter basename ohne Pfadanteile
                    // (siehe handleDocumentUpload) - kein Traversal möglich.
                    $path = Plugin::storageDir() . '/' . basename((string) $row['file_name']);
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
                $deleteStmt = $db->prepare('DELETE FROM `plugin_gesundheitstests` WHERE id = :id');
                $deleteStmt->execute(['id' => $id]);
            }
        }

        header('Location: /plugin/gesundheitstests/verwaltung');
        exit;
    }

    /**
     * Gleiches Validierungsmuster wie HorseController::handleImageUpload() im
     * Kern (echte MIME-Prüfung per finfo statt Dateiendung, Zufallsname),
     * erweitert um PDF und mit Ablage außerhalb des Webroots.
     *
     * @return array{name:string, original:string, mime:string}|null
     */
    private function handleDocumentUpload(?array $file): ?array {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) === 0) {
            return null;
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            return null;
        }

        $allowedMimeTypes = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        if (!isset($allowedMimeTypes[$mime])) {
            return null;
        }

        $storageDir = Plugin::storageDir();
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0750, true);
        }

        $filename = 'gtest_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $allowedMimeTypes[$mime];
        if (!move_uploaded_file($file['tmp_name'], $storageDir . '/' . $filename)) {
            return null;
        }

        return [
            'name' => $filename,
            'original' => basename((string) $file['name']),
            'mime' => $mime,
        ];
    }
}

/**
 * Zugriffsgeschützte Auslieferung hochgeladener Dokumente. Öffentlich sind
 * ausschließlich Dokumente von als öffentlich markierten Einträgen zu
 * veröffentlichten Pferden - und auch nur, wenn die Gast-Gruppe Pferde sehen
 * darf (identisches Gating wie die Detailseite im Kern). Alle übrigen
 * Dokumente erfordern die Verwaltungs-Berechtigung; unbekannte, gelöschte und
 * nicht zugängliche IDs liefern eine identische 404, damit die Route kein
 * Existenz-Orakel wird.
 */
class DownloadController extends BaseController {

    public function serve(): void {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        $entry = null;
        if ($id) {
            $stmt = Database::getInstance()->prepare(
                'SELECT g.file_name, g.file_original_name, g.file_mime, g.is_public, h.is_published
                 FROM `plugin_gesundheitstests` g
                 JOIN horses h ON h.id = g.horse_id AND h.deleted_at IS NULL
                 WHERE g.id = ?'
            );
            $stmt->execute([$id]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        $publiclyVisible = $entry
            && !empty($entry['is_public'])
            && !empty($entry['is_published'])
            && $this->hasPermission('horses', 'view');

        if (!$entry || empty($entry['file_name'])
            || (!$publiclyVisible && !$this->hasPermission('gesundheitstests', 'manage'))) {
            $this->renderNotFound('Dokument nicht gefunden.');
        }

        $path = Plugin::storageDir() . '/' . basename((string) $entry['file_name']);
        if (!is_file($path)) {
            $this->renderNotFound('Dokument nicht gefunden.');
        }

        $originalName = (string) ($entry['file_original_name'] ?: 'dokument');
        // Nur ASCII-sicheren Dateinamen im Header verwenden.
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName) ?: 'dokument';

        header('Content-Type: ' . ($entry['file_mime'] ?: 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
