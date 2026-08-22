<?php
// anpaarungs-empfehlung/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: Anpaarungs-Empfehlung. Für ein
// ausgewähltes Pferd (typischerweise eine Stute) werden mögliche Partner im
// Register nach dem voraussichtlichen Inzuchtkoeffizienten (Wright's COI)
// eines gemeinsamen Fohlens sortiert - die genetisch vielfältigste
// (niedrigster COI) Verpaarung zuerst.
//
// Die COI-Rechnung ist seit Addons#123 KEINE eigene Fassung mehr: Sie steht im
// gemeinsamen Rechenkern WrightCoi.php, den dieses Addon zeichengleich
// mitliefert - dieselbe Klasse, die auch das Inzuchtkoeffizient-Addon benutzt.
// Mitgeliefert wird sie, damit dieses Addon unabhängig davon funktioniert, ob
// das andere installiert ist (Plugins sind voneinander isoliert, siehe
// docs/plugin-development.md); die Begründung im Einzelnen steht in
// WrightCoi.php.
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
use Hengstverzeichnis\Addons\Shared\WrightCoi;
use PDO;

// Gemeinsamer COI-Rechenkern (#123). Der Wächter ist nötig, weil dieselbe
// Datei auch von anderen Addons mitgeliefert wird: Plugins liegen in keinem
// Autoloader, jedes bindet seine eigene Kopie per require_once ein - zwei
// verschiedene Dateipfade, dieselbe Klasse, also ohne Wächter ein
// "Cannot redeclare class". Wer zuerst lädt, gewinnt, und das ist gewollt:
// Danach rechnen alle beteiligten Addons garantiert mit DERSELBEN Fassung.
if (!class_exists(WrightCoi::class, false)) {
    require_once __DIR__ . '/WrightCoi.php';
}

// Altname aus der Zeit der Doppelung (#123): Vor der Zusammenlegung war
// CoiEstimator eine eigene Klasse mit eigener Rechnung - und lief zeitweise
// sogar auseinander (ihm fehlte Wrights Pfadregel). Der Alias hält bestehende
// Verweise (tests/Unit/AnpaarungsEmpfehlungCoiTest.php) am Leben, OHNE eine
// zweite Fassung zu sein: class_alias() erzeugt keinen eigenen Code, sondern
// einen zweiten Namen für exakt dieselbe Klasse.
if (!class_exists(__NAMESPACE__ . '\\CoiEstimator', false)) {
    class_alias(WrightCoi::class, __NAMESPACE__ . '\\CoiEstimator');
}

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
        // Die addoneigene Basispferd-Suche (/suche) ist mit Addons#125
        // entfallen. Sieben Addons brachten dieselbe Route und denselben
        // JS-Block mit; der Kern liefert beides seit Framework#341 unter
        // /admin/horses/search bzw. /js/horse-search.js.
        return [
            [
                'method' => 'GET',
                'path' => '/empfehlung',
                'callback' => [EmpfehlungController::class, 'show'],
            ],
        ];
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

    /**
     * Zweite geschlossene Abfrage: die weiteren Lebensnummern (Framework #246).
     * Bewusst getrennt statt als JOIN in EDGE_SQL - eine Verkettung je Pferd
     * bräuchte GROUP_CONCAT, und dessen Trennzeichen-Syntax unterscheidet sich
     * zwischen MariaDB und dem SQLite des Unit-Tests. Zwei Abfragen mit fester
     * Anzahl bleiben im Sinne von #69 (kein N+1 je Baum).
     */
    public const REGISTRATION_SQL = 'SELECT horse_id, registration_number FROM horse_registrations';

    /** @var array<int, array<string, mixed>> id => Zeile (ohne id-Spalte, FETCH_UNIQUE-Form) */
    private array $rows;

    /** @var array<string, int> kleingeschriebene UELN/Fremd-UELN/Lebensnummer => Pferde-ID */
    private array $uelnIndex = [];

    /** @var array<string, int> kleingeschriebener Name => Pferde-ID */
    private array $nameIndex = [];

    /**
     * @param array<int, array<string, mixed>> $rowsById
     * @param array<int, array{horse_id: mixed, registration_number: mixed}> $registrations
     *   Weitere Lebensnummern je Pferd (#246); leer, wenn keine vorliegen.
     */
    private function __construct(array $rowsById, array $registrations = []) {
        $this->rows = $rowsById;

        // Lookup-Indizes für die Freitext-Auflösung (Spiegel von
        // PedigreeBuilder::findParentByUelnOrName): UELN, Fremd-UELN und seit
        // Framework #246 auch die weiteren Lebensnummern stehen dort in EINEM
        // OR gleichrangig nebeneinander. Deshalb wird hier je Schlüssel die
        // kleinste ID über alle drei Quellen hinweg genommen - deterministisches
        // Pendant zum unbestimmten LIMIT 1 der DB, und anders als ein
        // "erster Treffer gewinnt" unabhängig davon, in welcher Reihenfolge
        // die beiden Abfragen ihre Zeilen liefern.
        foreach ($rowsById as $id => $row) {
            foreach ([$row['ueln'] ?? null, $row['foreign_ueln'] ?? null] as $ueln) {
                self::rememberSmallestId($this->uelnIndex, $ueln, (int) $id);
            }
            $nameKey = mb_strtolower(trim((string) ($row['name'] ?? '')));
            if ($nameKey !== '' && !isset($this->nameIndex[$nameKey])) {
                $this->nameIndex[$nameKey] = (int) $id;
            }
        }

        foreach ($registrations as $registration) {
            $horseId = (int) ($registration['horse_id'] ?? 0);
            // Eine Nummer, deren Pferd gelöscht ist, darf nicht auflösen: der
            // Kern filtert im selben Query mit deleted_at IS NULL, EDGE_SQL
            // liefert gelöschte Pferde gar nicht erst mit.
            if ($horseId <= 0 || !isset($rowsById[$horseId])) {
                continue;
            }
            self::rememberSmallestId($this->uelnIndex, $registration['registration_number'] ?? null, $horseId);
        }
    }

    /**
     * Trägt $id unter dem normalisierten $rawKey ein, sofern der Schlüssel
     * nicht leer ist und noch keine kleinere ID dort steht.
     *
     * @param array<string, int> $index
     */
    private static function rememberSmallestId(array &$index, mixed $rawKey, int $id): void {
        $key = mb_strtolower(trim((string) $rawKey));
        if ($key === '') {
            return;
        }
        if (!isset($index[$key]) || $id < $index[$key]) {
            $index[$key] = $id;
        }
    }

    public static function loadFromDatabase(PDO $db): self {
        // PDO::FETCH_UNIQUE: die erste Spalte (id) wird Array-Schlüssel und
        // aus der Zeile entfernt - genau die Form, die fromRows() erwartet.
        $rows = $db->query(self::EDGE_SQL)->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);
        $registrations = $db->query(self::REGISTRATION_SQL)->fetchAll(PDO::FETCH_ASSOC);
        return self::fromRows($rows, $registrations);
    }

    /**
     * @param array<int, array<string, mixed>> $rowsById id => Zeile mit den
     *   Spalten aus EDGE_SQL (ohne id, die steckt im Schlüssel)
     * @param array<int, array{horse_id: mixed, registration_number: mixed}> $registrations
     *   Zeilen aus REGISTRATION_SQL; leer heißt "keine weiteren Lebensnummern".
     */
    public static function fromRows(array $rowsById, array $registrations = []): self {
        return new self($rowsById, $registrations);
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

    // Die Treffer-Obergrenze der Basispferd-Suche steht seit Addons#125 im
    // Kern (HorseSearchController::MAX_TREFFER) - die addoneigene Konstante
    // ist mit der Route entfallen, die sie deckelte.

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

        // Basispferd auflösen: bevorzugt die ID aus dem Auswahlfeld, das das
        // gemeinsame Suchfeld des Kerns füllt (Addons#125), ersatzweise (ohne
        // JavaScript) der getippte Text (base_q) über eine serverseitige
        // Eindeutigkeits-Auflösung.
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
                $coi = WrightCoi::fromParentTrees($baseTree, $graph->build($candidateId, $depth));
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
        // Pferde-Auswahl über das gemeinsame Suchfeld des Kerns (Addons#125):
        // `hv-pferdesuche` verdrahtet /js/horse-search.js mit dem Endpunkt
        // /admin/horses/search und füllt das über `data-ziel` benannte
        // <select>. Der frühere addoneigene JS-Block samt /suche-Route ist
        // damit weg - samt seines unbehandelten Wettlaufs: Tippt jemand
        // zügig, konnte die Antwort auf "Ro" NACH der auf "Roga" eintreffen
        // und die Vorschlagsliste wieder verschlechtern.
        //
        // Das Textfeld behält seinen Namen `base_q`: Ohne JavaScript bleibt
        // die Auswahlliste leer, und dann löst show() den getippten Text über
        // resolveBaseId() auf. Das <select> trägt die bereits gewählte ID als
        // einzige Option vor - sonst ginge das Basispferd verloren, sobald
        // man nur Tiefe oder Anzahl ändert und erneut absendet.
        $content .= '<div class="form-group"><label for="base_q">Basispferd</label>'
            . '<input type="text" name="base_q" id="base_q" class="form-control hv-pferdesuche"'
            . ' data-ziel="base_id" autocomplete="off"'
            . ' placeholder="Namen eintippen und Vorschlag auswählen …"'
            . ' value="' . htmlspecialchars($baseLabel, ENT_QUOTES, 'UTF-8') . '">'
            . '<select name="base_id" id="base_id" class="form-control" style="margin-top:0.4rem;">'
            . ($baseRow !== null
                ? '<option value="' . (int) $baseRow['id'] . '" selected>'
                    . htmlspecialchars(self::horseLabel($baseRow), ENT_QUOTES, 'UTF-8') . '</option>'
                : '<option value="">– bitte oben suchen –</option>')
            . '</select>'
            . '</div>';

        $content .= '<div class="inline">';
        $content .= '<div class="form-group"><label for="depth">Generationen je Elternteil (1–' . self::MAX_DEPTH . ')</label>'
            . '<input type="number" name="depth" id="depth" class="form-control" min="1" max="' . self::MAX_DEPTH . '" value="' . (int) $depth . '"></div>';
        $content .= '<div class="form-group"><label for="limit">Anzahl Vorschläge</label>'
            . '<input type="number" name="limit" id="limit" class="form-control" min="1" max="' . self::MAX_LIMIT . '" value="' . (int) $limit . '"></div>';
        $content .= '</div>';

        $content .= '<p><button type="submit" class="btn">Empfehlungen berechnen</button></p>';
        $content .= '</form>';

        // Progressive Enhancement: Das Skript des Kerns (Framework#341)
        // verdrahtet jedes Feld mit der Klasse `hv-pferdesuche`. Ohne
        // fetch()/JS greift der No-JS-Fallback in show().
        $content .= '<script src="/js/horse-search.js"></script>';

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
                $content .= '<div class="tabelle-scroll"><table><thead><tr><th>#</th><th>Partner</th><th>Jahrgang</th>'
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
                $content .= '</tbody></table></div>';
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
     * No-JS-Fallback: löst den getippten Text des Suchfelds serverseitig zu
     * einer Pferde-ID auf - nur bei eindeutigem Treffer, sonst null.
     */
    private function resolveBaseId(PDO $db, string $q): ?int {
        // 1) Eindeutigkeits-Suffix "… [#123]". Die Vorschlagsliste des Kerns
        //    erzeugt es seit Addons#125 nicht mehr (sie hängt UELN und
        //    Jahrgang an); als ausdrückliche Eingabe bleibt es der einzige
        //    Weg, ohne JavaScript ein bestimmtes von zwei namensgleichen
        //    Pferden zu benennen - deshalb wird es weiterhin akzeptiert.
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
