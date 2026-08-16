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
        $hooks->addFilter('horse.edit_sections', [$this, 'addEditSection']);
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
     * Filter (#87, Framework#255): hängt die Erfassung direkt in das
     * Admin-Bearbeitungsformular des Hengstes.
     *
     * Das ist der eigentliche Fix für #87: Im Pferdekontext ist die horse_id
     * durch die Seite bereits gegeben - die Auswahl über den gesamten Bestand
     * entfällt ersatzlos, und geladen wird nur noch, was zu diesem einen Pferd
     * gehört.
     *
     * Auf einem Kern ohne den Hook passiert schlicht nichts; die
     * Verwaltungsseite bleibt deshalb als Erfassungsweg bestehen.
     */
    public function addEditSection(array $sections, array $horse): array {
        // Das Bearbeitungsformular verlangt horses.edit, diese Daten aber
        // titel-praemierungen.manage. Ohne diese Prüfung sähe ein Redakteur ein
        // Formular, das beim Absenden 403 liefert. Fail-closed.
        if (!\App\Permission\GroupMembership::hasPermission(
            (int) ($_SESSION['user_id'] ?? 0), 'titel-praemierungen', 'manage'
        )) {
            return $sections;
        }

        $horseId = (int) ($horse['id'] ?? 0);
        if ($horseId <= 0) {
            return $sections;
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT id, art, bezeichnung, jahr, kommentar
             FROM `plugin_titel_praemierungen`
             WHERE horse_id = :id
             ORDER BY FIELD(art, \'titel\', \'praemierung\', \'erfolg\'), jahr DESC, id DESC'
        );
        $stmt->execute(['id' => $horseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $csrfToken = Router::generateCsrfToken();
        $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $html = '<h3 style="margin-top:0;">🏅 Titel &amp; Prämierungen</h3>';

        if ($rows) {
            $html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:1rem;">';
            $html .= '<thead><tr style="text-align:left;border-bottom:2px solid var(--border-color);">'
                . '<th>Art</th><th>Bezeichnung</th><th>Jahr</th><th></th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr style="border-bottom:1px solid var(--border-color);">'
                    . '<td>' . $esc(Plugin::ART_LABELS[$row['art']] ?? $row['art']) . '</td>'
                    . '<td>' . $esc($row['bezeichnung']) . '</td>'
                    . '<td>' . $esc($row['jahr'] ?? '–') . '</td>'
                    . '<td><form method="POST" action="/plugin/titel-praemierungen/auszeichnungen/delete" style="margin:0;"'
                    . ' onsubmit="return confirm(\'Auszeichnung wirklich löschen?\');">'
                    . '<input type="hidden" name="csrf_token" value="' . $esc($csrfToken) . '">'
                    . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                    . '<input type="hidden" name="horse_id" value="' . $horseId . '">'
                    . '<input type="hidden" name="zurueck" value="pferd">'
                    . '<button type="submit" class="btn btn-secondary" style="color:var(--danger-fg);">Löschen</button>'
                    . '</form></td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<p style="color:var(--text-muted);">Für dieses Pferd ist noch keine Auszeichnung erfasst.</p>';
        }

        // Eigenes Formular mit eigener POST-Route: Der Abschnitt steht
        // ausserhalb des Kern-Formulars (Framework#255), der Speichern-Knopf
        // oben speichert diese Felder also NICHT mit.
        $html .= '<form method="POST" action="/plugin/titel-praemierungen/auszeichnungen/store">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $esc($csrfToken) . '">';
        $html .= '<input type="hidden" name="horse_id" value="' . $horseId . '">';
        $html .= '<input type="hidden" name="zurueck" value="pferd">';
        $html .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">';
        $html .= '<div class="form-group"><label for="tp_art">Art</label>'
            . '<select name="art" id="tp_art" class="form-control" required>';
        foreach (Plugin::ART_LABELS as $value => $label) {
            $html .= '<option value="' . $esc($value) . '">' . $esc($label) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div class="form-group"><label for="tp_jahr">Jahr</label>'
            . '<input type="number" name="jahr" id="tp_jahr" class="form-control" min="1900" max="2155" placeholder="z. B. 2019"></div>';
        $html .= '</div>';
        $html .= '<div class="form-group"><label for="tp_bezeichnung">Bezeichnung</label>'
            . '<input type="text" name="bezeichnung" id="tp_bezeichnung" class="form-control" maxlength="200" required'
            . ' placeholder="z. B. Elitehengst, Bundeschampion"></div>';
        $html .= '<div class="form-group"><label for="tp_kommentar">Kommentar</label>'
            . '<textarea name="kommentar" id="tp_kommentar" class="form-control" rows="2"></textarea></div>';
        // Beschriftung bewusst nicht "Speichern": Auf der Seite gibt es zwei
        // Knöpfe, und wer hier drückt, verliert ungespeicherte Stammdaten oben.
        $html .= '<p><button type="submit" class="btn">Auszeichnung hinzufügen</button>'
            . ' <span style="color:var(--text-muted);font-size:0.85rem;">Änderungen an den Stammdaten oben bitte zuerst speichern.</span></p>';
        $html .= '</form>';

        $sections[] = $html;
        return $sections;
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
                'method' => 'GET',
                'path' => '/auszeichnungen/suche',
                'callback' => [AuszeichnungenController::class, 'suche'],
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

    /** Treffergrenze der Pferdesuche (#87), wie in der Galerie. */
    private const SEARCH_LIMIT = 50;

    /** Einträge je Seite der bestandsweiten Übersicht (#87). */
    private const ENTRIES_PER_PAGE = 50;

    public function index(): void {
        $db = Database::getInstance();

        // Die Pferdeauswahl lädt NICHT mehr den gesamten Bestand (#87). Sie
        // läuft jetzt wie in der Galerie über ein Textfeld mit datalist und
        // einer AJAX-Suche, begrenzt auf SEARCH_LIMIT Treffer.
        //
        // Auch die Liste unten war ungedeckelt und wuchs mit dem Bestand -
        // sie paginiert jetzt. Beide Vollscans zusammen waren das eigentliche
        // Performance-Problem des Issues, nicht nur das <select>.
        $total = (int) $db->query('SELECT COUNT(*) FROM `plugin_titel_praemierungen`')->fetchColumn();
        $totalPages = max(1, (int) ceil($total / self::ENTRIES_PER_PAGE));
        $page = max(1, (int) ($_GET['seite'] ?? 1));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * self::ENTRIES_PER_PAGE;

        $stmt = $db->prepare(
            'SELECT t.*, h.name AS horse_name
             FROM `plugin_titel_praemierungen` t
             JOIN horses h ON h.id = t.horse_id
             ORDER BY t.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('limit', self::ENTRIES_PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

        // Textfeld + datalist statt eines <option> je Pferd im Bestand (#87).
        // Die gewählte ID landet per JS im Hidden-Feld; ohne JavaScript löst
        // store() den getippten Text über resolveHorseId() auf.
        $content .= '<div class="form-group"><label for="horse_q">Pferd</label>'
            . '<input type="text" name="horse_q" id="horse_q" class="form-control" list="horse_q_liste" autocomplete="off"'
            . ' placeholder="Namen eintippen und Vorschlag auswählen …" required>'
            . '<datalist id="horse_q_liste"></datalist>'
            . '<input type="hidden" name="horse_id" id="horse_id" value="">'
            . '</div>';

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

        // Vorschlagsliste per AJAX (#87), gleiches Muster wie in der Galerie.
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
        fetch("/plugin/titel-praemierungen/auszeichnungen/suche?q=" + encodeURIComponent(q))
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

        $content .= '<h2>Erfasste Auszeichnungen</h2>';
        $content .= '<table><thead><tr><th>Pferd</th><th>Art</th><th>Bezeichnung</th><th>Jahr</th><th></th></tr></thead><tbody>';
        foreach ($entries as $row) {
            $artLabel = Plugin::ART_LABELS[$row['art']] ?? (string) $row['art'];
            $content .= '<tr>';
            $content .= '<td><a href="/admin/horses/edit?id=' . (int) $row['horse_id'] . '">'
                . htmlspecialchars((string) $row['horse_name'], ENT_QUOTES, 'UTF-8') . '</a></td>';
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

        if ($totalPages > 1) {
            $content .= '<p>';
            if ($page > 1) {
                $content .= '<a class="btn btn-secondary" href="/plugin/titel-praemierungen/auszeichnungen?seite=' . ($page - 1) . '">&laquo; Zurück</a> ';
            }
            $content .= '<span style="color:var(--text-muted);">Seite ' . $page . ' von ' . $totalPages . '</span>';
            if ($page < $totalPages) {
                $content .= ' <a class="btn btn-secondary" href="/plugin/titel-praemierungen/auszeichnungen?seite=' . ($page + 1) . '">Weiter &raquo;</a>';
            }
            $content .= '</p>';
        }

        $content .= '<p><a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a></p>';
        $content .= '</div>';

        PluginPage::render('Titel & Prämierungen verwalten', $content);
    }

    public function store(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $db = Database::getInstance();
        $horseId = !empty($_POST['horse_id']) ? (int) $_POST['horse_id'] : null;
        // Ohne JavaScript bleibt horse_id leer - dann den getippten Text
        // auflösen (#87, Muster aus der Galerie).
        if ($horseId === null && trim((string) ($_POST['horse_q'] ?? '')) !== '') {
            $horseId = $this->resolveHorseId($db, trim((string) $_POST['horse_q']));
        }
        $art = trim($_POST['art'] ?? '');
        $bezeichnung = trim($_POST['bezeichnung'] ?? '');

        // Jahr nur als plausibler vierstelliger Wert - alles andere wird
        // bewusst zu NULL (das Feld ist optional, kein Grund zum Abbruch).
        $jahr = null;
        if (isset($_POST['jahr']) && preg_match('/^\d{4}$/', trim((string) $_POST['jahr']))) {
            $jahr = (int) trim((string) $_POST['jahr']);
        }

        if ($horseId && $bezeichnung !== '' && isset(Plugin::ART_LABELS[$art])) {
            $stmt = $db->prepare(
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

        $this->redirectBack($horseId);
    }

    public function delete(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $db = Database::getInstance();
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;

        // Die horse_id VOR dem DELETE aus der Zeile lesen - danach ist sie
        // nicht mehr zu holen, und dem POST allein ist sie nicht zu glauben.
        $horseId = null;
        if ($id) {
            $stmt = $db->prepare('SELECT horse_id FROM `plugin_titel_praemierungen` WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $found = $stmt->fetchColumn();
            $horseId = $found !== false ? (int) $found : null;

            $stmt = $db->prepare('DELETE FROM `plugin_titel_praemierungen` WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }

        $this->redirectBack($horseId);
    }

    /**
     * JSON-Vorschlagsliste für die Pferdesuche (#87). Liefert höchstens
     * SEARCH_LIMIT Treffer - die Seite lädt damit nie mehr den gesamten
     * Bestand. Zugriffsschutz über den Konstruktor wie die Verwaltungsseite.
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
        if (preg_match('/\[#(\d+)\]\s*$/', $q, $m)) {
            $stmt = $db->prepare('SELECT id FROM horses WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([(int) $m[1]]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int) $id : null;
        }

        if (preg_match('/^(.*\S)\s*\((\d{3,4})\)$/u', $q, $m)) {
            $stmt = $db->prepare('SELECT id FROM horses WHERE deleted_at IS NULL AND name = ? AND birth_year = ? LIMIT 2');
            $stmt->execute([$m[1], (int) $m[2]]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) === 1) {
                return (int) $ids[0];
            }
            if (count($ids) > 1) {
                return null;
            }
        }

        $stmt = $db->prepare('SELECT id FROM horses WHERE deleted_at IS NULL AND name = ? LIMIT 2');
        $stmt->execute([$q]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    /** Anzeige-/Suchlabel eines Pferdes: "Name (Jahrgang)" bzw. nur "Name". */
    private static function horseLabel(array $h): string {
        $label = (string) $h['name'];
        if (!empty($h['birth_year'])) {
            $label .= ' (' . (int) $h['birth_year'] . ')';
        }
        return $label;
    }

    /**
     * Rückweg nach dem Speichern/Löschen. Bewusst KEINE übergebene URL,
     * sondern ein Schalter plus geprüfte Integer - eine mitgeschickte
     * Zieladresse wäre ein offener Redirect.
     */
    private function redirectBack(?int $horseId): never {
        $toHorse = ($_POST['zurueck'] ?? '') === 'pferd' && $horseId !== null && $horseId > 0;
        header('Location: ' . ($toHorse
            ? '/admin/horses/edit?id=' . $horseId
            : '/plugin/titel-praemierungen/auszeichnungen'));
        exit;
    }
}
