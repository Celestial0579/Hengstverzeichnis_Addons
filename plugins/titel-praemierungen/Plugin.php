<?php
// titel-praemierungen/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#81. Der v2-Altbestand führt je
// Pferd `titel[]`, `praemierungen[]` und `erfolge[]` als Listen - in Gen 3
// landeten diese Werte mangels Gegenstück bisher als Textblöcke in
// `horses.description` (nicht filterbar, nicht einzeln pflegbar). Dieses
// Addon ergänzt strukturierte Auszeichnungen (Art, Bezeichnung, Jahr,
// Kommentar) je Pferd und zeigt sie auf der öffentlichen Detailseite an;
// das Migrationstool kann die Altdaten damit strukturiert einspielen.
//
// Installation (lokal im Framework-Repo):
//   cp -r titel-praemierungen plugins/titel-praemierungen
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// der gewünschten Gruppe unter /admin/groups die Berechtigung
// "Titel & Prämierungen -> Verwalten" zuweisen.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\TitelPraemierungen;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use App\Plugin\PluginPage;
use App\Router;
use PDO;

class Plugin {

    /**
     * Anzeige-Beschriftungen je Art - zugleich die Whitelist für store():
     * Nur diese drei Schlüssel erreichen das ENUM in der Datenbank, ein
     * beliebiger POST-Wert würde sonst (je nach SQL-Mode) still zu ''
     * verstümmelt statt abgelehnt.
     */
    public const ART_LABELS = [
        'titel' => 'Titel',
        'praemierung' => 'Prämierung',
        'erfolg' => 'Erfolg',
    ];

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
            'CREATE TABLE IF NOT EXISTS `plugin_titel_praemierungen` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `horse_id` INT NOT NULL,
                `art` ENUM(\'titel\',\'praemierung\',\'erfolg\') NOT NULL,
                `bezeichnung` VARCHAR(200) NOT NULL,
                `jahr` SMALLINT UNSIGNED NULL DEFAULT NULL,
                `kommentar` TEXT NULL DEFAULT NULL,
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
            Database::getInstance()->query('SELECT 1 FROM `plugin_titel_praemierungen` LIMIT 1');
        } catch (\Throwable $e) {
            $this->install();
        }
        $checked = true;
    }

    /**
     * Filter: hängt die erfassten Auszeichnungen des Pferdes an die
     * öffentliche Detailseite an - gruppiert nach Art (Titel zuerst),
     * innerhalb der Art neueste Jahre oben. Zeigt nichts an, wenn noch
     * keine Auszeichnung erfasst ist (kein leerer Abschnitt).
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $horseId = (int) $horse['id'];

        $stmt = Database::getInstance()->prepare(
            'SELECT art, bezeichnung, jahr, kommentar
             FROM `plugin_titel_praemierungen`
             WHERE horse_id = :id
             ORDER BY FIELD(art, \'titel\', \'praemierung\', \'erfolg\'), jahr DESC, id DESC'
        );
        $stmt->execute(['id' => $horseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return $sections;
        }

        $html = '<div style="margin-top:0.5rem;">';
        $html .= '<h3 style="margin-bottom:0.5rem;">🏅 Titel &amp; Prämierungen</h3>';
        $html .= '<table style="width:100%;border-collapse:collapse;">';
        $html .= '<thead><tr style="text-align:left;border-bottom:2px solid var(--border-color);">'
            . '<th style="padding:0.4rem;">Art</th><th style="padding:0.4rem;">Bezeichnung</th>'
            . '<th style="padding:0.4rem;">Jahr</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $artLabel = self::ART_LABELS[$row['art']] ?? (string) $row['art'];
            $html .= '<tr style="border-bottom:1px solid var(--border-color);">';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars($artLabel, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) $row['bezeichnung'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['jahr'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
            if (!empty($row['kommentar'])) {
                $html .= '<tr style="border-bottom:1px solid var(--border-color);"><td colspan="3" style="padding:0 0.4rem 0.5rem 0.4rem;color:var(--text-muted);font-size:0.9em;">'
                    . htmlspecialchars((string) $row['kommentar'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
        }

        $html .= '</tbody></table></div>';

        $sections[] = $html;
        return $sections;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function addDashboardTile(array $tiles): array {
        $tiles[] = [
            'url' => '/plugin/titel-praemierungen/auszeichnungen',
            'label' => 'Titel & Prämierungen',
            'icon' => '🏅',
        ];
        return $tiles;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'titel-praemierungen',
                'action' => 'manage',
                'label' => 'Verwalten',
                'module_label' => 'Titel & Prämierungen',
            ],
        ];
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            [
                'method' => 'GET',
                'path' => '/auszeichnungen',
                'callback' => [AuszeichnungenController::class, 'index'],
            ],
            [
                'method' => 'POST',
                'path' => '/auszeichnungen/store',
                'callback' => [AuszeichnungenController::class, 'store'],
            ],
            [
                'method' => 'POST',
                'path' => '/auszeichnungen/delete',
                'callback' => [AuszeichnungenController::class, 'delete'],
            ],
        ];
    }
}

/**
 * Admin-Verwaltung der Titel/Prämierungen/Erfolge. Zugriffsschutz über die
 * selbst registrierte Berechtigung "titel-praemierungen.manage", analog zum
 * zuchtschau-ergebnisse-Muster (ErgebnisseController).
 */
class AuszeichnungenController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('titel-praemierungen', 'manage');
    }

    public function index(): void {
        $db = Database::getInstance();

        $horses = $db->query(
            'SELECT id, name, birth_year FROM horses WHERE deleted_at IS NULL ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $entries = $db->query(
            'SELECT t.*, h.name AS horse_name
             FROM `plugin_titel_praemierungen` t
             JOIN horses h ON h.id = t.horse_id
             ORDER BY t.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $csrfToken = Router::generateCsrfToken();

        // Die Seite rendert als Fragment im Framework-Layout
        // (App\Plugin\PluginPage, Addons#66) - Header, Navigation,
        // Theme-Umschalter, Markenfarben und style.css kommen zentral vom
        // Layout. Hier bleibt nur addon-spezifische Geometrie
        // (Formular-Raster), Farben ausschließlich über Theme-Variablen.
        $content = '<style>';
        $content .= '.titel-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}';
        $content .= '</style>';

        $content .= '<div class="card">';
        $content .= '<h1>🏅 Titel &amp; Prämierungen verwalten</h1>';

        $content .= '<h2>Neue Auszeichnung erfassen</h2>';
        $content .= '<form method="POST" action="/plugin/titel-praemierungen/auszeichnungen/store">';
        $content .= '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';

        $content .= '<div class="form-group"><label for="horse_id">Pferd</label>'
            . '<select name="horse_id" id="horse_id" class="form-control" required>';
        $content .= '<option value="">– auswählen –</option>';
        foreach ($horses as $h) {
            $content .= '<option value="' . (int) $h['id'] . '">'
                . htmlspecialchars($h['name'] . ($h['birth_year'] ? ' (' . $h['birth_year'] . ')' : ''), ENT_QUOTES, 'UTF-8')
                . '</option>';
        }
        $content .= '</select></div>';

        $content .= '<div class="titel-row">';
        $content .= '<div class="form-group"><label for="art">Art</label>'
            . '<select name="art" id="art" class="form-control" required>';
        foreach (Plugin::ART_LABELS as $value => $label) {
            $content .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $content .= '</select></div>';
        $content .= '<div class="form-group"><label for="jahr">Jahr</label><input type="number" name="jahr" id="jahr" class="form-control" min="1900" max="2155" placeholder="z. B. 2019"></div>';
        $content .= '</div>';

        $content .= '<div class="form-group"><label for="bezeichnung">Bezeichnung</label><input type="text" name="bezeichnung" id="bezeichnung" class="form-control" maxlength="200" required placeholder="z. B. Elitehengst, Bundeschampion"></div>';

        $content .= '<div class="form-group"><label for="kommentar">Kommentar</label><textarea name="kommentar" id="kommentar" class="form-control" rows="3"></textarea></div>';

        $content .= '<p><button type="submit" class="btn">Speichern</button></p>';
        $content .= '</form>';

        $content .= '<h2>Erfasste Auszeichnungen</h2>';
        $content .= '<table><thead><tr><th>Pferd</th><th>Art</th><th>Bezeichnung</th><th>Jahr</th><th></th></tr></thead><tbody>';
        foreach ($entries as $row) {
            $artLabel = Plugin::ART_LABELS[$row['art']] ?? (string) $row['art'];
            $content .= '<tr>';
            $content .= '<td>' . htmlspecialchars((string) $row['horse_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . htmlspecialchars($artLabel, ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . htmlspecialchars((string) $row['bezeichnung'], ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . htmlspecialchars((string) ($row['jahr'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td><form method="POST" action="/plugin/titel-praemierungen/auszeichnungen/delete" style="margin:0;" onsubmit="return confirm(\'Auszeichnung wirklich löschen?\');">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                . '<button type="submit" class="btn btn-secondary" style="color:var(--danger-fg);">Löschen</button></form></td>';
            $content .= '</tr>';
        }
        if (empty($entries)) {
            $content .= '<tr><td colspan="5">Noch keine Auszeichnungen erfasst.</td></tr>';
        }
        $content .= '</tbody></table>';

        $content .= '<p><a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a></p>';
        $content .= '</div>';

        PluginPage::render('Titel & Prämierungen verwalten', $content);
    }

    public function store(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $horseId = !empty($_POST['horse_id']) ? (int) $_POST['horse_id'] : null;
        $art = trim($_POST['art'] ?? '');
        $bezeichnung = trim($_POST['bezeichnung'] ?? '');

        // Jahr nur als plausibler vierstelliger Wert - alles andere wird
        // bewusst zu NULL (das Feld ist optional, kein Grund zum Abbruch).
        $jahr = null;
        if (isset($_POST['jahr']) && preg_match('/^\d{4}$/', trim((string) $_POST['jahr']))) {
            $jahr = (int) trim((string) $_POST['jahr']);
        }

        if ($horseId && $bezeichnung !== '' && isset(Plugin::ART_LABELS[$art])) {
            $stmt = Database::getInstance()->prepare(
                'INSERT INTO `plugin_titel_praemierungen` (horse_id, art, bezeichnung, jahr, kommentar)
                 VALUES (:horse_id, :art, :bezeichnung, :jahr, :kommentar)'
            );
            $stmt->execute([
                'horse_id' => $horseId,
                'art' => $art,
                'bezeichnung' => $bezeichnung,
                'jahr' => $jahr,
                'kommentar' => trim($_POST['kommentar'] ?? '') ?: null,
            ]);
        }

        header('Location: /plugin/titel-praemierungen/auszeichnungen');
        exit;
    }

    public function delete(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        if ($id) {
            $stmt = Database::getInstance()->prepare('DELETE FROM `plugin_titel_praemierungen` WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }

        header('Location: /plugin/titel-praemierungen/auszeichnungen');
        exit;
    }
}
