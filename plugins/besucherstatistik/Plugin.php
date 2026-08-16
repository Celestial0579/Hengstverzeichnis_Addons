<?php
// besucherstatistik/Plugin.php
//
// Beispiel-Addon für Hengstverzeichnis_Framework: zählt pro Pferd, wie oft
// dessen öffentliche Detailseite aufgerufen wurde, und stellt Admins mit der
// Berechtigung "besucherstatistik.view" eine Rangliste der meistgesehenen
// Pferde bereit.
//
// Installation (lokal im Framework-Repo):
//   cp -r besucherstatistik plugins/besucherstatistik
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// der gewünschten Gruppe unter /admin/groups die Berechtigung
// "Besucherstatistik -> Statistik einsehen" zuweisen.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\Besucherstatistik;

use App\Database;
use App\Plugin\HookManager;
use App\Plugin\PluginPage;
use App\Controllers\BaseController;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        // Kein ensureTable() mehr: Die Tabelle legt install() an, das der
        // PluginManager bei Aktivierung und nach jedem Addon-Update genau
        // einmal aufruft (Framework #75). Die frueher hier stehende Probe
        // ("SELECT 1 FROM ... LIMIT 1", sonst install() nachholen) war ein
        // Rueckfall fuer Kerne OHNE diesen Hook - den es laut der
        // core_compatibility-Untergrenze in plugin.json nicht mehr gibt.
        // Geblieben waere nur der Preis: eine zusaetzliche Abfrage pro Plugin
        // und Anfrage, bei sieben Addons also sieben Roundtrips, bevor die
        // erste Zeile der Seite steht.
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
        $hooks->addAction('horse.after_save', [$this, 'onHorseSaved']);
    }

    /**
     * Framework-Hook (#75): Der PluginManager ruft install() bei der
     * Aktivierung und nach einem Addon-Update genau einmal auf - das
     * DDL-Statement läuft damit nicht mehr in jedem Request.
     */
    public function install(): void {
        Database::getInstance()->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_besucherstatistik_views` (
                `horse_id` INT NOT NULL PRIMARY KEY,
                `views` INT UNSIGNED NOT NULL DEFAULT 0,
                `last_viewed_at` DATETIME NULL DEFAULT NULL,
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    /**
     * Filter-Beispiel: zählt bei jedem Aufruf der öffentlichen Detailseite
     * eines Pferdes den Besuch mit und hängt einen Abschnitt mit der
     * aktuellen Aufrufzahl an. Der Rückgabewert wird von der View
     * unescaped ausgegeben - daher wird der Pferdename hier selbst mit
     * htmlspecialchars() escaped, bevor er ins HTML-Fragment eingebettet wird.
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons): array {
        $horseId = (int) $horse['id'];
        $db = Database::getInstance();

        $stmt = $db->prepare(
            'INSERT INTO `plugin_besucherstatistik_views` (`horse_id`, `views`, `last_viewed_at`)
             VALUES (:id, 1, NOW())
             ON DUPLICATE KEY UPDATE `views` = `views` + 1, `last_viewed_at` = NOW()'
        );
        $stmt->execute(['id' => $horseId]);

        $stmt = $db->prepare('SELECT `views` FROM `plugin_besucherstatistik_views` WHERE `horse_id` = :id');
        $stmt->execute(['id' => $horseId]);
        $views = (int) $stmt->fetchColumn();

        $sections[] = '<p style="color:var(--text-muted);font-size:0.9em;">👁 Dieses Profil wurde ' . $views . ' mal aufgerufen.</p>';
        return $sections;
    }

    /**
     * Filter-Beispiel: fügt dem Admin-Dashboard eine Kachel hinzu, die zur
     * eigenen Statistik-Route verlinkt. Der Zugriffsschutz der Zielseite
     * selbst erfolgt in StatistikController (siehe unten) - die Kachel wird
     * unabhängig von der Berechtigung angezeigt, ein Klick ohne Berechtigung
     * führt zur normalen 403-Seite des Kerns.
     */
    public function addDashboardTile(array $tiles): array {
        $tiles[] = [
            'url' => '/plugin/besucherstatistik/statistik',
            'label' => 'Besucherstatistik',
            'icon' => '📊',
        ];
        return $tiles;
    }

    /**
     * Action-Beispiel: legt für ein neu angelegtes Pferd sofort eine
     * Zähler-Zeile mit 0 Aufrufen an, damit es in der Rangliste von Anfang
     * an auftaucht statt erst beim ersten Besuch. Läuft in try/catch-
     * Isolation durch HookManager::doAction() - ein Fehler hier blockiert
     * nie den eigentlichen Speichervorgang.
     */
    public function onHorseSaved(int $horseId, array $data, bool $isNew): void {
        if (!$isNew) {
            return;
        }
        $stmt = Database::getInstance()->prepare(
            'INSERT IGNORE INTO `plugin_besucherstatistik_views` (`horse_id`, `views`) VALUES (:id, 0)'
        );
        $stmt->execute(['id' => $horseId]);
    }

    /**
     * Berechtigungs-Beispiel (#66): legt ein komplett neues Modul
     * "besucherstatistik" mit einer eigenen Aktion "view" an (daher mit
     * module_label, da das Modul noch nicht existiert). Erscheint danach
     * als eigener Abschnitt in der Berechtigungsmatrix unter /admin/groups.
     *
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'besucherstatistik',
                'action' => 'view',
                'label' => 'Statistik einsehen',
                'module_label' => 'Besucherstatistik',
            ],
        ];
    }

    /**
     * Routen-Beispiel: registriert die Statistik-Seite. Wird zwingend unter
     * /plugin/besucherstatistik/... eingehängt (siehe PluginManager) - kann
     * daher nie eine Kern-Route überschreiben. Callback als Klassenname-
     * String, nicht als [$this, ...] - der Router erzeugt pro Request eine
     * frische StatistikController-Instanz.
     *
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

/**
 * Eigene, von BaseController erbende Route-Klasse - so wie im
 * Sicherheitsmodell des Plugin-Systems vorgesehen (Zugriffsschutz für
 * Plugin-Routen ist Aufgabe des Plugins, nicht automatisch). Prüft Login und
 * die selbst registrierte Berechtigung "besucherstatistik.view", bevor die
 * Rangliste angezeigt wird.
 */
class StatistikController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('besucherstatistik', 'view');
    }

    public function show(): void {
        $stmt = Database::getInstance()->query(
            'SELECT h.id, h.name, h.birth_year, COALESCE(v.views, 0) AS views
             FROM `horses` h
             LEFT JOIN `plugin_besucherstatistik_views` v ON v.horse_id = h.id
             WHERE h.deleted_at IS NULL
             ORDER BY views DESC, h.name ASC
             LIMIT 50'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Inhalt als Fragment im Haupt-Layout über PluginPage (Addons#66):
        // Header, Navigation, Theme-Umschalter und Tabellen-Styling kommen
        // vom Framework, hier steht nur noch der eigentliche Seiteninhalt.
        $content = '<div class="card">';
        $content .= '<h1>📊 Besucherstatistik</h1>';
        $content .= '<p>Meistaufgerufene Pferde-Profile der öffentlichen Detailseite.</p>';
        $content .= '<table><thead><tr><th>#</th><th>Pferd</th><th>Geburtsjahr</th><th>Aufrufe</th></tr></thead><tbody>';

        $rank = 1;
        foreach ($rows as $row) {
            $content .= '<tr>';
            $content .= '<td>' . $rank++ . '</td>';
            $content .= '<td><a href="/horse?id=' . (int) $row['id'] . '">' . htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') . '</a></td>';
            $content .= '<td>' . htmlspecialchars((string) ($row['birth_year'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . (int) $row['views'] . '</td>';
            $content .= '</tr>';
        }

        if (empty($rows)) {
            $content .= '<tr><td colspan="4">Noch keine Pferde vorhanden.</td></tr>';
        }

        $content .= '</tbody></table>';
        $content .= '<p><a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a></p>';
        $content .= '</div>';

        PluginPage::render('Besucherstatistik', $content);
    }
}
