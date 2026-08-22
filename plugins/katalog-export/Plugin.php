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
use App\Plugin\PluginAudit;
use App\Plugin\PluginPage;

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
        // Auswahlliste "Deckstation" (#137). Bis v0.7 stand hier
        // `FROM breeding_stations` - eine eigene Tabelle, die AUSSCHLIESSLICH
        // Deckstationen enthielt. Seit Framework#336 liegen Personen und
        // Deckstationen gemeinsam in `contacts`; ein blosses Ersetzen des
        // Tabellennamens haette deshalb JEDEN Kontakt des Verzeichnisses als
        // "Deckstation" zur Auswahl gestellt - bei mehreren tausend Personen
        // ein unbrauchbares Aufklappmenue, das ausserdem etwas behauptet, was
        // nicht stimmt.
        //
        // Deckstation ist seit #336 keine Datensatzart mehr, sondern eine
        // ROLLE: Ein Kontakt ist Deckstation, wenn er in einem der beiden
        // Steckplaetze steht, die auf eine Station zeigen -
        // horses.breeding_station_id oder horse_persons.station_contact_id.
        // Genau das ist auch die Menge, die der Filter unten ueberhaupt
        // treffen kann (bs.name); alles andere waere eine Auswahl, die
        // garantiert null Treffer liefert.
        //
        // Freitext-Stationen (horses.breeding_station ohne Datensatz) bleiben
        // wie bisher aussen vor - sie standen auch vorher nicht im Menue, der
        // Filter findet sie aber weiterhin ueber die Freitextspalte.
        $stations = $db->query(
            "SELECT DISTINCT c.name
             FROM contacts c
             WHERE c.deleted_at IS NULL
               AND (
                   EXISTS (SELECT 1 FROM horses h WHERE h.breeding_station_id = c.id AND h.deleted_at IS NULL)
                   OR EXISTS (
                       SELECT 1 FROM horse_persons hp
                       JOIN horses hph ON hph.id = hp.horse_id AND hph.deleted_at IS NULL
                       WHERE hp.station_contact_id = c.id
                   )
               )
             ORDER BY c.name ASC"
        )->fetchAll(PDO::FETCH_COLUMN);

        // Die Seite rendert als Fragment im Framework-Layout
        // (App\Plugin\PluginPage, Addons#66) - Header, Navigation,
        // Theme-Umschalter, Markenfarben und style.css kommen zentral vom
        // Layout. Hier bleibt nur addon-spezifische Geometrie (Formular-Raster).
        $content = '<style>';
        $content .= '.katalog-export-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;}';
        $content .= '</style>';

        $content .= '<div class="card">';
        $content .= '<h1>📤 Katalog-Export (CSV)</h1>';
        $content .= '<p>Optional filtern, dann als CSV herunterladen - ohne Filter wird der gesamte Katalog exportiert.</p>';
        $content .= '<form method="GET" action="/plugin/katalog-export/csv">';

        $content .= '<div class="form-group"><label for="search">Allgemeine Suche</label><input type="text" name="search" id="search" class="form-control"></div>';

        $content .= '<div class="katalog-export-row">';
        $content .= '<div class="form-group"><label for="q_name">Name</label><input type="text" name="q_name" id="q_name" class="form-control"></div>';
        $content .= '<div class="form-group"><label for="q_ueln">UELN</label><input type="text" name="q_ueln" id="q_ueln" class="form-control"></div>';
        $content .= '</div>';

        $content .= '<div class="katalog-export-row">';
        $content .= '<div class="form-group"><label for="birth_year_from">Geburtsjahr von</label><input type="number" name="birth_year_from" id="birth_year_from" class="form-control"></div>';
        $content .= '<div class="form-group"><label for="birth_year_to">Geburtsjahr bis</label><input type="number" name="birth_year_to" id="birth_year_to" class="form-control"></div>';
        $content .= '</div>';

        // Geschlecht/Rasse (#172-Felder, wie auf der Katalogseite).
        $content .= '<div class="form-group"><label for="q_sex">Geschlecht</label><select name="q_sex" id="q_sex" class="form-control"><option value="">– alle –</option>';
        foreach (['stallion' => 'Hengst', 'mare' => 'Stute', 'gelding' => 'Wallach'] as $value => $labelText) {
            $content .= '<option value="' . $value . '">' . $labelText . '</option>';
        }
        $content .= '</select></div>';

        $content .= '<div class="form-group"><label for="q_breed">Rasse</label><select name="q_breed" id="q_breed" class="form-control"><option value="">– alle –</option>';
        foreach ($breeds as $breed) {
            $content .= '<option value="' . htmlspecialchars((string) $breed, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $breed, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $content .= '</select></div>';

        $content .= '<div class="form-group"><label for="q_color">Farbe</label><select name="q_color" id="q_color" class="form-control"><option value="">– alle –</option>';
        foreach ($colors as $color) {
            $content .= '<option value="' . htmlspecialchars((string) $color, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $color, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $content .= '</select></div>';

        // Status-Split im Framework (#188): status ist nur noch der Zuchtstatus,
        // der Lebensstatus (is_deceased) bekommt ein eigenes Filterfeld.
        $content .= '<div class="form-group"><label for="q_status">Zuchtstatus</label><select name="q_status" id="q_status" class="form-control"><option value="">– alle –</option>';
        foreach (['active' => 'Aktiv', 'inactive' => 'Inaktiv'] as $value => $labelText) {
            $content .= '<option value="' . $value . '">' . $labelText . '</option>';
        }
        $content .= '</select></div>';

        $content .= '<div class="form-group"><label for="q_deceased">Lebensstatus</label><select name="q_deceased" id="q_deceased" class="form-control"><option value="">– alle –</option>';
        foreach (['0' => 'Nur lebende', '1' => 'Nur verstorbene'] as $value => $labelText) {
            $content .= '<option value="' . $value . '">' . $labelText . '</option>';
        }
        $content .= '</select></div>';

        $content .= '<div class="form-group"><label for="q_station">Deckstation</label><select name="q_station" id="q_station" class="form-control"><option value="">– alle –</option>';
        foreach ($stations as $station) {
            $content .= '<option value="' . htmlspecialchars((string) $station, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $station, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $content .= '</select></div>';

        $content .= '<div class="katalog-export-row">';
        $content .= '<div class="form-group"><label for="q_sire">Vater</label><input type="text" name="q_sire" id="q_sire" class="form-control"></div>';
        $content .= '<div class="form-group"><label for="q_dam">Mutter</label><input type="text" name="q_dam" id="q_dam" class="form-control"></div>';
        $content .= '</div>';

        $content .= '<div class="katalog-export-row">';
        $content .= '<div class="form-group"><label for="q_breeder">Züchter</label><input type="text" name="q_breeder" id="q_breeder" class="form-control"></div>';
        $content .= '<div class="form-group"><label for="q_owner">Besitzer</label><input type="text" name="q_owner" id="q_owner" class="form-control"></div>';
        $content .= '</div>';

        $content .= '<p><button type="submit" class="btn">⬇️ Als CSV herunterladen</button></p>';
        $content .= '</form>';
        $content .= '<p><a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a></p>';
        $content .= '</div>';

        PluginPage::render('Katalog-Export', $content);
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

        // Kontakt-Treffer über EXISTS statt über multiplizierende JOINs -
        // dieselbe Bauart wie PublicController::catalog() im Kern (#125).
        // Ohne is_published-Einschränkung: Der Export ist bewusst eine
        // Backoffice-Funktion und enthält auch unveröffentlichte Kontakte
        // (siehe README).
        // Seit Framework#336 heisst die Tabelle `contacts` und die Spalte
        // `horse_persons.contact_id`; die ROLLE kommt aus `horse_persons.role`
        // und nicht mehr aus der Wahl der Tabelle (#137).
        // Seit Framework #246 gilt dort die Regel: Wo ueln/foreign_ueln
        // durchsucht wird, wird auch die Kindtabelle horse_registrations
        // (weitere Lebensnummern) einbezogen - foreign_ueln bleibt als
        // Kompatibilitäts-Fallback dabei. Der Export spiegelt die Filter des
        // Kerns (ApiController::buildFilters()) und zieht deshalb mit; sonst
        // fände der Export ein Pferd nicht, das der Katalog über dieselbe
        // Nummer findet.
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = "(h.name LIKE ? OR h.ueln LIKE ? OR h.foreign_ueln LIKE ? OR h.sire_name LIKE ? OR h.dam_name LIKE ? OR bs.name LIKE ? OR h.breeding_station LIKE ? OR EXISTS (
                SELECT 1 FROM horse_registrations hreg
                WHERE hreg.horse_id = h.id AND hreg.registration_number LIKE ?
            ) OR EXISTS (
                SELECT 1 FROM horse_persons hps
                JOIN contacts ps ON ps.id = hps.contact_id AND ps.deleted_at IS NULL
                WHERE hps.horse_id = h.id AND ps.name LIKE ?
            ))";
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }
        if ($qName !== '') {
            $where[] = "h.name LIKE ?";
            $params[] = '%' . $qName . '%';
        }
        if ($qUeln !== '') {
            $like = '%' . $qUeln . '%';
            $where[] = "(h.ueln LIKE ? OR h.foreign_ueln LIKE ? OR EXISTS (
                SELECT 1 FROM horse_registrations hreg
                WHERE hreg.horse_id = h.id AND hreg.registration_number LIKE ?
            ))";
            array_push($params, $like, $like, $like);
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
            $where[] = "EXISTS (
                SELECT 1 FROM horse_persons hpb
                JOIN contacts pb ON pb.id = hpb.contact_id AND pb.deleted_at IS NULL
                WHERE hpb.horse_id = h.id AND hpb.role = 'breeder' AND pb.name LIKE ?
            )";
            $params[] = '%' . $qBreeder . '%';
        }
        if ($qOwner !== '') {
            $where[] = "EXISTS (
                SELECT 1 FROM horse_persons hpo
                JOIN contacts po ON po.id = hpo.contact_id AND po.deleted_at IS NULL
                WHERE hpo.horse_id = h.id AND hpo.role = 'owner' AND po.name LIKE ?
            )";
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

        // Züchter/Besitzer aggregiert statt über multiplizierende JOINs, analog
        // zu PublicController::catalog() im Kern (#125, dort $personAggregateJoin):
        // Ein Pferd mit mehreren Züchtern/Besitzern (Besitzerhistorie über
        // from_year/until_year!) erzeugt so genau EINE CSV-Zeile, die Namen
        // stehen kommasepariert in ihrer Spalte. Das frühere DISTINCT konnte
        // die Vervielfachung nicht kompensieren, weil sich die Zeilen in
        // breeder_name/owner_name unterschieden (Addons#70). Alle
        // verbleibenden JOINs sind 1:1, daher ist kein DISTINCT nötig.
        // Abweichend vom Kern ohne is_published-Einschränkung: Backoffice-
        // Export, unveröffentlichte Kontakte sind hier gewollt (siehe README).
        //
        // FELDLISTE DER KONTAKTE - bitte beim Ändern lesen (#137).
        // Aus `contacts` wird ausschliesslich `name` gelesen: als Stationsname
        // (bs.name) und als Züchter-/Besitzername (p.name). Das ist bewusst so
        // und keine Nachlässigkeit.
        //
        // Bis v0.7 zog dieser Export seine Stationsangabe aus
        // `breeding_stations` - einer Tabelle ohne personenbezogene Felder im
        // engeren Sinn - und seine Namen aus `persons`. Seit Framework#336 ist
        // beides EINE Tabelle, und die trägt zusätzlich email, phone, mobile,
        // street, house_number, postal_code, address, contact_person und das
        // interne Freitextfeld contact_info. Ein `SELECT *` oder eine bequem
        // erweiterte Spaltenliste würde diese Felder ab v0.8 in eine CSV-Datei
        // schreiben, die vorher nur Namen enthielt - und die per E-Mail
        // weitergereicht, in eine Tabellenkalkulation geladen und auf
        // Fremdrechnern abgelegt wird, wo keine Löschfrist sie mehr erreicht.
        //
        // Wer hier Kontaktspalten ergänzen will, wendet dieselbe Regel an wie
        // die öffentliche Seite (docs/kontaktliste-umstellung.md,
        // "Datenschutz-Grenze"):
        //   immer:                 id, name, city, state, country, website,
        //                          is_breeder  (membership_status stand bis
        //                          v0.8 mit hier und ist mit Framework#349
        //                          entfallen)
        //   nur bei contact_public=1: email, phone, mobile, street,
        //                          house_number, postal_code, address,
        //                          contact_person
        //   nie:                   contact_info
        // Kein `SELECT *` auf `contacts`, auch nicht in diesem
        // rechtegeschützten Pfad.
        $sql = "
            SELECT
                h.id, h.name, h.ueln, h.foreign_ueln, h.birth_year, h.birth_date, h.birth_date_precision, h.color, h.sex, h.breed, h.height_cm, h.status, h.is_deceased, h.death_year,
                COALESCE(bs.name, h.breeding_station) AS station_name,
                COALESCE(sire.name, h.sire_name) AS sire_display,
                COALESCE(dam.name, h.dam_name) AS dam_display,
                hpx.breeder_name,
                hpx.owner_name
            FROM horses h
            LEFT JOIN contacts bs ON h.breeding_station_id = bs.id AND bs.deleted_at IS NULL
            LEFT JOIN horses sire ON h.sire_id = sire.id AND sire.deleted_at IS NULL
            LEFT JOIN horses dam ON h.dam_id = dam.id AND dam.deleted_at IS NULL
            LEFT JOIN (
                SELECT hp.horse_id,
                       GROUP_CONCAT(DISTINCT CASE WHEN hp.role = 'breeder' THEN p.name END SEPARATOR ', ') AS breeder_name,
                       GROUP_CONCAT(DISTINCT CASE WHEN hp.role = 'owner' THEN p.name END SEPARATOR ', ') AS owner_name
                FROM horse_persons hp
                JOIN contacts p ON p.id = hp.contact_id AND p.deleted_at IS NULL
                GROUP BY hp.horse_id
            ) hpx ON hpx.horse_id = h.id
            WHERE {$whereSql}
            ORDER BY h.name ASC
        ";

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Protokoll (#352): Ein Export ist eine der Aktionen, die
        // docs/plugin-development.md ausdrücklich nennt ("importieren,
        // exportieren, versenden"). Er ändert zwar nichts im Verzeichnis,
        // trägt aber den halben Bestand samt Züchter- und Besitzernamen aus
        // dem System heraus - genau das, wovon hinterher jemand wissen will,
        // wann es geschah und wer es tat.
        //
        // Protokolliert werden nur die NAMEN der gesetzten Filterfelder und
        // die Zeilenzahl, nicht die eingegebenen Werte: `q_breeder=Müller` ist
        // ein Personenname, und das Protokoll wird dauerhaft aufbewahrt und
        // von keiner Löschfrist erfasst.
        $aktiveFilter = array_keys(array_filter([
            'search' => $search !== '',
            'q_name' => $qName !== '',
            'q_ueln' => $qUeln !== '',
            'birth_year_from' => $birthYearFrom !== null,
            'birth_year_to' => $birthYearTo !== null,
            'q_color' => $qColor !== '',
            'q_sex' => $qSex !== '',
            'q_breed' => $qBreed !== '',
            'q_status' => $qStatus !== '',
            'q_deceased' => $qDeceased !== '',
            'q_breeder' => $qBreeder !== '',
            'q_owner' => $qOwner !== '',
            'q_station' => $qStation !== '',
            'q_sire' => $qSire !== '',
            'q_dam' => $qDam !== '',
        ]));
        PluginAudit::log(
            'katalog-export',
            'Katalog als CSV exportiert',
            count($rows) . ' Datensätze',
            $aktiveFilter === [] ? 'ohne Filter' : 'gesetzte Filter: ' . implode(', ', $aktiveFilter)
        );

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
                // Ein Geburtsdatum nur dann, wenn es TAGESGENAU ist
                // (Framework#379). Steht dort 'year', sind Monat und Tag
                // Platzhalter - in dieser Branche der 1. Januar, im
                // Altbestand bei knapp der Haelfte aller Pferde.
                //
                // Diese Datei wird per E-Mail weitergereicht und in eine
                // Tabellenkalkulation geladen; dort steht der Tag dann als
                // Tatsache, und zwar weiter weg von jeder Korrektur als die
                // Seite, von der er stammt. Das Jahr steht ohnehin in der
                // Spalte daneben und ist die Angabe, die stimmt.
                //
                // Die Zelle bleibt LEER statt das Jahr zu wiederholen: Genau
                // so sieht die Form aus, die der CSV-Import des Kerns als
                // "nur das Jahr bekannt" annimmt - leeres birth_date,
                // gefuelltes birth_year. Die Datei laesst sich damit ohne
                // Zeilenfehler zurueckspielen.
                (($row['birth_date_precision'] ?? 'day') === 'year') ? '' : $row['birth_date'],
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
