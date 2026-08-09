<?php
// katalog-export/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: CSV-Export des Pferdekatalogs,
// wahlweise gefiltert (dieselben Filterfelder wie der öffentliche Katalog,
// siehe PublicController::catalog() im Framework-Repo) oder komplett
// ungefiltert. Bewusst CSV statt eines echten .xlsx-Formats - öffnet ohne
// Zusatzsoftware in Excel/LibreOffice/Numbers und braucht keine zusätzliche
// Composer-Abhängigkeit (Philosophie des Kerns, siehe
// docs/plugin-development.md: "keine externen Abhängigkeiten").
//
// Installation (lokal im Framework-Repo):
//   cp -r katalog-export plugins/katalog-export
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// der gewünschten Gruppe unter /admin/groups die Berechtigung
// "Katalog-Export -> Exportieren" zuweisen.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\KatalogExport;

use App\Controllers\BaseController;
use App\Database;
use PDO;
use App\Plugin\HookManager;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
    }

    public function addDashboardTile(array $tiles): array {
        $tiles[] = [
            'url' => '/plugin/katalog-export/formular',
            'label' => 'Katalog-Export',
            'icon' => '📤',
        ];
        return $tiles;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'katalog-export',
                'action' => 'export',
                'label' => 'Exportieren',
                'module_label' => 'Katalog-Export',
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
                'path' => '/formular',
                'callback' => [ExportController::class, 'form'],
            ],
            [
                'method' => 'GET',
                'path' => '/csv',
                'callback' => [ExportController::class, 'exportCsv'],
            ],
        ];
    }
}

class ExportController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('katalog-export', 'export');
    }

    public function form(): void {
        $db = Database::getInstance();
        $colors = $db->query("SELECT DISTINCT color FROM horses WHERE color IS NOT NULL AND color != '' AND deleted_at IS NULL ORDER BY color ASC")->fetchAll(PDO::FETCH_COLUMN);
        $breeds = $db->query("SELECT DISTINCT breed FROM horses WHERE breed IS NOT NULL AND breed != '' AND deleted_at IS NULL ORDER BY breed ASC")->fetchAll(PDO::FETCH_COLUMN);
        $stations = $db->query("SELECT DISTINCT name FROM breeding_stations WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Katalog-Export</title>';
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
        echo '<style>body{font-family:sans-serif;padding:2rem;max-width:700px;margin:0 auto;background:var(--bg-color);}
            label{display:block;margin-top:0.9rem;font-weight:bold;font-size:0.9rem;}
            input,select{width:100%;padding:0.4rem;margin-top:0.2rem;}
            .row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}</style>';
        echo '</head><body>';
        echo '<h1>📤 Katalog-Export (CSV)</h1>';
        echo '<p>Optional filtern, dann als CSV herunterladen - ohne Filter wird der gesamte Katalog exportiert.</p>';
        echo '<form method="GET" action="/plugin/katalog-export/csv">';

        echo '<label for="search">Allgemeine Suche</label><input type="text" name="search" id="search">';

        echo '<div class="row">';
        echo '<div><label for="q_name">Name</label><input type="text" name="q_name" id="q_name"></div>';
        echo '<div><label for="q_ueln">UELN</label><input type="text" name="q_ueln" id="q_ueln"></div>';
        echo '</div>';

        echo '<div class="row">';
        echo '<div><label for="birth_year_from">Geburtsjahr von</label><input type="number" name="birth_year_from" id="birth_year_from"></div>';
        echo '<div><label for="birth_year_to">Geburtsjahr bis</label><input type="number" name="birth_year_to" id="birth_year_to"></div>';
        echo '</div>';

        // Geschlecht/Rasse (#172-Felder, wie auf der Katalogseite).
        echo '<label for="q_sex">Geschlecht</label><select name="q_sex" id="q_sex"><option value="">– alle –</option>';
        foreach (['stallion' => 'Hengst', 'mare' => 'Stute', 'gelding' => 'Wallach'] as $value => $labelText) {
            echo '<option value="' . $value . '">' . $labelText . '</option>';
        }
        echo '</select>';

        echo '<label for="q_breed">Rasse</label><select name="q_breed" id="q_breed"><option value="">– alle –</option>';
        foreach ($breeds as $breed) {
            echo '<option value="' . htmlspecialchars((string) $breed, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $breed, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';

        echo '<label for="q_color">Farbe</label><select name="q_color" id="q_color"><option value="">– alle –</option>';
        foreach ($colors as $color) {
            echo '<option value="' . htmlspecialchars((string) $color, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $color, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';

        // Status-Split im Framework (#188): status ist nur noch der Zuchtstatus,
        // der Lebensstatus (is_deceased) bekommt ein eigenes Filterfeld.
        echo '<label for="q_status">Zuchtstatus</label><select name="q_status" id="q_status"><option value="">– alle –</option>';
        foreach (['active' => 'Aktiv', 'inactive' => 'Inaktiv'] as $value => $labelText) {
            echo '<option value="' . $value . '">' . $labelText . '</option>';
        }
        echo '</select>';

        echo '<label for="q_deceased">Lebensstatus</label><select name="q_deceased" id="q_deceased"><option value="">– alle –</option>';
        foreach (['0' => 'Nur lebende', '1' => 'Nur verstorbene'] as $value => $labelText) {
            echo '<option value="' . $value . '">' . $labelText . '</option>';
        }
        echo '</select>';

        echo '<label for="q_station">Deckstation</label><select name="q_station" id="q_station"><option value="">– alle –</option>';
        foreach ($stations as $station) {
            echo '<option value="' . htmlspecialchars((string) $station, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $station, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';

        echo '<div class="row">';
        echo '<div><label for="q_sire">Vater</label><input type="text" name="q_sire" id="q_sire"></div>';
        echo '<div><label for="q_dam">Mutter</label><input type="text" name="q_dam" id="q_dam"></div>';
        echo '</div>';

        echo '<div class="row">';
        echo '<div><label for="q_breeder">Züchter</label><input type="text" name="q_breeder" id="q_breeder"></div>';
        echo '<div><label for="q_owner">Besitzer</label><input type="text" name="q_owner" id="q_owner"></div>';
        echo '</div>';

        echo '<p><button type="submit" style="margin-top:1.2rem;padding:0.6rem 1.2rem;">⬇️ Als CSV herunterladen</button></p>';
        echo '</form>';
        echo '<p><a href="/admin">Zurück zum Dashboard</a></p>';
        echo '</body></html>';
    }

    public function exportCsv(): void {
        $where = ["h.deleted_at IS NULL"];
        $params = [];

        $qName = trim($_GET['q_name'] ?? '');
        $qUeln = trim($_GET['q_ueln'] ?? '');
        $search = trim($_GET['search'] ?? '');
        $birthYearFrom = !empty($_GET['birth_year_from']) ? (int) $_GET['birth_year_from'] : null;
        $birthYearTo = !empty($_GET['birth_year_to']) ? (int) $_GET['birth_year_to'] : null;
        $qColor = trim($_GET['q_color'] ?? '');
        // Zuchtstatus-Whitelist seit dem Status-Split (Framework #188);
        // der Lebensstatus filtert separat über q_deceased. Der Alt-Wert
        // q_status=deceased mappt wie auf der Katalogseite (PublicController)
        // auf den Lebensstatus - kopierte /katalog?...-Query-Strings bleiben
        // damit funktional.
        $qStatus = in_array($_GET['q_status'] ?? '', ['active', 'inactive'], true) ? $_GET['q_status'] : '';
        $qDeceased = ($_GET['q_deceased'] ?? '') === '0' || ($_GET['q_deceased'] ?? '') === '1' ? $_GET['q_deceased'] : '';
        if (($_GET['q_status'] ?? '') === 'deceased') {
            $qDeceased = '1';
        }
        // Geschlecht/Rasse wie auf der Katalogseite: q_sex Whitelist gegen die
        // ENUM-Werte, q_breed Teilstring-Suche.
        $qSex = in_array($_GET['q_sex'] ?? '', ['stallion', 'mare', 'gelding'], true) ? $_GET['q_sex'] : '';
        $qBreed = trim($_GET['q_breed'] ?? '');
        $qBreeder = trim($_GET['q_breeder'] ?? '');
        $qOwner = trim($_GET['q_owner'] ?? '');
        $qStation = trim($_GET['q_station'] ?? '');
        $qSire = trim($_GET['q_sire'] ?? '');
        $qDam = trim($_GET['q_dam'] ?? '');

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = "(h.name LIKE ? OR h.ueln LIKE ? OR h.foreign_ueln LIKE ? OR h.sire_name LIKE ? OR h.dam_name LIKE ? OR bs.name LIKE ? OR h.breeding_station LIKE ? OR p_breeder.name LIKE ? OR p_owner.name LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }
        if ($qName !== '') {
            $where[] = "h.name LIKE ?";
            $params[] = '%' . $qName . '%';
        }
        if ($qUeln !== '') {
            $like = '%' . $qUeln . '%';
            $where[] = "(h.ueln LIKE ? OR h.foreign_ueln LIKE ?)";
            array_push($params, $like, $like);
        }
        if ($birthYearFrom !== null) {
            $where[] = "h.birth_year >= ?";
            $params[] = $birthYearFrom;
        }
        if ($birthYearTo !== null) {
            $where[] = "h.birth_year <= ?";
            $params[] = $birthYearTo;
        }
        if ($qColor !== '') {
            $where[] = "h.color LIKE ?";
            $params[] = '%' . $qColor . '%';
        }
        if ($qSex !== '') {
            $where[] = "h.sex = ?";
            $params[] = $qSex;
        }
        if ($qBreed !== '') {
            $where[] = "h.breed LIKE ?";
            $params[] = '%' . $qBreed . '%';
        }
        if ($qStatus !== '') {
            $where[] = "h.status = ?";
            $params[] = $qStatus;
        }
        if ($qDeceased !== '') {
            $where[] = "h.is_deceased = ?";
            $params[] = (int) $qDeceased;
        }
        if ($qBreeder !== '') {
            $where[] = "p_breeder.name LIKE ?";
            $params[] = '%' . $qBreeder . '%';
        }
        if ($qOwner !== '') {
            $where[] = "p_owner.name LIKE ?";
            $params[] = '%' . $qOwner . '%';
        }
        if ($qStation !== '') {
            $where[] = "(bs.name LIKE ? OR h.breeding_station LIKE ?)";
            $params[] = '%' . $qStation . '%';
            $params[] = '%' . $qStation . '%';
        }
        if ($qSire !== '') {
            $where[] = "(sire.name LIKE ? OR h.sire_name LIKE ?)";
            $params[] = '%' . $qSire . '%';
            $params[] = '%' . $qSire . '%';
        }
        if ($qDam !== '') {
            $where[] = "(dam.name LIKE ? OR h.dam_name LIKE ?)";
            $params[] = '%' . $qDam . '%';
            $params[] = '%' . $qDam . '%';
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT DISTINCT
                h.id, h.name, h.ueln, h.foreign_ueln, h.birth_year, h.birth_date, h.color, h.sex, h.breed, h.height_cm, h.status, h.is_deceased, h.death_year,
                COALESCE(bs.name, h.breeding_station) AS station_name,
                COALESCE(sire.name, h.sire_name) AS sire_display,
                COALESCE(dam.name, h.dam_name) AS dam_display,
                p_breeder.name AS breeder_name,
                p_owner.name AS owner_name
            FROM horses h
            LEFT JOIN breeding_stations bs ON h.breeding_station_id = bs.id AND bs.deleted_at IS NULL
            LEFT JOIN horses sire ON h.sire_id = sire.id AND sire.deleted_at IS NULL
            LEFT JOIN horses dam ON h.dam_id = dam.id AND dam.deleted_at IS NULL
            LEFT JOIN horse_persons hp_breeder ON hp_breeder.horse_id = h.id AND hp_breeder.role = 'breeder'
            LEFT JOIN persons p_breeder ON hp_breeder.person_id = p_breeder.id AND p_breeder.deleted_at IS NULL
            LEFT JOIN horse_persons hp_owner ON hp_owner.horse_id = h.id AND hp_owner.role = 'owner'
            LEFT JOIN persons p_owner ON hp_owner.person_id = p_owner.id AND p_owner.deleted_at IS NULL
            WHERE {$whereSql}
            ORDER BY h.name ASC
        ";

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        // "verzeichnis-" statt "hengstkatalog-" (#57): exportiert werden ALLE
        // Pferde des Katalogs, nicht nur Hengste - analog zur Katalog-
        // Umbenennung im Framework (dort #170).
        header('Content-Disposition: attachment; filename="verzeichnis-' . date('Y-m-d') . '.csv"');

        // UTF-8 BOM, damit Excel unter Windows Umlaute korrekt erkennt statt
        // die Datei fälschlich als ANSI zu interpretieren.
        echo "\xEF\xBB\xBF";

        // $escape MUSS explizit leer sein. PHPs Vorgabe "\\" ist kein CSV-Standard:
        // Ein Zellwert, der \" enthält, wird damit als \" statt als "" geschrieben,
        // und jeder RFC-4180-Parser (Excel, LibreOffice) sieht das Feld an dieser
        // Stelle enden. Alles danach landet in NEUEN Feldern, die csvSafe() nie
        // gesehen hat — ein Wert wie `Name\";=FORMEL(...)` schleust so trotz
        // csvSafe() eine Formel ein. Mit '' wird das " regelkonform verdoppelt und
        // der Wert bleibt ein Feld. PHP 8.4+ verlangt den Parameter ohnehin
        // ausdrücklich (Deprecation), weil sich die Vorgabe ändern wird.
        $out = fopen('php://output', 'w');
        // Geschlecht als kanonischer ENUM-Wert (stallion/mare/gelding) wie die
        // Status-Spalte - der CSV-Import des Frameworks nimmt ihn direkt an.
        fputcsv($out, ['ID', 'Name', 'UELN', 'Fremd-UELN', 'Geburtsjahr', 'Geburtsdatum', 'Farbe', 'Geschlecht', 'Rasse', 'Stockmaß (cm)', 'Status', 'Verstorben', 'Todesjahr', 'Deckstation', 'Vater', 'Mutter', 'Züchter', 'Besitzer'], ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'csvSafe'], [
                $row['id'],
                $row['name'],
                $row['ueln'],
                $row['foreign_ueln'],
                $row['birth_year'],
                $row['birth_date'],
                $row['color'],
                $row['sex'],
                $row['breed'],
                $row['height_cm'],
                $row['status'],
                $row['is_deceased'] ? 'ja' : 'nein',
                $row['death_year'],
                $row['station_name'],
                $row['sire_display'],
                $row['dam_display'],
                $row['breeder_name'],
                $row['owner_name'],
            ]), ';', '"', '');
        }
        fclose($out);
        exit;
    }

    /**
     * Neutralisiert CSV-/Formel-Injection: Tabellenkalkulationen (Excel,
     * LibreOffice) interpretieren einen Zellwert, der mit =, +, -, @ oder einem
     * Steuerzeichen (Tab/CR) beginnt, als Formel. Ein bösartig gesetzter
     * Pferde-/Personenname wie `=HYPERLINK(...)` könnte so beim Öffnen der
     * exportierten Datei ausgeführt werden. Ein vorangestelltes Hochkomma zwingt
     * die Zelle in reinen Text, ohne die angezeigten Daten zu verändern.
     */
    private static function csvSafe($value): string
    {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
