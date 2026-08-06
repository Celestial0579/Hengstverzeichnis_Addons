<?php
// zuchtschau-ergebnisse/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#14. Der Kern-Status eines Pferdes
// (`active` = "Aktiv (Gekört)") ist nur ein binäres Flag - dieses Addon
// ergänzt strukturierte Ergebnisse einzelner Zuchtschauen/Körungen
// (Veranstaltung, Note, Richter, Platzierung, Kommentar) und zeigt sie
// chronologisch auf der öffentlichen Pferde-Detailseite.
//
// Installation (lokal im Framework-Repo):
//   cp -r zuchtschau-ergebnisse plugins/zuchtschau-ergebnisse
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// der gewünschten Gruppe unter /admin/groups die Berechtigung
// "Zuchtschau-Ergebnisse -> Verwalten" zuweisen.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\ZuchtschauErgebnisse;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use App\Router;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        $this->ensureTable();
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
    }

    private function ensureTable(): void {
        Database::getInstance()->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_zuchtschau_ergebnisse` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `horse_id` INT NOT NULL,
                `event_name` VARCHAR(150) NOT NULL,
                `event_date` DATE NULL DEFAULT NULL,
                `category` VARCHAR(100) NULL DEFAULT NULL,
                `score` VARCHAR(50) NULL DEFAULT NULL,
                `judge` VARCHAR(100) NULL DEFAULT NULL,
                `placement` VARCHAR(50) NULL DEFAULT NULL,
                `comment` TEXT NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Filter-Beispiel: hängt eine chronologische Liste der erfassten
     * Zuchtschau-/Körungsergebnisse des Pferdes an die öffentliche
     * Detailseite an. Zeigt nichts an, wenn noch keine Ergebnisse erfasst
     * sind (kein leerer Abschnitt).
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $horseId = (int) $horse['id'];

        $stmt = Database::getInstance()->prepare(
            'SELECT event_name, event_date, category, score, judge, placement, `comment`
             FROM `plugin_zuchtschau_ergebnisse`
             WHERE horse_id = :id
             ORDER BY event_date DESC, id DESC'
        );
        $stmt->execute(['id' => $horseId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($results)) {
            return $sections;
        }

        $html = '<div style="margin-top:0.5rem;">';
        $html .= '<h3 style="margin-bottom:0.5rem;">🏆 Zuchtschau-/Körungsergebnisse</h3>';
        $html .= '<table style="width:100%;border-collapse:collapse;">';
        $html .= '<thead><tr style="text-align:left;border-bottom:2px solid #ddd;">'
            . '<th style="padding:0.4rem;">Veranstaltung</th><th style="padding:0.4rem;">Datum</th>'
            . '<th style="padding:0.4rem;">Kategorie</th><th style="padding:0.4rem;">Note</th>'
            . '<th style="padding:0.4rem;">Platzierung</th><th style="padding:0.4rem;">Richter</th></tr></thead><tbody>';

        foreach ($results as $row) {
            $html .= '<tr style="border-bottom:1px solid #eee;">';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) $row['event_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['event_date'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['category'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['score'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['placement'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['judge'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
            if (!empty($row['comment'])) {
                $html .= '<tr style="border-bottom:1px solid #eee;"><td colspan="6" style="padding:0 0.4rem 0.5rem 0.4rem;color:#666;font-size:0.9em;">'
                    . htmlspecialchars((string) $row['comment'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
        }

        $html .= '</tbody></table></div>';

        $sections[] = $html;
        return $sections;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'zuchtschau-ergebnisse',
                'action' => 'manage',
                'label' => 'Verwalten',
                'module_label' => 'Zuchtschau-Ergebnisse',
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
                'path' => '/ergebnisse',
                'callback' => [ErgebnisseController::class, 'index'],
            ],
            [
                'method' => 'POST',
                'path' => '/ergebnisse/store',
                'callback' => [ErgebnisseController::class, 'store'],
            ],
            [
                'method' => 'POST',
                'path' => '/ergebnisse/delete',
                'callback' => [ErgebnisseController::class, 'delete'],
            ],
        ];
    }
}

/**
 * Admin-Verwaltung der Zuchtschau-/Körungsergebnisse. Zugriffsschutz über
 * die selbst registrierte Berechtigung "zuchtschau-ergebnisse.manage",
 * analog zum besucherstatistik-Muster (StatistikController).
 */
class ErgebnisseController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('zuchtschau-ergebnisse', 'manage');
    }

    public function index(): void {
        $db = Database::getInstance();

        $horses = $db->query(
            'SELECT id, name, birth_year FROM horses WHERE deleted_at IS NULL ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $results = $db->query(
            'SELECT e.*, h.name AS horse_name
             FROM `plugin_zuchtschau_ergebnisse` e
             JOIN horses h ON h.id = e.horse_id
             ORDER BY e.event_date DESC, e.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $csrfToken = Router::generateCsrfToken();

        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Zuchtschau-Ergebnisse verwalten</title>';
        echo '<style>
            body{font-family:sans-serif;padding:2rem;max-width:900px;margin:0 auto;}
            table{width:100%;border-collapse:collapse;margin-top:1.5rem;}
            th,td{text-align:left;padding:0.5rem;border-bottom:1px solid #ddd;font-size:0.9rem;}
            label{display:block;margin-top:0.8rem;font-weight:bold;font-size:0.9rem;}
            input,select,textarea{width:100%;padding:0.4rem;margin-top:0.2rem;}
            .row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
        </style></head><body>';
        echo '<h1>🏆 Zuchtschau-/Körungs-Ergebnisverwaltung</h1>';

        echo '<h2>Neues Ergebnis erfassen</h2>';
        echo '<form method="POST" action="/plugin/zuchtschau-ergebnisse/ergebnisse/store">';
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
        echo '<div><label for="event_name">Veranstaltung</label><input type="text" name="event_name" id="event_name" required></div>';
        echo '<div><label for="event_date">Datum</label><input type="date" name="event_date" id="event_date"></div>';
        echo '</div>';

        echo '<div class="row">';
        echo '<div><label for="category">Kategorie</label><input type="text" name="category" id="category" placeholder="z. B. Körung, Zuchtschau"></div>';
        echo '<div><label for="score">Note</label><input type="text" name="score" id="score"></div>';
        echo '</div>';

        echo '<div class="row">';
        echo '<div><label for="placement">Platzierung</label><input type="text" name="placement" id="placement"></div>';
        echo '<div><label for="judge">Richter</label><input type="text" name="judge" id="judge"></div>';
        echo '</div>';

        echo '<label for="comment">Kommentar</label><textarea name="comment" id="comment" rows="3"></textarea>';

        echo '<p><button type="submit" style="margin-top:1.2rem;padding:0.6rem 1.2rem;">Speichern</button></p>';
        echo '</form>';

        echo '<h2>Erfasste Ergebnisse</h2>';
        echo '<table><thead><tr><th>Pferd</th><th>Veranstaltung</th><th>Datum</th><th>Note</th><th>Platzierung</th><th></th></tr></thead><tbody>';
        foreach ($results as $row) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars((string) $row['horse_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['event_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['event_date'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['score'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['placement'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td><form method="POST" action="/plugin/zuchtschau-ergebnisse/ergebnisse/delete" style="margin:0;" onsubmit="return confirm(\'Ergebnis wirklich löschen?\');">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                . '<button type="submit" style="color:#dc3545;">Löschen</button></form></td>';
            echo '</tr>';
        }
        if (empty($results)) {
            echo '<tr><td colspan="6">Noch keine Ergebnisse erfasst.</td></tr>';
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
        $eventName = trim($_POST['event_name'] ?? '');

        if ($horseId && $eventName !== '') {
            $stmt = Database::getInstance()->prepare(
                'INSERT INTO `plugin_zuchtschau_ergebnisse`
                    (horse_id, event_name, event_date, category, score, judge, placement, `comment`)
                 VALUES (:horse_id, :event_name, :event_date, :category, :score, :judge, :placement, :comment)'
            );
            $stmt->execute([
                'horse_id' => $horseId,
                'event_name' => $eventName,
                'event_date' => !empty($_POST['event_date']) ? $_POST['event_date'] : null,
                'category' => trim($_POST['category'] ?? '') ?: null,
                'score' => trim($_POST['score'] ?? '') ?: null,
                'judge' => trim($_POST['judge'] ?? '') ?: null,
                'placement' => trim($_POST['placement'] ?? '') ?: null,
                'comment' => trim($_POST['comment'] ?? '') ?: null,
            ]);
        }

        header('Location: /plugin/zuchtschau-ergebnisse/ergebnisse');
        exit;
    }

    public function delete(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        if ($id) {
            $stmt = Database::getInstance()->prepare('DELETE FROM `plugin_zuchtschau_ergebnisse` WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }

        header('Location: /plugin/zuchtschau-ergebnisse/ergebnisse');
        exit;
    }
}
