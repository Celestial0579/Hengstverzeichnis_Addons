<?php
// statistik-dashboard/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: ergänzt das Admin-Dashboard
// (admin_dashboard.php zeigt bisher nur Navigation und den
// Papierkorb-Zähler) um eine Kennzahlen-Übersicht - Anzahl Pferde je Status,
// Verteilung nach Deckstation, Wachstum der Datenbank über Zeit und
// meistgenutzte Blutlinien (Väter/Mütter).
//
// Installation (lokal im Framework-Repo):
//   cp -r statistik-dashboard plugins/statistik-dashboard
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// der gewünschten Gruppe unter /admin/groups die Berechtigung
// "Statistik-Dashboard -> Statistik einsehen" zuweisen.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\StatistikDashboard;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
    }

    public function addDashboardTile(array $tiles): array {
        $tiles[] = [
            'url' => '/plugin/statistik-dashboard/statistik',
            'label' => 'Statistik-Dashboard',
            'icon' => '📈',
        ];
        return $tiles;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'statistik-dashboard',
                'action' => 'view',
                'label' => 'Statistik einsehen',
                'module_label' => 'Statistik-Dashboard',
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
                'path' => '/statistik',
                'callback' => [StatistikController::class, 'show'],
            ],
        ];
    }
}

class StatistikController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('statistik-dashboard', 'view');
    }

    public function show(): void {
        $db = Database::getInstance();

        $statusCounts = $db->query(
            "SELECT status, COUNT(*) AS total FROM horses WHERE deleted_at IS NULL GROUP BY status"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $stationDistribution = $db->query(
            "SELECT COALESCE(bs.name, NULLIF(h.breeding_station, ''), 'Unbekannt') AS station, COUNT(*) AS total
             FROM horses h
             LEFT JOIN breeding_stations bs ON h.breeding_station_id = bs.id AND bs.deleted_at IS NULL
             WHERE h.deleted_at IS NULL
             GROUP BY station
             ORDER BY total DESC
             LIMIT 15"
        )->fetchAll(PDO::FETCH_ASSOC);

        $growthByYear = $db->query(
            "SELECT YEAR(created_at) AS yr, COUNT(*) AS total
             FROM horses
             WHERE deleted_at IS NULL AND created_at IS NOT NULL
             GROUP BY yr
             ORDER BY yr ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $topSires = $db->query(
            "SELECT COALESCE(sire.name, h.sire_name) AS display_name, COUNT(*) AS total
             FROM horses h
             LEFT JOIN horses sire ON h.sire_id = sire.id AND sire.deleted_at IS NULL
             WHERE h.deleted_at IS NULL AND (h.sire_id IS NOT NULL OR h.sire_name IS NOT NULL AND h.sire_name != '')
             GROUP BY display_name
             HAVING display_name IS NOT NULL
             ORDER BY total DESC
             LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC);

        $topDams = $db->query(
            "SELECT COALESCE(dam.name, h.dam_name) AS display_name, COUNT(*) AS total
             FROM horses h
             LEFT JOIN horses dam ON h.dam_id = dam.id AND dam.deleted_at IS NULL
             WHERE h.deleted_at IS NULL AND (h.dam_id IS NOT NULL OR h.dam_name IS NOT NULL AND h.dam_name != '')
             GROUP BY display_name
             HAVING display_name IS NOT NULL
             ORDER BY total DESC
             LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC);

        $totalActive = (int) ($statusCounts['active'] ?? 0);
        $totalInactive = (int) ($statusCounts['inactive'] ?? 0);
        $totalDeceased = (int) ($statusCounts['deceased'] ?? 0);
        $totalAll = $totalActive + $totalInactive + $totalDeceased;

        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Statistik-Dashboard</title>';
        echo '<link rel="stylesheet" href="/css/style.css">';
        echo <<<'HTML'
        <script>
        // Theme-Bootstrap wie im Framework-Layout (dort ausführlich begründet):
        // synchron im <head>, damit data-theme vor dem ersten Rendern steht.
        (function () {
            var stored = localStorage.getItem('theme');
            if (stored === 'dark' || stored === 'light') {
                document.documentElement.setAttribute('data-theme', stored);
            }
        })();
        </script>
        HTML;
        echo '<style>
            body{font-family:sans-serif;padding:2rem;max-width:1000px;margin:0 auto;color:var(--text-color);background:var(--bg-color);}
            h1{margin-top:0;}
            .tiles{display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:1rem;margin-bottom:2rem;}
            .tile{background:var(--surface-muted);border-radius:8px;padding:1rem;text-align:center;}
            .tile .num{font-size:1.8rem;font-weight:bold;}
            .tile .label{color:var(--text-muted);font-size:0.85rem;}
            table{width:100%;border-collapse:collapse;margin-bottom:2rem;}
            th,td{text-align:left;padding:0.5rem;border-bottom:1px solid var(--border-color);}
            h2{border-bottom:2px solid var(--secondary-color);padding-bottom:0.3rem;}
        </style></head><body>';
        echo '<h1>📈 Statistik-Dashboard</h1>';

        echo '<div class="tiles">';
        echo '<div class="tile"><div class="num">' . $totalAll . '</div><div class="label">Pferde gesamt</div></div>';
        echo '<div class="tile"><div class="num">' . $totalActive . '</div><div class="label">Aktiv</div></div>';
        echo '<div class="tile"><div class="num">' . $totalInactive . '</div><div class="label">Inaktiv</div></div>';
        echo '<div class="tile"><div class="num">' . $totalDeceased . '</div><div class="label">Verstorben</div></div>';
        echo '</div>';

        echo '<h2>Verteilung nach Deckstation</h2>';
        $this->renderCountTable($stationDistribution, 'station', 'Deckstation');

        echo '<h2>Wachstum der Datenbank über Zeit</h2>';
        echo '<table><thead><tr><th>Jahr</th><th>Neu angelegte Pferde</th></tr></thead><tbody>';
        foreach ($growthByYear as $row) {
            echo '<tr><td>' . htmlspecialchars((string) $row['yr'], ENT_QUOTES, 'UTF-8') . '</td><td>' . (int) $row['total'] . '</td></tr>';
        }
        if (empty($growthByYear)) {
            echo '<tr><td colspan="2">Keine Daten vorhanden.</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Top-Blutlinien: meistgenutzte Väter</h2>';
        $this->renderCountTable($topSires, 'display_name', 'Vater');

        echo '<h2>Top-Blutlinien: meistgenutzte Mütter</h2>';
        $this->renderCountTable($topDams, 'display_name', 'Mutter');

        echo '<p><a href="/admin">Zurück zum Dashboard</a></p>';
        echo '</body></html>';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderCountTable(array $rows, string $labelKey, string $labelHeading): void {
        echo '<table><thead><tr><th>' . htmlspecialchars($labelHeading, ENT_QUOTES, 'UTF-8') . '</th><th>Anzahl</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . htmlspecialchars((string) $row[$labelKey], ENT_QUOTES, 'UTF-8') . '</td><td>' . (int) $row['total'] . '</td></tr>';
        }
        if (empty($rows)) {
            echo '<tr><td colspan="2">Keine Daten vorhanden.</td></tr>';
        }
        echo '</tbody></table>';
    }
}
