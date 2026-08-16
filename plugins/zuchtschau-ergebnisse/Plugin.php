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
use App\Plugin\PluginPage;
use App\Router;
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
    }

    /**
     * Framework-Hook (#75): Der PluginManager ruft install() bei der
     * Aktivierung und nach einem Addon-Update auf - das DDL-Statement läuft
     * damit nicht mehr in jedem Request. install() muss idempotent sein
     * (CREATE TABLE IF NOT EXISTS), denn der Kern garantiert "mindestens
     * einmal nach Installation/Update", nicht "genau einmal" - so zieht ein
     * Addon-Update auf einer Bestandsinstallation die neue Kindtabelle nach,
     * ohne die vorhandene Ergebnistabelle anzufassen (#82).
     */
    public function install(): void {
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

        // Kindtabelle für Teilwertungen (#82): mehrspaltige Einzelwertungen
        // (Dressur/Springen/Gelände, Zeiten, Distanzen ...) je Ergebnis. Alle
        // Fachspalten bewusst NULL-tolerant - die Altdaten aus v1/v2 sind
        // lückig, und eine Teilwertung mit nur Bezeichnung+Note ist gültig.
        // ON DELETE CASCADE: Teilwertungen existieren nie ohne ihr Ergebnis.
        Database::getInstance()->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_zuchtschau_teilwertungen` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `ergebnis_id` INT NOT NULL,
                `bezeichnung` VARCHAR(150) NULL DEFAULT NULL,
                `wertung` VARCHAR(100) NULL DEFAULT NULL,
                `note` VARCHAR(50) NULL DEFAULT NULL,
                `platzierung` VARCHAR(50) NULL DEFAULT NULL,
                `distanz` VARCHAR(50) NULL DEFAULT NULL,
                `zeit` VARCHAR(50) NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`ergebnis_id`) REFERENCES `plugin_zuchtschau_ergebnisse`(`id`) ON DELETE CASCADE
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
            'SELECT id, event_name, event_date, category, score, judge, placement, `comment`
             FROM `plugin_zuchtschau_ergebnisse`
             WHERE horse_id = :id
             ORDER BY event_date DESC, id DESC'
        );
        $stmt->execute(['id' => $horseId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($results)) {
            return $sections;
        }

        // Teilwertungen (#82) je Ergebnis nachladen. Bewusst EIN vorbereitetes
        // Statement, das pro Ergebnis ausgeführt wird - kein per Interpolation
        // zusammengesetztes IN(...): Die Detailseite zeigt wenige Ergebnisse,
        // und das SQL bleibt ein reines Literal (Prepared Statement).
        $twStmt = Database::getInstance()->prepare(
            'SELECT bezeichnung, wertung, note, platzierung, distanz, zeit
             FROM `plugin_zuchtschau_teilwertungen`
             WHERE ergebnis_id = :ergebnis_id
             ORDER BY id ASC'
        );

        $html = '<div style="margin-top:0.5rem;">';
        $html .= '<h3 style="margin-bottom:0.5rem;">🏆 Zuchtschau-/Körungsergebnisse</h3>';
        $html .= '<table style="width:100%;border-collapse:collapse;">';
        $html .= '<thead><tr style="text-align:left;border-bottom:2px solid var(--border-color);">'
            . '<th style="padding:0.4rem;">Veranstaltung</th><th style="padding:0.4rem;">Datum</th>'
            . '<th style="padding:0.4rem;">Kategorie</th><th style="padding:0.4rem;">Note</th>'
            . '<th style="padding:0.4rem;">Platzierung</th><th style="padding:0.4rem;">Richter</th></tr></thead><tbody>';

        foreach ($results as $row) {
            $html .= '<tr style="border-bottom:1px solid var(--border-color);">';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) $row['event_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['event_date'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['category'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['score'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['placement'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['judge'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
            if (!empty($row['comment'])) {
                $html .= '<tr style="border-bottom:1px solid var(--border-color);"><td colspan="6" style="padding:0 0.4rem 0.5rem 0.4rem;color:var(--text-muted);font-size:0.9em;">'
                    . htmlspecialchars((string) $row['comment'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }

            // Teilwertungen unterhalb des jeweiligen Ergebnisses (#82) - nur
            // rendern, wenn welche erfasst sind (kein leerer Unterabschnitt).
            $twStmt->execute(['ergebnis_id' => (int) $row['id']]);
            $teilwertungen = $twStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($teilwertungen)) {
                $html .= '<tr style="border-bottom:1px solid var(--border-color);"><td colspan="6" style="padding:0 0.4rem 0.5rem 1.2rem;">';
                $html .= '<table style="width:100%;border-collapse:collapse;font-size:0.9em;">';
                $html .= '<thead><tr style="text-align:left;color:var(--text-muted);">'
                    . '<th style="padding:0.2rem 0.4rem;">Teilwertung</th><th style="padding:0.2rem 0.4rem;">Wertung</th>'
                    . '<th style="padding:0.2rem 0.4rem;">Note</th><th style="padding:0.2rem 0.4rem;">Platzierung</th>'
                    . '<th style="padding:0.2rem 0.4rem;">Distanz</th><th style="padding:0.2rem 0.4rem;">Zeit</th></tr></thead><tbody>';
                foreach ($teilwertungen as $tw) {
                    $html .= '<tr>';
                    $html .= '<td style="padding:0.2rem 0.4rem;">' . htmlspecialchars((string) ($tw['bezeichnung'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:0.2rem 0.4rem;">' . htmlspecialchars((string) ($tw['wertung'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:0.2rem 0.4rem;">' . htmlspecialchars((string) ($tw['note'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:0.2rem 0.4rem;">' . htmlspecialchars((string) ($tw['platzierung'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:0.2rem 0.4rem;">' . htmlspecialchars((string) ($tw['distanz'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:0.2rem 0.4rem;">' . htmlspecialchars((string) ($tw['zeit'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table></td></tr>';
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
            [
                'method' => 'POST',
                'path' => '/ergebnisse/teilwertung/store',
                'callback' => [ErgebnisseController::class, 'storeTeilwertung'],
            ],
            [
                'method' => 'POST',
                'path' => '/ergebnisse/teilwertung/delete',
                'callback' => [ErgebnisseController::class, 'deleteTeilwertung'],
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

    /**
     * Obergrenzen der Uebersicht. Beide bewusst grosszuegig - sie sollen den
     * Normalbetrieb nicht einschraenken, sondern nur verhindern, dass die
     * Seite mit dem Bestand mitwaechst.
     */
    private const HORSE_OPTION_LIMIT = 500;
    private const RESULT_LIMIT = 200;

    public function index(): void {
        $db = Database::getInstance();

        // Drei Obergrenzen, wo vorher keine war. Die Seite lud den KOMPLETTEN
        // Pferdebestand, ALLE Ergebnisse und ALLE Teilwertungen - drei
        // Volltabellen-Abfragen bei jedem Aufruf, deren Ergebnis vollständig
        // im PHP-Speicher landet. Mit ein paar hundert Zeilen faellt das nicht
        // auf, mit dem Bestand waechst es linear mit; die Schwester-Addons
        // (gesundheitstests, galerie, titel-praemierungen, verkaufsboerse)
        // arbeiten aus genau diesem Grund laengst mit SEARCH_LIMIT.
        $horses = $db->query(
            'SELECT id, name, birth_year FROM horses WHERE deleted_at IS NULL'
            . ' ORDER BY name ASC LIMIT ' . (self::HORSE_OPTION_LIMIT + 1)
        )->fetchAll(PDO::FETCH_ASSOC);
        $horsesCapped = count($horses) > self::HORSE_OPTION_LIMIT;
        if ($horsesCapped) {
            array_pop($horses);
        }

        $results = $db->query(
            'SELECT e.*, h.name AS horse_name
             FROM `plugin_zuchtschau_ergebnisse` e
             JOIN horses h ON h.id = e.horse_id
             ORDER BY e.event_date DESC, e.id DESC
             LIMIT ' . (self::RESULT_LIMIT + 1)
        )->fetchAll(PDO::FETCH_ASSOC);
        $resultsCapped = count($results) > self::RESULT_LIMIT;
        if ($resultsCapped) {
            array_pop($results);
        }

        // Teilwertungen (#82) in einem Rutsch laden und nach Ergebnis
        // gruppieren - erspart eine Query je Ergebniszeile. Jetzt aber nur
        // noch fuer die tatsaechlich angezeigten Ergebnisse: Vorher wurde die
        // gesamte Tabelle geladen, um die Teilwertungen von hoechstens ein
        // paar Dutzend sichtbaren Zeilen zu beschriften.
        $teilwertungenByErgebnis = [];
        $ergebnisIds = array_map(static fn (array $r): int => (int) $r['id'], $results);
        if ($ergebnisIds !== []) {
            $platzhalter = implode(',', array_fill(0, count($ergebnisIds), '?'));
            $twStmt = $db->prepare(
                'SELECT id, ergebnis_id, bezeichnung, wertung, note, platzierung, distanz, zeit
                 FROM `plugin_zuchtschau_teilwertungen`
                 WHERE ergebnis_id IN (' . $platzhalter . ')
                 ORDER BY id ASC'
            );
            $twStmt->execute($ergebnisIds);
            foreach ($twStmt->fetchAll(PDO::FETCH_ASSOC) as $tw) {
                $teilwertungenByErgebnis[(int) $tw['ergebnis_id']][] = $tw;
            }
        }

        $csrfToken = Router::generateCsrfToken();

        // Die Seite rendert als Fragment im Framework-Layout
        // (App\Plugin\PluginPage, Addons#66) - Header, Navigation,
        // Theme-Umschalter, Markenfarben und style.css kommen zentral vom
        // Layout. Hier bleibt nur addon-spezifische Geometrie
        // (Formular-Raster), Farben ausschließlich über Theme-Variablen.
        $content = '<style>';
        $content .= '.zuchtschau-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}';
        $content .= '.zuchtschau-tw-form{display:grid;grid-template-columns:repeat(6,1fr) auto;gap:0.5rem;align-items:end;margin:0.3rem 0 0.5rem 0;}';
        $content .= '.zuchtschau-tw-zelle{padding-left:1.5rem;}';
        $content .= '</style>';

        $content .= '<div class="card">';
        $content .= '<h1>🏆 Zuchtschau-/Körungs-Ergebnisverwaltung</h1>';

        $content .= '<h2>Neues Ergebnis erfassen</h2>';
        if ($horsesCapped) {
            $content .= '<p style="color: var(--text-muted); font-size: 0.9rem;">Die Auswahl zeigt die ersten '
                . self::HORSE_OPTION_LIMIT . ' Pferde alphabetisch.</p>';
        }
        $content .= '<form method="POST" action="/plugin/zuchtschau-ergebnisse/ergebnisse/store">';
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

        $content .= '<div class="zuchtschau-row">';
        $content .= '<div class="form-group"><label for="event_name">Veranstaltung</label><input type="text" name="event_name" id="event_name" class="form-control" required></div>';
        $content .= '<div class="form-group"><label for="event_date">Datum</label><input type="date" name="event_date" id="event_date" class="form-control"></div>';
        $content .= '</div>';

        $content .= '<div class="zuchtschau-row">';
        $content .= '<div class="form-group"><label for="category">Kategorie</label><input type="text" name="category" id="category" class="form-control" placeholder="z. B. Körung, Zuchtschau"></div>';
        $content .= '<div class="form-group"><label for="score">Note</label><input type="text" name="score" id="score" class="form-control"></div>';
        $content .= '</div>';

        $content .= '<div class="zuchtschau-row">';
        $content .= '<div class="form-group"><label for="placement">Platzierung</label><input type="text" name="placement" id="placement" class="form-control"></div>';
        $content .= '<div class="form-group"><label for="judge">Richter</label><input type="text" name="judge" id="judge" class="form-control"></div>';
        $content .= '</div>';

        $content .= '<div class="form-group"><label for="comment">Kommentar</label><textarea name="comment" id="comment" class="form-control" rows="3"></textarea></div>';

        $content .= '<p><button type="submit" class="btn">Speichern</button></p>';
        $content .= '</form>';

        $content .= '<h2>Erfasste Ergebnisse</h2>';
        if ($resultsCapped) {
            $content .= '<p style="color: var(--text-muted); font-size: 0.9rem;">Es werden die '
                . self::RESULT_LIMIT . ' neuesten Ergebnisse angezeigt.</p>';
        }
        $content .= '<table><thead><tr><th>Pferd</th><th>Veranstaltung</th><th>Datum</th><th>Note</th><th>Platzierung</th><th></th></tr></thead><tbody>';
        foreach ($results as $row) {
            $content .= '<tr>';
            $content .= '<td>' . htmlspecialchars((string) $row['horse_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . htmlspecialchars((string) $row['event_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . htmlspecialchars((string) ($row['event_date'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . htmlspecialchars((string) ($row['score'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td>' . htmlspecialchars((string) ($row['placement'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td><form method="POST" action="/plugin/zuchtschau-ergebnisse/ergebnisse/delete" style="margin:0;" onsubmit="return confirm(\'Ergebnis wirklich löschen? Zugehörige Teilwertungen werden mitgelöscht.\');">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                . '<button type="submit" class="btn btn-secondary" style="color:var(--danger-fg);">Löschen</button></form></td>';
            $content .= '</tr>';

            // Teilwertungen (#82) direkt unter der Ergebniszeile pflegen:
            // vorhandene auflisten (mit Löschen-Knopf) und neue über ein
            // Inline-Formular anlegen - gleiches CRUD-Muster wie beim
            // Ergebnis selbst (anlegen/löschen, kein Bearbeiten).
            $ergebnisId = (int) $row['id'];
            $content .= '<tr><td colspan="6" class="zuchtschau-tw-zelle">';
            $content .= '<details><summary>Teilwertungen ('
                . count($teilwertungenByErgebnis[$ergebnisId] ?? []) . ')</summary>';

            if (!empty($teilwertungenByErgebnis[$ergebnisId])) {
                $content .= '<table style="font-size:0.9em;"><thead><tr><th>Bezeichnung</th><th>Wertung</th><th>Note</th><th>Platzierung</th><th>Distanz</th><th>Zeit</th><th></th></tr></thead><tbody>';
                foreach ($teilwertungenByErgebnis[$ergebnisId] as $tw) {
                    $content .= '<tr>';
                    $content .= '<td>' . htmlspecialchars((string) ($tw['bezeichnung'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $content .= '<td>' . htmlspecialchars((string) ($tw['wertung'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $content .= '<td>' . htmlspecialchars((string) ($tw['note'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $content .= '<td>' . htmlspecialchars((string) ($tw['platzierung'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $content .= '<td>' . htmlspecialchars((string) ($tw['distanz'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $content .= '<td>' . htmlspecialchars((string) ($tw['zeit'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $content .= '<td><form method="POST" action="/plugin/zuchtschau-ergebnisse/ergebnisse/teilwertung/delete" style="margin:0;" onsubmit="return confirm(\'Teilwertung wirklich löschen?\');">'
                        . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                        . '<input type="hidden" name="id" value="' . (int) $tw['id'] . '">'
                        . '<button type="submit" class="btn btn-secondary" style="color:var(--danger-fg);">Löschen</button></form></td>';
                    $content .= '</tr>';
                }
                $content .= '</tbody></table>';
            }

            $content .= '<form method="POST" action="/plugin/zuchtschau-ergebnisse/ergebnisse/teilwertung/store" class="zuchtschau-tw-form">';
            $content .= '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';
            $content .= '<input type="hidden" name="ergebnis_id" value="' . $ergebnisId . '">';
            $content .= '<input type="text" name="bezeichnung" class="form-control" placeholder="Bezeichnung" aria-label="Bezeichnung" required>';
            $content .= '<input type="text" name="wertung" class="form-control" placeholder="Wertung" aria-label="Wertung">';
            $content .= '<input type="text" name="note" class="form-control" placeholder="Note" aria-label="Note">';
            $content .= '<input type="text" name="platzierung" class="form-control" placeholder="Platzierung" aria-label="Platzierung">';
            $content .= '<input type="text" name="distanz" class="form-control" placeholder="Distanz" aria-label="Distanz">';
            $content .= '<input type="text" name="zeit" class="form-control" placeholder="Zeit" aria-label="Zeit">';
            $content .= '<button type="submit" class="btn">Teilwertung anlegen</button>';
            $content .= '</form>';

            $content .= '</details></td></tr>';
        }
        if (empty($results)) {
            $content .= '<tr><td colspan="6">Noch keine Ergebnisse erfasst.</td></tr>';
        }
        $content .= '</tbody></table>';

        $content .= '<p><a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a></p>';
        $content .= '</div>';

        PluginPage::render('Zuchtschau-Ergebnisse verwalten', $content);
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
            // Zugehörige Teilwertungen räumt die Datenbank selbst ab
            // (FK ON DELETE CASCADE, siehe install()).
            $stmt = Database::getInstance()->prepare('DELETE FROM `plugin_zuchtschau_ergebnisse` WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }

        header('Location: /plugin/zuchtschau-ergebnisse/ergebnisse');
        exit;
    }

    /**
     * Legt eine Teilwertung (#82) zu einem bestehenden Ergebnis an. Alle
     * Fachfelder außer der Bezeichnung sind optional (NULL-tolerant, wie die
     * lückigen Altdaten); die Bezeichnung ist im Admin-Formular Pflicht,
     * damit dort keine unbenennbaren Leerzeilen entstehen.
     */
    public function storeTeilwertung(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $ergebnisId = !empty($_POST['ergebnis_id']) ? (int) $_POST['ergebnis_id'] : null;
        $bezeichnung = trim($_POST['bezeichnung'] ?? '');

        if ($ergebnisId && $bezeichnung !== '') {
            // Existenz des Elternergebnisses vorab prüfen, statt den
            // FK-Fehler als 500er beim Benutzer landen zu lassen (z. B.
            // wenn das Ergebnis in einem zweiten Tab gelöscht wurde).
            $check = Database::getInstance()->prepare(
                'SELECT id FROM `plugin_zuchtschau_ergebnisse` WHERE id = :id'
            );
            $check->execute(['id' => $ergebnisId]);

            if ($check->fetchColumn() !== false) {
                $stmt = Database::getInstance()->prepare(
                    'INSERT INTO `plugin_zuchtschau_teilwertungen`
                        (ergebnis_id, bezeichnung, wertung, note, platzierung, distanz, zeit)
                     VALUES (:ergebnis_id, :bezeichnung, :wertung, :note, :platzierung, :distanz, :zeit)'
                );
                $stmt->execute([
                    'ergebnis_id' => $ergebnisId,
                    'bezeichnung' => $bezeichnung,
                    'wertung' => trim($_POST['wertung'] ?? '') ?: null,
                    'note' => trim($_POST['note'] ?? '') ?: null,
                    'platzierung' => trim($_POST['platzierung'] ?? '') ?: null,
                    'distanz' => trim($_POST['distanz'] ?? '') ?: null,
                    'zeit' => trim($_POST['zeit'] ?? '') ?: null,
                ]);
            }
        }

        header('Location: /plugin/zuchtschau-ergebnisse/ergebnisse');
        exit;
    }

    /**
     * Löscht eine einzelne Teilwertung (#82) - das Ergebnis selbst bleibt
     * bestehen (Gegenrichtung läuft über den FK-CASCADE, siehe delete()).
     */
    public function deleteTeilwertung(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        if ($id) {
            $stmt = Database::getInstance()->prepare('DELETE FROM `plugin_zuchtschau_teilwertungen` WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }

        header('Location: /plugin/zuchtschau-ergebnisse/ergebnisse');
        exit;
    }
}
