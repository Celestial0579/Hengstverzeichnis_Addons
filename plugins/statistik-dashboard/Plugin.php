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
use App\Plugin\PluginPage;
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

        // Lebensstatus ist seit dem Status-Split (Framework #188) orthogonal
        // zum Zuchtstatus - eigene Zählung statt eines status-Enum-Werts.
        $totalDeceased = (int) $db->query(
            "SELECT COUNT(*) FROM horses WHERE deleted_at IS NULL AND is_deceased = 1"
        )->fetchColumn();

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

        // Der Zuchtstatus (active/inactive) partitioniert weiterhin den
        // Gesamtbestand; Verstorben zählt quer dazu und darf NICHT in die
        // Summe eingehen (sonst zählten verstorbene Tiere doppelt).
        $totalActive = (int) ($statusCounts['active'] ?? 0);
        $totalInactive = (int) ($statusCounts['inactive'] ?? 0);
        $totalAll = $totalActive + $totalInactive;

        // Inhalt als Fragment im Haupt-Layout über PluginPage (Addons#66):
        // Header, Navigation, Theme-Umschalter und Basis-Styling kommen vom
        // Framework, hier steht nur noch der eigentliche Seiteninhalt.
        // Das Kacheln-Raster ist addon-spezifische Geometrie und bleibt als
        // kleiner Style-Block; Farben ausschließlich über Theme-Variablen.
        $content = '<style>
            .tiles{display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:1rem;margin-bottom:2rem;}
            .tile{background:var(--surface-muted);border-radius:var(--border-radius, 6px);padding:1rem;text-align:center;}
            .tile .num{font-size:1.8rem;font-weight:bold;}
            .tile .label{color:var(--text-muted);font-size:0.85rem;}
        </style>';

        $content .= '<div class="card">';
        $content .= '<h1>📈 Statistik-Dashboard</h1>';

        $content .= '<div class="tiles">';
        $content .= '<div class="tile"><div class="num">' . $totalAll . '</div><div class="label">Pferde gesamt</div></div>';
        $content .= '<div class="tile"><div class="num">' . $totalActive . '</div><div class="label">Aktiv (Zucht)</div></div>';
        $content .= '<div class="tile"><div class="num">' . $totalInactive . '</div><div class="label">Inaktiv (Zucht)</div></div>';
        $content .= '<div class="tile"><div class="num">' . $totalDeceased . '</div><div class="label">Verstorben</div></div>';
        $content .= '</div>';

        $content .= '<h2>Verteilung nach Deckstation</h2>';
        $content .= $this->renderCountTable($stationDistribution, 'station', 'Deckstation');

        $content .= '<h2>Wachstum der Datenbank über Zeit</h2>';
        $content .= '<table><thead><tr><th>Jahr</th><th>Neu angelegte Pferde</th></tr></thead><tbody>';
        foreach ($growthByYear as $row) {
            $content .= '<tr><td>' . htmlspecialchars((string) $row['yr'], ENT_QUOTES, 'UTF-8') . '</td><td>' . (int) $row['total'] . '</td></tr>';
        }
        if (empty($growthByYear)) {
            $content .= '<tr><td colspan="2">Keine Daten vorhanden.</td></tr>';
        }
        $content .= '</tbody></table>';

        $content .= '<h2>Top-Blutlinien: meistgenutzte Väter</h2>';
        $content .= $this->renderCountTable($topSires, 'display_name', 'Vater');

        $content .= '<h2>Top-Blutlinien: meistgenutzte Mütter</h2>';
        $content .= $this->renderCountTable($topDams, 'display_name', 'Mutter');

        $content .= '<p><a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a></p>';
        $content .= '</div>';

        PluginPage::render('Statistik-Dashboard', $content);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderCountTable(array $rows, string $labelKey, string $labelHeading): string {
        $html = '<table><thead><tr><th>' . htmlspecialchars($labelHeading, ENT_QUOTES, 'UTF-8') . '</th><th>Anzahl</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . htmlspecialchars((string) $row[$labelKey], ENT_QUOTES, 'UTF-8') . '</td><td>' . (int) $row['total'] . '</td></tr>';
        }
        if (empty($rows)) {
            $html .= '<tr><td colspan="2">Keine Daten vorhanden.</td></tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }
}
