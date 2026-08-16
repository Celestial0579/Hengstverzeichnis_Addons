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
use App\Plugin\PluginPage;
use App\Router;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        $this->ensureTable();
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
    }

    /**
     * Framework-Hook (#75): Der PluginManager ruft install() bei der
     * Aktivierung und nach einem Addon-Update genau einmal auf - das
     * DDL-Statement läuft damit nicht mehr in jedem Request.
     */
    public function install(): void {
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
     * Fallback für ältere Kerne ohne install()-Hook (#75) - bewusst OHNE
     * Marker-Datei: Der Kern gibt das Plugin-Verzeichnis über einen
     * Inhalts-Fingerabdruck frei, in den auch Dotfiles einfließen. Jede zur
     * Laufzeit dorthin geschriebene Datei änderte den Fingerabdruck, und der
     * Kern deaktivierte das Plugin als unfreigegeben verändert. Statt DDL
     * pro Request (siehe Issue) läuft deshalb nur noch eine billige
     * SELECT-Probe je Request; erst wenn sie fehlschlägt, legt install()
     * die Tabelle an. Auf Kernen mit install()-Hook existiert die Tabelle
     * ohnehin - dort bleibt es bei der Probe.
     */
    private function ensureTable(): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        try {
            Database::getInstance()->query('SELECT 1 FROM `plugin_gesundheitstests` LIMIT 1');
        } catch (\Throwable $e) {
            $this->install();
        }
        $checked = true;
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
        $html .= '<thead><tr style="text-align:left;border-bottom:2px solid var(--border-color);">'
            . '<th style="padding:0.4rem;">Test/Untersuchung</th><th style="padding:0.4rem;">Ergebnis</th>'
            . '<th style="padding:0.4rem;">Ausgestellt von</th><th style="padding:0.4rem;">Datum</th>'
            . '<th style="padding:0.4rem;">Dokument</th></tr></thead><tbody>';

        foreach ($entries as $row) {
            $html .= '<tr style="border-bottom:1px solid var(--border-color);">';
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
            // Serverseitige Pferdesuche für die Datalist im Formular (#74,
            // Muster Framework-Katalog): JSON, max. 50 Treffer, nur mit
            // gesundheitstests.manage (Konstruktor-Schutz des Controllers).
            ['method' => 'GET', 'path' => '/suche', 'callback' => [VerwaltungController::class, 'suche']],
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

    /** Treffer-Deckel der Datalist-Suche (#74). */
    private const SEARCH_LIMIT = 50;

    /** Einträge je Verwaltungsseite (#74). */
    private const ENTRIES_PER_PAGE = 50;

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('gesundheitstests', 'manage');
    }

    public function index(): void {
        $db = Database::getInstance();

        // Eintragsliste paginiert (#74): vorher lud die Seite die komplette
        // Eintragstabelle per JOIN ohne LIMIT und renderte jede Zeile ins HTML.
        $totalEntries = (int) $db->query('SELECT COUNT(*) FROM `plugin_gesundheitstests`')->fetchColumn();
        $pageCount = max(1, (int) ceil($totalEntries / self::ENTRIES_PER_PAGE));
        $page = min($pageCount, max(1, (int) ($_GET['seite'] ?? 1)));

        $entriesStmt = $db->prepare(
            'SELECT g.*, h.name AS horse_name
             FROM `plugin_gesundheitstests` g
             JOIN horses h ON h.id = g.horse_id
             ORDER BY g.issued_at DESC, g.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $entriesStmt->bindValue('limit', self::ENTRIES_PER_PAGE, PDO::PARAM_INT);
        $entriesStmt->bindValue('offset', ($page - 1) * self::ENTRIES_PER_PAGE, PDO::PARAM_INT);
        $entriesStmt->execute();
        $entries = $entriesStmt->fetchAll(PDO::FETCH_ASSOC);

        $csrfToken = Router::generateCsrfToken();

        // Die Seite rendert als Fragment im Framework-Layout
        // (App\Plugin\PluginPage, Addons#66) - Header, Navigation,
        // Theme-Umschalter, Markenfarben und style.css kommen zentral vom
        // Layout. Hier bleibt nur addon-spezifische Geometrie
        // (Formular-Raster), Farben ausschließlich über Theme-Variablen.
        $content = '<style>';
        $content .= '.gesundheitstests-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}';
        $content .= '.gesundheitstests-hint{color:var(--text-muted);font-size:0.85em;margin-top:0.3rem;}';
        $content .= '</style>';

        $content .= '<div class="card">';
        $content .= '<h1>🩺 DNA-/Gesundheitstest-Verwaltung</h1>';

        $content .= '<h2>Neuen Eintrag erfassen</h2>';
        $content .= '<form method="POST" action="/plugin/gesundheitstests/verwaltung/store" enctype="multipart/form-data">';
        $content .= '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';

        // Pferde-Auswahl als Suchfeld mit serverseitig nachgeladener
        // Vorschlagsliste statt eines Voll-<select> über den gesamten
        // Bestand (#74, Muster Framework-Katalog). Die gewählte ID landet
        // per JS im Hidden-Feld horse_id; ohne JavaScript löst store() den
        // getippten Text über resolveHorseId() auf.
        $content .= '<div class="form-group"><label for="horse_q">Pferd</label>'
            . '<input type="text" name="horse_q" id="horse_q" class="form-control" list="horse_q_liste" autocomplete="off"'
            . ' placeholder="Namen eintippen und Vorschlag auswählen …" required>'
            . '<datalist id="horse_q_liste"></datalist>'
            . '<input type="hidden" name="horse_id" id="horse_id" value="">'
            . '</div>';

        $content .= '<div class="gesundheitstests-row">';
        $content .= '<div class="form-group"><label for="test_type">Test-/Untersuchungsart</label>'
            . '<input type="text" name="test_type" id="test_type" class="form-control" required placeholder="z. B. DNA-Abstammungstest, Röntgen, Gesundheitszeugnis"></div>';
        $content .= '<div class="form-group"><label for="issued_at">Ausgestellt am</label><input type="date" name="issued_at" id="issued_at" class="form-control"></div>';
        $content .= '</div>';

        $content .= '<div class="form-group"><label for="issued_by">Ausgestellt von</label>'
            . '<input type="text" name="issued_by" id="issued_by" class="form-control" placeholder="z. B. Labor, Tierklinik"></div>';
        $content .= '<div class="form-group"><label for="result_summary">Ergebnis-Zusammenfassung</label>'
            . '<textarea name="result_summary" id="result_summary" class="form-control" rows="3"></textarea></div>';

        $content .= '<div class="form-group"><label for="document">Dokument (PDF oder Bild, max. 10 MB)</label>'
            . '<input type="file" name="document" id="document" class="form-control" accept="application/pdf,image/jpeg,image/png,image/webp"></div>';
        $content .= '<p class="gesundheitstests-hint">Hochgeladene Dokumente werden außerhalb des Webroots gespeichert und sind nur über die zugriffsgeschützte Download-Route erreichbar.</p>';

        $content .= '<div class="form-group"><label><input type="checkbox" name="is_public" value="1"> Öffentlich sichtbar (Opt-in - Gesundheitsdaten erscheinen nie automatisch)</label></div>';

        $content .= '<p><button type="submit" class="btn">Speichern</button></p>';
        $content .= '</form>';

        // Progressive Enhancement der Pferdesuche: lädt Vorschläge von
        // /plugin/gesundheitstests/suche und mappt das gewählte Label auf die
        // ID im Hidden-Feld. Ohne fetch()/JS greift der No-JS-Fallback in
        // store().
        $content .= '<script>
(function () {
    var input = document.getElementById("horse_q");
    var hidden = document.getElementById("horse_id");
    var list = document.getElementById("horse_q_liste");
    if (!input || !hidden || !list || typeof window.fetch !== "function") { return; }

    var byLabel = {};
    var timer = null;

    function sync() {
        hidden.value = Object.prototype.hasOwnProperty.call(byLabel, input.value)
            ? String(byLabel[input.value])
            : "";
    }

    function loadSuggestions() {
        var q = input.value.trim();
        if (q === "") { return; }
        fetch("/plugin/gesundheitstests/suche?q=" + encodeURIComponent(q))
            .then(function (res) { return res.json(); })
            .then(function (items) {
                if (!Array.isArray(items)) { return; }
                byLabel = {};
                list.textContent = "";
                items.forEach(function (item) {
                    byLabel[item.label] = item.id;
                    var option = document.createElement("option");
                    option.value = item.label;
                    list.appendChild(option);
                });
                sync();
            })
            .catch(function () { /* Suche nicht erreichbar - der No-JS-Fallback greift beim Absenden */ });
    }

    input.addEventListener("input", function () {
        sync();
        if (timer) { clearTimeout(timer); }
        timer = setTimeout(loadSuggestions, 200);
    });
    input.addEventListener("change", sync);
})();
</script>';

        $content .= '<h2>Erfasste Einträge</h2>';
        $content .= '<table><thead><tr><th>Pferd</th><th>Test</th><th>Datum</th><th>Öffentlich</th><th>Dokument</th><th></th></tr></thead><tbody>';
        foreach ($entries as $row) {
            $content .= '<tr>';
            $content .= '<td>' . htmlspecialchars((string) $row['horse_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . htmlspecialchars((string) $row['test_type'], ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . htmlspecialchars((string) ($row['issued_at'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . (!empty($row['is_public']) ? 'ja' : 'nein') . '</td>';
            $content .= '<td>';
            if (!empty($row['file_name'])) {
                $content .= '<a href="/plugin/gesundheitstests/download?id=' . (int) $row['id'] . '">'
                    . htmlspecialchars((string) ($row['file_original_name'] ?: 'Dokument'), ENT_QUOTES, 'UTF-8') . '</a>';
            } else {
                $content .= '–';
            }
            $content .= '</td>';
            // Falschbefund, geprueft: Das ist HTML, kein SQL - und $page
            // ist keine Nutzereingabe mehr. Es entsteht aus
            // min($pageCount, max(1, (int) $_GET['seite'])), also
            // Ganzzahl-Cast plus Klemmung auf einen gueltigen
            // Seitenbereich. Semgreps Taint-Analyse erkennt den
            // (int)-Cast nicht als Bereinigung; derselbe Grund steht im
            // Kern an PublicController.php.
            // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string
            $content .= '<td><form method="POST" action="/plugin/gesundheitstests/verwaltung/delete" style="margin:0;" onsubmit="return confirm(\'Eintrag (inkl. Dokument) wirklich löschen?\');">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="seite" value="' . $page . '">'
                . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                . '<button type="submit" class="btn btn-secondary" style="color:var(--danger-fg);">Löschen</button></form></td>';
            $content .= '</tr>';
        }
        if (empty($entries)) {
            $content .= '<tr><td colspan="6">Noch keine Einträge erfasst.</td></tr>';
        }
        $content .= '</tbody></table>';

        // Blätter-Leiste (#74): erscheint erst, wenn es mehr als eine Seite
        // gibt - die Ein-Seiten-Ansicht bleibt unverändert schlank.
        if ($pageCount > 1) {
            $content .= '<p class="gesundheitstests-hint">';
            if ($page > 1) {
                $content .= '<a class="btn btn-secondary" href="/plugin/gesundheitstests/verwaltung?seite=' . ($page - 1) . '">&laquo; Zurück</a> ';
            }
            $content .= 'Seite ' . $page . ' von ' . $pageCount . ' (' . $totalEntries . ' Einträge)';
            if ($page < $pageCount) {
                $content .= ' <a class="btn btn-secondary" href="/plugin/gesundheitstests/verwaltung?seite=' . ($page + 1) . '">Weiter &raquo;</a>';
            }
            $content .= '</p>';
        }

        $content .= '<p><a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a></p>';
        $content .= '</div>';

        PluginPage::render('Gesundheitstests verwalten', $content);
    }

    public function store(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $db = Database::getInstance();

        // ID aus dem Hidden-Feld (per JS gesetzt), sonst No-JS-Fallback:
        // den getippten Text des Suchfelds serverseitig auflösen (#74). In
        // beiden Fällen wird gegen den Bestand geprüft - eine frei erfundene
        // ID liefe sonst in den FOREIGN-KEY-Fehler statt in einen Redirect.
        $horseId = !empty($_POST['horse_id']) ? (int) $_POST['horse_id'] : null;
        if ($horseId !== null) {
            $stmt = $db->prepare('SELECT id FROM horses WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$horseId]);
            $horseId = $stmt->fetchColumn() !== false ? $horseId : null;
        } else {
            $horseQ = trim($_POST['horse_q'] ?? '');
            if ($horseQ !== '') {
                $horseId = $this->resolveHorseId($db, $horseQ);
            }
        }

        $testType = trim($_POST['test_type'] ?? '');

        if ($horseId && $testType !== '') {
            $upload = $this->handleDocumentUpload($_FILES['document'] ?? null);

            $stmt = $db->prepare(
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

        // Zurück auf die Listenseite, von der gelöscht wurde (#74); index()
        // klemmt einen inzwischen zu großen Wert selbst auf die letzte Seite.
        $seite = (int) ($_POST['seite'] ?? 1);
        header('Location: /plugin/gesundheitstests/verwaltung' . ($seite > 1 ? '?seite=' . $seite : ''));
        exit;
    }

    /**
     * Serverseitige Pferdesuche für die Datalist (#74, Muster
     * Framework-Katalog): JSON-Liste {id, label} über eine Teilstring-Suche
     * im Namen, höchstens SEARCH_LIMIT Treffer. Läuft über denselben
     * Konstruktor-Schutz (gesundheitstests.manage) wie die Verwaltungsseite.
     */
    public function suche(): void {
        header('Content-Type: application/json; charset=utf-8');

        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q === '') {
            echo json_encode([]);
            exit;
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT id, name, birth_year FROM horses
             WHERE deleted_at IS NULL AND name LIKE ?
             ORDER BY name ASC, id ASC LIMIT ' . self::SEARCH_LIMIT
        );
        $stmt->execute(['%' . addcslashes($q, '\\%_') . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Label-Duplikate (gleicher Name und Jahrgang) eindeutig machen: Die
        // Datalist mappt Label -> ID, und der No-JS-Fallback löst das
        // "[#id]"-Suffix in resolveHorseId() wieder auf.
        $labelCounts = [];
        foreach ($rows as $row) {
            $label = self::horseLabel($row);
            $labelCounts[$label] = ($labelCounts[$label] ?? 0) + 1;
        }

        $result = [];
        foreach ($rows as $row) {
            $label = self::horseLabel($row);
            if ($labelCounts[$label] > 1) {
                $label .= ' [#' . (int) $row['id'] . ']';
            }
            $result[] = ['id' => (int) $row['id'], 'label' => $label];
        }

        echo json_encode($result);
        exit;
    }

    /**
     * No-JS-Fallback: löst den getippten Text des Suchfelds serverseitig zu
     * einer Pferde-ID auf - nur bei eindeutigem Treffer, sonst null.
     */
    private function resolveHorseId(PDO $db, string $q): ?int {
        // 1) Eindeutigkeits-Suffix aus der Vorschlagsliste: "… [#123]"
        if (preg_match('/\[#(\d+)\]\s*$/', $q, $m)) {
            $stmt = $db->prepare('SELECT id FROM horses WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([(int) $m[1]]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int) $id : null;
        }

        // 2) Label-Form "Name (Jahrgang)"
        if (preg_match('/^(.*\S)\s*\((\d{3,4})\)$/u', $q, $m)) {
            $stmt = $db->prepare('SELECT id FROM horses WHERE deleted_at IS NULL AND name = ? AND birth_year = ? LIMIT 2');
            $stmt->execute([$m[1], (int) $m[2]]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) === 1) {
                return (int) $ids[0];
            }
            if (count($ids) > 1) {
                return null; // mehrdeutig - nur die "[#id]"-Variante ist eindeutig
            }
            // kein Treffer: unten als wörtlichen Namen weiterversuchen
        }

        // 3) exakter Name, sofern eindeutig
        $stmt = $db->prepare('SELECT id FROM horses WHERE deleted_at IS NULL AND name = ? LIMIT 2');
        $stmt->execute([$q]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    /**
     * Anzeige-/Suchlabel eines Pferdes: "Name (Jahrgang)" bzw. nur "Name" -
     * dieselbe Form, die früher die <select>-Optionen trugen.
     *
     * @param array<string, mixed> $h
     */
    private static function horseLabel(array $h): string {
        $label = (string) $h['name'];
        if (!empty($h['birth_year'])) {
            $label .= ' (' . (int) $h['birth_year'] . ')';
        }
        return $label;
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

        // Der Verwaltungs-Zweig ist bewusst an eine angemeldete Sitzung
        // gebunden (Framework#218): Die Gast-Gruppe `public` ist im Kern frei
        // editierbar - ein Rechte-Fehlgriff dort (etwa "Berechtigungen
        // kopieren von Administrator" schreibt `gesundheitstests.manage` in
        // die Gast-Gruppe) darf diese Route für Anonyme nie öffnen. Für
        // Besucher ohne Sitzung zählt deshalb ausschließlich der
        // Opt-in-Pfad $publiclyVisible.
        $managerAccess = isset($_SESSION['user_id'])
            && $this->hasPermission('gesundheitstests', 'manage');

        if (!$entry || empty($entry['file_name'])
            || (!$publiclyVisible && !$managerAccess)) {
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
