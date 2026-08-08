<?php
// anpaarungs-empfehlung/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: Anpaarungs-Empfehlung. Für ein
// ausgewähltes Pferd (typischerweise eine Stute) werden alle anderen Pferde im
// Register als mögliche Partner nach dem voraussichtlichen Inzuchtkoeffizienten
// (Wright's COI) eines gemeinsamen Fohlens sortiert - die genetisch
// vielfältigste (niedrigster COI) Verpaarung zuerst.
//
// Baut auf demselben Pfad-Koeffizienten-Verfahren auf wie das
// Inzuchtkoeffizient-Addon, bringt die Rechenlogik aber bewusst selbst mit
// (eigenständige Klasse CoiEstimator), damit dieses Addon unabhängig davon
// funktioniert, ob das andere aktiviert ist - Plugins sind voneinander isoliert
// (siehe docs/plugin-development.md).
//
// Installation (lokal im Framework-Repo):
//   cp -r anpaarungs-empfehlung plugins/anpaarungs-empfehlung
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren.

namespace Plugin\AnpaarungsEmpfehlung;

use App\Controllers\BaseController;
use App\Database;
use App\Service\PedigreeBuilder;
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
 * Anpaarungs-Empfehlung: rankt für ein Basispferd alle anderen Pferde nach dem
 * voraussichtlichen Fohlen-COI. Rein GET-basiert; Zugriffsschutz über die selbst
 * registrierte Berechtigung "anpaarung.recommend".
 */
class EmpfehlungController extends BaseController {

    private const DEFAULT_DEPTH = 5;
    private const MAX_DEPTH = 8;
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    // Ab diesem Fohlen-COI wird eine Verpaarung optisch als erhöht markiert
    // (6,25 % entspricht etwa einer Halbgeschwister- bzw. Onkel/Nichte-Paarung).
    private const WARN_THRESHOLD = 0.0625;

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('anpaarung', 'recommend');
    }

    public function show(): void {
        $db = Database::getInstance();
        $horses = $db->query(
            "SELECT id, name, birth_year FROM horses WHERE deleted_at IS NULL ORDER BY name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $baseId = isset($_GET['base_id']) && $_GET['base_id'] !== '' ? (int) $_GET['base_id'] : null;
        $depth = isset($_GET['depth']) ? max(1, min(self::MAX_DEPTH, (int) $_GET['depth'])) : self::DEFAULT_DEPTH;
        $limit = isset($_GET['limit']) ? max(1, min(self::MAX_LIMIT, (int) $_GET['limit'])) : self::DEFAULT_LIMIT;

        $baseHorse = null;
        $ranking = [];
        if ($baseId !== null) {
            foreach ($horses as $h) {
                if ((int) $h['id'] === $baseId) {
                    $baseHorse = $h;
                    break;
                }
            }
        }

        if ($baseHorse !== null) {
            $baseTree = PedigreeBuilder::build($baseId, $depth);
            foreach ($horses as $h) {
                $candidateId = (int) $h['id'];
                if ($candidateId === $baseId) {
                    continue;
                }
                $candidateTree = PedigreeBuilder::build($candidateId, $depth);
                $coi = CoiEstimator::fromParentTrees($baseTree, $candidateTree);
                $ranking[] = [
                    'id' => $candidateId,
                    'name' => (string) $h['name'],
                    'birth_year' => $h['birth_year'] !== null ? (int) $h['birth_year'] : null,
                    'coi' => $coi,
                ];
            }
            // Aufsteigend nach COI (geringste Inzucht zuerst); bei Gleichstand nach Name.
            usort($ranking, static function (array $a, array $b): int {
                return $a['coi'] <=> $b['coi'] ?: strcasecmp($a['name'], $b['name']);
            });
        }

        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Anpaarungs-Empfehlung</title>';
        echo '<style>body{font-family:sans-serif;padding:2rem;max-width:820px;margin:0 auto;}';
        echo 'label{display:block;margin-top:1rem;font-weight:bold;} select,input{width:100%;padding:0.5rem;margin-top:0.3rem;}';
        echo '.inline{display:flex;gap:1rem;flex-wrap:wrap;} .inline > div{flex:1;min-width:140px;}';
        echo 'table{width:100%;border-collapse:collapse;margin-top:1.2rem;} th,td{padding:0.45rem 0.6rem;border-bottom:1px solid #eee;text-align:left;}';
        echo 'th{background:#f8f9fa;} td.num{text-align:right;font-variant-numeric:tabular-nums;}';
        echo 'tr.best td{background:#eafaf0;} tr.warn td{background:#fdf3f3;}';
        echo '.muted{color:#666;font-size:0.85em;}</style></head><body>';
        echo '<h1>💞 Anpaarungs-Empfehlung</h1>';
        echo '<p>Wählt für ein Basispferd (z. B. eine Stute) die genetisch vielfältigsten Partner: '
            . 'Alle anderen Pferde werden nach dem voraussichtlichen Inzuchtkoeffizienten (COI) eines '
            . 'gemeinsamen Fohlens sortiert – der niedrigste Wert zuerst.</p>';

        echo '<form method="GET">';
        echo '<label for="base_id">Basispferd</label><select name="base_id" id="base_id">';
        echo '<option value="">– auswählen –</option>';
        foreach ($horses as $h) {
            $selected = ($baseId === (int) $h['id']) ? ' selected' : '';
            $labelText = $h['name'] . ($h['birth_year'] ? ' (' . (int) $h['birth_year'] . ')' : '');
            echo '<option value="' . (int) $h['id'] . '"' . $selected . '>'
                . htmlspecialchars($labelText, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';

        echo '<div class="inline">';
        echo '<div><label for="depth">Generationstiefe (1–' . self::MAX_DEPTH . ')</label>'
            . '<input type="number" name="depth" id="depth" min="1" max="' . self::MAX_DEPTH . '" value="' . (int) $depth . '"></div>';
        echo '<div><label for="limit">Anzahl Vorschläge</label>'
            . '<input type="number" name="limit" id="limit" min="1" max="' . self::MAX_LIMIT . '" value="' . (int) $limit . '"></div>';
        echo '</div>';

        echo '<p><button type="submit" style="margin-top:1rem;padding:0.6rem 1.2rem;">Empfehlungen berechnen</button></p>';
        echo '</form>';

        if ($baseHorse !== null) {
            $baseName = htmlspecialchars((string) $baseHorse['name'], ENT_QUOTES, 'UTF-8');
            if (empty($ranking)) {
                echo '<p class="muted">Für „' . $baseName . '" gibt es derzeit keine weiteren Pferde im Register.</p>';
            } else {
                echo '<h2>Empfehlungen für „' . $baseName . '"</h2>';
                echo '<table><thead><tr><th>#</th><th>Partner</th><th>Jahrgang</th>'
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
                    echo '<tr' . $cls . '>';
                    echo '<td>' . $rank . '</td>';
                    echo '<td>' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . '</td>';
                    echo '<td>' . ($row['birth_year'] !== null ? (int) $row['birth_year'] : '—') . '</td>';
                    echo '<td class="num"><strong>' . $percent . ' %</strong></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '<p class="muted">Grün = geringste Inzucht. Rot markiert = Fohlen-COI ab '
                    . number_format(self::WARN_THRESHOLD * 100, 2, ',', '.') . ' % '
                    . '(etwa Halbgeschwister-/Onkel-Nichte-Niveau). Näherung über den verfügbaren, '
                    . 'bis zu ' . self::MAX_DEPTH . ' Generationen tiefen Stammbaum; ersetzt keine '
                    . 'züchterische Gesamtbewertung. Eine Farbprognose liefert das Addon „Farbvererbung".</p>';
            }
        }

        echo '<p style="margin-top:2rem;"><a href="/admin">Zurück zum Dashboard</a></p>';
        echo '</body></html>';
    }
}
