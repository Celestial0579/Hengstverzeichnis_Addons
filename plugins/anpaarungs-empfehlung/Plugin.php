<?php
// anpaarungs-empfehlung/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: Anpaarungs-Empfehlung. Für ein
// ausgewähltes Pferd (typischerweise eine Stute) werden mögliche Partner im
// Register nach dem voraussichtlichen Inzuchtkoeffizienten (Wright's COI)
// eines gemeinsamen Fohlens sortiert - die genetisch vielfältigste
// (niedrigster COI) Verpaarung zuerst.
//
// Baut auf demselben Pfad-Koeffizienten-Verfahren auf wie das
// Inzuchtkoeffizient-Addon, bringt die Rechenlogik aber bewusst selbst mit
// (eigenständige Klasse CoiEstimator), damit dieses Addon unabhängig davon
// funktioniert, ob das andere aktiviert ist - Plugins sind voneinander isoliert
// (siehe docs/plugin-development.md).
//
// Performance (#69): Die Ahnen-Kanten des gesamten Registers werden EINMAL
// geschlossen geladen (AncestorTreeBuilder) und alle Stammbäume rein in PHP
// daraus gebaut - statt je Kandidat rekursiv per Einzel-SELECTs über den
// PedigreeBuilder des Kerns (vorher bis zu Kandidaten x 2^Tiefe Queries pro
// Seitenaufruf). Zusätzlich wird die Kandidatenmenge SQL-seitig gefiltert und
// VOR der Berechnung hart gedeckelt.
//
// Installation (lokal im Framework-Repo):
//   cp -r anpaarungs-empfehlung plugins/anpaarungs-empfehlung
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren.

namespace Plugin\AnpaarungsEmpfehlung;

use App\Controllers\BaseController;
use App\Database;
use App\I18n\Translator;
use PDO;

class Plugin {

    /**
     * Eigenes Modul "anpaarung" mit der Aktion "recommend".
     *
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'anpaarung',
                'action' => 'recommend',
                'label' => 'Anpaarungs-Empfehlung nutzen',
                'module_label' => 'Anpaarungs-Empfehlung',
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
                'path' => '/empfehlung',
                'callback' => [EmpfehlungController::class, 'show'],
            ],
            [
                // Serverseitige Basispferd-Suche für die Datalist im Formular
                // (Muster Framework-Katalog #74): liefert JSON, max. 50 Treffer.
                'method' => 'GET',
                'path' => '/suche',
                'callback' => [EmpfehlungController::class, 'suche'],
            ],
        ];
    }
}

/**
 * Reine COI-Schätzlogik (Pfad-Koeffizienten-Verfahren), unabhängig von
 * HTTP/Controller. Verwendet die im Zuchtwesen übliche Näherung
 * F = Σ (0,5)^(n1+n2+1) über alle gemeinsamen Vorfahren beider Elterntiere,
 * wobei n1/n2 die Generationsschritte vom jeweiligen Elternteil zum
 * gemeinsamen Vorfahren sind - MIT Wrights Pfadregel, identisch zum
 * CoiCalculator des Inzuchtkoeffizient-Addons: Pfade enden am jeweils
 * ersten gemeinsamen Vorfahren, dessen eigene Ahnen zählen nicht zusätzlich
 * (jeder Pfad zu ihnen enthielte den bereits gezählten Vorfahren erneut).
 * Eine frühere Fassung ließ die Regel weg und lieferte dadurch systematisch
 * HÖHERE Werte als der Verpaarungsrechner, sobald ein gemeinsamer Vorfahre
 * selbst bekannte Ahnen im Baum hatte - die Absolutwerte beider Addons waren
 * nicht vergleichbar. Der exakte Wright-Term (1+F_A) für die Ingezüchtetheit
 * des gemeinsamen Vorfahren selbst wird - wie dort - bewusst nicht rekursiv
 * nachberechnet.
 */
class CoiEstimator {

    public static function fromParentTrees(?array $sireTree, ?array $damTree): float {
        // Erster Durchlauf ohne Abbruch: Menge der IDs, die in beiden
        // Teilbäumen vorkommen (gemeinsame Vorfahren).
        $sireAll = [];
        self::collectAncestors($sireTree, 0, $sireAll);
        $damAll = [];
        self::collectAncestors($damTree, 0, $damAll);
        $common = array_intersect_key($sireAll, $damAll);

        // Zweiter Durchlauf: Pfade enden am jeweils ersten gemeinsamen
        // Vorfahren (Wrights Pfadregel, s. Klassenkommentar).
        $sireOccurrences = [];
        self::collectAncestors($sireTree, 0, $sireOccurrences, $common);

        $damOccurrences = [];
        self::collectAncestors($damTree, 0, $damOccurrences, $common);

        $sum = 0.0;
        foreach ($sireOccurrences as $ancestorId => $linksFromSire) {
            if (!isset($damOccurrences[$ancestorId])) {
                continue;
            }
            foreach ($linksFromSire as $n1) {
                foreach ($damOccurrences[$ancestorId] as $n2) {
                    $sum += (0.5 ** ($n1 + $n2 + 1));
                }
            }
        }

        return $sum;
    }

    /**
     * @param array<int, list<int>> &$map Vorfahren-ID => Liste der Schrittzahlen
     * @param array<int, mixed> $stopAt IDs gemeinsamer Vorfahren, an denen die
     *   Rekursion endet (Wrights Pfadregel); leer im ersten Durchlauf
     */
    private static function collectAncestors(?array $node, int $links, array &$map, array $stopAt = []): void {
        if ($node === null || empty($node['id']) || !empty($node['is_placeholder'])) {
            return;
        }

        $map[$node['id']][] = $links;

        if (isset($stopAt[$node['id']])) {
            return;
        }

        self::collectAncestors($node['sire'] ?? null, $links + 1, $map, $stopAt);
        self::collectAncestors($node['dam'] ?? null, $links + 1, $map, $stopAt);
    }
}

/**
 * Baut Abstammungsbäume aus einer EINMAL geschlossen geladenen Kantenmenge
 * (#69), statt je Baum rekursiv Einzel-SELECTs abzusetzen: ein Query für den
 * gesamten Bestand, danach entstehen beliebig viele Bäume rein in PHP.
 *
 * Fachlich ein Spiegel von App\Service\PedigreeBuilder::buildRecursive() für
 * den Backend-Fall ($publishedOnly = false): gleiche Knotenform (id, name,
 * ueln, birth_year, color, depth, is_placeholder, sire, dam - Wurzel auf
 * depth = 1), gleiche Auflösungsreihenfolge (FK vor Freitext, dort UELN vor
 * Name), gleicher Abbruch bei Tiefe, Zyklus und gelöschten/fehlenden Eltern,
 * und wie dort keine Platzhalter-Erzeugung auf der letzten Generation. Der
 * Gleichlauf ist per Unit-Test gegen den echten PedigreeBuilder festgenagelt
 * (tests/Unit/AnpaarungsEmpfehlungCoiTest.php), damit eine Änderung an nur
 * einer der beiden Seiten sofort rot wird.
 *
 * Bewusste Näherung: Der Freitext-Eltern-Lookup des PedigreeBuilder
 * vergleicht über die DB-Kollation (utf8mb4_unicode_ci), hier wird mit
 * mb_strtolower() verglichen - Groß-/Kleinschreibung ist damit abgedeckt,
 * exotische Kollations-Gleichheiten (z. B. Akzentgleichheit) nicht. Und wo
 * die DB bei mehreren Namens-Treffern einen unbestimmten per LIMIT 1 wählt,
 * gewinnt hier deterministisch die kleinste ID.
 */
final class AncestorTreeBuilder {

    /**
     * Die eine geschlossene Kantenabfrage. Als Konstante öffentlich, damit der
     * Unit-Test exakt dieselbe Abfrage gegen seinen Testbestand fahren kann.
     */
    public const EDGE_SQL = 'SELECT id, name, ueln, foreign_ueln, birth_year, color, '
        . 'sire_id, sire_name, sire_ueln, dam_id, dam_name, dam_ueln '
        . 'FROM horses WHERE deleted_at IS NULL';

    /** @var array<int, array<string, mixed>> id => Zeile (ohne id-Spalte, FETCH_UNIQUE-Form) */
    private array $rows;

    /** @var array<string, int> kleingeschriebene UELN/Fremd-UELN => Pferde-ID */
    private array $uelnIndex = [];

    /** @var array<string, int> kleingeschriebener Name => Pferde-ID */
    private array $nameIndex = [];

    /** @param array<int, array<string, mixed>> $rowsById */
    private function __construct(array $rowsById) {
        $this->rows = $rowsById;

        // Lookup-Indizes für die Freitext-Auflösung (Spiegel von
        // PedigreeBuilder::findParentByUelnOrName): je Zeile erst UELN, dann
        // Fremd-UELN; bei Kollisionen gewinnt die zuerst gesehene (= kleinste)
        // ID - deterministisches Pendant zum LIMIT 1 der DB.
        foreach ($rowsById as $id => $row) {
            foreach ([$row['ueln'] ?? null, $row['foreign_ueln'] ?? null] as $ueln) {
                $key = mb_strtolower(trim((string) $ueln));
                if ($key !== '' && !isset($this->uelnIndex[$key])) {
                    $this->uelnIndex[$key] = (int) $id;
                }
            }
            $nameKey = mb_strtolower(trim((string) ($row['name'] ?? '')));
            if ($nameKey !== '' && !isset($this->nameIndex[$nameKey])) {
                $this->nameIndex[$nameKey] = (int) $id;
            }
        }
    }

    public static function loadFromDatabase(PDO $db): self {
        // PDO::FETCH_UNIQUE: die erste Spalte (id) wird Array-Schlüssel und
        // aus der Zeile entfernt - genau die Form, die fromRows() erwartet.
        $rows = $db->query(self::EDGE_SQL)->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);
        return self::fromRows($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rowsById id => Zeile mit den
     *   Spalten aus EDGE_SQL (ohne id, die steckt im Schlüssel)
     */
    public static function fromRows(array $rowsById): self {
        return new self($rowsById);
    }

    /**
     * Baut den Abstammungsbaum wie PedigreeBuilder::build(): Wurzel auf
     * depth = 1, $maxDepth = 1 heißt "nur das Pferd selbst".
     */
    public function build(?int $horseId, int $maxDepth): ?array {
        return $this->buildNode($horseId, 1, $maxDepth, []);
    }

    /**
     * @param array<int, true> $visited Pferde-IDs des aktuellen Rekursionspfades
     *   (Zyklen-Schutz, wie Framework #131)
     */
    private function buildNode(?int $horseId, int $currentDepth, int $maxDepth, array $visited): ?array {
        if (!$horseId || $currentDepth > $maxDepth) {
            return null;
        }
        if (isset($visited[$horseId])) {
            return null;
        }
        $visited[$horseId] = true;

        $row = $this->rows[$horseId] ?? null;
        if ($row === null) {
            // Gelöscht oder unbekannt - wie ein leerer fetchHorseRow()-Treffer.
            return null;
        }

        $node = [
            'id' => (int) $horseId,
            'name' => $row['name'],
            'ueln' => $row['ueln'],
            'birth_year' => $row['birth_year'],
            'color' => $row['color'],
            'sire_id' => $row['sire_id'],
            'sire_name' => $row['sire_name'],
            'sire_ueln' => $row['sire_ueln'],
            'dam_id' => $row['dam_id'],
            'dam_name' => $row['dam_name'],
            'dam_ueln' => $row['dam_ueln'],
            'depth' => $currentDepth,
        ];

        $node['sire'] = $this->resolveParent(
            $row['sire_id'], $row['sire_name'], $row['sire_ueln'], 'horse.unknown_sire',
            $currentDepth, $maxDepth, $visited
        );
        $node['dam'] = $this->resolveParent(
            $row['dam_id'], $row['dam_name'], $row['dam_ueln'], 'horse.unknown_dam',
            $currentDepth, $maxDepth, $visited
        );

        return $node;
    }

    /**
     * Spiegel der Sire-/Dam-Auflösung in PedigreeBuilder::buildRecursive():
     * FK zuerst; sonst Freitext-Lookup - aber wie dort NUR unterhalb der
     * letzten Generation ($currentDepth < $maxDepth), weil das Ergebnis auf
     * der letzten Generation ohnehin verworfen würde (Framework #119).
     *
     * @param array<int, true> $visited
     */
    private function resolveParent(
        mixed $fkId,
        mixed $freetextName,
        mixed $freetextUeln,
        string $unknownLabelKey,
        int $currentDepth,
        int $maxDepth,
        array $visited
    ): ?array {
        if (!empty($fkId)) {
            return $this->buildNode((int) $fkId, $currentDepth + 1, $maxDepth, $visited);
        }

        if ((!empty($freetextName) || !empty($freetextUeln)) && $currentDepth < $maxDepth) {
            $parentId = $this->findByUelnOrName($freetextUeln, $freetextName);
            if ($parentId !== null && !isset($visited[$parentId])) {
                return $this->buildNode($parentId, $currentDepth + 1, $maxDepth, $visited);
            }
            return [
                'id' => null,
                'name' => $freetextName ?: Translator::t($unknownLabelKey),
                'ueln' => $freetextUeln,
                'depth' => $currentDepth + 1,
                'is_placeholder' => true,
                'sire' => null,
                'dam' => null,
            ];
        }

        return null;
    }

    private function findByUelnOrName(mixed $ueln, mixed $name): ?int {
        $cleanUeln = trim((string) ($ueln ?? ''));
        if (!empty($cleanUeln)) {
            $key = mb_strtolower($cleanUeln);
            if (isset($this->uelnIndex[$key])) {
                return $this->uelnIndex[$key];
            }
        }

        $cleanName = trim((string) ($name ?? ''));
        if (!empty($cleanName)) {
            $key = mb_strtolower($cleanName);
            if (isset($this->nameIndex[$key])) {
                return $this->nameIndex[$key];
            }
        }

        return null;
    }
}

/**
 * Anpaarungs-Empfehlung: rankt für ein Basispferd Partner-Kandidaten nach dem
 * voraussichtlichen Fohlen-COI. Rein GET-basiert; Zugriffsschutz über die selbst
 * registrierte Berechtigung "anpaarung.recommend" (gilt auch für die Suchroute).
 *
 * Laufzeitdeckelung (#69): Die Kandidaten werden bereits in SQL auf das
 * passende Geschlecht eingeschränkt und alphabetisch auf candidateCap()
 * (Anzahl Vorschläge x 5, höchstens CANDIDATE_CAP_MAX) begrenzt, BEVOR
 * gerechnet wird; die Oberfläche weist auf eine greifende Deckelung hin.
 * Alle Bäume entstehen aus einer einzigen Kantenabfrage (AncestorTreeBuilder).
 */
class EmpfehlungController extends BaseController {

    // Generationen JE ELTERNTEIL (#72): Der Baum wird je Elterntier
    // (Basispferd bzw. Kandidat) als Wurzel gebaut - depth = 6 heißt also
    // 6 Generationen je Elternteil, identisch zur Tiefensemantik des
    // Verpaarungsrechners im Inzuchtkoeffizient-Addon
    // (RechnerController::DEFAULT_DEPTH = 6). Die frühere 5 ließ diese
    // Oberfläche eine Generation flacher rechnen als den Rechner - für
    // dieselbe Verpaarung standen dort und hier verschiedene Werte.
    private const DEFAULT_DEPTH = 6;
    private const MAX_DEPTH = 8;
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    /** Obergrenze der VOR der Berechnung betrachteten Kandidaten (#69). */
    public const CANDIDATE_CAP_MAX = 200;

    /** Treffer-Obergrenze der Basispferd-Suchroute (Muster Framework #74). */
    private const SEARCH_LIMIT = 50;

    // Ab diesem Fohlen-COI wird eine Verpaarung optisch als erhöht markiert
    // (6,25 % entspricht etwa einer Halbgeschwister- bzw. Onkel/Nichte-Paarung).
    private const WARN_THRESHOLD = 0.0625;

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('anpaarung', 'recommend');
    }

    /**
     * Wie viele Kandidaten höchstens berechnet werden: das Fünffache der
     * angezeigten Vorschläge, gedeckelt auf CANDIDATE_CAP_MAX (#69). Öffentlich
     * statisch, damit der Unit-Test die Deckelung ohne HTTP prüfen kann.
     */
    public static function candidateCap(int $limit): int {
        return min($limit * 5, self::CANDIDATE_CAP_MAX);
    }

    public function show(): void {
        $db = Database::getInstance();

        $depth = isset($_GET['depth']) ? max(1, min(self::MAX_DEPTH, (int) $_GET['depth'])) : self::DEFAULT_DEPTH;
        $limit = isset($_GET['limit']) ? max(1, min(self::MAX_LIMIT, (int) $_GET['limit'])) : self::DEFAULT_LIMIT;

        // Basispferd auflösen: bevorzugt die per Datalist-JS gesetzte ID,
        // ersatzweise (ohne JavaScript) der getippte Text (base_q) über eine
        // serverseitige Eindeutigkeits-Auflösung.
        $baseId = isset($_GET['base_id']) && $_GET['base_id'] !== '' ? (int) $_GET['base_id'] : null;
        $baseQuery = trim((string) ($_GET['base_q'] ?? ''));
        if ($baseId === null && $baseQuery !== '') {
            $baseId = $this->resolveBaseId($db, $baseQuery);
        }
        $baseUnresolved = ($baseId === null && $baseQuery !== '');

        $baseRow = null;
        if ($baseId !== null) {
            $stmt = $db->prepare("SELECT id, name, birth_year, sex FROM horses WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$baseId]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            $baseRow = $found === false ? null : $found;
        }

        // Geschlechtsfilter (#52): Zuchtpartner ist nur das jeweils
        // gegengeschlechtliche Tier; Wallache scheiden immer aus. Pferde OHNE
        // Geschlechtsangabe (NULL = unbekannt, Altbestand) bleiben in der Liste
        // und werden in der Tabelle gekennzeichnet - konsistent zur NULL-Regel
        // des Kerns (Framework #165). Ist das BASISPFERD ohne Angabe, kann
        // nicht gefiltert werden; das sagt die Seite dann ausdrücklich.
        $oppositeSex = ['stallion' => 'mare', 'mare' => 'stallion'];
        $baseSex = $baseRow['sex'] ?? null;

        // Ein Wallach ist kein Zuchtpartner - gar keine Empfehlung rechnen.
        $geldingBase = ($baseRow !== null && $baseSex === 'gelding');
        $baseHorse = $geldingBase ? null : $baseRow;

        $ranking = [];
        $totalCandidates = 0;
        $candidateCap = self::candidateCap($limit);

        if ($baseHorse !== null) {
            // Kandidaten SQL-seitig einschränken (#69): Geschlechtsfilter in
            // SQL statt in PHP, alphabetische Deckelung VOR der Berechnung.
            // Beide Zweige als vollständige Literal-Queries (LIMIT gebunden),
            // damit kein Variablenanteil in den Query-String gelangt - der
            // statische Plugin-Check blockiert interpolierte SQL-Strings.
            if ($baseSex !== null) {
                $countStmt = $db->prepare(
                    'SELECT COUNT(*) FROM horses
                     WHERE deleted_at IS NULL AND id != :id AND (sex = :sex OR sex IS NULL)'
                );
                $countStmt->execute(['id' => $baseId, 'sex' => $oppositeSex[$baseSex]]);

                $candidateStmt = $db->prepare(
                    'SELECT id, name, birth_year, sex FROM horses
                     WHERE deleted_at IS NULL AND id != :id AND (sex = :sex OR sex IS NULL)
                     ORDER BY name ASC, id ASC LIMIT :limit'
                );
                $candidateStmt->bindValue('sex', $oppositeSex[$baseSex]);
            } else {
                $countStmt = $db->prepare(
                    "SELECT COUNT(*) FROM horses
                     WHERE deleted_at IS NULL AND id != :id AND (sex IS NULL OR sex != 'gelding')"
                );
                $countStmt->execute(['id' => $baseId]);

                $candidateStmt = $db->prepare(
                    "SELECT id, name, birth_year, sex FROM horses
                     WHERE deleted_at IS NULL AND id != :id AND (sex IS NULL OR sex != 'gelding')
                     ORDER BY name ASC, id ASC LIMIT :limit"
                );
            }
            $totalCandidates = (int) $countStmt->fetchColumn();

            $candidateStmt->bindValue('id', $baseId, PDO::PARAM_INT);
            $candidateStmt->bindValue('limit', $candidateCap, PDO::PARAM_INT);
            $candidateStmt->execute();
            $candidates = $candidateStmt->fetchAll(PDO::FETCH_ASSOC);

            // Ahnen-Kanten EINMAL geschlossen laden, alle Bäume rein in PHP (#69).
            $graph = AncestorTreeBuilder::loadFromDatabase($db);
            $baseTree = $graph->build($baseId, $depth);

            foreach ($candidates as $h) {
                $candidateId = (int) $h['id'];
                $coi = CoiEstimator::fromParentTrees($baseTree, $graph->build($candidateId, $depth));
                $ranking[] = [
                    'id' => $candidateId,
                    'name' => (string) $h['name'],
                    'birth_year' => $h['birth_year'] !== null ? (int) $h['birth_year'] : null,
                    'sex' => $h['sex'] ?? null,
                    'coi' => $coi,
                ];
            }
            // Aufsteigend nach COI (geringste Inzucht zuerst); bei Gleichstand nach Name.
            usort($ranking, static function (array $a, array $b): int {
                return $a['coi'] <=> $b['coi'] ?: strcasecmp($a['name'], $b['name']);
            });
        }

        // Die Seite rendert als Fragment im Framework-Layout
        // (App\Plugin\PluginPage, Addons#66) - Header, Navigation,
        // Theme-Umschalter, Markenfarben und style.css kommen zentral vom
        // Layout; die frühere Standalone-Anbindung (#58) entfällt. Hier
        // bleibt nur addon-spezifische Geometrie (Formularzeile,
        // Rang-Markierungen), Farben ausschließlich über Theme-Variablen.
        $content = '<style>';
        $content .= '.inline{display:flex;gap:1rem;flex-wrap:wrap;} .inline > div{flex:1;min-width:140px;}';
        $content .= 'td.num{text-align:right;font-variant-numeric:tabular-nums;}';
        $content .= 'tr.best td{background:var(--success-soft-bg);} tr.warn td{background:var(--danger-soft-bg);}';
        $content .= '.muted{color:var(--text-muted);font-size:0.85em;}';
        $content .= '</style>';

        $content .= '<div class="card">';
        $content .= '<h1>💞 Anpaarungs-Empfehlung</h1>';
        $content .= '<p>Wählt für ein Basispferd (z. B. eine Stute) die genetisch vielfältigsten Partner: '
            . 'Die Partner-Kandidaten werden nach dem voraussichtlichen Inzuchtkoeffizienten (COI) eines '
            . 'gemeinsamen Fohlens sortiert – der niedrigste Wert zuerst.</p>';

        $content .= '<form method="GET">';
        $baseLabel = '';
        if ($baseRow !== null) {
            $baseLabel = self::horseLabel($baseRow);
        } elseif ($baseQuery !== '') {
            $baseLabel = $baseQuery;
        }
        // Suchfeld mit serverseitig nachgeladener Vorschlagsliste statt eines
        // Voll-<select> über den gesamten Bestand (#69, Muster Framework #74).
        // Die gewählte ID landet per JS im Hidden-Feld base_id; ohne
        // JavaScript löst show() den getippten Text über resolveBaseId() auf.
        $content .= '<div class="form-group"><label for="base_q">Basispferd</label>'
            . '<input type="text" name="base_q" id="base_q" class="form-control" list="base_q_liste" autocomplete="off"'
            . ' placeholder="Namen eintippen und Vorschlag auswählen …"'
            . ' value="' . htmlspecialchars($baseLabel, ENT_QUOTES, 'UTF-8') . '">'
            . '<datalist id="base_q_liste"></datalist>'
            . '<input type="hidden" name="base_id" id="base_id" value="' . ($baseRow !== null ? (int) $baseRow['id'] : '') . '">'
            . '</div>';

        $content .= '<div class="inline">';
        $content .= '<div class="form-group"><label for="depth">Generationen je Elternteil (1–' . self::MAX_DEPTH . ')</label>'
            . '<input type="number" name="depth" id="depth" class="form-control" min="1" max="' . self::MAX_DEPTH . '" value="' . (int) $depth . '"></div>';
        $content .= '<div class="form-group"><label for="limit">Anzahl Vorschläge</label>'
            . '<input type="number" name="limit" id="limit" class="form-control" min="1" max="' . self::MAX_LIMIT . '" value="' . (int) $limit . '"></div>';
        $content .= '</div>';

        $content .= '<p><button type="submit" class="btn">Empfehlungen berechnen</button></p>';
        $content .= '</form>';

        $content .= '<script>
(function () {
    var input = document.getElementById("base_q");
    var hidden = document.getElementById("base_id");
    var list = document.getElementById("base_q_liste");
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
        fetch("/plugin/anpaarungs-empfehlung/suche?q=" + encodeURIComponent(q))
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

        if ($baseUnresolved) {
            $content .= '<p class="muted">Zu „' . htmlspecialchars($baseQuery, ENT_QUOTES, 'UTF-8')
                . '" wurde kein eindeutiges Pferd gefunden – bitte weiter tippen und einen Vorschlag aus der Liste wählen.</p>';
        }
        if ($geldingBase) {
            $content .= '<p class="muted">Das gewählte Basispferd ist als Wallach erfasst - für Wallache wird keine Anpaarungs-Empfehlung berechnet.</p>';
        }
        $content .= '</div>';

        if ($baseHorse !== null) {
            $content .= '<div class="card">';
            $baseName = htmlspecialchars((string) $baseHorse['name'], ENT_QUOTES, 'UTF-8');
            if ($baseSex === null) {
                $content .= '<p class="muted">⚠️ Für „' . $baseName . '" ist kein Geschlecht hinterlegt - die Partnerliste kann deshalb nicht nach Geschlecht gefiltert werden.</p>';
            }
            if (empty($ranking)) {
                $content .= '<p class="muted">Für „' . $baseName . '" gibt es derzeit keine passenden Partner im Register.</p>';
            } else {
                $content .= '<h2>Empfehlungen für „' . $baseName . '"</h2>';
                $content .= '<table><thead><tr><th>#</th><th>Partner</th><th>Jahrgang</th>'
                    . '<th class="num">Fohlen-COI</th></tr></thead><tbody>';
                $rank = 0;
                foreach (array_slice($ranking, 0, $limit) as $row) {
                    $rank++;
                    $percent = number_format($row['coi'] * 100, 2, ',', '.');
                    $cls = '';
                    if ($rank === 1 && $row['coi'] < self::WARN_THRESHOLD) {
                        $cls = ' class="best"';
                    } elseif ($row['coi'] >= self::WARN_THRESHOLD) {
                        $cls = ' class="warn"';
                    }
                    $content .= '<tr' . $cls . '>';
                    $content .= '<td>' . $rank . '</td>';
                    $content .= '<td>' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')
                        . ($row['sex'] === null ? ' <span class="muted">(Geschlecht unbekannt)</span>' : '')
                        . '</td>';
                    $content .= '<td>' . ($row['birth_year'] !== null ? (int) $row['birth_year'] : '—') . '</td>';
                    $content .= '<td class="num"><strong>' . $percent . ' %</strong></td>';
                    $content .= '</tr>';
                }
                $content .= '</tbody></table>';
                if ($totalCandidates > count($ranking)) {
                    // Deckelungs-Hinweis (#69): Es wurde bewusst nicht der
                    // gesamte Bestand berechnet.
                    $content .= '<p class="muted">Aus Laufzeitgründen wurden die ' . count($ranking)
                        . ' alphabetisch ersten von ' . $totalCandidates . ' möglichen Partnern berechnet '
                        . '(Deckel: Anzahl Vorschläge × 5, höchstens ' . self::CANDIDATE_CAP_MAX . '). '
                        . 'Eine höhere „Anzahl Vorschläge" weitet die berechnete Menge aus.</p>';
                }
                $content .= '<p class="muted">Grün = geringste Inzucht. Rot markiert = Fohlen-COI ab '
                    . number_format(self::WARN_THRESHOLD * 100, 2, ',', '.') . ' % '
                    . '(etwa Halbgeschwister-/Onkel-Nichte-Niveau). Näherung über den verfügbaren, '
                    . 'bis zu ' . (int) $depth . ' Generationen je Elternteil tiefen Stammbaum – gleiche '
                    . 'Tiefensemantik wie der Verpaarungsrechner des Addons „Inzuchtkoeffizient"; ersetzt keine '
                    . 'züchterische Gesamtbewertung. Eine Farbprognose liefert das Addon „Farbvererbung".</p>';
            }
            $content .= '</div>';
        }

        $content .= '<p><a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a></p>';

        \App\Plugin\PluginPage::render('Anpaarungs-Empfehlung', $content);
    }

    /**
     * Serverseitige Basispferd-Suche für die Datalist (#69, Muster Framework
     * #74): JSON-Liste {id, label} über eine Teilstring-Suche im Namen,
     * höchstens SEARCH_LIMIT Treffer. Läuft über denselben Konstruktor-Schutz
     * (anpaarung.recommend) wie die Empfehlungsseite - die Namen auch
     * unveröffentlichter Pferde bleiben damit auf den berechtigten Kreis
     * beschränkt (siehe README: Kandidaten sind bewusst auch unveröffentlichte).
     */
    public function suche(): void {
        header('Content-Type: application/json; charset=utf-8');

        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q === '') {
            echo json_encode([]);
            exit;
        }

        $stmt = Database::getInstance()->prepare(
            "SELECT id, name, birth_year FROM horses "
            . "WHERE deleted_at IS NULL AND name LIKE ? "
            . "ORDER BY name ASC, id ASC LIMIT " . self::SEARCH_LIMIT
        );
        $stmt->execute(['%' . addcslashes($q, '\\%_') . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Label-Duplikate (gleicher Name und Jahrgang) eindeutig machen: Die
        // Datalist mappt Label -> ID, und der No-JS-Fallback löst das
        // "[#id]"-Suffix in resolveBaseId() wieder auf.
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
    private function resolveBaseId(PDO $db, string $q): ?int {
        // 1) Eindeutigkeits-Suffix aus der Vorschlagsliste: "… [#123]"
        if (preg_match('/\[#(\d+)\]\s*$/', $q, $m)) {
            $stmt = $db->prepare("SELECT id FROM horses WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([(int) $m[1]]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int) $id : null;
        }

        // 2) Label-Form "Name (Jahrgang)"
        if (preg_match('/^(.*\S)\s*\((\d{3,4})\)$/u', $q, $m)) {
            $stmt = $db->prepare("SELECT id FROM horses WHERE deleted_at IS NULL AND name = ? AND birth_year = ? LIMIT 2");
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
        $stmt = $db->prepare("SELECT id FROM horses WHERE deleted_at IS NULL AND name = ? LIMIT 2");
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
}
