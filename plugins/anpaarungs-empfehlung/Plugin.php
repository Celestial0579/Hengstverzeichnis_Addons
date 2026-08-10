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
            "SELECT id, name, birth_year, sex FROM horses WHERE deleted_at IS NULL ORDER BY name ASC"
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

        // Geschlechtsfilter (#52): Zuchtpartner ist nur das jeweils
        // gegengeschlechtliche Tier; Wallache scheiden immer aus. Pferde OHNE
        // Geschlechtsangabe (NULL = unbekannt, Altbestand) bleiben in der Liste
        // und werden in der Tabelle gekennzeichnet - konsistent zur NULL-Regel
        // des Kerns (Framework #165). Ist das BASISPFERD ohne Angabe, kann
        // nicht gefiltert werden; das sagt die Seite dann ausdrücklich.
        $oppositeSex = ['stallion' => 'mare', 'mare' => 'stallion'];
        $baseSex = $baseHorse['sex'] ?? null;

        if ($baseHorse !== null && $baseSex === 'gelding') {
            // Ein Wallach ist kein Zuchtpartner - gar keine Empfehlung rechnen.
            $baseHorse = null;
            $geldingBase = true;
        } else {
            $geldingBase = false;
        }

        if ($baseHorse !== null) {
            $baseTree = PedigreeBuilder::build($baseId, $depth);
            foreach ($horses as $h) {
                $candidateId = (int) $h['id'];
                if ($candidateId === $baseId) {
                    continue;
                }
                $candidateSex = $h['sex'] ?? null;
                if ($candidateSex === 'gelding') {
                    continue;
                }
                if ($baseSex !== null && $candidateSex !== null && $candidateSex !== $oppositeSex[$baseSex]) {
                    continue;
                }
                $candidateTree = PedigreeBuilder::build($candidateId, $depth);
                $coi = CoiEstimator::fromParentTrees($baseTree, $candidateTree);
                $ranking[] = [
                    'id' => $candidateId,
                    'name' => (string) $h['name'],
                    'birth_year' => $h['birth_year'] !== null ? (int) $h['birth_year'] : null,
                    'sex' => $candidateSex,
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
            . 'Alle anderen Pferde werden nach dem voraussichtlichen Inzuchtkoeffizienten (COI) eines '
            . 'gemeinsamen Fohlens sortiert – der niedrigste Wert zuerst.</p>';

        $content .= '<form method="GET">';
        $content .= '<div class="form-group"><label for="base_id">Basispferd</label>'
            . '<select name="base_id" id="base_id" class="form-control">';
        $content .= '<option value="">– auswählen –</option>';
        foreach ($horses as $h) {
            $selected = ($baseId === (int) $h['id']) ? ' selected' : '';
            $labelText = $h['name'] . ($h['birth_year'] ? ' (' . (int) $h['birth_year'] . ')' : '');
            $content .= '<option value="' . (int) $h['id'] . '"' . $selected . '>'
                . htmlspecialchars($labelText, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $content .= '</select></div>';

        $content .= '<div class="inline">';
        $content .= '<div class="form-group"><label for="depth">Generationstiefe (1–' . self::MAX_DEPTH . ')</label>'
            . '<input type="number" name="depth" id="depth" class="form-control" min="1" max="' . self::MAX_DEPTH . '" value="' . (int) $depth . '"></div>';
        $content .= '<div class="form-group"><label for="limit">Anzahl Vorschläge</label>'
            . '<input type="number" name="limit" id="limit" class="form-control" min="1" max="' . self::MAX_LIMIT . '" value="' . (int) $limit . '"></div>';
        $content .= '</div>';

        $content .= '<p><button type="submit" class="btn">Empfehlungen berechnen</button></p>';
        $content .= '</form>';

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
                $content .= '<p class="muted">Grün = geringste Inzucht. Rot markiert = Fohlen-COI ab '
                    . number_format(self::WARN_THRESHOLD * 100, 2, ',', '.') . ' % '
                    . '(etwa Halbgeschwister-/Onkel-Nichte-Niveau). Näherung über den verfügbaren, '
                    . 'bis zu ' . self::MAX_DEPTH . ' Generationen tiefen Stammbaum; ersetzt keine '
                    . 'züchterische Gesamtbewertung. Eine Farbprognose liefert das Addon „Farbvererbung".</p>';
            }
            $content .= '</div>';
        }

        $content .= '<p><a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a></p>';

        \App\Plugin\PluginPage::render('Anpaarungs-Empfehlung', $content);
    }
}
